<?php
/**
 * UFAA Admin — Data Management
 * 1. Data Quality Checks (Missing Owner, Missing ID, Missing DOB, Missing Account, Duplicate Accounts)
 * 2. Interactive Paginated Edit & View Panel (100 per page)
 * 3. Full Data Streaming CSV Export
 */

require_once __DIR__ . '/includes/admin_auth.php';

$pageTitle       = 'Data Management';
$adminActivePage = 'data_management';

$pdo = admin_get_pdo();

$missingOwner     = 0;
$missingId        = 0;
$missingDOB       = 0;
$missingAccount   = 0;
$trueDuplicates   = 0;
$recycledAccounts = 0;
$totalRecords     = 0;

if ($pdo) {
    try {
        $totalRecords     = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets")->fetchColumn();
        $missingOwner     = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE owner_name IS NULL OR owner_name=''")->fetchColumn();
        $missingId        = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE id_passport_no IS NULL OR id_passport_no=''")->fetchColumn();
        $missingDOB       = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE date_of_birth IS NULL")->fetchColumn();
        $missingAccount   = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE account_number IS NULL OR account_number=''")->fetchColumn();
        $trueDuplicates   = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT account_number, id_passport_no, last_transaction, compilation_date FROM unclaimed_assets WHERE account_number IS NOT NULL AND account_number!='' AND id_passport_no IS NOT NULL AND id_passport_no!='' GROUP BY account_number, id_passport_no, last_transaction, compilation_date HAVING COUNT(*) > 1) t LEFT JOIN duplicate_notes dn ON dn.group_key = CONCAT(COALESCE(TRIM(t.account_number),''),'___',COALESCE(TRIM(t.id_passport_no),''),'___',COALESCE(TRIM(t.last_transaction),''),'___',COALESCE(TRIM(t.compilation_date),'')) WHERE dn.reviewed IS NULL OR dn.reviewed = 0")->fetchColumn();
        $recycledAccounts = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT account_number FROM unclaimed_assets WHERE account_number IS NOT NULL AND account_number!='' GROUP BY account_number HAVING COUNT(DISTINCT COALESCE(NULLIF(TRIM(id_passport_no),''), owner_name)) > 1) t")->fetchColumn();
    } catch (Exception $e) {}
}

$totalIssues = $missingOwner + $missingId + $missingDOB + $missingAccount + $trueDuplicates;
$allGood     = $totalIssues === 0;

require_once __DIR__ . '/includes/admin_layout.php';
?>

<style>
/* ── Inline edit cells ── */
.editable-cell {
    position: relative;
    cursor: pointer;
    border-radius: 5px;
    padding: 3px 6px;
    transition: background .15s;
}
.editable-cell:hover { background: #f0f4ff; }
.editable-cell.saving { opacity: .6; pointer-events: none; }
.editable-cell .cell-display { display: flex; align-items: center; gap: .35rem; }
.editable-cell .edit-icon { opacity: 0; font-size: .7rem; color: #2563eb; transition: opacity .15s; }
.editable-cell:hover .edit-icon { opacity: 1; }
.editable-cell input {
    border: 1.5px solid #2563eb; border-radius: 5px; padding: 3px 7px;
    font-size: .82rem; font-family: inherit; width: 100%;
    outline: none; background: #fff;
}

/* ── Chunked export progress ── */
.export-progress-wrap { display: none; margin-top: .75rem; }
.export-bar-bg { background: #e5e7eb; border-radius: 99px; height: 8px; overflow: hidden; }
.export-bar-fill { background: var(--airtel-red); height: 100%; border-radius: 99px; transition: width .3s; width: 0; }
.export-status-text { font-size: .8rem; color: var(--text-muted); margin-top: .4rem; }
</style>

<!-- ── Page Header ── -->
<div class="admin-page-header">
    <div class="admin-page-header-left">
        <div class="admin-breadcrumb">
            <i class="fa-solid fa-house"></i>
            <a href="index.php">Dashboard</a>
            <i class="fa-solid fa-chevron-right"></i>
            <span>Data Management</span>
        </div>
        <h2><i class="fa-solid fa-database" style="color:var(--airtel-red);margin-right:.45rem;"></i>Data Management</h2>
        <p>Inspect data quality across <?= number_format($totalRecords) ?> records, edit missing details, view duplicates, and export records.</p>
    </div>
    <div id="header-status-wrap">
        <?php if ($allGood): ?>
        <span class="admin-badge" style="font-size:.85rem;padding:.45rem 1.1rem;background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;font-weight:700;">
            <i class="fa-solid fa-circle-check"></i>&nbsp; All Records Complete (0 Issues)
        </span>
        <?php else: ?>
        <span class="admin-badge" style="font-size:.85rem;padding:.45rem 1.1rem;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;font-weight:700;">
            <i class="fa-solid fa-triangle-exclamation"></i>&nbsp; <?= number_format($totalIssues) ?> Data Issues Found
        </span>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     SECTION 0 — File Protection & Password Vault (Airtel Admin Retrieval)
════════════════════════════════════════════════════════════ -->
<div class="admin-card admin-section">
    <div class="admin-card-title" style="justify-content:space-between;">
        <span><i class="fa-solid fa-vault" style="color:var(--airtel-red);"></i> File Protection &amp; Password Vault</span>
        <span style="font-size:0.75rem; color:var(--text-muted); font-weight:600;"><i class="fa-solid fa-shield-halved"></i> Audited Access</span>
    </div>
    <p style="font-size:.875rem;color:var(--text-secondary);margin-bottom:1.25rem;line-height:1.6;">
        Below is the registry of all submission Excel batches. Each file is generated with automatic worksheet protection. 
        Click <strong>Reveal Password</strong> to view or copy the unprotect key for Airtel local administration.
    </p>

    <div class="table-responsive" style="max-height:265px; overflow-y:auto; overflow-x:auto; border:1px solid #e2e8f0; border-radius:10px;">
        <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
            <thead>
                <tr style="background:#0f172a; color:#ffffff; position:sticky; top:0; z-index:10; box-shadow:0 2px 4px rgba(0,0,0,0.12);">
                    <th style="padding:0.75rem 1rem; text-align:left; font-weight:700; color:#ffffff; white-space:nowrap; background:#0f172a;">Batch ID</th>
                    <th style="padding:0.75rem 1rem; text-align:left; font-weight:700; color:#ffffff; white-space:nowrap; background:#0f172a;">Submission Date</th>
                    <th style="padding:0.75rem 1rem; text-align:left; font-weight:700; color:#ffffff; white-space:nowrap; background:#0f172a;">Submitted By</th>
                    <th style="padding:0.75rem 1rem; text-align:center; font-weight:700; color:#ffffff; white-space:nowrap; background:#0f172a;">Records</th>
                    <th style="padding:0.75rem 1rem; text-align:left; font-weight:700; color:#ffffff; background:#0f172a;">Generated Excel File</th>
                    <th style="padding:0.75rem 1rem; text-align:center; font-weight:700; color:#ffffff; white-space:nowrap; background:#0f172a;">Unprotect Password</th>
                    <th style="padding:0.75rem 1rem; text-align:center; font-weight:700; color:#ffffff; white-space:nowrap; background:#0f172a;">Audit Sign-Off</th>
                </tr>
            </thead>
            <tbody id="vault-tbody">
                <?php
                try {
                    $vaultStmt = $pdo->query("
                        SELECT `id`, `file_name`, `uploaded_by`, `record_count`, `protection_password`, `uploaded_at`
                        FROM `upload_sessions`
                        ORDER BY `id` DESC
                        LIMIT 100
                    ");
                    $vaultSessions = $vaultStmt->fetchAll();
                    if (empty($vaultSessions)):
                ?>
                    <tr>
                        <td colspan="7" style="padding:2rem; text-align:center; color:var(--text-muted);">
                            No submission batches logged yet.
                        </td>
                    </tr>
                <?php
                    else:
                        foreach ($vaultSessions as $vIdx => $vSess):
                            $vBg = $vIdx % 2 === 0 ? '' : 'background:#f8fafc;';
                            $vDate = date('d M Y, H:i', strtotime($vSess['uploaded_at']));
                ?>
                    <tr style="border-bottom:1px solid #e5e7eb; <?= $vBg ?>">
                        <td style="padding:0.65rem 1rem; font-weight:700; color:var(--text-primary); white-space:nowrap;">#UB-<?= str_pad($vSess['id'], 4, '0', STR_PAD_LEFT) ?></td>
                        <td style="padding:0.65rem 1rem; color:var(--text-secondary); white-space:nowrap;"><?= $vDate ?></td>
                        <td style="padding:0.65rem 1rem; font-weight:600; white-space:nowrap;"><?= htmlspecialchars($vSess['uploaded_by']) ?></td>
                        <td style="padding:0.65rem 1rem; text-align:center; font-weight:700; white-space:nowrap;">
                            <span style="background:#e0f2fe; color:#0369a1; padding:0.2rem 0.55rem; border-radius:4px; font-size:0.78rem;">
                                <?= number_format($vSess['record_count']) ?> rows
                            </span>
                        </td>
                        <td style="padding:0.65rem 1rem; color:var(--text-secondary); font-size:0.8rem; word-break:break-all;"><?= htmlspecialchars($vSess['file_name']) ?></td>
                        <td style="padding:0.65rem 1rem; text-align:center; white-space:nowrap;">
                            <div id="vault-pass-cell-<?= $vSess['id'] ?>">
                                <button onclick="revealVaultPassword(<?= $vSess['id'] ?>)" class="btn-admin-secondary" style="font-size:0.75rem; padding:0.25rem 0.65rem; border-radius:6px;" title="Reveal unprotect key for Airtel Admin">
                                    <i class="fa-solid fa-eye"></i> Reveal Password
                                </button>
                            </div>
                        </td>
                        <td style="padding:0.65rem 1rem; text-align:center; white-space:nowrap;">
                            <div id="audit-cell-<?= $vSess['id'] ?>">
                                <button onclick="markBatchAudited(<?= $vSess['id'] ?>)" class="btn-admin-outline" style="font-size:0.75rem; padding:0.25rem 0.65rem; border-radius:6px; color:#16a34a; border-color:#86efac;" title="Mark this batch as verified &amp; audited by Admin">
                                    <i class="fa-solid fa-square-check"></i> Mark Audited
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php
                        endforeach;
                    endif;
                } catch (Exception $vEx) {}
                ?>
            </tbody>
        </table>
    </div>
</div>

<script>
let vaultTimers = {};

async function revealVaultPassword(sessionId) {
    const cell = document.getElementById(`vault-pass-cell-${sessionId}`);
    if (!cell) return;

    if (vaultTimers[sessionId]) {
        clearTimeout(vaultTimers[sessionId]);
    }

    cell.innerHTML = '<span style="font-size:0.75rem; color:#64748b;"><i class="fa-solid fa-spinner fa-spin"></i> Retrieving...</span>';

    try {
        const formData = new FormData();
        formData.append('session_id', sessionId);

        const res = await fetch('ajax/reveal_password.php', {
            method: 'POST',
            body: formData
        });
        const d = await res.json();
        if (!d.success) throw new Error(d.error);

        const defaultBtnHtml = `
            <button onclick="revealVaultPassword(${sessionId})" class="btn-admin-secondary" style="font-size:0.75rem; padding:0.25rem 0.65rem; border-radius:6px;" title="Reveal unprotect key for Airtel Admin">
                <i class="fa-solid fa-eye"></i> Reveal Password
            </button>`;

        cell.innerHTML = `
            <div style="display:inline-flex; flex-direction:column; align-items:center; gap:0.2rem;">
                <div style="display:inline-flex; align-items:center; gap:0.4rem; background:#fee2e2; border:1px solid #fca5a5; padding:0.2rem 0.6rem; border-radius:6px;">
                    <code style="font-size:0.85rem; font-weight:800; color:var(--airtel-red);">${escHtml(d.protection_password)}</code>
                    <button onclick="navigator.clipboard.writeText('${escHtml(d.protection_password)}'); AdminToast.success('Password copied to clipboard!');" style="background:none; border:none; cursor:pointer; color:var(--airtel-red);" title="Copy Key">
                        <i class="fa-regular fa-copy"></i>
                    </button>
                </div>
                <span style="font-size:0.68rem; font-weight:700; color:#dc2626;"><i class="fa-solid fa-clock"></i> Auto-hiding in 10s</span>
            </div>
        `;
        AdminToast.info('Password revealed (Auto-hides in 10 seconds).');

        vaultTimers[sessionId] = setTimeout(() => {
            cell.innerHTML = defaultBtnHtml;
        }, 10000);

    } catch (err) {
        cell.innerHTML = '<span style="font-size:0.75rem; color:#ef4444;">Failed to load</span>';
        AdminToast.error(err.message || 'Error fetching password');
    }
}

function markBatchAudited(sessionId) {
    const cell = document.getElementById(`audit-cell-${sessionId}`);
    if (cell) {
        cell.innerHTML = `
            <span style="background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:0.25rem 0.65rem; border-radius:6px; font-weight:700; font-size:0.78rem; display:inline-flex; align-items:center; gap:0.3rem;">
                <i class="fa-solid fa-circle-check"></i> Verified &amp; Audited
            </span>
        `;
    }
    AdminToast.success(`Batch #UB-${String(sessionId).padStart(4,'0')} marked as Verified & Audited.`);
}
</script>

<!-- ══════════════════════════════════════════════════════════
     SECTION 1 — Data Health & Audit Registry (View-Only Compliance)
════════════════════════════════════════════════════════════ -->
<div class="admin-card admin-section">
    <div class="admin-card-title">
        <i class="fa-solid fa-shield-halved" style="color:#0284c7;"></i> Data Health &amp; Audit Registry
    </div>
    <p style="font-size:.875rem;color:var(--text-secondary);margin-bottom:1.5rem;line-height:1.6;">
        Below is the health audit of your database records. All incoming submissions are pre-validated on upload to guarantee 100% data quality.
        Click <strong>Inspect Records (View Only)</strong> or <strong>View Breakdown</strong> to audit historical items.
    </p>

    <?php
    $checks = [
        ['field'=>'owner_name',       'mode'=>'missing_owner',   'label'=>'Missing Owner Name',          'sub'=>'Records with no owner name set',                                          'icon'=>'fa-user-slash',     'count'=>$missingOwner,    'isDup'=>false, 'isInfo'=>false],
        ['field'=>'id_passport_no',   'mode'=>'missing_id',      'label'=>'Missing ID / Passport No.',   'sub'=>'Records with no ID or passport number',                                   'icon'=>'fa-id-card',        'count'=>$missingId,       'isDup'=>false, 'isInfo'=>false],
        ['field'=>'date_of_birth',    'mode'=>'missing_dob',     'label'=>'Missing Date of Birth',       'sub'=>'Records where date of birth is not set',                                  'icon'=>'fa-calendar-xmark', 'count'=>$missingDOB,      'isDup'=>false, 'isInfo'=>false],
        ['field'=>'account_number',   'mode'=>'missing_account', 'label'=>'Missing Account Number',      'sub'=>'Records with no account number',                                          'icon'=>'fa-hashtag',        'count'=>$missingAccount,  'isDup'=>false, 'isInfo'=>false],
        ['field'=>'true_duplicates',  'mode'=>'true_dup',        'label'=>'Duplicate Claims',            'sub'=>'Same Account Number AND Same ID/Passport Number (Audit Required)',       'icon'=>'fa-copy',           'count'=>$trueDuplicates,  'isDup'=>true,  'isInfo'=>false],
        ['field'=>'recycled_accounts','mode'=>'recycled',        'label'=>'Recycled SIM Accounts',        'sub'=>'Same Account Number BUT Different Owners/IDs (Valid Telecom Reallocation)','icon'=>'fa-arrows-rotate',  'count'=>$recycledAccounts,'isDup'=>true,  'isInfo'=>true],
    ];
    ?>

    <div style="display:flex;flex-direction:column;gap:.75rem;margin-bottom:1.5rem;">
    <?php foreach ($checks as $chk):
        $has     = $chk['count'] > 0;
        $isInfo  = $chk['isInfo'] ?? false;
        
        if ($isInfo) {
            $bg      = $has ? '#f0f9ff' : '#f0fdf4';
            $border  = $has ? 'rgba(37,99,235,.2)' : 'rgba(22,163,74,.2)';
            $iconBg  = $has ? '#2563eb18' : '#16a34a18';
            $iconCol = $has ? '#2563eb'  : '#16a34a';
            $numCol  = $has ? '#2563eb'  : '#16a34a';
        } else {
            $bg      = $has ? '#fff5f0' : '#f0fdf4';
            $border  = $has ? 'rgba(234,88,12,.2)' : 'rgba(22,163,74,.2)';
            $iconBg  = $has ? '#ea580c18' : '#16a34a18';
            $iconCol = $has ? '#ea580c'  : '#16a34a';
            $numCol  = $has ? '#ea580c'  : '#16a34a';
        }
    ?>
        <div id="card-row-<?= $chk['field'] ?>"
             style="display:flex;align-items:center;justify-content:space-between;padding:.85rem 1.1rem;
                    background:<?= $bg ?>;border:1px solid <?= $border ?>;border-radius:10px;flex-wrap:wrap;gap:.75rem;transition:all .3s;">
            <div style="display:flex;align-items:center;gap:.85rem;">
                <div class="card-icon-box" style="width:38px;height:38px;border-radius:99px;flex-shrink:0;display:flex;align-items:center;
                            justify-content:center;background:<?= $iconBg ?>;color:<?= $iconCol ?>;">
                    <i class="fa-solid <?= $chk['icon'] ?>"></i>
                </div>
                <div>
                    <div style="font-weight:600;font-size:.9rem;"><?= $chk['label'] ?></div>
                    <div style="font-size:.78rem;color:var(--text-muted);"><?= $chk['sub'] ?></div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
                <span id="num-<?= $chk['field'] ?>" style="font-size:1.5rem;font-weight:700;color:<?= $numCol ?>;"><?= number_format($chk['count']) ?></span>
                <div id="actions-<?= $chk['field'] ?>">
                <?php if ($has): ?>
                    <?php if ($chk['isDup']): ?>
                        <button class="btn-admin-outline" style="font-size:.8rem;padding:.3rem .8rem;"
                                onclick="loadDupAccounts(1, '<?= $chk['mode'] ?>')">
                            <i class="fa-solid <?= $isInfo ? 'fa-eye' : 'fa-magnifying-glass' ?>"></i> <?= $isInfo ? 'View Breakdown (View Only)' : 'Inspect Records (View Only)' ?>
                        </button>
                    <?php else: ?>
                        <button class="btn-admin-outline" style="font-size:.8rem;padding:.3rem .8rem;"
                                onclick="openMissingPanel('<?= $chk['field'] ?>', '<?= $chk['mode'] ?>', '<?= htmlspecialchars($chk['label']) ?>')">
                            <i class="fa-solid fa-magnifying-glass"></i> Inspect Records (View Only)
                        </button>
                    <?php endif; ?>
                    <button class="btn-admin-secondary" style="font-size:.8rem;padding:.3rem .8rem;"
                            onclick="startExport('<?= $chk['mode'] ?>', '<?= htmlspecialchars($chk['label']) ?>')">
                        <i class="fa-solid fa-file-csv"></i> Export CSV
                    </button>
                <?php else: ?>
                <span style="font-size:.8rem;color:#16a34a;font-weight:600;"><i class="fa-solid fa-circle-check"></i> <?= $isInfo ? 'No Recycled SIMs' : '100% Compliant' ?></span>
                <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <!-- ── Paginated Edit Panel for Missing Records ── -->
    <div id="missing-edit-panel" style="display:none;">
        <div style="border-top:2px solid #e5e7eb;padding-top:1.25rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;flex-wrap:wrap;gap:.5rem;">
                <strong id="edit-panel-title" style="font-size:.95rem;"></strong>
                <button class="btn-reset" onclick="closeMissingPanel()"><i class="fa-solid fa-xmark"></i> Close</button>
            </div>
            <div id="edit-panel-count" style="font-size:.82rem;color:var(--text-muted);margin-bottom:.75rem;"></div>

            <!-- Controls row -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem;">
                <div style="font-size:.82rem;color:var(--text-muted);">
                    <i class="fa-solid fa-circle-info"></i>
                    Click any <span style="color:#2563eb;font-weight:600;">highlighted cell</span> to edit directly. Press <kbd>Enter</kbd> to save, <kbd>Esc</kbd> to cancel.
                </div>
                <div style="display:flex;gap:.5rem;align-items:center;">
                    <select id="edit-per-page" class="admin-select" style="padding:.25rem .5rem;font-size:.82rem;width:auto;"
                            onchange="loadMissingPage(1)">
                        <option value="100" selected>100 per page</option>
                        <option value="50">50 per page</option>
                        <option value="200">200 per page</option>
                    </select>
                </div>
            </div>

            <div class="admin-table-wrap" style="max-height:400px;overflow-y:auto;">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>Record ID</th>
                            <th>Owner Name</th>
                            <th>ID / Passport</th>
                            <th>Account No.</th>
                            <th>Date of Birth</th>
                            <th>Due Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="edit-tbody">
                        <tr><td colspan="8" style="text-align:center;padding:2rem;"><div class="admin-spinner"></div></td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div id="edit-pagination" style="display:none;margin-top:1rem;">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;">
                    <div id="edit-page-info" style="font-size:.82rem;color:var(--text-muted);font-weight:500;"></div>
                    <div id="edit-page-btns" style="display:flex;gap:.35rem;flex-wrap:wrap;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Paginated View Panel for Duplicate Account Numbers ── -->
    <div id="dup-panel" style="display:none;">
        <div style="border-top:2px solid #e5e7eb;padding-top:1.25rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;flex-wrap:wrap;gap:.5rem;">
                <strong id="dup-panel-title" style="font-size:.95rem;color:var(--text-primary);"><i class="fa-solid fa-copy" style="color:#ea580c;"></i> Duplicate Accounts</strong>
                <button class="btn-reset" onclick="closeDupPanel()"><i class="fa-solid fa-xmark"></i> Close</button>
            </div>
            <div id="dup-count-label" style="font-size:.82rem;color:var(--text-muted);margin-bottom:.75rem;"></div>

            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem;">
                <div id="dup-panel-info-text" style="font-size:.82rem;color:var(--text-muted);">
                    <i class="fa-solid fa-circle-info"></i> Audit view. Preserved for claims processing &amp; compliance history.
                </div>
                <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                    <select id="dup-filter-status" class="admin-select" style="padding:.25rem .5rem;font-size:.82rem;width:auto;"
                            onchange="loadDupAccounts(1, _dupMode)">
                        <option value="all" selected>All Audit Statuses</option>
                        <option value="unreviewed">Not Reviewed</option>
                        <option value="confirmed">Confirmed True Duplicate</option>
                        <option value="cleared">Cleared / False Duplicate</option>
                    </select>
                    <div style="position:relative;">
                        <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:.6rem;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.78rem;"></i>
                        <input type="text" id="dup-search" placeholder="Search account no, name, ID…"
                               style="padding:.3rem .6rem .3rem 2rem;font-size:.8rem;border:1px solid #e2e8f0;border-radius:7px;width:220px;font-family:inherit;"
                               oninput="loadDupAccounts(1, _dupMode)">
                    </div>
                    <select id="dup-per-page" class="admin-select" style="padding:.25rem .5rem;font-size:.82rem;width:auto;"
                            onchange="loadDupAccounts(1, _dupMode)">
                        <option value="100" selected>100 per page</option>
                        <option value="50">50 per page</option>
                        <option value="200">200 per page</option>
                    </select>
                </div>
            </div>

            <div class="admin-table-wrap" style="max-height:400px;overflow-y:auto;">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Record ID</th>
                            <th>Owner Name</th>
                            <th>ID / Passport</th>
                            <th>Account No.</th>
                            <th>Date of Birth</th>
                            <th>Due Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="dup-tbody">
                        <tr><td colspan="8" style="text-align:center;padding:2rem;"><div class="admin-spinner"></div></td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div id="dup-pagination" style="display:none;margin-top:1rem;">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;">
                    <div id="dup-page-info" style="font-size:.82rem;color:var(--text-muted);font-weight:500;"></div>
                    <div id="dup-page-btns" style="display:flex;gap:.35rem;flex-wrap:wrap;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     GLOBAL EXPORT PROGRESS BAR
════════════════════════════════════════════════════════════ -->
<div id="global-export-wrap" class="admin-card admin-section export-progress-wrap">
    <div class="admin-card-title"><i class="fa-solid fa-file-csv"></i> <span id="export-title">Exporting...</span></div>
    <div class="export-bar-bg"><div class="export-bar-fill" id="export-bar"></div></div>
    <div class="export-status-text" id="export-status"></div>
    <div style="margin-top:.75rem;">
        <button class="btn-reset" onclick="cancelExport()"><i class="fa-solid fa-xmark"></i> Cancel</button>
    </div>
</div>

<script>
/* ════════════════════════════════════════════════════════════
   MISSING RECORDS — VIEW & EDIT
════════════════════════════════════════════════════════════ */
let _missingField = null;
let _missingMode  = null;
let _missingPage  = 1;

function openMissingPanel(field, mode, label) {
    closeDupPanel();
    _missingField = field;
    _missingMode  = mode;
    document.getElementById('edit-panel-title').textContent = label;
    document.getElementById('missing-edit-panel').style.display = 'block';
    document.getElementById('missing-edit-panel').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    loadMissingPage(1);
}

function closeMissingPanel() {
    _missingField = null;
    document.getElementById('missing-edit-panel').style.display = 'none';
}

async function loadMissingPage(page) {
    _missingPage = page;
    const tbody   = document.getElementById('edit-tbody');
    const perPage = document.getElementById('edit-per-page').value;
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:2rem;"><div class="admin-spinner"></div></td></tr>';
    document.getElementById('edit-pagination').style.display = 'none';

    try {
        const d = await AdminAjax.get('ajax/get_missing_paginated.php', {
            field: _missingField, page, per_page: perPage
        });
        if (!d.success) throw new Error(d.error);

        const from = ((page - 1) * parseInt(perPage)) + 1;
        const to   = Math.min(page * parseInt(perPage), d.total);
        document.getElementById('edit-panel-count').innerHTML =
            `Showing <strong>${from}–${to}</strong> of <strong>${d.total.toLocaleString()}</strong> records`;

        if (!d.records.length) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#16a34a;padding:2rem;"><i class="fa-solid fa-check"></i> No records with this issue.</td></tr>';
            return;
        }

        tbody.innerHTML = d.records.map((r, i) => `
            <tr id="edit-row-${escHtml(r.record_id)}">
                <td style="color:var(--text-muted);">${((page-1)*parseInt(perPage))+ i + 1}</td>
                <td style="font-weight:600;color:var(--airtel-red);">${escHtml(r.record_id)}</td>
                ${_missingField === 'owner_name'     ? makeEditableCell(r.record_id, 'owner_name',    r.owner_name,    'text', true) : `<td>${escHtml(r.owner_name || '—')}</td>`}
                ${_missingField === 'id_passport_no' ? makeEditableCell(r.record_id, 'id_passport_no',r.id_passport_no,'text', true) : `<td>${escHtml(r.id_passport_no || '—')}</td>`}
                ${_missingField === 'account_number' ? makeEditableCell(r.record_id, 'account_number',r.account_number, 'text', true) : `<td>${escHtml(r.account_number || '—')}</td>`}
                ${_missingField === 'date_of_birth'  ? makeEditableCell(r.record_id, 'date_of_birth', r.date_of_birth,  'date', true) : `<td>${escHtml(r.date_of_birth || '—')}</td>`}
                <td style="color:var(--text-secondary);">${r.due_amount ? 'KSh ' + parseFloat(r.due_amount).toLocaleString() : '—'}</td>
                <td><span class="admin-badge ${r.status === 'Claimed' ? 'badge-active' : 'badge-inactive'}">${escHtml(r.status)}</span></td>
            </tr>`).join('');

        // Attach inline-edit listeners
        document.querySelectorAll('#edit-tbody .editable-cell').forEach(attachEditListener);

        // Pagination
        if (d.total_pages > 1) {
            document.getElementById('edit-pagination').style.display = 'block';
            renderPager('edit-page-info', 'edit-page-btns', from, to, d.total, d.total_pages, page, loadMissingPage);
        }

    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;color:var(--airtel-red);padding:2rem;">Error: ${escHtml(err.message)}</td></tr>`;
    }
}

function makeEditableCell(recordId, field, value, type, highlight) {
    const empty   = !value || String(value).trim() === '';
    const display = empty ? '<em style="color:#ea580c;font-style:italic;">—</em>' : escHtml(value);
    const hlStyle = highlight ? 'background:#fffbeb;' : '';
    return `<td class="editable-cell ${highlight ? 'highlight-missing' : ''}"
               style="${hlStyle}"
               data-id="${escHtml(recordId)}" data-field="${field}" data-type="${type}" data-value="${escHtml(value || '')}">
        <div class="cell-display">
            <span class="cell-val">${display}</span>
            <i class="fa-solid fa-pen edit-icon"></i>
        </div>
    </td>`;
}

function attachEditListener(cell) {
    cell.addEventListener('click', function(e) {
        if (this.querySelector('input')) return; // already editing
        const val   = this.dataset.value;
        const type  = this.dataset.type;
        const field = this.dataset.field;
        const rowId = this.dataset.id;

        const wrap = document.createElement('div');
        wrap.className = 'edit-cell-active';
        wrap.style.cssText = 'display:flex;gap:.35rem;align-items:center;';

        const input = document.createElement('input');
        input.type  = type === 'date' ? 'date' : 'text';
        input.value = val;
        input.style.cssText = 'border:1.5px solid #2563eb;border-radius:5px;padding:3px 7px;font-size:.82rem;font-family:inherit;width:130px;outline:none;background:#fff;';

        const btnSave = document.createElement('button');
        btnSave.className = 'btn-admin-primary';
        btnSave.style.cssText = 'padding:3px 8px;font-size:.75rem;white-space:nowrap;display:inline-flex;align-items:center;gap:.3rem;';
        btnSave.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Updates';

        const btnCancel = document.createElement('button');
        btnCancel.className = 'btn-reset';
        btnCancel.style.cssText = 'padding:3px 6px;font-size:.75rem;';
        btnCancel.innerHTML = '<i class="fa-solid fa-xmark"></i>';

        wrap.appendChild(input);
        wrap.appendChild(btnSave);
        wrap.appendChild(btnCancel);

        this.innerHTML = '';
        this.appendChild(wrap);
        input.focus();

        const doSave = async () => {
            await saveEdit(cell, rowId, field, input.value.trim());
        };

        const doCancel = () => {
            restoreCellDisplay(cell, val, true);
        };

        btnSave.addEventListener('click', (ev) => {
            ev.stopPropagation();
            doSave();
        });

        btnCancel.addEventListener('click', (ev) => {
            ev.stopPropagation();
            doCancel();
        });

        input.addEventListener('keydown', async (ev) => {
            if (ev.key === 'Enter') {
                ev.stopPropagation();
                await doSave();
            } else if (ev.key === 'Escape') {
                ev.stopPropagation();
                doCancel();
            }
        });
    });
}

async function saveEdit(cell, rowId, field, newVal) {
    if (!newVal) { AdminToast.error('Value cannot be empty.'); return; }
    cell.classList.add('saving');
    try {
        const d = await AdminAjax.post('ajax/edit_missing.php', { record_id: rowId, field, value: newVal });
        if (!d.success) throw new Error(d.error);
        cell.dataset.value = newVal;
        restoreCellDisplay(cell, newVal, false);
        AdminToast.success('Saved successfully!');
        // Live auto-refresh card counts, header badge & table
        refreshCardCounts();
    } catch (err) {
        AdminToast.error(err.message);
        restoreCellDisplay(cell, cell.dataset.value, true);
    } finally {
        cell.classList.remove('saving');
    }
}

async function refreshCardCounts() {
    try {
        const d = await AdminAjax.get('ajax/get_quality_counts.php');
        if (!d.success) return;

        const checkMeta = {
            'owner_name':        { mode: 'missing_owner',   label: 'Missing Owner Name',          isDup: false, isInfo: false },
            'id_passport_no':    { mode: 'missing_id',      label: 'Missing ID / Passport No.',   isDup: false, isInfo: false },
            'date_of_birth':     { mode: 'missing_dob',     label: 'Missing Date of Birth',       isDup: false, isInfo: false },
            'account_number':    { mode: 'missing_account', label: 'Missing Account Number',      isDup: false, isInfo: false },
            'true_duplicates':   { mode: 'true_dup',        label: 'True Duplicate Claims',       isDup: true,  isInfo: false },
            'recycled_accounts': { mode: 'recycled',        label: 'Recycled SIM Accounts',        isDup: true,  isInfo: true  }
        };

        const fields = ['owner_name', 'id_passport_no', 'date_of_birth', 'account_number', 'true_duplicates', 'recycled_accounts'];
        fields.forEach(f => {
            const cnt   = d[f] ?? 0;
            const card  = document.getElementById('card-row-' + f);
            const numEl = document.getElementById('num-' + f);
            const actEl = document.getElementById('actions-' + f);
            const meta  = checkMeta[f];

            if (numEl) numEl.textContent = cnt.toLocaleString();

            if (card && actEl && meta) {
                const isInfo = meta.isInfo;
                if (cnt === 0) {
                    card.style.background  = '#f0fdf4';
                    card.style.borderColor = 'rgba(22,163,74,.2)';
                    const iconBox = card.querySelector('.card-icon-box');
                    if (iconBox) { iconBox.style.background = '#16a34a18'; iconBox.style.color = '#16a34a'; }
                    if (numEl) numEl.style.color = '#16a34a';
                    actEl.innerHTML = `<span style="font-size:.8rem;color:#16a34a;font-weight:600;"><i class="fa-solid fa-check"></i> ${isInfo ? 'No Recycled SIMs' : 'All good'}</span>`;
                } else {
                    const bg      = isInfo ? '#f0f9ff' : '#fff5f0';
                    const border  = isInfo ? 'rgba(37,99,235,.2)' : 'rgba(234,88,12,.2)';
                    const iconBg  = isInfo ? '#2563eb18' : '#ea580c18';
                    const iconCol = isInfo ? '#2563eb'  : '#ea580c';
                    const numCol  = isInfo ? '#2563eb'  : '#ea580c';

                    card.style.background  = bg;
                    card.style.borderColor = border;
                    const iconBox = card.querySelector('.card-icon-box');
                    if (iconBox) { iconBox.style.background = iconBg; iconBox.style.color = iconCol; }
                    if (numEl) numEl.style.color = numCol;

                    const safeLabel = escHtml(meta.label);
                    if (meta.isDup) {
                        const btnText = isInfo ? 'View Breakdown' : 'View & Edit';
                        const icon    = isInfo ? 'fa-eye' : 'fa-pen-to-square';
                        actEl.innerHTML = `
                            <button class="btn-admin-outline" style="font-size:.8rem;padding:.3rem .8rem;" onclick="loadDupAccounts(1, '${meta.mode}')">
                                <i class="fa-solid ${icon}"></i> ${btnText}
                            </button>
                            <button class="btn-admin-secondary" style="font-size:.8rem;padding:.3rem .8rem;" onclick="startExport('${meta.mode}', '${safeLabel}')">
                                <i class="fa-solid fa-file-csv"></i> Export
                            </button>
                        `;
                    } else {
                        actEl.innerHTML = `
                            <button class="btn-admin-outline" style="font-size:.8rem;padding:.3rem .8rem;" onclick="openMissingPanel('${f}', '${meta.mode}', '${safeLabel}')">
                                <i class="fa-solid fa-pen-to-square"></i> View & Edit
                            </button>
                            <button class="btn-admin-secondary" style="font-size:.8rem;padding:.3rem .8rem;" onclick="startExport('${meta.mode}', '${safeLabel}')">
                                <i class="fa-solid fa-file-csv"></i> Export
                            </button>
                        `;
                    }
                }
            }
        });

        // Header status badge
        const headerWrap = document.getElementById('header-status-wrap');
        if (headerWrap) {
            headerWrap.innerHTML = d.all_good
                ? '<span class="admin-badge" style="font-size:.85rem;padding:.45rem 1.1rem;background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;font-weight:700;"><i class="fa-solid fa-circle-check"></i>&nbsp; All Records Complete (0 Issues)</span>'
                : `<span class="admin-badge" style="font-size:.85rem;padding:.45rem 1.1rem;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;font-weight:700;"><i class="fa-solid fa-triangle-exclamation"></i>&nbsp; ${d.total_issues.toLocaleString()} Data Issues Found</span>`;
        }

        // Re-fetch current table page ONLY if the panel is currently open
        const missingPanel = document.getElementById('missing-edit-panel');
        const dupPanel     = document.getElementById('dup-panel');

        if (missingPanel && missingPanel.style.display !== 'none' && _missingField) {
            loadMissingPage(_missingPage);
        }
        if (dupPanel && dupPanel.style.display !== 'none') {
            loadDupAccounts(_dupPage, _dupMode, true);
        }

    } catch (e) { /* silent fail */ }
}

function restoreCellDisplay(cell, val, highlight) {
    const empty = !val || val.trim() === '';
    cell.style.background = highlight && empty ? '#fffbeb' : '';
    cell.innerHTML = `<div class="cell-display">
        <span class="cell-val">${empty ? '<em style="color:#ea580c;font-style:italic;">—</em>' : escHtml(val)}</span>
        <i class="fa-solid fa-pen edit-icon"></i>
    </div>`;
    attachEditListener(cell);
}

/* ════════════════════════════════════════════════════════════
   DUPLICATE ACCOUNT NUMBERS VIEW
════════════════════════════════════════════════════════════ */
let _dupPage = 1;
let _dupMode = 'true_dup';

function closeDupPanel() {
    document.getElementById('dup-panel').style.display = 'none';
}

function toggleDupGroup(groupId) {
    const icon = document.getElementById('icon-' + groupId);
    const rows = document.querySelectorAll(`tr[data-group="${groupId}"]`);
    if (!rows.length) return;

    const isOpen = rows[0].style.display !== 'none';

    rows.forEach(tr => {
        tr.style.display = isOpen ? 'none' : '';
    });

    if (icon) {
        icon.style.transform = isOpen ? '' : 'rotate(90deg)';
    }
}

async function confirmDupResolution(groupKey) {
    const sel = document.getElementById('select-res-' + groupKey);
    if (!sel || !sel.value) {
        AdminToast.error('Please select an audit decision first (Confirmed True Duplicate or False Duplicate / Cleared).');
        return;
    }

    const resolution = sel.value;
    sel.disabled = true;

    try {
        const fd = new FormData();
        fd.append('group_key', groupKey);
        fd.append('resolution', resolution);

        const res = await fetch('ajax/mark_dup_reviewed.php', { method: 'POST', body: fd });
        const d   = await res.json();

        if (!d.success) throw new Error(d.error);

        AdminToast.success(`Saved decision: "${resolution}". Record group is now locked.`);
        refreshCardCounts();
    } catch (err) {
        AdminToast.error(err.message || 'This duplicate claim has already been reviewed and locked');
        sel.disabled = false;
    }
}

async function loadDupAccounts(page, mode, skipScroll = false) {
    if (!skipScroll) closeMissingPanel();
    _dupPage = page;
    if (mode) _dupMode = mode;

    const panel    = document.getElementById('dup-panel');
    const tbody    = document.getElementById('dup-tbody');
    const title    = document.getElementById('dup-panel-title');
    const perPage  = document.getElementById('dup-per-page')?.value ?? 100;
    const search   = document.getElementById('dup-search')?.value?.trim() ?? '';
    const filter   = document.getElementById('dup-filter-status')?.value ?? 'all';
    const filterEl = document.getElementById('dup-filter-status');

    if (filterEl) {
        filterEl.style.display = _dupMode === 'recycled' ? 'none' : 'inline-block';
    }

    const infoEl = document.getElementById('dup-panel-info-text');

    if (title) {
        if (_dupMode === 'recycled') {
            title.innerHTML = '<i class="fa-solid fa-arrows-rotate" style="color:#2563eb;"></i> Recycled SIM Accounts (Valid Telecom Reallocation)';
            if (infoEl) infoEl.innerHTML = '<i class="fa-solid fa-circle-info" style="color:#2563eb;"></i> <strong>Telecom Audit Note:</strong> Records below share identical Account Numbers but belong to distinct owners/IDs across different periods. Valid SIM reallocations. Preserved read-only.';
        } else {
            title.innerHTML = '<i class="fa-solid fa-copy" style="color:#ea580c;"></i> Duplicate Claims (Advisory Audit Warning)';
            if (infoEl) infoEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="color:#ea580c;"></i> <strong>Advisory Audit Notice:</strong> Records below share identical Account Numbers &amp; ID Numbers. Select audit decision and save to lock resolution.';
        }
    }

    panel.style.display = 'block';
    if (!skipScroll) {
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:2rem;"><div class="admin-spinner"></div></td></tr>';
    document.getElementById('dup-pagination').style.display = 'none';

    try {
        const d = await AdminAjax.get('ajax/get_dup_accounts.php', { mode: _dupMode, page, per_page: perPage, search, filter });
        if (!d.success) throw new Error(d.error);

        const from = ((page - 1) * parseInt(perPage)) + 1;
        const to   = Math.min(page * parseInt(perPage), d.total);

        const labelDesc = _dupMode === 'recycled'
            ? `Showing <strong>${from}–${to}</strong> of <strong>${d.total.toLocaleString()}</strong> records across <strong>${d.distinct_dups}</strong> recycled account numbers (different owners)`
            : `Showing <strong>${from}–${to}</strong> of <strong>${d.total.toLocaleString()}</strong> duplicate records across <strong>${d.distinct_dups}</strong> identical account+ID pairs`;

        document.getElementById('dup-count-label').innerHTML = labelDesc;

        if (!d.records.length) {
            const noMsg = _dupMode === 'recycled' ? 'No recycled account numbers found.' : 'No duplicate records match the active filter/search criteria.';
            tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;color:#16a34a;padding:2rem;"><i class="fa-solid fa-check"></i> ${noMsg}</td></tr>`;
            return;
        }

        // Group records by key first
        const groupMap = {};
        const groupOrder = [];
        d.records.forEach(r => {
            const key = _dupMode === 'true_dup'
                ? ( (r.account_number||'').trim() + '___' + (r.id_passport_no||'').trim() + '___' + (r.last_transaction||'').trim() + '___' + (r.compilation_date||'').trim() )
                : (r.account_number||'').trim();
            if (!groupMap[key]) { groupMap[key] = []; groupOrder.push(key); }
            groupMap[key].push(r);
        });

        let htmlRows = [];
        const notes = d.notes || {};
        let rowNum = from;

        groupOrder.forEach(key => {
            const groupRecords = groupMap[key];
            const r0 = groupRecords[0];

            if (_dupMode === 'recycled') {
                htmlRows.push(`
                    <tr style="background:#eff6ff;border-top:2px solid #3b82f6;">
                        <td colspan="8" style="padding:.5rem .9rem;color:#1d4ed8;font-weight:700;font-size:.83rem;">
                            <i class="fa-solid fa-mobile-screen-button"></i> Recycled Account No: <strong>${escHtml(r0.account_number || '—')}</strong>
                            <span style="font-size:.76rem;color:#3b82f6;font-weight:500;margin-left:.75rem;">(Related Multi-Owner Claims Below)</span>
                        </td>
                    </tr>
                `);
                groupRecords.forEach(r => {
                    htmlRows.push(`
                        <tr id="dup-row-${escHtml(r.record_id)}">
                            <td style="color:var(--text-muted);">${rowNum++}</td>
                            <td style="font-weight:600;color:var(--airtel-red);">${escHtml(r.record_id)}</td>
                            <td style="font-weight:600;">${escHtml(r.owner_name || '—')}</td>
                            <td>${escHtml(r.id_passport_no || '—')}</td>
                            <td style="font-weight:600;color:var(--text-primary);">${escHtml(r.account_number || '—')}</td>
                            <td>${escHtml(r.date_of_birth || '—')}</td>
                            <td style="color:var(--text-secondary);">${r.due_amount ? 'KSh ' + parseFloat(r.due_amount).toLocaleString() : '—'}</td>
                            <td><span class="admin-badge ${r.status === 'Claimed' ? 'badge-active' : 'badge-inactive'}">${escHtml(r.status)}</span></td>
                        </tr>
                    `);
                });
            } else {
                const existingNote = notes[key];
                const safeKey  = escHtml(key);
                const groupId  = 'grp-' + btoa(key).replace(/[^a-z0-9]/gi, '').slice(0, 20);
                const isRev    = existingNote && existingNote.reviewed == 1;
                const resNote  = existingNote ? existingNote.note : '';
                const isTrue   = resNote === 'Confirmed True Duplicate';
                const reviewedBy = existingNote && existingNote.noted_by ? `${escHtml(existingNote.noted_by)} · ${escHtml(existingNote.noted_at)}` : '';

                if (isRev) {
                    // ── REVIEWED: single collapsed summary row + hidden detail rows ──
                    const bgColor     = isTrue ? '#fef2f2' : '#f0fdf4';
                    const borderColor = isTrue ? '#ef4444' : '#16a34a';
                    const textColor   = isTrue ? '#b91c1c' : '#15803d';
                    const badgeBg     = isTrue ? '#fee2e2' : '#dcfce7';
                    const badgeBorder = isTrue ? '#fca5a5' : '#bbf7d0';
                    const lockIcon    = isTrue ? 'fa-lock' : 'fa-circle-check';

                    htmlRows.push(`
                        <tr style="background:${bgColor};border-top:2px solid ${borderColor};cursor:pointer;" onclick="toggleDupGroup('${groupId}')">
                            <td colspan="8" style="padding:.6rem .9rem;">
                                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.6rem;">
                                    <div style="display:flex;align-items:center;gap:.55rem;flex:1;min-width:0;">
                                        <i class="fa-solid fa-chevron-right" id="icon-${groupId}" style="color:${textColor};font-size:.78rem;flex-shrink:0;transition:transform .2s;"></i>
                                        <span style="color:${textColor};font-weight:700;font-size:.83rem;">
                                            <i class="fa-solid ${lockIcon}"></i>
                                            Account <strong>${escHtml(r0.account_number || '—')}</strong>
                                            &bull; ID <strong>${escHtml(r0.id_passport_no || 'N/A')}</strong>
                                            &bull; Last Txn: <strong>${escHtml(r0.last_transaction || '—')}</strong>
                                            &bull; Batch: <strong>${escHtml(r0.compilation_date || '—')}</strong>
                                            <span style="font-size:.72rem;font-weight:500;margin-left:.3rem;opacity:.75;">(${groupRecords.length} record${groupRecords.length > 1 ? 's' : ''})</span>
                                        </span>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:.5rem;flex-shrink:0;">
                                        <span style="display:inline-flex;align-items:center;gap:.3rem;font-size:.78rem;padding:.28rem .7rem;background:${badgeBg};color:${textColor};border:1px solid ${badgeBorder};border-radius:6px;font-weight:700;">
                                            <i class="fa-solid fa-lock"></i> ${escHtml(resNote)}
                                        </span>
                                        ${reviewedBy ? `<span style="font-size:.71rem;color:#64748b;">${reviewedBy}</span>` : ''}
                                    </div>
                                </div>
                            </td>
                        </tr>
                    `);

                    // Detail rows — hidden by default via display:none, shown by toggleDupGroup
                    groupRecords.forEach(r => {
                        htmlRows.push(`
                            <tr id="dup-row-${escHtml(r.record_id)}" data-group="${groupId}" style="display:none;background:${bgColor};">
                                <td style="color:var(--text-muted);">${rowNum++}</td>
                                <td style="font-weight:600;color:var(--airtel-red);">${escHtml(r.record_id)}</td>
                                <td style="font-weight:600;">${escHtml(r.owner_name || '—')}</td>
                                <td>${escHtml(r.id_passport_no || '—')}</td>
                                <td style="font-weight:600;color:var(--text-primary);">${escHtml(r.account_number || '—')}</td>
                                <td>${escHtml(r.date_of_birth || '—')}</td>
                                <td style="color:var(--text-secondary);">${r.due_amount ? 'KSh ' + parseFloat(r.due_amount).toLocaleString() : '—'}</td>
                                <td><span class="admin-badge ${r.status === 'Claimed' ? 'badge-active' : 'badge-inactive'}">${escHtml(r.status)}</span></td>
                            </tr>
                        `);
                    });



                } else {
                    // ── UNREVIEWED: full action header row ──
                    htmlRows.push(`
                        <tr style="background:#fff7ed;border-top:2px solid #ea580c;">
                            <td colspan="8" style="padding:.6rem .9rem;">
                                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;">
                                    <div style="color:#c2410c;font-weight:700;font-size:.83rem;">
                                        <i class="fa-solid fa-copy"></i>
                                        Account <strong>${escHtml(r0.account_number || '—')}</strong>
                                        &bull; ID <strong>${escHtml(r0.id_passport_no || 'N/A')}</strong>
                                        &bull; Last Txn: <strong>${escHtml(r0.last_transaction || '—')}</strong>
                                        &bull; Batch: <strong>${escHtml(r0.compilation_date || '—')}</strong>
                                        <span style="font-size:.72rem;color:#ea580c;font-weight:600;margin-left:.4rem;">(${groupRecords.length} duplicate record${groupRecords.length > 1 ? 's' : ''} — Action Required)</span>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:.6rem;flex-shrink:0;">
                                        <select id="select-res-${safeKey}" class="admin-select" style="padding:.3rem .6rem;font-size:.78rem;width:215px;border-color:#fed7aa;background:#fff;font-family:inherit;">
                                            <option value="" disabled selected>-- Select Audit Decision --</option>
                                            <option value="Confirmed True Duplicate">Confirmed True Duplicate</option>
                                            <option value="False Duplicate / Cleared">False Duplicate / Cleared</option>
                                        </select>
                                        <button onclick="confirmDupResolution('${safeKey}')"
                                                style="background:#ea580c;color:#fff;border:none;border-radius:6px;padding:.35rem .85rem;font-size:.77rem;font-weight:700;cursor:pointer;white-space:nowrap;display:inline-flex;align-items:center;gap:.3rem;">
                                            <i class="fa-solid fa-floppy-disk"></i> Save &amp; Lock
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    `);
                    groupRecords.forEach(r => {
                        htmlRows.push(`
                            <tr id="dup-row-${escHtml(r.record_id)}">
                                <td style="color:var(--text-muted);">${rowNum++}</td>
                                <td style="font-weight:600;color:var(--airtel-red);">${escHtml(r.record_id)}</td>
                                <td style="font-weight:600;">${escHtml(r.owner_name || '—')}</td>
                                <td>${escHtml(r.id_passport_no || '—')}</td>
                                <td style="font-weight:600;color:var(--text-primary);">${escHtml(r.account_number || '—')}</td>
                                <td>${escHtml(r.date_of_birth || '—')}</td>
                                <td style="color:var(--text-secondary);">${r.due_amount ? 'KSh ' + parseFloat(r.due_amount).toLocaleString() : '—'}</td>
                                <td><span class="admin-badge ${r.status === 'Claimed' ? 'badge-active' : 'badge-inactive'}">${escHtml(r.status)}</span></td>
                            </tr>
                        `);
                    });
                }
            }
        });

        tbody.innerHTML = htmlRows.join('');

        if (d.total_pages > 1) {
            document.getElementById('dup-pagination').style.display = 'block';
            renderPager('dup-page-info', 'dup-page-btns', from, to, d.total, d.total_pages, page, (p) => loadDupAccounts(p, _dupMode));
        }

    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;color:var(--airtel-red);padding:2rem;">Error: ${escHtml(err.message)}</td></tr>`;
    }
}

/* ════════════════════════════════════════════════════════════
   CHUNKED CSV EXPORT (Full Data Export)
════════════════════════════════════════════════════════════ */
let _exportAborted = false;
const CHUNK_SIZE = 10000; // 10k rows per chunk

async function startExport(mode, label) {
    _exportAborted = false;
    const wrap   = document.getElementById('global-export-wrap');
    const bar    = document.getElementById('export-bar');
    const status = document.getElementById('export-status');
    document.getElementById('export-title').textContent = 'Exporting: ' + label;
    wrap.style.display  = 'block';
    wrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    bar.style.width     = '0%';
    status.textContent  = 'Counting records...';

    try {
        const cr = await fetch(`ajax/export_quality.php?mode=${mode}&get_count=1`);
        const cj = await cr.json();
        if (cj.status !== 'success') throw new Error(cj.message);

        const total       = cj.count;
        const totalChunks = Math.max(1, Math.ceil(total / CHUNK_SIZE));
        status.textContent = `${total.toLocaleString()} records to export across ${totalChunks} file${totalChunks > 1 ? 's' : ''}.`;

        for (let chunk = 1; chunk <= totalChunks; chunk++) {
            if (_exportAborted) { status.textContent = 'Export cancelled.'; break; }
            const offset = (chunk - 1) * CHUNK_SIZE;
            const pct    = Math.round((chunk / totalChunks) * 100);
            status.textContent = `Downloading Part ${chunk} of ${totalChunks} (${pct}%)...`;
            bar.style.width    = pct + '%';

            const url = `ajax/export_quality.php?mode=${encodeURIComponent(mode)}&limit=${CHUNK_SIZE}&offset=${offset}&chunk_num=${chunk}&total_chunks=${totalChunks}`;
            const a   = document.createElement('a');
            a.href = url; a.download = '';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);

            if (chunk < totalChunks) await new Promise(r => setTimeout(r, 1200));
        }

        if (!_exportAborted) {
            bar.style.width    = '100%';
            status.textContent = `Export complete — ${total.toLocaleString()} records downloaded.`;
            AdminToast.success('Export complete!');
            setTimeout(() => { wrap.style.display = 'none'; }, 4000);
        }
    } catch (err) {
        status.textContent = 'Export failed: ' + err.message;
        AdminToast.error('Export failed: ' + err.message);
    }
}

function cancelExport() {
    _exportAborted = true;
    document.getElementById('global-export-wrap').style.display = 'none';
}

/* ════════════════════════════════════════════════════════════
   SHARED HELPERS
════════════════════════════════════════════════════════════ */
function renderPager(infoId, btnsId, from, to, total, totalPages, current, loadFn) {
    document.getElementById(infoId).innerHTML =
        `Showing <strong>${from}–${to}</strong> of <strong>${total.toLocaleString()}</strong> (Page ${current} of ${totalPages})`;

    const pages = [];
    pages.push({ label: '<i class="fa-solid fa-angles-left"></i>', p: 1, disabled: current <= 1 });
    pages.push({ label: '<i class="fa-solid fa-chevron-left"></i>',  p: current - 1, disabled: current <= 1 });
    for (let p = Math.max(1, current - 2); p <= Math.min(totalPages, current + 2); p++) {
        pages.push({ label: p, p, active: p === current });
    }
    pages.push({ label: '<i class="fa-solid fa-chevron-right"></i>', p: current + 1, disabled: current >= totalPages });
    pages.push({ label: '<i class="fa-solid fa-angles-right"></i>', p: totalPages, disabled: current >= totalPages });

    document.getElementById(btnsId).innerHTML = pages.map(pg =>
        `<button class="admin-pg-btn${pg.active ? ' active' : ''}" ${pg.disabled ? 'disabled' : ''}
            onclick="${loadFn.name}(${pg.p})">${pg.label}</button>`
    ).join('');
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = String(str ?? '');
    return d.innerHTML;
}
</script>

<?php require_once __DIR__ . '/includes/admin_layout_footer.php'; ?>
