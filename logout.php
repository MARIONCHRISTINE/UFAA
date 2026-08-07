<?php
/**
 * UFAA Portal — Logout Handler
 * Destroys session cleanly then immediately redirects to login.php.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';          // defines BASE_PATH
require_once __DIR__ . '/admin/includes/admin_auth.php';

// ── Log logout activity (skip gracefully if DB unavailable) ──────────────────
try {
    if (!empty($_SESSION['admin_user'])) {
        $uName = $_SESSION['admin_user']['username'] ?? 'User';
        $uId   = $_SESSION['admin_user']['id'] ?? null;
        log_activity('logout', "User logged out: {$uName}", $uId);
    }
} catch (Throwable $e) { /* DB optional for logout */ }

// ── Destroy the session ───────────────────────────────────────────────────────
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}
session_destroy();

// ── Redirect to login — use BASE_PATH constant (always correct) ───────────────
header('Location: ' . BASE_PATH . '/login.php');
header('Cache-Control: no-store, no-cache, must-revalidate');
exit;
