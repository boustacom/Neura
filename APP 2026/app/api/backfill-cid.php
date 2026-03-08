<?php
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../config.php';

// Helper: remove accents, punctuation, spaces for fuzzy matching
function normalize_name($str) {
    // Convert to lowercase
    $str = mb_strtolower($str, 'UTF-8');
    // Transliterate accented characters
    if (function_exists('transliterator_transliterate')) {
        $str = transliterator_transliterate('Any-Latin; Latin-ASCII', $str);
    } else {
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
    }
    // Remove everything that is not a-z or 0-9
    $str = preg_replace('/[^a-z0-9]/', '', $str);
    return $str;
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // 1. Get all locations with google_cid = NULL
    $stmtLoc = $pdo->query("SELECT id, place_id, name FROM gbp_locations WHERE google_cid IS NULL");
    $locations = $stmtLoc->fetchAll(PDO::FETCH_ASSOC);

    $locationsProcessed = count($locations);
    $cidsFound = 0;
    $cidsMissing = [];
    $log = [];

    // Prepare update statement
    $stmtUpdate = $pdo->prepare("UPDATE gbp_locations SET google_cid = ? WHERE id = ?");

    // Prepare lookup by place_id
    $stmtByPlaceId = $pdo->prepare(
        "SELECT data_cid FROM grid_competitors WHERE place_id = ? AND data_cid IS NOT NULL LIMIT 1"
    );

    // Prepare lookup by exact name (case-insensitive)
    $stmtByName = $pdo->prepare(
        "SELECT data_cid FROM grid_competitors WHERE LOWER(title) = LOWER(?) AND data_cid IS NOT NULL LIMIT 1"
    );

    // Prepare lookup for normalized name fallback: fetch all distinct (title, data_cid) pairs
    $stmtAllCompetitors = $pdo->query(
        "SELECT DISTINCT title, data_cid FROM grid_competitors WHERE data_cid IS NOT NULL AND title IS NOT NULL"
    );
    $allCompetitors = $stmtAllCompetitors->fetchAll(PDO::FETCH_ASSOC);

    // Pre-compute normalized names for competitors
    $normalizedCompetitors = [];
    foreach ($allCompetitors as $comp) {
        $normalizedCompetitors[] = [
            'normalized' => normalize_name($comp['title']),
            'title'      => $comp['title'],
            'data_cid'   => $comp['data_cid'],
        ];
    }

    foreach ($locations as $loc) {
        $foundCid = null;
        $method = null;

        // a) Match by place_id
        if (!empty($loc['place_id'])) {
            $stmtByPlaceId->execute([$loc['place_id']]);
            $row = $stmtByPlaceId->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $foundCid = $row['data_cid'];
                $method = 'place_id';
            }
        }

        // b) Exact name match (case-insensitive)
        if (!$foundCid && !empty($loc['name'])) {
            $stmtByName->execute([$loc['name']]);
            $row = $stmtByName->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $foundCid = $row['data_cid'];
                $method = 'exact_name';
            }
        }

        // c) Normalized name match
        if (!$foundCid && !empty($loc['name'])) {
            $normalizedLocName = normalize_name($loc['name']);
            if ($normalizedLocName !== '') {
                foreach ($normalizedCompetitors as $comp) {
                    if ($comp['normalized'] === $normalizedLocName) {
                        $foundCid = $comp['data_cid'];
                        $method = 'normalized_name (matched: ' . $comp['title'] . ')';
                        break;
                    }
                }
            }
        }

        // d) Update if found
        if ($foundCid) {
            $stmtUpdate->execute([$foundCid, $loc['id']]);
            $cidsFound++;
            $log[] = [
                'id'     => (int)$loc['id'],
                'name'   => $loc['name'],
                'cid'    => $foundCid,
                'method' => $method,
            ];
        } else {
            $cidsMissing[] = [
                'id'   => (int)$loc['id'],
                'name' => $loc['name'],
            ];
        }
    }

    // 3. Clean up tmp files
    $tmpDir = realpath(__DIR__ . '/../tmp');
    $tmpFilesCleaned = [];

    if ($tmpDir && is_dir($tmpDir)) {
        // Delete scan_*.json files
        $scanFiles = glob($tmpDir . '/scan_*.json');
        if ($scanFiles) {
            foreach ($scanFiles as $f) {
                if (unlink($f)) {
                    $tmpFilesCleaned[] = basename($f);
                }
            }
        }
        // Delete run_token_*.txt files
        $tokenFiles = glob($tmpDir . '/run_token_*.txt');
        if ($tokenFiles) {
            foreach ($tokenFiles as $f) {
                if (unlink($f)) {
                    $tmpFilesCleaned[] = basename($f);
                }
            }
        }
    }

    // 4. Output results as JSON
    echo json_encode([
        'locations_processed' => $locationsProcessed,
        'cids_found'          => $cidsFound,
        'cids_found_detail'   => $log,
        'cids_missing'        => $cidsMissing,
        'tmp_files_cleaned'   => $tmpFilesCleaned,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
