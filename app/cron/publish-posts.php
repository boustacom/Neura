<?php
/**
 * BOUS'TACOM — CRON : Publication automatique des Google Posts
 *
 * Appelé par le planificateur de tâches InfoManiak via URL :
 * https://app.boustacom.fr/app/cron/publish-posts.php?token=XXXX
 *
 * PHASE 1 : Posts standalone (scheduled_at <= NOW())
 * PHASE 2 : Auto Lists (planning jours/heures)
 */

require_once __DIR__ . '/../config.php';

// Sécurité : vérifier le token secret
$expectedToken = hash('sha256', APP_SECRET . '_cron_posts');
$providedToken = $_GET['token'] ?? '';

if ($providedToken !== $expectedToken) {
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

$published = 0;
$failed = 0;
$log = [];

// ============================================
// PHASE 1 : Posts standalone (scheduled)
// ============================================

$stmt = db()->prepare('
    SELECT p.*, l.google_location_id, l.gbp_account_id, a.id as account_id, a.google_account_name
    FROM google_posts p
    JOIN gbp_locations l ON p.location_id = l.id
    JOIN gbp_accounts a ON l.gbp_account_id = a.id
    WHERE p.status = "scheduled"
    AND p.scheduled_at <= NOW()
    AND p.list_id IS NULL
    ORDER BY p.scheduled_at ASC
');
$stmt->execute();
$standalonePosts = $stmt->fetchAll();

foreach ($standalonePosts as $post) {
    $token = getValidGoogleToken($post['account_id']);
    if (!$token) {
        $error = 'Token Google expiré ou invalide. Reconnectez votre compte Google.';
        $upd = db()->prepare('UPDATE google_posts SET status = "failed", error_message = ?, updated_at = NOW() WHERE id = ?');
        $upd->execute([$error, $post['id']]);
        $failed++;
        $log[] = "[Standalone] Post #{$post['id']}: ERREUR — {$error}";
        continue;
    }

    $result = publishPostToGoogle($post, $post['google_location_id'], $token, $post['google_account_name'] ?? '');

    if ($result['success']) {
        $upd = db()->prepare('
            UPDATE google_posts SET
                status = "published", published_at = NOW(),
                google_post_id = ?, error_message = NULL, updated_at = NOW()
            WHERE id = ?
        ');
        $upd->execute([$result['google_post_id'], $post['id']]);
        $published++;
        $log[] = "[Standalone] Post #{$post['id']}: OK — {$result['google_post_id']}";
    } else {
        $upd = db()->prepare('
            UPDATE google_posts SET
                status = "failed", error_message = ?, updated_at = NOW()
            WHERE id = ?
        ');
        $upd->execute([$result['error'], $post['id']]);
        $failed++;
        $log[] = "[Standalone] Post #{$post['id']}: ECHEC — {$result['error']}";
    }

    usleep(500000); // 0.5s rate limiting
}

// ============================================
// PHASE 2 : Auto Lists
// ============================================
// Logique robuste qui respecte le planning configuré :
// - Vérifie si aujourd'hui est un jour de publication prévu
// - Compte combien de créneaux horaires sont DÉJÀ PASSÉS aujourd'hui
// - Compare avec le nombre de posts déjà publiés aujourd'hui
// - Si des créneaux passés n'ont pas été traités → publie le prochain post
// Fonctionne quelle que soit la fréquence du cron (toutes les heures, 5 min, etc.)

$now = new DateTime('now', new DateTimeZone('Europe/Paris'));
$currentDay = (int)$now->format('N'); // 1=Lundi, 7=Dimanche
$currentTime = $now->format('H:i');
$todayStr = $now->format('Y-m-d');

// Récupérer toutes les listes actives
$stmt = db()->prepare('
    SELECT pl.*, l.google_location_id, l.gbp_account_id, a.id as account_id, a.google_account_name
    FROM post_lists pl
    JOIN gbp_locations l ON pl.location_id = l.id
    JOIN gbp_accounts a ON l.gbp_account_id = a.id
    WHERE pl.is_active = 1
');
$stmt->execute();
$activeLists = $stmt->fetchAll();

foreach ($activeLists as $list) {
    $listLabel = "[Liste #{$list['id']} \"{$list['name']}\"]";

    // 1. Vérifier si aujourd'hui est un jour de publication
    $scheduleDays = array_map('intval', explode(',', $list['schedule_days']));
    if (!in_array($currentDay, $scheduleDays)) {
        continue;
    }

    // 2. Compter combien de créneaux horaires sont DÉJÀ PASSÉS maintenant
    $scheduleTimes = array_filter(array_map('trim', explode(',', $list['schedule_times'])));
    sort($scheduleTimes);
    $slotsPassed = 0;
    foreach ($scheduleTimes as $slot) {
        if ($currentTime >= $slot) {
            $slotsPassed++;
        }
    }

    if ($slotsPassed === 0) {
        // Aucun créneau n'est encore passé aujourd'hui, on attend
        continue;
    }

    // 3. Compter combien de posts ont déjà été publiés/traités aujourd'hui pour cette liste
    $stmt = db()->prepare('
        SELECT COUNT(*) FROM google_posts
        WHERE list_id = ?
        AND DATE(published_at) = ?
        AND status IN ("published", "failed")
    ');
    $stmt->execute([$list['id'], $todayStr]);
    $publishedToday = (int)$stmt->fetchColumn();

    if ($publishedToday >= $slotsPassed) {
        // Tous les créneaux passés ont déjà été traités
        continue;
    }

    // 4. Compter le nombre total de posts dans la liste
    $stmt = db()->prepare('SELECT COUNT(*) FROM google_posts WHERE list_id = ?');
    $stmt->execute([$list['id']]);
    $totalPosts = (int)$stmt->fetchColumn();

    if ($totalPosts === 0) {
        $log[] = "{$listLabel} Aucun post dans la liste";
        continue;
    }

    $currentIndex = (int)$list['current_index'];

    // Vérifier si la liste est terminée
    if ($currentIndex >= $totalPosts) {
        if ($list['is_repeat']) {
            // Mode boucle : reset au début
            $currentIndex = 0;
            $upd = db()->prepare('UPDATE post_lists SET current_index = 0, updated_at = NOW() WHERE id = ?');
            $upd->execute([$list['id']]);

            // Remettre tous les posts en list_pending
            $upd = db()->prepare('UPDATE google_posts SET status = "list_pending" WHERE list_id = ? AND status = "published"');
            $upd->execute([$list['id']]);

            $log[] = "{$listLabel} Boucle : reset au début";
        } else {
            // Terminée : désactiver la liste
            $upd = db()->prepare('UPDATE post_lists SET is_active = 0, updated_at = NOW() WHERE id = ?');
            $upd->execute([$list['id']]);
            $log[] = "{$listLabel} Tous les posts publiés, liste désactivée";
            continue;
        }
    }

    // Récupérer le post au current_index
    $stmt = db()->prepare('
        SELECT * FROM google_posts
        WHERE list_id = ? AND list_order = ?
    ');
    $stmt->execute([$list['id'], $currentIndex]);
    $post = $stmt->fetch();

    if (!$post) {
        $log[] = "{$listLabel} Post non trouvé à l'index {$currentIndex}, skip";
        $upd = db()->prepare('UPDATE post_lists SET current_index = current_index + 1, updated_at = NOW() WHERE id = ?');
        $upd->execute([$list['id']]);
        continue;
    }

    // Publier le post via l'API Google
    $token = getValidGoogleToken($list['account_id']);
    if (!$token) {
        $error = 'Token Google expiré ou invalide. Reconnectez votre compte Google.';
        $upd = db()->prepare('UPDATE google_posts SET status = "failed", error_message = ?, published_at = NOW(), updated_at = NOW() WHERE id = ?');
        $upd->execute([$error, $post['id']]);
        $failed++;
        $log[] = "{$listLabel} Post #{$post['id']}: ERREUR — {$error}";

        $upd = db()->prepare('UPDATE post_lists SET current_index = current_index + 1, last_published_at = NOW(), updated_at = NOW() WHERE id = ?');
        $upd->execute([$list['id']]);
        continue;
    }

    $result = publishPostToGoogle($post, $list['google_location_id'], $token, $list['google_account_name'] ?? '');

    if ($result['success']) {
        $upd = db()->prepare('
            UPDATE google_posts SET
                status = "published", published_at = NOW(),
                google_post_id = ?, error_message = NULL,
                list_published_count = list_published_count + 1,
                updated_at = NOW()
            WHERE id = ?
        ');
        $upd->execute([$result['google_post_id'], $post['id']]);
        $published++;
        $log[] = "{$listLabel} Post #{$post['id']} (index {$currentIndex}): OK — {$result['google_post_id']}";
    } else {
        $upd = db()->prepare('
            UPDATE google_posts SET
                status = "failed", error_message = ?, published_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ');
        $upd->execute([$result['error'], $post['id']]);
        $failed++;
        $log[] = "{$listLabel} Post #{$post['id']} (index {$currentIndex}): ÉCHEC — {$result['error']}";
    }

    // Incrémenter current_index + MAJ last_published_at
    $upd = db()->prepare('
        UPDATE post_lists SET
            current_index = current_index + 1,
            last_published_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ');
    $upd->execute([$list['id']]);

    usleep(500000); // 0.5s rate limiting
}

echo json_encode([
    'success' => true,
    'published' => $published,
    'failed' => $failed,
    'log' => $log,
]);
