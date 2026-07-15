<?php
/**
 * View Request Details
 * PCM - Project Cost Management System
 */

// IMPORTANT: Process all logic that may redirect BEFORE including header.php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();

$requestId = $_GET['id'] ?? null;

if (!$requestId) {
    header('Location: index.php');
    exit;
}

// Get request with project info
$request = dbGetRow("
    SELECT req.*, p.name as project_name, p.id as project_id,
           u.full_name as created_by_name,
           ua.full_name as approved_by_name,
           upm.full_name as pm_approved_by_name
    FROM requests req
    LEFT JOIN projects p ON req.project_id = p.id
    LEFT JOIN users u ON req.created_by = u.id
    LEFT JOIN users ua ON req.approved_by = ua.id
    LEFT JOIN users upm ON req.pm_approved_by = upm.id
    WHERE req.id = ?
", [$requestId]);

if (!$request) {
    setFlash('error', 'Pengajuan tidak ditemukan!');
    header('Location: index.php');
    exit;
}

// Check access - admin, PM, or creator can view
if (!hasPermission('requests.view') && $request['created_by'] != getCurrentUserId()) {
    setFlash('error', 'Anda tidak memiliki akses ke pengajuan ini!');
    header('Location: index.php');
    exit;
}

// Get request items - uses subcategory_id and item_name from request_items
$items = dbGetAll("
    SELECT reqi.*, rs.code, rs.name as subcategory_name
    FROM request_items reqi
    LEFT JOIN rab_subcategories rs ON reqi.subcategory_id = rs.id
    WHERE reqi.request_id = ?
    ORDER BY rs.code, reqi.item_name
", [$requestId]);

$totalAmount = array_sum(array_column($items, 'total_price'));

// Get request attachments
$requestAttachments = dbGetAll("SELECT * FROM request_attachments WHERE request_id = ? ORDER BY uploaded_at", [$requestId]);

// Get actualization data if exists
$actualData = null;
$actualAttachments = [];
if ($request['status'] === 'approved') {
    $actualData = dbGetRow("SELECT ra.*, u.full_name as created_by_name FROM request_actuals ra LEFT JOIN users u ON ra.created_by = u.id WHERE ra.request_id = ?", [$requestId]);
    if ($actualData) {
        $actualAttachments = dbGetAll("SELECT * FROM request_actual_attachments WHERE request_actual_id = ? ORDER BY created_at", [$actualData['id']]);
    }
}

// Get totals by category for actualization comparison
$itemTotalsByCategory = dbGetAll("
    SELECT 
        COALESCE(pi.category, reqi.item_type, 'other') as item_category,
        SUM(reqi.total_price) as total
    FROM request_items reqi
    LEFT JOIN project_items pi ON pi.item_code = reqi.item_code AND pi.project_id = ?
    WHERE reqi.request_id = ?
    GROUP BY item_category
", [$request['project_id'], $requestId]);

$catTotals = ['upah' => 0, 'material' => 0, 'alat' => 0];
foreach ($itemTotalsByCategory as $row) {
    $c = $row['item_category'] ?? '';
    if (isset($catTotals[$c])) $catTotals[$c] = floatval($row['total']);
}

// Get distinct pekerjaan (subcategories) for this request
// Extract all subcategory IDs from subcat_details JSON (not just subcategory_id column)
$reqItemsForPekerjaan = dbGetAll("SELECT subcat_details, subcategory_id FROM request_items WHERE request_id = ?", [$requestId]);
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
$pekerjaan = [];
if (!empty($allSubcatIds)) {
    $ids = array_keys($allSubcatIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pekerjaan = dbGetAll("
        SELECT DISTINCT rs.id, rs.code, rs.name, rc.code as category_code, rc.name as category_name
        FROM rab_subcategories rs
        JOIN rab_categories rc ON rs.category_id = rc.id
        WHERE rs.id IN ($placeholders)
        ORDER BY rc.sort_order, rc.code, rs.sort_order, rs.code
    ", $ids);
}

// NOW include header (after all possible redirects)
$pageTitle = 'Detail Pengajuan';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Title -->
<div class="row">
    <div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
            <h4 class="mb-sm-0"><?= sanitize($request['request_number']) ?></h4>
            <div class="page-title-right">
                <ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>">PCM</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Pengajuan</a></li>
                    <li class="breadcrumb-item active">Detail</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Status Bar -->
<div class="row mb-3">
    <div class="col-12">
        <div class="card bg-light">
            <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        Status: <?= getStatusBadge($request['status']) ?>
                        <span class="ms-3">Proyek: <strong><?= sanitize($request['project_name']) ?></strong></span>
                    </div>
                    <a href="index.php" class="btn btn-sm btn-outline-dark">
                        <i class="mdi mdi-arrow-left"></i> Kembali
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Request Info -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-body">
                <h5 class="header-title mb-3">Informasi Pengajuan</h5>
                <table class="table table-sm mb-0">
                    <tr><th>No. Request</th><td><?= sanitize($request['request_number']) ?></td></tr>
                    <tr><th>Tanggal Pengajuan</th><td><?= formatDateTime($request['created_at'], true) ?></td></tr>
                    <tr><th>Minggu Ke</th><td><?= $request['target_week'] ?? $request['week_number'] ?? '-' ?></td></tr>
                    <tr><th>Dibuat Oleh</th><td><?= sanitize($request['created_by_name']) ?></td></tr>
                    <?php if ($request['description']): ?>
                    <tr><th>Keterangan</th><td><?= sanitize($request['description']) ?></td></tr>
                    <?php endif; ?>
                </table>
                
                <?php if ($request['status'] !== 'pending'): ?>
                <hr>
                <h6 class="text-muted">Status Approval</h6>
                <table class="table table-sm mb-0">
                    <tr><th>Status</th><td><?= getStatusBadge($request['status']) ?></td></tr>
                    
                    <!-- PM Review Info -->
                    <?php if ($request['pm_approved_by_name']): ?>
                    <tr><th>PM Reviewer</th><td><?= sanitize($request['pm_approved_by_name']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($request['pm_approved_at']): ?>
                    <tr><th>Waktu Review PM</th><td><?= formatDateTime($request['pm_approved_at'], true) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($request['pm_notes']): ?>
                    <tr><th>Catatan PM</th><td><?= sanitize($request['pm_notes']) ?></td></tr>
                    <?php endif; ?>
                    
                    <!-- Admin Final Approval Info -->
                    <?php if ($request['approved_by_name']): ?>
                    <tr><th>Diproses Admin</th><td><?= sanitize($request['approved_by_name']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($request['approved_at']): ?>
                    <tr><th>Waktu Final Approval</th><td><?= formatDateTime($request['approved_at'], true) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($request['admin_notes']): ?>
                    <tr><th>Catatan Admin</th><td><?= sanitize($request['admin_notes']) ?></td></tr>
                    <?php endif; ?>
                    
                    <!-- Rejection Reason if any -->
                    <?php if ($request['rejection_reason']): ?>
                    <tr><th>Alasan Penolakan</th><td><?= sanitize($request['rejection_reason']) ?></td></tr>
                    <?php endif; ?>
                </table>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- PDF Export Card -->
        <div class="card border-danger">
            <div class="card-body">
                <h6 class="header-title mb-3 text-danger"><i class="mdi mdi-file-pdf-box"></i> Ekspor Laporan PDF</h6>
                <form action="export_request_pdf.php" method="GET" target="_blank">
                    <input type="hidden" name="id" value="<?= $requestId ?>">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="include_receipts" value="1" id="includeReceipts" checked>
                        <label class="form-check-label" for="includeReceipts" style="cursor: pointer;">
                            Sertakan Nota / Lampiran
                        </label>
                    </div>
                    <button type="submit" class="btn btn-danger w-100">
                        <i class="mdi mdi-download"></i> Unduh Laporan PDF
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Total Card -->
        <div class="card border-primary">
            <div class="card-body text-center">
                <h6 class="text-muted">Total Pengajuan</h6>
                <h3 class="text-primary"><?= formatRupiah($totalAmount) ?></h3>
            </div>
        </div>
        
        <!-- Pekerjaan Card -->
        <?php if (!empty($pekerjaan)): ?>
        <div class="card border-info">
            <div class="card-body">
                <h6 class="header-title mb-2"><i class="mdi mdi-briefcase-outline"></i> Pekerjaan Terkait</h6>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($pekerjaan as $pek): ?>
                    <li class="mb-1">
                        <code><?= sanitize($pek['code']) ?></code>
                        <?= sanitize($pek['name']) ?>
                        <br><small class="text-muted"><?= sanitize($pek['category_code']) ?>. <?= sanitize($pek['category_name']) ?></small>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <!-- Request Attachments Card -->
        <?php if (!empty($requestAttachments)): ?>
        <div class="card border-secondary">
            <div class="card-body">
                <h6 class="header-title mb-2"><i class="mdi mdi-paperclip"></i> Lampiran Pengajuan</h6>
                <div class="row g-2">
                    <?php foreach ($requestAttachments as $att): ?>
                    <div class="col-auto">
                        <?php if (in_array($att['file_type'], ['image/jpeg', 'image/png', 'image/jpg'])): ?>
                        <a href="<?= $baseUrl ?>/uploads/receipts/<?= $att['filename'] ?>" target="_blank" title="<?= sanitize($att['original_name']) ?>">
                            <img src="<?= $baseUrl ?>/uploads/receipts/<?= $att['filename'] ?>" 
                                 style="height: 60px; width: auto;" class="rounded border">
                        </a>
                        <?php else: ?>
                        <a href="<?= $baseUrl ?>/uploads/receipts/<?= $att['filename'] ?>" target="_blank" class="btn btn-outline-danger btn-sm" title="<?= sanitize($att['original_name']) ?>">
                            <i class="mdi mdi-file-pdf-box text-danger"></i> PDF
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Items List -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body">
                <h5 class="header-title mb-3">Daftar Item</h5>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th width="100">Kode</th>
                                <th>Uraian</th>
                                <th width="60">Satuan</th>
                                <th width="100" class="text-end">Koefisien</th>
                                <th width="130" class="text-end">Harga</th>
                                <th width="150" class="text-end">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                            <tr>
                                <td><code><?= sanitize($item['code'] ?? '-') ?></code></td>
                                <td>
                                    <?= sanitize($item['item_name']) ?>
                                    <?php if ($item['notes']): ?>
                                    <br><small class="text-muted"><?= sanitize($item['notes']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= sanitize($item['unit']) ?></td>
                                <td class="text-end">
                                    <?php if (($item['item_type'] ?? '') === 'upah'): ?>
                                        <?= formatNumber($item['coefficient'] / 6, 0) ?> org
                                        <br><small class="text-muted">× 6 hari = <?= formatNumber($item['coefficient'], 2) ?></small>
                                    <?php else: ?>
                                        <?= formatNumber($item['coefficient'], 4) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= formatRupiah($item['unit_price'], false) ?></td>
                                <td class="text-end"><strong><?= formatRupiah($item['total_price'], false) ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-primary">
                                <td colspan="5" class="text-end"><strong>TOTAL</strong></td>
                                <td class="text-end"><strong><?= formatRupiah($totalAmount, false) ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($request['status'] === 'approved'): ?>
<!-- Actualization Section -->
<div class="row mt-3">
    <div class="col-12">
        <?php if ($actualData): ?>
        <div class="card border-success">
            <div class="card-header bg-success text-white py-2 d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="mdi mdi-clipboard-check"></i> Laporan Aktual</h6>
                <a href="<?= $baseUrl ?>/pages/requests/actualization.php?id=<?= $requestId ?>" class="btn btn-sm btn-light py-0">
                    <i class="mdi mdi-pencil"></i> Edit
                </a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm mb-3">
                        <thead class="table-light">
                            <tr>
                                <th>Kategori</th>
                                <th class="text-end">Pengajuan</th>
                                <th class="text-end">Sisa Anggaran</th>
                                <th class="text-end">Aktual</th>
                                <th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="badge bg-primary">Upah</span></td>
                                <td class="text-end"><?= formatRupiah($catTotals['upah']) ?></td>
                                <td class="text-end text-danger"><?= formatRupiah($actualData['remaining_upah']) ?></td>
                                <td class="text-end text-success"><strong><?= formatRupiah($catTotals['upah'] - $actualData['remaining_upah']) ?></strong></td>
                                <td><small><?= sanitize($actualData['notes_upah'] ?: '-') ?></small></td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-success">Material</span></td>
                                <td class="text-end"><?= formatRupiah($catTotals['material']) ?></td>
                                <td class="text-end text-danger"><?= formatRupiah($actualData['remaining_material']) ?></td>
                                <td class="text-end text-success"><strong><?= formatRupiah($catTotals['material'] - $actualData['remaining_material']) ?></strong></td>
                                <td><small><?= sanitize($actualData['notes_material'] ?: '-') ?></small></td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-warning text-dark">Alat</span></td>
                                <td class="text-end"><?= formatRupiah($catTotals['alat']) ?></td>
                                <td class="text-end text-danger"><?= formatRupiah($actualData['remaining_alat']) ?></td>
                                <td class="text-end text-success"><strong><?= formatRupiah($catTotals['alat'] - $actualData['remaining_alat']) ?></strong></td>
                                <td><small><?= sanitize($actualData['notes_alat'] ?: '-') ?></small></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <?php 
                            $totalRemaining = $actualData['remaining_upah'] + $actualData['remaining_material'] + $actualData['remaining_alat'];
                            $totalAktual = $totalAmount - $totalRemaining;
                            ?>
                            <tr class="table-dark">
                                <th>TOTAL</th>
                                <th class="text-end"><?= formatRupiah($totalAmount) ?></th>
                                <th class="text-end text-danger"><?= formatRupiah($totalRemaining) ?></th>
                                <th class="text-end text-success"><?= formatRupiah($totalAktual) ?></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <?php if (!empty($actualAttachments)): ?>
                <h6 class="mt-3 mb-2"><i class="mdi mdi-camera"></i> Lampiran Nota</h6>
                <div class="row g-2">
                    <?php foreach ($actualAttachments as $att): ?>
                    <div class="col-auto">
                        <?php if (in_array($att['file_type'], ['image/jpeg', 'image/png', 'image/jpg'])): ?>
                        <a href="<?= $baseUrl ?>/uploads/actuals/<?= $att['filename'] ?>" target="_blank">
                            <img src="<?= $baseUrl ?>/uploads/actuals/<?= $att['filename'] ?>" 
                                 style="height: 80px; width: auto;" class="rounded border">
                        </a>
                        <?php else: ?>
                        <a href="<?= $baseUrl ?>/uploads/actuals/<?= $att['filename'] ?>" target="_blank" class="btn btn-outline-danger btn-sm">
                            <i class="mdi mdi-file-pdf-box"></i> <?= sanitize($att['original_name']) ?>
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <div class="mt-2">
                    <small class="text-muted">Diaktualisasi oleh: <?= sanitize($actualData['created_by_name']) ?> pada <?= formatDate($actualData['created_at'], true) ?></small>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card border-warning">
            <div class="card-body py-3 text-center">
                <i class="mdi mdi-alert-circle-outline text-warning" style="font-size: 2rem;"></i>
                <p class="mt-2 mb-2">Pengajuan ini belum memiliki laporan aktual.</p>
                <a href="<?= $baseUrl ?>/pages/requests/actualization.php?id=<?= $requestId ?>" class="btn btn-success btn-sm">
                    <i class="mdi mdi-pencil-box-outline"></i> Buat Laporan Aktual
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
