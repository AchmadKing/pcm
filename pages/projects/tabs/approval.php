<?php
/**
 * Tab Approval - Project Detail
 * PCC - Project Cost Control System
 * 
 * Separates Pending requests from Completed requests strictly for $projectId.
 * Left Panel: Pending requests awaiting approval.
 * Right Panel Normal: Completed requests history (Approved / Rejected).
 * Right Panel Selected: Full approval detail & action form with Strip/Minimize button.
 */

if (!isset($project) || !isset($projectId)) {
    die('Invalid access');
}

$isAdmin = hasPermission('projects.edit');
$canApproveGlobal = hasPermission('requests.approve');

// 1. Fetch Pending Requests for this project
$pendingRequestsList = dbGetAll("
    SELECT r.*, u.full_name as created_by_name,
           upm.full_name as pm_approved_by_name, upm.role as pm_approved_by_role,
           ua.full_name as approved_by_name, ua.role as approved_by_role,
           (SELECT COALESCE(SUM(CASE WHEN r.request_type = 'non_rab' THEN reqi.quantity * reqi.unit_price ELSE reqi.total_price END), 0) FROM request_items reqi WHERE reqi.request_id = r.id) as total_amount
    FROM requests r
    LEFT JOIN users u ON r.created_by = u.id
    LEFT JOIN users upm ON r.pm_approved_by = upm.id
    LEFT JOIN users ua ON r.approved_by = ua.id
    WHERE r.project_id = ? AND r.status IN ('pending', 'pm_approved')
    ORDER BY r.created_at ASC
", [$projectId]);

// 2. Fetch Completed Requests for this project (Approved / Rejected)
$completedRequestsList = dbGetAll("
    SELECT r.*, u.full_name as created_by_name,
           upm.full_name as pm_approved_by_name, upm.role as pm_approved_by_role,
           ua.full_name as approved_by_name, ua.role as approved_by_role,
           (SELECT COALESCE(SUM(CASE WHEN r.request_type = 'non_rab' THEN reqi.quantity * reqi.unit_price ELSE reqi.total_price END), 0) FROM request_items reqi WHERE reqi.request_id = r.id) as total_amount
    FROM requests r
    LEFT JOIN users u ON r.created_by = u.id
    LEFT JOIN users upm ON r.pm_approved_by = upm.id
    LEFT JOIN users ua ON r.approved_by = ua.id
    WHERE r.project_id = ? AND r.status IN ('approved', 'rejected')
    ORDER BY r.created_at DESC
", [$projectId]);

// 3. Sisa Dana Lapangan Unconsumed
$sisaData = dbGetRow("
    SELECT 
        COALESCE(SUM(
            GREATEST(ra.remaining_upah - ra.consumed_upah, 0) +
            GREATEST(ra.remaining_material - ra.consumed_material, 0) +
            GREATEST(ra.remaining_alat - ra.consumed_alat, 0)
        ), 0) as total_sisa
    FROM request_actuals ra
    JOIN requests r ON ra.request_id = r.id
    WHERE r.project_id = ? AND r.status = 'approved' AND r.is_actualized = 1
", [$projectId]);
$availableSisaLapangan = floatval($sisaData['total_sisa'] ?? 0);

// 4. Check if a pending request is selected
$selectedReqId = intval($_GET['req_id'] ?? 0);
$selectedRequest = null;
$selectedItems = [];
$selectedPekerjaan = [];
$harianSubcatStats = [];
$nonRabCategories = getNonRabCategories();

if ($selectedReqId > 0) {
    $selectedRequest = dbGetRow("
        SELECT req.*, p.name as project_name, p.id as project_id, u.full_name as created_by_name,
               upm.full_name as pm_approved_by_name, upm.role as pm_approved_by_role,
               ua.full_name as approved_by_name, ua.role as approved_by_role,
               (SELECT COALESCE(SUM(CASE WHEN req.request_type = 'non_rab' THEN reqi.quantity * reqi.unit_price ELSE reqi.total_price END), 0) FROM request_items reqi WHERE reqi.request_id = req.id) as total_amount
        FROM requests req
        LEFT JOIN projects p ON req.project_id = p.id
        LEFT JOIN users u ON req.created_by = u.id
        LEFT JOIN users upm ON req.pm_approved_by = upm.id
        LEFT JOIN users ua ON req.approved_by = ua.id
        WHERE req.id = ? AND req.project_id = ? AND req.status IN ('pending', 'pm_approved')
    ", [$selectedReqId, $projectId]);

    if ($selectedRequest) {
        $isNonRabRequest = ($selectedRequest['request_type'] ?? 'rab') === 'non_rab';

        if ($isNonRabRequest) {
            $selectedItems = dbGetAll("
                SELECT reqi.*
                FROM request_items reqi
                WHERE reqi.request_id = ?
                ORDER BY reqi.id ASC
            ", [$selectedReqId]);
        } else {
            // Direct Cost (RAB/RAP) with RAP benchmark queries
            $selectedItems = dbGetAll("
                SELECT reqi.*, rs.code, rs.name as subcategory_name,
                    (SELECT d2.coefficient FROM project_ahsp_details_rap d2 
                     JOIN project_items_rap pir2 ON d2.item_id = pir2.id 
                     JOIN project_ahsp_rap par2 ON d2.ahsp_id = par2.id
                     JOIN project_ahsp pa2 ON par2.ahsp_code = pa2.ahsp_code AND par2.project_id = pa2.project_id
                     JOIN rab_subcategories rs2 ON rs2.ahsp_id = pa2.id
                     WHERE pir2.item_code = reqi.item_code 
                       AND pir2.project_id = ?
                       AND rs2.id = reqi.subcategory_id
                     LIMIT 1) as rap_coefficient,
                    (SELECT COALESCE(d2.unit_price, pir2.price) FROM project_ahsp_details_rap d2 
                     JOIN project_items_rap pir2 ON d2.item_id = pir2.id 
                     JOIN project_ahsp_rap par2 ON d2.ahsp_id = par2.id
                     JOIN project_ahsp pa2 ON par2.ahsp_code = pa2.ahsp_code AND par2.project_id = pa2.project_id
                     JOIN rab_subcategories rs2 ON rs2.ahsp_id = pa2.id
                     WHERE pir2.item_code = reqi.item_code 
                       AND pir2.project_id = ?
                       AND rs2.id = reqi.subcategory_id
                     LIMIT 1) as rap_unit_price,
                    rap.volume as rap_volume,
                    (SELECT COALESCE(d2.coefficient, 0) * COALESCE(rap.volume, 0) 
                     FROM project_ahsp_details_rap d2 
                     JOIN project_items_rap pir2 ON d2.item_id = pir2.id 
                     JOIN project_ahsp_rap par2 ON d2.ahsp_id = par2.id
                     JOIN project_ahsp pa2 ON par2.ahsp_code = pa2.ahsp_code AND par2.project_id = pa2.project_id
                     JOIN rab_subcategories rs2 ON rs2.ahsp_id = pa2.id
                     WHERE pir2.item_code = reqi.item_code 
                       AND pir2.project_id = ?
                       AND rs2.id = reqi.subcategory_id
                     LIMIT 1) as rap_qty,
                    (SELECT COALESCE(SUM(reqi2.coefficient), 0) 
                     FROM request_items reqi2 
                     JOIN requests r ON reqi2.request_id = r.id 
                     WHERE reqi2.item_code = reqi.item_code 
                       AND reqi2.subcategory_id = reqi.subcategory_id
                       AND r.status = 'approved' 
                       AND r.id != req.id) as approved_qty
                FROM request_items reqi
                LEFT JOIN rab_subcategories rs ON reqi.subcategory_id = rs.id
                LEFT JOIN rap_items rap ON rap.subcategory_id = rs.id
                JOIN requests req ON reqi.request_id = req.id
                WHERE reqi.request_id = ?
                ORDER BY rs.code, reqi.item_name
            ", [$projectId, $projectId, $projectId, $selectedReqId]);

            // Distinct subcategories
            $reqItemsForPekerjaan = dbGetAll("SELECT subcat_details, subcategory_id FROM request_items WHERE request_id = ?", [$selectedReqId]);
            $allSubcatIds = [];
            foreach ($reqItemsForPekerjaan as $ri) {
                $sd = json_decode($ri['subcat_details'] ?? '', true);
                if (!empty($sd) && is_array($sd)) {
                    foreach ($sd as $detail) {
                        $allSubcatIds[intval($detail['subcategory_id'])] = true;
                    }
                } elseif (!empty($ri['subcategory_id'])) {
                    $allSubcatIds[intval($ri['subcategory_id'])] = true;
                }
            }
            if (!empty($allSubcatIds)) {
                $ids = array_keys($allSubcatIds);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $selectedPekerjaan = dbGetAll("
                    SELECT DISTINCT rs.id, rs.code, rs.name, rc.code as category_code, rc.name as category_name
                    FROM rab_subcategories rs
                    JOIN rab_categories rc ON rs.category_id = rc.id
                    WHERE rs.id IN ($placeholders)
                    ORDER BY rc.sort_order, rc.code, rs.sort_order, rs.code
                ", $ids);
            }

            // Harian benchmark
            if (($selectedRequest['work_type'] ?? 'borongan') === 'harian') {
                foreach ($selectedItems as $si) {
                    $subId = intval($si['subcategory_id'] ?? 0);
                    $iType = trim($si['item_type'] ?? 'upah');
                    if (!$subId) continue;
                    $key = $subId . '_' . $iType;
                    if (isset($harianSubcatStats[$key])) continue;

                    $scRow = dbGetRow("
                        SELECT rs.id, rs.code, rs.name, rs.unit, COALESCE(ri.volume, rs.volume, 0) as subcat_volume
                        FROM rab_subcategories rs
                        LEFT JOIN rap_items ri ON ri.subcategory_id = rs.id
                        WHERE rs.id = ?
                    ", [$subId]);

                    $rapCostRow = dbGetRow("
                        SELECT SUM(d.coefficient * COALESCE(d.unit_price, pir.price)) as rap_unit_cost
                        FROM rab_subcategories rs
                        JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                        JOIN project_ahsp_rap par ON par.ahsp_code = pa.ahsp_code AND par.project_id = pa.project_id
                        JOIN project_ahsp_details_rap d ON d.ahsp_id = par.id
                        JOIN project_items_rap pir ON d.item_id = pir.id
                        WHERE rs.id = ? AND pir.category = ?
                    ", [$subId, $iType]);

                    $rapUnitCost = floatval($rapCostRow['rap_unit_cost'] ?? 0);
                    $subcatVol = floatval($scRow['subcat_volume'] ?? 0);
                    $rapTotalCost = $rapUnitCost * $subcatVol;

                    $subcatTotalLapangan = 0;
                    $thisReqWorkVol = 0;
                    foreach ($selectedItems as $si2) {
                        if (intval($si2['subcategory_id'] ?? 0) === $subId && ($si2['item_type'] ?? 'upah') === $iType) {
                            $subcatTotalLapangan += floatval($si2['total_price'] ?: ($si2['quantity'] * $si2['unit_price']));
                            if (!empty($si2['work_volume'])) {
                                $thisReqWorkVol = max($thisReqWorkVol, floatval($si2['work_volume']));
                            }
                        }
                    }

                    $harianSubcatStats[$key] = [
                        'subcat_name' => $scRow['code'] . ' ' . $scRow['name'],
                        'unit' => $scRow['unit'],
                        'subcat_volume' => $subcatVol,
                        'rap_unit_cost' => $rapUnitCost,
                        'rap_total_cost' => $rapTotalCost,
                        'this_req_amount' => $subcatTotalLapangan,
                        'this_req_work_vol' => $thisReqWorkVol
                    ];
                }
            }
        }
    }
}

// Generate weekly ranges for target week selector
$weeklyRanges = [];
if (!empty($project['start_date']) && !empty($project['duration_days'])) {
    $weeklyRanges = generateWeeklyRanges($project['start_date'], $project['duration_days']);
}
?>

<div class="row">
    <!-- Left Column: Pending Requests List -->
    <div class="col-lg-4 col-md-5 mb-3">
        <div class="card h-100 mb-0 shadow-sm">
            <div class="card-header py-2 d-flex justify-content-between align-items-center bg-light">
                <h6 class="mb-0 text-dark">
                    <i class="mdi mdi-clock-alert-outline text-warning me-1"></i> Pengajuan Pending
                </h6>
                <span class="badge bg-warning"><?= count($pendingRequestsList) ?></span>
            </div>
            <div class="card-body p-0" style="max-height: 700px; overflow-y: auto;">
                <?php if (empty($pendingRequestsList)): ?>
                <div class="text-center py-5 text-muted px-3">
                    <i class="mdi mdi-check-circle-outline text-success" style="font-size: 3rem;"></i>
                    <p class="mt-2 mb-0 fw-medium">Tidak ada pengajuan pending</p>
                    <small class="text-muted">Semua pengajuan dana untuk proyek ini sudah selesai direview.</small>
                </div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($pendingRequestsList as $pReq): 
                        $isSelected = ($selectedReqId == $pReq['id']);
                        $isNonRab = ($pReq['request_type'] ?? 'rab') === 'non_rab';
                        $isMixed = ($pReq['request_type'] ?? 'rab') === 'mixed';
                        $workType = $pReq['work_type'] ?? 'borongan';
                    ?>
                    <a href="?id=<?= $projectId ?>&tab=approval&req_id=<?= $pReq['id'] ?>" 
                       class="list-group-item list-group-item-action py-3 <?= $isSelected ? 'bg-primary-subtle border-start border-primary border-4' : '' ?>">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <strong class="text-primary"><?= sanitize($pReq['request_number'] ?: 'REQ-' . $pReq['id']) ?></strong>
                            <span class="badge <?= $pReq['status'] === 'pm_approved' ? 'bg-info' : 'bg-warning' ?>">
                                <?= $pReq['status'] === 'pm_approved' ? 'PM Approved' : 'Pending' ?>
                            </span>
                        </div>
                        <div class="d-flex flex-wrap gap-1 mb-2">
                            <?php if ($isMixed): ?>
                            <span class="badge bg-primary-subtle text-primary font-size-10">RAB + Non-RAB</span>
                            <?php elseif ($isNonRab): ?>
                            <span class="badge text-white font-size-10" style="background-color: #6f42c1;">Biaya Lain-Lain</span>
                            <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary font-size-10">RAB</span>
                            <?php endif; ?>

                            <?php if ($workType === 'harian'): ?>
                            <span class="badge bg-warning-subtle text-warning font-size-10">Harian</span>
                            <?php else: ?>
                            <span class="badge bg-info-subtle text-info font-size-10">Borongan</span>
                            <?php endif; ?>
                            
                            <span class="badge bg-light text-dark font-size-10">Mgg <?= $pReq['target_week'] ?? $pReq['week_number'] ?? '-' ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted text-truncate" style="max-width: 140px;">
                                <i class="mdi mdi-account-outline"></i> <?= sanitize($pReq['created_by_name']) ?>
                            </small>
                            <span class="fw-bold text-dark font-size-13"><?= formatRupiah($pReq['total_amount']) ?></span>
                        </div>
                        <small class="text-muted d-block mt-1 font-size-11">
                            <i class="mdi mdi-calendar-outline"></i> <?= formatDateTime($pReq['created_at']) ?>
                        </small>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Dual State (Normal: Completed List | Selected: Detail Pending) -->
    <div class="col-lg-8 col-md-7 mb-3">
        <?php if ($selectedRequest): 
            $isNonRab = ($selectedRequest['request_type'] ?? 'rab') === 'non_rab';
            $isMixed = ($selectedRequest['request_type'] ?? 'rab') === 'mixed';
            $canApproveThis = canApproveRequest($selectedRequest);
            $directItems = [];
            $nonRabItems = [];
            foreach ($selectedItems as $it) {
                if (!empty($it['subcategory_id'])) {
                    $directItems[] = $it;
                } else {
                    $nonRabItems[] = $it;
                }
            }
        ?>
        <!-- STATE B: DETAIL PENGAJUAN PENDING TERPILIH -->
        <div class="card shadow-sm mb-0">
            <div class="card-header py-2 d-flex justify-content-between align-items-center <?= $selectedRequest['status'] === 'pm_approved' ? 'bg-info text-white' : 'bg-warning text-dark' ?>">
                <div class="d-flex align-items-center gap-2">
                    <h6 class="mb-0 fw-bold <?= $selectedRequest['status'] === 'pm_approved' ? 'text-white' : 'text-dark' ?>">
                        <i class="mdi mdi-file-document-edit-outline me-1"></i> Detail: <?= sanitize($selectedRequest['request_number']) ?>
                    </h6>
                    <span class="badge <?= $selectedRequest['status'] === 'pm_approved' ? 'bg-light text-info' : 'bg-dark text-white' ?>">
                        <?= $selectedRequest['status'] === 'pm_approved' ? 'PM Approved (Menunggu Admin)' : 'Pending Review' ?>
                    </span>
                </div>
                <!-- TOMBOL STRIP / MINIMIZE UNTUK KEMBALI KE RIWAYAT NORMAL -->
                <a href="?id=<?= $projectId ?>&tab=approval" class="btn btn-sm btn-light py-1 px-2 fw-medium shadow-sm" title="Tutup Detail dan Kembali ke Riwayat Selesai">
                    Tutup
                </a>
            </div>
            <div class="card-body">
                <!-- Meta Information -->
                <div class="row g-2 mb-3 bg-light p-2 rounded border">
                    <div class="col-md-3 col-6">
                        <small class="text-muted d-block font-size-11">Diajukan Oleh</small>
                        <strong><?= sanitize($selectedRequest['created_by_name']) ?></strong>
                    </div>
                    <div class="col-md-3 col-6">
                        <small class="text-muted d-block font-size-11">Tanggal Pengajuan</small>
                        <span><?= formatDateTime($selectedRequest['created_at'], true) ?></span>
                    </div>
                    <div class="col-md-3 col-6">
                        <small class="text-muted d-block font-size-11">Minggu Target</small>
                        <span class="badge bg-secondary">Minggu Ke-<?= $selectedRequest['target_week'] ?? $selectedRequest['week_number'] ?? '-' ?></span>
                    </div>
                    <div class="col-md-3 col-6">
                        <small class="text-muted d-block font-size-11">Tipe Pengajuan</small>
                        <?php if ($isMixed): ?>
                        <span class="badge bg-primary">RAB + Non-RAB</span>
                        <?php elseif ($isNonRab): ?>
                        <span class="badge text-white" style="background-color: #6f42c1;">Biaya Lain-Lain</span>
                        <?php else: ?>
                        <span class="badge bg-secondary"><?= strtoupper($selectedRequest['work_type'] ?? 'borongan') ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($selectedRequest['description'])): ?>
                    <div class="col-12 mt-2 pt-2 border-top">
                        <small class="text-muted d-block font-size-11">Keterangan Pengajuan</small>
                        <p class="mb-0 font-size-13"><?= nl2br(sanitize($selectedRequest['description'])) ?></p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- PM Verification Note (if any) -->
                <?php if (!empty($selectedRequest['pm_approved_by_name'])): ?>
                <div class="alert alert-info py-2 mb-3">
                    <div class="d-flex align-items-center mb-1">
                        <i class="mdi mdi-account-check fs-5 me-2 text-info"></i>
                        <strong>Diverifikasi oleh PM: <?= sanitize($selectedRequest['pm_approved_by_name']) ?></strong>
                        <span class="ms-auto text-muted small"><?= !empty($selectedRequest['pm_approved_at']) ? formatDateTime($selectedRequest['pm_approved_at'], true) : '' ?></span>
                    </div>
                    <?php if (!empty($selectedRequest['pm_notes'])): ?>
                    <div class="small mt-1 ps-4"><strong>Catatan PM:</strong> <?= nl2br(sanitize($selectedRequest['pm_notes'])) ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Sisa Dana Lapangan Alert (if any) -->
                <?php if ($availableSisaLapangan > 0 && $isAdmin): ?>
                <div class="alert alert-success py-2 mb-3">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <i class="mdi mdi-cash-refund text-success fs-5 me-1"></i>
                            <span class="fw-semibold">Tersedia Sisa Anggaran di Lapangan:</span>
                            <span class="badge bg-success font-size-12 ms-1"><?= formatRupiah($availableSisaLapangan) ?></span>
                        </div>
                        <small class="text-muted">
                            <?php if (!$isNonRab): ?>
                            Akan memotong realisasi pengajuan ini secara otomatis (FIFO)
                            <?php else: ?>
                            Tidak memotong pengajuan ini (Biaya Lain-Lain / Non-RAB)
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Items Breakdown -->
                <?php if ($isNonRab || $isMixed): ?>
                    <?php if (!empty($nonRabItems)): ?>
                    <h6 class="text-primary mb-2"><i class="mdi mdi-receipt"></i> Rincian Biaya Lain-Lain (Non-RAB)</h6>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light font-size-12">
                                <tr>
                                    <th>Item / Uraian</th>
                                    <th>Kategori</th>
                                    <th class="text-center">Satuan</th>
                                    <th class="text-end">Qty</th>
                                    <th class="text-end">Harga Satuan</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $totalNonRab = 0;
                                foreach ($nonRabItems as $nri): 
                                    $subTot = floatval($nri['quantity']) * floatval($nri['unit_price']);
                                    $totalNonRab += $subTot;
                                ?>
                                <tr>
                                    <td><?= sanitize($nri['item_name']) ?></td>
                                    <td><span class="badge bg-light text-dark"><?= $nonRabCategories[$nri['notes'] ?? ''] ?? sanitize($nri['notes'] ?: 'Lainnya') ?></span></td>
                                    <td class="text-center"><?= sanitize($nri['unit']) ?></td>
                                    <td class="text-end"><?= number_format($nri['quantity'], 2, ',', '.') ?></td>
                                    <td class="text-end"><?= formatRupiah($nri['unit_price']) ?></td>
                                    <td class="text-end fw-bold"><?= formatRupiah($subTot) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th colspan="5" class="text-end">Total Non-RAB</th>
                                    <th class="text-end text-primary"><?= formatRupiah($totalNonRab) ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!$isNonRab || $isMixed): ?>
                    <?php if (!empty($directItems)): ?>
                    <h6 class="text-success mb-2"><i class="mdi mdi-hammer-wrench"></i> Rincian Item Pekerjaan (Direct Cost / RAP)</h6>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-bordered mb-0 font-size-12">
                            <thead class="table-light">
                                <tr>
                                    <th>Pekerjaan / Item</th>
                                    <th>Satuan</th>
                                    <th class="text-end bg-light">RAP Qty / Vol</th>
                                    <th class="text-end bg-light">Harga RAP</th>
                                    <th class="text-end text-primary">Qty Pengajuan</th>
                                    <th class="text-end text-primary">Harga Pengajuan</th>
                                    <th class="text-end text-primary">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $totalDirect = 0;
                                foreach ($directItems as $di): 
                                    $diTotal = floatval($di['total_price'] ?: ($di['quantity'] * $di['unit_price']));
                                    $totalDirect += $diTotal;
                                ?>
                                <tr>
                                    <td>
                                        <small class="text-muted d-block"><?= sanitize($di['code'] ? $di['code'] . ' - ' : '') ?><?= sanitize($di['subcategory_name'] ?? 'Pekerjaan') ?></small>
                                        <strong><?= sanitize($di['item_name']) ?></strong>
                                    </td>
                                    <td class="text-center"><?= sanitize($di['unit']) ?></td>
                                    <td class="text-end bg-light"><?= !empty($di['rap_qty']) ? number_format($di['rap_qty'], 2, ',', '.') : '-' ?></td>
                                    <td class="text-end bg-light"><?= !empty($di['rap_unit_price']) ? formatRupiah($di['rap_unit_price']) : '-' ?></td>
                                    <td class="text-end fw-bold text-primary"><?= number_format($di['coefficient'] ?: $di['quantity'], 2, ',', '.') ?></td>
                                    <td class="text-end text-primary"><?= formatRupiah($di['unit_price']) ?></td>
                                    <td class="text-end fw-bold text-primary"><?= formatRupiah($diTotal) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th colspan="6" class="text-end">Total Direct Cost</th>
                                    <th class="text-end text-success"><?= formatRupiah($totalDirect) ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php
                // Calculate authoritative gross total matching the list
                $calculatedGrossTotal = 0;
                if ($isNonRab) {
                    $calculatedGrossTotal = $totalNonRab ?? 0;
                } elseif ($isMixed) {
                    $calculatedGrossTotal = ($totalDirect ?? 0) + ($totalNonRab ?? 0);
                } else {
                    $calculatedGrossTotal = $totalDirect ?? 0;
                }
                if ($calculatedGrossTotal <= 0 && !empty($selectedRequest['total_amount'])) {
                    $calculatedGrossTotal = floatval($selectedRequest['total_amount']);
                }

                // Sisa deduction applies to direct items (pure RAB or mixed direct portion)
                $deductibleAmount = (!$isNonRab) ? ($totalDirect ?? $calculatedGrossTotal) : 0;
                $potonganSisa = ($isAdmin && $availableSisaLapangan > 0) ? min($availableSisaLapangan, $deductibleAmount) : 0;
                $totalNettTransfer = max(0, $calculatedGrossTotal - $potonganSisa);
                $sisaSetelahnya = max(0, $availableSisaLapangan - $potonganSisa);
                ?>

                <!-- Grand Total & Calculation Box -->
                <?php if ($potonganSisa > 0): ?>
                <div class="card border border-warning shadow-sm mb-3">
                    <div class="card-header bg-dark text-white py-2 d-flex justify-content-between align-items-center">
                        <span class="fw-bold"><i class="mdi mdi-calculator me-1 text-warning"></i> RINCIAN PERHITUNGAN NILAI PENGAJUAN</span>
                        <span class="badge bg-warning text-dark font-size-10"><i class="mdi mdi-information-outline"></i> Ada Pemotongan Sisa Lapangan</span>
                    </div>
                    <div class="card-body p-3 bg-light">
                        <!-- 1. Total Pengajuan -->
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <div>
                                <strong class="text-dark font-size-13">Total Nilai Pengajuan</strong>
                                <small class="text-muted d-block font-size-11">
                                    <?php if ($isMixed): ?>
                                    Direct Cost (<?= formatRupiah($totalDirect ?? 0) ?>) + Biaya Lain-Lain (<?= formatRupiah($totalNonRab ?? 0) ?>)
                                    <?php else: ?>
                                    Total akumulasi dari seluruh item yang diajukan pada rincian di atas
                                    <?php endif; ?>
                                </small>
                            </div>
                            <span class="fs-6 fw-bold text-dark"><?= formatRupiah($calculatedGrossTotal) ?></span>
                        </div>

                        <!-- 2. Potongan Sisa Lapangan -->
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <div>
                                <span class="fw-bold text-success font-size-13"><i class="mdi mdi-minus-circle-outline me-1"></i> Potongan Sisa Anggaran Lapangan (FIFO)</span>
                                <small class="text-muted d-block font-size-11">
                                    Dipotong dari total sisa anggaran riil lapangan (<?= formatRupiah($availableSisaLapangan) ?>)
                                </small>
                            </div>
                            <span class="fs-6 fw-bold text-success">- <?= formatRupiah($potonganSisa) ?></span>
                        </div>

                        <!-- 3. Total Nett Ditransfer -->
                        <div class="d-flex justify-content-between align-items-center p-3 mt-2 rounded bg-dark text-white">
                            <div>
                                <span class="fs-6 fw-bold text-warning text-uppercase d-block">TOTAL DANA DITRANSFER (NETT)</span>
                                <small class="text-white-50">Dana kas baru yang perlu dicairkan ke lapangan untuk pengajuan ini</small>
                            </div>
                            <div class="text-end">
                                <span class="fs-4 fw-bold text-warning"><?= formatRupiah($totalNettTransfer) ?></span>
                                <small class="d-block text-white-50 font-size-11 mt-1">
                                    Proyeksi sisa anggaran di lapangan setelah pengajuan ini: <strong class="<?= $sisaSetelahnya > 0 ? 'text-success' : 'text-white' ?>"><?= formatRupiah($sisaSetelahnya) ?></strong>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="d-flex justify-content-between align-items-center p-3 rounded bg-dark text-white mb-3 shadow-sm">
                    <span class="fs-6 fw-bold text-uppercase">TOTAL NILAI PENGAJUAN</span>
                    <span class="fs-4 fw-bold text-warning"><?= formatRupiah($calculatedGrossTotal) ?></span>
                </div>
                <?php endif; ?>

                <!-- Approval / Rejection Action Form -->
                <div class="card border border-primary mt-3">
                    <div class="card-header bg-primary bg-opacity-10 py-2">
                        <h6 class="mb-0 text-primary"><i class="mdi mdi-checkbox-marked-circle-outline"></i> Tindakan Approval</h6>
                    </div>
                    <div class="card-body">
                        <?php if ($canApproveThis): ?>
                        <form method="POST" action="view.php?id=<?= $projectId ?>&tab=approval">
                            <input type="hidden" name="project_id" value="<?= $projectId ?>">
                            <input type="hidden" name="request_id" value="<?= $selectedRequest['id'] ?>">

                            <?php if ($isAdmin && empty($selectedRequest['target_week'])): ?>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Pilih Minggu Target Realisasi <span class="text-danger">*</span></label>
                                <select class="form-select form-select-sm" name="target_week" required>
                                    <option value="">-- Pilih Minggu Target --</option>
                                    <?php foreach ($weeklyRanges as $wr): ?>
                                    <option value="<?= $wr['week_number'] ?>" <?= ($selectedRequest['target_week'] == $wr['week_number']) ? 'selected' : '' ?>>
                                        Minggu Ke-<?= $wr['week_number'] ?> (<?= formatDate($wr['start']) ?> s/d <?= formatDate($wr['end']) ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label small fw-bold">Catatan Approval / Penolakan (Opsional)</label>
                                <textarea class="form-control form-control-sm" name="notes" rows="2" placeholder="Tuliskan catatan atau alasan penolakan..."></textarea>
                            </div>

                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <button type="submit" name="action" value="reject_request_tab" class="btn btn-outline-danger btn-sm" onclick="return confirm('Apakah Anda yakin ingin MENOLAK pengajuan dana ini?');">
                                    <i class="mdi mdi-close-circle"></i> Tolak Pengajuan
                                </button>
                                <div class="d-flex gap-2">
                                    <a href="?id=<?= $projectId ?>&tab=approval" class="btn btn-secondary btn-sm">
                                        Batal
                                    </a>
                                    <?php if ($isAdmin): ?>
                                    <button type="submit" name="action" value="approve_request_tab" class="btn btn-success btn-sm" onclick="return confirm('Apakah Anda yakin ingin MENYETUJUI (Final Approve) pengajuan ini? Realisasi mingguan akan langsung dicatat.');">
                                        <i class="mdi mdi-check-all"></i> Setujui Pengajuan (Admin)
                                    </button>
                                    <?php else: ?>
                                    <button type="submit" name="action" value="approve_request_tab" class="btn btn-info btn-sm text-white" onclick="return confirm('Apakah Anda yakin ingin MENYETUJUI (PM Review) pengajuan ini untuk diteruskan ke Admin?');">
                                        <i class="mdi mdi-check"></i> Setujui (PM Review)
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                        <?php else: ?>
                        <div class="alert alert-secondary mb-0 py-2">
                            <i class="mdi mdi-information text-info me-1"></i>
                            <?php if ($selectedRequest['status'] === 'pm_approved' && !$isAdmin): ?>
                            Pengajuan telah disetujui dalam tahap PM Review dan saat ini <strong>menunggu persetujuan final dari Admin</strong>.
                            <?php else: ?>
                            Anda tidak memiliki wewenang untuk menyetujui pengajuan pada tahap status ini.
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php else: ?>
        <!-- STATE A: NORMAL VIEW (RIWAYAT PENGAJUAN SELESAI / APPROVED & REJECTED) -->
        <div class="card shadow-sm mb-0">
            <div class="card-header py-2 bg-light d-flex justify-content-between align-items-center">
                <h6 class="mb-0 text-dark">
                    <i class="mdi mdi-history text-success me-1"></i> Riwayat Pengajuan Selesai
                </h6>
                <span class="badge bg-secondary"><?= count($completedRequestsList) ?> Selesai</span>
            </div>
            <div class="card-body">
                <?php if ($availableSisaLapangan > 0): ?>
                <div class="alert alert-success py-2 mb-3 d-flex justify-content-between align-items-center">
                    <div>
                        <i class="mdi mdi-cash-multiple text-success fs-5 me-1"></i>
                        <small>Total sisa dana di lapangan yang belum terpakai:</small>
                    </div>
                    <strong class="text-success ms-2"><?= formatRupiah($availableSisaLapangan) ?></strong>
                </div>
                <?php endif; ?>

                <?php if (empty($completedRequestsList)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="mdi mdi-clipboard-text-outline" style="font-size: 3rem;"></i>
                    <p class="mt-2 mb-0">Belum ada pengajuan yang telah disetujui atau ditolak pada proyek ini.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover table-sm font-size-12 mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>No. Pengajuan</th>
                                <th>Tanggal</th>
                                <th class="text-center">Minggu</th>
                                <th>Deskripsi</th>
                                <th class="text-end">Jumlah</th>
                                <th class="text-center">Status</th>
                                <th>Dibuat</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($completedRequestsList as $cReq): 
                                $isNonRab = ($cReq['request_type'] ?? 'rab') === 'non_rab';
                            ?>
                            <tr>
                                <td>
                                    <code><?= sanitize($cReq['request_number'] ?: 'REQ-' . $cReq['id']) ?></code>
                                    <?php if ($isNonRab): ?>
                                    <br><span class="badge text-white font-size-10" style="background-color: #6f42c1;">Biaya Lain-Lain</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= formatDateTime($cReq['created_at']) ?></td>
                                <td class="text-center"><?= $cReq['target_week'] ?? $cReq['week_number'] ?? '-' ?></td>
                                <td><?= sanitize($cReq['description'] ?: '-') ?></td>
                                <td class="text-end fw-bold"><?= formatRupiah($cReq['total_amount']) ?></td>
                                <td class="text-center"><?= getDetailedStatusBadge($cReq) ?></td>
                                <td><?= sanitize($cReq['created_by_name']) ?></td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1">
                                        <a href="<?= $baseUrl ?>/pages/requests/view_request.php?id=<?= $cReq['id'] ?>&return_url=<?= urlencode($_SERVER['REQUEST_URI'] ?? '') ?>" 
                                           class="btn btn-sm btn-outline-info py-0 px-2" title="Lihat Detail">
                                            <i class="mdi mdi-eye"></i>
                                        </a>
                                        <?php if ($cReq['status'] === 'rejected' && hasPermission('requests.create') && $project['status'] === 'on_progress'): ?>
                                        <a href="<?= $baseUrl ?>/pages/requests/create.php?project_id=<?= $projectId ?>&resubmit_id=<?= $cReq['id'] ?>&return_url=<?= urlencode($_SERVER['REQUEST_URI'] ?? '') ?>" 
                                           class="btn btn-sm btn-outline-warning py-0 px-2" title="Ajukan Ulang">
                                            <i class="mdi mdi-refresh"></i>
                                        </a>
                                        <?php endif; ?>
                                        <?php if (hasPermission('requests.delete')): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" title="Hapus Pengajuan" onclick="confirmDeleteRequest(<?= $cReq['id'] ?>, '<?= addslashes(sanitize($cReq['request_number'] ?: 'REQ-' . $cReq['id'])) ?>')">
                                            <i class="mdi mdi-delete"></i>
                                        </button>
                                        <?php endif; ?>
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
        <?php endif; ?>
    </div>
</div>
