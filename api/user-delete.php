<?php
/**
 * api/user-delete.php
 *
 * Secure server-side endpoint for User Account Deletion in STI-ARMES.
 * Authorized for Dean and Admin2 only (Faculty and Admin1 rejected with HTTP 403).
 * Enforces role hierarchy, CSRF protection, self-deletion prevention, last-Dean protection,
 * and comprehensive dependency pre-flight checks to preserve audit and activity integrity.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// 1. Authoritative Guard: Only Dean and Admin2 may access (Faculty/Admin1 receive 403)
requireRole('dean', 'admin2');

// 2. CSRF Token Protection
requireCsrfToken();

$currentUser = currentUser();

// Support isolated test database connection via environment variable or custom PDO
$db = null;
if (isset($customPdo) && $customPdo instanceof PDO) {
    $db = $customPdo;
} else {
    $testDbName = getenv('STI_TEST_DB');
    if ($testDbName !== false && $testDbName !== '') {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . $testDbName . ";charset=" . DB_CHARSET;
        $db = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } else {
        $db = getDB();
    }
}

// Determine if client expects JSON
$isJsonRequest = (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) ||
                  (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

// Determine base redirect path based on authenticated user's role
$defaultRedirect = ($currentUser['role'] === 'dean')
    ? BASE_URL . '/dean/users.php'
    : BASE_URL . '/admin2/users.php';

$redirectUrl = $defaultRedirect;
if (!empty($_POST['redirect'])) {
    $allowedPrefixes = [BASE_URL . '/dean/users.php', BASE_URL . '/admin2/users.php'];
    foreach ($allowedPrefixes as $prefix) {
        if (str_starts_with($_POST['redirect'], $prefix)) {
            $redirectUrl = $_POST['redirect'];
            break;
        }
    }
}

function respondDeleteError(string $errorCode, string $errorMessage, bool $isJson, string $redirectBase, int $httpCode = 422): void {
    if ($isJson) {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode([
            'success'    => false,
            'error_code' => $errorCode,
            'error'      => $errorMessage
        ]);
        exit;
    }
    $separator = str_contains($redirectBase, '?') ? '&' : '?';
    header("Location: {$redirectBase}{$separator}error=" . urlencode($errorCode));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondDeleteError('invalid_method', 'Only POST requests are allowed.', $isJsonRequest, $redirectUrl, 405);
}

// 3. Extract and Validate Target User
$userId = (int)($_POST['user_id'] ?? 0);
if ($userId <= 0) {
    respondDeleteError('invalid_user', 'Target user ID is missing or invalid.', $isJsonRequest, $redirectUrl, 422);
}

$stmt = $db->prepare('SELECT id, name, email, role, profile_picture FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$targetUser) {
    respondDeleteError('user_not_found', 'The specified user was not found.', $isJsonRequest, $redirectUrl, 404);
}

// 4. Self-Deletion Prevention Guard
if ((int)$targetUser['id'] === (int)$currentUser['id']) {
    respondDeleteError('forbidden_self_deletion', 'You cannot delete your own account.', $isJsonRequest, $redirectUrl, 403);
}

// 5. Role Hierarchy & Authority Validation
if ($currentUser['role'] === 'admin2') {
    // Admin 2 can ONLY delete faculty accounts
    if ($targetUser['role'] !== 'faculty') {
        respondDeleteError('forbidden_target', 'Admin 2 is authorized to delete Faculty accounts only.', $isJsonRequest, $redirectUrl, 403);
    }
} elseif ($currentUser['role'] === 'dean') {
    // Dean can delete faculty, admin1, and admin2 accounts.
    // If target is another Dean, verify that it is NOT the last Dean
    if ($targetUser['role'] === 'dean') {
        $deanCountStmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'dean'");
        $deanCount = (int)$deanCountStmt->fetchColumn();
        if ($deanCount <= 1) {
            respondDeleteError('forbidden_last_dean', 'Cannot delete the last remaining Dean account.', $isJsonRequest, $redirectUrl, 403);
        }
    }
} else {
    respondDeleteError('unauthorized', 'You are not authorized to delete accounts.', $isJsonRequest, $redirectUrl, 403);
}

// 6. Last Dean Check (Global protection for the last Dean account)
if ($targetUser['role'] === 'dean') {
    $deanCountStmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'dean'");
    $deanCount = (int)$deanCountStmt->fetchColumn();
    if ($deanCount <= 1) {
        respondDeleteError('forbidden_last_dean', 'Cannot delete the last remaining Dean account.', $isJsonRequest, $redirectUrl, 403);
    }
}

// 7. Audit Pre-Flight Check: Verify EVERY linked business and audit table
// Checks activities (author or last reviewer), approval logs, documents, post_event,
// proposal comments, section comments, section revisions, faculty tasks, and task deliverables.
$existingTables = $db->query("
    SELECT TABLE_NAME 
    FROM INFORMATION_SCHEMA.TABLES 
    WHERE TABLE_SCHEMA = DATABASE()
")->fetchAll(PDO::FETCH_COLUMN);
$tablesMap = array_flip($existingTables);

$counts = [
    'c_activities'        => 0,
    'c_approvals'         => 0,
    'c_documents'         => 0,
    'c_post_event'        => 0,
    'c_proposal_comments' => 0,
    'c_section_comments'  => 0,
    'c_section_revisions' => 0,
    'c_tasks'             => 0,
    'c_deliverables'      => 0,
];

if (isset($tablesMap['activities'])) {
    $colCheck = $db->query("SHOW COLUMNS FROM activities LIKE 'last_reviewer_id'")->fetch();
    if (!empty($colCheck)) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM activities WHERE faculty_id = ? OR last_reviewer_id = ?");
        $stmt->execute([$userId, $userId]);
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) FROM activities WHERE faculty_id = ?");
        $stmt->execute([$userId]);
    }
    $counts['c_activities'] = (int)$stmt->fetchColumn();
}

if (isset($tablesMap['approval_logs'])) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM approval_logs WHERE reviewer_id = ?");
    $stmt->execute([$userId]);
    $counts['c_approvals'] = (int)$stmt->fetchColumn();
}

if (isset($tablesMap['documents'])) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM documents WHERE uploaded_by = ?");
    $stmt->execute([$userId]);
    $counts['c_documents'] = (int)$stmt->fetchColumn();
}

if (isset($tablesMap['post_event'])) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM post_event WHERE submitted_by = ?");
    $stmt->execute([$userId]);
    $counts['c_post_event'] = (int)$stmt->fetchColumn();
}

if (isset($tablesMap['proposal_comments'])) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM proposal_comments WHERE reviewer_id = ?");
    $stmt->execute([$userId]);
    $counts['c_proposal_comments'] = (int)$stmt->fetchColumn();
}

if (isset($tablesMap['section_comments'])) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM section_comments WHERE reviewer_id = ?");
    $stmt->execute([$userId]);
    $counts['c_section_comments'] = (int)$stmt->fetchColumn();
}

if (isset($tablesMap['section_revisions'])) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM section_revisions WHERE reviewer_id = ?");
    $stmt->execute([$userId]);
    $counts['c_section_revisions'] = (int)$stmt->fetchColumn();
}

if (isset($tablesMap['faculty_tasks'])) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM faculty_tasks WHERE assigned_user_id = ? OR created_by = ?");
    $stmt->execute([$userId, $userId]);
    $counts['c_tasks'] = (int)$stmt->fetchColumn();
}

if (isset($tablesMap['task_deliverables'])) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM task_deliverables WHERE uploaded_by = ? OR reviewed_by = ?");
    $stmt->execute([$userId, $userId]);
    $counts['c_deliverables'] = (int)$stmt->fetchColumn();
}

$totalBusinessRecords = 0;
if ($counts) {
    foreach ($counts as $val) {
        $totalBusinessRecords += (int)$val;
    }
}

// 8. If linked records exist, block deletion to preserve history and prevent orphaned foreign keys
if ($totalBusinessRecords > 0) {
    $reasons = [];
    if (!empty($counts['c_activities']))        $reasons[] = "{$counts['c_activities']} activity proposal(s)";
    if (!empty($counts['c_approvals']))         $reasons[] = "{$counts['c_approvals']} approval log(s)";
    if (!empty($counts['c_tasks']))             $reasons[] = "{$counts['c_tasks']} committee task assignment(s)";
    if (!empty($counts['c_deliverables']))      $reasons[] = "{$counts['c_deliverables']} task deliverable(s)";
    if (!empty($counts['c_documents']))         $reasons[] = "{$counts['c_documents']} document upload(s)";
    if (!empty($counts['c_post_event']))        $reasons[] = "{$counts['c_post_event']} post-event report(s)";
    if (!empty($counts['c_proposal_comments']) || !empty($counts['c_section_comments']) || !empty($counts['c_section_revisions'])) {
        $reasons[] = "review comments or revisions";
    }
    $detail = !empty($reasons) ? " (linked to " . implode(', ', $reasons) . ")" : "";
    respondDeleteError(
        'user_has_activity_history',
        "Cannot delete user account: This account has historical activity records{$detail}. Account history must be preserved for audit and compliance integrity.",
        $isJsonRequest,
        $redirectUrl,
        409
    );
}

// 9. Unused Account: Delete permitted ephemeral records and then user inside a transaction
try {
    $db->beginTransaction();

    // Remove permitted ephemeral logs (notifications and milestone reminders)
    $stmtNotif = $db->prepare('DELETE FROM notifications WHERE user_id = ?');
    $stmtNotif->execute([$userId]);

    $stmtReminders = $db->prepare('DELETE FROM task_reminder_logs WHERE user_id = ?');
    $stmtReminders->execute([$userId]);

    // Remove local avatar if present and safely located in uploads/
    if (!empty($targetUser['profile_picture']) && !filter_var($targetUser['profile_picture'], FILTER_VALIDATE_URL)) {
        $avatarRel = ltrim($targetUser['profile_picture'], '/\\');
        $avatarAbs = realpath(__DIR__ . '/../' . $avatarRel);
        $uploadsDir = realpath(__DIR__ . '/../uploads');
        if ($avatarAbs !== false && $uploadsDir !== false && str_starts_with($avatarAbs, $uploadsDir) && is_file($avatarAbs)) {
            @unlink($avatarAbs);
        }
    }

    // Delete user from users table
    $stmtDel = $db->prepare('DELETE FROM users WHERE id = ?');
    $stmtDel->execute([$userId]);

    $db->commit();

    if ($isJsonRequest) {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'success'         => true,
            'deleted_user_id' => $userId,
            'name'            => $targetUser['name'],
            'email'           => $targetUser['email'],
            'role'            => $targetUser['role'],
            'message'         => 'User account deleted successfully.'
        ]);
        exit;
    }

    $separator = str_contains($redirectUrl, '?') ? '&' : '?';
    header("Location: {$redirectUrl}{$separator}deleted=1");
    exit;

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    respondDeleteError('database_error', 'A database error occurred while deleting the user account.', $isJsonRequest, $redirectUrl, 500);
}
