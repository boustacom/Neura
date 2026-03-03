<?php
/**
 * BOUS'TACOM — Vue Client v3 (Pro SaaS Layout)
 * Header épuré + KPI grid dense + navigation via sidebar contextuelle
 * Variables attendues : $selectedLocation, $selectedLocationId, $locations, $tab, $stats
 */

// Health dots
$hs = $stats['health_score'] ?? 0;
$dot1 = $hs >= 1 ? 'dot-g' : ($hs >= 0.5 ? 'dot-o' : 'dot-r');
$dot2 = $hs >= 2 ? 'dot-g' : ($hs >= 1.5 ? 'dot-o' : 'dot-r');
$dot3 = $hs >= 3 ? 'dot-g' : ($hs >= 2.5 ? 'dot-o' : 'dot-r');
?>

<!-- Client Header Pro -->
<div class="client-header-pro">
    <div class="client-header-left">
        <div class="client-avatar"><?= strtoupper(substr($selectedLocation['name'] ?? 'C', 0, 1)) ?></div>
        <div style="min-width:0;">
            <h1 class="client-name-pro"><?= $selectedLocation ? sanitize($selectedLocation['name']) : 'Client' ?></h1>
            <div class="client-meta-pro">
                <?php if (!empty($selectedLocation['city'])): ?>
                <span class="client-city-badge"><?= sanitize($selectedLocation['city']) ?></span>
                <?php endif; ?>
                <?php if (!empty($selectedLocation['category'])): ?>
                <span class="client-category"><?= sanitize($selectedLocation['category']) ?></span>
                <?php endif; ?>
                <span class="health-indicator" title="Sante: <?= $hs ?>/3">
                    <span class="dot <?= $dot1 ?>"></span>
                    <span class="dot <?= $dot2 ?>"></span>
                    <span class="dot <?= $dot3 ?>"></span>
                </span>
            </div>
        </div>
    </div>
    <div class="client-header-right">
        <?php if (!empty($selectedLocation['place_id'])): ?>
        <a href="https://www.google.com/maps/place/?q=place_id:<?= $selectedLocation['place_id'] ?>" target="_blank" rel="noopener" class="btn-gmaps">
            <svg viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0116 0z"/><circle cx="12" cy="10" r="3"/></svg>
            <span>Voir sur Google Maps</span>
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- KPI Cards -->
<div class="page-section">
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">Rang moyen</div>
            <div class="kpi-value" style="color:<?= $stats['avg_rank'] > 0 && $stats['avg_rank'] <= 3 ? 'var(--g)' : ($stats['avg_rank'] > 0 && $stats['avg_rank'] <= 10 ? 'var(--o)' : 'var(--r)') ?>">
                <?= $stats['avg_rank'] ? '#' . $stats['avg_rank'] : '&mdash;' ?>
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Top 3</div>
            <div class="kpi-value kpi-accent"><?= $stats['top3'] ?><span class="kpi-total"> / <?= $stats['keywords'] ?></span></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Note Google</div>
            <div class="kpi-value" style="color:var(--o)">
                <?php if ($stats['avg_rating']): ?>&starf; <?= $stats['avg_rating'] ?><?php else: ?>&mdash;<?php endif; ?>
            </div>
            <div class="kpi-sub"><?= $stats['reviews_total'] ?> avis</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Avis sans reponse</div>
            <div class="kpi-value" style="color:<?= $stats['reviews_unanswered'] > 0 ? 'var(--o)' : 'var(--g)' ?>"><?= $stats['reviews_unanswered'] ?></div>
            <?php if ($stats['reviews_unanswered'] == 0): ?>
            <div class="kpi-sub" style="color:var(--g)">&#10003; A jour</div>
            <?php endif; ?>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Posts programmes</div>
            <div class="kpi-value"><?= $stats['posts_scheduled'] ?></div>
        </div>
    </div>
</div>

<!-- Zone de contenu dynamique -->
<div class="page-section">
    <div class="sec" id="module-content">
        <div class="sh">
            <div class="stit">
                <?php
                $titles = [
                    'fiche' => 'Fiche etablissement',
                    'position-map' => 'Carte de position',
                    'keywords' => 'Mots-cles',
                    'competitors' => 'Concurrents',
                    'content-overview' => 'Vue d\'ensemble',
                    'posts' => 'Google Posts',
                    'post-lists' => 'Listes automatiques',
                    'post-visuals' => 'Visuels',
                    'photos' => 'Photos GBP',
                    'reviews' => 'Avis Google',
                    'stats' => 'Statistiques Google',
                    'settings' => 'Parametres',
                ];
                echo $titles[$tab] ?? 'Dashboard';
                ?>
            </div>
        </div>
        <?php if (!in_array($tab, ['fiche', 'position-map', 'keywords', 'competitors', 'reviews', 'stats', 'posts', 'post-lists', 'post-visuals', 'photos', 'content-overview', 'settings']) || !$selectedLocationId): ?>
        <div style="padding:48px 40px;text-align:center;">
            <svg viewBox="0 0 24 24" style="width:48px;height:48px;stroke:var(--acc);fill:none;stroke-width:1.5;margin-bottom:16px;opacity:.5">
                <path d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
            <div style="font-size:16px;font-weight:600;margin-bottom:8px;color:var(--t2)">Module en cours de developpement</div>
            <div style="font-size:13px;color:var(--t3)">
                <?php if (empty($locations)): ?>
                    Connectez votre compte Google Business Profile pour activer les fonctionnalites.
                <?php else: ?>
                    Ce module sera bientot disponible.
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
