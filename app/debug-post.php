<?php
/**
 * BOUS'TACOM — Debug Publication Post
 * Teste l'API Google localPosts pour TOUTES les fiches.
 * À SUPPRIMER APRÈS USAGE.
 */
require_once __DIR__ . '/config.php';
startSecureSession();
requireLogin();

header('Content-Type: text/html; charset=utf-8');
echo "<h2 style='font-family:monospace;color:#00d4ff;background:#000;padding:20px;'>BOUS'TACOM — Debug Publication Post (toutes fiches)</h2>";
echo "<pre style='font-family:monospace;background:#0a1628;color:#fff;padding:20px;border-radius:12px;overflow:auto;'>";

// 1. Token
$stmt = db()->prepare('SELECT * FROM gbp_accounts WHERE user_id = ? LIMIT 1');
$stmt->execute([$_SESSION['user_id']]);
$account = $stmt->fetch();

$token = getValidGoogleToken($account['id']);
if (!$token) {
    echo "❌ Token Google invalide.\n";
    exit;
}
echo "✅ Token valide\n\n";

// 2. Lister TOUTES les locations via API v4
echo "=== ÉTAPE 1 : Lister toutes les locations via API v4 ===\n";
$v4LocUrl = "https://mybusiness.googleapis.com/v4/{$account['google_account_name']}/locations?pageSize=100";
echo "URL : {$v4LocUrl}\n";

$ch = curl_init($v4LocUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
    CURLOPT_TIMEOUT => 30,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP : {$httpCode}\n\n";
$v4Data = json_decode($response, true);

// Créer une map des locations v4 : locationName → name (v4 ID)
$v4Map = [];
if (isset($v4Data['locations'])) {
    echo "📋 " . count($v4Data['locations']) . " fiches trouvées via API v4 :\n\n";
    foreach ($v4Data['locations'] as $v4Loc) {
        $v4Name = $v4Loc['name'] ?? 'N/A'; // Format: accounts/XXX/locations/YYY
        $v4Title = $v4Loc['locationName'] ?? 'N/A';
        $v4Map[$v4Title] = $v4Name;
        echo "   📍 {$v4Title}\n";
        echo "      ID v4 : {$v4Name}\n\n";
    }
}

// 3. Comparer avec la DB
echo "\n=== ÉTAPE 2 : Comparer DB vs API v4 pour chaque fiche ===\n\n";

$stmt = db()->prepare('
    SELECT l.*, a.google_account_name
    FROM gbp_locations l
    JOIN gbp_accounts a ON l.gbp_account_id = a.id
    WHERE l.is_active = 1
    ORDER BY l.id
');
$stmt->execute();
$dbLocations = $stmt->fetchAll();

foreach ($dbLocations as $dbLoc) {
    $dbId = $dbLoc['google_location_id'];
    $dbName = $dbLoc['name'];

    // Construire le path v4 tel qu'il serait utilisé pour les posts
    $v4Path = buildGoogleV4LocationPath($dbLoc['google_account_name'], $dbId);

    echo "--- Fiche #{$dbLoc['id']} : {$dbName} ({$dbLoc['city']}) ---\n";
    echo "   DB google_location_id : {$dbId}\n";
    echo "   Path v4 construit     : {$v4Path}\n";

    // Chercher cette fiche dans les résultats v4
    $foundV4 = null;
    if (isset($v4Data['locations'])) {
        foreach ($v4Data['locations'] as $v4Loc) {
            $v4LocName = $v4Loc['locationName'] ?? '';
            // Comparer par nom ou par ID
            if ($v4Loc['name'] === $v4Path) {
                $foundV4 = $v4Loc;
                break;
            }
            // Extraire juste le locations/XXX du path v4
            $v4LocId = preg_replace('/^accounts\/[^\/]+\//', '', $v4Loc['name']);
            if ($v4LocId === $dbId) {
                $foundV4 = $v4Loc;
                break;
            }
        }
    }

    if ($foundV4) {
        echo "   ✅ Trouvée dans API v4 : {$foundV4['name']}\n";
        if ($foundV4['name'] !== $v4Path) {
            echo "   ⚠️ MISMATCH ! L'ID v4 est : {$foundV4['name']}\n";
        }
    } else {
        echo "   ❌ PAS TROUVÉE dans API v4 !\n";
        echo "   💡 L'ID en DB ({$dbId}) ne correspond à aucune fiche v4.\n";
    }

    // Tester l'appel localPosts
    $testUrl = "https://mybusiness.googleapis.com/v4/{$v4Path}/localPosts?pageSize=1";
    $ch = curl_init($testUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 15,
    ]);
    $testResponse = curl_exec($ch);
    $testCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($testCode === 200) {
        echo "   ✅ localPosts API : HTTP 200 OK\n";
    } else {
        echo "   ❌ localPosts API : HTTP {$testCode} — PUBLICATION IMPOSSIBLE\n";

        // Si pas trouvée, essayer de matcher par nom
        if (!$foundV4 && isset($v4Data['locations'])) {
            foreach ($v4Data['locations'] as $v4Loc) {
                $v4LocName = $v4Loc['locationName'] ?? '';
                if (stripos($v4LocName, $dbName) !== false || stripos($dbName, $v4LocName) !== false) {
                    echo "   🔍 Correspondance possible par nom : {$v4Loc['name']} ({$v4LocName})\n";

                    // Tester avec cet ID
                    $altUrl = "https://mybusiness.googleapis.com/v4/{$v4Loc['name']}/localPosts?pageSize=1";
                    $ch2 = curl_init($altUrl);
                    curl_setopt_array($ch2, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
                        CURLOPT_TIMEOUT => 15,
                    ]);
                    $altResponse = curl_exec($ch2);
                    $altCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                    curl_close($ch2);

                    if ($altCode === 200) {
                        echo "   🎉 CET ID MARCHE ! Il faut corriger en DB :\n";
                        echo "      UPDATE gbp_locations SET google_location_id = '" . preg_replace('/^accounts\/[^\/]+\//', '', $v4Loc['name']) . "' WHERE id = {$dbLoc['id']};\n";
                    }
                }
            }
        }
    }

    echo "\n";

    usleep(300000); // Rate limiting
}

echo "\n=== FIN DU DEBUG ===\n";
echo "</pre>";
echo "<p style='font-family:monospace;padding:20px;'><a href='" . APP_URL . "/index.php' style='color:#00d4ff;'>← Retour au Dashboard</a></p>";
