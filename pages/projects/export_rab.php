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
requirePermission('rab.view');

$projectId = $_GET['id'] ?? null;
$format = $_GET['format'] ?? 'report'; // 'report' or 'import'

if (!$projectId || !canAccessProject($projectId)) {
    setFlash('error', 'Anda tidak memiliki akses ke proyek ini!');
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
$overheadPct = getProjectOverheadProfitPct($project);

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

ensureRabHeadSubsTableExists();

// Get RAB data with AHSP codes and Head-Sub info
$rabItems = dbGetAll("
    SELECT 
        rs.volume,
        rs.unit_price,
        rs.code as sub_code, rs.name as sub_name, rs.unit,
        rc.code as cat_code, rc.name as cat_name,
        hs.code as hs_code, hs.name as hs_name,
        pa.ahsp_code
    FROM rab_subcategories rs
    JOIN rab_categories rc ON rs.category_id = rc.id
    LEFT JOIN rab_head_subs hs ON rc.head_sub_id = hs.id
    LEFT JOIN project_ahsp pa ON rs.ahsp_id = pa.id
    WHERE rc.project_id = ?
    ORDER BY COALESCE(hs.sort_order, 99999), hs.id, rc.sort_order, LENGTH(rc.code), rc.code, rs.sort_order, LENGTH(rs.code), rs.code
", [$projectId]);

if ($format === 'import') {
    // ==========================================
    // IMPORT FORMAT: Head-Sub | Nama Kategori | Kode AHSP | Volume
    // ==========================================
    
    // Header row
    fputcsv($output, ['Head-Sub', 'Nama Kategori', 'Kode AHSP', 'Volume'], ';');
    
    $currentCat = '';
    $currentHs = '';
    
    foreach ($rabItems as $item) {
        $hsDisplay = $item['hs_name'] ? ($item['hs_code'] ? $item['hs_code'] . ' - ' . $item['hs_name'] : $item['hs_name']) : '';
        if ($currentCat !== $item['cat_name'] || $currentHs !== $hsDisplay) {
            $currentCat = $item['cat_name'];
            $currentHs = $hsDisplay;
            fputcsv($output, [
                $hsDisplay,
                $item['cat_name'],
                $item['ahsp_code'] ?? '',
                number_format($item['volume'], 2, ',', '')
            ], ';');
        } else {
            fputcsv($output, [
                '',
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
    $currentHs = null;
    $currentCat = '';
    $catTotal = 0;
    $hsTotal = 0;
    $itemNum = 0;
    
    foreach ($rabItems as $item) {
        $hsName = $item['hs_name'] ? ($item['hs_code'] ? $item['hs_code'] . ' - ' . $item['hs_name'] : $item['hs_name']) : '';

        // Check for new Head-Sub
        if ($currentHs !== $hsName) {
            if ($currentCat !== '') {
                fputcsv($output, ['', '', '', '', 'Jumlah Total ' . $currentCat, number_format($catTotal, 2, ',', '.'), ''], ';');
                $currentCat = '';
            }
            if ($currentHs !== null && $currentHs !== '') {
                fputcsv($output, ['', '', '', '', 'JUMLAH HEAD-SUB ' . $currentHs, number_format($hsTotal, 2, ',', '.'), ''], ';');
                fputcsv($output, [''], ';');
            }
            $currentHs = $hsName;
            $hsTotal = 0;
            if (!empty($hsName)) {
                fputcsv($output, ['HEAD-SUB', strtoupper($hsName), '', '', '', '', ''], ';');
            }
        }

        // Check for new category
        if ($currentCat !== $item['cat_code']) {
            if ($currentCat !== '') {
                fputcsv($output, ['', '', '', '', 'Jumlah Total ' . $currentCat, number_format($catTotal, 2, ',', '.'), ''], ';');
            }
            
            $currentCat = $item['cat_code'];
            $catTotal = 0;
            $itemNum = 0;
            fputcsv($output, [$item['cat_code'], strtoupper($item['cat_name']), '', '', '', '', ''], ';');
        }
        
        $itemNum++;
        
        $unitPrice = $item['unit_price'] * (1 + ($overheadPct / 100));
        $totalPrice = $item['volume'] * $unitPrice;
        
        $catTotal += $totalPrice;
        $hsTotal += $totalPrice;
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
    
    // Last totals
    if ($currentCat !== '') {
        fputcsv($output, ['', '', '', '', 'Jumlah Total ' . $currentCat, number_format($catTotal, 2, ',', '.'), ''], ';');
    }
    if ($currentHs !== null && $currentHs !== '') {
        fputcsv($output, ['', '', '', '', 'JUMLAH HEAD-SUB ' . $currentHs, number_format($hsTotal, 2, ',', '.'), ''], ';');
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
