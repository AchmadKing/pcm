<?php
/**
 * Project Dashboard - Tabbed View
 * PCM - Project Cost Management System
 */

// AJAX Handler: Weekly Detail Modal - returns approved requests for a specific week+subcategory
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
    
    $details = dbGetAll("
        SELECT req.id, req.request_number, req.request_date, req.description, req.admin_notes,
               u.full_name as created_by_name,
               COALESCE(SUM(reqi.unit_price * reqi.coefficient), 0) as subcategory_amount
        FROM requests req
        JOIN request_items reqi ON reqi.request_id = req.id
        LEFT JOIN users u ON req.created_by = u.id
        WHERE req.project_id = ? 
          AND req.status = 'approved'
          AND (req.target_week = ? OR (req.target_week IS NULL AND req.week_number = ?))
          AND reqi.subcategory_id = ?
        GROUP BY req.id
        ORDER BY req.request_date ASC
    ", [$pId, $weekNum, $weekNum, $subId]);
    
    echo json_encode(['data' => $details]);
    exit;
}

// AJAX Handler - Must be FIRST before any includes to prevent output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_item_ajax') {
    // Only load what we need for AJAX
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
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
        // Check for duplicate item_code (exclude current item)
        if (!empty($itemCode)) {
            $existing = dbGetRow("SELECT id FROM project_items WHERE project_id = ? AND item_code = ? AND id != ?", 
                [$projectId, $itemCode, $itemId]);
            if ($existing) {
                header('Content-Type: application/json');
                die(json_encode(['success' => false, 'message' => 'Kode item "' . $itemCode . '" sudah digunakan!']));
            }
        }
        
        dbExecute("UPDATE project_items SET item_code = ?, name = ?, brand = ?, category = ?, unit = ?, price = ?, actual_price = ? WHERE id = ? AND project_id = ?",
            [$itemCode, $name, $brand ?: null, $category, $unit, $price, $actualPrice, $itemId, $projectId]);
        
        // Note: AHSP sync happens when page is loaded normally (syncItemToAhsp is in master_data.php)
        
        header('Content-Type: application/json');
        die(json_encode(['success' => true, 'message' => 'Item berhasil disimpan!']));
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
    
    // Auth Check
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
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
            // Find all affected AHSPs that use this item
            $affectedAhsp = dbGetAll("
                SELECT DISTINCT d.ahsp_id 
                FROM project_ahsp_details_rap d
                JOIN project_ahsp_rap pa ON d.ahsp_id = pa.id
                WHERE d.item_id = ? AND pa.project_id = ?
            ", [$itemId, $projectId]);
            
            // Recalculate each affected AHSP
            // Note: d.unit_price stays NULL so COALESCE(d.unit_price, i.price) uses current item price
            foreach ($affectedAhsp as $row) {
                recalculateRapAhspPrice($row['ahsp_id']);
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
            
            // Duplicate Check
            if (!empty($itemCode)) {
                $existing = dbGetRow("SELECT id FROM project_items_rap WHERE project_id = ? AND item_code = ? AND id != ?", 
                    [$projectId, $itemCode, $itemId]);
                if ($existing) {
                    header('Content-Type: application/json');
                    die(json_encode(['success' => false, 'message' => 'Kode item "' . $itemCode . '" sudah digunakan!']));
                }
            }
            
            dbExecute("UPDATE project_items_rap SET item_code = ?, name = ?, brand = ?, category = ?, unit = ?, price = ?, actual_price = ? WHERE id = ? AND project_id = ?",
                [$itemCode, $name, $brand ?: null, $category, $unit, $price, $actualPrice, $itemId, $projectId]);
            
            syncRapItemToAhsp($itemId, $projectId);
            
            header('Content-Type: application/json');
            die(json_encode(['success' => true, 'message' => 'Item RAP berhasil disimpan!']));
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
    $overheadPct = $project['overhead_percentage'] ?? 10;
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
    
    $ahspListRap = dbGetAll("SELECT * FROM project_ahsp_rap WHERE project_id = ? ORDER BY $ahspOrderBy $ahspSortOrder", [$projectId]);
    
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

// AJAX Handler for RAB/RAP Volume Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['ajax_update_rab_volume', 'ajax_update_rap_volume'])) {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    
    header('Content-Type: application/json');
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
        die(json_encode(['success' => false, 'message' => 'Unauthorized']));
    }
    
    $action = $_POST['action'];
    $id = intval($_POST['id'] ?? 0);
    $value = floatval($_POST['value'] ?? 0);
    
    try {
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
        }
    } catch (Exception $e) {
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();

// Handle start project action (must be before HTML output)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'start_project') {
    $projectId = $_GET['id'] ?? null;
    
    if ($projectId && isAdmin()) {
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
    
    if ($projectId && isAdmin()) {
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

// Check access for field team
if (!isAdmin() && $project['status'] === 'draft') {
    setFlash('error', 'Proyek masih dalam tahap draft.');
    header('Location: index.php');
    exit;
}

// Handle actions
if (isset($_GET['action']) && isAdmin()) {
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

// RAB Summary - base total
$rabBaseTotal = dbGetRow("
    SELECT COALESCE(SUM(rs.volume * rs.unit_price), 0) as total
    FROM rab_subcategories rs
    JOIN rab_categories rc ON rs.category_id = rc.id
    WHERE rc.project_id = ?
", [$projectId])['total'] ?? 0;

// Apply overhead and PPN to get rounded total
$overheadPct = $project['overhead_percentage'] ?? 10;
$ppnPct = $project['ppn_percentage'] ?? 11;

// RAB total with overhead
$rabWithOverhead = $rabBaseTotal * (1 + ($overheadPct / 100));
$rabPpn = $rabWithOverhead * ($ppnPct / 100);
$rabTotal = ceil(($rabWithOverhead + $rabPpn) / 10) * 10;

// RAP Summary - base total
$rapBaseTotal = dbGetRow("
    SELECT COALESCE(SUM(rap.volume * rap.unit_price), 0) as total
    FROM rap_items rap
    JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
    JOIN rab_categories rc ON rs.category_id = rc.id
    WHERE rc.project_id = ?
", [$projectId])['total'] ?? 0;

// RAP total with overhead and PPN
$rapWithOverhead = $rapBaseTotal * (1 + ($overheadPct / 100));
$rapPpn = $rapWithOverhead * ($ppnPct / 100);
$rapTotal = ceil(($rapWithOverhead + $rapPpn) / 10) * 10;

// Actual Spending
$actualTotal = dbGetRow("
    SELECT COALESCE(SUM(reqi.total_price), 0) as total
    FROM request_items reqi
    JOIN requests req ON reqi.request_id = req.id
    WHERE req.project_id = ? AND req.status = 'approved'
", [$projectId])['total'] ?? 0;

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
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>">PCM</a></li>
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
                        <span class="text-muted">Wilayah: <?= sanitize($project['region_name']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="btn-group">
                        <?php if (isAdmin()): ?>
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
                        <p class="text-muted fw-medium mb-2">Sisa Anggaran(RAP-Realisasi)</p>
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
            <?php if (isAdmin()): ?>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab == 'master' ? 'active' : '' ?>" href="?id=<?= $projectId ?>&tab=master">
                    <i class="mdi mdi-database"></i> Master Data
                    <span class="badge bg-secondary"><?= $itemCount ?> / <?= $ahspCount ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab == 'rab' ? 'active' : '' ?>" href="?id=<?= $projectId ?>&tab=rab">
                    <i class="mdi mdi-file-document-outline"></i> RAB
                    <?php if ($project['rab_submitted']): ?>
                    <i class="mdi mdi-check-circle text-success"></i>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab == 'rap' ? 'active' : '' ?>" href="?id=<?= $projectId ?>&tab=rap">
                    <i class="mdi mdi-file-document-multiple-outline"></i> RAP
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab == 'actual' ? 'active' : '' ?>" href="?id=<?= $projectId ?>&tab=actual">
                    <i class="mdi mdi-cash-check"></i> Realisasi
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab == 'requests' ? 'active' : '' ?>" href="?id=<?= $projectId ?>&tab=requests">
                    <i class="mdi mdi-file-document-edit"></i> Pengajuan
                    <?php if ($pendingRequests > 0): ?>
                    <span class="badge bg-warning"><?= $pendingRequests ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">
        <?php
        // Include the appropriate tab content
        switch ($activeTab) {
            case 'master':
                if (isAdmin()) {
                    include __DIR__ . '/tabs/master_data.php';
                }
                break;
            case 'rab':
                if (isAdmin()) {
                    include __DIR__ . '/tabs/rab.php';
                }
                break;
            case 'rap':
                if (isAdmin()) {
                    include __DIR__ . '/tabs/rap.php';
                }
                break;
            case 'actual':
                include __DIR__ . '/tabs/actual.php';
                break;
            case 'requests':
                include __DIR__ . '/tabs/requests.php';
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
<?php if (isAdmin() && $project['status'] === 'on_progress'): ?>
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

