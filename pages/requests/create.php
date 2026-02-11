<?php
/**
 * Create Request - Dynamic Form with Category/Subcategory Selection
 * PCM - Project Cost Management System
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();

$projectId = $_GET['project_id'] ?? null;
$error = '';

// =====================================
// AJAX HANDLERS (must be before any output)
// =====================================

// AJAX: Get categories for a project
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_categories' && $projectId) {
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
    
    // Get RAP AHSP details for this subcategory
    $items = dbGetAll("
        SELECT rad.id, rad.category as item_type, pi.item_code, pi.name, pi.unit, 
               rad.coefficient, rad.unit_price, pi.actual_price
        FROM rap_ahsp_details rad
        JOIN project_items pi ON rad.item_id = pi.id
        JOIN rap_items ri ON rad.rap_item_id = ri.id
        WHERE ri.subcategory_id = ?
        ORDER BY rad.category, pi.name
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
    
    // Get RAP AHSP details filtered by item_type
    $items = dbGetAll("
        SELECT rad.id, rad.category as item_type, pi.item_code, pi.name, pi.unit, 
               rad.coefficient, rad.unit_price, pi.actual_price
        FROM rap_ahsp_details rad
        JOIN project_items pi ON rad.item_id = pi.id
        JOIN rap_items ri ON rad.rap_item_id = ri.id
        WHERE ri.subcategory_id = ? AND rad.category = ?
        ORDER BY pi.name
    ", [$subcategoryId, $itemType]);
    
    echo json_encode(['success' => true, 'data' => $items]);
    exit;
}

// AJAX: Get RAP pekerjaan for checkbox selection (grouped by category)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_rap_pekerjaan' && $projectId) {
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
    
    // Get items from rap_ahsp_details for selected subcategories and item type
    // Include rap_qty and used_qty for remaining (sisa) calculation
    $params = array_merge($subcategoryIds, [$itemType]);
    $items = dbGetAll("
        SELECT DISTINCT rad.id, rad.category as item_type, pi.item_code, pi.name, pi.unit, 
               rad.coefficient, rad.unit_price, pi.actual_price,
               rs.id as subcategory_id, rs.code as subcat_code, rs.name as subcat_name,
               ri.volume as rap_volume,
               (rad.coefficient * COALESCE(ri.volume, 0)) as rap_qty,
               (SELECT COALESCE(SUM(reqi2.coefficient), 0) 
                FROM request_items reqi2 
                JOIN requests r ON reqi2.request_id = r.id 
                WHERE reqi2.item_code = pi.item_code 
                  AND reqi2.subcategory_id = rs.id
                  AND r.status IN ('approved','pending')
               ) as used_qty
        FROM rap_ahsp_details rad
        JOIN project_items pi ON rad.item_id = pi.id
        JOIN rap_items ri ON rad.rap_item_id = ri.id
        JOIN rab_subcategories rs ON ri.subcategory_id = rs.id
        WHERE ri.subcategory_id IN ($placeholders) AND rad.category = ?
        ORDER BY rs.code, pi.name
    ", $params);
    
    echo json_encode(['success' => true, 'data' => $items]);
    exit;
}

// Get on-progress projects
$projects = dbGetAll("SELECT id, name FROM projects WHERE status = 'on_progress' ORDER BY name");

$project = null;
$categories = [];
if ($projectId) {
    $project = dbGetRow("SELECT * FROM projects WHERE id = ? AND status = 'on_progress'", [$projectId]);
    
    if (!$project) {
        setFlash('error', 'Proyek tidak ditemukan atau belum dimulai!');
        header('Location: index.php');
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
}

// Handle form submission (via AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_request') {
    header('Content-Type: application/json');
    
    $projectId = intval($_POST['project_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $items = json_decode($_POST['items'] ?? '[]', true);
    
    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Minimal tambahkan satu item!']);
        exit;
    }
    
    try {
        $pdo = getDB();
        $pdo->beginTransaction();
        
        // Generate request number
        $requestNumber = generateRequestNumber($projectId);
        
        // Create request header (week_number = NULL, set by admin during approval)
        $requestId = dbInsert("
            INSERT INTO requests (project_id, request_number, request_date, week_number, description, status, created_by)
            VALUES (?, ?, CURDATE(), NULL, ?, 'pending', ?)
        ", [$projectId, $requestNumber, $description, getCurrentUserId()]);
        
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
            
            if ($coefficient > 0 && $unitPrice > 0) {
                $totalPrice = $unitPrice * $coefficient;
                
                dbInsert("
                    INSERT INTO request_items 
                    (request_id, category_id, subcategory_id, item_code, item_type, item_name, unit, unit_price, quantity, coefficient, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ", [
                    $requestId, 
                    $categoryId ?: null, 
                    $subcategoryId ?: null, 
                    $itemCode ?: null, 
                    $itemType ?: null,
                    $itemName, 
                    $unit, 
                    $unitPrice,
                    $coefficient, // quantity = coefficient for now
                    $coefficient,
                    $notes
                ]);
                
                $totalAmount += $totalPrice;
            }
        }
        
        // Update request total
        dbExecute("UPDATE requests SET total_amount = ? WHERE id = ?", [$totalAmount, $requestId]);
        
        // Handle file uploads
        if (!empty($_FILES['attachments']['name'][0])) {
            $uploadDir = __DIR__ . '/../../uploads/receipts/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
            $maxSize = 5 * 1024 * 1024; // 5MB
            
            foreach ($_FILES['attachments']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['attachments']['error'][$key] !== UPLOAD_ERR_OK) continue;
                
                $fileType = $_FILES['attachments']['type'][$key];
                $fileSize = $_FILES['attachments']['size'][$key];
                $originalName = $_FILES['attachments']['name'][$key];
                
                // Validate
                if (!in_array($fileType, $allowedTypes)) continue;
                if ($fileSize > $maxSize) continue;
                
                // Generate unique filename
                $ext = pathinfo($originalName, PATHINFO_EXTENSION);
                $filename = 'req_' . $requestId . '_' . time() . '_' . $key . '.' . $ext;
                $filepath = $uploadDir . $filename;
                
                if (move_uploaded_file($tmpName, $filepath)) {
                    dbInsert("
                        INSERT INTO request_attachments (request_id, filename, original_name, file_type, file_size)
                        VALUES (?, ?, ?, ?, ?)
                    ", [$requestId, $filename, $originalName, $fileType, $fileSize]);
                }
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Pengajuan ' . $requestNumber . ' berhasil dibuat!',
            'request_id' => $requestId,
            'redirect' => 'index.php'
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
</style>

<!-- Request Form -->
<div class="row">
    <div class="col-12">
        <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
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
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Pengaju</label>
                            <input type="text" class="form-control readonly-field" value="<?= sanitize($_SESSION['user_name'] ?? 'User') ?>" readonly>
                            <input type="hidden" name="created_by" value="<?= getCurrentUserId() ?>">
                        </div>
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Keterangan</label>
                            <input type="text" class="form-control" name="description" id="description" placeholder="Keterangan pengajuan (opsional)">
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
                            <select class="form-select" id="itemSelect" disabled>
                                <option value="">-- Pilih Jenis Item dahulu --</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Item Preview & Add Box -->
                    <div id="itemPreviewBox" class="border rounded p-3 bg-light mb-3" style="display:none;">
                        <div class="row align-items-end">
                            <div class="col-md-4 mb-2">
                                <label class="form-label mb-1"><small>Kode & Nama Item</small></label>
                                <div class="fw-bold" id="previewItemName">-</div>
                                <small class="text-muted" id="previewItemCode">-</small>
                            </div>
                            <div class="col-md-2 mb-2">
                                <label class="form-label mb-1"><small>Satuan</small></label>
                                <input type="text" class="form-control form-control-sm readonly-field" id="previewUnit" readonly>
                            </div>
                            <div class="col-md-2 mb-2">
                                <label class="form-label mb-1"><small>Harga Satuan <span class="text-danger">*</span></small></label>
                                <input type="text" class="form-control form-control-sm text-end" id="previewPrice" placeholder="0">
                            </div>
                            <div class="col-md-2 mb-2">
                                <label class="form-label mb-1" id="previewCoefLabel"><small>Koefisien <span class="text-danger">*</span></small></label>
                                <input type="text" class="form-control form-control-sm text-end" id="previewCoef" placeholder="0">
                            </div>
                            <div class="col-md-2 mb-2">
                                <button type="button" class="btn btn-success btn-sm w-100" id="addToListBtn">
                                    <i class="mdi mdi-plus"></i> Tambah
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
                                    <th width="200">Item</th>
                                    <th>Nama Item</th>
                                    <th width="80">Satuan</th>
                                    <th width="130">Harga Satuan</th>
                                    <th width="100" id="tableCoefHeader">Koefisien</th>
                                    <th width="140">Total Harga</th>
                                    <th width="150">Catatan</th>
                                    <th width="50">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody">
                                <tr id="emptyRow">
                                    <td colspan="9" class="text-center text-muted py-4">
                                        <i class="mdi mdi-cart-outline" style="font-size: 2rem;"></i>
                                        <p class="mb-0 mt-2">Belum ada item. Pilih kategori & sub-kategori lalu klik "Tambah Item".</p>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="6" class="text-end"><strong>GRAND TOTAL</strong></td>
                                    <td class="text-end"><strong id="grandTotalDisplay">Rp 0</strong></td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Attachment Upload Card -->
            <div class="card mb-3">
                <div class="card-header bg-info text-white py-2">
                    <h6 class="mb-0"><i class="mdi mdi-paperclip"></i> Lampiran Nota</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label">Upload Nota/Bukti Transaksi</label>
                            <input type="file" class="form-control" id="attachmentInput" 
                                   accept=".jpg,.jpeg,.png,.pdf" multiple>
                            <small class="text-muted">Format: JPG, PNG, PDF. Maksimal 5MB per file.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">File yang akan diupload:</label>
                            <div id="attachmentList" class="border rounded p-2" style="min-height: 60px;">
                                <span class="text-muted small">Belum ada file dipilih</span>
                            </div>
                        </div>
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


<?php 
// Store script in $extraScripts to load AFTER jQuery in footer
ob_start();
?>
<script>
$(document).ready(function() {
    console.log('=== CREATE.PHP SCRIPT LOADED ===');
    
    const projectId = <?= $projectId ?>;
    console.log('Project ID:', projectId);
    
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
        // Reset item dropdown
        $('#itemSelect').html('<option value="">-- Pilih Jenis Item dahulu --</option>').prop('disabled', true);
        $('#itemPreviewBox').hide();
    }
    
    // Item Type change - load items from selected RAP pekerjaan
    $(document).on('change', '#itemTypeSelect', function() {
        const itemType = $(this).val();
        const selectedIds = getSelectedSubcategoryIds();
        
        console.log('Item Type selected:', itemType, 'Selected subcategories:', selectedIds);
        
        $('#itemSelect').html('<option value="">Memuat...</option>').prop('disabled', true);
        $('#itemPreviewBox').hide();
        
        if (!itemType || selectedIds.length === 0) {
            $('#itemSelect').html('<option value="">-- Pilih Jenis Item dahulu --</option>');
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
                        let options = '<option value="">-- Pilih Item --</option>';
                        res.data.forEach(function(item) {
                            const price = item.actual_price || item.unit_price;
                            const rapQty = parseFloat(item.rap_qty) || 0;
                            const usedQty = parseFloat(item.used_qty) || 0;
                            const sisaQty = rapQty - usedQty;
                            options += '<option value="' + item.id + '" ' +
                                    'data-code="' + (item.item_code || '') + '" ' +
                                    'data-name="' + escapeHtml(item.name) + '" ' +
                                    'data-unit="' + item.unit + '" ' +
                                    'data-price="' + price + '" ' +
                                    'data-coef="' + (item.coefficient || 1) + '" ' +
                                    'data-subcat-id="' + item.subcategory_id + '" ' +
                                    'data-subcat-code="' + item.subcat_code + '" ' +
                                    'data-rap-qty="' + rapQty + '" ' +
                                    'data-used-qty="' + usedQty + '" ' +
                                    'data-sisa-qty="' + sisaQty + '" ' +
                                    'data-item-type="' + item.item_type + '">' +
                                    '[' + item.subcat_code + '] ' +
                                    (item.item_code ? item.item_code + ' - ' : '') + item.name + 
                                    ' (' + item.unit + ')' +
                                    '</option>';
                        });
                        $('#itemSelect').html(options).prop('disabled', false);
                    } else {
                        $('#itemSelect').html('<option value="">Tidak ada item ' + itemType + '</option>');
                    }
                } else {
                    alert('Gagal memuat item: ' + res.message);
                }
            },
            error: function() {
                alert('Gagal memuat item dari server');
            }
        });
    });
    
    // Item select - show preview box
    $(document).on('change', '#itemSelect', function() {
        const selected = $(this).find(':selected');
        const itemId = $(this).val();
        
        if (!itemId) {
            $('#itemPreviewBox').hide();
            return;
        }
        
        const code = selected.data('code');
        const name = selected.data('name');
        const unit = selected.data('unit');
        const price = selected.data('price');
        const coef = selected.data('coef') || 1;
        const sisaQty = parseFloat(selected.data('sisa-qty')) || 0;
        const itemType = selected.data('item-type') || '';
        
        // Update koefisien label based on item type and show sisa
        const sisaText = sisaQty > 0 ? sisaQty.toLocaleString('id-ID', {maximumFractionDigits: 4}) : '0';
        if (itemType === 'upah') {
            const sisaOrang = Math.floor(sisaQty / 6);
            $('#previewCoefLabel').html('<small>Jml Orang <span class="text-info">(sisa: ' + sisaOrang + ')</span> <span class="text-danger">*</span></small>');
            $('#previewCoef').attr('placeholder', 'Jml Orang');
        } else {
            $('#previewCoefLabel').html('<small>Koefisien <span class="text-info">(sisa: ' + sisaText + ')</span> <span class="text-danger">*</span></small>');
            $('#previewCoef').attr('placeholder', '0');
        }
        
        $('#previewItemCode').text(code || '-');
        $('#previewItemName').text(name);
        $('#previewUnit').val(unit);
        $('#previewPrice').val('');
        $('#previewCoef').val('');
        
        // Store selected item data for adding (include subcategory info)
        const subcatId = selected.data('subcat-id');
        const subcatCode = selected.data('subcat-code');
        
        $('#itemPreviewBox').data('item', {
            code: code,
            name: name,
            unit: unit,
            defaultPrice: price,
            defaultCoef: coef,
            subcategory_id: subcatId,
            subcategory_code: subcatCode,
            item_type: itemType,
            sisa_qty: sisaQty
        });
        
        $('#itemPreviewBox').slideDown();
    });
    
    // Add to list button
    $('#addToListBtn').click(function() {
        const itemData = $('#itemPreviewBox').data('item');
        if (!itemData) return;
        
        const price = parseNumber($('#previewPrice').val());
        let inputVal = parseNumber($('#previewCoef').val());
        
        if (price <= 0 || inputVal <= 0) {
            showToast('Harga dan ' + (itemData.item_type === 'upah' ? 'jumlah orang' : 'koefisien') + ' harus lebih dari 0', 'error');
            return;
        }
        
        // For upah: input is jumlah orang, actual coefficient = jumlah_orang × 6 (hari kerja/minggu)
        let actualCoef = inputVal;
        let jumlahOrang = null;
        if (itemData.item_type === 'upah') {
            jumlahOrang = inputVal;
            actualCoef = inputVal * 6;
        }
        
        addItemRow({
            category_id: null, // Not used in new flow
            subcategory_id: itemData.subcategory_id,
            item_code: itemData.code,
            item_type: itemData.item_type || '',
            item_name: itemData.name,
            unit: itemData.unit,
            unit_price: price,
            coefficient: actualCoef,
            jumlah_orang: jumlahOrang,
            is_readonly: true
        });
        
        // Reset selection
        $('#itemSelect').val('');
        $('#itemPreviewBox').hide();
        // Reset label back to default
        $('#previewCoefLabel').html('<small>Koefisien <span class="text-danger">*</span></small>');
        $('#previewCoef').attr('placeholder', '0');
        
        showToast('Item berhasil ditambahkan', 'success');
    });
    
    // Auto-format preview price input
    $('#previewPrice').on('input', function() {
        autoFormatInput($(this));
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
        const isUpah = data.item_type === 'upah';
        
        // For upah: display jumlah orang, otherwise display coefficient
        let coefDisplay = '';
        let coefLabel = '';
        if (isUpah && data.jumlah_orang !== null) {
            coefDisplay = data.jumlah_orang.toString().replace('.', ',');
            coefLabel = '<small class="text-info d-block">× 6 hari = ' + data.coefficient + '</small>';
        } else {
            coefDisplay = data.coefficient > 0 ? data.coefficient.toString().replace('.', ',') : '';
        }
        
        const row = `
            <tr class="item-row" data-index="${itemIndex}" data-item-type="${data.item_type || ''}">
                <td class="text-center">${itemIndex}</td>
                <td>
                    <small class="text-muted">${data.item_code || '<em>Custom</em>'}</small>
                    <input type="hidden" name="items[${itemIndex}][category_id]" value="${data.category_id}">
                    <input type="hidden" name="items[${itemIndex}][subcategory_id]" value="${data.subcategory_id}">
                    <input type="hidden" name="items[${itemIndex}][item_code]" value="${data.item_code}">
                    <input type="hidden" name="items[${itemIndex}][item_type]" value="${data.item_type || ''}">
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
                    ${isUpah ? 
                        `<input type="text" name="items[${itemIndex}][coefficient]" 
                               value="${coefDisplay}" 
                               class="form-control form-control-sm text-end coef-input" 
                               data-is-upah="1" data-jumlah-orang="${data.jumlah_orang || ''}" 
                               placeholder="Jml Orang" required>
                         ${coefLabel}` 
                    : 
                        `<input type="text" name="items[${itemIndex}][coefficient]" 
                               value="${coefDisplay}" 
                               class="form-control form-control-sm text-end coef-input" 
                               placeholder="0" required>`
                    }
                </td>
                <td>
                    <input type="text" name="items[${itemIndex}][total_price]" 
                           value="${formatNumber(totalPrice)}" 
                           class="form-control form-control-sm text-end readonly-field total-price" readonly>
                </td>
                <td>
                    <input type="text" name="items[${itemIndex}][notes]" 
                           class="form-control form-control-sm" placeholder="Catatan">
                </td>
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
        const isUpah = $(this).data('is-upah') == 1;
        
        if (isUpah) {
            // For upah: input = jumlah orang, actual coef = jumlah_orang × 6
            const jumlahOrang = parseNumber(val);
            const actualCoef = jumlahOrang * 6;
            $(this).data('jumlah-orang', jumlahOrang);
            // Update the ×6 label
            $(this).siblings('small').remove();
            if (jumlahOrang > 0) {
                $(this).after('<small class="text-info d-block">× 6 hari = ' + actualCoef + '</small>');
            }
        }
        
        calculateRowTotal(row);
    });
    
    // =====================================
    // CALCULATE ROW TOTAL
    // =====================================
    function calculateRowTotal(row) {
        const price = parseNumber(row.find('.price-input').val());
        const coefInput = row.find('.coef-input');
        const isUpah = coefInput.data('is-upah') == 1;
        let coef = parseNumber(coefInput.val());
        
        // For upah: multiply by 6 to get actual coefficient
        if (isUpah) {
            coef = coef * 6;
        }
        
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
    $('#requestForm').on('submit', function(e) {
        e.preventDefault();
        
        const itemRows = $('#itemsBody tr.item-row');
        if (itemRows.length === 0) {
            showToast('Tambahkan minimal satu item!', 'error');
            return;
        }
        
        // Collect items data
        const items = [];
        let hasError = false;
        
        itemRows.each(function() {
            const row = $(this);
            const itemName = row.find('input[name*="[item_name]"]').val();
            const unit = row.find('input[name*="[unit]"]').val();
            const unitPrice = parseNumber(row.find('.price-input').val());
            const coefInput = row.find('.coef-input');
            const isUpah = coefInput.data('is-upah') == 1;
            let coefficient = parseNumber(coefInput.val());
            
            // For upah: actual coefficient = jumlah_orang × 6
            if (isUpah) {
                coefficient = coefficient * 6;
            }
            
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
                    notes: row.find('input[name*="[notes]"]').val()
                });
            }
        });
        
        if (hasError) {
            showToast('Lengkapi semua data item yang ditandai merah!', 'error');
            return;
        }
        
        // Disable submit button
        $('#submitBtn').prop('disabled', true).html('<i class="mdi mdi-loading mdi-spin"></i> Menyimpan...');
        
        // Build FormData for file uploads
        const formData = new FormData();
        formData.append('action', 'save_request');
        formData.append('project_id', projectId);
        formData.append('description', $('#description').val());
        formData.append('items', JSON.stringify(items));
        
        // Add attachments
        const attachmentInput = document.getElementById('attachmentInput');
        if (attachmentInput.files.length > 0) {
            for (let i = 0; i < attachmentInput.files.length; i++) {
                formData.append('attachments[]', attachmentInput.files[i]);
            }
        }
        
        // Send via AJAX with FormData
        $.ajax({
            url: 'create.php?project_id=' + projectId,
            method: 'POST',
            dataType: 'json',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                if (res.success) {
                    showToast(res.message, 'success');
                    setTimeout(function() {
                        window.location.href = res.redirect || 'index.php';
                    }, 1500);
                } else {
                    showToast(res.message, 'error');
                    $('#submitBtn').prop('disabled', false).html('<i class="mdi mdi-send"></i> Kirim Pengajuan');
                }
            },
            error: function() {
                showToast('Terjadi kesalahan server', 'error');
                $('#submitBtn').prop('disabled', false).html('<i class="mdi mdi-send"></i> Kirim Pengajuan');
            }
        });
    });
    
    // =====================================
    // ATTACHMENT HANDLING
    // =====================================
    $('#attachmentInput').change(function() {
        const files = this.files;
        const list = $('#attachmentList');
        
        if (files.length === 0) {
            list.html('<span class="text-muted small">Belum ada file dipilih</span>');
            return;
        }
        
        let html = '';
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            const size = (file.size / 1024).toFixed(1);
            const icon = file.type.includes('pdf') ? 'mdi-file-pdf-box text-danger' : 'mdi-image text-primary';
            
            html += `<div class="d-flex align-items-center mb-1">
                <i class="mdi ${icon} me-2"></i>
                <small>${file.name} (${size} KB)</small>
            </div>`;
        }
        list.html(html);
    });
    
    // =====================================
    // INITIALIZE - Load RAP Pekerjaan on page load
    // =====================================
    loadRapPekerjaan();
});
</script>
<?php 
$extraScripts = ob_get_clean();
?>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
