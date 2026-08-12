<?php
/**
 * Export RAB/Comparison to CSV
 * PCM - Project Cost Management System
 * Updated for new per-project structure
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermission('reports.export');

$projectId = $_GET['project_id'] ?? '';
$type = $_GET['type'] ?? 'rab'; // rab, comparison

// If project_id provided, export directly
if ($projectId && isset($_GET['download'])) {
    if (!canAccessProject($projectId)) {
        die('Anda tidak memiliki akses ke proyek ini');
    }
    
    $project = dbGetRow("SELECT * FROM projects WHERE id = ?", [$projectId]);
    
    if (!$project) {
        die('Proyek tidak ditemukan');
    }
    
    // Set headers for CSV download
    $filename = strtolower(str_replace(' ', '_', $project['name'])) . '_' . $type . '_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // BOM for Excel UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    $overheadPct = getProjectOverheadProfitPct($project);
    $ppnPct = floatval($project['ppn_percentage'] ?? 11);

    // Pre-calculate RAB AHSP prices
    $ahspPrices = [];
    $ahspRows = dbGetAll("
        SELECT d.ahsp_id, SUM(d.coefficient * COALESCE(d.unit_price, i.price)) as total 
        FROM project_ahsp_details d 
        JOIN project_items i ON d.item_id = i.id 
        JOIN project_ahsp pa ON d.ahsp_id = pa.id
        WHERE pa.project_id = ?
        GROUP BY d.ahsp_id
    ", [$projectId]);
    foreach ($ahspRows as $r) {
        $ahspPrices[$r['ahsp_id']] = floatval($r['total']);
    }

    if ($type === 'rab') {
        // RAB Export - now uses subcategories directly with dynamic AHSP prices & overhead
        fputcsv($output, ['RENCANA ANGGARAN BIAYA (RAB)']);
        fputcsv($output, ['Proyek: ' . $project['name']]);
        fputcsv($output, ['Wilayah: ' . ($project['region_name'] ?? '-')]);
        fputcsv($output, ['Tanggal Export: ' . date('d-m-Y H:i')]);
        fputcsv($output, []);
        
        fputcsv($output, ['Kode', 'Uraian Pekerjaan', 'Satuan', 'Volume', 'Harga Satuan (Rp)', 'Jumlah Harga (Rp)']);
        
        $categories = dbGetAll("SELECT * FROM rab_categories WHERE project_id = ? ORDER BY sort_order, code", [$projectId]);
        
        $subtotalRab = 0;
        
        foreach ($categories as $cat) {
            // Category header
            fputcsv($output, [$cat['code'], strtoupper($cat['name']), '', '', '', '']);
            
            // Subcategories
            $subcats = dbGetAll("SELECT * FROM rab_subcategories WHERE category_id = ? ORDER BY sort_order, code", [$cat['id']]);
            $catTotal = 0;
            
            foreach ($subcats as $sub) {
                $basePrice = isset($ahspPrices[$sub['ahsp_id']]) ? $ahspPrices[$sub['ahsp_id']] : floatval($sub['unit_price']);
                $unitPriceWithOverhead = $basePrice * (1 + ($overheadPct / 100));
                $total = floatval($sub['volume']) * $unitPriceWithOverhead;
                $catTotal += $total;
                
                fputcsv($output, [
                    $sub['code'],
                    $sub['name'],
                    $sub['unit'],
                    number_format($sub['volume'], 2, ',', '.'),
                    number_format($unitPriceWithOverhead, 2, ',', '.'),
                    number_format($total, 2, ',', '.')
                ]);
            }
            
            fputcsv($output, ['', '', '', '', 'Jumlah ' . $cat['code'], number_format($catTotal, 2, ',', '.')]);
            $subtotalRab += $catTotal;
        }
        
        $ppnAmount = $subtotalRab * ($ppnPct / 100);
        $totalWithPpn = $subtotalRab + $ppnAmount;
        $totalRounded = ceil($totalWithPpn / 10) * 10;

        fputcsv($output, []);
        fputcsv($output, ['', '', '', '', 'SUBTOTAL RAB', number_format($subtotalRab, 2, ',', '.')]);
        fputcsv($output, ['', '', '', '', 'PPN ' . number_format($ppnPct, 2, ',', '.') . '%', number_format($ppnAmount, 2, ',', '.')]);
        fputcsv($output, ['', '', '', '', 'TOTAL RAB (TERMASUK PPN)', number_format($totalWithPpn, 2, ',', '.')]);
        fputcsv($output, ['', '', '', '', 'TOTAL RAB (DIBULATKAN)', number_format($totalRounded, 0, ',', '.')]);
        
    } elseif ($type === 'comparison') {
        // RAB vs RAP vs Actual Comparison
        fputcsv($output, ['PERBANDINGAN RAB vs RAP vs AKTUAL']);
        fputcsv($output, ['Proyek: ' . $project['name']]);
        fputcsv($output, ['Wilayah: ' . ($project['region_name'] ?? '-')]);
        fputcsv($output, ['Tanggal Export: ' . date('d-m-Y H:i')]);
        fputcsv($output, []);
        
        fputcsv($output, ['Kode', 'Uraian', 'Satuan', 'Vol RAB', 'Harga RAB', 'Total RAB', 'Vol RAP', 'Harga RAP', 'Total RAP', 'Total Aktual', 'Selisih']);
        
        // Pre-calculate RAP AHSP component totals
        $ahspRapPrices = [];
        $ahspRapRows = dbGetAll("
            SELECT pa.ahsp_code, SUM(d.coefficient * COALESCE(d.unit_price, i.price)) as total 
            FROM project_ahsp_details_rap d 
            JOIN project_items_rap i ON d.item_id = i.id 
            JOIN project_ahsp_rap pa ON d.ahsp_id = pa.id
            WHERE pa.project_id = ?
            GROUP BY pa.ahsp_code
        ", [$projectId]);
        foreach ($ahspRapRows as $r) {
            $ahspRapPrices[$r['ahsp_code']] = floatval($r['total']);
        }

        // Pre-compute actualization adjustments per subcategory
        $actualizationAdjustments = [];
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
            $reqItems = dbGetAll("
                SELECT reqi.subcategory_id, SUM(reqi.total_price) as subcat_total
                FROM request_items reqi
                WHERE reqi.request_id = ?
                GROUP BY reqi.subcategory_id
            ", [$ar['request_id']]);
            $reqTotal = 0;
            foreach ($reqItems as $ri) {
                $reqTotal += floatval($ri['subcat_total']);
            }
            if ($reqTotal > 0) {
                foreach ($reqItems as $ri) {
                    $subcatId = intval($ri['subcategory_id']);
                    $proportion = floatval($ri['subcat_total']) / $reqTotal;
                    $deduction = $totalRemaining * $proportion;
                    $actualizationAdjustments[$subcatId] = ($actualizationAdjustments[$subcatId] ?? 0) + $deduction;
                }
            }
        }

        $items = dbGetAll("
            SELECT rs.id, rs.code, rs.name, rs.unit, rs.volume as rab_vol, rs.unit_price as rab_price, rs.ahsp_id,
                   rap.volume as rap_vol, rap.unit_price as rap_price,
                   pa.ahsp_code,
                   rc.code as cat_code, rc.name as cat_name
            FROM rab_subcategories rs
            JOIN rab_categories rc ON rs.category_id = rc.id
            LEFT JOIN rap_items rap ON rap.subcategory_id = rs.id
            LEFT JOIN project_ahsp pa ON rs.ahsp_id = pa.id
            WHERE rc.project_id = ?
            ORDER BY rc.sort_order, rc.code, rs.sort_order, rs.code
        ", [$projectId]);
        
        $totals = ['rab' => 0, 'rap' => 0, 'actual' => 0];
        $currentCat = '';
        
        foreach ($items as $item) {
            if ($currentCat !== $item['cat_code']) {
                $currentCat = $item['cat_code'];
                fputcsv($output, [$item['cat_code'], strtoupper($item['cat_name']), '', '', '', '', '', '', '', '', '']);
            }

            // RAB calculation
            $rabBasePrice = isset($ahspPrices[$item['ahsp_id']]) ? $ahspPrices[$item['ahsp_id']] : floatval($item['rab_price']);
            $rabPriceWithOverhead = $rabBasePrice * (1 + ($overheadPct / 100));
            $rabVol = floatval($item['rab_vol']);
            $rabTotal = $rabVol * $rabPriceWithOverhead;

            // RAP calculation
            $rapVol = (isset($item['rap_vol']) && $item['rap_vol'] !== null) ? floatval($item['rap_vol']) : $rabVol;
            $ahspCode = $item['ahsp_code'] ?? null;
            $rapBasePrice = 0;
            if ($ahspCode && isset($ahspRapPrices[$ahspCode])) {
                $rapBasePrice = $ahspRapPrices[$ahspCode];
            }
            if ($rapBasePrice <= 0) {
                $rapBasePrice = (isset($item['rap_price']) && floatval($item['rap_price']) > 0) ? floatval($item['rap_price']) : floatval($item['rab_price']);
            }
            $rapPriceWithOverhead = $rapBasePrice * (1 + ($overheadPct / 100));
            $rapTotal = $rapVol * $rapPriceWithOverhead;

            // Actual calculation
            $actRow = dbGetRow("
                SELECT COALESCE(SUM(reqi.total_price), 0) as total
                FROM request_items reqi
                JOIN requests req ON reqi.request_id = req.id
                WHERE reqi.subcategory_id = ? AND req.status = 'approved' AND req.project_id = ?
            ", [$item['id'], $projectId]);
            $actTotal = floatval($actRow['total'] ?? 0);
            $adj = $actualizationAdjustments[$item['id']] ?? 0;
            $actTotal -= $adj;
            if ($actTotal < 0) $actTotal = 0;
            
            $selisih = $rabTotal - $actTotal;

            fputcsv($output, [
                $item['code'],
                $item['name'],
                $item['unit'],
                number_format($rabVol, 2, ',', '.'),
                number_format($rabPriceWithOverhead, 2, ',', '.'),
                number_format($rabTotal, 2, ',', '.'),
                number_format($rapVol, 2, ',', '.'),
                number_format($rapPriceWithOverhead, 2, ',', '.'),
                number_format($rapTotal, 2, ',', '.'),
                number_format($actTotal, 2, ',', '.'),
                number_format($selisih, 2, ',', '.')
            ]);

            $totals['rab'] += $rabTotal;
            $totals['rap'] += $rapTotal;
            $totals['actual'] += $actTotal;
        }
        
        fputcsv($output, []);
        fputcsv($output, [
            '', 'SUBTOTAL', '', '', '', 
            number_format($totals['rab'], 2, ',', '.'), '', '', 
            number_format($totals['rap'], 2, ',', '.'), 
            number_format($totals['actual'], 2, ',', '.'), 
            number_format($totals['rab'] - $totals['actual'], 2, ',', '.')
        ]);

        $ppnAmount = $totals['rab'] * ($ppnPct / 100);
        $totalWithPpn = $totals['rab'] + $ppnAmount;
        $totalRounded = ceil($totalWithPpn / 10) * 10;

        fputcsv($output, [
            '', 'PPN (' . number_format($ppnPct, 2, ',', '.') . '%)', '', '', '', 
            number_format($ppnAmount, 2, ',', '.'), '', '', '', '', ''
        ]);
        fputcsv($output, [
            '', 'TOTAL RAB (DIBULATKAN)', '', '', '', 
            number_format($totalRounded, 0, ',', '.'), '', '', '', '', ''
        ]);
    }
    
    fclose($output);
    exit;
}

// Show export page
$pageTitle = 'Export CSV';
require_once __DIR__ . '/../../includes/header.php';

// Get projects based on view mode
$expViewMode = getProjectViewMode();
if ($expViewMode === 'all') {
    $projects = dbGetAll("SELECT id, name, status FROM projects WHERE status != 'draft' ORDER BY name");
} elseif ($expViewMode === 'assigned') {
    $projects = dbGetAll("
        SELECT p.id, p.name, p.status 
        FROM projects p 
        JOIN project_assignments pa ON pa.project_id = p.id 
        WHERE p.status != 'draft' AND pa.user_id = ? AND pa.is_active = 1 
        ORDER BY p.name
    ", [getCurrentUserId()]);
} else {
    $projects = [];
}
?>

<!-- Page Title -->
<div class="row">
    <div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
            <h4 class="mb-sm-0">Export CSV</h4>
            <div class="page-title-right">
                <ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="<?= $baseUrl ?>">PCM</a></li>
                    <li class="breadcrumb-item"><a href="dashboard.php">Laporan</a></li>
                    <li class="breadcrumb-item active">Export</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-6 mx-auto">
        <div class="card">
            <div class="card-body">
                <h4 class="header-title mb-4">Pilih Proyek untuk Export</h4>
                
                <form method="GET">
                    <input type="hidden" name="download" value="1">
                    
                    <div class="mb-3">
                        <label class="form-label required">Proyek</label>
                        <select class="form-select select2" name="project_id" required>
                            <option value="">-- Pilih Proyek --</option>
                            <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['id'] ?>">
                                <?= sanitize($p['name']) ?> 
                                (<?= $p['status'] === 'on_progress' ? 'Berjalan' : 'Selesai' ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label required">Tipe Export</label>
                        <select class="form-select" name="type" required>
                            <option value="rab">RAB (Rencana Anggaran Biaya)</option>
                            <option value="comparison">Perbandingan RAB vs RAP vs Aktual</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-success">
                        <i class="mdi mdi-download"></i> Download CSV
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">Kembali</a>
                </form>
            </div>
        </div>
        
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
