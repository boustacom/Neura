<?php
/**
 * BOUS'TACOM — CRON Synchronisation Google Places API (New)
 * Sécurisé par token SHA-256
 * À configurer sur Infomaniak : quotidien à 06:00
 * URL: https://app.boustacom.fr/app/cron/sync-places.php?token=XXXX
 *
 * Pour chaque location active avec places_api_linked = 1 :
 * 1. Fetch extended details via Google Places API
 * 2. Upsert cache + snapshot stats_history
 * 3. Fetch reviews → upsert google_places_reviews
 */

require_once __DIR__ . '/../config.php';

// ====== SÉCURITÉ ======
$expectedToken = hash('sha256', APP_SECRET . '_cron_sync_places');
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
$results = [];
$totalSynced = 0;
$totalErrors = 0;

echo "=== SYNC PLACES API — " . $now->format('Y-m-d H:i:s') . " ===\n\n";

// ====== Récupérer toutes les locations avec Places API liée ======
$stmt = db()->query('
    SELECT l.id, l.place_id, l.location_name,
           a.business_name AS account_name, a.user_id
    FROM gbp_locations l
    JOIN gbp_accounts a ON l.gbp_account_id = a.id
    WHERE l.is_active = 1
      AND l.places_api_linked = 1
      AND l.place_id IS NOT NULL
      AND l.place_id != ""
    ORDER BY l.id ASC
');
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = count($locations);

echo "Locations à synchroniser : {$total}\n\n";

if ($total === 0) {
    echo json_encode([
        'success' => true,
        'synced'  => 0,
        'errors'  => 0,
        'message' => 'Aucune location avec Places API liée',
    ]);
    exit;
}

foreach ($locations as $i => $loc) {
    $num = $i + 1;
    $placeId = $loc['place_id'];
    $name = $loc['location_name'] ?: $loc['account_name'];

    echo "[{$num}/{$total}] {$name} (place_id: {$placeId})\n";

    try {
        // 1. Fetch extended details (force refresh)
        $extData = googlePlacesDetailsFetch($placeId, 'extended', true);
        if (!$extData) {
            echo "  ⚠ Erreur fetch extended — skip\n";
            $totalErrors++;
            $results[] = ['location_id' => $loc['id'], 'name' => $name, 'status' => 'error', 'step' => 'extended'];
            continue;
        }

        $rating = $extData['rating'] ?? null;
        $reviewsCount = $extData['userRatingCount'] ?? 0;
        $photosCount = count($extData['photos'] ?? []);
        echo "  ✓ Extended OK — note: {$rating}, avis: {$reviewsCount}, photos: {$photosCount}\n";

        // 2. Snapshot stats_history (déjà fait par googlePlacesDetailsFetch, mais on vérifie)
        // Le wrapper appelle snapshotPlacesStats() automatiquement

        // 3. Fetch reviews (force refresh)
        $reviewsData = googlePlacesDetailsFetch($placeId, 'reviews', true);
        if ($reviewsData && !empty($reviewsData['reviews'])) {
            $reviewCount = count($reviewsData['reviews']);
            syncPlacesReviews($placeId, $reviewsData['reviews']);
            echo "  ✓ Reviews OK — {$reviewCount} avis synchronisés\n";
        } else {
            echo "  ○ Pas de reviews ou erreur fetch\n";
        }

        $totalSynced++;
        $results[] = [
            'location_id' => $loc['id'],
            'name'        => $name,
            'status'      => 'ok',
            'rating'      => $rating,
            'reviews'     => $reviewsCount,
            'photos'      => $photosCount,
        ];

    } catch (Exception $e) {
        echo "  ✗ Exception : " . $e->getMessage() . "\n";
        $totalErrors++;
        $results[] = ['location_id' => $loc['id'], 'name' => $name, 'status' => 'exception', 'error' => $e->getMessage()];
    }

    // Pause entre les locations pour ne pas surcharger l'API
    if ($i < $total - 1) {
        usleep(500000); // 500ms
    }
}

echo "\n=== RÉSUMÉ ===\n";
echo "Synchronisées : {$totalSynced}/{$total}\n";
echo "Erreurs : {$totalErrors}\n";

echo json_encode([
    'success' => true,
    'synced'  => $totalSynced,
    'errors'  => $totalErrors,
    'total'   => $total,
    'details' => $results,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);


// syncPlacesReviews() est centralisé dans functions.php (chargé via config.php)
