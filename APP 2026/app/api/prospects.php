<?php
/**
 * BOUS'TACOM — API Audit & Acquisition de clients
 *
 * Actions:
 *   - search: Recherche Google Maps via DataForSEO (keyword + city)
 *   - save_prospect: Sauvegarder un prospect dans la table audits
 *   - list_prospects: Lister les prospects sauvegardes
 *   - delete_prospect: Supprimer un prospect
 *   - get_credits: Retourner les credits restants
 *   - scan_grid: Lancer un scan grille prospect (49 points)
 *   - scan_status: Polling du statut d'un scan grille
 *   - run_audit: Calculer le score d'audit complet
 *   - get_audit: Recuperer les donnees d'audit completes
 *   - generate_pdf: Generer le PDF d'audit
 *   - send_audit: Envoyer l'audit par email
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? null;
if (!$action) {
    jsonResponse(['error' => 'Action requise'], 400);
}

// scan_status = polling GET, pas de CSRF
if ($action === 'scan_status') {
    startSecureSession();
    requireLogin();
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $auditId = $_GET['audit_id'] ?? null;
    if (!$auditId) jsonResponse(['error' => 'audit_id requis'], 400);

    $tmpDir = __DIR__ . '/../tmp';
    $progressFile = $tmpDir . '/prospect_scan_' . $auditId . '.json';

    if (!file_exists($progressFile)) {
        jsonResponse(['success' => true, 'status' => 'idle']);
    }

    $progress = json_decode(file_get_contents($progressFile), true);
    if (!$progress) {
        @unlink($progressFile);
        jsonResponse(['success' => true, 'status' => 'idle']);
    }

    // Auto-clean: scan running > 5 min = mort
    if (($progress['status'] ?? '') === 'running') {
        $startedAt = strtotime($progress['started_at'] ?? '');
        if ($startedAt && (time() - $startedAt) > 300) {
            $progress['status'] = 'failed';
            $progress['error'] = 'Scan expire (plus de 5 min). Relancez le scan.';
            @unlink($progressFile);
        }
    }

    // Auto-clean: termine/echoue → supprimer apres lecture
    if (in_array($progress['status'] ?? '', ['completed', 'failed'])) {
        @unlink($progressFile);
    }

    jsonResponse(['success' => true] + $progress);
}

// Toutes les autres actions necessitent CSRF
startSecureSession();
requireLogin();
requireCsrf();

$user = currentUser();

switch ($action) {

    // ====== RECHERCHE DE PROSPECTS ======
    case 'search':
        $keyword = trim($_POST['keyword'] ?? '');
        $city = trim($_POST['city'] ?? '');

        if (!$keyword || !$city) {
            jsonResponse(['error' => 'Mot-clé et ville requis'], 400);
        }

        // Vérifier les crédits
        $credits = getUserCredits($user['id']);
        if ($credits < 1) {
            jsonResponse(['error' => 'Crédits insuffisants. Vous avez ' . $credits . ' crédit(s) restant(s). Les crédits se réinitialisent chaque mois.'], 403);
        }

        // Coordonnées GPS de la ville (fournies par l'autocomplete geo.api.gouv.fr)
        $lat = (float)($_POST['lat'] ?? 0);
        $lng = (float)($_POST['lng'] ?? 0);
        if (!$lat || !$lng) {
            jsonResponse(['error' => 'Coordonnées GPS manquantes. Sélectionnez une ville dans la liste.'], 400);
        }
        $locationCoord = sprintf('%.7f,%.7f,13', $lat, $lng);

        // Appel DataForSEO — Google Maps SERP Live avec location_coordinate
        $liveResponse = dataforseoRequest('POST', '/serp/google/maps/live/advanced', [[
            'keyword'             => $keyword . ' ' . $city,
            'location_coordinate' => $locationCoord,
            'language_code'       => 'fr',
            'depth'               => 100,
            'device'              => 'mobile',
            'os'                  => 'android',
        ]]);

        if (isset($liveResponse['_api_error'])) {
            jsonResponse(['error' => 'Erreur DataForSEO: ' . $liveResponse['_api_error']], 500);
        }

        // Verifier le status de la task live
        $liveTask = $liveResponse['tasks'][0] ?? null;
        $liveTaskStatus = $liveTask['status_code'] ?? 0;
        if ($liveTaskStatus !== 20000) {
            $errMsg = $liveTask['status_message'] ?? 'Erreur inconnue';
            if ($liveTaskStatus === 40200) {
                $errMsg = 'Crédits DataForSEO épuisés. Rechargez sur app.dataforseo.com.';
            }
            jsonResponse(['error' => $errMsg], 500);
        }

        // Extraire et normaliser les items
        $liveResult = $liveTask['result'][0] ?? null;
        $rawItems = $liveResult['items'] ?? [];
        $items = [];
        foreach ($rawItems as $idx => $rawItem) {
            $itemType = $rawItem['type'] ?? '';
            if ($itemType === 'maps_paid_item') continue;
            if ($itemType !== 'maps_search' && $itemType !== 'local_pack') continue;
            $items[] = normalizeDataforseoItem($rawItem, $idx);
        }

        if (empty($items)) {
            jsonResponse(['error' => 'Aucun résultat trouvé pour cette recherche.'], 404);
        }

        $results = [];

        foreach ($items as $idx => $item) {
            if (empty($item['title'])) continue;

            $position = $item['position'] ?? ($idx + 1);

            // Extraire les donnees normalisees
            $ratingValue = (float)($item['rating'] ?? 0);
            $reviewsCount = (int)($item['reviews'] ?? 0);

            // Extraire le domaine depuis l'URL du site web
            $itemWebsite = $item['link'] ?? $item['website'] ?? '';
            $itemDomain = '';
            if ($itemWebsite) {
                $parsedHost = parse_url($itemWebsite, PHP_URL_HOST);
                if ($parsedHost) $itemDomain = str_replace('www.', '', strtolower($parsedHost));
            }

            $itemData = [
                'position' => $position,
                'business_name' => $item['title'] ?? '',
                'address' => $item['address'] ?? '',
                'city' => $city,
                'search_keyword' => $keyword, // mot-cle original (ex: "plombier")
                'phone' => $item['phone'] ?? '',
                'domain' => $itemDomain,
                'url' => $itemWebsite,
                'rating' => $ratingValue,
                'reviews_count' => $reviewsCount,
                'category' => $item['category'] ?? $item['type'] ?? '',
                'latitude' => $item['latitude'] ?? null,
                'longitude' => $item['longitude'] ?? null,
                'place_id' => $item['place_id'] ?? null,
                'data_cid' => $item['data_cid'] ?? null,
                'total_photos' => $item['total_photos'] ?? null,
            ];

            // Calculer le score de visibilité
            $itemData['score'] = calculateVisibilityScore($itemData, $position);

            $results[] = $itemData;
        }

        // Déduire 1 crédit
        deductCredits($user['id'], 1);
        $creditsRemaining = getUserCredits($user['id']);

        // Logger la recherche
        $stmt = db()->prepare('
            INSERT INTO prospect_searches (user_id, keyword, city, results_count, credits_used, searched_at)
            VALUES (?, ?, ?, ?, 1, NOW())
        ');
        $stmt->execute([$user['id'], $keyword, $city, count($results)]);

        jsonResponse([
            'success' => true,
            'results' => $results,
            'credits_remaining' => $creditsRemaining,
            'results_count' => count($results)
        ]);
        break;

    // ====== SAUVEGARDER UN PROSPECT ======
    case 'save_prospect':
        $businessName = trim($_POST['business_name'] ?? '');
        if (!$businessName) {
            jsonResponse(['error' => 'Nom de l\'entreprise requis'], 400);
        }

        // Verifier si le prospect existe deja (meme user + meme nom + meme ville)
        $checkStmt = db()->prepare('
            SELECT id FROM audits WHERE user_id = ? AND business_name = ? AND city = ? LIMIT 1
        ');
        $checkStmt->execute([$user['id'], $businessName, $_POST['city'] ?? '']);
        if ($checkStmt->fetch()) {
            jsonResponse(['error' => 'Ce prospect existe deja dans votre liste.'], 409);
        }

        $auditData = json_encode([
            'position' => (int)($_POST['position'] ?? 0),
            'rating' => (float)($_POST['rating'] ?? 0),
            'reviews_count' => (int)($_POST['reviews_count'] ?? 0),
            'website' => $_POST['url'] ?? '',
            'phone' => $_POST['phone'] ?? '',
        ]);

        $stmt = db()->prepare('
            INSERT INTO audits (
                user_id, business_name, city, search_keyword, address, category,
                latitude, longitude, place_id, data_cid, domain,
                rating, reviews_count, position, total_photos,
                prospect_phone, score, audit_status, audit_data, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $user['id'],
            $businessName,
            $_POST['city'] ?? '',
            $_POST['search_keyword'] ?? '',
            $_POST['address'] ?? '',
            $_POST['category'] ?? '',
            $_POST['latitude'] ? (float)$_POST['latitude'] : null,
            $_POST['longitude'] ? (float)$_POST['longitude'] : null,
            $_POST['place_id'] ?? null,
            $_POST['data_cid'] ?? null,
            $_POST['domain'] ?? '',
            $_POST['rating'] ? (float)$_POST['rating'] : null,
            (int)($_POST['reviews_count'] ?? 0),
            $_POST['position'] ? (int)$_POST['position'] : null,
            $_POST['total_photos'] ? (int)$_POST['total_photos'] : null,
            $_POST['phone'] ?? '',
            (int)($_POST['score'] ?? 0),
            'search_only',
            $auditData
        ]);

        jsonResponse(['success' => true, 'id' => db()->lastInsertId()]);
        break;

    // ====== LISTER LES PROSPECTS ======
    case 'list_prospects':
        $period = $_GET['period'] ?? 'all';
        $dateFilter = '';
        if ($period === '90d') {
            $dateFilter = ' AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)';
        } elseif ($period === '6m') {
            $dateFilter = ' AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)';
        }
        $stmt = db()->prepare('
            SELECT id, business_name, city, search_keyword, address, category,
                   latitude, longitude, place_id, data_cid, domain,
                   rating, reviews_count, position, total_photos,
                   description, social_facebook, social_instagram, unanswered_reviews,
                   prospect_phone, prospect_email, score, audit_status,
                   grid_visibility, grid_avg_position, grid_top3, grid_top10, grid_top20,
                   audit_data, sent_at, created_at
            FROM audits
            WHERE user_id = ?' . $dateFilter . '
            ORDER BY created_at DESC
            LIMIT 200
        ');
        $stmt->execute([$user['id']]);
        $prospects = $stmt->fetchAll();

        jsonResponse(['success' => true, 'prospects' => $prospects]);
        break;

    // ====== SUPPRIMER UN PROSPECT ======
    case 'delete_prospect':
        $prospectId = $_POST['prospect_id'] ?? null;
        if (!$prospectId) {
            jsonResponse(['error' => 'prospect_id requis'], 400);
        }

        $stmt = db()->prepare('DELETE FROM audits WHERE id = ? AND user_id = ?');
        $stmt->execute([$prospectId, $user['id']]);

        if ($stmt->rowCount() > 0) {
            jsonResponse(['success' => true]);
        } else {
            jsonResponse(['error' => 'Prospect non trouve'], 404);
        }
        break;

    // ====== OBTENIR LES CREDITS ======
    case 'get_credits':
        jsonResponse([
            'success' => true,
            'credits' => getUserCredits($user['id'])
        ]);
        break;

    // ====== SCAN GRILLE PROSPECT (background) ======
    case 'scan_grid':
        $auditId = $_POST['audit_id'] ?? null;
        if (!$auditId) jsonResponse(['error' => 'audit_id requis'], 400);

        // Charger le prospect
        $stmt = db()->prepare('SELECT * FROM audits WHERE id = ? AND user_id = ?');
        $stmt->execute([$auditId, $user['id']]);
        $audit = $stmt->fetch();
        if (!$audit) jsonResponse(['error' => 'Prospect non trouve'], 404);

        $lat = (float)($audit['latitude'] ?? 0);
        $lng = (float)($audit['longitude'] ?? 0);
        if (!$lat || !$lng) {
            jsonResponse(['error' => 'Coordonnees GPS manquantes pour ce prospect. Impossible de lancer le scan.'], 400);
        }

        // Verifier credits (2 par scan grille)
        $credits = getUserCredits($user['id']);
        if ($credits < 2) {
            jsonResponse(['error' => 'Credits insuffisants (2 requis). Vous avez ' . $credits . ' credit(s).'], 403);
        }

        // Fichier de progression
        $tmpDir = __DIR__ . '/../tmp';
        if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);
        $progressFile = $tmpDir . '/prospect_scan_' . $auditId . '.json';

        // Verifier si un scan est deja en cours
        if (file_exists($progressFile)) {
            $existing = json_decode(file_get_contents($progressFile), true);
            if ($existing && ($existing['status'] ?? '') === 'running') {
                $startedAt = strtotime($existing['started_at'] ?? '');
                if ($startedAt && (time() - $startedAt) < 300) {
                    jsonResponse(['error' => 'Un scan est deja en cours pour ce prospect', 'status' => 'running'], 409);
                }
            }
            @unlink($progressFile);
        }

        // Ecrire l'etat initial
        file_put_contents($progressFile, json_encode([
            'status'     => 'running',
            'started_at' => date('Y-m-d H:i:s'),
            'audit_id'   => (int)$auditId,
            'business'   => $audit['business_name'],
            'phase'      => 'starting',
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE), LOCK_EX);

        // Deduire 2 credits AVANT le scan
        deductCredits($user['id'], 2);

        // ====== REPONDRE AU NAVIGATEUR IMMEDIATEMENT ======
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        ignore_user_abort(true);
        set_time_limit(600);

        $responseJson = json_encode([
            'success' => true,
            'status'  => 'started',
            'message' => 'Scan grille lance en arriere-plan',
            'credits_remaining' => getUserCredits($user['id']),
        ], JSON_UNESCAPED_UNICODE);

        http_response_code(200);
        header('Content-Type: application/json');
        header('Content-Length: ' . strlen($responseJson));
        header('Connection: close');

        while (ob_get_level()) ob_end_flush();
        echo $responseJson;
        flush();

        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

        // ====== EXECUTION EN ARRIERE-PLAN ======
        $startTime = microtime(true);

        // Helper pour update progress
        $updateProgress = function(array $data) use ($progressFile) {
            $current = [];
            if (file_exists($progressFile)) {
                $current = json_decode(file_get_contents($progressFile), true) ?: [];
            }
            $current = array_merge($current, $data);
            file_put_contents($progressFile, json_encode($current, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        };

        try {
            // Phase 1 : Generer les points de grille
            $updateProgress(['phase' => 'grid']);
            $gridPoints = generateGridPoints49($lat, $lng, 0, 0);
            $totalGridPoints = count($gridPoints);
            $radiusKm = 15.0;

            // Utiliser le mot-cle de recherche original (ex: "plombier"), PAS le nom du business
            // Keyword et ville SEPARES (identique live_scan.php) — fusion geree par dataforseoLocalFinderLive()
            $keyword = $audit['search_keyword'] ?: $audit['category'] ?: $audit['business_name'];
            $targetCity = $audit['city'] ?? '';

            // Keyword complet pour affichage en DB
            $keywordForDb = $keyword;
            if ($targetCity) {
                $cityParts = explode(',', $targetCity);
                $keywordForDb .= ' ' . trim($cityParts[0]);
            }

            // Creer le scan en DB
            $stmtScan = db()->prepare('
                INSERT INTO prospect_grid_scans (audit_id, user_id, keyword, center_lat, center_lng, total_points, radius_km, status, started_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ');
            $stmtScan->execute([$auditId, $user['id'], $keywordForDb, $lat, $lng, $totalGridPoints, $radiusKm, 'running']);
            $scanId = db()->lastInsertId();

            // Preparer les taches DataForSEO
            $batchTasks = [];
            foreach ($gridPoints as $pointIdx => $point) {
                $batchTasks[] = [
                    'keyword'     => $keyword,
                    'lat'         => $point['latitude'],
                    'lng'         => $point['longitude'],
                    'tag'         => "pgrid_{$scanId}_{$pointIdx}_{$point['row']}_{$point['col']}",
                    'target_city' => $targetCity,
                ];
            }

            // Phase 2 : LOCAL_FINDER LIVE via curl_multi (identique live_scan.php)
            $updateProgress(['phase' => 'scanning', 'scan_id' => (int)$scanId]);
            $batchResult = dataforseoLocalFinderLive($batchTasks, 100);

            if (!$batchResult['success']) {
                db()->prepare('UPDATE prospect_grid_scans SET status = ?, error = ? WHERE id = ?')
                    ->execute(['failed', $batchResult['error'] ?? 'Erreur DataForSEO', $scanId]);
                $updateProgress(['status' => 'failed', 'error' => 'Erreur DataForSEO (grille) : ' . ($batchResult['error'] ?? 'inconnu')]);
                exit;
            }

            $taskToPoint = [];
            foreach ($batchResult['task_ids'] as $i => $taskId) {
                $taskToPoint[$taskId] = $i;
            }

            dbEnsureConnected();
            $updateProgress(['phase' => 'processing']);

            // Phase 3 : Traiter les résultats (MEME METHODE que live_scan.php)
            // Construire un pseudo-locationRow pour computeRankForPoint()
            $pseudoLocation = [
                'place_id'   => $audit['place_id'] ?? '',
                'google_cid' => $audit['data_cid'] ?? '',
                'name'       => $audit['business_name'],
            ];

            // ---- Phase 3a : Résoudre les identifiants via le point CENTRE ----
            // Le centre (row=3, col=3) est traité EN PREMIER pour découvrir place_id/CID si manquants.
            // Le fuzzy n'est utilisé QU'AU CENTRE et seulement si aucun identifiant fiable.
            $centerTaskId = null;
            $centerPointIdx = null;
            foreach ($batchResult['task_ids'] as $taskId) {
                $pidx = $taskToPoint[$taskId] ?? null;
                if ($pidx === null) continue;
                if (!empty($gridPoints[$pidx]['is_center'])) {
                    $centerTaskId = $taskId;
                    $centerPointIdx = $pidx;
                    break;
                }
            }

            if ($centerTaskId !== null) {
                $centerResult = $batchResult['results'][$centerTaskId] ?? null;
                $centerItems = $centerResult ? ($centerResult['items'] ?? []) : [];

                if (!empty($centerItems)) {
                    // D'abord matching STRICT (place_id + CID) au centre
                    $matchResult = computeRankForPoint($pseudoLocation, $centerItems, false);
                    // Si strict échoue, fallback FUZZY au centre uniquement
                    // pour découvrir les bons identifiants local_finder
                    // (maps et local_finder n'utilisent pas toujours les mêmes place_id/cid)
                    if (!$matchResult['found']) {
                        $matchResult = computeRankForPoint($pseudoLocation, $centerItems, true);
                    }

                    if ($matchResult['found'] && !empty($matchResult['backfill'])) {
                        $updates = [];
                        $params = [];
                        if (!empty($matchResult['backfill']['place_id']) && empty($pseudoLocation['place_id'])) {
                            $updates[] = 'place_id = ?';
                            $params[] = $matchResult['backfill']['place_id'];
                            $pseudoLocation['place_id'] = $matchResult['backfill']['place_id'];
                        }
                        if (!empty($matchResult['backfill']['google_cid']) && empty($pseudoLocation['google_cid'])) {
                            $updates[] = 'data_cid = ?';
                            $params[] = $matchResult['backfill']['google_cid'];
                            $pseudoLocation['google_cid'] = $matchResult['backfill']['google_cid'];
                        }
                        if ($updates) {
                            $params[] = $auditId;
                            db()->prepare('UPDATE audits SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
                        }
                    }
                }
            }

            // ---- Phase 3b : Traiter TOUS les 49 points avec matching STRICT ----
            // Identique à live_scan.php : PAS de fuzzy, seulement place_id + CID
            $allPositions = [];

            foreach ($batchResult['task_ids'] as $taskId) {
                $pointIdx = $taskToPoint[$taskId] ?? null;
                if ($pointIdx === null) continue;

                $point = $gridPoints[$pointIdx];
                $taskResult = $batchResult['results'][$taskId] ?? null;
                $placesResults = $taskResult ? ($taskResult['items'] ?? []) : [];

                $gridPosition = null;
                $foundName = null;

                if (!empty($placesResults)) {
                    // Matching STRICT (pas de fuzzy) — identique au scan client
                    $matchResult = computeRankForPoint($pseudoLocation, $placesResults, false);
                    if ($matchResult['found']) {
                        $gridPosition = $matchResult['rank'];
                        $foundName = $matchResult['matched_name'];

                        // Enrichir les identifiants en mémoire si découverts
                        if (!empty($matchResult['backfill'])) {
                            if (!empty($matchResult['backfill']['place_id']) && empty($pseudoLocation['place_id'])) {
                                $pseudoLocation['place_id'] = $matchResult['backfill']['place_id'];
                            }
                            if (!empty($matchResult['backfill']['google_cid']) && empty($pseudoLocation['google_cid'])) {
                                $pseudoLocation['google_cid'] = $matchResult['backfill']['google_cid'];
                            }
                        }
                    }
                }

                $dbPosition = $gridPosition;
                if ($dbPosition === null && !empty($placesResults)) {
                    $dbPosition = 101; // Présent dans les résultats mais pas trouvé
                }

                // Stocker le point
                db()->prepare('
                    INSERT INTO prospect_grid_points (scan_id, row_index, col_index, latitude, longitude, position, business_name_found)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ')->execute([$scanId, $point['row'], $point['col'], $point['latitude'], $point['longitude'], $dbPosition, $foundName]);
                $pointDbId = db()->lastInsertId();

                // Sauvegarder tous les concurrents a ce point (identique live_scan.php)
                foreach ($placesResults as $item) {
                    $isTarget = determineIsTarget($item, $pseudoLocation);
                    try {
                        db()->prepare('
                            INSERT INTO prospect_grid_competitors
                            (scan_id, point_id, position, title, address, phone, website, category, rating, reviews_count, place_id, data_cid, is_target)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ')->execute([
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
                    } catch (Exception $compEx) {
                        error_log("prospects.php: insert competitor failed: " . $compEx->getMessage());
                    }
                }

                $allPositions[] = $dbPosition;
            }

            // Phase 4 : Calcul KPI
            $kpis = computeGridKPIs($allPositions, $totalGridPoints);

            // Mettre a jour le scan
            db()->prepare('
                UPDATE prospect_grid_scans
                SET avg_position = ?, visibility_score = ?, top3_count = ?, top10_count = ?, top20_count = ?, out_count = ?, status = ?, completed_at = NOW()
                WHERE id = ?
            ')->execute([
                $kpis['avg_position'], $kpis['visibility_score'],
                $kpis['top3_count'], $kpis['top10_count'], $kpis['top20_count'], $kpis['out_count'],
                'completed', $scanId,
            ]);

            // Mettre a jour le prospect (audits)
            db()->prepare('
                UPDATE audits
                SET grid_scan_id = ?, grid_visibility = ?, grid_avg_position = ?, grid_top3 = ?, grid_top10 = ?, grid_top20 = ?,
                    audit_status = CASE WHEN audit_status = \'search_only\' THEN \'scanned\' ELSE audit_status END
                WHERE id = ?
            ')->execute([
                $scanId, $kpis['visibility_score'], $kpis['avg_position'],
                $kpis['top3_count'], $kpis['top10_count'], $kpis['top20_count'],
                $auditId,
            ]);

            $duration = round(microtime(true) - $startTime, 1);

            $updateProgress([
                'status'           => 'completed',
                'completed_at'     => date('Y-m-d H:i:s'),
                'scan_id'          => (int)$scanId,
                'visibility_score' => $kpis['visibility_score'],
                'avg_position'     => $kpis['avg_position'],
                'top3_count'       => $kpis['top3_count'],
                'top10_count'      => $kpis['top10_count'],
                'top20_count'      => $kpis['top20_count'],
                'out_count'        => $kpis['out_count'],
                'total_points'     => $totalGridPoints,
                'duration_sec'     => $duration,
            ]);

        } catch (Throwable $e) {
            try {
                if (isset($scanId)) {
                    db()->prepare('UPDATE prospect_grid_scans SET status = ?, error = ? WHERE id = ?')
                        ->execute(['failed', $e->getMessage(), $scanId]);
                }
            } catch (Exception $ignored) {}

            $updateProgress([
                'status' => 'failed',
                'error'  => 'Erreur interne : ' . $e->getMessage(),
            ]);
        }

        exit;

    // ====== LANCER L'AUDIT COMPLET ======
    case 'run_audit':
        $auditId = $_POST['audit_id'] ?? null;
        if (!$auditId) jsonResponse(['error' => 'audit_id requis'], 400);

        $stmt = db()->prepare('SELECT * FROM audits WHERE id = ? AND user_id = ?');
        $stmt->execute([$auditId, $user['id']]);
        $audit = $stmt->fetch();
        if (!$audit) jsonResponse(['error' => 'Prospect non trouve'], 404);

        $placeId = $audit['place_id'] ?? '';
        $businessName = $audit['business_name'] ?? '';
        $businessInfoUpdates = [];
        $updateFields = [];
        $updateValues = [];

        // === ENRICHISSEMENT PHASE 0 : Google Places API (New) ===
        if ($placeId) {
            $placesData = googlePlacesDetailsFetch($placeId, 'extended');
            if ($placesData) {
                // Horaires
                if (!empty($placesData['regularOpeningHours'])) {
                    $updateFields[] = 'has_hours = 1';
                    $audit['has_hours'] = 1;
                }
                // Photos (Places API retourne souvent plus que MyBiz)
                $placesPhotos = count($placesData['photos'] ?? []);
                if ($placesPhotos > 0 && empty($audit['total_photos'])) {
                    $updateFields[] = 'total_photos = ?';
                    $updateValues[] = $placesPhotos;
                    $audit['total_photos'] = $placesPhotos;
                }
                // Description
                if (!empty($placesData['editorialSummary']['text']) && empty($audit['description'])) {
                    $updateFields[] = 'description = ?';
                    $updateValues[] = $placesData['editorialSummary']['text'];
                    $audit['description'] = $placesData['editorialSummary']['text'];
                }
                // Attributs
                $attrCount = 0;
                foreach (['accessibilityOptions','outdoorSeating','takeout','delivery','dineIn','reservable','liveMusic','servesBreakfast','servesLunch','servesDinner','allowsDogs','restroom','goodForChildren','goodForGroups'] as $attr) {
                    if (!empty($placesData[$attr]) && $placesData[$attr] === true) $attrCount++;
                }
                if (!empty($placesData['accessibilityOptions']) && is_array($placesData['accessibilityOptions'])) {
                    $attrCount += count(array_filter($placesData['accessibilityOptions']));
                }
                $updateFields[] = 'attributes_count = ?';
                $updateValues[] = $attrCount;
                $audit['attributes_count'] = $attrCount;
                // Statut business
                $bStatus = $placesData['businessStatus'] ?? 'OPERATIONAL';
                $updateFields[] = 'business_status = ?';
                $updateValues[] = $bStatus;
                $audit['business_status'] = $bStatus;
                // Stocker les données Places dans le JSON d'audit
                $businessInfoUpdates['places_api'] = [
                    'has_hours' => !empty($placesData['regularOpeningHours']),
                    'photos_count' => $placesPhotos,
                    'attributes_count' => $attrCount,
                    'business_status' => $bStatus,
                    'rating' => $placesData['rating'] ?? null,
                    'reviews_count' => $placesData['userRatingCount'] ?? 0,
                ];
            }
        }

        // === ENRICHISSEMENT PHASE 1 : My Business Info (description, photos) ===

        if ($placeId) {
            $bizInfo = dataforseoMyBusinessInfo($placeId, $businessName);
            if ($bizInfo) {
                if (!empty($bizInfo['description']) && empty($audit['description'])) {
                    $updateFields[] = 'description = ?';
                    $updateValues[] = $bizInfo['description'];
                    $audit['description'] = $bizInfo['description'];
                }
                // Log si aucune description trouvée nulle part
                if (empty($audit['description'])) {
                    error_log("[RunAudit] Aucune description pour audit #{$auditId} ({$businessName}). Places editorialSummary=" . (!empty($placesData['editorialSummary']['text']) ? 'oui' : 'non') . ", DataForSEO description=" . (!empty($bizInfo['description']) ? 'oui' : 'non'));
                }
                if (!empty($bizInfo['total_photos']) && empty($audit['total_photos'])) {
                    $updateFields[] = 'total_photos = ?';
                    $updateValues[] = (int)$bizInfo['total_photos'];
                    $audit['total_photos'] = (int)$bizInfo['total_photos'];
                }
                $businessInfoUpdates = $bizInfo;
            }
        }

        // === ENRICHISSEMENT PHASE 2 : Scraping du site web ===
        // Recupere reseaux sociaux + emails de contact (gratuit)
        $domain = $audit['domain'] ?? '';
        if ($domain) {
            $websiteInfo = scrapeWebsiteInfo($domain);

            // Reseaux sociaux
            if (empty($audit['social_facebook']) && !empty($websiteInfo['facebook'])) {
                $updateFields[] = 'social_facebook = ?';
                $updateValues[] = $websiteInfo['facebook'];
                $audit['social_facebook'] = $websiteInfo['facebook'];
            }
            if (empty($audit['social_instagram']) && !empty($websiteInfo['instagram'])) {
                $updateFields[] = 'social_instagram = ?';
                $updateValues[] = $websiteInfo['instagram'];
                $audit['social_instagram'] = $websiteInfo['instagram'];
            }

            // Email de contact — auto-remplir si pas déjà renseigné
            if (empty($audit['prospect_email']) && !empty($websiteInfo['emails'])) {
                $updateFields[] = 'prospect_email = ?';
                $updateValues[] = $websiteInfo['emails'][0]; // meilleur email (contact@ en priorite)
                $audit['prospect_email'] = $websiteInfo['emails'][0];
            }

            $businessInfoUpdates['website_scrape'] = $websiteInfo;
        }

        // === ENRICHISSEMENT PHASE 3 : Avis sans reponse ===
        $reviewsData = null;
        if ($placeId) {
            $reviewsData = dataforseoReviewsAnalysis($placeId);
            if ($reviewsData && $reviewsData['total_reviews'] > 0) {
                $updateFields[] = 'unanswered_reviews = ?';
                $updateValues[] = $reviewsData['unanswered'];
                $audit['unanswered_reviews'] = $reviewsData['unanswered'];
                $businessInfoUpdates['reviews_analysis'] = $reviewsData;
            }
        }

        // Appliquer toutes les mises a jour en DB
        if (!empty($updateFields)) {
            $updateValues[] = $auditId;
            db()->prepare('UPDATE audits SET ' . implode(', ', $updateFields) . ' WHERE id = ?')
                 ->execute($updateValues);
        }

        // === TRACKING POSITION : Re-tracker le rang Google Maps avec le mot-cle ===
        $searchKeyword = $audit['search_keyword'] ?? '';
        $auditCity = $audit['city'] ?? '';
        $auditLat = (float)($audit['latitude'] ?? 0);
        $auditLng = (float)($audit['longitude'] ?? 0);
        $auditPlaceId = $audit['place_id'] ?? '';
        $auditCid = $audit['data_cid'] ?? '';

        if ($searchKeyword && $auditLat && $auditLng) {
            try {
                $locationCoord = sprintf('%.7f,%.7f,13', $auditLat, $auditLng);
                $mapResponse = dataforseoRequest('POST', '/serp/google/maps/live/advanced', [[
                    'keyword'             => $searchKeyword . ' ' . $auditCity,
                    'location_coordinate' => $locationCoord,
                    'language_code'       => 'fr',
                    'depth'               => 100,
                    'device'              => 'mobile',
                    'os'                  => 'android',
                ]]);

                $foundPosition = null;
                if (!isset($mapResponse['_api_error'])) {
                    $task = $mapResponse['tasks'][0] ?? null;
                    if (($task['status_code'] ?? 0) === 20000) {
                        $items = $task['result'][0]['items'] ?? [];
                        foreach ($items as $idx => $rawItem) {
                            $itemType = $rawItem['type'] ?? '';
                            if ($itemType === 'maps_paid_item') continue;
                            if ($itemType !== 'maps_search' && $itemType !== 'local_pack') continue;

                            // Match par place_id (priorite) ou CID
                            $itemPlaceId = $rawItem['place_id'] ?? '';
                            $itemCid = $rawItem['data_cid'] ?? ($rawItem['cid'] ?? '');

                            if (($auditPlaceId && $itemPlaceId && $itemPlaceId === $auditPlaceId) ||
                                ($auditCid && $itemCid && $itemCid === $auditCid)) {
                                $foundPosition = $rawItem['rank_absolute'] ?? ($idx + 1);
                                break;
                            }
                        }
                    }
                }

                if ($foundPosition !== null) {
                    db()->prepare('UPDATE audits SET position = ? WHERE id = ?')
                         ->execute([$foundPosition, $auditId]);
                    $audit['position'] = $foundPosition;
                }
            } catch (Exception $e) {
                error_log("Audit position tracking error: " . $e->getMessage());
            }
        }

        // Calculer le score d'audit detaille (avec les donnees enrichies)
        $scoreBreakdown = calculateProspectAuditScore($audit);
        $totalScore = $scoreBreakdown['total'];

        // Stocker le résultat
        $auditDataExisting = json_decode($audit['audit_data'] ?? '{}', true) ?: [];
        $auditDataExisting['score_breakdown'] = $scoreBreakdown;
        $auditDataExisting['audited_at'] = date('Y-m-d H:i:s');
        if ($businessInfoUpdates) {
            $auditDataExisting['business_info'] = $businessInfoUpdates;
        }

        db()->prepare('
            UPDATE audits SET score = ?, audit_data = ?, audit_status = ? WHERE id = ?
        ')->execute([
            $totalScore,
            json_encode($auditDataExisting, JSON_UNESCAPED_UNICODE),
            'audited',
            $auditId,
        ]);

        jsonResponse([
            'success' => true,
            'score' => $totalScore,
            'breakdown' => $scoreBreakdown,
        ]);
        break;

    // ====== RECUPERER DONNEES D'AUDIT COMPLETES ======
    case 'get_audit':
        $auditId = $_GET['audit_id'] ?? $_POST['audit_id'] ?? null;
        if (!$auditId) jsonResponse(['error' => 'audit_id requis'], 400);

        $stmt = db()->prepare('
            SELECT id, business_name, city, search_keyword, address, category,
                   latitude, longitude, place_id, data_cid, domain,
                   rating, reviews_count, position, total_photos,
                   description, social_facebook, social_instagram,
                   prospect_phone, prospect_email, score, audit_status,
                   grid_scan_id, grid_visibility, grid_avg_position, grid_top3, grid_top10, grid_top20,
                   audit_data, created_at
            FROM audits WHERE id = ? AND user_id = ?
        ');
        $stmt->execute([$auditId, $user['id']]);
        $audit = $stmt->fetch();
        if (!$audit) jsonResponse(['error' => 'Prospect non trouve'], 404);

        $audit['audit_data'] = json_decode($audit['audit_data'] ?? '{}', true);

        // Si un scan grille existe, recuperer les points
        $gridPoints = [];
        $competitors = [];
        $targetRank = null;
        $targetAvg = null;
        $totalCompetitors = 0;

        if ($audit['grid_scan_id']) {
            $stmtPts = db()->prepare('
                SELECT id, row_index, col_index, latitude, longitude, position, business_name_found
                FROM prospect_grid_points WHERE scan_id = ?
                ORDER BY row_index, col_index
            ');
            $stmtPts->execute([$audit['grid_scan_id']]);
            $gridPoints = $stmtPts->fetchAll();

            // ====== TOP 3 PAR POINT (pour les popups de la carte) ======
            try {
                $stmtPtComp = db()->prepare('
                    SELECT point_id, position, title, rating, reviews_count
                    FROM prospect_grid_competitors
                    WHERE scan_id = ? AND position <= 5
                    ORDER BY point_id, position ASC
                ');
                $stmtPtComp->execute([$audit['grid_scan_id']]);
                $ptComps = $stmtPtComp->fetchAll();
                $ptCompMap = [];
                foreach ($ptComps as $pc) {
                    $pid = $pc['point_id'];
                    if (!isset($ptCompMap[$pid])) $ptCompMap[$pid] = [];
                    if (count($ptCompMap[$pid]) < 3) {
                        $ptCompMap[$pid][] = ['pos' => (int)$pc['position'], 'title' => $pc['title'], 'rating' => $pc['rating'], 'reviews' => $pc['reviews_count']];
                    }
                }
                // Attacher aux points
                foreach ($gridPoints as &$gp) {
                    $gp['top_competitors'] = $ptCompMap[$gp['id']] ?? [];
                }
                unset($gp);
            } catch (Exception $e) {
                error_log("get_audit: per-point competitors failed: " . $e->getMessage());
            }

            // ====== CONCURRENTS AGREGES (meme logique que grid.php) ======
            $totalGridPoints = count($gridPoints) ?: 49;
            $auditPlaceId = $audit['place_id'] ?? '';
            $auditCid = $audit['data_cid'] ?? '';
            $auditName = $audit['business_name'] ?? '';

            try {
                $stmtComp = db()->prepare('
                    SELECT point_id, position, title, address, category, rating,
                           reviews_count, place_id, data_cid, website
                    FROM prospect_grid_competitors
                    WHERE scan_id = ?
                ');
                $stmtComp->execute([$audit['grid_scan_id']]);
                $allComps = $stmtComp->fetchAll();

                // Agreger par concurrent unique (cle = data_cid > place_id > title)
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
                            'website' => $c['website'],
                            'points' => [],
                        ];
                    }
                    $ptId = $c['point_id'];
                    $pos = (int)$c['position'];
                    if (!isset($grouped[$key]['points'][$ptId]) || $pos < $grouped[$key]['points'][$ptId]) {
                        $grouped[$key]['points'][$ptId] = $pos;
                    }
                }

                // Calcul position moyenne avec penalite 101 pour points absents
                $rawCompetitors = [];
                foreach ($grouped as $key => $comp) {
                    $appearances = count($comp['points']);
                    $sumPos = array_sum($comp['points']);
                    $missingPoints = max(0, $totalGridPoints - $appearances);
                    $localoAvg = $totalGridPoints > 0
                        ? ($sumPos + 101 * $missingPoints) / $totalGridPoints
                        : 101;

                    // Determiner is_target : place_id > CID > fuzzy name
                    $compCid = $comp['data_cid'] ?? '';
                    $compPlaceId = $comp['place_id'] ?? '';
                    $isTarget = false;
                    if ($auditPlaceId && $compPlaceId && $compPlaceId === $auditPlaceId) {
                        $isTarget = true;
                    } elseif ($auditCid && $compCid && $compCid === $auditCid) {
                        $isTarget = true;
                    } elseif ($auditName && !$compCid && !$compPlaceId
                              && normalizeTitle($comp['title']) === normalizeTitle($auditName)) {
                        $isTarget = true;
                    }

                    $rawCompetitors[] = [
                        'title' => $comp['title'],
                        'address' => $comp['address'],
                        'category' => $comp['category'],
                        'rating' => $comp['rating'],
                        'reviews_count' => $comp['reviews_count'],
                        'place_id' => $comp['place_id'],
                        'data_cid' => $comp['data_cid'],
                        'website' => $comp['website'],
                        'sum_position' => $sumPos,
                        'appearances' => $appearances,
                        'best_position' => min($comp['points']),
                        'avg_position' => round($localoAvg, 1),
                        'is_target' => $isTarget ? 1 : 0,
                    ];
                }

                usort($rawCompetitors, function($a, $b) {
                    return $a['avg_position'] <=> $b['avg_position'];
                });

                foreach ($rawCompetitors as $idx => &$comp) {
                    $comp['rank'] = $idx + 1;
                    if ((int)$comp['is_target'] === 1) {
                        $targetRank = $idx + 1;
                        $targetAvg = $comp['avg_position'];
                    }
                }
                unset($comp);

                $totalCompetitors = count($rawCompetitors);
                $competitors = array_slice($rawCompetitors, 0, 20);
            } catch (Exception $e) {
                error_log("prospects.php get_audit: competitor aggregation failed: " . $e->getMessage());
            }
        }

        $pdfUrl = !empty($audit['pdf_path']) ? APP_URL . '/uploads/audits/' . $audit['pdf_path'] : null;

        jsonResponse([
            'success' => true,
            'audit' => $audit,
            'grid_points' => $gridPoints,
            'competitors' => $competitors,
            'target_rank' => $targetRank,
            'target_avg' => $targetAvg,
            'total_competitors' => $totalCompetitors,
            'pdf_url' => $pdfUrl,
        ]);
        break;

    // ====== GENERER LE PDF D'AUDIT ======
    case 'generate_pdf':
        $auditId = $_POST['audit_id'] ?? null;
        if (!$auditId) jsonResponse(['error' => 'audit_id requis'], 400);

        $stmt = db()->prepare('SELECT * FROM audits WHERE id = ? AND user_id = ?');
        $stmt->execute([$auditId, $user['id']]);
        $audit = $stmt->fetch();
        if (!$audit) jsonResponse(['error' => 'Prospect non trouve'], 404);

        if ($audit['audit_status'] !== 'audited') {
            jsonResponse(['error' => 'L\'audit doit être lancé avant de générer le PDF'], 400);
        }

        // Recuperer les points de grille si dispo
        $gridPoints = [];
        $pdfCompetitors = [];
        if ($audit['grid_scan_id']) {
            $stmtPts = db()->prepare('SELECT * FROM prospect_grid_points WHERE scan_id = ?');
            $stmtPts->execute([$audit['grid_scan_id']]);
            $gridPoints = $stmtPts->fetchAll();

            // Recuperer concurrents agreges pour le PDF
            try {
                $totalGridPts = count($gridPoints) ?: 49;
                $stmtC = db()->prepare('SELECT point_id, position, title, address, category, rating, reviews_count, place_id, data_cid, website FROM prospect_grid_competitors WHERE scan_id = ?');
                $stmtC->execute([$audit['grid_scan_id']]);
                $allC = $stmtC->fetchAll();
                $grp = [];
                foreach ($allC as $c) {
                    $k = !empty($c['data_cid']) ? $c['data_cid'] : (!empty($c['place_id']) ? $c['place_id'] : $c['title']);
                    if (!isset($grp[$k])) $grp[$k] = ['title'=>$c['title'],'address'=>$c['address'],'category'=>$c['category'],'rating'=>$c['rating'],'reviews_count'=>$c['reviews_count'],'place_id'=>$c['place_id'],'data_cid'=>$c['data_cid'],'website'=>$c['website'],'points'=>[]];
                    $pid = $c['point_id']; $p = (int)$c['position'];
                    if (!isset($grp[$k]['points'][$pid]) || $p < $grp[$k]['points'][$pid]) $grp[$k]['points'][$pid] = $p;
                }
                $aPlaceId = $audit['place_id'] ?? '';
                $aCid = $audit['data_cid'] ?? '';
                $aName = $audit['business_name'] ?? '';
                foreach ($grp as $comp) {
                    $app = count($comp['points']); $sum = array_sum($comp['points']);
                    $miss = max(0, $totalGridPts - $app);
                    $avg = $totalGridPts > 0 ? ($sum + 101 * $miss) / $totalGridPts : 101;
                    $cCid = $comp['data_cid'] ?? ''; $cPid = $comp['place_id'] ?? '';
                    $isTgt = ($aPlaceId && $cPid && $cPid === $aPlaceId) || ($aCid && $cCid && $cCid === $aCid)
                           || ($aName && !$cCid && !$cPid && normalizeTitle($comp['title']) === normalizeTitle($aName));
                    $pdfCompetitors[] = ['title'=>$comp['title'],'rating'=>$comp['rating'],'reviews_count'=>$comp['reviews_count'],'avg_position'=>round($avg,1),'appearances'=>$app,'is_target'=>$isTgt?1:0];
                }
                usort($pdfCompetitors, fn($a,$b) => $a['avg_position'] <=> $b['avg_position']);
                foreach ($pdfCompetitors as $i => &$cc) {
                    $cc['rank'] = $i + 1;
                    if ((int)($cc['is_target'] ?? 0) === 1) {
                        $audit['target_rank'] = $i + 1;
                    }
                }
                unset($cc);
                $pdfCompetitors = array_slice($pdfCompetitors, 0, 15);
            } catch (Exception $e) {
                error_log("generate_pdf: competitor aggregation failed: " . $e->getMessage());
            }
        }

        require_once __DIR__ . '/../includes/audit-report-generator.php';

        $pdfDir = __DIR__ . '/../uploads/audits';
        if (!is_dir($pdfDir)) mkdir($pdfDir, 0755, true);

        $token = bin2hex(random_bytes(8));
        $filename = 'audit_' . $auditId . '_' . $token . '.pdf';
        $filepath = $pdfDir . '/' . $filename;

        // Supprimer l'ancien PDF s'il existe
        $oldPdf = $audit['pdf_path'] ?? '';
        if ($oldPdf && file_exists($pdfDir . '/' . $oldPdf)) {
            @unlink($pdfDir . '/' . $oldPdf);
        }

        try {
            $generator = new AuditReportGenerator($audit, $gridPoints, $pdfCompetitors);
            $generator->generate($filepath);
        } catch (Exception $e) {
            error_log("Audit PDF generation error: " . $e->getMessage());
            jsonResponse(['error' => 'Erreur lors de la génération du PDF : ' . $e->getMessage()], 500);
        }

        if (!file_exists($filepath)) {
            jsonResponse(['error' => 'Le PDF n\'a pas pu être créé. Vérifiez les logs.'], 500);
        }

        // Stocker le chemin
        db()->prepare('UPDATE audits SET pdf_path = ? WHERE id = ?')
            ->execute([$filename, $auditId]);

        $pdfUrl = APP_URL . '/uploads/audits/' . $filename;
        jsonResponse([
            'success' => true,
            'pdf_url' => $pdfUrl,
        ]);
        break;

    // ====== LISTER LES TEMPLATES EMAIL ======
    case 'list_email_templates':
        $templates = getAuditEmailTemplates();
        $out = [];
        foreach ($templates as $key => $tpl) {
            $out[] = ['key' => $key, 'label' => $tpl['label'], 'description' => $tpl['description'], 'body' => $tpl['body']];
        }
        jsonResponse(['success' => true, 'templates' => $out]);
        break;

    // ====== ENVOYER L'AUDIT PAR EMAIL ======
    case 'send_audit':
        $auditId = $_POST['audit_id'] ?? null;
        $emailsRaw = trim($_POST['email'] ?? '');
        $templateKey = $_POST['template'] ?? 'prospection_froid';
        if (!$auditId) jsonResponse(['error' => 'audit_id requis'], 400);
        if (!$emailsRaw) jsonResponse(['error' => 'Email requis'], 400);

        // Supporter plusieurs emails séparés par des virgules
        $emailList = array_filter(array_map('trim', preg_split('/[,;]+/', $emailsRaw)));
        $invalidEmails = [];
        foreach ($emailList as $e) {
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $invalidEmails[] = $e;
            }
        }
        if (!empty($invalidEmails)) {
            jsonResponse(['error' => 'Email(s) invalide(s) : ' . implode(', ', $invalidEmails)], 400);
        }
        if (empty($emailList)) {
            jsonResponse(['error' => 'Au moins un email valide requis'], 400);
        }

        $stmt = db()->prepare('SELECT * FROM audits WHERE id = ? AND user_id = ?');
        $stmt->execute([$auditId, $user['id']]);
        $audit = $stmt->fetch();
        if (!$audit) jsonResponse(['error' => 'Prospect non trouvé'], 404);

        if ($audit['audit_status'] !== 'audited') {
            jsonResponse(['error' => 'L\'audit doit être lancé avant l\'envoi'], 400);
        }

        // Générer le PDF si pas encore fait
        $pdfPath = null;
        if (!empty($audit['pdf_path'])) {
            $pdfPath = __DIR__ . '/../uploads/audits/' . $audit['pdf_path'];
            if (!file_exists($pdfPath)) $pdfPath = null;
        }

        if (!$pdfPath) {
            $gridPoints = [];
            $sendCompetitors = [];
            if ($audit['grid_scan_id']) {
                $stmtPts = db()->prepare('SELECT * FROM prospect_grid_points WHERE scan_id = ?');
                $stmtPts->execute([$audit['grid_scan_id']]);
                $gridPoints = $stmtPts->fetchAll();

                // Concurrents agreges pour le PDF
                try {
                    $tgp = count($gridPoints) ?: 49;
                    $stC = db()->prepare('SELECT point_id, position, title, address, category, rating, reviews_count, place_id, data_cid, website FROM prospect_grid_competitors WHERE scan_id = ?');
                    $stC->execute([$audit['grid_scan_id']]);
                    $aC = $stC->fetchAll();
                    $g = [];
                    foreach ($aC as $c) {
                        $k = !empty($c['data_cid']) ? $c['data_cid'] : (!empty($c['place_id']) ? $c['place_id'] : $c['title']);
                        if (!isset($g[$k])) $g[$k] = ['title'=>$c['title'],'rating'=>$c['rating'],'reviews_count'=>$c['reviews_count'],'place_id'=>$c['place_id'],'data_cid'=>$c['data_cid'],'points'=>[]];
                        $pid = $c['point_id']; $p = (int)$c['position'];
                        if (!isset($g[$k]['points'][$pid]) || $p < $g[$k]['points'][$pid]) $g[$k]['points'][$pid] = $p;
                    }
                    $aPid = $audit['place_id'] ?? ''; $aCid = $audit['data_cid'] ?? ''; $aN = $audit['business_name'] ?? '';
                    foreach ($g as $comp) {
                        $ap = count($comp['points']); $s = array_sum($comp['points']);
                        $avg = $tgp > 0 ? ($s + 101 * max(0,$tgp-$ap)) / $tgp : 101;
                        $cC = $comp['data_cid'] ?? ''; $cP = $comp['place_id'] ?? '';
                        $isTgt = ($aPid && $cP && $cP === $aPid) || ($aCid && $cC && $cC === $aCid)
                               || ($aN && !$cC && !$cP && normalizeTitle($comp['title']) === normalizeTitle($aN));
                        $sendCompetitors[] = ['title'=>$comp['title'],'rating'=>$comp['rating'],'reviews_count'=>$comp['reviews_count'],'avg_position'=>round($avg,1),'appearances'=>$ap,'is_target'=>$isTgt?1:0];
                    }
                    usort($sendCompetitors, fn($a,$b) => $a['avg_position'] <=> $b['avg_position']);
                    foreach ($sendCompetitors as $i => &$cc) {
                        $cc['rank'] = $i + 1;
                        if ((int)($cc['is_target'] ?? 0) === 1) {
                            $audit['target_rank'] = $i + 1;
                        }
                    }
                    unset($cc);
                    $sendCompetitors = array_slice($sendCompetitors, 0, 15);
                } catch (Exception $e) { /* ignore */ }
            }

            require_once __DIR__ . '/../includes/audit-report-generator.php';

            $pdfDir = __DIR__ . '/../uploads/audits';
            if (!is_dir($pdfDir)) mkdir($pdfDir, 0755, true);

            $token = bin2hex(random_bytes(8));
            $filename = 'audit_' . $auditId . '_' . $token . '.pdf';
            $pdfPath = $pdfDir . '/' . $filename;

            // Supprimer l'ancien PDF s'il existe
            $oldPdf = $audit['pdf_path'] ?? '';
            if ($oldPdf && file_exists($pdfDir . '/' . $oldPdf)) {
                @unlink($pdfDir . '/' . $oldPdf);
            }

            $generator = new AuditReportGenerator($audit, $gridPoints, $sendCompetitors);
            $generator->generate($pdfPath);

            db()->prepare('UPDATE audits SET pdf_path = ? WHERE id = ?')
                ->execute([$filename, $auditId]);
        }

        // Envoyer l'email à chaque destinataire
        $sentCount = 0;
        $failedEmails = [];
        foreach ($emailList as $email) {
            $sent = sendAuditEmail($audit, $email, $pdfPath, $user, $templateKey);
            if ($sent) {
                $sentCount++;
            } else {
                $failedEmails[] = $email;
            }
        }

        if ($sentCount > 0) {
            // Stocker les emails + date d'envoi
            $allEmails = implode(', ', $emailList);
            db()->prepare('UPDATE audits SET prospect_email = ?, sent_at = NOW() WHERE id = ?')
                ->execute([$allEmails, $auditId]);

            $msg = $sentCount === 1
                ? 'Audit envoyé à ' . $emailList[0]
                : 'Audit envoyé à ' . $sentCount . ' destinataire(s)';
            if (!empty($failedEmails)) {
                $msg .= ' (' . count($failedEmails) . ' échec(s) : ' . implode(', ', $failedEmails) . ')';
            }
            jsonResponse(['success' => true, 'message' => $msg, 'sent_count' => $sentCount, 'sent_at' => date('Y-m-d H:i:s')]);
        } else {
            jsonResponse(['error' => 'Échec de l\'envoi de l\'email'], 500);
        }
        break;

    default:
        jsonResponse(['error' => 'Action non reconnue'], 400);
}
