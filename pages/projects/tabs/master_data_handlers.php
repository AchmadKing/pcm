<?php
/**
 * Master Data POST Handlers (Early execution)
 * This file is included from view.php BEFORE HTML output
 * Handles clear_all_items, clear_all_ahsp, and related master data actions
 * Variables available: $projectId, $project (from view.php)
 */

// Only proceed if admin and project is editable
if (!function_exists('isAdmin') || !isAdmin()) {
    return;
}

$isEditable = ($project['status'] === 'draft');
if (!$isEditable) {
    return;
}

$action = $_POST['action'] ?? '';

// Define master data actions that should be handled here
$masterDataActions = [
    'clear_all_items', 'clear_all_ahsp', 
    'delete_item', 'delete_ahsp', 'delete_ahsp_detail',
    'clear_all_items_rap', 'clear_all_ahsp_rap',
    'delete_item_rap', 'delete_ahsp_rap', 'delete_ahsp_detail_rap'
];

if (!in_array($action, $masterDataActions)) {
    return;
}

// Helper function: recalculate AHSP price
if (!function_exists('recalculateAhspPrice')) {
    function recalculateAhspPrice($ahspId) {
        $total = dbGetRow("
            SELECT COALESCE(SUM(d.coefficient * COALESCE(d.unit_price, i.price)), 0) as total
            FROM project_ahsp_details d
            JOIN project_items i ON d.item_id = i.id
            WHERE d.ahsp_id = ?
        ", [$ahspId]);
        dbExecute("UPDATE project_ahsp SET unit_price = ? WHERE id = ?", [$total['total'] ?? 0, $ahspId]);
    }
}

// Helper function: recalculate RAP AHSP price
if (!function_exists('recalculateRapAhspPrice')) {
    function recalculateRapAhspPrice($ahspId) {
        $total = dbGetRow("
            SELECT COALESCE(SUM(d.coefficient * COALESCE(d.unit_price, i.price)), 0) as total
            FROM project_ahsp_details_rap d
            JOIN project_items_rap i ON d.item_id = i.id
            WHERE d.ahsp_id = ?
        ", [$ahspId]);
        dbExecute("UPDATE project_ahsp_rap SET unit_price = ? WHERE id = ?", [$total['total'] ?? 0, $ahspId]);
    }
}

try {
    switch ($action) {
        // ========== RAB ITEMS ==========
        case 'delete_item':
            $itemId = $_POST['item_id'];
            dbExecute("DELETE FROM project_items WHERE id = ? AND project_id = ?", [$itemId, $projectId]);
            setFlash('success', 'Item berhasil dihapus!');
            break;
            
        case 'clear_all_items':
            // Delete all AHSP details for this project first (foreign key)
            dbExecute("
                DELETE d FROM project_ahsp_details d
                INNER JOIN project_items i ON d.item_id = i.id
                WHERE i.project_id = ?
            ", [$projectId]);
            
            // Delete all items
            dbExecute("DELETE FROM project_items WHERE project_id = ?", [$projectId]);
            
            // Recalculate all AHSP prices (set to 0 since no items)
            dbExecute("UPDATE project_ahsp SET unit_price = 0 WHERE project_id = ?", [$projectId]);
            
            // === Also delete RAP Items ===
            dbExecute("
                DELETE d FROM project_ahsp_details_rap d
                INNER JOIN project_ahsp_rap a ON d.ahsp_id = a.id
                WHERE a.project_id = ?
            ", [$projectId]);
            
            dbExecute("DELETE FROM project_items_rap WHERE project_id = ?", [$projectId]);
            dbExecute("UPDATE project_ahsp_rap SET unit_price = 0 WHERE project_id = ?", [$projectId]);
            
            setFlash('success', "Semua items RAB dan RAP berhasil dihapus!");
            break;
            
        // ========== RAB AHSP ==========
        case 'delete_ahsp':
            $ahspId = $_POST['ahsp_id'];
            // Delete details first
            dbExecute("DELETE FROM project_ahsp_details WHERE ahsp_id = ?", [$ahspId]);
            // Delete AHSP
            dbExecute("DELETE FROM project_ahsp WHERE id = ? AND project_id = ?", [$ahspId, $projectId]);
            setFlash('success', 'AHSP berhasil dihapus!');
            break;
            
        case 'delete_ahsp_detail':
            $detailId = $_POST['detail_id'];
            $ahspId = $_POST['ahsp_id'];
            dbExecute("DELETE FROM project_ahsp_details WHERE id = ?", [$detailId]);
            recalculateAhspPrice($ahspId);
            setFlash('success', 'Komponen berhasil dihapus!');
            break;
            
        case 'clear_all_ahsp':
            // Delete in correct order due to foreign key constraints
            dbExecute("
                DELETE rad FROM rap_ahsp_details rad
                INNER JOIN rap_items rap ON rad.rap_item_id = rap.id
                INNER JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
                INNER JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                WHERE pa.project_id = ?
            ", [$projectId]);
            
            dbExecute("
                DELETE rap FROM rap_items rap
                INNER JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
                INNER JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                WHERE pa.project_id = ?
            ", [$projectId]);
            
            dbExecute("
                DELETE rs FROM rab_subcategories rs
                INNER JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                WHERE pa.project_id = ?
            ", [$projectId]);
            
            dbExecute("
                DELETE d FROM project_ahsp_details d
                INNER JOIN project_ahsp a ON d.ahsp_id = a.id
                WHERE a.project_id = ?
            ", [$projectId]);
            
            dbExecute("DELETE FROM project_ahsp WHERE project_id = ?", [$projectId]);
            
            // === Also delete RAP AHSP ===
            dbExecute("
                DELETE d FROM project_ahsp_details_rap d
                INNER JOIN project_ahsp_rap a ON d.ahsp_id = a.id
                WHERE a.project_id = ?
            ", [$projectId]);
            
            dbExecute("DELETE FROM project_ahsp_rap WHERE project_id = ?", [$projectId]);
            
            setFlash('success', "Semua AHSP RAB dan RAP berhasil dihapus!");
            break;
            
        // ========== RAP ITEMS ==========
        case 'delete_item_rap':
            $itemId = $_POST['item_id'];
            dbExecute("DELETE FROM project_items_rap WHERE id = ? AND project_id = ?", [$itemId, $projectId]);
            setFlash('success', 'Item RAP berhasil dihapus!');
            break;
            
        case 'clear_all_items_rap':
            // Delete RAP AHSP details first
            dbExecute("
                DELETE d FROM project_ahsp_details_rap d
                INNER JOIN project_ahsp_rap a ON d.ahsp_id = a.id
                WHERE a.project_id = ?
            ", [$projectId]);
            
            dbExecute("DELETE FROM project_items_rap WHERE project_id = ?", [$projectId]);
            dbExecute("UPDATE project_ahsp_rap SET unit_price = 0 WHERE project_id = ?", [$projectId]);
            
            // === Also delete RAB Items ===
            dbExecute("
                DELETE d FROM project_ahsp_details d
                INNER JOIN project_items i ON d.item_id = i.id
                WHERE i.project_id = ?
            ", [$projectId]);
            
            dbExecute("DELETE FROM project_items WHERE project_id = ?", [$projectId]);
            dbExecute("UPDATE project_ahsp SET unit_price = 0 WHERE project_id = ?", [$projectId]);
            
            setFlash('success', "Semua items RAP dan RAB berhasil dihapus!");
            break;
            
        // ========== RAP AHSP ==========
        case 'delete_ahsp_rap':
            $ahspId = $_POST['ahsp_id'];
            dbExecute("DELETE FROM project_ahsp_details_rap WHERE ahsp_id = ?", [$ahspId]);
            dbExecute("DELETE FROM project_ahsp_rap WHERE id = ? AND project_id = ?", [$ahspId, $projectId]);
            setFlash('success', 'AHSP RAP berhasil dihapus!');
            break;
            
        case 'delete_ahsp_detail_rap':
            $detailId = $_POST['detail_id'];
            $ahspId = $_POST['ahsp_id'];
            dbExecute("DELETE FROM project_ahsp_details_rap WHERE id = ?", [$detailId]);
            recalculateRapAhspPrice($ahspId);
            setFlash('success', 'Komponen AHSP RAP berhasil dihapus!');
            break;
            
        case 'clear_all_ahsp_rap':
            // Delete RAP AHSP details first
            dbExecute("
                DELETE d FROM project_ahsp_details_rap d
                INNER JOIN project_ahsp_rap a ON d.ahsp_id = a.id
                WHERE a.project_id = ?
            ", [$projectId]);
            
            // Delete RAP AHSP
            dbExecute("DELETE FROM project_ahsp_rap WHERE project_id = ?", [$projectId]);
            
            // === Also delete RAB AHSP ===
            dbExecute("
                DELETE rad FROM rap_ahsp_details rad
                INNER JOIN rap_items rap ON rad.rap_item_id = rap.id
                INNER JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
                INNER JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                WHERE pa.project_id = ?
            ", [$projectId]);
            
            dbExecute("
                DELETE rap FROM rap_items rap
                INNER JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
                INNER JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                WHERE pa.project_id = ?
            ", [$projectId]);
            
            dbExecute("
                DELETE rs FROM rab_subcategories rs
                INNER JOIN project_ahsp pa ON rs.ahsp_id = pa.id
                WHERE pa.project_id = ?
            ", [$projectId]);
            
            dbExecute("
                DELETE d FROM project_ahsp_details d
                INNER JOIN project_ahsp a ON d.ahsp_id = a.id
                WHERE a.project_id = ?
            ", [$projectId]);
            
            dbExecute("DELETE FROM project_ahsp WHERE project_id = ?", [$projectId]);
            
            setFlash('success', "Semua AHSP RAP dan RAB berhasil dihapus!");
            break;
    }
} catch (Exception $e) {
    setFlash('error', 'Error: ' . $e->getMessage());
}

// Determine correct subtab based on action
$subtab = '';
if (strpos($action, 'ahsp_rap') !== false || strpos($action, 'ahsp_detail_rap') !== false) {
    $subtab = '&subtab=ahsp_rap';
} elseif (strpos($action, 'item_rap') !== false || strpos($action, 'items_rap') !== false) {
    $subtab = '&subtab=items_rap';
} elseif (strpos($action, 'ahsp') !== false) {
    $subtab = '&subtab=ahsp';
}

header('Location: view.php?id=' . $projectId . '&tab=master' . $subtab);
exit;
