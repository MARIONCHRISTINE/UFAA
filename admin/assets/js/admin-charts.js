/**
 * UFAA Admin — Chart Utilities (admin-charts.js)
 * Initialised on the Dashboard page after Chart.js loads.
 * Chart.js is loaded from CDN in admin_layout_footer.php.
 */

'use strict';

/* ── Brand colour palette for charts ── */
const CHART_COLORS = {
    red:    '#CC0000',
    navy:   '#1e3a5f',
    blue:   '#2a5298',
    green:  '#16a34a',
    orange: '#ea580c',
    gray:   '#9ca3af',

    redAlpha:   'rgba(204,0,0,0.15)',
    navyAlpha:  'rgba(30,58,95,0.15)',
    greenAlpha: 'rgba(22,163,74,0.15)',
    orangeAlpha:'rgba(234,88,12,0.15)',
};

/* ── Default Chart.js overrides ── */
if (typeof Chart !== 'undefined') {
    Chart.defaults.font.family = "'Outfit', sans-serif";
    Chart.defaults.font.size   = 12;
    Chart.defaults.color       = '#6b7280';
    Chart.defaults.plugins.legend.labels.boxWidth = 12;
    Chart.defaults.plugins.legend.labels.padding  = 16;
}

/* ═══════════════════════════════════════════════════════════════
   DOUGHNUT — Claimed vs Unclaimed
═══════════════════════════════════════════════════════════════ */
function initStatusDoughnut(canvasId, claimed, unclaimed) {
    const ctx = document.getElementById(canvasId);
    if (!ctx || typeof Chart === 'undefined') return null;

    return new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Claimed', 'Unclaimed'],
            datasets: [{
                data: [claimed, unclaimed],
                backgroundColor: [CHART_COLORS.green, CHART_COLORS.red],
                hoverBackgroundColor: ['#15803d', '#a80000'],
                borderWidth: 0,
                hoverOffset: 6,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '50%',
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ` ${ctx.label}: ${ctx.parsed.toLocaleString()}`,
                    },
                },
            },
        },
    });
}

/* ═══════════════════════════════════════════════════════════════
   BAR — Uploads per month (last 6 months)
═══════════════════════════════════════════════════════════════ */
function initUploadsBar(canvasId, labels, data) {
    const ctx = document.getElementById(canvasId);
    if (!ctx || typeof Chart === 'undefined') return null;

    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Records Uploaded',
                data,
                backgroundColor: CHART_COLORS.navyAlpha,
                borderColor:     CHART_COLORS.navy,
                borderWidth: 2,
                borderRadius: 6,
                hoverBackgroundColor: CHART_COLORS.navy,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 11 } },
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: (val) => val.toLocaleString(),
                        font: { size: 11 },
                    },
                    grid: { color: 'rgba(0,0,0,0.05)' },
                },
            },
        },
    });
}

/* ═══════════════════════════════════════════════════════════════
   LINE — Activity trend (last 30 days)
═══════════════════════════════════════════════════════════════ */
function initActivityLine(canvasId, labels, data) {
    const ctx = document.getElementById(canvasId);
    if (!ctx || typeof Chart === 'undefined') return null;

    return new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: 'Actions',
                data,
                borderColor:     CHART_COLORS.red,
                backgroundColor: CHART_COLORS.redAlpha,
                borderWidth: 2.5,
                pointRadius: 3,
                pointHoverRadius: 6,
                fill: true,
                tension: 0.4,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { maxTicksLimit: 8, font: { size: 10 } },
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,0.05)' },
                    ticks: { font: { size: 11 }, precision: 0 },
                },
            },
        },
    });
}

/* ═══════════════════════════════════════════════════════════════
   HORIZONTAL BAR — Top users by actions
═══════════════════════════════════════════════════════════════ */
function initUserActivityBar(canvasId, labels, data) {
    const ctx = document.getElementById(canvasId);
    if (!ctx || typeof Chart === 'undefined') return null;

    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Actions',
                data,
                backgroundColor: [
                    CHART_COLORS.red,
                    CHART_COLORS.blue,
                    CHART_COLORS.green,
                    CHART_COLORS.orange,
                    CHART_COLORS.gray,
                ],
                borderRadius: 6,
            }],
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: { precision: 0, font: { size: 11 } },
                    grid: { color: 'rgba(0,0,0,0.05)' },
                },
                y: {
                    grid: { display: false },
                    ticks: { font: { size: 11 } },
                },
            },
        },
    });
}
