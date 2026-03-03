<?php
/**
 * BOUS'TACOM — Détails fiche Google (Google Places API New)
 * GET /api/places-details.php?place_id=ChIJ...&mode=basic|extended|reviews&refresh=1
 *
 * Modes :
 * - basic    : nom, adresse, GPS, catégorie, note, avis, site, téléphone (SKU Basic = $0)
 * - extended : + horaires, photos, attributs, statut, description (SKU Advanced)
 * - reviews  : extended + 5 derniers avis (SKU séparé)
 *
 * Cache DB : 6h (basic/extended), 24h (reviews). ?refresh=1 pour forcer.
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();

header('Content-Type: application/json');

$placeId = trim($_GET['place_id'] ?? '');
$mode = $_GET['mode'] ?? 'basic';
$forceRefresh = isset($_GET['refresh']);

if (!$placeId) {
    echo json_encode(['error' => 'place_id requis']);
    exit;
}
if (!in_array($mode, ['basic', 'extended', 'reviews'])) {
    $mode = 'basic';
}

// Utiliser le wrapper avec cache intégré
$data = googlePlacesDetailsFetch($placeId, $mode, $forceRefresh);

if (!$data) {
    echo json_encode(['error' => 'Erreur Google Places Details — vérifiez le place_id']);
    exit;
}

// Extraire le domaine du site web
$website = $data['websiteUri'] ?? '';
$domain = '';
if ($website) {
    $parsedHost = parse_url($website, PHP_URL_HOST);
    if ($parsedHost) $domain = str_replace('www.', '', strtolower($parsedHost));
}

// Extraire la ville depuis l'adresse courte ou complète
$shortAddr = $data['shortFormattedAddress'] ?? '';
$fullAddr  = $data['formattedAddress'] ?? '';
$city = '';
if ($shortAddr) {
    $parts = array_map('trim', explode(',', $shortAddr));
    if (count($parts) >= 2) {
        $city = end($parts);
        $city = preg_replace('/^\d{5}\s*/', '', $city);
    }
}

// Réponse de base (toujours présente)
$place = [
    'place_id'        => $placeId,
    'name'            => $data['displayName']['text'] ?? '',
    'address'         => $fullAddr,
    'short_address'   => $shortAddr,
    'city'            => $city,
    'lat'             => $data['location']['latitude'] ?? null,
    'lng'             => $data['location']['longitude'] ?? null,
    'category'        => $data['primaryTypeDisplayName']['text'] ?? ($data['primaryType'] ?? ''),
    'primary_type'    => $data['primaryType'] ?? '',
    'rating'          => $data['rating'] ?? null,
    'reviews_count'   => $data['userRatingCount'] ?? 0,
    'website'         => $website,
    'domain'          => $domain,
    'phone'           => $data['nationalPhoneNumber'] ?? '',
    'google_maps_url' => $data['googleMapsUri'] ?? '',
    'is_sab'          => $data['_is_sab'] ?? 0,
];

// Champs extended
if (in_array($mode, ['extended', 'reviews'])) {
    // Statut
    $place['business_status'] = $data['businessStatus'] ?? 'OPERATIONAL';

    // Description
    $place['description'] = $data['editorialSummary']['text'] ?? '';

    // Types complets
    $place['types'] = $data['types'] ?? [];

    // Horaires réguliers
    $hours = [];
    if (!empty($data['regularOpeningHours']['periods'])) {
        foreach ($data['regularOpeningHours']['periods'] as $period) {
            $hours[] = [
                'day'   => $period['open']['day'] ?? 0,
                'open'  => sprintf('%02d:%02d', $period['open']['hour'] ?? 0, $period['open']['minute'] ?? 0),
                'close' => isset($period['close']) ? sprintf('%02d:%02d', $period['close']['hour'] ?? 0, $period['close']['minute'] ?? 0) : '00:00',
            ];
        }
    }
    $place['hours'] = $hours;
    $place['hours_descriptions'] = $data['regularOpeningHours']['weekdayDescriptions'] ?? [];
    $place['has_hours'] = !empty($hours);

    // Horaires actuels (temps réel ouvert/fermé)
    $place['is_open_now'] = $data['currentOpeningHours']['openNow'] ?? null;

    // Photos (références seulement, pas les images)
    $photos = [];
    foreach (($data['photos'] ?? []) as $photo) {
        $photoRef = $photo['name'] ?? '';
        if ($photoRef) {
            $photos[] = [
                'ref'    => $photoRef,
                'width'  => $photo['widthPx'] ?? 0,
                'height' => $photo['heightPx'] ?? 0,
            ];
        }
    }
    $place['photos'] = $photos;
    $place['photos_count'] = count($photos);

    // Attributs business
    $attributes = [];
    $attrMap = [
        'takeout'              => 'Vente à emporter',
        'delivery'             => 'Livraison',
        'dineIn'               => 'Sur place',
        'curbsidePickup'       => 'Retrait en bordure de route',
        'reservable'           => 'Réservation',
        'outdoorSeating'       => 'Terrasse',
        'liveMusic'            => 'Musique live',
        'servesBreakfast'      => 'Petit-déjeuner',
        'servesLunch'          => 'Déjeuner',
        'servesDinner'         => 'Dîner',
        'servesVegetarianFood' => 'Options végétariennes',
        'allowsDogs'           => 'Chiens acceptés',
        'restroom'             => 'Toilettes',
        'goodForChildren'      => 'Adapté aux enfants',
        'goodForGroups'        => 'Adapté aux groupes',
    ];
    foreach ($attrMap as $key => $label) {
        if (isset($data[$key]) && $data[$key] === true) {
            $attributes[] = ['key' => $key, 'label' => $label, 'value' => true];
        }
    }
    // Accessibilité
    if (!empty($data['accessibilityOptions'])) {
        foreach ($data['accessibilityOptions'] as $aKey => $aVal) {
            if ($aVal === true) {
                $labelMap = [
                    'wheelchairAccessibleParking'  => 'Parking accessible PMR',
                    'wheelchairAccessibleEntrance'  => 'Entrée accessible PMR',
                    'wheelchairAccessibleRestroom'  => 'Toilettes accessibles PMR',
                    'wheelchairAccessibleSeating'   => 'Places assises PMR',
                ];
                $attributes[] = ['key' => $aKey, 'label' => $labelMap[$aKey] ?? $aKey, 'value' => true];
            }
        }
    }
    // Paiement
    if (!empty($data['paymentOptions'])) {
        foreach ($data['paymentOptions'] as $pKey => $pVal) {
            if ($pVal === true) {
                $pMap = [
                    'acceptsCreditCards'  => 'Carte bancaire',
                    'acceptsDebitCards'   => 'Carte de débit',
                    'acceptsCashOnly'     => 'Espèces uniquement',
                    'acceptsNfc'          => 'Paiement sans contact',
                ];
                $attributes[] = ['key' => $pKey, 'label' => $pMap[$pKey] ?? $pKey, 'value' => true];
            }
        }
    }
    $place['attributes'] = $attributes;
    $place['attributes_count'] = count($attributes);

    // Score de complétude
    $completeness = calculatePlacesCompletenessScore($data);
    $place['completeness_score'] = $completeness['score'];
    $place['completeness_checks'] = $completeness['checks'];
}

// Champs reviews
if ($mode === 'reviews') {
    $reviews = [];
    foreach (($data['reviews'] ?? []) as $review) {
        $reviews[] = [
            'author_name'  => $review['authorAttribution']['displayName'] ?? '',
            'author_photo' => $review['authorAttribution']['photoUri'] ?? '',
            'rating'       => $review['rating'] ?? 0,
            'text'         => $review['text']['text'] ?? '',
            'language'     => $review['text']['languageCode'] ?? '',
            'time'         => $review['publishTime'] ?? '',
            'relative'     => $review['relativePublishTimeDescription'] ?? '',
        ];
    }
    $place['recent_reviews'] = $reviews;
}

// Métadonnées cache
$place['_cache_updated_at'] = $data['_cache_updated_at'] ?? null;

echo json_encode(['success' => true, 'place' => $place, 'mode' => $mode], JSON_UNESCAPED_UNICODE);
