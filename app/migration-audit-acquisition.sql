-- Migration: Audit & Acquisition module
-- Date: 2026-02-27

-- 1. Enrichir la table audits
ALTER TABLE audits
  ADD COLUMN address VARCHAR(500) DEFAULT NULL AFTER city,
  ADD COLUMN category VARCHAR(255) DEFAULT NULL AFTER address,
  ADD COLUMN latitude DECIMAL(10,7) DEFAULT NULL AFTER category,
  ADD COLUMN longitude DECIMAL(10,7) DEFAULT NULL AFTER latitude,
  ADD COLUMN place_id VARCHAR(255) DEFAULT NULL AFTER longitude,
  ADD COLUMN data_cid VARCHAR(255) DEFAULT NULL AFTER place_id,
  ADD COLUMN domain VARCHAR(255) DEFAULT NULL AFTER data_cid,
  ADD COLUMN rating DECIMAL(2,1) DEFAULT NULL AFTER domain,
  ADD COLUMN reviews_count INT UNSIGNED DEFAULT 0 AFTER rating,
  ADD COLUMN position TINYINT UNSIGNED DEFAULT NULL AFTER reviews_count,
  ADD COLUMN audit_status ENUM('search_only','scanned','audited') DEFAULT 'search_only' AFTER score,
  ADD COLUMN grid_scan_id INT UNSIGNED DEFAULT NULL AFTER audit_status,
  ADD COLUMN grid_visibility TINYINT UNSIGNED DEFAULT NULL COMMENT '0-100' AFTER grid_scan_id,
  ADD COLUMN grid_avg_position DECIMAL(4,1) DEFAULT NULL AFTER grid_visibility,
  ADD COLUMN grid_top3 SMALLINT UNSIGNED DEFAULT 0 AFTER grid_avg_position,
  ADD COLUMN grid_top10 SMALLINT UNSIGNED DEFAULT 0 AFTER grid_top3,
  ADD COLUMN grid_top20 SMALLINT UNSIGNED DEFAULT 0 AFTER grid_top10,
  ADD INDEX idx_user_status (user_id, audit_status);

-- 2. Scans grille prospect
CREATE TABLE IF NOT EXISTS `prospect_grid_scans` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `audit_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `keyword` VARCHAR(255) NOT NULL COMMENT 'business name + city',
    `center_lat` DECIMAL(10,7) NOT NULL,
    `center_lng` DECIMAL(10,7) NOT NULL,
    `total_points` SMALLINT UNSIGNED DEFAULT 49,
    `radius_km` DECIMAL(5,1) DEFAULT 15.0,
    `avg_position` DECIMAL(4,1) DEFAULT NULL,
    `visibility_score` TINYINT UNSIGNED DEFAULT NULL,
    `top3_count` SMALLINT UNSIGNED DEFAULT 0,
    `top10_count` SMALLINT UNSIGNED DEFAULT 0,
    `top20_count` SMALLINT UNSIGNED DEFAULT 0,
    `out_count` SMALLINT UNSIGNED DEFAULT 0,
    `status` ENUM('running','completed','failed') DEFAULT 'running',
    `started_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `error` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`audit_id`) REFERENCES `audits`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_status` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Points de grille prospect
CREATE TABLE IF NOT EXISTS `prospect_grid_points` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `scan_id` INT UNSIGNED NOT NULL,
    `row_index` TINYINT UNSIGNED NOT NULL,
    `col_index` TINYINT UNSIGNED NOT NULL,
    `latitude` DECIMAL(10,7) NOT NULL,
    `longitude` DECIMAL(10,7) NOT NULL,
    `position` TINYINT UNSIGNED DEFAULT NULL COMMENT 'NULL = not found',
    `business_name_found` VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (`scan_id`) REFERENCES `prospect_grid_scans`(`id`) ON DELETE CASCADE,
    INDEX `idx_scan` (`scan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
