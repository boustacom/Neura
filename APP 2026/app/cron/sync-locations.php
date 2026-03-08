<?php
/**
 * BOUS'TACOM — CRON Synchronisation des fiches GBP
 * Securise par token SHA-256
 * A configurer sur InfoManiak : quotidien a 05:00
 * URL: https://app.boustacom.fr/app/cron/sync-locations.php?token=XXXX
 *
 * Auto-guerit les google_location_id perimes en listant les comptes
 * et locations via l'API v1 puis en matchant par ID ou par nom.
 * Met a jour les infos des fiches (nom, adresse, telephone, etc.)
 */

require_once __DIR__ . '/../config.php';

// ====== SECURITE ======
$expectedToken = hash('sha256', APP_SECRET . '_cron_sync_locations');
$providedToken = $_GET['token'] ?? '';

if (!hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Token invalide']);
    exit;
}

header('Content-Type: application/json');
http_response_code(200);
ini_set('display_errors', 1);
error_reporting(E_ALL);
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "\n\n!!! FATAL ERROR: {$err['message']} in {$err['file']}:{$err['line']}\n";
    }
});

$now = new DateTime('now', new DateTimeZone('Europe/Paris'));
$results = [];

echo "=== CRON Sync Locations — " . $now->format('Y-m-d H:i:s') . " ===\n";

// ====== RECUPERER TOUS LES COMPTES GBP ======
$stmt = db()->prepare('
    SELECT id, user_id, google_account_id, google_account_name, access_token, refresh_token, token_expires_at
    FROM gbp_accounts
    WHERE access_token IS NOT NULL
');
$stmt->execute();
$accounts = $stmt->fetchAll();

echo "Comptes GBP en base : " . count($accounts) . "\n\n";

foreach ($accounts as $account) {
    $accountId = $account['id'];
    $token = getValidGoogleToken($accountId);

    if (!$token) {
        echo "Compte #{$accountId} : Token invalide ou expire.\n\n";
        $results[] = ['account_id' => $accountId, 'status' => 'error', 'message' => 'Token invalide'];
        continue;
    }

    $headers = ['Authorization: Bearer ' . $token];

    // ====== LISTER LES COMPTES v1 ======
    $v1Accounts = httpGet('https://mybusinessaccountmanagement.googleapis.com/v1/accounts', $headers);
    if (!$v1Accounts || empty($v1Accounts['accounts'])) {
        echo "Compte #{$accountId} : Aucun compte v1 trouve.\n\n";
        $results[] = ['account_id' => $accountId, 'status' => 'error', 'message' => 'Aucun compte v1'];
        continue;
    }

    echo "Compte #{$accountId} : " . count($v1Accounts['accounts']) . " compte(s) v1\n";

    // Collecter TOUTES les locations de TOUS les comptes v1
    $allGoogleLocations = [];

    foreach ($v1Accounts['accounts'] as $v1Acc) {
        $v1AccName = $v1Acc['name'] ?? '';
        if (!$v1AccName) continue;

        // Mettre a jour google_account_name si different
        if ($v1AccName !== ($account['google_account_name'] ?? '')) {
            try {
                $stmtUpd = db()->prepare('UPDATE gbp_accounts SET google_account_name = ? WHERE id = ?');
                $stmtUpd->execute([$v1AccName, $accountId]);
                echo "  Account name mis a jour : {$v1AccName}\n";
            } catch (\Throwable $e) {
                // Ignorer les erreurs de mise a jour
            }
        }

        // Lister les locations avec pagination
        $pageToken = null;
        do {
            $url = "https://mybusinessbusinessinformation.googleapis.com/v1/{$v1AccName}/locations?readMask=name,title,storefrontAddress,phoneNumbers,websiteUri,categories,latlng,metadata&pageSize=100";
            if ($pageToken) $url .= '&pageToken=' . urlencode($pageToken);

            $v1Locs = httpGet($url, $headers);

            if ($v1Locs && !empty($v1Locs['locations'])) {
                foreach ($v1Locs['locations'] as $loc) {
                    $allGoogleLocations[] = [
                        'account_name' => $v1AccName,
                        'location_name' => $loc['name'] ?? '',
                        'title' => $loc['title'] ?? '',
                        'address' => $loc['storefrontAddress'] ?? [],
                        'phone' => $loc['phoneNumbers']['primaryPhone'] ?? null,
                        'website' => $loc['websiteUri'] ?? null,
                        'category' => $loc['categories']['primaryCategory']['displayName'] ?? null,
                        'lat' => $loc['latlng']['latitude'] ?? null,
                        'lng' => $loc['latlng']['longitude'] ?? null,
                        'place_id' => $loc['metadata']['placeId'] ?? null,
                    ];
                }
            }

            $pageToken = $v1Locs['nextPageToken'] ?? null;
        } while ($pageToken);

        usleep(200000); // 0.2s entre les comptes
    }

    echo "  Total locations Google : " . count($allGoogleLocations) . "\n";

    // ====== RECUPERER TOUTES LES FICHES EN BASE POUR CE COMPTE ======
    // IMPORTANT : PAS de filtre is_active — on doit voir toutes les fiches pour eviter les doublons
    $stmtLocs = db()->prepare('SELECT id, google_location_id, name, is_active FROM gbp_locations WHERE gbp_account_id = ?');
    $stmtLocs->execute([$accountId]);
    $dbLocations = $stmtLocs->fetchAll();

    $healedCount = 0;
    $updatedCount = 0;
    $newCount = 0;

    // ====== POUR CHAQUE FICHE GOOGLE, MATCHER OU CREER ======
    foreach ($allGoogleLocations as $gLoc) {
        $googleLocId = $gLoc['location_name']; // format: locations/XXXXX
        $gLocNumId = preg_replace('/^locations\//', '', $googleLocId);

        // Preparer les infos
        $addr = $gLoc['address'];
        $address = implode(', ', array_filter([
            $addr['addressLines'][0] ?? '',
            $addr['addressLines'][1] ?? '',
        ]));
        $city = $addr['locality'] ?? '';
        $postal = $addr['postalCode'] ?? '';

        // 1. Matcher par google_location_id exact (SQL, fiable)
        $matched = null;
        foreach ($dbLocations as $dbLoc) {
            $dbNumId = preg_replace('/^locations\//', '', $dbLoc['google_location_id'] ?? '');
            if ($dbNumId && $dbNumId === $gLocNumId) {
                $matched = $dbLoc;
                break;
            }
        }

        // 2. Fallback : matcher par nom de fiche
        if (!$matched) {
            foreach ($dbLocations as $dbLoc) {
                if ($dbLoc['name'] && mb_strtolower(trim($dbLoc['name'])) === mb_strtolower(trim($gLoc['title']))) {
                    $matched = $dbLoc;
                    break;
                }
            }
        }

        if ($matched) {
            $dbLocId = $matched['id'];

            // Auto-guerison du google_location_id si different
            $dbNumId = preg_replace('/^locations\//', '', $matched['google_location_id'] ?? '');
            if ($dbNumId !== $gLocNumId) {
                try {
                    $stmtHeal = db()->prepare('UPDATE gbp_locations SET google_location_id = ?, updated_at = NOW() WHERE id = ?');
                    $stmtHeal->execute([$googleLocId, $dbLocId]);
                    echo "  * AUTO-GUERISON '{$matched['name']}' : {$matched['google_location_id']} → {$googleLocId}\n";
                    $healedCount++;
                } catch (\Throwable $e) {
                    echo "  ! Erreur auto-guerison #{$dbLocId} : {$e->getMessage()}\n";
                }
            }

            // Mettre a jour les infos de la fiche
            try {
                $stmtUpdate = db()->prepare('
                    UPDATE gbp_locations SET
                        name = ?,
                        address = ?,
                        city = ?,
                        postal_code = ?,
                        phone = COALESCE(?, phone),
                        website = COALESCE(?, website),
                        category = COALESCE(?, category),
                        latitude = COALESCE(?, latitude),
                        longitude = COALESCE(?, longitude),
                        place_id = COALESCE(?, place_id),
                        updated_at = NOW()
                    WHERE id = ?
                ');
                $stmtUpdate->execute([
                    $gLoc['title'],
                    $address,
                    $city,
                    $postal,
                    $gLoc['phone'],
                    $gLoc['website'],
                    $gLoc['category'],
                    $gLoc['lat'],
                    $gLoc['lng'],
                    $gLoc['place_id'],
                    $dbLocId,
                ]);
                $updatedCount++;

                // Auto-associer Places API si place_id existe et pas encore lie
                if (!empty($gLoc['place_id'])) {
                    try {
                        $stmtAutoLink = db()->prepare('UPDATE gbp_locations SET places_api_linked = 1, places_api_linked_at = COALESCE(places_api_linked_at, NOW()) WHERE id = ? AND places_api_linked = 0');
                        $stmtAutoLink->execute([$dbLocId]);
                        if ($stmtAutoLink->rowCount() > 0) {
                            echo "  + Places API auto-associee pour '{$gLoc['title']}'\n";
                        }
                    } catch (\Throwable $e) { /* ignore */ }
                }
            } catch (\Throwable $e) {
                echo "  ! Erreur update #{$dbLocId} : {$e->getMessage()}\n";
            }
        } else {
            // Fiche Google non importee dans l'outil → on l'ignore
            // L'import se fait UNIQUEMENT manuellement via l'UI (import_selected)
        }
    }

    // ====== DETECTER LES FICHES SUPPRIMEES DE GOOGLE ======
    foreach ($dbLocations as $dbLoc) {
        if (!$dbLoc['is_active']) continue;
        $dbNumId = preg_replace('/^locations\//', '', $dbLoc['google_location_id'] ?? '');
        $stillExists = false;
        foreach ($allGoogleLocations as $gLoc) {
            $gNumId = preg_replace('/^locations\//', '', $gLoc['location_name']);
            if ($dbNumId && $dbNumId === $gNumId) { $stillExists = true; break; }
            if (mb_strtolower(trim($dbLoc['name'])) === mb_strtolower(trim($gLoc['title']))) { $stillExists = true; break; }
        }
        if (!$stillExists) {
            echo "  ? Fiche '{$dbLoc['name']}' (#{$dbLoc['id']}) : introuvable dans Google (peut-etre supprimee)\n";
        }
    }

    echo "  => {$updatedCount} mises a jour, {$healedCount} auto-guerie(s)\n\n";
    $results[] = [
        'account_id' => $accountId,
        'status' => 'success',
        'updated' => $updatedCount,
        'healed' => $healedCount,
    ];

    usleep(500000); // 0.5s entre comptes
}

echo "=== Termine ===\n";
echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
