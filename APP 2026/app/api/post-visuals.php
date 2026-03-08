<?php
/**
 * API — Post Visuals : Templates & Génération d'images
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$locationId = $_POST['location_id'] ?? $_GET['location_id'] ?? null;

// Actions qui nécessitent CSRF (mutations)
$mutationActions = ['save_image', 'delete_image', 'generate', 'generate_all', 'validate', 'validate_all', 'import_csv', 'push_to_list', 'save_template', 'upload_logo', 'delete_logo', 'batch_customize', 'bulk_delete', 'bulk_validate', 'bulk_update_template'];
if (in_array($action, $mutationActions)) {
    requireCsrf();
}

switch ($action) {

    // ============================================
    // TEMPLATES
    // ============================================

    case 'list_templates':
        // Seed templates par défaut si vide
        seedDefaultTemplates($_SESSION['user_id'] ?? 1);

        $stmt = db()->prepare("SELECT id, name, slug, width, height, category, thumbnail, is_active, created_at FROM post_templates WHERE is_active = 1 ORDER BY category, name");
        $stmt->execute();
        jsonResponse(['templates' => $stmt->fetchAll()]);
        break;

    case 'get_template':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'ID requis'], 400);

        $stmt = db()->prepare("SELECT * FROM post_templates WHERE id = ?");
        $stmt->execute([$id]);
        $tpl = $stmt->fetch();
        if (!$tpl) jsonResponse(['error' => 'Template non trouvé'], 404);
        $tpl['config'] = json_decode($tpl['config'], true);
        jsonResponse(['template' => $tpl]);
        break;

    case 'save_template':
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $config = $_POST['config'] ?? '';
        $category = trim($_POST['category'] ?? 'general');

        if (!$name) jsonResponse(['error' => 'Nom requis'], 400);
        if (!$config) jsonResponse(['error' => 'Config requise'], 400);

        // Valider le JSON
        $configArr = json_decode($config, true);
        if (!$configArr) jsonResponse(['error' => 'Config JSON invalide'], 400);

        $slug = slugify($name);
        $userId = $_SESSION['user_id'] ?? 1;

        if ($id) {
            $stmt = db()->prepare("UPDATE post_templates SET name = ?, slug = ?, config = ?, category = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$name, $slug, $config, $category, $id]);
        } else {
            // Éviter les doublons de slug
            $existing = db()->prepare("SELECT id FROM post_templates WHERE slug = ?");
            $existing->execute([$slug]);
            if ($existing->fetch()) {
                $slug .= '-' . time();
            }

            $stmt = db()->prepare("INSERT INTO post_templates (user_id, name, slug, config, category) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $name, $slug, $config, $category]);
            $id = db()->lastInsertId();
        }

        jsonResponse(['success' => true, 'id' => $id]);
        break;

    // ============================================
    // IMAGES
    // ============================================

    case 'list_images':
        if (!$locationId) jsonResponse(['error' => 'location_id requis'], 400);

        $stmt = db()->prepare("
            SELECT pi.*, pt.name as template_name, pt.slug as template_slug,
                   gp.status as post_status, gp.published_at as post_published_at
            FROM post_images pi
            LEFT JOIN post_templates pt ON pi.template_id = pt.id
            LEFT JOIN google_posts gp ON pi.google_post_id = gp.id
            WHERE pi.location_id = ? AND pi.status != 'published'
            ORDER BY pi.sort_order ASC, pi.created_at DESC
        ");
        $stmt->execute([$locationId]);
        $images = $stmt->fetchAll();

        // Stats
        $stats = [
            'total' => count($images),
            'draft' => 0, 'preview' => 0, 'validated' => 0, 'generated' => 0, 'published' => 0
        ];
        foreach ($images as $img) {
            $stats[$img['status']] = ($stats[$img['status']] ?? 0) + 1;
        }

        jsonResponse(['images' => $images, 'stats' => $stats]);
        break;

    case 'save_image':
        if (!$locationId) jsonResponse(['error' => 'location_id requis'], 400);

        $id = (int)($_POST['id'] ?? 0);
        $templateId = (int)($_POST['template_id'] ?? 0);
        $visualText = trim($_POST['visual_text'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $ctaText = trim($_POST['cta_text'] ?? '');
        $seoKeyword = trim($_POST['seo_keyword'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $bgColor = trim($_POST['bg_color'] ?? '');
        $textColor = trim($_POST['text_color'] ?? '');

        if (!$visualText) jsonResponse(['error' => 'Texte visuel requis'], 400);
        if (!$templateId) jsonResponse(['error' => 'Template requis'], 400);

        // Construire le JSON variables avec les couleurs custom
        $variables = [];
        if ($bgColor && preg_match('/^#[0-9a-fA-F]{6}$/', $bgColor)) {
            $variables['bg_color'] = $bgColor;
        }
        if ($textColor && preg_match('/^#[0-9a-fA-F]{6}$/', $textColor)) {
            $variables['text_color'] = $textColor;
        }
        $variablesJson = !empty($variables) ? json_encode($variables) : null;

        if ($id) {
            $stmt = db()->prepare("
                UPDATE post_images
                SET template_id = ?, visual_text = ?, description = ?, cta_text = ?, seo_keyword = ?, sort_order = ?, variables = ?,
                    status = CASE WHEN status IN ('generated','published') THEN 'draft' ELSE status END,
                    updated_at = NOW()
                WHERE id = ? AND location_id = ?
            ");
            $stmt->execute([$templateId, $visualText, $description, $ctaText, $seoKeyword ?: null, $sortOrder, $variablesJson, $id, $locationId]);
        } else {
            $stmt = db()->prepare("
                INSERT INTO post_images (location_id, template_id, visual_text, description, cta_text, seo_keyword, sort_order, variables)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$locationId, $templateId, $visualText, $description, $ctaText, $seoKeyword ?: null, $sortOrder, $variablesJson]);
            $id = db()->lastInsertId();
        }

        jsonResponse(['success' => true, 'id' => $id]);
        break;

    case 'delete_image':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'ID requis'], 400);

        // Supprimer le fichier si existant
        $stmt = db()->prepare("SELECT file_path FROM post_images WHERE id = ?");
        $stmt->execute([$id]);
        $img = $stmt->fetch();
        if ($img && $img['file_path']) {
            $fullPath = MEDIA_PATH . '/' . $img['file_path'];
            if (file_exists($fullPath)) unlink($fullPath);
            // Preview aussi
            $previewPath = preg_replace('/\.jpg$/', '-preview.jpg', $fullPath);
            if (file_exists($previewPath)) unlink($previewPath);
        }

        $stmt = db()->prepare("DELETE FROM post_images WHERE id = ?");
        $stmt->execute([$id]);

        jsonResponse(['success' => true]);
        break;

    // ============================================
    // GÉNÉRATION
    // ============================================

    case 'preview':
        // Génère un preview en mémoire et retourne en base64
        $templateId = (int)($_GET['template_id'] ?? $_POST['template_id'] ?? 0);
        $visualText = trim($_GET['visual_text'] ?? $_POST['visual_text'] ?? 'Texte d\'exemple');
        $ctaText = trim($_GET['cta_text'] ?? $_POST['cta_text'] ?? '');
        $lid = (int)($_GET['location_id'] ?? $_POST['location_id'] ?? 0);
        $bgColor = trim($_GET['bg_color'] ?? $_POST['bg_color'] ?? '');
        $textColor = trim($_GET['text_color'] ?? $_POST['text_color'] ?? '');
        $font = trim($_GET['font'] ?? $_POST['font'] ?? '');
        $decoColor = trim($_GET['deco_color'] ?? $_POST['deco_color'] ?? '');

        if (!$templateId) jsonResponse(['error' => 'template_id requis'], 400);

        $blob = generatePostVisualPreview($templateId, $visualText, $ctaText, $lid, $bgColor, $textColor, $font, $decoColor);
        if (!$blob) jsonResponse(['error' => 'Erreur de génération preview'], 500);

        jsonResponse(['success' => true, 'image' => 'data:image/jpeg;base64,' . base64_encode($blob)]);
        break;

    case 'generate':
        // Génère une seule image (version finale haute qualité)
        $imageId = (int)($_POST['image_id'] ?? 0);
        if (!$imageId) jsonResponse(['error' => 'image_id requis'], 400);

        $result = generatePostVisual($imageId, false);
        if (!$result['success']) {
            jsonResponse(['error' => $result['error'] ?? 'Erreur de génération'], 500);
        }

        jsonResponse([
            'success' => true,
            'path' => $result['path'],
            'url' => $result['url'],
            'size' => $result['size']
        ]);
        break;

    case 'batch_customize':
        // Applique template + couleurs à tous les visuels validés (ou brouillons) avant génération
        if (!$locationId) jsonResponse(['error' => 'location_id requis'], 400);

        $templateId = (int)($_POST['template_id'] ?? 0);
        $bgColor = trim($_POST['bg_color'] ?? '');
        $textColor = trim($_POST['text_color'] ?? '');
        $font = trim($_POST['font'] ?? '');
        $decoColor = trim($_POST['deco_color'] ?? '');
        $seoKeyword = trim($_POST['seo_keyword'] ?? '');
        $targetStatus = $_POST['target_status'] ?? 'validated'; // 'validated' ou 'all_pending'

        if (!$templateId) jsonResponse(['error' => 'template_id requis'], 400);

        // Construire les variables JSON
        $allowedFonts = ['montserrat','inter','playfair','space-mono','anton','raleway','poppins','poppins-bold'];
        $variables = [];
        if ($bgColor && preg_match('/^#[0-9a-fA-F]{6}$/', $bgColor)) {
            $variables['bg_color'] = $bgColor;
        }
        if ($textColor && preg_match('/^#[0-9a-fA-F]{6}$/', $textColor)) {
            $variables['text_color'] = $textColor;
        }
        if ($font && in_array($font, $allowedFonts)) {
            $variables['font'] = $font;
        }
        if ($decoColor && preg_match('/^#[0-9a-fA-F]{6}$/', $decoColor)) {
            $variables['deco_color'] = $decoColor;
        }
        $variablesJson = !empty($variables) ? json_encode($variables) : null;

        // Sélectionner les images cibles
        if ($targetStatus === 'all_pending') {
            $statusFilter = "status IN ('draft', 'preview', 'validated')";
        } else {
            $statusFilter = "status = 'validated'";
        }

        // Construire la requête UPDATE (avec ou sans seo_keyword)
        if ($seoKeyword) {
            $stmt = db()->prepare("
                UPDATE post_images
                SET template_id = ?, variables = ?, seo_keyword = ?, updated_at = NOW()
                WHERE location_id = ? AND $statusFilter
            ");
            $stmt->execute([$templateId, $variablesJson, $seoKeyword, $locationId]);
        } else {
            $stmt = db()->prepare("
                UPDATE post_images
                SET template_id = ?, variables = ?, updated_at = NOW()
                WHERE location_id = ? AND $statusFilter
            ");
            $stmt->execute([$templateId, $variablesJson, $locationId]);
        }
        $updated = $stmt->rowCount();

        jsonResponse(['success' => true, 'updated' => $updated]);
        break;

    case 'generate_all':
        // Génère toutes les images en attente (draft, preview, validated) d'une location
        if (!$locationId) jsonResponse(['error' => 'location_id requis'], 400);

        $stmt = db()->prepare("SELECT id FROM post_images WHERE location_id = ? AND status IN ('draft','preview','validated') AND template_id IS NOT NULL");
        $stmt->execute([$locationId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $results = ['total' => count($ids), 'success' => 0, 'errors' => []];

        foreach ($ids as $imgId) {
            $r = generatePostVisual($imgId, false);
            if ($r['success']) {
                $results['success']++;
            } else {
                $results['errors'][] = ['id' => $imgId, 'error' => $r['error'] ?? 'Erreur inconnue'];
            }
        }

        jsonResponse(['success' => true, 'results' => $results]);
        break;

    case 'validate':
        $imageId = (int)($_POST['image_id'] ?? 0);
        if (!$imageId) jsonResponse(['error' => 'image_id requis'], 400);

        $stmt = db()->prepare("UPDATE post_images SET status = 'validated', validated_at = NOW() WHERE id = ?");
        $stmt->execute([$imageId]);
        jsonResponse(['success' => true]);
        break;

    case 'validate_all':
        if (!$locationId) jsonResponse(['error' => 'location_id requis'], 400);

        $stmt = db()->prepare("UPDATE post_images SET status = 'validated', validated_at = NOW() WHERE location_id = ? AND status IN ('draft', 'preview')");
        $stmt->execute([$locationId]);
        jsonResponse(['success' => true, 'count' => $stmt->rowCount()]);
        break;

    // ============================================
    // IMPORT CSV
    // ============================================

    case 'import_csv':
        if (!$locationId) jsonResponse(['error' => 'location_id requis'], 400);

        $templateId = (int)($_POST['template_id'] ?? 0);
        if (!$templateId) jsonResponse(['error' => 'template_id requis'], 400);

        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['error' => 'Fichier CSV requis'], 400);
        }

        $file = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, 'r');
        if (!$handle) jsonResponse(['error' => 'Impossible de lire le fichier'], 500);

        // Détecter le séparateur (virgule ou point-virgule)
        $firstLine = fgets($handle);
        rewind($handle);
        $separator = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        // Lire le header
        $header = fgetcsv($handle, 0, $separator);
        if (!$header) {
            fclose($handle);
            jsonResponse(['error' => 'Header CSV vide'], 400);
        }

        // Normaliser les headers
        $header = array_map(function($h) {
            return strtolower(trim(str_replace(["\xEF\xBB\xBF", '"'], '', $h)));
        }, $header);

        // Mapper les colonnes
        $colVisual = array_search('visual_text', $header);
        if ($colVisual === false) $colVisual = array_search('texte_visuel', $header);
        if ($colVisual === false) $colVisual = array_search('texte', $header);
        if ($colVisual === false) $colVisual = 0;

        $colDesc = array_search('description', $header);
        if ($colDesc === false) $colDesc = array_search('contenu', $header);
        if ($colDesc === false) $colDesc = 1;

        $colCta = array_search('cta_text', $header);
        if ($colCta === false) $colCta = array_search('cta', $header);

        $imported = 0;
        $order = 0;

        // Récupérer le dernier sort_order
        $stmt = db()->prepare("SELECT MAX(sort_order) FROM post_images WHERE location_id = ?");
        $stmt->execute([$locationId]);
        $order = (int)$stmt->fetchColumn() + 1;

        while (($row = fgetcsv($handle, 0, $separator)) !== false) {
            $visualText = trim($row[$colVisual] ?? '');
            if (empty($visualText)) continue;

            $description = trim($row[$colDesc] ?? '');
            $ctaText = $colCta !== false ? trim($row[$colCta] ?? '') : '';

            $stmt = db()->prepare("
                INSERT INTO post_images (location_id, template_id, visual_text, description, cta_text, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$locationId, $templateId, $visualText, $description, $ctaText, $order]);
            $imported++;
            $order++;
        }

        fclose($handle);
        jsonResponse(['success' => true, 'imported' => $imported]);
        break;

    // ============================================
    // LIAISON AVEC GOOGLE POSTS / LISTES AUTO
    // ============================================

    case 'list_existing_lists':
        // Lister les listes auto existantes pour cette location
        if (!$locationId) jsonResponse(['error' => 'location_id requis'], 400);

        $stmt = db()->prepare("
            SELECT pl.id, pl.name, pl.schedule_days, pl.schedule_times, pl.is_repeat, pl.is_active,
                   COUNT(gp.id) as post_count
            FROM post_lists pl
            LEFT JOIN google_posts gp ON gp.list_id = pl.id
            WHERE pl.location_id = ?
            GROUP BY pl.id
            ORDER BY pl.created_at DESC
        ");
        $stmt->execute([$locationId]);
        jsonResponse(['lists' => $stmt->fetchAll()]);
        break;

    case 'push_to_list':
        // Crée une Liste auto + injecte toutes les images générées comme google_posts
        if (!$locationId) jsonResponse(['error' => 'location_id requis'], 400);

        $mode = trim($_POST['mode'] ?? 'new'); // 'new' | 'existing'
        $existingListId = (int)($_POST['list_id'] ?? 0);

        // Paramètres pour nouvelle liste
        $listName = trim($_POST['list_name'] ?? '');
        $scheduleDays = trim($_POST['schedule_days'] ?? '1,3,5');
        $scheduleTimes = trim($_POST['schedule_times'] ?? '09:00');
        $isRepeat = (int)($_POST['is_repeat'] ?? 0);

        // Récupérer les images générées (non encore liées)
        $stmt = db()->prepare("
            SELECT pi.id, pi.file_url, pi.description, pi.visual_text, pi.cta_text
            FROM post_images pi
            WHERE pi.location_id = ? AND pi.status = 'generated' AND pi.google_post_id IS NULL
            ORDER BY pi.sort_order ASC
        ");
        $stmt->execute([$locationId]);
        $images = $stmt->fetchAll();

        if (empty($images)) {
            jsonResponse(['error' => 'Aucune image générée disponible'], 400);
        }

        $listId = $existingListId;

        if ($mode === 'new') {
            if (!$listName) jsonResponse(['error' => 'Nom de la liste requis'], 400);

            // Valider schedule_days (1-7)
            $days = array_filter(array_map('intval', explode(',', $scheduleDays)), fn($d) => $d >= 1 && $d <= 7);
            if (empty($days)) $days = [1, 3, 5];
            $scheduleDays = implode(',', $days);

            // Valider schedule_times (HH:MM)
            $times = array_filter(array_map('trim', explode(',', $scheduleTimes)), fn($t) => preg_match('/^\d{2}:\d{2}$/', $t));
            if (empty($times)) $times = ['09:00'];
            $scheduleTimes = implode(',', $times);

            // Créer la liste
            $stmt = db()->prepare("
                INSERT INTO post_lists (location_id, name, schedule_days, schedule_times, is_repeat, is_active)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$locationId, $listName, $scheduleDays, $scheduleTimes, $isRepeat ? 1 : 0]);
            $listId = (int)db()->lastInsertId();
        }

        if (!$listId) jsonResponse(['error' => 'Liste invalide'], 400);

        // Récupérer le prochain list_order
        $stmt = db()->prepare("SELECT COALESCE(MAX(list_order), -1) + 1 FROM google_posts WHERE list_id = ?");
        $stmt->execute([$listId]);
        $nextOrder = (int)$stmt->fetchColumn();

        // Insérer chaque image comme google_post
        $insertStmt = db()->prepare("
            INSERT INTO google_posts (location_id, list_id, list_order, post_type, content, image_url, status)
            VALUES (?, ?, ?, 'STANDARD', ?, ?, 'list_pending')
        ");
        $updateImgStmt = db()->prepare("
            UPDATE post_images SET google_post_id = ?, status = 'published', published_at = NOW() WHERE id = ?
        ");

        $created = 0;
        foreach ($images as $img) {
            // Le contenu du post = description longue, ou visual_text si pas de description
            $content = !empty($img['description']) ? $img['description'] : ($img['visual_text'] ?? '');
            if (empty($content)) continue;

            // Tronquer à 1500 caractères (limite Google)
            if (mb_strlen($content) > 1500) {
                $content = mb_substr($content, 0, 1500);
            }

            $insertStmt->execute([
                $locationId,
                $listId,
                $nextOrder,
                $content,
                $img['file_url'] ?: null
            ]);

            $postId = (int)db()->lastInsertId();
            $updateImgStmt->execute([$postId, $img['id']]);

            $nextOrder++;
            $created++;
        }

        jsonResponse([
            'success' => true,
            'list_id' => $listId,
            'list_name' => $mode === 'new' ? $listName : null,
            'posts_created' => $created,
            'mode' => $mode
        ]);
        break;

    // ============================================
    // LOGO CLIENT
    // ============================================

    case 'upload_logo':
        $lid = (int)($_POST['location_id'] ?? 0);
        if (!$lid) jsonResponse(['error' => 'location_id requis'], 400);

        if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['error' => 'Fichier logo requis (PNG/JPG/WebP)'], 400);
        }

        $file = $_FILES['logo'];
        $mime = mime_content_type($file['tmp_name']);
        $allowed = ['image/png', 'image/jpeg', 'image/webp'];
        if (!in_array($mime, $allowed)) {
            jsonResponse(['error' => 'Format invalide. Accepté : PNG, JPG, WebP'], 400);
        }

        // Max 2 Mo
        if ($file['size'] > 2 * 1024 * 1024) {
            jsonResponse(['error' => 'Logo trop lourd (max 2 Mo)'], 400);
        }

        // Créer le dossier logos
        $logoDir = MEDIA_PATH . '/logos';
        if (!is_dir($logoDir)) {
            mkdir($logoDir, 0755, true);
        }

        // Supprimer l'ancien logo si existant
        $stmt = db()->prepare("SELECT logo_path FROM gbp_locations WHERE id = ?");
        $stmt->execute([$lid]);
        $oldLogo = $stmt->fetchColumn();
        if ($oldLogo && file_exists($logoDir . '/' . $oldLogo)) {
            unlink($logoDir . '/' . $oldLogo);
        }

        // Nom fichier : location-{id}.{ext}
        $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
        $filename = 'location-' . $lid . '-' . time() . '.' . $ext;

        if (!move_uploaded_file($file['tmp_name'], $logoDir . '/' . $filename)) {
            jsonResponse(['error' => 'Erreur upload'], 500);
        }

        // Optimiser avec Imagick : max 400x400, qualité 90
        try {
            $im = new Imagick($logoDir . '/' . $filename);
            $origW = $im->getImageWidth();
            $origH = $im->getImageHeight();
            if ($origW > 400 || $origH > 400) {
                $im->thumbnailImage(400, 400, true);
            }
            $im->setImageCompressionQuality(90);
            $im->writeImage($logoDir . '/' . $filename);
            $im->clear();
            $im->destroy();
        } catch (Exception $e) {
            // Le logo reste tel quel
        }

        // Stocker en BDD
        $stmt = db()->prepare("UPDATE gbp_locations SET logo_path = ? WHERE id = ?");
        $stmt->execute([$filename, $lid]);

        jsonResponse([
            'success' => true,
            'logo_path' => $filename,
            'logo_url' => MEDIA_URL . '/logos/' . $filename
        ]);
        break;

    case 'delete_logo':
        $lid = (int)($_POST['location_id'] ?? 0);
        if (!$lid) jsonResponse(['error' => 'location_id requis'], 400);

        $stmt = db()->prepare("SELECT logo_path FROM gbp_locations WHERE id = ?");
        $stmt->execute([$lid]);
        $oldLogo = $stmt->fetchColumn();

        if ($oldLogo) {
            $logoFile = MEDIA_PATH . '/logos/' . $oldLogo;
            if (file_exists($logoFile)) unlink($logoFile);

            $stmt = db()->prepare("UPDATE gbp_locations SET logo_path = NULL WHERE id = ?");
            $stmt->execute([$lid]);
        }

        jsonResponse(['success' => true]);
        break;

    case 'get_logo':
        $lid = (int)($_GET['location_id'] ?? 0);
        if (!$lid) jsonResponse(['error' => 'location_id requis'], 400);

        $stmt = db()->prepare("SELECT logo_path FROM gbp_locations WHERE id = ?");
        $stmt->execute([$lid]);
        $logoPath = $stmt->fetchColumn();

        jsonResponse([
            'has_logo' => !empty($logoPath),
            'logo_path' => $logoPath ?: null,
            'logo_url' => $logoPath ? MEDIA_URL . '/logos/' . $logoPath : null
        ]);
        break;

    // ============================================
    // STATS
    // ============================================

    case 'stats':
        if (!$locationId) jsonResponse(['error' => 'location_id requis'], 400);

        $stmt = db()->prepare("
            SELECT status, COUNT(*) as count
            FROM post_images
            WHERE location_id = ?
            GROUP BY status
        ");
        $stmt->execute([$locationId]);
        $rows = $stmt->fetchAll();

        $stats = ['draft' => 0, 'preview' => 0, 'validated' => 0, 'generated' => 0, 'published' => 0];
        foreach ($rows as $r) {
            $stats[$r['status']] = (int)$r['count'];
        }
        $stats['total'] = array_sum($stats);

        jsonResponse(['stats' => $stats]);
        break;

    // ============================================
    // BULK ACTIONS
    // ============================================

    case 'bulk_delete':
        if (!$locationId) jsonResponse(['error' => 'location_id requis'], 400);

        $imageIds = $_POST['image_ids'] ?? [];
        if (empty($imageIds) || !is_array($imageIds)) jsonResponse(['error' => 'image_ids requis (array)'], 400);

        $imageIds = array_map('intval', $imageIds);
        $ph = implode(',', array_fill(0, count($imageIds), '?'));

        // Récupérer les fichiers à supprimer
        $stmt = db()->prepare("SELECT id, file_path FROM post_images WHERE id IN ({$ph}) AND location_id = ?");
        $stmt->execute(array_merge($imageIds, [$locationId]));
        $toDelete = $stmt->fetchAll();

        // Supprimer les fichiers physiques
        foreach ($toDelete as $img) {
            if ($img['file_path'] && file_exists(MEDIA_PATH . '/' . $img['file_path'])) {
                unlink(MEDIA_PATH . '/' . $img['file_path']);
            }
        }

        // Supprimer de la BDD
        $stmt = db()->prepare("DELETE FROM post_images WHERE id IN ({$ph}) AND location_id = ?");
        $stmt->execute(array_merge($imageIds, [$locationId]));
        $deleted = $stmt->rowCount();

        jsonResponse(['success' => true, 'deleted' => $deleted]);
        break;

    case 'bulk_validate':
        if (!$locationId) jsonResponse(['error' => 'location_id requis'], 400);

        $imageIds = $_POST['image_ids'] ?? [];
        if (empty($imageIds) || !is_array($imageIds)) jsonResponse(['error' => 'image_ids requis (array)'], 400);

        $imageIds = array_map('intval', $imageIds);
        $ph = implode(',', array_fill(0, count($imageIds), '?'));

        $stmt = db()->prepare("
            UPDATE post_images SET status = 'validated', updated_at = NOW()
            WHERE id IN ({$ph}) AND location_id = ? AND status IN ('draft', 'preview')
        ");
        $stmt->execute(array_merge($imageIds, [$locationId]));

        jsonResponse(['success' => true, 'validated' => $stmt->rowCount()]);
        break;

    case 'bulk_update_template':
        if (!$locationId) jsonResponse(['error' => 'location_id requis'], 400);

        $imageIds = $_POST['image_ids'] ?? [];
        $templateId = (int)($_POST['template_id'] ?? 0);
        if (empty($imageIds) || !is_array($imageIds)) jsonResponse(['error' => 'image_ids requis (array)'], 400);
        if (!$templateId) jsonResponse(['error' => 'template_id requis'], 400);

        $imageIds = array_map('intval', $imageIds);
        $ph = implode(',', array_fill(0, count($imageIds), '?'));

        $stmt = db()->prepare("
            UPDATE post_images SET template_id = ?, updated_at = NOW()
            WHERE id IN ({$ph}) AND location_id = ?
        ");
        $stmt->execute(array_merge([$templateId], $imageIds, [$locationId]));

        jsonResponse(['success' => true, 'updated' => $stmt->rowCount()]);
        break;

    default:
        jsonResponse(['error' => 'Action non reconnue : ' . $action], 400);
}
