<?php
/**
 * Neura — API Fiche Google Business Profile
 * Lecture et ecriture du profil GBP via l'API Business Information v1
 * Actions : get_profile, save_section, list_categories
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$user = currentUser();

switch ($action) {

    // ================================================================
    // GET_PROFILE — Lit le profil Google complet + donnees locales
    // ================================================================
    case 'get_profile':
        $locationId = (int)($_GET['location_id'] ?? 0);
        if (!$locationId) {
            echo json_encode(['success' => false, 'error' => 'location_id requis']);
            break;
        }

        $result = gbpReadProfile($locationId);

        if (!$result['success']) {
            echo json_encode([
                'success' => false,
                'error' => $result['error'],
                'sync_status' => 'error',
                'local' => $result['local'] ?? null,
            ]);
            break;
        }

        // Vérifier le statut Voice of Merchant (protection)
        $voiceStatus = gbpGetVoiceOfMerchant($locationId);

        echo json_encode([
            'success' => true,
            'google' => $result['google'],
            'local' => [
                'id' => $result['local']['id'],
                'name' => $result['local']['name'],
                'address' => $result['local']['address'],
                'city' => $result['local']['city'],
                'postal_code' => $result['local']['postal_code'],
                'phone' => $result['local']['phone'],
                'website' => $result['local']['website'],
                'category' => $result['local']['category'],
                'latitude' => $result['local']['latitude'],
                'longitude' => $result['local']['longitude'],
                'place_id' => $result['local']['place_id'],
            ],
            'sync_status' => 'synced',
            'protection' => [
                'hasVoice' => $voiceStatus['hasVoice'] ?? false,
                'hasBusinessAuthority' => $voiceStatus['hasBusinessAuthority'] ?? false,
                'verified' => ($voiceStatus['hasVoice'] ?? false) && ($voiceStatus['hasBusinessAuthority'] ?? false),
                'apiSuccess' => $voiceStatus['success'] ?? false,
            ],
        ]);
        break;

    // ================================================================
    // SAVE_SECTION — Met a jour une section du profil via PATCH Google
    // ================================================================
    case 'save_section':
        $locationId = (int)($_POST['location_id'] ?? 0);
        $section = $_POST['section'] ?? '';

        if (!$locationId || !$section) {
            echo json_encode(['success' => false, 'error' => 'location_id et section requis']);
            break;
        }

        $updateMask = '';
        $patchData = [];
        $dbUpdates = []; // champs a mettre a jour en local

        switch ($section) {

            case 'identity':
                $title = trim($_POST['title'] ?? '');
                if (!$title) {
                    echo json_encode(['success' => false, 'error' => 'Le nom est obligatoire']);
                    break 2;
                }
                if (mb_strlen($title) > 100) {
                    echo json_encode(['success' => false, 'error' => 'Le nom ne doit pas depasser 100 caracteres']);
                    break 2;
                }
                $updateMask = 'title';
                $patchData = ['title' => $title];
                $dbUpdates = ['name' => $title];
                break;

            case 'description':
                $description = trim($_POST['description'] ?? '');
                if (mb_strlen($description) > 750) {
                    echo json_encode(['success' => false, 'error' => 'La description ne doit pas depasser 750 caracteres']);
                    break 2;
                }
                $updateMask = 'profile.description';
                $patchData = ['profile' => ['description' => $description]];
                break;

            case 'contact':
                $phone = trim($_POST['phone'] ?? '');
                $website = trim($_POST['website'] ?? '');
                if ($website && !filter_var($website, FILTER_VALIDATE_URL)) {
                    echo json_encode(['success' => false, 'error' => 'URL du site invalide']);
                    break 2;
                }
                $updateMask = 'phoneNumbers,websiteUri';
                $patchData = [
                    'phoneNumbers' => ['primaryPhone' => $phone],
                    'websiteUri' => $website,
                ];
                $dbUpdates = ['phone' => $phone, 'website' => $website];
                break;

            case 'address':
                $addressLines = trim($_POST['address_lines'] ?? '');
                $locality = trim($_POST['locality'] ?? '');
                $postalCode = trim($_POST['postal_code'] ?? '');
                $regionCode = trim($_POST['region_code'] ?? 'FR');
                $updateMask = 'storefrontAddress';
                $patchData = [
                    'storefrontAddress' => [
                        'addressLines' => array_filter(explode("\n", $addressLines)),
                        'locality' => $locality,
                        'postalCode' => $postalCode,
                        'regionCode' => $regionCode,
                    ],
                ];
                $dbUpdates = [
                    'address' => $addressLines,
                    'city' => $locality,
                    'postal_code' => $postalCode,
                ];
                break;

            case 'categories':
                $primaryCategoryName = trim($_POST['primary_category_name'] ?? '');
                if (!$primaryCategoryName) {
                    echo json_encode(['success' => false, 'error' => 'La categorie principale est obligatoire']);
                    break 2;
                }
                $categoriesData = [
                    'primaryCategory' => ['name' => $primaryCategoryName],
                ];
                // Categories additionnelles
                $additionalJson = $_POST['additional_categories'] ?? '[]';
                $additional = json_decode($additionalJson, true);
                if (!empty($additional)) {
                    $categoriesData['additionalCategories'] = array_map(function($cat) {
                        return ['name' => $cat['name']];
                    }, $additional);
                }
                $updateMask = 'categories';
                $patchData = ['categories' => $categoriesData];
                break;

            case 'hours':
                $periodsJson = $_POST['periods'] ?? '[]';
                $periods = json_decode($periodsJson, true);
                if (!is_array($periods)) {
                    echo json_encode(['success' => false, 'error' => 'Format des horaires invalide']);
                    break 2;
                }
                // Valider les periodes
                $validDays = ['MONDAY','TUESDAY','WEDNESDAY','THURSDAY','FRIDAY','SATURDAY','SUNDAY'];
                foreach ($periods as $p) {
                    if (!in_array($p['openDay'] ?? '', $validDays) || !in_array($p['closeDay'] ?? '', $validDays)) {
                        echo json_encode(['success' => false, 'error' => 'Jour invalide dans les horaires']);
                        break 3;
                    }
                }
                $updateMask = 'regularHours';
                $patchData = ['regularHours' => ['periods' => $periods]];
                break;

            case 'special-hours':
                $specialJson = $_POST['special_hour_periods'] ?? '[]';
                $specialPeriods = json_decode($specialJson, true);
                if (!is_array($specialPeriods)) {
                    echo json_encode(['success' => false, 'error' => 'Format des horaires exceptionnels invalide']);
                    break 2;
                }
                $updateMask = 'specialHours';
                $patchData = ['specialHours' => ['specialHourPeriods' => $specialPeriods]];
                break;

            case 'service-area':
                $serviceAreaJson = $_POST['service_area'] ?? '{}';
                $serviceArea = json_decode($serviceAreaJson, true);
                if (!is_array($serviceArea)) {
                    echo json_encode(['success' => false, 'error' => 'Format de la zone de couverture invalide']);
                    break 2;
                }
                $updateMask = 'serviceArea';
                $patchData = ['serviceArea' => $serviceArea];
                break;

            default:
                echo json_encode(['success' => false, 'error' => 'Section inconnue: ' . $section]);
                break 2;
        }

        // PATCH vers Google
        $result = gbpPatchProfile($locationId, $updateMask, $patchData);

        if (!$result['success']) {
            echo json_encode([
                'success' => false,
                'error' => 'Erreur Google: ' . $result['error'],
                'details' => $result['details'] ?? null,
            ]);
            break;
        }

        // Mettre a jour la DB locale si besoin
        if (!empty($dbUpdates)) {
            $sets = [];
            $params = [];
            foreach ($dbUpdates as $col => $val) {
                $sets[] = "{$col} = ?";
                $params[] = $val;
            }
            $sets[] = 'updated_at = NOW()';
            $params[] = $locationId;
            $sql = 'UPDATE gbp_locations SET ' . implode(', ', $sets) . ' WHERE id = ?';
            db()->prepare($sql)->execute($params);
        }

        echo json_encode([
            'success' => true,
            'section' => $section,
            'message' => 'Fiche mise a jour sur Google',
        ]);
        break;

    // ================================================================
    // LIST_CATEGORIES — Autocompletion des categories Google
    // ================================================================
    case 'list_categories':
        $query = trim($_GET['q'] ?? '');
        if (mb_strlen($query) < 2) {
            echo json_encode(['categories' => []]);
            break;
        }

        try {
            $categories = gbpListCategories($query);
            echo json_encode(['categories' => $categories]);
        } catch (\Throwable $e) {
            error_log('list_categories error: ' . $e->getMessage());
            echo json_encode(['categories' => [], 'error' => $e->getMessage()]);
        }
        break;

    // ================================================================
    // AI_SUGGEST — Suggestion IA pour titre / description
    // ================================================================
    case 'ai_suggest':
        $locationId = (int)($_POST['location_id'] ?? 0);
        $field = trim($_POST['field'] ?? '');

        if (!$locationId || !in_array($field, ['title', 'description'])) {
            echo json_encode(['success' => false, 'error' => 'Parametres invalides']);
            break;
        }

        // Recuperer le contexte de la fiche
        $stmt = db()->prepare('SELECT name, category, city, website FROM gbp_locations WHERE id = ?');
        $stmt->execute([$locationId]);
        $loc = $stmt->fetch();
        if (!$loc) {
            echo json_encode(['success' => false, 'error' => 'Location introuvable']);
            break;
        }

        $currentValue = trim($_POST['current_value'] ?? '');
        $keywords = trim($_POST['keywords'] ?? '');

        try {
            $suggestion = gbpAISuggest($field, [
                'name'     => $loc['name'],
                'category' => $loc['category'],
                'city'     => $loc['city'],
                'website'  => $loc['website'],
                'current'  => $currentValue,
                'keywords' => $keywords,
            ]);

            if ($suggestion) {
                echo json_encode(['success' => true, 'suggestion' => trim($suggestion)]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Aucune suggestion generee']);
            }
        } catch (\Throwable $e) {
            error_log('ai_suggest error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erreur IA: ' . $e->getMessage()]);
        }
        break;

    // ================================================================
    // SEARCH_CITIES — Autocompletion villes françaises (geo.api.gouv.fr)
    // ================================================================
    case 'search_cities':
        $query = trim($_GET['q'] ?? '');
        if (mb_strlen($query) < 2) {
            echo json_encode(['cities' => []]);
            break;
        }

        try {
            $url = 'https://geo.api.gouv.fr/communes?' . http_build_query([
                'nom'    => $query,
                'fields' => 'nom,code,departement,codesPostaux,population',
                'boost'  => 'population',
                'limit'  => 10,
            ]);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            $response = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($response, true);
            if (!is_array($data)) {
                echo json_encode(['cities' => []]);
                break;
            }

            $cities = array_map(function($c) {
                $cp = !empty($c['codesPostaux']) ? $c['codesPostaux'][0] : '';
                $dep = $c['departement']['nom'] ?? '';
                return [
                    'name'       => $c['nom'] ?? '',
                    'code'       => $c['code'] ?? '',
                    'postalCode' => $cp,
                    'department' => $dep,
                    'label'      => ($c['nom'] ?? '') . ($cp ? " ({$cp})" : '') . ($dep ? " — {$dep}" : ''),
                ];
            }, $data);

            echo json_encode(['cities' => $cities]);
        } catch (\Throwable $e) {
            error_log('search_cities error: ' . $e->getMessage());
            echo json_encode(['cities' => [], 'error' => $e->getMessage()]);
        }
        break;

    // ================================================================
    default:
        echo json_encode(['error' => 'Action inconnue: ' . $action]);
        break;
}
