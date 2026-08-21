<?php
/**
 * Master Data RAP UI Components
 * Tab panes for Items RAP and AHSP RAP
 * UI sama persis dengan RAB (Items RAB dan AHSP RAB)
 * This file should be included in master_data.php after the AHSP tab pane
 */

// Group RAP items by category for table display
$itemsRapByCategory = ['upah' => [], 'material' => [], 'alat' => []];
foreach ($itemsRap as $item) {
    $itemsRapByCategory[$item['category']][] = $item;
}
?>

<!-- ITEMS RAP TAB -->
<div class="tab-pane fade <?= $activeSubtab == 'items_rap' ? 'show active' : '' ?>" id="items-rap-tab">
    <div class="mb-3 d-flex gap-2 flex-wrap justify-content-between">
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($isEditable): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addItemRapModal">
                <i class="mdi mdi-plus"></i> Tambah Item
            </button>
            <a href="import.php?project_id=<?= $projectId ?>&type=items_rap" class="btn btn-success" title="Import ke RAP">
                <i class="mdi mdi-file-upload"></i> Import dari CSV
            </a>
            <a href="export_items.php?project_id=<?= $projectId ?>&type=rap" class="btn btn-info">
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
                <input class="form-check-input" type="checkbox" role="switch" id="editModeToggleRapItems" style="cursor: pointer;">
                <label class="form-check-label small text-muted" for="editModeToggleRapItems" style="cursor: pointer;">Mode Edit</label>
            </div>
            <button type="button" class="btn btn-outline-danger edit-mode-only-rap-items d-none" onclick="confirmClearItemsRap()">
                <i class="mdi mdi-delete-sweep"></i> Hapus Semua
            </button>
            <?php endif; ?>
            <!-- Category Filter -->
            <div class="btn-group" role="group" id="categoryFilterRap">
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
                <input type="text" class="form-control" id="searchItemsRap" placeholder="Cari item...">
            </div>
        </div>
    </div>
    
    <?php if (empty($itemsRap)): ?>
    <div class="text-center py-4">
        <i class="mdi mdi-package-variant-closed display-4 text-muted"></i>
        <h5 class="mt-3">Belum ada item RAP</h5>
        <p class="text-muted">Tambahkan item (upah, material, alat) untuk RAP proyek ini.</p>
    </div>
    <?php else: ?>
    
    <!-- Upah Section -->
    <?php if (!empty($itemsRapByCategory['upah'])): ?>
    <h6 class="text-primary item-section-rap"><i class="mdi mdi-account-hard-hat"></i> Upah</h6>
    <div class="table-responsive mb-4">
        <table class="table table-sm table-bordered item-table-rap" data-category="upah">
            <thead class="table-light">
                <tr>
                    <th width="100" class="sortable-header-rap" data-sort="code" style="cursor:pointer;">
                        Kode <i class="mdi mdi-sort sort-icon"></i>
                    </th>
                    <th class="sortable-header-rap" data-sort="name" style="cursor:pointer;">
                        Nama <i class="mdi mdi-sort sort-icon"></i>
                    </th>
                    <th width="100">Merk</th>
                    <th width="80" class="sortable-header-rap" data-sort="unit" style="cursor:pointer;">
                        Satuan <i class="mdi mdi-sort sort-icon"></i>
                    </th>
                    <th width="130" class="text-end sortable-header-rap" data-sort="price" style="cursor:pointer;">
                        Harga PU <i class="mdi mdi-sort sort-icon"></i>
                    </th>
                    <th width="130" class="text-end">Harga Aktual</th>
                    <?php if ($isEditable): ?><th width="50">Aksi</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($itemsRapByCategory['upah'] as $item): ?>
                <tr class="item-row-rap" data-item-id="<?= $item['id'] ?>" data-category="upah">
                    <td><input type="text" class="form-control form-control-sm border-0 item-code-rap" name="item_code" value="<?= sanitize($item['item_code'] ?? '') ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                    <td><input type="text" class="form-control form-control-sm border-0 item-name-rap" name="item_name" value="<?= sanitize($item['name']) ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                    <td><input type="text" class="form-control form-control-sm border-0 item-brand-rap" name="item_brand" value="<?= sanitize($item['brand'] ?? '') ?>" placeholder="-" <?= !$isEditable ? 'disabled' : '' ?>></td>
                    <td><input type="text" class="form-control form-control-sm border-0 item-unit-rap" name="item_unit" value="<?= sanitize($item['unit']) ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                    <td><input type="text" class="form-control form-control-sm border-0 text-end format-rupiah item-price-rap" name="item_price" value="<?= formatNumber($item['price']) ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                    <td><input type="text" class="form-control form-control-sm border-0 text-end format-rupiah item-actual-price-rap" name="item_actual_price" value="<?= $item['actual_price'] ? formatNumber($item['actual_price']) : '' ?>" placeholder="-" <?= !$isEditable ? 'disabled' : '' ?>></td>
                    <?php if ($isEditable): ?>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-danger edit-mode-only-rap-items d-none" onclick="deleteItemRap(<?= $item['id'] ?>)"><i class="mdi mdi-delete"></i></button>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Material Section -->
    <?php if (!empty($itemsRapByCategory['material'])): ?>
    <h6 class="text-success item-section-rap"><i class="mdi mdi-cube-outline"></i> Material</h6>
    <div class="table-responsive mb-4">
        <table class="table table-sm table-bordered item-table-rap" data-category="material">
            <thead class="table-light">
                <tr>
                    <th width="100" class="sortable-header-rap" data-sort="code" style="cursor:pointer;">
                        Kode <i class="mdi mdi-sort sort-icon"></i>
                    </th>
                    <th class="sortable-header-rap" data-sort="name" style="cursor:pointer;">
                        Nama <i class="mdi mdi-sort sort-icon"></i>
                    </th>
                    <th width="100">Merk</th>
                    <th width="80" class="sortable-header-rap" data-sort="unit" style="cursor:pointer;">
                        Satuan <i class="mdi mdi-sort sort-icon"></i>
                    </th>
                    <th width="130" class="text-end sortable-header-rap" data-sort="price" style="cursor:pointer;">
                        Harga PU <i class="mdi mdi-sort sort-icon"></i>
                    </th>
                    <th width="130" class="text-end">Harga Aktual</th>
                    <?php if ($isEditable): ?><th width="50">Aksi</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($itemsRapByCategory['material'] as $item): ?>
                <tr class="item-row-rap" data-item-id="<?= $item['id'] ?>" data-category="material">
                    <td><input type="text" class="form-control form-control-sm border-0 item-code-rap" name="item_code" value="<?= sanitize($item['item_code'] ?? '') ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                    <td><input type="text" class="form-control form-control-sm border-0 item-name-rap" name="item_name" value="<?= sanitize($item['name']) ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                    <td><input type="text" class="form-control form-control-sm border-0 item-brand-rap" name="item_brand" value="<?= sanitize($item['brand'] ?? '') ?>" placeholder="-" <?= !$isEditable ? 'disabled' : '' ?>></td>
                    <td><input type="text" class="form-control form-control-sm border-0 item-unit-rap" name="item_unit" value="<?= sanitize($item['unit']) ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                    <td><input type="text" class="form-control form-control-sm border-0 text-end format-rupiah item-price-rap" name="item_price" value="<?= formatNumber($item['price']) ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                    <td><input type="text" class="form-control form-control-sm border-0 text-end format-rupiah item-actual-price-rap" name="item_actual_price" value="<?= $item['actual_price'] ? formatNumber($item['actual_price']) : '' ?>" placeholder="-" <?= !$isEditable ? 'disabled' : '' ?>></td>
                    <?php if ($isEditable): ?>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-danger edit-mode-only-rap-items d-none" onclick="deleteItemRap(<?= $item['id'] ?>)"><i class="mdi mdi-delete"></i></button>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Alat Section -->
    <?php if (!empty($itemsRapByCategory['alat'])): ?>
    <h6 class="text-warning item-section-rap"><i class="mdi mdi-tools"></i> Alat</h6>
    <div class="table-responsive mb-4">
        <table class="table table-sm table-bordered item-table-rap" data-category="alat">
            <thead class="table-light">
                <tr>
                    <th width="100" class="sortable-header-rap" data-sort="code" style="cursor:pointer;">
                        Kode <i class="mdi mdi-sort sort-icon"></i>
                    </th>
                    <th class="sortable-header-rap" data-sort="name" style="cursor:pointer;">
                        Nama <i class="mdi mdi-sort sort-icon"></i>
                    </th>
                    <th width="100">Merk</th>
                    <th width="80" class="sortable-header-rap" data-sort="unit" style="cursor:pointer;">
                        Satuan <i class="mdi mdi-sort sort-icon"></i>
                    </th>
                    <th width="130" class="text-end sortable-header-rap" data-sort="price" style="cursor:pointer;">
                        Harga PU <i class="mdi mdi-sort sort-icon"></i>
                    </th>
                    <th width="130" class="text-end">Harga Aktual</th>
                    <?php if ($isEditable): ?><th width="50">Aksi</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($itemsRapByCategory['alat'] as $item): ?>
                <tr class="item-row-rap" data-item-id="<?= $item['id'] ?>" data-category="alat">
                    <td><input type="text" class="form-control form-control-sm border-0 item-code-rap" name="item_code" value="<?= sanitize($item['item_code'] ?? '') ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                    <td><input type="text" class="form-control form-control-sm border-0 item-name-rap" name="item_name" value="<?= sanitize($item['name']) ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                    <td><input type="text" class="form-control form-control-sm border-0 item-brand-rap" name="item_brand" value="<?= sanitize($item['brand'] ?? '') ?>" placeholder="-" <?= !$isEditable ? 'disabled' : '' ?>></td>
                    <td><input type="text" class="form-control form-control-sm border-0 item-unit-rap" name="item_unit" value="<?= sanitize($item['unit']) ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                    <td><input type="text" class="form-control form-control-sm border-0 text-end format-rupiah item-price-rap" name="item_price" value="<?= formatNumber($item['price']) ?>" <?= !$isEditable ? 'disabled' : '' ?>></td>
                    <td><input type="text" class="form-control form-control-sm border-0 text-end format-rupiah item-actual-price-rap" name="item_actual_price" value="<?= $item['actual_price'] ? formatNumber($item['actual_price']) : '' ?>" placeholder="-" <?= !$isEditable ? 'disabled' : '' ?>></td>
                    <?php if ($isEditable): ?>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-danger edit-mode-only-rap-items d-none" onclick="deleteItemRap(<?= $item['id'] ?>)"><i class="mdi mdi-delete"></i></button>
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

<!-- AHSP RAP TAB -->
<div class="tab-pane fade <?= $activeSubtab == 'ahsp_rap' ? 'show active' : '' ?>" id="ahsp-rap-tab">
    <div class="mb-3 d-flex gap-2 flex-wrap justify-content-between">
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($isEditable): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAhspRapModal">
                <i class="mdi mdi-plus"></i> Tambah AHSP
            </button>
            <a href="import.php?project_id=<?= $projectId ?>&type=ahsp" class="btn btn-success" title="Import ke RAB, data akan otomatis di-sync ke RAP">
                <i class="mdi mdi-file-upload"></i> Import dari CSV
            </a>
            <a href="export_ahsp.php?project_id=<?= $projectId ?>&type=rap" class="btn btn-info">
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
                <input class="form-check-input" type="checkbox" role="switch" id="editModeToggleRapAhsp" style="cursor: pointer;">
                <label class="form-check-label small text-muted" for="editModeToggleRapAhsp" style="cursor: pointer;">Mode Edit</label>
            </div>
            <button type="button" class="btn btn-outline-danger edit-mode-only-rap-ahsp d-none" onclick="confirmClearAhspRap()">
                <i class="mdi mdi-delete-sweep"></i> Hapus Semua
            </button>
            <?php endif; ?>
            <!-- Sort Dropdown -->
            <div class="input-group" style="width: 160px;">
                <span class="input-group-text"><i class="mdi mdi-sort"></i></span>
                <select class="form-select form-select-sm" id="sortAhspRap" onchange="sortAhspRap(this.value)">
                    <option value="name" <?= $ahspSort == 'name' ? 'selected' : '' ?>>Nama</option>
                    <option value="code" <?= $ahspSort == 'code' ? 'selected' : '' ?>>Kode</option>
                    <option value="price_asc" <?= $ahspSort == 'price_asc' ? 'selected' : '' ?>>Harga ↑</option>
                    <option value="price_desc" <?= $ahspSort == 'price_desc' ? 'selected' : '' ?>>Harga ↓</option>
                </select>
            </div>
            <div class="input-group" style="width: 250px;">
                <span class="input-group-text"><i class="mdi mdi-magnify"></i></span>
                <input type="text" class="form-control" id="searchAhspRap" placeholder="Cari AHSP...">
            </div>
        </div>
    </div>
    <?php if ($isEditable): ?>
    <div class="alert alert-info small mb-3">
        <i class="mdi mdi-information"></i>
        <strong>Tips Import AHSP:</strong> Pastikan Items RAP sudah diimport terlebih dahulu. 
        Sistem akan otomatis mencocokkan nama item dan mengambil satuan serta harga dari Master Data Items RAP.
    </div>
    <?php endif; ?>
    
    <?php if (empty($ahspListRap)): ?>
    <div class="text-center py-4">
        <i class="mdi mdi-file-table-outline display-4 text-muted"></i>
        <h5 class="mt-3">Belum ada AHSP RAP</h5>
        <p class="text-muted">Buat template AHSP (Analisa Harga Satuan Pekerjaan) untuk digunakan di RAP.</p>
    </div>
    <?php else: ?>
    
    <div class="accordion" id="ahspAccordionRap">
        <?php 
            // Render AHSP List from partial
            include __DIR__ . '/partials/ahsp_rap_list.php'; 
        ?>
    </div>
    
    <?php endif; ?>
</div>

<!-- RAP MODALS -->
<!-- Add Item RAP Modal -->
<div class="modal fade" id="addItemRapModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="add_item_rap">
            <div class="modal-header">
                <h5 class="modal-title"><i class="mdi mdi-plus"></i> Tambah Item RAP</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label required">Kode Item</label>
                        <input type="text" class="form-control" name="item_code" required placeholder="ITM-001">
                        <small class="text-muted">Jika kode baru, akan otomatis ditambahkan ke RAB</small>
                    </div>
                    <div class="col-md-8 mb-3">
                        <label class="form-label required">Nama Item</label>
                        <input type="text" class="form-control" name="item_name" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Merk/Brand</label>
                    <input type="text" class="form-control" name="item_brand" placeholder="Opsional">
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

<!-- Add AHSP RAP Modal -->
<div class="modal fade" id="addAhspRapModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="add_ahsp_rap">
            <div class="modal-header">
                <h5 class="modal-title"><i class="mdi mdi-plus"></i> Tambah AHSP RAP</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label required">Kode AHSP</label>
                        <input type="text" class="form-control" name="ahsp_code" required placeholder="AHSP-001">
                        <small class="text-muted">Jika kode baru, akan otomatis ditambahkan ke RAB</small>
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

<!-- Edit AHSP RAP Modal -->
<div class="modal fade" id="editAhspRapModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="update_ahsp_rap">
            <input type="hidden" name="ahsp_id" id="editAhspRapId">
            <div class="modal-header">
                <h5 class="modal-title"><i class="mdi mdi-pencil"></i> Edit AHSP RAP</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label required">Kode AHSP</label>
                        <input type="text" class="form-control" name="ahsp_code" id="editAhspRapCode" required>
                    </div>
                    <div class="col-md-8 mb-3">
                        <label class="form-label required">Nama Pekerjaan</label>
                        <input type="text" class="form-control" name="work_name" id="editAhspRapName" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label required">Satuan</label>
                    <input type="text" class="form-control" name="ahsp_unit" id="editAhspRapUnit" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Detail RAP Modal (untuk tambah komponen AHSP) -->
<div class="modal fade" id="addDetailRapModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="POST" class="modal-content" id="formAddAhspDetailRap">
            <input type="hidden" name="action" value="add_ahsp_detail_rap">
            <input type="hidden" name="ahsp_id" id="addDetailRapAhspId">
            <div class="modal-header bg-light">
                <h5 class="modal-title"><i class="mdi mdi-playlist-plus text-primary me-1"></i> Tambah Komponen AHSP RAP</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <!-- Search & Filters -->
                <div class="row g-2 mb-3">
                    <div class="col-md-7">
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="mdi mdi-magnify"></i></span>
                            <input type="text" class="form-control" id="searchItemRapInput" placeholder="Cari kode atau nama item..." autocomplete="off">
                            <button type="button" class="btn btn-outline-secondary" id="clearSearchItemRapBtn" style="display:none;">&times;</button>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="btn-group w-100" role="group" id="filterCategoryItemRap">
                            <button type="button" class="btn btn-sm btn-outline-secondary active" data-cat="all">Semua</button>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-cat="upah">Upah</button>
                            <button type="button" class="btn btn-sm btn-outline-success" data-cat="material">Material</button>
                            <button type="button" class="btn btn-sm btn-outline-warning" data-cat="alat">Alat</button>
                        </div>
                    </div>
                </div>

                <!-- Global Default Coefficient & Selection bar -->
                <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded mb-2 border">
                    <div class="d-flex align-items-center gap-2">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" id="checkAllItemsRap" style="cursor: pointer;">
                            <label class="form-check-label fw-bold small" for="checkAllItemsRap" style="cursor: pointer;">Pilih Semua</label>
                        </div>
                        <span class="badge bg-primary" id="selectedItemsRapCountBadge">0 dipilih</span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <label class="small text-muted mb-0 text-nowrap">Koefisien Default:</label>
                        <input type="text" class="form-control form-control-sm text-end" id="defaultCoeffRapInput" value="1,0000" style="width: 85px;">
                        <button type="button" class="btn btn-sm btn-outline-primary text-nowrap" id="applyDefaultCoeffRapBtn" title="Terapkan koefisien ke semua item yang diceklis">
                            <i class="mdi mdi-check-all"></i> Terapkan
                        </button>
                    </div>
                </div>

                <!-- Items Checklist Table Container -->
                <div class="table-responsive border rounded" style="max-height: 380px; overflow-y: auto;">
                    <table class="table table-hover table-sm align-middle mb-0" id="tableAddDetailRapItems">
                        <thead class="table-light sticky-top" style="z-index: 1;">
                            <tr>
                                <th width="35" class="text-center">#</th>
                                <th width="110">Kode Item</th>
                                <th>Nama Item</th>
                                <th width="90">Kategori</th>
                                <th width="70">Satuan</th>
                                <th width="120" class="text-end">Harga (Rp)</th>
                                <th width="110" class="text-end">Koefisien</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($itemsRap)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-3">Belum ada item di Master Data RAP</td></tr>
                            <?php else: ?>
                            <?php foreach ($itemsRap as $item): 
                                $catBadge = match($item['category']) {
                                    'upah' => 'badge bg-primary',
                                    'material' => 'badge bg-success',
                                    'alat' => 'badge bg-warning text-dark',
                                    default => 'badge bg-secondary'
                                };
                            ?>
                            <tr class="modal-item-rap-row" data-id="<?= $item['id'] ?>" data-code="<?= strtolower(htmlspecialchars($item['item_code'] ?? '')) ?>" data-name="<?= strtolower(htmlspecialchars($item['name'])) ?>" data-category="<?= $item['category'] ?>">
                                <td class="text-center">
                                    <input type="checkbox" name="selected_items[]" value="<?= $item['id'] ?>" class="form-check-input item-select-rap-checkbox" id="chk_item_rap_<?= $item['id'] ?>" style="cursor: pointer;">
                                </td>
                                <td>
                                    <label class="form-check-label d-block text-truncate font-monospace small fw-semibold mb-0" for="chk_item_rap_<?= $item['id'] ?>" title="<?= sanitize($item['item_code'] ?? '') ?>" style="cursor: pointer;">
                                        <?= sanitize($item['item_code'] ?? '-') ?>
                                    </label>
                                </td>
                                <td>
                                    <label class="form-check-label d-block text-truncate mb-0" for="chk_item_rap_<?= $item['id'] ?>" style="max-width: 240px; cursor: pointer;" title="<?= sanitize($item['name']) ?>">
                                        <strong><?= sanitize($item['name']) ?></strong>
                                        <?php if (!empty($item['brand'])): ?>
                                        <small class="text-muted d-block"><?= sanitize($item['brand']) ?></small>
                                        <?php endif; ?>
                                    </label>
                                </td>
                                <td><span class="<?= $catBadge ?> small"><?= ucfirst($item['category']) ?></span></td>
                                <td><span class="text-muted small"><?= sanitize($item['unit']) ?></span></td>
                                <td class="text-end small"><?= formatNumber($item['price']) ?></td>
                                <td>
                                    <input type="text" name="coefficients[<?= $item['id'] ?>]" class="form-control form-control-sm text-end modal-coeff-rap-input" value="1,0000" placeholder="0,0000" disabled>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                            <tr id="noMatchingItemsRapRow" style="display: none;">
                                <td colspan="7" class="text-center text-muted py-3">Tidak ada item yang sesuai dengan pencarian</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light d-flex justify-content-between">
                <div>
                    <span class="text-muted small" id="visibleItemsRapInfo">Total <?= count($itemsRap) ?> item</span>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="btnSubmitAddDetailRap" disabled>
                        <i class="mdi mdi-plus-box me-1"></i> Tambahkan Komponen (<span class="submit-count">0</span>)
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Delete Item RAP Form -->
<form method="POST" action="view.php?id=<?= $projectId ?>&tab=master&subtab=items_rap" id="deleteItemRapForm" class="d-none">
    <input type="hidden" name="action" value="delete_item_rap">
    <input type="hidden" name="item_id" id="deleteItemRapId">
</form>

<!-- Delete AHSP RAP Form -->
<form method="POST" action="view.php?id=<?= $projectId ?>&tab=master&subtab=ahsp_rap" id="deleteAhspRapForm" class="d-none">
    <input type="hidden" name="action" value="delete_ahsp_rap">
    <input type="hidden" name="ahsp_id" id="deleteAhspRapId">
</form>

<!-- Delete AHSP Detail RAP Form -->
<form method="POST" action="view.php?id=<?= $projectId ?>&tab=master&subtab=ahsp_rap" id="deleteAhspDetailRapForm" class="d-none">
    <input type="hidden" name="action" value="delete_ahsp_detail_rap">
    <input type="hidden" name="detail_id" id="deleteAhspDetailRapDetailId">
    <input type="hidden" name="ahsp_id" id="deleteAhspDetailRapAhspId">
</form>

<!-- Clear All Items RAP Form -->
<form method="POST" action="view.php?id=<?= $projectId ?>&tab=master&subtab=items_rap" id="clearAllItemsRapForm" class="d-none">
    <input type="hidden" name="action" value="clear_all_items_rap">
</form>

<!-- Clear All AHSP RAP Form -->
<form method="POST" action="view.php?id=<?= $projectId ?>&tab=master&subtab=ahsp_rap" id="clearAllAhspRapForm" class="d-none">
    <input type="hidden" name="action" value="clear_all_ahsp_rap">
</form>

<script>
// RAP AHSP - Edit Modal Handler
document.getElementById('editAhspRapModal')?.addEventListener('show.bs.modal', function(event) {
    var button = event.relatedTarget;
    document.getElementById('editAhspRapId').value = button.dataset.id;
    document.getElementById('editAhspRapCode').value = button.dataset.code;
    document.getElementById('editAhspRapName').value = button.dataset.name;
    document.getElementById('editAhspRapUnit').value = button.dataset.unit;
});

// Search/Filter and Multi-Checklist for Add Komponen RAP Modal
document.addEventListener('DOMContentLoaded', function() {
    var modalEl = document.getElementById('addDetailRapModal');
    if (!modalEl) return;
    
    var searchInput = document.getElementById('searchItemRapInput');
    var clearSearchBtn = document.getElementById('clearSearchItemRapBtn');
    var categoryPills = document.querySelectorAll('#filterCategoryItemRap button');
    var checkAllCheckbox = document.getElementById('checkAllItemsRap');
    var defaultCoeffInput = document.getElementById('defaultCoeffRapInput');
    var applyDefaultCoeffBtn = document.getElementById('applyDefaultCoeffRapBtn');
    var selectedCountBadge = document.getElementById('selectedItemsRapCountBadge');
    var submitCountSpan = document.querySelector('#btnSubmitAddDetailRap .submit-count');
    var submitBtn = document.getElementById('btnSubmitAddDetailRap');
    var visibleItemsInfo = document.getElementById('visibleItemsRapInfo');
    var noMatchingRow = document.getElementById('noMatchingItemsRapRow');
    var itemRows = modalEl.querySelectorAll('.modal-item-rap-row');
    
    var activeCategory = 'all';
    
    function updateSelectionStats() {
        var checkedRows = modalEl.querySelectorAll('.modal-item-rap-row .item-select-rap-checkbox:checked');
        var count = checkedRows.length;
        if (selectedCountBadge) selectedCountBadge.textContent = count + ' dipilih';
        if (submitCountSpan) submitCountSpan.textContent = count;
        if (submitBtn) submitBtn.disabled = (count === 0);
    }
    
    function filterRows() {
        var query = (searchInput.value || '').toLowerCase().trim();
        if (clearSearchBtn) clearSearchBtn.style.display = query.length > 0 ? 'block' : 'none';
        
        var visibleCount = 0;
        itemRows.forEach(function(row) {
            var code = row.dataset.code || '';
            var name = row.dataset.name || '';
            var cat = row.dataset.category || '';
            
            var matchSearch = !query || code.indexOf(query) > -1 || name.indexOf(query) > -1;
            var matchCategory = (activeCategory === 'all') || (cat === activeCategory);
            
            if (matchSearch && matchCategory) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        if (noMatchingRow) {
            noMatchingRow.style.display = visibleCount === 0 ? '' : 'none';
        }
        if (visibleItemsInfo) {
            visibleItemsInfo.textContent = 'Menampilkan ' + visibleCount + ' dari ' + itemRows.length + ' item';
        }
        
        updateCheckAllState();
    }
    
    function updateCheckAllState() {
        if (!checkAllCheckbox) return;
        var visibleCheckboxes = modalEl.querySelectorAll('.modal-item-rap-row:not([style*="display: none"]) .item-select-rap-checkbox');
        if (visibleCheckboxes.length === 0) {
            checkAllCheckbox.checked = false;
            checkAllCheckbox.indeterminate = false;
            return;
        }
        var checkedVisible = modalEl.querySelectorAll('.modal-item-rap-row:not([style*="display: none"]) .item-select-rap-checkbox:checked');
        if (checkedVisible.length === visibleCheckboxes.length) {
            checkAllCheckbox.checked = true;
            checkAllCheckbox.indeterminate = false;
        } else if (checkedVisible.length > 0) {
            checkAllCheckbox.checked = false;
            checkAllCheckbox.indeterminate = true;
        } else {
            checkAllCheckbox.checked = false;
            checkAllCheckbox.indeterminate = false;
        }
    }
    
    if (searchInput) {
        searchInput.addEventListener('input', filterRows);
    }
    if (clearSearchBtn) {
        clearSearchBtn.addEventListener('click', function() {
            searchInput.value = '';
            filterRows();
            searchInput.focus();
        });
    }
    
    categoryPills.forEach(function(btn) {
        btn.addEventListener('click', function() {
            categoryPills.forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            activeCategory = this.dataset.cat || 'all';
            filterRows();
        });
    });
    
    itemRows.forEach(function(row) {
        var chk = row.querySelector('.item-select-rap-checkbox');
        var coeffInput = row.querySelector('.modal-coeff-rap-input');
        
        if (chk && coeffInput) {
            chk.addEventListener('change', function() {
                if (this.checked) {
                    row.classList.add('table-active');
                    coeffInput.disabled = false;
                    if (!coeffInput.value || parseFloat(coeffInput.value.replace(',', '.')) <= 0) {
                        coeffInput.value = defaultCoeffInput ? defaultCoeffInput.value : '1,0000';
                    }
                } else {
                    row.classList.remove('table-active');
                    coeffInput.disabled = true;
                }
                updateSelectionStats();
                updateCheckAllState();
            });
        }
    });
    
    if (checkAllCheckbox) {
        checkAllCheckbox.addEventListener('change', function() {
            var isChecked = this.checked;
            var defaultCoeff = defaultCoeffInput ? defaultCoeffInput.value : '1,0000';
            
            var visibleRows = modalEl.querySelectorAll('.modal-item-rap-row:not([style*="display: none"])');
            visibleRows.forEach(function(row) {
                var chk = row.querySelector('.item-select-rap-checkbox');
                var coeffInput = row.querySelector('.modal-coeff-rap-input');
                if (chk && coeffInput) {
                    chk.checked = isChecked;
                    if (isChecked) {
                        row.classList.add('table-active');
                        coeffInput.disabled = false;
                        if (!coeffInput.value || parseFloat(coeffInput.value.replace(',', '.')) <= 0) {
                            coeffInput.value = defaultCoeff;
                        }
                    } else {
                        row.classList.remove('table-active');
                        coeffInput.disabled = true;
                    }
                }
            });
            updateSelectionStats();
        });
    }
    
    if (applyDefaultCoeffBtn && defaultCoeffInput) {
        applyDefaultCoeffBtn.addEventListener('click', function() {
            var val = defaultCoeffInput.value.trim() || '1,0000';
            modalEl.querySelectorAll('.modal-item-rap-row .item-select-rap-checkbox:checked').forEach(function(chk) {
                var row = chk.closest('.modal-item-rap-row');
                var coeffInput = row ? row.querySelector('.modal-coeff-rap-input') : null;
                if (coeffInput) {
                    coeffInput.value = val;
                }
            });
        });
    }
    
    modalEl.addEventListener('show.bs.modal', function(event) {
        var button = event.relatedTarget;
        if (button && button.dataset.ahspId) {
            document.getElementById('addDetailRapAhspId').value = button.dataset.ahspId;
        }
        
        if (searchInput) searchInput.value = '';
        activeCategory = 'all';
        categoryPills.forEach(function(b) {
            if (b.dataset.cat === 'all') b.classList.add('active');
            else b.classList.remove('active');
        });
        
        itemRows.forEach(function(row) {
            var chk = row.querySelector('.item-select-rap-checkbox');
            var coeffInput = row.querySelector('.modal-coeff-rap-input');
            if (chk) chk.checked = false;
            if (coeffInput) {
                coeffInput.disabled = true;
                coeffInput.value = defaultCoeffInput ? defaultCoeffInput.value : '1,0000';
            }
            row.classList.remove('table-active');
            row.style.display = '';
        });
        
        if (checkAllCheckbox) {
            checkAllCheckbox.checked = false;
            checkAllCheckbox.indeterminate = false;
        }
        updateSelectionStats();
        filterRows();
    });
    
    modalEl.addEventListener('shown.bs.modal', function() {
        if (searchInput) searchInput.focus();
    });
});

// Category Filter for RAP Items
document.getElementById('categoryFilterRap')?.addEventListener('click', function(e) {
    if (e.target.closest('button')) {
        var filter = e.target.closest('button').dataset.filter;
        var tables = document.querySelectorAll('.item-table-rap');
        var sections = document.querySelectorAll('.item-section-rap');
        
        this.querySelectorAll('button').forEach(b => b.classList.remove('active'));
        e.target.closest('button').classList.add('active');
        
        tables.forEach(function(table) {
            var tableCategory = table.dataset.category;
            var section = table.previousElementSibling;
            if (filter === 'all' || tableCategory === filter) {
                table.closest('.table-responsive').style.display = '';
                if (section && section.classList.contains('item-section-rap')) {
                    section.style.display = '';
                }
            } else {
                table.closest('.table-responsive').style.display = 'none';
                if (section && section.classList.contains('item-section-rap')) {
                    section.style.display = 'none';
                }
            }
        });
    }
});

// Search RAP Items
document.getElementById('searchItemsRap')?.addEventListener('input', function() {
    var search = this.value.toLowerCase();
    var rows = document.querySelectorAll('.item-row-rap');
    rows.forEach(function(row) {
        var text = row.textContent.toLowerCase();
        row.style.display = text.includes(search) ? '' : 'none';
    });
});

// Search RAP AHSP
document.getElementById('searchAhspRap')?.addEventListener('input', function() {
    var search = this.value.toLowerCase();
    var items = document.querySelectorAll('#ahspAccordionRap .accordion-item');
    items.forEach(function(item) {
        var text = item.textContent.toLowerCase();
        item.style.display = text.includes(search) ? '' : 'none';
    });
});

// Sort AHSP RAP
function sortAhspRap(value) {
    window.location.href = 'view.php?id=<?= $projectId ?>&tab=master&subtab=ahsp_rap&ahsp_sort=' + value;
}

// Delete functions
function deleteItemRap(id) {
    if (confirm('Apakah anda yakin ingin menghapus item ini?')) {
        document.getElementById('deleteItemRapId').value = id;
        document.getElementById('deleteItemRapForm').submit();
    }
}

function deleteAhspRap(id) {
    if (confirm('Apakah anda yakin ingin menghapus AHSP ini beserta semua komponennya?')) {
        document.getElementById('deleteAhspRapId').value = id;
        document.getElementById('deleteAhspRapForm').submit();
    }
}

function deleteAhspDetailRap(detailId, ahspId) {
    if (confirm('Apakah anda yakin ingin menghapus komponen ini?')) {
        document.getElementById('deleteAhspDetailRapDetailId').value = detailId;
        document.getElementById('deleteAhspDetailRapAhspId').value = ahspId;
        document.getElementById('deleteAhspDetailRapForm').submit();
    }
}

function confirmClearItemsRap() {
    if (confirm('Apakah Anda yakin ingin menghapus SEMUA items RAP? Tindakan ini tidak dapat dibatalkan.')) {
        document.getElementById('clearAllItemsRapForm').submit();
    }
}

function confirmClearAhspRap() {
    if (confirm('Apakah Anda yakin ingin menghapus SEMUA AHSP RAP? Tindakan ini tidak dapat dibatalkan.')) {
        document.getElementById('clearAllAhspRapForm').submit();
    }
}

// Edit mode toggles for RAP
document.getElementById('editModeToggleRapItems')?.addEventListener('change', function() {
    var editElements = document.querySelectorAll('.edit-mode-only-rap-items');
    editElements.forEach(el => {
        if (this.checked) {
            el.classList.remove('d-none');
        } else {
            el.classList.add('d-none');
        }
    });
    // Save to localStorage
    localStorage.setItem('editModeRapItems', this.checked ? '1' : '0');
});

document.getElementById('editModeToggleRapAhsp')?.addEventListener('change', function() {
    var editElements = document.querySelectorAll('.edit-mode-only-rap-ahsp');
    editElements.forEach(el => {
        if (this.checked) {
            el.classList.remove('d-none');
        } else {
            el.classList.add('d-none');
        }
    });
    // Save to localStorage
    localStorage.setItem('editModeRapAhsp', this.checked ? '1' : '0');
});

// Restore edit mode state from localStorage
document.addEventListener('DOMContentLoaded', function() {
    // RAP Items edit mode
    var savedRapItemsMode = localStorage.getItem('editModeRapItems');
    if (savedRapItemsMode === '1') {
        var toggle = document.getElementById('editModeToggleRapItems');
        if (toggle) {
            toggle.checked = true;
            toggle.dispatchEvent(new Event('change'));
        }
    }
    
    // RAP AHSP edit mode
    var savedRapAhspMode = localStorage.getItem('editModeRapAhsp');
    if (savedRapAhspMode === '1') {
        var toggle = document.getElementById('editModeToggleRapAhsp');
        if (toggle) {
            toggle.checked = true;
            toggle.dispatchEvent(new Event('change'));
        }
    }
    
    // Inline edit auto-save for RAP AHSP Details
    document.querySelectorAll('.ahsp-detail-row-rap input').forEach(function(input) {
        input.dataset.original = input.value;
        
        input.addEventListener('keypress', function(e) {
            if (e.which === 13 || e.keyCode === 13) {
                e.preventDefault();
                e.stopPropagation();
                saveAhspDetailRap(this.closest('tr'));
                this.blur(); // Remove focus to confirm visual change
            }
        });
        
        input.addEventListener('blur', function() {
            if (this.value !== this.dataset.original) {
                saveAhspDetailRap(this.closest('tr'));
            }
        });
    });
    
    // Inline edit for RAP items table
    document.querySelectorAll('.item-row-rap input').forEach(function(input) {
        input.dataset.original = input.value;
        
        input.addEventListener('keypress', function(e) {
            if (e.which === 13 || e.keyCode === 13) {
                e.preventDefault();
                e.stopPropagation();
                saveItemRap(this.closest('tr'));
                this.blur();
            }
        });
        
        input.addEventListener('blur', function() {
            if (this.value !== this.dataset.original) {
                saveItemRap(this.closest('tr'));
            }
        });
    });
});

// Save item RAP via AJAX
function saveItemRap(row) {
    var itemId = row.dataset.itemId;
    var formData = new FormData();
    formData.append('action', 'update_item_rap_ajax');
    formData.append('item_id', itemId);
    formData.append('item_code', row.querySelector('.item-code-rap').value);
    formData.append('item_name', row.querySelector('.item-name-rap').value);
    formData.append('item_brand', row.querySelector('.item-brand-rap').value);
    formData.append('item_category', row.dataset.category);
    formData.append('item_unit', row.querySelector('.item-unit-rap').value);
    formData.append('item_price', row.querySelector('.item-price-rap').value);
    formData.append('item_actual_price', row.querySelector('.item-actual-price-rap').value);
    
    // Show saving indicator (yellow bg)
    var originalBg = row.style.backgroundColor;
    row.style.backgroundColor = '#fffde7'; // Light yellow
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update original values
            row.querySelectorAll('input').forEach(input => {
                input.dataset.original = input.value;
            });
            // Show toast using global helper
            if (typeof showInlineToast === 'function') {
                showInlineToast(data.message, 'success');
            } else {
                showToast(data.message, 'success');
            }
            
            // Show success indicator (green fade)
            row.style.transition = 'background-color 0.5s';
            row.style.backgroundColor = 'rgba(40, 167, 69, 0.1)';
            setTimeout(function() {
                row.style.backgroundColor = originalBg || '';
            }, 1000);
            
            // Trigger AHSP Table Refresh
            refreshAhspRapTable();
        } else {
            if (typeof showInlineToast === 'function') {
                showInlineToast(data.message || 'Gagal menyimpan', 'danger');
            } else {
                showToast(data.message || 'Gagal menyimpan', 'error');
            }
            // Show error indicator (red fade)
            row.style.backgroundColor = 'rgba(220, 53, 69, 0.1)';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        if (typeof showInlineToast === 'function') {
            showInlineToast('Terjadi kesalahan network', 'danger');
        } else {
            showToast('Terjadi kesalahan network', 'error');
        }
        row.style.backgroundColor = 'rgba(220, 53, 69, 0.1)';
    });
}

// Save AHSP Detail RAP via AJAX
function saveAhspDetailRap(row) {
    var detailId = row.dataset.detailId;
    var ahspId = row.dataset.ahspId;
    var formData = new FormData();
    formData.append('action', 'update_ahsp_detail_rap_ajax');
    formData.append('detail_id', detailId);
    formData.append('ahsp_id', ahspId);
    
    var coeffInput = row.querySelector('.ahsp-detail-coeff-rap');
    formData.append('coefficient', coeffInput.value);
    
    // Show saving indicator (yellow bg)
    var originalBg = row.style.backgroundColor;
    row.style.backgroundColor = '#fffde7'; // Light yellow
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update original values
            row.querySelectorAll('input').forEach(input => {
                input.dataset.original = input.value;
            });
            // Show toast using global helper
            if (typeof showInlineToast === 'function') {
                showInlineToast(data.message, 'success');
            } else {
                showToast(data.message, 'success');
            }
            
            // Show success indicator (green fade)
            row.style.transition = 'background-color 0.5s';
            row.style.backgroundColor = 'rgba(40, 167, 69, 0.1)';
            setTimeout(function() {
                row.style.backgroundColor = originalBg || '';
            }, 1000);
        } else {
            if (typeof showInlineToast === 'function') {
                showInlineToast(data.message || 'Gagal menyimpan', 'danger');
            } else {
                showToast(data.message || 'Gagal menyimpan', 'error');
            }
            // Revert value
            coeffInput.value = coeffInput.dataset.original;
            // Show error indicator
            row.style.backgroundColor = 'rgba(220, 53, 69, 0.1)';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        if (typeof showInlineToast === 'function') {
            showInlineToast('Terjadi kesalahan network', 'danger');
        } else {
            showToast('Terjadi kesalahan network', 'error');
        }
        row.style.backgroundColor = 'rgba(220, 53, 69, 0.1)';
    });
}

// Function to refresh AHSP RAP table
function refreshAhspRapTable() {
    console.log('Refreshing AHSP RAP Table...');
    // We will implement this in next step
    // fetch('?id=PROJECT_ID&action=get_ahsp_rap_table')
    // .then(res => res.text())
    // .then(html => $('#ahspAccordionRap').html(html));
    
    // For now, let's use the existing URL with a special param
    var projectId = new URLSearchParams(window.location.search).get('id');
    fetch('view.php?id=' + projectId + '&action=get_ahsp_rap_html')
    .then(response => response.text())
    .then(html => {
        if (html.length > 50) { // Basic validation
             var container = document.getElementById('ahspAccordionRap');
             if (container) {
                 container.innerHTML = html;
                 // Re-attach event listeners if needed (mostly inline handlers so ok)
                 // But newly added DOM elements might need initialization if using class-based listeners
                 // Fortunately we used inline onchange/onclick or bubble delegation
             }
        }
    })
    .catch(err => console.error('Failed to refresh AHSP table', err));
}

// Toast notification function (uses existing toast if available)
function showToast(message, type) {
    if (typeof window.showToast === 'function') {
        window.showToast(message, type);
    } else {
        alert(message);
    }
}

// Edit Mode Toggle for RAP
document.addEventListener('DOMContentLoaded', function() {
    var editModeToggleRapItems = document.getElementById('editModeToggleRapItems');
    var editModeToggleRapAhsp = document.getElementById('editModeToggleRapAhsp');
    
    function toggleRapEditMode(isEnabled, scope) {
        var container = scope === 'items_rap' ? document.getElementById('items-rap-tab') : document.getElementById('ahsp-rap-tab');
        if (!container) return;
        
        var editOnlyClass = scope === 'items_rap' ? '.edit-mode-only-rap-items' : '.edit-mode-only-rap-ahsp';
        
        // Show/hide edit-mode-only buttons
        var editOnlyElements = container.querySelectorAll(editOnlyClass);
        editOnlyElements.forEach(function(el) {
            if (isEnabled) {
                el.classList.remove('d-none');
            } else {
                el.classList.add('d-none');
            }
        });
        
        if (scope === 'items_rap') {
            // Enable/disable input fields in items
            var inputs = container.querySelectorAll('.item-row-rap input, .item-row-rap select');
            inputs.forEach(function(input) {
                input.disabled = !isEnabled;
                if (isEnabled) {
                    input.classList.add('bg-white');
                } else {
                    input.classList.remove('bg-white');
                }
            });
        } else {
            // For AHSP RAP, enable/disable delete and edit buttons
            var ahspEditButtons = container.querySelectorAll('.ahsp-edit-btn-rap, .ahsp-delete-btn-rap, .detail-delete-btn-rap, input[name="coefficient"]');
            ahspEditButtons.forEach(function(el) {
                if (isEnabled) {
                    el.classList.remove('d-none');
                    if (el.tagName === 'INPUT') el.disabled = false;
                } else {
                    // For buttons that are usually hidden unless editing
                    if (el.tagName !== 'INPUT') { // Keep structure
                       // Logic handled by editOnlyClass above for delete/edit buttons that have that class.
                       // But some might check individually. Since we used edit-mode-only-rap-ahsp on them, the first block handles visibility.
                    }
                    if (el.tagName === 'INPUT') el.disabled = true;
                }
            });
        }
        
        // Store state in localStorage
        localStorage.setItem('editMode_' + scope, isEnabled ? '1' : '0');
    }
    
    // Initialize RAP Items edit mode toggle
    if (editModeToggleRapItems) {
        var savedState = localStorage.getItem('editMode_items_rap') === '1';
        editModeToggleRapItems.checked = savedState;
        toggleRapEditMode(savedState, 'items_rap');
        
        editModeToggleRapItems.addEventListener('change', function() {
            toggleRapEditMode(this.checked, 'items_rap');
        });
    }
    
    // Initialize RAP AHSP edit mode toggle
    if (editModeToggleRapAhsp) {
        var savedStateAhsp = localStorage.getItem('editMode_ahsp_rap') === '1';
        editModeToggleRapAhsp.checked = savedStateAhsp;
        toggleRapEditMode(savedStateAhsp, 'ahsp_rap');
        
        editModeToggleRapAhsp.addEventListener('change', function() {
            toggleRapEditMode(this.checked, 'ahsp_rap');
        });
    }
});
</script>
