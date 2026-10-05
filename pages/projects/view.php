<?php
/**
 * Project Dashboard - Tabbed View
 * PCC - Project Cost Control System
 */

// AJAX Handler: Weekly Detail Modal - returns FULL request details for a specific week+subcategory
if (isset($_GET['ajax']) && $_GET['ajax'] === 'weekly_detail') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    
    header('Content-Type: application/json');
    $pId = intval($_GET['project_id'] ?? $_GET['id'] ?? 0);
    $subId = intval($_GET['subcategory_id'] ?? 0);
    $weekNum = intval($_GET['week_number'] ?? 0);
    
    if (!$pId || !$subId || !$weekNum) {
        echo json_encode(['error' => 'Parameter tidak lengkap']);
        exit;
    }
    
    $requests = dbGetAll("
        SELECT req.id, req.request_number, req.request_date, req.description, req.status,
               req.target_week, req.week_number,
               req.admin_notes, req.approved_at, req.created_at,
               req.pm_notes, req.pm_approved_at,
               u.full_name as created_by_name,
               ua.full_name as approved_by_name,
               upm.full_name as pm_approved_by_name
        FROM requests req
        JOIN request_items reqi ON reqi.request_id = req.id
        LEFT JOIN users u ON req.created_by = u.id
        LEFT JOIN users ua ON req.approved_by = ua.id
        LEFT JOIN users upm ON req.pm_approved_by = upm.id
        WHERE req.project_id = ? 
          AND req.status = 'approved'
          AND (req.target_week = ? OR (req.target_week IS NULL AND req.week_number = ?))
          AND reqi.subcategory_id = ?
        GROUP BY req.id
        ORDER BY req.created_at ASC
    ", [$pId, $weekNum, $weekNum, $subId]);
    
    // For each request, get items, actuals, and attachments
    foreach ($requests as &$req) {
        // Items
        $req['items'] = dbGetAll("
            SELECT reqi.*, rs.code as subcategory_code, rs.name as subcategory_name
            FROM request_items reqi
            LEFT JOIN rab_subcategories rs ON reqi.subcategory_id = rs.id
            WHERE reqi.request_id = ?
            ORDER BY rs.code, reqi.item_name
        ", [$req['id']]);
        
        $req['total_amount'] = array_sum(array_column($req['items'], 'total_price'));
        
        // Category totals for actuals comparison
        $catTotalsRows = dbGetAll("
            SELECT COALESCE(pi.category, reqi.item_type, 'other') as item_category,
                   SUM(reqi.total_price) as total
            FROM request_items reqi
            LEFT JOIN project_items pi ON pi.item_code = reqi.item_code AND pi.project_id = ?
            WHERE reqi.request_id = ?
            GROUP BY item_category
        ", [$pId, $req['id']]);
        
        $req['cat_totals'] = ['upah' => 0, 'material' => 0, 'alat' => 0];
        foreach ($catTotalsRows as $row) {
            $c = $row['item_category'] ?? '';
            if (isset($req['cat_totals'][$c])) $req['cat_totals'][$c] = floatval($row['total']);
        }
        
        // Actuals
        $actual = dbGetRow("
            SELECT ra.*, u.full_name as actual_created_by_name
            FROM request_actuals ra
            LEFT JOIN users u ON ra.created_by = u.id
            WHERE ra.request_id = ?
        ", [$req['id']]);
        
        $req['actuals'] = $actual ?: null;
        
        // Attachments (only if actuals exist)
        $req['attachments'] = [];
        if ($actual) {
            $req['attachments'] = dbGetAll("
                SELECT filename, original_name, file_type
                FROM request_actual_attachments
                WHERE request_actual_id = ?
                ORDER BY created_at
            ", [$actual['id']]);
        }
    }
    unset($req);
    
    echo json_encode(['data' => $requests]);
    exit;
}

// AJAX Handler: Non-RAB Detail Modal - returns requests & items for Non-RAB category
if (isset($_GET['ajax']) && $_GET['ajax'] === 'non_rab_detail') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    
    header('Content-Type: application/json');
    $pId = intval($_GET['project_id'] ?? $_GET['id'] ?? 0);
    $category = trim($_GET['category'] ?? '');
    
    if (!$pId) {
        echo json_encode(['error' => 'Parameter tidak lengkap']);
        exit;
    }
    
    $where = "req.project_id = ? AND req.request_type = 'non_rab' AND req.status = 'approved'";
    $params = [$pId];
    if ($category) {
        $where .= " AND req.non_rab_category = ?";
        $params[] = $category;
    }
    
    $requests = dbGetAll("
        SELECT req.id, req.request_number, req.request_date, req.non_rab_category, req.description, req.status,
               req.target_week, req.week_number,
               req.admin_notes, req.approved_at, req.created_at,
               req.pm_notes, req.pm_approved_at,
               u.full_name as created_by_name,
               ua.full_name as approved_by_name,
               upm.full_name as pm_approved_by_name
        FROM requests req
        LEFT JOIN users u ON req.created_by = u.id
        LEFT JOIN users ua ON req.approved_by = ua.id
        LEFT JOIN users upm ON req.pm_approved_by = upm.id
        WHERE $where
        ORDER BY req.created_at DESC
    ", $params);
    
    $categories = getNonRabCategories();
    
    foreach ($requests as &$req) {
        $req['category_name'] = $categories[$req['non_rab_category']] ?? $req['non_rab_category'];
        $req['items'] = dbGetAll("
            SELECT reqi.*
            FROM request_items reqi
            WHERE reqi.request_id = ?
            ORDER BY reqi.id ASC
        ", [$req['id']]);
        
        $reqTotal = 0;
        foreach ($req['items'] as &$it) {
            $itTotal = floatval($it['quantity']) * floatval($it['unit_price']);
            $it['subtotal'] = $itTotal;
            $reqTotal += $itTotal;
        }
        unset($it);
        $req['total_amount'] = $reqTotal;
    }
    unset($req);
    
    echo json_encode(['data' => $requests]);
    exit;
}

// AJAX Handler - Must be FIRST before any includes to prevent output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_item_ajax') {
    // Only load what we need for AJAX
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/../../includes/auth.php';
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id']) || !hasPermission('master_data.edit')) {
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'Unauthorized']));
    }
    
    $projectId = $_GET['id'] ?? null;
    $itemId = $_POST['item_id'];
    $itemCode = trim($_POST['item_code'] ?? '');
    $name = trim($_POST['item_name']);
    $brand = trim($_POST['item_brand'] ?? '');
    $category = $_POST['item_category'];
    $unit = trim($_POST['item_unit']);
    $priceRaw = $_POST['item_price'];
    $price = floatval(str_replace(',', '.', str_replace('.', '', $priceRaw)));
    $actualPriceRaw = $_POST['item_actual_price'] ?? '';
    $actualPrice = !empty($actualPriceRaw) ? floatval(str_replace(',', '.', str_replace('.', '', $actualPriceRaw))) : null;
    
    try {
        // Check for duplicate item_code across RAB and RAP (excluding current item and its counterpart)
        if (!empty($itemCode)) {
            $matchingRap = dbGetRow("SELECT id FROM project_items_rap WHERE project_id = ? AND rab_item_id = ?", [$projectId, $itemId]);
            $matchingRapId = $matchingRap ? $matchingRap['id'] : null;
            if (isItemCodeDuplicate($projectId, $itemCode, $itemId, $matchingRapId)) {
                header('Content-Type: application/json');
                die(json_encode(['success' => false, 'message' => 'Kode item "' . $itemCode . '" sudah digunakan!']));
            }
        }
        
        dbExecute("UPDATE project_items SET item_code = ?, name = ?, brand = ?, category = ?, unit = ?, price = ?, actual_price = ? WHERE id = ? AND project_id = ?",
            [$itemCode, $name, $brand ?: null, $category, $unit, $price, $actualPrice, $itemId, $projectId]);
        
        // Sync item attributes (code, name, brand, category, unit) to RAP (price stays separate)
        syncEditItemRabToRap($itemId, $itemCode, $name, $brand, $category, $unit, $projectId);
        
        // Sync item price changes to AHSP and RAB subcategories
        syncItemToAhsp($itemId, $projectId);
        
        header('Content-Type: application/json');
        die(json_encode(['success' => true, 'message' => 'Item berhasil disimpan dan disinkronkan ke RAP!']));
    } catch (Exception $e) {
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

// RAP AJAX Handlers (Items & AHSP)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['update_item_rap_ajax', 'update_ahsp_detail_rap_ajax'])) {
    
    // Dependencies
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/../../includes/auth.php';
    
    // Auth Check
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!isset($_SESSION['user_id']) || !hasPermission('rap.edit')) {
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'Unauthorized']));
    }
    
    $projectId = $_GET['id'] ?? null;
    
    // Helper Functions specific to RAP (Scoped here to avoid pollution)
    if (!function_exists('recalculateRapAhspPrice')) {
        function recalculateRapAhspPrice($ahspId) {
            $total = dbGetRow("
                SELECT COALESCE(SUM(d.coefficient * COALESCE(d.unit_price, i.price)), 0) as total
                FROM project_ahsp_details_rap d
                JOIN project_items_rap i ON d.item_id = i.id
                WHERE d.ahsp_id = ?
            ", [$ahspId])['total'];
            
            dbExecute("UPDATE project_ahsp_rap SET unit_price = ? WHERE id = ?", [$total, $ahspId]);
        }
    }

    if (!function_exists('syncRapItemToAhsp')) {
        function syncRapItemToAhsp($itemId, $projectId) {
            // Clear override unit_price in details so it dynamically uses new master price
            dbExecute("UPDATE project_ahsp_details_rap SET unit_price = NULL WHERE item_id = ?", [$itemId]);
            
            // Find all affected AHSPs that use this item
            $affectedAhsp = dbGetAll("
                SELECT DISTINCT d.ahsp_id 
                FROM project_ahsp_details_rap d
                JOIN project_ahsp_rap pa ON d.ahsp_id = pa.id
                WHERE d.item_id = ? AND pa.project_id = ?
            ", [$itemId, $projectId]);
            
            foreach ($affectedAhsp as $row) {
                recalculateRapAhspPrice($row['ahsp_id']);
                if (function_exists('syncMasterAhspRapToRapTable')) {
                    syncMasterAhspRapToRapTable($row['ahsp_id'], $projectId);
                }
            }
        }
    }

    try {
        if ($_POST['action'] === 'update_item_rap_ajax') {
            $itemId = $_POST['item_id'];
            $itemCode = trim($_POST['item_code'] ?? '');
            $name = trim($_POST['item_name']);
            $brand = trim($_POST['item_brand'] ?? '');
            $category = $_POST['item_category'];
            $unit = trim($_POST['item_unit']);
            $priceRaw = $_POST['item_price'];
            $price = floatval(str_replace(',', '.', str_replace('.', '', $priceRaw)));
            $actualPriceRaw = $_POST['item_actual_price'] ?? '';
            $actualPrice = !empty($actualPriceRaw) ? floatval(str_replace(',', '.', str_replace('.', '', $actualPriceRaw))) : null;
            
            // Duplicate Check across RAP and RAB
            if (!empty($itemCode)) {
                $rapItem = dbGetRow("SELECT rab_item_id FROM project_items_rap WHERE id = ?", [$itemId]);
                $matchingRabId = $rapItem ? $rapItem['rab_item_id'] : null;
                if (isItemCodeDuplicate($projectId, $itemCode, $matchingRabId, $itemId)) {
                    header('Content-Type: application/json');
                    die(json_encode(['success' => false, 'message' => 'Kode item "' . $itemCode . '" sudah digunakan!']));
                }
            }
            
            dbExecute("UPDATE project_items_rap SET item_code = ?, name = ?, brand = ?, category = ?, unit = ?, price = ?, actual_price = ? WHERE id = ? AND project_id = ?",
                [$itemCode, $name, $brand ?: null, $category, $unit, $price, $actualPrice, $itemId, $projectId]);
            
            // Sync item attributes (code, name, brand, category, unit) to RAB (price stays separate)
            syncEditItemRapToRab($itemId, $itemCode, $name, $brand, $category, $unit, $projectId);
            
            syncRapItemToAhsp($itemId, $projectId);
            
            header('Content-Type: application/json');
            die(json_encode(['success' => true, 'message' => 'Item RAP berhasil disimpan dan disinkronkan ke RAB!']));
        }
        
        if ($_POST['action'] === 'update_ahsp_detail_rap_ajax') {
            $detailId = $_POST['detail_id'];
            $ahspId = $_POST['ahsp_id'];
            $coeffRaw = $_POST['coefficient'];
            $coefficient = floatval(str_replace(',', '.', $coeffRaw));
            
            if (empty($coeffRaw) || $coefficient > 0) {
                if (empty($coeffRaw)) {
                    $current = dbGetRow("SELECT coefficient FROM project_ahsp_details_rap WHERE id = ?", [$detailId]);
                    $coefficient = $current['coefficient'] ?? 1;
                }
                
                dbExecute("UPDATE project_ahsp_details_rap SET coefficient = ? WHERE id = ?",
                    [$coefficient, $detailId]);
                recalculateRapAhspPrice($ahspId);
                
                // Sync to RAP Table (rap_ahsp_details)
                syncMasterAhspRapToRapTable($ahspId, $projectId);
                
                header('Content-Type: application/json');
                die(json_encode(['success' => true, 'message' => 'Komponen berhasil diperbarui!']));
            } else {
                header('Content-Type: application/json');
                die(json_encode(['success' => false, 'message' => 'Koefisien harus lebih dari 0!']));
            }
        }
    } catch (Exception $e) {
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

// AJAX Handler: Get AHSP RAP HTML (for refresh after item update)
if (isset($_GET['action']) && $_GET['action'] === 'get_ahsp_rap_html') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/../../includes/auth.php';
    
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!isset($_SESSION['user_id'])) { die('Unauthorized'); }
    
    $projectId = $_GET['id'] ?? null;
    $project = dbGetRow("SELECT * FROM projects WHERE id = ?", [$projectId]);
    
    if (!$project) { die('Project not found'); }
    
    // Prepare variables for partial
    $overheadPct = getProjectOverheadProfitPct($project, 'rap');
    $overheadPctRap = $overheadPct;
    $isEditable = ($project['status'] === 'draft'); // Only draft is editable
    
    // AHSP sorting (match default logic)
    $ahspSort = $_GET['ahsp_sort'] ?? 'name';
    $ahspSortOrder = 'ASC';
    switch ($ahspSort) {
        case 'code': $ahspOrderBy = 'ahsp_code'; break;
        case 'price_asc': $ahspOrderBy = 'unit_price'; $ahspSortOrder = 'ASC'; break;
        case 'price_desc': $ahspOrderBy = 'unit_price'; $ahspSortOrder = 'DESC'; break;
        case 'name': default: $ahspOrderBy = 'work_name'; break;
    }
    
    $ahspListRap = dbGetAll("SELECT * FROM project_ahsp_rap WHERE project_id = ? ORDER BY CASE WHEN unit_price = 0 OR unit_price IS NULL THEN 0 ELSE 1 END ASC, $ahspOrderBy $ahspSortOrder", [$projectId]);
    
    // Include the partial
    include __DIR__ . '/tabs/partials/ahsp_rap_list.php';
    exit;
}

// AJAX Handler for Weekly Progress Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_weekly_progress') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    
    header('Content-Type: application/json');
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        die(json_encode(['success' => false, 'message' => 'Unauthorized']));
    }
    
    $projectId = intval($_GET['id'] ?? 0);
    $subcategoryId = intval($_POST['subcategory_id'] ?? 0);
    $weekNumber = intval($_POST['week_number'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    
    try {
        // Check if project is started
        $project = dbGetRow("SELECT status FROM projects WHERE id = ?", [$projectId]);
        if (!$project || $project['status'] === 'draft') {
            die(json_encode(['success' => false, 'message' => 'Proyek belum dimulai']));
        }
        
        // Upsert weekly progress
        $existing = dbGetRow(
            "SELECT id FROM weekly_progress WHERE project_id = ? AND subcategory_id = ? AND week_number = ?",
            [$projectId, $subcategoryId, $weekNumber]
        );
        
        if ($existing) {
            dbExecute(
                "UPDATE weekly_progress SET realization_amount = ?, updated_at = NOW() WHERE id = ?",
                [$amount, $existing['id']]
            );
        } else {
            dbInsert(
                "INSERT INTO weekly_progress (project_id, subcategory_id, week_number, realization_amount, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())",
                [$projectId, $subcategoryId, $weekNumber, $amount]
            );
        }
        
        die(json_encode(['success' => true, 'message' => 'Tersimpan']));
    } catch (Exception $e) {
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

// AJAX Handler for RAB/RAP/Snapshot Volume Updates & Category Reordering / Head-Sub Drag-and-Drop
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], [
    'ajax_update_rab_volume', 
    'ajax_update_rap_volume', 
    'ajax_update_snapshot_volume',
    'ajax_move_category_head_sub',
    'ajax_reorder_rab_categories',
    'ajax_move_category_order',
    'ajax_move_category_relative'
])) {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/../../includes/auth.php';
    
    header('Content-Type: application/json');
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!hasPermission('rab.edit')) {
        die(json_encode(['success' => false, 'message' => 'Unauthorized']));
    }
    
    $action = $_POST['action'];
    $projectId = intval($_GET['id'] ?? $_POST['project_id'] ?? 0);
    
    try {
        if ($action === 'ajax_move_category_head_sub') {
            $catId = intval($_POST['category_id'] ?? 0);
            $headSubId = isset($_POST['head_sub_id']) && $_POST['head_sub_id'] !== '' && $_POST['head_sub_id'] !== '0' ? intval($_POST['head_sub_id']) : null;
            if (!$catId) {
                die(json_encode(['success' => false, 'message' => 'Kategori tidak valid!']));
            }
            $cat = dbGetRow("SELECT project_id FROM rab_categories WHERE id = ?", [$catId]);
            if (!$cat) {
                die(json_encode(['success' => false, 'message' => 'Kategori tidak ditemukan!']));
            }
            $pId = $projectId ?: $cat['project_id'];
            dbExecute("UPDATE rab_categories SET head_sub_id = ? WHERE id = ?", [$headSubId, $catId]);
            resequenceRabCategoriesAndSubcategories($pId);
            $newOrder = dbGetAll("SELECT id, code, head_sub_id, sort_order FROM rab_categories WHERE project_id = ? ORDER BY sort_order ASC, id ASC", [$pId]);
            die(json_encode(['success' => true, 'message' => 'Kategori berhasil dipindahkan!', 'new_order' => $newOrder]));
        }

        if ($action === 'ajax_reorder_rab_categories') {
            $categoriesRaw = $_POST['categories'] ?? [];
            if (is_string($categoriesRaw)) {
                $categoriesRaw = json_decode($categoriesRaw, true) ?: [];
            }
            
            if (empty($categoriesRaw)) {
                die(json_encode(['success' => false, 'message' => 'Data urutan kategori kosong!']));
            }
            
            $sortIdx = 1;
            $pId = $projectId;
            foreach ($categoriesRaw as $item) {
                $cId = is_array($item) ? intval($item['id'] ?? 0) : intval($item);
                if (!$cId) continue;
                
                if (!$pId) {
                    $catInfo = dbGetRow("SELECT project_id FROM rab_categories WHERE id = ?", [$cId]);
                    if ($catInfo) $pId = $catInfo['project_id'];
                }
                
                if (is_array($item) && array_key_exists('head_sub_id', $item)) {
                    $hsId = (!empty($item['head_sub_id']) && $item['head_sub_id'] !== '0') ? intval($item['head_sub_id']) : null;
                    dbExecute("UPDATE rab_categories SET sort_order = ?, head_sub_id = ? WHERE id = ?", [$sortIdx, $hsId, $cId]);
                } else {
                    dbExecute("UPDATE rab_categories SET sort_order = ? WHERE id = ?", [$sortIdx, $cId]);
                }
                $sortIdx++;
            }
            
            if ($pId) {
                resequenceRabCategoriesAndSubcategories($pId);
            }
            $newOrder = dbGetAll("SELECT id, code, head_sub_id, sort_order FROM rab_categories WHERE project_id = ? ORDER BY sort_order ASC, id ASC", [$pId]);
            die(json_encode(['success' => true, 'message' => 'Urutan kategori berhasil disimpan!', 'new_order' => $newOrder]));
        }

        if ($action === 'ajax_move_category_order') {
            $catId = intval($_POST['category_id'] ?? 0);
            $direction = $_POST['direction'] ?? 'up';
            
            if (!$catId) {
                die(json_encode(['success' => false, 'message' => 'Kategori tidak valid!']));
            }
            
            $cat = dbGetRow("SELECT id, project_id, head_sub_id, sort_order FROM rab_categories WHERE id = ?", [$catId]);
            if (!$cat) {
                die(json_encode(['success' => false, 'message' => 'Kategori tidak ditemukan!']));
            }
            $pId = $projectId ?: $cat['project_id'];
            
            $allCats = dbGetAll("SELECT id, sort_order, head_sub_id FROM rab_categories WHERE project_id = ? ORDER BY sort_order ASC, id ASC", [$pId]);
            $currentIndex = -1;
            foreach ($allCats as $idx => $c) {
                if ($c['id'] == $catId) {
                    $currentIndex = $idx;
                    break;
                }
            }
            
            if ($currentIndex === -1) {
                die(json_encode(['success' => false, 'message' => 'Kategori tidak ditemukan dalam proyek!']));
            }
            
            $targetIndex = ($direction === 'up') ? $currentIndex - 1 : $currentIndex + 1;
            if ($targetIndex >= 0 && $targetIndex < count($allCats)) {
                // Swap positions in array
                $temp = $allCats[$currentIndex];
                $allCats[$currentIndex] = $allCats[$targetIndex];
                $allCats[$targetIndex] = $temp;
                
                // If moving between different head-subs, inherit the target position's head_sub_id
                $targetHsId = $allCats[$targetIndex]['head_sub_id'];
                
                // Update all sort orders
                $s = 1;
                foreach ($allCats as $c) {
                    dbExecute("UPDATE rab_categories SET sort_order = ? WHERE id = ?", [$s, $c['id']]);
                    $s++;
                }
                
                resequenceRabCategoriesAndSubcategories($pId);
                $newOrder = dbGetAll("SELECT id, code, head_sub_id, sort_order FROM rab_categories WHERE project_id = ? ORDER BY sort_order ASC, id ASC", [$pId]);
                die(json_encode(['success' => true, 'message' => 'Urutan kategori berhasil diubah!', 'new_order' => $newOrder]));
            } else {
                die(json_encode(['success' => true, 'message' => 'Kategori sudah berada di posisi paling ' . ($direction === 'up' ? 'atas' : 'bawah') . '.', 'no_change' => true]));
            }
        }

        if ($action === 'ajax_move_category_relative') {
            $sourceCatId = intval($_POST['source_cat_id'] ?? 0);
            $targetCatId = intval($_POST['target_cat_id'] ?? 0);
            $position = $_POST['position'] ?? 'after'; // 'before' or 'after'
            $targetHeadSubId = isset($_POST['target_head_sub_id']) && $_POST['target_head_sub_id'] !== '' && $_POST['target_head_sub_id'] !== '0' ? intval($_POST['target_head_sub_id']) : null;
            
            if (!$sourceCatId) {
                die(json_encode(['success' => false, 'message' => 'Kategori sumber tidak valid!']));
            }
            
            $sourceCat = dbGetRow("SELECT id, project_id, head_sub_id FROM rab_categories WHERE id = ?", [$sourceCatId]);
            if (!$sourceCat) {
                die(json_encode(['success' => false, 'message' => 'Kategori sumber tidak ditemukan!']));
            }
            $pId = $projectId ?: $sourceCat['project_id'];
            
            $allCats = dbGetAll("SELECT id, head_sub_id FROM rab_categories WHERE project_id = ? ORDER BY sort_order ASC, id ASC", [$pId]);
            
            // Remove source from list
            $filteredCats = [];
            foreach ($allCats as $c) {
                if ($c['id'] != $sourceCatId) {
                    $filteredCats[] = $c;
                }
            }
            
            $newOrderedList = [];
            $inserted = false;
            
            if ($targetCatId && $targetCatId != $sourceCatId) {
                $targetCat = dbGetRow("SELECT id, head_sub_id FROM rab_categories WHERE id = ?", [$targetCatId]);
                $finalHeadSubId = ($targetHeadSubId !== null) ? $targetHeadSubId : ($targetCat ? $targetCat['head_sub_id'] : null);
                
                foreach ($filteredCats as $c) {
                    if ($c['id'] == $targetCatId) {
                        if ($position === 'before') {
                            $newOrderedList[] = ['id' => $sourceCatId, 'head_sub_id' => $finalHeadSubId];
                            $newOrderedList[] = $c;
                        } else {
                            $newOrderedList[] = $c;
                            $newOrderedList[] = ['id' => $sourceCatId, 'head_sub_id' => $finalHeadSubId];
                        }
                        $inserted = true;
                    } else {
                        $newOrderedList[] = $c;
                    }
                }
            }
            
            if (!$inserted) {
                // If dropped onto head-sub without target category or fallback, append to head_sub group
                $finalHeadSubId = $targetHeadSubId;
                $newOrderedList = [];
                $placed = false;
                
                if ($finalHeadSubId !== null) {
                    // Place at end of that head_sub
                    foreach ($filteredCats as $c) {
                        $newOrderedList[] = $c;
                        if ($c['head_sub_id'] == $finalHeadSubId) {
                            // will place after last item of this head_sub
                        }
                    }
                    // Insert at appropriate place
                    $lastHsIdx = -1;
                    foreach ($filteredCats as $idx => $c) {
                        if ($c['head_sub_id'] == $finalHeadSubId) {
                            $lastHsIdx = $idx;
                        }
                    }
                    if ($lastHsIdx !== -1) {
                        array_splice($filteredCats, $lastHsIdx + 1, 0, [['id' => $sourceCatId, 'head_sub_id' => $finalHeadSubId]]);
                        $newOrderedList = $filteredCats;
                    } else {
                        $filteredCats[] = ['id' => $sourceCatId, 'head_sub_id' => $finalHeadSubId];
                        $newOrderedList = $filteredCats;
                    }
                } else {
                    $filteredCats[] = ['id' => $sourceCatId, 'head_sub_id' => null];
                    $newOrderedList = $filteredCats;
                }
            }
            
            // Update all sort orders and head_sub_ids
            $sortIdx = 1;
            foreach ($newOrderedList as $item) {
                $cId = $item['id'];
                $hsId = array_key_exists('head_sub_id', $item) ? $item['head_sub_id'] : null;
                dbExecute("UPDATE rab_categories SET sort_order = ?, head_sub_id = ? WHERE id = ?", [$sortIdx, $hsId, $cId]);
                $sortIdx++;
            }
            
            resequenceRabCategoriesAndSubcategories($pId);
            $newOrder = dbGetAll("SELECT id, code, head_sub_id, sort_order FROM rab_categories WHERE project_id = ? ORDER BY sort_order ASC, id ASC", [$pId]);
            die(json_encode(['success' => true, 'message' => 'Kategori berhasil dipindahkan!', 'new_order' => $newOrder]));
        }
        
        $id = intval($_POST['id'] ?? 0);
        $value = floatval($_POST['value'] ?? 0);
        
        if ($action === 'ajax_update_rab_volume') {
            // Update RAB subcategory volume
            dbExecute("UPDATE rab_subcategories SET volume = ? WHERE id = ?", [$value, $id]);
            
            // Get unit price for response and RAP sync
            $subcat = dbGetRow("SELECT unit_price, ahsp_id FROM rab_subcategories WHERE id = ?", [$id]);
            $unitPrice = $subcat['unit_price'] ?? 0;
            
            // Auto-sync to RAP
            $rapItem = dbGetRow("SELECT id FROM rap_items WHERE subcategory_id = ?", [$id]);
            if ($rapItem) {
                dbExecute("UPDATE rap_items SET volume = ?, unit_price = ? WHERE subcategory_id = ?", [$value, $unitPrice, $id]);
            } else {
                dbInsert("INSERT INTO rap_items (subcategory_id, volume, unit_price) VALUES (?, ?, ?)", [$id, $value, $unitPrice]);
            }
            
            die(json_encode(['success' => true, 'message' => 'Volume tersimpan']));
            
        } elseif ($action === 'ajax_update_rap_volume') {
            // Update RAP item volume
            dbExecute("UPDATE rap_items SET volume = ? WHERE id = ?", [$value, $id]);
            die(json_encode(['success' => true, 'message' => 'Volume tersimpan']));
        } elseif ($action === 'ajax_update_snapshot_volume') {
            // Update Snapshot subcategory volume
            dbExecute("UPDATE rab_snapshot_subcategories SET volume = ? WHERE id = ?", [$value, $id]);
            die(json_encode(['success' => true, 'message' => 'Volume tersimpan']));
        }
    } catch (Exception $e) {
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}


// AJAX Handler: Toggle Request Lock
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_request_lock') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/../../includes/auth.php';
    
    header('Content-Type: application/json');
    
    requireLogin();
    if (!hasPermission('projects.lock_request')) {
        die(json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses untuk fitur ini']));
    }
    
    $pId = intval($_GET['id'] ?? $_POST['project_id'] ?? 0);
    if (!$pId) {
        die(json_encode(['success' => false, 'message' => 'Project ID tidak valid']));
    }
    
    $proj = dbGetRow("SELECT id, request_locked FROM projects WHERE id = ?", [$pId]);
    if (!$proj) {
        die(json_encode(['success' => false, 'message' => 'Proyek tidak ditemukan']));
    }
    
    $newStatus = $proj['request_locked'] ? 0 : 1;
    dbExecute("UPDATE projects SET request_locked = ? WHERE id = ?", [$newStatus, $pId]);
    
    $label = $newStatus ? 'dikunci' : 'dibuka';
    die(json_encode(['success' => true, 'message' => 'Pengajuan berhasil ' . $label, 'locked' => $newStatus]));
}

// AJAX Handler: Get AHSP RAB Detail (for modal in RAB tab)
// Uses project_ahsp + project_ahsp_details (Master Data RAB)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_ahsp_detail') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    
    header('Content-Type: application/json');
    
    $ahspId = intval($_GET['ahsp_id'] ?? 0);
    $pId = intval($_GET['id'] ?? 0);
    
    if (!$ahspId || !$pId) {
        echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap']);
        exit;
    }
    
    // Get AHSP info
    $ahsp = dbGetRow("SELECT * FROM project_ahsp WHERE id = ? AND project_id = ?", [$ahspId, $pId]);
    if (!$ahsp) {
        echo json_encode(['success' => false, 'message' => 'AHSP tidak ditemukan']);
        exit;
    }
    
    // Get project overhead
    $project = dbGetRow("SELECT overhead_percentage, profit_percentage, overhead_apply_ahsp, overhead_apply_rab, overhead_apply_rap FROM projects WHERE id = ?", [$pId]);
    $overheadPct = getProjectOverheadProfitPct($project, 'ahsp');
    $overheadLabel = formatOverheadProfitLabel($project, 'ahsp');
    
    // Get AHSP details
    $details = dbGetAll("
        SELECT d.*, i.item_code, i.name as item_name, i.category, i.unit, 
               i.price as item_up_price, i.actual_price as item_actual_price,
               COALESCE(d.unit_price, i.price) as effective_price,
               (d.coefficient * COALESCE(d.unit_price, i.price)) as total_price
        FROM project_ahsp_details d
        JOIN project_items i ON d.item_id = i.id
        WHERE d.ahsp_id = ?
        ORDER BY i.category, i.name
    ", [$ahspId]);
    
    // Group by category
    $detailsByCategory = ['upah' => [], 'material' => [], 'alat' => []];
    $totals = ['upah' => 0, 'material' => 0, 'alat' => 0];
    foreach ($details as $detail) {
        $detailsByCategory[$detail['category']][] = $detail;
        $totals[$detail['category']] += $detail['total_price'];
    }
    $grandTotal = array_sum($totals);
    $overheadAmount = $grandTotal * ($overheadPct / 100);
    $totalWithOverhead = $grandTotal + $overheadAmount;
    
    // Build HTML
    ob_start();
    ?>
    <div class="mb-3">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h6 class="mb-1"><code class="me-2"><?= sanitize($ahsp['ahsp_code'] ?? '') ?></code> <strong><?= sanitize($ahsp['work_name']) ?></strong></h6>
                <span class="text-muted">Satuan: <?= sanitize($ahsp['unit']) ?></span>
            </div>
            <div class="text-end">
                <span class="badge bg-primary fs-6"><?= formatRupiah($totalWithOverhead) ?></span>
                <br><small class="text-muted">Termasuk <?= $overheadLabel ?></small>
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0">
            <thead>
                <tr class="table-primary">
                    <th width="100">Kode Item</th>
                    <th>Uraian</th>
                    <th width="80">Satuan</th>
                    <th width="100" class="text-end">Koefisien</th>
                    <th width="120" class="text-end">Harga Satuan</th>
                    <th width="130" class="text-end">Jumlah Harga</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $categories = [
                    'upah' => ['label' => 'A. TENAGA', 'bg' => '#e8f4fd', 'total_bg' => '#d4edfc', 'total_label' => 'JUMLAH TENAGA'],
                    'material' => ['label' => 'B. BAHAN', 'bg' => '#e8fde8', 'total_bg' => '#c8f7c8', 'total_label' => 'JUMLAH BAHAN'],
                    'alat' => ['label' => 'C. PERALATAN', 'bg' => '#fdf8e8', 'total_bg' => '#f5edc8', 'total_label' => 'JUMLAH ALAT']
                ];
                foreach ($categories as $catKey => $catInfo): ?>
                <tr style="background-color: <?= $catInfo['bg'] ?>;">
                    <td colspan="6"><strong><?= $catInfo['label'] ?></strong></td>
                </tr>
                <?php if (empty($detailsByCategory[$catKey])): ?>
                <tr><td colspan="6" class="text-center text-muted">Belum ada komponen</td></tr>
                <?php else: ?>
                <?php foreach ($detailsByCategory[$catKey] as $detail): ?>
                <tr>
                    <td class="text-center font-monospace small"><?= sanitize($detail['item_code'] ?? '-') ?></td>
                    <td><?= sanitize($detail['item_name']) ?></td>
                    <td><?= sanitize($detail['unit']) ?></td>
                    <td class="text-end"><?= formatNumber($detail['coefficient'], 4) ?></td>
                    <td class="text-end"><?= formatRupiah($detail['effective_price']) ?></td>
                    <td class="text-end"><?= formatRupiah($detail['total_price']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                <tr style="background-color: <?= $catInfo['total_bg'] ?>;">
                    <td colspan="5" class="text-end"><strong><?= $catInfo['total_label'] ?></strong></td>
                    <td class="text-end"><strong><?= formatRupiah($totals[$catKey]) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="table-secondary">
                    <td colspan="5" class="text-end"><strong>D. Jumlah (A+B+C)</strong></td>
                    <td class="text-end"><strong><?= formatRupiah($grandTotal) ?></strong></td>
                </tr>
                <tr>
                    <td colspan="5" class="text-end"><strong>E. <?= $overheadLabel ?></strong></td>
                    <td class="text-end"><?= formatRupiah($overheadAmount) ?></td>
                </tr>
                <tr class="table-dark">
                    <td colspan="5" class="text-end"><strong>HARGA SATUAN PEKERJAAN (D+E)</strong></td>
                    <td class="text-end"><strong><?= formatRupiah($totalWithOverhead) ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php
    $html = ob_get_clean();
    echo json_encode(['success' => true, 'html' => $html, 'work_name' => $ahsp['work_name']]);
    exit;
}

// AJAX Handler: Get AHSP RAP Detail (for modal in RAP tab)
// Uses project_ahsp_rap + project_ahsp_details_rap (Master Data RAP)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_ahsp_rap_detail') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    
    header('Content-Type: application/json');
    
    $ahspCode = $_GET['ahsp_code'] ?? '';
    $pId = intval($_GET['id'] ?? 0);
    
    if (!$ahspCode || !$pId) {
        echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap']);
        exit;
    }
    
    // Get project overhead
    $project = dbGetRow("SELECT overhead_percentage, profit_percentage, overhead_apply_ahsp, overhead_apply_rab, overhead_apply_rap FROM projects WHERE id = ?", [$pId]);
    $overheadPct = getProjectOverheadProfitPct($project, 'rap');
    $overheadLabel = formatOverheadProfitLabel($project, 'rap');
    
    // Find AHSP RAP by code
    $ahspRap = dbGetRow("SELECT * FROM project_ahsp_rap WHERE project_id = ? AND ahsp_code = ?", [$pId, $ahspCode]);
    if (!$ahspRap) {
        echo json_encode(['success' => false, 'message' => 'AHSP RAP tidak ditemukan untuk kode: ' . $ahspCode]);
        exit;
    }
    
    // Get AHSP details (composition)
    $details = dbGetAll("
        SELECT d.*, i.item_code, i.name as item_name, i.category, i.unit, i.price as item_price,
               COALESCE(d.unit_price, i.price) as effective_price,
               (d.coefficient * COALESCE(d.unit_price, i.price)) as total_price
        FROM project_ahsp_details_rap d
        JOIN project_items_rap i ON d.item_id = i.id
        WHERE d.ahsp_id = ?
        ORDER BY i.category, i.name
    ", [$ahspRap['id']]);
    
    // Group by category and calculate totals
    $detailsByCategory = ['upah' => [], 'material' => [], 'alat' => []];
    $totals = ['upah' => 0, 'material' => 0, 'alat' => 0];
    foreach ($details as $detail) {
        $detailsByCategory[$detail['category']][] = $detail;
        $totals[$detail['category']] += $detail['total_price'];
    }
    $grandTotal = array_sum($totals);
    $overheadAmount = $grandTotal * ($overheadPct / 100);
    $totalWithOverhead = $grandTotal + $overheadAmount;
    
    // Build HTML (same format as RAB AHSP modal)
    ob_start();
    ?>
    <div class="mb-3">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h6 class="mb-1"><code class="me-2"><?= sanitize($ahspRap['ahsp_code']) ?></code> <strong><?= sanitize($ahspRap['work_name']) ?></strong></h6>
                <span class="text-muted">Satuan: <?= sanitize($ahspRap['unit']) ?></span>
            </div>
            <div class="text-end">
                <span class="badge bg-info fs-6"><?= formatRupiah($totalWithOverhead) ?></span>
                <br><small class="text-muted">Termasuk <?= $overheadLabel ?></small>
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0">
            <thead>
                <tr class="table-info">
                    <th width="100">Kode Item</th>
                    <th>Uraian</th>
                    <th width="80">Satuan</th>
                    <th width="100" class="text-end">Koefisien</th>
                    <th width="120" class="text-end">Harga Satuan</th>
                    <th width="130" class="text-end">Jumlah Harga</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $categories = [
                    'upah' => ['label' => 'A. TENAGA', 'bg' => '#e8f4fd', 'total_bg' => '#d4edfc', 'total_label' => 'JUMLAH TENAGA'],
                    'material' => ['label' => 'B. BAHAN', 'bg' => '#e8fde8', 'total_bg' => '#c8f7c8', 'total_label' => 'JUMLAH BAHAN'],
                    'alat' => ['label' => 'C. PERALATAN', 'bg' => '#fdf8e8', 'total_bg' => '#f5edc8', 'total_label' => 'JUMLAH ALAT']
                ];
                foreach ($categories as $catKey => $catInfo): ?>
                <tr style="background-color: <?= $catInfo['bg'] ?>;">
                    <td colspan="6"><strong><?= $catInfo['label'] ?></strong></td>
                </tr>
                <?php if (empty($detailsByCategory[$catKey])): ?>
                <tr><td colspan="6" class="text-center text-muted">Belum ada komponen</td></tr>
                <?php else: ?>
                <?php foreach ($detailsByCategory[$catKey] as $detail): ?>
                <tr>
                    <td class="text-center font-monospace small"><?= sanitize($detail['item_code'] ?? '-') ?></td>
                    <td><?= sanitize($detail['item_name']) ?></td>
                    <td><?= sanitize($detail['unit']) ?></td>
                    <td class="text-end"><?= formatNumber($detail['coefficient'], 4) ?></td>
                    <td class="text-end"><?= formatRupiah($detail['effective_price']) ?></td>
                    <td class="text-end"><?= formatRupiah($detail['total_price']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                <tr style="background-color: <?= $catInfo['total_bg'] ?>;">
                    <td colspan="5" class="text-end"><strong><?= $catInfo['total_label'] ?></strong></td>
                    <td class="text-end"><strong><?= formatRupiah($totals[$catKey]) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="table-secondary">
                    <td colspan="5" class="text-end"><strong>D. Jumlah (A+B+C)</strong></td>
                    <td class="text-end"><strong><?= formatRupiah($grandTotal) ?></strong></td>
                </tr>
                <tr>
                    <td colspan="5" class="text-end"><strong>E. <?= $overheadLabel ?></strong></td>
                    <td class="text-end"><?= formatRupiah($overheadAmount) ?></td>
                </tr>
                <tr class="table-dark">
                    <td colspan="5" class="text-end"><strong>HARGA SATUAN PEKERJAAN (D+E)</strong></td>
                    <td class="text-end"><strong><?= formatRupiah($totalWithOverhead) ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php
    $html = ob_get_clean();
    echo json_encode(['success' => true, 'html' => $html, 'work_name' => $ahspRap['work_name']]);
    exit;
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();

// Check if POST payload exceeded PHP post_max_size (which empties $_POST & $_FILES)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
    $projectId = intval($_GET['id'] ?? 0);
    $tab = $_GET['tab'] ?? 'documentation';
    $maxPost = ini_get('post_max_size');
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || !empty($_POST['is_ajax']);
    $errorMsg = "Ukuran file yang diunggah melebihi batas konfigurasi server PHP (post_max_size = $maxPost). Silakan pilih file yang lebih kecil atau unggah secara bertahap.";
    
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $errorMsg]);
        exit;
    }
    
    setFlash('error', $errorMsg);
    header('Location: view.php?id=' . $projectId . '&tab=' . $tab);
    exit;
}

// Handle start project action (must be before HTML output)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'start_project') {
    $projectId = $_GET['id'] ?? null;
    
    if ($projectId && hasPermission('projects.edit')) {
        try {
            // Update project status to on_progress and set start date if not already set
            $currentProject = dbGetRow("SELECT start_date FROM projects WHERE id = ?", [$projectId]);
            $startDate = $currentProject['start_date'] ?: date('Y-m-d');
            
            dbExecute("UPDATE projects SET status = 'on_progress', start_date = ? WHERE id = ? AND status = 'draft'", [$startDate, $projectId]);
            setFlash('success', 'Proyek berhasil dimulai! Data RAB, RAP, Master Data, dan AHSP sekarang terkunci.');
        } catch (Exception $e) {
            setFlash('error', 'Gagal memulai proyek: ' . $e->getMessage());
        }
    }
    
    header('Location: view.php?id=' . $projectId . '&tab=detail');
    exit;
}

// Handle revert to draft action (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'revert_to_draft') {
    $projectId = $_GET['id'] ?? null;
    
    if ($projectId && hasPermission('projects.edit')) {
        try {
            dbExecute("UPDATE projects SET status = 'draft' WHERE id = ? AND status = 'on_progress'", [$projectId]);
            setFlash('success', 'Proyek berhasil dikembalikan ke Draft. Data RAB, RAP, Master Data, dan AHSP dapat diedit kembali.');
        } catch (Exception $e) {
            setFlash('error', 'Gagal mengembalikan proyek: ' . $e->getMessage());
        }
    }
    
    header('Location: view.php?id=' . $projectId . '&tab=detail');
    exit;
}

// Handle duplicate project
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'duplicate_project') {
    $sourceProjectId = intval($_GET['id'] ?? $_POST['project_id'] ?? 0);
    
    if (!hasPermission('projects.create')) {
        setFlash('error', 'Anda tidak memiliki hak akses untuk membuat atau menduplikasi proyek!');
        header('Location: view.php?id=' . $sourceProjectId . '&tab=detail');
        exit;
    }
    
    if (!canAccessProject($sourceProjectId)) {
        setFlash('error', 'Anda tidak memiliki akses ke proyek ini!');
        header('Location: index.php');
        exit;
    }
    
    $newName = trim($_POST['new_project_name'] ?? '');
    $newCode = trim($_POST['new_project_code'] ?? '');
    
    if (empty($newName)) {
        setFlash('error', 'Nama proyek baru tidak boleh kosong!');
        header('Location: view.php?id=' . $sourceProjectId . '&tab=detail');
        exit;
    }
    
    // Check if new code is available (if provided)
    if (!empty($newCode) && !isProjectCodeAvailable($newCode)) {
        setFlash('error', "Kode proyek \"$newCode\" sudah digunakan! Silakan gunakan kode lain.");
        header('Location: view.php?id=' . $sourceProjectId . '&tab=detail');
        exit;
    }
    
    try {
        $userId = getCurrentUserId();
        $newProjectId = duplicateProject($sourceProjectId, $newName, $newCode, $userId);
        
        setFlash('success', 'Proyek berhasil diduplikasi menjadi "' . sanitize($newName) . '"!');
        header('Location: view.php?id=' . $newProjectId . '&tab=detail');
        exit;
    } catch (Throwable $e) {
        setFlash('error', 'Duplikasi proyek gagal: ' . $e->getMessage());
        header('Location: view.php?id=' . $sourceProjectId . '&tab=detail');
        exit;
    }
}

// Handle update non-rab budgets (Admin only)


// Handle upload project images
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_project_images') {
    $projectId = $_GET['id'] ?? null;
    if ($projectId && hasPermission('projects.edit')) {
        if (!empty($_FILES['images']['name'][0])) {
            $uploadDir = __DIR__ . '/../../uploads/project_images/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
            $maxSize = 5 * 1024 * 1024; // 5MB
            $uploadedCount = 0;
            $errors = [];
            
            foreach ($_FILES['images']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['images']['error'][$key] !== UPLOAD_ERR_OK) {
                    continue;
                }
                
                $fileType = $_FILES['images']['type'][$key];
                $fileSize = $_FILES['images']['size'][$key];
                $originalName = $_FILES['images']['name'][$key];
                
                // Validate mime type & extension
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
                
                if (!in_array($fileType, $allowedTypes) || !in_array($ext, $allowedExtensions)) {
                    $errors[] = "$originalName: Format file tidak didukung (hanya JPG, JPEG, PNG, WEBP).";
                    continue;
                }
                if ($fileSize > $maxSize) {
                    $errors[] = "$originalName: Ukuran file melebihi 5MB.";
                    continue;
                }
                
                // Generate unique filename
                $filename = 'proj_' . $projectId . '_' . time() . '_' . $key . '_' . rand(1000, 9999) . '.' . $ext;
                $filepath = $uploadDir . $filename;
                
                if (move_uploaded_file($tmpName, $filepath)) {
                    $description = isset($_POST['descriptions'][$key]) ? trim($_POST['descriptions'][$key]) : '';
                    dbInsert("
                        INSERT INTO project_images (project_id, filename, original_name, description, uploaded_by)
                        VALUES (?, ?, ?, ?, ?)
                    ", [$projectId, $filename, $originalName, $description ?: null, $_SESSION['user_id']]);
                    $uploadedCount++;
                }
            }
            
            if ($uploadedCount > 0) {
                setFlash('success', "$uploadedCount foto lokasi proyek berhasil diunggah.");
            }
            if (!empty($errors)) {
                setFlash('error', implode('<br>', $errors));
            }
        } else {
            setFlash('error', 'Silakan pilih foto terlebih dahulu.');
        }
    }
    header('Location: view.php?id=' . $projectId . '&tab=detail');
    exit;
}

// Handle edit project image description
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_project_image_description') {
    $projectId = $_GET['id'] ?? null;
    $imageId = intval($_POST['image_id'] ?? 0);
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    
    if ($projectId && $imageId && hasPermission('projects.edit')) {
        dbExecute("UPDATE project_images SET description = ? WHERE id = ? AND project_id = ?", [$description ?: null, $imageId, $projectId]);
        setFlash('success', 'Keterangan foto berhasil diperbarui.');
    }
    header('Location: view.php?id=' . $projectId . '&tab=detail');
    exit;
}

// Handle delete project image
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_project_image') {
    $projectId = $_GET['id'] ?? null;
    $imageId = intval($_POST['image_id'] ?? 0);
    
    if ($projectId && $imageId && hasPermission('projects.edit')) {
        $img = dbGetRow("SELECT filename FROM project_images WHERE id = ? AND project_id = ?", [$imageId, $projectId]);
        if ($img) {
            $filepath = __DIR__ . '/../../uploads/project_images/' . $img['filename'];
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            dbExecute("DELETE FROM project_images WHERE id = ? AND project_id = ?", [$imageId, $projectId]);
            setFlash('success', 'Foto berhasil dihapus.');
        } else {
            setFlash('error', 'Foto tidak ditemukan.');
        }
    }
    header('Location: view.php?id=' . $projectId . '&tab=detail');
    exit;
}

// Handle Delete Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_request') {
    $projectId = intval($_GET['id'] ?? $_POST['project_id'] ?? 0);
    
    if (!hasPermission('requests.delete')) {
        setFlash('error', 'Anda tidak memiliki hak akses untuk menghapus pengajuan!');
        header('Location: view.php?id=' . $projectId . '&tab=requests');
        exit;
    }
    
    $reqId = intval($_POST['request_id'] ?? 0);
    $req = dbGetRow("SELECT id, request_number, project_id FROM requests WHERE id = ? AND project_id = ?", [$reqId, $projectId]);
    if ($req) {
        deleteRequest($reqId);
        setFlash('success', 'Pengajuan ' . ($req['request_number'] ?: 'REQ-' . $req['id']) . ' berhasil dihapus.');
    } else {
        setFlash('error', 'Pengajuan tidak ditemukan.');
    }
    header('Location: view.php?id=' . $projectId . '&tab=requests');
    exit;
}

// ========================================================
// DOCUMENTATION & FILE MANAGER HANDLERS
// ========================================================

// Handle create folder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_document_folder') {
    $projectId = intval($_GET['id'] ?? $_POST['project_id'] ?? 0);
    $parentId = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;
    $folderName = trim($_POST['name'] ?? '');
    
    if ($projectId && hasPermission('documentation.upload')) {
        if (!empty($folderName)) {
            // Check duplicate folder name in same parent
            $existing = dbGetRow("
                SELECT id FROM project_document_folders 
                WHERE project_id = ? AND name = ? AND (parent_id = ? OR (parent_id IS NULL AND ? IS NULL))
            ", [$projectId, $folderName, $parentId, $parentId]);
            
            if ($existing) {
                setFlash('error', "Folder dengan nama \"$folderName\" sudah ada di folder ini.");
            } else {
                dbInsert("
                    INSERT INTO project_document_folders (project_id, parent_id, name, created_by)
                    VALUES (?, ?, ?, ?)
                ", [$projectId, $parentId, $folderName, $_SESSION['user_id']]);
                setFlash('success', "Folder \"$folderName\" berhasil dibuat.");
            }
        } else {
            setFlash('error', 'Nama folder tidak boleh kosong.');
        }
    } else {
        setFlash('error', 'Anda tidak memiliki hak akses untuk membuat folder.');
    }
    
    $redirectUrl = 'view.php?id=' . $projectId . '&tab=documentation' . ($parentId ? '&folder_id=' . $parentId : '');
    header('Location: ' . $redirectUrl);
    exit;
}

// Handle rename folder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rename_document_folder') {
    $projectId = intval($_GET['id'] ?? $_POST['project_id'] ?? 0);
    $folderId = intval($_POST['folder_id'] ?? 0);
    $newName = trim($_POST['name'] ?? '');
    $parentId = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;
    
    if ($projectId && $folderId && hasPermission('documentation.upload')) {
        if (!empty($newName)) {
            $folder = dbGetRow("SELECT id, parent_id FROM project_document_folders WHERE id = ? AND project_id = ?", [$folderId, $projectId]);
            if ($folder) {
                dbExecute("UPDATE project_document_folders SET name = ? WHERE id = ? AND project_id = ?", [$newName, $folderId, $projectId]);
                setFlash('success', 'Nama folder berhasil diubah.');
                $parentId = $folder['parent_id'];
            } else {
                setFlash('error', 'Folder tidak ditemukan.');
            }
        } else {
            setFlash('error', 'Nama folder tidak boleh kosong.');
        }
    } else {
        setFlash('error', 'Anda tidak memiliki hak akses untuk mengubah nama folder.');
    }
    
    $redirectUrl = 'view.php?id=' . $projectId . '&tab=documentation' . ($parentId ? '&folder_id=' . $parentId : '');
    header('Location: ' . $redirectUrl);
    exit;
}

// Handle delete folder (recursively delete physical files and subfolders)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_document_folder') {
    $projectId = intval($_GET['id'] ?? $_POST['project_id'] ?? 0);
    $folderId = intval($_POST['folder_id'] ?? 0);
    $returnFolderId = !empty($_POST['return_folder_id']) ? intval($_POST['return_folder_id']) : null;
    
    if ($projectId && $folderId && hasPermission('documentation.upload')) {
        $folder = dbGetRow("SELECT id, name, parent_id FROM project_document_folders WHERE id = ? AND project_id = ?", [$folderId, $projectId]);
        if ($folder) {
            // Find all subfolder IDs recursively
            $folderIds = [$folderId];
            $queue = [$folderId];
            while (!empty($queue)) {
                $curr = array_shift($queue);
                $children = dbGetAll("SELECT id FROM project_document_folders WHERE parent_id = ? AND project_id = ?", [$curr, $projectId]);
                foreach ($children as $c) {
                    $cId = intval($c['id']);
                    $folderIds[] = $cId;
                    $queue[] = $cId;
                }
            }
            
            // Delete all physical files in these folders
            $inList = implode(',', $folderIds);
            $docs = dbGetAll("SELECT filename FROM project_documents WHERE folder_id IN ($inList) AND project_id = ?", [$projectId]);
            $uploadDir = __DIR__ . '/../../uploads/project_documents/';
            foreach ($docs as $d) {
                $fpath = $uploadDir . $d['filename'];
                if (file_exists($fpath)) {
                    @unlink($fpath);
                }
            }
            
            // Delete folder (foreign key cascades subfolders and document records)
            dbExecute("DELETE FROM project_document_folders WHERE id = ? AND project_id = ?", [$folderId, $projectId]);
            setFlash('success', 'Folder "' . sanitize($folder['name']) . '" dan seluruh isinya berhasil dihapus.');
            $returnFolderId = $folder['parent_id'];
        } else {
            setFlash('error', 'Folder tidak ditemukan.');
        }
    } else {
        setFlash('error', 'Anda tidak memiliki hak akses untuk menghapus folder.');
    }
    
    $redirectUrl = 'view.php?id=' . $projectId . '&tab=documentation' . ($returnFolderId ? '&folder_id=' . $returnFolderId : '');
    header('Location: ' . $redirectUrl);
    exit;
}

// Handle upload project documents
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_project_documents') {
    $projectId = intval($_GET['id'] ?? $_POST['project_id'] ?? 0);
    $folderId = !empty($_POST['folder_id']) ? intval($_POST['folder_id']) : null;
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || !empty($_POST['is_ajax']);
    
    // Verify folder belongs to project if specified
    if ($folderId) {
        $fCheck = dbGetRow("SELECT id FROM project_document_folders WHERE id = ? AND project_id = ?", [$folderId, $projectId]);
        if (!$fCheck) {
            $folderId = null;
        }
    }
    
    $redirectTabUrl = 'view.php?id=' . $projectId . '&tab=documentation' . ($folderId ? '&folder_id=' . $folderId : '');
    
    if (!$projectId || !hasPermission('documentation.upload')) {
        $msg = 'Anda tidak memiliki hak akses untuk mengunggah dokumen proyek.';
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }
        setFlash('error', $msg);
        header('Location: ' . $redirectTabUrl);
        exit;
    }
    
    if (!empty($_FILES['documents']['name'][0]) || (isset($_FILES['documents']['name']) && is_array($_FILES['documents']['name']) && count(array_filter($_FILES['documents']['name'])) > 0)) {
        $uploadDir = __DIR__ . '/../../uploads/project_documents/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $forbiddenExtensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'exe', 'dll', 'so', 'bat', 'cmd', 'sh', 'vbs', 'pl', 'cgi', 'htaccess', 'htpasswd'];
        $maxSize = 40 * 1024 * 1024; // 40MB aligned with server limits
        $uploadedCount = 0;
        $errors = [];
        
        $inputDescription = trim($_POST['description'] ?? '');
        $inputTitle = trim($_POST['title'] ?? '');
        
        $totalFiles = count($_FILES['documents']['name']);
        
        for ($i = 0; $i < $totalFiles; $i++) {
            $rawName = $_FILES['documents']['name'][$i] ?? '';
            if (empty($rawName)) {
                continue;
            }
            
            $errCode = $_FILES['documents']['error'][$i] ?? UPLOAD_ERR_OK;
            if ($errCode !== UPLOAD_ERR_OK) {
                switch ($errCode) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $errors[] = "File <strong>$rawName</strong> melebihi batas ukuran maksimal server (" . ini_get('upload_max_filesize') . ").";
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $errors[] = "File <strong>$rawName</strong> hanya terunggah sebagian.";
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        break;
                    default:
                        $errors[] = "File <strong>$rawName</strong> gagal diunggah (Kode Error: $errCode).";
                        break;
                }
                continue;
            }
            
            $originalName = $rawName;
            $tmpName = $_FILES['documents']['tmp_name'][$i];
            $fileSize = $_FILES['documents']['size'][$i];
            $fileType = $_FILES['documents']['type'][$i];
            
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            
            if (in_array($ext, $forbiddenExtensions)) {
                $errors[] = "File <strong>$originalName</strong> ditolak karena jenis file berisiko dieksekusi.";
                continue;
            }
            
            if ($fileSize > $maxSize) {
                $errors[] = "File <strong>$originalName</strong> melebihi batas ukuran 40MB.";
                continue;
            }
            
            // Handle custom filename/title set in upload queue
            $customFileName = trim($_POST['file_names'][$i] ?? '');
            if (!empty($customFileName)) {
                $customExt = strtolower(pathinfo($customFileName, PATHINFO_EXTENSION));
                $finalOriginalName = ($customExt === $ext) ? $customFileName : ($customFileName . '.' . $ext);
            } else {
                $finalOriginalName = $originalName;
            }
            
            $customTitle = trim($_POST['titles'][$i] ?? $_POST['custom_names'][$i] ?? '');
            if (!empty($customTitle)) {
                $docTitle = $customTitle;
            } else if ($totalFiles === 1 && !empty($inputTitle)) {
                $docTitle = $inputTitle;
            } else {
                $docTitle = pathinfo($finalOriginalName, PATHINFO_FILENAME);
            }
            
            $storedFilename = 'doc_' . $projectId . '_' . time() . '_' . $i . '_' . mt_rand(1000, 9999) . '.' . $ext;
            $targetPath = $uploadDir . $storedFilename;
            
            if (move_uploaded_file($tmpName, $targetPath)) {
                dbInsert("
                    INSERT INTO project_documents (project_id, folder_id, title, filename, original_name, file_type, file_size, category, description, uploaded_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)
                ", [
                    $projectId,
                    $folderId,
                    $docTitle,
                    $storedFilename,
                    $finalOriginalName,
                    $fileType,
                    $fileSize,
                    $inputDescription ?: null,
                    $_SESSION['user_id']
                ]);
                $uploadedCount++;
            } else {
                $errors[] = "Gagal memindahkan file <strong>$originalName</strong> ke folder server.";
            }
        }
        
        if ($isAjax) {
            header('Content-Type: application/json');
            if ($uploadedCount > 0) {
                $msg = "$uploadedCount dokumen berhasil diunggah.";
                setFlash('success', $msg);
                echo json_encode([
                    'success' => true,
                    'message' => $msg . (!empty($errors) ? '<br>' . implode('<br>', $errors) : ''),
                    'uploaded_count' => $uploadedCount,
                    'errors' => $errors,
                    'redirect' => $redirectTabUrl
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => !empty($errors) ? implode('<br>', $errors) : 'Gagal mengunggah dokumen proyek.'
                ]);
            }
            exit;
        }
        
        if ($uploadedCount > 0) {
            setFlash('success', "$uploadedCount dokumen berhasil diunggah.");
        }
        if (!empty($errors)) {
            setFlash('error', implode('<br>', $errors));
        }
    } else {
        $noFileMsg = 'Silakan pilih dokumen yang ingin diunggah terlebih dahulu.';
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $noFileMsg]);
            exit;
        }
        setFlash('error', $noFileMsg);
    }
    
    header('Location: ' . $redirectTabUrl);
    exit;
}

// Handle edit project document (rename & edit notes - no category)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_project_document') {
    $projectId = intval($_GET['id'] ?? $_POST['project_id'] ?? 0);
    $docId = intval($_POST['doc_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $folderId = !empty($_POST['folder_id']) ? intval($_POST['folder_id']) : null;
    
    if ($projectId && $docId && hasPermission('documentation.upload')) {
        if (!empty($title)) {
            $doc = dbGetRow("SELECT folder_id FROM project_documents WHERE id = ? AND project_id = ?", [$docId, $projectId]);
            if ($doc) {
                $folderId = $doc['folder_id'];
            }
            dbExecute("
                UPDATE project_documents 
                SET title = ?, description = ? 
                WHERE id = ? AND project_id = ?
            ", [$title, $description ?: null, $docId, $projectId]);
            setFlash('success', 'Informasi dokumen berhasil diperbarui.');
        } else {
            setFlash('error', 'Judul dokumen tidak boleh kosong.');
        }
    } else {
        setFlash('error', 'Anda tidak memiliki hak akses untuk mengubah data dokumen.');
    }
    
    $redirectUrl = 'view.php?id=' . $projectId . '&tab=documentation' . ($folderId ? '&folder_id=' . $folderId : '');
    header('Location: ' . $redirectUrl);
    exit;
}

// Handle delete project document
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_project_document') {
    $projectId = intval($_GET['id'] ?? $_POST['project_id'] ?? 0);
    $docId = intval($_POST['doc_id'] ?? 0);
    $folderId = !empty($_POST['folder_id']) ? intval($_POST['folder_id']) : null;
    
    if ($projectId && $docId && hasPermission('documentation.upload')) {
        $doc = dbGetRow("SELECT filename, title, original_name, folder_id FROM project_documents WHERE id = ? AND project_id = ?", [$docId, $projectId]);
        if ($doc) {
            $folderId = $doc['folder_id'];
            $filepath = __DIR__ . '/../../uploads/project_documents/' . $doc['filename'];
            if (file_exists($filepath)) {
                @unlink($filepath);
            }
            dbExecute("DELETE FROM project_documents WHERE id = ? AND project_id = ?", [$docId, $projectId]);
            setFlash('success', 'Dokumen "' . sanitize($doc['title']) . '" berhasil dihapus.');
        } else {
            setFlash('error', 'Dokumen tidak ditemukan.');
        }
    } else {
        setFlash('error', 'Anda tidak memiliki hak akses untuk menghapus dokumen.');
    }
    
    $redirectUrl = 'view.php?id=' . $projectId . '&tab=documentation' . ($folderId ? '&folder_id=' . $folderId : '');
    header('Location: ' . $redirectUrl);
    exit;
}

// Include Master Data handlers for POST actions (must be before rab_rap_handlers)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $projectId = $_GET['id'] ?? null;
    $project = dbGetRow("SELECT * FROM projects WHERE id = ?", [$projectId]);
    if ($project && $projectId) {
        include __DIR__ . '/tabs/master_data_handlers.php';
    }
}

// Include RAB/RAP handlers for POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $projectId = $_GET['id'] ?? null;
    $project = dbGetRow("SELECT * FROM projects WHERE id = ?", [$projectId]);
    if ($project && $projectId) {
        include __DIR__ . '/tabs/rab_rap_handlers.php';
    }
}

$projectId = $_GET['id'] ?? null;
$activeTab = $_GET['tab'] ?? 'detail';

if (!$projectId) {
    header('Location: index.php');
    exit;
}

// Get project
$project = dbGetRow("SELECT p.*, u.full_name as created_by_name FROM projects p LEFT JOIN users u ON p.created_by = u.id WHERE p.id = ?", [$projectId]);

if (!$project) {
    setFlash('error', 'Proyek tidak ditemukan!');
    header('Location: index.php');
    exit;
}

// Check access using view_mode based system
$viewMode = getProjectViewMode();

if ($viewMode === 'none') {
    setFlash('error', 'Anda tidak memiliki akses ke proyek.');
    header('Location: index.php');
    exit;
}

if (!canAccessProject($projectId)) {
    setFlash('error', 'Anda tidak memiliki akses ke proyek ini.');
    header('Location: index.php');
    exit;
}

// Users with 'assigned' mode cannot see draft projects
if ($viewMode === 'assigned' && $project['status'] === 'draft') {
    setFlash('error', 'Proyek masih dalam tahap draft.');
    header('Location: index.php');
    exit;
}

// Handle actions
if (isset($_GET['action']) && hasPermission('projects.edit')) {
    $action = $_GET['action'];
    
    if ($action === 'start' && $project['status'] === 'draft' && $project['rap_submitted']) {
        dbExecute("UPDATE projects SET status = 'on_progress' WHERE id = ?", [$projectId]);
        setFlash('success', 'Proyek berhasil dimulai!');
        header('Location: view.php?id=' . $projectId);
        exit;
    }
    
    if ($action === 'complete' && $project['status'] === 'on_progress') {
        dbExecute("UPDATE projects SET status = 'completed' WHERE id = ?", [$projectId]);
        setFlash('success', 'Proyek berhasil diselesaikan!');
        header('Location: view.php?id=' . $projectId);
        exit;
    }
}

// Get summary data
$itemCount = dbGetRow("SELECT COUNT(*) as cnt FROM project_items WHERE project_id = ?", [$projectId])['cnt'] ?? 0;
$ahspCount = dbGetRow("SELECT COUNT(*) as cnt FROM project_ahsp WHERE project_id = ?", [$projectId])['cnt'] ?? 0;
$docCount = dbGetRow("SELECT COUNT(*) as cnt FROM project_documents WHERE project_id = ?", [$projectId])['cnt'] ?? 0;

// Load MC0 and CCO snapshots for dynamic tabs
$mc0Snapshot = dbGetRow("SELECT * FROM rab_snapshots WHERE project_id = ? AND UPPER(name) = 'MC0' ORDER BY id DESC LIMIT 1", [$projectId]);
$ccoSnapshot = dbGetRow("SELECT * FROM rab_snapshots WHERE project_id = ? AND UPPER(name) = 'CCO' ORDER BY id DESC LIMIT 1", [$projectId]);

// RAB Summary - base total
$ahspPrices = [];
$ahspRows = dbGetAll("
    SELECT pad.ahsp_id, SUM(pad.coefficient * COALESCE(pad.unit_price, pi.price)) as unit_price
    FROM project_ahsp_details pad
    JOIN project_items pi ON pad.item_id = pi.id
    JOIN project_ahsp pa ON pad.ahsp_id = pa.id
    WHERE pa.project_id = ?
    GROUP BY pad.ahsp_id
", [$projectId]);
foreach ($ahspRows as $r) {
    $ahspPrices[$r['ahsp_id']] = floatval($r['unit_price']);
}

$subcatRows = dbGetAll("
    SELECT rs.volume, rs.unit_price, rs.ahsp_id
    FROM rab_subcategories rs
    JOIN rab_categories rc ON rs.category_id = rc.id
    WHERE rc.project_id = ?
", [$projectId]);

$rabBaseTotal = 0;
foreach ($subcatRows as $s) {
    $vol = floatval($s['volume']);
    $unitPrice = ($s['ahsp_id'] && isset($ahspPrices[$s['ahsp_id']])) 
        ? $ahspPrices[$s['ahsp_id']] 
        : floatval($s['unit_price']);
    $rabBaseTotal += ($vol * $unitPrice);
}

// Apply overhead and PPN to get rounded total
$rabOverheadPct = getProjectOverheadProfitPct($project, 'rab');
$ppnPct = floatval($project['ppn_percentage'] ?? 11);

$rabWithOverhead = $rabBaseTotal * (1 + ($rabOverheadPct / 100));
$rabPpn = $rabWithOverhead * ($ppnPct / 100);
$rabTotal = ceil(($rabWithOverhead + $rabPpn) / 10) * 10;

// RAP Summary - base total
$ahspRapPrices = [];
$ahspRapRows = dbGetAll("
    SELECT par.ahsp_code, SUM(d.coefficient * COALESCE(d.unit_price, pir.price)) as unit_price
    FROM project_ahsp_details_rap d
    JOIN project_ahsp_rap par ON d.ahsp_id = par.id
    JOIN project_items_rap pir ON d.item_id = pir.id
    WHERE par.project_id = ?
    GROUP BY par.ahsp_code
", [$projectId]);
foreach ($ahspRapRows as $r) {
    $ahspRapPrices[$r['ahsp_code']] = floatval($r['unit_price']);
}

$rapItemsRows = dbGetAll("
    SELECT rap.volume, rap.unit_price, pa.ahsp_code
    FROM rap_items rap
    JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
    JOIN rab_categories rc ON rs.category_id = rc.id
    LEFT JOIN project_ahsp pa ON pa.id = rs.ahsp_id
    WHERE rc.project_id = ?
", [$projectId]);

$rapBaseTotal = 0;
foreach ($rapItemsRows as $r) {
    $vol = floatval($r['volume']);
    $ahspCode = $r['ahsp_code'] ?? null;
    $unitPrice = ($ahspCode && isset($ahspRapPrices[$ahspCode])) 
        ? $ahspRapPrices[$ahspCode] 
        : floatval($r['unit_price']);
    $rapBaseTotal += ($vol * $unitPrice);
}

// RAP total with overhead and PPN
$rapOverheadPct = getProjectOverheadProfitPct($project, 'rap');
$rapWithOverhead = $rapBaseTotal * (1 + ($rapOverheadPct / 100));
$rapPpn = $rapWithOverhead * ($ppnPct / 100);
$rapTotal = ceil(($rapWithOverhead + $rapPpn) / 10) * 10;

// Actual Spending (Matches actual.php logic)
$actualBaseTotal = dbGetRow("
    SELECT COALESCE(SUM(reqi.total_price), 0) as total
    FROM request_items reqi
    JOIN requests req ON reqi.request_id = req.id
    WHERE req.project_id = ? AND req.status = 'approved'
", [$projectId])['total'] ?? 0;

// Actual total with PPN and rounding
$actualPpn = $actualBaseTotal * ($ppnPct / 100);
$actualTotal = ceil(($actualBaseTotal + $actualPpn) / 10) * 10;

// Pending Requests
$pendingRequests = dbGetRow("SELECT COUNT(*) as cnt FROM requests WHERE project_id = ? AND status = 'pending'", [$projectId])['cnt'] ?? 0;

// Sisa Anggaran = RAP Total - Actual
$sisaAnggaran = $rapTotal - $actualTotal;

$pageTitle = $project['name'];
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Title -->
<div class="row">
    <div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
            <h4 class="mb-sm-0"><?= sanitize($project['name']) ?></h4>
            <div class="page-title-right">
                <ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>">PCC</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Proyek</a></li>
                    <li class="breadcrumb-item active">Dashboard</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Status Bar -->
<div class="row mb-3">
    <div class="col-12">
        <div class="card bg-light">
            <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <span class="me-3">Status: <?= getStatusBadge($project['status']) ?></span>
                        <?php if ($project['region_name']): ?>
                        <span class="text-muted me-3">Wilayah: <?= sanitize($project['region_name']) ?></span>
                        <?php endif; ?>
                        <span id="lockBadge">
                            <?php if (!empty($project['request_locked'])): ?>
                            <span class="badge bg-danger"><i class="mdi mdi-lock"></i> Pengajuan Terkunci</span>
                            <?php else: ?>
                            <span class="badge bg-success"><i class="mdi mdi-lock-open"></i> Pengajuan Terbuka</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="btn-group">
                        <?php if (hasPermission('projects.lock_request') && $project['status'] === 'on_progress'): ?>
                        <button type="button" class="btn btn-<?= !empty($project['request_locked']) ? 'success' : 'danger' ?> btn-sm" 
                                id="btnToggleLock" onclick="toggleRequestLock(<?= $projectId ?>)">
                            <i class="mdi mdi-<?= !empty($project['request_locked']) ? 'lock-open-variant' : 'lock' ?>"></i> 
                            <?= !empty($project['request_locked']) ? 'Buka Pengajuan' : 'Kunci Pengajuan' ?>
                        </button>
                        <?php endif; ?>
                        <?php if (hasPermission('projects.edit')): ?>
                            <?php if ($project['status'] === 'draft' && $project['rap_submitted']): ?>
                            <a href="#" class="btn btn-success btn-sm"
                               onclick="confirmAction(function(){ window.location.href='?id=<?= $projectId ?>&action=start'; }, {title: 'Mulai Proyek', message: 'Yakin ingin memulai proyek ini?', buttonText: 'Mulai', buttonClass: 'btn-success'}); return false;">
                                <i class="mdi mdi-play"></i> Mulai Proyek
                            </a>
                            <?php elseif ($project['status'] === 'on_progress'): ?>
                            <a href="#" class="btn btn-success btn-sm"
                               onclick="confirmAction(function(){ window.location.href='?id=<?= $projectId ?>&action=complete'; }, {title: 'Selesaikan Proyek', message: 'Yakin ingin menyelesaikan proyek ini?', buttonText: 'Selesai', buttonClass: 'btn-success'}); return false;">
                                <i class="mdi mdi-check-all"></i> Selesai
                            </a>
                            <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#revertDraftModal">
                                <i class="mdi mdi-undo"></i> Kembali ke Draft
                            </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-3">
    <div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 mb-3">
        <div class="card mini-stats-wid h-100 mb-0">
            <div class="card-body">
                <div class="d-flex">
                    <div class="flex-grow-1">
                        <p class="text-muted fw-medium mb-2">Total RAB</p>
                        <h5 class="mb-0"><?= formatRupiah($rabTotal) ?></h5>
                    </div>
                    <div class="avatar-sm align-self-center ms-2 flex-shrink-0">
                        <span class="avatar-title rounded-circle bg-primary bg-soft text-primary font-size-24">
                            <i class="mdi mdi-file-document-outline"></i>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 mb-3">
        <div class="card mini-stats-wid h-100 mb-0">
            <div class="card-body">
                <div class="d-flex">
                    <div class="flex-grow-1">
                        <p class="text-muted fw-medium mb-2">Total RAP</p>
                        <h5 class="mb-0"><?= formatRupiah($rapTotal) ?></h5>
                    </div>
                    <div class="avatar-sm align-self-center ms-2 flex-shrink-0">
                        <span class="avatar-title rounded-circle bg-info bg-soft text-info font-size-24">
                            <i class="mdi mdi-file-document-multiple-outline"></i>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 mb-3">
        <div class="card mini-stats-wid h-100 mb-0">
            <div class="card-body">
                <div class="d-flex">
                    <div class="flex-grow-1">
                        <p class="text-muted fw-medium mb-2">Realisasi</p>
                        <h5 class="mb-0"><?= formatRupiah($actualTotal) ?></h5>
                    </div>
                    <div class="avatar-sm align-self-center ms-2 flex-shrink-0">
                        <span class="avatar-title rounded-circle bg-success bg-soft text-success font-size-24">
                            <i class="mdi mdi-cash-check"></i>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 mb-3">
        <div class="card mini-stats-wid h-100 mb-0">
            <div class="card-body">
                <div class="d-flex">
                    <div class="flex-grow-1">
                        <p class="text-muted fw-medium mb-2">
                            Sisa Anggaran
                            <small class="text-muted d-block fw-normal">(RAP - Realisasi)</small>
                        </p>
                        <h5 class="mb-0 <?= $sisaAnggaran < 0 ? 'text-danger' : '' ?>"><?= formatRupiah($sisaAnggaran) ?></h5>
                    </div>
                    <div class="avatar-sm align-self-center ms-2 flex-shrink-0">
                        <span class="avatar-title rounded-circle bg-warning bg-soft text-warning font-size-24">
                            <i class="mdi mdi-wallet-outline"></i>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs Navigation -->
<div class="card">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link <?= $activeTab == 'detail' ? 'active' : '' ?>" href="?id=<?= $projectId ?>&tab=detail">
                    <i class="mdi mdi-information-outline"></i> Detail
                </a>
            </li>
            <?php if (hasPermission('master_data.view')): ?>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab == 'master' ? 'active' : '' ?>" href="?id=<?= $projectId ?>&tab=master">
                    <i class="mdi mdi-database"></i> Master Data
                    <span class="badge bg-secondary"><?= $itemCount ?> / <?= $ahspCount ?></span>
                </a>
            </li>
            <?php endif; ?>
            <?php if (hasPermission('rab.view')): ?>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab == 'rab' ? 'active' : '' ?>" href="?id=<?= $projectId ?>&tab=rab">
                    <i class="mdi mdi-file-document-outline"></i> RAB
                    <?php if ($project['rab_submitted']): ?>
                    <i class="mdi mdi-check-circle text-success"></i>
                    <?php endif; ?>
                </a>
            </li>
            <?php if (!empty($mc0Snapshot)): ?>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab == 'mc0' ? 'active' : '' ?>" href="?id=<?= $projectId ?>&tab=mc0">
                    <i class="mdi mdi-file-document-edit-outline"></i> MC0
                    <span class="badge bg-info-subtle text-info font-size-10">Salinan</span>
                </a>
            </li>
            <?php endif; ?>
            <?php if (!empty($ccoSnapshot)): ?>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab == 'cco' ? 'active' : '' ?>" href="?id=<?= $projectId ?>&tab=cco">
                    <i class="mdi mdi-file-document-edit-outline"></i> CCO
                    <span class="badge bg-warning-subtle text-warning font-size-10">Salinan</span>
                </a>
            </li>
            <?php endif; ?>
            <?php endif; ?>
            <?php if (hasPermission('rap.view')): ?>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab == 'rap' ? 'active' : '' ?>" href="?id=<?= $projectId ?>&tab=rap">
                    <i class="mdi mdi-file-document-multiple-outline"></i> RAP
                </a>
            </li>
            <?php endif; ?>
            <?php if (hasPermission('reports.view')): ?>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab == 'actual' ? 'active' : '' ?>" href="?id=<?= $projectId ?>&tab=actual">
                    <i class="mdi mdi-cash-check"></i> Realisasi
                </a>
            </li>
            <?php endif; ?>
            <?php if (hasPermission('requests.view')): ?>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab == 'requests' ? 'active' : '' ?>" href="?id=<?= $projectId ?>&tab=requests">
                    <i class="mdi mdi-file-document-edit"></i> Pengajuan
                    <?php if ($pendingRequests > 0): ?>
                    <span class="badge bg-warning"><?= $pendingRequests ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>
            <?php if (hasPermission('documentation.view')): ?>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab == 'documentation' ? 'active' : '' ?>" href="?id=<?= $projectId ?>&tab=documentation">
                    <i class="mdi mdi-folder-multiple-image"></i> Dokumentasi
                    <?php if ($docCount > 0): ?>
                    <span class="badge bg-primary"><?= $docCount ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </div>
    <div class="card-body">
        <?php
        // Include the appropriate tab content
        switch ($activeTab) {
            case 'master':
                if (hasPermission('master_data.view')) {
                    include __DIR__ . '/tabs/master_data.php';
                } else {
                    echo '<div class="alert alert-warning">Anda tidak memiliki akses ke Master Data.</div>';
                }
                break;
            case 'rab':
                if (hasPermission('rab.view')) {
                    include __DIR__ . '/tabs/rab.php';
                } else {
                    echo '<div class="alert alert-warning">Anda tidak memiliki akses ke RAB.</div>';
                }
                break;
            case 'mc0':
                if (hasPermission('rab.view') && !empty($mc0Snapshot)) {
                    $currentSnapshot = $mc0Snapshot;
                    $snapshotType = 'MC0';
                    include __DIR__ . '/tabs/snapshot_view.php';
                } else {
                    echo '<div class="alert alert-warning">Salinan MC0 belum dibuat atau Anda tidak memiliki akses.</div>';
                }
                break;
            case 'cco':
                if (hasPermission('rab.view') && !empty($ccoSnapshot)) {
                    $currentSnapshot = $ccoSnapshot;
                    $snapshotType = 'CCO';
                    include __DIR__ . '/tabs/snapshot_view.php';
                } else {
                    echo '<div class="alert alert-warning">Salinan CCO belum dibuat atau Anda tidak memiliki akses.</div>';
                }
                break;
            case 'rap':
                if (hasPermission('rap.view')) {
                    include __DIR__ . '/tabs/rap.php';
                } else {
                    echo '<div class="alert alert-warning">Anda tidak memiliki akses ke RAP.</div>';
                }
                break;
            case 'actual':
                if (hasPermission('reports.view')) {
                    include __DIR__ . '/tabs/actual.php';
                } else {
                    echo '<div class="alert alert-warning">Anda tidak memiliki akses ke Realisasi.</div>';
                }
                break;
            case 'requests':
                if (hasPermission('requests.view')) {
                    include __DIR__ . '/tabs/requests.php';
                } else {
                    echo '<div class="alert alert-warning">Anda tidak memiliki akses ke Pengajuan.</div>';
                }
                break;
            case 'documentation':
                if (hasPermission('documentation.view')) {
                    include __DIR__ . '/tabs/documentation.php';
                } else {
                    echo '<div class="alert alert-warning">Anda tidak memiliki akses ke Dokumentasi Proyek.</div>';
                }
                break;
            case 'detail':
            default:
                include __DIR__ . '/tabs/detail.php';
                break;
        }
        ?>
    </div>
</div>

<!-- Modal Konfirmasi Kembali ke Draft -->
<?php if (hasPermission('projects.edit') && $project['status'] === 'on_progress'): ?>
<div class="modal fade" id="revertDraftModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="mdi mdi-undo text-warning"></i> Kembali ke Draft</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning mb-3">
                    <i class="mdi mdi-alert"></i>
                    <strong>Perhatian:</strong> Mengembalikan proyek ke status Draft akan:
                    <ul class="mb-0 mt-2">
                        <li>Membuka kunci data <strong>RAB, RAP, Master Data, dan AHSP</strong></li>
                        <li>Mengizinkan pengeditan kembali pada data tersebut</li>
                        <li>Data progress mingguan yang sudah diinput <strong>TIDAK</strong> akan dihapus</li>
                    </ul>
                </div>
                <p class="mb-0">Apakah Anda yakin ingin mengembalikan proyek <strong><?= sanitize($project['name']) ?></strong> ke status Draft?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <form method="POST" action="view.php?id=<?= $projectId ?>" class="d-inline">
                    <input type="hidden" name="action" value="revert_to_draft">
                    <button type="submit" class="btn btn-warning">
                        <i class="mdi mdi-undo"></i> Ya, Kembali ke Draft
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

<script>
function toggleRequestLock(projectId) {
    const btn = $('#btnToggleLock');
    const isCurrentlyLocked = btn.hasClass('btn-success'); // success = unlock button (currently locked)
    const action = isCurrentlyLocked ? 'membuka' : 'mengunci';
    
    if (!confirm(`Yakin ingin ${action} pengajuan untuk proyek ini?`)) return;
    
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    
    $.post('view.php?id=' + projectId, { action: 'toggle_request_lock', project_id: projectId }, function(res) {
        if (res.success) {
            // Reload page to update all lock-dependent UI
            location.reload();
        } else {
            alert(res.message || 'Gagal mengubah status lock');
            btn.prop('disabled', false);
        }
    }, 'json').fail(function() {
        alert('Gagal menghubungi server');
        btn.prop('disabled', false);
    });
}
</script>
