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

$missingOwner   = 0;
$missingId      = 0;
$missingDOB     = 0;
$missingAccount = 0;
$dupAccounts    = 0;
$totalRecords   = 0;

if ($pdo) {
    try {
        $totalRecords   = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets")->fetchColumn();
        $missingOwner   = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE owner_name IS NULL OR TRIM(owner_name)=''")->fetchColumn();
        $missingId      = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE id_passport_no IS NULL OR TRIM(id_passport_no)=''")->fetchColumn();
        $missingDOB     = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE date_of_birth IS NULL")->fetchColumn();
        $missingAccount = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE account_number IS NULL OR TRIM(account_number)=''")->fetchColumn();
        $dupAccounts    = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT account_number FROM unclaimed_assets WHERE account_number IS NOT NULL AND TRIM(account_number) != '' GROUP BY account_number HAVING COUNT(*) > 1) t")->fetchColumn();
    } catch (Exception $e) {}
}

$totalIssues = $missingOwner + $missingId + $missingDOB + $missingAccount + $dupAccounts;
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
     SECTION 1 — Data Quality Checks (5 Items)
════════════════════════════════════════════════════════════ -->
<div class="admin-card admin-section">
    <div class="admin-card-title">
        <i class="fa-solid fa-triangle-exclamation"></i> Data Quality Checks
    </div>
    <p style="font-size:.875rem;color:var(--text-secondary);margin-bottom:1.5rem;line-height:1.6;">
        Below is the health audit of your database records.
        Click <strong>View &amp; Edit</strong> (or <strong>View Records</strong>) to open the paginated table (100 per page) to inspect or edit records directly.
    </p>

    <?php
    $checks = [
        ['field'=>'owner_name',    'mode'=>'missing_owner',   'label'=>'Missing Owner Name',          'sub'=>'Records with no owner name set',                      'icon'=>'fa-user-slash',     'count'=>$missingOwner,   'isDup'=>false],
        ['field'=>'id_passport_no','mode'=>'missing_id',      'label'=>'Missing ID / Passport No.',   'sub'=>'Records with no ID or passport number',               'icon'=>'fa-id-card',        'count'=>$missingId,      'isDup'=>false],
        ['field'=>'date_of_birth', 'mode'=>'missing_dob',     'label'=>'Missing Date of Birth',       'sub'=>'Records where date of birth is not set',              'icon'=>'fa-calendar-xmark', 'count'=>$missingDOB,     'isDup'=>false],
        ['field'=>'account_number','mode'=>'missing_account', 'label'=>'Missing Account Number',      'sub'=>'Records with no account number',                      'icon'=>'fa-hashtag',        'count'=>$missingAccount, 'isDup'=>false],
        ['field'=>'dup_accounts',  'mode'=>'dup_accounts',    'label'=>'Duplicate Account Numbers',   'sub'=>'Distinct account numbers appearing on multiple records','icon'=>'fa-copy',          'count'=>$dupAccounts,    'isDup'=>true],
    ];
    ?>

    <div style="display:flex;flex-direction:column;gap:.75rem;margin-bottom:1.5rem;">
    <?php foreach ($checks as $chk):
        $has     = $chk['count'] > 0;
        $bg      = $has ? '#fff5f0' : '#f0fdf4';
        $border  = $has ? 'rgba(234,88,12,.2)' : 'rgba(22,163,74,.2)';
        $iconBg  = $has ? '#ea580c18' : '#16a34a18';
        $iconCol = $has ? '#ea580c'  : '#16a34a';
        $numCol  = $has ? '#ea580c'  : '#16a34a';
    ?>
        <div id="card-row-<?= $chk['field'] ?>"
             style="display:flex;align-items:center;justify-content:space-between;padding:.85rem 1.1rem;
                    background:<?= $bg ?>;border:1px solid <?= $border ?>;border-radius:10px;flex-wrap:wrap;gap:.75rem;transition:all .3s;">
            <div style="display:flex;align-items:center;gap:.85rem;">
                <div class="card-icon-box" style="width:38px;height:38px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;
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
                                onclick="loadDupAccounts(1)">
                            <i class="fa-solid fa-pen-to-square"></i> View &amp; Edit
                        </button>
                    <?php else: ?>
                        <button class="btn-admin-outline" style="font-size:.8rem;padding:.3rem .8rem;"
                                onclick="openMissingPanel('<?= $chk['field'] ?>', '<?= $chk['mode'] ?>', '<?= htmlspecialchars($chk['label']) ?>')">
                            <i class="fa-solid fa-pen-to-square"></i> View &amp; Edit
                        </button>
                    <?php endif; ?>
                    <button class="btn-admin-secondary" style="font-size:.8rem;padding:.3rem .8rem;"
                            onclick="startExport('<?= $chk['mode'] ?>', '<?= htmlspecialchars($chk['label']) ?>')">
                        <i class="fa-solid fa-file-csv"></i> Export
                    </button>
                <?php else: ?>
                <span style="font-size:.8rem;color:#16a34a;font-weight:600;"><i class="fa-solid fa-check"></i> All good</span>
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
                <strong style="font-size:.95rem;color:var(--text-primary);"><i class="fa-solid fa-copy" style="color:#ea580c;"></i> Duplicate Account Numbers</strong>
                <button class="btn-reset" onclick="closeDupPanel()"><i class="fa-solid fa-xmark"></i> Close</button>
            </div>
            <div id="dup-count-label" style="font-size:.82rem;color:var(--text-muted);margin-bottom:.75rem;"></div>

            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem;">
                <div style="font-size:.82rem;color:var(--text-muted);">
                    <i class="fa-solid fa-circle-info"></i> Click any <span style="color:#ea580c;font-weight:600;">highlighted account number</span> to edit directly. Press <kbd>Enter</kbd> to save, <kbd>Esc</kbd> to cancel.
                </div>
                <select id="dup-per-page" class="admin-select" style="padding:.25rem .5rem;font-size:.82rem;width:auto;"
                        onchange="loadDupAccounts(1)">
                    <option value="100" selected>100 per page</option>
                    <option value="50">50 per page</option>
                    <option value="200">200 per page</option>
                </select>
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

        const fields = ['owner_name', 'id_passport_no', 'date_of_birth', 'account_number', 'dup_accounts'];
        fields.forEach(f => {
            const cnt   = d[f] ?? 0;
            const card  = document.getElementById('card-row-' + f);
            const numEl = document.getElementById('num-' + f);
            const actEl = document.getElementById('actions-' + f);

            if (numEl) numEl.textContent = cnt.toLocaleString();

            if (card && actEl) {
                if (cnt === 0) {
                    card.style.background  = '#f0fdf4';
                    card.style.borderColor = 'rgba(22,163,74,.2)';
                    const iconBox = card.querySelector('.card-icon-box');
                    if (iconBox) { iconBox.style.background = '#16a34a18'; iconBox.style.color = '#16a34a'; }
                    if (numEl) numEl.style.color = '#16a34a';
                    actEl.innerHTML = '<span style="font-size:.8rem;color:#16a34a;font-weight:600;"><i class="fa-solid fa-check"></i> All good</span>';
                } else {
                    if (numEl) numEl.style.color = '#ea580c';
                }
            }
        });

        // Header status badge
        const headerWrap = document.getElementById('header-status-wrap');
        if (headerWrap) {
            headerWrap.innerHTML = d.all_good
                ? '<span class="admin-badge badge-active" style="font-size:.82rem;padding:.4rem 1rem;"><i class="fa-solid fa-circle-check"></i>&nbsp; All records complete</span>'
                : '<span class="admin-badge badge-inactive" style="font-size:.82rem;padding:.4rem 1rem;"><i class="fa-solid fa-triangle-exclamation"></i>&nbsp; Data issues found</span>';
        }

        // Re-fetch current table page so fixed record updates/disappears seamlessly
        if (_missingField) {
            loadMissingPage(_missingPage);
        } else {
            loadDupAccounts(_dupPage);
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

function closeDupPanel() {
    document.getElementById('dup-panel').style.display = 'none';
}

async function loadDupAccounts(page) {
    closeMissingPanel();
    _dupPage = page;
    const panel   = document.getElementById('dup-panel');
    const tbody   = document.getElementById('dup-tbody');
    const perPage = document.getElementById('dup-per-page')?.value ?? 100;
    panel.style.display = 'block';
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:2rem;"><div class="admin-spinner"></div></td></tr>';
    document.getElementById('dup-pagination').style.display = 'none';

    try {
        const d = await AdminAjax.get('ajax/get_dup_accounts.php', { page, per_page: perPage });
        if (!d.success) throw new Error(d.error);

        const from = ((page - 1) * parseInt(perPage)) + 1;
        const to   = Math.min(page * parseInt(perPage), d.total);
        document.getElementById('dup-count-label').innerHTML =
            `Showing <strong>${from}–${to}</strong> of <strong>${d.total.toLocaleString()}</strong> records across <strong>${d.distinct_dups}</strong> duplicate account numbers`;

        if (!d.records.length) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#16a34a;padding:2rem;"><i class="fa-solid fa-check"></i> No duplicates found.</td></tr>';
            return;
        }

        let lastAccount = null;
        tbody.innerHTML = d.records.map((r, i) => {
            const isNewGroup = r.account_number !== lastAccount;
            lastAccount = r.account_number;
            const rowStyle = isNewGroup && i > 0 ? 'border-top:2px solid #e5e7eb;' : '';
            return `<tr style="${rowStyle}" id="dup-row-${escHtml(r.record_id)}">
                <td style="color:var(--text-muted);">${from + i}</td>
                <td style="font-weight:600;color:var(--airtel-red);">${escHtml(r.record_id)}</td>
                <td>${escHtml(r.owner_name || '—')}</td>
                <td>${escHtml(r.id_passport_no || '—')}</td>
                ${makeEditableCell(r.record_id, 'account_number', r.account_number, 'text', true)}
                <td>${escHtml(r.date_of_birth || '—')}</td>
                <td style="color:var(--text-secondary);">${r.due_amount ? 'KSh ' + parseFloat(r.due_amount).toLocaleString() : '—'}</td>
                <td><span class="admin-badge ${r.status === 'Claimed' ? 'badge-active' : 'badge-inactive'}">${escHtml(r.status)}</span></td>
            </tr>`;
        }).join('');

        // Attach inline-edit listeners for duplicate records
        document.querySelectorAll('#dup-tbody .editable-cell').forEach(attachEditListener);

        if (d.total_pages > 1) {
            document.getElementById('dup-pagination').style.display = 'block';
            renderPager('dup-page-info', 'dup-page-btns', from, to, d.total, d.total_pages, page, loadDupAccounts);
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
