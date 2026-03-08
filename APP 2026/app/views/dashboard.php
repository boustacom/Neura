<?php
/**
 * NEURA — Dashboard v4 — Centre de pilotage
 * Template squelette : toutes les données sont chargées via /api/dashboard.php (AJAX)
 * Le sélecteur de période (7j/30j/90j/6 mois) déclenche un rechargement dynamique.
 * Variable requise : $hasLocations (bool)
 */
?>

<div class="ph">
    <div>
        <div class="ptit">Dashboard</div>
        <div class="psub">Centre de pilotage — <?= date('d/m/Y') ?></div>
    </div>
    <?php if ($hasLocations): ?>
    <div class="dash-period-selector" id="dash-period-selector">
        <button class="dash-period-btn" data-period="7" onclick="APP.dashboard.setPeriod(7)">7j</button>
        <button class="dash-period-btn active" data-period="30" onclick="APP.dashboard.setPeriod(30)">30j</button>
        <button class="dash-period-btn" data-period="90" onclick="APP.dashboard.setPeriod(90)">90j</button>
        <button class="dash-period-btn" data-period="180" onclick="APP.dashboard.setPeriod(180)">6 mois</button>
    </div>
    <?php endif; ?>
</div>

<?php if (!$hasLocations): ?>
<!-- ====== ÉTAT VIDE (pas de fiches) ====== -->
<div class="sec" style="padding:80px 40px;text-align:center;">
    <svg viewBox="0 0 24 24" style="width:56px;height:56px;stroke:var(--acc);fill:none;stroke-width:1.5;margin-bottom:20px;opacity:.4">
        <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0116 0z"/><circle cx="12" cy="10" r="3"/>
    </svg>
    <div style="font-size:20px;font-weight:700;margin-bottom:10px;color:var(--t1)">Aucune fiche connectée</div>
    <div style="color:var(--t2);font-size:14px;margin-bottom:24px;max-width:400px;margin-left:auto;margin-right:auto;">Connectez votre compte Google Business Profile pour accéder au centre de pilotage.</div>
    <button class="btn bp" onclick="APP.locations.confirmConnect()" style="font-size:14px;padding:12px 28px;">
        <svg viewBox="0 0 24 24" style="width:16px;height:16px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Connecter Google Business Profile
    </button>
</div>
<?php else: ?>

<!-- Zone erreur (masquée par défaut) -->
<div id="dash-error" class="dash-error" style="display:none;">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    <span>Erreur de chargement</span>
    <button onclick="APP.dashboard.load()" class="dash-error-retry">Réessayer</button>
</div>

<!-- Zone 1 : Alertes actionnables (temps réel, pas de période) -->
<div id="dash-alerts"></div>

<!-- Zone 2 : KPI Grid -->
<div id="dash-kpis" class="dash-kpi-grid"></div>

<!-- Zone 3 : Monitoring Grid -->
<div id="dash-monitor" class="dash-monitor-grid"></div>

<!-- Zone 4 : Derniers avis (temps réel) -->
<div id="dash-reviews"></div>

<!-- Zone 5 : Overview bar -->
<div id="dash-overview" class="dash-overview-bar" style="display:none;"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof APP !== 'undefined' && APP.dashboard) {
        APP.dashboard.init();
    }
});
</script>
<?php endif; ?>
