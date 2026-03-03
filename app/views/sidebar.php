<?php
/**
 * BOUS'TACOM — Sidebar v3
 * Mode global (dashboard/gestion) OU mode client contextuel
 * Variables attendues : $view, $globalUnanswered, $user, $globalStats,
 *   + si client : $selectedLocation, $selectedLocationId, $locations, $tab, $stats
 */
// Error count badge (admin only)
$errorBadgeCount = 0;
if (($user['role'] ?? '') === 'admin') {
    try {
        $s = db()->query("SELECT COUNT(*) FROM app_errors WHERE error_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND severity IN ('critical','error')");
        $errorBadgeCount = (int)$s->fetchColumn();
    } catch (Exception $e) { /* table may not exist yet */ }
}

$scheduledPosts = 0;
if (isset($locations) && $locations) {
    $ids = array_column($locations, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $s = db()->prepare("SELECT COUNT(*) FROM google_posts WHERE location_id IN ({$ph}) AND (status = 'scheduled' OR status = 'list_pending')");
        $s->execute($ids);
        $scheduledPosts = (int)$s->fetchColumn();
    } catch (Exception $e) {}
}
?>
<aside class="sb">
<div class="logo">
    <div class="logo-i">N</div>
    <div class="ltxt">Neura</div>
    <button class="theme-toggle" onclick="APP.toggleTheme()" title="Basculer le theme">
        <svg class="theme-icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
        <svg class="theme-icon-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
    </button>
</div>

<?php if ($view === 'client' && isset($selectedLocation)): ?>
<!-- ============================================================
     MODE CLIENT — Navigation contextuelle
     ============================================================ -->
<a href="?view=dashboard" class="ni sb-back">
    <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
    Dashboard
</a>

<div class="sb-client-info">
    <div class="sb-client-name"><?= sanitize($selectedLocation['name']) ?></div>
    <?php if (!empty($selectedLocation['city'])): ?>
    <div class="sb-client-city"><?= sanitize($selectedLocation['city']) ?></div>
    <?php endif; ?>
</div>

<?php if (count($locations) > 1): ?>
<select class="si sb-client-selector" onchange="if(this.value)window.location.href='?view=client&location='+this.value+'&tab=<?= $tab ?>'">
    <?php foreach ($locations as $loc): ?>
    <option value="<?= $loc['id'] ?>" <?= $loc['id'] == $selectedLocationId ? 'selected' : '' ?>><?= sanitize($loc['name']) ?> — <?= sanitize($loc['city'] ?? '') ?></option>
    <?php endforeach; ?>
</select>
<?php endif; ?>

<div class="ns">SEO Local</div>
<a href="?view=client&location=<?= $selectedLocationId ?>&tab=fiche" class="ni <?= $tab === 'fiche' ? 'act' : '' ?>">
    <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
    Fiche
</a>
<a href="?view=client&location=<?= $selectedLocationId ?>&tab=keywords" class="ni <?= $tab === 'keywords' ? 'act' : '' ?>">
    <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    Mots-cles
</a>
<a href="?view=client&location=<?= $selectedLocationId ?>&tab=position-map" class="ni <?= $tab === 'position-map' ? 'act' : '' ?>">
    <svg viewBox="0 0 24 24"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>
    Carte
</a>
<a href="?view=client&location=<?= $selectedLocationId ?>&tab=competitors" class="ni <?= $tab === 'competitors' ? 'act' : '' ?>">
    <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4-4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
    Concurrents
</a>

<div class="ns">Reputation</div>
<a href="?view=client&location=<?= $selectedLocationId ?>&tab=reviews" class="ni <?= $tab === 'reviews' ? 'act' : '' ?>">
    <svg viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
    Avis
    <?php if (!empty($stats['reviews_unanswered']) && $stats['reviews_unanswered'] > 0): ?><span class="nb nb-urgent"><?= $stats['reviews_unanswered'] ?></span><?php endif; ?>
</a>
<a href="?view=client&location=<?= $selectedLocationId ?>&tab=stats" class="ni <?= $tab === 'stats' ? 'act' : '' ?>">
    <svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
    Statistiques
</a>

<div class="ns">Contenu</div>
<a href="?view=client&location=<?= $selectedLocationId ?>&tab=content-overview" class="ni <?= $tab === 'content-overview' ? 'act' : '' ?>" title="Vue globale de tous vos contenus planifies et publies">
    <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
    Vue d'ensemble
</a>
<a href="?view=client&location=<?= $selectedLocationId ?>&tab=posts" class="ni <?= $tab === 'posts' ? 'act' : '' ?>" title="Creer, editer et planifier des posts manuels et generes par IA">
    <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
    Posts
    <?php if (!empty($stats['posts_scheduled_manual']) && $stats['posts_scheduled_manual'] > 0): ?><span class="nb cy"><?= $stats['posts_scheduled_manual'] ?></span><?php endif; ?>
</a>
<a href="?view=client&location=<?= $selectedLocationId ?>&tab=post-lists" class="ni <?= $tab === 'post-lists' ? 'act' : '' ?>" title="Gerer les listes de posts automatiques avec rotation programmee">
    <svg viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
    Listes auto
    <?php if (!empty($stats['posts_scheduled_auto']) && $stats['posts_scheduled_auto'] > 0): ?><span class="nb" style="background:var(--g);color:#000;"><?= $stats['posts_scheduled_auto'] ?></span><?php endif; ?>
</a>
<a href="?view=client&location=<?= $selectedLocationId ?>&tab=post-visuals" class="ni <?= $tab === 'post-visuals' ? 'act' : '' ?>" title="Creer des visuels professionnels pour vos publications">
    <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
    Visuels
</a>
<a href="?view=client&location=<?= $selectedLocationId ?>&tab=photos" class="ni <?= $tab === 'photos' ? 'act' : '' ?>" title="Gerer les photos de votre fiche Google Business Profile">
    <svg viewBox="0 0 24 24"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
    Photos GBP
</a>

<div class="ns">Systeme</div>
<a href="?view=client&location=<?= $selectedLocationId ?>&tab=settings" class="ni <?= $tab === 'settings' ? 'act' : '' ?>">
    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
    Parametres
</a>

<?php else: ?>
<!-- ============================================================
     MODE GLOBAL — Navigation principale
     ============================================================ -->
<a href="?view=dashboard" class="ni <?= $view === 'dashboard' ? 'act' : '' ?>">
    <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
    Dashboard
</a>

<div class="ns">Gestion</div>
<a href="?view=locations" class="ni <?= $view === 'locations' ? 'act' : '' ?>">
    <svg viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0116 0z"/><circle cx="12" cy="10" r="3"/></svg>
    Fiches GBP
    <?php if (isset($globalStats['total_locations']) && $globalStats['total_locations'] > 0): ?>
    <span class="nb" style="background:var(--subtle-bg);color:var(--t2);"><?= $globalStats['total_locations'] ?></span>
    <?php endif; ?>
</a>
<a href="?view=reviews-all" class="ni <?= $view === 'reviews-all' ? 'act' : '' ?>">
    <svg viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
    Avis
    <?php if ($globalUnanswered > 0): ?><span class="nb nb-urgent"><?= $globalUnanswered ?></span><?php endif; ?>
</a>
<a href="?view=reports" class="ni <?= $view === 'reports' ? 'act' : '' ?>">
    <svg viewBox="0 0 24 24"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>
    Rapports
</a>

<div class="ns">Croissance</div>
<a href="?view=acquisition" class="ni <?= $view === 'acquisition' ? 'act' : '' ?>">
    <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4-4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
    Audit & Acquisition
</a>

<div class="ns">Systeme</div>
<a href="?view=settings" class="ni <?= $view === 'settings' ? 'act' : '' ?>">
    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
    Parametres
</a>
<?php if (($user['role'] ?? '') === 'admin'): ?>
<a href="?view=errors" class="ni <?= $view === 'errors' ? 'act' : '' ?>">
    <svg viewBox="0 0 24 24" style="fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    Monitoring
    <?php if ($errorBadgeCount > 0): ?><span class="nb nb-urgent"><?= $errorBadgeCount ?></span><?php endif; ?>
</a>
<?php endif; ?>
<?php endif; ?>

<div class="sf">
    <div class="ui">
        <div class="ua"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
        <div style="min-width:0;">
            <div class="un"><?= sanitize($user['name']) ?></div>
            <div class="up">Neura — <?= ucfirst($user['role']) ?></div>
        </div>
    </div>
    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:14px;">
        <a href="<?= APP_URL ?>/auth/logout.php" style="font-size:12px;color:var(--t3);text-decoration:none;transition:color .15s;" onmouseover="this.style.color='var(--r)'" onmouseout="this.style.color='var(--t3)'">Deconnexion</a>
    </div>
    <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--bdr);font-size:10px;color:var(--t3);line-height:1.8;">
        <a href="?view=legal" style="color:var(--t3);text-decoration:none;">Mentions legales</a> &middot;
        <a href="?view=privacy" style="color:var(--t3);text-decoration:none;">Confidentialite</a> &middot;
        <a href="?view=cgu" style="color:var(--t3);text-decoration:none;">CGU</a>
    </div>
</div>
</aside>

<!-- ===== BOTTOM NAV MOBILE ===== -->
<?php if ($view === 'client' && isset($selectedLocation)): ?>
<nav class="bottom-nav">
    <a href="?view=client&location=<?= $selectedLocationId ?>&tab=keywords" class="bottom-nav-item <?= $tab === 'keywords' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <span>Mots-cles</span>
    </a>
    <a href="?view=client&location=<?= $selectedLocationId ?>&tab=position-map" class="bottom-nav-item <?= $tab === 'position-map' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>
        <span>Carte</span>
    </a>
    <a href="?view=client&location=<?= $selectedLocationId ?>&tab=reviews" class="bottom-nav-item <?= $tab === 'reviews' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
        <span>Avis</span>
        <?php if (!empty($stats['reviews_unanswered']) && $stats['reviews_unanswered'] > 0): ?><span class="bottom-nav-badge"><?= $stats['reviews_unanswered'] ?></span><?php endif; ?>
    </a>
    <a href="#" class="bottom-nav-item" onclick="event.preventDefault();document.querySelector('.sb').classList.toggle('mobile-open');document.querySelector('.sidebar-overlay').classList.toggle('active');">
        <svg viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        <span>Plus</span>
    </a>
</nav>
<?php else: ?>
<nav class="bottom-nav">
    <a href="?view=dashboard" class="bottom-nav-item <?= $view === 'dashboard' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
        <span>Accueil</span>
    </a>
    <a href="?view=reviews-all" class="bottom-nav-item <?= $view === 'reviews-all' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
        <span>Avis</span>
        <?php if ($globalUnanswered > 0): ?><span class="bottom-nav-badge"><?= $globalUnanswered ?></span><?php endif; ?>
    </a>
    <a href="?view=locations" class="bottom-nav-item <?= $view === 'locations' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0116 0z"/><circle cx="12" cy="10" r="3"/></svg>
        <span>Fiches</span>
    </a>
    <a href="#" class="bottom-nav-item" onclick="event.preventDefault();document.querySelector('.sb').classList.toggle('mobile-open');document.querySelector('.sidebar-overlay').classList.toggle('active');">
        <svg viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        <span>Plus</span>
    </a>
</nav>
<?php endif; ?>
