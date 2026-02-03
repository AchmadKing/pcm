<?php
/**
 * Actual Tab - Project Dashboard
 * Shows actual spending vs RAP with breakdown by Upah, Material, Alat
 * Displays hierarchical view (Category -> Subcategory) like RAP page
 * Progress calculated: Category progress = average of subcategory progress
 * Weekly Progress: Shows weekly date columns when project is started
 */

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

// Get categories
$categories = dbGetAll("
    SELECT rc.id, rc.code, rc.name, rc.sort_order
    FROM rab_categories rc
    WHERE rc.project_id = ?
    ORDER BY rc.sort_order, rc.code
", [$projectId]);

// Function to get RAP AHSP component breakdown
function getActualRapComponentBreakdown($rapItemId) {
    $result = ['upah' => 0, 'material' => 0, 'alat' => 0];
    
    if (!$rapItemId) return $result;
    
    // Try RAP-specific AHSP details first
    $totals = dbGetAll("
        SELECT d.category, SUM(d.coefficient * d.unit_price) as total 
        FROM rap_ahsp_details d 
        WHERE d.rap_item_id = ?
        GROUP BY d.category
    ", [$rapItemId]);
    
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
        
        // Get actual breakdown by component
        $actualBreakdown = dbGetAll("
            SELECT 
                pi.category as item_category,
                COALESCE(SUM(
                    reqi.total_price * 
                    (pad.coefficient * pi.price) / 
                    NULLIF(ahsp.unit_price, 0)
                ), 0) as category_total
            FROM request_items reqi
            JOIN requests req ON reqi.request_id = req.id
            JOIN rab_subcategories rs ON reqi.subcategory_id = rs.id
            JOIN project_ahsp ahsp ON rs.ahsp_id = ahsp.id
            JOIN project_ahsp_details pad ON pad.ahsp_id = ahsp.id
            JOIN project_items pi ON pad.item_id = pi.id
            WHERE reqi.subcategory_id = ? 
            AND req.status = 'approved'
            AND req.project_id = ?
            AND ahsp.unit_price > 0
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

<?php 
// Calculate total columns for footer (base + weekly)
$baseColCount = 9; // No, Uraian, RAP, Upah, Material, Alat, Total, Selisih, Progress
$weeklyColCount = $showWeeklyColumns ? count($weeklyRanges) * 3 : 0; // Each week has 3 columns
$totalColCount = $baseColCount + $weeklyColCount;

// Define sticky column positions (cumulative left values)
// Col widths: No=50px, Uraian=220px, RAP=110px, Upah=90px, Material=90px, Alat=90px, Total=100px, Selisih=100px, Progress=110px
$stickyPositions = [
    0 => 0,           // No
    1 => 50,          // Uraian Pekerjaan
    2 => 270,         // RAP (Target)
    3 => 380,         // Upah
    4 => 470,         // Material
    5 => 560,         // Alat
    6 => 650,         // Total
    7 => 750,         // Selisih
    8 => 850          // Progress
];
$lastStickyRight = 960; // Total width of sticky area
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
    min-width: <?= $showWeeklyColumns ? (960 + (count($weeklyRanges) * 230)) : 960 ?>px;
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

/* Column position classes */
.col-no { left: 0px; min-width: 50px; max-width: 50px; }
.col-uraian { left: 50px; min-width: 220px; }
.col-rap { left: 270px; min-width: 110px; max-width: 110px; }
.col-upah { left: 380px; min-width: 90px; max-width: 90px; }
.col-material { left: 470px; min-width: 90px; max-width: 90px; }
.col-alat { left: 560px; min-width: 90px; max-width: 90px; }
.col-total { left: 650px; min-width: 100px; max-width: 100px; }
.col-selisih { left: 750px; min-width: 100px; max-width: 100px; }
.col-progress { left: 850px; min-width: 110px; max-width: 110px; }

/* Row background colors for sticky cells */
.table-primary .sticky-col { background-color: #cfe2ff !important; }
.table-secondary .sticky-col { background-color: #e2e3e5 !important; }
.table-dark .sticky-col { background-color: #212529 !important; color: #fff !important; }
.table-light .sticky-col { background-color: #f8f9fa !important; }
.sub-row .sticky-col { background-color: #fff !important; }

/* Weekly column styling */
.weekly-col {
    background-color: #f0f9ff;
    border-left: 2px solid #0dcaf0;
}
.weekly-header {
    background-color: #0dcaf0 !important;
    color: #000 !important;
}
.weekly-subheader {
    background-color: #e0f7ff !important;
    color: #000 !important;
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
                <th rowspan="2" class="align-middle text-center sticky-col sticky-col-header col-no">No</th>
                <th rowspan="2" class="align-middle sticky-col sticky-col-header col-uraian">Uraian Pekerjaan</th>
                <th rowspan="2" class="align-middle text-end sticky-col sticky-col-header col-rap">RAP (Target)</th>
                <th colspan="4" class="text-center sticky-col sticky-col-header col-upah" style="left: 380px;">Realisasi Total</th>
                <th rowspan="2" class="align-middle text-end sticky-col sticky-col-header col-selisih">Selisih</th>
                <th rowspan="2" class="align-middle text-center sticky-col sticky-col-header col-progress">Progress</th>
                <?php if ($showWeeklyColumns): ?>
                <?php foreach ($weeklyRanges as $week): ?>
                <th colspan="3" class="text-center weekly-header">
                    Minggu ke-<?= $week['week_number'] ?><br>
                    <small>(<?= formatWeekRangeLabel($week['start'], $week['end']) ?>)</small>
                </th>
                <?php endforeach; ?>
                <?php endif; ?>
            </tr>
            <!-- Row 2: Sub Headers -->
            <tr>
                <th class="text-end sticky-col sticky-col-header col-upah">Upah</th>
                <th class="text-end sticky-col sticky-col-header col-material">Material</th>
                <th class="text-end sticky-col sticky-col-header col-alat">Alat</th>
                <th class="text-end sticky-col sticky-col-header col-total">Total</th>
                <?php if ($showWeeklyColumns): ?>
                <?php foreach ($weeklyRanges as $week): ?>
                <th class="text-end weekly-subheader">Realisasi (Rp)</th>
                <th class="text-end weekly-subheader">Bobot (%)</th>
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
                <td class="sticky-col col-upah"></td>
                <td class="sticky-col col-material"></td>
                <td class="sticky-col col-alat"></td>
                <td class="sticky-col col-total"></td>
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
                $progressClass = $sub['progress'] > 100 ? 'bg-danger' : ($sub['progress'] >= 75 ? 'bg-warning' : 'bg-success');
                $selisihClass = $sub['selisih'] < 0 ? 'text-danger' : 'text-success';
            ?>
            <tr class="sub-row" data-subcategory-id="<?= $sub['id'] ?>">
                <td class="sticky-col col-no"><?= sanitize($sub['code']) ?></td>
                <td class="sticky-col col-uraian"><?= sanitize($sub['name']) ?></td>
                <td class="sticky-col col-rap text-end"><?= formatNumber($sub['rap_total'], 2) ?></td>
                <td class="sticky-col col-upah text-end text-primary"><?= formatNumber($sub['actual_upah'], 2) ?></td>
                <td class="sticky-col col-material text-end text-success"><?= formatNumber($sub['actual_material'], 2) ?></td>
                <td class="sticky-col col-alat text-end text-warning"><?= formatNumber($sub['actual_alat'], 2) ?></td>
                <td class="sticky-col col-total text-end"><strong><?= formatNumber($sub['actual_total'], 2) ?></strong></td>
                <td class="sticky-col col-selisih text-end <?= $selisihClass ?>">
                    <?= ($sub['selisih'] >= 0 ? '+' : '') . formatNumber($sub['selisih'], 2) ?>
                </td>
                <td class="sticky-col col-progress">
                    <div class="progress" style="height: 18px;">
                        <div class="progress-bar <?= $progressClass ?>" 
                             style="width: <?= min($sub['progress'], 100) ?>%">
                            <?= number_format($sub['progress'], 1) ?>%
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
                <td class="text-end weekly-col week-bobot-<?= $sub['id'] ?>-<?= $weekNum ?>">
                    <?= $weekBobot > 0 ? number_format($weekBobot, 2) : '-' ?>%
                </td>
                <?php endforeach; ?>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            
            <!-- Category Total -->
            <?php 
                $catProgressClass = $catProgress > 100 ? 'bg-danger' : ($catProgress >= 75 ? 'bg-warning' : 'bg-success');
                $catSelisihClass = $catSelisih < 0 ? 'text-danger' : 'text-success';
            ?>
            <tr class="table-secondary">
                <td colspan="2" class="text-end sticky-col col-no" style="left: 0;"><strong>JUMLAH <?= sanitize($cat['code']) ?></strong></td>
                <td class="text-end sticky-col col-rap"><strong><?= formatNumber($data['rap_total'], 2) ?></strong></td>
                <td class="text-end text-primary sticky-col col-upah"><strong><?= formatNumber($data['actual_upah'], 2) ?></strong></td>
                <td class="text-end text-success sticky-col col-material"><strong><?= formatNumber($data['actual_material'], 2) ?></strong></td>
                <td class="text-end text-warning sticky-col col-alat"><strong><?= formatNumber($data['actual_alat'], 2) ?></strong></td>
                <td class="text-end sticky-col col-total"><strong><?= formatNumber($data['actual_total'], 2) ?></strong></td>
                <td class="text-end <?= $catSelisihClass ?> sticky-col col-selisih">
                    <strong><?= ($catSelisih >= 0 ? '+' : '') . formatNumber($catSelisih, 2) ?></strong>
                </td>
                <td class="sticky-col col-progress">
                    <div class="d-flex align-items-center">
                        <div class="progress flex-grow-1" style="height: 18px;">
                            <div class="progress-bar <?= $catProgressClass ?>" 
                                 style="width: <?= min($catProgress, 100) ?>%">
                            </div>
                        </div>
                        <strong class="ms-2" style="min-width: 45px;"><?= number_format($catProgress, 1) ?>%</strong>
                    </div>
                </td>
                <?php if ($showWeeklyColumns): ?>
                <?php for ($i = 0; $i < count($weeklyRanges) * 2; $i++): ?>
                <td class="weekly-col"></td>
                <?php endfor; ?>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <?php 
            // Grand progress & totals
            $overallProgress = $grandSubcatCount > 0 ? ($grandProgressSum / $grandSubcatCount) : 0;
            $grandDiff = $grandRap - $grandActualTotal;
            $grandProgressClass = $overallProgress > 100 ? 'bg-danger' : ($overallProgress >= 75 ? 'bg-warning' : 'bg-success');
            $grandSelisihClass = $grandDiff < 0 ? 'text-danger' : 'text-success';
            
            // PPN & Rounding
            $ppnRap = $grandRap * ($ppnPercentage / 100);
            $ppnActual = $grandActualTotal * ($ppnPercentage / 100);
            $totalRapWithPpn = $grandRap + $ppnRap;
            $totalActualWithPpn = $grandActualTotal + $ppnActual;
            $totalRapRounded = ceil($totalRapWithPpn / 10) * 10;
            $totalActualRounded = ceil($totalActualWithPpn / 10) * 10;
            $diffWithPpn = $totalRapWithPpn - $totalActualWithPpn;
            $diffRounded = $totalRapRounded - $totalActualRounded;
            ?>
            <!-- Grand Total Row -->
            <tr class="table-dark">
                <td colspan="2" class="text-end sticky-col col-no" style="left: 0;"><strong>JUMLAH TOTAL</strong></td>
                <td class="text-end sticky-col col-rap"><strong><?= formatNumber($grandRap, 2) ?></strong></td>
                <td class="text-end text-primary sticky-col col-upah"><strong><?= formatNumber($grandActualUpah, 2) ?></strong></td>
                <td class="text-end text-success sticky-col col-material"><strong><?= formatNumber($grandActualMaterial, 2) ?></strong></td>
                <td class="text-end text-warning sticky-col col-alat"><strong><?= formatNumber($grandActualAlat, 2) ?></strong></td>
                <td class="text-end sticky-col col-total"><strong><?= formatNumber($grandActualTotal, 2) ?></strong></td>
                <td class="text-end <?= $grandSelisihClass ?> sticky-col col-selisih">
                    <strong><?= ($grandDiff >= 0 ? '+' : '') . formatNumber($grandDiff, 2) ?></strong>
                </td>
                <td class="sticky-col col-progress">
                    <div class="d-flex align-items-center">
                        <div class="progress flex-grow-1" style="height: 18px;">
                            <div class="progress-bar <?= $grandProgressClass ?>" 
                                 style="width: <?= min($overallProgress, 100) ?>%">
                            </div>
                        </div>
                        <strong class="ms-2 text-white" style="min-width: 45px;"><?= number_format($overallProgress, 1) ?>%</strong>
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
                <td class="sticky-col col-upah"></td>
                <td class="sticky-col col-material"></td>
                <td class="sticky-col col-alat"></td>
                <td class="text-end sticky-col col-total"><strong><?= formatNumber($ppnActual, 2) ?></strong></td>
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
                <td class="sticky-col col-upah"></td>
                <td class="sticky-col col-material"></td>
                <td class="sticky-col col-alat"></td>
                <td class="text-end sticky-col col-total"><strong><?= formatNumber($totalActualWithPpn, 2) ?></strong></td>
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
                <td class="sticky-col col-upah"></td>
                <td class="sticky-col col-material"></td>
                <td class="sticky-col col-alat"></td>
                <td class="text-end sticky-col col-total"><strong><?= formatRupiah($totalActualRounded) ?></strong></td>
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

<?php endif; ?>
