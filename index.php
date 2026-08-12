<?php
/**
 * Main Entry Point - Dashboard
 * PCM - Project Cost Management System
 */

$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';

// Get statistics for dashboard
$stats = [
    'total_projects' => 0,
    'on_progress' => 0,
    'pending_requests' => 0,
    'total_rab' => 0
];

try {
    // Count projects based on view_mode
    $dashboardViewMode = getProjectViewMode();
    
    if ($dashboardViewMode === 'all') {
        // Admin / PM / Super Admin: count all projects
        $projectCount = dbGetRow("SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'on_progress' THEN 1 ELSE 0 END) as on_progress
        FROM projects");
    } elseif ($dashboardViewMode === 'assigned') {
        // Assigned users: count only assigned projects
        $projectCount = dbGetRow("SELECT 
            COUNT(DISTINCT p.id) as total,
            SUM(CASE WHEN p.status = 'on_progress' THEN 1 ELSE 0 END) as on_progress
        FROM projects p
        INNER JOIN project_assignments pa ON pa.project_id = p.id
        WHERE pa.user_id = ? AND pa.is_active = 1", [getCurrentUserId()]);
    } else {
        // No access
        $projectCount = ['total' => 0, 'on_progress' => 0];
    }

    $stats['total_projects'] = (int)($projectCount['total'] ?? 0);
    $stats['on_progress'] = (int)($projectCount['on_progress'] ?? 0);

    if (hasPermission('requests.approve')) {
        $pendingCount = dbGetRow("SELECT COUNT(*) as cnt FROM requests WHERE status = 'pending'");
        $stats['pending_requests'] = $pendingCount['cnt'] ?? 0;
    } else {
        $pendingCount = dbGetRow("SELECT COUNT(*) as cnt FROM requests WHERE status = 'pending' AND created_by = ?", [getCurrentUserId()]);
        $stats['pending_requests'] = $pendingCount['cnt'] ?? 0;
    }

    if ($dashboardViewMode === 'all') {
        // All access: see all projects
        $recentProjects = dbGetAll("SELECT * FROM projects ORDER BY updated_at DESC LIMIT 5");
    } elseif ($dashboardViewMode === 'assigned') {
        // Assigned: see only assigned projects
        $recentProjects = dbGetAll("
            SELECT p.* FROM projects p
            INNER JOIN project_assignments pa ON pa.project_id = p.id
            WHERE pa.user_id = ? AND pa.is_active = 1
            ORDER BY p.updated_at DESC LIMIT 5
        ", [getCurrentUserId()]);
    } else {
        $recentProjects = [];
    }

    if (hasPermission('requests.approve')) {
        $recentRequests = dbGetAll("SELECT req.*, p.name as project_name, u.full_name as created_by_name,
                upm.full_name as pm_approved_by_name, upm.role as pm_approved_by_role,
                ua.full_name as approved_by_name, ua.role as approved_by_role
            FROM requests req
            LEFT JOIN projects p ON req.project_id = p.id
            LEFT JOIN users u ON req.created_by = u.id
            LEFT JOIN users upm ON req.pm_approved_by = upm.id
            LEFT JOIN users ua ON req.approved_by = ua.id
            ORDER BY req.created_at DESC LIMIT 5");
    } else {
        $recentRequests = dbGetAll("SELECT req.*, p.name as project_name,
                upm.full_name as pm_approved_by_name, upm.role as pm_approved_by_role,
                ua.full_name as approved_by_name, ua.role as approved_by_role
            FROM requests req
            LEFT JOIN projects p ON req.project_id = p.id
            LEFT JOIN users upm ON req.pm_approved_by = upm.id
            LEFT JOIN users ua ON req.approved_by = ua.id
            WHERE req.created_by = ?
            ORDER BY req.created_at DESC LIMIT 5", [getCurrentUserId()]);
    }
} catch (Exception $e) {
    // Tables may not exist yet - migration not run
    $recentProjects = [];
    $recentRequests = [];
}
?>

<!-- Page Title -->
<div class="row">
    <div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
            <h4 class="mb-sm-0">Dashboard</h4>
            <div class="page-title-right">
                <ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="javascript: void(0);">PCM</a></li>
                    <li class="breadcrumb-item active">Dashboard</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="row">
    <div class="col-sm-6 col-xl-3">
        <div class="card">
            <div class="card-heading p-4">
                <div class="d-flex">
                    <div class="flex-grow-1 mt-3">
                        <h5 class="font-size-17">Total Proyek</h5>
                    </div>
                    <div class="mini-stat-icon">
                        <i class="mdi mdi-briefcase bg-primary text-white"></i>
                    </div>
                </div>
                <h3><?= $stats['total_projects'] ?></h3>
                <p class="text-muted mt-2 mb-0">Semua proyek</p>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card">
            <div class="card-heading p-4">
                <div class="d-flex">
                    <div class="flex-grow-1 mt-3">
                        <h5 class="font-size-17">On Progress</h5>
                    </div>
                    <div class="mini-stat-icon">
                        <i class="mdi mdi-progress-clock bg-success text-white"></i>
                    </div>
                </div>
                <h3><?= $stats['on_progress'] ?></h3>
                <p class="text-muted mt-2 mb-0">Proyek berjalan</p>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card">
            <div class="card-heading p-4">
                <div class="d-flex">
                    <div class="flex-grow-1 mt-3">
                        <h5 class="font-size-17">Pending Request</h5>
                    </div>
                    <div class="mini-stat-icon">
                        <i class="mdi mdi-file-document-edit bg-warning text-white"></i>
                    </div>
                </div>
                <h3><?= $stats['pending_requests'] ?></h3>
                <p class="text-muted mt-2 mb-0">Menunggu approval</p>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card">
            <div class="card-heading p-4">
                <div class="d-flex">
                    <div class="flex-grow-1 mt-3">
                        <h5 class="font-size-17">Selamat Datang</h5>
                    </div>
                    <div class="mini-stat-icon">
                        <i class="mdi mdi-account-circle bg-info text-white"></i>
                    </div>
                </div>
                <h3 class="font-size-16"><?= sanitize(getCurrentUserName()) ?></h3>
                <p class="text-muted mt-2 mb-0"><?= getRoleDisplayName(getCurrentUserRole()) ?></p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Recent Projects -->
    <div class="col-xl-6">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="mt-0 header-title">Proyek Terbaru</h4>
                    <a href="<?= $baseUrl ?>/pages/projects/index.php" class="btn btn-sm btn-primary">Lihat Semua</a>
                </div>
                
                <?php if (empty($recentProjects)): ?>
                <p class="text-muted">Belum ada proyek.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Nama Proyek</th>
                                <th>Wilayah</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentProjects as $project): ?>
                            <tr>
                                <td>
                                    <a href="<?= $baseUrl ?>/pages/projects/view.php?id=<?= $project['id'] ?>">
                                        <?= sanitize($project['name']) ?>
                                    </a>
                                </td>
                                <td><?= sanitize($project['region_name'] ?? '-') ?></td>
                                <td><?= getStatusBadge($project['status']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent Requests -->
    <div class="col-xl-6">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="mt-0 header-title">Pengajuan Terbaru</h4>
                    <a href="<?= $baseUrl ?>/pages/requests/index.php" class="btn btn-sm btn-primary">Lihat Semua</a>
                </div>
                
                <?php if (empty($recentRequests)): ?>
                <p class="text-muted">Belum ada pengajuan.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>No. Request</th>
                                <th>Proyek</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentRequests as $request): ?>
                            <tr>
                                <td><?= sanitize($request['request_number']) ?></td>
                                <td><?= sanitize($request['project_name']) ?></td>
                                <td><?= getDetailedStatusBadge($request) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (hasPermission('projects.create')): ?>
<!-- Quick Actions for Admin -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <h4 class="mt-0 header-title mb-4">Aksi Cepat</h4>
                <div class="d-flex flex-wrap gap-2">
                    <a href="<?= $baseUrl ?>/pages/projects/create.php" class="btn btn-primary">
                        <i class="mdi mdi-plus"></i> Buat Proyek Baru
                    </a>
                    <a href="<?= $baseUrl ?>/pages/projects/index.php" class="btn btn-outline-primary">
                        <i class="mdi mdi-briefcase"></i> Daftar Proyek
                    </a>
                    <a href="<?= $baseUrl ?>/pages/requests/approval.php" class="btn btn-outline-warning">
                        <i class="mdi mdi-check-circle"></i> Approval Center
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
