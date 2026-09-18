<?php
/**
 * AJAX Endpoint for Kalkulator Tenaga Kerja
 * PCC - Project Cost Control System
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 1. Get Projects accessible by the current user
if ($action === 'get_projects') {
    $reqViewMode = getProjectViewMode();
    if ($reqViewMode === 'all') {
        $projects = dbGetAll("SELECT id, name FROM projects WHERE status = 'on_progress' ORDER BY name");
    } elseif ($reqViewMode === 'assigned') {
        $projects = dbGetAll("
            SELECT p.id, p.name 
            FROM projects p 
            JOIN project_assignments pa ON pa.project_id = p.id 
            WHERE p.status = 'on_progress' AND pa.user_id = ? AND pa.is_active = 1 
            ORDER BY p.name
        ", [getCurrentUserId()]);
    } else {
        $projects = [];
    }
    
    echo json_encode(['success' => true, 'data' => $projects]);
    exit;
}

// All subsequent actions require projectId
$projectId = intval($_GET['project_id'] ?? $_POST['project_id'] ?? 0);

if (!$projectId || !canAccessProject($projectId)) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak atau proyek tidak valid']);
    exit;
}

// 2. Get AHSP List with RAP Volume for the project
if ($action === 'get_ahsps') {
    // Get work items (subcategories) linked to AHSP in this project with their RAP volume
    $subcatAhsps = dbGetAll("
        SELECT 
            rs.id as subcategory_id,
            rs.code as subcat_code,
            rs.name as subcat_name,
            rs.unit as subcat_unit,
            COALESCE(rap.volume, rs.volume) as rap_volume,
            pa.id as ahsp_id,
            pa.ahsp_code,
            pa.work_name as ahsp_work_name,
            pa.unit as ahsp_unit,
            rc.code as category_code,
            rc.name as category_name
        FROM rab_subcategories rs
        JOIN rab_categories rc ON rs.category_id = rc.id
        JOIN project_ahsp pa ON rs.ahsp_id = pa.id
        LEFT JOIN rap_items rap ON rap.subcategory_id = rs.id
        WHERE rc.project_id = ?
        ORDER BY rc.sort_order, rc.code, rs.sort_order, rs.code
    ", [$projectId]);

    $data = [];
    foreach ($subcatAhsps as $row) {
        $volume = floatval($row['rap_volume']);
        $unit = $row['subcat_unit'] ?: $row['ahsp_unit'] ?: 'm2';
        
        $label = (!empty($row['ahsp_code']) ? '[' . $row['ahsp_code'] . '] ' : '') . $row['subcat_name'];
        if ($volume > 0) {
            $label .= ' (' . number_format($volume, 2, ',', '.') . ' ' . $unit . ')';
        }

        $data[] = [
            'subcategory_id' => intval($row['subcategory_id']),
            'ahsp_id' => intval($row['ahsp_id']),
            'ahsp_code' => $row['ahsp_code'],
            'work_name' => $row['subcat_name'],
            'ahsp_name' => $row['ahsp_work_name'],
            'category_name' => $row['category_name'],
            'volume' => $volume,
            'unit' => $unit,
            'display_label' => $label
        ];
    }

    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

// 3. Get Labor Items & Coefficients for a specific selected AHSP / Subcategory
if ($action === 'get_items') {
    $subcategoryId = intval($_GET['subcategory_id'] ?? $_POST['subcategory_id'] ?? 0);
    $ahspId = intval($_GET['ahsp_id'] ?? $_POST['ahsp_id'] ?? 0);

    if (!$subcategoryId && !$ahspId) {
        echo json_encode(['success' => false, 'message' => 'AHSP tidak dipilih']);
        exit;
    }

    $volume = 0;
    $unit = '';

    // Fetch volume and unit from RAP
    if ($subcategoryId > 0) {
        $subcatInfo = dbGetRow("
            SELECT rs.id, rs.unit, COALESCE(rap.volume, rs.volume) as rap_volume, rs.ahsp_id
            FROM rab_subcategories rs
            LEFT JOIN rap_items rap ON rap.subcategory_id = rs.id
            WHERE rs.id = ?
        ", [$subcategoryId]);

        if ($subcatInfo) {
            $volume = floatval($subcatInfo['rap_volume']);
            $unit = $subcatInfo['unit'];
            if (!$ahspId && !empty($subcatInfo['ahsp_id'])) {
                $ahspId = intval($subcatInfo['ahsp_id']);
            }
        }
    }

    // Fetch labor items and their specific coefficients from Detail AHSP
    $items = [];
    if ($subcategoryId > 0) {
        // Try to query via RAP detail table first (matching subcategory)
        $rawItems = dbGetAll("
            SELECT d.id as detail_id, 
                   pir.id as item_id, 
                   pir.item_code, 
                   pir.name as item_name, 
                   pir.unit as item_unit,
                   d.coefficient
            FROM rab_subcategories rs
            JOIN project_ahsp pa ON rs.ahsp_id = pa.id
            JOIN project_ahsp_rap par ON par.ahsp_code = pa.ahsp_code AND par.project_id = pa.project_id
            JOIN project_ahsp_details_rap d ON d.ahsp_id = par.id
            JOIN project_items_rap pir ON d.item_id = pir.id
            WHERE rs.id = ? AND pir.category = 'upah'
            ORDER BY pir.name
        ", [$subcategoryId]);

        // Fallback to project_ahsp_details if empty
        if (empty($rawItems)) {
            $rawItems = dbGetAll("
                SELECT d.id as detail_id, 
                       pi.id as item_id, 
                       pi.item_code, 
                       pi.name as item_name, 
                       pi.unit as item_unit,
                       d.coefficient
                FROM rab_subcategories rs
                JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                JOIN project_ahsp_details d ON d.ahsp_id = pa.id
                JOIN project_items pi ON d.item_id = pi.id
                WHERE rs.id = ? AND pi.category = 'upah'
                ORDER BY pi.name
            ", [$subcategoryId]);
        }

        foreach ($rawItems as $it) {
            $items[] = [
                'detail_id' => intval($it['detail_id']),
                'item_id' => intval($it['item_id']),
                'item_code' => $it['item_code'],
                'item_name' => $it['item_name'],
                'item_unit' => $it['item_unit'] ?: 'OH',
                'coefficient' => floatval($it['coefficient'])
            ];
        }
    }

    // If still no items and we have ahsp_id, query by ahsp_id directly
    if (empty($items) && $ahspId > 0) {
        $rawItems = dbGetAll("
            SELECT d.id as detail_id, 
                   pir.id as item_id, 
                   pir.item_code, 
                   pir.name as item_name, 
                   pir.unit as item_unit,
                   d.coefficient
            FROM project_ahsp pa
            JOIN project_ahsp_rap par ON par.ahsp_code = pa.ahsp_code AND par.project_id = pa.project_id
            JOIN project_ahsp_details_rap d ON d.ahsp_id = par.id
            JOIN project_items_rap pir ON d.item_id = pir.id
            WHERE pa.id = ? AND pir.category = 'upah'
            ORDER BY pir.name
        ", [$ahspId]);

        if (empty($rawItems)) {
            $rawItems = dbGetAll("
                SELECT d.id as detail_id, 
                       pi.id as item_id, 
                       pi.item_code, 
                       pi.name as item_name, 
                       pi.unit as item_unit,
                       d.coefficient
                FROM project_ahsp pa
                JOIN project_ahsp_details d ON d.ahsp_id = pa.id
                JOIN project_items pi ON d.item_id = pi.id
                WHERE pa.id = ? AND pi.category = 'upah'
                ORDER BY pi.name
            ", [$ahspId]);
        }

        foreach ($rawItems as $it) {
            $items[] = [
                'detail_id' => intval($it['detail_id']),
                'item_id' => intval($it['item_id']),
                'item_code' => $it['item_code'],
                'item_name' => $it['item_name'],
                'item_unit' => $it['item_unit'] ?: 'OH',
                'coefficient' => floatval($it['coefficient'])
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'volume' => $volume,
            'unit' => $unit,
            'items' => $items
        ]
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
exit;
