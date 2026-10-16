<?php
/**
 * UFAA – Data Preparation & Draft Manager Landing Page
 * Allows compliance officers to upload new Excel files or choose an existing working draft file.
 * Redirects seamlessly to data_sanitizer_editor.php for full-screen web-Excel editing.
 */

require_once 'config.php';
$pageTitle = 'Data Upload';
$activePage = 'data_prep';
require_once 'includes/layout.php';
?>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<style>
    .draft-row {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: .85rem 1.25rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        transition: box-shadow .15s ease, border-color .15s ease;
    }

    .draft-row:hover {
        box-shadow: 0 4px 14px rgba(0, 0, 0, .08);
        border-color: #cbd5e1;
    }
</style>

<!-- ══════════════════════════════════════════════════════════
     LANDING PAGE (Upload Zone + Live Working Draft Files List)
══════════════════════════════════════════════════════════ -->
<div>

    <!-- Page Header -->
    <div
        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:.75rem;">
        <div>
            <h2
                style="font-size:1.4rem; font-weight:800; color:var(--text-primary); margin:0; display:flex; align-items:center; gap:.6rem;">
                <i class="fa-solid fa-cloud-arrow-up" style="color:var(--airtel-red);"></i> Data Upload &amp; Pre-Submission Portal
            </h2>
            <p style="font-size:.875rem; color:var(--text-secondary); margin-top:.2rem;">
                Upload a new Excel asset report or select an active working draft below to open the Web-Excel Editor.
            </p>
        </div>
        <a href="download_template.php" class="btn-reset"
            style="text-decoration:none; padding:.55rem 1.1rem; font-size:.84rem; border-radius:8px; display:inline-flex; align-items:center; gap:.45rem; font-weight:600;">
            <i class="fa-solid fa-download" style="color:var(--airtel-red);"></i> Download Standard Template
        </a>
    </div>

    <!-- Upload Dropzone -->
    <div id="sanitizer-dropzone"
        style="background:#fff; border:2px dashed #cbd5e1; border-radius:14px; padding:3rem 1.5rem; text-align:center; margin-bottom:1.75rem; transition:all .2s ease; cursor:pointer;"
        onclick="document.getElementById('sanitizer-file-input').click()">
        <div style="display:flex; flex-direction:column; align-items:center; pointer-events:none;">
            <div
                style="width:68px; height:68px; background:#fef2f2; border:1px solid #fca5a5; border-radius:50%; display:flex; align-items:center; justify-content:center; color:var(--airtel-red); font-size:1.9rem; margin-bottom:1rem; box-shadow:0 4px 12px rgba(220,38,38,0.12);">
                <i class="fa-solid fa-cloud-arrow-up"></i>
            </div>
            <p style="font-size:1.05rem; font-weight:800; color:var(--text-primary); margin-bottom:.35rem;">Drag &amp;
                Drop Excel File Here</p>
            <p style="font-size:.875rem; color:var(--text-secondary); margin-bottom:.75rem;">or <span
                    style="color:var(--airtel-red); font-weight:700; text-decoration:underline;">click to browse
                    computer</span></p>
            <span
                style="font-size:.78rem; color:#64748b; background:#f8fafc; padding:.35rem 1rem; border-radius:20px; border:1px solid #e2e8f0;">
                Supports .xlsx · .xls · .csv &nbsp;—&nbsp; Single worksheet format
            </span>
        </div>
    </div>
    <input type="file" id="sanitizer-file-input" accept=".xlsx,.xls,.csv" onchange="loadExcelAndRedirect(event)"
        style="display:none;">

    <!-- Flagged Duplicate Alert Box Container -->
    <div id="flagged-notice-alert-container"></div>

    <!-- Draft File List Section -->
    <div style="margin-bottom:.75rem; display:flex; justify-content:space-between; align-items:center;">
        <div
            style="font-size:.9rem; font-weight:800; color:var(--text-primary); display:flex; align-items:center; gap:.55rem;">
            <i class="fa-solid fa-folder-open" style="color:#64748b;"></i> Active Working Files (Drafts)
            <span
                style="font-size:.72rem; font-weight:600; color:#0284c7; background:#e0f2fe; padding:2px 8px; border-radius:10px;"><i
                    class="fa-solid fa-circle-dot"></i> Live Sync</span>
        </div>
        <button onclick="loadDraftList()" class="btn-reset"
            style="font-size:.78rem; padding:.25rem .65rem; border-radius:6px; color:#64748b;">
            <i class="fa-solid fa-arrows-rotate"></i> Refresh
        </button>
    </div>

    <div id="draft-list-wrap" style="display:flex; flex-direction:column; gap:.65rem;">
        <div style="text-align:center; padding:2rem; color:var(--text-muted); font-size:.875rem;">
            <i class="fa-solid fa-spinner fa-spin"></i> Loading working files…
        </div>
    </div>

</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        setupDragAndDrop();
        loadDraftList();
        startListAutoPoll();
    });

    let listPollTimer = null;
    function startListAutoPoll() {
        clearInterval(listPollTimer);
        listPollTimer = setInterval(loadDraftList, 4000);
    }

    function setupDragAndDrop() {
        const dropzone = document.getElementById('sanitizer-dropzone');
        if (!dropzone) return;

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(ev =>
            dropzone.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); }, false));

        ['dragenter', 'dragover'].forEach(ev =>
            dropzone.addEventListener(ev, () => {
                dropzone.style.borderColor = 'var(--airtel-red)';
                dropzone.style.background = '#fef2f2';
            }, false));

        ['dragleave', 'drop'].forEach(ev =>
            dropzone.addEventListener(ev, () => {
                dropzone.style.borderColor = '#cbd5e1';
                dropzone.style.background = '#fff';
            }, false));

        dropzone.addEventListener('drop', e => {
            const files = e.dataTransfer.files;
            if (files && files.length) loadExcelAndRedirect({ target: { files } });
        }, false);
    }

    async function loadDraftList() {
        const wrap = document.getElementById('draft-list-wrap');
        const alertWrap = document.getElementById('flagged-notice-alert-container');
        if (!wrap) return;
        try {
            const res = await fetch('ajax/sandbox_draft_api.php?action=list');
            const data = await res.json();

            if (data.status === 'success' && data.drafts && data.drafts.length > 0) {
                // Check if any draft is flagged
                const flaggedDrafts = data.drafts.filter(d => d.status === 'flagged');
                if (alertWrap) {
                    if (flaggedDrafts.length > 0) {
                        const f = flaggedDrafts[0];
                        alertWrap.innerHTML = `
                        <div style="background:#fffbeb; border:1.5px solid #fcd34d; border-radius:14px; padding:1.1rem 1.5rem; margin-bottom:1.5rem; display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; box-shadow:0 4px 14px rgba(217,119,6,0.12);">
                            <div style="display:flex; align-items:center; gap:1rem;">
                                <div style="width:46px; height:46px; background:#fef3c7; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#d97706; font-size:1.35rem; flex-shrink:0;">
                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                </div>
                                <div>
                                    <div style="font-weight:800; font-size:1rem; color:#92400e;">${flaggedDrafts.length} File Flagged for Duplicate Review</div>
                                    <div style="font-size:.85rem; color:#b45309; margin-top:2px;">
                                        ${escHtml(f.flagged_reason || 'An administrator flagged potential duplicate claims in this report. Click to open the Web-Excel Editor to resolve.')}
                                    </div>
                                </div>
                            </div>
                            <a href="data_sanitizer_editor.php?draft_id=${f.id}" class="btn-filter" style="padding:.55rem 1.2rem; font-size:.84rem; border-radius:9px; background:#d97706; color:#fff; text-decoration:none; display:inline-flex; align-items:center; gap:.45rem; font-weight:700; box-shadow:0 4px 12px rgba(217,119,6,0.3);">
                                <i class="fa-solid fa-pen-to-square"></i> Open Editor to Resolve
                            </a>
                        </div>`;
                    } else {
                        alertWrap.innerHTML = '';
                    }
                }

                wrap.innerHTML = data.drafts.map(d => {
                    const rows = Math.max(0, Math.round(parseFloat(d.approx_rows) || 0));
                    const viewers = parseInt(d.viewer_count) || 0;
                    const editors = parseInt(d.editor_count) || 0;
                    const since = formatRelTime(d.updated_at);
                    const isFlagged = d.status === 'flagged';
                    const iconBg = isFlagged ? '#fef3c7' : '#fef2f2';
                    const iconCol = isFlagged ? '#d97706' : 'var(--airtel-red)';
                    const iconBorder = isFlagged ? '#fcd34d' : '#fca5a5';

                    return `
                <div class="draft-row" style="${isFlagged ? 'border-color:#fcd34d; background:#fffbeb;' : ''}">
                    <div style="display:flex; align-items:center; gap:.85rem; flex:1; min-width:0;">
                        <div style="width:40px; height:40px; border-radius:10px; background:${iconBg}; display:flex; align-items:center; justify-content:center; color:${iconCol}; font-size:1.25rem; flex-shrink:0; border:1px solid ${iconBorder};">
                            <i class="fa-regular ${isFlagged ? 'fa-flag' : 'fa-file-excel'}"></i>
                        </div>
                        <div style="min-width:0;">
                            <div style="font-weight:800; color:var(--text-primary); font-size:.9rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:flex; align-items:center; gap:.5rem;">
                                ${escHtml(d.draft_name)}
                                ${isFlagged ? `<span style="color:#b45309; background:#fef3c7; border:1px solid #fcd34d; padding:1px 7px; border-radius:6px; font-weight:700; font-size:.72rem;"><i class="fa-solid fa-flag"></i> Flagged Duplicate — Action Required</span>` : ''}
                            </div>
                            <div style="font-size:.75rem; color:#64748b; margin-top:2px; display:flex; align-items:center; gap:.6rem; flex-wrap:wrap;">
                                <span>${rows} row${rows !== 1 ? 's' : ''} &nbsp;·&nbsp; Last edit by: <strong>${escHtml(d.last_edited_by)}</strong> &nbsp;·&nbsp; ${since}</span>
                                ${editors > 0 ? `<span style="color:#15803d; background:#dcfce7; padding:1px 7px; border-radius:6px; font-weight:700;"><i class="fa-solid fa-pen-to-square"></i> ${editors} editing live</span>` : ''}
                                ${viewers > 0 ? `<span style="color:#0369a1; background:#e0f2fe; padding:1px 7px; border-radius:6px; font-weight:600;"><i class="fa-solid fa-eye"></i> ${viewers} viewing</span>` : ''}
                            </div>
                        </div>
                    </div>
                    <div style="display:flex; align-items:center; gap:.5rem; flex-shrink:0;">
                        <a href="data_sanitizer_editor.php?draft_id=${d.id}" class="btn-filter" style="padding:.4rem .95rem; font-size:.82rem; border-radius:8px; text-decoration:none; display:inline-flex; align-items:center; gap:.4rem; ${isFlagged ? 'background:#d97706; color:#fff;' : ''}">
                            <i class="fa-solid ${isFlagged ? 'fa-pen-to-square' : 'fa-arrow-right-to-bracket'}"></i> ${isFlagged ? 'Resolve in Editor' : 'Open Editor'}
                        </a>
                        <button onclick="deleteDraft(${d.id}, '${escAttr(d.draft_name)}')" class="btn-reset" style="padding:.4rem .75rem; font-size:.82rem; border-radius:8px; color:#dc2626;" title="Delete draft">
                            <i class="fa-solid fa-trash-can"></i>
                        </button>
                    </div>
                </div>`;
                }).join('');
            } else {
                if (alertWrap) alertWrap.innerHTML = '';
                wrap.innerHTML = `
                <div style="text-align:center; padding:2.5rem 1rem; color:#94a3b8; font-size:.875rem; background:#fff; border:1px dashed #cbd5e1; border-radius:12px;">
                    <i class="fa-regular fa-folder-open" style="font-size:2rem; display:block; margin-bottom:.65rem; color:#cbd5e1;"></i>
                    No working files yet — upload an Excel file above to open the Editor.
                </div>`;
            }
        } catch (e) {
            wrap.innerHTML = `<div style="color:#dc2626; font-size:.85rem; padding:.5rem;">Could not load working files (${escHtml(e.message)}).</div>`;
        }
    }

    async function deleteDraft(draftId, name) {
        if (!confirm(`Delete working file "${name}"? This cannot be undone.`)) return;
        await fetch('ajax/sandbox_draft_api.php?action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ draft_id: draftId })
        });
        loadDraftList();
    }

    function loadExcelAndRedirect(event) {
        const file = event.target.files[0];
        if (!file) return;

        const dz = document.getElementById('sanitizer-dropzone');
        if (dz) {
            dz.style.pointerEvents = 'none';
            dz.innerHTML = `
                <div style="display:flex; flex-direction:column; align-items:center;">
                    <div style="width:68px; height:68px; background:#fef2f2; border:2px solid var(--airtel-red); border-radius:50%; display:flex; align-items:center; justify-content:center; color:var(--airtel-red); font-size:1.8rem; margin-bottom:1rem;">
                        <i class="fa-solid fa-spinner fa-spin"></i>
                    </div>
                    <p style="font-size:1.05rem; font-weight:800; color:var(--text-primary); margin-bottom:.2rem;">Reading ${escHtml(file.name)}…</p>
                    <p id="upload-progress-text" style="font-size:.875rem; color:#64748b;">Parsing worksheet data rows…</p>
                </div>`;
        }

        const reader = new FileReader();
        reader.onload = async e => {
            try {
                const wb = XLSX.read(new Uint8Array(e.target.result), { type: 'array', cellDates: false });
                const ws = wb.Sheets[wb.SheetNames[0]];
                // raw: false ensures formatted text (cell.w) is preserved (e.g. leading zeros like 0712345678, exact date strings)
                const raw = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '', raw: false });
                if (!raw || raw.length < 2) { alert('No data rows found in uploaded file.'); return; }

                const hdrs = raw[0].map(h => String(h).trim().toLowerCase());
                const col = {
                    owner_name: colIdx(hdrs, ['owner_name', 'holder_name', 'customer_name', 'full_name', 'owner', 'name', 'holder']),
                    id_passport_no: colIdx(hdrs, ['passport', 'id_number', 'id_no', 'national_id', 'id_num', 'id']),
                    date_of_birth: colIdx(hdrs, ['date_of_birth', 'birth_date', 'dob', 'birth']),
                    account_number: colIdx(hdrs, ['account_number', 'account_no', 'acc_no', 'account', 'acct']),
                    last_transaction: colIdx(hdrs, ['last_transaction', 'transaction_date', 'last_tx_date', 'last_tx', 'transaction']),
                    due_amount: colIdx(hdrs, ['due_amount', 'amount_due', 'amount', 'due', 'kes', 'balance', 'value']),
                    compilation_date: colIdx(hdrs, ['compilation_date', 'compiled_date', 'compilation', 'compiled', 'date_compiled'])
                };

                let parsedRows = [];
                for (let i = 1; i < raw.length; i++) {
                    const r = raw[i];
                    if (!r || r.every(c => String(c).trim() === '')) continue;
                    // Keep exact raw text as uploaded - do not modify or format anything yet
                    parsedRows.push({
                        owner_name:       rawVal(col.owner_name >= 0 ? r[col.owner_name] : r[0]),
                        id_passport_no:   rawVal(col.id_passport_no >= 0 ? r[col.id_passport_no] : r[1]),
                        date_of_birth:    rawVal(col.date_of_birth >= 0 ? r[col.date_of_birth] : r[2]),
                        account_number:   rawVal(col.account_number >= 0 ? r[col.account_number] : r[3]),
                        last_transaction: rawVal(col.last_transaction >= 0 ? r[col.last_transaction] : r[4]),
                        due_amount:       rawVal(col.due_amount >= 0 ? r[col.due_amount] : r[5]),
                        compilation_date: rawVal(col.compilation_date >= 0 ? r[col.compilation_date] : r[6])
                    });
                }

                // Enforce 500,000 maximum row limit as requested
                if (parsedRows.length > 500000) {
                    alert(`The uploaded file contains ${parsedRows.length.toLocaleString()} rows. System is capped at 500,000 rows maximum. Limiting to the first 500,000 rows.`);
                    parsedRows = parsedRows.slice(0, 500000);
                }

                const totalRows = parsedRows.length;
                const CHUNK_SIZE = 15000;
                const totalChunks = Math.ceil(totalRows / CHUNK_SIZE);
                let draftId = 0;

                const progressEl = document.getElementById('upload-progress-text');

                for (let chunkIdx = 0; chunkIdx < totalChunks; chunkIdx++) {
                    const start = chunkIdx * CHUNK_SIZE;
                    const end = Math.min(totalRows, start + CHUNK_SIZE);
                    const chunkRows = parsedRows.slice(start, end);

                    if (progressEl) {
                        const pct = Math.round(((chunkIdx + 1) / totalChunks) * 100);
                        progressEl.textContent = `Uploading chunk ${chunkIdx + 1} of ${totalChunks} (${end.toLocaleString()} / ${totalRows.toLocaleString()} rows · ${pct}%)…`;
                    }

                    const res = await fetch('ajax/sandbox_draft_api.php?action=save_chunk', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            draft_id: draftId,
                            draft_name: file.name,
                            chunk_index: chunkIdx,
                            total_chunks: totalChunks,
                            rows: chunkRows
                        })
                    });

                    const data = await res.json();
                    if (data.status !== 'chunk_saved' && data.status !== 'success') {
                        throw new Error(data.message || 'Error saving upload chunk.');
                    }

                    draftId = data.draft_id;

                    if (data.is_final) {
                        if (progressEl) progressEl.textContent = 'Launching Web-Excel Editor…';
                        window.location.href = `data_sanitizer_editor.php?draft_id=${draftId}`;
                        return;
                    }
                }
            } catch (err) {
                alert('File processing error: ' + err.message);
                window.location.reload();
            }
        };
        reader.readAsArrayBuffer(file);
    }

    function rawVal(v) { return (v === null || v === undefined) ? '' : String(v).trim(); }
    function txt(v) { return rawVal(v); }
    function fmtDate(v) { return rawVal(v); }
    function colIdx(h, keys) {
        // 1. First priority: exact match
        for (const k of keys) {
            const exact = h.findIndex(x => x === k);
            if (exact >= 0) return exact;
        }
        // 2. Second priority: word boundary match (prevents 'paid' matching 'id' or 'matrix' matching 'tx')
        for (const k of keys) {
            const found = h.findIndex(x => {
                if (k.length <= 2) {
                    const re = new RegExp('(^|[^a-z0-9])' + k + '([^a-z0-9]|$)', 'i');
                    return re.test(x);
                }
                return x.includes(k);
            });
            if (found >= 0) return found;
        }
        return -1;
    }
    function escHtml(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
    function escAttr(s) { return escHtml(s).replace(/"/g, '&quot;'); }
    function formatRelTime(dtStr) {
        if (!dtStr) return '';
        const d = new Date(dtStr);
        if (isNaN(d)) return dtStr;
        const diff = Math.floor((Date.now() - d.getTime()) / 1000);
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return d.toLocaleDateString();
    }
</script>

<?php require_once 'includes/layout_footer.php'; ?>