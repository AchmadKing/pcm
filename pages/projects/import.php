<?php
/**
 * Import Handler - CSV Import for Items and AHSP
 * Handles bulk import from CSV files
 */

require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Only admin can import
requireAdmin();

$projectId = $_GET['project_id'] ?? null;
$importType = $_GET['type'] ?? 'items'; // 'items' or 'ahsp'

if (!$projectId) {
    header('Location: ../projects/');
    exit;
}

// Get project info
$project = dbGetRow("SELECT * FROM projects WHERE id = ?", [$projectId]);
if (!$project) {
    header('Location: ../projects/');
    exit;
}

// Check if project is editable based on import type
if ($importType === 'rab') {
    // RAB editable: draft status and not submitted
    $isEditable = ($project['status'] === 'draft' && !$project['rab_submitted']);
    if (!$isEditable) {
        setFlash('error', 'RAB tidak dapat diedit karena sudah di-submit atau status proyek bukan draft.');
        header('Location: rab.php?id=' . $projectId);
        exit;
    }
} else {
    // Items/AHSP editable: draft status and RAB not submitted
    $isEditable = ($project['status'] === 'draft' && !$project['rab_submitted']);
    if (!$isEditable) {
        setFlash('error', 'Proyek tidak dapat diedit karena sudah disubmit atau tidak dalam status draft.');
        header('Location: view.php?id=' . $projectId . '&tab=master');
        exit;
    }
}

$errors = [];
$successCount = 0;
$previewData = [];
$step = $_POST['step'] ?? $_GET['step'] ?? 'upload'; // 'upload', 'preview', 'confirm'
$validCount = 0;
$errorCount = 0;
$isViewingDraft = false;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
}

// Handle file upload and parsing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Step 1: Upload and Preview
    if ($step === 'upload' && isset($_FILES['csv_file'])) {
        $file = $_FILES['csv_file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Error uploading file: ' . $file['error'];
        } else {
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($extension !== 'csv') {
                $errors[] = 'File harus berformat CSV (.csv)';
            } else {
                // Parse CSV
                $handle = fopen($file['tmp_name'], 'r');
                if ($handle) {
                    // Detect delimiter - prefer semicolon for Indonesian Excel
                    $firstLine = fgets($handle);
                    rewind($handle);
                    
                    $delimiter = ';'; // default for this app
                    if (substr_count($firstLine, "\t") >= 1) {
                        $delimiter = "\t"; // Tab-separated
                    } elseif (substr_count($firstLine, ';') >= 1) {
                        $delimiter = ';'; // Semicolon
                    } elseif (substr_count($firstLine, ',') >= 1) {
                        $delimiter = ','; // Comma
                    }
                    
                    if ($importType === 'items') {
                        // Items format: 8 columns - Kode, Nama Utama, Jenis (opsional), Merk (opsional), Kategori, Satuan, Harga PU, Harga Aktual (opsional)
                        $header = fgetcsv($handle, 0, $delimiter);
                        
                        if (count($header) < 7) {
                            $errors[] = 'Format CSV Items tidak valid! File harus memiliki minimal 7 kolom.';
                            $errors[] = 'Format: Kode | Nama Utama | Jenis (opsional) | Merk (opsional) | Kategori | Satuan | Harga PU | Harga Aktual (opsional)';
                            fclose($handle);
                        } else {
                            // Load existing item codes and names for duplicate checking
                            $existingItemsRaw = dbGetAll("SELECT LOWER(item_code) as item_code, LOWER(name) as name FROM project_items WHERE project_id = ?", [$projectId]);
                            $existingItems = [
                                'codes' => array_column($existingItemsRaw, 'item_code'),
                                'names' => array_column($existingItemsRaw, 'name')
                            ];
                            
                            $rowNum = 1;
                            $lastMainName = ''; // Track last main name for fill-down
                            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                                $rowNum++;
                                if (count($row) < 7) continue;
                                
                                // Get item code and main name
                                $itemCode = trim($row[0] ?? '');
                                $mainName = trim($row[1] ?? '');
                                
                                // Always update lastMainName if this row has a main name (for fill-down)
                                if (!empty($mainName)) {
                                    $lastMainName = $mainName;
                                }
                                
                                // Skip rows without item code (column A) - but fill-down already updated above
                                if (empty($itemCode)) {
                                    continue; // Skip this row - no code
                                }
                                
                                // Fill-down algorithm: if main name is empty, use lastMainName
                                if (empty($mainName)) {
                                    $row[1] = $lastMainName;
                                }
                                
                                $parsed = parseItemRow($row, $rowNum, $existingItems);
                                $previewData[] = $parsed;
                                // Note: Not tracking duplicates within CSV - only checking database
                            }
                            fclose($handle);
                            
                            $_SESSION['import_preview'] = $previewData;
                            $_SESSION['import_type'] = $importType;
                            $_SESSION['import_project_id'] = $projectId;
                            $step = 'preview';
                        }
                    } else {
                        // AHSP format baru:
                        // Jika Col A terisi = AHSP baru: A=Kode AHSP, B=Nama AHSP, D=Satuan AHSP
                        // Item rows: D=Kode Item, F=Koefisien (skip jika bukan angka)
                        $currentAhspCode = null;
                        $currentAhspName = null;
                        $currentAhspUnit = null;
                        $rowNum = 0;
                        
                        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                            $rowNum++;
                            if (empty($row)) continue;
                            
                            // Column indices (0-based): A=0, B=1, C=2, D=3, E=4, F=5
                            $colA = trim($row[0] ?? '');
                            $colB = trim($row[1] ?? '');
                            $colD = trim($row[3] ?? '');
                            $colF = trim($row[5] ?? '');
                            
                            // Check if this is an AHSP header row (Column A is filled with AHSP code)
                            if (!empty($colA)) {
                                // This is a new AHSP - Col A=Code, Col B=Name, Col D=Unit
                                $currentAhspCode = $colA;
                                $currentAhspName = $colB;
                                $currentAhspUnit = $colD;
                                continue; // Skip to item rows
                            }
                            
                            // This is an item row - only process if we have a current AHSP
                            if ($currentAhspCode && $currentAhspName) {
                                // Col D = Item Code, Col F = Coefficient
                                $itemCode = $colD;
                                $coeffRaw = $colF;
                                
                                // Skip if coefficient is not numeric (contains letters)
                                if (empty($coeffRaw) || !is_numeric(str_replace(',', '.', $coeffRaw))) {
                                    continue; // Skip this row
                                }
                                
                                // Skip if item code is empty
                                if (empty($itemCode)) {
                                    continue;
                                }
                                
                                $previewData[] = parseAhspRowSimplified(
                                    $currentAhspCode,
                                    $currentAhspName,
                                    $currentAhspUnit,
                                    $itemCode,
                                    $coeffRaw,
                                    $rowNum,
                                    $projectId
                                );
                            }
                        }
                        fclose($handle);
                        
                        if (empty($previewData)) {
                            $errors[] = 'Tidak ada data AHSP yang valid ditemukan.';
                            $errors[] = 'Format baru: Kolom A=Kode AHSP (membuat AHSP baru), B=Nama, D=Satuan. Item: D=Kode Item, F=Koefisien.';
                        } else {
                            $_SESSION['import_preview'] = $previewData;
                            $_SESSION['import_type'] = $importType;
                            $_SESSION['import_project_id'] = $projectId;
                            $step = 'preview';
                        }
                    }
                } elseif ($importType === 'rab') {
                    // RAB format: Col A = Category Name, Col B = AHSP Code, Col C = Volume
                    // If Col A is filled, create new category
                    // Col B = AHSP code to add as subcategory
                    // Col C = Volume
                    
                    // Load AHSP lookup
                    $ahspList = dbGetAll("SELECT id, ahsp_code, work_name, unit, unit_price FROM project_ahsp WHERE project_id = ?", [$projectId]);
                    $ahspLookup = [];
                    foreach ($ahspList as $ahsp) {
                        $code = strtolower(trim($ahsp['ahsp_code'] ?? ''));
                        if (!empty($code)) {
                            $ahspLookup[$code] = $ahsp;
                        }
                    }
                    
                    if (empty($ahspLookup)) {
                        $errors[] = 'Tidak ada AHSP di Master Data. Import AHSP terlebih dahulu.';
                        fclose($handle);
                    } else {
                        $rowNum = 0;
                        $currentCategory = null;
                        
                        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                            $rowNum++;
                            if (empty($row) || (count($row) === 1 && empty(trim($row[0])))) continue;
                            
                            $colA = trim($row[0] ?? ''); // Category name
                            $colB = trim($row[1] ?? ''); // AHSP code
                            $colC = trim($row[2] ?? ''); // Volume
                            
                            // Check if this row has category name
                            $isNewCategory = !empty($colA);
                            
                            // Parse volume
                            $volumeRaw = str_replace(',', '.', str_replace('.', '', $colC));
                            $volume = floatval($volumeRaw);
                            
                            // Find AHSP
                            $ahspCode = strtolower($colB);
                            $ahsp = $ahspLookup[$ahspCode] ?? null;
                            
                            // Determine error
                            $error = null;
                            if ($isNewCategory && empty($colB)) {
                                // Category-only row (no AHSP) - valid for creating category
                                // We'll handle this specially
                            } elseif (empty($colB)) {
                                continue; // Skip empty rows
                            } else {
                                if (!$ahsp) {
                                    $error = "AHSP '$colB' tidak ditemukan di Master Data";
                                } elseif ($volume <= 0) {
                                    $error = "Volume tidak valid: '$colC'";
                                } elseif (empty($currentCategory) && !$isNewCategory) {
                                    $error = "AHSP sebelum kategori. Isi nama kategori di kolom A terlebih dahulu.";
                                }
                            }
                            
                            if ($isNewCategory) {
                                $currentCategory = $colA;
                            }
                            
                            // Add to preview
                            $previewData[] = [
                                'row' => $rowNum,
                                'category_name' => $isNewCategory ? $colA : '',
                                'current_category' => $currentCategory,
                                'ahsp_code' => $colB,
                                'ahsp_name' => $ahsp['work_name'] ?? '',
                                'ahsp_unit' => $ahsp['unit'] ?? '',
                                'ahsp_id' => $ahsp['id'] ?? null,
                                'ahsp_price' => $ahsp['unit_price'] ?? 0,
                                'volume' => $volume,
                                'error' => $error,
                                'valid' => ($error === null && (!empty($colB) || $isNewCategory))
                            ];
                        }
                        fclose($handle);
                        
                        // Remove rows without AHSP and without category (empty rows that slipped through)
                        $previewData = array_filter($previewData, function($row) {
                            return !empty($row['ahsp_code']) || !empty($row['category_name']);
                        });
                        $previewData = array_values($previewData); // Re-index
                        
                        if (empty($previewData)) {
                            $errors[] = 'Tidak ada data RAB yang valid ditemukan.';
                            $errors[] = 'Format: Kolom A = Nama Kategori, Kolom B = Kode AHSP, Kolom C = Volume';
                        } else {
                            $_SESSION['import_preview'] = $previewData;
                            $_SESSION['import_type'] = $importType;
                            $_SESSION['import_project_id'] = $projectId;
                            $step = 'preview';
                        }
                    }
                } else {
                    $errors[] = 'Tidak dapat membaca file CSV.';
                }
            }
        }
    }
    
    // Step: Save Draft (save data to session for later)
    if ($step === 'save_draft') {
        if (!empty($_POST['draft_data'])) {
            $draftData = json_decode($_POST['draft_data'], true);
            if ($draftData) {
                $_SESSION['import_draft_' . $importType . '_' . $projectId] = $draftData;
                $_SESSION['import_draft_type_' . $projectId] = $importType;
                setFlash('success', 'Draft berhasil disimpan!');
                // Redirect back to import page to view draft
                header('Location: import.php?project_id=' . $projectId . '&type=' . $importType);
                exit;
            }
        }
        $errors[] = 'Tidak ada data untuk disimpan sebagai draft.';
        $step = 'preview';
        $previewData = $_SESSION['import_preview'] ?? [];
    }
    
    // Step: View/Edit Draft (restore saved draft for editing)
    if ($step === 'view_draft') {
        $draftKey = 'import_draft_' . $importType . '_' . $projectId;
        if (isset($_SESSION[$draftKey]) && !empty($_SESSION[$draftKey])) {
            $previewData = $_SESSION[$draftKey];
            $_SESSION['import_preview'] = $previewData;
            $_SESSION['import_type'] = $importType;
            $_SESSION['import_project_id'] = $projectId;
            $step = 'preview';
            $isViewingDraft = true;
        } else {
            $errors[] = 'Draft tidak ditemukan.';
            $step = 'upload';
        }
    }
    
    // Step: Delete Draft
    if ($step === 'delete_draft') {
        $draftKey = 'import_draft_' . $importType . '_' . $projectId;
        unset($_SESSION[$draftKey]);
        unset($_SESSION['import_draft_type_' . $projectId]);
        setFlash('success', 'Draft berhasil dihapus.');
        header('Location: import.php?project_id=' . $projectId . '&type=' . $importType);
        exit;
    }
    
    // Step 2: Confirm and Import
    if ($step === 'confirm') {
        // Priority: 1) edited_data from form, 2) session data, 3) already loaded draft data
        if (!empty($_POST['edited_data'])) {
            $editedData = json_decode($_POST['edited_data'], true);
            if ($editedData) {
                $previewData = $editedData;
            }
        }
        // If previewData still empty, try session
        if (empty($previewData)) {
            $previewData = $_SESSION['import_preview'] ?? [];
        }
        $importType = $_SESSION['import_type'] ?? $importType; // Keep current importType if set
        
        if (empty($previewData)) {
            $errors[] = 'Tidak ada data untuk diimport.';
        } else {
            try {
                if ($importType === 'items') {
                    $successCount = importItems($previewData, $projectId);
                    $redirectUrl = 'view.php?id=' . $projectId . '&tab=master';
                } elseif ($importType === 'ahsp') {
                    $successCount = importAhsp($previewData, $projectId);
                    $redirectUrl = 'view.php?id=' . $projectId . '&tab=master&subtab=ahsp';
                } elseif ($importType === 'rab') {
                    $successCount = importRab($previewData, $projectId);
                    $redirectUrl = 'rab.php?id=' . $projectId;
                } else {
                    throw new Exception('Tipe import tidak valid.');
                }
                
                // Clear session
                unset($_SESSION['import_preview']);
                unset($_SESSION['import_type']);
                unset($_SESSION['import_project_id']);
                
                setFlash('success', "Berhasil mengimport $successCount data!");
                echo '<script>window.location.href = "' . $redirectUrl . '";</script>';
                exit;
            } catch (Exception $e) {
                $errors[] = 'Error import: ' . $e->getMessage();
            }
        }
    }
}

/**
 * Parse a single row for Items import
 * Format: Kode | Nama Utama | Jenis (opsional) | Merk (opsional) | Kategori | Satuan | Harga PU | Harga Aktual (opsional)
 */
function parseItemRow($row, $rowNum, $existingItems = []) {
    $itemCode = trim($row[0] ?? '');
    $mainName = trim($row[1] ?? '');
    $subType = trim($row[2] ?? '');
    $brand = trim($row[3] ?? '');
    $category = strtolower(trim($row[4] ?? ''));
    $unit = trim($row[5] ?? '');
    $priceRaw = trim($row[6] ?? '0');
    $actualPriceRaw = trim($row[7] ?? ''); // Optional 8th column for actual price
    
    // Construct full name: if sub-type exists, combine with " - "
    if (!empty($subType)) {
        $name = $mainName . ' - ' . $subType;
    } else {
        $name = $mainName;
    }
    
    // Parse price (handle both . and , as decimal separator)
    $price = floatval(str_replace(',', '.', str_replace('.', '', $priceRaw)));
    $actualPrice = !empty($actualPriceRaw) ? floatval(str_replace(',', '.', str_replace('.', '', $actualPriceRaw))) : null;
    
    $error = null;
    $willUpdate = false;
    
    // Validation - check by item_code
    if (empty($itemCode)) {
        $error = 'Kode item kosong (wajib diisi)';
    } elseif (isset($existingItems['codes']) && in_array(strtolower($itemCode), $existingItems['codes'])) {
        // Item with this code already exists - mark for update instead of error
        $willUpdate = true;
    } elseif (empty($mainName)) {
        $error = 'Nama item utama kosong';
    } elseif (!in_array($category, ['upah', 'material', 'alat'])) {
        $error = "Kategori '$category' tidak valid (gunakan: upah, material, alat)";
    } elseif ($price <= 0) {
        $error = 'Harga harus lebih dari 0';
    }
    // Note: Duplicate names are now ALLOWED as long as item codes are different
    
    return [
        'row' => $rowNum,
        'item_code' => $itemCode,
        'name' => $name,
        'main_name' => $mainName,
        'sub_type' => $subType,
        'brand' => $brand,
        'category' => $category,
        'unit' => $unit,
        'price' => $price,
        'actual_price' => $actualPrice,
        'error' => $error,
        'valid' => ($error === null),
        'will_update' => $willUpdate
    ];
}

/**
 * Parse a single row for AHSP import
 */
function parseAhspRow($row, $rowNum, $projectId) {
    $ahspName = trim($row[0] ?? '');
    $ahspUnit = trim($row[1] ?? '');
    $itemName = trim($row[2] ?? '');
    $coeffRaw = trim($row[3] ?? '0');
    
    // Parse coefficient
    $coefficient = floatval(str_replace(',', '.', $coeffRaw));
    
    $error = null;
    $itemId = null;
    $itemUnit = null;
    $itemPrice = 0;
    
    // Validation
    if (empty($ahspName)) {
        $error = 'Nama AHSP kosong';
    } elseif (empty($ahspUnit)) {
        $error = 'Satuan AHSP kosong';
    } elseif (empty($itemName)) {
        $error = 'Nama Item kosong';
    } elseif ($coefficient <= 0) {
        $error = 'Koefisien harus lebih dari 0';
    } else {
        // Try to match item by name (case-insensitive)
        $item = dbGetRow(
            "SELECT id, unit, price FROM project_items WHERE project_id = ? AND LOWER(name) = LOWER(?)",
            [$projectId, $itemName]
        );
        
        if ($item) {
            $itemId = $item['id'];
            $itemUnit = $item['unit'];
            $itemPrice = $item['price'];
        } else {
            $error = "Item '$itemName' tidak ditemukan di Master Data";
        }
    }
    
    return [
        'row' => $rowNum,
        'ahsp_name' => $ahspName,
        'ahsp_unit' => $ahspUnit,
        'item_name' => $itemName,
        'item_id' => $itemId,
        'item_unit' => $itemUnit,
        'item_price' => $itemPrice,
        'coefficient' => $coefficient,
        'error' => $error,
        'valid' => ($error === null)
    ];
}

/**
 * Parse a single row for AHSP import (simplified format with [AHSP] markers)
 * Item identifier can be either item_code or item name - system tries both
 */
function parseAhspRowSimplified($ahspCode, $ahspName, $ahspUnit, $itemIdentifier, $coeffRaw, $rowNum, $projectId) {
    // Parse coefficient
    $coefficient = floatval(str_replace(',', '.', $coeffRaw));
    
    $error = null;
    $itemId = null;
    $itemCode = null;
    $itemName = null;
    $itemUnit = null;
    $itemPrice = 0;
    $originalInput = $itemIdentifier; // Store original input for error message
    
    // Validation
    if (empty($ahspCode)) {
        $error = 'Kode AHSP kosong';
    } elseif (empty($ahspName)) {
        $error = 'Nama AHSP kosong';
    } elseif (empty($ahspUnit)) {
        $error = 'Satuan AHSP kosong';
    } elseif (empty($itemIdentifier)) {
        $error = 'Nama/Kode Item kosong';
    } elseif ($coefficient <= 0) {
        $error = 'Koefisien harus lebih dari 0';
    } else {
        // Try to match item by CODE first (case-insensitive)
        $item = dbGetRow(
            "SELECT id, item_code, name, unit, price FROM project_items WHERE project_id = ? AND LOWER(item_code) = LOWER(?)",
            [$projectId, $itemIdentifier]
        );
        
        // If not found by code, try by NAME
        if (!$item) {
            $item = dbGetRow(
                "SELECT id, item_code, name, unit, price FROM project_items WHERE project_id = ? AND LOWER(name) = LOWER(?)",
                [$projectId, $itemIdentifier]
            );
        }
        
        if ($item) {
            $itemId = $item['id'];
            $itemCode = $item['item_code'];
            $itemName = $item['name'];
            $itemUnit = $item['unit'];
            $itemPrice = $item['price'];
        } else {
            $error = "Item '$itemIdentifier' tidak ditemukan di Master Data (cek kode atau nama)";
        }
    }
    
    return [
        'row' => $rowNum,
        'ahsp_code' => $ahspCode,
        'ahsp_name' => $ahspName,
        'ahsp_unit' => $ahspUnit,
        'item_identifier' => $originalInput, // Original input (code or name)
        'item_code' => $itemCode, // Resolved code from database
        'item_name' => $itemName, // Resolved name from database
        'item_id' => $itemId,
        'item_unit' => $itemUnit,
        'item_price' => $itemPrice,
        'coefficient' => $coefficient,
        'error' => $error,
        'valid' => ($error === null)
    ];
}

/**
 * Import Items to database
 */
function importItems($data, $projectId) {
    $count = 0;
    foreach ($data as $row) {
        if (!$row['valid']) continue;
        
        // Get item name - fallback to main_name + sub_type if name is empty
        $itemName = $row['name'] ?? '';
        if (empty($itemName)) {
            $mainName = $row['main_name'] ?? '';
            $subType = $row['sub_type'] ?? '';
            if (!empty($subType)) {
                $itemName = $mainName . ' - ' . $subType;
            } else {
                $itemName = $mainName;
            }
        }
        
        // Skip if still no name
        if (empty($itemName)) {
            continue;
        }
        
        $actualPrice = $row['actual_price'] ?? null;
        $itemCode = $row['item_code'] ?? '';
        $brand = $row['brand'] ?? null;
        
        // Check if item already exists by ITEM CODE (not name)
        // This allows duplicate names with different codes
        $existing = null;
        if (!empty($itemCode)) {
            $existing = dbGetRow(
                "SELECT id FROM project_items WHERE project_id = ? AND LOWER(item_code) = LOWER(?)",
                [$projectId, $itemCode]
            );
        }
        
        if ($existing) {
            // Update existing by code
            dbExecute(
                "UPDATE project_items SET name = ?, brand = ?, category = ?, unit = ?, price = ?, actual_price = ? WHERE id = ?",
                [$itemName, $brand, $row['category'], $row['unit'], $row['price'], $actualPrice, $existing['id']]
            );
        } else {
            // Insert new
            dbInsert(
                "INSERT INTO project_items (project_id, item_code, name, brand, category, unit, price, actual_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$projectId, $itemCode, $itemName, $brand, $row['category'], $row['unit'], $row['price'], $actualPrice]
            );
        }
        
        // Auto-sync to RAP Master Data
        if (!empty($itemCode)) {
            syncItemCodeRabToRap($projectId, $itemCode);
        }
        
        $count++;
    }
    return $count;
}

/**
 * Import AHSP to database
 */
function importAhsp($data, $projectId) {
    $ahspCache = []; // Cache for created AHSP
    $count = 0;
    
    foreach ($data as $row) {
        // Check if row is valid - check both explicit 'valid' key and validate data
        $isValid = isset($row['valid']) ? $row['valid'] : true;
        if (!$isValid) continue;
        
        // Get required fields with null safety
        $ahspCode = $row['ahsp_code'] ?? '';
        $ahspName = $row['ahsp_name'] ?? null;
        $ahspUnit = $row['ahsp_unit'] ?? null;
        $itemId = $row['item_id'] ?? null;
        $coefficient = $row['coefficient'] ?? 0;
        
        // Skip if required fields are missing
        if (empty($ahspCode) || empty($ahspName) || empty($ahspUnit) || empty($itemId)) {
            continue;
        }
        
        $ahspKey = $ahspCode . '|' . $ahspName . '|' . $ahspUnit;
        
        // Create or get AHSP
        if (!isset($ahspCache[$ahspKey])) {
            // Check if AHSP exists by code first, then by name
            $existing = dbGetRow(
                "SELECT id FROM project_ahsp WHERE project_id = ? AND (ahsp_code = ? OR LOWER(work_name) = LOWER(?))",
                [$projectId, $ahspCode, $ahspName]
            );
            
            if ($existing) {
                // Update existing AHSP with code if missing
                dbExecute("UPDATE project_ahsp SET ahsp_code = ? WHERE id = ? AND (ahsp_code IS NULL OR ahsp_code = '')",
                    [$ahspCode, $existing['id']]);
                $ahspCache[$ahspKey] = $existing['id'];
            } else {
                // Create new AHSP with code
                $ahspId = dbInsert(
                    "INSERT INTO project_ahsp (project_id, ahsp_code, work_name, unit, unit_price) VALUES (?, ?, ?, ?, 0)",
                    [$projectId, $ahspCode, $ahspName, $ahspUnit]
                );
                $ahspCache[$ahspKey] = $ahspId;
            }
        }
        
        $ahspId = $ahspCache[$ahspKey];
        
        // Check if detail already exists
        $existingDetail = dbGetRow(
            "SELECT id FROM project_ahsp_details WHERE ahsp_id = ? AND item_id = ?",
            [$ahspId, $itemId]
        );
        
        if ($existingDetail) {
            // Update coefficient
            dbExecute(
                "UPDATE project_ahsp_details SET coefficient = ? WHERE id = ?",
                [$coefficient, $existingDetail['id']]
            );
        } else {
            // Insert new detail
            dbInsert(
                "INSERT INTO project_ahsp_details (ahsp_id, item_id, coefficient) VALUES (?, ?, ?)",
                [$ahspId, $itemId, $coefficient]
            );
        }
        $count++;
    }
    
    // Recalculate unit_price for all affected AHSP
    foreach ($ahspCache as $ahspId) {
        recalculateAhspPriceById($ahspId);
    }
    
    // Auto-sync all imported AHSP to RAP Master Data
    foreach ($ahspCache as $ahspKey => $ahspId) {
        // Extract ahspCode from the cache key (format: code|name|unit)
        $keyParts = explode('|', $ahspKey);
        $ahspCode = $keyParts[0] ?? '';
        if (!empty($ahspCode)) {
            syncAhspCodeRabToRap($projectId, $ahspCode);
        }
    }
    
    return $count;
}

/**
 * Recalculate AHSP unit price
 */
function recalculateAhspPriceById($ahspId) {
    $total = dbGetRow("
        SELECT COALESCE(SUM(d.coefficient * i.price), 0) as total
        FROM project_ahsp_details d
        JOIN project_items i ON d.item_id = i.id
        WHERE d.ahsp_id = ?
    ", [$ahspId])['total'];
    
    dbExecute("UPDATE project_ahsp SET unit_price = ? WHERE id = ?", [$total, $ahspId]);
}

// Get counts for preview
$validCount = count(array_filter($previewData, fn($r) => $r['valid']));
$errorCount = count($previewData) - $validCount;

require_once '../../includes/header.php';
?>

<style>
.highlight-error {
    background-color: #ffcccc !important;
    animation: pulse-error 0.5s ease-in-out 2;
}
@keyframes pulse-error {
    0%, 100% { background-color: #ffcccc; }
    50% { background-color: #ff9999; }
}
#errorCount:hover {
    transform: scale(1.05);
    box-shadow: 0 2px 8px rgba(220, 53, 69, 0.4);
}
</style>

<div class="main-content">
    <div class="page-content" style="padding-top: 0;">
        <div class="container-fluid">
            <!-- Breadcrumb -->
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-flex align-items-center justify-content-between mb-2 mt-2">
                        <h4 class="mb-0">
                            Import <?= $importType === 'items' ? 'Items' : 'AHSP' ?> - <?= sanitize($project['name']) ?>
                        </h4>
                        <a href="view.php?id=<?= $projectId ?>&tab=master" class="btn btn-secondary">
                            <i class="mdi mdi-arrow-left"></i> Kembali
                        </a>
                    </div>
                </div>
            </div>

            <!-- Errors -->
            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                    <li><?= $error ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if ($step === 'upload' || $step === 'preview' && empty($previewData)): ?>
            <!-- Upload Form -->
            <div class="card mb-0">
                <div class="card-body p-3">
                    <div class="row g-4">
                        <div class="col-lg-5">
                            <h5 class="card-title mb-4">
                                <i class="mdi mdi-file-upload text-primary"></i> 
                                Upload File CSV
                            </h5>
                            
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="step" value="upload">
                                
                                <div class="mb-4">
                                    <label class="form-label">Pilih File CSV</label>
                                    <input type="file" class="form-control" name="csv_file" accept=".csv" required>
                                    <small class="text-muted">Format: .csv (Comma Separated Values)</small>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="mdi mdi-upload"></i> Upload & Preview
                                </button>
                            </form>
                            
                            <?php 
                            // Check if a draft exists for this import type and project
                            $draftKey = 'import_draft_' . $importType . '_' . $projectId;
                            $hasDraft = isset($_SESSION[$draftKey]) && !empty($_SESSION[$draftKey]);
                            ?>
                            <?php if ($hasDraft): ?>
                            <div class="mt-3 pt-3 border-top">
                                <div class="alert alert-warning mb-2">
                                    <i class="mdi mdi-file-document-edit"></i> Anda memiliki draft import yang tersimpan.
                                </div>
                                <div class="d-flex gap-2">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="step" value="view_draft">
                                        <button type="submit" class="btn btn-warning">
                                            <i class="mdi mdi-eye"></i> Lihat Draft
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Yakin ingin menghapus draft?');">
                                        <input type="hidden" name="step" value="delete_draft">
                                        <button type="submit" class="btn btn-outline-danger">
                                            <i class="mdi mdi-delete"></i> Hapus Draft
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-lg-7">
                            <h5 class="card-title mb-4">
                                <i class="mdi mdi-file-download text-success"></i> 
                                Download Template
                            </h5>
                            
                            <?php if ($importType === 'items'): ?>
                            <p>Template untuk import Items (Upah, Material, Alat):</p>
                            <table class="table table-sm table-bordered mb-3" style="font-size: 12px;">
                                <thead class="table-light">
                                    <tr>
                                        <th>Kode</th>
                                        <th>Nama Utama</th>
                                        <th>Jenis</th>
                                        <th>Merk</th>
                                        <th>Kategori</th>
                                        <th>Satuan</th>
                                        <th>Harga PU</th>
                                        <th>Harga Aktual</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td>MTR-001</td><td>Batu Kali</td><td>Belah 20-30</td><td class="text-muted">(kosong)</td><td>material</td><td>m3</td><td>300000</td><td class="text-muted">(kosong)</td></tr>
                                    <tr><td>MTR-002</td><td>Batu Kali</td><td>Pecah 15/20</td><td class="text-muted">(kosong)</td><td>material</td><td>m3</td><td>185000</td><td>180000</td></tr>
                                    <tr><td>MTR-013</td><td>Semen Portland</td><td class="text-muted">(kosong)</td><td>Tiga Roda</td><td>material</td><td>zak</td><td>65000</td><td>62000</td></tr>
                                    <tr><td>UPH-001</td><td>Pekerja</td><td class="text-muted">(kosong)</td><td class="text-muted">(kosong)</td><td>upah</td><td>OH</td><td>75000</td><td class="text-muted">(kosong)</td></tr>
                                </tbody>
                            </table>
                            <div class="alert alert-info small mb-3">
                                <i class="mdi mdi-information"></i>
                                <strong>Cara Kerja:</strong>
                                <ul class="mb-0 mt-1">
                                    <li><strong>Kolom A:</strong> Kode Item (wajib)</li>
                                    <li><strong>Kolom B:</strong> Nama Utama (wajib)</li>
                                    <li><strong>Kolom C:</strong> Jenis/Varian (opsional, kosongkan jika tidak ada)</li>
                                    <li><strong>Kolom D:</strong> Merk (opsional)</li>
                                    <li><strong>Kolom E:</strong> Kategori (upah/material/alat)</li>
                                    <li><strong>Kolom F:</strong> Satuan</li>
                                    <li><strong>Kolom G:</strong> Harga PU (wajib)</li>
                                    <li><strong>Kolom H:</strong> Harga Aktual (opsional)</li>
                                </ul>
                            </div>
                            <div class="alert alert-success small mb-3">
                                <i class="mdi mdi-check-circle"></i>
                                <strong>Hasil Nama Item:</strong>
                                <ul class="mb-0 mt-1">
                                    <li>Jika Jenis diisi: <code>Batu Kali - Belah 20-30</code></li>
                                    <li>Jika Jenis kosong: <code>Semen Portland</code></li>
                                </ul>
                            </div>
                            <a href="../../templates/template_items.csv" class="btn btn-success" download>
                                <i class="mdi mdi-download"></i> Download Template Items
                            </a>
                            <?php else: ?>
                            <p><strong>Format Import AHSP</strong></p>
                            
                            <div class="alert alert-success small mb-3">
                                <i class="mdi mdi-check-circle"></i>
                                <strong>Format Kolom:</strong>
                                <ul class="mb-0 mt-1">
                                    <li><strong>Baris AHSP (Kolom A terisi):</strong> A=Kode AHSP, B=Nama AHSP, D=Satuan</li>
                                    <li><strong>Baris Item:</strong> D=Kode Item (dari Master Data), F=Koefisien</li>
                                </ul>
                            </div>
                            
                            <div class="bg-light p-3 rounded mb-3" style="font-family: monospace; font-size: 11px;">
                                <table class="table table-sm table-bordered mb-0" style="font-size: 11px;">
                                    <thead class="table-secondary">
                                        <tr><th>A</th><th>B</th><th>C</th><th>D</th><th>E</th><th>F</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr class="table-primary"><td>A.4.1.1.4</td><td>Lantai kerja beton</td><td></td><td>m3</td><td></td><td></td></tr>
                                        <tr><td></td><td></td><td></td><td>L.01a</td><td></td><td>1,200</td></tr>
                                        <tr><td></td><td></td><td></td><td>L.03</td><td></td><td>0,200</td></tr>
                                        <tr><td></td><td></td><td></td><td>MTR-001</td><td></td><td>5,500</td></tr>
                                        <tr class="table-primary"><td>A.4.1.1.5</td><td>Beton mutu K-225</td><td></td><td>m3</td><td></td><td></td></tr>
                                        <tr><td></td><td></td><td></td><td>L.01a</td><td></td><td>1,650</td></tr>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="alert alert-warning small mb-3">
                                <i class="mdi mdi-alert"></i>
                                <strong>Catatan:</strong>
                                <ul class="mb-0 mt-1">
                                    <li>Jika kolom F bukan angka (ada huruf), baris tersebut akan dilewati</li>
                                    <li>Kode Item harus sesuai dengan Master Data</li>
                                </ul>
                            </div>
                            <a href="../../templates/template_ahsp.csv" class="btn btn-success" download>
                                <i class="mdi mdi-download"></i> Download Template AHSP
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($step === 'preview' && !empty($previewData)): ?>
            <!-- Preview/Draft Data -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <?php if ($isViewingDraft): ?>
                        <i class="mdi mdi-file-document-edit text-warning"></i> Data Draft
                        <?php else: ?>
                        <i class="mdi mdi-eye text-info"></i> Preview Data
                        <?php endif; ?>
                    </h5>
                    <div>
                        <span class="badge bg-success me-2" id="validCount">✓ Valid: <?= $validCount ?></span>
                        <span class="badge bg-danger" id="errorCount" role="button" style="cursor:pointer;<?= $errorCount == 0 ? 'display:none;' : '' ?>" 
                              title="Klik untuk lompat ke error">✗ Error: <?= $errorCount ?></span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: calc(100vh - 280px); overflow-y: auto;">
                        <table class="table table-sm table-bordered mb-0" id="previewTable">
                            <thead class="table-light sticky-top">
                                <?php if ($importType === 'items'): ?>
                                <tr>
                                    <th width="50">Row</th>
                                    <th width="50">Status</th>
                                    <th width="100">Kode</th>
                                    <th>Nama Item</th>
                                    <th width="100">Merk</th>
                                    <th width="100">Kategori</th>
                                    <th width="80">Satuan</th>
                                    <th width="110" class="text-end">Harga PU</th>
                                    <th width="110" class="text-end">Harga Aktual</th>
                                    <th>Keterangan</th>
                                </tr>
                                <?php else: ?>
                                <tr>
                                    <th width="50">Row</th>
                                    <th width="50">Status</th>
                                    <th>Kode AHSP</th>
                                    <th>Nama AHSP</th>
                                    <th>Satuan</th>
                                    <th>Input Item</th>
                                    <th>Item (Resolved)</th>
                                    <th class="text-end">Koefisien</th>
                                    <th>Error</th>
                                </tr>
                                <?php endif; ?>
                            </thead>
                            <tbody>
                                <?php foreach ($previewData as $idx => $row): ?>
                                <tr class="preview-row <?= ($row['valid'] ?? false) ? '' : 'table-danger' ?>" data-row-idx="<?= $idx ?>">
                                    <td class="text-center"><?= $row['row'] ?? $idx ?></td>
                                    <td class="text-center status-cell" id="status-<?= $idx ?>">
                                        <?php if ($row['valid'] ?? false): ?>
                                        <span class="text-success"><i class="mdi mdi-check-circle"></i></span>
                                        <?php else: ?>
                                        <span class="text-danger"><i class="mdi mdi-close-circle"></i></span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($importType === 'items'): ?>
                                        <?php if ($isViewingDraft): ?>
                                        <!-- Editable mode for draft -->
                                        <td>
                                            <input type="text" class="form-control form-control-sm border-0 edit-cell" 
                                                   data-idx="<?= $idx ?>" data-field="item_code"
                                                   value="<?= sanitize($row['item_code'] ?? '') ?>" style="width:80px;">
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm border-0 edit-cell" 
                                                   data-idx="<?= $idx ?>" data-field="name"
                                                   value="<?= sanitize($row['name'] ?? '') ?>" style="min-width:150px;">
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm border-0 edit-cell" 
                                                   data-idx="<?= $idx ?>" data-field="brand"
                                                   value="<?= sanitize($row['brand'] ?? '') ?>" style="width:80px;">
                                        </td>
                                        <td>
                                            <select class="form-select form-select-sm border-0 edit-cell" 
                                                    data-idx="<?= $idx ?>" data-field="category" style="width:90px;">
                                                <option value="upah" <?= ($row['category'] ?? '') === 'upah' ? 'selected' : '' ?>>upah</option>
                                                <option value="material" <?= ($row['category'] ?? '') === 'material' ? 'selected' : '' ?>>material</option>
                                                <option value="alat" <?= ($row['category'] ?? '') === 'alat' ? 'selected' : '' ?>>alat</option>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm border-0 edit-cell" 
                                                   data-idx="<?= $idx ?>" data-field="unit"
                                                   value="<?= sanitize($row['unit'] ?? '') ?>" style="width:60px;">
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm border-0 text-end edit-cell" 
                                                   data-idx="<?= $idx ?>" data-field="price"
                                                   value="<?= number_format($row['price'] ?? 0, 0, ',', '.') ?>" style="width:100px;">
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm border-0 text-end edit-cell" 
                                                   data-idx="<?= $idx ?>" data-field="actual_price"
                                                   value="<?= ($row['actual_price'] ?? null) ? number_format($row['actual_price'], 0, ',', '.') : '' ?>" style="width:100px;">
                                        </td>
                                        <?php else: ?>
                                        <!-- Read-only mode for preview -->
                                        <td><code><?= sanitize($row['item_code'] ?? '') ?></code></td>
                                        <td><?= sanitize($row['name'] ?? '') ?></td>
                                        <td><?= sanitize($row['brand'] ?? '') ?: '-' ?></td>
                                        <td>
                                            <?php 
                                            $catClass = ['upah' => 'primary', 'material' => 'success', 'alat' => 'warning'];
                                            $cat = $row['category'] ?? '';
                                            ?>
                                            <span class="badge bg-<?= $catClass[$cat] ?? 'secondary' ?>"><?= $cat ?: '-' ?></span>
                                        </td>
                                        <td><?= sanitize($row['unit'] ?? '') ?: '-' ?></td>
                                        <td class="text-end"><?= formatRupiah($row['price'] ?? 0) ?></td>
                                        <td class="text-end"><?= ($row['actual_price'] ?? null) ? formatRupiah($row['actual_price']) : '-' ?></td>
                                        <?php endif; ?>
                                        <td class="keterangan-cell" id="error-<?= $idx ?>">
                                            <?php if ($row['error'] ?? ''): ?>
                                            <small class="text-danger"><?= sanitize($row['error']) ?></small>
                                            <?php elseif ($row['will_update'] ?? false): ?>
                                            <small class="text-info"><i class="mdi mdi-update"></i> Update</small>
                                            <?php else: ?>
                                            <small class="text-success"><i class="mdi mdi-plus-circle"></i> Baru</small>
                                            <?php endif; ?>
                                        </td>
                                    <?php else: ?>
                                    <!-- AHSP columns -->
                                    <td><code><?= sanitize($row['ahsp_code'] ?? '') ?></code></td>
                                    <td><?= sanitize($row['ahsp_name'] ?? '') ?></td>
                                    <td><?= sanitize($row['ahsp_unit'] ?? '') ?></td>
                                    <td>
                                        <small class="text-muted"><?= sanitize($row['item_identifier'] ?? $row['item_name'] ?? '') ?></small>
                                    </td>
                                    <td id="resolved-<?= $idx ?>">
                                        <?php if (($row['valid'] ?? false) && ($row['item_name'] ?? '')): ?>
                                        <strong><?= sanitize($row['item_name'] ?? '') ?></strong>
                                        <?php if ($row['item_code'] ?? ''): ?><br><code class="small"><?= sanitize($row['item_code'] ?? '') ?></code><?php endif; ?>
                                        <br><small class="text-success"><?= formatRupiah($row['item_price'] ?? 0) ?></small>
                                        <?php else: ?>
                                        <span class="text-danger">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= formatNumber($row['coefficient'] ?? 0, 4) ?></td>
                                    <td class="error-cell" id="error-<?= $idx ?>">
                                        <?php if ($row['error'] ?? ''): ?>
                                        <small class="text-danger"><?= sanitize($row['error'] ?? '') ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Hidden data for JavaScript -->
                    <?php
                    // Ensure data is JSON-safe by cleaning each row
                    $jsonSafeData = [];
                    foreach ($previewData as $idx => $row) {
                        if ($importType === 'items') {
                            $jsonSafeData[$idx] = [
                                'row' => $row['row'] ?? 0,
                                'item_code' => mb_convert_encoding($row['item_code'] ?? '', 'UTF-8', 'UTF-8'),
                                'name' => mb_convert_encoding($row['name'] ?? '', 'UTF-8', 'UTF-8'),
                                'main_name' => mb_convert_encoding($row['main_name'] ?? '', 'UTF-8', 'UTF-8'),
                                'sub_type' => mb_convert_encoding($row['sub_type'] ?? '', 'UTF-8', 'UTF-8'),
                                'brand' => mb_convert_encoding($row['brand'] ?? '', 'UTF-8', 'UTF-8'),
                                'category' => $row['category'] ?? '',
                                'unit' => mb_convert_encoding($row['unit'] ?? '', 'UTF-8', 'UTF-8'),
                                'price' => floatval($row['price'] ?? 0),
                                'actual_price' => isset($row['actual_price']) ? floatval($row['actual_price']) : null,
                                'error' => $row['error'] ?? null,
                                'valid' => $row['valid'] ?? false,
                            ];
                        } else {
                            // AHSP import type
                            $jsonSafeData[$idx] = [
                                'row' => $row['row'] ?? 0,
                                'ahsp_code' => mb_convert_encoding($row['ahsp_code'] ?? '', 'UTF-8', 'UTF-8'),
                                'ahsp_name' => mb_convert_encoding($row['ahsp_name'] ?? '', 'UTF-8', 'UTF-8'),
                                'ahsp_unit' => mb_convert_encoding($row['ahsp_unit'] ?? '', 'UTF-8', 'UTF-8'),
                                'item_identifier' => mb_convert_encoding($row['item_identifier'] ?? '', 'UTF-8', 'UTF-8'),
                                'item_code' => mb_convert_encoding($row['item_code'] ?? '', 'UTF-8', 'UTF-8'),
                                'item_name' => mb_convert_encoding($row['item_name'] ?? '', 'UTF-8', 'UTF-8'),
                                'item_id' => $row['item_id'] ?? null,
                                'item_unit' => $row['item_unit'] ?? '',
                                'item_price' => floatval($row['item_price'] ?? 0),
                                'coefficient' => floatval($row['coefficient'] ?? 0),
                                'error' => $row['error'] ?? null,
                                'valid' => $row['valid'] ?? false,
                            ];
                        }
                    }
                    $jsonData = json_encode(array_values($jsonSafeData), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                    if ($jsonData === false) {
                        $jsonData = '[]';
                    }
                    ?>
                    <script>
                        var previewData = <?= $jsonData ?>;
                        console.log('Preview data loaded:', previewData.length, 'rows');
                    </script>
                </div>
                <div class="card-footer d-flex justify-content-between flex-wrap gap-2">
                    <div class="d-flex gap-2">
                        <?php if ($isViewingDraft ?? false): ?>
                        <a href="import.php?project_id=<?= $projectId ?>&type=<?= $importType ?>" class="btn btn-secondary">
                            <i class="mdi mdi-arrow-left"></i> Kembali
                        </a>
                        <?php else: ?>
                        <a href="import.php?project_id=<?= $projectId ?>&type=<?= $importType ?>" class="btn btn-secondary">
                            <i class="mdi mdi-arrow-left"></i> Upload Ulang
                        </a>
                        <?php endif; ?>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <?php if (!($isViewingDraft ?? false)): ?>
                        <form method="POST" id="saveDraftForm" class="d-inline">
                            <input type="hidden" name="step" value="save_draft">
                            <input type="hidden" name="draft_data" id="draftData">
                            <button type="submit" class="btn btn-warning btn-lg" id="saveDraftBtn">
                                <i class="mdi mdi-content-save"></i> Simpan Draft
                            </button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" id="importForm" class="d-inline">
                            <input type="hidden" name="step" value="confirm">
                            <input type="hidden" name="edited_data" id="editedData">
                            <button type="submit" class="btn btn-success btn-lg" id="importBtn" <?= $validCount == 0 ? 'disabled' : '' ?>>
                                <i class="mdi mdi-check"></i> Import <span id="validCountBtn"><?= $validCount ?></span> Data Valid
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <!-- JavaScript for import -->
            <script>
            var projectId = <?= $projectId ?>;
            var importType = '<?= $importType ?>';
            
            document.addEventListener('DOMContentLoaded', function() {
                // Before import form submit, update hidden data with current values
                var importForm = document.getElementById('importForm');
                if (importForm) {
                    importForm.addEventListener('submit', function(e) {
                        if (typeof previewData !== 'undefined') {
                            document.getElementById('editedData').value = JSON.stringify(previewData);
                        }
                    });
                }
                
                // Before save draft form submit, update hidden data with current values
                var saveDraftForm = document.getElementById('saveDraftForm');
                if (saveDraftForm) {
                    saveDraftForm.addEventListener('submit', function(e) {
                        if (typeof previewData !== 'undefined') {
                            document.getElementById('draftData').value = JSON.stringify(previewData);
                        }
                    });
                }
                
                // Inline edit handler
                document.querySelectorAll('.edit-cell').forEach(function(input) {
                    input.addEventListener('change', function() {
                        var idx = parseInt(this.dataset.idx);
                        var field = this.dataset.field;
                        var value = this.value.trim();
                        
                        // Convert price fields
                        if (field === 'price' || field === 'actual_price') {
                            // Remove dots and replace comma with dot for number parsing
                            value = value.replace(/\./g, '').replace(',', '.');
                            value = parseFloat(value) || 0;
                            if (field === 'actual_price' && this.value.trim() === '') {
                                value = null;
                            }
                        }
                        
                        // Update previewData
                        if (previewData[idx]) {
                            previewData[idx][field] = value;
                            
                            // Re-validate this row
                            validateRow(idx);
                            
                            // Update counts
                            updateValidationCounts();
                        }
                    });
                    
                    // Focus styling
                    input.addEventListener('focus', function() {
                        this.classList.add('bg-light');
                    });
                    input.addEventListener('blur', function() {
                        this.classList.remove('bg-light');
                    });
                });
                
                // Validate row function
                function validateRow(idx) {
                    var row = previewData[idx];
                    if (!row) return;
                    
                    var errors = [];
                    
                    if (importType === 'items') {
                        // Check required fields
                        if (!row.item_code || row.item_code.trim() === '') {
                            errors.push('Kode item wajib diisi');
                        }
                        if (!row.name || row.name.trim() === '') {
                            errors.push('Nama item wajib diisi');
                        }
                        if (!row.category || !['upah', 'material', 'alat'].includes(row.category)) {
                            errors.push('Kategori tidak valid');
                        }
                        if (!row.unit || row.unit.trim() === '') {
                            errors.push('Satuan wajib diisi');
                        }
                        if (!row.price || row.price <= 0) {
                            errors.push('Harga PU wajib diisi');
                        }
                    }
                    
                    // Update row validation status
                    var wasValid = row.valid;
                    row.valid = errors.length === 0;
                    row.error = errors.join(', ');
                    
                    // Update UI
                    var tableRow = document.querySelector('tr[data-row-idx="' + idx + '"]');
                    var statusCell = document.getElementById('status-' + idx);
                    var errorCell = document.getElementById('error-' + idx);
                    
                    if (tableRow) {
                        if (row.valid) {
                            tableRow.classList.remove('table-danger');
                            if (statusCell) statusCell.innerHTML = '<span class="text-success"><i class="mdi mdi-check-circle"></i></span>';
                            if (errorCell) errorCell.innerHTML = '<small class="text-success"><i class="mdi mdi-plus-circle"></i> Baru</small>';
                        } else {
                            tableRow.classList.add('table-danger');
                            if (statusCell) statusCell.innerHTML = '<span class="text-danger"><i class="mdi mdi-close-circle"></i></span>';
                            if (errorCell) errorCell.innerHTML = '<small class="text-danger">' + row.error + '</small>';
                        }
                    }
                }
                
                // Update validation counts
                function updateValidationCounts() {
                    var validCount = 0;
                    var errorCount = 0;
                    
                    previewData.forEach(function(row) {
                        if (row.valid) {
                            validCount++;
                        } else {
                            errorCount++;
                        }
                    });
                    
                    document.getElementById('validCount').textContent = '✓ Valid: ' + validCount;
                    document.getElementById('validCountBtn').textContent = validCount;
                    
                    var errorBadge = document.getElementById('errorCount');
                    if (errorCount > 0) {
                        errorBadge.textContent = '✗ Error: ' + errorCount;
                        errorBadge.style.display = 'inline';
                    } else {
                        errorBadge.style.display = 'none';
                    }
                    
                    // Enable/disable import button
                    var importBtn = document.getElementById('importBtn');
                    if (importBtn) {
                        importBtn.disabled = validCount === 0;
                    }
                }
                
                // Jump-to-error functionality
                var currentErrorIndex = -1;
                var errorBadge = document.getElementById('errorCount');
                if (errorBadge) {
                    errorBadge.addEventListener('click', function() {
                        // Get all error rows
                        var errorRows = document.querySelectorAll('tr.table-danger');
                        if (errorRows.length === 0) {
                            return;
                        }
                        
                        // Cycle to next error
                        currentErrorIndex++;
                        if (currentErrorIndex >= errorRows.length) {
                            currentErrorIndex = 0;
                        }
                        
                        var targetRow = errorRows[currentErrorIndex];
                        
                        // Remove highlight from all rows
                        errorRows.forEach(function(row) {
                            row.classList.remove('highlight-error');
                        });
                        
                        // Add highlight to current row
                        targetRow.classList.add('highlight-error');
                        
                        // Smooth scroll to row
                        targetRow.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                        
                        // Update badge to show position
                        this.textContent = '✗ Error: ' + (currentErrorIndex + 1) + '/' + errorRows.length;
                        
                        // Flash animation
                        targetRow.style.transition = 'background-color 0.3s';
                        setTimeout(function() {
                            targetRow.classList.remove('highlight-error');
                        }, 2000);
                    });
                }
            });
            </script>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<!-- Link Item Modal for AHSP Import -->
<div class="modal fade" id="linkItemModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="mdi mdi-link-variant"></i> Hubungkan ke Item Master Data</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="linkItemIdx">
                <div class="alert alert-info small">
                    <strong>Input Asli:</strong> <span id="linkItemOriginal"></span>
                </div>
                <div class="mb-3">
                    <label class="form-label">Cari Item di Master Data:</label>
                    <input type="text" class="form-control" id="linkItemSearch" placeholder="🔍 Ketik nama atau kode item...">
                </div>
                <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                    <table class="table table-sm table-hover" id="linkItemTable">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Kode</th>
                                <th>Nama Item</th>
                                <th>Kategori</th>
                                <th>Satuan</th>
                                <th class="text-end">Harga</th>
                                <th width="60">Pilih</th>
                            </tr>
                        </thead>
                        <tbody id="linkItemTableBody">
                            <!-- Items will be loaded via JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Master Data items for linking (load from PHP)
<?php 
$projectId = $projectId ?? 0;
$allItems = [];
if ($projectId > 0) {
    $allItems = dbGetAll("SELECT id, item_code, name, category, unit, price FROM project_items WHERE project_id = ? ORDER BY category, name", [$projectId]);
}
?>
var masterDataItems = <?= json_encode($allItems) ?>;
var linkItemModal = null;

document.addEventListener('DOMContentLoaded', function() {
    // Initialize modal
    var modalEl = document.getElementById('linkItemModal');
    if (modalEl) {
        linkItemModal = new bootstrap.Modal(modalEl);
    }
    
    // Handle link button clicks
    document.querySelectorAll('.link-item-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var idx = this.dataset.idx;
            var itemInput = this.dataset.itemInput;
            openLinkItemModal(idx, itemInput);
        });
    });
    
    // Search filter for link item modal
    var searchInput = document.getElementById('linkItemSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            filterLinkItemTable(this.value);
        });
    }
});

function openLinkItemModal(idx, originalInput) {
    document.getElementById('linkItemIdx').value = idx;
    document.getElementById('linkItemOriginal').textContent = originalInput;
    document.getElementById('linkItemSearch').value = '';
    
    // Populate table with all items
    renderLinkItemTable(masterDataItems);
    
    if (linkItemModal) {
        linkItemModal.show();
    }
}

function filterLinkItemTable(searchText) {
    var filtered = masterDataItems.filter(function(item) {
        var search = searchText.toLowerCase();
        return (item.item_code && item.item_code.toLowerCase().indexOf(search) > -1) ||
               (item.name && item.name.toLowerCase().indexOf(search) > -1);
    });
    renderLinkItemTable(filtered);
}

function renderLinkItemTable(items) {
    var tbody = document.getElementById('linkItemTableBody');
    tbody.innerHTML = '';
    
    var catLabels = {upah: 'Upah', material: 'Material', alat: 'Alat'};
    var catColors = {upah: 'primary', material: 'success', alat: 'warning'};
    
    items.forEach(function(item) {
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><code>' + (item.item_code || '-') + '</code></td>' +
            '<td>' + item.name + '</td>' +
            '<td><span class="badge bg-' + (catColors[item.category] || 'secondary') + '">' + (catLabels[item.category] || item.category) + '</span></td>' +
            '<td>' + item.unit + '</td>' +
            '<td class="text-end">' + formatRupiah(item.price) + '</td>' +
            '<td><button type="button" class="btn btn-sm btn-success" onclick="selectLinkItem(' + item.id + ', \'' + escapeHtml(item.item_code || '') + '\', \'' + escapeHtml(item.name) + '\', ' + item.price + ')"><i class="mdi mdi-check"></i></button></td>';
        tbody.appendChild(tr);
    });
    
    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Tidak ada item ditemukan</td></tr>';
    }
}

function selectLinkItem(itemId, itemCode, itemName, itemPrice) {
    var idx = parseInt(document.getElementById('linkItemIdx').value);
    
    // Update previewData
    if (typeof previewData !== 'undefined' && previewData[idx]) {
        previewData[idx].item_id = itemId;
        previewData[idx].item_code = itemCode;
        previewData[idx].item_name = itemName;
        previewData[idx].item_price = itemPrice;
        previewData[idx].error = null;
        previewData[idx].valid = true;
    }
    
    // Update UI
    var row = document.querySelector('tr[data-row-idx="' + idx + '"]');
    if (row) {
        row.classList.remove('table-danger');
        
        // Update status
        var statusCell = document.getElementById('status-' + idx);
        if (statusCell) {
            statusCell.innerHTML = '<span class="text-success"><i class="mdi mdi-check-circle"></i></span>';
        }
        
        // Update resolved item cell
        var resolvedCell = document.getElementById('resolved-' + idx);
        if (resolvedCell) {
            resolvedCell.innerHTML = '<strong>' + itemName + '</strong>' +
                (itemCode ? '<br><code class="small">' + itemCode + '</code>' : '') +
                '<br><small class="text-success">' + formatRupiah(itemPrice) + '</small>';
        }
        
        // Update error cell
        var errorCell = document.getElementById('error-' + idx);
        if (errorCell) {
            errorCell.innerHTML = '';
        }
        
        // Update action cell
        var actionCell = document.getElementById('action-' + idx);
        if (actionCell) {
            actionCell.innerHTML = '<span class="text-success"><i class="mdi mdi-check"></i></span>';
        }
    }
    
    // Update counters
    updateCounters();
    
    // Save to database if in draft mode
    if (typeof isDraftMode !== 'undefined' && isDraftMode) {
        saveAhspLinkToDatabase(idx, itemId);
    }
    
    // Close modal
    if (linkItemModal) {
        linkItemModal.hide();
    }
}

function saveAhspLinkToDatabase(idx, itemId) {
    var formData = new FormData();
    formData.append('action', 'update_draft_link');
    formData.append('idx', idx);
    formData.append('item_id', itemId);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Link saved to draft');
        }
    })
    .catch(err => console.error('Save link error:', err));
}

function updateCounters() {
    if (typeof previewData === 'undefined') return;
    
    var validCount = 0;
    var errorCount = 0;
    previewData.forEach(function(row) {
        if (row.valid) validCount++;
        else errorCount++;
    });
    
    var validEl = document.getElementById('validCount');
    var errorEl = document.getElementById('errorCount');
    
    if (validEl) {
        validEl.textContent = '✓ Valid: ' + validCount;
    }
    if (errorEl) {
        errorEl.textContent = '✗ Error: ' + errorCount;
        errorEl.style.display = errorCount > 0 ? '' : 'none';
    }
}

function formatRupiah(num) {
    return 'Rp ' + Number(num).toLocaleString('id-ID');
}

function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML.replace(/'/g, "\\'");
}
</script>
