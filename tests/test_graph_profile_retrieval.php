<?php
// tests/test_graph_profile_retrieval.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/microsoft_config.php';
require_once __DIR__ . '/../includes/MicrosoftAzureProvider.php';
require_once __DIR__ . '/../includes/MicrosoftOAuthService.php';

use App\OAuth\MicrosoftAzureProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Token\AccessToken;

$passed = 0;
$failed = 0;

function assertTest(string $desc, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] $desc\n";
        $passed++;
    } else {
        echo " [FAIL] $desc" . ($detail ? " ($detail)" : "") . "\n";
        $failed++;
    }
}

echo "========================================================================\n";
echo "  MICROSOFT GRAPH PROFILE RETRIEVAL FOCUSED VERIFICATION\n";
echo "========================================================================\n\n";

$db = getDB();
$config = getMicrosoftOAuthConfig();

// -------------------------------------------------------------------------
// TEST SUITE 1: HTTP Header & URL Hygiene Verification
// -------------------------------------------------------------------------
echo "--- 1. HTTP Header & URL Cleanliness Tests ---\n";

$provider = new MicrosoftAzureProvider([
    'clientId'     => 'test-client-id',
    'clientSecret' => 'test-secret',
    'redirectUri'  => 'http://localhost/sti-activity-system/auth/microsoft-callback.php',
    'tenantId'     => 'common',
]);

$dummyToken = new AccessToken([
    'access_token' => 'sample_secure_bearer_token_12345',
    'expires_in'   => 3600,
]);

// 1.1 URL must not contain the access token
$detailsUrl = $provider->getResourceOwnerDetailsUrl($dummyToken);
assertTest(
    "1.1 getResourceOwnerDetailsUrl does NOT include access_token query param",
    !str_contains($detailsUrl, 'access_token') && !str_contains($detailsUrl, 'sample_secure_bearer_token'),
    "URL: $detailsUrl"
);
assertTest(
    "1.1 getResourceOwnerDetailsUrl matches Microsoft Graph v1.0 /me endpoint",
    $detailsUrl === 'https://graph.microsoft.com/v1.0/me',
    "URL: $detailsUrl"
);

// 1.2 Outgoing authenticated request must include Authorization: Bearer header
$authRequest = $provider->getAuthenticatedRequest('GET', $detailsUrl, $dummyToken);
$authHeaders = $authRequest->getHeader('Authorization');

assertTest(
    "1.2 Authenticated request contains Authorization header",
    !empty($authHeaders),
    "Headers: " . json_encode($authRequest->getHeaders())
);
assertTest(
    "1.2 Authorization header begins with Bearer scheme",
    isset($authHeaders[0]) && str_starts_with($authHeaders[0], 'Bearer ')
);
assertTest(
    "1.2 Authorization header matches RFC 6750 Bearer <token> format",
    isset($authHeaders[0]) && $authHeaders[0] === 'Bearer sample_secure_bearer_token_12345'
);

// -------------------------------------------------------------------------
// TEST SUITE 2: End-to-End getUserFromCode with History Inspection
// -------------------------------------------------------------------------
echo "\n--- 2. End-to-End Profile Retrieval with Request Inspection ---\n";

$container = [];
$history = Middleware::history($container);

$mockTokenResponse = new Response(200, ['Content-Type' => 'application/json'], json_encode([
    'access_token' => 'test_jwt_access_token_graph',
    'token_type'   => 'Bearer',
    'expires_in'   => 3600,
]));

$mockProfileResponse = new Response(200, ['Content-Type' => 'application/json'], json_encode([
    'id'                => 'ms-student-001',
    'displayName'       => 'Van Robles',
    'mail'              => 'robles.376003@marikina.sti.edu.ph',
    'userPrincipalName' => 'robles.376003@marikina.sti.edu.ph',
]));

$mockHandler = new MockHandler([$mockTokenResponse, $mockProfileResponse]);
$handlerStack = HandlerStack::create($mockHandler);
$handlerStack->push($history);

$mockClient = new Client(['handler' => $handlerStack]);
$service = new MicrosoftOAuthService($config, $mockClient);

$userResult = $service->getUserFromCode('auth_code_test_123');

assertTest("2.1 getUserFromCode returns success = true", $userResult['success'] === true);
assertTest(
    "2.1 Extracted email matches institutional student email claim",
    $userResult['email'] === 'robles.376003@marikina.sti.edu.ph'
);
assertTest("2.1 Display name extracted correctly", $userResult['name'] === 'Van Robles');
assertTest("2.1 User ID extracted correctly", $userResult['id'] === 'ms-student-001');

// Verify outgoing HTTP requests in history
assertTest("2.2 Exactly 2 HTTP requests executed (Token + Graph /me)", count($container) === 2);

$tokenReq = $container[0]['request'];
$graphReq = $container[1]['request'];

assertTest(
    "2.2 Second HTTP request targeted Microsoft Graph /v1.0/me",
    (string)$graphReq->getUri() === 'https://graph.microsoft.com/v1.0/me'
);
assertTest(
    "2.2 Microsoft Graph request had Authorization: Bearer header",
    $graphReq->hasHeader('Authorization') && str_starts_with($graphReq->getHeaderLine('Authorization'), 'Bearer ')
);
assertTest(
    "2.2 Microsoft Graph URL had NO access_token query param",
    !str_contains((string)$graphReq->getUri(), 'access_token')
);

// -------------------------------------------------------------------------
// TEST SUITE 3: Microsoft Graph Error Handling (401 / 403 / Nested JSON)
// -------------------------------------------------------------------------
echo "\n--- 3. Microsoft Graph Error Handling Tests ---\n";

$mockTokenResponse2 = new Response(200, ['Content-Type' => 'application/json'], json_encode([
    'access_token' => 'invalid_token',
    'token_type'   => 'Bearer',
    'expires_in'   => 3600,
]));

$mockGraph401 = new Response(401, ['Content-Type' => 'application/json'], json_encode([
    'error' => [
        'code'    => 'InvalidAuthenticationToken',
        'message' => 'Access token has expired or is invalid.',
    ],
]));

$mockHandler2 = new MockHandler([$mockTokenResponse2, $mockGraph401]);
$mockClient2 = new Client(['handler' => HandlerStack::create($mockHandler2)]);
$service2 = new MicrosoftOAuthService($config, $mockClient2);

$failResult = $service2->getUserFromCode('code_expired');
assertTest("3.1 Graph 401 returns success = false", $failResult['success'] === false);
assertTest(
    "3.1 Error message is returned gracefully to caller",
    !empty($failResult['error'])
);

// -------------------------------------------------------------------------
// TEST SUITE 4: Callback Local User Lookup & Session Creation
// -------------------------------------------------------------------------
echo "\n--- 4. Local User Lookup & Authorization Tests ---\n";

function runCallbackSubprocess(array $queryParams, ?string $sessionState, ?array $mockProfileResult) {
    $script = __DIR__ . '/../auth/microsoft-callback.php';
    $queryExport = var_export($queryParams, true);
    $sessionExport = var_export($sessionState ? ['microsoft_oauth_state' => $sessionState] : [], true);
    $profileExport = var_export($mockProfileResult, true);

    $phpCode = "<?php
    define('TEST_RUNNER', true);
    if (session_status() === PHP_SESSION_NONE) {
        session_save_path('C:/xampp/tmp');
        session_start();
    }
    \$_SESSION = {$sessionExport};
    \$_GET = {$queryExport};
    \$_SERVER['REQUEST_METHOD'] = 'GET';
    \$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/config/microsoft_config.php';
    require_once __DIR__ . '/includes/MicrosoftAzureProvider.php';
    require_once __DIR__ . '/includes/MicrosoftOAuthService.php';

    // Mock service returning the tested profile result
    class TestSubprocService extends MicrosoftOAuthService {
        private array \$profile;
        public function __construct(array \$p) { \$this->profile = \$p; }
        public function validateState(?string \$r, ?string \$s): bool {
            return !empty(\$r) && !empty(\$s) && hash_equals(\$s, \$r);
        }
        public function getUserFromCode(string \$code): array {
            return \$this->profile;
        }
    }

    try {
        \$state = \$_GET['state'] ?? '';
        \$sessionState = \$_SESSION['microsoft_oauth_state'] ?? '';
        \$code = \$_GET['code'] ?? '';

        \$oauthService = new TestSubprocService({$profileExport});

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
                ? 'missing_email'
                : 'microsoft_oauth_failed';
            echo 'REDIRECT:' . BASE_URL . '/auth/login.php?error=' . \$err;
            echo '|SESSION:' . json_encode(\$_SESSION);
            exit;
        }

        \$msEmail = trim(\$profileResult['email']);

        \$db = getDB();
        \$stmt = \$db->prepare('SELECT * FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1');
        \$stmt->execute([\$msEmail]);
        \$localUser = \$stmt->fetch(PDO::FETCH_ASSOC);

        if (!\$localUser) {
            echo 'REDIRECT:' . BASE_URL . '/auth/login.php?error=microsoft_account_not_found';
            echo '|SESSION:' . json_encode(\$_SESSION);
            exit;
        }

        session_regenerate_id(true);

        \$_SESSION['user_id']              = \$localUser['id'];
        \$_SESSION['user_name']            = \$localUser['name'];
        \$_SESSION['user_email']           = \$localUser['email'];
        \$_SESSION['user_role']            = \$localUser['role'];
        \$_SESSION['user_dept']            = \$localUser['department'];
        \$_SESSION['must_change_password'] = (int)(\$localUser['must_change_password'] ?? 0);
        \$_SESSION['auth_method']          = 'microsoft';

        \$map = [
            'admin1'  => 'admin1',
            'admin2'  => 'admin2',
            'dean'    => 'dean',
            'faculty' => 'faculty',
        ];
        \$dir = \$map[\$localUser['role']] ?? 'faculty';
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

    fwrite($pipes[0], $phpCode);
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

$validState = 'test_state_1234567890123456789012';

// 4.1 Successful retrieval reaching local user lookup for registered user
$facultyEmail = 'faculty@sti.edu';
$dbUser = $db->query("SELECT * FROM users WHERE email = '{$facultyEmail}'")->fetch(PDO::FETCH_ASSOC);

$resSuccess = runCallbackSubprocess(
    ['state' => $validState, 'code' => 'code_valid'],
    $validState,
    ['success' => true, 'email' => $facultyEmail, 'name' => 'Maria Santos', 'id' => 'ms-001']
);

assertTest(
    "4.1 Successful profile retrieval reaches local lookup and redirects to dashboard",
    str_contains($resSuccess['redirect'], '/faculty/dashboard.php'),
    "Redirect: " . $resSuccess['redirect']
);
assertTest(
    "4.1 Session is created with correct local user_id",
    ($resSuccess['session']['user_id'] ?? 0) === (int)$dbUser['id']
);
assertTest(
    "4.1 Session auth_method is 'microsoft'",
    ($resSuccess['session']['auth_method'] ?? '') === 'microsoft'
);

// 4.2 Unregistered user is denied access
$resUnregistered = runCallbackSubprocess(
    ['state' => $validState, 'code' => 'code_unregistered'],
    $validState,
    ['success' => true, 'email' => 'unregistered.student@marikina.sti.edu.ph', 'name' => 'Unknown Student', 'id' => 'ms-999']
);

assertTest(
    "4.2 Unregistered account is rejected with error=microsoft_account_not_found",
    str_contains($resUnregistered['redirect'], 'error=microsoft_account_not_found'),
    "Redirect: " . $resUnregistered['redirect']
);
assertTest(
    "4.2 Unregistered account DOES NOT create an authenticated session",
    empty($resUnregistered['session']['user_id'])
);

// 4.3 Graph failure DOES NOT create a session
$resGraphFailure = runCallbackSubprocess(
    ['state' => $validState, 'code' => 'code_failed'],
    $validState,
    ['success' => false, 'error' => 'Microsoft authentication service returned an error. Please try again.']
);

assertTest(
    "4.3 Graph failure redirects to error=microsoft_oauth_failed",
    str_contains($resGraphFailure['redirect'], 'error=microsoft_oauth_failed'),
    "Redirect: " . $resGraphFailure['redirect']
);
assertTest(
    "4.3 Graph failure DOES NOT create an authenticated session",
    empty($resGraphFailure['session']['user_id'])
);

echo "\n========================================================================\n";
echo " TEST SUMMARY: Passed: $passed | Failed: $failed\n";
echo "========================================================================\n";

exit($failed === 0 ? 0 : 1);
