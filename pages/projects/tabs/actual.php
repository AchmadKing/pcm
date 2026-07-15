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

// Get project settings
$ppnPercentage = $project['ppn_percentage'] ?? 11;
$overheadPct = $project['overhead_percentage'] ?? 10;

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

// Pre-compute actualization adjustments per subcategory
// Pool all remaining (upah+material+alat) into total sisa, then distribute proportionally to subcategories
$actualizationAdjustments = []; // [subcategory_id => total deduction amount]
$actualizedRequests = dbGetAll("
    SELECT r.id as request_id, 
           GREATEST(ra.remaining_upah - ra.consumed_upah, 0) + 
           GREATEST(ra.remaining_material - ra.consumed_material, 0) + 
           GREATEST(ra.remaining_alat - ra.consumed_alat, 0) as total_remaining
    FROM requests r
    JOIN request_actuals ra ON ra.request_id = r.id
    WHERE r.project_id = ? AND r.status = 'approved' AND r.is_actualized = 1
    HAVING total_remaining > 0
", [$projectId]);

foreach ($actualizedRequests as $ar) {
    $totalRemaining = floatval($ar['total_remaining']);
    if ($totalRemaining <= 0) continue;
    
    // Get items for this request grouped by subcategory (all categories combined)
    $reqItems = dbGetAll("
        SELECT reqi.subcategory_id, SUM(reqi.total_price) as subcat_total
        FROM request_items reqi
        WHERE reqi.request_id = ?
        GROUP BY reqi.subcategory_id
    ", [$ar['request_id']]);
    
    // Get total request amount for proportional distribution
    $reqTotal = 0;
    foreach ($reqItems as $ri) {
        $reqTotal += floatval($ri['subcat_total']);
    }
    
    // Distribute total remaining proportionally to subcategories
    if ($reqTotal > 0) {
        foreach ($reqItems as $ri) {
            $subcatId = intval($ri['subcategory_id']);
            $proportion = floatval($ri['subcat_total']) / $reqTotal;
            $deduction = $totalRemaining * $proportion;
            
            if (!isset($actualizationAdjustments[$subcatId])) {
                $actualizationAdjustments[$subcatId] = 0;
            }
            $actualizationAdjustments[$subcatId] += $deduction;
        }
    }
}

// Get categories
$categories = dbGetAll("
    SELECT rc.id, rc.code, rc.name, rc.sort_order
    FROM rab_categories rc
    WHERE rc.project_id = ?
    ORDER BY rc.sort_order, rc.code
", [$projectId]);

// Function to get RAP AHSP component breakdown from Master Data RAP tables
function getActualRapComponentBreakdown($rapItemId) {
    $result = ['upah' => 0, 'material' => 0, 'alat' => 0];
    
    if (!$rapItemId) return $result;
    
    // Get subcategory info to find AHSP RAP via Master Data
    $rapItem = dbGetRow("SELECT subcategory_id FROM rap_items WHERE id = ?", [$rapItemId]);
    if ($rapItem) {
        $totals = dbGetAll("
            SELECT pir.category, SUM(d.coefficient * COALESCE(d.unit_price, pir.price)) as total 
            FROM rab_subcategories rs
            JOIN project_ahsp pa ON rs.ahsp_id = pa.id
            JOIN project_ahsp_rap par ON par.ahsp_code = pa.ahsp_code AND par.project_id = pa.project_id
            JOIN project_ahsp_details_rap d ON d.ahsp_id = par.id
            JOIN project_items_rap pir ON d.item_id = pir.id
            WHERE rs.id = ?
            GROUP BY pir.category
        ", [$rapItem['subcategory_id']]);
    }
    
    if (empty($totals)) {
        // Fallback to project AHSP details via subcategory
        $totals = dbGetAll("
            SELECT pi.category, SUM(pad.coefficient * pi.price) as total
            FROM rap_items rap
            JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
            JOIN project_ahsp_details pad ON pad.ahsp_id = rs.ahsp_id
            JOIN project_items pi ON pad.item_id = pi.id
            WHERE rap.id = ?
            GROUP BY pi.category
        ", [$rapItemId]);
    }
    
    foreach ($totals as $row) {
        $cat = $row['category'] ?? '';
        if ($cat === 'upah') $result['upah'] = floatval($row['total']);
        elseif ($cat === 'material') $result['material'] = floatval($row['total']);
        elseif ($cat === 'alat') $result['alat'] = floatval($row['total']);
    }
    
    return $result;
}

// Build hierarchical data with subcategory details
$actualData = [];

foreach ($categories as $cat) {
    $catId = $cat['id'];
    
    // Get subcategories with RAP data
    $subcats = dbGetAll("
        SELECT rs.id, rs.code, rs.name, rs.unit, rs.volume as rab_volume, rs.unit_price as rab_unit_price,
               rap.id as rap_id, rap.volume as rap_volume, rap.unit_price as rap_unit_price
        FROM rab_subcategories rs
        LEFT JOIN rap_items rap ON rs.id = rap.subcategory_id
        WHERE rs.category_id = ?
        ORDER BY rs.sort_order, rs.code
    ", [$catId]);
    
    $subcatData = [];
    $catRapTotal = 0;
    $catActualTotal = 0;
    $catRapUpah = 0;
    $catRapMaterial = 0;
    $catRapAlat = 0;
    $catActualUpah = 0;
    $catActualMaterial = 0;
    $catActualAlat = 0;
    $subcatProgressSum = 0;
    $subcatCount = 0;
    
    foreach ($subcats as $sub) {
        // Use RAP values if available, otherwise use RAB values
        $volume = $sub['rap_volume'] ?? $sub['rab_volume'];
        $baseUnitPrice = $sub['rap_unit_price'] ?? $sub['rab_unit_price'];
        
        // Apply overhead & profit to unit price
        $unitPriceWithOverhead = $baseUnitPrice * (1 + ($overheadPct / 100));
        $subRapTotal = $volume * $unitPriceWithOverhead;
        
        // Get component breakdown for RAP
        $rapComponents = ['upah' => 0, 'material' => 0, 'alat' => 0];
        if ($sub['rap_id']) {
            $rapComponents = getActualRapComponentBreakdown($sub['rap_id']);
        }
        
        // Apply overhead to components and multiply by volume
        $subRapUpah = $rapComponents['upah'] * (1 + ($overheadPct / 100)) * $volume;
        $subRapMaterial = $rapComponents['material'] * (1 + ($overheadPct / 100)) * $volume;
        $subRapAlat = $rapComponents['alat'] * (1 + ($overheadPct / 100)) * $volume;
        
        // Get actual spending for this subcategory
        $actualRow = dbGetRow("
            SELECT COALESCE(SUM(reqi.total_price), 0) as total
            FROM request_items reqi
            JOIN requests req ON reqi.request_id = req.id
            WHERE reqi.subcategory_id = ? 
            AND req.status = 'approved'
            AND req.project_id = ?
        ", [$sub['id'], $projectId]);
        $subActualTotal = floatval($actualRow['total'] ?? 0);
        
        // Get actual breakdown by component - use item's own category directly
        $actualBreakdown = dbGetAll("
            SELECT 
                pi.category as item_category,
                COALESCE(SUM(reqi.unit_price * reqi.coefficient), 0) as category_total
            FROM request_items reqi
            JOIN requests req ON reqi.request_id = req.id
            JOIN project_items pi ON pi.item_code = reqi.item_code AND pi.project_id = req.project_id
            WHERE reqi.subcategory_id = ? 
            AND req.status = 'approved'
            AND req.project_id = ?
            GROUP BY pi.category
        ", [$sub['id'], $projectId]);
        
        $subActualUpah = 0;
        $subActualMaterial = 0;
        $subActualAlat = 0;
        foreach ($actualBreakdown as $row) {
            $itemCat = $row['item_category'] ?? '';
            if ($itemCat === 'upah') $subActualUpah = floatval($row['category_total']);
            elseif ($itemCat === 'material') $subActualMaterial = floatval($row['category_total']);
            elseif ($itemCat === 'alat') $subActualAlat = floatval($row['category_total']);
        }
        
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
        $subProgress = $subRapTotal > 0 ? ($subActualTotal / $subRapTotal) * 100 : 0;
        
        // Selisih = RAP - Aktual (positif = sisa anggaran, negatif = overbudget)
        $subSelisih = $subRapTotal - $subActualTotal;
        
        // Get weekly data for this subcategory
        $subWeeklyData = $weeklyData[$sub['id']] ?? [];
        
        $subcatData[] = [
            'id' => $sub['id'],
            'code' => $sub['code'],
            'name' => $sub['name'],
            'unit' => $sub['unit'],
            'rap_total' => $subRapTotal,
            'rap_upah' => $subRapUpah,
            'rap_material' => $subRapMaterial,
            'rap_alat' => $subRapAlat,
            'actual_total' => $subActualTotal,
            'actual_upah' => $subActualUpah,
            'actual_material' => $subActualMaterial,
            'actual_alat' => $subActualAlat,
            'selisih' => $subSelisih,
            'progress' => $subProgress,
            'weekly' => $subWeeklyData
        ];
        
        // Accumulate category totals
        $catRapTotal += $subRapTotal;
        $catActualTotal += $subActualTotal;
        $catRapUpah += $subRapUpah;
        $catRapMaterial += $subRapMaterial;
        $catRapAlat += $subRapAlat;
        $catActualUpah += $subActualUpah;
        $catActualMaterial += $subActualMaterial;
        $catActualAlat += $subActualAlat;
        
        // For average progress calculation
        if ($subRapTotal > 0) {
            $subcatProgressSum += $subProgress;
            $subcatCount++;
        }
    }
    
    // Category progress = Average of subcategory progress
    $catProgress = $subcatCount > 0 ? ($subcatProgressSum / $subcatCount) : 0;
    $catSelisih = $catRapTotal - $catActualTotal;
    
    $actualData[$catId] = [
        'category' => $cat,
        'subcategories' => $subcatData,
        'rap_total' => $catRapTotal,
        'actual_total' => $catActualTotal,
        'rap_upah' => $catRapUpah,
        'rap_material' => $catRapMaterial,
        'rap_alat' => $catRapAlat,
        'actual_upah' => $catActualUpah,
        'actual_material' => $catActualMaterial,
        'actual_alat' => $catActualAlat,
        'selisih' => $catSelisih,
        'progress' => $catProgress,
        'subcat_count' => $subcatCount
    ];
}
?>

<?php if (empty($categories)): ?>
<div class="text-center py-5">
    <i class="mdi mdi-chart-bar display-4 text-muted"></i>
    <h5 class="mt-3">Belum ada data RAB</h5>
    <p class="text-muted">Silakan buat RAB terlebih dahulu.</p>
</div>
<?php else: ?>

<!-- Category Filter Buttons -->
<div class="mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="btn-group" role="group" aria-label="Filter Kategori Anggaran">
        <a href="?id=<?= $projectId ?>&tab=actual&cat=all" 
           class="btn btn-outline-dark <?= $categoryFilter === 'all' ? 'active' : '' ?>">
            <i class="mdi mdi-view-list"></i> Semua
        </a>
        <a href="?id=<?= $projectId ?>&tab=actual&cat=upah" 
           class="btn btn-outline-primary <?= $categoryFilter === 'upah' ? 'active' : '' ?>">
            <i class="mdi mdi-account-hard-hat"></i> Upah
        </a>
        <a href="?id=<?= $projectId ?>&tab=actual&cat=material" 
           class="btn btn-outline-success <?= $categoryFilter === 'material' ? 'active' : '' ?>">
            <i class="mdi mdi-package-variant"></i> Material
        </a>
        <a href="?id=<?= $projectId ?>&tab=actual&cat=alat" 
           class="btn btn-outline-warning <?= $categoryFilter === 'alat' ? 'active' : '' ?>">
            <i class="mdi mdi-tools"></i> Alat
        </a>
    </div>
    <?php if ($categoryFilter !== 'all'): ?>
    <div class="alert alert-info py-1 px-3 mb-0">
        <small><i class="mdi mdi-filter"></i> Menampilkan anggaran <strong><?= ucfirst($categoryFilter) ?></strong> saja</small>
    </div>
    <?php endif; ?>
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

/* Sticky column styles */
.sticky-col {
    position: sticky;
    z-index: 2;
    background-color: #fff;
}
.sticky-col-header {
    position: sticky;
    z-index: 3;
    background-color: #212529;
}

/* Column position classes - FILTERED VIEW (single realisasi column) */
<?php if ($categoryFilter !== 'all'): ?>
.col-no { left: 0px; min-width: 50px; max-width: 50px; }
.col-uraian { left: 50px; min-width: 220px; max-width: 220px; }
.col-rap { left: 270px; min-width: 150px; max-width: 150px; }
.col-upah { left: 420px; min-width: 150px; max-width: 150px; } /* Single realisasi column */
.col-selisih { left: 570px; min-width: 150px; max-width: 150px; }
.col-progress { left: 720px; min-width: 120px; max-width: 120px; }
<?php else: ?>
/* Column position classes - ALL VIEW (4 realisasi columns) */
.col-no { left: 0px; min-width: 50px; max-width: 50px; }
.col-uraian { left: 50px; min-width: 220px; max-width: 220px; }
.col-rap { left: 270px; min-width: 150px; max-width: 150px; }
.col-upah { left: 420px; min-width: 130px; max-width: 130px; }
.col-material { left: 550px; min-width: 130px; max-width: 130px; }
.col-alat { left: 680px; min-width: 130px; max-width: 130px; }
.col-total { left: 810px; min-width: 140px; max-width: 140px; }
.col-selisih { left: 950px; min-width: 150px; max-width: 150px; }
.col-progress { left: 1100px; min-width: 120px; max-width: 120px; }
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
.weekly-header {
    background-color: #0dcaf0 !important;
    color: #000 !important;
    border-color: #212529 !important;
    white-space: normal; /* Allow week date range to wrap */
}
.weekly-subheader {
    background-color: #e0f7ff !important;
    color: #000 !important;
    border-color: #212529 !important;
    min-width: 100px;
}

/* Box shadow for sticky edge */
.col-progress::after {
    content: '';
    position: absolute;
    top: 0;
    right: -4px;
    bottom: 0;
    width: 4px;
    background: linear-gradient(to right, rgba(0,0,0,0.1), transparent);
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
            <tr>
                <?php if ($categoryFilter === 'all'): ?>
                <th rowspan="2" class="align-middle text-center sticky-col sticky-col-header col-no">No</th>
                <th rowspan="2" class="align-middle sticky-col sticky-col-header col-uraian">Uraian Pekerjaan</th>
                <th rowspan="2" class="align-middle text-end sticky-col sticky-col-header col-rap">RAP (Target)</th>
                <th rowspan="2" class="align-middle text-end sticky-col sticky-col-header col-upah">Realisasi<br><small>Upah</small></th>
                <th rowspan="2" class="align-middle text-end sticky-col sticky-col-header col-material">Realisasi<br><small>Material</small></th>
                <th rowspan="2" class="align-middle text-end sticky-col sticky-col-header col-alat">Realisasi<br><small>Alat</small></th>
                <th rowspan="2" class="align-middle text-end sticky-col sticky-col-header col-total">Realisasi<br><small>Total</small></th>
                <th rowspan="2" class="align-middle text-end sticky-col sticky-col-header col-selisih">Selisih</th>
                <th rowspan="2" class="align-middle text-center sticky-col sticky-col-header col-progress">Progress</th>
                <?php else: ?>
                <th rowspan="2" class="align-middle text-center sticky-col sticky-col-header col-no">No</th>
                <th rowspan="2" class="align-middle sticky-col sticky-col-header col-uraian">Uraian Pekerjaan</th>
                <th rowspan="2" class="align-middle text-end sticky-col sticky-col-header col-rap">RAP <?= ucfirst($categoryFilter) ?></th>
                <th rowspan="2" class="align-middle text-end sticky-col sticky-col-header col-upah">Realisasi <?= ucfirst($categoryFilter) ?></th>
                <th rowspan="2" class="align-middle text-end sticky-col sticky-col-header col-selisih">Selisih</th>
                <th rowspan="2" class="align-middle text-center sticky-col sticky-col-header col-progress">Progress</th>
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
            <tr>
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
            $grandRap = 0;
            $grandActualUpah = 0;
            $grandActualMaterial = 0;
            $grandActualAlat = 0;
            $grandActualTotal = 0;
            $grandProgressSum = 0;
            $grandSubcatCount = 0;
            
            foreach ($actualData as $catId => $data): 
                $cat = $data['category'];
                $subcats = $data['subcategories'];
                $catProgress = $data['progress'];
                $catSelisih = $data['selisih'];
                
                // Accumulate grand totals
                $grandRap += $data['rap_total'];
                $grandActualUpah += $data['actual_upah'];
                $grandActualMaterial += $data['actual_material'];
                $grandActualAlat += $data['actual_alat'];
                $grandActualTotal += $data['actual_total'];
                
                foreach ($subcats as $sub) {
                    if ($sub['rap_total'] > 0) {
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
                <td class="sticky-col col-upah"></td>
                <td class="sticky-col col-material"></td>
                <td class="sticky-col col-alat"></td>
                <td class="sticky-col col-total"></td>
                <?php else: ?>
                <td class="sticky-col col-upah"></td>
                <?php endif; ?>
                <td class="sticky-col col-selisih"></td>
                <td class="sticky-col col-progress"></td>
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
                    $displayRap = $sub['rap_total'];
                    $displayActual = $sub['actual_total'];
                } elseif ($categoryFilter === 'upah') {
                    $displayRap = $sub['rap_upah'];
                    $displayActual = $sub['actual_upah'];
                } elseif ($categoryFilter === 'material') {
                    $displayRap = $sub['rap_material'];
                    $displayActual = $sub['actual_material'];
                } else { // alat
                    $displayRap = $sub['rap_alat'];
                    $displayActual = $sub['actual_alat'];
                }
                
                $displaySelisih = $displayRap - $displayActual;
                $displayProgress = $displayRap > 0 ? ($displayActual / $displayRap) * 100 : 0;
                $progressClass = $displayProgress > 100 ? 'bg-danger' : ($displayProgress >= 75 ? 'bg-warning' : 'bg-success');
                $selisihClass = $displaySelisih < 0 ? 'text-danger' : 'text-success';
            ?>
            <tr class="sub-row" data-subcategory-id="<?= $sub['id'] ?>">
                <td class="sticky-col col-no"><?= sanitize($sub['code']) ?></td>
                <td class="sticky-col col-uraian"><?= sanitize($sub['name']) ?></td>
                <td class="sticky-col col-rap text-end"><?= formatNumber($displayRap, 2) ?></td>
                <?php if ($categoryFilter === 'all'): ?>
                <td class="sticky-col col-upah text-end text-primary"><?= formatNumber($sub['actual_upah'], 2) ?></td>
                <td class="sticky-col col-material text-end text-success"><?= formatNumber($sub['actual_material'], 2) ?></td>
                <td class="sticky-col col-alat text-end text-warning"><?= formatNumber($sub['actual_alat'], 2) ?></td>
                <td class="sticky-col col-total text-end"><strong><?= formatNumber($sub['actual_total'], 2) ?></strong></td>
                <?php else: ?>
                <td class="sticky-col col-upah text-end"><strong><?= formatNumber($displayActual, 2) ?></strong></td>
                <?php endif; ?>
                <td class="sticky-col col-selisih text-end <?= $selisihClass ?>">
                    <?= ($displaySelisih >= 0 ? '+' : '') . formatNumber($displaySelisih, 2) ?>
                </td>
                <td class="sticky-col col-progress">
                    <div class="progress" style="height: 18px;">
                        <div class="progress-bar <?= $progressClass ?>" 
                             style="width: <?= min($displayProgress, 100) ?>%">
                            <?= number_format($displayProgress, 1) ?>%
                        </div>
                    </div>
                </td>
                <?php if ($showWeeklyColumns): ?>
                <?php foreach ($weeklyRanges as $week): 
                    $weekNum = $week['week_number'];
                    $weekRealization = $sub['weekly'][$weekNum] ?? 0;
                    $weekBobot = $sub['rap_total'] > 0 ? ($weekRealization / $sub['rap_total']) * 100 : 0;
                ?>
                <td class="text-end weekly-col">
                    <?= $weekRealization > 0 ? formatNumber($weekRealization, 0) : '<span class="text-muted">0</span>' ?>
                </td>
                <td class="text-center weekly-col">
                    <?= $weekBobot > 0 ? number_format($weekBobot, 2) . '%' : '-' ?>
                </td>
                <td class="text-center weekly-col">
                    <?php if ($weekRealization > 0): ?>
                    <button type="button" 
                       class="btn btn-sm btn-outline-info py-0 px-1" 
                       title="Lihat detail pengajuan minggu ke-<?= $weekNum ?>"
                       onclick="showWeeklyDetail(<?= $projectId ?>, <?= $sub['id'] ?>, <?= $weekNum ?>, '<?= addslashes($sub['name']) ?>')">
                        <i class="mdi mdi-eye"></i>
                    </button>
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
                    $catDisplayRap = $data['rap_total'];
                    $catDisplayActual = $data['actual_total'];
                } elseif ($categoryFilter === 'upah') {
                    $catDisplayRap = $data['rap_upah'];
                    $catDisplayActual = $data['actual_upah'];
                } elseif ($categoryFilter === 'material') {
                    $catDisplayRap = $data['rap_material'];
                    $catDisplayActual = $data['actual_material'];
                } else { // alat
                    $catDisplayRap = $data['rap_alat'];
                    $catDisplayActual = $data['actual_alat'];
                }
                
                $catDisplaySelisih = $catDisplayRap - $catDisplayActual;
                $catDisplayProgress = $catDisplayRap > 0 ? ($catDisplayActual / $catDisplayRap) * 100 : 0;
                $catProgressClass = $catDisplayProgress > 100 ? 'bg-danger' : ($catDisplayProgress >= 75 ? 'bg-warning' : 'bg-success');
                $catSelisihClass = $catDisplaySelisih < 0 ? 'text-danger' : 'text-success';
            ?>
            <tr class="table-secondary">
                <td colspan="2" class="text-end sticky-col col-no" style="left: 0;"><strong>JUMLAH <?= sanitize($cat['code']) ?></strong></td>
                <td class="text-end sticky-col col-rap"><strong><?= formatNumber($catDisplayRap, 2) ?></strong></td>
                <?php if ($categoryFilter === 'all'): ?>
                <td class="text-end text-primary sticky-col col-upah"><strong><?= formatNumber($data['actual_upah'], 2) ?></strong></td>
                <td class="text-end text-success sticky-col col-material"><strong><?= formatNumber($data['actual_material'], 2) ?></strong></td>
                <td class="text-end text-warning sticky-col col-alat"><strong><?= formatNumber($data['actual_alat'], 2) ?></strong></td>
                <td class="text-end sticky-col col-total"><strong><?= formatNumber($data['actual_total'], 2) ?></strong></td>
                <?php else: ?>
                <td class="text-end sticky-col col-upah"><strong><?= formatNumber($catDisplayActual, 2) ?></strong></td>
                <?php endif; ?>
                <td class="text-end <?= $catSelisihClass ?> sticky-col col-selisih">
                    <strong><?= ($catDisplaySelisih >= 0 ? '+' : '') . formatNumber($catDisplaySelisih, 2) ?></strong>
                </td>
                <td class="sticky-col col-progress">
                    <div class="d-flex align-items-center">
                        <div class="progress flex-grow-1" style="height: 18px;">
                            <div class="progress-bar <?= $catProgressClass ?>" 
                                 style="width: <?= min($catDisplayProgress, 100) ?>%">
                            </div>
                        </div>
                        <strong class="ms-2" style="min-width: 45px;"><?= number_format($catDisplayProgress, 1) ?>%</strong>
                    </div>
                </td>
                <?php if ($showWeeklyColumns): ?>
                <?php for ($i = 0; $i < count($weeklyRanges) * 3; $i++): ?>
                <td class="weekly-col"></td>
                <?php endfor; ?>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <?php 
            // Calculate filtered grand totals
            if ($categoryFilter === 'all') {
                $grandDisplayRap = $grandRap;
                $grandDisplayActual = $grandActualTotal;
            } elseif ($categoryFilter === 'upah') {
                $grandDisplayRap = 0;
                $grandDisplayActual = $grandActualUpah;
                // Re-calculate RAP for upah from actual data
                foreach ($actualData as $data) {
                    $grandDisplayRap += $data['rap_upah'];
                }
            } elseif ($categoryFilter === 'material') {
                $grandDisplayRap = 0;
                $grandDisplayActual = $grandActualMaterial;
                foreach ($actualData as $data) {
                    $grandDisplayRap += $data['rap_material'];
                }
            } else { // alat
                $grandDisplayRap = 0;
                $grandDisplayActual = $grandActualAlat;
                foreach ($actualData as $data) {
                    $grandDisplayRap += $data['rap_alat'];
                }
            }
            
            $grandDisplayDiff = $grandDisplayRap - $grandDisplayActual;
            $overallDisplayProgress = $grandDisplayRap > 0 ? ($grandDisplayActual / $grandDisplayRap) * 100 : 0;
            $grandProgressClass = $overallDisplayProgress > 100 ? 'bg-danger' : ($overallDisplayProgress >= 75 ? 'bg-warning' : 'bg-success');
            $grandSelisihClass = $grandDisplayDiff < 0 ? 'text-danger' : 'text-success';
            
            // PPN & Rounding
            $ppnRap = $grandDisplayRap * ($ppnPercentage / 100);
            $ppnActual = $grandDisplayActual * ($ppnPercentage / 100);
            $totalRapWithPpn = $grandDisplayRap + $ppnRap;
            $totalActualWithPpn = $grandDisplayActual + $ppnActual;
            $totalRapRounded = ceil($totalRapWithPpn / 10) * 10;
            $totalActualRounded = ceil($totalActualWithPpn / 10) * 10;
            $diffWithPpn = $totalRapWithPpn - $totalActualWithPpn;
            $diffRounded = $totalRapRounded - $totalActualRounded;
            ?>
            <!-- Grand Total Row -->
            <tr class="table-dark">
                <td colspan="2" class="text-end sticky-col col-no" style="left: 0;"><strong>JUMLAH TOTAL<?= $categoryFilter !== 'all' ? ' (' . strtoupper($categoryFilter) . ')' : '' ?></strong></td>
                <td class="text-end sticky-col col-rap"><strong><?= formatNumber($grandDisplayRap, 2) ?></strong></td>
                <?php if ($categoryFilter === 'all'): ?>
                <td class="text-end text-primary sticky-col col-upah"><strong><?= formatNumber($grandActualUpah, 2) ?></strong></td>
                <td class="text-end text-success sticky-col col-material"><strong><?= formatNumber($grandActualMaterial, 2) ?></strong></td>
                <td class="text-end text-warning sticky-col col-alat"><strong><?= formatNumber($grandActualAlat, 2) ?></strong></td>
                <td class="text-end sticky-col col-total"><strong><?= formatNumber($grandActualTotal, 2) ?></strong></td>
                <?php else: ?>
                <td class="text-end sticky-col col-upah"><strong><?= formatNumber($grandDisplayActual, 2) ?></strong></td>
                <?php endif; ?>
                <td class="text-end <?= $grandSelisihClass ?> sticky-col col-selisih">
                    <strong><?= ($grandDisplayDiff >= 0 ? '+' : '') . formatNumber($grandDisplayDiff, 2) ?></strong>
                </td>
                <td class="sticky-col col-progress">
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
                <?php for ($i = 0; $i < count($weeklyRanges) * 3; $i++): ?>
                <td class="weekly-col"></td>
                <?php endfor; ?>
                <?php endif; ?>
            </tr>
            <!-- PPN Row -->
            <tr class="table-light">
                <td colspan="2" class="text-end sticky-col col-no" style="left: 0;"><strong>PPN <?= number_format($ppnPercentage, 0) ?>%</strong></td>
                <td class="text-end sticky-col col-rap"><strong><?= formatNumber($ppnRap, 2) ?></strong></td>
                <?php if ($categoryFilter === 'all'): ?>
                <td class="sticky-col col-upah"></td>
                <td class="sticky-col col-material"></td>
                <td class="sticky-col col-alat"></td>
                <td class="text-end sticky-col col-total"><strong><?= formatNumber($ppnActual, 2) ?></strong></td>
                <?php else: ?>
                <td class="text-end sticky-col col-upah"><strong><?= formatNumber($ppnActual, 2) ?></strong></td>
                <?php endif; ?>
                <td class="text-end <?= ($ppnRap - $ppnActual) < 0 ? 'text-danger' : 'text-success' ?> sticky-col col-selisih">
                    <strong><?= (($ppnRap - $ppnActual) >= 0 ? '+' : '') . formatNumber($ppnRap - $ppnActual, 2) ?></strong>
                </td>
                <td class="sticky-col col-progress"></td>
                <?php if ($showWeeklyColumns): ?>
                <?php for ($i = 0; $i < count($weeklyRanges) * 3; $i++): ?>
                <td class="weekly-col"></td>
                <?php endfor; ?>
                <?php endif; ?>
            </tr>
            <!-- Total + PPN Row -->
            <tr class="table-light">
                <td colspan="2" class="text-end sticky-col col-no" style="left: 0;"><strong>JUMLAH TOTAL (TERMASUK PPN)</strong></td>
                <td class="text-end sticky-col col-rap"><strong><?= formatNumber($totalRapWithPpn, 2) ?></strong></td>
                <?php if ($categoryFilter === 'all'): ?>
                <td class="sticky-col col-upah"></td>
                <td class="sticky-col col-material"></td>
                <td class="sticky-col col-alat"></td>
                <td class="text-end sticky-col col-total"><strong><?= formatNumber($totalActualWithPpn, 2) ?></strong></td>
                <?php else: ?>
                <td class="text-end sticky-col col-upah"><strong><?= formatNumber($totalActualWithPpn, 2) ?></strong></td>
                <?php endif; ?>
                <td class="text-end <?= $diffWithPpn < 0 ? 'text-danger' : 'text-success' ?> sticky-col col-selisih">
                    <strong><?= ($diffWithPpn >= 0 ? '+' : '') . formatNumber($diffWithPpn, 2) ?></strong>
                </td>
                <td class="sticky-col col-progress"></td>
                <?php if ($showWeeklyColumns): ?>
                <?php for ($i = 0; $i < count($weeklyRanges) * 3; $i++): ?>
                <td class="weekly-col"></td>
                <?php endfor; ?>
                <?php endif; ?>
            </tr>
            <!-- Rounded Total Row -->
            <tr class="table-primary">
                <td colspan="2" class="text-end sticky-col col-no" style="left: 0;"><strong>JUMLAH TOTAL DIBULATKAN</strong></td>
                <td class="text-end sticky-col col-rap"><strong><?= formatRupiah($totalRapRounded) ?></strong></td>
                <?php if ($categoryFilter === 'all'): ?>
                <td class="sticky-col col-upah"></td>
                <td class="sticky-col col-material"></td>
                <td class="sticky-col col-alat"></td>
                <td class="text-end sticky-col col-total"><strong><?= formatRupiah($totalActualRounded) ?></strong></td>
                <?php else: ?>
                <td class="text-end sticky-col col-upah"><strong><?= formatRupiah($totalActualRounded) ?></strong></td>
                <?php endif; ?>
                <td class="text-end <?= $diffRounded < 0 ? 'text-danger' : 'text-success' ?> sticky-col col-selisih">
                    <strong><?= ($diffRounded >= 0 ? '+' : '') . formatRupiah($diffRounded) ?></strong>
                </td>
                <td class="sticky-col col-progress"></td>
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
        <em class="ms-2">RAP sudah termasuk overhead <?= number_format($overheadPct, 0) ?>%</em>
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
    html += '<tr><th>Tanggal</th><td>' + formatDateId(req.request_date) + '</td></tr>';
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
        html += '<tr><th>Tgl Disetujui</th><td>' + formatDateId(req.pm_approved_at) + '</td></tr>';
    }
    if (req.pm_notes) {
        html += '<tr><th>Catatan PM</th><td>' + escapeHtml(req.pm_notes) + '</td></tr>';
    }
    if (req.approved_by_name) {
        html += '<tr><th>Diproses Admin</th><td>' + escapeHtml(req.approved_by_name) + '</td></tr>';
    }
    if (req.approved_at) {
        html += '<tr><th>Tgl Diproses</th><td>' + formatDateId(req.approved_at) + '</td></tr>';
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
            '<th style="width:90px" class="text-end">Koefisien</th>' +
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
</script>

<?php endif; ?>
