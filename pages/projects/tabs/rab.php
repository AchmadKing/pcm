<?php
/**
 * RAB Tab - Embedded in Project View
 * Displays RAB table with inline editing, Head-Sub grouping, and collapse/expand
 * Variables available from parent view.php: $project, $projectId
 */

// Ensure database tables exist
ensureRabHeadSubsTableExists();

// RAB can be viewed anytime, but only edited when draft AND not yet submitted AND not locked
$isEditable = ($project['status'] === 'draft' && !$project['rab_submitted'] && !isProjectLocked($project));

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
$overheadPct = $project['overhead_percentage'] ?? 10;

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
    <h5 class="mb-0"><?= sanitize($project['name']) ?></h5>
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

<!-- RAB Table -->
<div class="table-responsive">
    <table class="table table-bordered mb-0" id="rabTable">
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
                        <tr class="table-primary category-row hs-item-<?= $hsId ?>" data-cat-id="<?= $catId ?>">
                            <td colspan="10" class="py-2">
                                <div class="d-flex justify-content-between align-items-center" style="padding-left: 15px;">
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if ($isEditable): ?>
                                        <span class="drag-handle" draggable="true" data-cat-id="<?= $catId ?>" style="cursor: grab; display: inline-flex; align-items: center; padding: 2px 4px;" title="Seret ikon ini untuk memindahkan kategori ke Head-Sub lain">
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
            <?php if (!empty($standaloneCats) || !empty($headSubs)): ?>
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

                <?php foreach ($standaloneCats as $catId => $data):
                    $cat = $data['category'];
                    $subcats = $data['subcategories'];
                ?>
                    <!-- Standalone Category Header Row -->
                    <tr class="table-primary category-row" data-cat-id="<?= $catId ?>">
                        <td colspan="10" class="py-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center gap-2">
                                    <?php if ($isEditable): ?>
                                    <span class="drag-handle" draggable="true" data-cat-id="<?= $catId ?>" style="cursor: grab; display: inline-flex; align-items: center; padding: 2px 4px;" title="Seret ikon ini untuk memindahkan kategori ke Head-Sub">
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

/* Blur Box Overlay Effect on Dropzone */
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
            return $('<div class="drag-helper-card"><i class="mdi mdi-folder-move me-2"></i>' + catTitle + '</div>').appendTo('body');
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
                moveCategoryToHeadSub(catId, hsId);
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

$(document).ready(function() {
    restoreRabCollapsedState();
});

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
