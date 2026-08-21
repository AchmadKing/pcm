<?php
/**
 * Master Data POST Handlers (Early execution)
 * This file is included from view.php BEFORE HTML output
 * Handles all Master Data actions for both RAB and RAP
 * Variables available: $projectId, $project (from view.php)
 */

// Only proceed if admin and project is editable
if (!function_exists('hasPermission') || !hasPermission('master_data.edit')) {
    return;
}

$isEditable = ($project['status'] === 'draft');
if (!$isEditable) {
    return;
}

$action = $_POST['action'] ?? '';

// Define all master data actions that should be handled early with redirect
$masterDataActions = [
    'add_item', 'update_item', 'delete_item', 'clear_all_items',
    'add_item_rap', 'update_item_rap', 'delete_item_rap', 'clear_all_items_rap',
    'add_ahsp', 'update_ahsp', 'delete_ahsp', 'clear_all_ahsp',
    'add_ahsp_rap', 'update_ahsp_rap', 'delete_ahsp_rap', 'clear_all_ahsp_rap',
    'add_ahsp_detail', 'update_ahsp_detail', 'delete_ahsp_detail',
    'add_ahsp_detail_rap', 'update_ahsp_detail_rap', 'delete_ahsp_detail_rap'
];

if (!in_array($action, $masterDataActions)) {
    return;
}

try {
    switch ($action) {
        // ============================================================
        // RAB ITEMS
        // ============================================================
        case 'add_item':
            $itemCode = trim($_POST['item_code'] ?? '');
            $name = trim($_POST['item_name'] ?? '');
            $brand = trim($_POST['item_brand'] ?? '');
            $category = $_POST['item_category'] ?? '';
            $unit = trim($_POST['item_unit'] ?? '');
            $priceRaw = $_POST['item_price'] ?? '0';
            $price = floatval(str_replace(',', '.', str_replace('.', '', $priceRaw)));
            $actualPriceRaw = $_POST['item_actual_price'] ?? '';
            $actualPrice = !empty($actualPriceRaw) ? floatval(str_replace(',', '.', str_replace('.', '', $actualPriceRaw))) : null;
            
            if (!empty($itemCode) && !empty($name) && !empty($category) && !empty($unit)) {
                if (isItemCodeDuplicate($projectId, $itemCode)) {
                    setFlash('error', 'Kode item "' . $itemCode . '" sudah digunakan dalam proyek ini!');
                } else {
                    $rabItemId = dbInsert("INSERT INTO project_items (project_id, item_code, name, brand, category, unit, price, actual_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        [$projectId, $itemCode, $name, $brand ?: null, $category, $unit, $price, $actualPrice]);
                    syncItemCodeRabToRap($projectId, $itemCode);
                    setFlash('success', 'Item berhasil ditambahkan dan disinkronkan ke RAP!');
                }
            } else {
                setFlash('error', 'Kode item, nama, kategori, dan satuan wajib diisi!');
            }
            break;
            
        case 'update_item':
            $itemId = $_POST['item_id'];
            $itemCode = trim($_POST['item_code'] ?? '');
            $name = trim($_POST['item_name'] ?? '');
            $brand = trim($_POST['item_brand'] ?? '');
            $category = $_POST['item_category'] ?? '';
            $unit = trim($_POST['item_unit'] ?? '');
            $priceRaw = $_POST['item_price'] ?? '0';
            $price = floatval(str_replace(',', '.', str_replace('.', '', $priceRaw)));
            $actualPriceRaw = $_POST['item_actual_price'] ?? '';
            $actualPrice = !empty($actualPriceRaw) ? floatval(str_replace(',', '.', str_replace('.', '', $actualPriceRaw))) : null;
            
            if (!empty($itemCode) && !empty($name) && !empty($category) && !empty($unit)) {
                $matchingRap = dbGetRow("SELECT id FROM project_items_rap WHERE project_id = ? AND rab_item_id = ?", [$projectId, $itemId]);
                $matchingRapId = $matchingRap ? $matchingRap['id'] : null;
                
                if (isItemCodeDuplicate($projectId, $itemCode, $itemId, $matchingRapId)) {
                    setFlash('error', 'Kode item "' . $itemCode . '" sudah digunakan!');
                } else {
                    dbExecute("UPDATE project_items SET item_code = ?, name = ?, brand = ?, category = ?, unit = ?, price = ?, actual_price = ? WHERE id = ? AND project_id = ?",
                        [$itemCode, $name, $brand ?: null, $category, $unit, $price, $actualPrice, $itemId, $projectId]);
                    
                    syncEditItemRabToRap($itemId, $itemCode, $name, $brand, $category, $unit, $projectId);
                    syncItemToAhsp($itemId, $projectId);
                    setFlash('success', 'Item berhasil diperbarui dan disinkronkan ke RAP!');
                }
            } else {
                setFlash('error', 'Kode item, nama, kategori, dan satuan wajib diisi!');
            }
            break;
            
        case 'delete_item':
            $itemId = $_POST['item_id'];
            syncDeleteItemRabToRap($itemId, $projectId);
            setFlash('success', 'Item berhasil dihapus dari RAB dan RAP!');
            break;
            
        case 'clear_all_items':
            dbExecute("
                DELETE d FROM project_ahsp_details d
                INNER JOIN project_items i ON d.item_id = i.id
                WHERE i.project_id = ?
            ", [$projectId]);
            
            dbExecute("DELETE FROM project_items WHERE project_id = ?", [$projectId]);
            dbExecute("UPDATE project_ahsp SET unit_price = 0 WHERE project_id = ?", [$projectId]);
            
            dbExecute("
                DELETE d FROM project_ahsp_details_rap d
                INNER JOIN project_ahsp_rap a ON d.ahsp_id = a.id
                WHERE a.project_id = ?
            ", [$projectId]);
            
            dbExecute("DELETE FROM project_items_rap WHERE project_id = ?", [$projectId]);
            dbExecute("UPDATE project_ahsp_rap SET unit_price = 0 WHERE project_id = ?", [$projectId]);
            
            setFlash('success', "Semua items RAB dan RAP berhasil dihapus!");
            break;
            
        // ============================================================
        // RAP ITEMS
        // ============================================================
        case 'add_item_rap':
            $itemCode = trim($_POST['item_code'] ?? '');
            $name = trim($_POST['item_name'] ?? '');
            $brand = trim($_POST['item_brand'] ?? '');
            $category = $_POST['item_category'] ?? '';
            $unit = trim($_POST['item_unit'] ?? '');
            $priceRaw = $_POST['item_price'] ?? '0';
            $price = floatval(str_replace(',', '.', str_replace('.', '', $priceRaw)));
            $actualPriceRaw = $_POST['item_actual_price'] ?? '';
            $actualPrice = !empty($actualPriceRaw) ? floatval(str_replace(',', '.', str_replace('.', '', $actualPriceRaw))) : null;
            
            if (!empty($itemCode) && !empty($name) && !empty($category) && !empty($unit)) {
                if (isItemCodeDuplicate($projectId, $itemCode)) {
                    setFlash('error', 'Kode item "' . $itemCode . '" sudah digunakan dalam proyek ini!');
                } else {
                    $rabItem = dbGetRow("SELECT id FROM project_items WHERE project_id = ? AND item_code = ?", [$projectId, $itemCode]);
                    $rabItemId = $rabItem ? $rabItem['id'] : null;
                    
                    dbInsert("INSERT INTO project_items_rap (project_id, item_code, name, brand, category, unit, price, actual_price, rab_item_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$projectId, $itemCode, $name, $brand ?: null, $category, $unit, $price, $actualPrice, $rabItemId]);
                    syncItemCodeRapToRab($projectId, $itemCode);
                    setFlash('success', 'Item RAP berhasil ditambahkan dan disinkronkan ke RAB!');
                }
            } else {
                setFlash('error', 'Kode item, nama, kategori, dan satuan wajib diisi!');
            }
            break;
            
        case 'update_item_rap':
            $itemId = $_POST['item_id'];
            $itemCode = trim($_POST['item_code'] ?? '');
            $name = trim($_POST['item_name'] ?? '');
            $brand = trim($_POST['item_brand'] ?? '');
            $category = $_POST['item_category'] ?? '';
            $unit = trim($_POST['item_unit'] ?? '');
            $priceRaw = $_POST['item_price'] ?? '0';
            $price = floatval(str_replace(',', '.', str_replace('.', '', $priceRaw)));
            $actualPriceRaw = $_POST['item_actual_price'] ?? '';
            $actualPrice = !empty($actualPriceRaw) ? floatval(str_replace(',', '.', str_replace('.', '', $actualPriceRaw))) : null;
            
            if (!empty($itemCode) && !empty($name) && !empty($category) && !empty($unit)) {
                $rapItem = dbGetRow("SELECT rab_item_id FROM project_items_rap WHERE id = ?", [$itemId]);
                $matchingRabId = $rapItem ? $rapItem['rab_item_id'] : null;
                
                if (isItemCodeDuplicate($projectId, $itemCode, $matchingRabId, $itemId)) {
                    setFlash('error', 'Kode item "' . $itemCode . '" sudah digunakan!');
                } else {
                    dbExecute("UPDATE project_items_rap SET item_code = ?, name = ?, brand = ?, category = ?, unit = ?, price = ?, actual_price = ? WHERE id = ? AND project_id = ?",
                        [$itemCode, $name, $brand ?: null, $category, $unit, $price, $actualPrice, $itemId, $projectId]);
                    
                    syncEditItemRapToRab($itemId, $itemCode, $name, $brand, $category, $unit, $projectId);
                    syncRapItemToAhsp($itemId, $projectId);
                    setFlash('success', 'Item RAP berhasil diperbarui dan disinkronkan ke RAB!');
                }
            } else {
                setFlash('error', 'Kode item, nama, kategori, dan satuan wajib diisi!');
            }
            break;
            
        case 'delete_item_rap':
            $itemId = $_POST['item_id'];
            syncDeleteItemRapToRab($itemId, $projectId);
            setFlash('success', 'Item RAP berhasil dihapus dari RAP dan RAB!');
            break;
            
        case 'clear_all_items_rap':
            dbExecute("
                DELETE d FROM project_ahsp_details_rap d
                INNER JOIN project_ahsp_rap a ON d.ahsp_id = a.id
                WHERE a.project_id = ?
            ", [$projectId]);
            
            dbExecute("DELETE FROM project_items_rap WHERE project_id = ?", [$projectId]);
            dbExecute("UPDATE project_ahsp_rap SET unit_price = 0 WHERE project_id = ?", [$projectId]);
            
            dbExecute("
                DELETE d FROM project_ahsp_details d
                INNER JOIN project_items i ON d.item_id = i.id
                WHERE i.project_id = ?
            ", [$projectId]);
            
            dbExecute("DELETE FROM project_items WHERE project_id = ?", [$projectId]);
            dbExecute("UPDATE project_ahsp SET unit_price = 0 WHERE project_id = ?", [$projectId]);
            
            setFlash('success', "Semua items RAP dan RAB berhasil dihapus!");
            break;
            
        // ============================================================
        // RAB AHSP
        // ============================================================
        case 'add_ahsp':
            $ahspCode = trim($_POST['ahsp_code'] ?? '');
            $workName = trim($_POST['work_name'] ?? '');
            $unit = trim($_POST['ahsp_unit'] ?? '');
            
            if (!empty($ahspCode) && !empty($workName) && !empty($unit)) {
                if (isAhspCodeDuplicate($projectId, $ahspCode)) {
                    setFlash('error', 'Kode AHSP "' . $ahspCode . '" sudah digunakan dalam proyek ini!');
                } else {
                    dbInsert("INSERT INTO project_ahsp (project_id, ahsp_code, work_name, unit, unit_price) VALUES (?, ?, ?, ?, 0)",
                        [$projectId, $ahspCode, $workName, $unit]);
                    syncAhspCodeRabToRap($projectId, $ahspCode);
                    setFlash('success', 'AHSP berhasil ditambahkan dan disinkronkan ke RAP!');
                }
            } else {
                setFlash('error', 'Kode AHSP, nama pekerjaan, dan satuan harus diisi!');
            }
            break;
            
        case 'update_ahsp':
            $ahspId = $_POST['ahsp_id'];
            $ahspCode = trim($_POST['ahsp_code'] ?? '');
            $workName = trim($_POST['work_name'] ?? '');
            $unit = trim($_POST['ahsp_unit'] ?? '');
            
            if (!empty($ahspCode)) {
                $oldAhsp = dbGetRow("SELECT ahsp_code FROM project_ahsp WHERE id = ?", [$ahspId]);
                $oldCode = $oldAhsp ? $oldAhsp['ahsp_code'] : $ahspCode;
                $matchingRap = dbGetRow("SELECT id FROM project_ahsp_rap WHERE project_id = ? AND (rab_ahsp_id = ? OR LOWER(ahsp_code) = LOWER(?))", [$projectId, $ahspId, $oldCode]);
                $matchingRapId = $matchingRap ? $matchingRap['id'] : null;
                
                if (isAhspCodeDuplicate($projectId, $ahspCode, $ahspId, $matchingRapId)) {
                    setFlash('error', 'Kode AHSP "' . $ahspCode . '" sudah digunakan!');
                    break;
                }
            }
            
            syncEditAhspRabToRap($ahspId, $ahspCode, $workName, $unit, $projectId);
            dbExecute("UPDATE project_ahsp SET ahsp_code = ?, work_name = ?, unit = ? WHERE id = ? AND project_id = ?",
                [$ahspCode, $workName, $unit, $ahspId, $projectId]);
            syncAhspToRab($ahspId);
            setFlash('success', 'AHSP berhasil diperbarui! Perubahan disinkronkan ke RAB dan RAP.');
            break;
            
        case 'delete_ahsp':
            $ahspId = $_POST['ahsp_id'];
            syncDeleteAhspRabToRap($ahspId, $projectId);
            
            dbExecute("
                DELETE rad FROM rap_ahsp_details rad
                INNER JOIN rap_items rap ON rad.rap_item_id = rap.id
                INNER JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
                WHERE rs.ahsp_id = ?
            ", [$ahspId]);
            
            dbExecute("
                DELETE rap FROM rap_items rap
                INNER JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
                WHERE rs.ahsp_id = ?
            ", [$ahspId]);
            
            dbExecute("DELETE FROM rab_subcategories WHERE ahsp_id = ?", [$ahspId]);
            dbExecute("DELETE FROM project_ahsp_details WHERE ahsp_id = ?", [$ahspId]);
            dbExecute("DELETE FROM project_ahsp WHERE id = ? AND project_id = ?", [$ahspId, $projectId]);
            setFlash('success', 'AHSP RAB dan RAP berhasil dihapus!');
            break;
            
        case 'clear_all_ahsp':
            dbExecute("
                DELETE rad FROM rap_ahsp_details rad
                INNER JOIN rap_items rap ON rad.rap_item_id = rap.id
                INNER JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
                INNER JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                WHERE pa.project_id = ?
            ", [$projectId]);
            
            dbExecute("
                DELETE rap FROM rap_items rap
                INNER JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
                INNER JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                WHERE pa.project_id = ?
            ", [$projectId]);
            
            dbExecute("
                DELETE rs FROM rab_subcategories rs
                INNER JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                WHERE pa.project_id = ?
            ", [$projectId]);
            
            dbExecute("
                DELETE d FROM project_ahsp_details d
                INNER JOIN project_ahsp a ON d.ahsp_id = a.id
                WHERE a.project_id = ?
            ", [$projectId]);
            
            dbExecute("DELETE FROM project_ahsp WHERE project_id = ?", [$projectId]);
            
            dbExecute("
                DELETE d FROM project_ahsp_details_rap d
                INNER JOIN project_ahsp_rap a ON d.ahsp_id = a.id
                WHERE a.project_id = ?
            ", [$projectId]);
            
            dbExecute("DELETE FROM project_ahsp_rap WHERE project_id = ?", [$projectId]);
            
            setFlash('success', "Semua AHSP RAB dan RAP berhasil dihapus!");
            break;
            
        // ============================================================
        // RAP AHSP
        // ============================================================
        case 'add_ahsp_rap':
            $ahspCode = trim($_POST['ahsp_code'] ?? '');
            $workName = trim($_POST['work_name'] ?? '');
            $unit = trim($_POST['ahsp_unit'] ?? '');
            
            if (!empty($ahspCode) && !empty($workName) && !empty($unit)) {
                if (isAhspCodeDuplicate($projectId, $ahspCode)) {
                    setFlash('error', 'Kode AHSP "' . $ahspCode . '" sudah digunakan dalam proyek ini!');
                } else {
                    $rabAhsp = dbGetRow("SELECT id FROM project_ahsp WHERE project_id = ? AND ahsp_code = ?", 
                        [$projectId, $ahspCode]);
                    $rabAhspId = $rabAhsp ? $rabAhsp['id'] : null;
                    
                    dbInsert("INSERT INTO project_ahsp_rap (project_id, ahsp_code, work_name, unit, unit_price, rab_ahsp_id) VALUES (?, ?, ?, ?, 0, ?)",
                        [$projectId, $ahspCode, $workName, $unit, $rabAhspId]);
                    syncAhspCodeRapToRab($projectId, $ahspCode);
                    setFlash('success', 'AHSP RAP berhasil ditambahkan dan disinkronkan ke RAB!');
                }
            } else {
                setFlash('error', 'Kode AHSP, nama pekerjaan, dan satuan harus diisi!');
            }
            break;
            
        case 'update_ahsp_rap':
            $ahspId = $_POST['ahsp_id'];
            $ahspCode = trim($_POST['ahsp_code'] ?? '');
            $workName = trim($_POST['work_name'] ?? '');
            $unit = trim($_POST['ahsp_unit'] ?? '');
            
            if (!empty($ahspCode)) {
                $oldRapAhsp = dbGetRow("SELECT rab_ahsp_id, ahsp_code FROM project_ahsp_rap WHERE id = ?", [$ahspId]);
                $oldCode = $oldRapAhsp ? $oldRapAhsp['ahsp_code'] : $ahspCode;
                $matchingRabId = $oldRapAhsp ? $oldRapAhsp['rab_ahsp_id'] : null;
                if (!$matchingRabId) {
                    $rab = dbGetRow("SELECT id FROM project_ahsp WHERE project_id = ? AND LOWER(ahsp_code) = LOWER(?)", [$projectId, $oldCode]);
                    $matchingRabId = $rab ? $rab['id'] : null;
                }
                
                if (isAhspCodeDuplicate($projectId, $ahspCode, $matchingRabId, $ahspId)) {
                    setFlash('error', 'Kode AHSP "' . $ahspCode . '" sudah digunakan!');
                    break;
                }
            }
            
            syncEditAhspRapToRab($ahspId, $ahspCode, $workName, $unit, $projectId);
            dbExecute("UPDATE project_ahsp_rap SET ahsp_code = ?, work_name = ?, unit = ? WHERE id = ? AND project_id = ?",
                [$ahspCode, $workName, $unit, $ahspId, $projectId]);
            setFlash('success', 'AHSP RAP berhasil diperbarui! Perubahan disinkronkan ke RAB.');
            break;
            
        case 'delete_ahsp_rap':
            $ahspId = $_POST['ahsp_id'];
            syncDeleteAhspRapToRab($ahspId, $projectId);
            dbExecute("DELETE FROM project_ahsp_details_rap WHERE ahsp_id = ?", [$ahspId]);
            dbExecute("DELETE FROM project_ahsp_rap WHERE id = ? AND project_id = ?", [$ahspId, $projectId]);
            setFlash('success', 'AHSP RAP dan RAB berhasil dihapus!');
            break;
            
        case 'clear_all_ahsp_rap':
            dbExecute("
                DELETE d FROM project_ahsp_details_rap d
                INNER JOIN project_ahsp_rap a ON d.ahsp_id = a.id
                WHERE a.project_id = ?
            ", [$projectId]);
            
            dbExecute("DELETE FROM project_ahsp_rap WHERE project_id = ?", [$projectId]);
            
            dbExecute("
                DELETE rad FROM rap_ahsp_details rad
                INNER JOIN rap_items rap ON rad.rap_item_id = rap.id
                INNER JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
                INNER JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                WHERE pa.project_id = ?
            ", [$projectId]);
            
            dbExecute("
                DELETE rap FROM rap_items rap
                INNER JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
                INNER JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                WHERE pa.project_id = ?
            ", [$projectId]);
            
            dbExecute("
                DELETE rs FROM rab_subcategories rs
                INNER JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                WHERE pa.project_id = ?
            ", [$projectId]);
            
            dbExecute("
                DELETE d FROM project_ahsp_details d
                INNER JOIN project_ahsp a ON d.ahsp_id = a.id
                WHERE a.project_id = ?
            ", [$projectId]);
            
            dbExecute("DELETE FROM project_ahsp WHERE project_id = ?", [$projectId]);
            setFlash('success', "Semua AHSP RAP dan RAB berhasil dihapus!");
            break;
            
        // ============================================================
        // AHSP DETAILS (RAB & RAP)
        // ============================================================
        case 'add_ahsp_detail':
            $ahspId = intval($_POST['ahsp_id'] ?? 0);
            $selectedItems = $_POST['selected_items'] ?? [];
            if (!is_array($selectedItems) && !empty($_POST['detail_item_id'])) {
                $selectedItems = [$_POST['detail_item_id']];
            }
            
            $globalCoeffRaw = $_POST['coefficient'] ?? '1';
            $globalCoeff = floatval(str_replace(',', '.', str_replace('.', '', $globalCoeffRaw)));
            if ($globalCoeff <= 0) $globalCoeff = 1.0;
            
            $coefficients = $_POST['coefficients'] ?? [];
            $addedCount = 0;
            
            if ($ahspId > 0 && !empty($selectedItems)) {
                foreach ($selectedItems as $itemId) {
                    $itemId = intval($itemId);
                    if ($itemId <= 0) continue;
                    
                    $coeffRaw = $coefficients[$itemId] ?? $globalCoeffRaw;
                    $coefficient = floatval(str_replace(',', '.', str_replace('.', '', (string)$coeffRaw)));
                    if ($coefficient <= 0) $coefficient = $globalCoeff;
                    
                    dbInsert("INSERT INTO project_ahsp_details (ahsp_id, item_id, coefficient) VALUES (?, ?, ?)",
                        [$ahspId, $itemId, $coefficient]);
                    syncAddComponentRabToRap($ahspId, $itemId, $coefficient, $projectId);
                    $addedCount++;
                }
            }
            
            if ($addedCount > 0) {
                recalculateAhspPrice($ahspId);
                setFlash('success', "$addedCount komponen berhasil ditambahkan dan disinkronkan ke RAP!");
            } else {
                setFlash('error', 'Pilih minimal satu komponen dengan koefisien yang valid!');
            }
            break;
            
        case 'update_ahsp_detail':
            $detailId = $_POST['detail_id'];
            $ahspId = $_POST['ahsp_id'];
            $coeffRaw = $_POST['coefficient'] ?? '';
            $coefficient = floatval(str_replace(',', '.', str_replace('.', '', $coeffRaw)));
            
            $unitPriceRaw = $_POST['unit_price'] ?? '';
            $unitPrice = null;
            if (!empty($unitPriceRaw)) {
                if (strpos($unitPriceRaw, '.') !== false && strpos($unitPriceRaw, ',') !== false) {
                    $unitPrice = floatval(str_replace(',', '.', str_replace('.', '', $unitPriceRaw)));
                } elseif (strpos($unitPriceRaw, '.') !== false && strlen($unitPriceRaw) - strrpos($unitPriceRaw, '.') == 4) {
                    $unitPrice = floatval(str_replace('.', '', $unitPriceRaw));
                } else {
                    $unitPrice = floatval(str_replace(',', '.', $unitPriceRaw));
                }
            }
            
            if (empty($coeffRaw) || $coefficient > 0) {
                if (empty($coeffRaw)) {
                    $current = dbGetRow("SELECT coefficient FROM project_ahsp_details WHERE id = ?", [$detailId]);
                    $coefficient = $current['coefficient'] ?? 1;
                }
                $detailInfo = dbGetRow("SELECT item_id FROM project_ahsp_details WHERE id = ?", [$detailId]);
                dbExecute("UPDATE project_ahsp_details SET coefficient = ?, unit_price = ? WHERE id = ?",
                    [$coefficient, $unitPrice, $detailId]);
                recalculateAhspPrice($ahspId);
                if ($detailInfo) {
                    syncCoefficientRabToRap($ahspId, $detailInfo['item_id'], $coefficient, $projectId);
                }
                setFlash('success', 'Komponen berhasil diperbarui dan disinkronkan ke RAP!');
            }
            break;
            
        case 'delete_ahsp_detail':
            $detailId = $_POST['detail_id'];
            $ahspId = $_POST['ahsp_id'];
            syncDeleteComponentRabToRap($detailId, $ahspId, $projectId);
            dbExecute("DELETE FROM project_ahsp_details WHERE id = ?", [$detailId]);
            recalculateAhspPrice($ahspId);
            setFlash('success', 'Komponen berhasil dihapus dari RAB dan RAP!');
            break;
            
        case 'add_ahsp_detail_rap':
            $ahspId = intval($_POST['ahsp_id'] ?? 0);
            $selectedItems = $_POST['selected_items'] ?? [];
            if (!is_array($selectedItems) && !empty($_POST['detail_item_id'])) {
                $selectedItems = [$_POST['detail_item_id']];
            }
            
            $globalCoeffRaw = $_POST['coefficient'] ?? '1';
            $globalCoeff = floatval(str_replace(',', '.', str_replace('.', '', $globalCoeffRaw)));
            if ($globalCoeff <= 0) $globalCoeff = 1.0;
            
            $coefficients = $_POST['coefficients'] ?? [];
            $unitPrices = $_POST['unit_prices'] ?? [];
            $addedCount = 0;
            
            if ($ahspId > 0 && !empty($selectedItems)) {
                foreach ($selectedItems as $itemId) {
                    $itemId = intval($itemId);
                    if ($itemId <= 0) continue;
                    
                    $coeffRaw = $coefficients[$itemId] ?? $globalCoeffRaw;
                    $coefficient = floatval(str_replace(',', '.', str_replace('.', '', (string)$coeffRaw)));
                    if ($coefficient <= 0) $coefficient = $globalCoeff;
                    
                    $unitPriceRaw = $unitPrices[$itemId] ?? ($_POST['detail_unit_price'] ?? '');
                    $unitPrice = !empty($unitPriceRaw) ? floatval(str_replace(',', '.', str_replace('.', '', (string)$unitPriceRaw))) : null;
                    
                    dbInsert("INSERT INTO project_ahsp_details_rap (ahsp_id, item_id, coefficient, unit_price) VALUES (?, ?, ?, ?)",
                        [$ahspId, $itemId, $coefficient, $unitPrice]);
                    syncAddComponentRapToRab($ahspId, $itemId, $coefficient, $projectId);
                    $addedCount++;
                }
            }
            
            if ($addedCount > 0) {
                recalculateRapAhspPrice($ahspId);
                setFlash('success', "$addedCount komponen berhasil ditambahkan dan disinkronkan ke RAB!");
            } else {
                setFlash('error', 'Pilih minimal satu komponen dengan koefisien yang valid!');
            }
            break;
            
        case 'update_ahsp_detail_rap':
            $detailId = $_POST['detail_id'];
            $ahspId = $_POST['ahsp_id'];
            $coeffRaw = $_POST['coefficient'] ?? '';
            $coefficient = floatval(str_replace(',', '.', str_replace('.', '', $coeffRaw)));
            
            if (empty($coeffRaw) || $coefficient > 0) {
                if (empty($coeffRaw)) {
                    $current = dbGetRow("SELECT coefficient FROM project_ahsp_details_rap WHERE id = ?", [$detailId]);
                    $coefficient = $current['coefficient'] ?? 1;
                }
                $detailInfo = dbGetRow("SELECT item_id FROM project_ahsp_details_rap WHERE id = ?", [$detailId]);
                dbExecute("UPDATE project_ahsp_details_rap SET coefficient = ? WHERE id = ?",
                    [$coefficient, $detailId]);
                recalculateRapAhspPrice($ahspId);
                if ($detailInfo) {
                    syncCoefficientRapToRab($ahspId, $detailInfo['item_id'], $coefficient, $projectId);
                }
                setFlash('success', 'Komponen berhasil diperbarui dan disinkronkan ke RAB!');
            }
            break;
            
        case 'delete_ahsp_detail_rap':
            $detailId = $_POST['detail_id'];
            $ahspId = $_POST['ahsp_id'];
            syncDeleteComponentRapToRab($detailId, $ahspId, $projectId);
            dbExecute("DELETE FROM project_ahsp_details_rap WHERE id = ?", [$detailId]);
            recalculateRapAhspPrice($ahspId);
            setFlash('success', 'Komponen berhasil dihapus dari RAP dan RAB!');
            break;
    }
} catch (Exception $e) {
    setFlash('error', 'Error: ' . $e->getMessage());
}

// Determine correct subtab based on action
$subtab = '&subtab=items';
if (strpos($action, 'ahsp_rap') !== false || strpos($action, 'ahsp_detail_rap') !== false) {
    $subtab = '&subtab=ahsp_rap';
} elseif (strpos($action, 'item_rap') !== false || strpos($action, 'items_rap') !== false) {
    $subtab = '&subtab=items_rap';
} elseif (strpos($action, 'ahsp') !== false || strpos($action, 'ahsp_detail') !== false) {
    $subtab = '&subtab=ahsp';
}

$ahspParam = '';
if (!empty($_POST['ahsp_id'])) {
    $ahspParam = '&ahsp_id=' . intval($_POST['ahsp_id']);
}

header('Location: view.php?id=' . $projectId . '&tab=master' . $subtab . $ahspParam);
exit;
