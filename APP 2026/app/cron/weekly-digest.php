<?php
/**
 * Neura — CRON : Digest Hebdomadaire SEO
 *
 * Envoie un email recapitulatif chaque lundi matin avec :
 * - Changements de positions par fiche / mot-cle
 * - Evolution de la visibilite grille
 * - Resume : gagnes / perdus / stables
 *
 * Securise par token SHA-256.
 * Configurer sur Infomaniak : chaque Lundi a 07:00
 * URL: https://app.boustacom.fr/app/cron/weekly-digest.php?token=XXXX
 */

require_once __DIR__ . '/../config.php';

// ====== SECURITE ======
$expectedToken = hash('sha256', APP_SECRET . '_cron_weekly_digest');
$providedToken = $_GET['token'] ?? '';

if (!hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    echo "Token invalide.\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
http_response_code(200);
set_time_limit(120);

// Activer l'affichage d'erreurs dans les crons (sinon les fatals sont invisibles)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Shutdown handler : capter les fatals et les afficher dans la reponse cron
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "\n\n!!! FATAL ERROR: {$err['message']} in {$err['file']}:{$err['line']}\n";
    }
});

echo "=== Neura Weekly Digest — " . date('Y-m-d H:i:s') . " ===\n";

// ====== GUARD JOUR : Lundi ou Mardi (rattrapage) ======
$now = new DateTime('now', new DateTimeZone('Europe/Paris'));
$dayOfWeek = (int)$now->format('N'); // 1=Lundi..7=Dimanche

if ($dayOfWeek > 2) {
    echo "Pas lundi ni mardi (jour {$dayOfWeek}), on skip.\n";
    exit;
}

// ====== ANTI-DOUBLON ======
$weekStart = (clone $now)->modify('monday this week')->format('Y-m-d');
$stmt = db()->prepare("SELECT value FROM settings WHERE key_name = 'last_weekly_digest'");
$stmt->execute();
$lastSent = $stmt->fetchColumn();

if ($lastSent === $weekStart) {
    echo "Digest deja envoye cette semaine ({$weekStart}).\n";
    exit;
}

// ====== CHARGER TOUTES LES FICHES ACTIVES ======
$locations = db()->query('
    SELECT id, name, city
    FROM gbp_locations
    ORDER BY name
')->fetchAll(PDO::FETCH_ASSOC);

if (empty($locations)) {
    echo "Aucune fiche trouvee.\n";
    exit;
}

// ====== COLLECTER LES DONNEES PAR FICHE ======
$allData = [];
$totalGained = 0;
$totalLost = 0;
$totalStable = 0;
$totalNew = 0;

foreach ($locations as $loc) {
    // Mots-cles actifs avec positions actuelle + precedente
    $stmt = db()->prepare('
        SELECT k.id, k.keyword,
            (SELECT kp.position FROM keyword_positions kp
             WHERE kp.keyword_id = k.id ORDER BY kp.tracked_at DESC LIMIT 1) as current_position,
            (SELECT kp.tracked_at FROM keyword_positions kp
             WHERE kp.keyword_id = k.id ORDER BY kp.tracked_at DESC LIMIT 1) as current_date,
            (SELECT kp.position FROM keyword_positions kp
             WHERE kp.keyword_id = k.id ORDER BY kp.tracked_at DESC LIMIT 1 OFFSET 1) as previous_position
        FROM keywords k
        WHERE k.location_id = ? AND k.is_active = 1
        ORDER BY k.keyword
    ');
    $stmt->execute([$loc['id']]);
    $keywords = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($keywords)) continue;

    // Visibilite grille par mot-cle
    $kwIds = array_column($keywords, 'id');
    $placeholders = implode(',', array_fill(0, count($kwIds), '?'));
    $stmtGrid = db()->prepare("
        SELECT gs.keyword_id, gs.visibility_score, gs.avg_position,
            (SELECT gs2.visibility_score FROM grid_scans gs2
             WHERE gs2.keyword_id = gs.keyword_id AND gs2.id < gs.id
             ORDER BY gs2.scanned_at DESC LIMIT 1) as prev_visibility
        FROM grid_scans gs
        INNER JOIN (
            SELECT keyword_id, MAX(id) as max_id
            FROM grid_scans WHERE keyword_id IN ({$placeholders}) GROUP BY keyword_id
        ) latest ON gs.id = latest.max_id
    ");
    $stmtGrid->execute($kwIds);
    $gridData = [];
    while ($row = $stmtGrid->fetch(PDO::FETCH_ASSOC)) {
        $gridData[$row['keyword_id']] = $row;
    }

    // Analyser les changements
    $gained = 0; $lost = 0; $stable = 0; $newKw = 0;
    $kwRows = [];

    foreach ($keywords as $kw) {
        $curr = $kw['current_position'];
        $prev = $kw['previous_position'];
        $grid = $gridData[$kw['id']] ?? null;

        $change = null;
        $status = 'stable';

        if ($curr === null && $prev === null) {
            $status = 'nodata';
        } elseif ($prev === null && $curr !== null) {
            $status = 'new';
            $newKw++;
            $totalNew++;
        } elseif ($curr === null && $prev !== null) {
            $status = 'lost';
            $lost++;
            $totalLost++;
        } elseif ((int)$curr < (int)$prev) {
            $status = 'gained';
            $change = (int)$prev - (int)$curr;
            $gained++;
            $totalGained++;
        } elseif ((int)$curr > (int)$prev) {
            $status = 'lost';
            $change = (int)$curr - (int)$prev;
            $lost++;
            $totalLost++;
        } else {
            $stable++;
            $totalStable++;
        }

        $kwRows[] = [
            'keyword' => $kw['keyword'],
            'current' => $curr,
            'previous' => $prev,
            'change' => $change,
            'status' => $status,
            'visibility' => $grid['visibility_score'] ?? null,
            'prev_visibility' => $grid['prev_visibility'] ?? null,
            'avg_position' => $grid['avg_position'] ?? null,
        ];
    }

    $allData[] = [
        'location' => $loc,
        'keywords' => $kwRows,
        'gained' => $gained,
        'lost' => $lost,
        'stable' => $stable,
        'new' => $newKw,
    ];
}

if (empty($allData)) {
    echo "Aucune donnee de tracking trouvee.\n";
    exit;
}

// ====== CONSTRUIRE L'EMAIL HTML ======
$dateLabel = $now->format('d/m/Y');

$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>';
$html .= '<body style="margin:0;padding:0;background:#0d0d1a;font-family:Arial,sans-serif;">';
$html .= '<div style="max-width:650px;margin:0 auto;padding:20px;">';

// Header
$html .= '<div style="text-align:center;padding:30px 20px;background:#2563eb;border-radius:16px 16px 0 0;">';
$html .= '<h1 style="margin:0;font-size:26px;color:#fff;font-weight:800;letter-spacing:1px;">NEURA</h1>';
$html .= '<p style="margin:6px 0 0;font-size:14px;color:rgba(255,255,255,.6);">Digest SEO — Semaine du ' . $dateLabel . '</p>';
$html .= '</div>';

// Resume global
$html .= '<div style="background:#1a1a2e;padding:20px 24px;border-bottom:1px solid #2a2a4a;">';
$html .= '<div style="display:flex;gap:16px;text-align:center;">';
$html .= '<div style="flex:1;"><div style="font-size:24px;font-weight:700;color:#22c55e;">' . $totalGained . '</div><div style="font-size:11px;color:#888;text-transform:uppercase;">En hausse</div></div>';
$html .= '<div style="flex:1;"><div style="font-size:24px;font-weight:700;color:#ef4444;">' . $totalLost . '</div><div style="font-size:11px;color:#888;text-transform:uppercase;">En baisse</div></div>';
$html .= '<div style="flex:1;"><div style="font-size:24px;font-weight:700;color:#888;">' . $totalStable . '</div><div style="font-size:11px;color:#888;text-transform:uppercase;">Stables</div></div>';
if ($totalNew > 0) {
    $html .= '<div style="flex:1;"><div style="font-size:24px;font-weight:700;color:#2563eb;">' . $totalNew . '</div><div style="font-size:11px;color:#888;text-transform:uppercase;">Nouveaux</div></div>';
}
$html .= '</div></div>';

// Par fiche
foreach ($allData as $data) {
    $loc = $data['location'];
    $html .= '<div style="background:#1a1a2e;padding:20px 24px;border-bottom:1px solid #2a2a4a;">';

    // Titre fiche + badges
    $html .= '<div style="margin-bottom:14px;">';
    $html .= '<div style="font-size:16px;font-weight:700;color:#fff;">' . htmlspecialchars($loc['name']) . '</div>';
    $html .= '<div style="font-size:12px;color:#666;margin-top:2px;">' . htmlspecialchars($loc['city'] ?? '') . '</div>';
    $html .= '<div style="margin-top:8px;">';
    if ($data['gained'] > 0) $html .= '<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;background:rgba(34,197,94,.12);color:#22c55e;margin-right:6px;">&#9650; ' . $data['gained'] . ' en hausse</span>';
    if ($data['lost'] > 0) $html .= '<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;background:rgba(239,68,68,.12);color:#ef4444;margin-right:6px;">&#9660; ' . $data['lost'] . ' en baisse</span>';
    if ($data['stable'] > 0) $html .= '<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;background:rgba(255,255,255,.06);color:#888;">= ' . $data['stable'] . ' stable' . ($data['stable'] > 1 ? 's' : '') . '</span>';
    $html .= '</div></div>';

    // Tableau mots-cles
    $html .= '<table style="width:100%;border-collapse:collapse;">';
    $html .= '<tr style="border-bottom:1px solid #2a2a4a;">';
    $html .= '<th style="padding:8px 4px;text-align:left;font-size:11px;color:#666;font-weight:600;text-transform:uppercase;">Mot-cle</th>';
    $html .= '<th style="padding:8px 4px;text-align:center;font-size:11px;color:#666;font-weight:600;text-transform:uppercase;width:60px;">Pos.</th>';
    $html .= '<th style="padding:8px 4px;text-align:center;font-size:11px;color:#666;font-weight:600;text-transform:uppercase;width:70px;">Evol.</th>';
    $html .= '<th style="padding:8px 4px;text-align:center;font-size:11px;color:#666;font-weight:600;text-transform:uppercase;width:70px;">Grille</th>';
    $html .= '</tr>';

    foreach ($data['keywords'] as $kw) {
        // Position affichee
        $posDisplay = $kw['current'] !== null ? (int)$kw['current'] : 'N/R';
        if ($posDisplay !== 'N/R' && $posDisplay <= 3) {
            $posColor = '#22c55e';
        } elseif ($posDisplay !== 'N/R' && $posDisplay <= 10) {
            $posColor = '#fbbf24';
        } elseif ($posDisplay !== 'N/R' && $posDisplay <= 20) {
            $posColor = '#f59e0b';
        } else {
            $posColor = '#ef4444';
        }

        // Evolution
        $evolHtml = '';
        if ($kw['status'] === 'gained') {
            $evolHtml = '<span style="color:#22c55e;font-weight:600;">&#9650; +' . $kw['change'] . '</span>';
        } elseif ($kw['status'] === 'lost' && $kw['change'] !== null) {
            $evolHtml = '<span style="color:#ef4444;font-weight:600;">&#9660; -' . $kw['change'] . '</span>';
        } elseif ($kw['status'] === 'lost') {
            $evolHtml = '<span style="color:#ef4444;font-size:10px;">Disparu</span>';
        } elseif ($kw['status'] === 'new') {
            $evolHtml = '<span style="color:#2563eb;font-size:10px;font-weight:600;">NOUVEAU</span>';
        } elseif ($kw['status'] === 'nodata') {
            $evolHtml = '<span style="color:#555;">—</span>';
        } else {
            $evolHtml = '<span style="color:#666;">=</span>';
        }

        // Visibilite grille
        $visHtml = '—';
        if ($kw['visibility'] !== null) {
            $vis = (int)$kw['visibility'];
            $visColor = $vis >= 70 ? '#22c55e' : ($vis >= 40 ? '#fbbf24' : '#ef4444');
            $visHtml = '<span style="color:' . $visColor . ';font-weight:600;">' . $vis . '%</span>';
            if ($kw['prev_visibility'] !== null) {
                $prevVis = (int)$kw['prev_visibility'];
                $visDiff = $vis - $prevVis;
                if ($visDiff > 0) {
                    $visHtml .= ' <span style="color:#22c55e;font-size:10px;">+' . $visDiff . '</span>';
                } elseif ($visDiff < 0) {
                    $visHtml .= ' <span style="color:#ef4444;font-size:10px;">' . $visDiff . '</span>';
                }
            }
        }

        $html .= '<tr style="border-bottom:1px solid rgba(255,255,255,.04);">';
        $html .= '<td style="padding:8px 4px;font-size:13px;color:#ddd;">' . htmlspecialchars($kw['keyword']) . '</td>';
        $html .= '<td style="padding:8px 4px;text-align:center;font-size:14px;font-weight:700;color:' . $posColor . ';">' . $posDisplay . '</td>';
        $html .= '<td style="padding:8px 4px;text-align:center;font-size:13px;">' . $evolHtml . '</td>';
        $html .= '<td style="padding:8px 4px;text-align:center;font-size:13px;">' . $visHtml . '</td>';
        $html .= '</tr>';
    }

    $html .= '</table></div>';
}

// Footer
$html .= '<div style="background:#1a1a2e;padding:20px 24px;border-radius:0 0 16px 16px;text-align:center;">';
$html .= '<a href="' . APP_URL . '" style="display:inline-block;padding:12px 28px;background:#2563eb;color:#fff;font-weight:700;text-decoration:none;border-radius:8px;font-size:14px;">Ouvrir Neura</a>';
$html .= '<div style="margin-top:16px;font-size:11px;color:#555;">Neura &mdash; une solution developpee par BOUS\'TACOM</div>';
$html .= '</div>';

$html .= '</div></body></html>';

// ====== ENVOYER L'EMAIL ======
$adminEmail = 'contact@boustacom.fr';
$adminName = 'Mathieu Bouscaillou';
$subject = 'Neura — Digest SEO semaine du ' . $dateLabel;

$result = sendEmail($adminEmail, $adminName, $subject, $html);

if ($result['success']) {
    echo "Email envoye avec succes a {$adminEmail}.\n";
    echo "  {$totalGained} en hausse, {$totalLost} en baisse, {$totalStable} stables, {$totalNew} nouveaux.\n";

    // Marquer comme envoye cette semaine
    $stmt = db()->prepare("INSERT INTO settings (key_name, value) VALUES ('last_weekly_digest', ?)
        ON DUPLICATE KEY UPDATE value = ?");
    $stmt->execute([$weekStart, $weekStart]);
} else {
    echo "ERREUR envoi email : " . ($result['error'] ?? 'inconnu') . "\n";
}

echo "\n=== Digest termine ===\n";
