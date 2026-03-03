<?php
/**
 * BOUS'TACOM — Test Grid Competitor Aggregation Bug
 * Verifies that deduplication per grid_point_id works correctly
 * and no competitor has appearances > total_points (37).
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$results = [];

// ====== TEST CASES ======
$testCases = [
    [
        'location_id' => 6,
        'target_cid' => '17462560768716230565',
        'scan_id' => 50,
        'label' => 'VPN Autos Brive',
    ],
    [
        'location_id' => 5,
        'target_cid' => '9485166981789509432',
        'scan_id' => null, // will use latest
        'label' => 'BOUSTACOM',
    ],
];

foreach ($testCases as $test) {
    $locId = $test['location_id'];
    $targetCid = $test['target_cid'];
    $testResult = [
        'label' => $test['label'],
        'location_id' => $locId,
        'target_cid' => $targetCid,
    ];

    // Load location
    $stmt = db()->prepare('SELECT id, name, place_id, google_cid, latitude, longitude FROM gbp_locations WHERE id = ?');
    $stmt->execute([$locId]);
    $location = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$location) {
        $testResult['error'] = 'Location not found';
        $results[] = $testResult;
        continue;
    }
    $testResult['location'] = $location;

    // Determine scan_id
    $scanId = $test['scan_id'];
    if (!$scanId) {
        $stmtScan = db()->prepare('
            SELECT gs.id, gs.total_points, gs.scanned_at, k.keyword
            FROM grid_scans gs
            JOIN keywords k ON gs.keyword_id = k.id
            WHERE k.location_id = ?
            ORDER BY gs.scanned_at DESC
            LIMIT 1
        ');
        $stmtScan->execute([$locId]);
        $scanRow = $stmtScan->fetch(PDO::FETCH_ASSOC);
        if (!$scanRow) {
            $testResult['error'] = 'No scan found';
            $results[] = $testResult;
            continue;
        }
        $scanId = (int)$scanRow['id'];
        $testResult['scan_info'] = $scanRow;
    } else {
        $stmtScan = db()->prepare('
            SELECT gs.id, gs.total_points, gs.scanned_at, k.keyword
            FROM grid_scans gs
            JOIN keywords k ON gs.keyword_id = k.id
            WHERE gs.id = ?
        ');
        $stmtScan->execute([$scanId]);
        $scanRow = $stmtScan->fetch(PDO::FETCH_ASSOC);
        $testResult['scan_info'] = $scanRow;
    }

    $totalGridPoints = (int)($scanRow['total_points'] ?? 37);
    $testResult['total_grid_points'] = $totalGridPoints;

    // Step a: Load ALL grid_competitors for this scan
    $stmtComp = db()->prepare('
        SELECT id, grid_point_id, position, title, address, category,
               rating, reviews_count, place_id, data_cid, is_target
        FROM grid_competitors
        WHERE grid_scan_id = ?
    ');
    $stmtComp->execute([$scanId]);
    $allComps = $stmtComp->fetchAll(PDO::FETCH_ASSOC);

    $testResult['raw_competitor_rows'] = count($allComps);

    // Step b: Group by identity key, deduplicate per grid_point_id (keep best position)
    $grouped = [];
    foreach ($allComps as $c) {
        $key = !empty($c['data_cid']) ? $c['data_cid']
             : (!empty($c['place_id']) ? $c['place_id'] : $c['title']);

        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'title' => $c['title'],
                'address' => $c['address'],
                'category' => $c['category'],
                'rating' => $c['rating'],
                'reviews_count' => $c['reviews_count'],
                'place_id' => $c['place_id'],
                'data_cid' => $c['data_cid'],
                'points' => [], // grid_point_id => best_position
            ];
        }
        $ptId = $c['grid_point_id'];
        $pos = (int)$c['position'];
        // Keep best (lowest) position per grid point
        if (!isset($grouped[$key]['points'][$ptId]) || $pos < $grouped[$key]['points'][$ptId]) {
            $grouped[$key]['points'][$ptId] = $pos;
        }
    }

    $testResult['unique_competitors'] = count($grouped);

    // Step c+d: Calculate stats for each competitor
    $competitorStats = [];
    $bugs = [];
    $targetInfo = null;

    foreach ($grouped as $key => $comp) {
        $appearances = count($comp['points']);
        $sumPosition = array_sum($comp['points']);
        $missingPoints = max(0, $totalGridPoints - $appearances);
        $localoAvg = $totalGridPoints > 0
            ? round(($sumPosition + 21 * $missingPoints) / $totalGridPoints, 1)
            : 21;
        $bestPosition = min($comp['points']);

        // Check for bugs
        if ($appearances > $totalGridPoints) {
            $bugs[] = [
                'type' => 'appearances_exceed_total',
                'competitor' => $comp['title'],
                'data_cid' => $comp['data_cid'],
                'appearances' => $appearances,
                'total_points' => $totalGridPoints,
            ];
        }
        if ($localoAvg < 0) {
            $bugs[] = [
                'type' => 'negative_avg',
                'competitor' => $comp['title'],
                'data_cid' => $comp['data_cid'],
                'avg_position' => $localoAvg,
            ];
        }

        $entry = [
            'title' => $comp['title'],
            'data_cid' => $comp['data_cid'],
            'place_id' => $comp['place_id'],
            'appearances' => $appearances,
            'sum_position' => $sumPosition,
            'missing_points' => $missingPoints,
            'best_position' => $bestPosition,
            'avg_position_localo' => $localoAvg,
            'is_target' => ($comp['data_cid'] === $targetCid) ? true : false,
        ];

        $competitorStats[] = $entry;

        // Track target
        if ($comp['data_cid'] === $targetCid) {
            $targetInfo = $entry;
        }
    }

    // Sort by avg_position_localo
    usort($competitorStats, function($a, $b) {
        return $a['avg_position_localo'] <=> $b['avg_position_localo'];
    });

    // Add rank
    foreach ($competitorStats as $idx => &$cs) {
        $cs['rank'] = $idx + 1;
        if ($cs['is_target']) {
            $targetInfo['rank'] = $idx + 1;
        }
    }
    unset($cs);

    // Step e: Top 10
    $testResult['top_10_competitors'] = array_slice($competitorStats, 0, 10);

    // Step f: Target info
    $testResult['target'] = $targetInfo ?: ['error' => 'Target CID not found in competitors'];

    // Step g: Bug check
    $testResult['bugs_found'] = $bugs;
    $testResult['bug_check'] = [
        'any_appearances_over_' . $totalGridPoints => count(array_filter($competitorStats, fn($c) => $c['appearances'] > $totalGridPoints)) > 0,
        'any_negative_avg' => count(array_filter($competitorStats, fn($c) => $c['avg_position_localo'] < 0)) > 0,
        'max_appearances' => max(array_column($competitorStats, 'appearances')),
        'status' => empty($bugs) ? 'PASS' : 'FAIL',
    ];

    $results[] = $testResult;
}

echo json_encode([
    'test' => 'grid_competitor_aggregation',
    'timestamp' => date('Y-m-d H:i:s'),
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
