<?php
/**
 * AHSP Viewer - View/Edit AHSP details for RAB subcategory
 * PCM - Project Cost Management System
 */

// AJAX Handler - must be first
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajax_update_ahsp_coefficient') {
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
        
        // Get detail info to update parent
        $detail = dbGetRow("SELECT ahsp_id FROM project_ahsp_details WHERE id = ?", [$detailId]);
        if (!$detail) {
            die(json_encode(['success' => false, 'message' => 'Detail not found']));
        }
        
        // Update coefficient
        dbExecute("UPDATE project_ahsp_details SET coefficient = ? WHERE id = ?", [$value, $detailId]);
        
        // Recalculate AHSP total
        $totalPrice = dbGetRow("
            SELECT SUM(d.coefficient * COALESCE(d.unit_price, i.price)) as total
            FROM project_ahsp_details d
            JOIN project_items i ON d.item_id = i.id
            WHERE d.ahsp_id = ?
        ", [$detail['ahsp_id']])['total'] ?? 0;
        
        dbExecute("UPDATE project_ahsp SET unit_price = ? WHERE id = ?", [$totalPrice, $detail['ahsp_id']]);
        dbExecute("UPDATE rab_subcategories SET unit_price = ? WHERE ahsp_id = ?", [$totalPrice, $detail['ahsp_id']]);
        
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

// Get subcategory with related data
$subcategory = dbGetRow("
    SELECT rs.*, rc.project_id, rc.name as category_name, rc.code as category_code,
           pa.work_name as ahsp_work_name, pa.unit as ahsp_unit, pa.unit_price as ahsp_unit_price
    FROM rab_subcategories rs
    JOIN rab_categories rc ON rs.category_id = rc.id
    LEFT JOIN project_ahsp pa ON rs.ahsp_id = pa.id
    WHERE rs.id = ?
", [$subcategoryId]);

if (!$subcategory) {
    setFlash('error', 'Sub-kategori tidak ditemukan!');
    header('Location: index.php');
    exit;
}

$projectId = $subcategory['project_id'];
$ahspId = $subcategory['ahsp_id'];

// Get project
$project = dbGetRow("SELECT * FROM projects WHERE id = ?", [$projectId]);
$isEditable = ($project['status'] === 'draft' && !$project['rab_submitted']);

// Handle POST actions (only if editable)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isEditable && $ahspId) {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'update_coefficient') {
            $detailId = $_POST['detail_id'];
            $coeffRaw = $_POST['coefficient'];
            $coefficient = floatval(str_replace(',', '.', str_replace('.', '', $coeffRaw)));
            
            if ($coefficient > 0) {
                dbExecute("UPDATE project_ahsp_details SET coefficient = ? WHERE id = ?",
                    [$coefficient, $detailId]);
                    
                // Recalculate AHSP unit_price
                $totalPrice = dbGetRow("
                    SELECT SUM(d.coefficient * COALESCE(d.unit_price, i.price)) as total 
                    FROM project_ahsp_details d 
                    JOIN project_items i ON d.item_id = i.id 
                    WHERE d.ahsp_id = ?
                ", [$ahspId])['total'] ?? 0;
                dbExecute("UPDATE project_ahsp SET unit_price = ? WHERE id = ?", [$totalPrice, $ahspId]);
                
                // Sync to rab_subcategories that use this AHSP
                dbExecute("UPDATE rab_subcategories SET unit_price = ? WHERE ahsp_id = ?", [$totalPrice, $ahspId]);
                
                setFlash('success', 'Koefisien berhasil diperbarui!');
            }
        }
        
        if ($action === 'update_price') {
            $detailId = $_POST['detail_id'];
            $unitPriceRaw = $_POST['unit_price'] ?? '';
            
            // Parse unit_price - handle both raw number and Indonesian format
            if (!empty($unitPriceRaw)) {
                if (strpos($unitPriceRaw, '.') !== false && strpos($unitPriceRaw, ',') !== false) {
                    $unitPrice = floatval(str_replace(',', '.', str_replace('.', '', $unitPriceRaw)));
                } elseif (strpos($unitPriceRaw, '.') !== false && strlen($unitPriceRaw) - strrpos($unitPriceRaw, '.') == 4) {
                    $unitPrice = floatval(str_replace('.', '', $unitPriceRaw));
                } else {
                    $unitPrice = floatval(str_replace(',', '.', $unitPriceRaw));
                }
                
                dbExecute("UPDATE project_ahsp_details SET unit_price = ? WHERE id = ?", [$unitPrice, $detailId]);
                
                // Recalculate AHSP total
                $totalPrice = dbGetRow("
                    SELECT SUM(d.coefficient * COALESCE(d.unit_price, i.price)) as total 
                    FROM project_ahsp_details d 
                    JOIN project_items i ON d.item_id = i.id 
                    WHERE d.ahsp_id = ?
                ", [$ahspId])['total'] ?? 0;
                dbExecute("UPDATE project_ahsp SET unit_price = ? WHERE id = ?", [$totalPrice, $ahspId]);
                
                // Sync to rab_subcategories that use this AHSP
                dbExecute("UPDATE rab_subcategories SET unit_price = ? WHERE ahsp_id = ?", [$totalPrice, $ahspId]);
                
                setFlash('success', 'Harga satuan berhasil diperbarui!');
            }
        }
    } catch (Exception $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
    }
    
    header('Location: ahsp.php?id=' . $subcategoryId);
    exit;
}

// Get AHSP details
$details = dbGetAll("
    SELECT d.*, i.name as item_name, i.category, i.unit, 
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

$pageTitle = 'AHSP - ' . $subcategory['name'];
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Title -->
<div class="row">
    <div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
            <h4 class="mb-sm-0">Analisa Harga Satuan Pekerjaan</h4>
            <div class="page-title-right">
                <ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>">PCM</a></li>
                    <li class="breadcrumb-item"><a href="view.php?id=<?= $projectId ?>"><?= sanitize($project['name']) ?></a></li>
                    <li class="breadcrumb-item"><a href="rab.php?id=<?= $projectId ?>">RAB</a></li>
                    <li class="breadcrumb-item active">AHSP</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Subcategory Info -->
<div class="card bg-light mb-3">
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <h5 class="mb-1"><?= sanitize($subcategory['code']) ?>. <?= sanitize($subcategory['name']) ?></h5>
                <p class="text-muted mb-0">
                    Kategori: <?= sanitize($subcategory['category_code']) ?>. <?= sanitize($subcategory['category_name']) ?>
                </p>
            </div>
            <div class="col-md-6 text-md-end">
                <p class="mb-1">
                    <span class="text-muted">Satuan:</span> <strong><?= sanitize($subcategory['unit']) ?></strong>
                </p>
                <p class="mb-0">
                    <span class="text-muted">Harga Satuan:</span> 
                    <strong class="text-primary fs-5"><?= formatRupiah($subcategory['unit_price']) ?></strong>
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
        <?php 
        $overheadPct = $project['overhead_percentage'] ?? 10;
        $overheadAmount = $grandTotal * ($overheadPct / 100);
        $totalWithOverhead = $grandTotal + $overheadAmount;
        ?>
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
                            <input type="text" 
                                   class="form-control form-control-sm border-0 text-end inline-ajax" 
                                   value="<?= formatNumber($detail['coefficient'], 4) ?>" 
                                   style="width:80px;"
                                   data-ajax-url="ahsp.php?id=<?= $subcategoryId ?>"
                                   data-action="ajax_update_ahsp_coefficient"
                                   data-id="<?= $detail['id'] ?>"
                                   data-field="coefficient"
                                   data-format="decimal"
                                   data-decimals="4"
                                   <?= !$isEditable ? 'disabled' : '' ?>>
                        </td>
                        <td>
                            <?php if ($isEditable): ?>
                            <form method="POST" class="inline-edit-form d-inline">
                                <input type="hidden" name="action" value="update_price">
                                <input type="hidden" name="detail_id" value="<?= $detail['id'] ?>">
                                <div class="d-flex align-items-center">
                                    <input type="text" class="form-control form-control-sm text-end price-input" 
                                           name="unit_price" 
                                           value="<?= formatNumber($detail['effective_price'], 2) ?>" 
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
                            <?php else: ?>
                            <span class="text-end d-block"><?= formatRupiah($detail['effective_price']) ?></span>
                            <?php endif; ?>
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
                            <input type="text" 
                                   class="form-control form-control-sm border-0 text-end inline-ajax" 
                                   value="<?= formatNumber($detail['coefficient'], 4) ?>" 
                                   style="width:80px;"
                                   data-ajax-url="ahsp.php?id=<?= $subcategoryId ?>"
                                   data-action="ajax_update_ahsp_coefficient"
                                   data-id="<?= $detail['id'] ?>"
                                   data-field="coefficient"
                                   data-format="decimal"
                                   data-decimals="4"
                                   <?= !$isEditable ? 'disabled' : '' ?>>
                        </td>
                        <td>
                            <?php if ($isEditable): ?>
                            <form method="POST" class="inline-edit-form d-inline">
                                <input type="hidden" name="action" value="update_price">
                                <input type="hidden" name="detail_id" value="<?= $detail['id'] ?>">
                                <div class="d-flex align-items-center">
                                    <input type="text" class="form-control form-control-sm text-end price-input" 
                                           name="unit_price" 
                                           value="<?= formatNumber($detail['effective_price'], 2) ?>" 
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
                            <?php else: ?>
                            <span class="text-end d-block"><?= formatRupiah($detail['effective_price']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= formatRupiah($detail['total_price']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    <tr style="background-color: #c8f7c8;">
                        <td colspan="5" class="text-end"><strong>JUMLAH BAHAN</strong></td>
                        <td class="text-end"><strong><?= formatRupiah($totals['material']) ?></strong></td>
                    </tr>
                    
                    <!-- C. PERALATAN -->
                    <tr style="background-color: #fdf8e8;">
                        <td colspan="6"><strong>C. PERALATAN</strong></td>
                    </tr>
                    <?php if (empty($detailsByCategory['alat'])): ?>
                    <tr><td colspan="6" class="text-center text-muted">Belum ada komponen alat</td></tr>
                    <?php else: ?>
                    <?php $no = 1; foreach ($detailsByCategory['alat'] as $detail): ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td><?= sanitize($detail['item_name']) ?></td>
                        <td><?= sanitize($detail['unit']) ?></td>
                        <td>
                            <input type="text" 
                                   class="form-control form-control-sm border-0 text-end inline-ajax" 
                                   value="<?= formatNumber($detail['coefficient'], 4) ?>" 
                                   style="width:80px;"
                                   data-ajax-url="ahsp.php?id=<?= $subcategoryId ?>"
                                   data-action="ajax_update_ahsp_coefficient"
                                   data-id="<?= $detail['id'] ?>"
                                   data-field="coefficient"
                                   data-format="decimal"
                                   data-decimals="4"
                                   <?= !$isEditable ? 'disabled' : '' ?>>
                        </td>
                        <td>
                            <?php if ($isEditable): ?>
                            <form method="POST" class="inline-edit-form d-inline">
                                <input type="hidden" name="action" value="update_price">
                                <input type="hidden" name="detail_id" value="<?= $detail['id'] ?>">
                                <div class="d-flex align-items-center">
                                    <input type="text" class="form-control form-control-sm text-end price-input" 
                                           name="unit_price" 
                                           value="<?= formatNumber($detail['effective_price'], 2) ?>" 
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
                            <?php else: ?>
                            <span class="text-end d-block"><?= formatRupiah($detail['effective_price']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= formatRupiah($detail['total_price']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    <tr style="background-color: #f5edc8;">
                        <td colspan="5" class="text-end"><strong>JUMLAH ALAT</strong></td>
                        <td class="text-end"><strong><?= formatRupiah($totals['alat']) ?></strong></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="table-secondary">
                        <td colspan="5" class="text-end"><strong>D. Jumlah (A+B+C)</strong></td>
                        <td class="text-end"><strong><?= formatRupiah($grandTotal) ?></strong></td>
                    </tr>
                    <tr>
                        <td colspan="5" class="text-end"><strong>E. Overhead & Profit (<?= $overheadPct ?>%)</strong></td>
                        <td class="text-end"><?= formatRupiah($overheadAmount) ?></td>
                    </tr>
                    <tr class="table-dark">
                        <td colspan="5" class="text-end"><strong>HARGA SATUAN PEKERJAAN (D+E)</strong></td>
                        <td class="text-end"><strong><?= formatRupiah($totalWithOverhead) ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <div class="card-footer">
        <a href="rab.php?id=<?= $projectId ?>" class="btn btn-secondary">
            <i class="mdi mdi-arrow-left"></i> Kembali ke RAB
        </a>
        <a href="view.php?id=<?= $projectId ?>&tab=master&subtab=ahsp&ahsp_id=<?= $ahspId ?>" class="btn btn-outline-primary">
            <i class="mdi mdi-pencil"></i> Edit di Master Data
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
            var price = parseFloat(this.dataset.price);
            // Find the form and input - use closest form instead of non-existent class
            var form = this.closest('form');
            var input = form ? form.querySelector('.price-input') : null;
            
            console.log('Price dropdown clicked:', price, 'Form found:', !!form, 'Input found:', !!input);
            
            if (input && price) {
                // Format number with Indonesian format
                input.value = price.toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                // Trigger form submit
                if (form) form.submit();
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
