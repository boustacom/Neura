<?php
/**
 * BOUS'TACOM — Proxy photo Google Places (New)
 * GET /api/places-photo.php?ref=places/ChIJ.../photos/XXXX&maxw=400
 *
 * Proxy les photos Google Places pour ne pas exposer la clé API côté client.
 * Retourne l'image JPEG directement avec cache navigateur 24h.
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();

$photoRef = trim($_GET['ref'] ?? '');
$maxWidth = max(100, min(4800, intval($_GET['maxw'] ?? 400)));

if (!$photoRef) {
    header('HTTP/1.1 400 Bad Request');
    echo 'Paramètre ref requis';
    exit;
}

$apiKey = defined('GOOGLE_PLACES_API_KEY') ? GOOGLE_PLACES_API_KEY : '';
if (!$apiKey) {
    header('HTTP/1.1 500 Internal Server Error');
    echo 'Clé API non configurée';
    exit;
}

// L'URL Google Places Photo (New) : GET https://places.googleapis.com/v1/{photoRef}/media
$url = 'https://places.googleapis.com/v1/' . $photoRef . '/media?maxWidthPx=' . $maxWidth . '&key=' . $apiKey;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_HTTPHEADER     => [
        'Referer: ' . APP_URL . '/',
    ],
]);

$imageData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($httpCode !== 200 || !$imageData) {
    header('HTTP/1.1 502 Bad Gateway');
    echo 'Erreur récupération photo (HTTP ' . $httpCode . ')';
    exit;
}

// Retourner l'image avec cache navigateur
header('Content-Type: ' . ($contentType ?: 'image/jpeg'));
header('Cache-Control: public, max-age=86400'); // 24h
header('Content-Length: ' . strlen($imageData));
echo $imageData;
