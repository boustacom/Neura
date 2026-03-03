<?php
/**
 * BOUS'TACOM — API Gestion des Profils IA (Presets)
 *
 * Actions:
 *   - list: Lister tous les presets de l'utilisateur
 *   - create: Creer un nouveau preset
 *   - update: Modifier un preset existant
 *   - delete: Supprimer un preset
 *   - apply: Appliquer un preset a une ou plusieurs fiches
 *   - get_assignments: Voir quelles fiches utilisent quel preset
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();
requireCsrf();

header('Content-Type: application/json');

$user = currentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? null;

if (!$action) {
    jsonResponse(['error' => 'Action requise'], 400);
}

// Verifier que la table existe, sinon la creer
try {
    db()->query('SELECT 1 FROM ai_presets LIMIT 1');
} catch (Exception $e) {
    db()->exec("
        CREATE TABLE IF NOT EXISTS `ai_presets` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT UNSIGNED NOT NULL,
          `name` VARCHAR(100) NOT NULL,
          `owner_name` VARCHAR(100) DEFAULT NULL,
          `default_tone` ENUM('professional','friendly','empathetic') DEFAULT 'professional',
          `gender` ENUM('male','female','neutral') DEFAULT 'neutral',
          `speech_style` ENUM('tu','vous') DEFAULT 'vous',
          `person` ENUM('singular','plural','brand') DEFAULT 'singular',
          `signature` TEXT DEFAULT NULL,
          `custom_instructions` TEXT DEFAULT NULL,
          `report_template` VARCHAR(100) DEFAULT NULL,
          `is_default` TINYINT(1) DEFAULT 0,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

// Ajouter la colonne preset_id a review_settings si elle n'existe pas
try {
    db()->query('SELECT preset_id FROM review_settings LIMIT 1');
} catch (Exception $e) {
    db()->exec("ALTER TABLE review_settings ADD COLUMN preset_id INT UNSIGNED DEFAULT NULL COMMENT 'Profil IA applique'");
}

// Ajouter la colonne signature a ai_presets si elle n'existe pas
try {
    db()->query('SELECT signature FROM ai_presets LIMIT 1');
} catch (Exception $e) {
    db()->exec("ALTER TABLE ai_presets ADD COLUMN signature TEXT DEFAULT NULL AFTER person");
}

switch ($action) {

    // ====== LISTER LES PRESETS ======
    case 'list':
        $stmt = db()->prepare('
            SELECT p.*,
                   (SELECT COUNT(*) FROM review_settings rs
                    JOIN gbp_locations l ON rs.location_id = l.id
                    JOIN gbp_accounts a ON l.gbp_account_id = a.id
                    WHERE a.user_id = ? AND rs.preset_id = p.id) as location_count
            FROM ai_presets p
            WHERE p.user_id = ?
            ORDER BY p.is_default DESC, p.name
        ');
        $stmt->execute([$user['id'], $user['id']]);
        $presets = $stmt->fetchAll();

        // Recuperer toutes les fiches avec leur preset actuel
        $stmt2 = db()->prepare('
            SELECT l.id, l.name, l.city, rs.preset_id,
                   rs.owner_name, rs.default_tone, rs.gender, rs.custom_instructions
            FROM gbp_locations l
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            LEFT JOIN review_settings rs ON rs.location_id = l.id
            WHERE a.user_id = ? AND l.is_active = 1
            ORDER BY l.name
        ');
        $stmt2->execute([$user['id']]);
        $locations = $stmt2->fetchAll();

        jsonResponse([
            'success' => true,
            'presets' => $presets,
            'locations' => $locations,
        ]);
        break;

    // ====== CREER UN PRESET ======
    case 'create':
        $name = trim($_POST['name'] ?? '');
        if (!$name) {
            jsonResponse(['error' => 'Le nom du profil est requis'], 400);
        }

        $stmt = db()->prepare('
            INSERT INTO ai_presets (user_id, name, owner_name, default_tone, gender, speech_style, person, signature, custom_instructions, report_template)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $user['id'],
            $name,
            trim($_POST['owner_name'] ?? ''),
            $_POST['default_tone'] ?? 'professional',
            $_POST['gender'] ?? 'neutral',
            $_POST['speech_style'] ?? 'vous',
            $_POST['person'] ?? 'singular',
            trim($_POST['signature'] ?? ''),
            trim($_POST['custom_instructions'] ?? ''),
            trim($_POST['report_template'] ?? ''),
        ]);

        jsonResponse(['success' => true, 'id' => db()->lastInsertId(), 'message' => 'Profil IA cree']);
        break;

    // ====== MODIFIER UN PRESET ======
    case 'update':
        $presetId = $_POST['preset_id'] ?? null;
        if (!$presetId) {
            jsonResponse(['error' => 'preset_id requis'], 400);
        }

        // Verifier que le preset appartient a l'utilisateur
        $stmt = db()->prepare('SELECT id FROM ai_presets WHERE id = ? AND user_id = ?');
        $stmt->execute([$presetId, $user['id']]);
        if (!$stmt->fetch()) {
            jsonResponse(['error' => 'Preset non trouve'], 404);
        }

        $name = trim($_POST['name'] ?? '');
        if (!$name) {
            jsonResponse(['error' => 'Le nom du profil est requis'], 400);
        }

        $stmt = db()->prepare('
            UPDATE ai_presets SET
                name = ?, owner_name = ?, default_tone = ?, gender = ?,
                speech_style = ?, person = ?, signature = ?, custom_instructions = ?, report_template = ?
            WHERE id = ? AND user_id = ?
        ');
        $stmt->execute([
            $name,
            trim($_POST['owner_name'] ?? ''),
            $_POST['default_tone'] ?? 'professional',
            $_POST['gender'] ?? 'neutral',
            $_POST['speech_style'] ?? 'vous',
            $_POST['person'] ?? 'singular',
            trim($_POST['signature'] ?? ''),
            trim($_POST['custom_instructions'] ?? ''),
            trim($_POST['report_template'] ?? ''),
            $presetId,
            $user['id'],
        ]);

        jsonResponse(['success' => true, 'message' => 'Profil IA mis a jour']);
        break;

    // ====== SUPPRIMER UN PRESET ======
    case 'delete':
        $presetId = $_POST['preset_id'] ?? null;
        if (!$presetId) {
            jsonResponse(['error' => 'preset_id requis'], 400);
        }

        // Dissocier les fiches liees
        $stmt = db()->prepare('
            UPDATE review_settings rs
            JOIN gbp_locations l ON rs.location_id = l.id
            JOIN gbp_accounts a ON l.gbp_account_id = a.id
            SET rs.preset_id = NULL
            WHERE a.user_id = ? AND rs.preset_id = ?
        ');
        $stmt->execute([$user['id'], $presetId]);

        $stmt = db()->prepare('DELETE FROM ai_presets WHERE id = ? AND user_id = ?');
        $stmt->execute([$presetId, $user['id']]);

        jsonResponse(['success' => true, 'message' => 'Profil supprime']);
        break;

    // ====== APPLIQUER UN PRESET A DES FICHES ======
    case 'apply':
        $presetId = $_POST['preset_id'] ?? null;
        $locationIds = $_POST['location_ids'] ?? [];

        if (!$presetId || empty($locationIds)) {
            jsonResponse(['error' => 'preset_id et location_ids requis'], 400);
        }

        // Verifier que le preset appartient a l'utilisateur
        $stmt = db()->prepare('SELECT * FROM ai_presets WHERE id = ? AND user_id = ?');
        $stmt->execute([$presetId, $user['id']]);
        $preset = $stmt->fetch();
        if (!$preset) {
            jsonResponse(['error' => 'Preset non trouve'], 404);
        }

        // Construire les instructions combinees a partir du preset
        $instructions = $preset['custom_instructions'] ?? '';
        $signature = $preset['signature'] ?? '';

        // Ajouter les meta-instructions basees sur speech_style et person
        $meta = [];
        if ($preset['speech_style'] === 'tu') {
            $meta[] = 'Utilise le tutoiement avec le client.';
        } else {
            $meta[] = 'Utilise le vouvoiement avec le client.';
        }
        if ($preset['person'] === 'singular') {
            $meta[] = 'Parle a la premiere personne du singulier (je).';
        } elseif ($preset['person'] === 'plural') {
            $meta[] = 'Parle a la premiere personne du pluriel (nous), au nom de l\'equipe.';
        } else {
            $meta[] = 'Parle au nom de la marque/entreprise (nous), de maniere corporate.';
        }

        $fullInstructions = implode(' ', $meta);
        if ($instructions) {
            $fullInstructions .= "\n" . $instructions;
        }

        $applied = 0;
        foreach ($locationIds as $locationId) {
            // Verifier que la fiche appartient a l'utilisateur
            $stmt = db()->prepare('
                SELECT l.id, l.name FROM gbp_locations l
                JOIN gbp_accounts a ON l.gbp_account_id = a.id
                WHERE l.id = ? AND a.user_id = ?
            ');
            $stmt->execute([$locationId, $user['id']]);
            $loc = $stmt->fetch();
            if (!$loc) continue;

            // Remplacer {nom_fiche} dans la signature par le nom reel de la fiche
            $locSignature = str_replace('{nom_fiche}', $loc['name'], $signature);

            // Combiner instructions + signature pour le prompt IA
            $locInstructions = $fullInstructions;
            if ($locSignature) {
                $locInstructions .= "\nSIGNATURE A UTILISER (respecte les retours a la ligne) :\n" . $locSignature;
            }

            // Determiner le owner_name : utiliser la signature ou le preset owner_name
            $ownerName = $preset['owner_name'] ?? '';
            if (!$ownerName && $locSignature) {
                // Si pas de nom de signataire explicite mais une signature, on garde vide
                $ownerName = '';
            }

            $stmt = db()->prepare('
                INSERT INTO review_settings (location_id, owner_name, default_tone, gender, custom_instructions, preset_id)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    owner_name = VALUES(owner_name),
                    default_tone = VALUES(default_tone),
                    gender = VALUES(gender),
                    custom_instructions = VALUES(custom_instructions),
                    preset_id = VALUES(preset_id),
                    updated_at = NOW()
            ');
            $stmt->execute([
                $locationId,
                $ownerName,
                $preset['default_tone'],
                $preset['gender'],
                $locInstructions,
                $presetId,
            ]);
            $applied++;
        }

        jsonResponse(['success' => true, 'message' => "Profil applique a {$applied} fiche(s)"]);
        break;

    default:
        jsonResponse(['error' => 'Action non reconnue'], 400);
}
