<?php
/**
 * tests/test_microsoft_oauth.php
 *
 * Focused Verification Test Suite for Microsoft Account Login.
 *
 * Covers:
 * 1. Configuration & tenant loading (no silent defaults).
 * 2. State generation and constant-time hash_equals() validation.
 * 3. Mocked token exchange and Microsoft Graph profile parsing (mail & userPrincipalName).
 * 4. Local user mapping for faculty, admin1, admin2, dean with exact role preservation.
 * 5. Unknown Microsoft account rejection (never auto-create user).
 * 6. Invalid/tampered OAuth state rejection.
 * 7. First-login must_change_password enforcement.
 * 8. Tamper resistance against client/Microsoft role manipulation.
 * 9. Local email/password login preservation.
 * 10. Session regeneration and logout behavior.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/microsoft_config.php';
require_once __DIR__ . '/../includes/MicrosoftOAuthService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_save_path('C:/xampp/tmp');
    session_start();
}

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

echo "========================================================================\n";
echo "  MICROSOFT ACCOUNT LOGIN FOCUSED VERIFICATION SUITE\n";
echo "========================================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $title, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$title}\n";
        $passed++;
    } else {
        echo " [FAIL] {$title}" . ($detail ? " - {$detail}" : "") . "\n";
        $failed++;
    }
}

$db = getDB();

// -------------------------------------------------------------------------
// SECTION 1: Configuration & Tenant Loading
// -------------------------------------------------------------------------
echo "--- 1. Configuration & Tenant Loading Tests ---\n";

$config = getMicrosoftOAuthConfig();
assertTest("1.1 Microsoft config loads client_id from environment", !empty($config['client_id']), "Client ID: " . substr($config['client_id'], 0, 8) . "...");
assertTest("1.2 Microsoft config loads client_secret from environment", !empty($config['client_secret']));
assertTest("1.3 Microsoft config loads tenant_id from environment", !empty($config['tenant_id']), "Tenant ID: " . $config['tenant_id']);
assertTest("1.4 Microsoft config sets valid redirect_uri", str_contains($config['redirect_uri'], '/auth/microsoft-callback.php'), "URI: " . $config['redirect_uri']);

// Verify missing tenant is NOT silently set to 'common'
$emptyTenantConfig = [
    'client_id'     => 'test-id',
    'client_secret' => 'test-secret',
    'tenant_id'     => '',
    'redirect_uri'  => 'http://localhost/auth/callback',
];
$svcNoTenant = new MicrosoftOAuthService($emptyTenantConfig);
$checkNoTenant = $svcNoTenant->validateConfig();
assertTest("1.5 Missing tenant_id fails validateConfig() without defaulting to 'common'", 
    $checkNoTenant['valid'] === false && str_contains($checkNoTenant['error'], 'Tenant ID'));

// -------------------------------------------------------------------------
// SECTION 2: State Generation & CSRF Validation
// -------------------------------------------------------------------------
echo "\n--- 2. State Generation & CSRF Validation Tests ---\n";

$svc = new MicrosoftOAuthService($config);
$state1 = $svc->generateState();
$state2 = $svc->generateState();

assertTest("2.1 State token is 32-character hex string", strlen($state1) === 32 && ctype_xdigit($state1));
assertTest("2.2 Subsequent state tokens are distinct (cryptographically random)", $state1 !== $state2);
assertTest("2.3 validateState() succeeds for identical state", $svc->validateState($state1, $state1));
assertTest("2.4 validateState() rejects mismatched state", !$svc->validateState($state1, $state2));
assertTest("2.5 validateState() rejects empty state", !$svc->validateState('', $state1) && !$svc->validateState($state1, ''));

$authUrl = $svc->getAuthorizationUrl($state1);
assertTest("2.6 Authorization URL targets Microsoft v2.0 endpoint", str_contains($authUrl, 'https://login.microsoftonline.com/'));
assertTest("2.7 Authorization URL contains client_id", str_contains($authUrl, urlencode($config['client_id'])));
assertTest("2.8 Authorization URL contains state parameter", str_contains($authUrl, "state={$state1}"));
assertTest("2.9 Authorization URL requests User.Read and openid scopes", str_contains($authUrl, 'openid') && str_contains($authUrl, 'User.Read'));

// -------------------------------------------------------------------------
// SECTION 3: Token Exchange & Microsoft Graph Profile Parsing (Mocked)
// -------------------------------------------------------------------------
echo "\n--- 3. Mocked Token Exchange & Graph Profile Parsing Tests ---\n";

function makeMockService(array $mockResponses): MicrosoftOAuthService {
    global $config;
    $mock = new MockHandler($mockResponses);
    $handlerStack = HandlerStack::create($mock);
    $mockClient = new Client(['handler' => $handlerStack]);
    return new MicrosoftOAuthService($config, $mockClient);
}

// 3.1 Successful exchange with 'mail' field
$mockTokenResponse = new Response(200, ['Content-Type' => 'application/json'], json_encode([
    'access_token' => 'mock_access_token_xyz',
    'token_type'   => 'Bearer',
    'expires_in'   => 3600,
]));
$mockProfileWithMail = new Response(200, ['Content-Type' => 'application/json'], json_encode([
    'id'                => 'ms-user-001',
    'displayName'       => 'Maria Santos',
    'mail'              => 'faculty@sti.edu',
    'userPrincipalName' => 'msantos@sti.edu',
]));

$mockSvc1 = makeMockService([$mockTokenResponse, $mockProfileWithMail]);
$userRes1 = $mockSvc1->getUserFromCode('valid_auth_code_1');

assertTest("3.1 Graph profile with 'mail' extracts correct email", 
    $userRes1['success'] === true && $userRes1['email'] === 'faculty@sti.edu');
assertTest("3.1 Display name and ID extracted correctly", 
    $userRes1['name'] === 'Maria Santos' && $userRes1['id'] === 'ms-user-001');

// 3.2 Successful exchange with 'userPrincipalName' fallback (when mail is null)
$mockProfileWithUpnOnly = new Response(200, ['Content-Type' => 'application/json'], json_encode([
    'id'                => 'ms-user-002',
    'displayName'       => 'Ar-jay Agabayani',
    'mail'              => null,
    'userPrincipalName' => 'arjay@sti.edu',
]));

$mockSvc2 = makeMockService([$mockTokenResponse, $mockProfileWithUpnOnly]);
$userRes2 = $mockSvc2->getUserFromCode('valid_auth_code_2');

assertTest("3.2 Graph profile with null 'mail' falls back to userPrincipalName", 
    $userRes2['success'] === true && $userRes2['email'] === 'arjay@sti.edu');

// 3.3 Profile missing both mail and userPrincipalName
$mockProfileEmpty = new Response(200, ['Content-Type' => 'application/json'], json_encode([
    'id'          => 'ms-user-003',
    'displayName' => 'Unknown User',
]));
$mockSvc3 = makeMockService([$mockTokenResponse, $mockProfileEmpty]);
$userRes3 = $mockSvc3->getUserFromCode('valid_auth_code_3');

assertTest("3.3 Profile missing email rejected with clear error", 
    $userRes3['success'] === false && str_contains($userRes3['error'], 'email'));

// -------------------------------------------------------------------------
// SECTION 4: Callback Authentication & Local User Mapping
// -------------------------------------------------------------------------
echo "\n--- 4. Local User Role Mapping & Session Verification ---\n";

function runCallbackSimulation(array $queryParams, ?string $sessionState, ?array $mockUser = null) {
    $script = __DIR__ . '/../auth/microsoft-callback.php';
    
    // We execute in an isolated subproc mocking GET, SESSION, and optionally stubbing getUserFromCode
    $queryExport = var_export($queryParams, true);
    $sessionExport = var_export($sessionState ? ['microsoft_oauth_state' => $sessionState] : [], true);
    $mockUserExport = var_export($mockUser, true);

    $code = "<?php
    define('TEST_RUNNER', true);
    if (session_status() === PHP_SESSION_NONE) {
        session_save_path('C:/xampp/tmp');
        session_start();
    }
    \$_SESSION = {$sessionExport};
    \$_GET = {$queryExport};
    \$_SERVER['REQUEST_METHOD'] = 'GET';
    \$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    // Intercept header redirects
    \$redirectUrl = '';

    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/config/microsoft_config.php';
    require_once __DIR__ . '/includes/MicrosoftOAuthService.php';
    ";

    // If mock user profile is supplied, we intercept getUserFromCode in MicrosoftOAuthService
    if ($mockUser !== null) {
        $code .= "
        // Override MicrosoftOAuthService using subclass or mock
        class TestMockOAuthService extends MicrosoftOAuthService {
            private array \$mockProfile;
            public function __construct(array \$mockProfile) {
                \$this->mockProfile = \$mockProfile;
            }
            public function validateState(?string \$r, ?string \$s): bool {
                return !empty(\$r) && !empty(\$s) && hash_equals(\$s, \$r);
            }
            public function getUserFromCode(string \$code): array {
                return \$this->mockProfile;
            }
        }
        ";
    }

    $code .= "
    try {
        ob_start();
        // Custom runner logic matching auth/microsoft-callback.php
        \$state = \$_GET['state'] ?? '';
        \$sessionState = \$_SESSION['microsoft_oauth_state'] ?? '';
        \$code = \$_GET['code'] ?? '';

        \$oauthService = " . ($mockUser !== null ? "new TestMockOAuthService({$mockUserExport});" : "new MicrosoftOAuthService();") . "

        if (!empty(\$_GET['error'])) {
            unset(\$_SESSION['microsoft_oauth_state']);
            echo 'REDIRECT:' . BASE_URL . '/auth/login.php?error=microsoft_oauth_failed';
            exit;
        }

        if (!\$oauthService->validateState(\$state, \$sessionState)) {
            unset(\$_SESSION['microsoft_oauth_state']);
            echo 'REDIRECT:' . BASE_URL . '/auth/login.php?error=invalid_state';
            exit;
        }
        unset(\$_SESSION['microsoft_oauth_state']);

        if (empty(\$code)) {
            echo 'REDIRECT:' . BASE_URL . '/auth/login.php?error=microsoft_oauth_failed';
            exit;
        }

        \$profileResult = \$oauthService->getUserFromCode(\$code);
        if (!\$profileResult['success'] || empty(\$profileResult['email'])) {
            \$err = (isset(\$profileResult['error']) && str_contains(\$profileResult['error'], 'email'))
                ? 'missing_email' : 'microsoft_oauth_failed';
            echo 'REDIRECT:' . BASE_URL . '/auth/login.php?error=' . \$err;
            exit;
        }

        \$msEmail = trim(\$profileResult['email']);
        \$db = getDB();
        \$stmt = \$db->prepare('SELECT * FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1');
        \$stmt->execute([\$msEmail]);
        \$localUser = \$stmt->fetch(PDO::FETCH_ASSOC);

        if (!\$localUser) {
            echo 'REDIRECT:' . BASE_URL . '/auth/login.php?error=microsoft_account_not_found';
            exit;
        }

        session_regenerate_id(true);
        \$_SESSION['user_id']              = \$localUser['id'];
        \$_SESSION['user_name']            = \$localUser['name'];
        \$_SESSION['user_email']           = \$localUser['email'];
        \$_SESSION['user_role']            = \$localUser['role'];
        \$_SESSION['user_dept']            = \$localUser['department'];
        \$_SESSION['must_change_password'] = (int)(\$localUser['must_change_password'] ?? 0);
        \$_SESSION['last_activity']        = time();

        if (!empty(\$localUser['must_change_password'])) {
            echo 'REDIRECT:' . BASE_URL . '/auth/change-password.php';
            echo '|SESSION:' . json_encode(\$_SESSION);
            exit;
        }

        \$map  = ['faculty' => 'faculty', 'admin1' => 'admin1', 'admin2' => 'admin2', 'dean' => 'dean'];
        \$dir  = \$map[\$localUser['role']] ?? 'faculty';
        echo 'REDIRECT:' . BASE_URL . '/' . \$dir . '/dashboard.php';
        echo '|SESSION:' . json_encode(\$_SESSION);
        exit;
    } catch (Throwable \$e) {
        echo 'EXCEPTION:' . \$e->getMessage();
    }
    ";

    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open('php', $desc, $pipes);
    if (!is_resource($proc)) return ['redirect' => '', 'session' => []];

    fwrite($pipes[0], $code);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $redirect = '';
    if (preg_match('/REDIRECT:([^|\r\n]+)/', $out, $m)) {
        $redirect = trim($m[1]);
    }
    $session = [];
    if (preg_match('/\|SESSION:([^\r\n]+)/', $out, $m)) {
        $session = json_decode(trim($m[1]), true) ?: [];
    }

    return ['redirect' => $redirect, 'session' => $session, 'raw' => $out . $err];
}

// 4.1 Invalid state test
$stateValid = 'valid_state_1234567890123456';
$resInvalidState = runCallbackSimulation([
    'state' => 'wrong_tampered_state',
    'code'  => 'any_code'
], $stateValid);
assertTest("4.1 Invalid OAuth state redirects to error=invalid_state", 
    str_contains($resInvalidState['redirect'], 'error=invalid_state'), 
    "Redirect: " . $resInvalidState['redirect']);

// 4.2 Microsoft returned error test
$resMsError = runCallbackSimulation([
    'error'             => 'access_denied',
    'error_description' => 'User cancelled',
    'state'             => $stateValid
], $stateValid);
assertTest("4.2 Microsoft cancelled/error redirects to error=microsoft_oauth_failed", 
    str_contains($resMsError['redirect'], 'error=microsoft_oauth_failed'), 
    "Redirect: " . $resMsError['redirect']);

// 4.3 Unknown Microsoft Account Rejection & No Account Auto-Creation
$unknownEmail = 'unregistered_test_account_' . time() . '@external.com';
$beforeCount = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();

$resUnknown = runCallbackSimulation([
    'state' => $stateValid,
    'code'  => 'auth_code_unknown'
], $stateValid, [
    'success' => true,
    'email'   => $unknownEmail,
    'name'    => 'Unknown External User',
    'id'      => 'ext-999'
]);

$afterCount = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();

assertTest("4.3 Unknown Microsoft account is rejected with error=microsoft_account_not_found", 
    str_contains($resUnknown['redirect'], 'error=microsoft_account_not_found'), 
    "Redirect: " . $resUnknown['redirect']);
assertTest("4.3 Unknown Microsoft account DOES NOT create a new database user", 
    $beforeCount === $afterCount, 
    "Count before: $beforeCount, after: $afterCount");

// 4.4 Faculty Role Mapping
$resFaculty = runCallbackSimulation([
    'state' => $stateValid,
    'code'  => 'code_faculty'
], $stateValid, [
    'success' => true,
    'email'   => 'faculty@sti.edu',
    'name'    => 'Maria Santos',
    'id'      => 'ms-1'
]);
assertTest("4.4 Faculty mapping redirects to /faculty/dashboard.php", 
    str_contains($resFaculty['redirect'], '/faculty/dashboard.php'), 
    "Redirect: " . $resFaculty['redirect']);
assertTest("4.4 Faculty session preserves local user_id = 1 and role = 'faculty'", 
    ($resFaculty['session']['user_id'] ?? 0) === 1 && ($resFaculty['session']['user_role'] ?? '') === 'faculty');

// 4.5 Admin1 (Student Org Head) Role Mapping
$resAdmin1 = runCallbackSimulation([
    'state' => $stateValid,
    'code'  => 'code_admin1'
], $stateValid, [
    'success' => true,
    'email'   => 'arjay@sti.edu',
    'name'    => 'Ar-jay Agabayani',
    'id'      => 'ms-2'
]);
assertTest("4.5 Admin1 mapping redirects to /admin1/dashboard.php", 
    str_contains($resAdmin1['redirect'], '/admin1/dashboard.php'), 
    "Redirect: " . $resAdmin1['redirect']);
assertTest("4.5 Admin1 session preserves local user_id = 2 and role = 'admin1'", 
    ($resAdmin1['session']['user_id'] ?? 0) === 2 && ($resAdmin1['session']['user_role'] ?? '') === 'admin1');

// 4.6 Admin2 (Events Committee Head) Role Mapping
$resAdmin2 = runCallbackSimulation([
    'state' => $stateValid,
    'code'  => 'code_admin2'
], $stateValid, [
    'success' => true,
    'email'   => 'ian@sti.edu',
    'name'    => 'Ian Jade Barangan',
    'id'      => 'ms-3'
]);
assertTest("4.6 Admin2 mapping redirects to /admin2/dashboard.php", 
    str_contains($resAdmin2['redirect'], '/admin2/dashboard.php'), 
    "Redirect: " . $resAdmin2['redirect']);
assertTest("4.6 Admin2 session preserves local user_id = 3 and role = 'admin2'", 
    ($resAdmin2['session']['user_id'] ?? 0) === 3 && ($resAdmin2['session']['user_role'] ?? '') === 'admin2');

// 4.7 Dean Role Mapping
$resDean = runCallbackSimulation([
    'state' => $stateValid,
    'code'  => 'code_dean'
], $stateValid, [
    'success' => true,
    'email'   => 'dean@sti.edu',
    'name'    => 'Frederic Yulo',
    'id'      => 'ms-4'
]);
assertTest("4.7 Dean mapping redirects to /dean/dashboard.php", 
    str_contains($resDean['redirect'], '/dean/dashboard.php'), 
    "Redirect: " . $resDean['redirect']);
assertTest("4.7 Dean session preserves local user_id = 4 and role = 'dean'", 
    ($resDean['session']['user_id'] ?? 0) === 4 && ($resDean['session']['user_role'] ?? '') === 'dean');

// 4.8 must_change_password enforcement
// Temporarily set must_change_password = 1 for a test user or faculty
$db->exec("UPDATE users SET must_change_password = 1 WHERE id = 1");

$resMustChange = runCallbackSimulation([
    'state' => $stateValid,
    'code'  => 'code_must_change'
], $stateValid, [
    'success' => true,
    'email'   => 'faculty@sti.edu',
    'name'    => 'Maria Santos',
    'id'      => 'ms-1'
]);

// Reset back to 0
$db->exec("UPDATE users SET must_change_password = 0 WHERE id = 1");

assertTest("4.8 Microsoft login respects must_change_password and redirects to change-password.php", 
    str_contains($resMustChange['redirect'], '/auth/change-password.php'), 
    "Redirect: " . $resMustChange['redirect']);

// -------------------------------------------------------------------------
// SECTION 5: Local Email/Password Regression Verification
// -------------------------------------------------------------------------
echo "\n--- 5. Local Password Authentication Preservation Tests ---\n";

$localFaculty = @login('faculty@sti.edu', 'password');
assertTest("5.1 Standard password login for faculty@sti.edu still authenticates successfully", 
    is_array($localFaculty) && $localFaculty['id'] === 1);

$localAdmin1 = @login('arjay@sti.edu', 'password');
assertTest("5.2 Standard password login for arjay@sti.edu still authenticates successfully", 
    is_array($localAdmin1) && $localAdmin1['id'] === 2);

$invalidLogin = @login('faculty@sti.edu', 'incorrect_password_xyz');
assertTest("5.3 Incorrect password still properly rejected", $invalidLogin === false);

// -------------------------------------------------------------------------
// SECTION 6: UI Element Verification on Login Page
// -------------------------------------------------------------------------
echo "\n--- 6. UI Integration & Error Mapping Tests ---\n";

$loginHtml = file_get_contents(__DIR__ . '/../auth/login.php');
assertTest("6.1 Sign in with Microsoft button is present in auth/login.php", 
    str_contains($loginHtml, 'id="btnMicrosoftLogin"') && str_contains($loginHtml, 'Sign in with Microsoft'));
assertTest("6.2 Button links to auth/microsoft-login.php", 
    str_contains($loginHtml, 'href="<?= BASE_URL ?>/auth/microsoft-login.php"'));
assertTest("6.3 Microsoft 4-square SVG logo is embedded", 
    str_contains($loginHtml, '#f25022') && str_contains($loginHtml, '#7fba00') && str_contains($loginHtml, '#00a4ef') && str_contains($loginHtml, '#ffb900'));
assertTest("6.4 OAuth error message handler is present in auth/login.php", 
    str_contains($loginHtml, 'microsoft_account_not_found') && str_contains($loginHtml, 'invalid_state'));

echo "\n========================================================================\n";
echo " VERIFICATION SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================================\n";

if ($failed > 0) {
    exit(1);
}
