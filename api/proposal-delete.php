<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

// 1. Authenticate user session
startSession();
$user = currentUser();

if (empty($user['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized: Please log in.']);
    exit;
}

// 2. Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed. Expected POST.']);
    exit;
}

// 3. Role enforcement: Faculty and Program Head (and institutional admins)
$allowedRoles = ['faculty', 'program_head', 'admin1', 'admin2', 'dean'];
if (!in_array($user['role'], $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden: Your role is not permitted to delete proposals.']);
    exit;
}

// 4. Retrieve and sanitize proposal ID
$rawInput = file_get_contents('php://input');
$json = json_decode($rawInput, true);

$proposalId = 0;
if (isset($json['id']) && is_numeric($json['id'])) {
    $proposalId = (int)$json['id'];
} elseif (isset($_POST['id']) && is_numeric($_POST['id'])) {
    $proposalId = (int)$_POST['id'];
}

if ($proposalId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid or missing proposal ID.']);
    exit;
}

$db = getDB();

// 5. Fetch target proposal
$stmt = $db->prepare("SELECT * FROM activities WHERE id = ? LIMIT 1");
$stmt->execute([$proposalId]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Proposal not found.']);
    exit;
}

// 6. STRICT ENFORCEMENT: Only proposals saved as 'draft' can be deleted
$actStatus = strtolower(trim((string)($activity['status'] ?? '')));
if ($actStatus !== 'draft') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Only proposals saved as draft can be deleted. Submitted or reviewed proposals are protected for audit compliance.'
    ]);
    exit;
}

// 7. Ownership and Permission Verification
$creatorId = (int)$activity['faculty_id'];
$isCreator = ($creatorId === (int)$user['id']);

$canDelete = false;

if ($isCreator) {
    $canDelete = true;
} elseif ($user['role'] === 'program_head') {
    // Program Head can delete their own drafts, or department drafts they have modify access to
    if (canUserAccessActivity($proposalId, 'modify', (int)$user['id'], $user['role'], $user['dept'] ?? '')) {
        $canDelete = true;
    }
} elseif (in_array($user['role'], ['admin1', 'admin2'], true)) {
    // Institutional administrators can manage draft cleanup
    $canDelete = true;
}

if (!$canDelete) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied: You can only delete your own draft proposals.']);
    exit;
}

// 8. Execute Deletion inside Database Transaction
try {
    $db->beginTransaction();

    // Collect child file paths for disk cleanup
    $filesToDelete = [];

    // Poster path
    if (!empty($activity['poster_path'])) {
        $filesToDelete[] = $activity['poster_path'];
    }

    // Floor plans
    $fpStmt = $db->prepare("SELECT file_path FROM floor_plans WHERE activity_id = ?");
    $fpStmt->execute([$proposalId]);
    while ($row = $fpStmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['file_path'])) {
            $filesToDelete[] = $row['file_path'];
        }
    }

    // Documents
    $docStmt = $db->prepare("SELECT file_path FROM documents WHERE activity_id = ?");
    $docStmt->execute([$proposalId]);
    while ($row = $docStmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['file_path'])) {
            $filesToDelete[] = $row['file_path'];
        }
    }

    // Task Deliverables & Tasks
    $tasksStmt = $db->prepare("SELECT id FROM faculty_tasks WHERE activity_id = ?");
    $tasksStmt->execute([$proposalId]);
    $taskIds = $tasksStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($taskIds)) {
        $inClause = implode(',', array_map('intval', $taskIds));
        
        // Find deliverables files
        $delivStmt = $db->query("SELECT file_path FROM task_deliverables WHERE task_id IN ($inClause)");
        while ($dRow = $delivStmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($dRow['file_path'])) {
                $filesToDelete[] = $dRow['file_path'];
            }
        }
        $db->exec("DELETE FROM task_deliverables WHERE task_id IN ($inClause)");
        $db->exec("DELETE FROM task_reminder_logs WHERE task_id IN ($inClause)");
    }

    // Delete related child tables
    $childQueries = [
        "DELETE FROM faculty_tasks WHERE activity_id = ?",
        "DELETE FROM floor_plans WHERE activity_id = ?",
        "DELETE FROM documents WHERE activity_id = ?",
        "DELETE FROM materials WHERE activity_id = ?",
        "DELETE FROM manpower WHERE activity_id = ?",
        "DELETE FROM schedules WHERE activity_id = ?",
        "DELETE FROM program_sequence WHERE activity_id = ?",
        "DELETE FROM guidelines WHERE activity_id = ?",
        "DELETE FROM kpi_evaluations WHERE activity_id = ?",
        "DELETE FROM proposal_collaborators WHERE activity_id = ?",
        "DELETE FROM proposal_contributions WHERE activity_id = ?",
        "DELETE FROM proposal_comments WHERE proposal_id = ?",
        "DELETE FROM proposal_ai_validation WHERE proposal_id = ?",
        "DELETE FROM section_revisions WHERE activity_id = ?",
        "DELETE FROM approval_logs WHERE activity_id = ?",
        "DELETE FROM notifications WHERE activity_id = ?",
        "DELETE FROM feedback_analysis WHERE activity_id = ?",
        "DELETE FROM kpi_analytics WHERE activity_id = ?",
        "DELETE FROM activities WHERE id = ?"
    ];

    foreach ($childQueries as $sql) {
        try {
            $delStmt = $db->prepare($sql);
            $delStmt->execute([$proposalId]);
        } catch (\PDOException $ex) {
            // If table does not exist or has cascade already, continue safely
        }
    }

    $db->commit();

    // 9. Remove Physical Uploaded Files
    $baseUploadDir = defined('UPLOAD_PATH') ? realpath(UPLOAD_PATH) : realpath(__DIR__ . '/../uploads');
    if ($baseUploadDir && is_dir($baseUploadDir)) {
        foreach ($filesToDelete as $fPath) {
            $cleanRel = ltrim(str_replace('\\', '/', $fPath), '/');
            if (str_starts_with($cleanRel, 'uploads/')) {
                $cleanRel = substr($cleanRel, 8);
            }
            $target = realpath($baseUploadDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanRel));
            if ($target && is_file($target) && str_starts_with(str_replace('\\', '/', $target), str_replace('\\', '/', $baseUploadDir))) {
                @unlink($target);
            }
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Draft proposal "' . ($activity['title'] ?: 'Untitled Draft') . '" deleted successfully.',
        'deleted_id' => $proposalId
    ]);
    exit;

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error deleting draft: ' . $e->getMessage()
    ]);
    exit;
}
