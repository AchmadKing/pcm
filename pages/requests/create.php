<?php
/**
 * Create Request - Dynamic Form with Category/Subcategory Selection
 * PCC - Project Cost Control System
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();
requirePermission('requests.create');

$projectId = $_GET['project_id'] ?? null;
$resubmitId = intval($_GET['resubmit_id'] ?? 0);
$error = '';

// Auto-detect project_id from resubmit_id if not explicitly provided
if (!$projectId && $resubmitId > 0) {
    $foundReq = dbGetRow("SELECT project_id FROM requests WHERE id = ?", [$resubmitId]);
    if ($foundReq) {
        $projectId = intval($foundReq['project_id']);
    }
}

// =====================================
// AJAX HANDLERS (must be before any output)
// =====================================

// AJAX: Get categories for a project
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_categories' && $projectId) {
    if (!canAccessProject($projectId)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
        exit;
    }
    header('Content-Type: application/json');
    $categories = dbGetAll("
        SELECT DISTINCT rc.id, rc.code, rc.name 
        FROM rab_categories rc
        JOIN rab_subcategories rs ON rs.category_id = rc.id
        WHERE rc.project_id = ?
        ORDER BY rc.sort_order, rc.code
    ", [$projectId]);
    echo json_encode(['success' => true, 'data' => $categories]);
    exit;
}

// AJAX: Get subcategories for a category
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_subcategories' && isset($_GET['category_id'])) {
    header('Content-Type: application/json');
    $categoryId = intval($_GET['category_id']);
    $subcategories = dbGetAll("
        SELECT rs.id, rs.code, rs.name, rs.unit,
               COALESCE(rap.total_price, rs.volume * rs.unit_price) as rap_total
        FROM rab_subcategories rs
        LEFT JOIN rap_items rap ON rap.subcategory_id = rs.id
        WHERE rs.category_id = ?
        ORDER BY rs.sort_order, rs.code
    ", [$categoryId]);
    echo json_encode(['success' => true, 'data' => $subcategories]);
    exit;
}

// AJAX: Get AHSP items for a subcategory
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_ahsp_items' && isset($_GET['subcategory_id'])) {
    header('Content-Type: application/json');
    $subcategoryId = intval($_GET['subcategory_id']);
    
    // Get AHSP RAP details from Master Data RAP tables
    $items = dbGetAll("
        SELECT d.id, pir.category as item_type, pir.item_code, pir.name, pir.unit, 
               d.coefficient, COALESCE(d.unit_price, pir.price) as unit_price, pir.actual_price
        FROM rab_subcategories rs
        JOIN project_ahsp pa ON rs.ahsp_id = pa.id
        JOIN project_ahsp_rap par ON par.ahsp_code = pa.ahsp_code AND par.project_id = pa.project_id
        JOIN project_ahsp_details_rap d ON d.ahsp_id = par.id
        JOIN project_items_rap pir ON d.item_id = pir.id
        WHERE rs.id = ?
        ORDER BY pir.category, pir.name
    ", [$subcategoryId]);
    
    echo json_encode(['success' => true, 'data' => $items]);
    exit;
}

// AJAX: Get items by type for a subcategory (filtered by item_type)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_items_by_type' && isset($_GET['subcategory_id']) && isset($_GET['item_type'])) {
    header('Content-Type: application/json');
    $subcategoryId = intval($_GET['subcategory_id']);
    $itemType = $_GET['item_type'];
    
    // Validate item_type
    if (!in_array($itemType, ['upah', 'material', 'alat'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid item type']);
        exit;
    }
    
    // Get AHSP RAP details filtered by item_type from Master Data RAP tables
    $items = dbGetAll("
        SELECT d.id, pir.category as item_type, pir.item_code, pir.name, pir.unit, 
               d.coefficient, COALESCE(d.unit_price, pir.price) as unit_price, pir.actual_price
        FROM rab_subcategories rs
        JOIN project_ahsp pa ON rs.ahsp_id = pa.id
        JOIN project_ahsp_rap par ON par.ahsp_code = pa.ahsp_code AND par.project_id = pa.project_id
        JOIN project_ahsp_details_rap d ON d.ahsp_id = par.id
        JOIN project_items_rap pir ON d.item_id = pir.id
        WHERE rs.id = ? AND pir.category = ?
        ORDER BY pir.name
    ", [$subcategoryId, $itemType]);
    
    echo json_encode(['success' => true, 'data' => $items]);
    exit;
}

// AJAX: Get RAP pekerjaan for checkbox selection (grouped by category)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_rap_pekerjaan' && $projectId) {
    if (!canAccessProject($projectId)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
        exit;
    }
    header('Content-Type: application/json');
    
    // Get RAP data: categories + subcategories (representing work items)
    $rapItems = dbGetAll("
        SELECT 
            rc.id as category_id,
            rc.code as category_code,
            rc.name as category_name,
            rs.id as subcategory_id,
            rs.code as item_code,
            rs.name as item_name
        FROM rab_categories rc
        JOIN rab_subcategories rs ON rs.category_id = rc.id
        JOIN rap_items rap ON rap.subcategory_id = rs.id
        WHERE rc.project_id = ?
        ORDER BY rc.sort_order, rc.code, rs.sort_order, rs.code
    ", [$projectId]);
    
    echo json_encode(['success' => true, 'data' => $rapItems]);
    exit;
}

// AJAX: Get items from selected RAP pekerjaan (by subcategory IDs and item type)
// Returns items grouped by selected subcategory (pekerjaan)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_items_by_selected_rap' && isset($_GET['subcategory_ids']) && isset($_GET['item_type'])) {
    header('Content-Type: application/json');
    
    $subcategoryIds = array_filter(array_map('intval', explode(',', $_GET['subcategory_ids'])));
    $itemType = $_GET['item_type'];
    
    // Validate item_type
    if (!in_array($itemType, ['upah', 'material', 'alat'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid item type']);
        exit;
    }
    
    if (empty($subcategoryIds)) {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }
    
    // Build placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($subcategoryIds), '?'));
    
    // Get info of all selected subcategories in proper order
    $subcatInfo = dbGetAll("
        SELECT rs.id, rs.code, rs.name, rs.unit, rs.volume as subcat_volume, rs.category_id, rc.code as category_code, rc.name as category_name
        FROM rab_subcategories rs
        JOIN rab_categories rc ON rs.category_id = rc.id
        WHERE rs.id IN ($placeholders)
        ORDER BY rc.sort_order, rc.code, rs.sort_order, rs.code
    ", $subcategoryIds);
    
    // Get all items per subcategory from Master Data RAP tables
    $params = array_merge($subcategoryIds, [$itemType]);
    $rawItems = dbGetAll("
        SELECT d.id, pir.category as item_type, pir.item_code, pir.name, pir.unit, 
               d.coefficient as ahsp_coefficient, COALESCE(d.unit_price, pir.price) as unit_price, pir.actual_price,
               rs.id as subcategory_id, rs.code as subcat_code, rs.name as subcat_name, rs.unit as subcat_unit,
               rs.category_id,
               COALESCE(ri.volume, rs.volume, 0) as rap_volume,
               (d.coefficient * COALESCE(ri.volume, rs.volume, 0)) as rap_qty,
               (SELECT COALESCE(SUM(reqi2.coefficient), 0) 
                FROM request_items reqi2 
                JOIN requests r ON reqi2.request_id = r.id 
                WHERE reqi2.item_code = pir.item_code 
                  AND reqi2.subcategory_id = rs.id
                  AND r.status IN ('approved','pending','pm_approved')
               ) as used_qty
        FROM rab_subcategories rs
        JOIN project_ahsp pa ON rs.ahsp_id = pa.id
        JOIN project_ahsp_rap par ON par.ahsp_code = pa.ahsp_code AND par.project_id = pa.project_id
        JOIN project_ahsp_details_rap d ON d.ahsp_id = par.id
        JOIN project_items_rap pir ON d.item_id = pir.id
        LEFT JOIN rap_items ri ON ri.subcategory_id = rs.id
        WHERE rs.id IN ($placeholders) AND pir.category = ?
        ORDER BY rs.sort_order, rs.code, pir.name
    ", $params);
    
    // Group items by subcategory_id
    $itemsBySubcat = [];
    foreach ($rawItems as $item) {
        $subId = intval($item['subcategory_id']);
        $rapQty = floatval($item['rap_qty']);
        $usedQty = floatval($item['used_qty']);
        $sisaQty = $rapQty - $usedQty;
        $ahspCoef = floatval($item['ahsp_coefficient']);
        $rapVolume = floatval($item['rap_volume']);
        $sisaVolume = $ahspCoef > 0 ? ($sisaQty / $ahspCoef) : 0;
        
        $itemsBySubcat[$subId][] = [
            'id' => $item['id'],
            'category_id' => $item['category_id'],
            'subcategory_id' => $subId,
            'subcat_code' => $item['subcat_code'],
            'subcat_name' => $item['subcat_name'],
            'subcat_unit' => $item['subcat_unit'],
            'item_code' => $item['item_code'],
            'name' => $item['name'],
            'unit' => ($itemType === 'upah' && !empty($item['subcat_unit']) ? $item['subcat_unit'] : $item['unit']),
            'item_type' => $item['item_type'],
            'unit_price' => floatval($item['unit_price']),
            'actual_price' => floatval($item['actual_price']),
            'ahsp_coefficient' => $ahspCoef,
            'rap_qty' => $rapQty,
            'used_qty' => $usedQty,
            'sisa_qty' => $sisaQty,
            'rap_volume' => $rapVolume,
            'sisa_volume' => $sisaVolume
        ];
    }
    
    // Structure result as list of selected pekerjaan with their items
    $result = [];
    foreach ($subcatInfo as $sc) {
        $subId = intval($sc['id']);
        $subcatItems = $itemsBySubcat[$subId] ?? [];
        $subcatVolume = floatval($sc['subcat_volume'] ?? 0);
        
        // Calculate RAP unit cost for this item_type: sum(ahsp_coefficient * unit_price)
        $rapUnitCost = 0.0;
        $minSisaVolume = $subcatVolume;
        foreach ($subcatItems as $sItem) {
            $rapUnitCost += ($sItem['ahsp_coefficient'] * $sItem['unit_price']);
            if ($sItem['sisa_volume'] < $minSisaVolume) {
                $minSisaVolume = $sItem['sisa_volume'];
            }
        }
        
        // Also deduct volume used by Harian requests for this subcategory
        $harianUsedRow = dbGetRow("
            SELECT COALESCE(SUM(h_vol), 0) as used_vol FROM (
                SELECT MAX(reqi.work_volume) as h_vol
                FROM request_items reqi
                JOIN requests r ON reqi.request_id = r.id
                WHERE reqi.subcategory_id = ? 
                  AND reqi.work_volume IS NOT NULL
                  AND r.status IN ('pending', 'pm_approved', 'approved')
                GROUP BY r.id
            ) t
        ", [$subId]);
        $usedHarianVol = floatval($harianUsedRow['used_vol'] ?? 0);
        $sharedSisaVolume = max(0, $minSisaVolume - $usedHarianVol);
        $rapTotalCost = $rapUnitCost * $subcatVolume;
        
        // Attach aggregated values to items for easy client-side access
        foreach ($subcatItems as &$sItem) {
            $sItem['rap_unit_cost'] = $rapUnitCost;
            $sItem['rap_total_cost'] = $rapTotalCost;
            $sItem['subcat_volume'] = $subcatVolume;
            $sItem['sisa_volume'] = $sharedSisaVolume;
        }
        unset($sItem);
        
        $result[] = [
            'subcategory_id' => $subId,
            'subcat_code' => $sc['code'],
            'subcat_name' => $sc['name'],
            'subcat_unit' => $sc['unit'] ?: "m'",
            'subcat_volume' => $subcatVolume,
            'rap_unit_cost' => $rapUnitCost,
            'rap_total_cost' => $rapTotalCost,
            'sisa_volume' => $sharedSisaVolume,
            'category_id' => $sc['category_id'],
            'category_code' => $sc['category_code'],
            'category_name' => $sc['category_name'],
            'items' => $subcatItems
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $result]);
    exit;
}

// Get on-progress projects based on project access mode
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

$project = null;
$categories = [];
$weeklyRanges = [];
if ($projectId) {
    if (!canAccessProject($projectId)) {
        setFlash('error', 'Anda tidak memiliki akses ke proyek ini!');
        header('Location: index.php');
        exit;
    }
    
    $project = dbGetRow("SELECT * FROM projects WHERE id = ? AND status = 'on_progress'", [$projectId]);
    
    if (!$project) {
        setFlash('error', 'Proyek tidak ditemukan atau belum dimulai!');
        header('Location: index.php');
        exit;
    }
    
    // Check if project requests are locked
    if (!empty($project['request_locked'])) {
        setFlash('error', 'Pengajuan dana untuk proyek ini sedang dikunci. Hubungi admin untuk membuka kunci.');
        header('Location: ../projects/view.php?id=' . $projectId . '&tab=requests');
        exit;
    }
    
    // Get categories that have RAP data
    $categories = dbGetAll("
        SELECT DISTINCT rc.id, rc.code, rc.name 
        FROM rab_categories rc
        JOIN rab_subcategories rs ON rs.category_id = rc.id
        WHERE rc.project_id = ?
        ORDER BY rc.sort_order, rc.code
    ", [$projectId]);
    
    // Generate weekly ranges for target week selection
    if (!empty($project['start_date']) && !empty($project['duration_days'])) {
        $weeklyRanges = generateWeeklyRanges($project['start_date'], $project['duration_days']);
    }
    
    // Fetch resubmit request data if resubmit_id is provided
    $resubmitReq = null;
    $resubmitItems = [];
    $resubmitAttachments = [];
    $resubmitSubcatIds = [];

    if ($resubmitId > 0 && $projectId) {
        $resubmitReq = dbGetRow("
            SELECT req.*, p.name as project_name 
            FROM requests req 
            JOIN projects p ON req.project_id = p.id 
            WHERE req.id = ? AND req.project_id = ?
        ", [$resubmitId, $projectId]);
        
        if ($resubmitReq) {
            $rawResubmitItems = dbGetAll("
                SELECT reqi.*, rs.code as subcategory_code, rs.name as subcategory_name
                FROM request_items reqi
                LEFT JOIN rab_subcategories rs ON reqi.subcategory_id = rs.id
                WHERE reqi.request_id = ?
                ORDER BY reqi.id
            ", [$resubmitId]);
            
            foreach ($rawResubmitItems as $item) {
                $subcatDetails = json_decode($item['subcat_details'] ?? '', true);
                if (!empty($subcatDetails) && is_array($subcatDetails)) {
                    foreach ($subcatDetails as $sd) {
                        if (!empty($sd['subcategory_id'])) {
                            $resubmitSubcatIds[intval($sd['subcategory_id'])] = true;
                        }
                    }
                } elseif (!empty($item['subcategory_id'])) {
                    $resubmitSubcatIds[intval($item['subcategory_id'])] = true;
                }
                
                // Calculate current rap_unit_price and sisa_qty for this item
                $rapUnitPrice = 0;
                $sisaQty = 0;
                if (!empty($item['item_code'])) {
                    // Find RAP price
                    $itemRap = dbGetRow("
                        SELECT pir.category as item_type, COALESCE(pir.actual_price, pir.price) as unit_price, pir.price as base_price
                        FROM project_items_rap pir
                        JOIN project_ahsp_rap par ON par.project_id = pir.project_id
                        WHERE pir.project_id = ? AND pir.item_code = ?
                        LIMIT 1
                    ", [$projectId, $item['item_code']]);
                    
                    if (!$itemRap) {
                        $itemRap = dbGetRow("SELECT category as item_type, price as unit_price FROM project_items WHERE project_id = ? AND item_code = ? LIMIT 1", [$projectId, $item['item_code']]);
                    }
                    
                    if ($itemRap) {
                        $rapUnitPrice = floatval($itemRap['unit_price'] ?: $itemRap['base_price'] ?: 0);
                    }
                    
                    // Calculate sisa_qty
                    if (!empty($subcatDetails) && is_array($subcatDetails)) {
                        $subIds = array_column($subcatDetails, 'subcategory_id');
                        if (!empty($subIds)) {
                            $subPlaceholders = implode(',', array_fill(0, count($subIds), '?'));
                            $calc = dbGetRow("
                                SELECT 
                                    SUM(d.coefficient * COALESCE(ri.volume, 0)) as total_rap_qty,
                                    (SELECT COALESCE(SUM(reqi2.coefficient), 0) 
                                     FROM request_items reqi2 
                                     JOIN requests r ON reqi2.request_id = r.id 
                                     WHERE reqi2.item_code = ? 
                                       AND reqi2.subcategory_id IN ($subPlaceholders)
                                       AND r.status IN ('approved','pending')
                                    ) as total_used_qty
                                FROM rab_subcategories rs
                                JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                                JOIN project_ahsp_rap par ON par.ahsp_code = pa.ahsp_code AND par.project_id = pa.project_id
                                JOIN project_ahsp_details_rap d ON d.ahsp_id = par.id
                                JOIN project_items_rap pir ON d.item_id = pir.id
                                LEFT JOIN rap_items ri ON ri.subcategory_id = rs.id
                                WHERE rs.id IN ($subPlaceholders) AND pir.item_code = ?
                            ", array_merge([$item['item_code']], $subIds, $subIds, [$item['item_code']]));
                            if ($calc) {
                                $sisaQty = floatval($calc['total_rap_qty']) - floatval($calc['total_used_qty']);
                            }
                        }
                    } elseif (!empty($item['subcategory_id'])) {
                        $calc = dbGetRow("
                            SELECT 
                                (d.coefficient * COALESCE(ri.volume, 0)) as total_rap_qty,
                                (SELECT COALESCE(SUM(reqi2.coefficient), 0) 
                                 FROM request_items reqi2 
                                 JOIN requests r ON reqi2.request_id = r.id 
                                 WHERE reqi2.item_code = ? 
                                   AND reqi2.subcategory_id = rs.id
                                   AND r.status IN ('approved','pending')
                                ) as total_used_qty
                            FROM rab_subcategories rs
                            JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                            JOIN project_ahsp_rap par ON par.ahsp_code = pa.ahsp_code AND par.project_id = pa.project_id
                            JOIN project_ahsp_details_rap d ON d.ahsp_id = par.id
                            JOIN project_items_rap pir ON d.item_id = pir.id
                            LEFT JOIN rap_items ri ON ri.subcategory_id = rs.id
                            WHERE rs.id = ? AND pir.item_code = ?
                        ", [$item['item_code'], $item['subcategory_id'], $item['item_code']]);
                        if ($calc) {
                            $sisaQty = floatval($calc['total_rap_qty']) - floatval($calc['total_used_qty']);
                        }
                    }
                }
                
                $resubmitItems[] = [
                    'category_id' => $item['category_id'],
                    'subcategory_id' => $item['subcategory_id'],
                    'subcat_code' => $item['subcategory_code'] ?? '',
                    'subcat_name' => $item['subcategory_name'] ?? '',
                    'item_code' => $item['item_code'] ?: '',
                    'item_type' => $item['item_type'] ?: '',
                    'work_type' => $item['work_type'] ?? ($resubmitReq['work_type'] ?? 'borongan'),
                    'work_volume' => floatval($item['work_volume'] ?? 0),
                    'work_unit' => $item['work_unit'] ?? '',
                    'work_quantity' => floatval($item['work_quantity'] ?? 0),
                    'work_quantity_unit' => $item['work_quantity_unit'] ?? '',
                    'work_duration' => floatval($item['work_duration'] ?? 0),
                    'work_duration_unit' => $item['work_duration_unit'] ?? 'Hr',
                    'work_billing_unit' => $item['work_billing_unit'] ?? '',
                    'item_name' => $item['item_name'],
                    'unit' => $item['unit'],
                    'unit_price' => floatval($item['unit_price']),
                    'coefficient' => floatval($item['coefficient'] ?: $item['quantity']),
                    'total_price' => floatval($item['total_price'] ?? 0),
                    'notes' => $item['notes'] ?? '',
                    'subcat_details' => !empty($subcatDetails) ? $subcatDetails : [],
                    'rap_unit_price' => $rapUnitPrice,
                    'sisa_qty' => $sisaQty,
                    'is_readonly' => !empty($item['item_code'])
                ];
            }
            
            // Fetch attachments
            $rawAttachments = dbGetAll("SELECT * FROM request_attachments WHERE request_id = ? ORDER BY uploaded_at", [$resubmitId]);
            $uploadDir = __DIR__ . '/../../uploads/receipts/';
            foreach ($rawAttachments as $att) {
                $filePath = $uploadDir . $att['filename'];
                if (file_exists($filePath)) {
                    $ext = strtolower(pathinfo($att['original_name'] ?: $att['filename'], PATHINFO_EXTENSION));
                    $nameWithoutExt = pathinfo($att['original_name'] ?: $att['filename'], PATHINFO_FILENAME);
                    $isImg = in_array($att['file_type'], ['image/jpeg', 'image/png', 'image/jpg', 'image/webp']) || in_array($ext, ['jpg', 'jpeg', 'png', 'webp']);
                    $isPdf = $att['file_type'] === 'application/pdf' || $ext === 'pdf';
                    
                    $resubmitAttachments[] = [
                        'id' => 'existing_' . $att['id'],
                        'attachment_id' => $att['id'],
                        'filename' => $att['filename'],
                        'customName' => $nameWithoutExt,
                        'originalName' => $att['original_name'] ?: $att['filename'],
                        'extension' => $ext,
                        'fileType' => $att['file_type'],
                        'fileSize' => intval($att['file_size']),
                        'isImage' => $isImg,
                        'isPdf' => $isPdf,
                        'previewUrl' => $baseUrl . '/uploads/receipts/' . $att['filename'],
                        'isExisting' => true
                    ];
                }
            }
        }
    }
}

// Handle form submission (via AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_request') {
    header('Content-Type: application/json');
    
    $projectId = intval($_POST['project_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $targetWeek = intval($_POST['target_week'] ?? 0);
    $workType = trim($_POST['work_type'] ?? 'borongan');
    if (!in_array($workType, ['borongan', 'harian'])) {
        $workType = 'borongan';
    }
    $directItems = json_decode($_POST['items'] ?? '[]', true);
    $nonRabItems = json_decode($_POST['non_rab_items'] ?? '[]', true);

    // Fallback if client passed single items array in old non_rab format
    if (empty($directItems) && empty($nonRabItems) && isset($_POST['request_type']) && $_POST['request_type'] === 'non_rab') {
        $nonRabItems = json_decode($_POST['items'] ?? '[]', true);
    }
    
    if (empty($targetWeek)) {
        echo json_encode(['success' => false, 'message' => 'Minggu target wajib dipilih!']);
        exit;
    }
    
    // Process Direct Cost items
    $validDirectItems = [];
    $directTotal = 0;
    if (is_array($directItems)) {
        foreach ($directItems as $item) {
            $categoryId = intval($item['category_id'] ?? 0);
            $subcategoryId = intval($item['subcategory_id'] ?? 0);
            if ($subcategoryId && !$categoryId) {
                $subcatInfoRow = dbGetRow("SELECT category_id FROM rab_subcategories WHERE id = ?", [$subcategoryId]);
                if ($subcatInfoRow) {
                    $categoryId = intval($subcatInfoRow['category_id']);
                }
            }
            $itemCode = trim($item['item_code'] ?? '');
            $itemType = trim($item['item_type'] ?? '');
            $itemName = trim($item['item_name'] ?? '');
            $itemWorkType = trim($item['work_type'] ?? $workType);
            if (!in_array($itemWorkType, ['borongan', 'harian'])) {
                $itemWorkType = $workType;
            }
            $unit = trim($item['unit'] ?? '');
            $unitPrice = floatval($item['unit_price'] ?? 0);
            $notes = trim($item['notes'] ?? '');
            $subcatDetails = trim($item['subcat_details'] ?? '');
            
            if ($subcatDetails && json_decode($subcatDetails) === null) {
                $subcatDetails = '';
            }
            
            if ($itemWorkType === 'harian') {
                $workVolume = floatval($item['work_volume'] ?? 0);
                $workUnit = trim($item['work_unit'] ?? '');
                $workQuantity = floatval($item['work_quantity'] ?? 0);
                $workQuantityUnit = trim($item['work_quantity_unit'] ?? 'orang');
                $workDuration = floatval($item['work_duration'] ?? 0);
                $workDurationUnit = trim($item['work_duration_unit'] ?? 'Hr');
                $workBillingUnit = trim($item['work_billing_unit'] ?? 'OH');
                
                if ($itemType === 'material') {
                    if ($workDuration <= 0) {
                        $workDuration = 1;
                    }
                    if (empty($workDurationUnit) || $workDurationUnit === 'Hr' || $workDurationUnit === 'Kali') {
                        $workDurationUnit = '-';
                    }
                    if (empty($workQuantityUnit)) {
                        $workQuantityUnit = $unit ?: 'ls';
                    }
                    if (empty($workBillingUnit)) {
                        $workBillingUnit = $unit ?: 'ls';
                    }
                }
                
                // Server-side calculation & integrity
                $quantity = round($workQuantity * $workDuration, 4);
                $coefficient = round($workQuantity * $workDuration, 6);
                $itemTotal = round($quantity * $unitPrice, 2);
                
                if ($workVolume > 0 && $workQuantity > 0 && $workDuration > 0 && $unitPrice > 0 && !empty($itemName)) {
                    $directTotal += $itemTotal;
                    $validDirectItems[] = [
                        'category_id' => $categoryId ?: null,
                        'subcategory_id' => $subcategoryId ?: null,
                        'subcat_details' => $subcatDetails ?: null,
                        'item_code' => $itemCode ?: null,
                        'item_type' => $itemType ?: null,
                        'work_type' => 'harian',
                        'work_volume' => $workVolume,
                        'work_unit' => $workUnit ?: null,
                        'work_quantity' => $workQuantity,
                        'work_quantity_unit' => $workQuantityUnit ?: null,
                        'work_duration' => $workDuration,
                        'work_duration_unit' => $workDurationUnit ?: 'Hr',
                        'work_billing_unit' => $workBillingUnit ?: null,
                        'item_name' => $itemName,
                        'unit' => $workBillingUnit ?: $unit,
                        'unit_price' => $unitPrice,
                        'quantity' => $quantity,
                        'coefficient' => $coefficient,
                        'total_price' => $itemTotal,
                        'notes' => $notes
                    ];
                }
            } else {
                // Borongan existing
                $coefficient = floatval($item['coefficient'] ?? 0);
                if ($coefficient > 0 && $unitPrice > 0 && !empty($itemName)) {
                    $itemTotal = round($unitPrice * $coefficient, 2);
                    $directTotal += $itemTotal;
                    $validDirectItems[] = [
                        'category_id' => $categoryId ?: null,
                        'subcategory_id' => $subcategoryId ?: null,
                        'subcat_details' => $subcatDetails ?: null,
                        'item_code' => $itemCode ?: null,
                        'item_type' => $itemType ?: null,
                        'work_type' => 'borongan',
                        'work_volume' => null,
                        'work_unit' => null,
                        'work_quantity' => null,
                        'work_quantity_unit' => null,
                        'work_duration' => null,
                        'work_duration_unit' => 'Hr',
                        'work_billing_unit' => null,
                        'item_name' => $itemName,
                        'unit' => $unit,
                        'unit_price' => $unitPrice,
                        'quantity' => $coefficient,
                        'coefficient' => $coefficient,
                        'total_price' => $itemTotal,
                        'notes' => $notes
                    ];
                }
            }
        }
    }

    // Process Non-RAB items
    $validNonRabItems = [];
    $nonRabTotal = 0;
    if (is_array($nonRabItems)) {
        foreach ($nonRabItems as $item) {
            $itemName = trim($item['item_name'] ?? '');
            $unit = trim($item['unit'] ?? 'ls');
            $quantity = floatval($item['quantity'] ?? 1);
            $unitPrice = floatval($item['unit_price'] ?? 0);
            $notes = trim($item['notes'] ?? '');

            if ($quantity > 0 && $unitPrice > 0 && !empty($itemName)) {
                $itemTotal = round($quantity * $unitPrice, 2);
                $nonRabTotal += $itemTotal;
                $validNonRabItems[] = [
                    'item_name' => $itemName,
                    'unit' => $unit,
                    'quantity' => $quantity,
                    'coefficient' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $itemTotal,
                    'notes' => $notes
                ];
            }
        }
    }

    if (empty($validDirectItems) && empty($validNonRabItems)) {
        echo json_encode(['success' => false, 'message' => 'Minimal tambahkan satu item (RAB atau Non-RAB)!']);
        exit;
    }
    
    try {
        $pdo = getDB();
        $pdo->beginTransaction();
        
        // Double-check lock status before saving
        $projCheck = dbGetRow("SELECT request_locked FROM projects WHERE id = ?", [$projectId]);
        if (!empty($projCheck['request_locked'])) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Pengajuan dana untuk proyek ini sedang dikunci!']);
            exit;
        }

        // Shared physical volume capacity validation with concurrency locking
        $subcatHarianVols = [];
        foreach ($validDirectItems as $vi) {
            if ($vi['work_type'] === 'harian' && !empty($vi['subcategory_id']) && !empty($vi['work_volume'])) {
                $sId = intval($vi['subcategory_id']);
                // Anti double-counting: use max work_volume for items in the same subcategory
                $subcatHarianVols[$sId] = max($subcatHarianVols[$sId] ?? 0, floatval($vi['work_volume']));
            }
        }
        
        if (!empty($subcatHarianVols)) {
            $lockPlaceholders = implode(',', array_fill(0, count($subcatHarianVols), '?'));
            $lockedSubcats = dbGetAll("
                SELECT rs.id, rs.code, rs.name, COALESCE(ri.volume, rs.volume, 0) as subcat_volume 
                FROM rab_subcategories rs 
                LEFT JOIN rap_items ri ON ri.subcategory_id = rs.id
                WHERE rs.id IN ($lockPlaceholders) 
                FOR UPDATE
            ", array_keys($subcatHarianVols));
            
            foreach ($lockedSubcats as $lsc) {
                $subId = intval($lsc['id']);
                $subcatVol = floatval($lsc['subcat_volume'] ?? 0);
                $reqVol = $subcatHarianVols[$subId] ?? 0;
                
                // Get Borongan used volume
                $boronganUsedItems = dbGetAll("
                    SELECT d.coefficient as ahsp_coef,
                           COALESCE((
                               SELECT SUM(reqi2.coefficient) 
                               FROM request_items reqi2 
                               JOIN requests r ON reqi2.request_id = r.id 
                               WHERE reqi2.item_code = pir.item_code 
                                 AND reqi2.subcategory_id = ? 
                                 AND (reqi2.work_type = 'borongan' OR reqi2.work_type IS NULL)
                                 AND r.status IN ('pending', 'pm_approved', 'approved')
                           ), 0) as used_coef
                    FROM rab_subcategories rs
                    JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                    JOIN project_ahsp_rap par ON par.ahsp_code = pa.ahsp_code AND par.project_id = pa.project_id
                    JOIN project_ahsp_details_rap d ON d.ahsp_id = par.id
                    JOIN project_items_rap pir ON d.item_id = pir.id
                    WHERE rs.id = ? AND pir.category = 'upah'
                ", [$subId, $subId]);
                
                $maxBoronganVol = 0.0;
                foreach ($boronganUsedItems as $bItem) {
                    $c = floatval($bItem['ahsp_coef']);
                    if ($c > 0) {
                        $v = floatval($bItem['used_coef']) / $c;
                        if ($v > $maxBoronganVol) $maxBoronganVol = $v;
                    }
                }
                
                // Get Harian used volume
                $harianUsedRow = dbGetRow("
                    SELECT COALESCE(SUM(h_vol), 0) as used_vol FROM (
                        SELECT MAX(reqi.work_volume) as h_vol
                        FROM request_items reqi
                        JOIN requests r ON reqi.request_id = r.id
                        WHERE reqi.subcategory_id = ? 
                          AND reqi.work_type = 'harian'
                          AND reqi.work_volume IS NOT NULL
                          AND r.status IN ('pending', 'pm_approved', 'approved')
                        GROUP BY r.id
                    ) t
                ", [$subId]);
                $usedHarianVol = floatval($harianUsedRow['used_vol'] ?? 0);
                
                $availableVol = max(0, $subcatVol - $maxBoronganVol - $usedHarianVol);
                if ($reqVol > ($availableVol + 0.0001)) {
                    $pdo->rollBack();
                    echo json_encode([
                        'success' => false, 
                        'message' => 'Volume pengajuan harian untuk pekerjaan "' . ($lsc['code'] . '. ' . $lsc['name']) . '" (' . number_format($reqVol, 2, ',', '.') . ') melebihi sisa kapasitas yang tersedia (' . number_format($availableVol, 2, ',', '.') . ')!'
                    ]);
                    exit;
                }
            }
        }
        
        // Generate request number
        $requestNumber = generateRequestNumber($projectId);

        // Determine request_type
        if (!empty($validDirectItems) && !empty($validNonRabItems)) {
            $requestType = 'mixed';
        } elseif (!empty($validNonRabItems)) {
            $requestType = 'non_rab';
        } else {
            $requestType = 'rab';
        }

        $requestDate = !empty($_POST['request_date']) ? trim($_POST['request_date']) : date('Y-m-d');
        $totalAmount = $directTotal + $nonRabTotal;

        // Insert request header with work_type
        $requestId = dbInsert("
            INSERT INTO requests (project_id, request_type, work_type, non_rab_category, request_number, request_date, week_number, target_week, description, status, total_amount, created_by)
            VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, 'pending', ?, ?)
        ", [$projectId, $requestType, $workType, $requestNumber, $requestDate, $targetWeek, $targetWeek, $description, $totalAmount, getCurrentUserId()]);

        // Insert Direct Cost items
        foreach ($validDirectItems as $vi) {
            dbInsert("
                INSERT INTO request_items 
                (request_id, category_id, subcategory_id, subcat_details, item_code, item_type, work_type, work_volume, work_unit, work_quantity, work_quantity_unit, work_duration, work_duration_unit, work_billing_unit, item_name, unit, unit_price, quantity, coefficient, total_price, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $requestId, 
                $vi['category_id'], 
                $vi['subcategory_id'],
                $vi['subcat_details'],
                $vi['item_code'], 
                $vi['item_type'],
                $vi['work_type'],
                $vi['work_volume'],
                $vi['work_unit'],
                $vi['work_quantity'],
                $vi['work_quantity_unit'],
                $vi['work_duration'],
                $vi['work_duration_unit'],
                $vi['work_billing_unit'],
                $vi['item_name'], 
                $vi['unit'], 
                $vi['unit_price'],
                $vi['quantity'], 
                $vi['coefficient'],
                $vi['total_price'],
                $vi['notes']
            ]);
        }

        // Insert Non-RAB items (subcategory_id = NULL, item_type = 'non_rab', work_type = 'borongan')
        foreach ($validNonRabItems as $vi) {
            dbInsert("
                INSERT INTO request_items 
                (request_id, category_id, subcategory_id, subcat_details, item_code, item_type, work_type, work_volume, work_unit, work_quantity, work_quantity_unit, work_duration, work_duration_unit, work_billing_unit, item_name, unit, unit_price, quantity, coefficient, total_price, notes)
                VALUES (?, NULL, NULL, NULL, NULL, 'non_rab', 'borongan', NULL, NULL, NULL, NULL, NULL, 'Hr', NULL, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $requestId,
                $vi['item_name'],
                $vi['unit'],
                $vi['unit_price'],
                $vi['quantity'],
                $vi['coefficient'],
                $vi['total_price'],
                $vi['notes']
            ]);
        }
        
        // Ensure upload directory exists
        $uploadDir = __DIR__ . '/../../uploads/receipts/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Handle existing/cloned attachments (from resubmitted request)
        $existingAttachments = json_decode($_POST['existing_attachments'] ?? '[]', true);
        if (!empty($existingAttachments) && is_array($existingAttachments)) {
            foreach ($existingAttachments as $idx => $exAtt) {
                $attId = intval($exAtt['attachment_id'] ?? 0);
                if ($attId <= 0) continue;
                
                $sourceAtt = dbGetRow("SELECT * FROM request_attachments WHERE id = ?", [$attId]);
                if (!$sourceAtt) continue;
                
                $sourcePath = $uploadDir . $sourceAtt['filename'];
                if (!file_exists($sourcePath)) continue;
                
                $origExt = strtolower(pathinfo($sourceAtt['original_name'] ?: $sourceAtt['filename'], PATHINFO_EXTENSION));
                $newFilename = 'req_' . $requestId . '_' . time() . '_ex' . $idx . '_' . bin2hex(random_bytes(4)) . '.' . $origExt;
                $newPath = $uploadDir . $newFilename;
                
                if (copy($sourcePath, $newPath)) {
                    // Custom name if edited by user
                    $customName = trim($exAtt['custom_name'] ?? '');
                    if (!empty($customName)) {
                        $customName = preg_replace('/[\\\\\/:\*\?"<>\|]/', '_', $customName);
                        $customExt = strtolower(pathinfo($customName, PATHINFO_EXTENSION));
                        if ($customExt !== $origExt && !empty($origExt)) {
                            $finalName = $customName . '.' . $origExt;
                        } else {
                            $finalName = $customName;
                        }
                    } else {
                        $finalName = $sourceAtt['original_name'];
                    }
                    
                    dbInsert("
                        INSERT INTO request_attachments (request_id, filename, original_name, file_type, file_size)
                        VALUES (?, ?, ?, ?, ?)
                    ", [$requestId, $newFilename, $finalName, $sourceAtt['file_type'], $sourceAtt['file_size']]);
                }
            }
        }
        
        // Handle new file uploads
        if (!empty($_FILES['attachments']['name'][0])) {
            
            $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp', 'application/pdf'];
            $allowedExts = ['jpeg', 'jpg', 'png', 'webp', 'pdf'];
            $maxSize = 5 * 1024 * 1024; // 5MB
            
            foreach ($_FILES['attachments']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['attachments']['error'][$key] !== UPLOAD_ERR_OK) continue;
                
                $fileType = $_FILES['attachments']['type'][$key];
                $fileSize = $_FILES['attachments']['size'][$key];
                $rawOriginalName = $_FILES['attachments']['name'][$key];
                $origExt = strtolower(pathinfo($rawOriginalName, PATHINFO_EXTENSION));
                
                // Validate extension and type
                if (!in_array($origExt, $allowedExts)) continue;
                if (!in_array($fileType, $allowedTypes)) {
                    if (function_exists('mime_content_type')) {
                        $detectedMime = mime_content_type($tmpName);
                        if (!in_array($detectedMime, $allowedTypes)) continue;
                        $fileType = $detectedMime;
                    }
                }
                if ($fileSize > $maxSize) continue;
                
                // Custom edited name handling
                $customName = isset($_POST['attachment_names'][$key]) ? trim($_POST['attachment_names'][$key]) : '';
                if (!empty($customName)) {
                    $customName = preg_replace('/[\\\\\/:\*\?"<>\|]/', '_', $customName);
                    $customExt = strtolower(pathinfo($customName, PATHINFO_EXTENSION));
                    if ($customExt !== $origExt && !empty($origExt)) {
                        $finalOriginalName = $customName . '.' . $origExt;
                    } else {
                        $finalOriginalName = $customName;
                    }
                } else {
                    $finalOriginalName = $rawOriginalName;
                }
                
                // Generate unique filename
                $filename = 'req_' . $requestId . '_' . time() . '_' . $key . '_' . bin2hex(random_bytes(4)) . '.' . $origExt;
                $filepath = $uploadDir . $filename;
                
                if (move_uploaded_file($tmpName, $filepath)) {
                    dbInsert("
                        INSERT INTO request_attachments (request_id, filename, original_name, file_type, file_size)
                        VALUES (?, ?, ?, ?, ?)
                    ", [$requestId, $filename, $finalOriginalName, $fileType, $fileSize]);
                }
            }
        }
        
        $pdo->commit();
        
        setFlash('success', 'Pengajuan ' . $requestNumber . ' berhasil dikirim!');
        
        echo json_encode([
            'success' => true, 
            'message' => 'Pengajuan ' . $requestNumber . ' berhasil dikirim!',
            'request_id' => $requestId,
            'redirect' => '../projects/view.php?id=' . $projectId . '&tab=requests'
        ]);
        exit;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

// NOW include header (after all possible redirects and AJAX handlers)
$pageTitle = 'Buat Pengajuan Dana';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Title -->
<div class="row">
    <div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <h4 class="mb-sm-0 me-3">Buat Pengajuan Dana</h4>
                <button type="button" class="btn btn-outline-primary btn-sm rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#modalLaborCalculator">
                    <i class="mdi mdi-calculator me-1"></i> Kalkulator Tenaga Kerja
                </button>
            </div>
            <div class="page-title-right">
                <ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>">PCC</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Pengajuan</a></li>
                    <li class="breadcrumb-item active">Buat Baru</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<?php if (!$projectId): ?>
<!-- Select Project First -->
<div class="row">
    <div class="col-lg-6 mx-auto">
        <div class="card">
            <div class="card-body">
                <h5 class="header-title mb-4">Pilih Proyek</h5>
                <form method="GET">
                    <div class="mb-3">
                        <label class="form-label required">Proyek</label>
                        <select class="form-select select2" name="project_id" required>
                            <option value="">-- Pilih Proyek --</option>
                            <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= sanitize($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="mdi mdi-arrow-right"></i> Lanjutkan
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php else: ?>

<style>
.item-row { background: #fafafa; }
.item-row:hover { background: #f0f7ff; }
.remove-item-btn { color: #dc3545; cursor: pointer; }
.remove-item-btn:hover { color: #a71d2a; }
.grand-total-box { 
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
    color: white; 
    border-radius: 10px;
    padding: 15px 20px;
}
.readonly-field { background-color: #e9ecef !important; }
.select2-container { width: 100% !important; }
/* Multi-item checkbox styles */
#itemCheckboxContainer { max-height: 500px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 6px; }
#itemCheckboxContainer table th,
#itemCheckboxContainer table td { vertical-align: middle; font-size: 0.85rem; }
#itemCheckboxContainer thead th { position: sticky; top: 0; z-index: 5; background-color: #f1f3f6 !important; }
#itemCheckboxContainer .subcat-header-row { background-color: #f0f4fd !important; border-top: 2px solid #b8d4fe; }
#itemCheckboxContainer .subcat-header-row:hover { background-color: #e5edfc !important; }
#itemCheckboxContainer .item-check-row:hover { background: #f8faff; }
#itemCheckboxContainer .item-check-row.selected { background: #e8f5e9; }
.btn-add-selected { position: sticky; bottom: 0; background: #fff; border-top: 2px solid #28a745; }
.harian-badge-vol { background-color: #e3fafc; color: #0c8599; border: 1px solid #99e9f2; }

/* Note Column & Tooltip Editor Styles */
.note-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    width: 100%;
}
.note-input-wrapper .item-notes-input {
    padding-right: 22px !important;
    text-overflow: ellipsis;
    transition: all 0.15s ease-in-out;
}
.note-input-wrapper.is-overflowing .item-notes-input {
    border-color: #93c5fd !important;
    background-color: #f0f7ff !important;
}
.note-input-wrapper .note-expand-trigger {
    position: absolute;
    right: 3px;
    top: 50%;
    transform: translateY(-50%);
    width: 18px;
    height: 18px;
    padding: 0;
    display: none;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: none;
    color: #64748b;
    cursor: pointer;
    border-radius: 3px;
    font-size: 11px;
    z-index: 2;
    transition: all 0.15s ease-in-out;
}
.note-input-wrapper:hover .note-expand-trigger,
.note-input-wrapper.is-overflowing .note-expand-trigger {
    display: flex;
}
.note-input-wrapper .note-expand-trigger:hover {
    color: #0d6efd;
    background-color: #e2e8f0;
}
.note-editor-tooltip {
    position: absolute;
    z-index: 1060;
    width: 360px;
    max-width: calc(100vw - 28px);
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    box-shadow: 0 12px 32px rgba(15, 23, 42, 0.22), 0 3px 8px rgba(15, 23, 42, 0.08);
    font-family: inherit;
    animation: noteTooltipFadeIn 0.15s ease-out;
}
@keyframes noteTooltipFadeIn {
    from { opacity: 0; transform: translateY(-4px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
.note-editor-tooltip .note-tooltip-arrow {
    position: absolute;
    width: 12px;
    height: 12px;
    background: #ffffff;
    transform: rotate(45deg);
    border: 1px solid #cbd5e1;
    z-index: 0;
}
.note-editor-tooltip.arrow-bottom .note-tooltip-arrow {
    bottom: -6px;
    border-top: none;
    border-left: none;
}
.note-editor-tooltip.arrow-top .note-tooltip-arrow {
    top: -6px;
    border-bottom: none;
    border-right: none;
}
.note-tooltip-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 7px 12px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    border-top-left-radius: 9px;
    border-top-right-radius: 9px;
    font-size: 12px;
    position: relative;
    z-index: 1;
}
.note-tooltip-body {
    padding: 8px 10px;
    position: relative;
    z-index: 1;
    background: #ffffff;
}
.note-tooltip-textarea {
    font-size: 13px !important;
    line-height: 1.45 !important;
    resize: vertical;
    min-height: 72px;
    max-height: 200px;
    border: 1px solid #cbd5e1 !important;
    border-radius: 6px !important;
    background: #ffffff !important;
}
.note-tooltip-textarea:focus {
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15) !important;
}
.note-tooltip-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 12px 8px 12px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    border-bottom-left-radius: 9px;
    border-bottom-right-radius: 9px;
    font-size: 11px;
    position: relative;
    z-index: 1;
}
.kbd-hint {
    background: #e2e8f0;
    color: #334155;
    padding: 1px 4px;
    border-radius: 3px;
    font-size: 10px;
    border: 1px solid #cbd5e1;
}
</style>

<!-- Request Form -->
<div class="row">
    <div class="col-12">
        <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>
        
        <?php if ($resubmitReq): ?>
        <div class="alert alert-warning border-warning shadow-sm mb-3">
            <div class="d-flex align-items-start">
                <i class="mdi mdi-refresh-circle text-warning me-2" style="font-size: 2rem; line-height: 1;"></i>
                <div class="flex-grow-1">
                    <h6 class="alert-heading mb-1 fw-bold text-dark">
                        Mode Pengajuan Ulang: Memuat dari <?= sanitize($resubmitReq['request_number'] ?: 'REQ-' . $resubmitReq['id']) ?>
                    </h6>
                    <div class="text-muted small">
                        Seluruh item pengajuan dan file lampiran telah dimuat otomatis. Anda dapat menyesuaikan harga/volume, menghapus item yang salah, menambah item baru, atau mengedit lampiran sebelum mengirim.
                    </div>
                    <?php if (!empty($resubmitReq['rejection_reason'])): ?>
                    <div class="mt-2 p-2 bg-light rounded border border-warning-subtle text-danger small">
                        <strong><i class="mdi mdi-alert-circle-outline"></i> Alasan Penolakan Sebelumnya:</strong>
                        <?= nl2br(sanitize($resubmitReq['rejection_reason'])) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <form id="requestForm">
            <input type="hidden" name="project_id" value="<?= $projectId ?>">
            
            <!-- Header Card -->
            <div class="card mb-3">
                <div class="card-header bg-primary text-white py-2">
                    <h6 class="mb-0"><i class="mdi mdi-information-outline"></i> Informasi Pengajuan Dana</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Pengaju</label>
                            <input type="text" class="form-control readonly-field" value="<?= sanitize($_SESSION['user_name'] ?? 'User') ?>" readonly>
                            <input type="hidden" name="created_by" value="<?= getCurrentUserId() ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Minggu Target <span class="text-danger">*</span></label>
                            <select class="form-select target-week-select" name="target_week" id="targetWeek" required>
                                <option value="">-- Pilih Minggu --</option>
                                <?php 
                                $selectedWeek = $resubmitReq ? ($resubmitReq['target_week'] ?? $resubmitReq['week_number']) : '';
                                foreach ($weeklyRanges as $week): 
                                ?>
                                <option value="<?= $week['week_number'] ?>" <?= (string)$selectedWeek === (string)$week['week_number'] ? 'selected' : '' ?>>
                                    Minggu ke-<?= $week['week_number'] ?> (<?= date('d M', strtotime($week['start'])) ?> - <?= date('d M Y', strtotime($week['end'])) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Keterangan</label>
                            <input type="text" class="form-control" name="description" id="description" value="<?= sanitize($resubmitReq['description'] ?? '') ?>" placeholder="Keterangan pengajuan (opsional)">
                        </div>
                    </div>
                    
                    <hr class="my-3">
                    
                    <!-- RAP Pekerjaan Checkbox Section -->
                    <div class="row">
                        <div class="col-12 mb-3">
                            <label class="form-label"><strong>Pilih Pekerjaan</strong> <span class="text-danger">*</span></label>
                            <div class="border rounded" style="max-height: 300px; overflow-y: auto;" id="rapPekerjaanContainer">
                                <div class="text-center text-muted py-4">
                                    <div class="spinner-border spinner-border-sm" role="status"></div>
                                    Memuat data RAP...
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-1">
                                <small class="text-muted">Centang pekerjaan yang akan diajukan dana-nya</small>
                                <div class="btn-group shadow-sm" role="group" id="workTypeToggleGroup" aria-label="Metode Pengajuan">
                                    <input type="radio" class="btn-check" name="work_type" id="workTypeBorongan" value="borongan" <?= ($resubmitReq['work_type'] ?? 'borongan') === 'harian' ? '' : 'checked' ?> autocomplete="off">
                                    <label class="btn btn-outline-primary btn-sm px-3 fw-semibold" for="workTypeBorongan" id="lblWorkTypeBorongan" title="Metode Borongan (Standar)">
                                        <i class="mdi mdi-hammer me-1"></i> Borongan
                                    </label>
                                    <input type="radio" class="btn-check" name="work_type" id="workTypeHarian" value="harian" <?= ($resubmitReq['work_type'] ?? 'borongan') === 'harian' ? 'checked' : '' ?> autocomplete="off">
                                    <label class="btn btn-outline-primary btn-sm px-3 fw-semibold" for="workTypeHarian" id="lblWorkTypeHarian" title="Metode Harian (Input Volume, Jumlah, Hari, Tarif)">
                                        <i class="mdi mdi-calendar-clock me-1"></i> Harian
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Row: Item Type & Item Selection (based on selected RAP checkboxes) -->
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Jenis Item <span class="text-danger">*</span></label>
                            <select class="form-select" id="itemTypeSelect" disabled>
                                <option value="">-- Pilih Pekerjaan dahulu --</option>
                                <option value="upah">Upah</option>
                                <option value="material">Material</option>
                                <option value="alat">Alat</option>
                            </select>

                            <!-- Opsi Biaya Non-RAB di Bagian Bawah Jenis Item -->
                            <div class="card mt-3 border-warning shadow-sm" id="nonRabOptionCard">
                                <div class="card-body p-3 bg-light rounded">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-0 fw-bold text-dark">
                                                <i class="mdi mdi-cash-multiple text-warning me-1"></i> Biaya Non-RAB
                                            </h6>
                                            <small class="text-muted font-size-11">Biaya operasional / umum / lain-lain di luar RAB</small>
                                        </div>
                                        <button type="button" class="btn btn-warning btn-sm fw-semibold shadow-sm text-nowrap" id="btnAddNonRabItemBtn" onclick="addNonRabItemRowAndFocus()">
                                            <i class="mdi mdi-plus-circle-outline me-1"></i> Tambah Non-RAB
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Pilih Item <span class="text-danger">*</span></label>
                            <div class="border rounded" id="itemCheckboxContainer">
                                <div class="text-center text-muted py-4">
                                    <i class="mdi mdi-arrow-left"></i> Pilih Jenis Item terlebih dahulu
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <small class="text-muted" id="selectedItemCount">0 item dipilih</small>
                                <button type="button" class="btn btn-success btn-sm" id="addSelectedItemsBtn" disabled>
                                    <i class="mdi mdi-plus-circle-multiple-outline"></i> Tambah Semua Terpilih
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Items Table Card: Direct Cost (RAB) -->
            <div class="card mb-3">
                <div class="card-header bg-success text-white py-2 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="mdi mdi-cart"></i> Detail Item Pengajuan (Direct Cost / RAB)</h6>
                    <span id="itemCount" class="badge bg-light text-dark">0 item</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0" id="itemsTable">
                            <thead class="table-light">
                                <tr>
                                    <th width="40">#</th>
                                    <th width="150">Item</th>
                                    <th>Nama Item</th>
                                    <th width="70">Satuan</th>
                                    <th width="120">Harga Satuan</th>
                                    <th width="90" id="tableCoefHeader">Volume</th>
                                    <th width="130">Total Harga</th>
                                    <th width="110">Catatan</th>
                                    <th width="100">Status Harga</th>
                                    <th width="110">Status Qty</th>
                                    <th width="40">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody">
                                <tr id="emptyRow">
                                    <td colspan="11" class="text-center text-muted py-4">
                                        <i class="mdi mdi-cart-outline" style="font-size: 2rem;"></i>
                                        <p class="mb-0 mt-2">Belum ada item RAB. Pilih pekerjaan & jenis item lalu klik "Tambah Semua Terpilih".</p>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="6" class="text-end"><strong>SUBTOTAL RAB</strong></td>
                                    <td class="text-end"><strong id="grandTotalDisplay">Rp 0</strong></td>
                                    <td colspan="4"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Non-RAB Dynamic Items Table Card -->
            <div class="card mb-3 shadow-sm border-warning border-opacity-50" id="nonRabItemsCard">
                <div class="card-header bg-warning bg-opacity-25 py-2 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-dark"><i class="mdi mdi-cash-multiple text-warning me-1"></i> Detail Item Pengajuan (Biaya Non-RAB / Lain-Lain)</h6>
                    <div class="d-flex align-items-center gap-2">
                        <span id="nonRabItemCount" class="badge bg-dark text-white">0 item</span>
                        <button type="button" class="btn btn-warning btn-sm fw-semibold py-1 px-2" onclick="addNonRabItemRowAndFocus()">
                            <i class="mdi mdi-plus-circle-outline me-1"></i> Tambah Baris
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm mb-0 align-middle" id="nonRabItemsTable">
                            <thead class="table-light">
                                <tr>
                                    <th width="40" class="text-center">#</th>
                                    <th>Nama Pengeluaran / Kebutuhan <span class="text-danger">*</span></th>
                                    <th width="110" class="text-center">Kuantitas <span class="text-danger">*</span></th>
                                    <th width="100" class="text-center">Satuan</th>
                                    <th width="180" class="text-end">Harga Satuan (Rp) <span class="text-danger">*</span></th>
                                    <th width="180" class="text-end">Total Harga (Rp)</th>
                                    <th>Catatan / Keterangan</th>
                                    <th width="50" class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="nonRabItemsBody">
                                <tr id="emptyNonRabRow">
                                    <td colspan="8" class="text-center text-muted py-3">
                                        <i class="mdi mdi-receipt-text-outline" style="font-size: 1.5rem;"></i>
                                        <p class="mb-0 mt-1 small">Belum ada item Biaya Non-RAB. Klik tombol <strong>"Tambah Non-RAB"</strong> di atas jika ingin mengajukan biaya non-RAB bersamaan.</p>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="5" class="text-end fw-bold font-size-13">SUBTOTAL BIAYA NON-RAB</td>
                                    <td class="text-end fw-bold font-size-13 text-primary" id="nonRabGrandTotalDisplay">Rp 0</td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Combined Grand Total Summary -->
            <div class="card mb-3 bg-white border-primary border-opacity-50 shadow-sm">
                <div class="card-body py-3 px-4">
                    <div class="row align-items-center">
                        <div class="col-md-6 mb-2 mb-md-0">
                            <div class="d-flex align-items-center gap-3">
                                <div>
                                    <small class="text-muted d-block font-size-12">Subtotal Direct Cost (RAB):</small>
                                    <strong id="rabSubtotalSummary" class="text-dark fs-6">Rp 0</strong>
                                </div>
                                <div class="vr" style="height: 30px;"></div>
                                <div>
                                    <small class="text-muted d-block font-size-12">Subtotal Biaya Non-RAB:</small>
                                    <strong id="nonRabSubtotalSummary" class="text-warning fs-6">Rp 0</strong>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <small class="text-muted d-block font-size-12">TOTAL PENGAJUAN (RAB + NON-RAB):</small>
                            <h4 class="mb-0 text-primary fw-bold" id="combinedGrandTotalDisplay">Rp 0</h4>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Attachment Upload Card -->
            <div class="card mb-3 shadow-sm border-info">
                <div class="card-header bg-info text-white py-2 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 text-white"><i class="mdi mdi-paperclip"></i> Lampiran Nota / Bukti Transaksi</h6>
                    <span id="stagedFilesCountBadge" class="badge bg-light text-dark">0 file dipilih</span>
                </div>
                <div class="card-body">
                    <!-- Drag & Drop Zone -->
                    <div class="upload-dropzone p-4 mb-3 text-center border border-2 border-dashed rounded bg-light" id="uploadDropzone" style="cursor: pointer; transition: all 0.2s ease;">
                        <input type="file" id="attachmentInput" accept=".jpg,.jpeg,.png,.webp,.pdf" multiple style="display: none;">
                        <div class="dropzone-content">
                            <i class="mdi mdi-cloud-upload-outline text-info" style="font-size: 3rem; display: block; line-height: 1;"></i>
                            <h6 class="mt-2 mb-1">Tarik & Lepaskan File Nota di Sini atau <span class="text-primary text-decoration-underline">Pilih dari Komputer</span></h6>
                            <p class="text-muted small mb-2">Bisa memilih banyak file sekaligus atau upload berkali-kali tanpa menimpa file sebelumnya.</p>
                            <div>
                                <span class="badge bg-soft-primary text-primary me-1"><i class="mdi mdi-image"></i> JPG, PNG, WEBP</span>
                                <span class="badge bg-soft-danger text-danger me-1"><i class="mdi mdi-file-pdf-box"></i> PDF</span>
                                <span class="badge bg-soft-secondary text-secondary"><i class="mdi mdi-weight"></i> Maks. 5MB per file</span>
                            </div>
                        </div>
                    </div>

                    <!-- Staged Files List Section -->
                    <div id="stagedFilesContainer" class="d-none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0 text-dark font-weight-bold">
                                <i class="mdi mdi-file-document-multiple-outline text-info"></i> Daftar File yang Akan Diupload:
                            </h6>
                            <div>
                                <button type="button" class="btn btn-outline-primary btn-sm me-1" id="btnAddMoreFiles">
                                    <i class="mdi mdi-plus"></i> Tambah File Lain
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm" id="btnClearAllStaged">
                                    <i class="mdi mdi-trash-can-outline"></i> Hapus Semua
                                </button>
                            </div>
                        </div>

                        <div class="table-responsive border rounded">
                            <table class="table table-hover align-middle mb-0" id="stagedFilesTable">
                                <thead class="table-light">
                                    <tr>
                                        <th width="40" class="text-center">#</th>
                                        <th width="80" class="text-center">Preview</th>
                                        <th>Nama File / Keterangan Nota</th>
                                        <th width="110" class="text-center">Ukuran</th>
                                        <th width="100" class="text-center">Format</th>
                                        <th width="130" class="text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody id="stagedFilesList">
                                    <!-- Rendered dynamically -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Empty State for Attachment -->
                    <div id="stagedFilesEmptyState" class="text-center py-2 text-muted small">
                        <i class="mdi mdi-information-outline"></i> Belum ada file nota yang dipilih. (Opsional / dapat dilampirkan)
                    </div>
                </div>
            </div>
            <!-- Submit Footer -->
            <div class="card">
                <div class="card-body py-3">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <a href="<?= $baseUrl ?>/pages/projects/view.php?id=<?= $projectId ?>&tab=requests" class="btn btn-secondary">
                                <i class="mdi mdi-arrow-left"></i> Kembali
                            </a>
                        </div>
                        <div class="col-md-6 text-end">
                            <div class="grand-total-box d-inline-block me-3">
                                <small>Total Pengajuan</small>
                                <h4 class="mb-0" id="grandTotalBig">Rp 0</h4>
                            </div>
                            <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                <i class="mdi mdi-send"></i> Kirim Pengajuan
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Warning Modal Before Submit -->
<div class="modal fade" id="submitWarningModal" tabindex="-1" aria-labelledby="submitWarningModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title" id="submitWarningModalLabel">
                    <i class="mdi mdi-alert"></i> Peringatan Pengajuan
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning mb-3">
                    <i class="mdi mdi-alert-circle"></i> Terdapat item dengan status berikut yang perlu diperhatikan:
                </div>
                <div id="warningItemsList"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="mdi mdi-close"></i> Batal, Periksa Ulang
                </button>
                <button type="button" class="btn btn-warning" id="confirmSubmitBtn">
                    <i class="mdi mdi-send"></i> Tetap Kirim Pengajuan
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Preview Gambar / Dokumen Nota -->
<div class="modal fade" id="attachmentPreviewModal" tabindex="-1" aria-labelledby="attachmentPreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white py-2">
                <h5 class="modal-title fs-6 d-flex align-items-center" id="attachmentPreviewModalLabel">
                    <i class="mdi mdi-eye me-2"></i> <span id="previewModalFileName">Preview Dokumen</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-3 bg-light" style="min-height: 250px; display: flex; align-items: center; justify-content: center;">
                <div id="previewModalContent" class="w-100">
                    <!-- Image or PDF container -->
                </div>
            </div>
            <div class="modal-footer py-2 d-flex justify-content-between">
                <span class="text-muted small" id="previewModalFileSize"></span>
                <div>
                    <a href="#" id="previewModalOpenNewTab" target="_blank" class="btn btn-outline-primary btn-sm me-1">
                        <i class="mdi mdi-open-in-new"></i> Buka Tab Baru
                    </a>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Ubah Nama File Nota -->
<div class="modal fade" id="editFileNameModal" tabindex="-1" aria-labelledby="editFileNameModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-2">
                <h5 class="modal-title fs-6" id="editFileNameModalLabel">
                    <i class="mdi mdi-pencil me-1"></i> Edit Nama File Nota
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editFileId">
                <div class="mb-3">
                    <label class="form-label">Nama File / Keterangan Nota <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="editFileNameInput" placeholder="Contoh: Nota Semen Toko ABC">
                        <span class="input-group-text bg-light text-muted" id="editFileExtension">.jpg</span>
                    </div>
                    <small class="text-muted">Beri nama yang jelas untuk memudahkan identifikasi saat verifikasi admin & PM.</small>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnSaveFileName">
                    <i class="mdi mdi-check"></i> Simpan Perubahan
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Floating Note Editor Tooltip -->
<div id="noteEditorTooltip" class="note-editor-tooltip" style="display: none;">
    <div class="note-tooltip-arrow"></div>
    <div class="note-tooltip-header">
        <div class="d-flex align-items-center gap-1 text-truncate me-2">
            <i class="mdi mdi-comment-text-outline text-primary fs-6"></i>
            <span class="note-tooltip-title fw-semibold text-dark font-size-12">Catatan:</span>
            <span class="badge bg-light text-primary border note-tooltip-item-badge text-truncate" style="max-width: 170px;">-</span>
        </div>
        <button type="button" class="btn-close btn-close-sm note-tooltip-close-btn" aria-label="Close" title="Tutup (Esc)"></button>
    </div>
    <div class="note-tooltip-body">
        <textarea class="form-control note-tooltip-textarea" rows="3" placeholder="Tulis catatan lengkap untuk item ini..."></textarea>
    </div>
    <div class="note-tooltip-footer">
        <div class="text-muted note-tooltip-hint">
            <span class="note-tooltip-char-count">0 karakter</span>
            <span class="mx-1">•</span>
            <span class="text-secondary"><kbd class="kbd-hint">Esc</kbd> tutup</span>
        </div>
        <button type="button" class="btn btn-sm btn-primary note-tooltip-done-btn py-0 px-2 fw-semibold">
            <i class="mdi mdi-check me-1"></i> Selesai
        </button>
    </div>
</div>

<?php 
// Store script in $extraScripts to load AFTER jQuery in footer
ob_start();
?>
<script>
// Toast notification helper (used throughout this page)
function showToast(message, type) {
    type = type || 'info';
    var bgMap = {success: '#00b894', error: '#d63031', warning: '#fdcb6e', info: '#0984e3'};
    var bg = bgMap[type] || bgMap.info;
    var textColor = type === 'warning' ? '#333' : '#fff';
    
    $('.pcm-toast').remove();
    var toast = $('<div class="pcm-toast position-fixed d-flex align-items-center px-3 py-2 rounded shadow" ' +
        'style="bottom:20px;right:20px;z-index:9999;min-width:280px;background:' + bg + ';color:' + textColor + ';">' +
        '<span class="me-2 fw-medium">' + message + '</span>' +
        '<button type="button" class="btn-close btn-close-white ms-auto" onclick="$(this).parent().fadeOut(200,function(){$(this).remove();})"></button>' +
        '</div>');
    $('body').append(toast);
    setTimeout(function() { toast.fadeOut(500, function(){ $(this).remove(); }); }, 3500);
}

$(document).ready(function() {
    console.log('=== CREATE.PHP SCRIPT LOADED ===');
    
    const projectId = <?= intval($projectId) ?>;
    const resubmitItems = <?= json_encode($resubmitItems ?? []) ?>;
    const resubmitAttachments = <?= json_encode($resubmitAttachments ?? []) ?>;
    const resubmitSubcatIds = <?= json_encode(array_values(array_keys($resubmitSubcatIds ?? []))) ?>;
    console.log('Project ID:', projectId, 'Resubmit items:', resubmitItems.length);
    
    let itemIndex = 0;
    let selectedCategoryId = null;
    let selectedSubcategoryId = null;
    let selectedSubcatName = '';
    let selectedSubcatCode = '';
    
    // Sync table headers on load based on checked work_type
    let previousWorkType = $('input[name="work_type"]:checked').val() || 'borongan';
    syncItemsTableHeader(previousWorkType);
    
    // Toggle Borongan / Harian
    $(document).on('change', 'input[name="work_type"]', function() {
        const newWorkType = $(this).val();
        const hasExistingDirectItems = $('#itemsBody tr.item-row').length > 0;
        
        if (hasExistingDirectItems) {
            if (!confirm('Mengubah metode pengajuan (Borongan / Harian) akan mengosongkan item pengajuan Direct Cost yang sudah dimasukkan. Lanjutkan?')) {
                if (previousWorkType === 'harian') {
                    $('#workTypeHarian').prop('checked', true);
                } else {
                    $('#workTypeBorongan').prop('checked', true);
                }
                return;
            }
            $('#itemsBody tr.item-row').remove();
            $('#emptyRow').show();
            updateItemCount();
            calculateGrandTotal();
        }
        
        previousWorkType = newWorkType;
        syncItemsTableHeader(newWorkType);
        
        if ($('#itemTypeSelect').val()) {
            $('#itemTypeSelect').trigger('change');
        }
    });

    function syncItemsTableHeader(workType) {
        const thead = $('#itemsTable thead');
        const emptyRow = $('#emptyRow td');
        const tfootFirst = $('#itemsTable tfoot tr td:first');
        
        if (workType === 'harian') {
            thead.html(`
                <tr>
                    <th width="35" class="text-center">#</th>
                    <th width="140">Item</th>
                    <th>Nama Item</th>
                    <th width="85" class="text-center">Vol.</th>
                    <th width="50" class="text-center">Sat.</th>
                    <th width="65" class="text-center">Jml</th>
                    <th width="65" class="text-center">Hari</th>
                    <th width="55" class="text-center">Sat.</th>
                    <th width="120" class="text-end">Tarif Satuan</th>
                    <th width="130" class="text-end">Total Lapangan</th>
                    <th width="105">Catatan</th>
                    <th width="125" class="text-center">Status Harga</th>
                    <th width="105" class="text-center">Status Sisa Qty</th>
                    <th width="40" class="text-center">Aksi</th>
                </tr>
            `);
            emptyRow.attr('colspan', 14);
            tfootFirst.attr('colspan', 9);
        } else {
            thead.html(`
                <tr>
                    <th width="40" class="text-center">#</th>
                    <th width="150">Item</th>
                    <th>Nama Item</th>
                    <th width="70">Satuan</th>
                    <th width="120" class="text-end">Harga Satuan</th>
                    <th width="90" class="text-end" id="tableCoefHeader">Volume</th>
                    <th width="130" class="text-end">Total Harga</th>
                    <th width="110">Catatan</th>
                    <th width="100" class="text-center">Status Harga</th>
                    <th width="110" class="text-center">Status Qty</th>
                    <th width="40" class="text-center">Aksi</th>
                </tr>
            `);
            emptyRow.attr('colspan', 11);
            tfootFirst.attr('colspan', 6);
        }
    }
    
    // =====================================
    // FORMAT HELPERS
    // =====================================
    function formatRupiah(num) {
        return 'Rp ' + num.toLocaleString('id-ID', {minimumFractionDigits: 0, maximumFractionDigits: 0});
    }
    
    function formatNumber(num) {
        return num.toLocaleString('id-ID', {minimumFractionDigits: 0, maximumFractionDigits: 0});
    }
    
    function parseNumber(str) {
        if (!str) return 0;
        // Remove dots (thousand sep) and replace comma with dot (decimal)
        return parseFloat(str.toString().replace(/\./g, '').replace(',', '.')) || 0;
    }
    
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    
    function autoFormatInput(input) {
        let val = input.val().replace(/[^\d,]/g, '');
        let parts = val.split(',');
        let intPart = parts[0].replace(/\./g, '');
        if (intPart) {
            intPart = parseInt(intPart).toLocaleString('id-ID');
        }
        let result = intPart;
        if (parts.length > 1) {
            result += ',' + parts[1].substring(0, 2);
        }
        input.val(result);
    }
    
    // =====================================
    // EXPANDED NOTE TOOLTIP EDITOR MODULE
    // =====================================
    let activeNoteInput = null;
    let _canvasMeasureContext = null;

    function measureTextWidth(text, font) {
        if (!_canvasMeasureContext) {
            const canvas = document.createElement('canvas');
            _canvasMeasureContext = canvas.getContext('2d');
        }
        _canvasMeasureContext.font = font || '13px system-ui, sans-serif';
        return _canvasMeasureContext.measureText(text).width;
    }

    function isNotesOverflowing(input) {
        if (!input) return false;
        const text = input.value || '';
        if (!text.trim()) return false;
        
        // DOM scrollWidth check
        if (input.scrollWidth > input.clientWidth + 2) {
            return true;
        }
        
        // Visual pixel width calculation
        try {
            const style = window.getComputedStyle(input);
            const font = `${style.fontWeight || '400'} ${style.fontSize || '13px'} ${style.fontFamily || 'sans-serif'}`;
            const textWidth = measureTextWidth(text, font);
            const paddingLeft = parseFloat(style.paddingLeft) || 8;
            const paddingRight = parseFloat(style.paddingRight) || 22;
            const availableWidth = input.clientWidth - paddingLeft - paddingRight;
            
            if (availableWidth > 0 && textWidth > availableWidth - 2) {
                return true;
            }
        } catch (e) {}
        
        // Fallbacks for narrow columns
        if (input.clientWidth <= 140 && text.length > 12) {
            return true;
        }
        if (text.length >= 18) {
            return true;
        }
        
        return false;
    }

    function updateNotesOverflowState(input) {
        if (!input) return;
        const $wrapper = $(input).closest('.note-input-wrapper');
        const overflowing = isNotesOverflowing(input);
        if (overflowing) {
            $wrapper.addClass('is-overflowing');
            input.title = input.value;
        } else {
            $wrapper.removeClass('is-overflowing');
            if (!input.value) {
                input.removeAttribute('title');
            } else {
                input.title = input.value;
            }
        }
    }

    function openNoteTooltip(input, focusTextarea = true) {
        if (!input) return;
        activeNoteInput = input;
        const $input = $(input);
        const $tooltip = $('#noteEditorTooltip');
        const $textarea = $tooltip.find('.note-tooltip-textarea');
        const $itemBadge = $tooltip.find('.note-tooltip-item-badge');
        const $charCount = $tooltip.find('.note-tooltip-char-count');
        
        // Find item name for tooltip header context
        const $row = $input.closest('tr');
        let itemName = '';
        const rowNameInput = $row.find('input[name*="[item_name]"], .non-rab-item-name');
        if (rowNameInput.length) {
            itemName = rowNameInput.val();
        }
        if (!itemName) {
            const rowNum = $row.find('td:first').text().trim();
            itemName = rowNum ? 'Item #' + rowNum : 'Item Pengajuan';
        }
        $itemBadge.text(itemName).attr('title', itemName);
        
        // Populate textarea
        const currentVal = input.value || '';
        $textarea.val(currentVal);
        $charCount.text(currentVal.length + ' karakter');
        
        // Show and position tooltip
        $tooltip.show();
        positionNoteTooltip(input);
        
        // Focus and preserve cursor position
        if (focusTextarea) {
            $textarea.focus();
            const start = typeof input.selectionStart === 'number' ? input.selectionStart : currentVal.length;
            const end = typeof input.selectionEnd === 'number' ? input.selectionEnd : currentVal.length;
            try {
                $textarea[0].setSelectionRange(start, end);
            } catch (e) {}
        }
    }

    function positionNoteTooltip(targetInput) {
        const $tooltip = $('#noteEditorTooltip');
        if (!$tooltip.is(':visible') || !targetInput) return;
        
        const rect = targetInput.getBoundingClientRect();
        const tooltipWidth = Math.min(380, window.innerWidth - 24);
        $tooltip.css('width', tooltipWidth + 'px');
        const tooltipHeight = $tooltip.outerHeight() || 185;
        
        const scrollX = window.pageXOffset || document.documentElement.scrollLeft;
        const scrollY = window.pageYOffset || document.documentElement.scrollTop;
        
        // Horizontal calculation
        let left = rect.left + scrollX;
        const minLeft = scrollX + 12;
        const maxLeft = scrollX + window.innerWidth - tooltipWidth - 12;
        if (left > maxLeft) left = maxLeft;
        if (left < minLeft) left = minLeft;
        
        // Vertical calculation
        let top;
        let arrowClass = 'arrow-bottom';
        const spaceAbove = rect.top;
        const spaceBelow = window.innerHeight - rect.bottom;
        
        if (spaceAbove >= tooltipHeight + 12) {
            top = rect.top + scrollY - tooltipHeight - 8;
            arrowClass = 'arrow-bottom';
        } else if (spaceBelow >= tooltipHeight + 12) {
            top = rect.bottom + scrollY + 8;
            arrowClass = 'arrow-top';
        } else {
            if (spaceAbove >= spaceBelow) {
                top = rect.top + scrollY - tooltipHeight - 8;
                arrowClass = 'arrow-bottom';
            } else {
                top = rect.bottom + scrollY + 8;
                arrowClass = 'arrow-top';
            }
        }
        
        // Arrow position pointing to horizontal center of input
        const inputCenterX = rect.left + scrollX + (rect.width / 2);
        let arrowLeft = inputCenterX - left - 6;
        arrowLeft = Math.max(16, Math.min(tooltipWidth - 28, arrowLeft));
        
        $tooltip.removeClass('arrow-top arrow-bottom').addClass(arrowClass);
        $tooltip.find('.note-tooltip-arrow').css('left', arrowLeft + 'px');
        $tooltip.css({
            top: Math.max(scrollY + 6, top) + 'px',
            left: left + 'px'
        });
    }

    function closeNoteTooltip(restoreFocus = false) {
        const $tooltip = $('#noteEditorTooltip');
        if ($tooltip.is(':visible')) {
            $tooltip.hide();
            if (activeNoteInput) {
                updateNotesOverflowState(activeNoteInput);
                if (restoreFocus) {
                    $(activeNoteInput).focus();
                }
            }
            activeNoteInput = null;
        }
    }

    // Event: Click on catatan input (opens tooltip if text overflows column)
    $(document).on('click', '.item-notes-input', function(e) {
        if (isNotesOverflowing(this)) {
            openNoteTooltip(this);
        }
    });

    // Event: Click on expand button (always opens tooltip)
    $(document).on('click', '.note-expand-trigger', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const input = $(this).siblings('.item-notes-input')[0];
        if (input) {
            openNoteTooltip(input);
        }
    });

    // Event: Double click on catatan input opens tooltip anytime
    $(document).on('dblclick', '.item-notes-input', function(e) {
        openNoteTooltip(this);
    });

    // Event: Typing inside catatan input updates overflow state
    $(document).on('input', '.item-notes-input', function() {
        updateNotesOverflowState(this);
    });

    // Event: Enter key inside catatan input opens tooltip instead of submitting form
    $(document).on('keydown', '.item-notes-input', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            openNoteTooltip(this);
        }
    });

    // Event: Textarea live synchronization
    $(document).on('input', '#noteEditorTooltip .note-tooltip-textarea', function() {
        const val = $(this).val();
        $('#noteEditorTooltip .note-tooltip-char-count').text(val.length + ' karakter');
        if (activeNoteInput) {
            activeNoteInput.value = val;
            activeNoteInput.title = val;
            $(activeNoteInput).trigger('input').trigger('change');
            updateNotesOverflowState(activeNoteInput);
        }
    });

    // Event: Textarea keyboard navigation (Esc, Ctrl+Enter, Tab)
    $(document).on('keydown', '#noteEditorTooltip .note-tooltip-textarea', function(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            closeNoteTooltip(true);
        } else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            closeNoteTooltip(true);
        } else if (e.key === 'Tab') {
            e.preventDefault();
            const currentInput = activeNoteInput;
            closeNoteTooltip(false);
            if (currentInput) {
                const $row = $(currentInput).closest('tr');
                if (e.shiftKey) {
                    const inputs = $row.find('input:visible, select:visible');
                    const idx = inputs.index(currentInput);
                    if (idx > 0) inputs.eq(idx - 1).focus();
                } else {
                    const inputs = $row.find('input:visible, select:visible, button:visible');
                    const idx = inputs.index(currentInput);
                    if (idx !== -1 && idx < inputs.length - 1) {
                        inputs.eq(idx + 1).focus();
                    }
                }
            }
        }
    });

    // Event: Close & Done buttons
    $(document).on('click', '#noteEditorTooltip .note-tooltip-close-btn, #noteEditorTooltip .note-tooltip-done-btn', function(e) {
        e.preventDefault();
        closeNoteTooltip(true);
    });

    // Event: Click outside closes tooltip
    $(document).on('mousedown touchstart', function(e) {
        if (!activeNoteInput) return;
        const $tooltip = $('#noteEditorTooltip');
        if ($tooltip.is(':visible') && 
            !$tooltip.is(e.target) && $tooltip.has(e.target).length === 0 &&
            !$(activeNoteInput).is(e.target) && !$(activeNoteInput).closest('.note-input-wrapper').has(e.target).length) {
            closeNoteTooltip(false);
        }
    });

    // Event: Reposition on scroll / resize
    $(window).on('resize scroll', function() {
        if (activeNoteInput) {
            positionNoteTooltip(activeNoteInput);
        }
    });
    $('.table-responsive').on('scroll', function() {
        if (activeNoteInput) {
            positionNoteTooltip(activeNoteInput);
        }
    });
    
    // =====================================
    // RAP PEKERJAAN CHECKBOX HANDLERS
    // =====================================
    
    // Load RAP Pekerjaan checkboxes on page load
    function loadRapPekerjaan() {
        console.log('Loading RAP pekerjaan for project:', projectId);
        
        $.ajax({
            url: 'create.php',
            data: {
                project_id: projectId,
                ajax: 'get_rap_pekerjaan'
            },
            method: 'GET',
            dataType: 'json',
            timeout: 30000, // 30 second timeout
            success: function(res) {
                console.log('RAP data response:', res);
                if (res.success && res.data.length > 0) {
                    renderRapCheckboxes(res.data);
                } else {
                    $('#rapPekerjaanContainer').html(
                        '<div class="text-center text-muted py-4"><i class="mdi mdi-alert-circle-outline"></i> Tidak ada data RAP untuk proyek ini.</div>'
                    );
                }
            },
            error: function(xhr, status, error) {
                console.error('RAP loading error:', status, error, xhr.responseText);
                $('#rapPekerjaanContainer').html(
                    '<div class="text-center text-danger py-4"><i class="mdi mdi-alert"></i> Gagal memuat data RAP. <br><small>Error: ' + (error || status) + '</small></div>'
                );
            }
        });
    }
    
    // Render RAP checkboxes table
    function renderRapCheckboxes(items) {
        let html = '<table class="table table-sm table-hover mb-0">';
        html += '<thead class="table-dark"><tr>';
        html += '<th width="40" class="text-center"><input type="checkbox" class="form-check-input" id="selectAllRap" title="Pilih Semua"></th>';
        html += '<th width="60">No</th>';
        html += '<th>Uraian Pekerjaan</th></tr></thead>';
        html += '<tbody>';
        
        let currentCategory = null;
        items.forEach(function(item) {
            // Category header row
            if (item.category_code !== currentCategory) {
                currentCategory = item.category_code;
                html += '<tr class="table-primary">';
                html += '<td class="text-center"><input type="checkbox" class="form-check-input rap-category-check" data-category="' + item.category_id + '" title="Pilih Kategori"></td>';
                html += '<td colspan="2"><strong>' + item.category_code + '. ' + escapeHtml(item.category_name) + '</strong></td>';
                html += '</tr>';
            }
            // Item row
            html += '<tr>';
            html += '<td class="text-center">';
            html += '<input type="checkbox" class="form-check-input rap-item-check" ';
            html += 'data-subcategory-id="' + item.subcategory_id + '" ';
            html += 'data-category-id="' + item.category_id + '">';
            html += '</td>';
            html += '<td>' + escapeHtml(item.item_code) + '</td>';
            html += '<td>' + escapeHtml(item.item_name) + '</td>';
            html += '</tr>';
        });
        
        html += '</tbody></table>';
        $('#rapPekerjaanContainer').html(html);
        
        // Auto-check subcategories if resubmitting
        if (resubmitSubcatIds && resubmitSubcatIds.length > 0) {
            resubmitSubcatIds.forEach(function(subId) {
                $('.rap-item-check[data-subcategory-id="' + subId + '"]').prop('checked', true);
            });
            // Update category checkboxes state
            $('.rap-category-check').each(function() {
                const catId = $(this).data('category');
                const allInCat = $('.rap-item-check[data-category-id="' + catId + '"]').length;
                const checkedInCat = $('.rap-item-check[data-category-id="' + catId + '"]:checked').length;
                if (allInCat > 0 && allInCat === checkedInCat) {
                    $(this).prop('checked', true);
                }
            });
            onRapSelectionChange();
        }
    }
    
    // Helper function to escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        return text.replace(/&/g, "&amp;")
                   .replace(/</g, "&lt;")
                   .replace(/>/g, "&gt;")
                   .replace(/"/g, "&quot;")
                   .replace(/'/g, "&#039;");
    }
    
    // Get selected subcategory IDs from checkboxes
    function getSelectedSubcategoryIds() {
        let ids = [];
        $('.rap-item-check:checked').each(function() {
            ids.push($(this).data('subcategory-id'));
        });
        return ids;
    }
    
    // Handle "Select All" checkbox
    $(document).on('change', '#selectAllRap', function() {
        const isChecked = $(this).prop('checked');
        $('.rap-category-check, .rap-item-check').prop('checked', isChecked);
        onRapSelectionChange();
    });
    
    // Handle category checkbox - select/deselect all items in category
    $(document).on('change', '.rap-category-check', function() {
        const catId = $(this).data('category');
        const isChecked = $(this).prop('checked');
        $('.rap-item-check[data-category-id="' + catId + '"]').prop('checked', isChecked);
        onRapSelectionChange();
    });
    
    // Handle individual item checkbox
    $(document).on('change', '.rap-item-check', function() {
        const catId = $(this).data('category-id');
        // Update category checkbox state based on items
        const allInCat = $('.rap-item-check[data-category-id="' + catId + '"]').length;
        const checkedInCat = $('.rap-item-check[data-category-id="' + catId + '"]:checked').length;
        $('.rap-category-check[data-category="' + catId + '"]').prop('checked', allInCat === checkedInCat);
        onRapSelectionChange();
    });
    
    // When selection changes, enable/disable item type dropdown
    function onRapSelectionChange() {
        const selectedIds = getSelectedSubcategoryIds();
        const currentType = $('#itemTypeSelect').val();
        
        if (selectedIds.length > 0) {
            $('#itemTypeSelect').prop('disabled', false);
            if (currentType) {
                // Trigger item type change to reload items with new selection
                $('#itemTypeSelect').trigger('change');
                return;
            }
        } else {
            $('#itemTypeSelect').val('').prop('disabled', true);
            $('#itemCheckboxContainer').html('<div class="text-center text-muted py-4"><i class="mdi mdi-arrow-left"></i> Pilih Pekerjaan dahulu</div>');
            $('#addSelectedItemsBtn').prop('disabled', true);
            $('#selectedItemCount').text('0 item dipilih');
            return;
        }
        // Reset item checkbox container if no itemType selected yet
        $('#itemCheckboxContainer').html('<div class="text-center text-muted py-4"><i class="mdi mdi-arrow-left"></i> Pilih Jenis Item terlebih dahulu</div>');
        $('#addSelectedItemsBtn').prop('disabled', true);
        $('#selectedItemCount').text('0 item dipilih');
    }
    
    // Item Type change - load items from selected RAP pekerjaan as checkboxes
    $(document).on('change', '#itemTypeSelect', function() {
        const itemType = $(this).val();
        const selectedIds = getSelectedSubcategoryIds();
        
        console.log('Item Type selected:', itemType, 'Selected subcategories:', selectedIds);
        
        $('#itemCheckboxContainer').html('<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div> Memuat item...</div>');
        $('#addSelectedItemsBtn').prop('disabled', true);
        $('#selectedItemCount').text('0 item dipilih');
        
        if (!itemType || selectedIds.length === 0) {
            $('#itemCheckboxContainer').html('<div class="text-center text-muted py-4"><i class="mdi mdi-arrow-left"></i> Pilih Jenis Item terlebih dahulu</div>');
            return;
        }
        
        $.ajax({
            url: 'create.php',
            data: {
                project_id: projectId,
                ajax: 'get_items_by_selected_rap',
                subcategory_ids: selectedIds.join(','),
                item_type: itemType
            },
            method: 'GET',
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    let totalItemsCount = 0;
                    if (res.data && res.data.length > 0) {
                        res.data.forEach(function(pek) {
                            totalItemsCount += (pek.items ? pek.items.length : 0);
                        });
                    }
                    if (totalItemsCount > 0) {
                        renderItemCheckboxes(res.data, itemType);
                    } else {
                        $('#itemCheckboxContainer').html('<div class="text-center text-muted py-4"><i class="mdi mdi-alert-circle-outline"></i> Tidak ada item ' + itemType + ' pada pekerjaan yang dipilih</div>');
                    }
                } else {
                    $('#itemCheckboxContainer').html('<div class="text-center text-danger py-4">Gagal memuat item: ' + res.message + '</div>');
                }
            },
            error: function() {
                $('#itemCheckboxContainer').html('<div class="text-center text-danger py-4">Gagal memuat item dari server</div>');
            }
        });
    });
    
    // Render item checkboxes table grouped by selected pekerjaan
    function renderItemCheckboxes(pekerjaanList, itemType) {
        const currentWorkType = $('input[name="work_type"]:checked').val() || 'borongan';
        const isHarian = currentWorkType === 'harian';
        const isUpah = itemType === 'upah';
        const isAlat = itemType === 'alat';
        const isMaterial = itemType === 'material';
        
        if (!isHarian) {
            // BORONGAN MODE (Existing unchanged)
            const colCount = isUpah ? 9 : 7;
            let html = '<table class="table table-sm table-hover mb-0" id="itemSelectionTable">';
            html += '<thead class="table-secondary">';
            html += '<tr>';
            html += '<th width="35" class="text-center"><input type="checkbox" class="form-check-input" id="selectAllItems" title="Pilih Semua Item"></th>';
            html += '<th width="65">Kode</th>';
            html += '<th>Nama Item</th>';
            html += '<th width="60">Satuan</th>';
            
            if (isUpah) {
                html += '<th width="85" class="text-center">Sisa Vol.</th>';
                html += '<th width="85" class="text-center">Koef. AHSP</th>';
                html += '<th width="110" class="text-center">Rencana Kerja <span class="text-danger">*</span></th>';
                html += '<th width="120" class="text-center">Harga Satuan <span class="text-danger">*</span></th>';
                html += '<th width="120" class="text-center">Jml Harga</th>';
            } else {
                html += '<th width="80" class="text-center">Sisa</th>';
                html += '<th width="120" class="text-center">Harga Satuan <span class="text-danger">*</span></th>';
                html += '<th width="100" class="text-center">Volume <span class="text-danger">*</span></th>';
            }
            
            html += '</tr></thead><tbody>';
            
            let globalItemIdx = 0;
            pekerjaanList.forEach(function(pek) {
                const hasItems = pek.items && pek.items.length > 0;
                
                // Pekerjaan Header Row
                html += '<tr class="subcat-header-row" data-subcat-id="' + pek.subcategory_id + '">';
                html += '<td class="text-center">';
                if (hasItems) {
                    html += '<input type="checkbox" class="form-check-input subcat-group-check" data-subcat-id="' + pek.subcategory_id + '" title="Pilih Semua Item di Pekerjaan Ini">';
                } else {
                    html += '<i class="mdi mdi-minus text-muted" style="font-size: 0.8rem;"></i>';
                }
                html += '</td>';
                html += '<td colspan="' + (colCount - 1) + '">';
                html += '<div class="d-flex align-items-center justify-content-between">';
                html += '<div>';
                html += '<i class="mdi mdi-briefcase-outline me-1 text-primary"></i>';
                html += '<strong>' + escapeHtml(pek.subcat_code) + '. ' + escapeHtml(pek.subcat_name) + '</strong>';
                html += '</div>';
                if (hasItems) {
                    html += '<span class="badge bg-primary bg-opacity-25 text-primary" style="font-size: 0.72rem;">' + pek.items.length + ' item</span>';
                } else {
                    html += '<span class="badge bg-secondary bg-opacity-25 text-secondary" style="font-size: 0.7rem;">Tidak ada item ' + itemType + '</span>';
                }
                html += '</div></td></tr>';
                
                if (hasItems) {
                    pek.items.forEach(function(item) {
                        const price = item.actual_price || item.unit_price;
                        const ahspCoef = parseFloat(item.ahsp_coefficient) || 0;
                        const sisaQty = parseFloat(item.sisa_qty) || 0;
                        const rapQty = parseFloat(item.rap_qty) || 0;
                        const usedQty = parseFloat(item.used_qty) || 0;
                        const sisaVol = item.sisa_volume !== undefined ? parseFloat(item.sisa_volume) : (ahspCoef > 0 ? (sisaQty / ahspCoef) : 0);
                        const sisaText = sisaQty > 0 ? sisaQty.toLocaleString('id-ID', {maximumFractionDigits: 4}) : '0';
                        const sisaVolText = sisaVol > 0 ? sisaVol.toLocaleString('id-ID', {maximumFractionDigits: 4}) : '0';
                        const idx = globalItemIdx++;
                        
                        html += '<tr class="item-check-row" data-subcat-id="' + pek.subcategory_id + '">';
                        html += '<td class="text-center">';
                        html += '<input type="checkbox" class="form-check-input item-checkbox" ';
                        html += 'data-work-type="borongan" ';
                        html += 'data-subcat-id="' + pek.subcategory_id + '" ';
                        html += 'data-subcat-code="' + escapeHtml(pek.subcat_code) + '" ';
                        html += 'data-subcat-name="' + escapeHtml(pek.subcat_name) + '" ';
                        html += 'data-category-id="' + (pek.category_id || '') + '" ';
                        html += 'data-code="' + (item.item_code || '') + '" ';
                        html += 'data-name="' + escapeHtml(item.name) + '" ';
                        html += 'data-unit="' + escapeHtml(item.unit) + '" ';
                        html += 'data-price="' + price + '" ';
                        html += 'data-ahsp-coef="' + ahspCoef + '" ';
                        html += 'data-sisa-qty="' + sisaQty + '" ';
                        html += 'data-sisa-volume="' + sisaVol + '" ';
                        html += 'data-rap-qty="' + rapQty + '" ';
                        html += 'data-used-qty="' + usedQty + '" ';
                        html += 'data-item-type="' + item.item_type + '">';
                        html += '</td>';
                        html += '<td><small class="text-muted">' + (item.item_code || '-') + '</small></td>';
                        html += '<td class="ps-3">' + escapeHtml(item.name) + '</td>';
                        html += '<td><small>' + escapeHtml(item.unit) + '</small></td>';
                        
                        if (isUpah) {
                            html += '<td class="text-center"><small class="' + (sisaVol > 0 ? 'text-success' : 'text-danger') + ' fw-semibold">' + sisaVolText + '</small></td>';
                            html += '<td class="text-center"><small class="fw-bold text-dark">' + ahspCoef.toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 6}) + '</small></td>';
                            html += '<td><input type="text" class="form-control form-control-sm text-end item-workplan-input" placeholder="0" data-idx="' + idx + '"></td>';
                            html += '<td><input type="text" class="form-control form-control-sm text-end item-price-input" placeholder="' + (price > 0 ? formatNumber(price) : '0') + '" data-idx="' + idx + '"></td>';
                            html += '<td class="text-end fw-bold text-primary item-total-price-cell">Rp 0</td>';
                        } else {
                            html += '<td class="text-center"><small class="' + (sisaQty > 0 ? 'text-success' : 'text-danger') + ' fw-semibold">' + sisaText + '</small></td>';
                            html += '<td><input type="text" class="form-control form-control-sm text-end item-price-input" placeholder="' + (price > 0 ? formatNumber(price) : '0') + '" data-idx="' + idx + '"></td>';
                            html += '<td><input type="text" class="form-control form-control-sm text-end item-coef-input" placeholder="0" data-idx="' + idx + '"></td>';
                        }
                        html += '</tr>';
                    });
                }
            });
            
            html += '</tbody></table>';
            $('#itemCheckboxContainer').html(html);
            return;
        }
        
        // HARIAN MODE
        const colCount = isMaterial ? 9 : 10;
        let html = '<table class="table table-sm table-hover mb-0" id="itemSelectionTable">';
        html += '<thead class="table-secondary">';
        html += '<tr>';
        html += '<th width="35" class="text-center"><input type="checkbox" class="form-check-input" id="selectAllItems" title="Pilih Semua Item"></th>';
        html += '<th width="65">Kode</th>';
        html += '<th>Nama Item</th>';
        html += '<th width="85" class="text-center">Vol. <span class="text-danger">*</span></th>';
        html += '<th width="50" class="text-center">Satuan</th>';
        html += '<th width="65" class="text-center">Jumlah <span class="text-danger">*</span></th>';
        if (!isMaterial) {
            html += '<th width="65" class="text-center">Hari <span class="text-danger">*</span></th>';
        }
        html += '<th width="55" class="text-center">Satuan</th>';
        html += '<th width="120" class="text-center">Harga Satuan <span class="text-danger">*</span></th>';
        html += '<th width="125" class="text-end">Jml Harga</th>';
        html += '</tr></thead><tbody>';
        
        let globalItemIdx = 0;
        pekerjaanList.forEach(function(pek) {
            const hasItems = pek.items && pek.items.length > 0;
            const subcatVol = parseFloat(pek.subcat_volume) || 0;
            const sisaVol = pek.sisa_volume !== undefined ? parseFloat(pek.sisa_volume) : subcatVol;
            const defaultWorkVol = sisaVol > 0 ? sisaVol : subcatVol;
            const subcatUnit = pek.subcat_unit || "m'";
            const rapUnitCost = parseFloat(pek.rap_unit_cost) || 0;
            const rapTotalCost = parseFloat(pek.rap_total_cost) || 0;
            
            html += '<tr class="subcat-header-row" data-subcat-id="' + pek.subcategory_id + '">';
            html += '<td class="text-center">';
            if (hasItems) {
                html += '<input type="checkbox" class="form-check-input subcat-group-check" data-subcat-id="' + pek.subcategory_id + '" title="Pilih Semua Item di Pekerjaan Ini">';
            } else {
                html += '<i class="mdi mdi-minus text-muted" style="font-size: 0.8rem;"></i>';
            }
            html += '</td>';
            html += '<td colspan="' + (colCount - 1) + '">';
            html += '<div class="d-flex align-items-center justify-content-between">';
            html += '<div>';
            html += '<i class="mdi mdi-briefcase-outline me-1 text-primary"></i>';
            html += '<strong>' + escapeHtml(pek.subcat_code) + '. ' + escapeHtml(pek.subcat_name) + '</strong> ';
            html += '<span class="badge harian-badge-vol ms-2 font-size-11" title="Volume RAP Pekerjaan">Vol RAP: ' + subcatVol.toLocaleString('id-ID', {maximumFractionDigits: 2}) + ' ' + escapeHtml(subcatUnit) + '</span>';
            html += '<span class="badge ' + (sisaVol > 0 ? 'bg-soft-success text-success' : 'bg-soft-danger text-danger') + ' ms-1 font-size-11" title="Sisa Volume Bersama">Sisa: ' + sisaVol.toLocaleString('id-ID', {maximumFractionDigits: 2}) + ' ' + escapeHtml(subcatUnit) + '</span>';
            html += '</div>';
            if (hasItems) {
                html += '<span class="badge bg-primary bg-opacity-25 text-primary" style="font-size: 0.72rem;">' + pek.items.length + ' item</span>';
            } else {
                html += '<span class="badge bg-secondary bg-opacity-25 text-secondary" style="font-size: 0.7rem;">Tidak ada item ' + itemType + '</span>';
            }
            html += '</div></td></tr>';
            
            if (hasItems) {
                pek.items.forEach(function(item) {
                    const price = item.actual_price || item.unit_price;
                    const idx = globalItemIdx++;
                    
                    let qtyUnit = 'orang';
                    let durUnit = 'Hr';
                    let billingUnit = 'OH';
                    if (isAlat) {
                        qtyUnit = 'unit';
                        durUnit = 'Hari';
                        billingUnit = 'unit-hari';
                    } else if (isMaterial) {
                        qtyUnit = item.unit || 'ls';
                        durUnit = '-';
                        billingUnit = item.unit || 'ls';
                    }
                    
                    html += '<tr class="item-check-row harian-item-row" data-subcat-id="' + pek.subcategory_id + '">';
                    html += '<td class="text-center">';
                    html += '<input type="checkbox" class="form-check-input item-checkbox" ';
                    html += 'data-work-type="harian" ';
                    html += 'data-subcat-id="' + pek.subcategory_id + '" ';
                    html += 'data-subcat-code="' + escapeHtml(pek.subcat_code) + '" ';
                    html += 'data-subcat-name="' + escapeHtml(pek.subcat_name) + '" ';
                    html += 'data-subcat-unit="' + escapeHtml(subcatUnit) + '" ';
                    html += 'data-subcat-volume="' + subcatVol + '" ';
                    html += 'data-sisa-volume="' + sisaVol + '" ';
                    html += 'data-rap-unit-cost="' + rapUnitCost + '" ';
                    html += 'data-rap-total-cost="' + rapTotalCost + '" ';
                    html += 'data-category-id="' + (pek.category_id || '') + '" ';
                    html += 'data-code="' + (item.item_code || '') + '" ';
                    html += 'data-name="' + escapeHtml(item.name) + '" ';
                    html += 'data-unit="' + escapeHtml(item.unit) + '" ';
                    html += 'data-price="' + price + '" ';
                    html += 'data-item-type="' + item.item_type + '" ';
                    html += 'data-qty-unit="' + qtyUnit + '" ';
                    html += 'data-dur-unit="' + durUnit + '" ';
                    html += 'data-billing-unit="' + billingUnit + '">';
                    html += '</td>';
                    html += '<td><small class="text-muted">' + (item.item_code || '-') + '</small></td>';
                    html += '<td class="ps-2 fw-medium">' + escapeHtml(item.name) + '</td>';
                    
                    // Vol.
                    html += '<td><input type="text" class="form-control form-control-sm text-end item-harian-vol" ';
                    html += 'data-subcat-id="' + pek.subcategory_id + '" data-max-sisa="' + sisaVol + '" ';
                    html += 'value="' + (defaultWorkVol > 0 ? defaultWorkVol.toString().replace('.', ',') : '') + '" placeholder="0"></td>';
                    
                    // Satuan Pekerjaan
                    html += '<td class="text-center"><small>' + escapeHtml(subcatUnit) + '</small></td>';
                    
                    // Jumlah
                    html += '<td><input type="text" class="form-control form-control-sm text-end item-harian-qty" placeholder="0" data-idx="' + idx + '"></td>';
                    
                    // Hari (only for Upah & Alat, Material omits Frek./Hari)
                    if (!isMaterial) {
                        html += '<td><input type="text" class="form-control form-control-sm text-end item-harian-dur" placeholder="0" data-idx="' + idx + '"></td>';
                    } else {
                        html += '<input type="hidden" class="item-harian-dur" value="1" data-idx="' + idx + '">';
                    }
                    
                    // Satuan Tagihan
                    html += '<td class="text-center"><small>' + escapeHtml(billingUnit) + '</small></td>';
                    
                    // Harga Satuan
                    html += '<td><input type="text" class="form-control form-control-sm text-end item-harian-price" ';
                    html += 'value="' + (price > 0 ? formatNumber(price) : '') + '" placeholder="' + (price > 0 ? formatNumber(price) : '0') + '" data-default-price="' + price + '" data-idx="' + idx + '"></td>';
                    
                    // Jml Harga
                    html += '<td class="text-end fw-bold text-primary item-harian-total-cell">Rp 0</td>';
                    
                    html += '</tr>';
                });
            }
        });
        
        html += '</tbody></table>';
        $('#itemCheckboxContainer').html(html);
    }
    
    // Auto-format price inputs in the checkbox table (Borongan)
    $(document).on('input', '.item-price-input', function() {
        autoFormatInput($(this));
        updateRowJmlHarga($(this).closest('tr'));
        checkRowOnInput($(this));
    });
    
    // Allow numbers and comma for workplan / coef inputs in the checkbox table (Borongan)
    $(document).on('input', '.item-workplan-input', function() {
        let val = $(this).val().replace(/[^\d,]/g, '');
        $(this).val(val);
        updateRowJmlHarga($(this).closest('tr'));
        checkRowOnInput($(this));
    });
    
    $(document).on('input', '.item-coef-input', function() {
        let val = $(this).val().replace(/[^\d,]/g, '');
        $(this).val(val);
        checkRowOnInput($(this));
    });

    // Helper to auto-check row checkbox when user types value (Borongan)
    function checkRowOnInput(inputElem) {
        const row = inputElem.closest('tr');
        const cb = row.find('.item-checkbox');
        const val = inputElem.val();
        if (val && parseNumber(val) > 0 && !cb.prop('checked')) {
            cb.prop('checked', true);
            row.addClass('selected');
            const subId = cb.data('subcat-id');
            const totalInSub = $('.item-checkbox[data-subcat-id="' + subId + '"]').length;
            const checkedInSub = $('.item-checkbox[data-subcat-id="' + subId + '"]:checked').length;
            $('.subcat-group-check[data-subcat-id="' + subId + '"]').prop('checked', totalInSub > 0 && totalInSub === checkedInSub);
            
            const total = $('.item-checkbox').length;
            const checked = $('.item-checkbox:checked').length;
            $('#selectAllItems').prop('checked', total > 0 && total === checked);
            updateSelectedItemCount();
        }
    }

    function updateRowJmlHarga(row) {
        if ($('#itemTypeSelect').val() === 'upah') {
            const cb = row.find('.item-checkbox');
            const ahspCoef = parseFloat(cb.attr('data-ahsp-coef')) || 0;
            const workPlan = parseNumber(row.find('.item-workplan-input').val());
            let price = parseNumber(row.find('.item-price-input').val());
            if (price <= 0) {
                price = parseFloat(cb.data('price')) || 0;
            }
            const jmlHarga = ahspCoef * workPlan * price;
            row.find('.item-total-price-cell').text(formatRupiah(jmlHarga));
        }
    }
    
    // Harian input handlers in Step 1 checkbox table
    $(document).on('input', '.item-harian-vol', function() {
        let valStr = $(this).val().replace(/[^\d,]/g, '');
        $(this).val(valStr);
        const subcatId = $(this).data('subcat-id');
        // Auto-sync to all other .item-harian-vol inputs in the same subcategory
        $('.item-harian-vol[data-subcat-id="' + subcatId + '"]').not(this).val(valStr);
        
        const maxSisa = parseFloat($(this).data('max-sisa')) || 0;
        const currentVal = parseNumber(valStr);
        if (maxSisa > 0 && currentVal > maxSisa) {
            $('.item-harian-vol[data-subcat-id="' + subcatId + '"]').addClass('is-invalid');
        } else {
            $('.item-harian-vol[data-subcat-id="' + subcatId + '"]').removeClass('is-invalid');
        }
    });

    $(document).on('input', '.item-harian-qty, .item-harian-dur', function() {
        let val = $(this).val().replace(/[^\d,]/g, '');
        $(this).val(val);
        updateHarianRowJmlHarga($(this).closest('tr'));
        checkRowOnInputHarian($(this));
    });

    $(document).on('input', '.item-harian-price', function() {
        autoFormatInput($(this));
        updateHarianRowJmlHarga($(this).closest('tr'));
        checkRowOnInputHarian($(this));
    });

    function updateHarianRowJmlHarga(row) {
        const qty = parseNumber(row.find('.item-harian-qty').val());
        const durInput = row.find('.item-harian-dur');
        const dur = durInput.length ? (parseNumber(durInput.val()) || 1) : 1;
        let price = parseNumber(row.find('.item-harian-price').val());
        if (price <= 0) {
            price = parseFloat(row.find('.item-checkbox').data('price')) || 0;
        }
        const total = qty * dur * price;
        row.find('.item-harian-total-cell').text(formatRupiah(total));
    }

    function checkRowOnInputHarian(inputElem) {
        const row = inputElem.closest('tr');
        const cb = row.find('.item-checkbox');
        const qty = parseNumber(row.find('.item-harian-qty').val());
        const durInput = row.find('.item-harian-dur');
        const dur = durInput.length ? (parseNumber(durInput.val()) || 1) : 1;
        const price = parseNumber(row.find('.item-harian-price').val()) || parseFloat(cb.data('price')) || 0;
        
        if (qty > 0 && dur > 0 && price > 0 && !cb.prop('checked')) {
            cb.prop('checked', true);
            row.addClass('selected');
            const subId = cb.data('subcat-id');
            const totalInSub = $('.item-checkbox[data-subcat-id="' + subId + '"]').length;
            const checkedInSub = $('.item-checkbox[data-subcat-id="' + subId + '"]:checked').length;
            $('.subcat-group-check[data-subcat-id="' + subId + '"]').prop('checked', totalInSub > 0 && totalInSub === checkedInSub);
            
            const total = $('.item-checkbox').length;
            const checked = $('.item-checkbox:checked').length;
            $('#selectAllItems').prop('checked', total > 0 && total === checked);
            updateSelectedItemCount();
        }
    }
    
    // Handle "Select All Items" checkbox
    $(document).on('change', '#selectAllItems', function() {
        const isChecked = $(this).prop('checked');
        $('.item-checkbox, .subcat-group-check').prop('checked', isChecked);
        $('.item-check-row').toggleClass('selected', isChecked);
        updateSelectedItemCount();
    });

    // Handle subcategory group checkbox
    $(document).on('change', '.subcat-group-check', function() {
        const subId = $(this).data('subcat-id');
        const isChecked = $(this).prop('checked');
        $('.item-checkbox[data-subcat-id="' + subId + '"]').prop('checked', isChecked);
        $('.item-check-row[data-subcat-id="' + subId + '"]').toggleClass('selected', isChecked);
        
        // Update #selectAllItems
        const total = $('.item-checkbox').length;
        const checked = $('.item-checkbox:checked').length;
        $('#selectAllItems').prop('checked', total > 0 && total === checked);
        updateSelectedItemCount();
    });
    
    // Handle individual item checkbox
    $(document).on('change', '.item-checkbox', function() {
        const subId = $(this).data('subcat-id');
        $(this).closest('tr').toggleClass('selected', $(this).prop('checked'));
        
        // Update this subcat group checkbox
        const totalInSub = $('.item-checkbox[data-subcat-id="' + subId + '"]').length;
        const checkedInSub = $('.item-checkbox[data-subcat-id="' + subId + '"]:checked').length;
        $('.subcat-group-check[data-subcat-id="' + subId + '"]').prop('checked', totalInSub > 0 && totalInSub === checkedInSub);
        
        // Update "Select All" state
        const total = $('.item-checkbox').length;
        const checked = $('.item-checkbox:checked').length;
        $('#selectAllItems').prop('checked', total > 0 && total === checked);
        updateSelectedItemCount();
    });
    
    // Update selected item count badge
    function updateSelectedItemCount() {
        const count = $('.item-checkbox:checked').length;
        $('#selectedItemCount').text(count + ' item dipilih');
        $('#addSelectedItemsBtn').prop('disabled', count === 0);
    }
    
    // Add all selected items to the list
    $('#addSelectedItemsBtn').click(function() {
        const checkedItems = $('.item-checkbox:checked');
        if (checkedItems.length === 0) {
            showToast('Pilih minimal satu item!', 'error');
            return;
        }
        
        const currentWorkType = $('input[name="work_type"]:checked').val() || 'borongan';
        const isHarian = currentWorkType === 'harian';
        
        let addedCount = 0;
        let errorCount = 0;
        
        if (isHarian) {
            checkedItems.each(function() {
                const cb = $(this);
                const row = cb.closest('tr');
                const itemType = cb.data('item-type') || '';
                const workVol = parseNumber(row.find('.item-harian-vol').val());
                const workQty = parseNumber(row.find('.item-harian-qty').val());
                const durInput = row.find('.item-harian-dur');
                const workDur = (itemType === 'material') ? 1 : (parseNumber(durInput.val()) || 1);
                let price = parseNumber(row.find('.item-harian-price').val());
                if (price <= 0) {
                    price = parseFloat(cb.data('price')) || 0;
                }
                
                if (workVol <= 0 || workQty <= 0 || workDur <= 0 || price <= 0) {
                    errorCount++;
                    row.addClass('table-danger');
                    return; // skip this item
                }
                row.removeClass('table-danger');
                
                const subcatId = cb.data('subcat-id');
                const subcatCode = cb.data('subcat-code');
                const subcatName = cb.data('subcat-name');
                const subcatUnit = cb.data('subcat-unit') || "m'";
                const categoryId = cb.data('category-id');
                const itemCode = cb.data('code');
                const itemName = cb.data('name');
                const qtyUnit = cb.data('qty-unit') || (itemType === 'material' ? (cb.data('unit') || 'ls') : 'orang');
                const durUnit = (itemType === 'material') ? '-' : (cb.data('dur-unit') || 'Hr');
                const billingUnit = cb.data('billing-unit') || (itemType === 'material' ? qtyUnit : 'OH');
                
                const billableQty = workQty * workDur;
                const totalPrice = billableQty * price;
                
                addItemRow({
                    work_type: 'harian',
                    category_id: categoryId || null,
                    subcategory_id: subcatId,
                    subcat_code: subcatCode,
                    subcat_name: subcatName,
                    subcat_unit: subcatUnit,
                    subcat_volume: parseFloat(cb.data('subcat-volume')) || 0,
                    sisa_volume: parseFloat(cb.data('sisa-volume')) || 0,
                    rap_unit_cost: parseFloat(cb.data('rap-unit-cost')) || 0,
                    rap_total_cost: parseFloat(cb.data('rap-total-cost')) || 0,
                    item_code: itemCode,
                    item_type: itemType || '',
                    item_name: itemName,
                    work_volume: workVol,
                    work_unit: subcatUnit,
                    work_quantity: workQty,
                    work_quantity_unit: qtyUnit,
                    work_duration: workDur,
                    work_duration_unit: durUnit,
                    work_billing_unit: billingUnit,
                    unit: billingUnit,
                    unit_price: price,
                    quantity: billableQty,
                    coefficient: billableQty,
                    total_price: totalPrice,
                    notes: '',
                    is_readonly: true
                });
                
                addedCount++;
                cb.prop('checked', false);
                row.removeClass('selected');
                row.find('.item-harian-qty').val('');
                if (itemType !== 'material') row.find('.item-harian-dur').val('');
                row.find('.item-harian-total-cell').text('Rp 0');
            });
        } else {
            // BORONGAN MODE (Existing unchanged)
            checkedItems.each(function() {
                const cb = $(this);
                const row = cb.closest('tr');
                let price = parseNumber(row.find('.item-price-input').val());
                if (price <= 0) {
                    price = parseFloat(cb.data('price')) || 0;
                }
                
                const itemType = cb.data('item-type');
                let actualCoef = 0;

                if (itemType === 'upah') {
                    const ahspCoef = parseFloat(cb.attr('data-ahsp-coef')) || 0;
                    const workPlan = parseNumber(row.find('.item-workplan-input').val());
                    if (price <= 0 || workPlan <= 0) {
                        errorCount++;
                        row.addClass('table-danger');
                        return; // skip this item
                    }
                    actualCoef = ahspCoef * workPlan;
                } else {
                    const inputVal = parseNumber(row.find('.item-coef-input').val());
                    if (price <= 0 || inputVal <= 0) {
                        errorCount++;
                        row.addClass('table-danger');
                        return; // skip this item
                    }
                    actualCoef = inputVal;
                }
                row.removeClass('table-danger');
                
                const subcatId = cb.data('subcat-id');
                const subcatCode = cb.data('subcat-code');
                const subcatName = cb.data('subcat-name');
                const categoryId = cb.data('category-id');
                
                addItemRow({
                    work_type: 'borongan',
                    category_id: categoryId || null,
                    subcategory_id: subcatId,
                    subcat_code: subcatCode,
                    subcat_name: subcatName,
                    item_code: cb.data('code'),
                    item_type: itemType || '',
                    item_name: cb.data('name'),
                    unit: cb.data('unit'),
                    unit_price: price,
                    coefficient: actualCoef,
                    subcat_details: null,
                    rap_unit_price: parseFloat(cb.data('price')) || 0,
                    sisa_qty: parseFloat(cb.data('sisa-qty')) || 0,
                    is_readonly: true
                });
                
                addedCount++;
                cb.prop('checked', false);
                row.removeClass('selected');
                row.find('.item-price-input').val('');
                row.find('.item-coef-input').val('');
                row.find('.item-workplan-input').val('');
                row.find('.item-total-price-cell').text('Rp 0');
            });
        }
        
        // Update subcat group checkboxes & select all
        $('.subcat-group-check').each(function() {
            const subId = $(this).data('subcat-id');
            const totalInSub = $('.item-checkbox[data-subcat-id="' + subId + '"]').length;
            const checkedInSub = $('.item-checkbox[data-subcat-id="' + subId + '"]:checked').length;
            $(this).prop('checked', totalInSub > 0 && totalInSub === checkedInSub);
        });
        $('#selectAllItems').prop('checked', false);
        updateSelectedItemCount();
        
        if (addedCount > 0) {
            showToast(addedCount + ' item berhasil ditambahkan', 'success');
        }
        if (errorCount > 0) {
            showToast(errorCount + ' item dilewati (kolom wajib belum diisi lengkap)', 'warning');
        }
    });
    
    // =====================================
    // ADD ITEM BUTTON - SHOW MODAL
    // =====================================
    $('#addItemBtn').click(function() {
        if (!selectedSubcategoryId) return;
        
        $('#modalSubcatName').text(selectedSubcatCode + '. ' + selectedSubcatName);
        $('#ahspItemsList').html('<div class="text-center py-3"><div class="spinner-border text-primary"></div></div>');
        
        $('#addItemModal').modal('show');
        
        // Load AHSP items
        $.ajax({
            url: 'create.php?project_id=' + projectId + '&ajax=get_ahsp_items&subcategory_id=' + selectedSubcategoryId,
            method: 'GET',
            dataType: 'json',
            success: function(res) {
                if (res.success && res.data.length > 0) {
                    let html = '<div class="list-group">';
                    res.data.forEach(function(item) {
                        const typeLabel = {'upah': 'Upah', 'material': 'Material', 'alat': 'Alat'}[item.item_type] || item.item_type;
                        const typeBadge = {'upah': 'primary', 'material': 'success', 'alat': 'warning'}[item.item_type] || 'secondary';
                        const price = item.actual_price || item.unit_price;
                        
                        html += `
                            <a href="#" class="list-group-item list-group-item-action ahsp-item-select" 
                               data-code="${item.item_code || ''}"
                               data-name="${item.name}"
                               data-unit="${item.unit}"
                               data-price="${price}"
                               data-coef="${item.coefficient}">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="badge bg-${typeBadge} me-2">${typeLabel}</span>
                                        <strong>${item.name}</strong>
                                        <br><small class="text-muted">${item.item_code || '-'} | ${item.unit} | Rp ${formatNumber(price)}</small>
                                    </div>
                                    <i class="mdi mdi-plus-circle text-success" style="font-size: 1.5rem;"></i>
                                </div>
                            </a>
                        `;
                    });
                    html += '</div>';
                    $('#ahspItemsList').html(html);
                } else {
                    $('#ahspItemsList').html('<div class="alert alert-warning mb-0">Tidak ada item AHSP untuk sub-kategori ini.</div>');
                }
            }
        });
    });
    
    // =====================================
    // SELECT AHSP ITEM FROM MODAL
    // =====================================
    $(document).on('click', '.ahsp-item-select', function(e) {
        e.preventDefault();
        
        const itemCode = $(this).data('code');
        const itemName = $(this).data('name');
        const itemUnit = $(this).data('unit');
        const itemPrice = $(this).data('price');
        const itemCoef = $(this).data('coef') || 1;
        
        addItemRow({
            work_type: $('input[name="work_type"]:checked').val() || 'borongan',
            category_id: selectedCategoryId,
            subcategory_id: selectedSubcategoryId,
            item_code: itemCode,
            item_name: itemName,
            unit: itemUnit,
            unit_price: 0,
            coefficient: 0,
            is_readonly: false
        });
        
        $('#addItemModal').modal('hide');
    });
    
    // =====================================
    // ADD CUSTOM ITEM
    // =====================================
    $('#addCustomItemBtn').click(function() {
        addItemRow({
            work_type: $('input[name="work_type"]:checked').val() || 'borongan',
            category_id: selectedCategoryId,
            subcategory_id: selectedSubcategoryId,
            item_code: '',
            item_name: '',
            unit: '',
            unit_price: 0,
            coefficient: 1,
            is_readonly: false
        });
        
        $('#addItemModal').modal('hide');
    });
    
    // =====================================
    // ADD ITEM ROW TO TABLE (STEP 2)
    // =====================================
    function addItemRow(data) {
        itemIndex++;
        $('#emptyRow').hide();
        
        const isHarianRow = (data.work_type === 'harian');
        
        if (isHarianRow) {
            const isMaterial = (data.item_type === 'material');
            const workVol = data.work_volume || 0;
            const workUnit = data.work_unit || data.subcat_unit || "m'";
            const workQty = data.work_quantity || 0;
            const workQtyUnit = data.work_quantity_unit || (isMaterial ? (data.unit || 'ls') : 'orang');
            const workDur = isMaterial ? 1 : (data.work_duration || 0);
            const workDurUnit = isMaterial ? '-' : (data.work_duration_unit || 'Hr');
            const billingUnit = data.work_billing_unit || data.unit || (isMaterial ? workQtyUnit : 'OH');
            const unitPrice = data.unit_price || 0;
            const billableQty = data.quantity || (workQty * workDur);
            const totalPrice = data.total_price || (billableQty * unitPrice);
            
            const subcatBadge = data.subcat_code ? `<br><span class="badge bg-soft-primary text-primary border border-primary-subtle" style="font-size: 0.72rem;" title="${escapeHtml(data.subcat_name || '')}"><i class="mdi mdi-briefcase-outline"></i> ${escapeHtml(data.subcat_code)}</span>` : '';
            const subcatSubtitle = data.subcat_name ? `<div class="text-muted small mt-1" style="font-size: 0.75rem;"><i class="mdi mdi-arrow-right-bottom text-primary"></i> ${escapeHtml(data.subcat_code ? data.subcat_code + ' - ' : '')}${escapeHtml(data.subcat_name)}</div>` : '';
            
            const row = `
                <tr class="item-row harian-item-row" data-index="${itemIndex}" data-work-type="harian" 
                    data-item-type="${escapeHtml(data.item_type || '')}"
                    data-subcat-id="${data.subcategory_id || ''}" 
                    data-subcat-volume="${data.subcat_volume || 0}" 
                    data-sisa-volume="${data.sisa_volume || 0}" 
                    data-rap-unit-cost="${data.rap_unit_cost || 0}" 
                    data-rap-total-cost="${data.rap_total_cost || 0}">
                    <td class="text-center">${itemIndex}</td>
                    <td>
                        <small class="text-muted fw-semibold">${data.item_code || '<em>Custom</em>'}</small>
                        ${subcatBadge}
                        <input type="hidden" name="items[${itemIndex}][work_type]" value="harian">
                        <input type="hidden" name="items[${itemIndex}][category_id]" value="${data.category_id || ''}">
                        <input type="hidden" name="items[${itemIndex}][subcategory_id]" value="${data.subcategory_id || ''}">
                        <input type="hidden" name="items[${itemIndex}][item_code]" value="${data.item_code || ''}">
                        <input type="hidden" name="items[${itemIndex}][item_type]" value="${data.item_type || ''}">
                        <input type="hidden" name="items[${itemIndex}][work_unit]" value="${escapeHtml(workUnit)}">
                        <input type="hidden" name="items[${itemIndex}][work_quantity_unit]" value="${escapeHtml(workQtyUnit)}">
                        <input type="hidden" name="items[${itemIndex}][work_duration_unit]" value="${escapeHtml(workDurUnit)}">
                        <input type="hidden" name="items[${itemIndex}][work_billing_unit]" value="${escapeHtml(billingUnit)}">
                        <input type="hidden" name="items[${itemIndex}][subcat_details]" value='${data.subcat_details ? JSON.stringify(data.subcat_details).replace(/'/g, "&#39;") : ""}'>
                    </td>
                    <td>
                        <input type="text" name="items[${itemIndex}][item_name]" value="${escapeHtml(data.item_name)}" 
                               ${data.is_readonly ? 'readonly class="form-control form-control-sm readonly-field"' : 'class="form-control form-control-sm"'} placeholder="Nama Item" required>
                        ${subcatSubtitle}
                    </td>
                    <td>
                        <input type="text" name="items[${itemIndex}][work_volume]" 
                               value="${workVol > 0 ? workVol.toString().replace('.', ',') : ''}" 
                               class="form-control form-control-sm text-end harian-row-vol" 
                               data-subcat-id="${data.subcategory_id || ''}" placeholder="0" required>
                    </td>
                    <td class="text-center">
                        <small class="fw-semibold">${escapeHtml(workUnit)}</small>
                    </td>
                    <td>
                        <input type="text" name="items[${itemIndex}][work_quantity]" 
                               value="${workQty > 0 ? workQty.toString().replace('.', ',') : ''}" 
                               class="form-control form-control-sm text-end harian-row-qty" 
                               placeholder="0" required>
                    </td>
                    <td class="text-center">
                        ${isMaterial ? 
                            `<span class="text-muted">-</span><input type="hidden" name="items[${itemIndex}][work_duration]" class="harian-row-dur" value="1">` :
                            `<input type="text" name="items[${itemIndex}][work_duration]" 
                                   value="${workDur > 0 ? workDur.toString().replace('.', ',') : ''}" 
                                   class="form-control form-control-sm text-end harian-row-dur" 
                                   placeholder="0" required>`
                        }
                    </td>
                    <td class="text-center">
                        <small class="fw-semibold">${escapeHtml(billingUnit)}</small>
                        <input type="hidden" name="items[${itemIndex}][unit]" value="${escapeHtml(billingUnit)}">
                    </td>
                    <td>
                        <input type="text" name="items[${itemIndex}][unit_price]" 
                               value="${unitPrice > 0 ? formatNumber(unitPrice) : ''}" 
                               class="form-control form-control-sm text-end harian-row-price price-input" 
                               placeholder="0" required>
                    </td>
                    <td>
                        <input type="text" name="items[${itemIndex}][total_price]" 
                               value="${formatNumber(totalPrice)}" 
                               class="form-control form-control-sm text-end readonly-field total-price harian-row-total" readonly>
                        <input type="hidden" name="items[${itemIndex}][quantity]" class="harian-hidden-qty" value="${billableQty.toFixed(4)}">
                        <input type="hidden" name="items[${itemIndex}][coefficient]" class="harian-hidden-coef" value="${billableQty.toFixed(6)}">
                    </td>
                    <td>
                        <div class="note-input-wrapper position-relative">
                            <input type="text" name="items[${itemIndex}][notes]" 
                                   value="${escapeHtml(data.notes || '')}" 
                                   class="form-control form-control-sm item-notes-input" 
                                   placeholder="Catatan"
                                   title="${escapeHtml(data.notes || '')}">
                            <button type="button" class="note-expand-trigger" title="Perluas catatan" tabindex="-1">
                                <i class="mdi mdi-arrow-expand-all"></i>
                            </button>
                        </div>
                    </td>
                    <td class="text-center harian-status-harga-cell status-harga-cell">
                        <span class="badge bg-secondary">-</span>
                    </td>
                    <td class="text-center harian-status-sisa-cell status-qty-cell">
                        <span class="badge bg-secondary">-</span>
                    </td>
                    <td class="text-center">
                        <span class="remove-item-btn" title="Hapus"><i class="mdi mdi-close-circle" style="font-size: 1.3rem;"></i></span>
                    </td>
                </tr>
            `;
            
            $('#itemsBody').append(row);
            updateNotesOverflowState($('#itemsBody tr:last .item-notes-input')[0]);
            updateHarianSubcategoryStatus(data.subcategory_id);
            updateItemCount();
            calculateGrandTotal();
            return;
        }
        
        // BORONGAN MODE (Existing)
        const readonlyName = data.is_readonly ? 'readonly class="form-control form-control-sm readonly-field"' : 'class="form-control form-control-sm"';
        const readonlyUnit = data.is_readonly ? 'readonly class="form-control form-control-sm readonly-field"' : 'class="form-control form-control-sm"';
        
        const totalPrice = data.unit_price * data.coefficient;
        const coefDisplay = data.coefficient > 0 ? data.coefficient.toString().replace('.', ',') : '';
        
        let statusHargaHtml = '<span class="badge bg-secondary">-</span>';
        const rapUnitPrice = parseFloat(data.rap_unit_price) || 0;
        if (rapUnitPrice > 0 && data.unit_price > 0) {
            const hargaLapangan = data.unit_price * data.coefficient;
            const hargaRap = rapUnitPrice * data.coefficient;
            if (hargaLapangan > hargaRap) {
                const pct = Math.round(((hargaLapangan - hargaRap) / hargaRap) * 100);
                statusHargaHtml = '<span class="badge bg-danger">LEBIH MAHAL ' + pct + '%</span>';
            } else if (hargaLapangan < hargaRap) {
                const pct = Math.round(((hargaRap - hargaLapangan) / hargaRap) * 100);
                statusHargaHtml = '<span class="badge bg-success">HEMAT ' + pct + '%</span>';
            } else {
                statusHargaHtml = '<span class="badge bg-success">AMAN</span>';
            }
        }
        
        let statusQtyHtml = '<span class="badge bg-secondary">-</span>';
        const sisaQty = parseFloat(data.sisa_qty) || 0;
        if (sisaQty > 0 || rapUnitPrice > 0) {
            const afterApproval = sisaQty - data.coefficient;
            if (data.coefficient > sisaQty && sisaQty >= 0) {
                statusQtyHtml = '<span class="badge bg-danger">⚠️ OVER QTY</span>' +
                    '<br><small class="text-danger">Proyeksi sisa: ' + afterApproval.toLocaleString('id-ID', {maximumFractionDigits: 4}) + '</small>';
            } else {
                statusQtyHtml = '<span class="badge bg-success">OK</span>' +
                    '<br><small class="text-success">Proyeksi sisa: ' + afterApproval.toLocaleString('id-ID', {maximumFractionDigits: 4}) + '</small>';
            }
        }
        
        const subcatBadge = data.subcat_code ? `<br><span class="badge bg-soft-primary text-primary border border-primary-subtle" style="font-size: 0.72rem;" title="${escapeHtml(data.subcat_name || '')}"><i class="mdi mdi-briefcase-outline"></i> ${escapeHtml(data.subcat_code)}</span>` : '';
        const subcatSubtitle = data.subcat_name ? `<div class="text-muted small mt-1" style="font-size: 0.75rem;"><i class="mdi mdi-arrow-right-bottom text-primary"></i> ${escapeHtml(data.subcat_code ? data.subcat_code + ' - ' : '')}${escapeHtml(data.subcat_name)}</div>` : '';
        
        const row = `
            <tr class="item-row" data-index="${itemIndex}" data-work-type="borongan" data-item-type="${data.item_type || ''}" 
                data-rap-unit-price="${rapUnitPrice}" data-sisa-qty="${sisaQty}">
                <td class="text-center">${itemIndex}</td>
                <td>
                    <small class="text-muted fw-semibold">${data.item_code || '<em>Custom</em>'}</small>
                    ${subcatBadge}
                    <input type="hidden" name="items[${itemIndex}][work_type]" value="borongan">
                    <input type="hidden" name="items[${itemIndex}][category_id]" value="${data.category_id || ''}">
                    <input type="hidden" name="items[${itemIndex}][subcategory_id]" value="${data.subcategory_id || ''}">
                    <input type="hidden" name="items[${itemIndex}][item_code]" value="${data.item_code || ''}">
                    <input type="hidden" name="items[${itemIndex}][item_type]" value="${data.item_type || ''}">
                    <input type="hidden" name="items[${itemIndex}][subcat_details]" value='${data.subcat_details ? JSON.stringify(data.subcat_details).replace(/'/g, "&#39;") : ""}'>
                </td>
                <td>
                    <input type="text" name="items[${itemIndex}][item_name]" value="${escapeHtml(data.item_name)}" 
                           ${readonlyName} placeholder="Nama Item" required>
                    ${subcatSubtitle}
                </td>
                <td>
                    <input type="text" name="items[${itemIndex}][unit]" value="${escapeHtml(data.unit)}" 
                           ${readonlyUnit} placeholder="Sat" required>
                </td>
                <td>
                    <input type="text" name="items[${itemIndex}][unit_price]" 
                           value="${data.unit_price > 0 ? formatNumber(data.unit_price) : ''}" 
                           class="form-control form-control-sm text-end price-input" 
                           placeholder="0" required>
                </td>
                <td>
                    <input type="text" name="items[${itemIndex}][coefficient]" 
                           value="${coefDisplay}" 
                           class="form-control form-control-sm text-end coef-input" 
                           placeholder="0" required>
                </td>
                <td>
                    <input type="text" name="items[${itemIndex}][total_price]" 
                           value="${formatNumber(totalPrice)}" 
                           class="form-control form-control-sm text-end readonly-field total-price" readonly>
                </td>
                <td>
                    <div class="note-input-wrapper position-relative">
                        <input type="text" name="items[${itemIndex}][notes]" 
                               value="${escapeHtml(data.notes || '')}"
                               class="form-control form-control-sm item-notes-input" 
                               placeholder="Catatan"
                               title="${escapeHtml(data.notes || '')}">
                        <button type="button" class="note-expand-trigger" title="Perluas catatan" tabindex="-1">
                            <i class="mdi mdi-arrow-expand-all"></i>
                        </button>
                    </div>
                </td>
                <td class="text-center status-harga-cell">${statusHargaHtml}</td>
                <td class="text-center status-qty-cell">${statusQtyHtml}</td>
                <td class="text-center">
                    <span class="remove-item-btn" title="Hapus"><i class="mdi mdi-close-circle" style="font-size: 1.3rem;"></i></span>
                </td>
            </tr>
        `;
        
        $('#itemsBody').append(row);
        updateNotesOverflowState($('#itemsBody tr:last .item-notes-input')[0]);
        updateItemCount();
        calculateGrandTotal();
    }
    
    // Calculate & update Status Harga and Status Sisa Qty for Harian subcategory
    function updateHarianSubcategoryStatus(subcatId) {
        if (!subcatId) return;
        const rows = $('#itemsBody tr.harian-item-row[data-subcat-id="' + subcatId + '"]');
        if (rows.length === 0) return;
        
        let subcatTotalLapangan = 0;
        rows.each(function() {
            const rowTot = parseNumber($(this).find('.harian-row-total').val());
            subcatTotalLapangan += rowTot;
        });
        
        const firstRow = rows.first();
        const workVol = parseNumber(firstRow.find('.harian-row-vol').val());
        const workUnit = firstRow.find('input[name*="[work_unit]"]').val() || "m'";
        const rapUnitCost = parseFloat(firstRow.data('rap-unit-cost')) || 0;
        const rapTotalCost = parseFloat(firstRow.data('rap-total-cost')) || 0;
        const sisaVol = parseFloat(firstRow.data('sisa-volume')) || 0;
        
        let effectiveRapTotal = 0;
        if (rapUnitCost > 0 && workVol > 0) {
            effectiveRapTotal = rapUnitCost * workVol;
        } else if (rapTotalCost > 0) {
            effectiveRapTotal = rapTotalCost;
        }
        
        // Status Harga
        let statusHargaBadge = '<span class="badge bg-secondary">-</span>';
        if (effectiveRapTotal > 0 && subcatTotalLapangan > 0) {
            const delta = effectiveRapTotal - subcatTotalLapangan;
            const pctDelta = (delta / effectiveRapTotal) * 100;
            
            if (delta < 0) {
                const pct = Math.abs(pctDelta).toFixed(2);
                statusHargaBadge = '<span class="badge bg-danger" title="Over budget: Selisih Rp ' + formatNumber(delta) + '">lebih mahal -' + pct + '%</span>';
            } else if (delta > 0) {
                const pct = pctDelta.toFixed(2);
                statusHargaBadge = '<span class="badge bg-success" title="Surplus: Hemat Rp ' + formatNumber(delta) + '">hemat +' + pct + '%</span>';
            } else {
                statusHargaBadge = '<span class="badge bg-success">aman 0.00%</span>';
            }
        }
        
        // Status Sisa Qty
        let statusSisaBadge = '<span class="badge bg-secondary">-</span>';
        if (sisaVol >= 0) {
            const projectedSisa = sisaVol - workVol;
            if (projectedSisa < 0) {
                statusSisaBadge = '<span class="badge bg-danger">⚠️ OVER QTY</span><br><small class="text-danger">Sisa: ' + projectedSisa.toLocaleString('id-ID', {maximumFractionDigits: 2}) + ' ' + escapeHtml(workUnit) + '</small>';
            } else if (projectedSisa === 0) {
                statusSisaBadge = '<span class="badge bg-success">0 unt</span>';
            } else {
                statusSisaBadge = '<span class="badge bg-success">' + projectedSisa.toLocaleString('id-ID', {maximumFractionDigits: 2}) + ' ' + escapeHtml(workUnit) + '</span>';
            }
        }
        
        rows.find('.harian-status-harga-cell').html(statusHargaBadge);
        rows.find('.harian-status-sisa-cell').html(statusSisaBadge);
    }
    
    // Step 2 Harian row input handlers
    $(document).on('input', '.harian-row-vol', function() {
        let valStr = $(this).val().replace(/[^\d,]/g, '');
        $(this).val(valStr);
        const subcatId = $(this).data('subcat-id');
        $('.harian-row-vol[data-subcat-id="' + subcatId + '"]').not(this).val(valStr);
        updateHarianSubcategoryStatus(subcatId);
    });

    $(document).on('input', '.harian-row-qty, .harian-row-dur', function() {
        let val = $(this).val().replace(/[^\d,]/g, '');
        $(this).val(val);
        calcHarianStep2Row($(this).closest('tr'));
    });

    $(document).on('input', '.harian-row-price', function() {
        autoFormatInput($(this));
        calcHarianStep2Row($(this).closest('tr'));
    });

    function calcHarianStep2Row(row) {
        const isMaterial = (row.data('item-type') === 'material') || (row.find('input[name*="[item_type]"]').val() === 'material');
        const qty = parseNumber(row.find('.harian-row-qty').val());
        const dur = isMaterial ? 1 : (parseNumber(row.find('.harian-row-dur').val()) || 1);
        const price = parseNumber(row.find('.harian-row-price').val());
        const billableQty = qty * dur;
        const total = billableQty * price;
        
        row.find('.harian-row-total').val(formatNumber(total));
        row.find('.harian-hidden-qty').val(billableQty.toFixed(4));
        row.find('.harian-hidden-coef').val(billableQty.toFixed(6));
        
        const subcatId = row.data('subcat-id');
        updateHarianSubcategoryStatus(subcatId);
        calculateGrandTotal();
    }
    
    // =====================================
    // REMOVE ITEM ROW
    // =====================================
    $(document).on('click', '.remove-item-btn', function() {
        const row = $(this).closest('tr');
        const isHarianRow = row.hasClass('harian-item-row');
        const subcatId = row.data('subcat-id');
        
        row.remove();
        updateItemCount();
        calculateGrandTotal();
        
        if (isHarianRow && subcatId) {
            updateHarianSubcategoryStatus(subcatId);
        }
        
        if ($('#itemsBody tr.item-row').length === 0) {
            $('#emptyRow').show();
        }
    });
    
    // =====================================
    // AUTO-FORMAT PRICE INPUT (BORONGAN)
    // =====================================
    $(document).on('input', '.price-input', function() {
        if ($(this).hasClass('harian-row-price')) return;
        autoFormatInput($(this));
        calculateRowTotal($(this).closest('tr'));
    });
    
    // =====================================
    // COEFFICIENT INPUT (BORONGAN)
    // =====================================
    $(document).on('input', '.coef-input', function() {
        let val = $(this).val().replace(/[^\d,]/g, '');
        $(this).val(val);
        
        const row = $(this).closest('tr');
        calculateRowTotal(row);
    });
    
    // =====================================
    // CALCULATE ROW TOTAL (BORONGAN)
    // =====================================
    function calculateRowTotal(row) {
        if (row.hasClass('harian-item-row')) return;
        const price = parseNumber(row.find('.price-input').val());
        const coefInput = row.find('.coef-input');
        const coef = parseNumber(coefInput.val());
        const total = price * coef;
        
        row.find('.total-price').val(formatNumber(total));
        
        // Dynamically update Status Harga
        const rapUnitPrice = parseFloat(row.data('rap-unit-price')) || 0;
        if (rapUnitPrice > 0 && price > 0) {
            const hargaLapangan = price * coef;
            const hargaRap = rapUnitPrice * coef;
            if (hargaLapangan > hargaRap) {
                const pct = Math.round(((hargaLapangan - hargaRap) / hargaRap) * 100);
                row.find('.status-harga-cell').html('<span class="badge bg-danger">LEBIH MAHAL ' + pct + '%</span>');
            } else if (hargaLapangan < hargaRap) {
                const pct = Math.round(((hargaRap - hargaLapangan) / hargaRap) * 100);
                row.find('.status-harga-cell').html('<span class="badge bg-success">HEMAT ' + pct + '%</span>');
            } else {
                row.find('.status-harga-cell').html('<span class="badge bg-success">AMAN</span>');
            }
        }
        
        // Dynamically update Status Qty
        const sisaQty = parseFloat(row.data('sisa-qty')) || 0;
        if (sisaQty > 0 || rapUnitPrice > 0) {
            const afterApproval = sisaQty - coef;
            if (coef > sisaQty && sisaQty >= 0) {
                row.find('.status-qty-cell').html(
                    '<span class="badge bg-danger">⚠️ OVER QTY</span>' +
                    '<br><small class="text-danger">Proyeksi sisa: ' + afterApproval.toLocaleString('id-ID', {maximumFractionDigits: 4}) + '</small>'
                );
            } else {
                row.find('.status-qty-cell').html(
                    '<span class="badge bg-success">OK</span>' +
                    '<br><small class="text-success">Proyeksi sisa: ' + afterApproval.toLocaleString('id-ID', {maximumFractionDigits: 4}) + '</small>'
                );
            }
        }
        
        calculateGrandTotal();
    }
    
    // =====================================
    // CALCULATE GRAND TOTAL
    // =====================================
    function calculateGrandTotal() {
        let grandTotal = 0;
        $('#itemsBody tr.item-row').each(function() {
            const total = parseNumber($(this).find('.total-price').val());
            grandTotal += total;
        });
        
        $('#grandTotalDisplay').text(formatRupiah(grandTotal));
        $('#grandTotalBig').text(formatRupiah(grandTotal));
        $('#rabSubtotalSummary').text(formatRupiah(grandTotal));
        if (typeof updateCombinedGrandTotal === 'function') {
            updateCombinedGrandTotal();
        }
    }
    
    // =====================================
    // UPDATE ITEM COUNT
    // =====================================
    function updateItemCount() {
        const count = $('#itemsBody tr.item-row').length;
        $('#itemCount').text(count + ' item');
    }
    
    // =====================================
    // FORM SUBMIT
    // =====================================
    // Collect and validate items data
    function collectItemsData() {
        const itemRows = $('#itemsBody tr.item-row');
        const items = [];
        let hasError = false;
        
        itemRows.each(function() {
            const row = $(this);
            const workType = row.data('work-type') || 'borongan';
            
            if (workType === 'harian') {
                const itemName = row.find('input[name*="[item_name]"]').val().trim();
                const itemType = row.find('input[name*="[item_type]"]').val() || row.data('item-type') || '';
                const isMaterial = (itemType === 'material');
                const workVol = parseNumber(row.find('.harian-row-vol').val());
                const workUnit = row.find('input[name*="[work_unit]"]').val() || "m'";
                const workQty = parseNumber(row.find('.harian-row-qty').val());
                const workQtyUnit = row.find('input[name*="[work_quantity_unit]"]').val() || (isMaterial ? (row.find('input[name*="[unit]"]').val() || 'ls') : 'orang');
                let workDur = parseNumber(row.find('.harian-row-dur').val());
                if (isMaterial && workDur <= 0) {
                    workDur = 1;
                }
                const workDurUnit = isMaterial ? '-' : (row.find('input[name*="[work_duration_unit]"]').val() || 'Hr');
                const billingUnit = row.find('input[name*="[work_billing_unit]"]').val() || (isMaterial ? workQtyUnit : 'OH');
                const unitPrice = parseNumber(row.find('.harian-row-price').val());
                
                if (!itemName || workVol <= 0 || workQty <= 0 || workDur <= 0 || unitPrice <= 0) {
                    hasError = true;
                    row.addClass('table-danger');
                } else {
                    row.removeClass('table-danger');
                    const billableQty = workQty * workDur;
                    items.push({
                        work_type: 'harian',
                        category_id: row.find('input[name*="[category_id]"]').val(),
                        subcategory_id: row.find('input[name*="[subcategory_id]"]').val(),
                        item_code: row.find('input[name*="[item_code]"]').val(),
                        item_type: row.find('input[name*="[item_type]"]').val(),
                        item_name: itemName,
                        work_volume: workVol,
                        work_unit: workUnit,
                        work_quantity: workQty,
                        work_quantity_unit: workQtyUnit,
                        work_duration: workDur,
                        work_duration_unit: workDurUnit,
                        work_billing_unit: billingUnit,
                        unit: billingUnit,
                        unit_price: unitPrice,
                        quantity: billableQty,
                        coefficient: billableQty,
                        total_price: billableQty * unitPrice,
                        notes: row.find('input[name*="[notes]"]').val(),
                        subcat_details: row.find('input[name*="[subcat_details]"]').val()
                    });
                }
            } else {
                const itemName = row.find('input[name*="[item_name]"]').val();
                const unit = row.find('input[name*="[unit]"]').val();
                const unitPrice = parseNumber(row.find('.price-input').val());
                const coefInput = row.find('.coef-input');
                const coefficient = parseNumber(coefInput.val());
                
                if (!itemName || !unit || unitPrice <= 0 || coefficient <= 0) {
                    hasError = true;
                    row.addClass('table-danger');
                } else {
                    row.removeClass('table-danger');
                    items.push({
                        work_type: 'borongan',
                        category_id: row.find('input[name*="[category_id]"]').val(),
                        subcategory_id: row.find('input[name*="[subcategory_id]"]').val(),
                        item_code: row.find('input[name*="[item_code]"]').val(),
                        item_type: row.find('input[name*="[item_type]"]').val(),
                        item_name: itemName,
                        unit: unit,
                        unit_price: unitPrice,
                        quantity: coefficient,
                        coefficient: coefficient,
                        total_price: coefficient * unitPrice,
                        notes: row.find('input[name*="[notes]"]').val(),
                        subcat_details: row.find('input[name*="[subcat_details]"]').val()
                    });
                }
            }
        });
        
        return { items, hasError };
    }
    
    // Check for price/qty warnings in the item list
    function getItemWarnings() {
        const warnings = [];
        $('#itemsBody tr.item-row').each(function() {
            const row = $(this);
            const itemName = row.find('input[name*="[item_name]"]').val();
            const hargaCell = row.find('.status-harga-cell').html();
            const qtyCell = row.find('.status-qty-cell').html();
            const issues = [];
            
            if (hargaCell && hargaCell.indexOf('bg-danger') !== -1) {
                issues.push('LEBIH MAHAL');
            }
            if (qtyCell && qtyCell.indexOf('bg-danger') !== -1) {
                issues.push('OVER QTY');
            }
            
            if (issues.length > 0) {
                warnings.push({ name: itemName, issues: issues });
            }
        });
        return warnings;
    }
    
    // =====================================
    // NON-RAB / BIAYA LAIN-LAIN HANDLERS
    // =====================================
    let nonRabItemIndex = 0;

    window.addNonRabItemRowAndFocus = function(item = null) {
        $('#emptyNonRabRow').hide();
        addNonRabItemRow(item);
        if (!item) {
            $('html, body').animate({
                scrollTop: $('#nonRabItemsCard').offset().top - 80
            }, 300);
        }
    };

    window.addNonRabItemRow = function(item = null) {
        $('#emptyNonRabRow').hide();
        nonRabItemIndex++;
        const idx = nonRabItemIndex;
        const name = item ? item.item_name : '';
        const qty = item ? (item.quantity !== undefined && item.quantity !== null && item.quantity !== '' ? item.quantity : (item.coefficient !== undefined ? item.coefficient : 1)) : 1;
        const unit = item ? item.unit : 'ls';
        const price = item ? item.unit_price : '';
        const notes = item ? item.notes : '';

        const html = `
            <tr id="nonRabRow_${idx}" class="non-rab-row">
                <td class="text-center text-muted fw-bold non-rab-row-num"></td>
                <td>
                    <input type="text" class="form-control form-control-sm non-rab-item-name" 
                           placeholder="Nama pengeluaran/kebutuhan..." value="${escapeHtml(name)}" required>
                </td>
                <td>
                    <input type="number" step="0.0001" min="0.0001" class="form-control form-control-sm text-center non-rab-item-qty" 
                           value="${qty}" oninput="calcNonRabRow(${idx})" required>
                </td>
                <td>
                    <input type="text" class="form-control form-control-sm text-center non-rab-item-unit" 
                           value="${escapeHtml(unit)}" placeholder="ls">
                </td>
                <td>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">Rp</span>
                        <input type="text" class="form-control form-control-sm text-end non-rab-item-price" 
                               value="${price ? formatNumberJs(price) : ''}" placeholder="0" 
                               oninput="formatNonRabPriceInput(this); calcNonRabRow(${idx})" required>
                    </div>
                </td>
                <td class="text-end fw-semibold font-size-13 non-rab-row-total">Rp 0</td>
                <td>
                    <div class="note-input-wrapper position-relative">
                        <input type="text" class="form-control form-control-sm non-rab-item-notes item-notes-input" 
                               value="${escapeHtml(notes)}" 
                               placeholder="Catatan tambahan..."
                               title="${escapeHtml(notes)}">
                        <button type="button" class="note-expand-trigger" title="Perluas catatan" tabindex="-1">
                            <i class="mdi mdi-arrow-expand-all"></i>
                        </button>
                    </div>
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-outline-danger btn-sm p-1" onclick="removeNonRabRow(${idx})" title="Hapus baris">
                        <i class="mdi mdi-trash-can-outline font-size-14"></i>
                    </button>
                </td>
            </tr>
        `;
        $('#nonRabItemsBody').append(html);
        updateNotesOverflowState($(`#nonRabRow_${idx} .item-notes-input`)[0]);
        reindexNonRabRows();
        calcNonRabRow(idx);
        if (!item) {
            $(`#nonRabRow_${idx} .non-rab-item-name`).focus();
        }
    };

    window.removeNonRabRow = function(idx) {
        $(`#nonRabRow_${idx}`).remove();
        reindexNonRabRows();
        calcNonRabGrandTotal();
        if ($('#nonRabItemsBody tr.non-rab-row').length === 0) {
            $('#emptyNonRabRow').show();
        }
    };

    function reindexNonRabRows() {
        const rows = $('#nonRabItemsBody tr.non-rab-row');
        rows.each(function(i) {
            $(this).find('.non-rab-row-num').text(i + 1);
        });
        $('#nonRabItemCount').text(rows.length + ' item');
    }

    window.formatNonRabPriceInput = function(elem) {
        let val = elem.value.replace(/[^0-9]/g, '');
        elem.value = val ? parseInt(val, 10).toLocaleString('id-ID') : '';
    };

    function parseFormattedPrice(str) {
        if (!str) return 0;
        return parseFloat(String(str).replace(/\./g, '').replace(/,/g, '.')) || 0;
    }

    function formatRupiahJs(num) {
        return 'Rp ' + (parseFloat(num) || 0).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    function formatNumberJs(num) {
        return (parseFloat(num) || 0).toLocaleString('id-ID');
    }

    window.calcNonRabRow = function(idx) {
        const row = $(`#nonRabRow_${idx}`);
        if (!row.length) return;
        const qty = parseFloat(row.find('.non-rab-item-qty').val()) || 0;
        const price = parseFormattedPrice(row.find('.non-rab-item-price').val());
        const total = qty * price;
        row.find('.non-rab-row-total').text(formatRupiahJs(total));
        calcNonRabGrandTotal();
    };

    function calcNonRabGrandTotal() {
        let grandTotal = 0;
        $('#nonRabItemsBody tr.non-rab-row').each(function() {
            const qty = parseFloat($(this).find('.non-rab-item-qty').val()) || 0;
            const price = parseFormattedPrice($(this).find('.non-rab-item-price').val());
            grandTotal += (qty * price);
        });

        $('#nonRabGrandTotalDisplay').text(formatRupiahJs(grandTotal));
        $('#nonRabSubtotalSummary').text(formatRupiahJs(grandTotal));
        updateCombinedGrandTotal();
    }

    window.updateCombinedGrandTotal = function() {
        let rabTotal = 0;
        $('#itemsBody tr.item-row').each(function() {
            const rowTotal = parseNumber($(this).find('.total-price').val());
            rabTotal += rowTotal;
        });
        let nonRabTotal = 0;
        $('#nonRabItemsBody tr.non-rab-row').each(function() {
            const qty = parseFloat($(this).find('.non-rab-item-qty').val()) || 0;
            const price = parseFormattedPrice($(this).find('.non-rab-item-price').val());
            nonRabTotal += (qty * price);
        });

        $('#rabSubtotalSummary').text(formatRupiahJs(rabTotal));
        $('#nonRabSubtotalSummary').text(formatRupiahJs(nonRabTotal));
        $('#combinedGrandTotalDisplay').text(formatRupiahJs(rabTotal + nonRabTotal));
    };

    // Submit request via AJAX
    function doSubmitRequest(directItems, nonRabItems) {
        $('#submitBtn').prop('disabled', true).html('<i class="mdi mdi-loading mdi-spin"></i> Menyimpan...');
        
        const formData = new FormData();
        formData.append('action', 'save_request');
        formData.append('project_id', projectId);
        formData.append('target_week', $('#targetWeek').val());
        formData.append('description', $('#description').val());
        formData.append('items', JSON.stringify(directItems));
        formData.append('non_rab_items', JSON.stringify(nonRabItems));
        const selectedWorkType = $('input[name="work_type"]:checked').val() || 'borongan';
        formData.append('work_type', selectedWorkType);
        
        if (stagedFiles && stagedFiles.length > 0) {
            const existingAtts = [];
            stagedFiles.forEach(function(item) {
                if (item.isExisting && item.attachment_id) {
                    existingAtts.push({
                        attachment_id: item.attachment_id,
                        custom_name: item.customName
                    });
                } else if (item.file) {
                    formData.append('attachments[]', item.file);
                    formData.append('attachment_names[]', item.customName);
                }
            });
            if (existingAtts.length > 0) {
                formData.append('existing_attachments', JSON.stringify(existingAtts));
            }
        }
        
        $.ajax({
            url: 'create.php?project_id=' + projectId,
            method: 'POST',
            dataType: 'json',
            data: formData,
            processData: false,
            contentType: false,
            timeout: 30000,
            success: function(res) {
                if (res.success) {
                    showToast(res.message, 'success');
                    window.location.href = res.redirect || 'index.php';
                } else {
                    showToast(res.message || 'Terjadi kesalahan', 'error');
                    $('#submitBtn').prop('disabled', false).html('<i class="mdi mdi-send"></i> Kirim Pengajuan');
                }
            },
            error: function(xhr, status, error) {
                console.error('Submit error:', status, error, xhr.responseText);
                showToast('Terjadi kesalahan server: ' + (error || status), 'error');
                $('#submitBtn').prop('disabled', false).html('<i class="mdi mdi-send"></i> Kirim Pengajuan');
            }
        });
    }

    function collectNonRabItems() {
        const nonRabItems = [];
        let hasError = false;

        $('#nonRabItemsBody tr.non-rab-row').each(function() {
            const row = $(this);
            const name = row.find('.non-rab-item-name').val().trim();
            const qty = parseFloat(row.find('.non-rab-item-qty').val()) || 0;
            const unit = row.find('.non-rab-item-unit').val().trim() || 'ls';
            const price = parseFormattedPrice(row.find('.non-rab-item-price').val());
            const notes = row.find('.non-rab-item-notes').val().trim();

            if (!name || qty <= 0 || price <= 0) {
                hasError = true;
                row.addClass('table-danger');
            } else {
                row.removeClass('table-danger');
                nonRabItems.push({
                    item_name: name,
                    quantity: qty,
                    unit: unit,
                    unit_price: price,
                    notes: notes
                });
            }
        });

        return { nonRabItems, hasError };
    }
    
    // Confirm submit button in warning modal
    $('#confirmSubmitBtn').click(function() {
        $('#submitWarningModal').modal('hide');
        const { items: directItems } = collectItemsData();
        const { nonRabItems } = collectNonRabItems();
        doSubmitRequest(directItems, nonRabItems);
    });
    
    $('#requestForm').on('submit', function(e) {
        e.preventDefault();

        // Validate target week
        if (!$('#targetWeek').val()) {
            showToast('Minggu target wajib dipilih!', 'error');
            $('#targetWeek').focus();
            return;
        }

        // Collect direct cost items
        const { items: directItems, hasError: directHasError } = collectItemsData();
        if (directHasError) {
            showToast('Lengkapi semua data item RAB yang ditandai merah!', 'error');
            return;
        }

        // Collect non-RAB items
        const { nonRabItems, hasError: nonRabHasError } = collectNonRabItems();
        if (nonRabHasError) {
            showToast('Lengkapi data pengeluaran Biaya Non-RAB yang ditandai merah!', 'error');
            return;
        }

        if (directItems.length === 0 && nonRabItems.length === 0) {
            showToast('Minimal tambahkan satu item pengajuan (RAB atau Non-RAB)!', 'error');
            return;
        }

        // Check for warnings on direct items
        if (directItems.length > 0) {
            const warnings = getItemWarnings();
            if (warnings.length > 0) {
                let warningHtml = '<table class="table table-sm table-bordered">';
                warningHtml += '<thead class="table-warning"><tr><th>Item</th><th>Peringatan</th></tr></thead><tbody>';
                warnings.forEach(function(w) {
                    warningHtml += '<tr>';
                    warningHtml += '<td>' + escapeHtml(w.name) + '</td>';
                    warningHtml += '<td>';
                    w.issues.forEach(function(issue) {
                        if (issue === 'LEBIH MAHAL') {
                            warningHtml += '<span class="badge bg-danger me-1">LEBIH MAHAL</span>';
                        } else {
                            warningHtml += '<span class="badge bg-danger me-1">⚠️ OVER QTY</span>';
                        }
                    });
                    warningHtml += '</td></tr>';
                });
                warningHtml += '</tbody></table>';
                $('#warningItemsList').html(warningHtml);
                $('#submitWarningModal').modal('show');
                return;
            }
        }
        
        doSubmitRequest(directItems, nonRabItems);
    });
    
    // =====================================
    // ATTACHMENT STAGING & MANAGEMENT
    // =====================================
    let stagedFiles = [];
    const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB
    const ALLOWED_EXTS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    function getFileExtension(filename) {
        return filename.slice((filename.lastIndexOf(".") - 1 >>> 0) + 2).toLowerCase();
    }

    function getFileNameWithoutExt(filename) {
        const lastDot = filename.lastIndexOf('.');
        return lastDot !== -1 ? filename.substring(0, lastDot) : filename;
    }

    function formatBytes(bytes, decimals = 1) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }

    // Process newly selected files (accumulative)
    function handleFileSelection(files) {
        if (!files || files.length === 0) return;

        let addedCount = 0;
        let errors = [];

        Array.from(files).forEach(function(file) {
            const ext = getFileExtension(file.name);
            
            // Validate extension
            if (!ALLOWED_EXTS.includes(ext)) {
                errors.push(`"${file.name}": Format tidak didukung (${ext}). Gunakan JPG, PNG, WEBP, atau PDF.`);
                return;
            }

            // Validate size
            if (file.size > MAX_FILE_SIZE) {
                errors.push(`"${file.name}": Ukuran melebihi 5MB (${formatBytes(file.size)}).`);
                return;
            }

            // Check duplicate by name and size
            const isDuplicate = stagedFiles.some(f => (f.file && f.file.name === file.name && f.file.size === file.size) || (f.isExisting && (f.customName + '.' + f.extension) === file.name && f.size === file.size));
            if (isDuplicate) {
                errors.push(`"${file.name}": File sudah ada di daftar.`);
                return;
            }

            const id = 'file_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            const isImage = file.type.startsWith('image/') || ['jpg', 'jpeg', 'png', 'webp'].includes(ext);
            const isPdf = file.type === 'application/pdf' || ext === 'pdf';
            const previewUrl = (isImage || isPdf) ? URL.createObjectURL(file) : null;

            stagedFiles.push({
                id: id,
                file: file,
                customName: getFileNameWithoutExt(file.name),
                extension: ext,
                isImage: isImage,
                isPdf: isPdf,
                previewUrl: previewUrl,
                size: file.size
            });

            addedCount++;
        });

        if (errors.length > 0) {
            showToast(errors.join('<br>'), 'warning');
        }

        if (addedCount > 0) {
            showToast(`${addedCount} file nota berhasil ditambahkan.`, 'success');
        }

        // Always reset file input value so user can upload again
        $('#attachmentInput').val('');
        renderStagedFiles();
    }

    // Render staged files list
    function renderStagedFiles() {
        const container = $('#stagedFilesContainer');
        const emptyState = $('#stagedFilesEmptyState');
        const listBody = $('#stagedFilesList');
        const countBadge = $('#stagedFilesCountBadge');

        if (stagedFiles.length === 0) {
            container.addClass('d-none');
            emptyState.removeClass('d-none');
            countBadge.text('0 file dipilih');
            listBody.empty();
            return;
        }

        container.removeClass('d-none');
        emptyState.addClass('d-none');

        const totalSize = stagedFiles.reduce((acc, cur) => acc + cur.size, 0);
        countBadge.text(`${stagedFiles.length} file (${formatBytes(totalSize)})`);

        let html = '';
        stagedFiles.forEach(function(item, index) {
            let previewThumb = '';
            if (item.isImage && item.previewUrl) {
                previewThumb = `
                    <div class="position-relative d-inline-block staged-thumb-wrapper" style="width: 48px; height: 48px; cursor: pointer;" onclick="previewStagedFile('${item.id}')" title="Klik untuk preview gambar">
                        <img src="${item.previewUrl}" class="rounded border" style="width: 48px; height: 48px; object-fit: cover;" alt="preview">
                        <div class="thumb-overlay position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center rounded" style="background: rgba(0,0,0,0.35); opacity: 0; transition: opacity 0.2s;">
                            <i class="mdi mdi-eye text-white fs-6"></i>
                        </div>
                    </div>
                `;
            } else {
                previewThumb = `
                    <div class="d-inline-flex align-items-center justify-content-center rounded bg-soft-danger text-danger border border-danger" style="width: 48px; height: 48px; cursor: pointer;" onclick="previewStagedFile('${item.id}')" title="Klik untuk preview PDF">
                        <i class="mdi mdi-file-pdf-box fs-3"></i>
                    </div>
                `;
            }

            const formatBadge = item.isPdf 
                ? '<span class="badge bg-danger">PDF</span>' 
                : `<span class="badge bg-primary">${item.extension.toUpperCase()}</span>`;

            html += `
                <tr id="staged-row-${item.id}">
                    <td class="text-center text-muted fw-bold">${index + 1}</td>
                    <td class="text-center">${previewThumb}</td>
                    <td>
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="flex-grow-1 me-2 text-truncate" style="max-width: 320px;">
                                <span class="fw-semibold text-dark file-display-name" id="name-display-${item.id}" title="${escapeHtml(item.customName)}.${item.extension}">${escapeHtml(item.customName)}</span>
                                <span class="text-muted small">.${item.extension}</span>
                                <div class="text-muted small text-truncate" style="font-size: 0.75rem;">
                                    ${item.isExisting ? '<span class="badge bg-soft-info text-info me-1"><i class="mdi mdi-history"></i> Lampiran Pengajuan Lama</span>' : ''}Nama asli: <em>${escapeHtml(item.file ? item.file.name : item.originalName)}</em>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary btn-edit-name py-1 px-2 text-nowrap" onclick="openEditFileNameModal('${item.id}')" title="Ubah Nama File">
                                <i class="mdi mdi-pencil"></i> Ubah Nama
                            </button>
                        </div>
                    </td>
                    <td class="text-center text-muted small">${formatBytes(item.size)}</td>
                    <td class="text-center">${formatBadge}</td>
                    <td class="text-center">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-primary" onclick="previewStagedFile('${item.id}')" title="Preview">
                                <i class="mdi mdi-eye"></i>
                            </button>
                            <button type="button" class="btn btn-outline-danger" onclick="deleteStagedFile('${item.id}')" title="Hapus">
                                <i class="mdi mdi-trash-can-outline"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        });

        listBody.html(html);

        // Add hover effects to thumbnail overlays
        $('.staged-thumb-wrapper').hover(
            function() { $(this).find('.thumb-overlay').css('opacity', '1'); },
            function() { $(this).find('.thumb-overlay').css('opacity', '0'); }
        );
    }

    // Delete single staged file
    window.deleteStagedFile = function(id) {
        const fileIndex = stagedFiles.findIndex(f => f.id === id);
        if (fileIndex !== -1) {
            const item = stagedFiles[fileIndex];
            if (item.previewUrl && !item.isExisting) {
                URL.revokeObjectURL(item.previewUrl);
            }
            stagedFiles.splice(fileIndex, 1);
            renderStagedFiles();
            showToast('File lampiran dihapus dari daftar.', 'info');
        }
    };

    // Open Edit File Name modal
    window.openEditFileNameModal = function(id) {
        const item = stagedFiles.find(f => f.id === id);
        if (!item) return;

        $('#editFileId').val(item.id);
        $('#editFileNameInput').val(item.customName);
        $('#editFileExtension').text('.' + item.extension);
        
        const modal = new bootstrap.Modal(document.getElementById('editFileNameModal'));
        modal.show();
        
        setTimeout(() => {
            $('#editFileNameInput').focus().select();
        }, 500);
    };

    // Save edited file name
    $('#btnSaveFileName').click(function() {
        const id = $('#editFileId').val();
        const newName = $('#editFileNameInput').val().trim();
        
        if (!newName) {
            showToast('Nama file tidak boleh kosong!', 'warning');
            $('#editFileNameInput').focus();
            return;
        }

        const item = stagedFiles.find(f => f.id === id);
        if (item) {
            item.customName = newName;
            renderStagedFiles();
            bootstrap.Modal.getInstance(document.getElementById('editFileNameModal')).hide();
            showToast('Nama file nota diperbarui.', 'success');
        }
    });

    // Enter key support in edit modal
    $('#editFileNameInput').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#btnSaveFileName').click();
        }
    });

    // Preview Staged File Modal
    window.previewStagedFile = function(id) {
        const item = stagedFiles.find(f => f.id === id);
        if (!item) return;

        $('#previewModalFileName').text(item.customName + '.' + item.extension);
        $('#previewModalFileSize').text(`Ukuran: ${formatBytes(item.size)} | Format: ${item.extension.toUpperCase()}`);
        $('#previewModalOpenNewTab').attr('href', item.previewUrl);

        const container = $('#previewModalContent');
        if (item.isImage) {
            container.html(`
                <div class="text-center">
                    <img src="${item.previewUrl}" class="img-fluid rounded shadow-sm" style="max-height: 70vh; object-fit: contain;" alt="${escapeHtml(item.customName)}">
                </div>
            `);
        } else if (item.isPdf) {
            container.html(`
                <div class="py-4 text-center">
                    <i class="mdi mdi-file-pdf-box text-danger" style="font-size: 5rem;"></i>
                    <h5 class="mt-3">${escapeHtml(item.customName)}.${item.extension}</h5>
                    <p class="text-muted">Dokumen PDF (${formatBytes(item.size)})</p>
                    <a href="${item.previewUrl}" target="_blank" class="btn btn-danger btn-sm">
                        <i class="mdi mdi-open-in-new"></i> Buka Dokumen PDF di Tab Baru
                    </a>
                </div>
            `);
        }

        const modal = new bootstrap.Modal(document.getElementById('attachmentPreviewModal'));
        modal.show();
    };

    // Clear all staged files
    $('#btnClearAllStaged').click(function() {
        if (stagedFiles.length === 0) return;
        if (!confirm('Apakah Anda yakin ingin menghapus semua file nota yang dipilih?')) return;

        stagedFiles.forEach(item => {
            if (item.previewUrl) URL.revokeObjectURL(item.previewUrl);
        });
        stagedFiles = [];
        renderStagedFiles();
        showToast('Semua file nota telah dihapus.', 'info');
    });

    // Trigger file input
    $('#uploadDropzone, #btnAddMoreFiles').click(function(e) {
        if (e.target.id !== 'attachmentInput') {
            $('#attachmentInput').trigger('click');
        }
    });

    $('#attachmentInput').on('change', function() {
        handleFileSelection(this.files);
    });

    // Drag and drop handlers
    const dropzone = document.getElementById('uploadDropzone');
    if (dropzone) {
        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).addClass('border-primary bg-soft-primary').removeClass('bg-light');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('border-primary bg-soft-primary').addClass('bg-light');
            }, false);
        });

        dropzone.addEventListener('drop', function(e) {
            const dt = e.dataTransfer;
            if (dt && dt.files && dt.files.length > 0) {
                handleFileSelection(dt.files);
            }
        }, false);
    }
    
    // =====================================
    // INITIALIZE
    // =====================================
    // Load resubmitted items into table if any
    if (resubmitItems && resubmitItems.length > 0) {
        resubmitItems.forEach(function(item) {
            if (item.subcategory_id) {
                addItemRow(item);
            } else {
                addNonRabItemRow(item);
            }
        });
    }

    // Load resubmitted attachments into staged files if any
    if (resubmitAttachments && resubmitAttachments.length > 0) {
        resubmitAttachments.forEach(function(att) {
            stagedFiles.push({
                id: att.id,
                attachment_id: att.attachment_id,
                file: null,
                originalName: att.originalName,
                customName: att.customName,
                extension: att.extension,
                isImage: att.isImage,
                isPdf: att.isPdf,
                previewUrl: att.previewUrl,
                size: att.fileSize,
                isExisting: true
            });
        });
        renderStagedFiles();
    }
    
    // Load RAP Pekerjaan checkboxes on page load
    loadRapPekerjaan();
    
    // Move Note Tooltip Editor to body to avoid overflow clipping from table-responsive
    if ($('#noteEditorTooltip').length) {
        $('body').append($('#noteEditorTooltip'));
    }
    
    // Evaluate initial overflow state for any existing note inputs
    $('.item-notes-input').each(function() {
        updateNotesOverflowState(this);
    });
});
</script>
<?php 
$extraScripts = ob_get_clean();
?>

<?php endif; ?>

<?php require_once __DIR__ . '/partials/modal_labor_calculator.php'; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
