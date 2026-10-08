<?php
/**
 * Focused Verification Suite for api/review-action.php Reviewer Authorization
 * 
 * Verifies:
 * 1. Admin1 cannot impersonate Dean through POST parameters (role=dean).
 * 2. Admin2 cannot impersonate Dean through POST parameters (role=dean).
 * 3. Admin1 can act on valid under_review student_org proposals.
 * 4. Admin2 can act on valid submitted faculty and endorsed student_org proposals.
 * 5. Dean can perform final approval on pending_final_approval.
 * 6. Invalid stage/role combinations are rejected with HTTP 403 / Access Denied.
 * 7. Existing approve/reject/return behavior still works.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

echo "========================================================================\n";
echo "  API/REVIEW-ACTION.PHP REVIEWER AUTHORIZATION VERIFICATION\n";
echo "========================================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $title, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$title}\n";
        $passed++;
    } else {
        echo " [FAIL] {$title}" . ($detail ? " - {$detail}" : "") . "\n";
        $failed++;
    }
}

// Fetch test users for each role
$usersByRole = [];
foreach (['faculty', 'admin1', 'admin2', 'dean'] as $r) {
    $row = $db->query("SELECT id, name, email, role FROM users WHERE role='{$r}' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo "[FATAL] User with role {$r} not found.\n";
        exit(1);
    }
    $usersByRole[$r] = $row;
}

// Helper to run review-action.php in subproc
function executeReviewAction(array $postData, array $userSession): array {
    $script = __DIR__ . '/../api/review-action.php';
    $postExport = var_export($postData, true);
    
    $code = "<?php
    define('TEST_RUNNER', true);
    if (session_status() === PHP_SESSION_NONE) session_start();
    \$_SESSION['user_id'] = {$userSession['id']};
    \$_SESSION['user_role'] = " . var_export($userSession['role'], true) . ";
    \$_SESSION['role'] = " . var_export($userSession['role'], true) . ";
    \$_SESSION['user_name'] = " . var_export($userSession['name'], true) . ";
    \$_SESSION['user_email'] = " . var_export($userSession['email'], true) . ";
    \$_SESSION['last_activity'] = time();
    \$_POST = {$postExport};
    \$_SERVER['REQUEST_METHOD'] = 'POST';
    try {
        ob_start();
        require '{$script}';
        \$out = ob_get_clean();
        echo \$out;
    } catch (Throwable \$e) {
        echo 'EXCEPTION: ' . \$e->getMessage();
    }
    ";

    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open('php', $desc, $pipes);
    if (!is_resource($proc)) return ['output' => '', 'exit_code' => -1];

    fwrite($pipes[0], $code);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($proc);

    return [
        'output' => trim($out . $err),
        'exit_code' => $status
    ];
}

// Helper to create test activities
$createdActivityIds = [];
function createTestActivity(string $title, string $source, string $status): int {
    global $db, $usersByRole, $createdActivityIds;
    $stmt = $db->prepare("
        INSERT INTO activities (faculty_id, title, description, source, status, event_date, start_time, end_time, venue)
        VALUES (?, ?, 'Test description', ?, ?, '2026-10-15', '09:00:00', '12:00:00', 'Test Room')
    ");
    $stmt->execute([$usersByRole['faculty']['id'], $title, $source, $status]);
    $id = (int)$db->lastInsertId();
    $createdActivityIds[] = $id;
    return $id;
}

// -------------------------------------------------------------------------
// 1. Admin1 Impersonation of Dean Prevention
// -------------------------------------------------------------------------
echo "\n--- 1. Admin1 Tampered POST Impersonation Prevention ---\n";
// Case A: Admin1 reviews student_org under_review with role=dean, action=approved
$actA = createTestActivity("Admin1 Spoof Dean Valid Stage " . time(), 'student_org', 'under_review');
$resA = executeReviewAction([
    'activity_id' => $actA,
    'action'      => 'approved',
    'role'        => 'dean', // Tampered client role!
    'notes'       => 'Approving as dean'
], $usersByRole['admin1']);

$statusA = $db->query("SELECT status FROM activities WHERE id={$actA}")->fetchColumn();
assertTest("Admin1 with role=dean DOES NOT advance to 'approved'", $statusA !== 'approved');
assertTest("Admin1 advances proposal only to 'endorsed' (their authorized transition)", $statusA === 'endorsed');

// Case B: Admin1 attempts to approve pending_final_approval with role=dean
$actB = createTestActivity("Admin1 Spoof Dean Dean Stage " . time(), 'faculty', 'pending_final_approval');
$resB = executeReviewAction([
    'activity_id' => $actB,
    'action'      => 'approved',
    'role'        => 'dean',
    'notes'       => 'Approving final'
], $usersByRole['admin1']);

$statusB = $db->query("SELECT status FROM activities WHERE id={$actB}")->fetchColumn();
assertTest("Admin1 acting on pending_final_approval is rejected", str_contains($resB['output'], 'Access denied'));
assertTest("Proposal at pending_final_approval remains untouched", $statusB === 'pending_final_approval');

// -------------------------------------------------------------------------
// 2. Admin2 Impersonation of Dean Prevention
// -------------------------------------------------------------------------
echo "\n--- 2. Admin2 Tampered POST Impersonation Prevention ---\n";
// Case C: Admin2 reviews faculty submitted with role=dean, action=approved
$actC = createTestActivity("Admin2 Spoof Dean Valid Stage " . time(), 'faculty', 'submitted');
$resC = executeReviewAction([
    'activity_id' => $actC,
    'action'      => 'approved',
    'role'        => 'dean', // Tampered!
    'notes'       => 'Approving as dean'
], $usersByRole['admin2']);

$statusC = $db->query("SELECT status FROM activities WHERE id={$actC}")->fetchColumn();
assertTest("Admin2 with role=dean DOES NOT advance to 'approved'", $statusC !== 'approved');
assertTest("Admin2 advances proposal only to 'pending_final_approval'", $statusC === 'pending_final_approval');

// Case D: Admin2 attempts to act on pending_final_approval with role=dean
$actD = createTestActivity("Admin2 Spoof Dean Dean Stage " . time(), 'faculty', 'pending_final_approval');
$resD = executeReviewAction([
    'activity_id' => $actD,
    'action'      => 'approved',
    'role'        => 'dean',
    'notes'       => 'Approving final'
], $usersByRole['admin2']);

$statusD = $db->query("SELECT status FROM activities WHERE id={$actD}")->fetchColumn();
assertTest("Admin2 acting on pending_final_approval is rejected", str_contains($resD['output'], 'Access denied'));
assertTest("Proposal at pending_final_approval remains untouched by Admin2", $statusD === 'pending_final_approval');

// -------------------------------------------------------------------------
// 3. Authorized Review Workflows
// -------------------------------------------------------------------------
echo "\n--- 3. Authorized Workflow Stages ---\n";
// Admin1 on student_org under_review -> endorsed
$actE = createTestActivity("Admin1 Authorized " . time(), 'student_org', 'under_review');
$resE = executeReviewAction([
    'activity_id' => $actE,
    'action'      => 'approved',
    'notes'       => 'Endorsing'
], $usersByRole['admin1']);
$statusE = $db->query("SELECT status FROM activities WHERE id={$actE}")->fetchColumn();
assertTest("Admin1 legitimately endorses student_org under_review proposal", $statusE === 'endorsed');

// Admin2 on endorsed student_org -> pending_final_approval
$actF = createTestActivity("Admin2 Authorized Endorsed " . time(), 'student_org', 'endorsed');
$resF = executeReviewAction([
    'activity_id' => $actF,
    'action'      => 'approved',
    'notes'       => 'Forwarding endorsed'
], $usersByRole['admin2']);
$statusF = $db->query("SELECT status FROM activities WHERE id={$actF}")->fetchColumn();
assertTest("Admin2 legitimately forwards endorsed student_org proposal to pending_final_approval", $statusF === 'pending_final_approval');

// Admin2 on submitted faculty -> pending_final_approval
$actG = createTestActivity("Admin2 Authorized Faculty " . time(), 'faculty', 'submitted');
$resG = executeReviewAction([
    'activity_id' => $actG,
    'action'      => 'approved',
    'notes'       => 'Forwarding faculty'
], $usersByRole['admin2']);
$statusG = $db->query("SELECT status FROM activities WHERE id={$actG}")->fetchColumn();
assertTest("Admin2 legitimately forwards submitted faculty proposal to pending_final_approval", $statusG === 'pending_final_approval');

// Dean final approval on pending_final_approval -> approved
$actH = createTestActivity("Dean Authorized Final " . time(), 'faculty', 'pending_final_approval');
$resH = executeReviewAction([
    'activity_id' => $actH,
    'action'      => 'approved',
    'notes'       => 'Final approval granted'
], $usersByRole['dean']);
$statusH = $db->query("SELECT status FROM activities WHERE id={$actH}")->fetchColumn();
assertTest("Dean legitimately approves pending_final_approval proposal", $statusH === 'approved');

// -------------------------------------------------------------------------
// 4. Invalid Stage/Role Combinations Rejected
// -------------------------------------------------------------------------
echo "\n--- 4. Invalid Stage / Role Combinations Rejection ---\n";
// Dean acting on submitted proposal
$actI = createTestActivity("Dean on Submitted " . time(), 'faculty', 'submitted');
$resI = executeReviewAction([
    'activity_id' => $actI,
    'action'      => 'approved'
], $usersByRole['dean']);
assertTest("Dean rejected when acting on 'submitted' proposal", str_contains($resI['output'], 'Access denied'));

// Admin1 acting on faculty submitted proposal
$actJ = createTestActivity("Admin1 on Faculty Submitted " . time(), 'faculty', 'submitted');
$resJ = executeReviewAction([
    'activity_id' => $actJ,
    'action'      => 'approved'
], $usersByRole['admin1']);
assertTest("Admin1 rejected when acting on faculty 'submitted' proposal", str_contains($resJ['output'], 'Access denied'));

// Admin2 acting on student_org under_review proposal (before Admin1 endorsement)
$actK = createTestActivity("Admin2 on StudentOrg under_review " . time(), 'student_org', 'under_review');
$resK = executeReviewAction([
    'activity_id' => $actK,
    'action'      => 'approved'
], $usersByRole['admin2']);
assertTest("Admin2 rejected when acting on student_org 'under_review' proposal", str_contains($resK['output'], 'Access denied'));

// Any reviewer acting on draft proposal
$actL = createTestActivity("Admin2 on Draft " . time(), 'faculty', 'draft');
$resL = executeReviewAction([
    'activity_id' => $actL,
    'action'      => 'approved'
], $usersByRole['admin2']);
assertTest("Admin2 rejected when acting on 'draft' proposal", str_contains($resL['output'], 'Access denied'));

// Any reviewer acting on approved proposal
$actM = createTestActivity("Dean on Already Approved " . time(), 'faculty', 'approved');
$resM = executeReviewAction([
    'activity_id' => $actM,
    'action'      => 'approved'
], $usersByRole['dean']);
assertTest("Dean rejected when acting on already 'approved' proposal", str_contains($resM['output'], 'Access denied'));

// -------------------------------------------------------------------------
// 5. Existing Reject and Return Actions Preservation
// -------------------------------------------------------------------------
echo "\n--- 5. Reject and Return Preserved ---\n";
// Rejection with notes succeeds
$actN = createTestActivity("Rejection Test " . time(), 'faculty', 'submitted');
$resN = executeReviewAction([
    'activity_id' => $actN,
    'action'      => 'rejected',
    'notes'       => 'Missing required budget breakdown'
], $usersByRole['admin2']);
$statusN = $db->query("SELECT status FROM activities WHERE id={$actN}")->fetchColumn();
assertTest("Rejection with notes transitions status to 'rejected'", $statusN === 'rejected');

// Return for revision with section comment succeeds
$actO = createTestActivity("Return Test " . time(), 'faculty', 'submitted');
// Insert staged section comment
$db->prepare("INSERT INTO section_comments (activity_id, reviewer_id, section_key, comment) VALUES (?, ?, 'budget', 'Update items')")
   ->execute([$actO, $usersByRole['admin2']['id']]);
$resO = executeReviewAction([
    'activity_id' => $actO,
    'action'      => 'returned',
    'notes'       => 'Please revise budget section'
], $usersByRole['admin2']);
$statusO = $db->query("SELECT status FROM activities WHERE id={$actO}")->fetchColumn();
assertTest("Return for revision with section comment transitions status to 'returned_for_revision'", $statusO === 'returned_for_revision');

// -------------------------------------------------------------------------
// CLEANUP
// -------------------------------------------------------------------------
echo "\n--- Cleanup Test Fixtures ---\n";
if (!empty($createdActivityIds)) {
    $ids = implode(',', $createdActivityIds);
    $db->exec("DELETE FROM notifications WHERE activity_id IN ({$ids})");
    $db->exec("DELETE FROM email_logs WHERE activity_id IN ({$ids})");
    $db->exec("DELETE FROM approval_logs WHERE activity_id IN ({$ids})");
    $db->exec("DELETE FROM section_comments WHERE activity_id IN ({$ids})");
    $db->exec("DELETE FROM activities WHERE id IN ({$ids})");
}
echo "Cleaned up " . count($createdActivityIds) . " test activities.\n";

echo "\n========================================================================\n";
echo " VERIFICATION SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
