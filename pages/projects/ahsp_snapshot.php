<?php
/**
 * AHSP Snapshot Editor - View/Edit AHSP details for snapshot subcategory
 * PCM - Project Cost Management System
 * Changes here do NOT affect master data or original RAB
 */

// AJAX Handler - must be first
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajax_update_snapshot_ahsp_coefficient') {
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
        
        // Get detail to find snapshot_subcategory_id
        $detail = dbGetRow("SELECT snapshot_subcategory_id FROM rab_snapshot_ahsp_details WHERE id = ?", [$detailId]);
        if (!$detail) {
            die(json_encode(['success' => false, 'message' => 'Detail not found']));
        }
        
        // Update coefficient
        dbExecute("UPDATE rab_snapshot_ahsp_details SET coefficient = ? WHERE id = ?", [$value, $detailId]);
        
        // Recalculate snapshot subcategory unit_price
        $totalPrice = dbGetRow("
            SELECT SUM(coefficient * unit_price) as total 
            FROM rab_snapshot_ahsp_details 
            WHERE snapshot_subcategory_id = ?
        ", [$detail['snapshot_subcategory_id']])['total'] ?? 0;
        dbExecute("UPDATE rab_snapshot_subcategories SET unit_price = ? WHERE id = ?", [$totalPrice, $detail['snapshot_subcategory_id']]);
        
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

$subcategoryId = $_GET['id'] ?? null;

if (!$subcategoryId) {
    header('Location: index.php');
    exit;
}

// Get snapshot subcategory with related data
$subcategory = dbGetRow("
    SELECT ss.*, sc.snapshot_id, sc.name as category_name, sc.code as category_code,
           s.name as snapshot_name, s.project_id, s.overhead_percentage, s.ppn_percentage,
           p.name as project_name
    FROM rab_snapshot_subcategories ss
    JOIN rab_snapshot_categories sc ON ss.category_id = sc.id
    JOIN rab_snapshots s ON sc.snapshot_id = s.id
    JOIN projects p ON s.project_id = p.id
    WHERE ss.id = ?
", [$subcategoryId]);

if (!$subcategory) {
    setFlash('error', 'Sub-kategori tidak ditemukan!');
    header('Location: index.php');
    exit;
}

$snapshotId = $subcategory['snapshot_id'];
$projectId = $subcategory['project_id'];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'update_coefficient') {
            $detailId = $_POST['detail_id'];
            $coeffRaw = $_POST['coefficient'];
            $coefficient = floatval(str_replace(',', '.', str_replace('.', '', $coeffRaw)));
            
            if ($coefficient > 0) {
                dbExecute("UPDATE rab_snapshot_ahsp_details SET coefficient = ? WHERE id = ?",
                    [$coefficient, $detailId]);
                    
                // Recalculate snapshot subcategory unit_price
                $totalPrice = dbGetRow("
                    SELECT SUM(coefficient * unit_price) as total 
                    FROM rab_snapshot_ahsp_details 
                    WHERE snapshot_subcategory_id = ?
                ", [$subcategoryId])['total'] ?? 0;
                dbExecute("UPDATE rab_snapshot_subcategories SET unit_price = ? WHERE id = ?", [$totalPrice, $subcategoryId]);
                
                setFlash('success', 'Koefisien berhasil diperbarui!');
            }
        }
        
        if ($action === 'update_price') {
            $detailId = $_POST['detail_id'];
            $unitPriceRaw = $_POST['unit_price'] ?? '';
            
            if (!empty($unitPriceRaw)) {
                if (strpos($unitPriceRaw, '.') !== false && strpos($unitPriceRaw, ',') !== false) {
                    $unitPrice = floatval(str_replace(',', '.', str_replace('.', '', $unitPriceRaw)));
                } elseif (strpos($unitPriceRaw, '.') !== false && strlen($unitPriceRaw) - strrpos($unitPriceRaw, '.') == 4) {
                    $unitPrice = floatval(str_replace('.', '', $unitPriceRaw));
                } else {
                    $unitPrice = floatval(str_replace(',', '.', $unitPriceRaw));
                }
                
                dbExecute("UPDATE rab_snapshot_ahsp_details SET unit_price = ? WHERE id = ?", [$unitPrice, $detailId]);
                
                // Recalculate snapshot subcategory unit_price
                $totalPrice = dbGetRow("
                    SELECT SUM(coefficient * unit_price) as total 
                    FROM rab_snapshot_ahsp_details 
                    WHERE snapshot_subcategory_id = ?
                ", [$subcategoryId])['total'] ?? 0;
                dbExecute("UPDATE rab_snapshot_subcategories SET unit_price = ? WHERE id = ?", [$totalPrice, $subcategoryId]);
                
                setFlash('success', 'Harga satuan berhasil diperbarui!');
            }
        }
    } catch (Exception $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
    }
    
    header('Location: ahsp_snapshot.php?id=' . $subcategoryId);
    exit;
}

// Get AHSP details from snapshot table
$details = dbGetAll("
    SELECT d.*, i.name as item_name, i.category, i.unit, 
           i.price as item_up_price, i.actual_price as item_actual_price,
           (d.coefficient * d.unit_price) as total_price
    FROM rab_snapshot_ahsp_details d
    JOIN project_items i ON d.item_id = i.id
    WHERE d.snapshot_subcategory_id = ?
    ORDER BY i.category, i.name
", [$subcategoryId]);

// If no snapshot AHSP details exist, auto-populate from original AHSP
if (empty($details) && $subcategory['ahsp_id']) {
    $ahspId = $subcategory['ahsp_id'];
    
    // Copy AHSP details from original
    $originalDetails = dbGetAll("
        SELECT d.item_id, i.category, d.coefficient, COALESCE(d.unit_price, i.price) as unit_price
        FROM project_ahsp_details d
        JOIN project_items i ON d.item_id = i.id
        WHERE d.ahsp_id = ?
        ORDER BY i.category, d.id
    ", [$ahspId]);
    
    foreach ($originalDetails as $detail) {
        dbInsert("INSERT INTO rab_snapshot_ahsp_details (snapshot_subcategory_id, item_id, category, coefficient, unit_price, sort_order) VALUES (?, ?, ?, ?, ?, ?)",
            [$subcategoryId, $detail['item_id'], $detail['category'], $detail['coefficient'], $detail['unit_price'], 0]);
    }
    
    // Re-fetch the details
    $details = dbGetAll("
        SELECT d.*, i.name as item_name, i.category, i.unit, 
               i.price as item_up_price, i.actual_price as item_actual_price,
               (d.coefficient * d.unit_price) as total_price
        FROM rab_snapshot_ahsp_details d
        JOIN project_items i ON d.item_id = i.id
        WHERE d.snapshot_subcategory_id = ?
        ORDER BY i.category, i.name
    ", [$subcategoryId]);
}

// Group by category
$detailsByCategory = ['upah' => [], 'material' => [], 'alat' => []];
$totals = ['upah' => 0, 'material' => 0, 'alat' => 0];
foreach ($details as $detail) {
    $detailsByCategory[$detail['category']][] = $detail;
    $totals[$detail['category']] += $detail['total_price'];
}
$grandTotal = array_sum($totals);

$overheadPct = $subcategory['overhead_percentage'] ?? 10;
$overheadAmount = $grandTotal * ($overheadPct / 100);
$totalWithOverhead = $grandTotal + $overheadAmount;

$pageTitle = 'AHSP Snapshot - ' . $subcategory['name'];
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Title -->
<div class="row">
    <div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
            <h4 class="mb-sm-0">Analisa Harga Satuan Pekerjaan (Salinan)</h4>
            <div class="page-title-right">
                <ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>">PCM</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Proyek</a></li>
                    <li class="breadcrumb-item"><a href="view.php?id=<?= $projectId ?>"><?= sanitize($subcategory['project_name']) ?></a></li>
                    <li class="breadcrumb-item"><a href="rab_snapshot.php?id=<?= $snapshotId ?>"><?= sanitize($subcategory['snapshot_name']) ?></a></li>
                    <li class="breadcrumb-item active">AHSP</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Snapshot Notice -->
<div class="alert alert-info mb-3">
    <i class="mdi mdi-information-outline me-1"></i>
    <strong>Mode Salinan:</strong> Perubahan di sini hanya mempengaruhi salinan <strong><?= sanitize($subcategory['snapshot_name']) ?></strong>, tidak mengubah Master Data atau RAB asli.
</div>

<!-- Subcategory Info -->
<div class="card bg-light mb-3">
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <h5 class="mb-1"><?= sanitize($subcategory['code']) ?>. <?= sanitize($subcategory['name']) ?></h5>
                <p class="mb-0 text-muted">
                    Kategori: <?= sanitize($subcategory['category_code']) ?>. <?= sanitize($subcategory['category_name']) ?>
                </p>
            </div>
            <div class="col-md-6 text-md-end">
                <p class="mb-0">
                    Satuan: <strong><?= sanitize($subcategory['unit']) ?></strong><br>
                    Harga Satuan (D): <strong class="text-primary fs-5"><?= formatRupiah($subcategory['unit_price']) ?></strong>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- AHSP Details Table -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Rincian AHSP</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered mb-0">
                <thead>
                    <tr class="table-primary">
                        <th width="50">No</th>
                        <th>Uraian</th>
                        <th width="80">Satuan</th>
                        <th width="100" class="text-end">Koefisien</th>
                        <th width="130" class="text-end">Harga Satuan</th>
                        <th width="150" class="text-end">Jumlah Harga</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- A. TENAGA -->
                    <tr style="background-color: #e8f4fd;">
                        <td colspan="6"><strong>A. TENAGA</strong></td>
                    </tr>
                    <?php if (empty($detailsByCategory['upah'])): ?>
                    <tr><td colspan="6" class="text-center text-muted">Belum ada komponen tenaga</td></tr>
                    <?php else: ?>
                    <?php $no = 1; foreach ($detailsByCategory['upah'] as $detail): ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td><?= sanitize($detail['item_name']) ?></td>
                        <td><?= sanitize($detail['unit']) ?></td>
                        <td>
                            <form method="POST" class="inline-edit-form d-inline">
                                <input type="hidden" name="action" value="update_coefficient">
                                <input type="hidden" name="detail_id" value="<?= $detail['id'] ?>">
                                <input type="text" class="form-control form-control-sm border-0 text-end" 
                                       name="coefficient" 
                                       value="<?= formatNumber($detail['coefficient'], 4) ?>" 
                                       style="width:80px;">
                            </form>
                        </td>
                        <td>
                            <form method="POST" class="inline-edit-form d-inline">
                                <input type="hidden" name="action" value="update_price">
                                <input type="hidden" name="detail_id" value="<?= $detail['id'] ?>">
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
                            </form>
                        </td>
                        <td class="text-end"><?= formatRupiah($detail['total_price']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    <tr style="background-color: #d4edfc;">
                        <td colspan="5" class="text-end"><strong>JUMLAH TENAGA</strong></td>
                        <td class="text-end"><strong><?= formatRupiah($totals['upah']) ?></strong></td>
                    </tr>
                    
                    <!-- B. BAHAN -->
                    <tr style="background-color: #e8fde8;">
                        <td colspan="6"><strong>B. BAHAN</strong></td>
                    </tr>
                    <?php if (empty($detailsByCategory['material'])): ?>
                    <tr><td colspan="6" class="text-center text-muted">Belum ada komponen bahan</td></tr>
                    <?php else: ?>
                    <?php $no = 1; foreach ($detailsByCategory['material'] as $detail): ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td><?= sanitize($detail['item_name']) ?></td>
                        <td><?= sanitize($detail['unit']) ?></td>
                        <td>
                            <form method="POST" class="inline-edit-form d-inline">
                                <input type="hidden" name="action" value="update_coefficient">
                                <input type="hidden" name="detail_id" value="<?= $detail['id'] ?>">
                                <input type="text" class="form-control form-control-sm border-0 text-end" 
                                       name="coefficient" 
                                       value="<?= formatNumber($detail['coefficient'], 4) ?>" 
                                       style="width:80px;">
                            </form>
                        </td>
                        <td>
                            <form method="POST" class="inline-edit-form d-inline">
                                <input type="hidden" name="action" value="update_price">
                                <input type="hidden" name="detail_id" value="<?= $detail['id'] ?>">
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
                            </form>
                        </td>
                        <td class="text-end"><?= formatRupiah($detail['total_price']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    <tr style="background-color: #d4fcd4;">
                        <td colspan="5" class="text-end"><strong>JUMLAH BAHAN</strong></td>
                        <td class="text-end"><strong><?= formatRupiah($totals['material']) ?></strong></td>
                    </tr>
                    
                    <!-- C. PERALATAN -->
                    <tr style="background-color: #fde8e8;">
                        <td colspan="6"><strong>C. PERALATAN</strong></td>
                    </tr>
                    <?php if (empty($detailsByCategory['alat'])): ?>
                    <tr><td colspan="6" class="text-center text-muted">Belum ada komponen peralatan</td></tr>
                    <?php else: ?>
                    <?php $no = 1; foreach ($detailsByCategory['alat'] as $detail): ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td><?= sanitize($detail['item_name']) ?></td>
                        <td><?= sanitize($detail['unit']) ?></td>
                        <td>
                            <form method="POST" class="inline-edit-form d-inline">
                                <input type="hidden" name="action" value="update_coefficient">
                                <input type="hidden" name="detail_id" value="<?= $detail['id'] ?>">
                                <input type="text" class="form-control form-control-sm border-0 text-end" 
                                       name="coefficient" 
                                       value="<?= formatNumber($detail['coefficient'], 4) ?>" 
                                       style="width:80px;">
                            </form>
                        </td>
                        <td>
                            <form method="POST" class="inline-edit-form d-inline">
                                <input type="hidden" name="action" value="update_price">
                                <input type="hidden" name="detail_id" value="<?= $detail['id'] ?>">
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
                            </form>
                        </td>
                        <td class="text-end"><?= formatRupiah($detail['total_price']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    <tr style="background-color: #fcd4d4;">
                        <td colspan="5" class="text-end"><strong>JUMLAH PERALATAN</strong></td>
                        <td class="text-end"><strong><?= formatRupiah($totals['alat']) ?></strong></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="table-secondary">
                        <td colspan="5" class="text-end"><strong>D. JUMLAH (A+B+C)</strong></td>
                        <td class="text-end"><strong><?= formatRupiah($grandTotal) ?></strong></td>
                    </tr>
                    <tr class="table-light">
                        <td colspan="5" class="text-end"><strong>E. OVERHEAD & PROFIT (<?= $overheadPct ?>%)</strong></td>
                        <td class="text-end"><strong><?= formatRupiah($overheadAmount) ?></strong></td>
                    </tr>
                    <tr class="table-primary">
                        <td colspan="5" class="text-end"><strong>HARGA SATUAN PEKERJAAN (D+E)</strong></td>
                        <td class="text-end"><strong><?= formatRupiah($totalWithOverhead) ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    
    <!-- Footer Actions -->
    <div class="card-footer">
        <a href="rab_snapshot.php?id=<?= $snapshotId ?>" class="btn btn-secondary">
            <i class="mdi mdi-arrow-left"></i> Kembali ke Salinan RAB
        </a>
    </div>
</div>

<?php
$customScript = <<<SCRIPT
<script>
$(document).ready(function() {
    // Submit on Enter
    $('.inline-edit-form input').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $(this).closest('form').submit();
        }
    });
    
    // Auto-save on blur
    $('.inline-edit-form input').on('blur', function() {
        var form = $(this).closest('form');
        var input = $(this);
        var originalValue = input.data('original') || input.val();
        if (input.val() !== originalValue) {
            form.submit();
        }
    });
    
    // Store original values
    $('.inline-edit-form input').each(function() {
        $(this).data('original', $(this).val());
    });
    
    // Price option dropdown
    $('.price-option').on('click', function(e) {
        e.preventDefault();
        var price = $(this).data('price');
        var form = $(this).closest('form');
        form.find('.price-input').val(price);
        form.submit();
    });
});
</script>
SCRIPT;

require_once __DIR__ . '/../../includes/footer.php'; 
?>
