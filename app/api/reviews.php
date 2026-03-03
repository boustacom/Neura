<?php
/**
 * BOUS'TACOM — API Gestion des Avis Google
 * + Profils IA separés (avis / posts)
 * + Sync automatique des avis Google
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();
requireCsrf();

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$locationId = $_POST['location_id'] ?? $_GET['location_id'] ?? null;

if (!$locationId) {
    jsonResponse(['error' => 'location_id requis'], 400);
}

// Cast en int pour les fonctions typees (PHP 8 strict)
$locationId = (int)$locationId;

// ====== AUTO-MIGRATION : ajouter les colonnes si manquantes ======
static $migrationDone = false;
if (!$migrationDone) {
    try {
        $cols = [];
        $colsResult = db()->query("SHOW COLUMNS FROM review_settings");
        while ($row = $colsResult->fetch()) {
            $cols[] = $row['Field'];
        }
        $toAdd = [
            'review_signature'  => "VARCHAR(255) DEFAULT NULL",
            'review_intro'      => "VARCHAR(255) DEFAULT NULL",
            'review_closing'    => "VARCHAR(255) DEFAULT NULL",
            'posts_tone'        => "VARCHAR(20) DEFAULT NULL",
            'posts_gender'      => "VARCHAR(10) DEFAULT NULL",
            'posts_signature'   => "VARCHAR(255) DEFAULT NULL",
            'posts_instructions'=> "TEXT DEFAULT NULL",
            'business_context'  => "TEXT DEFAULT NULL",
            'review_speech'     => "VARCHAR(10) DEFAULT 'vous'",
            'posts_speech'      => "VARCHAR(10) DEFAULT NULL",
        ];
        foreach ($toAdd as $col => $type) {
            if (!in_array($col, $cols)) {
                db()->exec("ALTER TABLE review_settings ADD COLUMN {$col} {$type}");
            }
        }
    } catch (Exception $e) {
        error_log("reviews.php migration: " . $e->getMessage());
    }
    $migrationDone = true;
}

// ====== AUTO-MIGRATION : colonnes + fix ENUM reply_source ======
static $reviewMigDone = false;
if (!$reviewMigDone) {
    try {
        // Garantir que reply_source accepte 'ai_draft'
        db()->exec("ALTER TABLE reviews MODIFY COLUMN reply_source ENUM('manual','ai_auto','ai_validated','ai_draft') NULL DEFAULT NULL");
        $rc = [];
        $rcResult = db()->query("SHOW COLUMNS FROM reviews");
        while ($row = $rcResult->fetch()) { $rc[] = $row['Field']; }
        $reviewCols = [
            'needs_auto_reply'  => "TINYINT(1) NOT NULL DEFAULT 0",
            'deleted_by_google' => "TINYINT(1) NOT NULL DEFAULT 0",
            'deleted_at'        => "DATETIME DEFAULT NULL",
        ];
        foreach ($reviewCols as $col => $type) {
            if (!in_array($col, $rc)) {
                db()->exec("ALTER TABLE reviews ADD COLUMN {$col} {$type}");
            }
        }
    } catch (Exception $e) {
        error_log("reviews.php migration reviews: " . $e->getMessage());
    }
    $reviewMigDone = true;
}

// Charger les infos de la fiche (pour le nom par defaut de la signature)
$stmtLoc = db()->prepare('
    SELECT l.*, a.id as account_id, a.google_account_name
    FROM gbp_locations l
    JOIN gbp_accounts a ON l.gbp_account_id = a.id
    WHERE l.id = ?
');
$stmtLoc->execute([$locationId]);
$locationData = $stmtLoc->fetch();

switch ($action) {

    // ====== LISTER LES AVIS ======
    case 'list':
        $filter = $_GET['filter'] ?? 'all';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $where = 'WHERE r.location_id = ?';
        $params = [$locationId];

        if ($filter === 'unanswered') {
            $where .= ' AND r.is_replied = 0 AND (r.deleted_by_google = 0 OR r.deleted_by_google IS NULL)';
        } elseif ($filter === 'deleted') {
            $where .= ' AND r.deleted_by_google = 1';
        } elseif (is_numeric($filter) && $filter >= 1 && $filter <= 5) {
            $where .= ' AND r.rating = ?';
            $params[] = (int)$filter;
        }

        $stmt = db()->prepare("SELECT COUNT(*) FROM reviews r {$where}");
        $stmt->execute($params);
        $total = $stmt->fetchColumn();

        $stmt = db()->prepare("
            SELECT r.*, r.reviewer_name as author_name FROM reviews r
            {$where}
            ORDER BY r.review_date DESC
            LIMIT {$perPage} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $reviews = $stmt->fetchAll();

        $stmt = db()->prepare('
            SELECT
                COUNT(*) as total,
                ROUND(AVG(rating), 1) as avg_rating,
                SUM(CASE WHEN is_replied = 0 AND (deleted_by_google = 0 OR deleted_by_google IS NULL) THEN 1 ELSE 0 END) as unanswered,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as stars_5,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as stars_4,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as stars_3,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as stars_2,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as stars_1,
                SUM(CASE WHEN deleted_by_google = 1 THEN 1 ELSE 0 END) as deleted_count
            FROM reviews WHERE location_id = ?
        ');
        $stmt->execute([$locationId]);
        $stats = $stmt->fetch();

        jsonResponse([
            'reviews' => $reviews,
            'stats' => $stats,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => (int)$total,
                'pages' => ceil($total / $perPage),
            ]
        ]);
        break;

    // ====== GÉNÉRER UNE RÉPONSE IA ======
    case 'generate_reply':
        $reviewId = $_POST['review_id'] ?? null;
        $tone = $_POST['tone'] ?? '';

        if (!$reviewId) {
            jsonResponse(['error' => 'review_id requis'], 400);
        }

        $stmt = db()->prepare('SELECT r.*, r.reviewer_name as author_name, l.name as business_name, l.category FROM reviews r JOIN gbp_locations l ON r.location_id = l.id WHERE r.id = ?');
        $stmt->execute([$reviewId]);
        $review = $stmt->fetch();

        if (!$review) {
            jsonResponse(['error' => 'Avis non trouvé'], 404);
        }

        $stmt = db()->prepare('SELECT * FROM review_settings WHERE location_id = ?');
        $stmt->execute([$locationId]);
        $settings = $stmt->fetch() ?: [];

        // Injecter les defauts si vide
        $businessName = $review['business_name'] ?? ($locationData['name'] ?? '');
        if (empty($settings['review_signature'])) $settings['review_signature'] = $businessName;
        if (empty($settings['review_intro'])) $settings['review_intro'] = 'Bonjour {prénom},';
        if (empty($settings['review_closing'])) $settings['review_closing'] = 'À bientôt,';

        try {
            $reply = generateReviewReplyWithSettings(
                $review,
                $businessName,
                $review['category'] ?? '',
                $settings,
                $tone
            );
            jsonResponse(['success' => true, 'reply' => $reply]);
        } catch (Exception $e) {
            jsonResponse(['error' => 'Erreur IA: ' . $e->getMessage()], 500);
        }
        break;

    // ====== SAUVEGARDER UNE RÉPONSE ======
    case 'save_reply':
        $reviewId = $_POST['review_id'] ?? null;
        $replyText = trim($_POST['reply_text'] ?? '');
        $postToGoogle = ($_POST['post_to_google'] ?? '0') === '1';

        if (!$reviewId || !$replyText) {
            jsonResponse(['error' => 'review_id et reply_text requis'], 400);
        }

        $postedToGoogle = false;
        $googleError = null;

        if ($postToGoogle) {
            $stmt = db()->prepare('
                SELECT r.google_review_id, l.id as loc_db_id, l.google_location_id, a.id as account_id, a.google_account_name
                FROM reviews r
                JOIN gbp_locations l ON r.location_id = l.id
                JOIN gbp_accounts a ON l.gbp_account_id = a.id
                WHERE r.id = ? AND r.location_id = ?
            ');
            $stmt->execute([$reviewId, $locationId]);
            $reviewInfo = $stmt->fetch();

            if ($reviewInfo && !empty($reviewInfo['google_review_id'])) {
                $token = getValidGoogleToken($reviewInfo['account_id']);
                if ($token) {
                    // Auto-guerison du path via resolveGoogleLocationPath
                    $resolved = resolveGoogleLocationPath(
                        $reviewInfo['google_location_id'],
                        $reviewInfo['google_account_name'] ?? '',
                        $token,
                        (int)($reviewInfo['loc_db_id'] ?? 0)
                    );

                    if ($resolved['success']) {
                        $url = "https://mybusiness.googleapis.com/v4/{$resolved['v4_path']}/reviews/{$reviewInfo['google_review_id']}/reply";
                        $result = httpPutJson($url, ['comment' => $replyText], ['Authorization: Bearer ' . $token]);
                        $httpCode = $result['_http_code'] ?? 0;
                        if ($httpCode >= 200 && $httpCode < 300) {
                            $postedToGoogle = true;
                        } else {
                            $googleError = $result['error']['message'] ?? "Erreur HTTP {$httpCode}";
                        }
                    } else {
                        $googleError = $resolved['error'];
                    }
                } else {
                    $googleError = 'Token Google expire. Reconnectez votre compte Google.';
                }
            }
        }

        $replySource = $postedToGoogle ? 'ai_validated' : 'manual';
        $stmt = db()->prepare('
            UPDATE reviews SET
                reply_text = ?,
                is_replied = 1,
                reply_date = NOW(),
                reply_source = ?
            WHERE id = ? AND location_id = ?
        ');
        $stmt->execute([$replyText, $replySource, $reviewId, $locationId]);

        $response = ['success' => true, 'posted_to_google' => $postedToGoogle];
        if ($googleError) {
            $response['google_error'] = $googleError;
            $response['message'] = 'Reponse sauvegardee mais echec publication Google : ' . $googleError;
        } elseif ($postedToGoogle) {
            $response['message'] = 'Reponse publiee sur Google !';
        }
        jsonResponse($response);
        break;

    // ====== SAUVEGARDER PROFIL IA AVIS ======
    case 'save_settings':
        $ownerName = trim($_POST['owner_name'] ?? '');
        $defaultTone = $_POST['default_tone'] ?? 'professional';
        $gender = $_POST['gender'] ?? 'neutral';
        $customInstructions = trim($_POST['custom_instructions'] ?? '');
        $reviewSignature = trim($_POST['review_signature'] ?? '');
        $reviewIntro = trim($_POST['review_intro'] ?? '');
        $reviewClosing = trim($_POST['review_closing'] ?? '');
        $reviewSpeech = $_POST['review_speech'] ?? 'vous';

        try {
            $stmt = db()->prepare('
                INSERT INTO review_settings (location_id, owner_name, default_tone, gender, custom_instructions, review_signature, review_intro, review_closing, review_speech)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    owner_name = VALUES(owner_name),
                    default_tone = VALUES(default_tone),
                    gender = VALUES(gender),
                    custom_instructions = VALUES(custom_instructions),
                    review_signature = VALUES(review_signature),
                    review_intro = VALUES(review_intro),
                    review_closing = VALUES(review_closing),
                    review_speech = VALUES(review_speech),
                    updated_at = NOW()
            ');
            $stmt->execute([$locationId, $ownerName, $defaultTone, $gender, $customInstructions, $reviewSignature ?: null, $reviewIntro ?: null, $reviewClosing ?: null, $reviewSpeech]);
            jsonResponse(['success' => true]);
        } catch (Exception $e) {
            jsonResponse(['error' => 'Erreur sauvegarde: ' . $e->getMessage()], 500);
        }
        break;

    // ====== SAUVEGARDER PROFIL IA POSTS ======
    case 'save_posts_settings':
        $postsTone = $_POST['posts_tone'] ?? '';
        $postsGender = $_POST['posts_gender'] ?? '';
        $postsSignature = trim($_POST['posts_signature'] ?? '');
        $postsInstructions = trim($_POST['posts_instructions'] ?? '');
        $postsSpeech = $_POST['posts_speech'] ?? '';
        $businessContext = trim($_POST['business_context'] ?? '');

        try {
            // Utiliser INSERT...ON DUPLICATE KEY UPDATE pour fiabilite maximale
            $stmt = db()->prepare('
                INSERT INTO review_settings (location_id, posts_tone, posts_gender, posts_signature, posts_instructions, posts_speech, business_context, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    posts_tone = VALUES(posts_tone),
                    posts_gender = VALUES(posts_gender),
                    posts_signature = VALUES(posts_signature),
                    posts_instructions = VALUES(posts_instructions),
                    posts_speech = VALUES(posts_speech),
                    business_context = VALUES(business_context),
                    updated_at = NOW()
            ');
            $stmt->execute([
                $locationId,
                $postsTone ?: null,
                $postsGender ?: null,
                $postsSignature ?: null,
                $postsInstructions ?: null,
                $postsSpeech ?: null,
                $businessContext ?: null
            ]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            jsonResponse(['error' => 'Erreur sauvegarde: ' . $e->getMessage() . ' | location_id=' . $locationId], 500);
        }
        break;

    // ====== CHARGER LES PARAMÈTRES (avis + posts) ======
    case 'get_settings':
        $stmt = db()->prepare('SELECT * FROM review_settings WHERE location_id = ?');
        $stmt->execute([$locationId]);
        $settings = $stmt->fetch() ?: [];

        // Defauts intelligents
        $businessName = $locationData['name'] ?? '';
        $defaults = [
            'owner_name'        => '',
            'default_tone'      => 'professional',
            'gender'            => 'neutral',
            'custom_instructions'=> '',
            'review_signature'  => $businessName,
            'review_intro'      => 'Bonjour {prénom},',
            'review_closing'    => 'À bientôt,',
            'review_speech'     => 'vous',
            'posts_tone'        => 'professional',
            'posts_gender'      => 'neutral',
            'posts_signature'   => '',
            'posts_instructions'=> "Rédige de vrais article bien détaillés et pensé pour le SEO ! Commence par un titre qui fera office d'objet tu peux le mettre en majuscule faut qu'il soit court et accrocheur soit sous forme de question soit sous forme d'affirmation. Pas d'émoji !",
            'posts_speech'      => 'vous',
            'business_context'  => '',
        ];
        foreach ($defaults as $key => $default) {
            if (!isset($settings[$key]) || $settings[$key] === null || $settings[$key] === '') {
                $settings[$key] = $default;
            }
        }
        // posts_signature fallback sur review_signature
        if (empty($settings['posts_signature'])) {
            $settings['posts_signature'] = $settings['review_signature'];
        }

        jsonResponse([
            'success' => true,
            'settings' => $settings,
            'location_name' => $businessName,
        ]);
        break;

    // ====== CHARGER LOCATION + SETTINGS (pour onglet Paramètres) ======
    case 'get_location_settings':
        $lat = $locationData['latitude'] ?? null;
        $lng = $locationData['longitude'] ?? null;
        $placeId = $locationData['place_id'] ?? '';
        $googleLocId = $locationData['google_location_id'] ?? '';

        // PAS de geocoding automatique ici — l'utilisateur utilise le bouton "Recuperer GPS"
        // pour eviter de propager des coords erronees

        // Location data
        $location = [
            'id'            => $locationData['id'] ?? null,
            'name'          => $locationData['name'] ?? '',
            'address'       => $locationData['address'] ?? '',
            'city'          => $locationData['city'] ?? '',
            'postal_code'   => $locationData['postal_code'] ?? '',
            'phone'         => $locationData['phone'] ?? '',
            'website'       => $locationData['website'] ?? '',
            'category'      => $locationData['category'] ?? '',
            'latitude'      => $lat,
            'longitude'     => $lng,
            'place_id'      => $placeId,
            'google_location_id' => $locationData['google_location_id'] ?? '',
            'grid_radius_km'  => $locationData['grid_radius_km'] ?? null,
            'grid_num_rings'  => $locationData['grid_num_rings'] ?? null,
            'report_email'         => $locationData['report_email'] ?? '',
            'report_contact_name'  => $locationData['report_contact_name'] ?? '',
            'logo_path'            => $locationData['logo_path'] ?? null,
            'signature_enabled'    => (int)($locationData['signature_enabled'] ?? 1),
            'signature_text'       => $locationData['signature_text'] ?? '',
            'created_at'           => $locationData['created_at'] ?? null,
        ];

        // Settings (même logique que get_settings)
        $stmtS = db()->prepare('SELECT * FROM review_settings WHERE location_id = ?');
        $stmtS->execute([$locationId]);
        $settingsLoc = $stmtS->fetch() ?: [];

        $bName = $locationData['name'] ?? '';
        $defaultsLoc = [
            'owner_name'        => '',
            'default_tone'      => 'professional',
            'gender'            => 'neutral',
            'custom_instructions'=> '',
            'review_signature'  => $bName,
            'review_intro'      => 'Bonjour {prénom},',
            'review_closing'    => 'À bientôt,',
            'review_speech'     => 'vous',
            'posts_tone'        => 'professional',
            'posts_gender'      => 'neutral',
            'posts_signature'   => '',
            'posts_instructions'=> "Rédige de vrais article bien détaillés et pensé pour le SEO ! Commence par un titre qui fera office d'objet tu peux le mettre en majuscule faut qu'il soit court et accrocheur soit sous forme de question soit sous forme d'affirmation. Pas d'émoji !",
            'posts_speech'      => 'vous',
        ];
        foreach ($defaultsLoc as $key => $default) {
            if (!isset($settingsLoc[$key]) || $settingsLoc[$key] === null || $settingsLoc[$key] === '') {
                $settingsLoc[$key] = $default;
            }
        }
        if (empty($settingsLoc['posts_signature'])) {
            $settingsLoc['posts_signature'] = $settingsLoc['review_signature'];
        }

        jsonResponse([
            'success' => true,
            'location' => $location,
            'settings' => $settingsLoc,
        ]);
        break;

    // ====== SYNC AVIS GOOGLE (auto-sync a l'ouverture) ======
    case 'sync':
        if (!$locationData || empty($locationData['google_location_id'])) {
            jsonResponse(['success' => true, 'synced' => 0, 'message' => 'Pas de fiche Google connectee']);
        }

        // Rate limit : 1 sync / 5 min max
        $tmpDir = __DIR__ . '/../tmp';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
        $syncLockFile = $tmpDir . '/sync_reviews_' . $locationId . '.txt';
        if (file_exists($syncLockFile)) {
            $lastSync = (int)file_get_contents($syncLockFile);
            if (time() - $lastSync < 300) {
                jsonResponse(['success' => true, 'synced' => 0, 'message' => 'Sync recent (< 5 min)', 'last_sync' => date('H:i:s', $lastSync)]);
            }
        }
        file_put_contents($syncLockFile, (string)time());

        $token = getValidGoogleToken($locationData['account_id']);
        if (!$token) {
            jsonResponse(['success' => true, 'synced' => 0, 'message' => 'Token Google invalide']);
        }

        $googleLocationId = $locationData['google_location_id'];
        $pageToken = null;
        $newCount = 0;
        $updatedCount = 0;
        $syncedGoogleIds = [];

        do {
            $v4Path = buildGoogleV4LocationPath($locationData['google_account_name'] ?? '', $googleLocationId);
            $url = "https://mybusiness.googleapis.com/v4/{$v4Path}/reviews";
            if ($pageToken) $url .= '?pageToken=' . urlencode($pageToken);

            $response = httpGet($url, ['Authorization: Bearer ' . $token]);
            if (!$response || isset($response['error'])) break;

            $reviews = $response['reviews'] ?? [];
            foreach ($reviews as $review) {
                $googleReviewId = $review['reviewId'] ?? $review['name'] ?? null;
                if (!$googleReviewId) continue;
                $syncedGoogleIds[] = $googleReviewId;

                $reviewerName = $review['reviewer']['displayName'] ?? 'Anonyme';
                $reviewerPhoto = $review['reviewer']['profilePhotoUrl'] ?? null;
                $ratingMap = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];
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
                    $stmt->execute([$locationId, $googleReviewId, $reviewerName, $reviewerPhoto, $rating, $comment, $reviewDate, $replyText, $replyDate, $isReplied]);
                    if ($stmt->rowCount() === 1) $newCount++;
                    elseif ($stmt->rowCount() === 2) $updatedCount++;
                } catch (Exception $e) {
                    error_log("reviews.php sync: " . $e->getMessage());
                }
            }

            $pageToken = $response['nextPageToken'] ?? null;
            if ($pageToken) usleep(300000);
        } while ($pageToken);

        // Detecter les supprimes par Google
        if (!empty($syncedGoogleIds)) {
            $ph = implode(',', array_fill(0, count($syncedGoogleIds), '?'));
            db()->prepare("UPDATE reviews SET deleted_by_google = 1, deleted_at = COALESCE(deleted_at, NOW()) WHERE location_id = ? AND google_review_id IS NOT NULL AND google_review_id NOT IN ({$ph}) AND deleted_by_google = 0")
                ->execute(array_merge([$locationId], $syncedGoogleIds));
            db()->prepare("UPDATE reviews SET deleted_by_google = 0, deleted_at = NULL WHERE location_id = ? AND google_review_id IN ({$ph}) AND deleted_by_google = 1")
                ->execute(array_merge([$locationId], $syncedGoogleIds));
        }

        jsonResponse(['success' => true, 'synced' => $newCount + $updatedCount, 'new' => $newCount, 'updated' => $updatedCount]);
        break;

    // ====== SAUVEGARDER CONTACT RAPPORT ======
    case 'save_report_contact':
        if (!$locationData) {
            jsonResponse(['error' => 'Fiche non trouvee'], 404);
        }
        $rptEmail = trim($_POST['report_email'] ?? '');
        $rptContact = trim($_POST['report_contact_name'] ?? '');

        $stmt = db()->prepare('UPDATE gbp_locations SET report_email = ?, report_contact_name = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$rptEmail ?: null, $rptContact ?: null, $locationData['id']]);
        jsonResponse(['success' => true]);
        break;

    // ====== SAUVEGARDER LA SIGNATURE VISUELS ======
    case 'save_signature':
        if (!$locationData) {
            jsonResponse(['error' => 'Fiche non trouvee'], 404);
        }
        $sigEnabled = (int)($_POST['signature_enabled'] ?? 1);
        $sigText = trim($_POST['signature_text'] ?? '');

        $stmt = db()->prepare('UPDATE gbp_locations SET signature_enabled = ?, signature_text = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$sigEnabled ? 1 : 0, $sigText ?: null, $locationData['id']]);
        jsonResponse(['success' => true]);
        break;

    // ====== SAUVEGARDER LES COORDONNEES GPS (saisie manuelle ou geocodage) ======
    case 'save_gps':
        if (!$locationData) {
            jsonResponse(['error' => 'Fiche non trouvee'], 404);
        }
        $saveLat = $_POST['latitude'] ?? null;
        $saveLng = $_POST['longitude'] ?? null;
        if (!$saveLat || !$saveLng || !is_numeric($saveLat) || !is_numeric($saveLng)) {
            jsonResponse(['error' => 'Coordonnees GPS invalides'], 400);
        }
        $saveLat = (float)$saveLat;
        $saveLng = (float)$saveLng;
        if ($saveLat < -90 || $saveLat > 90 || $saveLng < -180 || $saveLng > 180) {
            jsonResponse(['error' => 'Coordonnees hors limites'], 400);
        }
        $stmtSaveGps = db()->prepare('UPDATE gbp_locations SET latitude = ?, longitude = ?, updated_at = NOW() WHERE id = ?');
        $stmtSaveGps->execute([$saveLat, $saveLng, $locationData['id']]);
        jsonResponse(['success' => true, 'message' => "Coordonnees GPS sauvegardees : {$saveLat}, {$saveLng}"]);
        break;

    // ====== REINITIALISER LES COORDONNEES GPS ======
    case 'reset_gps':
        if (!$locationData) {
            jsonResponse(['error' => 'Fiche non trouvee'], 404);
        }
        $stmtReset = db()->prepare('UPDATE gbp_locations SET latitude = NULL, longitude = NULL, updated_at = NOW() WHERE id = ?');
        $stmtReset->execute([$locationData['id']]);
        jsonResponse(['success' => true, 'message' => 'Coordonnees GPS reinitialisees.']);
        break;

    // ====== FORCER LE GEOCODING GPS ======
    case 'force_geocode':
        if (!$locationData) {
            jsonResponse(['error' => 'Fiche non trouvee'], 404);
        }

        $placeIdGeo = $locationData['place_id'] ?? '';
        $googleLocIdGeo = $locationData['google_location_id'] ?? '';
        $geoLat = null;
        $geoLng = null;
        $method = '';

        $gToken = !empty($locationData['account_id']) ? getValidGoogleToken($locationData['account_id']) : null;

        // Strategie 1 : API GBP avec readMask=latlng,metadata
        if ($gToken && $googleLocIdGeo) {
            $gbpUrl = "https://mybusinessbusinessinformation.googleapis.com/v1/{$googleLocIdGeo}?readMask=latlng,metadata";
            $ch = curl_init($gbpUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $gToken],
                CURLOPT_TIMEOUT        => 10,
            ]);
            $gbpResp = curl_exec($ch);
            $gbpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $debugInfo = ['gbp_http' => $gbpCode];

            if ($gbpCode === 200 && $gbpResp) {
                $gbpData = json_decode($gbpResp, true);

                // latlng direct ?
                if (!empty($gbpData['latlng']['latitude']) && !empty($gbpData['latlng']['longitude'])) {
                    $geoLat = (float)$gbpData['latlng']['latitude'];
                    $geoLng = (float)$gbpData['latlng']['longitude'];
                    $method = 'GBP latlng direct';
                }

                // mapsUri ? On l'affiche en entier dans le debug
                $mapsUriFromGbp = $gbpData['metadata']['mapsUri'] ?? null;
                $debugInfo['mapsUri'] = $mapsUriFromGbp ?: '(absent)';

                // 1a. Essayer de parser l'URL directement
                if (empty($geoLat) && $mapsUriFromGbp) {
                    $coords = extractCoordsFromMapsUrl($mapsUriFromGbp);
                    if ($coords) {
                        $geoLat = $coords[0];
                        $geoLng = $coords[1];
                        $method = 'GBP mapsUri parsing direct';
                    }
                }

                // 1b. Si pas de coords dans l'URL, SUIVRE le mapsUri (redirection Google)
                if (empty($geoLat) && $mapsUriFromGbp) {
                    $ch = curl_init($mapsUriFromGbp);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_MAXREDIRS      => 10,
                        CURLOPT_TIMEOUT        => 15,
                        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    ]);
                    $mapsHtml = curl_exec($ch);
                    $mapsRedirectUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                    curl_close($ch);

                    $debugInfo['mapsUri_redirect'] = $mapsRedirectUrl;

                    // Essayer l'URL de redirection
                    if ($mapsRedirectUrl) {
                        $coords = extractCoordsFromMapsUrl($mapsRedirectUrl);
                        if ($coords) {
                            $geoLat = $coords[0];
                            $geoLng = $coords[1];
                            $method = 'GBP mapsUri redirect URL';
                        }
                    }

                    // Essayer dans le HTML retourne
                    if (empty($geoLat) && $mapsHtml) {
                        // Pattern @lat,lng
                        if (preg_match('/@(-?\d+\.\d{4,}),(-?\d+\.\d{4,})/', $mapsHtml, $m)) {
                            $geoLat = (float)$m[1];
                            $geoLng = (float)$m[2];
                            $method = 'GBP mapsUri HTML @lat,lng';
                        }
                        // Pattern [null,null,lat,lng]
                        if (empty($geoLat) && preg_match('/\[null,null,(-?\d+\.\d{4,}),(-?\d+\.\d{4,})\]/', $mapsHtml, $m)) {
                            $geoLat = (float)$m[1];
                            $geoLng = (float)$m[2];
                            $method = 'GBP mapsUri HTML [null,null,lat,lng]';
                        }
                        // Pattern !3d...!4d... dans le HTML
                        if (empty($geoLat) && preg_match('/!3d(-?\d+\.\d{4,})!4d(-?\d+\.\d{4,})/', $mapsHtml, $m)) {
                            $geoLat = (float)$m[1];
                            $geoLng = (float)$m[2];
                            $method = 'GBP mapsUri HTML !3d!4d';
                        }
                        // Garder un extrait du HTML pour debug
                        $debugInfo['mapsUri_html_length'] = strlen($mapsHtml);
                        // Chercher tous les patterns numeriques qui ressemblent a des coords
                        if (empty($geoLat) && preg_match_all('/([-]?\d{1,3}\.\d{4,})\D{1,5}([-]?\d{1,3}\.\d{4,})/', $mapsHtml, $allMatches, PREG_SET_ORDER)) {
                            $candidates = [];
                            foreach ($allMatches as $match) {
                                $cLat = (float)$match[1];
                                $cLng = (float)$match[2];
                                // Filtrer : lat entre -90/90, lng entre -180/180, et probablement en France
                                if (abs($cLat) <= 90 && abs($cLng) <= 180 && $cLat > 41 && $cLat < 52 && $cLng > -5 && $cLng < 10) {
                                    $candidates[] = [$cLat, $cLng];
                                }
                            }
                            if (!empty($candidates)) {
                                $geoLat = $candidates[0][0];
                                $geoLng = $candidates[0][1];
                                $method = 'GBP mapsUri HTML pattern scan';
                                $debugInfo['candidates_count'] = count($candidates);
                                $debugInfo['first_candidates'] = array_slice($candidates, 0, 5);
                            }
                        }
                    }
                }
            }
        }

        // Note: les anciens fallbacks ValueSERP (strategies 2 et 3) ont ete supprimes
        // lors de la migration vers DataForSEO. Le geocoding repose desormais sur
        // l'API GBP (latlng + mapsUri) et Google Maps redirect uniquement.

        if (!empty($geoLat) && !empty($geoLng)) {
            $stmtUpd = db()->prepare('UPDATE gbp_locations SET latitude = ?, longitude = ?, updated_at = NOW() WHERE id = ?');
            $stmtUpd->execute([$geoLat, $geoLng, $locationData['id']]);
            jsonResponse([
                'success' => true,
                'latitude' => $geoLat,
                'longitude' => $geoLng,
                'method' => $method,
                'message' => "Coordonnees GPS recuperees via {$method} : {$geoLat}, {$geoLng}"
            ]);
        } else {
            jsonResponse([
                'success' => false,
                'error' => 'Impossible de recuperer les coordonnees GPS avec aucune des strategies disponibles.',
                'debug' => $debugInfo ?? [],
            ]);
        }
        break;

    // ====== SAUVEGARDER PARAMÈTRES GRILLE (rayon + densité) ======
    case 'save_grid_settings':
        if (!$locationData) {
            jsonResponse(['error' => 'Fiche non trouvee'], 404);
        }
        $gridRadius = $_POST['grid_radius_km'] ?? null;
        $gridRings = $_POST['grid_num_rings'] ?? null;
        if ($gridRadius !== null) $gridRadius = (float)$gridRadius;
        if ($gridRings !== null) $gridRings = (int)$gridRings;
        if ($gridRadius !== null && ($gridRadius < 1 || $gridRadius > 50)) {
            jsonResponse(['error' => 'Rayon invalide (1-50 km)'], 400);
        }
        if ($gridRings !== null && ($gridRings < 1 || $gridRings > 6)) {
            jsonResponse(['error' => 'Nombre de rings invalide (1-6)'], 400);
        }
        try {
            $stmt = db()->prepare('UPDATE gbp_locations SET grid_radius_km = ?, grid_num_rings = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$gridRadius, $gridRings, $locationData['id']]);
            $totalPoints = $gridRings ? 1 + 3 * $gridRings * ($gridRings + 1) : null;
            jsonResponse(['success' => true, 'message' => "Parametres grille sauvegardes : {$gridRadius} km, {$gridRings} rings ({$totalPoints} points)"]);
        } catch (Exception $e) {
            jsonResponse(['error' => 'Erreur sauvegarde: ' . $e->getMessage()], 500);
        }
        break;

    // ====== SUPPRIMER UN AVIS ======
    case 'delete':
        $reviewId = $_POST['review_id'] ?? null;
        if (!$reviewId) {
            jsonResponse(['error' => 'review_id requis'], 400);
        }

        $stmt = db()->prepare('DELETE FROM reviews WHERE id = ? AND location_id = ?');
        $stmt->execute([$reviewId, $locationId]);
        jsonResponse(['success' => true]);
        break;

    // ====== GENERER LES REPONSES IA POUR TOUS LES AVIS SANS REPONSE ======
    case 'generate_all_replies':
        // Recuperer les parametres IA de la location
        $stmt = db()->prepare('SELECT * FROM review_settings WHERE location_id = ?');
        $stmt->execute([$locationId]);
        $settings = $stmt->fetch() ?: [];

        // Infos de la fiche
        $stmt = db()->prepare('SELECT name, category FROM gbp_locations WHERE id = ?');
        $stmt->execute([$locationId]);
        $locInfo = $stmt->fetch();
        $businessName = $locInfo['name'] ?? '';
        $category = $locInfo['category'] ?? '';

        // Injecter les defauts si vide
        if (empty($settings['review_signature'])) $settings['review_signature'] = $businessName;
        if (empty($settings['review_intro'])) $settings['review_intro'] = 'Bonjour {prénom},';
        if (empty($settings['review_closing'])) $settings['review_closing'] = 'À bientôt,';

        // Recuperer les avis sans reponse IA (max 10 par batch)
        $stmt = db()->prepare('
            SELECT r.*, r.reviewer_name as author_name
            FROM reviews r
            WHERE r.location_id = ?
              AND r.is_replied = 0
              AND (r.reply_text IS NULL OR r.reply_text = "")
              AND r.deleted_by_google = 0
            ORDER BY r.review_date DESC
            LIMIT 10
        ');
        $stmt->execute([$locationId]);
        $reviewsToReply = $stmt->fetchAll();

        $generated = 0;
        $errors = 0;

        foreach ($reviewsToReply as $rev) {
            try {
                $reply = generateReviewReplyWithSettings(
                    $rev,
                    $businessName,
                    $category,
                    $settings,
                    '' // pas de tone force
                );

                if ($reply) {
                    $stmtSave = db()->prepare('
                        UPDATE reviews SET
                            reply_text = ?,
                            reply_source = "ai_draft",
                            needs_auto_reply = 0,
                            updated_at = NOW()
                        WHERE id = ? AND location_id = ?
                    ');
                    $stmtSave->execute([$reply, $rev['id'], $locationId]);
                    $generated++;
                }

                // Rate limiting entre chaque generation
                usleep(500000); // 0.5s
            } catch (Exception $e) {
                $errors++;
                error_log("generate_all_replies error for review #{$rev['id']}: " . $e->getMessage());
            }
        }

        jsonResponse([
            'success' => true,
            'generated' => $generated,
            'errors' => $errors,
            'total_pending' => count($reviewsToReply),
        ]);
        break;

    default:
        jsonResponse(['error' => 'Action non reconnue'], 400);
}
