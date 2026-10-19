<?php
/**
 * Tab Actualization POST & AJAX Handlers
 * PCC - Project Cost Control System
 * 
 * Reuses storage mechanisms and attachment handling from pages/requests/actualization.php
 * strictly scoped to current project ($projectId).
 */

if (!isset($projectId) || !$projectId) {
    $projectId = intval($_POST['project_id'] ?? $_GET['id'] ?? 0);
}

if (!$projectId) {
    return;
}

requireLogin();

if (!canAccessProject($projectId)) {
    if (isAjaxRequest()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses ke proyek ini.']);
        exit;
    }
    setFlash('error', 'Anda tidak memiliki akses ke proyek ini.');
    header("Location: view.php?id={$projectId}&tab=actualization");
    exit;
}

// 1. AJAX GET: Retrieve actualization data for modal
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_actualization_data') {
    header('Content-Type: application/json');
    $reqId = intval($_GET['request_id'] ?? 0);

    if (!$reqId) {
        echo json_encode(['success' => false, 'message' => 'ID pengajuan tidak valid.']);
        exit;
    }

    $request = dbGetRow("
        SELECT req.*, p.name as project_name, u.full_name as created_by_name
        FROM requests req
        LEFT JOIN projects p ON req.project_id = p.id
        LEFT JOIN users u ON req.created_by = u.id
        WHERE req.id = ? AND req.project_id = ? AND req.status = 'approved'
    ", [$reqId, $projectId]);

    if (!$request) {
        echo json_encode(['success' => false, 'message' => 'Pengajuan tidak ditemukan atau belum disetujui.']);
        exit;
    }

    // Permission check: creator or approver/admin
    $canEdit = (hasPermission('requests.approve') || hasPermission('projects.edit') || $request['created_by'] == getCurrentUserId());

    // Category totals
    $itemsByType = dbGetAll("
        SELECT 
            COALESCE(pi.category, reqi.item_type, 'other') as item_category,
            SUM(reqi.total_price) as total
        FROM request_items reqi
        LEFT JOIN project_items pi ON pi.item_code = reqi.item_code AND pi.project_id = ?
        WHERE reqi.request_id = ?
        GROUP BY item_category
    ", [$projectId, $reqId]);

    $totalUpah = 0;
    $totalMaterial = 0;
    $totalAlat = 0;
    foreach ($itemsByType as $row) {
        $cat = $row['item_category'] ?? '';
        if ($cat === 'upah') $totalUpah = floatval($row['total']);
        elseif ($cat === 'material') $totalMaterial = floatval($row['total']);
        elseif ($cat === 'alat') $totalAlat = floatval($row['total']);
    }

    // Existing actualization
    $existingActual = dbGetRow("SELECT * FROM request_actuals WHERE request_id = ?", [$reqId]);
    $stagedAttachments = [];

    if ($existingActual) {
        $existingAttachments = dbGetAll("
            SELECT * FROM request_actual_attachments 
            WHERE request_actual_id = ? 
            ORDER BY created_at ASC
        ", [$existingActual['id']]);

        $baseUrl = getBaseUrl();
        $uploadDir = __DIR__ . '/../../../uploads/actuals/';

        foreach ($existingAttachments as $att) {
            $filePath = $uploadDir . $att['filename'];
            $ext = strtolower(pathinfo($att['original_name'] ?: $att['filename'], PATHINFO_EXTENSION));
            $nameWithoutExt = pathinfo($att['original_name'] ?: $att['filename'], PATHINFO_FILENAME);
            $isImg = in_array($att['file_type'], ['image/jpeg', 'image/png', 'image/jpg', 'image/webp']) || in_array($ext, ['jpg', 'jpeg', 'png', 'webp']);
            $isPdf = $att['file_type'] === 'application/pdf' || $ext === 'pdf';

            $stagedAttachments[] = [
                'id' => 'existing_' . $att['id'],
                'attachment_id' => intval($att['id']),
                'filename' => $att['filename'],
                'customName' => $nameWithoutExt,
                'originalName' => $att['original_name'] ?: $att['filename'],
                'extension' => $ext,
                'fileType' => $att['file_type'],
                'fileSize' => intval($att['file_size']),
                'fileSizeFormatted' => !empty($att['file_size']) ? (round($att['file_size'] / 1024, 1) . ' KB') : '',
                'isImage' => $isImg,
                'isPdf' => $isPdf,
                'previewUrl' => $baseUrl . '/uploads/actuals/' . $att['filename'],
                'isExisting' => true
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'can_edit' => $canEdit,
        'request' => [
            'id' => $request['id'],
            'request_number' => $request['request_number'],
            'request_type' => $request['request_type'],
            'target_week' => $request['target_week'] ?? $request['week_number'],
            'created_by_name' => $request['created_by_name'],
            'description' => $request['description']
        ],
        'totals' => [
            'upah' => $totalUpah,
            'material' => $totalMaterial,
            'alat' => $totalAlat,
            'grand_total' => $totalUpah + $totalMaterial + $totalAlat
        ],
        'actual' => $existingActual ?: null,
        'attachments' => $stagedAttachments
    ]);
    exit;
}

// 2. AJAX POST: Delete single attachment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_actual_attachment_tab') {
    header('Content-Type: application/json');
    $attId = intval($_POST['attachment_id'] ?? 0);
    $reqId = intval($_POST['request_id'] ?? 0);

    if (!$attId || !$reqId) {
        echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap.']);
        exit;
    }

    $request = dbGetRow("SELECT * FROM requests WHERE id = ? AND project_id = ? AND status = 'approved'", [$reqId, $projectId]);
    if (!$request) {
        echo json_encode(['success' => false, 'message' => 'Pengajuan tidak valid.']);
        exit;
    }

    if (!hasPermission('requests.approve') && !hasPermission('projects.edit') && $request['created_by'] != getCurrentUserId()) {
        echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak akses untuk menghapus lampiran.']);
        exit;
    }

    $existingActual = dbGetRow("SELECT * FROM request_actuals WHERE request_id = ?", [$reqId]);
    if ($existingActual) {
        $att = dbGetRow("SELECT * FROM request_actual_attachments WHERE id = ? AND request_actual_id = ?", [$attId, $existingActual['id']]);
        if ($att) {
            $filepath = __DIR__ . '/../../../uploads/actuals/' . $att['filename'];
            if (file_exists($filepath)) {
                @unlink($filepath);
            }
            dbExecute("DELETE FROM request_actual_attachments WHERE id = ?", [$attId]);
            echo json_encode(['success' => true]);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Lampiran tidak ditemukan.']);
    exit;
}

// 3. POST: Save Actualization Report (Form / AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_actualization_tab') {
    $isAjax = isAjaxRequest();
    if ($isAjax) {
        header('Content-Type: application/json');
    }

    $newlyUploadedFiles = [];
    $filesToDeleteAfterCommit = [];

    try {
        $reqId = intval($_POST['request_id'] ?? 0);
        if (!$reqId) {
            throw new Exception("ID pengajuan tidak valid.");
        }

        $request = dbGetRow("
            SELECT req.* FROM requests req 
            WHERE req.id = ? AND req.project_id = ? AND req.status = 'approved'
        ", [$reqId, $projectId]);

        if (!$request) {
            throw new Exception("Pengajuan tidak ditemukan pada proyek ini atau belum disetujui.");
        }

        // Permission check
        if (!hasPermission('requests.approve') && !hasPermission('projects.edit') && $request['created_by'] != getCurrentUserId()) {
            throw new Exception("Anda tidak memiliki hak akses untuk mengisi laporan aktual ini.");
        }

        // Calculate limits per category
        $itemsByType = dbGetAll("
            SELECT 
                COALESCE(pi.category, reqi.item_type, 'other') as item_category,
                SUM(reqi.total_price) as total
            FROM request_items reqi
            LEFT JOIN project_items pi ON pi.item_code = reqi.item_code AND pi.project_id = ?
            WHERE reqi.request_id = ?
            GROUP BY item_category
        ", [$projectId, $reqId]);

        $totalUpah = 0;
        $totalMaterial = 0;
        $totalAlat = 0;
        foreach ($itemsByType as $row) {
            $cat = $row['item_category'] ?? '';
            if ($cat === 'upah') $totalUpah = floatval($row['total']);
            elseif ($cat === 'material') $totalMaterial = floatval($row['total']);
            elseif ($cat === 'alat') $totalAlat = floatval($row['total']);
        }

        $remainingUpah = max(0, floatval($_POST['remaining_upah'] ?? 0));
        $remainingMaterial = max(0, floatval($_POST['remaining_material'] ?? 0));
        $remainingAlat = max(0, floatval($_POST['remaining_alat'] ?? 0));
        $notesUpah = trim($_POST['notes_upah'] ?? '');
        $notesMaterial = trim($_POST['notes_material'] ?? '');
        $notesAlat = trim($_POST['notes_alat'] ?? '');

        // Validations
        if ($remainingUpah > $totalUpah) {
            throw new Exception("Sisa upah (" . formatRupiah($remainingUpah) . ") tidak boleh melebihi total pengajuan upah (" . formatRupiah($totalUpah) . ").");
        }
        if ($remainingMaterial > $totalMaterial) {
            throw new Exception("Sisa material (" . formatRupiah($remainingMaterial) . ") tidak boleh melebihi total pengajuan material (" . formatRupiah($totalMaterial) . ").");
        }
        if ($remainingAlat > $totalAlat) {
            throw new Exception("Sisa alat (" . formatRupiah($remainingAlat) . ") tidak boleh melebihi total pengajuan alat (" . formatRupiah($totalAlat) . ").");
        }

        $pdo = getDB();
        $pdo->beginTransaction();

        $existingActual = dbGetRow("SELECT * FROM request_actuals WHERE request_id = ?", [$reqId]);

        if ($existingActual) {
            dbExecute("
                UPDATE request_actuals 
                SET remaining_upah = ?, remaining_material = ?, remaining_alat = ?,
                    notes_upah = ?, notes_material = ?, notes_alat = ?,
                    updated_at = NOW()
                WHERE id = ?
            ", [$remainingUpah, $remainingMaterial, $remainingAlat, $notesUpah, $notesMaterial, $notesAlat, $existingActual['id']]);
            $actualId = $existingActual['id'];
        } else {
            $actualId = dbInsert("
                INSERT INTO request_actuals (request_id, remaining_upah, remaining_material, remaining_alat, notes_upah, notes_material, notes_alat, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ", [$reqId, $remainingUpah, $remainingMaterial, $remainingAlat, $notesUpah, $notesMaterial, $notesAlat, getCurrentUserId()]);
        }

        // Mark request as actualized
        dbExecute("UPDATE requests SET is_actualized = 1 WHERE id = ? AND project_id = ?", [$reqId, $projectId]);

        $uploadDir = __DIR__ . '/../../../uploads/actuals/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Handle attachment deletions
        if (!empty($_POST['delete_attachments'])) {
            $deleteIds = json_decode($_POST['delete_attachments'], true);
            if (is_array($deleteIds)) {
                foreach ($deleteIds as $attId) {
                    $attId = intval($attId);
                    $att = dbGetRow("SELECT * FROM request_actual_attachments WHERE id = ? AND request_actual_id = ?", [$attId, $actualId]);
                    if ($att) {
                        $filepath = $uploadDir . $att['filename'];
                        if (file_exists($filepath)) {
                            $filesToDeleteAfterCommit[] = $filepath;
                        }
                        dbExecute("DELETE FROM request_actual_attachments WHERE id = ?", [$attId]);
                    }
                }
            }
        }

        // Handle existing attachments rename
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

                if (!in_array($origExt, $allowedExts)) continue;
                if (!in_array($fileType, $allowedTypes)) {
                    if (function_exists('mime_content_type')) {
                        $detectedMime = mime_content_type($tmpName);
                        if (!in_array($detectedMime, $allowedTypes)) continue;
                        $fileType = $detectedMime;
                    }
                }
                if ($fileSize > $maxSize) continue;

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

                $filename = 'actual_' . $reqId . '_' . time() . '_' . $key . '_' . bin2hex(random_bytes(4)) . '.' . $origExt;
                $filepath = $uploadDir . $filename;

                if (move_uploaded_file($tmpName, $filepath)) {
                    $newlyUploadedFiles[] = $filepath;
                    dbInsert("
                        INSERT INTO request_actual_attachments (request_actual_id, filename, original_name, file_type, file_size)
                        VALUES (?, ?, ?, ?, ?)
                    ", [$actualId, $filename, $finalOriginalName, $fileType, $fileSize]);
                }
            }
        }

        $pdo->commit();

        // Safe cleanup of staged deleted files after commit
        foreach ($filesToDeleteAfterCommit as $delFile) {
            if (file_exists($delFile)) {
                @unlink($delFile);
            }
        }

        if ($isAjax) {
            echo json_encode([
                'success' => true,
                'message' => 'Laporan aktualisasi pengajuan ' . sanitize($request['request_number']) . ' berhasil disimpan!'
            ]);
            exit;
        }

        setFlash('success', 'Laporan aktualisasi pengajuan ' . sanitize($request['request_number']) . ' berhasil disimpan!');
        header("Location: view.php?id={$projectId}&tab=actualization");
        exit;

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // Clean up newly uploaded files on rollback
        foreach ($newlyUploadedFiles as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }

        if ($isAjax) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }

        setFlash('error', 'Gagal menyimpan laporan aktual: ' . $e->getMessage());
        header("Location: view.php?id={$projectId}&tab=actualization");
        exit;
    }
}
