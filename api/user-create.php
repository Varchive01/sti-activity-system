<?php
/**
 * api/user-create.php
 *
 * Secure server-side endpoint for User Account Creation.
 * Authorized for Dean and Admin2 only.
 * Enforces role hierarchy, permission-aware role assignment, CSRF protection,
 * and creator-provided default password with forced first-login change.
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

function respondError(string $errorCode, string $errorMessage, bool $isJson, string $redirectBase, int $httpCode = 422): void {
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
    respondError('invalid_method', 'Only POST requests are allowed.', $isJsonRequest, $redirectUrl, 405);
}

// 3. Extract Inputs
$name            = trim($_POST['name'] ?? '');
$email           = trim($_POST['email'] ?? '');
$department      = trim($_POST['department'] ?? '');
$requestedRole   = trim($_POST['role'] ?? 'faculty');
$defaultPassword = $_POST['password'] ?? '';

// 4. Validate Inputs
if ($name === '') {
    respondError('missing_name', 'Full Name is required.', $isJsonRequest, $redirectUrl);
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respondError('invalid_email', 'A valid email address is required.', $isJsonRequest, $redirectUrl);
}

// 5. Authoritative Role Hierarchy & Permission Validation
$assignedRole = '';
if ($currentUser['role'] === 'dean') {
    // Dean can create Faculty, Admin1, Admin2. Cannot create another Dean.
    if ($requestedRole === 'dean') {
        respondError('forbidden_role', 'Dean cannot create another Dean account.', $isJsonRequest, $redirectUrl, 403);
    }
    if (!in_array($requestedRole, ['faculty', 'admin1', 'admin2'], true)) {
        respondError('invalid_role', 'Invalid role requested.', $isJsonRequest, $redirectUrl, 422);
    }
    $assignedRole = $requestedRole;
} elseif ($currentUser['role'] === 'admin2') {
    // Admin2 can ONLY create Faculty accounts.
    if ($requestedRole !== 'faculty') {
        respondError('forbidden_role', 'Admin 2 is not authorized to create administrative accounts.', $isJsonRequest, $redirectUrl, 403);
    }
    $assignedRole = 'faculty';
} else {
    respondError('unauthorized', 'You are not authorized to create accounts.', $isJsonRequest, $redirectUrl, 403);
}

// 6. Validate Creator-Provided Default Password
if ($defaultPassword === '') {
    respondError('missing_password', 'Default password is required.', $isJsonRequest, $redirectUrl);
}

if (strlen($defaultPassword) < 6) {
    respondError('invalid_password', 'Password must be at least 6 characters long.', $isJsonRequest, $redirectUrl);
}

// 7. Duplicate Email Validation
$checkStmt = $db->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
$checkStmt->execute([$email]);
if ($checkStmt->fetch()) {
    respondError('duplicate_email', 'An account with this email address already exists.', $isJsonRequest, $redirectUrl);
}

// 8. Secure Password Hashing
$hashedPassword = password_hash($defaultPassword, PASSWORD_DEFAULT);

// 9. Insert User Account with must_change_password = 1
try {
    $insertStmt = $db->prepare('
        INSERT INTO users (name, email, password, role, must_change_password, department)
        VALUES (?, ?, ?, ?, 1, ?)
    ');
    $insertStmt->execute([
        $name,
        $email,
        $hashedPassword,
        $assignedRole,
        $department !== '' ? $department : null,
    ]);
    $newUserId = (int)$db->lastInsertId();

    if ($isJsonRequest) {
        http_response_code(201);
        header('Content-Type: application/json');
        echo json_encode([
            'success'              => true,
            'user_id'              => $newUserId,
            'name'                 => $name,
            'email'                => $email,
            'role'                 => $assignedRole,
            'must_change_password' => 1,
            'message'              => 'User account created successfully.'
        ]);
        exit;
    }

    $separator = str_contains($redirectUrl, '?') ? '&' : '?';
    header("Location: {$redirectUrl}{$separator}created=1");
    exit;

} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        respondError('duplicate_email', 'An account with this email address already exists.', $isJsonRequest, $redirectUrl);
    }
    respondError('database_error', 'An error occurred while creating the account. Please try again.', $isJsonRequest, $redirectUrl);
}
