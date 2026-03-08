-- =============================================
-- BOUS'TACOM — Migration: Integration Google API
-- A executer sur la base de donnees MySQL
-- =============================================

-- 1. Ajouter la colonne reply_source si elle n'existe pas deja
-- (permet de tracer l'origine des reponses : manual, ai_auto, ai_validated)
ALTER TABLE reviews ADD COLUMN IF NOT EXISTS reply_source ENUM('manual','ai_auto','ai_validated') DEFAULT NULL;

-- 2. Ajouter la colonne ai_model_used si elle n'existe pas deja
ALTER TABLE reviews ADD COLUMN IF NOT EXISTS ai_model_used VARCHAR(50) DEFAULT NULL;

-- 3. S'assurer que google_review_id a un index unique
-- (ignore l'erreur si deja existant)
-- ALTER TABLE reviews ADD UNIQUE KEY `unique_review` (`location_id`, `google_review_id`);

-- 4. Ajouter des colonnes pour le suivi email/custom des rapports
ALTER TABLE report_recipients ADD COLUMN IF NOT EXISTS recipient_name VARCHAR(100) DEFAULT NULL;
ALTER TABLE report_recipients ADD COLUMN IF NOT EXISTS custom_email_body TEXT DEFAULT NULL;
ALTER TABLE report_recipients ADD COLUMN IF NOT EXISTS is_active TINYINT(1) DEFAULT 1;

-- 5. Ajouter colonnes email au template rapports
ALTER TABLE report_templates ADD COLUMN IF NOT EXISTS email_subject VARCHAR(255) DEFAULT NULL;
ALTER TABLE report_templates ADD COLUMN IF NOT EXISTS email_body TEXT DEFAULT NULL;

-- =============================================
-- NETTOYAGE DES DONNEES DE TEST
-- =============================================

-- Supprimer les avis sans google_review_id (= avis de test manuels)
-- ATTENTION: A executer une seule fois apres la connexion de l'API Google !
-- DELETE FROM reviews WHERE google_review_id IS NULL;

-- Supprimer les posts sans google_post_id (= posts de test non publies)
-- DELETE FROM google_posts WHERE google_post_id IS NULL AND status != 'scheduled';

-- =============================================
-- NOTES
-- =============================================
-- Pour le nettoyage, vous pouvez aussi utiliser l'interface :
-- Allez dans "Fiches GBP" > "Nettoyer les donnees test"
-- Cela supprimera automatiquement les avis et posts de test.
