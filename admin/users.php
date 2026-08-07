<?php
/**
 * UFAA Admin — User Management
 * Create, view, deactivate, change role, and reset passwords for admin users.
 */

require_once __DIR__ . '/includes/admin_auth.php';

$pageTitle       = 'User Management';
$adminActivePage = 'users';

require_once __DIR__ . '/includes/admin_layout.php';
?>

<!-- ── Page Header ── -->
<div class="admin-page-header">
    <div class="admin-page-header-left">
        <div class="admin-breadcrumb">
            <i class="fa-solid fa-house"></i>
            <a href="index.php">Dashboard</a>
            <i class="fa-solid fa-chevron-right"></i>
            <span>User Management</span>
        </div>
        <h2><i class="fa-solid fa-users-gear" style="color:var(--airtel-red);margin-right:.45rem;"></i>User Management</h2>
        <p>Create and manage portal admin accounts and roles.</p>
    </div>
    <button class="btn-admin-primary" onclick="adminOpenModal('modal-create-user')">
        <i class="fa-solid fa-user-plus"></i> Add User
    </button>
</div>

<!-- ── Filter Bar ── -->
<div class="admin-card admin-section">
    <div class="admin-filter-bar">
        <div class="admin-search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="admin-search-input" id="user-search"
                   placeholder="Search by username or email…" autocomplete="off">
        </div>
        <button class="btn-admin-outline" onclick="loadUsers()">
            <i class="fa-solid fa-rotate-right"></i> Refresh
        </button>
    </div>

    <!-- Users Table -->
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Created At</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="users-tbody">
                <tr>
                    <td colspan="8" style="text-align:center;padding:2rem;">
                        <div class="admin-spinner"></div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══════════════════ CREATE USER MODAL ═══════════════════ -->
<div class="admin-modal-overlay" id="modal-create-user">
    <div class="admin-modal">
        <div class="admin-modal-header">
            <h3><i class="fa-solid fa-user-plus" style="color:var(--airtel-red);"></i> Create New User</h3>
            <button class="admin-modal-close" onclick="adminCloseModal('modal-create-user')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="admin-modal-body">
            <div class="admin-form-row">
                <div class="admin-form-group">
                    <label for="cu-username">Username *</label>
                    <input type="text" id="cu-username" class="admin-input" placeholder="e.g. jdoe" autocomplete="off">
                </div>
                <div class="admin-form-group">
                    <label for="cu-fullname">Full Name</label>
                    <input type="text" id="cu-fullname" class="admin-input" placeholder="e.g. John Doe" autocomplete="off">
                </div>
            </div>
            <div class="admin-form-row">
                <div class="admin-form-group">
                    <label for="cu-email">Email</label>
                    <input type="email" id="cu-email" class="admin-input" placeholder="user@ufaa.go.ke" autocomplete="off">
                </div>
                <div class="admin-form-group">
                    <label for="cu-role">Role *</label>
                    <select id="cu-role" class="admin-select">
                        <option value="compliance_officer">Compliance Officer</option>
                        <option value="compliance_admin">Compliance Admin</option>
                        <option value="both">Both (Admin & Officer)</option>
                    </select>
                </div>
            </div>
            <div class="admin-form-row">
                <div class="admin-form-group" style="width:100%;">
                    <label for="cu-password">Password *</label>
                    <input type="password" id="cu-password" class="admin-input" placeholder="Min 6 characters" autocomplete="new-password">
                </div>
            </div>
            <div class="admin-form-hint">
                <strong>Roles:</strong> Compliance Admin = full management &nbsp;|&nbsp; Compliance Officer = operational access &nbsp;|&nbsp; Both = dual portal access
            </div>
        </div>
        <div class="admin-modal-footer">
            <button class="btn-admin-outline" onclick="adminCloseModal('modal-create-user')">Cancel</button>
            <button class="btn-admin-primary" id="btn-create-user" onclick="createUser()">
                <i class="fa-solid fa-check"></i> Create User
            </button>
        </div>
    </div>
</div>

<!-- ═══════════════════ CHANGE ROLE MODAL ═══════════════════ -->
<div class="admin-modal-overlay" id="modal-change-role">
    <div class="admin-modal" style="max-width:420px;">
        <div class="admin-modal-header">
            <h3><i class="fa-solid fa-user-gear" style="color:var(--airtel-red);"></i> Change User Role</h3>
            <button class="admin-modal-close" onclick="adminCloseModal('modal-change-role')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="admin-modal-body">
            <input type="hidden" id="cr-user-id">
            <p style="font-size:0.88rem;color:var(--text-secondary);margin-bottom:1rem;">
                Updating role for account: <strong id="cr-user-name" style="color:var(--text-primary);"></strong>
            </p>
            <div class="admin-form-group">
                <label for="cr-new-role">Select New Role *</label>
                <select id="cr-new-role" class="admin-select">
                    <option value="compliance_officer">Compliance Officer</option>
                    <option value="compliance_admin">Compliance Admin</option>
                    <option value="both">Both (Admin & Officer)</option>
                </select>
            </div>
            <div class="admin-form-hint" style="margin-top:0.75rem;">
                <strong>Admin</strong> = full management &nbsp;|&nbsp; <strong>Officer</strong> = operational &nbsp;|&nbsp; <strong>Both</strong> = dual portal access
            </div>
        </div>
        <div class="admin-modal-footer">
            <button class="btn-admin-outline" onclick="adminCloseModal('modal-change-role')">Cancel</button>
            <button class="btn-admin-primary" id="btn-save-role" onclick="doChangeRole()">
                <i class="fa-solid fa-check"></i> Save Role
            </button>
        </div>
    </div>
</div>

<script>
const isAdmin = <?= json_encode(($adminUser['role'] ?? '') !== 'compliance_officer') ?>;

async function loadUsers() {
    const search = document.getElementById('user-search').value.trim();
    const tbody  = document.getElementById('users-tbody');
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:2rem;"><div class="admin-spinner"></div></td></tr>';

    try {
        const d = await AdminAjax.get('ajax/manage_user.php', { action: 'list', search });
        if (!d.success) throw new Error(d.error);

        if (!d.users.length) {
            tbody.innerHTML = '<tr><td colspan="8"><div class="admin-empty-state"><i class="fa-solid fa-users-slash"></i><p>No users found.</p></div></td></tr>';
            return;
        }

        tbody.innerHTML = d.users.map((u, i) => {
            const statusBadge = u.is_active == 1
                ? '<span class="admin-badge badge-active"><i class="fa-solid fa-circle" style="font-size:.45rem;"></i> Active</span>'
                : '<span class="admin-badge badge-inactive"><i class="fa-solid fa-circle" style="font-size:.45rem;"></i> Suspended</span>';
            
            const lastLogin = u.last_login
                ? new Date(u.last_login).toLocaleDateString('en-KE')
                : '<span style="color:var(--text-muted);">Never</span>';
            const created   = new Date(u.created_at).toLocaleDateString('en-KE');

            // Format Role Badge
            let roleBadge = '';
            if (u.role === 'compliance_admin') {
                roleBadge = '<span class="admin-badge" style="background:rgba(204,0,0,0.1);color:#CC0000;border:1px solid rgba(204,0,0,0.25);"><i class="fa-solid fa-user-shield"></i> Compliance Admin</span>';
            } else if (u.role === 'both') {
                roleBadge = '<span class="admin-badge" style="background:rgba(124,58,237,0.1);color:#7c3aed;border:1px solid rgba(124,58,237,0.25);"><i class="fa-solid fa-user-astronaut"></i> Both (Admin & Officer)</span>';
            } else {
                roleBadge = '<span class="admin-badge" style="background:rgba(37,99,235,0.1);color:#2563eb;border:1px solid rgba(37,99,235,0.25);"><i class="fa-solid fa-user-check"></i> Compliance Officer</span>';
            }

            const suspendBtn = u.is_active == 1
                ? `<button class="table-action-btn" onclick="toggleActive(${u.id}, true)" title="Suspend user">
                    <i class="fa-solid fa-user-lock"></i> Suspend
                   </button>`
                : `<button class="table-action-btn" onclick="toggleActive(${u.id}, false)" style="color:#16a34a;border-color:rgba(22,163,74,0.35);" title="Unsuspend user">
                    <i class="fa-solid fa-user-check"></i> Unsuspend
                   </button>`;

            const actions = isAdmin ? `
                <div style="display:flex;gap:.35rem;flex-wrap:wrap;align-items:center;">
                    <button class="table-action-btn" onclick="openChangeRole(${u.id}, '${u.role}', '${escHtml(u.username)}')" title="Change Role">
                        <i class="fa-solid fa-user-gear"></i> Role
                    </button>
                    ${suspendBtn}
                    <button class="table-action-btn danger" onclick="deleteUser(${u.id})" title="Delete user">
                        <i class="fa-solid fa-trash"></i> Delete
                    </button>
                </div>` : '<span style="color:var(--text-muted);font-size:.78rem;">—</span>';

            const userDisplayName = u.fullname ? `<strong>${escHtml(u.fullname)}</strong><br><small style="color:var(--text-muted);">@${escHtml(u.username)}</small>` : `<strong>${escHtml(u.username)}</strong>`;

            return `<tr data-uid="${u.id}">
                <td style="color:var(--text-muted);">${i + 1}</td>
                <td>${userDisplayName}</td>
                <td style="color:var(--text-secondary);">${escHtml(u.email || '—')}</td>
                <td>${roleBadge}</td>
                <td>${created}</td>
                <td>${statusBadge}</td>
                <td>${lastLogin}</td>
                <td>${actions}</td>
            </tr>`;
        }).join('');

    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;color:var(--airtel-red);padding:1.5rem;">Error: ${err.message}</td></tr>`;
    }
}

async function createUser() {
    const btn = document.getElementById('btn-create-user');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Creating…';

    try {
        const d = await AdminAjax.post('ajax/manage_user.php', {
            action:   'create',
            username: document.getElementById('cu-username').value.trim(),
            fullname: document.getElementById('cu-fullname').value.trim(),
            email:    document.getElementById('cu-email').value.trim(),
            password: document.getElementById('cu-password').value,
            role:     document.getElementById('cu-role').value,
        });
        if (!d.success) throw new Error(d.error);
        AdminToast.success('User created successfully!');
        adminCloseModal('modal-create-user');
        loadUsers();
    } catch (err) {
        AdminToast.error(err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Create User';
    }
}

async function toggleActive(userId, isCurrentlyActive) {
    const isSuspending = (isCurrentlyActive === true || isCurrentlyActive == 1);
    const title = isSuspending ? 'Suspend User?' : 'Unsuspend User?';
    const msg   = isSuspending
        ? 'This user will be suspended and won\'t be able to access the portal.'
        : 'This user will be unsuspended and regain access to the portal.';

    const confirmed = await AdminConfirm.show(title, msg);
    if (!confirmed) return;

    try {
        const d = await AdminAjax.post('ajax/manage_user.php', { action: 'toggle_active', user_id: userId });
        if (!d.success) throw new Error(d.error);
        AdminToast.success(isSuspending ? 'User suspended successfully.' : 'User unsuspended successfully.');
        loadUsers();
    } catch (err) {
        AdminToast.error(err.message);
    }
}

function openChangeRole(userId, currentRole, username) {
    document.getElementById('cr-user-id').value = userId;
    document.getElementById('cr-user-name').textContent = username;
    document.getElementById('cr-new-role').value = currentRole || 'compliance_officer';
    adminOpenModal('modal-change-role');
}

async function doChangeRole() {
    const userId  = document.getElementById('cr-user-id').value;
    const newRole = document.getElementById('cr-new-role').value;
    const btn     = document.getElementById('btn-save-role');

    btn.disabled  = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    try {
        const d = await AdminAjax.post('ajax/manage_user.php', { action: 'change_role', user_id: userId, role: newRole });
        if (!d.success) throw new Error(d.error);
        AdminToast.success('User role updated successfully.');
        adminCloseModal('modal-change-role');
        loadUsers();
    } catch (err) {
        AdminToast.error(err.message);
    } finally {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Save Role';
    }
}

async function deleteUser(userId) {
    const confirmed = await AdminConfirm.show('Delete user?', 'This is permanent and cannot be undone.');
    if (!confirmed) return;
    try {
        const d = await AdminAjax.post('ajax/manage_user.php', { action: 'delete', user_id: userId });
        if (!d.success) throw new Error(d.error);
        AdminToast.success('User deleted.');
        loadUsers();
    } catch (err) {
        AdminToast.error(err.message);
    }
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

// Live search debounce & automatic page load
document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('user-search');
    if (searchInput) {
        let timer = null;
        searchInput.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(loadUsers, 350);
        });
    }
    loadUsers();
});

// Immediate load fallback if DOM is already ready
if (document.readyState === 'interactive' || document.readyState === 'complete') {
    loadUsers();
}
</script>

<?php require_once __DIR__ . '/includes/admin_layout_footer.php'; ?>
