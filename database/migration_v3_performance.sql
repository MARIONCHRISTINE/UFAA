-- ============================================================
-- UFAA Database Performance Optimization Migration (v3.0)
-- Speeds up searching, filtering, and sorting from 5 minutes to milliseconds!
-- Run this script in phpMyAdmin (http://localhost/phpmyadmin)
-- ============================================================

USE `ufaa_db`;

-- 1. Modify column data types to VARCHAR(500) for fast in-memory indexing & complete Excel tolerance
ALTER TABLE `unclaimed_assets`
    MODIFY COLUMN `owner_name`      VARCHAR(500) NULL,
    MODIFY COLUMN `id_passport_no`  VARCHAR(500) NULL,
    MODIFY COLUMN `account_number`  VARCHAR(500) NULL;

-- 2. Add B-Tree & Full-Text indexes for search columns
DROP PROCEDURE IF EXISTS AddPerformanceIndexes;
DELIMITER //
CREATE PROCEDURE AddPerformanceIndexes()
BEGIN
    -- Owner Name Index
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'unclaimed_assets' AND index_name = 'idx_owner_name') THEN
        ALTER TABLE `unclaimed_assets` ADD INDEX `idx_owner_name` (`owner_name`(191));
    END IF;

    -- ID / Passport Number Index
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'unclaimed_assets' AND index_name = 'idx_id_passport') THEN
        ALTER TABLE `unclaimed_assets` ADD INDEX `idx_id_passport` (`id_passport_no`(191));
    END IF;

    -- Account Number Index
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'unclaimed_assets' AND index_name = 'idx_account_no') THEN
        ALTER TABLE `unclaimed_assets` ADD INDEX `idx_account_no` (`account_number`(191));
    END IF;

    -- Status Index
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'unclaimed_assets' AND index_name = 'idx_status') THEN
        ALTER TABLE `unclaimed_assets` ADD INDEX `idx_status` (`status`);
    END IF;

    -- Compilation Date Index
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'unclaimed_assets' AND index_name = 'idx_compilation_date') THEN
        ALTER TABLE `unclaimed_assets` ADD INDEX `idx_compilation_date` (`compilation_date`);
    END IF;

    -- Full-Text Index on Owner Name
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'unclaimed_assets' AND index_name = 'ft_owner_name') THEN
        ALTER TABLE `unclaimed_assets` ADD FULLTEXT INDEX `ft_owner_name` (`owner_name`);
    END IF;
END //
DELIMITER ;

CALL AddPerformanceIndexes();
DROP PROCEDURE IF EXISTS AddPerformanceIndexes;
