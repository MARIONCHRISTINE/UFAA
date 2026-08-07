<?php
/**
 * UFAA Admin — Shared Layout Footer
 * Close main content + page-wrapper, render footer, load scripts.
 */

// Version busting
$adminJsV     = file_exists(__DIR__ . '/../assets/js/admin.js')        ? filemtime(__DIR__ . '/../assets/js/admin.js')        : time();
$adminChartsV = file_exists(__DIR__ . '/../assets/js/admin-charts.js') ? filemtime(__DIR__ . '/../assets/js/admin-charts.js') : time();
$mainJsV      = file_exists(__DIR__ . '/../../assets/js/app.js')       ? filemtime(__DIR__ . '/../../assets/js/app.js')       : time();
?>
    </main><!-- /.admin-main -->

</div><!-- /.page-wrapper -->

<!-- Toast Notifications -->
<div class="toast-container" id="toast-holder"></div>

<!-- Confirm Dialog Overlay -->
<div class="admin-confirm-overlay" id="admin-confirm-overlay">
    <div class="admin-confirm-card">
        <div class="admin-confirm-icon">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <h3 id="admin-confirm-title">Are you sure?</h3>
        <p id="admin-confirm-message">This action cannot be undone.</p>
        <div class="admin-confirm-actions">
            <button class="btn-admin-outline" id="admin-confirm-cancel">Cancel</button>
            <button class="btn-admin-danger" id="admin-confirm-ok">Confirm</button>
        </div>
    </div>
</div>

<!-- Shared Footer -->
<footer class="site-footer">
    <p>&copy; <?= date('Y') ?> Unclaimed Financial Assets Authority (UFAA) — All Rights Reserved.</p>
    <p>Administration Module &nbsp;|&nbsp; Internal Use Only</p>
</footer>

<!-- Main portal app.js (toast system, shared helpers) -->
<script src="../assets/js/app.js?v=<?= $mainJsV ?>"></script>

<!-- Chart.js CDN (only loaded here, not on main portal) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<!-- Admin shared JS -->
<script src="assets/js/admin.js?v=<?= $adminJsV ?>"></script>

<!-- Admin chart utilities -->
<script src="assets/js/admin-charts.js?v=<?= $adminChartsV ?>"></script>

</body>
</html>
