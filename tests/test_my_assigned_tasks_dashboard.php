<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();

echo "============================================================\n";
echo "  MY ASSIGNED TASKS DASHBOARD VERIFICATION SUITE\n";
echo "============================================================\n\n";

$facultyStmt = $db->query("SELECT id, name FROM users WHERE role = 'faculty' LIMIT 2");
$facList = $facultyStmt->fetchAll(PDO::FETCH_ASSOC);

if (count($facList) < 2) {
    die("Need at least 2 faculty users in test database.\n");
}

$userA = $facList[0];
$userB = $facList[1];

echo "User A: {$userA['name']} (ID: {$userA['id']})\n";
echo "User B: {$userB['name']} (ID: {$userB['id']})\n\n";

$db->beginTransaction();

try {
    // 1. Create a test activity
    $db->prepare("
        INSERT INTO activities (title, description, event_date, status, faculty_id) 
        VALUES ('Multi-Task Event 2026', 'Testing dashboard isolation', '2026-12-05', 'approved', ?)
    ")->execute([$userA['id']]);
    $actId = (int)$db->lastInsertId();

    // 2. Create Task 1 assigned to User A
    $db->prepare("
        INSERT INTO faculty_tasks (activity_id, assigned_user_id, faculty_name, assigned_task, task_title, task_description, role_in_event, due_date, status, completion_pct, created_by)
        VALUES (?, ?, ?, 'Audio Mixing', 'Audio Mixing', 'Handle audio consoles', 'Sound Tech', '2026-12-01', 'In Progress', 60, ?)
    ")->execute([$actId, $userA['id'], $userA['name'], $userA['id']]);
    $taskAId = (int)$db->lastInsertId();

    // 3. Create Task 2 assigned to User B
    $db->prepare("
        INSERT INTO faculty_tasks (activity_id, assigned_user_id, faculty_name, assigned_task, task_title, task_description, role_in_event, due_date, status, completion_pct, created_by)
        VALUES (?, ?, ?, 'Stage Decoration', 'Stage Decoration', 'Setup stage backdrop', 'Decorator', '2026-12-02', 'Not Started', 0, ?)
    ")->execute([$actId, $userB['id'], $userB['name'], $userA['id']]);
    $taskBId = (int)$db->lastInsertId();

    // Query function mimicking faculty/tasks.php strictly
    function getDashboardTasks($db, $userId) {
        $stmt = $db->prepare("
            SELECT ft.*,
                   a.id as activity_id,
                   a.title as activity_title,
                   a.event_date,
                   a.status as activity_status,
                   u.name as leader_name
            FROM faculty_tasks ft
            JOIN activities a ON ft.activity_id = a.id
            JOIN users u ON a.faculty_id = u.id
            WHERE ft.assigned_user_id = ?
            ORDER BY ft.due_date ASC, ft.id DESC
        ");
        $stmt->execute([(int)$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // TEST 1: User A dashboard view
    $tasksA = getDashboardTasks($db, $userA['id']);
    $taskIdsA = array_column($tasksA, 'id');
    $pass1 = (in_array($taskAId, $taskIdsA) && !in_array($taskBId, $taskIdsA));
    echo ($pass1 ? "  [PASS]" : "  [FAIL]") . " User A sees only their assigned task (Task A: {$taskAId}) and NOT User B's task (Task B: {$taskBId})\n";

    // TEST 2: User B dashboard view
    $tasksB = getDashboardTasks($db, $userB['id']);
    $taskIdsB = array_column($tasksB, 'id');
    $pass2 = (in_array($taskBId, $taskIdsB) && !in_array($taskAId, $taskIdsB));
    echo ($pass2 ? "  [PASS]" : "  [FAIL]") . " User B sees only their assigned task (Task B: {$taskBId}) and NOT User A's task (Task A: {$taskAId})\n";

    // TEST 3: Verify fields returned for User A
    $rowA = null;
    foreach ($tasksA as $t) {
        if ((int)$t['id'] === $taskAId) { $rowA = $t; break; }
    }
    $pass3 = ($rowA !== null &&
              $rowA['activity_title'] === 'Multi-Task Event 2026' &&
              $rowA['task_title'] === 'Audio Mixing' &&
              $rowA['due_date'] === '2026-12-01' &&
              $rowA['status'] === 'In Progress' &&
              (int)$rowA['completion_pct'] === 60);
    echo ($pass3 ? "  [PASS]" : "  [FAIL]") . " Task details correctly populated (Title: '{$rowA['task_title']}', Activity: '{$rowA['activity_title']}', Due: {$rowA['due_date']}, Status: {$rowA['status']}, Progress: {$rowA['completion_pct']}%)\n";

    // TEST 4: Empty state for non-assigned user
    $tasksEmpty = getDashboardTasks($db, 999999);
    $pass4 = (count($tasksEmpty) === 0);
    echo ($pass4 ? "  [PASS]" : "  [FAIL]") . " Non-assigned user returns empty task list (triggers clean empty-state UI)\n\n";

    $db->rollBack();
    echo "Rollback successful. Database clean.\n";

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
