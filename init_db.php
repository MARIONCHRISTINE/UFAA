<?php
/**
 * UFAA - Database Initialization Script (Self-Healing)
 * Safely creates the database and table if they do not exist.
 * Automatically checks and migrates columns if table already exists.
 */

require_once 'config.php';

header('Content-Type: application/json');

try {
    // 1. Create database if not exists
    $pdo_init->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    
    // 2. Connect to the database
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    // 3. Create the unclaimed_assets table if it doesn't exist
    $tableQuery = "
        CREATE TABLE IF NOT EXISTS `unclaimed_assets` (
            `record_id` INT AUTO_INCREMENT PRIMARY KEY,
            `owner_name` TEXT NULL,
            `id_passport_no` TEXT NULL,
            `date_of_birth` TEXT NULL,
            `account_number` TEXT NULL,
            `last_transaction` TEXT NULL,
            `due_amount` TEXT NULL,
            `status` VARCHAR(50) DEFAULT 'Unclaimed',
            `letter_received` VARCHAR(10) DEFAULT 'No',
            `letter_date` TEXT NULL,
            `letter_file_path` TEXT NULL,
            `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    
    $pdo->exec($tableQuery);

    // 3b. Create the uploaded_files tracking table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `uploaded_files` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `file_name` VARCHAR(500) NOT NULL UNIQUE,
            `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 4. Migration Check (Self-Healing): 
    // If the table already existed but was missing our new letter columns, add them dynamically.
    $existingColumns = [];
    $columnsQuery = $pdo->query("SHOW COLUMNS FROM `unclaimed_assets`");
    while ($col = $columnsQuery->fetch()) {
        $existingColumns[] = strtolower($col['Field']);
    }

    $migrationsDone = [];

    if (!in_array('letter_received', $existingColumns)) {
        $pdo->exec("ALTER TABLE `unclaimed_assets` ADD COLUMN `letter_received` VARCHAR(10) DEFAULT 'No' AFTER `status`");
        $migrationsDone[] = 'Added "letter_received" column';
    }

    if (!in_array('letter_date', $existingColumns)) {
        $pdo->exec("ALTER TABLE `unclaimed_assets` ADD COLUMN `letter_date` TEXT NULL AFTER `letter_received`");
        $migrationsDone[] = 'Added "letter_date" column';
    }

    if (!in_array('letter_file_path', $existingColumns)) {
        $pdo->exec("ALTER TABLE `unclaimed_assets` ADD COLUMN `letter_file_path` TEXT NULL AFTER `letter_date`");
        $migrationsDone[] = 'Added "letter_file_path" column';
    }

    $msg = 'Database and tables initialized successfully.';
    if (!empty($migrationsDone)) {
        $msg .= ' Dynamic migrations completed: ' . implode(', ', $migrationsDone);
    }

    echo json_encode([
        'status' => 'success',
        'message' => $msg
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Initialization failed: ' . $e->getMessage()
    ]);
}
