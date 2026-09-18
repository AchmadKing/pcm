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

$totalAmount = array_sum(array_column($items, 'total_price'));

// Fetch attachments if requested
$requestAttachments = [];
$actualAttachments = [];
if ($includeReceipts) {
    // Request attachments
    $requestAttachments = dbGetAll("SELECT * FROM request_attachments WHERE request_id = ? ORDER BY uploaded_at", [$requestId]);
    
    // Actualization attachments
    $actualData = dbGetRow("SELECT id FROM request_actuals WHERE request_id = ?", [$requestId]);
    if ($actualData) {
        $actualAttachments = dbGetAll("SELECT * FROM request_actual_attachments WHERE request_actual_id = ? ORDER BY created_at", [$actualData['id']]);
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

        .attachments-section {
            margin-top: 40px;
            border-top: 1px dashed #000;
            padding-top: 20px;
            page-break-before: always;
        }
        .attachments-title {
            font-size: 12pt;
            font-weight: bold;
            margin-bottom: 15px;
            text-transform: uppercase;
        }
        .attachment-item {
            margin-bottom: 20px;
            text-align: center;
        }
        .attachment-image {
            max-width: 100%;
            max-height: 200mm;
            border: 1px solid #ccc;
            padding: 5px;
            border-radius: 4px;
        }
        .attachment-label {
            font-size: 9pt;
            color: #666;
            margin-top: 5px;
            font-style: italic;
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
        }
    </style>
</head>
<body>

<div class="no-print-container no-print">
    <button onclick="window.print()" class="btn-print">
        Cetak Laporan PDF
    </button>
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
                <td><code><?= htmlspecialchars($item['code'] ?? '-') ?></code></td>
                <td>
                    <?= htmlspecialchars($item['item_name']) ?>
                    <?php if ($item['notes']): ?>
                    <br><small style="color: #666; font-style: italic;">Note: <?= htmlspecialchars($item['notes']) ?></small>
                    <?php endif; ?>
                </td>
                <td class="text-center"><?= htmlspecialchars($item['unit']) ?></td>
                <td class="text-end"><?= number_format($item['coefficient'], 4, ',', '.') ?></td>
                <td class="text-end"><?= number_format($item['unit_price'], 2, ',', '.') ?></td>
                <td class="text-end"><?= number_format($item['total_price'], 2, ',', '.') ?></td>
            </tr>
            <?php endforeach; ?>
            
            <tr class="total-row">
                <td colspan="6" class="text-end">TOTAL PENGAJUAN</td>
                <td class="text-end"><?= number_format($totalAmount, 2, ',', '.') ?></td>
            </tr>
        </tbody>
    </table>

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

    <?php if ($includeReceipts && (!empty($requestAttachments) || !empty($actualAttachments))): ?>
    <div class="attachments-section no-print-section">
        <div class="attachments-title">Lampiran Nota & Bukti Dukung</div>
        
        <?php if (!empty($requestAttachments)): ?>
        <h4 style="font-size: 11pt; font-weight: bold; margin-bottom: 10px;">Lampiran saat Pengajuan:</h4>
        <?php foreach ($requestAttachments as $att): ?>
            <div class="attachment-item">
                <?php if (in_array($att['file_type'], ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'])): ?>
                    <img src="../../uploads/receipts/<?= $att['filename'] ?>" class="attachment-image" alt="<?= htmlspecialchars($att['original_name']) ?>">
                    <div class="attachment-label"><?= htmlspecialchars($att['original_name']) ?></div>
                <?php else: ?>
                    <div style="border: 1px solid #ccc; padding: 15px; background: #fdfdfd; display: inline-block; border-radius: 4px;">
                        📄 <strong><?= htmlspecialchars($att['original_name']) ?></strong> (Dokumen PDF)
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($actualAttachments)): ?>
        <h4 style="font-size: 11pt; font-weight: bold; margin-top: 25px; margin-bottom: 10px;">Lampiran Realisasi (Nota Aktual):</h4>
        <?php foreach ($actualAttachments as $att): ?>
            <div class="attachment-item">
                <?php if (in_array($att['file_type'], ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'])): ?>
                    <img src="../../uploads/actuals/<?= $att['filename'] ?>" class="attachment-image" alt="<?= htmlspecialchars($att['original_name']) ?>">
                    <div class="attachment-label"><?= htmlspecialchars($att['original_name']) ?></div>
                <?php else: ?>
                    <div style="border: 1px solid #ccc; padding: 15px; background: #fdfdfd; display: inline-block; border-radius: 4px;">
                        📄 <strong><?= htmlspecialchars($att['original_name']) ?></strong> (Dokumen PDF)
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
    window.addEventListener('DOMContentLoaded', (event) => {
        // Trigger browser print automatically
        setTimeout(function() {
            window.print();
        }, 500);
    });
</script>
</body>
</html>
