<?php
/**
 * Requests List
 * PCM - Project Cost Management System
 */

$pageTitle = 'Daftar Pengajuan';
require_once __DIR__ . '/../../includes/header.php';

$projectFilter = $_GET['project_id'] ?? '';
$statusFilter = $_GET['status'] ?? '';

// Build query based on role
$where = [];
$params = [];

if (!isAdmin()) {
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
        (SELECT COALESCE(SUM(reqi.total_price), 0) FROM request_items reqi WHERE reqi.request_id = req.id) as total_amount
    FROM requests req
    LEFT JOIN projects p ON req.project_id = p.id
    LEFT JOIN users u ON req.created_by = u.id
    $whereClause
    ORDER BY req.created_at DESC
", $params);

// Get projects for filter
if (isAdmin()) {
    $projects = dbGetAll("SELECT id, name FROM projects WHERE status = 'on_progress' ORDER BY name");
} else {
    $projects = dbGetAll("SELECT id, name FROM projects WHERE status = 'on_progress' ORDER BY name");
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
                        <?php if (!isAdmin()): ?>
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
                                <?php if (isAdmin()): ?>
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
                                <td><?= formatDate($req['request_date']) ?></td>
                                <td class="text-end"><?= formatRupiah($req['total_amount']) ?></td>
                                <td><?= getStatusBadge($req['status']) ?></td>
                                <?php if (isAdmin()): ?>
                                <td><?= sanitize($req['created_by_name']) ?></td>
                                <?php endif; ?>
                                <td>
                                    <a href="view_request.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-info btn-action">
                                        <i class="mdi mdi-eye"></i>
                                    </a>
                                    <?php if (isAdmin() && $req['status'] === 'pending'): ?>
                                    <a href="approval.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-warning btn-action">
                                        <i class="mdi mdi-check"></i>
                                    </a>
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
                    <?php if (!isAdmin()): ?>
                    <a href="create.php" class="btn btn-primary">Buat Pengajuan Baru</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
