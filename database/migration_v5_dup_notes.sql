-- UFAA — Duplicate Notes Table
-- Run this once to store officer notes on confirmed duplicate upload groups.
USE `ufaa_db`;

CREATE TABLE IF NOT EXISTS `duplicate_notes` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `group_key`        VARCHAR(500) NOT NULL COMMENT 'account_number___id___last_transaction___compilation_date',
    `note`             TEXT NOT NULL DEFAULT '',
    `noted_by`         VARCHAR(255) NOT NULL DEFAULT '',
    `reviewed`         TINYINT(1)   NOT NULL DEFAULT 0,
    `reviewed_by`      VARCHAR(255)          DEFAULT NULL,
    `reviewed_at`      DATETIME              DEFAULT NULL,
    `noted_at`         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_group_key` (`group_key`(400))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- If the table already exists (older install), add new columns safely:
ALTER TABLE `duplicate_notes`
    ADD COLUMN IF NOT EXISTS `reviewed`    TINYINT(1)  NOT NULL DEFAULT 0        AFTER `noted_by`,
    ADD COLUMN IF NOT EXISTS `reviewed_by` VARCHAR(255)          DEFAULT NULL     AFTER `reviewed`,
    ADD COLUMN IF NOT EXISTS `reviewed_at` DATETIME              DEFAULT NULL     AFTER `reviewed_by`;
