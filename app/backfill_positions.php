<?php
/**
 * Backfill : remplir les keyword_positions NULL avec la position du point centre grille
 * À supprimer après exécution
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');

try {
    $pdo = db();

    // Trouver tous les keyword_positions avec position NULL qui ont un grid_scan
    $stmt = $pdo->query("
        SELECT kp.keyword_id, kp.tracked_at,
               gp.position AS center_position
        FROM keyword_positions kp
        JOIN keywords k ON k.id = kp.keyword_id AND k.is_active = 1
        JOIN grid_scans gs ON gs.keyword_id = kp.keyword_id
            AND DATE(gs.scanned_at) = kp.tracked_at
            AND gs.scanned_at = (
                SELECT MAX(gs2.scanned_at)
                FROM grid_scans gs2
                WHERE gs2.keyword_id = kp.keyword_id
                AND DATE(gs2.scanned_at) = kp.tracked_at
            )
        JOIN grid_points gp ON gp.grid_scan_id = gs.id
            AND gp.row_index = 3 AND gp.col_index = 3
        WHERE kp.position IS NULL
        AND gp.position IS NOT NULL
        AND gp.position <= 100
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($rows) . " NULL positions with grid center data\n\n";

    $updated = 0;
    $stmtUpdate = $pdo->prepare('
        UPDATE keyword_positions
        SET position = ?, in_local_pack = ?
        WHERE keyword_id = ? AND tracked_at = ?
    ');

    foreach ($rows as $row) {
        $pos = (int)$row['center_position'];
        $inLocalPack = ($pos <= 3) ? 1 : 0;
        $stmtUpdate->execute([$pos, $inLocalPack, $row['keyword_id'], $row['tracked_at']]);
        echo "  KW {$row['keyword_id']} @ {$row['tracked_at']}: NULL -> #{$pos}\n";
        $updated++;
    }

    echo "\nUpdated: {$updated} positions\n";

    // Si le point centre n'est pas row=3,col=3 (essayer aussi via is_center ou row=col=middle)
    // Essayer aussi avec le point le plus proche du centre de la fiche
    if ($updated === 0) {
        echo "\nTrying alternative: using grid avg_position as fallback...\n";
        $stmt2 = $pdo->query("
            SELECT kp.keyword_id, kp.tracked_at,
                   gs.avg_position
            FROM keyword_positions kp
            JOIN keywords k ON k.id = kp.keyword_id AND k.is_active = 1
            JOIN grid_scans gs ON gs.keyword_id = kp.keyword_id
                AND gs.scanned_at = (
                    SELECT MAX(gs2.scanned_at)
                    FROM grid_scans gs2
                    WHERE gs2.keyword_id = kp.keyword_id
                )
            WHERE kp.position IS NULL
            AND gs.avg_position IS NOT NULL
            AND gs.avg_position <= 100
            AND kp.tracked_at = DATE(gs.scanned_at)
        ");
        $rows2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        echo "Found " . count($rows2) . " with grid avg_position\n";

        foreach ($rows2 as $row) {
            $pos = (int)round($row['avg_position']);
            $inLocalPack = ($pos <= 3) ? 1 : 0;
            $stmtUpdate->execute([$pos, $inLocalPack, $row['keyword_id'], $row['tracked_at']]);
            echo "  KW {$row['keyword_id']} @ {$row['tracked_at']}: NULL -> ~#{$pos} (avg)\n";
            $updated++;
        }
        echo "\nUpdated (avg fallback): {$updated} positions\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
