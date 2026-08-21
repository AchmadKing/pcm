<?php
/**
 * Print / PDF Template for Project Reports
 * PCM - Project Cost Management System
 */

if (!defined('DB_HOST')) {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/auth.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
}

$currentUserName = $_SESSION['full_name'] ?? getCurrentUserName() ?? 'Administrator';
$currentUserRole = getRoleDisplayName(getCurrentUserRole());
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan PCM - <?= htmlspecialchars($isAllProjects ? 'Semua Proyek' : $selectedProject['name']) ?> (<?= htmlspecialchars($periodeLabel) ?>)</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 10pt;
            color: #000;
            background: #f4f6f9;
            padding: 20px;
        }

        .no-print-toolbar {
            max-width: <?= in_array($type, ['transactions', 'comparison']) ? '297mm' : '210mm' ?>;
            margin: 0 auto 15px auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn-action {
            background: #0d6efd;
            color: #fff;
            border: none;
            padding: 8px 16px;
            font-size: 9.5pt;
            font-weight: bold;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-action:hover { background: #0b5ed7; }
        .btn-secondary { background: #6c757d; }
        .btn-secondary:hover { background: #5c636a; }

        .page-container {
            background: #fff;
            max-width: <?= in_array($type, ['transactions', 'comparison']) ? '297mm' : '210mm' ?>;
            margin: 0 auto;
            padding: 15mm;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .report-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }
        .report-header h2 {
            font-size: 15pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }
        .report-header h3 {
            font-size: 12pt;
            font-weight: bold;
            color: #222;
            margin-bottom: 4px;
        }
        .report-header p {
            font-size: 9.5pt;
            color: #555;
        }

        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 9.5pt;
        }
        .meta-table td {
            padding: 3px 0;
            vertical-align: top;
        }
        .meta-table td.label {
            width: 140px;
            font-weight: bold;
        }
        .meta-table td.colon {
            width: 15px;
            text-align: center;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            margin-bottom: 25px;
            font-size: 9pt;
        }
        .data-table th, .data-table td {
            border: 1px solid #000;
            padding: 5px 6px;
            vertical-align: middle;
        }
        .data-table th {
            background: #f0f2f5;
            font-weight: bold;
            text-align: center;
        }
        .data-table td.text-end { text-align: right; }
        .data-table td.text-center { text-align: center; }
        .data-table tr.total-row td, .data-table tr.total-row th {
            font-weight: bold;
            background: #e9ecef;
        }

        .cat-header {
            background: #f8f9fa;
            font-weight: bold;
        }

        /* Signature Section */
        .signature-container {
            margin-top: 35px;
            page-break-inside: avoid;
        }
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            border: none;
        }
        .signature-table td {
            border: none;
            width: 33.33%;
            text-align: center;
            vertical-align: top;
            padding: 0 10px;
        }
        .signature-title {
            font-size: 9.5pt;
            font-weight: bold;
            margin-bottom: 2px;
        }
        .signature-role {
            font-size: 8.5pt;
            color: #444;
            margin-bottom: 55px;
        }
        .signature-name {
            font-size: 9.5pt;
            font-weight: bold;
            margin-bottom: 3px;
        }
        .signature-name u {
            text-decoration: underline;
        }
        .signature-date {
            font-size: 8pt;
            color: #555;
        }

        @media print {
            body {
                background: #fff;
                padding: 0;
            }
            .no-print-toolbar {
                display: none !important;
            }
            .page-container {
                box-shadow: none;
                padding: 0;
                margin: 0;
                max-width: 100%;
            }
            @page {
                size: <?= in_array($type, ['transactions', 'comparison']) ? 'A4 landscape' : 'A4 portrait' ?>;
                margin: 12mm 10mm;
            }
        }
    </style>
</head>
<body>

<div class="no-print-toolbar">
    <a href="dashboard.php" class="btn-action btn-secondary">
        &larr; Kembali ke Dashboard
    </a>
    <button onclick="window.print()" class="btn-action">
        &#128424; Cetak / Simpan PDF
    </button>
</div>

<div class="page-container">
    
    <!-- Kop Laporan -->
    <div class="report-header">
        <h2>PCM - Project Cost Management System</h2>
        <?php if ($type === 'rekap'): ?>
            <h3>Laporan Rekapitulasi Proyek & Realisasi</h3>
        <?php elseif ($type === 'transactions'): ?>
            <h3>Laporan Detail Realisasi & Transaksi Pengeluaran</h3>
        <?php elseif ($type === 'comparison'): ?>
            <h3>Laporan Perbandingan RAB vs RAP vs Realisasi</h3>
        <?php elseif ($type === 'rab'): ?>
            <h3>Rencana Anggaran Biaya (RAB Kontrak)</h3>
        <?php endif; ?>
        <p>Dokumen Laporan Keuangan & Pengendalian Biaya Proyek</p>
    </div>

    <!-- Metadata Laporan -->
    <table class="meta-table">
        <tr>
            <td style="width: 50%;">
                <table style="width: 100%;">
                    <tr>
                        <td class="label">Cakupan Proyek</td>
                        <td class="colon">:</td>
                        <td><strong><?= htmlspecialchars($isAllProjects ? 'Semua Proyek (Kompilasi)' : $selectedProject['name']) ?></strong></td>
                    </tr>
                    <?php if (!$isAllProjects): ?>
                    <tr>
                        <td class="label">Wilayah</td>
                        <td class="colon">:</td>
                        <td><?= htmlspecialchars($selectedProject['region_name'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <td class="label">Status Proyek</td>
                        <td class="colon">:</td>
                        <td><?= $selectedProject['status'] === 'on_progress' ? 'Berjalan (On Progress)' : ($selectedProject['status'] === 'completed' ? 'Selesai' : ucfirst($selectedProject['status'])) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </td>
            <td style="width: 50%;">
                <table style="width: 100%;">
                    <tr>
                        <td class="label">Periode Filter</td>
                        <td class="colon">:</td>
                        <td><strong><?= htmlspecialchars($periodeLabel) ?></strong></td>
                    </tr>
                    <tr>
                        <td class="label">Tanggal Cetak</td>
                        <td class="colon">:</td>
                        <td><?= formatDateTime(date('Y-m-d H:i:s'), true) ?></td>
                    </tr>
                    <tr>
                        <td class="label">Dicetak Oleh</td>
                        <td class="colon">:</td>
                        <td><?= htmlspecialchars($currentUserName) ?> (<?= htmlspecialchars($currentUserRole) ?>)</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <?php if ($type === 'rekap'): ?>
        <!-- ========================================================================= -->
        <!-- REKAPITULASI PROYEK TABLE -->
        <!-- ========================================================================= -->
        <?php if ($isAllProjects): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th width="30">No</th>
                        <th>Nama Proyek</th>
                        <th width="75">Status</th>
                        <th width="100">Wilayah</th>
                        <th width="125" class="text-end">RAB (Kontrak)</th>
                        <th width="125" class="text-end">RAP (Budget)</th>
                        <th width="125" class="text-end">Realisasi (Aktual)</th>
                        <th width="120" class="text-end">Sisa RAP</th>
                        <th width="120" class="text-end">Margin</th>
                        <th width="65" class="text-center">% RAP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $no = 1;
                    $grandRab = 0; $grandRap = 0; $grandActual = 0;
                    foreach ($targetProjects as $proj):
                        $stats = calculateProjectRealtimeStats($proj['id']);
                        $rab = floatval($stats['total_rab'] ?? 0);
                        $rap = floatval($stats['total_rap'] ?? 0);
                        $actual = ($startDate || $endDate) 
                            ? getProjectPeriodActualSpending($proj['id'], $startDate, $endDate) 
                            : floatval($stats['total_actual'] ?? 0);
                        $sisaRap = $rap - $actual;
                        $margin = $rab - $actual;
                        $pct = $rap > 0 ? ($actual / $rap) * 100 : 0;

                        $grandRab += $rab;
                        $grandRap += $rap;
                        $grandActual += $actual;
                    ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td><strong><?= htmlspecialchars($proj['name']) ?></strong></td>
                        <td class="text-center"><?= $proj['status'] === 'on_progress' ? 'Berjalan' : ($proj['status'] === 'completed' ? 'Selesai' : ucfirst($proj['status'])) ?></td>
                        <td><?= htmlspecialchars($proj['region_name'] ?? '-') ?></td>
                        <td class="text-end"><?= number_format($rab, 2, ',', '.') ?></td>
                        <td class="text-end"><?= number_format($rap, 2, ',', '.') ?></td>
                        <td class="text-end"><?= number_format($actual, 2, ',', '.') ?></td>
                        <td class="text-end" style="<?= $sisaRap < 0 ? 'color: red;' : '' ?>"><?= number_format($sisaRap, 2, ',', '.') ?></td>
                        <td class="text-end" style="<?= $margin < 0 ? 'color: red;' : 'color: green;' ?>"><?= number_format($margin, 2, ',', '.') ?></td>
                        <td class="text-center"><?= number_format($pct, 1, ',', '.') ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <?php 
                    $grandSisa = $grandRap - $grandActual;
                    $grandMargin = $grandRab - $grandActual;
                    $grandPct = $grandRap > 0 ? ($grandActual / $grandRap) * 100 : 0;
                    ?>
                    <tr class="total-row">
                        <th colspan="4" class="text-center">TOTAL KESELURUHAN</th>
                        <th class="text-end"><?= number_format($grandRab, 2, ',', '.') ?></th>
                        <th class="text-end"><?= number_format($grandRap, 2, ',', '.') ?></th>
                        <th class="text-end"><?= number_format($grandActual, 2, ',', '.') ?></th>
                        <th class="text-end"><?= number_format($grandSisa, 2, ',', '.') ?></th>
                        <th class="text-end"><?= number_format($grandMargin, 2, ',', '.') ?></th>
                        <th class="text-center"><?= number_format($grandPct, 1, ',', '.') ?>%</th>
                    </tr>
                </tfoot>
            </table>
        <?php else: ?>
            <!-- Rekap Detail 1 Proyek -->
            <?php 
            $stats = calculateProjectRealtimeStats($selectedProject['id']);
            $catStats = $stats['category_stats'] ?? [];
            ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th width="35">No</th>
                        <th width="70">Kode</th>
                        <th>Nama Kategori Pekerjaan</th>
                        <th width="140" class="text-end">RAB (Kontrak)</th>
                        <th width="140" class="text-end">RAP (Budget)</th>
                        <th width="140" class="text-end">Realisasi / Aktual</th>
                        <th width="130" class="text-end">Sisa RAP</th>
                        <th width="130" class="text-end">Margin</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $no = 1;
                    foreach ($catStats as $cat):
                        $cRab = floatval($cat['rab_total']);
                        $cRap = floatval($cat['rap_total']);
                        $cAct = floatval($cat['actual_total']);
                        $cSisa = $cRap - $cAct;
                        $cMargin = $cRab - $cAct;
                    ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td class="text-center"><code><?= htmlspecialchars($cat['code']) ?></code></td>
                        <td><?= htmlspecialchars($cat['name']) ?></td>
                        <td class="text-end"><?= number_format($cRab, 2, ',', '.') ?></td>
                        <td class="text-end"><?= number_format($cRap, 2, ',', '.') ?></td>
                        <td class="text-end"><?= number_format($cAct, 2, ',', '.') ?></td>
                        <td class="text-end" style="<?= $cSisa < 0 ? 'color: red;' : '' ?>"><?= number_format($cSisa, 2, ',', '.') ?></td>
                        <td class="text-end" style="<?= $cMargin < 0 ? 'color: red;' : 'color: green;' ?>"><?= number_format($cMargin, 2, ',', '.') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <th colspan="3" class="text-center">SUBTOTAL</th>
                        <th class="text-end"><?= number_format($stats['subtotal_rab'] ?? 0, 2, ',', '.') ?></th>
                        <th class="text-end"><?= number_format($stats['total_rap'] ?? 0, 2, ',', '.') ?></th>
                        <th class="text-end"><?= number_format($stats['total_actual'] ?? 0, 2, ',', '.') ?></th>
                        <th class="text-end"><?= number_format(($stats['total_rap'] ?? 0) - ($stats['total_actual'] ?? 0), 2, ',', '.') ?></th>
                        <th class="text-end"><?= number_format(($stats['subtotal_rab'] ?? 0) - ($stats['total_actual'] ?? 0), 2, ',', '.') ?></th>
                    </tr>
                    <?php if (!empty($stats['ppn_amount'])): ?>
                    <tr class="total-row">
                        <th colspan="3" class="text-center">PPN (<?= number_format($selectedProject['ppn_percentage'] ?? 11, 2, ',', '.') ?>%)</th>
                        <th class="text-end"><?= number_format($stats['ppn_amount'], 2, ',', '.') ?></th>
                        <th colspan="4"></th>
                    </tr>
                    <tr class="total-row">
                        <th colspan="3" class="text-center">TOTAL RAB DIBULATKAN</th>
                        <th class="text-end"><?= number_format($stats['total_rab'], 2, ',', '.') ?></th>
                        <th colspan="4"></th>
                    </tr>
                    <?php endif; ?>
                </tfoot>
            </table>
        <?php endif; ?>

    <?php elseif ($type === 'transactions'): ?>
        <!-- ========================================================================= -->
        <!-- DETAIL REALISASI & TRANSAKSI TABLE -->
        <!-- ========================================================================= -->
        <?php 
        $transactions = fetchProjectTransactions($accessibleProjectIds, $projectId, $startDate, $endDate);
        ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th width="25">No</th>
                    <th width="75">Tanggal</th>
                    <th width="90">No. Pengajuan</th>
                    <?php if ($isAllProjects): ?><th>Proyek</th><?php endif; ?>
                    <th width="45">Mg</th>
                    <th width="80">Kode</th>
                    <th>Uraian Item / Pekerjaan</th>
                    <th width="50">Satuan</th>
                    <th width="70" class="text-end">Koefisien</th>
                    <th width="95" class="text-end">Harga Satuan</th>
                    <th width="115" class="text-end">Total Biaya (Rp)</th>
                    <th width="90">Pemohon</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $no = 1;
                $totalNominal = 0;
                foreach ($transactions as $t):
                    $nominal = floatval($t['total_price']);
                    $totalNominal += $nominal;
                ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td class="text-center"><?= date('d/m/Y', strtotime($t['created_at'])) ?></td>
                    <td class="text-center"><strong><?= htmlspecialchars($t['request_number']) ?></strong></td>
                    <?php if ($isAllProjects): ?><td><?= htmlspecialchars($t['project_name']) ?></td><?php endif; ?>
                    <td class="text-center"><?= $t['target_week'] ?? $t['week_number'] ?? '-' ?></td>
                    <td><code><?= htmlspecialchars($t['item_code'] ?? $t['subcategory_code'] ?? '-') ?></code></td>
                    <td>
                        <?= htmlspecialchars($t['item_name']) ?>
                        <?php if ($t['subcategory_name']): ?>
                        <br><small style="color: #666; font-style: italic;">(<?= htmlspecialchars($t['subcategory_name']) ?>)</small>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><?= htmlspecialchars($t['unit']) ?></td>
                    <td class="text-end"><?= number_format(floatval($t['coefficient']), 4, ',', '.') ?></td>
                    <td class="text-end"><?= number_format(floatval($t['unit_price']), 2, ',', '.') ?></td>
                    <td class="text-end"><strong><?= number_format($nominal, 2, ',', '.') ?></strong></td>
                    <td><?= htmlspecialchars($t['created_by_name'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($transactions)): ?>
                <tr>
                    <td colspan="<?= $isAllProjects ? 12 : 11 ?>" class="text-center" style="padding: 20px; color: #888;">
                        Tidak ada transaksi pengeluaran pada filter periode ini.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <th colspan="<?= $isAllProjects ? 10 : 9 ?>" class="text-center">TOTAL REALISASI PENGELUARAN</th>
                    <th class="text-end"><?= number_format($totalNominal, 2, ',', '.') ?></th>
                    <th></th>
                </tr>
            </tfoot>
        </table>

    <?php elseif ($type === 'comparison'): ?>
        <!-- ========================================================================= -->
        <!-- PERBANDINGAN RAB vs RAP vs REALISASI TABLE -->
        <!-- ========================================================================= -->
        <table class="data-table">
            <thead>
                <tr>
                    <?php if ($isAllProjects): ?><th width="140">Proyek</th><?php endif; ?>
                    <th width="50">Kode</th>
                    <th>Uraian Pekerjaan</th>
                    <th width="45">Sat</th>
                    <th width="60" class="text-end">Vol RAB</th>
                    <th width="85" class="text-end">Harga RAB</th>
                    <th width="105" class="text-end">Total RAB</th>
                    <th width="60" class="text-end">Vol RAP</th>
                    <th width="85" class="text-end">Harga RAP</th>
                    <th width="105" class="text-end">Total RAP</th>
                    <th width="110" class="text-end">Realisasi</th>
                    <th width="105" class="text-end">Selisih</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $grandRab = 0; $grandRap = 0; $grandAct = 0;
                foreach ($targetProjects as $proj):
                    $comparisonData = fetchProjectComparisonData($proj['id'], $startDate, $endDate);
                    foreach ($comparisonData['items'] as $item):
                        $grandRab += $item['rab_total'];
                        $grandRap += $item['rap_total'];
                        $grandAct += $item['act_total'];
                        $selisih = $item['rab_total'] - $item['act_total'];
                ?>
                <tr>
                    <?php if ($isAllProjects): ?><td><?= htmlspecialchars($proj['name']) ?></td><?php endif; ?>
                    <td><code><?= htmlspecialchars($item['code']) ?></code></td>
                    <td><?= htmlspecialchars($item['name']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($item['unit']) ?></td>
                    <td class="text-end"><?= number_format($item['rab_vol'], 2, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($item['rab_price'], 2, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($item['rab_total'], 2, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($item['rap_vol'], 2, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($item['rap_price'], 2, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($item['rap_total'], 2, ',', '.') ?></td>
                    <td class="text-end"><strong><?= number_format($item['act_total'], 2, ',', '.') ?></strong></td>
                    <td class="text-end" style="<?= $selisih < 0 ? 'color: red;' : 'color: green;' ?>">
                        <?= number_format($selisih, 2, ',', '.') ?>
                    </td>
                </tr>
                <?php 
                    endforeach;
                endforeach; 
                ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <th colspan="<?= $isAllProjects ? 6 : 5 ?>" class="text-center">TOTAL KESELURUHAN</th>
                    <th class="text-end"><?= number_format($grandRab, 2, ',', '.') ?></th>
                    <th colspan="2"></th>
                    <th class="text-end"><?= number_format($grandRap, 2, ',', '.') ?></th>
                    <th class="text-end"><?= number_format($grandAct, 2, ',', '.') ?></th>
                    <th class="text-end"><?= number_format($grandRab - $grandAct, 2, ',', '.') ?></th>
                </tr>
            </tfoot>
        </table>

    <?php elseif ($type === 'rab' && !$isAllProjects): ?>
        <!-- ========================================================================= -->
        <!-- DETAIL RAB PROYEK TABLE -->
        <!-- ========================================================================= -->
        <?php 
        $overheadPct = getProjectOverheadProfitPct($selectedProject);
        $ppnPct = floatval($selectedProject['ppn_percentage'] ?? 11);
        $categories = dbGetAll("SELECT * FROM rab_categories WHERE project_id = ? ORDER BY sort_order, code", [$selectedProject['id']]);
        $subtotalRab = 0;
        ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th width="35">No</th>
                    <th width="80">Kode</th>
                    <th>Uraian Pekerjaan</th>
                    <th width="55">Satuan</th>
                    <th width="90" class="text-end">Volume</th>
                    <th width="120" class="text-end">Harga Satuan (Rp)</th>
                    <th width="140" class="text-end">Jumlah Harga (Rp)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $no = 1;
                foreach ($categories as $cat):
                    $subcats = dbGetAll("SELECT * FROM rab_subcategories WHERE category_id = ? ORDER BY sort_order, code", [$cat['id']]);
                    $catTotal = 0;
                ?>
                <tr class="cat-header">
                    <td></td>
                    <td class="text-center"><strong><?= htmlspecialchars($cat['code']) ?></strong></td>
                    <td colspan="5"><strong><?= strtoupper(htmlspecialchars($cat['name'])) ?></strong></td>
                </tr>
                <?php 
                foreach ($subcats as $sub):
                    $basePrice = floatval($sub['unit_price']);
                    $unitPriceWithOverhead = $basePrice * (1 + ($overheadPct / 100));
                    $total = floatval($sub['volume']) * $unitPriceWithOverhead;
                    $catTotal += $total;
                ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td><code><?= htmlspecialchars($sub['code']) ?></code></td>
                    <td><?= htmlspecialchars($sub['name']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($sub['unit']) ?></td>
                    <td class="text-end"><?= number_format(floatval($sub['volume']), 2, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($unitPriceWithOverhead, 2, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($total, 2, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background: #fafafa; font-weight: bold;">
                    <td colspan="6" class="text-end">Jumlah <?= htmlspecialchars($cat['code']) ?>:</td>
                    <td class="text-end"><?= number_format($catTotal, 2, ',', '.') ?></td>
                </tr>
                <?php 
                    $subtotalRab += $catTotal;
                endforeach; 
                $ppnAmount = $subtotalRab * ($ppnPct / 100);
                $totalWithPpn = $subtotalRab + $ppnAmount;
                $totalRounded = ceil($totalWithPpn / 10) * 10;
                ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <th colspan="6" class="text-end">SUBTOTAL RAB</th>
                    <th class="text-end"><?= number_format($subtotalRab, 2, ',', '.') ?></th>
                </tr>
                <tr class="total-row">
                    <th colspan="6" class="text-end">PPN <?= number_format($ppnPct, 2, ',', '.') ?>%</th>
                    <th class="text-end"><?= number_format($ppnAmount, 2, ',', '.') ?></th>
                </tr>
                <tr class="total-row">
                    <th colspan="6" class="text-end">TOTAL RAB DIBULATKAN</th>
                    <th class="text-end"><?= number_format($totalRounded, 0, ',', '.') ?></th>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>

    <!-- Section Tanda Tangan Resmi -->
    <div class="signature-container">
        <table class="signature-table">
            <tr>
                <!-- 1. Dibuat Oleh -->
                <td>
                    <div class="signature-title">Dibuat / Disiapkan Oleh,</div>
                    <div class="signature-role">Staff / Tim Proyek</div>
                    <div class="signature-name">
                        <u><?= htmlspecialchars($currentUserName) ?></u>
                    </div>
                    <div class="signature-date">
                        Tgl: <?= formatDate(date('Y-m-d')) ?>
                    </div>
                </td>

                <!-- 2. Diperiksa Oleh (Finance) -->
                <td>
                    <div class="signature-title">Diperiksa Oleh,</div>
                    <div class="signature-role">Finance / Keuangan</div>
                    <div class="signature-name">
                        ( ........................................ )
                    </div>
                    <div class="signature-date">
                        Tgl: ................................
                    </div>
                </td>

                <!-- 3. Disetujui Oleh (Pimpinan / Manajemen) -->
                <td>
                    <div class="signature-title">Disetujui Oleh,</div>
                    <div class="signature-role">Project Manager / Direksi</div>
                    <div class="signature-name">
                        ( ........................................ )
                    </div>
                    <div class="signature-date">
                        Tgl: ................................
                    </div>
                </td>
            </tr>
        </table>
    </div>

</div>

<script>
    window.addEventListener('DOMContentLoaded', () => {
        setTimeout(() => {
            window.print();
        }, 500);
    });
</script>
</body>
</html>
