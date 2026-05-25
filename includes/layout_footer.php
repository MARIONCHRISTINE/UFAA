    </main><!-- /.main-content -->

</div><!-- /.page-wrapper -->

<!-- Toast Notifications -->
<div class="toast-container" id="toast-holder"></div>

<!-- Shared Footer -->
<footer class="site-footer">
    <p>&copy; <?= date('Y') ?> Unclaimed Financial Assets Authority (UFAA) — All Rights Reserved.</p>
    <p>Compliance Module &nbsp;|&nbsp; Internal Use Only</p>
</footer>

<!-- Shared JS -->
<?php $jsVersion = file_exists(__DIR__ . '/../assets/js/app.js') ? filemtime(__DIR__ . '/../assets/js/app.js') : time(); ?>
<script src="assets/js/app.js?v=<?= $jsVersion ?>"></script>
</body>
</html>
