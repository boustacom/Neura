<?php
/**
 * Migration temporaire — Ajouter les colonnes grid_radius_km et grid_num_rings a gbp_locations
 * A supprimer apres execution.
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

try {
    // Verifier si les colonnes existent deja
    $stmt = db()->query("SHOW COLUMNS FROM gbp_locations LIKE 'grid_radius_km'");
    if ($stmt->rowCount() === 0) {
        db()->exec("ALTER TABLE gbp_locations ADD COLUMN grid_radius_km DECIMAL(5,1) DEFAULT NULL AFTER longitude");
        db()->exec("ALTER TABLE gbp_locations ADD COLUMN grid_num_rings TINYINT DEFAULT NULL AFTER grid_radius_km");
        echo json_encode(['success' => true, 'message' => 'Colonnes grid_radius_km et grid_num_rings ajoutees.']);
    } else {
        echo json_encode(['success' => true, 'message' => 'Colonnes deja presentes.']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
