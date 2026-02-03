<?php
/**
 * Approval Center
 * PCM - Project Cost Management System
 */

// IMPORTANT: Process all logic that may redirect BEFORE including header.php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();
requireAdmin();

$requestId = $_GET['id'] ?? null;
$projectFilter = $_GET['project_id'] ?? '';

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    $reqId = $_POST['request_id'];
    $notes = trim($_POST['notes'] ?? '');
    
    try {
        $newStatus = ($action === 'approve') ? 'approved' : 'rejected';
        
        dbExecute("UPDATE requests SET status = ?, admin_notes = ?, approved_by = ?, approved_at = NOW() WHERE id = ?",
            [$newStatus, $notes, getCurrentUserId(), $reqId]);
        
        $statusText = ($action === 'approve') ? 'disetujui' : 'ditolak';
        setFlash('success', 'Pengajuan berhasil ' . $statusText . '!');
        
    } catch (Exception $e) {
        setFlash('error', 'Terjadi kesalahan: ' . $e->getMessage());
    }
    
    header('Location: approval.php');
    exit;
}

// Get pending requests
$where = "req.status = 'pending'";
$params = [];
if ($projectFilter) {
    $where .= " AND req.project_id = ?";
    $params[] = $projectFilter;
}

$pendingRequests = dbGetAll("
    SELECT req.*, p.name as project_name, u.full_name as created_by_name,
        (SELECT COALESCE(SUM(reqi.total_price), 0) FROM request_items reqi WHERE reqi.request_id = req.id) as total_amount
    FROM requests req
    LEFT JOIN projects p ON req.project_id = p.id
    LEFT JOIN users u ON req.created_by = u.id
    WHERE $where
    ORDER BY req.created_at ASC
", $params);

// Get projects for filter
$projects = dbGetAll("SELECT id, name FROM projects WHERE status = 'on_progress' ORDER BY name");

// If specific request selected, get details
$selectedRequest = null;
$selectedItems = [];
if ($requestId) {
    $selectedRequest = dbGetRow("
        SELECT req.*, p.name as project_name, p.id as project_id, u.full_name as created_by_name
        FROM requests req
        LEFT JOIN projects p ON req.project_id = p.id
        LEFT JOIN users u ON req.created_by = u.id
        WHERE req.id = ? AND req.status = 'pending'
    ", [$requestId]);
    
    if ($selectedRequest) {
        // Get items with RAP comparison
        $selectedItems = dbGetAll("
            SELECT reqi.*, rs.code, rs.name as subcategory_name,
                rap.unit_price as rap_price, rap.volume as rap_volume,
                (SELECT COALESCE(SUM(reqi2.quantity), 0) 
                 FROM request_items reqi2 
                 JOIN requests r ON reqi2.request_id = r.id 
                 WHERE reqi2.subcategory_id = rs.id AND r.status = 'approved' AND r.id != req.id) as approved_qty
            FROM request_items reqi
            LEFT JOIN rab_subcategories rs ON reqi.subcategory_id = rs.id
            LEFT JOIN rap_items rap ON rap.subcategory_id = rs.id
            JOIN requests req ON reqi.request_id = req.id
            WHERE reqi.request_id = ?
            ORDER BY rs.code, reqi.item_name
        ", [$requestId]);
    }
}

// NOW include header (after all possible redirects)
$pageTitle = 'Approval Center';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Title -->
<div class="row">
    <div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
            <h4 class="mb-sm-0">Approval Center</h4>
            <div class="page-title-right">
                <ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>">PCM</a></li>
                    <li class="breadcrumb-item active">Approval</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Pending Requests List -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Pengajuan Pending <span class="badge bg-warning"><?= count($pendingRequests) ?></span></h5>
            </div>
            <div class="card-body p-0">
                <div class="p-2">
                    <select class="form-select form-select-sm" onchange="window.location='?project_id='+this.value">
                        <option value="">Filter Proyek</option>
                        <?php foreach ($projects as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $projectFilter == $p['id'] ? 'selected' : '' ?>>
                            <?= sanitize($p['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if (empty($pendingRequests)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="mdi mdi-check-circle-outline display-4"></i>
                    <p class="mt-2">Tidak ada pengajuan pending</p>
                </div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($pendingRequests as $req): ?>
                    <a href="?id=<?= $req['id'] ?>" 
                       class="list-group-item list-group-item-action <?= $requestId == $req['id'] ? 'active' : '' ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <strong><?= sanitize($req['request_number']) ?></strong>
                                <br><small><?= sanitize($req['project_name']) ?></small>
                                <br><small class="text-muted"><?= formatDate($req['request_date']) ?></small>
                            </div>
                            <span class="badge bg-primary"><?= formatRupiah($req['total_amount'], false) ?></span>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Request Details -->
    <div class="col-lg-8">
        <?php if ($selectedRequest): ?>
        <div class="card">
            <div class="card-header bg-warning">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><?= sanitize($selectedRequest['request_number']) ?></h5>
                    <span class="badge bg-dark"><?= sanitize($selectedRequest['project_name']) ?></span>
                </div>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <small class="text-muted">Dibuat Oleh</small>
                        <p class="mb-0"><strong><?= sanitize($selectedRequest['created_by_name']) ?></strong></p>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted">Tanggal</small>
                        <p class="mb-0"><?= formatDate($selectedRequest['request_date'], true) ?></p>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted">Minggu Ke</small>
                        <p class="mb-0"><?= $selectedRequest['week_number'] ?></p>
                    </div>
                </div>
                
                <?php if ($selectedRequest['description']): ?>
                <div class="alert alert-light mb-3"><?= sanitize($selectedRequest['description']) ?></div>
                <?php endif; ?>
                
                <h6 class="mb-3">Analisis Item Pengajuan</h6>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Kode</th>
                                <th>Uraian</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Harga Lapangan</th>
                                <th class="text-end">Harga RAP</th>
                                <th>Status Harga</th>
                                <th>Status Qty</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $totalReq = 0;
                            $totalRap = 0;
                            foreach ($selectedItems as $item): 
                                $totalReq += $item['total_price'];
                                $totalRap += $item['quantity'] * ($item['rap_price'] ?? 0);
                                
                                // Calculate remaining qty
                                $remainingQty = ($item['rap_volume'] ?? 0) - ($item['approved_qty'] ?? 0);
                                $isOverQty = $item['quantity'] > $remainingQty;
                                $afterApproval = $remainingQty - $item['quantity'];
                            ?>
                            <tr class="<?= $isOverQty ? 'table-danger' : '' ?>">
                                <td><code><?= sanitize($item['code'] ?? '-') ?></code></td>
                                <td>
                                    <?= sanitize($item['item_name']) ?>
                                    <?php if ($item['notes']): ?>
                                    <br><small class="text-muted"><?= sanitize($item['notes']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= formatNumber($item['quantity'], 2) ?></td>
                                <td class="text-end"><?= formatRupiah($item['unit_price'], false) ?></td>
                                <td class="text-end"><?= formatRupiah($item['rap_price'] ?? 0, false) ?></td>
                                <td>
                                    <?= getPriceComparisonLabel($item['unit_price'], $item['rap_price'] ?? 0) ?>
                                </td>
                                <td>
                                    <?php if ($isOverQty): ?>
                                    <span class="badge bg-danger">⚠️ OVER QTY</span>
                                    <br><small>Sisa: <?= formatNumber($remainingQty, 2) ?></small>
                                    <?php else: ?>
                                    <span class="badge bg-success">OK</span>
                                    <br><small>Sisa: <?= formatNumber($afterApproval, 2) ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-light">
                                <td colspan="3" class="text-end"><strong>Total Pengajuan</strong></td>
                                <td class="text-end"><strong><?= formatRupiah($totalReq) ?></strong></td>
                                <td class="text-end"><strong><?= formatRupiah($totalRap) ?></strong></td>
                                <td colspan="2">
                                    <?php 
                                    $diff = $totalReq - $totalRap;
                                    if ($diff > 0): ?>
                                    <span class="text-danger">+<?= formatRupiah($diff) ?> (LEBIH)</span>
                                    <?php else: ?>
                                    <span class="text-success"><?= formatRupiah($diff) ?> (HEMAT)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="card-footer">
                <form method="POST" id="approvalForm">
                    <input type="hidden" name="request_id" value="<?= $selectedRequest['id'] ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Catatan Admin</label>
                        <textarea class="form-control" name="notes" rows="2" id="adminNotes"
                                  placeholder="Catatan untuk tim lapangan (opsional untuk approve, wajib untuk reject)"></textarea>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" name="action" value="reject" class="btn btn-danger" 
                                onclick="return validateReject()">
                            <i class="mdi mdi-close"></i> Reject
                        </button>
                        <button type="submit" name="action" value="approve" class="btn btn-success">
                            <i class="mdi mdi-check"></i> Approve
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php else: ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="mdi mdi-cursor-pointer display-4 text-muted"></i>
                <h5 class="mt-3">Pilih Pengajuan</h5>
                <p class="text-muted">Klik pengajuan di sebelah kiri untuk melihat detail dan melakukan approval.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php 
$extraScripts = <<<'SCRIPT'
<script>
function validateReject() {
    var notes = document.getElementById('adminNotes').value.trim();
    if (!notes) {
        alert('Catatan wajib diisi untuk rejection!');
        document.getElementById('adminNotes').focus();
        return false;
    }
    
    // Use confirmAction modal
    confirmAction(function() {
        var form = document.getElementById('approvalForm');
        var actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'reject';
        form.appendChild(actionInput);
        form.submit();
    }, {
        title: 'Tolak Pengajuan',
        message: 'Yakin ingin menolak pengajuan ini?<br><br>Catatan akan dikirim ke tim lapangan.',
        buttonText: 'Ya, Tolak',
        buttonClass: 'btn-danger'
    });
    
    return false; // Prevent default form submission
}
</script>
SCRIPT;

require_once __DIR__ . '/../../includes/footer.php'; 
?>
