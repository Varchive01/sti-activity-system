<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();

echo "============================================================\n";
echo "  STEP 8 PROPOSAL EDIT RECONCILIATION VERIFICATION\n";
echo "============================================================\n\n";

$facultyStmt = $db->query("SELECT id, name FROM users WHERE role = 'faculty' LIMIT 2");
$facultyList = $facultyStmt->fetchAll(PDO::FETCH_ASSOC);

if (count($facultyList) < 1) {
    die("No faculty user found to test with.\n");
}

$creator = $facultyList[0];
$assignee = isset($facultyList[1]) ? $facultyList[1] : $facultyList[0];

$db->beginTransaction();

try {
    // 1. Create a draft activity with an existing legacy task (NULL user, NULL due date)
    $insAct = $db->prepare("
        INSERT INTO activities (title, description, event_date, status, faculty_id) 
        VALUES ('Test Edit Task Activity', 'Testing Step 8 edit linkage', '2026-11-20', 'draft', ?)
    ");
    $insAct->execute([$creator['id']]);
    $actId = (int)$db->lastInsertId();

    $insTask = $db->prepare("
        INSERT INTO faculty_tasks (activity_id, assigned_user_id, faculty_name, assigned_task, task_title, contribution_desc, task_description, role_in_event, due_date, status, created_by)
        VALUES (?, NULL, 'Legacy Freeform Name', 'Legacy Task', 'Legacy Task', 'Legacy Desc', 'Legacy Desc', 'Leader', NULL, 'Not Started', ?)
    ");
    $insTask->execute([$actId, $creator['id']]);
    $existingTaskId = (int)$db->lastInsertId();

    echo "Initial setup: Activity {$actId}, Task {$existingTaskId} (assigned_user_id: NULL, due_date: NULL)\n\n";

    // 2. Simulate proposal-update: Update existing task in place + add a new task
    $existingTasksStmt = $db->prepare("SELECT id FROM faculty_tasks WHERE activity_id = ?");
    $existingTasksStmt->execute([$actId]);
    $existingTaskIds = $existingTasksStmt->fetchAll(PDO::FETCH_COLUMN);
    $existingTaskIdsMap = array_flip($existingTaskIds);

    $submittedKeepIds = [];

    $updateTaskStmt = $db->prepare("
        UPDATE faculty_tasks 
        SET assigned_user_id = ?,
            faculty_name = ?,
            assigned_task = ?,
            task_title = ?,
            contribution_desc = ?,
            task_description = ?,
            role_in_event = ?,
            due_date = ?
        WHERE id = ? AND activity_id = ?
    ");

    $insertTaskStmt = $db->prepare("
        INSERT INTO faculty_tasks (
            activity_id, assigned_user_id, faculty_name, assigned_task, 
            task_title, contribution_desc, task_description, role_in_event, 
            due_date, status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Not Started', ?)
    ");

    $facultyLookupStmt = $db->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'faculty' LIMIT 1");

    $_POST = [
        'ft_id' => [(string)$existingTaskId, ''],
        'ft_task' => ['Updated Legacy Task Title', 'Brand New Task'],
        'ft_contribution' => ['Updated contribution description', 'New task contribution'],
        'ft_role' => ['Technical Lead', 'Assistant'],
        'ft_user_id' => [(string)$assignee['id'], ''],
        'ft_name' => ['', 'External Guest Assistant'],
        'ft_due_date' => ['2026-11-18', '2026-11-19']
    ];

    $taskItems = !empty($_POST['ft_task']) && is_array($_POST['ft_task']) ? $_POST['ft_task'] : [];

    foreach ($taskItems as $i => $itemVal) {
        $tTitle = trim($_POST['ft_task'][$i] ?? '');
        $rawTaskId = isset($_POST['ft_id'][$i]) ? (int)$_POST['ft_id'][$i] : 0;
        $rawUserId = isset($_POST['ft_user_id'][$i]) && is_numeric($_POST['ft_user_id'][$i]) ? (int)$_POST['ft_user_id'][$i] : 0;
        $submittedName = trim($_POST['ft_name'][$i] ?? '');

        $assignedUserId = null;
        $facultyName = $submittedName !== '' ? $submittedName : null;

        if ($rawUserId > 0) {
            $facultyLookupStmt->execute([$rawUserId]);
            $fUser = $facultyLookupStmt->fetch(PDO::FETCH_ASSOC);
            if ($fUser) {
                $assignedUserId = (int)$fUser['id'];
                $facultyName = $fUser['name'];
            }
        }

        $tDesc  = trim($_POST['ft_contribution'][$i] ?? '');
        $tRole  = trim($_POST['ft_role'][$i] ?? '');

        $dueDate = null;
        if (!empty($_POST['ft_due_date'][$i])) {
            $rawDate = trim($_POST['ft_due_date'][$i]);
            $dObj = DateTime::createFromFormat('Y-m-d', $rawDate);
            if ($dObj && $dObj->format('Y-m-d') === $rawDate) {
                $dueDate = $rawDate;
            }
        }

        if ($rawTaskId > 0 && isset($existingTaskIdsMap[$rawTaskId])) {
            $updateTaskStmt->execute([
                $assignedUserId, $facultyName, $tTitle, $tTitle, $tDesc, $tDesc, $tRole, $dueDate, $rawTaskId, $actId
            ]);
            $submittedKeepIds[] = $rawTaskId;
        } else {
            $insertTaskStmt->execute([
                $actId, $assignedUserId, $facultyName, $tTitle, $tTitle, $tDesc, $tDesc, $tRole, $dueDate, $creator['id']
            ]);
        }
    }

    // 3. Verify in-place update and new task
    $fetchTasks = $db->prepare("SELECT * FROM faculty_tasks WHERE activity_id = ? ORDER BY id ASC");
    $fetchTasks->execute([$actId]);
    $currentTasks = $fetchTasks->fetchAll(PDO::FETCH_ASSOC);

    echo "[1] Confirm existing task ID is preserved and updated in place:\n";
    $updatedTask = $currentTasks[0];
    $pass1 = ((int)$updatedTask['id'] === $existingTaskId &&
              (int)$updatedTask['assigned_user_id'] === (int)$assignee['id'] &&
              $updatedTask['faculty_name'] === $assignee['name'] &&
              $updatedTask['task_title'] === 'Updated Legacy Task Title' &&
              $updatedTask['due_date'] === '2026-11-18');
    echo ($pass1 ? "  [PASS]" : "  [FAIL]") . " Task ID: {$updatedTask['id']} (Expected: {$existingTaskId}), Assigned: {$updatedTask['assigned_user_id']}, Due: {$updatedTask['due_date']}\n\n";

    echo "[2] Confirm newly added task was inserted:\n";
    $newTask = $currentTasks[1];
    $pass2 = ($newTask['id'] > $existingTaskId &&
              $newTask['assigned_user_id'] === null &&
              $newTask['faculty_name'] === 'External Guest Assistant' &&
              $newTask['due_date'] === '2026-11-19');
    echo ($pass2 ? "  [PASS]" : "  [FAIL]") . " New Task ID: {$newTask['id']}, Name: {$newTask['faculty_name']}, Due: {$newTask['due_date']}\n\n";

    echo "[3] Verify validation checks (invalid role / invalid date):\n";
    // Test invalid role (e.g. ID not in faculty role)
    $facultyLookupStmt->execute([999999]);
    $invalidUser = $facultyLookupStmt->fetch(PDO::FETCH_ASSOC);
    $pass3a = ($invalidUser === false);
    echo ($pass3a ? "  [PASS] Non-existent/unauthorized user ID 999999 rejected" : "  [FAIL] Failed to reject invalid user ID") . "\n";

    // Test invalid date format
    $badDate = "2026-02-31"; // invalid calendar date
    $dObj = DateTime::createFromFormat('Y-m-d', $badDate);
    $pass3b = (!$dObj || $dObj->format('Y-m-d') !== $badDate);
    echo ($pass3b ? "  [PASS] Invalid calendar date {$badDate} rejected" : "  [FAIL] Failed to reject invalid calendar date") . "\n\n";

    // Clean up
    $db->rollBack();
    echo "Rollback successful. Database clean.\n";

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
}
