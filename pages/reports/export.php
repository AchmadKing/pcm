<?php
/**
 * Export RAB/Comparison to CSV
 * PCM - Project Cost Management System
 * Updated for new per-project structure
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAdmin();

$projectId = $_GET['project_id'] ?? '';
$type = $_GET['type'] ?? 'rab'; // rab, comparison

// If project_id provided, export directly
if ($projectId && isset($_GET['download'])) {
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
    
    if ($type === 'rab') {
        // RAB Export - now uses subcategories directly
        fputcsv($output, ['RENCANA ANGGARAN BIAYA (RAB)']);
        fputcsv($output, ['Proyek: ' . $project['name']]);
        fputcsv($output, ['Wilayah: ' . ($project['region_name'] ?? '-')]);
        fputcsv($output, ['Tanggal Export: ' . date('d-m-Y H:i')]);
        fputcsv($output, []);
        
        fputcsv($output, ['Kode', 'Uraian Pekerjaan', 'Satuan', 'Volume', 'Harga Satuan', 'Jumlah Harga']);
        
        $categories = dbGetAll("SELECT * FROM rab_categories WHERE project_id = ? ORDER BY sort_order, code", [$projectId]);
        
        $grandTotal = 0;
        
        foreach ($categories as $cat) {
            // Category header
            fputcsv($output, [$cat['code'], strtoupper($cat['name']), '', '', '', '']);
            
            // Subcategories
            $subcats = dbGetAll("SELECT * FROM rab_subcategories WHERE category_id = ? ORDER BY sort_order, code", [$cat['id']]);
            $catTotal = 0;
            
            foreach ($subcats as $idx => $sub) {
                $total = $sub['volume'] * $sub['unit_price'];
                $catTotal += $total;
                
                fputcsv($output, [
                    $sub['code'],
                    $sub['name'],
                    $sub['unit'],
                    number_format($sub['volume'], 2, ',', '.'),
                    number_format($sub['unit_price'], 2, ',', '.'),
                    number_format($total, 2, ',', '.')
                ]);
            }
            
            fputcsv($output, ['', '', '', '', 'Jumlah ' . $cat['code'], number_format($catTotal, 2, ',', '.')]);
            $grandTotal += $catTotal;
        }
        
        fputcsv($output, []);
        fputcsv($output, ['', '', '', '', 'TOTAL RAB', number_format($grandTotal, 2, ',', '.')]);
        
    } elseif ($type === 'comparison') {
        // RAB vs RAP vs Actual Comparison
        fputcsv($output, ['PERBANDINGAN RAB vs RAP vs AKTUAL']);
        fputcsv($output, ['Proyek: ' . $project['name']]);
        fputcsv($output, ['Wilayah: ' . ($project['region_name'] ?? '-')]);
        fputcsv($output, ['Tanggal Export: ' . date('d-m-Y H:i')]);
        fputcsv($output, []);
        
        fputcsv($output, ['Kode', 'Uraian', 'Satuan', 'Vol RAB', 'Harga RAB', 'Total RAB', 'Vol RAP', 'Harga RAP', 'Total RAP', 'Total Aktual', 'Selisih']);
        
        $items = dbGetAll("
            SELECT rs.code, rs.name, rs.unit, 
                   rs.volume as rab_vol, rs.unit_price as rab_price, (rs.volume * rs.unit_price) as rab_total,
                   COALESCE(rap.volume, 0) as rap_vol, COALESCE(rap.unit_price, 0) as rap_price, 
                   COALESCE(rap.total_price, 0) as rap_total,
                   COALESCE((SELECT SUM(reqi.total_price) FROM request_items reqi 
                             JOIN requests req ON reqi.request_id = req.id 
                             WHERE reqi.subcategory_id = rs.id AND req.status = 'approved'), 0) as actual_total,
                   rc.code as cat_code, rc.name as cat_name
            FROM rab_subcategories rs
            JOIN rab_categories rc ON rs.category_id = rc.id
            LEFT JOIN rap_items rap ON rap.subcategory_id = rs.id
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
            
            $selisih = $item['rab_total'] - $item['actual_total'];
            fputcsv($output, [
                $item['code'],
                $item['name'],
                $item['unit'],
                number_format($item['rab_vol'], 2, ',', '.'),
                number_format($item['rab_price'], 2, ',', '.'),
                number_format($item['rab_total'], 2, ',', '.'),
                number_format($item['rap_vol'], 2, ',', '.'),
                number_format($item['rap_price'], 2, ',', '.'),
                number_format($item['rap_total'], 2, ',', '.'),
                number_format($item['actual_total'], 2, ',', '.'),
                number_format($selisih, 2, ',', '.')
            ]);
            $totals['rab'] += $item['rab_total'];
            $totals['rap'] += $item['rap_total'];
            $totals['actual'] += $item['actual_total'];
        }
        
        fputcsv($output, []);
        fputcsv($output, [
            '', 'TOTAL', '', '', '', 
            number_format($totals['rab'], 2, ',', '.'), '', '', 
            number_format($totals['rap'], 2, ',', '.'), 
            number_format($totals['actual'], 2, ',', '.'), 
            number_format($totals['rab'] - $totals['actual'], 2, ',', '.')
        ]);
    }
    
    fclose($output);
    exit;
}

// Show export page
$pageTitle = 'Export CSV';
require_once __DIR__ . '/../../includes/header.php';

// Get projects
$projects = dbGetAll("SELECT id, name, status FROM projects WHERE status != 'draft' ORDER BY name");
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
        
        <div class="card">
            <div class="card-body">
                <h5 class="header-title mb-3">Keterangan Export</h5>
                <ul class="mb-0">
                    <li><strong>RAB</strong>: Export detail Rencana Anggaran Biaya per pekerjaan</li>
                    <li><strong>Perbandingan</strong>: Export lengkap RAB, RAP, dan Aktual dengan selisih</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
