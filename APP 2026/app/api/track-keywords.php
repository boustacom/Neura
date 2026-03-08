<?php
/**
 * BOUS'TACOM — API Tracking des positions via DataForSEO
 *
 * Supporte :
 *   - Scan de TOUS les mots-cles : POST location_id
 *   - Scan d'UN SEUL mot-cle :    POST location_id + keyword_id
 *
 * Utilise DataForSEO Google Maps SERP (task-based, batch)
 * location_coordinate gere directement les coordonnees GPS.
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();
requireCsrf();

header('Content-Type: application/json');

// Timeout PHP genereux pour les scans multi-mots-cles
set_time_limit(300); // 5 minutes max

$locationId = $_POST['location_id'] ?? $_GET['location_id'] ?? null;
$singleKeywordId = $_POST['keyword_id'] ?? null;

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
$stmt->execute([$locationId, $_SESSION['user_id']]);
$location = $stmt->fetch();

if (!$location) {
    jsonResponse(['error' => 'Fiche non trouvee'], 404);
}

// Mots-cles a tracker
if ($singleKeywordId) {
    $stmt = db()->prepare('SELECT * FROM keywords WHERE id = ? AND location_id = ? AND is_active = 1');
    $stmt->execute([$singleKeywordId, $locationId]);
    $keywords = $stmt->fetchAll();
    if (empty($keywords)) {
        jsonResponse(['error' => 'Mot-cle non trouve ou inactif'], 404);
    }
} else {
    $stmt = db()->prepare('SELECT * FROM keywords WHERE location_id = ? AND is_active = 1');
    $stmt->execute([$locationId]);
    $keywords = $stmt->fetchAll();
    if (empty($keywords)) {
        jsonResponse(['error' => 'Aucun mot-cle a tracker'], 400);
    }
}

$today = date('Y-m-d');
$results = [];
$debugInfo = [];

// Preparer le matching
$googleCid = $location['google_cid'] ?? '';
$placeId = $location['place_id'] ?? '';

// ============================================
// STRATEGIE DE LOCALISATION DataForSEO
// ============================================
// DataForSEO location_coordinate: "lat,lng,13" — GPS direct, zoom 13 pour viewport large
// Fallback si pas de GPS: location_name: "France" (le mot-cle contient deja la ville)
// ============================================

$lat = (float)($location['latitude'] ?? 0);
$lng = (float)($location['longitude'] ?? 0);
$locationMode = ($lat && $lng) ? 'gps' : 'fallback';

// ============================================
// BATCH : Poster toutes les tasks en 1 seul POST
// ============================================

$batchTasks = [];
foreach ($keywords as $idx => $kw) {
    $task = [
        'keyword' => $kw['keyword'],
        'tag'     => "kw_{$kw['id']}",
    ];
    if ($lat && $lng) {
        $task['lat'] = $lat;
        $task['lng'] = $lng;
    }
    $batchTasks[] = $task;
}

// POST batch
$batchResult = dataforseoPostTasks($batchTasks);

if (!$batchResult['success']) {
    jsonResponse([
        'success'    => false,
        'error'      => 'DataForSEO: echec du POST batch — ' . json_encode($batchResult),
        'error_type' => 'api_error',
        'location'   => $location['name'],
        'date'       => $today,
        'results'    => [],
    ]);
}

// Attendre les resultats (max 120s)
$allResults = dataforseoWaitForResults($batchResult['task_ids'], 120);
dbEnsureConnected(); // Reconnexion MySQL apres le polling DataForSEO

// Mapper task_id => keyword
$taskIdToKwIdx = [];
foreach ($batchResult['task_ids'] as $tIdx => $tId) {
    if (isset($keywords[$tIdx])) {
        $taskIdToKwIdx[$tId] = $tIdx;
    }
}

// ============================================
// TRAITER LES RESULTATS
// ============================================

foreach ($keywords as $kwIdx => $kw) {
    $position = null;
    $inLocalPack = false;
    $matchMethod = null;
    $matchedTitle = null;
    $apiError = null;

    // Trouver le resultat correspondant a ce mot-cle
    $items = [];
    foreach ($taskIdToKwIdx as $tId => $tIdx) {
        if ($tIdx === $kwIdx && isset($allResults[$tId])) {
            $items = $allResults[$tId]['items'] ?? [];
            break;
        }
    }

    if (empty($items)) {
        // Pas de resultat pour ce mot-cle
        $apiError = 'Aucun resultat DataForSEO pour ce mot-cle';
    } else {
        // ====== MATCHING 3-TIER : place_id > CID > fuzzy ======
        $matchResult = computeRankForPoint($location, $items);
        if ($matchResult['found']) {
            $position     = $matchResult['rank'];
            $inLocalPack  = ($matchResult['rank'] !== null && $matchResult['rank'] <= 3);
            $matchMethod  = $matchResult['match_method'];
            $matchedTitle = $matchResult['matched_name'] ?? '?';
        }
        // Auto-backfill des IDs decouverts
        if (!empty($matchResult['backfill'])) {
            applyBackfill($locationId, $matchResult['backfill'], 'track-keywords');
            $location = array_merge($location, $matchResult['backfill']);
            $googleCid = $location['google_cid'] ?? '';
            $placeId   = $location['place_id'] ?? '';
        }

        // Debug : les 5 premiers resultats
        $first5 = array_map(function($r) {
            return [
                'pos'      => $r['position'] ?? '?',
                'title'    => $r['title'] ?? '?',
                'data_cid' => $r['data_cid'] ?? null,
                'place_id' => $r['place_id'] ?? null,
            ];
        }, array_slice($items, 0, 5));

        $debugInfo[] = [
            'keyword'          => $kw['keyword'],
            'location_mode'    => $locationMode,
            'gps'              => ($lat && $lng) ? "{$lat},{$lng}" : 'none',
            'items_count'      => count($items),
            'position_found'   => $position,
            'match_method'     => $matchMethod,
            'matched_title'    => $matchedTitle,
            'our_google_cid'   => $googleCid,
            'our_place_id'     => $placeId,
            'first_5_results'  => $first5,
        ];
    }

    if ($apiError) {
        $debugInfo[] = [
            'keyword'        => $kw['keyword'],
            'location_mode'  => $locationMode,
            'gps'            => ($lat && $lng) ? "{$lat},{$lng}" : 'none',
            'error'          => $apiError,
            'our_google_cid' => $googleCid,
        ];
    }

    // Sauvegarder la position
    try {
        $stmt = db()->prepare('
            INSERT INTO keyword_positions (keyword_id, position, in_local_pack, tracked_at)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE position = VALUES(position), in_local_pack = VALUES(in_local_pack)
        ');
        $stmt->execute([$kw['id'], $position, $inLocalPack ? 1 : 0, $today]);
    } catch (Exception $e) {
        error_log("track-keywords: erreur sauvegarde kw={$kw['id']}: " . $e->getMessage());
    }

    // Position precedente
    $stmtPrev = db()->prepare('
        SELECT position FROM keyword_positions
        WHERE keyword_id = ? AND tracked_at < ?
        ORDER BY tracked_at DESC LIMIT 1
    ');
    $stmtPrev->execute([$kw['id'], $today]);
    $prevPosition = $stmtPrev->fetchColumn();

    $trend = null;
    if ($prevPosition !== false && $position !== null) {
        $trend = (int)$prevPosition - (int)$position;
    }

    $results[] = [
        'keyword_id'        => $kw['id'],
        'keyword'           => $kw['keyword'],
        'position'          => $position,
        'in_local_pack'     => $inLocalPack,
        'previous_position' => $prevPosition !== false ? (int)$prevPosition : null,
        'trend'             => $trend,
        'match_method'      => $matchMethod,
        'api_error'         => $apiError,
    ];
}

jsonResponse([
    'success'      => true,
    'location'     => $location['name'],
    'date'         => $today,
    'results'      => $results,
    'credits_used' => count($keywords),
    'debug'        => $debugInfo,
]);


// ============================================
// NOTE: Le matching utilise computeRankForPoint() (3-tier: place_id > CID > fuzzy)
// defini dans includes/functions.php. DataForSEO gere les coordonnees GPS
// via location_coordinate — plus besoin de UULE ou de cascade de fallbacks.
// ============================================
