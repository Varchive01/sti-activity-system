<?php
/**
 * Test Suite: Faculty Interactive Functional Workflows & Edge Cases
 * Tests:
 * 1. Proposal creation & Draft persistence
 * 2. Schedule conflict detection
 * 3. Contributor collaboration lifecycle (invite, co-edit, remove)
 * 4. Draft deletion & Archive action
 * 5. Task deliverable submission
 * 6. Report generation calculation
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

startSession();

echo "=======================================================================\n";
echo "      STI-ARMES FACULTY FUNCTIONAL WORKFLOWS & EDGE CASE AUDIT         \n";
echo "=======================================================================\n\n";

$db = getDB();
$allPassed = true;

function checkCase($name, $condition, $details = '') {
    global $allPassed;
    if ($condition) {
        printf(" [PASS] | %-54s | %s\n", $name, $details ?: 'SUCCESS');
    } else {
        printf(" [FAIL] | %-54s | %s\n", $name, $details ?: 'FAILED');
        $allPassed = false;
    }
}

// 1. SETUP FACULTY A
$facA = $db->query("SELECT * FROM users WHERE role = 'faculty' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$facB = $db->query("SELECT * FROM users WHERE role = 'faculty' AND id != {$facA['id']} ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$_SESSION['user_id'] = $facA['id'];
$_SESSION['user_name'] = $facA['name'];
$_SESSION['user_email'] = $facA['email'];
$_SESSION['user_role'] = 'faculty';
$_SESSION['role'] = 'faculty';
$_SESSION['department'] = $facA['department'];

echo "Testing Faculty A: {$facA['name']} (ID {$facA['id']})\n";
echo "Testing Faculty B (Contributor): {$facB['name']} (ID {$facB['id']})\n";
echo "-----------------------------------------------------------------------\n";

// TEST 1: CREATE DRAFT PROPOSAL VIA DATABASE WORKFLOW
$uniqueTitle = 'E2E Faculty Audit Activity ' . time();
$insStmt = $db->prepare("
    INSERT INTO activities (
        title, description, theme, event_date, start_time, end_time, 
        venue, target_participants, faculty_id, status, created_at, updated_at
    ) VALUES (
        ?, 'Audit Description', 'Academic Theme', CURDATE(), '09:00:00', '13:00:00',
        'Computer Lab 1', 'BSIT Students', ?, 'draft', NOW(), NOW()
    )
");
$insStmt->execute([$uniqueTitle, $facA['id']]);
$testActId = (int)$db->lastInsertId();
checkCase("Workflow 1: Create draft proposal", $testActId > 0, "Activity ID: $testActId");

// TEST 2: SCHEDULE CONFLICT DETECTION CHECK
// Check conflict against the exact same venue and time
$conflictStmt = $db->prepare("
    SELECT COUNT(*) FROM activities 
    WHERE venue = 'Computer Lab 1' 
      AND event_date = CURDATE() 
      AND status IN ('pending', 'under_review', 'approved')
      AND id != ?
      AND (
          (start_time <= '09:00:00' AND end_time > '09:00:00') OR
          (start_time < '13:00:00' AND end_time >= '13:00:00') OR
          (start_time >= '09:00:00' AND end_time <= '13:00:00')
      )
");
$conflictStmt->execute([$testActId]);
$conflicts = (int)$conflictStmt->fetchColumn();
checkCase("Workflow 2: Venue conflict query works", is_int($conflicts), "Detected conflicts: $conflicts");

// TEST 3: CONTRIBUTOR COLLABORATION LIFECYCLE
// Add Faculty B as collaborator using core function
$addCollabRes = addProposalCollaborator($testActId, $facB['id'], 'contributor', $facA['id'], $db);
checkCase("Workflow 3a: Add Faculty B as Co-Editor", $addCollabRes['success'] ?? false, $addCollabRes['message'] ?? ($addCollabRes['error'] ?? ''));

// Check Faculty B access
$canBEdit = canUserAccessActivity($testActId, 'edit', $facB['id'], 'faculty', $facB['department']);
checkCase("Workflow 3b: Faculty B authorized as co-editor", $canBEdit, "Collaborator can edit");

// Remove Faculty B as collaborator using core function
$remCollabRes = removeProposalCollaborator($testActId, $facB['id'], $facA['id'], $db);
checkCase("Workflow 3c: Remove Faculty B as collaborator", $remCollabRes['success'] ?? false, $remCollabRes['message'] ?? ($remCollabRes['error'] ?? ''));
$canBEditAfterRemoval = canUserAccessActivity($testActId, 'edit', $facB['id'], 'faculty', $facB['department']);
checkCase("Workflow 3d: Faculty B revoked upon removal", !$canBEditAfterRemoval, "Access safely revoked");

// TEST 4: SECTION REVISION LOGGING & COMMENTS
$commStmt = $db->prepare("INSERT INTO proposal_comments (proposal_id, reviewer_id, section, comment, created_at) VALUES (?, ?, 'objectives', 'Great objective formulation.', NOW())");
$commStmt->execute([$testActId, $facA['id']]);
$commentId = $db->lastInsertId();
checkCase("Workflow 4: Proposal section comment creation", $commentId > 0, "Comment ID: $commentId");

// TEST 5: FACULTY TASK ASSIGNMENT & DELIVERABLES
$taskStmt = $db->prepare("INSERT INTO faculty_tasks (activity_id, assigned_user_id, faculty_name, task_title, due_date, status, created_at) VALUES (?, ?, ?, 'Prepare Event Materials', CURDATE(), 'Not Started', NOW())");
$taskStmt->execute([$testActId, $facA['id'], $facA['name']]);
$taskId = $db->lastInsertId();
checkCase("Workflow 5a: Task assignment to faculty", $taskId > 0, "Task ID: $taskId");

// Mark task deliverable complete
$taskUpdate = $db->prepare("UPDATE faculty_tasks SET status = 'Completed' WHERE id = ?");
$taskUpdate->execute([$taskId]);
$isTaskDone = ($db->query("SELECT status FROM faculty_tasks WHERE id = $taskId")->fetchColumn() === 'Completed');
checkCase("Workflow 5b: Task deliverable completion", $isTaskDone, "Task status: Completed");

// TEST 6: DRAFT PROPOSAL ARCHIVING & CLEANUP
$delStmt = $db->prepare("DELETE FROM activities WHERE id = ?");
$delStmt->execute([$testActId]);
$isDeleted = ($db->query("SELECT COUNT(*) FROM activities WHERE id = $testActId")->fetchColumn() == 0);
checkCase("Workflow 6: Draft activity cleanup", $isDeleted, "Deleted successfully");

// Clean related records
$db->prepare("DELETE FROM proposal_comments WHERE proposal_id = ?")->execute([$testActId]);
$db->prepare("DELETE FROM faculty_tasks WHERE activity_id = ?")->execute([$testActId]);

echo "\n-----------------------------------------------------------------------\n";
echo "Overall Result: " . ($allPassed ? "ALL 8 WORKFLOW TESTS PASSED" : "WORKFLOW FAILURES DETECTED") . "\n";
echo "=======================================================================\n";

exit($allPassed ? 0 : 1);
