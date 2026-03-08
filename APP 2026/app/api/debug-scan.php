<?php
/**
 * BOUS'TACOM — Diagnostic DataForSEO : MAPS vs LOCAL_FINDER (LIVE)
 *
 * Compare 2 endpoints DataForSEO × 2 points GPS = 4 strategies :
 *   A) /serp/google/maps/live/advanced          — CENTRE
 *   B) /serp/google/maps/live/advanced          — 10km NORD
 *   C) /serp/google/local_finder/live/advanced   — CENTRE
 *   D) /serp/google/local_finder/live/advanced   — 10km NORD
 *
 * Utilise les endpoints LIVE (synchrones) pour eviter le timeout du polling.
 * Si D fonctionne → basculer les grid scans sur local_finder !
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
set_time_limit(180);

startSecureSession();
requireLogin();
$user = currentUser();

$locationId = $_GET['location_id'] ?? null;
$keywordId  = $_GET['keyword_id']  ?? null;

// ====== MODE API : si les 2 params sont fournis et valides ======
if ($locationId && $keywordId && is_numeric($locationId) && is_numeric($keywordId)) {
    header('Content-Type: application/json');
    $t0 = microtime(true);

    // Charger la location
    $stmt = db()->prepare('
        SELECT l.*, a.user_id FROM gbp_locations l
        JOIN gbp_accounts a ON l.gbp_account_id = a.id
        WHERE l.id = ? AND a.user_id = ?
    ');
    $stmt->execute([$locationId, $user['id']]);
    $location = $stmt->fetch();
    if (!$location) jsonResponse(['error' => 'Fiche non trouvee'], 404);

    // Charger le mot-cle
    $stmt = db()->prepare('SELECT * FROM keywords WHERE id = ? AND location_id = ?');
    $stmt->execute([$keywordId, $locationId]);
    $kw = $stmt->fetch();
    if (!$kw) jsonResponse(['error' => 'Mot-cle non trouve'], 404);

    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $lat = (float)$location['latitude'];
    $lng = (float)$location['longitude'];
    $keyword = $kw['keyword'];
    $targetCity = $kw['target_city'] ?? '';

    $cityShort = '';
    if ($targetCity) {
        $cityParts = explode(',', $targetCity);
        $cityShort = trim($cityParts[0]);
    }

    // Reconstituer keyword+ville exactement comme dataforseoPostTasks
    $keywordWithCity = $keyword;
    if ($cityShort && stripos($keyword, $cityShort) === false) {
        $keywordWithCity = $keyword . ' ' . $cityShort;
    }

    $locationCoord = sprintf('%.7f,%.7f,13', $lat, $lng);

    // Point a ~10km au NORD
    $distantLat = $lat + (10.0 / 111.32);
    $distantLng = $lng;
    $distantCoord = sprintf('%.7f,%.7f,13', $distantLat, $distantLng);

    $ourPlaceId = $location['place_id'] ?? '';
    $ourCid     = $location['google_cid'] ?? '';
    $ourName    = $location['name'] ?? '';

    // ====== PARAMETRES COMMUNS ======
    $commonParams = [
        'language_code' => 'fr',
        'depth' => 100,
        'device' => 'mobile',
        'os' => 'android',
    ];

    // ====== 4 STRATEGIES (2 endpoints × 2 GPS) ======
    $strategies = [
        'A_MAPS_centre' => [
            'endpoint' => '/serp/google/maps/live/advanced',
            'label'    => 'maps',
            'params'   => array_merge($commonParams, [
                'keyword' => $keywordWithCity,
                'location_coordinate' => $locationCoord,
            ]),
        ],
        'B_MAPS_10km' => [
            'endpoint' => '/serp/google/maps/live/advanced',
            'label'    => 'maps',
            'params'   => array_merge($commonParams, [
                'keyword' => $keywordWithCity,
                'location_coordinate' => $distantCoord,
            ]),
        ],
        'C_FINDER_centre' => [
            'endpoint' => '/serp/google/local_finder/live/advanced',
            'label'    => 'finder',
            'params'   => array_merge($commonParams, [
                'keyword' => $keywordWithCity,
                'location_coordinate' => $locationCoord,
            ]),
        ],
        'D_FINDER_10km' => [
            'endpoint' => '/serp/google/local_finder/live/advanced',
            'label'    => 'finder',
            'params'   => array_merge($commonParams, [
                'keyword' => $keywordWithCity,
                'location_coordinate' => $distantCoord,
            ]),
        ],
    ];

    // ====== EXECUTER CHAQUE STRATEGIE EN LIVE ======
    $output = [
        'location' => [
            'name'       => $ourName,
            'place_id'   => $ourPlaceId,
            'google_cid' => $ourCid,
            'lat'        => $lat,
            'lng'        => $lng,
            'distant_lat' => round($distantLat, 7),
            'distant_lng' => round($distantLng, 7),
        ],
        'keyword'           => $keyword,
        'target_city'       => $targetCity,
        'city_short'        => $cityShort,
        'keyword_with_city' => $keywordWithCity,
        'coord_centre'      => $locationCoord,
        'coord_10km'        => $distantCoord,
        'strategies'        => [],
        'result_errors'     => [],
    ];

    foreach ($strategies as $key => $strat) {
        $tStart = microtime(true);

        // Appel LIVE : on envoie un tableau avec 1 task
        $response = dataforseoRequest('POST', $strat['endpoint'], [$strat['params']]);
        $elapsed = round(microtime(true) - $tStart, 2);

        $stratResult = [
            'endpoint'         => $strat['label'],
            'endpoint_url'     => $strat['endpoint'],
            'params'           => $strat['params'],
            'elapsed_sec'      => $elapsed,
            'total_results'    => 0,
            'raw_item_types'   => [],
            'our_position'     => null,
            'our_match_method' => null,
            'api_error'        => null,
            'api_status_code'  => null,
            'task_status_code' => null,
            'top_20'           => [],
        ];

        // Erreur API globale
        if (isset($response['_api_error'])) {
            $stratResult['api_error'] = $response['_api_error'];
            $stratResult['api_status_code'] = $response['_http_code'] ?? null;
            $output['result_errors'][$key] = $response['_api_error'];
            $output['strategies'][$key] = $stratResult;
            continue;
        }

        $stratResult['api_status_code'] = $response['status_code'] ?? null;

        // Extraire la premiere task
        $task = $response['tasks'][0] ?? null;
        if (!$task) {
            $stratResult['api_error'] = 'Pas de task dans la reponse';
            $output['result_errors'][$key] = 'Pas de task dans la reponse';
            $output['strategies'][$key] = $stratResult;
            continue;
        }

        $stratResult['task_status_code'] = $task['status_code'] ?? null;
        $stratResult['task_cost'] = $task['cost'] ?? 0;

        // Erreur task
        if (($task['status_code'] ?? 0) !== 20000) {
            $msg = $task['status_message'] ?? "status " . ($task['status_code'] ?? '?');
            $stratResult['api_error'] = $msg;
            $output['result_errors'][$key] = $msg;
            $output['strategies'][$key] = $stratResult;
            continue;
        }

        // Extraire les items
        $result = $task['result'][0] ?? null;
        $rawItems = $result['items'] ?? [];

        // Collecter les types bruts pour debug
        $rawTypes = [];
        foreach ($rawItems as $ri) {
            $t = $ri['type'] ?? 'unknown';
            $rawTypes[$t] = ($rawTypes[$t] ?? 0) + 1;
        }
        $stratResult['raw_item_types'] = $rawTypes;
        $stratResult['se_results_count'] = $result['se_results_count'] ?? null;

        // Normaliser les items
        // maps → type='maps_search', local_finder → type='local_pack'
        // On accepte tout sauf maps_paid_item
        $items = [];
        foreach ($rawItems as $idx => $item) {
            $itemType = $item['type'] ?? '';
            if ($itemType === 'maps_paid_item') continue;

            // Normaliser vers notre format interne
            $items[] = [
                'position'  => $item['rank_group'] ?? ($idx + 1),
                'title'     => $item['title'] ?? '',
                'address'   => $item['address'] ?? '',
                'data_cid'  => isset($item['cid']) ? (string)$item['cid'] : null,
                'place_id'  => $item['place_id'] ?? null,
                'data_id'   => $item['feature_id'] ?? null,
                'domain'    => $item['domain'] ?? null,
                'url'       => $item['url'] ?? null,
                'rating'    => $item['rating']['value'] ?? null,
                'type'      => $itemType,
            ];
        }

        $stratResult['total_results'] = count($items);

        // Chercher notre fiche dans les resultats
        foreach ($items as $idx => $item) {
            $itemPlaceId = $item['place_id'] ?? null;
            // Aussi checker data_id qui peut contenir le place_id
            if (!$itemPlaceId && !empty($item['data_id'])) {
                $did = $item['data_id'];
                if (str_starts_with($did, 'ChIJ') || str_starts_with($did, '0x')) {
                    $itemPlaceId = $did;
                } elseif (preg_match('/(ChIJ[A-Za-z0-9_-]+)/', $did, $m)) {
                    $itemPlaceId = $m[1];
                }
            }
            // Aussi checker l'URL pour le place_id
            if (!$itemPlaceId && !empty($item['url'])) {
                if (preg_match('/place_id[=:]([A-Za-z0-9_-]+)/', $item['url'], $m)) {
                    $itemPlaceId = $m[1];
                } elseif (preg_match('/(ChIJ[A-Za-z0-9_-]+)/', $item['url'], $m)) {
                    $itemPlaceId = $m[1];
                }
            }

            $itemCid = $item['data_cid'] ?? null;

            $isUs = false;
            $matchMethod = null;

            // TIER 1 : Place ID
            if ($ourPlaceId && $itemPlaceId && $itemPlaceId === $ourPlaceId) {
                $isUs = true;
                $matchMethod = 'place_id';
            }
            // TIER 2 : CID
            elseif ($ourCid && $itemCid && $itemCid === $ourCid) {
                $isUs = true;
                $matchMethod = 'cid';
            }
            // TIER 3 : Nom (fuzzy - pour debug seulement)
            elseif ($ourName && normalizeTitle($item['title'] ?? '') === normalizeTitle($ourName)) {
                $isUs = true;
                $matchMethod = 'fuzzy_name';
            }

            if ($isUs && $stratResult['our_position'] === null) {
                $stratResult['our_position'] = $item['position'];
                $stratResult['our_match_method'] = $matchMethod;
            }

            if (count($stratResult['top_20']) < 20) {
                $stratResult['top_20'][] = [
                    'position'  => $item['position'],
                    'title'     => $item['title'] ?? '?',
                    'place_id'  => $itemPlaceId,
                    'data_cid'  => $itemCid,
                    'domain'    => $item['domain'] ?? null,
                    'is_us'     => $isUs,
                    'match'     => $matchMethod,
                    'type'      => $item['type'] ?? null,
                ];
            }
        }

        $output['strategies'][$key] = $stratResult;

        // Re-connecter la DB au cas ou (les appels API prennent du temps)
        dbEnsureConnected();
    }

    $output['total_duration_sec'] = round(microtime(true) - $t0, 1);
    $output['total_cost'] = array_sum(array_column($output['strategies'], 'task_cost'));

    jsonResponse($output);
    exit;
}

// ====== MODE HTML : interface de selection ======

$stmt = db()->prepare('
    SELECT l.id, l.name, l.city, l.place_id, l.google_cid, l.latitude, l.longitude
    FROM gbp_locations l
    JOIN gbp_accounts a ON l.gbp_account_id = a.id
    WHERE a.user_id = ? AND l.is_active = 1
    ORDER BY l.name
');
$stmt->execute([$user['id']]);
$locations = $stmt->fetchAll();

$kwByLocation = [];
if ($locations) {
    $locIds = array_column($locations, 'id');
    $placeholders = implode(',', array_fill(0, count($locIds), '?'));
    $stmt = db()->prepare("SELECT id, location_id, keyword, target_city FROM keywords WHERE location_id IN ($placeholders) ORDER BY keyword");
    $stmt->execute($locIds);
    foreach ($stmt->fetchAll() as $k) {
        $kwByLocation[$k['location_id']][] = $k;
    }
}

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Debug — MAPS vs LOCAL_FINDER (LIVE)</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Mono&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #0b1121; --bg2: #0f172a; --bg3: #1e293b;
    --bdr: rgba(255,255,255,.08);
    --t1: #f1f5f9; --t2: #94a3b8; --t3: #64748b;
    --acc: #00d4ff; --g: #22C55E; --o: #F59E0B; --p: #EC4899; --r: #EF4444;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', system-ui, sans-serif; background: var(--bg); color: var(--t1); min-height: 100vh; padding: 40px 24px; }
.container { max-width: 1400px; margin: 0 auto; }
h1 { font-size: 24px; font-weight: 700; margin-bottom: 8px; }
.subtitle { color: var(--t3); font-size: 14px; margin-bottom: 32px; line-height: 1.6; }
.subtitle strong { color: var(--acc); }

.form-row { display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
.form-group { flex: 1; min-width: 250px; }
.form-group label { display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .8px; color: var(--t3); margin-bottom: 8px; }
select, .btn { font-family: 'Inter', sans-serif; font-size: 14px; }
select { width: 100%; padding: 10px 14px; background: var(--bg2); border: 1px solid var(--bdr); border-radius: 8px; color: var(--t1); outline: none; cursor: pointer; }
select:hover, select:focus { border-color: var(--acc); }
option { background: var(--bg2); }
.btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 24px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; transition: all .15s; }
.btn-primary { background: linear-gradient(135deg, #00d4ff, #1e7eff); color: #fff; }
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 20px rgba(0,212,255,.3); }
.btn-primary:disabled { opacity: .4; cursor: not-allowed; transform: none; box-shadow: none; }

.info-card { background: var(--bg2); border: 1px solid var(--bdr); border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; }
.info-card h3 { font-size: 13px; font-weight: 600; color: var(--acc); margin-bottom: 10px; }
.info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px; }
.info-item { font-size: 12px; }
.info-item .label { color: var(--t3); }
.info-item .value { color: var(--t1); font-family: 'Space Mono', monospace; font-size: 11px; }

.loader { display: none; align-items: center; gap: 12px; padding: 20px; color: var(--acc); font-size: 14px; }
.loader.active { display: flex; }
.spinner { width: 24px; height: 24px; border: 3px solid rgba(0,212,255,.15); border-top-color: var(--acc); border-radius: 50%; animation: spin .8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.results { display: none; }
.results.active { display: block; }

.endpoint-badge { display: inline-block; font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 4px; text-transform: uppercase; letter-spacing: .5px; margin-left: 8px; }
.endpoint-badge.maps { background: rgba(245,158,11,.15); color: var(--o); }
.endpoint-badge.finder { background: rgba(0,212,255,.15); color: var(--acc); }

.strat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-top: 24px; }
@media (max-width: 1100px) { .strat-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 600px) { .strat-grid { grid-template-columns: 1fr; } }

.strat-card { background: var(--bg2); border: 1px solid var(--bdr); border-radius: 12px; overflow: hidden; }
.strat-card.winner { border-color: var(--g); box-shadow: 0 0 20px rgba(34,197,94,.15); }
.strat-card.loser { opacity: .6; }
.strat-card.key-test { border-color: var(--acc); box-shadow: 0 0 20px rgba(0,212,255,.1); }

.strat-header { padding: 14px 16px; border-bottom: 1px solid var(--bdr); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px; }
.strat-name { font-size: 13px; font-weight: 700; }
.strat-badge { font-size: 11px; padding: 3px 10px; border-radius: 20px; font-weight: 600; font-family: 'Space Mono', monospace; }
.strat-badge.found { background: rgba(34,197,94,.12); color: var(--g); }
.strat-badge.notfound { background: rgba(239,68,68,.12); color: var(--r); }

.strat-meta { padding: 10px 16px; border-bottom: 1px solid var(--bdr); font-size: 11px; color: var(--t3); font-family: 'Space Mono', monospace; }
.strat-meta span { color: var(--t2); }
.strat-desc { padding: 8px 16px; font-size: 11px; color: var(--t3); border-bottom: 1px solid var(--bdr); }

.strat-list { padding: 0; }
.strat-item { display: flex; align-items: center; gap: 10px; padding: 8px 16px; border-bottom: 1px solid rgba(255,255,255,.03); font-size: 13px; }
.strat-item:hover { background: rgba(255,255,255,.02); }
.strat-item.is-us { background: rgba(0,212,255,.06); border-left: 3px solid var(--acc); padding-left: 13px; }
.strat-pos { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; font-family: 'Space Mono', monospace; flex-shrink: 0; }
.strat-pos.top3 { background: rgba(34,197,94,.15); color: var(--g); }
.strat-pos.top10 { background: rgba(245,158,11,.12); color: var(--o); }
.strat-pos.top20 { background: rgba(236,72,153,.12); color: var(--p); }
.strat-pos.out { background: rgba(239,68,68,.12); color: var(--r); }
.strat-title { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--t2); }
.strat-item.is-us .strat-title { color: var(--acc); font-weight: 600; }
.strat-match { font-size: 10px; padding: 2px 6px; border-radius: 4px; background: rgba(0,212,255,.1); color: var(--acc); font-family: 'Space Mono', monospace; flex-shrink: 0; }
.strat-total { padding: 10px 16px; font-size: 12px; color: var(--t3); border-top: 1px solid var(--bdr); }
.strat-types { padding: 6px 16px; font-size: 10px; color: var(--t3); font-family: 'Space Mono', monospace; border-top: 1px solid var(--bdr); }

.verdict { margin-top: 24px; padding: 20px; background: var(--bg2); border: 1px solid var(--bdr); border-radius: 12px; }
.verdict h3 { font-size: 15px; font-weight: 700; margin-bottom: 12px; }
.verdict-row { display: flex; align-items: center; gap: 12px; padding: 8px 0; font-size: 13px; border-bottom: 1px solid rgba(255,255,255,.03); }
.verdict-strat { font-weight: 600; width: 320px; color: var(--t2); display: flex; align-items: center; gap: 6px; }
.verdict-pos { font-family: 'Space Mono', monospace; font-weight: 700; min-width: 120px; }
.verdict-pos.found { color: var(--g); }
.verdict-pos.notfound { color: var(--r); }
.verdict-meta { color: var(--t3); font-size: 12px; }

.verdict-conclusion { margin-top: 16px; padding: 16px 20px; border-radius: 8px; font-size: 14px; line-height: 1.6; }
.verdict-conclusion.success { background: rgba(34,197,94,.08); border: 1px solid rgba(34,197,94,.2); color: var(--g); }
.verdict-conclusion.fail { background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.2); color: var(--r); }
.verdict-conclusion.partial { background: rgba(245,158,11,.08); border: 1px solid rgba(245,158,11,.2); color: var(--o); }
.verdict-conclusion strong { color: var(--acc); }

.error-box { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3); border-radius: 12px; padding: 16px 20px; margin-bottom: 16px; }
.error-box h3 { color: var(--r); margin-bottom: 8px; font-size: 14px; }
.error-box div { font-size: 13px; color: var(--t2); margin: 4px 0; }

.back-link { display: inline-flex; align-items: center; gap: 6px; color: var(--t3); text-decoration: none; font-size: 13px; margin-bottom: 20px; }
.back-link:hover { color: var(--acc); }
</style>
</head>
<body>
<div class="container">
    <a href="../?view=dashboard" class="back-link">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        Retour au dashboard
    </a>

    <h1>MAPS vs LOCAL_FINDER (LIVE)</h1>
    <p class="subtitle">
        Compare les endpoints <strong>/serp/google/maps/</strong> vs <strong>/serp/google/local_finder/</strong> en mode LIVE (synchrone).<br>
        Chaque strategie envoie "<strong>keyword + ville</strong>" depuis 2 points GPS : centre et 10km nord.<br>
        Si <strong>D) LOCAL_FINDER 10km</strong> trouve la fiche → solution trouvee pour les grilles !
    </p>

    <div class="form-row">
        <div class="form-group">
            <label>Fiche GBP</label>
            <select id="selLocation" onchange="onLocationChange()">
                <option value="">-- Selectionner une fiche --</option>
                <?php foreach ($locations as $loc): ?>
                <option value="<?= (int)$loc['id'] ?>"
                    data-name="<?= htmlspecialchars($loc['name']) ?>"
                    data-city="<?= htmlspecialchars($loc['city'] ?? '') ?>"
                    data-place-id="<?= htmlspecialchars($loc['place_id'] ?? '') ?>"
                    data-cid="<?= htmlspecialchars($loc['google_cid'] ?? '') ?>"
                    data-lat="<?= htmlspecialchars($loc['latitude'] ?? '') ?>"
                    data-lng="<?= htmlspecialchars($loc['longitude'] ?? '') ?>"
                ><?= htmlspecialchars($loc['name']) ?> — <?= htmlspecialchars($loc['city'] ?? '?') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Mot-cle</label>
            <select id="selKeyword" disabled>
                <option value="">-- Selectionner un mot-cle --</option>
            </select>
        </div>
    </div>

    <div id="infoCard" class="info-card" style="display:none;">
        <h3>Parametres du test</h3>
        <div class="info-grid">
            <div class="info-item"><span class="label">Fiche :</span> <span class="value" id="infoName">-</span></div>
            <div class="info-item"><span class="label">Place ID :</span> <span class="value" id="infoPlaceId">-</span></div>
            <div class="info-item"><span class="label">CID :</span> <span class="value" id="infoCid">-</span></div>
            <div class="info-item"><span class="label">Coords centre :</span> <span class="value" id="infoCoords">-</span></div>
            <div class="info-item"><span class="label">Keyword :</span> <span class="value" id="infoKw">-</span></div>
            <div class="info-item"><span class="label">Ville :</span> <span class="value" id="infoCity">-</span></div>
        </div>
    </div>

    <button class="btn btn-primary" id="btnRun" disabled onclick="runTest()">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
        Lancer le test MAPS vs LOCAL_FINDER (LIVE)
    </button>

    <div class="loader" id="loader">
        <div class="spinner"></div>
        <span id="loaderText">Envoi de 4 requetes LIVE... ~20-40 sec</span>
    </div>

    <div class="results" id="results"></div>
</div>

<script>
const kwData = <?= json_encode($kwByLocation, JSON_UNESCAPED_UNICODE) ?>;

function onLocationChange() {
    const sel = document.getElementById('selLocation');
    const kwSel = document.getElementById('selKeyword');
    kwSel.innerHTML = '<option value="">-- Selectionner un mot-cle --</option>';
    kwSel.disabled = true;
    document.getElementById('btnRun').disabled = true;
    document.getElementById('infoCard').style.display = 'none';
    if (!sel.value) return;
    const keywords = kwData[sel.value] || [];
    if (!keywords.length) { kwSel.innerHTML = '<option value="">Aucun mot-cle</option>'; return; }
    keywords.forEach(kw => {
        const opt = document.createElement('option');
        opt.value = kw.id;
        opt.textContent = kw.keyword + (kw.target_city ? ' — ' + kw.target_city.split(',')[0] : '');
        opt.dataset.keyword = kw.keyword;
        opt.dataset.city = kw.target_city || '';
        kwSel.appendChild(opt);
    });
    kwSel.disabled = false;
    kwSel.onchange = onKeywordChange;
}

function onKeywordChange() {
    const locSel = document.getElementById('selLocation');
    const kwSel = document.getElementById('selKeyword');
    if (!locSel.value || !kwSel.value) {
        document.getElementById('btnRun').disabled = true;
        document.getElementById('infoCard').style.display = 'none';
        return;
    }
    document.getElementById('btnRun').disabled = false;
    document.getElementById('infoCard').style.display = 'block';
    const lo = locSel.options[locSel.selectedIndex];
    const ko = kwSel.options[kwSel.selectedIndex];
    document.getElementById('infoName').textContent = lo.dataset.name;
    document.getElementById('infoPlaceId').textContent = lo.dataset.placeId || '(vide!)';
    document.getElementById('infoCid').textContent = lo.dataset.cid || '(vide)';
    document.getElementById('infoCoords').textContent = lo.dataset.lat + ', ' + lo.dataset.lng;
    document.getElementById('infoKw').textContent = ko.dataset.keyword;
    document.getElementById('infoCity').textContent = ko.dataset.city || '(aucune)';
    document.getElementById('infoCard').style.borderColor = lo.dataset.placeId ? '' : 'rgba(239,68,68,.4)';
}

async function runTest() {
    const locId = document.getElementById('selLocation').value;
    const kwId = document.getElementById('selKeyword').value;
    if (!locId || !kwId) return;
    const btn = document.getElementById('btnRun');
    const loader = document.getElementById('loader');
    const results = document.getElementById('results');
    btn.disabled = true;
    loader.classList.add('active');
    results.classList.remove('active');
    results.innerHTML = '';
    try {
        const resp = await fetch('debug-scan.php?location_id=' + locId + '&keyword_id=' + kwId);
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const data = await resp.json();
        if (data.error) {
            results.innerHTML = '<div class="error-box"><h3>Erreur</h3><div>' + data.error + '</div></div>';
            results.classList.add('active');
            return;
        }
        renderResults(data);
    } catch (err) {
        results.innerHTML = '<div class="error-box"><h3>Erreur reseau</h3><div>' + err.message + '</div></div>';
        results.classList.add('active');
    } finally {
        btn.disabled = false;
        loader.classList.remove('active');
    }
}

function posClass(p) {
    if (p <= 3) return 'top3';
    if (p <= 10) return 'top10';
    if (p <= 20) return 'top20';
    return 'out';
}

function renderResults(data) {
    const el = document.getElementById('results');
    let html = '';
    const strats = data.strategies;
    const stratLabels = {
        'A_MAPS_centre':   'A) MAPS — Centre',
        'B_MAPS_10km':     'B) MAPS — 10km Nord',
        'C_FINDER_centre': 'C) LOCAL_FINDER — Centre',
        'D_FINDER_10km':   'D) LOCAL_FINDER — 10km Nord',
    };
    const stratDescs = {
        'A_MAPS_centre':   'Reference. Endpoint maps au centre GPS. Devrait fonctionner.',
        'B_MAPS_10km':     'Reference. Endpoint maps a 10km. Echoue (viewport Google Maps).',
        'C_FINDER_centre': 'Nouveau endpoint local_finder au centre. Test de base.',
        'D_FINDER_10km':   'LE TEST CLE ! Local_finder a 10km. Si ca marche → solution !',
    };

    // Erreurs
    if (data.result_errors && Object.keys(data.result_errors).length > 0) {
        html += '<div class="error-box"><h3>Erreurs</h3>';
        for (const [k, v] of Object.entries(data.result_errors)) {
            html += '<div><strong>' + k + '</strong>: ' + v + '</div>';
        }
        html += '</div>';
    }

    // Verdict
    html += '<div class="verdict"><h3>Verdict : MAPS vs LOCAL_FINDER</h3>';

    let dPos = null, cPos = null, aPos = null, bPos = null;

    for (const [key, s] of Object.entries(strats)) {
        const pos = s.our_position;
        const posStr = pos ? '#' + pos : 'Non trouve';
        const cls = pos ? 'found' : 'notfound';
        const epBadge = s.endpoint === 'finder'
            ? '<span class="endpoint-badge finder">LOCAL_FINDER</span>'
            : '<span class="endpoint-badge maps">MAPS</span>';

        html += '<div class="verdict-row">';
        html += '<span class="verdict-strat">' + stratLabels[key] + epBadge + '</span>';
        html += '<span class="verdict-pos ' + cls + '">' + posStr;
        if (pos && s.our_match_method) html += ' (' + s.our_match_method + ')';
        html += '</span>';
        html += '<span class="verdict-meta">' + s.total_results + ' res. | ' + s.elapsed_sec + 's | $' + (s.task_cost || 0) + '</span>';
        html += '</div>';

        if (key === 'A_MAPS_centre') aPos = pos;
        if (key === 'B_MAPS_10km') bPos = pos;
        if (key === 'C_FINDER_centre') cPos = pos;
        if (key === 'D_FINDER_10km') dPos = pos;
    }

    // Conclusion
    if (dPos && !bPos) {
        html += '<div class="verdict-conclusion success">';
        html += '<strong>LOCAL_FINDER FONCTIONNE A 10KM !</strong> Position #' + dPos + ' trouvee.<br>';
        html += 'Alors que MAPS echoue a 10km (seulement ' + (strats['B_MAPS_10km']?.total_results || '?') + ' resultats viewport).<br><br>';
        html += '→ <strong>BASCULER LES GRID SCANS SUR /serp/google/local_finder/ !</strong>';
        html += '</div>';
    } else if (dPos && bPos) {
        html += '<div class="verdict-conclusion success">';
        html += '<strong>Les deux endpoints fonctionnent a 10km !</strong><br>';
        html += 'MAPS: #' + bPos + ' | LOCAL_FINDER: #' + dPos + '<br>';
        html += 'LOCAL_FINDER est preferable car resultats plus stables sur les grilles.';
        html += '</div>';
    } else if (!dPos && cPos) {
        html += '<div class="verdict-conclusion partial">';
        html += 'LOCAL_FINDER fonctionne au centre (#' + cPos + ') mais PAS a 10km.<br>';
        html += 'Meme probleme que MAPS — les deux endpoints sont viewport-bases. Il faut investiguer autrement.';
        html += '</div>';
    } else if (!dPos && !cPos) {
        html += '<div class="verdict-conclusion fail">';
        html += 'LOCAL_FINDER ne retourne AUCUN resultat (meme au centre).<br>';
        html += 'Verifiez les erreurs API ci-dessus. Possible probleme de parametres ou credits.';
        html += '</div>';
    } else {
        html += '<div class="verdict-conclusion fail">';
        html += 'Resultats inattendus. Analysez les colonnes ci-dessous.';
        html += '</div>';
    }

    html += '<div style="margin-top:12px;font-size:12px;color:var(--t3);">Duree totale: ' + data.total_duration_sec + 's | Cout total: $' + (data.total_cost || 0) + '</div>';
    html += '</div>';

    // Cards
    html += '<div class="strat-grid">';
    for (const [key, s] of Object.entries(strats)) {
        let cardClass = 'strat-card';
        if (key === 'D_FINDER_10km') {
            cardClass += s.our_position ? ' winner' : ' key-test';
        }
        if (key === 'B_MAPS_10km' && !s.our_position) cardClass += ' loser';

        html += '<div class="' + cardClass + '">';
        html += '<div class="strat-header"><span class="strat-name">' + stratLabels[key] + '</span>';
        if (s.our_position) {
            html += '<span class="strat-badge found">#' + s.our_position + '</span>';
        } else if (s.api_error) {
            html += '<span class="strat-badge notfound">ERREUR</span>';
        } else {
            html += '<span class="strat-badge notfound">Non trouve</span>';
        }
        html += '</div>';

        html += '<div class="strat-meta">endpoint=<span>' + s.endpoint + '</span> | q=<span>"' + s.params.keyword + '"</span> | ' + s.elapsed_sec + 's</div>';
        html += '<div class="strat-desc">' + (stratDescs[key] || '') + '</div>';

        if (s.api_error) {
            html += '<div style="padding:10px 16px;color:var(--r);font-size:12px;">Erreur: ' + s.api_error + '</div>';
        }

        html += '<div class="strat-list">';
        (s.top_20 || []).forEach(item => {
            const cls = item.is_us ? 'strat-item is-us' : 'strat-item';
            const pCls = posClass(item.position);
            html += '<div class="' + cls + '">';
            html += '<div class="strat-pos ' + pCls + '">' + item.position + '</div>';
            html += '<div class="strat-title">' + (item.title || '?') + '</div>';
            if (item.match) html += '<span class="strat-match">' + item.match + '</span>';
            html += '</div>';
        });
        html += '</div>';

        html += '<div class="strat-total">' + s.total_results + ' resultats totaux</div>';
        if (s.raw_item_types && Object.keys(s.raw_item_types).length > 0) {
            html += '<div class="strat-types">Types: ' + Object.entries(s.raw_item_types).map(([t, c]) => t + '=' + c).join(', ') + '</div>';
        }
        html += '</div>';
    }
    html += '</div>';

    el.innerHTML = html;
    el.classList.add('active');
}
</script>
</body>
</html>
