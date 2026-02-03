<?php
/**
 * RAB Snapshot Editor - View/Edit Salinan RAB
 * PCM - Project Cost Management System
 * Layout matches RAB exactly (minus action column)
 */

// AJAX Handler - must be first
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajax_update_snapshot_volume') {
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
        $id = intval($_POST['id'] ?? 0);
        $value = floatval($_POST['value'] ?? 0);
        
        dbExecute("UPDATE rab_snapshot_subcategories SET volume = ? WHERE id = ?", [$value, $id]);
        die(json_encode(['success' => true, 'message' => 'Volume tersimpan']));
    } catch (Exception $e) {
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();
requireAdmin();

$snapshotId = $_GET['id'] ?? null;

if (!$snapshotId) {
    header('Location: index.php');
    exit;
}

// Get snapshot with project info
$snapshot = dbGetRow("
    SELECT s.*, p.name as project_name, p.id as project_id, p.overhead_percentage, p.ppn_percentage as project_ppn,
           u.full_name as creator_name
    FROM rab_snapshots s
    JOIN projects p ON s.project_id = p.id
    LEFT JOIN users u ON s.created_by = u.id
    WHERE s.id = ?
", [$snapshotId]);

if (!$snapshot) {
    setFlash('error', 'Salinan RAB tidak ditemukan!');
    header('Location: index.php');
    exit;
}

$projectId = $snapshot['project_id'];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'update_volume':
                $subcatId = $_POST['subcategory_id'];
                $volumeRaw = $_POST['volume'];
                $volume = floatval(str_replace(',', '.', str_replace('.', '', $volumeRaw)));
                dbExecute("UPDATE rab_snapshot_subcategories SET volume = ? WHERE id = ?", [$volume, $subcatId]);
                setFlash('success', 'Volume berhasil diperbarui!');
                break;
                
            case 'update_snapshot':
                $name = trim($_POST['name']);
                $desc = trim($_POST['description'] ?? '');
                dbExecute("UPDATE rab_snapshots SET name = ?, description = ? WHERE id = ?", [$name, $desc, $snapshotId]);
                setFlash('success', 'Salinan berhasil diperbarui!');
                break;
        }
    } catch (Exception $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
    }
    
    header('Location: rab_snapshot.php?id=' . $snapshotId);
    exit;
}

// Function to get AHSP component breakdown from snapshot data
function getSnapshotAhspComponentBreakdown($snapshotSubcatId) {
    $result = ['upah' => 0, 'material' => 0, 'alat' => 0];
    
    if (!$snapshotSubcatId) return $result;
    
    $totals = dbGetAll("
        SELECT d.category, SUM(d.coefficient * d.unit_price) as total 
        FROM rab_snapshot_ahsp_details d 
        WHERE d.snapshot_subcategory_id = ?
        GROUP BY d.category
    ", [$snapshotSubcatId]);
    
    foreach ($totals as $row) {
        if (isset($result[$row['category']])) {
            $result[$row['category']] = $row['total'];
        }
    }
    
    return $result;
}

// Get snapshot categories and subcategories
$categories = dbGetAll("SELECT * FROM rab_snapshot_categories WHERE snapshot_id = ? ORDER BY sort_order, code", [$snapshotId]);

$rabData = [];
$grandTotal = 0;
$grandTotalTenaga = 0;
$grandTotalBahan = 0;
$grandTotalAlat = 0;

$overheadPct = $snapshot['overhead_percentage'] ?? 10;

foreach ($categories as $cat) {
    $subcats = dbGetAll("SELECT * FROM rab_snapshot_subcategories WHERE category_id = ? ORDER BY sort_order, code", [$cat['id']]);
    $catTotal = 0;
    $catTenaga = 0;
    $catBahan = 0;
    $catAlat = 0;
    
    $enrichedSubcats = [];
    foreach ($subcats as $sub) {
        // Calculate unit price with overhead
        $baseUnitPrice = $sub['unit_price'];
        $unitPriceWithOverhead = $baseUnitPrice * (1 + ($overheadPct / 100));
        $sub['unit_price_display'] = $unitPriceWithOverhead;
        
        $subTotal = $sub['volume'] * $unitPriceWithOverhead;
        $catTotal += $subTotal;
        
        // Get AHSP component breakdown from snapshot data
        $components = getSnapshotAhspComponentBreakdown($sub['id']);
        $sub['anggaran_tenaga'] = $components['upah'] * $sub['volume'];
        $sub['anggaran_bahan'] = $components['material'] * $sub['volume'];
        $sub['anggaran_alat'] = $components['alat'] * $sub['volume'];
        
        $catTenaga += $sub['anggaran_tenaga'];
        $catBahan += $sub['anggaran_bahan'];
        $catAlat += $sub['anggaran_alat'];
        
        $enrichedSubcats[] = $sub;
    }
    
    $grandTotal += $catTotal;
    $grandTotalTenaga += $catTenaga;
    $grandTotalBahan += $catBahan;
    $grandTotalAlat += $catAlat;
    
    $rabData[$cat['id']] = [
        'category' => $cat,
        'subcategories' => $enrichedSubcats,
        'total' => $catTotal,
        'total_tenaga' => $catTenaga,
        'total_bahan' => $catBahan,
        'total_alat' => $catAlat
    ];
}

// Calculate totals with PPN
$ppnPercentage = $snapshot['ppn_percentage'] ?? 11;
$ppnAmount = $grandTotal * ($ppnPercentage / 100);
$totalWithPpn = $grandTotal + $ppnAmount;
$totalRounded = ceil($totalWithPpn / 10) * 10;

$pageTitle = 'Salinan RAB - ' . $snapshot['name'];
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Title -->
<div class="row">
    <div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
            <h4 class="mb-sm-0">Salinan RAB: <?= sanitize($snapshot['name']) ?></h4>
            <div class="page-title-right">
                <ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>">PCM</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Proyek</a></li>
                    <li class="breadcrumb-item"><a href="view.php?id=<?= $projectId ?>"><?= sanitize($snapshot['project_name']) ?></a></li>
                    <li class="breadcrumb-item"><a href="rab.php?id=<?= $projectId ?>">RAB</a></li>
                    <li class="breadcrumb-item active"><?= sanitize($snapshot['name']) ?></li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Snapshot Info -->
<div class="alert alert-info d-flex justify-content-between align-items-center">
    <div>
        <a href="rab.php?id=<?= $projectId ?>" class="btn btn-secondary btn-sm me-3">
            <i class="mdi mdi-arrow-left"></i> Kembali ke RAB
        </a>
        <i class="mdi mdi-content-copy me-1"></i>
        <strong><?= sanitize($snapshot['name']) ?></strong>
        <span class="ms-2 text-muted">
            Dibuat: <?= date('d M Y H:i', strtotime($snapshot['created_at'])) ?>
            <?php if ($snapshot['creator_name']): ?>
            oleh <?= sanitize($snapshot['creator_name']) ?>
            <?php endif; ?>
        </span>
        <?php if ($snapshot['description']): ?>
        <span class="ms-2">- <?= sanitize($snapshot['description']) ?></span>
        <?php endif; ?>
    </div>
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editSnapshotModal">
        <i class="mdi mdi-pencil"></i> Edit Info
    </button>
</div>

<!-- RAB Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><?= sanitize($snapshot['project_name']) ?> - Salinan</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered mb-0">
                <thead class="table-dark">
                    <tr>
                        <th width="80">No</th>
                        <th>Uraian Pekerjaan</th>
                        <th width="80">Satuan</th>
                        <th width="100" class="text-end">Volume</th>
                        <th width="150" class="text-end">Harga Satuan</th>
                        <th width="150" class="text-end">Jumlah Harga</th>
                        <th width="120" class="text-end">Tenaga</th>
                        <th width="120" class="text-end">Bahan</th>
                        <th width="120" class="text-end">Peralatan</th>
                        <th width="80">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rabData)): ?>
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">
                            Tidak ada data dalam salinan ini.
                        </td>
                    </tr>
                    <?php else: ?>
                    
                    <?php foreach ($rabData as $catId => $data): 
                        $cat = $data['category'];
                        $subcats = $data['subcategories'];
                        $catTotal = $data['total'];
                        $catTenaga = $data['total_tenaga'];
                        $catBahan = $data['total_bahan'];
                        $catAlat = $data['total_alat'];
                    ?>
                    <!-- Category Header -->
                    <tr class="table-primary">
                        <td colspan="10">
                            <strong><?= sanitize($cat['code']) ?>. <?= sanitize($cat['name']) ?></strong>
                        </td>
                    </tr>
                    
                    <!-- Subcategories -->
                    <?php foreach ($subcats as $sub): ?>
                        <tr data-category-id="<?= $cat['id'] ?>">
                            <td><?= sanitize($sub['code']) ?></td>
                            <td><?= sanitize($sub['name']) ?></td>
                            <td><?= sanitize($sub['unit']) ?></td>
                            <td>
                                <input type="text" 
                                       class="form-control form-control-sm border-0 text-end inline-ajax" 
                                       value="<?= formatVolume($sub['volume']) ?>" 
                                       style="width:80px;"
                                       data-ajax-url="rab_snapshot.php?id=<?= $snapshotId ?>"
                                       data-action="ajax_update_snapshot_volume"
                                       data-id="<?= $sub['id'] ?>"
                                       data-field="volume"
                                       data-format="decimal"
                                       data-unit-price="<?= $sub['unit_price_display'] ?>"
                                       data-unit-price-tenaga="<?= $sub['ahsp_tenaga'] ?? 0 ?>"
                                       data-unit-price-bahan="<?= $sub['ahsp_bahan'] ?? 0 ?>"
                                       data-unit-price-alat="<?= $sub['ahsp_alat'] ?? 0 ?>">
                            </td>
                            <td class="text-end"><?= formatNumber($sub['unit_price_display']) ?></td>
                            <td class="text-end" id="jumlah-<?= $sub['id'] ?>"><?= formatNumber($sub['volume'] * $sub['unit_price_display']) ?></td>
                            <td class="text-end" id="tenaga-<?= $sub['id'] ?>"><?= formatNumber($sub['anggaran_tenaga']) ?></td>
                            <td class="text-end" id="bahan-<?= $sub['id'] ?>"><?= formatNumber($sub['anggaran_bahan']) ?></td>
                            <td class="text-end" id="alat-<?= $sub['id'] ?>"><?= formatNumber($sub['anggaran_alat']) ?></td>
                            <td>
                                <a href="ahsp_snapshot.php?id=<?= $sub['id'] ?>" class="btn btn-sm btn-secondary" title="Lihat AHSP">
                                    <i class="mdi mdi-file-table-outline"></i> AHSP
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <!-- Category Total -->
                    <tr class="table-secondary">
                        <td colspan="5" class="text-end"><strong>JUMLAH <?= sanitize($cat['code']) ?></strong></td>
                        <td class="text-end" id="cat-total-<?= $cat['id'] ?>"><strong><?= formatNumber($catTotal) ?></strong></td>
                        <td class="text-end" id="cat-tenaga-<?= $cat['id'] ?>"><strong><?= formatNumber($catTenaga) ?></strong></td>
                        <td class="text-end" id="cat-bahan-<?= $cat['id'] ?>"><strong><?= formatNumber($catBahan) ?></strong></td>
                        <td class="text-end" id="cat-alat-<?= $cat['id'] ?>"><strong><?= formatNumber($catAlat) ?></strong></td>
                        <td></td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <!-- Grand Total Row -->
                    <tr class="table-dark">
                        <td colspan="5" class="text-end"><strong>JUMLAH TOTAL</strong></td>
                        <td class="text-end" id="grand-total"><strong><?= formatNumber($grandTotal) ?></strong></td>
                        <td class="text-end" id="grand-tenaga"><strong><?= formatNumber($grandTotalTenaga) ?></strong></td>
                        <td class="text-end" id="grand-bahan"><strong><?= formatNumber($grandTotalBahan) ?></strong></td>
                        <td class="text-end" id="grand-alat"><strong><?= formatNumber($grandTotalAlat) ?></strong></td>
                        <td></td>
                    </tr>
                    <!-- PPN Row -->
                    <tr class="table-light">
                        <td colspan="5" class="text-end"><strong>PPN <?= formatNumber($ppnPercentage, 2) ?>%</strong></td>
                        <td class="text-end"><strong><?= formatNumber($ppnAmount) ?></strong></td>
                        <td colspan="4"></td>
                    </tr>
                    <!-- Total + PPN -->
                    <tr class="table-light">
                        <td colspan="5" class="text-end"><strong>JUMLAH TOTAL (TERMASUK PPN)</strong></td>
                        <td class="text-end"><strong><?= formatNumber($totalWithPpn) ?></strong></td>
                        <td colspan="4"></td>
                    </tr>
                    <!-- Rounded Total -->
                    <tr class="table-primary">
                        <td colspan="5" class="text-end"><strong>JUMLAH TOTAL DIBULATKAN</strong></td>
                        <td class="text-end"><strong><?= formatRupiah($totalRounded) ?></strong></td>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    
    <!-- Footer Actions -->
    <div class="card-footer">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <a href="rab.php?id=<?= $projectId ?>" class="btn btn-secondary">
                    <i class="mdi mdi-arrow-left"></i> Kembali ke RAB
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Edit Snapshot Modal -->
<div class="modal fade" id="editSnapshotModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="update_snapshot">
            <div class="modal-header">
                <h5 class="modal-title">Edit Info Salinan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label required">Nama Salinan</label>
                    <input type="text" class="form-control" name="name" value="<?= sanitize($snapshot['name']) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Deskripsi (Opsional)</label>
                    <textarea class="form-control" name="description" rows="2"><?= sanitize($snapshot['description']) ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<?php
$customScript = <<<SCRIPT
<script>
// Submit inline form on Enter key
$(document).ready(function() {
    $('.inline-edit-form input[type="text"]').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $(this).closest('form').submit();
        }
    });
    
    // Auto-save on blur
    $('.inline-edit-form input[type="text"]').on('blur', function() {
        var form = $(this).closest('form');
        var input = $(this);
        var originalValue = input.data('original') || input.val();
        if (input.val() !== originalValue) {
            form.submit();
        }
    });
    
    $('.inline-edit-form input[type="text"]').each(function() {
        $(this).data('original', $(this).val());
    });
});
</script>
SCRIPT;

require_once __DIR__ . '/../../includes/footer.php'; 
?>
