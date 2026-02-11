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
    $targetWeek = $_POST['target_week'] ?? null;
    
    try {
        // Validate target_week is required for approval
        if ($action === 'approve' && empty($targetWeek)) {
            setFlash('error', 'Minggu target wajib dipilih sebelum menyetujui pengajuan!');
            header('Location: approval.php?id=' . $reqId);
            exit;
        }
        
        $newStatus = ($action === 'approve') ? 'approved' : 'rejected';
        
        // Update request status with target_week and week_number
        dbExecute("UPDATE requests SET status = ?, admin_notes = ?, target_week = ?, week_number = ?, approved_by = ?, approved_at = NOW() WHERE id = ?",
            [$newStatus, $notes, $action === 'approve' ? $targetWeek : null, $action === 'approve' ? $targetWeek : null, getCurrentUserId(), $reqId]);
        
        // If approved, insert realization into weekly_progress
        if ($action === 'approve') {
            // Get request info for project_id
            $reqInfo = dbGetRow("SELECT project_id FROM requests WHERE id = ?", [$reqId]);
            $projId = $reqInfo['project_id'];
            
            // Get project info for weekly ranges
            $projInfo = dbGetRow("SELECT start_date, duration_days FROM projects WHERE id = ?", [$projId]);
            $weekRanges = generateWeeklyRanges($projInfo['start_date'], $projInfo['duration_days']);
            
            // Find start/end dates for the selected week
            $weekStart = null;
            $weekEnd = null;
            foreach ($weekRanges as $w) {
                if ($w['week_number'] == $targetWeek) {
                    $weekStart = $w['start'];
                    $weekEnd = $w['end'];
                    break;
                }
            }
            
            if ($weekStart && $weekEnd) {
                // Get all items of the approved request
                $approvedItems = dbGetAll("
                    SELECT subcategory_id, SUM(unit_price * coefficient) as total_price
                    FROM request_items 
                    WHERE request_id = ?
                    GROUP BY subcategory_id
                ", [$reqId]);
                
                foreach ($approvedItems as $item) {
                    if (!$item['subcategory_id']) continue;
                    
                    // Insert or update weekly_progress
                    dbExecute("
                        INSERT INTO weekly_progress (project_id, subcategory_id, week_number, week_start, week_end, realization_amount, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE realization_amount = realization_amount + VALUES(realization_amount)
                    ", [$projId, $item['subcategory_id'], $targetWeek, $weekStart, $weekEnd, $item['total_price'], getCurrentUserId()]);
                }
            }
        }
        
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
        $projectId = $selectedRequest['project_id'];
        
        // Get items with RAP comparison - match by item_code from rap_ahsp_details
        // Use subquery to get rap_ahsp_details data to avoid duplicates
        $selectedItems = dbGetAll("
            SELECT reqi.*, rs.code, rs.name as subcategory_name,
                -- RAP data from rap_ahsp_details (matched by item_code within same project)
                (SELECT rad2.coefficient FROM rap_ahsp_details rad2 
                 JOIN project_items pi2 ON rad2.item_id = pi2.id 
                 JOIN rap_items rap2 ON rad2.rap_item_id = rap2.id
                 WHERE pi2.item_code = reqi.item_code 
                   AND pi2.project_id = ?
                   AND rap2.subcategory_id = reqi.subcategory_id
                 LIMIT 1) as rap_coefficient,
                (SELECT rad2.unit_price FROM rap_ahsp_details rad2 
                 JOIN project_items pi2 ON rad2.item_id = pi2.id 
                 JOIN rap_items rap2 ON rad2.rap_item_id = rap2.id
                 WHERE pi2.item_code = reqi.item_code 
                   AND pi2.project_id = ?
                   AND rap2.subcategory_id = reqi.subcategory_id
                 LIMIT 1) as rap_unit_price,
                rap.volume as rap_volume,
                -- Qty per Item RAP = koefisien AHSP × volume pekerjaan
                (SELECT COALESCE(rad2.coefficient, 0) * COALESCE(rap.volume, 0) 
                 FROM rap_ahsp_details rad2 
                 JOIN project_items pi2 ON rad2.item_id = pi2.id 
                 JOIN rap_items rap2 ON rad2.rap_item_id = rap2.id
                 WHERE pi2.item_code = reqi.item_code 
                   AND pi2.project_id = ?
                   AND rap2.subcategory_id = reqi.subcategory_id
                 LIMIT 1) as rap_qty,
                -- Sum approved qty by item_code for this subcategory
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
        ", [$projectId, $projectId, $projectId, $requestId]);
        
        // Get distinct pekerjaan (subcategories) for this request
        $selectedPekerjaan = dbGetAll("
            SELECT DISTINCT rs.id, rs.code, rs.name, rc.code as category_code, rc.name as category_name
            FROM request_items reqi
            JOIN rab_subcategories rs ON reqi.subcategory_id = rs.id
            JOIN rab_categories rc ON rs.category_id = rc.id
            WHERE reqi.request_id = ?
            ORDER BY rc.sort_order, rc.code, rs.sort_order, rs.code
        ", [$requestId]);
    }
}

// Generate weekly ranges for selected request's project
$weeklyRanges = [];
if ($selectedRequest) {
    $projectInfo = dbGetRow("SELECT start_date, duration_days FROM projects WHERE id = ?", [$selectedRequest['project_id']]);
    if (!empty($projectInfo['start_date']) && !empty($projectInfo['duration_days'])) {
        $weeklyRanges = generateWeeklyRanges($projectInfo['start_date'], $projectInfo['duration_days']);
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
                        <p class="mb-0"><?= $selectedRequest['target_week'] ?? $selectedRequest['week_number'] ?? '-' ?></p>
                    </div>
                </div>
                
                <?php if ($selectedRequest['description']): ?>
                <div class="alert alert-light mb-3"><?= sanitize($selectedRequest['description']) ?></div>
                <?php endif; ?>
                
                <?php if (!empty($selectedPekerjaan)): ?>
                <div class="alert alert-info mb-3">
                    <h6 class="alert-heading mb-2"><i class="mdi mdi-briefcase-outline"></i> Pekerjaan yang Diajukan</h6>
                    <ul class="mb-0 ps-3">
                        <?php foreach ($selectedPekerjaan as $pek): ?>
                        <li><strong><?= sanitize($pek['code']) ?></strong> - <?= sanitize($pek['name']) ?> <small class="text-muted">(<?= sanitize($pek['category_code']) ?>. <?= sanitize($pek['category_name']) ?>)</small></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <h6 class="mb-3">Analisis Item Pengajuan</h6>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Kode</th>
                                <th>Uraian</th>
                                <th class="text-end">Koef.</th>
                                <th class="text-end">Harga Satuan</th>
                                <th class="text-end">Total Lapangan</th>
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
                                // Total Lapangan = unit_price × coefficient
                                $hargaLapangan = $item['unit_price'] * $item['coefficient'];
                                $totalReq += $hargaLapangan;
                                
                                // Harga RAP = harga satuan RAP × koefisien yang diajukan
                                $hargaRap = ($item['rap_unit_price'] ?? 0) * $item['coefficient'];
                                $totalRap += $hargaRap;
                                
                                // Qty per Item RAP = koefisien AHSP × volume pekerjaan
                                $qtyRap = $item['rap_qty'] ?? 0;
                                
                                // Remaining qty = qty RAP - approved qty
                                $remainingQty = $qtyRap - ($item['approved_qty'] ?? 0);
                                $isOverQty = $item['coefficient'] > $remainingQty && $qtyRap > 0;
                                $afterApproval = $remainingQty - $item['coefficient'];
                            ?>
                            <tr class="<?= $isOverQty ? 'table-danger' : '' ?>">
                                <td><code><?= sanitize($item['item_code'] ?? $item['code'] ?? '-') ?></code></td>
                                <td>
                                    <?= sanitize($item['item_name']) ?>
                                    <?php if ($item['notes']): ?>
                                    <br><small class="text-muted"><?= sanitize($item['notes']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if (($item['item_type'] ?? '') === 'upah'): ?>
                                        <?= formatNumber($item['coefficient'] / 6, 0) ?> org
                                        <br><small class="text-muted">× 6 hari = <?= formatNumber($item['coefficient'], 4) ?></small>
                                    <?php else: ?>
                                        <?= formatNumber($item['coefficient'], 4) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= formatRupiah($item['unit_price'], false) ?></td>
                                <td class="text-end"><?= formatRupiah($hargaLapangan, false) ?></td>
                                <td class="text-end">
                                    <?= formatRupiah($hargaRap, false) ?>
                                    <?php if ($item['rap_unit_price'] > 0): ?>
                                    <br><small class="text-muted">@<?= formatRupiah($item['rap_unit_price'], false) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= getPriceComparisonLabel($hargaLapangan, $hargaRap) ?>
                                </td>
                                <td>
                                    <?php if ($qtyRap > 0): ?>
                                        <?php if ($isOverQty): ?>
                                        <span class="badge bg-danger">⚠️ OVER QTY</span>
                                        <br><small class="text-danger">Proyeksi sisa: <?= formatNumber($afterApproval, 4) ?></small>
                                        <?php else: ?>
                                        <span class="badge bg-success">OK</span>
                                        <br><small class="text-success">Proyeksi sisa: <?= formatNumber($afterApproval, 4) ?></small>
                                        <?php endif; ?>
                                        <br><small class="text-muted">RAP: <?= formatNumber($qtyRap, 4) ?> | Diajukan: 
                                        <?php if (($item['item_type'] ?? '') === 'upah'): ?>
                                            <?= formatNumber($item['coefficient'] / 6, 0) ?> org
                                        <?php else: ?>
                                            <?= formatNumber($item['coefficient'], 4) ?>
                                        <?php endif; ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">-</span>
                                        <br><small class="text-muted">Tidak ada data RAP</small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-light">
                                <td colspan="4" class="text-end"><strong>Total Pengajuan</strong></td>
                                <td class="text-end"><strong><?= formatRupiah($totalReq) ?></strong></td>
                                <td class="text-end"><strong><?= formatRupiah($totalRap) ?></strong></td>
                                <td colspan="2">
                                    <?php 
                                    $diff = $totalReq - $totalRap;
                                    if ($diff > 0): ?>
                                    <span class="text-danger">+<?= formatRupiah($diff) ?> (LEBIH)</span>
                                    <?php elseif ($diff < 0): ?>
                                    <span class="text-success"><?= formatRupiah($diff) ?> (HEMAT)</span>
                                    <?php else: ?>
                                    <span class="text-muted">= SAMA</span>
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
                        <label class="form-label">Minggu Target <span class="text-danger">*</span></label>
                        <select class="form-select" name="target_week" id="targetWeek">
                            <option value="">-- Pilih Minggu --</option>
                            <?php foreach ($weeklyRanges as $week): ?>
                            <option value="<?= $week['week_number'] ?>">
                                Minggu ke-<?= $week['week_number'] ?> (<?= date('d M', strtotime($week['start'])) ?> - <?= date('d M Y', strtotime($week['end'])) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($weeklyRanges)): ?>
                        <small class="text-warning"><i class="mdi mdi-alert"></i> Proyek belum memiliki tanggal mulai atau durasi, tidak bisa memilih minggu.</small>
                        <?php else: ?>
                        <small class="text-muted">Wajib dipilih sebelum approve. Realisasi akan masuk ke tabel minggu di tab Actual.</small>
                        <?php endif; ?>
                    </div>
                    
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
                        <button type="submit" name="action" value="approve" class="btn btn-success"
                                onclick="return validateApprove()">
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
function validateApprove() {
    var targetWeek = document.getElementById('targetWeek').value;
    if (!targetWeek) {
        alert('Minggu target wajib dipilih sebelum menyetujui pengajuan!');
        document.getElementById('targetWeek').focus();
        return false;
    }
    
    var weekText = document.getElementById('targetWeek').options[document.getElementById('targetWeek').selectedIndex].text;
    
    // Use confirmAction modal
    confirmAction(function() {
        var form = document.getElementById('approvalForm');
        var actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'approve';
        form.appendChild(actionInput);
        form.submit();
    }, {
        title: 'Setujui Pengajuan',
        message: 'Yakin ingin menyetujui pengajuan ini?<br><br>Realisasi akan masuk ke <strong>' + weekText + '</strong> di tab Actual.',
        buttonText: 'Ya, Setujui',
        buttonClass: 'btn-success'
    });
    
    return false;
}

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
    
    return false;
}
</script>
SCRIPT;

require_once __DIR__ . '/../../includes/footer.php'; 
?>
