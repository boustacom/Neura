<?php
/**
 * BOUS'TACOM — Vue Audit & Acquisition
 * Variables attendues : $user
 */
?>
<div class="ph">
    <div>
        <div class="ptit">Audit & Acquisition</div>
        <div class="psub">Scannez, auditez et convertissez vos prospects</div>
    </div>
    <div class="ha">
        <div class="credits-badge" id="credits-display">
            <svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
            <span id="credits-count"><?= getUserCredits($user['id']) ?></span> credits
        </div>
    </div>
</div>

<div class="page-section">
    <div class="sec" id="module-content">
        <div class="sh"><div class="stit">Recherche de prospects</div></div>
    </div>
</div>
