<?php
/**
 * AHSP RAP Editor - Edit AHSP components for RAP
 * Changes are stored separately in rap_ahsp_details table
 * Does NOT affect Master Data
 * Uses form POST like rab.php (more reliable than AJAX)
 */

// AJAX Handler - must be first
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajax_update_rap_ahsp_coefficient') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    
    header('Content-Type: application/json');
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
        die(json_encode(['success' => false, 'message' => 'Unauthorized']));
    }
    
    try {
        $detailId = intval($_POST['id'] ?? 0);
        $value = floatval($_POST['value'] ?? 0);
        
        // Get detail to find rap_item_id
        $detail = dbGetRow("SELECT rap_item_id FROM rap_ahsp_details WHERE id = ?", [$detailId]);
        if (!$detail) {
            die(json_encode(['success' => false, 'message' => 'Detail not found']));
        }
        
        // Update coefficient
        dbExecute("UPDATE rap_ahsp_details SET coefficient = ? WHERE id = ?", [$value, $detailId]);
        
        // Recalculate RAP unit_price
        $newUnitPrice = dbGetRow("SELECT SUM(coefficient * unit_price) as total FROM rap_ahsp_details WHERE rap_item_id = ?", [$detail['rap_item_id']])['total'] ?? 0;
        dbExecute("UPDATE rap_items SET unit_price = ? WHERE id = ?", [$newUnitPrice, $detail['rap_item_id']]);
        
        die(json_encode(['success' => true, 'message' => 'Koefisien tersimpan']));
    } catch (Exception $e) {
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();
requireAdmin();

$rapItemId = $_GET['id'] ?? null;

if (!$rapItemId) {
    header('Location: index.php');
    exit;
}

// Get RAP item with related data
$rapItem = dbGetRow("
    SELECT rap.*, rs.code, rs.name as work_name, rs.unit, rs.ahsp_id,
           rc.project_id, p.name as project_name, p.status as project_status
    FROM rap_items rap
    JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
    JOIN rab_categories rc ON rs.category_id = rc.id
    JOIN projects p ON rc.project_id = p.id
    WHERE rap.id = ?
", [$rapItemId]);

if (!$rapItem) {
    setFlash('error', 'RAP item tidak ditemukan!');
    header('Location: index.php');
    exit;
}

$projectId = $rapItem['project_id'];
$isEditable = ($rapItem['project_status'] === 'draft');

// Get project details for overhead percentage
$project = dbGetRow("SELECT * FROM projects WHERE id = ?", [$projectId]);

// Handle POST actions (form-based, same pattern as rab.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        // Update coefficient
        if ($action === 'update_coefficient' && $isEditable) {
            $detailId = intval($_POST['detail_id']);
            $valueRaw = $_POST['coefficient'] ?? '0';
            $value = floatval(str_replace(',', '.', str_replace('.', '', $valueRaw)));
            
            dbExecute("UPDATE rap_ahsp_details SET coefficient = ? WHERE id = ?", [$value, $detailId]);
            
            // Recalculate RAP unit_price
            $newUnitPrice = dbGetRow("SELECT SUM(coefficient * unit_price) as total FROM rap_ahsp_details WHERE rap_item_id = ?", [$rapItemId])['total'] ?? 0;
            dbExecute("UPDATE rap_items SET unit_price = ? WHERE id = ?", [$newUnitPrice, $rapItemId]);
            
            setFlash('success', 'Koefisien berhasil diperbarui!');
            header('Location: ahsp_rap.php?id=' . $rapItemId);
            exit;
        }
        
        // Update unit price
        if ($action === 'update_price' && $isEditable) {
            $detailId = intval($_POST['detail_id']);
            $valueRaw = $_POST['unit_price'] ?? '0';
            $value = floatval(str_replace(',', '.', str_replace('.', '', $valueRaw)));
            
            dbExecute("UPDATE rap_ahsp_details SET unit_price = ? WHERE id = ?", [$value, $detailId]);
            
            // Recalculate RAP unit_price
            $newUnitPrice = dbGetRow("SELECT SUM(coefficient * unit_price) as total FROM rap_ahsp_details WHERE rap_item_id = ?", [$rapItemId])['total'] ?? 0;
            dbExecute("UPDATE rap_items SET unit_price = ? WHERE id = ?", [$newUnitPrice, $rapItemId]);
            
            setFlash('success', 'Harga satuan berhasil diperbarui!');
            header('Location: ahsp_rap.php?id=' . $rapItemId);
            exit;
        }
        
        // Add item
        if ($action === 'add_item' && $isEditable) {
            $itemId = intval($_POST['item_id']);
            $coeffRaw = $_POST['coefficient'] ?? '0';
            $coefficient = floatval(str_replace(',', '.', str_replace('.', '', $coeffRaw)));
            
            $item = dbGetRow("SELECT category, price FROM project_items WHERE id = ?", [$itemId]);
            if ($item) {
                $existing = dbGetRow("SELECT id FROM rap_ahsp_details WHERE rap_item_id = ? AND item_id = ?", [$rapItemId, $itemId]);
                if (!$existing) {
                    dbInsert("INSERT INTO rap_ahsp_details (rap_item_id, item_id, category, coefficient, unit_price) VALUES (?, ?, ?, ?, ?)",
                        [$rapItemId, $itemId, $item['category'], $coefficient, $item['price']]);
                    
                    // Recalculate
                    $newUnitPrice = dbGetRow("SELECT SUM(coefficient * unit_price) as total FROM rap_ahsp_details WHERE rap_item_id = ?", [$rapItemId])['total'] ?? 0;
                    dbExecute("UPDATE rap_items SET unit_price = ? WHERE id = ?", [$newUnitPrice, $rapItemId]);
                    
                    setFlash('success', 'Item berhasil ditambahkan!');
                }
            }
            header('Location: ahsp_rap.php?id=' . $rapItemId);
            exit;
        }
        
        // Delete item
        if ($action === 'delete_detail' && $isEditable) {
            $detailId = intval($_POST['detail_id']);
            dbExecute("DELETE FROM rap_ahsp_details WHERE id = ? AND rap_item_id = ?", [$detailId, $rapItemId]);
            
            // Recalculate
            $newUnitPrice = dbGetRow("SELECT SUM(coefficient * unit_price) as total FROM rap_ahsp_details WHERE rap_item_id = ?", [$rapItemId])['total'] ?? 0;
            dbExecute("UPDATE rap_items SET unit_price = ? WHERE id = ?", [$newUnitPrice, $rapItemId]);
            
            setFlash('success', 'Item berhasil dihapus!');
            header('Location: ahsp_rap.php?id=' . $rapItemId);
            exit;
        }
        
    } catch (Exception $e) {
        setFlash('error', 'Terjadi kesalahan: ' . $e->getMessage());
        header('Location: ahsp_rap.php?id=' . $rapItemId);
        exit;
    }
}

// Get RAP AHSP details
$rapDetails = dbGetAll("
    SELECT rad.*, pi.name as item_name, pi.unit,
           pi.price as item_up_price, pi.actual_price as item_actual_price
    FROM rap_ahsp_details rad
    JOIN project_items pi ON rad.item_id = pi.id
    WHERE rad.rap_item_id = ?
    ORDER BY rad.category, pi.name
", [$rapItemId]);

// If empty, copy from project AHSP
if (empty($rapDetails) && $rapItem['ahsp_id']) {
    $projectDetails = dbGetAll("
        SELECT pad.item_id, pi.category, pad.coefficient, pi.price
        FROM project_ahsp_details pad
        JOIN project_items pi ON pad.item_id = pi.id
        WHERE pad.ahsp_id = ?
    ", [$rapItem['ahsp_id']]);
    
    foreach ($projectDetails as $detail) {
        dbInsert("INSERT INTO rap_ahsp_details (rap_item_id, item_id, category, coefficient, unit_price) VALUES (?, ?, ?, ?, ?)",
            [$rapItemId, $detail['item_id'], $detail['category'], $detail['coefficient'], $detail['price']]);
    }
    
    // Reload
    $rapDetails = dbGetAll("
        SELECT rad.*, pi.name as item_name, pi.unit
        FROM rap_ahsp_details rad
        JOIN project_items pi ON rad.item_id = pi.id
        WHERE rad.rap_item_id = ?
        ORDER BY rad.category, pi.name
    ", [$rapItemId]);
}

// Get RAB AHSP details for comparison (indexed by item_id)
$rabAhspDetails = [];
if ($rapItem['ahsp_id']) {
    $rabDetails = dbGetAll("
        SELECT pad.item_id, pad.coefficient, pi.price as unit_price, 
               (pad.coefficient * pi.price) as total_price
        FROM project_ahsp_details pad
        JOIN project_items pi ON pad.item_id = pi.id
        WHERE pad.ahsp_id = ?
    ", [$rapItem['ahsp_id']]);
    foreach ($rabDetails as $rd) {
        $rabAhspDetails[$rd['item_id']] = $rd;
    }
}

// Group by category and calculate selisih
$detailsByCategory = ['upah' => [], 'material' => [], 'alat' => []];
$totals = ['upah' => 0, 'material' => 0, 'alat' => 0];
$rabTotals = ['upah' => 0, 'material' => 0, 'alat' => 0];

foreach ($rapDetails as $detail) {
    $detail['total_price'] = $detail['coefficient'] * $detail['unit_price'];
    
    // Get RAB data for selisih calculation
    $rabData = $rabAhspDetails[$detail['item_id']] ?? null;
    $detail['rab_total_price'] = $rabData ? $rabData['total_price'] : 0;
    $detail['selisih'] = $detail['rab_total_price'] - $detail['total_price']; // Positive = RAP lebih murah
    
    $detailsByCategory[$detail['category']][] = $detail;
    $totals[$detail['category']] += $detail['total_price'];
    $rabTotals[$detail['category']] += $detail['rab_total_price'];
}

$grandTotal = $totals['upah'] + $totals['material'] + $totals['alat'];
$rabGrandTotal = $rabTotals['upah'] + $rabTotals['material'] + $rabTotals['alat'];

// Get available items for adding
$availableItems = dbGetAll("
    SELECT pi.* FROM project_items pi
    WHERE pi.project_id = ?
    AND pi.id NOT IN (SELECT item_id FROM rap_ahsp_details WHERE rap_item_id = ?)
    ORDER BY pi.category, pi.name
", [$projectId, $rapItemId]);

$pageTitle = 'AHSP RAP - ' . $rapItem['work_name'];
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Title -->
<div class="row">
    <div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
            <h4 class="mb-sm-0">AHSP RAP: <?= sanitize($rapItem['code']) ?></h4>
            <div class="page-title-right">
                <ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>">PCM</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Proyek</a></li>
                    <li class="breadcrumb-item"><a href="rap.php?id=<?= $projectId ?>">RAP</a></li>
                    <li class="breadcrumb-item active">AHSP</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- AHSP Details Table - Same structure as AHSP RAB -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Rincian AHSP RAP</h5>
    </div>
    <div class="card-body p-0">
        <?php 
        $overheadPct = $project['overhead_percentage'] ?? 10;
        $overheadAmount = $grandTotal * ($overheadPct / 100);
        $totalWithOverhead = $grandTotal + $overheadAmount;
        
        $rabOverheadAmount = $rabGrandTotal * ($overheadPct / 100);
        $rabTotalWithOverhead = $rabGrandTotal + $rabOverheadAmount;
        
        $totalSelisih = $rabTotalWithOverhead - $totalWithOverhead;
        ?>
        <div class="table-responsive">
            <table class="table table-bordered mb-0">
                <thead>
                    <tr class="table-primary">
                        <th width="40">No</th>
                        <th>Uraian</th>
                        <th width="70">Satuan</th>
                        <th width="90" class="text-end">Koefisien</th>
                        <th width="110" class="text-end">Harga Satuan</th>
                        <th width="120" class="text-end">Jumlah Harga</th>
                        <th width="110" class="text-end">Selisih RAB</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- A. TENAGA -->
                    <tr style="background-color: #e8f4fd;">
                        <td colspan="7"><strong>A. TENAGA</strong></td>
                    </tr>
                    <?php if (empty($detailsByCategory['upah'])): ?>
                    <tr><td colspan="7" class="text-center text-muted">Belum ada komponen tenaga</td></tr>
                    <?php else: ?>
                    <?php $no = 1; foreach ($detailsByCategory['upah'] as $detail): 
                        $selisihClass = $detail['selisih'] > 0 ? 'text-success' : ($detail['selisih'] < 0 ? 'text-danger' : 'text-warning');
                        $rabTotal = $detail['rab_total_price'] ?? 0;
                        $selisihPct = $rabTotal > 0 ? ($detail['selisih'] / $rabTotal) * 100 : 0;
                    ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td><?= sanitize($detail['item_name']) ?></td>
                        <td><?= sanitize($detail['unit']) ?></td>
                        <td>
                            <form method="POST" class="inline-edit-form d-inline">
                                <input type="hidden" name="action" value="update_coefficient">
                                <input type="hidden" name="detail_id" value="<?= $detail['id'] ?>">
                                <input type="text" class="form-control form-control-sm border-0 text-end" name="coefficient" value="<?= formatNumber($detail['coefficient'], 4) ?>" style="width:75px;" <?= !$isEditable ? 'disabled' : '' ?>>
                            </form>
                        </td>
                        <td>
                            <form method="POST" class="inline-edit-form d-inline">
                                <input type="hidden" name="action" value="update_price">
                                <input type="hidden" name="detail_id" value="<?= $detail['id'] ?>">
                                <?php if ($isEditable): ?>
                                <div class="d-flex align-items-center">
                                    <input type="text" class="form-control form-control-sm text-end price-input" 
                                           name="unit_price" 
                                           value="<?= formatNumber($detail['unit_price'], 2) ?>" 
                                           style="width:100px; border-top-right-radius:0; border-bottom-right-radius:0;">
                                    <div class="dropdown">
                                        <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="dropdown" 
                                                style="border-top-left-radius:0; border-bottom-left-radius:0; padding:0.25rem 0.4rem;">
                                            <i class="mdi mdi-chevron-down"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item price-option" href="#" data-price="<?= floatval($detail['item_up_price']) ?>">PU: <?= formatRupiah($detail['item_up_price']) ?></a></li>
                                            <?php if ($detail['item_actual_price']): ?>
                                            <li><a class="dropdown-item price-option" href="#" data-price="<?= floatval($detail['item_actual_price']) ?>">Aktual: <?= formatRupiah($detail['item_actual_price']) ?></a></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </div>
                                <?php else: ?>
                                <span class="text-end d-block"><?= formatRupiah($detail['unit_price']) ?></span>
                                <?php endif; ?>
                            </form>
                        </td>
                        <td class="text-end"><?= formatRupiah($detail['total_price']) ?></td>
                        <td class="text-end <?= $selisihClass ?>" 
                            data-bs-toggle="tooltip" 
                            data-bs-placement="left"
                            data-bs-html="true"
                            title="Selisih: <?= ($selisihPct >= 0 ? '+' : '') . formatNumber($selisihPct, 2) ?>%<br>RAB: <?= formatRupiah($rabTotal) ?>"
                            style="cursor: help;">
                            <strong><?= ($detail['selisih'] >= 0 ? '+' : '') . formatNumber($detail['selisih']) ?></strong>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    <?php 
                        $upahSelisih = $rabTotals['upah'] - $totals['upah'];
                        $upahSelisihPct = $rabTotals['upah'] > 0 ? ($upahSelisih / $rabTotals['upah']) * 100 : 0;
                    ?>
                    <tr style="background-color: #d4edfc;">
                        <td colspan="5" class="text-end"><strong>JUMLAH TENAGA</strong></td>
                        <td class="text-end"><strong><?= formatRupiah($totals['upah']) ?></strong></td>
                        <td class="text-end <?= $upahSelisih > 0 ? 'text-success' : ($upahSelisih < 0 ? 'text-danger' : 'text-warning') ?>"
                            data-bs-toggle="tooltip" 
                            data-bs-placement="left"
                            data-bs-html="true"
                            title="Selisih: <?= ($upahSelisihPct >= 0 ? '+' : '') . formatNumber($upahSelisihPct, 2) ?>%<br>RAB: <?= formatRupiah($rabTotals['upah']) ?>"
                            style="cursor: help;">
                            <strong><?= ($upahSelisih >= 0 ? '+' : '') . formatNumber($upahSelisih, 2) ?></strong>
                        </td>
                    </tr>
                    
                    <!-- B. BAHAN -->
                    <tr style="background-color: #e8fde8;">
                        <td colspan="7"><strong>B. BAHAN</strong></td>
                    </tr>
                    <?php if (empty($detailsByCategory['material'])): ?>
                    <tr><td colspan="7" class="text-center text-muted">Belum ada komponen bahan</td></tr>
                    <?php else: ?>
                    <?php $no = 1; foreach ($detailsByCategory['material'] as $detail): 
                        $selisihClass = $detail['selisih'] > 0 ? 'text-success' : ($detail['selisih'] < 0 ? 'text-danger' : 'text-warning');
                        $rabTotal = $detail['rab_total_price'] ?? 0;
                        $selisihPct = $rabTotal > 0 ? ($detail['selisih'] / $rabTotal) * 100 : 0;
                    ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td><?= sanitize($detail['item_name']) ?></td>
                        <td><?= sanitize($detail['unit']) ?></td>
                        <td>
                            <form method="POST" class="inline-edit-form d-inline">
                                <input type="hidden" name="action" value="update_coefficient">
                                <input type="hidden" name="detail_id" value="<?= $detail['id'] ?>">
                                <input type="text" class="form-control form-control-sm border-0 text-end" name="coefficient" value="<?= formatNumber($detail['coefficient'], 4) ?>" style="width:75px;" <?= !$isEditable ? 'disabled' : '' ?>>
                            </form>
                        </td>
                        <td>
                            <form method="POST" class="inline-edit-form d-inline">
                                <input type="hidden" name="action" value="update_price">
                                <input type="hidden" name="detail_id" value="<?= $detail['id'] ?>">
                                <?php if ($isEditable): ?>
                                <div class="d-flex align-items-center">
                                    <input type="text" class="form-control form-control-sm text-end price-input" 
                                           name="unit_price" 
                                           value="<?= formatNumber($detail['unit_price'], 2) ?>" 
                                           style="width:100px; border-top-right-radius:0; border-bottom-right-radius:0;">
                                    <div class="dropdown">
                                        <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="dropdown" 
                                                style="border-top-left-radius:0; border-bottom-left-radius:0; padding:0.25rem 0.4rem;">
                                            <i class="mdi mdi-chevron-down"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item price-option" href="#" data-price="<?= floatval($detail['item_up_price']) ?>">PU: <?= formatRupiah($detail['item_up_price']) ?></a></li>
                                            <?php if ($detail['item_actual_price']): ?>
                                            <li><a class="dropdown-item price-option" href="#" data-price="<?= floatval($detail['item_actual_price']) ?>">Aktual: <?= formatRupiah($detail['item_actual_price']) ?></a></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </div>
                                <?php else: ?>
                                <span class="text-end d-block"><?= formatRupiah($detail['unit_price']) ?></span>
                                <?php endif; ?>
                            </form>
                        </td>
                        <td class="text-end"><?= formatRupiah($detail['total_price']) ?></td>
                        <td class="text-end <?= $selisihClass ?>" 
                            data-bs-toggle="tooltip" 
                            data-bs-placement="left"
                            data-bs-html="true"
                            title="Selisih: <?= ($selisihPct >= 0 ? '+' : '') . formatNumber($selisihPct, 2) ?>%<br>RAB: <?= formatRupiah($rabTotal) ?>"
                            style="cursor: help;">
                            <strong><?= ($detail['selisih'] >= 0 ? '+' : '') . formatNumber($detail['selisih']) ?></strong>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    <?php 
                        $materialSelisih = $rabTotals['material'] - $totals['material'];
                        $materialSelisihPct = $rabTotals['material'] > 0 ? ($materialSelisih / $rabTotals['material']) * 100 : 0;
                    ?>
                    <tr style="background-color: #c8f7c8;">
                        <td colspan="5" class="text-end"><strong>JUMLAH BAHAN</strong></td>
                        <td class="text-end"><strong><?= formatRupiah($totals['material']) ?></strong></td>
                        <td class="text-end <?= $materialSelisih > 0 ? 'text-success' : ($materialSelisih < 0 ? 'text-danger' : 'text-warning') ?>"
                            data-bs-toggle="tooltip" 
                            data-bs-placement="left"
                            data-bs-html="true"
                            title="Selisih: <?= ($materialSelisihPct >= 0 ? '+' : '') . formatNumber($materialSelisihPct, 2) ?>%<br>RAB: <?= formatRupiah($rabTotals['material']) ?>"
                            style="cursor: help;">
                            <strong><?= ($materialSelisih >= 0 ? '+' : '') . formatNumber($materialSelisih, 2) ?></strong>
                        </td>
                    </tr>
                    
                    <!-- C. PERALATAN -->
                    <tr style="background-color: #fdf8e8;">
                        <td colspan="7"><strong>C. PERALATAN</strong></td>
                    </tr>
                    <?php if (empty($detailsByCategory['alat'])): ?>
                    <tr><td colspan="7" class="text-center text-muted">Belum ada komponen alat</td></tr>
                    <?php else: ?>
                    <?php $no = 1; foreach ($detailsByCategory['alat'] as $detail): 
                        $selisihClass = $detail['selisih'] > 0 ? 'text-success' : ($detail['selisih'] < 0 ? 'text-danger' : 'text-warning');
                        $rabTotal = $detail['rab_total_price'] ?? 0;
                        $selisihPct = $rabTotal > 0 ? ($detail['selisih'] / $rabTotal) * 100 : 0;
                    ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td><?= sanitize($detail['item_name']) ?></td>
                        <td><?= sanitize($detail['unit']) ?></td>
                        <td>
                            <form method="POST" class="inline-edit-form d-inline">
                                <input type="hidden" name="action" value="update_coefficient">
                                <input type="hidden" name="detail_id" value="<?= $detail['id'] ?>">
                                <input type="text" class="form-control form-control-sm border-0 text-end" name="coefficient" value="<?= formatNumber($detail['coefficient'], 4) ?>" style="width:75px;" <?= !$isEditable ? 'disabled' : '' ?>>
                            </form>
                        </td>
                        <td>
                            <form method="POST" class="inline-edit-form d-inline">
                                <input type="hidden" name="action" value="update_price">
                                <input type="hidden" name="detail_id" value="<?= $detail['id'] ?>">
                                <?php if ($isEditable): ?>
                                <div class="d-flex align-items-center">
                                    <input type="text" class="form-control form-control-sm text-end price-input" 
                                           name="unit_price" 
                                           value="<?= formatNumber($detail['unit_price'], 2) ?>" 
                                           style="width:100px; border-top-right-radius:0; border-bottom-right-radius:0;">
                                    <div class="dropdown">
                                        <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="dropdown" 
                                                style="border-top-left-radius:0; border-bottom-left-radius:0; padding:0.25rem 0.4rem;">
                                            <i class="mdi mdi-chevron-down"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item price-option" href="#" data-price="<?= floatval($detail['item_up_price']) ?>">PU: <?= formatRupiah($detail['item_up_price']) ?></a></li>
                                            <?php if ($detail['item_actual_price']): ?>
                                            <li><a class="dropdown-item price-option" href="#" data-price="<?= floatval($detail['item_actual_price']) ?>">Aktual: <?= formatRupiah($detail['item_actual_price']) ?></a></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </div>
                                <?php else: ?>
                                <span class="text-end d-block"><?= formatRupiah($detail['unit_price']) ?></span>
                                <?php endif; ?>
                            </form>
                        </td>
                        <td class="text-end"><?= formatRupiah($detail['total_price']) ?></td>
                        <td class="text-end <?= $selisihClass ?>" 
                            data-bs-toggle="tooltip" 
                            data-bs-placement="left"
                            data-bs-html="true"
                            title="Selisih: <?= ($selisihPct >= 0 ? '+' : '') . formatNumber($selisihPct, 2) ?>%<br>RAB: <?= formatRupiah($rabTotal) ?>"
                            style="cursor: help;">
                            <strong><?= ($detail['selisih'] >= 0 ? '+' : '') . formatNumber($detail['selisih']) ?></strong>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    <?php 
                        $alatSelisih = $rabTotals['alat'] - $totals['alat'];
                        $alatSelisihPct = $rabTotals['alat'] > 0 ? ($alatSelisih / $rabTotals['alat']) * 100 : 0;
                    ?>
                    <tr style="background-color: #f5edc8;">
                        <td colspan="5" class="text-end"><strong>JUMLAH ALAT</strong></td>
                        <td class="text-end"><strong><?= formatRupiah($totals['alat']) ?></strong></td>
                        <td class="text-end <?= $alatSelisih > 0 ? 'text-success' : ($alatSelisih < 0 ? 'text-danger' : 'text-warning') ?>"
                            data-bs-toggle="tooltip" 
                            data-bs-placement="left"
                            data-bs-html="true"
                            title="Selisih: <?= ($alatSelisihPct >= 0 ? '+' : '') . formatNumber($alatSelisihPct, 2) ?>%<br>RAB: <?= formatRupiah($rabTotals['alat']) ?>"
                            style="cursor: help;">
                            <strong><?= ($alatSelisih >= 0 ? '+' : '') . formatNumber($alatSelisih, 2) ?></strong>
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <?php 
                        $dSelisih = $rabGrandTotal - $grandTotal;
                        $dSelisihPct = $rabGrandTotal > 0 ? ($dSelisih / $rabGrandTotal) * 100 : 0;
                        $eSelisih = $rabOverheadAmount - $overheadAmount;
                        $eSelisihPct = $rabOverheadAmount > 0 ? ($eSelisih / $rabOverheadAmount) * 100 : 0;
                        $totalSelisihPct = $rabTotalWithOverhead > 0 ? ($totalSelisih / $rabTotalWithOverhead) * 100 : 0;
                    ?>
                    <tr class="table-secondary">
                        <td colspan="5" class="text-end"><strong>D. Jumlah (A+B+C)</strong></td>
                        <td class="text-end"><strong><?= formatRupiah($grandTotal) ?></strong></td>
                        <td class="text-end <?= $dSelisih > 0 ? 'text-success' : ($dSelisih < 0 ? 'text-danger' : 'text-warning') ?>"
                            data-bs-toggle="tooltip" 
                            data-bs-placement="left"
                            data-bs-html="true"
                            title="Selisih: <?= ($dSelisihPct >= 0 ? '+' : '') . formatNumber($dSelisihPct, 2) ?>%<br>RAB: <?= formatRupiah($rabGrandTotal) ?>"
                            style="cursor: help;">
                            <strong><?= ($dSelisih >= 0 ? '+' : '') . formatNumber($dSelisih, 2) ?></strong>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="5" class="text-end"><strong>E. Overhead & Profit (<?= $overheadPct ?>%)</strong></td>
                        <td class="text-end"><?= formatRupiah($overheadAmount) ?></td>
                        <td class="text-end <?= $eSelisih > 0 ? 'text-success' : ($eSelisih < 0 ? 'text-danger' : 'text-warning') ?>"
                            data-bs-toggle="tooltip" 
                            data-bs-placement="left"
                            data-bs-html="true"
                            title="Selisih: <?= ($eSelisihPct >= 0 ? '+' : '') . formatNumber($eSelisihPct, 2) ?>%<br>RAB: <?= formatRupiah($rabOverheadAmount) ?>"
                            style="cursor: help;">
                            <?= ($eSelisih >= 0 ? '+' : '') . formatNumber($eSelisih, 2) ?>
                        </td>
                    </tr>
                    <tr class="table-dark">
                        <td colspan="5" class="text-end"><strong>HARGA SATUAN PEKERJAAN (D+E)</strong></td>
                        <td class="text-end"><strong><?= formatRupiah($totalWithOverhead) ?></strong></td>
                        <td class="text-end <?= $totalSelisih > 0 ? 'text-success' : ($totalSelisih < 0 ? 'text-danger' : 'text-warning') ?>"
                            data-bs-toggle="tooltip" 
                            data-bs-placement="left"
                            data-bs-html="true"
                            title="Selisih: <?= ($totalSelisihPct >= 0 ? '+' : '') . formatNumber($totalSelisihPct, 2) ?>%<br>RAB: <?= formatRupiah($rabTotalWithOverhead) ?>"
                            style="cursor: help;">
                            <strong><?= ($totalSelisih >= 0 ? '+' : '') . formatNumber($totalSelisih, 2) ?></strong>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <div class="card-footer">
        <a href="rap.php?id=<?= $projectId ?>" class="btn btn-secondary">
            <i class="mdi mdi-arrow-left"></i> Kembali ke RAP
        </a>
    </div>
</div>

<script>
// Submit inline form on Enter key (vanilla JS - no jQuery)
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.inline-edit-form input').forEach(function(input) {
        // Store original value
        input.dataset.original = input.value;
        
        // Enter key submit
        input.addEventListener('keypress', function(e) {
            if (e.which === 13 || e.keyCode === 13) {
                e.preventDefault();
                this.closest('form').submit();
            }
        });
        
        // Auto-save on blur
        input.addEventListener('blur', function() {
            var originalValue = this.dataset.original || this.value;
            if (this.value !== originalValue) {
                this.closest('form').submit();
            }
        });
    });
    
    // Price dropdown selection handler
    document.querySelectorAll('.price-option').forEach(function(option) {
        option.addEventListener('click', function(e) {
            e.preventDefault();
            var price = parseFloat(this.dataset.price); // Use parseFloat to preserve decimals
            // Find the form and input - use closest form instead of non-existent class
            var form = this.closest('form');
            var input = form ? form.querySelector('.price-input') : null;
            
            console.log('Price dropdown clicked:', price, 'Form found:', !!form, 'Input found:', !!input);
            
            if (input && price) {
                // Format number with Indonesian format (dots as thousands separator)
                input.value = price.toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                // Trigger form submit
                if (form) form.submit();
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
