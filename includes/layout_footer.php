    </main><!-- /.main-content -->

</div><!-- /.page-wrapper -->

<!-- Status Selection Modal -->
<div class="popup-overlay" id="status-popup-overlay">
    <div class="popup-card">
        <h3><i class="fa-solid fa-circle-question" style="color:var(--airtel-red)"></i> Select Claim Status</h3>
        <input type="hidden" id="status-popup-record-id">
        <div class="popup-options">
            <label class="popup-option-label" id="label-status-unclaimed">
                <input type="radio" name="status-radio" value="Unclaimed" onchange="updateRadioLabels('status')">
                <span>Unclaimed</span>
            </label>
            <label class="popup-option-label" id="label-status-claimed">
                <input type="radio" name="status-radio" value="Claimed" onchange="updateRadioLabels('status')">
                <span>Claimed</span>
            </label>
        </div>
        <div class="popup-footer">
            <button class="btn-reset" style="padding: 0.5rem 1rem; border-radius: 8px;" onclick="closeStatusPopup()">Cancel</button>
            <button class="btn-filter" style="padding: 0.5rem 1rem; border-radius: 8px;" onclick="saveStatusPopup()">Save changes</button>
        </div>
    </div>
</div>

<!-- Letter Received Selection Modal -->
<div class="popup-overlay" id="letter-popup-overlay">
    <div class="popup-card">
        <h3><i class="fa-solid fa-circle-question" style="color:var(--airtel-red)"></i> Letter Received?</h3>
        <input type="hidden" id="letter-popup-record-id">
        <div class="popup-options">
            <label class="popup-option-label" id="label-letter-no">
                <input type="radio" name="letter-radio" value="No" onchange="updateRadioLabels('letter')">
                <span>No</span>
            </label>
            <label class="popup-option-label" id="label-letter-yes">
                <input type="radio" name="letter-radio" value="Yes" onchange="updateRadioLabels('letter')">
                <span>Yes</span>
            </label>
        </div>
        <div class="popup-footer">
            <button class="btn-reset" style="padding: 0.5rem 1rem; border-radius: 8px;" onclick="closeLetterPopup()">Cancel</button>
            <button class="btn-filter" style="padding: 0.5rem 1rem; border-radius: 8px;" onclick="saveLetterPopup()">Save changes</button>
        </div>
    </div>
</div>

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
