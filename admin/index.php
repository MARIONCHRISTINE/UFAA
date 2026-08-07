<?php
/**
 * UFAA Admin — Dashboard Overview
 * KPI stat cards + activity trend + status doughnut chart.
 */

require_once __DIR__ . '/includes/admin_auth.php';

$pageTitle       = 'Dashboard';
$adminActivePage = 'dashboard';

require_once __DIR__ . '/includes/admin_layout.php';
?>

<!-- ── Page Header ── -->
<div class="admin-page-header">
    <div class="admin-page-header-left">
        <div class="admin-breadcrumb">
            <i class="fa-solid fa-house"></i>
            <span>Admin</span>
            <i class="fa-solid fa-chevron-right"></i>
            <span>Dashboard</span>
        </div>
        <h2><i class="fa-solid fa-gauge-high" style="color:var(--airtel-red);margin-right:.45rem;"></i>Dashboard Overview</h2>
        <p>Live snapshot of the UFAA Compliance Portal.</p>
    </div>
    <div style="display:flex;gap:.7rem;align-items:center;">
        <span class="admin-badge badge-active" style="font-size:.78rem;padding:.35rem .85rem;">
            <i class="fa-solid fa-circle" style="font-size:.5rem;"></i> Live
        </span>
        <button class="btn-admin-outline" id="btn-refresh-stats" onclick="loadStats()">
            <i class="fa-solid fa-rotate-right"></i> Refresh
        </button>
    </div>
</div>

<!-- ── Stat Cards (Main Dashboard Style) ── -->
<div class="stats-grid admin-stats-grid">
    <?php
    $statCards = [
        ['icon'=>'fa-vault',          'label'=>'Total Financial Assets', 'key'=>'total',     'iconClass'=>'total',     'numStyle'=>''],
        ['icon'=>'fa-hourglass-half', 'label'=>'Unclaimed Assets',        'key'=>'unclaimed', 'iconClass'=>'unclaimed', 'numStyle'=>'color: var(--color-orange);'],
        ['icon'=>'fa-circle-check',   'label'=>'Claimed Assets',          'key'=>'claimed',   'iconClass'=>'claimed',   'numStyle'=>'color: var(--color-green);'],
        ['icon'=>'fa-file-pdf',       'label'=>'Holder Letters Issued',   'key'=>'letters',   'iconClass'=>'letter-yes','numStyle'=>'color: var(--color-blue);'],
        ['icon'=>'fa-users-gear',     'label'=>'Active Admin Users',      'key'=>'users',     'iconClass'=>'users',     'numStyle'=>'color: #7c3aed;'],
    ];
    foreach ($statCards as $card): ?>
    <div class="stat-card">
        <div class="stat-info">
            <h3><?= $card['label'] ?></h3>
            <div class="stat-number" id="stat-<?= $card['key'] ?>" data-count="0" style="<?= $card['numStyle'] ?>">—</div>
            <div class="stat-sub" id="stat-sub-<?= $card['key'] ?>" style="font-size:0.75rem; color:var(--text-muted); margin-top:4px; font-weight:500;">Loading...</div>
        </div>
        <div class="stat-icon <?= $card['iconClass'] ?>">
            <i class="fa-solid <?= $card['icon'] ?>"></i>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Charts & Activity Row ── -->
<div class="admin-grid-2 admin-section">

    <!-- Status Doughnut -->
    <div class="admin-card">
        <div class="admin-card-title">
            <i class="fa-solid fa-chart-pie"></i>
            Claimed vs Unclaimed
        </div>
        <div class="chart-wrap">
            <canvas id="chart-status-doughnut"></canvas>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="admin-card">
        <div class="admin-card-title" style="justify-content:space-between;">
            <span><i class="fa-solid fa-clock-rotate-left"></i> Recent Activity</span>
            <a href="logs.php" class="btn-admin-secondary" style="font-size:.78rem;padding:.3rem .75rem;">
                View All <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>
        <div class="log-timeline" id="recent-logs-list">
            <div class="admin-empty-state">
                <div class="admin-spinner"></div>
                <p style="margin-top:.75rem;">Loading activity...</p>
            </div>
        </div>
    </div>
</div>

<script>
/* ── Action icon/color map ── */
const ACTION_META = {
    upload:            { icon: 'fa-cloud-arrow-up',    color: '#2563eb',  bgClass: 'badge-upload' },
    upload_declined:   { icon: 'fa-cloud-arrow-up',    color: '#CC0000',  bgClass: 'badge-failed' },
    patch_upload:      { icon: 'fa-file-import',       color: '#7c3aed',  bgClass: 'badge-status' },
    record_edit:       { icon: 'fa-pen-to-square',     color: '#0891b2',  bgClass: 'badge-status' },
    export:            { icon: 'fa-file-csv',          color: '#16a34a',  bgClass: 'badge-active' },
    status_change:     { icon: 'fa-arrows-rotate',     color: '#ea580c',  bgClass: 'badge-status' },
    letter_generated:  { icon: 'fa-file-pdf',          color: '#16a34a',  bgClass: 'badge-letter' },
    user_created:      { icon: 'fa-user-plus',         color: '#16a34a',  bgClass: 'badge-active' },
    user_deleted:      { icon: 'fa-user-minus',        color: '#CC0000',  bgClass: 'badge-failed' },
    user_status_change:{ icon: 'fa-user-check',        color: '#ea580c',  bgClass: 'badge-status' },
    user_role_change:  { icon: 'fa-user-gear',         color: '#2a5298',  bgClass: 'badge-status' },
    password_reset:    { icon: 'fa-key',               color: '#6b7280',  bgClass: 'badge-login'  },
    login:             { icon: 'fa-right-to-bracket',  color: '#6b7280',  bgClass: 'badge-login'  },
    default:           { icon: 'fa-circle-dot',        color: '#1e3a5f',  bgClass: 'badge-status' },
};

function getActionMeta(action) {
    return ACTION_META[action] || ACTION_META.default;
}

let doughnutChart = null;

async function loadStats() {
    const btn = document.getElementById('btn-refresh-stats');
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Loading';
    btn.disabled  = true;

    try {
        const d = await AdminAjax.get('ajax/get_stats.php');
        if (!d.success) throw new Error(d.error);

        const subs = {
            total:          `${d.claimed} claimed, ${d.unclaimed} unclaimed`,
            claimed:        `${d.total > 0 ? ((d.claimed/d.total)*100).toFixed(1) : 0}% of total`,
            unclaimed:      `${d.total > 0 ? ((d.unclaimed/d.total)*100).toFixed(1) : 0}% of total`,
            letters:        `${d.total > 0 ? ((d.letters/d.total)*100).toFixed(1) : 0}% coverage`,
            users:          'active admin accounts',
        };

        const counts = { total: d.total, claimed: d.claimed, unclaimed: d.unclaimed,
            letters: d.letters, users: d.users };

        Object.entries(counts).forEach(([key, val]) => {
            const el  = document.getElementById(`stat-${key}`);
            const sub = document.getElementById(`stat-sub-${key}`);
            if (el) {
                el.dataset.count = val;
                animateCounter(el, val, 800);
            }
            if (sub) sub.textContent = subs[key] || '';
        });

        // ── Charts ──
        if (doughnutChart) doughnutChart.destroy();
        doughnutChart = initStatusDoughnut('chart-status-doughnut', d.claimed, d.unclaimed);

        // ── Recent logs ──
        loadRecentLogs();

    } catch (err) {
        AdminToast.error('Failed to load stats: ' + err.message);
    } finally {
        btn.innerHTML = '<i class="fa-solid fa-rotate-right"></i> Refresh';
        btn.disabled  = false;
    }
}

async function loadRecentLogs() {
    try {
        const container = document.getElementById('recent-logs-list');
        const d = await AdminAjax.get('ajax/get_logs.php', { page: 1, per_page: 6 });
        const logsToShow = (d.logs || []).slice(0, 6);
        if (!logsToShow.length) {
            container.innerHTML = '<div class="admin-empty-state"><i class="fa-solid fa-inbox"></i><p>No activity yet.</p></div>';
            return;
        }

        container.innerHTML = logsToShow.map(log => {
            const m   = getActionMeta(log.action);
            const ts  = new Date(log.created_at).toLocaleString('en-KE', { dateStyle: 'short', timeStyle: 'short' });
            return `
            <div class="log-entry">
                <div class="log-icon" style="background:${m.color}18;color:${m.color};">
                    <i class="fa-solid ${m.icon}"></i>
                </div>
                <div class="log-entry-body">
                    <div class="log-entry-action">${log.action.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase())}</div>
                    <div class="log-entry-desc">${log.description || '—'}</div>
                    <div class="log-entry-meta">
                        <span><i class="fa-solid fa-user"></i>${log.username}</span>
                        <span><i class="fa-regular fa-clock"></i>${ts}</span>
                    </div>
                </div>
            </div>`;
        }).join('');

    } catch (err) {
        document.getElementById('recent-logs-list').innerHTML =
            '<div class="admin-empty-state"><p>Could not load logs.</p></div>';
    }
}

// Auto-load on page ready
document.addEventListener('DOMContentLoaded', loadStats);
</script>

<?php require_once __DIR__ . '/includes/admin_layout_footer.php'; ?>
