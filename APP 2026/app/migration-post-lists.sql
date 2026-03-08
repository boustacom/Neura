-- ============================================
-- MIGRATION : Auto Lists pour Google Posts
-- ============================================

-- 1. Nouvelle table post_lists
CREATE TABLE IF NOT EXISTS `post_lists` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `location_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `schedule_days` VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5' COMMENT '1=Lu..7=Di, séparés par virgule',
  `schedule_times` VARCHAR(255) NOT NULL DEFAULT '09:00' COMMENT 'Heures HH:MM séparées par virgule',
  `is_repeat` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=boucle, 0=stop à la fin',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=actif, 0=pause',
  `current_index` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Index du prochain post à publier',
  `last_published_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`location_id`) REFERENCES `gbp_locations`(`id`) ON DELETE CASCADE,
  INDEX `idx_active` (`is_active`),
  INDEX `idx_location` (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Ajouter list_id sur google_posts (lien vers la liste)
ALTER TABLE `google_posts`
ADD COLUMN `list_id` INT UNSIGNED DEFAULT NULL AFTER `location_id`;

-- 3. Ajouter list_order pour l'ordre dans la liste
ALTER TABLE `google_posts`
ADD COLUMN `list_order` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `list_id`;

-- 4. Ajouter list_published_count pour tracker les republications en mode boucle
ALTER TABLE `google_posts`
ADD COLUMN `list_published_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `list_order`;

-- 5. Étendre l'enum status pour ajouter list_pending
ALTER TABLE `google_posts`
MODIFY COLUMN `status` ENUM('draft','scheduled','published','failed','list_pending') DEFAULT 'draft';

-- 6. Index pour recherche rapide par liste
ALTER TABLE `google_posts`
ADD INDEX `idx_list_order` (`list_id`, `list_order`);
