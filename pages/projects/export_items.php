<?php
/**
 * Export Items to CSV
 * PCM - Project Cost Management System
 * Format matches import template exactly
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();
requireAdmin();

$projectId = $_GET['project_id'] ?? null;
$exportType = $_GET['type'] ?? 'rab'; // 'rab' or 'rap'

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

// Get all items for this project based on type
$tableName = ($exportType === 'rap') ? 'project_items_rap' : 'project_items';
$items = dbGetAll("
    SELECT item_code, name, brand, category, unit, price, actual_price
    FROM {$tableName}
    WHERE project_id = ?
    ORDER BY category, item_code
", [$projectId]);

// Generate filename
$typeLabel = ($exportType === 'rap') ? 'rap_items' : 'items';
$filename = $typeLabel . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $project['name']) . '_' . date('Ymd_His') . '.csv';

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Open output stream
$output = fopen('php://output', 'w');

// Add BOM for Excel UTF-8 compatibility
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Write header row - matches import template
fputcsv($output, [
    'Kode',
    'Nama Utama',
    'Jenis',
    'Merk',
    'Kategori',
    'Satuan',
    'Harga PU',
    'Harga Aktual'
], ';');

// Write data rows
foreach ($items as $item) {
    // Parse name to extract Nama Utama and Jenis
    // Import format combines them as "Nama Utama - Jenis"
    $nameParts = explode(' - ', $item['name'], 2);
    $namaUtama = $nameParts[0];
    $jenis = $nameParts[1] ?? '';
    
    // Format prices with Indonesian format (dots for thousands, comma for decimal)
    // Example: 15142.86 becomes "15.142,86" (not "15.142.86.00")
    $priceFormatted = formatNumber($item['price'] ?? 0, 2);
    $actualPriceFormatted = !empty($item['actual_price']) ? formatNumber($item['actual_price'], 2) : '';
    
    fputcsv($output, [
        $item['item_code'] ?? '',
        $namaUtama,
        $jenis,
        $item['brand'] ?? '',
        $item['category'] ?? '',
        $item['unit'] ?? '',
        $priceFormatted,
        $actualPriceFormatted
    ], ';');
}

fclose($output);
exit;
