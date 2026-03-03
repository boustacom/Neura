<?php
/**
 * Neura — API Photos GBP
 * Upload, gestion et publication de photos sur Google Business Profile
 * Stockage avec URLs SEO-friendly
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$user = currentUser();

switch ($action) {

    // ================================================================
    // LIST — Photos d'une fiche
    // ================================================================
    case 'list':
        $locationId = (int)($_GET['location_id'] ?? 0);
        if (!$locationId) { echo json_encode(['error' => 'location_id requis']); break; }

        // Stats
        $stmtStats = db()->prepare('
            SELECT
                COUNT(*) as total,
                SUM(status = "published") as published,
                SUM(status = "draft") as draft,
                SUM(status = "failed") as failed
            FROM location_photos WHERE location_id = ?
        ');
        $stmtStats->execute([$locationId]);
        $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

        // Photos
        $category = $_GET['category'] ?? '';
        $sql = 'SELECT * FROM location_photos WHERE location_id = ?';
        $params = [$locationId];
        if ($category) {
            $sql .= ' AND category = ?';
            $params[] = $category;
        }
        $sql .= ' ORDER BY created_at DESC';

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['photos' => $photos, 'stats' => $stats]);
        break;

    // ================================================================
    // UPLOAD — Upload photo(s) avec URL SEO
    // ================================================================
    case 'upload':
        $locationId = (int)($_POST['location_id'] ?? 0);
        if (!$locationId) { echo json_encode(['error' => 'location_id requis']); break; }

        $seoKeyword = trim($_POST['seo_keyword'] ?? '');
        $category = $_POST['category'] ?? 'ADDITIONAL';
        $caption = trim($_POST['caption'] ?? '');

        // Valider catégorie
        $validCategories = ['COVER','PROFILE','EXTERIOR','INTERIOR','PRODUCT','AT_WORK','FOOD_AND_DRINK','TEAMS','ADDITIONAL'];
        if (!in_array($category, $validCategories)) $category = 'ADDITIONAL';

        // Récupérer infos fiche pour slug SEO
        $stmtLoc = db()->prepare('SELECT name, city FROM gbp_locations WHERE id = ?');
        $stmtLoc->execute([$locationId]);
        $location = $stmtLoc->fetch(PDO::FETCH_ASSOC);
        if (!$location) { echo json_encode(['error' => 'Fiche introuvable']); break; }

        $clientSlug = slugify($location['name'] ?? 'client');

        // Gérer upload multiple
        if (empty($_FILES['photos']) || empty($_FILES['photos']['name'][0])) {
            echo json_encode(['error' => 'Aucun fichier envoyé']);
            break;
        }

        $uploaded = [];
        $errors = [];
        $fileCount = count($_FILES['photos']['name']);

        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = $_FILES['photos']['name'][$i] . ': erreur upload';
                continue;
            }

            $tmpPath = $_FILES['photos']['tmp_name'][$i];
            $originalName = $_FILES['photos']['name'][$i];
            $mimeType = mime_content_type($tmpPath);

            // Valider type
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
            if (!in_array($mimeType, $allowedMimes)) {
                $errors[] = $originalName . ': type non supporté (JPG, PNG, WEBP)';
                continue;
            }

            // Valider taille (10MB max)
            if ($_FILES['photos']['size'][$i] > 10 * 1024 * 1024) {
                $errors[] = $originalName . ': fichier trop volumineux (max 10MB)';
                continue;
            }

            // Insérer en DB d'abord pour avoir l'ID
            $stmtInsert = db()->prepare('
                INSERT INTO location_photos (location_id, category, seo_keyword, caption, file_path, file_url)
                VALUES (?, ?, ?, ?, "", "")
            ');
            $stmtInsert->execute([$locationId, $category, $seoKeyword ?: null, $caption ?: null]);
            $photoId = db()->lastInsertId();

            // Construire chemin SEO
            if ($seoKeyword) {
                $kwSlug = slugify($seoKeyword);
                $relPath = "{$clientSlug}/{$kwSlug}/{$photoId}.jpg";
            } else {
                $catSlug = slugify($category);
                $relPath = "{$clientSlug}/{$catSlug}/{$photoId}.jpg";
            }

            $fullPath = MEDIA_PATH . '/' . $relPath;
            $fullUrl = MEDIA_URL . '/' . $relPath;

            // Créer répertoire
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Optimiser l'image : redimensionner max 2048px, convertir en JPEG 85%
            try {
                $img = new Imagick($tmpPath);
                $img->setImageFormat('jpeg');
                $img->setImageCompressionQuality(85);
                $img->stripImage(); // Supprimer EXIF pour la vie privée

                // Redimensionner si > 2048px
                $w = $img->getImageWidth();
                $h = $img->getImageHeight();
                if ($w > 2048 || $h > 2048) {
                    $img->resizeImage(2048, 2048, Imagick::FILTER_LANCZOS, 1, true);
                    $w = $img->getImageWidth();
                    $h = $img->getImageHeight();
                }

                $img->writeImage($fullPath);
                $fileSize = filesize($fullPath);
                $img->destroy();
            } catch (Exception $e) {
                // Fallback : copier directement
                copy($tmpPath, $fullPath);
                $fileSize = filesize($fullPath);
                $imgInfo = @getimagesize($fullPath);
                $w = $imgInfo[0] ?? 0;
                $h = $imgInfo[1] ?? 0;
            }

            // Mettre à jour DB avec le chemin final
            $stmtUpdate = db()->prepare('
                UPDATE location_photos
                SET file_path = ?, file_url = ?, file_size = ?, width = ?, height = ?
                WHERE id = ?
            ');
            $stmtUpdate->execute([$relPath, $fullUrl, $fileSize, $w, $h, $photoId]);

            $uploaded[] = [
                'id' => (int)$photoId,
                'file_url' => $fullUrl,
                'file_path' => $relPath,
                'width' => $w,
                'height' => $h,
                'file_size' => $fileSize,
                'category' => $category,
                'seo_keyword' => $seoKeyword,
                'status' => 'draft',
            ];
        }

        echo json_encode([
            'success' => true,
            'uploaded' => $uploaded,
            'errors' => $errors,
            'count' => count($uploaded),
        ]);
        break;

    // ================================================================
    // UPDATE — Modifier catégorie, mot-clé SEO, légende
    // ================================================================
    case 'update':
        $photoId = (int)($_POST['id'] ?? 0);
        if (!$photoId) { echo json_encode(['error' => 'id requis']); break; }

        $fields = [];
        $params = [];

        if (isset($_POST['category'])) {
            $fields[] = 'category = ?';
            $params[] = $_POST['category'];
        }
        if (isset($_POST['seo_keyword'])) {
            $fields[] = 'seo_keyword = ?';
            $params[] = $_POST['seo_keyword'] ?: null;
        }
        if (isset($_POST['caption'])) {
            $fields[] = 'caption = ?';
            $params[] = $_POST['caption'] ?: null;
        }

        if (empty($fields)) { echo json_encode(['error' => 'Rien à modifier']); break; }

        $params[] = $photoId;
        $stmt = db()->prepare('UPDATE location_photos SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($params);

        echo json_encode(['success' => true]);
        break;

    // ================================================================
    // DELETE — Supprimer une photo
    // ================================================================
    case 'delete':
        $photoId = (int)($_POST['id'] ?? 0);
        if (!$photoId) { echo json_encode(['error' => 'id requis']); break; }

        // Récupérer le chemin fichier
        $stmt = db()->prepare('SELECT file_path FROM location_photos WHERE id = ?');
        $stmt->execute([$photoId]);
        $photo = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($photo && $photo['file_path']) {
            $fullPath = MEDIA_PATH . '/' . $photo['file_path'];
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }

        $stmt = db()->prepare('DELETE FROM location_photos WHERE id = ?');
        $stmt->execute([$photoId]);

        echo json_encode(['success' => true]);
        break;

    // ================================================================
    // BULK_DELETE — Suppression groupée
    // ================================================================
    case 'bulk_delete':
        $ids = $_POST['ids'] ?? [];
        if (is_string($ids)) $ids = json_decode($ids, true);
        if (empty($ids)) { echo json_encode(['error' => 'ids requis']); break; }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // Supprimer fichiers
        $stmt = db()->prepare("SELECT file_path FROM location_photos WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['file_path']) {
                $fullPath = MEDIA_PATH . '/' . $row['file_path'];
                if (file_exists($fullPath)) @unlink($fullPath);
            }
        }

        $stmt = db()->prepare("DELETE FROM location_photos WHERE id IN ({$placeholders})");
        $stmt->execute($ids);

        echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
        break;

    // ================================================================
    // PUBLISH — Publier une photo sur Google Business Profile
    // ================================================================
    case 'publish':
        $photoId = (int)($_POST['id'] ?? 0);
        $locationId = (int)($_POST['location_id'] ?? 0);
        if (!$photoId || !$locationId) { echo json_encode(['error' => 'id et location_id requis']); break; }

        // Récupérer photo
        $stmt = db()->prepare('SELECT * FROM location_photos WHERE id = ?');
        $stmt->execute([$photoId]);
        $photo = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$photo) { echo json_encode(['error' => 'Photo introuvable']); break; }

        // Récupérer infos GBP
        $stmtLoc = db()->prepare('
            SELECT l.google_location_id, a.google_account_name, a.access_token, a.token_expires_at, a.refresh_token
            FROM gbp_locations l
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            WHERE l.id = ?
        ');
        $stmtLoc->execute([$locationId]);
        $loc = $stmtLoc->fetch(PDO::FETCH_ASSOC);
        if (!$loc) { echo json_encode(['error' => 'Fiche GBP non trouvée']); break; }

        // Refresh token si nécessaire
        $token = $loc['access_token'];
        if (strtotime($loc['token_expires_at'] ?? '2000-01-01') < time() && $loc['refresh_token']) {
            $refreshed = refreshGoogleToken($loc['refresh_token']);
            if ($refreshed && !empty($refreshed['access_token'])) {
                $token = $refreshed['access_token'];
            }
        }

        // Publier
        $result = publishPhotoToGoogle(
            $photo,
            $loc['google_location_id'],
            $token,
            $loc['google_account_name'],
            $locationId
        );

        if ($result['success']) {
            $stmtUp = db()->prepare('UPDATE location_photos SET status = "published", google_media_name = ?, published_at = NOW(), error_message = NULL WHERE id = ?');
            $stmtUp->execute([$result['google_media_name'] ?? null, $photoId]);
            echo json_encode(['success' => true, 'google_media_name' => $result['google_media_name'] ?? '']);
        } else {
            $stmtUp = db()->prepare('UPDATE location_photos SET status = "failed", error_message = ? WHERE id = ?');
            $stmtUp->execute([$result['error'] ?? 'Erreur inconnue', $photoId]);
            echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Erreur inconnue']);
        }
        break;

    default:
        echo json_encode(['error' => 'Action inconnue: ' . $action]);
        break;
}
