-- 1. Fix sandbox_drafts status to allow 'flagged' for duplicate review workflows
ALTER TABLE `sandbox_drafts` 
  MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'active';

-- 2. Add performance indexes for letter generation & status checks on millions of records
ALTER TABLE `unclaimed_assets` 
  ADD INDEX `idx_letter_gen` (`letter_generated`),
  ADD INDEX `idx_status_letter` (`status`, `letter_generated`, `letter_received`);


ALTER TABLE `unclaimed_assets` 
  MODIFY COLUMN `date_of_birth` VARCHAR(100) DEFAULT NULL;

MySQL / MariaDB (my.cnf / my.ini):
max_allowed_packet = 128M (prevents packet overflow when saving large drafts)
innodb_buffer_pool_size (set to at least 50%–70% of server RAM so index lookups stay in memory)
PHP (php.ini):
upload_max_filesize = 128M
post_max_size = 128M
memory_limit = 1024M
max_execution_time = 600