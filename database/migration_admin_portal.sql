-- ============================================================================
-- UFAA COMPLIANCE PORTAL — ADMIN DASHBOARD MIGRATION SCRIPT
-- Purpose: Adds all admin tables, security settings, and indexes to your
--          existing remote database WITHOUT dumping or altering existing data.
-- ============================================================================

-- 1. ADMIN USERS TABLE
CREATE TABLE IF NOT EXISTS `admin_users` (
    `id`                    INT AUTO_INCREMENT PRIMARY KEY,
    `username`              VARCHAR(100) NOT NULL UNIQUE,
    `fullname`              VARCHAR(250) NOT NULL DEFAULT '',
    `email`                 VARCHAR(200) NOT NULL DEFAULT '',
    `password_hash`         VARCHAR(255) NOT NULL,
    `role`                  VARCHAR(100) NOT NULL DEFAULT 'compliance_officer',
    `is_active`             TINYINT(1) NOT NULL DEFAULT 1,
    `failed_login_attempts` INT NOT NULL DEFAULT 0,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_login`            DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed Initial Default Admin Account (Username: admin | Password: Admin@1234)
INSERT IGNORE INTO `admin_users` (`id`, `username`, `fullname`, `email`, `password_hash`, `role`, `is_active`)
VALUES (1, 'admin', 'System Administrator', 'admin@ufaa.go.ke', '$2y$10$wE95hYp10hT8/r1dG0d9v.u0/7M5oJzE5X/1L9eZ2S3Y4A5B6C7D8', 'compliance_admin', 1);


-- 2. AUDIT LOGS TABLE
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT NULL,
    `username`    VARCHAR(100) NOT NULL DEFAULT 'system',
    `action`      VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `record_id`   INT NULL,
    `ip_address`  VARCHAR(45) NOT NULL DEFAULT '',
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_action`  (`action`),
    INDEX `idx_created` (`created_at`),
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 3. UPLOAD AUDIT SESSIONS TABLE
CREATE TABLE IF NOT EXISTS `upload_sessions` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `uploaded_by`  VARCHAR(100) NOT NULL DEFAULT 'system',
    `file_name`    VARCHAR(500) NOT NULL,
    `record_count` INT NOT NULL DEFAULT 0,
    `status`       ENUM('success','partial','failed') NOT NULL DEFAULT 'success',
    `notes`        TEXT NULL,
    `uploaded_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_uploaded_at` (`uploaded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 4. SYSTEM SETTINGS TABLE
CREATE TABLE IF NOT EXISTS `admin_settings` (
    `setting_key`   VARCHAR(100) PRIMARY KEY,
    `setting_value` TEXT NOT NULL,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed System Default Settings
INSERT IGNORE INTO `admin_settings` (`setting_key`, `setting_value`) VALUES
('portal_label',       'UFAA Compliance Portal'),
('session_timeout',    '120'),
('max_login_attempts', '5'),
('max_upload_mb',      '50'),
('records_per_page',   '50'),
('maintenance_mode',   '0');


-- 5. COLUMN & INDEX ENHANCEMENTS FOR EXISTING `unclaimed_assets` TABLE
-- (Safe to run: Creates missing tracking columns and performance indexes if not present)
SET @dbname = DATABASE();

-- Column: letter_generated
SET @tablename = 'unclaimed_assets';
SET @columnname = 'letter_generated';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  'SELECT 1',
  'ALTER TABLE `unclaimed_assets` ADD COLUMN `letter_generated` VARCHAR(10) DEFAULT "No" AFTER `status`;'
));
PREPARE add_letter_generated FROM @preparedStatement;
EXECUTE add_letter_generated;
DEALLOCATE PREPARE add_letter_generated;

-- Column: letter_generated_date
SET @columnname = 'letter_generated_date';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  'SELECT 1',
  'ALTER TABLE `unclaimed_assets` ADD COLUMN `letter_generated_date` DATETIME NULL AFTER `letter_generated`;'
));
PREPARE add_letter_date FROM @preparedStatement;
EXECUTE add_letter_date;
DEALLOCATE PREPARE add_letter_date;

-- Column: stamped_file_path
SET @columnname = 'stamped_file_path';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  'SELECT 1',
  'ALTER TABLE `unclaimed_assets` ADD COLUMN `stamped_file_path` VARCHAR(500) NULL AFTER `letter_file_path`;'
));
PREPARE add_stamped_path FROM @preparedStatement;
EXECUTE add_stamped_path;
DEALLOCATE PREPARE add_stamped_path;

-- ============================================================================
-- END OF MIGRATION SCRIPT
-- ============================================================================
