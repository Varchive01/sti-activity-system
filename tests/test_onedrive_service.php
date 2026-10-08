<?php
/**
 * tests/test_onedrive_service.php
 *
 * Focused Test Suite for Isolated OneDrive Microsoft Graph File Service (OneDriveService).
 *
 * Verifies:
 * 1. Token acquisition & bearer header attachment.
 * 2. Root folder creation & reuse (200 existing vs 404 + 201 created).
 * 3. Nested folder creation & traversal (createFolder, ensureNestedFolder).
 * 4. File upload to OneDrive with item ID and metadata extraction.
 * 5. Microsoft Graph error resilience (401, 403, 404, 500, network exception).
 * 6. Malformed & unexpected Graph response handling.
 * 7. Zero leakage of access tokens, refresh tokens, client secrets, or file contents.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/onedrive_config.php';
require_once __DIR__ . '/../includes/OneDriveAuthService.php';
require_once __DIR__ . '/../includes/OneDriveService.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\ConnectException;

echo "========================================================================\n";
echo "  ISOLATED ONEDRIVE GRAPH FILE SERVICE VERIFICATION SUITE\n";
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

/**
 * Creates a mock OneDriveAuthService that returns a designated access token.
 */
function createMockAuthService(bool $succeed = true, string $token = 'mock_bearer_token_xyz123', int $expiresIn = 3600): OneDriveAuthService {
    $mockResponses = [];
    if ($succeed) {
        $mockResponses[] = new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'token_type'   => 'Bearer',
            'expires_in'   => $expiresIn,
            'access_token' => $token,
        ]));
    } else {
        $mockResponses[] = new Response(400, ['Content-Type' => 'application/json'], json_encode([
            'error'             => 'invalid_grant',
            'error_description' => 'The refresh token is invalid or expired.',
        ]));
    }

    $mockHandler = new MockHandler($mockResponses);
    $handlerStack = HandlerStack::create($mockHandler);
    $mockHttpClient = new Client(['handler' => $handlerStack]);

    return new OneDriveAuthService([
        'client_id'     => 'test_client_id_123',
        'client_secret' => 'test_client_secret_456',
        'tenant_id'     => 'common',
        'refresh_token' => 'mock_refresh_token_789',
        'root_folder'   => 'STI_Activity_System_Documents',
        'enabled'       => true,
    ], $mockHttpClient);
}

/**
 * Helper to build OneDriveService with queued mock HTTP responses.
 */
function createMockOneDriveService(array $queue, ?OneDriveAuthService $authService = null, array &$historyContainer = []): OneDriveService {
    $mock = new MockHandler($queue);
    $handlerStack = HandlerStack::create($mock);

    // Track requests for header/body assertions
    $handlerStack->push(\GuzzleHttp\Middleware::history($historyContainer));

    $client = new Client(['handler' => $handlerStack]);
    $auth = $authService ?? createMockAuthService(true);

    return new OneDriveService($auth, $client);
}

// -------------------------------------------------------------------------
// SECTION 1: Token Acquisition & Service Initialization
// -------------------------------------------------------------------------
echo "--- 1. Service Initialization & Token Acquisition Tests ---\n";

$authSvc = createMockAuthService(true);
$svc1 = new OneDriveService($authSvc);

assertTest("1.1 OneDriveService instantiates successfully", $svc1 instanceof OneDriveService);
assertTest("1.2 isEnabled() delegates to OneDriveAuthService", $svc1->isEnabled() === true);
assertTest("1.3 getRootFolderName() returns default root folder", $svc1->getRootFolderName() === 'STI_Activity_System_Documents');

// Test failure when auth service fails to acquire token
$failingAuthSvc = createMockAuthService(false);
$svcFailingAuth = new OneDriveService($failingAuthSvc);
$failRootRes = $svcFailingAuth->ensureRootFolder();

assertTest("1.4 Failed token acquisition fails gracefully with success=false", $failRootRes['success'] === false);
assertTest("1.5 Error message describes authentication failure cleanly",
    str_contains($failRootRes['error'] ?? '', 'invalid_grant') || str_contains($failRootRes['error'] ?? '', 'Failed')
);

// -------------------------------------------------------------------------
// SECTION 2: Root Folder Creation & Reuse
// -------------------------------------------------------------------------
echo "\n--- 2. Root Folder Creation & Reuse Tests ---\n";

// Case 2.1: Root folder already exists (GET returns 200 OK)
$history21 = [];
$mockResponses21 = [
    new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'id'     => 'existing_root_folder_id_001',
        'name'   => 'STI_Activity_System_Documents',
        'webUrl' => 'https://onedrive.live.com/view?id=001',
        'folder' => ['childCount' => 3],
    ])),
];
$svc21 = createMockOneDriveService($mockResponses21, null, $history21);
$rootRes21 = $svc21->ensureRootFolder();

assertTest("2.1 Existing root folder returns success=true", $rootRes21['success'] === true);
assertTest("2.2 Correct existing folder_id returned", ($rootRes21['folder_id'] ?? '') === 'existing_root_folder_id_001');
assertTest("2.3 Flag 'created' is false for existing folder", ($rootRes21['created'] ?? null) === false);
assertTest("2.4 Request includes Authorization Bearer header",
    isset($history21[0]['request']) && $history21[0]['request']->hasHeader('Authorization') && str_contains($history21[0]['request']->getHeaderLine('Authorization'), 'Bearer mock_bearer_token')
);

// Case 2.2: Root folder does not exist yet (GET returns 404, then POST returns 201 Created)
$history22 = [];
$mockResponses22 = [
    // 1. GET check returns 404 Not Found
    new Response(404, ['Content-Type' => 'application/json'], json_encode([
        'error' => ['code' => 'itemNotFound', 'message' => 'The resource could not be found.'],
    ])),
    // 2. POST create returns 201 Created
    new Response(201, ['Content-Type' => 'application/json'], json_encode([
        'id'     => 'newly_created_root_id_002',
        'name'   => 'STI_Activity_System_Documents',
        'webUrl' => 'https://onedrive.live.com/view?id=002',
        'folder' => ['childCount' => 0],
    ])),
];
$svc22 = createMockOneDriveService($mockResponses22, null, $history22);
$rootRes22 = $svc22->ensureRootFolder();

assertTest("2.5 Newly created root folder returns success=true", $rootRes22['success'] === true);
assertTest("2.6 Correct new folder_id returned", ($rootRes22['folder_id'] ?? '') === 'newly_created_root_id_002');
assertTest("2.7 Flag 'created' is true for newly created folder", ($rootRes22['created'] ?? null) === true);
assertTest("2.8 POST request creates folder under /me/drive/root/children",
    isset($history22[1]['request']) && $history22[1]['request']->getMethod() === 'POST' && str_contains((string)$history22[1]['request']->getUri(), '/me/drive/root/children')
);

// -------------------------------------------------------------------------
// SECTION 3: Folder & Nested Folder Management
// -------------------------------------------------------------------------
echo "\n--- 3. Nested Folder Handling Tests ---\n";

// Case 3.1: Create a single folder under parent
$history31 = [];
$mockResponses31 = [
    new Response(201, ['Content-Type' => 'application/json'], json_encode([
        'id'              => 'folder_posters_id_111',
        'name'            => 'posters',
        'webUrl'          => 'https://onedrive.live.com/view?id=111',
        'parentReference' => ['id' => 'parent_id_999'],
    ])),
];
$svc31 = createMockOneDriveService($mockResponses31, null, $history31);
$createRes31 = $svc31->createFolder('posters', 'parent_id_999');

assertTest("3.1 createFolder returns success=true", $createRes31['success'] === true);
assertTest("3.2 Created folder has correct ID and name",
    ($createRes31['folder_id'] ?? '') === 'folder_posters_id_111' && ($createRes31['name'] ?? '') === 'posters'
);
assertTest("3.3 POST request targets children endpoint of parent",
    isset($history31[0]['request']) && str_contains((string)$history31[0]['request']->getUri(), '/items/parent_id_999/children')
);

// Case 3.2: 409 Conflict handled (folder already exists)
$mockResponses32 = [
    new Response(409, ['Content-Type' => 'application/json'], json_encode([
        'error' => ['code' => 'nameAlreadyExists', 'message' => 'An item with the same name already exists.'],
    ])),
    // Follow-up GET to retrieve existing
    new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'id'   => 'existing_folder_id_222',
        'name' => 'posters',
    ])),
];
$svc32 = createMockOneDriveService($mockResponses32);
$createRes32 = $svc32->createFolder('posters', 'parent_id_999');

assertTest("3.4 409 conflict recovers and returns existing folder ID",
    $createRes32['success'] === true && ($createRes32['folder_id'] ?? '') === 'existing_folder_id_222'
);

// Case 3.3: Traversal of nested folder path "post-event/2026/reports"
$mockResponses33 = [
    // 1. check "post-event" under base -> found
    new Response(200, ['Content-Type' => 'application/json'], json_encode(['id' => 'id_post_event', 'name' => 'post-event'])),
    // 2. check "2026" under "post-event" -> 404 not found
    new Response(404, ['Content-Type' => 'application/json'], json_encode(['error' => ['code' => 'itemNotFound']])),
    // 3. create "2026" -> 201 created
    new Response(201, ['Content-Type' => 'application/json'], json_encode(['id' => 'id_2026', 'name' => '2026'])),
    // 4. check "reports" under "2026" -> 404 not found
    new Response(404, ['Content-Type' => 'application/json'], json_encode(['error' => ['code' => 'itemNotFound']])),
    // 5. create "reports" -> 201 created
    new Response(201, ['Content-Type' => 'application/json'], json_encode(['id' => 'id_reports', 'name' => 'reports'])),
];
$svc33 = createMockOneDriveService($mockResponses33);
$nestedRes = $svc33->ensureNestedFolder('post-event/2026/reports', 'base_root_id');

assertTest("3.5 ensureNestedFolder returns success=true for multi-segment path", $nestedRes['success'] === true);
assertTest("3.6 Final leaf folder ID returned", ($nestedRes['folder_id'] ?? '') === 'id_reports');
assertTest("3.7 Clean relative path preserved", ($nestedRes['path'] ?? '') === 'post-event/2026/reports');

// Case 3.4: Empty relative path returns base folder
$emptyNestedRes = $svc33->ensureNestedFolder('', 'base_root_id');
assertTest("3.8 Empty nested path returns base folder ID",
    $emptyNestedRes['success'] === true && ($emptyNestedRes['folder_id'] ?? '') === 'base_root_id'
);

// -------------------------------------------------------------------------
// SECTION 4: File Upload & Metadata
// -------------------------------------------------------------------------
echo "\n--- 4. File Upload & Metadata Extraction Tests ---\n";

// Create a local test fixture file
$tempLocalFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_onedrive_upload_' . uniqid() . '.pdf';
$fileContent = "%PDF-1.4 Mock PDF binary content for testing OneDrive upload functionality";
file_put_contents($tempLocalFile, $fileContent);

$history41 = [];
$mockResponses41 = [
    new Response(201, ['Content-Type' => 'application/json'], json_encode([
        'id'                   => 'drive_item_file_9999',
        'name'                 => 'Proposal_Document.pdf',
        'size'                 => strlen($fileContent),
        'webUrl'               => 'https://onedrive.live.com/view?id=9999',
        'createdDateTime'      => '2026-09-22T08:00:00Z',
        'lastModifiedDateTime' => '2026-09-22T08:00:00Z',
        'file'                 => ['mimeType' => 'application/pdf'],
        'parentReference'      => ['id' => 'dest_folder_777'],
    ])),
];
$svc41 = createMockOneDriveService($mockResponses41, null, $history41);
$uploadRes = $svc41->uploadFile($tempLocalFile, 'Proposal_Document.pdf', 'dest_folder_777');

assertTest("4.1 uploadFile succeeds with 201 Created", $uploadRes['success'] === true);
assertTest("4.2 Correct OneDrive item_id returned", ($uploadRes['item_id'] ?? '') === 'drive_item_file_9999');
assertTest("4.3 Correct file name returned", ($uploadRes['name'] ?? '') === 'Proposal_Document.pdf');
assertTest("4.4 Correct size extracted", ($uploadRes['size'] ?? 0) === strlen($fileContent));
assertTest("4.5 Web URL returned", str_contains($uploadRes['web_url'] ?? '', 'https://onedrive.live.com'));
assertTest("4.6 MIME type extracted", ($uploadRes['mime_type'] ?? '') === 'application/pdf');
assertTest("4.7 Parent folder ID matches destination", ($uploadRes['parent_id'] ?? '') === 'dest_folder_777');

// Verify HTTP request specifics
assertTest("4.8 PUT request targets item content endpoint",
    isset($history41[0]['request']) && $history41[0]['request']->getMethod() === 'PUT' && str_contains((string)$history41[0]['request']->getUri(), ':/Proposal_Document.pdf:/content')
);
assertTest("4.9 PUT request carries file content stream",
    isset($history41[0]['request']) && (string)$history41[0]['request']->getBody() === $fileContent
);

// Case 4.2: Non-existent local file
$missingRes = $svc41->uploadFile('C:/non_existent_file_path_' . uniqid() . '.pdf');
assertTest("4.10 Uploading non-existent local file returns error without calling API",
    $missingRes['success'] === false && str_contains($missingRes['error'] ?? '', 'does not exist')
);

// Cleanup local fixture
@unlink($tempLocalFile);

// -------------------------------------------------------------------------
// SECTION 5: Microsoft Graph API Error Resilience
// -------------------------------------------------------------------------
echo "\n--- 5. Microsoft Graph API Failure Resilience Tests ---\n";

// Fixture file for failure testing
$errFixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'err_fixture_' . uniqid() . '.txt';
file_put_contents($errFixture, 'sample content');

// Case 5.1: 401 Unauthorized (invalid/expired bearer token)
$svc401 = createMockOneDriveService([
    new Response(401, ['Content-Type' => 'application/json'], json_encode([
        'error' => ['code' => 'InvalidAuthenticationToken', 'message' => 'Access token has expired or is invalid.'],
    ])),
]);
$res401 = $svc401->uploadFile($errFixture, 'test.txt', 'parent_1');
assertTest("5.1 401 Unauthorized returns success=false", $res401['success'] === false);
assertTest("5.2 401 error message cleanly formatted without token leak",
    str_contains($res401['error'] ?? '', 'InvalidAuthenticationToken') && !str_contains($res401['error'] ?? '', 'Bearer')
);

// Case 5.2: 403 Forbidden (insufficient permissions / quota)
$svc403 = createMockOneDriveService([
    new Response(403, ['Content-Type' => 'application/json'], json_encode([
        'error' => ['code' => 'accessDenied', 'message' => 'The caller does not have permission to perform the action.'],
    ])),
]);
$res403 = $svc403->uploadFile($errFixture, 'test.txt', 'parent_1');
assertTest("5.3 403 Forbidden returns success=false with clean error",
    $res403['success'] === false && str_contains($res403['error'] ?? '', 'accessDenied')
);

// Case 5.3: 500 Internal Server Error
$svc500 = createMockOneDriveService([
    new Response(500, ['Content-Type' => 'application/json'], json_encode([
        'error' => ['code' => 'generalException', 'message' => 'An unexpected server error occurred on Microsoft Graph.'],
    ])),
]);
$res500 = $svc500->uploadFile($errFixture, 'test.txt', 'parent_1');
assertTest("5.4 500 Server Error handled gracefully without crash",
    $res500['success'] === false && str_contains($res500['error'] ?? '', 'generalException')
);

// Case 5.4: Network / Connection Exception
$svcNet = createMockOneDriveService([
    new ConnectException('Connection timed out connecting to graph.microsoft.com', new Request('PUT', 'https://graph.microsoft.com/v1.0/test')),
]);
$resNet = $svcNet->uploadFile($errFixture, 'test.txt', 'parent_1');
assertTest("5.5 Network connection timeout handled gracefully",
    $resNet['success'] === false && str_contains($resNet['error'] ?? '', 'timed out')
);

// Cleanup
@unlink($errFixture);

// -------------------------------------------------------------------------
// SECTION 6: Malformed / Unexpected Graph Responses
// -------------------------------------------------------------------------
echo "\n--- 6. Malformed & Unexpected Responses Tests ---\n";

$fixture6 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'malformed_fixture_' . uniqid() . '.txt';
file_put_contents($fixture6, 'sample text');

// Case 6.1: Non-JSON body on error (e.g. HTML 502 Bad Gateway)
$svcMalformed = createMockOneDriveService([
    new Response(502, ['Content-Type' => 'text/html'], '<html><body>502 Bad Gateway</body></html>'),
]);
$resMalformed = $svcMalformed->uploadFile($fixture6, 'test.txt', 'parent_1');
assertTest("6.1 HTML 502 Bad Gateway handled cleanly without JSON parse error",
    $resMalformed['success'] === false && str_contains($resMalformed['error'] ?? '', '502')
);

// Case 6.2: 200 OK but missing expected 'id' field in JSON
$svcMissingId = createMockOneDriveService([
    new Response(200, ['Content-Type' => 'application/json'], json_encode(['unexpected_key' => 'unexpected_val'])),
]);
$resMissingId = $svcMissingId->uploadFile($fixture6, 'test.txt', 'parent_1');
assertTest("6.2 200 response missing 'id' returns success=false",
    $resMissingId['success'] === false
);

@unlink($fixture6);

// -------------------------------------------------------------------------
// SECTION 7: Zero Leakage Verification
// -------------------------------------------------------------------------
echo "\n--- 7. Zero Credential & Payload Leakage Tests ---\n";

$secretToken = 'super_secret_bearer_token_should_never_leak_in_errors';
$authLeakTest = createMockAuthService(true, $secretToken);

$svcLeak = createMockOneDriveService([
    new Response(400, ['Content-Type' => 'application/json'], json_encode([
        'error' => ['code' => 'invalidRequest', 'message' => 'Parameter is invalid.'],
    ])),
], $authLeakTest);

$resLeak = $svcLeak->ensureRootFolder();
assertTest("7.1 Bearer access token is NEVER included in error messages",
    !str_contains($resLeak['error'] ?? '', $secretToken),
    "Token leaked in error message!"
);

$oneDriveConf = getOneDriveConfig();
if (!empty($oneDriveConf['client_secret'])) {
    assertTest("7.2 Client secret is NEVER included in error messages",
        !str_contains($resLeak['error'] ?? '', $oneDriveConf['client_secret']),
        "Client secret leaked in error message!"
    );
}

echo "\n========================================================================\n";
echo " VERIFICATION SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================================\n";

exit($failed > 0 ? 1 : 0);
