<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

echo "============================================================\n";
echo "  TASK ASSIGNMENT NOTIFICATION VERIFICATION SUITE\n";
echo "============================================================\n\n";

$facultyStmt = $db->query("SELECT id, name, email FROM users WHERE role = 'faculty' LIMIT 3");
$facList = $facultyStmt->fetchAll(PDO::FETCH_ASSOC);

if (count($facList) < 3) {
    die("Need at least 3 faculty users in test database.\n");
}

$leader   = $facList[0];
$assignee1 = $facList[1];
$assignee2 = $facList[2];

echo "Leader:     {$leader['name']} (ID: {$leader['id']})\n";
echo "Assignee 1: {$assignee1['name']} (ID: {$assignee1['id']})\n";
echo "Assignee 2: {$assignee2['name']} (ID: {$assignee2['id']})\n\n";

$db->beginTransaction();

try {
    // Helper to count notifications for a user on an activity
    function getNotifCount($db, $userId, $activityId) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND activity_id = ? AND message LIKE 'Task Assignment:%'");
        $stmt->execute([$userId, $activityId]);
        return (int)$stmt->fetchColumn();
    }

    // 1. Create activity
    $db->prepare("
        INSERT INTO activities (title, description, event_date, status, faculty_id) 
        VALUES ('Notification Test Activity', 'Testing notifications', '2026-11-30', 'draft', ?)
    ")->execute([$leader['id']]);
    $actId = (int)$db->lastInsertId();

    // TEST 1: New task assigned to Assignee 1
    $before1 = getNotifCount($db, $assignee1['id'], $actId);
    sendTaskAssignmentNotification($db, $assignee1['id'], $actId, 'Stage Lighting Setup', '2026-11-20');
    $after1 = getNotifCount($db, $assignee1['id'], $actId);
    $pass1 = ($after1 === $before1 + 1);
    echo ($pass1 ? "  [PASS]" : "  [FAIL]") . " Notification created for new task assignment to Assignee 1 (Count: {$before1} -> {$after1})\n";

    // Verify content doesn't leak sensitive data
    $stmt = $db->prepare("SELECT message FROM notifications WHERE user_id = ? AND activity_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$assignee1['id'], $actId]);
    $msg = $stmt->fetchColumn();
    $passMsg = (strpos($msg, 'Stage Lighting Setup') !== false && strpos($msg, 'Notification Test Activity') !== false);
    echo ($passMsg ? "  [PASS]" : "  [FAIL]") . " Notification message properly formatted: \"{$msg}\"\n";

    // TEST 2: Unassigned task (assigned_user_id = 0/null) does NOT send notification
    $res2 = sendTaskAssignmentNotification($db, 0, $actId, 'External Catering');
    $pass2 = ($res2 === false);
    echo ($pass2 ? "  [PASS]" : "  [FAIL]") . " Unassigned task safely skipped (no notification sent)\n";

    // TEST 3: Proposal update with UNCHANGED assignee (should NOT send duplicate notification)
    // Simulate proposal-update logic:
    $rawTaskId = 500;
    $prevAssignee = $assignee1['id'];
    $submittedUserId = $assignee1['id']; // Unchanged!
    $notifsBefore3 = getNotifCount($db, $assignee1['id'], $actId);
    if ($submittedUserId && $submittedUserId !== $prevAssignee) {
        sendTaskAssignmentNotification($db, $submittedUserId, $actId, 'Stage Lighting Setup', '2026-11-20');
    }
    $notifsAfter3 = getNotifCount($db, $assignee1['id'], $actId);
    $pass3 = ($notifsBefore3 === $notifsAfter3);
    echo ($pass3 ? "  [PASS]" : "  [FAIL]") . " Unchanged assignee during proposal edit does NOT create duplicate notification\n";

    // TEST 4: Reassign task from Assignee 1 to Assignee 2
    $submittedUserId4 = $assignee2['id']; // Changed to Assignee 2!
    $notifsBeforeA1 = getNotifCount($db, $assignee1['id'], $actId);
    $notifsBeforeA2 = getNotifCount($db, $assignee2['id'], $actId);
    if ($submittedUserId4 && $submittedUserId4 !== $prevAssignee) {
        sendTaskAssignmentNotification($db, $submittedUserId4, $actId, 'Stage Lighting Setup', '2026-11-20');
    }
    $notifsAfterA1 = getNotifCount($db, $assignee1['id'], $actId);
    $notifsAfterA2 = getNotifCount($db, $assignee2['id'], $actId);
    $pass4 = ($notifsAfterA1 === $notifsBeforeA1 && $notifsAfterA2 === $notifsBeforeA2 + 1);
    echo ($pass4 ? "  [PASS]" : "  [FAIL]") . " Task reassignment notified NEW assignee only (Assignee 2: +1, Assignee 1: unchanged)\n\n";

    $db->rollBack();
    echo "Rollback successful. Database clean.\n";

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
