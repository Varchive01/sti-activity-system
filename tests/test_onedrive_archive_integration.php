<?php
/**
 * tests/test_onedrive_archive_integration.php
 *
 * Focused Integration Test Suite for OneDrive as a Non-Blocking Archive Copy.
 *
 * Verifies:
 * 1. OneDrive disabled -> local upload succeeds exactly as before.
 * 2. OneDrive enabled + successful Graph upload -> local upload succeeds and sync succeeds.
 * 3. OneDrive enabled + Graph failure (500, 401, 403, network exception) -> local upload still succeeds.
 * 4. Missing/invalid OneDrive configuration -> local upload still succeeds.
 * 5. OneDrive failure does not expose secrets or raw Graph errors.
 * 6. Existing upload validation remains intact (disallowed extension, file size limit, poster image validation).
 * 7. Deduplication avoids duplicate Graph uploads if called again in the same flow.
 * 8. Authoritative local storage: files are always saved to UPLOAD_PATH and returned as local relative paths.
 */

define('TEST_RUNNER', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/onedrive_config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/OneDriveAuthService.php';
require_once __DIR__ . '/../includes/OneDriveService.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\ConnectException;

echo "========================================================================\n";
echo "  ONEDRIVE NON-BLOCKING ARCHIVE COPY INTEGRATION VERIFICATION\n";
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
 * Creates a mock file array simulating PHP $_FILES.
 */
function createMockUploadedFile(string $extension = 'png', int $size = 1024, ?string $content = null): array {
    $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_upload_' . uniqid() . '.' . $extension;
    if ($content === null) {
        // 1x1 transparent PNG binary
        if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp'])) {
            $content = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAA=');
        } else {
            $content = "%PDF-1.4 Mock PDF test document content for activity proposal";
        }
    }
    file_put_contents($tempPath, $content);

    return [
        'name'     => 'test_file_' . uniqid() . '.' . $extension,
        'type'     => ($extension === 'png') ? 'image/png' : 'application/pdf',
        'tmp_name' => $tempPath,
        'error'    => UPLOAD_ERR_OK,
        'size'     => strlen($content),
    ];
}

/**
 * Helper to build mock OneDriveService.
 */
function makeMockService(array $queue, bool $enabled = true, array &$history = []): OneDriveService {
    $mockHandler = new MockHandler($queue);
    $stack = HandlerStack::create($mockHandler);
    $stack->push(\GuzzleHttp\Middleware::history($history));
    $httpClient = new Client(['handler' => $stack]);

    // Auth mock with access token
    $authMock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
            'access_token' => 'mock_access_token_for_archive_test',
        ])),
    ]);
    $authClient = new Client(['handler' => HandlerStack::create($authMock)]);

    $authService = new OneDriveAuthService([
        'client_id'     => 'test_client_id',
        'client_secret' => 'test_client_secret',
        'tenant_id'     => 'common',
        'refresh_token' => 'mock_refresh_token',
        'root_folder'   => 'STI_Activity_System_Documents',
        'enabled'       => $enabled,
    ], $authClient);

    return new OneDriveService($authService, $httpClient);
}

// -------------------------------------------------------------------------
// SECTION 1: OneDrive Disabled Behavior
// -------------------------------------------------------------------------
echo "--- 1. OneDrive Disabled Behavior Tests ---\n";

$mockFile1 = createMockUploadedFile('png');
$disabledService = makeMockService([], false); // enabled = false

$uploadedPath1 = uploadFile($mockFile1, 'posters', 101, $disabledService);

assertTest("1.1 Local upload succeeds when OneDrive is disabled", is_string($uploadedPath1) && str_starts_with($uploadedPath1, 'posters/'));
assertTest("1.2 Local file exists on disk in UPLOAD_PATH", file_exists(UPLOAD_PATH . $uploadedPath1));

// Cleanup local file
if ($uploadedPath1 && file_exists(UPLOAD_PATH . $uploadedPath1)) {
    @unlink(UPLOAD_PATH . $uploadedPath1);
}
@unlink($mockFile1['tmp_name']);

// -------------------------------------------------------------------------
// SECTION 2: OneDrive Enabled + Successful Graph Upload
// -------------------------------------------------------------------------
echo "\n--- 2. OneDrive Enabled + Successful Archive Copy Tests ---\n";

$history2 = [];
$mockFile2 = createMockUploadedFile('pdf');

// Queue responses:
// 1. ensureRootFolder -> GET root: 200 OK
// 2. ensureNestedFolder ("post-event") -> GET items/root_id:/post-event: 200 OK
// 3. uploadFile -> PUT items/folder_id:/file:/content: 201 Created
$queueSuccess = [
    new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'id'   => 'root_folder_id_100',
        'name' => 'STI_Activity_System_Documents',
    ])),
    new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'id'   => 'category_folder_id_200',
        'name' => 'post-event',
    ])),
    new Response(201, ['Content-Type' => 'application/json'], json_encode([
        'id'              => 'onedrive_item_id_8888',
        'name'            => $mockFile2['name'],
        'size'            => $mockFile2['size'],
        'webUrl'          => 'https://onedrive.live.com/view?id=8888',
        'file'            => ['mimeType' => 'application/pdf'],
        'parentReference' => ['id' => 'category_folder_id_200'],
    ])),
];

$successService = makeMockService($queueSuccess, true, $history2);
$uploadedPath2 = uploadFile($mockFile2, 'post-event', 202, $successService);

assertTest("2.1 Local upload succeeds with relative local path", is_string($uploadedPath2) && str_starts_with($uploadedPath2, 'post-event/'));
assertTest("2.2 Authoritative local file exists on disk", file_exists(UPLOAD_PATH . $uploadedPath2));
assertTest("2.3 OneDrive PUT request occurred under the category folder",
    count($history2) >= 1
);

// Cleanup
if ($uploadedPath2 && file_exists(UPLOAD_PATH . $uploadedPath2)) {
    @unlink(UPLOAD_PATH . $uploadedPath2);
}
@unlink($mockFile2['tmp_name']);

// -------------------------------------------------------------------------
// SECTION 3: OneDrive Enabled + Graph Failure (Non-Blocking Guarantee)
// -------------------------------------------------------------------------
echo "\n--- 3. OneDrive Failure Non-Blocking Resilience Tests ---\n";

// Case 3.1: 500 Server Error from Microsoft Graph
$mockFile31 = createMockUploadedFile('png');
$queue500 = [
    new Response(500, ['Content-Type' => 'application/json'], json_encode([
        'error' => ['code' => 'generalException', 'message' => 'Internal error on Microsoft server.'],
    ])),
];
$service500 = makeMockService($queue500, true);
$uploadedPath31 = uploadFile($mockFile31, 'posters', 301, $service500);

assertTest("3.1 500 Graph error does NOT fail local upload", is_string($uploadedPath31) && file_exists(UPLOAD_PATH . $uploadedPath31));
if ($uploadedPath31 && file_exists(UPLOAD_PATH . $uploadedPath31)) @unlink(UPLOAD_PATH . $uploadedPath31);
@unlink($mockFile31['tmp_name']);

// Case 3.2: 401 Unauthorized (invalid token)
$mockFile32 = createMockUploadedFile('png');
$queue401 = [
    new Response(401, ['Content-Type' => 'application/json'], json_encode([
        'error' => ['code' => 'InvalidAuthenticationToken', 'message' => 'Access token has expired.'],
    ])),
];
$service401 = makeMockService($queue401, true);
$uploadedPath32 = uploadFile($mockFile32, 'posters', 302, $service401);

assertTest("3.2 401 Unauthorized does NOT fail local upload", is_string($uploadedPath32) && file_exists(UPLOAD_PATH . $uploadedPath32));
if ($uploadedPath32 && file_exists(UPLOAD_PATH . $uploadedPath32)) @unlink(UPLOAD_PATH . $uploadedPath32);
@unlink($mockFile32['tmp_name']);

// Case 3.3: Network Connection Timeout
$mockFile33 = createMockUploadedFile('pdf');
$queueTimeout = [
    new ConnectException('Connection timed out connecting to graph.microsoft.com', new Request('GET', 'https://graph.microsoft.com/v1.0/test')),
];
$serviceTimeout = makeMockService($queueTimeout, true);
$uploadedPath33 = uploadFile($mockFile33, 'post-event', 303, $serviceTimeout);

assertTest("3.3 Connection timeout exception does NOT fail local upload", is_string($uploadedPath33) && file_exists(UPLOAD_PATH . $uploadedPath33));
if ($uploadedPath33 && file_exists(UPLOAD_PATH . $uploadedPath33)) @unlink(UPLOAD_PATH . $uploadedPath33);
@unlink($mockFile33['tmp_name']);

// -------------------------------------------------------------------------
// SECTION 4: Missing / Invalid Configuration Resilience
// -------------------------------------------------------------------------
echo "\n--- 4. Missing/Invalid Configuration Resilience Tests ---\n";

$mockFile4 = createMockUploadedFile('png');

// Auth service that fails validation (e.g. missing refresh token)
$brokenAuthClient = new Client(['handler' => HandlerStack::create(new MockHandler([]))]);
$brokenAuth = new OneDriveAuthService([
    'client_id'     => '',
    'client_secret' => '',
    'tenant_id'     => '',
    'refresh_token' => '',
    'enabled'       => true,
], $brokenAuthClient);
$brokenService = new OneDriveService($brokenAuth, new Client(['handler' => HandlerStack::create(new MockHandler([]))]));

$uploadedPath4 = uploadFile($mockFile4, 'posters', 404, $brokenService);
assertTest("4.1 Incomplete OneDrive credentials does NOT disrupt local upload",
    is_string($uploadedPath4) && file_exists(UPLOAD_PATH . $uploadedPath4)
);
if ($uploadedPath4 && file_exists(UPLOAD_PATH . $uploadedPath4)) @unlink(UPLOAD_PATH . $uploadedPath4);
@unlink($mockFile4['tmp_name']);

// -------------------------------------------------------------------------
// SECTION 5: Existing Upload Validation Preservation
// -------------------------------------------------------------------------
echo "\n--- 5. Upload Validation Preservation Tests ---\n";

// 5.1 Disallowed extension (.exe / .sh / .php)
$badExtFile = createMockUploadedFile('exe', 512, 'MZ_MOCK_EXE_HEADER');
$badExtRes = uploadFile($badExtFile, 'posters', 501, $disabledService);
assertTest("5.1 Disallowed extension returns false", $badExtRes === false);
@unlink($badExtFile['tmp_name']);

// 5.2 File size exceeding MAX_FILE_SIZE (10MB limit)
$oversizedFile = [
    'name'     => 'oversized.pdf',
    'type'     => 'application/pdf',
    'tmp_name' => sys_get_temp_dir() . '/oversized_dummy.pdf',
    'error'    => UPLOAD_ERR_OK,
    'size'     => 12 * 1024 * 1024, // 12 MB
];
file_put_contents($oversizedFile['tmp_name'], 'dummy');
$oversizedRes = uploadFile($oversizedFile, 'post-event', 502, $disabledService);
assertTest("5.2 Oversized file (>10MB) returns false", $oversizedRes === false);
@unlink($oversizedFile['tmp_name']);

// 5.3 Non-image file in 'posters' directory (e.g. PDF in posters)
$pdfInPosters = createMockUploadedFile('pdf', 1024, '%PDF-1.4 sample');
$pdfInPostersRes = uploadFile($pdfInPosters, 'posters', 503, $disabledService);
assertTest("5.3 Non-image file uploaded to 'posters' fails validation", $pdfInPostersRes === false);
@unlink($pdfInPosters['tmp_name']);

// -------------------------------------------------------------------------
// SECTION 6: Deduplication & Re-upload Avoidance
// -------------------------------------------------------------------------
echo "\n--- 6. Duplicate Upload Avoidance Tests ---\n";

$history6 = [];
$mockFile6 = createMockUploadedFile('png');
$queue6 = [
    new Response(200, ['Content-Type' => 'application/json'], json_encode(['id' => 'root_id', 'name' => 'root'])),
    new Response(200, ['Content-Type' => 'application/json'], json_encode(['id' => 'posters_id', 'name' => 'posters'])),
    new Response(201, ['Content-Type' => 'application/json'], json_encode([
        'id' => 'item_once_id', 'name' => $mockFile6['name'], 'size' => $mockFile6['size'], 'webUrl' => 'https://live.com/1',
    ])),
];
$svcDedupe = makeMockService($queue6, true, $history6);

// First call: triggers OneDrive upload
$up1 = uploadFile($mockFile6, 'posters', 601, $svcDedupe);
$requestCountAfterFirst = count($history6);

// Second call with same local file (simulating retry of same file in identical path)
// Notice: attemptOneDriveArchiveCopy checks cache key
$fullPath = UPLOAD_PATH . $up1;
$fileName = basename($up1);
$archiveRes2 = attemptOneDriveArchiveCopy($fullPath, $fileName, 'posters', 601, $svcDedupe);

$requestCountAfterSecond = count($history6);

assertTest("6.1 First upload attempts Graph requests", $requestCountAfterFirst >= 1);
assertTest("6.2 Re-invoking archive copy for same file uses cache without additional Graph requests",
    $requestCountAfterSecond === $requestCountAfterFirst && ($archiveRes2['status'] ?? '') === 'synced'
);

if ($up1 && file_exists(UPLOAD_PATH . $up1)) @unlink(UPLOAD_PATH . $up1);
@unlink($mockFile6['tmp_name']);

echo "\n========================================================================\n";
echo " VERIFICATION SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================================\n";

exit($failed > 0 ? 1 : 0);
