<?php
/**
 * Create Request - Dynamic Form with Category/Subcategory Selection
 * PCM - Project Cost Management System
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
// Groups items by item_code, summing sisa across all selected subcategories
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
    
    // Get all items per subcategory from Master Data RAP tables (ungrouped)
    $params = array_merge($subcategoryIds, [$itemType]);
    $rawItems = dbGetAll("
        SELECT d.id, pir.category as item_type, pir.item_code, pir.name, pir.unit, 
               d.coefficient as ahsp_coefficient, COALESCE(d.unit_price, pir.price) as unit_price, pir.actual_price,
               rs.id as subcategory_id, rs.code as subcat_code, rs.name as subcat_name, rs.unit as subcat_unit,
               ri.volume as rap_volume,
               (d.coefficient * COALESCE(ri.volume, 0)) as rap_qty,
               (SELECT COALESCE(SUM(reqi2.coefficient), 0) 
                FROM request_items reqi2 
                JOIN requests r ON reqi2.request_id = r.id 
                WHERE reqi2.item_code = pir.item_code 
                  AND reqi2.subcategory_id = rs.id
                  AND r.status IN ('approved','pending')
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
    
    // Group by item_code: sum rap_qty and used_qty, collect subcategory details
    $grouped = [];
    foreach ($rawItems as $item) {
        $code = $item['item_code'];
        if (!isset($grouped[$code])) {
            $grouped[$code] = [
                'item_code' => $code,
                'name' => $item['name'],
                'unit' => ($itemType === 'upah' && !empty($item['subcat_unit']) ? $item['subcat_unit'] : $item['unit']),
                'item_type' => $item['item_type'],
                'unit_price' => $item['unit_price'],
                'actual_price' => $item['actual_price'],
                'ahsp_coefficient' => floatval($item['ahsp_coefficient']),
                'rap_qty' => 0,
                'used_qty' => 0,
                'subcat_details' => [] // per-subcategory capacity for sequential filling
            ];
        }
        $rapQty = floatval($item['rap_qty']);
        $usedQty = floatval($item['used_qty']);
        $grouped[$code]['rap_qty'] += $rapQty;
        $grouped[$code]['used_qty'] += $usedQty;
        $grouped[$code]['subcat_details'][] = [
            'subcategory_id' => intval($item['subcategory_id']),
            'subcat_code' => $item['subcat_code'],
            'subcat_name' => $item['subcat_name'],
            'subcat_unit' => $item['subcat_unit'],
            'ahsp_coefficient' => floatval($item['ahsp_coefficient']),
            'rap_qty' => $rapQty,
            'used_qty' => $usedQty,
            'sisa' => $rapQty - $usedQty
        ];
        // Use actual_price if available, otherwise keep highest unit_price
        if ($item['actual_price'] > $grouped[$code]['actual_price']) {
            $grouped[$code]['actual_price'] = $item['actual_price'];
        }
    }
    
    echo json_encode(['success' => true, 'data' => array_values($grouped)]);
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
                    'item_code' => $item['item_code'] ?: '',
                    'item_type' => $item['item_type'] ?: '',
                    'item_name' => $item['item_name'],
                    'unit' => $item['unit'],
                    'unit_price' => floatval($item['unit_price']),
                    'coefficient' => floatval($item['coefficient'] ?: $item['quantity']),
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
    $items = json_decode($_POST['items'] ?? '[]', true);
    
    if (empty($targetWeek)) {
        echo json_encode(['success' => false, 'message' => 'Minggu target wajib dipilih!']);
        exit;
    }
    
    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Minimal tambahkan satu item!']);
        exit;
    }
    
    try {
        $pdo = getDB();
        $pdo->beginTransaction();
        
        // Double-check lock status before saving
        $projCheck = dbGetRow("SELECT request_locked FROM projects WHERE id = ?", [$projectId]);
        if (!empty($projCheck['request_locked'])) {
            echo json_encode(['success' => false, 'message' => 'Pengajuan dana untuk proyek ini sedang dikunci!']);
            exit;
        }
        
        // Generate request number
        $requestNumber = generateRequestNumber($projectId);
        
        // Create request header with target_week set by requester
        $requestId = dbInsert("
            INSERT INTO requests (project_id, request_number, request_date, week_number, target_week, description, status, created_by)
            VALUES (?, ?, CURDATE(), ?, ?, ?, 'pending', ?)
        ", [$projectId, $requestNumber, $targetWeek, $targetWeek, $description, getCurrentUserId()]);
        
        // Add items
        $totalAmount = 0;
        foreach ($items as $item) {
            $categoryId = intval($item['category_id'] ?? 0);
            $subcategoryId = intval($item['subcategory_id'] ?? 0);
            $itemCode = trim($item['item_code'] ?? '');
            $itemType = trim($item['item_type'] ?? '');
            $itemName = trim($item['item_name'] ?? '');
            $unit = trim($item['unit'] ?? '');
            $unitPrice = floatval($item['unit_price'] ?? 0);
            $coefficient = floatval($item['coefficient'] ?? 0);
            $notes = trim($item['notes'] ?? '');
            $subcatDetails = trim($item['subcat_details'] ?? '');
            
            // Validate subcat_details is valid JSON
            if ($subcatDetails && json_decode($subcatDetails) === null) {
                $subcatDetails = '';
            }
            
            if ($coefficient > 0 && $unitPrice > 0) {
                $totalPrice = $unitPrice * $coefficient;
                
                dbInsert("
                    INSERT INTO request_items 
                    (request_id, category_id, subcategory_id, subcat_details, item_code, item_type, item_name, unit, unit_price, quantity, coefficient, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ", [
                    $requestId, 
                    $categoryId ?: null, 
                    $subcategoryId ?: null,
                    $subcatDetails ?: null,
                    $itemCode ?: null, 
                    $itemType ?: null,
                    $itemName, 
                    $unit, 
                    $unitPrice,
                    $coefficient, 
                    $coefficient,
                    $notes
                ]);
                
                $totalAmount += $totalPrice;
            }
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
            <h4 class="mb-sm-0">Buat Pengajuan Dana</h4>
            <div class="page-title-right">
                <ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>">PCM</a></li>
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
#itemCheckboxContainer { max-height: 400px; overflow-y: auto; }
#itemCheckboxContainer table th,
#itemCheckboxContainer table td { vertical-align: middle; font-size: 0.85rem; }
#itemCheckboxContainer .item-check-row:hover { background: #f0f7ff; }
#itemCheckboxContainer .item-check-row.selected { background: #e8f5e9; }
.btn-add-selected { position: sticky; bottom: 0; background: #fff; border-top: 2px solid #28a745; }
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
                        Seluruh item pengajuan dan file lampiran telah dimuat otomatis. Anda dapat menyesuaikan harga/koefisien, menghapus item yang salah, menambah item baru, atau mengedit lampiran sebelum mengirim.
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
                    <h6 class="mb-0"><i class="mdi mdi-information-outline"></i> Informasi Pengajuan</h6>
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
                            <select class="form-select" name="target_week" id="targetWeek" required>
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
                            <small class="text-muted">Centang pekerjaan yang akan diajukan dana-nya</small>
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
            
            <!-- Items Table Card -->
            <div class="card mb-3">
                <div class="card-header bg-success text-white py-2 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="mdi mdi-cart"></i> Detail Item Pengajuan</h6>
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
                                    <th width="90" id="tableCoefHeader">Koefisien</th>
                                    <th width="130">Total Harga</th>
                                    <th width="100">Catatan</th>
                                    <th width="100">Status Harga</th>
                                    <th width="110">Status Qty</th>
                                    <th width="40">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody">
                                <tr id="emptyRow">
                                    <td colspan="11" class="text-center text-muted py-4">
                                        <i class="mdi mdi-cart-outline" style="font-size: 2rem;"></i>
                                        <p class="mb-0 mt-2">Belum ada item. Pilih kategori & sub-kategori lalu klik "Tambah Item".</p>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="6" class="text-end"><strong>GRAND TOTAL</strong></td>
                                    <td class="text-end"><strong id="grandTotalDisplay">Rp 0</strong></td>
                                    <td colspan="4"></td>
                                </tr>
                            </tfoot>
                        </table>
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
    
    const projectId = <?= $projectId ?>;
    const resubmitItems = <?= json_encode($resubmitItems ?? []) ?>;
    const resubmitAttachments = <?= json_encode($resubmitAttachments ?? []) ?>;
    const resubmitSubcatIds = <?= json_encode(array_values(array_keys($resubmitSubcatIds ?? []))) ?>;
    console.log('Project ID:', projectId, 'Resubmit items:', resubmitItems.length);
    
    let itemIndex = 0;
    let selectedCategoryId = null;
    let selectedSubcategoryId = null;
    let selectedSubcatName = '';
    let selectedSubcatCode = '';
    
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
        if (selectedIds.length > 0) {
            $('#itemTypeSelect').html(`
                <option value="">-- Pilih Jenis Item --</option>
                <option value="upah">Upah</option>
                <option value="material">Material</option>
                <option value="alat">Alat</option>
            `).prop('disabled', false);
        } else {
            $('#itemTypeSelect').html('<option value="">-- Pilih Pekerjaan dahulu --</option>').prop('disabled', true);
        }
        // Reset item checkbox container
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
                    if (res.data.length > 0) {
                        renderItemCheckboxes(res.data, itemType);
                    } else {
                        $('#itemCheckboxContainer').html('<div class="text-center text-muted py-4"><i class="mdi mdi-alert-circle-outline"></i> Tidak ada item ' + itemType + '</div>');
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
    
    // Render item checkboxes table (items grouped by item_code)
    function renderItemCheckboxes(items, itemType) {
        const isUpah = itemType === 'upah';
        
        let html = '<table class="table table-sm table-hover mb-0">';
        html += '<thead class="table-secondary" style="position:sticky;top:0;z-index:1;">';
        html += '<tr>';
        html += '<th width="35" class="text-center"><input type="checkbox" class="form-check-input" id="selectAllItems" title="Pilih Semua"></th>';
        html += '<th width="50">Kode</th>';
        html += '<th>Nama Item</th>';
        html += '<th width="60">Satuan</th>';
        
        if (isUpah) {
            html += '<th width="85" class="text-center">Koef. AHSP</th>';
            html += '<th width="110" class="text-center">Rencana Kerja <span class="text-danger">*</span></th>';
            html += '<th width="120" class="text-center">Harga Satuan <span class="text-danger">*</span></th>';
            html += '<th width="120" class="text-center">Jml Harga</th>';
        } else {
            html += '<th width="80" class="text-center">Sisa Total</th>';
            html += '<th width="120" class="text-center">Harga Satuan <span class="text-danger">*</span></th>';
            html += '<th width="100" class="text-center">Koefisien <span class="text-danger">*</span></th>';
        }
        
        html += '</tr></thead><tbody>';
        
        items.forEach(function(item, idx) {
            const price = item.actual_price || item.unit_price;
            const ahspCoef = parseFloat(item.ahsp_coefficient) || 0;
            const rapQty = parseFloat(item.rap_qty) || 0;
            const usedQty = parseFloat(item.used_qty) || 0;
            const sisaQty = rapQty - usedQty;
            const sisaText = sisaQty > 0 ? sisaQty.toLocaleString('id-ID', {maximumFractionDigits: 4}) : '0';
            
            // Build tooltip showing per-pekerjaan breakdown
            let tooltipLines = [];
            if (item.subcat_details && item.subcat_details.length > 1) {
                item.subcat_details.forEach(function(sd) {
                    const sdSisa = sd.sisa > 0 ? sd.sisa.toLocaleString('id-ID', {maximumFractionDigits: 4}) : '0';
                    tooltipLines.push(sd.subcat_code + ': sisa ' + sdSisa);
                });
            }
            const tooltipAttr = tooltipLines.length > 0 
                ? ' data-bs-toggle="tooltip" data-bs-placement="left" title="' + escapeHtml(tooltipLines.join('\n')) + '"' 
                : '';
            
            // Store subcat_details as JSON in data attribute
            const subcatDetailsJson = JSON.stringify(item.subcat_details || []);
            
            html += '<tr class="item-check-row">';
            html += '<td class="text-center">';
            html += '<input type="checkbox" class="form-check-input item-checkbox" ';
            html += 'data-code="' + (item.item_code || '') + '" ';
            html += 'data-name="' + escapeHtml(item.name) + '" ';
            html += 'data-unit="' + item.unit + '" ';
            html += 'data-price="' + price + '" ';
            html += 'data-ahsp-coef="' + ahspCoef + '" ';
            html += 'data-sisa-qty="' + sisaQty + '" ';
            html += 'data-item-type="' + item.item_type + '" ';
            html += "data-subcat-details='" + subcatDetailsJson.replace(/'/g, '&#39;') + "'>";
            html += '</td>';
            html += '<td><small class="text-muted">' + (item.item_code || '-') + '</small></td>';
            html += '<td>' + escapeHtml(item.name);
            // Show pekerjaan count badge if item spans multiple pekerjaan
            if (item.subcat_details && item.subcat_details.length > 1) {
                html += ' <span class="badge bg-info bg-opacity-25 text-info" style="font-size:0.7rem;">' + item.subcat_details.length + ' pekerjaan</span>';
            }
            html += '</td>';
            html += '<td><small>' + item.unit + '</small></td>';
            
            if (isUpah) {
                html += '<td class="text-center"><small class="fw-bold text-dark">' + ahspCoef.toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 6}) + '</small></td>';
                html += '<td><input type="text" class="form-control form-control-sm text-end item-workplan-input" placeholder="0" data-idx="' + idx + '"></td>';
                html += '<td><input type="text" class="form-control form-control-sm text-end item-price-input" placeholder="0" data-idx="' + idx + '"></td>';
                html += '<td class="text-end fw-bold text-primary item-total-price-cell">Rp 0</td>';
            } else {
                html += '<td class="text-center"' + tooltipAttr + '><small class="' + (sisaQty > 0 ? 'text-success' : 'text-danger') + '">' + sisaText + '</small></td>';
                html += '<td><input type="text" class="form-control form-control-sm text-end item-price-input" placeholder="0" data-idx="' + idx + '"></td>';
                html += '<td><input type="text" class="form-control form-control-sm text-end item-coef-input" placeholder="0" data-idx="' + idx + '"></td>';
            }
            html += '</tr>';
        });
        
        html += '</tbody></table>';
        $('#itemCheckboxContainer').html(html);
        
        // Re-init tooltips for sisa breakdown
        $('[data-bs-toggle="tooltip"]').tooltip();
    }

    
    // Auto-format price inputs in the checkbox table
    $(document).on('input', '.item-price-input', function() {
        autoFormatInput($(this));
        updateRowJmlHarga($(this).closest('tr'));
    });
    
    // Allow numbers and comma for workplan / coef inputs in the checkbox table
    $(document).on('input', '.item-workplan-input', function() {
        let val = $(this).val().replace(/[^\d,]/g, '');
        $(this).val(val);
        updateRowJmlHarga($(this).closest('tr'));
    });
    
    $(document).on('input', '.item-coef-input', function() {
        let val = $(this).val().replace(/[^\d,]/g, '');
        $(this).val(val);
    });

    function updateRowJmlHarga(row) {
        if ($('#itemTypeSelect').val() === 'upah') {
            const cb = row.find('.item-checkbox');
            const ahspCoef = parseFloat(cb.attr('data-ahsp-coef')) || 0;
            const workPlan = parseNumber(row.find('.item-workplan-input').val());
            const price = parseNumber(row.find('.item-price-input').val());
            const jmlHarga = ahspCoef * workPlan * price;
            row.find('.item-total-price-cell').text(formatRupiah(jmlHarga));
        }
    }
    
    // Handle "Select All Items" checkbox
    $(document).on('change', '#selectAllItems', function() {
        const isChecked = $(this).prop('checked');
        $('.item-checkbox').prop('checked', isChecked);
        $('.item-check-row').toggleClass('selected', isChecked);
        updateSelectedItemCount();
    });
    
    // Handle individual item checkbox
    $(document).on('change', '.item-checkbox', function() {
        $(this).closest('tr').toggleClass('selected', $(this).prop('checked'));
        // Update "Select All" state
        const total = $('.item-checkbox').length;
        const checked = $('.item-checkbox:checked').length;
        $('#selectAllItems').prop('checked', total === checked);
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
        
        let addedCount = 0;
        let errorCount = 0;
        
        checkedItems.each(function() {
            const cb = $(this);
            const row = cb.closest('tr');
            const price = parseNumber(row.find('.item-price-input').val());
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
            
            // Get subcat_details for sequential filling
            let subcatDetails = [];
            try {
                subcatDetails = JSON.parse(cb.attr('data-subcat-details') || '[]');
            } catch(e) { subcatDetails = []; }
            
            // Use first subcategory as primary (for backward compatibility)
            const primarySubcatId = subcatDetails.length > 0 ? subcatDetails[0].subcategory_id : null;
            
            addItemRow({
                category_id: null,
                subcategory_id: primarySubcatId,
                item_code: cb.data('code'),
                item_type: itemType || '',
                item_name: cb.data('name'),
                unit: cb.data('unit'),
                unit_price: price,
                coefficient: actualCoef,
                subcat_details: subcatDetails,
                rap_unit_price: parseFloat(cb.data('price')) || 0,
                sisa_qty: parseFloat(cb.data('sisa-qty')) || 0,
                is_readonly: true
            });

            
            addedCount++;
            // Uncheck the added item and reset its inputs
            cb.prop('checked', false);
            row.removeClass('selected');
            row.find('.item-price-input').val('');
            row.find('.item-coef-input').val('');
            row.find('.item-workplan-input').val('');
            row.find('.item-total-price-cell').text('Rp 0');
        });
        
        // Update select all and counter
        $('#selectAllItems').prop('checked', false);
        updateSelectedItemCount();
        
        if (addedCount > 0) {
            showToast(addedCount + ' item berhasil ditambahkan', 'success');
        }
        if (errorCount > 0) {
            showToast(errorCount + ' item dilewati (harga/koefisien/rencana kerja belum diisi)', 'warning');
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
    // ADD ITEM ROW TO TABLE
    // =====================================
    function addItemRow(data) {
        itemIndex++;
        $('#emptyRow').hide();
        
        const readonlyName = data.is_readonly ? 'readonly class="form-control form-control-sm readonly-field"' : 'class="form-control form-control-sm"';
        const readonlyUnit = data.is_readonly ? 'readonly class="form-control form-control-sm readonly-field"' : 'class="form-control form-control-sm"';
        
        const totalPrice = data.unit_price * data.coefficient;
        const coefDisplay = data.coefficient > 0 ? data.coefficient.toString().replace('.', ',') : '';
        
        // Status Harga: compare unit_price (field) vs rap_unit_price
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
        
        // Status Qty: compare coefficient vs sisa_qty
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
        
        const row = `
            <tr class="item-row" data-index="${itemIndex}" data-item-type="${data.item_type || ''}" 
                data-rap-unit-price="${rapUnitPrice}" data-sisa-qty="${sisaQty}">
                <td class="text-center">${itemIndex}</td>
                <td>
                    <small class="text-muted">${data.item_code || '<em>Custom</em>'}</small>
                    <input type="hidden" name="items[${itemIndex}][category_id]" value="${data.category_id}">
                    <input type="hidden" name="items[${itemIndex}][subcategory_id]" value="${data.subcategory_id}">
                    <input type="hidden" name="items[${itemIndex}][item_code]" value="${data.item_code}">
                    <input type="hidden" name="items[${itemIndex}][item_type]" value="${data.item_type || ''}">
                    <input type="hidden" name="items[${itemIndex}][subcat_details]" value='${JSON.stringify(data.subcat_details || []).replace(/'/g, "&#39;")}' >
                </td>
                <td>
                    <input type="text" name="items[${itemIndex}][item_name]" value="${data.item_name}" 
                           ${readonlyName} placeholder="Nama Item" required>
                </td>
                <td>
                    <input type="text" name="items[${itemIndex}][unit]" value="${data.unit}" 
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
                    <input type="text" name="items[${itemIndex}][notes]" 
                           value="${escapeHtml(data.notes || '')}"
                           class="form-control form-control-sm" placeholder="Catatan">
                </td>
                <td class="text-center status-harga-cell">${statusHargaHtml}</td>
                <td class="text-center status-qty-cell">${statusQtyHtml}</td>
                <td class="text-center">
                    <span class="remove-item-btn" title="Hapus"><i class="mdi mdi-close-circle" style="font-size: 1.3rem;"></i></span>
                </td>
            </tr>
        `;
        
        $('#itemsBody').append(row);
        updateItemCount();
        calculateGrandTotal();
    }
    
    // =====================================
    // REMOVE ITEM ROW
    // =====================================
    $(document).on('click', '.remove-item-btn', function() {
        $(this).closest('tr').remove();
        updateItemCount();
        calculateGrandTotal();
        
        if ($('#itemsBody tr.item-row').length === 0) {
            $('#emptyRow').show();
        }
    });
    
    // =====================================
    // AUTO-FORMAT PRICE INPUT
    // =====================================
    $(document).on('input', '.price-input', function() {
        autoFormatInput($(this));
        calculateRowTotal($(this).closest('tr'));
    });
    
    // =====================================
    // COEFFICIENT INPUT
    // =====================================
    $(document).on('input', '.coef-input', function() {
        // Allow numbers and comma for decimal
        let val = $(this).val().replace(/[^\d,]/g, '');
        $(this).val(val);
        
        const row = $(this).closest('tr');
        calculateRowTotal(row);
    });
    
    // =====================================
    // CALCULATE ROW TOTAL
    // =====================================
    function calculateRowTotal(row) {
        const price = parseNumber(row.find('.price-input').val());
        const coefInput = row.find('.coef-input');
        const coef = parseNumber(coefInput.val());
        const total = price * coef;
        
        row.find('.total-price').val(formatNumber(total));
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
                    category_id: row.find('input[name*="[category_id]"]').val(),
                    subcategory_id: row.find('input[name*="[subcategory_id]"]').val(),
                    item_code: row.find('input[name*="[item_code]"]').val(),
                    item_type: row.find('input[name*="[item_type]"]').val(),
                    item_name: itemName,
                    unit: unit,
                    unit_price: unitPrice,
                    coefficient: coefficient,
                    notes: row.find('input[name*="[notes]"]').val(),
                    subcat_details: row.find('input[name*="[subcat_details]"]').val()
                });
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
    
    // Actually submit the form data via AJAX
    function doSubmitRequest(items) {
        $('#submitBtn').prop('disabled', true).html('<i class="mdi mdi-loading mdi-spin"></i> Menyimpan...');
        
        const formData = new FormData();
        formData.append('action', 'save_request');
        formData.append('project_id', projectId);
        formData.append('description', $('#description').val());
        formData.append('target_week', $('#targetWeek').val());
        formData.append('items', JSON.stringify(items));
        
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
    
    // Confirm submit button in warning modal
    $('#confirmSubmitBtn').click(function() {
        $('#submitWarningModal').modal('hide');
        const { items } = collectItemsData();
        doSubmitRequest(items);
    });
    
    $('#requestForm').on('submit', function(e) {
        e.preventDefault();
        
        const itemRows = $('#itemsBody tr.item-row');
        if (itemRows.length === 0) {
            showToast('Tambahkan minimal satu item!', 'error');
            return;
        }
        
        // Validate target week
        if (!$('#targetWeek').val()) {
            showToast('Minggu target wajib dipilih!', 'error');
            $('#targetWeek').focus();
            return;
        }
        
        const { items, hasError } = collectItemsData();
        
        if (hasError) {
            showToast('Lengkapi semua data item yang ditandai merah!', 'error');
            return;
        }
        
        // Check for warnings
        const warnings = getItemWarnings();
        if (warnings.length > 0) {
            // Show warning modal
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
        
        // No warnings, submit directly
        doSubmitRequest(items);
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
            addItemRow(item);
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
});
</script>
<?php 
$extraScripts = ob_get_clean();
?>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
