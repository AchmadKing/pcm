<?php
/**
 * RAB & RAP Tab POST Handlers
 * Included from view.php to handle form submissions
 * Variables available: $projectId, $project
 */

// Get available AHSP for RAB operations
$ahspList = dbGetAll("SELECT * FROM project_ahsp WHERE project_id = ? ORDER BY work_name", [$projectId]);

// RAB can be edited when draft AND not yet submitted
$isRabEditable = ($project['status'] === 'draft' && !$project['rab_submitted']);

$action = $_POST['action'] ?? '';
$allowedWhenRabSubmitted = ['reopen_rab'];

// Ensure database tables for Head-Subs are present
ensureRabHeadSubsTableExists();

// Define actions that THIS handler processes (prevents intercepting master_data actions)
$rabRapActions = [
    'add_head_sub', 'edit_head_sub', 'delete_head_sub',
    'add_category', 'edit_category', 'add_subcategory', 'delete_category', 'delete_subcategory', 'reorder_categories',
    'update_ppn', 'update_volume', 'submit_rab', 'reopen_rab',
    'create_snapshot', 'delete_snapshot', 'import_rab', 'import_rap',
    'update_rap_volume', 'generate_rap', 'sync_from_reference'
];

if (!in_array($action, $rabRapActions)) {
    return;
}

// Handle RAB and RAP actions
if ($isRabEditable || in_array($action, $allowedWhenRabSubmitted) || strpos($action, 'rap') !== false || $action === 'generate_rap' || $action === 'update_rap_volume' || $action === 'sync_from_reference') {
    try {
        switch ($action) {
            // ======= RAB Head-Sub Actions =======
            case 'add_head_sub':
                $name = trim($_POST['name'] ?? '');
                $code = trim($_POST['code'] ?? '');
                if (empty($name)) {
                    throw new Exception('Nama Head-Sub tidak boleh kosong!');
                }
                if (empty($code)) {
                    $code = null;
                }
                $maxSort = dbGetRow("SELECT COALESCE(MAX(sort_order), 0) + 1 as next FROM rab_head_subs WHERE project_id = ?", [$projectId]);
                dbInsert("INSERT INTO rab_head_subs (project_id, code, name, sort_order) VALUES (?, ?, ?, ?)",
                    [$projectId, $code, $name, $maxSort['next']]);
                setFlash('success', 'Head-Sub berhasil ditambahkan!');
                break;

            case 'edit_head_sub':
                $headSubId = intval($_POST['head_sub_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $code = trim($_POST['code'] ?? '');
                if (empty($headSubId) || empty($name)) {
                    throw new Exception('Data Head-Sub tidak valid!');
                }
                if (empty($code)) {
                    $code = null;
                }
                dbExecute("UPDATE rab_head_subs SET code = ?, name = ? WHERE id = ? AND project_id = ?",
                    [$code, $name, $headSubId, $projectId]);
                setFlash('success', 'Head-Sub berhasil diperbarui!');
                break;


            case 'delete_head_sub':
                $headSubId = intval($_POST['head_sub_id'] ?? 0);
                if (empty($headSubId)) {
                    throw new Exception('Head-Sub tidak valid!');
                }
                dbExecute("DELETE FROM rab_head_subs WHERE id = ? AND project_id = ?", [$headSubId, $projectId]);
                resequenceRabCategoriesAndSubcategories($projectId);
                setFlash('success', 'Head-Sub berhasil dihapus!');
                break;

            // ======= RAB Actions =======
            case 'add_category':
                $name = trim($_POST['name']);
                $headSubId = !empty($_POST['head_sub_id']) ? intval($_POST['head_sub_id']) : null;
                $maxCode = dbGetRow("SELECT code FROM rab_categories WHERE project_id = ? ORDER BY sort_order DESC, id DESC LIMIT 1", [$projectId]);
                if ($maxCode && !empty($maxCode['code'])) {
                    $nextCode = getNextCategoryCode($maxCode['code']);
                } else {
                    $nextCode = 'A';
                }
                $maxSort = dbGetRow("SELECT COALESCE(MAX(sort_order), 0) + 1 as next FROM rab_categories WHERE project_id = ?", [$projectId]);
                dbInsert("INSERT INTO rab_categories (project_id, head_sub_id, code, name, sort_order) VALUES (?, ?, ?, ?, ?)", 
                    [$projectId, $headSubId, $nextCode, $name, $maxSort['next']]);
                resequenceRabCategoriesAndSubcategories($projectId);
                setFlash('success', 'Kategori berhasil ditambahkan!');
                break;

            case 'edit_category':
                $catId = intval($_POST['category_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $headSubId = !empty($_POST['head_sub_id']) ? intval($_POST['head_sub_id']) : null;
                if (empty($catId) || empty($name)) {
                    throw new Exception('Data Kategori tidak valid!');
                }
                dbExecute("UPDATE rab_categories SET name = ?, head_sub_id = ? WHERE id = ? AND project_id = ?",
                    [$name, $headSubId, $catId, $projectId]);
                resequenceRabCategoriesAndSubcategories($projectId);
                setFlash('success', 'Kategori berhasil diperbarui!');
                break;

            case 'reorder_categories':
                $categoryOrder = $_POST['category_order'] ?? [];
                if (is_string($categoryOrder)) {
                    $categoryOrder = json_decode($categoryOrder, true) ?: [];
                }
                $sortIdx = 1;
                foreach ($categoryOrder as $item) {
                    $cId = is_array($item) ? intval($item['id'] ?? 0) : intval($item);
                    if (!$cId) continue;
                    if (is_array($item) && array_key_exists('head_sub_id', $item)) {
                        $hsId = (!empty($item['head_sub_id']) && $item['head_sub_id'] !== '0') ? intval($item['head_sub_id']) : null;
                        dbExecute("UPDATE rab_categories SET sort_order = ?, head_sub_id = ? WHERE id = ? AND project_id = ?", [$sortIdx, $hsId, $cId, $projectId]);
                    } else {
                        dbExecute("UPDATE rab_categories SET sort_order = ? WHERE id = ? AND project_id = ?", [$sortIdx, $cId, $projectId]);
                    }
                    $sortIdx++;
                }
                resequenceRabCategoriesAndSubcategories($projectId);
                setFlash('success', 'Urutan kategori berhasil disimpan!');
                break;

            case 'add_subcategory':
                $categoryId = $_POST['category_id'] ?? '';
                if (empty($categoryId)) {
                    throw new Exception('Kategori tidak valid!');
                }
                
                $ahspId = $_POST['ahsp_id'];
                $volumeRaw = $_POST['volume'] ?? '0';
                $volume = floatval(str_replace(',', '.', str_replace('.', '', $volumeRaw)));
                
                // Validate category exists and belongs to this project
                $category = dbGetRow("SELECT code FROM rab_categories WHERE id = ? AND project_id = ?", [$categoryId, $projectId]);
                if (!$category) {
                    throw new Exception('Kategori tidak ditemukan!');
                }
                
                $ahsp = dbGetRow("SELECT * FROM project_ahsp WHERE id = ? AND project_id = ?", [$ahspId, $projectId]);
                if (!$ahsp) {
                    throw new Exception('AHSP tidak ditemukan!');
                }
                $maxSubCode = dbGetRow("SELECT COUNT(*) + 1 as next FROM rab_subcategories WHERE category_id = ?", [$categoryId]);
                $nextCode = $category['code'] . '.' . $maxSubCode['next'];
                $maxSort = dbGetRow("SELECT COALESCE(MAX(sort_order), 0) + 1 as next FROM rab_subcategories WHERE category_id = ?", [$categoryId]);
                
                $subcatId = dbInsert("INSERT INTO rab_subcategories (category_id, ahsp_id, code, name, unit, volume, unit_price, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", 
                    [$categoryId, $ahspId, $nextCode, $ahsp['work_name'], $ahsp['unit'], $volume, $ahsp['unit_price'], $maxSort['next']]);
                
                // Auto-sync: Create RAP item
                $rapItemId = dbInsert("INSERT INTO rap_items (subcategory_id, volume, unit_price) VALUES (?, ?, ?)",
                    [$subcatId, $volume, $ahsp['unit_price']]);
                
                // Copy AHSP details to rap_ahsp_details
                $ahspDetails = dbGetAll("
                    SELECT d.item_id, i.category, d.coefficient, i.price
                    FROM project_ahsp_details d
                    JOIN project_items i ON d.item_id = i.id
                    WHERE d.ahsp_id = ?
                ", [$ahspId]);
                
                foreach ($ahspDetails as $detail) {
                    dbInsert("INSERT INTO rap_ahsp_details (rap_item_id, item_id, category, coefficient, unit_price) VALUES (?, ?, ?, ?, ?)",
                        [$rapItemId, $detail['item_id'], $detail['category'], $detail['coefficient'], $detail['price']]);
                }
                
                setFlash('success', 'Sub-kategori berhasil ditambahkan!');
                break;
                
            case 'delete_category':
                $deletedCatId = $_POST['category_id'];
                dbExecute("DELETE FROM rab_categories WHERE id = ? AND project_id = ?", [$deletedCatId, $projectId]);
                resequenceRabCategoriesAndSubcategories($projectId);
                setFlash('success', 'Kategori berhasil dihapus!');
                break;

            case 'delete_subcategory':
                $subcatId = $_POST['subcategory_id'];
                $catId = dbGetRow("SELECT category_id FROM rab_subcategories WHERE id = ?", [$subcatId])['category_id'];
                dbExecute("DELETE FROM rab_subcategories WHERE id = ?", [$subcatId]);
                $categoryCode = dbGetRow("SELECT code FROM rab_categories WHERE id = ?", [$catId])['code'];
                $remainingSubs = dbGetAll("SELECT id FROM rab_subcategories WHERE category_id = ? ORDER BY sort_order", [$catId]);
                $subNum = 1;
                foreach ($remainingSubs as $sub) {
                    $newCode = $categoryCode . '.' . $subNum;
                    dbExecute("UPDATE rab_subcategories SET code = ? WHERE id = ?", [$newCode, $sub['id']]);
                    $subNum++;
                }
                setFlash('success', 'Sub-kategori berhasil dihapus!');
                break;
                
            case 'update_ppn':
                ensureProfitPercentageColumnExists();
                $ppnRaw = $_POST['ppn_percentage'] ?? 11;
                $ppn = floatval(str_replace(',', '.', $ppnRaw));
                
                $updates = ["ppn_percentage = ?"];
                $params = [$ppn];
                
                if (isset($_POST['overhead_percentage'])) {
                    $oh = floatval(str_replace(',', '.', $_POST['overhead_percentage']));
                    $updates[] = "overhead_percentage = ?";
                    $params[] = $oh;
                }
                if (isset($_POST['profit_percentage'])) {
                    $pf = floatval(str_replace(',', '.', $_POST['profit_percentage']));
                    $updates[] = "profit_percentage = ?";
                    $params[] = $pf;
                }
                
                $params[] = $projectId;
                dbExecute("UPDATE projects SET " . implode(', ', $updates) . " WHERE id = ?", $params);
                setFlash('success', 'Pengaturan anggaran berhasil diperbarui!');
                break;
            
            case 'update_volume':
                $subcatId = $_POST['subcategory_id'];
                $volumeRaw = $_POST['volume'];
                $volume = floatval(str_replace(',', '.', str_replace('.', '', $volumeRaw)));
                
                dbExecute("UPDATE rab_subcategories SET volume = ? WHERE id = ?", [$volume, $subcatId]);
                
                $subcat = dbGetRow("SELECT unit_price FROM rab_subcategories WHERE id = ?", [$subcatId]);
                
                $rapItem = dbGetRow("SELECT id FROM rap_items WHERE subcategory_id = ?", [$subcatId]);
                if ($rapItem) {
                    dbExecute("UPDATE rap_items SET volume = ?, unit_price = ? WHERE subcategory_id = ?",
                        [$volume, $subcat['unit_price'], $subcatId]);
                } else {
                    dbInsert("INSERT INTO rap_items (subcategory_id, volume, unit_price) VALUES (?, ?, ?)",
                        [$subcatId, $volume, $subcat['unit_price']]);
                }
                
                setFlash('success', 'Volume berhasil diperbarui!');
                break;
                
            case 'submit_rab':
                $hasRap = dbGetRow("
                    SELECT COUNT(*) as cnt FROM rap_items rap
                    JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
                    JOIN rab_categories rc ON rs.category_id = rc.id
                    WHERE rc.project_id = ?
                ", [$projectId]);
                
                if ($hasRap && $hasRap['cnt'] > 0) {
                    dbExecute("
                        UPDATE rap_items rap
                        JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
                        JOIN rab_categories rc ON rs.category_id = rc.id
                        SET rap.unit_price = rs.unit_price
                        WHERE rc.project_id = ? AND rap.is_locked = 0
                    ", [$projectId]);
                    setFlash('success', 'RAB berhasil di-submit! RAP yang terkait sudah disinkronkan.');
                } else {
                    setFlash('success', 'RAB berhasil di-submit!');
                }
                
                dbExecute("UPDATE projects SET rab_submitted = 1 WHERE id = ?", [$projectId]);
                break;
                
            case 'reopen_rab':
                if (hasPermission('rab.edit')) {
                    dbExecute("UPDATE projects SET rab_submitted = 0 WHERE id = ?", [$projectId]);
                    setFlash('success', 'RAB dibuka kembali untuk diedit.');
                }
                break;
                
            case 'create_snapshot':
                $snapshotName = trim($_POST['snapshot_name'] ?? '');
                $snapshotDesc = trim($_POST['snapshot_description'] ?? '');
                
                if (empty($snapshotName)) {
                    throw new Exception('Nama salinan harus diisi!');
                }
                
                $snapshotId = dbInsert("INSERT INTO rab_snapshots (project_id, name, description, created_by) VALUES (?, ?, ?, ?)",
                    [$projectId, $snapshotName, $snapshotDesc, $_SESSION['user_id']]);
                
                $categories = dbGetAll("SELECT * FROM rab_categories WHERE project_id = ? ORDER BY sort_order", [$projectId]);
                foreach ($categories as $cat) {
                    $snapCatId = dbInsert("INSERT INTO rab_snapshot_categories (snapshot_id, original_category_id, code, name, sort_order) VALUES (?, ?, ?, ?, ?)",
                        [$snapshotId, $cat['id'], $cat['code'], $cat['name'], $cat['sort_order']]);
                    
                    $subcats = dbGetAll("SELECT * FROM rab_subcategories WHERE category_id = ? ORDER BY sort_order", [$cat['id']]);
                    foreach ($subcats as $sub) {
                        dbInsert("INSERT INTO rab_snapshot_subcategories (category_id, original_subcategory_id, ahsp_id, code, name, unit, volume, unit_price, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [$snapCatId, $sub['id'], $sub['ahsp_id'], $sub['code'], $sub['name'], $sub['unit'], $sub['volume'], $sub['unit_price'], $sub['sort_order']]);
                    }
                }
                setFlash('success', 'Salinan RAB berhasil dibuat!');
                break;
                
            case 'delete_snapshot':
                $snapshotId = $_POST['snapshot_id'];
                dbExecute("DELETE FROM rab_snapshots WHERE id = ?", [$snapshotId]);
                setFlash('success', 'Salinan RAB berhasil dihapus!');
                break;
                
            case 'import_rab':
                if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('File tidak valid atau tidak diunggah.');
                }
                
                $file = $_FILES['csv_file']['tmp_name'];
                $handle = fopen($file, 'r');
                if (!$handle) {
                    throw new Exception('Tidak dapat membaca file CSV.');
                }
                
                $firstLine = fgets($handle);
                rewind($handle);
                $delimiter = (strpos($firstLine, ';') !== false) ? ';' : ',';
                
                $ahspLookup = [];
                foreach ($ahspList as $ahsp) {
                    $code = strtolower(trim($ahsp['ahsp_code'] ?? ''));
                    if (!empty($code)) {
                        $ahspLookup[$code] = $ahsp;
                    }
                }
                
                $currentCategoryId = null;
                $importedCategories = 0;
                $importedSubcategories = 0;
                $errors = [];
                $rowNum = 0;
                
                while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                    $rowNum++;
                    if (empty($row) || (count($row) === 1 && empty(trim($row[0])))) continue;
                    
                    $colA = trim($row[0] ?? '');
                    $colB = trim($row[1] ?? '');
                    $colC = trim($row[2] ?? '');
                    
                    if (!empty($colA)) {
                        $maxCode = dbGetRow("SELECT code FROM rab_categories WHERE project_id = ? ORDER BY sort_order DESC, id DESC LIMIT 1", [$projectId]);
                        if ($maxCode && !empty($maxCode['code'])) {
                            $nextCode = getNextCategoryCode($maxCode['code']);
                        } else {
                            $nextCode = 'A';
                        }
                        $maxSort = dbGetRow("SELECT COALESCE(MAX(sort_order), 0) + 1 as next FROM rab_categories WHERE project_id = ?", [$projectId]);
                        
                        $currentCategoryId = dbInsert("INSERT INTO rab_categories (project_id, code, name, sort_order) VALUES (?, ?, ?, ?)", 
                            [$projectId, $nextCode, $colA, $maxSort['next']]);
                        $importedCategories++;
                    }
                    
                    if (empty($colB)) continue;
                    
                    if (!$currentCategoryId) {
                        $errors[] = "Baris $rowNum: AHSP sebelum kategori.";
                        continue;
                    }
                    
                    $ahspCode = strtolower($colB);
                    
                    if (!isset($ahspLookup[$ahspCode])) {
                        $errors[] = "Baris $rowNum: AHSP '$colB' tidak ditemukan";
                        continue;
                    }
                    
                    $ahsp = $ahspLookup[$ahspCode];
                    
                    $volumeRaw = str_replace(',', '.', str_replace('.', '', $colC));
                    $volume = floatval($volumeRaw);
                    if ($volume <= 0) {
                        $errors[] = "Baris $rowNum: Volume tidak valid '$colC'";
                        continue;
                    }
                    
                    $category = dbGetRow("SELECT code FROM rab_categories WHERE id = ?", [$currentCategoryId]);
                    $maxSubCode = dbGetRow("SELECT COUNT(*) + 1 as next FROM rab_subcategories WHERE category_id = ?", [$currentCategoryId]);
                    $nextSubCode = $category['code'] . '.' . $maxSubCode['next'];
                    $maxSort = dbGetRow("SELECT COALESCE(MAX(sort_order), 0) + 1 as next FROM rab_subcategories WHERE category_id = ?", [$currentCategoryId]);
                    
                    $subcatId = dbInsert("INSERT INTO rab_subcategories (category_id, ahsp_id, code, name, unit, volume, unit_price, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", 
                        [$currentCategoryId, $ahsp['id'], $nextSubCode, $ahsp['work_name'], $ahsp['unit'], $volume, $ahsp['unit_price'], $maxSort['next']]);
                    
                    $rapItemId = dbInsert("INSERT INTO rap_items (subcategory_id, volume, unit_price) VALUES (?, ?, ?)",
                        [$subcatId, $volume, $ahsp['unit_price']]);
                    
                    $ahspDetails = dbGetAll("
                        SELECT d.item_id, i.category, d.coefficient, i.price
                        FROM project_ahsp_details d
                        JOIN project_items i ON d.item_id = i.id
                        WHERE d.ahsp_id = ?
                    ", [$ahsp['id']]);
                    
                    foreach ($ahspDetails as $detail) {
                        dbInsert("INSERT INTO rap_ahsp_details (rap_item_id, item_id, category, coefficient, unit_price) VALUES (?, ?, ?, ?, ?)",
                            [$rapItemId, $detail['item_id'], $detail['category'], $detail['coefficient'], $detail['price']]);
                    }
                    
                    $importedSubcategories++;
                }
                fclose($handle);
                
                if (!empty($errors)) {
                    $errorMsg = implode('<br>', array_slice($errors, 0, 5));
                    if (count($errors) > 5) {
                        $errorMsg .= '<br>...dan ' . (count($errors) - 5) . ' error lainnya';
                    }
                    setFlash('warning', "Import selesai dengan beberapa error:<br>$errorMsg<br><br>Berhasil: $importedCategories kategori, $importedSubcategories sub-kategori");
                } else {
                    setFlash('success', "Berhasil import $importedCategories kategori dan $importedSubcategories sub-kategori!");
                }
                break;
                
            case 'import_rap':
                if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('File tidak valid atau tidak diunggah.');
                }
                
                $file = $_FILES['csv_file']['tmp_name'];
                $handle = fopen($file, 'r');
                if (!$handle) {
                    throw new Exception('Tidak dapat membaca file CSV.');
                }
                
                $firstLine = fgets($handle);
                rewind($handle);
                $delimiter = (strpos($firstLine, ';') !== false) ? ';' : ',';
                
                $ahspList = dbGetAll("SELECT * FROM project_ahsp WHERE project_id = ? ORDER BY work_name", [$projectId]);
                $ahspLookup = [];
                foreach ($ahspList as $ahsp) {
                    $code = strtolower(trim($ahsp['ahsp_code'] ?? ''));
                    if (!empty($code)) {
                        $ahspLookup[$code] = $ahsp;
                    }
                }
                
                $currentCategoryId = null;
                $importedCategories = 0;
                $updatedRapItems = 0;
                $insertedRapItems = 0;
                $errors = [];
                $rowNum = 0;
                
                while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                    $rowNum++;
                    if (empty($row) || (count($row) === 1 && empty(trim($row[0])))) continue;
                    
                    $colA = trim($row[0] ?? '');
                    $colB = trim($row[1] ?? '');
                    $colC = trim($row[2] ?? '');
                    
                    if (!empty($colA)) {
                        $existingCat = dbGetRow("SELECT id FROM rab_categories WHERE project_id = ? AND LOWER(name) = LOWER(?)", [$projectId, $colA]);
                        if ($existingCat) {
                            $currentCategoryId = $existingCat['id'];
                        } else {
                            $maxCode = dbGetRow("SELECT code FROM rab_categories WHERE project_id = ? ORDER BY sort_order DESC, id DESC LIMIT 1", [$projectId]);
                            if ($maxCode && !empty($maxCode['code'])) {
                                $nextCode = getNextCategoryCode($maxCode['code']);
                            } else {
                                $nextCode = 'A';
                            }
                            $maxSort = dbGetRow("SELECT COALESCE(MAX(sort_order), 0) + 1 as next FROM rab_categories WHERE project_id = ?", [$projectId]);
                            
                            $currentCategoryId = dbInsert("INSERT INTO rab_categories (project_id, code, name, sort_order) VALUES (?, ?, ?, ?)", 
                                [$projectId, $nextCode, $colA, $maxSort['next']]);
                            $importedCategories++;
                        }
                    }
                    
                    if (empty($colB)) continue;
                    
                    if (!$currentCategoryId) {
                        $errors[] = "Baris $rowNum: AHSP sebelum kategori.";
                        continue;
                    }
                    
                    $ahspCode = strtolower($colB);
                    
                    if (!isset($ahspLookup[$ahspCode])) {
                        $errors[] = "Baris $rowNum: AHSP '$colB' tidak ditemukan";
                        continue;
                    }
                    
                    $ahsp = $ahspLookup[$ahspCode];
                    
                    $volumeRaw = str_replace(',', '.', str_replace('.', '', $colC));
                    $volume = floatval($volumeRaw);
                    if ($volume <= 0) {
                        $errors[] = "Baris $rowNum: Volume tidak valid '$colC'";
                        continue;
                    }
                    
                    $existingSub = dbGetRow("
                        SELECT id, name, unit, unit_price FROM rab_subcategories 
                        WHERE category_id = ? AND ahsp_id = ?
                    ", [$currentCategoryId, $ahsp['id']]);
                    
                    if ($existingSub) {
                        $subcatId = $existingSub['id'];
                        $existingRap = dbGetRow("SELECT id FROM rap_items WHERE subcategory_id = ?", [$subcatId]);
                        if ($existingRap) {
                            dbExecute("UPDATE rap_items SET volume = ? WHERE id = ?", [$volume, $existingRap['id']]);
                            $updatedRapItems++;
                        } else {
                            $rapItemId = dbInsert("INSERT INTO rap_items (subcategory_id, volume, unit_price) VALUES (?, ?, ?)",
                                [$subcatId, $volume, $existingSub['unit_price']]);
                            
                            $ahspDetails = dbGetAll("
                                SELECT d.item_id, i.category, d.coefficient, i.price
                                FROM project_ahsp_details d
                                JOIN project_items i ON d.item_id = i.id
                                WHERE d.ahsp_id = ?
                            ", [$ahsp['id']]);
                            
                            foreach ($ahspDetails as $detail) {
                                dbInsert("INSERT INTO rap_ahsp_details (rap_item_id, item_id, category, coefficient, unit_price) VALUES (?, ?, ?, ?, ?)",
                                    [$rapItemId, $detail['item_id'], $detail['category'], $detail['coefficient'], $detail['price']]);
                            }
                            $insertedRapItems++;
                        }
                    } else {
                        $category = dbGetRow("SELECT code FROM rab_categories WHERE id = ?", [$currentCategoryId]);
                        $maxSubCode = dbGetRow("SELECT COUNT(*) + 1 as next FROM rab_subcategories WHERE category_id = ?", [$currentCategoryId]);
                        $nextSubCode = $category['code'] . '.' . $maxSubCode['next'];
                        $maxSort = dbGetRow("SELECT COALESCE(MAX(sort_order), 0) + 1 as next FROM rab_subcategories WHERE category_id = ?", [$currentCategoryId]);
                        
                        $subcatId = dbInsert("INSERT INTO rab_subcategories (category_id, ahsp_id, code, name, unit, volume, unit_price, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", 
                            [$currentCategoryId, $ahsp['id'], $nextSubCode, $ahsp['work_name'], $ahsp['unit'], $volume, $ahsp['unit_price'], $maxSort['next']]);
                        
                        $rapItemId = dbInsert("INSERT INTO rap_items (subcategory_id, volume, unit_price) VALUES (?, ?, ?)",
                            [$subcatId, $volume, $ahsp['unit_price']]);
                        
                        $ahspDetails = dbGetAll("
                            SELECT d.item_id, i.category, d.coefficient, i.price
                            FROM project_ahsp_details d
                            JOIN project_items i ON d.item_id = i.id
                            WHERE d.ahsp_id = ?
                        ", [$ahsp['id']]);
                        
                        foreach ($ahspDetails as $detail) {
                            dbInsert("INSERT INTO rap_ahsp_details (rap_item_id, item_id, category, coefficient, unit_price) VALUES (?, ?, ?, ?, ?)",
                                [$rapItemId, $detail['item_id'], $detail['category'], $detail['coefficient'], $detail['price']]);
                        }
                        $insertedRapItems++;
                    }
                }
                fclose($handle);
                
                if (!empty($errors)) {
                    $errorMsg = implode('<br>', array_slice($errors, 0, 5));
                    if (count($errors) > 5) {
                        $errorMsg .= '<br>...dan ' . (count($errors) - 5) . ' error lainnya';
                    }
                    setFlash('warning', "Import selesai dengan beberapa error:<br>$errorMsg<br><br>Hasil: $importedCategories kategori baru, $insertedRapItems RAP item baru, $updatedRapItems RAP item diperbarui");
                } else {
                    setFlash('success', "Berhasil import RAP! ($importedCategories kategori baru, $insertedRapItems RAP item baru, $updatedRapItems RAP item diperbarui)");
                }
                break;
            
            // ======= RAP Actions =======
            case 'update_rap_volume':
                if ($project['status'] === 'draft') {
                    $rapItemId = intval($_POST['rap_item_id']);
                    $volumeRaw = $_POST['volume'] ?? '0';
                    $volume = floatval(str_replace(',', '.', str_replace('.', '', $volumeRaw)));
                    
                    dbExecute("UPDATE rap_items SET volume = ? WHERE id = ?", [$volume, $rapItemId]);
                    setFlash('success', 'Volume berhasil diperbarui!');
                }
                break;
            
            case 'generate_rap':
                $rabSubcats = dbGetAll("
                    SELECT rs.id, rs.volume, rs.unit_price, rs.ahsp_id
                    FROM rab_subcategories rs
                    JOIN rab_categories rc ON rs.category_id = rc.id
                    WHERE rc.project_id = ?
                ", [$projectId]);
                
                foreach ($rabSubcats as $sub) {
                    $existing = dbGetRow("SELECT id FROM rap_items WHERE subcategory_id = ?", [$sub['id']]);
                    
                    if ($existing) {
                        dbExecute("UPDATE rap_items SET volume = ?, unit_price = ? WHERE subcategory_id = ?",
                            [$sub['volume'], $sub['unit_price'], $sub['id']]);
                        $rapItemId = $existing['id'];
                    } else {
                        $rapItemId = dbInsert("INSERT INTO rap_items (subcategory_id, volume, unit_price) VALUES (?, ?, ?)",
                            [$sub['id'], $sub['volume'], $sub['unit_price']]);
                    }
                    
                    if ($sub['ahsp_id']) {
                        $ahspDetails = dbGetAll("
                            SELECT d.item_id, i.category, d.coefficient, i.price
                            FROM project_ahsp_details d
                            JOIN project_items i ON d.item_id = i.id
                            WHERE d.ahsp_id = ?
                        ", [$sub['ahsp_id']]);
                        
                        foreach ($ahspDetails as $detail) {
                            $existingDetail = dbGetRow("SELECT id FROM rap_ahsp_details WHERE rap_item_id = ? AND item_id = ?", 
                                [$rapItemId, $detail['item_id']]);
                            
                            if (!$existingDetail) {
                                dbInsert("INSERT INTO rap_ahsp_details (rap_item_id, item_id, category, coefficient, unit_price) VALUES (?, ?, ?, ?, ?)",
                                    [$rapItemId, $detail['item_id'], $detail['category'], $detail['coefficient'], $detail['price']]);
                            }
                        }
                    }
                }
                setFlash('success', 'RAP berhasil di-sinkronkan dari RAB!');
                break;
            
            case 'sync_from_reference':
                if ($project['status'] === 'draft') {
                    $sourceId = intval($_POST['source_id'] ?? 0);
                    $sourceName = 'RAB Asli';
                    
                    // Delete all existing RAP data
                    $existingRapItems = dbGetAll("
                        SELECT rap.id FROM rap_items rap
                        JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
                        JOIN rab_categories rc ON rs.category_id = rc.id
                        WHERE rc.project_id = ?
                    ", [$projectId]);
                    
                    foreach ($existingRapItems as $rapItem) {
                        dbExecute("DELETE FROM rap_ahsp_details WHERE rap_item_id = ?", [$rapItem['id']]);
                    }
                    
                    dbExecute("
                        DELETE rap FROM rap_items rap
                        JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
                        JOIN rab_categories rc ON rs.category_id = rc.id
                        WHERE rc.project_id = ?
                    ", [$projectId]);
                    
                    if ($sourceId > 0) {
                        $snapshot = dbGetRow("SELECT id, name FROM rab_snapshots WHERE id = ? AND project_id = ?", [$sourceId, $projectId]);
                        
                        if ($snapshot) {
                            $sourceName = $snapshot['name'];
                            
                            $snapshotSubcats = dbGetAll("
                                SELECT ss.original_subcategory_id, ss.volume, ss.unit_price, ss.ahsp_id
                                FROM rab_snapshot_subcategories ss
                                JOIN rab_snapshot_categories sc ON ss.category_id = sc.id
                                WHERE sc.snapshot_id = ?
                            ", [$sourceId]);
                            
                            foreach ($snapshotSubcats as $snapSub) {
                                if ($snapSub['original_subcategory_id']) {
                                    $rapItemId = dbInsert("INSERT INTO rap_items (subcategory_id, volume, unit_price) VALUES (?, ?, ?)",
                                        [$snapSub['original_subcategory_id'], $snapSub['volume'], $snapSub['unit_price']]);
                                    
                                    if ($snapSub['ahsp_id']) {
                                        $ahspDetails = dbGetAll("
                                            SELECT d.item_id, i.category, d.coefficient, i.price
                                            FROM project_ahsp_details d
                                            JOIN project_items i ON d.item_id = i.id
                                            WHERE d.ahsp_id = ?
                                        ", [$snapSub['ahsp_id']]);
                                        
                                        foreach ($ahspDetails as $detail) {
                                            dbInsert("INSERT INTO rap_ahsp_details (rap_item_id, item_id, category, coefficient, unit_price) VALUES (?, ?, ?, ?, ?)",
                                                [$rapItemId, $detail['item_id'], $detail['category'], $detail['coefficient'], $detail['price']]);
                                        }
                                    }
                                }
                            }
                        }
                    } else {
                        $rabSubcats = dbGetAll("
                            SELECT rs.id, rs.volume, rs.unit_price, rs.ahsp_id
                            FROM rab_subcategories rs
                            JOIN rab_categories rc ON rs.category_id = rc.id
                            WHERE rc.project_id = ?
                        ", [$projectId]);
                        
                        foreach ($rabSubcats as $sub) {
                            $rapItemId = dbInsert("INSERT INTO rap_items (subcategory_id, volume, unit_price) VALUES (?, ?, ?)",
                                [$sub['id'], $sub['volume'], $sub['unit_price']]);
                            
                            if ($sub['ahsp_id']) {
                                $ahspDetails = dbGetAll("
                                    SELECT d.item_id, i.category, d.coefficient, i.price
                                    FROM project_ahsp_details d
                                    JOIN project_items i ON d.item_id = i.id
                                    WHERE d.ahsp_id = ?
                                ", [$sub['ahsp_id']]);
                                
                                foreach ($ahspDetails as $detail) {
                                    dbInsert("INSERT INTO rap_ahsp_details (rap_item_id, item_id, category, coefficient, unit_price) VALUES (?, ?, ?, ?, ?)",
                                        [$rapItemId, $detail['item_id'], $detail['category'], $detail['coefficient'], $detail['price']]);
                                }
                            }
                        }
                    }
                    
                    dbExecute("UPDATE projects SET rap_source_id = ? WHERE id = ?", [$sourceId, $projectId]);
                    
                    setFlash('success', 'RAP berhasil di-reset dan disinkronkan dari ' . $sourceName . '!');
                }
                break;
        }
    } catch (Exception $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
    }
    
    // Redirect to appropriate tab
    $redirectTab = 'rab';
    if (in_array($action, ['update_rap_volume', 'generate_rap', 'sync_from_reference'])) {
        $redirectTab = 'rap';
    }
    
    header('Location: view.php?id=' . $projectId . '&tab=' . $redirectTab);
    exit;
}
