<?php
/**
 * BOUS'TACOM — Autocomplete fiches Google (Google Places API New)
 * GET /api/places-autocomplete.php?q=Restaurant+Le&lat=45.77&lng=3.08
 *
 * Retourne des suggestions de fiches avec place_id, nom, adresse, types.
 * Le paramètre lat/lng (optionnel) biaise les résultats vers cette zone.
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();

header('Content-Type: application/json');

$query = trim($_GET['q'] ?? '');

if (mb_strlen($query) < 3) {
    echo json_encode(['results' => []]);
    exit;
}

$apiKey = defined('GOOGLE_PLACES_API_KEY') ? GOOGLE_PLACES_API_KEY : '';
if (!$apiKey || $apiKey === 'PLACEHOLDER_WAITING_FOR_KEY') {
    echo json_encode(['error' => 'Clé API Google Places non configurée', 'results' => []]);
    exit;
}

// Construire le body de la requête
$body = [
    'input'                => $query,
    'includedPrimaryTypes' => ['establishment'],
    'languageCode'         => 'fr',
    'regionCode'           => 'FR',
];

// Biais géographique si des coordonnées sont fournies
$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : 0;
$lng = isset($_GET['lng']) ? (float)$_GET['lng'] : 0;
if ($lat && $lng) {
    $body['locationBias'] = [
        'circle' => [
            'center' => ['latitude' => $lat, 'longitude' => $lng],
            'radius' => 50000.0, // 50km
        ],
    ];
}

$ch = curl_init('https://places.googleapis.com/v1/places:autocomplete');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($body),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-Goog-Api-Key: ' . $apiKey,
        'X-Goog-FieldMask: suggestions.placePrediction.placeId,suggestions.placePrediction.structuredFormat,suggestions.placePrediction.types,suggestions.placePrediction.text',
        'Referer: ' . APP_URL . '/',
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    echo json_encode(['error' => 'Erreur Google Places API (HTTP ' . $httpCode . ')', 'results' => []]);
    exit;
}

$data = json_decode($response, true);
if (!is_array($data)) {
    echo json_encode(['results' => []]);
    exit;
}

$results = [];
$suggestions = $data['suggestions'] ?? [];
foreach ($suggestions as $s) {
    $pred = $s['placePrediction'] ?? null;
    if (!$pred) continue;

    $structured = $pred['structuredFormat'] ?? [];
    $name    = $structured['mainText']['text'] ?? '';
    $address = $structured['secondaryText']['text'] ?? '';
    $placeId = $pred['placeId'] ?? '';
    $types   = $pred['types'] ?? [];
    $fullText = $pred['text']['text'] ?? ($name . ', ' . $address);

    if (!$placeId || !$name) continue;

    $results[] = [
        'place_id'  => $placeId,
        'name'      => $name,
        'address'   => $address,
        'full_text' => $fullText,
        'types'     => $types,
    ];
}

echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE);
