<?php
/**
 * BOUS'TACOM — API Gestion des Auto Lists (Google Posts)
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();
requireCsrf();

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$locationId = $_POST['location_id'] ?? $_GET['location_id'] ?? null;

if (!$locationId) {
    jsonResponse(['error' => 'location_id requis'], 400);
}

switch ($action) {

    // ====== LISTER LES LISTES ======
    case 'list':
        $stmt = db()->prepare('
            SELECT pl.*,
                COUNT(gp.id) as post_count,
                SUM(CASE WHEN gp.status = "published" THEN 1 ELSE 0 END) as published_count,
                SUM(CASE WHEN gp.status = "list_pending" THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN gp.status = "failed" THEN 1 ELSE 0 END) as failed_count
            FROM post_lists pl
            LEFT JOIN google_posts gp ON gp.list_id = pl.id
            WHERE pl.location_id = ?
            GROUP BY pl.id
            ORDER BY pl.is_active DESC, pl.created_at DESC
        ');
        $stmt->execute([$locationId]);
        $lists = $stmt->fetchAll();

        // Stats globales
        $activeCount = 0;
        $totalPending = 0;
        foreach ($lists as $l) {
            if ($l['is_active']) $activeCount++;
            $totalPending += (int)$l['pending_count'];
        }

        jsonResponse([
            'lists' => $lists,
            'stats' => [
                'total' => count($lists),
                'active' => $activeCount,
                'total_pending' => $totalPending,
            ]
        ]);
        break;

    // ====== DÉTAIL D'UNE LISTE AVEC SES POSTS ======
    case 'get':
        $listId = $_GET['list_id'] ?? null;
        if (!$listId) {
            jsonResponse(['error' => 'list_id requis'], 400);
        }

        $stmt = db()->prepare('SELECT * FROM post_lists WHERE id = ? AND location_id = ?');
        $stmt->execute([$listId, $locationId]);
        $list = $stmt->fetch();

        if (!$list) {
            jsonResponse(['error' => 'Liste non trouvée'], 404);
        }

        // Posts de la liste, triés par list_order
        $stmt = db()->prepare('
            SELECT * FROM google_posts
            WHERE list_id = ? AND location_id = ?
            ORDER BY list_order ASC
        ');
        $stmt->execute([$listId, $locationId]);
        $posts = $stmt->fetchAll();

        jsonResponse(['success' => true, 'list' => $list, 'posts' => $posts]);
        break;

    // ====== CRÉER UNE LISTE ======
    case 'create':
        $name = trim($_POST['name'] ?? '');
        $scheduleDays = trim($_POST['schedule_days'] ?? '1,2,3,4,5');
        $scheduleTimes = trim($_POST['schedule_times'] ?? '09:00');
        $isRepeat = (int)($_POST['is_repeat'] ?? 0);

        if (!$name) {
            jsonResponse(['error' => 'Le nom de la liste est requis'], 400);
        }

        // Valider les jours (1-7)
        $days = array_filter(array_map('intval', explode(',', $scheduleDays)), fn($d) => $d >= 1 && $d <= 7);
        if (empty($days)) {
            jsonResponse(['error' => 'Sélectionnez au moins un jour de publication'], 400);
        }
        $scheduleDays = implode(',', $days);

        // Valider les heures (HH:MM)
        $times = array_filter(array_map('trim', explode(',', $scheduleTimes)), function($t) {
            return preg_match('/^\d{2}:\d{2}$/', $t);
        });
        if (empty($times)) {
            jsonResponse(['error' => 'Ajoutez au moins un horaire de publication'], 400);
        }
        sort($times);
        $scheduleTimes = implode(',', $times);

        try {
            $stmt = db()->prepare('
                INSERT INTO post_lists (location_id, name, schedule_days, schedule_times, is_repeat)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([$locationId, $name, $scheduleDays, $scheduleTimes, $isRepeat ? 1 : 0]);
            jsonResponse(['success' => true, 'id' => db()->lastInsertId()]);
        } catch (Exception $e) {
            jsonResponse(['error' => 'Erreur création: ' . $e->getMessage()], 500);
        }
        break;

    // ====== MODIFIER UNE LISTE ======
    case 'update':
        $listId = $_POST['list_id'] ?? null;
        $name = trim($_POST['name'] ?? '');
        $scheduleDays = trim($_POST['schedule_days'] ?? '');
        $scheduleTimes = trim($_POST['schedule_times'] ?? '');
        $isRepeat = (int)($_POST['is_repeat'] ?? 0);

        if (!$listId || !$name) {
            jsonResponse(['error' => 'list_id et nom requis'], 400);
        }

        // Valider les jours
        $days = array_filter(array_map('intval', explode(',', $scheduleDays)), fn($d) => $d >= 1 && $d <= 7);
        if (empty($days)) {
            jsonResponse(['error' => 'Sélectionnez au moins un jour'], 400);
        }
        $scheduleDays = implode(',', $days);

        // Valider les heures
        $times = array_filter(array_map('trim', explode(',', $scheduleTimes)), function($t) {
            return preg_match('/^\d{2}:\d{2}$/', $t);
        });
        if (empty($times)) {
            jsonResponse(['error' => 'Ajoutez au moins un horaire'], 400);
        }
        sort($times);
        $scheduleTimes = implode(',', $times);

        try {
            $stmt = db()->prepare('
                UPDATE post_lists SET
                    name = ?, schedule_days = ?, schedule_times = ?,
                    is_repeat = ?, updated_at = NOW()
                WHERE id = ? AND location_id = ?
            ');
            $stmt->execute([$name, $scheduleDays, $scheduleTimes, $isRepeat ? 1 : 0, $listId, $locationId]);
            jsonResponse(['success' => true]);
        } catch (Exception $e) {
            jsonResponse(['error' => 'Erreur modification: ' . $e->getMessage()], 500);
        }
        break;

    // ====== SUPPRIMER UNE LISTE ======
    case 'delete':
        $listId = $_POST['list_id'] ?? null;
        if (!$listId) {
            jsonResponse(['error' => 'list_id requis'], 400);
        }

        // Les posts deviennent standalone (draft)
        $stmt = db()->prepare('
            UPDATE google_posts SET
                list_id = NULL, list_order = 0, status = "draft"
            WHERE list_id = ? AND location_id = ?
        ');
        $stmt->execute([$listId, $locationId]);

        // Supprimer la liste
        $stmt = db()->prepare('DELETE FROM post_lists WHERE id = ? AND location_id = ?');
        $stmt->execute([$listId, $locationId]);

        jsonResponse(['success' => true]);
        break;

    // ====== ACTIVER / DÉSACTIVER ======
    case 'toggle_active':
        $listId = $_POST['list_id'] ?? null;
        if (!$listId) {
            jsonResponse(['error' => 'list_id requis'], 400);
        }

        $stmt = db()->prepare('UPDATE post_lists SET is_active = NOT is_active, updated_at = NOW() WHERE id = ? AND location_id = ?');
        $stmt->execute([$listId, $locationId]);

        // Retourner le nouveau statut
        $stmt = db()->prepare('SELECT is_active FROM post_lists WHERE id = ?');
        $stmt->execute([$listId]);
        $row = $stmt->fetch();

        jsonResponse(['success' => true, 'is_active' => (int)$row['is_active']]);
        break;

    // ====== ACTIVER / DÉSACTIVER LA RÉPÉTITION ======
    case 'toggle_repeat':
        $listId = $_POST['list_id'] ?? null;
        if (!$listId) {
            jsonResponse(['error' => 'list_id requis'], 400);
        }

        $stmt = db()->prepare('UPDATE post_lists SET is_repeat = NOT is_repeat, updated_at = NOW() WHERE id = ? AND location_id = ?');
        $stmt->execute([$listId, $locationId]);

        $stmt = db()->prepare('SELECT is_repeat FROM post_lists WHERE id = ?');
        $stmt->execute([$listId]);
        $row = $stmt->fetch();

        jsonResponse(['success' => true, 'is_repeat' => (int)$row['is_repeat']]);
        break;

    // ====== RÉORDONNER LES POSTS ======
    case 'reorder':
        $listId = $_POST['list_id'] ?? null;
        $postIdsJson = $_POST['post_ids'] ?? '[]';

        if (!$listId) {
            jsonResponse(['error' => 'list_id requis'], 400);
        }

        $postIds = json_decode($postIdsJson, true);
        if (!is_array($postIds) || empty($postIds)) {
            jsonResponse(['error' => 'post_ids invalide'], 400);
        }

        try {
            $stmt = db()->prepare('UPDATE google_posts SET list_order = ? WHERE id = ? AND list_id = ? AND location_id = ?');
            foreach ($postIds as $order => $postId) {
                $stmt->execute([$order, $postId, $listId, $locationId]);
            }
            jsonResponse(['success' => true]);
        } catch (Exception $e) {
            jsonResponse(['error' => 'Erreur réordonnement: ' . $e->getMessage()], 500);
        }
        break;

    // ====== RETIRER UN POST D'UNE LISTE ======
    case 'remove_post':
        $listId = $_POST['list_id'] ?? null;
        $postId = $_POST['post_id'] ?? null;

        if (!$listId || !$postId) {
            jsonResponse(['error' => 'list_id et post_id requis'], 400);
        }

        // Retirer de la liste → devient un brouillon standalone
        $stmt = db()->prepare('
            UPDATE google_posts SET
                list_id = NULL, list_order = 0, status = "draft"
            WHERE id = ? AND list_id = ? AND location_id = ?
        ');
        $stmt->execute([$postId, $listId, $locationId]);

        // Réordonner les posts restants
        $stmt = db()->prepare('
            SELECT id FROM google_posts
            WHERE list_id = ? AND location_id = ?
            ORDER BY list_order ASC
        ');
        $stmt->execute([$listId, $locationId]);
        $remaining = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $updStmt = db()->prepare('UPDATE google_posts SET list_order = ? WHERE id = ?');
        foreach ($remaining as $i => $id) {
            $updStmt->execute([$i, $id]);
        }

        // Ajuster current_index si nécessaire
        $stmt = db()->prepare('SELECT current_index FROM post_lists WHERE id = ?');
        $stmt->execute([$listId]);
        $list = $stmt->fetch();
        $total = count($remaining);

        if ($list && $list['current_index'] >= $total && $total > 0) {
            $stmt = db()->prepare('UPDATE post_lists SET current_index = ? WHERE id = ?');
            $stmt->execute([$total - 1, $listId]);
        }

        jsonResponse(['success' => true]);
        break;

    // ====== RETIRER EN MASSE DES POSTS D'UNE LISTE ======
    case 'bulk_remove':
        $listId = $_POST['list_id'] ?? null;
        $postIds = $_POST['post_ids'] ?? [];
        if (!$listId || empty($postIds) || !is_array($postIds)) {
            jsonResponse(['error' => 'list_id et post_ids[] requis'], 400);
        }

        // Retirer chaque post de la liste → brouillon standalone
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $params = array_map('intval', $postIds);
        $params[] = (int)$listId;
        $params[] = $locationId;
        $stmt = db()->prepare("UPDATE google_posts SET list_id = NULL, list_order = 0, status = 'draft' WHERE id IN ($placeholders) AND list_id = ? AND location_id = ?");
        $stmt->execute($params);
        $removed = $stmt->rowCount();

        // Réordonner les posts restants
        $stmt = db()->prepare('SELECT id FROM google_posts WHERE list_id = ? AND location_id = ? ORDER BY list_order ASC');
        $stmt->execute([$listId, $locationId]);
        $remaining = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $updStmt = db()->prepare('UPDATE google_posts SET list_order = ? WHERE id = ?');
        foreach ($remaining as $i => $id) {
            $updStmt->execute([$i, $id]);
        }

        // Ajuster current_index
        $stmt = db()->prepare('SELECT current_index FROM post_lists WHERE id = ?');
        $stmt->execute([$listId]);
        $list = $stmt->fetch();
        $total = count($remaining);
        if ($list && $total > 0 && $list['current_index'] >= $total) {
            $stmt = db()->prepare('UPDATE post_lists SET current_index = ? WHERE id = ?');
            $stmt->execute([$total - 1, $listId]);
        } elseif ($total === 0) {
            $stmt = db()->prepare('UPDATE post_lists SET current_index = 0 WHERE id = ?');
            $stmt->execute([$listId]);
        }

        jsonResponse(['success' => true, 'removed' => $removed]);
        break;

    // ====== SUPPRIMER DÉFINITIVEMENT EN MASSE ======
    case 'bulk_delete_posts':
        $listId = $_POST['list_id'] ?? null;
        $postIds = $_POST['post_ids'] ?? [];
        if (!$listId || empty($postIds) || !is_array($postIds)) {
            jsonResponse(['error' => 'list_id et post_ids[] requis'], 400);
        }

        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $params = array_map('intval', $postIds);
        $params[] = (int)$listId;
        $params[] = $locationId;
        $stmt = db()->prepare("DELETE FROM google_posts WHERE id IN ($placeholders) AND list_id = ? AND location_id = ?");
        $stmt->execute($params);
        $deleted = $stmt->rowCount();

        // Réordonner les posts restants
        $stmt = db()->prepare('SELECT id FROM google_posts WHERE list_id = ? AND location_id = ? ORDER BY list_order ASC');
        $stmt->execute([$listId, $locationId]);
        $remaining = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $updStmt = db()->prepare('UPDATE google_posts SET list_order = ? WHERE id = ?');
        foreach ($remaining as $i => $id) {
            $updStmt->execute([$i, $id]);
        }

        // Ajuster current_index
        $total = count($remaining);
        $stmt = db()->prepare('UPDATE post_lists SET current_index = ?, updated_at = NOW() WHERE id = ? AND location_id = ?');
        $stmt->execute([min($total > 0 ? $total - 1 : 0, 0), $listId, $locationId]);

        jsonResponse(['success' => true, 'deleted' => $deleted]);
        break;

    // ====== SUPPRIMER TOUS LES POSTS D'UNE LISTE ======
    case 'delete_all_posts':
        $listId = $_POST['list_id'] ?? null;
        if (!$listId) {
            jsonResponse(['error' => 'list_id requis'], 400);
        }

        $stmt = db()->prepare('DELETE FROM google_posts WHERE list_id = ? AND location_id = ?');
        $stmt->execute([$listId, $locationId]);

        // Reset current_index
        $stmt = db()->prepare('UPDATE post_lists SET current_index = 0, updated_at = NOW() WHERE id = ? AND location_id = ?');
        $stmt->execute([$listId, $locationId]);

        jsonResponse(['success' => true]);
        break;

    // ====== IMPORTER CSV DANS UNE LISTE ======
    case 'import_csv':
        $listId = $_POST['list_id'] ?? null;

        if (!$listId) {
            jsonResponse(['error' => 'list_id requis'], 400);
        }

        if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['error' => 'Fichier CSV requis'], 400);
        }

        // Vérifier que la liste existe
        $stmt = db()->prepare('SELECT id FROM post_lists WHERE id = ? AND location_id = ?');
        $stmt->execute([$listId, $locationId]);
        if (!$stmt->fetch()) {
            jsonResponse(['error' => 'Liste non trouvée'], 404);
        }

        $file = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, 'r');
        if (!$handle) {
            jsonResponse(['error' => 'Impossible de lire le fichier CSV'], 500);
        }

        // Détecter le séparateur
        $firstLine = fgets($handle);
        rewind($handle);
        $firstLine = str_replace("\xEF\xBB\xBF", '', $firstLine);
        $semicolonCount = substr_count($firstLine, ';');
        $commaCount = substr_count($firstLine, ',');
        $separator = $semicolonCount >= $commaCount ? ';' : ',';

        // Lire le header
        $header = fgetcsv($handle, 0, $separator);
        if (!$header) {
            fclose($handle);
            jsonResponse(['error' => 'Fichier CSV vide ou mal formaté'], 400);
        }

        $header = array_map(function($col) {
            return strtolower(trim(str_replace("\xEF\xBB\xBF", '', $col)));
        }, $header);

        // Chercher la colonne description
        $descIdx = array_search('description', $header);
        if ($descIdx === false) {
            foreach ($header as $i => $col) {
                if (in_array($col, ['contenu', 'content', 'texte', 'text'])) {
                    $descIdx = $i;
                    break;
                }
            }
        }
        if ($descIdx === false) {
            fclose($handle);
            jsonResponse(['error' => 'Colonne "description" introuvable. Colonnes: ' . implode(', ', $header)], 400);
        }

        // Colonnes optionnelles
        $imgIdx = null;
        foreach ($header as $i => $col) {
            if (in_array($col, ['image', 'image_url', 'photo', 'visuel', 'media'])) { $imgIdx = $i; break; }
        }
        $ctaTypeIdx = null;
        foreach ($header as $i => $col) {
            if (in_array($col, ['cta_type', 'cta', 'action_type', 'bouton'])) { $ctaTypeIdx = $i; break; }
        }
        $ctaUrlIdx = null;
        foreach ($header as $i => $col) {
            if (in_array($col, ['cta_url', 'lien', 'url', 'link'])) { $ctaUrlIdx = $i; break; }
        }

        // Obtenir le prochain list_order
        $stmt = db()->prepare('SELECT COALESCE(MAX(list_order), -1) + 1 FROM google_posts WHERE list_id = ?');
        $stmt->execute([$listId]);
        $nextOrder = (int)$stmt->fetchColumn();

        // Lire les lignes
        $imported = 0;
        try {
            $insertStmt = db()->prepare('
                INSERT INTO google_posts
                    (location_id, list_id, list_order, post_type, content, image_url,
                     call_to_action_type, call_to_action_url, status)
                VALUES (?, ?, ?, "STANDARD", ?, ?, ?, ?, "list_pending")
            ');

            while (($row = fgetcsv($handle, 0, $separator)) !== false) {
                $description = trim($row[$descIdx] ?? '');
                if (!$description) continue;

                if (mb_strlen($description) > 1500) {
                    $description = mb_substr($description, 0, 1500);
                }

                $insertStmt->execute([
                    $locationId,
                    $listId,
                    $nextOrder,
                    $description,
                    $imgIdx !== null ? trim($row[$imgIdx] ?? '') ?: null : null,
                    $ctaTypeIdx !== null ? strtoupper(trim($row[$ctaTypeIdx] ?? '')) ?: null : null,
                    $ctaUrlIdx !== null ? trim($row[$ctaUrlIdx] ?? '') ?: null : null,
                ]);
                $nextOrder++;
                $imported++;
            }
            fclose($handle);

            if ($imported === 0) {
                jsonResponse(['error' => 'Aucun post valide trouvé dans le CSV'], 400);
            }

            jsonResponse([
                'success' => true,
                'imported' => $imported,
                'message' => "{$imported} post(s) ajouté(s) à la liste",
            ]);
        } catch (Exception $e) {
            fclose($handle);
            jsonResponse(['error' => 'Erreur import: ' . $e->getMessage()], 500);
        }
        break;

    // ====== AJOUTER UN POST DANS UNE LISTE ======
    case 'add_post':
        $listId = $_POST['list_id'] ?? null;
        $content = trim($_POST['content'] ?? '');
        $postType = $_POST['post_type'] ?? 'STANDARD';
        $imageUrl = trim($_POST['image_url'] ?? '');
        $ctaType = $_POST['cta_type'] ?? null;
        $ctaUrl = trim($_POST['cta_url'] ?? '');

        if (!$listId || !$content) {
            jsonResponse(['error' => 'list_id et contenu requis'], 400);
        }

        if (mb_strlen($content) > 1500) {
            jsonResponse(['error' => 'Le contenu ne doit pas dépasser 1500 caractères'], 400);
        }

        // Prochain list_order
        $stmt = db()->prepare('SELECT COALESCE(MAX(list_order), -1) + 1 FROM google_posts WHERE list_id = ?');
        $stmt->execute([$listId]);
        $nextOrder = (int)$stmt->fetchColumn();

        try {
            $stmt = db()->prepare('
                INSERT INTO google_posts
                    (location_id, list_id, list_order, post_type, content, image_url,
                     call_to_action_type, call_to_action_url, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, "list_pending")
            ');
            $stmt->execute([
                $locationId, $listId, $nextOrder,
                $postType, $content,
                $imageUrl ?: null, $ctaType ?: null, $ctaUrl ?: null,
            ]);
            jsonResponse(['success' => true, 'id' => db()->lastInsertId()]);
        } catch (Exception $e) {
            jsonResponse(['error' => 'Erreur: ' . $e->getMessage()], 500);
        }
        break;

    // ====== GENERATION BATCH IA ======
    case 'batch_generate':
        set_time_limit(180);

        $count = (int)($_POST['count'] ?? 4);
        $category = $_POST['category'] ?? 'articles';
        $scheduleDay = (int)($_POST['schedule_day'] ?? 1);

        if (!in_array($count, [4, 8, 12, 16])) {
            jsonResponse(['error' => 'Nombre de posts invalide (4, 8, 12 ou 16)'], 400);
        }
        if (!in_array($category, ['faq_ai', 'articles', 'mix'])) {
            jsonResponse(['error' => 'Categorie invalide'], 400);
        }
        if ($scheduleDay < 1 || $scheduleDay > 7) {
            jsonResponse(['error' => 'Jour invalide (1-7)'], 400);
        }

        // Infos de la fiche
        $stmtLoc = db()->prepare('SELECT name, category, city FROM gbp_locations WHERE id = ?');
        $stmtLoc->execute([$locationId]);
        $locInfo = $stmtLoc->fetch(PDO::FETCH_ASSOC);
        if (!$locInfo) {
            jsonResponse(['error' => 'Fiche non trouvee'], 404);
        }

        $businessName = $locInfo['name'] ?? 'l\'entreprise';
        $businessCategory = $locInfo['category'] ?? '';
        $city = $locInfo['city'] ?? '';

        // Description de la categorie pour le prompt
        $categoryDesc = match($category) {
            'faq_ai' => "des questions FAQ optimisees pour apparaitre dans les resultats de recherche IA (Google AI Overview, ChatGPT, Perplexity). Chaque sujet doit etre formule comme une question naturelle que poserait un internaute. Exemples: \"Quel est le meilleur {metier} a {ville} ?\", \"Comment {action} rapidement ?\", \"Pourquoi faire appel a un {metier} professionnel ?\"",
            'articles' => "des sujets d'articles/conseils d'expert montrant l'expertise de l'entreprise. Chaque sujet doit etre un theme concret et utile pour le client. Exemples: \"Les 5 erreurs a eviter quand...\", \"Guide complet pour...\", \"Tout savoir sur...\"",
            'mix' => "un mix alterne entre : (1) des questions FAQ pour la recherche IA et (2) des articles/conseils d'expert. Alterne strictement entre les deux types : impair = FAQ, pair = article.",
        };

        // ETAPE 1 : Generer les sujets (1 appel Claude)
        $subjectsPrompt = "Tu es un expert en SEO local et content marketing.

Genere exactement {$count} sujets de posts Google Business Profile pour l'entreprise \"{$businessName}\"" . ($businessCategory ? " (categorie: {$businessCategory})" : "") . ($city ? " situee a {$city}" : "") . ".

TYPE DE CONTENU : {$categoryDesc}

REGLES :
- Chaque sujet doit etre UNIQUE et couvrir un angle different
- Les sujets doivent etre pertinents pour le secteur \"{$businessCategory}\"" . ($city ? " et la ville de {$city}" : "") . "
- Varie les angles : saisonnier, pratique, comparatif, guide, actualite, FAQ client, conseil pro...
- Chaque sujet fait entre 5 et 20 mots
- Reponds UNIQUEMENT avec un JSON array de strings, sans explication ni markdown
- Format exact : [\"sujet 1\", \"sujet 2\", \"sujet 3\"]

Genere exactement {$count} sujets :";

        try {
            $subjectsRaw = callClaude($subjectsPrompt, 1000);
        } catch (\Throwable $e) {
            jsonResponse(['error' => 'Erreur generation sujets: ' . $e->getMessage()], 500);
        }

        if (!$subjectsRaw) {
            jsonResponse(['error' => 'L\'IA n\'a retourne aucune reponse pour les sujets.'], 500);
        }

        // Parser le JSON (Claude envoie parfois ```json ... ```)
        $subjectsRaw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($subjectsRaw));
        $subjects = json_decode($subjectsRaw, true);

        if (!is_array($subjects) || count($subjects) < 1) {
            jsonResponse(['error' => 'Erreur parsing sujets IA. Reponse: ' . substr($subjectsRaw, 0, 500)], 500);
        }
        $subjects = array_slice($subjects, 0, $count);

        // ETAPE 2 : Generer le contenu de chaque post
        $generatedPosts = [];
        $errors = [];

        foreach ($subjects as $i => $subject) {
            if (!is_string($subject)) continue;
            $subject = trim($subject);
            if (empty($subject)) continue;

            try {
                $prompt = buildPostPrompt($locationId, $subject, 'STANDARD');
                $content = callClaude($prompt, 600);
                if ($content) {
                    $generatedPosts[] = ['subject' => $subject, 'content' => stripMarkdown(trim($content))];
                } else {
                    $errors[] = "Post #" . ($i + 1) . ": reponse vide";
                }
            } catch (\Throwable $e) {
                $errors[] = "Post #" . ($i + 1) . " ({$subject}): " . $e->getMessage();
            }
            usleep(300000); // 0.3s anti rate-limit
        }

        if (empty($generatedPosts)) {
            jsonResponse(['error' => 'Aucun post genere. Erreurs: ' . implode(', ', $errors)], 500);
        }

        // ETAPE 3 : Creer la liste + inserer les posts
        $catLabel = match($category) {
            'faq_ai' => 'FAQ IA',
            'articles' => 'Articles',
            'mix' => 'Mix FAQ+Articles',
        };
        $listName = "Lot IA — {$catLabel} — " . date('d/m/Y');

        $stmt = db()->prepare('
            INSERT INTO post_lists (location_id, name, schedule_days, schedule_times, is_repeat, is_active)
            VALUES (?, ?, ?, ?, 0, 0)
        ');
        $stmt->execute([$locationId, $listName, (string)$scheduleDay, '10:00']);
        $listId = (int)db()->lastInsertId();

        $insertStmt = db()->prepare('
            INSERT INTO google_posts
                (location_id, list_id, list_order, post_type, title, content, status)
            VALUES (?, ?, ?, "STANDARD", ?, ?, "list_pending")
        ');

        foreach ($generatedPosts as $order => $post) {
            $insertStmt->execute([
                $locationId,
                $listId,
                $order,
                $post['subject'],
                $post['content'],
            ]);
        }

        jsonResponse([
            'success' => true,
            'list_id' => $listId,
            'list_name' => $listName,
            'generated' => count($generatedPosts),
            'requested' => $count,
            'errors' => $errors,
        ]);
        break;

    default:
        jsonResponse(['error' => 'Action non reconnue'], 400);
}
