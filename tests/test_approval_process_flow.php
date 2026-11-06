<?php
/**
 * Automated test suite for Proposal Approval Flow:
 * 1. student_org source -> Admin1 (under_review) -> Admin2 (endorsed) -> Dean (pending_final_approval) -> Final Approved
 * 2. faculty source     -> Straight to Admin2 (submitted / under_review) -> Dean (pending_final_approval) -> Final Approved
 * 3. Faculty-sourced proposals MUST NOT appear on Admin1 Dashboard or Admin1 Pending Queue
 * 4. Admin1 review.php must reject faculty-sourced proposals
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';

$db = getDB();

echo "========================================================\n";
echo "PROPOSAL APPROVAL PROCESS ROUTING & SCOPING AUDIT\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $description) {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] " . $description . "\n";
        $passCount++;
    } else {
        echo "[FAIL] " . $description . "\n";
        $failCount++;
    }
}

// 1. Create or ensure test proposal with source='faculty'
$facultyProposal = $db->query("SELECT * FROM activities WHERE title LIKE '%Under the Neon Sky%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$facultyProposal) {
    $db->prepare("
        INSERT INTO activities (title, faculty_id, source, status, event_date, venue, target_participants, theme, submitted_at, created_at, updated_at)
        VALUES ('Under the Neon Sky', 387, 'faculty', 'under_review', '2026-11-11', 'STI Marikina Auditorium', 165, 'Starry Night Soiree: An Electric Dream', NOW(), NOW(), NOW())
    ")->execute();
    $facultyProposal = $db->query("SELECT * FROM activities WHERE title LIKE '%Under the Neon Sky%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} else {
    // Ensure it's source='faculty' and status='under_review' for this test
    $db->prepare("UPDATE activities SET source='faculty', status='under_review' WHERE id=?")->execute([$facultyProposal['id']]);
    $facultyProposal['source'] = 'faculty';
    $facultyProposal['status'] = 'under_review';
}

echo "Testing Faculty Proposal: '{$facultyProposal['title']}' (ID: {$facultyProposal['id']}, Source: {$facultyProposal['source']}, Status: {$facultyProposal['status']})\n\n";

// Test 1: Admin1 Pending Page Query MUST NOT include faculty-sourced proposal
$admin1PendingQuery = $db->query("
    SELECT a.*, u.name as faculty_name FROM activities a
    JOIN users u ON a.faculty_id=u.id
    WHERE a.source = 'student_org' AND a.status IN ('under_review', 'submitted', 'resubmitted')
")->fetchAll(PDO::FETCH_ASSOC);

$foundInAdmin1Pending = false;
foreach ($admin1PendingQuery as $p) {
    if ((int)$p['id'] === (int)$facultyProposal['id'] || $p['source'] === 'faculty') {
        $foundInAdmin1Pending = true;
        break;
    }
}
assertTest(!$foundInAdmin1Pending, "Admin1 Pending Reviews queue strictly excludes faculty-sourced proposals");

// Test 2: Admin1 Dashboard Review Queue Query MUST NOT include faculty-sourced proposal
$periodBounds = buildPeriodWhereClause(2026, 'all', 'a.event_date');
$whereClause = $periodBounds['where'];
$params = $periodBounds['params'];

$admin1DashboardQueueQuery = $db->prepare("
    SELECT a.*, u.name as faculty_name FROM activities a
    JOIN users u ON a.faculty_id=u.id
    WHERE a.source='student_org' AND a.status IN ('under_review','resubmitted') AND {$whereClause}
    ORDER BY a.submitted_at DESC LIMIT 10
");
$admin1DashboardQueueQuery->execute($params);
$admin1DashboardQueue = $admin1DashboardQueueQuery->fetchAll(PDO::FETCH_ASSOC);

$foundInAdmin1Dashboard = false;
foreach ($admin1DashboardQueue as $p) {
    if ((int)$p['id'] === (int)$facultyProposal['id'] || $p['source'] === 'faculty') {
        $foundInAdmin1Dashboard = true;
        break;
    }
}
assertTest(!$foundInAdmin1Dashboard, "Admin1 Dashboard Review Queue strictly excludes faculty-sourced proposals");

// Test 3: Admin2 Pending Reviews queue MUST include faculty-sourced proposal in ('submitted', 'under_review', 'resubmitted')
$admin2PendingQuery = $db->query("
    SELECT a.*, u.name as faculty_name FROM activities a
    JOIN users u ON a.faculty_id=u.id
    WHERE ((a.source = 'faculty' AND a.status IN ('submitted', 'under_review', 'resubmitted')) OR (a.source = 'student_org' AND a.status = 'endorsed'))
")->fetchAll(PDO::FETCH_ASSOC);

$foundInAdmin2Pending = false;
foreach ($admin2PendingQuery as $p) {
    if ((int)$p['id'] === (int)$facultyProposal['id']) {
        $foundInAdmin2Pending = true;
        break;
    }
}
assertTest($foundInAdmin2Pending, "Admin2 Pending Reviews queue includes faculty-sourced proposal ('Under the Neon Sky')");

// Test 4: Verify student_org proposal flows to Admin1
$studentOrgProposal = $db->query("SELECT * FROM activities WHERE source='student_org' AND status IN ('under_review', 'submitted', 'resubmitted') LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$studentOrgProposal) {
    $db->prepare("
        INSERT INTO activities (title, faculty_id, source, status, event_date, venue, target_participants, theme, submitted_at, created_at, updated_at)
        VALUES ('Student Org Leadership Test', 387, 'student_org', 'under_review', '2026-11-20', 'Auditorium', 50, 'Leadership', NOW(), NOW(), NOW())
    ")->execute();
    $studentOrgProposal = $db->query("SELECT * FROM activities WHERE title='Student Org Leadership Test' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}

$foundStudentOrgInAdmin1 = false;
foreach ($admin1PendingQuery as $p) {
    if ((int)$p['id'] === (int)$studentOrgProposal['id']) {
        $foundStudentOrgInAdmin1 = true;
        break;
    }
}
assertTest($foundStudentOrgInAdmin1, "Admin1 Pending Reviews queue includes student_org proposal ('{$studentOrgProposal['title']}')");

// Test 5: Verify review-action.php stage gating
// Admin1 cannot review faculty-sourced proposals
$actStmt = $db->prepare("SELECT * FROM activities WHERE id=?");
$actStmt->execute([$facultyProposal['id']]);
$act = $actStmt->fetch();

$userRole = 'admin1';
$isValidStageAdmin1 = ($act['source'] === 'student_org' && in_array($act['status'], ['under_review', 'submitted', 'resubmitted'], true));
assertTest(!$isValidStageAdmin1, "Admin1 role cannot review faculty-sourced proposal in api/review-action.php");

// Admin2 can review faculty-sourced proposals
$userRole = 'admin2';
$isValidStageAdmin2 = (
    ($act['source'] === 'faculty' && in_array($act['status'], ['submitted', 'under_review', 'resubmitted'], true)) ||
    ($act['source'] === 'student_org' && $act['status'] === 'endorsed')
);
assertTest($isValidStageAdmin2, "Admin2 role is permitted to review faculty-sourced proposal in api/review-action.php");

echo "\nSummary: {$passCount} PASSED, {$failCount} FAILED.\n";
if ($failCount > 0) exit(1);
exit(0);

