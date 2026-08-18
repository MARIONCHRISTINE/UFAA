<?php
/**
 * UFAA Admin AJAX — Reveal Excel Unprotect Password
 * Safely fetches protection_password for an upload session and logs
 * a `password_vault_view` security audit record in `activity_logs`.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/admin_auth.php';

$pdo = admin_get_pdo();
if (!$pdo) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed.']);
    exit;
}

$sessionId = (int)($_POST['session_id'] ?? $_GET['session_id'] ?? 0);

if (!$sessionId) {
    echo json_encode(['success' => false, 'error' => 'Invalid upload session ID.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT `id`, `file_name`, `uploaded_by`, `protection_password`, `record_count`, `uploaded_at` FROM `upload_sessions` WHERE `id` = ?");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();

    if (!$session) {
        echo json_encode(['success' => false, 'error' => 'Upload session record not found.']);
        exit;
    }

    $password = $session['protection_password'] ?: 'N/A';
    $fileName = $session['file_name'];
    $adminUser = $_SESSION['admin_user']['username'] ?? 'Admin';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    // Log security audit event: password_vault_view
    $stmtLog = $pdo->prepare("
        INSERT INTO `activity_logs` (`user_id`, `username`, `action`, `description`, `ip_address`, `created_at`)
        VALUES (NULL, ?, 'password_vault_view', ?, ?, NOW())
    ");
    $stmtLog->execute([
        $adminUser,
        "Admin '{$adminUser}' revealed Excel unprotect password for Upload Session #{$sessionId} ('{$fileName}').",
        $ip
    ]);

    echo json_encode([
        'success'             => true,
        'session_id'          => $sessionId,
        'file_name'           => $fileName,
        'protection_password' => $password,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
