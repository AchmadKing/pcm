<?php
/**
 * Main Entry Point - Dashboard
 * PCC - Project Cost Control System
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$hasAccess = hasAnyPermission();
$pageTitle = $hasAccess ? 'Dashboard' : 'Akses Dibatasi';
require_once __DIR__ . '/includes/header.php';

if (!$hasAccess):
?>
<div class="row justify-content-center my-5 py-4">
    <div class="col-md-8 col-lg-6 col-xl-5">
        <div class="card shadow border-0 mt-4">
            <div class="card-body p-4 p-sm-5 text-center">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-soft-danger text-danger mb-4" style="width: 80px; height: 80px;">
                    <i class="mdi mdi-shield-lock-outline" style="font-size: 40px;"></i>
                </div>
                <h4 class="fw-bold text-dark mb-3">Akses Dibatasi</h4>
                <p class="text-muted font-size-15 mb-4 leading-relaxed">
                    Anda tidak memiliki akses silahkan hubungi administrator atau pihak terkait.
                </p>
                <div class="d-flex justify-content-center gap-2">
                    <button type="button" class="btn btn-primary px-4 py-2 waves-effect waves-light" onclick="window.location.reload();">
                        <i class="mdi mdi-refresh me-1"></i> Reload
                    </button>
                    <a href="<?= $baseUrl ?>/pages/auth/logout.php" class="btn btn-danger px-4 py-2 waves-effect waves-light">
                        <i class="mdi mdi-power me-1"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
require_once __DIR__ . '/includes/footer.php';
exit;
endif;


// Get statistics for dashboard
$stats = [
    'total_projects' => 0,
    'total_projects_rab' => 0,
    'draft_projects' => 0,
    'draft_projects_rab' => 0,
    'on_progress_projects' => 0,
    'on_progress_projects_rab' => 0,
    'completed_projects' => 0,
    'completed_projects_rab' => 0,
    'pending_requests' => 0,
    'pending_requests_nominal' => 0
];

try {
    // Count and get projects based on view_mode
    $dashboardViewMode = getProjectViewMode();
    
    if ($dashboardViewMode === 'all') {
        // Admin / PM / Super Admin: all projects
        $projectsForStats = dbGetAll("
            SELECT id, name, status, overhead_percentage, profit_percentage, overhead_apply_ahsp, overhead_apply_rab, ppn_percentage
            FROM projects
        ");
    } elseif ($dashboardViewMode === 'assigned') {
        // Assigned users: only assigned projects
        $projectsForStats = dbGetAll("
            SELECT DISTINCT p.id, p.name, p.status, p.overhead_percentage, p.profit_percentage, p.overhead_apply_ahsp, p.overhead_apply_rab, p.ppn_percentage
            FROM projects p
            INNER JOIN project_assignments pa ON pa.project_id = p.id
            WHERE pa.user_id = ? AND pa.is_active = 1
        ", [getCurrentUserId()]);
    } else {
        $projectsForStats = [];
    }

    $stats['total_projects'] = count($projectsForStats);

    // Calculate real-time total RAB for each project
    if (!empty($projectsForStats)) {
        $projectIds = array_column($projectsForStats, 'id');
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));

        // Batch load AHSP component unit prices
        $ahspPrices = [];
        $ahspRows = dbGetAll("
            SELECT pa.id as ahsp_id, COALESCE(SUM(d.coefficient * COALESCE(d.unit_price, i.price)), 0) as total
            FROM project_ahsp pa
            LEFT JOIN project_ahsp_details d ON d.ahsp_id = pa.id
            LEFT JOIN project_items i ON d.item_id = i.id
            WHERE pa.project_id IN ($placeholders)
            GROUP BY pa.id
        ", $projectIds);
        foreach ($ahspRows as $r) {
            $ahspPrices[$r['ahsp_id']] = floatval($r['total']);
        }

        // Batch load subcategories
        $subcatRows = dbGetAll("
            SELECT rc.project_id, rs.volume, rs.unit_price, rs.ahsp_id
            FROM rab_subcategories rs
            JOIN rab_categories rc ON rs.category_id = rc.id
            WHERE rc.project_id IN ($placeholders)
        ", $projectIds);

        $projectMap = [];
        foreach ($projectsForStats as $p) {
            $projectMap[$p['id']] = $p;
        }

        $projectSubtotalRab = [];
        foreach ($subcatRows as $s) {
            $pid = $s['project_id'];
            if (!isset($projectMap[$pid])) continue;
            $p = $projectMap[$pid];
            $vol = floatval($s['volume']);
            $unitPrice = ($s['ahsp_id'] && isset($ahspPrices[$s['ahsp_id']])) 
                ? $ahspPrices[$s['ahsp_id']] 
                : floatval($s['unit_price']);
            $overheadPct = getProjectOverheadProfitPct($p, 'rab');
            $rabUnitPrice = $unitPrice * (1 + ($overheadPct / 100));
            $subRab = $vol * $rabUnitPrice;
            $projectSubtotalRab[$pid] = ($projectSubtotalRab[$pid] ?? 0) + $subRab;
        }

        foreach ($projectsForStats as $p) {
            $st = strtolower(trim($p['status'] ?? ''));
            $subtotalRab = $projectSubtotalRab[$p['id']] ?? 0;
            $ppnPct = floatval($p['ppn_percentage'] ?? 11);
            $ppnAmount = $subtotalRab * ($ppnPct / 100);
            $totalRabWithPpn = $subtotalRab + $ppnAmount;
            $totalRab = ceil($totalRabWithPpn / 10) * 10;

            if ($st === 'draft') {
                $stats['draft_projects']++;
                $stats['draft_projects_rab'] += $totalRab;
            } elseif ($st === 'on_progress') {
                $stats['on_progress_projects']++;
                $stats['on_progress_projects_rab'] += $totalRab;
            } elseif ($st === 'completed') {
                $stats['completed_projects']++;
                $stats['completed_projects_rab'] += $totalRab;
            }
            $stats['total_projects_rab'] += $totalRab;
        }
    }

    // Pending requests & total nominal (real-time)
    if (hasPermission('requests.approve')) {
        $pendingWhere = hasPermission('projects.edit') ? "req.status IN ('pending', 'pm_approved')" : "req.status = 'pending'";
        $pendingData = dbGetRow("
            SELECT 
                COUNT(*) as cnt,
                COALESCE(SUM(
                    CASE 
                        WHEN req.total_amount > 0 THEN req.total_amount
                        ELSE (
                            SELECT COALESCE(SUM(CASE WHEN req.request_type = 'non_rab' THEN reqi.quantity * reqi.unit_price ELSE reqi.total_price END), 0)
                            FROM request_items reqi 
                            WHERE reqi.request_id = req.id
                        )
                    END
                ), 0) as total_nominal
            FROM requests req
            WHERE $pendingWhere
        ");
    } else {
        $pendingData = dbGetRow("
            SELECT 
                COUNT(*) as cnt,
                COALESCE(SUM(
                    CASE 
                        WHEN req.total_amount > 0 THEN req.total_amount
                        ELSE (
                            SELECT COALESCE(SUM(CASE WHEN req.request_type = 'non_rab' THEN reqi.quantity * reqi.unit_price ELSE reqi.total_price END), 0)
                            FROM request_items reqi 
                            WHERE reqi.request_id = req.id
                        )
                    END
                ), 0) as total_nominal
            FROM requests req
            WHERE req.status IN ('pending', 'pm_approved') AND req.created_by = ?
        ", [getCurrentUserId()]);
    }
    $stats['pending_requests'] = (int)($pendingData['cnt'] ?? 0);
    $stats['pending_requests_nominal'] = floatval($pendingData['total_nominal'] ?? 0);

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
                    <li class="breadcrumb-item"><a href="javascript: void(0);">PCC</a></li>
                    <li class="breadcrumb-item active">Dashboard</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Stats Cards (Total Proyek, Draft, On Progress, Selesai) -->
<div class="row g-3 mb-4">
    <!-- 1. Total Proyek -->
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 mb-0">
            <div class="card-heading p-4 h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h5 class="font-size-17 mb-0">Total Proyek</h5>
                        <div class="mini-stat-icon">
                            <i class="mdi mdi-briefcase bg-primary text-white"></i>
                        </div>
                    </div>
                    <h3 class="mb-1"><?= number_format($stats['total_projects'], 0, ',', '.') ?></h3>
                    <p class="text-muted mb-0 font-size-13">Semua proyek</p>
                </div>
                <div class="mt-3">
                    <div class="text-dark fw-bold font-size-20 text-truncate" title="Rp. <?= number_format($stats['total_projects_rab'], 0, ',', '.') ?>">
                        Rp. <?= number_format($stats['total_projects_rab'], 0, ',', '.') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. Draft -->
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 mb-0">
            <div class="card-heading p-4 h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h5 class="font-size-17 mb-0">Draft</h5>
                        <div class="mini-stat-icon">
                            <i class="mdi mdi-file-document-edit-outline bg-secondary text-white"></i>
                        </div>
                    </div>
                    <h3 class="mb-1"><?= number_format($stats['draft_projects'], 0, ',', '.') ?></h3>
                    <p class="text-muted mb-0 font-size-13">Proyek draft</p>
                </div>
                <div class="mt-3">
                    <div class="text-dark fw-bold font-size-20 text-truncate" title="Rp. <?= number_format($stats['draft_projects_rab'], 0, ',', '.') ?>">
                        Rp. <?= number_format($stats['draft_projects_rab'], 0, ',', '.') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. On Progress -->
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 mb-0">
            <div class="card-heading p-4 h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h5 class="font-size-17 mb-0">On Progress</h5>
                        <div class="mini-stat-icon">
                            <i class="mdi mdi-progress-clock bg-info text-white"></i>
                        </div>
                    </div>
                    <h3 class="mb-1"><?= number_format($stats['on_progress_projects'], 0, ',', '.') ?></h3>
                    <p class="text-muted mb-0 font-size-13">Proyek berjalan</p>
                </div>
                <div class="mt-3">
                    <div class="text-dark fw-bold font-size-20 text-truncate" title="Rp. <?= number_format($stats['on_progress_projects_rab'], 0, ',', '.') ?>">
                        Rp. <?= number_format($stats['on_progress_projects_rab'], 0, ',', '.') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. Selesai -->
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 mb-0">
            <div class="card-heading p-4 h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h5 class="font-size-17 mb-0">Selesai</h5>
                        <div class="mini-stat-icon">
                            <i class="mdi mdi-check-circle-outline bg-success text-white"></i>
                        </div>
                    </div>
                    <h3 class="mb-1"><?= number_format($stats['completed_projects'], 0, ',', '.') ?></h3>
                    <p class="text-muted mb-0 font-size-13">Proyek selesai</p>
                </div>
                <div class="mt-3">
                    <div class="text-dark fw-bold font-size-20 text-truncate" title="Rp. <?= number_format($stats['completed_projects_rab'], 0, ',', '.') ?>">
                        Rp. <?= number_format($stats['completed_projects_rab'], 0, ',', '.') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Recent Projects -->
    <div class="col-xl-6 mb-4">
        <div class="card shadow-sm border-0 h-100 mb-0">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="mt-0 header-title mb-0">Proyek Terbaru</h4>
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
                                    <a href="<?= $baseUrl ?>/pages/projects/view.php?id=<?= $project['id'] ?>" class="text-dark fw-medium">
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
    <div class="col-xl-6 mb-4">
        <div class="card shadow-sm border-0 h-100 mb-0">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="mt-0 header-title mb-0">Pengajuan Terbaru</h4>
                    <a href="<?= $baseUrl ?>/pages/requests/index.php" class="btn btn-sm btn-primary">Lihat Semua</a>
                </div>

                <!-- Pending Request Summary Widget Banner -->
                <div class="alert border-0 p-3 mb-3 rounded-3 shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-2" style="background: linear-gradient(135deg, #fffbf0 0%, #fff3cd 100%); border-left: 4px solid #f1b44c !important;">
                    <div class="d-flex align-items-center">
                        <div class="mini-stat-icon me-3">
                            <i class="mdi mdi-file-document-edit bg-warning text-white" style="width: 44px; height: 44px; line-height: 44px; font-size: 22px;"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-bold text-dark font-size-14">Pending Request</span>
                                <span class="badge bg-warning text-dark font-size-12 px-2 py-1"><?= $stats['pending_requests'] ?></span>
                            </div>
                            <div class="text-muted font-size-12">Menunggu approval</div>
                            <div class="text-dark fw-bold font-size-20 mt-1" title="Rp. <?= number_format($stats['pending_requests_nominal'], 0, ',', '.') ?>">
                                Rp. <?= number_format($stats['pending_requests_nominal'], 0, ',', '.') ?>
                            </div>
                        </div>
                    </div>
                    <?php if (hasPermission('requests.approve')): ?>
                    <div>
                        <a href="<?= $baseUrl ?>/pages/requests/approval.php" class="btn btn-sm btn-warning text-dark fw-medium waves-effect">
                            <i class="mdi mdi-check-circle-outline me-1"></i> Review Approval <i class="mdi mdi-arrow-right ms-1"></i>
                        </a>
                    </div>
                    <?php else: ?>
                    <div>
                        <a href="<?= $baseUrl ?>/pages/requests/index.php?status=pending" class="btn btn-sm btn-outline-warning text-dark font-size-12">
                            Lihat Pengajuan <i class="mdi mdi-arrow-right ms-1"></i>
                        </a>
                    </div>
                    <?php endif; ?>
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
                                <td>
                                    <a href="<?= $baseUrl ?>/pages/requests/view_request.php?id=<?= $request['id'] ?>" class="text-dark fw-medium">
                                        <?= sanitize($request['request_number']) ?>
                                    </a>
                                </td>
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
