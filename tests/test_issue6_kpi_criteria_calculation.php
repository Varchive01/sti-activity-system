<?php
/**
 * test_issue6_kpi_criteria_calculation.php
 *
 * Automated verification test suite for Issue 6:
 * Calculate KPI results using the criteria defined in the proposal.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';

echo "=== Issue 6 Verification: Proposal-Defined KPI Criteria & Calculations ===\n\n";

$db = getDB();
$testsPassed = 0;
$totalTests = 6;

// TEST 1: KPI Target Metric Parser
echo "  [TEST 1] Testing parseKpiTargetMetric()...\n";
$p1 = parseKpiTargetMetric('500 Participants', 500);
$p2 = parseKpiTargetMetric('90%', 100);
$p3 = parseKpiTargetMetric('Rating: 4.5');
$p4 = parseKpiTargetMetric('4.0 / 5.0');
$p5 = parseKpiTargetMetric('3.5 out of 4.0');

if ($p1['calc_target'] == 500 && $p2['calc_target'] == 90 && $p3['calc_target'] == 4.5 && $p4['calc_target'] == 4.0 && $p5['calc_target'] == 3.5) {
    echo "  [PASS] Target metrics accurately parsed (Numeric, Percentage, Rating, Ratio)\n";
    $testsPassed++;
} else {
    echo "  [FAIL] Target metric parser produced unexpected results\n";
    print_r([$p1, $p2, $p3, $p4, $p5]);
}

// Setup a clean test activity for proposal criteria evaluation
$facultyUser = $db->query("SELECT id FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$facultyId = $facultyUser ? (int)$facultyUser['id'] : 1;

$title = 'Test Issue 6 KPI Activity ' . time();
$db->prepare("
    INSERT INTO activities (faculty_id, title, target_participants, event_date, status, source) 
    VALUES (?, ?, 100, CURDATE(), 'completed', 'faculty')
")->execute([$facultyId, $title]);
$testActId = (int)$db->lastInsertId();

// TEST 2: Calculate with defined proposal criteria in kpi_evaluations
echo "\n  [TEST 2] Proposal-Defined KPI Criteria Evaluation...\n";
$db->prepare("INSERT INTO kpi_evaluations (activity_id, indicator, target_metric, evaluation_method) VALUES (?, ?, ?, ?)")
    ->execute([$testActId, 'Participant Attendance Turnout', '90 Attendees', 'Paper Attendance List']);

$db->prepare("INSERT INTO kpi_evaluations (activity_id, indicator, target_metric, evaluation_method) VALUES (?, ?, ?, ?)")
    ->execute([$testActId, 'Student Evaluation Satisfaction', 'Rating: 4.5', 'Participant Evaluation Survey']);

// Simulate post_event with paper_list attendance
$db->prepare("
    INSERT INTO post_event (activity_id, target_attendance, actual_attendance, manual_attendance, attendance_method, satisfaction_score, submitted_by)
    VALUES (?, 100, 85, 85, 'paper_list', 4.6, ?)
")->execute([$testActId, $facultyId]);

$kpiRes = calculateActivityKpis($testActId, $db);
$allRows = $kpiRes['all_kpi_rows'] ?? [];

$attRow = null;
$satRow = null;
foreach ($allRows as $r) {
    if ($r['indicator'] === 'Participant Attendance Turnout') $attRow = $r;
    if ($r['indicator'] === 'Student Evaluation Satisfaction') $satRow = $r;
}

if ($attRow && $satRow && $attRow['actual'] == '85' && $attRow['achievement_val'] == 94.4 && $satRow['achievement_val'] == 102.2) {
    echo "  [PASS] Proposal-defined criteria evaluated dynamically using user's targets (Attendance: {$attRow['achievement']}, Satisfaction: {$satRow['achievement']})\n";
    $testsPassed++;
} else {
    echo "  [FAIL] Dynamic proposal criteria calculation mismatch\n";
    print_r($allRows);
}

// TEST 3: Attendance deduplication when both paper list and survey are used
echo "\n  [TEST 3] Attendance Deduplication (Preventing Double-Counting)...\n";
// Update post-event to method = 'both' with paper = 50, and insert 40 survey responses
$db->prepare("
    UPDATE post_event 
    SET attendance_method = 'both', manual_attendance = 50, actual_attendance = 50 
    WHERE activity_id = ?
")->execute([$testActId]);

// Insert 40 survey evaluation responses
$insSurvey = $db->prepare("INSERT INTO kpi_evaluations (activity_id, criteria, rating, evaluator_name, evaluated_at) VALUES (?, ?, ?, ?, NOW())");
for ($i = 1; $i <= 40; $i++) {
    $insSurvey->execute([$testActId, 'Event Quality', 4, "Student_Attendee_{$i}@sti.edu"]);
}

$kpiResBoth = calculateActivityKpis($testActId, $db);
$attBoth = null;
foreach ($kpiResBoth['all_kpi_rows'] as $r) {
    if ($r['indicator'] === 'Participant Attendance Turnout') $attBoth = $r;
}

// Paper = 50, Survey = 40. Deduplicated unique attendance must be max(50, 40) = 50 (NOT 50 + 40 = 90)
if ($attBoth && $attBoth['actual'] == '50' && $kpiResBoth['deduplication_applied'] === true) {
    echo "  [PASS] Deduplication verified: Paper (50) + Survey (40) deduplicated to 50 unique attendees (Double-counting prevented)\n";
    echo "         Calculation Details: " . $attBoth['calculation_details'] . "\n";
    $testsPassed++;
} else {
    echo "  [FAIL] Deduplication failed: actual was {$attBoth['actual']}, expected 50\n";
}

// TEST 4: Survey-Only Attendance Method
echo "\n  [TEST 4] Survey-Only Attendance Method Evaluation...\n";
$db->prepare("UPDATE post_event SET attendance_method = 'survey_only', manual_attendance = NULL WHERE activity_id = ?")->execute([$testActId]);
$kpiResSurvey = calculateActivityKpis($testActId, $db);
$attSurvey = null;
foreach ($kpiResSurvey['all_kpi_rows'] as $r) {
    if ($r['indicator'] === 'Participant Attendance Turnout') $attSurvey = $r;
}

if ($attSurvey && $attSurvey['actual'] == '40') {
    echo "  [PASS] Survey-only attendance calculated from 40 survey respondents\n";
    echo "         Calculation Details: " . $attSurvey['calculation_details'] . "\n";
    $testsPassed++;
} else {
    echo "  [FAIL] Survey-only attendance mismatch: expected 40, got " . ($attSurvey['actual'] ?? 'null') . "\n";
}

// TEST 5: Appropriate Missing Data Handling
echo "\n  [TEST 5] Handling Missing Data Appropriately...\n";
// Create activity with proposal criteria but NO post-event report yet
$db->prepare("INSERT INTO activities (faculty_id, title, target_participants, event_date, status, source) VALUES (?, 'No Post Event Activity', 50, CURDATE(), 'approved', 'faculty')")
    ->execute([$facultyId]);
$noPostActId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO kpi_evaluations (activity_id, indicator, target_metric, evaluation_method) VALUES (?, 'Crowd Attendance', '50 Participants', 'Paper Attendance')")
    ->execute([$noPostActId]);

$kpiNoData = calculateActivityKpis($noPostActId, $db);
$noDataRow = $kpiNoData['all_kpi_rows'][0] ?? null;

if ($noDataRow && $noDataRow['status'] === 'Insufficient Data' && $noDataRow['actual'] === '—' && $kpiNoData['has_data'] === false) {
    echo "  [PASS] Missing data handled gracefully with 'Insufficient Data' and clear explanation\n";
    echo "         Details: {$noDataRow['calculation_details']}\n";
    $testsPassed++;
} else {
    echo "  [FAIL] Missing data handling failed\n";
    print_r($noDataRow);
}

// TEST 6: Backward Compatibility with Legacy Activities
echo "\n  [TEST 6] Backward Compatibility with Legacy Activities...\n";
$db->prepare("INSERT INTO activities (faculty_id, title, target_participants, event_date, status, source) VALUES (?, 'Legacy Activity Without Custom KPIs', 80, CURDATE(), 'completed', 'faculty')")
    ->execute([$facultyId]);
$legacyActId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO post_event (activity_id, target_attendance, actual_attendance, submitted_by) VALUES (?, 80, 76, ?)")
    ->execute([$legacyActId, $facultyId]);

$kpiLegacy = calculateActivityKpis($legacyActId, $db);
if (!empty($kpiLegacy['all_kpi_rows']) && $kpiLegacy['kpis']['attendance']['actual'] == '76') {
    echo "  [PASS] Legacy activity without custom KPIs fell back cleanly to standard Attendance & Satisfaction metrics\n";
    $testsPassed++;
} else {
    echo "  [FAIL] Legacy fallback failed\n";
    print_r($kpiLegacy);
}

// Cleanup test records
$db->prepare("DELETE FROM kpi_evaluations WHERE activity_id IN (?, ?, ?)")->execute([$testActId, $noPostActId, $legacyActId]);
$db->prepare("DELETE FROM post_event WHERE activity_id IN (?, ?, ?)")->execute([$testActId, $noPostActId, $legacyActId]);
$db->prepare("DELETE FROM activities WHERE id IN (?, ?, ?)")->execute([$testActId, $noPostActId, $legacyActId]);

echo "\n========================================================\n";
if ($testsPassed === $totalTests) {
    echo " FINAL RESULT: ALL {$testsPassed}/{$totalTests} CHECKS PASSED!\n";
} else {
    echo " WARNING: {$testsPassed}/{$totalTests} CHECKS PASSED.\n";
}
echo "========================================================\n";

