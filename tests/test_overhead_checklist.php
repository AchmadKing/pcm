<?php
/**
 * Test Suite for Overhead & Profit Checklist Feature
 * Validates:
 * 1. Functions & Helpers (isProjectOverheadEnabled, getProjectOverheadProfitPct, formatOverheadProfitLabel)
 * 2. All 8 scope combinations across AHSP, RAB, RAP
 * 3. Realtime Stats calculation with different toggle states
 * 4. Database schema migration
 * 5. Snapshot creation and immutability
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$passed = 0;
$failed = 0;

function assertTest($condition, $testName, $details = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] $testName\n";
    } else {
        $failed++;
        echo "[FAIL] $testName" . ($details ? " - $details" : "") . "\n";
    }
}

echo "====================================================\n";
echo "TEST SUITE: Overhead & Profit Checklist per Module\n";
echo "====================================================\n\n";

// ---------------------------------------------------------
// 1. UNIT TESTS FOR HELPER FUNCTIONS
// ---------------------------------------------------------
echo "--- 1. Testing Helper Functions ---\n";

$mockProject = [
    'overhead_percentage' => 10,
    'profit_percentage' => 5,
    'overhead_apply_ahsp' => 1,
    'overhead_apply_rab' => 0,
    'overhead_apply_rap' => 1,
];

// Test isProjectOverheadEnabled
assertTest(isProjectOverheadEnabled($mockProject, 'ahsp') === true, "isProjectOverheadEnabled('ahsp') returns true for 1");
assertTest(isProjectOverheadEnabled($mockProject, 'rab') === false, "isProjectOverheadEnabled('rab') returns false for 0");
assertTest(isProjectOverheadEnabled($mockProject, 'rap') === true, "isProjectOverheadEnabled('rap') returns true for 1");

// Test default when column missing (backward compatibility)
$legacyProject = [
    'overhead_percentage' => 12,
    'profit_percentage' => 3
];
assertTest(isProjectOverheadEnabled($legacyProject, 'ahsp') === true, "isProjectOverheadEnabled defaults to true when column missing (AHSP)");
assertTest(isProjectOverheadEnabled($legacyProject, 'rab') === true, "isProjectOverheadEnabled defaults to true when column missing (RAB)");
assertTest(isProjectOverheadEnabled($legacyProject, 'rap') === true, "isProjectOverheadEnabled defaults to true when column missing (RAP)");

// Test getProjectOverheadProfitPct with scopes
assertTest(getProjectOverheadProfitPct($mockProject, 'ahsp') === 15.0, "getProjectOverheadProfitPct('ahsp') returns 15.0% when enabled");
assertTest(getProjectOverheadProfitPct($mockProject, 'rab') === 0.0, "getProjectOverheadProfitPct('rab') returns 0.0% when disabled");
assertTest(getProjectOverheadProfitPct($mockProject, 'rap') === 15.0, "getProjectOverheadProfitPct('rap') returns 15.0% when enabled");
assertTest(getProjectOverheadProfitPct($mockProject) === 15.0, "getProjectOverheadProfitPct(null) returns base total 15.0%");

// Test formatOverheadProfitLabel
assertTest(strpos(formatOverheadProfitLabel($mockProject, 'ahsp'), '15%') !== false, "formatOverheadProfitLabel('ahsp') shows 15%");
assertTest(strpos(formatOverheadProfitLabel($mockProject, 'rab'), 'Non-aktif') !== false, "formatOverheadProfitLabel('rab') shows Non-aktif");
assertTest(strpos(formatOverheadProfitLabel($mockProject, 'rap'), '15%') !== false, "formatOverheadProfitLabel('rap') shows 15%");

// ---------------------------------------------------------
// 2. TEST ALL 8 COMBINATIONS OF SCOPES
// ---------------------------------------------------------
echo "\n--- 2. Testing All 8 Combinations (AHSP, RAB, RAP) ---\n";

$combinations = [
    ['ahsp' => 1, 'rab' => 1, 'rap' => 1],
    ['ahsp' => 1, 'rab' => 1, 'rap' => 0],
    ['ahsp' => 1, 'rab' => 0, 'rap' => 1],
    ['ahsp' => 1, 'rab' => 0, 'rap' => 0],
    ['ahsp' => 0, 'rab' => 1, 'rap' => 1],
    ['ahsp' => 0, 'rab' => 1, 'rap' => 0],
    ['ahsp' => 0, 'rab' => 0, 'rap' => 1],
    ['ahsp' => 0, 'rab' => 0, 'rap' => 0],
];

foreach ($combinations as $c) {
    $p = [
        'overhead_percentage' => 8,
        'profit_percentage' => 4, // total 12%
        'overhead_apply_ahsp' => $c['ahsp'],
        'overhead_apply_rab' => $c['rab'],
        'overhead_apply_rap' => $c['rap']
    ];
    $code = "{$c['ahsp']}{$c['rab']}{$c['rap']}";
    $expectedAhsp = $c['ahsp'] ? 12.0 : 0.0;
    $expectedRab = $c['rab'] ? 12.0 : 0.0;
    $expectedRap = $c['rap'] ? 12.0 : 0.0;

    $actualAhsp = getProjectOverheadProfitPct($p, 'ahsp');
    $actualRab = getProjectOverheadProfitPct($p, 'rab');
    $actualRap = getProjectOverheadProfitPct($p, 'rap');

    assertTest(
        $actualAhsp === $expectedAhsp && $actualRab === $expectedRab && $actualRap === $expectedRap,
        "Combination [$code]: AHSP={$actualAhsp}%, RAB={$actualRab}%, RAP={$actualRap}%"
    );
}

// ---------------------------------------------------------
// 3. DATABASE SCHEMA & PERSISTENCE TEST
// ---------------------------------------------------------
echo "\n--- 3. Testing Database Columns and Persistence ---\n";

ensureOverheadApplyColumnsExist();

// Verify columns exist on projects
$projCols = dbGetAll("SHOW COLUMNS FROM projects LIKE 'overhead_apply_%'");
$projColNames = array_column($projCols, 'Field');
assertTest(in_array('overhead_apply_ahsp', $projColNames), "projects table has overhead_apply_ahsp");
assertTest(in_array('overhead_apply_rab', $projColNames), "projects table has overhead_apply_rab");
assertTest(in_array('overhead_apply_rap', $projColNames), "projects table has overhead_apply_rap");

// Verify columns exist on rab_snapshots
$snapCols = dbGetAll("SHOW COLUMNS FROM rab_snapshots LIKE 'overhead_apply_%'");
$snapColNames = array_column($snapCols, 'Field');
assertTest(in_array('overhead_apply_ahsp', $snapColNames), "rab_snapshots table has overhead_apply_ahsp");
assertTest(in_array('overhead_apply_rab', $snapColNames), "rab_snapshots table has overhead_apply_rab");
assertTest(in_array('overhead_apply_rap', $snapColNames), "rab_snapshots table has overhead_apply_rap");

// ---------------------------------------------------------
// 4. INTEGRATION TEST: REAL PROJECT & REALTIME STATS & SNAPSHOT
// ---------------------------------------------------------
echo "\n--- 4. Integration Test: Real Project, Stats & Snapshot ---\n";

$testProjCode = 'TEST_OH_' . time();
$db = getDB();

// Create a test project
$stmt = $db->prepare("
    INSERT INTO projects (name, status, overhead_percentage, profit_percentage, overhead_apply_ahsp, overhead_apply_rab, overhead_apply_rap, ppn_percentage, created_at)
    VALUES (?, 'draft', 10.00, 5.00, 1, 1, 0, 11.00, NOW())
");
$stmt->execute(['Test Overhead Project ' . time()]);
$testProjectId = $db->lastInsertId();

assertTest($testProjectId > 0, "Test project created with ID $testProjectId");

// Create Item & AHSP
$stmt = $db->prepare("INSERT INTO project_items (project_id, item_code, name, category, unit, price) VALUES (?, 'UPH-01', 'Pekerja', 'upah', 'OH', 10000)");
$stmt->execute([$testProjectId]);
$testItemId = $db->lastInsertId();

$stmt = $db->prepare("INSERT INTO project_ahsp (project_id, ahsp_code, work_name, unit, unit_price) VALUES (?, 'AHSP-01', 'Pekerjaan Pembersihan', 'm2', 10000)");
$stmt->execute([$testProjectId]);
$testAhspId = $db->lastInsertId();

$stmt = $db->prepare("INSERT INTO project_ahsp_details (ahsp_id, item_id, coefficient, unit_price) VALUES (?, ?, 1.0, 10000)");
$stmt->execute([$testAhspId, $testItemId]);

// Create Category and Subcategory
$stmt = $db->prepare("INSERT INTO rab_categories (project_id, code, name, sort_order) VALUES (?, '01', 'Pekerjaan Persiapan', 1)");
$stmt->execute([$testProjectId]);
$testCatId = $db->lastInsertId();

$stmt = $db->prepare("INSERT INTO rab_subcategories (category_id, code, name, unit, volume, unit_price, ahsp_id, sort_order) VALUES (?, '01.01', 'Pembersihan Lahan', 'm2', 100, 10000, ?, 1)");
$stmt->execute([$testCatId, $testAhspId]);
$testSubcatId = $db->lastInsertId();

// Base subtotal = 100 * 10,000 = 1,000,000
// RAB has OH+Profit ON (15%):
//   unit_price_rab = 10,000 * 1.15 = 11,500
//   subtotal_rab = 100 * 11,500 = 1,150,000
//   ppn_rab (11%) = 126,500
//   total_rab = 1,276,500
// RAP has OH+Profit OFF (0%):
//   unit_price_rap = 10,000 * 1.0 = 10,000
//   subtotal_rap = 100 * 10,000 = 1,000,000
//   total_rap = 1,000,000

$stats = calculateProjectRealtimeStats($testProjectId);
assertTest($stats !== null, "calculateProjectRealtimeStats returned data");
assertTest(abs($stats['total_rab'] - 1276500) < 0.01, "total_rab is 1,276,500 (RAB with 15% OH+Profit + 11% PPN)", "Got: " . ($stats['total_rab'] ?? 0));
assertTest(abs($stats['total_rap'] - 1000000) < 0.01, "total_rap is 1,000,000 (RAP with 0% OH+Profit)", "Got: " . ($stats['total_rap'] ?? 0));

// Test changing toggles: Turn RAB OFF, Turn RAP ON
$db->query("UPDATE projects SET overhead_apply_rab = 0, overhead_apply_rap = 1 WHERE id = $testProjectId");
$stats2 = calculateProjectRealtimeStats($testProjectId);
// Now RAB OH is OFF:
//   subtotal_rab = 1,000,000 -> with 11% PPN = 1,110,000
// RAP OH is ON (15%):
//   subtotal_rap = 1,150,000
assertTest(abs($stats2['total_rab'] - 1110000) < 0.01, "After toggle: total_rab is 1,110,000 (RAB with 0% OH + 11% PPN)", "Got: " . ($stats2['total_rab'] ?? 0));
assertTest(abs($stats2['total_rap'] - 1150000) < 0.01, "After toggle: total_rap is 1,150,000 (RAP with 15% OH)", "Got: " . ($stats2['total_rap'] ?? 0));

// ---------------------------------------------------------
// 5. TEST SNAPSHOT CREATION & IMMUTABILITY
// ---------------------------------------------------------
echo "\n--- 5. Testing Snapshot Creation & Immutability ---\n";

// Create Snapshot while project is (rab=0, rap=1, ahsp=1)
$firstUser = dbGetRow("SELECT id FROM users LIMIT 1");
$testUserId = $firstUser ? $firstUser['id'] : 1;

$stmt = $db->prepare("
    INSERT INTO rab_snapshots (project_id, name, description, overhead_percentage, profit_percentage, overhead_apply_ahsp, overhead_apply_rab, overhead_apply_rap, ppn_percentage, created_by)
    VALUES (?, 'Snapshot V1', 'Test Snapshot', 10.00, 5.00, 1, 0, 1, 11.00, ?)
");
$stmt->execute([$testProjectId, $testUserId]);
$snapshotId = $db->lastInsertId();

$snapRow = dbGetRow("SELECT * FROM rab_snapshots WHERE id = ?", [$snapshotId]);
assertTest($snapRow['overhead_apply_ahsp'] == 1, "Snapshot preserved overhead_apply_ahsp = 1");
assertTest($snapRow['overhead_apply_rab'] == 0, "Snapshot preserved overhead_apply_rab = 0");
assertTest($snapRow['overhead_apply_rap'] == 1, "Snapshot preserved overhead_apply_rap = 1");
assertTest(getProjectOverheadProfitPct($snapRow, 'rab') === 0.0, "Snapshot RAB overhead is 0% as captured");

// Now modify project flags to all 1
$db->query("UPDATE projects SET overhead_apply_ahsp = 1, overhead_apply_rab = 1, overhead_apply_rap = 1 WHERE id = $testProjectId");

// Re-fetch snapshot and ensure it did NOT change
$snapRowAfter = dbGetRow("SELECT * FROM rab_snapshots WHERE id = ?", [$snapshotId]);
assertTest($snapRowAfter['overhead_apply_rab'] == 0, "Snapshot remains immutable after project update (RAB=0)");
assertTest(getProjectOverheadProfitPct($snapRowAfter, 'rab') === 0.0, "Snapshot calculation remains immutable (0%)");

// Clean up test data
$db->query("DELETE FROM rab_snapshots WHERE project_id = $testProjectId");
$db->query("DELETE FROM rab_subcategories WHERE category_id = $testCatId");
$db->query("DELETE FROM rab_categories WHERE project_id = $testProjectId");
$db->query("DELETE FROM project_ahsp_details WHERE ahsp_id = $testAhspId");
$db->query("DELETE FROM project_ahsp WHERE id = $testAhspId");
$db->query("DELETE FROM project_items WHERE id = $testItemId");
$db->query("DELETE FROM projects WHERE id = $testProjectId");

echo "\n====================================================\n";
echo "SUMMARY: Passed: $passed, Failed: $failed\n";
echo "====================================================\n";

if ($failed > 0) {
    exit(1);
} else {
    exit(0);
}
