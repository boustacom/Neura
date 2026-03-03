<?php
/**
 * Neura — CRON Backup Base de Donnees (quotidien)
 *
 * Exporte un dump SQL complet de la base, compresse en gzip.
 * Rotation automatique : conserve les 30 derniers jours.
 * Securise par token SHA-256.
 *
 * URL: https://app.boustacom.fr/app/cron/backup-db.php?token=XXXX
 *
 * Configurer un cron quotidien a 3h du matin sur Infomaniak :
 *   wget -qO- "https://app.boustacom.fr/app/cron/backup-db.php?token=XXXX"
 */

require_once __DIR__ . '/../config.php';

// ====== SECURITE ======
$expectedToken = hash('sha256', APP_SECRET . '_cron_backup_db');
$providedToken = $_GET['token'] ?? '';

if (!hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    echo "Token invalide.\n";
    exit;
}

header('Content-Type: text/plain');
http_response_code(200);
set_time_limit(120);
ini_set('display_errors', 1);
error_reporting(E_ALL);
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "\n\n!!! FATAL ERROR: {$err['message']} in {$err['file']}:{$err['line']}\n";
    }
});

// ====== CONFIGURATION ======
$backupDir    = __DIR__ . '/../backups';
$retentionDays = 30;
$timestamp     = date('Y-m-d_His');
$filename      = "neura_backup_{$timestamp}.sql.gz";
$filepath      = "{$backupDir}/{$filename}";

echo "=== Neura DB Backup — " . date('Y-m-d H:i:s') . " ===\n";

// ====== CREER LE DOSSIER BACKUPS ======
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0750, true);
    // Creer un .htaccess pour bloquer tout acces web
    file_put_contents($backupDir . '/.htaccess', "Order deny,allow\nDeny from all\n");
    // Index vide par securite
    file_put_contents($backupDir . '/index.php', '<?php // silence');
    echo "Dossier backups cree.\n";
}

// ====== DUMP SQL via PDO (compatible hebergement mutualise) ======
try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ouvrir le fichier gzip
    $gz = gzopen($filepath, 'wb9');
    if (!$gz) {
        echo "ERREUR : impossible de creer le fichier gzip.\n";
        exit(1);
    }

    // En-tete du dump
    gzwrite($gz, "-- Neura Database Backup\n");
    gzwrite($gz, "-- Date: {$timestamp}\n");
    gzwrite($gz, "-- Base: " . DB_NAME . "\n");
    gzwrite($gz, "-- Serveur: " . DB_HOST . "\n\n");
    gzwrite($gz, "SET NAMES utf8mb4;\n");
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

    // Lister toutes les tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $totalRows = 0;

    foreach ($tables as $table) {
        echo "  Table: {$table}... ";

        // Structure (CREATE TABLE)
        $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
        $createSql = $create['Create Table'] ?? $create['Create View'] ?? '';

        gzwrite($gz, "-- ==============================\n");
        gzwrite($gz, "-- Table: {$table}\n");
        gzwrite($gz, "-- ==============================\n");
        gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n");
        gzwrite($gz, $createSql . ";\n\n");

        // Donnees (INSERT par batch de 500 lignes)
        $count = $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();

        if ($count > 0) {
            $batchSize = 500;
            $offset = 0;

            // Recuperer les noms de colonnes
            $colStmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
            $columns = $colStmt->fetchAll(PDO::FETCH_COLUMN);
            $colList = implode('`, `', $columns);

            while ($offset < $count) {
                $rows = $pdo->query("SELECT * FROM `{$table}` LIMIT {$batchSize} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);

                if (empty($rows)) break;

                $values = [];
                foreach ($rows as $row) {
                    $escaped = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $escaped[] = 'NULL';
                        } else {
                            $escaped[] = $pdo->quote($val);
                        }
                    }
                    $values[] = '(' . implode(',', $escaped) . ')';
                }

                gzwrite($gz, "INSERT INTO `{$table}` (`{$colList}`) VALUES\n" . implode(",\n", $values) . ";\n\n");

                $offset += $batchSize;
            }
        }

        $totalRows += $count;
        echo "{$count} lignes.\n";
    }

    gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
    gzwrite($gz, "-- Fin du backup.\n");
    gzclose($gz);

    $fileSize = filesize($filepath);
    $fileSizeKb = round($fileSize / 1024, 1);

    echo "\nBackup termine !\n";
    echo "  Fichier : {$filename}\n";
    echo "  Tables  : " . count($tables) . "\n";
    echo "  Lignes  : {$totalRows}\n";
    echo "  Taille  : {$fileSizeKb} Ko\n";

} catch (Exception $e) {
    echo "ERREUR BACKUP : " . $e->getMessage() . "\n";
    // Nettoyer le fichier incomplet
    if (isset($gz) && $gz) gzclose($gz);
    if (file_exists($filepath)) unlink($filepath);
    exit(1);
}

// ====== ROTATION : supprimer les backups > 30 jours ======
echo "\n--- Rotation (retention : {$retentionDays} jours) ---\n";
$deleted = 0;
$cutoff = time() - ($retentionDays * 86400);

foreach (glob($backupDir . '/neura_backup_*.sql.gz') as $file) {
    if (filemtime($file) < $cutoff) {
        unlink($file);
        $deleted++;
        echo "  Supprime : " . basename($file) . "\n";
    }
}

// Compter les backups restants
$remaining = count(glob($backupDir . '/neura_backup_*.sql.gz'));
echo "Rotation : {$deleted} ancien(s) supprime(s), {$remaining} backup(s) conserve(s).\n";

echo "\n=== Backup termine avec succes ===\n";
