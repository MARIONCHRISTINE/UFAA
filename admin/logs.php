<?php
/**
 * UFAA Admin — Activity Logs
 * Advanced filters (main portal style) + default 100 per page pagination.
 */

require_once __DIR__ . '/includes/admin_auth.php';

$pageTitle       = 'Activity Logs';
$adminActivePage = 'logs';

require_once __DIR__ . '/includes/admin_layout.php';
?>

<!-- ── Page Header ── -->
<div class="admin-page-header">
    <div class="admin-page-header-left">
        <div class="admin-breadcrumb">
            <i class="fa-solid fa-house"></i>
            <a href="index.php">Dashboard</a>
            <i class="fa-solid fa-chevron-right"></i>
            <span>Activity Logs</span>
        </div>
        <h2><i class="fa-solid fa-list-check" style="color:var(--airtel-red);margin-right:.45rem;"></i>Activity Logs</h2>
        <p>Full audit trail of every action taken on the portal (100 per page).</p>
    </div>
    <button class="btn-admin-outline" onclick="resetFilters()">
        <i class="fa-solid fa-rotate-right"></i> Reset All Filters
    </button>
</div>

<!-- ── Main Portal Style Advanced Filters Panel ── -->
<div class="admin-card admin-section">
    <div class="filters-panel" style="margin-bottom:0; background: #fafafa; border:1px solid #e5e7eb; border-radius:12px; padding:1.25rem;">
        
        <div class="filters-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:1rem;">
            
            <!-- Search Keyword -->
            <div class="filter-group">
                <label for="log-search"><i class="fa-solid fa-magnifying-glass"></i> Search Logs</label>
                <input type="text" class="filter-input" id="log-search"
                       placeholder="Username, description, IP, record #…">
            </div>

            <!-- Action Filter -->
            <div class="filter-group">
                <label for="log-action-filter"><i class="fa-solid fa-bolt"></i> Action Type</label>
                <select class="filter-input" id="log-action-filter">
                    <option value="">All Actions</option>
                </select>
            </div>

            <!-- User Filter -->
            <div class="filter-group">
                <label for="log-user-filter"><i class="fa-solid fa-user"></i> User</label>
                <select class="filter-input" id="log-user-filter">
                    <option value="">All Users</option>
                </select>
            </div>

            <!-- Date From -->
            <div class="filter-group">
                <label for="log-date-from"><i class="fa-regular fa-calendar"></i> Date From</label>
                <input type="date" class="filter-input" id="log-date-from">
            </div>

            <!-- Date To -->
            <div class="filter-group">
                <label for="log-date-to"><i class="fa-regular fa-calendar"></i> Date To</label>
                <input type="date" class="filter-input" id="log-date-to">
            </div>

            <!-- Per Page Select -->
            <div class="filter-group">
                <label for="log-per-page"><i class="fa-solid fa-list-ol"></i> Display Limit</label>
                <select class="filter-input" id="log-per-page">
                    <option value="100" selected>100 per page (Default)</option>
                    <option value="50">50 per page</option>
                    <option value="25">25 per page</option>
                    <option value="200">200 per page</option>
                    <option value="500">500 per page</option>
                </select>
            </div>

        </div>

        <div class="filters-actions" style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #e5e7eb; margin-top:1rem; padding-top:1rem; flex-wrap:wrap; gap:0.75rem;">
            <div id="log-status-summary" style="font-size:0.875rem; color:var(--text-secondary); font-weight:500;">
                Loading count…
            </div>
            <div style="display:flex; gap:0.5rem;">
                <button class="btn-reset" onclick="resetFilters()">
                    <i class="fa-solid fa-xmark"></i> Clear Filters
                </button>
                <button class="btn-filter" onclick="loadLogs(1)">
                    <i class="fa-solid fa-filter"></i> Apply Filters
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Logs Table Card ── -->
<div class="admin-card admin-section">
    <div class="table-responsive" style="overflow-x:auto;">
        <table id="log-list" style="width:100%; border-collapse:collapse; font-size:0.875rem;">
            <thead>
                <tr style="background:var(--bg-secondary,#f8fafc); border-bottom:2px solid #e5e7eb;">
                    <th style="padding:0.75rem 1rem; text-align:left; font-weight:700; color:var(--text-secondary); white-space:nowrap; width:60px;">#</th>
                    <th style="padding:0.75rem 1rem; text-align:left; font-weight:700; color:var(--text-secondary); white-space:nowrap;">Action</th>
                    <th style="padding:0.75rem 1rem; text-align:left; font-weight:700; color:var(--text-secondary); white-space:nowrap;">User</th>
                    <th style="padding:0.75rem 1rem; text-align:left; font-weight:700; color:var(--text-secondary);">Description</th>
                    <th style="padding:0.75rem 1rem; text-align:center; font-weight:700; color:var(--text-secondary); white-space:nowrap;">Record ID Modified</th>
                    <th style="padding:0.75rem 1rem; text-align:left; font-weight:700; color:var(--text-secondary); white-space:nowrap;">IP Address</th>
                    <th style="padding:0.75rem 1rem; text-align:left; font-weight:700; color:var(--text-secondary); white-space:nowrap;">Timestamp</th>
                </tr>
            </thead>
            <tbody id="log-tbody">
                <tr>
                    <td colspan="7" style="padding:2.5rem; text-align:center;">
                        <div class="admin-empty-state"><div class="admin-spinner"></div><p style="margin-top:.75rem;">Loading activity logs…</p></div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination Navigation -->
    <div class="admin-pagination" id="log-pagination" style="display:none; border-top:1px solid #e5e7eb; padding-top:1.25rem; margin-top:1rem;">
        <div class="admin-pagination-info" id="log-page-info" style="font-weight:500; font-size:0.875rem;"></div>
        <div class="admin-pagination-btns" id="log-page-btns"></div>
    </div>
</div>

<script>
const ACTION_ICONS = {
    upload:               { icon: 'fa-cloud-arrow-up',   color: '#2563eb' },
    upload_declined:      { icon: 'fa-cloud-arrow-up',   color: '#CC0000' },
    patch_upload:         { icon: 'fa-file-import',      color: '#7c3aed' },
    record_edit:          { icon: 'fa-pen-to-square',    color: '#0891b2' },
    export:               { icon: 'fa-file-csv',         color: '#16a34a' },
    status_change:        { icon: 'fa-arrows-rotate',    color: '#ea580c' },
    letter_generated:     { icon: 'fa-file-pdf',         color: '#16a34a' },
    user_created:         { icon: 'fa-user-plus',        color: '#16a34a' },
    user_deleted:         { icon: 'fa-user-minus',       color: '#CC0000' },
    user_status_change:   { icon: 'fa-user-check',       color: '#ea580c' },
    user_role_change:     { icon: 'fa-user-gear',        color: '#2a5298' },
    password_reset:       { icon: 'fa-key',              color: '#6b7280' },
    password_vault_view: { icon: 'fa-vault',            color: '#dc2626' },
    login:                { icon: 'fa-right-to-bracket', color: '#16a34a' },
    login_failed:         { icon: 'fa-user-slash',       color: '#CC0000' },
    optimise_db:          { icon: 'fa-bolt',             color: '#f59e0b' },
    default:              { icon: 'fa-circle-dot',       color: '#1e3a5f' },
};

let currentPage = 1;

async function loadLogs(page = 1) {
    currentPage = page;
    const tbody  = document.getElementById('log-tbody');
    const pgWrap = document.getElementById('log-pagination');
    const summary = document.getElementById('log-status-summary');

    tbody.innerHTML = `<tr><td colspan="7" style="padding:2.5rem; text-align:center;">
        <div class="admin-empty-state"><div class="admin-spinner"></div><p style="margin-top:.75rem;">Loading logs…</p></div>
    </td></tr>`;
    pgWrap.style.display = 'none';

    const params = {
        page,
        per_page:      document.getElementById('log-per-page').value,
        search:        document.getElementById('log-search').value.trim(),
        action_filter: document.getElementById('log-action-filter').value,
        user_filter:   document.getElementById('log-user-filter').value,
        date_from:     document.getElementById('log-date-from').value,
        date_to:       document.getElementById('log-date-to').value,
    };

    try {
        const d = await AdminAjax.get('ajax/get_logs.php', params);
        if (!d.success) throw new Error(d.error);

        // Populate action filter dropdown
        const actionSel = document.getElementById('log-action-filter');
        if (actionSel.options.length <= 1 && d.action_list) {
            d.action_list.forEach(a => {
                const opt = document.createElement('option');
                opt.value = a;
                opt.textContent = a.replace(/_/g,' ').replace(/\b\w/g, c => c.toUpperCase());
                actionSel.appendChild(opt);
            });
            if (window._preActionFilter) {
                actionSel.value = window._preActionFilter;
                window._preActionFilter = null;
                if (actionSel.value) { loadLogs(1); return; }
            }
        }

        // Populate user filter dropdown
        const userSel = document.getElementById('log-user-filter');
        if (userSel.options.length <= 1 && d.user_list) {
            d.user_list.forEach(u => {
                const opt = document.createElement('option');
                opt.value = u;
                opt.textContent = u;
                userSel.appendChild(opt);
            });
        }

        summary.innerHTML = `Found <strong>${d.total.toLocaleString()}</strong> log entries`;

        if (!d.logs.length) {
            tbody.innerHTML = `<tr><td colspan="7" style="padding:2.5rem; text-align:center;">
                <div class="admin-empty-state"><i class="fa-solid fa-inbox"></i><p>No activity log entries found matching filters.</p></div>
            </td></tr>`;
            return;
        }

        tbody.innerHTML = d.logs.map((log, idx) => {
            const m  = ACTION_ICONS[log.action] || ACTION_ICONS.default;
            const ts = new Date(log.created_at).toLocaleString('en-KE', { dateStyle: 'medium', timeStyle: 'short' });
            const actionLabel = log.action.replace(/_/g,' ').replace(/\b\w/g, c => c.toUpperCase());
            // Strip duplicate 'Record #NNN' or 'for Record #NNN' from description since it's shown in its own column
            const cleanDesc = log.description ? log.description.replace(/\s*(?:for\s+)?Record\s+#\d+/gi, '').trim() : '';
            const rowBg = idx % 2 === 0 ? '' : 'background:#f8fafc;';
            return `
            <tr style="border-bottom:1px solid #e5e7eb; ${rowBg} transition:background .15s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='${idx % 2 === 0 ? '' : '#f8fafc'}'">
                <td style="padding:0.65rem 1rem; color:var(--text-muted); font-size:0.78rem; font-weight:600; white-space:nowrap;">#${log.id}</td>
                <td style="padding:0.65rem 1rem; white-space:nowrap;">
                    <span style="display:inline-flex; align-items:center; gap:0.4rem; background:${m.color}15; color:${m.color}; padding:0.25rem 0.6rem; border-radius:6px; font-size:0.78rem; font-weight:700;">
                        <i class="fa-solid ${m.icon}"></i> ${actionLabel}
                    </span>
                </td>
                <td style="padding:0.65rem 1rem; font-weight:600; white-space:nowrap;">${escHtml(log.username)}</td>
                <td style="padding:0.65rem 1rem; color:var(--text-secondary); max-width:320px; min-width:180px; word-break:break-word; overflow-wrap:break-word; white-space:normal;">${cleanDesc ? escHtml(cleanDesc) : '<em style="color:var(--text-muted);">No details</em>'}</td>
                <td style="padding:0.65rem 1rem; text-align:center; font-weight:600;">${log.record_id ? `<span style="background:#e0f2fe;color:#0369a1;padding:.2rem .5rem;border-radius:4px;font-size:.78rem;">#${log.record_id}</span>` : '<span style="color:var(--text-muted);">—</span>'}</td>
                <td style="padding:0.65rem 1rem; font-size:0.8rem; color:var(--text-secondary); white-space:nowrap;">${escHtml(log.ip_address || '—')}</td>
                <td style="padding:0.65rem 1rem; font-size:0.8rem; color:var(--text-secondary); white-space:nowrap;">${ts}</td>
            </tr>`;
        }).join('');

        // Pagination
        if (d.total_pages > 1) {
            pgWrap.style.display = 'flex';
            const from = ((page - 1) * parseInt(params.per_page)) + 1;
            const to   = Math.min(page * parseInt(params.per_page), d.total);
            document.getElementById('log-page-info').textContent = `Showing ${from}–${to} of ${d.total.toLocaleString()} entries (Page ${page} of ${d.total_pages})`;
            renderPagination(d.total_pages, page);
        }

    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="7" style="padding:2.5rem; text-align:center; color:var(--airtel-red);">Error: ${err.message}</td></tr>`;
    }
}

function renderPagination(total, current) {
    const container = document.getElementById('log-page-btns');
    const pages     = [];

    // First page
    pages.push({ label: '<i class="fa-solid fa-angles-left"></i>', page: 1, disabled: current <= 1, title: 'First Page' });
    // Previous page
    pages.push({ label: '<i class="fa-solid fa-chevron-left"></i>', page: current - 1, disabled: current <= 1, title: 'Previous Page' });

    for (let p = Math.max(1, current - 2); p <= Math.min(total, current + 2); p++) {
        pages.push({ label: p, page: p, active: p === current });
    }

    // Next page
    pages.push({ label: '<i class="fa-solid fa-chevron-right"></i>', page: current + 1, disabled: current >= total, title: 'Next Page' });
    // Last page
    pages.push({ label: '<i class="fa-solid fa-angles-right"></i>', page: total, disabled: current >= total, title: 'Last Page' });

    container.innerHTML = pages.map(p =>
        `<button class="admin-pg-btn${p.active ? ' active' : ''}" ${p.disabled ? 'disabled' : ''}
            title="${p.title || ''}" onclick="loadLogs(${p.page})">${p.label}</button>`
    ).join('');
}

function resetFilters() {
    document.getElementById('log-search').value = '';
    document.getElementById('log-action-filter').value = '';
    document.getElementById('log-user-filter').value = '';
    document.getElementById('log-date-from').value = '';
    document.getElementById('log-date-to').value = '';
    document.getElementById('log-per-page').value = '100';
    loadLogs(1);
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

document.addEventListener('DOMContentLoaded', () => {
    // Live search debounce
    const logSearch = document.getElementById('log-search');
    if (logSearch) {
        let timer = null;
        logSearch.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(() => loadLogs(1), 350);
        });
    }

    ['log-action-filter', 'log-user-filter', 'log-date-from', 'log-date-to', 'log-per-page'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', () => loadLogs(1));
    });

    // Pre-select filters from URL query params (e.g. redirected from upload_audit)
    const urlParams = new URLSearchParams(window.location.search);
    const preAction = urlParams.get('action_filter');
    if (preAction) {
        window._preActionFilter = preAction;
    }
    loadLogs(1);
});

// Immediate load fallback if DOM is already ready
if (document.readyState === 'interactive' || document.readyState === 'complete') {
    loadLogs(1);
}
</script>

<?php require_once __DIR__ . '/includes/admin_layout_footer.php'; ?>
