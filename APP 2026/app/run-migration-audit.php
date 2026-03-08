<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$results = [];
$pdo = db();

// Helper: ajouter une colonne si elle n'existe pas
function addCol($pdo, $col, $def, &$results) {
    $exists = $pdo->query("SHOW COLUMNS FROM audits LIKE '{$col}'")->rowCount() > 0;
    if ($exists) { $results[] = "{$col}: exists"; return; }
    $pdo->exec("ALTER TABLE audits ADD COLUMN {$col} {$def}");
    $results[] = "{$col}: ADDED";
}

try {
    // Toutes les colonnes manquantes
    addCol($pdo, 'address', 'VARCHAR(500) DEFAULT NULL AFTER city', $results);
    addCol($pdo, 'category', 'VARCHAR(255) DEFAULT NULL AFTER address', $results);
    addCol($pdo, 'latitude', 'DECIMAL(10,7) DEFAULT NULL AFTER category', $results);
    addCol($pdo, 'longitude', 'DECIMAL(10,7) DEFAULT NULL AFTER latitude', $results);
    addCol($pdo, 'place_id', 'VARCHAR(255) DEFAULT NULL AFTER longitude', $results);
    addCol($pdo, 'data_cid', 'VARCHAR(50) DEFAULT NULL AFTER place_id', $results);
    addCol($pdo, 'domain', 'VARCHAR(255) DEFAULT NULL AFTER data_cid', $results);
    addCol($pdo, 'rating', 'DECIMAL(2,1) DEFAULT NULL AFTER domain', $results);
    addCol($pdo, 'reviews_count', 'INT DEFAULT 0 AFTER rating', $results);
    addCol($pdo, 'position', 'TINYINT DEFAULT NULL AFTER reviews_count', $results);
    addCol($pdo, 'audit_status', "ENUM('search_only','scanned','audited') DEFAULT 'search_only' AFTER score", $results);
    addCol($pdo, 'grid_scan_id', 'INT DEFAULT NULL AFTER audit_status', $results);
    addCol($pdo, 'grid_visibility', 'DECIMAL(5,2) DEFAULT NULL AFTER grid_scan_id', $results);
    addCol($pdo, 'grid_avg_position', 'DECIMAL(5,2) DEFAULT NULL AFTER grid_visibility', $results);
    addCol($pdo, 'grid_top3', 'INT DEFAULT 0 AFTER grid_avg_position', $results);
    addCol($pdo, 'grid_top10', 'INT DEFAULT 0 AFTER grid_top3', $results);
    addCol($pdo, 'grid_top20', 'INT DEFAULT 0 AFTER grid_top10', $results);

    // Nouvelles colonnes enrichissement My Business Info
    addCol($pdo, 'total_photos', 'INT DEFAULT NULL AFTER grid_top20', $results);
    addCol($pdo, 'description', 'TEXT DEFAULT NULL AFTER total_photos', $results);
    addCol($pdo, 'social_facebook', 'VARCHAR(500) DEFAULT NULL AFTER description', $results);
    addCol($pdo, 'social_instagram', 'VARCHAR(500) DEFAULT NULL AFTER social_facebook', $results);
    addCol($pdo, 'unanswered_reviews', 'INT DEFAULT NULL AFTER social_instagram', $results);

    // Tables pour les scans grille prospect
    $pdo->exec("CREATE TABLE IF NOT EXISTS prospect_grid_scans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        audit_id INT NOT NULL,
        user_id INT NOT NULL,
        keyword VARCHAR(500) NOT NULL,
        center_lat DECIMAL(10,7) NOT NULL,
        center_lng DECIMAL(10,7) NOT NULL,
        total_points INT DEFAULT 49,
        radius_km DECIMAL(5,2) DEFAULT 15.00,
        avg_position DECIMAL(5,2) DEFAULT NULL,
        visibility_score DECIMAL(5,2) DEFAULT NULL,
        top3_count INT DEFAULT 0,
        top10_count INT DEFAULT 0,
        top20_count INT DEFAULT 0,
        out_count INT DEFAULT 0,
        status ENUM('running','completed','failed') DEFAULT 'running',
        error TEXT DEFAULT NULL,
        started_at DATETIME DEFAULT NULL,
        completed_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit (audit_id),
        INDEX idx_user (user_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = "prospect_grid_scans: OK";

    $pdo->exec("CREATE TABLE IF NOT EXISTS prospect_grid_points (
        id INT AUTO_INCREMENT PRIMARY KEY,
        scan_id INT NOT NULL,
        row_index TINYINT NOT NULL,
        col_index TINYINT NOT NULL,
        latitude DECIMAL(10,7) NOT NULL,
        longitude DECIMAL(10,7) NOT NULL,
        position TINYINT DEFAULT NULL,
        business_name_found VARCHAR(255) DEFAULT NULL,
        INDEX idx_scan (scan_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = "prospect_grid_points: OK";

    // Table prospect_searches (pour le log des recherches)
    $pdo->exec("CREATE TABLE IF NOT EXISTS prospect_searches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        keyword VARCHAR(255) NOT NULL,
        city VARCHAR(255) NOT NULL,
        results_count INT DEFAULT 0,
        credits_used INT DEFAULT 1,
        searched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = "prospect_searches: OK";

    // Verification finale
    $cols = $pdo->query('SHOW COLUMNS FROM audits')->fetchAll(PDO::FETCH_COLUMN, 0);
    echo json_encode(['success' => true, 'results' => $results, 'columns' => $cols, 'total_cols' => count($cols)], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage(), 'results' => $results]);
}
