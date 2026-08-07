<?php
/**
 * UFAA Admin — Sidebar Navigation Links
 * Included by admin_layout.php. Renders admin-specific nav items.
 * Set $adminActivePage before including this file.
 */
$adminActivePage = $adminActivePage ?? 'dashboard';
?>
<div class="nav-section-label">Admin Panel</div>

<a href="index.php" class="nav-item <?= $adminActivePage === 'dashboard' ? 'active' : '' ?>">
    <i class="fa-solid fa-gauge-high"></i>
    <span>Dashboard</span>
</a>

<a href="users.php" class="nav-item <?= $adminActivePage === 'users' ? 'active' : '' ?>">
    <i class="fa-solid fa-users-gear"></i>
    <span>User Management</span>
</a>

<a href="logs.php" class="nav-item <?= $adminActivePage === 'logs' ? 'active' : '' ?>">
    <i class="fa-solid fa-list-check"></i>
    <span>Activity Logs</span>
</a>

<a href="data_management.php" class="nav-item <?= $adminActivePage === 'data_management' ? 'active' : '' ?>">
    <i class="fa-solid fa-database"></i>
    <span>Data Management</span>
</a>

<a href="reports.php" class="nav-item <?= $adminActivePage === 'reports' ? 'active' : '' ?>">
    <i class="fa-solid fa-chart-bar"></i>
    <span>Reports</span>
</a>

<div class="nav-section-label" style="margin-top:1rem;">System</div>

<a href="settings.php" class="nav-item <?= $adminActivePage === 'settings' ? 'active' : '' ?>">
    <i class="fa-solid fa-sliders"></i>
    <span>Settings</span>
</a>
