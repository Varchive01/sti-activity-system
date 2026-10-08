<?php
/**
 * api/user-reset-password.php
 *
 * Secure server-side endpoint for Setting/Resetting a User's Default Password.
 * Authorized for Dean and Admin2 only.
 * Enforces role hierarchy, target restrictions, CSRF protection,
 * and sets must_change_password = 1 so the user is forced to change it on next login.
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

function respondResetError(string $errorCode, string $errorMessage, bool $isJson, string $redirectBase, int $httpCode = 422): void {
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
    respondResetError('invalid_method', 'Only POST requests are allowed.', $isJsonRequest, $redirectUrl, 405);
}

// 3. Extract and Validate Target User
$userId = (int)($_POST['user_id'] ?? 0);
if ($userId <= 0) {
    respondResetError('invalid_user', 'Target user ID is missing or invalid.', $isJsonRequest, $redirectUrl);
}

$stmt = $db->prepare('SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$targetUser) {
    respondResetError('user_not_found', 'The specified user was not found.', $isJsonRequest, $redirectUrl, 404);
}

// 4. Role Hierarchy & Permission Validation
if ($currentUser['role'] === 'admin2') {
    // Admin 2 can ONLY set passwords for faculty accounts
    if ($targetUser['role'] !== 'faculty') {
        respondResetError('forbidden_target', 'Admin 2 is not authorized to set passwords for administrative accounts.', $isJsonRequest, $redirectUrl, 403);
    }
} elseif ($currentUser['role'] === 'dean') {
    // Dean can set passwords for faculty, admin1, and admin2. Cannot set password for other Dean accounts.
    if ($targetUser['role'] === 'dean' && $targetUser['id'] !== (int)$currentUser['id']) {
        respondResetError('forbidden_target', 'Cannot reset password for other Dean accounts.', $isJsonRequest, $redirectUrl, 403);
    }
} else {
    respondResetError('unauthorized', 'You are not authorized to set passwords.', $isJsonRequest, $redirectUrl, 403);
}

// 5. Validate New Default Password
$newPassword = $_POST['new_password'] ?? '';
if ($newPassword === '') {
    respondResetError('missing_password', 'New default password is required.', $isJsonRequest, $redirectUrl);
}

if (strlen($newPassword) < 6) {
    respondResetError('invalid_password', 'Password must be at least 6 characters long.', $isJsonRequest, $redirectUrl);
}

// 6. Secure Hashing and Update
$hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

try {
    $updateStmt = $db->prepare('
        UPDATE users
        SET password = ?, must_change_password = 1
        WHERE id = ?
    ');
    $updateStmt->execute([$hashedPassword, $userId]);

    if ($isJsonRequest) {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'success'              => true,
            'user_id'              => $userId,
            'must_change_password' => 1,
            'message'              => 'Default password set successfully. User must change it on next login.'
        ]);
        exit;
    }

    $separator = str_contains($redirectUrl, '?') ? '&' : '?';
    header("Location: {$redirectUrl}{$separator}password_reset=1");
    exit;

} catch (PDOException $e) {
    respondResetError('database_error', 'An error occurred while setting the password.', $isJsonRequest, $redirectUrl);
}
