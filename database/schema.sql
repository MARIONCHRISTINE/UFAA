-- UFAA Database Schema Setup
-- You can import this file directly into phpMyAdmin:
-- 1. Open phpMyAdmin (http://localhost/phpmyadmin)
-- 2. Click on the "Import" tab at the top
-- 3. Choose this file (database/schema.sql) and click "Go"

-- ============================================================
--  1. Create Database
-- ============================================================
CREATE DATABASE IF NOT EXISTS `ufaa_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `ufaa_db`;

-- ============================================================
--  2. Main Asset Records Table
-- ============================================================
CREATE TABLE IF NOT EXISTS `unclaimed_assets` (
    `record_id`        INT AUTO_INCREMENT PRIMARY KEY,
    `owner_name`       TEXT NULL,
    `id_passport_no`   TEXT NULL,
    `date_of_birth`    TEXT NULL,
    `account_number`   TEXT NULL,
    `last_transaction` TEXT NULL,
    `due_amount`       TEXT NULL,
    `compilation_date` DATE NULL,
    `status`           VARCHAR(50)  DEFAULT 'Unclaimed',
    `letter_received`  VARCHAR(10)  DEFAULT 'No',
    `letter_date`      TEXT NULL,
    `letter_file_path` TEXT NULL,
    `uploaded_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
--  3. Migration: Add columns if upgrading from an older version
--     (Safe to run — only adds if missing)
-- ============================================================
ALTER TABLE `unclaimed_assets`
    ADD COLUMN IF NOT EXISTS `compilation_date` DATE NULL                  AFTER `due_amount`,
    ADD COLUMN IF NOT EXISTS `letter_received`  VARCHAR(10) DEFAULT 'No'  AFTER `status`,
    ADD COLUMN IF NOT EXISTS `letter_date`      TEXT NULL                  AFTER `letter_received`,
    ADD COLUMN IF NOT EXISTS `letter_file_path` TEXT NULL                  AFTER `letter_date`;

-- ============================================================
--  4. Uploaded Files Tracking Table
--     Stores filenames to prevent duplicate uploads
-- ============================================================
CREATE TABLE IF NOT EXISTS `uploaded_files` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `file_name`   VARCHAR(500) NOT NULL UNIQUE,
    `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- 1. Add the composite index safely to speed up the smart re-upload lookups on millions of rows
DROP PROCEDURE IF EXISTS AddLookupIndex;
DELIMITER //
CREATE PROCEDURE AddLookupIndex()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics 
        WHERE table_schema = DATABASE() 
          AND table_name = 'unclaimed_assets' 
          AND index_name = 'idx_lookup'
    ) THEN
        ALTER TABLE `unclaimed_assets` ADD INDEX `idx_lookup` (`owner_name`(100), `id_passport_no`(100));
    END IF;
END //
DELIMITER ;
CALL AddLookupIndex();
DROP PROCEDURE IF EXISTS AddLookupIndex;

-- ============================================================
--  5. One-Time Data Cleaning Migration
--     Run this once on existing databases to:
--       a) Delete rows that have no compilation date (in batches)
--       b) Strip timestamps and format compilation_date to YYYY-MM-DD (in batches)
--       c) Convert the column type to DATE
-- ============================================================

-- a) Delete records that are missing a compilation date (batched to prevent Lock Wait Timeout)
DROP PROCEDURE IF EXISTS BatchDeleteNullCompilationDate;
DELIMITER //
CREATE PROCEDURE BatchDeleteNullCompilationDate()
BEGIN
    DECLARE rows_affected INT DEFAULT 1;
    WHILE rows_affected > 0 DO
        DELETE FROM `unclaimed_assets` 
        WHERE `compilation_date` IS NULL OR TRIM(`compilation_date`) = '' 
        LIMIT 10000;
        SET rows_affected = ROW_COUNT();
    END WHILE;
END //
DELIMITER ;
CALL BatchDeleteNullCompilationDate();
DROP PROCEDURE IF EXISTS BatchDeleteNullCompilationDate;


-- b) Clean up compilation_date: strip timestamps and standardize to YYYY-MM-DD (batched to prevent Lock Wait Timeout)
DROP PROCEDURE IF EXISTS BatchUpdateCompilationDate;
DELIMITER //
CREATE PROCEDURE BatchUpdateCompilationDate()
BEGIN
    DECLARE rows_affected INT DEFAULT 1;
    WHILE rows_affected > 0 DO
        UPDATE `unclaimed_assets`
        SET `compilation_date` = DATE_FORMAT(
            COALESCE(
                STR_TO_DATE(`compilation_date`, '%Y-%m-%d %H:%i:%s'),
                STR_TO_DATE(`compilation_date`, '%Y-%m-%d'),
                STR_TO_DATE(`compilation_date`, '%d/%m/%Y %H:%i:%s'),
                STR_TO_DATE(`compilation_date`, '%d/%m/%Y'),
                STR_TO_DATE(`compilation_date`, '%d-%m-%Y %H:%i:%s'),
                STR_TO_DATE(`compilation_date`, '%d-%m-%Y'),
                STR_TO_DATE(`compilation_date`, '%d-%b-%Y'),
                STR_TO_DATE(`compilation_date`, '%d-%M-%Y'),
                STR_TO_DATE(`compilation_date`, '%d/%b/%Y'),
                STR_TO_DATE(`compilation_date`, '%d/%M/%Y')
            ),
            '%Y-%m-%d'
        )
        WHERE `compilation_date` IS NOT NULL 
          AND TRIM(`compilation_date`) <> '' 
          AND `compilation_date` NOT REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
        LIMIT 10000;
        SET rows_affected = ROW_COUNT();
    END WHILE;
END //
DELIMITER ;
CALL BatchUpdateCompilationDate();
DROP PROCEDURE IF EXISTS BatchUpdateCompilationDate;

-- c) Alter compilation_date column type to DATE (removes timestamp storage permanently)
ALTER TABLE `unclaimed_assets` MODIFY COLUMN `compilation_date` DATE NULL;
