<?php
/**
 * BOUS'TACOM — API Gestion des Google Posts
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();
requireCsrf();

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$locationId = $_POST['location_id'] ?? $_GET['location_id'] ?? null;

// location_id optionnel pour import_csv_multi (qui accepte location_ids[])
if (!$locationId && $action !== 'import_csv_multi') {
    jsonResponse(['error' => 'location_id requis'], 400);
}

// Cast en int pour les fonctions typees (PHP 8 strict)
if ($locationId !== null) {
    $locationId = (int)$locationId;
}

// Recuperer l'account_id et le google_location_id pour cette location
function getLocationInfo(int $locationId): ?array {
    $stmt = db()->prepare('
        SELECT l.*, a.id as account_id, a.google_account_name
        FROM gbp_locations l
        JOIN gbp_accounts a ON l.gbp_account_id = a.id
        WHERE l.id = ?
    ');
    $stmt->execute([$locationId]);
    return $stmt->fetch() ?: null;
}

// Publier un post sur Google Business Profile
function publishPostToGoogleFromAPI(array $post, array $locationInfo): array {
    $token = getValidGoogleToken($locationInfo['account_id']);
    if (!$token) {
        return ['success' => false, 'error' => 'Token Google expire ou invalide. Reconnectez votre compte Google.'];
    }
    return publishPostToGoogle($post, $locationInfo['google_location_id'], $token, $locationInfo['google_account_name'] ?? '', (int)($locationInfo['id'] ?? 0));
}

switch ($action) {

    // ====== LISTER LES POSTS ======
    case 'list':
        $statusFilter = $_GET['status'] ?? 'all';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $where = 'WHERE p.location_id = ?';
        $params = [$locationId];

        // Par défaut, exclure les posts qui sont dans une liste (list_pending)
        // sauf si on filtre explicitement par list_id
        $where .= ' AND p.list_id IS NULL';

        if ($statusFilter !== 'all' && in_array($statusFilter, ['draft', 'scheduled', 'published', 'failed'])) {
            $where .= ' AND p.status = ?';
            $params[] = $statusFilter;
        }

        // Total
        $stmt = db()->prepare("SELECT COUNT(*) FROM google_posts p {$where}");
        $stmt->execute($params);
        $total = $stmt->fetchColumn();

        // Posts
        $stmt = db()->prepare("
            SELECT p.* FROM google_posts p
            {$where}
            ORDER BY
                CASE p.status
                    WHEN 'scheduled' THEN 1
                    WHEN 'draft' THEN 2
                    WHEN 'failed' THEN 3
                    WHEN 'published' THEN 4
                END,
                CASE
                    WHEN p.status IN ('scheduled', 'draft', 'failed')
                    THEN COALESCE(p.scheduled_at, p.created_at)
                    ELSE NULL
                END ASC,
                CASE
                    WHEN p.status = 'published'
                    THEN COALESCE(p.published_at, p.created_at)
                    ELSE NULL
                END DESC
            LIMIT {$perPage} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $posts = $stmt->fetchAll();

        // Stats (uniquement les posts standalone, pas ceux des Auto Lists)
        $stmt = db()->prepare('
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = "draft" THEN 1 ELSE 0 END) as drafts,
                SUM(CASE WHEN status = "scheduled" THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN status = "published" THEN 1 ELSE 0 END) as published,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed
            FROM google_posts WHERE location_id = ? AND list_id IS NULL
        ');
        $stmt->execute([$locationId]);
        $stats = $stmt->fetch();

        jsonResponse([
            'posts' => $posts,
            'stats' => $stats,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => (int)$total,
                'pages' => ceil($total / $perPage),
            ]
        ]);
        break;

    // ====== LISTER TOUS LES POSTS (y compris auto-lists) — Vue d'ensemble ======
    case 'list_all':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        // Total (tous les posts)
        $stmt = db()->prepare("SELECT COUNT(*) FROM google_posts WHERE location_id = ?");
        $stmt->execute([$locationId]);
        $total = $stmt->fetchColumn();

        // Posts (pas de filtre list_id)
        $stmt = db()->prepare("
            SELECT p.* FROM google_posts p
            WHERE p.location_id = ?
            ORDER BY
                CASE p.status
                    WHEN 'scheduled' THEN 1
                    WHEN 'list_pending' THEN 2
                    WHEN 'draft' THEN 3
                    WHEN 'failed' THEN 4
                    WHEN 'published' THEN 5
                END,
                CASE
                    WHEN p.status IN ('scheduled', 'list_pending', 'draft', 'failed')
                    THEN COALESCE(p.scheduled_at, p.created_at)
                    ELSE NULL
                END ASC,
                CASE
                    WHEN p.status = 'published'
                    THEN COALESCE(p.published_at, p.created_at)
                    ELSE NULL
                END DESC
            LIMIT {$perPage} OFFSET {$offset}
        ");
        $stmt->execute([$locationId]);
        $posts = $stmt->fetchAll();

        // === Calculer next_publish_at pour les posts list_pending ===
        $listIds = array_unique(array_filter(array_column($posts, 'list_id')));
        $listsInfo = [];
        if ($listIds) {
            $ph = implode(',', array_fill(0, count($listIds), '?'));
            $stmtL = db()->prepare("SELECT id, schedule_days, schedule_times, current_index, is_active FROM post_lists WHERE id IN ({$ph})");
            $stmtL->execute(array_values($listIds));
            foreach ($stmtL->fetchAll() as $li) {
                $listsInfo[$li['id']] = $li;
            }
        }
        $now = new DateTime('now', new DateTimeZone('Europe/Paris'));
        foreach ($posts as &$p) {
            if ($p['list_id'] && $p['status'] === 'list_pending' && isset($listsInfo[$p['list_id']])) {
                $li = $listsInfo[$p['list_id']];
                $slotsAway = (int)$p['list_order'] - (int)$li['current_index'];
                if ($slotsAway < 0) { $p['next_publish_at'] = null; continue; }
                $days = array_map('intval', array_filter(explode(',', $li['schedule_days'])));
                $times = array_filter(array_map('trim', explode(',', $li['schedule_times'])));
                sort($days); sort($times);
                if (empty($days) || empty($times)) { $p['next_publish_at'] = null; continue; }
                $slotsPerDay = count($times);
                // Combien de créneaux restent aujourd'hui ?
                $todayDow = (int)$now->format('N');
                $nowTime = $now->format('H:i');
                $remainToday = 0;
                if (in_array($todayDow, $days)) {
                    foreach ($times as $t) { if ($t > $nowTime) $remainToday++; }
                }
                // Parcourir les jours pour trouver la date
                $remaining = $slotsAway;
                if ($remainToday > 0 && $remaining < $remainToday) {
                    $timeIdx = count($times) - $remainToday + $remaining;
                    $p['next_publish_at'] = $now->format('Y-m-d') . ' ' . $times[$timeIdx] . ':00';
                } else {
                    $remaining -= $remainToday;
                    $dt = clone $now;
                    $found = false;
                    for ($d = 1; $d <= 365; $d++) {
                        $dt->modify('+1 day');
                        $dow = (int)$dt->format('N');
                        if (in_array($dow, $days)) {
                            if ($remaining < $slotsPerDay) {
                                $p['next_publish_at'] = $dt->format('Y-m-d') . ' ' . $times[$remaining] . ':00';
                                $found = true;
                                break;
                            }
                            $remaining -= $slotsPerDay;
                        }
                    }
                    if (!$found) $p['next_publish_at'] = null;
                }
            }
        }
        unset($p);

        // Stats enrichies par type ET par status
        $stmt = db()->prepare('
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = "draft" THEN 1 ELSE 0 END) as drafts,
                SUM(CASE WHEN status IN ("scheduled", "list_pending") THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN status = "published" THEN 1 ELSE 0 END) as published,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN post_type = "EVENT" THEN 1 ELSE 0 END) as type_event,
                SUM(CASE WHEN post_type = "OFFER" THEN 1 ELSE 0 END) as type_offer,
                SUM(CASE WHEN generation_category = "faq_ai" THEN 1 ELSE 0 END) as type_faq,
                SUM(CASE WHEN generation_category = "articles" THEN 1 ELSE 0 END) as type_article_ai,
                SUM(CASE WHEN generation_category = "mix" THEN 1 ELSE 0 END) as type_mix,
                SUM(CASE WHEN list_id IS NOT NULL THEN 1 ELSE 0 END) as type_autolist
            FROM google_posts WHERE location_id = ?
        ');
        $stmt->execute([$locationId]);
        $stats = $stmt->fetch();
        // Article = AI articles + posts manuels (sans categorie, sans list_id, pas EVENT/OFFER)
        $stats['type_article'] = (int)$stats['type_article_ai'] + max(0,
            (int)$stats['total']
            - (int)$stats['type_event'] - (int)$stats['type_offer']
            - (int)$stats['type_faq'] - (int)$stats['type_article_ai']
            - (int)$stats['type_mix'] - (int)$stats['type_autolist']
        );

        jsonResponse([
            'posts' => $posts,
            'stats' => $stats,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => (int)$total,
                'pages' => ceil($total / $perPage),
            ]
        ]);
        break;

    // ====== DÉTAIL D'UN POST ======
    case 'get':
        $postId = $_GET['post_id'] ?? null;
        if (!$postId) {
            jsonResponse(['error' => 'post_id requis'], 400);
        }

        $stmt = db()->prepare('SELECT * FROM google_posts WHERE id = ? AND location_id = ?');
        $stmt->execute([$postId, $locationId]);
        $post = $stmt->fetch();

        if (!$post) {
            jsonResponse(['error' => 'Post non trouvé'], 404);
        }

        jsonResponse(['success' => true, 'post' => $post]);
        break;

    // ====== SAUVEGARDER UN BROUILLON ======
    case 'save':
        $postId = $_POST['post_id'] ?? null;
        $postType = $_POST['post_type'] ?? 'STANDARD';
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $imageUrl = trim($_POST['image_url'] ?? '');
        $ctaType = $_POST['cta_type'] ?? null;
        $ctaUrl = trim($_POST['cta_url'] ?? '');
        $eventTitle = trim($_POST['event_title'] ?? '');
        $eventStart = $_POST['event_start'] ?? null;
        $eventEnd = $_POST['event_end'] ?? null;
        $offerCoupon = trim($_POST['offer_coupon_code'] ?? '');
        $offerTerms = trim($_POST['offer_terms'] ?? '');
        $status = $_POST['status'] ?? 'draft';
        $scheduledAt = $_POST['scheduled_at'] ?? null;

        if (!$content) {
            jsonResponse(['error' => 'Le contenu du post est requis'], 400);
        }

        if (mb_strlen($content) > 1500) {
            jsonResponse(['error' => 'Le contenu ne doit pas dépasser 1500 caractères'], 400);
        }

        if (!in_array($postType, ['STANDARD', 'EVENT', 'OFFER'])) {
            $postType = 'STANDARD';
        }

        if (!in_array($status, ['draft', 'scheduled'])) {
            $status = 'draft';
        }

        try {
            if ($postId) {
                // Modifier un post existant (seulement si draft ou scheduled)
                $stmt = db()->prepare('SELECT status FROM google_posts WHERE id = ? AND location_id = ?');
                $stmt->execute([$postId, $locationId]);
                $existing = $stmt->fetch();

                if (!$existing || !in_array($existing['status'], ['draft', 'scheduled'])) {
                    jsonResponse(['error' => 'Impossible de modifier un post publié ou échoué'], 400);
                }

                $stmt = db()->prepare('
                    UPDATE google_posts SET
                        post_type = ?, title = ?, content = ?, image_url = ?,
                        call_to_action_type = ?, call_to_action_url = ?,
                        event_title = ?, event_start = ?, event_end = ?,
                        offer_coupon_code = ?, offer_terms = ?,
                        status = ?, scheduled_at = ?,
                        updated_at = NOW()
                    WHERE id = ? AND location_id = ?
                ');
                $stmt->execute([
                    $postType, $title ?: null, $content, $imageUrl ?: null,
                    $ctaType, $ctaUrl ?: null,
                    $eventTitle ?: null, $eventStart, $eventEnd,
                    $offerCoupon ?: null, $offerTerms ?: null,
                    $status, $scheduledAt,
                    $postId, $locationId
                ]);

                jsonResponse(['success' => true, 'id' => $postId, 'action' => 'updated']);
            } else {
                // Créer un nouveau post
                $stmt = db()->prepare('
                    INSERT INTO google_posts
                        (location_id, post_type, title, content, image_url,
                         call_to_action_type, call_to_action_url,
                         event_title, event_start, event_end,
                         offer_coupon_code, offer_terms,
                         status, scheduled_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $locationId, $postType, $title ?: null, $content, $imageUrl ?: null,
                    $ctaType, $ctaUrl ?: null,
                    $eventTitle ?: null, $eventStart, $eventEnd,
                    $offerCoupon ?: null, $offerTerms ?: null,
                    $status, $scheduledAt
                ]);

                jsonResponse(['success' => true, 'id' => db()->lastInsertId(), 'action' => 'created']);
            }
        } catch (Exception $e) {
            jsonResponse(['error' => 'Erreur: ' . $e->getMessage()], 500);
        }
        break;

    // ====== PUBLIER UN POST SUR GOOGLE ======
    case 'publish':
        $postId = $_POST['post_id'] ?? null;
        if (!$postId) {
            jsonResponse(['error' => 'post_id requis'], 400);
        }

        $stmt = db()->prepare('SELECT * FROM google_posts WHERE id = ? AND location_id = ?');
        $stmt->execute([$postId, $locationId]);
        $post = $stmt->fetch();

        if (!$post) {
            jsonResponse(['error' => 'Post non trouvé'], 404);
        }

        if ($post['status'] === 'published') {
            jsonResponse(['error' => 'Ce post est déjà publié'], 400);
        }

        $locationInfo = getLocationInfo($locationId);
        if (!$locationInfo) {
            jsonResponse(['error' => 'Fiche GBP non trouvée'], 404);
        }

        $result = publishPostToGoogleFromAPI($post, $locationInfo);

        if ($result['success']) {
            $stmt = db()->prepare('
                UPDATE google_posts SET
                    status = "published",
                    published_at = NOW(),
                    google_post_id = ?,
                    error_message = NULL,
                    updated_at = NOW()
                WHERE id = ?
            ');
            $stmt->execute([$result['google_post_id'], $postId]);
            jsonResponse(['success' => true, 'message' => 'Post publié avec succès !']);
        } else {
            $stmt = db()->prepare('
                UPDATE google_posts SET
                    status = "failed",
                    error_message = ?,
                    updated_at = NOW()
                WHERE id = ?
            ');
            $stmt->execute([$result['error'], $postId]);
            jsonResponse(['error' => 'Publication échouée: ' . $result['error']], 500);
        }
        break;

    // ====== PUBLIER TOUS LES BROUILLONS ======
    case 'publish_all':
        $stmt = db()->prepare('SELECT * FROM google_posts WHERE location_id = ? AND status IN ("draft", "failed") AND list_id IS NULL');
        $stmt->execute([$locationId]);
        $posts = $stmt->fetchAll();

        if (empty($posts)) {
            jsonResponse(['error' => 'Aucun brouillon à publier'], 400);
        }

        $locationInfo = getLocationInfo($locationId);
        if (!$locationInfo) {
            jsonResponse(['error' => 'Fiche GBP non trouvée'], 404);
        }

        $published = 0;
        $failed = 0;
        $errors = [];

        foreach ($posts as $post) {
            $result = publishPostToGoogleFromAPI($post, $locationInfo);

            if ($result['success']) {
                $stmt = db()->prepare('
                    UPDATE google_posts SET
                        status = "published",
                        published_at = NOW(),
                        google_post_id = ?,
                        error_message = NULL,
                        updated_at = NOW()
                    WHERE id = ?
                ');
                $stmt->execute([$result['google_post_id'], $post['id']]);
                $published++;
            } else {
                $stmt = db()->prepare('
                    UPDATE google_posts SET
                        status = "failed",
                        error_message = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ');
                $stmt->execute([$result['error'], $post['id']]);
                $failed++;
                $errors[] = "Post #{$post['id']}: {$result['error']}";
            }

            // Pause entre chaque publication pour éviter le rate limiting
            usleep(500000); // 0.5 seconde
        }

        jsonResponse([
            'success' => true,
            'published' => $published,
            'failed' => $failed,
            'errors' => $errors,
            'message' => "{$published} post(s) publié(s)" . ($failed > 0 ? ", {$failed} échec(s)" : ""),
        ]);
        break;

    // ====== SUPPRIMER UN POST ======
    case 'delete':
        $postId = $_POST['post_id'] ?? null;
        if (!$postId) {
            jsonResponse(['error' => 'post_id requis'], 400);
        }

        $stmt = db()->prepare('SELECT * FROM google_posts WHERE id = ? AND location_id = ?');
        $stmt->execute([$postId, $locationId]);
        $post = $stmt->fetch();

        if (!$post) {
            jsonResponse(['error' => 'Post non trouvé'], 404);
        }

        // Si publié sur Google, supprimer aussi via l'API
        if ($post['status'] === 'published' && !empty($post['google_post_id'])) {
            $locationInfo = getLocationInfo($locationId);
            if ($locationInfo) {
                $token = getValidGoogleToken($locationInfo['account_id']);
                if ($token) {
                    $url = "https://mybusiness.googleapis.com/v4/{$post['google_post_id']}";
                    httpDeleteWithHeaders($url, ['Authorization: Bearer ' . $token]);
                }
            }
        }

        // Supprimer de la DB
        $stmt = db()->prepare('DELETE FROM google_posts WHERE id = ? AND location_id = ?');
        $stmt->execute([$postId, $locationId]);

        jsonResponse(['success' => true]);
        break;

    // ====== SUPPRESSION EN MASSE ======
    case 'bulk_delete':
        $postIds = $_POST['post_ids'] ?? [];
        if (empty($postIds) || !is_array($postIds)) {
            jsonResponse(['error' => 'post_ids[] requis'], 400);
        }

        $deleted = 0;
        foreach ($postIds as $pid) {
            $pid = (int)$pid;
            if (!$pid) continue;

            // Charger le post pour vérifier propriété + status Google
            $stmt = db()->prepare('SELECT * FROM google_posts WHERE id = ? AND location_id = ?');
            $stmt->execute([$pid, $locationId]);
            $post = $stmt->fetch();
            if (!$post) continue;

            // Si publié sur Google, supprimer via l'API
            if ($post['status'] === 'published' && !empty($post['google_post_id'])) {
                $locationInfo = getLocationInfo($locationId);
                if ($locationInfo) {
                    $token = getValidGoogleToken($locationInfo['account_id']);
                    if ($token) {
                        $url = "https://mybusiness.googleapis.com/v4/{$post['google_post_id']}";
                        httpDeleteWithHeaders($url, ['Authorization: Bearer ' . $token]);
                    }
                }
            }

            // Supprimer de la DB
            $stmt = db()->prepare('DELETE FROM google_posts WHERE id = ? AND location_id = ?');
            $stmt->execute([$pid, $locationId]);
            $deleted++;
        }

        jsonResponse(['success' => true, 'deleted' => $deleted]);
        break;

    // ====== GÉNÉRER CONTENU AVEC IA ======
    case 'generate_content':
        try {
        $subject = trim($_POST['subject'] ?? '');
        $postType = $_POST['post_type'] ?? 'STANDARD';

        if (!$subject) {
            jsonResponse(['error' => 'Le sujet est requis pour la génération'], 400);
        }

        // Utiliser la fonction partagee buildPostPrompt() (functions.php)
        $prompt = buildPostPrompt($locationId, $subject, $postType);
            $reply = callClaude($prompt, 600);
            if (!$reply) {
                jsonResponse(['error' => 'La génération IA n\'a retourné aucune réponse.'], 500);
            }
            jsonResponse([
                'success' => true,
                'content' => stripMarkdown(trim($reply)),
            ]);
        } catch (\Throwable $e) {
            jsonResponse(['error' => 'Erreur generation: ' . $e->getMessage()], 500);
        }
        break;

    // ====== PLANIFIER UN POST ======
    case 'schedule_post':
        $postId = $_POST['post_id'] ?? null;
        $scheduledAt = trim($_POST['scheduled_at'] ?? '');

        if (!$postId || !$scheduledAt) {
            jsonResponse(['error' => 'post_id et scheduled_at requis'], 400);
        }

        // Vérifier que le post existe et appartient à cette location
        $stmt = db()->prepare('SELECT * FROM google_posts WHERE id = ? AND location_id = ?');
        $stmt->execute([$postId, $locationId]);
        $post = $stmt->fetch();

        if (!$post) {
            jsonResponse(['error' => 'Post non trouvé'], 404);
        }

        // Seuls les posts en draft, failed ou scheduled peuvent être (re)planifiés
        if (!in_array($post['status'], ['draft', 'failed', 'scheduled'])) {
            jsonResponse(['error' => 'Seuls les posts en brouillon, échoué ou planifié peuvent être planifiés'], 400);
        }

        // Valider le format datetime
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $scheduledAt);
        if (!$dt || $dt->format('Y-m-d H:i:s') !== $scheduledAt) {
            // Essayer aussi le format sans secondes
            $dt = DateTime::createFromFormat('Y-m-d H:i', $scheduledAt);
            if (!$dt || $dt->format('Y-m-d H:i') !== $scheduledAt) {
                jsonResponse(['error' => 'Format de date invalide (attendu: YYYY-MM-DD HH:MM ou YYYY-MM-DD HH:MM:SS)'], 400);
            }
            $scheduledAt = $dt->format('Y-m-d H:i:s');
        }

        // Vérifier que la date est dans le futur
        if ($dt <= new DateTime()) {
            jsonResponse(['error' => 'La date de planification doit être dans le futur'], 400);
        }

        // Mettre à jour le post
        $stmt = db()->prepare('
            UPDATE google_posts SET
                status = "scheduled",
                scheduled_at = ?,
                error_message = NULL,
                updated_at = NOW()
            WHERE id = ? AND location_id = ?
        ');
        $stmt->execute([$scheduledAt, $postId, $locationId]);

        jsonResponse(['success' => true, 'post_id' => (int)$postId, 'scheduled_at' => $scheduledAt]);
        break;

    // ====== PLANIFIER TOUS LES BROUILLONS D'UN COUP ======
    case 'schedule_bulk':
        $days = $_POST['days'] ?? '';           // ex: "1,4" (lundi + jeudi)
        $time = trim($_POST['time'] ?? '10:00');
        $startDate = trim($_POST['start_date'] ?? '');

        if (!$days) {
            jsonResponse(['error' => 'Selectionnez au moins un jour'], 400);
        }

        // Valider les jours (1=lundi..7=dimanche)
        $dayList = array_map('intval', explode(',', $days));
        $dayList = array_filter($dayList, fn($d) => $d >= 1 && $d <= 7);
        sort($dayList);
        if (empty($dayList)) {
            jsonResponse(['error' => 'Jours invalides'], 400);
        }

        // Valider l'heure
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time = '10:00';
        }

        // Date de depart (defaut: aujourd'hui)
        if (!$startDate || !strtotime($startDate)) {
            $startDate = date('Y-m-d');
        }

        // Recuperer tous les brouillons de cette fiche, par ordre de creation
        $stmt = db()->prepare('
            SELECT id FROM google_posts
            WHERE location_id = ? AND status = "draft"
            ORDER BY created_at ASC, id ASC
        ');
        $stmt->execute([$locationId]);
        $drafts = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($drafts)) {
            jsonResponse(['error' => 'Aucun brouillon a planifier'], 400);
        }

        // Mapper les jours PHP (1=lundi..7=dimanche) vers ISO (1=Monday..7=Sunday)
        // PHP date('N') retourne 1=Monday..7=Sunday, c'est deja le bon format
        $cursor = new DateTime($startDate);
        $scheduled = [];

        // Trouver le prochain jour valide a partir de la date de depart
        $updateStmt = db()->prepare('
            UPDATE google_posts SET
                status = "scheduled",
                scheduled_at = ?,
                error_message = NULL,
                updated_at = NOW()
            WHERE id = ? AND location_id = ?
        ');

        $dayIndex = 0;
        foreach ($drafts as $postId) {
            // Trouver la prochaine date valide
            $found = false;
            for ($attempt = 0; $attempt < 365; $attempt++) {
                $currentDayOfWeek = (int)$cursor->format('N'); // 1=Mon..7=Sun
                if (in_array($currentDayOfWeek, $dayList)) {
                    $scheduledAt = $cursor->format('Y-m-d') . ' ' . $time . ':00';
                    // Verifier que c'est dans le futur
                    if (new DateTime($scheduledAt) > new DateTime()) {
                        $found = true;
                        break;
                    }
                }
                $cursor->modify('+1 day');
            }

            if (!$found) break;

            $updateStmt->execute([$scheduledAt, $postId, $locationId]);
            $scheduled[] = ['post_id' => (int)$postId, 'scheduled_at' => $scheduledAt];

            // Avancer au jour suivant
            $cursor->modify('+1 day');
        }

        jsonResponse([
            'success' => true,
            'scheduled_count' => count($scheduled),
            'total_drafts' => count($drafts),
            'schedule' => $scheduled,
        ]);
        break;

    // ====== GENERATION BATCH IA (posts brouillons) ======
    case 'batch_generate':
        set_time_limit(180);

        $count = (int)($_POST['count'] ?? 4);
        $category = $_POST['category'] ?? 'articles';
        $keywords = trim($_POST['keywords'] ?? '');
        $subjects = trim($_POST['subjects'] ?? '');

        if (!in_array($count, [4, 8, 12, 16])) {
            jsonResponse(['error' => 'Nombre de posts invalide (4, 8, 12 ou 16)'], 400);
        }
        if (!in_array($category, ['faq_ai', 'articles', 'mix'])) {
            jsonResponse(['error' => 'Categorie invalide'], 400);
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
            'faq_ai' => "des questions FAQ optimisees pour apparaitre dans les resultats de recherche IA (Google AI Overview, ChatGPT, Perplexity). Chaque sujet doit etre formule comme une question naturelle que poserait un internaute.",
            'articles' => "des sujets d'articles/conseils d'expert montrant l'expertise de l'entreprise. Chaque sujet doit etre un theme concret et utile pour le client.",
            'mix' => "un mix alterne entre : (1) des questions FAQ pour la recherche IA et (2) des articles/conseils d'expert. Alterne strictement entre les deux types.",
        };

        // Construire les instructions sur les mots-cles et sujets
        $kwInstruction = '';
        if ($keywords) {
            $kwList = array_map('trim', explode(',', $keywords));
            $kwList = array_filter($kwList);
            if (!empty($kwList)) {
                $kwInstruction = "\n\nMOTS-CLES A PRIORISER dans les sujets et contenus (integre-les naturellement) :\n- " . implode("\n- ", $kwList);
            }
        }

        $subjectInstruction = '';
        $userSubjects = [];
        if ($subjects) {
            $userSubjects = array_map('trim', explode("\n", $subjects));
            $userSubjects = array_filter($userSubjects);
        }

        // Si l'utilisateur a fourni exactement le bon nombre de sujets, on les utilise directement
        if (count($userSubjects) >= $count) {
            $finalSubjects = array_slice($userSubjects, 0, $count);
        } else {
            // Sinon on demande a Claude de generer les sujets manquants
            $alreadyCount = count($userSubjects);
            $needCount = $count - $alreadyCount;

            if ($alreadyCount > 0) {
                $subjectInstruction = "\n\nL'utilisateur a deja choisi ces sujets (garde-les tels quels en debut de liste) :\n";
                foreach ($userSubjects as $i => $s) {
                    $subjectInstruction .= ($i + 1) . ". \"{$s}\"\n";
                }
                $subjectInstruction .= "\nGenere {$needCount} sujets SUPPLEMENTAIRES pour completer la liste a {$count} au total.";
            }

            $subjectsPrompt = "Tu es un expert en SEO local et content marketing.

Genere exactement " . ($alreadyCount > 0 ? $needCount : $count) . " sujets de posts Google Business Profile pour l'entreprise \"{$businessName}\"" . ($businessCategory ? " (categorie: {$businessCategory})" : "") . ($city ? " situee a {$city}" : "") . ".

TYPE DE CONTENU : {$categoryDesc}{$kwInstruction}{$subjectInstruction}

REGLES :
- Chaque sujet doit etre UNIQUE et couvrir un angle different
- Les sujets doivent etre pertinents pour le secteur \"{$businessCategory}\"" . ($city ? " et la ville de {$city}" : "") . "
- Varie les angles : saisonnier, pratique, comparatif, guide, actualite, FAQ client, conseil pro...
- Chaque sujet fait entre 5 et 20 mots
- Reponds UNIQUEMENT avec un JSON array de strings, sans explication ni markdown
- Format exact : [\"sujet 1\", \"sujet 2\", \"sujet 3\"]

Genere exactement " . ($alreadyCount > 0 ? $needCount : $count) . " sujets :";

            try {
                $subjectsRaw = callClaude($subjectsPrompt, 1000);
            } catch (\Throwable $e) {
                jsonResponse(['error' => 'Erreur generation sujets: ' . $e->getMessage()], 500);
            }

            if (!$subjectsRaw) {
                jsonResponse(['error' => 'L\'IA n\'a retourne aucune reponse pour les sujets.'], 500);
            }

            // Parser le JSON
            $subjectsRaw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($subjectsRaw));
            $generatedSubjects = json_decode($subjectsRaw, true);

            if (!is_array($generatedSubjects) || count($generatedSubjects) < 1) {
                jsonResponse(['error' => 'Erreur parsing sujets IA. Reponse: ' . substr($subjectsRaw, 0, 500)], 500);
            }

            // Fusionner : sujets utilisateur en premier, puis sujets IA
            $finalSubjects = array_merge($userSubjects, array_slice($generatedSubjects, 0, $needCount));
            $finalSubjects = array_slice($finalSubjects, 0, $count);
        }

        // ETAPE 2 : Generer le contenu de chaque post
        $generatedPosts = [];
        $errors = [];

        foreach ($finalSubjects as $i => $subject) {
            if (!is_string($subject)) continue;
            $subject = trim($subject);
            if (empty($subject)) continue;

            // Enrichir le sujet avec les mots-cles pour le prompt
            $enrichedSubject = $subject;
            if ($kwInstruction) {
                $enrichedSubject .= "\n\n(Integre naturellement ces mots-cles dans le contenu : " . implode(', ', $kwList) . ")";
            }

            // Determiner la categorie effective pour le prompt (FAQ vs Articles)
            $promptCategory = $category;
            if ($category === 'mix') {
                $promptCategory = ($i % 2 === 0) ? 'faq_ai' : 'articles';
            }

            try {
                $prompt = buildPostPrompt($locationId, $enrichedSubject, 'STANDARD', $promptCategory);
                $maxTokens = ($promptCategory === 'faq_ai') ? 900 : 600;
                $content = callClaude($prompt, $maxTokens);
                if ($content) {
                    // Stocker la categorie d'origine (mix reste mix, pas alternance)
                    $generatedPosts[] = ['subject' => $subject, 'content' => stripMarkdown(trim($content)), 'category' => $category];
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

        // ETAPE 3 : Creer les posts comme brouillons individuels
        $insertStmt = db()->prepare('
            INSERT INTO google_posts
                (location_id, post_type, title, content, status, generation_category, created_at)
            VALUES (?, "STANDARD", ?, ?, "draft", ?, NOW())
        ');

        foreach ($generatedPosts as $post) {
            $insertStmt->execute([
                $locationId,
                $post['subject'],
                $post['content'],
                $post['category'] ?? null,
            ]);
        }

        jsonResponse([
            'success' => true,
            'generated' => count($generatedPosts),
            'requested' => $count,
            'subjects' => array_column($generatedPosts, 'subject'),
            'errors' => $errors,
        ]);
        break;

    default:
        jsonResponse(['error' => 'Action non reconnue'], 400);
}
