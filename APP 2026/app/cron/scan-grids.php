<?php
/**
 * BOUS'TACOM — CRON Scan automatique des grilles de positions
 * Securise par token SHA-256
 * A configurer sur InfoManiak : quotidien a 06:00 (le filtre hebdo est dans le PHP)
 * URL: https://app.boustacom.fr/app/cron/scan-grids.php?token=XXXX
 *
 * Pour chaque fiche active, scanne les mots-cles :
 *   Phase A : Position tracking (1 task DataForSEO au centre)
 *   Phase B : Grid scan sunburst 49 pts (Local Finder LIVE, curl_multi, 48 requetes)
 * Budget quotidien limite a CRON_DAILY_API_LIMIT.
 *
 * Moteur : DataForSEO (Maps async pour position, Local Finder LIVE pour grille)
 */

require_once __DIR__ . '/../config.php';

// ====== SECURITE ======
$expectedToken = hash('sha256', APP_SECRET . '_cron_scan_grids');
$providedToken = $_GET['token'] ?? '';

if (!hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    echo "Token invalide.\n";
    exit;
}

header('Content-Type: text/plain');
http_response_code(200);
set_time_limit(600);

// Activer l'affichage d'erreurs dans les crons (sinon les fatals sont invisibles)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Shutdown handler : capter les fatals et les afficher dans la reponse cron
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "\n\n!!! FATAL ERROR: {$err['message']} in {$err['file']}:{$err['line']}\n";
    }
});

$now = new DateTime('now', new DateTimeZone('Europe/Paris'));
$apiCallsUsed = 0;
$apiLimit = CRON_DAILY_API_LIMIT;

echo "=== CRON Scan Grilles — " . $now->format('Y-m-d H:i:s') . " ===\n";
echo "Budget API quotidien : {$apiLimit} appels\n";
echo "Moteur : DataForSEO Maps + Local Finder LIVE\n\n";

// ====== RECUPERER TOUTES LES FICHES ACTIVES ======
$stmt = db()->prepare('
    SELECT l.*, a.user_id
    FROM gbp_locations l
    JOIN gbp_accounts a ON l.gbp_account_id = a.id
    WHERE l.is_active = 1
');
$stmt->execute();
$locations = $stmt->fetchAll();

echo "Fiches actives trouvees : " . count($locations) . "\n\n";

if (empty($locations)) {
    echo "Aucune fiche active. Fin du cron.\n";
    exit;
}

// ============================================
// MATCHING CID-ONLY (v3 — refonte complete)
// ============================================
// Le CID (data_cid) est l'identifiant permanent Google.
// Auto-detecte au premier scan par scan-async.php et stocke dans gbp_locations.google_cid.
// Ici on compare via computeRankForPoint() : place_id > CID > fuzzy.

// ============================================
// BOUCLE PRINCIPALE — PAR FICHE
// ============================================

foreach ($locations as $location) {
    $locationId = (int)$location['id'];
    $locationName = $location['name'];

    echo "=== Fiche: {$locationName} (ID:{$locationId}) ===\n";

    // Verifier le budget restant
    if ($apiCallsUsed >= $apiLimit) {
        echo "  Budget API atteint ({$apiCallsUsed}/{$apiLimit}). Arret.\n\n";
        break;
    }

    // Obtenir les coordonnees GPS
    $lat = (float)($location['latitude'] ?? 0);
    $lng = (float)($location['longitude'] ?? 0);

    if (!$lat || !$lng) {
        echo "  ERREUR: Coordonnees GPS manquantes. Renseignez-les dans l'onglet Parametres.\n\n";
        continue;
    }

    // Preparer le matching 3-tier (place_id > CID > fuzzy)
    $googleCid = $location['google_cid'] ?? '';
    $locationPlaceId = $location['place_id'] ?? '';
    if (empty($googleCid) && empty($locationPlaceId) && empty($location['name'])) {
        echo "  ATTENTION: Aucun identifiant (CID, place_id) ni nom disponible. Fiche ignoree.\n\n";
        continue;
    }
    if (empty($googleCid) && empty($locationPlaceId)) {
        echo "  INFO: CID et place_id absents. Matching par nom uniquement (fuzzy).\n";
    } elseif (empty($googleCid)) {
        echo "  INFO: CID absent. Matching par place_id + fuzzy.\n";
    }

    // Recuperer les mots-cles actifs dont le dernier scan date de plus de CRON_SCAN_INTERVAL_DAYS jours
    // Les scans manuels (live_scan / scan-async) ne sont pas affectes par ce filtre
    $intervalDays = CRON_SCAN_INTERVAL_DAYS;
    $stmtKw = db()->prepare("
        SELECT * FROM keywords
        WHERE location_id = ? AND is_active = 1
          AND (last_grid_scan_at IS NULL OR last_grid_scan_at < DATE_SUB(NOW(), INTERVAL {$intervalDays} DAY))
        ORDER BY last_grid_scan_at ASC
    ");
    $stmtKw->execute([$locationId]);
    $keywords = $stmtKw->fetchAll();

    echo "  Mots-cles a scanner (intervalle {$intervalDays}j) : " . count($keywords) . "\n";

    if (empty($keywords)) {
        echo "  Tous les mots-cles ont ete scannes recemment. Fiche ignoree.\n\n";
        continue;
    }

    foreach ($keywords as $keyword) {
        $keywordId = (int)$keyword['id'];
        $keywordText = $keyword['keyword'];
        $targetCity   = $keyword['target_city'] ?? '';

        echo "\n  >> Mot-cle: \"{$keywordText}\" (ID:{$keywordId})" . ($targetCity ? " [ville: {$targetCity}]" : '') . "\n";

        // Verifier le budget pour au moins la Phase A (1 task)
        if ($apiCallsUsed >= $apiLimit) {
            echo "     Budget API atteint ({$apiCallsUsed}/{$apiLimit}). Arret.\n";
            break 2;
        }

        // Log de debut
        $stmtLog = db()->prepare('
            INSERT INTO cron_scan_log (location_id, scan_type, keywords_scanned, grid_points_scanned, api_calls_used, status, started_at)
            VALUES (?, ?, 0, 0, 0, ?, NOW())
        ');
        $stmtLog->execute([$locationId, 'grid_scan', 'running']);
        $logId = db()->lastInsertId();

        $kwApiCalls = 0;
        $kwGridPoints = 0;

        try {
            $searchQuery = $keywordText;

            // ====== PHASE A : POSITION TRACKING (1 task DataForSEO au centre) ======
            echo "     Phase A: Position tracking (DataForSEO batch)...\n";

            $posResult = dataforseoPostTasks([
                ['keyword' => $searchQuery, 'lat' => $lat, 'lng' => $lng, 'tag' => 'center', 'target_city' => $targetCity]
            ]);

            if (!$posResult['success']) {
                throw new Exception('DataForSEO Phase A: echec du POST — ' . json_encode($posResult));
            }

            $kwApiCalls++;
            $apiCallsUsed++;

            // Attendre le resultat (max 60s)
            $posResults = dataforseoWaitForResults($posResult['task_ids'], 60);
            dbEnsureConnected(); // Reconnexion MySQL apres le polling DataForSEO

            if (empty($posResults)) {
                echo "     Phase A: Aucun resultat apres polling. Position inconnue.\n";
                $placesResults = [];
            } else {
                $firstResult = reset($posResults);
                $placesResults = $firstResult['items'] ?? [];
                echo "     Phase A: " . count($placesResults) . " resultats recus.\n";
            }

            // Matching 3-tier : place_id > CID > fuzzy
            $centerPosition = null;
            $centerInLocalPack = 0;
            $matchResult = computeRankForPoint($location, $placesResults);
            if ($matchResult['found']) {
                $centerPosition = $matchResult['rank'];
                $centerInLocalPack = ($centerPosition !== null && $centerPosition <= 3) ? 1 : 0;
                echo "     Match method: {$matchResult['match_method']}\n";
                // Auto-backfill
                if (!empty($matchResult['backfill'])) {
                    applyBackfill($locationId, $matchResult['backfill'], 'cron-scan-grids');
                    $location = array_merge($location, $matchResult['backfill']);
                    $googleCid = $location['google_cid'] ?? '';
                    echo "     AUTO-BACKFILL: " . json_encode($matchResult['backfill']) . "\n";
                }
            } elseif (!empty($placesResults)) {
                $centerPosition = 101;
                echo "     Non trouve dans les 100 premiers resultats (position 101)\n";
            }

            // Sauvegarder dans keyword_positions
            $stmtPos = db()->prepare('
                INSERT INTO keyword_positions (keyword_id, position, in_local_pack, tracked_at)
                VALUES (?, ?, ?, CURDATE())
                ON DUPLICATE KEY UPDATE position = VALUES(position), in_local_pack = VALUES(in_local_pack)
            ');
            $stmtPos->execute([$keywordId, $centerPosition, $centerInLocalPack]);

            $posLabel = $centerPosition !== null ? "#$centerPosition" : "non trouve";
            echo "     Position centre: {$posLabel}" . ($centerInLocalPack ? " (Local Pack)" : "") . "\n";

            // ====== PHASE B : GRID SCAN (batch DataForSEO) ======
            // Grille sunburst 49 points (deterministe par location+keyword)
            $gridPoints = generateGridPoints49($lat, $lng, $locationId, $keywordId);
            $totalPoints = count($gridPoints); // 49
            $maxRadius = 15.0;

            // En batch DataForSEO : 1 POST pour tous les points non-centre
            $nonCenterCount = 0;
            foreach ($gridPoints as $pt) {
                if (empty($pt['is_center'])) $nonCenterCount++;
            }

            if (($apiCallsUsed + $nonCenterCount) > $apiLimit) {
                echo "     Phase B: Budget insuffisant ({$nonCenterCount} tasks necessaires, reste " . ($apiLimit - $apiCallsUsed) . "). Grid scan reporte.\n";
                $stmtLogUp = db()->prepare('
                    UPDATE cron_scan_log SET status = ?, keywords_scanned = 1, grid_points_scanned = 0, api_calls_used = ?, completed_at = NOW()
                    WHERE id = ?
                ');
                $stmtLogUp->execute(['partial', $kwApiCalls, $logId]);
                continue;
            }

            echo "     Phase B: Grid scan ({$totalPoints} points, {$nonCenterCount} tasks DataForSEO)...\n";

            // Rayon total pour stockage DB
            $radiusKmTotal = $maxRadius;

            // Creer l'enregistrement du scan
            $stmtScan = db()->prepare('
                INSERT INTO grid_scans (keyword_id, grid_size, radius_km, grid_type, num_rings, total_points, scanned_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ');
            $stmtScan->execute([$keywordId, 49, $radiusKmTotal, 'sunburst', 0, $totalPoints]);
            $scanId = db()->lastInsertId();

            // Positions pour le calcul KPI via computeGridKPIs()
            $allPositions = [];

            // Construire le batch de tasks pour les points non-centre
            $batchTasks = [];
            $taskIndexMap = []; // taskIndex => gridPointIndex
            $taskIdx = 0;

            foreach ($gridPoints as $pointIdx => $point) {
                if (!empty($point['is_center'])) continue;

                $batchTasks[] = [
                    'keyword'     => $searchQuery,
                    'lat'         => $point['latitude'],
                    'lng'         => $point['longitude'],
                    'tag'         => "pt_{$pointIdx}",
                    'target_city' => $targetCity,
                ];
                $taskIndexMap[$taskIdx] = $pointIdx;
                $taskIdx++;
            }

            // LOCAL_FINDER LIVE via curl_multi
            echo "     Phase B: Envoi de " . count($batchTasks) . " tasks en LOCAL_FINDER LIVE...\n";
            $batchResult = dataforseoLocalFinderLive($batchTasks, 100);

            if (!$batchResult['success']) {
                echo "     Phase B: Echec LOCAL_FINDER LIVE — " . json_encode($batchResult) . "\n";
                // Nettoyer le scan incomplet
                db()->prepare('DELETE FROM grid_scans WHERE id = ?')->execute([$scanId]);
                $stmtLogUp = db()->prepare('
                    UPDATE cron_scan_log SET status = ?, error_message = ?, api_calls_used = ?, completed_at = NOW()
                    WHERE id = ?
                ');
                $stmtLogUp->execute(['error', 'DataForSEO LOCAL_FINDER LIVE failed', $kwApiCalls, $logId]);
                continue;
            }

            $kwApiCalls += $nonCenterCount;
            $apiCallsUsed += $nonCenterCount;

            // Resultats deja disponibles (mode LIVE = synchrone)
            $gridResults = $batchResult['results'];
            dbEnsureConnected();

            echo "     Phase B: " . count($gridResults) . "/" . count($batchResult['task_ids']) . " resultats recus (LIVE).\n";

            // Mapper task_id => gridPointIndex pour retrouver chaque point
            $taskIdToPointIdx = [];
            foreach ($batchResult['task_ids'] as $tIdx => $tId) {
                if (isset($taskIndexMap[$tIdx])) {
                    $taskIdToPointIdx[$tId] = $taskIndexMap[$tIdx];
                }
            }

            // Traiter TOUS les points (centre + non-centre)
            foreach ($gridPoints as $pointIdx => $point) {
                $position = null;
                $foundName = null;
                $ptMatchMethod = null;
                $ptPlacesResults = [];

                if (!empty($point['is_center'])) {
                    // Point central : reutiliser la position de Phase A
                    $position = $centerPosition;
                    $foundName = $position !== null ? $location['name'] : null;
                    $ptMatchMethod = isset($matchResult) ? ($matchResult['match_method'] ?? null) : null;
                    $ptPlacesResults = $placesResults;
                } else {
                    // Point non-central : chercher dans les resultats batch
                    $foundTaskResult = null;
                    foreach ($taskIdToPointIdx as $tId => $pIdx) {
                        if ($pIdx === $pointIdx && isset($gridResults[$tId])) {
                            $foundTaskResult = $gridResults[$tId];
                            break;
                        }
                    }

                    if ($foundTaskResult) {
                        $ptPlacesResults = $foundTaskResult['items'] ?? [];
                    }

                    // Matching 3-tier
                    $ptMatch = computeRankForPoint($location, $ptPlacesResults);
                    if ($ptMatch['found']) {
                        $position = $ptMatch['rank'];
                        $foundName = $ptMatch['matched_name'];
                        $ptMatchMethod = $ptMatch['match_method'] ?? null;
                        if (!empty($ptMatch['backfill'])) {
                            applyBackfill($locationId, $ptMatch['backfill'], 'cron-scan-grids-pt');
                            $location = array_merge($location, $ptMatch['backfill']);
                            $googleCid = $location['google_cid'] ?? '';
                        }
                    } elseif (!empty($ptPlacesResults)) {
                        $position = 101;
                    }
                }

                // Sauvegarder le point
                $stmtPt = db()->prepare('
                    INSERT INTO grid_points (grid_scan_id, row_index, col_index, latitude, longitude, position, business_name_found, match_method)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmtPt->execute([
                    $scanId, $point['row'], $point['col'],
                    $point['latitude'], $point['longitude'],
                    $position, $foundName, $ptMatchMethod,
                ]);
                $pointDbId = db()->lastInsertId();

                // Sauvegarder TOUS les concurrents (depth=100) — pas de slice
                foreach ($ptPlacesResults as $item) {
                    $isTarget = determineIsTarget($item, $location);
                    try {
                        $stmtComp = db()->prepare('
                            INSERT INTO grid_competitors (grid_scan_id, grid_point_id, position, title, address, phone, website, category, rating, reviews_count, place_id, data_cid, is_target)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ');
                        $stmtComp->execute([
                            $scanId, $pointDbId,
                            $item['position'] ?? 0, $item['title'] ?? '',
                            $item['address'] ?? null, $item['phone'] ?? null,
                            $item['link'] ?? $item['website'] ?? null,
                            $item['category'] ?? $item['type'] ?? null,
                            $item['rating'] ?? null, $item['reviews'] ?? 0,
                            $item['place_id'] ?? null, $item['data_cid'] ?? null,
                            $isTarget ? 1 : 0,
                        ]);
                    } catch (Exception $e) {
                        logAppError("Insert competitor failed: " . $e->getMessage(), 'cron_error', 'warning', ['source' => 'cron/scan-grids.php', 'action' => 'insert_competitor', 'keyword_id' => $keywordId]);
                    }
                }

                $allPositions[] = $position;
                $kwGridPoints++;
            }

            // Calculer les KPI avec la nouvelle formule (Top3/Total x 100)
            $kpis = computeGridKPIs($allPositions, $totalPoints);

            // Mettre a jour grid_scans avec les stats
            $stmtScanUp = db()->prepare('
                UPDATE grid_scans SET
                    avg_position = ?, visibility_score = ?,
                    top3_count = ?, top10_count = ?, top20_count = ?, out_count = ?
                WHERE id = ?
            ');
            $stmtScanUp->execute([
                $kpis['avg_position'], $kpis['visibility_score'],
                $kpis['top3_count'], $kpis['top10_count'], $kpis['top20_count'], $kpis['out_count'],
                $scanId,
            ]);

            // Mettre a jour last_grid_scan_at du mot-cle
            $stmtKwUp = db()->prepare('UPDATE keywords SET last_grid_scan_at = NOW() WHERE id = ?');
            $stmtKwUp->execute([$keywordId]);

            echo "     Stats: avg=#" . ($kpis['avg_position'] ?? '-') . " vis={$kpis['visibility_score']}% top3={$kpis['top3_count']} top10={$kpis['top10_count']} top20={$kpis['top20_count']} out={$kpis['out_count']}\n";
            echo "     API calls: {$kwApiCalls} | Budget restant: " . ($apiLimit - $apiCallsUsed) . "/{$apiLimit}\n";

            // Mettre a jour le log comme termine
            $stmtLogUp = db()->prepare('
                UPDATE cron_scan_log SET status = ?, keywords_scanned = 1, grid_points_scanned = ?, api_calls_used = ?, completed_at = NOW()
                WHERE id = ?
            ');
            $stmtLogUp->execute(['completed', $kwGridPoints, $kwApiCalls, $logId]);

        } catch (Exception $e) {
            echo "     ERREUR: " . $e->getMessage() . "\n";
            logAppError("Keyword {$keywordId} scan error: " . $e->getMessage(), 'cron_error', 'error', ['source' => 'cron/scan-grids.php', 'action' => 'keyword_scan', 'keyword_id' => $keywordId, 'stack' => $e->getTraceAsString()]);
            $stmtLogUp = db()->prepare('
                UPDATE cron_scan_log SET status = ?, error_message = ?, keywords_scanned = 0, grid_points_scanned = ?, api_calls_used = ?, completed_at = NOW()
                WHERE id = ?
            ');
            $stmtLogUp->execute(['error', substr($e->getMessage(), 0, 500), $kwGridPoints, $kwApiCalls, $logId]);
            continue;
        }
    }

    echo "\n";
}

// ====== RESUME FINAL ======
$finalStatus = ($apiCallsUsed >= $apiLimit) ? 'partial' : 'completed';
echo "=== Termine ({$finalStatus}). Appels API utilises: {$apiCallsUsed}/{$apiLimit} ===\n";
