<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

$result = [
    'timestamp' => date('Y-m-d H:i:s'),
    'status' => 'ok',
    'errors' => []
];

try {
    require __DIR__ . '/../config.php';

    // Connect to database
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // 1. List all tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $result['all_tables'] = $tables;

    // 2. Schema of key tables
    $key_tables = ['grid_scans', 'grid_points', 'grid_competitors', 'keyword_positions', 'gbp_locations', 'keywords'];
    $result['schemas'] = [];
    foreach ($key_tables as $tbl) {
        try {
            $row = $pdo->query("SHOW CREATE TABLE `$tbl`")->fetch(PDO::FETCH_ASSOC);
            $result['schemas'][$tbl] = $row['Create Table'] ?? $row[array_keys($row)[1]] ?? null;
        } catch (Exception $e) {
            $result['schemas'][$tbl] = 'ERROR: ' . $e->getMessage();
        }
    }

    // 3. Row counts
    $result['row_counts'] = [];
    foreach ($key_tables as $tbl) {
        try {
            $count = $pdo->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
            $result['row_counts'][$tbl] = (int)$count;
        } catch (Exception $e) {
            $result['row_counts'][$tbl] = 'ERROR: ' . $e->getMessage();
        }
    }

    // 4. Check google_cid in gbp_locations
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM gbp_locations LIKE 'google_cid'")->fetchAll();
        $result['gbp_locations_google_cid'] = [
            'column_exists' => count($cols) > 0,
            'column_info' => $cols
        ];
        if (count($cols) > 0) {
            $non_null = $pdo->query("SELECT COUNT(*) FROM gbp_locations WHERE google_cid IS NOT NULL AND google_cid != ''")->fetchColumn();
            $result['gbp_locations_google_cid']['non_null_count'] = (int)$non_null;
        }
    } catch (Exception $e) {
        $result['gbp_locations_google_cid'] = 'ERROR: ' . $e->getMessage();
    }

    // 5. Check data_cid in grid_competitors
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM grid_competitors LIKE 'data_cid'")->fetchAll();
        $result['grid_competitors_data_cid'] = [
            'column_exists' => count($cols) > 0,
            'column_info' => $cols
        ];
        if (count($cols) > 0) {
            $non_null = $pdo->query("SELECT COUNT(*) FROM grid_competitors WHERE data_cid IS NOT NULL AND data_cid != ''")->fetchColumn();
            $result['grid_competitors_data_cid']['non_null_count'] = (int)$non_null;
        }
    } catch (Exception $e) {
        $result['grid_competitors_data_cid'] = 'ERROR: ' . $e->getMessage();
    }

    // 6. Sample data from gbp_locations
    try {
        $sample = $pdo->query("SELECT id, name, google_cid, place_id FROM gbp_locations LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        $result['gbp_locations_sample'] = $sample;
    } catch (Exception $e) {
        $result['gbp_locations_sample'] = 'ERROR: ' . $e->getMessage();
    }

    // 7. Scan files in tmp directory
    $scan_files = glob(__DIR__ . '/../tmp/scan_*.json');
    $token_files = glob(__DIR__ . '/../tmp/run_token_*.txt');
    $result['tmp_files'] = [
        'scan_json_files' => $scan_files ? array_map('basename', $scan_files) : [],
        'run_token_files' => $token_files ? array_map('basename', $token_files) : []
    ];

    // 8. Last 5 grid_scans with keyword
    try {
        $scans = $pdo->query("
            SELECT gs.*, k.keyword 
            FROM grid_scans gs 
            LEFT JOIN keywords k ON gs.keyword_id = k.id 
            ORDER BY gs.id DESC 
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
        $result['last_5_scans'] = $scans;
    } catch (Exception $e) {
        $result['last_5_scans'] = 'ERROR: ' . $e->getMessage();
    }

} catch (Exception $e) {
    $result['status'] = 'error';
    $result['errors'][] = $e->getMessage();
    $result['trace'] = $e->getTraceAsString();
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
