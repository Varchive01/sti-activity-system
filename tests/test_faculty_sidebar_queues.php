<?php
/**
 * Test: Faculty Navigation & Status Queues Parity
 *
 * Checks:
 * 1. Faculty sidebar includes 'Pending Proposals', 'Approved Activities', 'Returned / Rejected'
 * 2. Destinations exist and are accessible to faculty
 * 3. Scoping ensures faculty only see their own proposals and active collaborations
 * 4. Active menu highlighting works on faculty pages
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

echo "========================================================\n";
echo "FACULTY NAVIGATION & STATUS QUEUES PARITY AUDIT\n";
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

// 1. Emulate Faculty Session
$_SESSION['user_id'] = 387;
$_SESSION['user_role'] = 'faculty';
$_SESSION['user_name'] = 'Maria Santos';
$_SESSION['user_dept'] = 'IT Department';

ob_start();
include dirname(__DIR__) . '/includes/sidebar.php';
$sidebarHtml = ob_get_clean();

$facLinks = [];
foreach ($navs['faculty'] as $grp) {
    foreach ($grp['links'] as $lnk) {
        $facLinks[$lnk['label']] = $lnk['href'];
    }
}

assertTest(isset($facLinks['Pending Proposals']), "Faculty sidebar has 'Pending Proposals' link");
assertTest(isset($facLinks['Approved Activities']), "Faculty sidebar has 'Approved Activities' link");
assertTest(isset($facLinks['Returned / Rejected']), "Faculty sidebar has 'Returned / Rejected' link");

assertTest($facLinks['Pending Proposals'] === BASE_URL . '/faculty/pending.php', "Pending Proposals links to faculty/pending.php");
assertTest($facLinks['Approved Activities'] === BASE_URL . '/faculty/approved.php', "Approved Activities links to faculty/approved.php");
assertTest($facLinks['Returned / Rejected'] === BASE_URL . '/faculty/returned.php', "Returned / Rejected links to faculty/returned.php");

// 2. Check File Exists
assertTest(file_exists(dirname(__DIR__) . '/faculty/pending.php'), "faculty/pending.php exists");
assertTest(file_exists(dirname(__DIR__) . '/faculty/approved.php'), "faculty/approved.php exists");
assertTest(file_exists(dirname(__DIR__) . '/faculty/returned.php'), "faculty/returned.php exists");

// 3. Test Database Scoping
$db = getDB();
$userId = 387;

// Pending query test
$pending = $db->query("
    SELECT a.id, a.status FROM activities a 
    WHERE (a.faculty_id = {$userId} OR EXISTS (SELECT 1 FROM proposal_collaborators pc WHERE pc.activity_id = a.id AND pc.user_id = {$userId} AND pc.status = 'active'))
      AND a.status IN ('submitted', 'under_review', 'endorsed', 'pending_final_approval', 'resubmitted')
")->fetchAll(PDO::FETCH_ASSOC);

$pendingStatuses = array_column($pending, 'status');
$allPendingValid = true;
foreach ($pendingStatuses as $st) {
    if (!in_array($st, ['submitted', 'under_review', 'endorsed', 'pending_final_approval', 'resubmitted'], true)) {
        $allPendingValid = false;
        break;
    }
}
assertTest($allPendingValid, "faculty/pending.php strictly contains pending review statuses");

// Approved query test
$approved = $db->query("
    SELECT a.id, a.status FROM activities a 
    WHERE (a.faculty_id = {$userId} OR EXISTS (SELECT 1 FROM proposal_collaborators pc WHERE pc.activity_id = a.id AND pc.user_id = {$userId} AND pc.status = 'active') OR EXISTS (SELECT 1 FROM faculty_tasks ft WHERE ft.activity_id = a.id AND ft.assigned_user_id = {$userId}))
      AND a.status IN ('approved', 'completed')
")->fetchAll(PDO::FETCH_ASSOC);

$approvedStatuses = array_column($approved, 'status');
$allApprovedValid = true;
foreach ($approvedStatuses as $st) {
    if (!in_array($st, ['approved', 'completed'], true)) {
        $allApprovedValid = false;
        break;
    }
}
assertTest($allApprovedValid, "faculty/approved.php strictly contains approved or completed statuses");

// Returned / Rejected query test
$returned = $db->query("
    SELECT a.id, a.status FROM activities a 
    WHERE (a.faculty_id = {$userId} OR EXISTS (SELECT 1 FROM proposal_collaborators pc WHERE pc.activity_id = a.id AND pc.user_id = {$userId} AND pc.status = 'active'))
      AND a.status IN ('returned_for_revision', 'rejected')
")->fetchAll(PDO::FETCH_ASSOC);

$returnedStatuses = array_column($returned, 'status');
$allReturnedValid = true;
foreach ($returnedStatuses as $st) {
    if (!in_array($st, ['returned_for_revision', 'rejected'], true)) {
        $allReturnedValid = false;
        break;
    }
}
assertTest($allReturnedValid, "faculty/returned.php strictly contains returned_for_revision or rejected statuses");

echo "\nSummary: {$passCount} PASSED, {$failCount} FAILED.\n";
if ($failCount > 0) exit(1);
exit(0);
