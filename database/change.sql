-- 1. Fix sandbox_drafts status to allow 'flagged' for duplicate review workflows
ALTER TABLE `sandbox_drafts` 
  MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'active';

-- 2. Add performance indexes for letter generation & status checks on millions of records
ALTER TABLE `unclaimed_assets` 
  ADD INDEX `idx_letter_gen` (`letter_generated`),
  ADD INDEX `idx_status_letter` (`status`, `letter_generated`, `letter_received`);

ALTER TABLE `unclaimed_assets` 
  MODIFY COLUMN `date_of_birth` VARCHAR(100) DEFAULT NULL;

