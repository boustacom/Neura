<?php
/**
 * BOUS'TACOM — Autocomplétion villes françaises
 * GET /api/dataforseo-locations.php?q=Male
 *
 * Utilise l'API geo.api.gouv.fr (gratuite, toutes les 36 000+ communes FR)
 * Retourne le nom de la commune + département pour un format clean "Malemort, Corrèze"
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();

header('Content-Type: application/json');

$query = trim($_GET['q'] ?? '');

if (mb_strlen($query) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

// Appeler l'API geo.api.gouv.fr
$url = 'https://geo.api.gouv.fr/communes?nom=' . urlencode($query) . '&fields=nom,departement,codesPostaux,population,centre&boost=population&limit=10';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    echo json_encode(['error' => 'Erreur API communes', 'results' => []]);
    exit;
}

$communes = json_decode($response, true);
if (!is_array($communes)) {
    echo json_encode(['results' => []]);
    exit;
}

$results = [];
foreach ($communes as $c) {
    $nom = $c['nom'] ?? '';
    $dept = $c['departement']['nom'] ?? '';
    $cp = !empty($c['codesPostaux']) ? $c['codesPostaux'][0] : '';
    $pop = $c['population'] ?? 0;

    // Format: "Malemort, Corrèze" — propre et lisible
    $fullName = $nom;
    if ($dept) $fullName .= ', ' . $dept;

    // centre = GeoJSON Point {type:"Point", coordinates:[lng, lat]}
    $centre = $c['centre'] ?? null;
    $lat = $centre ? ($centre['coordinates'][1] ?? null) : null;
    $lng = $centre ? ($centre['coordinates'][0] ?? null) : null;

    $results[] = [
        'name'       => $fullName,
        'city'       => $nom,
        'department' => $dept,
        'postal'     => $cp,
        'population' => $pop,
        'lat'        => $lat,
        'lng'        => $lng,
        'type'       => $pop > 50000 ? 'Ville' : ($pop > 10000 ? 'Commune' : 'Village'),
    ];
}

echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE);
