<?php
/**
 * UFAA Admin — Auth Guard & DB Bootstrap
 * - Auto-creates admin_users and activity_logs tables if they don't exist.
 * - Guards all admin pages from unauthorised access.
 * - Provides log_activity() helper used across all admin pages.
 *
 * Include this file at the very top of every admin page.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Resolve project root regardless of where this file is included from ──
define('ADMIN_ROOT', __DIR__ . '/..');
define('PROJECT_ROOT', __DIR__ . '/../..');

require_once PROJECT_ROOT . '/config.php';

// ── Auto-create admin tables ──────────────────────────────────────────────
function admin_ensure_tables(PDO $pdo): void
{
    // admin_users
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `admin_users` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `username`      VARCHAR(100) NOT NULL UNIQUE,
            `email`         VARCHAR(200) NOT NULL DEFAULT '',
            `password_hash` VARCHAR(255) NOT NULL,
            `role`          ENUM('compliance_admin','compliance_officer') NOT NULL DEFAULT 'compliance_officer',
            `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_login`    DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Auto-migrate old enum roles & add fullname if present
    try {
        $chkName = $pdo->query("SHOW COLUMNS FROM `admin_users` LIKE 'fullname'");
        if ($chkName->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `admin_users` ADD COLUMN `fullname` VARCHAR(250) NOT NULL DEFAULT '' AFTER `username`");
        }
        $chkFailed = $pdo->query("SHOW COLUMNS FROM `admin_users` LIKE 'failed_login_attempts'");
        if ($chkFailed->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `admin_users` ADD COLUMN `failed_login_attempts` INT NOT NULL DEFAULT 0 AFTER `is_active`");
        }
        @$pdo->exec("ALTER TABLE `admin_users` MODIFY COLUMN `role` VARCHAR(100) NOT NULL DEFAULT 'compliance_officer'");
        @$pdo->exec("UPDATE `admin_users` SET `role` = 'compliance_admin' WHERE `role` = 'admin'");
        @$pdo->exec("UPDATE `admin_users` SET `role` = 'compliance_officer' WHERE `role` IN ('viewer', 'uploader')");
    } catch (Exception $ex) {}

    // Seed default admin account if table is empty (password: Admin@1234)
    $count = (int) $pdo->query("SELECT COUNT(*) FROM `admin_users`")->fetchColumn();
    if ($count === 0) {
        $hash = password_hash('Admin@1234', PASSWORD_BCRYPT);
        $stmt = $pdo->prepare(
            "INSERT INTO `admin_users` (username, email, password_hash, role)
             VALUES ('admin', 'admin@ufaa.go.ke', ?, 'compliance_admin')"
        );
        $stmt->execute([$hash]);
    }

    // activity_logs
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `activity_logs` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `user_id`     INT NULL,
            `username`    VARCHAR(100) NOT NULL DEFAULT 'system',
            `action`      VARCHAR(100) NOT NULL,
            `description` TEXT NULL,
            `record_id`   INT NULL,
            `ip_address`  VARCHAR(45) NOT NULL DEFAULT '',
            INDEX `idx_action`     (`action`),
            INDEX `idx_created`    (`created_at`),
            INDEX `idx_user_id`    (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Clean up legacy log descriptions with numeric user IDs (e.g. User #4 -> User 'marion')
    try {
        @$pdo->exec("
            UPDATE `activity_logs` al
            JOIN `admin_users` au ON (
                al.description LIKE CONCAT('%user #', au.id, '%') OR
                al.description LIKE CONCAT('%User #', au.id, '%')
            )
            SET al.description = REPLACE(
                REPLACE(al.description, CONCAT('User #', au.id), CONCAT('user \'', COALESCE(NULLIF(au.fullname, ''), au.username), '\'')),
                CONCAT('user #', au.id), CONCAT('user \'', COALESCE(NULLIF(au.fullname, ''), au.username), '\'')
            )
        ");
    } catch (Exception $ex) {}

    // upload_sessions
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `upload_sessions` (
            `id`           INT AUTO_INCREMENT PRIMARY KEY,
            `uploaded_by`  VARCHAR(100) NOT NULL DEFAULT 'system',
            `file_name`    VARCHAR(500) NOT NULL,
            `record_count` INT NOT NULL DEFAULT 0,
            `status`       ENUM('success','partial','failed') NOT NULL DEFAULT 'success',
            `notes`        TEXT NULL,
            `uploaded_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_uploaded_at` (`uploaded_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // admin_settings
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `admin_settings` (
            `setting_key`   VARCHAR(100) PRIMARY KEY,
            `setting_value` TEXT NOT NULL,
            `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Seed default settings
    $settingCount = (int) $pdo->query("SELECT COUNT(*) FROM `admin_settings`")->fetchColumn();
    if ($settingCount === 0) {
        $defaults = [
            ['portal_label',       'UFAA Compliance Portal'],
            ['session_timeout',    '120'],
            ['max_login_attempts', '5'],
            ['max_upload_mb',      '50'],
            ['records_per_page',   '50'],
            ['maintenance_mode',   '0'],
        ];
        $ins = $pdo->prepare(
            "INSERT IGNORE INTO `admin_settings` (setting_key, setting_value) VALUES (?, ?)"
        );
        foreach ($defaults as [$k, $v]) {
            $ins->execute([$k, $v]);
        }
    }
}

// ── Get a PDO connection and bootstrap tables ─────────────────────────────
function admin_get_pdo(): ?PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $pdo = get_db_connection();
    if ($pdo) {
        try { admin_ensure_tables($pdo); } catch (Exception $e) { /* graceful */ }
    }
    return $pdo;
}

// ── Auth guard ────────────────────────────────────────────────────────────
function admin_require_auth(): void
{
    if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_user'])) {
        // BASE_PATH is defined in config.php (already required at file scope above)
        // e.g. '/UFAA' for subfolder, '' for virtual host — never a double slash
        header('Location: ' . BASE_PATH . '/login.php');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        exit;
    }

    if (!in_array($_SESSION['admin_user']['role'] ?? '', ['compliance_admin', 'compliance_officer', 'both'])) {
        $_SESSION['admin_user']['role'] = 'compliance_officer';
    }

    // Inactivity Session Timeout Guard
    $timeoutMin = max(1, (int)admin_get_setting('session_timeout', '120'));
    $timeoutSec = $timeoutMin * 60;

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeoutSec)) {
        $username = $_SESSION['admin_user']['username'] ?? 'User';
        log_activity('logout', "User '$username' logged out automatically due to inactivity ($timeoutMin mins)");

        session_unset();
        session_destroy();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['login_notice'] = "Your session has expired due to {$timeoutMin} minute(s) of inactivity. Please log in again.";
        header('Location: ' . BASE_PATH . '/login.php?reason=timeout');
        exit;
    }

    $_SESSION['last_activity'] = time();
}

// ── Activity logger ───────────────────────────────────────────────────────
function log_activity(
    string $action,
    string $description = '',
    ?int   $recordId    = null
): void {
    $pdo = admin_get_pdo();
    if (!$pdo) return;

    $user     = $_SESSION['admin_user'] ?? ['id' => null, 'username' => 'system'];
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '';

    try {
        $stmt = $pdo->prepare("
            INSERT INTO `activity_logs`
                (user_id, username, action, description, record_id, ip_address)
            VALUES
                (:uid, :uname, :action, :desc, :rid, :ip)
        ");
        $stmt->execute([
            ':uid'    => $user['id'],
            ':uname'  => $user['username'],
            ':action' => $action,
            ':desc'   => $description,
            ':rid'    => $recordId,
            ':ip'     => $ip,
        ]);
    } catch (Exception $e) { /* silent fail */ }
}

// ── Get a single setting value ────────────────────────────────────────────
function admin_get_setting(string $key, string $default = ''): string
{
    $pdo = admin_get_pdo();
    if (!$pdo) return $default;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : $default;
    } catch (Exception $e) { return $default; }
}

// ── Bootstrap guard conditionally ─────────────────────────────────────────
// Automatically guard pages located in /admin/ (except login/register/logout)
$currentScript = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$isAuthPage    = (bool)preg_match('/(login|register|logout)\.php$/i', $currentScript);

if (!$isAuthPage && strpos($currentScript, '/admin/') !== false) {
    admin_require_auth();
}

$adminPdo  = admin_get_pdo();
$adminUser = $_SESSION['admin_user'] ?? null;
