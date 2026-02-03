<?php
/**
 * Export AHSP to CSV
 * PCM - Project Cost Management System
 * Format matches import template exactly
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();
requireAdmin();

$projectId = $_GET['project_id'] ?? null;

if (!$projectId) {
    setFlash('error', 'Project ID tidak ditemukan!');
    header('Location: index.php');
    exit;
}

// Get project info
$project = dbGetRow("SELECT * FROM projects WHERE id = ?", [$projectId]);

if (!$project) {
    setFlash('error', 'Proyek tidak ditemukan!');
    header('Location: index.php');
    exit;
}

// Get all AHSP with their details for this project
$ahspList = dbGetAll("
    SELECT a.id, a.ahsp_code, a.work_name, a.unit
    FROM project_ahsp a
    WHERE a.project_id = ?
    ORDER BY a.ahsp_code
", [$projectId]);

// Generate filename
$filename = 'ahsp_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $project['name']) . '_' . date('Ymd_His') . '.csv';

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Open output stream
$output = fopen('php://output', 'w');

// Add BOM for Excel UTF-8 compatibility
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Write header row (matches import template columns A-F)
fputcsv($output, [
    'Kode AHSP',
    'Nama AHSP',
    '',
    'Satuan/Kode Item',
    '',
    'Koefisien'
], ';');

// Write data rows
foreach ($ahspList as $ahsp) {
    // Write AHSP header row
    fputcsv($output, [
        $ahsp['ahsp_code'] ?? '',
        $ahsp['work_name'] ?? '',
        '',
        $ahsp['unit'] ?? '',
        '',
        ''
    ], ';');
    
    // Get details for this AHSP
    $details = dbGetAll("
        SELECT d.coefficient, i.item_code, i.name as item_name
        FROM project_ahsp_details d
        JOIN project_items i ON d.item_id = i.id
        WHERE d.ahsp_id = ?
        ORDER BY i.category, i.item_code
    ", [$ahsp['id']]);
    
    // Write item rows
    foreach ($details as $detail) {
        fputcsv($output, [
            '',
            '',
            '',
            $detail['item_code'] ?? '',
            '',
            number_format($detail['coefficient'], 6, ',', '')
        ], ';');
    }
}

fclose($output);
exit;
