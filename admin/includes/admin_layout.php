<?php
/**
 * UFAA Admin — Shared Layout Header
 * Reuses the global brand tokens (fonts, CSS vars) from the main portal.
 * Injects admin-specific stylesheets on top.
 *
 * Variables to set BEFORE including this file:
 *   $pageTitle       — page tab title
 *   $adminActivePage — active nav key (dashboard | users | logs | ...)
 */
$pageTitle       = $pageTitle       ?? 'Admin';
$adminActivePage = $adminActivePage ?? 'dashboard';
$bodyExtraClass  = $bodyExtraClass  ?? '';

// Version busting for admin assets
$adminCssV  = file_exists(__DIR__ . '/../assets/css/admin.css')        ? filemtime(__DIR__ . '/../assets/css/admin.css')        : time();
$adminPgCssV= file_exists(__DIR__ . '/../assets/css/admin-pages.css') ? filemtime(__DIR__ . '/../assets/css/admin-pages.css') : time();
$mainCssV   = file_exists(__DIR__ . '/../../assets/css/style.css')    ? filemtime(__DIR__ . '/../../assets/css/style.css')    : time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — UFAA Admin</title>
    <meta name="robots" content="noindex, nofollow">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Main portal base styles (vars, reset, shared components) -->
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= $mainCssV ?>">

    <!-- Admin-specific layout -->
    <link rel="stylesheet" href="assets/css/admin.css?v=<?= $adminCssV ?>">

    <!-- Admin page-level styles -->
    <link rel="stylesheet" href="assets/css/admin-pages.css?v=<?= $adminPgCssV ?>">
</head>
<body class="admin-body<?= $bodyExtraClass ? ' ' . htmlspecialchars($bodyExtraClass) : '' ?>">

<!-- ═══════════════════════════════════════════════════════
     TOP HEADER BAR  (identical brand to main portal)
════════════════════════════════════════════════════════════ -->
<header class="top-header">
    <div class="header-brand">
        <!-- Mobile Hamburger Button -->
        <button class="mobile-menu-toggle" id="mobile-menu-btn" aria-label="Toggle Menu">
            <i class="fa-solid fa-bars"></i>
        </button>
        <div class="brand-logo-icon">U</div>
        <div class="brand-text">
            <h1>UFAA Portal</h1>
            <p>Unclaimed Financial Assets Authority</p>
        </div>
    </div>

    <!-- Divider -->
    <div class="header-brand-divider"></div>

    <!-- Airtel Co-branding -->
    <div class="header-right">
        <div class="header-airtel-logo-wrap">
            <img src="../logo.png" alt="Airtel Logo" class="header-airtel-logo">
        </div>
        <span class="header-airtel-label">Airtel Internal Compliance Portal</span>
    </div>
</header>

<!-- ═══════════════════════════════════════════════════════
     PAGE WRAPPER: Admin Sidebar + Main Content
════════════════════════════════════════════════════════════ -->
<div class="page-wrapper">

    <!-- ── ADMIN SIDEBAR ── -->
    <aside class="sidebar admin-sidebar">
        <nav class="sidebar-nav">
            <?php require_once __DIR__ . '/admin_nav.php'; ?>
        </nav>

        <div class="sidebar-footer">
            <!-- Profile card -->
            <?php
                $__u        = $_SESSION['admin_user'] ?? [];
                $__display  = htmlspecialchars($__u['username'] ?? 'User');
                $__fullname = $__u['fullname'] ?? $__u['username'] ?? 'U';
                $__role     = ($__u['role'] ?? '') === 'compliance_admin' ? 'Compliance Admin' : 'Compliance Officer';
                $__initials = strtoupper(implode('', array_map(function($w){ return $w[0]; }, array_slice(explode(' ', trim($__fullname)), 0, 2))));
            ?>
            <div class="sidebar-profile">
                <div class="sidebar-avatar"><?= $__initials ?: 'U' ?></div>
                <div class="sidebar-profile-info">
                    <span class="sidebar-profile-name"><?= $__display ?></span>
                    <span class="sidebar-profile-role"><?= $__role ?></span>
                </div>
            </div>
            <!-- Logout -->
            <a href="#" class="nav-item nav-logout"
               onclick="adminConfirmLogout(); return false;">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <!-- ── MAIN CONTENT AREA ── -->
    <main class="main-content admin-main">

    <?php
    $mMMode   = admin_get_setting('maintenance_mode', '0');
    $mMBanner = admin_get_setting('maintenance_show_banner', '1');
    $mMMsg    = admin_get_setting('maintenance_banner_msg', '');
    $mMSched  = admin_get_setting('maintenance_scheduled_at', '');

    if ($mMBanner === '1' && ($mMMode === '1' || $mMMode === 'scheduled')):
        $isNow = ($mMMode === '1');
        $bg    = $isNow ? '#fef2f2' : '#fffbeb';
        $border= $isNow ? 'rgba(204,0,0,.25)' : 'rgba(234,88,12,.25)';
        $iconC = $isNow ? '#CC0000' : '#ea580c';
    ?>
    <div style="background:<?= $bg ?>;border:1px solid <?= $border ?>;border-radius:10px;padding:.85rem 1.1rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:.85rem;">
        <i class="fa-solid fa-triangle-exclamation" style="font-size:1.3rem;color:<?= $iconC ?>;"></i>
        <div style="flex:1;font-size:.85rem;line-height:1.5;">
            <strong style="color:<?= $iconC ?>;">
                <?= $isNow ? 'System Under Maintenance Notice:' : 'Scheduled Maintenance Notice:' ?>
            </strong>
            <span><?= htmlspecialchars($mMMsg ?: 'The system is undergoing maintenance.') ?></span>
            <?php if ($mMSched && !$isNow): ?>
            <span style="font-weight:600;margin-left:.35rem;color:var(--text-primary);">(Scheduled for <?= date('d M Y, H:i', strtotime($mMSched)) ?>)</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
