<?php
/**
 * BOUS'TACOM — API Rapports Automatiques
 * Gestion des templates, destinataires, envoi
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();
requireCsrf();

header('Content-Type: application/json');

$user = currentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ====== LISTER LES TEMPLATES ======
    case 'list_templates':
        $stmt = db()->prepare('
            SELECT rt.*,
                   (SELECT COUNT(*) FROM report_recipients rr WHERE rr.template_id = rt.id) as recipient_count,
                   (SELECT MAX(rh.sent_at) FROM report_history rh WHERE rh.template_id = rt.id AND rh.status = "sent") as last_sent_at
            FROM report_templates rt
            WHERE rt.user_id = ?
            ORDER BY rt.name
        ');
        $stmt->execute([$user['id']]);
        $templates = $stmt->fetchAll();

        // Liste des fiches pour le formulaire de destinataires
        $stmt = db()->prepare('
            SELECT l.id, l.name, l.city, l.report_email, l.report_contact_name
            FROM gbp_locations l
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            WHERE a.user_id = ? AND l.is_active = 1
            ORDER BY l.name
        ');
        $stmt->execute([$user['id']]);
        $locations = $stmt->fetchAll();

        jsonResponse(['templates' => $templates, 'locations' => $locations]);
        break;

    // ====== DETAIL D'UN TEMPLATE ======
    case 'get_template':
        $templateId = $_GET['template_id'] ?? $_POST['template_id'] ?? null;
        if (!$templateId) jsonResponse(['error' => 'template_id requis'], 400);

        $stmt = db()->prepare('SELECT * FROM report_templates WHERE id = ? AND user_id = ?');
        $stmt->execute([$templateId, $user['id']]);
        $template = $stmt->fetch();

        if (!$template) jsonResponse(['error' => 'Template non trouve'], 404);

        $stmt = db()->prepare('
            SELECT rr.*, l.name as location_name, l.city as location_city
            FROM report_recipients rr
            JOIN gbp_locations l ON rr.location_id = l.id
            WHERE rr.template_id = ?
            ORDER BY l.name
        ');
        $stmt->execute([$templateId]);
        $recipients = $stmt->fetchAll();

        jsonResponse(['template' => $template, 'recipients' => $recipients]);
        break;

    // ====== CREER / MODIFIER UN TEMPLATE ======
    case 'save_template':
        $templateId = $_POST['template_id'] ?? null;
        $name = trim($_POST['name'] ?? '');
        $scheduleFrequency = $_POST['schedule_frequency'] ?? 'monthly';
        $scheduleDay = (int)($_POST['schedule_day'] ?? 1);
        $sections = $_POST['sections'] ?? '{}';
        $emailSubject = trim($_POST['email_subject'] ?? 'Rapport SEO - {client_name} - {period}');
        $emailBody = trim($_POST['email_body'] ?? '');
        $sendMode = in_array($_POST['send_mode'] ?? '', ['auto', 'manual']) ? $_POST['send_mode'] : 'manual';

        if (!$name) jsonResponse(['error' => 'Nom du template requis'], 400);
        if (!in_array($scheduleFrequency, ['monthly', 'weekly'])) jsonResponse(['error' => 'Frequence invalide'], 400);

        try {
            if ($templateId) {
                $stmt = db()->prepare('SELECT id FROM report_templates WHERE id = ? AND user_id = ?');
                $stmt->execute([$templateId, $user['id']]);
                if (!$stmt->fetch()) jsonResponse(['error' => 'Template non trouve'], 404);

                $stmt = db()->prepare('
                    UPDATE report_templates SET
                        name = ?, schedule_frequency = ?, schedule_day = ?,
                        sections = ?, email_subject = ?, email_body = ?, send_mode = ?
                    WHERE id = ? AND user_id = ?
                ');
                $stmt->execute([$name, $scheduleFrequency, $scheduleDay, $sections, $emailSubject, $emailBody, $sendMode, $templateId, $user['id']]);
            } else {
                $stmt = db()->prepare('
                    INSERT INTO report_templates (user_id, name, type, schedule_frequency, schedule_day, sections, email_subject, email_body, is_active, send_mode)
                    VALUES (?, ?, "custom", ?, ?, ?, ?, ?, 1, ?)
                ');
                $stmt->execute([$user['id'], $name, $scheduleFrequency, $scheduleDay, $sections, $emailSubject, $emailBody, $sendMode]);
                $templateId = db()->lastInsertId();
            }
            jsonResponse(['success' => true, 'template_id' => $templateId]);
        } catch (Exception $e) {
            jsonResponse(['error' => 'Erreur: ' . $e->getMessage()], 500);
        }
        break;

    // ====== SUPPRIMER UN TEMPLATE ======
    case 'delete_template':
        $templateId = $_POST['template_id'] ?? null;
        if (!$templateId) jsonResponse(['error' => 'template_id requis'], 400);

        // Verifier la propriete
        $stmt = db()->prepare('SELECT id FROM report_templates WHERE id = ? AND user_id = ?');
        $stmt->execute([$templateId, $user['id']]);
        if (!$stmt->fetch()) jsonResponse(['error' => 'Template non trouve'], 404);

        // Supprimer recipients et historique
        db()->prepare('DELETE FROM report_recipients WHERE template_id = ?')->execute([$templateId]);
        db()->prepare('DELETE FROM report_history WHERE template_id = ?')->execute([$templateId]);
        db()->prepare('DELETE FROM report_templates WHERE id = ?')->execute([$templateId]);

        jsonResponse(['success' => true]);
        break;

    // ====== TOGGLE ACTIF/INACTIF ======
    case 'toggle_active':
        $templateId = $_POST['template_id'] ?? null;
        if (!$templateId) jsonResponse(['error' => 'template_id requis'], 400);

        $stmt = db()->prepare('UPDATE report_templates SET is_active = NOT is_active WHERE id = ? AND user_id = ?');
        $stmt->execute([$templateId, $user['id']]);
        jsonResponse(['success' => true]);
        break;

    // ====== AJOUTER UN DESTINATAIRE ======
    case 'add_recipient':
        $templateId = $_POST['template_id'] ?? null;
        $locationId = $_POST['location_id'] ?? null;
        $email = trim($_POST['recipient_email'] ?? '');
        $name = trim($_POST['recipient_name'] ?? '');

        if (!$templateId || !$locationId || !$email) {
            jsonResponse(['error' => 'template_id, location_id et email requis'], 400);
        }

        // Verifier propriete du template
        $stmt = db()->prepare('SELECT id FROM report_templates WHERE id = ? AND user_id = ?');
        $stmt->execute([$templateId, $user['id']]);
        if (!$stmt->fetch()) jsonResponse(['error' => 'Template non trouve'], 404);

        // Verifier propriete de la fiche
        $stmt = db()->prepare('
            SELECT l.id FROM gbp_locations l
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            WHERE l.id = ? AND a.user_id = ?
        ');
        $stmt->execute([$locationId, $user['id']]);
        if (!$stmt->fetch()) jsonResponse(['error' => 'Fiche non trouvee'], 404);

        try {
            $stmt = db()->prepare('
                INSERT INTO report_recipients (template_id, location_id, recipient_email, recipient_name, is_active)
                VALUES (?, ?, ?, ?, 1)
            ');
            $stmt->execute([$templateId, $locationId, $email, $name]);
            jsonResponse(['success' => true, 'id' => db()->lastInsertId()]);
        } catch (Exception $e) {
            jsonResponse(['error' => 'Erreur: ' . $e->getMessage()], 500);
        }
        break;

    // ====== RETIRER UN DESTINATAIRE ======
    case 'remove_recipient':
        $recipientId = $_POST['recipient_id'] ?? null;
        if (!$recipientId) jsonResponse(['error' => 'recipient_id requis'], 400);

        // Verifier propriete (via template -> user)
        $stmt = db()->prepare('
            SELECT rr.id FROM report_recipients rr
            JOIN report_templates rt ON rr.template_id = rt.id
            WHERE rr.id = ? AND rt.user_id = ?
        ');
        $stmt->execute([$recipientId, $user['id']]);
        if (!$stmt->fetch()) jsonResponse(['error' => 'Destinataire non trouve'], 404);

        db()->prepare('DELETE FROM report_recipients WHERE id = ?')->execute([$recipientId]);
        jsonResponse(['success' => true]);
        break;

    // ====== MODIFIER UN DESTINATAIRE ======
    case 'update_recipient':
        $recipientId = $_POST['recipient_id'] ?? null;
        $customBody = trim($_POST['custom_email_body'] ?? '');

        if (!$recipientId) jsonResponse(['error' => 'recipient_id requis'], 400);

        // Verifier propriete
        $stmt = db()->prepare('
            SELECT rr.id FROM report_recipients rr
            JOIN report_templates rt ON rr.template_id = rt.id
            WHERE rr.id = ? AND rt.user_id = ?
        ');
        $stmt->execute([$recipientId, $user['id']]);
        if (!$stmt->fetch()) jsonResponse(['error' => 'Destinataire non trouve'], 404);

        $stmt = db()->prepare('UPDATE report_recipients SET custom_email_body = ? WHERE id = ?');
        $stmt->execute([$customBody ?: null, $recipientId]);
        jsonResponse(['success' => true]);
        break;

    // ====== APERCU DU RAPPORT (donnees JSON) ======
    case 'preview_report':
        $locationId = $_GET['location_id'] ?? $_POST['location_id'] ?? null;
        if (!$locationId) jsonResponse(['error' => 'location_id requis'], 400);

        // Verifier propriete
        $stmt = db()->prepare('
            SELECT l.*, a.id as account_id FROM gbp_locations l
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            WHERE l.id = ? AND a.user_id = ?
        ');
        $stmt->execute([$locationId, $user['id']]);
        $location = $stmt->fetch();
        if (!$location) jsonResponse(['error' => 'Fiche non trouvee'], 404);

        // Donnees mots-cles
        $stmt = db()->prepare('
            SELECT k.keyword, kp.position, kp.in_local_pack, kp.tracked_at
            FROM keywords k
            LEFT JOIN keyword_positions kp ON kp.keyword_id = k.id
                AND kp.tracked_at = (SELECT MAX(tracked_at) FROM keyword_positions WHERE keyword_id = k.id)
            WHERE k.location_id = ? AND k.is_active = 1
            ORDER BY kp.position ASC
        ');
        $stmt->execute([$locationId]);
        $keywords = $stmt->fetchAll();

        // Stats avis
        $stmt = db()->prepare('
            SELECT COUNT(*) as total, ROUND(AVG(rating), 1) as avg_rating,
                   SUM(CASE WHEN is_replied = 0 THEN 1 ELSE 0 END) as unanswered
            FROM reviews WHERE location_id = ?
        ');
        $stmt->execute([$locationId]);
        $reviewStats = $stmt->fetch();

        // Stats posts
        $stmt = db()->prepare('
            SELECT COUNT(*) as total,
                   SUM(CASE WHEN status = "published" THEN 1 ELSE 0 END) as published,
                   SUM(CASE WHEN status = "scheduled" OR status = "list_pending" THEN 1 ELSE 0 END) as scheduled
            FROM google_posts WHERE location_id = ?
        ');
        $stmt->execute([$locationId]);
        $postStats = $stmt->fetch();

        // Stats Google Business (impressions + interactions mensuelles)
        $stmt = db()->prepare('
            SELECT DATE_FORMAT(stat_date, "%Y-%m") as month,
                   SUM(impressions_search) as impressions_search,
                   SUM(impressions_maps) as impressions_maps,
                   SUM(call_clicks) as call_clicks,
                   SUM(website_clicks) as website_clicks,
                   SUM(direction_requests) as direction_requests,
                   COUNT(*) as days
            FROM location_daily_stats
            WHERE location_id = ? AND stat_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(stat_date, "%Y-%m")
            ORDER BY month DESC
            LIMIT 6
        ');
        $stmt->execute([$locationId]);
        $monthlyStats = $stmt->fetchAll();

        // Grid visibility
        $kwIds = array_column($keywords, 'keyword_id') ?: [];
        $gridData = [];
        if (!empty($kwIds)) {
            $placeholders = implode(',', array_fill(0, count($kwIds), '?'));
            $stmt = db()->prepare("
                SELECT gs.keyword_id, k.keyword, gs.avg_position, gs.visibility_score,
                       gs.top3_count, gs.top10_count, gs.top20_count, gs.out_count
                FROM grid_scans gs
                JOIN keywords k ON gs.keyword_id = k.id
                WHERE gs.keyword_id IN ($placeholders)
                  AND gs.scanned_at = (SELECT MAX(scanned_at) FROM grid_scans WHERE keyword_id = gs.keyword_id)
                ORDER BY gs.visibility_score DESC
            ");
            $stmt->execute($kwIds);
            $gridData = $stmt->fetchAll();
        }

        jsonResponse([
            'location' => $location,
            'keywords' => $keywords,
            'review_stats' => $reviewStats,
            'post_stats' => $postStats,
            'monthly_stats' => $monthlyStats,
            'grid_data' => $gridData,
        ]);
        break;

    // ====== HISTORIQUE DES ENVOIS ======
    case 'list_history':
        $templateId = $_GET['template_id'] ?? null;
        if (!$templateId) jsonResponse(['error' => 'template_id requis'], 400);

        // Verifier propriete
        $stmt = db()->prepare('SELECT id FROM report_templates WHERE id = ? AND user_id = ?');
        $stmt->execute([$templateId, $user['id']]);
        if (!$stmt->fetch()) jsonResponse(['error' => 'Template non trouve'], 404);

        $stmt = db()->prepare('
            SELECT rh.*, l.name as location_name, l.city as location_city
            FROM report_history rh
            LEFT JOIN gbp_locations l ON rh.location_id = l.id
            WHERE rh.template_id = ?
            ORDER BY rh.sent_at DESC
            LIMIT 50
        ');
        $stmt->execute([$templateId]);
        $history = $stmt->fetchAll();

        jsonResponse(['history' => $history]);
        break;

    // ====== ENVOI MANUEL IMMEDIAT ======
    case 'trigger_send':
        $templateId = $_POST['template_id'] ?? null;
        if (!$templateId) jsonResponse(['error' => 'template_id requis'], 400);

        // Verifier propriete
        $stmt = db()->prepare('SELECT * FROM report_templates WHERE id = ? AND user_id = ?');
        $stmt->execute([$templateId, $user['id']]);
        $template = $stmt->fetch();
        if (!$template) jsonResponse(['error' => 'Template non trouve'], 404);

        // Recuperer les destinataires actifs
        $stmt = db()->prepare('
            SELECT rr.*, l.name as location_name, l.city as location_city, l.id as loc_id, a.id as account_id
            FROM report_recipients rr
            JOIN gbp_locations l ON rr.location_id = l.id
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            WHERE rr.template_id = ? AND rr.is_active = 1
        ');
        $stmt->execute([$templateId]);
        $recipients = $stmt->fetchAll();

        if (empty($recipients)) {
            jsonResponse(['error' => 'Aucun destinataire actif pour ce template'], 400);
        }

        $sent = 0;
        $failed = 0;
        $errors = [];
        // Periode = mois selectionne ou mois precedent par defaut
        $selectedMonth = trim($_POST['month'] ?? '');
        if ($selectedMonth && preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
            $firstDay = new DateTime($selectedMonth . '-01', new DateTimeZone('Europe/Paris'));
            $lastDay = (clone $firstDay)->modify('last day of this month');
        } else {
            $lastDay = new DateTime('last day of previous month', new DateTimeZone('Europe/Paris'));
            $firstDay = new DateTime('first day of previous month', new DateTimeZone('Europe/Paris'));
        }
        $period = strftime_fr($firstDay->getTimestamp());
        $endDate = $lastDay->format('Y-m-d');
        $startDate = (clone $firstDay)->modify('-5 months')->format('Y-m-d');

        // Charger le generateur PDF
        require_once __DIR__ . '/../includes/report-generator.php';
        $generator = new ReportGenerator();
        $sections = json_decode($template['sections'] ?? '{}', true) ?: [];

        foreach ($recipients as $rcpt) {
            // Preparer sujet et corps
            $contactName = $rcpt['recipient_name'] ?? '';
            $subject = str_replace(
                ['{client_name}', '{period}', '{sender_name}', '{contact_name}'],
                [$rcpt['location_name'], $period, $user['name'], $contactName],
                $template['email_subject'] ?? 'Rapport SEO'
            );

            $body = $rcpt['custom_email_body'] ?: ($template['email_body'] ?? '');
            $body = str_replace(
                ['{client_name}', '{period}', '{sender_name}', '{contact_name}'],
                [$rcpt['location_name'], $period, $user['name'], $contactName],
                $body
            );

            // Generer le PDF
            $pdfPath = null;
            try {
                $pdfPath = $generator->generate((int)$rcpt['loc_id'], $sections, $startDate, $endDate, $period);
            } catch (Exception $e) {
                // Le PDF est optionnel, on continue sans
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
            $stmt2->execute([$templateId, $rcpt['loc_id'], $rcpt['recipient_email'], $status]);

            if ($result['success']) {
                $sent++;
            } else {
                $failed++;
                $errors[] = $rcpt['recipient_email'] . ': ' . ($result['error'] ?? 'Erreur inconnue');
            }
        }

        $response = ['success' => true, 'sent' => $sent, 'failed' => $failed];
        if ($errors) $response['errors'] = $errors;
        jsonResponse($response);
        break;

    // ====== ENVOYER UN TEST ======
    case 'send_test':
        $templateId = $_POST['template_id'] ?? null;
        $testEmail = trim($_POST['test_email'] ?? '');

        if (!$templateId || !$testEmail) {
            jsonResponse(['error' => 'template_id et test_email requis'], 400);
        }

        // Verifier propriete
        $stmt = db()->prepare('SELECT * FROM report_templates WHERE id = ? AND user_id = ?');
        $stmt->execute([$templateId, $user['id']]);
        $template = $stmt->fetch();
        if (!$template) jsonResponse(['error' => 'Template non trouve'], 404);

        // Trouver une fiche du user pour generer un PDF de demo
        $stmt = db()->prepare('
            SELECT l.id FROM gbp_locations l
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            WHERE a.user_id = ? AND l.is_active = 1
            LIMIT 1
        ');
        $stmt->execute([$user['id']]);
        $demoLocation = $stmt->fetch();

        // Generer le PDF de test si possible
        $pdfPath = null;
        $pdfDebug = '';
        if ($demoLocation) {
            require_once __DIR__ . '/../includes/report-generator.php';
            $generator = new ReportGenerator();
            $sections = json_decode($template['sections'] ?? '{}', true) ?: [];
            // Si aucune section cochee, activer tout par defaut pour le test
            if (empty($sections)) {
                $sections = ['google_stats' => true, 'keyword_positions' => true, 'reviews_summary' => true, 'posts_summary' => true];
            }
            // Periode = mois selectionne ou mois precedent par defaut
            $selMonth = trim($_POST['month'] ?? '');
            if ($selMonth && preg_match('/^\d{4}-\d{2}$/', $selMonth)) {
                $firstDayT = new DateTime($selMonth . '-01', new DateTimeZone('Europe/Paris'));
                $lastDayT = (clone $firstDayT)->modify('last day of this month');
            } else {
                $lastDayT = new DateTime('last day of previous month', new DateTimeZone('Europe/Paris'));
                $firstDayT = new DateTime('first day of previous month', new DateTimeZone('Europe/Paris'));
            }
            $endDate = $lastDayT->format('Y-m-d');
            $startDate = (clone $firstDayT)->modify('-5 months')->format('Y-m-d');
            $testPeriodLabel = strftime_fr($firstDayT->getTimestamp());
            try {
                $pdfPath = $generator->generate((int)$demoLocation['id'], $sections, $startDate, $endDate, $testPeriodLabel);
                if ($pdfPath) {
                    $pdfDebug = 'PDF genere: ' . $pdfPath . ' (' . (file_exists($pdfPath) ? filesize($pdfPath) . ' octets' : 'FICHIER INTROUVABLE') . ')';
                } else {
                    $pdfDebug = 'generate() a retourne null — FPDF probablement absent (class_exists FPDF: ' . (class_exists('FPDF') ? 'oui' : 'non') . ')';
                }
            } catch (Exception $e) {
                $pdfDebug = 'Exception PDF: ' . $e->getMessage();
            }
        } else {
            $pdfDebug = 'Aucune fiche trouvee pour le user';
        }

        // Preparer le contenu de test — periode = mois precedent
        $firstDayPrevP = new DateTime('first day of previous month', new DateTimeZone('Europe/Paris'));
        $period = strftime_fr($firstDayPrevP->getTimestamp());
        $subject = str_replace(
            ['{client_name}', '{period}', '{sender_name}', '{contact_name}'],
            ['[TEST] Fiche exemple', $period, $user['name'], '[Prenom client]'],
            $template['email_subject'] ?? 'Rapport SEO'
        );

        $body = $template['email_body'] ?? "Ceci est un email de test du template \"{$template['name']}\".";
        $body = str_replace(
            ['{client_name}', '{period}', '{sender_name}', '{contact_name}'],
            ['[TEST] Fiche exemple', $period, $user['name'], '[Prenom client]'],
            $body
        );

        // Envoyer l'email de test (avec PDF si disponible)
        $result = sendReportEmail($testEmail, '', $subject, $body, $pdfPath);

        if ($result['success']) {
            $msg = 'Email de test envoye a ' . $testEmail;
            if ($pdfPath) $msg .= ' (avec rapport PDF)';
            jsonResponse(['success' => true, 'message' => $msg, 'debug_pdf' => $pdfDebug]);
        } else {
            jsonResponse(['error' => 'Erreur envoi: ' . ($result['error'] ?? 'Inconnue')], 500);
        }
        break;

    // ====== AJOUT GROUPE DE DESTINATAIRES ======
    case 'bulk_add_recipients':
        $templateId = $_POST['template_id'] ?? null;
        $recipientsJson = $_POST['recipients'] ?? '[]';

        if (!$templateId) jsonResponse(['error' => 'template_id requis'], 400);

        $items = json_decode($recipientsJson, true);
        if (!is_array($items) || empty($items)) {
            jsonResponse(['error' => 'Aucun destinataire a ajouter'], 400);
        }

        // Verifier propriete du template
        $stmt = db()->prepare('SELECT id FROM report_templates WHERE id = ? AND user_id = ?');
        $stmt->execute([$templateId, $user['id']]);
        if (!$stmt->fetch()) jsonResponse(['error' => 'Template non trouve'], 404);

        // Recuperer les fiches du user pour validation
        $stmt = db()->prepare('
            SELECT l.id FROM gbp_locations l
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            WHERE a.user_id = ? AND l.is_active = 1
        ');
        $stmt->execute([$user['id']]);
        $validLocIds = array_column($stmt->fetchAll(), 'id');

        // Recuperer destinataires existants pour eviter les doublons
        $stmt = db()->prepare('SELECT location_id, recipient_email FROM report_recipients WHERE template_id = ?');
        $stmt->execute([$templateId]);
        $existing = [];
        foreach ($stmt->fetchAll() as $row) {
            $existing[$row['location_id'] . '|' . strtolower($row['recipient_email'])] = true;
        }

        $added = 0;
        $skipped = 0;
        $insertStmt = db()->prepare('
            INSERT INTO report_recipients (template_id, location_id, recipient_email, recipient_name, is_active)
            VALUES (?, ?, ?, ?, 1)
        ');

        foreach ($items as $item) {
            $locId = $item['location_id'] ?? null;
            $email = trim($item['email'] ?? '');
            $name = trim($item['name'] ?? '');

            if (!$locId || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }
            if (!in_array($locId, $validLocIds)) {
                $skipped++;
                continue;
            }
            $key = $locId . '|' . strtolower($email);
            if (isset($existing[$key])) {
                $skipped++;
                continue;
            }

            try {
                $insertStmt->execute([$templateId, $locId, $email, $name]);
                $existing[$key] = true;
                $added++;
            } catch (Exception $e) {
                $skipped++;
            }
        }

        jsonResponse(['success' => true, 'added' => $added, 'skipped' => $skipped]);
        break;

    // ====== GENERER UN PDF D'APERCU ======
    case 'generate_preview_pdf':
        $locationId = $_POST['location_id'] ?? $_GET['location_id'] ?? null;
        if (!$locationId) jsonResponse(['error' => 'location_id requis'], 400);

        // Verifier propriete
        $stmt = db()->prepare('
            SELECT l.id FROM gbp_locations l
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            WHERE l.id = ? AND a.user_id = ?
        ');
        $stmt->execute([$locationId, $user['id']]);
        if (!$stmt->fetch()) jsonResponse(['error' => 'Fiche non trouvee'], 404);

        // Periode = mois selectionne ou mois precedent
        $selMonth = trim($_POST['month'] ?? '');
        if ($selMonth && preg_match('/^\d{4}-\d{2}$/', $selMonth)) {
            $firstDay = new DateTime($selMonth . '-01', new DateTimeZone('Europe/Paris'));
            $lastDay = (clone $firstDay)->modify('last day of this month');
        } else {
            $lastDay = new DateTime('last day of previous month', new DateTimeZone('Europe/Paris'));
            $firstDay = new DateTime('first day of previous month', new DateTimeZone('Europe/Paris'));
        }
        $endDate = $lastDay->format('Y-m-d');
        $startDate = (clone $firstDay)->modify('-5 months')->format('Y-m-d');
        $periodLabel = strftime_fr($firstDay->getTimestamp());

        require_once __DIR__ . '/../includes/report-generator.php';
        $generator = new ReportGenerator();
        $sections = ['google_stats' => true, 'keyword_positions' => true, 'reviews_summary' => true, 'posts_summary' => true];

        try {
            $pdfPath = $generator->generate((int)$locationId, $sections, $startDate, $endDate, $periodLabel);
            if ($pdfPath && file_exists($pdfPath)) {
                $filename = 'preview_' . $locationId . '_' . date('Ymd_His') . '.pdf';
                $targetDir = __DIR__ . '/../reports/';
                if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
                $target = $targetDir . $filename;
                copy($pdfPath, $target);
                jsonResponse(['success' => true, 'pdf_url' => APP_URL . '/reports/' . $filename]);
            } else {
                jsonResponse(['error' => 'Echec de generation du PDF (FPDF absent ?)'], 500);
            }
        } catch (Exception $e) {
            jsonResponse(['error' => 'Erreur: ' . $e->getMessage()], 500);
        }
        break;

    default:
        jsonResponse(['error' => 'Action non reconnue'], 400);
}

/**
 * Helper : formatage date en francais
 */
function strftime_fr(int $timestamp): string {
    $mois = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
             'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    return $mois[(int)date('n', $timestamp)] . ' ' . date('Y', $timestamp);
}
