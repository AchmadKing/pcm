<?php
/**
 * Export RAP to PDF (Print-Ready HTML with Edit Print)
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

// Determine RAB source for optional comparison column (Selisih RAB)
$storedSourceId = intval($project['rap_source_id'] ?? 0);
$rabSourceId = isset($_GET['rab_source']) ? intval($_GET['rab_source']) : $storedSourceId;
$usingSnapshot = false;
$selectedSnapshot = null;

if ($rabSourceId > 0) {
    $selectedSnapshot = dbGetRow("SELECT * FROM rab_snapshots WHERE id = ? AND project_id = ?", [$rabSourceId, $projectId]);
    if ($selectedSnapshot) {
        $usingSnapshot = true;
    } else {
        $rabSourceId = 0;
    }
}

$overheadPct = getProjectOverheadProfitPct($project, 'rap');
$rabOverheadPct = ($usingSnapshot && $selectedSnapshot)
    ? getProjectOverheadProfitPct($selectedSnapshot, 'rab')
    : getProjectOverheadProfitPct($project, 'rab');
$regionName = $project['region_name'] ?? '-';
$ppnPercentage = floatval($project['ppn_percentage'] ?? 0);

// Batch-load AHSP component breakdowns & dynamic RAP item breakdowns
$rapItemBreakdowns = batchGetRapItemBreakdowns($projectId);
$rabAhspBreakdownMap = batchGetAhspComponentBreakdowns($projectId);

ensureRabHeadSubsTableExists();

// Get Head-Subs and Categories (same ordering as tabs/rap.php)
$headSubs = dbGetAll("SELECT * FROM rab_head_subs WHERE project_id = ? ORDER BY sort_order, id", [$projectId]);
$categories = dbGetAll("SELECT * FROM rab_categories WHERE project_id = ? ORDER BY sort_order, LENGTH(code), code, id", [$projectId]);

// Preload snapshot subcategories if using snapshot
$snapSubcatMap = [];
if ($usingSnapshot) {
    $snapPrices = batchGetSnapshotSubcatUnitPrices($rabSourceId);
    $snapRows = dbGetAll("
        SELECT ss.id, ss.original_subcategory_id, ss.volume
        FROM rab_snapshot_subcategories ss
        JOIN rab_snapshot_categories sc ON ss.category_id = sc.id
        WHERE sc.snapshot_id = ?
    ", [$rabSourceId]);
    foreach ($snapRows as $sr) {
        $origId = !empty($sr['original_subcategory_id']) ? $sr['original_subcategory_id'] : $sr['id'];
        $snapSubcatMap[$origId] = [
            'volume' => floatval($sr['volume']),
            'unit_price' => floatval($snapPrices[$sr['id']]['total'] ?? 0.0)
        ];
    }
}

$headSubMap = [];
foreach ($headSubs as $hs) {
    $headSubMap[$hs['id']] = [
        'head_sub' => $hs,
        'categories' => [],
        'total' => 0,
        'total_tenaga' => 0,
        'total_bahan' => 0,
        'total_alat' => 0,
        'rab_total' => 0
    ];
}

$standaloneCats = [];
$grandTotal = 0;
$grandTotalTenaga = 0;
$grandTotalBahan = 0;
$grandTotalAlat = 0;
$grandRabTotal = 0;

foreach ($categories as $cat) {
    $subcats = dbGetAll("
        SELECT rs.id, rs.volume, rs.code, rs.name, rs.unit, rs.ahsp_id,
               rap.id as rap_id, rap.volume as rap_volume,
               pa.ahsp_code as ahsp_code
        FROM rab_subcategories rs
        LEFT JOIN rap_items rap ON rs.id = rap.subcategory_id
        LEFT JOIN project_ahsp pa ON rs.ahsp_id = pa.id
        WHERE rs.category_id = ?
        ORDER BY rs.sort_order, rs.code
    ", [$cat['id']]);

    $catTotal = 0;
    $catTenaga = 0;
    $catBahan = 0;
    $catAlat = 0;
    $catRabTotal = 0;
    $enrichedSubcats = [];

    foreach ($subcats as $sub) {
        $volume = isset($sub['rap_volume']) && $sub['rap_volume'] !== null ? floatval($sub['rap_volume']) : floatval($sub['volume']);

        $components = $rapItemBreakdowns[$sub['id']] ?? ['upah' => 0.0, 'material' => 0.0, 'alat' => 0.0, 'total' => 0.0];
        $baseUnitPrice = floatval($components['total'] ?? 0.0);

        $unitPriceWithOverhead = $baseUnitPrice * (1 + ($overheadPct / 100));
        $subTotal = $volume * $unitPriceWithOverhead;
        $catTotal += $subTotal;

        // RAB Comparison
        $rabVolume = floatval($sub['volume']);
        if ($usingSnapshot) {
            $rabPrice = 0.0;
            if (isset($snapSubcatMap[$sub['id']])) {
                $rabVolume = $snapSubcatMap[$sub['id']]['volume'];
                $rabPrice = $snapSubcatMap[$sub['id']]['unit_price'];
            }
        } else {
            if ($sub['ahsp_id']) {
                $rabPrice = floatval($rabAhspBreakdownMap[$sub['ahsp_id']]['total'] ?? 0.0);
            } else {
                $rabPrice = 0.0;
            }
        }

        $rabUnitPriceWithOverhead = $rabPrice * (1 + ($rabOverheadPct / 100));
        $rabTotal = $rabVolume * $rabUnitPriceWithOverhead;
        $catRabTotal += $rabTotal;

        $sub['display_volume'] = $volume;
        $sub['display_unit_price'] = $unitPriceWithOverhead;
        $sub['display_total'] = $subTotal;
        $sub['anggaran_tenaga'] = $components['upah'] * $volume;
        $sub['anggaran_bahan'] = $components['material'] * $volume;
        $sub['anggaran_alat'] = $components['alat'] * $volume;
        $sub['rab_total'] = $rabTotal;
        $sub['selisih'] = $rabTotal - $subTotal;

        $catTenaga += $sub['anggaran_tenaga'];
        $catBahan += $sub['anggaran_bahan'];
        $catAlat += $sub['anggaran_alat'];

        $enrichedSubcats[] = $sub;
    }

    if (empty($enrichedSubcats)) {
        continue;
    }

    $grandTotal += $catTotal;
    $grandTotalTenaga += $catTenaga;
    $grandTotalBahan += $catBahan;
    $grandTotalAlat += $catAlat;
    $grandRabTotal += $catRabTotal;

    $catData = [
        'category' => $cat,
        'subcategories' => $enrichedSubcats,
        'total' => $catTotal,
        'total_tenaga' => $catTenaga,
        'total_bahan' => $catBahan,
        'total_alat' => $catAlat,
        'rab_total' => $catRabTotal,
        'selisih' => $catRabTotal - $catTotal
    ];

    if (!empty($cat['head_sub_id']) && isset($headSubMap[$cat['head_sub_id']])) {
        $hsId = $cat['head_sub_id'];
        $headSubMap[$hsId]['categories'][$cat['id']] = $catData;
        $headSubMap[$hsId]['total'] += $catTotal;
        $headSubMap[$hsId]['total_tenaga'] += $catTenaga;
        $headSubMap[$hsId]['total_bahan'] += $catBahan;
        $headSubMap[$hsId]['total_alat'] += $catAlat;
        $headSubMap[$hsId]['rab_total'] += $catRabTotal;
    } else {
        $standaloneCats[$cat['id']] = $catData;
    }
}

// PPN & Rounding calculations (same as tabs/rap.php)
$ppnAmount = $grandTotal * ($ppnPercentage / 100);
$totalWithPpn = $grandTotal + $ppnAmount;
$totalRounded = ceil($totalWithPpn / 10) * 10;

$grandSelisih = $grandRabTotal - $grandTotal;
$rabPpnAmount = $grandRabTotal * ($ppnPercentage / 100);
$rabTotalWithPpn = $grandRabTotal + $rabPpnAmount;
$rabTotalRounded = ceil($rabTotalWithPpn / 10) * 10;
$selisihRounded = $rabTotalRounded - $totalRounded;

$currentUserName = $_SESSION['full_name'] ?? getCurrentUserName() ?? 'Tim Proyek';
$currentUserRole = getRoleDisplayName(getCurrentUserRole());
$todayFormatted = formatDate(date('Y-m-d'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan RAP - <?= htmlspecialchars($project['name']) ?></title>
    <style id="dynamicPageStyle">
        @page {
            size: A4 portrait;
            margin: 12mm 10mm;
        }
    </style>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 10pt;
            color: #000;
            background: #eef1f5;
            padding: 0 16px 32px 16px;
        }

        /* ===== TOP TOOLBAR & EDIT PRINT PANEL (NO-PRINT) ===== */
        .print-toolbar-wrapper {
            position: sticky;
            top: 0;
            z-index: 1000;
            margin: 0 -16px 18px -16px;
            background: #1e293b;
            color: #f8fafc;
            box-shadow: 0 2px 10px rgba(0,0,0,0.18);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .print-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 18px;
        }

        .toolbar-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .toolbar-title {
            font-size: 13.5px;
            font-weight: 700;
            letter-spacing: 0.2px;
        }

        .toolbar-status-pill {
            font-size: 11.5px;
            background: rgba(255,255,255,0.12);
            color: #cbd5e1;
            padding: 3px 10px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.14);
        }

        .toolbar-actions {
            display: flex;
            align-items: center;
            gap: 7px;
            flex-wrap: wrap;
        }

        .tb-btn {
            padding: 6px 13px;
            border: 1px solid transparent;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12.5px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.15s ease;
            font-family: inherit;
        }
        .tb-btn-settings {
            background: #334155;
            color: #f8fafc;
            border-color: #475569;
        }
        .tb-btn-settings:hover, .tb-btn-settings.active {
            background: #0ea5e9;
            border-color: #0ea5e9;
            color: #fff;
        }
        .tb-btn-edit {
            background: #334155;
            color: #f8fafc;
            border-color: #475569;
        }
        .tb-btn-edit:hover {
            background: #f59e0b;
            border-color: #f59e0b;
            color: #111;
        }
        .tb-btn-edit.active {
            background: #f59e0b;
            border-color: #fbbf24;
            color: #111;
            box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.35);
        }
        .tb-btn-reset {
            background: transparent;
            color: #cbd5e1;
            border-color: #475569;
        }
        .tb-btn-reset:hover {
            background: #475569;
            color: #fff;
        }
        .tb-btn-print {
            background: #16a34a;
            color: #fff;
        }
        .tb-btn-print:hover {
            background: #15803d;
        }
        .tb-btn-close {
            background: #64748b;
            color: #fff;
        }
        .tb-btn-close:hover {
            background: #475569;
        }

        /* Collapsible Edit Print Panel */
        .edit-print-panel {
            display: none;
            background: #f8fafc;
            color: #1e293b;
            border-top: 1px solid #334155;
            border-bottom: 1px solid #cbd5e1;
            padding: 14px 18px;
        }
        .edit-print-panel.open {
            display: block;
        }

        .ep-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 14px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .ep-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 14px;
        }
        .ep-card-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #0f172a;
            margin-bottom: 10px;
            padding-bottom: 6px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .ep-row {
            display: flex;
            gap: 8px;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }
        .ep-field {
            flex: 1;
            min-width: 105px;
        }
        .ep-field label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 3px;
        }
        .ep-field select,
        .ep-field input[type="number"],
        .ep-field input[type="text"] {
            width: 100%;
            padding: 5px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 5px;
            font-size: 12px;
            color: #0f172a;
            background: #fff;
            font-family: inherit;
        }
        .ep-field select:focus,
        .ep-field input:focus {
            outline: none;
            border-color: #0ea5e9;
            box-shadow: 0 0 0 2px rgba(14, 165, 233, 0.15);
        }

        .ep-checks {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 10px;
        }
        .ep-check-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #334155;
            cursor: pointer;
            user-select: none;
        }
        .ep-check-label input[type="checkbox"] {
            width: 14px;
            height: 14px;
            cursor: pointer;
            accent-color: #0ea5e9;
        }

        /* Signature Editor Cards inside Panel */
        .ep-sig-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-height: 210px;
            overflow-y: auto;
            padding-right: 4px;
        }
        .ep-sig-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px;
        }
        .ep-sig-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            font-size: 11.5px;
            font-weight: 700;
            color: #334155;
        }
        .ep-sig-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 5px;
        }
        .ep-sig-inputs input {
            padding: 4px 6px;
            font-size: 11.5px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            width: 100%;
            font-family: inherit;
        }
        .ep-btn-mini {
            padding: 3px 8px;
            font-size: 11px;
            border-radius: 4px;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #0f172a;
            cursor: pointer;
            font-weight: 600;
            font-family: inherit;
        }
        .ep-btn-mini:hover { background: #e2e8f0; }
        .ep-btn-mini-primary {
            background: #0ea5e9;
            border-color: #0ea5e9;
            color: #fff;
        }
        .ep-btn-mini-primary:hover { background: #0284c7; }
        .ep-btn-mini-danger {
            background: #fee2e2;
            border-color: #fecaca;
            color: #b91c1c;
        }
        .ep-btn-mini-danger:hover { background: #fca5a5; }

        /* Edit Mode Active Banner */
        .edit-mode-banner {
            display: none;
            background: #fef3c7;
            color: #92400e;
            border-bottom: 1px solid #fcd34d;
            padding: 7px 18px;
            font-size: 12px;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .edit-mode-banner.active {
            display: flex;
        }

        /* ===== PRINTABLE PAGE CONTAINER ===== */
        .page-container {
            background: #fff;
            width: 100%;
            max-width: 210mm;
            margin: 0 auto;
            padding: 12mm 10mm;
            box-shadow: 0 3px 14px rgba(0,0,0,0.12);
            transition: max-width 0.2s ease, padding 0.2s ease;
        }

        /* Inline Editable Highlighting (Screen Only) */
        .edit-mode-active [contenteditable="true"] {
            outline: 1px dashed rgba(245, 158, 11, 0.55);
            outline-offset: 1px;
            border-radius: 2px;
            cursor: text;
            transition: background-color 0.15s ease, outline-color 0.15s ease;
        }
        .edit-mode-active [contenteditable="true"]:hover {
            background-color: rgba(254, 243, 199, 0.45);
            outline-color: #d97706;
        }
        .edit-mode-active [contenteditable="true"]:focus {
            background-color: #fffbeb;
            outline: 2px solid #f59e0b;
        }

        .report-title {
            text-align: center;
            font-size: 14pt;
            font-weight: bold;
            margin-bottom: 14px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        .project-info {
            margin-bottom: 14px;
            border-collapse: collapse;
            width: 100%;
        }
        .project-info td {
            padding: 2px 5px;
            vertical-align: top;
            font-size: 9.5pt;
        }
        .project-info td:first-child {
            width: 210px;
            font-weight: bold;
        }
        .project-info td:nth-child(2) {
            width: 14px;
            text-align: center;
        }

        .rab-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 9.5pt;
        }
        .rab-table th, .rab-table td {
            border: 1px solid #000;
            padding: 4px 6px;
            vertical-align: middle;
        }
        .rab-table th {
            background: #d9e2f3;
            font-weight: bold;
            text-align: center;
        }
        .rab-table .text-end { text-align: right; }
        .rab-table .text-center { text-align: center; }
        .rab-table .text-nowrap { white-space: nowrap; }

        .head-sub-row td {
            background: #2c3e50;
            color: #ffffff;
            font-weight: bold;
            padding: 5px 8px;
        }
        .head-sub-standalone td {
            background: #4a6572;
            color: #ffffff;
            font-weight: bold;
            padding: 5px 8px;
        }
        .head-sub-total td {
            background: #d1ecf1;
            font-weight: bold;
        }
        .cat-header td {
            background: #e8f0fe;
            font-weight: bold;
        }
        .cat-total td {
            background: #f2f2f2;
            font-weight: bold;
        }
        .grand-total td {
            background: #d9e2f3;
            font-weight: bold;
        }
        .ppn-row td {
            background: #fafafa;
            font-weight: bold;
        }
        .final-total td {
            background: #bdd7ee;
            font-weight: bold;
        }
        .selisih-pos { color: #157347; }
        .selisih-neg { color: #b02a37; }

        /* Color Themes */
        .theme-minimal .rab-table th { background: #e9ecef; color: #000; }
        .theme-minimal .head-sub-row td,
        .theme-minimal .head-sub-standalone td { background: #dee2e6; color: #000; }
        .theme-minimal .cat-header td { background: #f1f3f5; }
        .theme-minimal .head-sub-total td { background: #e9ecef; }
        .theme-minimal .grand-total td { background: #dee2e6; }
        .theme-minimal .final-total td { background: #ced4da; }

        .theme-bw .rab-table th,
        .theme-bw .head-sub-row td,
        .theme-bw .head-sub-standalone td,
        .theme-bw .cat-header td,
        .theme-bw .cat-total td,
        .theme-bw .head-sub-total td,
        .theme-bw .grand-total td,
        .theme-bw .ppn-row td,
        .theme-bw .final-total td {
            background: #fff !important;
            color: #000 !important;
        }
        .theme-bw .selisih-pos,
        .theme-bw .selisih-neg {
            color: #000 !important;
        }

        /* Optional Column Visibility Helpers */
        .col-breakdown { display: none; }
        .col-selisih { display: none; }
        .show-breakdown .col-breakdown { display: table-cell; }
        .show-selisih .col-selisih { display: table-cell; }
        .hide-cat-total .cat-total { display: none; }
        .hide-ppn-rows .ppn-summary-row { display: none; }

        /* Notes Section */
        .notes-section {
            display: none;
            margin-top: 16px;
            border: 1px solid #555;
            padding: 8px 12px;
            font-size: 9.5pt;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .notes-section.visible { display: block; }
        .notes-title {
            font-weight: bold;
            margin-bottom: 4px;
            text-decoration: underline;
        }

        /* Signature Section */
        .signature-section {
            margin-top: 28px;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .signature-place-date {
            text-align: right;
            font-size: 9.5pt;
            margin-bottom: 14px;
        }
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            border: none;
        }
        .signature-table td {
            border: none;
            text-align: center;
            vertical-align: top;
            padding: 0 8px;
        }
        .sig-title {
            font-size: 9.5pt;
            font-weight: bold;
            margin-bottom: 2px;
        }
        .sig-role {
            font-size: 9pt;
            color: #333;
            margin-bottom: 52px;
        }
        .sig-name {
            font-size: 9.5pt;
            font-weight: bold;
            margin-bottom: 3px;
        }
        .sig-name u {
            text-decoration: underline;
        }
        .sig-date {
            font-size: 8.5pt;
            color: #444;
        }

        /* ===== PRINT STYLES ===== */
        @media print {
            body {
                background: #fff !important;
                padding: 0 !important;
                margin: 0 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .no-print {
                display: none !important;
            }
            .page-container {
                box-shadow: none !important;
                padding: 0 !important;
                margin: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
            }
            [contenteditable="true"] {
                outline: none !important;
                background: transparent !important;
            }
            .rab-table {
                page-break-inside: auto;
            }
            .rab-table thead {
                display: table-header-group;
            }
            /* Prevent grand total from ever repeating per page */
            .rab-table tfoot {
                display: table-row-group !important;
            }
            .rab-table tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .rab-table tbody.summary-section {
                page-break-inside: avoid;
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>

<!-- ===== STICKY TOOLBAR & EDIT PRINT PANEL ===== -->
<div class="print-toolbar-wrapper no-print">
    <div class="print-toolbar">
        <div class="toolbar-left">
            <span class="toolbar-title">Preview Cetak RAP</span>
            <span class="toolbar-status-pill" id="toolbarStatusPill">A4 Portrait · Font 9.5pt · Margin 12/10mm</span>
        </div>
        <div class="toolbar-actions">
            <button type="button" class="tb-btn tb-btn-settings" id="btnToggleEditPrint" onclick="toggleEditPrintPanel()">
                &#9881; Edit Print
            </button>
            <button type="button" class="tb-btn tb-btn-edit" id="btnToggleEditText" onclick="toggleDirectEditMode()">
                &#9998; Mode Edit Teks
            </button>
            <button type="button" class="tb-btn tb-btn-reset" onclick="resetAllPrintEdits()" title="Kembalikan pengaturan dan teks ke awal">
                &#8634; Reset
            </button>
            <button type="button" class="tb-btn tb-btn-print" onclick="triggerRapPrint()">
                &#128424; Cetak / Simpan PDF
            </button>
            <button type="button" class="tb-btn tb-btn-close" onclick="closePreviewWindow()">
                &#10005; Tutup
            </button>
        </div>
    </div>

    <!-- Active Inline Edit Banner -->
    <div class="edit-mode-banner" id="editModeBanner">
        <span>
            <strong>Mode Edit Teks Aktif:</strong> Klik langsung pada judul, info proyek, uraian pekerjaan, catatan, atau tanda tangan di bawah untuk mengubah teks sebelum dicetak. <em>(Perubahan hanya berlaku untuk cetakan ini dan tidak mengubah database.)</em>
        </span>
        <button type="button" class="ep-btn-mini" onclick="toggleDirectEditMode(false)">Selesai Edit</button>
    </div>

    <!-- Collapsible Edit Print Settings Panel -->
    <div class="edit-print-panel" id="editPrintPanel">
        <div class="ep-grid">
            <!-- Card 1: Tata Letak & Kertas -->
            <div class="ep-card">
                <div class="ep-card-title">
                    <span>1. Tata Letak &amp; Kertas</span>
                </div>
                <div class="ep-row">
                    <div class="ep-field">
                        <label for="optPaperSize">Ukuran Kertas</label>
                        <select id="optPaperSize" onchange="applyPrintLayout()">
                            <option value="A4" selected>A4 (210 × 297 mm)</option>
                            <option value="F4">F4 / Folio (215 × 330 mm)</option>
                            <option value="Legal">Legal (216 × 356 mm)</option>
                            <option value="Letter">Letter (216 × 279 mm)</option>
                        </select>
                    </div>
                    <div class="ep-field">
                        <label for="optOrientation">Orientasi</label>
                        <select id="optOrientation" onchange="applyPrintLayout()">
                            <option value="portrait" selected>Portrait (Tegak)</option>
                            <option value="landscape">Landscape (Mendatar)</option>
                        </select>
                    </div>
                </div>
                <div class="ep-row">
                    <div class="ep-field">
                        <label for="optFontSize">Ukuran Font Tabel</label>
                        <select id="optFontSize" onchange="applyPrintLayout()">
                            <option value="7.5">7.5 pt (Sangat Padat)</option>
                            <option value="8">8 pt (Padat)</option>
                            <option value="8.5">8.5 pt</option>
                            <option value="9">9 pt</option>
                            <option value="9.5" selected>9.5 pt (Normal)</option>
                            <option value="10">10 pt</option>
                            <option value="10.5">10.5 pt</option>
                            <option value="11">11 pt (Besar)</option>
                        </select>
                    </div>
                    <div class="ep-field">
                        <label for="optTheme">Warna Tabel</label>
                        <select id="optTheme" onchange="applyPrintLayout()">
                            <option value="default" selected>Biru Klasik</option>
                            <option value="minimal">Abu-abu Minimalis</option>
                            <option value="bw">Hitam Putih (Hemat Tinta)</option>
                        </select>
                    </div>
                </div>
                <div class="ep-row" style="margin-bottom:0;">
                    <div class="ep-field">
                        <label for="optMarginV">Margin Atas/Bawah (mm)</label>
                        <input type="number" id="optMarginV" value="12" min="4" max="35" step="1" onchange="applyPrintLayout()">
                    </div>
                    <div class="ep-field">
                        <label for="optMarginH">Margin Kiri/Kanan (mm)</label>
                        <input type="number" id="optMarginH" value="10" min="4" max="35" step="1" onchange="applyPrintLayout()">
                    </div>
                </div>
            </div>

            <!-- Card 2: Bagian & Kolom yang Ditampilkan -->
            <div class="ep-card">
                <div class="ep-card-title">
                    <span>2. Tampilkan Bagian &amp; Kolom</span>
                </div>
                <div class="ep-checks">
                    <label class="ep-check-label">
                        <input type="checkbox" id="chkProjectInfo" checked onchange="applyVisibilityOptions()">
                        Info Proyek
                    </label>
                    <label class="ep-check-label">
                        <input type="checkbox" id="chkCatTotals" checked onchange="applyVisibilityOptions()">
                        Subtotal Kategori
                    </label>
                    <label class="ep-check-label">
                        <input type="checkbox" id="chkPpnSummary" checked onchange="applyVisibilityOptions()">
                        PPN &amp; Pembulatan
                    </label>
                    <label class="ep-check-label">
                        <input type="checkbox" id="chkSignatures" checked onchange="applyVisibilityOptions()">
                        Blok Tanda Tangan
                    </label>
                    <label class="ep-check-label">
                        <input type="checkbox" id="chkBreakdownCols" onchange="applyVisibilityOptions(true)">
                        Tenaga / Bahan / Alat
                    </label>
                    <label class="ep-check-label">
                        <input type="checkbox" id="chkSelisihCol" onchange="applyVisibilityOptions(true)">
                        Kolom Selisih RAB
                    </label>
                    <label class="ep-check-label">
                        <input type="checkbox" id="chkNotesBox" onchange="applyVisibilityOptions()">
                        Catatan Tambahan
                    </label>
                    <label class="ep-check-label">
                        <input type="checkbox" id="chkPlaceDate" onchange="applyVisibilityOptions()">
                        Baris Kota &amp; Tanggal
                    </label>
                </div>
                <div class="ep-row" id="placeDateInputRow" style="display:none; margin-top:8px; margin-bottom:0;">
                    <div class="ep-field">
                        <label for="optPlaceDateText">Teks Kota &amp; Tanggal (di atas tanda tangan)</label>
                        <input type="text" id="optPlaceDateText" value="<?= htmlspecialchars(($regionName !== '-' ? $regionName : 'Jakarta') . ', ' . $todayFormatted) ?>" oninput="syncPlaceDateText()">
                    </div>
                </div>
            </div>

            <!-- Card 3: Pengaturan Tanda Tangan -->
            <div class="ep-card">
                <div class="ep-card-title">
                    <span>3. Blok Tanda Tangan</span>
                    <button type="button" class="ep-btn-mini ep-btn-mini-primary" onclick="addSignatureColumn()">+ Tambah</button>
                </div>
                <div class="ep-sig-list" id="sigEditorList"></div>
            </div>
        </div>
    </div>
</div>

<!-- ===== PRINTABLE DOCUMENT CONTAINER ===== -->
<div class="page-container" id="printablePage">
    <div class="report-title editable-text" id="reportTitle">RENCANA ANGGARAN PELAKSANAAN (RAP)</div>

    <table class="project-info" id="projectInfoTable">
        <tr>
            <td class="editable-text">NAMA KEGIATAN</td>
            <td>:</td>
            <td class="editable-text"><?= htmlspecialchars($project['activity_name'] ?? '-') ?></td>
        </tr>
        <tr>
            <td class="editable-text">PEKERJAAN</td>
            <td>:</td>
            <td class="editable-text"><?= htmlspecialchars($project['work_description'] ?? '-') ?></td>
        </tr>
        <tr>
            <td class="editable-text">SUMBER DANA</td>
            <td>:</td>
            <td class="editable-text"><?= htmlspecialchars($project['funding_source'] ?? '-') ?></td>
        </tr>
        <tr>
            <td class="editable-text">TAHUN ANGGARAN</td>
            <td>:</td>
            <td class="editable-text"><?= htmlspecialchars($project['budget_year'] ?? '-') ?></td>
        </tr>
        <tr>
            <td class="editable-text">NO. dan TGL KONTRAK</td>
            <td>:</td>
            <td class="editable-text"><?= htmlspecialchars(($project['contract_number'] ?? '-') . ' TANGGAL ' . ($project['contract_date'] ? formatDate($project['contract_date']) : '-')) ?></td>
        </tr>
        <tr>
            <td class="editable-text">PENYEDIA JASA</td>
            <td>:</td>
            <td class="editable-text"><?= htmlspecialchars($project['service_provider'] ?? '-') ?></td>
        </tr>
        <tr>
            <td class="editable-text">KONS. PENGAWAS</td>
            <td>:</td>
            <td class="editable-text"><?= htmlspecialchars($project['supervisor_consultant'] ?? '-') ?></td>
        </tr>
        <tr>
            <td class="editable-text">JANGKA WAKTU PELAKSANAAN</td>
            <td>:</td>
            <td class="editable-text"><?= htmlspecialchars($project['duration_days'] ?? 0) ?> HARI</td>
        </tr>
        <tr>
            <td class="editable-text">WILAYAH</td>
            <td>:</td>
            <td class="editable-text"><?= htmlspecialchars($regionName) ?></td>
        </tr>
    </table>

    <table class="rab-table" id="rapPrintTable">
        <thead>
            <tr>
                <th width="38">NO.</th>
                <th>URAIAN PEKERJAAN</th>
                <th width="60">SATUAN</th>
                <th width="72">VOLUME</th>
                <th width="115">HARGA SATUAN<br>(Rp)</th>
                <th width="125">JUMLAH HARGA<br>(Rp)</th>
                <th width="100" class="col-breakdown">TENAGA<br>(Rp)</th>
                <th width="100" class="col-breakdown">BAHAN<br>(Rp)</th>
                <th width="95" class="col-breakdown">PERALATAN<br>(Rp)</th>
                <th width="110" class="col-selisih">SELISIH RAB<br>(Rp)</th>
            </tr>
        </thead>
        <tbody>
            <?php
            // Helper closure for rendering a category block
            $renderCategoryRows = function($data) {
                $cat = $data['category'];
                $subcats = $data['subcategories'];
                $catTotal = $data['total'];
                $catTenaga = $data['total_tenaga'];
                $catBahan = $data['total_bahan'];
                $catAlat = $data['total_alat'];
                $catSelisih = $data['selisih'];
                $catSelisihCls = $catSelisih > 0 ? 'selisih-pos' : ($catSelisih < 0 ? 'selisih-neg' : '');
                if (empty($subcats)) return;
                ?>
                <tr class="cat-header">
                    <td class="text-center editable-text"><?= htmlspecialchars($cat['code']) ?></td>
                    <td colspan="5" class="dynamic-cat-colspan editable-text"><?= htmlspecialchars(strtoupper($cat['name'])) ?></td>
                </tr>
                <?php
                $itemNum = 0;
                foreach ($subcats as $sub):
                    $itemNum++;
                    $subSelisih = $sub['selisih'] ?? 0;
                    $subSelisihCls = $subSelisih > 0 ? 'selisih-pos' : ($subSelisih < 0 ? 'selisih-neg' : '');
                ?>
                <tr>
                    <td class="text-center"><?= $itemNum ?></td>
                    <td class="editable-text"><?= htmlspecialchars($sub['name']) ?></td>
                    <td class="text-center editable-text"><?= htmlspecialchars($sub['unit']) ?></td>
                    <td class="text-end text-nowrap"><?= number_format($sub['display_volume'], 2, ',', '.') ?></td>
                    <td class="text-end text-nowrap"><?= number_format($sub['display_unit_price'], 2, ',', '.') ?></td>
                    <td class="text-end text-nowrap"><?= number_format($sub['display_total'], 2, ',', '.') ?></td>
                    <td class="text-end text-nowrap col-breakdown"><?= number_format($sub['anggaran_tenaga'], 2, ',', '.') ?></td>
                    <td class="text-end text-nowrap col-breakdown"><?= number_format($sub['anggaran_bahan'], 2, ',', '.') ?></td>
                    <td class="text-end text-nowrap col-breakdown"><?= number_format($sub['anggaran_alat'], 2, ',', '.') ?></td>
                    <td class="text-end text-nowrap col-selisih <?= $subSelisihCls ?>"><?= ($subSelisih >= 0 ? '+' : '') . number_format($subSelisih, 2, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="cat-total">
                    <td colspan="5" class="text-end editable-text">JUMLAH <?= htmlspecialchars($cat['code']) ?></td>
                    <td class="text-end text-nowrap"><?= number_format($catTotal, 2, ',', '.') ?></td>
                    <td class="text-end text-nowrap col-breakdown"><?= number_format($catTenaga, 2, ',', '.') ?></td>
                    <td class="text-end text-nowrap col-breakdown"><?= number_format($catBahan, 2, ',', '.') ?></td>
                    <td class="text-end text-nowrap col-breakdown"><?= number_format($catAlat, 2, ',', '.') ?></td>
                    <td class="text-end text-nowrap col-selisih <?= $catSelisihCls ?>"><?= ($catSelisih >= 0 ? '+' : '') . number_format($catSelisih, 2, ',', '.') ?></td>
                </tr>
                <?php
            };

            // 1. Render Head-Sub Groups
            foreach ($headSubMap as $hsId => $hsGroup):
                $hs = $hsGroup['head_sub'];
                $hsCats = $hsGroup['categories'];
                if (empty($hsCats)) continue;
                $hsSelisih = $hsGroup['rab_total'] - $hsGroup['total'];
                $hsSelisihCls = $hsSelisih > 0 ? 'selisih-pos' : ($hsSelisih < 0 ? 'selisih-neg' : '');
            ?>
                <tr class="head-sub-row">
                    <td colspan="6" class="dynamic-full-colspan editable-text">
                        <?= !empty($hs['code']) ? htmlspecialchars($hs['code']) . ' - ' : '' ?><?= htmlspecialchars(strtoupper($hs['name'])) ?>
                    </td>
                </tr>
                <?php foreach ($hsCats as $catData) { $renderCategoryRows($catData); } ?>
                <tr class="head-sub-total">
                    <td colspan="5" class="text-end editable-text">JUMLAH <?= !empty($hs['code']) ? htmlspecialchars($hs['code']) : htmlspecialchars($hs['name']) ?></td>
                    <td class="text-end text-nowrap"><?= number_format($hsGroup['total'], 2, ',', '.') ?></td>
                    <td class="text-end text-nowrap col-breakdown"><?= number_format($hsGroup['total_tenaga'], 2, ',', '.') ?></td>
                    <td class="text-end text-nowrap col-breakdown"><?= number_format($hsGroup['total_bahan'], 2, ',', '.') ?></td>
                    <td class="text-end text-nowrap col-breakdown"><?= number_format($hsGroup['total_alat'], 2, ',', '.') ?></td>
                    <td class="text-end text-nowrap col-selisih <?= $hsSelisihCls ?>"><?= ($hsSelisih >= 0 ? '+' : '') . number_format($hsSelisih, 2, ',', '.') ?></td>
                </tr>
            <?php endforeach; ?>

            <!-- 2. Render Standalone Categories -->
            <?php if (!empty($standaloneCats)): ?>
                <?php if (!empty($headSubs)): ?>
                <tr class="head-sub-standalone">
                    <td colspan="6" class="dynamic-full-colspan editable-text">KATEGORI TANPA HEAD-SUB</td>
                </tr>
                <?php endif; ?>
                <?php foreach ($standaloneCats as $catData) { $renderCategoryRows($catData); } ?>
            <?php endif; ?>
        </tbody>

        <!-- Summary Block in dedicated tbody so it ONLY appears once at the very end (never repeated per page) -->
        <tbody class="summary-section">
            <?php
                $grandSelisihCls = $grandSelisih > 0 ? 'selisih-pos' : ($grandSelisih < 0 ? 'selisih-neg' : '');
                $selisihRoundedCls = $selisihRounded > 0 ? 'selisih-pos' : ($selisihRounded < 0 ? 'selisih-neg' : '');
            ?>
            <tr class="grand-total">
                <td colspan="5" class="text-end editable-text">JUMLAH TOTAL RAP</td>
                <td class="text-end text-nowrap"><?= number_format($grandTotal, 2, ',', '.') ?></td>
                <td class="text-end text-nowrap col-breakdown"><?= number_format($grandTotalTenaga, 2, ',', '.') ?></td>
                <td class="text-end text-nowrap col-breakdown"><?= number_format($grandTotalBahan, 2, ',', '.') ?></td>
                <td class="text-end text-nowrap col-breakdown"><?= number_format($grandTotalAlat, 2, ',', '.') ?></td>
                <td class="text-end text-nowrap col-selisih <?= $grandSelisihCls ?>"><?= ($grandSelisih >= 0 ? '+' : '') . number_format($grandSelisih, 2, ',', '.') ?></td>
            </tr>
            <tr class="ppn-row ppn-summary-row">
                <td colspan="5" class="text-end editable-text">PPN <?= number_format($ppnPercentage, 2, ',', '.') ?>%</td>
                <td class="text-end text-nowrap"><?= number_format($ppnAmount, 2, ',', '.') ?></td>
                <td class="col-breakdown" colspan="3"></td>
                <td class="col-selisih"></td>
            </tr>
            <tr class="ppn-row ppn-summary-row">
                <td colspan="5" class="text-end editable-text">JUMLAH TOTAL (TERMASUK PPN)</td>
                <td class="text-end text-nowrap"><?= number_format($totalWithPpn, 2, ',', '.') ?></td>
                <td class="col-breakdown" colspan="3"></td>
                <td class="col-selisih"></td>
            </tr>
            <tr class="final-total ppn-summary-row">
                <td colspan="5" class="text-end editable-text">JUMLAH TOTAL DIBULATKAN</td>
                <td class="text-end text-nowrap">Rp <?= number_format($totalRounded, 2, ',', '.') ?></td>
                <td class="col-breakdown" colspan="3"></td>
                <td class="text-end text-nowrap col-selisih <?= $selisihRoundedCls ?>"><?= ($selisihRounded >= 0 ? '+Rp ' : '-Rp ') . number_format(abs($selisihRounded), 2, ',', '.') ?></td>
            </tr>
        </tbody>
    </table>

    <!-- Optional Notes Box -->
    <div class="notes-section" id="notesSection">
        <div class="notes-title editable-text">Catatan:</div>
        <div class="editable-text" id="notesContent">Harga satuan dan volume mengacu pada Rencana Anggaran Pelaksanaan (RAP) proyek yang berlaku.</div>
    </div>

    <!-- Signature Block (only at the very end of the document) -->
    <div class="signature-section" id="signatureSection">
        <div class="signature-place-date editable-text" id="signaturePlaceDate" style="display:none;">
            <?= htmlspecialchars(($regionName !== '-' ? $regionName : 'Jakarta') . ', ' . $todayFormatted) ?>
        </div>
        <table class="signature-table" id="signatureTable">
            <tr id="signatureTableRow"></tr>
        </table>
    </div>
</div>

<script>
(function() {
    var defaultSignatures = [
        {
            title: 'Dibuat Oleh,',
            role: <?= json_encode($currentUserRole ?: 'Tim Proyek') ?>,
            name: <?= json_encode($currentUserName) ?>,
            date: <?= json_encode('Tgl: ' . $todayFormatted) ?>
        },
        {
            title: 'Diperiksa Oleh,',
            role: 'Project Manager',
            name: '( ........................................ )',
            date: 'Tgl: ................................'
        },
        {
            title: 'Disetujui Oleh,',
            role: 'Direktur / Pimpinan',
            name: '( ........................................ )',
            date: 'Tgl: ................................'
        }
    ];

    var signatures = JSON.parse(JSON.stringify(defaultSignatures));
    var isEditMode = false;

    // Store initial HTML snapshots of editable elements for Reset
    var initialEditableSnapshots = [];

    function captureInitialSnapshots() {
        initialEditableSnapshots = [];
        document.querySelectorAll('#printablePage .editable-text').forEach(function(el) {
            initialEditableSnapshots.push({ el: el, html: el.innerHTML });
        });
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Paper dimension lookup in mm (width x height in portrait)
    var paperSizes = {
        'A4':     { w: 210, h: 297, css: 'A4' },
        'F4':     { w: 215, h: 330, css: '215mm 330mm' },
        'Legal':  { w: 216, h: 356, css: 'legal' },
        'Letter': { w: 216, h: 279, css: 'letter' }
    };

    window.toggleEditPrintPanel = function(forceState) {
        var panel = document.getElementById('editPrintPanel');
        var btn = document.getElementById('btnToggleEditPrint');
        var shouldOpen = (typeof forceState === 'boolean') ? forceState : !panel.classList.contains('open');
        panel.classList.toggle('open', shouldOpen);
        btn.classList.toggle('active', shouldOpen);
    };

    window.toggleDirectEditMode = function(forceState) {
        isEditMode = (typeof forceState === 'boolean') ? forceState : !isEditMode;
        var page = document.getElementById('printablePage');
        var btn = document.getElementById('btnToggleEditText');
        var banner = document.getElementById('editModeBanner');

        page.classList.toggle('edit-mode-active', isEditMode);
        btn.classList.toggle('active', isEditMode);
        banner.classList.toggle('active', isEditMode);

        applyEditableAttribute();
    };

    function applyEditableAttribute() {
        document.querySelectorAll('#printablePage .editable-text').forEach(function(el) {
            if (isEditMode) {
                el.setAttribute('contenteditable', 'true');
                el.setAttribute('spellcheck', 'false');
            } else {
                el.removeAttribute('contenteditable');
                el.removeAttribute('spellcheck');
            }
        });
    }

    window.applyPrintLayout = function() {
        var paperKey = document.getElementById('optPaperSize').value;
        var orientation = document.getElementById('optOrientation').value;
        var fontSize = document.getElementById('optFontSize').value;
        var theme = document.getElementById('optTheme').value;
        var marginV = Math.max(4, Math.min(35, parseInt(document.getElementById('optMarginV').value, 10) || 12));
        var marginH = Math.max(4, Math.min(35, parseInt(document.getElementById('optMarginH').value, 10) || 10));

        var p = paperSizes[paperKey] || paperSizes['A4'];
        var pageSizeCss;
        if (paperKey === 'F4') {
            pageSizeCss = (orientation === 'landscape') ? (p.h + 'mm ' + p.w + 'mm') : (p.w + 'mm ' + p.h + 'mm');
        } else {
            pageSizeCss = p.css + ' ' + orientation;
        }

        // Update @page rule dynamically
        var styleEl = document.getElementById('dynamicPageStyle');
        styleEl.textContent = '@page { size: ' + pageSizeCss + '; margin: ' + marginV + 'mm ' + marginH + 'mm; }';

        // Update preview container width & padding
        var page = document.getElementById('printablePage');
        var previewWidth = (orientation === 'landscape') ? p.h : p.w;
        page.style.maxWidth = previewWidth + 'mm';
        page.style.padding = marginV + 'mm ' + marginH + 'mm';

        // Update table font size
        var table = document.getElementById('rapPrintTable');
        table.style.fontSize = fontSize + 'pt';

        // Update color theme class
        page.classList.remove('theme-minimal', 'theme-bw');
        if (theme === 'minimal') page.classList.add('theme-minimal');
        if (theme === 'bw') page.classList.add('theme-bw');

        // Update status pill
        var orientLabel = (orientation === 'landscape') ? 'Landscape' : 'Portrait';
        document.getElementById('toolbarStatusPill').textContent =
            paperKey + ' ' + orientLabel + ' · Font ' + fontSize + 'pt · Margin ' + marginV + '/' + marginH + 'mm';
    };

    window.applyVisibilityOptions = function(autoLandscapeHint) {
        var page = document.getElementById('printablePage');
        var showInfo = document.getElementById('chkProjectInfo').checked;
        var showCatTotals = document.getElementById('chkCatTotals').checked;
        var showPpn = document.getElementById('chkPpnSummary').checked;
        var showSigs = document.getElementById('chkSignatures').checked;
        var showBreakdown = document.getElementById('chkBreakdownCols').checked;
        var showSelisih = document.getElementById('chkSelisihCol').checked;
        var showNotes = document.getElementById('chkNotesBox').checked;
        var showPlaceDate = document.getElementById('chkPlaceDate').checked;

        document.getElementById('projectInfoTable').style.display = showInfo ? '' : 'none';
        document.getElementById('signatureSection').style.display = showSigs ? '' : 'none';
        document.getElementById('notesSection').classList.toggle('visible', showNotes);
        document.getElementById('signaturePlaceDate').style.display = showPlaceDate ? 'block' : 'none';
        document.getElementById('placeDateInputRow').style.display = showPlaceDate ? 'flex' : 'none';

        page.classList.toggle('hide-cat-total', !showCatTotals);
        page.classList.toggle('hide-ppn-rows', !showPpn);
        page.classList.toggle('show-breakdown', showBreakdown);
        page.classList.toggle('show-selisih', showSelisih);

        // Auto-switch to Landscape when Tenaga/Bahan/Alat enabled so columns fit cleanly
        if (autoLandscapeHint && showBreakdown && document.getElementById('optOrientation').value === 'portrait') {
            document.getElementById('optOrientation').value = 'landscape';
            applyPrintLayout();
        }

        // Update dynamic colspans for Head-Sub and Category headers
        var extraCols = (showBreakdown ? 3 : 0) + (showSelisih ? 1 : 0);
        document.querySelectorAll('.dynamic-full-colspan').forEach(function(td) {
            td.setAttribute('colspan', 6 + extraCols);
        });
        document.querySelectorAll('.dynamic-cat-colspan').forEach(function(td) {
            td.setAttribute('colspan', 5 + extraCols);
        });
    };

    window.syncPlaceDateText = function() {
        var val = document.getElementById('optPlaceDateText').value;
        document.getElementById('signaturePlaceDate').textContent = val;
    };

    // ===== SIGNATURE MANAGER =====
    function renderSignatures() {
        var row = document.getElementById('signatureTableRow');
        row.innerHTML = '';

        var colWidth = signatures.length > 0 ? (100 / signatures.length).toFixed(2) + '%' : '100%';

        signatures.forEach(function(sig, idx) {
            var td = document.createElement('td');
            td.style.width = colWidth;

            var isUnderline = sig.name && sig.name.trim().charAt(0) !== '(';
            var nameHtml = isUnderline ? '<u>' + escapeHtml(sig.name) + '</u>' : escapeHtml(sig.name);

            td.innerHTML =
                '<div class="sig-title editable-text" data-sig-idx="' + idx + '" data-sig-field="title">' + escapeHtml(sig.title) + '</div>' +
                '<div class="sig-role editable-text" data-sig-idx="' + idx + '" data-sig-field="role">' + escapeHtml(sig.role) + '</div>' +
                '<div class="sig-name editable-text" data-sig-idx="' + idx + '" data-sig-field="name">' + nameHtml + '</div>' +
                '<div class="sig-date editable-text" data-sig-idx="' + idx + '" data-sig-field="date">' + escapeHtml(sig.date) + '</div>';

            row.appendChild(td);
        });

        // Two-way sync when edited directly in preview
        row.querySelectorAll('[data-sig-idx]').forEach(function(el) {
            el.addEventListener('input', function() {
                var i = parseInt(el.getAttribute('data-sig-idx'), 10);
                var f = el.getAttribute('data-sig-field');
                if (signatures[i]) {
                    signatures[i][f] = el.textContent.trim();
                    var inp = document.querySelector('#sigEditorList input[data-sig-idx="' + i + '"][data-sig-field="' + f + '"]');
                    if (inp) inp.value = signatures[i][f];
                }
            });
        });

        renderSignatureEditorList();
        applyEditableAttribute();
    }

    function renderSignatureEditorList() {
        var list = document.getElementById('sigEditorList');
        list.innerHTML = '';

        if (signatures.length === 0) {
            list.innerHTML = '<div style="font-size:11.5px; color:#64748b; text-align:center; padding:10px;">Belum ada kolom tanda tangan. Klik "+ Tambah".</div>';
            return;
        }

        signatures.forEach(function(sig, idx) {
            var item = document.createElement('div');
            item.className = 'ep-sig-item';
            item.innerHTML =
                '<div class="ep-sig-item-header">' +
                    '<span>Kolom #' + (idx + 1) + '</span>' +
                    '<button type="button" class="ep-btn-mini ep-btn-mini-danger" onclick="removeSignatureColumn(' + idx + ')">Hapus</button>' +
                '</div>' +
                '<div class="ep-sig-inputs">' +
                    '<input type="text" placeholder="Judul (cth: Dibuat Oleh,)" value="' + escapeHtml(sig.title) + '" data-sig-idx="' + idx + '" data-sig-field="title" oninput="updateSignatureField(' + idx + ', \'title\', this.value)">' +
                    '<input type="text" placeholder="Jabatan" value="' + escapeHtml(sig.role) + '" data-sig-idx="' + idx + '" data-sig-field="role" oninput="updateSignatureField(' + idx + ', \'role\', this.value)">' +
                    '<input type="text" placeholder="Nama" value="' + escapeHtml(sig.name) + '" data-sig-idx="' + idx + '" data-sig-field="name" oninput="updateSignatureField(' + idx + ', \'name\', this.value)">' +
                    '<input type="text" placeholder="Tanggal (opsional)" value="' + escapeHtml(sig.date) + '" data-sig-idx="' + idx + '" data-sig-field="date" oninput="updateSignatureField(' + idx + ', \'date\', this.value)">' +
                '</div>';
            list.appendChild(item);
        });
    }

    window.updateSignatureField = function(idx, field, val) {
        if (!signatures[idx]) return;
        signatures[idx][field] = val;
        var target = document.querySelector('#signatureTableRow [data-sig-idx="' + idx + '"][data-sig-field="' + field + '"]');
        if (target) {
            if (field === 'name') {
                var isUnderline = val && val.trim().charAt(0) !== '(';
                target.innerHTML = isUnderline ? '<u>' + escapeHtml(val) + '</u>' : escapeHtml(val);
            } else {
                target.textContent = val;
            }
        }
    };

    window.addSignatureColumn = function() {
        if (signatures.length >= 5) {
            alert('Maksimal 5 kolom tanda tangan.');
            return;
        }
        signatures.push({
            title: 'Mengetahui,',
            role: 'Jabatan',
            name: '( ........................................ )',
            date: 'Tgl: ................................'
        });
        renderSignatures();
    };

    window.removeSignatureColumn = function(idx) {
        signatures.splice(idx, 1);
        renderSignatures();
    };

    window.resetAllPrintEdits = function() {
        if (!confirm('Reset semua perubahan teks dan pengaturan cetak ke default?')) {
            return;
        }
        // Restore editable DOM elements
        initialEditableSnapshots.forEach(function(item) {
            item.el.innerHTML = item.html;
        });

        // Reset layout inputs
        document.getElementById('optPaperSize').value = 'A4';
        document.getElementById('optOrientation').value = 'portrait';
        document.getElementById('optFontSize').value = '9.5';
        document.getElementById('optTheme').value = 'default';
        document.getElementById('optMarginV').value = '12';
        document.getElementById('optMarginH').value = '10';

        // Reset visibility checkboxes
        document.getElementById('chkProjectInfo').checked = true;
        document.getElementById('chkCatTotals').checked = true;
        document.getElementById('chkPpnSummary').checked = true;
        document.getElementById('chkSignatures').checked = true;
        document.getElementById('chkBreakdownCols').checked = false;
        document.getElementById('chkSelisihCol').checked = false;
        document.getElementById('chkNotesBox').checked = false;
        document.getElementById('chkPlaceDate').checked = false;
        document.getElementById('optPlaceDateText').value = document.getElementById('signaturePlaceDate').textContent.trim();

        // Reset signatures
        signatures = JSON.parse(JSON.stringify(defaultSignatures));
        renderSignatures();

        // Turn off edit mode
        toggleDirectEditMode(false);
        applyPrintLayout();
        applyVisibilityOptions(false);
    };

    window.triggerRapPrint = function() {
        // Blur any active editable field so caret/outline does not appear in PDF
        if (document.activeElement && typeof document.activeElement.blur === 'function') {
            document.activeElement.blur();
        }
        window.print();
    };

    window.closePreviewWindow = function() {
        if (window.parent && window.parent !== window) {
            var modalEl = window.parent.document.getElementById('pdfPreviewModalRap');
            if (modalEl && window.parent.bootstrap && window.parent.bootstrap.Modal) {
                var inst = window.parent.bootstrap.Modal.getInstance(modalEl);
                if (inst) {
                    inst.hide();
                    return;
                }
            }
        }
        window.close();
    };

    // Initialize on load
    captureInitialSnapshots();
    renderSignatures();
    applyPrintLayout();
    applyVisibilityOptions(false);
})();
</script>

</body>
</html>
