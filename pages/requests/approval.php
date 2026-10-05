<?php
/**
 * Approval Center
 * PCC - Project Cost Control System
 */

// IMPORTANT: Process all logic that may redirect BEFORE including header.php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();
requireAdminOrPM();

$requestId = $_GET['id'] ?? null;

// Project filter: persist across requests, refreshes, and page navigation via $_SESSION
if (isset($_GET['project_id'])) {
    $projectFilter = $_GET['project_id'];
    $_SESSION['approval_project_filter'] = $projectFilter;
} elseif (isset($_SESSION['approval_project_filter'])) {
    $projectFilter = $_SESSION['approval_project_filter'];
} else {
    $projectFilter = '';
}

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    $reqId = $_POST['request_id'];
    $notes = trim($_POST['notes'] ?? '');
    $targetWeek = $_POST['target_week'] ?? null;
    
    try {
        // Determine current request status
        $currentReq = dbGetRow("SELECT req.*, p.name as project_name FROM requests req LEFT JOIN projects p ON req.project_id = p.id WHERE req.id = ?", [$reqId]);
        $currentStatus = $currentReq['status'] ?? '';
        
        if ($action === 'approve') {
            $isAdmin = hasPermission('projects.edit');
            
            if ($isAdmin) {
                if (!in_array($currentStatus, ['pending', 'pm_approved'])) {
                    setFlash('error', 'Status pengajuan tidak valid untuk di-approve oleh Admin!');
                    header('Location: approval.php');
                    exit;
                }
            } else {
                if ($currentStatus !== 'pending') {
                    setFlash('error', 'Status pengajuan tidak valid untuk di-approve oleh PM!');
                    header('Location: approval.php');
                    exit;
                }
            }
            
            // Get target_week from the request (set by requester at creation)
            $targetWeek = $currentReq['target_week'] ?? null;
            if (empty($targetWeek) && ($currentReq['request_type'] ?? 'rab') !== 'non_rab') {
                setFlash('error', 'Pengajuan ini belum memiliki minggu target!');
                header('Location: approval.php?id=' . $reqId);
                exit;
            }
            if (empty($targetWeek)) {
                $targetWeek = 1;
            }
            
            if (!$isAdmin) {
                // PM approval: set status to 'pm_approved'
                dbExecute("UPDATE requests SET status = 'pm_approved', pm_notes = ?, pm_approved_by = ?, pm_approved_at = NOW() WHERE id = ?",
                    [$notes, getCurrentUserId(), $reqId]);
                setFlash('success', 'Pengajuan berhasil disetujui (PM Review) dan diteruskan ke Admin.');
                header('Location: approval.php');
                exit;
            }
            
            // ADMIN APPROVAL
            $newStatus = 'approved';
            $reqType = $currentReq['request_type'] ?? 'rab';
            $projId = $currentReq['project_id'];
            $projName = $currentReq['project_name'] ?? '';

            // Calculate authoritative total of this request
            $reqTotalRow = dbGetRow("SELECT COALESCE(SUM(quantity * unit_price), 0) as total FROM request_items WHERE request_id = ?", [$reqId]);
            $reqTotal = floatval($reqTotalRow['total']);
            
            if ($reqType === 'non_rab') {
                // Update request status
                dbExecute("UPDATE requests SET status = ?, approved_amount = ?, admin_notes = ?, target_week = ?, week_number = ?, approved_by = ?, approved_at = NOW() WHERE id = ?",
                    [$newStatus, $reqTotal, $notes, $targetWeek, $targetWeek, getCurrentUserId(), $reqId]);
                
                setFlash('success', 'Pengajuan Biaya Lain-Lain (' . formatRupiah($reqTotal) . ') berhasil disetujui!');
                $_SESSION['approved_project_id'] = $projId;
                $_SESSION['approved_project_name'] = $projName;
                header('Location: approval.php');
                exit;
            }
            
            // DIRECT COST (RAB/RAP) OR MIXED FLOW
            // Update request status with target_week, week_number and approved_amount
            dbExecute("UPDATE requests SET status = ?, approved_amount = ?, admin_notes = ?, target_week = ?, week_number = ?, approved_by = ?, approved_at = NOW() WHERE id = ?",
                [$newStatus, $reqTotal, $notes, $targetWeek, $targetWeek, getCurrentUserId(), $reqId]);
            
            // Insert realization into weekly_progress using sequential filling
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
                // Get all items of the approved request with subcat_details
                $approvedItems = dbGetAll("
                    SELECT id, subcategory_id, subcat_details, item_code, item_type,
                           unit_price, coefficient, quantity, work_type, total_price
                    FROM request_items 
                    WHERE request_id = ?
                ", [$reqId]);
                
                // Accumulate amounts per subcategory for weekly_progress
                $subcatAmounts = [];
                
                foreach ($approvedItems as $item) {
                    if (empty($item['subcategory_id']) && empty($item['subcat_details'])) {
                        // Non-RAB item in mixed request: does not write to weekly_progress table
                        continue;
                    }
                    
                    $itemWorkType = $item['work_type'] ?? ($currentReq['work_type'] ?? 'borongan');
                    if ($itemWorkType === 'harian') {
                        $subcatId = intval($item['subcategory_id']);
                        if ($subcatId > 0) {
                            $amount = floatval($item['total_price'] ?: ($item['quantity'] * $item['unit_price']));
                            if (!isset($subcatAmounts[$subcatId])) {
                                $subcatAmounts[$subcatId] = 0;
                            }
                            $subcatAmounts[$subcatId] += $amount;
                        }
                        continue;
                    }
                    
                    $itemCoef = floatval($item['coefficient']);
                    $itemPrice = floatval($item['unit_price']);
                    $subcatDetails = json_decode($item['subcat_details'] ?? '', true);
                    
                    if (!empty($subcatDetails) && is_array($subcatDetails)) {
                        // Sequential filling: distribute coefficient across pekerjaan in order
                        $remaining = $itemCoef;
                        
                        foreach ($subcatDetails as $sd) {
                            if ($remaining <= 0) break;
                            
                            $subcatId = intval($sd['subcategory_id']);
                            $sisaCapacity = floatval($sd['sisa'] ?? 0);
                            
                            // Recalculate current sisa from DB for accuracy
                            $currentUsed = dbGetRow("
                                SELECT COALESCE(SUM(reqi2.coefficient), 0) as total_used
                                FROM request_items reqi2
                                JOIN requests r ON reqi2.request_id = r.id
                                WHERE reqi2.item_code = ?
                                  AND reqi2.subcategory_id = ?
                                  AND r.status = 'approved'
                                  AND r.id != ?
                            ", [$item['item_code'], $subcatId, $reqId]);
                            
                            $rapQty = floatval($sd['rap_qty'] ?? 0);
                            $actualSisa = $rapQty - floatval($currentUsed['total_used'] ?? 0);
                            if ($actualSisa < 0) $actualSisa = 0;
                            
                            // Fill this pekerjaan: take min(remaining, capacity)
                            $fillAmount = min($remaining, $actualSisa);
                            if ($fillAmount <= 0) continue;
                            
                            $amount = $fillAmount * $itemPrice;
                            
                            if (!isset($subcatAmounts[$subcatId])) {
                                $subcatAmounts[$subcatId] = 0;
                            }
                            $subcatAmounts[$subcatId] += $amount;
                            
                            $remaining -= $fillAmount;
                        }
                        
                        // If there's still remaining (over-capacity), put into last subcategory
                        if ($remaining > 0 && !empty($subcatDetails)) {
                            $lastSubcatId = intval(end($subcatDetails)['subcategory_id']);
                            $amount = $remaining * $itemPrice;
                            if (!isset($subcatAmounts[$lastSubcatId])) {
                                $subcatAmounts[$lastSubcatId] = 0;
                            }
                            $subcatAmounts[$lastSubcatId] += $amount;
                        }
                    } else {
                        // Fallback: no subcat_details, use primary subcategory_id
                        $subcatId = intval($item['subcategory_id']);
                        if ($subcatId) {
                            $amount = $itemCoef * $itemPrice;
                            if (!isset($subcatAmounts[$subcatId])) {
                                $subcatAmounts[$subcatId] = 0;
                            }
                            $subcatAmounts[$subcatId] += $amount;
                        }
                    }
                }
                
                // Calculate available remaining budget (total pool from all actualized requests)
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
                
                // Pool all remaining into one total (no per-category restriction)
                $totalAvailSisa = 0;
                foreach ($sisaRows as $sr) {
                    $totalAvailSisa += floatval($sr['avail_upah']) + floatval($sr['avail_material']) + floatval($sr['avail_alat']);
                }
                
                // Deduction = min(total available sisa, total request amount)
                $totalRequestAmount = array_sum($subcatAmounts);
                $totalDeduction = min($totalAvailSisa, $totalRequestAmount);
                
                // Mark consumed in request_actuals (FIFO: consume from oldest first)
                $remainToConsume = $totalDeduction;
                foreach ($sisaRows as $sr) {
                    if ($remainToConsume <= 0) break;
                    
                    $rowAvail = floatval($sr['avail_upah']) + floatval($sr['avail_material']) + floatval($sr['avail_alat']);
                    $consumeFromRow = min($remainToConsume, $rowAvail);
                    
                    if ($consumeFromRow > 0) {
                        // Distribute consumption proportionally across categories within this row
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
                
                // Insert/update weekly_progress per subcategory (with total sisa deduction)
                foreach ($subcatAmounts as $subcatId => $totalAmount) {
                    if ($totalAmount <= 0) continue;
                    
                    // Proportionally deduct total sisa across all subcategories
                    $netAmount = $totalAmount;
                    if ($totalDeduction > 0 && $totalRequestAmount > 0) {
                        $proportion = $totalAmount / $totalRequestAmount;
                        $deductionForSubcat = $totalDeduction * $proportion;
                        $netAmount = max(0, $totalAmount - $deductionForSubcat);
                    }
                    
                    dbExecute("
                        INSERT INTO weekly_progress (project_id, subcategory_id, week_number, week_start, week_end, realization_amount, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE realization_amount = realization_amount + VALUES(realization_amount)
                    ", [$projId, $subcatId, $targetWeek, $weekStart, $weekEnd, $netAmount, getCurrentUserId()]);
                }
            }
            
            $deductionMsg = (isset($totalDeduction) && $totalDeduction > 0) ? ' (dipotong sisa anggaran ' . number_format($totalDeduction, 0, ',', '.') . ')' : '';
            setFlash('success', 'Pengajuan berhasil disetujui dan tercatat di realisasi!' . $deductionMsg);
            
            // Store approved project info for navigation modal
            $_SESSION['approved_project_id'] = $projId;
            $_SESSION['approved_project_name'] = $projName;
            
        } else {
            if (!in_array($currentStatus, ['pending', 'pm_approved'])) {
                setFlash('error', 'Status pengajuan tidak valid untuk ditolak!');
                header('Location: approval.php');
                exit;
            }
            $rejectionNotes = $notes;
            dbExecute("UPDATE requests SET status = 'rejected', admin_notes = ?, approved_by = ?, approved_at = NOW() WHERE id = ?",
                [$rejectionNotes, getCurrentUserId(), $reqId]);
            setFlash('success', 'Pengajuan berhasil ditolak.');
        }
        
    } catch (Exception $e) {
        setFlash('error', 'Terjadi kesalahan: ' . $e->getMessage());
    }
    
    header('Location: approval.php');
    exit;
}

// Get non-RAB category list
$nonRabCategories = getNonRabCategories();

// Get requests based on role
$isAdmin = hasPermission('projects.edit');
$where = $isAdmin ? "req.status IN ('pending', 'pm_approved')" : "req.status = 'pending'";
$params = [];
if ($projectFilter) {
    $where .= " AND req.project_id = ?";
    $params[] = $projectFilter;
}

$pendingRequests = dbGetAll("
    SELECT req.*, p.name as project_name, u.full_name as created_by_name,
        upm.full_name as pm_approved_by_name, upm.role as pm_approved_by_role,
        ua.full_name as approved_by_name, ua.role as approved_by_role,
        (SELECT COALESCE(SUM(CASE WHEN req.request_type = 'non_rab' THEN reqi.quantity * reqi.unit_price ELSE reqi.total_price END), 0) FROM request_items reqi WHERE reqi.request_id = req.id) as total_amount
    FROM requests req
    LEFT JOIN projects p ON req.project_id = p.id
    LEFT JOIN users u ON req.created_by = u.id
    LEFT JOIN users upm ON req.pm_approved_by = upm.id
    LEFT JOIN users ua ON req.approved_by = ua.id
    WHERE $where
    ORDER BY req.created_at ASC
", $params);

// Get projects for filter (active or having pending requests)
$projects = dbGetAll("
    SELECT DISTINCT p.id, p.name 
    FROM projects p
    WHERE p.status = 'on_progress' 
       OR p.id IN (SELECT project_id FROM requests WHERE status IN ('pending', 'pm_approved'))
    ORDER BY p.name
");

// If specific request selected, get details
$selectedRequest = null;
$selectedItems = [];
$nonRabBudgetStats = null;
if ($requestId) {
    $statusFilter = $isAdmin ? "req.status IN ('pending', 'pm_approved')" : "req.status = 'pending'";
    $selectedRequest = dbGetRow("
        SELECT req.*, p.name as project_name, p.id as project_id, u.full_name as created_by_name,
               upm.full_name as pm_approved_by_name, upm.role as pm_approved_by_role,
               ua.full_name as approved_by_name, ua.role as approved_by_role
        FROM requests req
        LEFT JOIN projects p ON req.project_id = p.id
        LEFT JOIN users u ON req.created_by = u.id
        LEFT JOIN users upm ON req.pm_approved_by = upm.id
        LEFT JOIN users ua ON req.approved_by = ua.id
        WHERE req.id = ? AND $statusFilter
    ", [$requestId]);
    
    if ($selectedRequest) {
        $projectId = $selectedRequest['project_id'];
        $isNonRabRequest = ($selectedRequest['request_type'] ?? 'rab') === 'non_rab';
        
        if ($isNonRabRequest) {
            // Non-RAB request items
            $selectedItems = dbGetAll("
                SELECT reqi.*
                FROM request_items reqi
                WHERE reqi.request_id = ?
                ORDER BY reqi.id ASC
            ", [$requestId]);
            
            $selectedPekerjaan = [];
        } else {
            // Direct Cost (RAB/RAP)
            // Get items with RAP comparison - match by item_code from Master Data RAP tables
            // Use subquery to get project_ahsp_details_rap data to avoid duplicates
            $selectedItems = dbGetAll("
                SELECT reqi.*, rs.code, rs.name as subcategory_name,
                    -- RAP data from Master Data RAP (matched by item_code within same project)
                    (SELECT d2.coefficient FROM project_ahsp_details_rap d2 
                     JOIN project_items_rap pir2 ON d2.item_id = pir2.id 
                     JOIN project_ahsp_rap par2 ON d2.ahsp_id = par2.id
                     JOIN project_ahsp pa2 ON par2.ahsp_code = pa2.ahsp_code AND par2.project_id = pa2.project_id
                     JOIN rab_subcategories rs2 ON rs2.ahsp_id = pa2.id
                     WHERE pir2.item_code = reqi.item_code 
                       AND pir2.project_id = ?
                       AND rs2.id = reqi.subcategory_id
                     LIMIT 1) as rap_coefficient,
                    (SELECT COALESCE(d2.unit_price, pir2.price) FROM project_ahsp_details_rap d2 
                     JOIN project_items_rap pir2 ON d2.item_id = pir2.id 
                     JOIN project_ahsp_rap par2 ON d2.ahsp_id = par2.id
                     JOIN project_ahsp pa2 ON par2.ahsp_code = pa2.ahsp_code AND par2.project_id = pa2.project_id
                     JOIN rab_subcategories rs2 ON rs2.ahsp_id = pa2.id
                     WHERE pir2.item_code = reqi.item_code 
                       AND pir2.project_id = ?
                       AND rs2.id = reqi.subcategory_id
                     LIMIT 1) as rap_unit_price,
                    rap.volume as rap_volume,
                    -- Qty per Item RAP = koefisien AHSP × volume pekerjaan
                    (SELECT COALESCE(d2.coefficient, 0) * COALESCE(rap.volume, 0) 
                     FROM project_ahsp_details_rap d2 
                     JOIN project_items_rap pir2 ON d2.item_id = pir2.id 
                     JOIN project_ahsp_rap par2 ON d2.ahsp_id = par2.id
                     JOIN project_ahsp pa2 ON par2.ahsp_code = pa2.ahsp_code AND par2.project_id = pa2.project_id
                     JOIN rab_subcategories rs2 ON rs2.ahsp_id = pa2.id
                     WHERE pir2.item_code = reqi.item_code 
                       AND pir2.project_id = ?
                       AND rs2.id = reqi.subcategory_id
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
            // Extract all subcategory IDs from subcat_details JSON (not just subcategory_id column)
            $reqItemsForPekerjaan = dbGetAll("SELECT subcat_details, subcategory_id FROM request_items WHERE request_id = ?", [$requestId]);
            $allSubcatIds = [];
            foreach ($reqItemsForPekerjaan as $ri) {
                $sd = json_decode($ri['subcat_details'] ?? '', true);
                if (!empty($sd) && is_array($sd)) {
                    foreach ($sd as $detail) {
                        $allSubcatIds[intval($detail['subcategory_id'])] = true;
                    }
                } elseif (!empty($ri['subcategory_id'])) {
                    $allSubcatIds[intval($ri['subcategory_id'])] = true;
                }
            }
            $selectedPekerjaan = [];
            if (!empty($allSubcatIds)) {
                $ids = array_keys($allSubcatIds);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $selectedPekerjaan = dbGetAll("
                    SELECT DISTINCT rs.id, rs.code, rs.name, rc.code as category_code, rc.name as category_name
                    FROM rab_subcategories rs
                    JOIN rab_categories rc ON rs.category_id = rc.id
                    WHERE rs.id IN ($placeholders)
                    ORDER BY rc.sort_order, rc.code, rs.sort_order, rs.code
                ", $ids);
            }
            
            // If request is Harian, compute subcategory RAP benchmark & remaining physical volume
            $harianSubcatStats = [];
            if (($selectedRequest['work_type'] ?? 'borongan') === 'harian') {
                foreach ($selectedItems as $si) {
                    $subId = intval($si['subcategory_id'] ?? 0);
                    $iType = trim($si['item_type'] ?? 'upah');
                    if (!$subId) continue;
                    $key = $subId . '_' . $iType;
                    if (isset($harianSubcatStats[$key])) continue;
                    
                    $scRow = dbGetRow("
                        SELECT rs.id, rs.code, rs.name, rs.unit, COALESCE(ri.volume, rs.volume, 0) as subcat_volume
                        FROM rab_subcategories rs
                        LEFT JOIN rap_items ri ON ri.subcategory_id = rs.id
                        WHERE rs.id = ?
                    ", [$subId]);
                    
                    $rapCostRow = dbGetRow("
                        SELECT SUM(d.coefficient * COALESCE(d.unit_price, pir.price)) as rap_unit_cost
                        FROM rab_subcategories rs
                        JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                        JOIN project_ahsp_rap par ON par.ahsp_code = pa.ahsp_code AND par.project_id = pa.project_id
                        JOIN project_ahsp_details_rap d ON d.ahsp_id = par.id
                        JOIN project_items_rap pir ON d.item_id = pir.id
                        WHERE rs.id = ? AND pir.category = ?
                    ", [$subId, $iType]);
                    
                    $rapUnitCost = floatval($rapCostRow['rap_unit_cost'] ?? 0);
                    $subcatVol = floatval($scRow['subcat_volume'] ?? 0);
                    $rapTotalCost = $rapUnitCost * $subcatVol;
                    
                    $subcatTotalLapangan = 0;
                    $thisReqWorkVol = 0;
                    foreach ($selectedItems as $si2) {
                        if (intval($si2['subcategory_id'] ?? 0) === $subId && ($si2['item_type'] ?? 'upah') === $iType) {
                            $subcatTotalLapangan += floatval($si2['total_price'] ?: ($si2['quantity'] * $si2['unit_price']));
                            if (!empty($si2['work_volume'])) {
                                $thisReqWorkVol = max($thisReqWorkVol, floatval($si2['work_volume']));
                            }
                        }
                    }
                    
                    $boronganUsedItems = dbGetAll("
                        SELECT d.coefficient as ahsp_coef,
                               COALESCE((
                                   SELECT SUM(reqi2.coefficient) 
                                   FROM request_items reqi2 
                                   JOIN requests r ON reqi2.request_id = r.id 
                                   WHERE reqi2.item_code = pir.item_code 
                                     AND reqi2.subcategory_id = ? 
                                     AND (reqi2.work_type = 'borongan' OR reqi2.work_type IS NULL)
                                     AND r.status IN ('pending', 'pm_approved', 'approved')
                                     AND r.id != ?
                               ), 0) as used_coef
                        FROM rab_subcategories rs
                        JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                        JOIN project_ahsp_rap par ON par.ahsp_code = pa.ahsp_code AND par.project_id = pa.project_id
                        JOIN project_ahsp_details_rap d ON d.ahsp_id = par.id
                        JOIN project_items_rap pir ON d.item_id = pir.id
                        WHERE rs.id = ? AND pir.category = ?
                    ", [$subId, $requestId, $subId, $iType]);
                    
                    $maxBoronganVol = 0.0;
                    foreach ($boronganUsedItems as $bItem) {
                        $c = floatval($bItem['ahsp_coef']);
                        if ($c > 0) {
                            $v = floatval($bItem['used_coef']) / $c;
                            if ($v > $maxBoronganVol) $maxBoronganVol = $v;
                        }
                    }
                    
                    $harianUsedRow = dbGetRow("
                        SELECT COALESCE(SUM(h_vol), 0) as used_vol FROM (
                            SELECT MAX(reqi.work_volume) as h_vol
                            FROM request_items reqi
                            JOIN requests r ON reqi.request_id = r.id
                            WHERE reqi.subcategory_id = ? 
                              AND reqi.work_type = 'harian'
                              AND reqi.work_volume IS NOT NULL
                              AND r.status IN ('pending', 'pm_approved', 'approved')
                              AND r.id != ?
                            GROUP BY r.id
                        ) t
                    ", [$subId, $requestId]);
                    $usedHarianVol = floatval($harianUsedRow['used_vol'] ?? 0);
                    
                    $remainingVol = max(0, $subcatVol - $maxBoronganVol - $usedHarianVol - $thisReqWorkVol);
                    
                    $diff = $rapTotalCost - $subcatTotalLapangan;
                    $pct = $rapTotalCost > 0 ? ($diff / $rapTotalCost) * 100 : 0;
                    
                    $harianSubcatStats[$key] = [
                        'subcat_code' => $scRow['code'] ?? '',
                        'subcat_name' => $scRow['name'] ?? '',
                        'subcat_unit' => $scRow['unit'] ?: "m'",
                        'subcat_volume' => $subcatVol,
                        'rap_unit_cost' => $rapUnitCost,
                        'rap_total_cost' => $rapTotalCost,
                        'subcat_total_lapangan' => $subcatTotalLapangan,
                        'diff' => $diff,
                        'pct' => $pct,
                        'remaining_vol' => $remainingVol
                    ];
                }
            }
        }
    }
}

// Calculate available remaining budget (total pool) from previous actualized requests
$availableRemaining = ['upah' => 0, 'material' => 0, 'alat' => 0, 'total' => 0];
if ($selectedRequest) {
    $sisaData = dbGetAll("
        SELECT 
            GREATEST(ra.remaining_upah - ra.consumed_upah, 0) as avail_upah,
            GREATEST(ra.remaining_material - ra.consumed_material, 0) as avail_material,
            GREATEST(ra.remaining_alat - ra.consumed_alat, 0) as avail_alat
        FROM request_actuals ra
        JOIN requests r ON ra.request_id = r.id
        WHERE r.project_id = ? AND r.status = 'approved' AND r.is_actualized = 1
    ", [$selectedRequest['project_id']]);
    
    foreach ($sisaData as $sd) {
        $availableRemaining['upah'] += floatval($sd['avail_upah']);
        $availableRemaining['material'] += floatval($sd['avail_material']);
        $availableRemaining['alat'] += floatval($sd['avail_alat']);
    }
    $availableRemaining['total'] = $availableRemaining['upah'] + $availableRemaining['material'] + $availableRemaining['alat'];
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
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>">PCC</a></li>
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
                <h5 class="mb-0">Pengajuan Menunggu Review <span class="badge bg-warning"><?= count($pendingRequests) ?></span></h5>
            </div>
            <div class="card-body p-0">
                <div class="p-2">
                    <select class="form-select form-select-sm" onchange="window.location='?project_id='+encodeURIComponent(this.value)">
                        <option value="" <?= empty($projectFilter) ? 'selected' : '' ?>>Filter Proyek (Semua)</option>
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
                                <?php if ($req['status'] === 'pm_approved'): ?>
                                <span class="badge bg-info">PM Approved</span>
                                <?php endif; ?>
                                <?php if (($req['request_type'] ?? 'rab') === 'non_rab'): ?>
                                <br><span class="badge text-white" style="background-color: #6f42c1; font-size: 0.7rem;"><i class="mdi mdi-receipt"></i> Biaya Lain-Lain</span>
                                <?php endif; ?>
                                <br><small><?= sanitize($req['project_name']) ?></small>
                                <br><small class="text-muted"><?= formatDateTime($req['created_at']) ?></small>
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
        <?php if ($selectedRequest): 
            $isNonRab = ($selectedRequest['request_type'] ?? 'rab') === 'non_rab';
            $isMixed = ($selectedRequest['request_type'] ?? 'rab') === 'mixed';
            
            $directItems = [];
            $nonRabItems = [];
            foreach ($selectedItems as $it) {
                if (!empty($it['subcategory_id'])) {
                    $directItems[] = $it;
                } else {
                    $nonRabItems[] = $it;
                }
            }
        ?>
        <div class="card">
            <div class="card-header <?= $selectedRequest['status'] === 'pm_approved' ? 'bg-info text-white' : ($isMixed ? 'bg-primary text-white' : ($isNonRab ? 'text-white' : 'bg-warning')) ?>" <?= $isNonRab && $selectedRequest['status'] !== 'pm_approved' ? 'style="background-color: #6f42c1;"' : '' ?>>
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 <?= ($isNonRab || $isMixed) && $selectedRequest['status'] !== 'pm_approved' ? 'text-white' : '' ?>"><?= sanitize($selectedRequest['request_number']) ?></h5>
                    <div class="d-flex gap-1 align-items-center">
                        <?php if ($isMixed): ?>
                        <span class="badge bg-light text-primary fw-bold"><i class="mdi mdi-layers-outline"></i> RAB + Non-RAB</span>
                        <?php elseif ($isNonRab): ?>
                        <span class="badge bg-light text-dark fw-bold"><i class="mdi mdi-receipt"></i> Biaya Lain-Lain</span>
                        <?php endif; ?>
                        <?php if (($selectedRequest['work_type'] ?? 'borongan') === 'harian'): ?>
                        <span class="badge bg-warning text-dark fw-bold"><i class="mdi mdi-calendar-clock"></i> HARIAN</span>
                        <?php else: ?>
                        <span class="badge bg-light text-primary fw-bold"><i class="mdi mdi-hammer-wrench"></i> BORONGAN</span>
                        <?php endif; ?>
                        <?php if ($selectedRequest['status'] === 'pm_approved'): ?>
                        <span class="badge bg-light text-info">PM Approved</span>
                        <?php endif; ?>
                        <span class="badge bg-dark"><?= sanitize($selectedRequest['project_name']) ?></span>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-3">
                        <small class="text-muted">Dibuat Oleh</small>
                        <p class="mb-0"><strong><?= sanitize($selectedRequest['created_by_name']) ?></strong></p>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted">Tanggal Input</small>
                        <p class="mb-0"><?= formatDateTime($selectedRequest['created_at'], true) ?></p>
                    </div>
                    <?php if ($isNonRab || $isMixed): ?>
                    <div class="col-md-3">
                        <small class="text-muted">Tgl Nota/Kwitansi</small>
                        <p class="mb-0"><strong><?= !empty($selectedRequest['request_date']) ? formatDate($selectedRequest['request_date']) : '-' ?></strong></p>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-3">
                        <small class="text-muted">Minggu Ke</small>
                        <p class="mb-0"><?= $selectedRequest['target_week'] ?? $selectedRequest['week_number'] ?? '-' ?></p>
                    </div>
                </div>
                
                <?php if ($selectedRequest['description']): ?>
                <div class="alert alert-light mb-3"><strong>Keterangan:</strong> <?= sanitize($selectedRequest['description']) ?></div>
                <?php endif; ?>

                <?php 
                $totalReq = 0;
                $totalRap = 0;
                $totalNonRab = 0;
                ?>

                <!-- DIRECT COST (RAB/RAP) ITEM ANALYSIS -->
                <?php if (!empty($directItems)): ?>
                <?php if (!empty($selectedPekerjaan)): ?>
                <div class="alert alert-info mb-3">
                    <h6 class="alert-heading mb-2"><i class="mdi mdi-briefcase-outline"></i> Pekerjaan yang Diajukan (RAP)</h6>
                    <ol class="mb-0 ps-3">
                        <?php foreach ($selectedPekerjaan as $pek): ?>
                        <li class="mb-1"><strong><?= sanitize($pek['code']) ?></strong> - <?= sanitize($pek['name']) ?> <small class="text-muted">(<?= sanitize($pek['category_code']) ?>. <?= sanitize($pek['category_name']) ?>)</small></li>
                        <?php endforeach; ?>
                    </ol>
                </div>
                <?php endif; ?>
                
                <h6 class="mb-3 text-primary"><i class="mdi mdi-calculator"></i> Analisis Item Biaya Langsung (RAP) <?= (($selectedRequest['work_type'] ?? 'borongan') === 'harian') ? '<span class="badge bg-warning text-dark ms-2">Metode Harian</span>' : '<span class="badge bg-info ms-2">Metode Borongan</span>' ?></h6>
                
                <?php if (($selectedRequest['work_type'] ?? 'borongan') === 'harian'): ?>
                <!-- HARIAN TABLE (10 COLUMNS AS PER STEP 3 MOCKUP) -->
                <div class="table-responsive mb-4">
                    <table class="table table-bordered table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Kode</th>
                                <th>Uraian</th>
                                <th>Jenis Item</th>
                                <th class="text-end">Vol.</th>
                                <th class="text-center">Satuan</th>
                                <th class="text-end">Harga Satuan</th>
                                <th class="text-end">Total Lapangan</th>
                                <th class="text-end">Harga RAP</th>
                                <th class="text-center">Status Harga</th>
                                <th class="text-center">Status Qty</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $harianRapSubcatsAdded = [];
                            $totalHarianRap = 0;
                            foreach ($directItems as $item): 
                                $subId = intval($item['subcategory_id'] ?? 0);
                                $iType = trim($item['item_type'] ?? 'upah');
                                $statKey = $subId . '_' . $iType;
                                $stat = $harianSubcatStats[$statKey] ?? null;
                                
                                $itemTotalLap = floatval($item['total_price'] ?: ($item['quantity'] * $item['unit_price']));
                                $totalReq += $itemTotalLap;
                                
                                $rapSubcatTotal = $stat ? $stat['rap_total_cost'] : 0;
                                if (!isset($harianRapSubcatsAdded[$statKey])) {
                                    $totalHarianRap += $rapSubcatTotal;
                                    $harianRapSubcatsAdded[$statKey] = true;
                                }
                                
                                $workVol = floatval($item['work_volume'] ?? 0);
                                $workUnit = $item['work_unit'] ?: "m'";
                                $satuan = $item['unit'] ?: ($item['work_billing_unit'] ?: 'OH');
                            ?>
                            <tr>
                                <td>
                                    <code><?= sanitize($item['item_code'] ?? '-') ?></code>
                                    <?php if (!empty($item['code'])): ?>
                                    <br><span class="badge bg-soft-primary text-primary border border-primary-subtle" style="font-size:0.7rem;"><i class="mdi mdi-briefcase-outline"></i> <?= sanitize($item['code']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= sanitize($item['item_name']) ?></strong>
                                    <?php if (!empty($item['subcategory_name'])): ?>
                                    <div class="text-muted small mt-1" style="font-size: 0.75rem;"><i class="mdi mdi-arrow-right-bottom text-primary"></i> <?= sanitize($item['code'] ? $item['code'] . ' - ' : '') ?><?= sanitize($item['subcategory_name']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($item['notes'])): ?>
                                    <small class="text-muted d-block mt-1"><?= sanitize($item['notes']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border text-capitalize"><?= sanitize($item['item_type'] ?: 'upah') ?></span>
                                </td>
                                <td class="text-end">
                                    <?= formatVolume($workVol) ?> <?= sanitize($workUnit) ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-info-subtle text-info border"><?= sanitize($satuan) ?></span>
                                </td>
                                <td class="text-end">
                                    <?= formatRupiah($item['unit_price'], false) ?>
                                </td>
                                <td class="text-end fw-bold">
                                    <?= formatRupiah($itemTotalLap, false) ?>
                                </td>
                                <td class="text-end">
                                    <?= $rapSubcatTotal > 0 ? formatRupiah($rapSubcatTotal, false) : '-' ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($stat && $rapSubcatTotal > 0): ?>
                                        <?php if ($stat['diff'] < -0.01): ?>
                                            <span class="badge bg-danger">lebih mahal <?= number_format($stat['pct'], 2, ',', '.') ?>%</span>
                                        <?php elseif ($stat['diff'] > 0.01): ?>
                                            <span class="badge bg-success">hemat +<?= number_format($stat['pct'], 2, ',', '.') ?>%</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">aman 0,00%</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($stat): ?>
                                        <?php if ($stat['remaining_vol'] <= 0): ?>
                                            <span class="badge bg-danger">0 <?= sanitize($stat['subcat_unit'] ?: 'unt') ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-success"><?= formatVolume($stat['remaining_vol']) ?> <?= sanitize($stat['subcat_unit'] ?: 'unt') ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-light">
                                <td colspan="6" class="text-end"><strong>Total Pengajuan</strong></td>
                                <td class="text-end"><strong><?= formatRupiah($totalReq) ?></strong></td>
                                <td class="text-end"><strong><?= formatRupiah($totalHarianRap) ?></strong></td>
                                <td colspan="2" class="text-center">
                                    <?php 
                                    $totalDiff = $totalHarianRap - $totalReq;
                                    $totalPct = $totalHarianRap > 0 ? ($totalDiff / $totalHarianRap) * 100 : 0;
                                    if ($totalHarianRap > 0):
                                        if ($totalDiff < -0.01): ?>
                                            <span class="text-danger fw-bold">Selisih: <?= formatRupiah($totalDiff) ?> (<?= number_format($totalPct, 2, ',', '.') ?>%)</span>
                                        <?php elseif ($totalDiff > 0.01): ?>
                                            <span class="text-success fw-bold">Selisih: +<?= formatRupiah($totalDiff) ?> (+<?= number_format($totalPct, 2, ',', '.') ?>%)</span>
                                        <?php else: ?>
                                            <span class="text-muted fw-bold">Selisih: 0 (0,00%)</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($availableRemaining['total'] > 0): ?>
                            <tr class="table-success">
                                <td colspan="6" class="text-end"><strong>Sisa Anggaran Tersedia</strong></td>
                                <td class="text-end text-success"><strong>- <?= formatRupiah($availableRemaining['total']) ?></strong></td>
                                <td colspan="3"></td>
                            </tr>
                            <tr class="table-warning">
                                <td colspan="6" class="text-end"><strong>Nett yang Harus Dikirim (RAP)</strong></td>
                                <td class="text-end"><strong><?= formatRupiah(max(0, $totalReq - $availableRemaining['total'])) ?></strong></td>
                                <td colspan="3"></td>
                            </tr>
                            <?php endif; ?>
                        </tfoot>
                    </table>
                </div>
                <?php else: ?>
                <!-- BORONGAN TABLE (EXISTING, UNTOUCHED) -->
                <div class="table-responsive mb-4">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Kode</th>
                                <th>Uraian</th>
                                <th class="text-end">Volume</th>
                                <th class="text-end">Harga Satuan</th>
                                <th class="text-end">Total Lapangan</th>
                                <th class="text-end">Harga RAP</th>
                                <th class="text-end">Selisih Harga</th>
                                <th>Status Qty</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            foreach ($directItems as $item): 
                                $hargaLapangan = $item['unit_price'] * $item['coefficient'];
                                $totalReq += $hargaLapangan;
                                
                                $hargaRap = ($item['rap_unit_price'] ?? 0) * $item['coefficient'];
                                $totalRap += $hargaRap;
                                
                                $qtyRap = $item['rap_qty'] ?? 0;
                                $remainingQty = $qtyRap - ($item['approved_qty'] ?? 0);
                                $isOverQty = $item['coefficient'] > $remainingQty && $qtyRap > 0;
                                $afterApproval = $remainingQty - $item['coefficient'];
                            ?>
                            <tr class="<?= $isOverQty ? 'table-danger' : '' ?>">
                                <td>
                                    <code><?= sanitize($item['item_code'] ?? '-') ?></code>
                                    <?php if (!empty($item['code'])): ?>
                                    <br><span class="badge bg-soft-primary text-primary border border-primary-subtle" style="font-size:0.7rem;"><i class="mdi mdi-briefcase-outline"></i> <?= sanitize($item['code']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= sanitize($item['item_name']) ?>
                                    <?php if (!empty($item['subcategory_name'])): ?>
                                    <div class="text-muted small mt-1" style="font-size: 0.75rem;"><i class="mdi mdi-arrow-right-bottom text-primary"></i> <?= sanitize($item['code'] ? $item['code'] . ' - ' : '') ?><?= sanitize($item['subcategory_name']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($item['notes']): ?>
                                    <small class="text-muted d-block mt-1"><?= sanitize($item['notes']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= formatVolume($item['coefficient']) ?></td>
                                <td class="text-end"><?= formatRupiah($item['unit_price'], false) ?></td>
                                <td class="text-end"><?= formatRupiah($hargaLapangan, false) ?></td>
                                <td class="text-end">
                                    <?= formatRupiah($hargaRap, false) ?>
                                    <?php if ($item['rap_unit_price'] > 0): ?>
                                    <br><small class="text-muted">@<?= formatRupiah($item['rap_unit_price'], false) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php 
                                    $rapUnitPrice = floatval($item['rap_unit_price'] ?? 0);
                                    if ($rapUnitPrice > 0): 
                                        $selisihItem = $hargaLapangan - $hargaRap;
                                        if ($hargaLapangan > $hargaRap): ?>
                                            <div class="text-danger fw-bold">+<?= formatRupiah($selisihItem, false) ?></div>
                                        <?php elseif ($hargaLapangan < $hargaRap): ?>
                                            <div class="text-success fw-bold">-<?= formatRupiah(abs($selisihItem), false) ?></div>
                                        <?php else: ?>
                                            <div class="text-muted fw-semibold">0,00</div>
                                        <?php endif; ?>
                                        <?= getPriceComparisonLabel($hargaLapangan, $hargaRap, true) ?>
                                    <?php else: ?>
                                        <span class="badge bg-secondary" style="font-size: 0.68rem;">-</span>
                                        <br><small class="text-muted" style="font-size: 0.75rem;">Tidak ada RAP</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($qtyRap > 0): ?>
                                        <?php if ($isOverQty): ?>
                                         <span class="badge bg-danger">⚠️ OVER QTY</span>
                                        <br><small class="text-danger">Proyeksi sisa: <?= formatVolume($afterApproval) ?></small>
                                        <?php else: ?>
                                        <span class="badge bg-success">OK</span>
                                        <br><small class="text-success">Proyeksi sisa: <?= formatVolume($afterApproval) ?></small>
                                        <?php endif; ?>
                                        <br><small class="text-muted">RAP: <?= formatVolume($qtyRap) ?> | Diajukan: <?= formatVolume($item['coefficient']) ?></small>
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
                                <td colspan="4" class="text-end"><strong>Total Item RAP</strong></td>
                                <td class="text-end"><strong><?= formatRupiah($totalReq) ?></strong></td>
                                <td class="text-end"><strong><?= formatRupiah($totalRap) ?></strong></td>
                                <td colspan="2">
                                    <?php 
                                    $diff = $totalRap - $totalReq;
                                    if ($diff > 0): ?>
                                    <span class="text-success">+<?= formatRupiah($diff) ?> (HEMAT)</span>
                                    <?php elseif ($diff < 0): ?>
                                    <span class="text-danger"><?= formatRupiah($diff) ?> (LEBIH)</span>
                                    <?php else: ?>
                                    <span class="text-muted">= SAMA</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($availableRemaining['total'] > 0): ?>
                            <tr class="table-success">
                                <td colspan="4" class="text-end"><strong>Sisa Anggaran Tersedia</strong></td>
                                <td class="text-end text-success"><strong>- <?= formatRupiah($availableRemaining['total']) ?></strong></td>
                                <td colspan="3"></td>
                            </tr>
                            <tr class="table-warning">
                                <td colspan="4" class="text-end"><strong>Nett yang Harus Dikirim (RAP)</strong></td>
                                <td class="text-end"><strong><?= formatRupiah(max(0, $totalReq - $availableRemaining['total'])) ?></strong></td>
                                <td colspan="3"></td>
                            </tr>
                            <?php endif; ?>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>

                <?php if ($availableRemaining['total'] > 0): ?>
                <div class="alert alert-success mb-3">
                    <h6 class="alert-heading mb-2"><i class="mdi mdi-cash-refund"></i> Sisa Anggaran dari Aktualisasi Sebelumnya</h6>
                    <div class="row text-center">
                        <div class="col-4">
                            <small class="text-muted d-block">Upah</small>
                            <strong><?= formatRupiah($availableRemaining['upah']) ?></strong>
                        </div>
                        <div class="col-4">
                            <small class="text-muted d-block">Material</small>
                            <strong><?= formatRupiah($availableRemaining['material']) ?></strong>
                        </div>
                        <div class="col-4">
                            <small class="text-muted d-block">Alat</small>
                            <strong><?= formatRupiah($availableRemaining['alat']) ?></strong>
                        </div>
                    </div>
                    <hr class="my-2">
                    <div class="text-center">
                        <strong>Total Sisa: <?= formatRupiah($availableRemaining['total']) ?></strong>
                        <br><small>Akan otomatis dipotong dari realisasi saat di-approve.</small>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <!-- NON-RAB ITEMS -->
                <?php if (!empty($nonRabItems)): ?>
                <h6 class="mb-3" style="color: #6f42c1;"><i class="mdi mdi-receipt"></i> Rincian Pengeluaran Biaya Non-RAB</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-bordered table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th width="40" class="text-center">No</th>
                                <th>Nama Biaya / Uraian</th>
                                <th width="90" class="text-end">Volume</th>
                                <th width="70">Satuan</th>
                                <th width="130" class="text-end">Harga Satuan</th>
                                <th width="150" class="text-end">Total</th>
                                <th width="90" class="text-center">Bukti Nota</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = 1;
                            foreach ($nonRabItems as $item): 
                                $subtotal = floatval($item['quantity'] ?: $item['coefficient'] ?: 1) * floatval($item['unit_price']);
                                $totalNonRab += $subtotal;
                            ?>
                            <tr>
                                <td class="text-center"><?= $no++ ?></td>
                                <td>
                                    <strong><?= sanitize($item['item_name']) ?></strong>
                                    <?php if (!empty($item['notes'])): ?>
                                    <br><small class="text-muted"><?= sanitize($item['notes']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= formatVolume($item['quantity'] ?: $item['coefficient'] ?: 1) ?></td>
                                <td><?= sanitize($item['unit'] ?: 'ls') ?></td>
                                <td class="text-end"><?= formatRupiah($item['unit_price'], false) ?></td>
                                <td class="text-end"><strong><?= formatRupiah($subtotal, false) ?></strong></td>
                                <td class="text-center">
                                    <?php if (!empty($item['receipt_file'])): 
                                        $fileUrl = $baseUrl . '/uploads/receipts/' . $item['receipt_file'];
                                        $ext = strtolower(pathinfo($item['receipt_file'], PATHINFO_EXTENSION));
                                    ?>
                                        <?php if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])): ?>
                                        <a href="<?= $fileUrl ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2" title="Lihat Foto Nota">
                                            <i class="mdi mdi-image"></i>
                                        </a>
                                        <?php else: ?>
                                        <a href="<?= $fileUrl ?>" target="_blank" class="btn btn-sm btn-outline-danger py-0 px-2" title="Lihat Dokumen Nota">
                                            <i class="mdi mdi-file-pdf-box"></i>
                                        </a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-primary">
                                <td colspan="5" class="text-end"><strong>TOTAL BIAYA NON-RAB</strong></td>
                                <td class="text-end"><strong><?= formatRupiah($totalNonRab) ?></strong></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>

                <?php if (!empty($directItems) && !empty($nonRabItems)): ?>
                <div class="card bg-light border-primary mb-3">
                    <div class="card-body py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <span class="text-muted me-3">Subtotal RAP: <strong><?= formatRupiah($totalReq) ?></strong></span>
                            <span class="text-muted">Subtotal Non-RAB: <strong><?= formatRupiah($totalNonRab) ?></strong></span>
                        </div>
                        <div>
                            <span class="fs-6 text-muted me-2">Grand Total Pengajuan:</span>
                            <span class="fs-5 fw-bold text-primary font-monospace"><?= formatRupiah($totalReq + $totalNonRab) ?></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <form method="POST" id="approvalForm">
                    <input type="hidden" name="request_id" value="<?= $selectedRequest['id'] ?>">
                    
                    <?php if (hasPermission('requests.approve')): ?>
                    <div class="mb-3">
                        <label class="form-label">Minggu Target</label>
                        <input type="text" class="form-control readonly-field" 
                               value="<?= $selectedRequest['target_week'] ? 'Minggu ke-' . $selectedRequest['target_week'] : 'Belum ditentukan' ?>" readonly>
                        <small class="text-muted">Minggu target ditentukan oleh pengaju saat membuat pengajuan.</small>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label"><?= hasPermission('projects.edit') ? 'Catatan Admin' : 'Catatan PM' ?></label>
                        <textarea class="form-control" name="notes" rows="2" id="adminNotes"
                                  placeholder="Catatan (opsional untuk approve, wajib untuk reject)"></textarea>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" name="action" value="reject" class="btn btn-danger" 
                                onclick="return validateReject()">
                            <i class="mdi mdi-close"></i> Reject
                        </button>
                        <?php if (!hasPermission('projects.edit') && hasPermission('requests.approve') && $selectedRequest['status'] === 'pending'): ?>
                        <button type="submit" name="action" value="approve" class="btn btn-info"
                                onclick="return confirm('Setujui pengajuan ini? Akan diteruskan ke Admin untuk approval final.')">
                            <i class="mdi mdi-check"></i> Approve (PM Review)
                        </button>
                        <?php elseif (hasPermission('projects.edit') && in_array($selectedRequest['status'], ['pm_approved', 'pending'])): ?>
                        <button type="submit" name="action" value="approve" class="btn btn-success"
                                onclick="return validateApprove('<?= $selectedRequest['status'] ?>')">
                            <i class="mdi mdi-check-all"></i> Final Approve
                        </button>
                        <?php endif; ?>
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
// Check if there's an approved project to show navigation modal
$approvedProjectId = $_SESSION['approved_project_id'] ?? null;
$approvedProjectName = $_SESSION['approved_project_name'] ?? '';
unset($_SESSION['approved_project_id'], $_SESSION['approved_project_name']);
?>

<!-- Approval Success Navigation Modal -->
<?php if ($approvedProjectId): ?>
<div class="modal fade" id="approvalSuccessModal" tabindex="-1" aria-labelledby="approvalSuccessModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="approvalSuccessModalLabel">
                    <i class="mdi mdi-check-circle-outline"></i> Approve Berhasil!
                </h5>
            </div>
            <div class="modal-body text-center py-4">
                <div class="mb-3">
                    <i class="mdi mdi-check-decagram text-success" style="font-size: 4rem;"></i>
                </div>
                <h5 class="mb-2">Pengajuan berhasil disetujui!</h5>
                <p class="text-muted mb-0">Realisasi telah masuk ke tabel mingguan proyek:</p>
                <p class="fw-bold"><?= sanitize($approvedProjectName) ?></p>
                <p class="text-muted">Anda ingin melihat tabel realisasi atau tetap di Approval Center?</p>
            </div>
            <div class="modal-footer justify-content-center">
                <a href="../projects/view.php?id=<?= $approvedProjectId ?>&tab=actual" class="btn btn-success btn-lg">
                    <i class="mdi mdi-table-eye"></i> Lihat Realisasi
                </a>
                <button type="button" class="btn btn-outline-secondary btn-lg" data-bs-dismiss="modal">
                    <i class="mdi mdi-clipboard-check-outline"></i> Tetap di Approval
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php 
ob_start();
?>
<script>
function submitApproveForm(weekText) {
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
}

function validateApprove(requestStatus) {
    var weekInfo = '<?= $selectedRequest['target_week'] ?? '' ?>';
    var weekText = weekInfo ? 'Minggu ke-' + weekInfo : '';
    
    if (requestStatus === 'pending') {
        // Show warning modal: request not yet verified by PM
        confirmAction(function() {
            // After user confirms warning, proceed to approval confirmation
            submitApproveForm(weekText);
        }, {
            title: '⚠️ Peringatan: Belum Diverifikasi PM',
            message: 'Pengajuan ini <strong>BELUM DIVERIFIKASI</strong> oleh Project Manager.<br><br>' +
                     'Apakah Anda yakin ingin langsung meng-approve tanpa menunggu verifikasi PM?',
            buttonText: 'Ya, Lanjutkan Approve',
            buttonClass: 'btn-warning'
        });
    } else {
        // pm_approved: proceed directly to approval confirmation
        submitApproveForm(weekText);
    }
    
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

// Auto-show approval success modal
<?php if ($approvedProjectId): ?>
$(document).ready(function() {
    var modal = new bootstrap.Modal(document.getElementById('approvalSuccessModal'));
    modal.show();
});
<?php endif; ?>
</script>
<?php
$extraScripts = ob_get_clean();
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
