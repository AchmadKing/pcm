<?php
/**
 * Migration Script: Reset AHSP RAP Details unit_price to NULL
 * 
 * This script fixes an issue where the buggy syncRapItemToAhsp() function
 * was setting d.unit_price = i.price, which prevented AHSP from tracking
 * item price changes.
 * 
 * After running this:
 * - All AHSP details will use COALESCE(NULL, i.price) = item price
 * - Item price changes will immediately reflect in AHSP calculations
 * 
 * Run from CLI: php migrations/fix_ahsp_rap_unit_price.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=== AHSP RAP Details Unit Price Fix ===\n\n";

try {
    // Count affected records
    $count = dbGetRow("SELECT COUNT(*) as cnt FROM project_ahsp_details_rap WHERE unit_price IS NOT NULL");
    echo "Found " . $count['cnt'] . " AHSP RAP details with non-NULL unit_price.\n";
    
    if ($count['cnt'] == 0) {
        echo "No fix needed. All records already have NULL unit_price.\n";
        exit(0);
    }
    
    // Show sample of affected records
    echo "\nSample of affected records:\n";
    $samples = dbGetAll("
        SELECT d.id, d.ahsp_id, d.unit_price as detail_price, i.price as item_price, i.name as item_name
        FROM project_ahsp_details_rap d
        JOIN project_items_rap i ON d.item_id = i.id
        WHERE d.unit_price IS NOT NULL
        LIMIT 5
    ");
    
    foreach ($samples as $sample) {
        echo "  - Detail #{$sample['id']}: {$sample['item_name']} " .
             "(Detail: " . number_format($sample['detail_price']) . 
             ", Item: " . number_format($sample['item_price']) . ")\n";
    }
    
    // Confirm before proceeding
    echo "\nThis will set unit_price = NULL for all " . $count['cnt'] . " records.\n";
    echo "AHSP will then use current item prices instead of fixed values.\n";
    echo "Continue? (y/n): ";
    
    $response = trim(fgets(STDIN));
    if (strtolower($response) !== 'y') {
        echo "Aborted.\n";
        exit(1);
    }
    
    // Execute the fix
    dbExecute("UPDATE project_ahsp_details_rap SET unit_price = NULL WHERE unit_price IS NOT NULL");
    
    echo "\n✓ Reset " . $count['cnt'] . " records to NULL.\n";
    
    // Recalculate all AHSP RAP totals
    echo "\nRecalculating AHSP RAP totals...\n";
    
    $ahspList = dbGetAll("SELECT id FROM project_ahsp_rap");
    $updated = 0;
    
    foreach ($ahspList as $ahsp) {
        recalculateRapAhspPrice($ahsp['id']);
        $updated++;
    }
    
    echo "✓ Recalculated " . $updated . " AHSP RAP entries.\n";
    echo "\n=== Fix Complete ===\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
