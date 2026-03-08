-- ============================================
-- BOUS'TACOM SEO LOCAL — DATABASE SCHEMA
-- Version 1.0
-- À exécuter dans phpMyAdmin sur InfoManiak
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- UTILISATEURS & AUTH
-- ============================================

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','user') DEFAULT 'user',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- FICHES GOOGLE BUSINESS PROFILE
-- ============================================

CREATE TABLE IF NOT EXISTS `gbp_accounts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `google_account_id` VARCHAR(255) DEFAULT NULL,
  `access_token` TEXT DEFAULT NULL,
  `refresh_token` TEXT DEFAULT NULL,
  `token_expires_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `gbp_locations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `gbp_account_id` INT UNSIGNED NOT NULL,
  `google_location_id` VARCHAR(255) DEFAULT NULL,
  `name` VARCHAR(255) NOT NULL,
  `address` VARCHAR(500) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `postal_code` VARCHAR(10) DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `website` VARCHAR(500) DEFAULT NULL,
  `category` VARCHAR(255) DEFAULT NULL,
  `latitude` DECIMAL(10,7) DEFAULT NULL,
  `longitude` DECIMAL(10,7) DEFAULT NULL,
  `place_id` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`gbp_account_id`) REFERENCES `gbp_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- MOTS-CLÉS & SUIVI DE POSITIONS
-- ============================================

CREATE TABLE IF NOT EXISTS `keywords` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `location_id` INT UNSIGNED NOT NULL,
  `keyword` VARCHAR(255) NOT NULL,
  `search_volume` INT DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`location_id`) REFERENCES `gbp_locations`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_kw_location` (`location_id`, `keyword`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `keyword_positions` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `keyword_id` INT UNSIGNED NOT NULL,
  `position` TINYINT UNSIGNED DEFAULT NULL COMMENT 'NULL = not found',
  `in_local_pack` TINYINT(1) DEFAULT 0,
  `tracked_at` DATE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`keyword_id`) REFERENCES `keywords`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_kw_date` (`keyword_id`, `tracked_at`),
  INDEX `idx_tracked_at` (`tracked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- GRILLE DE POSITIONS (GRID RANK)
-- ============================================

CREATE TABLE IF NOT EXISTS `grid_scans` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `keyword_id` INT UNSIGNED NOT NULL,
  `grid_size` TINYINT UNSIGNED DEFAULT 7 COMMENT 'Nb rings (circular) or NxN (square)',
  `radius_km` DECIMAL(5,1) DEFAULT 15.0,
  `grid_type` ENUM('square','circular') DEFAULT 'circular',
  `num_rings` TINYINT UNSIGNED DEFAULT 3,
  `total_points` SMALLINT UNSIGNED DEFAULT 0,
  `avg_position` DECIMAL(4,1) DEFAULT NULL,
  `visibility_score` TINYINT UNSIGNED DEFAULT NULL COMMENT '0-100',
  `top3_count` SMALLINT UNSIGNED DEFAULT 0,
  `top10_count` SMALLINT UNSIGNED DEFAULT 0,
  `top20_count` SMALLINT UNSIGNED DEFAULT 0,
  `out_count` SMALLINT UNSIGNED DEFAULT 0,
  `scanned_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`keyword_id`) REFERENCES `keywords`(`id`) ON DELETE CASCADE,
  INDEX `idx_scanned_at` (`scanned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `grid_points` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `grid_scan_id` INT UNSIGNED NOT NULL,
  `row_index` TINYINT UNSIGNED NOT NULL,
  `col_index` TINYINT UNSIGNED NOT NULL,
  `latitude` DECIMAL(10,7) NOT NULL,
  `longitude` DECIMAL(10,7) NOT NULL,
  `position` TINYINT UNSIGNED DEFAULT NULL,
  `business_name_found` VARCHAR(255) DEFAULT NULL,
  FOREIGN KEY (`grid_scan_id`) REFERENCES `grid_scans`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

-- ============================================
-- AVIS GOOGLE
-- ============================================

CREATE TABLE IF NOT EXISTS `reviews` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `location_id` INT UNSIGNED NOT NULL,
  `google_review_id` VARCHAR(255) DEFAULT NULL,
  `reviewer_name` VARCHAR(255) DEFAULT NULL,
  `reviewer_photo_url` VARCHAR(500) DEFAULT NULL,
  `rating` TINYINT UNSIGNED NOT NULL COMMENT '1-5',
  `comment` TEXT DEFAULT NULL,
  `review_date` DATETIME DEFAULT NULL,
  `reply_text` TEXT DEFAULT NULL,
  `reply_date` DATETIME DEFAULT NULL,
  `reply_source` ENUM('manual','ai_auto','ai_validated') DEFAULT NULL,
  `ai_model_used` VARCHAR(50) DEFAULT NULL,
  `is_replied` TINYINT(1) DEFAULT 0,
  `deleted_by_google` TINYINT(1) DEFAULT 0 COMMENT 'Avis supprime par Google (plus present dans l API)',
  `deleted_at` DATETIME DEFAULT NULL COMMENT 'Date de detection de la suppression',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`location_id`) REFERENCES `gbp_locations`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_review` (`location_id`, `google_review_id`),
  INDEX `idx_is_replied` (`is_replied`),
  INDEX `idx_deleted` (`deleted_by_google`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `review_settings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `location_id` INT UNSIGNED NOT NULL UNIQUE,
  `owner_name` VARCHAR(100) DEFAULT NULL COMMENT 'Nom du signataire des réponses',
  `ai_provider` ENUM('claude','openai') DEFAULT 'claude',
  `ai_model` VARCHAR(50) DEFAULT 'claude-sonnet-4-5-20250514',
  `default_tone` ENUM('professional','friendly','empathetic') DEFAULT 'professional',
  `custom_instructions` TEXT DEFAULT NULL,
  `auto_reply_5_stars` TINYINT(1) DEFAULT 1,
  `auto_reply_4_stars` TINYINT(1) DEFAULT 1,
  `manual_validation_3_below` TINYINT(1) DEFAULT 1,
  `notify_email` TINYINT(1) DEFAULT 1,
  `notify_email_address` VARCHAR(255) DEFAULT NULL,
  `preset_id` INT UNSIGNED DEFAULT NULL COMMENT 'Profil IA applique',
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`location_id`) REFERENCES `gbp_locations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- PROFILS IA (PRESETS)
-- ============================================

CREATE TABLE IF NOT EXISTS `ai_presets` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL COMMENT 'Nom du preset (ex: Tutoiement pro, Vouvoiement formel...)',
  `owner_name` VARCHAR(100) DEFAULT NULL COMMENT 'Nom du signataire',
  `default_tone` ENUM('professional','friendly','empathetic') DEFAULT 'professional',
  `gender` ENUM('male','female','neutral') DEFAULT 'neutral' COMMENT 'male=je suis ravi, female=je suis ravie, neutral=nous sommes ravis',
  `speech_style` ENUM('tu','vous') DEFAULT 'vous' COMMENT 'Tutoiement ou vouvoiement du client',
  `person` ENUM('singular','plural','brand') DEFAULT 'singular' COMMENT 'singular=je, plural=nous, brand=au nom de la marque',
  `signature` TEXT DEFAULT NULL COMMENT 'Signature multi-ligne avec variable {nom_fiche}',
  `custom_instructions` TEXT DEFAULT NULL COMMENT 'Instructions libres pour l IA',
  `report_template` VARCHAR(100) DEFAULT NULL COMMENT 'Template de rapport a utiliser',
  `is_default` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- GOOGLE POSTS
-- ============================================

CREATE TABLE IF NOT EXISTS `post_lists` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `location_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `schedule_days` VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5' COMMENT '1=Lu..7=Di',
  `schedule_times` VARCHAR(255) NOT NULL DEFAULT '09:00' COMMENT 'HH:MM séparées par virgule',
  `is_repeat` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `current_index` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_published_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`location_id`) REFERENCES `gbp_locations`(`id`) ON DELETE CASCADE,
  INDEX `idx_active` (`is_active`),
  INDEX `idx_location` (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `google_posts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `location_id` INT UNSIGNED NOT NULL,
  `list_id` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = post standalone, sinon lié à une Auto List',
  `list_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `list_published_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `post_type` ENUM('STANDARD','EVENT','OFFER') DEFAULT 'STANDARD',
  `event_title` VARCHAR(255) DEFAULT NULL,
  `event_start` DATETIME DEFAULT NULL,
  `event_end` DATETIME DEFAULT NULL,
  `offer_coupon_code` VARCHAR(100) DEFAULT NULL,
  `offer_terms` TEXT DEFAULT NULL,
  `title` VARCHAR(255) DEFAULT NULL,
  `content` TEXT NOT NULL,
  `image_url` VARCHAR(500) DEFAULT NULL,
  `call_to_action_type` VARCHAR(50) DEFAULT NULL,
  `call_to_action_url` VARCHAR(500) DEFAULT NULL,
  `status` ENUM('draft','scheduled','published','failed','list_pending') DEFAULT 'draft',
  `generation_category` VARCHAR(20) DEFAULT NULL COMMENT 'faq_ai, articles, ou NULL si créé manuellement',
  `scheduled_at` DATETIME DEFAULT NULL,
  `published_at` DATETIME DEFAULT NULL,
  `google_post_id` VARCHAR(255) DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`location_id`) REFERENCES `gbp_locations`(`id`) ON DELETE CASCADE,
  INDEX `idx_status` (`status`),
  INDEX `idx_scheduled` (`scheduled_at`),
  INDEX `idx_list_order` (`list_id`, `list_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- AUDITS PROSPECT
-- ============================================

CREATE TABLE IF NOT EXISTS `audits` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `business_name` VARCHAR(255) NOT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `google_url` VARCHAR(500) DEFAULT NULL,
  `prospect_email` VARCHAR(255) DEFAULT NULL,
  `prospect_phone` VARCHAR(30) DEFAULT NULL,
  `score` TINYINT UNSIGNED DEFAULT NULL COMMENT '0-100',
  `audit_data` JSON DEFAULT NULL COMMENT 'Detailed audit results',
  `pdf_path` VARCHAR(500) DEFAULT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- RAPPORTS AUTOMATIQUES
-- ============================================

CREATE TABLE IF NOT EXISTS `report_templates` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `type` ENUM('performance','reviews','grid','custom') DEFAULT 'performance',
  `sections` JSON DEFAULT NULL COMMENT 'Which sections to include',
  `schedule_frequency` ENUM('weekly','biweekly','monthly') DEFAULT 'monthly',
  `schedule_day` VARCHAR(20) DEFAULT NULL COMMENT 'monday, friday, 1 (day of month)',
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `report_recipients` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `template_id` INT UNSIGNED NOT NULL,
  `location_id` INT UNSIGNED NOT NULL,
  `recipient_email` VARCHAR(255) NOT NULL,
  `recipient_name` VARCHAR(100) DEFAULT NULL,
  FOREIGN KEY (`template_id`) REFERENCES `report_templates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`location_id`) REFERENCES `gbp_locations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `report_history` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `template_id` INT UNSIGNED DEFAULT NULL,
  `location_id` INT UNSIGNED NOT NULL,
  `recipient_email` VARCHAR(255) NOT NULL,
  `report_type` VARCHAR(50) NOT NULL,
  `pdf_path` VARCHAR(500) DEFAULT NULL,
  `status` ENUM('sent','failed','scheduled') DEFAULT 'scheduled',
  `sent_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`template_id`) REFERENCES `report_templates`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`location_id`) REFERENCES `gbp_locations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- SETTINGS GLOBAUX
-- ============================================

CREATE TABLE IF NOT EXISTS `settings` (
  `key_name` VARCHAR(100) PRIMARY KEY,
  `value` TEXT DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insérer les settings par défaut
INSERT INTO `settings` (`key_name`, `value`) VALUES
('app_name', 'BOUS''TACOM SEO Local'),
('valueserp_api_key', ''),
('google_client_id', ''),
('google_client_secret', ''),
('google_redirect_uri', 'https://app.boustacom.fr/auth/callback.php'),
('claude_api_key', ''),
('openai_api_key', ''),
('default_grid_size', '7'),
('default_grid_radius_km', '15.0'),
('smtp_host', ''),
('smtp_port', '587'),
('smtp_user', ''),
('smtp_password', ''),
('smtp_from_email', 'contact@boustacom.fr'),
('smtp_from_name', 'BOUS''TACOM');

-- Créer l'admin par défaut (mot de passe: à changer)
INSERT INTO `users` (`name`, `email`, `password_hash`, `role`) VALUES
('Mathieu Bouscaillou', 'contact@boustacom.fr', '$2y$10$CHANGE_THIS_HASH', 'admin');

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- FIN DU SCRIPT
-- Tu peux maintenant aller dans phpMyAdmin,
-- créer une base "boustacom_app" et exécuter ce SQL.
-- ============================================
