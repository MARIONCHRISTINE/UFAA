-- ============================================================
-- UFAA Database Migration Script (v2.0)
-- For existing databases (Local & Hosted Servers)
-- Run this script in phpMyAdmin or MySQL client on your hosted server
-- ============================================================

USE `ufaa_db`;

-- 1. Add new tracking columns for Holder Letter Generation & Stamped Copy Uploads
ALTER TABLE `unclaimed_assets`
    ADD COLUMN IF NOT EXISTS `letter_generated`      VARCHAR(10) DEFAULT 'No' AFTER `status`,
    ADD COLUMN IF NOT EXISTS `letter_generated_date` DATETIME    NULL         AFTER `letter_generated`,
    ADD COLUMN IF NOT EXISTS `stamped_file_path`     VARCHAR(500) NULL        AFTER `letter_file_path`;

-- 2. Update existing records with received letters to set letter_generated = 'Yes'
UPDATE `unclaimed_assets` 
SET `letter_generated` = 'Yes' 
WHERE `letter_received` = 'Yes' OR (`letter_file_path` IS NOT NULL AND `letter_file_path` != '');

-- 3. Optimize lookup index
DROP PROCEDURE IF EXISTS AddLookupIndexV2;
DELIMITER //
CREATE PROCEDURE AddLookupIndexV2()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics 
        WHERE table_schema = DATABASE() 
          AND table_name = 'unclaimed_assets' 
          AND index_name = 'idx_status_letter'
    ) THEN
        ALTER TABLE `unclaimed_assets` ADD INDEX `idx_status_letter` (`status`, `letter_generated`, `letter_received`);
    END IF;
END //
DELIMITER ;
CALL AddLookupIndexV2();
DROP PROCEDURE IF EXISTS AddLookupIndexV2;
