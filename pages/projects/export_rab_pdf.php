<?php
/**
 * Export RAB to PDF (Print-Ready HTML)
 * PCM - Project Cost Management System
 * Standalone HTML page for browser print-to-PDF
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();

$projectId = $_GET['id'] ?? null;

if (!$projectId) {
    die('Project ID required');
}

$project = dbGetRow("SELECT * FROM projects WHERE id = ?", [$projectId]);
if (!$project) {
    die('Proyek tidak ditemukan');
}

$overheadPct = $project['overhead_percentage'] ?? 10;
$regionName = $project['region_name'] ?? '-';
$ppnPercentage = $project['ppn_percentage'] ?? 11;

// Get RAB data with AHSP codes
$rabItems = dbGetAll("
    SELECT 
        rs.volume,
        rs.unit_price,
        rs.code as sub_code, rs.name as sub_name, rs.unit,
        rc.code as cat_code, rc.name as cat_name,
        pa.ahsp_code
    FROM rab_subcategories rs
    JOIN rab_categories rc ON rs.category_id = rc.id
    LEFT JOIN project_ahsp pa ON rs.ahsp_id = pa.id
    WHERE rc.project_id = ?
    ORDER BY rc.sort_order, LENGTH(rc.code), rc.code, rs.sort_order, LENGTH(rs.code), rs.code
", [$projectId]);

// Function to get AHSP component breakdown
function getAhspBreakdownForPdf($ahspId) {
    $result = ['upah' => 0, 'material' => 0, 'alat' => 0, 'total' => 0];
    if (!$ahspId) return $result;
    
    $totals = dbGetAll("
        SELECT i.category, SUM(d.coefficient * COALESCE(d.unit_price, i.price)) as total 
        FROM project_ahsp_details d 
        JOIN project_items i ON d.item_id = i.id 
        WHERE d.ahsp_id = ?
        GROUP BY i.category
    ", [$ahspId]);
    
    foreach ($totals as $row) {
        if (isset($result[$row['category']])) {
            $result[$row['category']] = $row['total'];
        }
        $result['total'] += $row['total'];
    }
    return $result;
}

ensureRabHeadSubsTableExists();

// Get Head-Subs and Categories for PDF
$headSubs = dbGetAll("SELECT * FROM rab_head_subs WHERE project_id = ? ORDER BY sort_order, id", [$projectId]);
$categories = dbGetAll("SELECT * FROM rab_categories WHERE project_id = ? ORDER BY sort_order, LENGTH(code), code, id", [$projectId]);

$headSubMap = [];
foreach ($headSubs as $hs) {
    $headSubMap[$hs['id']] = [
        'head_sub' => $hs,
        'categories' => [],
        'total' => 0
    ];
}

$standaloneCats = [];
$grandTotal = 0;

foreach ($categories as $cat) {
    $subcats = dbGetAll("SELECT * FROM rab_subcategories WHERE category_id = ? ORDER BY sort_order, code", [$cat['id']]);
    $catTotal = 0;
    $enrichedSubcats = [];
    
    foreach ($subcats as $sub) {
        $components = getAhspBreakdownForPdf($sub['ahsp_id']);
        $baseUnitPrice = $components['total'];
        $unitPriceWithOverhead = $baseUnitPrice * (1 + ($overheadPct / 100));
        $sub['unit_price_display'] = $unitPriceWithOverhead;
        $subTotal = $sub['volume'] * $unitPriceWithOverhead;
        $catTotal += $subTotal;
        
        $enrichedSubcats[] = $sub;
    }
    
    $grandTotal += $catTotal;
    $catData = [
        'category' => $cat,
        'subcategories' => $enrichedSubcats,
        'total' => $catTotal
    ];

    if (!empty($cat['head_sub_id']) && isset($headSubMap[$cat['head_sub_id']])) {
        $hsId = $cat['head_sub_id'];
        $headSubMap[$hsId]['categories'][$cat['id']] = $catData;
        $headSubMap[$hsId]['total'] += $catTotal;
    } else {
        $standaloneCats[$cat['id']] = $catData;
    }
}


$ppnAmount = $grandTotal * ($ppnPercentage / 100);
$totalWithPpn = $grandTotal + $ppnAmount;
$totalRounded = ceil($totalWithPpn / 10) * 10;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan RAB - <?= htmlspecialchars($project['name']) ?></title>
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
        .ppn-row td, .final-row td {
            font-weight: bold;
        }
        .final-total {
            background: #bdd7ee;
            font-weight: bold;
            font-size: 10pt;
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
                margin: 0;
            }
            .page-container {
                box-shadow: none;
                padding: 5mm 8mm;
                max-width: 100%;
            }
            .toolbar { display: none !important; }
            
            @page {
                size: A4 landscape;
                margin: 10mm;
            }
            
            .rab-table { page-break-inside: auto; }
            .rab-table tr { page-break-inside: avoid; }
            .rab-table thead { display: table-header-group; }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <button class="btn-print" onclick="window.print()">
        🖨️ Cetak / Export PDF
    </button>
    <button class="btn-close-preview" onclick="window.close(); if(window.parent && window.parent !== window) window.parent.document.getElementById('pdfPreviewModal') && bootstrap.Modal.getInstance(window.parent.document.getElementById('pdfPreviewModal')).hide();">
        ✕ Tutup
    </button>
</div>

<div class="page-container">
    <div class="report-title">RENCANA ANGGARAN BIAYA (RAB)</div>

    <table class="project-info">
        <tr><td>NAMA KEGIATAN</td><td>:</td><td><?= htmlspecialchars($project['activity_name'] ?? '-') ?></td></tr>
        <tr><td>PEKERJAAN</td><td>:</td><td><?= htmlspecialchars($project['work_description'] ?? '-') ?></td></tr>
        <tr><td>SUMBER DANA</td><td>:</td><td><?= htmlspecialchars($project['funding_source'] ?? '-') ?></td></tr>
        <tr><td>TAHUN ANGGARAN</td><td>:</td><td><?= htmlspecialchars($project['budget_year'] ?? '-') ?></td></tr>
        <tr><td>NO. dan TGL KONTRAK</td><td>:</td><td><?= htmlspecialchars($project['contract_number'] ?? '-') ?> TANGGAL <?= $project['contract_date'] ? formatDate($project['contract_date']) : '-' ?></td></tr>
        <tr><td>PENYEDIA JASA</td><td>:</td><td><?= htmlspecialchars($project['service_provider'] ?? '-') ?></td></tr>
        <tr><td>KONS. PENGAWAS</td><td>:</td><td><?= htmlspecialchars($project['supervisor_consultant'] ?? '-') ?></td></tr>
        <tr><td>JANGKA WAKTU PELAKSANAAN</td><td>:</td><td><?= ($project['duration_days'] ?? 0) ?> HARI</td></tr>
        <tr><td>WILAYAH</td><td>:</td><td><?= htmlspecialchars($regionName) ?></td></tr>
    </table>

    <table class="rab-table">
        <thead>
            <tr>
                <th width="50">No</th>
                <th>URAIAN PEKERJAAN</th>
                <th width="65">SAT</th>
                <th width="80">VOLUME</th>
                <th width="140">HARGA SATUAN (Rp)</th>
                <th width="150">JUMLAH HARGA (Rp)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            // Render Head-Sub Groups
            foreach ($headSubMap as $hsId => $hsGroup):
                $hs = $hsGroup['head_sub'];
                $hsCats = $hsGroup['categories'];
            ?>
            <tr style="background:#2c3e50; color:#ffffff; font-weight:bold;">
                <td colspan="6" style="padding:6px 8px;">
                    <?= !empty($hs['code']) ? htmlspecialchars($hs['code']) . ' - ' : '' ?><?= strtoupper(htmlspecialchars($hs['name'])) ?>
                </td>
            </tr>

            <?php foreach ($hsCats as $catId => $data):
                $cat = $data['category'];
                $subcats = $data['subcategories'];
                $catTotal = $data['total'];
            ?>
            <tr class="cat-header">
                <td><?= htmlspecialchars($cat['code']) ?></td>
                <td colspan="5"><?= strtoupper(htmlspecialchars($cat['name'])) ?></td>
            </tr>
            
            <?php $itemNum = 0; foreach ($subcats as $sub): $itemNum++; 
                $unitPrice = $sub['unit_price_display'];
                $totalPrice = $sub['volume'] * $unitPrice;
            ?>
            <tr>
                <td class="text-center"><?= $itemNum ?></td>
                <td><?= htmlspecialchars($sub['name']) ?></td>
                <td class="text-center"><?= htmlspecialchars($sub['unit']) ?></td>
                <td class="text-end"><?= number_format($sub['volume'], 2, ',', '.') ?></td>
                <td class="text-end"><?= number_format($unitPrice, 2, ',', '.') ?></td>
                <td class="text-end"><?= number_format($totalPrice, 2, ',', '.') ?></td>
            </tr>
            <?php endforeach; ?>
            
            <tr class="cat-total">
                <td colspan="5" class="text-end">Jumlah Total <?= htmlspecialchars($cat['code']) ?></td>
                <td class="text-end"><?= number_format($catTotal, 2, ',', '.') ?></td>
            </tr>
            <?php endforeach; ?>

            <tr style="background:#d1ecf1; font-weight:bold;">
                <td colspan="5" class="text-end">JUMLAH <?= !empty($hs['code']) ? htmlspecialchars($hs['code']) : htmlspecialchars($hs['name']) ?></td>
                <td class="text-end"><?= number_format($hsGroup['total'], 2, ',', '.') ?></td>
            </tr>
            <?php endforeach; ?>

            <!-- Render Standalone Categories -->
            <?php if (!empty($standaloneCats)): ?>
                <?php if (!empty($headSubs)): ?>
                <tr style="background:#4a6572; color:#ffffff; font-weight:bold;">
                    <td colspan="6" style="padding:6px 8px;">KATEGORI TANPA HEAD-SUB</td>
                </tr>
                <?php endif; ?>

                <?php foreach ($standaloneCats as $catId => $data):
                    $cat = $data['category'];
                    $subcats = $data['subcategories'];
                    $catTotal = $data['total'];
                ?>
                <tr class="cat-header">
                    <td><?= htmlspecialchars($cat['code']) ?></td>
                    <td colspan="5"><?= strtoupper(htmlspecialchars($cat['name'])) ?></td>
                </tr>
                
                <?php $itemNum = 0; foreach ($subcats as $sub): $itemNum++; 
                    $unitPrice = $sub['unit_price_display'];
                    $totalPrice = $sub['volume'] * $unitPrice;
                ?>
                <tr>
                    <td class="text-center"><?= $itemNum ?></td>
                    <td><?= htmlspecialchars($sub['name']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($sub['unit']) ?></td>
                    <td class="text-end"><?= number_format($sub['volume'], 2, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($unitPrice, 2, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($totalPrice, 2, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
                
                <tr class="cat-total">
                    <td colspan="5" class="text-end">Jumlah Total <?= htmlspecialchars($cat['code']) ?></td>
                    <td class="text-end"><?= number_format($catTotal, 2, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>

        </tbody>
        <tfoot>
            <!-- Grand Total -->
            <tr class="grand-total">
                <td colspan="5" class="text-end">JUMLAH TOTAL</td>
                <td class="text-end"><?= number_format($grandTotal, 2, ',', '.') ?></td>
            </tr>
            <!-- PPN -->
            <tr class="ppn-row">
                <td colspan="5" class="text-end">PPN <?= number_format($ppnPercentage, 2, ',', '.') ?>%</td>
                <td class="text-end"><?= number_format($ppnAmount, 2, ',', '.') ?></td>
            </tr>
            <!-- Total with PPN -->
            <tr class="ppn-row">
                <td colspan="5" class="text-end">JUMLAH TOTAL (TERMASUK PPN)</td>
                <td class="text-end"><?= number_format($totalWithPpn, 2, ',', '.') ?></td>
            </tr>
            <!-- Rounded Total -->
            <tr class="final-total">
                <td colspan="5" class="text-end">JUMLAH TOTAL DIBULATKAN</td>
                <td class="text-end"><?= number_format($totalRounded, 0, ',', '.') ?></td>
            </tr>
        </tfoot>
    </table>
</div>

</body>
</html>
