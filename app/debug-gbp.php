<?php
/**
 * BOUS'TACOM — Debug Import GBP Locations
 * Ce script teste l'API Google Business Profile et importe les fiches.
 * À SUPPRIMER APRÈS USAGE.
 */
require_once __DIR__ . '/config.php';
startSecureSession();
requireLogin();

header('Content-Type: text/html; charset=utf-8');
echo "<h2 style='font-family:monospace;color:#00d4ff;background:#000;padding:20px;'>BOUS'TACOM — Debug Import GBP</h2>";
echo "<pre style='font-family:monospace;background:#0a1628;color:#fff;padding:20px;border-radius:12px;overflow:auto;'>";

// 1. Récupérer le compte GBP
$stmt = db()->prepare('SELECT * FROM gbp_accounts WHERE user_id = ?');
$stmt->execute([$_SESSION['user_id']]);
$account = $stmt->fetch();

if (!$account) {
    echo "❌ Aucun compte GBP trouvé.\n";
    exit;
}

echo "✅ Compte GBP trouvé (ID: {$account['id']})\n";
echo "   Google Account ID: {$account['google_account_id']}\n";
echo "   Token expires: {$account['token_expires_at']}\n\n";

// 2. Obtenir un token valide
$token = getValidGoogleToken($account['id']);
if (!$token) {
    echo "❌ Impossible d'obtenir un token valide.\n";
    echo "   Tentative de refresh...\n";
    
    $result = refreshGoogleToken($account['refresh_token']);
    echo "   Refresh result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
    exit;
}

echo "✅ Token valide obtenu\n\n";

// 3. Tester l'API Account Management
echo "=== Test API Account Management ===\n";
$url = 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
    CURLOPT_TIMEOUT => 30,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP {$httpCode}\n";
$data = json_decode($response, true);
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

if (!$data || !isset($data['accounts'])) {
    echo "❌ Pas de comptes trouvés. Vérifiez les scopes OAuth.\n";
    
    // Essayons avec l'ancienne API
    echo "\n=== Test ancienne API Google My Business ===\n";
    $url2 = 'https://mybusiness.googleapis.com/v4/accounts';
    $ch2 = curl_init($url2);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response2 = curl_exec($ch2);
    $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    
    echo "HTTP {$httpCode2}\n";
    echo json_encode(json_decode($response2, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    
    exit;
}

// 4. Pour chaque compte, lister les locations
foreach ($data['accounts'] as $gbpAccount) {
    $accountName = $gbpAccount['name'];
    $accountDisplayName = $gbpAccount['accountName'] ?? $gbpAccount['name'] ?? 'N/A';
    echo "=== Compte: {$accountDisplayName} ({$accountName}) ===\n";
    
    // Essayer la nouvelle API Business Information
    echo "\n--- Test Business Information API ---\n";
    $locUrl = "https://mybusinessbusinessinformation.googleapis.com/v1/{$accountName}/locations?readMask=name,title,storefrontAddress,phoneNumbers,websiteUri,categories,latlng,metadata";
    $ch3 = curl_init($locUrl);
    curl_setopt_array($ch3, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response3 = curl_exec($ch3);
    $httpCode3 = curl_getinfo($ch3, CURLINFO_HTTP_CODE);
    curl_close($ch3);
    
    echo "HTTP {$httpCode3}\n";
    $locData = json_decode($response3, true);
    echo json_encode($locData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    
    // Si ça ne marche pas, essayer l'ancienne API v4
    if ($httpCode3 !== 200 || !isset($locData['locations'])) {
        echo "--- Fallback: ancienne API v4 ---\n";
        $locUrl4 = "https://mybusiness.googleapis.com/v4/{$accountName}/locations";
        $ch4 = curl_init($locUrl4);
        curl_setopt_array($ch4, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response4 = curl_exec($ch4);
        $httpCode4 = curl_getinfo($ch4, CURLINFO_HTTP_CODE);
        curl_close($ch4);
        
        echo "HTTP {$httpCode4}\n";
        $locData = json_decode($response4, true);
        echo json_encode($locData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    }
    
    // 5. Importer les locations trouvées
    if (isset($locData['locations'])) {
        echo "🎉 " . count($locData['locations']) . " fiche(s) trouvée(s) !\n\n";
        
        foreach ($locData['locations'] as $loc) {
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
                $lines = $addr['addressLines'] ?? [];
                $address = implode(', ', array_filter($lines));
                $city   = $addr['locality'] ?? '';
                $postal = $addr['postalCode'] ?? '';
            }
            
            echo "  📍 {$name}\n";
            echo "     Adresse: {$address}, {$postal} {$city}\n";
            echo "     Tél: {$phone}\n";
            echo "     Catégorie: {$category}\n";
            echo "     GPS: {$lat}, {$lng}\n";
            echo "     Place ID: {$placeId}\n";
            
            // Insérer en base
            try {
                $stmt = db()->prepare('
                    INSERT INTO gbp_locations 
                        (gbp_account_id, google_location_id, name, address, city, postal_code, phone, website, category, latitude, longitude, place_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        name = VALUES(name), address = VALUES(address), city = VALUES(city),
                        phone = VALUES(phone), website = VALUES(website), category = VALUES(category),
                        latitude = VALUES(latitude), longitude = VALUES(longitude),
                        updated_at = NOW()
                ');
                $stmt->execute([
                    $account['id'], $googleLocationId, $name, $address, $city, $postal,
                    $phone, $website, $category, $lat, $lng, $placeId
                ]);
                echo "     ✅ Importée en base !\n\n";
            } catch (Exception $e) {
                echo "     ❌ Erreur DB: " . $e->getMessage() . "\n\n";
            }
        }
    } else {
        echo "⚠️ Aucune fiche trouvée pour ce compte.\n";
        echo "   Vérifiez que ce compte Google gère bien des fiches GBP.\n\n";
    }
}

echo "\n=== FIN DU DEBUG ===\n";
echo "Retournez sur le dashboard pour voir si les fiches apparaissent.\n";
echo "</pre>";
echo "<p style='font-family:monospace;padding:20px;'><a href='" . APP_URL . "/index.php' style='color:#00d4ff;'>← Retour au Dashboard</a></p>";
