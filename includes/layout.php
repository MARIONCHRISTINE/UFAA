<?php
/**
 * UFAA - Shared Layout: Top Header + Sidebar Navigation
 * Include this at the top of every page.
 * Set $activePage = 'home' | 'claimed' | 'unclaimed' | 'letters' before including.
 */
$activePage = $activePage ?? 'home';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'UFAA Compliance Portal' ?> — Unclaimed Financial Assets Authority</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Shared Stylesheet -->
    <?php $cssVersion = file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time(); ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= $cssVersion ?>">
</head>
<body>

<!-- ═══════════════════════════════════════════════════════
     TOP HEADER BAR
══════════════════════════════════════════════════════════ -->
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
    <div class="header-right">
        <div class="badge-compliance">
            <i class="fa-solid fa-shield-halved"></i>
            <span>Compliance Portal</span>
        </div>
    </div>
</header>

<!-- ═══════════════════════════════════════════════════════
     PAGE WRAPPER: Sidebar + Main Content
══════════════════════════════════════════════════════════ -->
<div class="page-wrapper">

    <!-- ── SIDEBAR ── -->
    <aside class="sidebar">
        <nav class="sidebar-nav">

            <div class="nav-section-label">Navigation</div>

            <a href="index.php" class="nav-item <?= $activePage === 'home' ? 'active' : '' ?>">
                <i class="fa-solid fa-house"></i>
                <span>Home</span>
            </a>

            <a href="claimed.php" class="nav-item <?= $activePage === 'claimed' ? 'active' : '' ?>">
                <i class="fa-solid fa-circle-check"></i>
                <span>Claimed Assets</span>
            </a>

            <a href="unclaimed.php" class="nav-item <?= $activePage === 'unclaimed' ? 'active' : '' ?>">
                <i class="fa-solid fa-hourglass-half"></i>
                <span>Unclaimed Assets</span>
            </a>

            <a href="letters.php" class="nav-item <?= $activePage === 'letters' ? 'active' : '' ?>">
                <i class="fa-solid fa-envelope-open-text"></i>
                <span>Letters Received</span>
            </a>

        </nav>

        <!-- Logout placeholder at the very bottom -->
        <div class="sidebar-footer">
            <a href="#" class="nav-item nav-logout" onclick="alert('Login system coming soon!'); return false;">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <!-- ── MAIN CONTENT AREA ── -->
    <main class="main-content">
