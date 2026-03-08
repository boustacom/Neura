-- ============================================
-- MIGRATION : google_posts
-- Ajouter les colonnes pour types de posts,
-- événements et offres
-- À exécuter dans phpMyAdmin sur InfoManiak
-- ============================================

-- Type de post (Standard, Événement, Offre)
ALTER TABLE `google_posts`
ADD COLUMN `post_type` ENUM('STANDARD','EVENT','OFFER') DEFAULT 'STANDARD'
AFTER `location_id`;

-- Champs pour les posts de type ÉVÉNEMENT
ALTER TABLE `google_posts`
ADD COLUMN `event_title` VARCHAR(255) DEFAULT NULL
AFTER `post_type`;

ALTER TABLE `google_posts`
ADD COLUMN `event_start` DATETIME DEFAULT NULL
AFTER `event_title`;

ALTER TABLE `google_posts`
ADD COLUMN `event_end` DATETIME DEFAULT NULL
AFTER `event_start`;

-- Champs pour les posts de type OFFRE
ALTER TABLE `google_posts`
ADD COLUMN `offer_coupon_code` VARCHAR(100) DEFAULT NULL
AFTER `event_end`;

ALTER TABLE `google_posts`
ADD COLUMN `offer_terms` TEXT DEFAULT NULL
AFTER `offer_coupon_code`;
