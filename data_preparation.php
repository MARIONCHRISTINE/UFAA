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
        if (!wrap) return;
        try {
            const res = await fetch('ajax/sandbox_draft_api.php?action=list');
            const data = await res.json();

            if (data.status === 'success' && data.drafts && data.drafts.length > 0) {
                wrap.innerHTML = data.drafts.map(d => {
                    const rows = Math.max(0, Math.round(parseFloat(d.approx_rows) || 0));
                    const viewers = parseInt(d.viewer_count) || 0;
                    const editors = parseInt(d.editor_count) || 0;
                    const since = formatRelTime(d.updated_at);
                    return `
                <div class="draft-row">
                    <div style="display:flex; align-items:center; gap:.85rem; flex:1; min-width:0;">
                        <div style="width:40px; height:40px; border-radius:10px; background:#fef2f2; display:flex; align-items:center; justify-content:center; color:var(--airtel-red); font-size:1.25rem; flex-shrink:0; border:1px solid #fca5a5;">
                            <i class="fa-regular fa-file-excel"></i>
                        </div>
                        <div style="min-width:0;">
                            <div style="font-weight:800; color:var(--text-primary); font-size:.9rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escHtml(d.draft_name)}</div>
                            <div style="font-size:.75rem; color:#64748b; margin-top:2px; display:flex; align-items:center; gap:.6rem; flex-wrap:wrap;">
                                <span>${rows} row${rows !== 1 ? 's' : ''} &nbsp;·&nbsp; Last edit by: <strong>${escHtml(d.last_edited_by)}</strong> &nbsp;·&nbsp; ${since}</span>
                                ${editors > 0 ? `<span style="color:#15803d; background:#dcfce7; padding:1px 7px; border-radius:6px; font-weight:700;"><i class="fa-solid fa-pen-to-square"></i> ${editors} editing live</span>` : ''}
                                ${viewers > 0 ? `<span style="color:#0369a1; background:#e0f2fe; padding:1px 7px; border-radius:6px; font-weight:600;"><i class="fa-solid fa-eye"></i> ${viewers} viewing</span>` : ''}
                            </div>
                        </div>
                    </div>
                    <div style="display:flex; align-items:center; gap:.5rem; flex-shrink:0;">
                        <a href="data_sanitizer_editor.php?draft_id=${d.id}" class="btn-filter" style="padding:.4rem .95rem; font-size:.82rem; border-radius:8px; text-decoration:none; display:inline-flex; align-items:center; gap:.4rem;">
                            <i class="fa-solid fa-arrow-right-to-bracket"></i> Open Editor
                        </a>
                        <button onclick="deleteDraft(${d.id}, '${escAttr(d.draft_name)}')" class="btn-reset" style="padding:.4rem .75rem; font-size:.82rem; border-radius:8px; color:#dc2626;" title="Delete draft">
                            <i class="fa-solid fa-trash-can"></i>
                        </button>
                    </div>
                </div>`;
                }).join('');
            } else {
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

        const reader = new FileReader();
        reader.onload = async e => {
            try {
                const wb = XLSX.read(new Uint8Array(e.target.result), { type: 'array', cellDates: true });
                const ws = wb.Sheets[wb.SheetNames[0]];
                const raw = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });
                if (!raw || raw.length < 2) { alert('No data rows found in uploaded file.'); return; }

                const hdrs = raw[0].map(h => String(h).trim().toLowerCase());
                const col = {
                    owner_name: colIdx(hdrs, ['owner', 'name', 'holder']),
                    id_passport_no: colIdx(hdrs, ['passport', 'id_number', 'id_no', 'id']),
                    date_of_birth: colIdx(hdrs, ['birth', 'dob']),
                    account_number: colIdx(hdrs, ['account', 'acct', 'acc']),
                    last_transaction: colIdx(hdrs, ['transaction', 'last_tx', 'tx']),
                    due_amount: colIdx(hdrs, ['amount', 'due', 'kes', 'balance', 'value']),
                    compilation_date: colIdx(hdrs, ['compilation', 'compiled', 'date_compiled'])
                };

                const parsedRows = [];
                for (let i = 1; i < raw.length; i++) {
                    const r = raw[i];
                    if (!r || r.every(c => String(c).trim() === '')) continue;
                    parsedRows.push({
                        owner_name: txt(r[col.owner_name >= 0 ? col.owner_name : 0]),
                        id_passport_no: txt(r[col.id_passport_no >= 0 ? col.id_passport_no : 1]),
                        date_of_birth: fmtDate(r[col.date_of_birth >= 0 ? col.date_of_birth : 2]),
                        account_number: txt(r[col.account_number >= 0 ? col.account_number : 3]),
                        last_transaction: fmtDate(r[col.last_transaction >= 0 ? col.last_transaction : 4]),
                        due_amount: txt(r[col.due_amount >= 0 ? col.due_amount : 5]).replace(/,/g, ''),
                        compilation_date: fmtDate(r[col.compilation_date >= 0 ? col.compilation_date : 6])
                    });
                }

                // Save new draft
                const res = await fetch('ajax/sandbox_draft_api.php?action=save', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        draft_id: 0,
                        draft_name: file.name,
                        rows: parsedRows
                    })
                });
                const data = await res.json();
                if (data.status === 'success' && data.draft_id) {
                    window.location.href = `data_sanitizer_editor.php?draft_id=${data.draft_id}`;
                } else {
                    throw new Error(data.message || 'Could not save draft.');
                }
            } catch (err) {
                alert('File processing error: ' + err.message);
            }
        };
        reader.readAsArrayBuffer(file);
    }

    function txt(v) { return String(v ?? '').trim(); }
    function fmtDate(v) { if (!v) return ''; if (v instanceof Date) return v.toISOString().split('T')[0]; return txt(v); }
    function colIdx(h, keys) { return h.findIndex(x => keys.some(k => x.includes(k))); }
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