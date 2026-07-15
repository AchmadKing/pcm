<?php
/**
 * Role Management Page
 * PCM - Project Cost Management System
 * Only accessible by users with admin.roles permission
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();
requirePermission('admin.roles');

// Define all available permissions with labels
$allPermissions = [
    'Proyek' => [
        'projects.view' => 'Lihat Proyek',
        'projects.create' => 'Buat Proyek',
        'projects.edit' => 'Edit Proyek',
        'projects.delete' => 'Hapus Proyek',
        'projects.lock_request' => 'Lock/Unlock Pengajuan',
    ],
    'RAB' => [
        'rab.view' => 'Lihat RAB',
        'rab.edit' => 'Edit RAB',
    ],
    'RAP' => [
        'rap.view' => 'Lihat RAP',
        'rap.edit' => 'Edit RAP',
    ],
    'Pengajuan Dana' => [
        'requests.view' => 'Lihat Pengajuan',
        'requests.create' => 'Buat Pengajuan',
        'requests.approve' => 'Approve Pengajuan',
    ],
    'Laporan' => [
        'reports.view' => 'Lihat Laporan',
        'reports.export' => 'Export Laporan',
    ],
    'Master Data' => [
        'master_data.view' => 'Lihat Master Data',
        'master_data.edit' => 'Edit Master Data',
    ],
    'Administrasi' => [
        'admin.roles' => 'Manajemen Role',
        'admin.users' => 'Manajemen User',
    ],
];

// Handle AJAX requests BEFORE including header (which outputs HTML)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'get_roles':
                $roles = dbGetAll("
                    SELECT r.*, 
                        (SELECT COUNT(*) FROM users u WHERE u.role COLLATE utf8mb4_unicode_ci = r.name) as user_count
                    FROM roles r 
                    ORDER BY r.is_system DESC, r.name ASC
                ");
                echo json_encode(['success' => true, 'data' => $roles]);
                break;

            case 'toggle_role_type':
                $id = intval($_POST['id'] ?? 0);
                
                $role = dbGetRow("SELECT * FROM roles WHERE id = ?", [$id]);
                if (!$role) {
                    echo json_encode(['success' => false, 'message' => 'Role tidak ditemukan']);
                    break;
                }
                
                // Prevent changing super_admin role type
                if ($role['name'] === 'super_admin') {
                    echo json_encode(['success' => false, 'message' => 'Role Super Admin tidak dapat diubah tipenya']);
                    break;
                }
                
                $newType = $role['is_system'] ? 0 : 1;
                dbExecute("UPDATE roles SET is_system = ? WHERE id = ?", [$newType, $id]);
                
                $typeLabel = $newType ? 'Sistem' : 'Custom';
                echo json_encode(['success' => true, 'message' => 'Tipe role berhasil diubah ke ' . $typeLabel]);
                break;

            case 'add_role':
                $name = trim($_POST['name'] ?? '');
                $displayName = trim($_POST['display_name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                
                if (empty($name) || empty($displayName)) {
                    echo json_encode(['success' => false, 'message' => 'Nama dan display name harus diisi']);
                    break;
                }
                
                // Sanitize name to be lowercase alphanumeric with underscores
                $name = preg_replace('/[^a_za-z0-9_]/', '_', strtolower($name));
                
                // Check duplicate
                $existing = dbGetRow("SELECT id FROM roles WHERE name = ?", [$name]);
                if ($existing) {
                    echo json_encode(['success' => false, 'message' => 'Nama role sudah digunakan']);
                    break;
                }
                
                $roleId = dbInsert(
                    "INSERT INTO roles (name, display_name, description, is_system) VALUES (?, ?, ?, 0)",
                    [$name, $displayName, $description]
                );
                
                echo json_encode(['success' => true, 'message' => 'Role berhasil ditambahkan', 'id' => $roleId]);
                break;

            case 'edit_role':
                $id = intval($_POST['id'] ?? 0);
                $displayName = trim($_POST['display_name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                
                if ($id <= 0 || empty($displayName)) {
                    echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
                    break;
                }
                
                dbExecute(
                    "UPDATE roles SET display_name = ?, description = ? WHERE id = ?",
                    [$displayName, $description, $id]
                );
                
                echo json_encode(['success' => true, 'message' => 'Role berhasil diupdate']);
                break;

            case 'delete_role':
                $id = intval($_POST['id'] ?? 0);
                
                $role = dbGetRow("SELECT * FROM roles WHERE id = ?", [$id]);
                if (!$role) {
                    echo json_encode(['success' => false, 'message' => 'Role tidak ditemukan']);
                    break;
                }
                
                if ($role['is_system']) {
                    echo json_encode(['success' => false, 'message' => 'Role bawaan sistem tidak bisa dihapus']);
                    break;
                }
                
                // Check if any user uses this role
                $userCount = dbGetRow("SELECT COUNT(*) as cnt FROM users WHERE role = ?", [$role['name']]);
                if ($userCount['cnt'] > 0) {
                    echo json_encode(['success' => false, 'message' => 'Role masih digunakan oleh ' . $userCount['cnt'] . ' user']);
                    break;
                }
                
                dbExecute("DELETE FROM roles WHERE id = ?", [$id]);
                echo json_encode(['success' => true, 'message' => 'Role berhasil dihapus']);
                break;

            case 'get_permissions':
                $roleId = intval($_POST['role_id'] ?? 0);
                // Read from role_permissions table as source of truth
                $rows = dbGetAll("SELECT permission_key, is_allowed FROM role_permissions WHERE role_id = ?", [$roleId]);
                $permMap = [];
                foreach ($rows as $row) {
                    $permMap[$row['permission_key']] = intval($row['is_allowed']);
                }
                echo json_encode(['success' => true, 'data' => $permMap]);
                break;

            case 'save_permissions':
                $roleId = intval($_POST['role_id'] ?? 0);
                $permissions = json_decode($_POST['permissions'] ?? '{}', true);
                
                if ($roleId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Role tidak valid']);
                    break;
                }
                
                // Delete existing permissions from role_permissions table
                dbExecute("DELETE FROM role_permissions WHERE role_id = ?", [$roleId]);
                
                // Insert new permissions to role_permissions table
                foreach ($permissions as $key => $allowed) {
                    dbInsert(
                        "INSERT INTO role_permissions (role_id, permission_key, is_allowed) VALUES (?, ?, ?)",
                        [$roleId, $key, $allowed ? 1 : 0]
                    );
                }
                
                echo json_encode(['success' => true, 'message' => 'Hak akses berhasil disimpan']);
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
$pageTitle = 'Manajemen Role';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Title -->
<div class="row">
    <div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
            <h4 class="mb-sm-0">Manajemen Role</h4>
            <div class="page-title-right">
                <ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>/index.php">PCM</a></li>
                    <li class="breadcrumb-item"><a href="javascript:void(0);">Pengaturan</a></li>
                    <li class="breadcrumb-item active">Manajemen Role</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Role List -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="mt-0 header-title">Daftar Role</h4>
                    <button class="btn btn-primary" onclick="showAddRoleModal()">
                        <i class="mdi mdi-plus"></i> Tambah Role
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="rolesTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Nama Role</th>
                                <th>Display Name</th>
                                <th>Deskripsi</th>
                                <th>Jumlah User</th>
                                <th>Tipe</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="rolesTableBody">
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

<!-- Add/Edit Role Modal -->
<div class="modal fade" id="roleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="roleModalTitle">Tambah Role</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="roleId">
                <div class="mb-3" id="roleNameGroup">
                    <label for="roleName" class="form-label required">Nama Role (lowercase, tanpa spasi)</label>
                    <input type="text" class="form-control" id="roleName" placeholder="contoh: quality_control">
                    <small class="text-muted">Digunakan sebagai identifier internal. Hanya huruf kecil dan underscore.</small>
                </div>
                <div class="mb-3">
                    <label for="roleDisplayName" class="form-label required">Display Name</label>
                    <input type="text" class="form-control" id="roleDisplayName" placeholder="contoh: Quality Control">
                </div>
                <div class="mb-3">
                    <label for="roleDescription" class="form-label">Deskripsi</label>
                    <textarea class="form-control" id="roleDescription" rows="3" placeholder="Deskripsi singkat tentang role ini"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary" id="btnSaveRole" onclick="saveRole()">
                    <i class="mdi mdi-content-save"></i> Simpan
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Permissions Modal -->
<div class="modal fade" id="permissionsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Setting Hak Akses - <span id="permRoleName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="permRoleId">
                <div id="permissionsContent">
                    <?php foreach ($allPermissions as $group => $perms): ?>
                    <div class="card mb-3">
                        <div class="card-header bg-light py-2">
                            <h6 class="mb-0">
                                <i class="mdi mdi-folder-outline me-1"></i><?= $group ?>
                            </h6>
                        </div>
                        <div class="card-body py-2">
                            <div class="row">
                                <?php foreach ($perms as $key => $label): ?>
                                <div class="col-md-6 mb-2">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input perm-checkbox" type="checkbox" 
                                               id="perm_<?= str_replace('.', '_', $key) ?>" 
                                               data-key="<?= $key ?>">
                                        <label class="form-check-label" for="perm_<?= str_replace('.', '_', $key) ?>">
                                            <?= $label ?>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary" onclick="savePermissions()">
                    <i class="mdi mdi-content-save"></i> Simpan Hak Akses
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteRoleModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Hapus Role</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <i class="mdi mdi-alert-circle-outline text-danger" style="font-size: 3rem;"></i>
                <p class="mt-2">Apakah Anda yakin ingin menghapus role <strong id="deleteRoleName"></strong>?</p>
                <input type="hidden" id="deleteRoleId">
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-danger" onclick="confirmDeleteRole()">
                    <i class="mdi mdi-delete"></i> Hapus
                </button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

<script>
const BASE_URL = '<?= $baseUrl ?>';

// Load roles on page load
$(document).ready(function() {
    loadRoles();
});

function loadRoles() {
    $.post('', { action: 'get_roles' })
        .done(function(res) {
            if (res.success) {
                let html = '';
                res.data.forEach(function(role, idx) {
                    const isSystem = role.is_system == 1;
                    const isSuperAdmin = role.name === 'super_admin';
                    const badge = isSystem 
                        ? '<span class="badge bg-info">Sistem</span>' 
                        : '<span class="badge bg-secondary">Custom</span>';
                    
                    // Toggle type button - not available for super_admin
                    const toggleTypeBtn = !isSuperAdmin 
                        ? `<button class="btn btn-outline-${isSystem ? 'secondary' : 'info'} btn-action" onclick="toggleRoleType(${role.id}, '${escapeHtml(role.display_name)}', ${isSystem ? 1 : 0})" title="Ubah ke ${isSystem ? 'Custom' : 'Sistem'}">
                            <i class="mdi mdi-${isSystem ? 'swap-horizontal' : 'swap-horizontal'}"></i>
                        </button>` 
                        : '';
                    
                    html += `<tr>
                        <td>${idx + 1}</td>
                        <td><code>${role.name}</code></td>
                        <td>${escapeHtml(role.display_name)}</td>
                        <td>${escapeHtml(role.description || '-')}</td>
                        <td><span class="badge bg-primary">${role.user_count}</span></td>
                        <td>${badge}</td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary btn-action" onclick="showPermissionsModal(${role.id}, '${escapeHtml(role.display_name)}')" title="Hak Akses">
                                    <i class="mdi mdi-shield-key"></i>
                                </button>
                                ${toggleTypeBtn}
                                <button class="btn btn-outline-info btn-action" onclick="showEditRoleModal(${role.id}, '${escapeHtml(role.display_name)}', '${escapeHtml(role.description || '')}')" title="Edit">
                                    <i class="mdi mdi-pencil"></i>
                                </button>
                                ${!isSystem ? `<button class="btn btn-outline-danger btn-action" onclick="showDeleteRoleModal(${role.id}, '${escapeHtml(role.display_name)}')" title="Hapus">
                                    <i class="mdi mdi-delete"></i>
                                </button>` : ''}
                            </div>
                        </td>
                    </tr>`;
                });
                
                if (html === '') {
                    html = '<tr><td colspan="7" class="text-center text-muted">Belum ada data role.</td></tr>';
                }
                
                $('#rolesTableBody').html(html);
            } else {
                $('#rolesTableBody').html('<tr><td colspan="7" class="text-center text-danger">Gagal memuat data: ' + (res.message || 'Unknown error') + '</td></tr>');
            }
        })
        .fail(function(xhr, status, error) {
            console.error('AJAX Error:', status, error, xhr.responseText ? xhr.responseText.substring(0, 500) : '');
            $('#rolesTableBody').html('<tr><td colspan="7" class="text-center text-danger">Gagal memuat data. Response: ' + escapeHtml(error || status) + '</td></tr>');
        });
}

function showAddRoleModal() {
    $('#roleModalTitle').text('Tambah Role Baru');
    $('#roleId').val('');
    $('#roleName').val('').prop('disabled', false);
    $('#roleNameGroup').show();
    $('#roleDisplayName').val('');
    $('#roleDescription').val('');
    new bootstrap.Modal('#roleModal').show();
}

function showEditRoleModal(id, displayName, description) {
    $('#roleModalTitle').text('Edit Role');
    $('#roleId').val(id);
    $('#roleNameGroup').hide();
    $('#roleDisplayName').val(displayName);
    $('#roleDescription').val(description);
    new bootstrap.Modal('#roleModal').show();
}

function saveRole() {
    const id = $('#roleId').val();
    const data = {
        action: id ? 'edit_role' : 'add_role',
        display_name: $('#roleDisplayName').val(),
        description: $('#roleDescription').val()
    };
    
    if (id) {
        data.id = id;
    } else {
        data.name = $('#roleName').val();
    }
    
    $('#btnSaveRole').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Menyimpan...');
    
    $.post('', data, function(res) {
        $('#btnSaveRole').prop('disabled', false).html('<i class="mdi mdi-content-save"></i> Simpan');
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('roleModal')).hide();
            loadRoles();
            showToast('success', res.message);
        } else {
            showToast('danger', res.message);
        }
    }, 'json');
}

function showDeleteRoleModal(id, name) {
    $('#deleteRoleId').val(id);
    $('#deleteRoleName').text(name);
    new bootstrap.Modal('#deleteRoleModal').show();
}

function confirmDeleteRole() {
    const id = $('#deleteRoleId').val();
    $.post('', { action: 'delete_role', id: id }, function(res) {
        bootstrap.Modal.getInstance(document.getElementById('deleteRoleModal')).hide();
        if (res.success) {
            loadRoles();
            showToast('success', res.message);
        } else {
            showToast('danger', res.message);
        }
    }, 'json');
}

function toggleRoleType(id, name, currentIsSystem) {
    const newType = currentIsSystem ? 'Custom' : 'Sistem';
    if (!confirm(`Ubah tipe role "${name}" menjadi ${newType}?`)) return;
    
    $.post('', { action: 'toggle_role_type', id: id }, function(res) {
        if (res.success) {
            loadRoles();
            showToast('success', res.message);
        } else {
            showToast('danger', res.message);
        }
    }, 'json');
}

function showPermissionsModal(roleId, roleName) {
    $('#permRoleId').val(roleId);
    $('#permRoleName').text(roleName);
    
    // Reset all checkboxes
    $('.perm-checkbox').prop('checked', false);
    
    // Load current permissions
    $.post('', { action: 'get_permissions', role_id: roleId }, function(res) {
        if (res.success) {
            Object.keys(res.data).forEach(function(key) {
                if (res.data[key]) {
                    $(`#perm_${key.replace(/\./g, '_')}`).prop('checked', true);
                }
            });
        }
        new bootstrap.Modal('#permissionsModal').show();
    }, 'json');
}

function savePermissions() {
    const roleId = $('#permRoleId').val();
    const permissions = {};
    
    $('.perm-checkbox').each(function() {
        permissions[$(this).data('key')] = $(this).is(':checked') ? 1 : 0;
    });
    
    $.post('', { 
        action: 'save_permissions', 
        role_id: roleId, 
        permissions: JSON.stringify(permissions) 
    }, function(res) {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('permissionsModal')).hide();
            showToast('success', res.message);
        } else {
            showToast('danger', res.message);
        }
    }, 'json');
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
