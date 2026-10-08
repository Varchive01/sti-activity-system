<?php
/**
 * Focused Verification Suite for GoogleOAuthService
 * 
 * Verifies:
 * 1. Missing credentials handling (clear, non-sensitive errors).
 * 2. Successful token response parsing with mocked responses.
 * 3. Malformed JSON / missing access_token response handling.
 * 4. Google OAuth error responses (e.g., HTTP 400 invalid_grant) handled without credential leakage.
 * 5. Network / exception failure safety.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/google_config.php';
require_once __DIR__ . '/../includes/GoogleOAuthService.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;

echo "========================================================================\n";
echo "  GOOGLE OAUTH SERVICE UNIT & SECURITY VERIFICATION\n";
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
// 1. Missing Credentials Handling
// -------------------------------------------------------------------------
echo "--- 1. Missing Credentials Tests ---\n";

// Case 1.1: All credentials empty
$emptyService = new GoogleOAuthService([
    'GOOGLE_CLIENT_ID' => '',
    'GOOGLE_CLIENT_SECRET' => '',
    'GOOGLE_REFRESH_TOKEN' => '',
]);
$res1 = $emptyService->getAccessToken();
assertTest("1.1 Empty credentials rejected with success=false", $res1['success'] === false);
assertTest("1.1 Non-sensitive error message returned", str_contains($res1['error'], 'Missing Google Client ID'));

// Case 1.2: Missing Secret
$noSecretService = new GoogleOAuthService([
    'GOOGLE_CLIENT_ID' => 'test-client-id.apps.googleusercontent.com',
    'GOOGLE_CLIENT_SECRET' => '',
    'GOOGLE_REFRESH_TOKEN' => '1//test-refresh-token',
]);
$res2 = $noSecretService->getAccessToken();
assertTest("1.2 Missing secret rejected", $res2['success'] === false && str_contains($res2['error'], 'Client Secret'));

// Case 1.3: Missing Refresh Token
$noTokenService = new GoogleOAuthService([
    'GOOGLE_CLIENT_ID' => 'test-client-id.apps.googleusercontent.com',
    'GOOGLE_CLIENT_SECRET' => 'super_secret_key_12345',
    'GOOGLE_REFRESH_TOKEN' => '',
]);
$res3 = $noTokenService->getAccessToken();
assertTest("1.3 Missing refresh token rejected", $res3['success'] === false && str_contains($res3['error'], 'Refresh Token'));
assertTest("1.3 Secrets not exposed in error message", !str_contains($res3['error'], 'super_secret_key_12345'));

// -------------------------------------------------------------------------
// 2. Successful Token Parsing (Mocked)
// -------------------------------------------------------------------------
echo "\n--- 2. Successful Token Parsing Tests (Mocked) ---\n";

$mockAccessToken = 'ya29.a0AfH6SMD_mock_access_token_xyz987';
$mockSuccessBody = json_encode([
    'access_token' => $mockAccessToken,
    'expires_in'   => 3599,
    'token_type'   => 'Bearer',
    'scope'        => 'https://www.googleapis.com/auth/forms.body'
]);

$mock = new MockHandler([
    new Response(200, ['Content-Type' => 'application/json'], $mockSuccessBody)
]);
$client = new Client(['handler' => HandlerStack::create($mock)]);

$service = new GoogleOAuthService([
    'GOOGLE_CLIENT_ID'     => 'test-id.apps.googleusercontent.com',
    'GOOGLE_CLIENT_SECRET' => 'secret_val_xyz',
    'GOOGLE_REFRESH_TOKEN' => '1//refresh_token_abc',
], $client);

$successRes = $service->getAccessToken();
assertTest("2.1 Successful exchange returns success=true", $successRes['success'] === true);
assertTest("2.2 Access token parsed correctly", ($successRes['access_token'] ?? '') === $mockAccessToken);
assertTest("2.3 Expiration parsed correctly", ($successRes['expires_in'] ?? 0) === 3599);
assertTest("2.4 Token type parsed correctly", ($successRes['token_type'] ?? '') === 'Bearer');

// -------------------------------------------------------------------------
// 3. Google OAuth Error Responses Handled Safely
// -------------------------------------------------------------------------
echo "\n--- 3. Error Responses Handled Safely ---\n";

// Case 3.1: HTTP 400 invalid_grant
$mockErrorBody = json_encode([
    'error'             => 'invalid_grant',
    'error_description' => 'Token has been expired or revoked.'
]);

$mockErr = new MockHandler([
    new Response(400, ['Content-Type' => 'application/json'], $mockErrorBody)
]);
$errClient = new Client(['handler' => HandlerStack::create($mockErr)]);

$errService = new GoogleOAuthService([
    'GOOGLE_CLIENT_ID'     => 'test-id.apps.googleusercontent.com',
    'GOOGLE_CLIENT_SECRET' => 'super_secret_credential_value',
    'GOOGLE_REFRESH_TOKEN' => 'secret_refresh_token_value',
], $errClient);

$errRes = $errService->getAccessToken();
assertTest("3.1 HTTP 400 error returns success=false", $errRes['success'] === false);
assertTest("3.2 Error message contains sanitized Google error description", str_contains($errRes['error'], 'Token has been expired or revoked'));
assertTest("3.3 Client secret not exposed in error output", !str_contains($errRes['error'], 'super_secret_credential_value'));
assertTest("3.3 Refresh token not exposed in error output", !str_contains($errRes['error'], 'secret_refresh_token_value'));

// Case 3.2: Malformed Response (HTTP 200 without access_token)
$mockMalformed = new MockHandler([
    new Response(200, ['Content-Type' => 'application/json'], json_encode(['status' => 'ok']))
]);
$malformedClient = new Client(['handler' => HandlerStack::create($mockMalformed)]);

$malService = new GoogleOAuthService([
    'GOOGLE_CLIENT_ID'     => 'test-id',
    'GOOGLE_CLIENT_SECRET' => 'test-sec',
    'GOOGLE_REFRESH_TOKEN' => 'test-tok',
], $malformedClient);

$malRes = $malService->getAccessToken();
assertTest("3.4 Malformed response (missing access_token) returns success=false", $malRes['success'] === false);
assertTest("3.5 Malformed response returns clear error", str_contains($malRes['error'], 'access_token missing'));

// Case 3.3: Network / Connection Failure Exception
$mockNet = new MockHandler([
    new ConnectException('Connection timed out', new Request('POST', 'https://oauth2.googleapis.com/token'))
]);
$netClient = new Client(['handler' => HandlerStack::create($mockNet)]);

$netService = new GoogleOAuthService([
    'GOOGLE_CLIENT_ID'     => 'test-id',
    'GOOGLE_CLIENT_SECRET' => 'my_secret_token_123',
    'GOOGLE_REFRESH_TOKEN' => 'my_refresh_tok_456',
], $netClient);

$netRes = $netService->getAccessToken();
assertTest("3.6 Network exception caught safely without uncaught throw", $netRes['success'] === false);
assertTest("3.7 Network error message is clean and generic", str_contains($netRes['error'], 'Network or communication error'));
assertTest("3.7 No secret leaked during network exception", !str_contains($netRes['error'], 'my_secret_token_123'));

echo "\n========================================================================\n";
echo " VERIFICATION SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
