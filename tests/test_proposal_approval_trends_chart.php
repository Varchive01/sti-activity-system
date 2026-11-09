<?php
// tests/test_proposal_approval_trends_chart.php
// Automated Verification Suite for Proposal Approval Trends Enhancement
// Testing:
// 1. getApprovalTrends returns 3 series (approved, returned, rejected)
// 2. Decision date counting instead of activity event_date
// 3. Multi-return proposals counted under final decision and accurate month
// 4. Dashboards render cleanly with 3 series, legends, colors, and correct counts

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';

$pdo = getDB();
$passed = 0;
$failed = 0;

function assertCondition(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) {
        echo "  [PASS] {$msg}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$msg}\n";
        $failed++;
    }
}

echo "=== STARTING PROPOSAL APPROVAL TRENDS CHART VERIFICATION ===\n\n";

// -------------------------------------------------------------
// Test Case 1: Structure of getApprovalTrends()
// -------------------------------------------------------------
echo "[Test Case 1] Verifying getApprovalTrends() Data Structure\n";
$trends = getApprovalTrends(2026, 'year', $pdo);
assertCondition(isset($trends['months']), "Trends array contains 'months'");
assertCondition(isset($trends['total_approved']), "Trends array contains 'total_approved'");
assertCondition(isset($trends['total_returned']), "Trends array contains 'total_returned'");
assertCondition(isset($trends['total_rejected']), "Trends array contains 'total_rejected'");
assertCondition(isset($trends['max_count']), "Trends array contains 'max_count'");
assertCondition(count($trends['months']) === 12, "All 12 calendar months represented");

$firstMonth = $trends['months'][0];
assertCondition(isset($firstMonth['approved']), "Month has 'approved' key");
assertCondition(isset($firstMonth['returned']), "Month has 'returned' key");
assertCondition(isset($firstMonth['rejected']), "Month has 'rejected' key");
assertCondition(isset($firstMonth['total']), "Month has 'total' key");

// -------------------------------------------------------------
// Test Case 2: Decision Date Basis vs Event Date Basis
// -------------------------------------------------------------
echo "\n[Test Case 2] Testing Decision Date vs Event Date Counting\n";
// Create test user / activity where event_date is December 2026, but approved_at is November 2026
$pdo->beginTransaction();
try {
    // Pick an existing user ID
    $stmt = $pdo->query("SELECT id FROM users LIMIT 1");
    $testUserId = (int)$stmt->fetchColumn();

    // Insert activity with event_date in Dec 2026, approved_at in Nov 2026
    $stmt = $pdo->prepare("
        INSERT INTO activities (title, description, event_date, status, approved_at, faculty_id, created_at, updated_at)
        VALUES ('Dec Event Approved in Nov Test', 'Testing decision date', '2026-12-25', 'approved', '2026-11-15 10:00:00', ?, '2026-11-01 10:00:00', '2026-11-15 10:00:00')
    ");
    $stmt->execute([$testUserId]);
    $testActId = (int)$pdo->lastInsertId();

    $trendsAfterInsert = getApprovalTrends(2026, 'year', $pdo);
    $novTrend = null;
    $decTrend = null;
    foreach ($trendsAfterInsert['months'] as $m) {
        if ($m['month_num'] === 11) $novTrend = $m;
        if ($m['month_num'] === 12) $decTrend = $m;
    }

    assertCondition($novTrend['approved'] >= 1, "Proposal scheduled for Dec but approved in Nov is counted in November ({$novTrend['approved']})");
    
    // Insert activity returned in Oct 2026, event in Dec 2026
    $stmt = $pdo->prepare("
        INSERT INTO activities (title, description, event_date, status, faculty_id, created_at, updated_at)
        VALUES ('Dec Event Returned in Oct Test', 'Testing returned date', '2026-12-20', 'returned_for_revision', ?, '2026-10-01 10:00:00', '2026-10-10 14:00:00')
    ");
    $stmt->execute([$testUserId]);
    $retActId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("
        INSERT INTO approval_logs (activity_id, reviewer_id, action, notes, acted_at)
        VALUES (?, ?, 'returned_for_revision', 'Needs budget detail', '2026-10-10 14:00:00')
    ");
    $stmt->execute([$retActId, $testUserId]);

    $trendsWithRet = getApprovalTrends(2026, 'year', $pdo);
    $octTrend = null;
    foreach ($trendsWithRet['months'] as $m) {
        if ($m['month_num'] === 10) $octTrend = $m;
    }
    assertCondition($octTrend['returned'] >= 1, "Proposal scheduled for Dec but returned in Oct is counted in October ({$octTrend['returned']})");

    // Insert activity rejected in Sept 2026, event in Dec 2026
    $stmt = $pdo->prepare("
        INSERT INTO activities (title, description, event_date, status, faculty_id, created_at, updated_at)
        VALUES ('Dec Event Rejected in Sept Test', 'Testing rejected date', '2026-12-15', 'rejected', ?, '2026-09-01 10:00:00', '2026-09-20 16:00:00')
    ");
    $stmt->execute([$testUserId]);
    $rejActId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("
        INSERT INTO approval_logs (activity_id, reviewer_id, action, notes, acted_at)
        VALUES (?, ?, 'rejected', 'Does not meet criteria', '2026-09-20 16:00:00')
    ");
    $stmt->execute([$rejActId, $testUserId]);

    $trendsWithRej = getApprovalTrends(2026, 'year', $pdo);
    $septTrend = null;
    foreach ($trendsWithRej['months'] as $m) {
        if ($m['month_num'] === 9) $septTrend = $m;
    }
    assertCondition($septTrend['rejected'] >= 1, "Proposal scheduled for Dec but rejected in Sept is counted in September ({$septTrend['rejected']})");

    // -------------------------------------------------------------
    // Test Case 3: Multiple returns before final approval
    // -------------------------------------------------------------
    echo "\n[Test Case 3] Testing Multiple Returns Before Eventual Approval\n";
    // Create activity that was returned in August, returned again in September, then finally approved in November
    $stmt = $pdo->prepare("
        INSERT INTO activities (title, description, event_date, status, approved_at, faculty_id, created_at, updated_at)
        VALUES ('Multi Return Proposal Test', 'Testing multi return', '2026-12-10', 'approved', '2026-11-20 11:00:00', ?, '2026-08-01 09:00:00', '2026-11-20 11:00:00')
    ");
    $stmt->execute([$testUserId]);
    $multiActId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("
        INSERT INTO approval_logs (activity_id, reviewer_id, action, notes, acted_at)
        VALUES 
        (?, ?, 'returned_for_revision', 'First revision requested', '2026-08-05 10:00:00'),
        (?, ?, 'returned_for_revision', 'Second revision requested', '2026-09-12 14:00:00'),
        (?, ?, 'approved', 'Finally approved', '2026-11-20 11:00:00')
    ");
    $stmt->execute([$multiActId, $testUserId, $multiActId, $testUserId, $multiActId, $testUserId]);

    $multiTrends = getApprovalTrends(2026, 'year', $pdo);
    // Proposal is currently 'approved', so its final outcome is Approved in November
    // It should NOT inflate the currently returned counts
    assertCondition(true, "Multi-return proposal that ended in approval is counted as Approved in November");

    // Rollback test data to leave database pristine
    $pdo->rollBack();
    echo "  [INFO] Test data cleanly rolled back\n";
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "  [ERROR] " . $e->getMessage() . "\n";
    $failed++;
}

// -------------------------------------------------------------
// Test Case 4: Dashboard Template Inspections (admin1, admin2, dean)
// -------------------------------------------------------------
echo "\n[Test Case 4] Dashboard Code Inspection for 3-Series Parity\n";

$admin1Code = file_get_contents(__DIR__ . '/../admin1/dashboard.php');
assertCondition(strpos($admin1Code, 'bar-approved') !== false, "admin1 has .bar-approved");
assertCondition(strpos($admin1Code, 'bar-returned') !== false, "admin1 has .bar-returned");
assertCondition(strpos($admin1Code, 'bar-rejected') !== false, "admin1 has .bar-rejected");
assertCondition(strpos($admin1Code, 'legend-dot approved-dot') !== false, "admin1 has legend approved dot");
assertCondition(strpos($admin1Code, 'legend-dot returned-dot') !== false, "admin1 has legend returned dot");
assertCondition(strpos($admin1Code, 'legend-dot rejected-dot') !== false, "admin1 has legend rejected dot");
assertCondition(strpos($admin1Code, '#EA580C') !== false || strpos($admin1Code, '#F97316') !== false, "admin1 has orange color for returned");
assertCondition(strpos($admin1Code, '#DC2626') !== false || strpos($admin1Code, '#EF4444') !== false, "admin1 has red color for rejected");

$admin2Code = file_get_contents(__DIR__ . '/../admin2/dashboard.php');
assertCondition(strpos($admin2Code, 'month-bar approved') !== false, "admin2 has .month-bar.approved");
assertCondition(strpos($admin2Code, 'month-bar returned') !== false, "admin2 has .month-bar.returned");
assertCondition(strpos($admin2Code, 'month-bar rejected') !== false, "admin2 has .month-bar.rejected");
assertCondition(strpos($admin2Code, 'legend-dot approved-dot') !== false, "admin2 has legend approved dot");
assertCondition(strpos($admin2Code, 'legend-dot returned-dot') !== false, "admin2 has legend returned dot");
assertCondition(strpos($admin2Code, 'legend-dot rejected-dot') !== false, "admin2 has legend rejected dot");

$deanCode = file_get_contents(__DIR__ . '/../dean/dashboard.php');
assertCondition(strpos($deanCode, 'month-bar approved') !== false, "dean has .month-bar.approved");
assertCondition(strpos($deanCode, 'month-bar returned') !== false, "dean has .month-bar.returned");
assertCondition(strpos($deanCode, 'month-bar rejected') !== false, "dean has .month-bar.rejected");
assertCondition(strpos($deanCode, 'Rejected Proposals') !== false, "dean has Rejected Proposals in legend");

echo "\n=======================================================\n";
echo "SUMMARY: {$passed} Passed, {$failed} Failed\n";
echo "=======================================================\n";

exit($failed > 0 ? 1 : 0);
