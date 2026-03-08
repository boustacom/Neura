<?php
/**
 * BOUS'TACOM — Scan Asynchrone (Positions + Grille) via DataForSEO
 *
 * Actions:
 *   - start:  Lance un scan en arriere-plan via appel HTTP interne
 *   - run:    Execute le scan (appele en interne, token requis — PAS par le navigateur)
 *   - status: Retourne la progression en cours
 *   - cancel: Annule un scan en cours
 *
 * Grille : Local Finder LIVE via curl_multi (48 requetes paralleles, sunburst 49 pts).
 * Position : Google Maps async (1 task + polling).
 * La progression est stockee dans /app/tmp/scan_{location_id}.json
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? null;
$locationId = $_POST['location_id'] ?? $_GET['location_id'] ?? null;

if (!$action || !$locationId) {
    jsonResponse(['error' => 'action et location_id requis'], 400);
}

// L'action "run" utilise un token (appel interne sans session)
// Les autres actions (start, status, cancel) necessitent la session
if ($action !== 'run') {
    startSecureSession();
    requireLogin();
    requireCsrf();
    $user = currentUser();

    // Verifier que la location appartient a l'utilisateur
    $stmt = db()->prepare('
        SELECT l.*, a.user_id
        FROM gbp_locations l
        JOIN gbp_accounts a ON l.gbp_account_id = a.id
        WHERE l.id = ? AND a.user_id = ?
    ');
    $stmt->execute([$locationId, $user['id']]);
    $location = $stmt->fetch();

    // Liberer le lock de session immediatement pour les actions de lecture (status/cancel)
    // CRITIQUE : sinon le polling est bloque par le scan qui tient la session
    if ($action === 'status' || $action === 'cancel') {
        session_write_close();
    }
} else {
    // Action "run" : charger la location directement (authentification via token)
    $stmt = db()->prepare('SELECT l.* FROM gbp_locations l WHERE l.id = ?');
    $stmt->execute([$locationId]);
    $location = $stmt->fetch();
}

if (!$location) {
    jsonResponse(['error' => 'Fiche non trouvee'], 404);
}

// Chemin du fichier de progression
$tmpDir = __DIR__ . '/../tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0755, true);
}
$progressFile = $tmpDir . '/scan_' . $locationId . '.json';

// ============================================
// ROUTAGE DES ACTIONS
// ============================================

switch ($action) {

    // ====== STATUS : Lire la progression ======
    case 'status':
        if (!file_exists($progressFile)) {
            // Aussi supprimer un eventuel token orphelin
            $orphanToken = $tmpDir . '/run_token_' . $locationId . '.txt';
            if (file_exists($orphanToken)) @unlink($orphanToken);
            jsonResponse(['success' => true, 'status' => 'idle', 'message' => 'Aucun scan en cours']);
        }
        $progress = json_decode(file_get_contents($progressFile), true);
        if (!$progress) {
            @unlink($progressFile);
            jsonResponse(['success' => true, 'status' => 'idle', 'message' => 'Fichier de progression invalide — nettoyé']);
        }
        // AUTO-CLEAN : si le scan est en "running" depuis plus de 5 min, il est mort
        if (($progress['status'] ?? '') === 'running') {
            $startedAt = strtotime($progress['started_at'] ?? '');
            if ($startedAt && (time() - $startedAt) > 300) {
                $progress['status'] = 'failed';
                $progress['error'] = 'Scan expiré (plus de 5 min sans réponse). Relancez le scan.';
                @unlink($progressFile);
                // Aussi supprimer le run_token s'il existe encore
                $tokenFile = $tmpDir . '/run_token_' . $locationId . '.txt';
                if (file_exists($tokenFile)) @unlink($tokenFile);
            }
        }
        // AUTO-CLEAN : si le scan est terminé ou annulé, supprimer le fichier après lecture
        if (in_array($progress['status'] ?? '', ['completed', 'cancelled', 'failed'])) {
            @unlink($progressFile);
        }
        jsonResponse(['success' => true] + $progress);
        break;

    // ====== CANCEL : Annuler un scan en cours ======
    case 'cancel':
        if (file_exists($progressFile)) {
            $progress = json_decode(file_get_contents($progressFile), true) ?: [];
            $progress['status'] = 'cancelled';
            $progress['cancelled_at'] = date('Y-m-d H:i:s');
            file_put_contents($progressFile, json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        }
        jsonResponse(['success' => true, 'message' => 'Scan annule']);
        break;

    // ====== START : Lancer le scan en arriere-plan ======
    // Strategie : repondre au navigateur IMMEDIATEMENT, puis executer le scan
    // dans le meme process PHP apres fastcgi_finish_request().
    // Plus de self-HTTP (launchBackgroundHttp) — c'est fragile sur mutualisé.
    case 'start':
        // AUTO-CLEAN : supprimer tout fichier orphelin d'un scan precedent
        if (file_exists($progressFile)) {
            $existing = json_decode(file_get_contents($progressFile), true);

            // Scan "running" → verifier s'il est vraiment actif
            if ($existing && ($existing['status'] ?? '') === 'running') {
                $startedAt = strtotime($existing['started_at'] ?? '');
                $phase = $existing['phase'] ?? '';
                // Scan bloque en "starting" depuis > 30s = mort (le background n'a jamais demarre)
                // Scan qui a progresse (position/grid) = laisser 3 min
                $timeout = ($phase === 'starting' || $phase === '') ? 30 : 180;
                if ($startedAt && (time() - $startedAt) < $timeout) {
                    jsonResponse(['error' => 'Un scan est deja en cours pour cette fiche', 'progress' => $existing], 409);
                }
            }

            // Scan mort/fini/corrompu → supprimer
            @unlink($progressFile);
            $tokenFile = $tmpDir . '/run_token_' . $locationId . '.txt';
            if (file_exists($tokenFile)) @unlink($tokenFile);
        }

        // Nettoyer tokens orphelins
        $tokenFileCheck = $tmpDir . '/run_token_' . $locationId . '.txt';
        if (file_exists($tokenFileCheck)) @unlink($tokenFileCheck);

        // Recuperer les mots-cles a scanner
        // Si keyword_id est fourni → scan un seul mot-cle (mode Localo)
        // Sinon → scan tous les mots-cles actifs
        $singleKeywordId = $_POST['keyword_id'] ?? null;
        if ($singleKeywordId) {
            $stmt = db()->prepare('SELECT * FROM keywords WHERE id = ? AND location_id = ? AND is_active = 1');
            $stmt->execute([$singleKeywordId, $locationId]);
        } else {
            $stmt = db()->prepare('SELECT * FROM keywords WHERE location_id = ? AND is_active = 1 ORDER BY id');
            $stmt->execute([$locationId]);
        }
        $keywords = $stmt->fetchAll();

        if (empty($keywords)) {
            jsonResponse(['error' => 'Aucun mot-cle actif pour cette fiche'], 400);
        }

        // Coordonnees GPS
        $lat = (float)$location['latitude'];
        $lng = (float)$location['longitude'];

        if (!$lat || !$lng) {
            jsonResponse(['error' => 'Coordonnees GPS manquantes. Renseignez-les dans l\'onglet Parametres de la fiche.'], 400);
        }

        // Grille sunburst 49 points (generee par keyword dans runBackgroundScan)
        $totalGridPoints = 49;
        $totalKeywords = count($keywords);

        // Ecrire l'etat initial
        $initialProgress = [
            'status'                => 'running',
            'started_at'            => date('Y-m-d H:i:s'),
            'total_keywords'        => $totalKeywords,
            'current_keyword_index' => 0,
            'current_keyword'       => $keywords[0]['keyword'],
            'phase'                 => 'starting',
            'grid_points_done'      => 0,
            'grid_points_total'     => $totalGridPoints,
            'keywords_done'         => 0,
            'results'               => [],
            'error'                 => null,
            'location_name_updated' => null,
        ];
        file_put_contents($progressFile, json_encode($initialProgress, JSON_UNESCAPED_UNICODE), LOCK_EX);

        // ====== REPONDRE AU NAVIGATEUR IMMEDIATEMENT ======
        // CRITIQUE : fermer la session PHP AVANT le scan pour debloquer les requetes de polling
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Configurer pour execution longue
        ignore_user_abort(true);
        set_time_limit(1800); // 30 min max

        // Envoyer la reponse JSON et fermer la connexion HTTP
        $responseJson = json_encode([
            'success'        => true,
            'message'        => 'Scan lance en arriere-plan',
            'total_keywords' => $totalKeywords,
            'grid_points'    => $totalGridPoints,
        ], JSON_UNESCAPED_UNICODE);

        http_response_code(200);
        header('Content-Type: application/json');
        header('Content-Length: ' . strlen($responseJson));
        header('Connection: close');

        // Vider tous les buffers de sortie
        while (ob_get_level()) ob_end_flush();
        echo $responseJson;
        flush();

        // Couper proprement la connexion avec le navigateur
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // ====== Le navigateur est deconnecte. Executer le scan inline. ======
        runBackgroundScan($locationId, $location, $keywords, $lat, $lng, $progressFile);
        exit;

    // ====== RUN : Execute par l'appel interne (ne pas appeler directement) ======
    case 'run':
        $runToken = $_GET['token'] ?? '';
        $tokenFile = $tmpDir . '/run_token_' . $locationId . '.txt';
        $expectedToken = file_exists($tokenFile) ? trim(file_get_contents($tokenFile)) : '';

        if (!$runToken || $runToken !== $expectedToken) {
            jsonResponse(['error' => 'Token invalide'], 403);
        }

        // Supprimer le token (usage unique)
        @unlink($tokenFile);

        // Configurer pour tourner en arriere-plan
        ignore_user_abort(true);
        set_time_limit(1800); // 30 min max

        // Charger les donnees
        $stmt = db()->prepare('SELECT * FROM keywords WHERE location_id = ? AND is_active = 1 ORDER BY id');
        $stmt->execute([$locationId]);
        $keywords = $stmt->fetchAll();

        $lat = (float)$location['latitude'];
        $lng = (float)$location['longitude'];
        // Repondre rapidement au socket (le caller n'attend pas)
        echo json_encode(['ok' => true]);
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

        // Lancer le scan
        runBackgroundScan($locationId, $location, $keywords, $lat, $lng, $progressFile);
        exit;

    default:
        jsonResponse(['error' => 'Action non reconnue. Actions valides: start, status, cancel'], 400);
}


// ============================================
// FONCTIONS
// ============================================

/**
 * Execute le scan complet en arriere-plan via DataForSEO.
 * Pour chaque mot-cle :
 *   Phase 1 : Position tracking (Maps async, 1 task)
 *   Phase 2 : Grid scan (Local Finder LIVE, curl_multi, 48 requetes paralleles)
 */
function runBackgroundScan(int $locationId, array $location, array $keywords, float $lat, float $lng, string $progressFile): void {

    $totalKeywords = count($keywords);
    $today = date('Y-m-d');
    $allResults = [];
    $locationNameUpdated = null;

    $googleCid = $location['google_cid'] ?? '';
    $placeId = $location['place_id'] ?? '';

    // Boucle sur chaque mot-cle
    foreach ($keywords as $kwIndex => $kw) {

        if (isScanCancelled($progressFile)) return;

        $keywordId = $kw['id'];
        $keywordText = $kw['keyword'];
        $targetCity  = $kw['target_city'] ?? '';

        // Grille sunburst 49 points pour CE mot-cle
        $gridPoints = generateGridPoints49($lat, $lng, $locationId, (int)$keywordId);
        $totalGridPoints = count($gridPoints); // 49

        // ============================================
        // PHASE 1 : POSITION TRACKING (depuis le centre)
        // ============================================

        updateProgress($progressFile, [
            'current_keyword_index' => $kwIndex,
            'current_keyword'       => $keywordText,
            'phase'                 => 'position',
            'grid_points_done'      => 0,
        ]);

        $position = null;
        $inLocalPack = false;

        // 1 task DataForSEO au centre pour la position tracking
        $posResult = dataforseoPostTasks([
            ['keyword' => $keywordText, 'lat' => $lat, 'lng' => $lng, 'tag' => 'pos_center', 'target_city' => $targetCity]
        ], 100);

        if (!$posResult['success']) {
            updateProgress($progressFile, ['status' => 'failed', 'error' => $posResult['error'] ?? 'Erreur DataForSEO']);
            return;
        }

        // Attendre le resultat de la position
        $posResults = dataforseoWaitForResults($posResult['task_ids'], 60);
        dbEnsureConnected(); // Reconnexion MySQL apres le long polling DataForSEO
        $posData = !empty($posResult['task_ids']) ? ($posResults[$posResult['task_ids'][0]] ?? null) : null;
        $posItems = $posData ? ($posData['items'] ?? []) : [];

        if (!empty($posItems)) {
            $matchResult = computeRankForPoint($location, $posItems);
            if ($matchResult['found']) {
                $position    = $matchResult['rank'];
                $inLocalPack = ($matchResult['rank'] !== null && $matchResult['rank'] <= 3);

                if (!empty($matchResult['backfill'])) {
                    applyBackfill($locationId, $matchResult['backfill'], 'scan-async-pos');
                    $location = array_merge($location, $matchResult['backfill']);
                    $googleCid = $location['google_cid'] ?? '';
                    $placeId   = $location['place_id'] ?? '';
                }

                $googleName = trim($matchResult['matched_name'] ?? '');
                if ($googleName && strtolower($googleName) !== strtolower($location['name'])) {
                    try {
                        db()->prepare('UPDATE gbp_locations SET name = ? WHERE id = ?')->execute([$googleName, $locationId]);
                        $locationNameUpdated = $googleName;
                        $location['name'] = $googleName;
                        updateProgress($progressFile, ['location_name_updated' => $googleName]);
                    } catch (Exception $e) {
                        logAppError("Update location name failed: " . $e->getMessage(), 'scan_error', 'warning', ['source' => 'scan-async.php', 'action' => 'update_location_name', 'location_id' => $locationId]);
                    }
                }
            }
        }

        // Sauvegarder la position en base
        try {
            $stmtPos = db()->prepare('
                INSERT INTO keyword_positions (keyword_id, position, in_local_pack, tracked_at)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE position = VALUES(position), in_local_pack = VALUES(in_local_pack)
            ');
            $stmtPos->execute([$keywordId, $position, $inLocalPack ? 1 : 0, $today]);
        } catch (Exception $e) {
            logAppError("Save position failed kw={$keywordId}: " . $e->getMessage(), 'scan_error', 'error', ['source' => 'scan-async.php', 'action' => 'save_position', 'location_id' => $locationId, 'keyword_id' => (int)$keywordId]);
        }

        // ============================================
        // PHASE 2 : GRID SCAN (batch DataForSEO)
        // ============================================

        if (isScanCancelled($progressFile)) return;

        updateProgress($progressFile, ['phase' => 'grid', 'grid_points_done' => 0]);

        // Rayon max grille 7x7 = 15 km
        $radiusKmTotal = 15.0;

        $stmtScan = db()->prepare('
            INSERT INTO grid_scans (keyword_id, grid_size, radius_km, grid_type, num_rings, total_points, scanned_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmtScan->execute([$keywordId, 49, $radiusKmTotal, 'sunburst', 0, $totalGridPoints]);
        $scanId = db()->lastInsertId();

        // Construire le batch de tasks : 1 task par point de grille
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
        updateProgress($progressFile, ['phase' => 'grid', 'grid_points_done' => 0]);
        $batchResult = dataforseoLocalFinderLive($batchTasks, 100);

        if (!$batchResult['success']) {
            cleanupIncompleteGridScan($scanId);
            updateProgress($progressFile, ['status' => 'failed', 'error' => $batchResult['error'] ?? 'Erreur DataForSEO batch']);
            return;
        }

        // Mapper task_id → point index
        $taskToPoint = [];
        foreach ($batchResult['task_ids'] as $i => $taskId) {
            $taskToPoint[$taskId] = $i;
        }

        // Resultats deja disponibles (mode LIVE = synchrone)
        $gridResults = $batchResult['results'];
        updateProgress($progressFile, ['phase' => 'grid', 'grid_points_done' => count($gridResults)]);
        dbEnsureConnected();

        if (isScanCancelled($progressFile)) {
            cleanupIncompleteGridScan($scanId);
            return;
        }

        // Traiter les resultats point par point
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
            $matchResult = null;

            if (!empty($placesResults)) {
                $matchResult = computeRankForPoint($location, $placesResults);
                if ($matchResult['found']) {
                    $gridPosition = $matchResult['rank'];
                    $foundName    = $matchResult['matched_name'];
                    if (!empty($matchResult['backfill'])) {
                        applyBackfill($locationId, $matchResult['backfill'], 'scan-async-grid');
                        $location = array_merge($location, $matchResult['backfill']);
                        $googleCid = $location['google_cid'] ?? '';
                    }
                }
            }

            $dbPosition = $gridPosition;
            if ($dbPosition === null && !empty($placesResults)) {
                $dbPosition = 101;
            }

            // Sauvegarder le point
            $matchMethod = $matchResult ? ($matchResult['match_method'] ?? null) : null;
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
                    logAppError("Insert competitor failed: " . $e->getMessage(), 'scan_error', 'warning', ['source' => 'scan-async.php', 'action' => 'insert_competitor', 'location_id' => $locationId, 'keyword_id' => (int)$keywordId]);
                }
            }

            $allPositions[] = $dbPosition;
            $processedPoints[] = ['row' => $point['row'], 'col' => $point['col'], 'lat' => $point['latitude'], 'lng' => $point['longitude'], 'position' => $dbPosition, 'name' => $foundName, 'is_center' => $point['is_center']];
        }

        updateProgress($progressFile, ['grid_points_done' => $totalGridPoints]);

        // Calcul KPI avec la nouvelle formule (Top3/Total x 100)
        $kpis = computeGridKPIs($allPositions, $totalGridPoints);

        db()->prepare('UPDATE grid_scans SET avg_position = ?, visibility_score = ?, top3_count = ?, top10_count = ?, top20_count = ?, out_count = ? WHERE id = ?')
            ->execute([$kpis['avg_position'], $kpis['visibility_score'], $kpis['top3_count'], $kpis['top10_count'], $kpis['top20_count'], $kpis['out_count'], $scanId]);

        try {
            db()->prepare('UPDATE keywords SET last_grid_scan_at = NOW() WHERE id = ?')->execute([$keywordId]);
        } catch (Exception $e) {
            logAppError("Update last_grid_scan_at failed: " . $e->getMessage(), 'scan_error', 'warning', ['source' => 'scan-async.php', 'action' => 'update_last_grid_scan', 'location_id' => $locationId, 'keyword_id' => (int)$keywordId]);
        }

        $allResults[] = ['keyword' => $keywordText, 'position' => $position, 'visibility' => $kpis['visibility_score'], 'scan_id' => $scanId];
        updateProgress($progressFile, ['keywords_done' => $kwIndex + 1, 'results' => $allResults]);
    }

    updateProgress($progressFile, ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s'), 'results' => $allResults]);
}


/**
 * Met a jour le fichier de progression (merge partiel).
 * Thread-safe avec LOCK_EX.
 */
function updateProgress(string $file, array $updates): void {
    $current = [];
    if (file_exists($file)) {
        $json = file_get_contents($file);
        $current = json_decode($json, true) ?: [];
    }
    $current = array_merge($current, $updates);
    file_put_contents($file, json_encode($current, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}


/**
 * Verifie si le scan a ete annule.
 */
function isScanCancelled(string $file): bool {
    if (!file_exists($file)) return true;
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    return ($data['status'] ?? '') === 'cancelled';
}


/**
 * Verifie si c'est une erreur API temporaire (a retrier).
 */
function isTemporaryApiError(?array $response): bool {
    if ($response === null) return true;
    if (isset($response['_api_error']) && !in_array($response['_http_code'] ?? 0, [401, 402, 403])) return true;
    return false;
}


/**
 * Supprime un scan de grille incomplet (en cas d'annulation ou d'erreur).
 */
function cleanupIncompleteGridScan(int $scanId): void {
    try {
        db()->prepare('DELETE FROM grid_competitors WHERE grid_scan_id = ?')->execute([$scanId]);
        db()->prepare('DELETE FROM grid_points WHERE grid_scan_id = ?')->execute([$scanId]);
        db()->prepare('DELETE FROM grid_scans WHERE id = ?')->execute([$scanId]);
    } catch (Exception $e) {
        logAppError("Cleanup scan #{$scanId} failed: " . $e->getMessage(), 'scan_error', 'warning', ['source' => 'scan-async.php', 'action' => 'cleanup_scan']);
    }
}


