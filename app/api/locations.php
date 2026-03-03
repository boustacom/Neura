<?php
/**
 * BOUS'TACOM — API Gestion des Fiches GBP
 *
 * Actions:
 *   - list: Lister toutes les fiches de l'utilisateur
 *   - toggle: Activer/desactiver une fiche
 *   - sync_locations: Re-synchroniser les fiches depuis Google
 *   - sync_reviews: Synchroniser les avis d'une fiche depuis Google
 *   - delete: Supprimer une fiche et toutes ses donnees
 *   - cleanup_test_data: Supprimer les donnees de test
 *   - post_reply: Poster une reponse a un avis sur Google
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();
requireCsrf();

header('Content-Type: application/json');

$user = currentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? null;

if (!$action) {
    jsonResponse(['error' => 'Action requise'], 400);
}

switch ($action) {

    // ====== LISTER LES FICHES ======
    case 'list':
        $showAll = ($_GET['show_all'] ?? '0') === '1';
        $activeFilter = $showAll ? '' : 'AND l.is_active = 1';

        $stmt = db()->prepare("
            SELECT l.*, a.id as account_id, a.google_account_id,
                   (SELECT COUNT(*) FROM keywords WHERE location_id = l.id AND is_active = 1) as keyword_count,
                   (SELECT COUNT(*) FROM reviews WHERE location_id = l.id) as review_count,
                   (SELECT COUNT(*) FROM reviews WHERE location_id = l.id AND is_replied = 0) as unanswered_count,
                   (SELECT COUNT(*) FROM reviews WHERE location_id = l.id AND is_replied = 0 AND rating <= 2) as negative_unanswered,
                   (SELECT ROUND(AVG(rating), 1) FROM reviews WHERE location_id = l.id) as avg_rating,
                   (SELECT COUNT(*) FROM google_posts WHERE location_id = l.id) as post_count,
                   (SELECT COUNT(*) FROM google_posts WHERE location_id = l.id AND (status = 'scheduled' OR status = 'list_pending')) as scheduled_count,
                   (SELECT COUNT(*) FROM google_posts WHERE location_id = l.id AND status = 'published' AND published_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as published_week,
                   (SELECT COUNT(*) FROM google_posts WHERE location_id = l.id AND status = 'failed') as failed_count
            FROM gbp_locations l
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            WHERE a.user_id = ? {$activeFilter}
            ORDER BY l.is_active DESC, l.name
        ");
        $stmt->execute([$user['id']]);
        $locations = $stmt->fetchAll();

        jsonResponse(['success' => true, 'locations' => $locations]);
        break;

    // ====== ACTIVER/DESACTIVER UNE FICHE ======
    case 'toggle':
        $locationId = $_POST['location_id'] ?? null;
        if (!$locationId) {
            jsonResponse(['error' => 'location_id requis'], 400);
        }

        $stmt = db()->prepare('
            SELECT l.id, l.is_active FROM gbp_locations l
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            WHERE l.id = ? AND a.user_id = ?
        ');
        $stmt->execute([$locationId, $user['id']]);
        $location = $stmt->fetch();

        if (!$location) {
            jsonResponse(['error' => 'Fiche non trouvee'], 404);
        }

        $newStatus = $location['is_active'] ? 0 : 1;
        $stmt = db()->prepare('UPDATE gbp_locations SET is_active = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$newStatus, $locationId]);

        jsonResponse([
            'success' => true,
            'is_active' => $newStatus,
            'message' => $newStatus ? 'Fiche activee' : 'Fiche desactivee'
        ]);
        break;

    // ====== APERCU DES FICHES GOOGLE (sans import) ======
    case 'preview_locations':
        $stmt = db()->prepare('SELECT * FROM gbp_accounts WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$user['id']]);
        $account = $stmt->fetch();

        if (!$account) {
            jsonResponse(['error' => 'Aucun compte Google connecte. Veuillez reconnecter Google.'], 400);
        }

        $token = getValidGoogleToken($account['id']);
        if (!$token) {
            jsonResponse(['error' => 'Token Google expire. Veuillez reconnecter Google.'], 401);
        }

        $accountsResponse = httpGet('https://mybusinessaccountmanagement.googleapis.com/v1/accounts', [
            'Authorization: Bearer ' . $token,
        ]);

        if (!$accountsResponse || !isset($accountsResponse['accounts'])) {
            jsonResponse(['error' => 'Impossible de recuperer les comptes Google Business.'], 500);
        }

        $availableLocations = [];

        // Recuperer les google_location_id deja en base
        $stmt = db()->prepare('
            SELECT google_location_id FROM gbp_locations l
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            WHERE a.user_id = ?
        ');
        $stmt->execute([$user['id']]);
        $existingIds = array_column($stmt->fetchAll(), 'google_location_id');

        foreach ($accountsResponse['accounts'] as $gbpAccount) {
            $accountName = $gbpAccount['name'];

            // Stocker le google_account_name au passage
            $stmt = db()->prepare('UPDATE gbp_accounts SET google_account_name = ? WHERE id = ?');
            $stmt->execute([$accountName, $account['id']]);

            $pageToken = null;
            do {
                $url = "https://mybusinessbusinessinformation.googleapis.com/v1/{$accountName}/locations?readMask=name,title,storefrontAddress,phoneNumbers,websiteUri,categories,latlng,metadata&pageSize=100";
                if ($pageToken) {
                    $url .= '&pageToken=' . urlencode($pageToken);
                }

                $locations = httpGet($url, ['Authorization: Bearer ' . $token]);
                if (!$locations || !isset($locations['locations'])) break;

                foreach ($locations['locations'] as $loc) {
                    $googleLocationId = $loc['name'] ?? '';
                    $city = '';
                    if (isset($loc['storefrontAddress'])) {
                        $city = $loc['storefrontAddress']['locality'] ?? '';
                    }

                    $availableLocations[] = [
                        'google_location_id' => $googleLocationId,
                        'name' => $loc['title'] ?? '',
                        'city' => $city,
                        'category' => $loc['categories']['primaryCategory']['displayName'] ?? '',
                        'phone' => $loc['phoneNumbers']['primaryPhone'] ?? '',
                        'already_imported' => in_array($googleLocationId, $existingIds),
                    ];
                }

                $pageToken = $locations['nextPageToken'] ?? null;
            } while ($pageToken);
        }

        jsonResponse([
            'success' => true,
            'locations' => $availableLocations,
            'total' => count($availableLocations),
            'already_imported' => count(array_filter($availableLocations, fn($l) => $l['already_imported'])),
        ]);
        break;

    // ====== IMPORTER DES FICHES SELECTIONNEES ======
    case 'import_selected':
        $selectedIds = $_POST['google_location_ids'] ?? [];

        if (empty($selectedIds) || !is_array($selectedIds)) {
            jsonResponse(['error' => 'Selectionnez au moins une fiche'], 400);
        }

        $stmt = db()->prepare('SELECT * FROM gbp_accounts WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$user['id']]);
        $account = $stmt->fetch();

        if (!$account) {
            jsonResponse(['error' => 'Aucun compte Google connecte.'], 400);
        }

        $token = getValidGoogleToken($account['id']);
        if (!$token) {
            jsonResponse(['error' => 'Token Google expire.'], 401);
        }

        $accountsResponse = httpGet('https://mybusinessaccountmanagement.googleapis.com/v1/accounts', [
            'Authorization: Bearer ' . $token,
        ]);

        if (!$accountsResponse || !isset($accountsResponse['accounts'])) {
            jsonResponse(['error' => 'Impossible de recuperer les comptes Google Business.'], 500);
        }

        $imported = 0;
        $updated = 0;

        foreach ($accountsResponse['accounts'] as $gbpAccount) {
            $accountName = $gbpAccount['name'];

            $pageToken = null;
            do {
                $url = "https://mybusinessbusinessinformation.googleapis.com/v1/{$accountName}/locations?readMask=name,title,storefrontAddress,phoneNumbers,websiteUri,categories,latlng,metadata&pageSize=100";
                if ($pageToken) $url .= '&pageToken=' . urlencode($pageToken);

                $locations = httpGet($url, ['Authorization: Bearer ' . $token]);
                if (!$locations || !isset($locations['locations'])) break;

                foreach ($locations['locations'] as $loc) {
                    $googleLocationId = $loc['name'] ?? '';

                    // Seulement les fiches selectionnees
                    if (!in_array($googleLocationId, $selectedIds)) continue;

                    $name = $loc['title'] ?? '';
                    $address = '';
                    $city = '';
                    $postal = '';
                    $phone = $loc['phoneNumbers']['primaryPhone'] ?? null;
                    $website = $loc['websiteUri'] ?? null;
                    $category = $loc['categories']['primaryCategory']['displayName'] ?? null;
                    $lat = $loc['latlng']['latitude'] ?? null;
                    $lng = $loc['latlng']['longitude'] ?? null;
                    $placeId = $loc['metadata']['placeId'] ?? null;
                    $mapsUri = $loc['metadata']['mapsUri'] ?? null;

                    if (isset($loc['storefrontAddress'])) {
                        $addr = $loc['storefrontAddress'];
                        $address = implode(', ', array_filter([
                            $addr['addressLines'][0] ?? '',
                            $addr['addressLines'][1] ?? '',
                        ]));
                        $city = $addr['locality'] ?? '';
                        $postal = $addr['postalCode'] ?? '';
                    }

                    // GPS : on utilise UNIQUEMENT le latlng direct de l'API GBP
                    // Pas de geocoding approximatif ici — l'utilisateur peut le faire
                    // manuellement via le bouton dans l'onglet Parametres

                    $stmt = db()->prepare('SELECT id FROM gbp_locations WHERE gbp_account_id = ? AND google_location_id = ?');
                    $stmt->execute([$account['id'], $googleLocationId]);
                    $existing = $stmt->fetch();

                    if ($existing) {
                        // Si on a deja des coords en base et que l'API n'en retourne pas,
                        // on garde celles en base (ne pas ecraser avec NULL)
                        $updateLat = $lat;
                        $updateLng = $lng;
                        if ($lat === null || $lng === null) {
                            $stmtExist = db()->prepare('SELECT latitude, longitude FROM gbp_locations WHERE id = ?');
                            $stmtExist->execute([$existing['id']]);
                            $existCoords = $stmtExist->fetch();
                            if (!empty($existCoords['latitude']) && !empty($existCoords['longitude'])) {
                                $updateLat = $existCoords['latitude'];
                                $updateLng = $existCoords['longitude'];
                            }
                        }
                        $stmt = db()->prepare('
                            UPDATE gbp_locations SET
                                name = ?, address = ?, city = ?, postal_code = ?,
                                phone = ?, website = ?, category = ?,
                                latitude = ?, longitude = ?, place_id = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ');
                        $stmt->execute([$name, $address, $city, $postal, $phone, $website, $category, $updateLat, $updateLng, $placeId, $existing['id']]);
                        $updated++;
                    } else {
                        $stmt = db()->prepare('
                            INSERT INTO gbp_locations
                                (gbp_account_id, google_location_id, name, address, city, postal_code, phone, website, category, latitude, longitude, place_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ');
                        $stmt->execute([$account['id'], $googleLocationId, $name, $address, $city, $postal, $phone, $website, $category, $lat, $lng, $placeId]);
                        $imported++;
                    }
                }

                $pageToken = $locations['nextPageToken'] ?? null;
            } while ($pageToken);
        }

        jsonResponse([
            'success' => true,
            'imported' => $imported,
            'updated' => $updated,
            'message' => "{$imported} fiche(s) importee(s), {$updated} mise(s) a jour."
        ]);
        break;

    // ====== SYNCHRONISER LES FICHES DEPUIS GOOGLE ======
    case 'sync_locations':
        $stmt = db()->prepare('SELECT * FROM gbp_accounts WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$user['id']]);
        $account = $stmt->fetch();

        if (!$account) {
            jsonResponse(['error' => 'Aucun compte Google connecte. Veuillez reconnecter Google.'], 400);
        }

        $token = getValidGoogleToken($account['id']);
        if (!$token) {
            jsonResponse(['error' => 'Token Google expire. Veuillez reconnecter Google.'], 401);
        }

        // Recuperer les comptes Google Business
        $accountsResponse = httpGet('https://mybusinessaccountmanagement.googleapis.com/v1/accounts', [
            'Authorization: Bearer ' . $token,
        ]);

        if (!$accountsResponse || !isset($accountsResponse['accounts'])) {
            jsonResponse(['error' => 'Impossible de recuperer les comptes Google Business. Verifiez les autorisations.'], 500);
        }

        $imported = 0;
        $updated = 0;

        foreach ($accountsResponse['accounts'] as $gbpAccount) {
            $accountName = $gbpAccount['name']; // Format: accounts/XXXXX

            // Stocker le google_account_name
            $stmt = db()->prepare('UPDATE gbp_accounts SET google_account_name = ? WHERE id = ?');
            $stmt->execute([$accountName, $account['id']]);

            // Recuperer les locations avec pagination
            $pageToken = null;
            do {
                $url = "https://mybusinessbusinessinformation.googleapis.com/v1/{$accountName}/locations?readMask=name,title,storefrontAddress,phoneNumbers,websiteUri,categories,latlng,metadata&pageSize=100";
                if ($pageToken) {
                    $url .= '&pageToken=' . urlencode($pageToken);
                }

                $locations = httpGet($url, ['Authorization: Bearer ' . $token]);

                if (!$locations || !isset($locations['locations'])) {
                    break;
                }

                foreach ($locations['locations'] as $loc) {
                    $googleLocationId = $loc['name'] ?? '';
                    $name = $loc['title'] ?? '';
                    $address = '';
                    $city = '';
                    $postal = '';
                    $phone = $loc['phoneNumbers']['primaryPhone'] ?? null;
                    $website = $loc['websiteUri'] ?? null;
                    $category = $loc['categories']['primaryCategory']['displayName'] ?? null;
                    $lat = $loc['latlng']['latitude'] ?? null;
                    $lng = $loc['latlng']['longitude'] ?? null;
                    $placeId = $loc['metadata']['placeId'] ?? null;
                    $mapsUri = $loc['metadata']['mapsUri'] ?? null;

                    if (isset($loc['storefrontAddress'])) {
                        $addr = $loc['storefrontAddress'];
                        $address = implode(', ', array_filter([
                            $addr['addressLines'][0] ?? '',
                            $addr['addressLines'][1] ?? '',
                        ]));
                        $city = $addr['locality'] ?? '';
                        $postal = $addr['postalCode'] ?? '';
                    }

                    // GPS : on utilise UNIQUEMENT le latlng direct de l'API GBP
                    // Pas de geocoding approximatif — l'utilisateur peut le faire
                    // manuellement via le bouton dans l'onglet Parametres

                    $stmt = db()->prepare('SELECT id FROM gbp_locations WHERE gbp_account_id = ? AND google_location_id = ?');
                    $stmt->execute([$account['id'], $googleLocationId]);
                    $existing = $stmt->fetch();

                    if ($existing) {
                        // Garder les coords existantes si l'API n'en retourne pas
                        $updateLat = $lat;
                        $updateLng = $lng;
                        if ($lat === null || $lng === null) {
                            $stmtExist = db()->prepare('SELECT latitude, longitude FROM gbp_locations WHERE id = ?');
                            $stmtExist->execute([$existing['id']]);
                            $existCoords = $stmtExist->fetch();
                            if (!empty($existCoords['latitude']) && !empty($existCoords['longitude'])) {
                                $updateLat = $existCoords['latitude'];
                                $updateLng = $existCoords['longitude'];
                            }
                        }
                        $stmt = db()->prepare('
                            UPDATE gbp_locations SET
                                name = ?, address = ?, city = ?, postal_code = ?,
                                phone = ?, website = ?, category = ?,
                                latitude = ?, longitude = ?, place_id = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ');
                        $stmt->execute([$name, $address, $city, $postal, $phone, $website, $category, $updateLat, $updateLng, $placeId, $existing['id']]);
                        $updated++;
                    } else {
                        $stmt = db()->prepare('
                            INSERT INTO gbp_locations
                                (gbp_account_id, google_location_id, name, address, city, postal_code, phone, website, category, latitude, longitude, place_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ');
                        $stmt->execute([$account['id'], $googleLocationId, $name, $address, $city, $postal, $phone, $website, $category, $lat, $lng, $placeId]);
                        $imported++;
                    }
                }

                $pageToken = $locations['nextPageToken'] ?? null;
            } while ($pageToken);
        }

        jsonResponse([
            'success' => true,
            'imported' => $imported,
            'updated' => $updated,
            'message' => "Synchronisation terminee : {$imported} nouvelle(s) fiche(s), {$updated} mise(s) a jour."
        ]);
        break;

    // ====== ACTUALISER LES FICHES EXISTANTES (pas d'import) ======
    case 'refresh_locations':
        $stmt = db()->prepare('SELECT * FROM gbp_accounts WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$user['id']]);
        $account = $stmt->fetch();

        if (!$account) {
            jsonResponse(['error' => 'Aucun compte Google connecte. Veuillez reconnecter Google.'], 400);
        }

        $token = getValidGoogleToken($account['id']);
        if (!$token) {
            jsonResponse(['error' => 'Token Google expire. Veuillez reconnecter Google.'], 401);
        }

        // Charger les fiches existantes en base
        $stmtExisting = db()->prepare('SELECT id, google_location_id FROM gbp_locations WHERE gbp_account_id = ?');
        $stmtExisting->execute([$account['id']]);
        $existingMap = [];
        while ($row = $stmtExisting->fetch()) {
            $existingMap[$row['google_location_id']] = $row['id'];
        }

        if (empty($existingMap)) {
            jsonResponse(['error' => 'Aucune fiche a actualiser. Utilisez "Importer des fiches" d\'abord.'], 400);
        }

        $accountsResponse = httpGet('https://mybusinessaccountmanagement.googleapis.com/v1/accounts', [
            'Authorization: Bearer ' . $token,
        ]);

        if (!$accountsResponse || !isset($accountsResponse['accounts'])) {
            jsonResponse(['error' => 'Impossible de recuperer les comptes Google Business.'], 500);
        }

        $refreshed = 0;
        $skipped = 0;

        foreach ($accountsResponse['accounts'] as $gbpAccount) {
            $accountName = $gbpAccount['name'];
            $pageToken = null;
            do {
                $url = "https://mybusinessbusinessinformation.googleapis.com/v1/{$accountName}/locations?readMask=name,title,storefrontAddress,phoneNumbers,websiteUri,categories,latlng,metadata&pageSize=100";
                if ($pageToken) {
                    $url .= '&pageToken=' . urlencode($pageToken);
                }

                $locations = httpGet($url, ['Authorization: Bearer ' . $token]);
                if (!$locations || !isset($locations['locations'])) break;

                foreach ($locations['locations'] as $loc) {
                    $googleLocationId = $loc['name'] ?? '';

                    // UNIQUEMENT mettre a jour les fiches deja presentes
                    if (!isset($existingMap[$googleLocationId])) {
                        $skipped++;
                        continue;
                    }

                    $existingId = $existingMap[$googleLocationId];
                    $name = $loc['title'] ?? '';
                    $address = '';
                    $city = '';
                    $postal = '';
                    $phone = $loc['phoneNumbers']['primaryPhone'] ?? null;
                    $website = $loc['websiteUri'] ?? null;
                    $category = $loc['categories']['primaryCategory']['displayName'] ?? null;
                    $lat = $loc['latlng']['latitude'] ?? null;
                    $lng = $loc['latlng']['longitude'] ?? null;
                    $placeId = $loc['metadata']['placeId'] ?? null;

                    if (isset($loc['storefrontAddress'])) {
                        $addr = $loc['storefrontAddress'];
                        $address = implode(', ', array_filter([
                            $addr['addressLines'][0] ?? '',
                            $addr['addressLines'][1] ?? '',
                        ]));
                        $city = $addr['locality'] ?? '';
                        $postal = $addr['postalCode'] ?? '';
                    }

                    // Garder les coords existantes si l'API n'en retourne pas
                    $updateLat = $lat;
                    $updateLng = $lng;
                    if ($lat === null || $lng === null) {
                        $stmtCoords = db()->prepare('SELECT latitude, longitude FROM gbp_locations WHERE id = ?');
                        $stmtCoords->execute([$existingId]);
                        $existCoords = $stmtCoords->fetch();
                        if (!empty($existCoords['latitude']) && !empty($existCoords['longitude'])) {
                            $updateLat = $existCoords['latitude'];
                            $updateLng = $existCoords['longitude'];
                        }
                    }

                    $stmt = db()->prepare('
                        UPDATE gbp_locations SET
                            name = ?, address = ?, city = ?, postal_code = ?,
                            phone = ?, website = ?, category = ?,
                            latitude = ?, longitude = ?, place_id = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ');
                    $stmt->execute([$name, $address, $city, $postal, $phone, $website, $category, $updateLat, $updateLng, $placeId, $existingId]);
                    $refreshed++;
                }

                $pageToken = $locations['nextPageToken'] ?? null;
            } while ($pageToken);
        }

        jsonResponse([
            'success' => true,
            'refreshed' => $refreshed,
            'skipped' => $skipped,
            'message' => "{$refreshed} fiche(s) actualisee(s) depuis Google." . ($skipped > 0 ? " {$skipped} fiche(s) Google non importee(s) ignoree(s)." : '')
        ]);
        break;

    // ====== SYNCHRONISER LES AVIS D'UNE FICHE ======
    case 'sync_reviews':
        $locationId = $_POST['location_id'] ?? null;

        if ($locationId) {
            $stmt = db()->prepare('
                SELECT l.id, l.google_location_id, l.name, a.id as account_id, a.google_account_name
                FROM gbp_locations l
                JOIN gbp_accounts a ON l.gbp_account_id = a.id
                WHERE l.id = ? AND a.user_id = ? AND l.is_active = 1
            ');
            $stmt->execute([$locationId, $user['id']]);
            $locationsToSync = $stmt->fetchAll();
        } else {
            $stmt = db()->prepare('
                SELECT l.id, l.google_location_id, l.name, a.id as account_id, a.google_account_name
                FROM gbp_locations l
                JOIN gbp_accounts a ON l.gbp_account_id = a.id
                WHERE a.user_id = ? AND l.is_active = 1 AND l.google_location_id IS NOT NULL
            ');
            $stmt->execute([$user['id']]);
            $locationsToSync = $stmt->fetchAll();
        }

        if (empty($locationsToSync)) {
            jsonResponse(['error' => 'Aucune fiche a synchroniser'], 400);
        }

        $totalNew = 0;
        $totalUpdated = 0;
        $syncResults = [];

        foreach ($locationsToSync as $loc) {
            $token = getValidGoogleToken($loc['account_id']);
            if (!$token) {
                $syncResults[] = ['location' => $loc['name'], 'status' => 'error', 'message' => 'Token invalide'];
                continue;
            }

            // Construire le path v4 complet
            $v4Path = buildGoogleV4LocationPath($loc['google_account_name'] ?? '', $loc['google_location_id']);

            $newCount = 0;
            $updatedCount = 0;
            $pageToken = null;

            do {
                $url = "https://mybusiness.googleapis.com/v4/{$v4Path}/reviews";
                if ($pageToken) {
                    $url .= '?pageToken=' . urlencode($pageToken);
                }

                $response = httpGet($url, ['Authorization: Bearer ' . $token]);

                if (!$response || isset($response['error'])) {
                    $syncResults[] = [
                        'location' => $loc['name'],
                        'status' => 'error',
                        'message' => $response['error']['message'] ?? 'Erreur API'
                    ];
                    break;
                }

                $reviews = $response['reviews'] ?? [];

                foreach ($reviews as $review) {
                    $googleReviewId = $review['reviewId'] ?? $review['name'] ?? null;
                    if (!$googleReviewId) continue;

                    $reviewerName = $review['reviewer']['displayName'] ?? 'Anonyme';
                    $reviewerPhoto = $review['reviewer']['profilePhotoUrl'] ?? null;

                    $ratingMap = [
                        'ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5,
                        'STAR_RATING_UNSPECIFIED' => 0,
                    ];
                    $starRating = $review['starRating'] ?? 'STAR_RATING_UNSPECIFIED';
                    $rating = $ratingMap[$starRating] ?? (int)preg_replace('/[^0-9]/', '', $starRating);
                    if ($rating < 1) $rating = 1;
                    if ($rating > 5) $rating = 5;

                    $comment = $review['comment'] ?? null;
                    $reviewDate = isset($review['createTime']) ? date('Y-m-d H:i:s', strtotime($review['createTime'])) : date('Y-m-d H:i:s');

                    $replyText = null;
                    $replyDate = null;
                    $isReplied = 0;
                    if (isset($review['reviewReply'])) {
                        $replyText = $review['reviewReply']['comment'] ?? null;
                        $replyDate = isset($review['reviewReply']['updateTime']) ? date('Y-m-d H:i:s', strtotime($review['reviewReply']['updateTime'])) : null;
                        $isReplied = $replyText ? 1 : 0;
                    }

                    try {
                        $stmt = db()->prepare('
                            INSERT INTO reviews (location_id, google_review_id, reviewer_name, reviewer_photo_url, rating, comment, review_date, reply_text, reply_date, is_replied)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                                reviewer_name = VALUES(reviewer_name),
                                reviewer_photo_url = VALUES(reviewer_photo_url),
                                rating = VALUES(rating),
                                comment = VALUES(comment),
                                reply_text = CASE
                                    WHEN VALUES(is_replied) = 1 THEN VALUES(reply_text)
                                    WHEN reply_source = \'ai_draft\' AND is_replied = 0 THEN reply_text
                                    ELSE NULL
                                END,
                                reply_date = CASE
                                    WHEN VALUES(is_replied) = 1 THEN VALUES(reply_date)
                                    WHEN reply_source = \'ai_draft\' AND is_replied = 0 THEN reply_date
                                    ELSE NULL
                                END,
                                is_replied = IF(VALUES(is_replied) = 1, 1, 0),
                                updated_at = NOW()
                        ');
                        $stmt->execute([
                            $loc['id'], $googleReviewId, $reviewerName, $reviewerPhoto,
                            $rating, $comment, $reviewDate, $replyText, $replyDate, $isReplied
                        ]);

                        if ($stmt->rowCount() === 1) $newCount++;
                        elseif ($stmt->rowCount() === 2) $updatedCount++;
                    } catch (Exception $e) {
                        // Continuer silencieusement
                    }
                }

                $pageToken = $response['nextPageToken'] ?? null;
                if ($pageToken) usleep(300000);

            } while ($pageToken);

            $totalNew += $newCount;
            $totalUpdated += $updatedCount;
            $syncResults[] = ['location' => $loc['name'], 'status' => 'success', 'new' => $newCount, 'updated' => $updatedCount];

            usleep(300000);
        }

        jsonResponse([
            'success' => true,
            'total_new' => $totalNew,
            'total_updated' => $totalUpdated,
            'details' => $syncResults,
            'message' => "Synchronisation terminee : {$totalNew} nouveau(x) avis, {$totalUpdated} mis a jour."
        ]);
        break;

    // ====== POSTER UNE REPONSE SUR GOOGLE ======
    case 'post_reply':
        $reviewId = $_POST['review_id'] ?? null;
        $replyText = trim($_POST['reply_text'] ?? '');

        if (!$reviewId || !$replyText) {
            jsonResponse(['error' => 'review_id et reply_text requis'], 400);
        }

        // Recuperer l'avis avec ses infos Google + google_account_name
        $stmt = db()->prepare('
            SELECT r.*, l.google_location_id, a.id as account_id, a.google_account_name
            FROM reviews r
            JOIN gbp_locations l ON r.location_id = l.id
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            WHERE r.id = ? AND a.user_id = ?
        ');
        $stmt->execute([$reviewId, $user['id']]);
        $review = $stmt->fetch();

        if (!$review) {
            jsonResponse(['error' => 'Avis non trouve'], 404);
        }

        if (empty($review['google_review_id'])) {
            $stmt = db()->prepare('UPDATE reviews SET reply_text = ?, is_replied = 1, reply_date = NOW() WHERE id = ?');
            $stmt->execute([$replyText, $reviewId]);
            jsonResponse(['success' => true, 'posted_to_google' => false, 'message' => 'Reponse sauvegardee (avis manuel, non publiee sur Google).']);
            break;
        }

        $token = getValidGoogleToken($review['account_id']);
        if (!$token) {
            jsonResponse(['error' => 'Token Google expire. Veuillez reconnecter Google.'], 401);
        }

        // Auto-guerison du path via resolveGoogleLocationPath
        $resolved = resolveGoogleLocationPath(
            $review['google_location_id'],
            $review['google_account_name'] ?? '',
            $token,
            (int)($review['location_id'] ?? 0)
        );

        if (!$resolved['success']) {
            // Sauvegarder localement malgre tout
            $stmt = db()->prepare('UPDATE reviews SET reply_text = ?, is_replied = 1, reply_date = NOW() WHERE id = ?');
            $stmt->execute([$replyText, $reviewId]);
            jsonResponse(['success' => true, 'posted_to_google' => false, 'message' => 'Reponse sauvegardee mais fiche introuvable sur Google : ' . $resolved['error']]);
            break;
        }

        $url = "https://mybusiness.googleapis.com/v4/{$resolved['v4_path']}/reviews/{$review['google_review_id']}/reply";
        $result = httpPutJson($url, ['comment' => $replyText], ['Authorization: Bearer ' . $token]);

        $httpCode = $result['_http_code'] ?? 0;

        if ($httpCode >= 200 && $httpCode < 300) {
            $stmt = db()->prepare('
                UPDATE reviews SET reply_text = ?, is_replied = 1, reply_date = NOW(), reply_source = ? WHERE id = ?
            ');
            $replySource = ($_POST['source'] ?? 'manual') === 'ai' ? 'ai_validated' : 'manual';
            $stmt->execute([$replyText, $replySource, $reviewId]);

            jsonResponse(['success' => true, 'posted_to_google' => true, 'message' => 'Reponse publiee sur Google avec succes !']);
        } else {
            $errorMsg = $result['error']['message'] ?? $result['_curl_error'] ?? "Erreur HTTP {$httpCode}";

            $stmt = db()->prepare('UPDATE reviews SET reply_text = ?, is_replied = 1, reply_date = NOW() WHERE id = ?');
            $stmt->execute([$replyText, $reviewId]);

            jsonResponse([
                'success' => true,
                'posted_to_google' => false,
                'message' => 'Reponse sauvegardee localement mais echec publication Google : ' . $errorMsg
            ]);
        }
        break;

    // ====== SUPPRIMER LES DOUBLONS ======
    case 'remove_duplicates':
        // Trouver les google_location_id en double pour cet utilisateur
        $stmt = db()->prepare('
            SELECT l.google_location_id, GROUP_CONCAT(l.id ORDER BY
                (SELECT COUNT(*) FROM reviews WHERE location_id = l.id) DESC,
                (SELECT COUNT(*) FROM keywords WHERE location_id = l.id) DESC,
                (SELECT COUNT(*) FROM google_posts WHERE location_id = l.id) DESC,
                l.id ASC
            ) as ids,
            COUNT(*) as cnt
            FROM gbp_locations l
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            WHERE a.user_id = ? AND l.google_location_id IS NOT NULL AND l.google_location_id != ""
            GROUP BY l.google_location_id
            HAVING cnt > 1
        ');
        $stmt->execute([$user['id']]);
        $duplicates = $stmt->fetchAll();

        if (empty($duplicates)) {
            jsonResponse(['success' => true, 'removed' => 0, 'message' => 'Aucun doublon detecte.']);
            break;
        }

        $totalRemoved = 0;

        foreach ($duplicates as $dup) {
            $ids = explode(',', $dup['ids']);
            $keepId = array_shift($ids); // Garder la premiere (celle avec le plus de donnees)

            foreach ($ids as $removeId) {
                // Supprimer la fiche doublon (CASCADE supprimera keywords, reviews, posts)
                $stmt = db()->prepare('DELETE FROM gbp_locations WHERE id = ?');
                $stmt->execute([(int)$removeId]);
                $totalRemoved++;
            }
        }

        jsonResponse([
            'success' => true,
            'removed' => $totalRemoved,
            'message' => "{$totalRemoved} fiche(s) en double supprimee(s). Les fiches avec le plus de donnees ont ete conservees."
        ]);
        break;

    // ====== SUPPRIMER LES DONNEES DE TEST ======
    case 'cleanup_test_data':
        $stmt = db()->prepare('
            DELETE r FROM reviews r
            JOIN gbp_locations l ON r.location_id = l.id
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            WHERE a.user_id = ? AND r.google_review_id IS NULL
        ');
        $stmt->execute([$user['id']]);
        $deletedReviews = $stmt->rowCount();

        $stmt = db()->prepare('
            DELETE p FROM google_posts p
            JOIN gbp_locations l ON p.location_id = l.id
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            WHERE a.user_id = ? AND p.google_post_id IS NULL AND p.status != "scheduled"
        ');
        $stmt->execute([$user['id']]);
        $deletedPosts = $stmt->rowCount();

        jsonResponse([
            'success' => true,
            'deleted_reviews' => $deletedReviews,
            'deleted_posts' => $deletedPosts,
            'message' => "Nettoyage effectue : {$deletedReviews} avis de test et {$deletedPosts} posts de test supprimes."
        ]);
        break;

    // ====== RESET ALL GPS + RE-SYNC DEPUIS GOOGLE ======
    case 'reset_all_gps_and_resync':
        // Etape 1 : Reset TOUTES les coordonnees GPS de toutes les fiches
        $stmtReset = db()->prepare('
            UPDATE gbp_locations l
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            SET l.latitude = NULL, l.longitude = NULL, l.updated_at = NOW()
            WHERE a.user_id = ?
        ');
        $stmtReset->execute([$user['id']]);
        $resetCount = $stmtReset->rowCount();

        // Etape 2 : Re-fetch depuis l'API GBP pour recuperer les vrais latlng
        $stmt = db()->prepare('SELECT * FROM gbp_accounts WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$user['id']]);
        $account = $stmt->fetch();

        $restoredFromGoogle = 0;
        $restoredFromAddress = 0;
        $sabCount = 0;

        if ($account) {
            $token = getValidGoogleToken($account['id']);
            if ($token) {
                $accountsResponse = httpGet('https://mybusinessaccountmanagement.googleapis.com/v1/accounts', [
                    'Authorization: Bearer ' . $token,
                ]);
                if ($accountsResponse && isset($accountsResponse['accounts'])) {
                    foreach ($accountsResponse['accounts'] as $gbpAccount) {
                        $accountName = $gbpAccount['name'];
                        $pageToken = null;
                        do {
                            // readMask COMPLET — meme que l'import, pour maximiser les donnees recues
                            $url = "https://mybusinessbusinessinformation.googleapis.com/v1/{$accountName}/locations?readMask=name,title,storefrontAddress,latlng,metadata&pageSize=100";
                            if ($pageToken) $url .= '&pageToken=' . urlencode($pageToken);

                            $locations = httpGet($url, ['Authorization: Bearer ' . $token]);
                            if (!$locations || !isset($locations['locations'])) break;

                            foreach ($locations['locations'] as $loc) {
                                $glid = $loc['name'] ?? '';
                                $apiLat = $loc['latlng']['latitude'] ?? null;
                                $apiLng = $loc['latlng']['longitude'] ?? null;

                                // Extraire l'adresse postale pour geocoding fallback
                                $addrStr = '';
                                $addrCity = '';
                                $addrPostal = '';
                                if (isset($loc['storefrontAddress'])) {
                                    $addr = $loc['storefrontAddress'];
                                    $addrStr = implode(', ', array_filter([
                                        $addr['addressLines'][0] ?? '',
                                        $addr['addressLines'][1] ?? '',
                                    ]));
                                    $addrCity = $addr['locality'] ?? '';
                                    $addrPostal = $addr['postalCode'] ?? '';
                                }

                                $finalLat = null;
                                $finalLng = null;
                                $source = '';

                                if ($apiLat && $apiLng) {
                                    // Strategie 1 : latlng direct de l'API Google
                                    $finalLat = $apiLat;
                                    $finalLng = $apiLng;
                                    $source = 'google_latlng';
                                } elseif (!empty($addrStr) && !empty($addrCity)) {
                                    // Strategie 2 : geocoder l'adresse postale complete via Nominatim
                                    $coords = geocodeFullAddress($addrStr, $addrCity, $addrPostal);
                                    if ($coords) {
                                        $finalLat = $coords[0];
                                        $finalLng = $coords[1];
                                        $source = 'address_geocode';
                                    }
                                    // Petit delai pour respecter la rate-limit Nominatim (1 req/sec)
                                    usleep(300000);
                                }

                                if ($finalLat && $finalLng) {
                                    $stmtUpd = db()->prepare('
                                        UPDATE gbp_locations SET latitude = ?, longitude = ?, updated_at = NOW()
                                        WHERE gbp_account_id = ? AND google_location_id = ?
                                    ');
                                    $stmtUpd->execute([$finalLat, $finalLng, $account['id'], $glid]);
                                    if ($stmtUpd->rowCount() > 0) {
                                        if ($source === 'google_latlng') {
                                            $restoredFromGoogle++;
                                        } else {
                                            $restoredFromAddress++;
                                        }
                                    }
                                } else {
                                    $sabCount++;
                                }
                            }

                            $pageToken = $locations['nextPageToken'] ?? null;
                        } while ($pageToken);
                    }
                }
            }
        }

        // Compter combien de fiches n'ont toujours pas de GPS
        $stmtNoGps = db()->prepare('
            SELECT COUNT(*) FROM gbp_locations l
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            WHERE a.user_id = ? AND (l.latitude IS NULL OR l.longitude IS NULL)
        ');
        $stmtNoGps->execute([$user['id']]);
        $noGpsCount = (int)$stmtNoGps->fetchColumn();

        $totalRestored = $restoredFromGoogle + $restoredFromAddress;
        jsonResponse([
            'success' => true,
            'reset_count' => $resetCount,
            'restored_from_google' => $restoredFromGoogle,
            'restored_from_address' => $restoredFromAddress,
            'still_missing' => $noGpsCount,
            'message' => "GPS reinitialise pour {$resetCount} fiches. {$restoredFromGoogle} coords recuperees depuis Google, {$restoredFromAddress} geocodees depuis l'adresse postale." . ($noGpsCount > 0 ? " {$noGpsCount} fiches sans GPS — renseignez l'adresse manuellement dans l'onglet Parametres." : " Toutes les fiches ont un GPS !")
        ]);
        break;

    // ====== NETTOYER LES GPS DUPLIQUES (propagation erronee) ======
    case 'cleanup_bad_gps':
        // Trouver les coords dupliquees (meme lat/lng sur 2+ fiches = propagation erronee)
        $stmt = db()->prepare('
            SELECT latitude, longitude, COUNT(*) as cnt, GROUP_CONCAT(l.id) as ids
            FROM gbp_locations l
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            WHERE a.user_id = ? AND l.latitude IS NOT NULL AND l.longitude IS NOT NULL
            GROUP BY latitude, longitude
            HAVING cnt > 1
        ');
        $stmt->execute([$user['id']]);
        $duplicates = $stmt->fetchAll();

        $resetCount = 0;
        foreach ($duplicates as $dup) {
            // Reset toutes les fiches qui partagent les memes coords
            $ids = explode(',', $dup['ids']);
            foreach ($ids as $id) {
                db()->prepare('UPDATE gbp_locations SET latitude = NULL, longitude = NULL, updated_at = NOW() WHERE id = ?')
                    ->execute([(int)$id]);
                $resetCount++;
            }
        }

        jsonResponse([
            'success' => true,
            'reset_count' => $resetCount,
            'duplicates_found' => count($duplicates),
            'message' => $resetCount > 0
                ? "{$resetCount} fiche(s) avec GPS dupliques reinitialisees. Utilisez le bouton 'Recuperer GPS' dans l'onglet Parametres de chaque fiche."
                : "Aucun GPS duplique detecte."
        ]);
        break;

    // ====== SUPPRIMER UNE FICHE ======
    case 'delete':
        $locationId = $_POST['location_id'] ?? null;
        if (!$locationId) {
            jsonResponse(['error' => 'location_id requis'], 400);
        }

        $stmt = db()->prepare('
            SELECT l.id FROM gbp_locations l
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            WHERE l.id = ? AND a.user_id = ?
        ');
        $stmt->execute([$locationId, $user['id']]);
        if (!$stmt->fetch()) {
            jsonResponse(['error' => 'Fiche non trouvee'], 404);
        }

        $stmt = db()->prepare('DELETE FROM gbp_locations WHERE id = ?');
        $stmt->execute([$locationId]);

        jsonResponse(['success' => true, 'message' => 'Fiche supprimee avec toutes ses donnees.']);
        break;

    // ====== ACTIONS EN MASSE ======
    case 'bulk_delete':
    case 'bulk_deactivate':
        $ids = $_POST['location_ids'] ?? '';
        if (!$ids) {
            jsonResponse(['error' => 'Aucune fiche selectionnee'], 400);
        }

        // Nettoyer et valider les IDs
        $idArray = array_filter(array_map('intval', explode(',', $ids)));
        if (empty($idArray)) {
            jsonResponse(['error' => 'IDs invalides'], 400);
        }

        // Verifier que toutes les fiches appartiennent au user
        $placeholders = implode(',', array_fill(0, count($idArray), '?'));
        $params = array_merge($idArray, [$user['id']]);
        $stmt = db()->prepare("
            SELECT l.id FROM gbp_locations l
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            WHERE l.id IN ({$placeholders}) AND a.user_id = ?
        ");
        $stmt->execute($params);
        $ownedIds = array_column($stmt->fetchAll(), 'id');

        if (empty($ownedIds)) {
            jsonResponse(['error' => 'Fiches non trouvees'], 404);
        }

        $ownedPlaceholders = implode(',', array_fill(0, count($ownedIds), '?'));
        $affected = 0;

        if ($action === 'bulk_delete') {
            $stmt = db()->prepare("DELETE FROM gbp_locations WHERE id IN ({$ownedPlaceholders})");
            $stmt->execute($ownedIds);
            $affected = $stmt->rowCount();
            $msg = "{$affected} fiche(s) supprimee(s) definitivement.";
        } else {
            $stmt = db()->prepare("UPDATE gbp_locations SET is_active = 0, updated_at = NOW() WHERE id IN ({$ownedPlaceholders})");
            $stmt->execute($ownedIds);
            $affected = $stmt->rowCount();
            $msg = "{$affected} fiche(s) desactivee(s).";
        }

        jsonResponse(['success' => true, 'affected' => $affected, 'message' => $msg]);
        break;

    // ====== MIGRATION : ajouter colonnes grid si absentes ======
    case 'migrate_grid_columns':
        try {
            $check = db()->query("SHOW COLUMNS FROM gbp_locations LIKE 'grid_radius_km'");
            if ($check->rowCount() === 0) {
                db()->exec("ALTER TABLE gbp_locations ADD COLUMN grid_radius_km DECIMAL(5,1) DEFAULT NULL AFTER longitude");
                db()->exec("ALTER TABLE gbp_locations ADD COLUMN grid_num_rings TINYINT DEFAULT NULL AFTER grid_radius_km");
                jsonResponse(['success' => true, 'message' => 'Colonnes grid ajoutees.']);
            } else {
                jsonResponse(['success' => true, 'message' => 'Colonnes deja presentes.']);
            }
        } catch (Exception $e) {
            jsonResponse(['error' => $e->getMessage()], 500);
        }
        break;

    default:
        jsonResponse(['error' => 'Action non reconnue'], 400);
}
