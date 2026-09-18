<?php
/**
 * RAB Tab - Embedded in Project View
 * Displays RAB table with inline editing, Head-Sub grouping, and collapse/expand
 * Variables available from parent view.php: $project, $projectId
 */

// Ensure database tables exist
ensureRabHeadSubsTableExists();

// RAB can be viewed anytime, but only edited when draft AND not yet submitted AND not locked AND user has rab.edit
$isEditable = ($project['status'] === 'draft' && !$project['rab_submitted'] && !isProjectLocked($project) && hasPermission('rab.edit'));

// Get available AHSP for this project
$ahspList = dbGetAll("SELECT * FROM project_ahsp WHERE project_id = ? ORDER BY work_name", [$projectId]);

// Batch-load ALL AHSP component breakdowns in 1 query (instead of N queries in loop)
$ahspBreakdownMap = batchGetAhspComponentBreakdowns($projectId);

// Get Head-Subs and Categories
$headSubs = dbGetAll("SELECT * FROM rab_head_subs WHERE project_id = ? ORDER BY sort_order, id", [$projectId]);
$categories = dbGetAll("SELECT * FROM rab_categories WHERE project_id = ? ORDER BY sort_order, LENGTH(code), code, id", [$projectId]);

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
$overheadPct = getProjectOverheadProfitPct($project);

foreach ($categories as $cat) {
    $subcats = dbGetAll("SELECT * FROM rab_subcategories WHERE category_id = ? ORDER BY sort_order, code", [$cat['id']]);
    $catTotal = 0;
    $catTenaga = 0;
    $catBahan = 0;
    $catAlat = 0;
    
    $enrichedSubcats = [];
    foreach ($subcats as $sub) {
        // Use pre-loaded batch map instead of per-subcategory query
        $components = $ahspBreakdownMap[$sub['ahsp_id']] ?? ['upah' => 0, 'material' => 0, 'alat' => 0, 'total' => 0];
        
        $baseUnitPrice = $components['total'];
        $unitPriceWithOverhead = $baseUnitPrice * (1 + ($overheadPct / 100));
        $sub['unit_price_display'] = $unitPriceWithOverhead;
        
        $subTotal = $sub['volume'] * $unitPriceWithOverhead;
        $catTotal += $subTotal;
        
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
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <h5 class="mb-0"><?= sanitize($project['name']) ?></h5>
        <!-- Search Input for RAB -->
        <div class="input-group input-group-sm ms-md-2" style="width: 240px;">
            <span class="input-group-text bg-white border-end-0"><i class="mdi mdi-magnify text-muted"></i></span>
            <input type="text" class="form-control border-start-0" id="searchRabInput" placeholder="Cari pekerjaan, kode..." autocomplete="off">
            <button class="btn btn-outline-secondary border-start-0 d-none" type="button" id="clearSearchRabBtn" title="Reset pencarian">
                <i class="mdi mdi-close"></i>
            </button>
        </div>
        <span id="rabSearchResultCount" class="badge bg-primary-subtle text-primary small d-none"></span>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <!-- Expand / Collapse All -->
        <button type="button" class="btn btn-outline-secondary btn-sm text-nowrap" onclick="toggleAllRabRows(true)" title="Buka Semua Tampilan Tabel">
            <i class="mdi mdi-unfold-more-horizontal"></i> Buka Semua
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm text-nowrap" onclick="toggleAllRabRows(false)" title="Tutup Semua Tampilan Tabel">
            <i class="mdi mdi-unfold-less-horizontal"></i> Tutup Semua
        </button>

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
        <?php if (!empty($categories)): ?>
        <button class="btn btn-outline-primary btn-sm text-nowrap" data-bs-toggle="modal" data-bs-target="#rearrangeCategoryModal" title="Atur Urutan Kategori RAB">
            <i class="mdi mdi-sort-variant"></i> Atur Urutan
        </button>
        <?php endif; ?>
        <button class="btn btn-outline-primary btn-sm text-nowrap" data-bs-toggle="modal" data-bs-target="#addHeadSubModal">
            <i class="mdi mdi-folder-plus-outline"></i> Tambah Head-Sub
        </button>
        <button class="btn btn-primary btn-sm text-nowrap" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
            <i class="mdi mdi-plus"></i> Tambah Kategori
        </button>
        <?php endif; ?>
        <!-- Export Dropdown -->
        <div class="dropdown">
            <button class="btn btn-success btn-sm dropdown-toggle text-nowrap" type="button" data-bs-toggle="dropdown">
                <i class="mdi mdi-file-export-outline"></i> Export
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><h6 class="dropdown-header">Export CSV</h6></li>
                <li>
                    <a class="dropdown-item" href="export_rab.php?id=<?= $projectId ?>&format=report">
                        <i class="mdi mdi-file-delimited-outline"></i> CSV Laporan
                        <small class="d-block text-muted">Format lengkap dengan header dan total</small>
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="export_rab.php?id=<?= $projectId ?>&format=import">
                        <i class="mdi mdi-file-upload-outline"></i> CSV untuk Import
                        <small class="d-block text-muted">Format sederhana</small>
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li><h6 class="dropdown-header">Export PDF</h6></li>
                <li>
                    <a class="dropdown-item" href="#" onclick="openPdfPreview(); return false;">
                        <i class="mdi mdi-file-pdf-box text-danger"></i> Export PDF
                        <small class="d-block text-muted">Preview laporan lalu cetak/export ke PDF</small>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>

<!-- RAB Table Styles (Sticky Floating Header) -->
<style>
/* Ensure main-content allows sticky to viewport */
.main-content {
    overflow: visible !important;
}

/* Floating Synced Sticky Header for RAB */
.rab-floating-header-wrapper {
    position: fixed;
    top: 70px;
    z-index: 999;
    overflow-x: hidden;
    overflow-y: hidden;
    display: none;
    box-shadow: 0 6px 12px rgba(0,0,0,0.25);
    background-color: #212529;
    border-bottom: 2px solid #212529;
}
.rab-floating-header-wrapper .rab-table,
.rab-floating-header-wrapper table {
    margin-bottom: 0 !important;
}
.rab-floating-header-wrapper th {
    border-top: none !important;
    background-color: #212529 !important;
    color: #fff !important;
    vertical-align: middle !important;
}

@media print {
    .rab-floating-header-wrapper {
        display: none !important;
    }
}
</style>

<!-- RAB Table -->
<div class="table-responsive rab-scroll-wrapper" id="rabTableWrapper">
    <table class="table table-bordered mb-0 rab-table" id="rabTable">
        <thead class="table-dark">
            <tr class="rab-header-row">
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
            <?php if (empty($headSubs) && empty($categories)): ?>
            <tr>
                <td colspan="10" class="text-center text-muted py-4">
                    Belum ada data RAB. Klik "Tambah Kategori" atau "Tambah Head-Sub" untuk memulai.
                </td>
            </tr>
            <?php else: ?>

            <!-- Render Head-Sub Groups -->
            <?php foreach ($headSubMap as $hsId => $hsGroup):
                $hs = $hsGroup['head_sub'];
                $hsCats = $hsGroup['categories'];
            ?>
                <!-- Head-Sub Row (Dropzone) -->
                <tr class="table-dark head-sub-row dropzone-head-sub" data-hs-id="<?= $hsId ?>" data-drop-text="📥 Drop Kategori ke <?= sanitize($hs['name']) ?> Di Sini">

                    <td colspan="10" class="py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-sm btn-outline-light p-0 px-2 toggle-hs-btn" onclick="toggleHeadSub(<?= $hsId ?>)" title="Tutup / Buka Head-Sub">
                                    <i class="mdi mdi-chevron-down font-size-16" id="hs-chevron-<?= $hsId ?>"></i>
                                </button>
                                <strong class="font-size-14 text-uppercase text-warning">
                                    <?php if (!empty($hs['code'])): ?>
                                        <span class="badge bg-warning text-dark me-2"><?= sanitize($hs['code']) ?></span>
                                    <?php endif; ?>
                                    <?= sanitize($hs['name']) ?>
                                </strong>
                            </div>
                            <?php if ($isEditable): ?>
                            <div class="d-flex gap-1 align-items-center">
                                <small class="text-white-50 font-size-11 me-2 d-none d-md-inline"><i class="mdi mdi-drag-variant"></i> Drop kategori ke sini</small>
                                <button type="button" class="btn btn-sm btn-light py-0" data-bs-toggle="modal" data-bs-target="#addCategoryModal" data-head-sub-id="<?= $hsId ?>" title="Tambah Kategori dalam Head-Sub ini">
                                    <i class="mdi mdi-plus"></i> Kategori
                                </button>
                                <button type="button" class="btn btn-sm btn-warning py-0" onclick="editHeadSub(<?= $hsId ?>, '<?= addslashes(sanitize($hs['code'] ?? '')) ?>', '<?= addslashes(sanitize($hs['name'])) ?>')" title="Edit Head-Sub">
                                    <i class="mdi mdi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-danger py-0" onclick="deleteHeadSub(<?= $hsId ?>)" title="Hapus Head-Sub">
                                    <i class="mdi mdi-delete"></i>
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>

                <?php if (empty($hsCats)): ?>
                <tr class="hs-item-<?= $hsId ?> table-light">
                    <td colspan="10" class="text-center text-muted py-2 small fs-13">
                        <em>Belum ada kategori di dalam Head-Sub ini. Drag kategori dari tempat lain ke header Head-Sub di atas untuk memasukkannya.</em>
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($hsCats as $catId => $data):
                        $cat = $data['category'];
                        $subcats = $data['subcategories'];
                    ?>
                        <!-- Category Header Row -->
                        <tr class="table-primary category-row dropzone-cat hs-item-<?= $hsId ?>" data-cat-id="<?= $catId ?>" data-hs-id="<?= $hsId ?>" data-cat-name="<?= htmlspecialchars(sanitize($cat['name'])) ?>">
                            <td colspan="10" class="py-2">
                                <div class="d-flex justify-content-between align-items-center" style="padding-left: 15px;">
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if ($isEditable): ?>
                                        <span class="drag-handle" draggable="true" data-cat-id="<?= $catId ?>" style="cursor: grab; display: inline-flex; align-items: center; padding: 2px 4px;" title="Seret ikon ini untuk mengatur urutan kategori atau memindahkan ke Head-Sub">
                                            <i class="mdi mdi-drag font-size-18 text-white-50"></i>
                                        </span>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-primary p-0 px-2 toggle-cat-btn" onclick="toggleCategory(<?= $catId ?>)" title="Tutup / Buka Kategori" draggable="false">
                                            <i class="mdi mdi-chevron-down font-size-15" id="cat-chevron-<?= $catId ?>"></i>
                                        </button>
                                        <strong class="font-size-14"><?= sanitize($cat['code']) ?>. <?= sanitize($cat['name']) ?></strong>
                                    </div>
                                    <?php if ($isEditable): ?>
                                    <div class="d-flex gap-1 align-items-center">
                                        <!-- Quick Move Up / Down Buttons -->
                                        <button type="button" class="btn btn-sm btn-outline-light py-0" onclick="moveCategoryDirection(<?= $catId ?>, 'up')" title="Geser Kategori Ke Atas" draggable="false">
                                            <i class="mdi mdi-arrow-up"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-light py-0" onclick="moveCategoryDirection(<?= $catId ?>, 'down')" title="Geser Kategori Ke Bawah" draggable="false">
                                            <i class="mdi mdi-arrow-down"></i>
                                        </button>
                                        <?php if (!empty($headSubs)): ?>
                                        <!-- Quick Move Dropdown -->
                                        <div class="dropdown d-inline-block">
                                            <button type="button" class="btn btn-sm btn-outline-light py-0 dropdown-toggle" data-bs-toggle="dropdown" title="Pindahkan ke Head-Sub" draggable="false">
                                                <i class="mdi mdi-folder-move"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width: 220px;">
                                                <li><h6 class="dropdown-header">Pindahkan Kategori Ke:</h6></li>
                                                <li>
                                                    <a class="dropdown-item d-flex align-items-center gap-2 <?= empty($cat['head_sub_id']) ? 'active' : '' ?>" href="javascript:void(0);" onclick="moveCategoryToHeadSub(<?= $catId ?>, 0)">
                                                        <i class="mdi mdi-folder-outline text-secondary"></i> Tanpa Head-Sub
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <?php foreach ($headSubs as $hsItem): ?>
                                                <li>
                                                    <a class="dropdown-item d-flex align-items-center gap-2 <?= ($cat['head_sub_id'] ?? 0) == $hsItem['id'] ? 'active' : '' ?>" href="javascript:void(0);" onclick="moveCategoryToHeadSub(<?= $catId ?>, <?= $hsItem['id'] ?>)">
                                                        <i class="mdi mdi-folder text-warning"></i>
                                                        <span><?= !empty($hsItem['code']) ? sanitize($hsItem['code']) . ' - ' : '' ?><?= sanitize($hsItem['name']) ?></span>
                                                    </a>
                                                </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-light py-0" data-bs-toggle="modal" data-bs-target="#addSubcategoryModal" data-category-id="<?= $cat['id'] ?>" draggable="false">
                                            <i class="mdi mdi-plus"></i> Sub
                                        </button>
                                        <button type="button" class="btn btn-sm btn-warning py-0" onclick="editCategory(<?= $cat['id'] ?>, '<?= addslashes(sanitize($cat['name'])) ?>', '<?= $cat['head_sub_id'] ?? '' ?>')" title="Edit Kategori" draggable="false">
                                            <i class="mdi mdi-pencil"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger py-0" onclick="confirmDelete(document.getElementById('deleteCatForm_<?= $cat['id'] ?>'))" title="Hapus Kategori" draggable="false">
                                            <i class="mdi mdi-delete"></i>
                                        </button>
                                        <form method="POST" class="d-none" id="deleteCatForm_<?= $cat['id'] ?>">
                                            <input type="hidden" name="action" value="delete_category">
                                            <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                        </form>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                        <!-- Subcategories -->
                        <?php foreach ($subcats as $sub): ?>
                        <tr class="hs-item-<?= $hsId ?> cat-item-<?= $catId ?>">
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
                                <?php if ($sub['ahsp_id']): ?>
                                <button type="button" class="btn btn-sm btn-secondary" title="Lihat AHSP" onclick="showAhspModal(<?= $sub['ahsp_id'] ?>)">
                                    <i class="mdi mdi-file-table-outline"></i> AHSP
                                </button>
                                <?php endif; ?>
                                <?php if ($isEditable): ?>
                                <button type="button" class="btn btn-sm btn-danger" onclick="deleteSubcat(<?= $sub['id'] ?>)"><i class="mdi mdi-delete"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <!-- Category Total Row -->
                        <tr class="table-secondary hs-item-<?= $hsId ?> cat-item-<?= $catId ?>">
                            <td colspan="5" class="text-end"><strong>JUMLAH <?= sanitize($cat['code']) ?></strong></td>
                            <td class="text-end" id="cat-total-<?= $cat['id'] ?>"><strong><?= formatNumber($data['total']) ?></strong></td>
                            <td class="text-end" id="cat-tenaga-<?= $cat['id'] ?>"><strong><?= formatNumber($data['total_tenaga']) ?></strong></td>
                            <td class="text-end" id="cat-bahan-<?= $cat['id'] ?>"><strong><?= formatNumber($data['total_bahan']) ?></strong></td>
                            <td class="text-end" id="cat-alat-<?= $cat['id'] ?>"><strong><?= formatNumber($data['total_alat']) ?></strong></td>
                            <td></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Head-Sub Total Row -->
                <tr class="table-info hs-item-<?= $hsId ?>">
                    <td colspan="5" class="text-end"><strong>JUMLAH <?= sanitize($hs['name']) ?></strong></td>
                    <td class="text-end"><strong><?= formatNumber($hsGroup['total']) ?></strong></td>
                    <td class="text-end"><strong><?= formatNumber($hsGroup['total_tenaga']) ?></strong></td>
                    <td class="text-end"><strong><?= formatNumber($hsGroup['total_bahan']) ?></strong></td>
                    <td class="text-end"><strong><?= formatNumber($hsGroup['total_alat']) ?></strong></td>
                    <td></td>
                </tr>
            <?php endforeach; ?>


            <!-- Render Standalone Categories (without Head-Sub) -->
            <?php if (!empty($standaloneCats)): ?>
                <?php if (!empty($headSubs)): ?>
                <tr class="table-dark dropzone-head-sub" data-hs-id="0" data-drop-text="📂 Drop Di Sini untuk Melepas Kategori dari Head-Sub">
                    <td colspan="10" class="py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong class="font-size-14 text-uppercase">KATEGORI TANPA HEAD-SUB</strong>
                            <?php if ($isEditable): ?>
                            <small class="text-white-50 font-size-11"><i class="mdi mdi-drag-variant"></i> Drop kategori ke sini untuk melepas dari Head-Sub</small>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($standaloneCats as $catId => $data):
                    $cat = $data['category'];
                    $subcats = $data['subcategories'];
                ?>
                    <!-- Standalone Category Header Row -->
                    <tr class="table-primary category-row dropzone-cat" data-cat-id="<?= $catId ?>" data-hs-id="0" data-cat-name="<?= htmlspecialchars(sanitize($cat['name'])) ?>">
                        <td colspan="10" class="py-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center gap-2">
                                    <?php if ($isEditable): ?>
                                    <span class="drag-handle" draggable="true" data-cat-id="<?= $catId ?>" style="cursor: grab; display: inline-flex; align-items: center; padding: 2px 4px;" title="Seret ikon ini untuk mengatur urutan kategori atau memindahkan ke Head-Sub">
                                        <i class="mdi mdi-drag font-size-18 text-white-50"></i>
                                    </span>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-primary p-0 px-2 toggle-cat-btn" onclick="toggleCategory(<?= $catId ?>)" title="Tutup / Buka Kategori" draggable="false">
                                        <i class="mdi mdi-chevron-down font-size-15" id="cat-chevron-<?= $catId ?>"></i>
                                    </button>
                                    <strong class="font-size-14"><?= sanitize($cat['code']) ?>. <?= sanitize($cat['name']) ?></strong>
                                </div>
                                <?php if ($isEditable): ?>
                                <div class="d-flex gap-1 align-items-center">
                                    <!-- Quick Move Up / Down Buttons -->
                                    <button type="button" class="btn btn-sm btn-outline-light py-0" onclick="moveCategoryDirection(<?= $catId ?>, 'up')" title="Geser Kategori Ke Atas" draggable="false">
                                        <i class="mdi mdi-arrow-up"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-light py-0" onclick="moveCategoryDirection(<?= $catId ?>, 'down')" title="Geser Kategori Ke Bawah" draggable="false">
                                        <i class="mdi mdi-arrow-down"></i>
                                    </button>
                                    <?php if (!empty($headSubs)): ?>
                                    <!-- Quick Move Dropdown -->
                                    <div class="dropdown d-inline-block">
                                        <button type="button" class="btn btn-sm btn-outline-light py-0 dropdown-toggle" data-bs-toggle="dropdown" title="Pindahkan ke Head-Sub" draggable="false">
                                            <i class="mdi mdi-folder-move"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width: 220px;">
                                            <li><h6 class="dropdown-header">Pindahkan Kategori Ke:</h6></li>
                                            <li>
                                                <a class="dropdown-item d-flex align-items-center gap-2 active" href="javascript:void(0);" onclick="moveCategoryToHeadSub(<?= $catId ?>, 0)">
                                                    <i class="mdi mdi-folder-outline text-secondary"></i> Tanpa Head-Sub
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <?php foreach ($headSubs as $hsItem): ?>
                                            <li>
                                                <a class="dropdown-item d-flex align-items-center gap-2" href="javascript:void(0);" onclick="moveCategoryToHeadSub(<?= $catId ?>, <?= $hsItem['id'] ?>)">
                                                    <i class="mdi mdi-folder text-warning"></i>
                                                    <span><?= !empty($hsItem['code']) ? sanitize($hsItem['code']) . ' - ' : '' ?><?= sanitize($hsItem['name']) ?></span>
                                                </a>
                                            </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-light py-0" data-bs-toggle="modal" data-bs-target="#addSubcategoryModal" data-category-id="<?= $cat['id'] ?>" draggable="false">
                                        <i class="mdi mdi-plus"></i> Sub
                                    </button>
                                    <button type="button" class="btn btn-sm btn-warning py-0" onclick="editCategory(<?= $cat['id'] ?>, '<?= addslashes(sanitize($cat['name'])) ?>', '')" title="Edit Kategori" draggable="false">
                                        <i class="mdi mdi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger py-0" onclick="confirmDelete(document.getElementById('deleteCatForm_<?= $cat['id'] ?>'))" title="Hapus Kategori" draggable="false">
                                        <i class="mdi mdi-delete"></i>
                                    </button>
                                    <form method="POST" class="d-none" id="deleteCatForm_<?= $cat['id'] ?>">
                                        <input type="hidden" name="action" value="delete_category">
                                        <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>



                    <!-- Subcategories -->
                    <?php foreach ($subcats as $sub): ?>
                    <tr class="cat-item-<?= $catId ?>">
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
                            <?php if ($sub['ahsp_id']): ?>
                            <button type="button" class="btn btn-sm btn-secondary" title="Lihat AHSP" onclick="showAhspModal(<?= $sub['ahsp_id'] ?>)">
                                <i class="mdi mdi-file-table-outline"></i> AHSP
                            </button>
                            <?php endif; ?>
                            <?php if ($isEditable): ?>
                            <button type="button" class="btn btn-sm btn-danger" onclick="deleteSubcat(<?= $sub['id'] ?>)"><i class="mdi mdi-delete"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <!-- Standalone Category Total -->
                    <tr class="table-secondary cat-item-<?= $catId ?>">
                        <td colspan="5" class="text-end"><strong>JUMLAH <?= sanitize($cat['code']) ?></strong></td>
                        <td class="text-end" id="cat-total-<?= $cat['id'] ?>"><strong><?= formatNumber($data['total']) ?></strong></td>
                        <td class="text-end" id="cat-tenaga-<?= $cat['id'] ?>"><strong><?= formatNumber($data['total_tenaga']) ?></strong></td>
                        <td class="text-end" id="cat-bahan-<?= $cat['id'] ?>"><strong><?= formatNumber($data['total_bahan']) ?></strong></td>
                        <td class="text-end" id="cat-alat-<?= $cat['id'] ?>"><strong><?= formatNumber($data['total_alat']) ?></strong></td>
                        <td></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>

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
    <?php if ($isEditable && (!empty($headSubs) || !empty($categories))): ?>
    <form method="POST" id="submitRabForm">
        <input type="hidden" name="action" value="submit_rab">
        <button type="button" class="btn btn-success" onclick="confirmAction(function(){ document.getElementById('submitRabForm').submit(); }, {title: 'Submit RAB', message: 'Setelah di-submit, RAB tidak dapat diedit lagi. Lanjutkan?', buttonText: 'Submit', buttonClass: 'btn-success'})">
            <i class="mdi mdi-check-all"></i> Submit RAB
        </button>
    </form>
    <?php elseif ($project['rab_submitted'] && $project['status'] === 'draft' && hasPermission('rab.edit')): ?>
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

<!-- Add Head-Sub Modal -->
<div class="modal fade" id="addHeadSubModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="add_head_sub">
            <div class="modal-header">
                <h5 class="modal-title"><i class="mdi mdi-folder-plus-outline"></i> Tambah Head-Sub</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Kode Head-Sub (Opsional)</label>
                    <input type="text" class="form-control" name="code" placeholder="Contoh: HEAD-1, I, PEKERJAAN UTAMA">
                    <small class="text-muted">Jika dikosongkan, kode akan otomatis dibuat (HEAD-1, HEAD-2, dst)</small>
                </div>
                <div class="mb-3">
                    <label class="form-label required">Nama Head-Sub</label>
                    <input type="text" class="form-control" name="name" required placeholder="Contoh: PEKERJAAN UTAMA">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Head-Sub Modal -->
<div class="modal fade" id="editHeadSubModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="edit_head_sub">
            <input type="hidden" name="head_sub_id" id="edit_head_sub_id">
            <div class="modal-header">
                <h5 class="modal-title"><i class="mdi mdi-pencil"></i> Edit Head-Sub</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Kode Head-Sub</label>
                    <input type="text" class="form-control" name="code" id="edit_head_sub_code">
                </div>
                <div class="mb-3">
                    <label class="form-label required">Nama Head-Sub</label>
                    <input type="text" class="form-control" name="name" id="edit_head_sub_name" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
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
                <?php if (!empty($headSubs)): ?>
                <div class="mb-3">
                    <label class="form-label">Head-Sub (Opsional)</label>
                    <select class="form-select" name="head_sub_id" id="add_cat_head_sub_id">
                        <option value="">-- Tanpa Head-Sub --</option>
                        <?php foreach ($headSubs as $hs): ?>
                        <option value="<?= $hs['id'] ?>">
                            <?= !empty($hs['code']) ? sanitize($hs['code']) . ' - ' : '' ?><?= sanitize($hs['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
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

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="edit_category">
            <input type="hidden" name="category_id" id="edit_category_id">
            <div class="modal-header">
                <h5 class="modal-title"><i class="mdi mdi-pencil"></i> Edit Kategori</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if (!empty($headSubs)): ?>
                <div class="mb-3">
                    <label class="form-label">Head-Sub (Opsional)</label>
                    <select class="form-select" name="head_sub_id" id="edit_cat_head_sub_id">
                        <option value="">-- Tanpa Head-Sub --</option>
                        <?php foreach ($headSubs as $hs): ?>
                        <option value="<?= $hs['id'] ?>">
                            <?= !empty($hs['code']) ? sanitize($hs['code']) . ' - ' : '' ?><?= sanitize($hs['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label required">Nama Kategori</label>
                    <input type="text" class="form-control" name="name" id="edit_category_name" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- Rearrange Categories Modal -->
<div class="modal fade" id="rearrangeCategoryModal" tabindex="-1" aria-labelledby="rearrangeCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-light py-2">
                <h5 class="modal-title" id="rearrangeCategoryModalLabel"><i class="mdi mdi-sort-variant text-primary"></i> Atur Urutan Kategori RAB</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 small mb-3">
                    <i class="mdi mdi-information-outline"></i>
                    <strong>Petunjuk:</strong> Tarik (drag & drop) ikon <i class="mdi mdi-drag"></i> untuk mengubah urutan kategori, atau gunakan tombol panah (<strong>&uarr;</strong> / <strong>&darr;</strong>). Anda juga dapat mengubah Head-Sub masing-masing kategori melalui pilihan dropdown. Huruf kode (A, B, C...) dan sub-kategori akan otomatis disesuaikan.
                </div>
                
                <ul class="list-group sortable-cat-modal-list" id="rearrangeCatSortableList">
                    <?php 
                    $modalCatIndex = 1;
                    foreach ($categories as $mCat): 
                        $mSubCount = dbGetRow("SELECT COUNT(*) as cnt FROM rab_subcategories WHERE category_id = ?", [$mCat['id']])['cnt'] ?? 0;
                    ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3 rearrange-cat-item" 
                        data-cat-id="<?= $mCat['id'] ?>"
                        data-original-hs-id="<?= $mCat['head_sub_id'] ?? 0 ?>">
                        <div class="d-flex align-items-center gap-2 flex-grow-1 me-3">
                            <span class="modal-drag-handle text-muted" style="cursor: grab; padding: 4px;" title="Drag untuk mengubah urutan">
                                <i class="mdi mdi-drag font-size-20"></i>
                            </span>
                            <span class="badge bg-primary fs-6 modal-cat-code-badge" style="min-width: 28px; text-align: center;"><?= sanitize($mCat['code']) ?></span>
                            <div>
                                <strong class="modal-cat-name"><?= sanitize($mCat['name']) ?></strong>
                                <span class="badge bg-light text-secondary border ms-2">
                                    <i class="mdi mdi-format-list-bulleted"></i> <?= $mSubCount ?> sub
                                </span>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <?php if (!empty($headSubs)): ?>
                            <div style="min-width: 170px;">
                                <select class="form-select form-select-sm modal-cat-hs-select" style="font-size: 12px;">
                                    <option value="0" <?= empty($mCat['head_sub_id']) ? 'selected' : '' ?>>-- Tanpa Head-Sub --</option>
                                    <?php foreach ($headSubs as $mHs): ?>
                                    <option value="<?= $mHs['id'] ?>" <?= ($mCat['head_sub_id'] ?? 0) == $mHs['id'] ? 'selected' : '' ?>>
                                        <?= !empty($mHs['code']) ? sanitize($mHs['code']) . ' - ' : '' ?><?= sanitize($mHs['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary btn-modal-move-up" title="Geser ke Atas">
                                    <i class="mdi mdi-arrow-up"></i>
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-modal-move-down" title="Geser ke Bawah">
                                    <i class="mdi mdi-arrow-down"></i>
                                </button>
                            </div>
                        </div>
                    </li>
                    <?php 
                        $modalCatIndex++;
                    endforeach; 
                    ?>
                </ul>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary" onclick="resetRearrangeModalList()">
                    <i class="mdi mdi-restore"></i> Reset
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary" id="btnSaveRearrangeCat" onclick="saveRearrangeModalOrder()">
                        <i class="mdi mdi-check"></i> Simpan Urutan
                    </button>
                </div>
            </div>
        </div>
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
                    <strong>Format CSV (3 atau 4 Kolom):</strong>
                    <table class="table table-sm table-bordered mt-2 mb-0 bg-white">
                        <thead class="table-light">
                            <tr><th>Kolom A</th><th>Kolom B</th><th>Kolom C</th><th>Kolom D (Opsional)</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>Head-Sub / Kategori</td><td>Nama Kategori / Kode AHSP</td><td>Kode AHSP / Volume</td><td>Volume</td></tr>
                        </tbody>
                    </table>
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
                        $overheadPct = getProjectOverheadProfitPct($project);
                        $overheadLabel = formatOverheadProfitLabel($project);
                        foreach ($ahspList as $ahsp): 
                            $priceWithOverhead = $ahsp['unit_price'] * (1 + ($overheadPct / 100));
                        ?>
                        <option value="<?= $ahsp['id'] ?>" data-unit="<?= sanitize($ahsp['unit']) ?>" data-price="<?= $priceWithOverhead ?>">
                            <?= sanitize($ahsp['work_name']) ?> (<?= formatRupiah($priceWithOverhead) ?>/<?= $ahsp['unit'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Harga sudah termasuk <?= $overheadLabel ?></small>
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

<!-- Edit Pengaturan Anggaran & PPN Modal -->
<div class="modal fade" id="editPpnModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="update_ppn">
            <div class="modal-header">
                <h5 class="modal-title"><i class="mdi mdi-calculator"></i> Edit Pengaturan Anggaran & PPN</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Overhead (%)</label>
                        <input type="number" step="0.1" class="form-control" name="overhead_percentage" id="modal_oh_pct"
                               value="<?= $project['overhead_percentage'] ?? 10 ?>" min="0" max="100" required oninput="calcModalTotalOh()">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Profit (%)</label>
                        <input type="number" step="0.1" class="form-control" name="profit_percentage" id="modal_pf_pct"
                               value="<?= $project['profit_percentage'] ?? 0 ?>" min="0" max="100" required oninput="calcModalTotalOh()">
                    </div>
                </div>
                <div class="alert alert-info py-2 mb-3">
                    <small>
                        <i class="mdi mdi-information-outline"></i>
                        Total Overhead & Profit pada AHSP: <strong><span id="modal_preview_total_oh"><?= (floatval($project['overhead_percentage'] ?? 10) + floatval($project['profit_percentage'] ?? 0)) ?></span>%</strong>
                    </small>
                </div>
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
<script>
function calcModalTotalOh() {
    var oh = parseFloat(document.getElementById('modal_oh_pct').value) || 0;
    var pf = parseFloat(document.getElementById('modal_pf_pct').value) || 0;
    var total = (oh + pf).toFixed(1).replace(/\.0$/, '');
    var elem = document.getElementById('modal_preview_total_oh');
    if (elem) elem.textContent = total;
}
</script>

<style>
.drag-handle[draggable="true"] {
    cursor: grab !important;
    user-select: none;
    display: inline-flex;
    align-items: center;
    padding: 2px 5px;
    border: 1px dashed rgba(255, 255, 255, 0.4);
    border-radius: 4px;
}
.drag-handle[draggable="true"]:active {
    cursor: grabbing !important;
}
.category-row.is-dragging {
    opacity: 0.35;
    background-color: #bbdefb !important;
}
body.is-dragging-active .dropzone-head-sub * {
    pointer-events: none !important;
}
.dropzone-head-sub {
    position: relative;
    transition: background-color 0.15s ease, outline 0.15s ease;
}
.dropzone-head-sub.drag-over {
    background-color: #ffc107 !important;
    color: #000 !important;
    outline: 3px dashed #fd7e14 !important;
}
.dropzone-head-sub.drag-over td,
.dropzone-head-sub.drag-over strong,
.dropzone-head-sub.drag-over span {
    color: #000 !important;
}

/* Blur Box Overlay Effect on Head-Sub Dropzone */
.dropzone-head-sub.drag-over::after {
    content: attr(data-drop-text);
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255, 193, 7, 0.90);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    color: #000;
    font-weight: 700;
    font-size: 15px;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px dashed #000;
    border-radius: 4px;
    z-index: 999;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    animation: dropzonePulse 1s infinite alternate;
}

@keyframes dropzonePulse {
    0% { background: rgba(255, 193, 7, 0.88); border-color: #000; }
    100% { background: rgba(255, 152, 0, 0.96); border-color: #ffffff; }
}

/* Category Row Droppable Indicator */
.category-row.dropzone-cat {
    transition: background-color 0.15s ease, border-top 0.15s ease, border-bottom 0.15s ease;
    position: relative;
}
.category-row.dropzone-cat.drag-over-top {
    border-top: 4px solid #fd7e14 !important;
    background-color: #fff3cd !important;
}
.category-row.dropzone-cat.drag-over-bottom {
    border-bottom: 4px solid #fd7e14 !important;
    background-color: #fff3cd !important;
}

/* Modal Rearrange Styles */
.rearrange-cat-placeholder {
    height: 48px;
    background: #e9ecef;
    border: 2px dashed #0d6efd;
    border-radius: 6px;
    margin-bottom: 6px;
}
.rearrange-cat-item {
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    border-radius: 6px !important;
    margin-bottom: 6px;
    border: 1px solid #dee2e6;
    background: #ffffff;
}
.rearrange-cat-item:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.modal-drag-handle:hover {
    color: #0d6efd !important;
}
</style>


<?php 
// Scripts to be loaded after jQuery in footer.php
ob_start(); 
?>
<!-- Include jQuery UI -->
<script src="<?= $baseUrl ?>/dist/assets/libs/jquery-ui-dist/jquery-ui.min.js"></script>

<script>
$(document).ready(function() {
    <?php if ($isEditable): ?>
    // Make category drag handles draggable using jQuery UI
    $('.drag-handle').draggable({
        helper: function() {
            var catRow = $(this).closest('.category-row');
            var catTitle = catRow.find('strong').text() || 'Kategori';
            return $('<div class="drag-helper-card"><i class="mdi mdi-cursor-move me-2"></i>' + catTitle + '</div>').appendTo('body');
        },
        revert: 'invalid',
        cursor: 'grabbing',
        cursorAt: { top: 15, left: 15 },
        start: function(event, ui) {
            var catId = $(this).attr('data-cat-id');
            $(this).closest('.category-row').addClass('is-dragging');
            $('body').addClass('is-dragging-active');
            
            ui.helper.css({
                'background': '#1f2d3d',
                'color': '#ffc107',
                'padding': '8px 16px',
                'border-radius': '6px',
                'border': '2px solid #ffc107',
                'box-shadow': '0 6px 20px rgba(0,0,0,0.4)',
                'font-size': '14px',
                'font-weight': 'bold',
                'z-index': 99999
            });
        },
        stop: function(event, ui) {
            $('body').removeClass('is-dragging-active');
            $('.category-row').removeClass('is-dragging');
            $('.dropzone-head-sub').removeClass('drag-over');
            $('.category-row').removeClass('drag-over-top drag-over-bottom');
        }
    });

    // Make Category Rows droppable for relative ordering (Before / After)
    $('.category-row.dropzone-cat').droppable({
        accept: '.drag-handle',
        tolerance: 'pointer',
        over: function(event, ui) {
            var sourceCatId = ui.draggable.attr('data-cat-id');
            var targetCatId = $(this).attr('data-cat-id');
            if (sourceCatId == targetCatId) return;

            var offset = $(this).offset();
            var height = $(this).outerHeight();
            var posY = event.pageY - offset.top;

            if (posY < height / 2) {
                $(this).addClass('drag-over-top').removeClass('drag-over-bottom');
            } else {
                $(this).addClass('drag-over-bottom').removeClass('drag-over-top');
            }
        },
        out: function(event, ui) {
            $(this).removeClass('drag-over-top drag-over-bottom');
        },
        drop: function(event, ui) {
            var sourceCatId = ui.draggable.attr('data-cat-id');
            var targetCatId = $(this).attr('data-cat-id');
            $(this).removeClass('drag-over-top drag-over-bottom');

            if (!sourceCatId || !targetCatId || sourceCatId == targetCatId) return;

            var offset = $(this).offset();
            var height = $(this).outerHeight();
            var posY = event.pageY - offset.top;
            var position = (posY < height / 2) ? 'before' : 'after';

            reorderCategoryRelative(sourceCatId, targetCatId, position);
        }
    });

    // Make Head-Sub rows droppable using jQuery UI
    $('.dropzone-head-sub').droppable({
        accept: '.drag-handle',
        hoverClass: 'drag-over',
        tolerance: 'pointer',
        drop: function(event, ui) {
            var hsId = $(this).attr('data-hs-id');
            var catId = ui.draggable.attr('data-cat-id');

            if (catId) {
                reorderCategoryRelative(catId, 0, 'after', hsId);
            }
        }
    });
    <?php endif; ?>

    // Initialize Select2 for AHSP dropdown
    $('#addSubcategoryModal').on('shown.bs.modal', function () {
        $('.select2-ahsp').select2({
            placeholder: '-- Ketik untuk mencari AHSP --',
            allowClear: true,
            width: '100%',
            dropdownParent: $('#addSubcategoryModal'),
            language: {
                noResults: function() { return 'AHSP tidak ditemukan'; },
                searching: function() { return 'Mencari...'; }
            }
        });
    });

    $('#addSubcategoryModal').on('hidden.bs.modal', function () {
        $('.select2-ahsp').val('').trigger('change');
        if ($('.select2-ahsp').data('select2')) {
            $('.select2-ahsp').select2('destroy');
        }
    });

    $('#addSubcategoryModal').on('show.bs.modal', function (e) {
        var categoryId = $(e.relatedTarget).data('categoryId');
        $('#add_subcat_category_id').val(categoryId);
    });

    $('#addCategoryModal').on('show.bs.modal', function (e) {
        var headSubId = $(e.relatedTarget).data('headSubId');
        if (headSubId) {
            $('#add_cat_head_sub_id').val(headSubId);
        }
    });

    // Initialize Modal Sortable List
    if ($('#rearrangeCatSortableList').length) {
        $('#rearrangeCatSortableList').sortable({
            handle: '.modal-drag-handle',
            placeholder: 'rearrange-cat-placeholder',
            axis: 'y',
            cursor: 'grabbing',
            update: function(event, ui) {
                updateModalLetterBadges();
            }
        });
    }

    // Modal Move Up Button
    $(document).on('click', '.btn-modal-move-up', function(e) {
        e.preventDefault();
        var item = $(this).closest('.rearrange-cat-item');
        var prev = item.prev('.rearrange-cat-item');
        if (prev.length) {
            item.insertBefore(prev);
            updateModalLetterBadges();
        }
    });

    // Modal Move Down Button
    $(document).on('click', '.btn-modal-move-down', function(e) {
        e.preventDefault();
        var item = $(this).closest('.rearrange-cat-item');
        var next = item.next('.rearrange-cat-item');
        if (next.length) {
            item.insertAfter(next);
            updateModalLetterBadges();
        }
    });
});

var pIdRab = <?= intval($projectId) ?>;
var hsKeyRab = 'pcm_rab_collapsed_hs_' + pIdRab;
var catKeyRab = 'pcm_rab_collapsed_cat_' + pIdRab;

function getCollapsedRab(key) {
    try { return JSON.parse(sessionStorage.getItem(key) || '[]'); } catch(e) { return []; }
}
function saveCollapsedRab(key, list) {
    sessionStorage.setItem(key, JSON.stringify(list));
}

// Toggle Head-Sub rows visibility
function toggleHeadSub(hsId) {
    var icon = $('#hs-chevron-' + hsId);
    var list = getCollapsedRab(hsKeyRab);
    if (icon.hasClass('mdi-chevron-right')) {
        icon.removeClass('mdi-chevron-right').addClass('mdi-chevron-down');
        $('.hs-item-' + hsId).show();
        // Keep collapsed categories hidden
        var catList = getCollapsedRab(catKeyRab);
        catList.forEach(function(catId) {
            $('.cat-item-' + catId).hide();
        });
        list = list.filter(function(id) { return id != hsId; });
    } else {
        icon.removeClass('mdi-chevron-down').addClass('mdi-chevron-right');
        $('.hs-item-' + hsId).hide();
        if (list.indexOf(hsId) === -1) list.push(hsId);
    }
    saveCollapsedRab(hsKeyRab, list);
}

// Toggle Category rows visibility
function toggleCategory(catId) {
    var icon = $('#cat-chevron-' + catId);
    var list = getCollapsedRab(catKeyRab);
    if (icon.hasClass('mdi-chevron-right')) {
        icon.removeClass('mdi-chevron-right').addClass('mdi-chevron-down');
        $('.cat-item-' + catId).show();
        list = list.filter(function(id) { return id != catId; });
    } else {
        icon.removeClass('mdi-chevron-down').addClass('mdi-chevron-right');
        $('.cat-item-' + catId).hide();
        if (list.indexOf(catId) === -1) list.push(catId);
    }
    saveCollapsedRab(catKeyRab, list);
}

// Toggle All Rows
function toggleAllRabRows(expand) {
    if (expand) {
        $('.toggle-hs-btn i').removeClass('mdi-chevron-right').addClass('mdi-chevron-down');
        $('.toggle-cat-btn i').removeClass('mdi-chevron-right').addClass('mdi-chevron-down');
        $('[class*="hs-item-"], [class*="cat-item-"]').show();
        saveCollapsedRab(hsKeyRab, []);
        saveCollapsedRab(catKeyRab, []);
    } else {
        $('.toggle-hs-btn i').removeClass('mdi-chevron-down').addClass('mdi-chevron-right');
        $('.toggle-cat-btn i').removeClass('mdi-chevron-down').addClass('mdi-chevron-right');
        $('[class*="hs-item-"], [class*="cat-item-"]').hide();
        
        var allHs = [];
        $('.toggle-hs-btn').each(function() {
            var onclick = $(this).attr('onclick') || '';
            var match = onclick.match(/\d+/);
            if (match) allHs.push(parseInt(match[0]));
        });
        var allCat = [];
        $('.toggle-cat-btn').each(function() {
            var onclick = $(this).attr('onclick') || '';
            var match = onclick.match(/\d+/);
            if (match) allCat.push(parseInt(match[0]));
        });
        saveCollapsedRab(hsKeyRab, allHs);
        saveCollapsedRab(catKeyRab, allCat);
    }
}

function restoreRabCollapsedState() {
    var hsList = getCollapsedRab(hsKeyRab);
    hsList.forEach(function(hsId) {
        $('#hs-chevron-' + hsId).removeClass('mdi-chevron-down').addClass('mdi-chevron-right');
        $('.hs-item-' + hsId).hide();
    });

    var catList = getCollapsedRab(catKeyRab);
    catList.forEach(function(catId) {
        $('#cat-chevron-' + catId).removeClass('mdi-chevron-down').addClass('mdi-chevron-right');
        $('.cat-item-' + catId).hide();
    });
}

// Live search filter implementation for RAB
function filterRabTable(query) {
    query = (query || '').trim().toLowerCase();
    var hasQuery = query.length > 0;
    
    $('#clearSearchRabBtn').toggleClass('d-none', !hasQuery);
    $('#rabNoSearchResultsRow').remove();
    
    if (!hasQuery) {
        $('#rabSearchResultCount').addClass('d-none').text('');
        restoreRabCollapsedState();
        return;
    }
    
    var matchedSubcatCount = 0;
    var matchedCatIds = new Set();
    var matchedHsIds = new Set();
    
    // 1. Evaluate subcategory rows
    $('#rabTable tbody tr').each(function() {
        var row = $(this);
        if (row.hasClass('head-sub-row') || row.hasClass('category-row') || row.hasClass('table-secondary') || row.hasClass('table-info') || row.hasClass('table-light') || row.hasClass('dropzone-head-sub')) {
            return;
        }
        
        var text = row.text().toLowerCase();
        var catId = 0;
        var classList = (row.attr('class') || '').split(/\s+/);
        classList.forEach(function(cls) {
            var m = cls.match(/^cat-item-(\d+)$/);
            if (m) catId = parseInt(m[1]);
        });
        
        var catName = '';
        var hsName = '';
        var hsId = 0;
        if (catId) {
            var catRow = $('.category-row[data-cat-id="' + catId + '"]');
            catName = (catRow.find('strong').text() || '').toLowerCase();
            hsId = parseInt(catRow.attr('data-hs-id')) || 0;
            if (hsId) {
                var hsRow = $('.head-sub-row[data-hs-id="' + hsId + '"]');
                hsName = (hsRow.find('strong').text() || '').toLowerCase();
            }
        }
        
        var isMatch = text.indexOf(query) !== -1 || catName.indexOf(query) !== -1 || hsName.indexOf(query) !== -1;
        if (isMatch) {
            row.show();
            matchedSubcatCount++;
            if (catId) {
                matchedCatIds.add(catId);
                if (hsId) matchedHsIds.add(hsId);
            }
        } else {
            row.hide();
        }
    });
    
    // 2. Direct category matches
    $('.category-row').each(function() {
        var catRow = $(this);
        var catId = parseInt(catRow.attr('data-cat-id'));
        var hsId = parseInt(catRow.attr('data-hs-id')) || 0;
        var catTitle = (catRow.find('strong').text() || '').toLowerCase();
        
        if (catTitle.indexOf(query) !== -1) {
            matchedCatIds.add(catId);
            if (hsId) matchedHsIds.add(hsId);
            $('.cat-item-' + catId + ':not(.category-row):not(.table-secondary)').each(function() {
                $(this).show();
                matchedSubcatCount++;
            });
        }
    });
    
    // 3. Direct Head-Sub matches
    $('.head-sub-row').each(function() {
        var hsRow = $(this);
        var hsId = parseInt(hsRow.attr('data-hs-id'));
        var hsTitle = (hsRow.find('strong').text() || '').toLowerCase();
        
        if (hsTitle.indexOf(query) !== -1 && hsId) {
            matchedHsIds.add(hsId);
            $('.hs-item-' + hsId).each(function() {
                var row = $(this);
                if (row.hasClass('category-row')) {
                    var cId = parseInt(row.attr('data-cat-id'));
                    if (cId) matchedCatIds.add(cId);
                    row.show();
                } else if (!row.hasClass('table-info') && !row.hasClass('table-secondary') && !row.hasClass('table-light')) {
                    row.show();
                    matchedSubcatCount++;
                }
            });
        }
    });
    
    // 4. Show/hide category headers & total rows
    $('.category-row').each(function() {
        var catId = parseInt($(this).attr('data-cat-id'));
        if (matchedCatIds.has(catId)) {
            $(this).show();
            $('#cat-chevron-' + catId).removeClass('mdi-chevron-right').addClass('mdi-chevron-down');
            $('.table-secondary.cat-item-' + catId).show();
        } else {
            $(this).hide();
            $('.table-secondary.cat-item-' + catId).hide();
        }
    });
    
    // 5. Show/hide Head-Sub headers & total rows
    $('.head-sub-row').each(function() {
        var hsId = parseInt($(this).attr('data-hs-id'));
        if (matchedHsIds.has(hsId)) {
            $(this).show();
            $('#hs-chevron-' + hsId).removeClass('mdi-chevron-right').addClass('mdi-chevron-down');
            $('.table-info.hs-item-' + hsId).show();
            $('.hs-item-' + hsId + '.table-light').hide();
        } else {
            $(this).hide();
            $('.table-info.hs-item-' + hsId).hide();
            $('.hs-item-' + hsId + '.table-light').hide();
        }
    });
    
    var hasStandaloneMatches = false;
    matchedCatIds.forEach(function(cId) {
        var catRow = $('.category-row[data-cat-id="' + cId + '"]');
        if (!catRow.attr('data-hs-id') || catRow.attr('data-hs-id') == '0') {
            hasStandaloneMatches = true;
        }
    });
    $('.dropzone-head-sub[data-hs-id="0"]').toggle(hasStandaloneMatches);
    
    // 6. Show result count badge and no-results alert if 0
    if (matchedSubcatCount > 0 || matchedCatIds.size > 0) {
        $('#rabSearchResultCount').removeClass('d-none').text('Ditemukan: ' + matchedSubcatCount + ' pekerjaan');
    } else {
        $('#rabSearchResultCount').removeClass('d-none').text('0 pekerjaan');
        var emptyRow = $('<tr id="rabNoSearchResultsRow"><td colspan="10" class="text-center text-muted py-4"><i class="mdi mdi-magnify font-size-24 d-block mb-1 text-secondary"></i>Tidak ada pekerjaan yang cocok dengan pencarian "<strong>' + query.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</strong>"</td></tr>');
        $('#rabTable tbody').append(emptyRow);
    }
}

$(document).ready(function() {
    restoreRabCollapsedState();
    
    $('#searchRabInput').on('input', function() {
        filterRabTable($(this).val());
    });
    
    $('#clearSearchRabBtn').on('click', function() {
        $('#searchRabInput').val('');
        filterRabTable('');
        $('#searchRabInput').focus();
    });
});

// Single-step move category up or down
function moveCategoryDirection(catId, direction) {
    var catRow = $('.category-row[data-cat-id="' + catId + '"]');
    if (catRow.length) {
        catRow.css('opacity', '0.5');
    }
    $.ajax({
        url: 'view.php?id=<?= $projectId ?>',
        type: 'POST',
        data: {
            action: 'ajax_move_category_order',
            category_id: catId,
            direction: direction
        },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                if (res.no_change) {
                    catRow.css('opacity', '1');
                    return;
                }
                if (res.new_order) {
                    applyCategoryReorder(res.new_order);
                } else {
                    location.reload();
                }
            } else {
                catRow.css('opacity', '1');
                alert('Gagal mengubah urutan: ' + (res.message || 'Error'));
            }
        },
        error: function() {
            catRow.css('opacity', '1');
            alert('Terjadi kesalahan saat mengubah urutan kategori.');
        }
    });
}

// Reorder category relative to target category or into head-sub
function reorderCategoryRelative(sourceCatId, targetCatId, position, targetHeadSubId) {
    var data = {
        action: 'ajax_move_category_relative',
        source_cat_id: sourceCatId,
        target_cat_id: targetCatId || 0,
        position: position || 'after'
    };
    if (typeof targetHeadSubId !== 'undefined') {
        data.target_head_sub_id = targetHeadSubId;
    }

    $.ajax({
        url: 'view.php?id=<?= $projectId ?>',
        type: 'POST',
        data: data,
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                if (res.new_order) {
                    applyCategoryReorder(res.new_order);
                } else {
                    location.reload();
                }
            } else {
                alert('Gagal memindahkan kategori: ' + (res.message || 'Error'));
            }
        },
        error: function() {
            alert('Terjadi kesalahan saat memindahkan kategori.');
        }
    });
}

// Convert 1-based index to Category letter code (A, B, C... Z, AA, AB...)
function getJsCategoryCode(index) {
    index = parseInt(index) || 1;
    if (index <= 0) return 'A';
    var code = '';
    while (index > 0) {
        index--;
        code = String.fromCharCode(65 + (index % 26)) + code;
        index = Math.floor(index / 26);
    }
    return code;
}

// Update letter code badges in modal list
function updateModalLetterBadges() {
    $('#rearrangeCatSortableList .rearrange-cat-item').each(function(idx) {
        var code = getJsCategoryCode(idx + 1);
        $(this).find('.modal-cat-code-badge').text(code);
    });
}

// Apply new category order directly into the DOM without page reload
function applyCategoryReorder(newOrder) {
    if (!newOrder || !newOrder.length) return;

    // 1. Update text, code badges, and attributes for all categories and their subcategories
    newOrder.forEach(function(item) {
        var catId = item.id;
        var catCode = item.code;
        var catHeader = $('.category-row[data-cat-id="' + catId + '"]');
        if (!catHeader.length) return;

        catHeader.css('opacity', '1');
        catHeader.attr('data-hs-id', item.head_sub_id || 0);

        // Update category title text
        var catName = catHeader.attr('data-cat-name') || '';
        catHeader.find('strong.font-size-14').text(catCode + '. ' + catName);

        // Update subcategory codes: catCode + '.' + subIndex
        var subIndex = 1;
        $('.cat-item-' + catId + ':not(.category-row):not(.table-secondary)').each(function() {
            $(this).find('td:first').text(catCode + '.' + subIndex);
            subIndex++;
        });

        // Update category total row label
        $('.table-secondary.cat-item-' + catId).find('td:first strong').text('JUMLAH ' + catCode);

        // Update active class in dropdowns
        var dropdownMenu = catHeader.find('.dropdown-menu');
        if (dropdownMenu.length) {
            dropdownMenu.find('.dropdown-item').removeClass('active');
            var targetHs = item.head_sub_id || 0;
            dropdownMenu.find('.dropdown-item').each(function() {
                var onclick = $(this).attr('onclick') || '';
                if (onclick.indexOf('moveCategoryToHeadSub(' + catId + ', ' + targetHs + ')') !== -1) {
                    $(this).addClass('active');
                }
            });
        }
    });

    // 2. Rearrange DOM rows in the table
    var hasHeadSubs = $('.head-sub-row').length > 0;

    if (hasHeadSubs) {
        var hsGroups = {};
        newOrder.forEach(function(item) {
            var hsKey = (item.head_sub_id || 0).toString();
            if (!hsGroups[hsKey]) hsGroups[hsKey] = [];
            hsGroups[hsKey].push(item.id);
        });

        // For each head-sub header row
        $('.head-sub-row').each(function() {
            var hsId = $(this).attr('data-hs-id');
            var hsHeader = $(this);
            var catIdsInHs = hsGroups[hsId] || [];

            var insertAfterElem = hsHeader;
            var emptyRow = $('.hs-item-' + hsId + '.table-light');
            if (catIdsInHs.length > 0 && emptyRow.length) {
                emptyRow.remove();
            }

            catIdsInHs.forEach(function(cId) {
                var catRows = $('.category-row[data-cat-id="' + cId + '"]').add('.cat-item-' + cId);
                // Update hs-item class
                catRows.removeClass(function(index, className) {
                    return (className.match(/(^|\s)hs-item-\S+/g) || []).join(' ');
                }).addClass('hs-item-' + hsId);

                insertAfterElem.after(catRows);
                insertAfterElem = catRows.last();
            });
        });

        // Standalone section (without head-sub)
        var standaloneHeader = $('.dropzone-head-sub[data-hs-id="0"]');
        var standaloneCatIds = hsGroups['0'] || [];
        if (standaloneHeader.length) {
            var insertAfterStandalone = standaloneHeader;
            standaloneCatIds.forEach(function(cId) {
                var catRows = $('.category-row[data-cat-id="' + cId + '"]').add('.cat-item-' + cId);
                catRows.removeClass(function(index, className) {
                    return (className.match(/(^|\s)hs-item-\S+/g) || []).join(' ');
                });
                insertAfterStandalone.after(catRows);
                insertAfterStandalone = catRows.last();
            });
        }
    } else {
        // No head-subs: reorder all categories sequentially
        var tbody = $('#rabTable tbody');
        var prevElem = null;
        newOrder.forEach(function(item) {
            var catRows = $('.category-row[data-cat-id="' + item.id + '"]').add('.cat-item-' + item.id);
            if (prevElem === null) {
                tbody.prepend(catRows);
            } else {
                prevElem.after(catRows);
            }
            prevElem = catRows.last();
        });
    }

    // Brief highlight animation for visual feedback
    newOrder.forEach(function(item) {
        var catRow = $('.category-row[data-cat-id="' + item.id + '"]');
        catRow.css('background-color', 'rgba(25, 118, 210, 0.25)');
        setTimeout(function() {
            catRow.css('background-color', '');
        }, 600);
    });
}

// Reset Modal List to initial state
function resetRearrangeModalList() {
    location.reload();
}

// Save Modal Order
function saveRearrangeModalOrder() {
    var btn = $('#btnSaveRearrangeCat');
    var originalBtnHtml = btn.html();
    
    var categories = [];
    $('#rearrangeCatSortableList .rearrange-cat-item').each(function() {
        var catId = parseInt($(this).attr('data-cat-id'));
        var hsSelect = $(this).find('.modal-cat-hs-select');
        var hsId = hsSelect.length ? hsSelect.val() : $(this).attr('data-original-hs-id');
        categories.push({
            id: catId,
            head_sub_id: hsId
        });
    });

    if (categories.length === 0) {
        alert('Tidak ada kategori untuk diurutkan.');
        return;
    }

    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Menyimpan...');

    $.ajax({
        url: 'view.php?id=<?= $projectId ?>',
        type: 'POST',
        data: {
            action: 'ajax_reorder_rab_categories',
            categories: JSON.stringify(categories)
        },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                if (res.new_order) {
                    // Check if any head_sub changed — if so, reload because DOM structure differs
                    var hsChanged = false;
                    res.new_order.forEach(function(item) {
                        var catRow = $('.category-row[data-cat-id="' + item.id + '"]');
                        var currentHs = catRow.attr('data-hs-id') || '0';
                        var newHs = (item.head_sub_id || 0).toString();
                        if (currentHs !== newHs) hsChanged = true;
                    });
                    if (hsChanged) {
                        location.reload();
                    } else {
                        applyCategoryReorder(res.new_order);
                        var modal = bootstrap.Modal.getInstance(document.getElementById('rearrangeCategoryModal'));
                        if (modal) modal.hide();
                    }
                } else {
                    location.reload();
                }
            } else {
                alert('Gagal menyimpan urutan: ' + (res.message || 'Error'));
                btn.prop('disabled', false).html(originalBtnHtml);
            }
        },
        error: function() {
            alert('Terjadi kesalahan saat menyimpan urutan kategori.');
            btn.prop('disabled', false).html(originalBtnHtml);
        }
    });
}

function moveCategoryToHeadSub(catId, hsId) {

    $.ajax({
        url: 'view.php?id=<?= $projectId ?>',
        type: 'POST',
        data: {
            action: 'ajax_move_category_head_sub',
            category_id: catId,
            head_sub_id: hsId
        },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                // Head-sub change requires reload because DOM structure involves
                // moving rows between head-sub sections with different CSS classes
                location.reload();
            } else {
                alert('Gagal memindahkan kategori: ' + res.message);
            }
        },
        error: function() {
            alert('Terjadi kesalahan saat memindahkan kategori.');
        }
    });
}

function editHeadSub(hsId, code, name) {

    $('#edit_head_sub_id').val(hsId);
    $('#edit_head_sub_code').val(code);
    $('#edit_head_sub_name').val(name);
    var modal = new bootstrap.Modal(document.getElementById('editHeadSubModal'));
    modal.show();
}

function deleteHeadSub(hsId) {
    confirmDelete(function() {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="delete_head_sub"><input type="hidden" name="head_sub_id" value="' + hsId + '">';
        document.body.appendChild(form);
        form.submit();
    });
}

function editCategory(catId, name, headSubId) {
    $('#edit_category_id').val(catId);
    $('#edit_category_name').val(name);
    $('#edit_cat_head_sub_id').val(headSubId || '');
    var modal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
    modal.show();
}

function deleteSubcat(subcatId) {
    confirmDelete(function() {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="delete_subcategory"><input type="hidden" name="subcategory_id" value="' + subcatId + '">';
        document.body.appendChild(form);
        form.submit();
    });
}

function deleteSnapshot(snapshotId) {
    confirmDelete(function() {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="delete_snapshot"><input type="hidden" name="snapshot_id" value="' + snapshotId + '">';
        document.body.appendChild(form);
        form.submit();
    });
}

function openPdfPreview() {
    var modal = new bootstrap.Modal(document.getElementById('pdfPreviewModal'));
    var iframe = document.getElementById('pdfPreviewIframe');
    iframe.src = 'export_rab_pdf.php?id=<?= $projectId ?>';
    modal.show();
}

function printPdfPreview() {
    var iframe = document.getElementById('pdfPreviewIframe');
    if (iframe.contentWindow) {
        iframe.contentWindow.print();
    }
}

function showAhspModal(ahspId) {
    var modalEl = document.getElementById('ahspDetailModal');
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    document.getElementById('ahspDetailBody').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">Memuat data AHSP...</p></div>';
    document.getElementById('ahspDetailModalLabel').textContent = 'Detail AHSP';
    modal.show();
    
    fetch('view.php?id=<?= $projectId ?>&ajax=get_ahsp_detail&ahsp_id=' + ahspId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                document.getElementById('ahspDetailBody').innerHTML = data.html;
                document.getElementById('ahspDetailModalLabel').textContent = 'Detail AHSP - ' + data.work_name;
            } else {
                document.getElementById('ahspDetailBody').innerHTML = '<div class="alert alert-danger">' + data.message + '</div>';
            }
        })
        .catch(function(err) {
            document.getElementById('ahspDetailBody').innerHTML = '<div class="alert alert-danger">Gagal memuat data: ' + err.message + '</div>';
        });
}

// Floating Synced Sticky Header Implementation for RAB
(function initRabStickyHeader() {
    function setupStickyHeader() {
        var origWrapper = document.getElementById('rabTableWrapper') || document.querySelector('.rab-scroll-wrapper');
        var origTable = document.getElementById('rabTable');
        if (!origWrapper || !origTable) return;
        
        var origThead = origTable.querySelector('thead');
        if (!origThead) return;

        // Remove any existing floating header wrapper
        var existing = document.getElementById('rabFloatingHeader');
        if (existing) existing.remove();

        // Create floating container
        var floatWrapper = document.createElement('div');
        floatWrapper.id = 'rabFloatingHeader';
        floatWrapper.className = 'rab-floating-header-wrapper';
        
        // Create cloned table and thead
        var floatTable = document.createElement('table');
        floatTable.className = origTable.className + ' rab-floating-table';
        
        var clonedThead = origThead.cloneNode(true);
        var clonedIds = clonedThead.querySelectorAll('[id]');
        for (var k = 0; k < clonedIds.length; k++) {
            clonedIds[k].removeAttribute('id');
        }
        floatTable.appendChild(clonedThead);
        floatWrapper.appendChild(floatTable);
        document.body.appendChild(floatWrapper);

        // Sync column widths between original thead and cloned thead
        function syncWidths() {
            var origTableWidth = origTable.offsetWidth;
            floatTable.style.width = origTableWidth + 'px';
            floatTable.style.minWidth = origTableWidth + 'px';
            
            var origRows = origThead.querySelectorAll('tr');
            var cloneRows = clonedThead.querySelectorAll('tr');
            for (var r = 0; r < origRows.length; r++) {
                if (!cloneRows[r]) continue;
                cloneRows[r].style.height = origRows[r].offsetHeight + 'px';
                var origThs = origRows[r].children;
                var cloneThs = cloneRows[r].children;
                for (var i = 0; i < origThs.length; i++) {
                    if (cloneThs[i]) {
                        var rect = origThs[i].getBoundingClientRect();
                        var w = rect.width;
                        cloneThs[i].style.width = w + 'px';
                        cloneThs[i].style.minWidth = w + 'px';
                        cloneThs[i].style.maxWidth = w + 'px';
                        cloneThs[i].style.boxSizing = 'border-box';
                    }
                }
            }
        }

        // Update position and visibility on scroll
        function updatePosition() {
            var topbar = document.getElementById('page-topbar');
            var topOffset = topbar ? topbar.offsetHeight : 70;
            
            var rect = origWrapper.getBoundingClientRect();
            var theadRect = origThead.getBoundingClientRect();
            var theadHeight = origThead.offsetHeight;
            var tableBottom = rect.bottom;
            
            // Show floating header when original header has scrolled past the topbar,
            // and hide before the table completely leaves the view
            if (theadRect.top <= topOffset && tableBottom > (topOffset + theadHeight + 30)) {
                floatWrapper.style.display = 'block';
                floatWrapper.style.top = topOffset + 'px';
                floatWrapper.style.left = rect.left + 'px';
                floatWrapper.style.width = rect.width + 'px';
                floatWrapper.scrollLeft = origWrapper.scrollLeft;
            } else {
                floatWrapper.style.display = 'none';
            }
        }

        // Bidirectional horizontal scroll sync
        var isSyncing = false;
        origWrapper.addEventListener('scroll', function() {
            if (!isSyncing) {
                isSyncing = true;
                floatWrapper.scrollLeft = origWrapper.scrollLeft;
                isSyncing = false;
            }
        }, { passive: true });

        floatWrapper.addEventListener('scroll', function() {
            if (!isSyncing) {
                isSyncing = true;
                origWrapper.scrollLeft = floatWrapper.scrollLeft;
                isSyncing = false;
            }
        }, { passive: true });

        floatWrapper.addEventListener('wheel', function(e) {
            if (e.deltaX) {
                origWrapper.scrollLeft += e.deltaX;
            }
        }, { passive: true });

        // Window scroll and resize listeners with requestAnimationFrame
        var ticking = false;
        function onScrollOrResize() {
            if (!ticking) {
                window.requestAnimationFrame(function() {
                    syncWidths();
                    updatePosition();
                    ticking = false;
                });
                ticking = true;
            }
        }

        window.addEventListener('scroll', onScrollOrResize, { passive: true });
        window.addEventListener('resize', onScrollOrResize, { passive: true });

        // Observe size changes via ResizeObserver
        if (window.ResizeObserver) {
            var ro = new ResizeObserver(function() {
                syncWidths();
                updatePosition();
            });
            ro.observe(origWrapper);
            ro.observe(origTable);
        }

        // Initial measurement
        setTimeout(function() {
            syncWidths();
            updatePosition();
        }, 50);
    }

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        setupStickyHeader();
    } else {
        document.addEventListener('DOMContentLoaded', setupStickyHeader);
    }
})();
</script>

<!-- AHSP Detail Modal -->
<div class="modal fade" id="ahspDetailModal" tabindex="-1" aria-labelledby="ahspDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="ahspDetailModalLabel">Detail AHSP</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="ahspDetailBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted">Memuat data AHSP...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- PDF Preview Modal -->
<div class="modal fade" id="pdfPreviewModal" tabindex="-1" aria-labelledby="pdfPreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" id="pdfPreviewModalLabel">
                    <i class="mdi mdi-file-pdf-box text-danger"></i> Preview Laporan RAB
                </h5>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-success btn-sm" onclick="printPdfPreview()">
                        <i class="mdi mdi-printer"></i> Cetak / Export PDF
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body p-0">
                <iframe id="pdfPreviewIframe" style="width:100%; height:100%; border:none;"></iframe>
            </div>
        </div>
    </div>
</div>

<?php 
$extraScripts = ob_get_clean();
?>
