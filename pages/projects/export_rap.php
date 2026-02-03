<?php
/**
 * Export RAP to CSV
 * PCM - Project Cost Management System
 * Can export even if RAP is not submitted (uses RAB data as fallback)
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

// Get overhead percentage for calculation
$overheadPct = $project['overhead_percentage'] ?? 10;

// Region is now stored directly in project
$regionName = $project['region_name'] ?? '-';

// Set headers for CSV download
$filename = 'RAP_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $project['name']) . '_' . date('Ymd') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// BOM for Excel UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Project header info
fputcsv($output, ['RENCANA ANGGARAN PELAKSANAAN (RAP)'], ';');
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

// Table header
fputcsv($output, ['No', 'URAIAN PEKERJAAN', 'SAT', 'VOLUME', 'HARGA SATUAN (Rp)', 'JUMLAH HARGA (Rp)'], ';');

// Get RAP items or RAB subcategories (fallback if RAP not generated yet)
// Uses LEFT JOIN to get RAP data if available, otherwise falls back to RAB data
$rapItems = dbGetAll("
    SELECT 
        COALESCE(rap.volume, rs.volume) as volume,
        COALESCE(rap.unit_price, rs.unit_price) as base_price,
        rs.code as sub_code, rs.name as sub_name, rs.unit,
        rc.code as cat_code, rc.name as cat_name
    FROM rab_subcategories rs
    JOIN rab_categories rc ON rs.category_id = rc.id
    LEFT JOIN rap_items rap ON rap.subcategory_id = rs.id
    WHERE rc.project_id = ?
    ORDER BY rc.sort_order, rc.code, rs.sort_order, rs.code
", [$projectId]);

$grandTotal = 0;
$currentCat = '';
$catTotal = 0;
$itemNum = 0;

foreach ($rapItems as $index => $item) {
    // Check for new category
    if ($currentCat !== $item['cat_code']) {
        // Print previous category total if not first
        if ($currentCat !== '') {
            fputcsv($output, ['', '', '', '', 'Jumlah Total ' . $currentCat, number_format($catTotal, 2, ',', '.')], ';');
            fputcsv($output, [''], ';');
        }
        
        // Print category header
        $currentCat = $item['cat_code'];
        $catTotal = 0;
        $itemNum = 0;
        fputcsv($output, [$item['cat_code'], strtoupper($item['cat_name']), '', '', '', ''], ';');
    }
    
    $itemNum++;
    
    // Apply overhead markup to unit price (same as rap.php calculation)
    $unitPrice = $item['base_price'] * (1 + ($overheadPct / 100));
    $totalPrice = $item['volume'] * $unitPrice;
    
    $catTotal += $totalPrice;
    $grandTotal += $totalPrice;
    
    fputcsv($output, [
        $itemNum,
        $item['sub_name'],
        $item['unit'],
        number_format($item['volume'], 2, ',', '.'),
        number_format($unitPrice, 2, ',', '.'),
        number_format($totalPrice, 2, ',', '.')
    ], ';');
}

// Last category total
if ($currentCat !== '') {
    fputcsv($output, ['', '', '', '', 'Jumlah Total ' . $currentCat, number_format($catTotal, 2, ',', '.')], ';');
}

fputcsv($output, [''], ';');

// Footer totals
fputcsv($output, ['', '', '', '', 'JUMLAH TOTAL RAP', number_format($grandTotal, 2, ',', '.')], ';');

fclose($output);
exit;
