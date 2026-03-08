-- Migration: Cron Grid Scanning System
-- Date: 2026-02-17

CREATE TABLE IF NOT EXISTS cron_scan_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    location_id INT NOT NULL,
    scan_type ENUM('keywords','grid') NOT NULL,
    keywords_scanned INT DEFAULT 0,
    grid_points_scanned INT DEFAULT 0,
    api_calls_used INT DEFAULT 0,
    status ENUM('running','completed','failed','partial') DEFAULT 'running',
    error_message TEXT NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    INDEX idx_location_type (location_id, scan_type),
    INDEX idx_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE keywords ADD COLUMN IF NOT EXISTS last_grid_scan_at DATETIME NULL DEFAULT NULL;
