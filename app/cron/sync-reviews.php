<?php
/**
 * BOUS'TACOM — CRON Synchronisation des avis Google
 * Securise par token SHA-256
 * A configurer sur InfoManiak : quotidien a 07:00
 * URL: https://app.boustacom.fr/app/cron/sync-reviews.php?token=XXXX
 *
 * Utilise l'API Google My Business v4 pour recuperer les avis
 * de toutes les fiches GBP actives et les stocker en base.
 */

require_once __DIR__ . '/../config.php';

// ====== SECURITE ======
$expectedToken = hash('sha256', APP_SECRET . '_cron_sync_reviews');
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

// ====== AUTO-MIGRATION : colonnes + fix ENUM reply_source ======
try {
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
            echo "Migration: colonne '{$col}' ajoutee.\n";
        }
    }
} catch (\Throwable $e) {
    echo "Migration: " . $e->getMessage() . "\n";
}

$now = new DateTime('now', new DateTimeZone('Europe/Paris'));
$results = [];
$totalNew = 0;
$totalUpdated = 0;

echo "=== CRON Sync Avis Google — " . $now->format('Y-m-d H:i:s') . " ===\n";

// ====== RECUPERER TOUTES LES FICHES ACTIVES ======
$stmt = db()->prepare('
    SELECT l.id, l.google_location_id, l.name, a.id as account_id, a.google_account_name
    FROM gbp_locations l
    JOIN gbp_accounts a ON l.gbp_account_id = a.id
    WHERE l.is_active = 1 AND l.google_location_id IS NOT NULL
');
$stmt->execute();
$locations = $stmt->fetchAll();

echo "Fiches actives trouvees : " . count($locations) . "\n\n";

foreach ($locations as $location) {
    $locationId = $location['id'];
    $googleLocationId = $location['google_location_id'];
    $locationName = $location['name'];

    echo "--- {$locationName} ({$googleLocationId}) ---\n";

    // Obtenir un token Google valide
    $token = getValidGoogleToken($location['account_id']);
    if (!$token) {
        echo "  ERREUR: Token Google invalide ou expire. Reconnexion necessaire.\n\n";
        $results[] = ['location' => $locationName, 'status' => 'error', 'message' => 'Token invalide'];
        continue;
    }

    // ====== RECUPERER LES AVIS VIA L'API ======
    $pageToken = null;
    $newCount = 0;
    $updatedCount = 0;
    $pageNum = 0;
    $syncedGoogleIds = []; // IDs Google des avis presents dans l'API (pour detecter les supprimes)

    // Auto-guerison du path via resolveGoogleLocationPath
    $resolved = resolveGoogleLocationPath(
        $googleLocationId,
        $location['google_account_name'] ?? '',
        $token,
        $locationId
    );

    if (!$resolved['success']) {
        echo "  ERREUR: " . $resolved['error'] . "\n\n";
        $results[] = ['location' => $locationName, 'status' => 'error', 'message' => $resolved['error']];
        continue;
    }

    if ($resolved['healed']) {
        echo "  AUTO-GUERISON: Path corrige vers {$resolved['v4_path']}\n";
    }

    do {
        $pageNum++;
        $url = "https://mybusiness.googleapis.com/v4/{$resolved['v4_path']}/reviews";
        if ($pageToken) {
            $url .= '?pageToken=' . urlencode($pageToken);
        }

        $response = httpGet($url, ['Authorization: Bearer ' . $token]);

        if (!$response) {
            echo "  ERREUR: Pas de reponse de l'API Google (page {$pageNum}).\n";
            break;
        }

        if (isset($response['error'])) {
            echo "  ERREUR API: " . ($response['error']['message'] ?? json_encode($response['error'])) . "\n";
            break;
        }

        $reviews = $response['reviews'] ?? [];
        echo "  Page {$pageNum}: " . count($reviews) . " avis\n";

        foreach ($reviews as $review) {
            $googleReviewId = $review['reviewId'] ?? $review['name'] ?? null;
            if (!$googleReviewId) continue;
            $syncedGoogleIds[] = $googleReviewId;

            $reviewerName = $review['reviewer']['displayName'] ?? 'Anonyme';
            $reviewerPhoto = $review['reviewer']['profilePhotoUrl'] ?? null;
            $rating = (int)str_replace('STAR_RATING_', '', $review['starRating'] ?? 'ONE');

            // Convertir le starRating enum en int
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

            // Verifier si la reponse du proprietaire existe
            $replyText = null;
            $replyDate = null;
            $isReplied = 0;
            if (isset($review['reviewReply'])) {
                $replyText = $review['reviewReply']['comment'] ?? null;
                $replyDate = isset($review['reviewReply']['updateTime']) ? date('Y-m-d H:i:s', strtotime($review['reviewReply']['updateTime'])) : null;
                $isReplied = $replyText ? 1 : 0;
            }

            // Determiner si l'avis a besoin d'une reponse auto
            $needsAutoReply = ($isReplied === 0) ? 1 : 0;

            // Inserer ou mettre a jour
            try {
                $stmt = db()->prepare('
                    INSERT INTO reviews (location_id, google_review_id, reviewer_name, reviewer_photo_url, rating, comment, review_date, reply_text, reply_date, is_replied, needs_auto_reply)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                    $locationId, $googleReviewId, $reviewerName, $reviewerPhoto,
                    $rating, $comment, $reviewDate, $replyText, $replyDate, $isReplied, $needsAutoReply
                ]);

                if ($stmt->rowCount() === 1) {
                    $newCount++;
                } elseif ($stmt->rowCount() === 2) {
                    // ON DUPLICATE KEY UPDATE counts as 2 affected rows
                    $updatedCount++;
                }
            } catch (Exception $e) {
                echo "  ERREUR DB pour avis {$googleReviewId}: {$e->getMessage()}\n";
            }
        }

        // Pagination
        $pageToken = $response['nextPageToken'] ?? null;

        // Rate limiting
        if ($pageToken) {
            usleep(300000); // 0.3s entre les pages
        }

    } while ($pageToken);

    // ====== DETECTER LES AVIS SUPPRIMES PAR GOOGLE ======
    // Avis en BDD avec google_review_id qui ne sont plus dans la reponse API
    $deletedCount = 0;
    if (!empty($syncedGoogleIds)) {
        $placeholders = implode(',', array_fill(0, count($syncedGoogleIds), '?'));
        $stmtDel = db()->prepare("
            UPDATE reviews
            SET deleted_by_google = 1, deleted_at = COALESCE(deleted_at, NOW())
            WHERE location_id = ?
              AND google_review_id IS NOT NULL
              AND google_review_id NOT IN ({$placeholders})
              AND deleted_by_google = 0
        ");
        $stmtDel->execute(array_merge([$locationId], $syncedGoogleIds));
        $deletedCount = $stmtDel->rowCount();

        // Aussi : reactiver ceux qui reapparaissent (Google peut restaurer un avis)
        $stmtReact = db()->prepare("
            UPDATE reviews
            SET deleted_by_google = 0, deleted_at = NULL
            WHERE location_id = ?
              AND google_review_id IN ({$placeholders})
              AND deleted_by_google = 1
        ");
        $stmtReact->execute(array_merge([$locationId], $syncedGoogleIds));
    }

    echo "  => {$newCount} nouveau(x), {$updatedCount} mis a jour, {$deletedCount} supprime(s) par Google\n\n";
    $totalNew += $newCount;
    $totalUpdated += $updatedCount;

    $results[] = [
        'location' => $locationName,
        'status' => 'success',
        'new' => $newCount,
        'updated' => $updatedCount,
        'deleted_by_google' => $deletedCount,
    ];

    // Pause entre les fiches
    usleep(500000); // 0.5s
}

echo "=== Termine. {$totalNew} nouveau(x) avis, {$totalUpdated} mis a jour ===\n";
echo json_encode(['results' => $results, 'total_new' => $totalNew, 'total_updated' => $totalUpdated], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
