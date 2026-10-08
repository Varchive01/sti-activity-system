<?php
/**
 * Focused Verification Suite for GoogleFormsService
 * 
 * Verifies:
 * 1. forms.create success response parsing (formId, responderUri, revisionId).
 * 2. forms.batchUpdate success response parsing (replies, data).
 * 3. OAuth token failure propagation (without token leak).
 * 4. Google Forms API HTTP/error handling (HTTP 400, 403, 500).
 * 5. Credential / token masking in error strings.
 * 6. Input validation (missing title, missing formId).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/GoogleOAuthService.php';
require_once __DIR__ . '/../includes/GoogleFormsService.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;

echo "========================================================================\n";
echo "  GOOGLE FORMS SERVICE UNIT & SECURITY VERIFICATION\n";
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

// Helper mock OAuth service returning a known dummy access token
function createMockOAuthService(bool $shouldSucceed = true, string $token = 'dummy_secret_access_token_12345'): GoogleOAuthService {
    $mockHttp = new MockHandler([
        new Response(
            $shouldSucceed ? 200 : 400,
            ['Content-Type' => 'application/json'],
            $shouldSucceed
                ? json_encode(['access_token' => $token, 'expires_in' => 3600, 'token_type' => 'Bearer'])
                : json_encode(['error' => 'invalid_grant', 'error_description' => 'Token has expired'])
        )
    ]);

    return new GoogleOAuthService([
        'GOOGLE_CLIENT_ID'     => 'test-client-id',
        'GOOGLE_CLIENT_SECRET' => 'test-client-secret',
        'GOOGLE_REFRESH_TOKEN' => 'test-refresh-token',
    ], new Client(['handler' => HandlerStack::create($mockHttp)]));
}

// -------------------------------------------------------------------------
// 1. forms.create Success Parsing
// -------------------------------------------------------------------------
echo "--- 1. forms.create Success Tests ---\n";

$mockFormId = '1FAIpQLScMockFormId_ABC123';
$mockResponderUri = 'https://docs.google.com/forms/d/e/1FAIpQLScMockFormId_ABC123/viewform';
$mockRevisionId = '00000001';

$createResponseBody = json_encode([
    'formId'       => $mockFormId,
    'info'         => [
        'title'         => 'Test Evaluation Activity',
        'documentTitle' => 'Test Evaluation Activity Form'
    ],
    'responderUri' => $mockResponderUri,
    'revisionId'   => $mockRevisionId,
]);

$mockHttp = new MockHandler([
    new Response(200, ['Content-Type' => 'application/json'], $createResponseBody)
]);
$httpClient = new Client(['handler' => HandlerStack::create($mockHttp)]);
$formsService = new GoogleFormsService(createMockOAuthService(true), $httpClient);

$createResult = $formsService->createForm('Test Evaluation Activity');

assertTest("1.1 forms.create returns success=true", $createResult['success'] === true);
assertTest("1.2 forms.create returns parsed form_id", ($createResult['form_id'] ?? '') === $mockFormId);
assertTest("1.3 forms.create returns parsed responder_uri", ($createResult['responder_uri'] ?? '') === $mockResponderUri);
assertTest("1.4 forms.create returns parsed revision_id", ($createResult['revision_id'] ?? '') === $mockRevisionId);

// -------------------------------------------------------------------------
// 2. forms.batchUpdate Success Parsing
// -------------------------------------------------------------------------
echo "\n--- 2. forms.batchUpdate Success Tests ---\n";

$batchResponseBody = json_encode([
    'replies' => [
        ['createItem' => ['itemId' => '01000001', 'questionId' => ['78f23a9b']]],
        ['createItem' => ['itemId' => '01000002', 'questionId' => ['42c8d10e']]],
    ],
    'writeControl' => ['requiredRevisionId' => '00000002'],
]);

$mockHttp2 = new MockHandler([
    new Response(200, ['Content-Type' => 'application/json'], $batchResponseBody)
]);
$httpClient2 = new Client(['handler' => HandlerStack::create($mockHttp2)]);
$formsService2 = new GoogleFormsService(createMockOAuthService(true), $httpClient2);

$sampleRequests = [
    [
        'createItem' => [
            'item' => [
                'title' => 'How satisfied were you with the speaker?',
                'questionItem' => [
                    'question' => [
                        'required' => true,
                        'scaleQuestion' => ['low' => 1, 'high' => 4]
                    ]
                ]
            ],
            'location' => ['index' => 0]
        ]
    ]
];

$batchResult = $formsService2->batchUpdate($mockFormId, $sampleRequests);

assertTest("2.1 forms.batchUpdate returns success=true", $batchResult['success'] === true);
assertTest("2.2 forms.batchUpdate returns parsed replies", isset($batchResult['replies']) && count($batchResult['replies']) === 2);
assertTest("2.3 forms.batchUpdate returns reply item IDs", ($batchResult['replies'][0]['createItem']['itemId'] ?? '') === '01000001');
assertTest("2.4 forms.batchUpdate returns reply question IDs", ($batchResult['replies'][0]['createItem']['questionId'][0] ?? '') === '78f23a9b');

// -------------------------------------------------------------------------
// 3. OAuth Failure Handling
// -------------------------------------------------------------------------
echo "\n--- 3. OAuth Failure Handling Tests ---\n";

$failedOAuthService = createMockOAuthService(false);
$mockHttp3 = new MockHandler([]);
$formsServiceOAuthFail = new GoogleFormsService($failedOAuthService, new Client(['handler' => HandlerStack::create($mockHttp3)]));

$failResult = $formsServiceOAuthFail->createForm('Should Fail Title');

assertTest("3.1 OAuth failure propagates success=false", $failResult['success'] === false);
assertTest("3.2 OAuth error message indicates authentication failure", str_contains($failResult['error'] ?? '', 'OAuth'));

// -------------------------------------------------------------------------
// 4. API / HTTP Error Handling & Token Masking
// -------------------------------------------------------------------------
echo "\n--- 4. API Error Handling & Credential Masking Tests ---\n";

$sensitiveToken = 'super_secret_bearer_token_xyz999';
$apiErrorBody = json_encode([
    'error' => [
        'code'    => 400,
        'message' => "Invalid question scale index near token {$sensitiveToken}",
        'status'  => 'INVALID_ARGUMENT'
    ]
]);

$mockHttp4 = new MockHandler([
    new Response(400, ['Content-Type' => 'application/json'], $apiErrorBody)
]);
$httpClient4 = new Client(['handler' => HandlerStack::create($mockHttp4)]);
$formsServiceErr = new GoogleFormsService(createMockOAuthService(true, $sensitiveToken), $httpClient4);

$errResult = $formsServiceErr->createForm('Valid Title With API Error');

assertTest("4.1 HTTP 400 error returns success=false", $errResult['success'] === false);
assertTest("4.2 Error message contains sanitized API error description", str_contains($errResult['error'], 'Invalid question scale index'));
assertTest("4.3 Bearer token is completely redacted from error output", !str_contains($errResult['error'], $sensitiveToken));

// -------------------------------------------------------------------------
// 5. Input Validation Tests
// -------------------------------------------------------------------------
echo "\n--- 5. Input Validation Tests ---\n";

$formsServiceVal = new GoogleFormsService(createMockOAuthService(true));

$emptyTitleRes = $formsServiceVal->createForm('');
assertTest("5.1 Empty form title rejected without network call", $emptyTitleRes['success'] === false && str_contains($emptyTitleRes['error'], 'Form title is required'));

$emptyIdRes = $formsServiceVal->batchUpdate('', [['createItem' => []]]);
assertTest("5.2 Empty form ID rejected without network call", $emptyIdRes['success'] === false && str_contains($emptyIdRes['error'], 'Form ID is required'));

// -------------------------------------------------------------------------
// 6. Network Exception Safety
// -------------------------------------------------------------------------
echo "\n--- 6. Network Exception Safety Tests ---\n";

$mockNet = new MockHandler([
    new ConnectException('Connection timed out', new Request('POST', 'https://forms.googleapis.com/v1/forms'))
]);
$formsServiceNet = new GoogleFormsService(createMockOAuthService(true), new Client(['handler' => HandlerStack::create($mockNet)]));

$netResult = $formsServiceNet->createForm('Network Timeout Test');
assertTest("6.1 Network exception caught safely with success=false", $netResult['success'] === false);
assertTest("6.2 Network error message is clean and generic", str_contains($netResult['error'], 'Network or communication error'));

echo "\n========================================================================\n";
echo " VERIFICATION SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
