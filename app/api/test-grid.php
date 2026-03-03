<?php
/**
 * BOUS'TACOM — Test Grid Data Flow
 * Verifies the full grid recalcul logic for location_id=5 and location_id=6
 * No session required — diagnostic script
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$results = [];

// ====== TEST LOCATIONS 6 AND 5 ======
$locationIds = [6, 5];

foreach ($locationIds as $locId) {
    $locResult = ['location_id' => $locId];

    // a. Load the location and show its google_cid
    $stmt = db()->prepare('SELECT id, name, place_id, google_cid, latitude, longitude FROM gbp_locations WHERE id = ?');
    $stmt->execute([$locId]);
    $location = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$location) {
        $locResult['error'] = 'Location not found';
        $results[] = $locResult;
        continue;
    }

    $locResult['location'] = $location;
    $googleCid = $location['google_cid'] ?? '';
    $placeId = $location['place_id'] ?? '';

    // b. Find keywords for this location and get the latest grid_scan
    $stmtKw = db()->prepare('
        SELECT k.id as keyword_id, k.keyword, gs.id as scan_id, gs.scanned_at, gs.total_points,
               gs.avg_position, gs.visibility_score, gs.top3_count, gs.top10_count, gs.top20_count, gs.out_count,
               gs.grid_type, gs.num_rings, gs.radius_km
        FROM keywords k
        JOIN grid_scans gs ON gs.keyword_id = k.id
        WHERE k.location_id = ?
        ORDER BY gs.scanned_at DESC
        LIMIT 1
    ');
    $stmtKw->execute([$locId]);
    $latestScan = $stmtKw->fetch(PDO::FETCH_ASSOC);

    if (!$latestScan) {
        $locResult['error'] = 'No grid scan found for this location';
        $results[] = $locResult;
        continue;
    }

    $locResult['latest_scan'] = $latestScan;
    $scanId = $latestScan['scan_id'];
    $totalGridPoints = (int)($latestScan['total_points'] ?? 0);

    // c. Load grid_points for that scan
    $stmtPts = db()->prepare('SELECT id, row_index, col_index, latitude, longitude, position, business_name_found FROM grid_points WHERE grid_scan_id = ? ORDER BY row_index, col_index');
    $stmtPts->execute([$scanId]);
    $points = $stmtPts->fetchAll(PDO::FETCH_ASSOC);

    $locResult['grid_points_count'] = count($points);
    if ($totalGridPoints === 0) $totalGridPoints = count($points);

    // d. For each point, query grid_competitors to find CID match and show position
    $pointDetails = [];
    foreach ($points as &$pt) {
        $ptId = $pt['id'];
        $detail = [
            'point_id' => $ptId,
            'row' => $pt['row_index'],
            'col' => $pt['col_index'],
            'original_position' => $pt['position'],
            'original_name' => $pt['business_name_found'],
        ];

        $found = false;
        $matchMethod = null;

        // 1. Match by CID
        if ($googleCid) {
            $stmtC = db()->prepare('SELECT position, title, data_cid FROM grid_competitors WHERE grid_point_id = ? AND data_cid = ? LIMIT 1');
            $stmtC->execute([$ptId, $googleCid]);
            $match = $stmtC->fetch(PDO::FETCH_ASSOC);
            if ($match) {
                $detail['recalc_position'] = $match['position'];
                $detail['recalc_name'] = $match['title'];
                $detail['match_method'] = 'CID';
                $found = true;
            }
        }

        // 2. Fallback by place_id
        if (!$found && $placeId) {
            $stmtC2 = db()->prepare('SELECT position, title, data_cid FROM grid_competitors WHERE grid_point_id = ? AND place_id = ? LIMIT 1');
            $stmtC2->execute([$ptId, $placeId]);
            $match2 = $stmtC2->fetch(PDO::FETCH_ASSOC);
            if ($match2) {
                $detail['recalc_position'] = $match2['position'];
                $detail['recalc_name'] = $match2['title'];
                $detail['match_method'] = 'place_id';
                $found = true;
            }
        }

        // 3. Fallback by is_target
        if (!$found) {
            $stmtC3 = db()->prepare('SELECT position, title FROM grid_competitors WHERE grid_point_id = ? AND is_target = 1 LIMIT 1');
            $stmtC3->execute([$ptId]);
            $match3 = $stmtC3->fetch(PDO::FETCH_ASSOC);
            if ($match3) {
                $detail['recalc_position'] = $match3['position'];
                $detail['recalc_name'] = $match3['title'];
                $detail['match_method'] = 'is_target';
                $found = true;
            }
        }

        if (!$found) {
            // Check if competitors exist for this point
            $stmtCount = db()->prepare('SELECT COUNT(*) FROM grid_competitors WHERE grid_point_id = ?');
            $stmtCount->execute([$ptId]);
            $hasComp = (int)$stmtCount->fetchColumn();
            $detail['recalc_position'] = $hasComp > 0 ? 21 : null;
            $detail['match_method'] = $hasComp > 0 ? 'not_in_top20' : 'no_data';
        }

        $pointDetails[] = $detail;
    }
    unset($pt);

    $locResult['point_details'] = $pointDetails;

    // Recalculate stats from recalculated positions
    $recalcTop3 = 0; $recalcTop10 = 0; $recalcTop20 = 0; $recalcOut = 0;
    $recalcPosSum = 0; $recalcPosCount = 0;
    foreach ($pointDetails as $d) {
        $pos = $d['recalc_position'];
        if ($pos === null) continue;
        $pos = (int)$pos;
        $effPos = $pos <= 20 ? $pos : 21;
        $recalcPosSum += $effPos;
        $recalcPosCount++;
        if ($effPos <= 3) $recalcTop3++;
        elseif ($effPos <= 10) $recalcTop10++;
        elseif ($effPos <= 20) $recalcTop20++;
        else $recalcOut++;
    }
    $recalcAvg = $recalcPosCount > 0 ? round($recalcPosSum / $recalcPosCount, 1) : null;
    $recalcVis = 0;
    if ($totalGridPoints > 0) {
        $score = 0;
        foreach ($pointDetails as $d) {
            $pos = $d['recalc_position'];
            if ($pos !== null && (int)$pos <= 20) {
                $score += (21 - (int)$pos) / 20 * 100;
            }
        }
        $recalcVis = round($score / $totalGridPoints);
    }

    $locResult['recalculated_stats'] = [
        'avg_position' => $recalcAvg,
        'visibility_score' => $recalcVis,
        'top3' => $recalcTop3,
        'top10' => $recalcTop10,
        'top20' => $recalcTop20,
        'out' => $recalcOut,
        'points_with_data' => $recalcPosCount,
    ];

    $locResult['stored_stats'] = [
        'avg_position' => $latestScan['avg_position'],
        'visibility_score' => $latestScan['visibility_score'],
        'top3' => $latestScan['top3_count'],
        'top10' => $latestScan['top10_count'],
        'top20' => $latestScan['top20_count'],
        'out' => $latestScan['out_count'],
    ];

    // f. Competitor aggregation — top 5 by avg position with is_target flag
    $stmtComp = db()->prepare('
        SELECT title, address, category, rating, reviews_count, place_id, data_cid,
               SUM(position) as sum_position,
               COUNT(*) as appearances,
               MIN(position) as best_position
        FROM grid_competitors
        WHERE grid_scan_id = ?
        GROUP BY COALESCE(NULLIF(data_cid, \'\'), NULLIF(place_id, \'\'), title)
        HAVING appearances > 0
        ORDER BY (SUM(position) + 21 * (? - COUNT(*))) / ? ASC
        LIMIT 10
    ');
    $stmtComp->execute([$scanId, $totalGridPoints, $totalGridPoints]);
    $rawComp = $stmtComp->fetchAll(PDO::FETCH_ASSOC);

    $competitorAggregation = [];
    foreach ($rawComp as $idx => $comp) {
        $appearances = (int)$comp['appearances'];
        $sumPos = (float)$comp['sum_position'];
        $missingPoints = $totalGridPoints - $appearances;
        $localoAvg = round(($sumPos + 21 * $missingPoints) / $totalGridPoints, 1);

        $compCid = $comp['data_cid'] ?? '';
        $compPlaceId = $comp['place_id'] ?? '';
        $isTarget = false;
        if ($googleCid && $compCid && $compCid === $googleCid) {
            $isTarget = true;
        } elseif ($placeId && $compPlaceId && $compPlaceId === $placeId) {
            $isTarget = true;
        }

        $competitorAggregation[] = [
            'rank' => $idx + 1,
            'title' => $comp['title'],
            'data_cid' => $comp['data_cid'],
            'place_id' => $comp['place_id'],
            'avg_position' => $localoAvg,
            'appearances' => $appearances,
            'total_points' => $totalGridPoints,
            'best_position' => $comp['best_position'],
            'is_target' => $isTarget ? 1 : 0,
            'rating' => $comp['rating'],
            'reviews_count' => $comp['reviews_count'],
        ];
    }

    $locResult['competitor_aggregation_top10'] = $competitorAggregation;

    // Find target rank among ALL competitors (not just top 10)
    $stmtAllComp = db()->prepare('
        SELECT data_cid, place_id,
               (SUM(position) + 21 * (? - COUNT(*))) / ? as localo_avg
        FROM grid_competitors
        WHERE grid_scan_id = ?
        GROUP BY COALESCE(NULLIF(data_cid, \'\'), NULLIF(place_id, \'\'), title)
        HAVING COUNT(*) > 0
        ORDER BY localo_avg ASC
    ');
    $stmtAllComp->execute([$totalGridPoints, $totalGridPoints, $scanId]);
    $allComp = $stmtAllComp->fetchAll(PDO::FETCH_ASSOC);

    $targetRank = null;
    $targetAvg = null;
    $totalCompetitors = count($allComp);
    foreach ($allComp as $idx => $c) {
        $cCid = $c['data_cid'] ?? '';
        $cPid = $c['place_id'] ?? '';
        $isT = false;
        if ($googleCid && $cCid && $cCid === $googleCid) $isT = true;
        elseif ($placeId && $cPid && $cPid === $placeId) $isT = true;
        if ($isT) {
            $targetRank = $idx + 1;
            $targetAvg = round((float)$c['localo_avg'], 1);
            break;
        }
    }

    $locResult['target_summary'] = [
        'target_rank' => $targetRank,
        'target_avg_position' => $targetAvg,
        'total_competitors' => $totalCompetitors,
    ];

    $results[] = $locResult;
}

echo json_encode(['test_grid_data_flow' => $results, 'timestamp' => date('Y-m-d H:i:s')], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
