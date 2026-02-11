<?php
// Partial: AHSP RAP List
// Requires: $ahspListRap, $overheadPct, $isEditable, $projectId

foreach ($ahspListRap as $idx => $ahsp): 
    // Get details for this AHSP RAP
    // Note: We use dbGetAll directly here if $details are not passed. 
    // Ideally this logic should be in the controller/handler, but for a partial include pattern, 
    // we can keep it here or ensure $ahspListRap contains everything needed.
    // For simplicity in this legacy-style codebase, we'll keep the query here but ensure db connection exists.
    
    // Check if db function exists (it should), but just in case
    if (!function_exists('dbGetAll')) {
         // Fallback or error, but this should be included where functions are loaded
    }

    $details = dbGetAll("
        SELECT d.*, i.name as item_name, i.category, i.unit, 
               i.price as item_up_price, i.actual_price as item_actual_price,
               COALESCE(d.unit_price, i.price) as effective_price,
               (d.coefficient * COALESCE(d.unit_price, i.price)) as total_price
        FROM project_ahsp_details_rap d
        JOIN project_items_rap i ON d.item_id = i.id
        WHERE d.ahsp_id = ?
        ORDER BY i.category, i.name
    ", [$ahsp['id']]);
    
    // Calculate totals for header display
    $detailsByCategory = ['upah' => [], 'material' => [], 'alat' => []];
    $totalByCategory = ['upah' => 0, 'material' => 0, 'alat' => 0];
    foreach ($details as $detail) {
        $detailsByCategory[$detail['category']][] = $detail;
        $totalByCategory[$detail['category']] += $detail['total_price'];
    }
    $subtotal = array_sum($totalByCategory);
    $overheadAmount = $subtotal * ($overheadPct / 100);
    $grandTotal = $subtotal + $overheadAmount;
    
    // Check if this AHSP should be opened
    $targetAhspRapId = $_GET['ahsp_rap_id'] ?? null;
    $isTargetAhsp = ($targetAhspRapId && $ahsp['id'] == $targetAhspRapId);
    $shouldBeOpen = $isTargetAhsp || ($idx == 0 && !$targetAhspRapId);
?>
<div class="accordion-item <?= $isTargetAhsp ? 'border-primary border-2 target-ahsp' : '' ?>">
    <h2 class="accordion-header">
        <button class="accordion-button <?= !$shouldBeOpen ? 'collapsed' : '' ?>" type="button" 
                data-bs-toggle="collapse" data-bs-target="#ahsp-rap-<?= $ahsp['id'] ?>">
            <div class="d-flex justify-content-between w-100 me-3">
                <div>
                    <span class="badge bg-info me-2"><?= sanitize($ahsp['ahsp_code'] ?? '') ?></span>
                    <strong><?= sanitize($ahsp['work_name']) ?></strong>
                    <span class="text-muted ms-2">(<?= sanitize($ahsp['unit']) ?>)</span>
                </div>
                <div class="text-end">
                    <span class="badge bg-dark"><?= formatRupiah($grandTotal) ?></span>
                </div>
            </div>
        </button>
    </h2>
    <div id="ahsp-rap-<?= $ahsp['id'] ?>" class="accordion-collapse collapse <?= $shouldBeOpen ? 'show' : '' ?>" data-bs-parent="#ahspAccordionRap">
        <div class="accordion-body">
            <!-- Edit/Delete buttons -->
            <?php if ($isEditable): ?>
            <div class="mb-3 d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary ahsp-edit-btn-rap" 
                        data-bs-toggle="modal" 
                        data-bs-target="#editAhspRapModal"
                        data-id="<?= $ahsp['id'] ?>"
                        data-code="<?= htmlspecialchars($ahsp['ahsp_code'] ?? '') ?>"
                        data-name="<?= htmlspecialchars($ahsp['work_name']) ?>"
                        data-unit="<?= htmlspecialchars($ahsp['unit']) ?>">
                    <i class="mdi mdi-pencil"></i> Edit AHSP
                </button>
                <button class="btn btn-sm btn-outline-danger ahsp-delete-btn-rap edit-mode-only-rap-ahsp d-none" 
                        onclick="deleteAhspRap(<?= $ahsp['id'] ?>)">
                    <i class="mdi mdi-delete"></i> Hapus
                </button>
                <button class="btn btn-sm btn-outline-success" 
                        data-bs-toggle="modal" 
                        data-bs-target="#addDetailRapModal"
                        data-ahsp-id="<?= $ahsp['id'] ?>">
                    <i class="mdi mdi-plus"></i> Tambah Komponen
                </button>
            </div>
            <?php endif; ?>
            
            <!-- Components Table - sama persis dengan AHSP RAB -->
            <?php if (empty($details)): ?>
            <p class="text-muted">Belum ada komponen. Tambahkan upah, material, atau alat.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead>
                        <tr class="table-primary">
                            <th width="40">No</th>
                            <th>Uraian</th>
                            <th width="80">Satuan</th>
                            <th width="100" class="text-end">Koefisien</th>
                            <th width="120" class="text-end">Harga Satuan</th>
                            <th width="130" class="text-end">Jumlah Harga</th>
                            <?php if ($isEditable): ?><th width="50">Aksi</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- A. TENAGA -->
                        <tr style="background-color: #e8f4fd;">
                            <td colspan="<?= $isEditable ? 7 : 6 ?>"><strong>A. TENAGA</strong></td>
                        </tr>
                        <?php if (empty($detailsByCategory['upah'])): ?>
                        <tr><td colspan="<?= $isEditable ? 7 : 6 ?>" class="text-center text-muted">Belum ada komponen tenaga</td></tr>
                        <?php else: ?>
                        <?php $no = 1; foreach ($detailsByCategory['upah'] as $detail): ?>
                        <tr class="ahsp-detail-row-rap" data-detail-id="<?= $detail['id'] ?>" data-ahsp-id="<?= $ahsp['id'] ?>">
                            <td class="text-center"><?= $no++ ?></td>
                            <td><?= sanitize($detail['item_name']) ?></td>
                            <td><?= sanitize($detail['unit']) ?></td>
                            <td><input type="text" class="form-control form-control-sm border-0 text-end ahsp-detail-coeff-rap" name="coefficient" value="<?= formatNumber($detail['coefficient'], 4) ?>" style="width:80px;" <?= !$isEditable ? 'disabled' : '' ?>></td>
                            <td class="text-end"><?= formatRupiah($detail['effective_price']) ?></td>
                            <td class="text-end"><?= formatRupiah($detail['total_price']) ?></td>
                            <?php if ($isEditable): ?>
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-danger detail-delete-btn-rap edit-mode-only-rap-ahsp d-none" onclick="deleteAhspDetailRap(<?= $detail['id'] ?>, <?= $ahsp['id'] ?>)"><i class="mdi mdi-delete"></i></button>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        <tr style="background-color: #d4edfc;">
                            <td colspan="5" class="text-end"><strong>JUMLAH TENAGA</strong></td>
                            <td class="text-end"><strong><?= formatRupiah($totalByCategory['upah']) ?></strong></td>
                            <?php if ($isEditable): ?><td></td><?php endif; ?>
                        </tr>
                        
                        <!-- B. BAHAN -->
                        <tr style="background-color: #e8fde8;">
                            <td colspan="<?= $isEditable ? 7 : 6 ?>"><strong>B. BAHAN</strong></td>
                        </tr>
                        <?php if (empty($detailsByCategory['material'])): ?>
                        <tr><td colspan="<?= $isEditable ? 7 : 6 ?>" class="text-center text-muted">Belum ada komponen bahan</td></tr>
                        <?php else: ?>
                        <?php $no = 1; foreach ($detailsByCategory['material'] as $detail): ?>
                        <tr class="ahsp-detail-row-rap" data-detail-id="<?= $detail['id'] ?>" data-ahsp-id="<?= $ahsp['id'] ?>">
                            <td class="text-center"><?= $no++ ?></td>
                            <td><?= sanitize($detail['item_name']) ?></td>
                            <td><?= sanitize($detail['unit']) ?></td>
                            <td><input type="text" class="form-control form-control-sm border-0 text-end ahsp-detail-coeff-rap" name="coefficient" value="<?= formatNumber($detail['coefficient'], 4) ?>" style="width:80px;" <?= !$isEditable ? 'disabled' : '' ?>></td>
                            <td class="text-end"><?= formatRupiah($detail['effective_price']) ?></td>
                            <td class="text-end"><?= formatRupiah($detail['total_price']) ?></td>
                            <?php if ($isEditable): ?>
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-danger detail-delete-btn-rap edit-mode-only-rap-ahsp d-none" onclick="deleteAhspDetailRap(<?= $detail['id'] ?>, <?= $ahsp['id'] ?>)"><i class="mdi mdi-delete"></i></button>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        <tr style="background-color: #c8f7c8;">
                            <td colspan="5" class="text-end"><strong>JUMLAH BAHAN</strong></td>
                            <td class="text-end"><strong><?= formatRupiah($totalByCategory['material']) ?></strong></td>
                            <?php if ($isEditable): ?><td></td><?php endif; ?>
                        </tr>
                        
                        <!-- C. PERALATAN -->
                        <tr style="background-color: #fdf8e8;">
                            <td colspan="<?= $isEditable ? 7 : 6 ?>"><strong>C. PERALATAN</strong></td>
                        </tr>
                        <?php if (empty($detailsByCategory['alat'])): ?>
                        <tr><td colspan="<?= $isEditable ? 7 : 6 ?>" class="text-center text-muted">Belum ada komponen alat</td></tr>
                        <?php else: ?>
                        <?php $no = 1; foreach ($detailsByCategory['alat'] as $detail): ?>
                        <tr class="ahsp-detail-row-rap" data-detail-id="<?= $detail['id'] ?>" data-ahsp-id="<?= $ahsp['id'] ?>">
                            <td class="text-center"><?= $no++ ?></td>
                            <td><?= sanitize($detail['item_name']) ?></td>
                            <td><?= sanitize($detail['unit']) ?></td>
                            <td><input type="text" class="form-control form-control-sm border-0 text-end ahsp-detail-coeff-rap" name="coefficient" value="<?= formatNumber($detail['coefficient'], 4) ?>" style="width:80px;" <?= !$isEditable ? 'disabled' : '' ?>></td>
                            <td class="text-end"><?= formatRupiah($detail['effective_price']) ?></td>
                            <td class="text-end"><?= formatRupiah($detail['total_price']) ?></td>
                            <?php if ($isEditable): ?>
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-danger detail-delete-btn-rap edit-mode-only-rap-ahsp d-none" onclick="deleteAhspDetailRap(<?= $detail['id'] ?>, <?= $ahsp['id'] ?>)"><i class="mdi mdi-delete"></i></button>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        <tr style="background-color: #f5edc8;">
                            <td colspan="5" class="text-end"><strong>JUMLAH ALAT</strong></td>
                            <td class="text-end"><strong><?= formatRupiah($totalByCategory['alat']) ?></strong></td>
                            <?php if ($isEditable): ?><td></td><?php endif; ?>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="table-secondary">
                            <td colspan="5" class="text-end"><strong>D. Jumlah (A+B+C)</strong></td>
                            <td class="text-end"><strong><?= formatRupiah($subtotal) ?></strong></td>
                            <?php if ($isEditable): ?><td></td><?php endif; ?>
                        </tr>
                        <tr>
                            <td colspan="5" class="text-end"><strong>E. Overhead & Profit (<?= $overheadPct ?>%)</strong></td>
                            <td class="text-end"><?= formatRupiah($overheadAmount) ?></td>
                            <?php if ($isEditable): ?><td></td><?php endif; ?>
                        </tr>
                        <tr class="table-dark">
                            <td colspan="5" class="text-end"><strong>HARGA SATUAN PEKERJAAN (D+E)</strong></td>
                            <td class="text-end"><strong><?= formatRupiah($grandTotal) ?></strong></td>
                            <?php if ($isEditable): ?><td></td><?php endif; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
