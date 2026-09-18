<?php
/**
 * Role Management Page
 * PCC - Project Cost Control System
 * Only accessible by users with admin.roles permission
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();
requirePermission('admin.roles');

// Structured permission definitions with clear scope and parent-child dependencies
$permissionDefinitions = [
    'project_global' => [
        'title' => 'Manajemen Proyek (Global)',
        'description' => 'Mengatur level akses melihat proyek dan izin operasi umum proyek',
        'icon' => 'mdi-briefcase-outline',
        'badge' => 'Global Proyek',
        'badge_class' => 'bg-primary',
        'items' => [
            'projects.create' => ['label' => 'Buat Proyek Baru', 'desc' => 'Dapat membuat proyek baru di sistem', 'parent' => null],
            'projects.edit' => ['label' => 'Edit Data Proyek', 'desc' => 'Dapat mengubah informasi, tanggal, dan status proyek', 'parent' => null],
            'projects.delete' => ['label' => 'Hapus Proyek', 'desc' => 'Dapat menghapus proyek yang masih berstatus Draft', 'parent' => null],
            'projects.lock_request' => ['label' => 'Lock / Unlock Pengajuan', 'desc' => 'Dapat mengunci atau membuka form pengajuan dana proyek', 'parent' => null],
        ]
    ],
    'project_tabs' => [
        'title' => 'Akses Tab di Dalam Proyek',
        'description' => 'Mengatur hak akses tab yang dapat dibuka saat user berada di halaman detail proyek (Dashboard Proyek)',
        'icon' => 'mdi-tab',
        'badge' => 'Tab Detail Proyek',
        'badge_class' => 'bg-info',
        'groups' => [
            'master_data' => [
                'name' => 'Tab Master Data (Item & AHSP)',
                'icon' => 'mdi-database',
                'parent_key' => 'master_data.view',
                'items' => [
                    'master_data.view' => ['label' => 'Lihat Tab Master Data', 'desc' => 'Dapat membuka tab Master Data dan melihat item material/upah/alat serta AHSP proyek', 'is_parent' => true, 'parent' => null],
                    'master_data.edit' => ['label' => 'Edit & Kelola Master Data', 'desc' => 'Dapat menambah, mengedit, dan menghapus item/AHSP di proyek', 'parent' => 'master_data.view'],
                ]
            ],
            'rab' => [
                'name' => 'Tab RAB (Rencana Anggaran Biaya)',
                'icon' => 'mdi-file-document-outline',
                'parent_key' => 'rab.view',
                'items' => [
                    'rab.view' => ['label' => 'Lihat Tab RAB', 'desc' => 'Dapat membuka tab RAB dan melihat rincian rencana biaya kontrak', 'is_parent' => true, 'parent' => null],
                    'rab.edit' => ['label' => 'Input & Edit Item RAB', 'desc' => 'Dapat menambah kategori, subkategori, volume, dan harga RAB', 'parent' => 'rab.view'],
                ]
            ],
            'rap' => [
                'name' => 'Tab RAP (Rencana Anggaran Pelaksanaan)',
                'icon' => 'mdi-file-document-multiple-outline',
                'parent_key' => 'rap.view',
                'items' => [
                    'rap.view' => ['label' => 'Lihat Tab RAP', 'desc' => 'Dapat membuka tab RAP dan melihat anggaran pelaksanaan kerja', 'is_parent' => true, 'parent' => null],
                    'rap.edit' => ['label' => 'Input & Edit Item RAP', 'desc' => 'Dapat menyusun dan mengunci anggaran pelaksanaan proyek', 'parent' => 'rap.view'],
                ]
            ],
            'actual' => [
                'name' => 'Tab Realisasi (Aktual)',
                'icon' => 'mdi-cash-check',
                'parent_key' => null,
                'items' => [
                    'reports.view' => ['label' => 'Lihat Tab Realisasi', 'desc' => 'Dapat membuka tab Realisasi pengeluaran dan melihat progress mingguan proyek', 'parent' => null],
                ]
            ],
            'tab_requests' => [
                'name' => 'Tab Pengajuan (Di Dalam Proyek)',
                'icon' => 'mdi-file-document-edit-outline',
                'parent_key' => 'requests.view',
                'items' => [
                    'requests.view' => ['label' => 'Lihat Tab Pengajuan Proyek', 'desc' => 'Dapat melihat daftar pengajuan dan penugasan tim di proyek terkait', 'is_parent' => true, 'parent' => null],
                    'requests.create' => ['label' => 'Buat Pengajuan dari Tab Proyek', 'desc' => 'Dapat membuat pengajuan dana baru langsung dari tab proyek', 'parent' => 'requests.view'],
                    'requests.delete' => ['label' => 'Hapus Pengajuan di Tab Proyek', 'desc' => 'Dapat menghapus pengajuan dana langsung dari tab proyek', 'parent' => 'requests.view'],
                ]
            ],
            'documentation' => [
                'name' => 'Tab Dokumentasi Proyek',
                'icon' => 'mdi-folder-multiple-image',
                'parent_key' => 'documentation.view',
                'items' => [
                    'documentation.view' => ['label' => 'Lihat Tab Dokumentasi', 'desc' => 'Dapat membuka tab Dokumentasi Proyek dan melihat serta mendownload file dokumen', 'is_parent' => true, 'parent' => null],
                    'documentation.upload' => ['label' => 'Upload & Kelola Dokumen', 'desc' => 'Dapat mengunggah file baru, mengubah keterangan, dan menghapus dokumen proyek', 'parent' => 'documentation.view'],
                ]
            ],
        ]
    ],
    'sidebar_menus' => [
        'title' => 'Menu Sidebar & Fitur Global',
        'description' => 'Mengatur hak akses menu navigasi utama pada sidebar sistem (independen dari proyek)',
        'icon' => 'mdi-menu',
        'badge' => 'Menu Sidebar',
        'badge_class' => 'bg-secondary',
        'groups' => [
            'sidebar_requests' => [
                'name' => 'Menu Pengajuan Dana (Sidebar)',
                'icon' => 'mdi-file-document-edit',
                'parent_key' => 'requests.view',
                'items' => [
                    'requests.view' => ['label' => 'Buka Menu Daftar Pengajuan', 'desc' => 'Akses menu "Daftar Pengajuan" di sidebar untuk melihat riwayat pengajuan', 'is_parent' => true, 'parent' => null],
                    'requests.create' => ['label' => 'Akses Form Buat Pengajuan', 'desc' => 'Akses tombol buat pengajuan dana baru di halaman pengajuan', 'parent' => 'requests.view'],
                    'requests.approve' => ['label' => 'Akses Menu Approval Center', 'desc' => 'Akses menu persetujuan/penolakan pengajuan dana (khusus PM & Admin)', 'parent' => 'requests.view'],
                    'requests.delete' => ['label' => 'Hapus Pengajuan di Daftar Pengajuan', 'desc' => 'Dapat menghapus pengajuan dana dari menu sidebar Daftar Pengajuan', 'parent' => 'requests.view'],
                ]
            ],
            'sidebar_reports' => [
                'name' => 'Menu Laporan (Sidebar)',
                'icon' => 'mdi-chart-bar',
                'parent_key' => 'reports.view',
                'items' => [
                    'reports.view' => ['label' => 'Buka Menu Dashboard Laporan', 'desc' => 'Akses menu "Dashboard Laporan" di sidebar untuk melihat analisa performa proyek', 'is_parent' => true, 'parent' => null],
                    'reports.export' => ['label' => 'Akses Menu Export Laporan', 'desc' => 'Dapat mendownload file CSV & PDF laporan RAB/RAP/Realisasi', 'parent' => 'reports.view'],
                ]
            ],
            'sidebar_admin' => [
                'name' => 'Menu Pengaturan Sistem (Sidebar)',
                'icon' => 'mdi-cog',
                'parent_key' => null,
                'items' => [
                    'admin.roles' => ['label' => 'Akses Menu Manajemen Role', 'desc' => 'Dapat mengelola role, tipe, dan hak akses seluruh pengguna', 'parent' => null],
                    'admin.users' => ['label' => 'Akses Menu Manajemen User', 'desc' => 'Dapat menambah, mengedit, dan me-reset password akun pengguna', 'parent' => null],
                ]
            ]
        ]
    ]
];

// Flat list of permissions that depend on having project access (view_mode != none)
$projectDependentPerms = [
    'projects.create', 'projects.edit', 'projects.delete', 'projects.lock_request',
    'rab.view', 'rab.edit',
    'rap.view', 'rap.edit',
    'master_data.view', 'master_data.edit',
    'documentation.view', 'documentation.upload',
    'reports.view', 'reports.export',
    'requests.view', 'requests.create', 'requests.approve', 'requests.delete',
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
                $permMap = [];
                $viewMode = 'none';
                
                try {
                    $rows = dbGetAll("SELECT permission_key, is_allowed, view_mode FROM role_permissions WHERE role_id = ?", [$roleId]);
                } catch (Throwable $e) {
                    try {
                        $rows = dbGetAll("SELECT permission_key, is_allowed FROM role_permissions WHERE role_id = ?", [$roleId]);
                    } catch (Throwable $e2) {
                        $rows = [];
                    }
                }
                
                foreach ($rows as $row) {
                    $permMap[$row['permission_key']] = intval($row['is_allowed']);
                    if ($row['permission_key'] === 'projects.view' && !empty($row['is_allowed'])) {
                        $viewMode = $row['view_mode'] ?? 'assigned';
                    }
                }
                echo json_encode(['success' => true, 'data' => $permMap, 'view_mode' => $viewMode]);
                break;

            case 'save_permissions':
                $roleId = intval($_POST['role_id'] ?? 0);
                $permissions = json_decode($_POST['permissions'] ?? '{}', true);
                $viewMode = trim($_POST['view_mode'] ?? 'none');
                
                if ($roleId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Role tidak valid']);
                    break;
                }
                
                // Validate view_mode
                if (!in_array($viewMode, ['none', 'assigned', 'all'])) {
                    $viewMode = 'none';
                }
                
                // If view_mode is 'none', force projects.view OFF and all dependent perms OFF
                $projectDependentPerms = [
                    'rab.view', 'rab.edit', 'rap.view', 'rap.edit',
                    'requests.view', 'requests.create', 'requests.approve', 'requests.delete',
                    'reports.view', 'reports.export',
                    'master_data.view', 'master_data.edit',
                    'documentation.view', 'documentation.upload',
                    'projects.create', 'projects.edit', 'projects.delete', 'projects.lock_request',
                ];
                
                if ($viewMode === 'none') {
                    $permissions['projects.view'] = 0;
                    foreach ($projectDependentPerms as $depKey) {
                        $permissions[$depKey] = 0;
                    }
                } else {
                    $permissions['projects.view'] = 1;
                    
                    // Strict sub-permission dependencies:
                    // 1. RAB: rab.edit requires rab.view
                    if (empty($permissions['rab.view'])) {
                        $permissions['rab.edit'] = 0;
                    }
                    
                    // 2. RAP: rap.edit requires rap.view
                    if (empty($permissions['rap.view'])) {
                        $permissions['rap.edit'] = 0;
                    }
                    
                    // 3. Master Data: master_data.edit requires master_data.view
                    if (empty($permissions['master_data.view'])) {
                        $permissions['master_data.edit'] = 0;
                    }
                    
                    // 4. Requests: requests.create, requests.approve, and requests.delete require requests.view
                    if (empty($permissions['requests.view'])) {
                        $permissions['requests.create'] = 0;
                        $permissions['requests.approve'] = 0;
                        $permissions['requests.delete'] = 0;
                    }
                    
                    // 5. Reports: reports.export requires reports.view
                    if (empty($permissions['reports.view'])) {
                        $permissions['reports.export'] = 0;
                    }
                    
                    // 6. Documentation: documentation.upload requires documentation.view
                    if (empty($permissions['documentation.view'])) {
                        $permissions['documentation.upload'] = 0;
                    }
                }
                
                // Try to ensure view_mode column exists in table
                try {
                    dbExecute("ALTER TABLE role_permissions ADD COLUMN view_mode VARCHAR(20) DEFAULT NULL");
                } catch (Throwable $e) {
                    // Column already exists or table locked, ignore
                }
                
                // Delete existing permissions from role_permissions table
                dbExecute("DELETE FROM role_permissions WHERE role_id = ?", [$roleId]);
                
                // Insert new permissions to role_permissions table
                foreach ($permissions as $key => $allowed) {
                    $vm = ($key === 'projects.view') ? $viewMode : null;
                    try {
                        dbInsert(
                            "INSERT INTO role_permissions (role_id, permission_key, is_allowed, view_mode) VALUES (?, ?, ?, ?)",
                            [$roleId, $key, $allowed ? 1 : 0, $vm]
                        );
                    } catch (Throwable $e) {
                        dbInsert(
                            "INSERT INTO role_permissions (role_id, permission_key, is_allowed) VALUES (?, ?, ?)",
                            [$roleId, $key, $allowed ? 1 : 0]
                        );
                    }
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
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>/index.php">PCC</a></li>
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
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-light py-2">
                <div>
                    <h5 class="modal-title mb-0">Setting Hak Akses</h5>
                    <small class="text-muted">Role: <strong id="permRoleName" class="text-primary"></strong></small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <input type="hidden" id="permRoleId">
                <div id="permissionsContent">
                    
                    <!-- ======================================================== -->
                    <!-- SECTION 1: MANAJEMEN PROYEK (GLOBAL)                    -->
                    <!-- ======================================================== -->
                    <div class="card mb-3 border-primary shadow-sm">
                        <div class="card-header bg-primary text-white py-2 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 text-white">
                                <i class="mdi mdi-briefcase-outline me-1"></i> 1. Manajemen Proyek (Global)
                            </h6>
                            <span class="badge bg-white text-primary">Akses Proyek</span>
                        </div>
                        <div class="card-body py-3">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Level Akses Proyek <span class="text-danger">*</span></label>
                                <select class="form-select border-primary" id="projectViewMode" onchange="onViewModeChange()">
                                    <option value="none">❌ Tidak Ada Akses Proyek (Semua fitur & tab proyek terkunci)</option>
                                    <option value="assigned">🔒 Hanya Proyek Yang Ditugaskan (User harus dikerahkan ke proyek)</option>
                                    <option value="all">🌐 Semua Proyek (Bisa akses seluruh proyek tanpa penugasan)</option>
                                </select>
                                <div class="mt-2">
                                    <small class="text-muted" id="viewModeDesc">Pilih cakupan proyek yang boleh diakses oleh role ini.</small>
                                </div>
                                <div class="alert alert-warning mt-2 mb-0 py-2 d-none" id="viewModeWarning">
                                    <i class="mdi mdi-alert"></i>
                                    <small><strong>Perhatian:</strong> Memilih "Tidak Ada Akses Proyek" akan otomatis menonaktifkan seluruh fitur proyek & tab internal di bawah karena fitur tersebut berada di dalam proyek.</small>
                                </div>
                            </div>
                            
                            <hr class="my-2 text-muted opacity-25">
                            <label class="form-label fw-bold small text-muted text-uppercase mb-2">Operasi Umum Proyek</label>
                            <div class="row g-2" id="projectGlobalOps">
                                <?php foreach ($permissionDefinitions['project_global']['items'] as $key => $item): ?>
                                <div class="col-md-6">
                                    <div class="p-2 border rounded bg-light h-100 perm-box">
                                        <div class="form-check form-switch mb-1">
                                            <input class="form-check-input perm-checkbox project-dependent" type="checkbox" 
                                                   id="perm_<?= str_replace('.', '_', $key) ?>" 
                                                   data-key="<?= $key ?>"
                                                   onchange="onPermCheckboxChange(this)">
                                            <label class="form-check-label fw-semibold" for="perm_<?= str_replace('.', '_', $key) ?>">
                                                <?= $item['label'] ?>
                                            </label>
                                        </div>
                                        <small class="text-muted d-block ps-4" style="font-size: 0.78rem;"><?= $item['desc'] ?></small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ======================================================== -->
                    <!-- SECTION 2: TAB DI DALAM PROYEK (PROJECT DASHBOARD TABS)  -->
                    <!-- ======================================================== -->
                    <div id="projectScopeContainer">
                        <div class="card mb-3 border-info shadow-sm">
                            <div class="card-header bg-info text-white py-2 d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-0 text-white">
                                        <i class="mdi mdi-tab me-1"></i> 2. Akses Tab di Dalam Proyek
                                    </h6>
                                    <small class="text-white opacity-75" style="font-size: 0.78rem;">Mengatur tab yang dapat dibuka pada halaman detail proyek (Dashboard Proyek)</small>
                                </div>
                                <span class="badge bg-white text-info">Tab Detail Proyek</span>
                            </div>
                            <div class="card-body p-3">
                                
                                <div class="row g-3">
                                    <?php foreach ($permissionDefinitions['project_tabs']['groups'] as $groupKey => $group): ?>
                                    <div class="col-12">
                                        <div class="border rounded p-2 bg-light">
                                            <div class="d-flex align-items-center mb-2 pb-1 border-bottom">
                                                <i class="mdi <?= $group['icon'] ?> text-info fs-5 me-2"></i>
                                                <strong class="text-dark small text-uppercase"><?= $group['name'] ?></strong>
                                            </div>
                                            <div class="row g-2">
                                                <?php foreach ($group['items'] as $key => $item): ?>
                                                <div class="col-md-6">
                                                    <div class="p-2 border rounded bg-white h-100 perm-box <?= !empty($item['parent']) ? 'ps-3 border-start border-start-3 border-start-primary' : '' ?>">
                                                        <div class="form-check form-switch mb-1">
                                                            <input class="form-check-input perm-checkbox project-dependent <?= !empty($item['is_parent']) ? 'parent-perm' : '' ?> <?= !empty($item['parent']) ? 'child-perm' : '' ?>" 
                                                                   type="checkbox" 
                                                                   id="perm_<?= str_replace('.', '_', $key) ?>" 
                                                                   data-key="<?= $key ?>"
                                                                   <?= !empty($item['parent']) ? 'data-parent="' . $item['parent'] . '"' : '' ?>
                                                                   onchange="onPermCheckboxChange(this)">
                                                            <label class="form-check-label fw-semibold" for="perm_<?= str_replace('.', '_', $key) ?>">
                                                                <?= $item['label'] ?>
                                                                <?php if (!empty($item['is_parent'])): ?>
                                                                    <span class="badge bg-soft-primary text-primary ms-1" style="font-size: 0.65rem;">Akses Utama</span>
                                                                <?php elseif (!empty($item['parent'])): ?>
                                                                    <span class="badge bg-soft-warning text-warning ms-1" style="font-size: 0.65rem;">Wajib Lihat Aktif</span>
                                                                <?php endif; ?>
                                                            </label>
                                                        </div>
                                                        <small class="text-muted d-block ps-4" style="font-size: 0.78rem;"><?= $item['desc'] ?></small>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                
                            </div>
                        </div>
                    </div><!-- end projectScopeContainer -->
                    
                    <!-- ======================================================== -->
                    <!-- SECTION 3: MENU SIDEBAR & FITUR GLOBAL                   -->
                    <!-- ======================================================== -->
                    <div class="card mb-3 border-secondary shadow-sm">
                        <div class="card-header bg-secondary text-white py-2 d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0 text-white">
                                    <i class="mdi mdi-menu me-1"></i> 3. Menu Sidebar & Fitur Global
                                </h6>
                                <small class="text-white opacity-75" style="font-size: 0.78rem;">Mengatur menu navigasi utama pada sidebar sistem (independen dari proyek)</small>
                            </div>
                            <span class="badge bg-white text-secondary">Menu Sidebar</span>
                        </div>
                        <div class="card-body p-3">
                            <div class="row g-3">
                                <?php foreach ($permissionDefinitions['sidebar_menus']['groups'] as $groupKey => $group): ?>
                                <div class="col-12">
                                    <div class="border rounded p-2 bg-light">
                                        <div class="d-flex align-items-center mb-2 pb-1 border-bottom">
                                            <i class="mdi <?= $group['icon'] ?> text-secondary fs-5 me-2"></i>
                                            <strong class="text-dark small text-uppercase"><?= $group['name'] ?></strong>
                                        </div>
                                        <div class="row g-2">
                                            <?php foreach ($group['items'] as $key => $item): ?>
                                            <div class="col-md-6">
                                                <div class="p-2 border rounded bg-white h-100 perm-box <?= !empty($item['parent']) ? 'ps-3 border-start border-start-3 border-start-secondary' : '' ?>">
                                                    <div class="form-check form-switch mb-1">
                                                        <input class="form-check-input perm-checkbox <?= !empty($item['is_parent']) ? 'parent-perm' : '' ?> <?= !empty($item['parent']) ? 'child-perm' : '' ?>" 
                                                               type="checkbox" 
                                                               id="perm_sidebar_<?= str_replace('.', '_', $key) ?>" 
                                                               data-key="<?= $key ?>"
                                                               <?= !empty($item['parent']) ? 'data-parent="' . $item['parent'] . '"' : '' ?>
                                                               onchange="onPermCheckboxChange(this)">
                                                        <label class="form-check-label fw-semibold" for="perm_sidebar_<?= str_replace('.', '_', $key) ?>">
                                                            <?= $item['label'] ?>
                                                            <?php if (!empty($item['is_parent'])): ?>
                                                                <span class="badge bg-soft-primary text-primary ms-1" style="font-size: 0.65rem;">Akses Utama</span>
                                                            <?php elseif (!empty($item['parent'])): ?>
                                                                <span class="badge bg-soft-warning text-warning ms-1" style="font-size: 0.65rem;">Wajib Lihat Aktif</span>
                                                            <?php endif; ?>
                                                        </label>
                                                    </div>
                                                    <small class="text-muted d-block ps-4" style="font-size: 0.78rem;"><?= $item['desc'] ?></small>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
            <div class="modal-footer bg-light">
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
                            <i class="mdi mdi-swap-horizontal"></i>
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
                                    <i class="mdi mdi-shield-key"></i> Hak Akses
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
    
    // Reset all checkboxes and dropdown
    $('.perm-checkbox').prop('checked', false).prop('disabled', false);
    $('.perm-box').removeClass('opacity-50');
    $('#projectViewMode').val('none');
    
    // Load current permissions
    $.post('', { action: 'get_permissions', role_id: roleId }, function(res) {
        if (res.success) {
            // Set view mode dropdown
            const viewMode = res.view_mode || 'none';
            $('#projectViewMode').val(viewMode);
            
            // Set permission checkboxes across all matching keys
            Object.keys(res.data).forEach(function(key) {
                if (res.data[key]) {
                    $(`.perm-checkbox[data-key="${key}"]`).prop('checked', true);
                }
            });
            
            // Apply project level and sub-permission dependencies
            onViewModeChange();
        }
        new bootstrap.Modal('#permissionsModal').show();
    }, 'json');
}

function onPermCheckboxChange(el) {
    const key = $(el).data('key');
    const isChecked = $(el).is(':checked');
    
    // Sync ALL duplicate checkboxes with the same data-key (across sections)
    $(`.perm-checkbox[data-key="${key}"]`).prop('checked', isChecked);
    
    // Update sub-permission dependencies
    updateSubPermissionDependencies();
}

/**
 * Sync all duplicate checkboxes so sidebar and project-tab versions stay consistent.
 * Called after bulk operations like onViewModeChange that bypass onPermCheckboxChange.
 */
function syncAllDuplicateKeys() {
    // Build a map of unique keys to their resolved checked state
    const keyStates = {};
    
    // For each unique key, if ANY enabled checkbox is checked, mark as checked
    // If ALL checkboxes for that key are unchecked (or disabled+unchecked), mark as unchecked
    $('.perm-checkbox').each(function() {
        const key = $(this).data('key');
        if (keyStates[key] === undefined) {
            keyStates[key] = false;
        }
        // A checked, non-disabled checkbox wins
        if ($(this).is(':checked') && !$(this).is(':disabled')) {
            keyStates[key] = true;
        }
    });
    
    // Apply the resolved state to ALL checkboxes with each key
    Object.keys(keyStates).forEach(function(key) {
        $(`.perm-checkbox[data-key="${key}"]`).prop('checked', keyStates[key]);
    });
}

function updateSubPermissionDependencies() {
    const viewMode = $('#projectViewMode').val();
    const isNone = (viewMode === 'none');
    
    if (isNone) {
        return; // Already handled by onViewModeChange
    }
    
    // Helper to enforce parent-child dependency
    function syncChild(parentKey) {
        const parentChecked = $(`.perm-checkbox[data-key="${parentKey}"]`).first().is(':checked');
        $(`.perm-checkbox[data-parent="${parentKey}"]`).each(function() {
            if (!parentChecked) {
                $(this).prop('checked', false).prop('disabled', true);
                $(this).closest('.perm-box').addClass('opacity-50');
                // Also sync the duplicate sidebar checkbox for this key
                const childKey = $(this).data('key');
                $(`.perm-checkbox[data-key="${childKey}"]`).prop('checked', false);
            } else {
                $(this).prop('disabled', false);
                $(this).closest('.perm-box').removeClass('opacity-50');
            }
        });
    }
    
    // 1. RAB: rab.edit requires rab.view
    syncChild('rab.view');
    
    // 2. RAP: rap.edit requires rap.view
    syncChild('rap.view');
    
    // 3. Master Data: master_data.edit requires master_data.view
    syncChild('master_data.view');
    
    // 4. Requests: requests.create, requests.approve, and requests.delete require requests.view
    syncChild('requests.view');
    
    // 5. Reports: reports.export requires reports.view
    syncChild('reports.view');
    
    // 6. Documentation: documentation.upload requires documentation.view
    syncChild('documentation.view');
}

function onViewModeChange() {
    const mode = $('#projectViewMode').val();
    const isNone = (mode === 'none');
    
    // Show/hide warning and descriptions
    if (isNone) {
        $('#viewModeWarning').removeClass('d-none');
        $('#viewModeDesc').text('Role ini tidak dapat mengakses proyek manapun.');
        
        // Disable project global ops and projectScopeContainer
        $('.project-dependent').prop('checked', false).prop('disabled', true);
        $('.project-dependent').closest('.perm-box').addClass('opacity-50');
        $('#projectScopeContainer').css('opacity', '0.5').css('pointer-events', 'none');
        $('#projectGlobalOps').css('opacity', '0.5').css('pointer-events', 'none');
        
        // CRITICAL: Sync sidebar duplicate checkboxes for project-dependent keys
        // When project-dependent checkboxes are unchecked, their sidebar counterparts must also be unchecked
        syncAllDuplicateKeys();
    } else {
        $('#viewModeWarning').addClass('d-none');
        if (mode === 'assigned') {
            $('#viewModeDesc').text('Role ini hanya dapat melihat proyek yang sudah ditugaskan melalui penugasan tim di halaman proyek.');
        } else {
            $('#viewModeDesc').text('Role ini dapat melihat semua proyek tanpa perlu ditugaskan terlebih dahulu.');
        }
        
        $('#projectScopeContainer').css('opacity', '1').css('pointer-events', 'auto');
        $('#projectGlobalOps').css('opacity', '1').css('pointer-events', 'auto');
        $('.project-dependent').prop('disabled', false);
        $('.project-dependent').closest('.perm-box').removeClass('opacity-50');
        
        // Re-evaluate sub-permission dependencies
        updateSubPermissionDependencies();
    }
}

function savePermissions() {
    const roleId = $('#permRoleId').val();
    const viewMode = $('#projectViewMode').val();
    const permissions = {};
    
    // Collect unique permission keys from ALL checkboxes
    // For duplicate keys (same key in project-tab and sidebar sections),
    // a key is ON if ANY of its checkboxes is checked
    const seenKeys = {};
    $('.perm-checkbox').each(function() {
        const key = $(this).data('key');
        if (!seenKeys[key]) {
            seenKeys[key] = true;
            // Check if ANY checkbox with this key is checked
            const anyChecked = $(`.perm-checkbox[data-key="${key}"]`).filter(':checked').length > 0;
            permissions[key] = anyChecked ? 1 : 0;
        }
    });
    
    // Set projects.view based on viewMode
    permissions['projects.view'] = (viewMode !== 'none') ? 1 : 0;
    
    // Client-side enforcement before sending
    if (viewMode === 'none') {
        permissions['projects.view'] = 0;
        Object.keys(permissions).forEach(k => {
            if (k !== 'admin.roles' && k !== 'admin.users') {
                permissions[k] = 0;
            }
        });
    } else {
        if (!permissions['rab.view']) permissions['rab.edit'] = 0;
        if (!permissions['rap.view']) permissions['rap.edit'] = 0;
        if (!permissions['master_data.view']) permissions['master_data.edit'] = 0;
        if (!permissions['requests.view']) {
            permissions['requests.create'] = 0;
            permissions['requests.approve'] = 0;
            permissions['requests.delete'] = 0;
        }
        if (!permissions['reports.view']) permissions['reports.export'] = 0;
        if (!permissions['documentation.view']) permissions['documentation.upload'] = 0;
    }
    
    $.post('', { 
        action: 'save_permissions', 
        role_id: roleId, 
        view_mode: viewMode, 
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
