<?php
/**
 * Export Request Details to PDF (Print-Ready HTML)
 * PCC - Project Cost Control System
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();

$requestId = $_GET['id'] ?? null;
$includeReceipts = isset($_GET['include_receipts']) && $_GET['include_receipts'] === '1';

if (!$requestId) {
    die('ID Pengajuan wajib diisi');
}

// Fetch request details
$request = dbGetRow("
    SELECT req.*, p.name as project_name, p.id as project_id,
           u.full_name as created_by_name, u.role as created_by_role,
           ua.full_name as approved_by_name, ua.role as approved_by_role,
           upm.full_name as pm_approved_by_name, upm.role as pm_approved_by_role
    FROM requests req
    LEFT JOIN projects p ON req.project_id = p.id
    LEFT JOIN users u ON req.created_by = u.id
    LEFT JOIN users ua ON req.approved_by = ua.id
    LEFT JOIN users upm ON req.pm_approved_by = upm.id
    WHERE req.id = ?
", [$requestId]);

if (!$request) {
    die('Pengajuan tidak ditemukan');
}

$isNonRab = ($request['request_type'] ?? 'rab') === 'non_rab';
$isMixed = ($request['request_type'] ?? 'rab') === 'mixed';

// Check access - creator or users with requests.view and project access
$canView = false;
if (hasPermission('projects.edit')) {
    $canView = true;
} elseif ($request['created_by'] == getCurrentUserId()) {
    $canView = true;
} elseif (hasPermission('requests.view') && canAccessProject($request['project_id'])) {
    $canView = true;
}

if (!$canView) {
    die('Anda tidak memiliki akses ke pengajuan ini');
}

// Get items
$items = dbGetAll("
    SELECT reqi.*, rs.code, rs.name as subcategory_name
    FROM request_items reqi
    LEFT JOIN rab_subcategories rs ON reqi.subcategory_id = rs.id
    WHERE reqi.request_id = ?
    ORDER BY rs.code, reqi.item_name
", [$requestId]);

$totalAmount = 0;
foreach ($items as $it) {
    $totalAmount += floatval($it['total_price'] ?: ($it['quantity'] * $it['unit_price']));
}

// Fetch attachments if requested
$requestAttachments = [];
$actualAttachments = [];
if ($includeReceipts) {
    // Request attachments
    $requestAttachments = dbGetAll("SELECT * FROM request_attachments WHERE request_id = ? ORDER BY uploaded_at", [$requestId]);
    
    // Actualization attachments (all attachments linked to this request's actuals)
    $actualAttachments = dbGetAll("
        SELECT raa.* 
        FROM request_actual_attachments raa 
        JOIN request_actuals ra ON raa.request_actual_id = ra.id 
        WHERE ra.request_id = ? 
        ORDER BY raa.created_at ASC
    ", [$requestId]);
    
    // Fallback if no JOIN match but request_actuals exists
    if (empty($actualAttachments)) {
        $actualData = dbGetRow("SELECT id FROM request_actuals WHERE request_id = ?", [$requestId]);
        if ($actualData) {
            $actualAttachments = dbGetAll("SELECT * FROM request_actual_attachments WHERE request_actual_id = ? ORDER BY created_at", [$actualData['id']]);
        }
    }
}

$baseUrl = getBaseUrl();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Pengajuan - <?= htmlspecialchars($request['request_number']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 11pt;
            color: #000;
            background: #f5f5f5;
            padding: 20px;
        }

        .no-print-container {
            max-width: 210mm;
            margin: 0 auto 15px auto;
            text-align: right;
        }

        .btn-print {
            background: #0d6efd;
            color: #fff;
            border: none;
            padding: 8px 16px;
            font-size: 10pt;
            cursor: pointer;
            border-radius: 4px;
            font-weight: bold;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }
        .btn-print:hover {
            background: #0b5ed7;
        }

        .page-container {
            background: #fff;
            max-width: 210mm;
            margin: 0 auto;
            padding: 20mm 15mm;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .report-header {
            text-align: center;
            margin-bottom: 25px;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
        }
        .report-header h2 {
            font-size: 16pt;
            font-weight: bold;
            text-transform: uppercase;
        }
        .report-header p {
            font-size: 10pt;
            color: #555;
            margin-top: 5px;
        }

        .info-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 25px;
        }
        .info-table {
            width: 48%;
            border-collapse: collapse;
        }
        .info-table td {
            padding: 4px 0;
            vertical-align: top;
            font-size: 10pt;
        }
        .info-table td.label {
            width: 130px;
            font-weight: bold;
        }
        .info-table td.colon {
            width: 15px;
            text-align: center;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            margin-bottom: 25px;
            font-size: 10pt;
        }
        .items-table th, .items-table td {
            border: 1px solid #000;
            padding: 6px 8px;
            vertical-align: middle;
        }
        .items-table th {
            background: #f2f2f2;
            font-weight: bold;
            text-align: center;
        }
        .items-table td.text-end {
            text-align: right;
        }
        .items-table td.text-center {
            text-align: center;
        }
        
        .total-row {
            font-weight: bold;
            background: #f9f9f9;
        }

        .notes-section {
            margin-top: 15px;
            font-size: 10pt;
            border: 1px solid #ccc;
            padding: 10px;
            background: #fafafa;
            border-radius: 4px;
        }
        .notes-title {
            font-weight: bold;
            margin-bottom: 5px;
            text-decoration: underline;
        }

        /* Attachments Section Styling */
        .attachments-section {
            margin-top: 35px;
            border-top: 2px dashed #000;
            padding-top: 20px;
            page-break-before: always;
            break-before: page;
        }
        .attachments-title {
            font-size: 13pt;
            font-weight: bold;
            margin-bottom: 20px;
            text-transform: uppercase;
            text-align: center;
            border-bottom: 1px solid #ddd;
            padding-bottom: 8px;
        }
        .attachment-subgroup-title {
            font-size: 11pt;
            font-weight: bold;
            margin-top: 20px;
            margin-bottom: 15px;
            color: #111;
            border-left: 4px solid #0d6efd;
            padding-left: 8px;
        }
        .attachment-item {
            margin-bottom: 25px;
            text-align: center;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .attachment-img-wrapper {
            display: inline-block;
            max-width: 100%;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .attachment-image {
            max-width: 100%;
            max-height: 220mm;
            border: 1px solid #ccc;
            padding: 4px;
            border-radius: 4px;
            background: #fff;
            display: block;
            margin: 0 auto;
            object-fit: contain;
        }
        .attachment-label {
            font-size: 9.5pt;
            color: #444;
            margin-top: 6px;
            font-style: normal;
        }

        /* PDF Attachment Styles */
        .attachment-pdf-item {
            text-align: left;
            margin-bottom: 25px;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .attachment-pdf-header {
            font-size: 10pt;
            color: #222;
            padding: 6px 10px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px 4px 0 0;
            margin-bottom: 10px;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .attachment-pdf-meta {
            font-size: 9pt;
            color: #666;
            font-style: italic;
            margin-left: 6px;
        }
        .attachment-pdf-pages {
            width: 100%;
            text-align: center;
        }
        .attachment-pdf-page-wrapper {
            margin-bottom: 20px;
            page-break-inside: avoid;
            break-inside: avoid;
            text-align: center;
        }
        .attachment-pdf-page-badge {
            font-size: 8.5pt;
            color: #555;
            margin-bottom: 4px;
            text-align: right;
            font-family: sans-serif;
        }
        .attachment-pdf-page-img {
            max-width: 100%;
            max-height: 220mm;
            width: auto;
            height: auto;
            border: 1px solid #ccc;
            border-radius: 3px;
            background: #fff;
            display: block;
            margin: 0 auto;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            object-fit: contain;
        }
        .pdf-loading-placeholder {
            padding: 18px;
            background: #f8f9fa;
            border: 1px dashed #ced4da;
            border-radius: 4px;
            color: #6c757d;
            font-family: sans-serif;
            font-size: 9.5pt;
            text-align: center;
            margin: 10px 0;
        }
        .pdf-fallback-box {
            border: 1px solid #ffeeba;
            background: #fff3cd;
            color: #856404;
            padding: 12px 16px;
            border-radius: 4px;
            text-align: left;
            font-family: sans-serif;
            font-size: 9.5pt;
            margin: 10px 0;
        }
        .btn-fallback-download {
            display: inline-block;
            margin-top: 6px;
            color: #0d6efd;
            text-decoration: underline;
            font-weight: bold;
            font-size: 9pt;
        }

        /* Loading Spinner */
        .loading-spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(0,0,0,0.2);
            border-radius: 50%;
            border-top-color: #0d6efd;
            animation: spin 0.8s linear infinite;
            vertical-align: middle;
            margin-right: 5px;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Status Banner for PDF processing */
        .status-banner {
            padding: 8px 14px;
            border-radius: 4px;
            margin-bottom: 12px;
            font-family: sans-serif;
            font-size: 9.5pt;
            text-align: left;
        }
        .status-banner-info {
            background: #cff4fc;
            border: 1px solid #b6effb;
            color: #055160;
        }
        .status-banner-success {
            background: #d1e7dd;
            border: 1px solid #badbcc;
            color: #0f5132;
        }

        .signature-container {
            margin-top: 35px;
            page-break-inside: avoid;
        }
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            border: none;
        }
        .signature-table td {
            border: none;
            width: 33.33%;
            text-align: center;
            vertical-align: top;
            padding: 0 10px;
        }
        .signature-title {
            font-size: 10pt;
            font-weight: bold;
            margin-bottom: 2px;
        }
        .signature-role {
            font-size: 9pt;
            color: #444;
            margin-bottom: 60px;
        }
        .signature-name {
            font-size: 10pt;
            font-weight: bold;
            margin-bottom: 4px;
        }
        .signature-name u {
            text-decoration: underline;
        }
        .signature-date {
            font-size: 8.5pt;
            color: #555;
        }

        @media print {
            body {
                background: #fff;
                padding: 0;
            }
            .page-container {
                box-shadow: none;
                padding: 0;
                margin: 0;
                max-width: 100%;
            }
            .no-print {
                display: none !important;
            }
            .attachment-pdf-header {
                background: transparent;
                border-color: #999;
            }
            .attachment-pdf-page-img {
                box-shadow: none;
                border: 1px solid #aaa;
            }
        }

        @media print and (orientation: landscape) {
            .attachment-pdf-page-img, .attachment-image {
                max-height: 175mm;
            }
        }
        @media print and (orientation: portrait) {
            .attachment-pdf-page-img, .attachment-image {
                max-height: 240mm;
            }
        }
    </style>
</head>
<body>

<div class="no-print-container no-print">
    <div id="printStatusBanner" class="status-banner status-banner-info" style="display: none;">
        <span class="loading-spinner"></span> <span id="printStatusText">Menyiapkan lampiran nota dokumen...</span>
    </div>
    <div style="display: flex; justify-content: flex-end; gap: 8px; align-items: center;">
        <button onclick="window.print()" class="btn-print" id="btnPrintReport">
            Cetak Laporan PDF
        </button>
    </div>
</div>

<div class="page-container">
    <div class="report-header">
        <h2>Laporan Pengajuan Dana</h2>
        <p>PCC - Project Cost Control System</p>
    </div>

    <div class="info-section">
        <table class="info-table">
            <tr>
                <td class="label">No. Pengajuan</td>
                <td class="colon">:</td>
                <td><strong><?= htmlspecialchars($request['request_number']) ?></strong></td>
            </tr>
            <tr>
                <td class="label">Proyek</td>
                <td class="colon">:</td>
                <td><?= htmlspecialchars($request['project_name']) ?></td>
            </tr>
            <tr>
                <td class="label">Metode / Jenis</td>
                <td class="colon">:</td>
                <td>
                    <?= $isNonRab ? 'Biaya Lain-Lain (Non-RAB)' : ($isMixed ? 'RAB + Non-RAB' : 'Direct Cost (RAB/RAP)') ?>
                    <?php if (!$isNonRab): ?>
                        - <strong><?= strtoupper(htmlspecialchars($request['work_type'] ?? 'borongan')) ?></strong>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td class="label">Minggu Ke</td>
                <td class="colon">:</td>
                <td><?= htmlspecialchars($request['target_week'] ?? $request['week_number'] ?? '-') ?></td>
            </tr>
            <tr>
                <td class="label">Dibuat Oleh</td>
                <td class="colon">:</td>
                <td><?= htmlspecialchars($request['created_by_name']) ?></td>
            </tr>
            <tr>
                <td class="label">Waktu Pengajuan</td>
                <td class="colon">:</td>
                <td><?= formatDateTime($request['created_at'], true) ?></td>
            </tr>
        </table>
        
        <table class="info-table">
            <tr>
                <td class="label">Status</td>
                <td class="colon">:</td>
                <td>
                    <span style="font-weight: bold; text-transform: uppercase;">
                        <?= htmlspecialchars($request['status']) ?>
                    </span>
                </td>
            </tr>
            <?php if ($request['pm_approved_by_name']): ?>
            <tr>
                <td class="label">PM Reviewer</td>
                <td class="colon">:</td>
                <td><?= htmlspecialchars($request['pm_approved_by_name']) ?></td>
            </tr>
            <tr>
                <td class="label">Waktu Review PM</td>
                <td class="colon">:</td>
                <td><?= formatDateTime($request['pm_approved_at'], true) ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($request['approved_by_name']): ?>
            <tr>
                <td class="label">Final Approval</td>
                <td class="colon">:</td>
                <td><?= htmlspecialchars($request['approved_by_name']) ?></td>
            </tr>
            <tr>
                <td class="label">Waktu Final Approval</td>
                <td class="colon">:</td>
                <td><?= formatDateTime($request['approved_at'], true) ?></td>
            </tr>
            <?php endif; ?>
        </table>
    </div>

    <?php if ($request['description']): ?>
    <div class="notes-section" style="margin-bottom: 20px;">
        <div class="notes-title">Keterangan Pengajuan:</div>
        <p><?= nl2br(htmlspecialchars($request['description'])) ?></p>
    </div>
    <?php endif; ?>

    <?php if (($request['work_type'] ?? 'borongan') === 'harian'): ?>
    <!-- HARIAN ITEMS TABLE -->
    <table class="items-table">
        <thead>
            <tr>
                <th width="35">No</th>
                <th width="85">Kode</th>
                <th>Uraian Pekerjaan / Item</th>
                <th width="90" class="text-end">Vol. Pek.</th>
                <th width="85" class="text-center">Jml Tenaga</th>
                <th width="70" class="text-center">Durasi</th>
                <th width="110" class="text-end">Tarif Satuan</th>
                <th width="125" class="text-end">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $i = 1;
            foreach ($items as $item): 
            ?>
            <tr>
                <td class="text-center"><?= $i++ ?></td>
                <td><code><?= htmlspecialchars($item['code'] ?? ($item['item_code'] ?: '-')) ?></code></td>
                <td>
                    <strong><?= htmlspecialchars($item['item_name']) ?></strong>
                    <?php if (!empty($item['subcategory_name'])): ?>
                    <br><small style="color: #555;"><?= htmlspecialchars($item['code'] ? $item['code'] . ' - ' : '') ?><?= htmlspecialchars($item['subcategory_name']) ?></small>
                    <?php endif; ?>
                    <?php if ($item['notes']): ?>
                    <br><small style="color: #666; font-style: italic;">Note: <?= htmlspecialchars($item['notes']) ?></small>
                    <?php endif; ?>
                </td>
                <td class="text-end"><?= formatVolume($item['work_volume']) ?> <?= htmlspecialchars($item['work_unit'] ?: "m'") ?></td>
                <td class="text-center"><?= formatVolume($item['work_quantity']) ?> <?= htmlspecialchars($item['work_quantity_unit'] ?: 'orang') ?></td>
                <td class="text-center">
                    <?php if (($item['item_type'] ?? '') === 'material'): ?>
                    -
                    <?php else: ?>
                    <?= formatVolume($item['work_duration']) ?> <?= htmlspecialchars($item['work_duration_unit'] ?: 'Hr') ?>
                    <?php endif; ?>
                </td>
                <td class="text-end"><?= number_format($item['unit_price'], 2, ',', '.') ?><br><small style="color: #555;">/ <?= htmlspecialchars($item['unit'] ?: ($item['work_billing_unit'] ?: 'OH')) ?></small></td>
                <td class="text-end"><strong><?= number_format($item['total_price'] ?: ($item['quantity'] * $item['unit_price']), 2, ',', '.') ?></strong></td>
            </tr>
            <?php endforeach; ?>
            
            <tr class="total-row">
                <td colspan="7" class="text-end">TOTAL PENGAJUAN</td>
                <td class="text-end"><?= number_format($totalAmount, 2, ',', '.') ?></td>
            </tr>
        </tbody>
    </table>
    <?php else: ?>
    <!-- BORONGAN ITEMS TABLE (EXISTING) -->
    <table class="items-table">
        <thead>
            <tr>
                <th width="40">No</th>
                <th width="100">Kode</th>
                <th>Uraian Pekerjaan / Item</th>
                <th width="60">Satuan</th>
                <th width="100" class="text-end">Volume</th>
                <th width="120" class="text-end">Harga Satuan</th>
                <th width="140" class="text-end">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $i = 1;
            foreach ($items as $item): 
            ?>
            <tr>
                <td class="text-center"><?= $i++ ?></td>
                <td><code><?= htmlspecialchars($item['code'] ?? ($item['item_code'] ?: '-')) ?></code></td>
                <td>
                    <?= htmlspecialchars($item['item_name']) ?>
                    <?php if (!empty($item['subcategory_name'])): ?>
                    <br><small style="color: #555;"><?= htmlspecialchars($item['code'] ? $item['code'] . ' - ' : '') ?><?= htmlspecialchars($item['subcategory_name']) ?></small>
                    <?php endif; ?>
                    <?php if ($item['notes']): ?>
                    <br><small style="color: #666; font-style: italic;">Note: <?= htmlspecialchars($item['notes']) ?></small>
                    <?php endif; ?>
                </td>
                <td class="text-center"><?= htmlspecialchars($item['unit'] ?: 'ls') ?></td>
                <td class="text-end"><?= formatVolume($item['coefficient'] ?: $item['quantity']) ?></td>
                <td class="text-end"><?= number_format($item['unit_price'], 2, ',', '.') ?></td>
                <td class="text-end"><?= number_format($item['total_price'] ?: ($item['quantity'] * $item['unit_price']), 2, ',', '.') ?></td>
            </tr>
            <?php endforeach; ?>
            
            <tr class="total-row">
                <td colspan="6" class="text-end">TOTAL PENGAJUAN</td>
                <td class="text-end"><?= number_format($totalAmount, 2, ',', '.') ?></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if ($request['pm_notes'] || $request['admin_notes'] || $request['rejection_reason']): ?>
    <div class="notes-section">
        <div class="notes-title">Catatan Proses Approval:</div>
        <?php if ($request['pm_notes']): ?>
            <p><strong>Catatan PM:</strong> <?= htmlspecialchars($request['pm_notes']) ?></p>
        <?php endif; ?>
        <?php if ($request['admin_notes']): ?>
            <p><strong>Catatan Admin:</strong> <?= htmlspecialchars($request['admin_notes']) ?></p>
        <?php endif; ?>
        <?php if ($request['rejection_reason']): ?>
            <p style="color: red;"><strong>Alasan Penolakan:</strong> <?= htmlspecialchars($request['rejection_reason']) ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Section Tanda Tangan -->
    <div class="signature-container">
        <table class="signature-table">
            <tr>
                <!-- 1. Diajukan Oleh (Pengaju) -->
                <td>
                    <div class="signature-title">Diajukan Oleh,</div>
                    <div class="signature-role">Pemohon / Tim Proyek</div>
                    <div class="signature-name">
                        <?php if (!empty($request['created_by_name'])): ?>
                            <u><?= htmlspecialchars($request['created_by_name']) ?></u>
                        <?php else: ?>
                            ( ........................................ )
                        <?php endif; ?>
                    </div>
                    <div class="signature-date">
                        Tgl: <?= !empty($request['created_at']) ? formatDate($request['created_at']) : '........................' ?>
                    </div>
                </td>

                <!-- 2. Finance / Keuangan -->
                <td>
                    <div class="signature-title">Diperiksa Oleh,</div>
                    <div class="signature-role">Finance / Keuangan</div>
                    <div class="signature-name">
                        ( ........................................ )
                    </div>
                    <div class="signature-date">
                        Tgl: ................................
                    </div>
                </td>

                <!-- 3. Disetujui Oleh (Approver) -->
                <td>
                    <div class="signature-title">Disetujui Oleh,</div>
                    <div class="signature-role">
                        <?php 
                        if (!empty($request['approved_by_name'])) {
                            echo !empty($request['approved_by_role']) ? htmlspecialchars(getRoleDisplayName($request['approved_by_role'])) : 'Admin / Manajemen';
                        } elseif (!empty($request['pm_approved_by_name'])) {
                            echo 'Project Manager';
                        } else {
                            echo 'Project Manager / Admin';
                        }
                        ?>
                    </div>
                    <div class="signature-name">
                        <?php if (!empty($request['approved_by_name'])): ?>
                            <u><?= htmlspecialchars($request['approved_by_name']) ?></u>
                            <?php if (!empty($request['pm_approved_by_name']) && $request['approved_by_name'] !== $request['pm_approved_by_name']): ?>
                                <div style="font-size: 8pt; font-weight: normal; color: #555; text-decoration: none; margin-top: 2px;">(Review: <?= htmlspecialchars($request['pm_approved_by_name']) ?>)</div>
                            <?php endif; ?>
                        <?php elseif (!empty($request['pm_approved_by_name'])): ?>
                            <u><?= htmlspecialchars($request['pm_approved_by_name']) ?></u>
                        <?php else: ?>
                            ( ........................................ )
                        <?php endif; ?>
                    </div>
                    <div class="signature-date">
                        <?php if (!empty($request['approved_at'])): ?>
                            Tgl: <?= formatDate($request['approved_at']) ?>
                        <?php elseif (!empty($request['pm_approved_at'])): ?>
                            Tgl: <?= formatDate($request['pm_approved_at']) ?>
                        <?php else: ?>
                            Tgl: ................................
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <?php
    /**
     * Helper to render an attachment item in the print view
     */
    function renderAttachmentBlock($att, $folder = 'actuals') {
        $filename = $att['filename'] ?? '';
        $origName = !empty($att['original_name']) ? $att['original_name'] : $filename;
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $origExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $fileType = strtolower($att['file_type'] ?? '');
        
        $isPdf = ($fileType === 'application/pdf' || $ext === 'pdf' || $origExt === 'pdf');
        $isImg = in_array($fileType, ['image/jpeg', 'image/png', 'image/jpg', 'image/webp']) 
                 || in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']) 
                 || in_array($origExt, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
        
        $fileUrl = "../../uploads/{$folder}/" . rawurlencode($filename);
        $filePath = __DIR__ . "/../../uploads/{$folder}/" . $filename;
        $base64Data = '';
        if ($isPdf && !empty($filename) && file_exists($filePath) && filesize($filePath) <= 15 * 1024 * 1024) {
            $base64Data = base64_encode(file_get_contents($filePath));
        }
        ?>
        <div class="attachment-item <?= $isPdf ? 'attachment-pdf-item' : ($isImg ? 'attachment-img-item' : 'attachment-other-item') ?>"
             <?= $isPdf ? 'data-pdf-url="' . htmlspecialchars($fileUrl) . '" data-title="' . htmlspecialchars($origName) . '"' : '' ?>
             <?= (!empty($base64Data)) ? 'data-pdf-base64="' . $base64Data . '"' : '' ?>>
            
            <?php if ($isPdf): ?>
                <div class="attachment-pdf-header">
                    <span style="font-size: 11pt; margin-right: 4px;">📄</span>
                    <strong><?= htmlspecialchars($origName) ?></strong>
                    <span class="attachment-pdf-meta">(Dokumen PDF - <span class="pdf-status-text">Menyiapkan halaman...</span>)</span>
                </div>
                <div class="attachment-pdf-pages">
                    <div class="pdf-loading-placeholder no-print">
                        <span class="loading-spinner"></span> Menyiapkan halaman PDF agar ikut tercetak...
                    </div>
                </div>
            <?php elseif ($isImg): ?>
                <div class="attachment-img-wrapper">
                    <img src="<?= htmlspecialchars($fileUrl) ?>" class="attachment-image" alt="<?= htmlspecialchars($origName) ?>">
                    <div class="attachment-label">
                        📷 <strong><?= htmlspecialchars($origName) ?></strong>
                        <?php if (!empty($att['file_size'])): ?>
                            <small style="color: #777;">(<?= round($att['file_size'] / 1024, 1) ?> KB)</small>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div style="border: 1px solid #ccc; padding: 12px; background: #fafafa; display: inline-block; border-radius: 4px; text-align: left;">
                    📎 <strong><?= htmlspecialchars($origName) ?></strong> (Berkas Lampiran)
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    ?>

    <?php if ($includeReceipts && (!empty($requestAttachments) || !empty($actualAttachments))): ?>
    <div class="attachments-section">
        <div class="attachments-title">Lampiran Nota & Bukti Dukung</div>
        
        <?php if (!empty($requestAttachments)): ?>
        <div class="attachment-subgroup-title">Lampiran saat Pengajuan:</div>
        <?php foreach ($requestAttachments as $att): ?>
            <?php renderAttachmentBlock($att, 'receipts'); ?>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($actualAttachments)): ?>
        <div class="attachment-subgroup-title">Lampiran Realisasi (Nota Aktual):</div>
        <?php foreach ($actualAttachments as $att): ?>
            <?php renderAttachmentBlock($att, 'actuals'); ?>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- PDF.js library for rendering PDF attachments directly into print preview -->
<script src="../../dist/assets/libs/pdfjs/pdf.min.js"></script>
<script>
    if (typeof pdfjsLib === 'undefined') {
        document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"><\/script>');
    }
</script>
<script>
    if (typeof pdfjsLib !== 'undefined') {
        try {
            pdfjsLib.GlobalWorkerOptions.workerSrc = '../../dist/assets/libs/pdfjs/pdf.worker.min.js';
        } catch (e) {
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        }
    }

    async function renderPdfAttachment(item) {
        const pdfBase64 = item.getAttribute('data-pdf-base64');
        const pdfUrl = item.getAttribute('data-pdf-url');
        const docTitle = item.getAttribute('data-title') || 'Dokumen PDF';
        const pagesContainer = item.querySelector('.attachment-pdf-pages');
        const statusSpan = item.querySelector('.pdf-status-text');
        
        if ((!pdfUrl && !pdfBase64) || !pagesContainer) return;
        
        try {
            if (typeof pdfjsLib === 'undefined') {
                throw new Error('Library PDF.js belum tersedia');
            }
            
            let loadingTask;
            if (pdfBase64 && pdfBase64.length > 0) {
                const binaryStr = atob(pdfBase64);
                const bytes = new Uint8Array(binaryStr.length);
                for (let i = 0; i < binaryStr.length; i++) {
                    bytes[i] = binaryStr.charCodeAt(i);
                }
                loadingTask = pdfjsLib.getDocument({
                    data: bytes,
                    cMapUrl: 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/cmaps/',
                    cMapPacked: true
                });
            } else {
                const encodedUrl = encodeURI(decodeURI(pdfUrl));
                loadingTask = pdfjsLib.getDocument({
                    url: encodedUrl,
                    cMapUrl: 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/cmaps/',
                    cMapPacked: true
                });
            }
            
            const pdf = await loadingTask.promise;
            const totalPages = pdf.numPages;
            
            if (statusSpan) {
                statusSpan.textContent = totalPages + ' Halaman';
            }
            
            pagesContainer.innerHTML = '';
            
            for (let pageNum = 1; pageNum <= totalPages; pageNum++) {
                const page = await pdf.getPage(pageNum);
                
                // High resolution scaling (2.0) for crisp printing
                const scale = 2.0;
                const viewport = page.getViewport({ scale: scale });
                
                const canvas = document.createElement('canvas');
                canvas.width = viewport.width;
                canvas.height = viewport.height;
                const ctx = canvas.getContext('2d');
                
                await page.render({
                    canvasContext: ctx,
                    viewport: viewport
                }).promise;
                
                // Convert canvas to image for rock-solid print fidelity
                const img = document.createElement('img');
                img.src = canvas.toDataURL('image/jpeg', 0.95);
                img.className = 'attachment-pdf-page-img';
                img.alt = `${docTitle} - Hal ${pageNum}`;
                
                const pageWrapper = document.createElement('div');
                pageWrapper.className = 'attachment-pdf-page-wrapper';
                
                if (totalPages > 1) {
                    const badge = document.createElement('div');
                    badge.className = 'attachment-pdf-page-badge';
                    badge.innerHTML = `<small>📄 ${docTitle} &bull; <strong>Halaman ${pageNum} dari ${totalPages}</strong></small>`;
                    pageWrapper.appendChild(badge);
                }
                
                pageWrapper.appendChild(img);
                pagesContainer.appendChild(pageWrapper);
            }
        } catch (err) {
            console.error('Gagal memuat PDF:', pdfUrl, err);
            if (statusSpan) {
                statusSpan.textContent = 'Gagal memuat otomatis';
            }
            pagesContainer.innerHTML = `
                <div class="pdf-fallback-box">
                    <div style="font-weight: bold; margin-bottom: 3px;">⚠️ Dokumen PDF tidak dapat dimuat otomatis pada cetakan.</div>
                    <div style="font-size: 8.5pt; color: #555; margin-bottom: 6px;">Berkas: ${docTitle}</div>
                    <a href="${pdfUrl}" target="_blank" class="no-print btn-fallback-download">
                        📥 Klik di sini untuk membuka / mengunduh berkas
                    </a>
                </div>
            `;
        }
    }

    window.addEventListener('DOMContentLoaded', async () => {
        const pdfItems = document.querySelectorAll('.attachment-pdf-item');
        const statusBanner = document.getElementById('printStatusBanner');
        const statusText = document.getElementById('printStatusText');
        const btnPrint = document.getElementById('btnPrintReport');
        
        if (pdfItems.length > 0) {
            if (statusBanner) statusBanner.style.display = 'block';
            if (btnPrint) {
                btnPrint.disabled = true;
                btnPrint.style.opacity = '0.7';
                btnPrint.innerHTML = '<span class="loading-spinner"></span> Menyiapkan Nota PDF...';
            }
            
            let count = 0;
            for (const item of pdfItems) {
                count++;
                const title = item.getAttribute('data-title') || 'Lampiran';
                if (statusText) {
                    statusText.innerHTML = `Menyiapkan halaman nota lampiran (${count}/${pdfItems.length}): <strong>${title}</strong>...`;
                }
                await renderPdfAttachment(item);
            }
            
            if (statusBanner) {
                statusBanner.className = 'status-banner status-banner-success';
                statusBanner.innerHTML = '✓ Seluruh lampiran nota PDF berhasil disiapkan dan terpasang pada laporan.';
                setTimeout(() => {
                    statusBanner.style.display = 'none';
                }, 3500);
            }
            
            if (btnPrint) {
                btnPrint.disabled = false;
                btnPrint.style.opacity = '1';
                btnPrint.innerHTML = 'Cetak Laporan PDF';
            }
        }
        
        // Wait for all images (including newly rendered canvas images) to load
        const allImages = Array.from(document.images);
        await Promise.all(allImages.map(img => {
            if (img.complete) return Promise.resolve();
            return new Promise(resolve => {
                img.onload = resolve;
                img.onerror = resolve;
            });
        }));
        
        // Buffer to ensure DOM paint before opening print preview
        setTimeout(() => {
            window.print();
        }, 500);
    });
</script>
</body>
</html>
