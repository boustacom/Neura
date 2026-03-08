-- ============================================
-- MIGRATION : Systeme de rapports automatiques
-- A executer sur phpMyAdmin
-- ============================================

-- 1. Ajouter champs email au template de rapport
ALTER TABLE report_templates
ADD COLUMN email_subject VARCHAR(500) DEFAULT 'Rapport SEO - {client_name} - {period}'
    AFTER sections;

ALTER TABLE report_templates
ADD COLUMN email_body TEXT DEFAULT NULL
    AFTER email_subject;

-- 2. Ajouter personnalisation par destinataire
ALTER TABLE report_recipients
ADD COLUMN custom_email_body TEXT DEFAULT NULL
    AFTER recipient_name;

ALTER TABLE report_recipients
ADD COLUMN is_active TINYINT(1) DEFAULT 1
    AFTER custom_email_body;
