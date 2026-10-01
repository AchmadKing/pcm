<?php
/**
 * Projects List
 * PCC - Project Cost Control System
 */

// IMPORTANT: Process all logic that may redirect BEFORE including header.php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();

// Check project access level using view_mode
$viewMode = getProjectViewMode();

if ($viewMode === 'none') {
    setFlash('error', 'Anda tidak memiliki akses ke halaman proyek.');
    header('Location: ' . getBaseUrl() . '/index.php');
    exit;
}

// Handle delete (admin only) - MUST BE BEFORE header.php include
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id']) && hasPermission('projects.delete')) {
    $id = $_GET['id'];
    $project = dbGetRow("SELECT status FROM projects WHERE id = ?", [$id]);
    
    if ($project && $project['status'] === 'draft') {
        try {
            // Delete in correct order to avoid foreign key constraint errors
            // Order matters due to foreign key relationships:
            // - rap_ahsp_details -> rap_items -> rab_subcategories -> rab_categories
            // - rab_subcategories -> project_ahsp (via ahsp_id)
            // - project_ahsp_details -> project_ahsp
            
            // 1. Delete rap_ahsp_details (references rap_items)
            dbExecute("
                DELETE rad FROM rap_ahsp_details rad
                INNER JOIN rap_items rap ON rad.rap_item_id = rap.id
                INNER JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
                INNER JOIN rab_categories rc ON rs.category_id = rc.id
                WHERE rc.project_id = ?
            ", [$id]);
            
            // 2. Delete rap_items (references rab_subcategories)
            dbExecute("
                DELETE rap FROM rap_items rap
                INNER JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
                INNER JOIN rab_categories rc ON rs.category_id = rc.id
                WHERE rc.project_id = ?
            ", [$id]);
            
            // 3. Delete rab_subcategories (references project_ahsp via ahsp_id and rab_categories)
            dbExecute("
                DELETE rs FROM rab_subcategories rs
                INNER JOIN rab_categories rc ON rs.category_id = rc.id
                WHERE rc.project_id = ?
            ", [$id]);
            
            // 4. Delete rab_categories
            dbExecute("DELETE FROM rab_categories WHERE project_id = ?", [$id]);
            
            // 5. Delete project_ahsp_details (references project_ahsp)
            dbExecute("
                DELETE pad FROM project_ahsp_details pad
                INNER JOIN project_ahsp pa ON pad.ahsp_id = pa.id
                WHERE pa.project_id = ?
            ", [$id]);
            
            // 6. Delete project_ahsp (now safe - no more references from rab_subcategories)
            dbExecute("DELETE FROM project_ahsp WHERE project_id = ?", [$id]);
            
            // 7. Delete project_items
            dbExecute("DELETE FROM project_items WHERE project_id = ?", [$id]);
            
            // 8. Delete request_items and requests for this project
            dbExecute("
                DELETE ri FROM request_items ri
                INNER JOIN requests r ON ri.request_id = r.id
                WHERE r.project_id = ?
            ", [$id]);
            dbExecute("DELETE FROM requests WHERE project_id = ?", [$id]);
            
            // 9. Finally delete the project
            dbExecute("DELETE FROM projects WHERE id = ?", [$id]);
            
            setFlash('success', 'Proyek berhasil dihapus!');
        } catch (Exception $e) {
            setFlash('error', 'Gagal menghapus proyek: ' . $e->getMessage());
        }
    } else {
        setFlash('error', 'Hanya proyek dengan status Draft yang dapat dihapus!');
    }
    header('Location: index.php');
    exit;
}

if ($viewMode === 'all') {
    // Users with 'all' access: see all projects
    $projects = dbGetAll("
        SELECT p.*, u.full_name as created_by_name,
            (SELECT COUNT(*) FROM rab_categories WHERE project_id = p.id) as category_count,
            (SELECT COALESCE(SUM(
                rs.volume * COALESCE(ahsp_totals.unit_price, 0)
            ), 0)
            FROM rab_subcategories rs
            JOIN rab_categories rc ON rs.category_id = rc.id
            LEFT JOIN (
                SELECT pad.ahsp_id, SUM(pad.coefficient * COALESCE(pad.unit_price, pi.price)) as unit_price
                FROM project_ahsp_details pad
                JOIN project_items pi ON pad.item_id = pi.id
                GROUP BY pad.ahsp_id
            ) ahsp_totals ON ahsp_totals.ahsp_id = rs.ahsp_id
            WHERE rc.project_id = p.id) as base_rab
        FROM projects p 
        LEFT JOIN users u ON p.created_by = u.id
        ORDER BY p.created_at DESC
    ");
} else {
    // Users with 'assigned' access: only see assigned projects
    $projects = dbGetAll("
        SELECT p.*, u.full_name as created_by_name,
            (SELECT COALESCE(SUM(
                rs.volume * COALESCE(ahsp_totals.unit_price, 0)
            ), 0)
            FROM rab_subcategories rs
            JOIN rab_categories rc ON rs.category_id = rc.id
            LEFT JOIN (
                SELECT pad.ahsp_id, SUM(pad.coefficient * COALESCE(pad.unit_price, pi.price)) as unit_price
                FROM project_ahsp_details pad
                JOIN project_items pi ON pad.item_id = pi.id
                GROUP BY pad.ahsp_id
            ) ahsp_totals ON ahsp_totals.ahsp_id = rs.ahsp_id
            WHERE rc.project_id = p.id) as base_rab
        FROM projects p 
        LEFT JOIN users u ON p.created_by = u.id
        INNER JOIN project_assignments pa ON pa.project_id = p.id
        WHERE pa.user_id = ? AND pa.is_active = 1
        ORDER BY p.name ASC
    ", [getCurrentUserId()]);
}

// Calculate total_rab with overhead, PPN, and rounding for each project
foreach ($projects as &$proj) {
    $baseRab = $proj['base_rab'];
    $overheadPct = getProjectOverheadProfitPct($proj, 'rab');
    $ppnPct = $proj['ppn_percentage'] ?? 11;
    $rabWithOverhead = $baseRab * (1 + ($overheadPct / 100));
    $rabPpn = $rabWithOverhead * ($ppnPct / 100);
    $proj['total_rab'] = ceil(($rabWithOverhead + $rabPpn) / 10) * 10;
}
unset($proj);

// NOW include header (after all possible redirects)
$pageTitle = 'Daftar Proyek';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Title -->
<div class="row">
    <div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
            <h4 class="mb-sm-0">Daftar Proyek</h4>
            <div class="page-title-right">
                <ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>">PCC</a></li>
                    <li class="breadcrumb-item active">Proyek</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="header-title mb-0">Semua Proyek</h4>
                    <?php if (hasPermission('projects.create')): ?>
                    <a href="create.php" class="btn btn-primary">
                        <i class="mdi mdi-plus"></i> Buat Proyek Baru
                    </a>
                    <?php endif; ?>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover datatable">
                        <thead>
                            <tr>
                                <th width="50">No</th>
                                <th width="120">Kode Proyek</th>
                                <th>Nama Proyek</th>
                                <th>Wilayah</th>
                                <th class="text-end">Total RAB</th>
                                <th width="100">Status</th>
                                <?php if (hasPermission('projects.edit')): ?>
                                <th>Dibuat Oleh</th>
                                <?php endif; ?>
                                <th width="150">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projects as $i => $project): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><code class="fs-5"><?= sanitize($project['project_code'] ?: '-') ?></code></td>
                                <td>
                                    <strong><?= sanitize($project['name']) ?></strong>
                                    <?php if ($project['activity_name']): ?>
                                    <br><small class="text-muted"><?= sanitize($project['activity_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= sanitize($project['region_name']) ?></td>
                                <td class="text-end"><?= formatRupiah($project['total_rab']) ?></td>
                                <td>
                                    <?= getStatusBadge($project['status']) ?>
                                    <?php if (!empty($project['request_locked'])): ?>
                                    <br><span class="badge bg-danger mt-1"><i class="mdi mdi-lock"></i> Lock</span>
                                    <?php endif; ?>
                                </td>
                                <?php if (hasPermission('projects.edit')): ?>
                                <td><?= sanitize($project['created_by_name']) ?></td>
                                <?php endif; ?>
                                <td>
                                    <a href="view.php?id=<?= $project['id'] ?>" class="btn btn-sm btn-info btn-action" title="Lihat">
                                        <i class="mdi mdi-eye"></i>
                                    </a>
                                    <?php if (hasPermission('projects.edit')): ?>
                                    <a href="rab.php?id=<?= $project['id'] ?>" class="btn btn-sm btn-primary btn-action" title="Edit RAB">
                                        <i class="mdi mdi-file-document-edit"></i>
                                    </a>
                                    <?php if ($project['status'] === 'draft' && hasPermission('projects.delete')): ?>
                                    <a href="?action=delete&id=<?= $project['id'] ?>" class="btn btn-sm btn-danger btn-action btn-delete" title="Hapus">
                                        <i class="mdi mdi-delete"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php elseif (hasPermission('requests.create')): ?>
                                    <?php if (empty($project['request_locked'])): ?>
                                    <a href="<?= $baseUrl ?>/pages/requests/create.php?project_id=<?= $project['id'] ?>" 
                                       class="btn btn-sm btn-success btn-action" title="Buat Pengajuan">
                                        <i class="mdi mdi-plus"></i>
                                    </a>
                                    <?php else: ?>
                                    <span class="btn btn-sm btn-secondary btn-action disabled" title="Pengajuan Terkunci">
                                        <i class="mdi mdi-lock"></i>
                                    </span>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (empty($projects)): ?>
                <div class="text-center py-4">
                    <p class="text-muted">Belum ada proyek.</p>
                    <?php if (hasPermission('projects.create')): ?>
                    <a href="create.php" class="btn btn-primary">Buat Proyek Baru</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
