<?php
/**
 * Actual Tab - Project Dashboard
 * Shows actual spending vs RAP with breakdown by Upah, Material, Alat
 * Displays hierarchical view (Category -> Subcategory) like RAP page
 * Progress calculated: Category progress = average of subcategory progress
 * Weekly Progress: Shows weekly date columns when project is started
 */

// Get category filter (all, upah, material, alat)
$categoryFilter = $_GET['cat'] ?? 'all';
$validFilters = ['all', 'upah', 'material', 'alat'];
if (!in_array($categoryFilter, $validFilters)) {
    $categoryFilter = 'all';
}

// Get target type (rap, rab) - default is rap
$targetType = $_GET['target'] ?? 'rap';
if (!in_array($targetType, ['rap', 'rab'])) {
    $targetType = 'rap';
}
$isRabTarget = ($targetType === 'rab');
$targetLabel = $isRabTarget ? 'RAB' : 'RAP';

// Get project settings
$ppnPercentage = $project['ppn_percentage'] ?? 11;
$overheadPct = getProjectOverheadProfitPct($project, $targetType);

// Generate weekly ranges if project is started (not draft) OR has weekly history
$weeklyRanges = [];
$showWeeklyColumns = false;

// Check if there's any weekly history
$hasWeeklyHistory = dbGetRow("SELECT 1 FROM weekly_progress WHERE project_id = ? LIMIT 1", [$projectId]) ? true : false;

// Show weekly columns if project is on_progress/completed OR has history
if ($project['status'] !== 'draft' || $hasWeeklyHistory) {
    if (!empty($project['start_date']) && !empty($project['duration_days'])) {
        $weeklyRanges = generateWeeklyRanges($project['start_date'], $project['duration_days']);
        $showWeeklyColumns = !empty($weeklyRanges);
    }
}

// Get all weekly progress data for this project (for performance)
$weeklyData = [];
if ($showWeeklyColumns) {
    $allWeeklyProgress = dbGetAll("
        SELECT subcategory_id, week_number, realization_amount
        FROM weekly_progress
        WHERE project_id = ?
    ", [$projectId]);
    
    foreach ($allWeeklyProgress as $wp) {
        $weeklyData[$wp['subcategory_id']][$wp['week_number']] = $wp['realization_amount'];
    }
}

// Batch-load ALL data needed for subcategory calculations (instead of N queries per subcategory)
$rapAhspBreakdownMap = batchGetRapAhspComponentBreakdowns($projectId);
$rabAhspBreakdownMap = batchGetAhspComponentBreakdowns($projectId);
$actualizationAdjustments = batchGetActualizationAdjustments($projectId);
$batchActualSpending = batchGetActualSpendingBySubcategory($projectId);
$batchActualBreakdown = batchGetActualBreakdownBySubcategory($projectId);

// Get Biaya Lain-Lain / Non-RAB data
$nonRabTransactions = getProjectNonRabTransactions($projectId);
$totalNonRabActual = getProjectNonRabActualTotal($projectId);

// Get categories
$categories = dbGetAll("
    SELECT rc.id, rc.code, rc.name, rc.sort_order
    FROM rab_categories rc
    WHERE rc.project_id = ?
    ORDER BY rc.sort_order, rc.code
", [$projectId]);

// Build hierarchical data with subcategory details
$actualData = [];

foreach ($categories as $cat) {
    $catId = $cat['id'];
    
    // Get subcategories with RAP data, AHSP code, and AHSP id
    $subcats = dbGetAll("
        SELECT rs.id, rs.code, rs.name, rs.unit, rs.volume as rab_volume, rs.unit_price as rab_unit_price,
               rs.ahsp_id,
               rap.id as rap_id, rap.volume as rap_volume, rap.unit_price as rap_unit_price,
               pa.ahsp_code
        FROM rab_subcategories rs
        LEFT JOIN rap_items rap ON rs.id = rap.subcategory_id
        LEFT JOIN project_ahsp pa ON rs.ahsp_id = pa.id
        WHERE rs.category_id = ?
        ORDER BY rs.sort_order, rs.code
    ", [$catId]);
    
    $subcatData = [];
    $catTargetTotal = 0;
    $catActualTotal = 0;
    $catTargetUpah = 0;
    $catTargetMaterial = 0;
    $catTargetAlat = 0;
    $catActualUpah = 0;
    $catActualMaterial = 0;
    $catActualAlat = 0;
    $subcatProgressSum = 0;
    $subcatCount = 0;
    $catWeeklyTotals = [];
    
    foreach ($subcats as $sub) {
        if ($isRabTarget) {
            // Target RAB Mode
            $volume = floatval($sub['rab_volume']);
            $rabComponents = ($sub['ahsp_id'] && isset($rabAhspBreakdownMap[$sub['ahsp_id']])) 
                ? $rabAhspBreakdownMap[$sub['ahsp_id']] 
                : ['upah' => 0.0, 'material' => 0.0, 'alat' => 0.0, 'total' => 0.0];
            
            $baseUnitPrice = $rabComponents['total'];
            if ($baseUnitPrice <= 0) {
                $baseUnitPrice = (isset($sub['rab_unit_price']) && floatval($sub['rab_unit_price']) > 0) 
                    ? floatval($sub['rab_unit_price']) 
                    : 0.0;
            }
            
            $unitPriceWithOverhead = $baseUnitPrice * (1 + ($overheadPct / 100));
            $subTargetTotal = $volume * $unitPriceWithOverhead;
            
            $subTargetUpah = $rabComponents['upah'] * (1 + ($overheadPct / 100)) * $volume;
            $subTargetMaterial = $rabComponents['material'] * (1 + ($overheadPct / 100)) * $volume;
            $subTargetAlat = $rabComponents['alat'] * (1 + ($overheadPct / 100)) * $volume;
        } else {
            // Target RAP Mode
            $volume = (isset($sub['rap_volume']) && $sub['rap_volume'] !== null) 
                ? floatval($sub['rap_volume']) 
                : floatval($sub['rab_volume']);
            
            $ahspCode = $sub['ahsp_code'] ?? null;
            $rapComponents = $ahspCode 
                ? ($rapAhspBreakdownMap[$ahspCode] ?? ['upah' => 0.0, 'material' => 0.0, 'alat' => 0.0]) 
                : ['upah' => 0.0, 'material' => 0.0, 'alat' => 0.0];
            
            $baseUnitPrice = $rapComponents['upah'] + $rapComponents['material'] + $rapComponents['alat'];
            if ($baseUnitPrice <= 0) {
                $baseUnitPrice = (isset($sub['rap_unit_price']) && floatval($sub['rap_unit_price']) > 0) 
                    ? floatval($sub['rap_unit_price']) 
                    : floatval($sub['rab_unit_price']);
            }
            
            $unitPriceWithOverhead = $baseUnitPrice * (1 + ($overheadPct / 100));
            $subTargetTotal = $volume * $unitPriceWithOverhead;
            
            $subTargetUpah = $rapComponents['upah'] * (1 + ($overheadPct / 100)) * $volume;
            $subTargetMaterial = $rapComponents['material'] * (1 + ($overheadPct / 100)) * $volume;
            $subTargetAlat = $rapComponents['alat'] * (1 + ($overheadPct / 100)) * $volume;
        }
        
        // Use pre-loaded actual spending (was 1 query per subcategory)
        $subActualTotal = $batchActualSpending[$sub['id']] ?? 0.0;
        
        // Use pre-loaded actual breakdown (was 1 query per subcategory)
        $breakdown = $batchActualBreakdown[$sub['id']] ?? ['upah' => 0.0, 'material' => 0.0, 'alat' => 0.0];
        $subActualUpah = $breakdown['upah'];
        $subActualMaterial = $breakdown['material'];
        $subActualAlat = $breakdown['alat'];
        
        // Adjust for actualization: subtract remaining budget from actualized requests
        $adjTotal = $actualizationAdjustments[$sub['id']] ?? 0;
        if ($adjTotal > 0) {
            // Distribute deduction proportionally across categories based on actual spending ratio
            $subActualSum = $subActualUpah + $subActualMaterial + $subActualAlat;
            if ($subActualSum > 0) {
                $subActualUpah -= $adjTotal * ($subActualUpah / $subActualSum);
                $subActualMaterial -= $adjTotal * ($subActualMaterial / $subActualSum);
                $subActualAlat -= $adjTotal * ($subActualAlat / $subActualSum);
            }
            $subActualTotal -= $adjTotal;
        }
        
        // Calculate item progress
        $subProgress = $subTargetTotal > 0 ? ($subActualTotal / $subTargetTotal) * 100 : 0;
        
        // Selisih = Target - Aktual (positif = sisa anggaran, negatif = overbudget)
        $subSelisih = $subTargetTotal - $subActualTotal;
        
        // Get weekly data for this subcategory
        $subWeeklyData = $weeklyData[$sub['id']] ?? [];
        
        if ($showWeeklyColumns) {
            foreach ($weeklyRanges as $week) {
                $weekNum = $week['week_number'];
                $weekRealization = $subWeeklyData[$weekNum] ?? 0;
                if (!isset($catWeeklyTotals[$weekNum])) {
                    $catWeeklyTotals[$weekNum] = 0;
                }
                $catWeeklyTotals[$weekNum] += $weekRealization;
            }
        }
        
        $subcatData[] = [
            'id' => $sub['id'],
            'code' => $sub['code'],
            'name' => $sub['name'],
            'unit' => $sub['unit'],
            'target_total' => $subTargetTotal,
            'target_upah' => $subTargetUpah,
            'target_material' => $subTargetMaterial,
            'target_alat' => $subTargetAlat,
            'rap_total' => $subTargetTotal,
            'rap_upah' => $subTargetUpah,
            'rap_material' => $subTargetMaterial,
            'rap_alat' => $subTargetAlat,
            'actual_total' => $subActualTotal,
            'actual_upah' => $subActualUpah,
            'actual_material' => $subActualMaterial,
            'actual_alat' => $subActualAlat,
            'selisih' => $subSelisih,
            'progress' => $subProgress,
            'weekly' => $subWeeklyData
        ];
        
        // Accumulate category totals
        $catTargetTotal += $subTargetTotal;
        $catActualTotal += $subActualTotal;
        $catTargetUpah += $subTargetUpah;
        $catTargetMaterial += $subTargetMaterial;
        $catTargetAlat += $subTargetAlat;
        $catActualUpah += $subActualUpah;
        $catActualMaterial += $subActualMaterial;
        $catActualAlat += $subActualAlat;
        
        // For average progress calculation
        if ($subTargetTotal > 0) {
            $subcatProgressSum += $subProgress;
            $subcatCount++;
        }
    }
    
    // Category progress = Average of subcategory progress
    $catProgress = $subcatCount > 0 ? ($subcatProgressSum / $subcatCount) : 0;
    $catSelisih = $catTargetTotal - $catActualTotal;
    
    $actualData[$catId] = [
        'category' => $cat,
        'subcategories' => $subcatData,
        'target_total' => $catTargetTotal,
        'target_upah' => $catTargetUpah,
        'target_material' => $catTargetMaterial,
        'target_alat' => $catTargetAlat,
        'rap_total' => $catTargetTotal,
        'actual_total' => $catActualTotal,
        'rap_upah' => $catTargetUpah,
        'rap_material' => $catTargetMaterial,
        'rap_alat' => $catTargetAlat,
        'actual_upah' => $catActualUpah,
        'actual_material' => $catActualMaterial,
        'actual_alat' => $catActualAlat,
        'selisih' => $catSelisih,
        'progress' => $catProgress,
        'subcat_count' => $subcatCount,
        'weekly_totals' => $catWeeklyTotals
    ];
}

// Automatically append "Lain-Lain" category at the bottom if there are approved Non-RAB transactions
if (!empty($nonRabTransactions)) {
    // Determine category code (next letter after last category)
    $lastCatCode = '';
    if (!empty($categories)) {
        $lastCat = end($categories);
        $lastCatCode = trim($lastCat['code']);
    }
    if (preg_match('/^[A-Z]$/i', $lastCatCode)) {
        $nonRabCatCode = chr(ord(strtoupper($lastCatCode)) + 1);
    } elseif (is_numeric($lastCatCode)) {
        $nonRabCatCode = (string)(intval($lastCatCode) + 1);
    } else {
        $nonRabCatCode = 'L';
    }

    $nonRabCatName = 'LAIN-LAIN';
    $nonRabCatId = 'non_rab';

    $nonRabSubcats = [];
    $nonRabCatActualTotal = 0;
    $nonRabWeeklyTotals = [];

    $itemNo = 1;
    foreach ($nonRabTransactions as $tx) {
        $txAmount = floatval($tx['total_amount'] ?? $tx['total_price'] ?? 0);
        $txWeek = intval($tx['target_week'] ?: ($tx['week_number'] ?: 1));

        $txWeekly = [];
        if ($showWeeklyColumns) {
            foreach ($weeklyRanges as $week) {
                $wNum = $week['week_number'];
                $isTargetWeek = ($wNum === $txWeek);
                $txWeekly[$wNum] = $isTargetWeek ? $txAmount : 0;
                if (!isset($nonRabWeeklyTotals[$wNum])) {
                    $nonRabWeeklyTotals[$wNum] = 0;
                }
                if ($isTargetWeek) {
                    $nonRabWeeklyTotals[$wNum] += $txAmount;
                }
            }
        }

        $nonRabSubcats[] = [
            'id' => 'nr_' . ($tx['item_id'] ?? $itemNo),
            'is_non_rab' => true,
            'request_id' => $tx['request_id'],
            'request_number' => $tx['request_number'],
            'code' => $nonRabCatCode . '.' . $itemNo,
            'name' => $tx['item_name'],
            'unit' => $tx['unit'] ?: 'ls',
            'quantity' => floatval($tx['quantity'] ?: 1),
            'unit_price' => floatval($tx['unit_price'] ?: $txAmount),
            'notes' => $tx['notes'] ?? '',
            'receipt_file' => $tx['receipt_file'] ?? '',
            'request_date' => $tx['request_date'] ?? $tx['created_at'],
            'target_total' => 0.0,
            'target_upah' => 0.0,
            'target_material' => 0.0,
            'target_alat' => 0.0,
            'rap_total' => 0.0,
            'rap_upah' => 0.0,
            'rap_material' => 0.0,
            'rap_alat' => 0.0,
            'actual_total' => $txAmount,
            'actual_upah' => 0.0,
            'actual_material' => 0.0,
            'actual_alat' => 0.0,
            'selisih' => -$txAmount,
            'progress' => 0.0,
            'weekly' => $txWeekly
        ];

        $nonRabCatActualTotal += $txAmount;
        $itemNo++;
    }

    $actualData[$nonRabCatId] = [
        'category' => [
            'id' => $nonRabCatId,
            'code' => $nonRabCatCode,
            'name' => $nonRabCatName,
            'sort_order' => 9999
        ],
        'is_non_rab' => true,
        'subcategories' => $nonRabSubcats,
        'target_total' => 0.0,
        'target_upah' => 0.0,
        'target_material' => 0.0,
        'target_alat' => 0.0,
        'rap_total' => 0.0,
        'actual_total' => $nonRabCatActualTotal,
        'rap_upah' => 0.0,
        'rap_material' => 0.0,
        'rap_alat' => 0.0,
        'actual_upah' => 0.0,
        'actual_material' => 0.0,
        'actual_alat' => 0.0,
        'selisih' => -$nonRabCatActualTotal,
        'progress' => 0.0,
        'subcat_count' => count($nonRabSubcats),
        'weekly_totals' => $nonRabWeeklyTotals
    ];
}
?>

<?php if (empty($actualData)): ?>
<div class="text-center py-5">
    <i class="mdi mdi-chart-bar display-4 text-muted"></i>
    <h5 class="mt-3">Belum ada data RAB</h5>
    <p class="text-muted">Silakan buat RAB terlebih dahulu.</p>
</div>
<?php else: ?>

<!-- Controls: Target Selector & Category Filter -->
<div class="mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <!-- Target Selector (RAP vs RAB) -->
        <div class="d-flex align-items-center gap-1">
            <span class="text-muted small fw-semibold me-1"><i class="mdi mdi-target"></i> Acuan Target:</span>
            <div class="btn-group" role="group" aria-label="Pilih Acuan Target">
                <a href="?id=<?= $projectId ?>&tab=actual&target=rap&cat=<?= $categoryFilter ?>" 
                   class="btn btn-sm <?= $targetType === 'rap' ? 'btn-info text-white fw-bold shadow-sm' : 'btn-outline-info' ?>" 
                   title="Gunakan RAP sebagai angka target / acuan">
                    <i class="mdi mdi-bullseye-arrow"></i> RAP (Target)
                </a>
                <a href="?id=<?= $projectId ?>&tab=actual&target=rab&cat=<?= $categoryFilter ?>" 
                   class="btn btn-sm <?= $targetType === 'rab' ? 'btn-info text-white fw-bold shadow-sm' : 'btn-outline-info' ?>"
                   title="Gunakan RAB sebagai angka target / acuan">
                    <i class="mdi mdi-file-document-box-check-outline"></i> RAB (Target)
                </a>
            </div>
        </div>

        <div class="vr d-none d-md-block" style="height: 24px;"></div>

        <!-- Category Filter Buttons -->
        <div class="d-flex align-items-center gap-1">
            <span class="text-muted small fw-semibold me-1"><i class="mdi mdi-filter-variant"></i> Kategori:</span>
            <div class="btn-group" role="group" aria-label="Filter Kategori Anggaran">
                <a href="?id=<?= $projectId ?>&tab=actual&target=<?= $targetType ?>&cat=all" 
                   class="btn btn-sm <?= $categoryFilter === 'all' ? 'btn-dark' : 'btn-outline-dark' ?>">
                    <i class="mdi mdi-view-list"></i> Semua
                </a>
                <a href="?id=<?= $projectId ?>&tab=actual&target=<?= $targetType ?>&cat=upah" 
                   class="btn btn-sm <?= $categoryFilter === 'upah' ? 'btn-primary' : 'btn-outline-primary' ?>">
                    <i class="mdi mdi-account-hard-hat"></i> Upah
                </a>
                <a href="?id=<?= $projectId ?>&tab=actual&target=<?= $targetType ?>&cat=material" 
                   class="btn btn-sm <?= $categoryFilter === 'material' ? 'btn-success' : 'btn-outline-success' ?>">
                    <i class="mdi mdi-package-variant"></i> Material
                </a>
                <a href="?id=<?= $projectId ?>&tab=actual&target=<?= $targetType ?>&cat=alat" 
                   class="btn btn-sm <?= $categoryFilter === 'alat' ? 'btn-warning' : 'btn-outline-warning' ?>">
                    <i class="mdi mdi-tools"></i> Alat
                </a>
            </div>
        </div>
    </div>

    <div class="d-flex align-items-center gap-2 flex-wrap">
        <?php if ($targetType === 'rab' || $categoryFilter !== 'all'): ?>
        <div class="alert alert-info py-1 px-3 mb-0">
            <small>
                <i class="mdi mdi-information-outline"></i> Acuan: <strong>Target <?= $targetLabel ?></strong>
                <?php if ($categoryFilter !== 'all'): ?>
                | Kategori: <strong><?= ucfirst($categoryFilter) ?></strong>
                <?php endif; ?>
            </small>
        </div>
        <?php endif; ?>
        
        <!-- Export Dropdown -->
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle text-nowrap" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="mdi mdi-file-export-outline"></i> Export
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><h6 class="dropdown-header">Export CSV (Target <?= $targetLabel ?>)</h6></li>
                <li>
                    <a class="dropdown-item" href="export_actual.php?id=<?= $projectId ?>&target=<?= $targetType ?>">
                        <i class="mdi mdi-file-document-outline"></i> Export CSV Laporan
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li><h6 class="dropdown-header">Export PDF (Target <?= $targetLabel ?>)</h6></li>
                <li>
                    <a class="dropdown-item" href="javascript:void(0);" onclick="openPdfPreviewActual()">
                        <i class="mdi mdi-file-pdf-box text-danger"></i> Export PDF
                        <small class="d-block text-muted">Preview laporan lalu cetak/export ke PDF</small>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>

<?php 
// Calculate total columns for footer (base + weekly)
$baseColCount = 9; // No, Uraian, RAP, Upah, Material, Alat, Total, Selisih, Progress
$weeklyColCount = $showWeeklyColumns ? count($weeklyRanges) * 3 : 0; // Each week has 3 columns
$totalColCount = $baseColCount + $weeklyColCount;

// Define sticky column positions (cumulative left values)
// Col widths: No=50px, Uraian=220px, RAP=150px, Upah=130px, Material=130px, Alat=130px, Total=140px, Selisih=150px, Progress=120px
$stickyPositions = [
    0 => 0,           // No
    1 => 50,          // Uraian Pekerjaan
    2 => 270,         // RAP (Target)
    3 => 420,         // Upah
    4 => 550,         // Material
    5 => 680,         // Alat
    6 => 810,         // Total
    7 => 950,         // Selisih
    8 => 1100         // Progress
];
$lastStickyRight = 1220; // Total width of sticky area
?>

<style>
/* Ensure main-content allows sticky to viewport */
.main-content {
    overflow: visible !important;
}

/* Wrapper with horizontal scroll */
.actual-scroll-wrapper {
    width: 100%;
    overflow-x: auto;
    position: relative;
}

/* Table base styling */
.actual-table {
    border-collapse: separate;
    border-spacing: 0;
    min-width: <?= $showWeeklyColumns ? (1220 + (count($weeklyRanges) * 300)) : 1220 ?>px;
    --actual-topbar-offset: 70px;
    --actual-r1-height: 48px;
}

/* Prevent text wrapping in all data cells */
.actual-table td,
.actual-table th {
    white-space: nowrap;
}
/* Allow uraian column to wrap text normally */
.actual-table .col-uraian {
    white-space: normal;
    max-width: 220px;
}

/* All thead th default styling */
.actual-table thead th {
    background-color: #212529;
    color: #fff;
    border-top: 1px solid #373b3e !important;
    border-bottom: 1px solid #373b3e !important;
    border-left: 1px solid #373b3e !important;
    border-right: 1px solid #373b3e !important;
}

/* Sticky column styles for horizontal scroll */
.sticky-col {
    position: sticky;
    z-index: 5;
    background-color: #fff;
}

/* When sticky column is in header, it sticks on left -> highest z-index */
.sticky-col-header {
    position: sticky;
    z-index: 30 !important;
    background-color: #212529 !important;
    color: #fff !important;
}

/* Column position classes */
.col-no { left: 0px; min-width: 50px; max-width: 50px; }
.col-uraian { left: 50px; min-width: 220px; max-width: 220px; }
.col-rap { left: 270px; min-width: 150px; max-width: 150px; }

<?php if ($categoryFilter !== 'all'): ?>
.col-upah { min-width: 150px; max-width: 150px; } /* Single realisasi column */
.col-selisih { min-width: 150px; max-width: 150px; }
.col-progress { min-width: 120px; max-width: 120px; }
<?php else: ?>
.col-upah { min-width: 130px; max-width: 130px; }
.col-material { min-width: 130px; max-width: 130px; }
.col-alat { min-width: 130px; max-width: 130px; }
.col-total { min-width: 140px; max-width: 140px; }
.col-selisih { min-width: 150px; max-width: 150px; }
.col-progress { min-width: 120px; max-width: 120px; }
<?php endif; ?>

/* Row background colors for sticky cells */
.table-primary .sticky-col { background-color: #cfe2ff !important; }
.table-secondary .sticky-col { background-color: #e2e3e5 !important; }
.table-dark .sticky-col { background-color: #212529 !important; color: #fff !important; }
.table-light .sticky-col { background-color: #f8f9fa !important; }
.sub-row .sticky-col { background-color: #fff !important; }

/* Weekly column styling */
.weekly-col {
    background-color: #f0f9ff;
    border-left: 2px solid #212529;
    border-color: #212529 !important;
    min-width: 120px;
}

/* Headers with rowspan="2" in Row 1 */
.actual-table thead tr.actual-header-row-1 th[rowspan="2"],
.actual-floating-header-wrapper th[rowspan="2"] {
    height: 88px !important;
    vertical-align: middle !important;
    background-color: #212529 !important;
    color: #fff !important;
}

/* Weekly Main Header (Row 1) */
.actual-table thead tr.actual-header-row-1 th.weekly-header,
.actual-floating-header-wrapper th.weekly-header {
    background-color: #0dcaf0 !important;
    color: #000 !important;
    border-color: #212529 !important;
    white-space: normal;
    height: 52px !important;
    line-height: 1.2 !important;
    padding: 4px 6px !important;
    font-size: 13px;
}

/* Weekly Sub Headers (Row 2) */
.actual-table thead tr.actual-header-row-2 th.weekly-subheader,
.actual-floating-header-wrapper th.weekly-subheader {
    background-color: #e0f7ff !important;
    color: #000 !important;
    border-color: #212529 !important;
    height: 36px !important;
    padding: 4px 6px !important;
    min-width: 100px;
    font-size: 12px;
}

/* Box shadow for sticky edge */
.col-rap::after {
    content: '';
    position: absolute;
    top: 0;
    right: -4px;
    bottom: 0;
    width: 4px;
    background: linear-gradient(to right, rgba(0,0,0,0.1), transparent);
    pointer-events: none;
}

/* Floating Synced Sticky Header */
.actual-floating-header-wrapper {
    position: fixed;
    top: 70px;
    z-index: 999;
    overflow-x: hidden;
    overflow-y: hidden;
    display: none;
    box-shadow: 0 6px 12px rgba(0,0,0,0.25);
    background-color: #212529;
    border-bottom: 2px solid #212529;
}
.actual-floating-header-wrapper .actual-table {
    margin-bottom: 0 !important;
}
.actual-floating-header-wrapper .sticky-col-header {
    position: sticky;
    z-index: 30 !important;
    background-color: #212529 !important;
    color: #fff !important;
}
.actual-floating-header-wrapper th {
    border-top: none !important;
}

/* Scrollbar styling */
.actual-scroll-wrapper::-webkit-scrollbar {
    height: 10px;
}
.actual-scroll-wrapper::-webkit-scrollbar-track {
    background: #f1f1f1;
}
.actual-scroll-wrapper::-webkit-scrollbar-thumb {
    background: #0dcaf0;
    border-radius: 5px;
}
</style>

<div class="actual-scroll-wrapper">
    <table class="table table-bordered actual-table mb-0">
        <thead class="table-dark">
            <!-- Row 1: Main Headers -->
            <tr class="actual-header-row-1">
                <?php if ($categoryFilter === 'all'): ?>
                <th rowspan="2" class="align-middle text-center sticky-col sticky-col-header col-no">No</th>
                <th rowspan="2" class="align-middle sticky-col sticky-col-header col-uraian">Uraian Pekerjaan</th>
                <th rowspan="2" class="align-middle text-end sticky-col sticky-col-header col-rap"><?= $targetLabel ?> (Target)</th>
                <th rowspan="2" class="align-middle text-end col-upah">Realisasi<br><small>Upah</small></th>
                <th rowspan="2" class="align-middle text-end col-material">Realisasi<br><small>Material</small></th>
                <th rowspan="2" class="align-middle text-end col-alat">Realisasi<br><small>Alat</small></th>
                <th rowspan="2" class="align-middle text-end col-total">Realisasi<br><small>Total</small></th>
                <th rowspan="2" class="align-middle text-end col-selisih">Selisih</th>
                <th rowspan="2" class="align-middle text-center col-progress">Progress</th>
                <?php else: ?>
                <th rowspan="2" class="align-middle text-center sticky-col sticky-col-header col-no">No</th>
                <th rowspan="2" class="align-middle sticky-col sticky-col-header col-uraian">Uraian Pekerjaan</th>
                <th rowspan="2" class="align-middle text-end sticky-col sticky-col-header col-rap"><?= $targetLabel ?> <?= ucfirst($categoryFilter) ?></th>
                <th rowspan="2" class="align-middle text-end col-upah">Realisasi <?= ucfirst($categoryFilter) ?></th>
                <th rowspan="2" class="align-middle text-end col-selisih">Selisih</th>
                <th rowspan="2" class="align-middle text-center col-progress">Progress</th>
                <?php endif; ?>
                <?php if ($showWeeklyColumns): ?>
                <?php foreach ($weeklyRanges as $week): ?>
                <th colspan="3" class="text-center weekly-header">
                    Minggu ke-<?= $week['week_number'] ?><br>
                    <small>(<?= formatWeekRangeLabel($week['start'], $week['end']) ?>)</small>
                </th>
                <?php endforeach; ?>
                <?php endif; ?>
            </tr>
            <!-- Row 2: Sub Headers for weekly columns -->
            <tr class="actual-header-row-2">
                <?php if ($showWeeklyColumns): ?>
                <?php foreach ($weeklyRanges as $week): ?>
                <th class="text-end weekly-subheader">Realisasi (Rp)</th>
                <th class="text-center weekly-subheader">Bobot (%)</th>
                <th class="text-center weekly-subheader">Aksi</th>
                <?php endforeach; ?>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php 
            $grandTarget = 0;
            $grandActualUpah = 0;
            $grandActualMaterial = 0;
            $grandActualAlat = 0;
            $grandActualTotal = 0;
            $grandProgressSum = 0;
            $grandSubcatCount = 0;
            $grandWeeklyTotals = [];
            
            foreach ($actualData as $catId => $data): 
                $cat = $data['category'];
                $subcats = $data['subcategories'];
                $catProgress = $data['progress'];
                $catSelisih = $data['selisih'];
                
                // Accumulate grand totals
                $grandTarget += $data['target_total'];
                $grandActualUpah += $data['actual_upah'];
                $grandActualMaterial += $data['actual_material'];
                $grandActualAlat += $data['actual_alat'];
                $grandActualTotal += $data['actual_total'];
                
                if ($showWeeklyColumns) {
                    foreach ($weeklyRanges as $week) {
                        $weekNum = $week['week_number'];
                        if (!isset($grandWeeklyTotals[$weekNum])) {
                            $grandWeeklyTotals[$weekNum] = 0;
                        }
                        $grandWeeklyTotals[$weekNum] += $data['weekly_totals'][$weekNum] ?? 0;
                    }
                }
                
                foreach ($subcats as $sub) {
                    if ($sub['target_total'] > 0) {
                        $grandProgressSum += $sub['progress'];
                        $grandSubcatCount++;
                    }
                }
            ?>
            <!-- Category Header -->
            <tr class="table-primary">
                <td colspan="2" class="sticky-col col-no" style="left: 0;">
                    <strong><?= sanitize($cat['code']) ?>. <?= sanitize($cat['name']) ?></strong>
                </td>
                <td class="sticky-col col-rap"></td>
                <?php if ($categoryFilter === 'all'): ?>
                <td class="col-upah"></td>
                <td class="col-material"></td>
                <td class="col-alat"></td>
                <td class="col-total"></td>
                <?php else: ?>
                <td class="col-upah"></td>
                <?php endif; ?>
                <td class="col-selisih"></td>
                <td class="col-progress"></td>
                <?php if ($showWeeklyColumns): ?>
                <?php for ($i = 0; $i < count($weeklyRanges) * 3; $i++): ?>
                <td class="weekly-col"></td>
                <?php endfor; ?>
                <?php endif; ?>
            </tr>
            
            <!-- Subcategory Items -->
            <?php foreach ($subcats as $sub): 
                // Calculate filtered values based on category filter
                if ($categoryFilter === 'all') {
                    $displayTarget = $sub['target_total'];
                    $displayActual = $sub['actual_total'];
                } elseif ($categoryFilter === 'upah') {
                    $displayTarget = $sub['target_upah'];
                    $displayActual = $sub['actual_upah'];
                } elseif ($categoryFilter === 'material') {
                    $displayTarget = $sub['target_material'];
                    $displayActual = $sub['actual_material'];
                } else { // alat
                    $displayTarget = $sub['target_alat'];
                    $displayActual = $sub['actual_alat'];
                }
                
                $displaySelisih = $displayTarget - $displayActual;
                $displayProgress = $displayTarget > 0 ? ($displayActual / $displayTarget) * 100 : 0;
                $progressClass = $displayProgress > 100 ? 'bg-danger' : ($displayProgress >= 75 ? 'bg-warning' : 'bg-success');
                $selisihClass = $displaySelisih < 0 ? 'text-danger' : 'text-success';
            ?>
            <tr class="sub-row" data-subcategory-id="<?= $sub['id'] ?>">
                <td class="sticky-col col-no"><?= sanitize($sub['code']) ?></td>
                <td class="sticky-col col-uraian">
                    <?= sanitize($sub['name']) ?>
                    <?php if (!empty($sub['is_non_rab'])): ?>
                        <?php if (!empty($sub['notes'])): ?>
                        <br><small class="text-muted"><i class="mdi mdi-information-outline"></i> <?= sanitize($sub['notes']) ?></small>
                        <?php endif; ?>
                        <?php if ($sub['quantity'] > 1 || (!empty($sub['unit']) && $sub['unit'] !== 'ls')): ?>
                        <br><small class="text-muted"><?= formatVolume($sub['quantity']) ?> <?= sanitize($sub['unit']) ?> @ <?= formatRupiah($sub['unit_price']) ?></small>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td class="sticky-col col-rap text-end"><?= formatNumber($displayTarget, 2) ?></td>
                <?php if ($categoryFilter === 'all'): ?>
                <td class="col-upah text-end text-primary"><?= formatNumber($sub['actual_upah'], 2) ?></td>
                <td class="col-material text-end text-success"><?= formatNumber($sub['actual_material'], 2) ?></td>
                <td class="col-alat text-end text-warning"><?= formatNumber($sub['actual_alat'], 2) ?></td>
                <td class="col-total text-end"><strong><?= formatNumber($sub['actual_total'], 2) ?></strong></td>
                <?php else: ?>
                <td class="col-upah text-end"><strong><?= formatNumber($displayActual, 2) ?></strong></td>
                <?php endif; ?>
                <td class="col-selisih text-end <?= $selisihClass ?>">
                    <?= ($displaySelisih >= 0 ? '+' : '') . formatNumber($displaySelisih, 2) ?>
                </td>
                <td class="col-progress">
                    <?php if (!empty($sub['is_non_rab'])): ?>
                    <span class="badge bg-light text-muted border">-</span>
                    <?php else: ?>
                    <div class="progress" style="height: 18px;">
                        <div class="progress-bar <?= $progressClass ?>" 
                             style="width: <?= min($displayProgress, 100) ?>%">
                            <?= number_format($displayProgress, 1) ?>%
                        </div>
                    </div>
                    <?php endif; ?>
                </td>
                <?php if ($showWeeklyColumns): ?>
                <?php foreach ($weeklyRanges as $week): 
                    $weekNum = $week['week_number'];
                    $weekRealization = $sub['weekly'][$weekNum] ?? 0;
                    $weekBobot = $sub['target_total'] > 0 ? ($weekRealization / $sub['target_total']) * 100 : 0;
                ?>
                <td class="text-end weekly-col">
                    <?= $weekRealization > 0 ? formatNumber($weekRealization, 0) : '<span class="text-muted">0</span>' ?>
                </td>
                <td class="text-center weekly-col">
                    <?= $weekBobot > 0 ? number_format($weekBobot, 2) . '%' : '-' ?>
                </td>
                <td class="text-center weekly-col">
                    <?php if ($weekRealization > 0): ?>
                        <?php if (!empty($sub['is_non_rab'])): ?>
                        <a href="<?= $baseUrl ?>/pages/requests/view_request.php?id=<?= $sub['request_id'] ?>" 
                           class="btn btn-sm btn-outline-primary py-0 px-1" 
                           title="Lihat Pengajuan <?= sanitize($sub['request_number']) ?>" target="_blank">
                            <i class="mdi mdi-eye"></i>
                        </a>
                        <?php else: ?>
                        <button type="button" 
                           class="btn btn-sm btn-outline-info py-0 px-1" 
                           title="Lihat detail pengajuan minggu ke-<?= $weekNum ?>"
                           onclick="showWeeklyDetail(<?= $projectId ?>, <?= $sub['id'] ?>, <?= $weekNum ?>, '<?= addslashes($sub['name']) ?>')">
                            <i class="mdi mdi-eye"></i>
                        </button>
                        <?php endif; ?>
                    <?php else: ?>
                    <span class="text-muted">-</span>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            
            <!-- Category Total -->
            <?php 
                // Calculate filtered category totals
                if ($categoryFilter === 'all') {
                    $catDisplayTarget = $data['target_total'];
                    $catDisplayActual = $data['actual_total'];
                } elseif ($categoryFilter === 'upah') {
                    $catDisplayTarget = $data['target_upah'];
                    $catDisplayActual = $data['actual_upah'];
                } elseif ($categoryFilter === 'material') {
                    $catDisplayTarget = $data['target_material'];
                    $catDisplayActual = $data['actual_material'];
                } else { // alat
                    $catDisplayTarget = $data['target_alat'];
                    $catDisplayActual = $data['actual_alat'];
                }
                
                $catDisplaySelisih = $catDisplayTarget - $catDisplayActual;
                $catDisplayProgress = $catDisplayTarget > 0 ? ($catDisplayActual / $catDisplayTarget) * 100 : 0;
                $catProgressClass = $catDisplayProgress > 100 ? 'bg-danger' : ($catDisplayProgress >= 75 ? 'bg-warning' : 'bg-success');
                $catSelisihClass = $catDisplaySelisih < 0 ? 'text-danger' : 'text-success';
            ?>
            <tr class="table-secondary">
                <td colspan="2" class="text-end sticky-col col-no" style="left: 0;"><strong>JUMLAH <?= sanitize($cat['code']) ?></strong></td>
                <td class="text-end sticky-col col-rap"><strong><?= formatNumber($catDisplayTarget, 2) ?></strong></td>
                <?php if ($categoryFilter === 'all'): ?>
                <td class="text-end text-primary col-upah"><strong><?= formatNumber($data['actual_upah'], 2) ?></strong></td>
                <td class="text-end text-success col-material"><strong><?= formatNumber($data['actual_material'], 2) ?></strong></td>
                <td class="text-end text-warning col-alat"><strong><?= formatNumber($data['actual_alat'], 2) ?></strong></td>
                <td class="text-end col-total"><strong><?= formatNumber($data['actual_total'], 2) ?></strong></td>
                <?php else: ?>
                <td class="text-end col-upah"><strong><?= formatNumber($catDisplayActual, 2) ?></strong></td>
                <?php endif; ?>
                <td class="text-end <?= $catSelisihClass ?> col-selisih">
                    <strong><?= ($catDisplaySelisih >= 0 ? '+' : '') . formatNumber($catDisplaySelisih, 2) ?></strong>
                </td>
                <td class="col-progress">
                    <?php if (!empty($data['is_non_rab'])): ?>
                    <span class="badge bg-light text-muted border">-</span>
                    <?php else: ?>
                    <div class="d-flex align-items-center">
                        <div class="progress flex-grow-1" style="height: 18px;">
                            <div class="progress-bar <?= $catProgressClass ?>" 
                                 style="width: <?= min($catDisplayProgress, 100) ?>%">
                            </div>
                        </div>
                        <strong class="ms-2" style="min-width: 45px;"><?= number_format($catDisplayProgress, 1) ?>%</strong>
                    </div>
                    <?php endif; ?>
                </td>
                <?php if ($showWeeklyColumns): ?>
                <?php foreach ($weeklyRanges as $week): 
                    $weekNum = $week['week_number'];
                    $catWeekRealization = $data['weekly_totals'][$weekNum] ?? 0;
                    $catWeekBobot = $data['target_total'] > 0 ? ($catWeekRealization / $data['target_total']) * 100 : 0;
                ?>
                <td class="text-end weekly-col">
                    <strong><?= $catWeekRealization > 0 ? formatNumber($catWeekRealization, 0) : '<span class="text-muted">0</span>' ?></strong>
                </td>
                <td class="text-center weekly-col">
                    <strong><?= $catWeekBobot > 0 ? number_format($catWeekBobot, 2) . '%' : '-' ?></strong>
                </td>
                <td class="text-center weekly-col">
                    <span class="text-muted">-</span>
                </td>
                <?php endforeach; ?>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <?php 
            // Calculate filtered grand totals
            if ($categoryFilter === 'all') {
                $grandDisplayTarget = $grandTarget;
                $grandDisplayActual = $grandActualTotal;
            } elseif ($categoryFilter === 'upah') {
                $grandDisplayTarget = 0;
                $grandDisplayActual = $grandActualUpah;
                // Re-calculate Target for upah from actual data
                foreach ($actualData as $data) {
                    $grandDisplayTarget += $data['target_upah'];
                }
            } elseif ($categoryFilter === 'material') {
                $grandDisplayTarget = 0;
                $grandDisplayActual = $grandActualMaterial;
                foreach ($actualData as $data) {
                    $grandDisplayTarget += $data['target_material'];
                }
            } else { // alat
                $grandDisplayTarget = 0;
                $grandDisplayActual = $grandActualAlat;
                foreach ($actualData as $data) {
                    $grandDisplayTarget += $data['target_alat'];
                }
            }
            
            $grandDisplayDiff = $grandDisplayTarget - $grandDisplayActual;
            $overallDisplayProgress = $grandDisplayTarget > 0 ? ($grandDisplayActual / $grandDisplayTarget) * 100 : 0;
            $grandProgressClass = $overallDisplayProgress > 100 ? 'bg-danger' : ($overallDisplayProgress >= 75 ? 'bg-warning' : 'bg-success');
            $grandSelisihClass = $grandDisplayDiff < 0 ? 'text-danger' : 'text-success';
            
            // PPN & Rounding
            $ppnTarget = $grandDisplayTarget * ($ppnPercentage / 100);
            $ppnActual = $grandDisplayActual * ($ppnPercentage / 100);
            $totalTargetWithPpn = $grandDisplayTarget + $ppnTarget;
            $totalActualWithPpn = $grandDisplayActual + $ppnActual;
            $totalTargetRounded = ceil($totalTargetWithPpn / 10) * 10;
            $totalActualRounded = ceil($totalActualWithPpn / 10) * 10;
            $diffWithPpn = $totalTargetWithPpn - $totalActualWithPpn;
            $diffRounded = $totalTargetRounded - $totalActualRounded;
            ?>
            <!-- Grand Total Row -->
            <tr class="table-dark">
                <td colspan="2" class="text-end sticky-col col-no" style="left: 0;"><strong>JUMLAH TOTAL<?= $categoryFilter !== 'all' ? ' (' . strtoupper($categoryFilter) . ')' : '' ?></strong></td>
                <td class="text-end sticky-col col-rap"><strong><?= formatNumber($grandDisplayTarget, 2) ?></strong></td>
                <?php if ($categoryFilter === 'all'): ?>
                <td class="text-end text-primary col-upah"><strong><?= formatNumber($grandActualUpah, 2) ?></strong></td>
                <td class="text-end text-success col-material"><strong><?= formatNumber($grandActualMaterial, 2) ?></strong></td>
                <td class="text-end text-warning col-alat"><strong><?= formatNumber($grandActualAlat, 2) ?></strong></td>
                <td class="text-end col-total"><strong><?= formatNumber($grandActualTotal, 2) ?></strong></td>
                <?php else: ?>
                <td class="text-end col-upah"><strong><?= formatNumber($grandDisplayActual, 2) ?></strong></td>
                <?php endif; ?>
                <td class="text-end <?= $grandSelisihClass ?> col-selisih">
                    <strong><?= ($grandDisplayDiff >= 0 ? '+' : '') . formatNumber($grandDisplayDiff, 2) ?></strong>
                </td>
                <td class="col-progress">
                    <div class="d-flex align-items-center">
                        <div class="progress flex-grow-1" style="height: 18px;">
                            <div class="progress-bar <?= $grandProgressClass ?>" 
                                 style="width: <?= min($overallDisplayProgress, 100) ?>%">
                            </div>
                        </div>
                        <strong class="ms-2 text-white" style="min-width: 45px;"><?= number_format($overallDisplayProgress, 1) ?>%</strong>
                    </div>
                </td>
                <?php if ($showWeeklyColumns): ?>
                <?php foreach ($weeklyRanges as $week): 
                    $weekNum = $week['week_number'];
                    $grandWeekRealization = $grandWeeklyTotals[$weekNum] ?? 0;
                    $grandWeekBobot = $grandTarget > 0 ? ($grandWeekRealization / $grandTarget) * 100 : 0;
                ?>
                <td class="text-end weekly-col">
                    <strong><?= $grandWeekRealization > 0 ? formatNumber($grandWeekRealization, 0) : '<span class="text-muted">0</span>' ?></strong>
                </td>
                <td class="text-center weekly-col">
                    <strong><?= $grandWeekBobot > 0 ? number_format($grandWeekBobot, 2) . '%' : '-' ?></strong>
                </td>
                <td class="text-center weekly-col">
                    <span class="text-muted">-</span>
                </td>
                <?php endforeach; ?>
                <?php endif; ?>
            </tr>
            <!-- PPN Row -->
            <tr class="table-light">
                <td colspan="2" class="text-end sticky-col col-no" style="left: 0;"><strong>PPN <?= number_format($ppnPercentage, 0) ?>%</strong></td>
                <td class="text-end sticky-col col-rap"><strong><?= formatNumber($ppnTarget, 2) ?></strong></td>
                <?php if ($categoryFilter === 'all'): ?>
                <td class="col-upah"></td>
                <td class="col-material"></td>
                <td class="col-alat"></td>
                <td class="text-end col-total"><strong><?= formatNumber($ppnActual, 2) ?></strong></td>
                <?php else: ?>
                <td class="text-end col-upah"><strong><?= formatNumber($ppnActual, 2) ?></strong></td>
                <?php endif; ?>
                <td class="text-end <?= ($ppnTarget - $ppnActual) < 0 ? 'text-danger' : 'text-success' ?> col-selisih">
                    <strong><?= (($ppnTarget - $ppnActual) >= 0 ? '+' : '') . formatNumber($ppnTarget - $ppnActual, 2) ?></strong>
                </td>
                <td class="col-progress"></td>
                <?php if ($showWeeklyColumns): ?>
                <?php for ($i = 0; $i < count($weeklyRanges) * 3; $i++): ?>
                <td class="weekly-col"></td>
                <?php endfor; ?>
                <?php endif; ?>
            </tr>
            <!-- Total + PPN Row -->
            <tr class="table-light">
                <td colspan="2" class="text-end sticky-col col-no" style="left: 0;"><strong>JUMLAH TOTAL (TERMASUK PPN)</strong></td>
                <td class="text-end sticky-col col-rap"><strong><?= formatNumber($totalTargetWithPpn, 2) ?></strong></td>
                <?php if ($categoryFilter === 'all'): ?>
                <td class="col-upah"></td>
                <td class="col-material"></td>
                <td class="col-alat"></td>
                <td class="text-end col-total"><strong><?= formatNumber($totalActualWithPpn, 2) ?></strong></td>
                <?php else: ?>
                <td class="text-end col-upah"><strong><?= formatNumber($totalActualWithPpn, 2) ?></strong></td>
                <?php endif; ?>
                <td class="text-end <?= $diffWithPpn < 0 ? 'text-danger' : 'text-success' ?> col-selisih">
                    <strong><?= ($diffWithPpn >= 0 ? '+' : '') . formatNumber($diffWithPpn, 2) ?></strong>
                </td>
                <td class="col-progress"></td>
                <?php if ($showWeeklyColumns): ?>
                <?php for ($i = 0; $i < count($weeklyRanges) * 3; $i++): ?>
                <td class="weekly-col"></td>
                <?php endfor; ?>
                <?php endif; ?>
            </tr>
            <!-- Rounded Total Row -->
            <tr class="table-primary">
                <td colspan="2" class="text-end sticky-col col-no" style="left: 0;"><strong>JUMLAH TOTAL DIBULATKAN</strong></td>
                <td class="text-end sticky-col col-rap"><strong><?= formatRupiah($totalTargetRounded) ?></strong></td>
                <?php if ($categoryFilter === 'all'): ?>
                <td class="col-upah"></td>
                <td class="col-material"></td>
                <td class="col-alat"></td>
                <td class="text-end col-total"><strong><?= formatRupiah($totalActualRounded) ?></strong></td>
                <?php else: ?>
                <td class="text-end col-upah"><strong><?= formatRupiah($totalActualRounded) ?></strong></td>
                <?php endif; ?>
                <td class="text-end <?= $diffRounded < 0 ? 'text-danger' : 'text-success' ?> col-selisih">
                    <strong><?= ($diffRounded >= 0 ? '+' : '') . formatRupiah($diffRounded) ?></strong>
                </td>
                <td class="col-progress"></td>
                <?php if ($showWeeklyColumns): ?>
                <?php for ($i = 0; $i < count($weeklyRanges) * 3; $i++): ?>
                <td class="weekly-col"></td>
                <?php endfor; ?>
                <?php endif; ?>
            </tr>
        </tfoot>
    </table>
</div>
<!-- Legend -->
<div class="mt-3">
    <small class="text-muted">
        <span class="badge bg-primary me-2">Upah</span> Biaya tenaga kerja |
        <span class="badge bg-success me-2 ms-2">Material</span> Biaya bahan/material |
        <span class="badge bg-warning me-2 ms-2">Alat</span> Biaya peralatan |
        <em class="ms-2"><?= $targetLabel ?> sudah termasuk <?= formatOverheadProfitLabel($project, $targetType) ?></em>
    </small>
</div>

<div class="mt-2">
    <small class="text-muted">
        <strong>Keterangan Progress:</strong>
        <span class="badge bg-success me-1">Hijau</span> &lt; 75% |
        <span class="badge bg-warning me-1 ms-1">Kuning</span> 75-100% |
        <span class="badge bg-danger me-1 ms-1">Merah</span> &gt; 100% (Overbudget)
        <br><em>Progress Kategori = Rata-rata progress item di dalamnya</em>
    </small>
</div>

<?php if ($showWeeklyColumns): ?>
<div class="alert alert-info py-2 mt-3 mb-0">
    <i class="mdi mdi-information-outline"></i>
    <strong>Info:</strong> Data realisasi mingguan otomatis terisi dari pengajuan yang telah disetujui melalui <strong>Approval Center</strong>.
</div>
<?php endif; ?>


<!-- Modal Non-RAB Detail -->
<div class="modal fade" id="nonRabDetailModal" tabindex="-1" aria-labelledby="nonRabDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header text-white" style="background-color: #6f42c1;">
                <h5 class="modal-title" id="nonRabDetailModalLabel">
                    <i class="mdi mdi-receipt"></i> Rincian Pengajuan Biaya Non-RAB
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="nonRabDetailBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Memuat...</span>
                    </div>
                    <p class="mt-2 text-muted">Memuat data pengajuan Non-RAB...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- Weekly Detail Modal -->
<div class="modal fade" id="weeklyDetailModal" tabindex="-1" aria-labelledby="weeklyDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="weeklyDetailModalLabel">
                    <i class="mdi mdi-clipboard-list-outline"></i> Detail Pengajuan
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="weeklyDetailBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-info" role="status">
                        <span class="visually-hidden">Memuat...</span>
                    </div>
                    <p class="mt-2 text-muted">Memuat data pengajuan...</p>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <div></div>
                <div id="weeklyDetailPagination" class="d-flex align-items-center gap-2" style="display:none!important;"></div>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
var weeklyDetailData = [];
var weeklyDetailCurrent = 0;
var weeklyBaseUrl = '<?= $baseUrl ?>';

function showWeeklyDetail(projectId, subcategoryId, weekNumber, subcategoryName) {
    // Update modal title
    document.getElementById('weeklyDetailModalLabel').innerHTML = 
        '<i class="mdi mdi-clipboard-list-outline"></i> Detail Pengajuan — Minggu ke-' + weekNumber + 
        ' — <small>' + subcategoryName + '</small>';
    
    // Show loading
    document.getElementById('weeklyDetailBody').innerHTML = 
        '<div class="text-center py-4">' +
        '<div class="spinner-border text-info" role="status"><span class="visually-hidden">Memuat...</span></div>' +
        '<p class="mt-2 text-muted">Memuat data pengajuan...</p></div>';
    
    // Hide pagination initially
    document.getElementById('weeklyDetailPagination').style.display = 'none';
    
    // Open modal
    var modal = new bootstrap.Modal(document.getElementById('weeklyDetailModal'));
    modal.show();
    
    // Fetch data
    var url = '?id=' + projectId + '&tab=actual&ajax=weekly_detail' +
              '&project_id=' + projectId + 
              '&subcategory_id=' + subcategoryId + 
              '&week_number=' + weekNumber;
    
    fetch(url)
        .then(function(response) { return response.json(); })
        .then(function(result) {
            if (result.error) {
                document.getElementById('weeklyDetailBody').innerHTML = 
                    '<div class="alert alert-danger">' + result.error + '</div>';
                return;
            }
            
            var data = result.data;
            if (!data || data.length === 0) {
                document.getElementById('weeklyDetailBody').innerHTML = 
                    '<div class="text-center py-4 text-muted">' +
                    '<i class="mdi mdi-file-document-outline display-4"></i>' +
                    '<p class="mt-2">Tidak ada pengajuan yang ditemukan untuk minggu ini.</p></div>';
                return;
            }
            
            weeklyDetailData = data;
            weeklyDetailCurrent = 0;
            renderRequestDetail(0);
            
            // Setup pagination
            var pagDiv = document.getElementById('weeklyDetailPagination');
            if (data.length > 1) {
                pagDiv.style.display = 'flex';
                pagDiv.setAttribute('style', 'display:flex!important');
                updatePagination();
            } else {
                pagDiv.style.display = 'none';
                pagDiv.setAttribute('style', 'display:none!important');
            }
        })
        .catch(function(err) {
            document.getElementById('weeklyDetailBody').innerHTML = 
                '<div class="alert alert-danger">Gagal memuat data: ' + err.message + '</div>';
        });
}

function renderRequestDetail(index) {
    var req = weeklyDetailData[index];
    if (!req) return;
    weeklyDetailCurrent = index;
    
    var html = '';
    
    // --- Row 1: Request Info (left) + Items (right) ---
    html += '<div class="row">';
    
    // Left column: Informasi Pengajuan
    html += '<div class="col-lg-4">';
    html += '<div class="card border-0 shadow-sm mb-3">';
    html += '<div class="card-body">';
    html += '<h6 class="header-title mb-3"><i class="mdi mdi-information-outline text-info"></i> Informasi Pengajuan</h6>';
    html += '<table class="table table-sm mb-0">';
    html += '<tr><th style="width:40%">No. Request</th><td><code>' + escapeHtml(req.request_number || '-') + '</code></td></tr>';
    html += '<tr><th>Tanggal Pengajuan</th><td>' + formatDateTimeJs(req.created_at) + '</td></tr>';
    html += '<tr><th>Minggu Ke</th><td>' + (req.target_week || req.week_number || '-') + '</td></tr>';
    html += '<tr><th>Dibuat Oleh</th><td><i class="mdi mdi-account"></i> ' + escapeHtml(req.created_by_name || '-') + '</td></tr>';
    if (req.description) {
        html += '<tr><th>Keterangan</th><td>' + escapeHtml(req.description) + '</td></tr>';
    }
    html += '</table>';
    
    // Approval info
    html += '<hr>';
    html += '<h6 class="text-muted mb-2">Status Approval</h6>';
    html += '<table class="table table-sm mb-0">';
    html += '<tr><th style="width:40%">Status</th><td><span class="badge bg-success">Approved</span></td></tr>';
    if (req.pm_approved_by_name) {
        html += '<tr><th>Disetujui Oleh</th><td>' + escapeHtml(req.pm_approved_by_name) + '</td></tr>';
    }
    if (req.pm_approved_at) {
        html += '<tr><th>Tgl Disetujui</th><td>' + formatDateTimeJs(req.pm_approved_at) + '</td></tr>';
    }
    if (req.pm_notes) {
        html += '<tr><th>Catatan PM</th><td>' + escapeHtml(req.pm_notes) + '</td></tr>';
    }
    if (req.approved_by_name) {
        html += '<tr><th>Diproses Admin</th><td>' + escapeHtml(req.approved_by_name) + '</td></tr>';
    }
    if (req.approved_at) {
        html += '<tr><th>Tgl Diproses</th><td>' + formatDateTimeJs(req.approved_at) + '</td></tr>';
    }
    if (req.admin_notes) {
        html += '<tr><th>Catatan Admin</th><td>' + escapeHtml(req.admin_notes) + '</td></tr>';
    }
    html += '</table>';
    html += '</div></div>';
    
    // Total card
    html += '<div class="card border-primary mb-3">';
    html += '<div class="card-body text-center py-2">';
    html += '<h6 class="text-muted mb-1">Total Pengajuan</h6>';
    html += '<h4 class="text-primary mb-0">' + formatRupiahJs(parseFloat(req.total_amount) || 0) + '</h4>';
    html += '</div></div>';
    
    html += '</div>'; // end left col
    
    // Right column: Daftar Item
    html += '<div class="col-lg-8">';
    html += '<div class="card border-0 shadow-sm mb-3">';
    html += '<div class="card-body">';
    html += '<h6 class="header-title mb-3"><i class="mdi mdi-format-list-bulleted text-primary"></i> Daftar Item</h6>';
    html += '<div class="table-responsive">';
    html += '<table class="table table-bordered table-sm mb-0">';
    html += '<thead class="table-light">';
    html += '<tr><th style="width:80px">Kode</th><th>Uraian</th><th style="width:55px">Satuan</th>' +
            '<th style="width:90px" class="text-end">Volume</th>' +
            '<th style="width:110px" class="text-end">Harga</th>' +
            '<th style="width:130px" class="text-end">Jumlah</th></tr>';
    html += '</thead><tbody>';
    
    var items = req.items || [];
    var totalItems = 0;
    for (var i = 0; i < items.length; i++) {
        var it = items[i];
        var tp = parseFloat(it.total_price) || 0;
        totalItems += tp;
        var coeff = parseFloat(it.coefficient) || 0;
        var coeffDisplay = '';
        if ((it.item_type || '') === 'upah' && coeff > 0) {
            coeffDisplay = formatNumberJs(coeff / 6, 0) + ' org<br><small class="text-muted">× 6 = ' + formatNumberJs(coeff, 2) + '</small>';
        } else {
            coeffDisplay = formatNumberJs(coeff, 4);
        }
        
        var itemDesc = escapeHtml(it.item_name || '-');
        if (it.notes) {
            itemDesc += '<br><small class="text-muted">' + escapeHtml(it.notes) + '</small>';
        }
        
        html += '<tr>';
        html += '<td><code>' + escapeHtml(it.subcategory_code || it.item_code || '-') + '</code></td>';
        html += '<td>' + itemDesc + '</td>';
        html += '<td>' + escapeHtml(it.unit || '-') + '</td>';
        html += '<td class="text-end">' + coeffDisplay + '</td>';
        html += '<td class="text-end">' + formatRupiahJs(parseFloat(it.unit_price) || 0) + '</td>';
        html += '<td class="text-end"><strong>' + formatRupiahJs(tp) + '</strong></td>';
        html += '</tr>';
    }
    html += '</tbody>';
    html += '<tfoot><tr class="table-primary">';
    html += '<td colspan="5" class="text-end"><strong>TOTAL</strong></td>';
    html += '<td class="text-end"><strong>' + formatRupiahJs(totalItems) + '</strong></td>';
    html += '</tr></tfoot>';
    html += '</table></div>';
    html += '</div></div>';
    html += '</div>'; // end right col
    html += '</div>'; // end row
    
    // --- Row 2: Laporan Aktual (full-width) ---
    var act = req.actuals;
    if (act) {
        var catTotals = req.cat_totals || {upah: 0, material: 0, alat: 0};
        var remUpah = parseFloat(act.remaining_upah) || 0;
        var remMaterial = parseFloat(act.remaining_material) || 0;
        var remAlat = parseFloat(act.remaining_alat) || 0;
        var totalRemaining = remUpah + remMaterial + remAlat;
        var reqTotal = parseFloat(req.total_amount) || 0;
        var totalAktual = reqTotal - totalRemaining;
        
        html += '<div class="card border-success mb-3">';
        html += '<div class="card-header bg-success text-white py-2">';
        html += '<h6 class="mb-0"><i class="mdi mdi-clipboard-check"></i> Laporan Aktual</h6>';
        html += '</div>';
        html += '<div class="card-body">';
        html += '<div class="table-responsive">';
        html += '<table class="table table-bordered table-sm mb-0">';
        html += '<thead class="table-light">';
        html += '<tr><th>Kategori</th><th class="text-end">Pengajuan</th><th class="text-end">Sisa Anggaran</th><th class="text-end">Aktual</th><th>Catatan</th></tr>';
        html += '</thead><tbody>';
        
        // Upah
        html += '<tr>';
        html += '<td><span class="badge bg-primary">Upah</span></td>';
        html += '<td class="text-end">' + formatRupiahJs(catTotals.upah) + '</td>';
        html += '<td class="text-end text-danger">' + formatRupiahJs(remUpah) + '</td>';
        html += '<td class="text-end text-success"><strong>' + formatRupiahJs(catTotals.upah - remUpah) + '</strong></td>';
        html += '<td><small>' + escapeHtml(act.notes_upah || '-') + '</small></td>';
        html += '</tr>';
        
        // Material
        html += '<tr>';
        html += '<td><span class="badge bg-success">Material</span></td>';
        html += '<td class="text-end">' + formatRupiahJs(catTotals.material) + '</td>';
        html += '<td class="text-end text-danger">' + formatRupiahJs(remMaterial) + '</td>';
        html += '<td class="text-end text-success"><strong>' + formatRupiahJs(catTotals.material - remMaterial) + '</strong></td>';
        html += '<td><small>' + escapeHtml(act.notes_material || '-') + '</small></td>';
        html += '</tr>';
        
        // Alat
        html += '<tr>';
        html += '<td><span class="badge bg-warning text-dark">Alat</span></td>';
        html += '<td class="text-end">' + formatRupiahJs(catTotals.alat) + '</td>';
        html += '<td class="text-end text-danger">' + formatRupiahJs(remAlat) + '</td>';
        html += '<td class="text-end text-success"><strong>' + formatRupiahJs(catTotals.alat - remAlat) + '</strong></td>';
        html += '<td><small>' + escapeHtml(act.notes_alat || '-') + '</small></td>';
        html += '</tr>';
        
        html += '</tbody>';
        html += '<tfoot><tr class="table-dark">';
        html += '<th>TOTAL</th>';
        html += '<th class="text-end">' + formatRupiahJs(reqTotal) + '</th>';
        html += '<th class="text-end text-danger">' + formatRupiahJs(totalRemaining) + '</th>';
        html += '<th class="text-end text-success">' + formatRupiahJs(totalAktual) + '</th>';
        html += '<th></th>';
        html += '</tr></tfoot>';
        html += '</table></div>';
        
        // Attachments
        var attachments = req.attachments || [];
        if (attachments.length > 0) {
            html += '<h6 class="mt-3 mb-2"><i class="mdi mdi-camera"></i> Lampiran Nota</h6>';
            html += '<div class="d-flex flex-wrap gap-2">';
            for (var a = 0; a < attachments.length; a++) {
                var att = attachments[a];
                var isImage = (att.file_type || '').indexOf('image/') === 0;
                if (isImage) {
                    html += '<a href="' + weeklyBaseUrl + '/uploads/actuals/' + att.filename + '" target="_blank">';
                    html += '<img src="' + weeklyBaseUrl + '/uploads/actuals/' + att.filename + '" ' +
                            'style="height:80px;width:auto;" class="rounded border">';
                    html += '</a>';
                } else {
                    html += '<a href="' + weeklyBaseUrl + '/uploads/actuals/' + att.filename + '" target="_blank" class="btn btn-outline-danger btn-sm">';
                    html += '<i class="mdi mdi-file-pdf-box"></i> ' + escapeHtml(att.original_name || 'File');
                    html += '</a>';
                }
            }
            html += '</div>';
        }
        
        if (act.actual_created_by_name) {
            html += '<div class="mt-2"><small class="text-muted">Diaktualisasi oleh: ' + 
                    escapeHtml(act.actual_created_by_name) + ' pada ' + formatDateId(act.created_at || '') + '</small></div>';
        }
        
        html += '</div></div>';
    } else {
        html += '<div class="card border-warning mb-3">';
        html += '<div class="card-body py-3 text-center">';
        html += '<i class="mdi mdi-alert-circle-outline text-warning" style="font-size:2rem;"></i>';
        html += '<p class="mt-2 mb-0">Pengajuan ini belum memiliki laporan aktual.</p>';
        html += '</div></div>';
    }
    
    document.getElementById('weeklyDetailBody').innerHTML = html;
    
    // Update pagination highlight
    if (weeklyDetailData.length > 1) {
        updatePagination();
    }
}

function updatePagination() {
    var pagDiv = document.getElementById('weeklyDetailPagination');
    var total = weeklyDetailData.length;
    var current = weeklyDetailCurrent;
    
    var pHtml = '';
    // Left arrow
    pHtml += '<button type="button" class="btn btn-sm btn-outline-info' + (current <= 0 ? ' disabled' : '') + '" ' +
             'onclick="navigateRequest(-1)" title="Pengajuan sebelumnya">';
    pHtml += '<i class="mdi mdi-chevron-left"></i>';
    pHtml += '</button>';
    
    // Current number
    pHtml += '<span class="badge bg-info fs-6 px-3 py-2">' + (current + 1) + '</span>';
    
    // Right arrow
    pHtml += '<button type="button" class="btn btn-sm btn-outline-info' + (current >= total - 1 ? ' disabled' : '') + '" ' +
             'onclick="navigateRequest(1)" title="Pengajuan berikutnya">';
    pHtml += '<i class="mdi mdi-chevron-right"></i>';
    pHtml += '</button>';
    
    // Info text
    pHtml += '<small class="text-muted ms-2">dari ' + total + ' pengajuan</small>';
    
    pagDiv.innerHTML = pHtml;
}

function navigateRequest(direction) {
    var newIndex = weeklyDetailCurrent + direction;
    if (newIndex >= 0 && newIndex < weeklyDetailData.length) {
        renderRequestDetail(newIndex);
    }
}

function formatRupiahJs(number) {
    if (!number || isNaN(number)) return 'Rp 0';
    return 'Rp ' + Math.round(number).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

function formatNumberJs(number, decimals) {
    if (isNaN(number)) return '0';
    return Number(number).toLocaleString('id-ID', {minimumFractionDigits: decimals, maximumFractionDigits: decimals});
}

function formatDateTimeJs(dateTimeStr) {
    if (!dateTimeStr) return '-';
    var parts = dateTimeStr.split(' ');
    var datePart = parts[0];
    var timePart = parts.length > 1 ? parts[1] : '';
    
    var formattedDate = formatDateId(datePart);
    if (timePart) {
        var timeParts = timePart.split(':');
        var formattedTime = timeParts[0] + ':' + timeParts[1];
        return formattedDate + ' - ' + formattedTime + ' WIB';
    }
    return formattedDate;
}

function formatDateId(dateStr) {
    if (!dateStr) return '-';
    var months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    var parts = dateStr.split('-');
    if (parts.length !== 3) return dateStr;
    return parseInt(parts[2]) + ' ' + months[parseInt(parts[1]) - 1] + ' ' + parts[0];
}

function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

// Show Non-RAB Details Modal
function showNonRabDetail(projectId, category, categoryLabel) {
    document.getElementById('nonRabDetailModalLabel').innerHTML = 
        '<i class="mdi mdi-receipt"></i> Rincian Pengajuan Biaya Non-RAB — <small>' + escapeHtml(categoryLabel) + '</small>';
    
    document.getElementById('nonRabDetailBody').innerHTML = 
        '<div class="text-center py-4">' +
        '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Memuat...</span></div>' +
        '<p class="mt-2 text-muted">Memuat data pengajuan Non-RAB...</p></div>';
    
    var modal = new bootstrap.Modal(document.getElementById('nonRabDetailModal'));
    modal.show();
    
    var url = '?id=' + projectId + '&tab=actual&ajax=non_rab_detail' +
              '&project_id=' + projectId + 
              '&category=' + encodeURIComponent(category);
    
    fetch(url)
        .then(function(response) { return response.json(); })
        .then(function(result) {
            if (result.error) {
                document.getElementById('nonRabDetailBody').innerHTML = 
                    '<div class="alert alert-danger">' + escapeHtml(result.error) + '</div>';
                return;
            }
            
            var requests = result.data || [];
            if (requests.length === 0) {
                document.getElementById('nonRabDetailBody').innerHTML = 
                    '<div class="text-center py-5 text-muted">' +
                    '<i class="mdi mdi-receipt-text-outline display-4"></i>' +
                    '<h5 class="mt-3">Belum Ada Realisasi</h5>' +
                    '<p class="mb-0">Belum ada pengajuan biaya Non-RAB yang disetujui untuk kategori ini.</p></div>';
                return;
            }
            
            var totalAll = 0;
            var html = '<div class="d-flex flex-column gap-3">';
            
            for (var i = 0; i < requests.length; i++) {
                var req = requests[i];
                var reqTotal = parseFloat(req.total_amount) || 0;
                totalAll += reqTotal;
                var tglTransaksi = req.request_date ? formatDateId(req.request_date) : formatDateTimeJs(req.created_at);
                
                html += '<div class="card border shadow-none mb-0">';
                html += '<div class="card-header bg-light d-flex justify-content-between align-items-center py-2 px-3">';
                html += '<div>';
                html += '<span class="fw-bold text-dark me-2">' + escapeHtml(req.request_number) + '</span>';
                html += '<span class="badge text-white me-2" style="background-color: #6f42c1;">' + escapeHtml(req.category_name || categoryLabel) + '</span>';
                html += '<small class="text-muted"><i class="mdi mdi-calendar-clock"></i> Tgl Transaksi: <strong>' + tglTransaksi + '</strong></small>';
                html += '</div>';
                html += '<div class="text-end">';
                html += '<span class="text-muted small me-1">Total:</span><span class="fw-bold text-primary font-monospace">' + formatRupiahJs(reqTotal) + '</span>';
                html += '</div>';
                html += '</div>';
                
                html += '<div class="card-body p-3">';
                if (req.description) {
                    html += '<div class="mb-2 small text-muted"><i class="mdi mdi-text-short"></i> <strong>Keterangan Pengajuan:</strong> ' + escapeHtml(req.description) + '</div>';
                }
                
                html += '<div class="table-responsive">';
                html += '<table class="table table-sm table-bordered align-middle mb-0">';
                html += '<thead class="table-light">';
                html += '<tr>';
                html += '<th width="30" class="text-center">#</th>';
                html += '<th>Nama Biaya / Kebutuhan</th>';
                html += '<th width="70" class="text-center">Satuan</th>';
                html += '<th width="70" class="text-end">Volume</th>';
                html += '<th width="120" class="text-end">Harga Satuan</th>';
                html += '<th width="130" class="text-end">Subtotal</th>';
                html += '<th width="90" class="text-center">Bukti/Nota</th>';
                html += '<th>Catatan</th>';
                html += '</tr>';
                html += '</thead><tbody>';
                
                var items = req.items || [];
                for (var j = 0; j < items.length; j++) {
                    var it = items[j];
                    var qty = parseFloat(it.quantity) || 0;
                    var price = parseFloat(it.unit_price) || 0;
                    var subtotal = parseFloat(it.subtotal) || (qty * price);
                    
                    var receiptBtn = '<span class="text-muted small">-</span>';
                    if (it.receipt_path) {
                        receiptBtn = '<a href="<?= $baseUrl ?>/' + escapeHtml(it.receipt_path) + '" target="_blank" class="btn btn-xs btn-outline-info py-0 px-1">' +
                                     '<i class="mdi mdi-paperclip"></i> Lihat</a>';
                    }
                    
                    html += '<tr>';
                    html += '<td class="text-center">' + (j + 1) + '</td>';
                    html += '<td class="fw-semibold">' + escapeHtml(it.item_name) + '</td>';
                    html += '<td class="text-center">' + escapeHtml(it.unit || 'ls') + '</td>';
                    html += '<td class="text-end font-monospace">' + formatNumberJs(qty, 2) + '</td>';
                    html += '<td class="text-end font-monospace">' + formatRupiahJs(price) + '</td>';
                    html += '<td class="text-end font-monospace fw-bold text-primary">' + formatRupiahJs(subtotal) + '</td>';
                    html += '<td class="text-center">' + receiptBtn + '</td>';
                    html += '<td class="small text-muted">' + escapeHtml(it.notes || '-') + '</td>';
                    html += '</tr>';
                }
                
                html += '</tbody></table></div>';
                
                html += '<div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top text-muted small">';
                html += '<div><i class="mdi mdi-account"></i> Diajukan oleh: <strong>' + escapeHtml(req.created_by_name || '-') + '</strong></div>';
                html += '<div>';
                if (req.pm_approved_by_name) {
                    html += '<span class="me-3"><i class="mdi mdi-check-circle text-success"></i> PM: ' + escapeHtml(req.pm_approved_by_name) + '</span>';
                }
                if (req.approved_by_name) {
                    html += '<span><i class="mdi mdi-check-all text-primary"></i> Admin: ' + escapeHtml(req.approved_by_name) + '</span>';
                }
                html += '</div>';
                html += '</div>'; // footer
                
                html += '</div></div>'; // end card
            }
            
            html += '</div>'; // end container
            
            // Grand summary alert
            var summaryHtml = '<div class="alert alert-primary d-flex justify-content-between align-items-center py-2 mb-3">' +
                              '<div><strong>Total Realisasi ' + escapeHtml(categoryLabel) + ':</strong> ' + requests.length + ' Pengajuan Disetujui</div>' +
                              '<div class="h5 mb-0 fw-bold font-monospace">' + formatRupiahJs(totalAll) + '</div>' +
                              '</div>';
            
            document.getElementById('nonRabDetailBody').innerHTML = summaryHtml + html;
        })
        .catch(function(err) {
            document.getElementById('nonRabDetailBody').innerHTML = 
                '<div class="alert alert-danger">Gagal memuat data: ' + escapeHtml(err.message) + '</div>';
        });
}

// Open PDF Preview in modal for Realisasi
function openPdfPreviewActual() {
    var modal = new bootstrap.Modal(document.getElementById('pdfPreviewModalActual'));
    var iframe = document.getElementById('pdfPreviewIframeActual');
    iframe.src = 'export_actual_pdf.php?id=<?= $projectId ?>&target=<?= $targetType ?>';
    modal.show();
}

// Print PDF from iframe for Realisasi
function printPdfPreviewActual() {
    var iframe = document.getElementById('pdfPreviewIframeActual');
    if (iframe.contentWindow) {
        iframe.contentWindow.print();
    }
}

// Floating Synced Sticky Header Implementation
(function initActualStickyHeader() {
    function setupStickyHeader() {
        var origWrapper = document.querySelector('.actual-scroll-wrapper');
        var origTable = document.querySelector('.actual-table');
        if (!origWrapper || !origTable) return;
        
        var origThead = origTable.querySelector('thead');
        if (!origThead) return;

        // Remove any existing floating header wrapper
        var existing = document.getElementById('actualFloatingHeader');
        if (existing) existing.remove();

        // Create floating container
        var floatWrapper = document.createElement('div');
        floatWrapper.id = 'actualFloatingHeader';
        floatWrapper.className = 'actual-floating-header-wrapper';
        
        // Create cloned table and thead
        var floatTable = document.createElement('table');
        floatTable.className = origTable.className + ' actual-floating-table';
        
        var clonedThead = origThead.cloneNode(true);
        floatTable.appendChild(clonedThead);
        floatWrapper.appendChild(floatTable);
        document.body.appendChild(floatWrapper);

        // Sync column widths between original thead and cloned thead
        function syncWidths() {
            var origTableWidth = origTable.offsetWidth;
            floatTable.style.width = origTableWidth + 'px';
            floatTable.style.minWidth = origTableWidth + 'px';
            
            // Row 1 headers
            var origR1 = origThead.querySelectorAll('tr.actual-header-row-1 > th');
            var cloneR1 = clonedThead.querySelectorAll('tr.actual-header-row-1 > th');
            for (var i = 0; i < origR1.length; i++) {
                if (cloneR1[i]) {
                    var rect = origR1[i].getBoundingClientRect();
                    var w = rect.width;
                    cloneR1[i].style.width = w + 'px';
                    cloneR1[i].style.minWidth = w + 'px';
                    cloneR1[i].style.maxWidth = w + 'px';
                    cloneR1[i].style.boxSizing = 'border-box';
                }
            }
            
            // Row 2 headers (weekly subheaders)
            var origR2 = origThead.querySelectorAll('tr.actual-header-row-2 > th');
            var cloneR2 = clonedThead.querySelectorAll('tr.actual-header-row-2 > th');
            for (var j = 0; j < origR2.length; j++) {
                if (cloneR2[j]) {
                    var r2Rect = origR2[j].getBoundingClientRect();
                    var w2 = r2Rect.width;
                    cloneR2[j].style.width = w2 + 'px';
                    cloneR2[j].style.minWidth = w2 + 'px';
                    cloneR2[j].style.maxWidth = w2 + 'px';
                    cloneR2[j].style.boxSizing = 'border-box';
                }
            }
        }

        // Update position and visibility on scroll
        function updatePosition() {
            var topbar = document.getElementById('page-topbar');
            var topOffset = topbar ? topbar.offsetHeight : 70;
            
            var rect = origWrapper.getBoundingClientRect();
            var theadRect = origThead.getBoundingClientRect();
            var theadHeight = origThead.offsetHeight;
            var tableBottom = rect.bottom;
            
            // Show floating header when original header has scrolled past the topbar,
            // and hide before the table completely leaves the view
            if (theadRect.top <= topOffset && tableBottom > (topOffset + theadHeight + 30)) {
                floatWrapper.style.display = 'block';
                floatWrapper.style.top = topOffset + 'px';
                floatWrapper.style.left = rect.left + 'px';
                floatWrapper.style.width = rect.width + 'px';
                floatWrapper.scrollLeft = origWrapper.scrollLeft;
            } else {
                floatWrapper.style.display = 'none';
            }
        }

        // Bidirectional horizontal scroll sync
        var isSyncing = false;
        origWrapper.addEventListener('scroll', function() {
            if (!isSyncing) {
                isSyncing = true;
                floatWrapper.scrollLeft = origWrapper.scrollLeft;
                isSyncing = false;
            }
        }, { passive: true });

        floatWrapper.addEventListener('scroll', function() {
            if (!isSyncing) {
                isSyncing = true;
                origWrapper.scrollLeft = floatWrapper.scrollLeft;
                isSyncing = false;
            }
        }, { passive: true });

        // Window scroll and resize listeners with requestAnimationFrame
        var ticking = false;
        function onScrollOrResize() {
            if (!ticking) {
                window.requestAnimationFrame(function() {
                    syncWidths();
                    updatePosition();
                    ticking = false;
                });
                ticking = true;
            }
        }

        window.addEventListener('scroll', onScrollOrResize, { passive: true });
        window.addEventListener('resize', onScrollOrResize, { passive: true });

        // Initial measurement
        setTimeout(function() {
            syncWidths();
            updatePosition();
        }, 50);
    }

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        setupStickyHeader();
    } else {
        document.addEventListener('DOMContentLoaded', setupStickyHeader);
    }
})();
</script>

<!-- PDF Preview Modal Actual -->
<div class="modal fade" id="pdfPreviewModalActual" tabindex="-1" aria-labelledby="pdfPreviewModalActualLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" id="pdfPreviewModalActualLabel">
                    <i class="mdi mdi-file-pdf-box text-danger"></i> Preview Laporan Realisasi (Target <?= $targetLabel ?>)
                </h5>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-success btn-sm" onclick="printPdfPreviewActual()">
                        <i class="mdi mdi-printer"></i> Cetak / Export PDF
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body p-0">
                <iframe id="pdfPreviewIframeActual" style="width:100%; height:100%; border:none;"></iframe>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>
