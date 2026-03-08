<?php
/**
 * BOUS'TACOM — API Gestion des mots-cles (ajout/suppression)
 * Suppression = HARD DELETE explicite de toutes les tables enfants
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();
requireCsrf();

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$locationId = $_POST['location_id'] ?? null;

if (!$locationId) {
    jsonResponse(['error' => 'location_id requis'], 400);
}

/**
 * Supprime un mot-cle et TOUTES ses donnees associees (grid, positions, concurrents, queue).
 * Nettoyage explicite sans dependance au CASCADE des FK.
 */
function hardDeleteKeyword(int $keywordId, int $locationId): void {
    // 1. grid_competitors et grid_points via grid_scans
    $scanIds = db()->prepare('SELECT id FROM grid_scans WHERE keyword_id = ?');
    $scanIds->execute([$keywordId]);
    $sids = $scanIds->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($sids)) {
        $ph = implode(',', array_fill(0, count($sids), '?'));
        db()->prepare("DELETE FROM grid_competitors WHERE grid_scan_id IN ({$ph})")->execute($sids);
        db()->prepare("DELETE FROM grid_points WHERE grid_scan_id IN ({$ph})")->execute($sids);
    }
    // 2. grid_scans
    db()->prepare('DELETE FROM grid_scans WHERE keyword_id = ?')->execute([$keywordId]);
    // 3. keyword_positions
    db()->prepare('DELETE FROM keyword_positions WHERE keyword_id = ?')->execute([$keywordId]);
    // 4. scan_queue
    db()->prepare('DELETE FROM scan_queue WHERE keyword_id = ?')->execute([$keywordId]);
    // 5. Le mot-cle lui-meme
    db()->prepare('DELETE FROM keywords WHERE id = ?')->execute([$keywordId]);
    // 6. Fichier de progression
    $pf = __DIR__ . '/../tmp/kwscan_' . $keywordId . '.json';
    if (file_exists($pf)) @unlink($pf);
}

switch ($action) {
    case 'add':
        $keyword = trim($_POST['keyword'] ?? '');
        if (empty($keyword)) {
            jsonResponse(['error' => 'Mot-cle vide'], 400);
        }

        $gridRadius = !empty($_POST['grid_radius_km']) ? (float)$_POST['grid_radius_km'] : null;
        $targetCity = trim($_POST['target_city'] ?? '');
        $keywordLower = strtolower($keyword);

        try {
            // Verifier si le mot-cle existe deja (meme keyword + meme ville)
            $check = db()->prepare('SELECT id, is_active FROM keywords WHERE location_id = ? AND keyword = ? AND target_city = ?');
            $check->execute([$locationId, $keywordLower, $targetCity]);
            $existing = $check->fetch();

            if ($existing) {
                // Le mot-cle existe encore (probablement un residu de suppression echouee)
                // → Supprimer proprement l'ancien puis re-creer
                hardDeleteKeyword((int)$existing['id'], (int)$locationId);
            }

            $stmt = db()->prepare('INSERT INTO keywords (location_id, keyword, grid_radius_km, target_city, is_active) VALUES (?, ?, ?, ?, 1)');
            $stmt->execute([$locationId, $keywordLower, $gridRadius, $targetCity]);
            jsonResponse(['success' => true, 'id' => db()->lastInsertId(), 'keyword' => $keywordLower]);
        } catch (Exception $e) {
            logAppError("Add keyword failed: " . $e->getMessage(), 'db_error', 'error', [
                'source' => 'manage-keywords.php', 'action' => 'add',
                'location_id' => (int)$locationId,
                'context' => ['keyword' => $keyword],
                'stack' => $e->getTraceAsString(),
            ]);
            jsonResponse(['error' => cleanErrorMessage($e->getMessage(), 'keywords')], 500);
        }
        break;

    case 'delete':
        $keywordId = $_POST['keyword_id'] ?? null;
        if (!$keywordId) {
            jsonResponse(['error' => 'keyword_id requis'], 400);
        }

        try {
            hardDeleteKeyword((int)$keywordId, (int)$locationId);
            jsonResponse(['success' => true]);
        } catch (Exception $e) {
            logAppError("Delete keyword failed kw={$keywordId}: " . $e->getMessage(), 'db_error', 'error', [
                'source' => 'manage-keywords.php', 'action' => 'delete',
                'location_id' => (int)$locationId, 'keyword_id' => (int)$keywordId,
                'stack' => $e->getTraceAsString(),
            ]);
            jsonResponse(['error' => cleanErrorMessage($e->getMessage(), 'keywords')], 500);
        }
        break;

    default:
        jsonResponse(['error' => 'Action non reconnue'], 400);
}
