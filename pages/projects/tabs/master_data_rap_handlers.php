<?php
/**
 * Master Data RAP Handlers
 * Handles Items RAP and AHSP RAP operations
 * This file should be included in master_data.php after the RAB handlers
 */

// Initialize RAP Master Data if not done yet
if (!isset($rapInitialized)) {
    initRapMasterData($projectId);
    $rapInitialized = true;
}

// ============================================================
// RAP ITEM HANDLERS
// ============================================================
if ($action === 'add_item_rap') {
    $itemCode = trim($_POST['item_code'] ?? '');
    $name = trim($_POST['item_name']);
    $brand = trim($_POST['item_brand'] ?? '');
    $category = $_POST['item_category'];
    $unit = trim($_POST['item_unit']);
    $priceRaw = $_POST['item_price'];
    $price = floatval(str_replace(',', '.', str_replace('.', '', $priceRaw)));
    $actualPriceRaw = $_POST['item_actual_price'] ?? '';
    $actualPrice = !empty($actualPriceRaw) ? floatval(str_replace(',', '.', str_replace('.', '', $actualPriceRaw))) : null;
    
    if (!empty($itemCode) && !empty($name) && !empty($category) && !empty($unit)) {
        // Check for duplicate item_code in RAP
        $existing = dbGetRow("SELECT id FROM project_items_rap WHERE project_id = ? AND item_code = ?", 
            [$projectId, $itemCode]);
        if ($existing) {
            setFlash('error', 'Kode item "' . $itemCode . '" sudah digunakan di Master Data RAP!');
        } else {
            // Find corresponding RAB item
            $rabItem = dbGetRow("SELECT id FROM project_items WHERE project_id = ? AND item_code = ?", 
                [$projectId, $itemCode]);
            $rabItemId = $rabItem ? $rabItem['id'] : null;
            
            dbInsert("INSERT INTO project_items_rap (project_id, item_code, name, brand, category, unit, price, actual_price, rab_item_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$projectId, $itemCode, $name, $brand ?: null, $category, $unit, $price, $actualPrice, $rabItemId]);
            
            // Sync to RAB if code doesn't exist there
            syncItemCodeRapToRab($projectId, $itemCode);
            
            setFlash('success', 'Item RAP berhasil ditambahkan!');
        }
    } else {
        setFlash('error', 'Kode item, nama, kategori, dan satuan wajib diisi!');
    }
}

if ($action === 'update_item_rap') {
    $itemId = $_POST['item_id'];
    $itemCode = trim($_POST['item_code'] ?? '');
    $name = trim($_POST['item_name']);
    $brand = trim($_POST['item_brand'] ?? '');
    $category = $_POST['item_category'];
    $unit = trim($_POST['item_unit']);
    $priceRaw = $_POST['item_price'];
    $price = floatval(str_replace(',', '.', str_replace('.', '', $priceRaw)));
    $actualPriceRaw = $_POST['item_actual_price'] ?? '';
    $actualPrice = !empty($actualPriceRaw) ? floatval(str_replace(',', '.', str_replace('.', '', $actualPriceRaw))) : null;
    
    dbExecute("UPDATE project_items_rap SET item_code = ?, name = ?, brand = ?, category = ?, unit = ?, price = ?, actual_price = ? WHERE id = ? AND project_id = ?",
        [$itemCode, $name, $brand ?: null, $category, $unit, $price, $actualPrice, $itemId, $projectId]);
    
    // Sync price changes to all RAP AHSP that use this item
    syncRapItemToAhsp($itemId, $projectId);
    
    setFlash('success', 'Item RAP berhasil diperbarui!');
}

if ($action === 'update_item_rap_ajax') {
    $itemId = $_POST['item_id'];
    $itemCode = trim($_POST['item_code'] ?? '');
    $name = trim($_POST['item_name']);
    $brand = trim($_POST['item_brand'] ?? '');
    $category = $_POST['item_category'];
    $unit = trim($_POST['item_unit']);
    $priceRaw = $_POST['item_price'];
    $price = floatval(str_replace(',', '.', str_replace('.', '', $priceRaw)));
    $actualPriceRaw = $_POST['item_actual_price'] ?? '';
    $actualPrice = !empty($actualPriceRaw) ? floatval(str_replace(',', '.', str_replace('.', '', $actualPriceRaw))) : null;
    
    // Check for duplicate item_code across RAP and RAB
    if (!empty($itemCode)) {
        $rapItem = dbGetRow("SELECT rab_item_id FROM project_items_rap WHERE id = ?", [$itemId]);
        $matchingRabId = $rapItem ? $rapItem['rab_item_id'] : null;
        if (isItemCodeDuplicate($projectId, $itemCode, $matchingRabId, $itemId)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Kode item "' . $itemCode . '" sudah digunakan!']);
            exit;
        }
    }
    
    dbExecute("UPDATE project_items_rap SET item_code = ?, name = ?, brand = ?, category = ?, unit = ?, price = ?, actual_price = ? WHERE id = ? AND project_id = ?",
        [$itemCode, $name, $brand ?: null, $category, $unit, $price, $actualPrice, $itemId, $projectId]);
    
    // Sync item attributes to RAB (price stays separate)
    syncEditItemRapToRab($itemId, $itemCode, $name, $brand, $category, $unit, $projectId);
    
    // Sync price changes to all RAP AHSP that use this item
    syncRapItemToAhsp($itemId, $projectId);
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => 'Item RAP berhasil disimpan dan disinkronkan ke RAB!',
        'item' => [
            'id' => $itemId,
            'item_code' => $itemCode,
            'name' => $name,
            'brand' => $brand,
            'category' => $category,
            'unit' => $unit,
            'price' => $price,
            'actual_price' => $actualPrice
        ]
    ]);
    exit;
}

if ($action === 'delete_item_rap') {
    $itemId = $_POST['item_id'];
    dbExecute("DELETE FROM project_items_rap WHERE id = ? AND project_id = ?", [$itemId, $projectId]);
    setFlash('success', 'Item RAP berhasil dihapus!');
}

if ($action === 'clear_all_items_rap') {
    // Delete AHSP details first, then items
    dbExecute("
        DELETE d FROM project_ahsp_details_rap d
        INNER JOIN project_ahsp_rap a ON d.ahsp_id = a.id
        WHERE a.project_id = ?
    ", [$projectId]);
    
    dbExecute("DELETE FROM project_items_rap WHERE project_id = ?", [$projectId]);
    
    // Reset all RAP AHSP prices
    dbExecute("UPDATE project_ahsp_rap SET unit_price = 0 WHERE project_id = ?", [$projectId]);
    
    // === Also delete RAB Items ===
    // Delete RAB AHSP details first
    dbExecute("
        DELETE d FROM project_ahsp_details d
        INNER JOIN project_items i ON d.item_id = i.id
        WHERE i.project_id = ?
    ", [$projectId]);
    
    // Delete RAB items
    dbExecute("DELETE FROM project_items WHERE project_id = ?", [$projectId]);
    
    // Reset RAB AHSP prices
    dbExecute("UPDATE project_ahsp SET unit_price = 0 WHERE project_id = ?", [$projectId]);
    
    setFlash('success', "Semua items RAP dan RAB berhasil dihapus!");
}

// ============================================================
// RAP AHSP HANDLERS
// ============================================================
if ($action === 'add_ahsp_rap') {
    $ahspCode = trim($_POST['ahsp_code'] ?? '');
    $workName = trim($_POST['work_name']);
    $unit = trim($_POST['ahsp_unit']);
    
    if (!empty($ahspCode) && !empty($workName) && !empty($unit)) {
        $existing = dbGetRow("SELECT id FROM project_ahsp_rap WHERE project_id = ? AND ahsp_code = ?", 
            [$projectId, $ahspCode]);
        if ($existing) {
            setFlash('error', 'Kode AHSP "' . $ahspCode . '" sudah digunakan di Master Data RAP!');
        } else {
            // Find corresponding RAB AHSP
            $rabAhsp = dbGetRow("SELECT id FROM project_ahsp WHERE project_id = ? AND ahsp_code = ?", 
                [$projectId, $ahspCode]);
            $rabAhspId = $rabAhsp ? $rabAhsp['id'] : null;
            
            dbInsert("INSERT INTO project_ahsp_rap (project_id, ahsp_code, work_name, unit, unit_price, rab_ahsp_id) VALUES (?, ?, ?, ?, 0, ?)",
                [$projectId, $ahspCode, $workName, $unit, $rabAhspId]);
            
            // Sync to RAB if code doesn't exist there
            syncAhspCodeRapToRab($projectId, $ahspCode);
            
            setFlash('success', 'AHSP RAP berhasil ditambahkan!');
        }
    } else {
        setFlash('error', 'Kode AHSP, nama pekerjaan, dan satuan harus diisi!');
    }
}

if ($action === 'update_ahsp_rap') {
    $ahspId = $_POST['ahsp_id'];
    $ahspCode = trim($_POST['ahsp_code'] ?? '');
    $workName = trim($_POST['work_name']);
    $unit = trim($_POST['ahsp_unit']);
    
    // Check duplicate code
    $existing = dbGetRow("SELECT id FROM project_ahsp_rap WHERE project_id = ? AND ahsp_code = ? AND id != ?", 
        [$projectId, $ahspCode, $ahspId]);
    if ($existing) {
        setFlash('error', 'Kode AHSP "' . $ahspCode . '" sudah digunakan!');
    } else {
        // Sync to RAB BEFORE updating (need old ahsp_code)
        syncEditAhspRapToRab($ahspId, $ahspCode, $workName, $unit, $projectId);
        
        dbExecute("UPDATE project_ahsp_rap SET ahsp_code = ?, work_name = ?, unit = ? WHERE id = ? AND project_id = ?",
            [$ahspCode, $workName, $unit, $ahspId, $projectId]);
        
        setFlash('success', 'AHSP RAP berhasil diperbarui! Perubahan disinkronkan ke RAB.');
    }
}

if ($action === 'delete_ahsp_rap') {
    $ahspId = $_POST['ahsp_id'];
    
    // Sync: delete matching RAB AHSP BEFORE deleting RAP (need ahsp_code)
    syncDeleteAhspRapToRab($ahspId, $projectId);
    
    // Delete AHSP details first
    dbExecute("DELETE FROM project_ahsp_details_rap WHERE ahsp_id = ?", [$ahspId]);
    
    // Delete AHSP
    dbExecute("DELETE FROM project_ahsp_rap WHERE id = ? AND project_id = ?", [$ahspId, $projectId]);
    setFlash('success', 'AHSP RAP dan RAB berhasil dihapus!');
}

if ($action === 'clear_all_ahsp_rap') {
    // Delete all RAP AHSP details first
    dbExecute("
        DELETE d FROM project_ahsp_details_rap d
        INNER JOIN project_ahsp_rap a ON d.ahsp_id = a.id
        WHERE a.project_id = ?
    ", [$projectId]);
    
    // Delete all RAP AHSP
    dbExecute("DELETE FROM project_ahsp_rap WHERE project_id = ?", [$projectId]);
    
    // === Also delete RAB AHSP ===
    // Delete related data in correct order
    // 1. Delete rap_ahsp_details
    dbExecute("
        DELETE rad FROM rap_ahsp_details rad
        INNER JOIN rap_items rap ON rad.rap_item_id = rap.id
        INNER JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
        INNER JOIN project_ahsp pa ON rs.ahsp_id = pa.id
        WHERE pa.project_id = ?
    ", [$projectId]);
    
    // 2. Delete rap_items
    dbExecute("
        DELETE rap FROM rap_items rap
        INNER JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
        INNER JOIN project_ahsp pa ON rs.ahsp_id = pa.id
        WHERE pa.project_id = ?
    ", [$projectId]);
    
    // 3. Delete rab_subcategories
    dbExecute("
        DELETE rs FROM rab_subcategories rs
        INNER JOIN project_ahsp pa ON rs.ahsp_id = pa.id
        WHERE pa.project_id = ?
    ", [$projectId]);
    
    // 4. Delete RAB AHSP details
    dbExecute("
        DELETE d FROM project_ahsp_details d
        INNER JOIN project_ahsp a ON d.ahsp_id = a.id
        WHERE a.project_id = ?
    ", [$projectId]);
    
    // 5. Delete RAB AHSP
    dbExecute("DELETE FROM project_ahsp WHERE project_id = ?", [$projectId]);
    
    setFlash('success', "Semua AHSP RAP dan RAB berhasil dihapus!");
}

// ============================================================
// RAP AHSP DETAIL HANDLERS
// ============================================================
if ($action === 'add_ahsp_detail_rap') {
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
}

if ($action === 'update_ahsp_detail_rap') {
    $detailId = $_POST['detail_id'];
    $ahspId = $_POST['ahsp_id'];
    $coeffRaw = $_POST['coefficient'];
    $coefficient = floatval(str_replace(',', '.', $coeffRaw));
    $unitPriceRaw = $_POST['detail_unit_price'] ?? '';
    $unitPrice = !empty($unitPriceRaw) ? floatval(str_replace(',', '.', str_replace('.', '', $unitPriceRaw))) : null;
    
    if (empty($coeffRaw) || $coefficient > 0) {
        if (empty($coeffRaw)) {
            $current = dbGetRow("SELECT coefficient FROM project_ahsp_details_rap WHERE id = ?", [$detailId]);
            $coefficient = $current['coefficient'] ?? 1;
        }
        
        // Get item_id for sync
        $detailInfo = dbGetRow("SELECT item_id FROM project_ahsp_details_rap WHERE id = ?", [$detailId]);
        
        dbExecute("UPDATE project_ahsp_details_rap SET coefficient = ?, unit_price = ? WHERE id = ?",
            [$coefficient, $unitPrice, $detailId]);
        recalculateRapAhspPrice($ahspId);
        
        // Sync: mirror coefficient to RAB AHSP
        if ($detailInfo) {
            syncCoefficientRapToRab($ahspId, $detailInfo['item_id'], $coefficient, $projectId);
        }
        
        setFlash('success', 'Komponen berhasil diperbarui dan disinkronkan ke RAB!');
    }
}

if ($action === 'update_ahsp_detail_rap_ajax') {
    $detailId = $_POST['detail_id'];
    $ahspId = $_POST['ahsp_id'];
    $coeffRaw = $_POST['coefficient'];
    $coefficient = floatval(str_replace(',', '.', $coeffRaw));
    
    // Check if input is empty first to allow clearing, but db requires non-zero usually
    if (empty($coeffRaw) || $coefficient > 0) {
        if (empty($coeffRaw)) {
            $current = dbGetRow("SELECT coefficient FROM project_ahsp_details_rap WHERE id = ?", [$detailId]);
            $coefficient = $current['coefficient'] ?? 1;
        }
        
        // Get item_id for sync
        $detailInfo = dbGetRow("SELECT item_id FROM project_ahsp_details_rap WHERE id = ?", [$detailId]);
        
        dbExecute("UPDATE project_ahsp_details_rap SET coefficient = ? WHERE id = ?",
            [$coefficient, $detailId]);
        recalculateRapAhspPrice($ahspId);
        
        // Sync: mirror coefficient to RAB AHSP
        if ($detailInfo) {
            syncCoefficientRapToRab($ahspId, $detailInfo['item_id'], $coefficient, $projectId);
        }
        
        // Return JSON response
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'message' => 'Komponen berhasil diperbarui!'
        ]);
        exit;
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Koefisien harus lebih dari 0!'
        ]);
        exit;
    }
}

if ($action === 'delete_ahsp_detail_rap') {
    $detailId = $_POST['detail_id'];
    $ahspId = $_POST['ahsp_id'];
    
    // Sync: mirror deletion to RAB BEFORE deleting (need item info)
    syncDeleteComponentRapToRab($detailId, $ahspId, $projectId);
    
    dbExecute("DELETE FROM project_ahsp_details_rap WHERE id = ?", [$detailId]);
    recalculateRapAhspPrice($ahspId);
    setFlash('success', 'Komponen berhasil dihapus dari RAP dan RAB!');
}
