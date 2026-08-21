<?php
/**
 * Export Laporan Proyek (CSV & PDF)
 * PCM - Project Cost Management System
 * Fitur Export Fleksibel: Seluruh Proyek atau Per Proyek, dengan Filter Periode Tanggal
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requirePermission('reports.export');

$projectId = $_GET['project_id'] ?? 'all';
$type = $_GET['type'] ?? 'rekap'; // rekap, transactions, comparison, rab
$startDate = !empty($_GET['start_date']) ? trim($_GET['start_date']) : null;
$endDate = !empty($_GET['end_date']) ? trim($_GET['end_date']) : null;
$format = $_GET['format'] ?? (isset($_GET['download']) ? 'csv' : '');

// Periode Label Text
if ($startDate && $endDate) {
    $periodeLabel = formatDate($startDate) . ' s/d ' . formatDate($endDate);
} elseif ($startDate) {
    $periodeLabel = 'Mulai ' . formatDate($startDate);
} elseif ($endDate) {
    $periodeLabel = 'Sampai ' . formatDate($endDate);
} else {
    $periodeLabel = 'Semua Periode';
}

// Get accessible projects based on view mode
$repViewMode = getProjectViewMode();
if ($repViewMode === 'all') {
    $accessibleProjects = dbGetAll("
        SELECT p.* 
        FROM projects p 
        WHERE p.status != 'draft' 
        ORDER BY p.name
    ");
} elseif ($repViewMode === 'assigned') {
    $accessibleProjects = dbGetAll("
        SELECT p.* 
        FROM projects p 
        JOIN project_assignments pa ON pa.project_id = p.id 
        WHERE p.status != 'draft' AND pa.user_id = ? AND pa.is_active = 1 
        ORDER BY p.name
    ", [getCurrentUserId()]);
} else {
    $accessibleProjects = [];
}

$accessibleProjectIds = array_column($accessibleProjects, 'id');
$accessibleProjectsMap = [];
foreach ($accessibleProjects as $p) {
    $accessibleProjectsMap[$p['id']] = $p;
}

// Determine target projects
$isAllProjects = ($projectId === 'all' || empty($projectId));
$targetProjects = [];

if ($isAllProjects) {
    $targetProjects = $accessibleProjects;
    $selectedProject = null;
} else {
    $pId = intval($projectId);
    if (!in_array($pId, $accessibleProjectIds)) {
        die('Anda tidak memiliki akses ke proyek ini.');
    }
    $selectedProject = $accessibleProjectsMap[$pId] ?? dbGetRow("SELECT * FROM projects WHERE id = ?", [$pId]);
    if (!$selectedProject) {
        die('Proyek tidak ditemukan.');
    }
    $targetProjects = [$selectedProject];
}

// =========================================================================
// 1. HANDLER: EXPORT TO CSV
// =========================================================================
if ($format === 'csv') {
    $cleanProjName = $isAllProjects ? 'SEMUA_PROYEK' : preg_replace('/[^a-zA-Z0-9_]/', '_', $selectedProject['name']);
    $dateStamp = date('Ymd_His');
    $filename = "LAPORAN_{$type}_{$cleanProjName}_{$dateStamp}.csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    // UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    $delimiter = ';'; // Standard for Excel in Indonesian/European locale

    if ($type === 'rekap') {
        // -------------------------------------------------------------
        // REKAPITULASI SUMMARY CSV
        // -------------------------------------------------------------
        fputcsv($output, ['LAPORAN REKAPITULASI BIAYA & REALISASI PROYEK'], $delimiter);
        fputcsv($output, ['PCM - Project Cost Management System'], $delimiter);
        fputcsv($output, ['Cakupan Proyek', ':', $isAllProjects ? 'Semua Proyek' : $selectedProject['name']], $delimiter);
        fputcsv($output, ['Periode', ':', $periodeLabel], $delimiter);
        fputcsv($output, ['Tanggal Cetak', ':', date('d-m-Y H:i') . ' WIB'], $delimiter);
        fputcsv($output, ['Dicetak Oleh', ':', sanitize($_SESSION['full_name'] ?? 'User')], $delimiter);
        fputcsv($output, [], $delimiter);

        if ($isAllProjects) {
            fputcsv($output, [
                'No', 'Nama Proyek', 'Status', 'Wilayah', 
                'Total RAB (Kontrak)', 'Total RAP (Budget)', 'Realisasi (Aktual)', 
                'Sisa Budget RAP', 'Margin Potensial', '% Pemakaian RAP'
            ], $delimiter);

            $no = 1;
            $grandRab = 0; $grandRap = 0; $grandActual = 0;

            foreach ($targetProjects as $proj) {
                $stats = calculateProjectRealtimeStats($proj['id']);
                $rab = floatval($stats['total_rab'] ?? 0);
                $rap = floatval($stats['total_rap'] ?? 0);
                
                // If period filter is active, calculate actual spending within period
                if ($startDate || $endDate) {
                    $actual = getProjectPeriodActualSpending($proj['id'], $startDate, $endDate);
                } else {
                    $actual = floatval($stats['total_actual'] ?? 0);
                }
                
                $sisaRap = $rap - $actual;
                $margin = $rab - $actual;
                $pct = $rap > 0 ? ($actual / $rap) * 100 : 0;

                $grandRab += $rab;
                $grandRap += $rap;
                $grandActual += $actual;

                $statusLabel = $proj['status'] === 'on_progress' ? 'Berjalan' : ($proj['status'] === 'completed' ? 'Selesai' : ucfirst($proj['status']));

                fputcsv($output, [
                    $no++,
                    $proj['name'],
                    $statusLabel,
                    $proj['region_name'] ?? '-',
                    number_format($rab, 2, ',', '.'),
                    number_format($rap, 2, ',', '.'),
                    number_format($actual, 2, ',', '.'),
                    number_format($sisaRap, 2, ',', '.'),
                    number_format($margin, 2, ',', '.'),
                    number_format($pct, 2, ',', '.') . '%'
                ], $delimiter);
            }

            $grandSisa = $grandRap - $grandActual;
            $grandMargin = $grandRab - $grandActual;
            $grandPct = $grandRap > 0 ? ($grandActual / $grandRap) * 100 : 0;

            fputcsv($output, [], $delimiter);
            fputcsv($output, [
                'TOTAL', '', '', '',
                number_format($grandRab, 2, ',', '.'),
                number_format($grandRap, 2, ',', '.'),
                number_format($grandActual, 2, ',', '.'),
                number_format($grandSisa, 2, ',', '.'),
                number_format($grandMargin, 2, ',', '.'),
                number_format($grandPct, 2, ',', '.') . '%'
            ], $delimiter);

        } else {
            // Rekap Per Kategori untuk 1 Proyek
            $stats = calculateProjectRealtimeStats($selectedProject['id']);
            $catStats = $stats['category_stats'] ?? [];

            fputcsv($output, ['No', 'Kode Kategori', 'Nama Kategori Pekerjaan', 'Total RAB (Rp)', 'Total RAP (Rp)', 'Realisasi / Aktual (Rp)', 'Sisa Budget RAP (Rp)', 'Margin (Rp)'], $delimiter);

            $no = 1;
            foreach ($catStats as $cat) {
                $cRab = floatval($cat['rab_total']);
                $cRap = floatval($cat['rap_total']);
                $cAct = floatval($cat['actual_total']);
                $cSisa = $cRap - $cAct;
                $cMargin = $cRab - $cAct;

                fputcsv($output, [
                    $no++,
                    $cat['code'],
                    $cat['name'],
                    number_format($cRab, 2, ',', '.'),
                    number_format($cRap, 2, ',', '.'),
                    number_format($cAct, 2, ',', '.'),
                    number_format($cSisa, 2, ',', '.'),
                    number_format($cMargin, 2, ',', '.')
                ], $delimiter);
            }

            fputcsv($output, [], $delimiter);
            fputcsv($output, [
                'SUBTOTAL', '', '',
                number_format($stats['subtotal_rab'] ?? 0, 2, ',', '.'),
                number_format($stats['total_rap'] ?? 0, 2, ',', '.'),
                number_format($stats['total_actual'] ?? 0, 2, ',', '.'),
                number_format(($stats['total_rap'] ?? 0) - ($stats['total_actual'] ?? 0), 2, ',', '.'),
                number_format(($stats['subtotal_rab'] ?? 0) - ($stats['total_actual'] ?? 0), 2, ',', '.')
            ], $delimiter);

            if (!empty($stats['ppn_amount'])) {
                fputcsv($output, ['PPN', '', '', number_format($stats['ppn_amount'], 2, ',', '.'), '', '', '', ''], $delimiter);
                fputcsv($output, ['TOTAL RAB DIBULATKAN', '', '', number_format($stats['total_rab'], 2, ',', '.'), '', '', '', ''], $delimiter);
            }
        }

    } elseif ($type === 'transactions') {
        // -------------------------------------------------------------
        // DETAIL REALISASI & TRANSAKSI PENGAJUAN CSV
        // -------------------------------------------------------------
        fputcsv($output, ['LAPORAN DETAIL REALISASI & PENGELUARAN DANA'], $delimiter);
        fputcsv($output, ['PCM - Project Cost Management System'], $delimiter);
        fputcsv($output, ['Cakupan Proyek', ':', $isAllProjects ? 'Semua Proyek' : $selectedProject['name']], $delimiter);
        fputcsv($output, ['Periode Transaksi', ':', $periodeLabel], $delimiter);
        fputcsv($output, ['Tanggal Cetak', ':', date('d-m-Y H:i') . ' WIB'], $delimiter);
        fputcsv($output, ['Dicetak Oleh', ':', sanitize($_SESSION['full_name'] ?? 'User')], $delimiter);
        fputcsv($output, [], $delimiter);

        fputcsv($output, [
            'No', 'Tanggal Pengajuan', 'No. Pengajuan', 'Nama Proyek', 'Minggu Ke',
            'Kode Pekerjaan', 'Kategori / Pekerjaan', 'Kode Item', 'Uraian Pekerjaan / Item',
            'Satuan', 'Koefisien', 'Harga Satuan (Rp)', 'Total Biaya (Rp)', 'Pemohon', 'Catatan'
        ], $delimiter);

        $transactions = fetchProjectTransactions($accessibleProjectIds, $projectId, $startDate, $endDate);

        $no = 1;
        $totalNominal = 0;
        foreach ($transactions as $t) {
            $nominal = floatval($t['total_price']);
            $totalNominal += $nominal;

            fputcsv($output, [
                $no++,
                date('d-m-Y', strtotime($t['created_at'])),
                $t['request_number'],
                $t['project_name'],
                $t['target_week'] ?? $t['week_number'] ?? '-',
                $t['subcategory_code'] ?? '-',
                $t['subcategory_name'] ?? '-',
                $t['item_code'] ?? '-',
                $t['item_name'],
                $t['unit'],
                number_format(floatval($t['coefficient']), 4, ',', '.'),
                number_format(floatval($t['unit_price']), 2, ',', '.'),
                number_format($nominal, 2, ',', '.'),
                $t['created_by_name'] ?? '-',
                $t['notes'] ?? ''
            ], $delimiter);
        }

        fputcsv($output, [], $delimiter);
        fputcsv($output, [
            '', '', '', '', '', '', '', '', '', '',
            'TOTAL PENGELUARAN REALISASI', '',
            number_format($totalNominal, 2, ',', '.'), '', ''
        ], $delimiter);

    } elseif ($type === 'comparison') {
        // -------------------------------------------------------------
        // PERBANDINGAN RAB vs RAP vs REALISASI CSV
        // -------------------------------------------------------------
        fputcsv($output, ['LAPORAN PERBANDINGAN RAB vs RAP vs REALISASI'], $delimiter);
        fputcsv($output, ['Cakupan Proyek', ':', $isAllProjects ? 'Semua Proyek' : $selectedProject['name']], $delimiter);
        fputcsv($output, ['Periode', ':', $periodeLabel], $delimiter);
        fputcsv($output, ['Tanggal Cetak', ':', date('d-m-Y H:i') . ' WIB'], $delimiter);
        fputcsv($output, [], $delimiter);

        fputcsv($output, [
            'Proyek', 'Kode', 'Uraian Pekerjaan', 'Satuan',
            'Vol RAB', 'Harga RAB (Rp)', 'Total RAB (Rp)',
            'Vol RAP', 'Harga RAP (Rp)', 'Total RAP (Rp)',
            'Total Realisasi (Rp)', 'Selisih RAB-Aktual (Rp)', '% Realisasi/RAP'
        ], $delimiter);

        $grandRab = 0; $grandRap = 0; $grandAct = 0;

        foreach ($targetProjects as $proj) {
            $comparisonData = fetchProjectComparisonData($proj['id'], $startDate, $endDate);
            
            foreach ($comparisonData['items'] as $item) {
                $grandRab += $item['rab_total'];
                $grandRap += $item['rap_total'];
                $grandAct += $item['act_total'];
                $pct = $item['rap_total'] > 0 ? ($item['act_total'] / $item['rap_total']) * 100 : 0;

                fputcsv($output, [
                    $proj['name'],
                    $item['code'],
                    $item['name'],
                    $item['unit'],
                    number_format($item['rab_vol'], 2, ',', '.'),
                    number_format($item['rab_price'], 2, ',', '.'),
                    number_format($item['rab_total'], 2, ',', '.'),
                    number_format($item['rap_vol'], 2, ',', '.'),
                    number_format($item['rap_price'], 2, ',', '.'),
                    number_format($item['rap_total'], 2, ',', '.'),
                    number_format($item['act_total'], 2, ',', '.'),
                    number_format($item['rab_total'] - $item['act_total'], 2, ',', '.'),
                    number_format($pct, 1, ',', '.') . '%'
                ], $delimiter);
            }
        }

        fputcsv($output, [], $delimiter);
        fputcsv($output, [
            'TOTAL', '', '', '', '', '',
            number_format($grandRab, 2, ',', '.'), '', '',
            number_format($grandRap, 2, ',', '.'),
            number_format($grandAct, 2, ',', '.'),
            number_format($grandRab - $grandAct, 2, ',', '.'),
            ($grandRap > 0 ? number_format(($grandAct / $grandRap) * 100, 1, ',', '.') . '%' : '0%')
        ], $delimiter);

    } elseif ($type === 'rab' && !$isAllProjects) {
        // -------------------------------------------------------------
        // DETAIL RAB PROYEK CSV
        // -------------------------------------------------------------
        fputcsv($output, ['RENCANA ANGGARAN BIAYA (RAB)'], $delimiter);
        fputcsv($output, ['Proyek', ':', $selectedProject['name']], $delimiter);
        fputcsv($output, ['Wilayah', ':', $selectedProject['region_name'] ?? '-'], $delimiter);
        fputcsv($output, ['Tanggal Cetak', ':', date('d-m-Y H:i') . ' WIB'], $delimiter);
        fputcsv($output, [], $delimiter);

        fputcsv($output, ['Kode', 'Uraian Pekerjaan', 'Satuan', 'Volume', 'Harga Satuan (Rp)', 'Jumlah Harga (Rp)'], $delimiter);

        $overheadPct = getProjectOverheadProfitPct($selectedProject);
        $ppnPct = floatval($selectedProject['ppn_percentage'] ?? 11);
        $categories = dbGetAll("SELECT * FROM rab_categories WHERE project_id = ? ORDER BY sort_order, code", [$selectedProject['id']]);
        
        $subtotalRab = 0;
        foreach ($categories as $cat) {
            fputcsv($output, [$cat['code'], strtoupper($cat['name']), '', '', '', ''], $delimiter);
            $subcats = dbGetAll("SELECT * FROM rab_subcategories WHERE category_id = ? ORDER BY sort_order, code", [$cat['id']]);
            $catTotal = 0;
            foreach ($subcats as $sub) {
                $basePrice = floatval($sub['unit_price']);
                $unitPriceWithOverhead = $basePrice * (1 + ($overheadPct / 100));
                $total = floatval($sub['volume']) * $unitPriceWithOverhead;
                $catTotal += $total;

                fputcsv($output, [
                    $sub['code'],
                    $sub['name'],
                    $sub['unit'],
                    number_format(floatval($sub['volume']), 2, ',', '.'),
                    number_format($unitPriceWithOverhead, 2, ',', '.'),
                    number_format($total, 2, ',', '.')
                ], $delimiter);
            }
            fputcsv($output, ['', '', '', '', 'Jumlah ' . $cat['code'], number_format($catTotal, 2, ',', '.')], $delimiter);
            $subtotalRab += $catTotal;
        }

        $ppnAmount = $subtotalRab * ($ppnPct / 100);
        $totalWithPpn = $subtotalRab + $ppnAmount;
        $totalRounded = ceil($totalWithPpn / 10) * 10;

        fputcsv($output, [], $delimiter);
        fputcsv($output, ['', '', '', '', 'SUBTOTAL RAB', number_format($subtotalRab, 2, ',', '.')], $delimiter);
        fputcsv($output, ['', '', '', '', 'PPN ' . number_format($ppnPct, 2, ',', '.') . '%', number_format($ppnAmount, 2, ',', '.')], $delimiter);
        fputcsv($output, ['', '', '', '', 'TOTAL RAB (TERMASUK PPN)', number_format($totalWithPpn, 2, ',', '.')], $delimiter);
        fputcsv($output, ['', '', '', '', 'TOTAL RAB (DIBULATKAN)', number_format($totalRounded, 0, ',', '.')], $delimiter);
    }

    fclose($output);
    exit;
}

// =========================================================================
// 2. HANDLER: EXPORT TO PDF / PRINT VIEW
// =========================================================================
if ($format === 'pdf' || $format === 'print') {
    include __DIR__ . '/export_pdf_view.php';
    exit;
}

// =========================================================================
// 3. HANDLER: INTERACTIVE WEB FORM (DEDICATED EXPORT PAGE)
// =========================================================================
$pageTitle = 'Export Laporan Proyek';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Title -->
<div class="row">
    <div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
            <h4 class="mb-sm-0">Export Laporan Proyek</h4>
            <div class="page-title-right">
                <ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>">PCM</a></li>
                    <li class="breadcrumb-item"><a href="dashboard.php">Laporan</a></li>
                    <li class="breadcrumb-item active">Export</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-8 mx-auto">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-primary text-white py-3">
                <h5 class="card-title mb-0 text-white"><i class="mdi mdi-file-export-outline me-2"></i> Konfigurasi Export Laporan</h5>
            </div>
            <div class="card-body p-4">
                <form method="GET" action="export.php" id="exportForm" target="_blank">
                    
                    <!-- Pilihan Proyek -->
                    <div class="mb-4">
                        <label class="form-label fw-bold text-dark"><i class="mdi mdi-office-building me-1 text-primary"></i> Cakupan Proyek</label>
                        <select class="form-select select2" name="project_id" id="projectSelect" onchange="toggleReportOptions()">
                            <option value="all" <?= $projectId === 'all' ? 'selected' : '' ?>>Semua Proyek (Rekapitulasi Seluruh Proyek)</option>
                            <optgroup label="Pilih Proyek Spesifik">
                                <?php foreach ($accessibleProjects as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= $projectId == $p['id'] ? 'selected' : '' ?>>
                                    <?= sanitize($p['name']) ?> (<?= $p['status'] === 'on_progress' ? 'Berjalan' : 'Selesai' ?>)
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                        <small class="text-muted">Pilih "Semua Proyek" untuk melihat laporan kompilasi atau pilih salah satu proyek spesifik.</small>
                    </div>

                    <!-- Filter Periode Tanggal -->
                    <div class="mb-4 bg-light p-3 rounded border">
                        <label class="form-label fw-bold text-dark mb-2"><i class="mdi mdi-calendar-range me-1 text-primary"></i> Filter Periode Waktu</label>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Dari Tanggal</label>
                                <input type="date" class="form-control" name="start_date" id="startDate" value="<?= htmlspecialchars($startDate ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Sampai Tanggal</label>
                                <input type="date" class="form-control" name="end_date" id="endDate" value="<?= htmlspecialchars($endDate ?? '') ?>">
                            </div>
                        </div>
                        
                        <!-- Quick Presets -->
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2" onclick="setPeriodPreset('all')">Semua Waktu</button>
                            <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2" onclick="setPeriodPreset('this_month')">Bulan Ini</button>
                            <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2" onclick="setPeriodPreset('last_month')">Bulan Lalu</button>
                            <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2" onclick="setPeriodPreset('this_year')">Tahun Ini</button>
                        </div>
                        <small class="text-muted d-block mt-2 font-italic"><i class="mdi mdi-information-outline"></i> Kosongkan kedua tanggal untuk mengekspor keseluruhan periode proyek tanpa batas waktu.</small>
                    </div>

                    <!-- Tipe Laporan -->
                    <div class="mb-4">
                        <label class="form-label fw-bold text-dark"><i class="mdi mdi-format-list-checks me-1 text-primary"></i> Jenis / Format Laporan</label>
                        <div class="list-group">
                            <label class="list-group-item d-flex align-items-center cursor-pointer">
                                <input class="form-check-input me-3" type="radio" name="type" value="rekap" checked>
                                <div>
                                    <div class="fw-bold text-dark">Rekapitulasi Ringkasan Proyek (Summary)</div>
                                    <small class="text-muted">Ringkasan total Kontrak (RAB), Anggaran (RAP), Realisasi Pengeluaran, Sisa Budget, Margin, dan % Pemakaian.</small>
                                </div>
                            </label>

                            <label class="list-group-item d-flex align-items-center cursor-pointer">
                                <input class="form-check-input me-3" type="radio" name="type" value="transactions">
                                <div>
                                    <div class="fw-bold text-dark">Detail Realisasi & Transaksi Pengeluaran</div>
                                    <small class="text-muted">Daftar rinci seluruh item pekerjaan yang diajukan dan disetujui (koefisien, harga satuan, total nominal, nomor request, pemohon) dalam periode.</small>
                                </div>
                            </label>

                            <label class="list-group-item d-flex align-items-center cursor-pointer">
                                <input class="form-check-input me-3" type="radio" name="type" value="comparison">
                                <div>
                                    <div class="fw-bold text-dark">Perbandingan RAB vs RAP vs Realisasi</div>
                                    <small class="text-muted">Tabel analisa komparasi terperinci per subkategori pekerjaan antara kontrak RAB, rencana RAP, dan realisasi aktual.</small>
                                </div>
                            </label>

                            <label class="list-group-item d-flex align-items-center cursor-pointer" id="rabOptionLabel">
                                <input class="form-check-input me-3" type="radio" name="type" value="rab" id="rabOption">
                                <div>
                                    <div class="fw-bold text-dark">Rencana Anggaran Biaya (RAB Kontrak)</div>
                                    <small class="text-muted">Rincian lengkap item RAB dan harga satuan kontrak (hanya berlaku per 1 proyek terpilih).</small>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center pt-3 border-top">
                        <a href="dashboard.php" class="btn btn-secondary">
                            <i class="mdi mdi-arrow-left"></i> Kembali ke Dashboard
                        </a>
                        <div class="d-flex gap-2">
                            <!-- Download CSV Button -->
                            <button type="submit" name="format" value="csv" class="btn btn-success px-3">
                                <i class="mdi mdi-file-excel me-1"></i> Download Excel / CSV
                            </button>
                            <!-- Print / PDF Button -->
                            <button type="submit" name="format" value="pdf" class="btn btn-danger px-3">
                                <i class="mdi mdi-file-pdf-box me-1"></i> Cetak / PDF
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function toggleReportOptions() {
    const proj = document.getElementById('projectSelect').value;
    const rabOptionLabel = document.getElementById('rabOptionLabel');
    const rabOption = document.getElementById('rabOption');
    
    if (proj === 'all') {
        rabOptionLabel.style.opacity = '0.5';
        if (rabOption.checked) {
            document.querySelector('input[name="type"][value="rekap"]').checked = true;
        }
        rabOption.disabled = true;
    } else {
        rabOptionLabel.style.opacity = '1';
        rabOption.disabled = false;
    }
}

function setPeriodPreset(preset) {
    const startInput = document.getElementById('startDate');
    const endInput = document.getElementById('endDate');
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
    toggleReportOptions();
});
</script>

<?php 
require_once __DIR__ . '/../../includes/footer.php'; 

// =========================================================================
// HELPER FUNCTIONS FOR REPORT EXPORT
// =========================================================================

/**
 * Calculate actual spending in a given period for a project
 */
function getProjectPeriodActualSpending($projectId, $startDate = null, $endDate = null) {
    $where = "req.project_id = ? AND req.status = 'approved'";
    $params = [$projectId];
    
    if ($startDate) {
        $where .= " AND DATE(req.created_at) >= ?";
        $params[] = $startDate;
    }
    if ($endDate) {
        $where .= " AND DATE(req.created_at) <= ?";
        $params[] = $endDate;
    }
    
    $row = dbGetRow("
        SELECT COALESCE(SUM(reqi.total_price), 0) as total
        FROM request_items reqi
        JOIN requests req ON reqi.request_id = req.id
        WHERE $where
    ", $params);
    
    return floatval($row['total'] ?? 0);
}

/**
 * Fetch detailed transactions for export
 */
function fetchProjectTransactions($accessibleProjectIds, $projectId = 'all', $startDate = null, $endDate = null) {
    if (empty($accessibleProjectIds)) return [];
    
    $where = "req.status = 'approved'";
    $params = [];
    
    if ($projectId === 'all' || empty($projectId)) {
        $placeholders = implode(',', array_fill(0, count($accessibleProjectIds), '?'));
        $where .= " AND req.project_id IN ($placeholders)";
        $params = array_merge($params, $accessibleProjectIds);
    } else {
        $where .= " AND req.project_id = ?";
        $params[] = intval($projectId);
    }
    
    if ($startDate) {
        $where .= " AND DATE(req.created_at) >= ?";
        $params[] = $startDate;
    }
    if ($endDate) {
        $where .= " AND DATE(req.created_at) <= ?";
        $params[] = $endDate;
    }
    
    return dbGetAll("
        SELECT req.id as request_id, req.request_number, req.created_at, req.target_week, req.week_number,
               p.id as project_id, p.name as project_name,
               u.full_name as created_by_name,
               reqi.item_name, reqi.item_code, reqi.unit, reqi.coefficient, reqi.unit_price, reqi.total_price, reqi.notes,
               rs.code as subcategory_code, rs.name as subcategory_name
        FROM request_items reqi
        JOIN requests req ON reqi.request_id = req.id
        JOIN projects p ON req.project_id = p.id
        LEFT JOIN rab_subcategories rs ON reqi.subcategory_id = rs.id
        LEFT JOIN users u ON req.created_by = u.id
        WHERE $where
        ORDER BY req.created_at DESC, p.name, req.id DESC
    ", $params);
}

/**
 * Fetch Comparison data for a project
 */
function fetchProjectComparisonData($projectId, $startDate = null, $endDate = null) {
    $project = dbGetRow("SELECT * FROM projects WHERE id = ?", [$projectId]);
    if (!$project) return ['items' => []];

    $overheadPct = getProjectOverheadProfitPct($project);

    // Pre-calculate RAB AHSP prices
    $ahspPrices = [];
    $ahspRows = dbGetAll("
        SELECT d.ahsp_id, SUM(d.coefficient * COALESCE(d.unit_price, i.price)) as total 
        FROM project_ahsp_details d 
        JOIN project_items i ON d.item_id = i.id 
        JOIN project_ahsp pa ON d.ahsp_id = pa.id
        WHERE pa.project_id = ?
        GROUP BY d.ahsp_id
    ", [$projectId]);
    foreach ($ahspRows as $r) {
        $ahspPrices[$r['ahsp_id']] = floatval($r['total']);
    }

    // Pre-calculate RAP AHSP component totals
    $ahspRapPrices = [];
    $ahspRapRows = dbGetAll("
        SELECT pa.ahsp_code, SUM(d.coefficient * COALESCE(d.unit_price, i.price)) as total 
        FROM project_ahsp_details_rap d 
        JOIN project_items_rap i ON d.item_id = i.id 
        JOIN project_ahsp_rap pa ON d.ahsp_id = pa.id
        WHERE pa.project_id = ?
        GROUP BY pa.ahsp_code
    ", [$projectId]);
    foreach ($ahspRapRows as $r) {
        $ahspRapPrices[$r['ahsp_code']] = floatval($r['total']);
    }

    // Subcategories
    $items = dbGetAll("
        SELECT rs.id, rs.code, rs.name, rs.unit, rs.volume as rab_vol, rs.unit_price as rab_price, rs.ahsp_id,
               rap.volume as rap_vol, rap.unit_price as rap_price,
               pa.ahsp_code,
               rc.code as cat_code, rc.name as cat_name
        FROM rab_subcategories rs
        JOIN rab_categories rc ON rs.category_id = rc.id
        LEFT JOIN rap_items rap ON rap.subcategory_id = rs.id
        LEFT JOIN project_ahsp pa ON rs.ahsp_id = pa.id
        WHERE rc.project_id = ?
        ORDER BY rc.sort_order, rc.code, rs.sort_order, rs.code
    ", [$projectId]);

    $outItems = [];
    foreach ($items as $item) {
        $rabBasePrice = isset($ahspPrices[$item['ahsp_id']]) ? $ahspPrices[$item['ahsp_id']] : floatval($item['rab_price']);
        $rabPriceWithOverhead = $rabBasePrice * (1 + ($overheadPct / 100));
        $rabVol = floatval($item['rab_vol']);
        $rabTotal = $rabVol * $rabPriceWithOverhead;

        $rapVol = (isset($item['rap_vol']) && $item['rap_vol'] !== null) ? floatval($item['rap_vol']) : $rabVol;
        $ahspCode = $item['ahsp_code'] ?? null;
        $rapBasePrice = 0;
        if ($ahspCode && isset($ahspRapPrices[$ahspCode])) {
            $rapBasePrice = $ahspRapPrices[$ahspCode];
        }
        if ($rapBasePrice <= 0) {
            $rapBasePrice = (isset($item['rap_price']) && floatval($item['rap_price']) > 0) ? floatval($item['rap_price']) : floatval($item['rab_price']);
        }
        $rapPriceWithOverhead = $rapBasePrice * (1 + ($overheadPct / 100));
        $rapTotal = $rapVol * $rapPriceWithOverhead;

        // Actual with optional period
        $actWhere = "reqi.subcategory_id = ? AND req.status = 'approved' AND req.project_id = ?";
        $actParams = [$item['id'], $projectId];
        if ($startDate) {
            $actWhere .= " AND DATE(req.created_at) >= ?";
            $actParams[] = $startDate;
        }
        if ($endDate) {
            $actWhere .= " AND DATE(req.created_at) <= ?";
            $actParams[] = $endDate;
        }

        $actRow = dbGetRow("
            SELECT COALESCE(SUM(reqi.total_price), 0) as total
            FROM request_items reqi
            JOIN requests req ON reqi.request_id = req.id
            WHERE $actWhere
        ", $actParams);
        $actTotal = floatval($actRow['total'] ?? 0);

        $outItems[] = [
            'id' => $item['id'],
            'code' => $item['code'],
            'name' => $item['name'],
            'unit' => $item['unit'],
            'cat_code' => $item['cat_code'],
            'cat_name' => $item['cat_name'],
            'rab_vol' => $rabVol,
            'rab_price' => $rabPriceWithOverhead,
            'rab_total' => $rabTotal,
            'rap_vol' => $rapVol,
            'rap_price' => $rapPriceWithOverhead,
            'rap_total' => $rapTotal,
            'act_total' => $actTotal
        ];
    }

    return ['items' => $outItems];
}
