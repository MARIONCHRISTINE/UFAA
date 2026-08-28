-- ============================================================================
-- UFAA PORTAL — MULTI-MILLION DATASET PERFORMANCE INDEXES
-- Purpose: Adds critical BTREE indexes to `unclaimed_assets` so duplicate account
--          queries, status checks, and data management views run in milliseconds on 7M+ records.
-- ============================================================================

-- 1. INDEX ON ACCOUNT NUMBER (Speeds up duplicate account queries & missing account checks)
SET @dbname = DATABASE();
SET @tablename = 'unclaimed_assets';
SET @indexname = 'idx_account_no';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = @indexname) > 0,
  'SELECT 1',
  'ALTER TABLE `unclaimed_assets` ADD INDEX `idx_account_no` (`account_number`);'
));
PREPARE add_idx_account FROM @preparedStatement;
EXECUTE add_idx_account;
DEALLOCATE PREPARE add_idx_account;

-- 2. INDEX ON STATUS (Speeds up Claimed / Unclaimed filter queries)
SET @indexname = 'idx_status';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = @indexname) > 0,
  'SELECT 1',
  'ALTER TABLE `unclaimed_assets` ADD INDEX `idx_status` (`status`);'
));
PREPARE add_idx_status FROM @preparedStatement;
EXECUTE add_idx_status;
DEALLOCATE PREPARE add_idx_status;

-- 3. INDEX ON OWNER NAME (Speeds up owner name searches & missing owner audit)
SET @indexname = 'idx_owner_name';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = @indexname) > 0,
  'SELECT 1',
  'ALTER TABLE `unclaimed_assets` ADD INDEX `idx_owner_name` (`owner_name`);'
));
PREPARE add_idx_owner FROM @preparedStatement;
EXECUTE add_idx_owner;
DEALLOCATE PREPARE add_idx_owner;

-- 4. INDEX ON ID / PASSPORT NUMBER (Speeds up ID lookup & missing ID audit)
SET @indexname = 'idx_id_number';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = @indexname) > 0,
  'SELECT 1',
  'ALTER TABLE `unclaimed_assets` ADD INDEX `idx_id_number` (`id_passport_no`);'
));
PREPARE add_idx_id FROM @preparedStatement;
EXECUTE add_idx_id;
DEALLOCATE PREPARE add_idx_id;

-- 5. INDEX ON LETTER GENERATED
SET @indexname = 'idx_letter_gen';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = @indexname) > 0,
  'SELECT 1',
  'ALTER TABLE `unclaimed_assets` ADD INDEX `idx_letter_gen` (`letter_generated`);'
));
PREPARE add_idx_letter FROM @preparedStatement;
EXECUTE add_idx_letter;
DEALLOCATE PREPARE add_idx_letter;



-- Adds performance index on account_number, status, owner_name, and id_passport_no
ALTER TABLE `unclaimed_assets` ADD INDEX `idx_account_no` (`account_number`);
ALTER TABLE `unclaimed_assets` ADD INDEX `idx_status`     (`status`);
ALTER TABLE `unclaimed_assets` ADD INDEX `idx_owner_name` (`owner_name`);
ALTER TABLE `unclaimed_assets` ADD INDEX `idx_id_number`  (`id_passport_no`);