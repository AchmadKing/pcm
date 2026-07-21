<?php
/**
 * RAP Tab - Embedded in Project View
 * Displays RAP table with inline editing
 * Variables available from parent view.php: $project, $projectId
 */

// Get available RAB snapshots for this project
$rabSnapshots = dbGetAll("SELECT id, name, created_at FROM rab_snapshots WHERE project_id = ? ORDER BY created_at DESC", [$projectId]);

// Determine which RAB source to use for comparison
$storedSourceId = intval($project['rap_source_id'] ?? 0);
$rabSourceId = isset($_GET['rab_source']) ? intval($_GET['rab_source']) : $storedSourceId;
$rabSourceName = 'RAB Asli';
$usingSnapshot = false;

if ($rabSourceId > 0) {
    $selectedSnapshot = dbGetRow("SELECT id, name FROM rab_snapshots WHERE id = ? AND project_id = ?", [$rabSourceId, $projectId]);
    if ($selectedSnapshot) {
        $rabSourceName = $selectedSnapshot['name'];
        $usingSnapshot = true;
    } else {
        $rabSourceId = 0;
    }
}

// Function to get RAP AHSP component breakdown from Master Data RAP
// Uses AHSP code matching to get data from project_ahsp_rap / project_ahsp_details_rap
function getRapAhspComponentBreakdown($ahspCode, $projectId) {
    $result = ['upah' => 0, 'material' => 0, 'alat' => 0];
    
    if (!$ahspCode) return $result;
    
    // Find matching AHSP RAP by code
    $ahspRap = dbGetRow("
        SELECT id FROM project_ahsp_rap 
        WHERE project_id = ? AND ahsp_code = ?
    ", [$projectId, $ahspCode]);
    
    if (!$ahspRap) return $result;
    
    // Get component breakdown from project_ahsp_details_rap
    $totals = dbGetAll("
        SELECT i.category, SUM(d.coefficient * COALESCE(d.unit_price, i.price)) as total 
        FROM project_ahsp_details_rap d
        JOIN project_items_rap i ON d.item_id = i.id
        WHERE d.ahsp_id = ?
        GROUP BY i.category
    ", [$ahspRap['id']]);
    
    foreach ($totals as $row) {
        if (isset($result[$row['category']])) {
            $result[$row['category']] = $row['total'];
        }
    }
    
    return $result;
}


// Function to get RAB AHSP component breakdown (dynamically calculated)
// Copied from rab.php to ensure consistency
function getAhspComponentBreakdown($ahspId) {
    $result = ['upah' => 0, 'material' => 0, 'alat' => 0, 'total' => 0];
    
    if (!$ahspId) return $result;
    
    $totals = dbGetAll("
        SELECT i.category, SUM(d.coefficient * COALESCE(d.unit_price, i.price)) as total 
        FROM project_ahsp_details d 
        JOIN project_items i ON d.item_id = i.id 
        WHERE d.ahsp_id = ?
        GROUP BY i.category
    ", [$ahspId]);
    
    foreach ($totals as $row) {
        if (isset($result[$row['category']])) {
            $result[$row['category']] = $row['total'];
        }
        $result['total'] += $row['total'];
    }
    
    return $result;
}

// Get RAP data grouped by category
$categories = dbGetAll("SELECT * FROM rab_categories WHERE project_id = ? ORDER BY sort_order, code", [$projectId]);

$rapData = [];
$grandTotal = 0;
$grandTotalTenaga = 0;
$grandTotalBahan = 0;
$grandTotalAlat = 0;

foreach ($categories as $cat) {
    $subcats = dbGetAll("
        SELECT rs.*, rap.id as rap_id, rap.volume as rap_volume, rap.unit_price as rap_unit_price,
               pa.ahsp_code as ahsp_code
        FROM rab_subcategories rs
        LEFT JOIN rap_items rap ON rs.id = rap.subcategory_id
        LEFT JOIN project_ahsp pa ON rs.ahsp_id = pa.id
        WHERE rs.category_id = ? 
        ORDER BY rs.sort_order, rs.code
    ", [$cat['id']]);
    
    $catTotal = 0;
    $catTenaga = 0;
    $catBahan = 0;
    $catAlat = 0;
    $catRabTotal = 0;
    
    $overheadPct = $project['overhead_percentage'] ?? 10;
    
    $enrichedSubcats = [];
    foreach ($subcats as $sub) {
        $volume = $sub['rap_volume'] ?? $sub['volume'];
        
        // Get AHSP component breakdown from Master Data RAP
        $ahspCode = $sub['ahsp_code'] ?? null;
        if ($ahspCode) {
            $components = getRapAhspComponentBreakdown($ahspCode, $projectId);
        } else {
            $components = ['upah' => 0, 'material' => 0, 'alat' => 0];
        }
        
        // Derive baseUnitPrice directly from component totals (D = A+B+C)
        // This guarantees: harga satuan = tenaga + bahan + alat (same as AHSP RAP master data)
        $baseUnitPrice = $components['upah'] + $components['material'] + $components['alat'];
        
        // If no AHSP RAP data available, fallback to stored value
        if ($baseUnitPrice <= 0) {
            $baseUnitPrice = $sub['rap_unit_price'] ?? $sub['unit_price'];
        }
        
        $unitPriceWithOverhead = $baseUnitPrice * (1 + ($overheadPct / 100));
        $subTotal = $volume * $unitPriceWithOverhead;
        $catTotal += $subTotal;
        
        // Calculate RAB total for comparison
        $rabVolume = $sub['volume'];
        
        if ($usingSnapshot) {
             // If using snapshot, use stored price
            $rabPrice = $sub['unit_price'];
        } else {
             // If NOT using snapshot (Current RAB), calculate dynamically from AHSP components
             // This matches logic in rab.php
             if ($sub['ahsp_id']) {
                 $rabComponents = getAhspComponentBreakdown($sub['ahsp_id']);
                 $rabPrice = $rabComponents['total'];
             } else {
                 $rabPrice = $sub['unit_price'];
             }
        }
        
        if ($usingSnapshot && $sub['id']) {
            $snapSubcat = dbGetRow("
                SELECT ss.volume, ss.unit_price 
                FROM rab_snapshot_subcategories ss
                JOIN rab_snapshot_categories sc ON ss.category_id = sc.id
                WHERE sc.snapshot_id = ? AND ss.original_subcategory_id = ?
            ", [$rabSourceId, $sub['id']]);
            if ($snapSubcat) {
                $rabVolume = $snapSubcat['volume'];
                $rabPrice = $snapSubcat['unit_price'];
            }
        }
        
        $rabUnitPriceWithOverhead = $rabPrice * (1 + ($overheadPct / 100));
        $rabTotal = $rabVolume * $rabUnitPriceWithOverhead;
        $sub['rab_total'] = $rabTotal;
        $sub['selisih'] = $rabTotal - $subTotal;
        $catRabTotal += $rabTotal;
        
        // Component budgets = component unit price × volume (without overhead)
        $sub['display_volume'] = $volume;
        $sub['display_unit_price'] = $unitPriceWithOverhead;
        $sub['display_total'] = $subTotal;
        $sub['anggaran_tenaga'] = $components['upah'] * $volume;
        $sub['anggaran_bahan'] = $components['material'] * $volume;
        $sub['anggaran_alat'] = $components['alat'] * $volume;
        
        $catTenaga += $sub['anggaran_tenaga'];
        $catBahan += $sub['anggaran_bahan'];
        $catAlat += $sub['anggaran_alat'];
        
        $enrichedSubcats[] = $sub;
    }
    
    $grandTotal += $catTotal;
    $grandTotalTenaga += $catTenaga;
    $grandTotalBahan += $catBahan;
    $grandTotalAlat += $catAlat;
    
    $rapData[$cat['id']] = [
        'category' => $cat,
        'subcategories' => $enrichedSubcats,
        'total' => $catTotal,
        'total_tenaga' => $catTenaga,
        'total_bahan' => $catBahan,
        'total_alat' => $catAlat,
        'rab_total' => $catRabTotal,
        'selisih' => $catRabTotal - $catTotal
    ];
}

// Check if there are RAB items but no RAP items
$rabSubcatCount = dbGetRow("
    SELECT COUNT(*) as cnt FROM rab_subcategories rs
    JOIN rab_categories rc ON rs.category_id = rc.id
    WHERE rc.project_id = ?
", [$projectId])['cnt'] ?? 0;

$rapItemCount = dbGetRow("
    SELECT COUNT(*) as cnt FROM rap_items rap
    JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
    JOIN rab_categories rc ON rs.category_id = rc.id
    WHERE rc.project_id = ?
", [$projectId])['cnt'] ?? 0;

$needsSync = ($rabSubcatCount > 0 && $rapItemCount < $rabSubcatCount);

// RAP can be edited when project is in draft status and not locked
$isEditable = ($project['status'] === 'draft' && !isProjectLocked($project));

// Calculate PPN
$ppnPercentage = $project['ppn_percentage'];
$ppnAmount = $grandTotal * ($ppnPercentage / 100);
$totalWithPpn = $grandTotal + $ppnAmount;
$totalRounded = ceil($totalWithPpn / 10) * 10;

// Calculate grand RAB total for comparison
$grandRabTotal = 0;
foreach ($rapData as $data) {
    $grandRabTotal += $data['rab_total'];
}
$grandSelisih = $grandRabTotal - $grandTotal;

// Calculate RAB totals with PPN for rounded comparison
$rabPpnAmount = $grandRabTotal * ($ppnPercentage / 100);
$rabTotalWithPpn = $grandRabTotal + $rabPpnAmount;
$rabTotalRounded = ceil($rabTotalWithPpn / 10) * 10;
$selisihRounded = $rabTotalRounded - $totalRounded;
?>

<?php if ($needsSync): ?>
<!-- Sync Prompt -->
<div class="alert alert-warning d-flex justify-content-between align-items-center">
    <span><i class="mdi mdi-sync-alert"></i> Ada <?= $rabSubcatCount - $rapItemCount ?> item RAB yang belum tersinkron ke RAP.</span>
    <form method="POST" class="d-inline">
        <input type="hidden" name="action" value="generate_rap">
        <button type="submit" class="btn btn-success">
            <i class="mdi mdi-sync"></i> Sinkronkan Sekarang
        </button>
    </form>
</div>
<?php endif; ?>

<!-- Action Buttons -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h5 class="mb-0"><?= sanitize($project['name']) ?></h5>
    <div class="d-flex flex-wrap gap-2">
        <!-- Acuan RAB Dropdown -->
        <div class="dropdown">
            <button class="btn btn-sm btn-info dropdown-toggle text-nowrap" type="button" data-bs-toggle="dropdown">
                <i class="mdi mdi-file-document-outline"></i> Acuan: <?= sanitize($rabSourceName) ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end" style="min-width: 280px;">
                <li><h6 class="dropdown-header">Pilih Acuan RAB untuk RAP</h6></li>
                <li>
                    <a class="dropdown-item d-flex justify-content-between align-items-center <?= $rabSourceId == 0 ? 'active' : '' ?>" 
                       href="javascript:void(0);" onclick="confirmSyncReference(0, 'RAB Asli')">
                        <span><i class="mdi mdi-file-document me-2"></i>RAB Asli</span>
                        <?php if ($rabSourceId == 0): ?><i class="mdi mdi-check"></i><?php endif; ?>
                    </a>
                </li>
                <?php if (!empty($rabSnapshots)): ?>
                <li><hr class="dropdown-divider"></li>
                <li><h6 class="dropdown-header">Salinan RAB</h6></li>
                <?php foreach ($rabSnapshots as $snap): ?>
                <li>
                    <a class="dropdown-item d-flex justify-content-between align-items-center <?= $rabSourceId == $snap['id'] ? 'active' : '' ?>" 
                       href="javascript:void(0);" onclick="confirmSyncReference(<?= $snap['id'] ?>, '<?= sanitize($snap['name']) ?>')">
                        <div>
                            <div><i class="mdi mdi-content-copy me-2"></i><?= sanitize($snap['name']) ?></div>
                            <small class="text-muted"><?= date('d M Y', strtotime($snap['created_at'])) ?></small>
                        </div>
                        <?php if ($rabSourceId == $snap['id']): ?><i class="mdi mdi-check"></i><?php endif; ?>
                    </a>
                </li>
                <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
        
        <?php if ($project['status'] === 'draft'): ?>
        <button class="btn btn-outline-success btn-sm text-nowrap" data-bs-toggle="modal" data-bs-target="#importRapModal">
            <i class="mdi mdi-upload"></i> Import CSV
        </button>
        <?php endif; ?>
        
        <!-- Export Dropdown -->
        <div class="dropdown d-inline-block">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle text-nowrap" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="mdi mdi-file-export-outline"></i> Export
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><h6 class="dropdown-header">Export CSV</h6></li>
                <li>
                    <a class="dropdown-item" href="export_rap.php?id=<?= $projectId ?>&format=report">
                        <i class="mdi mdi-file-document-outline"></i> Export CSV Laporan
                    </a>
                </li>
                <?php if ($project['status'] === 'draft'): ?>
                <li>
                    <a class="dropdown-item" href="export_rap.php?id=<?= $projectId ?>&format=import">
                        <i class="mdi mdi-file-upload-outline"></i> CSV untuk Import
                    </a>
                </li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li><h6 class="dropdown-header">Export PDF</h6></li>
                <li>
                    <a class="dropdown-item" href="javascript:void(0);" onclick="openPdfPreviewRap()">
                        <i class="mdi mdi-file-pdf-box text-danger"></i> Export PDF
                        <small class="d-block text-muted">Preview laporan lalu cetak/export ke PDF</small>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>

<!-- RAP Table -->
<div class="table-responsive">
    <table class="table table-bordered mb-0" id="rapTable">
        <thead class="table-dark">
            <tr>
                <th width="80">No</th>
                <th>Uraian Pekerjaan</th>
                <th width="80">Satuan</th>
                <th width="90" class="text-end">Volume</th>
                <th width="130" class="text-end">Harga Satuan</th>
                <th width="130" class="text-end">Jumlah Harga</th>
                <th width="100" class="text-end">Tenaga</th>
                <th width="100" class="text-end">Bahan</th>
                <th width="100" class="text-end">Peralatan</th>
                <th width="100" class="text-end">Selisih RAB</th>
                <th width="70">Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rapData) || $rabSubcatCount == 0): ?>
            <tr>
                <td colspan="11" class="text-center text-muted py-4">
                    Belum ada data RAB. <a href="?id=<?= $projectId ?>&tab=rab">Tambahkan item di RAB</a> terlebih dahulu.
                </td>
            </tr>
            <?php else: ?>
            
            <?php foreach ($rapData as $catId => $data): 
                $cat = $data['category'];
                $subcats = $data['subcategories'];
                $catTotal = $data['total'];
                $catTenaga = $data['total_tenaga'];
                $catBahan = $data['total_bahan'];
                $catAlat = $data['total_alat'];
            ?>
            <tr class="table-primary">
                <td colspan="11">
                    <strong><?= sanitize($cat['code']) ?>. <?= sanitize($cat['name']) ?></strong>
                </td>
            </tr>
            
            <?php foreach ($subcats as $sub): ?>
                <tr data-category-id="<?= $cat['id'] ?>" data-rap-id="<?= $sub['rap_id'] ?? '' ?>">
                    <td><?= sanitize($sub['code']) ?></td>
                    <td><?= sanitize($sub['name']) ?></td>
                    <td><?= sanitize($sub['unit']) ?></td>
                    <td>
                        <?php if ($sub['rap_id'] && $isEditable): ?>
                        <input type="text" 
                               class="form-control form-control-sm border-0 text-end inline-ajax" 
                               value="<?= formatVolume($sub['display_volume']) ?>" 
                               style="width:80px;"
                               data-ajax-url="view.php?id=<?= $projectId ?>"
                               data-action="ajax_update_rap_volume"
                               data-id="<?= $sub['rap_id'] ?>"
                               data-field="volume"
                               data-format="decimal"
                               data-unit-price="<?= $sub['display_unit_price'] ?>"
                               data-unit-price-tenaga="<?= $sub['ahsp_tenaga'] ?? 0 ?>"
                               data-unit-price-bahan="<?= $sub['ahsp_bahan'] ?? 0 ?>"
                               data-unit-price-alat="<?= $sub['ahsp_alat'] ?? 0 ?>">
                        <?php else: ?>
                        <span class="text-end d-block"><?= formatVolume($sub['display_volume']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= formatNumber($sub['display_unit_price'], 2) ?></td>
                    <td class="text-end" id="jumlah-rap-<?= $sub['rap_id'] ?? $sub['id'] ?>"><?= formatNumber($sub['display_total'], 2) ?></td>
                    <td class="text-end" id="tenaga-rap-<?= $sub['rap_id'] ?? $sub['id'] ?>"><?= formatNumber($sub['anggaran_tenaga']) ?></td>
                    <td class="text-end" id="bahan-rap-<?= $sub['rap_id'] ?? $sub['id'] ?>"><?= formatNumber($sub['anggaran_bahan']) ?></td>
                    <td class="text-end" id="alat-rap-<?= $sub['rap_id'] ?? $sub['id'] ?>"><?= formatNumber($sub['anggaran_alat']) ?></td>
                    <?php 
                        $rapSubTotal = $sub['display_total'] ?? 0;
                        $rabTotal = $sub['rab_total'] ?? 0;
                        $selisih = $rabTotal - $rapSubTotal;
                        $selisihPct = $rabTotal > 0 ? ($selisih / $rabTotal) * 100 : 0;
                        
                        // Inverted Logic:
                        // Positive (Savings) = Green
                        // Negative (Over Budget) = Red
                        $selisihClass = $selisih > 0 ? 'text-success' : ($selisih < 0 ? 'text-danger' : 'text-muted');
                    ?>
                    <td class="text-end <?= $selisihClass ?>" 
                        data-bs-toggle="tooltip" 
                        data-bs-placement="left"
                        data-bs-html="true"
                        title="Selisih: <?= ($selisihPct >= 0 ? '+' : '') . formatNumber($selisihPct, 2) ?>%<br>RAB: <?= formatRupiah($rabTotal) ?>"
                        style="cursor: help;">
                        <strong><?= ($selisih >= 0 ? '+' : '') . formatNumber($selisih) ?></strong>
                    </td>
                    <td>
                        <?php if ($sub['rap_id'] && !empty($sub['ahsp_code'])): ?>
                        <button type="button" class="btn btn-sm btn-secondary" title="Lihat AHSP RAP" onclick="showAhspRapModal('<?= addslashes($sub['ahsp_code']) ?>')">
                            <i class="mdi mdi-file-table-outline"></i>
                        </button>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            
            <!-- Category Total -->
            <?php 
                $catRabTotal = $data['rab_total'] ?? 0;
                $catSelisih = $catRabTotal - $catTotal;
                $catSelisihPct = $catRabTotal > 0 ? ($catSelisih / $catRabTotal) * 100 : 0;
                
                // Inverted Logic:
                // Positive (Savings) = Green
                // Negative (Over Budget) = Red
                $catSelisihClass = $catSelisih > 0 ? 'text-success' : ($catSelisih < 0 ? 'text-danger' : 'text-muted');
            ?>
            <tr class="table-secondary">
                <td colspan="5" class="text-end"><strong>JUMLAH <?= sanitize($cat['code']) ?></strong></td>
                <td class="text-end" id="cat-total-<?= $cat['id'] ?>"><strong><?= formatNumber($catTotal, 2) ?></strong></td>
                <td class="text-end" id="cat-tenaga-<?= $cat['id'] ?>"><strong><?= formatNumber($catTenaga, 2) ?></strong></td>
                <td class="text-end" id="cat-bahan-<?= $cat['id'] ?>"><strong><?= formatNumber($catBahan, 2) ?></strong></td>
                <td class="text-end" id="cat-alat-<?= $cat['id'] ?>"><strong><?= formatNumber($catAlat, 2) ?></strong></td>
                <td class="text-end <?= $catSelisihClass ?>"
                    data-bs-toggle="tooltip" 
                    data-bs-placement="left"
                    data-bs-html="true"
                    title="Selisih: <?= ($catSelisihPct >= 0 ? '+' : '') . formatNumber($catSelisihPct, 2) ?>%<br>RAB: <?= formatRupiah($catRabTotal) ?>"
                    style="cursor: help;">
                    <strong><?= ($catSelisih >= 0 ? '+' : '') . formatNumber($catSelisih, 2) ?></strong>
                </td>
                <td></td>
            </tr>
            <?php endforeach; ?>
            
            <?php endif; ?>
        </tbody>
        <tfoot>
            <!-- Grand Total Row -->
            <?php 
                $grandSelisihPct = $grandRabTotal > 0 ? ($grandSelisih / $grandRabTotal) * 100 : 0;
                
                // Inverted Logic:
                // Positive (Savings) = Green
                // Negative (Over Budget) = Red
                $grandSelisihClass = $grandSelisih > 0 ? 'text-success' : ($grandSelisih < 0 ? 'text-danger' : 'text-muted');
            ?>
            <tr class="table-dark">
                <td colspan="5" class="text-end"><strong>JUMLAH TOTAL</strong></td>
                <td class="text-end" id="grand-total"><strong><?= formatNumber($grandTotal, 2) ?></strong></td>
                <td class="text-end" id="grand-tenaga"><strong><?= formatNumber($grandTotalTenaga, 2) ?></strong></td>
                <td class="text-end" id="grand-bahan"><strong><?= formatNumber($grandTotalBahan, 2) ?></strong></td>
                <td class="text-end" id="grand-alat"><strong><?= formatNumber($grandTotalAlat, 2) ?></strong></td>
                <td class="text-end <?= $grandSelisihClass ?>"
                    data-bs-toggle="tooltip" 
                    data-bs-placement="left"
                    data-bs-html="true"
                    title="Selisih: <?= ($grandSelisihPct >= 0 ? '+' : '') . formatNumber($grandSelisihPct, 2) ?>%<br>RAB: <?= formatRupiah($grandRabTotal) ?>"
                    style="cursor: help;">
                    <strong><?= ($grandSelisih >= 0 ? '+' : '') . formatNumber($grandSelisih, 2) ?></strong>
                </td>
                <td></td>
            </tr>
            <!-- PPN Row -->
            <tr class="table-light">
                <td colspan="5" class="text-end">
                    <strong>PPN <?= formatNumber($ppnPercentage, 2) ?>%</strong>
                </td>
                <td class="text-end"><strong><?= formatNumber($ppnAmount, 2) ?></strong></td>
                <td colspan="5"></td>
            </tr>
            <!-- Total + PPN -->
            <tr class="table-light">
                <td colspan="5" class="text-end"><strong>JUMLAH TOTAL (TERMASUK PPN)</strong></td>
                <td class="text-end"><strong><?= formatNumber($totalWithPpn, 2) ?></strong></td>
                <td colspan="5"></td>
            </tr>
            <!-- Rounded Total -->
            <?php 
                $selisihRoundedPct = $rabTotalRounded > 0 ? ($selisihRounded / $rabTotalRounded) * 100 : 0;
                
                // Inverted Logic:
                // Positive (Savings) = Green
                // Negative (Over Budget) = Red
                $selisihRoundedClass = $selisihRounded > 0 ? 'text-success' : ($selisihRounded < 0 ? 'text-danger' : 'text-muted');
            ?>
            <tr class="table-primary">
                <td colspan="5" class="text-end"><strong>JUMLAH TOTAL DIBULATKAN</strong></td>
                <td class="text-end"><strong><?= formatRupiah($totalRounded) ?></strong></td>
                <td colspan="3"></td>
                <td class="text-end <?= $selisihRoundedClass ?>"
                    data-bs-toggle="tooltip" 
                    data-bs-placement="left"
                    data-bs-html="true"
                    title="Selisih: <?= ($selisihRoundedPct >= 0 ? '+' : '') . formatNumber($selisihRoundedPct, 2) ?>%<br>RAB: <?= formatRupiah($rabTotalRounded) ?>"
                    style="cursor: help;">
                    <strong><?= ($selisihRounded >= 0 ? '+' : '') . formatRupiah($selisihRounded) ?></strong>
                </td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>

<script>
// Confirm sync reference
var currentSourceId = <?= $rabSourceId ?>;

function confirmSyncReference(sourceId, sourceName) {
    if (sourceId === currentSourceId) {
        return;
    }
    
    document.getElementById('syncSourceId').value = sourceId;
    document.getElementById('syncSourceName').textContent = sourceName;
    var modal = new bootstrap.Modal(document.getElementById('syncReferenceModal'));
    modal.show();
}

// Initialize tooltips
$(document).ready(function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<!-- Modal Konfirmasi Sync Reference -->
<div class="modal fade" id="syncReferenceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="mdi mdi-alert-circle"></i> Peringatan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning mb-3">
                    <i class="mdi mdi-information"></i>
                    <strong>Perhatian!</strong> Mengubah acuan akan melakukan hal berikut:
                    <ul class="mb-0 mt-2">
                        <li><strong>Menghapus</strong> seluruh data RAP saat ini</li>
                        <li><strong>Me-reset</strong> semua perubahan/editan manual yang pernah dilakukan</li>
                        <li><strong>Menyalin ulang</strong> data dari acuan yang dipilih</li>
                    </ul>
                </div>
                <p class="mb-0">
                    Anda akan mengubah acuan RAP ke: <strong id="syncSourceName"></strong>
                    <br><br>
                    <span class="text-danger"><i class="mdi mdi-alert"></i> Tindakan ini tidak dapat dibatalkan!</span>
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="mdi mdi-close"></i> Batal
                </button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="sync_from_reference">
                    <input type="hidden" name="source_id" id="syncSourceId" value="">
                    <button type="submit" class="btn btn-warning">
                        <i class="mdi mdi-sync"></i> Ya, Ganti Acuan
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- AHSP RAP Detail Modal -->
<div class="modal fade" id="ahspRapDetailModal" tabindex="-1" aria-labelledby="ahspRapDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="ahspRapDetailModalLabel">Detail AHSP RAP</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="ahspRapDetailBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-info" role="status"></div>
                    <p class="mt-2 text-muted">Memuat data AHSP RAP...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- Import RAP Modal -->
<div class="modal fade" id="importRapModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="action" value="import_rap">
            <div class="modal-header">
                <h5 class="modal-title">Import RAP dari CSV</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label required">File CSV</label>
                    <input type="file" class="form-control" name="csv_file" accept=".csv,.txt" required>
                </div>
                
                <div class="alert alert-info small">
                    <i class="mdi mdi-information"></i>
                    <strong>Format CSV (3 Kolom):</strong>
                    <table class="table table-sm table-bordered mt-2 mb-0 bg-white">
                        <thead class="table-light">
                            <tr><th>Kolom A</th><th>Kolom B</th><th>Kolom C</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>Nama Kategori</td><td>Kode AHSP</td><td>Volume</td></tr>
                        </tbody>
                    </table>
                    <small class="text-muted mt-1 d-block">Jika Kolom A terisi = buat kategori baru. Sub-kategori setelahnya mengikuti kategori tersebut.</small>
                </div>
                
                <div class="bg-light p-3 rounded mb-3" style="font-family: monospace; font-size: 12px;">
                    <div class="row fw-bold text-muted mb-1" style="font-size:10px">
                        <div class="col-5">Kolom A</div>
                        <div class="col-4">Kolom B</div>
                        <div class="col-3">Kolom C</div>
                    </div>
                    <div class="row text-primary fw-bold"><div class="col-5">PEKERJAAN PERSIAPAN</div><div class="col-4">A.4.1.1.4</div><div class="col-3">10</div></div>
                    <div class="row"><div class="col-5"></div><div class="col-4">A.4.1.1.5</div><div class="col-3">25,5</div></div>
                    <div class="row text-primary fw-bold mt-1"><div class="col-5">PEKERJAAN TANAH</div><div class="col-4">A.4.2.1.1</div><div class="col-3">100</div></div>
                    <div class="row"><div class="col-5"></div><div class="col-4">A.4.2.1.2</div><div class="col-3">50</div></div>
                </div>
                
                <div class="alert alert-warning small mb-0">
                    <i class="mdi mdi-alert"></i>
                    <strong>Penting:</strong> Kode AHSP harus sesuai dengan yang ada di Master Data AHSP proyek ini.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-success">
                    <i class="mdi mdi-upload"></i> Import
                </button>
            </div>
        </form>
    </div>
</div>

<!-- PDF Preview Modal RAP -->
<div class="modal fade" id="pdfPreviewModalRap" tabindex="-1" aria-labelledby="pdfPreviewModalRapLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" id="pdfPreviewModalRapLabel">
                    <i class="mdi mdi-file-pdf-box text-danger"></i> Preview Laporan RAP
                </h5>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-success btn-sm" onclick="printPdfPreviewRap()">
                        <i class="mdi mdi-printer"></i> Cetak / Export PDF
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body p-0">
                <iframe id="pdfPreviewIframeRap" style="width:100%; height:100%; border:none;"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
// Show AHSP RAP Detail Modal (from Master Data RAP)
function showAhspRapModal(ahspCode) {
    var modalEl = document.getElementById('ahspRapDetailModal');
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    document.getElementById('ahspRapDetailBody').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-info" role="status"></div><p class="mt-2 text-muted">Memuat data AHSP RAP...</p></div>';
    document.getElementById('ahspRapDetailModalLabel').textContent = 'Detail AHSP RAP';
    modal.show();
    
    fetch('view.php?id=<?= $projectId ?>&ajax=get_ahsp_rap_detail&ahsp_code=' + encodeURIComponent(ahspCode))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                document.getElementById('ahspRapDetailBody').innerHTML = data.html;
                document.getElementById('ahspRapDetailModalLabel').textContent = 'Detail AHSP RAP - ' + data.work_name;
            } else {
                document.getElementById('ahspRapDetailBody').innerHTML = '<div class="alert alert-danger">' + data.message + '</div>';
            }
        })
        .catch(function(err) {
            document.getElementById('ahspRapDetailBody').innerHTML = '<div class="alert alert-danger">Gagal memuat data: ' + err.message + '</div>';
        });
}

// Open PDF Preview in modal for RAP
function openPdfPreviewRap() {
    var modal = new bootstrap.Modal(document.getElementById('pdfPreviewModalRap'));
    var iframe = document.getElementById('pdfPreviewIframeRap');
    iframe.src = 'export_rap_pdf.php?id=<?= $projectId ?>';
    modal.show();
}

// Print PDF from iframe for RAP
function printPdfPreviewRap() {
    var iframe = document.getElementById('pdfPreviewIframeRap');
    if (iframe.contentWindow) {
        iframe.contentWindow.print();
    }
}
</script>
