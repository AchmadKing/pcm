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
            SELECT rc.project_id, rs.volume, rs.ahsp_id
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
                : 0.0;
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

    // Pending requests & total nominal (real-time dynamic)
    $itemTotalSql = sqlRequestItemTotalExpr('reqi');
    if (hasPermission('requests.approve')) {
        $pendingWhere = hasPermission('projects.edit') ? "req.status IN ('pending', 'pm_approved')" : "req.status = 'pending'";
        $pendingData = dbGetRow("
            SELECT 
                COUNT(*) as cnt,
                COALESCE(SUM(
                    (SELECT COALESCE(SUM($itemTotalSql), 0)
                     FROM request_items reqi 
                     WHERE reqi.request_id = req.id)
                ), 0) as total_nominal
            FROM requests req
            WHERE $pendingWhere
        ");
    } else {
        $pendingData = dbGetRow("
            SELECT 
                COUNT(*) as cnt,
                COALESCE(SUM(
                    (SELECT COALESCE(SUM($itemTotalSql), 0)
                     FROM request_items reqi 
                     WHERE reqi.request_id = req.id)
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

<?php
// Calculate percentages for statistics
$totalProj = intval($stats['total_projects']);
$draftPct = $totalProj > 0 ? round(($stats['draft_projects'] / $totalProj) * 100, 1) : 0;
$onProgressPct = $totalProj > 0 ? round(($stats['on_progress_projects'] / $totalProj) * 100, 1) : 0;
$completedPct = $totalProj > 0 ? round(($stats['completed_projects'] / $totalProj) * 100, 1) : 0;
?>

<!-- ApexCharts CSS for Dashboard -->
<link href="<?= $baseUrl ?>/dist/assets/libs/apexcharts/apexcharts.css" rel="stylesheet" type="text/css" />

<style>
.stat-card-widget {
    transition: all 0.25s ease;
}
.stat-card-widget:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.08) !important;
}
/* Mode Tampilan Toggle Segmented Control */
.stats-mode-wrapper {
    background: #edf2f9;
    border: 1px solid #c8d3e2 !important;
    padding: 3px;
    border-radius: 30px;
    display: inline-flex;
    align-items: center;
    box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.05);
}
.stats-mode-wrapper .btn-mode-toggle {
    border: none !important;
    border-radius: 20px !important;
    padding: 5px 14px;
    font-size: 13px;
    font-weight: 500;
    color: #495057 !important;
    background: transparent !important;
    transition: all 0.2s ease-in-out;
    display: inline-flex;
    align-items: center;
    line-height: 1.4;
    cursor: pointer;
}
.stats-mode-wrapper .btn-mode-toggle i {
    font-size: 14px;
    color: #6c757d;
    transition: color 0.2s ease;
}
.stats-mode-wrapper .btn-mode-toggle:hover {
    color: #556ee6 !important;
    background-color: rgba(85, 110, 230, 0.1) !important;
}
.stats-mode-wrapper .btn-mode-toggle:hover i {
    color: #556ee6 !important;
}
.stats-mode-wrapper .btn-mode-toggle.active {
    background-color: #556ee6 !important;
    color: #ffffff !important;
    font-weight: 600;
    box-shadow: 0 2px 6px rgba(85, 110, 230, 0.35);
}
.stats-mode-wrapper .btn-mode-toggle.active i {
    color: #ffffff !important;
}
/* Individual Card Toggle Button */
.btn-card-toggle {
    width: 30px;
    height: 30px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    border: 1px solid #d0d7de !important;
    background-color: #f8f9fa;
    color: #556ee6 !important;
    transition: all 0.2s ease;
}
.btn-card-toggle:hover {
    background-color: #556ee6 !important;
    border-color: #556ee6 !important;
    color: #ffffff !important;
    box-shadow: 0 2px 5px rgba(85, 110, 230, 0.3);
}
.card-stat-view {
    transition: opacity 0.2s ease;
}
.mini-chart-container {
    min-height: 120px;
    display: flex;
    align-items: center;
    justify-content: center;
}
</style>

<!-- Stats Section Header with Toggle -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="font-size-16 mb-0 fw-semibold text-dark">
            <i class="mdi mdi-chart-box-outline text-primary me-1"></i> Ringkasan Statistik Proyek
        </h5>
        <span class="text-muted font-size-12">Pantau status proyek dan akumulasi anggaran RAB</span>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="text-secondary fw-semibold font-size-13 d-none d-sm-inline">
            <i class="mdi mdi-tune-variant me-1 text-primary"></i> Mode Tampilan:
        </span>
        <div class="stats-mode-wrapper btn-group" role="group" id="statsModeGroup">
            <button type="button" class="btn btn-mode-toggle active" id="btnModeCard" data-mode="card" title="Tampilkan Kartu Ringkasan Angka">
                <i class="mdi mdi-view-grid-outline me-1"></i> Kartu
            </button>
            <button type="button" class="btn btn-mode-toggle" id="btnModeChart" data-mode="chart" title="Tampilkan Tampilan Diagram">
                <i class="mdi mdi-chart-donut me-1"></i> Diagram
            </button>
        </div>
    </div>
</div>

<!-- Stats Cards (Total Proyek, Draft, On Progress, Selesai) -->
<div class="row g-3 mb-4" id="statsCardsContainer">
    <!-- 1. Total Proyek -->
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 mb-0 stat-card-widget" id="card-stat-total">
            <div class="card-heading p-4 h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h5 class="font-size-17 mb-0">Total Proyek</h5>
                        <div class="d-flex align-items-center gap-1">
                            <button type="button" class="btn btn-sm btn-light text-muted btn-card-toggle" data-card="total" title="Ubah ke tampilan diagram">
                                <i class="mdi mdi-chart-donut"></i>
                            </button>
                            <div class="mini-stat-icon">
                                <i class="mdi mdi-briefcase bg-primary text-white"></i>
                            </div>
                        </div>
                    </div>
                    <!-- View 1: Numeric -->
                    <div class="card-stat-view view-numeric">
                        <h3 class="mb-1"><?= number_format($stats['total_projects'], 0, ',', '.') ?></h3>
                        <p class="text-muted mb-0 font-size-13">Semua proyek terdaftar</p>
                    </div>
                    <!-- View 2: Diagram -->
                    <div class="card-stat-view view-diagram d-none">
                        <div id="miniChartTotal" class="mini-chart-container"></div>
                        <p class="text-muted mb-0 font-size-12 text-center">Komposisi: <?= $stats['draft_projects'] ?> Draft, <?= $stats['on_progress_projects'] ?> Berjalan, <?= $stats['completed_projects'] ?> Selesai</p>
                    </div>
                </div>
                <div class="mt-3 pt-2 border-top border-light">
                    <small class="text-muted font-size-11 d-block mb-1">Total Nilai RAB:</small>
                    <div class="text-dark fw-bold font-size-18 text-truncate" title="Rp. <?= number_format($stats['total_projects_rab'], 0, ',', '.') ?>">
                        Rp. <?= number_format($stats['total_projects_rab'], 0, ',', '.') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. Draft -->
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 mb-0 stat-card-widget" id="card-stat-draft">
            <div class="card-heading p-4 h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h5 class="font-size-17 mb-0">Draft</h5>
                        <div class="d-flex align-items-center gap-1">
                            <button type="button" class="btn btn-sm btn-light text-muted btn-card-toggle" data-card="draft" title="Ubah ke tampilan diagram">
                                <i class="mdi mdi-chart-donut"></i>
                            </button>
                            <div class="mini-stat-icon">
                                <i class="mdi mdi-file-document-edit-outline bg-secondary text-white"></i>
                            </div>
                        </div>
                    </div>
                    <!-- View 1: Numeric -->
                    <div class="card-stat-view view-numeric">
                        <h3 class="mb-1"><?= number_format($stats['draft_projects'], 0, ',', '.') ?></h3>
                        <p class="text-muted mb-0 font-size-13">Proyek tahap draft (<?= $draftPct ?>%)</p>
                    </div>
                    <!-- View 2: Diagram -->
                    <div class="card-stat-view view-diagram d-none">
                        <div id="miniChartDraft" class="mini-chart-container"></div>
                        <p class="text-muted mb-0 font-size-12 text-center"><?= $stats['draft_projects'] ?> dari <?= $stats['total_projects'] ?> proyek (<?= $draftPct ?>%)</p>
                    </div>
                </div>
                <div class="mt-3 pt-2 border-top border-light">
                    <small class="text-muted font-size-11 d-block mb-1">Nilai RAB Draft:</small>
                    <div class="text-dark fw-bold font-size-18 text-truncate" title="Rp. <?= number_format($stats['draft_projects_rab'], 0, ',', '.') ?>">
                        Rp. <?= number_format($stats['draft_projects_rab'], 0, ',', '.') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. On Progress -->
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 mb-0 stat-card-widget" id="card-stat-onprogress">
            <div class="card-heading p-4 h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h5 class="font-size-17 mb-0">On Progress</h5>
                        <div class="d-flex align-items-center gap-1">
                            <button type="button" class="btn btn-sm btn-light text-muted btn-card-toggle" data-card="onprogress" title="Ubah ke tampilan diagram">
                                <i class="mdi mdi-chart-donut"></i>
                            </button>
                            <div class="mini-stat-icon">
                                <i class="mdi mdi-progress-clock bg-info text-white"></i>
                            </div>
                        </div>
                    </div>
                    <!-- View 1: Numeric -->
                    <div class="card-stat-view view-numeric">
                        <h3 class="mb-1"><?= number_format($stats['on_progress_projects'], 0, ',', '.') ?></h3>
                        <p class="text-muted mb-0 font-size-13">Proyek sedang berjalan (<?= $onProgressPct ?>%)</p>
                    </div>
                    <!-- View 2: Diagram -->
                    <div class="card-stat-view view-diagram d-none">
                        <div id="miniChartOnProgress" class="mini-chart-container"></div>
                        <p class="text-muted mb-0 font-size-12 text-center"><?= $stats['on_progress_projects'] ?> dari <?= $stats['total_projects'] ?> proyek (<?= $onProgressPct ?>%)</p>
                    </div>
                </div>
                <div class="mt-3 pt-2 border-top border-light">
                    <small class="text-muted font-size-11 d-block mb-1">Nilai RAB Berjalan:</small>
                    <div class="text-dark fw-bold font-size-18 text-truncate" title="Rp. <?= number_format($stats['on_progress_projects_rab'], 0, ',', '.') ?>">
                        Rp. <?= number_format($stats['on_progress_projects_rab'], 0, ',', '.') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. Selesai -->
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 mb-0 stat-card-widget" id="card-stat-completed">
            <div class="card-heading p-4 h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h5 class="font-size-17 mb-0">Selesai</h5>
                        <div class="d-flex align-items-center gap-1">
                            <button type="button" class="btn btn-sm btn-light text-muted btn-card-toggle" data-card="completed" title="Ubah ke tampilan diagram">
                                <i class="mdi mdi-chart-donut"></i>
                            </button>
                            <div class="mini-stat-icon">
                                <i class="mdi mdi-check-circle-outline bg-success text-white"></i>
                            </div>
                        </div>
                    </div>
                    <!-- View 1: Numeric -->
                    <div class="card-stat-view view-numeric">
                        <h3 class="mb-1"><?= number_format($stats['completed_projects'], 0, ',', '.') ?></h3>
                        <p class="text-muted mb-0 font-size-13">Proyek telah selesai (<?= $completedPct ?>%)</p>
                    </div>
                    <!-- View 2: Diagram -->
                    <div class="card-stat-view view-diagram d-none">
                        <div id="miniChartCompleted" class="mini-chart-container"></div>
                        <p class="text-muted mb-0 font-size-12 text-center"><?= $stats['completed_projects'] ?> dari <?= $stats['total_projects'] ?> proyek (<?= $completedPct ?>%)</p>
                    </div>
                </div>
                <div class="mt-3 pt-2 border-top border-light">
                    <small class="text-muted font-size-11 d-block mb-1">Nilai RAB Selesai:</small>
                    <div class="text-dark fw-bold font-size-18 text-truncate" title="Rp. <?= number_format($stats['completed_projects_rab'], 0, ',', '.') ?>">
                        Rp. <?= number_format($stats['completed_projects_rab'], 0, ',', '.') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Expanded Full Diagram Panel (Activated when toggled to Diagram) -->
<div id="dashboardFullChartPanel" class="card shadow-sm border-0 mb-4 d-none">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="card-title mb-1"><i class="mdi mdi-chart-timeline-variant-shimmer text-primary me-1"></i> Analisis Diagram Statistik Proyek</h5>
                <p class="text-muted font-size-12 mb-0">Perbandingan visual distribusi status proyek dan alokasi total anggaran RAB</p>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" id="btnToggleFullChart">
                <i class="mdi mdi-chevron-up me-1"></i> Sembunyikan Panel
            </button>
        </div>
        <div class="row g-4 align-items-center">
            <div class="col-lg-5">
                <div class="p-3 border rounded-3 bg-light bg-opacity-50">
                    <h6 class="text-dark font-size-13 fw-semibold text-center mb-3">Distribusi Status Proyek (Jumlah)</h6>
                    <div id="dashboardChartStatusDonut" style="min-height: 260px;"></div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="p-3 border rounded-3 bg-light bg-opacity-50">
                    <h6 class="text-dark font-size-13 fw-semibold text-center mb-3">Total Nilai Anggaran RAB per Status Proyek (Rupiah)</h6>
                    <div id="dashboardChartRabBar" style="min-height: 260px;"></div>
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

<!-- ApexCharts Script & Dashboard Card Toggle Logic -->
<script src="<?= $baseUrl ?>/dist/assets/libs/apexcharts/apexcharts.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function formatIDR(val) {
        return 'Rp ' + Number(val || 0).toLocaleString('id-ID');
    }

    var chartsInitialized = false;
    var charts = {};

    var statsData = {
        totalProjects: <?= (int)$stats['total_projects'] ?>,
        draftProjects: <?= (int)$stats['draft_projects'] ?>,
        onProgressProjects: <?= (int)$stats['on_progress_projects'] ?>,
        completedProjects: <?= (int)$stats['completed_projects'] ?>,
        draftPct: <?= $draftPct ?>,
        onProgressPct: <?= $onProgressPct ?>,
        completedPct: <?= $completedPct ?>,
        totalRab: <?= round($stats['total_projects_rab']) ?>,
        draftRab: <?= round($stats['draft_projects_rab']) ?>,
        onProgressRab: <?= round($stats['on_progress_projects_rab']) ?>,
        completedRab: <?= round($stats['completed_projects_rab']) ?>
    };

    function initCharts() {
        if (chartsInitialized) return;
        chartsInitialized = true;

        // 1. Mini Chart Total Proyek (Donut)
        var elTotal = document.querySelector("#miniChartTotal");
        if (elTotal) {
            charts.miniTotal = new ApexCharts(elTotal, {
                series: [statsData.draftProjects, statsData.onProgressProjects, statsData.completedProjects],
                labels: ['Draft', 'On Progress', 'Selesai'],
                colors: ['#74788d', '#50a5f1', '#34c38f'],
                chart: { type: 'donut', height: 110, sparkline: { enabled: true } },
                stroke: { width: 1 },
                tooltip: {
                    fixed: { enabled: false },
                    y: {
                        formatter: function(val, opts) {
                            return val + ' Proyek (' + opts.globals.labels[opts.seriesIndex] + ')';
                        }
                    }
                }
            });
            charts.miniTotal.render();
        }

        // 2. Mini Chart Draft (Radial Bar)
        var elDraft = document.querySelector("#miniChartDraft");
        if (elDraft) {
            charts.miniDraft = new ApexCharts(elDraft, {
                series: [statsData.draftPct],
                chart: { type: 'radialBar', height: 110, sparkline: { enabled: true } },
                colors: ['#74788d'],
                plotOptions: {
                    radialBar: {
                        hollow: { size: '52%' },
                        track: { background: '#f2f2f2' },
                        dataLabels: {
                            name: { show: false },
                            value: {
                                offsetY: 5,
                                fontSize: '13px',
                                fontWeight: 700,
                                formatter: function(val) { return val + '%'; }
                            }
                        }
                    }
                }
            });
            charts.miniDraft.render();
        }

        // 3. Mini Chart On Progress (Radial Bar)
        var elProg = document.querySelector("#miniChartOnProgress");
        if (elProg) {
            charts.miniProg = new ApexCharts(elProg, {
                series: [statsData.onProgressPct],
                chart: { type: 'radialBar', height: 110, sparkline: { enabled: true } },
                colors: ['#50a5f1'],
                plotOptions: {
                    radialBar: {
                        hollow: { size: '52%' },
                        track: { background: '#eaf4fd' },
                        dataLabels: {
                            name: { show: false },
                            value: {
                                offsetY: 5,
                                fontSize: '13px',
                                fontWeight: 700,
                                formatter: function(val) { return val + '%'; }
                            }
                        }
                    }
                }
            });
            charts.miniProg.render();
        }

        // 4. Mini Chart Selesai (Radial Bar)
        var elComp = document.querySelector("#miniChartCompleted");
        if (elComp) {
            charts.miniComp = new ApexCharts(elComp, {
                series: [statsData.completedPct],
                chart: { type: 'radialBar', height: 110, sparkline: { enabled: true } },
                colors: ['#34c38f'],
                plotOptions: {
                    radialBar: {
                        hollow: { size: '52%' },
                        track: { background: '#e7f8f2' },
                        dataLabels: {
                            name: { show: false },
                            value: {
                                offsetY: 5,
                                fontSize: '13px',
                                fontWeight: 700,
                                formatter: function(val) { return val + '%'; }
                            }
                        }
                    }
                }
            });
            charts.miniComp.render();
        }

        // 5. Full Panel - Donut Distribusi Status
        var elFullDonut = document.querySelector("#dashboardChartStatusDonut");
        if (elFullDonut) {
            charts.fullDonut = new ApexCharts(elFullDonut, {
                series: [statsData.draftProjects, statsData.onProgressProjects, statsData.completedProjects],
                labels: ['Draft', 'On Progress', 'Selesai'],
                colors: ['#74788d', '#50a5f1', '#34c38f'],
                chart: { type: 'donut', height: 260 },
                legend: { position: 'bottom', horizontalAlign: 'center' },
                dataLabels: {
                    enabled: true,
                    formatter: function(val) { return val.toFixed(1) + '%'; }
                },
                plotOptions: {
                    pie: {
                        donut: {
                            size: '65%',
                            labels: {
                                show: true,
                                total: {
                                    show: true,
                                    label: 'Total Proyek',
                                    formatter: function() { return statsData.totalProjects; }
                                }
                            }
                        }
                    }
                },
                tooltip: {
                    y: {
                        formatter: function(val) { return val + ' Proyek'; }
                    }
                }
            });
            charts.fullDonut.render();
        }

        // 6. Full Panel - Bar Total Anggaran RAB per Status
        var elFullBar = document.querySelector("#dashboardChartRabBar");
        if (elFullBar) {
            charts.fullBar = new ApexCharts(elFullBar, {
                series: [{
                    name: 'Nilai RAB',
                    data: [statsData.totalRab, statsData.draftRab, statsData.onProgressRab, statsData.completedRab]
                }],
                chart: { type: 'bar', height: 260, toolbar: { show: false } },
                colors: ['#556ee6', '#74788d', '#50a5f1', '#34c38f'],
                plotOptions: {
                    bar: {
                        borderRadius: 5,
                        columnWidth: '45%',
                        distributed: true,
                        dataLabels: { position: 'top' }
                    }
                },
                dataLabels: {
                    enabled: true,
                    formatter: function(val) {
                        if (val >= 1000000000) return (val / 1000000000).toFixed(1) + ' M';
                        if (val >= 1000000) return (val / 1000000).toFixed(0) + ' Jt';
                        return val.toLocaleString('id-ID');
                    },
                    offsetY: -20,
                    style: { fontSize: '11px', colors: ['#304758'] }
                },
                legend: { show: false },
                xaxis: {
                    categories: ['Semua Proyek', 'Draft', 'On Progress', 'Selesai'],
                    labels: { style: { fontSize: '12px', fontWeight: 600 } }
                },
                yaxis: {
                    labels: {
                        formatter: function(val) {
                            if (val >= 1000000000) return (val / 1000000000).toFixed(1) + ' M';
                            if (val >= 1000000) return (val / 1000000).toFixed(0) + ' Jt';
                            return val.toLocaleString('id-ID');
                        }
                    }
                },
                tooltip: {
                    y: {
                        formatter: function(val) { return formatIDR(val); }
                    }
                },
                grid: { borderColor: '#f1f1f1', padding: { top: 10 } }
            });
            charts.fullBar.render();
        }
    }

    // Toggle individual card between numeric view and diagram view
    function toggleSingleCard(cardElement, forceMode) {
        var numView = cardElement.querySelector('.view-numeric');
        var diaView = cardElement.querySelector('.view-diagram');
        var toggleBtn = cardElement.querySelector('.btn-card-toggle');
        if (!numView || !diaView) return;

        var isDiagram;
        if (forceMode !== undefined) {
            isDiagram = (forceMode === 'diagram');
        } else {
            isDiagram = diaView.classList.contains('d-none');
        }

        if (isDiagram) {
            numView.classList.add('d-none');
            diaView.classList.remove('d-none');
            if (toggleBtn) {
                toggleBtn.innerHTML = '<i class="mdi mdi-numeric"></i>';
                toggleBtn.setAttribute('title', 'Ubah ke tampilan angka');
            }
        } else {
            diaView.classList.add('d-none');
            numView.classList.remove('d-none');
            if (toggleBtn) {
                toggleBtn.innerHTML = '<i class="mdi mdi-chart-donut"></i>';
                toggleBtn.setAttribute('title', 'Ubah ke tampilan diagram');
            }
        }

        initCharts();
        setTimeout(function() {
            window.dispatchEvent(new Event('resize'));
        }, 150);
    }

    // Set global view mode: 'card' or 'chart'
    function setStatsMode(mode) {
        var btnCard = document.getElementById('btnModeCard');
        var btnChart = document.getElementById('btnModeChart');
        var fullPanel = document.getElementById('dashboardFullChartPanel');
        var allCards = document.querySelectorAll('.stat-card-widget');

        if (mode === 'chart') {
            btnCard.classList.remove('active');
            btnChart.classList.add('active');
            if (fullPanel) fullPanel.classList.remove('d-none');

            allCards.forEach(function(card) {
                toggleSingleCard(card, 'diagram');
            });
            localStorage.setItem('pcm_dashboard_stats_mode', 'chart');
        } else {
            btnChart.classList.remove('active');
            btnCard.classList.add('active');
            if (fullPanel) fullPanel.classList.add('d-none');

            allCards.forEach(function(card) {
                toggleSingleCard(card, 'numeric');
            });
            localStorage.setItem('pcm_dashboard_stats_mode', 'card');
        }

        initCharts();
        setTimeout(function() {
            window.dispatchEvent(new Event('resize'));
        }, 150);
    }

    // Global Mode Toggle Buttons
    var btnModeCard = document.getElementById('btnModeCard');
    var btnModeChart = document.getElementById('btnModeChart');
    if (btnModeCard) {
        btnModeCard.addEventListener('click', function() { setStatsMode('card'); });
    }
    if (btnModeChart) {
        btnModeChart.addEventListener('click', function() { setStatsMode('chart'); });
    }

    // Individual Card Toggle Buttons
    document.querySelectorAll('.btn-card-toggle').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            var card = this.closest('.stat-card-widget');
            if (card) {
                toggleSingleCard(card);
            }
        });
    });

    // Toggle Full Chart Panel Button
    var btnToggleFull = document.getElementById('btnToggleFullChart');
    if (btnToggleFull) {
        btnToggleFull.addEventListener('click', function() {
            var fullPanel = document.getElementById('dashboardFullChartPanel');
            if (fullPanel) fullPanel.classList.toggle('d-none');
            setTimeout(function() {
                window.dispatchEvent(new Event('resize'));
            }, 100);
        });
    }

    // Restore user preference
    var savedMode = localStorage.getItem('pcm_dashboard_stats_mode');
    if (savedMode === 'chart') {
        setStatsMode('chart');
    }
});
</script>
