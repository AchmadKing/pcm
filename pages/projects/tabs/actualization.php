<?php
/**
 * Tab Actualization - Project Detail
 * PCC - Project Cost Control System
 * 
 * Lists approved requests for $projectId.
 * Displays spending summary (Yang Dibelanjakan vs Sisa Anggaran).
 * Provides interactive modal to input/edit actualization and upload receipts.
 */

if (!isset($project) || !isset($projectId)) {
    die('Invalid access');
}

$canViewActual = hasPermission('requests.view') || hasPermission('requests.approve');
$canApprove = hasPermission('requests.approve');
$isAdmin = hasPermission('projects.edit');

// Fetch all approved requests for this project
$approvedRequests = dbGetAll("
    SELECT r.*, u.full_name as created_by_name,
           ua.full_name as approved_by_name,
           ra.id as actual_id, ra.remaining_upah, ra.remaining_material, ra.remaining_alat,
           ra.consumed_upah, ra.consumed_material, ra.consumed_alat,
           ra.notes_upah, ra.notes_material, ra.notes_alat,
           ra.created_at as actualized_at, ra.updated_at as actualized_updated_at,
           uact.full_name as actualized_by_name,
           (SELECT COALESCE(SUM(CASE WHEN r.request_type = 'non_rab' THEN reqi.quantity * reqi.unit_price ELSE reqi.total_price END), 0) FROM request_items reqi WHERE reqi.request_id = r.id) as total_amount,
           (SELECT COUNT(*) FROM request_actual_attachments raa WHERE raa.request_actual_id = ra.id) as attachment_count
    FROM requests r
    LEFT JOIN users u ON r.created_by = u.id
    LEFT JOIN users ua ON r.approved_by = ua.id
    LEFT JOIN request_actuals ra ON ra.request_id = r.id
    LEFT JOIN users uact ON ra.created_by = uact.id
    WHERE r.project_id = ? AND r.status = 'approved'
    ORDER BY r.target_week DESC, r.created_at DESC
", [$projectId]);

// Summary Calculations
$summaryTotalApproved = 0;
$summaryTotalSisa = 0;
$summaryTotalDibelanjakan = 0;
$countActualized = 0;
$countUnactualized = 0;
$countLangsung = 0;

foreach ($approvedRequests as $reqRow) {
    $rowTotal = floatval($reqRow['total_amount']);
    $summaryTotalApproved += $rowTotal;
    $isNonRab = ($reqRow['request_type'] ?? 'rab') === 'non_rab';

    if ($isNonRab) {
        $countLangsung++;
        $summaryTotalDibelanjakan += $rowTotal;
    } else {
        if (!empty($reqRow['is_actualized']) && !empty($reqRow['actual_id'])) {
            $countActualized++;
            $rowSisa = floatval($reqRow['remaining_upah']) + floatval($reqRow['remaining_material']) + floatval($reqRow['remaining_alat']);
            $summaryTotalSisa += $rowSisa;
            $summaryTotalDibelanjakan += max(0, $rowTotal - $rowSisa);
        } else {
            $countUnactualized++;
            $summaryTotalDibelanjakan += $rowTotal;
        }
    }
}

// Unconsumed available sisa in lapangan
$sisaData = dbGetRow("
    SELECT 
        COALESCE(SUM(
            GREATEST(ra.remaining_upah - ra.consumed_upah, 0) +
            GREATEST(ra.remaining_material - ra.consumed_material, 0) +
            GREATEST(ra.remaining_alat - ra.consumed_alat, 0)
        ), 0) as total_unconsumed
    FROM request_actuals ra
    JOIN requests r ON ra.request_id = r.id
    WHERE r.project_id = ? AND r.status = 'approved' AND r.is_actualized = 1
", [$projectId]);
$totalUnconsumedSisa = floatval($sisaData['total_unconsumed'] ?? 0);
?>

<!-- Top Summary Cards -->
<div class="row mb-3 g-3">
    <div class="col-xl-3 col-md-6">
        <div class="card mini-stats-wid h-100 mb-0 shadow-sm border">
            <div class="card-body">
                <div class="d-flex">
                    <div class="flex-grow-1">
                        <p class="text-muted fw-medium mb-1 font-size-12">Total Disetujui (Approved)</p>
                        <h5 class="mb-0 text-primary"><?= formatRupiah($summaryTotalApproved) ?></h5>
                        <small class="text-muted"><?= count($approvedRequests) ?> pengajuan approved</small>
                    </div>
                    <div class="avatar-sm align-self-center ms-2 flex-shrink-0">
                        <span class="avatar-title rounded-circle bg-primary-subtle text-primary font-size-20">
                            <i class="mdi mdi-check-all"></i>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card mini-stats-wid h-100 mb-0 shadow-sm border">
            <div class="card-body">
                <div class="d-flex">
                    <div class="flex-grow-1">
                        <p class="text-muted fw-medium mb-1 font-size-12">Yang Dibelanjakan (Realisasi Kas)</p>
                        <h5 class="mb-0 text-success"><?= formatRupiah($summaryTotalDibelanjakan) ?></h5>
                        <small class="text-muted">Total netto terpakai</small>
                    </div>
                    <div class="avatar-sm align-self-center ms-2 flex-shrink-0">
                        <span class="avatar-title rounded-circle bg-success-subtle text-success font-size-20">
                            <i class="mdi mdi-cash-check"></i>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card mini-stats-wid h-100 mb-0 shadow-sm border">
            <div class="card-body">
                <div class="d-flex">
                    <div class="flex-grow-1">
                        <p class="text-muted fw-medium mb-1 font-size-12">Sisa Anggaran di Lapangan</p>
                        <h5 class="mb-0 text-warning"><?= formatRupiah($totalUnconsumedSisa) ?></h5>
                        <small class="text-muted">Belum terpotong pengajuan baru</small>
                    </div>
                    <div class="avatar-sm align-self-center ms-2 flex-shrink-0">
                        <span class="avatar-title rounded-circle bg-warning-subtle text-warning font-size-20">
                            <i class="mdi mdi-cash-refund"></i>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card mini-stats-wid h-100 mb-0 shadow-sm border">
            <div class="card-body">
                <div class="d-flex">
                    <div class="flex-grow-1">
                        <p class="text-muted fw-medium mb-1 font-size-12">Status Laporan Aktual</p>
                        <div class="d-flex gap-2 align-items-center mt-1">
                            <span class="badge bg-success font-size-11"><?= $countActualized ?> Sudah</span>
                            <?php if ($countUnactualized > 0): ?>
                            <span class="badge bg-danger font-size-11"><?= $countUnactualized ?> Belum</span>
                            <?php endif; ?>
                            <?php if ($countLangsung > 0): ?>
                            <span class="badge text-white font-size-11" style="background-color: #6f42c1;"><?= $countLangsung ?> Langsung</span>
                            <?php endif; ?>
                        </div>
                        <small class="text-muted d-block mt-1">Dari <?= count($approvedRequests) ?> total pengajuan</small>
                    </div>
                    <div class="avatar-sm align-self-center ms-2 flex-shrink-0">
                        <span class="avatar-title rounded-circle bg-info-subtle text-info font-size-20">
                            <i class="mdi mdi-clipboard-check-outline"></i>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Table: Approved Requests with Actualization Data -->
<div class="card shadow-sm border">
    <div class="card-header py-2 bg-light d-flex justify-content-between align-items-center">
        <h6 class="mb-0 text-dark">
            <i class="mdi mdi-cash-register text-success me-1"></i> Daftar Belanja & Laporan Aktual Proyek
        </h6>
        <span class="badge bg-secondary"><?= count($approvedRequests) ?> Pengajuan Approved</span>
    </div>
    <div class="card-body">
        <?php if (empty($approvedRequests)): ?>
        <div class="text-center py-5 text-muted">
            <i class="mdi mdi-cash-remove" style="font-size: 3rem;"></i>
            <p class="mt-2 mb-0 fw-medium">Belum ada pengajuan dana yang disetujui pada proyek ini.</p>
            <small class="text-muted">Laporan aktualisasi dapat diisi setelah ada pengajuan dana yang berstatus Approved.</small>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle font-size-12 mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Pengajuan</th>
                        <th>Minggu</th>
                        <th>Tgl Disetujui</th>
                        <th>Diajukan Oleh</th>
                        <th class="text-end">Total Disetujui</th>
                        <th class="text-end">Sisa Anggaran</th>
                        <th class="text-end text-success">Dibelanjakan</th>
                        <th class="text-center">Status Aktual</th>
                        <th class="text-center">Lampiran</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($approvedRequests as $appReq): 
                        $isNonRab = ($appReq['request_type'] ?? 'rab') === 'non_rab';
                        $isActualized = !empty($appReq['is_actualized']) && !empty($appReq['actual_id']);
                        $reqTotal = floatval($appReq['total_amount']);
                        $rowSisa = $isActualized ? (floatval($appReq['remaining_upah']) + floatval($appReq['remaining_material']) + floatval($appReq['remaining_alat'])) : 0;
                        $rowDibelanjakan = $isNonRab ? $reqTotal : ($isActualized ? max(0, $reqTotal - $rowSisa) : $reqTotal);
                        $canEditThis = ($isAdmin || $canApprove || $appReq['created_by'] == getCurrentUserId());
                    ?>
                    <tr>
                        <td>
                            <a href="<?= $baseUrl ?>/pages/requests/view_request.php?id=<?= $appReq['id'] ?>&return_url=<?= urlencode($_SERVER['REQUEST_URI'] ?? '') ?>" class="text-primary fw-bold text-decoration-none" title="Lihat Detail Pengajuan">
                                <code><?= sanitize($appReq['request_number'] ?: 'REQ-' . $appReq['id']) ?></code>
                            </a>
                            <?php if ($isNonRab): ?>
                            <br><span class="badge text-white font-size-10" style="background-color: #6f42c1;">Biaya Lain-Lain</span>
                            <?php else: ?>
                            <br><span class="badge bg-light text-secondary font-size-10"><?= strtoupper($appReq['work_type'] ?? 'borongan') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">Mgg <?= $appReq['target_week'] ?? $appReq['week_number'] ?? '-' ?></td>
                        <td>
                            <?= !empty($appReq['approved_at']) ? formatDate($appReq['approved_at']) : '-' ?>
                            <br><small class="text-muted">Oleh: <?= sanitize($appReq['approved_by_name'] ?: 'Admin') ?></small>
                        </td>
                        <td><?= sanitize($appReq['created_by_name']) ?></td>
                        <td class="text-end fw-bold"><?= formatRupiah($reqTotal) ?></td>
                        <td class="text-end">
                            <?php if ($isNonRab): ?>
                            <span class="text-muted font-size-11">-</span>
                            <?php elseif ($isActualized): ?>
                                <strong class="<?= $rowSisa > 0 ? 'text-danger' : 'text-muted' ?>"><?= formatRupiah($rowSisa) ?></strong>
                                <?php if ($rowSisa > 0): ?>
                                <br><small class="font-size-10 text-muted">
                                    U: <?= formatRupiah($appReq['remaining_upah'], false) ?> | M: <?= formatRupiah($appReq['remaining_material'], false) ?> | A: <?= formatRupiah($appReq['remaining_alat'], false) ?>
                                </small>
                                <?php endif; ?>
                            <?php else: ?>
                            <span class="badge bg-warning-subtle text-warning">Belum diinput</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end fw-bold text-success font-size-13">
                            <?= formatRupiah($rowDibelanjakan) ?>
                        </td>
                        <td class="text-center">
                            <?php if ($isNonRab): ?>
                            <span class="badge text-white" style="background-color: #6f42c1;"><i class="mdi mdi-check"></i> Langsung</span>
                            <?php elseif ($isActualized): ?>
                            <span class="badge bg-success"><i class="mdi mdi-check-circle"></i> Sudah</span>
                            <?php else: ?>
                            <span class="badge bg-danger"><i class="mdi mdi-clock-outline"></i> Belum</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($appReq['attachment_count'] > 0): ?>
                            <button type="button" class="btn btn-sm btn-outline-info py-0 px-2 font-size-11" onclick="openActualModal(<?= $appReq['id'] ?>, true)" title="Lihat Lampiran">
                                <i class="mdi mdi-paperclip"></i> <?= $appReq['attachment_count'] ?> Nota
                            </button>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="d-flex justify-content-center gap-1">
                                <?php if (!$isNonRab): ?>
                                    <?php if ($canEditThis): ?>
                                    <button type="button" class="btn btn-sm <?= $isActualized ? 'btn-outline-primary' : 'btn-success' ?> py-0 px-2 font-size-11" 
                                            onclick="openActualModal(<?= $appReq['id'] ?>)" 
                                            title="<?= $isActualized ? 'Edit Laporan Aktual' : 'Input Laporan Aktual' ?>">
                                        <i class="mdi <?= $isActualized ? 'mdi-pencil-outline' : 'mdi-plus-box-outline' ?>"></i> 
                                        <?= $isActualized ? 'Edit' : 'Input Aktual' ?>
                                    </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <a href="<?= $baseUrl ?>/pages/requests/view_request.php?id=<?= $appReq['id'] ?>&return_url=<?= urlencode($_SERVER['REQUEST_URI'] ?? '') ?>" class="btn btn-sm btn-outline-dark py-0 px-2" title="Detail Pengajuan">
                                    <i class="mdi mdi-eye"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Input / Edit Aktualisasi -->
<div class="modal fade" id="actualModal" tabindex="-1" aria-labelledby="actualModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-success text-white py-2">
                <h6 class="modal-title fs-6" id="actualModalLabel">
                    <i class="mdi mdi-pencil-box-outline me-1"></i> Form Laporan Aktualisasi
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="actualModalLoading" class="text-center py-4">
                    <div class="spinner-border text-success" role="status"></div>
                    <p class="mt-2 text-muted small">Memuat data pengajuan...</p>
                </div>

                <form id="actualModalForm" enctype="multipart/form-data" style="display: none;">
                    <input type="hidden" name="action" value="save_actualization_tab">
                    <input type="hidden" name="project_id" value="<?= $projectId ?>">
                    <input type="hidden" name="request_id" id="modalReqId" value="">
                    <input type="hidden" name="delete_attachments" id="modalDeleteAttachments" value="[]">
                    <input type="hidden" name="existing_attachments" id="modalExistingAttachments" value="[]">

                    <!-- Summary Info Box -->
                    <div class="card bg-light border mb-3">
                        <div class="card-body py-2">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div>
                                    <strong id="modalReqNumber" class="text-primary font-size-14">-</strong>
                                    <span class="ms-2">|</span>
                                    <span class="ms-2 font-size-12">Minggu ke-<strong id="modalTargetWeek">-</strong></span>
                                    <span class="ms-2">|</span>
                                    <span class="ms-2 font-size-12">Oleh: <strong id="modalCreator">-</strong></span>
                                </div>
                                <div class="text-end">
                                    <span class="text-muted font-size-11 d-block">Total Nilai Pengajuan</span>
                                    <strong id="modalGrandTotal" class="text-dark font-size-14">Rp 0</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Ringkasan Nilai Yang Dibelanjakan vs Sisa -->
                    <div class="alert alert-info py-2 mb-3 d-flex justify-content-between align-items-center">
                        <div>
                            <small class="d-block text-muted">Kalkulasi Netto Yang Dibelanjakan (Realisasi Kas):</small>
                            <h5 class="mb-0 text-success fw-bold" id="modalLiveDibelanjakan">Rp 0</h5>
                        </div>
                        <div class="text-end">
                            <small class="d-block text-muted">Total Sisa Anggaran:</small>
                            <h6 class="mb-0 text-danger fw-bold" id="modalLiveTotalSisa">Rp 0</h6>
                        </div>
                    </div>

                    <!-- Category Remaining Inputs -->
                    <div class="row g-2 mb-3">
                        <!-- Upah -->
                        <div class="col-md-4">
                            <div class="card border-primary h-100 mb-0">
                                <div class="card-header bg-primary bg-opacity-10 py-1 d-flex justify-content-between">
                                    <strong class="text-primary font-size-12"><i class="mdi mdi-account-hard-hat"></i> Upah</strong>
                                    <span class="badge bg-primary font-size-10" id="badgeMaxUpah">Maks: 0</span>
                                </div>
                                <div class="card-body p-2">
                                    <label class="form-label small mb-1">Sisa Anggaran (Rp)</label>
                                    <input type="text" inputmode="numeric" autocomplete="off" class="form-control form-control-sm remaining-input" name="remaining_upah" id="inputSisaUpah" value="0">
                                    <label class="form-label small mb-1 mt-2">Catatan Sisa Upah</label>
                                    <textarea class="form-control form-control-sm" name="notes_upah" id="notesUpah" rows="2" placeholder="Catatan jika ada sisa upah..."></textarea>
                                </div>
                            </div>
                        </div>
                        <!-- Material -->
                        <div class="col-md-4">
                            <div class="card border-success h-100 mb-0">
                                <div class="card-header bg-success bg-opacity-10 py-1 d-flex justify-content-between">
                                    <strong class="text-success font-size-12"><i class="mdi mdi-package-variant"></i> Material</strong>
                                    <span class="badge bg-success font-size-10" id="badgeMaxMaterial">Maks: 0</span>
                                </div>
                                <div class="card-body p-2">
                                    <label class="form-label small mb-1">Sisa Anggaran (Rp)</label>
                                    <input type="text" inputmode="numeric" autocomplete="off" class="form-control form-control-sm remaining-input" name="remaining_material" id="inputSisaMaterial" value="0">
                                    <label class="form-label small mb-1 mt-2">Catatan Sisa Material</label>
                                    <textarea class="form-control form-control-sm" name="notes_material" id="notesMaterial" rows="2" placeholder="Catatan jika ada sisa material..."></textarea>
                                </div>
                            </div>
                        </div>
                        <!-- Alat -->
                        <div class="col-md-4">
                            <div class="card border-warning h-100 mb-0">
                                <div class="card-header bg-warning bg-opacity-10 py-1 d-flex justify-content-between">
                                    <strong class="text-warning text-dark font-size-12"><i class="mdi mdi-tools"></i> Alat</strong>
                                    <span class="badge bg-warning text-dark font-size-10" id="badgeMaxAlat">Maks: 0</span>
                                </div>
                                <div class="card-body p-2">
                                    <label class="form-label small mb-1">Sisa Anggaran (Rp)</label>
                                    <input type="text" inputmode="numeric" autocomplete="off" class="form-control form-control-sm remaining-input" name="remaining_alat" id="inputSisaAlat" value="0">
                                    <label class="form-label small mb-1 mt-2">Catatan Sisa Alat</label>
                                    <textarea class="form-control form-control-sm" name="notes_alat" id="notesAlat" rows="2" placeholder="Catatan jika ada sisa alat..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Lampiran Nota / Bukti Section -->
                    <div class="card border mb-0">
                        <div class="card-header bg-light py-2">
                            <h6 class="mb-0 font-size-13"><i class="mdi mdi-camera text-primary me-1"></i> Lampiran Nota / Bukti Transaksi</h6>
                        </div>
                        <div class="card-body p-3">
                            <div id="modalExistingAttachmentsList" class="row g-2 mb-2"></div>
                            
                            <label class="form-label small fw-bold mb-1">Upload Bukti / Nota Baru (Gambar / PDF, maks 5MB):</label>
                            <input type="file" class="form-control form-control-sm" name="attachments[]" id="modalFileInput" multiple accept="image/jpeg,image/png,image/jpg,image/webp,application/pdf">
                            <small class="text-muted font-size-11">Bisa memilih lebih dari 1 file sekaligus.</small>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer py-2 d-flex justify-content-between">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
                <button type="button" class="btn btn-success btn-sm" id="btnSaveActualModal" onclick="submitActualModalForm()">
                    <i class="mdi mdi-content-save"></i> Simpan Laporan Aktual
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Quick View Lampiran Preview -->
<div class="modal fade" id="actualViewAttModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white py-2">
                <h6 class="modal-title fs-6" id="actualViewAttModalTitle">Preview Dokumen</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-2 bg-light" style="min-height: 250px;">
                <div id="actualViewAttContent"></div>
            </div>
            <div class="modal-footer py-2">
                <a href="#" id="actualViewAttOpenTab" target="_blank" class="btn btn-sm btn-outline-primary">
                    <i class="mdi mdi-open-in-new"></i> Buka Ukuran Penuh
                </a>
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentModalMaxTotals = { upah: 0, material: 0, alat: 0, grand: 0 };
let currentDeletedAttIds = [];

function openActualModal(reqId, viewOnly = false) {
    currentDeletedAttIds = [];
    $('#modalReqId').val(reqId);
    $('#modalDeleteAttachments').val('[]');
    $('#modalExistingAttachments').val('[]');
    $('#modalFileInput').val('');
    $('#actualModalForm').hide();
    $('#actualModalLoading').show();
    $('#btnSaveActualModal').toggle(!viewOnly);

    const modal = new bootstrap.Modal(document.getElementById('actualModal'));
    modal.show();

    $.getJSON('view.php?id=<?= $projectId ?>&ajax=get_actualization_data&request_id=' + reqId, function(res) {
        $('#actualModalLoading').hide();
        if (!res.success) {
            alert(res.message || 'Gagal memuat data pengajuan.');
            modal.hide();
            return;
        }

        $('#actualModalForm').show();
        $('#modalReqNumber').text(res.request.request_number);
        $('#modalTargetWeek').text(res.request.target_week || '-');
        $('#modalCreator').text(res.request.created_by_name || '-');
        
        currentModalMaxTotals = {
            upah: parseFloat(res.totals.upah || 0),
            material: parseFloat(res.totals.material || 0),
            alat: parseFloat(res.totals.alat || 0),
            grand: parseFloat(res.totals.grand_total || 0)
        };

        $('#modalGrandTotal').text('Rp ' + currentModalMaxTotals.grand.toLocaleString('id-ID'));
        $('#badgeMaxUpah').text('Maks: Rp ' + currentModalMaxTotals.upah.toLocaleString('id-ID'));
        $('#badgeMaxMaterial').text('Maks: Rp ' + currentModalMaxTotals.material.toLocaleString('id-ID'));
        $('#badgeMaxAlat').text('Maks: Rp ' + currentModalMaxTotals.alat.toLocaleString('id-ID'));

        $('#inputSisaUpah').attr('data-max', currentModalMaxTotals.upah).prop('disabled', currentModalMaxTotals.upah <= 0);
        $('#inputSisaMaterial').attr('data-max', currentModalMaxTotals.material).prop('disabled', currentModalMaxTotals.material <= 0);
        $('#inputSisaAlat').attr('data-max', currentModalMaxTotals.alat).prop('disabled', currentModalMaxTotals.alat <= 0);

        if (res.actual) {
            $('#inputSisaUpah').val(formatNumber(res.actual.remaining_upah || 0, 0));
            $('#inputSisaMaterial').val(formatNumber(res.actual.remaining_material || 0, 0));
            $('#inputSisaAlat').val(formatNumber(res.actual.remaining_alat || 0, 0));
            $('#notesUpah').val(res.actual.notes_upah || '');
            $('#notesMaterial').val(res.actual.notes_material || '');
            $('#notesAlat').val(res.actual.notes_alat || '');
        } else {
            $('#inputSisaUpah').val('0');
            $('#inputSisaMaterial').val('0');
            $('#inputSisaAlat').val('0');
            $('#notesUpah').val('');
            $('#notesMaterial').val('');
            $('#notesAlat').val('');
        }

        // Render existing attachments
        let attHtml = '';
        if (res.attachments && res.attachments.length > 0) {
            res.attachments.forEach(att => {
                attHtml += `
                <div class="col-md-6" id="attCard_${att.attachment_id}">
                    <div class="border rounded p-2 d-flex align-items-center bg-white">
                        <div class="me-2" style="width: 40px; height: 40px; cursor: pointer;" onclick="previewAttFile('${att.previewUrl}', '${att.originalName}', ${att.isImage})">
                            ${att.isImage ? `<img src="${att.previewUrl}" style="width:40px; height:40px; object-fit:cover;" class="rounded border">` : `<i class="mdi mdi-file-pdf-box text-danger fs-3"></i>`}
                        </div>
                        <div class="flex-grow-1 overflow-hidden">
                            <span class="d-block text-truncate small fw-medium" title="${att.originalName}">${att.originalName}</span>
                            <small class="text-muted font-size-10">${att.fileSizeFormatted}</small>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-info py-0 px-1 me-1" onclick="previewAttFile('${att.previewUrl}', '${att.originalName}', ${att.isImage})"><i class="mdi mdi-eye"></i></button>
                        ${!viewOnly && res.can_edit ? `<button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="stageDeleteAtt(${att.attachment_id})"><i class="mdi mdi-delete"></i></button>` : ''}
                    </div>
                </div>`;
            });
        }
        $('#modalExistingAttachmentsList').html(attHtml || '<div class="col-12"><small class="text-muted">Belum ada nota/lampiran.</small></div>');

        recalculateLiveActualModal();
    }).fail(function() {
        alert('Gagal menghubungi server.');
        modal.hide();
    });
}

function recalculateLiveActualModal() {
    const sUpah = Math.max(0, (typeof parseNumber === 'function' ? parseNumber($('#inputSisaUpah').val()) : parseFloat($('#inputSisaUpah').val().replace(/\./g, ''))) || 0);
    const sMat = Math.max(0, (typeof parseNumber === 'function' ? parseNumber($('#inputSisaMaterial').val()) : parseFloat($('#inputSisaMaterial').val().replace(/\./g, ''))) || 0);
    const sAlat = Math.max(0, (typeof parseNumber === 'function' ? parseNumber($('#inputSisaAlat').val()) : parseFloat($('#inputSisaAlat').val().replace(/\./g, ''))) || 0);
    const totalSisa = sUpah + sMat + sAlat;
    const dibelanjakan = Math.max(0, currentModalMaxTotals.grand - totalSisa);

    $('#modalLiveTotalSisa').text('Rp ' + totalSisa.toLocaleString('id-ID'));
    $('#modalLiveDibelanjakan').text('Rp ' + dibelanjakan.toLocaleString('id-ID'));
}

$(document).on('input', '.remaining-input', function() {
    const maxVal = parseFloat($(this).attr('data-max') || $(this).attr('max')) || 0;
    
    // Live format with thousands dots as user types
    if (typeof formatRupiahInput === 'function') {
        formatRupiahInput(this, false);
    }
    
    let curVal = (typeof parseNumber === 'function' ? parseNumber($(this).val()) : parseFloat($(this).val().replace(/\./g, ''))) || 0;
    if (maxVal > 0 && curVal > maxVal) {
        $(this).val(typeof formatNumber === 'function' ? formatNumber(maxVal, 0) : maxVal.toLocaleString('id-ID'));
    }
    recalculateLiveActualModal();
});

function stageDeleteAtt(attId) {
    if (!confirm('Hapus lampiran ini?')) return;
    currentDeletedAttIds.push(attId);
    $('#modalDeleteAttachments').val(JSON.stringify(currentDeletedAttIds));
    $('#attCard_' + attId).remove();
}

function previewAttFile(url, name, isImage) {
    $('#actualViewAttModalTitle').text(name);
    $('#actualViewAttOpenTab').attr('href', url);
    const content = $('#actualViewAttContent');
    content.empty();
    if (isImage) {
        content.html(`<img src="${url}" class="img-fluid rounded" style="max-height: 70vh;">`);
    } else {
        content.html(`<iframe src="${url}" style="width:100%; height:70vh;" frameborder="0"></iframe>`);
    }
    new bootstrap.Modal(document.getElementById('actualViewAttModal')).show();
}

function submitActualModalForm() {
    const form = document.getElementById('actualModalForm');
    const formData = new FormData(form);
    const btn = $('#btnSaveActualModal');

    // Make sure pure numeric unformatted values are sent
    const cleanUpah = typeof parseNumber === 'function' ? parseNumber($('#inputSisaUpah').val()) : parseFloat($('#inputSisaUpah').val().replace(/\./g, '')) || 0;
    const cleanMaterial = typeof parseNumber === 'function' ? parseNumber($('#inputSisaMaterial').val()) : parseFloat($('#inputSisaMaterial').val().replace(/\./g, '')) || 0;
    const cleanAlat = typeof parseNumber === 'function' ? parseNumber($('#inputSisaAlat').val()) : parseFloat($('#inputSisaAlat').val().replace(/\./g, '')) || 0;
    formData.set('remaining_upah', cleanUpah);
    formData.set('remaining_material', cleanMaterial);
    formData.set('remaining_alat', cleanAlat);

    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Menyimpan...');

    $.ajax({
        url: 'view.php?id=<?= $projectId ?>&tab=actualization',
        type: 'POST',
        data: formData,
        contentType: false,
        processData: false,
        dataType: 'json',
        success: function(res) {
            btn.prop('disabled', false).html('<i class="mdi mdi-content-save"></i> Simpan Laporan Aktual');
            if (res.success) {
                alert(res.message || 'Laporan aktual berhasil disimpan!');
                location.reload();
            } else {
                alert(res.message || 'Gagal menyimpan laporan aktual.');
            }
        },
        error: function(xhr) {
            btn.prop('disabled', false).html('<i class="mdi mdi-content-save"></i> Simpan Laporan Aktual');
            alert('Terjadi kesalahan koneksi server: ' + xhr.responseText);
        }
    });
}
</script>
