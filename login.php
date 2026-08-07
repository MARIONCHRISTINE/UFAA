<?php
/**
 * UFAA Portal — Login Page
 * Fully connected to MySQL database (admin_users).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config.php';          // defines BASE_PATH
require_once __DIR__ . '/admin/includes/admin_auth.php';

$pageTitle = 'Login — UFAA & Airtel Compliance Portal';
$pdo       = admin_get_pdo();
$errorMsg  = '';
if (!empty($_SESSION['login_notice'])) {
    $errorMsg = $_SESSION['login_notice'];
    unset($_SESSION['login_notice']);
} elseif (isset($_GET['reason']) && $_GET['reason'] === 'timeout') {
    $errorMsg = 'Your session has expired due to inactivity. Please log in again.';
}
$username  = '';
$role      = 'compliance_officer';

// Redirect if already logged in
if (!empty($_SESSION['admin_logged_in']) && !empty($_SESSION['admin_user'])) {
    $userRole = $_SESSION['admin_user']['role'] ?? 'compliance_officer';
    header('Location: ' . BASE_PATH . ($userRole === 'compliance_admin' ? '/admin/index.php' : '/index.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = trim($_POST['role'] ?? 'compliance_officer');

    if (empty($username) || empty($password)) {
        $errorMsg = 'Please enter your username/email and password.';
    } else {
        try {
            // Find user by username or email
            $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE (username = ? OR email = ?) LIMIT 1");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();

            $maxAttempts = (int)admin_get_setting('max_login_attempts', '5');

            if (!$user) {
                $errorMsg = 'Invalid credentials. User not found.';
                log_activity('login_failed', "Failed login attempt for '$username': User not found");
            } elseif ((int)$user['is_active'] !== 1) {
                $errorMsg = 'Your account has been locked or deactivated. Please contact an Administrator to reactivate.';
                log_activity('login_failed', "Failed login attempt for '{$user['username']}': Account is locked/deactivated", (int)$user['id']);
            } elseif (!password_verify($password, $user['password_hash'])) {
                $newFailed = (int)($user['failed_login_attempts'] ?? 0) + 1;
                if ($newFailed >= $maxAttempts) {
                    $pdo->prepare("UPDATE admin_users SET is_active = 0, failed_login_attempts = ? WHERE id = ?")->execute([$newFailed, $user['id']]);
                    $errorMsg = "Your account has been locked after {$maxAttempts} consecutive failed login attempts. Please contact an Administrator to reactivate.";
                    log_activity('user_status_change', "Account for '{$user['username']}' auto-locked after {$maxAttempts} failed login attempts", (int)$user['id']);
                } else {
                    $pdo->prepare("UPDATE admin_users SET failed_login_attempts = ? WHERE id = ?")->execute([$newFailed, $user['id']]);
                    $remaining = $maxAttempts - $newFailed;
                    $errorMsg = "Incorrect password. Warning: {$remaining} attempt(s) remaining before your account is locked.";
                    log_activity('login_failed', "Failed login attempt for '{$user['username']}': Incorrect password (Attempt {$newFailed}/{$maxAttempts})", (int)$user['id']);
                }
            } else {
                // Verify selected tab role against user's actual assigned DB roles
                $dbRole       = strtolower($user['role'] ?? '');
                $selectedRole = in_array($role, ['compliance_admin', 'compliance_officer']) ? $role : 'compliance_officer';

                $hasAdminAccess   = (strpos($dbRole, 'admin') !== false || $dbRole === 'both');
                $hasOfficerAccess = (strpos($dbRole, 'officer') !== false || $dbRole === 'both');

                if ($selectedRole === 'compliance_admin' && !$hasAdminAccess) {
                    $errorMsg = 'Access denied. You do not have Compliance Administrator privileges. Please select the Compliance Officer tab to sign in.';
                    log_activity('login_failed', "Failed login attempt for '{$user['username']}': Access denied for Admin portal", (int)$user['id']);
                } elseif ($selectedRole === 'compliance_officer' && !$hasOfficerAccess) {
                    $errorMsg = 'Access denied. Your account is registered as a Compliance Administrator. Please select the Compliance Admin tab to sign in.';
                    log_activity('login_failed', "Failed login attempt for '{$user['username']}': Access denied for Officer portal", (int)$user['id']);
                } else {
                    // Reset failed login attempts & update last_login
                    $upd = $pdo->prepare("UPDATE admin_users SET failed_login_attempts = 0, last_login = NOW() WHERE id = ?");
                    $upd->execute([$user['id']]);

                    // Establish session with selected role mode
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_user']      = [
                        'id'       => (int)$user['id'],
                        'fullname' => $user['fullname'] ?: $user['username'],
                        'username' => $user['username'],
                        'email'    => $user['email'],
                        'role'     => $selectedRole,
                    ];

                    log_activity('login', "User signed in: {$user['username']} as {$selectedRole}", (int)$user['id']);

                    // Redirect to chosen dashboard based on tab selection
                    if ($selectedRole === 'compliance_admin') {
                        header('Location: ' . BASE_PATH . '/admin/index.php');
                    } else {
                        header('Location: ' . BASE_PATH . '/index.php');
                    }
                    exit;
                }
            }
        } catch (Exception $e) {
            $errorMsg = 'Authentication system error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <!-- base href: ensures assets/links resolve from app root regardless of subfolder -->
    <base href="<?= BASE_PATH !== '' ? '/' . ltrim(BASE_PATH, '/') . '/' : '/' ?>">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Main portal CSS -->
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        body.auth-page {
            background: linear-gradient(135deg, #0f1e30 0%, #1e3a5f 50%, #0f1e30 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 1.5rem 1rem;
            margin: 0;
            overflow-y: auto;
            box-sizing: border-box;
        }

        .auth-card {
            width: 100%;
            max-width: 560px;
            max-height: calc(100vh - 3rem);
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 25px 65px rgba(0, 0, 0, 0.42);
            border: 1px solid rgba(255, 255, 255, 0.12);
            display: flex;
            flex-direction: column;
            margin: auto;
            overflow: hidden;
        }

        .auth-header.top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.1rem 1.8rem;
            background: linear-gradient(135deg, #CC0000 0%, #990000 100%);
            box-shadow: 0 4px 20px rgba(204, 0, 0, 0.3);
            color: #ffffff;
            border-radius: 0;
            margin-bottom: 0;
            position: relative;
            flex-shrink: 0;
        }

        .auth-header .header-brand {
            display: flex;
            align-items: center;
            gap: 0.85rem;
        }

        .auth-header .brand-logo-icon {
            background: rgba(255, 255, 255, 0.18);
            border: 2px solid rgba(255, 255, 255, 0.45);
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 900;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.15);
            flex-shrink: 0;
        }

        .auth-header .brand-text h1 {
            font-size: 1.25rem;
            font-weight: 800;
            margin: 0;
            letter-spacing: -0.3px;
        }

        .auth-header .brand-text p {
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.85);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-top: 2px;
            margin-bottom: 0;
        }

        .header-brand-divider {
            width: 1px;
            height: 40px;
            background: linear-gradient(to bottom, transparent, rgba(255,255,255,0.4), transparent);
            flex-shrink: 0;
            margin: 0 0.75rem;
        }

        .auth-header .header-right {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
        }

        .header-airtel-logo-wrap {
            background: #ffffff;
            border-radius: 8px;
            padding: 4px 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.18);
        }

        .header-airtel-logo {
            height: 28px;
            width: auto;
            object-fit: contain;
        }

        .header-airtel-label {
            font-size: 0.65rem;
            color: rgba(255, 255, 255, 0.85);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 600;
        }

        .auth-body {
            padding: 1.6rem 2rem 1.6rem;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow-y: auto;
        }

        .auth-body::-webkit-scrollbar {
            width: 6px;
        }

        .auth-body::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }

        .auth-body::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .auth-body::-webkit-scrollbar-thumb:hover {
            background: #CC0000;
        }

        .auth-card-title {
            text-align: center;
            margin-bottom: 1.1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .auth-card-title h2 {
            font-size: 1.35rem;
            font-weight: 800;
            color: #1e3a5f;
            margin: 0;
        }

        .auth-card-title p {
            font-size: 0.84rem;
            color: #64748b;
            margin-top: 3px;
            margin-bottom: 0;
        }

        .alert-box {
            padding: 0.7rem 1rem;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.65rem;
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .auth-role-tabs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            background: #f1f5f9;
            padding: 5px;
            border-radius: 10px;
            margin-bottom: 1.3rem;
        }

        .role-tab-btn {
            background: transparent;
            border: none;
            padding: 0.65rem 0.5rem;
            font-size: 0.84rem;
            font-weight: 700;
            color: #64748b;
            border-radius: 7px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }

        .role-tab-btn.active {
            background: #ffffff;
            color: #CC0000;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.09);
        }

        .auth-form-group {
            margin-bottom: 1.1rem;
        }

        .auth-form-group label {
            display: block;
            font-size: 0.84rem;
            font-weight: 700;
            color: #1e3a5f;
            margin-bottom: 0.4rem;
        }

        .input-with-icon {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-with-icon i.field-icon {
            position: absolute;
            left: 0.95rem;
            color: #94a3b8;
            font-size: 0.95rem;
        }

        .input-with-icon input,
        .input-with-icon select {
            width: 100%;
            padding: 0.72rem 0.95rem 0.72rem 2.6rem;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            font-size: 0.88rem;
            color: #1e293b;
            background: #f8fafc;
            transition: all 0.2s;
        }

        .input-with-icon input:focus,
        .input-with-icon select:focus {
            outline: none;
            border-color: #CC0000;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(204, 0, 0, 0.12);
        }

        .toggle-password {
            position: absolute;
            right: 0.85rem;
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 0.9rem;
            padding: 0.3rem;
        }

        .toggle-password:hover { color: #64748b; }

        .auth-meta-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.84rem;
            margin-bottom: 1.3rem;
        }

        .remember-label {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            color: #475569;
            cursor: pointer;
        }

        .remember-label input {
            accent-color: #CC0000;
            width: 16px;
            height: 16px;
        }

        .forgot-link {
            color: #CC0000;
            text-decoration: none;
            font-weight: 700;
        }

        .forgot-link:hover { text-decoration: underline; }

        .btn-auth-primary {
            width: 100%;
            padding: 0.88rem;
            background: linear-gradient(135deg, #CC0000 0%, #a80000 100%);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 0.98rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 5px 16px rgba(204, 0, 0, 0.32);
            transition: all 0.25s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
        }

        .btn-auth-primary:hover {
            background: linear-gradient(135deg, #b30000 0%, #880000 100%);
            box-shadow: 0 7px 20px rgba(204, 0, 0, 0.42);
            transform: translateY(-1px);
        }

        .auth-footer {
            text-align: center;
            margin-top: 1.1rem;
            padding-top: 0.9rem;
            border-top: 1px solid #f1f5f9;
            font-size: 0.84rem;
            color: #64748b;
        }

        .auth-footer a {
            color: #CC0000;
            font-weight: 700;
            text-decoration: none;
        }

        .auth-footer a:hover { text-decoration: underline; }

        /* ── Responsive Media Queries ── */
        @media (max-width: 768px) {
            body.auth-page {
                padding: 1rem 0.5rem;
            }
            .auth-header.top-header {
                flex-direction: column;
                text-align: center;
                gap: 0.75rem;
                padding: 1.1rem 1.2rem;
            }
            .header-brand-divider {
                display: none;
            }
            .auth-header .header-brand {
                flex-direction: row;
                justify-content: center;
            }
            .auth-header .header-right {
                align-items: center;
            }
            .auth-body {
                padding: 2rem 1.5rem;
            }
        }

        @media (max-width: 480px) {
            body.auth-page {
                padding: 0.75rem 0.5rem;
            }
            .auth-card {
                border-radius: 16px;
            }
            .auth-header.top-header {
                padding: 1.2rem 1rem;
                gap: 0.75rem;
            }
            .auth-header .brand-logo-icon {
                width: 44px;
                height: 44px;
                font-size: 1.4rem;
                border-radius: 10px;
            }
            .auth-header .brand-text h1 {
                font-size: 1.2rem;
            }
            .auth-header .brand-text p {
                font-size: 0.68rem;
            }
            .header-airtel-logo {
                height: 26px;
            }
            .header-airtel-label {
                font-size: 0.65rem;
            }
            .auth-body {
                padding: 1.4rem 1.1rem;
            }
            .auth-card-title h2 {
                font-size: 1.25rem;
            }
            .auth-role-tabs {
                grid-template-columns: 1fr;
                gap: 0.4rem;
            }
            .role-tab-btn {
                padding: 0.65rem 0.5rem;
                font-size: 0.82rem;
            }
            .auth-meta-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }
            .input-with-icon input {
                font-size: 16px; /* Avoid mobile browser zoom */
                padding: 0.8rem 0.9rem 0.8rem 2.6rem;
            }
            .btn-auth-primary {
                padding: 0.9rem;
                font-size: 0.95rem;
            }
        }
    </style>
</head>
<body class="auth-page">

<div class="auth-card">
    
    <!-- Top Header Bar -->
    <header class="auth-header top-header">
        <div class="header-brand">
            <div class="brand-logo-icon">U</div>
            <div class="brand-text">
                <h1>UFAA Portal</h1>
                <p>Unclaimed Financial Assets Authority</p>
            </div>
        </div>

        <div class="header-brand-divider"></div>

        <div class="header-right">
            <div class="header-airtel-logo-wrap">
                <img src="logo.png" alt="Airtel Logo" class="header-airtel-logo">
            </div>
            <span class="header-airtel-label">Airtel Internal Compliance Portal</span>
        </div>
    </header>

    <div class="auth-body">
        
        <div class="auth-card-title">
            <h2>Portal Sign In</h2>
            <p>Access your UFAA compliance account</p>
        </div>

        <?php if ($errorMsg !== ''): ?>
            <div class="alert-box">
                <i class="fa-solid fa-circle-exclamation" style="font-size:1.1rem;"></i>
                <span><?= htmlspecialchars($errorMsg) ?></span>
            </div>
        <?php endif; ?>

        <!-- Role Quick Selection Tabs -->
        <div class="auth-role-tabs">
            <button type="button" class="role-tab-btn <?= $role === 'compliance_officer' ? 'active' : '' ?>" onclick="selectRole('compliance_officer', this)">
                <i class="fa-solid fa-user-check"></i> Compliance Officer
            </button>
            <button type="button" class="role-tab-btn <?= $role === 'compliance_admin' ? 'active' : '' ?>" onclick="selectRole('compliance_admin', this)">
                <i class="fa-solid fa-shield-halved"></i> Compliance Admin
            </button>
        </div>

        <form method="POST" action="login.php">
            <input type="hidden" name="role" id="selected_role" value="<?= htmlspecialchars($role) ?>">

            <!-- Username or Email -->
            <div class="auth-form-group">
                <label for="username">Username or Email</label>
                <div class="input-with-icon">
                    <i class="fa-solid fa-user field-icon"></i>
                    <input type="text" name="username" id="username" placeholder="e.g. jwanjiku or officer@airtel.com" value="<?= htmlspecialchars($username) ?>" required autocomplete="username">
                </div>
            </div>

            <!-- Password -->
            <div class="auth-form-group">
                <label for="password">Password</label>
                <div class="input-with-icon">
                    <i class="fa-solid fa-lock field-icon"></i>
                    <input type="password" name="password" id="password" placeholder="Enter your password" required autocomplete="current-password">
                    <button type="button" class="toggle-password" onclick="togglePassVisibility('password', this)">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
            </div>

            <!-- Remember Me & Forgot Password -->
            <div class="auth-meta-row">
                <label class="remember-label">
                    <input type="checkbox" name="remember" value="1">
                    <span>Remember me</span>
                </label>
                <a href="#" onclick="alert('For password resets, please contact your Compliance Administrator.'); return false;" class="forgot-link">Forgot password?</a>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="btn-auth-primary">
                <i class="fa-solid fa-right-to-bracket"></i> Sign In to Portal
            </button>
        </form>

        <div class="auth-footer">
            Don't have an account? <a href="register.php">Register Here</a>
        </div>
    </div>
</div>

<script>
function selectRole(role, btn) {
    document.getElementById('selected_role').value = role;
    document.querySelectorAll('.role-tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

function togglePassVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fa-regular fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fa-regular fa-eye';
    }
}
</script>

</body>
</html>
