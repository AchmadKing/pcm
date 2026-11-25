<?php
/**
 * Diagram & Visual Analytics Tab - Project Dashboard
 * PCC - Project Cost Control System
 */

// Ensure dependencies are available
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Overhead & PPN settings
$ppnPct = floatval($project['ppn_percentage'] ?? 11);
$rapOverheadPct = getProjectOverheadProfitPct($project, 'rap');
$rabOverheadPct = getProjectOverheadProfitPct($project, 'rab');

// 1. Synchronize & Load Spending Data
$syncedProgress = syncProjectWeeklyProgress($projectId, true);
$batchActualSpending = $syncedProgress['spending'] ?? [];
$batchActualBreakdown = $syncedProgress['breakdown'] ?? [];
$weeklyData = $syncedProgress['weekly'] ?? [];

// Calculate Cost Breakdown (Upah, Material, Alat)
$totalActualUpah = 0.0;
$totalActualMaterial = 0.0;
$totalActualAlat = 0.0;
foreach ($batchActualBreakdown as $subId => $b) {
    $totalActualUpah += floatval($b['upah'] ?? 0.0);
    $totalActualMaterial += floatval($b['material'] ?? 0.0);
    $totalActualAlat += floatval($b['alat'] ?? 0.0);
}

// Biaya Lain-Lain / Non-RAB
$totalNonRabActual = floatval(getProjectNonRabActualTotal($projectId));
$nonRabTransactions = getProjectNonRabTransactions($projectId);

// Financial Totals
$totalRealisasiNet = $totalActualUpah + $totalActualMaterial + $totalActualAlat + $totalNonRabActual;
$totalRealisasiWithPpn = ceil(($totalRealisasiNet * (1 + ($ppnPct / 100))) / 10) * 10;
// Note: $rabTotal, $rapTotal, $actualTotal, and $sisaAnggaran are precomputed in view.php
$effectiveActualTotal = ($actualTotal > 0) ? $actualTotal : $totalRealisasiWithPpn;
$effectiveSisaAnggaran = $rapTotal - $effectiveActualTotal;
$penyerapanPct = ($rapTotal > 0) ? min(round(($effectiveActualTotal / $rapTotal) * 100, 1), 999) : 0;
$penghematanRapRab = $rabTotal - $rapTotal;
$marginRapRabPct = ($rabTotal > 0) ? round(($penghematanRapRab / $rabTotal) * 100, 1) : 0;

// 2. Category Level Comparison (RAP vs Realisasi)
$categories = dbGetAll("
    SELECT rc.id, rc.code, rc.name, rc.sort_order
    FROM rab_categories rc
    WHERE rc.project_id = ?
    ORDER BY rc.sort_order, rc.code
", [$projectId]);

$rapItemBreakdowns = batchGetRapItemBreakdowns($projectId);
$categoryChartLabels = [];
$categoryChartRap = [];
$categoryChartActual = [];

foreach ($categories as $cat) {
    $cId = $cat['id'];
    $subcats = dbGetAll("
        SELECT rs.id, rs.volume as rab_volume, rap.volume as rap_volume
        FROM rab_subcategories rs
        LEFT JOIN rap_items rap ON rs.id = rap.subcategory_id
        WHERE rs.category_id = ?
    ", [$cId]);
    
    $catRapSum = 0.0;
    $catActualSum = 0.0;
    
    foreach ($subcats as $s) {
        $subId = $s['id'];
        $vol = (isset($s['rap_volume']) && $s['rap_volume'] !== null) ? floatval($s['rap_volume']) : floatval($s['rab_volume']);
        $rapComponents = $rapItemBreakdowns[$subId] ?? ['total' => 0.0];
        $baseUnitPrice = floatval($rapComponents['total'] ?? 0.0);
        $unitPriceWithOverhead = $baseUnitPrice * (1 + ($rapOverheadPct / 100));
        $catRapSum += ($vol * $unitPriceWithOverhead);
        
        $catActualSum += floatval($batchActualSpending[$subId] ?? 0.0);
    }
    
    // Add PPN for clean category comparison
    $catRapWithPpn = ceil(($catRapSum * (1 + ($ppnPct / 100))) / 10) * 10;
    $catActualWithPpn = ceil(($catActualSum * (1 + ($ppnPct / 100))) / 10) * 10;
    
    $shortName = strlen($cat['name']) > 28 ? substr($cat['name'], 0, 25) . '...' : $cat['name'];
    $categoryChartLabels[] = $cat['code'] . ' - ' . $shortName;
    $categoryChartRap[] = round($catRapWithPpn);
    $categoryChartActual[] = round($catActualWithPpn);
}

// Append Non-RAB to category comparison if present
if ($totalNonRabActual > 0) {
    $nonRabWithPpn = ceil(($totalNonRabActual * (1 + ($ppnPct / 100))) / 10) * 10;
    $categoryChartLabels[] = 'Lain-Lain (Non-RAB)';
    $categoryChartRap[] = 0;
    $categoryChartActual[] = round($nonRabWithPpn);
}

// 3. Weekly Trend / S-Curve Realisasi
$weeklyTrend = [];
if (!empty($project['start_date']) && !empty($project['duration_days'])) {
    $weeklyRanges = generateWeeklyRanges($project['start_date'], $project['duration_days']);
    foreach ($weeklyRanges as $w) {
        $wNum = $w['week_number'];
        $wLabel = !empty($w['label']) ? ' (' . $w['label'] . ')' : '';
        $weeklyTrend[$wNum] = [
            'label' => 'M-' . $wNum,
            'full_label' => 'Minggu ' . $wNum . $wLabel,
            'amount' => 0.0
        ];
    }
}

// Populate weekly amounts from subcategories
foreach ($weeklyData as $subId => $weeks) {
    if (is_array($weeks)) {
        foreach ($weeks as $wNum => $wValues) {
            $wAmt = floatval($wValues['total'] ?? 0.0);
            if (!isset($weeklyTrend[$wNum])) {
                $weeklyTrend[$wNum] = [
                    'label' => 'M-' . $wNum,
                    'full_label' => 'Minggu ' . $wNum,
                    'amount' => 0.0
                ];
            }
            $weeklyTrend[$wNum]['amount'] += $wAmt;
        }
    }
}

// Also populate weekly amounts from Non-RAB transactions
foreach ($nonRabTransactions as $tx) {
    $txAmount = floatval($tx['item_total'] ?? $tx['total_amount'] ?? $tx['total_price'] ?? 0.0);
    $txWeek = intval($tx['target_week'] ?: ($tx['week_number'] ?: 1));
    if ($txWeek > 0) {
        if (!isset($weeklyTrend[$txWeek])) {
            $weeklyTrend[$txWeek] = [
                'label' => 'M-' . $txWeek,
                'full_label' => 'Minggu ' . $txWeek,
                'amount' => 0.0
            ];
        }
        $weeklyTrend[$txWeek]['amount'] += $txAmount;
    }
}

// Sort weekly trend by week number
ksort($weeklyTrend);

$trendLabels = [];
$trendWeeklyAmount = [];
$trendCumulativeAmount = [];
$cumulativeSum = 0.0;

foreach ($weeklyTrend as $wNum => $tData) {
    $trendLabels[] = $tData['label'];
    $amtWithPpn = ceil(($tData['amount'] * (1 + ($ppnPct / 100))) / 10) * 10;
    $trendWeeklyAmount[] = round($amtWithPpn);
    $cumulativeSum += $amtWithPpn;
    $trendCumulativeAmount[] = round($cumulativeSum);
}

// 4. Request Status Breakdown
$itemTotalSql = sqlRequestItemTotalExpr('reqi');
$reqStatusRows = dbGetAll("
    SELECT req.status,
           COUNT(*) as cnt,
           COALESCE(SUM(
               (SELECT COALESCE(SUM($itemTotalSql), 0)
                FROM request_items reqi
                WHERE reqi.request_id = req.id)
           ), 0) as total_nominal
    FROM requests req
    WHERE req.project_id = ?
    GROUP BY req.status
", [$projectId]);

$statusTranslation = [
    'approved' => ['label' => 'Disetujui', 'color' => '#34c38f'],
    'pm_approved' => ['label' => 'Disetujui PM (Pending Admin)', 'color' => '#f1b44c'],
    'pending' => ['label' => 'Pending Review', 'color' => '#50a5f1'],
    'rejected' => ['label' => 'Ditolak', 'color' => '#f46a6a'],
    'draft' => ['label' => 'Draft', 'color' => '#74788d']
];

$reqStatusLabels = [];
$reqStatusCounts = [];
$reqStatusNominals = [];
$reqStatusColors = [];

foreach ($reqStatusRows as $r) {
    $st = strtolower(trim($r['status']));
    $conf = $statusTranslation[$st] ?? ['label' => ucfirst($st), 'color' => '#6c757d'];
    $reqStatusLabels[] = $conf['label'];
    $reqStatusCounts[] = intval($r['cnt']);
    $reqStatusNominals[] = round(floatval($r['total_nominal']));
    $reqStatusColors[] = $conf['color'];
}

// 5. Top 5 Subcategories Spending
$topSpendingItems = dbGetAll("
    SELECT rs.code as subcategory_code, rs.name as subcategory_name, rc.name as category_name,
           COALESCE(SUM($itemTotalSql), 0) as total_spending
    FROM request_items reqi
    JOIN requests req ON reqi.request_id = req.id
    JOIN rab_subcategories rs ON reqi.subcategory_id = rs.id
    JOIN rab_categories rc ON rs.category_id = rc.id
    WHERE req.project_id = ? AND req.status = 'approved'
    GROUP BY rs.id
    ORDER BY total_spending DESC
    LIMIT 5
", [$projectId]);
?>

<!-- ApexCharts CSS -->
<link href="<?= $baseUrl ?>/dist/assets/libs/apexcharts/apexcharts.css" rel="stylesheet" type="text/css" />

<style>
.chart-card {
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    border-radius: 10px;
}
.chart-card:hover {
    box-shadow: 0 4px 14px rgba(0,0,0,0.08) !important;
}
.chart-badge-indicator {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    margin-right: 6px;
}
.metric-pill {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
}
</style>

<!-- Top Metric Badges Bar -->
<div class="row g-3 mb-4">
    <!-- Total RAB -->
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 mb-0 chart-card bg-light bg-opacity-50">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted font-size-13 fw-semibold">Target RAB</span>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                        <i class="mdi mdi-file-document-outline me-1"></i> Rencana
                    </span>
                </div>
                <h4 class="mb-1 text-primary fw-bold"><?= formatRupiah($rabTotal) ?></h4>
                <div class="d-flex align-items-center justify-content-between text-muted font-size-12 mt-2">
                    <span>Overhead: <?= floatval($rabOverheadPct) ?>%</span>
                    <span>PPN: <?= floatval($ppnPct) ?>%</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Total RAP -->
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 mb-0 chart-card bg-light bg-opacity-50">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted font-size-13 fw-semibold">Target RAP</span>
                    <span class="badge bg-info-subtle text-info border border-info-subtle">
                        <i class="mdi mdi-file-document-multiple-outline me-1"></i> Pelaksanaan
                    </span>
                </div>
                <h4 class="mb-1 text-info fw-bold"><?= formatRupiah($rapTotal) ?></h4>
                <div class="d-flex align-items-center justify-content-between text-muted font-size-12 mt-2">
                    <span>Efisiensi: <?= ($penghematanRapRab >= 0 ? '+' : '') . formatRupiah($penghematanRapRab) ?></span>
                    <span class="fw-semibold text-<?= $penghematanRapRab >= 0 ? 'success' : 'danger' ?>"><?= $marginRapRabPct ?>%</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Realisasi -->
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 mb-0 chart-card bg-light bg-opacity-50">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted font-size-13 fw-semibold">Total Realisasi</span>
                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                        <i class="mdi mdi-cash-check me-1"></i> Aktual
                    </span>
                </div>
                <h4 class="mb-1 text-success fw-bold"><?= formatRupiah($effectiveActualTotal) ?></h4>
                <div class="d-flex align-items-center justify-content-between text-muted font-size-12 mt-2">
                    <span>Penyerapan Anggaran:</span>
                    <span class="fw-bold <?= $penyerapanPct > 100 ? 'text-danger' : 'text-success' ?>"><?= $penyerapanPct ?>%</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Sisa Anggaran -->
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 mb-0 chart-card bg-light bg-opacity-50">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted font-size-13 fw-semibold">Sisa Anggaran</span>
                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle">
                        <i class="mdi mdi-wallet-outline me-1"></i> RAP - Realisasi
                    </span>
                </div>
                <h4 class="mb-1 <?= $effectiveSisaAnggaran < 0 ? 'text-danger' : 'text-dark' ?> fw-bold">
                    <?= formatRupiah($effectiveSisaAnggaran) ?>
                </h4>
                <div class="d-flex align-items-center justify-content-between text-muted font-size-12 mt-2">
                    <span>Status:</span>
                    <span class="fw-semibold <?= $effectiveSisaAnggaran < 0 ? 'text-danger' : 'text-success' ?>">
                        <?= $effectiveSisaAnggaran < 0 ? 'Over Budget' : 'Aman' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Charts Row 1: Comparison & Composition -->
<div class="row g-3 mb-4">
    <!-- Chart 1: Perbandingan Anggaran & Realisasi -->
    <div class="col-xl-7">
        <div class="card h-100 shadow-sm border-0 chart-card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="card-title mb-1"><i class="mdi mdi-chart-bar me-1 text-primary"></i> Perbandingan Anggaran & Realisasi</h5>
                        <p class="text-muted font-size-12 mb-0">Komparasi nilai RAB, RAP, Realisasi aktual, dan Sisa Anggaran</p>
                    </div>
                    <span class="badge bg-light text-dark font-size-12 border px-2 py-1">
                        <i class="mdi mdi-shield-check text-success me-1"></i> Data Terkini
                    </span>
                </div>
                <div id="chartFinancialComparison" style="min-height: 330px;"></div>
            </div>
        </div>
    </div>

    <!-- Chart 2: Komposisi Realisasi Biaya -->
    <div class="col-xl-5">
        <div class="card h-100 shadow-sm border-0 chart-card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="card-title mb-1"><i class="mdi mdi-chart-donut me-1 text-success"></i> Komposisi Biaya Realisasi</h5>
                        <p class="text-muted font-size-12 mb-0">Proporsi pengeluaran per jenis komponen biaya</p>
                    </div>
                </div>

                <?php if ($totalRealisasiNet <= 0): ?>
                <div class="text-center py-5">
                    <i class="mdi mdi-chart-arc display-4 text-muted opacity-50"></i>
                    <p class="text-muted mt-2 mb-0">Belum ada realisasi biaya yang tercatat untuk proyek ini.</p>
                </div>
                <?php else: ?>
                <div id="chartCostComposition" style="min-height: 250px;"></div>
                
                <!-- Breakdown Table -->
                <div class="table-responsive mt-3">
                    <table class="table table-sm table-borderless mb-0 font-size-13">
                        <tbody>
                            <tr>
                                <td><span class="chart-badge-indicator" style="background-color: #556ee6;"></span> Upah</td>
                                <td class="text-end fw-semibold"><?= formatRupiah($totalActualUpah) ?></td>
                                <td class="text-end text-muted"><?= $totalRealisasiNet > 0 ? number_format(($totalActualUpah / $totalRealisasiNet) * 100, 1) : 0 ?>%</td>
                            </tr>
                            <tr>
                                <td><span class="chart-badge-indicator" style="background-color: #34c38f;"></span> Material</td>
                                <td class="text-end fw-semibold"><?= formatRupiah($totalActualMaterial) ?></td>
                                <td class="text-end text-muted"><?= $totalRealisasiNet > 0 ? number_format(($totalActualMaterial / $totalRealisasiNet) * 100, 1) : 0 ?>%</td>
                            </tr>
                            <tr>
                                <td><span class="chart-badge-indicator" style="background-color: #f1b44c;"></span> Alat</td>
                                <td class="text-end fw-semibold"><?= formatRupiah($totalActualAlat) ?></td>
                                <td class="text-end text-muted"><?= $totalRealisasiNet > 0 ? number_format(($totalActualAlat / $totalRealisasiNet) * 100, 1) : 0 ?>%</td>
                            </tr>
                            <?php if ($totalNonRabActual > 0): ?>
                            <tr>
                                <td><span class="chart-badge-indicator" style="background-color: #e83e8c;"></span> Lain-Lain (Non-RAB)</td>
                                <td class="text-end fw-semibold"><?= formatRupiah($totalNonRabActual) ?></td>
                                <td class="text-end text-muted"><?= $totalRealisasiNet > 0 ? number_format(($totalNonRabActual / $totalRealisasiNet) * 100, 1) : 0 ?>%</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Main Charts Row 2: Category Breakdown & Weekly Trend (S-Curve) -->
<div class="row g-3 mb-4">
    <!-- Chart 3: Realisasi vs Target RAP per Kategori Pekerjaan -->
    <div class="col-xl-6">
        <div class="card h-100 shadow-sm border-0 chart-card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="card-title mb-1"><i class="mdi mdi-format-list-bulleted-type me-1 text-info"></i> Target vs Realisasi per Kategori</h5>
                        <p class="text-muted font-size-12 mb-0">Monitoring penyerapan anggaran per kategori pekerjaan</p>
                    </div>
                </div>

                <?php if (empty($categoryChartLabels)): ?>
                <div class="text-center py-5">
                    <i class="mdi mdi-view-list display-4 text-muted opacity-50"></i>
                    <p class="text-muted mt-2 mb-0">Belum ada kategori RAB pada proyek ini.</p>
                </div>
                <?php else: ?>
                <div id="chartCategoryBreakdown" style="min-height: 350px;"></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Chart 4: Tren Pengeluaran Mingguan & Kumulatif (Kurva S Realisasi) -->
    <div class="col-xl-6">
        <div class="card h-100 shadow-sm border-0 chart-card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="card-title mb-1"><i class="mdi mdi-chart-timeline-variant me-1 text-warning"></i> Tren Pengeluaran Mingguan & Kumulatif</h5>
                        <p class="text-muted font-size-12 mb-0">Kurva realisasi per minggu serta akumulasi pengeluaran</p>
                    </div>
                </div>

                <?php if (empty($trendLabels) || array_sum($trendWeeklyAmount) <= 0): ?>
                <div class="text-center py-5">
                    <i class="mdi mdi-chart-line display-4 text-muted opacity-50"></i>
                    <p class="text-muted mt-2 mb-0">Belum ada pengeluaran mingguan yang disetujui.</p>
                    <small class="text-muted">Kurva akan otomatis terbentuk saat pengajuan mingguan disetujui.</small>
                </div>
                <?php else: ?>
                <div id="chartWeeklyTrend" style="min-height: 350px;"></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Main Charts Row 3: Status Pengajuan & Top 5 Pengeluaran -->
<div class="row g-3">
    <!-- Chart 5: Status Pengajuan Biaya -->
    <div class="col-xl-5">
        <div class="card h-100 shadow-sm border-0 chart-card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="card-title mb-1"><i class="mdi mdi-file-document-edit-outline me-1 text-secondary"></i> Status Pengajuan Dana</h5>
                        <p class="text-muted font-size-12 mb-0">Distribusi pengajuan berdasarkan status persetujuan</p>
                    </div>
                </div>

                <?php if (empty($reqStatusCounts)): ?>
                <div class="text-center py-5">
                    <i class="mdi mdi-file-question-outline display-4 text-muted opacity-50"></i>
                    <p class="text-muted mt-2 mb-0">Belum ada riwayat pengajuan dana pada proyek ini.</p>
                </div>
                <?php else: ?>
                <div id="chartRequestStatus" style="min-height: 260px;"></div>
                <div class="mt-3">
                    <div class="row text-center g-2 font-size-12">
                        <?php foreach ($reqStatusRows as $r): 
                            $st = strtolower(trim($r['status']));
                            $conf = $statusTranslation[$st] ?? ['label' => ucfirst($st), 'color' => '#6c757d'];
                        ?>
                        <div class="col">
                            <div class="p-2 border rounded bg-light">
                                <span class="chart-badge-indicator" style="background-color: <?= $conf['color'] ?>;"></span>
                                <span class="fw-semibold d-block text-truncate" title="<?= $conf['label'] ?>"><?= $conf['label'] ?></span>
                                <span class="fw-bold text-dark font-size-14"><?= $r['cnt'] ?></span>
                                <small class="text-muted d-block font-size-10"><?= formatRupiah(floatval($r['total_nominal'])) ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Top 5 Subkategori Pengeluaran Terbesar -->
    <div class="col-xl-7">
        <div class="card h-100 shadow-sm border-0 chart-card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="card-title mb-1"><i class="mdi mdi-trophy-outline me-1 text-primary"></i> 5 Subkategori Pengeluaran Terbesar</h5>
                        <p class="text-muted font-size-12 mb-0">Item pekerjaan dengan alokasi realisasi dana tertinggi</p>
                    </div>
                </div>

                <?php if (empty($topSpendingItems)): ?>
                <div class="text-center py-5">
                    <i class="mdi mdi-cash-remove display-4 text-muted opacity-50"></i>
                    <p class="text-muted mt-2 mb-0">Belum ada data pengeluaran pekerjaan.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="50">#</th>
                                <th>Subkategori Pekerjaan</th>
                                <th>Kategori</th>
                                <th class="text-end">Total Realisasi</th>
                                <th class="text-center" width="120">Porsi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = 1;
                            foreach ($topSpendingItems as $item): 
                                $spend = floatval($item['total_spending']);
                                $sharePct = $totalRealisasiNet > 0 ? round(($spend / $totalRealisasiNet) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td><span class="badge bg-primary-subtle text-primary fw-bold"><?= $no++ ?></span></td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= sanitize($item['subcategory_name']) ?></div>
                                    <small class="text-muted">Kode: <?= sanitize($item['subcategory_code']) ?></small>
                                </td>
                                <td><span class="badge bg-light text-muted border"><?= sanitize($item['category_name']) ?></span></td>
                                <td class="text-end fw-bold text-dark"><?= formatRupiah($spend) ?></td>
                                <td class="text-center">
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar bg-primary" style="width: <?= min($sharePct, 100) ?>%"></div>
                                    </div>
                                    <small class="text-muted font-size-11"><?= $sharePct ?>%</small>
                                </td>
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

<!-- ApexCharts Library -->
<script src="<?= $baseUrl ?>/dist/assets/libs/apexcharts/apexcharts.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Indonesian Rupiah Formatter Helper
    function formatIDR(val) {
        return 'Rp ' + Number(val || 0).toLocaleString('id-ID');
    }

    // Chart 1: Perbandingan Anggaran & Realisasi (Bar / Column)
    (function() {
        var el = document.querySelector("#chartFinancialComparison");
        if (!el) return;

        var options = {
            series: [{
                name: 'Nilai (Rp)',
                data: [
                    <?= round($rabTotal) ?>,
                    <?= round($rapTotal) ?>,
                    <?= round($effectiveActualTotal) ?>,
                    <?= round($effectiveSisaAnggaran) ?>
                ]
            }],
            chart: {
                type: 'bar',
                height: 330,
                toolbar: { show: false }
            },
            plotOptions: {
                bar: {
                    borderRadius: 6,
                    columnWidth: '45%',
                    distributed: true,
                    dataLabels: {
                        position: 'top'
                    }
                }
            },
            colors: ['#556ee6', '#50a5f1', '#34c38f', '<?= $effectiveSisaAnggaran < 0 ? "#f46a6a" : "#f1b44c" ?>'],
            dataLabels: {
                enabled: true,
                formatter: function(val) {
                    if (val >= 1000000000) {
                        return (val / 1000000000).toFixed(2) + ' M';
                    } else if (val >= 1000000) {
                        return (val / 1000000).toFixed(1) + ' Jt';
                    }
                    return val.toLocaleString('id-ID');
                },
                offsetY: -22,
                style: {
                    fontSize: '11px',
                    fontWeight: 600,
                    colors: ['#304758']
                }
            },
            legend: { show: false },
            xaxis: {
                categories: ['Target RAB', 'Target RAP', 'Total Realisasi', 'Sisa Anggaran'],
                labels: {
                    style: {
                        fontSize: '12px',
                        fontWeight: 600
                    }
                }
            },
            yaxis: {
                labels: {
                    formatter: function(val) {
                        if (val >= 1000000000) {
                            return (val / 1000000000).toFixed(1) + ' M';
                        } else if (val >= 1000000) {
                            return (val / 1000000).toFixed(0) + ' Jt';
                        }
                        return val.toLocaleString('id-ID');
                    }
                }
            },
            tooltip: {
                y: {
                    formatter: function(val) {
                        return formatIDR(val);
                    }
                }
            },
            grid: {
                borderColor: '#f1f1f1',
                padding: { top: 15 }
            }
        };

        var chart = new ApexCharts(el, options);
        chart.render();
    })();

    // Chart 2: Komposisi Biaya Realisasi (Donut)
    <?php if ($totalRealisasiNet > 0): ?>
    (function() {
        var el = document.querySelector("#chartCostComposition");
        if (!el) return;

        var labels = ['Upah', 'Material', 'Alat'<?= $totalNonRabActual > 0 ? ", 'Lain-Lain (Non-RAB)'" : "" ?>];
        var series = [
            <?= round($totalActualUpah) ?>,
            <?= round($totalActualMaterial) ?>,
            <?= round($totalActualAlat) ?>
            <?= $totalNonRabActual > 0 ? ", " . round($totalNonRabActual) : "" ?>
        ];
        var colors = ['#556ee6', '#34c38f', '#f1b44c'<?= $totalNonRabActual > 0 ? ", '#e83e8c'" : "" ?>];

        var options = {
            series: series,
            labels: labels,
            chart: {
                type: 'donut',
                height: 250
            },
            colors: colors,
            legend: {
                position: 'bottom',
                horizontalAlign: 'center'
            },
            dataLabels: {
                enabled: true,
                formatter: function(val) {
                    return val.toFixed(1) + '%';
                }
            },
            plotOptions: {
                pie: {
                    donut: {
                        size: '65%',
                        labels: {
                            show: true,
                            total: {
                                show: true,
                                label: 'Total Biaya',
                                formatter: function(w) {
                                    return formatIDR(<?= round($totalRealisasiNet) ?>);
                                }
                            }
                        }
                    }
                }
            },
            tooltip: {
                y: {
                    formatter: function(val) {
                        return formatIDR(val);
                    }
                }
            }
        };

        var chart = new ApexCharts(el, options);
        chart.render();
    })();
    <?php endif; ?>

    // Chart 3: Realisasi vs Target RAP per Kategori (Grouped Column)
    <?php if (!empty($categoryChartLabels)): ?>
    (function() {
        var el = document.querySelector("#chartCategoryBreakdown");
        if (!el) return;

        var options = {
            series: [
                {
                    name: 'Target RAP',
                    data: <?= json_encode($categoryChartRap) ?>
                },
                {
                    name: 'Realisasi',
                    data: <?= json_encode($categoryChartActual) ?>
                }
            ],
            chart: {
                type: 'bar',
                height: 350,
                toolbar: { show: false }
            },
            colors: ['#50a5f1', '#34c38f'],
            plotOptions: {
                bar: {
                    horizontal: false,
                    columnWidth: '55%',
                    borderRadius: 4
                }
            },
            dataLabels: { enabled: false },
            stroke: {
                show: true,
                width: 2,
                colors: ['transparent']
            },
            xaxis: {
                categories: <?= json_encode($categoryChartLabels) ?>,
                labels: {
                    rotate: -30,
                    trim: true,
                    style: { fontSize: '11px' }
                }
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
            legend: {
                position: 'top',
                horizontalAlign: 'right'
            },
            tooltip: {
                y: {
                    formatter: function(val) {
                        return formatIDR(val);
                    }
                }
            },
            grid: {
                borderColor: '#f1f1f1'
            }
        };

        var chart = new ApexCharts(el, options);
        chart.render();
    })();
    <?php endif; ?>

    // Chart 4: Tren Pengeluaran Mingguan & Kumulatif (Line / Area)
    <?php if (!empty($trendLabels) && array_sum($trendWeeklyAmount) > 0): ?>
    (function() {
        var el = document.querySelector("#chartWeeklyTrend");
        if (!el) return;

        var options = {
            series: [
                {
                    name: 'Pengeluaran Mingguan',
                    type: 'column',
                    data: <?= json_encode($trendWeeklyAmount) ?>
                },
                {
                    name: 'Akumulasi Pengeluaran',
                    type: 'line',
                    data: <?= json_encode($trendCumulativeAmount) ?>
                }
            ],
            chart: {
                height: 350,
                type: 'line',
                toolbar: { show: false }
            },
            stroke: {
                width: [0, 3],
                curve: 'smooth'
            },
            colors: ['#556ee6', '#f1b44c'],
            plotOptions: {
                bar: {
                    columnWidth: '40%',
                    borderRadius: 4
                }
            },
            xaxis: {
                categories: <?= json_encode($trendLabels) ?>
            },
            yaxis: [
                {
                    title: { text: 'Mingguan (Rp)' },
                    labels: {
                        formatter: function(val) {
                            if (val >= 1000000000) return (val / 1000000000).toFixed(1) + ' M';
                            if (val >= 1000000) return (val / 1000000).toFixed(0) + ' Jt';
                            return val.toLocaleString('id-ID');
                        }
                    }
                },
                {
                    opposite: true,
                    title: { text: 'Kumulatif (Rp)' },
                    labels: {
                        formatter: function(val) {
                            if (val >= 1000000000) return (val / 1000000000).toFixed(1) + ' M';
                            if (val >= 1000000) return (val / 1000000).toFixed(0) + ' Jt';
                            return val.toLocaleString('id-ID');
                        }
                    }
                }
            ],
            legend: {
                position: 'top',
                horizontalAlign: 'right'
            },
            tooltip: {
                y: {
                    formatter: function(val) {
                        return formatIDR(val);
                    }
                }
            },
            grid: {
                borderColor: '#f1f1f1'
            }
        };

        var chart = new ApexCharts(el, options);
        chart.render();
    })();
    <?php endif; ?>

    // Chart 5: Status Pengajuan Dana (Donut)
    <?php if (!empty($reqStatusCounts)): ?>
    (function() {
        var el = document.querySelector("#chartRequestStatus");
        if (!el) return;

        var options = {
            series: <?= json_encode($reqStatusCounts) ?>,
            labels: <?= json_encode($reqStatusLabels) ?>,
            colors: <?= json_encode($reqStatusColors) ?>,
            chart: {
                type: 'donut',
                height: 260
            },
            legend: {
                position: 'bottom'
            },
            plotOptions: {
                pie: {
                    donut: {
                        size: '60%',
                        labels: {
                            show: true,
                            total: {
                                show: true,
                                label: 'Total Request',
                                formatter: function(w) {
                                    return <?= array_sum($reqStatusCounts) ?>;
                                }
                            }
                        }
                    }
                }
            },
            tooltip: {
                y: {
                    formatter: function(val, opts) {
                        var nominals = <?= json_encode($reqStatusNominals) ?>;
                        var idx = opts.seriesIndex;
                        var nom = nominals[idx] || 0;
                        return val + ' pengajuan (' + formatIDR(nom) + ')';
                    }
                }
            }
        };

        var chart = new ApexCharts(el, options);
        chart.render();
    })();
    <?php endif; ?>
});
</script>
