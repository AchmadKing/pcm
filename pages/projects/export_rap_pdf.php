<?php
/**
 * Export RAP to PDF (Print-Ready HTML)
 * PCC - Project Cost Control System
 * Standalone HTML page for browser print-to-PDF
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();
requirePermission('rap.view');

$projectId = $_GET['id'] ?? null;

if (!$projectId || !canAccessProject($projectId)) {
    die('Anda tidak memiliki akses ke proyek ini');
}

$project = dbGetRow("SELECT * FROM projects WHERE id = ?", [$projectId]);
if (!$project) {
    die('Proyek tidak ditemukan');
}

$overheadPct = getProjectOverheadProfitPct($project, 'rap');
$regionName = $project['region_name'] ?? '-';

// Function to get RAP AHSP component breakdown from Master Data RAP
function getRapAhspBreakdownForPdf($ahspCode, $projectId) {
    $result = ['upah' => 0, 'material' => 0, 'alat' => 0];
    if (!$ahspCode) return $result;
    
    $ahspRap = dbGetRow("
        SELECT id FROM project_ahsp_rap 
        WHERE project_id = ? AND ahsp_code = ?
    ", [$projectId, $ahspCode]);
    
    if (!$ahspRap) return $result;
    
    $totals = dbGetAll("
        SELECT i.category, SUM(d.coefficient * COALESCE(d.unit_price, i.price)) as total 
        FROM project_ahsp_details_rap d
        JOIN project_items_rap i ON d.item_id = i.id
        WHERE d.ahsp_id = ?
        GROUP BY i.category
    ", [$ahspRap['id']]);
    
    foreach ($totals as $row) {
        if (isset($result[$row['category']])) {
            $result[$row['category']] = $row['total'];
        }
    }
    return $result;
}

// Get enriched data
$categories = dbGetAll("SELECT * FROM rab_categories WHERE project_id = ? ORDER BY sort_order, code", [$projectId]);

$rapData = [];
$grandTotal = 0;

foreach ($categories as $cat) {
    $subcats = dbGetAll("
        SELECT rs.*, rap.id as rap_id, rap.volume as rap_volume, rap.unit_price as rap_unit_price,
               pa.ahsp_code as ahsp_code
        FROM rab_subcategories rs
        LEFT JOIN rap_items rap ON rs.id = rap.subcategory_id
        LEFT JOIN project_ahsp pa ON rs.ahsp_id = pa.id
        WHERE rs.category_id = ? 
        ORDER BY rs.sort_order, rs.code
    ", [$cat['id']]);
    
    $catTotal = 0;
    $enrichedSubcats = [];
    foreach ($subcats as $sub) {
        $volume = $sub['rap_volume'] ?? $sub['volume'];
        
        // Get AHSP component breakdown from Master Data RAP
        $ahspCode = $sub['ahsp_code'] ?? null;
        if ($ahspCode) {
            $components = getRapAhspBreakdownForPdf($ahspCode, $projectId);
        } else {
            $components = ['upah' => 0, 'material' => 0, 'alat' => 0];
        }
        
        // Derive baseUnitPrice directly from component totals (D = A+B+C)
        $baseUnitPrice = $components['upah'] + $components['material'] + $components['alat'];
        
        // If no AHSP RAP data available, fallback to stored value
        if ($baseUnitPrice <= 0) {
            $baseUnitPrice = $sub['rap_unit_price'] ?? $sub['unit_price'];
        }
        
        $unitPriceWithOverhead = $baseUnitPrice * (1 + ($overheadPct / 100));
        $subTotal = $volume * $unitPriceWithOverhead;
        $catTotal += $subTotal;
        
        $sub['display_volume'] = $volume;
        $sub['display_unit_price'] = $unitPriceWithOverhead;
        $sub['display_total'] = $subTotal;
        
        $enrichedSubcats[] = $sub;
    }
    
    $grandTotal += $catTotal;
    $rapData[$cat['id']] = [
        'category' => $cat,
        'subcategories' => $enrichedSubcats,
        'total' => $catTotal
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan RAP - <?= htmlspecialchars($project['name']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 11pt;
            color: #000;
            background: #f5f5f5;
            padding: 20px;
        }

        .page-container {
            background: #fff;
            max-width: 297mm;
            margin: 0 auto;
            padding: 15mm 12mm;
            box-shadow: 0 2px 10px rgba(0,0,0,0.15);
        }

        .report-title {
            text-align: center;
            font-size: 14pt;
            font-weight: bold;
            margin-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .project-info {
            margin-bottom: 15px;
            border-collapse: collapse;
            width: 100%;
        }
        .project-info td {
            padding: 2px 5px;
            vertical-align: top;
            font-size: 10pt;
        }
        .project-info td:first-child {
            width: 220px;
            font-weight: bold;
        }
        .project-info td:nth-child(2) {
            width: 15px;
            text-align: center;
        }

        .rab-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 9.5pt;
        }
        .rab-table th, .rab-table td {
            border: 1px solid #000;
            padding: 4px 6px;
        }
        .rab-table th {
            background: #d9e2f3;
            font-weight: bold;
            text-align: center;
            vertical-align: middle;
        }
        .rab-table .text-end { text-align: right; }
        .rab-table .text-center { text-align: center; }

        .cat-header {
            background: #e8f0fe;
            font-weight: bold;
        }
        .cat-total {
            background: #f2f2f2;
            font-weight: bold;
        }
        .grand-total {
            background: #d9e2f3;
            font-weight: bold;
        }

        .toolbar {
            position: fixed;
            top: 10px;
            right: 20px;
            z-index: 1000;
            display: flex;
            gap: 8px;
        }
        .toolbar button {
            padding: 8px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .btn-print {
            background: #28a745;
            color: #fff;
        }
        .btn-print:hover { background: #218838; }
        .btn-close-preview {
            background: #6c757d;
            color: #fff;
        }
        .btn-close-preview:hover { background: #5a6268; }

        @media print {
            body {
                background: #fff;
                padding: 0;
            }
            .page-container {
                box-shadow: none;
                padding: 0;
                max-width: 100%;
            }
            .toolbar {
                display: none;
            }
            @page {
                size: A4 portrait;
                margin: 15mm 12mm;
            }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <button class="btn-print" onclick="window.print()">
        <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
            <path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2H5zM4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4V3zm1 5a2 2 0 1 1-4 0 2 2 0 0 1 4 0z"/>
        </svg>
        Cetak Laporan
    </button>
</div>

<div class="page-container">
    <div class="report-title">RENCANA ANGGARAN PELAKSANAAN (RAP)</div>
    
    <table class="project-info">
        <tr>
            <td>NAMA KEGIATAN</td>
            <td>:</td>
            <td><?= htmlspecialchars($project['activity_name'] ?? '-') ?></td>
        </tr>
        <tr>
            <td>PEKERJAAN</td>
            <td>:</td>
            <td><?= htmlspecialchars($project['work_description'] ?? '-') ?></td>
        </tr>
        <tr>
            <td>SUMBER DANA</td>
            <td>:</td>
            <td><?= htmlspecialchars($project['funding_source'] ?? '-') ?></td>
        </tr>
        <tr>
            <td>TAHUN ANGGARAN</td>
            <td>:</td>
            <td><?= htmlspecialchars($project['budget_year']) ?></td>
        </tr>
        <tr>
            <td>NO. dan TGL KONTRAK</td>
            <td>:</td>
            <td><?= htmlspecialchars(($project['contract_number'] ?? '-') . ' TANGGAL ' . ($project['contract_date'] ? formatDate($project['contract_date']) : '-')) ?></td>
        </tr>
        <tr>
            <td>PENYEDIA JASA</td>
            <td>:</td>
            <td><?= htmlspecialchars($project['service_provider'] ?? '-') ?></td>
        </tr>
        <tr>
            <td>KONS. PENGAWAS</td>
            <td>:</td>
            <td><?= htmlspecialchars($project['supervisor_consultant'] ?? '-') ?></td>
        </tr>
        <tr>
            <td>JANGKA WAKTU PELAKSANAAN</td>
            <td>:</td>
            <td><?= htmlspecialchars($project['duration_days'] ?? 0) ?> HARI</td>
        </tr>
        <tr>
            <td>WILAYAH</td>
            <td>:</td>
            <td><?= htmlspecialchars($regionName) ?></td>
        </tr>
    </table>

    <table class="rab-table">
        <thead>
            <tr>
                <th width="40">NO.</th>
                <th>URAIAN PEKERJAAN</th>
                <th width="80">SATUAN</th>
                <th width="100">VOLUME</th>
                <th width="150">HARGA SATUAN<br>(Rp)</th>
                <th width="180">JUMLAH HARGA<br>(Rp)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rapData as $catId => $data): 
                $cat = $data['category'];
                $subcats = $data['subcategories'];
                $catTotal = $data['total'];
                if (empty($subcats)) continue;
            ?>
            <!-- Category Header -->
            <tr class="cat-header">
                <td class="text-center"><?= htmlspecialchars($cat['code']) ?></td>
                <td colspan="5"><?= htmlspecialchars(strtoupper($cat['name'])) ?></td>
            </tr>
            
            <?php 
            $itemNum = 0;
            foreach ($subcats as $sub): 
                $itemNum++;
                $unitPrice = $sub['display_unit_price'];
                $totalPrice = $sub['display_total'];
            ?>
            <tr>
                <td class="text-center"><?= $itemNum ?></td>
                <td><?= htmlspecialchars($sub['name']) ?></td>
                <td class="text-center"><?= htmlspecialchars($sub['unit']) ?></td>
                <td class="text-end"><?= number_format($sub['display_volume'], 2, ',', '.') ?></td>
                <td class="text-end"><?= number_format($unitPrice, 2, ',', '.') ?></td>
                <td class="text-end"><?= number_format($totalPrice, 2, ',', '.') ?></td>
            </tr>
            <?php endforeach; ?>
            
            <!-- Category Total -->
            <tr class="cat-total">
                <td colspan="5" class="text-end">Jumlah Total <?= htmlspecialchars($cat['code']) ?></td>
                <td class="text-end"><?= number_format($catTotal, 2, ',', '.') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <!-- Grand Total -->
            <tr class="grand-total">
                <td colspan="5" class="text-end">JUMLAH TOTAL RAP</td>
                <td class="text-end"><?= number_format($grandTotal, 2, ',', '.') ?></td>
            </tr>
        </tfoot>
    </table>
</div>

</body>
</html>
