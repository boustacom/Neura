<?php
/**
 * BOUS'TACOM — Live Scan (Scan Asynchrone par mot-cle)
 *
 * Le frontend envoie un POST → le serveur repond immediatement "scan lance"
 * puis execute le scan en arriere-plan via fastcgi_finish_request().
 * Le frontend poll /api/live_scan.php?action=status&keyword_id=X toutes les 5s.
 *
 * Actions:
 *   POST (action=start, default) : Lance le scan en arriere-plan
 *   GET  (action=status)         : Retourne la progression / resultat
 *
 * POST params:
 *   - location_id (required)
 *   - keyword_id  (required)
 *   - action      (optional, default 'start')
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$action     = $_POST['action'] ?? $_GET['action'] ?? 'start';
$locationId = $_POST['location_id'] ?? $_GET['location_id'] ?? null;
$keywordId  = $_POST['keyword_id'] ?? $_GET['keyword_id'] ?? null;

// status ne necessite que keyword_id, start necessite les deux
if ($action === 'status') {
    if (!$keywordId) jsonResponse(['error' => 'keyword_id requis'], 400);
} else {
    if (!$locationId || !$keywordId) jsonResponse(['error' => 'location_id et keyword_id requis'], 400);
}

// Fichier de progression par mot-cle
$tmpDir = __DIR__ . '/../tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0755, true);
}
$progressFile = $tmpDir . '/kwscan_' . $keywordId . '.json';


// ============================================================
// ACTION : STATUS — Lire la progression du scan
// ============================================================
if ($action === 'status') {
    startSecureSession();
    requireLogin();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if (!file_exists($progressFile)) {
        jsonResponse(['success' => true, 'status' => 'idle']);
    }

    $progress = json_decode(file_get_contents($progressFile), true);
    if (!$progress) {
        @unlink($progressFile);
        jsonResponse(['success' => true, 'status' => 'idle']);
    }

    // AUTO-CLEAN : scan "running" depuis plus de 5 min = mort
    if (($progress['status'] ?? '') === 'running') {
        $startedAt = strtotime($progress['started_at'] ?? '');
        if ($startedAt && (time() - $startedAt) > 300) {
            $progress['status'] = 'failed';
            $progress['error'] = 'Scan expire (plus de 5 min). Relancez le scan.';
            @unlink($progressFile);
        }
    }

    // AUTO-CLEAN : si termine/echoue, supprimer le fichier apres lecture
    if (in_array($progress['status'] ?? '', ['completed', 'failed'])) {
        @unlink($progressFile);
    }

    jsonResponse(['success' => true] + $progress);
}


// ============================================================
// ACTION : START — Lancer le scan en arriere-plan
// ============================================================

startSecureSession();
requireLogin();
requireCsrf();
$user = currentUser();

$stmt = db()->prepare('
    SELECT l.*, a.user_id
    FROM gbp_locations l
    JOIN gbp_accounts a ON l.gbp_account_id = a.id
    WHERE l.id = ? AND a.user_id = ?
');
$stmt->execute([$locationId, $user['id']]);
$location = $stmt->fetch();

if (!$location) {
    jsonResponse(['error' => 'Fiche non trouvee'], 404);
}

$stmt = db()->prepare('SELECT * FROM keywords WHERE id = ? AND location_id = ? AND is_active = 1');
$stmt->execute([$keywordId, $locationId]);
$keyword = $stmt->fetch();

if (!$keyword) {
    jsonResponse(['error' => 'Mot-cle non trouve ou inactif'], 404);
}

$lat = (float)$location['latitude'];
$lng = (float)$location['longitude'];

if (!$lat || !$lng) {
    jsonResponse(['error' => 'Coordonnees GPS manquantes. Renseignez-les dans les parametres de la fiche.'], 400);
}

// Verifier si un scan est deja en cours pour ce mot-cle
if (file_exists($progressFile)) {
    $existing = json_decode(file_get_contents($progressFile), true);
    if ($existing && ($existing['status'] ?? '') === 'running') {
        $startedAt = strtotime($existing['started_at'] ?? '');
        if ($startedAt && (time() - $startedAt) < 300) {
            jsonResponse(['error' => 'Un scan est deja en cours pour ce mot-cle', 'status' => 'running'], 409);
        }
    }
    // Scan mort/fini → nettoyer
    @unlink($progressFile);
}

// ====== RATE LIMIT : max 1 scan manuel par mot-cle par 7 jours ======
$stmtManual = db()->prepare('SELECT last_manual_scan_at FROM keywords WHERE id = ?');
$stmtManual->execute([$keywordId]);
$kwManual = $stmtManual->fetch();
$lastManual = $kwManual['last_manual_scan_at'] ?? null;

if ($lastManual) {
    $lastManualTs = strtotime($lastManual);
    $nextAllowed = $lastManualTs + (7 * 86400);
    if (time() < $nextAllowed) {
        $nextDate = date('d/m/Y H:i', $nextAllowed);
        jsonResponse([
            'error' => 'Prochain scan manuel possible le ' . $nextDate,
            'rate_limited' => true,
            'next_scan_at' => date('Y-m-d\TH:i:s', $nextAllowed),
        ], 429);
    }
}

// Ecrire l'etat initial
$initialProgress = [
    'status'     => 'running',
    'started_at' => date('Y-m-d H:i:s'),
    'keyword_id' => (int)$keywordId,
    'keyword'    => $keyword['keyword'],
    'phase'      => 'starting',
    'error'      => null,
];
file_put_contents($progressFile, json_encode($initialProgress, JSON_UNESCAPED_UNICODE), LOCK_EX);

// ====== REPONDRE AU NAVIGATEUR IMMEDIATEMENT ======
// Fermer la session PHP pour debloquer les requetes de polling
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

ignore_user_abort(true);
set_time_limit(600);

$responseJson = json_encode([
    'success' => true,
    'status'  => 'started',
    'message' => 'Scan lance en arriere-plan',
], JSON_UNESCAPED_UNICODE);

http_response_code(200);
header('Content-Type: application/json');
header('Content-Length: ' . strlen($responseJson));
header('Connection: close');

while (ob_get_level()) ob_end_flush();
echo $responseJson;
flush();

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}


// ============================================================
// ====== EXECUTION EN ARRIERE-PLAN (navigateur deconnecte) ======
// ============================================================

$startTime = microtime(true);

$gridPoints = generateGridPoints49($lat, $lng, (int)$locationId, (int)$keywordId);
$totalGridPoints = count($gridPoints); // 49
$maxRadius = 15.0;

$keywordText = $keyword['keyword'];
$targetCity  = $keyword['target_city'] ?? '';
$today = date('Y-m-d');

$googleCid = $location['google_cid'] ?? '';
$placeId   = $location['place_id'] ?? '';


// ============================================================
// ETAPE A — POSITION TRACKING au centre (endpoint Maps, async)
// ============================================================

kwscanProgress($progressFile, ['phase' => 'position']);

$position    = null;
$inLocalPack = false;

$posResult = dataforseoPostTasks([
    ['keyword' => $keywordText, 'lat' => $lat, 'lng' => $lng, 'tag' => 'pos_center', 'target_city' => $targetCity]
], 100);

if (!$posResult['success']) {
    kwscanProgress($progressFile, [
        'status' => 'failed',
        'error'  => 'Erreur DataForSEO (position) : ' . ($posResult['error'] ?? 'inconnu'),
    ]);
    exit;
}

$posResults = dataforseoWaitForResults($posResult['task_ids'], 90, null, 2);
dbEnsureConnected();

$posData  = !empty($posResult['task_ids']) ? ($posResults[$posResult['task_ids'][0]] ?? null) : null;
$posItems = $posData ? ($posData['items'] ?? []) : [];

if (!empty($posItems)) {
    $matchResult = computeRankForPoint($location, $posItems);
    if ($matchResult['found']) {
        $position    = $matchResult['rank'];
        $inLocalPack = ($matchResult['rank'] !== null && $matchResult['rank'] <= 3);

        if (!empty($matchResult['backfill'])) {
            applyBackfill($locationId, $matchResult['backfill'], 'live-scan-pos');
            $location = array_merge($location, $matchResult['backfill']);
            $googleCid = $location['google_cid'] ?? '';
            $placeId   = $location['place_id'] ?? '';
        }

        $googleName = trim($matchResult['matched_name'] ?? '');
        if ($googleName && strtolower($googleName) !== strtolower($location['name'])) {
            try {
                db()->prepare('UPDATE gbp_locations SET name = ? WHERE id = ?')->execute([$googleName, $locationId]);
                $location['name'] = $googleName;
            } catch (Exception $e) {
                logAppError("Update location name failed: " . $e->getMessage(), 'scan_error', 'warning', [
                    'source' => 'live_scan.php', 'action' => 'update_location_name',
                    'location_id' => (int)$locationId, 'keyword_id' => (int)$keywordId,
                ]);
            }
        }
    }
}

// Sauvegarder la position
try {
    $stmtPos = db()->prepare('
        INSERT INTO keyword_positions (keyword_id, position, in_local_pack, tracked_at)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE position = VALUES(position), in_local_pack = VALUES(in_local_pack)
    ');
    $stmtPos->execute([$keywordId, $position, $inLocalPack ? 1 : 0, $today]);
} catch (Exception $e) {
    logAppError("Save position failed kw={$keywordId}: " . $e->getMessage(), 'scan_error', 'error', [
        'source' => 'live_scan.php', 'action' => 'save_position',
        'location_id' => (int)$locationId, 'keyword_id' => (int)$keywordId,
    ]);
}


// ============================================================
// ETAPE B — GRID SCAN (Local Finder Live, curl_multi)
// ============================================================

kwscanProgress($progressFile, ['phase' => 'grid']);

$radiusKmTotal = $maxRadius;

$stmtScan = db()->prepare('
    INSERT INTO grid_scans (keyword_id, grid_size, radius_km, grid_type, num_rings, total_points, scanned_at)
    VALUES (?, ?, ?, ?, ?, ?, NOW())
');
$stmtScan->execute([$keywordId, 49, $radiusKmTotal, 'sunburst', 0, $totalGridPoints]);
$scanId = db()->lastInsertId();

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

// LOCAL_FINDER LIVE via curl_multi : 49 requetes paralleles
$batchResult = dataforseoLocalFinderLive($batchTasks, 100);

if (!$batchResult['success']) {
    try { db()->prepare('DELETE FROM grid_scans WHERE id = ?')->execute([$scanId]); } catch (Exception $e) {}
    kwscanProgress($progressFile, [
        'status' => 'failed',
        'error'  => 'Erreur DataForSEO (grille) : ' . ($batchResult['error'] ?? 'inconnu'),
    ]);
    exit;
}

$taskToPoint = [];
foreach ($batchResult['task_ids'] as $i => $taskId) {
    $taskToPoint[$taskId] = $i;
}

$gridResults = $batchResult['results'];
dbEnsureConnected();

kwscanProgress($progressFile, ['phase' => 'processing']);


// ============================================================
// ETAPE C — TRAITEMENT DES RESULTATS + CALCUL KPI
// ============================================================

$allPositions = [];
$processedPoints = [];

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
        $matchResult = computeRankForPoint($location, $placesResults);
        if ($matchResult['found']) {
            $gridPosition = $matchResult['rank'];
            $foundName    = $matchResult['matched_name'];
            $matchMethod  = $matchResult['match_method'] ?? null;

            if (!empty($matchResult['backfill'])) {
                applyBackfill($locationId, $matchResult['backfill'], 'live-scan-grid');
                $location = array_merge($location, $matchResult['backfill']);
            }
        }
    }

    $dbPosition = $gridPosition;
    if ($dbPosition === null && !empty($placesResults)) {
        $dbPosition = 101;
    }

    $stmtPt = db()->prepare('
        INSERT INTO grid_points (grid_scan_id, row_index, col_index, latitude, longitude, position, business_name_found, match_method)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmtPt->execute([$scanId, $point['row'], $point['col'], $point['latitude'], $point['longitude'], $dbPosition, $foundName, $matchMethod]);
    $pointDbId = db()->lastInsertId();

    // Sauvegarder tous les concurrents
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
            logAppError("Insert competitor failed: " . $e->getMessage(), 'scan_error', 'warning', [
                'source' => 'live_scan.php', 'action' => 'insert_competitor',
                'location_id' => (int)$locationId, 'keyword_id' => (int)$keywordId,
            ]);
        }
    }

    $allPositions[] = $dbPosition;
    $processedPoints[] = [
        'row'       => $point['row'],
        'col'       => $point['col'],
        'lat'       => $point['latitude'],
        'lng'       => $point['longitude'],
        'position'  => $dbPosition,
        'name'      => $foundName,
        'is_center' => !empty($point['is_center']),
    ];
}

// Calcul KPI
$kpis = computeGridKPIs($allPositions, $totalGridPoints);

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
    foreach ($processedPoints as $pp) {
        if (!empty($pp['is_center']) && $pp['position'] !== null && $pp['position'] <= 100) {
            $position = $pp['position'];
            $inLocalPack = ($position <= 3);
            // Mettre a jour keyword_positions avec la position du centre grille
            try {
                $stmtPosFallback = db()->prepare('
                    INSERT INTO keyword_positions (keyword_id, position, in_local_pack, tracked_at)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE position = VALUES(position), in_local_pack = VALUES(in_local_pack)
                ');
                $stmtPosFallback->execute([$keywordId, $position, $inLocalPack ? 1 : 0, $today]);
            } catch (Exception $e) {
                logAppError("Fallback position save failed kw={$keywordId}: " . $e->getMessage(), 'scan_error', 'warning', [
                    'source' => 'live_scan.php', 'action' => 'save_position_fallback',
                    'location_id' => (int)$locationId, 'keyword_id' => (int)$keywordId,
                ]);
            }
            break;
        }
    }
}

try {
    db()->prepare('UPDATE keywords SET last_grid_scan_at = NOW(), last_manual_scan_at = NOW() WHERE id = ?')->execute([$keywordId]);
} catch (Exception $e) {
    logAppError("Update last_grid_scan_at failed: " . $e->getMessage(), 'scan_error', 'warning', [
        'source' => 'live_scan.php', 'action' => 'update_last_grid_scan',
        'location_id' => (int)$locationId, 'keyword_id' => (int)$keywordId,
    ]);
}

$duration = round(microtime(true) - $startTime, 1);


// ============================================================
// ECRIRE LE RESULTAT FINAL DANS LE FICHIER DE PROGRESSION
// ============================================================

kwscanProgress($progressFile, [
    'status'           => 'completed',
    'completed_at'     => date('Y-m-d H:i:s'),
    'keyword_id'       => (int)$keywordId,
    'keyword'          => $keywordText,
    'position'         => $position,
    'in_local_pack'    => $inLocalPack,
    'grid_scan_id'     => (int)$scanId,
    'top3_count'       => $kpis['top3_count'],
    'top10_count'      => $kpis['top10_count'],
    'top20_count'      => $kpis['top20_count'],
    'out_count'        => $kpis['out_count'],
    'visibility_score' => $kpis['visibility_score'],
    'avg_position'     => $kpis['avg_position'],
    'total_points'     => $totalGridPoints,
    'duration_sec'     => $duration,
]);

exit;


// ============================================================
// FONCTIONS UTILITAIRES
// ============================================================

/**
 * Met a jour le fichier de progression (merge partiel).
 */
function kwscanProgress(string $file, array $updates): void {
    $current = [];
    if (file_exists($file)) {
        $json = file_get_contents($file);
        $current = json_decode($json, true) ?: [];
    }
    $current = array_merge($current, $updates);
    file_put_contents($file, json_encode($current, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}
