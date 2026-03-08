-- Migration : Table location_photos pour la galerie GBP
-- Exécuter une seule fois

CREATE TABLE IF NOT EXISTS location_photos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    location_id     INT UNSIGNED NOT NULL,
    category        VARCHAR(50) DEFAULT 'ADDITIONAL',
    seo_keyword     VARCHAR(200) DEFAULT NULL,
    caption         VARCHAR(500) DEFAULT NULL,
    file_path       VARCHAR(500) NOT NULL,
    file_url        VARCHAR(500) NOT NULL,
    file_size       INT UNSIGNED DEFAULT NULL,
    width           INT UNSIGNED DEFAULT NULL,
    height          INT UNSIGNED DEFAULT NULL,
    status          ENUM('draft','published','failed') DEFAULT 'draft',
    google_media_name VARCHAR(255) DEFAULT NULL,
    error_message   TEXT DEFAULT NULL,
    published_at    DATETIME DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_location (location_id),
    INDEX idx_status (status),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
