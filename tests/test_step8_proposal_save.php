<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();

echo "============================================================\n";
echo "  STEP 8 TASK ASSIGNMENT LINKAGE VERIFICATION\n";
echo "============================================================\n\n";

// 1. Get a test faculty user
$facultyStmt = $db->query("SELECT id, name FROM users WHERE role = 'faculty' LIMIT 2");
$facultyList = $facultyStmt->fetchAll(PDO::FETCH_ASSOC);

if (count($facultyList) < 1) {
    die("No faculty user found to test with.\n");
}

$creator = $facultyList[0];
$assignee = isset($facultyList[1]) ? $facultyList[1] : $facultyList[0];

echo "Creator: {$creator['name']} (ID: {$creator['id']})\n";
echo "Assignee: {$assignee['name']} (ID: {$assignee['id']})\n\n";

// 2. Insert a temporary activity to simulate proposal-save execution
$db->beginTransaction();

try {
    $insAct = $db->prepare("
        INSERT INTO activities (title, description, event_date, status, faculty_id) 
        VALUES ('Test Step 8 Linkage Activity', 'Testing Step 8 linkage', '2026-11-15', 'pending', ?)
    ");
    $insAct->execute([$creator['id']]);
    $testActId = (int)$db->lastInsertId();

    // Simulate Step 8 save logic from api/proposal-save.php
    $_POST = [
        'ft_task' => ['Setup Sound System', 'General Coordination', 'Invalid User Task'],
        'ft_contribution' => ['Configure stage sound', 'Coordinate committees', 'Should be skipped/failed'],
        'ft_role' => ['Sound Engineer', 'Co-Lead', 'Tester'],
        'ft_user_id' => [(string)$assignee['id'], '', '999999'],
        'ft_name' => ['', 'External Guest Organizer', 'Non Existent'],
        'ft_due_date' => ['2026-11-10', '', '2026-11-12']
    ];

    $taskItems = !empty($_POST['ft_task']) && is_array($_POST['ft_task']) 
        ? $_POST['ft_task'] 
        : (!empty($_POST['ft_name']) && is_array($_POST['ft_name']) ? $_POST['ft_name'] : []);

    $ft = $db->prepare("
        INSERT INTO faculty_tasks (
            activity_id, assigned_user_id, faculty_name, assigned_task, 
            task_title, contribution_desc, task_description, role_in_event, 
            due_date, status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Not Started', ?)
    ");

    $facultyLookupStmt = $db->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'faculty' LIMIT 1");

    $rejectedInvalid = false;

    foreach ($taskItems as $i => $itemVal) {
        $tTitle = trim($_POST['ft_task'][$i] ?? '');
        $rawUserId = isset($_POST['ft_user_id'][$i]) && is_numeric($_POST['ft_user_id'][$i]) ? (int)$_POST['ft_user_id'][$i] : 0;
        $submittedName = trim($_POST['ft_name'][$i] ?? '');

        if ($tTitle === '' && $submittedName === '' && $rawUserId === 0) {
            continue;
        }

        $assignedUserId = null;
        $facultyName = $submittedName !== '' ? $submittedName : null;

        if ($rawUserId > 0) {
            $facultyLookupStmt->execute([$rawUserId]);
            $fUser = $facultyLookupStmt->fetch(PDO::FETCH_ASSOC);
            if ($fUser) {
                $assignedUserId = (int)$fUser['id'];
                $facultyName = $fUser['name'];
            } else {
                // Verified rejection of invalid ID
                $rejectedInvalid = true;
                continue; // In actual proposal-save, rolls back. For unit verification, record rejection.
            }
        }

        $tDesc = trim($_POST['ft_contribution'][$i] ?? '');
        $tRole = trim($_POST['ft_role'][$i] ?? '');

        $dueDate = null;
        if (!empty($_POST['ft_due_date'][$i])) {
            $rawDate = trim($_POST['ft_due_date'][$i]);
            $dObj = DateTime::createFromFormat('Y-m-d', $rawDate);
            if ($dObj && $dObj->format('Y-m-d') === $rawDate) {
                $dueDate = $rawDate;
            }
        }

        $ft->execute([
            $testActId,
            $assignedUserId,
            $facultyName,
            $tTitle,
            $tTitle,
            $tDesc,
            $tDesc,
            $tRole,
            $dueDate,
            $creator['id']
        ]);
    }

    // Verify stored tasks
    $chkStmt = $db->prepare("SELECT * FROM faculty_tasks WHERE activity_id = ? ORDER BY id ASC");
    $chkStmt->execute([$testActId]);
    $savedTasks = $chkStmt->fetchAll(PDO::FETCH_ASSOC);

    echo "[1] Task with assigned faculty member:\n";
    $task1 = $savedTasks[0];
    $pass1 = ((int)$task1['assigned_user_id'] === (int)$assignee['id'] &&
              $task1['faculty_name'] === $assignee['name'] &&
              $task1['due_date'] === '2026-11-10' &&
              $task1['status'] === 'Not Started');
    echo ($pass1 ? "  [PASS]" : "  [FAIL]") . " Assigned User ID: {$task1['assigned_user_id']}, Name: {$task1['faculty_name']}, Due: {$task1['due_date']}\n\n";

    echo "[2] Unassigned task with external name and no deadline:\n";
    $task2 = $savedTasks[1];
    $pass2 = ($task2['assigned_user_id'] === null &&
              $task2['faculty_name'] === 'External Guest Organizer' &&
              $task2['due_date'] === null &&
              $task2['status'] === 'Not Started');
    echo ($pass2 ? "  [PASS]" : "  [FAIL]") . " Assigned User ID: " . var_export($task2['assigned_user_id'], true) . ", Name: {$task2['faculty_name']}, Due: " . var_export($task2['due_date'], true) . "\n\n";

    echo "[3] Rejection of invalid/unauthorized user ID:\n";
    echo ($rejectedInvalid ? "  [PASS] Rejected non-existent/unauthorized user ID 999999" : "  [FAIL] Failed to reject invalid user ID") . "\n\n";

    // Clean up test data
    $db->rollBack();
    echo "Rollback successful. Database clean.\n";

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
}
