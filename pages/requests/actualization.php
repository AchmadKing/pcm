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
$stagedExistingAttachments = [];
if ($existingActual) {
    $existingAttachments = dbGetAll("SELECT * FROM request_actual_attachments WHERE request_actual_id = ? ORDER BY created_at", [$existingActual['id']]);
    $uploadDir = __DIR__ . '/../../uploads/actuals/';
    foreach ($existingAttachments as $att) {
        $filePath = $uploadDir . $att['filename'];
        if (file_exists($filePath)) {
            $ext = strtolower(pathinfo($att['original_name'] ?: $att['filename'], PATHINFO_EXTENSION));
            $nameWithoutExt = pathinfo($att['original_name'] ?: $att['filename'], PATHINFO_FILENAME);
            $isImg = in_array($att['file_type'], ['image/jpeg', 'image/png', 'image/jpg', 'image/webp']) || in_array($ext, ['jpg', 'jpeg', 'png', 'webp']);
            $isPdf = $att['file_type'] === 'application/pdf' || $ext === 'pdf';
            
            $stagedExistingAttachments[] = [
                'id' => 'existing_' . $att['id'],
                'attachment_id' => intval($att['id']),
                'filename' => $att['filename'],
                'customName' => $nameWithoutExt,
                'originalName' => $att['original_name'] ?: $att['filename'],
                'extension' => $ext,
                'fileType' => $att['file_type'],
                'fileSize' => intval($att['file_size']),
                'isImage' => $isImg,
                'isPdf' => $isPdf,
                'previewUrl' => $baseUrl . '/uploads/actuals/' . $att['filename'],
                'isExisting' => true
            ];
        }
    }
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
        
        $uploadDir = __DIR__ . '/../../uploads/actuals/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Handle attachment deletions
        if (!empty($_POST['delete_attachments'])) {
            $deleteIds = json_decode($_POST['delete_attachments'], true);
            if (is_array($deleteIds)) {
                foreach ($deleteIds as $attId) {
                    $att = dbGetRow("SELECT * FROM request_actual_attachments WHERE id = ? AND request_actual_id = ?", [$attId, $actualId]);
                    if ($att) {
                        $filepath = $uploadDir . $att['filename'];
                        if (file_exists($filepath)) @unlink($filepath);
                        dbExecute("DELETE FROM request_actual_attachments WHERE id = ?", [$attId]);
                    }
                }
            }
        }

        // Handle existing attachments rename if any
        if (!empty($_POST['existing_attachments'])) {
            $existingAttList = json_decode($_POST['existing_attachments'], true);
            if (is_array($existingAttList)) {
                foreach ($existingAttList as $exAtt) {
                    $attId = intval($exAtt['attachment_id'] ?? 0);
                    $customName = trim($exAtt['custom_name'] ?? '');
                    if ($attId > 0 && !empty($customName)) {
                        $att = dbGetRow("SELECT * FROM request_actual_attachments WHERE id = ? AND request_actual_id = ?", [$attId, $actualId]);
                        if ($att) {
                            $origExt = strtolower(pathinfo($att['original_name'] ?: $att['filename'], PATHINFO_EXTENSION));
                            $customName = preg_replace('/[\\\\\/:\*\?"<>\|]/', '_', $customName);
                            $customExt = strtolower(pathinfo($customName, PATHINFO_EXTENSION));
                            if ($customExt !== $origExt && !empty($origExt)) {
                                $finalName = $customName . '.' . $origExt;
                            } else {
                                $finalName = $customName;
                            }
                            dbExecute("UPDATE request_actual_attachments SET original_name = ? WHERE id = ?", [$finalName, $attId]);
                        }
                    }
                }
            }
        }

        // Handle new file uploads
        if (!empty($_FILES['attachments']['name'][0])) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp', 'application/pdf'];
            $allowedExts = ['jpeg', 'jpg', 'png', 'webp', 'pdf'];
            $maxSize = 5 * 1024 * 1024; // 5MB
            
            foreach ($_FILES['attachments']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['attachments']['error'][$key] !== UPLOAD_ERR_OK) continue;
                
                $fileType = $_FILES['attachments']['type'][$key];
                $fileSize = $_FILES['attachments']['size'][$key];
                $rawOriginalName = $_FILES['attachments']['name'][$key];
                $origExt = strtolower(pathinfo($rawOriginalName, PATHINFO_EXTENSION));
                
                // Validate extension and type
                if (!in_array($origExt, $allowedExts)) continue;
                if (!in_array($fileType, $allowedTypes)) {
                    if (function_exists('mime_content_type')) {
                        $detectedMime = mime_content_type($tmpName);
                        if (!in_array($detectedMime, $allowedTypes)) continue;
                        $fileType = $detectedMime;
                    }
                }
                if ($fileSize > $maxSize) continue;
                
                // Custom edited name handling
                $customName = isset($_POST['attachment_names'][$key]) ? trim($_POST['attachment_names'][$key]) : '';
                if (!empty($customName)) {
                    $customName = preg_replace('/[\\\\\/:\*\?"<>\|]/', '_', $customName);
                    $customExt = strtolower(pathinfo($customName, PATHINFO_EXTENSION));
                    if ($customExt !== $origExt && !empty($origExt)) {
                        $finalOriginalName = $customName . '.' . $origExt;
                    } else {
                        $finalOriginalName = $customName;
                    }
                } else {
                    $finalOriginalName = $rawOriginalName;
                }
                
                // Generate unique filename
                $filename = 'actual_' . $requestId . '_' . time() . '_' . $key . '_' . bin2hex(random_bytes(4)) . '.' . $origExt;
                $filepath = $uploadDir . $filename;
                
                if (move_uploaded_file($tmpName, $filepath)) {
                    dbInsert("
                        INSERT INTO request_actual_attachments (request_actual_id, filename, original_name, file_type, file_size)
                        VALUES (?, ?, ?, ?, ?)
                    ", [$actualId, $filename, $finalOriginalName, $fileType, $fileSize]);
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
                    
                    <!-- Upload Nota (Multi-file Drag & Drop with Staging) -->
                    <div class="card mb-3 shadow-sm border-info">
                        <div class="card-header bg-info text-white py-2 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 text-white"><i class="mdi mdi-paperclip"></i> Lampiran Nota / Bukti Transaksi</h6>
                            <span id="stagedFilesCountBadge" class="badge bg-light text-dark">0 file dipilih</span>
                        </div>
                        <div class="card-body">
                            <!-- Drag & Drop Zone -->
                            <div class="upload-dropzone p-4 mb-3 text-center border border-2 border-dashed rounded bg-light" id="uploadDropzone" style="cursor: pointer; transition: all 0.2s ease;">
                                <input type="file" id="attachmentInput" accept=".jpg,.jpeg,.png,.webp,.pdf" multiple style="display: none;">
                                <div class="dropzone-content">
                                    <i class="mdi mdi-cloud-upload-outline text-info" style="font-size: 3rem; display: block; line-height: 1;"></i>
                                    <h6 class="mt-2 mb-1">Tarik & Lepaskan File Nota di Sini atau <span class="text-primary text-decoration-underline">Pilih dari Komputer</span></h6>
                                    <p class="text-muted small mb-2">Bisa memilih banyak file sekaligus atau upload berkali-kali tanpa menimpa file sebelumnya.</p>
                                    <div>
                                        <span class="badge bg-soft-primary text-primary me-1"><i class="mdi mdi-image"></i> JPG, PNG, WEBP</span>
                                        <span class="badge bg-soft-danger text-danger me-1"><i class="mdi mdi-file-pdf-box"></i> PDF</span>
                                        <span class="badge bg-soft-secondary text-secondary"><i class="mdi mdi-weight"></i> Maks. 5MB per file</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Staged Files List Section -->
                            <div id="stagedFilesContainer" class="d-none">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="mb-0 text-dark font-weight-bold">
                                        <i class="mdi mdi-file-document-multiple-outline text-info"></i> Daftar File yang Akan Disimpan:
                                    </h6>
                                    <div>
                                        <button type="button" class="btn btn-outline-primary btn-sm me-1" id="btnAddMoreFiles">
                                            <i class="mdi mdi-plus"></i> Tambah File Lain
                                        </button>
                                        <button type="button" class="btn btn-outline-danger btn-sm" id="btnClearAllStaged">
                                            <i class="mdi mdi-trash-can-outline"></i> Hapus Semua
                                        </button>
                                    </div>
                                </div>

                                <div class="table-responsive border rounded">
                                    <table class="table table-hover align-middle mb-0" id="stagedFilesTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="40" class="text-center">#</th>
                                                <th width="80" class="text-center">Preview</th>
                                                <th>Nama File / Keterangan Nota</th>
                                                <th width="110" class="text-center">Ukuran</th>
                                                <th width="100" class="text-center">Format</th>
                                                <th width="130" class="text-center">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody id="stagedFilesList">
                                            <!-- Rendered dynamically -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Empty State for Attachment -->
                            <div id="stagedFilesEmptyState" class="text-center py-2 text-muted small">
                                <i class="mdi mdi-information-outline"></i> Belum ada file nota yang dipilih. (Opsional / dapat dilampirkan)
                            </div>
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

<!-- Modal Preview Gambar / Dokumen Nota -->
<div class="modal fade" id="attachmentPreviewModal" tabindex="-1" aria-labelledby="attachmentPreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white py-2">
                <h5 class="modal-title fs-6 d-flex align-items-center" id="attachmentPreviewModalLabel">
                    <i class="mdi mdi-eye me-2"></i> <span id="previewModalFileName">Preview Dokumen</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-3 bg-light" style="min-height: 250px; display: flex; align-items: center; justify-content: center;">
                <div id="previewModalContent" class="w-100">
                    <!-- Image or PDF container -->
                </div>
            </div>
            <div class="modal-footer py-2 d-flex justify-content-between">
                <span class="text-muted small" id="previewModalFileSize"></span>
                <div>
                    <a href="#" id="previewModalOpenNewTab" target="_blank" class="btn btn-outline-primary btn-sm me-1">
                        <i class="mdi mdi-open-in-new"></i> Buka Tab Baru
                    </a>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Ubah Nama File Nota -->
<div class="modal fade" id="editFileNameModal" tabindex="-1" aria-labelledby="editFileNameModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-2">
                <h5 class="modal-title fs-6" id="editFileNameModalLabel">
                    <i class="mdi mdi-pencil me-1"></i> Edit Nama File Nota
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editFileId">
                <div class="mb-3">
                    <label class="form-label">Nama File / Keterangan Nota <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="editFileNameInput" placeholder="Contoh: Nota Semen Toko ABC">
                        <span class="input-group-text bg-light text-muted" id="editFileExtension">.jpg</span>
                    </div>
                    <small class="text-muted">Beri nama yang jelas untuk memudahkan identifikasi saat verifikasi admin & PM.</small>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnSaveFileName">
                    <i class="mdi mdi-check"></i> Simpan Perubahan
                </button>
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
var existingAttachments = <?= json_encode($stagedExistingAttachments ?? []) ?>;

// Toast notification helper
function showToast(message, type) {
    type = type || 'info';
    var bgMap = {success: '#00b894', error: '#d63031', warning: '#fdcb6e', info: '#0984e3'};
    var bg = bgMap[type] || bgMap.info;
    var textColor = type === 'warning' ? '#333' : '#fff';
    
    $('.pcm-toast').remove();
    var toast = $('<div class="pcm-toast position-fixed d-flex align-items-center px-3 py-2 rounded shadow" ' +
        'style="bottom:20px;right:20px;z-index:9999;min-width:280px;background:' + bg + ';color:' + textColor + ';">' +
        '<span class="me-2 fw-medium">' + message + '</span>' +
        '<button type="button" class="btn-close btn-close-white ms-auto" onclick="$(this).parent().fadeOut(200,function(){$(this).remove();})"></button>' +
        '</div>');
    $('body').append(toast);
    setTimeout(function() { toast.fadeOut(500, function(){ $(this).remove(); }); }, 3500);
}

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

// =====================================
// ATTACHMENT STAGING & MANAGEMENT
// =====================================
let stagedFiles = [];
let deletedAttachmentIds = [];
const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB
const ALLOWED_EXTS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

function getFileExtension(filename) {
    return filename.slice((filename.lastIndexOf(".") - 1 >>> 0) + 2).toLowerCase();
}

function getFileNameWithoutExt(filename) {
    const lastDot = filename.lastIndexOf('.');
    return lastDot !== -1 ? filename.substring(0, lastDot) : filename;
}

function formatBytes(bytes, decimals = 1) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}

function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, "&amp;")
               .replace(/</g, "&lt;")
               .replace(/>/g, "&gt;")
               .replace(/"/g, "&quot;")
               .replace(/'/g, "&#039;");
}

function handleFileSelection(files) {
    if (!files || files.length === 0) return;

    let addedCount = 0;
    let errors = [];

    Array.from(files).forEach(function(file) {
        const ext = getFileExtension(file.name);
        
        if (!ALLOWED_EXTS.includes(ext)) {
            errors.push(`"${file.name}": Format tidak didukung (${ext}). Gunakan JPG, PNG, WEBP, atau PDF.`);
            return;
        }

        if (file.size > MAX_FILE_SIZE) {
            errors.push(`"${file.name}": Ukuran melebihi 5MB (${formatBytes(file.size)}).`);
            return;
        }

        const isDuplicate = stagedFiles.some(f => (f.file && f.file.name === file.name && f.file.size === file.size) || (f.isExisting && (f.customName + '.' + f.extension) === file.name && f.size === file.size));
        if (isDuplicate) {
            errors.push(`"${file.name}": File sudah ada di daftar.`);
            return;
        }

        const id = 'file_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        const isImage = file.type.startsWith('image/') || ['jpg', 'jpeg', 'png', 'webp'].includes(ext);
        const isPdf = file.type === 'application/pdf' || ext === 'pdf';
        const previewUrl = (isImage || isPdf) ? URL.createObjectURL(file) : null;

        stagedFiles.push({
            id: id,
            file: file,
            customName: getFileNameWithoutExt(file.name),
            extension: ext,
            isImage: isImage,
            isPdf: isPdf,
            previewUrl: previewUrl,
            size: file.size,
            isExisting: false
        });

        addedCount++;
    });

    if (errors.length > 0) {
        showToast(errors.join('<br>'), 'warning');
    }

    if (addedCount > 0) {
        showToast(`${addedCount} file nota berhasil ditambahkan.`, 'success');
    }

    $('#attachmentInput').val('');
    renderStagedFiles();
}

function renderStagedFiles() {
    const container = $('#stagedFilesContainer');
    const emptyState = $('#stagedFilesEmptyState');
    const listBody = $('#stagedFilesList');
    const countBadge = $('#stagedFilesCountBadge');

    if (stagedFiles.length === 0) {
        container.addClass('d-none');
        emptyState.removeClass('d-none');
        countBadge.text('0 file dipilih');
        listBody.empty();
        return;
    }

    container.removeClass('d-none');
    emptyState.addClass('d-none');

    const totalSize = stagedFiles.reduce((acc, cur) => acc + cur.size, 0);
    countBadge.text(`${stagedFiles.length} file (${formatBytes(totalSize)})`);

    let html = '';
    stagedFiles.forEach(function(item, index) {
        let previewThumb = '';
        if (item.isImage && item.previewUrl) {
            previewThumb = `
                <div class="position-relative d-inline-block staged-thumb-wrapper" style="width: 48px; height: 48px; cursor: pointer;" onclick="previewStagedFile('${item.id}')" title="Klik untuk preview gambar">
                    <img src="${item.previewUrl}" class="rounded border" style="width: 48px; height: 48px; object-fit: cover;" alt="preview">
                    <div class="thumb-overlay position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center rounded" style="background: rgba(0,0,0,0.35); opacity: 0; transition: opacity 0.2s;">
                        <i class="mdi mdi-eye text-white fs-6"></i>
                    </div>
                </div>
            `;
        } else {
            previewThumb = `
                <div class="d-inline-flex align-items-center justify-content-center rounded bg-soft-danger text-danger border border-danger" style="width: 48px; height: 48px; cursor: pointer;" onclick="previewStagedFile('${item.id}')" title="Klik untuk preview PDF">
                    <i class="mdi mdi-file-pdf-box fs-3"></i>
                </div>
            `;
        }

        const formatBadge = item.isPdf 
            ? '<span class="badge bg-danger">PDF</span>' 
            : `<span class="badge bg-primary">${item.extension.toUpperCase()}</span>`;

        html += `
            <tr id="staged-row-${item.id}">
                <td class="text-center text-muted fw-bold">${index + 1}</td>
                <td class="text-center">${previewThumb}</td>
                <td>
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="flex-grow-1 me-2 text-truncate" style="max-width: 320px;">
                            <span class="fw-semibold text-dark file-display-name" id="name-display-${item.id}" title="${escapeHtml(item.customName)}.${item.extension}">${escapeHtml(item.customName)}</span>
                            <span class="text-muted small">.${item.extension}</span>
                            <div class="text-muted small text-truncate" style="font-size: 0.75rem;">
                                ${item.isExisting ? '<span class="badge bg-soft-info text-info me-1"><i class="mdi mdi-check"></i> Sudah Tersimpan</span>' : ''}Nama asli: <em>${escapeHtml(item.file ? item.file.name : item.originalName)}</em>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-edit-name py-1 px-2 text-nowrap" onclick="openEditFileNameModal('${item.id}')" title="Ubah Nama File">
                            <i class="mdi mdi-pencil"></i> Ubah Nama
                        </button>
                    </div>
                </td>
                <td class="text-center text-muted small">${formatBytes(item.size)}</td>
                <td class="text-center">${formatBadge}</td>
                <td class="text-center">
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-primary" onclick="previewStagedFile('${item.id}')" title="Preview">
                            <i class="mdi mdi-eye"></i>
                        </button>
                        <button type="button" class="btn btn-outline-danger" onclick="deleteStagedFile('${item.id}')" title="Hapus">
                            <i class="mdi mdi-trash-can-outline"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    });

    listBody.html(html);

    $('.staged-thumb-wrapper').hover(
        function() { $(this).find('.thumb-overlay').css('opacity', '1'); },
        function() { $(this).find('.thumb-overlay').css('opacity', '0'); }
    );
}

window.deleteStagedFile = function(id) {
    const fileIndex = stagedFiles.findIndex(f => f.id === id);
    if (fileIndex !== -1) {
        const item = stagedFiles[fileIndex];
        if (item.isExisting && item.attachment_id) {
            deletedAttachmentIds.push(item.attachment_id);
        }
        if (item.previewUrl && !item.isExisting) {
            URL.revokeObjectURL(item.previewUrl);
        }
        stagedFiles.splice(fileIndex, 1);
        renderStagedFiles();
        showToast('File lampiran dihapus dari daftar.', 'info');
    }
};

window.openEditFileNameModal = function(id) {
    const item = stagedFiles.find(f => f.id === id);
    if (!item) return;

    $('#editFileId').val(item.id);
    $('#editFileNameInput').val(item.customName);
    $('#editFileExtension').text('.' + item.extension);
    
    const modal = new bootstrap.Modal(document.getElementById('editFileNameModal'));
    modal.show();
    
    setTimeout(() => {
        $('#editFileNameInput').focus().select();
    }, 500);
};

$('#btnSaveFileName').click(function() {
    const id = $('#editFileId').val();
    const newName = $('#editFileNameInput').val().trim();
    
    if (!newName) {
        showToast('Nama file tidak boleh kosong!', 'warning');
        $('#editFileNameInput').focus();
        return;
    }

    const item = stagedFiles.find(f => f.id === id);
    if (item) {
        item.customName = newName;
        renderStagedFiles();
        bootstrap.Modal.getInstance(document.getElementById('editFileNameModal')).hide();
        showToast('Nama file nota diperbarui.', 'success');
    }
});

$('#editFileNameInput').on('keypress', function(e) {
    if (e.which === 13) {
        e.preventDefault();
        $('#btnSaveFileName').click();
    }
});

window.previewStagedFile = function(id) {
    const item = stagedFiles.find(f => f.id === id);
    if (!item) return;

    $('#previewModalFileName').text(item.customName + '.' + item.extension);
    $('#previewModalFileSize').text(`Ukuran: ${formatBytes(item.size)} | Format: ${item.extension.toUpperCase()}`);
    $('#previewModalOpenNewTab').attr('href', item.previewUrl);

    const container = $('#previewModalContent');
    if (item.isImage) {
        container.html(`
            <div class="text-center">
                <img src="${item.previewUrl}" class="img-fluid rounded shadow-sm" style="max-height: 70vh; object-fit: contain;" alt="${escapeHtml(item.customName)}">
            </div>
        `);
    } else if (item.isPdf) {
        container.html(`
            <div class="py-4 text-center">
                <i class="mdi mdi-file-pdf-box text-danger" style="font-size: 5rem;"></i>
                <h5 class="mt-3">${escapeHtml(item.customName)}.${item.extension}</h5>
                <p class="text-muted">Dokumen PDF (${formatBytes(item.size)})</p>
                <a href="${item.previewUrl}" target="_blank" class="btn btn-danger btn-sm">
                    <i class="mdi mdi-open-in-new"></i> Buka Dokumen PDF di Tab Baru
                </a>
            </div>
        `);
    }

    const modal = new bootstrap.Modal(document.getElementById('attachmentPreviewModal'));
    modal.show();
};

$('#btnClearAllStaged').click(function() {
    if (stagedFiles.length === 0) return;
    if (!confirm('Apakah Anda yakin ingin menghapus semua file nota?')) return;

    stagedFiles.forEach(item => {
        if (item.isExisting && item.attachment_id) {
            deletedAttachmentIds.push(item.attachment_id);
        }
        if (item.previewUrl && !item.isExisting) URL.revokeObjectURL(item.previewUrl);
    });
    stagedFiles = [];
    renderStagedFiles();
    showToast('Semua file nota telah dihapus.', 'info');
});

$('#uploadDropzone, #btnAddMoreFiles').click(function(e) {
    if (e.target.id !== 'attachmentInput') {
        $('#attachmentInput').trigger('click');
    }
});

$('#attachmentInput').on('change', function() {
    handleFileSelection(this.files);
});

const dropzone = document.getElementById('uploadDropzone');
if (dropzone) {
    ['dragenter', 'dragover'].forEach(eventName => {
        dropzone.addEventListener(eventName, function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('border-primary bg-soft-primary').removeClass('bg-light');
        }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropzone.addEventListener(eventName, function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('border-primary bg-soft-primary').addClass('bg-light');
        }, false);
    });

    dropzone.addEventListener('drop', function(e) {
        const dt = e.dataTransfer;
        if (dt && dt.files && dt.files.length > 0) {
            handleFileSelection(dt.files);
        }
    }, false);
}

// Load existing attachments on page load
if (existingAttachments && existingAttachments.length > 0) {
    existingAttachments.forEach(function(att) {
        stagedFiles.push({
            id: att.id,
            attachment_id: att.attachment_id,
            file: null,
            originalName: att.originalName,
            customName: att.customName,
            extension: att.extension,
            isImage: att.isImage,
            isPdf: att.isPdf,
            previewUrl: att.previewUrl,
            size: att.fileSize,
            isExisting: true
        });
    });
    renderStagedFiles();
}

// Form submit handler
$('#actualizationForm').on('submit', function(e) {
    e.preventDefault();
    
    var btn = $('#btnSubmit');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Menyimpan...');
    
    var formData = new FormData();
    formData.append('action', 'save_actualization');
    formData.append('remaining_upah', $('[name="remaining_upah"]').val());
    formData.append('remaining_material', $('[name="remaining_material"]').val());
    formData.append('remaining_alat', $('[name="remaining_alat"]').val());
    formData.append('notes_upah', $('[name="notes_upah"]').val());
    formData.append('notes_material', $('[name="notes_material"]').val());
    formData.append('notes_alat', $('[name="notes_alat"]').val());
    
    if (stagedFiles && stagedFiles.length > 0) {
        const existingAtts = [];
        stagedFiles.forEach(function(item) {
            if (item.isExisting && item.attachment_id) {
                existingAtts.push({
                    attachment_id: item.attachment_id,
                    custom_name: item.customName
                });
            } else if (item.file) {
                formData.append('attachments[]', item.file);
                formData.append('attachment_names[]', item.customName);
            }
        });
        if (existingAtts.length > 0) {
            formData.append('existing_attachments', JSON.stringify(existingAtts));
        }
    }
    
    if (deletedAttachmentIds && deletedAttachmentIds.length > 0) {
        formData.append('delete_attachments', JSON.stringify(deletedAttachmentIds));
    }
    
    $.ajax({
        url: 'actualization.php?id=<?= $requestId ?>',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(data) {
            if (data.success) {
                $('#successMessage').text(data.message);
                var modal = new bootstrap.Modal(document.getElementById('successModal'));
                modal.show();
            } else {
                showToast(data.message || 'Terjadi kesalahan', 'error');
                btn.prop('disabled', false).html('<i class="mdi mdi-check-circle"></i> Simpan Laporan Aktual');
            }
        },
        error: function(xhr, status, error) {
            console.error('Submit error:', status, error, xhr.responseText);
            showToast('Error server: ' + (error || status), 'error');
            btn.prop('disabled', false).html('<i class="mdi mdi-check-circle"></i> Simpan Laporan Aktual');
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
