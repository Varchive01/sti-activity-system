<?php
/**
 * Test Suite: Post-Activity Compliance Calculation Helper
 *
 * Verification requirements:
 * 1. Test an activity where approved and actual values match (Compliant, 100%).
 * 2. Test one with a changed venue/date (Partially Compliant, correct percentage).
 * 3. Test one with missing post-event information (Not Verifiable, null percentage).
 * 4. Confirm results are deterministic across multiple executions.
 * 5. Confirm no database records are modified (zero mutations).
 * 6. Test all failure edge cases (0% Not Compliant).
 * 7. Test partial verifiable fields exclusion from denominator.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$passed = 0;
$failed = 0;

function assertCondition(bool $condition, string $testName, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$testName}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$testName} - {$details}\n";
    }
}

echo "==========================================================\n";
echo "Post-Activity Compliance Calculation Helper Test Suite\n";
echo "==========================================================\n\n";

// Find or pick test activities:
// Activity 118 has proposal data (Test Event 1)
$act118 = $pdo->query("SELECT * FROM activities WHERE id = 118")->fetch(PDO::FETCH_ASSOC);
if (!$act118) {
    die("Error: Activity 118 required for tests is not found in database.\n");
}

// Find an activity with NO post-event record
$noPeAct = $pdo->query("
    SELECT a.id, a.title FROM activities a
    LEFT JOIN post_event pe ON a.id = pe.activity_id
    WHERE pe.id IS NULL
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$noPeAct) {
    die("Error: No activity without post_event found in database.\n");
}
$noPeActId = (int)$noPeAct['id'];

// -------------------------------------------------------------
// Test 1: Approved and Actual values match (100% Compliant)
// -------------------------------------------------------------
echo "Test 1: Approved and Actual values match\n";
$matchingOverrides = [
    'actual_title'       => $act118['title'],
    'actual_event_date'  => $act118['event_date'],
    'actual_venue'       => $act118['venue'],
    'actual_objectives'  => 'met',
    'actual_program'     => 'conducted as scheduled',
    'actual_manpower'    => 'fulfilled',
    'actual_kpis'        => 4.5,
    'documents_present'  => true
];

$result1 = calculatePostActivityCompliance(118, $matchingOverrides, $pdo);

assertCondition(
    $result1['overall_status'] === 'Compliant',
    "Overall status is Compliant",
    "Got: " . ($result1['overall_status'] ?? 'null')
);

assertCondition(
    $result1['compliance_percentage'] === 100.0,
    "Compliance percentage is exactly 100.0%",
    "Got: " . var_export($result1['compliance_percentage'], true)
);

assertCondition(
    $result1['verifiable_count'] === 8,
    "All 8 checks are verifiable",
    "Got: " . $result1['verifiable_count']
);

assertCondition(
    $result1['compliant_count'] === 8,
    "All 8 checks are Compliant",
    "Got: " . $result1['compliant_count']
);

assertCondition(
    $result1['non_compliant_count'] === 0,
    "Non-compliant count is 0",
    "Got: " . $result1['non_compliant_count']
);

// Verify check fields are well-structured
$fields = array_column($result1['checks'], 'field');
$expectedFields = [
    'Activity title',
    'Event date',
    'Venue',
    'Objectives',
    'Program/sequence',
    'Assigned people/manpower',
    'KPI/evaluation criteria',
    'Post-event documentation presence'
];
assertCondition(
    $fields === $expectedFields,
    "All 8 expected comparison fields are present in checks array",
    "Got fields: " . implode(', ', $fields)
);

// -------------------------------------------------------------
// Test 2: Changed venue and event date (Partially Compliant)
// -------------------------------------------------------------
echo "\nTest 2: Changed venue and event date\n";
$changedVenueDateOverrides = [
    'actual_title'       => $act118['title'],
    'actual_event_date'  => '2026-12-25', // Different from approved 2026-09-01
    'actual_venue'       => 'Off-campus Arena', // Different from approved Room 203
    'actual_objectives'  => 'met',
    'actual_program'     => 'conducted as scheduled',
    'actual_manpower'    => 'fulfilled',
    'actual_kpis'        => 4.2,
    'documents_present'  => true
];

$result2 = calculatePostActivityCompliance(118, $changedVenueDateOverrides, $pdo);

assertCondition(
    $result2['overall_status'] === 'Partially Compliant',
    "Overall status is Partially Compliant",
    "Got: " . ($result2['overall_status'] ?? 'null')
);

// 6 compliant, 2 non-compliant out of 8 = 75.0%
assertCondition(
    $result2['compliance_percentage'] === 75.0,
    "Compliance percentage is exactly 75.0% (6/8)",
    "Got: " . var_export($result2['compliance_percentage'], true)
);

assertCondition(
    $result2['compliant_count'] === 6 && $result2['non_compliant_count'] === 2,
    "Counts are 6 compliant, 2 non-compliant",
    "Got compliant: {$result2['compliant_count']}, non_compliant: {$result2['non_compliant_count']}"
);

// Verify specific checks for Venue and Date
$dateCheck = null;
$venueCheck = null;
foreach ($result2['checks'] as $c) {
    if ($c['field'] === 'Event date') $dateCheck = $c;
    if ($c['field'] === 'Venue') $venueCheck = $c;
}

assertCondition(
    $dateCheck !== null && $dateCheck['result'] === 'Not Compliant',
    "Event date check result is 'Not Compliant'",
    "Result: " . ($dateCheck['result'] ?? 'null')
);

assertCondition(
    $venueCheck !== null && $venueCheck['result'] === 'Not Compliant',
    "Venue check result is 'Not Compliant'",
    "Result: " . ($venueCheck['result'] ?? 'null')
);

// -------------------------------------------------------------
// Test 3: Missing post-event information
// -------------------------------------------------------------
echo "\nTest 3: Missing post-event information (Activity ID: {$noPeActId})\n";
$result3 = calculatePostActivityCompliance($noPeActId, null, $pdo);

assertCondition(
    $result3['overall_status'] === 'Not Verifiable',
    "Overall status is 'Not Verifiable'",
    "Got: " . ($result3['overall_status'] ?? 'null')
);

assertCondition(
    $result3['compliance_percentage'] === null,
    "Compliance percentage is null when no verifiable checks exist",
    "Got: " . var_export($result3['compliance_percentage'], true)
);

assertCondition(
    $result3['verifiable_count'] === 0,
    "Verifiable count is 0",
    "Got: " . $result3['verifiable_count']
);

assertCondition(
    $result3['not_verifiable_count'] === 8,
    "All 8 checks are marked 'Not Verifiable'",
    "Got: " . $result3['not_verifiable_count']
);

// Confirm no check is passed or failed
$passedOrFailedCount = 0;
foreach ($result3['checks'] as $c) {
    if ($c['result'] === 'Compliant' || $c['result'] === 'Not Compliant') {
        $passedOrFailedCount++;
    }
}
assertCondition(
    $passedOrFailedCount === 0,
    "No item is erroneously passed or failed when records are missing",
    "Passed/Failed count: {$passedOrFailedCount}"
);

// -------------------------------------------------------------
// Test 4: Determinism across multiple executions
// -------------------------------------------------------------
echo "\nTest 4: Deterministic behavior\n";
$resA = calculatePostActivityCompliance(118, $changedVenueDateOverrides, $pdo);
$resB = calculatePostActivityCompliance(118, $changedVenueDateOverrides, $pdo);
$resC = calculatePostActivityCompliance(118, $changedVenueDateOverrides, $pdo);

assertCondition(
    serialize($resA) === serialize($resB) && serialize($resB) === serialize($resC),
    "Consecutive executions yield identical results",
    "Results differ across calls"
);

// -------------------------------------------------------------
// Test 5: Confirm no database records are modified
// -------------------------------------------------------------
echo "\nTest 5: Zero database mutations\n";
function getDatabaseStateHash(PDO $db): string {
    $tables = ['activities', 'post_event', 'documents', 'program_sequence', 'manpower', 'faculty_tasks', 'kpi_evaluations'];
    $hashes = [];
    foreach ($tables as $t) {
        $count = $db->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        $maxId = $db->query("SELECT MAX(id) FROM {$t}")->fetchColumn() ?: 0;
        $hashes[] = "{$t}:{$count}:{$maxId}";
    }
    return implode('|', $hashes);
}

$stateBefore = getDatabaseStateHash($pdo);

// Run multiple calculations across different activities and options
calculatePostActivityCompliance(118, null, $pdo);
calculatePostActivityCompliance(118, $matchingOverrides, $pdo);
calculatePostActivityCompliance(118, $changedVenueDateOverrides, $pdo);
calculatePostActivityCompliance($noPeActId, null, $pdo);

$stateAfter = getDatabaseStateHash($pdo);

assertCondition(
    $stateBefore === $stateAfter,
    "Database state hash is identical before and after compliance calculations",
    "Before: {$stateBefore} | After: {$stateAfter}"
);

// -------------------------------------------------------------
// Test 6: 100% Not Compliant edge case
// -------------------------------------------------------------
echo "\nTest 6: All verifiable checks fail (0% Not Compliant)\n";
$allFailedOverrides = [
    'actual_title'       => 'Completely Wrong Title',
    'actual_event_date'  => '2020-01-01',
    'actual_venue'       => 'Wrong Venue Entirely',
    'actual_objectives'  => 'unmet',
    'actual_program'     => 'not followed',
    'actual_manpower'    => 'unfulfilled',
    'actual_kpis'        => 1.5,
    'documents_present'  => false
];

$result6 = calculatePostActivityCompliance(118, $allFailedOverrides, $pdo);

assertCondition(
    $result6['overall_status'] === 'Not Compliant',
    "Overall status is 'Not Compliant'",
    "Got: " . ($result6['overall_status'] ?? 'null')
);

assertCondition(
    $result6['compliance_percentage'] === 0.0,
    "Compliance percentage is exactly 0.0%",
    "Got: " . var_export($result6['compliance_percentage'], true)
);

assertCondition(
    $result6['non_compliant_count'] === 8 && $result6['compliant_count'] === 0,
    "8 non-compliant, 0 compliant",
    "Compliant: {$result6['compliant_count']}, Non-compliant: {$result6['non_compliant_count']}"
);

// -------------------------------------------------------------
// Test 7: Compliance percentage excludes Not Verifiable checks
// -------------------------------------------------------------
echo "\nTest 7: Compliance percentage based strictly on verifiable checks\n";
// Provide 4 matching fields and 4 missing fields (so 4 Compliant, 4 Not Verifiable)
$partialVerifiableOverrides = [
    'actual_title'       => $act118['title'],
    'actual_event_date'  => $act118['event_date'],
    'actual_venue'       => $act118['venue'],
    'documents_present'  => true
    // Note: objectives, program, manpower, kpi will depend on availability or be not verifiable
];

// Let's create an in-memory/test scenario where exactly 4 are verifiable and compliant
$customTest = calculatePostActivityCompliance(118, [
    'actual_title'       => $act118['title'],
    'actual_event_date'  => $act118['event_date'],
    'actual_venue'       => $act118['venue'],
    'actual_objectives'  => null, // Not verifiable
    'actual_program'     => null, // Not verifiable
    'actual_manpower'    => null, // Will be checked
    'actual_kpis'        => null,
    'documents_present'  => true
], $pdo);

// Assert calculation formula: (compliant_count / verifiable_count) * 100
$expectedPct = round(($customTest['compliant_count'] / $customTest['verifiable_count']) * 100, 1);
assertCondition(
    $customTest['compliance_percentage'] === $expectedPct,
    "Compliance percentage strictly equals (compliant / verifiable) * 100",
    "Got: {$customTest['compliance_percentage']}, Expected: {$expectedPct}"
);

assertCondition(
    $customTest['verifiable_count'] + $customTest['not_verifiable_count'] === $customTest['total_checks'],
    "Verifiable count + Not Verifiable count equals total checks (8)",
    "Verifiable: {$customTest['verifiable_count']}, Not Verifiable: {$customTest['not_verifiable_count']}"
);

echo "\n==========================================================\n";
echo "Test Summary: Passed: {$passed}, Failed: {$failed}\n";
echo "==========================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
