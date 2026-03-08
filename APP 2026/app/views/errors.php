<?php
/**
 * BOUS'TACOM — Vue Monitoring Erreurs (admin only)
 */
if (($user['role'] ?? '') !== 'admin') {
    echo '<div style="padding:60px 20px;text-align:center;color:var(--t3)"><svg viewBox="0 0 24 24" style="width:48px;height:48px;stroke:var(--r);fill:none;stroke-width:1.5;margin-bottom:16px"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg><div style="font-size:16px;font-weight:600;margin-bottom:8px">Acces reserve</div><div style="font-size:13px;color:var(--t2)">Cette section est reservee aux administrateurs.</div></div>';
    return;
}
?>
<div id="errors-content">
    <div style="padding:60px 20px;text-align:center;color:var(--t3)">
        <svg class="spin" viewBox="0 0 24 24" style="width:32px;height:32px;stroke:var(--acc);fill:none;stroke-width:2"><path d="M12 2v4m0 12v4m-7.07-3.93l2.83-2.83m8.48-8.48l2.83-2.83M2 12h4m12 0h4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83"/></svg>
        <div style="margin-top:12px">Chargement du monitoring...</div>
    </div>
</div>
