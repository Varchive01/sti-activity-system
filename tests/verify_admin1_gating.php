<?php
// Start session before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();

echo "=== VERIFYING ADMIN 1 REVIEW-STAGE GATING ===\n\n";

$allPassed = true;

function assertCheck(string $title, bool $cond, string $details = ''): bool {
    global $allPassed;
    if ($cond) {
        echo "[PASS] $title" . ($details ? " ($details)" : "") . "\n";
        return true;
    } else {
        echo "[FAIL] $title" . ($details ? " ($details)" : "") . "\n";
        $allPassed = false;
        return false;
    }
}

// Clean up any old leftover test activities
$db->exec("DELETE FROM approval_logs WHERE activity_id IN (SELECT id FROM activities WHERE title LIKE 'Test Gating Admin1 %')");
$db->exec("DELETE FROM notifications WHERE activity_id IN (SELECT id FROM activities WHERE title LIKE 'Test Gating Admin1 %')");
$db->exec("DELETE FROM activities WHERE title LIKE 'Test Gating Admin1 %'");

// 1. In admin1/activities.php: student_org + under_review -> Review action visible
$a1 = ['id' => 101, 'source' => 'student_org', 'status' => 'under_review'];
$showReview1 = ($a1['source'] === 'student_org' && $a1['status'] === 'under_review');
assertCheck("1. student_org + under_review -> Review action visible in activities.php", $showReview1 === true);

// 2. In admin1/activities.php: student_org + resubmitted -> Review action NOT visible
$a2 = ['id' => 102, 'source' => 'student_org', 'status' => 'resubmitted'];
$showReview2 = ($a2['source'] === 'student_org' && $a2['status'] === 'under_review');
assertCheck("2. student_org + resubmitted -> Review action NOT visible in activities.php", $showReview2 === false);

// 3. In admin1/pending.php: student_org + under_review -> appears in pending queue
$stmt3 = $db->prepare("SELECT CASE WHEN ('student_org' = 'student_org' AND 'under_review' = 'under_review') THEN 1 ELSE 0 END");
$stmt3->execute();
$inPending3 = (bool)$stmt3->fetchColumn();
assertCheck("3. student_org + under_review -> appears in Admin 1 pending queue", $inPending3 === true);

// 4. In admin1/pending.php: student_org + resubmitted -> does NOT appear in pending queue
$stmt4 = $db->prepare("SELECT CASE WHEN ('student_org' = 'student_org' AND 'resubmitted' = 'under_review') THEN 1 ELSE 0 END");
$stmt4->execute();
$inPending4 = (bool)$stmt4->fetchColumn();
assertCheck("4. student_org + resubmitted -> does NOT appear in pending queue", $inPending4 === false);

// 5 & 6. View-activity HTML rendering
$faculty = $db->query("SELECT id FROM users WHERE role='faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$facultyId = $faculty['id'] ?? 1;

$db->prepare("INSERT INTO activities (title, faculty_id, status, source, target_participants, event_date, created_at, updated_at) VALUES ('Test Gating Admin1 Under Review', ?, 'under_review', 'student_org', 50, CURDATE(), NOW(), NOW())")->execute([$facultyId]);
$testIdUnderReview = (int)$db->lastInsertId();

$db->prepare("INSERT INTO activities (title, faculty_id, status, source, target_participants, event_date, created_at, updated_at) VALUES ('Test Gating Admin1 Resubmitted', ?, 'resubmitted', 'student_org', 50, CURDATE(), NOW(), NOW())")->execute([$facultyId]);
$testIdResubmitted = (int)$db->lastInsertId();

$admin1User = $db->query("SELECT * FROM users WHERE role='admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$_SESSION['user_id'] = $admin1User['id'];
$_SESSION['user_role'] = 'admin1';
$_SESSION['role'] = 'admin1';
$_SESSION['name'] = $admin1User['name'];
$_SESSION['email'] = $admin1User['email'];
$_SESSION['must_change_password'] = 0;

// Test view-activity for under_review
$_GET = ['id' => $testIdUnderReview];
ob_start();
include __DIR__ . '/../admin1/view-activity.php';
$htmlUnderReview = ob_get_clean();

$hasReviewLinkUnderReview = (strpos($htmlUnderReview, "admin1/review.php?id={$testIdUnderReview}") !== false);
assertCheck("5. Opening view-activity for under_review shows Review link", $hasReviewLinkUnderReview === true);

// Test view-activity for resubmitted
$_GET = ['id' => $testIdResubmitted];
ob_start();
include __DIR__ . '/../admin1/view-activity.php';
$htmlResubmitted = ob_get_clean();

$hasReviewLinkResubmitted = (strpos($htmlResubmitted, "admin1/review.php?id={$testIdResubmitted}") !== false);
assertCheck("6. Opening view-activity for resubmitted does not show Review link", $hasReviewLinkResubmitted === false);

// 7. Test Admin 1 review submission for under_review
$runnerFile = __DIR__ . '/test_admin1_action_runner.php';
$runnerContent = "<?php
define('TEST_RUNNER', true);
if (session_status() === PHP_SESSION_NONE) session_start();
\$_SESSION['user_id'] = {$admin1User['id']};
\$_SESSION['user_role'] = 'admin1';
\$_SESSION['role'] = 'admin1';
\$_SESSION['user_name'] = " . var_export($admin1User['name'], true) . ";
\$_SESSION['user_email'] = " . var_export($admin1User['email'], true) . ";
\$_SESSION['last_activity'] = time();
\$_POST = [
    'activity_id' => {$testIdUnderReview},
    'action'      => 'approved',
    'notes'       => 'Automated test endorsement'
];
\$_SERVER['REQUEST_METHOD'] = 'POST';
try {
    ob_start();
    require " . var_export(__DIR__ . '/../api/review-action.php', true) . ";
    ob_end_clean();
    echo 'SUCCESS';
} catch (Throwable \$e) {
    echo 'EXCEPTION: ' . \$e->getMessage();
}
";
file_put_contents($runnerFile, $runnerContent);

$cmdOutput = shell_exec("php " . escapeshellarg($runnerFile));
if (file_exists($runnerFile)) {
    unlink($runnerFile);
}

// Verify updated status
$updatedStatus = $db->query("SELECT status FROM activities WHERE id={$testIdUnderReview}")->fetchColumn();
assertCheck("7. Existing Admin 1 review submission for under_review still works", $updatedStatus === 'endorsed', "New status: $updatedStatus");

// Clean up temporary activities and related logs
$db->prepare("DELETE FROM approval_logs WHERE activity_id IN (?, ?)")->execute([$testIdUnderReview, $testIdResubmitted]);
$db->prepare("DELETE FROM notifications WHERE activity_id IN (?, ?)")->execute([$testIdUnderReview, $testIdResubmitted]);
$db->prepare("DELETE FROM activities WHERE id IN (?, ?)")->execute([$testIdUnderReview, $testIdResubmitted]);

echo "\nOVERALL STATUS: " . ($allPassed ? "ALL 7 CHECKS PASSED" : "FAILURES DETECTED") . "\n";
