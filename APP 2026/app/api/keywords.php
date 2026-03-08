<?php
/**
 * BOUS'TACOM — API Liste des mots-cles avec positions + visibility
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();
requireCsrf();

header('Content-Type: application/json');

$locationId = $_GET['location_id'] ?? null;

if (!$locationId) {
    jsonResponse(['error' => 'location_id requis'], 400);
}

// Recuperer les mots-cles avec leur derniere position + visibility du dernier grid scan
$stmt = db()->prepare('
    SELECT
        k.id,
        k.keyword,
        k.target_city,
        k.grid_radius_km,
        k.search_volume,
        k.is_active,
        k.last_grid_scan_at,
        k.last_manual_scan_at,
        kp_latest.position AS current_position,
        kp_latest.in_local_pack,
        kp_latest.tracked_at AS last_tracked,
        kp_prev.position AS previous_position,
        gs_latest.visibility_score,
        gs_latest.avg_position AS grid_avg_position,
        gs_latest.scanned_at AS grid_scanned_at
    FROM keywords k
    LEFT JOIN keyword_positions kp_latest ON kp_latest.keyword_id = k.id
        AND kp_latest.tracked_at = (
            SELECT MAX(kp2.tracked_at) FROM keyword_positions kp2 WHERE kp2.keyword_id = k.id
        )
    LEFT JOIN keyword_positions kp_prev ON kp_prev.keyword_id = k.id
        AND kp_prev.tracked_at = (
            SELECT MAX(kp3.tracked_at) FROM keyword_positions kp3
            WHERE kp3.keyword_id = k.id AND kp3.tracked_at < COALESCE(kp_latest.tracked_at, CURDATE())
        )
    LEFT JOIN grid_scans gs_latest ON gs_latest.keyword_id = k.id
        AND gs_latest.scanned_at = (
            SELECT MAX(gs2.scanned_at) FROM grid_scans gs2 WHERE gs2.keyword_id = k.id
        )
    WHERE k.location_id = ? AND k.is_active = 1
    ORDER BY kp_latest.position ASC, k.keyword ASC
');
$stmt->execute([$locationId]);
$keywords = $stmt->fetchAll();

// Charger la location pour le CID/place_id (rang Localo)
$stmtLoc = db()->prepare('SELECT google_cid, place_id, name, city, latitude, longitude FROM gbp_locations WHERE id = ?');
$stmtLoc->execute([$locationId]);
$loc = $stmtLoc->fetch();
$googleCid = $loc['google_cid'] ?? '';
$locPlaceId = $loc['place_id'] ?? '';

// Calculer les stats
$total = count($keywords);
$tracked = 0;
$top3 = 0;
$top10 = 0;
$top20 = 0;
$out = 0;
$posSum = 0;
$posCount = 0;

foreach ($keywords as &$kw) {
    $pos = $kw['current_position'];
    $prev = $kw['previous_position'];

    if ($pos !== null) {
        $tracked++;

        // Moyenne : inclure toutes les positions (1-101, depth=100)
        $posSum += (int)$pos;
        $posCount++;

        if ($pos <= 3) $top3++;
        elseif ($pos <= 10) $top10++;
        elseif ($pos <= 20) $top20++;
        else $out++;

        // Trend
        if ($prev !== null) {
            $kw['trend'] = (int)$prev - (int)$pos;
        } else {
            $kw['trend'] = null;
        }
    } else {
        $out++;
        $kw['trend'] = null;
    }

    // Historique des 30 derniers jours
    $stmtHist = db()->prepare('
        SELECT tracked_at, position FROM keyword_positions
        WHERE keyword_id = ?
        ORDER BY tracked_at DESC LIMIT 30
    ');
    $stmtHist->execute([$kw['id']]);
    $kw['history'] = array_reverse($stmtHist->fetchAll());

    // ====== RATE LIMIT : scan manuel 1x/semaine ======
    if ($kw['last_manual_scan_at']) {
        $nextTs = strtotime($kw['last_manual_scan_at']) + (7 * 86400);
        $kw['next_manual_scan_at'] = date('Y-m-d\TH:i:s', $nextTs);
        $kw['can_manual_scan'] = (time() >= $nextTs);
    } else {
        $kw['next_manual_scan_at'] = null;
        $kw['can_manual_scan'] = true;
    }

    // ====== RANG LOCALO ======
    // Calculer le rang de la fiche parmi les concurrents sur le dernier grid scan
    $kw['grid_rank'] = null;
    $kw['grid_total_competitors'] = null;
    if (!empty($kw['grid_scanned_at'])) {
        try {
            // Trouver le scan_id correspondant
            $stmtScan = db()->prepare('SELECT id, total_points FROM grid_scans WHERE keyword_id = ? AND scanned_at = ?');
            $stmtScan->execute([$kw['id'], $kw['grid_scanned_at']]);
            $scanRow = $stmtScan->fetch();
            if ($scanRow) {
                $scanId = $scanRow['id'];
                $totalPts = (int)$scanRow['total_points'];

                // Charger les concurrents et agréger en PHP (compatible MariaDB)
                $stmtC = db()->prepare('SELECT grid_point_id, position, data_cid, place_id, title FROM grid_competitors WHERE grid_scan_id = ?');
                $stmtC->execute([$scanId]);
                $allC = $stmtC->fetchAll();

                $grouped = [];
                foreach ($allC as $c) {
                    $key = !empty($c['data_cid']) ? $c['data_cid'] : (!empty($c['place_id']) ? $c['place_id'] : $c['title']);
                    if (!isset($grouped[$key])) $grouped[$key] = ['data_cid' => $c['data_cid'], 'place_id' => $c['place_id'], 'title' => $c['title'], 'points' => []];
                    $ptId = $c['grid_point_id'];
                    $p = (int)$c['position'];
                    if (!isset($grouped[$key]['points'][$ptId]) || $p < $grouped[$key]['points'][$ptId]) {
                        $grouped[$key]['points'][$ptId] = $p;
                    }
                }

                // Calculer les moyennes Localo et trier
                $avgs = [];
                foreach ($grouped as $key => $comp) {
                    $appearances = count($comp['points']);
                    $sumP = array_sum($comp['points']);
                    $missing = max(0, $totalPts - $appearances);
                    $localoAvg = $totalPts > 0 ? ($sumP + 101 * $missing) / $totalPts : 101;
                    $avgs[] = ['key' => $key, 'avg' => $localoAvg, 'data_cid' => $comp['data_cid'], 'place_id' => $comp['place_id'], 'title' => $comp['title'] ?? $key];
                }
                usort($avgs, fn($a, $b) => $a['avg'] <=> $b['avg']);

                $kw['grid_total_competitors'] = count($avgs);

                // Trouver le rang de notre fiche (3-tier : place_id > CID > fuzzy)
                foreach ($avgs as $idx => $a) {
                    $isTarget = false;
                    if ($locPlaceId && $a['place_id'] && $a['place_id'] === $locPlaceId) {
                        $isTarget = true;
                    } elseif ($googleCid && $a['data_cid'] && $a['data_cid'] === $googleCid) {
                        $isTarget = true;
                    } elseif (!empty($loc['name']) && !$a['data_cid'] && !$a['place_id']
                              && normalizeTitle($a['title'] ?? '') === normalizeTitle($loc['name'])) {
                        $isTarget = true;
                    }
                    if ($isTarget) {
                        $kw['grid_rank'] = $idx + 1;
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            error_log("keywords.php: rank calc failed for kw={$kw['id']}: " . $e->getMessage());
        }
    }
}

jsonResponse([
    'keywords' => $keywords,
    'location' => [
        'city' => $loc['city'] ?? '',
        'latitude' => $loc['latitude'] ?? null,
        'longitude' => $loc['longitude'] ?? null,
    ],
    'stats' => [
        'total' => $total,
        'tracked' => $tracked,
        'avg_position' => $posCount > 0 ? round($posSum / $posCount, 1) : null,
        'top3' => $top3,
        'top10' => $top10,
        'top20' => $top20,
        'out' => $out,
    ]
]);
