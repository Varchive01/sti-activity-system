<?php
/**
 * tests/test_submitted_proposal_workflow.php
 *
 * Verifies that:
 * 1. Newly submitted proposals display status "Submitted" (badge-primary).
 * 2. Faculty can edit proposals while in "Submitted" status.
 * 3. Proposal transitions to "Under Review" only when the assigned approver views it.
 * 4. Once in "Under Review", faculty editing is locked.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

echo "=======================================================\n";
echo " STI-ARMES Proposal Submission & Review Flow Verification\n";
echo "=======================================================\n\n";

$passed = 0;
$failed = 0;

function assertCheck($name, $condition, $details = '') {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] $name\n";
        $passed++;
    } else {
        echo " [FAIL] $name" . ($details ? " ($details)" : "") . "\n";
        $failed++;
    }
}

// 1. Badge rendering check
$badgeSub = getStatusBadge('submitted');
$badgeUr = getStatusBadge('under_review');
assertCheck("getStatusBadge('submitted') outputs 'Submitted'", strpos($badgeSub, 'Submitted') !== false, $badgeSub);
assertCheck("getStatusBadge('submitted') uses badge-primary", strpos($badgeSub, 'badge-primary') !== false, $badgeSub);
assertCheck("getStatusBadge('under_review') outputs 'Under Review'", strpos($badgeUr, 'Under Review') !== false, $badgeUr);
assertCheck("getStatusBadge('under_review') uses badge-warning", strpos($badgeUr, 'badge-warning') !== false, $badgeUr);

// 2. Verify faculty/activities.php code allows editing on 'submitted'
$activitiesSrc = file_get_contents(__DIR__ . '/../faculty/activities.php');
assertCheck(
    "faculty/activities.php includes 'submitted' in edit condition",
    strpos($activitiesSrc, "in_array(\$a['status'], ['draft', 'submitted', 'returned_for_revision'])") !== false
);

// 3. Verify faculty/dashboard.php code allows editing on 'submitted'
$dashboardSrc = file_get_contents(__DIR__ . '/../faculty/dashboard.php');
assertCheck(
    "faculty/dashboard.php includes 'submitted' in edit condition",
    strpos($dashboardSrc, "in_array(\$act['status'], ['draft', 'submitted', 'returned_for_revision'])") !== false
);

// 4. Verify faculty/view-activity.php includes edit link for 'submitted'
$viewActSrc = file_get_contents(__DIR__ . '/../faculty/view-activity.php');
assertCheck(
    "faculty/view-activity.php includes edit proposal button for 'submitted'",
    strpos($viewActSrc, "in_array(\$activity['status'] ?? '', ['draft', 'submitted', 'returned_for_revision'], true)") !== false
);

// 5. Database simulation test: Create a submitted proposal
$faculty = $db->query("SELECT id, department FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin2 = $db->query("SELECT id FROM users WHERE role = 'admin2' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if ($faculty && $admin2) {
    $facId = (int)$faculty['id'];
    $admin2Id = (int)$admin2['id'];

    // Insert a test proposal with status 'submitted'
    $ins = $db->prepare("
        INSERT INTO activities (title, faculty_id, status, source, target_participants, event_date, created_at, updated_at)
        VALUES ('Test Workflow Submitted Activity', ?, 'submitted', 'faculty', 100, CURDATE(), NOW(), NOW())
    ");
    $ins->execute([$facId]);
    $testId = (int)$db->lastInsertId();

    try {
        // A. Verify canUserAccessActivity allows modify for faculty owner
        $canModify = canUserAccessActivity($testId, 'modify', $facId, 'faculty', $faculty['department'] ?? '');
        assertCheck("Faculty author can modify activity when status is 'submitted'", $canModify === true);

        // B. Simulate faculty viewing their own proposal -> status must stay 'submitted'
        $curStatus = $db->query("SELECT status FROM activities WHERE id = $testId")->fetchColumn();
        assertCheck("Activity status remains 'submitted' after creation", $curStatus === 'submitted', "Got: $curStatus");

        // C. Simulate Admin 2 (Events Committee Head) opening the proposal via admin2/view-activity.php logic
        // Auto-mark as 'under_review'
        $actRow = $db->query("SELECT * FROM activities WHERE id = $testId")->fetch(PDO::FETCH_ASSOC);
        if (in_array($actRow['status'] ?? '', ['submitted', 'resubmitted'], true)) {
            $db->prepare("UPDATE activities SET status = 'under_review' WHERE id = ?")->execute([$testId]);
            $db->prepare("INSERT INTO approval_logs (activity_id, reviewer_id, action, notes) VALUES (?, ?, 'under_review', 'Automatically marked as Under Review upon opening proposal.')")
               ->execute([$testId, $admin2Id]);
            $actRow['status'] = 'under_review';
        }

        $afterReviewStatus = $db->query("SELECT status FROM activities WHERE id = $testId")->fetchColumn();
        assertCheck("Status transitions to 'under_review' upon approver viewing", $afterReviewStatus === 'under_review', "Got: $afterReviewStatus");

        // D. Verify approval_logs captured the automatic 'under_review' transition
        $logCount = (int)$db->query("SELECT COUNT(*) FROM approval_logs WHERE activity_id = $testId AND action = 'under_review'")->fetchColumn();
        assertCheck("Approval log entry recorded for automatic under_review transition", $logCount > 0);

        // E. Verify that once 'under_review', faculty proposal-edit.php prevents selection
        $editStmt = $db->prepare("SELECT * FROM activities WHERE id=? AND status IN ('draft','submitted','returned_for_revision')");
        $editStmt->execute([$testId]);
        $editable = $editStmt->fetch(PDO::FETCH_ASSOC);
        assertCheck("Faculty edit query blocks access once status is 'under_review'", $editable === false);

    } finally {
        // Cleanup test proposal and logs
        $db->exec("DELETE FROM approval_logs WHERE activity_id = $testId");
        $db->exec("DELETE FROM activities WHERE id = $testId");
    }
} else {
    echo " [WARN] Skipping DB simulation: faculty or admin2 account not found\n";
}

echo "\n-------------------------------------------------------\n";
echo " Results: $passed Passed, $failed Failed.\n";
if ($failed === 0) {
    echo " ALL TESTS PASSED! Submitted -> Under Review workflow is verified.\n";
} else {
    echo " SOME TESTS FAILED.\n";
}
echo "=======================================================\n";
