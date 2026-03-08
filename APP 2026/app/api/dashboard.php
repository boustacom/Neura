<?php
/**
 * NEURA — API Dashboard v4
 * Retourne toutes les données du dashboard pour une période donnée.
 * Paramètre GET : period = 7 | 30 | 90 | 180 (défaut: 30)
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();
requireCsrf();

header('Content-Type: application/json');

$user = currentUser();
$period = (int)($_GET['period'] ?? 30);
$allowedPeriods = [7, 30, 90, 180];
if (!in_array($period, $allowedPeriods)) {
    $period = 30;
}

// Labels
$periodLabels = [7 => '7 derniers jours', 30 => '30 derniers jours', 90 => '90 derniers jours', 180 => '6 derniers mois'];

// ====== FICHES ACTIVES ======
$stmt = db()->prepare('
    SELECT l.id, l.name, l.city, l.category
    FROM gbp_locations l
    JOIN gbp_accounts a ON l.gbp_account_id = a.id
    WHERE a.user_id = ? AND l.is_active = 1
    ORDER BY l.name
');
$stmt->execute([$user['id']]);
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($locations)) {
    jsonResponse([
        'period' => $period,
        'period_label' => $periodLabels[$period],
        'empty' => true,
        'kpis' => [],
        'position_distrib' => ['top3' => 0, 'top10' => 0, 'top20' => 0, 'out20' => 0, 'total' => 0],
        'health' => ['good' => 0, 'mid' => 0, 'low' => 0],
        'publications' => [],
        'recent_reviews' => [],
        'alerts' => [],
        'overview' => ['total_locations' => 0, 'total_keywords' => 0, 'total_reviews' => 0],
    ]);
}

$locationIds = array_column($locations, 'id');
$placeholders = implode(',', array_fill(0, count($locationIds), '?'));

// ====== 1. REQUÊTE AGRÉGÉE FICHES (snapshot, pas de période) ======
$stmt = db()->prepare("
    SELECT
        l.id, l.name, l.city,
        COALESCE(kw.keyword_count, 0) as keyword_count,
        kw.avg_position,
        COALESCE(kw.top3_count, 0) as top3_count,
        COALESCE(rv.review_count, 0) as review_count,
        COALESCE(rv.unanswered_count, 0) as unanswered_count,
        COALESCE(rv.negative_unanswered, 0) as negative_unanswered,
        rv.avg_rating,
        COALESCE(gp.scheduled_count, 0) as scheduled_count,
        COALESCE(gp.published_period, 0) as published_period,
        COALESCE(gp.published_prev, 0) as published_prev,
        COALESCE(gp.failed_count, 0) as failed_count
    FROM gbp_locations l
    LEFT JOIN (
        SELECT k.location_id,
               COUNT(*) as keyword_count,
               ROUND(AVG(kp_latest.position), 1) as avg_position,
               SUM(CASE WHEN kp_latest.position IS NOT NULL AND kp_latest.position <= 3 THEN 1 ELSE 0 END) as top3_count
        FROM keywords k
        LEFT JOIN (
            SELECT kp.keyword_id, kp.position
            FROM keyword_positions kp
            INNER JOIN (
                SELECT keyword_id, MAX(tracked_at) as max_date
                FROM keyword_positions
                GROUP BY keyword_id
            ) kp_max ON kp.keyword_id = kp_max.keyword_id AND kp.tracked_at = kp_max.max_date
        ) kp_latest ON kp_latest.keyword_id = k.id
        WHERE k.is_active = 1
        GROUP BY k.location_id
    ) kw ON kw.location_id = l.id
    LEFT JOIN (
        SELECT location_id,
               COUNT(*) as review_count,
               SUM(CASE WHEN is_replied = 0 THEN 1 ELSE 0 END) as unanswered_count,
               SUM(CASE WHEN is_replied = 0 AND rating <= 2 THEN 1 ELSE 0 END) as negative_unanswered,
               ROUND(AVG(rating), 1) as avg_rating
        FROM reviews
        GROUP BY location_id
    ) rv ON rv.location_id = l.id
    LEFT JOIN (
        SELECT location_id,
               SUM(CASE WHEN status = 'scheduled' OR status = 'list_pending' THEN 1 ELSE 0 END) as scheduled_count,
               SUM(CASE WHEN status = 'published' AND published_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY) THEN 1 ELSE 0 END) as published_period,
               SUM(CASE WHEN status = 'published' AND published_at >= DATE_SUB(NOW(), INTERVAL " . ($period * 2) . " DAY) AND published_at < DATE_SUB(NOW(), INTERVAL {$period} DAY) THEN 1 ELSE 0 END) as published_prev,
               SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count
        FROM google_posts
        GROUP BY location_id
    ) gp ON gp.location_id = l.id
    WHERE l.id IN ({$placeholders})
    ORDER BY l.name
");
$stmt->execute($locationIds);
$dashData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ====== Calculer stats globales + santé ======
$totalKeywords = 0;
$totalUnanswered = 0;
$totalTop3 = 0;
$totalReviews = 0;
$totalNegUnanswered = 0;
$totalFailed = 0;
$totalScheduled = 0;
$totalPublishedPeriod = 0;
$totalPublishedPrev = 0;
$allRatings = [];
$healthGood = 0;
$healthMid = 0;
$healthLow = 0;

foreach ($dashData as $d) {
    $totalKeywords += (int)$d['keyword_count'];
    $totalUnanswered += (int)$d['unanswered_count'];
    $totalTop3 += (int)$d['top3_count'];
    $totalReviews += (int)$d['review_count'];
    $totalNegUnanswered += (int)$d['negative_unanswered'];
    $totalFailed += (int)$d['failed_count'];
    $totalScheduled += (int)$d['scheduled_count'];
    $totalPublishedPeriod += (int)$d['published_period'];
    $totalPublishedPrev += (int)$d['published_prev'];
    if ($d['avg_rating']) $allRatings[] = (float)$d['avg_rating'];

    // Score de santé (0-3 points)
    $health = 0;
    $pos = $d['avg_position'] ? (float)$d['avg_position'] : 99;
    if ($pos <= 5) $health++;
    elseif ($pos <= 10) $health += 0.5;
    if ($d['unanswered_count'] == 0) $health++;
    elseif ($d['unanswered_count'] <= 2) $health += 0.5;
    if ($d['published_period'] > 0 || $d['scheduled_count'] > 0) $health++;

    if ($health >= 2.5) $healthGood++;
    elseif ($health >= 1.5) $healthMid++;
    else $healthLow++;
}

$avgPositionGlobal = 0;
$allPositions = array_filter(array_column($dashData, 'avg_position'), fn($p) => $p !== null);
if ($allPositions) {
    $avgPositionGlobal = round(array_sum($allPositions) / count($allPositions), 1);
}
$avgRatingGlobal = $allRatings ? round(array_sum($allRatings) / count($allRatings), 1) : 0;

// ====== 2. DISTRIBUTION POSITIONS (snapshot actuel) ======
$posDistrib = ['top3' => 0, 'top10' => 0, 'top20' => 0, 'out20' => 0];
try {
    $stmtDist = db()->prepare("
        SELECT
            SUM(CASE WHEN kp.position <= 3 THEN 1 ELSE 0 END) as top3,
            SUM(CASE WHEN kp.position > 3 AND kp.position <= 10 THEN 1 ELSE 0 END) as top10,
            SUM(CASE WHEN kp.position > 10 AND kp.position <= 20 THEN 1 ELSE 0 END) as top20,
            SUM(CASE WHEN kp.position > 20 OR kp.position IS NULL THEN 1 ELSE 0 END) as out20
        FROM keywords k
        LEFT JOIN (
            SELECT kp1.keyword_id, kp1.position
            FROM keyword_positions kp1
            INNER JOIN (SELECT keyword_id, MAX(tracked_at) as max_date FROM keyword_positions GROUP BY keyword_id) kp2
            ON kp1.keyword_id = kp2.keyword_id AND kp1.tracked_at = kp2.max_date
        ) kp ON kp.keyword_id = k.id
        WHERE k.location_id IN ({$placeholders}) AND k.is_active = 1
    ");
    $stmtDist->execute($locationIds);
    $dist = $stmtDist->fetch(PDO::FETCH_ASSOC);
    if ($dist) {
        $posDistrib = [
            'top3' => (int)$dist['top3'],
            'top10' => (int)$dist['top10'],
            'top20' => (int)$dist['top20'],
            'out20' => (int)$dist['out20'],
        ];
    }
} catch (Exception $e) {}

// ====== 3. TENDANCES POSITIONS : période vs période précédente ======
$positionTrend = ['avg_current' => 0, 'avg_prev' => 0, 'top3_current' => 0, 'top3_prev' => 0];
try {
    $stmtTrend = db()->prepare("
        SELECT
            ROUND(AVG(CASE WHEN kp.tracked_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY) THEN kp.position END), 1) as avg_current,
            ROUND(AVG(CASE WHEN kp.tracked_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND kp.tracked_at < DATE_SUB(CURDATE(), INTERVAL ? DAY) THEN kp.position END), 1) as avg_prev,
            SUM(CASE WHEN kp.tracked_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND kp.position <= 3 THEN 1 ELSE 0 END) as top3_current,
            SUM(CASE WHEN kp.tracked_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND kp.tracked_at < DATE_SUB(CURDATE(), INTERVAL ? DAY) AND kp.position <= 3 THEN 1 ELSE 0 END) as top3_prev
        FROM keyword_positions kp
        JOIN keywords k ON kp.keyword_id = k.id
        WHERE k.location_id IN ({$placeholders}) AND k.is_active = 1
        AND kp.tracked_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    ");
    // Params: period, period*2, period, period, period*2, period, period*2
    $trendParams = [$period, $period * 2, $period, $period, $period * 2, $period, $period * 2];
    $stmtTrend->execute(array_merge($trendParams, $locationIds));
    $trend = $stmtTrend->fetch(PDO::FETCH_ASSOC);
    if ($trend) {
        $positionTrend['avg_current'] = $trend['avg_current'] ? (float)$trend['avg_current'] : 0;
        $positionTrend['avg_prev'] = $trend['avg_prev'] ? (float)$trend['avg_prev'] : 0;
        $positionTrend['top3_current'] = (int)$trend['top3_current'];
        $positionTrend['top3_prev'] = (int)$trend['top3_prev'];
    }
} catch (Exception $e) {}

// ====== 4. TENDANCES AVIS : période vs période précédente ======
$reviewTrend = ['new_current' => 0, 'new_prev' => 0, 'avg_current' => 0, 'avg_prev' => 0];
try {
    $stmtReviewTrend = db()->prepare("
        SELECT
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) THEN 1 ELSE 0 END) as new_current,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY) THEN 1 ELSE 0 END) as new_prev,
            ROUND(AVG(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) THEN rating END), 1) as avg_current,
            ROUND(AVG(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY) THEN rating END), 1) as avg_prev
        FROM reviews
        WHERE location_id IN ({$placeholders})
    ");
    // Params: period, period*2, period, period, period*2, period
    $reviewParams = [$period, $period * 2, $period, $period, $period * 2, $period];
    $stmtReviewTrend->execute(array_merge($reviewParams, $locationIds));
    $revTrend = $stmtReviewTrend->fetch(PDO::FETCH_ASSOC);
    if ($revTrend) {
        $reviewTrend['new_current'] = (int)($revTrend['new_current'] ?? 0);
        $reviewTrend['new_prev'] = (int)($revTrend['new_prev'] ?? 0);
        $reviewTrend['avg_current'] = $revTrend['avg_current'] ? (float)$revTrend['avg_current'] : 0;
        $reviewTrend['avg_prev'] = $revTrend['avg_prev'] ? (float)$revTrend['avg_prev'] : 0;
    }
} catch (Exception $e) {}

// ====== 5. PERFORMANCE GOOGLE : avec décalage de 3 jours ======
// Google a un lag de 2-3 jours → on compare des périodes complètes décalées
$lag = 3;
$perfData = [];
try {
    $stmtPerf = db()->prepare("
        SELECT
            SUM(CASE WHEN stat_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND stat_date < DATE_SUB(CURDATE(), INTERVAL ? DAY) THEN impressions_search + impressions_maps ELSE 0 END) as impr_curr,
            SUM(CASE WHEN stat_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND stat_date < DATE_SUB(CURDATE(), INTERVAL ? DAY) THEN impressions_search + impressions_maps ELSE 0 END) as impr_prev,
            SUM(CASE WHEN stat_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND stat_date < DATE_SUB(CURDATE(), INTERVAL ? DAY) THEN direction_requests ELSE 0 END) as dir_curr,
            SUM(CASE WHEN stat_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND stat_date < DATE_SUB(CURDATE(), INTERVAL ? DAY) THEN direction_requests ELSE 0 END) as dir_prev,
            SUM(CASE WHEN stat_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND stat_date < DATE_SUB(CURDATE(), INTERVAL ? DAY) THEN call_clicks ELSE 0 END) as calls_curr,
            SUM(CASE WHEN stat_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND stat_date < DATE_SUB(CURDATE(), INTERVAL ? DAY) THEN call_clicks ELSE 0 END) as calls_prev,
            SUM(CASE WHEN stat_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND stat_date < DATE_SUB(CURDATE(), INTERVAL ? DAY) THEN website_clicks ELSE 0 END) as web_curr,
            SUM(CASE WHEN stat_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND stat_date < DATE_SUB(CURDATE(), INTERVAL ? DAY) THEN website_clicks ELSE 0 END) as web_prev,
            SUM(CASE WHEN stat_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND stat_date < DATE_SUB(CURDATE(), INTERVAL ? DAY) THEN conversations ELSE 0 END) as conv_curr,
            SUM(CASE WHEN stat_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND stat_date < DATE_SUB(CURDATE(), INTERVAL ? DAY) THEN conversations ELSE 0 END) as conv_prev,
            SUM(CASE WHEN stat_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND stat_date < DATE_SUB(CURDATE(), INTERVAL ? DAY) THEN bookings ELSE 0 END) as book_curr,
            SUM(CASE WHEN stat_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND stat_date < DATE_SUB(CURDATE(), INTERVAL ? DAY) THEN bookings ELSE 0 END) as book_prev,
            MAX(stat_date) as last_stat_date
        FROM location_daily_stats
        WHERE location_id IN ({$placeholders}) AND stat_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    ");
    // Période courante : J-(period+lag) à J-lag
    // Période précédente : J-(period*2+lag) à J-(period+lag)
    $pCurrStart = $period + $lag;   // ex: 30+3 = 33
    $pCurrEnd = $lag;               // ex: 3
    $pPrevStart = $period * 2 + $lag; // ex: 63
    $pPrevEnd = $period + $lag;     // ex: 33

    $perfParams = [];
    // 6 metrics × 2 params each for curr + 6 metrics × 2 params each for prev = 24 params
    for ($i = 0; $i < 6; $i++) {
        $perfParams[] = $pCurrStart;
        $perfParams[] = $pCurrEnd;
    }
    for ($i = 0; $i < 6; $i++) {
        $perfParams[] = $pPrevStart;
        $perfParams[] = $pPrevEnd;
    }
    // Params interleaved: curr_start, curr_end, prev_start, prev_end × 6 metrics
    $perfParams = [];
    for ($i = 0; $i < 6; $i++) {
        $perfParams[] = $pCurrStart;
        $perfParams[] = $pCurrEnd;
        $perfParams[] = $pPrevStart;
        $perfParams[] = $pPrevEnd;
    }

    $stmtPerf->execute(array_merge($perfParams, $locationIds, [$pPrevStart]));
    $perf = $stmtPerf->fetch(PDO::FETCH_ASSOC);
    if ($perf) {
        $perfData = [
            'impressions' => ['value' => (int)($perf['impr_curr'] ?? 0), 'prev' => (int)($perf['impr_prev'] ?? 0)],
            'direction_requests' => ['value' => (int)($perf['dir_curr'] ?? 0), 'prev' => (int)($perf['dir_prev'] ?? 0)],
            'call_clicks' => ['value' => (int)($perf['calls_curr'] ?? 0), 'prev' => (int)($perf['calls_prev'] ?? 0)],
            'website_clicks' => ['value' => (int)($perf['web_curr'] ?? 0), 'prev' => (int)($perf['web_prev'] ?? 0)],
            'conversations' => ['value' => (int)($perf['conv_curr'] ?? 0), 'prev' => (int)($perf['conv_prev'] ?? 0)],
            'bookings' => ['value' => (int)($perf['book_curr'] ?? 0), 'prev' => (int)($perf['book_prev'] ?? 0)],
            'last_date' => $perf['last_stat_date'] ?? null,
        ];
        // Ajouter delta_pct et trend
        foreach ($perfData as $key => &$metric) {
            if ($key === 'last_date') continue;
            $v = $metric['value'];
            $p = $metric['prev'];
            $metric['delta_pct'] = $p > 0 ? round(($v - $p) / $p * 100) : ($v > 0 ? 100 : 0);
            $metric['trend'] = $v > $p ? 'up' : ($v < $p ? 'down' : 'stable');
        }
        unset($metric);
    }
} catch (Exception $e) {}

// ====== 6. DERNIERS AVIS (5 max, temps réel) ======
$recentReviews = [];
try {
    $stmtRecent = db()->prepare("
        SELECT r.rating, r.comment, r.reviewer_name, r.is_replied, r.created_at, l.name as location_name, l.id as location_id
        FROM reviews r
        JOIN gbp_locations l ON r.location_id = l.id
        WHERE r.location_id IN ({$placeholders})
        ORDER BY r.created_at DESC
        LIMIT 5
    ");
    $stmtRecent->execute($locationIds);
    $recentReviews = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ====== 7. ALERTES (temps réel, pas de période) ======
$alerts = [];

if ($totalNegUnanswered > 0) {
    $alerts[] = [
        'type' => 'danger',
        'icon' => "\u{26A0}",
        'text' => $totalNegUnanswered . ' avis négatif' . ($totalNegUnanswered > 1 ? 's' : '') . ' sans réponse',
        'sub' => 'Impact direct sur la réputation — répondez en priorité',
        'action' => '?view=reviews-all',
        'action_label' => 'Traiter maintenant',
    ];
}
if ($totalUnanswered > 0 && $totalNegUnanswered == 0) {
    $alerts[] = [
        'type' => 'warning',
        'icon' => "\u{1F4AC}",
        'text' => $totalUnanswered . ' avis en attente de réponse',
        'sub' => 'Les réponses rapides améliorent votre classement local',
        'action' => '?view=reviews-all',
        'action_label' => 'Répondre',
    ];
}
if ($totalFailed > 0) {
    $firstFailed = null;
    foreach ($dashData as $d) {
        if ((int)$d['failed_count'] > 0) { $firstFailed = $d; break; }
    }
    $failedAction = $firstFailed
        ? '?view=client&location=' . $firstFailed['id'] . '&tab=posts&post_status=failed'
        : '?view=locations';
    $alerts[] = [
        'type' => 'danger',
        'icon' => "\u{274C}",
        'text' => $totalFailed . ' post' . ($totalFailed > 1 ? 's' : '') . ' en erreur de publication',
        'sub' => 'Vérifiez les contenus et relancez la publication',
        'action' => $failedAction,
        'action_label' => 'Voir les erreurs',
    ];
}

// Alerte chute de position
$positionDelta = ($positionTrend['avg_current'] && $positionTrend['avg_prev'])
    ? round($positionTrend['avg_prev'] - $positionTrend['avg_current'], 1)
    : 0;
if ($positionDelta < -1) {
    $alerts[] = [
        'type' => 'warning',
        'icon' => "\u{1F4C9}",
        'text' => 'Position moyenne en baisse de ' . abs($positionDelta) . ' places',
        'sub' => 'Vérifiez vos mots-clés et la fréquence de vos publications',
        'action' => '?view=locations',
        'action_label' => 'Analyser',
    ];
}

// Fiches sans activité posts récente
$inactivePostClients = array_filter($dashData, fn($d) => $d['published_period'] == 0 && $d['scheduled_count'] == 0 && $d['keyword_count'] > 0);
if (count($inactivePostClients) > 0) {
    $nbInactive = count($inactivePostClients);
    if ($nbInactive === 1) {
        $firstInactive = $inactivePostClients[array_key_first($inactivePostClients)];
        $inactiveAction = '?view=client&location=' . $firstInactive['id'] . '&tab=posts';
    } else {
        $inactiveAction = '?view=locations';
    }
    $alerts[] = [
        'type' => 'info',
        'icon' => "\u{1F4DD}",
        'text' => $nbInactive . ' fiche' . ($nbInactive > 1 ? 's' : '') . ' sans post récemment',
        'sub' => 'La régularité de publication booste la visibilité locale',
        'action' => $inactiveAction,
        'action_label' => 'Publier',
    ];
}

// Fiches avec note < 4
$lowRatedClients = array_filter($dashData, fn($d) => $d['avg_rating'] && $d['avg_rating'] < 4.0);
if (count($lowRatedClients) > 0) {
    $nbLowRated = count($lowRatedClients);
    if ($nbLowRated === 1) {
        $firstLow = $lowRatedClients[array_key_first($lowRatedClients)];
        $lowAction = '?view=client&location=' . $firstLow['id'] . '&tab=reviews';
    } else {
        $lowAction = '?view=reviews-all';
    }
    $alerts[] = [
        'type' => 'info',
        'icon' => "\u{2B50}",
        'text' => $nbLowRated . ' fiche' . ($nbLowRated > 1 ? 's' : '') . ' avec une note inférieure à 4.0',
        'sub' => 'Sollicitez vos clients satisfaits pour améliorer la note',
        'action' => $lowAction,
        'action_label' => 'Voir les avis',
    ];
}

// ====== 8. PUBLICATIONS agrégées ======
$activeLocationsWithPosts = count(array_filter($dashData, fn($d) => $d['published_period'] > 0 || $d['scheduled_count'] > 0));
$coveragePct = count($dashData) > 0 ? round(($activeLocationsWithPosts / count($dashData)) * 100) : 0;

$publications = [
    'published_period' => $totalPublishedPeriod,
    'published_prev' => $totalPublishedPrev,
    'scheduled' => $totalScheduled,
    'failed' => $totalFailed,
    'coverage_pct' => $coveragePct,
    'active_locations' => $activeLocationsWithPosts,
    'total_locations' => count($dashData),
];

// ====== CONSTRUIRE LES KPIs ======
$kpis = [];

// Position moyenne
$kpis['avg_position'] = [
    'value' => $avgPositionGlobal,
    'prev' => $positionTrend['avg_prev'],
    'delta' => $positionDelta,
    'trend' => $positionDelta > 0 ? 'up' : ($positionDelta < 0 ? 'down' : 'stable'),
];

// Top 3
$top3Delta = $positionTrend['top3_current'] - $positionTrend['top3_prev'];
$kpis['top3_count'] = [
    'value' => $posDistrib['top3'],
    'prev' => $positionTrend['top3_prev'],
    'total' => $totalKeywords,
    'delta' => $top3Delta,
    'trend' => $top3Delta > 0 ? 'up' : ($top3Delta < 0 ? 'down' : 'stable'),
];

// Note moyenne
$ratingDelta = ($reviewTrend['avg_current'] && $reviewTrend['avg_prev'])
    ? round($reviewTrend['avg_current'] - $reviewTrend['avg_prev'], 1) : 0;
$kpis['avg_rating'] = [
    'value' => $avgRatingGlobal,
    'prev' => $reviewTrend['avg_prev'],
    'delta' => $ratingDelta,
    'trend' => $ratingDelta > 0 ? 'up' : ($ratingDelta < 0 ? 'down' : 'stable'),
];

// Avis sans réponse (action card mais dans les KPIs)
$kpis['unanswered_reviews'] = [
    'value' => $totalUnanswered,
    'new_period' => $reviewTrend['new_current'],
    'new_prev' => $reviewTrend['new_prev'],
    'is_action' => true,
];

// ====== RÉPONSE JSON ======
jsonResponse([
    'period' => $period,
    'period_label' => $periodLabels[$period],
    'kpis' => $kpis,
    'performance' => $perfData,
    'position_distrib' => array_merge($posDistrib, ['total' => $totalKeywords]),
    'health' => ['good' => $healthGood, 'mid' => $healthMid, 'low' => $healthLow],
    'publications' => $publications,
    'recent_reviews' => $recentReviews,
    'alerts' => $alerts,
    'overview' => [
        'total_locations' => count($locations),
        'total_keywords' => $totalKeywords,
        'total_reviews' => $totalReviews,
    ],
]);
