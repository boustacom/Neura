<?php
/**
 * BOUS'TACOM — Fix reply_source ENUM
 */
require_once __DIR__ . '/../config.php';

$expectedToken = hash('sha256', APP_SECRET . '_cron_fix_columns');
$providedToken = $_GET['token'] ?? '';

if (!hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    die('Token invalide');
}

header('Content-Type: text/plain; charset=utf-8');
http_response_code(200);
ini_set('display_errors', 1);
error_reporting(E_ALL);
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "\n\n!!! FATAL ERROR: {$err['message']} in {$err['file']}:{$err['line']}\n";
    }
});

echo "=== FIX REPLY_SOURCE ENUM ===\n\n";

// 1. Modifier l'ENUM pour ajouter 'ai_draft'
echo "--- Modification de reply_source ---\n";
try {
    db()->exec("ALTER TABLE reviews MODIFY COLUMN reply_source ENUM('manual','ai_auto','ai_validated','ai_draft') NULL DEFAULT NULL");
    echo "  OK ! reply_source peut maintenant accepter 'ai_draft'\n";
} catch (\Throwable $e) {
    echo "  ERREUR: " . $e->getMessage() . "\n";
}

// 2. Verification
echo "\n--- Verification ---\n";
$result = db()->query("SHOW COLUMNS FROM reviews LIKE 'reply_source'");
$row = $result->fetch(PDO::FETCH_ASSOC);
echo "  reply_source: " . $row['Type'] . "\n";

// 3. Stats
echo "\n--- Stats actuelles ---\n";
$r = db()->query("SELECT COUNT(*) as total, SUM(CASE WHEN is_replied = 0 AND (reply_text IS NULL OR reply_text = '') AND (deleted_by_google = 0 OR deleted_by_google IS NULL) THEN 1 ELSE 0 END) as needs_reply FROM reviews")->fetch(PDO::FETCH_ASSOC);
echo "  Total avis: " . $r['total'] . "\n";
echo "  Sans reponse (a traiter): " . $r['needs_reply'] . "\n";

echo "\n=== TERMINE — Relance le cron auto-reply maintenant ! ===\n";
