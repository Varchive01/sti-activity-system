<?php
/**
 * api/collaborators.php
 *
 * REST API for proposal collaborator management and contribution history (Task 14).
 * Supports list, add, remove, and available faculty search.
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('faculty', 'program_head', 'dean', 'admin1', 'admin2');

$user = currentUser();
$db   = getDB();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$activityId = (int)($_GET['activity_id'] ?? $_POST['activity_id'] ?? 0);

if ($activityId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid or missing activity ID.']);
    exit;
}

// ── Action: list ─────────────────────────────────────────────────────────────
if ($action === 'list') {
    if (!canUserAccessActivity($activityId, 'view', $user['id'], $user['role'], $user['dept'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied: You cannot view collaborators for this proposal.']);
        exit;
    }

    $actStmt = $db->prepare("SELECT id, faculty_id, status FROM activities WHERE id = ? LIMIT 1");
    $actStmt->execute([$activityId]);
    $activity = $actStmt->fetch(PDO::FETCH_ASSOC);

    if (!$activity) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Activity proposal not found.']);
        exit;
    }

    $isOwner = ((int)$activity['faculty_id'] === (int)$user['id']);
    $isEditable = in_array($activity['status'], ['draft', 'returned_for_revision'], true);
    $canManage = ($isOwner || in_array($user['role'], ['program_head', 'dean', 'admin1', 'admin2'], true)) && $isEditable;

    $collaborators = getProposalCollaborators($activityId, $db);
    $logs = getProposalContributionLogs($activityId, 30, $db);

    echo json_encode([
        'success'           => true,
        'activity_id'       => $activityId,
        'status'            => $activity['status'],
        'is_editable'       => $isEditable,
        'is_owner'          => $isOwner,
        'can_manage'        => $canManage,
        'current_user_id'   => (int)$user['id'],
        'collaborator_count'=> count($collaborators),
        'collaborators'     => $collaborators,
        'contribution_logs' => $logs
    ]);
    exit;
}

// ── Action: add ──────────────────────────────────────────────────────────────
if ($action === 'add') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
        exit;
    }

    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $collabRole   = trim($_POST['role'] ?? 'contributor');

    if ($targetUserId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please select a valid faculty member.']);
        exit;
    }

    $result = addProposalCollaborator($activityId, $targetUserId, $collabRole, (int)$user['id'], $db);

    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }

    echo json_encode($result);
    exit;
}

// ── Action: remove ───────────────────────────────────────────────────────────
if ($action === 'remove') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
        exit;
    }

    $targetUserId = (int)($_POST['user_id'] ?? 0);

    if ($targetUserId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid collaborator user ID.']);
        exit;
    }

    $result = removeProposalCollaborator($activityId, $targetUserId, (int)$user['id'], $db);

    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }

    echo json_encode($result);
    exit;
}

// ── Action: search_faculty ───────────────────────────────────────────────────
if ($action === 'search_faculty') {
    if (!canUserAccessActivity($activityId, 'view', $user['id'], $user['role'], $user['dept'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied.']);
        exit;
    }

    $actStmt = $db->prepare("SELECT faculty_id FROM activities WHERE id = ? LIMIT 1");
    $actStmt->execute([$activityId]);
    $ownerId = (int)$actStmt->fetchColumn();

    // Query active collaborator IDs to exclude
    $collabStmt = $db->prepare("
        SELECT user_id FROM proposal_collaborators 
        WHERE activity_id = ? AND status = 'active'
    ");
    $collabStmt->execute([$activityId]);
    $existingIds = $collabStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $existingIds[] = $ownerId;
    $existingIds = array_unique(array_filter(array_map('intval', $existingIds)));

    $inClause = !empty($existingIds) ? implode(',', $existingIds) : '0';
    $search = trim($_GET['q'] ?? '');

    $sql = "
        SELECT id, name, email, department, role 
        FROM users 
        WHERE role IN ('faculty', 'program_head') 
          AND id NOT IN ({$inClause})
    ";
    $params = [];
    if ($search !== '') {
        $sql .= " AND (name LIKE ? OR department LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    $sql .= " ORDER BY department ASC, name ASC LIMIT 50";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $facultyList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'activity_id' => $activityId,
        'available_faculty' => $facultyList
    ]);
    exit;
}

// Default fallback
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action.']);
exit;

