-- ============================================
-- BOUS'TACOM — Migration : Ajout google_account_name
-- Stocke le nom du compte GBP (format accounts/XXXXX)
-- nécessaire pour construire les URLs v4 correctes
-- ============================================

ALTER TABLE `gbp_accounts` ADD COLUMN `google_account_name` VARCHAR(255) DEFAULT NULL AFTER `google_account_id`;
