<?php

/**
 * tests/test_microsoft_logout_and_sso.php
 *
 * Comprehensive End-to-End Test Suite for Microsoft SSO Logout,
 * Account Selection Prompt, Session Security, and Multi-Role Access.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/microsoft_config.php';
require_once __DIR__ . '/../includes/MicrosoftOAuthService.php';

echo "========================================================================\n";
echo "  STI-ARMES MICROSOFT SSO LOGOUT & ACCOUNT SELECTION TEST SUITE\n";
echo "========================================================================\n\n";

$passed = 0;
$failed = 0;

function it(string $title, bool $condition, string $detail = '')
{
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$title}\n";
        $passed++;
    } else {
        echo " [FAIL] {$title}" . ($detail ? " (Detail: {$detail})" : "") . "\n";
        $failed++;
    }
}

$baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost/sti-activity-system';
$savePath = is_dir('C:/xampp/tmp') ? 'C:/xampp/tmp' : session_save_path();

// -------------------------------------------------------------------------
// 1. Account Selection & Authorization Initiation
// -------------------------------------------------------------------------
echo "--- 1. Account Selection (prompt=select_account) Tests ---\n";

$oauthService = new MicrosoftOAuthService();
$state = $oauthService->generateState();
$authUrl = $oauthService->getAuthorizationUrl($state);

it("1.1 Authorization URL contains prompt=select_account", str_contains($authUrl, 'prompt=select_account'));
it(
    "1.2 Authorization URL includes openid, profile, email, User.Read scopes",
    str_contains($authUrl, 'scope=openid%20profile%20email%20User.Read') || (str_contains($authUrl, 'openid') && str_contains($authUrl, 'User.Read'))
);
it("1.3 Authorization URL carries unique state parameter", str_contains($authUrl, "state={$state}"));
it("1.4 Authorization URL points to Microsoft login endpoint", str_starts_with($authUrl, 'https://login.microsoftonline.com/'));

// -------------------------------------------------------------------------
// 2. Microsoft SSO Logout Redirection & Session Invalidation
// -------------------------------------------------------------------------
echo "\n--- 2. Logout Process & Cookie Clearing Tests ---\n";

$msLogoutUrl = $oauthService->getLogoutUrl();
it("2.1 Microsoft logout endpoint is correctly formed", str_contains($msLogoutUrl, '/oauth2/v2.0/logout'));
it(
    "2.2 post_logout_redirect_uri is appended pointing to login.php",
    str_contains($msLogoutUrl, 'post_logout_redirect_uri=') && str_contains($msLogoutUrl, urlencode($baseUrl . '/auth/login.php'))
);

// Create an active Microsoft session and verify logout redirect
$sessId = bin2hex(random_bytes(16));
$sessionData = [
    'user_id'       => 387,
    'user_name'     => 'Maria Santos',
    'user_email'    => 'faculty@sti.edu',
    'user_role'     => 'faculty',
    'user_dept'     => 'General Academic',
    'auth_method'   => 'microsoft',
    'last_activity' => time()
];

$sessFile = rtrim($savePath, '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $sessId;
// Serialize standard PHP session data
$encoded = '';
foreach ($sessionData as $k => $v) {
    $encoded .= $k . '|' . serialize($v);
}
file_put_contents($sessFile, $encoded);

// Request auth/logout.php with the session cookie
$ch = curl_init($baseUrl . '/auth/logout.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_COOKIE         => 'PHPSESSID=' . $sessId,
    CURLOPT_FOLLOWLOCATION => false,
]);
$logoutResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$headers = substr($logoutResponse, 0, $headerSize);

it("2.3 auth/logout.php responds with HTTP 302 redirect", $httpCode === 302);
it(
    "2.4 auth/logout.php redirects to Microsoft logout endpoint for SSO session",
    str_contains($redirectUrl, 'login.microsoftonline.com') && str_contains($redirectUrl, '/logout')
);
it(
    "2.5 Set-Cookie header expires PHPSESSID in client browser",
    str_contains($headers, 'PHPSESSID=deleted') || str_contains($headers, 'Max-Age=0') || str_contains($headers, '1970')
);

// Verify session file on disk is wiped or destroyed
$sessionStillValid = false;
if (file_exists($sessFile)) {
    $content = file_get_contents($sessFile);
    if (!empty($content) && str_contains($content, 'user_id')) {
        $sessionStillValid = true;
    }
}
it("2.6 Server-side session storage destroyed after logout", !$sessionStillValid);

// -------------------------------------------------------------------------
// 3. Protected Page Access After Logout
// -------------------------------------------------------------------------
echo "\n--- 3. Protected Page Access After Logout ---\n";

$rolesToTest = [
    'faculty'      => $baseUrl . '/faculty/dashboard.php',
    'admin1'       => $baseUrl . '/admin1/dashboard.php',
    'admin2'       => $baseUrl . '/admin2/dashboard.php',
    'dean'         => $baseUrl . '/dean/dashboard.php',
    'program_head' => $baseUrl . '/program_head/dashboard.php',
];

foreach ($rolesToTest as $roleName => $dashUrl) {
    $ch = curl_init($dashUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIE         => 'PHPSESSID=' . $sessId, // Using the logged-out session ID
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $dashRes = curl_exec($ch);
    $dashCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $dashRedirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);

    it(
        "3. Protected [{$roleName}] dashboard redirects to login after logout",
        $dashCode === 302 && str_contains($dashRedirect, 'auth/login.php')
    );
}

// -------------------------------------------------------------------------
// 4. Session Expiration & Inactive Timeout Handling
// -------------------------------------------------------------------------
echo "\n--- 4. Session Expiration / Timeout Tests ---\n";

$expiredSessId = bin2hex(random_bytes(16));
$expiredData = [
    'user_id'       => 387,
    'user_name'     => 'Maria Santos',
    'user_role'     => 'faculty',
    'auth_method'   => 'microsoft',
    'last_activity' => time() - 7200 // 2 hours ago (timeout is 3600s)
];
$expEncoded = '';
foreach ($expiredData as $k => $v) {
    $expEncoded .= $k . '|' . serialize($v);
}
$expFile = rtrim($savePath, '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $expiredSessId;
file_put_contents($expFile, $expEncoded);

$ch = curl_init($baseUrl . '/faculty/dashboard.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_COOKIE         => 'PHPSESSID=' . $expiredSessId,
    CURLOPT_FOLLOWLOCATION => false,
]);
$expRes = curl_exec($ch);
$expCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$expRedirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
@unlink($expFile);

it(
    "4.1 Inactive expired session redirects to login page with timeout notice",
    $expCode === 302 && str_contains($expRedirect, 'timeout=1')
);

// -------------------------------------------------------------------------
// 5. Unauthorized Account & Error Handling
// -------------------------------------------------------------------------
echo "\n--- 5. Unauthorized Account Restrictions & OAuth Failure Tests ---\n";

// Test 5.1: Microsoft returned error=access_denied (User cancelled)
$ch = curl_init($baseUrl . '/auth/microsoft-callback.php?error=access_denied');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_FOLLOWLOCATION => false,
]);
$errRes = curl_exec($ch);
$errRedirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);

it(
    "5.1 Microsoft cancellation (error=access_denied) redirects to login with error parameter",
    str_contains($errRedirect, 'error=microsoft_oauth_failed')
);

// Test 5.2: OAuth state mismatch / CSRF tampering
$ch = curl_init($baseUrl . '/auth/microsoft-callback.php?state=tampered_fake_state&code=fake_code');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_FOLLOWLOCATION => false,
]);
$stateRes = curl_exec($ch);
$stateRedirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);

it(
    "5.2 Tampered OAuth state parameter safely rejected with invalid_state error",
    str_contains($stateRedirect, 'error=invalid_state')
);

// Clean up
@unlink($sessFile);

echo "\n========================================================================\n";
echo " TEST SUITE COMPLETE: Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================================\n";

if ($failed > 0) {
    exit(1);
}
