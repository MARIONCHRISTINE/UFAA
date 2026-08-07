<?php
/**
 * UFAA Admin AJAX — User Management
 * Handles: list, create, toggle_active, change_role, reset_password
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/admin_auth.php';

// Only compliance_admin role can manage users
if (($adminUser['role'] ?? '') !== 'compliance_admin') {
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$pdo    = admin_get_pdo();
$action = trim($_POST['action'] ?? $_GET['action'] ?? 'list');

if (!$pdo) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }

try {
    switch ($action) {

        // ── LIST ─────────────────────────────────────────────────────
        case 'list':
            $search = trim($_GET['search'] ?? '');
            $where  = $search ? "WHERE username LIKE :s OR fullname LIKE :s2 OR email LIKE :s3" : '';
            $stmt   = $pdo->prepare("SELECT id, username, fullname, email, role, is_active, created_at, last_login FROM admin_users $where ORDER BY created_at DESC");
            if ($search) {
                $stmt->bindValue(':s',  '%' . $search . '%');
                $stmt->bindValue(':s2', '%' . $search . '%');
                $stmt->bindValue(':s3', '%' . $search . '%');
            }
            $stmt->execute();
            echo json_encode(['success' => true, 'users' => $stmt->fetchAll()]);
            break;

        // ── CREATE ───────────────────────────────────────────────────
        case 'create':
            $username = trim($_POST['username'] ?? '');
            $fullname = trim($_POST['fullname'] ?? '');
            $email    = trim($_POST['email']    ?? '');
            $password = trim($_POST['password'] ?? '');
            $role     = in_array($_POST['role'] ?? '', ['compliance_admin','compliance_officer','both']) ? $_POST['role'] : 'compliance_officer';

            if (!$username || !$password) {
                echo json_encode(['success' => false, 'error' => 'Username and password required']);
                break;
            }

            // Enforce Unique Username Check
            $chkUser = $pdo->prepare("SELECT id FROM admin_users WHERE username = ?");
            $chkUser->execute([$username]);
            if ($chkUser->fetch()) {
                echo json_encode(['success' => false, 'error' => "Username '$username' is already taken. Please choose a unique username."]);
                break;
            }

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare(
                "INSERT INTO admin_users (username, fullname, email, password_hash, role) VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$username, $fullname, $email, $hash, $role]);
            $newId = (int)$pdo->lastInsertId();

            $roleLabel = ($role === 'compliance_admin') ? 'Compliance Admin' : (($role === 'both') ? 'Both (Admin & Officer)' : 'Compliance Officer');
            $nameLabel = $fullname ? "$username ($fullname)" : $username;
            log_activity('user_created', "Created new user '$nameLabel' with role '$roleLabel'");
            echo json_encode(['success' => true, 'id' => $newId]);
            break;

        // ── TOGGLE ACTIVE ────────────────────────────────────────────
        case 'toggle_active':
            $userId = (int)($_POST['user_id'] ?? 0);
            if (!$userId) { echo json_encode(['success' => false, 'error' => 'Invalid user']); break; }

            // Don't allow self-deactivation
            if ($userId === (int)($adminUser['id'] ?? 0)) {
                echo json_encode(['success' => false, 'error' => 'Cannot deactivate your own account']);
                break;
            }

            $chk = $pdo->prepare("SELECT username, fullname, is_active FROM admin_users WHERE id = ?");
            $chk->execute([$userId]);
            $targetUser = $chk->fetch();

            if (!$targetUser) {
                echo json_encode(['success' => false, 'error' => 'User not found']);
                break;
            }

            $newStatus = 1 - (int)$targetUser['is_active'];
            $pdo->prepare("UPDATE admin_users SET is_active = ?, failed_login_attempts = 0 WHERE id = ?")->execute([$newStatus, $userId]);

            $statusLabel = $newStatus ? 'unsuspended' : 'suspended';
            $nameLabel   = $targetUser['fullname'] ? "{$targetUser['username']} ({$targetUser['fullname']})" : $targetUser['username'];
            log_activity('user_status_change', "User '$nameLabel' was $statusLabel");
            echo json_encode(['success' => true, 'is_active' => $newStatus]);
            break;

        // ── CHANGE ROLE ──────────────────────────────────────────────
        case 'change_role':
            $userId  = (int)($_POST['user_id'] ?? 0);
            $newRole = in_array($_POST['role'] ?? '', ['compliance_admin','compliance_officer','both']) ? $_POST['role'] : null;

            if (!$userId || !$newRole) { echo json_encode(['success' => false, 'error' => 'Invalid params']); break; }

            $chk = $pdo->prepare("SELECT username, fullname FROM admin_users WHERE id = ?");
            $chk->execute([$userId]);
            $targetUser = $chk->fetch();

            if (!$targetUser) {
                echo json_encode(['success' => false, 'error' => 'User not found']);
                break;
            }

            $pdo->prepare("UPDATE admin_users SET role = ? WHERE id = ?")->execute([$newRole, $userId]);

            $roleLabel = ($newRole === 'compliance_admin') ? 'Compliance Admin' : (($newRole === 'both') ? 'Both (Admin & Officer)' : 'Compliance Officer');
            $nameLabel = $targetUser['fullname'] ? "{$targetUser['username']} ({$targetUser['fullname']})" : $targetUser['username'];
            log_activity('user_role_change', "Changed role of user '$nameLabel' to '$roleLabel'");
            echo json_encode(['success' => true]);
            break;

        // ── DELETE ───────────────────────────────────────────────────
        case 'delete':
            $userId = (int)($_POST['user_id'] ?? 0);
            if (!$userId || $userId === (int)($adminUser['id'] ?? 0)) {
                echo json_encode(['success' => false, 'error' => 'Cannot delete this account']);
                break;
            }

            $chk = $pdo->prepare("SELECT username, fullname FROM admin_users WHERE id = ?");
            $chk->execute([$userId]);
            $targetUser = $chk->fetch();

            $nameLabel = $targetUser ? ($targetUser['fullname'] ? "{$targetUser['username']} ({$targetUser['fullname']})" : $targetUser['username']) : "ID #$userId";

            $pdo->prepare("DELETE FROM admin_users WHERE id = ?")->execute([$userId]);
            log_activity('user_deleted', "Deleted user account '$nameLabel'");
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
