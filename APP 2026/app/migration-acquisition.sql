-- =============================================
-- BOUS'TACOM — Migration: Module Acquisition
-- A executer sur la base de donnees MySQL
-- =============================================

-- Ajouter les credits a la table users
ALTER TABLE users ADD COLUMN credits INT UNSIGNED DEFAULT 100;
ALTER TABLE users ADD COLUMN credits_reset_at DATETIME DEFAULT NULL;

-- Table des recherches de prospects
CREATE TABLE IF NOT EXISTS `prospect_searches` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `keyword` VARCHAR(255) NOT NULL,
    `city` VARCHAR(100) NOT NULL,
    `results_count` INT UNSIGNED DEFAULT 0,
    `credits_used` INT UNSIGNED DEFAULT 1,
    `searched_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_searches` (`user_id`, `searched_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
