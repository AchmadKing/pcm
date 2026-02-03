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
           ua.full_name as approved_by_name
    FROM requests req
    LEFT JOIN projects p ON req.project_id = p.id
    LEFT JOIN users u ON req.created_by = u.id
    LEFT JOIN users ua ON req.approved_by = ua.id
    WHERE req.id = ?
", [$requestId]);

if (!$request) {
    setFlash('error', 'Pengajuan tidak ditemukan!');
    header('Location: index.php');
    exit;
}

// Check access
if (!isAdmin() && $request['created_by'] != getCurrentUserId()) {
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
                    <tr><th>Tanggal</th><td><?= formatDate($request['request_date'], true) ?></td></tr>
                    <tr><th>Minggu Ke</th><td><?= $request['week_number'] ?></td></tr>
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
                    <?php if ($request['approved_by_name']): ?>
                    <tr><th>Diproses Oleh</th><td><?= sanitize($request['approved_by_name']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($request['approved_at']): ?>
                    <tr><th>Tanggal</th><td><?= formatDate($request['approved_at'], true) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($request['admin_notes']): ?>
                    <tr><th>Catatan Admin</th><td><?= sanitize($request['admin_notes']) ?></td></tr>
                    <?php endif; ?>
                </table>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Total Card -->
        <div class="card border-primary">
            <div class="card-body text-center">
                <h6 class="text-muted">Total Pengajuan</h6>
                <h3 class="text-primary"><?= formatRupiah($totalAmount) ?></h3>
            </div>
        </div>
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
                                <th width="80" class="text-end">Qty</th>
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
                                <td class="text-end"><?= formatNumber($item['quantity'], 2) ?></td>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
