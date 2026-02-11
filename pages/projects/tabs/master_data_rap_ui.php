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
            <a href="import.php?project_id=<?= $projectId ?>&type=items" class="btn btn-success" title="Import ke RAB, data akan otomatis di-sync ke RAP">
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
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="add_ahsp_detail_rap">
            <input type="hidden" name="ahsp_id" id="addDetailRapAhspId">
            <div class="modal-header">
                <h5 class="modal-title"><i class="mdi mdi-plus"></i> Tambah Komponen AHSP RAP</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label required">Pilih Item</label>
                    <select class="form-select" name="detail_item_id" id="addDetailRapItemSelect" required>
                        <option value="">-- Pilih Item --</option>
                        <optgroup label="Upah">
                            <?php foreach ($itemsRapByCategory['upah'] as $item): ?>
                            <option value="<?= $item['id'] ?>" data-price="<?= $item['price'] ?>">
                                <?= sanitize($item['name']) ?> (<?= sanitize($item['unit']) ?>) - <?= formatRupiah($item['price']) ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Material">
                            <?php foreach ($itemsRapByCategory['material'] as $item): ?>
                            <option value="<?= $item['id'] ?>" data-price="<?= $item['price'] ?>">
                                <?= sanitize($item['name']) ?> (<?= sanitize($item['unit']) ?>) - <?= formatRupiah($item['price']) ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Alat">
                            <?php foreach ($itemsRapByCategory['alat'] as $item): ?>
                            <option value="<?= $item['id'] ?>" data-price="<?= $item['price'] ?>">
                                <?= sanitize($item['name']) ?> (<?= sanitize($item['unit']) ?>) - <?= formatRupiah($item['price']) ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Koefisien</label>
                        <input type="text" class="form-control" name="coefficient" required placeholder="0,0000">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Harga Satuan Custom (Rp)</label>
                        <input type="text" class="form-control text-end currency" name="detail_unit_price" placeholder="Kosongkan untuk pakai harga item">
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

// Add Detail RAP Modal - set AHSP ID
document.getElementById('addDetailRapModal')?.addEventListener('show.bs.modal', function(event) {
    var button = event.relatedTarget;
    document.getElementById('addDetailRapAhspId').value = button.dataset.ahspId;
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
