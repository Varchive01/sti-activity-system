<?php
/**
 * api/task-assignment.php
 *
 * REST API for Task & Role Assignment foundation.
 * Allows the faculty member leading an activity to assign work to specific people and committees.
 *
 * Authorization:
 * - Activity leader (faculty who owns the activity) can manage assignments (create, update, delete, update_status).
 * - Dean can view assignments.
 * - Admins (admin1, admin2) can view assignments according to existing activity access.
 * - Other faculty/unauthorized users are denied access (403).
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

startSession();
$user = currentUser();
if (empty($user['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$db = getDB();

// Read input from POST, JSON body, or GET
$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true) ?: [];
$params = array_merge($_GET, $_POST, $jsonInput);

$action = trim($params['action'] ?? 'list');
$activityId = (int)($params['activity_id'] ?? 0);

if (!$activityId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required activity_id']);
    exit;
}

// Fetch activity and verify existence
$actStmt = $db->prepare("
    SELECT a.*, u.name as leader_name, u.email as leader_email, u.department as leader_dept
    FROM activities a
    JOIN users u ON a.faculty_id = u.id
    WHERE a.id = ?
");
$actStmt->execute([$activityId]);
$activity = $actStmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Activity not found']);
    exit;
}

$isLeader = ($user['role'] === 'faculty' && (int)$activity['faculty_id'] === (int)$user['id']);
$isDean = ($user['role'] === 'dean');
$isAdmin = in_array($user['role'], ['admin1', 'admin2']);

// Valid statuses definition
$validStatuses = ['Not Started', 'In Progress', 'Completed', 'Delayed'];

// ── Check Access ───────────────────────────────────────────────
if (in_array($action, ['create', 'update', 'delete', 'update_status'])) {
    // Only the activity leader can mutate task assignments
    if (!$isLeader) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized: Only the faculty activity leader can manage assignments for this activity.'
        ]);
        exit;
    }
} else {
    // Read access check
    if ($user['role'] === 'faculty' && !$isLeader) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized: You do not have access to view assignments for this activity.'
        ]);
        exit;
    }
    if (!$isLeader && !$isDean && !$isAdmin) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized access.'
        ]);
        exit;
    }
}

// Helper to sanitize text
function cleanText(?string $val): string {
    return trim($val ?? '');
}

// ── ACTION: LIST ──────────────────────────────────────────────
if ($action === 'list') {
    $tasksStmt = $db->prepare("
        SELECT ft.*, 
               u.name as assigned_user_name, 
               u.email as assigned_user_email,
               u.department as assigned_user_dept,
               cb.name as creator_name
        FROM faculty_tasks ft
        LEFT JOIN users u ON ft.assigned_user_id = u.id
        LEFT JOIN users cb ON ft.created_by = cb.id
        WHERE ft.activity_id = ?
        ORDER BY ft.id ASC
    ");
    $tasksStmt->execute([$activityId]);
    $rawTasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);

    $tasks = [];
    foreach ($rawTasks as $t) {
        $tasks[] = [
            'id' => (int)$t['id'],
            'activity_id' => (int)$t['activity_id'],
            'task_title' => cleanText($t['task_title'] ?: $t['assigned_task']),
            'task_description' => cleanText($t['task_description'] ?: $t['contribution_desc']),
            'committee' => cleanText($t['committee']),
            'assigned_user_id' => $t['assigned_user_id'] ? (int)$t['assigned_user_id'] : null,
            'assigned_member' => cleanText($t['assigned_user_name'] ?: $t['faculty_name']),
            'assigned_user_name' => cleanText($t['assigned_user_name']),
            'role' => cleanText($t['role_in_event']),
            'due_date' => $t['due_date'] ? date('Y-m-d', strtotime($t['due_date'])) : null,
            'status' => in_array($t['status'], $validStatuses) ? $t['status'] : 'Not Started',
            'completion_pct' => (int)($t['completion_pct'] ?? 0),
            'created_by' => $t['created_by'] ? (int)$t['created_by'] : null,
            'creator_name' => cleanText($t['creator_name']),
            'created_at' => $t['created_at'],
            'updated_at' => $t['updated_at']
        ];
    }

    // Available users for assignment dropdown
    $usersStmt = $db->query("SELECT id, name, email, role, department FROM users WHERE role IN ('faculty','admin1','admin2') ORDER BY name ASC");
    $systemUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

    // Available manpower from this activity
    $mpStmt = $db->prepare("SELECT role, assigned_person, type FROM manpower WHERE activity_id = ?");
    $mpStmt->execute([$activityId]);
    $manpowerPeople = $mpStmt->fetchAll(PDO::FETCH_ASSOC);

    // Available standard committees
    $standardCommittees = [
        'Events Committee',
        'Technical & Logistics Committee',
        'Registration & Ushering Committee',
        'Program & Stage Committee',
        'Documentation & Media Committee',
        'Health & Safety Committee',
        'Finance & Materials Committee',
        'Student Organization',
        'IT Department'
    ];

    echo json_encode([
        'success' => true,
        'activity' => [
            'id' => (int)$activity['id'],
            'title' => $activity['title'],
            'status' => $activity['status'],
            'event_date' => $activity['event_date'],
            'leader_id' => (int)$activity['faculty_id'],
            'leader_name' => $activity['leader_name'],
            'leader_email' => $activity['leader_email'],
            'leader_dept' => $activity['leader_dept'],
            'can_manage' => $isLeader
        ],
        'tasks' => $tasks,
        'available_users' => $systemUsers,
        'available_manpower' => $manpowerPeople,
        'available_committees' => $standardCommittees,
        'valid_statuses' => $validStatuses
    ]);
    exit;
}

// ── ACTION: CREATE ────────────────────────────────────────────
if ($action === 'create') {
    $taskTitle = cleanText($params['task_title'] ?? $params['assigned_task'] ?? '');
    if ($taskTitle === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Task title is required.']);
        exit;
    }

    $taskDesc = cleanText($params['task_description'] ?? $params['contribution_desc'] ?? '');
    $committee = cleanText($params['committee'] ?? '');
    $role = cleanText($params['role'] ?? $params['role_in_event'] ?? '');
    $assignedUserId = !empty($params['assigned_user_id']) ? (int)$params['assigned_user_id'] : null;
    $assignedMember = cleanText($params['assigned_member'] ?? $params['faculty_name'] ?? '');

    // If user ID provided, get user name
    if ($assignedUserId) {
        $uCheck = $db->prepare("SELECT name FROM users WHERE id = ?");
        $uCheck->execute([$assignedUserId]);
        $uName = $uCheck->fetchColumn();
        if ($uName) {
            $assignedMember = $uName;
        } else {
            $assignedUserId = null;
        }
    } elseif ($assignedMember !== '') {
        // Try to match with registered user
        $uMatch = $db->prepare("SELECT id FROM users WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $uMatch->execute([$assignedMember]);
        $matchedId = $uMatch->fetchColumn();
        if ($matchedId) {
            $assignedUserId = (int)$matchedId;
        }
    }

    $dueDate = !empty($params['due_date']) ? date('Y-m-d', strtotime($params['due_date'])) : null;
    $status = cleanText($params['status'] ?? 'Not Started');
    if (!in_array($status, $validStatuses)) {
        $status = 'Not Started';
    }

    $insertStmt = $db->prepare("
        INSERT INTO faculty_tasks (
            activity_id, assigned_user_id, faculty_name, committee,
            assigned_task, task_title, contribution_desc, task_description,
            role_in_event, due_date, status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $insertStmt->execute([
        $activityId,
        $assignedUserId,
        $assignedMember ?: null,
        $committee ?: null,
        $taskTitle,
        $taskTitle,
        $taskDesc ?: null,
        $taskDesc ?: null,
        $role ?: null,
        $dueDate,
        $status,
        $user['id']
    ]);

    $newTaskId = (int)$db->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Task assignment created successfully.',
        'task_id' => $newTaskId,
        'task' => [
            'id' => $newTaskId,
            'activity_id' => $activityId,
            'task_title' => $taskTitle,
            'task_description' => $taskDesc,
            'committee' => $committee,
            'assigned_user_id' => $assignedUserId,
            'assigned_member' => $assignedMember,
            'role' => $role,
            'due_date' => $dueDate,
            'status' => $status,
            'created_by' => $user['id'],
            'created_at' => date('Y-m-d H:i:s')
        ]
    ]);
    exit;
}

// ── ACTION: UPDATE ────────────────────────────────────────────
if ($action === 'update') {
    $taskId = (int)($params['id'] ?? $params['task_id'] ?? 0);
    if (!$taskId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing task ID.']);
        exit;
    }

    // Verify task exists and belongs to this activity
    $checkTask = $db->prepare("SELECT id FROM faculty_tasks WHERE id = ? AND activity_id = ?");
    $checkTask->execute([$taskId, $activityId]);
    if (!$checkTask->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Task assignment not found for this activity.']);
        exit;
    }

    $taskTitle = cleanText($params['task_title'] ?? $params['assigned_task'] ?? '');
    if ($taskTitle === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Task title is required.']);
        exit;
    }

    $taskDesc = cleanText($params['task_description'] ?? $params['contribution_desc'] ?? '');
    $committee = cleanText($params['committee'] ?? '');
    $role = cleanText($params['role'] ?? $params['role_in_event'] ?? '');
    $assignedUserId = !empty($params['assigned_user_id']) ? (int)$params['assigned_user_id'] : null;
    $assignedMember = cleanText($params['assigned_member'] ?? $params['faculty_name'] ?? '');

    if ($assignedUserId) {
        $uCheck = $db->prepare("SELECT name FROM users WHERE id = ?");
        $uCheck->execute([$assignedUserId]);
        $uName = $uCheck->fetchColumn();
        if ($uName) {
            $assignedMember = $uName;
        } else {
            $assignedUserId = null;
        }
    } elseif ($assignedMember !== '') {
        $uMatch = $db->prepare("SELECT id FROM users WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $uMatch->execute([$assignedMember]);
        $matchedId = $uMatch->fetchColumn();
        if ($matchedId) {
            $assignedUserId = (int)$matchedId;
        }
    }

    $dueDate = !empty($params['due_date']) ? date('Y-m-d', strtotime($params['due_date'])) : null;
    $status = cleanText($params['status'] ?? 'Not Started');
    if (!in_array($status, $validStatuses)) {
        $status = 'Not Started';
    }

    $updateStmt = $db->prepare("
        UPDATE faculty_tasks SET
            assigned_user_id = ?,
            faculty_name = ?,
            committee = ?,
            assigned_task = ?,
            task_title = ?,
            contribution_desc = ?,
            task_description = ?,
            role_in_event = ?,
            due_date = ?,
            status = ?
        WHERE id = ? AND activity_id = ?
    ");

    $updateStmt->execute([
        $assignedUserId,
        $assignedMember ?: null,
        $committee ?: null,
        $taskTitle,
        $taskTitle,
        $taskDesc ?: null,
        $taskDesc ?: null,
        $role ?: null,
        $dueDate,
        $status,
        $taskId,
        $activityId
    ]);

    // Check task progress reminders
    checkTaskProgressReminders($db, null, null, $activityId);

    echo json_encode([
        'success' => true,
        'message' => 'Task assignment updated successfully.',
        'task' => [
            'id' => $taskId,
            'activity_id' => $activityId,
            'task_title' => $taskTitle,
            'task_description' => $taskDesc,
            'committee' => $committee,
            'assigned_user_id' => $assignedUserId,
            'assigned_member' => $assignedMember,
            'role' => $role,
            'due_date' => $dueDate,
            'status' => $status
        ]
    ]);
    exit;
}

// ── ACTION: UPDATE STATUS ─────────────────────────────────────
if ($action === 'update_status') {
    $taskId = (int)($params['id'] ?? $params['task_id'] ?? 0);
    if (!$taskId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing task ID.']);
        exit;
    }

    $newStatus = cleanText($params['status'] ?? '');
    if (!in_array($newStatus, $validStatuses)) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => 'Invalid status. Allowed values: ' . implode(', ', $validStatuses)
        ]);
        exit;
    }

    if ($newStatus === 'Completed') {
        $statusStmt = $db->prepare("UPDATE faculty_tasks SET status = ?, completion_pct = 100 WHERE id = ? AND activity_id = ?");
    } elseif ($newStatus === 'Not Started') {
        $statusStmt = $db->prepare("UPDATE faculty_tasks SET status = ?, completion_pct = 0 WHERE id = ? AND activity_id = ?");
    } else {
        $statusStmt = $db->prepare("UPDATE faculty_tasks SET status = ? WHERE id = ? AND activity_id = ?");
    }
    $statusStmt->execute([$newStatus, $taskId, $activityId]);

    if ($statusStmt->rowCount() === 0) {
        // Confirm task exists
        $c = $db->prepare("SELECT id, status FROM faculty_tasks WHERE id = ? AND activity_id = ?");
        $c->execute([$taskId, $activityId]);
        $curr = $c->fetch(PDO::FETCH_ASSOC);
        if (!$curr) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Task assignment not found.']);
            exit;
        }
    }

    if ($newStatus !== 'Completed') {
        $db->prepare("DELETE FROM task_reminder_logs WHERE task_id = ? AND reminder_type = 'completed'")->execute([$taskId]);
    }

    // Check task progress reminders
    checkTaskProgressReminders($db, null, null, $activityId);

    echo json_encode([
        'success' => true,
        'message' => "Task status updated to {$newStatus}.",
        'task_id' => $taskId,
        'status' => $newStatus
    ]);
    exit;
}

// ── ACTION: DELETE ────────────────────────────────────────────
if ($action === 'delete') {
    $taskId = (int)($params['id'] ?? $params['task_id'] ?? 0);
    if (!$taskId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing task ID.']);
        exit;
    }

    $delStmt = $db->prepare("DELETE FROM faculty_tasks WHERE id = ? AND activity_id = ?");
    $delStmt->execute([$taskId, $activityId]);

    if ($delStmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Task assignment not found or already deleted.']);
        exit;
    }

    $db->prepare("DELETE FROM task_reminder_logs WHERE task_id = ?")->execute([$taskId]);

    echo json_encode([
        'success' => true,
        'message' => 'Task assignment removed successfully.'
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => "Unknown action '{$action}'."]);
