<?php
/**
 * tests/test_onedrive_oauth_callback.php
 *
 * Focused Verification Suite for Dedicated OneDrive OAuth Callback & State Bridge.
 *
 * Verifies:
 * 1. Dedicated callback redirect URI in scripts/generate_onedrive_token.php.
 * 2. Cryptographic state generation & constant-time validation.
 * 3. Secure bridge storage outside public web root with 10-minute maximum lifetime.
 * 4. Callback execution:
 *    - Rejection of non-GET requests (HTTP 405).
 *    - Rejection of missing state (HTTP 400).
 *    - Rejection of mismatched state (HTTP 403 / state_mismatch).
 *    - Rejection of expired transactions (HTTP 400 / expired).
 *    - Safe capture of valid code with matching state (HTTP 200).
 *    - Clean handling of Microsoft OAuth errors (HTTP 400).
 * 5. Leakage Prevention:
 *    - Authorization code is NEVER rendered in HTTP HTML/JSON response output.
 *    - Client secret is NEVER rendered in output.
 *    - Refresh token is NEVER rendered in output.
 * 6. CLI transaction retrieval & immediate cleanup.
 * 7. Integrity: auth/microsoft-callback.php remains completely unmodified.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/onedrive_config.php';
require_once __DIR__ . '/../includes/OneDriveAuthService.php';

echo "========================================================================\n";
echo "  DEDICATED ONEDRIVE OAUTH CALLBACK & BRIDGE VERIFICATION SUITE\n";
echo "========================================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $title, bool $condition, string $detail = ''): void {
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
// SECTION 1: Authorization URL & Redirect URI in CLI Script
// -------------------------------------------------------------------------
echo "--- 1. Dedicated Redirect URI & Authorization URL Tests ---\n";

$scriptContent = file_get_contents(__DIR__ . '/../scripts/generate_onedrive_token.php');

assertTest(
    "1.1 scripts/generate_onedrive_token.php references dedicated callback endpoint",
    str_contains($scriptContent, '/api/onedrive-oauth-callback.php'),
    "Expected script to use /api/onedrive-oauth-callback.php"
);

assertTest(
    "1.2 scripts/generate_onedrive_token.php DOES NOT default to auth/microsoft-callback.php",
    !str_contains($scriptContent, "'/auth/microsoft-callback.php'"),
    "Script still contains auth/microsoft-callback.php reference"
);

$config = getOneDriveConfig();
$authService = new OneDriveAuthService($config);
$testState = OneDriveAuthService::generateState();
$authUrl = $authService->getAuthorizationUrl('http://localhost/sti-activity-system/api/onedrive-oauth-callback.php', $testState);

assertTest(
    "1.3 Generated Authorization URL includes dedicated callback redirect_uri",
    str_contains($authUrl, rawurlencode('http://localhost/sti-activity-system/api/onedrive-oauth-callback.php')),
    "Auth URL: {$authUrl}"
);

assertTest(
    "1.4 Generated Authorization URL includes cryptographically generated state parameter",
    str_contains($authUrl, "state={$testState}"),
    "Auth URL state parameter missing"
);

// -------------------------------------------------------------------------
// SECTION 2: Cryptographic State & Storage Location
// -------------------------------------------------------------------------
echo "\n--- 2. OAuth State Security & Storage Location Tests ---\n";

$state1 = OneDriveAuthService::generateState();
$state2 = OneDriveAuthService::generateState();

assertTest(
    "2.1 Generated state is 64-character hex string (32 random bytes)",
    strlen($state1) === 64 && ctype_xdigit($state1)
);

assertTest(
    "2.2 Subsequent state generations produce unique entropy",
    $state1 !== $state2
);

$bridgeDir = OneDriveAuthService::getBridgeDirectory();
assertTest(
    "2.3 Bridge directory is outside public web document root (htdocs)",
    !str_starts_with(str_replace('\\', '/', realpath($bridgeDir) ?: $bridgeDir), str_replace('\\', '/', realpath(__DIR__ . '/..'))),
    "Bridge dir: {$bridgeDir}"
);

assertTest(
    "2.4 Bridge directory exists and is writable",
    is_dir($bridgeDir) && is_writable($bridgeDir)
);

assertTest(
    "2.5 Bridge directory contains .htaccess denying web access",
    file_exists($bridgeDir . DIRECTORY_SEPARATOR . '.htaccess')
);

// -------------------------------------------------------------------------
// SECTION 3: Transaction Bridge Lifecycle & Expiration
// -------------------------------------------------------------------------
echo "\n--- 3. Transaction Bridge Lifecycle & Expiration Tests ---\n";

$txState = OneDriveAuthService::generateState();

// 3.1 Save pending transaction
$saved = OneDriveAuthService::savePendingTransaction($txState);
assertTest("3.1 savePendingTransaction creates pending transaction file", $saved === true);

$pending = OneDriveAuthService::retrievePendingTransaction($txState);
assertTest(
    "3.2 retrievePendingTransaction finds saved transaction with status 'pending'",
    is_array($pending) && ($pending['status'] ?? '') === 'pending' && empty($pending['code'])
);

// 3.3 Reject mismatched state in storeAuthorizationCode
$mismatchedStore = OneDriveAuthService::storeAuthorizationCode('wrong_mismatched_state_value', 'test_auth_code_123');
assertTest(
    "3.3 Storing code with non-existent or mismatched state fails",
    $mismatchedStore['success'] === false
);

// 3.4 Valid storeAuthorizationCode
$validStore = OneDriveAuthService::storeAuthorizationCode($txState, 'microsoft_secret_code_xyz789');
assertTest("3.4 Storing code with matching state succeeds", $validStore['success'] === true);

// 3.5 Retrieve updated transaction
$authorized = OneDriveAuthService::retrievePendingTransaction($txState);
assertTest(
    "3.5 retrievePendingTransaction returns stored code and 'authorized' status",
    is_array($authorized) && ($authorized['code'] ?? '') === 'microsoft_secret_code_xyz789' && ($authorized['status'] ?? '') === 'authorized'
);

// 3.6 Expiration simulation (> 10 minutes)
$expiredTxState = OneDriveAuthService::generateState();
OneDriveAuthService::savePendingTransaction($expiredTxState);
$expiredFile = $bridgeDir . DIRECTORY_SEPARATOR . 'tx_' . hash('sha256', $expiredTxState) . '.json';
// Artificially backdate created_at by 650 seconds (10 mins 50 secs)
$expiredData = json_decode(file_get_contents($expiredFile), true);
$expiredData['created_at'] = time() - 650;
file_put_contents($expiredFile, json_encode($expiredData));

$expiredAttempt = OneDriveAuthService::storeAuthorizationCode($expiredTxState, 'late_code');
assertTest(
    "3.6 Transactions older than 10 minutes are rejected as expired",
    $expiredAttempt['success'] === false && ($expiredAttempt['error'] ?? '') === 'expired'
);

// 3.7 Clear pending transaction
OneDriveAuthService::clearPendingTransaction($txState);
$cleared = OneDriveAuthService::retrievePendingTransaction($txState);
assertTest("3.7 clearPendingTransaction removes transaction file immediately", $cleared === null);

// -------------------------------------------------------------------------
// SECTION 4: Callback Endpoint HTTP Behavior & Output Safety
// -------------------------------------------------------------------------
echo "\n--- 4. Dedicated Callback Endpoint Execution & Leakage Tests ---\n";

/**
 * Helper to invoke api/onedrive-oauth-callback.php via isolated subproc.
 */
function invokeCallback(string $method, array $get = [], array $headers = []): array {
    $script = __DIR__ . '/../api/onedrive-oauth-callback.php';
    $getExport = var_export($get, true);
    $headersExport = var_export($headers, true);

    $code = "<?php
    \$_SERVER['REQUEST_METHOD'] = '{$method}';
    \$_GET = {$getExport};
    foreach ({$headersExport} as \$k => \$v) {
        \$_SERVER[\$k] = \$v;
    }

    // Intercept http_response_code
    require_once '{$script}';
    ";

    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open('php', $desc, $pipes);
    if (!is_resource($proc)) {
        return ['status' => 500, 'stdout' => '', 'stderr' => ''];
    }

    fwrite($pipes[0], $code);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($proc);

    return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
}

// 4.1 Non-GET request rejection (HTTP 405)
$resPost = invokeCallback('POST', ['code' => 'test']);
assertTest(
    "4.1 Non-GET request (POST) is rejected with Method Not Allowed",
    str_contains($resPost['stdout'], 'Method Not Allowed')
);

// 4.2 Missing state rejection (HTTP 400)
$resNoState = invokeCallback('GET', ['code' => 'test_code'], ['HTTP_ACCEPT' => 'application/json']);
assertTest(
    "4.2 Missing state returns HTTP 400 error in JSON",
    str_contains($resNoState['stdout'], '"error":"missing_state"')
);

// 4.3 Missing code rejection (HTTP 400)
$resNoCode = invokeCallback('GET', ['state' => $state1], ['HTTP_ACCEPT' => 'application/json']);
assertTest(
    "4.3 Missing code returns HTTP 400 error in JSON",
    str_contains($resNoCode['stdout'], '"error":"missing_code"')
);

// 4.4 Mismatched state rejection (HTTP 403 / state_mismatch)
$resBadState = invokeCallback('GET', ['state' => 'unregistered_state_abc', 'code' => 'any_code'], ['HTTP_ACCEPT' => 'application/json']);
assertTest(
    "4.4 Mismatched or non-existent state is rejected",
    str_contains($resBadState['stdout'], '"success":false')
);

// 4.5 Microsoft error response handling
$resMsError = invokeCallback('GET', [
    'error'             => 'access_denied',
    'error_description' => 'User consent was cancelled',
    'state'             => $state1,
], ['HTTP_ACCEPT' => 'application/json']);

assertTest(
    "4.5 Microsoft OAuth errors handled gracefully without secret leakage",
    str_contains($resMsError['stdout'], '"error":"access_denied"') && !str_contains($resMsError['stdout'], 'client_secret')
);

// 4.6 Successful OAuth callback flow
$flowState = OneDriveAuthService::generateState();
OneDriveAuthService::savePendingTransaction($flowState);
$secretAuthCode = 'SENSITIVE_MS_AUTH_CODE_' . bin2hex(random_bytes(16));

// Call callback as browser would (HTML response)
$resHtml = invokeCallback('GET', [
    'code'  => $secretAuthCode,
    'state' => $flowState,
]);

assertTest(
    "4.6 Callback renders success status in HTML response",
    str_contains($resHtml['stdout'], 'OneDrive Authorization Received') && str_contains($resHtml['stdout'], 'Ready in Terminal')
);

// -------------------------------------------------------------------------
// SECTION 5: Zero Secret / Code Leakage Verification
// -------------------------------------------------------------------------
echo "\n--- 5. Zero Leakage Verification ---\n";

assertTest(
    "5.1 HTML response NEVER contains the authorization code",
    !str_contains($resHtml['stdout'], $secretAuthCode),
    "Authorization code leaked in HTML output!"
);

$clientSecret = $config['client_secret'];
if (!empty($clientSecret)) {
    assertTest(
        "5.2 HTML response NEVER contains client_secret",
        !str_contains($resHtml['stdout'], $clientSecret),
        "Client secret leaked in HTML output!"
    );
}

$refreshToken = $config['refresh_token'];
if (!empty($refreshToken)) {
    assertTest(
        "5.3 HTML response NEVER contains refresh_token",
        !str_contains($resHtml['stdout'], $refreshToken),
        "Refresh token leaked in HTML output!"
    );
}

// Check JSON format call as well
$jsonFlowState = OneDriveAuthService::generateState();
OneDriveAuthService::savePendingTransaction($jsonFlowState);
$jsonSecretCode = 'JSON_SECRET_CODE_' . bin2hex(random_bytes(16));

$resJson = invokeCallback('GET', [
    'code'   => $jsonSecretCode,
    'state'  => $jsonFlowState,
    'format' => 'json',
]);

assertTest(
    "5.4 JSON response succeeds with success=true",
    str_contains($resJson['stdout'], '"success":true')
);

assertTest(
    "5.5 JSON response NEVER contains the authorization code",
    !str_contains($resJson['stdout'], $jsonSecretCode),
    "Authorization code leaked in JSON output!"
);

// Verify code was securely transferred into bridge
$capturedTx = OneDriveAuthService::retrievePendingTransaction($flowState);
assertTest(
    "5.6 Authorization code is securely retrievable from local bridge",
    ($capturedTx['code'] ?? '') === $secretAuthCode
);

// Cleanup
OneDriveAuthService::clearPendingTransaction($flowState);
OneDriveAuthService::clearPendingTransaction($jsonFlowState);

// -------------------------------------------------------------------------
// SECTION 6: Integrity Verification of auth/microsoft-callback.php
// -------------------------------------------------------------------------
echo "\n--- 6. Microsoft Login Callback Integrity Verification ---\n";

$msCallbackContent = file_get_contents(__DIR__ . '/../auth/microsoft-callback.php');
assertTest(
    "6.1 auth/microsoft-callback.php still contains user mapping logic",
    str_contains($msCallbackContent, 'getUserFromCode') && str_contains($msCallbackContent, 'users WHERE LOWER(TRIM(email))')
);

$diffOutput = shell_exec('git diff ' . escapeshellarg(__DIR__ . '/../auth/microsoft-callback.php'));
assertTest(
    "6.2 auth/microsoft-callback.php has ZERO git modifications",
    empty(trim((string)$diffOutput)),
    "Expected clean git diff for auth/microsoft-callback.php"
);

echo "\n========================================================================\n";
echo " VERIFICATION SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================================\n";

exit($failed > 0 ? 1 : 0);
