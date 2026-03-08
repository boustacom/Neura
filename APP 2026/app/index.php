<?php
/**
 * BOUS'TACOM — Dashboard Principal
 * Architecture multi-vues : dashboard / client / reviews-all / reports
 */
require_once __DIR__ . '/config.php';
startSecureSession();

// ====== MODE MAINTENANCE ======
// Si le fichier maintenance.flag existe, bloquer les utilisateurs non-admin
$maintenanceMode = file_exists(__DIR__ . '/maintenance.flag');
if ($maintenanceMode && ($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(503);
    header('Retry-After: 600');
    include __DIR__ . '/views/maintenance.php';
    exit;
}

// Pages legales accessibles sans authentification
$publicViews = ['legal', 'privacy', 'cgu'];
$requestedView = $_GET['view'] ?? '';
if (in_array($requestedView, $publicViews)) {
    $publicViewMap = [
        'legal'   => 'views/legal.php',
        'privacy' => 'views/privacy.php',
        'cgu'     => 'views/cgu.php',
    ];
    // Afficher la page legale dans un layout minimal
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . ucfirst($requestedView) . ' — Neura</title>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="' . APP_URL . '/assets/css/style.css?v=' . filemtime(__DIR__ . '/assets/css/style.css') . '">';
    echo '<script>(function(){var t=localStorage.getItem(\'boustacom_theme\')||\'dark\';document.documentElement.setAttribute(\'data-theme\',t);})()</script>';
    echo '</head><body style="padding:24px;max-width:900px;margin:0 auto;">';
    include __DIR__ . '/' . $publicViewMap[$requestedView];
    echo '<div style="text-align:center;margin:32px 0;"><a href="' . APP_URL . '/auth/login.php" style="color:var(--acc);font-size:13px;text-decoration:none;">&larr; Retour a la connexion</a></div>';
    echo '</body></html>';
    exit;
}

requireLogin();

$user = currentUser();

// ====== ROUTING ======
$view = $_GET['view'] ?? null;

// Rétrocompatibilité : anciennes URLs ?tab=xxx&location=Y
if (!$view && isset($_GET['tab'])) {
    if (isset($_GET['location'])) {
        header('Location: ?view=client&location=' . urlencode($_GET['location']) . '&tab=' . urlencode($_GET['tab']));
        exit;
    }
    // tab sans location → dashboard
    $view = 'dashboard';
}

if (!$view) {
    $view = 'dashboard';
}

// ====== RÉCUPÉRER TOUTES LES FICHES GBP ======
$stmt = db()->prepare('
    SELECT l.*, a.id as account_id
    FROM gbp_locations l
    JOIN gbp_accounts a ON l.gbp_account_id = a.id
    WHERE a.user_id = ? AND l.is_active = 1
    ORDER BY l.name
');
$stmt->execute([$user['id']]);
$locations = $stmt->fetchAll();

// ====== DONNÉES SPÉCIFIQUES PAR VUE ======

// --- VUE CLIENT ---
$selectedLocationId = null;
$selectedLocation = null;
$tab = 'keywords';
$stats = ['keywords' => 0, 'avg_position' => 0, 'avg_rank' => 0, 'top3' => 0, 'out20' => 0, 'reviews_total' => 0, 'reviews_unanswered' => 0, 'avg_rating' => 0];

if ($view === 'client') {
    $selectedLocationId = $_GET['location'] ?? null;
    $tab = $_GET['tab'] ?? 'keywords';
    // Retrocompat: grid tab redirects to position-map
    if ($tab === 'grid') $tab = 'position-map';

    foreach ($locations as $loc) {
        if ($loc['id'] == $selectedLocationId) {
            $selectedLocation = $loc;
            break;
        }
    }

    if (!$selectedLocation && $locations) {
        $selectedLocation = $locations[0];
        $selectedLocationId = $selectedLocation['id'];
    }

    if ($selectedLocationId) {
        // Keywords count
        $s = db()->prepare('SELECT COUNT(*) FROM keywords WHERE location_id = ? AND is_active = 1');
        $s->execute([$selectedLocationId]);
        $stats['keywords'] = $s->fetchColumn();

        // Positions (latest)
        $s = db()->prepare('
            SELECT kp.position, kp.in_local_pack
            FROM keyword_positions kp
            JOIN keywords k ON kp.keyword_id = k.id
            WHERE k.location_id = ? AND kp.tracked_at = (
                SELECT MAX(tracked_at) FROM keyword_positions kp2 WHERE kp2.keyword_id = k.id
            )
        ');
        $s->execute([$selectedLocationId]);
        $positions = $s->fetchAll();

        if ($positions) {
            $posValues = array_column($positions, 'position');
            $stats['avg_position'] = round(array_sum($posValues) / count($posValues), 1);
            $stats['top3'] = count(array_filter($posValues, fn($p) => $p <= 3));
            $stats['out20'] = count(array_filter($posValues, fn($p) => $p > 20));
        }

        // Rang Localo moyen (basé sur les grilles) — c'est le classement parmi les concurrents
        // On prend le dernier scan de chaque mot-clé et on récupère le rang de la fiche cible
        try {
            $stmtRanks = db()->prepare('
                SELECT gs.id as scan_id, gs.keyword_id, gs.total_points
                FROM grid_scans gs
                JOIN keywords k ON gs.keyword_id = k.id
                WHERE k.location_id = ? AND k.is_active = 1
                AND gs.scanned_at = (
                    SELECT MAX(gs2.scanned_at) FROM grid_scans gs2 WHERE gs2.keyword_id = gs.keyword_id
                )
            ');
            $stmtRanks->execute([$selectedLocationId]);
            $latestScans = $stmtRanks->fetchAll();

            if ($latestScans) {
                $googleCid = $selectedLocation['google_cid'] ?? '';
                $locPlaceId = $selectedLocation['place_id'] ?? '';
                $ranks = [];

                foreach ($latestScans as $ls) {
                    $scanId = $ls['scan_id'];
                    $totalPts = (int)$ls['total_points'];

                    // Charger tous les concurrents de ce scan et agréger en PHP
                    $stmtC = db()->prepare('SELECT grid_point_id, position, data_cid, place_id, title FROM grid_competitors WHERE grid_scan_id = ?');
                    $stmtC->execute([$scanId]);
                    $allC = $stmtC->fetchAll();

                    $grouped = [];
                    foreach ($allC as $c) {
                        $key = !empty($c['data_cid']) ? $c['data_cid'] : (!empty($c['place_id']) ? $c['place_id'] : $c['title']);
                        if (!isset($grouped[$key])) $grouped[$key] = ['data_cid' => $c['data_cid'], 'place_id' => $c['place_id'], 'points' => []];
                        $ptId = $c['grid_point_id'];
                        $pos = (int)$c['position'];
                        if (!isset($grouped[$key]['points'][$ptId]) || $pos < $grouped[$key]['points'][$ptId]) {
                            $grouped[$key]['points'][$ptId] = $pos;
                        }
                    }

                    // Calculer les positions moyennes Localo et trier
                    $avgs = [];
                    foreach ($grouped as $key => $comp) {
                        $appearances = count($comp['points']);
                        $sumPos = array_sum($comp['points']);
                        $missing = max(0, $totalPts - $appearances);
                        $localoAvg = $totalPts > 0 ? ($sumPos + 21 * $missing) / $totalPts : 21;
                        $avgs[] = ['key' => $key, 'avg' => $localoAvg, 'data_cid' => $comp['data_cid'], 'place_id' => $comp['place_id']];
                    }
                    usort($avgs, fn($a, $b) => $a['avg'] <=> $b['avg']);

                    // Trouver le rang de notre fiche
                    foreach ($avgs as $idx => $a) {
                        $isTarget = ($googleCid && $a['data_cid'] && $a['data_cid'] === $googleCid)
                                 || ($locPlaceId && $a['place_id'] && $a['place_id'] === $locPlaceId);
                        if ($isTarget) {
                            $ranks[] = $idx + 1;
                            break;
                        }
                    }
                }

                if ($ranks) {
                    $stats['avg_rank'] = round(array_sum($ranks) / count($ranks), 1);
                }
            }
        } catch (Exception $e) {
            error_log("index.php: rank calc failed: " . $e->getMessage());
        }

        // Reviews
        $s = db()->prepare('SELECT COUNT(*) as total, SUM(CASE WHEN is_replied = 0 THEN 1 ELSE 0 END) as unanswered, AVG(rating) as avg_rating FROM reviews WHERE location_id = ?');
        $s->execute([$selectedLocationId]);
        $reviewStats = $s->fetch();
        $stats['reviews_total'] = $reviewStats['total'] ?? 0;
        $stats['reviews_unanswered'] = $reviewStats['unanswered'] ?? 0;
        $stats['avg_rating'] = round($reviewStats['avg_rating'] ?? 0, 1);

        // Posts stats pour le client (séparé manuels / listes auto)
        $s = db()->prepare("
            SELECT
                SUM(CASE WHEN (status = 'scheduled' OR status = 'list_pending') AND list_id IS NULL THEN 1 ELSE 0 END) as scheduled_manual,
                SUM(CASE WHEN (status = 'scheduled' OR status = 'list_pending') AND list_id IS NOT NULL THEN 1 ELSE 0 END) as scheduled_auto,
                SUM(CASE WHEN status = 'scheduled' OR status = 'list_pending' THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN status = 'published' AND published_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as published_week,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                COUNT(*) as total_posts
            FROM google_posts WHERE location_id = ?
        ");
        $s->execute([$selectedLocationId]);
        $postStats = $s->fetch();
        $stats['posts_scheduled'] = (int)($postStats['scheduled'] ?? 0);
        $stats['posts_scheduled_manual'] = (int)($postStats['scheduled_manual'] ?? 0);
        $stats['posts_scheduled_auto'] = (int)($postStats['scheduled_auto'] ?? 0);
        $stats['posts_published_week'] = (int)($postStats['published_week'] ?? 0);
        $stats['posts_failed'] = (int)($postStats['failed'] ?? 0);
        $stats['posts_total'] = (int)($postStats['total_posts'] ?? 0);

        // Score de sante client (0-3)
        $health = 0;
        $pos = $stats['avg_rank'] ? (float)$stats['avg_rank'] : 99;
        if ($pos <= 5) $health++;
        elseif ($pos <= 10) $health += 0.5;
        if ($stats['reviews_unanswered'] == 0) $health++;
        elseif ($stats['reviews_unanswered'] <= 2) $health += 0.5;
        if ($stats['posts_published_week'] > 0 || $stats['posts_scheduled'] > 0) $health++;
        $stats['health_score'] = $health;
    }
}

// --- VUE DASHBOARD (v4 : données chargées via AJAX /api/dashboard.php) ---
$hasLocations = !empty($locations);

// --- VUE REVIEWS-ALL : stats globales pour le badge sidebar ---
$globalUnanswered = 0;
if ($locations) {
    $ids = array_column($locations, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $s = db()->prepare("SELECT SUM(CASE WHEN is_replied = 0 THEN 1 ELSE 0 END) as total FROM reviews WHERE location_id IN ({$ph})");
    $s->execute($ids);
    $globalUnanswered = (int)($s->fetchColumn() ?: 0);
}

// Flash message
$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php
    if ($view === 'client' && $selectedLocation) echo sanitize($selectedLocation['name']) . ' — Neura';
    elseif ($view === 'reviews-all') echo 'Avis Global — Neura';
    elseif ($view === 'reports') echo 'Rapports — Neura';
    elseif ($view === 'acquisition') echo 'Audit & Acquisition — Neura';
    elseif ($view === 'locations') echo 'Fiches GBP — Neura';
    elseif ($view === 'settings') echo 'Parametres — Neura';
    elseif ($view === 'errors') echo 'Monitoring Erreurs — Neura';
    elseif ($view === 'legal') echo 'Mentions legales — Neura';
    elseif ($view === 'privacy') echo 'Confidentialite — Neura';
    elseif ($view === 'cgu') echo 'CGU — Neura';
    else echo 'Dashboard — Neura';
?></title>
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/brand/favicon.svg">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
<meta name="app-url" content="<?= APP_URL ?>">
<meta name="mapbox-token" content="<?= MAPBOX_TOKEN ?>">
<meta name="user-role" content="<?= sanitize($_SESSION['user_role'] ?? 'user') ?>">
<script>(function(){var t=localStorage.getItem('boustacom_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})()</script>
</head>
<body>
<!-- Hamburger mobile -->
<button class="hamburger-btn" onclick="document.querySelector('.sb').classList.toggle('mobile-open');document.querySelector('.sidebar-overlay').classList.toggle('active');" aria-label="Menu">☰</button>
<div class="sidebar-overlay" onclick="document.querySelector('.sb').classList.remove('mobile-open');this.classList.remove('active');"></div>

<div class="lay">

<!-- ====== SIDEBAR (vue partielle) ====== -->
<?php include __DIR__ . '/views/sidebar.php'; ?>

<!-- ====== MAIN CONTENT ====== -->
<main class="mn">

<?php if ($flash): ?>
<div class="flash flash-<?= $flash['type'] ?>"><?= sanitize($flash['message']) ?></div>
<?php endif; ?>

<?php
// ====== RENDU DES VUES (fichiers partiels) ======
$viewMap = [
    'dashboard'   => 'views/dashboard.php',
    'reviews-all' => 'views/reviews-all.php',
    'reports'     => 'views/reports.php',
    'acquisition' => 'views/acquisition.php',
    'settings'    => 'views/settings.php',
    'locations'   => 'views/locations.php',
    'client'      => 'views/client.php',
    'errors'      => 'views/errors.php',
    'legal'       => 'views/legal.php',
    'privacy'     => 'views/privacy.php',
    'cgu'         => 'views/cgu.php',
];
$viewFile = $viewMap[$view] ?? $viewMap['dashboard'];
include __DIR__ . '/' . $viewFile;
?>

<?= csrfField() ?>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>

<?php if ($view === 'client' && $selectedLocationId): ?>
    <?php if ($tab === 'position-map'): ?>
    <script>APP.positionMap.load(<?= $selectedLocationId ?>);</script>
    <?php elseif ($tab === 'keywords'): ?>
    <script>APP.keywords.load(<?= $selectedLocationId ?>);</script>
    <?php elseif ($tab === 'competitors'): ?>
    <script>APP.competitors.load(<?= $selectedLocationId ?>);</script>
    <?php elseif ($tab === 'reviews'): ?>
    <script>APP.reviews.load(<?= $selectedLocationId ?>);</script>
    <?php elseif ($tab === 'posts'): ?>
    <script>APP.posts.load(<?= $selectedLocationId ?>);</script>
    <?php elseif ($tab === 'post-lists'): ?>
    <script>APP.postLists.load(<?= $selectedLocationId ?>);</script>
    <?php elseif ($tab === 'post-visuals'): ?>
    <script>APP.postVisuals.load(<?= $selectedLocationId ?>);</script>
    <?php elseif ($tab === 'photos'): ?>
    <script>APP.photos.load(<?= $selectedLocationId ?>);</script>
    <?php elseif ($tab === 'content-overview'): ?>
    <script>APP.contentOverview.load(<?= $selectedLocationId ?>);</script>
    <?php elseif ($tab === 'fiche'): ?>
    <script>APP.gbpProfile.load(<?= $selectedLocationId ?>);</script>
    <?php elseif ($tab === 'stats'): ?>
    <script>APP.stats.load(<?= $selectedLocationId ?>);</script>
    <?php elseif ($tab === 'settings'): ?>
    <script>APP.clientSettings.load(<?= $selectedLocationId ?>);</script>
    <?php endif; ?>
<?php elseif ($view === 'reviews-all'): ?>
<script>APP.reviewsAll.load();</script>
<?php elseif ($view === 'reports'): ?>
<script>APP.reportsAll.load();</script>
<?php elseif ($view === 'acquisition'): ?>
<script>APP.acquisition.init();</script>
<?php elseif ($view === 'settings'): ?>
<script>APP.settings.load();</script>
<?php elseif ($view === 'locations'): ?>
<script>APP.locations.load();</script>
<?php elseif ($view === 'errors'): ?>
<script>APP.errors.load();</script>
<?php endif; ?>

</main>
</div>
<script>
// Fermer sidebar mobile quand on clique sur un lien
document.querySelectorAll('.sb .ni, .sb .sidebar-back').forEach(function(link) {
    link.addEventListener('click', function() {
        document.querySelector('.sb').classList.remove('mobile-open');
        var overlay = document.querySelector('.sidebar-overlay');
        if (overlay) overlay.classList.remove('active');
    });
});
</script>
</body>
</html>
