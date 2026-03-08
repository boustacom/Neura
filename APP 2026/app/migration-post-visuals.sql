-- Migration: Post Visuals — Templates & Generated Images
-- Date: 2026-02-28

-- Table des templates graphiques pour visuels Google Posts
CREATE TABLE IF NOT EXISTS post_templates (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    slug            VARCHAR(100) NOT NULL,
    width           INT UNSIGNED NOT NULL DEFAULT 1200,
    height          INT UNSIGNED NOT NULL DEFAULT 900,
    config          JSON NOT NULL,
    thumbnail       VARCHAR(500) DEFAULT NULL,
    category        VARCHAR(50) DEFAULT 'general',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_template_slug (slug),
    INDEX idx_category (category),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table des images generees
CREATE TABLE IF NOT EXISTS post_images (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    location_id     INT UNSIGNED NOT NULL,
    template_id     INT UNSIGNED DEFAULT NULL,
    google_post_id  INT UNSIGNED DEFAULT NULL,
    visual_text     VARCHAR(500) DEFAULT NULL,
    description     TEXT DEFAULT NULL,
    cta_text        VARCHAR(200) DEFAULT NULL,
    variables       JSON DEFAULT NULL,
    file_path       VARCHAR(500) DEFAULT NULL,
    file_url        VARCHAR(500) DEFAULT NULL,
    file_size       INT UNSIGNED DEFAULT NULL,
    status          ENUM('draft','preview','validated','generated','published') DEFAULT 'draft',
    sort_order      INT UNSIGNED DEFAULT 0,
    generated_at    DATETIME DEFAULT NULL,
    validated_at    DATETIME DEFAULT NULL,
    published_at    DATETIME DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_location (location_id),
    INDEX idx_template (template_id),
    INDEX idx_status (status),
    INDEX idx_google_post (google_post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
