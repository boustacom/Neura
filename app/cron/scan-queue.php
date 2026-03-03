<?php
/**
 * BOUS'TACOM — CRON Scan Queue (toutes les 5 minutes)
 *
 * Traite les keywords en file d'attente (table scan_queue).
 * Securise par token SHA-256.
 * URL: https://app.boustacom.fr/app/cron/scan-queue.php?token=XXXX
 *
 * Pour chaque entree pending :
 *   Phase A : Position tracking (1 task DataForSEO au centre)
 *   Phase B : Grid scan circulaire (batch de N tasks GPS en 1 POST)
 *   Phase C : Calcul KPI via computeGridKPIs()
 *
 * Moteur : DataForSEO (Maps async pour position, Local Finder LIVE pour grille)
 */

require_once __DIR__ . '/../config.php';

// ====== SECURITE ======
$expectedToken = hash('sha256', APP_SECRET . '_cron_scan_queue');
$providedToken = $_GET['token'] ?? '';

if (!hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    echo "Token invalide.\n";
    exit;
}

header('Content-Type: text/plain');
http_response_code(200);
set_time_limit(600);
ini_set('display_errors', 1);
error_reporting(E_ALL);
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "\n\n!!! FATAL ERROR: {$err['message']} in {$err['file']}:{$err['line']}\n";
    }
});

// ====== LOCK FILE — Empecher les executions simultanees ======
$lockFile = sys_get_temp_dir() . '/boustacom_scan_queue.lock';
if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge < 540) { // 9 minutes — si le lock est recent, une autre instance tourne
        echo "Lock file actif depuis {$lockAge}s. Une autre instance est en cours.\n";
        exit;
    }
    // Lock perime (> 9 min) — le supprimer et continuer
    echo "Lock perime ({$lockAge}s). Nettoyage.\n";
    unlink($lockFile);
}
file_put_contents($lockFile, date('Y-m-d H:i:s'));
register_shutdown_function(function() use ($lockFile) { @unlink($lockFile); });

$now = new DateTime('now', new DateTimeZone('Europe/Paris'));
echo "=== CRON Scan Queue — " . $now->format('Y-m-d H:i:s') . " ===\n";

// ====== NETTOYER LES ENTREES PERIMEES ======

// 1. Entries stuck in "processing" > 10 min → mark as failed
try {
    $stmtStuck = db()->prepare("
        UPDATE scan_queue SET status = 'failed', error = 'Timeout: processing > 10 min', completed_at = NOW()
        WHERE status = 'processing' AND started_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    ");
    $stmtStuck->execute();
    $stuck = $stmtStuck->rowCount();
    if ($stuck > 0) echo "Entries stuck nettoyees: {$stuck}\n";
} catch (Exception $e) {
    error_log("scan-queue.php: cleanup stuck failed: " . $e->getMessage());
}

// 2. Delete completed entries > 24h
try {
    db()->exec("DELETE FROM scan_queue WHERE status IN ('completed','failed') AND completed_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
} catch (Exception $e) { /* ignore */ }

// ====== RECUPERER LES ENTREES PENDING ======
$stmt = db()->prepare("
    SELECT sq.*, k.keyword, k.is_active, k.grid_radius_km AS kw_grid_radius_km, k.target_city,
           l.name AS location_name, l.latitude, l.longitude,
           l.google_cid, l.place_id,
           l.grid_num_rings, l.grid_radius_km
    FROM scan_queue sq
    JOIN keywords k ON sq.keyword_id = k.id
    JOIN gbp_locations l ON sq.location_id = l.id
    WHERE sq.status = 'pending'
    ORDER BY sq.priority DESC, sq.created_at ASC
    LIMIT 5
");
$stmt->execute();
$queue = $stmt->fetchAll();

echo "Entries pending: " . count($queue) . "\n\n";

if (empty($queue)) {
    echo "Rien a traiter. Fin.\n";
    exit;
}

foreach ($queue as $entry) {
    $queueId    = (int)$entry['id'];
    $keywordId  = (int)$entry['keyword_id'];
    $locationId = (int)$entry['location_id'];
    $keywordText = $entry['keyword'];
    $targetCity   = $entry['target_city'] ?? '';

    echo "=== Queue #{$queueId}: \"{$keywordText}\" (kw:{$keywordId}, loc:{$locationId}) ===\n";

    // Verifier que le keyword est toujours actif
    if (!$entry['is_active']) {
        echo "  Mot-cle inactif. Skip.\n";
        db()->prepare("UPDATE scan_queue SET status = 'failed', error = 'Keyword inactive', completed_at = NOW() WHERE id = ?")->execute([$queueId]);
        continue;
    }

    // Mark as processing
    db()->prepare("UPDATE scan_queue SET status = 'processing', started_at = NOW() WHERE id = ?")->execute([$queueId]);

    $lat = (float)$entry['latitude'];
    $lng = (float)$entry['longitude'];

    if (!$lat || !$lng) {
        echo "  GPS manquantes. Skip.\n";
        db()->prepare("UPDATE scan_queue SET status = 'failed', error = 'Coordonnees GPS manquantes', completed_at = NOW() WHERE id = ?")->execute([$queueId]);
        continue;
    }

    // Construire un objet $location compatible avec computeRankForPoint()
    $location = [
        'id'          => $locationId,
        'name'        => $entry['location_name'],
        'latitude'    => $lat,
        'longitude'   => $lng,
        'google_cid'  => $entry['google_cid'] ?? '',
        'place_id'    => $entry['place_id'] ?? '',
    ];

    // Grille sunburst 49 points (deterministe par location+keyword)
    $gridPoints = generateGridPoints49($lat, $lng, $locationId, $keywordId);
    $totalGridPoints = count($gridPoints); // 49
    $maxRadius = 15.0;
    $today = date('Y-m-d');
    $startTime = microtime(true);

    try {
        // ============================================================
        // PHASE A — POSITION TRACKING (centre)
        // ============================================================
        echo "  Phase A: Position tracking...\n";

        $posResult = dataforseoPostTasks([
            ['keyword' => $keywordText, 'lat' => $lat, 'lng' => $lng, 'tag' => 'pos_center', 'target_city' => $targetCity]
        ]);

        if (!$posResult['success']) {
            throw new Exception('DataForSEO Phase A: ' . ($posResult['error'] ?? 'echec POST'));
        }

        // Polling standard (5s) — max 90 secondes
        $posResults = dataforseoWaitForResults($posResult['task_ids'], 90, null, 5);
        dbEnsureConnected();

        $posData  = !empty($posResult['task_ids']) ? ($posResults[$posResult['task_ids'][0]] ?? null) : null;
        $posItems = $posData ? ($posData['items'] ?? []) : [];

        $position    = null;
        $inLocalPack = false;

        if (!empty($posItems)) {
            $matchResult = computeRankForPoint($location, $posItems);
            if ($matchResult['found']) {
                $position    = $matchResult['rank'];
                $inLocalPack = ($matchResult['rank'] !== null && $matchResult['rank'] <= 3);

                if (!empty($matchResult['backfill'])) {
                    applyBackfill($locationId, $matchResult['backfill'], 'cron-queue-pos');
                    $location = array_merge($location, $matchResult['backfill']);
                }
            }
        }

        // Sauvegarder la position
        $stmtPos = db()->prepare('
            INSERT INTO keyword_positions (keyword_id, position, in_local_pack, tracked_at)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE position = VALUES(position), in_local_pack = VALUES(in_local_pack)
        ');
        $stmtPos->execute([$keywordId, $position, $inLocalPack ? 1 : 0, $today]);

        echo "  Position centre: " . ($position !== null ? "#$position" : "non trouve") . ($inLocalPack ? " (Local Pack)" : "") . "\n";

        // ============================================================
        // PHASE B — GRID SCAN (batch)
        // ============================================================
        echo "  Phase B: Grid scan ({$totalGridPoints} points)...\n";

        $radiusKmTotal = $maxRadius;

        $stmtScan = db()->prepare('
            INSERT INTO grid_scans (keyword_id, grid_size, radius_km, grid_type, num_rings, total_points, scanned_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmtScan->execute([$keywordId, 49, $radiusKmTotal, 'sunburst', 0, $totalGridPoints]);
        $scanId = db()->lastInsertId();

        // Construire le batch
        $batchTasks = [];
        foreach ($gridPoints as $pointIdx => $point) {
            $batchTasks[] = [
                'keyword'     => $keywordText,
                'lat'         => $point['latitude'],
                'lng'         => $point['longitude'],
                'tag'         => "grid_{$scanId}_{$pointIdx}_{$point['row']}_{$point['col']}",
                'target_city' => $targetCity,
            ];
        }

        // LOCAL_FINDER LIVE via curl_multi
        $batchResult = dataforseoLocalFinderLive($batchTasks, 100);

        if (!$batchResult['success']) {
            try { db()->prepare('DELETE FROM grid_scans WHERE id = ?')->execute([$scanId]); } catch (Exception $e) {}
            throw new Exception('DataForSEO Phase B: ' . ($batchResult['error'] ?? 'echec POST'));
        }

        // Mapper task_id → point index
        $taskToPoint = [];
        foreach ($batchResult['task_ids'] as $i => $taskId) {
            $taskToPoint[$taskId] = $i;
        }

        // Resultats deja disponibles (mode LIVE = synchrone)
        $gridResults = $batchResult['results'];
        dbEnsureConnected();

        echo "  Phase B: " . count($gridResults) . "/" . count($batchResult['task_ids']) . " resultats.\n";

        // ============================================================
        // PHASE C — TRAITEMENT + CALCUL KPI
        // ============================================================

        $allPositions = [];

        foreach ($batchResult['task_ids'] as $taskId) {
            $pointIdx = $taskToPoint[$taskId] ?? null;
            if ($pointIdx === null) continue;

            $point = $gridPoints[$pointIdx];
            $taskResult = $gridResults[$taskId] ?? null;
            $placesResults = $taskResult ? ($taskResult['items'] ?? []) : [];

            $gridPosition = null;
            $foundName = null;
            $matchMethod = null;

            if (!empty($placesResults)) {
                $ptMatch = computeRankForPoint($location, $placesResults);
                if ($ptMatch['found']) {
                    $gridPosition = $ptMatch['rank'];
                    $foundName    = $ptMatch['matched_name'];
                    $matchMethod  = $ptMatch['match_method'] ?? null;

                    if (!empty($ptMatch['backfill'])) {
                        applyBackfill($locationId, $ptMatch['backfill'], 'cron-queue-grid');
                        $location = array_merge($location, $ptMatch['backfill']);
                    }
                }
            }

            // Position en base : si pas trouve dans les resultats → 101
            $dbPosition = $gridPosition;
            if ($dbPosition === null && !empty($placesResults)) {
                $dbPosition = 101;
            }

            // Sauvegarder le point
            $stmtPt = db()->prepare('
                INSERT INTO grid_points (grid_scan_id, row_index, col_index, latitude, longitude, position, business_name_found, match_method)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmtPt->execute([$scanId, $point['row'], $point['col'], $point['latitude'], $point['longitude'], $dbPosition, $foundName, $matchMethod]);
            $pointDbId = db()->lastInsertId();

            // Sauvegarder TOUS les concurrents (depth=100) — pas de slice
            foreach ($placesResults as $item) {
                $isTarget = determineIsTarget($item, $location);
                try {
                    $stmtComp = db()->prepare('
                        INSERT INTO grid_competitors
                        (grid_scan_id, grid_point_id, position, title, address, phone, website, category, rating, reviews_count, place_id, data_cid, is_target)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ');
                    $stmtComp->execute([
                        $scanId, $pointDbId,
                        $item['position'] ?? 0,
                        $item['title'] ?? '',
                        $item['address'] ?? null,
                        $item['phone'] ?? null,
                        $item['link'] ?? $item['website'] ?? null,
                        $item['category'] ?? $item['type'] ?? null,
                        $item['rating'] ?? null,
                        $item['reviews'] ?? 0,
                        $item['place_id'] ?? null,
                        $item['data_cid'] ?? null,
                        $isTarget ? 1 : 0,
                    ]);
                } catch (Exception $e) {
                    error_log("scan-queue.php: insert competitor failed: " . $e->getMessage());
                }
            }

            $allPositions[] = $dbPosition;
        }

        // Calcul KPI avec la nouvelle formule
        $kpis = computeGridKPIs($allPositions, $totalGridPoints);

        // Mettre a jour grid_scans
        db()->prepare('
            UPDATE grid_scans
            SET avg_position = ?, visibility_score = ?, top3_count = ?, top10_count = ?, top20_count = ?, out_count = ?
            WHERE id = ?
        ')->execute([
            $kpis['avg_position'], $kpis['visibility_score'],
            $kpis['top3_count'], $kpis['top10_count'], $kpis['top20_count'], $kpis['out_count'],
            $scanId,
        ]);

        // ====== FALLBACK POSITION : si Phase A (Maps) a echoue, utiliser le point centre de la grille ======
        if ($position === null) {
            // Chercher la position du point centre dans la grille
            foreach ($batchResult['task_ids'] as $taskId) {
                $ptIdx = $taskToPoint[$taskId] ?? null;
                if ($ptIdx === null) continue;
                $pt = $gridPoints[$ptIdx];
                if (!empty($pt['is_center'])) {
                    $centerResult = $gridResults[$taskId] ?? null;
                    $centerItems = $centerResult ? ($centerResult['items'] ?? []) : [];
                    if (!empty($centerItems)) {
                        $centerMatch = computeRankForPoint($location, $centerItems);
                        if ($centerMatch['found'] && $centerMatch['rank'] !== null && $centerMatch['rank'] <= 100) {
                            $position = $centerMatch['rank'];
                            $inLocalPack = ($position <= 3);
                            // Mettre a jour keyword_positions avec la position du centre grille
                            try {
                                db()->prepare('
                                    INSERT INTO keyword_positions (keyword_id, position, in_local_pack, tracked_at)
                                    VALUES (?, ?, ?, ?)
                                    ON DUPLICATE KEY UPDATE position = VALUES(position), in_local_pack = VALUES(in_local_pack)
                                ')->execute([$keywordId, $position, $inLocalPack ? 1 : 0, $today]);
                                echo "  Fallback: position centre grille = #{$position}\n";
                            } catch (Exception $e) {
                                error_log("scan-queue.php: fallback position save failed kw={$keywordId}: " . $e->getMessage());
                            }
                        }
                    }
                    break;
                }
            }
        }

        // Mettre a jour last_grid_scan_at du mot-cle
        try {
            db()->prepare('UPDATE keywords SET last_grid_scan_at = NOW() WHERE id = ?')->execute([$keywordId]);
        } catch (Exception $e) {
            error_log("scan-queue.php: update last_grid_scan_at failed: " . $e->getMessage());
        }

        $duration = round(microtime(true) - $startTime, 1);

        // Marquer comme complete
        db()->prepare("UPDATE scan_queue SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$queueId]);

        echo "  OK! Vis={$kpis['visibility_score']}% Avg={$kpis['avg_position']} Top3={$kpis['top3_count']} ({$duration}s)\n\n";

    } catch (Exception $e) {
        echo "  ERREUR: " . $e->getMessage() . "\n\n";
        error_log("scan-queue.php: queue #{$queueId} error: " . $e->getMessage());
        try {
            db()->prepare("UPDATE scan_queue SET status = 'failed', error = ?, completed_at = NOW() WHERE id = ?")->execute([substr($e->getMessage(), 0, 1000), $queueId]);
        } catch (Exception $e2) {
            error_log("scan-queue.php: update queue status failed: " . $e2->getMessage());
        }
    }
}

echo "=== Termine. ===\n";
