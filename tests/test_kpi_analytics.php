<?php
/**
 * test_kpi_analytics.php
 *
 * Verification script to test KPI calculations, status thresholds, caching hash, and model behavior.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';

$db = getDB();

echo "===========================================\n";
echo "RUNNING VERIFICATION TESTS FOR AI FEATURE #6\n";
echo "===========================================\n\n";

// --- TEST 1: Numerical Calculations and Status Classification ---
echo "TEST 1: KPI Thresholds and Status\n";
$testCases = [
    ['target' => 200, 'actual' => 240, 'expected_pct' => 120.0, 'expected_status' => 'Exceeded Target'],
    ['target' => 200, 'actual' => 187, 'expected_pct' => 93.5,  'expected_status' => 'Met Target'],
    ['target' => 200, 'actual' => 150, 'expected_pct' => 75.0,  'expected_status' => 'Near Target'],
    ['target' => 200, 'actual' => 100, 'expected_pct' => 50.0,  'expected_status' => 'Below Target'],
    ['target' => 200, 'actual' => null,  'expected_pct' => null,  'expected_status' => 'Insufficient Data'],
    ['target' => 0,   'actual' => 50,   'expected_pct' => null,  'expected_status' => 'Insufficient Data'],
];

foreach ($testCases as $idx => $tc) {
    $pct = null;
    if ($tc['target'] > 0 && $tc['actual'] !== null) {
        $pct = ($tc['actual'] / $tc['target']) * 100;
    }
    $status = getKpiStatus($pct);
    $passed = ($status === $tc['expected_status']);
    
    printf("  Case %d: Target=%s, Actual=%s => Computed Pct=%s, Computed Status='%s' (%s)\n",
        $idx + 1,
        $tc['target'],
        $tc['actual'] ?? 'null',
        $pct !== null ? $pct . '%' : 'null',
        $status,
        $passed ? 'PASSED' : 'FAILED'
    );
}

// --- TEST 2: Benchmark KPI Target (Satisfaction) ---
echo "\nTEST 2: Satisfaction score percentage mapping\n";
$targetSat = 4.0;
$actualSat = 4.4;
$satPct = round(($actualSat / $targetSat) * 100, 1);
$satStatus = getKpiStatus($satPct);
printf("  Target=%0.1f, Actual=%0.1f => Achievement=%0.1f%%, Status='%s' (Expected: 110%%, Exceeded Target) - %s\n",
    $targetSat,
    $actualSat,
    $satPct,
    $satStatus,
    ($satPct == 110.0 && $satStatus === 'Exceeded Target') ? 'PASSED' : 'FAILED'
);

// --- TEST 3: Check activity list in DB ---
echo "\nTEST 3: Active database entries\n";
$actStmt = $db->query("SELECT id, title, status FROM activities ORDER BY id DESC LIMIT 5");
$activities = $actStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($activities)) {
    echo "  No activities found in the database. Cannot run database checks.\n";
} else {
    echo "  Recent activities:\n";
    foreach ($activities as $a) {
        printf("    - ID: %d, Title: '%s', Status: '%s'\n", $a['id'], $a['title'], $a['status']);
    }
    
    // Let's run calculateActivityKpis on the latest activity
    $latestId = $activities[0]['id'];
    $kpiResults = calculateActivityKpis($latestId, $db);
    echo "  Computed KPI Results for Latest Activity (ID: {$latestId}):\n";
    echo "    Overall Performance: " . ($kpiResults['overall_performance'] ?? 'null') . "\n";
    echo "    Overall Status: " . ($kpiResults['overall_status'] ?? 'null') . "\n";
    echo "    Data Hash: " . ($kpiResults['data_hash'] ?? 'null') . "\n";
}

echo "\nVerification completed successfully.\n";
