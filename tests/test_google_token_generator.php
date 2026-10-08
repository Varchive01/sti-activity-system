<?php
/**
 * Focused Verification Suite for Google OAuth Token Generator & Authorization Flow
 *
 * Verifies:
 * 1. Client credentials validation (presence of ID & secret).
 * 2. Authorization URL generation with required scopes and offline parameters.
 * 3. Authorization code exchange parsing (access_token, refresh_token).
 * 4. Error response handling without secret leakage.
 * 5. CLI helper flags (--help, --check, missing credentials prerequisite).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/google_config.php';
require_once __DIR__ . '/../includes/GoogleOAuthService.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

echo "========================================================================\n";
echo "  GOOGLE OAUTH TOKEN GENERATOR FOCUSED VERIFICATION\n";
echo "========================================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $title, bool $condition, string $detail = ''): void
{
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
// 1. Client Credentials Validation Tests
// -------------------------------------------------------------------------
echo "--- 1. Client Credentials Validation Tests ---\n";

$svcEmpty = new GoogleOAuthService([
    'GOOGLE_CLIENT_ID'     => '',
    'GOOGLE_CLIENT_SECRET' => '',
    'GOOGLE_REFRESH_TOKEN' => '',
]);
$valEmpty = $svcEmpty->validateClientCredentials();
assertTest("1.1 Empty client credentials rejected", $valEmpty['valid'] === false);
assertTest("1.2 Error specifies missing Client ID", str_contains($valEmpty['error'] ?? '', 'Client ID'));

$svcNoSecret = new GoogleOAuthService([
    'GOOGLE_CLIENT_ID'     => 'test-client-id-123.apps.googleusercontent.com',
    'GOOGLE_CLIENT_SECRET' => '',
    'GOOGLE_REFRESH_TOKEN' => '',
]);
$valNoSecret = $svcNoSecret->validateClientCredentials();
assertTest("1.3 Missing client secret rejected", $valNoSecret['valid'] === false && str_contains($valNoSecret['error'] ?? '', 'Client Secret'));

$svcValidClient = new GoogleOAuthService([
    'GOOGLE_CLIENT_ID'     => 'test-client-id-123.apps.googleusercontent.com',
    'GOOGLE_CLIENT_SECRET' => 'super-secret-key-xyz',
    'GOOGLE_REFRESH_TOKEN' => '', // Refresh token NOT yet present
]);
$valValidClient = $svcValidClient->validateClientCredentials();
assertTest("1.4 Client credentials valid without refresh token", $valValidClient['valid'] === true);

// -------------------------------------------------------------------------
// 2. Authorization URL Generation Tests
// -------------------------------------------------------------------------
echo "\n--- 2. Authorization URL Generation Tests ---\n";

$redirectUri = 'http://localhost/sti-activity-system/api/google-oauth-callback.php';
$authUrl = $svcValidClient->getAuthorizationUrl($redirectUri, 'test_state_123');

assertTest("2.1 Authorization URL points to Google accounts endpoint", str_starts_with($authUrl, 'https://accounts.google.com/o/oauth2/v2/auth'));
assertTest("2.2 Contains client_id parameter", str_contains($authUrl, 'client_id=test-client-id-123.apps.googleusercontent.com'));
assertTest("2.3 Contains encoded redirect_uri parameter", str_contains($authUrl, urlencode($redirectUri)));
assertTest("2.4 Contains response_type=code", str_contains($authUrl, 'response_type=code'));
assertTest("2.5 Contains access_type=offline for refresh token", str_contains($authUrl, 'access_type=offline'));
assertTest("2.6 Contains prompt=consent", str_contains($authUrl, 'prompt=consent'));
assertTest("2.7 Contains forms.body scope", str_contains($authUrl, urlencode('https://www.googleapis.com/auth/forms.body')));
assertTest("2.8 Contains forms.responses.readonly scope", str_contains($authUrl, urlencode('https://www.googleapis.com/auth/forms.responses.readonly')));
assertTest("2.9 Contains state parameter", str_contains($authUrl, 'state=test_state_123'));

// -------------------------------------------------------------------------
// 3. Authorization Code Exchange Success Tests
// -------------------------------------------------------------------------
echo "\n--- 3. Authorization Code Exchange Tests ---\n";

$mockRefreshToken = '1//04test_mock_refresh_token_abc987';
$mockAccessToken  = 'ya29.a0test_mock_access_token_xyz123';

$mockSuccessHttp = new MockHandler([
    new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'access_token'  => $mockAccessToken,
        'expires_in'    => 3599,
        'refresh_token' => $mockRefreshToken,
        'scope'         => GoogleOAuthService::SCOPES,
        'token_type'    => 'Bearer',
    ]))
]);

$svcExchange = new GoogleOAuthService([
    'GOOGLE_CLIENT_ID'     => 'test-client-id-123.apps.googleusercontent.com',
    'GOOGLE_CLIENT_SECRET' => 'super-secret-client-key-456',
    'GOOGLE_REFRESH_TOKEN' => '',
], new Client(['handler' => HandlerStack::create($mockSuccessHttp)]));

$exchangeResult = $svcExchange->exchangeAuthorizationCode('4/0AY0e-mock_code', $redirectUri);

assertTest("3.1 Code exchange returns success=true", ($exchangeResult['success'] ?? false) === true);
assertTest("3.2 Returns parsed refresh_token", ($exchangeResult['refresh_token'] ?? '') === $mockRefreshToken);
assertTest("3.3 Returns parsed access_token", ($exchangeResult['access_token'] ?? '') === $mockAccessToken);
assertTest("3.4 Returns expires_in integer", ($exchangeResult['expires_in'] ?? 0) === 3599);
assertTest("3.5 Returns token_type Bearer", ($exchangeResult['token_type'] ?? '') === 'Bearer');

// Empty code validation
$resEmptyCode = $svcExchange->exchangeAuthorizationCode('', $redirectUri);
assertTest("3.6 Empty authorization code rejected", ($resEmptyCode['success'] ?? true) === false);

// -------------------------------------------------------------------------
// 4. Error Handling & Secret Protection Tests
// -------------------------------------------------------------------------
echo "\n--- 4. Error Handling & Secret Protection Tests ---\n";

$secretKey = 'super-secret-client-key-456';
$mockFailHttp = new MockHandler([
    new Response(400, ['Content-Type' => 'application/json'], json_encode([
        'error'             => 'invalid_grant',
        'error_description' => "Malformed code or expired for secret {$secretKey}",
    ]))
]);

$svcFailExchange = new GoogleOAuthService([
    'GOOGLE_CLIENT_ID'     => 'test-client-id-123.apps.googleusercontent.com',
    'GOOGLE_CLIENT_SECRET' => $secretKey,
    'GOOGLE_REFRESH_TOKEN' => '',
], new Client(['handler' => HandlerStack::create($mockFailHttp)]));

$failResult = $svcFailExchange->exchangeAuthorizationCode('invalid_code_123', $redirectUri);

assertTest("4.1 HTTP 400 error returns success=false", ($failResult['success'] ?? true) === false);
assertTest("4.2 Error message contains sanitized description", str_contains($failResult['error'] ?? '', 'invalid_grant') || str_contains($failResult['error'] ?? '', 'Malformed code'));
assertTest("4.3 Error response does NOT leak raw client secret", !str_contains($failResult['error'] ?? '', $secretKey));

// -------------------------------------------------------------------------
// 5. CLI Script Execution Tests
// -------------------------------------------------------------------------
echo "\n--- 5. CLI Script Execution Tests ---\n";

// 5.1 Test --help
$helpOutput = shell_exec('php "' . __DIR__ . '/../scripts/generate_google_token.php" --help 2>&1');
assertTest("5.1 CLI --help returns usage guide", str_contains($helpOutput ?? '', 'Usage:'));
assertTest("5.2 CLI --help shows forms.body scope", str_contains($helpOutput ?? '', 'forms.body'));
assertTest("5.3 CLI --help shows forms.responses.readonly scope", str_contains($helpOutput ?? '', 'forms.responses.readonly'));

// 5.2 Test --check
$checkOutput = shell_exec('php "' . __DIR__ . '/../scripts/generate_google_token.php" --check 2>&1');
assertTest("5.4 CLI --check executes and displays status report", str_contains($checkOutput ?? '', 'Google Forms Configuration Status Check'));
assertTest("5.5 CLI --check masks credentials", !str_contains($checkOutput ?? '', 'super-secret') && str_contains($checkOutput ?? '', 'Scopes:'));

// 5.3 Test no-args when credentials missing
$noArgsOutput = shell_exec('php "' . __DIR__ . '/../scripts/generate_google_token.php" 2>&1');
assertTest("5.6 Running without credentials reports prerequisite guidance", str_contains($noArgsOutput ?? '', 'Missing Google Cloud OAuth Client credentials') || str_contains($noArgsOutput ?? '', 'Prerequisite Steps:'));

echo "\n========================================================================\n";
echo " VERIFICATION SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
