<?php
/**
 * Reports Dashboard
 * PCM - Project Cost Management System
 * Updated for new per-project master data structure
 */

$pageTitle = 'Dashboard Laporan';
require_once __DIR__ . '/../../includes/header.php';
requirePermission('reports.view');

$projectId = $_GET['project_id'] ?? '';

// Get projects for filter based on view mode
$repViewMode = getProjectViewMode();
if ($repViewMode === 'all') {
    $projects = dbGetAll("SELECT id, name FROM projects WHERE status != 'draft' ORDER BY name");
} elseif ($repViewMode === 'assigned') {
    $projects = dbGetAll("
        SELECT p.id, p.name 
        FROM projects p 
        JOIN project_assignments pa ON pa.project_id = p.id 
        WHERE p.status != 'draft' AND pa.user_id = ? AND pa.is_active = 1 
        ORDER BY p.name
    ", [getCurrentUserId()]);
} else {
    $projects = [];
}

// If project selected, get detailed stats
$projectStats = null;
$categoryStats = [];
if ($projectId) {
    if (!canAccessProject($projectId)) {
        setFlash('error', 'Anda tidak memiliki akses ke laporan proyek ini.');
        header('Location: dashboard.php');
        exit;
    }
    $projectStats = calculateProjectRealtimeStats($projectId);
    if ($projectStats) {
        $categoryStats = $projectStats['category_stats'];
    }
}

// Get overall stats across all non-draft projects
$overallStats = getOverallProjectsRealtimeStats();
?>

<!-- Page Title -->
<div class="row">
    <div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
            <h4 class="mb-sm-0">Dashboard Laporan</h4>
            <div class="page-title-right">
                <ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>">PCM</a></li>
                    <li class="breadcrumb-item active">Dashboard</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Overall Stats Cards -->
<div class="row">
    <div class="col-xl-3 col-md-6">
        <div class="card">
            <div class="card-body">
                <h6 class="text-muted mb-2">Total RAB (Kontrak)</h6>
                <h3 class="text-primary"><?= formatRupiah($overallStats['total_rab'] ?? 0) ?></h3>
                <small class="text-muted"><?= $overallStats['total_projects'] ?? 0 ?> proyek</small>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card">
            <div class="card-body">
                <h6 class="text-muted mb-2">Total RAP (Budget)</h6>
                <h3 class="text-info"><?= formatRupiah($overallStats['total_rap'] ?? 0) ?></h3>
                <small class="text-muted">Target pengeluaran</small>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card">
            <div class="card-body">
                <h6 class="text-muted mb-2">Total Aktual</h6>
                <?php $totalActual = $overallStats['total_actual'] ?? 0; $totalRap = $overallStats['total_rap'] ?? 0; ?>
                <h3 class="<?= $totalActual > $totalRap ? 'text-danger' : 'text-success' ?>">
                    <?= formatRupiah($totalActual) ?>
                </h3>
                <small class="text-muted">Pengeluaran approved</small>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card">
            <div class="card-body">
                <h6 class="text-muted mb-2">Margin Potensial</h6>
                <?php $margin = ($overallStats['total_rab'] ?? 0) - $totalActual; ?>
                <h3 class="<?= $margin >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= formatRupiah($margin) ?>
                </h3>
                <small class="text-muted">RAB - Aktual</small>
            </div>
        </div>
    </div>
</div>

<!-- Project Filter -->
<div class="row mb-3">
    <div class="col-12">
        <div class="card">
            <div class="card-body py-2">
                <form method="GET" class="row g-2 align-items-center">
                    <div class="col-md-4">
                        <select class="form-select" name="project_id" onchange="this.form.submit()">
                            <option value="">-- Detail per Proyek --</option>
                            <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $projectId == $p['id'] ? 'selected' : '' ?>>
                                <?= sanitize($p['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8 text-end">
                        <a href="export.php" class="btn btn-success">
                            <i class="mdi mdi-file-excel"></i> Export CSV
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($projectStats): ?>
<!-- Project Detail -->
<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><?= sanitize($projectStats['name']) ?></h5>
            </div>
            <div class="card-body">
                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="border rounded p-3 text-center bg-primary bg-opacity-10">
                            <h6 class="text-muted mb-1">RAB</h6>
                            <h4 class="text-primary mb-0"><?= formatRupiah($projectStats['total_rab']) ?></h4>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 text-center bg-info bg-opacity-10">
                            <h6 class="text-muted mb-1">RAP</h6>
                            <h4 class="text-info mb-0"><?= formatRupiah($projectStats['total_rap']) ?></h4>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 text-center <?= $projectStats['total_actual'] > $projectStats['total_rap'] ? 'bg-danger' : 'bg-success' ?> bg-opacity-10">
                            <h6 class="text-muted mb-1">Aktual</h6>
                            <h4 class="<?= $projectStats['total_actual'] > $projectStats['total_rap'] ? 'text-danger' : 'text-success' ?> mb-0">
                                <?= formatRupiah($projectStats['total_actual']) ?>
                            </h4>
                        </div>
                    </div>
                </div>
                
                <!-- Category Breakdown -->
                <h6 class="mb-3">Detail per Kategori</h6>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Kategori</th>
                                <th class="text-end">RAB</th>
                                <th class="text-end">RAP</th>
                                <th class="text-end">Aktual</th>
                                <th class="text-end">Sisa RAP</th>
                                <th class="text-end">Margin</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categoryStats as $cat): 
                                $sisaRap = $cat['rap_total'] - $cat['actual_total'];
                                $catMargin = $cat['rab_total'] - $cat['actual_total'];
                            ?>
                            <tr>
                                <td><?= sanitize($cat['code']) ?>. <?= sanitize($cat['name']) ?></td>
                                <td class="text-end"><?= formatRupiah($cat['rab_total']) ?></td>
                                <td class="text-end"><?= formatRupiah($cat['rap_total']) ?></td>
                                <td class="text-end"><?= formatRupiah($cat['actual_total']) ?></td>
                                <td class="text-end <?= $sisaRap < 0 ? 'text-danger' : '' ?>">
                                    <?= formatRupiah($sisaRap) ?>
                                </td>
                                <td class="text-end <?= $catMargin >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= formatRupiah($catMargin) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <tr>
                                <th>SUBTOTAL</th>
                                <th class="text-end"><?= formatRupiah($projectStats['subtotal_rab']) ?></th>
                                <th class="text-end"><?= formatRupiah($projectStats['total_rap']) ?></th>
                                <th class="text-end"><?= formatRupiah($projectStats['total_actual']) ?></th>
                                <th class="text-end"><?= formatRupiah($projectStats['total_rap'] - $projectStats['total_actual']) ?></th>
                                <th class="text-end"><?= formatRupiah($projectStats['subtotal_rab'] - $projectStats['total_actual']) ?></th>
                            </tr>
                            <?php if (($projectStats['ppn_amount'] ?? 0) > 0): ?>
                            <tr>
                                <th>PPN (<?= number_format($projectStats['project']['ppn_percentage'] ?? 11, 2, ',', '.') ?>%)</th>
                                <th class="text-end"><?= formatRupiah($projectStats['ppn_amount']) ?></th>
                                <th colspan="4"></th>
                            </tr>
                            <tr>
                                <th>TOTAL RAB (DIBULATKAN)</th>
                                <th class="text-end"><?= formatRupiah($projectStats['total_rab']) ?></th>
                                <th colspan="4"></th>
                            </tr>
                            <?php endif; ?>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Margin Analysis -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Analisis Margin</h5>
            </div>
            <div class="card-body">
                <?php 
                $marginRabRap = $projectStats['total_rab'] - $projectStats['total_rap'];
                $marginRapAktual = $projectStats['total_rap'] - $projectStats['total_actual'];
                $marginTotal = $projectStats['total_rab'] - $projectStats['total_actual'];
                $percentUsed = $projectStats['total_rap'] > 0 ? ($projectStats['total_actual'] / $projectStats['total_rap']) * 100 : 0;
                ?>
                
                <div class="mb-4">
                    <div class="d-flex justify-content-between mb-1">
                        <span>Budget Used</span>
                        <span><?= number_format($percentUsed, 1) ?>%</span>
                    </div>
                    <div class="progress" style="height: 20px;">
                        <div class="progress-bar <?= $percentUsed > 100 ? 'bg-danger' : ($percentUsed > 80 ? 'bg-warning' : 'bg-success') ?>" 
                             style="width: <?= min($percentUsed, 100) ?>%"></div>
                    </div>
                </div>
                
                <table class="table table-sm">
                    <tr>
                        <td>Margin RAB vs RAP</td>
                        <td class="text-end <?= $marginRabRap >= 0 ? 'text-success' : 'text-danger' ?>">
                            <strong><?= formatRupiah($marginRabRap) ?></strong>
                        </td>
                    </tr>
                    <tr>
                        <td>Sisa Budget RAP</td>
                        <td class="text-end <?= $marginRapAktual >= 0 ? 'text-success' : 'text-danger' ?>">
                            <strong><?= formatRupiah($marginRapAktual) ?></strong>
                        </td>
                    </tr>
                    <tr class="table-light">
                        <td><strong>Total Margin</strong></td>
                        <td class="text-end <?= $marginTotal >= 0 ? 'text-success' : 'text-danger' ?>">
                            <strong><?= formatRupiah($marginTotal) ?></strong>
                        </td>
                    </tr>
                </table>
                
                <hr>
                <h6>Status</h6>
                <?php if ($percentUsed > 100): ?>
                <div class="alert alert-danger py-2">
                    <i class="mdi mdi-alert"></i> Budget RAP telah terlampaui!
                </div>
                <?php elseif ($percentUsed > 80): ?>
                <div class="alert alert-warning py-2">
                    <i class="mdi mdi-alert-circle"></i> Penggunaan budget > 80%
                </div>
                <?php else: ?>
                <div class="alert alert-success py-2">
                    <i class="mdi mdi-check-circle"></i> Budget dalam batas aman
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
