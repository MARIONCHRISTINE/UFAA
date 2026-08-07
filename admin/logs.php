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

<!-- ── Logs Timeline Card ── -->
<div class="admin-card admin-section">
    <!-- Log timeline -->
    <div class="log-timeline" id="log-list">
        <div class="admin-empty-state">
            <div class="admin-spinner"></div>
            <p style="margin-top:.75rem;">Loading activity logs…</p>
        </div>
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
    login:                { icon: 'fa-right-to-bracket', color: '#16a34a' },
    login_failed:         { icon: 'fa-user-slash',       color: '#CC0000' },
    optimise_db:          { icon: 'fa-bolt',             color: '#f59e0b' },
    default:              { icon: 'fa-circle-dot',       color: '#1e3a5f' },
};

let currentPage = 1;

async function loadLogs(page = 1) {
    currentPage = page;
    const list   = document.getElementById('log-list');
    const pgWrap = document.getElementById('log-pagination');
    const summary = document.getElementById('log-status-summary');

    list.innerHTML = '<div class="admin-empty-state"><div class="admin-spinner"></div><p style="margin-top:.75rem;">Loading logs…</p></div>';
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
            // Pre-select from URL query param on first load
            if (window._preActionFilter) {
                actionSel.value = window._preActionFilter;
                window._preActionFilter = null;
                // Reload with filter applied
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
            list.innerHTML = '<div class="admin-empty-state"><i class="fa-solid fa-inbox"></i><p>No activity log entries found matching filters.</p></div>';
            return;
        }

        list.innerHTML = d.logs.map(log => {
            const m  = ACTION_ICONS[log.action] || ACTION_ICONS.default;
            const ts = new Date(log.created_at).toLocaleString('en-KE', { dateStyle: 'medium', timeStyle: 'short' });
            const actionLabel = log.action.replace(/_/g,' ').replace(/\b\w/g, c => c.toUpperCase());
            return `
            <div class="log-entry">
                <div class="log-icon" style="background:${m.color}18;color:${m.color};">
                    <i class="fa-solid ${m.icon}"></i>
                </div>
                <div class="log-entry-body">
                    <div class="log-entry-action">${actionLabel}</div>
                    <div class="log-entry-desc">${log.description ? escHtml(log.description) : '<em style="color:var(--text-muted);">No details</em>'}</div>
                    <div class="log-entry-meta">
                        <span><i class="fa-solid fa-user"></i>${escHtml(log.username)}</span>
                        <span><i class="fa-solid fa-network-wired"></i>${escHtml(log.ip_address || '—')}</span>
                        <span><i class="fa-regular fa-clock"></i>${ts}</span>
                        ${log.record_id ? `<span><i class="fa-solid fa-hashtag"></i>Record #${log.record_id}</span>` : ''}
                    </div>
                </div>
                <div style="flex-shrink:0;">
                    <span style="font-size:.75rem;color:var(--text-muted);font-weight:600;">#${log.id}</span>
                </div>
            </div>`;
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
        list.innerHTML = `<div class="admin-empty-state"><p style="color:var(--airtel-red);">Error: ${err.message}</p></div>`;
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
