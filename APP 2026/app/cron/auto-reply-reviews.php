<?php
/**
 * BOUS'TACOM — CRON Generation automatique des reponses IA
 * Securise par token SHA-256
 * A configurer sur InfoManiak : quotidien a 07:30
 * URL: https://app.boustacom.fr/app/cron/auto-reply-reviews.php?token=XXXX
 *
 * Pour chaque location avec review_settings configure :
 * genere les reponses IA pour les avis needs_auto_reply = 1
 * Sauvegarde en ai_draft (jamais publication auto)
 */

require_once __DIR__ . '/../config.php';

// ====== SECURITE ======
$expectedToken = hash('sha256', APP_SECRET . '_cron_auto_reply');
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
    // Garantir que reply_source accepte 'ai_draft'
    db()->exec("ALTER TABLE reviews MODIFY COLUMN reply_source ENUM('manual','ai_auto','ai_validated','ai_draft') NULL DEFAULT NULL");
    // Ajouter colonnes manquantes
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
$totalGenerated = 0;

echo "=== CRON Auto-Reply Reviews — " . $now->format('Y-m-d H:i:s') . " ===\n";

// ====== RECUPERER TOUTES LES LOCATIONS AVEC SETTINGS IA ======
$stmt = db()->prepare('
    SELECT l.id, l.name, l.category, rs.*, a.id as account_id
    FROM gbp_locations l
    LEFT JOIN review_settings rs ON rs.location_id = l.id
    JOIN gbp_accounts a ON l.gbp_account_id = a.id
    WHERE l.is_active = 1
');
$stmt->execute();
$locations = $stmt->fetchAll();

echo "Locations avec profil IA : " . count($locations) . "\n\n";

foreach ($locations as $loc) {
    $locationId = $loc['id'];
    $locationName = $loc['name'];
    $category = $loc['category'] ?? '';

    // Construire les settings
    $settings = $loc;
    $businessName = $locationName;
    if (empty($settings['review_signature'])) $settings['review_signature'] = $businessName;
    if (empty($settings['review_intro'])) $settings['review_intro'] = 'Bonjour {prénom},';
    if (empty($settings['review_closing'])) $settings['review_closing'] = 'À bientôt,';

    // Recuperer les avis necessitant une reponse auto
    $stmtReviews = db()->prepare('
        SELECT r.*, r.reviewer_name as author_name
        FROM reviews r
        WHERE r.location_id = ?
          AND (r.needs_auto_reply = 1 OR (r.is_replied = 0 AND (r.reply_text IS NULL OR r.reply_text = "")))
          AND r.deleted_by_google = 0
        ORDER BY r.review_date DESC
        LIMIT 20
    ');
    $stmtReviews->execute([$locationId]);
    $reviews = $stmtReviews->fetchAll();

    if (empty($reviews)) {
        echo "--- {$locationName} : aucun avis a traiter\n";
        continue;
    }

    echo "--- {$locationName} : " . count($reviews) . " avis a traiter ---\n";
    $generated = 0;
    $errors = 0;

    foreach ($reviews as $rev) {
        try {
            $reply = generateReviewReplyWithSettings(
                $rev,
                $businessName,
                $category,
                $settings,
                ''
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
                echo "  + Avis #{$rev['id']} ({$rev['reviewer_name']}) : OK\n";
            }

            // Rate limiting : 2s entre chaque generation
            sleep(2);
        } catch (Exception $e) {
            $errors++;
            echo "  ! Avis #{$rev['id']} : ERREUR — {$e->getMessage()}\n";
            error_log("auto-reply-reviews error #{$rev['id']}: " . $e->getMessage());
        }
    }

    echo "  => {$generated} generee(s), {$errors} erreur(s)\n\n";
    $totalGenerated += $generated;

    $results[] = [
        'location' => $locationName,
        'generated' => $generated,
        'errors' => $errors,
    ];

    // Pause entre les locations
    usleep(500000); // 0.5s
}

echo "=== Termine. {$totalGenerated} reponse(s) IA generee(s) au total ===\n";
echo json_encode(['results' => $results, 'total_generated' => $totalGenerated], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
