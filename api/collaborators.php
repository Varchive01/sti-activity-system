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
$sessionToken = trim($_GET['session_token'] ?? $_POST['session_token'] ?? '');

if ($activityId <= 0 && $sessionToken === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid or missing activity ID or session token.']);
    exit;
}

// Ensure session is started and temporary workspace initialized if session_token is provided
if ($sessionToken !== '') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['temp_collaborators'][$sessionToken]) || !is_array($_SESSION['temp_collaborators'][$sessionToken])) {
        $_SESSION['temp_collaborators'][$sessionToken] = [];
    }
}

// ── Action: list ─────────────────────────────────────────────────────────────
if ($action === 'list') {
    // Temporary workspace handling for new proposals
    if ($activityId <= 0 && $sessionToken !== '') {
        $ownerItem = [
            'id'          => 0,
            'user_id'     => (int)$user['id'],
            'name'        => $user['name'],
            'email'       => $user['email'] ?? '',
            'department'  => $user['dept'] ?? $user['department'] ?? '',
            'system_role' => $user['role'],
            'role'        => 'owner',
            'role_label'  => 'Owner',
            'added_by'    => (int)$user['id'],
            'added_at'    => date('Y-m-d H:i:s'),
            'is_owner'    => true,
            'can_remove'  => false,
            'status'      => 'active'
        ];
        $staged = array_values($_SESSION['temp_collaborators'][$sessionToken]);
        $allCollabs = array_merge([$ownerItem], $staged);
        echo json_encode([
            'success'            => true,
            'activity_id'        => 0,
            'session_token'      => $sessionToken,
            'is_temp'            => true,
            'is_owner'           => true,
            'can_manage'         => true,
            'current_user_id'    => (int)$user['id'],
            'collaborator_count' => count($allCollabs),
            'staged_count'       => count($staged),
            'collaborators'      => $allCollabs,
            'contribution_logs'  => []
        ]);
        exit;
    }
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

    // Temporary workspace handling for new proposals
    if ($activityId <= 0 && $sessionToken !== '') {
        $uStmt = $db->prepare("SELECT id, name, email, department, role FROM users WHERE id = ? LIMIT 1");
        $uStmt->execute([$targetUserId]);
        $targetUser = $uStmt->fetch(PDO::FETCH_ASSOC);

        if (!$targetUser) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Selected faculty member not found.']);
            exit;
        }

        $targetKey = function_exists('cleanFacultyNameKey') ? cleanFacultyNameKey($targetUser['name']) : strtolower(trim($targetUser['name']));
        $userKey = function_exists('cleanFacultyNameKey') ? cleanFacultyNameKey($user['name']) : strtolower(trim($user['name']));

        if ($targetUserId === (int)$user['id'] || ($targetKey !== '' && $targetKey === $userKey)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'You cannot add yourself as a collaborator. You are already the proposal owner.']);
            exit;
        }

        if (!in_array($collabRole, ['co_author', 'contributor'], true)) {
            $collabRole = 'contributor';
        }

        if (isset($_SESSION['temp_collaborators'][$sessionToken][$targetUserId])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Faculty member is already added to this proposal session.']);
            exit;
        }

        if ($targetKey !== '') {
            foreach ($_SESSION['temp_collaborators'][$sessionToken] as $staged) {
                $stagedKey = function_exists('cleanFacultyNameKey') ? cleanFacultyNameKey($staged['name'] ?? '') : '';
                if ($stagedKey !== '' && $stagedKey === $targetKey) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Faculty member is already added to this proposal session.']);
                    exit;
                }
            }
        }

        if (!$targetUser) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Selected faculty member not found.']);
            exit;
        }

        if (!in_array($targetUser['role'], ['faculty', 'program_head'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Only faculty members and program heads can be added as proposal collaborators.']);
            exit;
        }

        $roleLabel = formatCollaboratorRole($collabRole);
        $_SESSION['temp_collaborators'][$sessionToken][$targetUserId] = [
            'id'          => 0,
            'user_id'     => (int)$targetUser['id'],
            'name'        => $targetUser['name'],
            'email'       => $targetUser['email'] ?? '',
            'department'  => $targetUser['department'] ?? '',
            'system_role' => $targetUser['role'],
            'role'        => $collabRole,
            'role_label'  => $roleLabel,
            'added_by'    => (int)$user['id'],
            'added_at'    => date('Y-m-d H:i:s'),
            'is_owner'    => false,
            'can_remove'  => true,
            'status'      => 'active'
        ];

        echo json_encode([
            'success'    => true,
            'message'    => "Successfully added {$targetUser['name']} as {$roleLabel}.",
            'user_id'    => $targetUserId,
            'name'       => $targetUser['name'],
            'role'       => $collabRole,
            'role_label' => $roleLabel
        ]);
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

    // Temporary workspace handling for new proposals
    if ($activityId <= 0 && $sessionToken !== '') {
        if (isset($_SESSION['temp_collaborators'][$sessionToken][$targetUserId])) {
            $removedName = $_SESSION['temp_collaborators'][$sessionToken][$targetUserId]['name'];
            unset($_SESSION['temp_collaborators'][$sessionToken][$targetUserId]);
            echo json_encode([
                'success' => true,
                'message' => "Successfully removed {$removedName} from proposal collaborators."
            ]);
            exit;
        }

        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Collaborator not found in draft session.']);
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
    $search = trim($_GET['q'] ?? '');

    // Temporary workspace handling for new proposals
    if ($activityId <= 0 && $sessionToken !== '') {
        $stagedIds = array_keys($_SESSION['temp_collaborators'][$sessionToken]);
        $stagedIds[] = (int)$user['id'];
        $facultyList = getEligibleFacultyForDropdown($db, $stagedIds, $search);

        echo json_encode([
            'success' => true,
            'activity_id' => 0,
            'available_faculty' => $facultyList
        ]);
        exit;
    }

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

    $facultyList = getEligibleFacultyForDropdown($db, $existingIds, $search);

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

