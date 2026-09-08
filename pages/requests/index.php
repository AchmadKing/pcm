<?php
/**
 * Requests List
 * PCM - Project Cost Management System
 */

// IMPORTANT: Process all logic that may redirect BEFORE including header.php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();

if (!hasPermission('requests.view') && !hasPermission('requests.approve')) {
    setFlash('error', 'Anda tidak memiliki akses ke halaman pengajuan.');
    header('Location: ' . getBaseUrl() . '/index.php');
    exit;
}

// Handle Delete Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_request') {
    if (!hasPermission('requests.delete')) {
        setFlash('error', 'Anda tidak memiliki hak akses untuk menghapus pengajuan!');
        header('Location: index.php');
        exit;
    }
    
    $reqId = intval($_POST['request_id'] ?? 0);
    $req = dbGetRow("SELECT id, request_number, project_id FROM requests WHERE id = ?", [$reqId]);
    if ($req) {
        if (!canAccessProject($req['project_id'])) {
            setFlash('error', 'Anda tidak memiliki akses ke proyek pengajuan ini!');
            header('Location: index.php');
            exit;
        }
        deleteRequest($reqId);
        setFlash('success', 'Pengajuan ' . ($req['request_number'] ?: 'REQ-' . $req['id']) . ' berhasil dihapus.');
    } else {
        setFlash('error', 'Pengajuan tidak ditemukan.');
    }
    header('Location: index.php');
    exit;
}

$pageTitle = 'Daftar Pengajuan';
require_once __DIR__ . '/../../includes/header.php';

$projectFilter = $_GET['project_id'] ?? '';
$statusFilter = $_GET['status'] ?? '';

// Build query based on project access mode and permissions
$where = [];
$params = [];
$reqViewMode = getProjectViewMode();

if ($reqViewMode === 'all') {
    if (!hasPermission('requests.view')) {
        $where[] = "req.created_by = ?";
        $params[] = getCurrentUserId();
    }
} elseif ($reqViewMode === 'assigned') {
    if (hasPermission('requests.view')) {
        $where[] = "(req.project_id IN (SELECT project_id FROM project_assignments WHERE user_id = ? AND is_active = 1) OR req.created_by = ?)";
        $params[] = getCurrentUserId();
        $params[] = getCurrentUserId();
    } else {
        $where[] = "req.created_by = ?";
        $params[] = getCurrentUserId();
    }
} else {
    // No project access: can only see their own requests if any
    $where[] = "req.created_by = ?";
    $params[] = getCurrentUserId();
}

if ($projectFilter) {
    $where[] = "req.project_id = ?";
    $params[] = $projectFilter;
}

if ($statusFilter) {
    $where[] = "req.status = ?";
    $params[] = $statusFilter;
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

$requests = dbGetAll("
    SELECT req.*, p.name as project_name, u.full_name as created_by_name,
        upm.full_name as pm_approved_by_name, upm.role as pm_approved_by_role,
        ua.full_name as approved_by_name, ua.role as approved_by_role,
        (SELECT COALESCE(SUM(reqi.total_price), 0) FROM request_items reqi WHERE reqi.request_id = req.id) as total_amount
    FROM requests req
    LEFT JOIN projects p ON req.project_id = p.id
    LEFT JOIN users u ON req.created_by = u.id
    LEFT JOIN users upm ON req.pm_approved_by = upm.id
    LEFT JOIN users ua ON req.approved_by = ua.id
    $whereClause
    ORDER BY req.created_at DESC
", $params);

// Get projects for filter
if ($reqViewMode === 'all') {
    $projects = dbGetAll("SELECT id, name FROM projects WHERE status = 'on_progress' ORDER BY name");
} elseif ($reqViewMode === 'assigned') {
    $projects = dbGetAll("
        SELECT p.id, p.name 
        FROM projects p 
        JOIN project_assignments pa ON pa.project_id = p.id 
        WHERE p.status = 'on_progress' AND pa.user_id = ? AND pa.is_active = 1 
        ORDER BY p.name
    ", [getCurrentUserId()]);
} else {
    $projects = [];
}
?>

<!-- Page Title -->
<div class="row">
    <div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
            <h4 class="mb-sm-0">Daftar Pengajuan Dana</h4>
            <div class="page-title-right">
                <ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>">PCM</a></li>
                    <li class="breadcrumb-item active">Pengajuan</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Filter -->
<div class="row mb-3">
    <div class="col-12">
        <div class="card">
            <div class="card-body py-2">
                <form method="GET" class="row g-2 align-items-center">
                    <div class="col-md-4">
                        <select class="form-select form-select-sm" name="project_id" onchange="this.form.submit()">
                            <option value="">Semua Proyek</option>
                            <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $projectFilter == $p['id'] ? 'selected' : '' ?>>
                                <?= sanitize($p['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
                            <option value="">Semua Status</option>
                            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="col-md-5 text-end">
                        <?php if (!hasPermission('requests.approve')): ?>
                        <a href="create.php" class="btn btn-primary btn-sm">
                            <i class="mdi mdi-plus"></i> Buat Pengajuan Baru
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Requests Table -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover datatable">
                        <thead>
                            <tr>
                                <th>No. Request</th>
                                <th>Proyek</th>
                                <th>Tanggal</th>
                                <th class="text-end">Total Pengajuan</th>
                                <th>Status</th>
                                <?php if (hasPermission('requests.approve')): ?>
                                <th>Dibuat Oleh</th>
                                <?php endif; ?>
                                <th width="100">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $req): ?>
                            <tr>
                                <td><strong><?= sanitize($req['request_number']) ?></strong></td>
                                <td><?= sanitize($req['project_name']) ?></td>
                                <td><?= formatDateTime($req['created_at']) ?></td>
                                <td class="text-end"><?= formatRupiah($req['total_amount']) ?></td>
                                <td><?= getDetailedStatusBadge($req) ?></td>
                                <?php if (hasPermission('requests.approve')): ?>
                                <td><?= sanitize($req['created_by_name']) ?></td>
                                <?php endif; ?>
                                <td>
                                    <a href="view_request.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-info btn-action" title="Lihat Detail">
                                        <i class="mdi mdi-eye"></i>
                                    </a>
                                    <?php if ($req['status'] === 'rejected' && hasPermission('requests.create') && canAccessProject($req['project_id'])): ?>
                                    <a href="create.php?project_id=<?= $req['project_id'] ?>&resubmit_id=<?= $req['id'] ?>" class="btn btn-sm btn-warning btn-action" title="Ajukan Ulang">
                                        <i class="mdi mdi-refresh"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (hasPermission('requests.approve') && $req['status'] === 'pending'): ?>
                                    <a href="approval.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-warning btn-action" title="Review">
                                        <i class="mdi mdi-check"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (hasPermission('requests.delete')): ?>
                                    <button type="button" class="btn btn-sm btn-danger btn-action" title="Hapus Pengajuan" onclick="confirmDeleteRequest(<?= $req['id'] ?>, '<?= addslashes(sanitize($req['request_number'] ?: 'REQ-' . $req['id'])) ?>')">
                                        <i class="mdi mdi-delete"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (empty($requests)): ?>
                <div class="text-center py-4">
                    <p class="text-muted">Belum ada pengajuan.</p>
                    <?php if (!hasPermission('requests.approve')): ?>
                    <a href="create.php" class="btn btn-primary">Buat Pengajuan Baru</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

<script>
function confirmDeleteRequest(reqId, reqNumber) {
    confirmDelete(function() {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = 'index.php';
        
        var actInput = document.createElement('input');
        actInput.type = 'hidden';
        actInput.name = 'action';
        actInput.value = 'delete_request';
        form.appendChild(actInput);
        
        var idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'request_id';
        idInput.value = reqId;
        form.appendChild(idInput);
        
        document.body.appendChild(form);
        form.submit();
    }, {
        title: 'Hapus Pengajuan Dana',
        message: 'Apakah Anda yakin ingin menghapus pengajuan <strong>' + (reqNumber || ('REQ-' + reqId)) + '</strong>?<br><small class="text-danger">Seluruh rincian item, data aktual, dan lampiran terkait akan ikut terhapus permanen.</small>',
        buttonText: 'Ya, Hapus Pengajuan',
        buttonClass: 'btn-danger'
    });
}
</script>
