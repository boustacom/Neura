<?php
/**
 * BOUS'TACOM — API Grille de Positions (Grid Rank)
 *
 * Actions:
 *   - list: Lister les scans existants pour un mot-cle
 *   - get: Recuperer un scan (points + concurrents agreges)
 *
 * Note: Le scan est gere par scan-async.php (batch DataForSEO)
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();
requireCsrf();

header('Content-Type: application/json');
set_time_limit(300); // 5 minutes max pour les scans

$user = currentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? null;
$locationId = $_POST['location_id'] ?? $_GET['location_id'] ?? null;

if (!$action) {
    jsonResponse(['error' => 'Action requise'], 400);
}

if (!$locationId) {
    jsonResponse(['error' => 'location_id requis'], 400);
}

// Verifier que la location appartient a l'utilisateur
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

switch ($action) {

    // ====== LISTER LES SCANS D'UN MOT-CLE ======
    case 'list':
        $keywordId = $_GET['keyword_id'] ?? null;
        if (!$keywordId) {
            jsonResponse(['error' => 'keyword_id requis'], 400);
        }

        $stmt = db()->prepare('
            SELECT gs.*, k.keyword
            FROM grid_scans gs
            JOIN keywords k ON gs.keyword_id = k.id
            WHERE k.id = ? AND k.location_id = ?
            ORDER BY gs.scanned_at DESC
            LIMIT 10
        ');
        $stmt->execute([$keywordId, $locationId]);
        $scans = $stmt->fetchAll();

        jsonResponse(['success' => true, 'scans' => $scans]);
        break;

    // ====== RECUPERER UN SCAN AVEC SES POINTS + CONCURRENTS ======
    case 'get':
        $scanId = $_GET['scan_id'] ?? null;
        if (!$scanId) {
            jsonResponse(['error' => 'scan_id requis'], 400);
        }

        $stmt = db()->prepare('
            SELECT gs.*, k.keyword
            FROM grid_scans gs
            JOIN keywords k ON gs.keyword_id = k.id
            WHERE gs.id = ? AND k.location_id = ?
        ');
        $stmt->execute([$scanId, $locationId]);
        $scan = $stmt->fetch();

        if (!$scan) {
            jsonResponse(['error' => 'Scan non trouve'], 404);
        }

        $stmt = db()->prepare('SELECT * FROM grid_points WHERE grid_scan_id = ? ORDER BY row_index, col_index');
        $stmt->execute([$scanId]);
        $points = $stmt->fetchAll();

        // Nombre total de points de la grille
        $totalGridPoints = (int)($scan['total_points'] ?? count($points));

        // ====== RECALCUL DES POSITIONS VIA CID (corrige les anciens scans) ======
        // Strategie multi-fallback pour identifier notre fiche dans les concurrents :
        //   1. CID (google_cid ↔ data_cid) — 100% fiable, prioritaire
        //   2. place_id exact — fiable, fallback si CID pas encore detecte
        //   3. is_target = 1 stocke en base — dernier recours (ancien matching)
        // On recalcule a chaque affichage pour que les anciens scans soient corriges.
        $googleCid = $location['google_cid'] ?? '';
        $placeId = $location['place_id'] ?? '';

        // RECALCUL par point : trouver la vraie position de notre fiche
        foreach ($points as &$pt) {
            $ptId = $pt['id'];
            $found = false;

            // 1. Match par CID (le plus fiable)
            if ($googleCid) {
                $stmtPtComp = db()->prepare('
                    SELECT position, title FROM grid_competitors
                    WHERE grid_point_id = ? AND data_cid = ?
                    LIMIT 1
                ');
                $stmtPtComp->execute([$ptId, $googleCid]);
                $targetComp = $stmtPtComp->fetch();
                if ($targetComp) {
                    $pt['position'] = $targetComp['position'];
                    $pt['business_name_found'] = $targetComp['title'];
                    $found = true;
                }
            }

            // 2. Fallback par place_id (si CID pas disponible ou pas trouve)
            if (!$found && $placeId) {
                $stmtPtComp2 = db()->prepare('
                    SELECT position, title, data_cid FROM grid_competitors
                    WHERE grid_point_id = ? AND place_id = ?
                    LIMIT 1
                ');
                $stmtPtComp2->execute([$ptId, $placeId]);
                $targetComp2 = $stmtPtComp2->fetch();
                if ($targetComp2) {
                    $pt['position'] = $targetComp2['position'];
                    $pt['business_name_found'] = $targetComp2['title'];
                    $found = true;
                    // Bonus : si on a trouve le CID via place_id et qu'on ne l'avait pas, le sauvegarder
                    if (!$googleCid && !empty($targetComp2['data_cid'])) {
                        $googleCid = $targetComp2['data_cid'];
                        try {
                            db()->prepare('UPDATE gbp_locations SET google_cid = ? WHERE id = ? AND google_cid IS NULL')
                                ->execute([$googleCid, $locationId]);
                            error_log("grid.php: AUTO-BACKFILL CID={$googleCid} for location={$locationId} via place_id");
                        } catch (Exception $e) { /* ignore */ }
                    }
                }
            }

            // 3. Fallback : is_target=1 en base (ancien matching)
            if (!$found) {
                $stmtPtComp3 = db()->prepare('
                    SELECT position, title FROM grid_competitors
                    WHERE grid_point_id = ? AND is_target = 1
                    LIMIT 1
                ');
                $stmtPtComp3->execute([$ptId]);
                $targetComp3 = $stmtPtComp3->fetch();
                if ($targetComp3) {
                    $pt['position'] = $targetComp3['position'];
                    $pt['business_name_found'] = $targetComp3['title'];
                    $found = true;
                }
            }

            // 4. Fuzzy match par nom normalise (fonctionne meme sans CID/place_id)
            if (!$found && !empty($location['name'])) {
                $ourNormName = normalizeTitle($location['name']);
                $stmtPtComp4 = db()->prepare('
                    SELECT position, title, data_cid, place_id FROM grid_competitors
                    WHERE grid_point_id = ?
                    ORDER BY position ASC
                ');
                $stmtPtComp4->execute([$ptId]);
                foreach ($stmtPtComp4->fetchAll() as $comp) {
                    if (normalizeTitle($comp['title']) === $ourNormName) {
                        $pt['position'] = $comp['position'];
                        $pt['business_name_found'] = $comp['title'];
                        $found = true;
                        // Backfill depuis donnees stockees
                        $backfill = [];
                        if (!$googleCid && !empty($comp['data_cid'])) {
                            $backfill['google_cid'] = $comp['data_cid'];
                            $googleCid = $comp['data_cid'];
                        }
                        if (!$placeId && !empty($comp['place_id'])) {
                            $backfill['place_id'] = $comp['place_id'];
                            $placeId = $comp['place_id'];
                        }
                        if (!empty($backfill)) {
                            applyBackfill($locationId, $backfill, 'grid-get');
                        }
                        break;
                    }
                }
            }

            // Si pas trouve dans grid_competitors, conserver la position ORIGINALE du scan.
            // computeRankForPoint() lors du scan a cherche dans TOUS les resultats (depth=100).
            // Avant ce fix, on ecrasait la position a 101 ici, ce qui causait le bug
            // "rang #2 dans le classement mais 20+ sur les bulles".
            if (!$found) {
                // Si la position originale est deja renseignee (1-101), on la GARDE.
                // On ne met 101 que si position est vide ET l'API avait repondu.
                $originalPos = $pt['position'];
                if ($originalPos === null || $originalPos === '') {
                    $stmtCount = db()->prepare('SELECT COUNT(*) FROM grid_competitors WHERE grid_point_id = ?');
                    $stmtCount->execute([$ptId]);
                    $hasCompetitors = (int)$stmtCount->fetchColumn() > 0;
                    if ($hasCompetitors) {
                        $pt['position'] = 101;
                        $pt['business_name_found'] = null;
                    }
                }
            }
        }
        unset($pt);

        // ====== CONCURRENTS AGREGES ======
        // Position moyenne stricte : avg = (sum_positions + 101 * missing) / total_points
        // 101 = absent du top 100 (depth=100). is_target recalcule via CID + place_id fallback
        $competitors = [];
        $targetRank = null;
        $targetAvg = null;
        try {
            // Charger TOUS les concurrents bruts du scan
            $stmtComp = db()->prepare('
                SELECT grid_point_id, position, title, address, category, rating,
                       reviews_count, place_id, data_cid, website
                FROM grid_competitors
                WHERE grid_scan_id = ?
            ');
            $stmtComp->execute([$scanId]);
            $allComps = $stmtComp->fetchAll();

            // Agreger en PHP pour eviter les problemes MySQL GROUP BY
            // Cle = data_cid (prioritaire) ou place_id ou title
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
                        'points' => [], // grid_point_id => best_position
                    ];
                }
                $ptId = $c['grid_point_id'];
                $pos = (int)$c['position'];
                // Garder la meilleure position par point (deduplication)
                if (!isset($grouped[$key]['points'][$ptId]) || $pos < $grouped[$key]['points'][$ptId]) {
                    $grouped[$key]['points'][$ptId] = $pos;
                }
            }

            // Calculer position moyenne Localo + is_target
            $rawCompetitors = [];
            foreach ($grouped as $key => $comp) {
                $appearances = count($comp['points']);
                $sumPos = array_sum($comp['points']);
                $missingPoints = max(0, $totalGridPoints - $appearances);
                $localoAvg = $totalGridPoints > 0
                    ? ($sumPos + 101 * $missingPoints) / $totalGridPoints
                    : 101;

                // Recalcul is_target 3-tier : place_id > CID > fuzzy
                $compCid = $comp['data_cid'] ?? '';
                $compPlaceId = $comp['place_id'] ?? '';
                $isTarget = false;
                if ($placeId && $compPlaceId && $compPlaceId === $placeId) {
                    $isTarget = true;
                } elseif ($googleCid && $compCid && $compCid === $googleCid) {
                    $isTarget = true;
                } elseif (!empty($location['name']) && !$compCid && !$compPlaceId
                          && normalizeTitle($comp['title']) === normalizeTitle($location['name'])) {
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

            $competitors = array_slice($rawCompetitors, 0, 30);
        } catch (Exception $e) {
            error_log("grid.php: grid_competitors aggregation failed: " . $e->getMessage());
        }

        // Recalculer les stats du scan via computeGridKPIs() (formule stricte Top3/Total x 100)
        $recalcPositions = [];
        foreach ($points as $pt) {
            $recalcPositions[] = ($pt['position'] !== null && $pt['position'] !== '') ? (int)$pt['position'] : null;
        }
        $recalcKpis = computeGridKPIs($recalcPositions, $totalGridPoints);

        // Injecter les stats recalculees dans l'objet scan
        $scan['avg_position'] = $recalcKpis['avg_position'];
        $scan['visibility_score'] = $recalcKpis['visibility_score'];
        $scan['top3_count'] = $recalcKpis['top3_count'];
        $scan['top10_count'] = $recalcKpis['top10_count'];
        $scan['top20_count'] = $recalcKpis['top20_count'];
        $scan['out_count'] = $recalcKpis['out_count'];

        jsonResponse([
            'success'     => true,
            'scan'        => $scan,
            'points'      => $points,
            'center'      => ['lat' => (float)$location['latitude'], 'lng' => (float)$location['longitude']],
            'competitors' => $competitors,
            'target_rank' => $targetRank,
            'target_avg'  => $targetAvg,
            'total_competitors' => count($rawCompetitors ?? []),
        ]);
        break;

    // ====== SCAN DE GRILLE — DEPRECIE ======
    // Le scan est desormais gere par scan-async.php (batch DataForSEO)
    case 'scan':
    case 'scan_cron':
        jsonResponse(['error' => 'Action depreciee. Utilisez scan-async.php pour les scans de grille.'], 410);
        break;

    default:
        jsonResponse(['error' => 'Action non reconnue'], 400);
}


// ============================================
// FONCTIONS LOCALES
// ============================================

// ============================================
// NOTE: Le matching est desormais CID-only (data_cid === google_cid).
// Les anciennes fonctions buildNameVariantsForGrid() et matchBusinessForGrid()
// ont ete supprimees car elles causaient des faux positifs.
// Le CID est auto-detecte au premier scan par scan-async.php.
// ============================================
