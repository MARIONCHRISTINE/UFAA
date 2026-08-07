-- Most critical: columns used in WHERE, ORDER BY, and filters
ALTER TABLE `unclaimed_assets` ADD INDEX `idx_status`        (`status`);
ALTER TABLE `unclaimed_assets` ADD INDEX `idx_id_number`     (`id_number`);
ALTER TABLE `unclaimed_assets` ADD INDEX `idx_account_no`    (`account_no`);
ALTER TABLE `unclaimed_assets` ADD INDEX `idx_owner_name`    (`owner_name`);
ALTER TABLE `unclaimed_assets` ADD INDEX `idx_letter_gen`    (`letter_generated`);
ALTER TABLE `unclaimed_assets` ADD INDEX `idx_status_letter` (`status`, `letter_generated`); -- composite
