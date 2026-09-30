<?php
/**
 * Snapshot Tab Component (MC0 / CCO) - Embedded in Project View
 * PCC - Project Cost Control System
 * Displays independent snapshot table with inline editing without affecting master RAB
 * Variables available from parent view.php: $project, $projectId, $currentSnapshot, $snapshotType
 */

ensureRabHeadSubsTableExists();

$snapshotId = intval($currentSnapshot['id']);
$snapshotName = sanitize($currentSnapshot['name']);
$snapshotDesc = sanitize($currentSnapshot['description'] ?? '');
$isEditable = hasPermission('rab.edit');

// Get creator name
$creatorName = dbGetRow("SELECT full_name FROM users WHERE id = ?", [$currentSnapshot['created_by']])['full_name'] ?? '-';

// Overhead & PPN for snapshot
$overheadPct = getProjectOverheadProfitPct($currentSnapshot, 'rab');
$ppnPercentage = floatval($currentSnapshot['ppn_percentage'] ?? $project['ppn_percentage'] ?? 11);

// Head-subs
$headSubs = dbGetAll("SELECT * FROM rab_head_subs WHERE project_id = ? ORDER BY sort_order, id", [$projectId]);

// Categories for this snapshot
$categories = dbGetAll("
    SELECT * FROM rab_snapshot_categories 
    WHERE snapshot_id = ? 
    ORDER BY sort_order, LENGTH(code), code, id
", [$snapshotId]);

// Batch-load AHSP details breakdown for this snapshot
$ahspDetailsRaw = dbGetAll("
    SELECT d.snapshot_subcategory_id, d.category, 
           SUM(d.coefficient * d.unit_price) as total_cat
    FROM rab_snapshot_ahsp_details d
    JOIN rab_snapshot_subcategories ss ON d.snapshot_subcategory_id = ss.id
    JOIN rab_snapshot_categories sc ON ss.category_id = sc.id
    WHERE sc.snapshot_id = ?
    GROUP BY d.snapshot_subcategory_id, d.category
", [$snapshotId]);

$snapBreakdownMap = [];
foreach ($ahspDetailsRaw as $r) {
    $sId = $r['snapshot_subcategory_id'];
    if (!isset($snapBreakdownMap[$sId])) {
        $snapBreakdownMap[$sId] = ['upah' => 0, 'material' => 0, 'alat' => 0, 'total' => 0];
    }
    $catKey = $r['category'];
    $val = floatval($r['total_cat']);
    if (isset($snapBreakdownMap[$sId][$catKey])) {
        $snapBreakdownMap[$sId][$catKey] = $val;
    }
    $snapBreakdownMap[$sId]['total'] += $val;
}

// Build Head-Sub map
$headSubMap = [];
foreach ($headSubs as $hs) {
    $headSubMap[$hs['id']] = [
        'head_sub' => $hs,
        'categories' => [],
        'total' => 0,
        'total_tenaga' => 0,
        'total_bahan' => 0,
        'total_alat' => 0
    ];
}

$standaloneCats = [];
$grandTotal = 0;
$grandTotalTenaga = 0;
$grandTotalBahan = 0;
$grandTotalAlat = 0;

foreach ($categories as $cat) {
    $subcats = dbGetAll("
        SELECT * FROM rab_snapshot_subcategories 
        WHERE category_id = ? 
        ORDER BY sort_order, code
    ", [$cat['id']]);
    
    $catTotal = 0;
    $catTenaga = 0;
    $catBahan = 0;
    $catAlat = 0;
    
    $enrichedSubcats = [];
    foreach ($subcats as $sub) {
        $sId = $sub['id'];
        $components = $snapBreakdownMap[$sId] ?? null;
        
        if ($components && $components['total'] > 0) {
            $baseUnitPrice = $components['total'];
            $compUpah = $components['upah'];
            $compMaterial = $components['material'];
            $compAlat = $components['alat'];
        } else {
            $baseUnitPrice = floatval($sub['unit_price']);
            $compUpah = 0;
            $compMaterial = 0;
            $compAlat = 0;
        }
        
        $unitPriceWithOverhead = $baseUnitPrice * (1 + ($overheadPct / 100));
        $sub['unit_price_display'] = $unitPriceWithOverhead;
        $sub['base_unit_price'] = $baseUnitPrice;
        $sub['ahsp_tenaga'] = $compUpah;
        $sub['ahsp_bahan'] = $compMaterial;
        $sub['ahsp_alat'] = $compAlat;
        
        $vol = floatval($sub['volume']);
        $subTotal = $vol * $unitPriceWithOverhead;
        $catTotal += $subTotal;
        
        $sub['anggaran_tenaga'] = $compUpah * $vol;
        $sub['anggaran_bahan'] = $compMaterial * $vol;
        $sub['anggaran_alat'] = $compAlat * $vol;
        
        $catTenaga += $sub['anggaran_tenaga'];
        $catBahan += $sub['anggaran_bahan'];
        $catAlat += $sub['anggaran_alat'];
        
        $enrichedSubcats[] = $sub;
    }
    
    $grandTotal += $catTotal;
    $grandTotalTenaga += $catTenaga;
    $grandTotalBahan += $catBahan;
    $grandTotalAlat += $catAlat;
    
    $catData = [
        'category' => $cat,
        'subcategories' => $enrichedSubcats,
        'total' => $catTotal,
        'total_tenaga' => $catTenaga,
        'total_bahan' => $catBahan,
        'total_alat' => $catAlat
    ];

    if (!empty($cat['head_sub_id']) && isset($headSubMap[$cat['head_sub_id']])) {
        $hsId = $cat['head_sub_id'];
        $headSubMap[$hsId]['categories'][$cat['id']] = $catData;
        $headSubMap[$hsId]['total'] += $catTotal;
        $headSubMap[$hsId]['total_tenaga'] += $catTenaga;
        $headSubMap[$hsId]['total_bahan'] += $catBahan;
        $headSubMap[$hsId]['total_alat'] += $catAlat;
    } else {
        $standaloneCats[$cat['id']] = $catData;
    }
}

// Calculate totals with PPN
$ppnAmount = $grandTotal * ($ppnPercentage / 100);
$totalWithPpn = $grandTotal + $ppnAmount;
$totalRounded = ceil($totalWithPpn / 10) * 10;
?>

<!-- Mode Notice Banner -->
<div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <i class="mdi mdi-information-outline me-1 font-size-16"></i>
        <strong>Mode Salinan (<?= $snapshotType ?>):</strong>
        <span>Perubahan volume atau AHSP pada salinan ini hanya mempengaruhi data <strong><?= $snapshotType ?></strong> dan <strong>TIDAK</strong> akan mengubah Master Data maupun RAB asli proyek.</span>
        <div class="small text-muted mt-1">
            Dibuat pada: <strong><?= date('d M Y H:i', strtotime($currentSnapshot['created_at'])) ?></strong> oleh <strong><?= sanitize($creatorName) ?></strong>
            <?php if (!empty($snapshotDesc)): ?> | Catatan: <?= $snapshotDesc ?><?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editSnapshotInfoModal_<?= $snapshotId ?>">
            <i class="mdi mdi-pencil"></i> Edit Info
        </button>
        <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteSnapshotModal_<?= $snapshotId ?>">
            <i class="mdi mdi-trash-can-outline"></i> Hapus <?= $snapshotType ?>
        </button>
    </div>
</div>

<!-- Action Bar -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <h5 class="mb-0">
            <?= sanitize($project['name']) ?> - 
            <span class="badge bg-primary font-size-14"><?= $snapshotType ?></span>
        </h5>
        <!-- Search Input -->
        <div class="input-group input-group-sm ms-md-2" style="width: 240px;">
            <span class="input-group-text bg-white border-end-0"><i class="mdi mdi-magnify text-muted"></i></span>
            <input type="text" class="form-control border-start-0" id="searchSnapshotInput_<?= $snapshotId ?>" placeholder="Cari pekerjaan, kode..." autocomplete="off">
            <button class="btn btn-outline-secondary border-start-0 d-none" type="button" id="clearSearchSnapshotBtn_<?= $snapshotId ?>" title="Reset pencarian">
                <i class="mdi mdi-close"></i>
            </button>
        </div>
        <span id="snapSearchResultCount_<?= $snapshotId ?>" class="badge bg-primary-subtle text-primary small d-none"></span>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <!-- Expand / Collapse All -->
        <button type="button" class="btn btn-outline-secondary btn-sm text-nowrap" onclick="toggleAllSnapshotRows_<?= $snapshotId ?>(true)" title="Buka Semua Tampilan Tabel">
            <i class="mdi mdi-unfold-more-horizontal"></i> Buka Semua
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm text-nowrap" onclick="toggleAllSnapshotRows_<?= $snapshotId ?>(false)" title="Tutup Semua Tampilan Tabel">
            <i class="mdi mdi-unfold-less-horizontal"></i> Tutup Semua
        </button>

        <!-- Re-sync from RAB button -->
        <button type="button" class="btn btn-outline-warning btn-sm text-nowrap" data-bs-toggle="modal" data-bs-target="#resyncSnapshotModal_<?= $snapshotId ?>" title="Salin ulang data terbaru dari RAB asli">
            <i class="mdi mdi-sync"></i> Salin Ulang dari RAB
        </button>
        
        <!-- Export Button -->
        <div class="dropdown">
            <button class="btn btn-success btn-sm dropdown-toggle text-nowrap" type="button" data-bs-toggle="dropdown">
                <i class="mdi mdi-download"></i> Export <?= $snapshotType ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <a class="dropdown-item" href="export_rab.php?id=<?= $projectId ?>&snapshot_id=<?= $snapshotId ?>">
                        <i class="mdi mdi-file-delimited text-success me-2"></i>Export CSV (Laporan)
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="export_rab_pdf.php?id=<?= $projectId ?>&snapshot_id=<?= $snapshotId ?>" target="_blank">
                        <i class="mdi mdi-file-pdf-box text-danger me-2"></i>Print / Export PDF
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>

<!-- Snapshot Table Card -->
<div class="card border shadow-none mb-3">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered mb-0 align-middle snapshot-table" id="snapshotTable_<?= $snapshotId ?>">
                <thead class="table-dark">
                    <tr>
                        <th width="70" class="text-center">No</th>
                        <th>Uraian Pekerjaan</th>
                        <th width="75" class="text-center">Satuan</th>
                        <th width="105" class="text-end">Volume</th>
                        <th width="145" class="text-end">Harga Satuan</th>
                        <th width="155" class="text-end">Jumlah Harga</th>
                        <th width="125" class="text-end">Tenaga</th>
                        <th width="125" class="text-end">Bahan</th>
                        <th width="125" class="text-end">Peralatan</th>
                        <th width="85" class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categories)): ?>
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">
                            <i class="mdi mdi-information-outline font-size-24 d-block mb-1"></i>
                            Belum ada data dalam salinan <?= $snapshotType ?> ini.
                        </td>
                    </tr>
                    <?php else: ?>

                    <?php
                    // Helper renderer for category row & subcategories in snapshot
                    $renderSnapshotCategory = function($catData, $snapshotId, $overheadPct, $isEditable) {
                        $cat = $catData['category'];
                        $subcats = $catData['subcategories'];
                        $catId = $cat['id'];
                        $catTotal = $catData['total'];
                        $catTenaga = $catData['total_tenaga'];
                        $catBahan = $catData['total_bahan'];
                        $catAlat = $catData['total_alat'];
                    ?>
                        <!-- Category Header Row -->
                        <tr class="table-primary snap-cat-header-row" data-cat-id="<?= $catId ?>" style="cursor: pointer;" onclick="toggleSnapshotCat(<?= $catId ?>)">
                            <td colspan="10" class="py-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="mdi mdi-chevron-down snap-cat-toggle-icon-<?= $catId ?> font-size-16"></i>
                                        <strong><?= sanitize($cat['code']) ?>. <?= sanitize($cat['name']) ?></strong>
                                    </div>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle small">
                                        Subtotal: <?= formatRupiah($catTotal) ?>
                                    </span>
                                </div>
                            </td>
                        </tr>

                        <!-- Subcategory Rows -->
                        <?php foreach ($subcats as $sub): 
                            $subId = $sub['id'];
                        ?>
                        <tr class="snap-subcat-row snap-cat-group-<?= $catId ?>" data-sub-id="<?= $subId ?>" data-cat-id="<?= $catId ?>">
                            <td class="text-center font-monospace text-muted small"><?= sanitize($sub['code']) ?></td>
                            <td>
                                <span class="fw-medium snap-item-name"><?= sanitize($sub['name']) ?></span>
                            </td>
                            <td class="text-center text-muted small"><?= sanitize($sub['unit']) ?></td>
                            <td class="p-1">
                                <?php if ($isEditable): ?>
                                <input type="text" 
                                       class="form-control form-control-sm text-end snap-volume-input" 
                                       value="<?= formatVolume($sub['volume']) ?>" 
                                       data-id="<?= $subId ?>"
                                       data-cat-id="<?= $catId ?>"
                                       data-unit-price="<?= $sub['unit_price_display'] ?>"
                                       data-unit-tenaga="<?= $sub['ahsp_tenaga'] ?>"
                                       data-unit-bahan="<?= $sub['ahsp_bahan'] ?>"
                                       data-unit-alat="<?= $sub['ahsp_alat'] ?>"
                                       title="Klik untuk mengedit volume salinan">
                                <?php else: ?>
                                <div class="text-end fw-medium"><?= formatVolume($sub['volume']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end font-monospace text-muted"><?= formatNumber($sub['unit_price_display']) ?></td>
                            <td class="text-end font-monospace fw-bold snap-sub-total" id="snap-subtotal-<?= $subId ?>">
                                <?= formatNumber($sub['volume'] * $sub['unit_price_display']) ?>
                            </td>
                            <td class="text-end font-monospace text-muted small snap-sub-tenaga" id="snap-tenaga-<?= $subId ?>">
                                <?= formatNumber($sub['anggaran_tenaga']) ?>
                            </td>
                            <td class="text-end font-monospace text-muted small snap-sub-bahan" id="snap-bahan-<?= $subId ?>">
                                <?= formatNumber($sub['anggaran_bahan']) ?>
                            </td>
                            <td class="text-end font-monospace text-muted small snap-sub-alat" id="snap-alat-<?= $subId ?>">
                                <?= formatNumber($sub['anggaran_alat']) ?>
                            </td>
                            <td class="text-center p-1">
                                <a href="ahsp_snapshot.php?id=<?= $subId ?>" class="btn btn-outline-secondary btn-sm py-0 px-2 font-size-12" title="Edit Rincian AHSP Salinan">
                                    <i class="mdi mdi-file-table-outline me-1"></i>AHSP
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <!-- Category Subtotal Row -->
                        <tr class="table-light fw-bold snap-cat-subtotal-row snap-cat-group-<?= $catId ?>">
                            <td colspan="5" class="text-end py-2">JUMLAH <?= sanitize($cat['code']) ?>:</td>
                            <td class="text-end font-monospace py-2 snap-cat-sum-total" id="snap-cat-total-<?= $catId ?>">
                                <?= formatNumber($catTotal) ?>
                            </td>
                            <td class="text-end font-monospace text-muted py-2 snap-cat-sum-tenaga" id="snap-cat-tenaga-<?= $catId ?>">
                                <?= formatNumber($catTenaga) ?>
                            </td>
                            <td class="text-end font-monospace text-muted py-2 snap-cat-sum-bahan" id="snap-cat-bahan-<?= $catId ?>">
                                <?= formatNumber($catBahan) ?>
                            </td>
                            <td class="text-end font-monospace text-muted py-2 snap-cat-sum-alat" id="snap-cat-alat-<?= $catId ?>">
                                <?= formatNumber($catAlat) ?>
                            </td>
                            <td></td>
                        </tr>
                    <?php
                    };
                    ?>

                    <!-- Render Head-Sub Groups -->
                    <?php foreach ($headSubMap as $hsId => $hsGroup): ?>
                        <?php if (!empty($hsGroup['categories'])): 
                            $hs = $hsGroup['head_sub'];
                        ?>
                        <tr class="table-secondary snap-headsub-header-row" data-hs-id="<?= $hsId ?>">
                            <td colspan="10" class="py-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="font-size-15 fw-bold text-dark">
                                        <i class="mdi mdi-folder-outline me-1"></i>
                                        <?= !empty($hs['code']) ? sanitize($hs['code']) . '. ' : '' ?><?= sanitize($hs['name']) ?>
                                    </span>
                                    <span class="badge bg-dark-subtle text-dark border font-size-12" id="snap-hs-total-<?= $hsId ?>">
                                        Total: <?= formatRupiah($hsGroup['total']) ?>
                                    </span>
                                </div>
                            </td>
                        </tr>
                        <?php foreach ($hsGroup['categories'] as $catData): ?>
                            <?php $renderSnapshotCategory($catData, $snapshotId, $overheadPct, $isEditable); ?>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <!-- Render Standalone Categories -->
                    <?php foreach ($standaloneCats as $catData): ?>
                        <?php $renderSnapshotCategory($catData, $snapshotId, $overheadPct, $isEditable); ?>
                    <?php endforeach; ?>

                    <?php endif; ?>
                </tbody>

                <tfoot>
                    <!-- Grand Total Row -->
                    <tr class="table-dark">
                        <td colspan="5" class="text-end py-2 font-size-14"><strong>JUMLAH TOTAL (<?= $snapshotType ?>)</strong></td>
                        <td class="text-end font-monospace py-2 font-size-14" id="snap-grand-total"><strong><?= formatNumber($grandTotal) ?></strong></td>
                        <td class="text-end font-monospace py-2" id="snap-grand-tenaga"><strong><?= formatNumber($grandTotalTenaga) ?></strong></td>
                        <td class="text-end font-monospace py-2" id="snap-grand-bahan"><strong><?= formatNumber($grandTotalBahan) ?></strong></td>
                        <td class="text-end font-monospace py-2" id="snap-grand-alat"><strong><?= formatNumber($grandTotalAlat) ?></strong></td>
                        <td></td>
                    </tr>
                    <!-- PPN Row -->
                    <tr class="table-light">
                        <td colspan="5" class="text-end py-1"><strong>PPN <?= formatNumber($ppnPercentage, 2) ?>%</strong></td>
                        <td class="text-end font-monospace py-1" id="snap-ppn-amount"><strong><?= formatNumber($ppnAmount) ?></strong></td>
                        <td colspan="4"></td>
                    </tr>
                    <!-- Total + PPN -->
                    <tr class="table-light">
                        <td colspan="5" class="text-end py-1"><strong>JUMLAH TOTAL (TERMASUK PPN)</strong></td>
                        <td class="text-end font-monospace py-1" id="snap-total-with-ppn"><strong><?= formatNumber($totalWithPpn) ?></strong></td>
                        <td colspan="4"></td>
                    </tr>
                    <!-- Rounded Total -->
                    <tr class="table-primary">
                        <td colspan="5" class="text-end py-2 font-size-15"><strong>JUMLAH TOTAL DIBULATKAN</strong></td>
                        <td class="text-end font-monospace py-2 font-size-15 text-primary" id="snap-total-rounded"><strong><?= formatRupiah($totalRounded) ?></strong></td>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Modal Resync Snapshot from current project RAB -->
<div class="modal fade" id="resyncSnapshotModal_<?= $snapshotId ?>" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="resync_snapshot">
            <input type="hidden" name="snapshot_name" value="<?= $snapshotType ?>">
            <div class="modal-header">
                <h5 class="modal-title"><i class="mdi mdi-sync text-warning me-2"></i>Salin Ulang dari RAB Asli</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning mb-3">
                    <i class="mdi mdi-alert me-1"></i>
                    <strong>Perhatian:</strong> Menyalin ulang akan menggantikan seluruh volume dan AHSP pada salinan <strong><?= $snapshotType ?></strong> dengan data RAB proyek saat ini.
                </div>
                <p class="mb-2">Apakah Anda yakin ingin menyalin ulang data RAB ke <strong><?= $snapshotType ?></strong>?</p>
                <div class="mb-3">
                    <label class="form-label">Catatan Tambahan (Opsional)</label>
                    <textarea class="form-control" name="snapshot_description" rows="2"><?= $snapshotDesc ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-warning"><i class="mdi mdi-sync"></i> Ya, Salin Ulang</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Delete Snapshot -->
<div class="modal fade" id="deleteSnapshotModal_<?= $snapshotId ?>" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="delete_snapshot">
            <input type="hidden" name="snapshot_id" value="<?= $snapshotId ?>">
            <div class="modal-header">
                <h5 class="modal-title"><i class="mdi mdi-trash-can text-danger me-2"></i>Hapus Salinan <?= $snapshotType ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger mb-3">
                    <i class="mdi mdi-alert-octagon me-1"></i>
                    Tindakan ini akan menghapus salinan <strong><?= $snapshotType ?></strong> secara permanen dan tab ini akan dihilangkan dari dashboard.
                </div>
                <p class="mb-0">Apakah Anda yakin ingin menghapus salinan <strong><?= $snapshotType ?></strong>?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger"><i class="mdi mdi-trash-can"></i> Ya, Hapus</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit Info Snapshot -->
<div class="modal fade" id="editSnapshotInfoModal_<?= $snapshotId ?>" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="rab_snapshot.php?id=<?= $snapshotId ?>" class="modal-content">
            <input type="hidden" name="action" value="update_snapshot">
            <div class="modal-header">
                <h5 class="modal-title"><i class="mdi mdi-pencil me-2"></i>Edit Info Salinan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label required">Nama Salinan</label>
                    <input type="text" class="form-control" name="name" value="<?= $snapshotName ?>" required readonly>
                    <small class="text-muted">Tipe salinan terkunci pada MC0 atau CCO.</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">Deskripsi / Catatan</label>
                    <textarea class="form-control" name="description" rows="3"><?= $snapshotDesc ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
// Toggle category expand/collapse
function toggleSnapshotCat(catId) {
    const rows = document.querySelectorAll('.snap-cat-group-' + catId);
    const icon = document.querySelector('.snap-cat-toggle-icon-' + catId);
    let isHidden = false;
    rows.forEach(r => {
        if (r.style.display === 'none') {
            r.style.display = '';
            isHidden = false;
        } else {
            r.style.display = 'none';
            isHidden = true;
        }
    });
    if (icon) {
        if (isHidden) {
            icon.classList.remove('mdi-chevron-down');
            icon.classList.add('mdi-chevron-right');
        } else {
            icon.classList.remove('mdi-chevron-right');
            icon.classList.add('mdi-chevron-down');
        }
    }
}

// Toggle all categories expand/collapse
function toggleAllSnapshotRows_<?= $snapshotId ?>(expand) {
    const subRows = document.querySelectorAll('#snapshotTable_<?= $snapshotId ?> .snap-subcat-row, #snapshotTable_<?= $snapshotId ?> .snap-cat-subtotal-row');
    const icons = document.querySelectorAll('#snapshotTable_<?= $snapshotId ?> [class*="snap-cat-toggle-icon-"]');
    
    subRows.forEach(r => {
        r.style.display = expand ? '' : 'none';
    });
    icons.forEach(ic => {
        if (expand) {
            ic.classList.remove('mdi-chevron-right');
            ic.classList.add('mdi-chevron-down');
        } else {
            ic.classList.remove('mdi-chevron-down');
            ic.classList.add('mdi-chevron-right');
        }
    });
}

// Format numbers
function formatSnapNumber(val) {
    return new Intl.NumberFormat('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(val);
}

function formatSnapRupiah(val) {
    return 'Rp ' + new Intl.NumberFormat('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(val);
}

// Inline AJAX volume update & dynamic recalculation
$(document).ready(function() {
    const ppnRate = <?= $ppnPercentage ?> / 100;

    function recalculateSnapshotTotals() {
        let grandTotal = 0;
        let grandTenaga = 0;
        let grandBahan = 0;
        let grandAlat = 0;

        // Map for category sums
        const catSums = {};

        $('#snapshotTable_<?= $snapshotId ?> .snap-volume-input').each(function() {
            const input = $(this);
            const subId = input.data('id');
            const catId = input.data('cat-id');
            const unitPrice = parseFloat(input.data('unit-price')) || 0;
            const unitTenaga = parseFloat(input.data('unit-tenaga')) || 0;
            const unitBahan = parseFloat(input.data('unit-bahan')) || 0;
            const unitAlat = parseFloat(input.data('unit-alat')) || 0;

            let valStr = input.val().replace(/\./g, '').replace(',', '.');
            let vol = parseFloat(valStr) || 0;

            const subTotal = vol * unitPrice;
            const subTenaga = vol * unitTenaga;
            const subBahan = vol * unitBahan;
            const subAlat = vol * unitAlat;

            // Update row cells
            $('#snap-subtotal-' + subId).text(formatSnapNumber(subTotal));
            $('#snap-tenaga-' + subId).text(formatSnapNumber(subTenaga));
            $('#snap-bahan-' + subId).text(formatSnapNumber(subBahan));
            $('#snap-alat-' + subId).text(formatSnapNumber(subAlat));

            // Accumulate category sums
            if (!catSums[catId]) {
                catSums[catId] = { total: 0, tenaga: 0, bahan: 0, alat: 0 };
            }
            catSums[catId].total += subTotal;
            catSums[catId].tenaga += subTenaga;
            catSums[catId].bahan += subBahan;
            catSums[catId].alat += subAlat;

            grandTotal += subTotal;
            grandTenaga += subTenaga;
            grandBahan += subBahan;
            grandAlat += subAlat;
        });

        // Update category subtotal rows
        for (const catId in catSums) {
            $('#snap-cat-total-' + catId).text(formatSnapNumber(catSums[catId].total));
            $('#snap-cat-tenaga-' + catId).text(formatSnapNumber(catSums[catId].tenaga));
            $('#snap-cat-bahan-' + catId).text(formatSnapNumber(catSums[catId].bahan));
            $('#snap-cat-alat-' + catId).text(formatSnapNumber(catSums[catId].alat));
        }

        // Update footer totals
        const ppnAmount = grandTotal * ppnRate;
        const totalWithPpn = grandTotal + ppnAmount;
        const totalRounded = Math.ceil(totalWithPpn / 10) * 10;

        $('#snap-grand-total strong').text(formatSnapNumber(grandTotal));
        $('#snap-grand-tenaga strong').text(formatSnapNumber(grandTenaga));
        $('#snap-grand-bahan strong').text(formatSnapNumber(grandBahan));
        $('#snap-grand-alat strong').text(formatSnapNumber(grandAlat));

        $('#snap-ppn-amount strong').text(formatSnapNumber(ppnAmount));
        $('#snap-total-with-ppn strong').text(formatSnapNumber(totalWithPpn));
        $('#snap-total-rounded strong').text(formatSnapRupiah(totalRounded));
    }

    // Save volume on change
    $('#snapshotTable_<?= $snapshotId ?>').on('change', '.snap-volume-input', function() {
        const input = $(this);
        const subId = input.data('id');
        let rawVal = input.val().replace(/\./g, '').replace(',', '.');
        let floatVal = parseFloat(rawVal) || 0;

        // Visual saving indicator
        input.addClass('bg-warning-subtle');

        $.ajax({
            url: 'view.php?id=<?= $projectId ?>',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ajax_update_snapshot_volume',
                id: subId,
                value: floatVal
            },
            success: function(res) {
                input.removeClass('bg-warning-subtle');
                if (res.success) {
                    input.addClass('bg-success-subtle');
                    setTimeout(() => input.removeClass('bg-success-subtle'), 1000);
                    recalculateSnapshotTotals();
                } else {
                    alert(res.message || 'Gagal menyimpan volume');
                    input.addClass('bg-danger-subtle');
                }
            },
            error: function() {
                input.removeClass('bg-warning-subtle').addClass('bg-danger-subtle');
                alert('Gagal menghubungi server untuk menyimpan volume');
            }
        });
    });

    // Search filter
    $('#searchSnapshotInput_<?= $snapshotId ?>').on('input', function() {
        const q = $(this).val().toLowerCase().trim();
        const clearBtn = $('#clearSearchSnapshotBtn_<?= $snapshotId ?>');
        const countBadge = $('#snapSearchResultCount_<?= $snapshotId ?>');
        
        if (q.length > 0) {
            clearBtn.removeClass('d-none');
            let matchCount = 0;
            
            $('#snapshotTable_<?= $snapshotId ?> .snap-subcat-row').each(function() {
                const text = $(this).text().toLowerCase();
                if (text.includes(q)) {
                    $(this).show();
                    matchCount++;
                } else {
                    $(this).hide();
                }
            });
            
            countBadge.removeClass('d-none').text(matchCount + ' hasil ditemukan');
        } else {
            clearBtn.addClass('d-none');
            countBadge.addClass('d-none');
            $('#snapshotTable_<?= $snapshotId ?> .snap-subcat-row').show();
        }
    });

    $('#clearSearchSnapshotBtn_<?= $snapshotId ?>').on('click', function() {
        $('#searchSnapshotInput_<?= $snapshotId ?>').val('').trigger('input');
    });
});
</script>
