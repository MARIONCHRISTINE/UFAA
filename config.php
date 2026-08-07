<?php
/**
 * UFAA - Database Configuration (Root)
 * Sets up connection credentials, configures timezone for Kenya, and establishes PDO.
 * Feel free to modify the DB_PASS or DB_HOST if your XAMPP installation uses custom settings!
 */

// Set default timezone to Kenya
date_default_timezone_set('Africa/Nairobi');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('DB_HOST', '127.0.0.1'); // If your XAMPP uses a custom port, change to e.g., '127.0.0.1:3307'
define('DB_USER', 'root');
define('DB_PASS', '');          // If your MySQL has a password, enter it here!
define('DB_NAME', 'ufaa_db');

// ── App base path (root-relative) ─────────────────────────────────────────────
// Computes the URL subfolder the app lives in.
// e.g.  DOCUMENT_ROOT = C:/xampp/htdocs,  __DIR__ = C:/xampp/htdocs/UFAA  → /UFAA
// e.g.  DOCUMENT_ROOT = C:/xampp/htdocs/UFAA (virtual host)               → ''
if (!defined('BASE_PATH')) {
    $__docRoot  = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\'));
    $__appRoot  = str_replace('\\', '/', rtrim(__DIR__, '/\\'));
    $__basePath = str_replace($__docRoot, '', $__appRoot); // e.g. '/UFAA' or ''
    define('BASE_PATH', $__basePath);                      // root-relative, no trailing slash
    unset($__docRoot, $__appRoot, $__basePath);
}

try {
    // Attempt standard connection to the MySQL server
    $pdo_init = new PDO(
        "mysql:host=" . DB_HOST . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    // Auto-create database if missing
    $pdo_init->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (PDOException $e) {
    die("Database connection failed! Please ensure MySQL is running in your XAMPP Control Panel. Error details: " . $e->getMessage());
}

/**
 * Returns a connection to the specific UFAA database.
 */
function get_db_connection() {
    try {
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
        ensure_ufaa_columns($pdo);
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Self-healing helper: Ensures required columns & high-performance indexes exist
 */
function ensure_ufaa_columns($pdo) {
    if (!$pdo) return;
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM `unclaimed_assets` LIKE 'letter_generated'");
        if ($chk->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `unclaimed_assets` ADD COLUMN `letter_generated` VARCHAR(10) DEFAULT 'No' AFTER `status`");
        }
        $chkDate = $pdo->query("SHOW COLUMNS FROM `unclaimed_assets` LIKE 'letter_generated_date'");
        if ($chkDate->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `unclaimed_assets` ADD COLUMN `letter_generated_date` DATETIME NULL AFTER `letter_generated`");
        }
        $chkStamped = $pdo->query("SHOW COLUMNS FROM `unclaimed_assets` LIKE 'stamped_file_path'");
        if ($chkStamped->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `unclaimed_assets` ADD COLUMN `stamped_file_path` VARCHAR(500) NULL AFTER `letter_file_path`");
        }

        // Automatic Column & Index Optimization for Fast Searching (TEXT -> VARCHAR(500))
        $chkOwnerType = $pdo->query("SHOW COLUMNS FROM `unclaimed_assets` LIKE 'owner_name'")->fetch();
        if ($chkOwnerType && stripos($chkOwnerType['Type'], 'text') !== false) {
            @$pdo->exec("ALTER TABLE `unclaimed_assets` MODIFY COLUMN `owner_name` VARCHAR(500) NULL");
        }
        $chkIdType = $pdo->query("SHOW COLUMNS FROM `unclaimed_assets` LIKE 'id_passport_no'")->fetch();
        if ($chkIdType && stripos($chkIdType['Type'], 'text') !== false) {
            @$pdo->exec("ALTER TABLE `unclaimed_assets` MODIFY COLUMN `id_passport_no` VARCHAR(500) NULL");
        }
        $chkAcctType = $pdo->query("SHOW COLUMNS FROM `unclaimed_assets` LIKE 'account_number'")->fetch();
        if ($chkAcctType && stripos($chkAcctType['Type'], 'text') !== false) {
            @$pdo->exec("ALTER TABLE `unclaimed_assets` MODIFY COLUMN `account_number` VARCHAR(500) NULL");
        }

        $indexes = $pdo->query("SHOW INDEX FROM `unclaimed_assets`")->fetchAll();
        $existingIndexNames = array_column($indexes, 'Key_name');

        if (!in_array('idx_owner_name', $existingIndexNames, true)) {
            @$pdo->exec("CREATE INDEX `idx_owner_name` ON `unclaimed_assets` (`owner_name`(191))");
        }
        if (!in_array('idx_id_passport', $existingIndexNames, true)) {
            @$pdo->exec("CREATE INDEX `idx_id_passport` ON `unclaimed_assets` (`id_passport_no`(191))");
        }
        if (!in_array('idx_account_no', $existingIndexNames, true)) {
            @$pdo->exec("CREATE INDEX `idx_account_no` ON `unclaimed_assets` (`account_number`(191))");
        }
        if (!in_array('idx_status', $existingIndexNames, true)) {
            @$pdo->exec("CREATE INDEX `idx_status` ON `unclaimed_assets` (`status`)");
        }
        if (!in_array('idx_compilation_date', $existingIndexNames, true)) {
            @$pdo->exec("CREATE INDEX `idx_compilation_date` ON `unclaimed_assets` (`compilation_date`)");
        }
        if (!in_array('ft_owner_name', $existingIndexNames, true)) {
            @$pdo->exec("CREATE FULLTEXT INDEX `ft_owner_name` ON `unclaimed_assets` (`owner_name`)");
        }
    } catch (Exception $e) {
        // Table doesn't exist yet or permission limited
    }
}

/**
 * Splits a search string by commas, semicolons, or newlines, and builds
 * an OR-based PDO parameterized SQL clause for the specified field name.
 */
function build_multiple_search_clause($fieldName, $userInput, &$whereClauses, &$params, $paramPrefix) {
    if ($userInput === '') {
        return;
    }
    // Split by commas, semicolons, or newlines
    $terms = preg_split('/[\n\r,;]+/', $userInput);
    $subClauses = [];
    $index = 0;
    foreach ($terms as $term) {
        $term = trim($term);
        if ($term !== '') {
            $paramKey = ':' . $paramPrefix . '_' . $index;
            $subClauses[] = "`$fieldName` LIKE $paramKey";
            $params[$paramKey] = '%' . $term . '%';
            $index++;
        }
    }
    if (!empty($subClauses)) {
        if (count($subClauses) === 1) {
            $whereClauses[] = $subClauses[0];
        } else {
            $whereClauses[] = '(' . implode(' OR ', $subClauses) . ')';
        }
    }
}

