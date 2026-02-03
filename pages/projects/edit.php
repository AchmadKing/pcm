<?php
/**
 * Edit Project
 * PCM - Project Cost Management System
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();
requireAdmin();

$projectId = $_GET['id'] ?? null;
$error = '';

if (!$projectId) {
    header('Location: index.php');
    exit;
}

// Get project
$project = dbGetRow("SELECT * FROM projects WHERE id = ?", [$projectId]);

if (!$project) {
    setFlash('error', 'Proyek tidak ditemukan!');
    header('Location: index.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $projectCode = trim($_POST['project_code'] ?? '');
    $regionName = trim($_POST['region_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    $activityName = trim($_POST['activity_name'] ?? '');
    $workDescription = trim($_POST['work_description'] ?? '');
    $fundingSource = trim($_POST['funding_source'] ?? '');
    $budgetYear = $_POST['budget_year'] ?? date('Y');
    $contractNumber = trim($_POST['contract_number'] ?? '');
    $contractDate = $_POST['contract_date'] ?: null;
    $serviceProvider = trim($_POST['service_provider'] ?? '');
    $supervisorConsultant = trim($_POST['supervisor_consultant'] ?? '');
    $durationDays = intval($_POST['duration_days'] ?? 0);
    $startDate = $_POST['start_date'] ?: null;
    $overheadPercentage = floatval($_POST['overhead_percentage'] ?? 10);
    
    if (empty($name)) {
        $error = 'Nama proyek harus diisi!';
    } else {
        try {
            dbExecute("
                UPDATE projects SET
                    name = ?, project_code = ?, region_name = ?, description = ?, activity_name = ?, work_description = ?,
                    funding_source = ?, budget_year = ?, contract_number = ?, contract_date = ?,
                    service_provider = ?, supervisor_consultant = ?, duration_days = ?, start_date = ?,
                    overhead_percentage = ?, updated_at = NOW()
                WHERE id = ?
            ", [
                $name, $projectCode, $regionName, $description, $activityName, $workDescription,
                $fundingSource, $budgetYear, $contractNumber, $contractDate,
                $serviceProvider, $supervisorConsultant, $durationDays, $startDate,
                $overheadPercentage, $projectId
            ]);
            
            setFlash('success', 'Proyek berhasil diperbarui!');
            header('Location: view.php?id=' . $projectId);
            exit;
        } catch (Exception $e) {
            $error = 'Terjadi kesalahan: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Proyek';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Title -->
<div class="row">
    <div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
            <h4 class="mb-sm-0">Edit Proyek</h4>
            <div class="page-title-right">
                <ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>">PCM</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Proyek</a></li>
                    <li class="breadcrumb-item active">Edit</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <h4 class="header-title mb-4">Edit: <?= sanitize($project['name']) ?></h4>
                
                <?php if ($error): ?>
                <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="row">
                        <!-- Basic Info -->
                        <div class="col-lg-6">
                            <h5 class="font-size-14 mb-3"><i class="mdi mdi-information-outline"></i> Informasi Dasar</h5>
                            
                            <div class="mb-3">
                                <label for="name" class="form-label required">Nama Proyek</label>
                                <input type="text" class="form-control" id="name" name="name" required
                                       value="<?= sanitize($project['name']) ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="project_code" class="form-label">Kode Proyek</label>
                                <input type="text" class="form-control" id="project_code" name="project_code"
                                       value="<?= sanitize($project['project_code'] ?? '') ?>"
                                       placeholder="Contoh: PRJ-2026-001">
                            </div>
                            
                            <div class="mb-3">
                                <label for="region_name" class="form-label">Wilayah</label>
                                <input type="text" class="form-control" id="region_name" name="region_name"
                                       value="<?= sanitize($project['region_name'] ?? '') ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="activity_name" class="form-label">Nama Kegiatan</label>
                                <input type="text" class="form-control" id="activity_name" name="activity_name"
                                       value="<?= sanitize($project['activity_name'] ?? '') ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="work_description" class="form-label">Pekerjaan</label>
                                <textarea class="form-control" id="work_description" name="work_description" rows="2"><?= sanitize($project['work_description'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Keterangan Tambahan</label>
                                <textarea class="form-control" id="description" name="description" rows="2"><?= sanitize($project['description'] ?? '') ?></textarea>
                            </div>
                        </div>
                        
                        <!-- Contract Info -->
                        <div class="col-lg-6">
                            <h5 class="font-size-14 mb-3"><i class="mdi mdi-file-document-outline"></i> Informasi Kontrak</h5>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="funding_source" class="form-label">Sumber Dana</label>
                                    <input type="text" class="form-control" id="funding_source" name="funding_source"
                                           value="<?= sanitize($project['funding_source'] ?? '') ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="budget_year" class="form-label">Tahun Anggaran</label>
                                    <input type="number" class="form-control" id="budget_year" name="budget_year"
                                           value="<?= $project['budget_year'] ?? date('Y') ?>" min="2020" max="2100">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="contract_number" class="form-label">No. Kontrak</label>
                                    <input type="text" class="form-control" id="contract_number" name="contract_number"
                                           value="<?= sanitize($project['contract_number'] ?? '') ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="contract_date" class="form-label">Tanggal Kontrak</label>
                                    <input type="date" class="form-control" id="contract_date" name="contract_date"
                                           value="<?= $project['contract_date'] ?? '' ?>">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="service_provider" class="form-label">Penyedia Jasa</label>
                                <input type="text" class="form-control" id="service_provider" name="service_provider"
                                       value="<?= sanitize($project['service_provider'] ?? '') ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="supervisor_consultant" class="form-label">Konsultan Pengawas</label>
                                <input type="text" class="form-control" id="supervisor_consultant" name="supervisor_consultant"
                                       value="<?= sanitize($project['supervisor_consultant'] ?? '') ?>">
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="duration_days" class="form-label">Durasi (Hari)</label>
                                    <input type="number" class="form-control" id="duration_days" name="duration_days"
                                           value="<?= $project['duration_days'] ?? 120 ?>" min="1">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="start_date" class="form-label">Tanggal Mulai</label>
                                    <input type="date" class="form-control" id="start_date" name="start_date"
                                           value="<?= $project['start_date'] ?? '' ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="overhead_percentage" class="form-label">Overhead (%)</label>
                                    <input type="number" class="form-control" id="overhead_percentage" name="overhead_percentage"
                                           value="<?= $project['overhead_percentage'] ?? 10 ?>" min="0" max="100" step="0.1">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="mdi mdi-content-save"></i> Simpan Perubahan
                        </button>
                        <a href="view.php?id=<?= $projectId ?>" class="btn btn-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
