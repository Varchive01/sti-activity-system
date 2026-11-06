<?php
require_once __DIR__ . '/../config/database.php';

$db = getDB();

echo "=== CHECKING faculty_tasks SCHEMA & INDEXES ===" . PHP_EOL;
$stmt = $db->query("SHOW INDEX FROM faculty_tasks");
$indexes = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $indexes[] = $row['Key_name'] . ' (' . $row['Column_name'] . ', Non_unique=' . $row['Non_unique'] . ')';
}
echo implode(PHP_EOL, $indexes) . PHP_EOL . PHP_EOL;

echo "=== TESTING DUPLICATE TASK NAMES AND DUPLICATE ASSIGNEES ===" . PHP_EOL;

// Start transaction to safely test without polluting database
$db->beginTransaction();

try {
    // 1. Get an existing activity or create a dummy one
    $actStmt = $db->query("SELECT id FROM activities LIMIT 1");
    $actId = $actStmt->fetchColumn();

    if (!$actId) {
        echo "No activity found to test." . PHP_EOL;
        $db->rollBack();
        exit(1);
    }

    echo "Using Activity ID: " . $actId . PHP_EOL;

    // 2. Fetch a valid faculty user
    $userStmt = $db->query("SELECT id, name FROM users WHERE role = 'faculty' LIMIT 1");
    $facultyUser = $userStmt->fetch(PDO::FETCH_ASSOC);
    $facultyId = $facultyUser['id'];
    $facultyName = $facultyUser['name'];
    echo "Using Faculty User: ID={$facultyId}, Name='{$facultyName}'" . PHP_EOL;

    $insertStmt = $db->prepare("
        INSERT INTO faculty_tasks (
            activity_id, assigned_user_id, faculty_name, assigned_task,
            task_title, contribution_desc, task_description, role_in_event,
            due_date, status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Not Started', ?)
    ");

    // Insert Task 1: "Registration Officer" assigned to the same faculty
    $insertStmt->execute([
        $actId, $facultyId, $facultyName, "Registration Officer",
        "Registration Officer", "Manage attendance booth", "Manage attendance booth",
        "Lead Officer", date('Y-m-d', strtotime('+3 days')), $facultyId
    ]);
    $task1Id = $db->lastInsertId();
    echo "Task 1 created successfully with ID: {$task1Id} (Name: 'Registration Officer', Assignee: '{$facultyName}')" . PHP_EOL;

    // Insert Task 2: EXACT SAME NAME "Registration Officer" and EXACT SAME ASSIGNEE
    $insertStmt->execute([
        $actId, $facultyId, $facultyName, "Registration Officer",
        "Registration Officer", "Manage second attendance booth", "Manage second attendance booth",
        "Assistant Officer", date('Y-m-d', strtotime('+3 days')), $facultyId
    ]);
    $task2Id = $db->lastInsertId();
    echo "Task 2 created successfully with ID: {$task2Id} (Name: 'Registration Officer', Assignee: '{$facultyName}')" . PHP_EOL;

    // Verify both exist
    $checkStmt = $db->prepare("SELECT id, task_title, faculty_name FROM faculty_tasks WHERE id IN (?, ?)");
    $checkStmt->execute([$task1Id, $task2Id]);
    $results = $checkStmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($results) . " tasks in database:" . PHP_EOL;
    foreach ($results as $r) {
        echo " - ID: " . $r['id'] . " | Title: '" . $r['task_title'] . "' | Assignee: '" . $r['faculty_name'] . "'" . PHP_EOL;
    }

    // Roll back so we leave the DB clean
    $db->rollBack();
    echo PHP_EOL . "Test completed: No errors were thrown. Transaction rolled back successfully." . PHP_EOL;
} catch (Exception $e) {
    $db->rollBack();
    echo "ERROR OCCURRED: " . $e->getMessage() . PHP_EOL;
}
