<?php
/**
 * UFAA Admin — System Settings
 * Manage portal-level configuration & system maintenance scheduling.
 */

require_once __DIR__ . '/includes/admin_auth.php';

$pageTitle       = 'System Settings';
$adminActivePage = 'settings';

$pdo     = admin_get_pdo();
$message = '';
$msgType = '';

// ── Handle form save ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if ($adminUser['role'] !== 'compliance_admin') {
        $message = 'You do not have permission to change settings.';
        $msgType = 'error';
    } else {
        $allowed = [
            'portal_label',
            'session_timeout',
            'max_login_attempts',
            'maintenance_mode',
            'maintenance_scheduled_at',
            'maintenance_banner_msg',
            'maintenance_show_banner'
        ];
        
        $stmt = $pdo->prepare("INSERT INTO admin_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()");

        foreach ($allowed as $key) {
            $val = trim($_POST[$key] ?? '');
            if ($key === 'session_timeout') {
                $val = max(5, (int)$val);
            }
            if ($key === 'max_login_attempts') {
                $val = max(1, (int)$val);
            }
            if ($key === 'maintenance_show_banner') {
                $val = isset($_POST['maintenance_show_banner']) ? '1' : '0';
            }
            $stmt->execute([$key, $val]);
        }

        log_activity('settings_saved', 'System settings & maintenance schedule updated by ' . $adminUser['username']);
        $message = 'Settings saved successfully.';
        $msgType = 'success';
    }
}

// ── Load current settings ─────────────────────────────────
$settings = [];
if ($pdo) {
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM admin_settings")->fetchAll();
        foreach ($rows as $r) $settings[$r['setting_key']] = $r['setting_value'];
    } catch (Exception $e) {}
}

function sv(array $s, string $key, string $default = ''): string {
    return htmlspecialchars($s[$key] ?? $default, ENT_QUOTES);
}

$bodyExtraClass  = 'settings-page';
require_once __DIR__ . '/includes/admin_layout.php';
?>

<!-- ── Page Header ── -->
<div class="admin-page-header">
    <div class="admin-page-header-left">
        <div class="admin-breadcrumb">
            <i class="fa-solid fa-house"></i>
            <a href="index.php">Dashboard</a>
            <i class="fa-solid fa-chevron-right"></i>
            <span>Settings</span>
        </div>
        <h2><i class="fa-solid fa-sliders" style="color:var(--airtel-red);margin-right:.45rem;"></i>System Settings</h2>
        <p>Configure portal options, session security, and schedule system maintenance notifications.</p>
    </div>
</div>

<?php if ($message): ?>
<div class="admin-card admin-section" style="border-left:4px solid <?= $msgType==='success' ? '#16a34a' : '#CC0000' ?>;">
    <p style="color:<?= $msgType==='success' ? '#16a34a' : '#CC0000' ?>;font-weight:600;margin:0;">
        <i class="fa-solid <?= $msgType==='success' ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
        <?= htmlspecialchars($message) ?>
    </p>
</div>
<?php endif; ?>

<form method="POST">
<input type="hidden" name="save_settings" value="1">

<div class="admin-grid-2 admin-section">

    <!-- General & Session Settings -->
    <div class="admin-card">
        <div class="settings-section-title"><i class="fa-solid fa-gear"></i> General &amp; Security</div>

        <div class="settings-row">
            <div class="settings-row-left">
                <label for="portal_label">Portal Label</label>
                <p>Displayed in the browser tab and page header.</p>
            </div>
            <div class="settings-row-right">
                <input type="text" name="portal_label" id="portal_label"
                       class="admin-input" style="width:220px;"
                       value="<?= sv($settings,'portal_label','UFAA Compliance Portal') ?>">
            </div>
        </div>

        <div class="settings-row">
            <div class="settings-row-left">
                <label for="session_timeout">Session Timeout (minutes)</label>
                <p>Auto-logout idle users after this duration of inactivity.</p>
            </div>
            <div class="settings-row-right">
                <input type="number" name="session_timeout" id="session_timeout"
                       class="admin-input" style="width:90px;" min="5" max="480"
                       value="<?= sv($settings,'session_timeout','120') ?>">
            </div>
        </div>

        <div class="settings-row" style="border-bottom:none;">
            <div class="settings-row-left">
                <label for="max_login_attempts">Max Failed Login Attempts</label>
                <p>Auto-lock account after this many consecutive failed login attempts. Requires Administrator reactivation.</p>
            </div>
            <div class="settings-row-right">
                <input type="number" name="max_login_attempts" id="max_login_attempts"
                       class="admin-input" style="width:90px;" min="1" max="50"
                       value="<?= sv($settings,'max_login_attempts','5') ?>">
            </div>
        </div>
    </div>

    <!-- System Maintenance & Notifications -->
    <div class="admin-card">
        <div class="settings-section-title"><i class="fa-solid fa-triangle-exclamation"></i> System Maintenance Scheduling</div>

        <!-- Maintenance Status Select -->
        <div class="settings-row">
            <div class="settings-row-left">
                <label for="maintenance_mode">Maintenance Status</label>
                <p>Choose whether system is operational, scheduled for maintenance, or currently under maintenance.</p>
            </div>
            <div class="settings-row-right">
                <?php $mMode = $settings['maintenance_mode'] ?? '0'; ?>
                <select name="maintenance_mode" id="maintenance_mode" class="admin-select" style="width:220px;" onchange="toggleMaintFields()">
                    <option value="0"         <?= $mMode === '0'         ? 'selected' : '' ?>>Off (Normal Operation)</option>
                    <option value="scheduled" <?= $mMode === 'scheduled' ? 'selected' : '' ?>>Scheduled Maintenance</option>
                    <option value="1"         <?= $mMode === '1'         ? 'selected' : '' ?>>Active Now (Under Maintenance)</option>
                </select>
            </div>
        </div>

        <!-- Scheduled Date & Time Picker -->
        <div class="settings-row" id="row-scheduled-at" style="<?= $mMode === 'scheduled' ? '' : 'display:none;' ?>">
            <div class="settings-row-left">
                <label for="maintenance_scheduled_at">Scheduled Date &amp; Time</label>
                <p>Specify when maintenance is planned to take place.</p>
            </div>
            <div class="settings-row-right">
                <input type="datetime-local" name="maintenance_scheduled_at" id="maintenance_scheduled_at"
                       class="admin-input" style="width:220px;"
                       value="<?= sv($settings,'maintenance_scheduled_at','') ?>">
            </div>
        </div>

        <!-- Maintenance Notification Banner Message -->
        <div class="settings-row">
            <div class="settings-row-left">
                <label for="maintenance_banner_msg">Notification Banner Message</label>
                <p>This message will appear as a highlighted notice on all portal pages when maintenance is active or scheduled.</p>
            </div>
            <div class="settings-row-right" style="flex:1;max-width:100%;width:100%;">
                <textarea name="maintenance_banner_msg" id="maintenance_banner_msg"
                          class="admin-input" style="width:100%;height:120px;font-family:inherit;font-size:.865rem;resize:vertical;line-height:1.6;"
                          placeholder="e.g. Notice: System will be under maintenance on Saturday from 10:00 PM to 2:00 AM. Some functions may be temporarily unavailable."><?= sv($settings,'maintenance_banner_msg','Notice: The system is undergoing scheduled maintenance to improve services. Some functions may be temporarily limited.') ?></textarea>
            </div>
        </div>

        <!-- Show Banner Checkbox -->
        <div class="settings-row" style="border-bottom:none;">
            <div class="settings-row-left">
                <label>Display Notification Banner</label>
                <p>Display maintenance notification banner on portal pages.</p>
            </div>
            <div class="settings-row-right">
                <label class="admin-toggle">
                    <input type="checkbox" name="maintenance_show_banner"
                           <?= ($settings['maintenance_show_banner'] ?? '1') === '1' ? 'checked' : '' ?>>
                    <span class="admin-toggle-slider"></span>
                </label>
            </div>
        </div>

    </div>
</div>

<?php if ($adminUser['role'] === 'compliance_admin'): ?>
<div style="display:flex;justify-content:flex-end;gap:.75rem;margin-top:.5rem;margin-bottom:1.5rem;">
    <a href="index.php" class="btn-admin-outline">
        <i class="fa-solid fa-xmark"></i> Cancel
    </a>
    <button type="submit" class="btn-admin-primary">
        <i class="fa-solid fa-floppy-disk"></i> Save Settings
    </button>
</div>
<?php endif; ?>

</form>

<!-- Clean System Info Card -->
<div class="admin-card admin-section system-info-card">
    <div class="settings-section-title"><i class="fa-solid fa-circle-info"></i> System Information</div>
    <div class="admin-grid-3">
        <div>
            <div class="stat-label">PHP Version</div>
            <div style="font-weight:700;margin-top:.25rem;"><?= PHP_VERSION ?></div>
        </div>
        <div>
            <div class="stat-label">Server Time (Nairobi)</div>
            <div style="font-weight:700;margin-top:.25rem;"><?= date('d M Y, H:i:s') ?></div>
        </div>
        <div>
            <div class="stat-label">Database Engine</div>
            <div style="font-weight:700;margin-top:.25rem;">MySQL via PDO</div>
        </div>
        <div>
            <div class="stat-label">Admin Module Tables</div>
            <div style="font-weight:700;margin-top:.25rem;color:#16a34a;">
                <i class="fa-solid fa-circle-check"></i> Initialised
            </div>
        </div>
        <div>
            <div class="stat-label">System Environment</div>
            <div style="font-weight:700;margin-top:.25rem;color:#2563eb;">Production Ready</div>
        </div>
        <div>
            <div class="stat-label">Portal Version</div>
            <div style="font-weight:700;margin-top:.25rem;">v2.0 &mdash; Admin Management</div>
        </div>
    </div>
</div>

<script>
function toggleMaintFields() {
    const val = document.getElementById('maintenance_mode').value;
    const row = document.getElementById('row-scheduled-at');
    if (row) {
        row.style.display = (val === 'scheduled') ? 'flex' : 'none';
    }
}
</script>

<?php require_once __DIR__ . '/includes/admin_layout_footer.php'; ?>
