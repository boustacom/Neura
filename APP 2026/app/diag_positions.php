<?php
/**
 * Diagnostic : vérifier l'état des keyword_positions
 * À supprimer après usage
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

try {
    $pdo = db();

    // 1. Combien de keywords actifs au total
    $totalKw = $pdo->query("SELECT COUNT(*) FROM keywords WHERE is_active = 1")->fetchColumn();

    // 2. Combien ont au moins une position enregistrée
    $kwWithPos = $pdo->query("SELECT COUNT(DISTINCT keyword_id) FROM keyword_positions")->fetchColumn();

    // 3. Dernières positions enregistrées
    $lastPositions = $pdo->query("
        SELECT k.keyword, k.location_id, kp.position, kp.in_local_pack, kp.tracked_at,
               k.last_manual_scan_at, k.last_grid_scan_at
        FROM keyword_positions kp
        JOIN keywords k ON k.id = kp.keyword_id
        ORDER BY kp.tracked_at DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 4. Keywords sans aucune position
    $kwNoPos = $pdo->query("
        SELECT k.id, k.keyword, k.location_id, k.target_city, k.last_manual_scan_at, k.last_grid_scan_at
        FROM keywords k
        LEFT JOIN keyword_positions kp ON kp.keyword_id = k.id
        WHERE k.is_active = 1 AND kp.id IS NULL
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 5. Grid scans existants (pour comparaison)
    $gridScans = $pdo->query("
        SELECT gs.keyword_id, k.keyword, gs.visibility_score, gs.avg_position, gs.scanned_at
        FROM grid_scans gs
        JOIN keywords k ON k.id = gs.keyword_id
        ORDER BY gs.scanned_at DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 6. Scan queue entries récentes
    $scanQueue = $pdo->query("
        SELECT sq.*, k.keyword
        FROM scan_queue sq
        LEFT JOIN keywords k ON k.id = sq.keyword_id
        ORDER BY sq.created_at DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 7. Positions NULL (matching failed)
    $nullPositions = $pdo->query("
        SELECT kp.keyword_id, k.keyword, kp.position, kp.tracked_at
        FROM keyword_positions kp
        JOIN keywords k ON k.id = kp.keyword_id
        WHERE kp.position IS NULL
        ORDER BY kp.tracked_at DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'total_active_keywords' => (int)$totalKw,
        'keywords_with_position_data' => (int)$kwWithPos,
        'keywords_without_any_position' => $kwNoPos,
        'null_positions_matching_failed' => $nullPositions,
        'last_positions' => $lastPositions,
        'grid_scans_exist' => $gridScans,
        'scan_queue_recent' => $scanQueue,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
