<?php
/**
 * UFAA Portal — Account Registration Page
 * Fully connected to MySQL database (admin_users).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config.php';          // defines BASE_PATH
require_once __DIR__ . '/admin/includes/admin_auth.php';

// ── Compute absolute base URL ─────────────────────────────────────────────────
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script  = $_SERVER['SCRIPT_NAME'] ?? '/UFAA/register.php';
$baseDir = rtrim(dirname($script), '/');
$baseUrl = $scheme . '://' . $host . $baseDir; // e.g. http://localhost/UFAA

$pageTitle  = 'Register — UFAA & Airtel Compliance Portal';
$pdo        = admin_get_pdo();
$errorMsg   = '';
$successMsg = '';

// Form values for sticky input
$fullname = '';
$username = '';
$email    = '';
$role     = 'compliance_officer';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname        = trim($_POST['fullname'] ?? '');
    $username        = trim($_POST['username'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $password        = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $role            = trim($_POST['role'] ?? 'compliance_officer');
    $terms           = isset($_POST['terms']);

    if (empty($fullname) || empty($username) || empty($email) || empty($password)) {
        $errorMsg = 'Please fill in all required fields.';
    } elseif ($password !== $confirmPassword) {
        $errorMsg = 'Passwords do not match. Please re-enter your password.';
    } elseif (strlen($password) < 6) {
        $errorMsg = 'Password must be at least 6 characters long.';
    } elseif (!$terms) {
        $errorMsg = 'You must agree to the UFAA & Airtel Security Policies.';
    } elseif (!in_array($role, ['compliance_officer', 'compliance_admin'])) {
        $role = 'compliance_officer';
    } else {
        try {
            // Check if username or email already registered
            $chk = $pdo->prepare("SELECT id FROM admin_users WHERE username = ? OR email = ?");
            $chk->execute([$username, $email]);
            if ($chk->fetch()) {
                $errorMsg = 'Username or Work Email is already registered. Please sign in or use a different email.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO admin_users (username, fullname, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                $stmt->execute([$username, $fullname, $email, $hash, $role]);
                $newUserId = (int)$pdo->lastInsertId();

                log_activity('user_created', "User registered: {$username} ({$fullname}) as {$role}", $newUserId);

                $successMsg = 'Registration successful! Your account is active. You can now sign in.';
                
                // Clear fields on success
                $fullname = '';
                $username = '';
                $email    = '';
                $role     = 'compliance_officer';
            }
        } catch (Exception $e) {
            $errorMsg = 'Database operation failed: ' . $e->getMessage();
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
            max-width: 620px;
            max-height: calc(100vh - 3rem);
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.42);
            border: 1px solid rgba(255, 255, 255, 0.12);
            margin: auto;
            display: flex;
            flex-direction: column;
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
            padding: 1.4rem 1.8rem 1.4rem;
            overflow-y: auto;
            flex-grow: 1;
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
            margin-bottom: 0.9rem;
            padding-bottom: 0.6rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .auth-card-title h2 {
            font-size: 1.3rem;
            font-weight: 800;
            color: #1e3a5f;
            margin: 0;
        }

        .auth-card-title p {
            font-size: 0.82rem;
            color: #64748b;
            margin-top: 2px;
            margin-bottom: 0;
        }

        .alert-box {
            padding: 0.65rem 0.9rem;
            border-radius: 10px;
            font-size: 0.84rem;
            font-weight: 600;
            margin-bottom: 0.9rem;
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
        }

        .alert-box.error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-box.success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .alert-dismiss {
            margin-left: auto;
            background: none;
            border: none;
            color: inherit;
            opacity: 0.6;
            cursor: pointer;
            font-size: 1.05rem;
            line-height: 1;
            padding: 0 0 0 0.35rem;
            flex-shrink: 0;
        }
        .alert-dismiss:hover { opacity: 1; }

        .auth-form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.9rem;
        }

        .auth-form-group {
            margin-bottom: 0.85rem;
        }

        .auth-form-group label.field-label {
            display: block;
            font-size: 0.82rem;
            font-weight: 700;
            color: #1e3a5f;
            margin-bottom: 0.35rem;
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
            font-size: 0.9rem;
        }

        .input-with-icon input,
        .input-with-icon select {
            width: 100%;
            padding: 0.65rem 0.9rem 0.65rem 2.6rem;
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
            right: 0.8rem;
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 0.85rem;
            padding: 0.25rem;
        }

        .toggle-password:hover { color: #64748b; }

        .role-cards-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.85rem;
            margin-top: 0.15rem;
        }

        .role-option-card {
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            padding: 0.65rem 0.85rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            background: #f8fafc;
        }

        .role-option-card:hover {
            border-color: #94a3b8;
            background: #ffffff;
        }

        .role-option-card.selected {
            border-color: #CC0000;
            background: rgba(204, 0, 0, 0.04);
            box-shadow: 0 3px 10px rgba(204, 0, 0, 0.08);
        }

        .role-option-card input[type="radio"] {
            accent-color: #CC0000;
            width: 16px;
            height: 16px;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .role-option-title {
            font-size: 0.84rem;
            font-weight: 700;
            color: #1e3a5f;
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }

        .role-option-desc {
            font-size: 0.72rem;
            color: #64748b;
            margin-top: 2px;
            line-height: 1.3;
        }

        .terms-row {
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            font-size: 0.78rem;
            color: #475569;
            margin-top: 0.25rem;
            margin-bottom: 0.9rem;
            line-height: 1.35;
            padding: 0.6rem 0.85rem;
            background: #f8fafc;
            border-radius: 9px;
            border: 1px solid #e2e8f0;
        }

        .terms-row input {
            accent-color: #CC0000;
            width: 16px;
            height: 16px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .btn-auth-primary {
            width: 100%;
            padding: 0.85rem;
            background: linear-gradient(135deg, #CC0000 0%, #a80000 100%);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 0.96rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(204, 0, 0, 0.32);
            transition: all 0.25s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-auth-primary:hover {
            background: linear-gradient(135deg, #b30000 0%, #880000 100%);
            box-shadow: 0 6px 18px rgba(204, 0, 0, 0.42);
            transform: translateY(-1px);
        }

        .auth-footer {
            text-align: center;
            margin-top: 0.9rem;
            padding-top: 0.75rem;
            border-top: 1px solid #f1f5f9;
            font-size: 0.82rem;
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
                padding: 1.2rem 1.2rem;
            }
            .role-cards-grid {
                grid-template-columns: 1fr;
                gap: 0.6rem;
            }
        }

        @media (max-width: 600px) {
            .auth-form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
        }

        @media (max-width: 480px) {
            .auth-card {
                border-radius: 14px;
            }
            .auth-header.top-header {
                padding: 1rem 0.85rem;
                gap: 0.6rem;
            }
            .auth-header .brand-logo-icon {
                width: 38px;
                height: 38px;
                font-size: 1.25rem;
                border-radius: 8px;
            }
            .auth-header .brand-text h1 {
                font-size: 1.1rem;
            }
            .auth-header .brand-text p {
                font-size: 0.65rem;
            }
            .header-airtel-logo {
                height: 24px;
            }
            .header-airtel-label {
                font-size: 0.62rem;
            }
            .auth-body {
                padding: 1.1rem 0.95rem;
            }
            .auth-card-title h2 {
                font-size: 1.15rem;
            }
            .input-with-icon input {
                font-size: 16px; /* Avoid mobile browser zoom */
                padding: 0.65rem 0.85rem 0.65rem 2.4rem;
            }
            .terms-row {
                padding: 0.55rem 0.75rem;
                font-size: 0.75rem;
            }
            .btn-auth-primary {
                padding: 0.8rem;
                font-size: 0.9rem;
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
            <h2>Create Account</h2>
            <p>Register for access to the UFAA Compliance Portal</p>
        </div>

        <?php if ($errorMsg !== ''): ?>
            <div class="alert-box error" id="reg-alert-error">
                <i class="fa-solid fa-circle-exclamation" style="font-size:1.05rem;flex-shrink:0;"></i>
                <span><?= htmlspecialchars($errorMsg) ?></span>
                <button class="alert-dismiss" type="button" onclick="dismissAlert('reg-alert-error')" title="Dismiss">&times;</button>
            </div>
        <?php endif; ?>

        <?php if ($successMsg !== ''): ?>
            <div class="alert-box success" id="reg-alert-success">
                <i class="fa-solid fa-circle-check" style="font-size:1.05rem;flex-shrink:0;"></i>
                <span><?= htmlspecialchars($successMsg) ?></span>
                <button class="alert-dismiss" type="button" onclick="dismissAlert('reg-alert-success')" title="Dismiss">&times;</button>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php">
            
            <!-- Full Name -->
            <div class="auth-form-group">
                <label for="fullname" class="field-label">Full Name</label>
                <div class="input-with-icon">
                    <i class="fa-solid fa-id-card field-icon"></i>
                    <input type="text" name="fullname" id="fullname" placeholder="e.g. Jane Wanjiku Doe" value="<?= htmlspecialchars($fullname) ?>" required>
                </div>
            </div>

            <!-- Username & Email Row -->
            <div class="auth-form-row">
                <div class="auth-form-group">
                    <label for="username" class="field-label">Username</label>
                    <div class="input-with-icon">
                        <i class="fa-solid fa-user field-icon"></i>
                        <input type="text" name="username" id="username" placeholder="jwanjiku" value="<?= htmlspecialchars($username) ?>" required>
                    </div>
                </div>
                <div class="auth-form-group">
                    <label for="email" class="field-label">Work Email Address</label>
                    <div class="input-with-icon">
                        <i class="fa-solid fa-envelope field-icon"></i>
                        <input type="email" name="email" id="email" placeholder="jane@airtel.com" value="<?= htmlspecialchars($email) ?>" required>
                    </div>
                </div>
            </div>

            <!-- Password & Confirm Password Row -->
            <div class="auth-form-row">
                <div class="auth-form-group">
                    <label for="password" class="field-label">Password</label>
                    <div class="input-with-icon">
                        <i class="fa-solid fa-lock field-icon"></i>
                        <input type="password" name="password" id="password" placeholder="••••••••" required>
                        <button type="button" class="toggle-password" onclick="togglePassVisibility('password', this)">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="auth-form-group">
                    <label for="confirm_password" class="field-label">Confirm Password</label>
                    <div class="input-with-icon">
                        <i class="fa-solid fa-shield-halved field-icon"></i>
                        <input type="password" name="confirm_password" id="confirm_password" placeholder="••••••••" required>
                        <button type="button" class="toggle-password" onclick="togglePassVisibility('confirm_password', this)">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Role Selection (Two Roles: Officer vs Admin) -->
            <div class="auth-form-group">
                <label class="field-label">System Role</label>
                <div class="role-cards-grid">
                    
                    <label class="role-option-card <?= $role === 'compliance_officer' ? 'selected' : '' ?>" onclick="highlightRole(this)">
                        <input type="radio" name="role" value="compliance_officer" <?= $role === 'compliance_officer' ? 'checked' : '' ?>>
                        <div>
                            <div class="role-option-title">
                                <i class="fa-solid fa-user-check" style="color:#2563eb;"></i> Compliance Officer
                            </div>
                            <div class="role-option-desc">Data uploads, record quality checks & letter issuance.</div>
                        </div>
                    </label>

                    <label class="role-option-card <?= $role === 'compliance_admin' ? 'selected' : '' ?>" onclick="highlightRole(this)">
                        <input type="radio" name="role" value="compliance_admin" <?= $role === 'compliance_admin' ? 'checked' : '' ?>>
                        <div>
                            <div class="role-option-title">
                                <i class="fa-solid fa-shield-halved" style="color:#CC0000;"></i> Compliance Admin
                            </div>
                            <div class="role-option-desc">Full governance, activity logs & portal management.</div>
                        </div>
                    </label>

                </div>
            </div>

            <!-- Terms & Privacy -->
            <div class="terms-row">
                <input type="checkbox" name="terms" id="terms" required>
                <label for="terms">I confirm that I am an authorized officer and agree to UFAA &amp; Airtel Security Policies.</label>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="btn-auth-primary">
                <i class="fa-solid fa-user-plus"></i> Register System Account
            </button>
        </form>

        <div class="auth-footer">
            Already have an account? <a href="login.php">Sign In Here</a>
        </div>
    </div>
</div>

<script>
function dismissAlert(id) {
    const el = document.getElementById(id);
    if (el) {
        el.style.transition = 'opacity 0.25s, max-height 0.3s, margin 0.3s, padding 0.3s';
        el.style.opacity = '0';
        el.style.maxHeight = '0';
        el.style.margin = '0';
        el.style.padding = '0';
        el.style.overflow = 'hidden';
        setTimeout(() => el.remove(), 320);
    }
}

function highlightRole(card) {
    document.querySelectorAll('.role-option-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    const radio = card.querySelector('input[type="radio"]');
    if (radio) radio.checked = true;
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
