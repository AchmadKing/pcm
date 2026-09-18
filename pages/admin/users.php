<?php
/**
 * User Management Page
 * PCC - Project Cost Control System
 * Only accessible by users with admin.users permission
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();
requirePermission('admin.users');

// Get all roles for dropdown
$roles = dbGetAll("SELECT id, name, display_name FROM roles ORDER BY name ASC");

// Handle AJAX requests BEFORE including header (which outputs HTML)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'get_users':
                $users = dbGetAll("
                    SELECT u.id, u.username, u.full_name, u.role, u.is_active, u.created_at, u.updated_at,
                           COALESCE(r.display_name, u.role) as role_display
                    FROM users u
                    LEFT JOIN roles r ON r.name = u.role COLLATE utf8mb4_unicode_ci
                    ORDER BY u.role ASC, u.username ASC
                ");
                echo json_encode(['success' => true, 'data' => $users]);
                break;

            case 'add_user':
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                $fullName = trim($_POST['full_name'] ?? '');
                $role = trim($_POST['role'] ?? 'field_team');
                $isActive = intval($_POST['is_active'] ?? 1);
                
                if (empty($username) || empty($password) || empty($fullName)) {
                    echo json_encode(['success' => false, 'message' => 'Username, password, dan nama lengkap harus diisi']);
                    break;
                }
                
                if (strlen($password) < 6) {
                    echo json_encode(['success' => false, 'message' => 'Password minimal 6 karakter']);
                    break;
                }
                
                // Check duplicate username
                $existing = dbGetRow("SELECT id FROM users WHERE username = ?", [$username]);
                if ($existing) {
                    echo json_encode(['success' => false, 'message' => 'Username sudah digunakan']);
                    break;
                }
                
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                
                $userId = dbInsert(
                    "INSERT INTO users (username, password, full_name, role, is_active) VALUES (?, ?, ?, ?, ?)",
                    [$username, $hashedPassword, $fullName, $role, $isActive]
                );
                
                echo json_encode(['success' => true, 'message' => 'User berhasil ditambahkan', 'id' => $userId]);
                break; 

            case 'get_user':
                $id = intval($_POST['id'] ?? 0);
                $user = dbGetRow("SELECT id, username, full_name, role, is_active FROM users WHERE id = ?", [$id]);
                if ($user) {
                    echo json_encode(['success' => true, 'data' => $user]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'User tidak ditemukan']);
                }
                break;

            case 'edit_user':
                $id = intval($_POST['id'] ?? 0);
                $username = trim($_POST['username'] ?? '');
                $fullName = trim($_POST['full_name'] ?? '');
                $role = trim($_POST['role'] ?? 'field_team');
                $isActive = intval($_POST['is_active'] ?? 1);
                
                if ($id <= 0 || empty($username) || empty($fullName)) {
                    echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
                    break;
                }
                
                // Check duplicate username (exclude current user)
                $existing = dbGetRow("SELECT id FROM users WHERE username = ? AND id != ?", [$username, $id]);
                if ($existing) {
                    echo json_encode(['success' => false, 'message' => 'Username sudah digunakan oleh user lain']);
                    break;
                }
                
                // Prevent changing own role (super admin safety)
                if ($id == getCurrentUserId()) {
                    $role = 'super_admin'; // Cannot change own role
                }
                
                dbExecute(
                    "UPDATE users SET username = ?, full_name = ?, role = ?, is_active = ? WHERE id = ?",
                    [$username, $fullName, $role, $isActive, $id]
                );
                
                echo json_encode(['success' => true, 'message' => 'User berhasil diupdate']);
                break;

            case 'reset_password':
                $id = intval($_POST['id'] ?? 0);
                $newPassword = $_POST['new_password'] ?? '';
                
                if ($id <= 0 || empty($newPassword)) {
                    echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
                    break;
                }
                
                if (strlen($newPassword) < 6) {
                    echo json_encode(['success' => false, 'message' => 'Password minimal 6 karakter']);
                    break;
                }
                
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                dbExecute("UPDATE users SET password = ? WHERE id = ?", [$hashedPassword, $id]);
                
                echo json_encode(['success' => true, 'message' => 'Password berhasil direset']);
                break;

            case 'toggle_active':
                $id = intval($_POST['id'] ?? 0);
                $isActive = intval($_POST['is_active'] ?? 0);
                
                // Prevent deactivating self
                if ($id == getCurrentUserId()) {
                    echo json_encode(['success' => false, 'message' => 'Tidak bisa menonaktifkan akun sendiri']);
                    break;
                }
                
                dbExecute("UPDATE users SET is_active = ? WHERE id = ?", [$isActive, $id]);
                $statusText = $isActive ? 'diaktifkan' : 'dinonaktifkan';
                echo json_encode(['success' => true, 'message' => "User berhasil $statusText"]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Only include header for normal page loads (not AJAX)
$pageTitle = 'Manajemen User';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Title -->
<div class="row">
    <div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
            <h4 class="mb-sm-0">Manajemen User</h4>
            <div class="page-title-right">
                <ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>/index.php">PCC</a></li>
                    <li class="breadcrumb-item"><a href="javascript:void(0);">Pengaturan</a></li>
                    <li class="breadcrumb-item active">Manajemen User</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- User List -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="mt-0 header-title">Daftar User</h4>
                    <button class="btn btn-primary" onclick="showAddUserModal()">
                        <i class="mdi mdi-account-plus"></i> Tambah User
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="usersTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Username</th>
                                <th>Nama Lengkap</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Dibuat</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                            <tr>
                                <td colspan="7" class="text-center">
                                    <div class="spinner-border spinner-border-sm text-primary" role="status"></div> Memuat data...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit User Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userModalTitle">Tambah User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="userId">
                <div class="mb-3">
                    <label for="userUsername" class="form-label required">Username</label>
                    <input type="text" class="form-control" id="userUsername" placeholder="Masukkan username" autocomplete="off">
                </div>
                <div class="mb-3" id="passwordGroup">
                    <label for="userPassword" class="form-label required">Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="userPassword" placeholder="Minimal 6 karakter" autocomplete="new-password">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('userPassword', this)">
                            <i class="mdi mdi-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="userFullName" class="form-label required">Nama Lengkap</label>
                    <input type="text" class="form-control" id="userFullName" placeholder="Masukkan nama lengkap">
                </div>
                <div class="mb-3">
                    <label for="userRole" class="form-label required">Role</label>
                    <select class="form-select" id="userRole">
                        <?php foreach ($roles as $role): ?>
                        <option value="<?= $role['name'] ?>"><?= sanitize($role['display_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="userIsActive" class="form-label">Status</label>
                    <select class="form-select" id="userIsActive">
                        <option value="1">Aktif</option>
                        <option value="0">Nonaktif</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary" id="btnSaveUser" onclick="saveUser()">
                    <i class="mdi mdi-content-save"></i> Simpan
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">Reset Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="resetUserId">
                <p class="text-muted mb-3">Reset password untuk user: <strong id="resetUserName"></strong></p>
                <div class="mb-3">
                    <label for="newPassword" class="form-label required">Password Baru</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="newPassword" placeholder="Minimal 6 karakter" autocomplete="new-password">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('newPassword', this)">
                            <i class="mdi mdi-eye"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-warning" onclick="confirmResetPassword()">
                    <i class="mdi mdi-lock-reset"></i> Reset Password
                </button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

<script>
const BASE_URL = '<?= $baseUrl ?>';
const CURRENT_USER_ID = <?= getCurrentUserId() ?>;

// Load users on page load
$(document).ready(function() {
    loadUsers();
});

function loadUsers() {
    $.post('', { action: 'get_users' }, function(res) {
        if (res.success) {
            let html = '';
            res.data.forEach(function(user, idx) {
                const isSelf = user.id == CURRENT_USER_ID;
                const statusBadge = user.is_active == 1
                    ? '<span class="badge bg-success">Aktif</span>'
                    : '<span class="badge bg-danger">Nonaktif</span>';
                const roleBadge = getRoleBadge(user.role, user.role_display);
                const createdDate = new Date(user.created_at).toLocaleDateString('id-ID', { 
                    day: '2-digit', month: 'short', year: 'numeric' 
                });
                
                html += `<tr${!user.is_active ? ' class="table-secondary"' : ''}>
                    <td>${idx + 1}</td>
                    <td><strong>${escapeHtml(user.username)}</strong>${isSelf ? ' <span class="badge bg-info">Anda</span>' : ''}</td>
                    <td>${escapeHtml(user.full_name)}</td>
                    <td>${roleBadge}</td>
                    <td>${statusBadge}</td>
                    <td>${createdDate}</td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-info btn-action" onclick="showEditUserModal(${user.id})" title="Edit">
                                <i class="mdi mdi-pencil"></i>
                            </button>
                            <button class="btn btn-outline-warning btn-action" onclick="showResetPasswordModal(${user.id}, '${escapeHtml(user.username)}')" title="Reset Password">
                                <i class="mdi mdi-lock-reset"></i>
                            </button>
                            ${!isSelf ? `<button class="btn btn-outline-${user.is_active == 1 ? 'danger' : 'success'} btn-action" 
                                onclick="toggleUserActive(${user.id}, ${user.is_active == 1 ? 0 : 1})" 
                                title="${user.is_active == 1 ? 'Nonaktifkan' : 'Aktifkan'}">
                                <i class="mdi mdi-${user.is_active == 1 ? 'account-off' : 'account-check'}"></i>
                            </button>` : ''}
                        </div>
                    </td>
                </tr>`;
            });
            
            if (html === '') {
                html = '<tr><td colspan="7" class="text-center text-muted">Belum ada data user.</td></tr>';
            }
            
            $('#usersTableBody').html(html);
        }
    }, 'json');
}

function getRoleBadge(role, displayName) {
    const colors = {
        'super_admin': 'danger',
        'admin': 'primary',
        'project_manager': 'info',
        'field_team': 'success'
    };
    const color = colors[role] || 'secondary';
    return `<span class="badge bg-${color}">${escapeHtml(displayName)}</span>`;
}

function showAddUserModal() {
    $('#userModalTitle').text('Tambah User Baru');
    $('#userId').val('');
    $('#userUsername').val('').prop('disabled', false);
    $('#userPassword').val('');
    $('#passwordGroup').show();
    $('#userFullName').val('');
    $('#userRole').val('field_team');
    $('#userIsActive').val('1');
    new bootstrap.Modal('#userModal').show();
}

function showEditUserModal(id) {
    $.post('', { action: 'get_user', id: id }, function(res) {
        if (res.success) {
            const user = res.data;
            $('#userModalTitle').text('Edit User');
            $('#userId').val(user.id);
            $('#userUsername').val(user.username).prop('disabled', false);
            $('#passwordGroup').hide();
            $('#userFullName').val(user.full_name);
            $('#userRole').val(user.role);
            $('#userIsActive').val(user.is_active);
            
            // Disable role change for self
            if (user.id == CURRENT_USER_ID) {
                $('#userRole').prop('disabled', true);
            } else {
                $('#userRole').prop('disabled', false);
            }
            
            new bootstrap.Modal('#userModal').show();
        } else {
            showToast('danger', res.message);
        }
    }, 'json');
}

function saveUser() {
    const id = $('#userId').val();
    const data = {
        action: id ? 'edit_user' : 'add_user',
        username: $('#userUsername').val(),
        full_name: $('#userFullName').val(),
        role: $('#userRole').val(),
        is_active: $('#userIsActive').val()
    };
    
    if (!id) {
        data.password = $('#userPassword').val();
    } else {
        data.id = id;
    }
    
    $('#btnSaveUser').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Menyimpan...');
    
    $.post('', data, function(res) {
        $('#btnSaveUser').prop('disabled', false).html('<i class="mdi mdi-content-save"></i> Simpan');
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('userModal')).hide();
            loadUsers();
            showToast('success', res.message);
        } else {
            showToast('danger', res.message);
        }
    }, 'json');
}

function showResetPasswordModal(id, username) {
    $('#resetUserId').val(id);
    $('#resetUserName').text(username);
    $('#newPassword').val('');
    new bootstrap.Modal('#resetPasswordModal').show();
}

function confirmResetPassword() {
    const id = $('#resetUserId').val();
    const newPassword = $('#newPassword').val();
    
    if (!newPassword || newPassword.length < 6) {
        showToast('danger', 'Password minimal 6 karakter');
        return;
    }
    
    $.post('', { action: 'reset_password', id: id, new_password: newPassword }, function(res) {
        bootstrap.Modal.getInstance(document.getElementById('resetPasswordModal')).hide();
        if (res.success) {
            showToast('success', res.message);
        } else {
            showToast('danger', res.message);
        }
    }, 'json');
}

function toggleUserActive(id, newStatus) {
    const action = newStatus == 1 ? 'mengaktifkan' : 'menonaktifkan';
    if (!confirm(`Apakah Anda yakin ingin ${action} user ini?`)) return;
    
    $.post('', { action: 'toggle_active', id: id, is_active: newStatus }, function(res) {
        if (res.success) {
            loadUsers();
            showToast('success', res.message);
        } else {
            showToast('danger', res.message);
        }
    }, 'json');
}

function togglePasswordVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('mdi-eye', 'mdi-eye-off');
    } else {
        input.type = 'password';
        icon.classList.replace('mdi-eye-off', 'mdi-eye');
    }
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function showToast(type, message) {
    const toastHtml = `
        <div class="alert alert-${type} alert-dismissible fade show position-fixed" 
             style="top: 80px; right: 20px; z-index: 9999; min-width: 300px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
            <i class="mdi mdi-${type === 'success' ? 'check-circle' : 'alert-circle'}"></i> ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>`;
    $('body').append(toastHtml);
    setTimeout(() => { $('.alert').alert('close'); }, 3000);
}
</script>
