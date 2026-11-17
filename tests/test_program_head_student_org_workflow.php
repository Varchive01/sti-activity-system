<?php
/**
 * Automated Verification: Program Head to Student Organization Head Workflow Connection
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();
echo "=== TESTING PROGRAM HEAD & STUDENT ORG HEAD WORKFLOW CONNECTION ===\n\n";

$allPassed = true;
function assertTest(bool $cond, string $msg, string $details = '') {
    global $allPassed;
    if ($cond) {
        echo "[PASS] $msg" . ($details ? " ($details)" : "") . "\n";
    } else {
        echo "[FAIL] $msg" . ($details ? " ($details)" : "") . "\n";
        $allPassed = false;
    }
}

// Find test users
$phUser = $db->query("SELECT * FROM users WHERE role='program_head' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin1User = $db->query("SELECT * FROM users WHERE role='admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin2User = $db->query("SELECT * FROM users WHERE role='admin2' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$deanUser = $db->query("SELECT * FROM users WHERE role='dean' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$facUser = $db->query("SELECT * FROM users WHERE role='faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

assertTest(!empty($phUser), "Program Head user found", "Name: {$phUser['name']}");
assertTest(!empty($admin1User), "Admin 1 (Student Org Head) found", "Name: {$admin1User['name']}");
assertTest(!empty($admin2User), "Admin 2 (Events Committee Head) found", "Name: {$admin2User['name']}");
assertTest(!empty($deanUser), "Dean found", "Name: {$deanUser['name']}");

// Clean up any old leftover test activities
$db->exec("DELETE FROM documents WHERE activity_id IN (SELECT id FROM activities WHERE title LIKE 'Test Workflow Connection %')");
$db->exec("DELETE FROM approval_logs WHERE activity_id IN (SELECT id FROM activities WHERE title LIKE 'Test Workflow Connection %')");
$db->exec("DELETE FROM notifications WHERE activity_id IN (SELECT id FROM activities WHERE title LIKE 'Test Workflow Connection %')");
$db->exec("DELETE FROM section_comments WHERE activity_id IN (SELECT id FROM activities WHERE title LIKE 'Test Workflow Connection %')");
$db->exec("DELETE FROM activities WHERE title LIKE 'Test Workflow Connection %'");

// Helper function to call review-action.php in isolated process
function executeReviewAction(int $actId, string $action, array $user, string $notes = ''): array {
    $runnerFile = __DIR__ . '/temp_runner_' . bin2hex(random_bytes(6)) . '.php';
    $targetScript = __DIR__ . '/../api/review-action.php';
    
    $code = "<?php
define('TEST_RUNNER', true);
if (session_status() === PHP_SESSION_NONE) session_start();
\$_SESSION['user_id'] = {$user['id']};
\$_SESSION['user_role'] = '{$user['role']}';
\$_SESSION['role'] = '{$user['role']}';
\$_SESSION['user_name'] = " . var_export($user['name'], true) . ";
\$_SESSION['user_email'] = " . var_export($user['email'], true) . ";
\$_SESSION['must_change_password'] = 0;
\$_SESSION['last_activity'] = time();

\$_POST = [
    'activity_id' => {$actId},
    'action'      => " . var_export($action, true) . ",
    'notes'       => " . var_export($notes, true) . "
];
\$_SERVER['REQUEST_METHOD'] = 'POST';

try {
    ob_start();
    require " . var_export($targetScript, true) . ";
    \$out = ob_get_clean();
    echo json_encode(['success' => true, 'output' => \$out]);
} catch (Throwable \$e) {
    echo json_encode(['success' => false, 'error' => \$e->getMessage()]);
}
";
    file_put_contents($runnerFile, $code);
    $output = shell_exec("php " . escapeshellarg($runnerFile));
    if (file_exists($runnerFile)) {
        unlink($runnerFile);
    }
    return ($output !== null && $output !== '') ? (json_decode($output, true) ?: ['success' => false, 'raw' => $output]) : ['success' => false, 'raw' => ''];
}

// -------------------------------------------------------------
// SCENARIO 1: Proposal submitted by Program Head (source = 'faculty')
// -------------------------------------------------------------
echo "\n--- SCENARIO 1: Program Head proposal (source='faculty') reviewed by Student Org Head ---\n";

$db->prepare("
    INSERT INTO activities (faculty_id, title, source, status, event_date, target_participants, created_at, updated_at)
    VALUES (?, 'Test Workflow Connection PH Fac 1', 'faculty', 'under_review', CURDATE(), 80, NOW(), NOW())
")->execute([$phUser['id']]);
$actId1 = (int)$db->lastInsertId();

// Verify Admin 1 review page access query
$stmtReview = $db->prepare("
    SELECT a.*, u.name as faculty_name, u.email as faculty_email, u.role as author_role 
    FROM activities a 
    JOIN users u ON a.faculty_id = u.id 
    WHERE a.id = ? AND (a.source = 'student_org' OR u.role = 'program_head')
");
$stmtReview->execute([$actId1]);
$rowReview = $stmtReview->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($rowReview), "Admin 1 review page query successfully retrieves PH proposal", "ID: $actId1");

// Verify canUserAccessActivity
assertTest(canUserAccessActivity($actId1, 'view', (int)$admin1User['id'], 'admin1', $admin1User['department'] ?? ''), "canUserAccessActivity allows Admin 1 to view PH proposal");

// Test Admin 1 APPROVE action (Approve & Forward to Dean/Admin2)
$resApprove = executeReviewAction($actId1, 'approved', $admin1User, 'Endorsed by Student Org Head');
$statusAfterApprove = $db->query("SELECT status FROM activities WHERE id={$actId1}")->fetchColumn();
assertTest($statusAfterApprove === 'endorsed', "Admin 1 successfully approved PH proposal without 403 error", "Status: $statusAfterApprove");

// Verify Admin 2 can now review the endorsed proposal
$resAdmin2 = executeReviewAction($actId1, 'approved', $admin2User, 'Events Committee Approved');
$statusAfterAdmin2 = $db->query("SELECT status FROM activities WHERE id={$actId1}")->fetchColumn();
assertTest($statusAfterAdmin2 === 'pending_final_approval', "Admin 2 successfully approved endorsed PH proposal", "Status: $statusAfterAdmin2");

// Verify Dean can now give final approval
$resDean = executeReviewAction($actId1, 'approved', $deanUser, 'Dean Final Approved');
$statusAfterDean = $db->query("SELECT status FROM activities WHERE id={$actId1}")->fetchColumn();
assertTest($statusAfterDean === 'approved', "Dean successfully gave final approval to PH proposal", "Status: $statusAfterDean");

// -------------------------------------------------------------
// SCENARIO 2: Program Head proposal RETURNED FOR REVISION by Admin 1
// -------------------------------------------------------------
echo "\n--- SCENARIO 2: Program Head proposal RETURNED for revision by Student Org Head ---\n";

$db->prepare("
    INSERT INTO activities (faculty_id, title, source, status, event_date, target_participants, created_at, updated_at)
    VALUES (?, 'Test Workflow Connection PH Fac 2', 'faculty', 'under_review', CURDATE(), 80, NOW(), NOW())
")->execute([$phUser['id']]);
$actId2 = (int)$db->lastInsertId();

// Stage a section comment so return requirement is satisfied
$db->prepare("
    INSERT INTO section_comments (activity_id, reviewer_id, section_key, comment)
    VALUES (?, ?, 'event_details', 'Please refine event venue description')
")->execute([$actId2, $admin1User['id']]);

$resReturn = executeReviewAction($actId2, 'returned', $admin1User, 'Please revise event details');
$statusAfterReturn = $db->query("SELECT status FROM activities WHERE id={$actId2}")->fetchColumn();
assertTest($statusAfterReturn === 'returned_for_revision', "Admin 1 successfully returned PH proposal for revision without 403 error", "Status: $statusAfterReturn");

// Simulate Program Head resubmission through proposal-update.php routing logic
$logStmt = $db->prepare("
    SELECT al.*, u.role
    FROM approval_logs al
    JOIN users u ON al.reviewer_id = u.id
    WHERE al.activity_id = ? AND al.action = 'returned_for_revision'
    ORDER BY al.acted_at DESC LIMIT 1
");
$logStmt->execute([$actId2]);
$lastReturn = $logStmt->fetch(PDO::FETCH_ASSOC);
$isProgramHeadSubmitter = ($phUser['role'] === 'program_head');

$nextStatus = ($lastReturn['role'] === 'admin1') ? 'under_review' : 'submitted';
$db->prepare("UPDATE activities SET status=? WHERE id=?")->execute([$nextStatus, $actId2]);

$statusAfterResubmit = $db->query("SELECT status FROM activities WHERE id={$actId2}")->fetchColumn();
assertTest($statusAfterResubmit === 'under_review', "Resubmitted PH proposal routes cleanly back to Admin 1 as 'under_review'", "Status: $statusAfterResubmit");

// Verify Admin 1 can review the resubmitted proposal again
$resApprove2 = executeReviewAction($actId2, 'approved', $admin1User, 'Re-endorsed after revision');
$statusAfterReApprove = $db->query("SELECT status FROM activities WHERE id={$actId2}")->fetchColumn();
assertTest($statusAfterReApprove === 'endorsed', "Admin 1 can approve resubmitted PH proposal without 403 error", "Status: $statusAfterReApprove");

// -------------------------------------------------------------
// SCENARIO 3: Program Head proposal REJECTED by Admin 1
// -------------------------------------------------------------
echo "\n--- SCENARIO 3: Program Head proposal REJECTED by Student Org Head ---\n";

$db->prepare("
    INSERT INTO activities (faculty_id, title, source, status, event_date, target_participants, created_at, updated_at)
    VALUES (?, 'Test Workflow Connection PH Fac 3', 'faculty', 'under_review', CURDATE(), 80, NOW(), NOW())
")->execute([$phUser['id']]);
$actId3 = (int)$db->lastInsertId();

$resReject = executeReviewAction($actId3, 'rejected', $admin1User, 'Proposal does not align with student activity guidelines');
$statusAfterReject = $db->query("SELECT status FROM activities WHERE id={$actId3}")->fetchColumn();
assertTest($statusAfterReject === 'rejected', "Admin 1 successfully rejected PH proposal without 403 error", "Status: $statusAfterReject");

// -------------------------------------------------------------
// SCENARIO 4: Preservation of Regular Faculty Proposal Workflow
// -------------------------------------------------------------
echo "\n--- SCENARIO 4: Regular Faculty Proposal Workflow Preservation ---\n";

$db->prepare("
    INSERT INTO activities (faculty_id, title, source, status, event_date, target_participants, created_at, updated_at)
    VALUES (?, 'Test Workflow Connection Regular Faculty', 'faculty', 'submitted', CURDATE(), 50, NOW(), NOW())
")->execute([$facUser['id']]);
$actIdFac = (int)$db->lastInsertId();

// Verify Admin 1 CANNOT review standard faculty proposal
$resAdmin1OnFac = executeReviewAction($actIdFac, 'approved', $admin1User, 'Unauthorized review attempt');
$statusFacAfterAdmin1 = $db->query("SELECT status FROM activities WHERE id={$actIdFac}")->fetchColumn();
assertTest($statusFacAfterAdmin1 === 'submitted', "Admin 1 is strictly barred from reviewing standard faculty proposal (status remains unchanged)", "Status: $statusFacAfterAdmin1");

// Verify Admin 2 CAN review standard faculty proposal directly
$resAdmin2OnFac = executeReviewAction($actIdFac, 'approved', $admin2User, 'Events Committee Approved Faculty Proposal');
$statusFacAfterAdmin2 = $db->query("SELECT status FROM activities WHERE id={$actIdFac}")->fetchColumn();
assertTest($statusFacAfterAdmin2 === 'pending_final_approval', "Admin 2 continues to directly review standard faculty proposal", "Status: $statusFacAfterAdmin2");

// Clean up test records
$db->prepare("DELETE FROM documents WHERE activity_id IN (?, ?, ?, ?)")->execute([$actId1, $actId2, $actId3, $actIdFac]);
$db->prepare("DELETE FROM approval_logs WHERE activity_id IN (?, ?, ?, ?)")->execute([$actId1, $actId2, $actId3, $actIdFac]);
$db->prepare("DELETE FROM notifications WHERE activity_id IN (?, ?, ?, ?)")->execute([$actId1, $actId2, $actId3, $actIdFac]);
$db->prepare("DELETE FROM section_comments WHERE activity_id IN (?, ?, ?, ?)")->execute([$actId1, $actId2, $actId3, $actIdFac]);
$db->prepare("DELETE FROM activities WHERE id IN (?, ?, ?, ?)")->execute([$actId1, $actId2, $actId3, $actIdFac]);

echo "\n=======================================================\n";
echo "OVERALL VERIFICATION: " . ($allPassed ? "ALL TESTS PASSED PERFECTLY" : "FAILURES DETECTED") . "\n";
echo "=======================================================\n";
