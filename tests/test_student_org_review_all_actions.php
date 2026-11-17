<?php
/**
 * Comprehensive Test Suite for Student Organization Head (admin1) Review Actions
 * Covers: Approve, Reject, Return for Revision across all sources (Faculty & Program Head)
 * and verifies that unauthorized actions are blocked.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();
echo "=== COMPREHENSIVE TEST: STUDENT ORG HEAD (ADMIN1) REVIEW ACTIONS ===\n\n";

$allPassed = true;
function assertCheck(bool $condition, string $label, string $details = '') {
    global $allPassed;
    if ($condition) {
        echo "[PASS] $label" . ($details ? " ($details)" : "") . "\n";
    } else {
        echo "[FAIL] $label" . ($details ? " ($details)" : "") . "\n";
        $allPassed = false;
    }
}

// Users
$phUser = $db->query("SELECT * FROM users WHERE role='program_head' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin1User = $db->query("SELECT * FROM users WHERE role='admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$facUser = $db->query("SELECT * FROM users WHERE role='faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

function runReview(int $actId, string $action, array $user, string $notes = ''): array {
    $runnerFile = __DIR__ . '/runner_' . bin2hex(random_bytes(6)) . '.php';
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
    echo json_encode(['success' => true, 'output' => \$out, 'http_code' => http_response_code()]);
} catch (Throwable \$e) {
    echo json_encode(['success' => false, 'error' => \$e->getMessage(), 'http_code' => http_response_code()]);
}
";
    file_put_contents($runnerFile, $code);
    $output = shell_exec("php " . escapeshellarg($runnerFile));
    if (file_exists($runnerFile)) {
        unlink($runnerFile);
    }
    return ($output !== null && $output !== '') ? (json_decode($output, true) ?: ['success' => false, 'raw' => $output]) : ['success' => false, 'raw' => ''];
}

function createProposal(int $authorId, string $title, string $source, string $status): int {
    global $db;
    $db->prepare("
        INSERT INTO activities (faculty_id, title, source, status, event_date, target_participants, created_at, updated_at)
        VALUES (?, ?, ?, ?, CURDATE(), 60, NOW(), NOW())
    ")->execute([$authorId, $title, $source, $status]);
    return (int)$db->lastInsertId();
}

function cleanUp(array $ids) {
    global $db;
    if (empty($ids)) return;
    $in = implode(',', array_map('intval', $ids));
    $db->exec("DELETE FROM section_comments WHERE activity_id IN ($in)");
    $db->exec("DELETE FROM approval_logs WHERE activity_id IN ($in)");
    $db->exec("DELETE FROM notifications WHERE activity_id IN ($in)");
    $db->exec("DELETE FROM activities WHERE id IN ($in)");
}

$cleanupIds = [];

// -------------------------------------------------------------------------
// SECTION A: Student Org Source Proposals (by Faculty)
// -------------------------------------------------------------------------
echo "--- SECTION A: Student Org Source Proposals (Authored by Faculty) ---\n";

// A1: Approve
$idA1 = createProposal($facUser['id'], 'Test Faculty Student Org - Approve', 'student_org', 'under_review');
$cleanupIds[] = $idA1;
runReview($idA1, 'approved', $admin1User);
$stA1 = $db->query("SELECT status FROM activities WHERE id={$idA1}")->fetchColumn();
assertCheck($stA1 === 'endorsed', "Admin 1 Approve on Student Org proposal transitions to 'endorsed'", "Status: $stA1");

// A2: Return for Revision (with staged section comment)
$idA2 = createProposal($facUser['id'], 'Test Faculty Student Org - Return', 'student_org', 'under_review');
$cleanupIds[] = $idA2;
$db->prepare("INSERT INTO section_comments (activity_id, reviewer_id, section_key, comment) VALUES (?, ?, 'materials', 'Add cost breakdown')")->execute([$idA2, $admin1User['id']]);
runReview($idA2, 'returned', $admin1User);
$stA2 = $db->query("SELECT status FROM activities WHERE id={$idA2}")->fetchColumn();
assertCheck($stA2 === 'returned_for_revision', "Admin 1 Return on Student Org proposal transitions to 'returned_for_revision'", "Status: $stA2");

// A3: Reject (with mandatory rejection comment)
$idA3 = createProposal($facUser['id'], 'Test Faculty Student Org - Reject', 'student_org', 'under_review');
$cleanupIds[] = $idA3;
runReview($idA3, 'rejected', $admin1User, 'Proposal does not adhere to campus club guidelines');
$stA3 = $db->query("SELECT status FROM activities WHERE id={$idA3}")->fetchColumn();
assertCheck($stA3 === 'rejected', "Admin 1 Reject on Student Org proposal transitions to 'rejected'", "Status: $stA3");

// -------------------------------------------------------------------------
// SECTION B: Program Head Proposals (source = 'faculty')
// -------------------------------------------------------------------------
echo "\n--- SECTION B: Program Head Proposals (source='faculty') ---\n";

// B1: Approve
$idB1 = createProposal($phUser['id'], 'Test PH Faculty - Approve', 'faculty', 'under_review');
$cleanupIds[] = $idB1;
runReview($idB1, 'approved', $admin1User);
$stB1 = $db->query("SELECT status FROM activities WHERE id={$idB1}")->fetchColumn();
assertCheck($stB1 === 'endorsed', "Admin 1 Approve on Program Head proposal transitions to 'endorsed'", "Status: $stB1");

// B2: Return for Revision
$idB2 = createProposal($phUser['id'], 'Test PH Faculty - Return', 'faculty', 'under_review');
$cleanupIds[] = $idB2;
$db->prepare("INSERT INTO section_comments (activity_id, reviewer_id, section_key, comment) VALUES (?, ?, 'objectives', 'Align with ABET accreditation')")->execute([$idB2, $admin1User['id']]);
runReview($idB2, 'returned', $admin1User);
$stB2 = $db->query("SELECT status FROM activities WHERE id={$idB2}")->fetchColumn();
assertCheck($stB2 === 'returned_for_revision', "Admin 1 Return on Program Head proposal transitions to 'returned_for_revision'", "Status: $stB2");

// B3: Reject
$idB3 = createProposal($phUser['id'], 'Test PH Faculty - Reject', 'faculty', 'under_review');
$cleanupIds[] = $idB3;
runReview($idB3, 'rejected', $admin1User, 'Curriculum review required first');
$stB3 = $db->query("SELECT status FROM activities WHERE id={$idB3}")->fetchColumn();
assertCheck($stB3 === 'rejected', "Admin 1 Reject on Program Head proposal transitions to 'rejected'", "Status: $stB3");

// -------------------------------------------------------------------------
// SECTION C: Program Head Proposals (source = 'student_org')
// -------------------------------------------------------------------------
echo "\n--- SECTION C: Program Head Proposals (source='student_org') ---\n";

// C1: Approve
$idC1 = createProposal($phUser['id'], 'Test PH Org - Approve', 'student_org', 'under_review');
$cleanupIds[] = $idC1;
runReview($idC1, 'approved', $admin1User);
$stC1 = $db->query("SELECT status FROM activities WHERE id={$idC1}")->fetchColumn();
assertCheck($stC1 === 'endorsed', "Admin 1 Approve on PH Student Org proposal transitions to 'endorsed'", "Status: $stC1");

// C2: Return for Revision
$idC2 = createProposal($phUser['id'], 'Test PH Org - Return', 'student_org', 'under_review');
$cleanupIds[] = $idC2;
$db->prepare("INSERT INTO section_comments (activity_id, reviewer_id, section_key, comment) VALUES (?, ?, 'people', 'List student officers')")->execute([$idC2, $admin1User['id']]);
runReview($idC2, 'returned', $admin1User);
$stC2 = $db->query("SELECT status FROM activities WHERE id={$idC2}")->fetchColumn();
assertCheck($stC2 === 'returned_for_revision', "Admin 1 Return on PH Student Org proposal transitions to 'returned_for_revision'", "Status: $stC2");

// C3: Reject
$idC3 = createProposal($phUser['id'], 'Test PH Org - Reject', 'student_org', 'under_review');
$cleanupIds[] = $idC3;
runReview($idC3, 'rejected', $admin1User, 'Insufficient student leadership involvement');
$stC3 = $db->query("SELECT status FROM activities WHERE id={$idC3}")->fetchColumn();
assertCheck($stC3 === 'rejected', "Admin 1 Reject on PH Student Org proposal transitions to 'rejected'", "Status: $stC3");

// -------------------------------------------------------------------------
// SECTION D: Mandatory Rules & Security Validation Checks
// -------------------------------------------------------------------------
echo "\n--- SECTION D: Mandatory Rules & Security Validation Checks ---\n";

// D1: Return without section comments must be rejected/redirected
$idD1 = createProposal($phUser['id'], 'Test Return Without Comments', 'faculty', 'under_review');
$cleanupIds[] = $idD1;
runReview($idD1, 'returned', $admin1User);
$stD1 = $db->query("SELECT status FROM activities WHERE id={$idD1}")->fetchColumn();
assertCheck($stD1 === 'under_review', "Return without section comments is blocked (status remains 'under_review')", "Status: $stD1");

// D2: Reject without comment must be rejected/redirected
$idD2 = createProposal($phUser['id'], 'Test Reject Without Notes', 'faculty', 'under_review');
$cleanupIds[] = $idD2;
runReview($idD2, 'rejected', $admin1User, '   ');
$stD2 = $db->query("SELECT status FROM activities WHERE id={$idD2}")->fetchColumn();
assertCheck($stD2 === 'under_review', "Reject with empty note is blocked (status remains 'under_review')", "Status: $stD2");

// D3: Unauthorized role (Faculty) calling review-action
$idD3 = createProposal($facUser['id'], 'Test Unauthorized Faculty Review', 'student_org', 'under_review');
$cleanupIds[] = $idD3;
$resFacReview = runReview($idD3, 'approved', $facUser);
$stD3 = $db->query("SELECT status FROM activities WHERE id={$idD3}")->fetchColumn();
assertCheck($stD3 === 'under_review', "Regular Faculty is blocked from executing review action (status remains 'under_review')", "Status: $stD3");

// D4: Out-of-stage review (Admin 1 attempting review on 'endorsed' proposal)
$idD4 = createProposal($facUser['id'], 'Test Out-of-stage Endorsed', 'student_org', 'endorsed');
$cleanupIds[] = $idD4;
runReview($idD4, 'approved', $admin1User);
$stD4 = $db->query("SELECT status FROM activities WHERE id={$idD4}")->fetchColumn();
assertCheck($stD4 === 'endorsed', "Admin 1 cannot review an already 'endorsed' proposal", "Status: $stD4");

// D5: Out-of-stage review (Admin 1 attempting review on 'approved' proposal)
$idD5 = createProposal($facUser['id'], 'Test Out-of-stage Approved', 'student_org', 'approved');
$cleanupIds[] = $idD5;
runReview($idD5, 'rejected', $admin1User, 'Attempting to reject approved');
$stD5 = $db->query("SELECT status FROM activities WHERE id={$idD5}")->fetchColumn();
assertCheck($stD5 === 'approved', "Admin 1 cannot review an already 'approved' proposal", "Status: $stD5");

// D6: Out-of-stage review (Admin 1 attempting review on 'rejected' proposal)
$idD6 = createProposal($facUser['id'], 'Test Out-of-stage Rejected', 'student_org', 'rejected');
$cleanupIds[] = $idD6;
runReview($idD6, 'approved', $admin1User);
$stD6 = $db->query("SELECT status FROM activities WHERE id={$idD6}")->fetchColumn();
assertCheck($stD6 === 'rejected', "Admin 1 cannot review an already 'rejected' proposal", "Status: $stD6");

// Clean up test data
cleanUp($cleanupIds);

echo "\n=======================================================\n";
echo "SUMMARY: " . ($allPassed ? "ALL 15 COMPREHENSIVE CHECKS PASSED!" : "FAILURES DETECTED!") . "\n";
echo "=======================================================\n";

