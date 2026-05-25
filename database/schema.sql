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
