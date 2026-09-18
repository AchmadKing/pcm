<?php
/**
 * Reports Dashboard
 * PCC - Project Cost Control System
 * Updated for new per-project master data structure and comprehensive Export feature
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();
requirePermission('reports.view');

$pageTitle = 'Dashboard Laporan';
require_once __DIR__ . '/../../includes/header.php';


$projectId = $_GET['project_id'] ?? '';

// Get projects for filter based on view mode
$repViewMode = getProjectViewMode();
if ($repViewMode === 'all') {
    $projects = dbGetAll("
        SELECT p.* 
        FROM projects p 
        WHERE p.status != 'draft' 
        ORDER BY p.name
    ");
} elseif ($repViewMode === 'assigned') {
    $projects = dbGetAll("
        SELECT p.* 
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
            <h4 class="mb-sm-0">Dashboard Laporan & Analisa Biaya</h4>
            <div class="page-title-right">
                <ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>">PCC</a></li>
                    <li class="breadcrumb-item active">Dashboard Laporan</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Overall Stats Cards -->
<div class="row">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card mini-stats-wid shadow-sm border-0 h-100 mb-0">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-grow-1">
                        <p class="text-muted fw-medium mb-1">Total RAB (Kontrak)</p>
                        <h4 class="text-primary mb-1"><?= formatRupiah($overallStats['total_rab'] ?? 0) ?></h4>
                        <small class="text-muted"><i class="mdi mdi-office-building me-1"></i><?= $overallStats['total_projects'] ?? 0 ?> proyek</small>
                    </div>
                    <div class="avatar-sm align-self-center ms-2 flex-shrink-0">
                        <span class="avatar-title rounded-circle bg-primary bg-soft text-primary font-size-22">
                            <i class="mdi mdi-file-document-outline"></i>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card mini-stats-wid shadow-sm border-0 h-100 mb-0">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-grow-1">
                        <p class="text-muted fw-medium mb-1">Total RAP (Budget)</p>
                        <h4 class="text-info mb-1"><?= formatRupiah($overallStats['total_rap'] ?? 0) ?></h4>
                        <small class="text-muted">Target pagu pengeluaran</small>
                    </div>
                    <div class="avatar-sm align-self-center ms-2 flex-shrink-0">
                        <span class="avatar-title rounded-circle bg-info bg-soft text-info font-size-22">
                            <i class="mdi mdi-calculator"></i>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card mini-stats-wid shadow-sm border-0 h-100 mb-0">
            <div class="card-body">
                <?php $totalActual = $overallStats['total_actual'] ?? 0; $totalRap = $overallStats['total_rap'] ?? 0; ?>
                <div class="d-flex align-items-center">
                    <div class="flex-grow-1">
                        <p class="text-muted fw-medium mb-1">Total Realisasi (Aktual)</p>
                        <h4 class="<?= $totalActual > $totalRap ? 'text-danger' : 'text-success' ?> mb-1">
                            <?= formatRupiah($totalActual) ?>
                        </h4>
                        <small class="text-muted">Pengeluaran ter-approve</small>
                    </div>
                    <div class="avatar-sm align-self-center ms-2 flex-shrink-0">
                        <span class="avatar-title rounded-circle <?= $totalActual > $totalRap ? 'bg-danger text-danger' : 'bg-success text-success' ?> bg-soft font-size-22">
                            <i class="mdi mdi-cash-check"></i>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card mini-stats-wid shadow-sm border-0 h-100 mb-0">
            <div class="card-body">
                <?php $margin = ($overallStats['total_rab'] ?? 0) - $totalActual; ?>
                <div class="d-flex align-items-center">
                    <div class="flex-grow-1">
                        <p class="text-muted fw-medium mb-1">Margin Potensial</p>
                        <h4 class="<?= $margin >= 0 ? 'text-success' : 'text-danger' ?> mb-1">
                            <?= formatRupiah($margin) ?>
                        </h4>
                        <small class="text-muted">Total RAB - Total Aktual</small>
                    </div>
                    <div class="avatar-sm align-self-center ms-2 flex-shrink-0">
                        <span class="avatar-title rounded-circle <?= $margin >= 0 ? 'bg-success text-success' : 'bg-danger text-danger' ?> bg-soft font-size-22">
                            <i class="mdi mdi-trending-up"></i>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Project Filter & Export Bar -->
<div class="row mb-3">
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-body py-2">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <form method="GET" class="d-flex align-items-center gap-2 flex-grow-1" style="max-width: 420px;">
                        <select class="form-select select2" name="project_id" onchange="this.form.submit()">
                            <option value="">-- Rekapitulasi Semua Proyek --</option>
                            <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $projectId == $p['id'] ? 'selected' : '' ?>>
                                <?= sanitize($p['name']) ?> (<?= $p['status'] === 'on_progress' ? 'Berjalan' : 'Selesai' ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <div class="d-flex gap-2">
                        <?php if (hasPermission('reports.export')): ?>
                        <!-- Main Export Button (Opens Modal) -->
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#exportReportModal">
                            <i class="mdi mdi-download me-1"></i> Export Laporan
                        </button>
                        <!-- Dedicated Export Page Link -->
                        <a href="export.php<?= $projectId ? '?project_id=' . $projectId : '' ?>" class="btn btn-outline-primary" title="Buka Halaman Export Lengkap">
                            <i class="mdi mdi-file-export-outline me-1"></i> Menu Export
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($projectStats): ?>
<!-- ========================================================================= -->
<!-- DETAIL SATU PROYEK TERPILIH -->
<!-- ========================================================================= -->
<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-light d-flex justify-content-between align-items-center py-3">
                <h5 class="mb-0 text-dark">
                    <i class="mdi mdi-folder-open-outline text-primary me-2"></i>
                    <?= sanitize($projectStats['name']) ?>
                </h5>
                <span class="badge bg-<?= $projectStats['project']['status'] === 'on_progress' ? 'primary' : 'success' ?>">
                    <?= $projectStats['project']['status'] === 'on_progress' ? 'On Progress' : 'Completed' ?>
                </span>
            </div>
            <div class="card-body">
                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="border rounded p-3 text-center bg-primary bg-opacity-10">
                            <h6 class="text-muted mb-1">RAB (Kontrak)</h6>
                            <h4 class="text-primary mb-0"><?= formatRupiah($projectStats['total_rab']) ?></h4>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 text-center bg-info bg-opacity-10">
                            <h6 class="text-muted mb-1">RAP (Budget)</h6>
                            <h4 class="text-info mb-0"><?= formatRupiah($projectStats['total_rap']) ?></h4>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 text-center <?= $projectStats['total_actual'] > $projectStats['total_rap'] ? 'bg-danger' : 'bg-success' ?> bg-opacity-10">
                            <h6 class="text-muted mb-1">Realisasi (Aktual)</h6>
                            <h4 class="<?= $projectStats['total_actual'] > $projectStats['total_rap'] ? 'text-danger' : 'text-success' ?> mb-0">
                                <?= formatRupiah($projectStats['total_actual']) ?>
                            </h4>
                        </div>
                    </div>
                </div>
                
                <!-- Category Breakdown -->
                <h6 class="mb-3 fw-bold"><i class="mdi mdi-view-list me-1 text-primary"></i> Detail Biaya per Kategori Pekerjaan</h6>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm table-hover align-middle">
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
                                <td><strong><?= sanitize($cat['code']) ?></strong>. <?= sanitize($cat['name']) ?></td>
                                <td class="text-end"><?= formatRupiah($cat['rab_total']) ?></td>
                                <td class="text-end"><?= formatRupiah($cat['rap_total']) ?></td>
                                <td class="text-end"><?= formatRupiah($cat['actual_total']) ?></td>
                                <td class="text-end <?= $sisaRap < 0 ? 'text-danger fw-bold' : '' ?>">
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
        <div class="card shadow-sm border-0">
            <div class="card-header bg-light py-3">
                <h5 class="mb-0 text-dark"><i class="mdi mdi-chart-donut me-1 text-primary"></i> Analisis Margin & Budget</h5>
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
                        <span class="fw-semibold">Penggunaan Budget RAP</span>
                        <span class="fw-bold <?= $percentUsed > 100 ? 'text-danger' : ($percentUsed > 80 ? 'text-warning' : 'text-success') ?>">
                            <?= number_format($percentUsed, 1) ?>%
                        </span>
                    </div>
                    <div class="progress" style="height: 18px;">
                        <div class="progress-bar <?= $percentUsed > 100 ? 'bg-danger' : ($percentUsed > 80 ? 'bg-warning' : 'bg-success') ?>" 
                             style="width: <?= min($percentUsed, 100) ?>%"></div>
                    </div>
                </div>
                
                <table class="table table-sm mb-3">
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
                        <td><strong>Total Margin Proyek</strong></td>
                        <td class="text-end <?= $marginTotal >= 0 ? 'text-success' : 'text-danger' ?>">
                            <strong><?= formatRupiah($marginTotal) ?></strong>
                        </td>
                    </tr>
                </table>
                
                <hr>
                <h6>Status Budget</h6>
                <?php if ($percentUsed > 100): ?>
                <div class="alert alert-danger py-2 mb-0">
                    <i class="mdi mdi-alert me-1"></i> Budget RAP telah terlampaui!
                </div>
                <?php elseif ($percentUsed > 80): ?>
                <div class="alert alert-warning py-2 mb-0">
                    <i class="mdi mdi-alert-circle me-1"></i> Penggunaan budget telah mencapai &gt; 80%
                </div>
                <?php else: ?>
                <div class="alert alert-success py-2 mb-0">
                    <i class="mdi mdi-check-circle me-1"></i> Penggunaan budget dalam batas aman
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ========================================================================= -->
<!-- REKAPITULASI SELURUH PROYEK TABLE (OVERALL VIEW) -->
<!-- ========================================================================= -->
<div class="row">
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-light d-flex justify-content-between align-items-center py-3">
                <h5 class="mb-0 text-dark">
                    <i class="mdi mdi-table-large text-primary me-2"></i> Rekapitulasi Performa & Biaya Seluruh Proyek
                </h5>
                <small class="text-muted">Total <?= count($projects) ?> Proyek</small>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle table-sm">
                        <thead class="table-light">
                            <tr>
                                <th width="35" class="text-center">No</th>
                                <th>Nama Proyek</th>
                                <th width="100" class="text-center">Status</th>
                                <th width="130">Wilayah</th>
                                <th width="150" class="text-end">RAB (Kontrak)</th>
                                <th width="150" class="text-end">RAP (Budget)</th>
                                <th width="150" class="text-end">Realisasi (Aktual)</th>
                                <th width="140" class="text-end">Sisa RAP</th>
                                <th width="140" class="text-end">Margin</th>
                                <th width="100" class="text-center">% Pemakaian</th>
                                <th width="120" class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = 1;
                            $sumRab = 0; $sumRap = 0; $sumAct = 0;
                            foreach ($projects as $p):
                                $pStats = calculateProjectRealtimeStats($p['id']);
                                $rRab = floatval($pStats['total_rab'] ?? 0);
                                $rRap = floatval($pStats['total_rap'] ?? 0);
                                $rAct = floatval($pStats['total_actual'] ?? 0);
                                $rSisa = $rRap - $rAct;
                                $rMargin = $rRab - $rAct;
                                $rPct = $rRap > 0 ? ($rAct / $rRap) * 100 : 0;

                                $sumRab += $rRab;
                                $sumRap += $rRap;
                                $sumAct += $rAct;
                            ?>
                            <tr>
                                <td class="text-center"><?= $no++ ?></td>
                                <td>
                                    <a href="?project_id=<?= $p['id'] ?>" class="fw-bold text-primary">
                                        <?= sanitize($p['name']) ?>
                                    </a>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $p['status'] === 'on_progress' ? 'primary' : 'success' ?>">
                                        <?= $p['status'] === 'on_progress' ? 'Berjalan' : 'Selesai' ?>
                                    </span>
                                </td>
                                <td><?= sanitize($p['region_name'] ?? '-') ?></td>
                                <td class="text-end"><?= formatRupiah($rRab) ?></td>
                                <td class="text-end"><?= formatRupiah($rRap) ?></td>
                                <td class="text-end"><?= formatRupiah($rAct) ?></td>
                                <td class="text-end <?= $rSisa < 0 ? 'text-danger fw-bold' : '' ?>"><?= formatRupiah($rSisa) ?></td>
                                <td class="text-end <?= $rMargin >= 0 ? 'text-success' : 'text-danger' ?>"><?= formatRupiah($rMargin) ?></td>
                                <td class="text-center">
                                    <span class="badge <?= $rPct > 100 ? 'bg-danger' : ($rPct > 80 ? 'bg-warning' : 'bg-success') ?>">
                                        <?= number_format($rPct, 1) ?>%
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <a href="?project_id=<?= $p['id'] ?>" class="btn btn-outline-primary" title="Lihat Detail Laporan">
                                            <i class="mdi mdi-eye"></i>
                                        </a>
                                        <a href="export.php?project_id=<?= $p['id'] ?>" class="btn btn-outline-success" title="Export Laporan Proyek Ini">
                                            <i class="mdi mdi-download"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($projects)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-4 text-muted">Belum ada data proyek aktif.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <?php 
                            $sumSisa = $sumRap - $sumAct;
                            $sumMargin = $sumRab - $sumAct;
                            $sumPct = $sumRap > 0 ? ($sumAct / $sumRap) * 100 : 0;
                            ?>
                            <tr>
                                <th colspan="4" class="text-center">TOTAL KESELURUHAN</th>
                                <th class="text-end"><?= formatRupiah($sumRab) ?></th>
                                <th class="text-end"><?= formatRupiah($sumRap) ?></th>
                                <th class="text-end"><?= formatRupiah($sumAct) ?></th>
                                <th class="text-end"><?= formatRupiah($sumSisa) ?></th>
                                <th class="text-end"><?= formatRupiah($sumMargin) ?></th>
                                <th class="text-center"><?= number_format($sumPct, 1) ?>%</th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- MODAL: EXPORT LAPORAN PROYEK -->
<!-- ========================================================================= -->
<div class="modal fade" id="exportReportModal" tabindex="-1" aria-labelledby="exportReportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title text-white" id="exportReportModalLabel">
                    <i class="mdi mdi-file-export-outline me-1"></i> Export Laporan Proyek
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="GET" action="export.php" target="_blank">
                <div class="modal-body p-4">
                    
                    <!-- Cakupan Proyek -->
                    <div class="mb-3">
                        <label class="form-label fw-bold text-dark"><i class="mdi mdi-office-building text-primary me-1"></i> Cakupan Proyek</label>
                        <select class="form-select" name="project_id" id="modalProjectSelect" onchange="modalToggleOptions()">
                            <option value="all" <?= empty($projectId) ? 'selected' : '' ?>>Semua Proyek (Seluruh Proyek)</option>
                            <optgroup label="Pilih Proyek Spesifik">
                                <?php foreach ($projects as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= $projectId == $p['id'] ? 'selected' : '' ?>>
                                    <?= sanitize($p['name']) ?> (<?= $p['status'] === 'on_progress' ? 'Berjalan' : 'Selesai' ?>)
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>

                    <!-- Filter Periode Waktu -->
                    <div class="mb-3 bg-light p-3 rounded border">
                        <label class="form-label fw-bold text-dark mb-2"><i class="mdi mdi-calendar-range text-primary me-1"></i> Filter Periode Waktu</label>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Dari Tanggal</label>
                                <input type="date" class="form-control" name="start_date" id="modalStartDate">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Sampai Tanggal</label>
                                <input type="date" class="form-control" name="end_date" id="modalEndDate">
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2" onclick="setModalPeriodPreset('all')">Semua Waktu</button>
                            <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2" onclick="setModalPeriodPreset('this_month')">Bulan Ini</button>
                            <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2" onclick="setModalPeriodPreset('last_month')">Bulan Lalu</button>
                            <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2" onclick="setModalPeriodPreset('this_year')">Tahun Ini</button>
                        </div>
                        <small class="text-muted d-block mt-2 font-italic">Kosongkan untuk mengekspor keseluruhan periode waktu proyek.</small>
                    </div>

                    <!-- Jenis Laporan -->
                    <div class="mb-3">
                        <label class="form-label fw-bold text-dark"><i class="mdi mdi-format-list-bulleted-type text-primary me-1"></i> Jenis Laporan</label>
                        <div class="list-group">
                            <label class="list-group-item d-flex align-items-center cursor-pointer">
                                <input class="form-check-input me-3" type="radio" name="type" value="rekap" checked>
                                <div>
                                    <div class="fw-bold text-dark">Rekapitulasi Ringkasan Proyek</div>
                                    <small class="text-muted">Total RAB, RAP, Realisasi, Sisa Budget, dan Margin per proyek / kategori.</small>
                                </div>
                            </label>
                            <label class="list-group-item d-flex align-items-center cursor-pointer">
                                <input class="form-check-input me-3" type="radio" name="type" value="transactions">
                                <div>
                                    <div class="fw-bold text-dark">Detail Realisasi & Pengeluaran Transaksi</div>
                                    <small class="text-muted">Daftar item pekerjaan/biaya yang diajukan & disetujui dalam periode.</small>
                                </div>
                            </label>
                            <label class="list-group-item d-flex align-items-center cursor-pointer">
                                <input class="form-check-input me-3" type="radio" name="type" value="comparison">
                                <div>
                                    <div class="fw-bold text-dark">Perbandingan RAB vs RAP vs Realisasi</div>
                                    <small class="text-muted">Analisis komparasi volume & harga per subkategori pekerjaan.</small>
                                </div>
                            </label>
                            <label class="list-group-item d-flex align-items-center cursor-pointer" id="modalRabOptionLabel">
                                <input class="form-check-input me-3" type="radio" name="type" value="rab" id="modalRabOption">
                                <div>
                                    <div class="fw-bold text-dark">Rencana Anggaran Biaya (RAB Kontrak)</div>
                                    <small class="text-muted">Rincian item pekerjaan kontrak (khusus 1 proyek spesifik).</small>
                                </div>
                            </label>
                        </div>
                    </div>

                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    <!-- Download CSV Button -->
                    <button type="submit" name="format" value="csv" class="btn btn-success">
                        <i class="mdi mdi-file-excel me-1"></i> Download CSV / Excel
                    </button>
                    <!-- PDF Print Button -->
                    <button type="submit" name="format" value="pdf" class="btn btn-danger">
                        <i class="mdi mdi-file-pdf-box me-1"></i> Cetak / PDF
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function modalToggleOptions() {
    const proj = document.getElementById('modalProjectSelect').value;
    const rabLabel = document.getElementById('modalRabOptionLabel');
    const rabInput = document.getElementById('modalRabOption');
    
    if (proj === 'all') {
        rabLabel.style.opacity = '0.5';
        if (rabInput.checked) {
            document.querySelector('#exportReportModal input[name="type"][value="rekap"]').checked = true;
        }
        rabInput.disabled = true;
    } else {
        rabLabel.style.opacity = '1';
        rabInput.disabled = false;
    }
}

function setModalPeriodPreset(preset) {
    const startInput = document.getElementById('modalStartDate');
    const endInput = document.getElementById('modalEndDate');
    const today = new Date();
    
    if (preset === 'all') {
        startInput.value = '';
        endInput.value = '';
    } else if (preset === 'this_month') {
        const y = today.getFullYear();
        const m = String(today.getMonth() + 1).padStart(2, '0');
        const lastDay = new Date(y, today.getMonth() + 1, 0).getDate();
        startInput.value = `${y}-${m}-01`;
        endInput.value = `${y}-${m}-${String(lastDay).padStart(2, '0')}`;
    } else if (preset === 'last_month') {
        const prevMonthDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
        const y = prevMonthDate.getFullYear();
        const m = String(prevMonthDate.getMonth() + 1).padStart(2, '0');
        const lastDay = new Date(y, prevMonthDate.getMonth() + 1, 0).getDate();
        startInput.value = `${y}-${m}-01`;
        endInput.value = `${y}-${m}-${String(lastDay).padStart(2, '0')}`;
    } else if (preset === 'this_year') {
        const y = today.getFullYear();
        startInput.value = `${y}-01-01`;
        endInput.value = `${y}-12-31`;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    modalToggleOptions();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
