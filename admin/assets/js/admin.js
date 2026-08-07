/**
 * UFAA Admin — Shared JavaScript (admin.js)
 * Sidebar toggle, toast notifications, confirm dialogs, AJAX helpers.
 */

'use strict';

/* ═══════════════════════════════════════════════════════════════
   SIDEBAR MOBILE TOGGLE (Admin portal — runs after DOM ready)
═══════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function initAdminSidebar() {
    const btn     = document.getElementById('mobile-menu-btn');
    const sidebar = document.querySelector('.admin-sidebar') || document.querySelector('.sidebar');
    if (!btn || !sidebar) return;

    // Prevent double-binding if app.js already attached a handler
    if (btn.dataset.sidebarBound === '1') return;
    btn.dataset.sidebarBound = '1';

    function openSidebar() {
        sidebar.classList.add('open');
        document.body.classList.add('sidebar-open');
        const icon = btn.querySelector('i');
        if (icon) icon.className = 'fa-solid fa-xmark';
    }

    function closeSidebar() {
        sidebar.classList.remove('open');
        document.body.classList.remove('sidebar-open');
        const icon = btn.querySelector('i');
        if (icon) icon.className = 'fa-solid fa-bars';
    }

    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
    });

    // Close when clicking the dark overlay backdrop
    document.body.addEventListener('click', function(e) {
        if (document.body.classList.contains('sidebar-open') &&
            !sidebar.contains(e.target) && e.target !== btn) {
            closeSidebar();
        }
    });

    // Close on nav link click
    sidebar.querySelectorAll('.nav-item').forEach(function(link) {
        link.addEventListener('click', function() { closeSidebar(); });
    });
});
/* ═══════════════════════════════════════════════════════════════
   TOAST NOTIFICATIONS
═══════════════════════════════════════════════════════════════ */
const AdminToast = {
    show(message, type = 'info', duration = 3500) {
        const holder = document.getElementById('toast-holder');
        if (!holder) return;

        const icons = {
            success: 'fa-circle-check',
            error:   'fa-circle-xmark',
            warning: 'fa-triangle-exclamation',
            info:    'fa-circle-info',
        };

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <i class="fa-solid ${icons[type] || icons.info}"></i>
            <span>${message}</span>
            <button onclick="this.parentElement.remove()" class="toast-close">
                <i class="fa-solid fa-xmark"></i>
            </button>`;

        holder.appendChild(toast);

        // Animate in
        requestAnimationFrame(() => toast.classList.add('toast-visible'));

        // Auto dismiss
        setTimeout(() => {
            toast.classList.remove('toast-visible');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, duration);
    },

    success(msg) { this.show(msg, 'success'); },
    error(msg)   { this.show(msg, 'error'); },
    warning(msg) { this.show(msg, 'warning'); },
    info(msg)    { this.show(msg, 'info'); },
};

/* ═══════════════════════════════════════════════════════════════
   CONFIRM DIALOG
═══════════════════════════════════════════════════════════════ */
const AdminConfirm = {
    _resolve: null,

    show(title = 'Are you sure?', message = 'This action cannot be undone.') {
        return new Promise((resolve) => {
            this._resolve = resolve;
            const overlay = document.getElementById('admin-confirm-overlay');
            document.getElementById('admin-confirm-title').textContent   = title;
            document.getElementById('admin-confirm-message').textContent = message;
            overlay.classList.add('visible');

            document.getElementById('admin-confirm-ok').onclick = () => {
                overlay.classList.remove('visible');
                resolve(true);
            };
            document.getElementById('admin-confirm-cancel').onclick = () => {
                overlay.classList.remove('visible');
                resolve(false);
            };
        });
    },
};

/** Called from the sidebar logout link */
function adminConfirmLogout() {
    AdminConfirm.show('Logout?', 'You will be signed out of the admin panel.').then((confirmed) => {
        if (confirmed) {
            window.location.href = '../logout.php';
        }
    });
}

/* ═══════════════════════════════════════════════════════════════
   AJAX HELPER
═══════════════════════════════════════════════════════════════ */
const AdminAjax = {
    /**
     * POST JSON data to a URL and return parsed response.
     */
    async post(url, data = {}) {
        const body = new URLSearchParams(data);
        const resp = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
        });
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
        return resp.json();
    },

    /**
     * GET with optional query params.
     */
    async get(url, params = {}) {
        const qs = new URLSearchParams(params).toString();
        const fullUrl = qs ? `${url}?${qs}` : url;
        const resp = await fetch(fullUrl);
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
        return resp.json();
    },
};

/* ═══════════════════════════════════════════════════════════════
   MODAL HELPERS
═══════════════════════════════════════════════════════════════ */
function adminOpenModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('visible');
}

function adminCloseModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('visible');
}

// Close modal when clicking overlay background
document.addEventListener('click', (e) => {
    if (e.target.classList.contains('admin-modal-overlay')) {
        e.target.classList.remove('visible');
    }
});

/* ═══════════════════════════════════════════════════════════════
   NUMBER COUNTER ANIMATION
═══════════════════════════════════════════════════════════════ */
function animateCounter(el, target, duration = 900) {
    const start     = performance.now();
    const startVal  = 0;

    function update(now) {
        const elapsed  = now - start;
        const progress = Math.min(elapsed / duration, 1);
        const eased    = 1 - Math.pow(1 - progress, 3); // ease-out cubic
        const current  = Math.round(startVal + (target - startVal) * eased);

        el.textContent = current.toLocaleString();
        if (progress < 1) requestAnimationFrame(update);
    }

    requestAnimationFrame(update);
}

/**
 * Animate all .stat-value elements that have data-count attribute.
 */
function animateAllStats() {
    document.querySelectorAll('.stat-value[data-count], .stat-number[data-count]').forEach((el) => {
        const target = parseInt(el.dataset.count, 10);
        if (!isNaN(target)) animateCounter(el, target);
    });
}

document.addEventListener('DOMContentLoaded', animateAllStats);

/* ═══════════════════════════════════════════════════════════════
   SEARCH DEBOUNCE UTILITY
═══════════════════════════════════════════════════════════════ */
function debounce(fn, delay = 300) {
    let timer;
    return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delay);
    };
}
