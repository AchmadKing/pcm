<?php
/**
 * Export RAB to CSV
 * PCM - Project Cost Management System
 * Supports two formats:
 * - report: Full report format with headers and totals
 * - import: Simplified format for re-import (Kategori | Kode AHSP | Volume)
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();

$projectId = $_GET['id'] ?? null;
$format = $_GET['format'] ?? 'report'; // 'report' or 'import'

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

// Set filename based on format
$formatSuffix = ($format === 'import') ? '_IMPORT' : '';
$filename = 'RAB' . $formatSuffix . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $project['name']) . '_' . date('Ymd') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// BOM for Excel UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Get RAB data with AHSP codes
$rabItems = dbGetAll("
    SELECT 
        rs.volume,
        rs.unit_price,
        rs.code as sub_code, rs.name as sub_name, rs.unit,
        rc.code as cat_code, rc.name as cat_name,
        pa.ahsp_code
    FROM rab_subcategories rs
    JOIN rab_categories rc ON rs.category_id = rc.id
    LEFT JOIN project_ahsp pa ON rs.ahsp_id = pa.id
    WHERE rc.project_id = ?
    ORDER BY rc.sort_order, rc.code, rs.sort_order, rs.code
", [$projectId]);

if ($format === 'import') {
    // ==========================================
    // IMPORT FORMAT: Kategori | Kode AHSP | Volume
    // ==========================================
    
    // Header row
    fputcsv($output, ['Nama Kategori', 'Kode AHSP', 'Volume'], ';');
    
    $currentCat = '';
    
    foreach ($rabItems as $item) {
        // Check for new category
        if ($currentCat !== $item['cat_name']) {
            $currentCat = $item['cat_name'];
            // First item of new category: include category name
            fputcsv($output, [
                $item['cat_name'],
                $item['ahsp_code'] ?? '',
                number_format($item['volume'], 2, ',', '')
            ], ';');
        } else {
            // Same category: leave category column empty
            fputcsv($output, [
                '',
                $item['ahsp_code'] ?? '',
                number_format($item['volume'], 2, ',', '')
            ], ';');
        }
    }
    
} else {
    // ==========================================
    // REPORT FORMAT: Full report with headers and totals
    // ==========================================
    
    // Project header info
    fputcsv($output, ['RENCANA ANGGARAN BIAYA (RAB)'], ';');
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
    fputcsv($output, ['No', 'URAIAN PEKERJAAN', 'SAT', 'VOLUME', 'HARGA SATUAN (Rp)', 'JUMLAH HARGA (Rp)', 'KODE AHSP'], ';');
    
    $grandTotal = 0;
    $currentCat = '';
    $catTotal = 0;
    $itemNum = 0;
    
    foreach ($rabItems as $item) {
        // Check for new category
        if ($currentCat !== $item['cat_code']) {
            // Print previous category total if not first
            if ($currentCat !== '') {
                fputcsv($output, ['', '', '', '', 'Jumlah Total ' . $currentCat, number_format($catTotal, 2, ',', '.'), ''], ';');
                fputcsv($output, [''], ';');
            }
            
            // Print category header
            $currentCat = $item['cat_code'];
            $catTotal = 0;
            $itemNum = 0;
            fputcsv($output, [$item['cat_code'], strtoupper($item['cat_name']), '', '', '', '', ''], ';');
        }
        
        $itemNum++;
        
        // Apply overhead markup to unit price (same as rab.php calculation)
        $unitPrice = $item['unit_price'] * (1 + ($overheadPct / 100));
        $totalPrice = $item['volume'] * $unitPrice;
        
        $catTotal += $totalPrice;
        $grandTotal += $totalPrice;
        
        fputcsv($output, [
            $itemNum,
            $item['sub_name'],
            $item['unit'],
            number_format($item['volume'], 2, ',', '.'),
            number_format($unitPrice, 2, ',', '.'),
            number_format($totalPrice, 2, ',', '.'),
            $item['ahsp_code'] ?? ''
        ], ';');
    }
    
    // Last category total
    if ($currentCat !== '') {
        fputcsv($output, ['', '', '', '', 'Jumlah Total ' . $currentCat, number_format($catTotal, 2, ',', '.'), ''], ';');
    }
    
    fputcsv($output, [''], ';');
    
    // PPN calculation
    $ppnPercentage = $project['ppn_percentage'];
    $ppnAmount = $grandTotal * ($ppnPercentage / 100);
    $totalWithPpn = $grandTotal + $ppnAmount;
    $totalRounded = ceil($totalWithPpn / 10) * 10;
    
    // Footer totals
    fputcsv($output, ['', '', '', '', 'JUMLAH TOTAL', number_format($grandTotal, 2, ',', '.'), ''], ';');
    fputcsv($output, ['', '', '', '', 'PPN ' . number_format($ppnPercentage, 2, ',', '.') . '%', number_format($ppnAmount, 2, ',', '.'), ''], ';');
    fputcsv($output, ['', '', '', '', 'JUMLAH TOTAL (TERMASUK PPN)', number_format($totalWithPpn, 2, ',', '.'), ''], ';');
    fputcsv($output, ['', '', '', '', 'JUMLAH TOTAL DIBULATKAN', number_format($totalRounded, 0, ',', '.'), ''], ';');
}

fclose($output);
exit;
