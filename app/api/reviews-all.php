<?php
/**
 * BOUS'TACOM — API Avis Global (cross-location)
 * Gere les avis de TOUTES les fiches d'un utilisateur
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();
requireCsrf();

header('Content-Type: application/json');

// ====== AUTO-MIGRATION : fix ENUM reply_source + colonnes manquantes ======
static $mig = false;
if (!$mig) {
    try {
        db()->exec("ALTER TABLE reviews MODIFY COLUMN reply_source ENUM('manual','ai_auto','ai_validated','ai_draft') NULL DEFAULT NULL");
        $rc = [];
        $rcR = db()->query("SHOW COLUMNS FROM reviews");
        while ($row = $rcR->fetch()) { $rc[] = $row['Field']; }
        foreach (['needs_auto_reply' => "TINYINT(1) NOT NULL DEFAULT 0", 'deleted_by_google' => "TINYINT(1) NOT NULL DEFAULT 0", 'deleted_at' => "DATETIME DEFAULT NULL"] as $col => $type) {
            if (!in_array($col, $rc)) db()->exec("ALTER TABLE reviews ADD COLUMN {$col} {$type}");
        }
    } catch (\Throwable $e) {}
    $mig = true;
}

$user = currentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Recuperer les IDs de toutes les fiches de l'utilisateur
$stmt = db()->prepare('
    SELECT l.id FROM gbp_locations l
    JOIN gbp_accounts a ON l.gbp_account_id = a.id
    WHERE a.user_id = ? AND l.is_active = 1
');
$stmt->execute([$user['id']]);
$locationIds = array_column($stmt->fetchAll(), 'id');

if (empty($locationIds)) {
    jsonResponse(['reviews' => [], 'stats' => null, 'pagination' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'pages' => 0]]);
}

$placeholders = implode(',', array_fill(0, count($locationIds), '?'));

switch ($action) {

    // ====== LISTER LES AVIS (TOUTES FICHES) ======
    case 'list':
        $filter = $_GET['filter'] ?? 'all';
        $filterLocation = $_GET['filter_location'] ?? '';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $where = "WHERE r.location_id IN ({$placeholders})";
        $params = $locationIds;

        // Filtre par fiche specifique
        if ($filterLocation && is_numeric($filterLocation)) {
            // Verifier que la fiche appartient a l'utilisateur
            if (in_array((int)$filterLocation, array_map('intval', $locationIds))) {
                $where .= ' AND r.location_id = ?';
                $params[] = (int)$filterLocation;
            }
        }

        // Filtre par statut
        if ($filter === 'unanswered') {
            $where .= ' AND r.is_replied = 0 AND (r.deleted_by_google = 0 OR r.deleted_by_google IS NULL)';
        } elseif ($filter === 'deleted') {
            $where .= ' AND r.deleted_by_google = 1';
        } elseif (is_numeric($filter) && $filter >= 1 && $filter <= 5) {
            $where .= ' AND r.rating = ?';
            $params[] = (int)$filter;
        }

        // Total
        $stmt = db()->prepare("SELECT COUNT(*) FROM reviews r {$where}");
        $stmt->execute($params);
        $total = $stmt->fetchColumn();

        // Avis avec infos de la fiche
        $stmt = db()->prepare("
            SELECT r.*, r.reviewer_name as author_name,
                   l.name as location_name, l.city as location_city, l.category as location_category
            FROM reviews r
            JOIN gbp_locations l ON r.location_id = l.id
            {$where}
            ORDER BY r.review_date DESC
            LIMIT {$perPage} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $reviews = $stmt->fetchAll();

        // Stats globales
        $stmt = db()->prepare("
            SELECT
                COUNT(*) as total,
                ROUND(AVG(rating), 1) as avg_rating,
                SUM(CASE WHEN is_replied = 0 AND (deleted_by_google = 0 OR deleted_by_google IS NULL) THEN 1 ELSE 0 END) as unanswered,
                SUM(CASE WHEN deleted_by_google = 1 THEN 1 ELSE 0 END) as deleted_count,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as stars_5,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as stars_4,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as stars_3,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as stars_2,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as stars_1
            FROM reviews
            WHERE location_id IN ({$placeholders})
        ");
        $stmt->execute($locationIds);
        $stats = $stmt->fetch();

        // Liste des fiches pour le filtre dropdown
        $stmt = db()->prepare("
            SELECT l.id, l.name, l.city,
                   COUNT(r.id) as review_count,
                   SUM(CASE WHEN r.is_replied = 0 AND (r.deleted_by_google = 0 OR r.deleted_by_google IS NULL) THEN 1 ELSE 0 END) as unanswered_count
            FROM gbp_locations l
            LEFT JOIN reviews r ON r.location_id = l.id
            WHERE l.id IN ({$placeholders})
            GROUP BY l.id, l.name, l.city
            ORDER BY l.name
        ");
        $stmt->execute($locationIds);
        $locationsList = $stmt->fetchAll();

        jsonResponse([
            'reviews' => $reviews,
            'stats' => $stats,
            'locations' => $locationsList,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => (int)$total,
                'pages' => ceil($total / $perPage),
            ]
        ]);
        break;

    // ====== GENERER UNE REPONSE IA ======
    case 'generate_reply':
        $reviewId = $_POST['review_id'] ?? null;
        $tone = $_POST['tone'] ?? '';

        if (!$reviewId) {
            jsonResponse(['error' => 'review_id requis'], 400);
        }

        // Recuperer l'avis AVEC verification d'appartenance
        $stmt = db()->prepare("
            SELECT r.*, r.reviewer_name as author_name,
                   l.name as business_name, l.category, l.id as loc_id
            FROM reviews r
            JOIN gbp_locations l ON r.location_id = l.id
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            WHERE r.id = ? AND a.user_id = ?
        ");
        $stmt->execute([$reviewId, $user['id']]);
        $review = $stmt->fetch();

        if (!$review) {
            jsonResponse(['error' => 'Avis non trouve'], 404);
        }

        // Recuperer les settings de LA fiche de l'avis
        $stmt = db()->prepare('SELECT * FROM review_settings WHERE location_id = ?');
        $stmt->execute([$review['loc_id']]);
        $settings = $stmt->fetch() ?: [];

        try {
            $reply = generateReviewReplyWithSettings(
                $review,
                $review['business_name'],
                $review['category'] ?? '',
                $settings,
                $tone
            );
            jsonResponse(['success' => true, 'reply' => $reply]);
        } catch (Exception $e) {
            jsonResponse(['error' => 'Erreur IA: ' . $e->getMessage()], 500);
        }
        break;

    // ====== SAUVEGARDER UNE REPONSE ======
    case 'save_reply':
        $reviewId = $_POST['review_id'] ?? null;
        $replyText = trim($_POST['reply_text'] ?? '');
        $postToGoogle = ($_POST['post_to_google'] ?? '0') === '1';

        if (!$reviewId || !$replyText) {
            jsonResponse(['error' => 'review_id et reply_text requis'], 400);
        }

        // Verifier que l'avis appartient a l'utilisateur + recuperer infos Google
        $stmt = db()->prepare("
            SELECT r.id, r.google_review_id, r.location_id, l.google_location_id, a.id as account_id, a.google_account_name
            FROM reviews r
            JOIN gbp_locations l ON r.location_id = l.id
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            WHERE r.id = ? AND a.user_id = ?
        ");
        $stmt->execute([$reviewId, $user['id']]);
        $reviewInfo = $stmt->fetch();
        if (!$reviewInfo) {
            jsonResponse(['error' => 'Avis non trouve'], 404);
        }

        $postedToGoogle = false;
        $googleError = null;

        // Tenter de publier sur Google si demande
        if ($postToGoogle && !empty($reviewInfo['google_review_id'])) {
            $token = getValidGoogleToken($reviewInfo['account_id']);
            if ($token) {
                // Auto-guerison du path v4
                $resolved = resolveGoogleLocationPath(
                    $reviewInfo['google_location_id'],
                    $reviewInfo['google_account_name'] ?? '',
                    $token,
                    $reviewInfo['location_id'] ?? 0
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
                    $googleError = $resolved['error'] ?? 'Impossible de resoudre le path Google';
                }
            } else {
                $googleError = 'Token Google expire';
            }
        }

        $replySource = $postedToGoogle ? 'ai_validated' : 'manual';
        $stmt = db()->prepare('
            UPDATE reviews SET
                reply_text = ?,
                is_replied = 1,
                reply_date = NOW(),
                reply_source = ?
            WHERE id = ?
        ');
        $stmt->execute([$replyText, $replySource, $reviewId]);

        $response = ['success' => true, 'posted_to_google' => $postedToGoogle];
        if ($googleError) {
            $response['google_error'] = $googleError;
            $response['message'] = 'Reponse sauvegardee mais echec publication Google : ' . $googleError;
        } elseif ($postedToGoogle) {
            $response['message'] = 'Reponse publiee sur Google !';
        }
        jsonResponse($response);
        break;

    // ====== GENERER TOUTES LES REPONSES IA (TOUTES FICHES) ======
    case 'generate_all_replies':
        $generated = 0;
        $errors = 0;
        $processed = [];

        foreach ($locationIds as $lid) {
            // Infos fiche
            $stmtLoc = db()->prepare('SELECT name, category FROM gbp_locations WHERE id = ?');
            $stmtLoc->execute([$lid]);
            $locInfo = $stmtLoc->fetch();
            if (!$locInfo) continue;

            // Settings IA (ou defauts)
            $stmtSet = db()->prepare('SELECT * FROM review_settings WHERE location_id = ?');
            $stmtSet->execute([$lid]);
            $settings = $stmtSet->fetch() ?: [];
            $businessName = $locInfo['name'];
            if (empty($settings['review_signature'])) $settings['review_signature'] = $businessName;
            if (empty($settings['review_intro'])) $settings['review_intro'] = 'Bonjour {prénom},';
            if (empty($settings['review_closing'])) $settings['review_closing'] = 'À bientôt,';

            // Avis sans reponse
            $stmtRev = db()->prepare('
                SELECT r.*, r.reviewer_name as author_name
                FROM reviews r
                WHERE r.location_id = ?
                  AND r.is_replied = 0
                  AND (r.reply_text IS NULL OR r.reply_text = "")
                  AND (r.deleted_by_google = 0 OR r.deleted_by_google IS NULL)
                ORDER BY r.review_date DESC
                LIMIT 10
            ');
            $stmtRev->execute([$lid]);
            $reviews = $stmtRev->fetchAll();
            if (empty($reviews)) continue;

            foreach ($reviews as $rev) {
                try {
                    $reply = generateReviewReplyWithSettings(
                        $rev, $businessName, $locInfo['category'] ?? '', $settings, ''
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
                        $stmtSave->execute([$reply, $rev['id'], $lid]);
                        $generated++;
                        $processed[] = ['id' => $rev['id'], 'location' => $businessName, 'author' => $rev['reviewer_name']];
                    }
                    usleep(500000); // 0.5s entre chaque
                } catch (Exception $e) {
                    $errors++;
                    error_log("generate_all_replies error #{$rev['id']}: " . $e->getMessage());
                }
            }
        }

        jsonResponse([
            'success' => true,
            'generated' => $generated,
            'errors' => $errors,
            'processed' => $processed,
        ]);
        break;

    default:
        jsonResponse(['error' => 'Action non reconnue'], 400);
}
