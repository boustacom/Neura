<?php
/**
 * BOUS'TACOM — CRON Synchronisation des statistiques Google
 * Securise par token SHA-256
 * A configurer sur InfoManiak : quotidien a 06:00
 * URL: https://app.boustacom.fr/app/cron/sync-stats.php?token=XXXX
 *
 * Recupere les metrics Google Business Profile Performance
 * pour toutes les fiches actives et les stocke en base.
 *
 * IMPORTANT: L'API Performance v1 utilise des requetes GET (pas POST!)
 */

require_once __DIR__ . '/../config.php';

// ====== SECURITE ======
$expectedToken = hash('sha256', APP_SECRET . '_cron_sync_stats');
$providedToken = $_GET['token'] ?? '';

if (!hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Token invalide']);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
http_response_code(200);
set_time_limit(300);
ini_set('display_errors', 1);
error_reporting(E_ALL);
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "\n\n!!! FATAL ERROR: {$err['message']} in {$err['file']}:{$err['line']}\n";
    }
});

$now = new DateTime('now', new DateTimeZone('Europe/Paris'));
echo "=== CRON Sync Stats — " . $now->format('Y-m-d H:i:s') . " ===\n\n";

// ====== AUTO-MIGRATION ======
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
    // Table existe deja
}

/**
 * Appel GET vers l'API Google Business Profile Performance v1
 */
function fetchGooglePerformanceStatsForCron(string $googleLocationId, string $token, DateTime $startDate, DateTime $endDate): array {
    $params = [
        'dailyRange.startDate.year'  => (int)$startDate->format('Y'),
        'dailyRange.startDate.month' => (int)$startDate->format('m'),
        'dailyRange.startDate.day'   => (int)$startDate->format('d'),
        'dailyRange.endDate.year'    => (int)$endDate->format('Y'),
        'dailyRange.endDate.month'   => (int)$endDate->format('m'),
        'dailyRange.endDate.day'     => (int)$endDate->format('d'),
    ];

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
        CURLOPT_HTTPGET        => true,
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
    if ($curlError) $decoded['_curl_error'] = $curlError;

    return $decoded;
}

// ====== RECUPERER TOUS LES COMPTES ======
$stmt = db()->query('
    SELECT a.id as account_id, a.google_account_name, a.access_token, a.refresh_token,
           l.id as location_id, l.google_location_id, l.name
    FROM gbp_locations l
    JOIN gbp_accounts a ON l.gbp_account_id = a.id
    WHERE l.is_active = 1 AND a.access_token IS NOT NULL
    ORDER BY a.id, l.id
');
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Fiches actives : " . count($locations) . "\n\n";

$totalSynced = 0;
$totalErrors = 0;
$processedAccounts = [];

foreach ($locations as $loc) {
    $accountId = $loc['account_id'];

    // Obtenir un token valide (une seule fois par compte)
    if (!isset($processedAccounts[$accountId])) {
        $processedAccounts[$accountId] = getValidGoogleToken($accountId);
    }
    $token = $processedAccounts[$accountId];

    if (!$token) {
        logAppError("Token invalide pour le compte #{$accountId}", 'auth_error', 'warning', [
            'source' => 'cron/sync-stats.php', 'action' => 'sync_stats', 'location_id' => $loc['location_id'] ?? null,
        ]);
        echo "  ! {$loc['name']} : Token invalide\n";
        $totalErrors++;
        continue;
    }

    // Normaliser le path : doit etre "locations/XXXXX"
    $googleLocationId = $loc['google_location_id'];
    if (strpos($googleLocationId, 'locations/') !== 0) {
        $googleLocationId = 'locations/' . preg_replace('/^accounts\/\d+\/locations\//', '', $googleLocationId);
    }

    // Periode : 18 derniers mois (historique complet)
    $endDate = new DateTime('yesterday');
    $startDate = new DateTime('-18 months');

    // Appel GET vers l'API Performance
    $response = fetchGooglePerformanceStatsForCron($googleLocationId, $token, $startDate, $endDate);

    $httpCode = $response['_http_code'] ?? 0;

    if (isset($response['error'])) {
        $errMsg = $response['error']['message'] ?? 'Erreur API (HTTP ' . $httpCode . ')';
        logAppError("Stats sync API error: {$errMsg}", 'api_error', 'error', [
            'source' => 'cron/sync-stats.php', 'action' => 'sync_stats', 'location_id' => $loc['location_id'] ?? null,
            'context' => ['http_code' => $httpCode],
        ]);
        echo "  ! {$loc['name']} : {$errMsg}\n";
        $totalErrors++;
        usleep(300000);
        continue;
    }

    if ($httpCode >= 400) {
        logAppError("Stats sync HTTP {$httpCode}", 'api_error', 'error', [
            'source' => 'cron/sync-stats.php', 'action' => 'sync_stats', 'location_id' => $loc['location_id'] ?? null,
            'context' => ['http_code' => $httpCode],
        ]);
        echo "  ! {$loc['name']} : HTTP {$httpCode}\n";
        $totalErrors++;
        usleep(300000);
        continue;
    }

    // Parser les time series (gerer format imbrique ET direct)
    $dailyData = [];
    $multiSeries = $response['multiDailyMetricTimeSeries'] ?? [];

    // Aplatir : extraire toutes les series individuelles
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
        $dataPoints = $series['timeSeries']['datedValues'] ?? [];
        if (empty($dataPoints) && isset($series['dailySubEntityType']['timeSeries']['datedValues'])) {
            $dataPoints = $series['dailySubEntityType']['timeSeries']['datedValues'];
        }

        foreach ($dataPoints as $point) {
            if (!isset($point['date']['year'])) continue;
            $date = sprintf('%04d-%02d-%02d', $point['date']['year'], $point['date']['month'], $point['date']['day']);
            if (!isset($dailyData[$date])) {
                $dailyData[$date] = [
                    'impressions_search' => 0, 'impressions_maps' => 0,
                    'direction_requests' => 0, 'call_clicks' => 0,
                    'website_clicks' => 0, 'conversations' => 0, 'bookings' => 0,
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

    // UPSERT en base
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

    $days = 0;
    foreach ($dailyData as $date => $metrics) {
        $stmtUpsert->execute([
            $loc['location_id'], $date,
            $metrics['impressions_search'], $metrics['impressions_maps'],
            $metrics['direction_requests'], $metrics['call_clicks'],
            $metrics['website_clicks'], $metrics['conversations'], $metrics['bookings'],
        ]);
        $days++;
    }

    echo "  OK {$loc['name']} : {$days} jours syncs\n";
    $totalSynced++;

    usleep(500000); // 0.5s rate limit
}

echo "\n=== Termine : {$totalSynced} fiches syncs, {$totalErrors} erreur(s) ===\n";
echo json_encode(['synced' => $totalSynced, 'errors' => $totalErrors]);
