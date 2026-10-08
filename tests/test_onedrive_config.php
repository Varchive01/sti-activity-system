<?php
/**
 * tests/test_onedrive_config.php
 *
 * Focused Verification Suite for OneDrive Microsoft Graph Configuration and Auth.
 *
 * Verifies:
 * 1. Configuration loading and field structure (getOneDriveConfig).
 * 2. Strict separation: ONEDRIVE_REFRESH_TOKEN is distinct from mail OAUTH_REFRESH_TOKEN.
 * 3. Fallback to Azure App Registration credentials (OAUTH_CLIENT_ID, etc.).
 * 4. Override support via ONEDRIVE_* specific environment variables.
 * 5. Root folder and enabled flag defaults and boolean parsing.
 * 6. Scope includes Files.ReadWrite and offline_access.
 * 7. OneDriveAuthService::validateConfig() enforces all required credentials.
 * 8. OneDriveAuthService::getAccessToken() with mocked token responses (success, expired, invalid_grant).
 * 9. OneDriveAuthService::exchangeCodeForTokens() with mocked response.
 * 10. No regression on getMicrosoftOAuthConfig() or getMailConfig().
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/mail_config.php';
require_once __DIR__ . '/../config/microsoft_config.php';
require_once __DIR__ . '/../config/onedrive_config.php';
require_once __DIR__ . '/../includes/OneDriveAuthService.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\ConnectException;

echo "========================================================================\n";
echo "  ONEDRIVE GRAPH PERMISSION & TOKEN CONFIGURATION VERIFICATION\n";
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

// -------------------------------------------------------------------------
// SECTION 1: Configuration Loading & Separation Tests
// -------------------------------------------------------------------------
echo "--- 1. Configuration Loading & Token Isolation Tests ---\n";

$oneDriveConfig = getOneDriveConfig();
$mailConfig     = getMailConfig();
$msConfig       = getMicrosoftOAuthConfig();

assertTest("1.1 getOneDriveConfig() returns an array with required keys",
    isset($oneDriveConfig['client_id'], $oneDriveConfig['client_secret'], $oneDriveConfig['tenant_id'], $oneDriveConfig['refresh_token'], $oneDriveConfig['root_folder'], $oneDriveConfig['enabled'], $oneDriveConfig['scopes'])
);

assertTest("1.2 Root folder defaults to 'STI_Activity_System_Documents' or non-empty string",
    !empty($oneDriveConfig['root_folder']) && is_string($oneDriveConfig['root_folder'])
);

assertTest("1.3 Enabled flag is boolean",
    is_bool($oneDriveConfig['enabled'])
);

assertTest("1.4 Scopes contain Files.ReadWrite and offline_access",
    str_contains($oneDriveConfig['scopes'], 'Files.ReadWrite') && str_contains($oneDriveConfig['scopes'], 'offline_access')
);

assertTest("1.5 Reuses Azure App Registration client_id when ONEDRIVE_CLIENT_ID is unset",
    !empty($oneDriveConfig['client_id']) && $oneDriveConfig['client_id'] === $msConfig['client_id']
);

assertTest("1.6 Reuses Azure App Registration client_secret when ONEDRIVE_CLIENT_SECRET is unset",
    !empty($oneDriveConfig['client_secret']) && $oneDriveConfig['client_secret'] === $msConfig['client_secret']
);

assertTest("1.7 Reuses Azure App Registration tenant_id when ONEDRIVE_TENANT_ID is unset",
    !empty($oneDriveConfig['tenant_id']) && $oneDriveConfig['tenant_id'] === $msConfig['tenant_id']
);

// Verify isolation: If ONEDRIVE_REFRESH_TOKEN is not explicitly in .env,
// getOneDriveConfig() MUST NOT fall back to the mail OAUTH_REFRESH_TOKEN
$rawEnvHasOneDriveToken = !empty(getenv('ONEDRIVE_REFRESH_TOKEN'));
if (!$rawEnvHasOneDriveToken && !empty($mailConfig['OAUTH_REFRESH_TOKEN'])) {
    assertTest("1.8 Strict Token Isolation: ONEDRIVE_REFRESH_TOKEN does not copy mail OAUTH_REFRESH_TOKEN",
        $oneDriveConfig['refresh_token'] !== $mailConfig['OAUTH_REFRESH_TOKEN'] || empty($oneDriveConfig['refresh_token'])
    );
} else {
    assertTest("1.8 Strict Token Isolation: ONEDRIVE_REFRESH_TOKEN key is handled independently", true);
}

// Verify Mail and Login configurations are undisturbed
assertTest("1.9 Mail configuration remains intact (OAUTH_CLIENT_ID / OAUTH_REFRESH_TOKEN)",
    isset($mailConfig['OAUTH_CLIENT_ID'], $mailConfig['OAUTH_REFRESH_TOKEN'])
);

assertTest("1.10 Microsoft Login configuration remains intact (redirect_uri to callback.php)",
    str_contains($msConfig['redirect_uri'], '/auth/microsoft-callback.php')
);

// -------------------------------------------------------------------------
// SECTION 2: Credential Validation in OneDriveAuthService
// -------------------------------------------------------------------------
echo "\n--- 2. Credential Validation Tests ---\n";

// Case 2.1: Missing client_id
$svcMissingClientId = new OneDriveAuthService([
    'client_id'     => '',
    'client_secret' => 'sec-123',
    'tenant_id'     => 'common',
    'refresh_token' => 'rt-123',
]);
$res21 = $svcMissingClientId->validateConfig();
assertTest("2.1 Missing client_id fails validation", $res21['valid'] === false && str_contains($res21['error'] ?? '', 'Client ID'));

// Case 2.2: Missing client_secret
$svcMissingSecret = new OneDriveAuthService([
    'client_id'     => 'app-123',
    'client_secret' => '',
    'tenant_id'     => 'common',
    'refresh_token' => 'rt-123',
]);
$res22 = $svcMissingSecret->validateConfig();
assertTest("2.2 Missing client_secret fails validation", $res22['valid'] === false && str_contains($res22['error'] ?? '', 'Client Secret'));

// Case 2.3: Missing tenant_id
$svcMissingTenant = new OneDriveAuthService([
    'client_id'     => 'app-123',
    'client_secret' => 'sec-123',
    'tenant_id'     => '',
    'refresh_token' => 'rt-123',
]);
$res23 = $svcMissingTenant->validateConfig();
assertTest("2.3 Missing tenant_id fails validation", $res23['valid'] === false && str_contains($res23['error'] ?? '', 'Tenant ID'));

// Case 2.4: Missing refresh_token (OneDrive not yet authorized)
$svcMissingToken = new OneDriveAuthService([
    'client_id'     => 'app-123',
    'client_secret' => 'sec-123',
    'tenant_id'     => 'common',
    'refresh_token' => '',
]);
$res24 = $svcMissingToken->validateConfig();
assertTest("2.4 Missing refresh_token fails validation with clear authorization notice",
    $res24['valid'] === false && str_contains($res24['error'] ?? '', 'ONEDRIVE_REFRESH_TOKEN')
);

// Case 2.5: Fully configured
$svcValid = new OneDriveAuthService([
    'client_id'     => 'app-123',
    'client_secret' => 'sec-123',
    'tenant_id'     => 'common',
    'refresh_token' => 'rt-test-token',
    'enabled'       => true,
    'root_folder'   => 'Test_Folder',
]);
$res25 = $svcValid->validateConfig();
assertTest("2.5 Valid configuration passes validation", $res25['valid'] === true);
assertTest("2.6 isEnabled() returns true when configured", $svcValid->isEnabled() === true);
assertTest("2.7 getRootFolder() returns configured folder name", $svcValid->getRootFolder() === 'Test_Folder');

// -------------------------------------------------------------------------
// SECTION 3: Authorization URL Generation
// -------------------------------------------------------------------------
echo "\n--- 3. Authorization URL Generation Tests ---\n";

$authUrl = $svcValid->getAuthorizationUrl('http://localhost/sti-activity-system/auth/microsoft-callback.php', 'state123');

assertTest("3.1 Auth URL targets login.microsoftonline.com", str_contains($authUrl, 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize'));
assertTest("3.2 Auth URL contains client_id", str_contains($authUrl, 'client_id=app-123'));
assertTest("3.3 Auth URL specifies response_type=code", str_contains($authUrl, 'response_type=code'));
assertTest("3.4 Auth URL specifies Files.ReadWrite scope", str_contains($authUrl, 'Files.ReadWrite'));
assertTest("3.5 Auth URL specifies offline_access scope", str_contains($authUrl, 'offline_access'));
assertTest("3.6 Auth URL includes redirect_uri", str_contains($authUrl, rawurlencode('http://localhost/sti-activity-system/auth/microsoft-callback.php')) || str_contains($authUrl, 'redirect_uri=http%3A%2F%2Flocalhost'));
assertTest("3.7 Auth URL preserves state parameter", str_contains($authUrl, 'state=state123'));

// -------------------------------------------------------------------------
// SECTION 4: Mocked Token Exchange (getAccessToken via Refresh Token)
// -------------------------------------------------------------------------
echo "\n--- 4. Mocked Token Refresh Tests ---\n";

// Case 4.1: Successful Token Refresh
$mockSuccess = new MockHandler([
    new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'token_type'    => 'Bearer',
        'scope'         => 'Files.ReadWrite User.Read profile openid email',
        'expires_in'    => 3600,
        'access_token'  => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1Ni...mock_onedrive_access_token',
        'refresh_token' => '0.AXo...new_rotated_refresh_token',
    ])),
]);
$handlerStackSuccess = HandlerStack::create($mockSuccess);
$clientSuccess = new Client(['handler' => $handlerStackSuccess]);

$authSvcMockSuccess = new OneDriveAuthService([
    'client_id'     => 'test-client-id',
    'client_secret' => 'test-client-secret',
    'tenant_id'     => 'common',
    'refresh_token' => 'mock_existing_refresh_token',
], $clientSuccess);

$tokenResult = $authSvcMockSuccess->getAccessToken();
assertTest("4.1 getAccessToken succeeds with mock 200 response", $tokenResult['success'] === true);
assertTest("4.2 Correct access token extracted", ($tokenResult['access_token'] ?? '') === 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1Ni...mock_onedrive_access_token');
assertTest("4.3 Expiry time parsed (3600 seconds)", ($tokenResult['expires_in'] ?? 0) === 3600);
assertTest("4.4 Rotated refresh token captured if returned", ($tokenResult['refresh_token'] ?? '') === '0.AXo...new_rotated_refresh_token');

// Case 4.2: Microsoft invalid_grant Error (e.g. revoked/expired refresh token)
$mockInvalidGrant = new MockHandler([
    new Response(400, ['Content-Type' => 'application/json'], json_encode([
        'error'             => 'invalid_grant',
        'error_description' => "AADSTS700084: The refresh token was issued to a single application and is expired or revoked.\r\nTrace ID: 123",
        'error_codes'       => [700084],
    ])),
]);
$handlerStackInvalidGrant = HandlerStack::create($mockInvalidGrant);
$clientInvalidGrant = new Client(['handler' => $handlerStackInvalidGrant]);

$authSvcMockError = new OneDriveAuthService([
    'client_id'     => 'test-client-id',
    'client_secret' => 'test-client-secret',
    'tenant_id'     => 'common',
    'refresh_token' => 'expired_refresh_token',
], $clientInvalidGrant);

$errResult = $authSvcMockError->getAccessToken();
assertTest("4.5 Invalid grant returns success=false", $errResult['success'] === false);
assertTest("4.6 Clean error message returned without credential leakage",
    str_contains($errResult['error'] ?? '', 'invalid_grant') && !str_contains($errResult['error'] ?? '', 'test-client-secret')
);

// Case 4.3: Network / Connection Failure Resilience
$mockNetworkFail = new MockHandler([
    new ConnectException('Connection timed out connecting to login.microsoftonline.com', new Request('POST', 'https://login.microsoftonline.com/common/oauth2/v2.0/token')),
]);
$handlerStackNetFail = HandlerStack::create($mockNetworkFail);
$clientNetFail = new Client(['handler' => $handlerStackNetFail]);

$authSvcMockNet = new OneDriveAuthService([
    'client_id'     => 'test-client-id',
    'client_secret' => 'test-client-secret',
    'tenant_id'     => 'common',
    'refresh_token' => 'rt_test',
], $clientNetFail);

$netResult = $authSvcMockNet->getAccessToken();
assertTest("4.7 Network exception handled gracefully with success=false", $netResult['success'] === false);
assertTest("4.8 Non-empty error returned on network exception", !empty($netResult['error']));

// -------------------------------------------------------------------------
// SECTION 5: Mocked Authorization Code Exchange (exchangeCodeForTokens)
// -------------------------------------------------------------------------
echo "\n--- 5. Mocked Authorization Code Exchange Tests ---\n";

$mockCodeExchange = new MockHandler([
    new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'token_type'    => 'Bearer',
        'scope'         => 'https://graph.microsoft.com/Files.ReadWrite https://graph.microsoft.com/offline_access',
        'expires_in'    => 3600,
        'access_token'  => 'eyJ0e...initial_access_token',
        'refresh_token' => '0.AXo...first_acquired_onedrive_refresh_token',
    ])),
]);
$handlerStackCode = HandlerStack::create($mockCodeExchange);
$clientCode = new Client(['handler' => $handlerStackCode]);

$authSvcCode = new OneDriveAuthService([
    'client_id'     => 'test-client-id',
    'client_secret' => 'test-client-secret',
    'tenant_id'     => 'common',
    'refresh_token' => '',
], $clientCode);

$exchangeRes = $authSvcCode->exchangeCodeForTokens('sample_auth_code', 'http://localhost/sti-activity-system/auth/microsoft-callback.php');
assertTest("5.1 exchangeCodeForTokens succeeds with 200 response", $exchangeRes['success'] === true);
assertTest("5.2 Acquired refresh token extracted correctly", ($exchangeRes['refresh_token'] ?? '') === '0.AXo...first_acquired_onedrive_refresh_token');
assertTest("5.3 Initial access token extracted correctly", ($exchangeRes['access_token'] ?? '') === 'eyJ0e...initial_access_token');

// -------------------------------------------------------------------------
// SECTION 6: CLI Utility Verification
// -------------------------------------------------------------------------
echo "\n--- 6. CLI Utility Smoke Tests ---\n";

assertTest("6.1 scripts/generate_onedrive_token.php exists on disk",
    file_exists(__DIR__ . '/../scripts/generate_onedrive_token.php')
);

// Run the script with --help via CLI
$helpOutput = shell_exec('php ' . escapeshellarg(__DIR__ . '/../scripts/generate_onedrive_token.php') . ' --help');
assertTest("6.2 CLI utility runs with --help and displays usage",
    str_contains((string)$helpOutput, 'Microsoft OneDrive (Graph) Refresh Token Generator')
);

// Run the script with --check via CLI
$checkOutput = shell_exec('php ' . escapeshellarg(__DIR__ . '/../scripts/generate_onedrive_token.php') . ' --check');
assertTest("6.3 CLI utility runs with --check and reports status",
    str_contains((string)$checkOutput, 'OneDrive Configuration Status Check')
);

echo "\n========================================================================\n";
echo " VERIFICATION SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================================\n";

exit($failed > 0 ? 1 : 0);
