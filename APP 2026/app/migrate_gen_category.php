<?php
/**
 * Migration : ajouter colonne generation_category à google_posts
 * À supprimer après exécution
 */
require_once __DIR__ . '/config.php';

try {
    $pdo = db();

    // Vérifier si la colonne existe déjà
    $stmt = $pdo->query("SHOW COLUMNS FROM google_posts LIKE 'generation_category'");
    if ($stmt->rowCount() > 0) {
        echo "La colonne generation_category existe déjà.\n";
    } else {
        $pdo->exec("ALTER TABLE `google_posts` ADD COLUMN `generation_category` VARCHAR(20) DEFAULT NULL COMMENT 'faq_ai, articles, ou NULL si créé manuellement' AFTER `status`");
        echo "Colonne generation_category ajoutée avec succès.\n";
    }
} catch (Exception $e) {
    echo "Erreur : " . $e->getMessage() . "\n";
}
