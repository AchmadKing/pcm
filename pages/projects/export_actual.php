<?php
/**
 * Export Realisasi (Actualization) to CSV
 * PCM - Project Cost Management System
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();

$projectId = $_GET['id'] ?? null;

if (!$projectId) {
    header('Location: index.php');
    exit;
}

// Get project
$project = dbGetRow("SELECT * FROM projects WHERE id = ?", [$projectId]);

if (!$project) {
    setFlash('error', 'Proyek tidak ditemukan!');
    header('Location: index.php');
    exit;
}

$overheadPct = $project['overhead_percentage'] ?? 10;
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

// Set Headers for CSV
$filename = 'REALISASI_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $project['name']) . '_' . date('Ymd') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

// File headers
fputcsv($output, ['LAPORAN REALISASI ANGGARAN & FISIK'], ';');
fputcsv($output, [''], ';');
fputcsv($output, ['NAMA KEGIATAN', ':', $project['activity_name'] ?? '-'], ';');
fputcsv($output, ['PEKERJAAN', ':', $project['work_description'] ?? '-'], ';');
fputcsv($output, ['SUMBER DANA', ':', $project['funding_source'] ?? '-'], ';');
fputcsv($output, ['TAHUN ANGGARAN', ':', $project['budget_year']], ';');
fputcsv($output, ['NO. dan TGL KONTRAK', ':', ($project['contract_number'] ?? '-') . ' TANGGAL ' . ($project['contract_date'] ? formatDate($project['contract_date']) : '-')], ';');
fputcsv($output, ['PENYEDIA JASA', ':', $project['service_provider'] ?? '-'], ';');
fputcsv($output, ['KONS. PENGAWAS', ':', $project['supervisor_consultant'] ?? '-'], ';');
fputcsv($output, ['JANGKA WAKTU PELAKSANAAN', ':', ($project['duration_days'] ?? 0) . ' HARI'], ';');
fputcsv($output, ['WILAYAH', ':', $regionName], ';');
fputcsv($output, [''], ';');

// Setup column headers
$csvHeaders = [
    'No', 
    'URAIAN PEKERJAAN', 
    'SAT', 
    'RAP TARGET (Rp)', 
    'REALISASI UPAH (Rp)', 
    'REALISASI MATERIAL (Rp)', 
    'REALISASI ALAT (Rp)', 
    'REALISASI TOTAL (Rp)', 
    'SELISIH (Rp)', 
    'PROGRESS (%)'
];

if ($showWeeklyColumns) {
    foreach ($weeklyRanges as $week) {
        $csvHeaders[] = 'Mgg ' . $week['week_number'] . ' (Rp)';
        $csvHeaders[] = 'Mgg ' . $week['week_number'] . ' (%)';
    }
}

fputcsv($output, $csvHeaders, ';');

// Totals variables
$grandRapTotal = 0;
$grandActualUpah = 0;
$grandActualMaterial = 0;
$grandActualAlat = 0;
$grandActualTotal = 0;
$grandSelisih = 0;
$weeklyGrandTotals = [];

$catNum = 0;
foreach ($categories as $cat) {
    $catNum++;
    $catId = $cat['id'];
    
    // Subcategories
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
    
    // Print Category row
    $catRow = [$cat['code'], strtoupper($cat['name']), '', '', '', '', '', '', '', ''];
    if ($showWeeklyColumns) {
        foreach ($weeklyRanges as $week) {
            $catRow[] = '';
            $catRow[] = '';
        }
    }
    fputcsv($output, $catRow, ';');
    
    $catRapTotal = 0;
    $catActualUpah = 0;
    $catActualMaterial = 0;
    $catActualAlat = 0;
    $catActualTotal = 0;
    $catSelisih = 0;
    $catWeeklyTotals = [];
    
    $itemNum = 0;
    foreach ($subcats as $sub) {
        $itemNum++;
        
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
        
        // Accumulate category totals
        $catRapTotal += $subRapTotal;
        $catActualUpah += $subActualUpah;
        $catActualMaterial += $subActualMaterial;
        $catActualAlat += $subActualAlat;
        $catActualTotal += $subActualTotal;
        $catSelisih += $subSelisih;
        
        // Prepare subcategory row
        $subcatRow = [
            $itemNum,
            $sub['name'],
            $sub['unit'],
            number_format($subRapTotal, 2, ',', '.'),
            number_format($subActualUpah, 2, ',', '.'),
            number_format($subActualMaterial, 2, ',', '.'),
            number_format($subActualAlat, 2, ',', '.'),
            number_format($subActualTotal, 2, ',', '.'),
            number_format($subSelisih, 2, ',', '.'),
            number_format($subProgress, 1, ',', '.') . '%'
        ];
        
        if ($showWeeklyColumns) {
            foreach ($weeklyRanges as $week) {
                $weekNum = $week['week_number'];
                $weekRealization = $weeklyData[$sub['id']][$weekNum] ?? 0;
                $weekBobot = $subRapTotal > 0 ? ($weekRealization / $subRapTotal) * 100 : 0;
                
                $subcatRow[] = number_format($weekRealization, 2, ',', '.');
                $subcatRow[] = number_format($weekBobot, 2, ',', '.') . '%';
                
                if (!isset($catWeeklyTotals[$weekNum])) {
                    $catWeeklyTotals[$weekNum] = 0;
                }
                $catWeeklyTotals[$weekNum] += $weekRealization;
            }
        }
        
        fputcsv($output, $subcatRow, ';');
    }
    
    // Category Totals Row
    $catTotalRow = [
        '',
        'Jumlah Total ' . $cat['code'],
        '',
        number_format($catRapTotal, 2, ',', '.'),
        number_format($catActualUpah, 2, ',', '.'),
        number_format($catActualMaterial, 2, ',', '.'),
        number_format($catActualAlat, 2, ',', '.'),
        number_format($catActualTotal, 2, ',', '.'),
        number_format($catSelisih, 2, ',', '.'),
        '' // Progress category average is not direct sum
    ];
    
    if ($showWeeklyColumns) {
        foreach ($weeklyRanges as $week) {
            $weekNum = $week['week_number'];
            $weekTotal = $catWeeklyTotals[$weekNum] ?? 0;
            $catWeekBobot = $catRapTotal > 0 ? ($weekTotal / $catRapTotal) * 100 : 0;
            $catTotalRow[] = number_format($weekTotal, 2, ',', '.');
            $catTotalRow[] = $catWeekBobot > 0 ? number_format($catWeekBobot, 2, ',', '.') . '%' : '-';
            
            if (!isset($weeklyGrandTotals[$weekNum])) {
                $weeklyGrandTotals[$weekNum] = 0;
            }
            $weeklyGrandTotals[$weekNum] += $weekTotal;
        }
    }
    
    fputcsv($output, $catTotalRow, ';');
    fputcsv($output, [''], ';'); // empty separator row
    
    // Accumulate grand totals
    $grandRapTotal += $catRapTotal;
    $grandActualUpah += $catActualUpah;
    $grandActualMaterial += $catActualMaterial;
    $grandActualAlat += $catActualAlat;
    $grandActualTotal += $catActualTotal;
    $grandSelisih += $catSelisih;
}

// Grand Totals Row
$grandProgress = $grandRapTotal > 0 ? ($grandActualTotal / $grandRapTotal) * 100 : 0;
$grandTotalRow = [
    '',
    'JUMLAH TOTAL REALISASI',
    '',
    number_format($grandRapTotal, 2, ',', '.'),
    number_format($grandActualUpah, 2, ',', '.'),
    number_format($grandActualMaterial, 2, ',', '.'),
    number_format($grandActualAlat, 2, ',', '.'),
    number_format($grandActualTotal, 2, ',', '.'),
    number_format($grandSelisih, 2, ',', '.'),
    number_format($grandProgress, 1, ',', '.') . '%'
];

if ($showWeeklyColumns) {
    foreach ($weeklyRanges as $week) {
        $weekNum = $week['week_number'];
        $weekGrand = $weeklyGrandTotals[$weekNum] ?? 0;
        $grandWeekBobot = $grandRapTotal > 0 ? ($weekGrand / $grandRapTotal) * 100 : 0;
        $grandTotalRow[] = number_format($weekGrand, 2, ',', '.');
        $grandTotalRow[] = $grandWeekBobot > 0 ? number_format($grandWeekBobot, 2, ',', '.') . '%' : '-';
    }
}

fputcsv($output, $grandTotalRow, ';');

fclose($output);
exit;
