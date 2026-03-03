<?php
/**
 * BOUS'TACOM — API Places Data (location-aware)
 *
 * Actions :
 *   GET  ?action=get_cached&location_id=X        — Données Places en cache pour ce client
 *   POST ?action=refresh&location_id=X            — Forcer rafraîchissement depuis Google
 *   GET  ?action=stats_history&location_id=X      — Historique stats pour courbes
 *   GET  ?action=reviews&location_id=X            — 5 derniers avis en cache
 *   POST ?action=link_place&location_id=X         — Associer un place_id au client
 *   POST ?action=link_from_url&location_id=X      — Extraire place_id depuis URL Google Maps
 *   GET  ?action=completeness&location_id=X       — Score de complétude détaillé
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$locationId = intval($_GET['location_id'] ?? $_POST['location_id'] ?? 0);

if (!$locationId) {
    echo json_encode(['error' => 'location_id requis']);
    exit;
}

// Vérifier que la location appartient à l'utilisateur
$user = currentUser();
$stmt = db()->prepare('
    SELECT l.* FROM gbp_locations l
    JOIN gbp_accounts a ON l.gbp_account_id = a.id
    WHERE l.id = ? AND a.user_id = ? AND l.is_active = 1
');
$stmt->execute([$locationId, $user['id']]);
$location = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$location) {
    echo json_encode(['error' => 'Location non trouvée']);
    exit;
}

$placeId = $location['place_id'] ?? '';

switch ($action) {

    // ============================================
    // GET CACHED — Données Places en cache
    // ============================================
    case 'get_cached':
        if (!$placeId) {
            echo json_encode(['success' => true, 'linked' => false, 'place' => null]);
            exit;
        }

        // Chercher le cache extended (même périmé, on retourne ce qu'on a)
        $stmt = db()->prepare('SELECT raw_data, updated_at, is_sab FROM google_places_cache WHERE place_id = ? AND mode = ? ORDER BY updated_at DESC LIMIT 1');
        $stmt->execute([$placeId, 'extended']);
        $cache = $stmt->fetch(PDO::FETCH_ASSOC);

        $placeData = null;
        $isStale = true;
        $lastRefresh = null;

        if ($cache) {
            $placeData = json_decode($cache['raw_data'], true);
            $lastRefresh = $cache['updated_at'];
            $isStale = (strtotime($cache['updated_at']) < time() - 21600); // > 6h
        }

        // Si pas de cache du tout, tenter un fetch basique rapide
        if (!$placeData) {
            $placeData = googlePlacesDetailsFetch($placeId, 'extended', false);
            $isStale = false;
            $lastRefresh = date('Y-m-d H:i:s');
        }

        // Récupérer les derniers avis en cache
        $stmtR = db()->prepare('
            SELECT author_name, rating, text_content, review_time, has_owner_reply, language_code
            FROM google_places_reviews WHERE place_id = ? ORDER BY review_time DESC LIMIT 5
        ');
        $stmtR->execute([$placeId]);
        $reviews = $stmtR->fetchAll(PDO::FETCH_ASSOC);

        // Score de complétude
        $completeness = $placeData ? calculatePlacesCompletenessScore($placeData) : ['score' => 0, 'checks' => []];

        echo json_encode([
            'success'       => true,
            'linked'        => (bool)$location['places_api_linked'],
            'place'         => $placeData,
            'reviews'       => $reviews,
            'completeness'  => $completeness,
            'is_sab'        => $cache ? (int)$cache['is_sab'] : 0,
            'last_refresh'  => $lastRefresh,
            'is_stale'      => $isStale,
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ============================================
    // REFRESH — Forcer le rafraîchissement
    // ============================================
    case 'refresh':
        if (!$placeId) {
            echo json_encode(['error' => 'Aucun place_id associé à cette fiche']);
            exit;
        }

        // Fetch extended avec force refresh
        $data = googlePlacesDetailsFetch($placeId, 'extended', true);
        if (!$data) {
            echo json_encode(['error' => 'Erreur lors de la récupération des données Google']);
            exit;
        }

        // Aussi rafraîchir les reviews
        $reviewsData = googlePlacesDetailsFetch($placeId, 'reviews', true);
        if ($reviewsData && !empty($reviewsData['reviews'])) {
            syncPlacesReviews($placeId, $reviewsData['reviews']);
        }

        $completeness = calculatePlacesCompletenessScore($data);

        // Récupérer les avis mis à jour
        $stmtR = db()->prepare('
            SELECT author_name, rating, text_content, review_time, has_owner_reply, language_code
            FROM google_places_reviews WHERE place_id = ? ORDER BY review_time DESC LIMIT 5
        ');
        $stmtR->execute([$placeId]);
        $reviews = $stmtR->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'      => true,
            'place'        => $data,
            'reviews'      => $reviews,
            'completeness' => $completeness,
            'is_sab'       => $data['_is_sab'] ?? 0,
            'last_refresh' => date('Y-m-d H:i:s'),
            'is_stale'     => false,
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ============================================
    // STATS HISTORY — Historique pour courbes
    // ============================================
    case 'stats_history':
        if (!$placeId) {
            echo json_encode(['success' => true, 'history' => []]);
            exit;
        }
        $days = max(7, min(365, intval($_GET['days'] ?? 90)));
        $stmt = db()->prepare('
            SELECT stat_date, rating, total_reviews, total_photos, completeness_score
            FROM google_places_stats_history
            WHERE place_id = ? AND stat_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            ORDER BY stat_date ASC
        ');
        $stmt->execute([$placeId, $days]);
        echo json_encode(['success' => true, 'history' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        break;

    // ============================================
    // REVIEWS — 5 derniers avis en cache
    // ============================================
    case 'reviews':
        if (!$placeId) {
            echo json_encode(['success' => true, 'reviews' => []]);
            exit;
        }
        $stmtR = db()->prepare('
            SELECT author_name, author_photo_url, rating, text_content, review_time,
                   has_owner_reply, owner_reply_text, owner_reply_time, language_code
            FROM google_places_reviews WHERE place_id = ? ORDER BY review_time DESC LIMIT 5
        ');
        $stmtR->execute([$placeId]);
        echo json_encode(['success' => true, 'reviews' => $stmtR->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        break;

    // ============================================
    // LINK PLACE — Associer un place_id
    // ============================================
    case 'link_place':
        $newPlaceId = trim($_POST['place_id'] ?? '');
        if (!$newPlaceId) {
            echo json_encode(['error' => 'place_id requis']);
            exit;
        }

        // Vérifier que le place_id est valide en appelant l'API
        $placeData = googlePlacesDetailsFetch($newPlaceId, 'basic', true);
        if (!$placeData) {
            echo json_encode(['error' => 'Place ID invalide ou non trouvé sur Google']);
            exit;
        }

        // Mettre à jour la location
        $stmtUp = db()->prepare('
            UPDATE gbp_locations SET place_id = ?, places_api_linked = 1, places_api_linked_at = NOW()
            WHERE id = ?
        ');
        $stmtUp->execute([$newPlaceId, $locationId]);

        // Lancer un fetch extended en background pour peupler le cache
        $extData = googlePlacesDetailsFetch($newPlaceId, 'extended', true);

        // Aussi fetcher les reviews
        $reviewsData = googlePlacesDetailsFetch($newPlaceId, 'reviews', true);
        if ($reviewsData && !empty($reviewsData['reviews'])) {
            syncPlacesReviews($newPlaceId, $reviewsData['reviews']);
        }

        $name = $placeData['displayName']['text'] ?? '';
        echo json_encode([
            'success' => true,
            'message' => "Fiche \"{$name}\" associée avec succès",
            'place'   => $placeData,
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ============================================
    // LINK FROM URL — Extraire place_id depuis URL Google Maps
    // ============================================
    case 'link_from_url':
        $url = trim($_POST['url'] ?? '');
        if (!$url) {
            echo json_encode(['error' => 'URL requise']);
            exit;
        }

        $extractedPlaceId = extractPlaceIdFromGoogleUrl($url);
        if (!$extractedPlaceId) {
            echo json_encode(['error' => 'Impossible d\'extraire le Place ID depuis cette URL. Essayez de copier le Place ID manuellement.']);
            exit;
        }

        // Vérifier et associer
        $placeData = googlePlacesDetailsFetch($extractedPlaceId, 'basic', true);
        if (!$placeData) {
            echo json_encode(['error' => 'Place ID extrait mais non valide : ' . $extractedPlaceId]);
            exit;
        }

        $stmtUp = db()->prepare('
            UPDATE gbp_locations SET place_id = ?, places_api_linked = 1, places_api_linked_at = NOW()
            WHERE id = ?
        ');
        $stmtUp->execute([$extractedPlaceId, $locationId]);

        // Cache extended
        googlePlacesDetailsFetch($extractedPlaceId, 'extended', true);

        $name = $placeData['displayName']['text'] ?? '';
        echo json_encode([
            'success'  => true,
            'message'  => "Place ID extrait ({$extractedPlaceId}) — fiche \"{$name}\" associée",
            'place_id' => $extractedPlaceId,
            'place'    => $placeData,
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ============================================
    // COMPLETENESS — Score détaillé
    // ============================================
    case 'completeness':
        if (!$placeId) {
            echo json_encode(['success' => true, 'score' => 0, 'checks' => []]);
            exit;
        }

        // Utiliser le cache extended s'il existe
        $stmt = db()->prepare('SELECT raw_data FROM google_places_cache WHERE place_id = ? AND mode = ? LIMIT 1');
        $stmt->execute([$placeId, 'extended']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $data = json_decode($row['raw_data'], true);
        } else {
            $data = googlePlacesDetailsFetch($placeId, 'extended', false);
        }

        $completeness = $data ? calculatePlacesCompletenessScore($data) : ['score' => 0, 'checks' => []];
        echo json_encode(['success' => true] + $completeness, JSON_UNESCAPED_UNICODE);
        break;

    default:
        echo json_encode(['error' => 'Action inconnue : ' . $action]);
        break;
}

// syncPlacesReviews() est maintenant dans functions.php (centralisé)
