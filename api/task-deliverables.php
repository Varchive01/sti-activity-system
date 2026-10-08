<?php
/**
 * api/task-deliverables.php
 *
 * REST API for Deliverable Upload and Review for assigned activity tasks.
 *
 * Capabilities:
 * 1. Assigned user can upload files for their own task.
 * 2. Store deliverables against the task assignment and parent activity.
 * 3. Activity leader can review deliverables, set completion percentage,
 *    mark task completed, or return deliverable for improvement.
 * 4. Dean can view deliverables and completion state read-only.
 * 5. Protected file download routing via api/document-download.php.
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

function cleanStr(?string $val): string {
    return trim($val ?? '');
}

// ── ACTION: UPLOAD DELIVERABLE ─────────────────────────────────────
if ($action === 'upload') {
    $taskId = (int)($params['task_id'] ?? 0);
    if (!$taskId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required task_id']);
        exit;
    }

    // Verify task exists and retrieve activity leadership and assignment info
    $tStmt = $db->prepare("
        SELECT ft.*, 
               a.id as activity_id, 
               a.faculty_id as leader_id, 
               a.title as activity_title,
               u.name as assigned_name,
               u.email as assigned_email
        FROM faculty_tasks ft
        JOIN activities a ON ft.activity_id = a.id
        LEFT JOIN users u ON ft.assigned_user_id = u.id
        WHERE ft.id = ?
        LIMIT 1
    ");
    $tStmt->execute([$taskId]);
    $task = $tStmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Task assignment not found']);
        exit;
    }

    $isLeader = ($user['role'] === 'faculty' && (int)$task['leader_id'] === (int)$user['id']);
    $isAssigned = ((int)$task['assigned_user_id'] === (int)$user['id']);

    // Check authorization: ONLY the assigned user or the activity leader can upload deliverables
    if (!$isAssigned && !$isLeader) {
        http_response_code(403);
        echo json_encode([
            'success' => false, 
            'error' => 'Forbidden: You are not assigned to this task and cannot upload deliverables for it.'
        ]);
        exit;
    }

    // Validate uploaded file
    $fileKey = isset($_FILES['deliverable_file']) ? 'deliverable_file' : (isset($_FILES['file']) ? 'file' : null);
    if (!$fileKey || empty($_FILES[$fileKey]['name'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No deliverable file provided']);
        exit;
    }

    $uploadedFile = $_FILES[$fileKey];
    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'File upload error code: ' . $uploadedFile['error']]);
        exit;
    }

    $originalName = $uploadedFile['name'];
    $fileSize     = (int)$uploadedFile['size'];
    $mimeType     = (!empty($uploadedFile['tmp_name']) && file_exists($uploadedFile['tmp_name'])) 
                    ? (mime_content_type($uploadedFile['tmp_name']) ?: ($uploadedFile['type'] ?? 'application/octet-stream')) 
                    : ($uploadedFile['type'] ?? 'application/octet-stream');
    $notes        = cleanStr($params['notes'] ?? $params['description'] ?? '');

    // Store deliverable using existing system file storage
    $savedPath = uploadFile($uploadedFile, 'deliverables', (int)$task['activity_id']);
    if (!$savedPath) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => 'Invalid file. Ensure the file is under 10MB and has an allowed extension (' . implode(', ', ALLOWED_EXTENSIONS) . ').'
        ]);
        exit;
    }

    // Insert into task_deliverables table
    $ins = $db->prepare("
        INSERT INTO task_deliverables (
            task_id, activity_id, file_name, file_path, file_size, mime_type, uploaded_by, review_status, review_notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)
    ");
    $ins->execute([
        $taskId,
        (int)$task['activity_id'],
        $originalName,
        $savedPath,
        $fileSize,
        $mimeType,
        $user['id'],
        $notes ?: null
    ]);

    $deliverableId = (int)$db->lastInsertId();

    // If task was 'Not Started', advance to 'In Progress' upon deliverable submission
    if ($task['status'] === 'Not Started') {
        $db->prepare("UPDATE faculty_tasks SET status = 'In Progress' WHERE id = ?")->execute([$taskId]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Deliverable uploaded successfully.',
        'deliverable' => [
            'id'             => $deliverableId,
            'task_id'        => $taskId,
            'task_title'     => $task['task_title'],
            'activity_id'    => (int)$task['activity_id'],
            'file_name'      => $originalName,
            'file_size'      => $fileSize,
            'mime_type'      => $mimeType,
            'uploaded_by'    => $user['id'],
            'uploader_name'  => $user['name'] ?? '',
            'uploaded_at'    => date('Y-m-d H:i:s'),
            'review_status'  => 'pending',
            'review_notes'   => $notes,
            'download_url'   => BASE_URL . '/api/document-download.php?deliverable_id=' . $deliverableId
        ]
    ]);
    exit;
}

// ── ACTION: LIST DELIVERABLES ──────────────────────────────────────
if ($action === 'list') {
    $taskId     = (int)($params['task_id'] ?? 0);
    $activityId = (int)($params['activity_id'] ?? 0);

    if (!$taskId && !$activityId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Must provide task_id or activity_id']);
        exit;
    }

    if ($taskId) {
        $tStmt = $db->prepare("
            SELECT ft.*, a.faculty_id as leader_id, a.id as act_id
            FROM faculty_tasks ft
            JOIN activities a ON ft.activity_id = a.id
            WHERE ft.id = ?
        ");
        $tStmt->execute([$taskId]);
        $task = $tStmt->fetch(PDO::FETCH_ASSOC);
        if (!$task) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Task not found']);
            exit;
        }
        $activityId = (int)$task['act_id'];
    } else {
        $actStmt = $db->prepare("SELECT id, faculty_id FROM activities WHERE id = ?");
        $actStmt->execute([$activityId]);
        $actRow = $actStmt->fetch(PDO::FETCH_ASSOC);
        if (!$actRow) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Activity not found']);
            exit;
        }
    }

    // Authorization check
    $role = $user['role'] ?? '';
    $isAuthorized = false;

    if (in_array($role, ['dean', 'admin1', 'admin2'], true)) {
        $isAuthorized = true;
    } elseif ($role === 'faculty') {
        // Activity leader
        $checkLeader = $db->prepare("SELECT id FROM activities WHERE id = ? AND faculty_id = ?");
        $checkLeader->execute([$activityId, $user['id']]);
        if ($checkLeader->fetch()) {
            $isAuthorized = true;
        } else {
            // Check if assigned to any task in this activity
            $checkAssigned = $db->prepare("SELECT id FROM faculty_tasks WHERE activity_id = ? AND assigned_user_id = ?");
            $checkAssigned->execute([$activityId, $user['id']]);
            if ($checkAssigned->fetch()) {
                $isAuthorized = true;
            }
        }
    }

    if (!$isAuthorized) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden: Access denied to view deliverables.']);
        exit;
    }

    $sql = "
        SELECT td.*, 
               ft.task_title,
               ft.status as task_status,
               ft.completion_pct,
               ft.assigned_user_id,
               u.name as uploader_name,
               u.email as uploader_email,
               r.name as reviewer_name
        FROM task_deliverables td
        JOIN faculty_tasks ft ON td.task_id = ft.id
        JOIN users u ON td.uploaded_by = u.id
        LEFT JOIN users r ON td.reviewed_by = r.id
        WHERE " . ($taskId ? "td.task_id = ?" : "td.activity_id = ?") . "
        ORDER BY td.uploaded_at DESC, td.id DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$taskId ?: $activityId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $deliverables = [];
    foreach ($rows as $r) {
        $deliverables[] = [
            'id'             => (int)$r['id'],
            'task_id'        => (int)$r['task_id'],
            'task_title'     => $r['task_title'],
            'activity_id'    => (int)$r['activity_id'],
            'file_name'      => $r['file_name'],
            'file_size'      => (int)$r['file_size'],
            'mime_type'      => $r['mime_type'],
            'download_url'   => BASE_URL . '/api/document-download.php?deliverable_id=' . $r['id'],
            'uploaded_by'    => (int)$r['uploaded_by'],
            'uploader_name'  => $r['uploader_name'],
            'uploader_email' => $r['uploader_email'],
            'uploaded_at'    => $r['uploaded_at'],
            'review_status'  => $r['review_status'],
            'review_notes'   => $r['review_notes'],
            'reviewed_by'    => $r['reviewed_by'] ? (int)$r['reviewed_by'] : null,
            'reviewer_name'  => $r['reviewer_name'],
            'reviewed_at'    => $r['reviewed_at'],
            'task_status'    => $r['task_status'],
            'completion_pct' => (int)$r['completion_pct']
        ];
    }

    echo json_encode([
        'success'      => true,
        'deliverables' => $deliverables
    ]);
    exit;
}

// ── ACTION: REVIEW DELIVERABLE ─────────────────────────────────────
if ($action === 'review') {
    $deliverableId = (int)($params['deliverable_id'] ?? $params['id'] ?? 0);
    if (!$deliverableId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing deliverable_id']);
        exit;
    }

    // Retrieve deliverable, task, and activity
    $dStmt = $db->prepare("
        SELECT td.*, 
               ft.id as ft_task_id, 
               ft.task_title, 
               ft.task_description, 
               ft.status as current_status, 
               ft.completion_pct as current_pct,
               ft.due_date,
               a.faculty_id as leader_id
        FROM task_deliverables td
        JOIN faculty_tasks ft ON td.task_id = ft.id
        JOIN activities a ON td.activity_id = a.id
        WHERE td.id = ?
        LIMIT 1
    ");
    $dStmt->execute([$deliverableId]);
    $item = $dStmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Deliverable not found']);
        exit;
    }

    // Authorization: ONLY the activity leader can review deliverables and update completion
    $isLeader = ($user['role'] === 'faculty' && (int)$item['leader_id'] === (int)$user['id']);
    if (!$isLeader) {
        http_response_code(403);
        echo json_encode([
            'success' => false, 
            'error' => 'Forbidden: Only the faculty activity leader can review deliverables and set completion percentage.'
        ]);
        exit;
    }

    $reviewStatus = cleanStr($params['review_status'] ?? 'approved');
    if (!in_array($reviewStatus, ['pending', 'approved', 'returned_for_improvement'], true)) {
        $reviewStatus = 'approved';
    }

    $notes = cleanStr($params['notes'] ?? $params['review_notes'] ?? '');
    $markCompleted = !empty($params['mark_completed']);
    
    // Explicit completion percentage requested
    $completionPctInput = isset($params['completion_pct']) ? (int)$params['completion_pct'] : null;

    $taskId = (int)$item['ft_task_id'];
    $currentPct = (int)$item['current_pct'];
    $currentStatus = $item['current_status'];
    $isOverdue = (!empty($item['due_date']) && $item['due_date'] < date('Y-m-d'));

    // Completion behavior calculation:
    // 0-99% = incomplete
    // 100% = completed
    // Returning for improvement must not automatically mark task completed.
    if ($markCompleted) {
        $newPct = 100;
        $newStatus = 'Completed';
    } elseif ($reviewStatus === 'returned_for_improvement') {
        // Returning for improvement must NOT be completed
        if ($completionPctInput !== null) {
            $newPct = max(0, min(99, $completionPctInput));
        } else {
            $newPct = ($currentPct >= 100) ? 50 : $currentPct;
        }
        $newStatus = $isOverdue ? 'Delayed' : 'In Progress';
    } elseif ($completionPctInput !== null) {
        $newPct = max(0, min(100, $completionPctInput));
        if ($newPct === 100) {
            $newStatus = 'Completed';
        } elseif ($newPct === 0) {
            $newStatus = $isOverdue ? 'Delayed' : 'Not Started';
        } else {
            $newStatus = $isOverdue ? 'Delayed' : 'In Progress';
        }
    } else {
        // Default when approved without explicit %
        if ($reviewStatus === 'approved') {
            $newPct = max(50, $currentPct);
            $newStatus = ($newPct === 100) ? 'Completed' : ($isOverdue ? 'Delayed' : 'In Progress');
        } else {
            $newPct = $currentPct;
            $newStatus = $currentStatus;
        }
    }

    // Update deliverable record
    $db->prepare("
        UPDATE task_deliverables 
        SET review_status = ?, review_notes = ?, reviewed_by = ?, reviewed_at = NOW() 
        WHERE id = ?
    ")->execute([$reviewStatus, $notes ?: null, $user['id'], $deliverableId]);

    // Update task completion_pct and status
    $desc = $item['task_description'] ?: '';
    if (preg_match('/(?:progress|completion)?\s*[:=\-]?\s*\d{1,3}%/i', $desc)) {
        $desc = preg_replace('/(?:progress|completion)?\s*[:=\-]?\s*\d{1,3}%/i', 'Progress: ' . $newPct . '%', $desc);
    } else {
        $desc = trim($desc . "\nProgress: " . $newPct . '%');
    }

    $db->prepare("
        UPDATE faculty_tasks 
        SET completion_pct = ?, status = ?, task_description = ? 
        WHERE id = ?
    ")->execute([$newPct, $newStatus, $desc, $taskId]);

    if ($newPct < 100 && $newStatus !== 'Completed') {
        $db->prepare("DELETE FROM task_reminder_logs WHERE task_id = ? AND reminder_type = 'completed'")->execute([$taskId]);
    }

    // Check task progress reminders
    checkTaskProgressReminders($db, null, null, (int)$item['activity_id']);

    echo json_encode([
        'success'        => true,
        'message'        => 'Deliverable review saved successfully.',
        'deliverable_id' => $deliverableId,
        'review_status'  => $reviewStatus,
        'review_notes'   => $notes,
        'task_id'        => $taskId,
        'completion_pct' => $newPct,
        'task_status'    => $newStatus
    ]);
    exit;
}

// ── ACTION: SET COMPLETION PERCENTAGE ──────────────────────────────
if ($action === 'set_completion') {
    $taskId = (int)($params['task_id'] ?? 0);
    if (!$taskId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing task_id']);
        exit;
    }

    $tStmt = $db->prepare("
        SELECT ft.*, a.faculty_id as leader_id 
        FROM faculty_tasks ft 
        JOIN activities a ON ft.activity_id = a.id 
        WHERE ft.id = ?
    ");
    $tStmt->execute([$taskId]);
    $task = $tStmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Task not found']);
        exit;
    }

    $isLeader = ($user['role'] === 'faculty' && (int)$task['leader_id'] === (int)$user['id']);
    if (!$isLeader) {
        http_response_code(403);
        echo json_encode([
            'success' => false, 
            'error' => 'Forbidden: Only the faculty activity leader can set completion percentage.'
        ]);
        exit;
    }

    $pctInput = isset($params['completion_pct']) ? (int)$params['completion_pct'] : 0;
    $newPct = max(0, min(100, $pctInput));
    $markCompleted = !empty($params['mark_completed']);
    if ($markCompleted) {
        $newPct = 100;
    }

    $isOverdue = (!empty($task['due_date']) && $task['due_date'] < date('Y-m-d'));
    if ($newPct === 100) {
        $newStatus = 'Completed';
    } elseif ($newPct === 0) {
        $newStatus = $isOverdue ? 'Delayed' : 'Not Started';
    } else {
        $newStatus = $isOverdue ? 'Delayed' : 'In Progress';
    }

    $desc = $task['task_description'] ?: '';
    if (preg_match('/(?:progress|completion)?\s*[:=\-]?\s*\d{1,3}%/i', $desc)) {
        $desc = preg_replace('/(?:progress|completion)?\s*[:=\-]?\s*\d{1,3}%/i', 'Progress: ' . $newPct . '%', $desc);
    } else {
        $desc = trim($desc . "\nProgress: " . $newPct . '%');
    }

    $db->prepare("
        UPDATE faculty_tasks 
        SET completion_pct = ?, status = ?, task_description = ? 
        WHERE id = ?
    ")->execute([$newPct, $newStatus, $desc, $taskId]);

    if ($newPct < 100 && $newStatus !== 'Completed') {
        $db->prepare("DELETE FROM task_reminder_logs WHERE task_id = ? AND reminder_type = 'completed'")->execute([$taskId]);
    }

    // Check task progress reminders
    checkTaskProgressReminders($db, null, null, (int)$task['activity_id']);

    echo json_encode([
        'success'        => true,
        'task_id'        => $taskId,
        'completion_pct' => $newPct,
        'status'         => $newStatus,
        'message'        => "Task completion set to {$newPct}% ({$newStatus})."
    ]);
    exit;
}

// ── ACTION: DELETE DELIVERABLE ─────────────────────────────────────
if ($action === 'delete') {
    $deliverableId = (int)($params['deliverable_id'] ?? $params['id'] ?? 0);
    if (!$deliverableId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing deliverable_id']);
        exit;
    }

    $dStmt = $db->prepare("
        SELECT td.*, a.faculty_id as leader_id 
        FROM task_deliverables td
        JOIN activities a ON td.activity_id = a.id
        WHERE td.id = ?
    ");
    $dStmt->execute([$deliverableId]);
    $item = $dStmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Deliverable not found']);
        exit;
    }

    $isLeader = ($user['role'] === 'faculty' && (int)$item['leader_id'] === (int)$user['id']);
    $isUploader = ((int)$item['uploaded_by'] === (int)$user['id']);

    if (!$isLeader && !$isUploader) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden: You cannot delete this deliverable.']);
        exit;
    }

    // Delete record from DB
    $db->prepare("DELETE FROM task_deliverables WHERE id = ?")->execute([$deliverableId]);

    // Safely remove local file if within UPLOAD_PATH
    $localPath = realpath(UPLOAD_PATH . $item['file_path']);
    if ($localPath && is_file($localPath) && str_starts_with(str_replace('\\', '/', $localPath), str_replace('\\', '/', realpath(UPLOAD_PATH)))) {
        @unlink($localPath);
    }

    echo json_encode(['success' => true, 'message' => 'Deliverable removed successfully.']);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => "Unknown action '{$action}'."]);
