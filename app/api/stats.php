<?php
/**
 * BOUS'TACOM — API Statistiques Google Business Profile
 * Endpoints: list (cached), fetch (from Google), summary
 * Utilise l'API Google Business Profile Performance v1
 * 
 * IMPORTANT: L'API Performance utilise des requetes GET avec query params
 * https://developers.google.com/my-business/reference/performance/rest/v1/locations/fetchMultiDailyMetricsTimeSeries
 */

require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();
requireCsrf();

header('Content-Type: application/json');
$user = currentUser();

// ====== AUTO-MIGRATION : table location_daily_stats ======
static $statsMigDone = false;
if (!$statsMigDone) {
    try {
        db()->exec("
            CREATE TABLE IF NOT EXISTS location_daily_stats (
                id INT AUTO_INCREMENT PRIMARY KEY,
                location_id INT NOT NULL,
                stat_date DATE NOT NULL,
                impressions_search INT NOT NULL DEFAULT 0,
                impressions_maps INT NOT NULL DEFAULT 0,
                direction_requests INT NOT NULL DEFAULT 0,
                call_clicks INT NOT NULL DEFAULT 0,
                website_clicks INT NOT NULL DEFAULT 0,
                conversations INT NOT NULL DEFAULT 0,
                bookings INT NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_loc_date (location_id, stat_date),
                INDEX idx_location (location_id),
                INDEX idx_date (stat_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {
        error_log("stats.php migration: " . $e->getMessage()); // logAppError pas encore dispo ici
    }
    $statsMigDone = true;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$locationId = intval($_GET['location_id'] ?? $_POST['location_id'] ?? 0);

if (!$locationId) {
    echo json_encode(['error' => 'location_id requis']);
    exit;
}

// Verifier que l'utilisateur a acces a cette fiche
$stmt = db()->prepare('
    SELECT l.*, a.id as account_id, a.google_account_name
    FROM gbp_locations l
    JOIN gbp_accounts a ON l.gbp_account_id = a.id
    WHERE l.id = ? AND a.user_id = ?
');
$stmt->execute([$locationId, $user['id']]);
$location = $stmt->fetch();

if (!$location) {
    echo json_encode(['error' => 'Fiche introuvable']);
    exit;
}

/**
 * Appel GET vers l'API Google Business Profile Performance v1
 * fetchMultiDailyMetricsTimeSeries
 *
 * @param string $googleLocationId  ex: "locations/123456789"
 * @param string $token             Bearer token Google OAuth2
 * @param DateTime $startDate
 * @param DateTime $endDate
 * @return array  Reponse decodee JSON
 */
function fetchGooglePerformanceStats(string $googleLocationId, string $token, DateTime $startDate, DateTime $endDate): array {
    // Construire les query params pour le GET
    $params = [
        'dailyRange.startDate.year'  => (int)$startDate->format('Y'),
        'dailyRange.startDate.month' => (int)$startDate->format('m'),
        'dailyRange.startDate.day'   => (int)$startDate->format('d'),
        'dailyRange.endDate.year'    => (int)$endDate->format('Y'),
        'dailyRange.endDate.month'   => (int)$endDate->format('m'),
        'dailyRange.endDate.day'     => (int)$endDate->format('d'),
    ];

    // dailyMetrics doit etre repete pour chaque valeur (array dans query string)
    $dailyMetrics = [
        'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
        'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
        'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
        'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
        'BUSINESS_DIRECTION_REQUESTS',
        'CALL_CLICKS',
        'WEBSITE_CLICKS',
        'BUSINESS_CONVERSATIONS',
        'BUSINESS_BOOKINGS',
    ];

    // Construire la query string manuellement (dailyMetrics repete)
    $queryParts = [];
    foreach ($dailyMetrics as $metric) {
        $queryParts[] = 'dailyMetrics=' . urlencode($metric);
    }
    foreach ($params as $key => $value) {
        $queryParts[] = urlencode($key) . '=' . urlencode($value);
    }
    $queryString = implode('&', $queryParts);

    $baseUrl = "https://businessprofileperformance.googleapis.com/v1/{$googleLocationId}:fetchMultiDailyMetricsTimeSeries";
    $url = $baseUrl . '?' . $queryString;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,  // GET explicite
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $decoded = json_decode($response, true) ?? [];
    $decoded['_http_code'] = $httpCode;
    $decoded['_request_url'] = $url;
    if ($curlError) $decoded['_curl_error'] = $curlError;

    return $decoded;
}

/**
 * Parse les time series Google en tableau date => metrics
 *
 * La reponse Google fetchMultiDailyMetricsTimeSeries a une structure imbriquee :
 * multiDailyMetricTimeSeries[].dailyMetricTimeSeries[].dailyMetric
 * multiDailyMetricTimeSeries[].dailyMetricTimeSeries[].timeSeries.datedValues[]
 *
 * Ou parfois directement (ancien format) :
 * multiDailyMetricTimeSeries[].dailyMetric
 * multiDailyMetricTimeSeries[].timeSeries.datedValues[]
 */
function parseTimeSeries(array $response): array {
    $dailyData = [];
    $multiSeries = $response['multiDailyMetricTimeSeries'] ?? [];

    // Aplatir : extraire toutes les series individuelles (gerer les 2 formats)
    $allSeries = [];
    foreach ($multiSeries as $entry) {
        // Format imbrique : multiDailyMetricTimeSeries[].dailyMetricTimeSeries[]
        if (isset($entry['dailyMetricTimeSeries']) && is_array($entry['dailyMetricTimeSeries'])) {
            foreach ($entry['dailyMetricTimeSeries'] as $innerSeries) {
                $allSeries[] = $innerSeries;
            }
        }
        // Format direct : multiDailyMetricTimeSeries[].dailyMetric + timeSeries
        elseif (isset($entry['dailyMetric'])) {
            $allSeries[] = $entry;
        }
    }

    foreach ($allSeries as $series) {
        $metricName = $series['dailyMetric'] ?? '';

        // Chercher datedValues dans plusieurs chemins possibles
        $dataPoints = $series['timeSeries']['datedValues'] ?? [];
        if (empty($dataPoints) && isset($series['dailySubEntityType']['timeSeries']['datedValues'])) {
            $dataPoints = $series['dailySubEntityType']['timeSeries']['datedValues'];
        }

        foreach ($dataPoints as $point) {
            if (!isset($point['date']['year'])) continue;
            $date = sprintf('%04d-%02d-%02d', $point['date']['year'], $point['date']['month'], $point['date']['day']);
            if (!isset($dailyData[$date])) {
                $dailyData[$date] = [
                    'impressions_search' => 0,
                    'impressions_maps' => 0,
                    'direction_requests' => 0,
                    'call_clicks' => 0,
                    'website_clicks' => 0,
                    'conversations' => 0,
                    'bookings' => 0,
                ];
            }
            $value = (int)($point['value'] ?? 0);

            switch ($metricName) {
                case 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH':
                case 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH':
                    $dailyData[$date]['impressions_search'] += $value;
                    break;
                case 'BUSINESS_IMPRESSIONS_DESKTOP_MAPS':
                case 'BUSINESS_IMPRESSIONS_MOBILE_MAPS':
                    $dailyData[$date]['impressions_maps'] += $value;
                    break;
                case 'BUSINESS_DIRECTION_REQUESTS':
                    $dailyData[$date]['direction_requests'] += $value;
                    break;
                case 'CALL_CLICKS':
                    $dailyData[$date]['call_clicks'] += $value;
                    break;
                case 'WEBSITE_CLICKS':
                    $dailyData[$date]['website_clicks'] += $value;
                    break;
                case 'BUSINESS_CONVERSATIONS':
                    $dailyData[$date]['conversations'] += $value;
                    break;
                case 'BUSINESS_BOOKINGS':
                    $dailyData[$date]['bookings'] += $value;
                    break;
            }
        }
    }

    return $dailyData;
}

switch ($action) {

    // ====== LISTE DES STATS CACHED ======
    case 'list':
        // Periode personnalisee (from/to) ou par nombre de mois
        $customFrom = $_GET['from'] ?? null;
        $customTo   = $_GET['to'] ?? null;
        if ($customFrom && $customTo && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customTo)) {
            $startDate = $customFrom;
            $endDate   = $customTo;
        } else {
            $months = intval($_GET['months'] ?? 6);
            $startDate = date('Y-m-01', strtotime("-{$months} months"));
            $endDate   = null;
        }

        $sql = 'SELECT stat_date, impressions_search, impressions_maps,
                       direction_requests, call_clicks, website_clicks,
                       conversations, bookings
                FROM location_daily_stats
                WHERE location_id = ? AND stat_date >= ?';
        $params = [$locationId, $startDate];
        if ($endDate) {
            $sql .= ' AND stat_date <= ?';
            $params[] = $endDate;
        }
        $sql .= ' ORDER BY stat_date ASC';

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $daily = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Agreger par mois
        $monthly = [];
        foreach ($daily as $row) {
            $m = substr($row['stat_date'], 0, 7); // YYYY-MM
            if (!isset($monthly[$m])) {
                $monthly[$m] = [
                    'month' => $m,
                    'impressions_search' => 0,
                    'impressions_maps' => 0,
                    'direction_requests' => 0,
                    'call_clicks' => 0,
                    'website_clicks' => 0,
                    'conversations' => 0,
                    'bookings' => 0,
                    'days' => 0,
                ];
            }
            $monthly[$m]['impressions_search'] += (int)$row['impressions_search'];
            $monthly[$m]['impressions_maps'] += (int)$row['impressions_maps'];
            $monthly[$m]['direction_requests'] += (int)$row['direction_requests'];
            $monthly[$m]['call_clicks'] += (int)$row['call_clicks'];
            $monthly[$m]['website_clicks'] += (int)$row['website_clicks'];
            $monthly[$m]['conversations'] += (int)$row['conversations'];
            $monthly[$m]['bookings'] += (int)$row['bookings'];
            $monthly[$m]['days']++;
        }

        // Trier par mois
        ksort($monthly);
        $monthlyArr = array_values($monthly);

        // Calculer les tendances (mois complets intelligents)
        // Si le dernier mois a < 28 jours → comparer les 2 derniers mois COMPLETS
        // Ex: le 5 fevrier → Janvier vs Decembre (pas Fevrier incomplet vs Janvier)
        $trends = [];
        $comparedMonths = null;

        if (count($monthlyArr) >= 2) {
            $lastIdx = count($monthlyArr) - 1;
            $lastMonth = $monthlyArr[$lastIdx];
            $lastMonthComplete = ($lastMonth['days'] >= 28);

            $current = null;
            $previous = null;

            if ($lastMonthComplete && $lastIdx >= 1) {
                // Dernier mois complet → comparer last vs avant-dernier
                $current = $monthlyArr[$lastIdx];
                $previous = $monthlyArr[$lastIdx - 1];
            } elseif (!$lastMonthComplete && $lastIdx >= 2) {
                // Mois en cours incomplet → comparer les 2 derniers mois complets
                $current = $monthlyArr[$lastIdx - 1];
                $previous = $monthlyArr[$lastIdx - 2];
            } elseif ($lastIdx === 1) {
                // Seulement 2 mois → comparer quand meme
                $current = $monthlyArr[1];
                $previous = $monthlyArr[0];
            }

            if ($current && $previous) {
                $comparedMonths = [
                    'current'       => $current['month'],
                    'current_days'  => $current['days'],
                    'previous'      => $previous['month'],
                    'previous_days' => $previous['days'],
                ];

                $metrics = ['impressions_search', 'impressions_maps', 'direction_requests', 'call_clicks', 'website_clicks', 'conversations', 'bookings'];
                foreach ($metrics as $metric) {
                    $prev = $previous[$metric] ?: 1;
                    $curr = $current[$metric];
                    $pct = round(($curr - $previous[$metric]) / $prev * 100, 1);
                    $trends[$metric] = [
                        'current'    => $curr,
                        'previous'   => $previous[$metric],
                        'change_pct' => $pct,
                        'direction'  => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'stable'),
                    ];
                }
            }
        }

        // ====== TOTAUX DE LA PERIODE (somme sur toute la plage) ======
        $periodTotals = [
            'impressions_search' => 0, 'impressions_maps' => 0,
            'direction_requests' => 0, 'call_clicks' => 0,
            'website_clicks' => 0, 'conversations' => 0, 'bookings' => 0,
        ];
        foreach ($daily as $row) {
            foreach (array_keys($periodTotals) as $mk) {
                $periodTotals[$mk] += (int)$row[$mk];
            }
        }

        // ====== TENDANCES PERIODE vs PERIODE PRECEDENTE (plages custom) ======
        $periodTrends = null;
        if ($endDate) {
            $fromDt = new DateTime($startDate);
            $toDt = new DateTime($endDate);
            $dayCount = $fromDt->diff($toDt)->days + 1;
            $prevEnd = (clone $fromDt)->modify('-1 day');
            $prevStart = (clone $prevEnd)->modify('-' . ($dayCount - 1) . ' days');
            $stmtPrev = db()->prepare('
                SELECT COALESCE(SUM(impressions_search),0) as impressions_search,
                       COALESCE(SUM(impressions_maps),0) as impressions_maps,
                       COALESCE(SUM(direction_requests),0) as direction_requests,
                       COALESCE(SUM(call_clicks),0) as call_clicks,
                       COALESCE(SUM(website_clicks),0) as website_clicks,
                       COALESCE(SUM(conversations),0) as conversations,
                       COALESCE(SUM(bookings),0) as bookings
                FROM location_daily_stats
                WHERE location_id = ? AND stat_date >= ? AND stat_date <= ?
            ');
            $stmtPrev->execute([$locationId, $prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d')]);
            $prevTotals = $stmtPrev->fetch(PDO::FETCH_ASSOC);
            if ($prevTotals) {
                $periodTrends = [];
                $metrics = array_keys($periodTotals);
                foreach ($metrics as $metric) {
                    $curr = $periodTotals[$metric];
                    $prev = (int)($prevTotals[$metric] ?? 0);
                    $base = $prev ?: 1;
                    $pct = round(($curr - $prev) / $base * 100, 1);
                    $periodTrends[$metric] = [
                        'current' => $curr,
                        'previous' => $prev,
                        'change_pct' => $pct,
                        'direction' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'stable'),
                    ];
                }
            }
        }

        // Derniere date de stats
        $stmtLast = db()->prepare('SELECT MAX(stat_date) as last_date FROM location_daily_stats WHERE location_id = ?');
        $stmtLast->execute([$locationId]);
        $lastDate = $stmtLast->fetchColumn();

        // ====== SEO KEYWORDS SUMMARY ======
        $seo = ['total' => 0, 'tracked' => 0, 'top3' => 0, 'top10' => 0, 'top20' => 0, 'out' => 0, 'avg_position' => 0, 'keywords' => []];
        try {
            $stmtKw = db()->prepare('
                SELECT k.id, k.keyword, k.target_city,
                    kp.position AS current_position,
                    kp_prev.position AS previous_position,
                    gs.visibility_score, gs.avg_position AS grid_avg_position,
                    gs.top3_count AS grid_top3, gs.top10_count AS grid_top10, gs.top20_count AS grid_top20,
                    gs.total_points AS grid_total, gs.scanned_at AS grid_scanned_at
                FROM keywords k
                LEFT JOIN keyword_positions kp ON kp.keyword_id = k.id
                    AND kp.tracked_at = (SELECT MAX(kp2.tracked_at) FROM keyword_positions kp2 WHERE kp2.keyword_id = k.id)
                LEFT JOIN keyword_positions kp_prev ON kp_prev.keyword_id = k.id
                    AND kp_prev.tracked_at = (SELECT MAX(kp3.tracked_at) FROM keyword_positions kp3 WHERE kp3.keyword_id = k.id AND kp3.tracked_at < COALESCE(kp.tracked_at, CURDATE()))
                LEFT JOIN grid_scans gs ON gs.keyword_id = k.id
                    AND gs.scanned_at = (SELECT MAX(gs2.scanned_at) FROM grid_scans gs2 WHERE gs2.keyword_id = k.id)
                WHERE k.location_id = ? AND k.is_active = 1
                ORDER BY kp.position ASC, k.keyword ASC
            ');
            $stmtKw->execute([$locationId]);
            $kwRows = $stmtKw->fetchAll(PDO::FETCH_ASSOC);
            $seo['total'] = count($kwRows);
            $posSum = 0; $posCount = 0;
            foreach ($kwRows as $kw) {
                $pos = $kw['current_position'];
                $prev = $kw['previous_position'];
                $trend = ($pos !== null && $prev !== null) ? ((int)$prev - (int)$pos) : null;
                if ($pos !== null) {
                    $seo['tracked']++;
                    $posSum += (int)$pos;
                    $posCount++;
                    if ($pos <= 3) $seo['top3']++;
                    elseif ($pos <= 10) $seo['top10']++;
                    elseif ($pos <= 20) $seo['top20']++;
                    else $seo['out']++;
                } else {
                    $seo['out']++;
                }
                $seo['keywords'][] = [
                    'id' => $kw['id'],
                    'keyword' => $kw['keyword'],
                    'city' => $kw['target_city'],
                    'position' => $pos !== null ? (int)$pos : null,
                    'previous' => $prev !== null ? (int)$prev : null,
                    'trend' => $trend,
                    'visibility' => $kw['visibility_score'] !== null ? (int)$kw['visibility_score'] : null,
                    'grid_avg' => $kw['grid_avg_position'] ? round((float)$kw['grid_avg_position'], 1) : null,
                    'grid_top3' => $kw['grid_top3'] !== null ? (int)$kw['grid_top3'] : null,
                    'grid_total' => $kw['grid_total'] !== null ? (int)$kw['grid_total'] : null,
                    'grid_date' => $kw['grid_scanned_at'],
                ];
            }
            $seo['avg_position'] = $posCount > 0 ? round($posSum / $posCount, 1) : 0;
        } catch (Exception $e) { /* table may not exist */ }

        // ====== REVIEWS SUMMARY ======
        $reviews = ['total' => 0, 'avg_rating' => 0, 'unanswered' => 0, 'distribution' => [1=>0,2=>0,3=>0,4=>0,5=>0], 'recent' => []];
        try {
            $stmtRev = db()->prepare('SELECT COUNT(*) as total, ROUND(AVG(rating),2) as avg_rating FROM reviews WHERE location_id = ? AND deleted_by_google = 0');
            $stmtRev->execute([$locationId]);
            $revStats = $stmtRev->fetch(PDO::FETCH_ASSOC);
            $reviews['total'] = (int)($revStats['total'] ?? 0);
            $reviews['avg_rating'] = $revStats['avg_rating'] ? round((float)$revStats['avg_rating'], 2) : 0;

            $stmtUn = db()->prepare("SELECT COUNT(*) FROM reviews WHERE location_id = ? AND deleted_by_google = 0 AND is_replied = 0");
            $stmtUn->execute([$locationId]);
            $reviews['unanswered'] = (int)$stmtUn->fetchColumn();

            $stmtDist = db()->prepare('SELECT rating, COUNT(*) as cnt FROM reviews WHERE location_id = ? AND deleted_by_google = 0 GROUP BY rating ORDER BY rating');
            $stmtDist->execute([$locationId]);
            foreach ($stmtDist->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $reviews['distribution'][(int)$row['rating']] = (int)$row['cnt'];
            }

            // 5 derniers avis
            $stmtRecent = db()->prepare('SELECT reviewer_name AS author_name, rating, comment, review_date AS create_time, reply_text AS reply, is_replied FROM reviews WHERE location_id = ? AND deleted_by_google = 0 ORDER BY review_date DESC LIMIT 5');
            $stmtRecent->execute([$locationId]);
            $reviews['recent'] = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { /* table may not exist */ }

        // ====== LOCATION INFO ======
        $locationInfo = [];
        try {
            $stmtLoc = db()->prepare('SELECT name, city, address, category, website, phone, google_cid, place_id, latitude, longitude FROM gbp_locations WHERE id = ?');
            $stmtLoc->execute([$locationId]);
            $locationInfo = $stmtLoc->fetch(PDO::FETCH_ASSOC) ?: [];

            // Compter les photos depuis google_posts (type photo/image publiees)
            try {
                $stmtPhotos = db()->prepare("SELECT COUNT(*) FROM post_images WHERE location_id = ? AND status = 'generated'");
                $stmtPhotos->execute([$locationId]);
                $locationInfo['total_visuals'] = (int)$stmtPhotos->fetchColumn();
            } catch (Exception $e2) { $locationInfo['total_visuals'] = 0; }

            // Description : essayer de la recuperer si la colonne existe
            $locationInfo['description'] = '';
            try {
                $stmtDesc = db()->prepare('SELECT description FROM gbp_locations WHERE id = ?');
                $stmtDesc->execute([$locationId]);
                $locationInfo['description'] = $stmtDesc->fetchColumn() ?: '';
            } catch (Exception $e2) { /* colonne n'existe pas */ }
        } catch (Exception $e) {}

        // ====== POSTS SUMMARY ======
        $posts = ['total' => 0, 'published' => 0, 'scheduled' => 0, 'draft' => 0];
        try {
            $stmtPosts = db()->prepare("SELECT status, COUNT(*) as cnt FROM google_posts WHERE location_id = ? GROUP BY status");
            $stmtPosts->execute([$locationId]);
            foreach ($stmtPosts->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $posts['total'] += (int)$row['cnt'];
                if ($row['status'] === 'published') $posts['published'] = (int)$row['cnt'];
                elseif ($row['status'] === 'scheduled' || $row['status'] === 'list_pending') $posts['scheduled'] += (int)$row['cnt'];
                else $posts['draft'] += (int)$row['cnt'];
            }
        } catch (Exception $e) {}

        echo json_encode([
            'success'         => true,
            'daily'           => $daily,
            'monthly'         => $monthlyArr,
            'trends'          => $trends,
            'compared_months' => $comparedMonths,
            'period_totals'   => $periodTotals,
            'period_trends'   => $periodTrends,
            'last_sync'       => $lastDate,
            'total_days'      => count($daily),
            'seo'             => $seo,
            'reviews'         => $reviews,
            'location_info'   => $locationInfo,
            'posts'           => $posts,
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ====== FETCH STATS DEPUIS GOOGLE (GET request!) ======
    case 'fetch':
        $token = getValidGoogleToken($location['account_id']);
        if (!$token) {
            echo json_encode(['error' => 'Token Google expire. Reconnectez Google.']);
            exit;
        }

        $googleLocationId = $location['google_location_id'];
        $rawGoogleId = $googleLocationId;

        // Normaliser le path : doit etre "locations/XXXXX"
        if (strpos($googleLocationId, 'locations/') !== 0) {
            $numericId = preg_replace('/^(accounts\/\d+\/)?locations\//', '', $googleLocationId);
            $googleLocationId = 'locations/' . $numericId;
        }

        // Periode : 18 derniers mois
        $endDate = new DateTime('yesterday');
        $startDate = new DateTime('-18 months');

        // Appel GET vers l'API Performance Google
        $response = fetchGooglePerformanceStats($googleLocationId, $token, $startDate, $endDate);

        $httpCode = $response['_http_code'] ?? 0;
        $requestUrl = $response['_request_url'] ?? '';

        // Gestion des erreurs
        if (isset($response['error']) && isset($response['error']['message'])) {
            logAppError('Google API error: ' . $response['error']['message'], 'api_error', 'error', [
                'source' => 'stats.php', 'action' => 'fetch_stats',
                'user_id' => $user['id'], 'location_id' => $locationId,
                'context' => ['http_code' => $httpCode, 'google_error' => $response['error']],
            ]);
            echo json_encode(['error' => cleanErrorMessage('', 'stats')]);
            exit;
        }

        if ($httpCode >= 400) {
            logAppError("Google API HTTP {$httpCode}", 'api_error', 'error', [
                'source' => 'stats.php', 'action' => 'fetch_stats',
                'user_id' => $user['id'], 'location_id' => $locationId,
                'context' => ['http_code' => $httpCode, 'api_url' => $requestUrl],
            ]);
            echo json_encode(['error' => cleanErrorMessage('', 'stats')]);
            exit;
        }

        // Parser les time series avec debug detaille
        $timeSeries = $response['multiDailyMetricTimeSeries'] ?? [];

        // Debug : examiner la structure brute de la reponse
        $debugStructure = [];
        foreach ($timeSeries as $idx => $entry) {
            $entryDebug = [
                'index' => $idx,
                'keys' => array_keys($entry),
            ];
            // Format imbrique avec dailyMetricTimeSeries
            if (isset($entry['dailyMetricTimeSeries'])) {
                $entryDebug['format'] = 'NESTED (dailyMetricTimeSeries)';
                $entryDebug['inner_count'] = count($entry['dailyMetricTimeSeries']);
                foreach ($entry['dailyMetricTimeSeries'] as $j => $inner) {
                    $entryDebug['inner_' . $j] = [
                        'dailyMetric' => $inner['dailyMetric'] ?? 'MISSING',
                        'has_timeSeries' => isset($inner['timeSeries']),
                        'datedValues_count' => count($inner['timeSeries']['datedValues'] ?? []),
                    ];
                    if (!empty($inner['timeSeries']['datedValues'])) {
                        $entryDebug['inner_' . $j]['first_value'] = $inner['timeSeries']['datedValues'][0];
                    }
                    if ($j >= 2) break;
                }
            }
            // Format direct
            elseif (isset($entry['dailyMetric'])) {
                $entryDebug['format'] = 'DIRECT (dailyMetric at root)';
                $entryDebug['dailyMetric'] = $entry['dailyMetric'];
                $entryDebug['datedValues_count'] = count($entry['timeSeries']['datedValues'] ?? []);
            }
            $debugStructure[] = $entryDebug;
            if ($idx >= 1) break;
        }

        $dailyData = parseTimeSeries($response);

        // Stocker en base (UPSERT)
        $inserted = 0;
        $stmtUpsert = db()->prepare('
            INSERT INTO location_daily_stats
                (location_id, stat_date, impressions_search, impressions_maps, direction_requests, call_clicks, website_clicks, conversations, bookings)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                impressions_search = VALUES(impressions_search),
                impressions_maps = VALUES(impressions_maps),
                direction_requests = VALUES(direction_requests),
                call_clicks = VALUES(call_clicks),
                website_clicks = VALUES(website_clicks),
                conversations = VALUES(conversations),
                bookings = VALUES(bookings)
        ');

        foreach ($dailyData as $date => $metrics) {
            $stmtUpsert->execute([
                $locationId,
                $date,
                $metrics['impressions_search'],
                $metrics['impressions_maps'],
                $metrics['direction_requests'],
                $metrics['call_clicks'],
                $metrics['website_clicks'],
                $metrics['conversations'],
                $metrics['bookings'],
            ]);
            $inserted++;
        }

        echo json_encode([
            'success' => true,
            'days_synced' => $inserted,
            'date_range' => $startDate->format('Y-m-d') . ' → ' . $endDate->format('Y-m-d'),
            'message' => "{$inserted} jour(s) de statistiques synchronises.",
            '_debug' => [
                'google_location_id' => $googleLocationId,
                'raw_google_id' => $rawGoogleId,
                'api_url' => $requestUrl,
                'http_code' => $httpCode,
                'response_keys' => array_diff(array_keys($response), ['_http_code', '_request_url', '_curl_error']),
                'time_series_count' => count($timeSeries),
                'parsed_days' => count($dailyData),
                'method' => 'GET',
                'series_structure' => $debugStructure,
            ],
        ]);
        break;

    default:
        echo json_encode(['error' => 'Action inconnue: ' . $action]);
        break;
}
