<?php
/**
 * BOUS'TACOM — API Monitoring des erreurs (admin only)
 * Actions: count, list, detail
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();
requireCsrf();

header('Content-Type: application/json');
$user = currentUser();

// Admin-only
if (($user['role'] ?? '') !== 'admin') {
    jsonResponse(['error' => 'Acces reserve aux administrateurs'], 403);
}

$action = $_GET['action'] ?? 'list';

switch ($action) {

    // Badge count : erreurs critiques/error en 24h
    case 'count':
        try {
            $stmt = db()->query("SELECT COUNT(*) FROM app_errors WHERE error_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND severity IN ('critical','error')");
            $count = (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            $count = 0;
        }
        jsonResponse(['count' => $count]);
        break;

    // Liste paginee avec filtres
    case 'list':
        $type     = $_GET['type'] ?? '';
        $severity = $_GET['severity'] ?? '';
        $days     = max(1, intval($_GET['days'] ?? 7));
        $limit    = min(intval($_GET['limit'] ?? 100), 500);
        $offset   = max(0, intval($_GET['offset'] ?? 0));

        $where  = ['error_date >= DATE_SUB(NOW(), INTERVAL ? DAY)'];
        $params = [$days];

        if ($type) {
            $where[]  = 'error_type = ?';
            $params[] = $type;
        }
        if ($severity) {
            $where[]  = 'severity = ?';
            $params[] = $severity;
        }

        $whereStr = implode(' AND ', $where);

        // Total
        $countStmt = db()->prepare("SELECT COUNT(*) FROM app_errors WHERE {$whereStr}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Data
        $params[] = $limit;
        $params[] = $offset;
        $stmt = db()->prepare("
            SELECT id, error_date, user_id, location_id, keyword_id, action, error_type, severity, source, message
            FROM app_errors
            WHERE {$whereStr}
            ORDER BY error_date DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $errors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(['success' => true, 'errors' => $errors, 'total' => $total]);
        break;

    // Detail complet d'une erreur
    case 'detail':
        $id = intval($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'id requis'], 400);

        $stmt = db()->prepare('SELECT * FROM app_errors WHERE id = ?');
        $stmt->execute([$id]);
        $error = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$error) jsonResponse(['error' => 'Erreur introuvable'], 404);

        // Decoder le context JSON
        if ($error['context']) {
            $error['context'] = json_decode($error['context'], true);
        }

        jsonResponse(['success' => true, 'error' => $error]);
        break;

    default:
        jsonResponse(['error' => 'Action inconnue'], 400);
}
