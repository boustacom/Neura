<?php
/**
 * NEURA — Vue Avis Global v2
 * Module de gestion des avis avec etats, filtres enrichis, side panel
 */
?>
<div class="ph">
    <div>
        <div class="ptit">Avis</div>
        <div class="psub">Pilotage de tous les avis clients &bull; <a href="?view=settings" style="color:var(--acc);">Profils IA</a></div>
    </div>
    <div class="ha">
        <button class="btn bs" onclick="APP.reviewsAll.syncReviews()" id="btn-sync-reviews-global">
            <svg viewBox="0 0 24 24"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            Synchroniser
        </button>
    </div>
</div>

<div class="page-section">
    <div class="sec" id="module-content">
        <div class="sh"><div class="stit">Chargement des avis...</div></div>
    </div>
</div>
