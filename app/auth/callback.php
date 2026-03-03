<?php
/**
 * BOUS'TACOM — Google OAuth Callback
 *
 * Gere le retour de Google apres autorisation.
 * Stocke les tokens et recupere la liste des fiches GBP.
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();

// Verification du state (CSRF)
$state = $_GET['state'] ?? '';
if (!hash_equals($_SESSION[CSRF_TOKEN_NAME] ?? '', $state)) {
    die('Erreur de securite : token invalide.');
}

// Verifier qu'on a un code d'autorisation
$code = $_GET['code'] ?? '';
if (empty($code)) {
    $error = $_GET['error'] ?? 'Autorisation refusee.';
    flashMessage('error', 'Erreur Google : ' . $error);
    redirect(APP_URL . '/');
}

// Echanger le code contre un access token
$tokens = exchangeGoogleCode($code);

if (!$tokens || !isset($tokens['access_token'])) {
    flashMessage('error', 'Impossible d\'obtenir le token Google.');
    redirect(APP_URL . '/');
}

$accessToken  = $tokens['access_token'];
$refreshToken = $tokens['refresh_token'] ?? null;
$expiresIn    = $tokens['expires_in'] ?? 3600;
$expiresAt    = date('Y-m-d H:i:s', time() + $expiresIn);

// Recuperer les infos du compte Google
$userInfo = httpGet('https://www.googleapis.com/oauth2/v2/userinfo', [
    'Authorization: Bearer ' . $accessToken,
]);

$googleAccountId = $userInfo['id'] ?? 'unknown';
$userId = $_SESSION['user_id'];

// Sauvegarder ou mettre a jour le compte GBP
$stmt = db()->prepare('SELECT id FROM gbp_accounts WHERE user_id = ? AND google_account_id = ?');
$stmt->execute([$userId, $googleAccountId]);
$existing = $stmt->fetch();

if ($existing) {
    $stmt = db()->prepare('UPDATE gbp_accounts SET access_token = ?, refresh_token = COALESCE(?, refresh_token), token_expires_at = ? WHERE id = ?');
    $stmt->execute([$accessToken, $refreshToken, $expiresAt, $existing['id']]);
    $accountId = $existing['id'];
} else {
    $stmt = db()->prepare('INSERT INTO gbp_accounts (user_id, google_account_id, access_token, refresh_token, token_expires_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $googleAccountId, $accessToken, $refreshToken, $expiresAt]);
    $accountId = db()->lastInsertId();
}

// Recuperer la liste des fiches GBP (locations)
$accounts = httpGet('https://mybusinessaccountmanagement.googleapis.com/v1/accounts', [
    'Authorization: Bearer ' . $accessToken,
]);

if ($accounts && isset($accounts['accounts'])) {
    foreach ($accounts['accounts'] as $account) {
        $accountName = $account['name']; // Format: accounts/XXXXX

        // Stocker le google_account_name pour les appels API v4
        $stmt = db()->prepare('UPDATE gbp_accounts SET google_account_name = ? WHERE id = ?');
        $stmt->execute([$accountName, $accountId]);

        // Recuperer les locations avec pagination
        $pageToken = null;
        do {
            $url = "https://mybusinessbusinessinformation.googleapis.com/v1/{$accountName}/locations?readMask=name,title,storefrontAddress,phoneNumbers,websiteUri,categories,latlng,metadata&pageSize=100";
            if ($pageToken) {
                $url .= '&pageToken=' . urlencode($pageToken);
            }

            $locations = httpGet($url, ['Authorization: Bearer ' . $accessToken]);

            if ($locations && isset($locations['locations'])) {
                foreach ($locations['locations'] as $loc) {
                    $googleLocationId = $loc['name'] ?? '';
                    $name     = $loc['title'] ?? '';
                    $address  = '';
                    $city     = '';
                    $postal   = '';
                    $phone    = $loc['phoneNumbers']['primaryPhone'] ?? null;
                    $website  = $loc['websiteUri'] ?? null;
                    $category = $loc['categories']['primaryCategory']['displayName'] ?? null;
                    $lat      = $loc['latlng']['latitude'] ?? null;
                    $lng      = $loc['latlng']['longitude'] ?? null;
                    $placeId  = $loc['metadata']['placeId'] ?? null;

                    if (isset($loc['storefrontAddress'])) {
                        $addr = $loc['storefrontAddress'];
                        $address = implode(', ', array_filter([
                            $addr['addressLines'][0] ?? '',
                            $addr['addressLines'][1] ?? '',
                        ]));
                        $city   = $addr['locality'] ?? '';
                        $postal = $addr['postalCode'] ?? '';
                    }

                    $stmt = db()->prepare('
                        INSERT INTO gbp_locations
                            (gbp_account_id, google_location_id, name, address, city, postal_code, phone, website, category, latitude, longitude, place_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            name = VALUES(name), address = VALUES(address), city = VALUES(city),
                            postal_code = VALUES(postal_code), phone = VALUES(phone),
                            website = VALUES(website), category = VALUES(category),
                            latitude = VALUES(latitude), longitude = VALUES(longitude),
                            place_id = VALUES(place_id), updated_at = NOW()
                    ');
                    $stmt->execute([
                        $accountId, $googleLocationId, $name, $address, $city, $postal,
                        $phone, $website, $category, $lat, $lng, $placeId
                    ]);
                }
            }

            $pageToken = $locations['nextPageToken'] ?? null;
        } while ($pageToken);
    }
}

flashMessage('success', 'Compte Google connecte avec succes ! Vos fiches GBP ont ete importees.');
redirect(APP_URL . '/');
