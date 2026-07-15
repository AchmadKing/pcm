<?php
/**
 * Actualization Report - Laporan Aktual
 * Records remaining budget (sisa anggaran) per approved request
 * PCM - Project Cost Management System
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();

$requestId = intval($_GET['id'] ?? 0);

if (!$requestId) {
    header('Location: index.php');
    exit;
}

// Get request with project info
$request = dbGetRow("
    SELECT req.*, p.name as project_name, p.id as project_id,
           u.full_name as created_by_name
    FROM requests req
    LEFT JOIN projects p ON req.project_id = p.id
    LEFT JOIN users u ON req.created_by = u.id
    WHERE req.id = ? AND req.status = 'approved'
", [$requestId]);

if (!$request) {
    setFlash('error', 'Pengajuan tidak ditemukan atau belum disetujui!');
    header('Location: index.php');
    exit;
}

// Check access - creator or admin can actualize
if (!hasPermission('requests.approve') && $request['created_by'] != getCurrentUserId()) {
    setFlash('error', 'Anda tidak memiliki akses untuk membuat laporan aktual!');
    header('Location: index.php');
    exit;
}

// Get existing actualization data if any
$existingActual = dbGetRow("SELECT * FROM request_actuals WHERE request_id = ?", [$requestId]);
$existingAttachments = [];
if ($existingActual) {
    $existingAttachments = dbGetAll("SELECT * FROM request_actual_attachments WHERE request_actual_id = ? ORDER BY created_at", [$existingActual['id']]);
}

// Get request items grouped by type for summary
$itemsByType = dbGetAll("
    SELECT 
        COALESCE(pi.category, reqi.item_type, 'other') as item_category,
        SUM(reqi.total_price) as total
    FROM request_items reqi
    LEFT JOIN project_items pi ON pi.item_code = reqi.item_code AND pi.project_id = ?
    WHERE reqi.request_id = ?
    GROUP BY item_category
", [$request['project_id'], $requestId]);

$totalUpah = 0;
$totalMaterial = 0;
$totalAlat = 0;
foreach ($itemsByType as $row) {
    $cat = $row['item_category'] ?? '';
    if ($cat === 'upah') $totalUpah = floatval($row['total']);
    elseif ($cat === 'material') $totalMaterial = floatval($row['total']);
    elseif ($cat === 'alat') $totalAlat = floatval($row['total']);
}
$totalPengajuan = $totalUpah + $totalMaterial + $totalAlat;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_actualization') {
    header('Content-Type: application/json');
    
    try {
        $pdo = getDB();
        $pdo->beginTransaction();
        
        $remainingUpah = floatval($_POST['remaining_upah'] ?? 0);
        $remainingMaterial = floatval($_POST['remaining_material'] ?? 0);
        $remainingAlat = floatval($_POST['remaining_alat'] ?? 0);
        $notesUpah = trim($_POST['notes_upah'] ?? '');
        $notesMaterial = trim($_POST['notes_material'] ?? '');
        $notesAlat = trim($_POST['notes_alat'] ?? '');
        
        // Validate - remaining can't exceed pengajuan
        if ($remainingUpah > $totalUpah) {
            throw new Exception("Sisa upah (Rp " . number_format($remainingUpah) . ") tidak boleh melebihi total pengajuan upah (Rp " . number_format($totalUpah) . ")");
        }
        if ($remainingMaterial > $totalMaterial) {
            throw new Exception("Sisa material (Rp " . number_format($remainingMaterial) . ") tidak boleh melebihi total pengajuan material (Rp " . number_format($totalMaterial) . ")");
        }
        if ($remainingAlat > $totalAlat) {
            throw new Exception("Sisa alat (Rp " . number_format($remainingAlat) . ") tidak boleh melebihi total pengajuan alat (Rp " . number_format($totalAlat) . ")");
        }
        
        if ($existingActual) {
            // Update existing
            dbExecute("
                UPDATE request_actuals 
                SET remaining_upah = ?, remaining_material = ?, remaining_alat = ?,
                    notes_upah = ?, notes_material = ?, notes_alat = ?,
                    updated_at = NOW()
                WHERE id = ?
            ", [$remainingUpah, $remainingMaterial, $remainingAlat, $notesUpah, $notesMaterial, $notesAlat, $existingActual['id']]);
            $actualId = $existingActual['id'];
        } else {
            // Insert new
            $actualId = dbInsert("
                INSERT INTO request_actuals (request_id, remaining_upah, remaining_material, remaining_alat, notes_upah, notes_material, notes_alat, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ", [$requestId, $remainingUpah, $remainingMaterial, $remainingAlat, $notesUpah, $notesMaterial, $notesAlat, getCurrentUserId()]);
        }
        
        // Mark request as actualized
        dbExecute("UPDATE requests SET is_actualized = 1 WHERE id = ?", [$requestId]);
        
        // Handle file uploads
        if (!empty($_FILES['attachments']['name'][0])) {
            $uploadDir = __DIR__ . '/../../uploads/actuals/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
            $maxSize = 5 * 1024 * 1024; // 5MB
            
            foreach ($_FILES['attachments']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['attachments']['error'][$key] !== UPLOAD_ERR_OK) continue;
                
                $fileType = $_FILES['attachments']['type'][$key];
                $fileSize = $_FILES['attachments']['size'][$key];
                $originalName = $_FILES['attachments']['name'][$key];
                
                if (!in_array($fileType, $allowedTypes)) continue;
                if ($fileSize > $maxSize) continue;
                
                $ext = pathinfo($originalName, PATHINFO_EXTENSION);
                $filename = 'actual_' . $requestId . '_' . time() . '_' . $key . '.' . $ext;
                $filepath = $uploadDir . $filename;
                
                if (move_uploaded_file($tmpName, $filepath)) {
                    dbInsert("
                        INSERT INTO request_actual_attachments (request_actual_id, filename, original_name, file_type, file_size)
                        VALUES (?, ?, ?, ?, ?)
                    ", [$actualId, $filename, $originalName, $fileType, $fileSize]);
                }
            }
        }
        
        // Handle attachment deletions
        if (!empty($_POST['delete_attachments'])) {
            $deleteIds = json_decode($_POST['delete_attachments'], true);
            if (is_array($deleteIds)) {
                foreach ($deleteIds as $attId) {
                    $att = dbGetRow("SELECT * FROM request_actual_attachments WHERE id = ? AND request_actual_id = ?", [$attId, $actualId]);
                    if ($att) {
                        $filepath = __DIR__ . '/../../uploads/actuals/' . $att['filename'];
                        if (file_exists($filepath)) unlink($filepath);
                        dbExecute("DELETE FROM request_actual_attachments WHERE id = ?", [$attId]);
                    }
                }
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Laporan aktual berhasil disimpan!',
            'redirect' => 'view_request.php?id=' . $requestId
        ]);
        exit;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Delete single attachment via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_attachment') {
    header('Content-Type: application/json');
    $attId = intval($_POST['attachment_id'] ?? 0);
    
    if ($existingActual && $attId > 0) {
        $att = dbGetRow("SELECT * FROM request_actual_attachments WHERE id = ? AND request_actual_id = ?", [$attId, $existingActual['id']]);
        if ($att) {
            $filepath = __DIR__ . '/../../uploads/actuals/' . $att['filename'];
            if (file_exists($filepath)) unlink($filepath);
            dbExecute("DELETE FROM request_actual_attachments WHERE id = ?", [$attId]);
            echo json_encode(['success' => true]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'message' => 'Lampiran tidak ditemukan']);
    exit;
}

$pageTitle = 'Laporan Aktual - ' . $request['request_number'];
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Title -->
<div class="row">
    <div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
            <h4 class="mb-sm-0">
                <i class="mdi mdi-clipboard-check text-success"></i> Laporan Aktual
            </h4>
            <div class="page-title-right">
                <ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>">PCM</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Pengajuan</a></li>
                    <li class="breadcrumb-item"><a href="view_request.php?id=<?= $requestId ?>">Detail</a></li>
                    <li class="breadcrumb-item active">Laporan Aktual</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Request Info Summary -->
<div class="row mb-3">
    <div class="col-12">
        <div class="card bg-light">
            <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <strong><?= sanitize($request['request_number']) ?></strong>
                        <span class="ms-2">|</span>
                        <span class="ms-2">Proyek: <strong><?= sanitize($request['project_name']) ?></strong></span>
                        <span class="ms-2">|</span>
                        <span class="ms-2">Minggu ke-<strong><?= $request['target_week'] ?? $request['week_number'] ?? '-' ?></strong></span>
                        <span class="ms-2">|</span>
                        <span class="ms-2">Oleh: <strong><?= sanitize($request['created_by_name']) ?></strong></span>
                    </div>
                    <a href="view_request.php?id=<?= $requestId ?>" class="btn btn-sm btn-outline-dark">
                        <i class="mdi mdi-arrow-left"></i> Kembali ke Detail
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Left: Ringkasan Pengajuan -->
    <div class="col-lg-4">
        <div class="card border-primary mb-3">
            <div class="card-header bg-primary text-white py-2">
                <h6 class="mb-0"><i class="mdi mdi-file-document-outline"></i> Ringkasan Pengajuan</h6>
            </div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <th class="border-0"><span class="badge bg-primary me-1">Upah</span></th>
                        <td class="text-end border-0"><?= formatRupiah($totalUpah) ?></td>
                    </tr>
                    <tr>
                        <th class="border-0"><span class="badge bg-success me-1">Material</span></th>
                        <td class="text-end border-0"><?= formatRupiah($totalMaterial) ?></td>
                    </tr>
                    <tr>
                        <th class="border-0"><span class="badge bg-warning me-1">Alat</span></th>
                        <td class="text-end border-0"><?= formatRupiah($totalAlat) ?></td>
                    </tr>
                    <tr class="table-primary">
                        <th><strong>Total Pengajuan</strong></th>
                        <td class="text-end"><strong><?= formatRupiah($totalPengajuan) ?></strong></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Info Panel -->
        <div class="card border-info mb-3">
            <div class="card-body py-2">
                <h6 class="text-info mb-2"><i class="mdi mdi-information"></i> Petunjuk</h6>
                <ul class="small mb-0 ps-3">
                    <li>Isi <strong>jumlah sisa anggaran</strong> (dalam Rupiah) yang tidak terpakai</li>
                    <li>Jika semua anggaran terpakai, isi <strong>0</strong></li>
                    <li>Berikan <strong>catatan</strong> untuk menjelaskan alasan sisa</li>
                    <li>Upload <strong>foto nota/bukti transaksi</strong> sebagai lampiran</li>
                </ul>
            </div>
        </div>
    </div>
    
    <!-- Right: Form Aktualisasi -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header py-2 bg-success text-white">
                <h6 class="mb-0">
                    <i class="mdi mdi-pencil-box-outline"></i> 
                    <?= $existingActual ? 'Edit Laporan Aktual' : 'Buat Laporan Aktual' ?>
                </h6>
            </div>
            <div class="card-body">
                <form id="actualizationForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_actualization">
                    
                    <!-- Sisa Upah -->
                    <div class="card border-primary mb-3">
                        <div class="card-header py-2 bg-primary bg-opacity-10">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="mb-0 text-primary">
                                    <i class="mdi mdi-account-hard-hat"></i> Sisa Anggaran Upah
                                </h6>
                                <span class="badge bg-primary">Pengajuan: <?= formatRupiah($totalUpah) ?></span>
                            </div>
                        </div>
                        <div class="card-body py-2">
                            <div class="row">
                                <div class="col-md-5">
                                    <label class="form-label small mb-1">Jumlah Sisa (Rp)</label>
                                    <div class="input-group">
                                        <span class="input-group-text">Rp</span>
                                        <input type="number" class="form-control remaining-input" name="remaining_upah" 
                                               value="<?= $existingActual ? $existingActual['remaining_upah'] : 0 ?>"
                                               min="0" max="<?= $totalUpah ?>" step="1" 
                                               data-max="<?= $totalUpah ?>" data-category="upah"
                                               <?= $totalUpah <= 0 ? 'disabled' : '' ?>>
                                    </div>
                                    <small class="text-muted">Maks: <?= formatRupiah($totalUpah) ?></small>
                                </div>
                                <div class="col-md-7">
                                    <label class="form-label small mb-1">Catatan</label>
                                    <textarea class="form-control" name="notes_upah" rows="2" 
                                              placeholder="Contoh: 2 pekerja tidak masuk 2 hari"><?= sanitize($existingActual['notes_upah'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sisa Material -->
                    <div class="card border-success mb-3">
                        <div class="card-header py-2 bg-success bg-opacity-10">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="mb-0 text-success">
                                    <i class="mdi mdi-package-variant"></i> Sisa Anggaran Material
                                </h6>
                                <span class="badge bg-success">Pengajuan: <?= formatRupiah($totalMaterial) ?></span>
                            </div>
                        </div>
                        <div class="card-body py-2">
                            <div class="row">
                                <div class="col-md-5">
                                    <label class="form-label small mb-1">Jumlah Sisa (Rp)</label>
                                    <div class="input-group">
                                        <span class="input-group-text">Rp</span>
                                        <input type="number" class="form-control remaining-input" name="remaining_material" 
                                               value="<?= $existingActual ? $existingActual['remaining_material'] : 0 ?>"
                                               min="0" max="<?= $totalMaterial ?>" step="1"
                                               data-max="<?= $totalMaterial ?>" data-category="material"
                                               <?= $totalMaterial <= 0 ? 'disabled' : '' ?>>
                                    </div>
                                    <small class="text-muted">Maks: <?= formatRupiah($totalMaterial) ?></small>
                                </div>
                                <div class="col-md-7">
                                    <label class="form-label small mb-1">Catatan</label>
                                    <textarea class="form-control" name="notes_material" rows="2" 
                                              placeholder="Contoh: Semen hanya terpakai 6 dari 10 sak"><?= sanitize($existingActual['notes_material'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sisa Alat -->
                    <div class="card border-warning mb-3">
                        <div class="card-header py-2 bg-warning bg-opacity-10">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="mb-0 text-warning">
                                    <i class="mdi mdi-tools"></i> Sisa Anggaran Alat
                                </h6>
                                <span class="badge bg-warning text-dark">Pengajuan: <?= formatRupiah($totalAlat) ?></span>
                            </div>
                        </div>
                        <div class="card-body py-2">
                            <div class="row">
                                <div class="col-md-5">
                                    <label class="form-label small mb-1">Jumlah Sisa (Rp)</label>
                                    <div class="input-group">
                                        <span class="input-group-text">Rp</span>
                                        <input type="number" class="form-control remaining-input" name="remaining_alat" 
                                               value="<?= $existingActual ? $existingActual['remaining_alat'] : 0 ?>"
                                               min="0" max="<?= $totalAlat ?>" step="1"
                                               data-max="<?= $totalAlat ?>" data-category="alat"
                                               <?= $totalAlat <= 0 ? 'disabled' : '' ?>>
                                    </div>
                                    <small class="text-muted">Maks: <?= formatRupiah($totalAlat) ?></small>
                                </div>
                                <div class="col-md-7">
                                    <label class="form-label small mb-1">Catatan</label>
                                    <textarea class="form-control" name="notes_alat" rows="2" 
                                              placeholder="Contoh: Sewa alat berat hanya 3 hari dari 5 hari"><?= sanitize($existingActual['notes_alat'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Upload Nota -->
                    <div class="card border-secondary mb-3">
                        <div class="card-header py-2 bg-secondary bg-opacity-10">
                            <h6 class="mb-0"><i class="mdi mdi-camera"></i> Lampiran Nota / Bukti Transaksi</h6>
                        </div>
                        <div class="card-body py-2">
                            <?php if (!empty($existingAttachments)): ?>
                            <div class="mb-3">
                                <label class="form-label small mb-1">Lampiran yang sudah diupload:</label>
                                <div class="row g-2" id="existingAttachments">
                                    <?php foreach ($existingAttachments as $att): ?>
                                    <div class="col-auto" id="att-<?= $att['id'] ?>">
                                        <div class="border rounded p-2 d-flex align-items-center gap-2">
                                            <?php if (in_array($att['file_type'], ['image/jpeg', 'image/png', 'image/jpg'])): ?>
                                            <a href="<?= $baseUrl ?>/uploads/actuals/<?= $att['filename'] ?>" target="_blank">
                                                <img src="<?= $baseUrl ?>/uploads/actuals/<?= $att['filename'] ?>" 
                                                     style="height: 50px; width: auto;" class="rounded">
                                            </a>
                                            <?php else: ?>
                                            <a href="<?= $baseUrl ?>/uploads/actuals/<?= $att['filename'] ?>" target="_blank">
                                                <i class="mdi mdi-file-pdf-box text-danger" style="font-size: 2rem;"></i>
                                            </a>
                                            <?php endif; ?>
                                            <div>
                                                <small class="d-block"><?= sanitize($att['original_name']) ?></small>
                                                <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1" 
                                                        onclick="deleteAttachment(<?= $att['id'] ?>)">
                                                    <i class="mdi mdi-delete"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <label class="form-label small mb-1">Upload nota baru (JPG, PNG, PDF, maks 5MB per file):</label>
                            <input type="file" class="form-control" name="attachments[]" multiple 
                                   accept="image/jpeg,image/png,application/pdf">
                            <div id="filePreview" class="mt-2 d-flex gap-2 flex-wrap"></div>
                        </div>
                    </div>
                    
                    <!-- Summary -->
                    <div class="card border-dark mb-3">
                        <div class="card-body py-2">
                            <div class="row text-center">
                                <div class="col-4">
                                    <small class="text-muted d-block">Total Pengajuan</small>
                                    <strong class="text-primary" id="summaryPengajuan"><?= formatRupiah($totalPengajuan) ?></strong>
                                </div>
                                <div class="col-4">
                                    <small class="text-muted d-block">Total Sisa</small>
                                    <strong class="text-danger" id="summarySisa">Rp 0</strong>
                                </div>
                                <div class="col-4">
                                    <small class="text-muted d-block">Pengeluaran Aktual</small>
                                    <strong class="text-success" id="summaryAktual"><?= formatRupiah($totalPengajuan) ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Submit -->
                    <div class="d-flex justify-content-end gap-2">
                        <a href="view_request.php?id=<?= $requestId ?>" class="btn btn-outline-secondary">
                            <i class="mdi mdi-close"></i> Batal
                        </a>
                        <button type="submit" class="btn btn-success" id="btnSubmit">
                            <i class="mdi mdi-check-circle"></i> Simpan Laporan Aktual
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Success Modal -->
<div class="modal fade" id="successModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center py-4">
                <i class="mdi mdi-check-circle text-success" style="font-size: 3rem;"></i>
                <h5 class="mt-2" id="successMessage">Berhasil!</h5>
            </div>
            <div class="modal-footer justify-content-center py-2">
                <a href="view_request.php?id=<?= $requestId ?>" class="btn btn-primary btn-sm">
                    <i class="mdi mdi-eye"></i> Lihat Detail
                </a>
                <a href="../projects/view.php?id=<?= $request['project_id'] ?>&tab=actual" class="btn btn-success btn-sm">
                    <i class="mdi mdi-chart-bar"></i> Tab Actual
                </a>
            </div>
        </div>
    </div>
</div>

<script>
var totalPengajuan = <?= $totalPengajuan ?>;

// Update summary on input change
document.querySelectorAll('.remaining-input').forEach(function(input) {
    input.addEventListener('input', updateSummary);
});

function updateSummary() {
    var sisaUpah = parseFloat(document.querySelector('[name="remaining_upah"]').value) || 0;
    var sisaMaterial = parseFloat(document.querySelector('[name="remaining_material"]').value) || 0;
    var sisaAlat = parseFloat(document.querySelector('[name="remaining_alat"]').value) || 0;
    
    var totalSisa = sisaUpah + sisaMaterial + sisaAlat;
    var totalAktual = totalPengajuan - totalSisa;
    
    document.getElementById('summarySisa').textContent = formatRupiahJS(totalSisa);
    document.getElementById('summaryAktual').textContent = formatRupiahJS(totalAktual);
    
    // Change color if there's remaining
    document.getElementById('summarySisa').className = totalSisa > 0 ? 'text-danger' : 'text-muted';
    document.getElementById('summaryAktual').className = totalAktual < totalPengajuan ? 'text-success' : 'text-primary';
}

function formatRupiahJS(amount) {
    return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(amount));
}

// Initial update
updateSummary();

// Form submission
document.getElementById('actualizationForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    var btn = document.getElementById('btnSubmit');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Menyimpan...';
    
    var formData = new FormData(this);
    
    fetch('actualization.php?id=<?= $requestId ?>', {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            document.getElementById('successMessage').textContent = data.message;
            var modal = new bootstrap.Modal(document.getElementById('successModal'));
            modal.show();
        } else {
            alert('Gagal: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="mdi mdi-check-circle"></i> Simpan Laporan Aktual';
        }
    })
    .catch(function(err) {
        alert('Error: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="mdi mdi-check-circle"></i> Simpan Laporan Aktual';
    });
});

// Delete existing attachment
function deleteAttachment(attId) {
    if (!confirm('Hapus lampiran ini?')) return;
    
    fetch('actualization.php?id=<?= $requestId ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=delete_attachment&attachment_id=' + attId
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            var el = document.getElementById('att-' + attId);
            if (el) el.remove();
        } else {
            alert('Gagal menghapus: ' + (data.message || 'Unknown error'));
        }
    });
}

// File preview
document.querySelector('[name="attachments[]"]').addEventListener('change', function() {
    var preview = document.getElementById('filePreview');
    preview.innerHTML = '';
    
    Array.from(this.files).forEach(function(file) {
        var badge = document.createElement('span');
        badge.className = 'badge bg-secondary';
        badge.textContent = file.name + ' (' + (file.size / 1024).toFixed(0) + ' KB)';
        preview.appendChild(badge);
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
