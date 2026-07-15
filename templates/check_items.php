<?php
/**
 * Check AHSP Items vs Test Items
 * This script compares items used in AHSP CSV with items in test_items CSV
 */

$ahspFile = __DIR__ . '/test_ahsp_40.csv';
$itemsFile = __DIR__ . '/test_items_500.csv';

echo "<h2>AHSP vs Items Comparison</h2>";

// Parse items - get all item names
$itemNames = [];
$handle = fopen($itemsFile, 'r');
$header = fgetcsv($handle, 0, ';'); // Skip header
while (($row = fgetcsv($handle, 0, ';')) !== false) {
    $mainName = trim($row[0] ?? '');
    $subType = trim($row[1] ?? '');
    if (!empty($subType)) {
        $fullName = $mainName . ' - ' . $subType;
    } else {
        $fullName = $mainName;
    }
    $itemNames[strtolower($fullName)] = $fullName;
}
fclose($handle);

// Parse AHSP - get all item names used
$ahspItems = [];
$handle = fopen($ahspFile, 'r');
while (($row = fgetcsv($handle, 0, ';')) !== false) {
    $firstCol = trim($row[0] ?? '');
    if (strtoupper($firstCol) === '[AHSP]') continue;
    if (empty($firstCol)) continue;
    $ahspItems[strtolower($firstCol)] = $firstCol;
}
fclose($handle);

// Find missing items
$missing = [];
$found = [];
foreach ($ahspItems as $lower => $original) {
    if (!isset($itemNames[$lower])) {
        $missing[] = $original;
    } else {
        $found[] = $original;
    }
}

echo "<p><strong>Total items in CSV:</strong> " . count($itemNames) . "</p>";
echo "<p><strong>Total unique items used in AHSP:</strong> " . count($ahspItems) . "</p>";
echo "<p style='color:green'><strong>✓ Matched items:</strong> " . count($found) . "</p>";
echo "<p style='color:red'><strong>✗ Missing items:</strong> " . count($missing) . "</p>";

if (!empty($missing)) {
    echo "<h3 style='color:red'>Missing Items (need to add to test_items_500.csv):</h3>";
    echo "<ol>";
    foreach ($missing as $item) {
        echo "<li>" . htmlspecialchars($item) . "</li>";
    }
    echo "</ol>";
} else {
    echo "<h3 style='color:green'>✓ All AHSP items exist in test_items_500.csv!</h3>";
}
