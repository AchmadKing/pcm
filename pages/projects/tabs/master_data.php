<?php
/**
 * Master Data Tab - Project Dashboard
 * Manages Items and AHSP for this project
 */

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isAdmin()) {
    $action = $_POST['action'] ?? '';
    
    try {
        // ========== ITEMS ==========
        if ($action === 'add_item') {
            $itemCode = trim($_POST['item_code'] ?? '');
            $name = trim($_POST['item_name']);
            $brand = trim($_POST['item_brand'] ?? '');
            $category = $_POST['item_category'];
            $unit = trim($_POST['item_unit']);
            $priceRaw = $_POST['item_price'];
            $price = floatval(str_replace(',', '.', str_replace('.', '', $priceRaw)));
            $actualPriceRaw = $_POST['item_actual_price'] ?? '';
            $actualPrice = !empty($actualPriceRaw) ? floatval(str_replace(',', '.', str_replace('.', '', $actualPriceRaw))) : null;
            
            if (!empty($itemCode) && !empty($name) && !empty($category) && !empty($unit)) {
                // Check for duplicate item_code
                $existing = dbGetRow("SELECT id FROM project_items WHERE project_id = ? AND item_code = ?", 
                    [$projectId, $itemCode]);
                if ($existing) {
                    setFlash('error', 'Kode item "' . $itemCode . '" sudah digunakan!');
                } else {
                    dbInsert("INSERT INTO project_items (project_id, item_code, name, brand, category, unit, price, actual_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        [$projectId, $itemCode, $name, $brand ?: null, $category, $unit, $price, $actualPrice]);
                    setFlash('success', 'Item berhasil ditambahkan!');
                }
            } else {
                setFlash('error', 'Kode item, nama, kategori, dan satuan wajib diisi!');
            }
        }
        
        if ($action === 'update_item') {
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
            
            dbExecute("UPDATE project_items SET item_code = ?, name = ?, brand = ?, category = ?, unit = ?, price = ?, actual_price = ? WHERE id = ? AND project_id = ?",
                [$itemCode, $name, $brand ?: null, $category, $unit, $price, $actualPrice, $itemId, $projectId]);
            
            // Sync price changes to all AHSP that use this item (cascades to RAB/RAP)
            syncItemToAhsp($itemId, $projectId);
            
            setFlash('success', 'Item berhasil diperbarui! Perubahan telah disinkronkan ke AHSP dan RAB.');
        }
        
        // AJAX version - update item without reload
        if ($action === 'update_item_ajax') {
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
            
            // Check for duplicate item_code (exclude current item)
            if (!empty($itemCode)) {
                $existing = dbGetRow("SELECT id FROM project_items WHERE project_id = ? AND item_code = ? AND id != ?", 
                    [$projectId, $itemCode, $itemId]);
                if ($existing) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Kode item "' . $itemCode . '" sudah digunakan!']);
                    exit;
                }
            }
            
            dbExecute("UPDATE project_items SET item_code = ?, name = ?, brand = ?, category = ?, unit = ?, price = ?, actual_price = ? WHERE id = ? AND project_id = ?",
                [$itemCode, $name, $brand ?: null, $category, $unit, $price, $actualPrice, $itemId, $projectId]);
            
            // Sync price changes to all AHSP that use this item
            syncItemToAhsp($itemId, $projectId);
            
            // Return JSON response
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true, 
                'message' => 'Item berhasil disimpan!',
                'item' => [
                    'id' => $itemId,
                    'item_code' => $itemCode,
                    'name' => $name,
                    'brand' => $brand,
                    'category' => $category,
                    'unit' => $unit,
                    'price' => $price,
                    'actual_price' => $actualPrice
                ]
            ]);
            exit;
        }
        
        if ($action === 'delete_item') {
            $itemId = $_POST['item_id'];
            dbExecute("DELETE FROM project_items WHERE id = ? AND project_id = ?", [$itemId, $projectId]);
            setFlash('success', 'Item berhasil dihapus!');
        }
        
        if ($action === 'clear_all_items') {
            // Delete all AHSP details for this project first (foreign key)
            dbExecute("
                DELETE d FROM project_ahsp_details d
                INNER JOIN project_items i ON d.item_id = i.id
                WHERE i.project_id = ?
            ", [$projectId]);
            
            // Delete all items
            $deletedCount = dbExecute("DELETE FROM project_items WHERE project_id = ?", [$projectId]);
            
            // Recalculate all AHSP prices (set to 0 since no items)
            dbExecute("UPDATE project_ahsp SET unit_price = 0 WHERE project_id = ?", [$projectId]);
            
            setFlash('success', "Semua items berhasil dihapus!");
        }
        
        if ($action === 'clear_all_ahsp') {
            // Delete in correct order due to foreign key constraints:
            // 1. rap_ahsp_details -> rap_items -> rab_subcategories -> project_ahsp
            
            // 1. Delete rap_ahsp_details that reference rap_items for subcategories using these AHSP
            dbExecute("
                DELETE rad FROM rap_ahsp_details rad
                INNER JOIN rap_items rap ON rad.rap_item_id = rap.id
                INNER JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
                INNER JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                WHERE pa.project_id = ?
            ", [$projectId]);
            
            // 2. Delete rap_items that reference rab_subcategories using these AHSP
            dbExecute("
                DELETE rap FROM rap_items rap
                INNER JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
                INNER JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                WHERE pa.project_id = ?
            ", [$projectId]);
            
            // 3. Delete rab_subcategories that reference these AHSP
            dbExecute("
                DELETE rs FROM rab_subcategories rs
                INNER JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                WHERE pa.project_id = ?
            ", [$projectId]);
            
            // 4. Delete all AHSP details for this project
            dbExecute("
                DELETE d FROM project_ahsp_details d
                INNER JOIN project_ahsp a ON d.ahsp_id = a.id
                WHERE a.project_id = ?
            ", [$projectId]);
            
            // 5. Delete all AHSP (now safe - no more references)
            $deletedCount = dbExecute("DELETE FROM project_ahsp WHERE project_id = ?", [$projectId]);
            
            setFlash('success', "Semua AHSP berhasil dihapus!");
        }
        
        // ========== AHSP ==========
        if ($action === 'add_ahsp') {
            $ahspCode = trim($_POST['ahsp_code'] ?? '');
            $workName = trim($_POST['work_name']);
            $unit = trim($_POST['ahsp_unit']);
            
            if (!empty($ahspCode) && !empty($workName) && !empty($unit)) {
                // Check for duplicate ahsp_code
                $existing = dbGetRow("SELECT id FROM project_ahsp WHERE project_id = ? AND ahsp_code = ?", 
                    [$projectId, $ahspCode]);
                if ($existing) {
                    setFlash('error', 'Kode AHSP "' . $ahspCode . '" sudah digunakan!');
                } else {
                    $newAhspId = dbInsert("INSERT INTO project_ahsp (project_id, ahsp_code, work_name, unit, unit_price) VALUES (?, ?, ?, ?, 0)",
                        [$projectId, $ahspCode, $workName, $unit]);
                    setFlash('success', 'AHSP berhasil ditambahkan!');
                }
            } else {
                setFlash('error', 'Kode AHSP, nama pekerjaan, dan satuan harus diisi!');
            }
        }
        
        if ($action === 'update_ahsp') {
            $ahspId = $_POST['ahsp_id'];
            $ahspCode = trim($_POST['ahsp_code'] ?? '');
            $workName = trim($_POST['work_name']);
            $unit = trim($_POST['ahsp_unit']);
            
            // Check for duplicate ahsp_code (exclude current)
            if (!empty($ahspCode)) {
                $existing = dbGetRow("SELECT id FROM project_ahsp WHERE project_id = ? AND ahsp_code = ? AND id != ?", 
                    [$projectId, $ahspCode, $ahspId]);
                if ($existing) {
                    setFlash('error', 'Kode AHSP "' . $ahspCode . '" sudah digunakan!');
                    header('Location: ' . $_SERVER['REQUEST_URI']);
                    exit;
                }
            }
            
            dbExecute("UPDATE project_ahsp SET ahsp_code = ?, work_name = ?, unit = ? WHERE id = ? AND project_id = ?",
                [$ahspCode, $workName, $unit, $ahspId, $projectId]);
            
            // Sync name/unit changes to RAB subcategories
            syncAhspToRab($ahspId);
            
            setFlash('success', 'AHSP berhasil diperbarui! Perubahan telah disinkronkan ke RAB.');
        }
        
        if ($action === 'delete_ahsp') {
            $ahspId = $_POST['ahsp_id'];
            
            // Delete in correct order due to foreign key constraints
            // 1. Delete rap_ahsp_details for subcategories using this AHSP
            dbExecute("
                DELETE rad FROM rap_ahsp_details rad
                INNER JOIN rap_items rap ON rad.rap_item_id = rap.id
                INNER JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
                WHERE rs.ahsp_id = ?
            ", [$ahspId]);
            
            // 2. Delete rap_items for subcategories using this AHSP
            dbExecute("
                DELETE rap FROM rap_items rap
                INNER JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
                WHERE rs.ahsp_id = ?
            ", [$ahspId]);
            
            // 3. Delete rab_subcategories that reference this AHSP
            dbExecute("DELETE FROM rab_subcategories WHERE ahsp_id = ?", [$ahspId]);
            
            // 4. Delete AHSP details
            dbExecute("DELETE FROM project_ahsp_details WHERE ahsp_id = ?", [$ahspId]);
            
            // 5. Delete AHSP (now safe)
            dbExecute("DELETE FROM project_ahsp WHERE id = ? AND project_id = ?", [$ahspId, $projectId]);
            setFlash('success', 'AHSP berhasil dihapus!');
        }
        
        // ========== AHSP DETAILS ==========
        if ($action === 'add_ahsp_detail') {
            $ahspId = $_POST['ahsp_id'];
            $itemId = $_POST['detail_item_id'];
            $coeffRaw = $_POST['coefficient'];
            $coefficient = floatval(str_replace(',', '.', str_replace('.', '', $coeffRaw)));
            
            // Debug: Check if AHSP exists
            $ahspExists = dbGetRow("SELECT id FROM project_ahsp WHERE id = ?", [$ahspId]);
            if (!$ahspExists) {
                throw new Exception("AHSP ID $ahspId tidak ditemukan di database!");
            }
            
            if (!empty($itemId) && $coefficient > 0) {
                dbInsert("INSERT INTO project_ahsp_details (ahsp_id, item_id, coefficient) VALUES (?, ?, ?)",
                    [$ahspId, $itemId, $coefficient]);
                    
                // Recalculate AHSP unit_price
                recalculateAhspPrice($ahspId);
                setFlash('success', 'Komponen berhasil ditambahkan!');
            }
        }
        
        if ($action === 'update_ahsp_detail') {
            $detailId = $_POST['detail_id'];
            $ahspId = $_POST['ahsp_id'];
            $coeffRaw = $_POST['coefficient'] ?? '';
            
            // Parse coefficient (uses Indonesian format with dots as thousands separator)
            $coefficient = floatval(str_replace(',', '.', str_replace('.', '', $coeffRaw)));
            
            // Parse unit_price - check if it's Indonesian formatted or raw number
            $unitPriceRaw = $_POST['unit_price'] ?? '';
            if (!empty($unitPriceRaw)) {
                // If contains both dots and commas, it's Indonesian format
                if (strpos($unitPriceRaw, '.') !== false && strpos($unitPriceRaw, ',') !== false) {
                    $unitPrice = floatval(str_replace(',', '.', str_replace('.', '', $unitPriceRaw)));
                } elseif (strpos($unitPriceRaw, '.') !== false && strlen($unitPriceRaw) - strrpos($unitPriceRaw, '.') == 4) {
                    // Has dot as thousands separator (e.g., 150.000)
                    $unitPrice = floatval(str_replace('.', '', $unitPriceRaw));
                } else {
                    // Raw number or already decimal format
                    $unitPrice = floatval(str_replace(',', '.', $unitPriceRaw));
                }
            } else {
                $unitPrice = null;
            }
            
            // Only require coefficient > 0 if coefficient is being updated
            if (empty($coeffRaw) || $coefficient > 0) {
                // If coefficient is empty, use current value
                if (empty($coeffRaw)) {
                    $current = dbGetRow("SELECT coefficient FROM project_ahsp_details WHERE id = ?", [$detailId]);
                    $coefficient = $current['coefficient'] ?? 1;
                }
                
                dbExecute("UPDATE project_ahsp_details SET coefficient = ?, unit_price = ? WHERE id = ?",
                    [$coefficient, $unitPrice, $detailId]);
                recalculateAhspPrice($ahspId);
                setFlash('success', 'Komponen berhasil diperbarui!');
            }
        }
        
        if ($action === 'delete_ahsp_detail') {
            $detailId = $_POST['detail_id'];
            $ahspId = $_POST['ahsp_id'];
            dbExecute("DELETE FROM project_ahsp_details WHERE id = ?", [$detailId]);
            recalculateAhspPrice($ahspId);
            setFlash('success', 'Komponen berhasil dihapus!');
        }
    } catch (Exception $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
    }
    
    // Use JavaScript redirect - stay on AHSP subtab for AHSP-related actions
    $subtab = (strpos($action, 'ahsp') !== false) ? '&subtab=ahsp' : '';
    echo '<script>window.location.href = "view.php?id=' . $projectId . '&tab=master' . $subtab . '";</script>';
    exit;
}

// Helper function to recalculate AHSP price
function recalculateAhspPrice($ahspId) {
    $total = dbGetRow("
        SELECT COALESCE(SUM(d.coefficient * COALESCE(d.unit_price, i.price)), 0) as total
        FROM project_ahsp_details d
        JOIN project_items i ON d.item_id = i.id
        WHERE d.ahsp_id = ?
    ", [$ahspId])['total'];
    
    dbExecute("UPDATE project_ahsp SET unit_price = ? WHERE id = ?", [$total, $ahspId]);
    
    // Sync to RAB subcategories that use this AHSP
    syncAhspToRab($ahspId);
}

// Sync AHSP changes to RAB subcategories
function syncAhspToRab($ahspId) {
    // Get updated AHSP data
    $ahsp = dbGetRow("SELECT work_name, unit, unit_price FROM project_ahsp WHERE id = ?", [$ahspId]);
    if (!$ahsp) return;
    
    // Update all RAB subcategories that reference this AHSP
    dbExecute("
        UPDATE rab_subcategories 
        SET name = ?, unit = ?, unit_price = ?
        WHERE ahsp_id = ?
    ", [$ahsp['work_name'], $ahsp['unit'], $ahsp['unit_price'], $ahspId]);
    
    // Also sync to RAP (unit_price only, keep volume as user may have customized it)
    dbExecute("
        UPDATE rap_items rap
        JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
        SET rap.unit_price = rs.unit_price
        WHERE rs.ahsp_id = ? AND rap.is_locked = 0
    ", [$ahspId]);
}

// Sync Item changes to all AHSP that use this item
function syncItemToAhsp($itemId, $projectId) {
    // Find all AHSP that use this item via project_ahsp_details
    $affectedAhsp = dbGetAll("
        SELECT DISTINCT d.ahsp_id 
        FROM project_ahsp_details d
        JOIN project_ahsp pa ON d.ahsp_id = pa.id
        WHERE d.item_id = ? AND pa.project_id = ?
    ", [$itemId, $projectId]);
    
    // Recalculate each affected AHSP (which will cascade to RAB)
    foreach ($affectedAhsp as $row) {
        recalculateAhspPrice($row['ahsp_id']);
    }
}

// Get data
$items = dbGetAll("SELECT * FROM project_items WHERE project_id = ? ORDER BY category, name", [$projectId]);

// AHSP sorting
$ahspSort = $_GET['ahsp_sort'] ?? 'name';
$ahspSortOrder = 'ASC';
switch ($ahspSort) {
    case 'code':
        $ahspOrderBy = 'ahsp_code';
        break;
    case 'price_asc':
        $ahspOrderBy = 'unit_price';
        $ahspSortOrder = 'ASC';
        break;
    case 'price_desc':
        $ahspOrderBy = 'unit_price';
        $ahspSortOrder = 'DESC';
        break;
    case 'name':
    default:
        $ahspOrderBy = 'work_name';
        break;
}
$ahspList = dbGetAll("SELECT * FROM project_ahsp WHERE project_id = ? ORDER BY $ahspOrderBy $ahspSortOrder", [$projectId]);

// Get overhead percentage for price display
$overheadPct = $project['overhead_percentage'] ?? 10;

// Group items by category
$itemsByCategory = ['upah' => [], 'material' => [], 'alat' => []];
foreach ($items as $item) {
    $itemsByCategory[$item['category']][] = $item;
}

// Master Data is editable only when project is in draft status
$isEditable = ($project['status'] === 'draft');

// Check which sub-tab should be active
$activeSubtab = $_GET['subtab'] ?? 'items';

// Check if specific AHSP should be opened (from ahsp.php edit button)
$targetAhspId = $_GET['ahsp_id'] ?? null;
?>

<!-- Sub-tabs for Items and AHSP -->
<ul class="nav nav-pills mb-3" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?= $activeSubtab == 'items' ? 'active' : '' ?>" data-bs-toggle="pill" href="#items-tab">
            <i class="mdi mdi-package-variant"></i> Items
            <span class="badge bg-secondary"><?= count($items) ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeSubtab == 'ahsp' ? 'active' : '' ?>" data-bs-toggle="pill" href="#ahsp-tab">
            <i class="mdi mdi-file-table-outline"></i> AHSP
            <span class="badge bg-secondary"><?= count($ahspList) ?></span>
        </a>
    </li>
</ul>

<div class="tab-content">
    <!-- ITEMS TAB -->
    <div class="tab-pane fade <?= $activeSubtab == 'items' ? 'show active' : '' ?>" id="items-tab">
        <div class="mb-3 d-flex gap-2 flex-wrap justify-content-between">
            <div class="d-flex gap-2 flex-wrap">
                <?php if ($isEditable): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addItemModal">
                    <i class="mdi mdi-plus"></i> Tambah Item
                </button>
                <a href="import.php?project_id=<?= $projectId ?>&type=items" class="btn btn-success">
                    <i class="mdi mdi-file-upload"></i> Import dari CSV
                </a>
                <a href="export_items.php?project_id=<?= $projectId ?>" class="btn btn-info">
                    <i class="mdi mdi-file-download"></i> Export CSV
                </a>
                <a href="../../templates/template_items.csv" class="btn btn-outline-secondary" download>
                    <i class="mdi mdi-download"></i> Download Template
                </a>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <?php if ($isEditable): ?>
                <!-- Edit Mode Toggle -->
                <div class="form-check form-switch me-2">
                    <input class="form-check-input" type="checkbox" role="switch" id="editModeToggle" style="cursor: pointer;">
                    <label class="form-check-label small text-muted" for="editModeToggle" style="cursor: pointer;">Mode Edit</label>
                </div>
                <button type="button" class="btn btn-outline-danger edit-mode-only d-none" onclick="confirmClearItems()">
                    <i class="mdi mdi-delete-sweep"></i> Hapus Semua
                </button>
                <?php endif; ?>
                <!-- Category Filter -->
                <div class="btn-group" role="group" id="categoryFilter">
                    <button type="button" class="btn btn-outline-secondary active" data-filter="all">
                        <i class="mdi mdi-view-list"></i> Semua
                    </button>
                    <button type="button" class="btn btn-outline-primary" data-filter="upah">
                        <i class="mdi mdi-account-hard-hat"></i> Upah
                    </button>
                    <button type="button" class="btn btn-outline-success" data-filter="material">
                        <i class="mdi mdi-cube-outline"></i> Material
                    </button>
                    <button type="button" class="btn btn-outline-warning" data-filter="alat">
                        <i class="mdi mdi-tools"></i> Alat
                    </button>
                </div>
                <div class="input-group" style="width: 200px;">
                    <span class="input-group-text"><i class="mdi mdi-magnify"></i></span>
                    <input type="text" class="form-control" id="searchItems" placeholder="Cari item...">
                </div>
            </div>
        </div>
        
        <?php if (empty($items)): ?>
        <div class="text-center py-4">
            <i class="mdi mdi-package-variant-closed display-4 text-muted"></i>
            <h5 class="mt-3">Belum ada item</h5>
            <p class="text-muted">Tambahkan item (upah, material, alat) untuk proyek ini.</p>
        </div>
        <?php else: ?>
        
        <!-- Upah Section -->
        <?php if (!empty($itemsByCategory['upah'])): ?>
        <h6 class="text-primary item-section"><i class="mdi mdi-account-hard-hat"></i> Upah</h6>
        <div class="table-responsive mb-4">
            <table class="table table-sm table-bordered item-table" data-category="upah">
                <thead class="table-light">
                    <tr>
                        <th width="100" class="sortable-header" data-sort="code" style="cursor:pointer;">
                            Kode <i class="mdi mdi-sort sort-icon"></i>
                        </th>
                        <th class="sortable-header" data-sort="name" style="cursor:pointer;">
                            Nama <i class="mdi mdi-sort sort-icon"></i>
                        </th>
                        <th width="100">Merk</th>
                        <th width="80" class="sortable-header" data-sort="unit" style="cursor:pointer;">
                            Satuan <i class="mdi mdi-sort sort-icon"></i>
                        </th>
                        <th width="130" class="text-end sortable-header" data-sort="price" style="cursor:pointer;">
                            Harga PU <i class="mdi mdi-sort sort-icon"></i>
                        </th>
                        <th width="130" class="text-end">Harga Aktual</th>
                        <?php if ($isEditable): ?><th width="50">Aksi</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($itemsByCategory['upah'] as $item): ?>
                    <tr class="item-row" data-item-id="<?= $item['id'] ?>" data-category="upah">
                        <td><input type="text" class="form-control form-control-sm border-0 item-code" name="item_code" value="<?= sanitize($item['item_code'] ?? '') ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                        <td><input type="text" class="form-control form-control-sm border-0 item-name" name="item_name" value="<?= sanitize($item['name']) ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                        <td><input type="text" class="form-control form-control-sm border-0 item-brand" name="item_brand" value="<?= sanitize($item['brand'] ?? '') ?>" placeholder="-" <?= !$isEditable ? 'disabled' : '' ?>></td>
                        <td><input type="text" class="form-control form-control-sm border-0 item-unit" name="item_unit" value="<?= sanitize($item['unit']) ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                        <td><input type="text" class="form-control form-control-sm border-0 text-end format-rupiah item-price" name="item_price" value="<?= formatNumber($item['price']) ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                        <td><input type="text" class="form-control form-control-sm border-0 text-end format-rupiah item-actual-price" name="item_actual_price" value="<?= $item['actual_price'] ? formatNumber($item['actual_price']) : '' ?>" placeholder="-" <?= !$isEditable ? 'disabled' : '' ?>></td>
                        <?php if ($isEditable): ?>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-danger edit-mode-only d-none" onclick="deleteItem(<?= $item['id'] ?>)"><i class="mdi mdi-delete"></i></button>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- Material Section -->
        <?php if (!empty($itemsByCategory['material'])): ?>
        <h6 class="text-success item-section"><i class="mdi mdi-cube-outline"></i> Material</h6>
        <div class="table-responsive mb-4">
            <table class="table table-sm table-bordered item-table" data-category="material">
                <thead class="table-light">
                    <tr>
                        <th width="100" class="sortable-header" data-sort="code" style="cursor:pointer;">
                            Kode <i class="mdi mdi-sort sort-icon"></i>
                        </th>
                        <th class="sortable-header" data-sort="name" style="cursor:pointer;">
                            Nama <i class="mdi mdi-sort sort-icon"></i>
                        </th>
                        <th width="100">Merk</th>
                        <th width="80" class="sortable-header" data-sort="unit" style="cursor:pointer;">
                            Satuan <i class="mdi mdi-sort sort-icon"></i>
                        </th>
                        <th width="130" class="text-end sortable-header" data-sort="price" style="cursor:pointer;">
                            Harga PU <i class="mdi mdi-sort sort-icon"></i>
                        </th>
                        <th width="130" class="text-end">Harga Aktual</th>
                        <?php if ($isEditable): ?><th width="50">Aksi</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($itemsByCategory['material'] as $item): ?>
                    <tr class="item-row" data-item-id="<?= $item['id'] ?>" data-category="material">
                        <td><input type="text" class="form-control form-control-sm border-0 item-code" name="item_code" value="<?= sanitize($item['item_code'] ?? '') ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                        <td><input type="text" class="form-control form-control-sm border-0 item-name" name="item_name" value="<?= sanitize($item['name']) ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                        <td><input type="text" class="form-control form-control-sm border-0 item-brand" name="item_brand" value="<?= sanitize($item['brand'] ?? '') ?>" placeholder="-" <?= !$isEditable ? 'disabled' : '' ?>></td>
                        <td><input type="text" class="form-control form-control-sm border-0 item-unit" name="item_unit" value="<?= sanitize($item['unit']) ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                        <td><input type="text" class="form-control form-control-sm border-0 text-end format-rupiah item-price" name="item_price" value="<?= formatNumber($item['price']) ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                        <td><input type="text" class="form-control form-control-sm border-0 text-end format-rupiah item-actual-price" name="item_actual_price" value="<?= $item['actual_price'] ? formatNumber($item['actual_price']) : '' ?>" placeholder="-" <?= !$isEditable ? 'disabled' : '' ?>></td>
                        <?php if ($isEditable): ?>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-danger edit-mode-only d-none" onclick="deleteItem(<?= $item['id'] ?>)"><i class="mdi mdi-delete"></i></button>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- Alat Section -->
        <?php if (!empty($itemsByCategory['alat'])): ?>
        <h6 class="text-warning item-section"><i class="mdi mdi-tools"></i> Alat</h6>
        <div class="table-responsive">
            <table class="table table-sm table-bordered item-table" data-category="alat">
                <thead class="table-light">
                    <tr>
                        <th width="100" class="sortable-header" data-sort="code" style="cursor:pointer;">
                            Kode <i class="mdi mdi-sort sort-icon"></i>
                        </th>
                        <th class="sortable-header" data-sort="name" style="cursor:pointer;">
                            Nama <i class="mdi mdi-sort sort-icon"></i>
                        </th>
                        <th width="100">Merk</th>
                        <th width="80" class="sortable-header" data-sort="unit" style="cursor:pointer;">
                            Satuan <i class="mdi mdi-sort sort-icon"></i>
                        </th>
                        <th width="130" class="text-end sortable-header" data-sort="price" style="cursor:pointer;">
                            Harga PU <i class="mdi mdi-sort sort-icon"></i>
                        </th>
                        <th width="130" class="text-end">Harga Aktual</th>
                        <?php if ($isEditable): ?><th width="50">Aksi</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($itemsByCategory['alat'] as $item): ?>
                    <tr class="item-row" data-item-id="<?= $item['id'] ?>" data-category="alat">
                        <td><input type="text" class="form-control form-control-sm border-0 item-code" name="item_code" value="<?= sanitize($item['item_code'] ?? '') ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                        <td><input type="text" class="form-control form-control-sm border-0 item-name" name="item_name" value="<?= sanitize($item['name']) ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                        <td><input type="text" class="form-control form-control-sm border-0 item-brand" name="item_brand" value="<?= sanitize($item['brand'] ?? '') ?>" placeholder="-" <?= !$isEditable ? 'disabled' : '' ?>></td>
                        <td><input type="text" class="form-control form-control-sm border-0 item-unit" name="item_unit" value="<?= sanitize($item['unit']) ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                        <td><input type="text" class="form-control form-control-sm border-0 text-end format-rupiah item-price" name="item_price" value="<?= formatNumber($item['price']) ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                        <td><input type="text" class="form-control form-control-sm border-0 text-end format-rupiah item-actual-price" name="item_actual_price" value="<?= $item['actual_price'] ? formatNumber($item['actual_price']) : '' ?>" placeholder="-" <?= !$isEditable ? 'disabled' : '' ?>></td>
                        <?php if ($isEditable): ?>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-danger edit-mode-only d-none" onclick="deleteItem(<?= $item['id'] ?>)"><i class="mdi mdi-delete"></i></button>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <?php endif; ?>
    </div>
    
    <!-- AHSP TAB -->
    <div class="tab-pane fade <?= $activeSubtab == 'ahsp' ? 'show active' : '' ?>" id="ahsp-tab">
        <div class="mb-3 d-flex gap-2 flex-wrap justify-content-between">
            <div class="d-flex gap-2 flex-wrap">
                <?php if ($isEditable): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAhspModal">
                    <i class="mdi mdi-plus"></i> Tambah AHSP
                </button>
                <a href="import.php?project_id=<?= $projectId ?>&type=ahsp" class="btn btn-success">
                    <i class="mdi mdi-file-upload"></i> Import dari CSV
                </a>
                <a href="export_ahsp.php?project_id=<?= $projectId ?>" class="btn btn-info">
                    <i class="mdi mdi-file-download"></i> Export CSV
                </a>
                <a href="../../templates/template_ahsp.csv" class="btn btn-outline-secondary" download>
                    <i class="mdi mdi-download"></i> Download Template
                </a>
                <?php endif; ?>
            </div>
                <div class="d-flex gap-2 align-items-center">
                <?php if ($isEditable): ?>
                <!-- Edit Mode Toggle -->
                <div class="form-check form-switch me-2">
                    <input class="form-check-input" type="checkbox" role="switch" id="editModeToggleAhsp" style="cursor: pointer;">
                    <label class="form-check-label small text-muted" for="editModeToggleAhsp" style="cursor: pointer;">Mode Edit</label>
                </div>
                <button type="button" class="btn btn-outline-danger edit-mode-only d-none" onclick="confirmClearAhsp()">
                    <i class="mdi mdi-delete-sweep"></i> Hapus Semua
                </button>
                <?php endif; ?>
                <!-- Sort Dropdown -->
                <div class="input-group" style="width: 160px;">
                    <span class="input-group-text"><i class="mdi mdi-sort"></i></span>
                    <select class="form-select form-select-sm" id="sortAhsp" onchange="sortAhsp(this.value)">
                        <option value="name" <?= $ahspSort == 'name' ? 'selected' : '' ?>>Nama</option>
                        <option value="code" <?= $ahspSort == 'code' ? 'selected' : '' ?>>Kode</option>
                        <option value="price_asc" <?= $ahspSort == 'price_asc' ? 'selected' : '' ?>>Harga ↑</option>
                        <option value="price_desc" <?= $ahspSort == 'price_desc' ? 'selected' : '' ?>>Harga ↓</option>
                    </select>
                </div>
                <div class="input-group" style="width: 250px;">
                    <span class="input-group-text"><i class="mdi mdi-magnify"></i></span>
                    <input type="text" class="form-control" id="searchAhsp" placeholder="Cari AHSP...">
                </div>
            </div>
        </div>
        <?php if ($isEditable): ?>
        <div class="alert alert-info small mb-3">
            <i class="mdi mdi-information"></i>
            <strong>Tips Import AHSP:</strong> Pastikan Items sudah diimport terlebih dahulu. 
            Sistem akan otomatis mencocokkan nama item dan mengambil satuan serta harga dari Master Data Items.
        </div>
        <?php endif; ?>
        
        <?php if (empty($ahspList)): ?>
        <div class="text-center py-4">
            <i class="mdi mdi-file-table-outline display-4 text-muted"></i>
            <h5 class="mt-3">Belum ada AHSP</h5>
            <p class="text-muted">Buat template AHSP (Analisa Harga Satuan Pekerjaan) untuk digunakan di RAB.</p>
        </div>
        <?php else: ?>
        
        <div class="accordion" id="ahspAccordion">
            <?php foreach ($ahspList as $idx => $ahsp): 
                // Get details for this AHSP
                $details = dbGetAll("
                    SELECT d.*, i.name as item_name, i.category, i.unit, 
                           i.price as item_up_price, i.actual_price as item_actual_price,
                           COALESCE(d.unit_price, i.price) as effective_price,
                           (d.coefficient * COALESCE(d.unit_price, i.price)) as total_price
                    FROM project_ahsp_details d
                    JOIN project_items i ON d.item_id = i.id
                    WHERE d.ahsp_id = ?
                    ORDER BY i.category, i.name
                ", [$ahsp['id']]);
                
                // Check if this AHSP should be opened (from URL param or first item)
                $isTargetAhsp = ($targetAhspId && $ahsp['id'] == $targetAhspId);
                $shouldBeOpen = $isTargetAhsp || ($idx == 0 && !$targetAhspId);
            ?>
            <div class="accordion-item <?= $isTargetAhsp ? 'border-primary border-2 target-ahsp' : '' ?>">
                <h2 class="accordion-header">
                    <button class="accordion-button <?= !$shouldBeOpen ? 'collapsed' : '' ?>" type="button" 
                            data-bs-toggle="collapse" data-bs-target="#ahsp-<?= $ahsp['id'] ?>">
                        <div class="d-flex justify-content-between w-100 me-3">
                            <span><code class="me-2 fs-5"><?= sanitize($ahsp['ahsp_code'] ?? '') ?></code> <strong><?= sanitize($ahsp['work_name']) ?></strong> (<?= sanitize($ahsp['unit']) ?>)</span>
                            <?php 
                                // Calculate price with overhead (D+E)
                                $priceWithOverhead = $ahsp['unit_price'] * (1 + ($overheadPct / 100));
                            ?>
                            <span class="badge bg-primary" 
                                  data-bs-toggle="tooltip" 
                                  title="Harga Satuan (D+E) dengan Overhead <?= $overheadPct ?>%">
                                <?= formatRupiah($priceWithOverhead) ?>
                            </span>
                        </div>
                    </button>
                </h2>
                <div id="ahsp-<?= $ahsp['id'] ?>" class="accordion-collapse collapse <?= $shouldBeOpen ? 'show' : '' ?>">
                    <div class="accordion-body">
                        <?php if ($isEditable): ?>
                        <div class="mb-2">
                            <button class="btn btn-sm btn-success edit-mode-only d-none" onclick="openAddDetail(<?= $ahsp['id'] ?>)">
                                <i class="mdi mdi-plus"></i> Tambah Komponen
                            </button>
                            <button type="button" class="btn btn-sm btn-warning ahsp-edit-btn edit-mode-only d-none" onclick="editAhsp(<?= $ahsp['id'] ?>, '<?= addslashes($ahsp['ahsp_code'] ?? '') ?>', '<?= addslashes($ahsp['work_name']) ?>', '<?= addslashes($ahsp['unit']) ?>')">
                                <i class="mdi mdi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-danger ahsp-delete-btn edit-mode-only d-none" onclick="deleteAhsp(<?= $ahsp['id'] ?>)">
                                <i class="mdi mdi-delete"></i>
                            </button>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (empty($details)): ?>
                        <p class="text-muted">Belum ada komponen. Tambahkan upah, material, atau alat.</p>
                        <?php else: 
                            // Group details by category
                            $detailsByCategory = ['upah' => [], 'material' => [], 'alat' => []];
                            $totalByCategory = ['upah' => 0, 'material' => 0, 'alat' => 0];
                            foreach ($details as $detail) {
                                $detailsByCategory[$detail['category']][] = $detail;
                                $totalByCategory[$detail['category']] += $detail['total_price'];
                            }
                            $subtotal = array_sum($totalByCategory);
                            $overheadPct = $project['overhead_percentage'] ?? 10;
                            $overheadAmount = $subtotal * ($overheadPct / 100);
                            $grandTotal = $subtotal + $overheadAmount;
                        ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0">
                                <thead>
                                    <tr class="table-primary">
                                        <th width="40">No</th>
                                        <th>Uraian</th>
                                        <th width="80">Satuan</th>
                                        <th width="100" class="text-end">Koefisien</th>
                                        <th width="120" class="text-end">Harga Satuan</th>
                                        <th width="130" class="text-end">Jumlah Harga</th>
                                        <?php if ($isEditable): ?><th width="50">Aksi</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- A. TENAGA -->
                                    <tr style="background-color: #e8f4fd;">
                                        <td colspan="<?= $isEditable ? 7 : 6 ?>"><strong>A. TENAGA</strong></td>
                                    </tr>
                                    <?php if (empty($detailsByCategory['upah'])): ?>
                                    <tr><td colspan="<?= $isEditable ? 7 : 6 ?>" class="text-center text-muted">Belum ada komponen tenaga</td></tr>
                                    <?php else: ?>
                                    <?php $no = 1; foreach ($detailsByCategory['upah'] as $detail): ?>
                                    <form method="POST" class="inline-edit-form">
                                        <input type="hidden" name="action" value="update_ahsp_detail">
                                        <input type="hidden" name="detail_id" value="<?= $detail['id'] ?>">
                                        <input type="hidden" name="ahsp_id" value="<?= $ahsp['id'] ?>">
                                        <tr>
                                            <td class="text-center"><?= $no++ ?></td>
                                            <td><?= sanitize($detail['item_name']) ?></td>
                                            <td><?= sanitize($detail['unit']) ?></td>
                                            <td><input type="text" class="form-control form-control-sm border-0 text-end" name="coefficient" value="<?= formatNumber($detail['coefficient'], 4) ?>" style="width:80px;" <?= !$isEditable ? 'disabled' : '' ?>></td>
                                            <td class="text-end"><?= formatRupiah($detail['item_up_price']) ?></td>
                                            <td class="text-end"><?= formatRupiah($detail['total_price']) ?></td>
                                            <?php if ($isEditable): ?>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-danger detail-delete-btn edit-mode-only d-none" onclick="deleteAhspDetail(<?= $detail['id'] ?>, <?= $ahsp['id'] ?>)"><i class="mdi mdi-delete"></i></button>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    </form>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                    <tr style="background-color: #d4edfc;">
                                        <td colspan="5" class="text-end"><strong>JUMLAH TENAGA</strong></td>
                                        <td class="text-end"><strong><?= formatRupiah($totalByCategory['upah']) ?></strong></td>
                                        <?php if ($isEditable): ?><td></td><?php endif; ?>
                                    </tr>
                                    
                                    <!-- B. BAHAN -->
                                    <tr style="background-color: #e8fde8;">
                                        <td colspan="<?= $isEditable ? 7 : 6 ?>"><strong>B. BAHAN</strong></td>
                                    </tr>
                                    <?php if (empty($detailsByCategory['material'])): ?>
                                    <tr><td colspan="<?= $isEditable ? 7 : 6 ?>" class="text-center text-muted">Belum ada komponen bahan</td></tr>
                                    <?php else: ?>
                                    <?php $no = 1; foreach ($detailsByCategory['material'] as $detail): ?>
                                    <form method="POST" class="inline-edit-form">
                                        <input type="hidden" name="action" value="update_ahsp_detail">
                                        <input type="hidden" name="detail_id" value="<?= $detail['id'] ?>">
                                        <input type="hidden" name="ahsp_id" value="<?= $ahsp['id'] ?>">
                                        <tr>
                                            <td class="text-center"><?= $no++ ?></td>
                                            <td><?= sanitize($detail['item_name']) ?></td>
                                            <td><?= sanitize($detail['unit']) ?></td>
                                            <td><input type="text" class="form-control form-control-sm border-0 text-end" name="coefficient" value="<?= formatNumber($detail['coefficient'], 4) ?>" style="width:80px;" <?= !$isEditable ? 'disabled' : '' ?>></td>
                                            <td class="text-end"><?= formatRupiah($detail['item_up_price']) ?></td>
                                            <td class="text-end"><?= formatRupiah($detail['total_price']) ?></td>
                                            <?php if ($isEditable): ?>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-danger detail-delete-btn edit-mode-only d-none" onclick="deleteAhspDetail(<?= $detail['id'] ?>, <?= $ahsp['id'] ?>)"><i class="mdi mdi-delete"></i></button>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    </form>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                    <tr style="background-color: #c8f7c8;">
                                        <td colspan="5" class="text-end"><strong>JUMLAH BAHAN</strong></td>
                                        <td class="text-end"><strong><?= formatRupiah($totalByCategory['material']) ?></strong></td>
                                        <?php if ($isEditable): ?><td></td><?php endif; ?>
                                    </tr>
                                    
                                    <!-- C. PERALATAN -->
                                    <tr style="background-color: #fdf8e8;">
                                        <td colspan="<?= $isEditable ? 7 : 6 ?>"><strong>C. PERALATAN</strong></td>
                                    </tr>
                                    <?php if (empty($detailsByCategory['alat'])): ?>
                                    <tr><td colspan="<?= $isEditable ? 7 : 6 ?>" class="text-center text-muted">Belum ada komponen alat</td></tr>
                                    <?php else: ?>
                                    <?php $no = 1; foreach ($detailsByCategory['alat'] as $detail): ?>
                                    <form method="POST" class="inline-edit-form">
                                        <input type="hidden" name="action" value="update_ahsp_detail">
                                        <input type="hidden" name="detail_id" value="<?= $detail['id'] ?>">
                                        <input type="hidden" name="ahsp_id" value="<?= $ahsp['id'] ?>">
                                        <tr>
                                            <td class="text-center"><?= $no++ ?></td>
                                            <td><?= sanitize($detail['item_name']) ?></td>
                                            <td><?= sanitize($detail['unit']) ?></td>
                                            <td><input type="text" class="form-control form-control-sm border-0 text-end" name="coefficient" value="<?= formatNumber($detail['coefficient'], 4) ?>" style="width:80px;" <?= !$isEditable ? 'disabled' : '' ?>></td>
                                            <td class="text-end"><?= formatRupiah($detail['item_up_price']) ?></td>
                                            <td class="text-end"><?= formatRupiah($detail['total_price']) ?></td>
                                            <?php if ($isEditable): ?>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-danger detail-delete-btn edit-mode-only d-none" onclick="deleteAhspDetail(<?= $detail['id'] ?>, <?= $ahsp['id'] ?>)"><i class="mdi mdi-delete"></i></button>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    </form>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                    <tr style="background-color: #f5edc8;">
                                        <td colspan="5" class="text-end"><strong>JUMLAH ALAT</strong></td>
                                        <td class="text-end"><strong><?= formatRupiah($totalByCategory['alat']) ?></strong></td>
                                        <?php if ($isEditable): ?><td></td><?php endif; ?>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="table-secondary">
                                        <td colspan="5" class="text-end"><strong>D. Jumlah (A+B+C)</strong></td>
                                        <td class="text-end"><strong><?= formatRupiah($subtotal) ?></strong></td>
                                        <?php if ($isEditable): ?><td></td><?php endif; ?>
                                    </tr>
                                    <tr>
                                        <td colspan="5" class="text-end"><strong>E. Overhead & Profit (<?= $overheadPct ?>%)</strong></td>
                                        <td class="text-end"><?= formatRupiah($overheadAmount) ?></td>
                                        <?php if ($isEditable): ?><td></td><?php endif; ?>
                                    </tr>
                                    <tr class="table-dark">
                                        <td colspan="5" class="text-end"><strong>HARGA SATUAN PEKERJAAN (D+E)</strong></td>
                                        <td class="text-end"><strong><?= formatRupiah($grandTotal) ?></strong></td>
                                        <?php if ($isEditable): ?><td></td><?php endif; ?>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <?php endif; ?>
    </div>
</div>

<!-- Add Item Modal -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="add_item">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label required">Kode Item</label>
                        <input type="text" class="form-control" name="item_code" required placeholder="MTR-001">
                    </div>
                    <div class="col-md-8 mb-3">
                        <label class="form-label required">Nama Item</label>
                        <input type="text" class="form-control" name="item_name" required placeholder="Contoh: Semen Portland">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Merk</label>
                    <input type="text" class="form-control" name="item_brand" placeholder="Opsional (Contoh: Tiga Roda)">
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Kategori</label>
                        <select class="form-select" name="item_category" required>
                            <option value="">-- Pilih --</option>
                            <option value="upah">Upah</option>
                            <option value="material">Material</option>
                            <option value="alat">Alat</option>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Satuan</label>
                        <input type="text" class="form-control" name="item_unit" required placeholder="Contoh: sak, m3, OH">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Harga PU (Rp)</label>
                        <input type="text" class="form-control text-end currency" name="item_price" required placeholder="0">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Harga Aktual (Rp)</label>
                        <input type="text" class="form-control text-end currency" name="item_actual_price" placeholder="Opsional">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Item Modal -->
<div class="modal fade" id="editItemModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="update_item">
            <input type="hidden" name="item_id" id="edit_item_id">
            <div class="modal-header">
                <h5 class="modal-title">Edit Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label required">Kode Item</label>
                        <input type="text" class="form-control" name="item_code" id="edit_item_code" required>
                    </div>
                    <div class="col-md-8 mb-3">
                        <label class="form-label required">Nama Item</label>
                        <input type="text" class="form-control" name="item_name" id="edit_item_name" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Merk</label>
                    <input type="text" class="form-control" name="item_brand" id="edit_item_brand" placeholder="Opsional">
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Kategori</label>
                        <select class="form-select" name="item_category" id="edit_item_category" required>
                            <option value="upah">Upah</option>
                            <option value="material">Material</option>
                            <option value="alat">Alat</option>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Satuan</label>
                        <input type="text" class="form-control" name="item_unit" id="edit_item_unit" required>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Harga PU (Rp)</label>
                        <input type="text" class="form-control text-end currency" name="item_price" id="edit_item_price" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Harga Aktual (Rp)</label>
                        <input type="text" class="form-control text-end currency" name="item_actual_price" id="edit_item_actual_price">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Add AHSP Modal -->
<div class="modal fade" id="addAhspModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="add_ahsp">
            <div class="modal-header">
                <h5 class="modal-title">Tambah AHSP</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label required">Kode AHSP</label>
                        <input type="text" class="form-control" name="ahsp_code" required placeholder="AHSP-001">
                    </div>
                    <div class="col-md-8 mb-3">
                        <label class="form-label required">Nama Pekerjaan</label>
                        <input type="text" class="form-control" name="work_name" required 
                               placeholder="Contoh: Pekerjaan Pasangan Bata">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label required">Satuan</label>
                    <input type="text" class="form-control" name="ahsp_unit" required placeholder="Contoh: m2, m3, unit">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit AHSP Modal -->
<div class="modal fade" id="editAhspModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="update_ahsp">
            <input type="hidden" name="ahsp_id" id="edit_ahsp_id">
            <div class="modal-header">
                <h5 class="modal-title">Edit AHSP</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label required">Kode AHSP</label>
                        <input type="text" class="form-control" name="ahsp_code" id="edit_ahsp_code" required>
                    </div>
                    <div class="col-md-8 mb-3">
                        <label class="form-label required">Nama Pekerjaan</label>
                        <input type="text" class="form-control" name="work_name" id="edit_ahsp_name" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label required">Satuan</label>
                    <input type="text" class="form-control" name="ahsp_unit" id="edit_ahsp_unit" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Add AHSP Detail Modal -->
<div class="modal fade" id="addDetailModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="add_ahsp_detail">
            <input type="hidden" name="ahsp_id" id="add_detail_ahsp_id">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Komponen</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label required">Pilih Item</label>
                    <input type="text" class="form-control mb-2" id="searchItemInput" placeholder="🔍 Ketik untuk mencari item...">
                    <select class="form-select" name="detail_item_id" id="detailItemSelect" required size="8" style="height: auto;">
                        <option value="">-- Pilih Item --</option>
                        <?php if (!empty($itemsByCategory['upah'])): ?>
                        <optgroup label="Upah">
                            <?php foreach ($itemsByCategory['upah'] as $item): ?>
                            <option value="<?= $item['id'] ?>"><?= sanitize($item['name']) ?> (<?= formatRupiah($item['price']) ?>)</option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                        <?php if (!empty($itemsByCategory['material'])): ?>
                        <optgroup label="Material">
                            <?php foreach ($itemsByCategory['material'] as $item): ?>
                            <option value="<?= $item['id'] ?>"><?= sanitize($item['name']) ?> (<?= formatRupiah($item['price']) ?>)</option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                        <?php if (!empty($itemsByCategory['alat'])): ?>
                        <optgroup label="Alat">
                            <?php foreach ($itemsByCategory['alat'] as $item): ?>
                            <option value="<?= $item['id'] ?>"><?= sanitize($item['name']) ?> (<?= formatRupiah($item['price']) ?>)</option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label required">Koefisien</label>
                    <input type="text" class="form-control text-end" name="coefficient" required placeholder="0,0000">
                    <small class="text-muted">Gunakan koma untuk desimal. Contoh: 0,0025</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
// Delete Item Function
function deleteItem(itemId) {
    confirmDelete(function() {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="delete_item"><input type="hidden" name="item_id" value="' + itemId + '">';
        document.body.appendChild(form);
        form.submit();
    });
}

// Clear All Items Function
function confirmClearItems() {
    // Use confirmDelete modal with custom warning message
    confirmDelete(function() {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="clear_all_items">';
        document.body.appendChild(form);
        form.submit();
    }, {
        title: '⚠️ PERINGATAN!',
        message: '<strong>Anda akan menghapus SEMUA items</strong> (Upah, Material, Alat) dari proyek ini.<br><br>Semua komponen AHSP yang terkait juga akan dihapus.<br><br><span class="text-danger">Tindakan ini tidak dapat dibatalkan!</span>',
        buttonText: 'Ya, Hapus Semua',
        buttonClass: 'btn-danger'
    });
}

// Delete AHSP Detail Function
function deleteAhspDetail(detailId, ahspId) {
    confirmDelete(function() {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="delete_ahsp_detail"><input type="hidden" name="detail_id" value="' + detailId + '"><input type="hidden" name="ahsp_id" value="' + ahspId + '">';
        document.body.appendChild(form);
        form.submit();
    });
}

// Delete AHSP Function
function deleteAhsp(ahspId) {
    confirmDelete(function() {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="delete_ahsp"><input type="hidden" name="ahsp_id" value="' + ahspId + '">';
        document.body.appendChild(form);
        form.submit();
    });
}

// All JavaScript rewritten to vanilla JS (no jQuery dependency)
document.addEventListener('DOMContentLoaded', function() {
    console.log('Master Data JS loaded!');
    
    // Function to submit item row changes via AJAX (no reload)
    function submitItemRow(row) {
        var itemId = row.dataset.itemId;
        var category = row.dataset.category;
        var codeInput = row.querySelector('.item-code');
        var itemCode = codeInput ? codeInput.value : '';
        var name = row.querySelector('.item-name').value;
        var brandInput = row.querySelector('.item-brand');
        var brand = brandInput ? brandInput.value : '';
        var unit = row.querySelector('.item-unit').value;
        var price = row.querySelector('.item-price').value;
        var actualPriceInput = row.querySelector('.item-actual-price');
        var actualPrice = actualPriceInput ? actualPriceInput.value : '';
        
        // Debug log
        console.log('=== submitItemRow called ===');
        console.log('Item ID:', itemId);
        console.log('item_code:', itemCode);
        console.log('item_brand:', brand);
        console.log('has .item-code input:', codeInput !== null);
        console.log('has .item-brand input:', brandInput !== null);
        
        // Show saving indicator
        row.style.backgroundColor = '#fffde7';
        
        // Use FormData for fetch
        var formData = new FormData();
        formData.append('action', 'update_item_ajax');
        formData.append('item_id', itemId);
        formData.append('item_code', itemCode);
        formData.append('item_category', category);
        formData.append('item_name', name);
        formData.append('item_brand', brand);
        formData.append('item_unit', unit);
        formData.append('item_price', price);
        formData.append('item_actual_price', actualPrice);
        
        console.log('Sending fetch to:', window.location.href);
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Response status:', response.status);
            console.log('Response headers:', response.headers.get('content-type'));
            return response.text(); // Use text first to see raw response
        })
        .then(text => {
            console.log('Raw response:', text.substring(0, 500));
            try {
                var data = JSON.parse(text);
                console.log('Parsed JSON:', data);
                
                if (data.success) {
                    // Flash green and update original values
                    row.style.backgroundColor = '#e8f5e9';
                    setTimeout(() => { row.style.backgroundColor = ''; }, 1000);
                    
                    // Update original values to prevent re-save on blur
                    row.querySelectorAll('input').forEach(input => {
                        input.dataset.original = input.value;
                    });
                    
                    // Show toast notification
                    showToast('success', data.message);
                } else {
                    row.style.backgroundColor = '#ffebee';
                    showToast('error', data.message || 'Gagal menyimpan!');
                }
            } catch (e) {
                console.error('JSON parse error:', e);
                console.error('Response was:', text.substring(0, 200));
                row.style.backgroundColor = '#ffebee';
                showToast('error', 'Response bukan JSON valid!');
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            row.style.backgroundColor = '#ffebee';
            showToast('error', 'Gagal menyimpan: ' + error.message);
        });
    }
    
    // Toast notification helper
    function showToast(type, message) {
        var toast = document.createElement('div');
        toast.className = 'position-fixed bottom-0 end-0 p-3';
        toast.style.zIndex = '9999';
        toast.innerHTML = '<div class="toast show align-items-center text-white bg-' + (type === 'success' ? 'success' : 'danger') + ' border-0" role="alert"><div class="d-flex"><div class="toast-body">' + message + '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>';
        document.body.appendChild(toast);
        setTimeout(() => { toast.remove(); }, 3000);
    }
    
    // Handle Enter key for item rows
    document.querySelectorAll('.item-row input').forEach(function(input) {
        // Store original value
        input.dataset.original = input.value;
        
        input.addEventListener('keypress', function(e) {
            if (e.which === 13 || e.keyCode === 13) {
                e.preventDefault();
                var row = this.closest('.item-row');
                submitItemRow(row);
            }
        });
        
        // Handle blur for auto-save
        input.addEventListener('blur', function() {
            var originalValue = this.dataset.original || '';
            if (this.value !== originalValue) {
                var row = this.closest('.item-row');
                submitItemRow(row);
            }
        });
    });
    
    // Handle inline forms (AHSP details) - Enter key
    document.querySelectorAll('.inline-edit-form input').forEach(function(input) {
        input.dataset.original = input.value;
        
        input.addEventListener('keypress', function(e) {
            if (e.which === 13 || e.keyCode === 13) {
                e.preventDefault();
                this.closest('form').submit();
            }
        });
    });
    
    // Auto-switch to AHSP subtab if URL has subtab=ahsp
    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('subtab') === 'ahsp') {
        var itemsLink = document.querySelector('a[href="#items-tab"]');
        var ahspLink = document.querySelector('a[href="#ahsp-tab"]');
        var itemsTab = document.getElementById('items-tab');
        var ahspTab = document.getElementById('ahsp-tab');
        
        if (itemsLink) itemsLink.classList.remove('active');
        if (ahspLink) ahspLink.classList.add('active');
        if (itemsTab) itemsTab.classList.remove('show', 'active');
        if (ahspTab) ahspTab.classList.add('show', 'active');
        
        // Scroll to target AHSP if specified
        var targetAhsp = document.querySelector('.target-ahsp');
        if (targetAhsp) {
            setTimeout(function() {
                targetAhsp.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 300);
        }
    }
    
    // Search Items
    var searchItemsInput = document.getElementById('searchItems');
    if (searchItemsInput) {
        console.log('Search Items input found!');
        
        searchItemsInput.addEventListener('input', function() {
            var searchText = this.value.toLowerCase().trim();
            console.log('Searching for:', searchText);
            
            // Get all item rows
            var rows = document.querySelectorAll('.item-row');
            console.log('Found', rows.length, 'item rows');
            
            rows.forEach(function(row) {
                var nameInput = row.querySelector('.item-name');
                var itemName = nameInput ? nameInput.value.toLowerCase() : '';
                
                if (searchText === '' || itemName.indexOf(searchText) > -1) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Show/hide section headers based on visible rows
            var sections = document.querySelectorAll('.item-section');
            sections.forEach(function(section) {
                var tableWrapper = section.nextElementSibling;
                if (tableWrapper && tableWrapper.classList.contains('table-responsive')) {
                    var visibleRows = tableWrapper.querySelectorAll('.item-row:not([style*="display: none"])').length;
                    
                    if (visibleRows === 0) {
                        section.style.display = 'none';
                        tableWrapper.style.display = 'none';
                    } else {
                        section.style.display = '';
                        tableWrapper.style.display = '';
                    }
                }
            });
        });
    } else {
        console.log('Search Items input NOT found!');
    }
    
    // Category Filter for Items
    var categoryFilter = document.getElementById('categoryFilter');
    if (categoryFilter) {
        var filterButtons = categoryFilter.querySelectorAll('button[data-filter]');
        filterButtons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                // Update active button
                filterButtons.forEach(function(b) { b.classList.remove('active'); });
                this.classList.add('active');
                
                var filter = this.dataset.filter;
                var sections = document.querySelectorAll('.item-section');
                
                sections.forEach(function(section) {
                    var tableWrapper = section.nextElementSibling;
                    if (!tableWrapper || !tableWrapper.classList.contains('table-responsive')) return;
                    
                    var table = tableWrapper.querySelector('.item-table');
                    var category = table ? table.dataset.category : '';
                    
                    if (filter === 'all' || filter === category) {
                        section.style.display = '';
                        tableWrapper.style.display = '';
                    } else {
                        section.style.display = 'none';
                        tableWrapper.style.display = 'none';
                    }
                });
            });
        });
    }
    
    // Price dropdown selection handler
    document.querySelectorAll('.price-option').forEach(function(option) {
        option.addEventListener('click', function(e) {
            e.preventDefault();
            var price = parseInt(this.dataset.price); // Use parseInt for integer
            var inputGroup = this.closest('.price-dropdown-wrap');
            var input = inputGroup.querySelector('.price-input');
            if (input && price) {
                // Format number with Indonesian format (dots as thousands separator)
                input.value = price.toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                // Trigger form submit
                var form = input.closest('form');
                if (form) form.submit();
            }
        });
    });
    
    
    // Sort AHSP function
    window.sortAhsp = function(sortBy) {
        var url = new URL(window.location.href);
        url.searchParams.set('ahsp_sort', sortBy);
        url.searchParams.set('subtab', 'ahsp');
        window.location.href = url.toString();
    };
    
    // Search AHSP
    var searchAhspInput = document.getElementById('searchAhsp');
    if (searchAhspInput) {
        console.log('Search AHSP input found!');
        
        searchAhspInput.addEventListener('input', function() {
            var searchText = this.value.toLowerCase().trim();
            console.log('Searching AHSP for:', searchText);
            
            // Get all accordion items
            var accordionItems = document.querySelectorAll('#ahspAccordion .accordion-item');
            console.log('Found', accordionItems.length, 'accordion items');
            
            accordionItems.forEach(function(item) {
                var strongEl = item.querySelector('.accordion-button strong');
                var workName = strongEl ? strongEl.textContent.toLowerCase() : '';
                
                if (searchText === '' || workName.indexOf(searchText) > -1) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    } else {
        console.log('Search AHSP input NOT found!');
    }
    
    // Sorting functionality for item tables
    document.querySelectorAll('.sortable-header').forEach(function(header) {
        header.addEventListener('click', function() {
            var table = this.closest('table');
            var tbody = table.querySelector('tbody');
            var sortField = this.dataset.sort;
            var currentOrder = this.dataset.order || 'none';
            var newOrder = currentOrder === 'asc' ? 'desc' : 'asc';
            
            // Get all rows from this table
            var rows = Array.from(tbody.querySelectorAll('.item-row'));
            
            // Sort rows
            rows.sort(function(a, b) {
                var aValue, bValue;
                
                if (sortField === 'name') {
                    aValue = a.querySelector('.item-name').value.toLowerCase();
                    bValue = b.querySelector('.item-name').value.toLowerCase();
                    return newOrder === 'asc' 
                        ? aValue.localeCompare(bValue)
                        : bValue.localeCompare(aValue);
                } else if (sortField === 'unit') {
                    aValue = a.querySelector('.item-unit').value.toLowerCase();
                    bValue = b.querySelector('.item-unit').value.toLowerCase();
                    return newOrder === 'asc' 
                        ? aValue.localeCompare(bValue)
                        : bValue.localeCompare(aValue);
                } else if (sortField === 'price') {
                    // Parse price (remove dots and convert comma to dot)
                    var aPrice = a.querySelector('.item-price').value.replace(/\./g, '').replace(',', '.');
                    var bPrice = b.querySelector('.item-price').value.replace(/\./g, '').replace(',', '.');
                    aValue = parseFloat(aPrice) || 0;
                    bValue = parseFloat(bPrice) || 0;
                    return newOrder === 'asc' 
                        ? aValue - bValue
                        : bValue - aValue;
                }
                return 0;
            });
            
            // Re-append sorted rows
            rows.forEach(function(row) {
                tbody.appendChild(row);
            });
            
            // Update sort icons in this table only
            table.querySelectorAll('.sortable-header').forEach(function(th) {
                var icon = th.querySelector('.sort-icon');
                if (th === header) {
                    // Current sorted column
                    th.dataset.order = newOrder;
                    icon.className = 'mdi mdi-sort-' + (newOrder === 'asc' ? 'ascending' : 'descending') + ' sort-icon text-primary';
                } else {
                    // Reset other columns
                    th.dataset.order = 'none';
                    icon.className = 'mdi mdi-sort sort-icon';
                }
            });
        });
    });
});

// Open Add Detail Modal Function
function openAddDetail(ahspId) {
    document.getElementById('add_detail_ahsp_id').value = ahspId;
    var modal = new bootstrap.Modal(document.getElementById('addDetailModal'));
    modal.show();
}

// Edit AHSP Function - opens modal with code, name and unit
function editAhsp(ahspId, ahspCode, workName, unit) {
    document.getElementById('edit_ahsp_id').value = ahspId;
    document.getElementById('edit_ahsp_code').value = ahspCode;
    document.getElementById('edit_ahsp_name').value = workName;
    document.getElementById('edit_ahsp_unit').value = unit;
    var modal = new bootstrap.Modal(document.getElementById('editAhspModal'));
    modal.show();
}

// Confirm Clear All AHSP
function confirmClearAhsp() {
    confirmDelete(function() {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="clear_all_ahsp">';
        document.body.appendChild(form);
        form.submit();
    }, {
        title: '⚠️ PERINGATAN!',
        message: '<strong>Anda akan menghapus SEMUA AHSP</strong> dari proyek ini.<br><br>Semua komponen/detail AHSP juga akan dihapus.<br><br><span class="text-danger">Tindakan ini tidak dapat dibatalkan!</span>',
        buttonText: 'Ya, Hapus Semua AHSP',
        buttonClass: 'btn-danger'
    });
}

// Search/Filter for Item Select in Add Komponen modal
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('searchItemInput');
    var selectEl = document.getElementById('detailItemSelect');
    
    if (searchInput && selectEl) {
        // Store original options on load
        var allOptions = [];
        var optgroups = selectEl.querySelectorAll('optgroup');
        
        optgroups.forEach(function(og) {
            var groupData = {
                label: og.label,
                options: []
            };
            og.querySelectorAll('option').forEach(function(opt) {
                groupData.options.push({
                    value: opt.value,
                    text: opt.textContent,
                    element: opt.cloneNode(true)
                });
            });
            allOptions.push(groupData);
        });
        
        // Also store standalone options (like "-- Pilih Item --")
        var standaloneOptions = [];
        selectEl.querySelectorAll(':scope > option').forEach(function(opt) {
            standaloneOptions.push(opt.cloneNode(true));
        });
        
        searchInput.addEventListener('input', function() {
            var searchText = this.value.toLowerCase().trim();
            
            // Clear current options
            selectEl.innerHTML = '';
            
            // Re-add standalone options
            standaloneOptions.forEach(function(opt) {
                selectEl.appendChild(opt.cloneNode(true));
            });
            
            // Filter and rebuild optgroups
            allOptions.forEach(function(group) {
                var matchingOptions = group.options.filter(function(opt) {
                    return opt.text.toLowerCase().indexOf(searchText) > -1;
                });
                
                if (matchingOptions.length > 0) {
                    var og = document.createElement('optgroup');
                    og.label = group.label;
                    matchingOptions.forEach(function(opt) {
                        og.appendChild(opt.element.cloneNode(true));
                    });
                    selectEl.appendChild(og);
                }
            });
        });
        
        // Clear search when modal is opened
        var modal = document.getElementById('addDetailModal');
        if (modal) {
            modal.addEventListener('shown.bs.modal', function() {
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('input'));
                searchInput.focus();
            });
        }
    }
});

// Edit Mode Toggle
document.addEventListener('DOMContentLoaded', function() {
    var editModeToggle = document.getElementById('editModeToggle');
    var editModeToggleAhsp = document.getElementById('editModeToggleAhsp');
    
    function toggleEditMode(isEnabled, scope) {
        var container = scope === 'items' ? document.getElementById('items-tab') : document.getElementById('ahsp-tab');
        if (!container) return;
        
        // Show/hide edit-mode-only buttons
        var editOnlyElements = container.querySelectorAll('.edit-mode-only');
        editOnlyElements.forEach(function(el) {
            if (isEnabled) {
                el.classList.remove('d-none');
            } else {
                el.classList.add('d-none');
            }
        });
        
        if (scope === 'items') {
            // Enable/disable input fields in items
            var inputs = container.querySelectorAll('.item-row input, .item-row select');
            inputs.forEach(function(input) {
                input.disabled = !isEnabled;
                if (isEnabled) {
                    input.classList.add('bg-white');
                } else {
                    input.classList.remove('bg-white');
                }
            });
        } else {
            // For AHSP, enable/disable delete and edit buttons
            var ahspEditButtons = container.querySelectorAll('.ahsp-edit-btn, .ahsp-delete-btn, .detail-delete-btn, .coef-input');
            ahspEditButtons.forEach(function(el) {
                if (isEnabled) {
                    el.classList.remove('d-none');
                    if (el.tagName === 'INPUT') el.disabled = false;
                } else {
                    if (el.classList.contains('ahsp-delete-btn') || el.classList.contains('detail-delete-btn')) {
                        el.classList.add('d-none');
                    }
                    if (el.tagName === 'INPUT') el.disabled = true;
                }
            });
        }
        
        // Store state in localStorage
        localStorage.setItem('editMode_' + scope, isEnabled ? '1' : '0');
    }
    
    // Initialize Items edit mode toggle
    if (editModeToggle) {
        // Restore previous state
        var savedState = localStorage.getItem('editMode_items') === '1';
        editModeToggle.checked = savedState;
        toggleEditMode(savedState, 'items');
        
        editModeToggle.addEventListener('change', function() {
            toggleEditMode(this.checked, 'items');
        });
    }
    
    // Initialize AHSP edit mode toggle
    if (editModeToggleAhsp) {
        var savedStateAhsp = localStorage.getItem('editMode_ahsp') === '1';
        editModeToggleAhsp.checked = savedStateAhsp;
        toggleEditMode(savedStateAhsp, 'ahsp');
        
        editModeToggleAhsp.addEventListener('change', function() {
            toggleEditMode(this.checked, 'ahsp');
        });
    }
});
</script>
