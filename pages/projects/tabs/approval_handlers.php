<?php
/**
 * Tab Approval POST Handlers
 * PCC - Project Cost Control System
 * 
 * Reuses identical business logic from pages/requests/approval.php
 * strictly scoped to current project ($projectId).
 */

if (!isset($projectId) || !$projectId) {
    $projectId = intval($_POST['project_id'] ?? $_GET['id'] ?? 0);
}

if (!$projectId) {
    return;
}

// Security: Require Login & Project Access
requireLogin();

if (!hasPermission('requests.approve')) {
    setFlash('error', 'Anda tidak memiliki hak akses untuk menyetujui pengajuan.');
    header("Location: view.php?id={$projectId}&tab=approval");
    exit;
}

if (!canAccessProject($projectId)) {
    setFlash('error', 'Anda tidak memiliki akses ke proyek ini.');
    header("Location: view.php?id={$projectId}&tab=approval");
    exit;
}

$action = $_POST['action'] ?? '';
$reqId = intval($_POST['request_id'] ?? 0);
$notes = trim($_POST['notes'] ?? '');
$targetWeek = isset($_POST['target_week']) ? intval($_POST['target_week']) : null;

if (!$reqId) {
    setFlash('error', 'ID pengajuan tidak valid.');
    header("Location: view.php?id={$projectId}&tab=approval");
    exit;
}

try {
    $pdo = getDB();
    $pdo->beginTransaction();

    // Lock and verify request belongs to this project
    $stmt = $pdo->prepare("
        SELECT req.*, p.name as project_name 
        FROM requests req 
        LEFT JOIN projects p ON req.project_id = p.id 
        WHERE req.id = ? AND req.project_id = ? 
        FOR UPDATE
    ");
    $stmt->execute([$reqId, $projectId]);
    $currentReq = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentReq) {
        $pdo->rollBack();
        setFlash('error', 'Pengajuan tidak ditemukan pada proyek ini.');
        header("Location: view.php?id={$projectId}&tab=approval");
        exit;
    }

    $currentStatus = $currentReq['status'] ?? '';
    $isAdmin = hasPermission('projects.edit');

    if ($action === 'approve_request_tab') {
        if ($isAdmin) {
            if (!in_array($currentStatus, ['pending', 'pm_approved'])) {
                $pdo->rollBack();
                setFlash('error', 'Status pengajuan tidak valid untuk disetujui oleh Admin.');
                header("Location: view.php?id={$projectId}&tab=approval");
                exit;
            }
        } else {
            if ($currentStatus !== 'pending') {
                $pdo->rollBack();
                setFlash('error', 'Status pengajuan tidak valid untuk disetujui oleh PM.');
                header("Location: view.php?id={$projectId}&tab=approval");
                exit;
            }
        }

        // Determine target_week
        $reqTargetWeek = $currentReq['target_week'] ?? null;
        if (empty($reqTargetWeek) && ($currentReq['request_type'] ?? 'rab') !== 'non_rab') {
            if (!empty($targetWeek)) {
                $reqTargetWeek = $targetWeek;
            } else {
                $pdo->rollBack();
                setFlash('error', 'Pengajuan ini belum memiliki minggu target.');
                header("Location: view.php?id={$projectId}&tab=approval&req_id={$reqId}");
                exit;
            }
        }
        if (empty($reqTargetWeek)) {
            $reqTargetWeek = !empty($targetWeek) ? $targetWeek : 1;
        }

        // PM APPROVAL FLOW (pending -> pm_approved)
        if (!$isAdmin) {
            dbExecute(
                "UPDATE requests SET status = 'pm_approved', pm_notes = ?, pm_approved_by = ?, pm_approved_at = NOW() WHERE id = ? AND project_id = ?",
                [$notes, getCurrentUserId(), $reqId, $projectId]
            );
            $pdo->commit();
            setFlash('success', 'Pengajuan ' . sanitize($currentReq['request_number']) . ' berhasil disetujui (PM Review) dan diteruskan ke Admin.');
            header("Location: view.php?id={$projectId}&tab=approval");
            exit;
        }

        // ADMIN APPROVAL FLOW (pending / pm_approved -> approved)
        $newStatus = 'approved';
        $reqType = $currentReq['request_type'] ?? 'rab';
        $projId = $projectId;
        $projName = $currentReq['project_name'] ?? '';

        // Calculate authoritative total of this request
        $reqTotalRow = dbGetRow("SELECT COALESCE(SUM(quantity * unit_price), 0) as total FROM request_items WHERE request_id = ?", [$reqId]);
        $reqTotal = floatval($reqTotalRow['total']);

        if ($reqType === 'non_rab') {
            dbExecute(
                "UPDATE requests SET status = ?, approved_amount = ?, admin_notes = ?, target_week = ?, week_number = ?, approved_by = ?, approved_at = NOW() WHERE id = ? AND project_id = ?",
                [$newStatus, $reqTotal, $notes, $reqTargetWeek, $reqTargetWeek, getCurrentUserId(), $reqId, $projectId]
            );
            $pdo->commit();
            setFlash('success', 'Pengajuan Biaya Lain-Lain (' . formatRupiah($reqTotal) . ') berhasil disetujui.');
            header("Location: view.php?id={$projectId}&tab=approval");
            exit;
        }

        // DIRECT COST (RAB/RAP) OR MIXED FLOW
        dbExecute(
            "UPDATE requests SET status = ?, approved_amount = ?, admin_notes = ?, target_week = ?, week_number = ?, approved_by = ?, approved_at = NOW() WHERE id = ? AND project_id = ?",
            [$newStatus, $reqTotal, $notes, $reqTargetWeek, $reqTargetWeek, getCurrentUserId(), $reqId, $projectId]
        );

        // Insert realization into weekly_progress using sequential filling & FIFO sisa deduction
        $projInfo = dbGetRow("SELECT start_date, duration_days FROM projects WHERE id = ?", [$projId]);
        $weekRanges = generateWeeklyRanges($projInfo['start_date'], $projInfo['duration_days']);

        $weekStart = null;
        $weekEnd = null;
        foreach ($weekRanges as $w) {
            if ($w['week_number'] == $reqTargetWeek) {
                $weekStart = $w['start'];
                $weekEnd = $w['end'];
                break;
            }
        }

        if ($weekStart && $weekEnd) {
            $approvedItems = dbGetAll("
                SELECT id, subcategory_id, subcat_details, item_code, item_type,
                       unit_price, coefficient, quantity, work_type, total_price
                FROM request_items 
                WHERE request_id = ?
            ", [$reqId]);

            $subcatAmounts = [];

            foreach ($approvedItems as $item) {
                if (empty($item['subcategory_id']) && empty($item['subcat_details'])) {
                    // Non-RAB item in mixed request: does not write to weekly_progress table
                    continue;
                }

                $itemWorkType = $item['work_type'] ?? ($currentReq['work_type'] ?? 'borongan');
                if ($itemWorkType === 'harian') {
                    $subId = intval($item['subcategory_id']);
                    if ($subId > 0) {
                        $amount = floatval($item['total_price'] ?: ($item['quantity'] * $item['unit_price']));
                        if (!isset($subcatAmounts[$subId])) {
                            $subcatAmounts[$subId] = 0;
                        }
                        $subcatAmounts[$subId] += $amount;
                    }
                    continue;
                }

                $itemCoef = floatval($item['coefficient']);
                $itemPrice = floatval($item['unit_price']);
                $subcatDetails = json_decode($item['subcat_details'] ?? '', true);

                if (!empty($subcatDetails) && is_array($subcatDetails)) {
                    $remaining = $itemCoef;

                    foreach ($subcatDetails as $sd) {
                        if ($remaining <= 0) break;

                        $subId = intval($sd['subcategory_id']);
                        $sisaCapacity = floatval($sd['sisa'] ?? 0);

                        $currentUsed = dbGetRow("
                            SELECT COALESCE(SUM(reqi2.coefficient), 0) as total_used
                            FROM request_items reqi2
                            JOIN requests r ON reqi2.request_id = r.id
                            WHERE reqi2.item_code = ?
                              AND reqi2.subcategory_id = ?
                              AND r.status = 'approved'
                              AND r.id != ?
                        ", [$item['item_code'], $subId, $reqId]);

                        $rapQty = floatval($sd['rap_qty'] ?? 0);
                        $actualSisa = $rapQty - floatval($currentUsed['total_used'] ?? 0);
                        if ($actualSisa < 0) $actualSisa = 0;

                        $fillAmount = min($remaining, $actualSisa);
                        if ($fillAmount <= 0) continue;

                        $amount = $fillAmount * $itemPrice;

                        if (!isset($subcatAmounts[$subId])) {
                            $subcatAmounts[$subId] = 0;
                        }
                        $subcatAmounts[$subId] += $amount;

                        $remaining -= $fillAmount;
                    }

                    if ($remaining > 0 && !empty($subcatDetails)) {
                        $lastSubcatId = intval(end($subcatDetails)['subcategory_id']);
                        $amount = $remaining * $itemPrice;
                        if (!isset($subcatAmounts[$lastSubcatId])) {
                            $subcatAmounts[$lastSubcatId] = 0;
                        }
                        $subcatAmounts[$lastSubcatId] += $amount;
                    }
                } else {
                    $subId = intval($item['subcategory_id']);
                    if ($subId) {
                        $amount = $itemCoef * $itemPrice;
                        if (!isset($subcatAmounts[$subId])) {
                            $subcatAmounts[$subId] = 0;
                        }
                        $subcatAmounts[$subId] += $amount;
                    }
                }
            }

            // Calculate available remaining budget pool from all actualized requests of this project
            $sisaRows = dbGetAll("
                SELECT ra.id,
                    GREATEST(ra.remaining_upah - ra.consumed_upah, 0) as avail_upah,
                    GREATEST(ra.remaining_material - ra.consumed_material, 0) as avail_material,
                    GREATEST(ra.remaining_alat - ra.consumed_alat, 0) as avail_alat
                FROM request_actuals ra
                JOIN requests r ON ra.request_id = r.id
                WHERE r.project_id = ? AND r.status = 'approved' AND r.is_actualized = 1
                HAVING avail_upah > 0 OR avail_material > 0 OR avail_alat > 0
                ORDER BY ra.created_at ASC
            ", [$projId]);

            $totalAvailSisa = 0;
            foreach ($sisaRows as $sr) {
                $totalAvailSisa += floatval($sr['avail_upah']) + floatval($sr['avail_material']) + floatval($sr['avail_alat']);
            }

            $totalRequestAmount = array_sum($subcatAmounts);
            $totalDeduction = min($totalAvailSisa, $totalRequestAmount);

            // Mark consumed in request_actuals (FIFO)
            $remainToConsume = $totalDeduction;
            foreach ($sisaRows as $sr) {
                if ($remainToConsume <= 0) break;

                $rowAvail = floatval($sr['avail_upah']) + floatval($sr['avail_material']) + floatval($sr['avail_alat']);
                $consumeFromRow = min($remainToConsume, $rowAvail);

                if ($consumeFromRow > 0) {
                    $cUpah = $rowAvail > 0 ? $consumeFromRow * (floatval($sr['avail_upah']) / $rowAvail) : 0;
                    $cMaterial = $rowAvail > 0 ? $consumeFromRow * (floatval($sr['avail_material']) / $rowAvail) : 0;
                    $cAlat = $rowAvail > 0 ? $consumeFromRow * (floatval($sr['avail_alat']) / $rowAvail) : 0;

                    dbExecute("
                        UPDATE request_actuals 
                        SET consumed_upah = consumed_upah + ?,
                            consumed_material = consumed_material + ?,
                            consumed_alat = consumed_alat + ?
                        WHERE id = ?
                    ", [$cUpah, $cMaterial, $cAlat, $sr['id']]);
                }

                $remainToConsume -= $consumeFromRow;
            }

            // Insert/update weekly_progress per subcategory
            foreach ($subcatAmounts as $subcatId => $totalAmt) {
                if ($totalAmt <= 0) continue;

                $netAmount = $totalAmt;
                if ($totalDeduction > 0 && $totalRequestAmount > 0) {
                    $proportion = $totalAmt / $totalRequestAmount;
                    $deductionForSubcat = $totalDeduction * $proportion;
                    $netAmount = max(0, $totalAmt - $deductionForSubcat);
                }

                dbExecute("
                    INSERT INTO weekly_progress (project_id, subcategory_id, week_number, week_start, week_end, realization_amount, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE realization_amount = realization_amount + VALUES(realization_amount)
                ", [$projId, $subcatId, $reqTargetWeek, $weekStart, $weekEnd, $netAmount, getCurrentUserId()]);
            }
        }

        $pdo->commit();
        $deductionMsg = (isset($totalDeduction) && $totalDeduction > 0) ? ' (dipotong sisa anggaran ' . formatRupiah($totalDeduction) . ')' : '';
        setFlash('success', 'Pengajuan ' . sanitize($currentReq['request_number']) . ' berhasil disetujui dan tercatat di realisasi!' . $deductionMsg);
        header("Location: view.php?id={$projectId}&tab=approval");
        exit;

    } elseif ($action === 'reject_request_tab') {
        if (!in_array($currentStatus, ['pending', 'pm_approved'])) {
            $pdo->rollBack();
            setFlash('error', 'Status pengajuan tidak valid untuk ditolak.');
            header("Location: view.php?id={$projectId}&tab=approval");
            exit;
        }

        $rejectionNotes = $notes;
        dbExecute(
            "UPDATE requests SET status = 'rejected', admin_notes = ?, approved_by = ?, approved_at = NOW() WHERE id = ? AND project_id = ?",
            [$rejectionNotes, getCurrentUserId(), $reqId, $projectId]
        );
        $pdo->commit();
        setFlash('success', 'Pengajuan ' . sanitize($currentReq['request_number']) . ' berhasil ditolak.');
        header("Location: view.php?id={$projectId}&tab=approval");
        exit;
    }

    $pdo->rollBack();
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    setFlash('error', 'Terjadi kesalahan saat memproses approval: ' . $e->getMessage());
    header("Location: view.php?id={$projectId}&tab=approval");
    exit;
}
