<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();

echo "============================================================\n";
echo "  ASSIGNED FACULTY STATUS UPDATE VERIFICATION\n";
echo "============================================================\n\n";

// Get 3 distinct faculty members
$stmt = $db->query("SELECT id, name, email FROM users WHERE role = 'faculty' LIMIT 3");
$facList = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($facList) < 3) {
    die("Need at least 3 faculty users in test database.\n");
}

$leader   = $facList[0];
$assignee = $facList[1];
$otherFac = $facList[2];

echo "Leader:    {$leader['name']} (ID: {$leader['id']})\n";
echo "Assignee:  {$assignee['name']} (ID: {$assignee['id']})\n";
echo "Other Fac: {$otherFac['name']} (ID: {$otherFac['id']})\n\n";

$db->beginTransaction();

try {
    // 1. Create activity owned by leader
    $db->prepare("
        INSERT INTO activities (title, description, event_date, status, faculty_id) 
        VALUES ('Test Status Updates Act', 'Status test', '2026-11-25', 'approved', ?)
    ")->execute([$leader['id']]);
    $actId = (int)$db->lastInsertId();

    // 2. Create Task 1 assigned to Assignee, and Task 2 assigned to Leader
    $ins = $db->prepare("
        INSERT INTO faculty_tasks (activity_id, assigned_user_id, faculty_name, assigned_task, task_title, status, completion_pct, created_by)
        VALUES (?, ?, ?, ?, ?, 'Not Started', 50, ?)
    ");
    $ins->execute([$actId, $assignee['id'], $assignee['name'], 'Task 1', 'Task 1', $leader['id']]);
    $task1Id = (int)$db->lastInsertId();

    $ins->execute([$actId, $leader['id'], $leader['name'], 'Task 2', 'Task 2', $leader['id']]);
    $task2Id = (int)$db->lastInsertId();

    // Helper to simulate request to api/task-assignment.php
    function testStatusUpdate($db, $user, $actId, $taskId, $newStatus) {
        $activityStmt = $db->prepare("SELECT a.*, u.name as leader_name FROM activities a JOIN users u ON a.faculty_id = u.id WHERE a.id = ?");
        $activityStmt->execute([$actId]);
        $activity = $activityStmt->fetch(PDO::FETCH_ASSOC);
        if (!$activity) return ['code' => 404, 'msg' => 'Activity not found'];

        $isLeader = ($user['role'] === 'faculty' && (int)$activity['faculty_id'] === (int)$user['id']);
        $validStatuses = ['Not Started', 'In Progress', 'Completed', 'Delayed'];

        if (!in_array($newStatus, $validStatuses)) {
            return ['code' => 400, 'msg' => 'Invalid status'];
        }

        // Access check as implemented in api/task-assignment.php
        if (!$isLeader) {
            if ($user['role'] !== 'faculty') {
                return ['code' => 403, 'msg' => 'Unauthorized'];
            }
            $chk = $db->prepare("SELECT assigned_user_id FROM faculty_tasks WHERE id = ? AND activity_id = ?");
            $chk->execute([$taskId, $actId]);
            $taskAssignedUserId = $chk->fetchColumn();
            if ($taskAssignedUserId === false) return ['code' => 404, 'msg' => 'Task not found'];
            if ((int)$taskAssignedUserId !== (int)$user['id']) {
                return ['code' => 403, 'msg' => 'Unauthorized: You can only update the status of tasks assigned to you.'];
            }
        }

        // Update logic
        if ($isLeader) {
            if ($newStatus === 'Completed') {
                $statusStmt = $db->prepare("UPDATE faculty_tasks SET status = ?, completion_pct = 100 WHERE id = ? AND activity_id = ?");
            } elseif ($newStatus === 'Not Started') {
                $statusStmt = $db->prepare("UPDATE faculty_tasks SET status = ?, completion_pct = 0 WHERE id = ? AND activity_id = ?");
            } else {
                $statusStmt = $db->prepare("UPDATE faculty_tasks SET status = ? WHERE id = ? AND activity_id = ?");
            }
        } else {
            $statusStmt = $db->prepare("UPDATE faculty_tasks SET status = ? WHERE id = ? AND activity_id = ?");
        }
        $statusStmt->execute([$newStatus, $taskId, $actId]);
        return ['code' => 200, 'msg' => 'Updated'];
    }

    // TEST 1: Assignee updates their own task (Task 1) -> In Progress
    $r1 = testStatusUpdate($db, ['id' => $assignee['id'], 'role' => 'faculty'], $actId, $task1Id, 'In Progress');
    $checkT1 = $db->query("SELECT status, completion_pct FROM faculty_tasks WHERE id = {$task1Id}")->fetch(PDO::FETCH_ASSOC);
    $pass1 = ($r1['code'] === 200 && $checkT1['status'] === 'In Progress' && (int)$checkT1['completion_pct'] === 50);
    echo ($pass1 ? "  [PASS]" : "  [FAIL]") . " Assigned faculty updated own task to 'In Progress' (HTTP 200, completion_pct preserved at 50%)\n";

    // TEST 2: Assignee attempts to update Leader's task (Task 2) -> Should be blocked (403)
    $r2 = testStatusUpdate($db, ['id' => $assignee['id'], 'role' => 'faculty'], $actId, $task2Id, 'Delayed');
    $checkT2 = $db->query("SELECT status FROM faculty_tasks WHERE id = {$task2Id}")->fetchColumn();
    $pass2 = ($r2['code'] === 403 && $checkT2 === 'Not Started');
    echo ($pass2 ? "  [PASS]" : "  [FAIL]") . " Assigned faculty blocked from updating another user's task (HTTP 403)\n";

    // TEST 3: Other non-assigned faculty attempts to update Task 1 -> Should be blocked (403)
    $r3 = testStatusUpdate($db, ['id' => $otherFac['id'], 'role' => 'faculty'], $actId, $task1Id, 'Completed');
    $pass3 = ($r3['code'] === 403);
    echo ($pass3 ? "  [PASS]" : "  [FAIL]") . " Non-assigned faculty blocked from updating Task 1 (HTTP 403)\n";

    // TEST 4: Invalid status value rejected (400)
    $r4 = testStatusUpdate($db, ['id' => $assignee['id'], 'role' => 'faculty'], $actId, $task1Id, 'InvalidStatusXYZ');
    $pass4 = ($r4['code'] === 400);
    echo ($pass4 ? "  [PASS]" : "  [FAIL]") . " Invalid status value rejected (HTTP 400)\n";

    // TEST 5: Leader can update any task (Task 1 -> Completed)
    $r5 = testStatusUpdate($db, ['id' => $leader['id'], 'role' => 'faculty'], $actId, $task1Id, 'Completed');
    $checkT1Lead = $db->query("SELECT status, completion_pct FROM faculty_tasks WHERE id = {$task1Id}")->fetch(PDO::FETCH_ASSOC);
    $pass5 = ($r5['code'] === 200 && $checkT1Lead['status'] === 'Completed' && (int)$checkT1Lead['completion_pct'] === 100);
    echo ($pass5 ? "  [PASS]" : "  [FAIL]") . " Leader successfully updated Task 1 to 'Completed' (HTTP 200, completion_pct updated to 100%)\n\n";

    $db->rollBack();
    echo "Rollback successful. Database clean.\n";

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
