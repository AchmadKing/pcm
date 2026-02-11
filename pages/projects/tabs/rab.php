<?php
/**
 * RAB Tab - Embedded in Project View
 * Displays RAB table with inline editing
 * Variables available from parent view.php: $project, $projectId
 */

// RAB can be viewed anytime, but only edited when draft AND not yet submitted AND not locked
$isEditable = ($project['status'] === 'draft' && !$project['rab_submitted'] && !isProjectLocked($project));

// Get available AHSP for this project
$ahspList = dbGetAll("SELECT * FROM project_ahsp WHERE project_id = ? ORDER BY work_name", [$projectId]);

// Function to get AHSP component breakdown (tenaga, bahan, peralatan) and total
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

// Get RAB data
$categories = dbGetAll("SELECT * FROM rab_categories WHERE project_id = ? ORDER BY sort_order, code", [$projectId]);

$rabData = [];
$grandTotal = 0;
$grandTotalTenaga = 0;
$grandTotalBahan = 0;
$grandTotalAlat = 0;

foreach ($categories as $cat) {
    $subcats = dbGetAll("SELECT * FROM rab_subcategories WHERE category_id = ? ORDER BY sort_order, code", [$cat['id']]);
    $catTotal = 0;
    $catTenaga = 0;
    $catBahan = 0;
    $catAlat = 0;
    
    // Get overhead percentage from project
    $overheadPct = $project['overhead_percentage'] ?? 10;
    
    // Enrich subcategories with component breakdown
    $enrichedSubcats = [];
    foreach ($subcats as $sub) {
        // Get AHSP component breakdown (includes total calculated from details)
        $components = getAhspComponentBreakdown($sub['ahsp_id']);
        
        // Use AHSP calculated total as base price (not from rab_subcategories.unit_price)
        // This ensures AHSP and RAB prices are always in sync
        $baseUnitPrice = $components['total'];
        $unitPriceWithOverhead = $baseUnitPrice * (1 + ($overheadPct / 100));
        $sub['unit_price_display'] = $unitPriceWithOverhead;
        
        $subTotal = $sub['volume'] * $unitPriceWithOverhead;
        $catTotal += $subTotal;
        
        // Component breakdown for tenaga/bahan/alat columns
        $sub['anggaran_tenaga'] = $components['upah'] * $sub['volume'];
        $sub['anggaran_bahan'] = $components['material'] * $sub['volume'];
        $sub['anggaran_alat'] = $components['alat'] * $sub['volume'];
        
        // Accumulate category totals
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

// Calculate PPN
$ppnPercentage = $project['ppn_percentage'];
$ppnAmount = $grandTotal * ($ppnPercentage / 100);
$totalWithPpn = $grandTotal + $ppnAmount;
$totalRounded = ceil($totalWithPpn / 10) * 10;
?>

<!-- Status Messages -->
<?php if (!$isEditable): ?>
<div class="alert alert-info">
    <i class="mdi mdi-information-outline"></i>
    Mode Lihat Saja - RAB tidak dapat diedit karena 
    <?= $project['rab_submitted'] ? 'sudah di-submit' : 'status proyek bukan draft' ?>.
</div>
<?php endif; ?>

<?php if (empty($ahspList) && $isEditable): ?>
<div class="alert alert-warning">
    <i class="mdi mdi-alert"></i>
    Belum ada AHSP di Master Data. 
    <a href="view.php?id=<?= $projectId ?>&tab=master" class="alert-link">Tambahkan AHSP</a> terlebih dahulu.
</div>
<?php endif; ?>

<!-- Action Buttons -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h5 class="mb-0"><?= sanitize($project['name']) ?></h5>
    <div class="d-flex flex-wrap gap-2">
        <?php 
        $snapshots = dbGetAll("SELECT s.*, u.full_name as creator_name FROM rab_snapshots s LEFT JOIN users u ON s.created_by = u.id WHERE s.project_id = ? ORDER BY s.created_at DESC", [$projectId]);
        ?>
        <!-- Salinan RAB Dropdown -->
        <div class="dropdown">
            <button class="btn btn-info btn-sm dropdown-toggle text-nowrap" type="button" data-bs-toggle="dropdown">
                <i class="mdi mdi-content-copy"></i> Salinan RAB <?php if (!empty($snapshots)): ?><span class="badge bg-light text-info"><?= count($snapshots) ?></span><?php endif; ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end" style="min-width: 320px;">
                <li><h6 class="dropdown-header">Salinan RAB</h6></li>
                <?php if (empty($snapshots)): ?>
                <li><span class="dropdown-item-text text-muted small">Belum ada salinan</span></li>
                <?php else: ?>
                <?php foreach ($snapshots as $snap): ?>
                <li>
                    <a href="rab_snapshot.php?id=<?= $snap['id'] ?>" class="dropdown-item d-flex justify-content-between align-items-center py-2" style="cursor:pointer;">
                        <div class="me-2" style="flex: 1;">
                            <div class="fw-bold small"><?= sanitize($snap['name']) ?></div>
                            <small class="text-muted"><?= date('d M Y H:i', strtotime($snap['created_at'])) ?></small>
                        </div>
                        <button type="button" class="btn btn-danger btn-sm text-nowrap" onclick="event.preventDefault(); event.stopPropagation(); deleteSnapshot(<?= $snap['id'] ?>)" title="Hapus">
                            <i class="mdi mdi-delete"></i>
                        </button>
                    </a>
                </li>
                <?php endforeach; ?>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#createSnapshotModal">
                        <i class="mdi mdi-plus"></i> Buat Salinan Baru
                    </a>
                </li>
            </ul>
        </div>
        <?php if ($isEditable && !empty($ahspList)): ?>
        <button class="btn btn-outline-success btn-sm text-nowrap" data-bs-toggle="modal" data-bs-target="#importRabModal">
            <i class="mdi mdi-upload"></i> Import CSV
        </button>
        <button class="btn btn-primary btn-sm text-nowrap" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
            <i class="mdi mdi-plus"></i> Tambah Kategori
        </button>
        <?php endif; ?>
        <!-- Export Dropdown -->
        <div class="dropdown">
            <button class="btn btn-success btn-sm dropdown-toggle text-nowrap" type="button" data-bs-toggle="dropdown">
                <i class="mdi mdi-microsoft-excel"></i> Export CSV
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <a class="dropdown-item" href="export_rab.php?id=<?= $projectId ?>&format=report">
                        <i class="mdi mdi-file-document-outline"></i> Export Laporan
                        <small class="d-block text-muted">Format lengkap dengan header dan total</small>
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="export_rab.php?id=<?= $projectId ?>&format=import">
                        <i class="mdi mdi-upload"></i> Export untuk Import
                        <small class="d-block text-muted">Format sederhana (Kategori | Kode AHSP | Volume)</small>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>

<!-- RAB Table -->
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
                <th width="130">Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rabData)): ?>
            <tr>
                <td colspan="10" class="text-center text-muted py-4">
                    Belum ada data RAB. Klik "Tambah Kategori" untuk memulai.
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
                    <div class="d-flex justify-content-between align-items-center">
                        <strong class=""><?= sanitize($cat['code']) ?>. <?= sanitize($cat['name']) ?></strong>
                        <?php if ($isEditable): ?>
                        <div>
                            <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#addSubcategoryModal"
                                    data-category-id="<?= $cat['id'] ?>">
                                <i class="mdi mdi-plus"></i> Sub
                            </button>
                            <form method="POST" class="d-inline" id="deleteCatForm_<?= $cat['id'] ?>">
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                <button type="button" class="btn btn-sm btn-danger" onclick="confirmDelete(document.getElementById('deleteCatForm_<?= $cat['id'] ?>'))">
                                    <i class="mdi mdi-delete"></i>
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            
            <!-- Subcategories -->
            <?php foreach ($subcats as $sub): 
                $subTotal = $sub['volume'] * $sub['unit_price'];
            ?>
                <tr data-category-id="<?= $cat['id'] ?>">
                    <td><?= sanitize($sub['code']) ?></td>
                    <td><?= sanitize($sub['name']) ?></td>
                    <td><?= sanitize($sub['unit']) ?></td>
                    <td>
                        <input type="text" 
                               class="form-control form-control-sm border-0 text-end inline-ajax" 
                               value="<?= formatVolume($sub['volume']) ?>" 
                               style="width:80px;" 
                               data-ajax-url="view.php?id=<?= $projectId ?>"
                               data-action="ajax_update_rab_volume"
                               data-id="<?= $sub['id'] ?>"
                               data-field="volume"
                               data-format="decimal"
                               data-unit-price="<?= $sub['unit_price_display'] ?>"
                               data-unit-price-tenaga="<?= $sub['ahsp_tenaga'] ?? 0 ?>"
                               data-unit-price-bahan="<?= $sub['ahsp_bahan'] ?? 0 ?>"
                               data-unit-price-alat="<?= $sub['ahsp_alat'] ?? 0 ?>"
                               <?= !$isEditable ? 'disabled' : '' ?>>
                    </td>
                    <td class="text-end"><?= formatNumber($sub['unit_price_display']) ?></td>
                    <td class="text-end" id="jumlah-<?= $sub['id'] ?>"><?= formatNumber($sub['volume'] * $sub['unit_price_display']) ?></td>
                    <td class="text-end" id="tenaga-<?= $sub['id'] ?>"><?= formatNumber($sub['anggaran_tenaga']) ?></td>
                    <td class="text-end" id="bahan-<?= $sub['id'] ?>"><?= formatNumber($sub['anggaran_bahan']) ?></td>
                    <td class="text-end" id="alat-<?= $sub['id'] ?>"><?= formatNumber($sub['anggaran_alat']) ?></td>
                    <td>
                        <a href="ahsp.php?id=<?= $sub['id'] ?>" class="btn btn-sm btn-secondary" title="Lihat AHSP">
                            <i class="mdi mdi-file-table-outline"></i> AHSP
                        </a>
                        <?php if ($isEditable): ?>
                        <button type="button" class="btn btn-sm btn-danger" onclick="deleteSubcat(<?= $sub['id'] ?>)"><i class="mdi mdi-delete"></i></button>
                        <?php endif; ?>
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
                <td colspan="5" class="text-end">
                    <strong>PPN <?= formatNumber($ppnPercentage, 2) ?>%</strong>
                    <?php if ($isEditable): ?>
                    <button type="button" class="btn btn-sm btn-link p-0 ms-1" data-bs-toggle="modal" data-bs-target="#editPpnModal">
                        <i class="mdi mdi-pencil"></i>
                    </button>
                    <?php endif; ?>
                </td>
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
                <td colspan="5" class="text-end"><strong class="">JUMLAH TOTAL DIBULATKAN</strong></td>
                <td class="text-end"><strong class=""><?= formatRupiah($totalRounded) ?></strong></td>
                <td colspan="4"></td>
            </tr>
        </tfoot>
    </table>
</div>

<!-- Submit/Reopen Buttons -->
<div class="d-flex justify-content-end mt-3 gap-2">
    <?php if ($isEditable && !empty($rabData)): ?>
    <form method="POST" id="submitRabForm">
        <input type="hidden" name="action" value="submit_rab">
        <button type="button" class="btn btn-success" onclick="confirmAction(function(){ document.getElementById('submitRabForm').submit(); }, {title: 'Submit RAB', message: 'Setelah di-submit, RAB tidak dapat diedit lagi. Lanjutkan?', buttonText: 'Submit', buttonClass: 'btn-success'})">
            <i class="mdi mdi-check-all"></i> Submit RAB
        </button>
    </form>
    <?php elseif ($project['rab_submitted'] && $project['status'] === 'draft' && isAdmin()): ?>
    <form method="POST" id="reopenRabForm">
        <input type="hidden" name="action" value="reopen_rab">
        <button type="button" class="btn btn-warning" onclick="confirmAction(function(){ document.getElementById('reopenRabForm').submit(); }, {title: 'Buka Kembali RAB', message: 'RAB akan dibuka untuk diedit. Lanjutkan?', buttonText: 'Buka', buttonClass: 'btn-warning'})">
            <i class="mdi mdi-lock-open"></i> Buka Kembali
        </button>
    </form>
    <?php endif; ?>
</div>

<!-- Create Snapshot Modal -->
<div class="modal fade" id="createSnapshotModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="create_snapshot">
            <div class="modal-header">
                <h5 class="modal-title">Buat Salinan RAB</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Salinan RAB adalah snapshot dari RAB saat ini yang dapat diedit secara terpisah tanpa mempengaruhi RAB asli. Berguna untuk keperluan MC0 atau CCO.</p>
                <div class="mb-3">
                    <label class="form-label required">Jenis Salinan</label>
                    <select class="form-select" name="snapshot_name" required>
                        <option value="">-- Pilih Jenis --</option>
                        <option value="MC0">MC0</option>
                        <option value="CCO">CCO</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Deskripsi (Opsional)</label>
                    <textarea class="form-control" name="snapshot_description" rows="2" 
                              placeholder="Catatan tambahan tentang salinan ini"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Buat Salinan</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="add_category">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Kategori</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label required">Nama Kategori</label>
                    <input type="text" class="form-control" name="name" required 
                           placeholder="Contoh: PEKERJAAN PERSIAPAN">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Import RAB Modal -->
<div class="modal fade" id="importRabModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="action" value="import_rab">
            <div class="modal-header">
                <h5 class="modal-title">Import RAB dari CSV</h5>
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

<!-- Add Subcategory Modal -->
<div class="modal fade" id="addSubcategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="add_subcategory">
            <input type="hidden" name="category_id" id="add_subcat_category_id">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Sub-Kategori</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label required">Pilih Pekerjaan (dari AHSP)</label>
                    <select class="form-select select2-ahsp" name="ahsp_id" id="select_ahsp_id" required>
                        <option value="">-- Ketik untuk mencari AHSP --</option>
                        <?php 
                        $overheadPct = $project['overhead_percentage'] ?? 10;
                        foreach ($ahspList as $ahsp): 
                            $priceWithOverhead = $ahsp['unit_price'] * (1 + ($overheadPct / 100));
                        ?>
                        <option value="<?= $ahsp['id'] ?>" data-unit="<?= sanitize($ahsp['unit']) ?>" data-price="<?= $priceWithOverhead ?>">
                            <?= sanitize($ahsp['work_name']) ?> (<?= formatRupiah($priceWithOverhead) ?>/<?= $ahsp['unit'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Harga sudah termasuk overhead <?= formatNumber($overheadPct, 0) ?>%</small>
                </div>
                <div class="mb-3">
                    <label class="form-label required">Volume</label>
                    <input type="text" class="form-control text-end" name="volume" required placeholder="0">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit PPN Modal -->
<div class="modal fade" id="editPpnModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="update_ppn">
            <div class="modal-header">
                <h5 class="modal-title">Edit PPN</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Persentase PPN (%)</label>
                    <input type="number" step="0.01" class="form-control" name="ppn_percentage" 
                           value="<?= $ppnPercentage ?>" min="0" max="100" required>
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
// Scripts to be loaded after jQuery (in footer.php)
ob_start(); 
?>
<script>
$(document).ready(function() {
    // Initialize Select2 for AHSP dropdown when modal is shown
    $('#addSubcategoryModal').on('shown.bs.modal', function () {
        // Initialize Select2 inside modal
        $('.select2-ahsp').select2({
            placeholder: '-- Ketik untuk mencari AHSP --',
            allowClear: true,
            width: '100%',
            dropdownParent: $('#addSubcategoryModal'),
            language: {
                noResults: function() {
                    return 'AHSP tidak ditemukan';
                },
                searching: function() {
                    return 'Mencari...';
                }
            }
        });
    });

    // Reset Select2 when modal is closed
    $('#addSubcategoryModal').on('hidden.bs.modal', function () {
        $('.select2-ahsp').val('').trigger('change');
        // Destroy and let it reinitialize on next open
        if ($('.select2-ahsp').data('select2')) {
            $('.select2-ahsp').select2('destroy');
        }
    });

    // Add Subcategory Modal - set category ID
    $('#addSubcategoryModal').on('show.bs.modal', function (e) {
        var categoryId = $(e.relatedTarget).data('categoryId');
        $('#add_subcat_category_id').val(categoryId);
    });
});

// Delete Subcategory Function
function deleteSubcat(subcatId) {
    confirmDelete(function() {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="delete_subcategory"><input type="hidden" name="subcategory_id" value="' + subcatId + '">';
        document.body.appendChild(form);
        form.submit();
    });
}

// Delete Category Function
function deleteCategory(catId) {
    confirmDelete(function() {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="delete_category"><input type="hidden" name="category_id" value="' + catId + '">';
        document.body.appendChild(form);
        form.submit();
    });
}

// Delete Snapshot Function
function deleteSnapshot(snapshotId) {
    confirmDelete(function() {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="delete_snapshot"><input type="hidden" name="snapshot_id" value="' + snapshotId + '">';
        document.body.appendChild(form);
        form.submit();
    });
}
</script>
<?php 
$extraScripts = ob_get_clean();
?>

