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
requirePermission('reports.export');

$projectId = $_GET['project_id'] ?? null;
$exportType = $_GET['type'] ?? 'rab'; // 'rab' or 'rap'

if (!$projectId || !canAccessProject($projectId)) {
    setFlash('error', 'Anda tidak memiliki akses ke proyek ini!');
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

// Get all AHSP with their details for this project based on type
$ahspTable = ($exportType === 'rap') ? 'project_ahsp_rap' : 'project_ahsp';
$ahspList = dbGetAll("
    SELECT a.id, a.ahsp_code, a.work_name, a.unit
    FROM {$ahspTable} a
    WHERE a.project_id = ?
    ORDER BY a.ahsp_code
", [$projectId]);

$filename = "AHSP_Project_" . preg_replace('/[^a-zA-Z0-9]/', '_', $project['name']) . "_" . date('Y-m-d') . ".csv";

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

fputcsv($output, ['Kode AHSP', 'Uraian Pekerjaan', 'Satuan', 'Harga Satuan']);

$ahsps = dbGetAll("SELECT * FROM project_ahsp WHERE project_id = ? ORDER BY ahsp_code ASC", [$projectId]);

foreach ($ahsps as $ahsp) {
    // Write AHSP header row
    fputcsv($output, [
        $ahsp['ahsp_code'] ?? '',
        $ahsp['work_name'] ?? '',
        '',
        $ahsp['unit'] ?? '',
        '',
        ''
    ], ';');
    
    // Get details for this AHSP based on type
    if ($exportType === 'rap') {
        $details = dbGetAll("
            SELECT d.coefficient, i.item_code, i.name as item_name
            FROM project_ahsp_details_rap d
            JOIN project_items_rap i ON d.item_id = i.id
            WHERE d.ahsp_id = ?
            ORDER BY i.category, i.item_code
        ", [$ahsp['id']]);
    } else {
        $details = dbGetAll("
            SELECT d.coefficient, i.item_code, i.name as item_name
            FROM project_ahsp_details d
            JOIN project_items i ON d.item_id = i.id
            WHERE d.ahsp_id = ?
            ORDER BY i.category, i.item_code
        ", [$ahsp['id']]);
    }
    
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
