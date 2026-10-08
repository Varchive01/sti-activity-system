<?php
// auth/microsoft-callback.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/MicrosoftOAuthService.php';

startSession();

// Check if Microsoft or user cancelled / returned error
if (!empty($_GET['error'])) {
    unset($_SESSION['microsoft_oauth_state']);
    header("Location: " . BASE_URL . "/auth/login.php?error=microsoft_oauth_failed");
    exit;
}

$state = $_GET['state'] ?? '';
$sessionState = $_SESSION['microsoft_oauth_state'] ?? '';
$code = $_GET['code'] ?? '';

$oauthService = new MicrosoftOAuthService();

// Validate OAuth state using constant-time hash_equals()
if (!$oauthService->validateState($state, $sessionState)) {
    unset($_SESSION['microsoft_oauth_state']);
    header("Location: " . BASE_URL . "/auth/login.php?error=invalid_state");
    exit;
}

// Clean up state token immediately after validation
unset($_SESSION['microsoft_oauth_state']);

if (empty($code)) {
    header("Location: " . BASE_URL . "/auth/login.php?error=microsoft_oauth_failed");
    exit;
}

// Exchange code for access token and fetch profile from Microsoft Graph
$profileResult = $oauthService->getUserFromCode($code);

if (!$profileResult['success'] || empty($profileResult['email'])) {
    $err = (isset($profileResult['error']) && str_contains($profileResult['error'], 'email'))
        ? 'missing_email'
        : 'microsoft_oauth_failed';
    header("Location: " . BASE_URL . "/auth/login.php?error=" . $err);
    exit;
}

$msEmail = trim($profileResult['email']);

// Map Microsoft email to an existing local user account ONLY
$db = getDB();
$stmt = $db->prepare("SELECT * FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1");
$stmt->execute([$msEmail]);
$localUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$localUser) {
    // Policy requirement: Unknown Microsoft account MUST be rejected.
    // NEVER automatically create an account or assign a default role.
    header("Location: " . BASE_URL . "/auth/login.php?error=microsoft_account_not_found");
    exit;
}

// Security: Regenerate session ID upon successful authentication
session_regenerate_id(true);

// Establish application session preserving the existing local user role exactly
$_SESSION['user_id']              = $localUser['id'];
$_SESSION['user_name']            = $localUser['name'];
$_SESSION['user_email']           = $localUser['email'];
$_SESSION['user_role']            = $localUser['role'];
$_SESSION['user_dept']            = $localUser['department'];
$_SESSION['must_change_password'] = (int)($localUser['must_change_password'] ?? 0);
$_SESSION['last_activity']        = time();

// Clear login throttle state for this account
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$throttleKey = hash('sha256', $clientIp . '|' . strtolower($localUser['email']));
unset($_SESSION['login_throttle'][$throttleKey]);

// Respect must_change_password requirement
if (!empty($localUser['must_change_password'])) {
    header("Location: " . BASE_URL . "/auth/change-password.php");
    exit;
}

// Route to authorized role dashboard
$role = $localUser['role'] ?? 'faculty';
$map  = ['faculty' => 'faculty', 'admin1' => 'admin1', 'admin2' => 'admin2', 'dean' => 'dean'];
$dir  = $map[$role] ?? 'faculty';

header("Location: " . BASE_URL . "/{$dir}/dashboard.php");
exit;
