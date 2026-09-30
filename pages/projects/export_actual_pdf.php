<?php
/**
 * Export Realisasi (Actualization) to PDF (Print-Ready HTML)
 * PCC - Project Cost Control System
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();
requirePermission('reports.view');

$projectId = $_GET['id'] ?? null;

if (!$projectId || !canAccessProject($projectId)) {
    die('Anda tidak memiliki akses ke proyek ini');
}

$project = dbGetRow("SELECT * FROM projects WHERE id = ?", [$projectId]);
if (!$project) {
    die('Proyek tidak ditemukan');
}

$overheadPct = getProjectOverheadProfitPct($project, 'rap');
$regionName = $project['region_name'] ?? '-';

// Weekly progress setup
$weeklyRanges = [];
$showWeeklyColumns = false;
$hasWeeklyHistory = dbGetRow("SELECT 1 FROM weekly_progress WHERE project_id = ? LIMIT 1", [$projectId]) ? true : false;
if ($project['status'] !== 'draft' || $hasWeeklyHistory) {
    if (!empty($project['start_date']) && !empty($project['duration_days'])) {
        $weeklyRanges = generateWeeklyRanges($project['start_date'], $project['duration_days']);
        $showWeeklyColumns = !empty($weeklyRanges);
    }
}

// Weekly progress data
$weeklyData = [];
if ($showWeeklyColumns) {
    $allWeeklyProgress = dbGetAll("
        SELECT subcategory_id, week_number, realization_amount
        FROM weekly_progress
        WHERE project_id = ?
    ", [$projectId]);
    
    foreach ($allWeeklyProgress as $wp) {
        $weeklyData[$wp['subcategory_id']][$wp['week_number']] = floatval($wp['realization_amount']);
    }
}

// Actualization adjustments - batch loaded
$actualizationAdjustments = batchGetActualizationAdjustments($projectId);

// Batch-load all data needed (instead of per-subcategory queries)
$rapAhspBreakdownMap = batchGetRapAhspComponentBreakdowns($projectId);
$batchActualSpending = batchGetActualSpendingBySubcategory($projectId);
$batchActualBreakdown = batchGetActualBreakdownBySubcategory($projectId);

// Get categories
$categories = dbGetAll("
    SELECT rc.id, rc.code, rc.name, rc.sort_order
    FROM rab_categories rc
    WHERE rc.project_id = ?
    ORDER BY rc.sort_order, rc.code
", [$projectId]);

// Build hierarchical view
$actualData = [];
$grandRapTotal = 0;
$grandActualUpah = 0;
$grandActualMaterial = 0;
$grandActualAlat = 0;
$grandActualTotal = 0;
$grandSelisih = 0;
$weeklyGrandTotals = [];

foreach ($categories as $cat) {
    $catId = $cat['id'];
    
    $subcats = dbGetAll("
        SELECT rs.id, rs.code, rs.name, rs.unit, rs.volume as rab_volume, rs.unit_price as rab_unit_price,
               rap.volume as rap_volume, rap.unit_price as rap_unit_price,
               pa.ahsp_code
        FROM rab_subcategories rs
        LEFT JOIN rap_items rap ON rs.id = rap.subcategory_id
        LEFT JOIN project_ahsp pa ON rs.ahsp_id = pa.id
        WHERE rs.category_id = ?
        ORDER BY rs.sort_order, rs.code
    ", [$catId]);
    
    if (empty($subcats)) continue;
    
    $enrichedSubcats = [];
    $catRapTotal = 0;
    $catActualUpah = 0;
    $catActualMaterial = 0;
    $catActualAlat = 0;
    $catActualTotal = 0;
    $catSelisih = 0;
    $catWeeklyTotals = [];
    
    foreach ($subcats as $sub) {
        $volume = (isset($sub['rap_volume']) && $sub['rap_volume'] !== null) ? floatval($sub['rap_volume']) : floatval($sub['rab_volume']);
        
        // Use pre-loaded batch map instead of per-subcategory query
        $ahspCode = $sub['ahsp_code'] ?? null;
        $rapComponents = $ahspCode ? ($rapAhspBreakdownMap[$ahspCode] ?? ['upah' => 0.0, 'material' => 0.0, 'alat' => 0.0]) : ['upah' => 0.0, 'material' => 0.0, 'alat' => 0.0];
        
        $baseUnitPrice = $rapComponents['upah'] + $rapComponents['material'] + $rapComponents['alat'];
        if ($baseUnitPrice <= 0) {
            $baseUnitPrice = (isset($sub['rap_unit_price']) && floatval($sub['rap_unit_price']) > 0) ? floatval($sub['rap_unit_price']) : floatval($sub['rab_unit_price']);
        }
        
        $unitPriceWithOverhead = $baseUnitPrice * (1 + ($overheadPct / 100));
        $subRapTotal = $volume * $unitPriceWithOverhead;
        
        // Use pre-loaded actual spending
        $subActualTotal = $batchActualSpending[$sub['id']] ?? 0.0;
        
        // Use pre-loaded actual breakdown
        $breakdown = $batchActualBreakdown[$sub['id']] ?? ['upah' => 0.0, 'material' => 0.0, 'alat' => 0.0];
        $subActualUpah = $breakdown['upah'];
        $subActualMaterial = $breakdown['material'];
        $subActualAlat = $breakdown['alat'];
        
        // Adjust for actualization deduction
        $adjTotal = $actualizationAdjustments[$sub['id']] ?? 0;
        if ($adjTotal > 0) {
            $subActualSum = $subActualUpah + $subActualMaterial + $subActualAlat;
            if ($subActualSum > 0) {
                $subActualUpah -= $adjTotal * ($subActualUpah / $subActualSum);
                $subActualMaterial -= $adjTotal * ($subActualMaterial / $subActualSum);
                $subActualAlat -= $adjTotal * ($subActualAlat / $subActualSum);
            }
            $subActualTotal -= $adjTotal;
        }
        
        $subProgress = $subRapTotal > 0 ? ($subActualTotal / $subRapTotal) * 100 : 0;
        $subSelisih = $subRapTotal - $subActualTotal;
        
        // Accumulate cat totals
        $catRapTotal += $subRapTotal;
        $catActualUpah += $subActualUpah;
        $catActualMaterial += $subActualMaterial;
        $catActualAlat += $subActualAlat;
        $catActualTotal += $subActualTotal;
        $catSelisih += $subSelisih;
        
        $sub['display_rap_total'] = $subRapTotal;
        $sub['display_actual_upah'] = $subActualUpah;
        $sub['display_actual_material'] = $subActualMaterial;
        $sub['display_actual_alat'] = $subActualAlat;
        $sub['display_actual_total'] = $subActualTotal;
        $sub['display_selisih'] = $subSelisih;
        $sub['display_progress'] = $subProgress;
        
        // Add weekly values
        $sub['weekly'] = [];
        if ($showWeeklyColumns) {
            foreach ($weeklyRanges as $week) {
                $weekNum = $week['week_number'];
                $weekRealization = $weeklyData[$sub['id']][$weekNum] ?? 0;
                $sub['weekly'][$weekNum] = $weekRealization;
                
                if (!isset($catWeeklyTotals[$weekNum])) {
                    $catWeeklyTotals[$weekNum] = 0;
                }
                $catWeeklyTotals[$weekNum] += $weekRealization;
            }
        }
        
        $enrichedSubcats[] = $sub;
    }
    
    // Accumulate grand totals
    $grandRapTotal += $catRapTotal;
    $grandActualUpah += $catActualUpah;
    $grandActualMaterial += $catActualMaterial;
    $grandActualAlat += $catActualAlat;
    $grandActualTotal += $catActualTotal;
    $grandSelisih += $catSelisih;
    
    if ($showWeeklyColumns) {
        foreach ($weeklyRanges as $week) {
            $weekNum = $week['week_number'];
            $weekTotal = $catWeeklyTotals[$weekNum] ?? 0;
            if (!isset($weeklyGrandTotals[$weekNum])) {
                $weeklyGrandTotals[$weekNum] = 0;
            }
            $weeklyGrandTotals[$weekNum] += $weekTotal;
        }
    }
    
    $actualData[$cat['id']] = [
        'category' => $cat,
        'subcategories' => $enrichedSubcats,
        'total_rap' => $catRapTotal,
        'total_upah' => $catActualUpah,
        'total_material' => $catActualMaterial,
        'total_alat' => $catActualAlat,
        'total_actual' => $catActualTotal,
        'total_selisih' => $catSelisih,
        'weekly_totals' => $catWeeklyTotals
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Realisasi - <?= htmlspecialchars($project['name']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 10pt;
            color: #000;
            background: #f5f5f5;
            padding: 20px;
        }

        .page-container {
            background: #fff;
            max-width: <?= $showWeeklyColumns ? '350mm' : '297mm' ?>;
            margin: 0 auto;
            padding: 12mm 10mm;
            box-shadow: 0 2px 10px rgba(0,0,0,0.15);
        }

        .report-title {
            text-align: center;
            font-size: 13pt;
            font-weight: bold;
            margin-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .project-info {
            margin-bottom: 15px;
            border-collapse: collapse;
            width: 100%;
        }
        .project-info td {
            padding: 2px 4px;
            vertical-align: top;
            font-size: 9pt;
        }
        .project-info td:first-child {
            width: 200px;
            font-weight: bold;
        }
        .project-info td:nth-child(2) {
            width: 15px;
            text-align: center;
        }

        .actual-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 8.5pt;
        }
        .actual-table th, .actual-table td {
            border: 1px solid #000;
            padding: 3px 5px;
        }
        .actual-table th {
            background: #e2efda;
            font-weight: bold;
            text-align: center;
            vertical-align: middle;
        }
        .actual-table .text-end { text-align: right; }
        .actual-table .text-center { text-align: center; }

        .cat-header {
            background: #f2f9f0;
            font-weight: bold;
        }
        .cat-total {
            background: #f2f2f2;
            font-weight: bold;
        }
        .grand-total {
            background: #c6e0b4;
            font-weight: bold;
        }

        .toolbar {
            position: fixed;
            top: 10px;
            right: 20px;
            z-index: 1000;
            display: flex;
            gap: 8px;
        }
        .toolbar button {
            padding: 8px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .btn-print {
            background: #28a745;
            color: #fff;
        }
        .btn-print:hover { background: #218838; }

        @media print {
            body {
                background: #fff;
                padding: 0;
            }
            .page-container {
                box-shadow: none;
                padding: 0;
                max-width: 100%;
            }
            .toolbar {
                display: none;
            }
            @page {
                size: A4 <?= $showWeeklyColumns ? 'landscape' : 'portrait' ?>;
                margin: 10mm 10mm;
            }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <button class="btn-print" onclick="window.print()">
        <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
            <path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2H5zM4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4V3zm1 5a2 2 0 1 1-4 0 2 2 0 0 1 4 0z"/>
        </svg>
        Cetak Laporan
    </button>
</div>

<div class="page-container">
    <div class="report-title">LAPORAN REALISASI ANGGARAN & FISIK</div>
    
    <table class="project-info">
        <tr>
            <td>NAMA KEGIATAN</td>
            <td>:</td>
            <td><?= htmlspecialchars($project['activity_name'] ?? '-') ?></td>
        </tr>
        <tr>
            <td>PEKERJAAN</td>
            <td>:</td>
            <td><?= htmlspecialchars($project['work_description'] ?? '-') ?></td>
        </tr>
        <tr>
            <td>SUMBER DANA</td>
            <td>:</td>
            <td><?= htmlspecialchars($project['funding_source'] ?? '-') ?></td>
        </tr>
        <tr>
            <td>TAHUN ANGGARAN</td>
            <td>:</td>
            <td><?= htmlspecialchars($project['budget_year']) ?></td>
        </tr>
        <tr>
            <td>NO. dan TGL KONTRAK</td>
            <td>:</td>
            <td><?= htmlspecialchars(($project['contract_number'] ?? '-') . ' TANGGAL ' . ($project['contract_date'] ? formatDate($project['contract_date']) : '-')) ?></td>
        </tr>
        <tr>
            <td>PENYEDIA JASA</td>
            <td>:</td>
            <td><?= htmlspecialchars($project['service_provider'] ?? '-') ?></td>
        </tr>
        <tr>
            <td>KONS. PENGAWAS</td>
            <td>:</td>
            <td><?= htmlspecialchars($project['supervisor_consultant'] ?? '-') ?></td>
        </tr>
        <tr>
            <td>JANGKA WAKTU PELAKSANAAN</td>
            <td>:</td>
            <td><?= htmlspecialchars($project['duration_days'] ?? 0) ?> HARI</td>
        </tr>
        <tr>
            <td>WILAYAH</td>
            <td>:</td>
            <td><?= htmlspecialchars($regionName) ?></td>
        </tr>
    </table>

    <table class="actual-table">
        <thead>
            <tr>
                <th rowspan="2" width="40">NO.</th>
                <th rowspan="2">URAIAN PEKERJAAN</th>
                <th rowspan="2" width="40">SAT</th>
                <th rowspan="2" width="100">TARGET RAP<br>(Rp)</th>
                <th colspan="4">REALISASI ANGGARAN (Rp)</th>
                <th rowspan="2" width="100">SELISIH<br>(Rp)</th>
                <th rowspan="2" width="60">PROG<br>(%)</th>
                <?php if ($showWeeklyColumns): ?>
                <?php foreach ($weeklyRanges as $week): ?>
                <th colspan="2">MINGGU KE-<?= $week['week_number'] ?></th>
                <?php endforeach; ?>
                <?php endif; ?>
            </tr>
            <tr>
                <th width="80">UPAH</th>
                <th width="80">MATERIAL</th>
                <th width="80">ALAT</th>
                <th width="90">TOTAL</th>
                <?php if ($showWeeklyColumns): ?>
                <?php foreach ($weeklyRanges as $week): ?>
                <th width="80">Rp</th>
                <th width="50">%</th>
                <?php endforeach; ?>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($actualData as $catId => $data): 
                $cat = $data['category'];
                $subcats = $data['subcategories'];
                if (empty($subcats)) continue;
            ?>
            <!-- Category Header -->
            <tr class="cat-header">
                <td class="text-center"><?= htmlspecialchars($cat['code']) ?></td>
                <td colspan="<?= $showWeeklyColumns ? 8 + count($weeklyRanges)*2 : 8 ?>"><?= htmlspecialchars(strtoupper($cat['name'])) ?></td>
            </tr>
            
            <?php 
            $itemNum = 0;
            foreach ($subcats as $sub): 
                $itemNum++;
            ?>
            <tr>
                <td class="text-center"><?= $itemNum ?></td>
                <td><?= htmlspecialchars($sub['name']) ?></td>
                <td class="text-center"><?= htmlspecialchars($sub['unit']) ?></td>
                <td class="text-end"><?= number_format($sub['display_rap_total'], 2, ',', '.') ?></td>
                <td class="text-end"><?= number_format($sub['display_actual_upah'], 2, ',', '.') ?></td>
                <td class="text-end"><?= number_format($sub['display_actual_material'], 2, ',', '.') ?></td>
                <td class="text-end"><?= number_format($sub['display_actual_alat'], 2, ',', '.') ?></td>
                <td class="text-end"><?= number_format($sub['display_actual_total'], 2, ',', '.') ?></td>
                <td class="text-end"><?= number_format($sub['display_selisih'], 2, ',', '.') ?></td>
                <td class="text-center"><?= number_format($sub['display_progress'], 1, ',', '.') ?>%</td>
                
                <?php if ($showWeeklyColumns): ?>
                <?php foreach ($weeklyRanges as $week): 
                    $weekNum = $week['week_number'];
                    $weekRealization = $sub['weekly'][$weekNum] ?? 0;
                    $weekBobot = $sub['display_rap_total'] > 0 ? ($weekRealization / $sub['display_rap_total']) * 100 : 0;
                ?>
                <td class="text-end"><?= $weekRealization > 0 ? number_format($weekRealization, 0, ',', '.') : '-' ?></td>
                <td class="text-center"><?= $weekBobot > 0 ? number_format($weekBobot, 2, ',', '.') . '%' : '-' ?></td>
                <?php endforeach; ?>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            
            <!-- Category Total -->
            <tr class="cat-total">
                <td colspan="3" class="text-end">Jumlah Total <?= htmlspecialchars($cat['code']) ?></td>
                <td class="text-end"><?= number_format($data['total_rap'], 2, ',', '.') ?></td>
                <td class="text-end"><?= number_format($data['total_upah'], 2, ',', '.') ?></td>
                <td class="text-end"><?= number_format($data['total_material'], 2, ',', '.') ?></td>
                <td class="text-end"><?= number_format($data['total_alat'], 2, ',', '.') ?></td>
                <td class="text-end"><?= number_format($data['total_actual'], 2, ',', '.') ?></td>
                <td class="text-end"><?= number_format($data['total_selisih'], 2, ',', '.') ?></td>
                <td class="text-center">-</td>
                
                <?php if ($showWeeklyColumns): ?>
                <?php foreach ($weeklyRanges as $week): 
                    $weekNum = $week['week_number'];
                    $weekTotal = $data['weekly_totals'][$weekNum] ?? 0;
                    $catWeekBobot = $data['total_rap'] > 0 ? ($weekTotal / $data['total_rap']) * 100 : 0;
                ?>
                <td class="text-end"><?= $weekTotal > 0 ? number_format($weekTotal, 0, ',', '.') : '-' ?></td>
                <td class="text-center"><?= $catWeekBobot > 0 ? number_format($catWeekBobot, 2, ',', '.') . '%' : '-' ?></td>
                <?php endforeach; ?>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <!-- Grand Total -->
            <?php 
            $grandProgress = $grandRapTotal > 0 ? ($grandActualTotal / $grandRapTotal) * 100 : 0;
            ?>
            <tr class="grand-total">
                <td colspan="3" class="text-end">JUMLAH TOTAL REALISASI</td>
                <td class="text-end"><?= number_format($grandRapTotal, 2, ',', '.') ?></td>
                <td class="text-end"><?= number_format($grandActualUpah, 2, ',', '.') ?></td>
                <td class="text-end"><?= number_format($grandActualMaterial, 2, ',', '.') ?></td>
                <td class="text-end"><?= number_format($grandActualAlat, 2, ',', '.') ?></td>
                <td class="text-end"><?= number_format($grandActualTotal, 2, ',', '.') ?></td>
                <td class="text-end"><?= number_format($grandSelisih, 2, ',', '.') ?></td>
                <td class="text-center"><?= number_format($grandProgress, 1, ',', '.') ?>%</td>
                
                <?php if ($showWeeklyColumns): ?>
                <?php foreach ($weeklyRanges as $week): 
                    $weekNum = $week['week_number'];
                    $weekGrand = $weeklyGrandTotals[$weekNum] ?? 0;
                    $grandWeekBobot = $grandRapTotal > 0 ? ($weekGrand / $grandRapTotal) * 100 : 0;
                ?>
                <td class="text-end"><?= $weekGrand > 0 ? number_format($weekGrand, 0, ',', '.') : '-' ?></td>
                <td class="text-center"><?= $grandWeekBobot > 0 ? number_format($grandWeekBobot, 2, ',', '.') . '%' : '-' ?></td>
                <?php endforeach; ?>
                <?php endif; ?>
            </tr>
        </tfoot>
    </table>
</div>

</body>
</html>
