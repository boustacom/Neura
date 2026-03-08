-- ============================================
-- MIGRATION : review_settings
-- Ajouter owner_name et renommer tone -> default_tone
-- À exécuter dans phpMyAdmin sur InfoManiak
-- ============================================

-- Ajouter la colonne owner_name si elle n'existe pas
ALTER TABLE `review_settings`
ADD COLUMN IF NOT EXISTS `owner_name` VARCHAR(100) DEFAULT NULL
COMMENT 'Nom du signataire des réponses'
AFTER `location_id`;

-- Ajouter la colonne default_tone si elle n'existe pas
ALTER TABLE `review_settings`
ADD COLUMN IF NOT EXISTS `default_tone` ENUM('professional','friendly','empathetic') DEFAULT 'professional'
AFTER `ai_model`;

-- Si l'ancienne colonne 'tone' existe, copier les données puis la supprimer
-- (Exécuter manuellement si nécessaire)
-- UPDATE review_settings SET default_tone =
--   CASE tone
--     WHEN 'professional_warm' THEN 'professional'
--     WHEN 'formal' THEN 'professional'
--     WHEN 'casual' THEN 'friendly'
--     WHEN 'empathetic' THEN 'empathetic'
--     ELSE 'professional'
--   END
-- WHERE tone IS NOT NULL;
-- ALTER TABLE review_settings DROP COLUMN tone;
