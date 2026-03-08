<?php
/**
 * BOUS'TACOM — Fonctions utilitaires
 */

// ============================================
// SESSION & AUTH
// ============================================

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_start([
            'cookie_httponly' => true,
            'cookie_secure'  => true,
            'cookie_samesite' => 'Lax',
        ]);
    }
}

function isLoggedIn(): bool {
    startSecureSession();
    return isset($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/auth/login.php');
        exit;
    }
    // Mode maintenance : seuls les admins passent
    if (file_exists(__DIR__ . '/../maintenance.flag') && ($_SESSION['user_role'] ?? '') !== 'admin') {
        http_response_code(503);
        if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            // Requete API → reponse JSON
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Maintenance en cours. Reessayez dans quelques minutes.']);
        } else {
            include __DIR__ . '/../views/maintenance.php';
        }
        exit;
    }
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    static $user = null;
    if ($user === null) {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
    }
    return $user;
}

/**
 * Tente une connexion. Retourne un tableau ['success' => bool, 'error' => string|null]
 */
function login(string $email, string $password): array {
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'error' => 'Email ou mot de passe incorrect.'];
    }

    // Verifier le statut du compte
    $status = $user['status'] ?? 'active';
    if ($status === 'pending') {
        return ['success' => false, 'error' => 'Votre compte est en attente de validation par un administrateur.'];
    }
    if ($status === 'suspended') {
        return ['success' => false, 'error' => 'Votre compte a ete suspendu. Contactez l\'administrateur.'];
    }

    startSecureSession();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];
    return ['success' => true, 'error' => null];
}

function logout(): void {
    startSecureSession();
    session_destroy();
    header('Location: ' . APP_URL . '/auth/login.php');
    exit;
}

// ============================================
// CSRF PROTECTION
// ============================================

function generateCsrfToken(): string {
    startSecureSession();
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function csrfField(): string {
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . generateCsrfToken() . '">';
}

function verifyCsrfToken(): bool {
    startSecureSession();
    $token = $_POST[CSRF_TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals($_SESSION[CSRF_TOKEN_NAME] ?? '', $token);
}

/**
 * Exige un token CSRF valide pour les requetes POST.
 * A appeler dans chaque endpoint API apres requireLogin().
 */
function requireCsrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrfToken()) {
        http_response_code(403);
        echo json_encode(['error' => 'Token CSRF invalide']);
        exit;
    }
}

// ============================================
// RATE LIMITING
// ============================================

function checkLoginRateLimit(string $ip): array {
    $stmt = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    $stmt->execute([$ip]);
    $count = (int)$stmt->fetchColumn();
    if ($count >= 5) {
        $stmt2 = db()->prepare('SELECT MAX(attempted_at) FROM login_attempts WHERE ip = ?');
        $stmt2->execute([$ip]);
        $last = $stmt2->fetchColumn();
        $unlockTime = strtotime($last) + 900; // +15 min
        $remaining = max(1, (int)ceil(($unlockTime - time()) / 60));
        return ['blocked' => true, 'minutes' => $remaining];
    }
    return ['blocked' => false];
}

function recordLoginAttempt(string $ip, string $email): void {
    $stmt = db()->prepare('INSERT INTO login_attempts (ip, email, attempted_at) VALUES (?, ?, NOW())');
    $stmt->execute([$ip, $email]);
}

function clearLoginAttempts(string $ip): void {
    $stmt = db()->prepare('DELETE FROM login_attempts WHERE ip = ?');
    $stmt->execute([$ip]);
}

// ============================================
// PASSWORD RESET
// ============================================

function sendPasswordResetEmail(string $email, string $rawToken): bool {
    $resetUrl = APP_URL . '/auth/reset-password.php?token=' . urlencode($rawToken);

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;background:#f5f5f5;padding:20px;">
    <div style="max-width:500px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.1);">
        <div style="background:#2563eb;padding:24px 30px;">
            <h1 style="margin:0;font-size:22px;color:#fff;font-weight:700;">NEURA</h1>
            <p style="margin:4px 0 0;font-size:13px;color:rgba(255,255,255,.6);">Reinitialisation de mot de passe</p>
        </div>
        <div style="padding:30px;">
            <p style="font-size:14px;color:#333;line-height:1.6;margin:0 0 20px;">Vous avez demande la reinitialisation de votre mot de passe. Cliquez sur le bouton ci-dessous pour en choisir un nouveau :</p>
            <div style="text-align:center;margin:24px 0;">
                <a href="' . htmlspecialchars($resetUrl) . '" style="display:inline-block;padding:14px 32px;background:#2563eb;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;font-size:14px;">Reinitialiser mon mot de passe</a>
            </div>
            <p style="font-size:12px;color:#999;line-height:1.5;">Ce lien expire dans <strong>1 heure</strong>. Si vous n\'avez pas fait cette demande, ignorez cet email.</p>
        </div>
        <div style="padding:16px 30px;background:#f8f9fa;border-top:1px solid #eee;font-size:11px;color:#999;text-align:center;">
            Neura &mdash; une solution developpee par BOUS\'TACOM
        </div>
    </div>
    </body></html>';

    $result = sendEmail($email, '', 'Reinitialisation de votre mot de passe — Neura', $html);
    return $result['success'] ?? false;
}

// ============================================
// SETTINGS (from database)
// ============================================

function getSetting(string $key, string $default = ''): string {
    $stmt = db()->prepare('SELECT value FROM settings WHERE key_name = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? ($row['value'] ?? $default) : $default;
}

function setSetting(string $key, string $value): void {
    $stmt = db()->prepare('INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?');
    $stmt->execute([$key, $value, $value]);
}

// ============================================
// GOOGLE OAUTH HELPERS
// ============================================

function getGoogleAuthUrl(): string {
    $params = http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => GOOGLE_SCOPES,
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'state'         => generateCsrfToken(),
    ]);
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
}

function exchangeGoogleCode(string $code): ?array {
    $response = httpPost('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]);
    return $response;
}

function refreshGoogleToken(string $refreshToken): ?array {
    return httpPost('https://oauth2.googleapis.com/token', [
        'refresh_token' => $refreshToken,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'grant_type'    => 'refresh_token',
    ]);
}

function getValidGoogleToken(int $accountId): ?string {
    $stmt = db()->prepare('SELECT access_token, refresh_token, token_expires_at FROM gbp_accounts WHERE id = ?');
    $stmt->execute([$accountId]);
    $account = $stmt->fetch();
    
    if (!$account) return null;
    
    // Token encore valide ?
    if (strtotime($account['token_expires_at']) > time() + 60) {
        return $account['access_token'];
    }
    
    // Refresh le token
    $result = refreshGoogleToken($account['refresh_token']);
    if ($result && isset($result['access_token'])) {
        $expiresAt = date('Y-m-d H:i:s', time() + ($result['expires_in'] ?? 3600));
        $stmt = db()->prepare('UPDATE gbp_accounts SET access_token = ?, token_expires_at = ? WHERE id = ?');
        $stmt->execute([$result['access_token'], $expiresAt, $accountId]);
        return $result['access_token'];
    }
    
    return null;
}

// ============================================
// DATAFORSEO — Google Maps & Local Finder API
// ============================================

/**
 * Appel HTTP generique vers l'API DataForSEO.
 * Auth: HTTP Basic (login:password en Base64).
 *
 * @param string $method   'GET' ou 'POST'
 * @param string $endpoint Ex: '/serp/google/maps/task_post'
 * @param array|null $body Body JSON pour POST, null pour GET
 * @return array Reponse decodee avec _http_code ajoute
 */
function dataforseoRequest(string $method, string $endpoint, ?array $body = null, int $timeout = 120): array {
    $url = DATAFORSEO_API_URL . $endpoint;

    $ch = curl_init($url);
    $headers = [
        'Authorization: Basic ' . base64_encode(DATAFORSEO_LOGIN . ':' . DATAFORSEO_PASSWORD),
        'Content-Type: application/json',
    ];

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'BOUSTACOM/2.0',
    ];

    if ($method === 'POST' && $body !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("DataForSEO CURL error: {$curlError} - HTTP {$httpCode} - {$method} {$endpoint}");
        return ['_http_code' => 0, '_api_error' => "Erreur reseau: {$curlError}"];
    }

    $decoded = json_decode($response, true);
    if (!$decoded) {
        error_log("DataForSEO: JSON decode failed - HTTP {$httpCode} - " . substr($response, 0, 500));
        return ['_http_code' => $httpCode, '_api_error' => 'Reponse invalide de DataForSEO'];
    }

    $decoded['_http_code'] = $httpCode;

    // Gestion erreurs globales
    $statusCode = $decoded['status_code'] ?? 0;
    if ($statusCode !== 20000 && $statusCode !== 20100) {
        $msg = $decoded['status_message'] ?? 'Erreur DataForSEO';
        if ($httpCode === 401 || $httpCode === 403) {
            $msg = 'Identifiants DataForSEO invalides. Verifiez DATAFORSEO_LOGIN/PASSWORD dans config.php.';
        } elseif ($httpCode === 402) {
            $msg = 'Credits DataForSEO epuises. Rechargez sur app.dataforseo.com.';
        } elseif ($httpCode === 429) {
            $msg = 'Trop de requetes DataForSEO. Reessayez dans quelques minutes.';
        }
        $decoded['_api_error'] = $msg;
    }

    return $decoded;
}

/**
 * Poster un batch de tasks Google Maps vers DataForSEO.
 * Chaque task = 1 recherche Google Maps avec coordonnees GPS precises.
 *
 * @param array $tasks Array de tasks [{keyword, lat, lng, tag?}, ...]
 * @param int $depth   Nombre de resultats (defaut 20)
 * @return array {success, task_ids: [...], error?, cost?}
 */
function dataforseoPostTasks(array $tasks, int $depth = 100, bool $gridMode = false): array {
    $body = [];
    foreach ($tasks as $task) {
        $lat = round($task['lat'], 7);
        $lng = round($task['lng'], 7);
        // CRITIQUE : sprintf force le point decimal (locale-independant)
        $locationCoord = sprintf('%.7f,%.7f,13', $lat, $lng);

        // STRATEGIE :
        //   - gridMode=true  → endpoint LOCAL_FINDER + keyword+ville
        //     Local Finder retourne les MEMES commerces a tout point GPS,
        //     seul le CLASSEMENT change par proximite (comme Localo).
        //   - gridMode=false → endpoint MAPS + keyword+ville
        //     Pour le position tracking depuis le centre (viewport OK).
        //
        // TOUJOURS fusionner keyword + ville (les deux modes)
        $searchKeyword = $task['keyword'];
        if (!empty($task['target_city'])) {
            $cityParts = explode(',', $task['target_city']);
            $cityShort = trim($cityParts[0]);
            if (stripos($task['keyword'], $cityShort) === false) {
                $searchKeyword = $task['keyword'] . ' ' . $cityShort;
            }
        }

        $endpointLabel = $gridMode ? 'GRID(local_finder)' : 'POS(maps)';
        error_log("[DATAFORSEO] mode={$endpointLabel} q=\"{$searchKeyword}\" coord={$locationCoord}");

        $apiTask = [
            'keyword'             => $searchKeyword,
            'location_coordinate' => $locationCoord,
            'language_code'       => 'fr',
            'depth'               => $depth,
            'device'              => 'mobile',
            'os'                  => 'android',
            'tag'                 => $task['tag'] ?? null,
        ];

        // PAS de search_this_area — ni pour maps ni pour local_finder

        $body[] = $apiTask;
    }

    // gridMode → local_finder (resultats stables sur toute la grille)
    // posMode  → maps (viewport OK depuis le centre)
    $postEndpoint = $gridMode
        ? '/serp/google/local_finder/task_post'
        : '/serp/google/maps/task_post';

    $response = dataforseoRequest('POST', $postEndpoint, $body);

    if (isset($response['_api_error'])) {
        return ['success' => false, 'error' => $response['_api_error'], 'task_ids' => []];
    }

    // Verifier le status de chaque task individuellement
    // DataForSEO peut retourner status_code global 20000 mais 40200 par task (402 Payment Required)
    $taskIds = [];
    $totalCost = 0;
    $taskErrors = 0;
    $firstTaskError = null;
    foreach ($response['tasks'] ?? [] as $t) {
        $tStatus = $t['status_code'] ?? 0;
        if ($tStatus === 40200) {
            // 402 — Payment Required : credits epuises
            $taskErrors++;
            if (!$firstTaskError) $firstTaskError = 'Credits DataForSEO epuises (402). Rechargez sur app.dataforseo.com.';
        } elseif ($tStatus !== 20000 && $tStatus !== 20100) {
            $taskErrors++;
            if (!$firstTaskError) $firstTaskError = ($t['status_message'] ?? "Erreur task {$tStatus}");
        }
        if (!empty($t['id'])) {
            $taskIds[] = $t['id'];
            $totalCost += $t['cost'] ?? 0;
        }
    }

    // Si TOUTES les tasks sont en erreur, remonter l'erreur
    if ($taskErrors > 0 && $taskErrors === count($response['tasks'] ?? [])) {
        return ['success' => false, 'error' => $firstTaskError, 'task_ids' => []];
    }

    $usedEndpoint = $gridMode ? 'local_finder' : 'maps';
    return ['success' => true, 'task_ids' => $taskIds, 'cost' => $totalCost, 'endpoint' => $usedEndpoint];
}

/**
 * Envoyer les tasks a l'API LOCAL_FINDER en mode LIVE (synchrone) via curl_multi.
 *
 * L'endpoint /local_finder/live/advanced n'accepte qu'UNE SEULE task par appel.
 * On utilise curl_multi pour envoyer les 48 requetes EN PARALLELE :
 *   - Chaque requete contient 1 seule task
 *   - Toutes les requetes sont envoyees simultanement
 *   - Temps total ≈ temps de la requete la plus lente (~30-60s)
 *
 * @param array $tasks  Meme format que dataforseoPostTasks
 * @param int $depth    Profondeur des resultats (max 100 en live)
 * @return array ['success', 'task_ids', 'results' => [taskId => {items, tag}], 'cost']
 */
/**
 * Scan grille GPS via curl_multi — Local Finder Live
 *
 * Local Finder simule le "Local Pack" de Google Search.
 * Les positions VARIENT selon le GPS (confirmé en prod : 59% visibilité avec positions 7-18).
 * NE PAS utiliser Maps ici : le viewport Maps à 10km du centre montre des commerces
 * sans rapport et donne des résultats incohérents (5% au lieu de 59%).
 */
function dataforseoLocalFinderLive(array $tasks, int $depth = 100): array {
    $url = DATAFORSEO_API_URL . '/serp/google/local_finder/live/advanced';
    $auth = 'Authorization: Basic ' . base64_encode(DATAFORSEO_LOGIN . ':' . DATAFORSEO_PASSWORD);

    // Preparer les bodies individuels (1 task par requete)
    $requestBodies = [];
    $tags = [];
    foreach ($tasks as $i => $task) {
        $lat = round($task['lat'], 7);
        $lng = round($task['lng'], 7);
        $locationCoord = sprintf('%.7f,%.7f,13', $lat, $lng);

        // TOUJOURS fusionner keyword + ville
        $searchKeyword = $task['keyword'];
        if (!empty($task['target_city'])) {
            $cityParts = explode(',', $task['target_city']);
            $cityShort = trim($cityParts[0]);
            if (stripos($task['keyword'], $cityShort) === false) {
                $searchKeyword = $task['keyword'] . ' ' . $cityShort;
            }
        }

        $apiTask = [
            'keyword'             => $searchKeyword,
            'location_coordinate' => $locationCoord,
            'language_code'       => 'fr',
            'depth'               => min($depth, 100),
            'device'              => 'mobile',
            'os'                  => 'android',
            'tag'                 => $task['tag'] ?? null,
        ];

        // IMPORTANT : envelopper dans un array car l'API attend un tableau de tasks
        // meme si on n'en envoie qu'une seule
        $requestBodies[$i] = json_encode([$apiTask]);
        $tags[$i] = $task['tag'] ?? null;
    }

    $taskCount = count($requestBodies);
    error_log("[DATAFORSEO] LOCAL_FINDER LIVE curl_multi: {$taskCount} requetes paralleles");

    // ====== CURL_MULTI : toutes les requetes en parallele ======
    $mh = curl_multi_init();
    $handles = [];

    foreach ($requestBodies as $i => $jsonBody) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_HTTPHEADER     => [$auth, 'Content-Type: application/json'],
            CURLOPT_USERAGENT      => 'BOUSTACOM/2.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }

    // Executer toutes les requetes
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 1.0);
        }
    } while ($active && $status === CURLM_OK);

    // ====== COLLECTER LES RESULTATS ======
    $taskIds = [];
    $results = [];
    $totalCost = 0;
    $taskErrors = 0;
    $firstTaskError = null;

    foreach ($handles as $i => $ch) {
        $rawResponse = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        $tag = $tags[$i] ?? null;

        // Erreur CURL
        if ($curlError || !$rawResponse) {
            $taskId = "live_{$i}";
            $taskIds[] = $taskId;
            $taskErrors++;
            $errMsg = $curlError ?: "HTTP {$httpCode} vide";
            if (!$firstTaskError) $firstTaskError = $errMsg;
            $results[$taskId] = ['items' => [], 'tag' => $tag, 'api_error' => "CURL: {$errMsg}"];
            continue;
        }

        // Decoder JSON
        $decoded = json_decode($rawResponse, true);
        if (!$decoded) {
            $taskId = "live_{$i}";
            $taskIds[] = $taskId;
            $taskErrors++;
            if (!$firstTaskError) $firstTaskError = 'JSON decode failed';
            $results[$taskId] = ['items' => [], 'tag' => $tag, 'api_error' => 'JSON decode failed'];
            continue;
        }

        // Extraire la task (1 seule par reponse)
        $t = $decoded['tasks'][0] ?? null;
        if (!$t) {
            $taskId = "live_{$i}";
            $taskIds[] = $taskId;
            $taskErrors++;
            if (!$firstTaskError) $firstTaskError = 'Pas de task dans la reponse';
            $results[$taskId] = ['items' => [], 'tag' => $tag, 'api_error' => 'Pas de task'];
            continue;
        }

        $taskId = $t['id'] ?? "live_{$i}";
        $taskIds[] = $taskId;
        $totalCost += $t['cost'] ?? 0;

        $statusCode = $t['status_code'] ?? 0;
        $taskTag = $t['data']['tag'] ?? $tag;

        if ($statusCode !== 20000) {
            $msg = $t['status_message'] ?? "status {$statusCode}";
            $taskErrors++;
            if (!$firstTaskError) $firstTaskError = $msg;
            $results[$taskId] = ['items' => [], 'tag' => $taskTag, 'api_error' => $msg];
            continue;
        }

        // Extraire et normaliser les items
        $result = $t['result'][0] ?? null;
        $normalized = [];
        foreach (($result['items'] ?? []) as $idx => $item) {
            $itemType = $item['type'] ?? '';
            if ($itemType === 'maps_paid_item') continue;
            if ($itemType !== 'local_pack' && $itemType !== 'maps_search') continue;
            $normalized[] = normalizeDataforseoItem($item, $idx);
        }

        $results[$taskId] = ['items' => $normalized, 'tag' => $taskTag];
    }

    curl_multi_close($mh);

    // Si TOUTES les tasks sont en erreur, remonter l'erreur
    if ($taskErrors > 0 && $taskErrors === $taskCount) {
        return ['success' => false, 'error' => $firstTaskError, 'task_ids' => [], 'results' => []];
    }

    error_log("[DATAFORSEO] LOCAL_FINDER LIVE curl_multi: {$taskCount} done, " . ($taskCount - $taskErrors) . " ok, cout=\${$totalCost}");

    return [
        'success'  => true,
        'task_ids' => $taskIds,
        'results'  => $results,
        'cost'     => $totalCost,
        'endpoint' => 'local_finder',
    ];
}

/**
 * Recuperer le resultat d'une task DataForSEO.
 *
 * @param string $taskId UUID de la task
 * @return array|null Items normalises, ou null si la task est encore en cours.
 *   Retourne ['items'=>[], 'api_error'=>...] pour les erreurs terminales (40102, etc.)
 *   afin que le polling s'arrete au lieu de boucler indefiniment.
 */
function dataforseoGetResult(string $taskId, string $endpoint = 'maps'): ?array {
    $response = dataforseoRequest('GET', "/serp/google/{$endpoint}/task_get/advanced/{$taskId}");

    // Erreur reseau/auth
    if (isset($response['_api_error'])) {
        $httpCode = $response['_http_code'] ?? 0;
        // Erreurs fatales (auth, credits) → arreter le polling
        if (in_array($httpCode, [401, 402, 403], true)) {
            error_log("DataForSEO fatal HTTP {$httpCode} task {$taskId}: {$response['_api_error']}");
            return ['items' => [], 'tag' => null, 'api_error' => $response['_api_error']];
        }
        return null; // Erreur reseau temporaire → reessayer
    }

    $tasks = $response['tasks'] ?? [];
    if (empty($tasks)) return null;

    $task = $tasks[0];
    $statusCode = $task['status_code'] ?? 0;
    $tag = $task['data']['tag'] ?? null;

    // Statuts "encore en cours" → null pour continuer le polling
    // 20100 = Task Created (processing), 40602 = Task In Queue
    if ($statusCode === 20100 || $statusCode === 40602) {
        return null;
    }

    // Statuts d'erreur terminaux → arreter le polling, retourner vide
    // 40102 = No Search Results, 40101 = Cancelled, 40601 = Expired, etc.
    if ($statusCode !== 20000) {
        $msg = $task['status_message'] ?? "status {$statusCode}";
        error_log("DataForSEO task {$taskId}: {$msg} (code {$statusCode})");
        return ['items' => [], 'tag' => $tag, 'api_error' => $msg];
    }

    // 20000 = succes
    $result = $task['result'][0] ?? null;
    if (!$result || empty($result['items'])) {
        return ['items' => [], 'tag' => $tag];
    }

    // Normaliser les items vers notre format interne
    // maps → type='maps_search', local_finder → type='local_pack'
    // On accepte les deux, on exclut maps_paid_item
    $normalized = [];
    foreach ($result['items'] as $idx => $item) {
        $itemType = $item['type'] ?? '';
        if ($itemType === 'maps_paid_item') continue;
        if ($itemType !== 'maps_search' && $itemType !== 'local_pack') continue;
        $normalized[] = normalizeDataforseoItem($item, $idx);
    }

    return [
        'items' => $normalized,
        'tag'   => $tag,
    ];
}

/**
 * Attendre que toutes les tasks soient terminees et recuperer les resultats.
 * Polling fixe toutes les 5 secondes.
 *
 * @param array $taskIds         Array de task UUIDs
 * @param int $maxWaitSec        Temps max d'attente (defaut 180s)
 * @param callable|null $onProgress  Callback(int $completed, int $total)
 * @return array taskId => {items, tag} ou null si timeout
 */
function dataforseoWaitForResults(array $taskIds, int $maxWaitSec = 180, ?callable $onProgress = null, int $pollInterval = 5, string $endpoint = 'maps'): array {
    $results = [];
    $pending = array_flip($taskIds);
    $startTime = time();

    while (!empty($pending) && (time() - $startTime) < $maxWaitSec) {
        sleep($pollInterval);

        foreach (array_keys($pending) as $taskId) {
            $result = dataforseoGetResult($taskId, $endpoint);
            if ($result !== null) {
                $results[$taskId] = $result;
                unset($pending[$taskId]);
            }
        }

        if ($onProgress) {
            $onProgress(count($results), count($taskIds));
        }
    }

    // Les tasks restantes = timeout
    foreach (array_keys($pending) as $taskId) {
        $results[$taskId] = null;
    }

    return $results;
}

/**
 * Normaliser un item DataForSEO vers le format interne utilise par
 * computeRankForPoint(), extractPlaceIdFromItem(), extractCidFromItem().
 *
 * DataForSEO → format interne normalise :
 *   rank_group → position
 *   title → title
 *   address → address
 *   phone → phone
 *   url/domain → link, website
 *   cid → data_cid
 *   place_id → place_id
 *   rating.value → rating
 *   rating.votes_count → reviews
 *   category → category
 */
function normalizeDataforseoItem(array $item, int $index): array {
    return [
        'position'  => $item['rank_group'] ?? ($index + 1),
        'title'     => $item['title'] ?? '',
        'address'   => $item['address'] ?? '',
        'phone'     => $item['phone'] ?? null,
        'link'      => $item['url'] ?? null,
        'website'   => $item['url'] ?? $item['contact_url'] ?? null,
        'data_cid'  => isset($item['cid']) ? (string)$item['cid'] : null,
        'place_id'  => $item['place_id'] ?? null,
        'data_id'   => $item['feature_id'] ?? null,
        'rating'    => $item['rating']['value'] ?? null,
        'reviews'   => $item['rating']['votes_count'] ?? 0,
        'category'  => $item['category'] ?? null,
        'type'      => $item['category'] ?? null,
        'latitude'  => $item['latitude'] ?? null,
        'longitude' => $item['longitude'] ?? null,
        'domain'    => $item['domain'] ?? null,
        'total_photos' => $item['total_photos'] ?? null,
    ];
}

/**
 * Appelle DataForSEO My Business Info Live pour recuperer les donnees detaillees
 * d'un etablissement (description, photos, attributs).
 *
 * Endpoint: /business_data/google/my_business_info/live
 * Cout: ~$0.01/task
 * Note: le champ 'keyword' est OBLIGATOIRE pour cet endpoint.
 * Note: DataForSEO ne retourne PAS les liens reseaux sociaux du GBP.
 *
 * @param string $placeId      Le place_id Google
 * @param string $businessName Le nom de l'entreprise (requis comme keyword)
 * @return array|null  Les donnees ou null en cas d'erreur
 */
function dataforseoMyBusinessInfo(string $placeId, string $businessName = ''): ?array {
    if (empty($placeId)) return null;
    if (empty($businessName)) $businessName = 'business'; // fallback

    $payload = [
        [
            'keyword' => $businessName,
            'place_id' => $placeId,
            'language_code' => 'fr',
            'location_name' => 'France',
        ]
    ];

    $response = dataforseoRequest('POST', '/business_data/google/my_business_info/live', $payload, 45);

    if (!$response || !isset($response['tasks'][0]['result'][0])) {
        $statusCode = $response['tasks'][0]['status_code'] ?? 0;
        $statusMsg = $response['tasks'][0]['status_message'] ?? 'unknown';
        error_log("[MyBusinessInfo] Pas de resultat pour place_id: {$placeId} (code: {$statusCode}, msg: {$statusMsg})");
        return null;
    }

    // La structure est: result[0] -> items[0] (le business info est DANS items)
    $resultWrapper = $response['tasks'][0]['result'][0];
    $result = $resultWrapper['items'][0] ?? $resultWrapper;

    // Description : chercher dans plusieurs champs possibles
    $description = $result['description'] ?? $result['snippet'] ?? $result['description_text'] ?? null;
    // Si pas trouvé dans items[0], chercher dans le wrapper direct
    if (empty($description) && !empty($resultWrapper['description'])) {
        $description = $resultWrapper['description'];
    }

    // Log pour debug si pas de description trouvée
    if (empty($description)) {
        $availableKeys = array_keys($result);
        error_log("[MyBusinessInfo] Pas de description pour place_id: {$placeId}. Clés disponibles: " . implode(', ', $availableKeys));
    }

    return [
        'description' => $description,
        'total_photos' => $result['total_photos'] ?? null,
        'attributes' => $result['attributes'] ?? [],
        'place_topics' => $result['place_topics'] ?? [],
        'popular_times' => !empty($result['popular_times']) ? true : false,
        'is_claimed' => $result['is_claimed'] ?? null,
        'rating_distribution' => $result['rating_distribution'] ?? null,
    ];
}

/**
 * Scrape le site web d'un prospect pour trouver :
 * - Les liens Facebook et Instagram (depuis le footer/header)
 * - Les adresses email de contact
 *
 * Gratuit (pas d'appel API). Note: DataForSEO ne retourne pas les liens
 * reseaux sociaux du GBP, donc le scraping du site est la meilleure alternative.
 *
 * @param string $domain  Le domaine du site (ex: "monentreprise.fr")
 * @return array {facebook: string|null, instagram: string|null, emails: string[]}
 */
function scrapeWebsiteInfo(string $domain): array {
    $result = ['facebook' => null, 'instagram' => null, 'emails' => []];
    if (empty($domain)) return $result;

    $url = 'https://' . $domain;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING => '', // accepter gzip
    ]);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$html || $httpCode >= 400) {
        error_log("[ScrapeWebsite] Echec fetch {$url} (HTTP {$httpCode})");
        return $result;
    }

    // === RESEAUX SOCIAUX ===

    // Chercher les liens Facebook
    if (preg_match_all('/href=["\']?(https?:\/\/(?:www\.)?facebook\.com\/[^\s"\'<>]+)/i', $html, $matches)) {
        foreach ($matches[1] as $fbUrl) {
            // Exclure les liens generiques (sharer, share, login, etc.)
            if (!preg_match('/\/(sharer|share|login|dialog|plugins|tr)\b/i', $fbUrl)) {
                $result['facebook'] = rtrim($fbUrl, '/');
                break;
            }
        }
    }

    // Chercher les liens Instagram
    if (preg_match_all('/href=["\']?(https?:\/\/(?:www\.)?instagram\.com\/[^\s"\'<>]+)/i', $html, $matches)) {
        foreach ($matches[1] as $igUrl) {
            // Exclure les liens generiques
            if (!preg_match('/\/(explore|accounts|p\/|reel\/|developer)\b/i', $igUrl)) {
                $result['instagram'] = rtrim($igUrl, '/');
                break;
            }
        }
    }

    // === EMAILS ===

    // Methode 1: liens mailto:
    $emailsFound = [];
    if (preg_match_all('/href=["\']mailto:([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/i', $html, $matches)) {
        foreach ($matches[1] as $email) {
            $email = strtolower(trim($email));
            if (!in_array($email, $emailsFound)) {
                $emailsFound[] = $email;
            }
        }
    }

    // Methode 2: emails dans le texte visible (hors attributs de tags)
    // Nettoyer les tags HTML mais garder le texte
    $textContent = strip_tags(str_replace(['<', '>'], [' <', '> '], $html));
    if (preg_match_all('/\b([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})\b/', $textContent, $matches)) {
        foreach ($matches[1] as $email) {
            $email = strtolower(trim($email));
            // Exclure les emails generiques/techniques
            if (in_array($email, $emailsFound)) continue;
            if (preg_match('/@(example|test|localhost|wixpress|sentry)\./i', $email)) continue;
            if (preg_match('/\.(png|jpg|gif|svg|css|js)$/i', $email)) continue;
            $emailsFound[] = $email;
        }
    }

    // Trier : privilegier contact@, info@, accueil@ en premier
    usort($emailsFound, function ($a, $b) {
        $priority = ['contact@' => 1, 'info@' => 2, 'accueil@' => 3, 'bonjour@' => 4, 'hello@' => 5];
        $pa = 99; $pb = 99;
        foreach ($priority as $prefix => $prio) {
            if (strpos($a, $prefix) === 0) $pa = $prio;
            if (strpos($b, $prefix) === 0) $pb = $prio;
        }
        return $pa - $pb;
    });

    $result['emails'] = array_slice($emailsFound, 0, 5); // max 5 emails

    return $result;
}

/**
 * Recupere le nombre d'avis sans reponse du proprietaire via DataForSEO Reviews.
 *
 * Endpoint: /business_data/google/reviews/live/advanced
 * Cout: ~$0.002/task
 *
 * @param string $placeId  Le place_id Google
 * @return array {total_reviews: int, unanswered: int, unanswered_pct: float}
 */
function dataforseoReviewsAnalysis(string $placeId): array {
    $default = ['total_reviews' => 0, 'unanswered' => 0, 'unanswered_pct' => 0];
    if (empty($placeId)) return $default;

    $payload = [
        [
            'place_id' => $placeId,
            'language_code' => 'fr',
            'depth' => 100, // max 100 avis par requete
        ]
    ];

    $response = dataforseoRequest('POST', '/business_data/google/reviews/live/advanced', $payload, 30);

    if (!$response || !isset($response['tasks'][0]['result'][0])) {
        error_log('[ReviewsAnalysis] Pas de resultat pour place_id: ' . $placeId);
        return $default;
    }

    $result = $response['tasks'][0]['result'][0];
    $items = $result['items'] ?? [];
    $totalReviews = count($items);
    $unanswered = 0;

    foreach ($items as $review) {
        if (empty($review['owner_answer'])) {
            $unanswered++;
        }
    }

    $pct = $totalReviews > 0 ? round(($unanswered / $totalReviews) * 100, 1) : 0;

    return [
        'total_reviews' => $totalReviews,
        'unanswered' => $unanswered,
        'unanswered_pct' => $pct,
    ];
}

// ============================================
// UULE — Geolocalisation precise Google
// ============================================

/**
 * Genere un parametre UULE Google a partir d'un nom canonique.
 *
 * Le UULE v1 (User Location) encode le lieu exact pour la requete Google.
 * Format : w+CAIQICI<length_char><base64_canonical_name>
 *
 * La table de correspondance longueur → caractere est fixe (spec Google Ads):
 * longueur_en_bytes mod 64 → caractere dans l'alphabet Base64
 *
 * References :
 *   - https://valentin.app/uule.html
 *   - https://blog.linkody.com/seo-local/uule-2
 *
 * @param string $canonicalName  Ex: "Allassac,Correze,Nouvelle-Aquitaine,France"
 * @return string Le parametre UULE encode
 */
function generateUULE(string $canonicalName): string {
    // Alphabet Base64 standard (meme que Google utilise pour le length char)
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    $length = strlen($canonicalName);
    $lengthChar = $chars[$length % 64];
    // Le canonical name DOIT etre encode en Base64 (sans padding =)
    $encoded = rtrim(base64_encode($canonicalName), '=');
    return 'w+CAIQICI' . $lengthChar . $encoded;
}

/**
 * Reverse-geocode des coordonnees GPS vers un nom canonique Google.
 * Utilise Nominatim (OpenStreetMap) gratuit.
 * Cache le resultat en base pour eviter les appels repetitifs.
 *
 * @param float $lat Latitude
 * @param float $lng Longitude
 * @return string|null Nom canonique (ex: "Allassac,Correze,Nouvelle-Aquitaine,France") ou null
 */
function reverseGeocodeToCanonical(float $lat, float $lng): ?string {
    $url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
        'lat'             => $lat,
        'lon'             => $lng,
        'format'          => 'json',
        'zoom'            => 14,
        'addressdetails'  => 1,
    ]);
    $ctx = stream_context_create(['http' => [
        'header'  => 'User-Agent: BOUSTACOM/1.0',
        'timeout' => 5,
    ]]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return null;

    $data = json_decode($json, true);
    if (!$data || !isset($data['address'])) return null;

    $addr = $data['address'];

    // Construire le nom canonique a la Google :
    // Ville/commune, Departement, Region, Pays
    $parts = [];
    $city = $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['municipality'] ?? $addr['hamlet'] ?? '';
    if ($city) $parts[] = $city;
    $county = $addr['county'] ?? '';
    if ($county) $parts[] = $county;
    $state = $addr['state'] ?? '';
    if ($state) $parts[] = $state;
    $country = $addr['country'] ?? 'France';
    $parts[] = $country;

    $canonical = implode(',', $parts);

    // Supprimer les accents — Google UULE attend des noms ASCII
    $canonicalAscii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $canonical);
    return $canonicalAscii ?: $canonical;
}

/**
 * Recupere les coordonnees GPS d'une fiche.
 * Strategie multi-fallback :
 * 1. API GBP : fetch direct avec readMask=latlng,metadata → extraire latlng OU parser mapsUri
 * 2. Google Maps redirect : suivre l'URL place_id pour extraire les coords de la redirection
 * Retourne [lat, lng] ou null.
 */
function geocodeByPlaceId(string $placeId, string $token, string $businessName = '', string $city = '', string $googleLocationId = ''): ?array {
    error_log("geocodeByPlaceId() START — placeId={$placeId}, business={$businessName}, city={$city}, glid={$googleLocationId}");

    // Strategie 1 : Re-fetch la fiche GBP directement pour recuperer latlng ET metadata.mapsUri
    if ($googleLocationId && $token) {
        $url = "https://mybusinessbusinessinformation.googleapis.com/v1/{$googleLocationId}?readMask=latlng,metadata";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        error_log("geocodeByPlaceId() GBP API response HTTP={$httpCode}, body=" . substr($response ?: '', 0, 500));

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);

            // 1a. Essayer latlng direct
            if (!empty($data['latlng']['latitude']) && !empty($data['latlng']['longitude'])) {
                error_log("geocodeByPlaceId() SUCCESS via GBP latlng direct");
                return [(float)$data['latlng']['latitude'], (float)$data['latlng']['longitude']];
            }

            // 1b. Essayer metadata.mapsUri — contient souvent les coords dans l'URL
            if (!empty($data['metadata']['mapsUri'])) {
                $mapsUri = $data['metadata']['mapsUri'];
                error_log("geocodeByPlaceId() mapsUri found: {$mapsUri}");
                $coords = extractCoordsFromMapsUrl($mapsUri);
                if ($coords) {
                    error_log("geocodeByPlaceId() SUCCESS via mapsUri parsing");
                    return $coords;
                }
            }
        }
    }

    // Strategie 2 : Google Maps redirect via place_id (pas de service tiers)
    // Note: les anciens fallbacks ValueSERP ont ete supprimes lors de la migration DataForSEO.

    error_log("geocodeByPlaceId() FAILED — all strategies exhausted");
    return null;
}

/**
 * Extrait les coordonnees GPS d'une URL Google Maps.
 * Supporte les formats :
 * - /@48.856614,2.3522219,...
 * - /place/.../@48.856614,2.3522219,...
 * - ?q=...&ll=48.856614,2.3522219
 * - center=48.856614,2.3522219
 * - !3d48.856614!4d2.3522219
 * @return array|null [lat, lng] ou null
 */
function extractCoordsFromMapsUrl(string $url): ?array {
    // Pattern 1 : /@lat,lng,... (le plus courant)
    if (preg_match('/@(-?\d+\.\d{4,}),(-?\d+\.\d{4,})/', $url, $m)) {
        return [(float)$m[1], (float)$m[2]];
    }
    // Pattern 2 : !3dlat!4dlng (format encoded Google Maps)
    if (preg_match('/!3d(-?\d+\.\d{4,})!4d(-?\d+\.\d{4,})/', $url, $m)) {
        return [(float)$m[1], (float)$m[2]];
    }
    // Pattern 3 : ll=lat,lng (query param)
    if (preg_match('/[?&]ll=(-?\d+\.\d{4,}),(-?\d+\.\d{4,})/', $url, $m)) {
        return [(float)$m[1], (float)$m[2]];
    }
    // Pattern 4 : center=lat%2Clng (URL encoded)
    if (preg_match('/center=(-?\d+\.\d{4,})%2C(-?\d+\.\d{4,})/', $url, $m)) {
        return [(float)$m[1], (float)$m[2]];
    }
    return null;
}

/**
 * Geocode une ville vers des coordonnees GPS via Nominatim.
 * Retourne [lat, lng] ou null.
 */
function geocodeCity(string $cityName): ?array {
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q'      => $cityName . ', France',
        'format' => 'json',
        'limit'  => 1,
    ]);
    $ctx = stream_context_create(['http' => [
        'header'  => 'User-Agent: BOUSTACOM/1.0',
        'timeout' => 5,
    ]]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return null;

    $data = json_decode($json, true);
    if (!empty($data[0]['lat']) && !empty($data[0]['lon'])) {
        return [(float)$data[0]['lat'], (float)$data[0]['lon']];
    }
    return null;
}

/**
 * Geocoder une adresse complete via Nominatim (rue + ville + CP).
 * Beaucoup plus precis que geocodeCity() car on utilise l'adresse complete.
 */
function geocodeFullAddress(string $address, string $city, string $postalCode = '', string $country = 'France'): ?array {
    // Construire la query la plus precise possible
    $parts = array_filter([$address, $postalCode, $city, $country]);
    $query = implode(', ', $parts);
    if (empty(trim($query))) return null;

    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q'      => $query,
        'format' => 'json',
        'limit'  => 1,
    ]);
    $ctx = stream_context_create(['http' => [
        'header'  => 'User-Agent: BOUSTACOM/1.0',
        'timeout' => 5,
    ]]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return null;

    $data = json_decode($json, true);
    if (!empty($data[0]['lat']) && !empty($data[0]['lon'])) {
        return [(float)$data[0]['lat'], (float)$data[0]['lon']];
    }
    return null;
}

/**
 * Obtenir un UULE pour des coordonnees GPS.
 * Reverse geocode → nom canonique → UULE.
 */
function getUULEForCoordinates(float $lat, float $lng): ?string {
    $canonical = reverseGeocodeToCanonical($lat, $lng);
    if (!$canonical) return null;
    return generateUULE($canonical);
}

/**
 * Obtenir un UULE pour une ville.
 * Geocode → reverse geocode → nom canonique → UULE.
 */
function getUULEForCity(string $city): ?string {
    $coords = geocodeCity($city);
    if (!$coords) return null;
    return getUULEForCoordinates($coords[0], $coords[1]);
}

// ============================================
// AI HELPERS (Claude / OpenAI)
// ============================================

function generateAIReply(string $reviewText, int $rating, string $reviewerName, array $settings): ?string {
    $provider = $settings['ai_provider'] ?? 'claude';
    $toneMap = [
        'professional_warm' => 'professionnel et chaleureux',
        'formal'            => 'formel et courtois',
        'casual'            => 'décontracté et amical',
        'empathetic'        => 'empathique et compréhensif',
    ];
    $tone = $toneMap[$settings['tone']] ?? 'professionnel et chaleureux';
    $instructions = $settings['custom_instructions'] ?? '';
    
    $prompt = "Tu es l'assistant de réponse aux avis Google pour une entreprise locale.
Génère une réponse à cet avis Google.

AVIS:
- Auteur: {$reviewerName}
- Note: {$rating}/5 étoiles
- Commentaire: {$reviewText}

CONSIGNES:
- Ton: {$tone}
- Langue: Français
- Longueur: 2-4 phrases maximum
- {$instructions}
- Ne mets PAS de formule d'appel type 'Cher/Chère'
- Commence directement par remercier

Réponds UNIQUEMENT avec le texte de la réponse, sans guillemets ni explications.";

    if ($provider === 'claude') {
        return callClaude($prompt);
    } else {
        return callOpenAI($prompt);
    }
}

function callClaude(string $prompt, int $maxTokens = 300): ?string {
    if (empty(CLAUDE_API_KEY)) {
        throw new Exception('Clé API Claude non configurée dans config.php');
    }

    $ch = curl_init(CLAUDE_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'      => CLAUDE_MODEL,
            'max_tokens' => $maxTokens,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . CLAUDE_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('Erreur connexion Claude: ' . $curlError);
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200) {
        $errorMsg = $data['error']['message'] ?? "HTTP {$httpCode}";
        throw new Exception('API Claude: ' . $errorMsg);
    }

    return $data['content'][0]['text'] ?? null;
}

function callOpenAI(string $prompt): ?string {
    $ch = curl_init(OPENAI_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'    => OPENAI_MODEL,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 300,
        ]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? null;
}

// ============================================
// AI REVIEW REPLY — SHARED FUNCTION
// ============================================

/**
 * Genere une reponse IA a un avis Google en utilisant les settings de la fiche.
 * Utilisee par reviews.php et reviews-all.php
 *
 * @param array $review Donnees de l'avis (rating, comment, reviewer_name)
 * @param string $businessName Nom de l'entreprise
 * @param string $category Categorie de l'entreprise
 * @param array $settings Settings IA (owner_name, gender, default_tone, custom_instructions)
 * @param string $forceTone Optionnel : forcer un ton specifique
 * @return string La reponse generee
 */
function generateReviewReplyWithSettings(array $review, string $businessName, string $category, array $settings, string $forceTone = ''): string {
    $ownerName = $settings['owner_name'] ?? "L'equipe";
    $gender = $settings['gender'] ?? 'neutral';
    $customInstructions = $settings['custom_instructions'] ?? '';
    $tone = $forceTone ?: ($settings['default_tone'] ?? 'professional');

    // Templates intro/closing/signature
    $reviewIntro = $settings['review_intro'] ?? 'Bonjour {prénom},';
    $reviewClosing = $settings['review_closing'] ?? 'À bientôt,';
    $reviewSignature = $settings['review_signature'] ?? $businessName;
    $reviewSpeech = $settings['review_speech'] ?? 'vous';

    $toneDesc = match($tone) {
        'friendly' => 'amical et chaleureux, comme un ami',
        'empathetic' => 'empathique et comprehensif, montrant une vraie ecoute',
        default => 'professionnel mais humain, courtois et attentionne',
    };

    $genderDesc = match($gender) {
        'male' => "Tu parles en tant qu'homme, a la premiere personne du singulier (je). Utilise la forme masculine pour les accords (ex: \"je suis ravi\", \"je suis heureux\", \"enchante\").",
        'female' => "Tu parles en tant que femme, a la premiere personne du singulier (je). Utilise la forme feminine pour les accords (ex: \"je suis ravie\", \"je suis heureuse\", \"enchantee\").",
        default => "Tu parles au nom de l'entreprise/la marque \"{$businessName}\", a la premiere personne du pluriel (nous). Utilise des formules collectives (ex: \"nous sommes ravis\", \"notre equipe\", \"nous vous remercions\").",
    };

    $stars = $review['rating'];
    $comment = $review['comment'] ?? '';
    $author = $review['reviewer_name'] ?? $review['author_name'] ?? 'Client';
    // Extraire le prenom (premier mot du nom)
    $prenom = explode(' ', trim($author))[0];

    $identite = match($gender) {
        'neutral' => "Tu representes l'entreprise \"{$businessName}\"",
        default => "Tu es {$ownerName}, gerant" . ($gender === 'female' ? 'e' : '') . " de l'entreprise \"{$businessName}\"",
    };

    // Construire l'intro avec le prenom
    $introFinal = str_replace('{prénom}', $prenom, $reviewIntro);

    $prompt = "{$identite} (categorie: {$category}). Tu dois rediger une reponse a un avis Google.

INFORMATIONS SUR L'AVIS :
- Auteur : {$author} (prenom: {$prenom})
- Note : {$stars}/5 etoiles
- Commentaire : " . ($comment ? "\"{$comment}\"" : "(pas de commentaire, juste la note)") . "

TON DE LA REPONSE : {$toneDesc}

VOIX / GENRE :
{$genderDesc}

TUTOIEMENT / VOUVOIEMENT :
" . ($reviewSpeech === 'tu' ? "TUTOIE le client (utilise \"tu\", \"ton\", \"ta\", \"tes\", \"toi\"). Par exemple : \"Merci pour ton avis !\", \"On espere te revoir bientot !\"" : "VOUVOIE le client (utilise \"vous\", \"votre\", \"vos\"). Par exemple : \"Merci pour votre avis !\", \"Nous esperons vous revoir bientot !\"") . "

FORMAT OBLIGATOIRE DE LA REPONSE :
1. Commence OBLIGATOIREMENT par : \"{$introFinal}\"
2. Corps : 2-4 phrases (remerciement + contenu adapte a la note)
3. Termine OBLIGATOIREMENT par :
{$reviewClosing}
{$reviewSignature}

REGLES IMPORTANTES :
- Reponds en francais
- Respecte EXACTEMENT le format ci-dessus (intro + corps + closing + signature)
- Sois concis (le corps fait 2-4 phrases, pas plus)
- Si l'avis est positif (4-5 etoiles) : remercie chaleureusement, mentionne un aspect specifique si le commentaire en contient
- Si l'avis est mitige (3 etoiles) : remercie, reconnais les points d'amelioration, propose de s'ameliorer
- Si l'avis est negatif (1-2 etoiles) : reste respectueux, montre de l'empathie, propose un contact direct pour resoudre le probleme
- N'invente pas de details sur l'entreprise
- Ne mets pas de guillemets autour de la reponse
" . ($customInstructions ? "\nINSTRUCTIONS SUPPLEMENTAIRES : {$customInstructions}" : "") . "

Ecris uniquement la reponse, sans rien d'autre.";

    $reply = callClaude($prompt);

    if (!$reply) {
        throw new Exception("La generation IA n'a retourne aucune reponse. Verifiez votre cle API Claude dans config.php.");
    }

    return trim($reply);
}

// ============================================
// HTTP HELPERS
// ============================================

function httpPost(string $url, array $data): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function httpGet(string $url, array $headers = []): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function httpPostJson(string $url, array $data, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    $decoded = json_decode($response, true) ?? [];
    $decoded['_http_code'] = $httpCode;
    if ($curlError) $decoded['_curl_error'] = $curlError;
    return $decoded;
}

function httpPutJson(string $url, array $data, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    $decoded = json_decode($response, true) ?? [];
    $decoded['_http_code'] = $httpCode;
    if ($curlError) $decoded['_curl_error'] = $curlError;
    return $decoded;
}

function httpPatchJson(string $url, array $data, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    $decoded = json_decode($response, true) ?? [];
    $decoded['_http_code'] = $httpCode;
    if ($curlError) $decoded['_curl_error'] = $curlError;
    return $decoded;
}

function httpDeleteWithHeaders(string $url, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['_http_code' => $httpCode, 'body' => json_decode($response, true)];
}

// ============================================
// GOOGLE API v4 URL HELPERS
// ============================================

/**
 * Construit le chemin v4 complet pour une location.
 * L'API v4 attend : accounts/{id}/locations/{id}
 *
 * @param string $googleAccountName Format "accounts/XXXXX"
 * @param string $googleLocationId  Format "locations/YYYYY"
 * @return string "accounts/XXXXX/locations/YYYYY"
 */
function buildGoogleV4LocationPath(string $googleAccountName, string $googleLocationId): string {
    // Si google_location_id contient deja le path complet, le retourner
    if (str_starts_with($googleLocationId, 'accounts/')) {
        return $googleLocationId;
    }
    // Sinon, combiner account name + location id
    return rtrim($googleAccountName, '/') . '/' . ltrim($googleLocationId, '/');
}

/**
 * Recupere le google_account_name pour une location donnee.
 */
function getGoogleAccountNameForLocation(int $locationId): ?string {
    $stmt = db()->prepare('
        SELECT a.google_account_name
        FROM gbp_locations l
        JOIN gbp_accounts a ON l.gbp_account_id = a.id
        WHERE l.id = ?
    ');
    $stmt->execute([$locationId]);
    $row = $stmt->fetch();
    return $row['google_account_name'] ?? null;
}

// ============================================
// GOOGLE API : AUTO-GUERISON LOCATION PATH
// ============================================

/**
 * Resout le chemin v4 correct d'une location Google en auto-guerissant les IDs perimes.
 *
 * Strategie :
 * 1. Liste TOUS les comptes v1 et TOUTES leurs locations
 * 2. Match d'abord par location ID exact, sinon par NOM de fiche
 * 3. Si un nouveau location ID est trouve, met a jour la DB automatiquement
 * 4. Retourne le path v4 correct (accounts/XXX/locations/YYY)
 *
 * @param string $googleLocationId  L'ID Google de la fiche (ex: locations/YYY)
 * @param string $googleAccountName Le nom du compte GBP (ex: accounts/XXX)
 * @param string $token             Token OAuth2 Google valide
 * @param int    $dbLocationId      L'ID en base de la location (pour auto-heal)
 * @return array ['success' => bool, 'v4_path' => string, 'account_id' => string, 'location_id' => string, 'healed' => bool, 'error' => string|null]
 */
function resolveGoogleLocationPath(string $googleLocationId, string $googleAccountName, string $token, int $dbLocationId = 0): array {
    $headers = ['Authorization: Bearer ' . $token];
    $numericLocId = preg_replace('/^locations\//', '', $googleLocationId);
    $numericAccId = preg_replace('/^accounts\//', '', $googleAccountName);

    // Recuperer le nom de la fiche en DB pour matching par nom
    $locNameForMatch = '';
    if ($dbLocationId > 0) {
        $stmtName = db()->prepare('SELECT name FROM gbp_locations WHERE id = ?');
        $stmtName->execute([$dbLocationId]);
        $locNameForMatch = $stmtName->fetchColumn() ?: '';
    }

    $correctAccId = null;
    $correctLocId = null;
    $allLocations = [];

    $v1Accounts = httpGet('https://mybusinessaccountmanagement.googleapis.com/v1/accounts', $headers);
    if ($v1Accounts && !empty($v1Accounts['accounts'])) {
        foreach ($v1Accounts['accounts'] as $v1Acc) {
            $v1AccName = $v1Acc['name'] ?? '';
            if (!$v1AccName) continue;
            $v1AccNumId = preg_replace('/^accounts\//', '', $v1AccName);

            $pageToken = null;
            do {
                $v1LocsUrl = "https://mybusinessbusinessinformation.googleapis.com/v1/{$v1AccName}/locations?readMask=name,title&pageSize=100";
                if ($pageToken) $v1LocsUrl .= '&pageToken=' . urlencode($pageToken);
                $v1Locs = httpGet($v1LocsUrl, $headers);

                if ($v1Locs && !empty($v1Locs['locations'])) {
                    foreach ($v1Locs['locations'] as $v1Loc) {
                        $v1LocName = $v1Loc['name'] ?? '';
                        $v1LocTitle = $v1Loc['title'] ?? '';
                        $v1LocNumId = preg_replace('/^locations\//', '', $v1LocName);
                        $allLocations[] = "{$v1LocTitle} ({$v1LocName}) → compte {$v1AccName}";

                        if ($v1LocNumId === $numericLocId) {
                            $correctAccId = $v1AccNumId;
                            $correctLocId = $numericLocId;
                            break 3;
                        }

                        if (!$correctLocId && $locNameForMatch && mb_strtolower(trim($v1LocTitle)) === mb_strtolower(trim($locNameForMatch))) {
                            $correctAccId = $v1AccNumId;
                            $correctLocId = $v1LocNumId;
                        }
                    }
                }
                $pageToken = $v1Locs['nextPageToken'] ?? null;
            } while ($pageToken);
        }
    }

    $healed = false;

    // Auto-guerison si nouveau location ID trouve
    if ($correctLocId && $correctLocId !== $numericLocId && $dbLocationId > 0) {
        try {
            $stmtFix = db()->prepare('UPDATE gbp_locations SET google_location_id = ?, updated_at = NOW() WHERE id = ?');
            $stmtFix->execute(["locations/{$correctLocId}", $dbLocationId]);
            if ($correctAccId && $correctAccId !== $numericAccId) {
                $stmtFixAcc = db()->prepare('UPDATE gbp_accounts SET google_account_name = ? WHERE id = (SELECT gbp_account_id FROM gbp_locations WHERE id = ?)');
                $stmtFixAcc->execute(["accounts/{$correctAccId}", $dbLocationId]);
            }
            $healed = true;
        } catch (\Throwable $e) {
            error_log("resolveGoogleLocationPath auto-heal: " . $e->getMessage());
        }
    }

    $effectiveAccId = $correctAccId ?: $numericAccId;
    $effectiveLocId = $correctLocId ?: $numericLocId;

    if (!$effectiveAccId || !$effectiveLocId) {
        $locListStr = !empty($allLocations) ? implode(", ", array_slice($allLocations, 0, 5)) : 'AUCUNE';
        return [
            'success' => false,
            'v4_path' => '',
            'account_id' => $effectiveAccId ?: '',
            'location_id' => $effectiveLocId ?: '',
            'healed' => false,
            'error' => "Fiche introuvable dans Google. Fiches trouvees : {$locListStr}. Reconnectez votre compte Google."
        ];
    }

    return [
        'success' => true,
        'v4_path' => "accounts/{$effectiveAccId}/locations/{$effectiveLocId}",
        'account_id' => $effectiveAccId,
        'location_id' => $effectiveLocId,
        'healed' => $healed,
        'error' => null
    ];
}

// ============================================
// GOOGLE POSTS : PUBLICATION HELPER
// ============================================

/**
 * Publie un post sur Google Business Profile via l'API v4.
 *
 * @param array $post Donnees du post (content, image_url, call_to_action_type, etc.)
 * @param string $googleLocationId L'ID Google de la fiche (ex: locations/YYY)
 * @param string $token Token OAuth2 Google valide
 * @param string $googleAccountName Le nom du compte GBP (ex: accounts/XXX)
 * @return array ['success' => bool, 'google_post_id' => string|null, 'error' => string|null]
 */
function publishPostToGoogle(array $post, string $googleLocationId, string $token, string $googleAccountName = '', int $dbLocationId = 0): array {
    // Construire le payload selon le type de post
    $payload = [
        'languageCode' => 'fr',
        'summary' => $post['content'],
        'topicType' => $post['post_type'] ?? 'STANDARD',
    ];

    // Image
    if (!empty($post['image_url'])) {
        $payload['media'] = [
            ['mediaFormat' => 'PHOTO', 'sourceUrl' => $post['image_url']]
        ];
    }

    // CTA (Call To Action)
    if (!empty($post['call_to_action_type']) && $post['call_to_action_type'] !== 'NONE') {
        $cta = ['actionType' => $post['call_to_action_type']];
        if (!empty($post['call_to_action_url'])) {
            $cta['url'] = $post['call_to_action_url'];
        }
        $payload['callToAction'] = $cta;
    }

    // Événement
    if (($post['post_type'] ?? 'STANDARD') === 'EVENT') {
        $payload['event'] = [
            'title' => $post['event_title'] ?? $post['title'] ?? 'Événement',
            'schedule' => [
                'startDate' => formatGoogleDate($post['event_start']),
                'startTime' => formatGoogleTime($post['event_start']),
            ],
        ];
        if (!empty($post['event_end'])) {
            $payload['event']['schedule']['endDate'] = formatGoogleDate($post['event_end']);
            $payload['event']['schedule']['endTime'] = formatGoogleTime($post['event_end']);
        }
    }

    // Offre
    if (($post['post_type'] ?? 'STANDARD') === 'OFFER') {
        $payload['offer'] = [];
        if (!empty($post['offer_coupon_code'])) {
            $payload['offer']['couponCode'] = $post['offer_coupon_code'];
        }
        if (!empty($post['offer_terms'])) {
            $payload['offer']['termsConditions'] = $post['offer_terms'];
        }
    }

    // Construire le path v4 complet via auto-guerison
    if (!$googleAccountName && !str_starts_with($googleLocationId, 'accounts/')) {
        return ['success' => false, 'error' => "Compte Google non configure (google_account_name manquant). Reconnectez votre compte Google."];
    }

    // Utiliser resolveGoogleLocationPath pour trouver le bon path
    $resolved = resolveGoogleLocationPath($googleLocationId, $googleAccountName, $token, $dbLocationId);

    if (!$resolved['success']) {
        return ['success' => false, 'error' => $resolved['error']];
    }

    $headers = ['Authorization: Bearer ' . $token];
    $attempts = [];

    // Construire les URLs a essayer
    $urls = [];
    $urls[] = "https://mybusiness.googleapis.com/v4/{$resolved['v4_path']}/localPosts";
    $urls[] = "https://mybusiness.googleapis.com/v4/locations/{$resolved['location_id']}/localPosts";
    $urls = array_values(array_unique($urls));

    // Essayer chaque URL
    foreach ($urls as $idx => $url) {
        $result = httpPostJson($url, $payload, $headers);
        $httpCode = $result['_http_code'] ?? 0;
        $errorDetail = $result['error']['message'] ?? ($result['error']['status'] ?? '');
        // Capturer les details supplementaires de l'erreur Google
        $errorDetails = '';
        if (!empty($result['error']['details'])) {
            foreach ($result['error']['details'] as $d) {
                $errorDetails .= ' | ' . json_encode($d, JSON_UNESCAPED_UNICODE);
            }
        }

        $attempts[] = "URL " . ($idx + 1) . ": {$url} → HTTP {$httpCode}" . ($errorDetail ? " ({$errorDetail})" : "") . $errorDetails;

        if ($httpCode >= 200 && $httpCode < 300 && !empty($result['name'])) {
            return ['success' => true, 'google_post_id' => $result['name']];
        }

        if ($httpCode === 401 || $httpCode === 403) {
            return ['success' => false, 'error' => ($result['error']['message'] ?? "HTTP {$httpCode}") . " — Reconnectez votre compte Google."];
        }
    }

    // Diagnostic complet avec payload
    $payloadDebug = "topicType=" . ($payload['topicType'] ?? '?')
        . ", summary=" . mb_substr($payload['summary'] ?? '', 0, 50) . "..."
        . ", media=" . (!empty($payload['media']) ? 'oui' : 'non')
        . ", cta=" . (!empty($payload['callToAction']) ? ($payload['callToAction']['actionType'] ?? '?') : 'non');

    $errorMsg = "Publication impossible.\n\n" . implode("\n", $attempts)
        . "\n\n• Path : {$resolved['v4_path']}"
        . "\n• Payload : {$payloadDebug}"
        . ($resolved['healed'] ? "\n• Auto-guerison : OUI" : "")
        . "\n\nReconnectez votre compte Google si le probleme persiste.";
    return ['success' => false, 'error' => $errorMsg];
}

/**
 * Publier une photo sur la galerie Google Business Profile.
 * Utilise l'endpoint media de l'API v4.
 */
function publishPhotoToGoogle(array $photo, string $googleLocationId, string $token, string $googleAccountName = '', int $dbLocationId = 0): array {
    if (empty($photo['file_url'])) {
        return ['success' => false, 'error' => 'URL de la photo manquante.'];
    }

    // S'assurer que l'URL est absolue
    $sourceUrl = $photo['file_url'];
    if (!str_starts_with($sourceUrl, 'http')) {
        $sourceUrl = rtrim(APP_URL, '/') . '/' . ltrim($sourceUrl, '/');
    }

    // Catégories acceptées par l'API Google Media
    $validCats = ['COVER','PROFILE','EXTERIOR','INTERIOR','PRODUCT','AT_WORK','FOOD_AND_DRINK','MENU','COMMON_AREA','ROOMS','TEAMS','ADDITIONAL'];
    $category = $photo['category'] ?? 'ADDITIONAL';
    if (!in_array($category, $validCats)) $category = 'ADDITIONAL';

    $payload = [
        'mediaFormat' => 'PHOTO',
        'sourceUrl' => $sourceUrl,
        'locationAssociation' => [
            'category' => $category,
        ],
    ];

    // Résoudre le path Google
    if (!$googleAccountName && !str_starts_with($googleLocationId, 'accounts/')) {
        return ['success' => false, 'error' => 'Compte Google non configuré (google_account_name manquant).'];
    }

    $resolved = resolveGoogleLocationPath($googleLocationId, $googleAccountName, $token, $dbLocationId);
    if (!$resolved['success']) {
        return ['success' => false, 'error' => $resolved['error']];
    }

    $headers = ['Authorization: Bearer ' . $token];
    $attempts = [];

    // URLs à essayer — v4 avec account path (le seul qui fonctionne pour media)
    $urls = [];
    $urls[] = "https://mybusiness.googleapis.com/v4/{$resolved['v4_path']}/media";
    // Fallback sans account
    $urls[] = "https://mybusiness.googleapis.com/v4/locations/{$resolved['location_id']}/media";
    $urls = array_values(array_unique($urls));

    foreach ($urls as $idx => $url) {
        $result = httpPostJson($url, $payload, $headers);
        $httpCode = $result['_http_code'] ?? 0;
        $errorDetail = $result['error']['message'] ?? ($result['error']['status'] ?? '');
        $errorDetails = '';
        if (!empty($result['error']['details'])) {
            foreach ($result['error']['details'] as $d) {
                $errorDetails .= ' | ' . json_encode($d, JSON_UNESCAPED_UNICODE);
            }
        }
        $attempts[] = "URL " . ($idx + 1) . ": {$url} → HTTP {$httpCode}" . ($errorDetail ? " ({$errorDetail})" : "") . $errorDetails;

        if ($httpCode >= 200 && $httpCode < 300 && !empty($result['name'])) {
            return ['success' => true, 'google_media_name' => $result['name']];
        }

        // Si 400 avec category spécifique, retry avec ADDITIONAL
        if ($httpCode === 400 && $category !== 'ADDITIONAL') {
            $payload['locationAssociation']['category'] = 'ADDITIONAL';
            $result2 = httpPostJson($url, $payload, $headers);
            $httpCode2 = $result2['_http_code'] ?? 0;
            $attempts[] = "URL " . ($idx + 1) . " (retry ADDITIONAL): {$url} → HTTP {$httpCode2}";
            if ($httpCode2 >= 200 && $httpCode2 < 300 && !empty($result2['name'])) {
                return ['success' => true, 'google_media_name' => $result2['name']];
            }
        }

        if ($httpCode === 401 || $httpCode === 403) {
            return ['success' => false, 'error' => ($result['error']['message'] ?? "HTTP {$httpCode}") . " — Reconnectez votre compte Google."];
        }
    }

    $errorMsg = "Publication photo impossible.\n" . implode("\n", $attempts)
        . "\n\n• Path : {$resolved['v4_path']}"
        . "\n• sourceUrl : " . $sourceUrl
        . "\n• category : " . $category
        . "\n\nVérifiez que l'image est accessible publiquement.";
    return ['success' => false, 'error' => $errorMsg];
}

function formatGoogleDate(string $datetime): array {
    $d = new DateTime($datetime);
    return ['year' => (int)$d->format('Y'), 'month' => (int)$d->format('m'), 'day' => (int)$d->format('d')];
}

function formatGoogleTime(string $datetime): array {
    $d = new DateTime($datetime);
    return ['hours' => (int)$d->format('H'), 'minutes' => (int)$d->format('i'), 'seconds' => 0, 'nanos' => 0];
}

// ============================================
// EMAIL HELPERS (PHPMailer)
// ============================================

/**
 * Envoie un email via SMTP InfoManiak avec PHPMailer.
 *
 * @param string $to Adresse du destinataire
 * @param string $toName Nom du destinataire
 * @param string $subject Sujet de l'email
 * @param string $htmlBody Corps HTML de l'email
 * @param string|null $attachmentPath Chemin du fichier PDF a joindre (optionnel)
 * @return array ['success' => bool, 'error' => string|null]
 */
function sendEmail(string $to, string $toName, string $subject, string $htmlBody, ?string $attachmentPath = null, ?string $bcc = null): array {
    // Charger PHPMailer
    $phpmailerDir = __DIR__ . '/phpmailer/';
    require_once $phpmailerDir . 'Exception.php';
    require_once $phpmailerDir . 'PHPMailer.php';
    require_once $phpmailerDir . 'SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // Configuration SMTP
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        // Expediteur
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);

        // Destinataire
        $mail->addAddress($to, $toName);

        // Copie cachee (BCC)
        if ($bcc) {
            $mail->addBCC($bcc);
        }

        // Contenu
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody));

        // Piece jointe PDF (optionnel)
        if ($attachmentPath && file_exists($attachmentPath)) {
            $mail->addAttachment($attachmentPath);
        }

        $mail->send();
        return ['success' => true, 'error' => null];
    } catch (\Exception $e) {
        return ['success' => false, 'error' => $mail->ErrorInfo ?: $e->getMessage()];
    }
}

/**
 * Envoie un rapport par email avec mise en forme HTML
 */
function sendReportEmail(string $to, string $toName, string $subject, string $body, ?string $pdfPath = null): array {
    // Convertir le body texte en HTML stylise
    $bodyHtml = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;background:#f5f5f5;padding:20px;">
    <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.1);">
        <div style="background:#2563eb;padding:24px 30px;">
            <h1 style="margin:0;font-size:22px;color:#fff;font-weight:700;">NEURA</h1>
            <p style="margin:4px 0 0;font-size:13px;color:rgba(255,255,255,.6);">Rapport de performance SEO local</p>
        </div>
        <div style="padding:30px;">
            <div style="font-size:14px;line-height:1.8;color:#333;">' . $bodyHtml . '</div>
        </div>
        <div style="padding:16px 30px;background:#f8f9fa;border-top:1px solid #eee;font-size:11px;color:#999;text-align:center;">
            Neura &mdash; une solution developpee par BOUS\'TACOM
        </div>
    </div>
    </body></html>';

    // BCC : copie automatique de tous les rapports
    return sendEmail($to, $toName, $subject, $html, $pdfPath, 'contact@boustacom.fr');
}

// ============================================
// MATCHING & RANKING UTILITIES
// ============================================

/**
 * Extraire le place_id depuis un item de resultats SERP.
 * Cherche dans plusieurs champs pour compatibilite.
 */
function extractPlaceIdFromItem(array $item): ?string
{
    // 1. Champ direct place_id
    if (!empty($item['place_id']) && is_string($item['place_id'])) {
        return $item['place_id'];
    }

    // 2. data_id peut contenir le place_id (format ChIJ...)
    $dataId = $item['data_id'] ?? '';
    if ($dataId) {
        if (str_starts_with($dataId, 'ChIJ') || str_starts_with($dataId, '0x')) {
            return $dataId;
        }
        if (preg_match('/(ChIJ[A-Za-z0-9_-]+)/', $dataId, $m)) {
            return $m[1];
        }
    }

    // 3. Parser depuis le lien Google Maps
    $link = $item['link'] ?? $item['url'] ?? '';
    if ($link && preg_match('/place_id[=:]([A-Za-z0-9_-]+)/', $link, $m)) {
        return $m[1];
    }
    if ($link && preg_match('/ftid[=:]([A-Za-z0-9:_-]+)/', $link, $m)) {
        return $m[1];
    }

    return null;
}

/**
 * Extraire le CID (data_cid) depuis un item de resultats SERP.
 * Normalise en string (les CID sont des entiers 64-bit qui overflow en PHP).
 */
function extractCidFromItem(array $item): ?string
{
    $cid = $item['data_cid'] ?? null;
    if ($cid !== null && $cid !== '') {
        return (string)$cid;
    }

    // Fallback: data_id numerique pur
    $dataId = $item['data_id'] ?? '';
    if ($dataId && preg_match('/^(\d{10,25})$/', $dataId)) {
        return $dataId;
    }

    return null;
}

/**
 * Normaliser un nom d'entreprise pour le matching fuzzy.
 * Minuscules, sans accents, sans ponctuation, espaces normalises.
 * Ex: "BOUS'TACOM" → "boustacom", "Boulangerie Pâtisserie" → "boulangerie patisserie"
 */
function normalizeTitle(string $str): string
{
    $str = mb_strtolower(trim($str), 'UTF-8');
    $str = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str) ?: $str;
    $str = strtolower($str);
    $str = preg_replace('/[^\p{L}\p{N}\s]/u', '', $str);
    $str = preg_replace('/\s+/', ' ', trim($str));
    return $str;
}

/**
 * Normaliser une adresse pour le matching fuzzy.
 * Comme normalizeTitle + suppression des stop-words francais et codes postaux.
 */
function normalizeAddress(string $str): string
{
    $str = normalizeTitle($str);
    // Retirer les stop-words francais d'adresse
    $stopWords = [
        'rue', 'avenue', 'av', 'boulevard', 'bd', 'blvd', 'place', 'pl',
        'chemin', 'ch', 'route', 'rte', 'impasse', 'imp', 'allee', 'alle',
        'cours', 'passage', 'psg', 'square', 'sq', 'quai',
        'lot', 'lotissement', 'residence', 'bat', 'batiment',
        'de', 'du', 'des', 'la', 'le', 'les', 'l', 'et', 'a', 'au', 'aux',
        'cedex', 'bp', 'cs',
    ];
    $pattern = '/\b(' . implode('|', $stopWords) . ')\b/i';
    $str = preg_replace($pattern, '', $str);
    // Retirer les codes postaux (5 chiffres)
    $str = preg_replace('/\b\d{5}\b/', '', $str);
    $str = preg_replace('/\s+/', ' ', trim($str));
    return $str;
}

/**
 * Extraire les resultats places depuis une reponse DataForSEO normalisee.
 * Les items sont deja normalises par normalizeDataforseoItem().
 */
function extractResultsFromResponse(?array $response): array
{
    if (!$response) return [];
    return $response['items'] ?? [];
}

/**
 * FONCTION CENTRALE — Trouver notre fiche dans les resultats Google Maps pour un point GPS.
 *
 * Matching 3-tier :
 *   1. place_id exact — identifiant Google le plus stable
 *   2. CID exact — backup si Place ID pas encore connu
 *   PAS de fuzzy name — trop de faux positifs avec accents/tirets
 *
 * @param array $locationRow    Ligne gbp_locations (place_id, google_cid)
 * @param array $placesResults  Resultats normalises (items DataForSEO)
 * @return array {rank, found, matched_name, match_method, matched_item, backfill}
 */
function computeRankForPoint(array $locationRow, array $placesResults, bool $fuzzyFallback = false): array
{
    $result = [
        'rank'         => null,
        'found'        => false,
        'matched_name' => null,
        'match_method' => null,
        'matched_item' => null,
        'backfill'     => [],
    ];

    if (empty($placesResults)) {
        return $result;
    }

    $ourPlaceId = $locationRow['place_id'] ?? '';
    $ourCid     = $locationRow['google_cid'] ?? '';

    // ==== TIER 1 : Place ID exact (seul identifiant qui ne ment jamais) ====
    if ($ourPlaceId) {
        foreach ($placesResults as $idx => $item) {
            $itemPlaceId = extractPlaceIdFromItem($item);
            if ($itemPlaceId && $itemPlaceId === $ourPlaceId) {
                $itemCid = extractCidFromItem($item);
                $result['rank']         = $item['position'] ?? ($idx + 1);
                $result['found']        = true;
                $result['matched_name'] = $item['title'] ?? '';
                $result['match_method'] = 'place_id';
                $result['matched_item'] = $item;
                if (!$ourCid && $itemCid) {
                    $result['backfill']['google_cid'] = $itemCid;
                }
                return $result;
            }
        }
    }

    // ==== TIER 2 : CID exact (backup si Place ID pas encore renseigne) ====
    if ($ourCid) {
        foreach ($placesResults as $idx => $item) {
            $itemCid = extractCidFromItem($item);
            if ($itemCid && $itemCid === $ourCid) {
                $itemPlaceId = extractPlaceIdFromItem($item);
                $result['rank']         = $item['position'] ?? ($idx + 1);
                $result['found']        = true;
                $result['matched_name'] = $item['title'] ?? '';
                $result['match_method'] = 'cid';
                $result['matched_item'] = $item;
                if (!$ourPlaceId && $itemPlaceId) {
                    $result['backfill']['place_id'] = $itemPlaceId;
                }
                return $result;
            }
        }
    }

    // ==== TIER 3 : Fuzzy name matching (pour les prospects sans place_id/CID fiable) ====
    // Active uniquement quand $fuzzyFallback = true (utilise par le scan prospect)
    // Pas utilise pour le suivi de mots-cles (risque de faux positifs)
    $ourName = $locationRow['name'] ?? '';
    if ($fuzzyFallback && $ourName) {
        $ourNorm = normalizeTitle($ourName);
        $bestMatch = null;
        $bestScore = 0;

        foreach ($placesResults as $idx => $item) {
            $itemTitle = $item['title'] ?? '';
            if (!$itemTitle) continue;
            $itemNorm = normalizeTitle($itemTitle);

            // Match exact normalise
            if ($ourNorm === $itemNorm) {
                $bestMatch = $idx;
                $bestScore = 100;
                break;
            }

            // Containment : le nom normalise du prospect est contenu dans l'item ou vice-versa
            if (mb_strlen($ourNorm) >= 4 && mb_strlen($itemNorm) >= 4) {
                if (str_contains($itemNorm, $ourNorm) || str_contains($ourNorm, $itemNorm)) {
                    $score = 90;
                    if ($score > $bestScore) { $bestScore = $score; $bestMatch = $idx; }
                    continue;
                }
            }

            // Similarite >= 85%
            similar_text($ourNorm, $itemNorm, $pct);
            if ($pct >= 85 && $pct > $bestScore) {
                $bestScore = $pct;
                $bestMatch = $idx;
            }
        }

        if ($bestMatch !== null && $bestScore >= 85) {
            $item = $placesResults[$bestMatch];
            $itemPlaceId = extractPlaceIdFromItem($item);
            $itemCid = extractCidFromItem($item);

            $result['rank']         = $item['position'] ?? ($bestMatch + 1);
            $result['found']        = true;
            $result['matched_name'] = $item['title'] ?? '';
            $result['match_method'] = 'fuzzy_name';
            $result['matched_item'] = $item;

            // Backfill les identifiants decouverts
            if (!$ourPlaceId && $itemPlaceId) {
                $result['backfill']['place_id'] = $itemPlaceId;
            }
            if (!$ourCid && $itemCid) {
                $result['backfill']['google_cid'] = $itemCid;
            }
            return $result;
        }
    }

    // API a repondu mais notre fiche n'est pas dans les resultats (depth=100)
    $result['rank'] = 101;
    return $result;
}

/**
 * Calcule les KPI d'un scan de grille a partir des positions de chaque point.
 *
 * Regles :
 * - Top 3 = nombre de points ou la fiche est classee 1ere, 2eme ou 3eme
 * - Visibilite = (Top 3 / Total points) × 100
 * - Position moyenne = moyenne de toutes les positions (null ou non-trouve = 101)
 *
 * @param array $positions  Liste des positions (int|null) pour chaque point de la grille
 * @param int   $totalPoints  Nombre total de points dans la grille
 * @return array ['top3_count' => int, 'top10_count' => int, 'top20_count' => int, 'out_count' => int, 'visibility_score' => int, 'avg_position' => float|null]
 */
function computeGridKPIs(array $positions, int $totalPoints): array {
    $top3 = 0;
    $top10 = 0;
    $top20 = 0;
    $out = 0;
    $posSum = 0;
    $weightedSum = 0;

    foreach ($positions as $pos) {
        $p = ($pos === null || $pos === false) ? 101 : (int)$pos;
        if ($p <= 3)  $top3++;
        if ($p <= 10) $top10++;
        if ($p <= 20) $top20++;
        if ($p > 20)  $out++;
        $posSum += min($p, 101);

        // Decroissement lineaire (identique Localo/LocalFalcon) :
        // Rang 1 = 20pts, Rang 2 = 19pts, ..., Rang 20 = 1pt, Rang 21+ = 0pt
        if ($p <= 20) {
            $weightedSum += (21 - $p);
        }
    }

    $count = count($positions);
    $maxScore = $totalPoints * 20; // score max si tout rang 1

    return [
        'top3_count'       => $top3,
        'top10_count'      => $top10,
        'top20_count'      => $top20,
        'out_count'        => $out,
        'visibility_score' => $maxScore > 0 ? (int)round(($weightedSum / $maxScore) * 100) : 0,
        'avg_position'     => $count > 0 ? round($posSum / $count, 1) : null,
    ];
}

/**
 * Persister les IDs decouverts (CID, place_id) dans gbp_locations.
 * N'ecrase JAMAIS une valeur existante (COALESCE/NULLIF).
 */
function applyBackfill(int $locationId, array $backfill, string $callerContext = ''): void
{
    if (empty($backfill)) return;

    $sets = [];
    $params = [];

    if (!empty($backfill['google_cid'])) {
        $sets[] = "google_cid = COALESCE(NULLIF(google_cid, ''), ?)";
        $params[] = $backfill['google_cid'];
    }
    if (!empty($backfill['place_id'])) {
        $sets[] = "place_id = COALESCE(NULLIF(place_id, ''), ?)";
        $params[] = $backfill['place_id'];
    }

    if (empty($sets)) return;

    $params[] = $locationId;
    $sql = 'UPDATE gbp_locations SET ' . implode(', ', $sets) . ' WHERE id = ?';

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() > 0) {
            error_log("{$callerContext}: BACKFILL location={$locationId} " . json_encode($backfill));
        }
    } catch (Exception $e) {
        error_log("{$callerContext}: backfill failed location={$locationId}: " . $e->getMessage());
    }
}

/**
 * Determiner si un item SERP est notre fiche cible.
 * Utilise uniquement place_id + CID (PAS de fuzzy) pour le flag is_target permanent.
 */
function determineIsTarget(array $item, array $locationRow): bool
{
    $ourPlaceId = $locationRow['place_id'] ?? '';
    $ourCid     = $locationRow['google_cid'] ?? '';

    // Tier 1 : place_id
    if ($ourPlaceId) {
        $itemPlaceId = extractPlaceIdFromItem($item);
        if ($itemPlaceId && $itemPlaceId === $ourPlaceId) return true;
    }

    // Tier 2 : CID
    if ($ourCid) {
        $itemCid = extractCidFromItem($item);
        if ($itemCid && $itemCid === $ourCid) return true;
    }

    return false;
}

// ============================================
// UTILITY HELPERS
// ============================================

function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Log une erreur dans app_errors ET dans error_log PHP.
 *
 * @param string $message     Message d'erreur lisible
 * @param string $errorType   scan_error|api_error|cron_error|db_error|auth_error|general
 * @param string $severity    critical|error|warning|info
 * @param array  $options     [source, action, user_id, location_id, keyword_id, stack, context]
 */
function logAppError(string $message, string $errorType = 'general', string $severity = 'error', array $options = []): void {
    $source = $options['source'] ?? 'unknown';
    error_log("[{$severity}][{$errorType}] {$source}: {$message}");

    try {
        $stmt = db()->prepare('
            INSERT INTO app_errors (error_date, user_id, location_id, keyword_id, action, error_type, severity, source, message, stack, context)
            VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $options['user_id'] ?? null,
            $options['location_id'] ?? null,
            $options['keyword_id'] ?? null,
            $options['action'] ?? null,
            $errorType,
            $severity,
            $source,
            mb_substr($message, 0, 2000),
            isset($options['stack']) ? mb_substr($options['stack'], 0, 5000) : null,
            isset($options['context']) ? json_encode($options['context'], JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Exception $e) {
        error_log("logAppError DB FAILED: " . $e->getMessage() . " | Original: {$message}");
    }
}

/**
 * Retourne un message utilisateur propre (jamais technique).
 */
function cleanErrorMessage(string $technicalError, string $context = ''): string {
    $contextLabels = [
        'scan'     => 'Un probleme technique est survenu lors de l\'analyse. L\'operation sera relancee automatiquement.',
        'stats'    => 'Un probleme technique est survenu lors de la synchronisation des statistiques.',
        'keywords' => 'Un probleme technique est survenu lors de la gestion des mots-cles. Veuillez reessayer.',
        'reviews'  => 'Un probleme technique est survenu lors du traitement des avis.',
        'api'      => 'Une anomalie a ete detectee lors de la communication avec un service externe.',
        'auth'     => 'Un incident d\'authentification est survenu. Veuillez vous reconnecter.',
        'default'  => 'Un incident technique est survenu. Notre equipe a ete notifiee.',
    ];
    return $contextLabels[$context] ?? $contextLabels['default'];
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function flashMessage(string $type, string $message): void {
    startSecureSession();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlashMessage(): ?array {
    startSecureSession();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

/**
 * Construit le prompt IA pour generer un post Google Business.
 * Reutilisable par posts.php (single) et post-lists.php (batch).
 */
function buildPostPrompt(int $locationId, string $subject, string $postType = 'STANDARD', string $batchCategory = ''): string {
    // Infos de la fiche
    $stmtLoc = db()->prepare('SELECT name, category FROM gbp_locations WHERE id = ?');
    $stmtLoc->execute([$locationId]);
    $locInfo = $stmtLoc->fetch(PDO::FETCH_ASSOC);
    $businessName = $locInfo['name'] ?? 'l\'entreprise';
    $category = $locInfo['category'] ?? '';

    // Parametres du profil IA
    $stmtSettings = db()->prepare('SELECT * FROM review_settings WHERE location_id = ?');
    $stmtSettings->execute([$locationId]);
    $profileSettings = $stmtSettings->fetch(PDO::FETCH_ASSOC);
    if (!$profileSettings) $profileSettings = [];

    $defaults = [
        'owner_name'         => '',
        'default_tone'       => 'professional',
        'gender'             => 'neutral',
        'custom_instructions'=> '',
        'review_signature'   => $businessName,
        'review_speech'      => 'vous',
        'posts_tone'         => 'professional',
        'posts_gender'       => 'neutral',
        'posts_signature'    => '',
        'posts_instructions' => "Redige de vrais article bien detailles et pense pour le SEO ! Commence par un titre qui fera office d'objet tu peux le mettre en majuscule faut qu'il soit court et accrocheur soit sous forme de question soit sous forme d'affirmation. Pas d'emoji !",
        'posts_speech'       => 'vous',
        'business_context'   => '',
    ];
    foreach ($defaults as $k => $v) {
        if (!isset($profileSettings[$k]) || $profileSettings[$k] === null || $profileSettings[$k] === '') {
            $profileSettings[$k] = $v;
        }
    }
    if (empty($profileSettings['posts_signature'])) {
        $profileSettings['posts_signature'] = $profileSettings['review_signature'];
    }

    $ownerName = $profileSettings['owner_name'];
    $tone = !empty($profileSettings['posts_tone']) ? $profileSettings['posts_tone'] : $profileSettings['default_tone'];
    $gender = !empty($profileSettings['posts_gender']) ? $profileSettings['posts_gender'] : $profileSettings['gender'];
    $customInstructions = !empty($profileSettings['posts_instructions']) ? $profileSettings['posts_instructions'] : $profileSettings['custom_instructions'];
    $signature = $profileSettings['posts_signature'];
    $speech = !empty($profileSettings['posts_speech']) ? $profileSettings['posts_speech'] : $profileSettings['review_speech'];
    $businessContext = $profileSettings['business_context'] ?? '';

    $typeDesc = match($postType) {
        'EVENT' => "un post Google de type EVENEMENT",
        'OFFER' => "un post Google de type OFFRE PROMOTIONNELLE",
        default => "un post Google standard (actualite/nouveaute)",
    };
    $toneDesc = match($tone) {
        'friendly' => 'amical et chaleureux, accessible et decontracte',
        'empathetic' => 'empathique et bienveillant, proche des clients',
        default => 'professionnel mais engageant et humain',
    };
    $genderDesc = match($gender) {
        'male' => "Redige a la premiere personne du singulier, en tant qu'homme" . ($ownerName ? " ({$ownerName})" : "") . ". Utilise la forme masculine (ex: \"je suis ravi de vous annoncer\").",
        'female' => "Redige a la premiere personne du singulier, en tant que femme" . ($ownerName ? " ({$ownerName})" : "") . ". Utilise la forme feminine (ex: \"je suis ravie de vous annoncer\").",
        default => "Redige au nom de l'entreprise \"{$businessName}\", a la premiere personne du pluriel (nous). Utilise des formules collectives (ex: \"nous sommes ravis de vous annoncer\").",
    };
    $identite = match($gender) {
        'neutral' => "Tu es un expert en marketing digital et en SEO local, tu rediges au nom de l'entreprise \"{$businessName}\"",
        default => "Tu es un expert en marketing digital et en SEO local, tu rediges pour " . ($ownerName ?: 'le gerant') . ", " . ($gender === 'female' ? 'gerante' : 'gerant') . " de \"{$businessName}\"",
    };
    $speechDesc = ($speech === 'tu')
        ? "TUTOIE le lecteur (utilise \"tu\", \"ton\", \"ta\", \"tes\", \"toi\"). Par exemple : \"Decouvre notre nouveau...\", \"Profite de...\", \"N'hesite pas a...\""
        : "VOUVOIE le lecteur (utilise \"vous\", \"votre\", \"vos\"). Par exemple : \"Decouvrez notre nouveau...\", \"Profitez de...\", \"N'hesitez pas a...\"";

    // Instructions specifiques selon la categorie batch
    $categoryBlock = '';
    if ($batchCategory === 'faq_ai') {
        $categoryBlock = "
=== FORMAT FAQ IA (PRIORITE HAUTE) ===

Ce post est une FAQ optimisee pour les moteurs de recherche IA (Google AI Overview, ChatGPT, Perplexity).

STRUCTURE OBLIGATOIRE :
1. TITRE = une QUESTION conversationnelle en MAJUSCULES (long-tail, naturelle, le genre de question qu'un utilisateur poserait a un assistant IA)
   Exemple : \"COMMENT CHOISIR UN BON PLOMBIER A LYON ?\" ou \"QUEL EST LE PRIX MOYEN D'UNE SERRURE 3 POINTS A MARSEILLE ?\"
2. Saute une ligne apres le titre
3. REPONSE = comprehensive, structuree, autoritaire. Tu es l'EXPERT qui repond definitivement a cette question.

STYLE FAQ IA :
- Commence TOUJOURS par la question en MAJUSCULES comme titre
- La reponse doit etre FACTUELLE et DEFINITIVE (pas d'hesitation, pas de \"ca depend\")
- Inclus des CHIFFRES concrets, des fourchettes de prix, des durees, des statistiques quand c'est pertinent
- Utilise un vocabulaire riche : synonymes, termes techniques du metier, termes associes
- Mentionne le contexte geographique (ville, quartier, region) si pertinent
- Structure la reponse avec des listes, des etapes numerotees ou des points cles
- Ecris en langage NATUREL et CONVERSATIONNEL (comme si tu expliquais a quelqu'un)
- L'objectif est d'etre CITE par les IA comme source de reference

LONGUEUR : 800 a 1200 caracteres (plus long qu'un post normal, c'est voulu)
- Ne mets PAS de hashtags
- Ne commence PAS par \"Bonjour\" ou formule de politesse

";
    } elseif ($batchCategory === 'articles') {
        $categoryBlock = "
=== FORMAT ARTICLE EXPERT ===

Ce post est un article/conseil d'expert classique pour Google Business Profile.

STYLE ARTICLE :
- Ton promotionnel, engageant, personnel — tu donnes des CONSEILS pratiques
- Commence par un TITRE court et accrocheur en MAJUSCULES (affirmation ou question courte)
- Puis rentre directement dans le vif du sujet avec des conseils concrets
- Inclus des astuces, des guides pratiques, du contenu saisonnier ou tendance
- Termine par un APPEL A L'ACTION fort (prise de RDV, appel, visite, devis gratuit...)
- Donne envie au lecteur de passer a l'action MAINTENANT

LONGUEUR : 300 a 600 caracteres (post concis et percutant)
- Ne mets PAS de hashtags
- Ne commence PAS par \"Bonjour\" ou formule de politesse

";
    }

    // Consignes techniques adaptees selon la categorie
    $charConsigne = match($batchCategory) {
        'faq_ai' => '- Longueur : 800 a 1200 caracteres (reponse complete et detaillee)',
        'articles' => '- Longueur : 300 a 600 caracteres (post concis et percutant)',
        default => '- Maximum 1000 caracteres (ideal: 300-500 caracteres)',
    };
    $titreConsigne = ($batchCategory === 'faq_ai' || $batchCategory === 'articles')
        ? '- Commence par un TITRE en MAJUSCULES, puis saute une ligne avant le contenu'
        : '- Ne mets pas de titre separe, ecris directement le contenu';

    $contextBlock = '';
    if (!empty($businessContext)) {
        $contextBlock = "
=== CONTEXTE METIER DE L'ENTREPRISE (informations reelles a utiliser) ===
{$businessContext}
=== FIN DU CONTEXTE METIER ===

IMPORTANT : Utilise UNIQUEMENT les informations ci-dessus pour parler de l'entreprise (tarifs, services, specialites, zone d'intervention, etc.). Ne JAMAIS inventer de tarifs, de services ou d'informations qui ne figurent pas dans ce contexte. Si une info n'est pas disponible, ne l'invente pas.
ATTENTION : Meme si un numero de telephone figure dans le contexte, ne l'inclus JAMAIS dans le post. Google supprime automatiquement les posts contenant des numeros de telephone.

";
    }

    // Date du jour pour contexte temporel et saisonnalite
    $aujourdhui = date('d/m/Y');
    $moisFr = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    $saisonMap = [12=>'hiver',1=>'hiver',2=>'hiver',3=>'printemps',4=>'printemps',5=>'printemps',6=>'été',7=>'été',8=>'été',9=>'automne',10=>'automne',11=>'automne'];
    $moisActuel = $moisFr[date('n') - 1];
    $anneeActuelle = date('Y');
    $saisonActuelle = $saisonMap[date('n')];

    return "{$identite}" . ($category ? " (categorie: {$category})" : "") . ". Tu dois rediger {$typeDesc}.

=== DATE DU JOUR (PRIORITE ABSOLUE) ===
Nous sommes le {$aujourdhui} ({$moisActuel} {$anneeActuelle}, saison : {$saisonActuelle}).
Tu DOIS etre a jour : ne mentionne JAMAIS une annee passee (2024, 2025...) ni une saison qui ne correspond pas a la date actuelle.
Adapte ton contenu a la saisonnalite ({$saisonActuelle} {$anneeActuelle}) : references saisonnieres, evenements du moment, actualites pertinentes.
Si tu cites une annee, ce doit etre {$anneeActuelle}.
=== FIN DATE ===

SUJET DU POST : {$subject}
" . ($customInstructions ? "\nINSTRUCTIONS DE STYLE : {$customInstructions}\n" : "") . "
{$contextBlock}{$categoryBlock}CONSIGNES TECHNIQUES :
- Redige en francais
{$charConsigne}
- Inclus un appel a l'action naturel dans le texte
- Ne mets pas de hashtags
{$titreConsigne}
- Sois specifique et pertinent pour le secteur d'activite
- Donne envie au lecteur de s'engager (visiter, appeler, reserver...)
- Rentre directement dans le vif du sujet, comme un article (pas de formule de politesse type \"Bonjour\" au debut)
- Ne mets JAMAIS de numero de telephone dans le post (Google les supprime automatiquement). Dis plutot \"contactez-nous\" ou \"appelez-nous\" sans donner le numero.

=== REGLES OBLIGATOIRES (a respecter imperativement, priorite maximale) ===

TON OBLIGATOIRE : {$toneDesc}. Adopte ce ton tout au long du post.

VOIX / GENRE OBLIGATOIRE :
{$genderDesc}

FORME D'ADRESSE OBLIGATOIRE :
{$speechDesc}

SIGNATURE OBLIGATOIRE :
Termine TOUJOURS le post par un retour a la ligne puis la signature exacte : \"{$signature}\"
Ne modifie pas la signature, recopie-la telle quelle.

=== FIN DES REGLES OBLIGATOIRES ===

Ecris UNIQUEMENT le contenu du post, sans guillemets ni explications.
IMPORTANT : N'utilise AUCUNE mise en forme markdown (pas de ** ni de * ni de # ni de tirets - pour les listes). Ecris en texte brut uniquement.";
}

/**
 * Nettoie le markdown d'un texte genere par l'IA (bold, italic, headers, listes)
 * Google Business Profile affiche le texte brut — le markdown apparait en asterisques.
 */
function stripMarkdown(string $text): string {
    // **bold** ou __bold__
    $text = preg_replace('/\*\*(.+?)\*\*/', '$1', $text);
    $text = preg_replace('/__(.+?)__/', '$1', $text);
    // *italic* ou _italic_
    $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '$1', $text);
    // # Headers
    $text = preg_replace('/^#{1,6}\s*/m', '', $text);
    // - ou * listes a puces → remplacer par simple tiret sans markdown
    $text = preg_replace('/^[\*\-]\s+/m', '- ', $text);
    // Backticks `code`
    $text = preg_replace('/`(.+?)`/', '$1', $text);
    // Liens [texte](url) → juste le texte
    $text = preg_replace('/\[(.+?)\]\(.+?\)/', '$1', $text);
    return trim($text);
}

/**
 * Genere un maillage radial de coordonnees GPS autour d'un centre.
 * Algorithme : anneaux concentriques hexagonaux avec progression geometrique.
 * Les anneaux sont plus denses au centre et plus espaces vers l'exterieur.
 * Le dernier anneau correspond exactement au rayon maximal choisi.
 *
 * Progression geometrique (ratio q=2.0) :
 *   Anneau i = maxRadius / q^(numRings - i)
 *   Ex pour maxRadius=15km, 3 anneaux : 3.75km, 7.5km, 15km
 *
 * @deprecated Utiliser generateGridPoints49() pour les nouveaux scans (sunburst 49 pts)
 * @param float $lat        Latitude du centre (fiche GBP)
 * @param float $lng        Longitude du centre
 * @param int   $numRings   Nombre d'anneaux (3 = 37 points)
 * @param float $maxRadius  Rayon maximal en km (dernier anneau = exactement cette valeur)
 * @return array Points avec row, col, ring, latitude, longitude, is_center
 */
function generateCircularGridCoordinates(float $lat, float $lng, int $numRings = 3, float $maxRadius = 15.0): array {
    $points = [];

    // Centre (ring 0) — position exacte de la fiche Google
    $points[] = [
        'row'       => 0,
        'col'       => 0,
        'ring'      => 0,
        'latitude'  => round($lat, 7),
        'longitude' => round($lng, 7),
        'is_center' => true,
    ];

    // Ratio de la progression geometrique
    // q=2.0 : chaque anneau est 2x plus loin que le precedent
    $geoRatio = 2.0;

    // Anneaux concentriques avec progression geometrique
    for ($ring = 1; $ring <= $numRings; $ring++) {
        // Plus le cercle est grand, plus il faut de points pour quadriller la zone
        $pointsInRing = $ring * 6;

        // Progression geometrique : anneau_i = maxRadius / q^(N - i)
        // Anneau 1 = maxRadius / q^(N-1) (le plus proche, dense)
        // Anneau N = maxRadius / q^0 = maxRadius (exactement le max)
        $currentRadius = $maxRadius / pow($geoRatio, $numRings - $ring);

        for ($i = 0; $i < $pointsInRing; $i++) {
            $angle = ($i * 360) / $pointsInRing;
            $angleRad = $angle * (M_PI / 180);

            // Calcul geometrique pour de courtes distances
            // 1 degre de latitude = environ 111.32 km
            $deltaLat = ($currentRadius * cos($angleRad)) / 111.32;
            $deltaLng = ($currentRadius * sin($angleRad)) / (111.32 * cos($lat * (M_PI / 180)));

            $points[] = [
                'row'       => $ring,
                'col'       => $i,
                'ring'      => $ring,
                'latitude'  => round($lat + $deltaLat, 7),
                'longitude' => round($lng + $deltaLng, 7),
                'is_center' => false,
            ];
        }
    }

    return $points;
}

/**
 * Génère une grille 7×7 fixe de 49 points GPS (style Localo).
 *
 * Structure :
 *   Grille carrée 7×7 = 49 points répartis uniformément sur toute la zone
 *   Rayon max = 15 km (diamètre 30 km)
 *   Espacement entre points = 30 / 6 = 5 km
 *   Alignement cardinal N/S/E/O (pas de rotation)
 *   Jitter déterministe ±60m pour casser l'effet trop parfait
 *
 * @param float $centerLat   Latitude du centre (fiche GBP)
 * @param float $centerLng   Longitude du centre
 * @param int   $locationId  ID de la fiche GBP (pour seed déterministe)
 * @param int   $keywordId   ID du mot-clé (pour seed déterministe)
 * @return array 49 points avec row, col, ring, latitude, longitude, is_center
 */
function generateGridPoints49(float $centerLat, float $centerLng, int $locationId, int $keywordId): array
{
    $points = [];
    $gridSize   = 7;       // 7×7 = 49 points
    $radiusKm   = 15.0;    // rayon max
    $halfGrid   = 3;       // floor(7/2) = 3
    $stepKm     = ($radiusKm * 2) / ($gridSize - 1); // 30/6 = 5.0 km

    // Seed deterministe pour le jitter uniquement
    $seedStr = $locationId . '_' . $keywordId;
    $seed    = abs(crc32($seedStr));
    $rng     = $seed;

    // Correction longitude par la latitude
    $cosLat = cos($centerLat * M_PI / 180.0);

    // Grille alignee N/S/E/O — pas de rotation
    for ($row = 0; $row < $gridSize; $row++) {
        for ($col = 0; $col < $gridSize; $col++) {
            $isCenter = ($row === $halfGrid && $col === $halfGrid);

            if ($isCenter) {
                $points[] = [
                    'row'       => $row,
                    'col'       => $col,
                    'ring'      => 0,
                    'latitude'  => round($centerLat, 7),
                    'longitude' => round($centerLng, 7),
                    'is_center' => true,
                ];
                continue;
            }

            // Position dans la grille en km (axes cardinaux)
            $dxKm = ($col - $halfGrid) * $stepKm; // Est(+) / Ouest(-)
            $dyKm = ($halfGrid - $row) * $stepKm;  // Nord(+) / Sud(-)

            // Jitter deterministe ±60m (±0.06 km)
            $rng = ($rng * 1103515245 + 12345) & 0x7FFFFFFF;
            $jLat = (($rng % 121) - 60) / 1000.0;
            $rng = ($rng * 1103515245 + 12345) & 0x7FFFFFFF;
            $jLng = (($rng % 121) - 60) / 1000.0;

            // Conversion km → degrés
            $deltaLat = ($dyKm + $jLat) / 111.32;
            $deltaLng = ($dxKm + $jLng) / (111.32 * $cosLat);

            // Ring : distance au centre en cases (1 = adjacent, 2 = peripherie)
            $dist = max(abs($row - $halfGrid), abs($col - $halfGrid));
            $ring = ($dist <= 1) ? 1 : 2;

            $points[] = [
                'row'       => $row,
                'col'       => $col,
                'ring'      => $ring,
                'latitude'  => round($centerLat + $deltaLat, 7),
                'longitude' => round($centerLng + $deltaLng, 7),
                'is_center' => false,
            ];
        }
    }

    return $points; // exactement 49 points
}


/**
 * Génère les coordonnées GPS pour une grille CARRÉE autour d'un point central
 * @deprecated Utiliser generateGridPoints49() pour les nouveaux scans
 */
function generateGridCoordinates(float $lat, float $lng, int $gridSize = 7, float $radiusKm = 2.0): array {
    $points = [];
    $halfGrid = floor($gridSize / 2);
    $stepKm = ($radiusKm * 2) / ($gridSize - 1);
    
    // 1 degré latitude ≈ 111.32 km
    // 1 degré longitude ≈ 111.32 * cos(latitude) km
    $latStep = $stepKm / 111.32;
    $lngStep = $stepKm / (111.32 * cos(deg2rad($lat)));
    
    for ($row = 0; $row < $gridSize; $row++) {
        for ($col = 0; $col < $gridSize; $col++) {
            $pointLat = $lat + ($halfGrid - $row) * $latStep;
            $pointLng = $lng + ($col - $halfGrid) * $lngStep;
            $points[] = [
                'row'       => $row,
                'col'       => $col,
                'latitude'  => round($pointLat, 7),
                'longitude' => round($pointLng, 7),
                'is_center' => ($row === $halfGrid && $col === $halfGrid),
            ];
        }
    }
    
    return $points;
}

// ============================================
// CREDIT MANAGEMENT
// ============================================

/**
 * Retourne les credits restants d'un utilisateur
 * Reset automatique mensuel a 100 credits
 */
function getUserCredits(int $userId): int {
    $stmt = db()->prepare('SELECT credits, credits_reset_at FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) return 0;

    // Reset mensuel auto
    $resetAt = $user['credits_reset_at'];
    if (!$resetAt || strtotime($resetAt) < strtotime('first day of this month midnight')) {
        $stmt = db()->prepare('UPDATE users SET credits = 100, credits_reset_at = NOW() WHERE id = ?');
        $stmt->execute([$userId]);
        return 100;
    }
    return (int)($user['credits'] ?? 100);
}

/**
 * Deduit des credits de maniere atomique
 * Retourne false si credits insuffisants
 */
function deductCredits(int $userId, int $amount): bool {
    $stmt = db()->prepare('UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?');
    $stmt->execute([$amount, $userId, $amount]);
    return $stmt->rowCount() > 0;
}

/**
 * Calcule un score de visibilite SEO (0-100)
 * position: 40%, avis: 20%, note: 20%, site: 10%, tel: 10%
 */
function calculateVisibilityScore(array $item, int $position): int {
    $score = 0;

    // Position (40 points max)
    if ($position >= 1 && $position <= 3) {
        $score += 40 - (($position - 1) * 5); // 40, 35, 30
    } elseif ($position <= 10) {
        $score += 30 - (($position - 3) * 3); // 27..9
    } elseif ($position <= 20) {
        $score += max(2, 10 - ($position - 10));
    }

    // Nombre d'avis (20 points max)
    $reviews = (int)($item['reviews_count'] ?? 0);
    if ($reviews >= 100) $score += 20;
    elseif ($reviews >= 50) $score += 16;
    elseif ($reviews >= 20) $score += 12;
    elseif ($reviews >= 10) $score += 8;
    elseif ($reviews >= 5) $score += 4;

    // Note moyenne (20 points max)
    $rating = (float)($item['rating'] ?? 0);
    if ($rating >= 4.5) $score += 20;
    elseif ($rating >= 4.0) $score += 16;
    elseif ($rating >= 3.5) $score += 12;
    elseif ($rating >= 3.0) $score += 8;
    elseif ($rating > 0) $score += 4;

    // Site web (10 points)
    if (!empty($item['domain']) || !empty($item['url'])) $score += 10;

    // Telephone (10 points)
    if (!empty($item['phone'])) $score += 10;

    return min(100, $score);
}

/**
 * Calculer le score d'audit prospect detaille (4 sections, /100).
 *
 * @param array $audit  Ligne de la table audits
 * @return array {visibility => {score, max, details}, reputation => ..., presence => ..., activity => ..., total => int}
 */
function calculateProspectAuditScore(array $audit): array {
    $breakdown = [
        'visibility' => ['score' => 0, 'max' => 35, 'details' => []],
        'reputation' => ['score' => 0, 'max' => 25, 'details' => []],
        'presence'   => ['score' => 0, 'max' => 25, 'details' => []],
        'activity'   => ['score' => 0, 'max' => 15, 'details' => []],
    ];

    // ====== VISIBILITE LOCALE (/35) ======
    // Scoring STRICT : seul le top 3 est "bon", position 4-7 = moyen, 8+ = mauvais
    $position = (int)($audit['position'] ?? 0);
    $gridVis  = (float)($audit['grid_visibility'] ?? 0);
    $gridTop3 = (int)($audit['grid_top3'] ?? 0);
    $gridTop10 = (int)($audit['grid_top10'] ?? 0);
    $totalPts = 49;

    // Rang Google Maps — scoring dur (0-15)
    // #1 = 15, #2 = 12, #3 = 10, #4 = 6, #5 = 4, #6-7 = 2, #8-10 = 1, #11+ = 0
    $posScore = 0;
    if ($position === 1) $posScore = 15;
    elseif ($position === 2) $posScore = 12;
    elseif ($position === 3) $posScore = 10;
    elseif ($position === 4) $posScore = 6;
    elseif ($position === 5) $posScore = 4;
    elseif ($position >= 6 && $position <= 7) $posScore = 2;
    elseif ($position >= 8 && $position <= 10) $posScore = 1;
    $breakdown['visibility']['details']['rang_google'] = $posScore;
    $breakdown['visibility']['details']['rang_value'] = $position;
    $breakdown['visibility']['score'] += $posScore;

    // Grid : % de points dans le top 3 (0-12)
    $top3Ratio = $totalPts > 0 ? $gridTop3 / $totalPts : 0;
    $gridTop3Score = 0;
    if ($top3Ratio >= 0.7) $gridTop3Score = 12;
    elseif ($top3Ratio >= 0.5) $gridTop3Score = 9;
    elseif ($top3Ratio >= 0.3) $gridTop3Score = 6;
    elseif ($top3Ratio >= 0.1) $gridTop3Score = 3;
    elseif ($top3Ratio > 0) $gridTop3Score = 1;
    $breakdown['visibility']['details']['grid_top3_score'] = $gridTop3Score;
    $breakdown['visibility']['details']['grid_top3_ratio'] = round($top3Ratio * 100);
    $breakdown['visibility']['score'] += $gridTop3Score;

    // Grid : couverture top 10 (0-8)
    $top10Ratio = $totalPts > 0 ? $gridTop10 / $totalPts : 0;
    $gridTop10Score = 0;
    if ($top10Ratio >= 0.8) $gridTop10Score = 8;
    elseif ($top10Ratio >= 0.6) $gridTop10Score = 6;
    elseif ($top10Ratio >= 0.4) $gridTop10Score = 4;
    elseif ($top10Ratio >= 0.2) $gridTop10Score = 2;
    elseif ($top10Ratio > 0) $gridTop10Score = 1;
    $breakdown['visibility']['details']['grid_top10_score'] = $gridTop10Score;
    $breakdown['visibility']['score'] += $gridTop10Score;

    // ====== E-RÉPUTATION (/25) ======
    $rating  = (float)($audit['rating'] ?? 0);
    $reviews = (int)($audit['reviews_count'] ?? 0);

    // Note moyenne — seuils exigeants (0-12)
    $ratingScore = 0;
    if ($rating >= 4.8) $ratingScore = 12;
    elseif ($rating >= 4.5) $ratingScore = 10;
    elseif ($rating >= 4.2) $ratingScore = 7;
    elseif ($rating >= 4.0) $ratingScore = 5;
    elseif ($rating >= 3.5) $ratingScore = 3;
    elseif ($rating > 0) $ratingScore = 1;
    $breakdown['reputation']['details']['rating'] = $ratingScore;
    $breakdown['reputation']['details']['rating_value'] = $rating;
    $breakdown['reputation']['score'] += $ratingScore;

    // Nombre d'avis — seuils par secteur (0-10)
    $reviewsScore = 0;
    if ($reviews >= 200) $reviewsScore = 10;
    elseif ($reviews >= 100) $reviewsScore = 7;
    elseif ($reviews >= 50) $reviewsScore = 5;
    elseif ($reviews >= 20) $reviewsScore = 3;
    elseif ($reviews >= 5) $reviewsScore = 1;
    $breakdown['reputation']['details']['reviews_count'] = $reviewsScore;
    $breakdown['reputation']['details']['reviews_value'] = $reviews;
    $breakdown['reputation']['score'] += $reviewsScore;

    // Qualité cumulée (0-3)
    $qualityScore = 0;
    if ($rating >= 4.5 && $reviews >= 100) $qualityScore = 3;
    elseif ($rating >= 4.3 && $reviews >= 50) $qualityScore = 2;
    elseif ($rating >= 4.0 && $reviews >= 20) $qualityScore = 1;
    $breakdown['reputation']['details']['quality'] = $qualityScore;
    $breakdown['reputation']['score'] += $qualityScore;

    // ====== PRÉSENCE DIGITALE (/25) ======
    // Analyse : site, téléphone, catégorie, titre GBP, photos, description, horaires, attributs
    $hasSite  = !empty($audit['domain']);
    $hasPhone = !empty($audit['prospect_phone']);
    $hasCat   = !empty($audit['category']);
    $title    = trim($audit['business_name'] ?? '');
    $searchKw = strtolower(trim($audit['search_keyword'] ?? ''));
    $city     = strtolower(trim($audit['city'] ?? ''));
    $cityShort = strtolower(trim(explode(',', $city)[0]));
    $totalPhotos = (int)($audit['total_photos'] ?? 0);
    $hasDescription = !empty($audit['description']);
    $hasHours = !empty($audit['has_hours']);
    $attributesCount = (int)($audit['attributes_count'] ?? 0);

    // Site web (0-3)
    $breakdown['presence']['details']['website'] = $hasSite ? 3 : 0;
    $breakdown['presence']['score'] += $hasSite ? 3 : 0;

    // Téléphone (0-2)
    $breakdown['presence']['details']['phone'] = $hasPhone ? 2 : 0;
    $breakdown['presence']['score'] += $hasPhone ? 2 : 0;

    // Catégorie (0-2)
    $breakdown['presence']['details']['category'] = $hasCat ? 2 : 0;
    $breakdown['presence']['score'] += $hasCat ? 2 : 0;

    // Photos (0-4) — Google valorise les fiches avec beaucoup de photos
    $photosScore = 0;
    if ($totalPhotos >= 30) $photosScore = 4;
    elseif ($totalPhotos >= 15) $photosScore = 3;
    elseif ($totalPhotos >= 5) $photosScore = 2;
    elseif ($totalPhotos >= 1) $photosScore = 1;
    $breakdown['presence']['details']['photos'] = $photosScore;
    $breakdown['presence']['details']['photos_count'] = $totalPhotos;
    $breakdown['presence']['score'] += $photosScore;

    // Description de la fiche (0-2)
    $descScore = $hasDescription ? 2 : 0;
    $breakdown['presence']['details']['description'] = $descScore;
    $breakdown['presence']['details']['has_description'] = $hasDescription;
    $breakdown['presence']['score'] += $descScore;

    // Horaires complets (0-4) — via Google Places API
    $hoursScore = $hasHours ? 4 : 0;
    $breakdown['presence']['details']['hours'] = $hoursScore;
    $breakdown['presence']['details']['has_hours'] = $hasHours;
    $breakdown['presence']['score'] += $hoursScore;

    // Attributs renseignés (0-4) — via Google Places API (accessibilité, services, paiement...)
    $attrScore = 0;
    if ($attributesCount >= 8) $attrScore = 4;
    elseif ($attributesCount >= 5) $attrScore = 3;
    elseif ($attributesCount >= 3) $attrScore = 2;
    elseif ($attributesCount >= 1) $attrScore = 1;
    $breakdown['presence']['details']['attributes'] = $attrScore;
    $breakdown['presence']['details']['attributes_count'] = $attributesCount;
    $breakdown['presence']['score'] += $attrScore;

    // Analyse du titre GBP (0-4) — regles Google strictes
    $titleScore = 4;
    $titleIssues = [];
    $titleLower = mb_strtolower($title);

    // Titre trop long (> 50 chars suspect, > 80 = spam)
    $titleLen = mb_strlen($title);
    if ($titleLen > 80) {
        $titleScore -= 2;
        $titleIssues[] = 'Titre trop long (' . $titleLen . ' car.) — possible keyword stuffing';
    } elseif ($titleLen > 50) {
        $titleScore -= 1;
        $titleIssues[] = 'Titre long (' . $titleLen . ' car.) — vérifier la conformité';
    }

    // Detection mot-cle dans le titre (ex: "plombier" dans le nom)
    if ($searchKw && mb_strlen($searchKw) >= 3 && mb_strpos($titleLower, $searchKw) !== false) {
        $titleScore -= 2;
        $titleIssues[] = 'Contient le mot-clé "' . $searchKw . '" — possible ajout SEO non conforme';
    }

    // Detection ville/localisation dans le titre
    if ($cityShort && mb_strlen($cityShort) >= 3 && mb_strpos($titleLower, $cityShort) !== false) {
        $titleScore -= 1;
        $titleIssues[] = 'Contient la localisation "' . ucfirst($cityShort) . '" — non conforme aux règles Google';
    }

    // Detection TOUT EN MAJUSCULES
    if ($title === mb_strtoupper($title) && $titleLen > 5) {
        $titleScore -= 1;
        $titleIssues[] = 'Titre en MAJUSCULES — non conforme aux conventions Google';
    }

    // Detection separateurs suspects (|, -, :) souvent signe de keyword stuffing
    if (preg_match('/[|:–—]/', $title)) {
        $titleScore -= 1;
        $titleIssues[] = 'Contient des séparateurs (|, :, —) suggérant un slogan ou des mots-clés ajoutés';
    }

    $titleScore = max(0, $titleScore);
    $breakdown['presence']['details']['title'] = $titleScore;
    $breakdown['presence']['details']['title_issues'] = $titleIssues;
    $breakdown['presence']['score'] += $titleScore;

    // ====== ACTIVITÉ (/15) ======
    // Photos + avis + note comme indicateurs d'activite
    $activityScore = 0;

    // Combo avis/note (0-10)
    $comboScore = 0;
    if ($reviews >= 100 && $rating >= 4.0) $comboScore = 10;
    elseif ($reviews >= 50 && $rating >= 3.5) $comboScore = 7;
    elseif ($reviews >= 20 && $rating >= 3.0) $comboScore = 4;
    elseif ($reviews >= 10) $comboScore = 2;
    elseif ($reviews >= 5) $comboScore = 1;
    $breakdown['activity']['details']['combo'] = $comboScore;
    $activityScore += $comboScore;

    // Volume de photos comme proxy d'activite (0-5)
    $photoActivity = 0;
    if ($totalPhotos >= 50) $photoActivity = 5;
    elseif ($totalPhotos >= 25) $photoActivity = 3;
    elseif ($totalPhotos >= 10) $photoActivity = 2;
    elseif ($totalPhotos >= 3) $photoActivity = 1;
    $breakdown['activity']['details']['photos_activity'] = $photoActivity;
    $activityScore += $photoActivity;

    $breakdown['activity']['score'] = min(15, $activityScore);

    // Total
    $total = $breakdown['visibility']['score']
           + $breakdown['reputation']['score']
           + $breakdown['presence']['score']
           + $breakdown['activity']['score'];

    $breakdown['total'] = min(100, $total);

    // ====== RECOMMANDATIONS (ton critique, orientation vente) ======
    $recommendations = [];

    // Visibilité
    if ($position > 3) {
        $nbAbove = $position - 1;
        $recommendations[] = "Rang #{$position} sur Google Maps : {$nbAbove} concurrent(s) apparaissent avant vous dans le Local Pack. Seules les positions 1 à 3 sont visibles sans clic supplémentaire.";
    }
    if ($gridTop3Score < 6) {
        $pctTop3 = round($top3Ratio * 100);
        $recommendations[] = "Seulement {$pctTop3}% de présence dans le top 3 local sur la zone géographique. Les prospects à plus de quelques km ne vous trouvent pas en premier.";
    }

    // E-réputation
    if ($rating < 4.5 && $rating > 0) {
        $recommendations[] = "Note de {$rating}/5 : les consommateurs privilégient les fiches avec 4.5+ étoiles. Chaque dixième de point impacte votre taux de clic.";
    }
    if ($reviews < 50) {
        $recommendations[] = "Seulement {$reviews} avis Google : insuffisant pour inspirer confiance. Objectif minimum : 50 avis. Déployer une stratégie de collecte (QR code, email post-visite, SMS).";
    }

    // Présence digitale
    if (!$hasSite) $recommendations[] = "Aucun site web détecté : un site professionnel est indispensable pour convertir les visiteurs et renforcer la crédibilité auprès de Google.";
    if (!$hasPhone) $recommendations[] = "Téléphone non renseigné sur la fiche Google : les prospects ne peuvent pas vous contacter directement depuis Maps.";

    // Photos
    if ($totalPhotos < 15) {
        $recommendations[] = "Seulement {$totalPhotos} photo(s) sur la fiche : les fiches avec 30+ photos obtiennent significativement plus de clics. Ajouter des photos professionnelles de l'extérieur, l'intérieur, l'équipe et les réalisations.";
    }

    // Description
    if (!$hasDescription) {
        $recommendations[] = "Aucune description rédigée sur la fiche Google Business Profile. La description permet de présenter votre activité et d'intégrer naturellement des mots-clés pertinents.";
    }

    // Horaires
    if (!$hasHours) {
        $recommendations[] = "Aucun horaire renseigné sur la fiche Google. Les horaires d'ouverture sont un critère majeur de confiance et de conversion : les internautes veulent savoir si vous êtes ouvert avant de se déplacer.";
    }

    // Attributs
    if ($attributesCount < 3) {
        $recommendations[] = "Seulement {$attributesCount} attribut(s) renseigné(s) sur la fiche (accessibilité, services, paiement…). Compléter les attributs améliore la pertinence de la fiche pour les recherches filtrées.";
    }

    // Titre GBP
    if (!empty($titleIssues)) {
        $recommendations[] = "Titre de la fiche non conforme aux guidelines Google : " . implode('. ', $titleIssues) . '. Risque de suspension de la fiche.';
    } elseif ($titleScore >= 3) {
        // Titre OK, pas de reco
    }

    // Activité
    if ($activityScore < 10) {
        $recommendations[] = "Publier régulièrement des Google Posts (actualités, offres, événements) et répondre à chaque avis client pour montrer une activité constante.";
    }

    $breakdown['recommendations'] = array_slice($recommendations, 0, 8);

    return $breakdown;
}

/**
 * Retourne les templates d'email disponibles pour l'envoi d'audit
 */
function getAuditEmailTemplates(): array {
    return [
        'prospection_froid' => [
            'label' => 'Prospection à froid',
            'description' => 'Premier contact — le prospect ne vous connaît pas',
            'body' => "Bonjour,\n\nJe suis Mathieu, expert en référencement Google Business Profile. Je me permets de vous contacter car j'ai réalisé un audit de visibilité de votre établissement {business_name}{city_text}.\n\nVotre score actuel est de {score}/100, ce qui signifie qu'il y a des axes d'amélioration concrets pour rendre votre fiche plus visible.\n\nMon objectif au quotidien, c'est justement de positionner mes clients dans le top 3 local sur Google, pour que ce soit eux que les internautes choisissent en premier.\n\nVous trouverez le rapport détaillé en pièce jointe. Et si vous souhaitez en savoir plus sur mes services, c'est par ici : www.boustacom.fr\n\nN'hésitez pas à me contacter si vous avez des questions.",
        ],
        'contact_existant' => [
            'label' => 'Contact existant',
            'description' => 'Suite à un échange — le prospect vous connaît déjà',
            'body' => "Bonjour,\n\nSuite à notre échange, je vous envoie comme convenu l'audit de visibilité en ligne de votre établissement {business_name}{city_text}.\n\nVotre score actuel est de {score}/100.\n\nLe rapport en pièce jointe détaille votre positionnement sur Google, votre e-réputation et votre présence digitale, avec des recommandations personnalisées.\n\nN'hésitez pas si vous avez des questions, je suis disponible pour en discuter.",
        ],
        'relance' => [
            'label' => 'Relance',
            'description' => 'Relance après un premier envoi sans réponse',
            'body' => "Bonjour,\n\nJe me permets de revenir vers vous suite à l'audit de visibilité que je vous avais transmis concernant {business_name}{city_text}.\n\nPour rappel, votre score actuel est de {score}/100, ce qui signifie qu'il existe des opportunités concrètes pour améliorer votre visibilité en ligne.\n\nLe rapport détaillé est de nouveau en pièce jointe. Je reste disponible pour échanger si vous le souhaitez.",
        ],
    ];
}

/**
 * Retourne la signature HTML email de BOUS'TACOM
 */
function getEmailSignatureHtml(): string {
    return '<div style="max-width: 600px; margin: auto;"><div style="font-family: \'Inter\', Arial, sans-serif; font-size: 14px; color: #0a1628; margin-bottom: 20px;"><span>À bientôt,</span></div><table cellpadding="0" cellspacing="0" style="font-family: \'Inter\', Arial, sans-serif; font-size: 14px; color: #0a1628;"><tbody><tr><td style="padding-right: 20px; vertical-align: top;"><img src="http://www.image-heberg.fr/files/17700417883784048688.png" alt="Mathieu Bouscaillou" style="height: 105px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);" width="105" height="105" border="0"></td><td style="vertical-align: top;"><table cellpadding="0" cellspacing="0" style="font-size: 14px; line-height: 22px; color: #0a1628;"><tbody><tr><td style="font-weight: bold; font-size: 18px; font-family: \'Anton\', Arial, sans-serif; color: #0a1628; letter-spacing: 0.5px; text-transform: uppercase;">Mathieu BOUSCAILLOU</td></tr><tr><td style="font-size: 14px; font-weight: 800; padding-bottom: 8px; font-family: \'Inter\', Arial, sans-serif;"><span style="color: #2563eb; font-weight: 800;">Expert fiche Google Business Profile</span></td></tr><tr><td style="font-family: \'Inter\', Arial, sans-serif;">06 72 01 68 13</td></tr><tr><td style="font-family: \'Inter\', Arial, sans-serif;"><a href="mailto:contact@boustacom.fr" style="color: #0a1628; text-decoration: none;">contact@boustacom.fr</a></td></tr><tr><td style="font-family: \'Inter\', Arial, sans-serif;"><a href="https://www.boustacom.fr" style="font-weight: 800; text-decoration: none; color: #2563eb;" target="_blank">www.boustacom.fr</a></td></tr><tr><td style="font-family: \'Inter\', Arial, sans-serif; color: #64748b; padding-top: 4px;">19100, Brive-la-Gaillarde</td></tr></tbody></table></td><td style="padding-left: 20px; vertical-align: middle;"><a href="https://credsverse.com/credentials/a0785971-e207-4a1e-8beb-2d90dc2f139b?preview=1" style="text-decoration: none;" target="_blank"><img src="http://www.image-heberg.fr/files/17576833074120554629.png" alt="Badge Localo SEO" style="height: 95px; border: none;" width="95" height="95" border="0"></a></td></tr><tr><td colspan="3" style="padding-top: 20px; font-size: 12px; color: #64748b; padding-bottom: 8px; font-family: \'Inter\', Arial, sans-serif;">Suivez-moi sur les réseaux &#128071;</td></tr><tr><td colspan="3" style="padding-top: 5px;"><a href="https://f.mtr.cool/NOGNYEABXK" style="margin-right: 12px; text-decoration: none;" target="_blank"><img src="https://cdn-icons-png.flaticon.com/512/174/174855.png" alt="Instagram" width="24" height="24" style="vertical-align: middle;" border="0"></a> <a href="https://f.mtr.cool/GGLMHQRESI" style="margin-right: 12px; text-decoration: none;" target="_blank"><img src="https://cdn-icons-png.flaticon.com/512/174/174857.png" alt="LinkedIn" width="24" height="24" style="vertical-align: middle;" border="0"></a> <a href="https://wa.me/33972981864" style="text-decoration: none;" target="_blank"><img src="https://cdn-icons-png.flaticon.com/512/733/733585.png" alt="WhatsApp" width="24" height="24" style="vertical-align: middle;" border="0"></a></td></tr></tbody></table></div><div style="max-width: 600px; margin: auto;"><table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #0a1628; border-radius: 0 0 8px 8px; margin-top: 20px;"><tbody><tr><td align="center" style="padding: 10px 0;"><img src="https://boustacom.fr/images/logo-en-tete-footer.png" alt="Logo BOUS\'TACOM" width="110" height="auto" style="display: block; margin: 0 auto;" border="0"></td></tr></tbody></table></div>';
}

/**
 * Envoyer un audit prospect par email avec PDF en piece jointe.
 *
 * @param array $audit        Ligne audits
 * @param string $email       Email du destinataire
 * @param string $pdfPath     Chemin absolu du PDF
 * @param array $user         Utilisateur qui envoie
 * @param string $templateKey Cle du template email
 * @return bool
 */
function sendAuditEmail(array $audit, string $email, string $pdfPath, array $user, string $templateKey = 'prospection_froid'): bool {
    $businessName = $audit['business_name'] ?? 'Votre entreprise';
    $city = $audit['city'] ?? '';
    $score = (int)($audit['score'] ?? 0);

    // Couleur score
    $scoreColor = $score >= 70 ? '#22c55e' : ($score >= 40 ? '#f59e0b' : '#ef4444');

    // Charger le template
    $templates = getAuditEmailTemplates();
    $tpl = $templates[$templateKey] ?? $templates['prospection_froid'];

    // Remplacer les variables du template
    $cityText = $city ? ' à ' . $city : '';
    $bodyText = str_replace(
        ['{business_name}', '{city_text}', '{score}', '{city}'],
        [$businessName, $cityText, $score, $city],
        $tpl['body']
    );

    // Convertir les sauts de ligne en paragraphes HTML
    $bodyHtml = '';
    foreach (explode("\n\n", $bodyText) as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph !== '') {
            $bodyHtml .= '<p style="font-size:14px;color:#0a1628;line-height:1.8;margin:0 0 16px;">' . nl2br(htmlspecialchars($paragraph, ENT_QUOTES, 'UTF-8')) . '</p>';
        }
    }

    $subject = "Audit SEO Local \xe2\x80\x94 {$businessName}" . ($city ? " ({$city})" : '');

    // Signature HTML
    $signature = getEmailSignatureHtml();

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:\'Inter\',Arial,sans-serif;background:#f4f5f7;padding:20px;margin:0;color:#0a1628;">
    <div style="max-width:600px;margin:0 auto;">
        <div style="background:#0a1628;padding:18px 28px;border-radius:12px 12px 0 0;">
            <h1 style="margin:0;font-size:20px;color:#ffffff;font-weight:700;letter-spacing:-0.5px;">NEURA</h1>
            <p style="margin:4px 0 0;font-size:11px;color:rgba(255,255,255,.45);">Audit de visibilité SEO local</p>
        </div>
        <div style="background:#ffffff;padding:32px 28px;border-left:1px solid #e5e7eb;border-right:1px solid #e5e7eb;">
            ' . $bodyHtml . '
            <div style="text-align:center;margin:24px 0;">
                <div style="display:inline-block;background:#f8fafc;border:1px solid #e5e7eb;border-radius:16px;padding:20px 40px;">
                    <div style="font-size:42px;font-weight:700;color:' . $scoreColor . ';letter-spacing:-2px;">' . $score . '<span style="font-size:18px;color:#94a3b8;">/100</span></div>
                    <div style="font-size:11px;color:#94a3b8;margin-top:4px;text-transform:uppercase;letter-spacing:1px;">Score de visibilité</div>
                </div>
            </div>
            <p style="font-size:13px;color:#64748b;line-height:1.7;margin:20px 0 28px;padding:12px 16px;background:#f8fafc;border-radius:8px;border-left:3px solid #0a1628;">
                &#128206; Le rapport détaillé est en pièce jointe de cet email (PDF).
            </p>
            ' . $signature . '
        </div>
        <div style="padding:14px 28px;background:#f8fafc;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 12px 12px;font-size:10px;color:#94a3b8;text-align:center;">
            Rapport généré par Neura &mdash; une solution développée par BOUS\'TACOM
        </div>
    </div>
    </body></html>';

    $result = sendEmail($email, $businessName, $subject, $html, $pdfPath, 'contact@boustacom.fr');
    return $result['success'] ?? false;
}


// ============================================
// POST VISUALS — Moteur de génération Imagick
// ============================================

/**
 * Slugify une chaîne pour URLs SEO-friendly
 */
function slugify(string $text): string {
    $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

/**
 * Résout le chemin d'une police TTF
 */
function resolveFontPath(string $fontName): string {
    $map = [
        'montserrat'       => 'Montserrat-Variable.ttf',
        'montserrat-bold'  => 'Montserrat-Variable.ttf',
        'inter'            => 'Inter-Variable.ttf',
        'inter-regular'    => 'Inter-Variable.ttf',
        'playfair'         => 'PlayfairDisplay-Variable.ttf',
        'playfair-bold'    => 'PlayfairDisplay-Variable.ttf',
        'space-mono'       => 'SpaceMono-Bold.ttf',
        'space-mono-bold'  => 'SpaceMono-Bold.ttf',
        'anton'            => 'Anton-Regular.ttf',
        'raleway'          => 'Raleway-Variable.ttf',
        'poppins'          => 'Poppins-Regular.ttf',
        'poppins-regular'  => 'Poppins-Regular.ttf',
        'poppins-bold'     => 'Poppins-Bold.ttf',
    ];
    $file = $map[strtolower($fontName)] ?? $map['montserrat'];
    return FONTS_PATH . '/' . $file;
}

/**
 * Auto-size : réduit la taille de police jusqu'à ce que le texte tienne dans maxWidth × maxHeight
 */
function autoSizeText(Imagick $canvas, string $text, string $fontPath, int $startSize, int $maxWidth, int $maxHeight = 0, float $lineHeight = 1.3): array {
    $fontSize = $startSize;
    $minSize = 14;

    while ($fontSize >= $minSize) {
        $lines = wrapText($canvas, $text, $fontPath, $fontSize, $maxWidth);
        $totalH = count($lines) * ($fontSize * $lineHeight);

        if ($maxHeight > 0 && $totalH > $maxHeight) {
            $fontSize -= 2;
            continue;
        }

        // Vérifier que chaque ligne tient dans maxWidth
        $allFit = true;
        $draw = new ImagickDraw();
        $draw->setFont($fontPath);
        $draw->setFontSize($fontSize);
        foreach ($lines as $line) {
            $metrics = $canvas->queryFontMetrics($draw, $line);
            if ($metrics['textWidth'] > $maxWidth) {
                $allFit = false;
                break;
            }
        }

        if ($allFit) {
            return ['fontSize' => $fontSize, 'lines' => $lines, 'totalHeight' => $totalH];
        }
        $fontSize -= 2;
    }

    // Fallback : taille minimum
    $lines = wrapText($canvas, $text, $fontPath, $minSize, $maxWidth);
    $totalH = count($lines) * ($minSize * $lineHeight);
    return ['fontSize' => $minSize, 'lines' => $lines, 'totalHeight' => $totalH];
}

/**
 * Word-wrap : découpe le texte en lignes selon la largeur max
 */
function wrapText(Imagick $canvas, string $text, string $fontPath, int $fontSize, int $maxWidth): array {
    $draw = new ImagickDraw();
    $draw->setFont($fontPath);
    $draw->setFontSize($fontSize);

    $words = explode(' ', $text);
    $lines = [];
    $currentLine = '';

    foreach ($words as $word) {
        $testLine = $currentLine === '' ? $word : $currentLine . ' ' . $word;
        $metrics = $canvas->queryFontMetrics($draw, $testLine);

        if ($metrics['textWidth'] > $maxWidth && $currentLine !== '') {
            $lines[] = $currentLine;
            $currentLine = $word;
        } else {
            $currentLine = $testLine;
        }
    }
    if ($currentLine !== '') {
        $lines[] = $currentLine;
    }

    return $lines;
}

/**
 * Rend un calque texte sur le canvas
 */
function renderTextLayer(Imagick $canvas, array $layer, string $text): void {
    if (empty($text)) return;

    $fontPath = resolveFontPath($layer['font'] ?? 'montserrat');
    $maxW = $layer['maxWidth'] ?? ($canvas->getImageWidth() - ($layer['x'] ?? 0) * 2);
    $maxH = $layer['maxHeight'] ?? 0;
    $startSize = $layer['size'] ?? 48;
    $lineH = $layer['lineHeight'] ?? 1.3;
    $color = $layer['color'] ?? '#FFFFFF';
    $align = $layer['align'] ?? 'left';
    $uppercase = $layer['uppercase'] ?? false;

    if ($uppercase) $text = mb_strtoupper($text);

    $sized = autoSizeText($canvas, $text, $fontPath, $startSize, $maxW, $maxH, $lineH);

    $draw = new ImagickDraw();
    $draw->setFont($fontPath);
    $draw->setFontSize($sized['fontSize']);
    $draw->setFillColor(new ImagickPixel($color));
    $draw->setTextEncoding('UTF-8');

    // Centrage vertical : si le texte est plus court que maxHeight, on le centre dans la zone
    $totalTextH = count($sized['lines']) * ($sized['fontSize'] * $lineH);
    $yOffset = 0;
    if ($maxH > 0 && $totalTextH < $maxH) {
        $yOffset = (int)(($maxH - $totalTextH) / 2);
    }

    // Ombre portée
    if (!empty($layer['shadow'])) {
        $shadowDraw = clone $draw;
        $shadowColor = $layer['shadow']['color'] ?? '#00000088';
        $shadowDraw->setFillColor(new ImagickPixel($shadowColor));
        $ox = $layer['shadow']['offsetX'] ?? 2;
        $oy = $layer['shadow']['offsetY'] ?? 2;

        $sy = ($layer['y'] ?? 0) + $yOffset;
        foreach ($sized['lines'] as $line) {
            $lineX = calcTextX($canvas, $draw, $line, $layer['x'] ?? 0, $maxW, $align);
            $canvas->annotateImage($shadowDraw, $lineX + $ox, $sy + $sized['fontSize'] + $oy, 0, $line);
            $sy += $sized['fontSize'] * $lineH;
        }
    }

    // Texte principal
    $y = ($layer['y'] ?? 0) + $yOffset;
    foreach ($sized['lines'] as $line) {
        $lineX = calcTextX($canvas, $draw, $line, $layer['x'] ?? 0, $maxW, $align);
        $canvas->annotateImage($draw, $lineX, $y + $sized['fontSize'], 0, $line);
        $y += $sized['fontSize'] * $lineH;
    }
}

/**
 * Calcule la position X d'une ligne selon l'alignement
 */
function calcTextX(Imagick $canvas, ImagickDraw $draw, string $text, int $baseX, int $maxWidth, string $align): int {
    if ($align === 'left') return $baseX;

    $metrics = $canvas->queryFontMetrics($draw, $text);
    $textW = $metrics['textWidth'];

    if ($align === 'center') {
        return $baseX + (int)(($maxWidth - $textW) / 2);
    }
    if ($align === 'right') {
        return $baseX + (int)($maxWidth - $textW);
    }
    return $baseX;
}

/**
 * Rend un calque image (logo client, icône, etc.)
 */
function renderImageLayer(Imagick $canvas, array $layer, string $imagePath): void {
    if (empty($imagePath) || !file_exists($imagePath)) return;

    $img = new Imagick($imagePath);
    $maxW = $layer['maxWidth'] ?? 200;
    $maxH = $layer['maxHeight'] ?? 200;

    $img->thumbnailImage($maxW, $maxH, true);

    $x = $layer['x'] ?? 0;
    $y = $layer['y'] ?? 0;

    // Centrage dans la zone si demandé
    if (($layer['align'] ?? '') === 'center') {
        $x = $x + (int)(($maxW - $img->getImageWidth()) / 2);
    }

    $canvas->compositeImage($img, Imagick::COMPOSITE_OVER, $x, $y);
    $img->clear();
    $img->destroy();
}

/**
 * Rend un calque rectangle (overlay, barre, etc.)
 */
function renderRectLayer(Imagick $canvas, array $layer): void {
    $w = $layer['width'] ?? $canvas->getImageWidth();
    $h = $layer['height'] ?? 100;
    $color = $layer['color'] ?? '#000000';
    $opacity = $layer['opacity'] ?? 1.0;
    $x = $layer['x'] ?? 0;
    $y = $layer['y'] ?? 0;
    $radius = $layer['radius'] ?? 0;

    $rect = new Imagick();
    $rect->newImage($w, $h, new ImagickPixel('transparent'));
    $rect->setImageFormat('png');

    $draw = new ImagickDraw();
    $draw->setFillColor(new ImagickPixel($color));

    if ($radius > 0) {
        $draw->roundRectangle(0, 0, $w - 1, $h - 1, $radius, $radius);
    } else {
        $draw->rectangle(0, 0, $w - 1, $h - 1);
    }
    $rect->drawImage($draw);

    if ($opacity < 1.0) {
        $rect->evaluateImage(Imagick::EVALUATE_MULTIPLY, $opacity, Imagick::CHANNEL_ALPHA);
    }

    $canvas->compositeImage($rect, Imagick::COMPOSITE_OVER, $x, $y);
    $rect->clear();
    $rect->destroy();
}

/**
 * Place le logo client centré en haut du visuel
 * Taille auto : max 180px de large, 120px de haut, avec marge top de 40px
 */
function renderLogoOnVisual(Imagick $canvas, string $logoPath, int $canvasW, int $canvasH): void {
    if (!file_exists($logoPath)) return;

    try {
        $logo = new Imagick($logoPath);

        // Gérer la transparence PNG
        if ($logo->getImageAlphaChannel()) {
            $logo->setImageBackgroundColor(new ImagickPixel('transparent'));
        }

        // Taille max du logo
        $maxW = 180;
        $maxH = 120;
        $logo->thumbnailImage($maxW, $maxH, true);

        // Centrer horizontalement, placer en haut avec marge
        $logoW = $logo->getImageWidth();
        $logoH = $logo->getImageHeight();
        $x = (int)(($canvasW - $logoW) / 2);
        $y = 40;

        $canvas->compositeImage($logo, Imagick::COMPOSITE_OVER, $x, $y);
        $logo->clear();
        $logo->destroy();
    } catch (Exception $e) {
        error_log("renderLogoOnVisual error: " . $e->getMessage());
    }
}

/**
 * Rend la bande de signature en bas du visuel
 * Bande semi-transparente noire avec texte blanc discret
 */
function renderSignatureLayer(Imagick $canvas, string $text, int $canvasW, int $canvasH): void {
    if (empty($text)) return;

    try {
        $barH = 32;
        $fontSize = 9;
        $fontPath = resolveFontPath('inter');

        // Bande semi-transparente noire en bas
        $bar = new Imagick();
        $bar->newImage($canvasW, $barH, new ImagickPixel('transparent'));
        $bar->setImageFormat('png');

        $drawBar = new ImagickDraw();
        $drawBar->setFillColor(new ImagickPixel('rgba(0,0,0,0.55)'));
        $drawBar->rectangle(0, 0, $canvasW, $barH);
        $bar->drawImage($drawBar);

        $canvas->compositeImage($bar, Imagick::COMPOSITE_OVER, 0, $canvasH - $barH);
        $bar->clear();
        $bar->destroy();

        // Texte centré dans la bande
        $draw = new ImagickDraw();
        $draw->setFont($fontPath);
        $draw->setFontSize($fontSize);
        $draw->setFillColor(new ImagickPixel('rgba(255,255,255,0.75)'));
        $draw->setTextEncoding('UTF-8');

        // Mesurer le texte pour centrer
        $metrics = $canvas->queryFontMetrics($draw, $text);
        $textW = $metrics['textWidth'];
        $textX = (int)(($canvasW - $textW) / 2);
        $textY = $canvasH - $barH + (int)(($barH + $fontSize) / 2) - 1;

        $canvas->annotateImage($draw, $textX, $textY, 0, $text);

    } catch (Exception $e) {
        error_log("renderSignatureLayer error: " . $e->getMessage());
    }
}

/**
 * Résout les variables dynamiques dans un template
 */
function resolveTemplateVariable(string $variable, array $imageData, array $locationData = []): string {
    switch ($variable) {
        case 'visual_text':
            return $imageData['visual_text'] ?? '';
        case 'description':
            return $imageData['description'] ?? '';
        case 'cta_text':
            return $imageData['cta_text'] ?? '';
        case 'client_name':
            return $locationData['name'] ?? '';
        case 'city':
            return $locationData['city'] ?? '';
        case 'category':
            return $locationData['category'] ?? '';
        case 'phone':
            return $locationData['phone'] ?? '';
        case 'website':
            return $locationData['website'] ?? '';
        default:
            // Variables custom dans le JSON
            $vars = json_decode($imageData['variables'] ?? '{}', true) ?: [];
            return $vars[$variable] ?? '';
    }
}

/**
 * Construit le chemin SEO pour une image générée
 */
function buildImageSeoPath(array $locationData, array $imageData, string $keyword = ''): string {
    $clientSlug = slugify($locationData['name'] ?? 'client');
    $id = $imageData['id'] ?? time();

    // Priorité : seo_keyword de l'image > keyword passé en param
    $seoKw = !empty($imageData['seo_keyword']) ? $imageData['seo_keyword'] : $keyword;

    if ($seoKw) {
        // Dossier = marque / mot-clé (la ville est incluse manuellement dans le mot-clé)
        $kwSlug = slugify($seoKw);
        return "{$clientSlug}/{$kwSlug}/{$id}.jpg";
    }

    // Fallback sans mot-clé : marque / texte-visuel-id
    $textSlug = slugify(mb_substr($imageData['visual_text'] ?? 'post', 0, 50));
    return "{$clientSlug}/{$textSlug}-{$id}.jpg";
}

/**
 * MOTEUR PRINCIPAL — Génère une image à partir d'un template + données
 *
 * @param int $imageId    ID dans post_images
 * @param bool $preview   Si true, génère en basse résolution (600x450)
 * @return array          ['success' => bool, 'path' => string, 'url' => string, 'size' => int]
 */
function generatePostVisual(int $imageId, bool $preview = false): array {
    try {
        // 1. Charger les données
        $stmt = db()->prepare("SELECT * FROM post_images WHERE id = ?");
        $stmt->execute([$imageId]);
        $img = $stmt->fetch();
        if (!$img) return ['success' => false, 'error' => 'Image non trouvée'];

        $stmt = db()->prepare("SELECT * FROM post_templates WHERE id = ?");
        $stmt->execute([$img['template_id']]);
        $tpl = $stmt->fetch();
        if (!$tpl) return ['success' => false, 'error' => 'Template non trouvé'];

        $stmt = db()->prepare("SELECT * FROM gbp_locations WHERE id = ?");
        $stmt->execute([$img['location_id']]);
        $location = $stmt->fetch() ?: [];

        $config = json_decode($tpl['config'], true);
        if (!$config) return ['success' => false, 'error' => 'Config template invalide'];

        // Récupérer les overrides depuis variables JSON
        $vars = json_decode($img['variables'] ?? '{}', true) ?: [];
        $customBg = $vars['bg_color'] ?? null;
        $customTextColor = $vars['text_color'] ?? null;
        $customFont = $vars['font'] ?? null;
        $customDecoColor = $vars['deco_color'] ?? null;

        $w = $tpl['width'];
        $h = $tpl['height'];

        // 2. Canvas — couleur de fond custom ou template
        $canvas = new Imagick();
        $bgColor = $customBg ?: ($config['background']['color'] ?? '#1a1a2e');
        $canvas->newImage($w, $h, new ImagickPixel($bgColor));
        $canvas->setImageFormat('jpeg');

        // 3. Background image (seulement si pas de couleur custom)
        if (!$customBg && !empty($config['background']['image'])) {
            $bgPath = ROOT_PATH . '/' . $config['background']['image'];
            if (file_exists($bgPath)) {
                $bg = new Imagick($bgPath);
                $bg->resizeImage($w, $h, Imagick::FILTER_LANCZOS, 1, false);
                $canvas->compositeImage($bg, Imagick::COMPOSITE_OVER, 0, 0);
                $bg->clear();
                $bg->destroy();
            }
        }

        // 4. Gradient overlay
        if (!empty($config['gradient'])) {
            $g = $config['gradient'];
            $gH = (int)(($g['heightPercent'] ?? 50) / 100 * $h);
            $gY = $h - $gH;

            $gradient = new Imagick();
            $gradient->newPseudoImage($w, $gH, "gradient:transparent-" . ($g['color'] ?? '#000000'));
            $gradient->setImageFormat('png');

            if (($g['opacity'] ?? 1.0) < 1.0) {
                $gradient->evaluateImage(Imagick::EVALUATE_MULTIPLY, $g['opacity'], Imagick::CHANNEL_ALPHA);
            }
            $canvas->compositeImage($gradient, Imagick::COMPOSITE_OVER, 0, $gY);
            $gradient->clear();
            $gradient->destroy();
        }

        // 5. Overlays rectangulaires (avec override couleur déco)
        if (!empty($config['overlays'])) {
            foreach ($config['overlays'] as $ov) {
                if ($customDecoColor) $ov['color'] = $customDecoColor;
                renderRectLayer($canvas, $ov);
            }
        }

        // 6. Logo client (si disponible — au-dessus des overlays, avant le texte)
        if (!empty($location['logo_path'])) {
            $logoFullPath = MEDIA_PATH . '/logos/' . $location['logo_path'];
            if (file_exists($logoFullPath)) {
                renderLogoOnVisual($canvas, $logoFullPath, $w, $h);
            }
        }

        // 7. Layers (texte, images, rectangles)
        if (!empty($config['layers'])) {
            foreach ($config['layers'] as $layer) {
                switch ($layer['type'] ?? '') {
                    case 'text':
                        $varName = $layer['variable'] ?? 'visual_text';
                        $text = resolveTemplateVariable($varName, $img, $location);
                        if (!empty($text)) {
                            if ($customTextColor) $layer['color'] = $customTextColor;
                            if ($customFont) $layer['font'] = $customFont;
                            renderTextLayer($canvas, $layer, $text);
                        }
                        break;

                    case 'dynamic_image':
                        $source = $layer['source'] ?? '';
                        if ($source === 'client_logo' && !empty($location['logo_path'])) {
                            $logoFullPath = MEDIA_PATH . '/logos/' . $location['logo_path'];
                            if (file_exists($logoFullPath)) {
                                renderImageLayer($canvas, $layer, $logoFullPath);
                            }
                        }
                        break;

                    case 'rectangle':
                        if ($customDecoColor) $layer['color'] = $customDecoColor;
                        renderRectLayer($canvas, $layer);
                        break;
                }
            }
        }

        // 7b. Signature en bas (si activée pour ce client)
        $sigEnabled = (int)($location['signature_enabled'] ?? 1);
        if ($sigEnabled) {
            $defaultSig = 'Gérée par Neura · BOUS\'TACOM — Expert Google Business · boustacom.fr';
            $sigText = !empty($location['signature_text']) ? $location['signature_text'] : $defaultSig;
            renderSignatureLayer($canvas, $sigText, $w, $h);
        }

        // 8. Compression & export
        $canvas->setImageCompressionQuality($preview ? 70 : 85);
        $canvas->stripImage();

        if ($preview) {
            $canvas->resizeImage(600, 450, Imagick::FILTER_LANCZOS, 1);
        }

        // 8. Chemin SEO
        $seoPath = buildImageSeoPath($location, $img);
        $fullDir = MEDIA_PATH . '/' . dirname($seoPath);
        if (!is_dir($fullDir)) {
            mkdir($fullDir, 0755, true);
        }

        $suffix = $preview ? '-preview' : '';
        $finalPath = preg_replace('/\.jpg$/', $suffix . '.jpg', $seoPath);
        $fullPath = MEDIA_PATH . '/' . $finalPath;

        $canvas->writeImage($fullPath);
        $fileSize = filesize($fullPath);

        $canvas->clear();
        $canvas->destroy();

        // 9. Mise à jour BDD
        $url = MEDIA_URL . '/' . $finalPath;
        if (!$preview) {
            $stmt = db()->prepare("
                UPDATE post_images
                SET file_path = ?, file_url = ?, file_size = ?,
                    status = 'generated', generated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$finalPath, $url, $fileSize, $imageId]);
        }

        return [
            'success' => true,
            'path' => $finalPath,
            'url' => $url,
            'size' => $fileSize
        ];

    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Génère un preview rapide en mémoire (retourne le blob JPEG sans écrire sur disque)
 * Utilisé pour la prévisualisation temps réel dans l'UI
 */
function generatePostVisualPreview(int $templateId, string $visualText, string $ctaText = '', int $locationId = 0, string $bgColorOverride = '', string $textColorOverride = '', string $fontOverride = '', string $decoColorOverride = ''): string {
    try {
        $stmt = db()->prepare("SELECT * FROM post_templates WHERE id = ?");
        $stmt->execute([$templateId]);
        $tpl = $stmt->fetch();
        if (!$tpl) return '';

        $location = [];
        if ($locationId) {
            $stmt = db()->prepare("SELECT * FROM gbp_locations WHERE id = ?");
            $stmt->execute([$locationId]);
            $location = $stmt->fetch() ?: [];
        }

        $config = json_decode($tpl['config'], true);
        if (!$config) return '';

        $w = $tpl['width'];
        $h = $tpl['height'];

        // Canvas + background (override couleur si fourni)
        $canvas = new Imagick();
        $bgColor = $bgColorOverride ?: ($config['background']['color'] ?? '#1a1a2e');
        $canvas->newImage($w, $h, new ImagickPixel($bgColor));
        $canvas->setImageFormat('jpeg');

        if (!$bgColorOverride && !empty($config['background']['image'])) {
            $bgPath = ROOT_PATH . '/' . $config['background']['image'];
            if (file_exists($bgPath)) {
                $bg = new Imagick($bgPath);
                $bg->resizeImage($w, $h, Imagick::FILTER_LANCZOS, 1, false);
                $canvas->compositeImage($bg, Imagick::COMPOSITE_OVER, 0, 0);
                $bg->clear();
                $bg->destroy();
            }
        }

        // Gradient
        if (!empty($config['gradient'])) {
            $g = $config['gradient'];
            $gH = (int)(($g['heightPercent'] ?? 50) / 100 * $h);
            $gradient = new Imagick();
            $gradient->newPseudoImage($w, $gH, "gradient:transparent-" . ($g['color'] ?? '#000000'));
            $gradient->setImageFormat('png');
            if (($g['opacity'] ?? 1.0) < 1.0) {
                $gradient->evaluateImage(Imagick::EVALUATE_MULTIPLY, $g['opacity'], Imagick::CHANNEL_ALPHA);
            }
            $canvas->compositeImage($gradient, Imagick::COMPOSITE_OVER, 0, $h - $gH);
            $gradient->clear();
            $gradient->destroy();
        }

        // Overlays (avec override couleur déco)
        if (!empty($config['overlays'])) {
            foreach ($config['overlays'] as $ov) {
                if ($decoColorOverride) $ov['color'] = $decoColorOverride;
                renderRectLayer($canvas, $ov);
            }
        }

        // Logo client
        if (!empty($location['logo_path'])) {
            $logoFullPath = MEDIA_PATH . '/logos/' . $location['logo_path'];
            if (file_exists($logoFullPath)) {
                renderLogoOnVisual($canvas, $logoFullPath, $w, $h);
            }
        }

        // Fake imageData pour la résolution des variables
        $fakeImg = [
            'visual_text' => $visualText,
            'cta_text' => $ctaText,
            'variables' => '{}'
        ];

        // Layers (avec overrides font + text color + deco color)
        if (!empty($config['layers'])) {
            foreach ($config['layers'] as $layer) {
                if (($layer['type'] ?? '') === 'text') {
                    if ($textColorOverride) $layer['color'] = $textColorOverride;
                    if ($fontOverride) $layer['font'] = $fontOverride;
                    $text = resolveTemplateVariable($layer['variable'] ?? 'visual_text', $fakeImg, $location);
                    if (!empty($text)) renderTextLayer($canvas, $layer, $text);
                } elseif (($layer['type'] ?? '') === 'rectangle') {
                    if ($decoColorOverride) $layer['color'] = $decoColorOverride;
                    renderRectLayer($canvas, $layer);
                }
            }
        }

        // Signature en bas (si activée)
        $sigEnabled = (int)($location['signature_enabled'] ?? 1);
        if ($sigEnabled) {
            $defaultSig = 'Gérée par Neura · BOUS\'TACOM — Expert Google Business · boustacom.fr';
            $sigText = !empty($location['signature_text']) ? $location['signature_text'] : $defaultSig;
            renderSignatureLayer($canvas, $sigText, $w, $h);
        }

        // Export en basse résolution
        $canvas->resizeImage(600, 450, Imagick::FILTER_LANCZOS, 1);
        $canvas->setImageCompressionQuality(70);
        $canvas->stripImage();

        $blob = $canvas->getImageBlob();
        $canvas->clear();
        $canvas->destroy();

        return $blob;

    } catch (Exception $e) {
        error_log("generatePostVisualPreview error: " . $e->getMessage());
        return '';
    }
}

/**
 * Insère les templates de base dans la BDD (idempotent)
 */
function seedDefaultTemplates(int $userId = 1): void {
    $check = db()->query("SELECT COUNT(*) FROM post_templates")->fetchColumn();
    if ($check > 0) return;

    $templates = getDefaultTemplateConfigs();

    foreach ($templates as $tpl) {
        $stmt = db()->prepare("
            INSERT INTO post_templates (user_id, name, slug, width, height, config, category, is_active)
            VALUES (?, ?, ?, 1200, 900, ?, ?, 1)
        ");
        $stmt->execute([
            $userId,
            $tpl['name'],
            $tpl['slug'],
            json_encode($tpl['config'], JSON_UNESCAPED_UNICODE),
            $tpl['category']
        ]);
    }
}

/**
 * Retourne les configs des templates par défaut
 */
function getDefaultTemplateConfigs(): array {
    return [
        [
            'name' => 'Classique Sombre',
            'slug' => 'classique-sombre',
            'category' => 'general',
            'config' => [
                'background' => ['color' => '#0f172a'],
                'gradient' => ['color' => '#000000', 'heightPercent' => 60, 'opacity' => 0.7],
                'overlays' => [],
                'layers' => [
                    [
                        'id' => 'main_text', 'type' => 'text', 'variable' => 'visual_text',
                        'x' => 80, 'y' => 340, 'maxWidth' => 1040, 'maxHeight' => 320,
                        'font' => 'montserrat-bold', 'size' => 56, 'color' => '#FFFFFF',
                        'align' => 'center', 'lineHeight' => 1.35, 'uppercase' => true,
                        'shadow' => ['color' => '#00000099', 'offsetX' => 3, 'offsetY' => 3]
                    ],
                    [
                        'id' => 'cta', 'type' => 'text', 'variable' => 'cta_text',
                        'x' => 80, 'y' => 740, 'maxWidth' => 1040, 'maxHeight' => 80,
                        'font' => 'inter-regular', 'size' => 26, 'color' => '#00e5cc',
                        'align' => 'center', 'lineHeight' => 1.3
                    ],
                    [
                        'id' => 'accent_bar', 'type' => 'rectangle',
                        'x' => 0, 'y' => 860, 'width' => 1200, 'height' => 40,
                        'color' => '#00e5cc', 'opacity' => 1.0
                    ]
                ]
            ]
        ],
        [
            'name' => 'Cyan Moderne',
            'slug' => 'cyan-moderne',
            'category' => 'general',
            'config' => [
                'background' => ['color' => '#0a1628'],
                'gradient' => null,
                'overlays' => [
                    ['x' => 0, 'y' => 0, 'width' => 1200, 'height' => 900, 'color' => '#0a1628', 'opacity' => 1.0],
                    ['x' => 0, 'y' => 420, 'width' => 1200, 'height' => 480, 'color' => '#00e5cc', 'opacity' => 0.08]
                ],
                'layers' => [
                    [
                        'id' => 'label', 'type' => 'text', 'variable' => 'cta_text',
                        'x' => 80, 'y' => 180, 'maxWidth' => 1040, 'maxHeight' => 50,
                        'font' => 'space-mono-bold', 'size' => 18, 'color' => '#00e5cc',
                        'align' => 'center', 'lineHeight' => 1.2, 'uppercase' => true
                    ],
                    [
                        'id' => 'main_text', 'type' => 'text', 'variable' => 'visual_text',
                        'x' => 60, 'y' => 280, 'maxWidth' => 1080, 'maxHeight' => 360,
                        'font' => 'anton', 'size' => 64, 'color' => '#FFFFFF',
                        'align' => 'center', 'lineHeight' => 1.25, 'uppercase' => true
                    ],
                    [
                        'id' => 'line_top', 'type' => 'rectangle',
                        'x' => 500, 'y' => 240, 'width' => 200, 'height' => 3,
                        'color' => '#00e5cc', 'opacity' => 1.0
                    ],
                    [
                        'id' => 'line_bottom', 'type' => 'rectangle',
                        'x' => 500, 'y' => 700, 'width' => 200, 'height' => 3,
                        'color' => '#00e5cc', 'opacity' => 1.0
                    ]
                ]
            ]
        ],
        [
            'name' => 'Élégant Playfair',
            'slug' => 'elegant-playfair',
            'category' => 'premium',
            'config' => [
                'background' => ['color' => '#1c1917'],
                'gradient' => null,
                'overlays' => [
                    ['x' => 0, 'y' => 0, 'width' => 1200, 'height' => 900, 'color' => '#1c1917', 'opacity' => 1.0],
                    ['x' => 40, 'y' => 40, 'width' => 1120, 'height' => 820, 'color' => '#292524', 'opacity' => 1.0, 'radius' => 0],
                    ['x' => 50, 'y' => 50, 'width' => 1100, 'height' => 800, 'color' => '#1c1917', 'opacity' => 1.0, 'radius' => 0]
                ],
                'layers' => [
                    [
                        'id' => 'deco_top', 'type' => 'rectangle',
                        'x' => 520, 'y' => 200, 'width' => 160, 'height' => 2,
                        'color' => '#d4a574', 'opacity' => 1.0
                    ],
                    [
                        'id' => 'main_text', 'type' => 'text', 'variable' => 'visual_text',
                        'x' => 100, 'y' => 260, 'maxWidth' => 1000, 'maxHeight' => 350,
                        'font' => 'playfair-bold', 'size' => 52, 'color' => '#fafaf9',
                        'align' => 'center', 'lineHeight' => 1.4
                    ],
                    [
                        'id' => 'deco_bottom', 'type' => 'rectangle',
                        'x' => 520, 'y' => 680, 'width' => 160, 'height' => 2,
                        'color' => '#d4a574', 'opacity' => 1.0
                    ],
                    [
                        'id' => 'cta', 'type' => 'text', 'variable' => 'cta_text',
                        'x' => 100, 'y' => 720, 'maxWidth' => 1000, 'maxHeight' => 60,
                        'font' => 'raleway', 'size' => 22, 'color' => '#d4a574',
                        'align' => 'center', 'lineHeight' => 1.3, 'uppercase' => true
                    ]
                ]
            ]
        ],
        [
            'name' => 'Impact Poppins',
            'slug' => 'impact-poppins',
            'category' => 'commercial',
            'config' => [
                'background' => ['color' => '#111827'],
                'gradient' => null,
                'overlays' => [
                    ['x' => 0, 'y' => 0, 'width' => 600, 'height' => 900, 'color' => '#7c3aed', 'opacity' => 0.15],
                    ['x' => 600, 'y' => 0, 'width' => 600, 'height' => 900, 'color' => '#06b6d4', 'opacity' => 0.10]
                ],
                'layers' => [
                    [
                        'id' => 'main_text', 'type' => 'text', 'variable' => 'visual_text',
                        'x' => 80, 'y' => 240, 'maxWidth' => 1040, 'maxHeight' => 380,
                        'font' => 'poppins-bold', 'size' => 58, 'color' => '#FFFFFF',
                        'align' => 'center', 'lineHeight' => 1.3, 'uppercase' => false,
                        'shadow' => ['color' => '#00000066', 'offsetX' => 2, 'offsetY' => 2]
                    ],
                    [
                        'id' => 'cta', 'type' => 'text', 'variable' => 'cta_text',
                        'x' => 80, 'y' => 700, 'maxWidth' => 1040, 'maxHeight' => 80,
                        'font' => 'poppins-regular', 'size' => 24, 'color' => '#a78bfa',
                        'align' => 'center', 'lineHeight' => 1.3
                    ],
                    [
                        'id' => 'accent', 'type' => 'rectangle',
                        'x' => 480, 'y' => 660, 'width' => 240, 'height' => 4,
                        'color' => '#7c3aed', 'opacity' => 1.0, 'radius' => 2
                    ]
                ]
            ]
        ],
        [
            'name' => 'Minimaliste Blanc',
            'slug' => 'minimaliste-blanc',
            'category' => 'general',
            'config' => [
                'background' => ['color' => '#ffffff'],
                'gradient' => null,
                'overlays' => [],
                'layers' => [
                    [
                        'id' => 'main_text', 'type' => 'text', 'variable' => 'visual_text',
                        'x' => 100, 'y' => 300, 'maxWidth' => 1000, 'maxHeight' => 320,
                        'font' => 'inter', 'size' => 48, 'color' => '#0f172a',
                        'align' => 'center', 'lineHeight' => 1.4
                    ],
                    [
                        'id' => 'cta', 'type' => 'text', 'variable' => 'cta_text',
                        'x' => 100, 'y' => 700, 'maxWidth' => 1000, 'maxHeight' => 60,
                        'font' => 'inter-regular', 'size' => 22, 'color' => '#64748b',
                        'align' => 'center', 'lineHeight' => 1.3
                    ],
                    [
                        'id' => 'top_line', 'type' => 'rectangle',
                        'x' => 540, 'y' => 250, 'width' => 120, 'height' => 4,
                        'color' => '#0f172a', 'opacity' => 1.0, 'radius' => 2
                    ],
                    [
                        'id' => 'bottom_line', 'type' => 'rectangle',
                        'x' => 540, 'y' => 670, 'width' => 120, 'height' => 4,
                        'color' => '#0f172a', 'opacity' => 1.0, 'radius' => 2
                    ]
                ]
            ]
        ],
        [
            'name' => 'Restaurant Chaud',
            'slug' => 'restaurant-chaud',
            'category' => 'restaurant',
            'config' => [
                'background' => ['color' => '#1a0a00'],
                'gradient' => ['color' => '#000000', 'heightPercent' => 70, 'opacity' => 0.8],
                'overlays' => [],
                'layers' => [
                    [
                        'id' => 'main_text', 'type' => 'text', 'variable' => 'visual_text',
                        'x' => 80, 'y' => 320, 'maxWidth' => 1040, 'maxHeight' => 340,
                        'font' => 'playfair-bold', 'size' => 54, 'color' => '#fef3c7',
                        'align' => 'center', 'lineHeight' => 1.35,
                        'shadow' => ['color' => '#00000088', 'offsetX' => 2, 'offsetY' => 2]
                    ],
                    [
                        'id' => 'cta', 'type' => 'text', 'variable' => 'cta_text',
                        'x' => 80, 'y' => 740, 'maxWidth' => 1040, 'maxHeight' => 60,
                        'font' => 'raleway', 'size' => 22, 'color' => '#f59e0b',
                        'align' => 'center', 'lineHeight' => 1.3, 'uppercase' => true
                    ],
                    [
                        'id' => 'accent_bar', 'type' => 'rectangle',
                        'x' => 0, 'y' => 860, 'width' => 1200, 'height' => 40,
                        'color' => '#f59e0b', 'opacity' => 1.0
                    ]
                ]
            ]
        ]
    ];
}

// ============================================
// GOOGLE BUSINESS PROFILE — Read / Patch API
// ============================================

/**
 * Lit le profil complet d'une fiche Google Business Profile.
 * Utilise l'API Business Information v1 avec un readMask etendu.
 *
 * @param int $locationId  ID de la location en base
 * @return array ['success' => bool, 'google' => array, 'local' => array, 'error' => string|null]
 */
function gbpReadProfile(int $locationId): array {
    $stmt = db()->prepare('
        SELECT l.*, a.id as account_id, a.google_account_name
        FROM gbp_locations l
        JOIN gbp_accounts a ON a.id = l.gbp_account_id
        WHERE l.id = ?
    ');
    $stmt->execute([$locationId]);
    $loc = $stmt->fetch();
    if (!$loc) return ['success' => false, 'error' => 'Location introuvable'];

    $token = getValidGoogleToken($loc['account_id']);
    if (!$token) return ['success' => false, 'error' => 'Token Google expire ou absent'];

    // Resoudre le path correct
    $resolved = resolveGoogleLocationPath(
        $loc['google_location_id'],
        $loc['google_account_name'],
        $token,
        $locationId
    );
    if (!$resolved['success']) {
        return ['success' => false, 'error' => 'Impossible de resoudre le path Google: ' . ($resolved['error'] ?? 'unknown')];
    }

    $v1Path = "locations/{$resolved['location_id']}";
    $readMask = 'title,storefrontAddress,phoneNumbers,websiteUri,categories,regularHours,specialHours,profile,serviceArea,latlng,metadata,openInfo,labels';
    $url = "https://mybusinessbusinessinformation.googleapis.com/v1/{$v1Path}?readMask=" . urlencode($readMask);

    $headers = ['Authorization: Bearer ' . $token];
    $data = httpGet($url, $headers);

    if (!$data || isset($data['error'])) {
        $errMsg = $data['error']['message'] ?? 'Erreur API Google';
        return ['success' => false, 'error' => $errMsg];
    }

    return [
        'success' => true,
        'google' => $data,
        'local' => $loc,
        'resolved_path' => $v1Path,
        'account_id' => $loc['account_id'],
    ];
}

/**
 * Met a jour une section du profil Google via PATCH.
 *
 * @param int    $locationId  ID de la location en base
 * @param string $updateMask  Champs a mettre a jour (ex: 'title', 'regularHours', 'profile.description')
 * @param array  $patchData   Donnees a envoyer (structure Google API)
 * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
 */
function gbpPatchProfile(int $locationId, string $updateMask, array $patchData): array {
    try {
        $stmt = db()->prepare('
            SELECT l.*, a.id as account_id, a.google_account_name
            FROM gbp_locations l
            JOIN gbp_accounts a ON a.id = l.gbp_account_id
            WHERE l.id = ?
        ');
        $stmt->execute([$locationId]);
        $loc = $stmt->fetch();
        if (!$loc) return ['success' => false, 'error' => 'Location introuvable'];

        $token = getValidGoogleToken($loc['account_id']);
        if (!$token) return ['success' => false, 'error' => 'Token Google expire ou absent'];

        // Extraire l'ID numerique directement (evite le resolveGoogleLocationPath lent)
        $numericLocId = preg_replace('/^locations\//', '', $loc['google_location_id']);
        $v1Path = "locations/{$numericLocId}";

        $url = "https://mybusinessbusinessinformation.googleapis.com/v1/{$v1Path}?updateMask=" . urlencode($updateMask);
        $headers = ['Authorization: Bearer ' . $token];

        $result = httpPatchJson($url, $patchData, $headers);

        $httpCode = $result['_http_code'] ?? 0;

        // Si 404 (path stale), essayer avec resolveGoogleLocationPath
        if ($httpCode === 404) {
            error_log("gbpPatchProfile: 404 on {$v1Path}, trying resolve...");
            $resolved = resolveGoogleLocationPath(
                $loc['google_location_id'],
                $loc['google_account_name'],
                $token,
                $locationId
            );
            if ($resolved['success']) {
                $v1Path = "locations/{$resolved['location_id']}";
                $url = "https://mybusinessbusinessinformation.googleapis.com/v1/{$v1Path}?updateMask=" . urlencode($updateMask);
                $result = httpPatchJson($url, $patchData, $headers);
                $httpCode = $result['_http_code'] ?? 0;
            }
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'data' => $result];
        }

        $errMsg = $result['error']['message'] ?? ($result['_curl_error'] ?? 'Erreur inconnue Google API (HTTP ' . $httpCode . ')');
        error_log("gbpPatchProfile error: {$errMsg} — HTTP {$httpCode} — mask: {$updateMask} — data: " . json_encode($patchData));
        return ['success' => false, 'error' => $errMsg, 'http_code' => $httpCode, 'details' => $result];
    } catch (\Throwable $e) {
        error_log('gbpPatchProfile exception: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Erreur serveur: ' . $e->getMessage()];
    }
}

/**
 * Recherche de categories Google Business (autocompletion).
 *
 * @param string $query  Texte de recherche
 * @param string $regionCode  Code pays (defaut: FR)
 * @param string $languageCode  Code langue (defaut: fr)
 * @return array Liste de categories [{name, displayName}]
 */
function gbpListCategories(string $query, string $regionCode = 'FR', string $languageCode = 'fr'): array {
    try {
        // Recuperer un token valide depuis n'importe quel compte GBP
        $stmt = db()->query('SELECT id FROM gbp_accounts LIMIT 1');
        $accountId = $stmt->fetchColumn();
        if (!$accountId) return [];

        $token = getValidGoogleToken((int)$accountId);
        if (!$token) return [];

        // Le filtre Google : displayName=query (SANS guillemets)
        // Docs Google: "Eg: displayName=foo"
        $params = [
            'regionCode' => $regionCode,
            'languageCode' => $languageCode,
            'pageSize' => 20,
            'view' => 'FULL',
        ];
        if ($query) {
            $params['filter'] = 'displayName=' . $query;
        }

        // Construire l'URL manuellement pour eviter le double-encodage du filtre
        $url = 'https://mybusinessbusinessinformation.googleapis.com/v1/categories?'
            . 'regionCode=' . urlencode($regionCode)
            . '&languageCode=' . urlencode($languageCode)
            . '&pageSize=20&view=FULL'
            . '&filter=' . urlencode('displayName=' . $query);
        $headers = ['Authorization: Bearer ' . $token];
        $data = httpGet($url, $headers);

        if (!$data || !isset($data['categories'])) return [];

        return array_map(function($cat) {
            return [
                'name' => $cat['name'] ?? '',
                'displayName' => $cat['displayName'] ?? '',
            ];
        }, $data['categories']);
    } catch (\Throwable $e) {
        error_log('gbpListCategories error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Genere une suggestion IA pour un champ de la fiche GBP.
 * Utilise Claude pour proposer un contenu optimise SEO local.
 *
 * @param string $field       Champ cible : 'title', 'description'
 * @param array  $context     Contexte : nom actuel, categorie, ville, etc.
 * @return string|null        Suggestion generee
 */
function gbpAISuggest(string $field, array $context): ?string {
    $businessName = $context['name'] ?? '';
    $category     = $context['category'] ?? '';
    $city         = $context['city'] ?? '';
    $currentValue = $context['current'] ?? '';
    $website      = $context['website'] ?? '';
    $keywords     = $context['keywords'] ?? '';

    if ($field === 'title') {
        $prompt = <<<PROMPT
Tu es un expert Google Business Profile en 2026.

Le client veut vérifier que le nom de sa fiche GBP est conforme aux guidelines Google.

Nom actuel : {$businessName}
Catégorie : {$category}
Ville : {$city}

RÈGLES GOOGLE 2026 (strictes, non négociables) :
- Le nom DOIT correspondre EXACTEMENT au nom commercial réel (enseigne, signalétique, documents légaux)
- INTERDIT d'ajouter des mots-clés, la ville, la catégorie, ou des descriptifs
- INTERDIT les superlatifs ("meilleur", "#1", "top")
- INTERDIT les caractères spéciaux ou emojis
- INTERDIT le tout-majuscules sauf si c'est la marque officielle
- Google SUSPEND les fiches qui ne respectent pas ces règles en 2026

Analyse le nom actuel et :
1. S'il est déjà conforme → renvoie-le tel quel
2. S'il contient des mots-clés ajoutés, une ville, ou des descriptifs → nettoie-le pour ne garder que le vrai nom commercial

Réponds UNIQUEMENT avec le nom conforme, sans guillemets, sans explication.
PROMPT;
        return callClaude($prompt, 100);

    } elseif ($field === 'description') {
        $keywordsInfo = $keywords
            ? "\nMots-clés à intégrer naturellement : {$keywords}"
            : '';

        $prompt = <<<PROMPT
Tu es un expert SEO local Google Business Profile en 2026.
Rédige une description d'établissement parfaitement optimisée.

Contexte :
- Nom : {$businessName}
- Catégorie : {$category}
- Ville : {$city}
- Site web : {$website}{$keywordsInfo}
- Description actuelle : {$currentValue}

RÈGLES D'OPTIMISATION SEO LOCAL 2026 :
1. EXACTEMENT entre 700 et 750 caractères (utilise tout l'espace)
2. Première phrase = accroche avec le mot-clé principal + nom de la ville
3. Intègre CHAQUE mot-clé fourni de façon NATURELLE (1 à 2 occurrences max par mot-clé)
4. Mentionne la zone géographique (ville + quartier ou région si pertinent)
5. Décris les services/spécialités principaux
6. Inclus les points forts / différenciateurs (ancienneté, certifications, expertise)
7. Termine par un appel à l'action non-promotionnel
8. Ton professionnel et chaleureux — écrit pour des humains, pas pour des robots
9. INTERDIT : numéros de téléphone, URLs, prix, promotions, remises
10. INTERDIT : bourrage de mots-clés (max 2 mentions par mot-clé)
11. Utilise des variations sémantiques plutôt que de répéter les mêmes termes

Réponds UNIQUEMENT avec la description, sans guillemets, sans explication.
PROMPT;
        return callClaude($prompt, 600);
    }

    return null;
}

/**
 * Vérifie le statut Voice of Merchant pour une fiche GBP.
 * Indique si le propriétaire a le contrôle de sa fiche.
 *
 * @param int $locationId  ID local de la location
 * @return array           ['success' => bool, 'hasVoice' => bool, 'error' => string|null]
 */
function gbpGetVoiceOfMerchant(int $locationId): array {
    // Récupérer le path Google de la location
    $stmt = db()->prepare('
        SELECT gl.google_location_id, ga.google_account_id
        FROM gbp_locations gl
        JOIN gbp_accounts ga ON ga.id = gl.gbp_account_id
        WHERE gl.id = ?
    ');
    $stmt->execute([$locationId]);
    $loc = $stmt->fetch();

    if (!$loc || !$loc['google_location_id'] || !$loc['google_account_id']) {
        return ['success' => false, 'hasVoice' => false, 'error' => 'Location non trouvée'];
    }

    $locationGid = $loc['google_location_id'];
    $locationPath = "locations/{$locationGid}";

    $token = getValidGoogleToken($locationId);
    if (!$token) {
        return ['success' => false, 'hasVoice' => false, 'error' => 'Token Google invalide'];
    }

    // Appel API Google Business Verifications v1
    $url = "https://mybusinessverifications.googleapis.com/v1/{$locationPath}/VoiceOfMerchantState";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true) ?: [];

    if ($httpCode >= 200 && $httpCode < 300) {
        $hasVoice = ($data['hasVoiceOfMerchant'] ?? false) === true;
        return [
            'success'              => true,
            'hasVoice'             => $hasVoice,
            'hasBusinessAuthority' => ($data['hasBusinessAuthority'] ?? false) === true,
            'complyWithGuidelines' => $data['complyWithGuidelines'] ?? null,
            'resolveOwnershipConflict' => $data['resolveOwnershipConflict'] ?? null,
            'verify'               => $data['verify'] ?? null,
        ];
    }

    error_log("gbpGetVoiceOfMerchant error HTTP {$httpCode}: " . ($response ?: 'empty'));
    return ['success' => false, 'hasVoice' => false, 'error' => "Erreur API ({$httpCode})"];
}

// ============================================
// GOOGLE PLACES API (New) — Fonctions helpers
// ============================================

/**
 * Lire le cache Places API depuis la base de données
 * @return array|null Les données en cache, ou null si périmées/absentes
 */
function getPlacesCache(string $placeId, string $mode = 'basic', int $ttlSeconds = 21600): ?array {
    try {
        $stmt = db()->prepare('
            SELECT raw_data, updated_at, is_sab FROM google_places_cache
            WHERE place_id = ? AND mode = ?
            AND updated_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ');
        $stmt->execute([$placeId, $mode, $ttlSeconds]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $data = json_decode($row['raw_data'], true);
            if (is_array($data)) {
                $data['_cache_updated_at'] = $row['updated_at'];
                $data['_is_sab'] = (int)$row['is_sab'];
                return $data;
            }
        }
    } catch (Exception $e) {
        error_log("getPlacesCache error: " . $e->getMessage());
    }
    return null;
}

/**
 * Écrire dans le cache Places API
 */
function setPlacesCache(string $placeId, string $mode, array $data, bool $isSab = false): void {
    try {
        $stmt = db()->prepare('
            INSERT INTO google_places_cache (place_id, mode, raw_data, is_sab, updated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE raw_data = VALUES(raw_data), is_sab = VALUES(is_sab), updated_at = NOW()
        ');
        $stmt->execute([$placeId, $mode, json_encode($data, JSON_UNESCAPED_UNICODE), $isSab ? 1 : 0]);
    } catch (Exception $e) {
        error_log("setPlacesCache error: " . $e->getMessage());
    }
}

/**
 * Récupérer les détails d'un lieu via Google Places API (New) avec gestion du cache
 *
 * @param string $placeId  Place ID Google (ChIJ...)
 * @param string $mode     'basic' | 'extended' | 'reviews'
 * @param bool   $forceRefresh  Forcer un appel API même si cache valide
 * @return array|null  Les données du lieu ou null en cas d'erreur
 */
function googlePlacesDetailsFetch(string $placeId, string $mode = 'basic', bool $forceRefresh = false): ?array {
    $apiKey = defined('GOOGLE_PLACES_API_KEY') ? GOOGLE_PLACES_API_KEY : '';
    if (!$apiKey || $apiKey === 'PLACEHOLDER_WAITING_FOR_KEY') return null;

    // TTL selon le mode : basic=6h, extended=6h, reviews=24h
    $ttl = ($mode === 'reviews') ? 86400 : 21600;

    // Vérifier le cache
    if (!$forceRefresh) {
        $cached = getPlacesCache($placeId, $mode, $ttl);
        if ($cached) return $cached;
    }

    // Construire le FieldMask selon le mode
    $basicFields = [
        'id', 'displayName', 'formattedAddress', 'shortFormattedAddress',
        'location', 'primaryType', 'primaryTypeDisplayName',
        'rating', 'userRatingCount', 'websiteUri', 'nationalPhoneNumber', 'googleMapsUri',
    ];
    $extendedFields = array_merge($basicFields, [
        'regularOpeningHours', 'currentOpeningHours', 'photos', 'businessStatus',
        'editorialSummary', 'types', 'addressComponents',
        'accessibilityOptions', 'outdoorSeating', 'takeout', 'delivery', 'dineIn',
        'curbsidePickup', 'reservable', 'paymentOptions', 'allowsDogs', 'restroom',
        'goodForChildren', 'goodForGroups', 'liveMusic', 'servesBreakfast',
        'servesLunch', 'servesDinner', 'servesVegetarianFood',
    ]);
    $reviewsFields = array_merge($extendedFields, ['reviews']);

    $fields = match ($mode) {
        'extended' => $extendedFields,
        'reviews'  => $reviewsFields,
        default    => $basicFields,
    };
    $fieldMask = implode(',', $fields);

    // Appel API
    $url = 'https://places.googleapis.com/v1/places/' . urlencode($placeId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . $apiKey,
            'X-Goog-FieldMask: ' . $fieldMask,
            'Referer: ' . APP_URL . '/',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        error_log("googlePlacesDetailsFetch error HTTP {$httpCode} for {$placeId} mode={$mode}");
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) return null;

    // Détection SAB (Service Area Business) : pas d'adresse visible
    $isSab = empty($data['formattedAddress']);

    // Stocker en cache
    setPlacesCache($placeId, $mode, $data, $isSab);

    // Auto-snapshot pour les modes extended et reviews
    if (in_array($mode, ['extended', 'reviews'])) {
        snapshotPlacesStats($placeId, $data);
    }

    $data['_is_sab'] = $isSab ? 1 : 0;
    return $data;
}

/**
 * Calculer le score de complétude d'une fiche Google Places
 * @return array ['score' => 0-100, 'max' => 100, 'checks' => [...]]
 */
function calculatePlacesCompletenessScore(array $placeData): array {
    $checks = [
        'name' => [
            'filled' => !empty($placeData['displayName']['text'] ?? $placeData['name'] ?? ''),
            'weight' => 10,
            'label'  => 'Nom de l\'établissement',
        ],
        'address' => [
            'filled' => !empty($placeData['formattedAddress'] ?? $placeData['address'] ?? ''),
            'weight' => 10,
            'label'  => 'Adresse',
        ],
        'phone' => [
            'filled' => !empty($placeData['nationalPhoneNumber'] ?? $placeData['phone'] ?? ''),
            'weight' => 10,
            'label'  => 'Téléphone',
        ],
        'website' => [
            'filled' => !empty($placeData['websiteUri'] ?? $placeData['website'] ?? ''),
            'weight' => 10,
            'label'  => 'Site web',
        ],
        'hours' => [
            'filled' => !empty($placeData['regularOpeningHours']['periods'] ?? []),
            'weight' => 15,
            'label'  => 'Horaires d\'ouverture',
        ],
        'description' => [
            'filled' => !empty($placeData['editorialSummary']['text'] ?? ''),
            'weight' => 10,
            'label'  => 'Description',
        ],
        'photos_5' => [
            'filled' => count($placeData['photos'] ?? []) >= 5,
            'weight' => 15,
            'label'  => 'Photos (5 minimum)',
        ],
        'photos_10' => [
            'filled' => count($placeData['photos'] ?? []) >= 10,
            'weight' => 5,
            'label'  => 'Photos (10 ou plus)',
        ],
        'category' => [
            'filled' => !empty($placeData['primaryType'] ?? $placeData['primaryTypeDisplayName'] ?? ''),
            'weight' => 5,
            'label'  => 'Catégorie principale',
        ],
        'rating' => [
            'filled' => ($placeData['rating'] ?? 0) > 0,
            'weight' => 5,
            'label'  => 'Note Google',
        ],
        'reviews_5' => [
            'filled' => ($placeData['userRatingCount'] ?? 0) >= 5,
            'weight' => 5,
            'label'  => 'Avis (5 minimum)',
        ],
    ];

    $score = 0;
    foreach ($checks as &$check) {
        if ($check['filled']) $score += $check['weight'];
    }

    return ['score' => $score, 'max' => 100, 'checks' => $checks];
}

/**
 * Extraire un place_id depuis une URL Google Maps
 * @return string|null Le place_id ou null si non trouvé
 */
function extractPlaceIdFromGoogleUrl(string $url): ?string {
    // Pattern 1: ?place_id=ChIJ... ou &place_id=ChIJ...
    if (preg_match('/[?&]place_id=([A-Za-z0-9_-]+)/', $url, $m)) return $m[1];
    // Pattern 2: place_id:ChIJ... dans l'URL
    if (preg_match('/place_id[=:]([A-Za-z0-9_-]+)/', $url, $m)) return $m[1];
    // Pattern 3: ChIJ suivi de 20+ caractères alphanumériques dans le path
    if (preg_match('/(ChIJ[A-Za-z0-9_-]{20,})/', $url, $m)) return $m[1];
    return null;
}

/**
 * Upsert un snapshot quotidien dans google_places_stats_history
 */
function snapshotPlacesStats(string $placeId, array $data): void {
    try {
        $rating = $data['rating'] ?? null;
        $totalReviews = $data['userRatingCount'] ?? 0;
        $totalPhotos = count($data['photos'] ?? []);
        $completeness = calculatePlacesCompletenessScore($data);

        $stmt = db()->prepare('
            INSERT INTO google_places_stats_history (place_id, stat_date, rating, total_reviews, total_photos, completeness_score, raw_snapshot)
            VALUES (?, CURDATE(), ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE rating = VALUES(rating), total_reviews = VALUES(total_reviews),
                total_photos = VALUES(total_photos), completeness_score = VALUES(completeness_score),
                raw_snapshot = VALUES(raw_snapshot)
        ');
        $snapshot = [
            'businessStatus' => $data['businessStatus'] ?? null,
            'hasHours' => !empty($data['regularOpeningHours']),
            'hasDescription' => !empty($data['editorialSummary']),
            'hasWebsite' => !empty($data['websiteUri']),
            'hasPhone' => !empty($data['nationalPhoneNumber']),
        ];
        $stmt->execute([
            $placeId, $rating, $totalReviews, $totalPhotos,
            $completeness['score'],
            json_encode($snapshot, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Exception $e) {
        error_log("snapshotPlacesStats error: " . $e->getMessage());
    }
}

/**
 * Synchronise les avis Google Places API vers la table google_places_reviews.
 * Upsert : met à jour si l'avis existe déjà, insère sinon.
 */
function syncPlacesReviews(string $placeId, array $reviews): void {
    try {
        $stmt = db()->prepare('
            INSERT INTO google_places_reviews (place_id, review_id, author_name, author_photo_url, rating, text_content, language_code, review_time, has_owner_reply, owner_reply_text, owner_reply_time, fetched_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                rating = VALUES(rating), text_content = VALUES(text_content),
                has_owner_reply = VALUES(has_owner_reply), owner_reply_text = VALUES(owner_reply_text),
                owner_reply_time = VALUES(owner_reply_time), fetched_at = NOW()
        ');

        foreach ($reviews as $review) {
            $reviewId = $review['name'] ?? md5(json_encode($review));
            $authorName = $review['authorAttribution']['displayName'] ?? '';
            $authorPhoto = $review['authorAttribution']['photoUri'] ?? '';
            $rating = $review['rating'] ?? 0;
            $text = $review['text']['text'] ?? '';
            $lang = $review['text']['languageCode'] ?? '';
            $time = $review['publishTime'] ?? null;
            if ($time) $time = date('Y-m-d H:i:s', strtotime($time));

            $hasReply = !empty($review['ownerResponse']);
            $replyText = $review['ownerResponse']['text'] ?? null;
            $replyTime = $review['ownerResponse']['updateTime'] ?? null;
            if ($replyTime) $replyTime = date('Y-m-d H:i:s', strtotime($replyTime));

            $stmt->execute([
                $placeId, $reviewId, $authorName, $authorPhoto, $rating,
                $text, $lang, $time, $hasReply ? 1 : 0, $replyText, $replyTime,
            ]);
        }
    } catch (Exception $e) {
        error_log("syncPlacesReviews error: " . $e->getMessage());
    }
}
