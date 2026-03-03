<?php
/**
 * _diag.php — Comprehensive diagnostic for ALL locations
 * No session required. Outputs JSON with sections: locations, tmp_files, scan_details
 */
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

$result = [
    'timestamp' => date('Y-m-d H:i:s T'),
    'php_version' => PHP_VERSION,
    'status' => 'ok',
    'errors' => [],
    'locations' => [],
    'tmp_files' => [],
    'scan_details' => [],
    'sql_bug_check' => null,
];

try {
    require __DIR__ . '/../config.php';

    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // ============================================================
    // SECTION 1: ALL LOCATIONS with keyword counts, grid_scan counts, CID check
    // ============================================================
    $locations = $pdo->query('SELECT * FROM gbp_locations ORDER BY id')->fetchAll();

    foreach ($locations as $loc) {
        $locId = $loc['id'];

        // Count keywords
        $kwCount = (int)$pdo->prepare('SELECT COUNT(*) FROM keywords WHERE location_id = ?');
        $stK = $pdo->prepare('SELECT COUNT(*) FROM keywords WHERE location_id = ?');
        $stK->execute([$locId]);
        $kwCount = (int)$stK->fetchColumn();

        // Count grid_scans
        $stS = $pdo->prepare('SELECT COUNT(*) FROM grid_scans gs JOIN keywords k ON gs.keyword_id = k.id WHERE k.location_id = ?');
        $stS->execute([$locId]);
        $scanCount = (int)$stS->fetchColumn();

        // List keywords with details
        $stKW = $pdo->prepare('SELECT id, keyword, is_active FROM keywords WHERE location_id = ? ORDER BY id');
        $stKW->execute([$locId]);
        $keywords = $stKW->fetchAll();

        $result['locations'][] = [
            'id' => (int)$loc['id'],
            'name' => $loc['name'] ?? null,
            'google_cid' => $loc['google_cid'] ?? null,
            'google_cid_set' => !empty($loc['google_cid']),
            'place_id' => $loc['place_id'] ?? null,
            'latitude' => $loc['latitude'] ?? null,
            'longitude' => $loc['longitude'] ?? null,
            'keyword_count' => $kwCount,
            'grid_scan_count' => $scanCount,
            'keywords' => $keywords,
        ];
    }

    // ============================================================
    // SECTION 2: TMP FILES — scan_*.json and run_token_*.txt with content
    // ============================================================
    $tmpDir = __DIR__ . '/../tmp/';
    $scanFiles = glob($tmpDir . 'scan_*.json') ?: [];
    $tokenFiles = glob($tmpDir . 'run_token_*.txt') ?: [];
    $allTmpFiles = array_merge($scanFiles, $tokenFiles);

    // Also grab any other files in tmp for completeness
    $allFiles = glob($tmpDir . '*') ?: [];
    $otherFiles = array_diff($allFiles, $allTmpFiles);

    foreach ($allTmpFiles as $f) {
        $content = @file_get_contents($f);
        $decoded = @json_decode($content, true);
        $result['tmp_files'][] = [
            'filename' => basename($f),
            'full_path' => $f,
            'size_bytes' => filesize($f),
            'modified' => date('Y-m-d H:i:s', filemtime($f)),
            'content_raw' => mb_strlen($content) > 10000 ? mb_substr($content, 0, 10000) . '...[TRUNCATED]' : $content,
            'content_json' => $decoded,
        ];
    }

    // Other files in tmp
    foreach ($otherFiles as $f) {
        $result['tmp_files'][] = [
            'filename' => basename($f),
            'full_path' => $f,
            'size_bytes' => @filesize($f),
            'modified' => @date('Y-m-d H:i:s', @filemtime($f)),
            'type' => is_dir($f) ? 'directory' : 'file',
            'content_raw' => is_file($f) ? (mb_strlen(@file_get_contents($f)) > 5000 ? mb_substr(@file_get_contents($f), 0, 5000) . '...[TRUNCATED]' : @file_get_contents($f)) : null,
        ];
    }

    if (empty($result['tmp_files'])) {
        $result['tmp_files_note'] = 'No files found in ' . realpath($tmpDir);
    }

    // ============================================================
    // SECTION 3: SCAN DETAILS for locations WITH grid_scans
    // ============================================================
    foreach ($result['locations'] as $locData) {
        if ($locData['grid_scan_count'] === 0) continue;

        $locId = $locData['id'];
        $locScanDetails = [
            'location_id' => $locId,
            'location_name' => $locData['name'],
            'scans' => [],
        ];

        // Get ALL scans for this location (via keywords), ordered by most recent
        $stScans = $pdo->prepare('
            SELECT gs.*, k.keyword, k.id as kw_id
            FROM grid_scans gs
            JOIN keywords k ON gs.keyword_id = k.id
            WHERE k.location_id = ?
            ORDER BY gs.id DESC
        ');
        $stScans->execute([$locId]);
        $scans = $stScans->fetchAll();

        foreach ($scans as $scan) {
            $scanId = $scan['id'];

            // Count grid_points
            $stPts = $pdo->prepare('SELECT COUNT(*) FROM grid_points WHERE grid_scan_id = ?');
            $stPts->execute([$scanId]);
            $pointCount = (int)$stPts->fetchColumn();

            // Count grid_competitors
            $stComps = $pdo->prepare('SELECT COUNT(*) FROM grid_competitors WHERE grid_scan_id = ?');
            $stComps->execute([$scanId]);
            $compCount = (int)$stComps->fetchColumn();

            // Sample 3 grid_points with positions
            $stSample = $pdo->prepare('SELECT id, row_index, col_index, latitude, longitude, position, business_name_found FROM grid_points WHERE grid_scan_id = ? ORDER BY id LIMIT 3');
            $stSample->execute([$scanId]);
            $samplePoints = $stSample->fetchAll();

            // Check for null positions vs valid positions
            $stNulls = $pdo->prepare('SELECT COUNT(*) FROM grid_points WHERE grid_scan_id = ? AND position IS NULL');
            $stNulls->execute([$scanId]);
            $nullPositions = (int)$stNulls->fetchColumn();

            $stPos21 = $pdo->prepare('SELECT COUNT(*) FROM grid_points WHERE grid_scan_id = ? AND position = 21');
            $stPos21->execute([$scanId]);
            $pos21Count = (int)$stPos21->fetchColumn();

            $stPosFound = $pdo->prepare('SELECT COUNT(*) FROM grid_points WHERE grid_scan_id = ? AND position IS NOT NULL AND position < 21');
            $stPosFound->execute([$scanId]);
            $posFoundCount = (int)$stPosFound->fetchColumn();

            // Count distinct competitors by data_cid
            $stDistinct = $pdo->prepare('SELECT COUNT(DISTINCT data_cid) FROM grid_competitors WHERE grid_scan_id = ? AND data_cid IS NOT NULL AND data_cid != ""');
            $stDistinct->execute([$scanId]);
            $distinctCids = (int)$stDistinct->fetchColumn();

            // Check if target competitor is found (by CID match)
            $targetFound = false;
            if (!empty($locData['google_cid'])) {
                $stTarget = $pdo->prepare('SELECT COUNT(*) FROM grid_competitors WHERE grid_scan_id = ? AND data_cid = ?');
                $stTarget->execute([$scanId, $locData['google_cid']]);
                $targetFound = (int)$stTarget->fetchColumn() > 0;
            }

            $locScanDetails['scans'][] = [
                'scan_id' => (int)$scanId,
                'keyword' => $scan['keyword'],
                'keyword_id' => (int)$scan['kw_id'],
                'scanned_at' => $scan['scanned_at'] ?? null,
                'grid_type' => $scan['grid_type'] ?? null,
                'num_rings' => $scan['num_rings'] ?? null,
                'radius_km' => $scan['radius_km'] ?? null,
                'total_points_declared' => $scan['total_points'] ?? null,
                'avg_position' => $scan['avg_position'] ?? null,
                'visibility_score' => $scan['visibility_score'] ?? null,
                'top3_count' => $scan['top3_count'] ?? null,
                'top10_count' => $scan['top10_count'] ?? null,
                'top20_count' => $scan['top20_count'] ?? null,
                'out_count' => $scan['out_count'] ?? null,
                'grid_points_exist' => $pointCount > 0,
                'grid_points_count' => $pointCount,
                'grid_competitors_exist' => $compCount > 0,
                'grid_competitors_count' => $compCount,
                'distinct_competitor_cids' => $distinctCids,
                'positions_found_lt21' => $posFoundCount,
                'positions_21_absent' => $pos21Count,
                'positions_null_error' => $nullPositions,
                'target_found_by_cid' => $targetFound,
                'sample_3_points' => $samplePoints,
            ];
        }

        $result['scan_details'][] = $locScanDetails;
    }

    // ============================================================
    // SECTION 4: SQL BUG CHECK — the ANY_VALUE aggregation subquery in grid.php case 'scan'
    // ============================================================
    // The scan action has a SQL query that uses ANY_VALUE() in a subquery with GROUP BY.
    // Let's test if this query runs without error on a real scan_id.
    $bugCheck = [
        'description' => 'Testing the ANY_VALUE aggregation SQL from grid.php case scan (competitor aggregation)',
        'sql_used' => null,
        'test_scan_id' => null,
        'result' => null,
        'error' => null,
        'row_count' => null,
        'mysql_version' => null,
        'sql_mode' => null,
    ];

    // Get MySQL version and sql_mode
    $bugCheck['mysql_version'] = $pdo->query('SELECT VERSION()')->fetchColumn();
    $bugCheck['sql_mode'] = $pdo->query('SELECT @@sql_mode')->fetchColumn();

    // Find a scan with competitors to test on
    $testScan = $pdo->query('
        SELECT gs.id, gs.total_points 
        FROM grid_scans gs 
        WHERE EXISTS (SELECT 1 FROM grid_competitors gc WHERE gc.grid_scan_id = gs.id)
        ORDER BY gs.id DESC LIMIT 1
    ')->fetch();

    if ($testScan) {
        $testScanId = $testScan['id'];
        $totalPoints = (int)($testScan['total_points'] ?? 0);
        $bugCheck['test_scan_id'] = (int)$testScanId;

        $sql = "
            SELECT
                gc_key,
                ANY_VALUE(title) as title,
                ANY_VALUE(address) as address,
                ANY_VALUE(category) as category,
                ANY_VALUE(rating) as rating,
                ANY_VALUE(reviews_count) as reviews_count,
                ANY_VALUE(place_id) as place_id,
                ANY_VALUE(data_cid) as data_cid,
                ANY_VALUE(website) as website,
                SUM(best_pos_at_point) as sum_position,
                COUNT(*) as appearances,
                MIN(best_pos_at_point) as best_position
            FROM (
                SELECT
                    COALESCE(NULLIF(data_cid, ''), NULLIF(place_id, ''), title) as gc_key,
                    grid_point_id,
                    MIN(position) as best_pos_at_point,
                    title, address, category, rating, reviews_count, place_id, data_cid, website
                FROM grid_competitors
                WHERE grid_scan_id = ?
                GROUP BY gc_key, grid_point_id
            ) as deduped
            GROUP BY gc_key
            HAVING appearances > 0
        ";
        $bugCheck['sql_used'] = $sql;

        try {
            $stBug = $pdo->prepare($sql);
            $stBug->execute([$testScanId]);
            $rows = $stBug->fetchAll();
            $bugCheck['result'] = 'SUCCESS — query ran without error';
            $bugCheck['row_count'] = count($rows);
            // Show first 3 rows as sample
            $bugCheck['sample_rows'] = array_slice($rows, 0, 3);
        } catch (Exception $e) {
            $bugCheck['result'] = 'FAILED — SQL error';
            $bugCheck['error'] = $e->getMessage();
        }

        // Also test the INNER subquery alone to check if it's the source of issues
        $innerSql = "
            SELECT
                COALESCE(NULLIF(data_cid, ''), NULLIF(place_id, ''), title) as gc_key,
                grid_point_id,
                MIN(position) as best_pos_at_point,
                title, address, category, rating, reviews_count, place_id, data_cid, website
            FROM grid_competitors
            WHERE grid_scan_id = ?
            GROUP BY gc_key, grid_point_id
        ";
        try {
            $stInner = $pdo->prepare($innerSql);
            $stInner->execute([$testScanId]);
            $innerRows = $stInner->fetchAll();
            $bugCheck['inner_subquery_result'] = 'SUCCESS';
            $bugCheck['inner_subquery_rows'] = count($innerRows);
        } catch (Exception $e) {
            $bugCheck['inner_subquery_result'] = 'FAILED';
            $bugCheck['inner_subquery_error'] = $e->getMessage();
            // Note: the inner subquery selects non-aggregated columns (title, address, etc.)
            // with GROUP BY gc_key, grid_point_id — in ONLY_FULL_GROUP_BY mode, these columns
            // are NOT in GROUP BY and NOT aggregated, so it would FAIL.
            $bugCheck['inner_subquery_diagnosis'] = 'The inner subquery selects title, address, category, rating, reviews_count, place_id, data_cid, website without ANY_VALUE() wrapping, and they are not in the GROUP BY clause. With ONLY_FULL_GROUP_BY enabled, this WILL fail.';
        }

        // Test a fixed version of the inner subquery
        $fixedInnerSql = "
            SELECT
                COALESCE(NULLIF(data_cid, ''), NULLIF(place_id, ''), title) as gc_key,
                grid_point_id,
                MIN(position) as best_pos_at_point,
                ANY_VALUE(title) as title,
                ANY_VALUE(address) as address,
                ANY_VALUE(category) as category,
                ANY_VALUE(rating) as rating,
                ANY_VALUE(reviews_count) as reviews_count,
                ANY_VALUE(place_id) as place_id,
                ANY_VALUE(data_cid) as data_cid,
                ANY_VALUE(website) as website
            FROM grid_competitors
            WHERE grid_scan_id = ?
            GROUP BY gc_key, grid_point_id
        ";
        try {
            $stFixed = $pdo->prepare($fixedInnerSql);
            $stFixed->execute([$testScanId]);
            $fixedRows = $stFixed->fetchAll();
            $bugCheck['fixed_inner_subquery_result'] = 'SUCCESS';
            $bugCheck['fixed_inner_subquery_rows'] = count($fixedRows);
        } catch (Exception $e) {
            $bugCheck['fixed_inner_subquery_result'] = 'FAILED';
            $bugCheck['fixed_inner_subquery_error'] = $e->getMessage();
        }

    } else {
        $bugCheck['result'] = 'SKIP — no scan with competitors found to test';
    }

    $result['sql_bug_check'] = $bugCheck;

    // ============================================================
    // SECTION 5: Table schemas for reference
    // ============================================================
    $schemasTables = ['gbp_locations', 'keywords', 'grid_scans', 'grid_points', 'grid_competitors'];
    $result['table_schemas'] = [];
    foreach ($schemasTables as $tbl) {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll();
            $result['table_schemas'][$tbl] = $cols;
        } catch (Exception $e) {
            $result['table_schemas'][$tbl] = 'ERROR: ' . $e->getMessage();
        }
    }

    // Row counts
    $result['row_counts'] = [];
    foreach ($schemasTables as $tbl) {
        try {
            $result['row_counts'][$tbl] = (int)$pdo->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
        } catch (Exception $e) {
            $result['row_counts'][$tbl] = 'ERROR: ' . $e->getMessage();
        }
    }

} catch (Exception $e) {
    $result['status'] = 'error';
    $result['errors'][] = $e->getMessage();
    $result['trace'] = $e->getTraceAsString();
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
