<?php
/**
 * api/user-update.php
 *
 * Secure server-side endpoint for User Account Editing.
 * Authorized for Dean and Admin2 only.
 * Enforces role hierarchy, permission-aware editing, target restrictions, and CSRF protection.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// 1. Authoritative Guard: Only Dean and Admin2 may access
requireRole('dean', 'admin2');

// 2. CSRF Token Protection
requireCsrfToken();

$currentUser = currentUser();
$db = getDB();

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

function respondUpdateError(string $errorCode, string $errorMessage, bool $isJson, string $redirectBase, int $httpCode = 422): void {
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
    respondUpdateError('invalid_method', 'Only POST requests are allowed.', $isJsonRequest, $redirectUrl, 405);
}

// 3. Extract and Validate Target User
$userId = (int)($_POST['user_id'] ?? 0);
if ($userId <= 0) {
    respondUpdateError('invalid_user', 'Target user ID is missing or invalid.', $isJsonRequest, $redirectUrl);
}

$stmt = $db->prepare('SELECT id, name, email, role, department FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$targetUser) {
    respondUpdateError('user_not_found', 'The specified user was not found.', $isJsonRequest, $redirectUrl, 404);
}

// 4. Extract Form Inputs
$name          = trim($_POST['name'] ?? '');
$email         = trim($_POST['email'] ?? '');
$department    = trim($_POST['department'] ?? '');
$requestedRole = trim($_POST['role'] ?? $targetUser['role']);

if ($name === '') {
    respondUpdateError('missing_name', 'Full Name is required.', $isJsonRequest, $redirectUrl);
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respondUpdateError('invalid_email', 'A valid email address is required.', $isJsonRequest, $redirectUrl);
}

// 5. Authoritative Permission & Hierarchy Validation
$assignedRole = $targetUser['role'];

if ($currentUser['role'] === 'admin2') {
    // Admin2 can ONLY edit users whose CURRENT role is faculty
    if ($targetUser['role'] !== 'faculty') {
        respondUpdateError('forbidden_target', 'Admin 2 is not authorized to edit administrative accounts.', $isJsonRequest, $redirectUrl, 403);
    }
    // Admin2 cannot promote or change faculty to any administrative role
    if ($requestedRole !== 'faculty') {
        respondUpdateError('forbidden_role_change', 'Admin 2 cannot promote Faculty to administrative roles.', $isJsonRequest, $redirectUrl, 403);
    }
    $assignedRole = 'faculty';

} elseif ($currentUser['role'] === 'dean') {
    // Dean cannot modify their own role
    if ($targetUser['id'] === (int)$currentUser['id']) {
        if ($requestedRole !== 'dean') {
            respondUpdateError('forbidden_self_demotion', 'You cannot modify your own role.', $isJsonRequest, $redirectUrl, 403);
        }
        $assignedRole = 'dean';
    } else {
        // Dean cannot change another user into Dean
        if ($requestedRole === 'dean') {
            respondUpdateError('forbidden_role_change', 'Cannot assign the Dean role to an account.', $isJsonRequest, $redirectUrl, 403);
        }
        if (!in_array($requestedRole, ['faculty', 'admin1', 'admin2'], true)) {
            respondUpdateError('invalid_role', 'Invalid role requested.', $isJsonRequest, $redirectUrl, 422);
        }
        $assignedRole = $requestedRole;
    }
} else {
    respondUpdateError('unauthorized', 'You are not authorized to edit users.', $isJsonRequest, $redirectUrl, 403);
}

// 6. Duplicate Email Check (excluding the target user)
$checkEmail = $db->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ? LIMIT 1');
$checkEmail->execute([$email, $userId]);
if ($checkEmail->fetch()) {
    respondUpdateError('duplicate_email', 'An account with this email address already exists.', $isJsonRequest, $redirectUrl);
}

// 7. Update User Record
try {
    $updateStmt = $db->prepare('
        UPDATE users
        SET name = ?, email = ?, department = ?, role = ?
        WHERE id = ?
    ');
    $updateStmt->execute([
        $name,
        $email,
        $department !== '' ? $department : null,
        $assignedRole,
        $userId
    ]);

    if ($isJsonRequest) {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'success'    => true,
            'user_id'    => $userId,
            'name'       => $name,
            'email'      => $email,
            'role'       => $assignedRole,
            'department' => $department,
            'message'    => 'User updated successfully.'
        ]);
        exit;
    }

    $separator = str_contains($redirectUrl, '?') ? '&' : '?';
    header("Location: {$redirectUrl}{$separator}updated=1");
    exit;

} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        respondUpdateError('duplicate_email', 'An account with this email address already exists.', $isJsonRequest, $redirectUrl);
    }
    respondUpdateError('database_error', 'An error occurred while updating the account.', $isJsonRequest, $redirectUrl);
}
