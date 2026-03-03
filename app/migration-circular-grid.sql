-- ============================================
-- MIGRATION : Grille circulaire + Concurrents
-- À exécuter sur la base existante
-- ============================================

-- 1. Modifier grid_scans pour supporter la grille circulaire
ALTER TABLE `grid_scans`
  ADD COLUMN `grid_type` ENUM('square','circular') DEFAULT 'circular' AFTER `radius_km`,
  ADD COLUMN `num_rings` TINYINT UNSIGNED DEFAULT 3 AFTER `grid_type`,
  ADD COLUMN `total_points` SMALLINT UNSIGNED DEFAULT 0 AFTER `num_rings`,
  MODIFY `radius_km` DECIMAL(5,1) DEFAULT 15.0;

-- Mettre les anciens scans en type 'square'
UPDATE `grid_scans` SET `grid_type` = 'square' WHERE `grid_type` = 'circular';

-- 2. Creer la table des concurrents
CREATE TABLE IF NOT EXISTS `grid_competitors` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `grid_scan_id` INT UNSIGNED NOT NULL,
  `grid_point_id` BIGINT UNSIGNED DEFAULT NULL,
  `position` TINYINT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `address` VARCHAR(500) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `website` VARCHAR(500) DEFAULT NULL,
  `category` VARCHAR(255) DEFAULT NULL,
  `rating` DECIMAL(2,1) DEFAULT NULL,
  `reviews_count` INT UNSIGNED DEFAULT 0,
  `place_id` VARCHAR(255) DEFAULT NULL,
  `data_cid` VARCHAR(255) DEFAULT NULL,
  `is_target` TINYINT(1) DEFAULT 0 COMMENT '1 = our business',
  FOREIGN KEY (`grid_scan_id`) REFERENCES `grid_scans`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`grid_point_id`) REFERENCES `grid_points`(`id`) ON DELETE CASCADE,
  INDEX `idx_scan_position` (`grid_scan_id`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Mettre a jour le setting du rayon par defaut
UPDATE `settings` SET `value` = '15.0' WHERE `key_name` = 'default_grid_radius_km';
