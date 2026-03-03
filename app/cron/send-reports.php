<?php
/**
 * BOUS'TACOM — CRON Envoi de rapports automatiques
 * Securise par token SHA-256
 * A configurer sur InfoManiak : quotidien a 08:00 (fenetre de rattrapage 3j si echec)
 * URL: https://app.boustacom.fr/app/cron/send-reports.php?token=XXXX
 */

require_once __DIR__ . '/../config.php';

// ====== SECURITE ======
$expectedToken = hash('sha256', APP_SECRET . '_cron_reports');
$providedToken = $_GET['token'] ?? '';

if (!hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Token invalide']);
    exit;
}

header('Content-Type: application/json');
http_response_code(200);
ini_set('display_errors', 1);
error_reporting(E_ALL);
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "\n\n!!! FATAL ERROR: {$err['message']} in {$err['file']}:{$err['line']}\n";
    }
});

$now = new DateTime('now', new DateTimeZone('Europe/Paris'));
$dayOfMonth = (int)$now->format('j');
$dayOfWeek = (int)$now->format('N'); // 1=Lundi..7=Dimanche
$results = [];

echo "=== CRON Rapports — " . $now->format('Y-m-d H:i:s') . " ===\n";

// ====== TROUVER LES TEMPLATES AUTO A ENVOYER AUJOURD'HUI ======
$stmt = db()->prepare("
    SELECT rt.* FROM report_templates rt
    WHERE rt.is_active = 1 AND rt.send_mode = 'auto'
");
$stmt->execute();
$templates = $stmt->fetchAll();

echo "Templates auto actifs trouves : " . count($templates) . "\n";

foreach ($templates as $template) {
    $shouldSend = false;
    $schedDay = (int)$template['schedule_day'];

    if ($template['schedule_frequency'] === 'monthly') {
        // Fenetre de rattrapage : jour prevu + 3 jours
        // Ex: schedule_day=5 → envoie du 5 au 8 inclus
        $shouldSend = ($dayOfMonth >= $schedDay && $dayOfMonth <= $schedDay + 3);
    } elseif ($template['schedule_frequency'] === 'weekly') {
        // Hebdo : jour exact + 1 jour de rattrapage
        $shouldSend = ($dayOfWeek === $schedDay || $dayOfWeek === ($schedDay % 7) + 1);
    }

    if (!$shouldSend) {
        echo "Template '{$template['name']}' — pas dans la fenetre d'envoi (prevu={$schedDay}, aujourd'hui={$dayOfMonth})\n";
        continue;
    }

    echo "Template '{$template['name']}' — dans la fenetre d'envoi (prevu le {$schedDay}, aujourd'hui le {$dayOfMonth})\n";

    // Verifier si deja envoye CE MOIS-CI (eviter les doublons meme avec rattrapage)
    $monthStart = $now->format('Y-m-01');
    $monthEnd = $now->format('Y-m-t');
    $stmt = db()->prepare('
        SELECT COUNT(*) FROM report_history
        WHERE template_id = ? AND DATE(sent_at) BETWEEN ? AND ? AND status = "sent"
    ');
    $stmt->execute([$template['id'], $monthStart, $monthEnd]);
    $alreadySent = $stmt->fetchColumn();

    if ($alreadySent > 0) {
        echo "  -> Deja envoye ce mois-ci (" . $now->format('Y-m') . "), on saute.\n";
        continue;
    }

    // Recuperer le proprietaire du template
    $stmt = db()->prepare('SELECT name, email FROM users WHERE id = ?');
    $stmt->execute([$template['user_id']]);
    $owner = $stmt->fetch();

    // Recuperer les destinataires actifs
    $stmt = db()->prepare('
        SELECT rr.*, l.name as location_name, l.city as location_city, l.id as loc_id
        FROM report_recipients rr
        JOIN gbp_locations l ON rr.location_id = l.id
        WHERE rr.template_id = ? AND rr.is_active = 1
    ');
    $stmt->execute([$template['id']]);
    $recipients = $stmt->fetchAll();

    echo "  -> " . count($recipients) . " destinataire(s)\n";

    // Periode = mois precedent (ex: cron le 5 fevrier → rapport de Janvier)
    // endDate = dernier jour du mois precedent
    // startDate = 6 mois avant pour avoir l'historique + comparaison
    $lastDayPrevMonth = new DateTime('last day of previous month', new DateTimeZone('Europe/Paris'));
    $firstDayPrevMonth = new DateTime('first day of previous month', new DateTimeZone('Europe/Paris'));
    $period = strftime_fr($firstDayPrevMonth->getTimestamp()); // "Janvier 2026"
    $endDate = $lastDayPrevMonth->format('Y-m-d');             // "2026-01-31"
    $startDate = (clone $firstDayPrevMonth)->modify('-5 months')->format('Y-m-d'); // 6 mois d'historique

    echo "  -> Periode rapport : {$period} (du {$startDate} au {$endDate})\n";

    // Charger le generateur PDF (functions.php deja charge via config.php)
    require_once __DIR__ . '/../includes/report-generator.php';
    $generator = new ReportGenerator();
    $sections = json_decode($template['sections'] ?? '{}', true) ?: [];

    foreach ($recipients as $rcpt) {
        try {
            // Preparer le sujet et le corps
            $contactName = $rcpt['recipient_name'] ?? '';
            $subject = str_replace(
                ['{client_name}', '{period}', '{sender_name}', '{contact_name}'],
                [$rcpt['location_name'], $period, $owner['name'] ?? 'Neura', $contactName],
                $template['email_subject'] ?? 'Rapport SEO'
            );

            $body = $rcpt['custom_email_body'] ?: ($template['email_body'] ?? '');
            $body = str_replace(
                ['{client_name}', '{period}', '{sender_name}', '{contact_name}'],
                [$rcpt['location_name'], $period, $owner['name'] ?? 'Neura', $contactName],
                $body
            );

            // Generer le PDF
            $pdfPath = null;
            try {
                $pdfPath = $generator->generate((int)$rcpt['loc_id'], $sections, $startDate, $endDate, $period);
                echo "  -> PDF genere : {$pdfPath}\n";
            } catch (Exception $e) {
                echo "  -> Erreur PDF (non bloquant) : {$e->getMessage()}\n";
            }

            // Envoyer l'email via PHPMailer (avec PDF si disponible)
            $result = sendReportEmail(
                $rcpt['recipient_email'],
                $rcpt['recipient_name'] ?? '',
                $subject,
                $body,
                $pdfPath
            );

            $status = $result['success'] ? 'sent' : 'failed';

            // Logger dans l'historique
            $stmt2 = db()->prepare('
                INSERT INTO report_history (template_id, location_id, recipient_email, report_type, status, sent_at)
                VALUES (?, ?, ?, "custom", ?, NOW())
            ');
            $stmt2->execute([$template['id'], $rcpt['loc_id'], $rcpt['recipient_email'], $status]);

            if ($result['success']) {
                echo "  -> Email envoye a {$rcpt['recipient_email']} ({$rcpt['location_name']})" . ($pdfPath ? " avec PDF" : " sans PDF") . "\n";
            } else {
                echo "  -> ECHEC envoi a {$rcpt['recipient_email']}: " . ($result['error'] ?? 'Inconnue') . "\n";
            }

            $results[] = [
                'template' => $template['name'],
                'email' => $rcpt['recipient_email'],
                'location' => $rcpt['location_name'],
                'status' => $status,
                'pdf' => $pdfPath ? true : false
            ];

        } catch (Exception $e) {
            $stmt2 = db()->prepare('
                INSERT INTO report_history (template_id, location_id, recipient_email, report_type, status, sent_at)
                VALUES (?, ?, ?, "custom", "failed", NOW())
            ');
            $stmt2->execute([$template['id'], $rcpt['loc_id'], $rcpt['recipient_email']]);

            echo "  -> ERREUR pour {$rcpt['recipient_email']}: {$e->getMessage()}\n";

            $results[] = [
                'template' => $template['name'],
                'email' => $rcpt['recipient_email'],
                'location' => $rcpt['location_name'],
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }
}

echo "\n=== Termine. " . count($results) . " operation(s) ===\n";
echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

/**
 * Helper : formatage date en francais
 */
function strftime_fr(int $timestamp): string {
    $mois = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
             'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    return $mois[(int)date('n', $timestamp)] . ' ' . date('Y', $timestamp);
}
