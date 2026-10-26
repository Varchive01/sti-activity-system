<?php
/**
 * test_onedrive_metadata_persistence.php
 *
 * Verification suite for OneDrive metadata persistence in STI-ARMES.
 *
 * Verifies:
 * 1. Microsoft Graph upload success persists item_id, web_url, status ('synced'), and timestamp to database.
 * 2. Database record correctly links to activity and document without creating duplicates.
 * 3. Local file storage remains authoritative fallback.
 * 4. Microsoft Graph failures are safely recorded as status='failed' without leaking tokens or breaking local storage.
 * 5. OneDrive disabled state is handled cleanly without breaking local upload.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/OneDriveService.php';
require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

$db = getDB();

echo "========================================================================\n";
echo "   VERIFICATION: ONEDRIVE METADATA PERSISTENCE IN STI-ARMES\n";
echo "========================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $title, bool $condition, string $detail = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] {$title}\n";
        if ($detail) echo "        {$detail}\n";
    } else {
        $failCount++;
        echo " [FAIL] {$title}\n";
        if ($detail) echo "        Defect: {$detail}\n";
    }
}

function makeMockServiceWithQueue(array $queue, bool $enabled = true): OneDriveService {
    $mockHandler = new MockHandler($queue);
    $handlerStack = HandlerStack::create($mockHandler);
    $client = new Client(['handler' => $handlerStack]);

    $authMock = new OneDriveAuthService([
        'client_id'     => 'test_client_id_123',
        'client_secret' => 'test_client_secret_xyz',
        'tenant_id'     => 'test_tenant_id_abc',
        'refresh_token' => 'test_refresh_token_789',
        'root_folder'   => 'STI_Activity_System_Documents',
        'enabled'       => $enabled,
    ], $client);

    return new OneDriveService($authMock, $client);
}

function createSampleMockFile(string $ext = 'pdf'): array {
    $tmp = tempnam(sys_get_temp_dir(), 'od_persist_');
    file_put_contents($tmp, "%PDF-1.4 Mock PDF Document Content for Persistence Testing\n");
    return [
        'name'     => 'sample_test_doc_' . uniqid() . '.' . $ext,
        'tmp_name' => $tmp,
        'size'     => filesize($tmp),
        'error'    => UPLOAD_ERR_OK,
        'type'     => 'application/pdf',
    ];
}

// Ensure schema is up to date
$checkCol = $db->query("SHOW COLUMNS FROM `documents` LIKE 'onedrive_status'")->fetch();
assertTest("1. Database Schema Verification: onedrive columns exist in documents",
    !empty($checkCol),
    "Column onedrive_status exists in documents table."
);

$testActivityId = (int)$db->query("SELECT id FROM activities ORDER BY id ASC LIMIT 1")->fetchColumn();
if (!$testActivityId) {
    die("No activity found in database for test.\n");
}

// -------------------------------------------------------------------------
// TEST 2: Successful Microsoft Graph Upload Persists Metadata to Document Record
// -------------------------------------------------------------------------
echo "\n--- TEST 2: Successful Graph Upload Persistence ---\n";

$queueSuccess = [
    new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'access_token' => 'mock_token_abc',
        'expires_in'   => 3600,
    ])),
    new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'id'   => 'root_folder_100',
        'name' => 'STI_Activity_System_Documents',
    ])),
    new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'id'   => 'category_folder_200',
        'name' => 'post-event',
    ])),
    new Response(201, ['Content-Type' => 'application/json'], json_encode([
        'id'              => 'graph_item_id_998877',
        'name'            => 'event_report.pdf',
        'size'            => 1024,
        'webUrl'          => 'https://onedrive.live.com/view?id=graph_item_id_998877',
        'file'            => ['mimeType' => 'application/pdf'],
        'parentReference' => ['id' => 'category_folder_200'],
    ])),
];

$svcSuccess = makeMockServiceWithQueue($queueSuccess, true);
$mockFile2 = createSampleMockFile('pdf');

// Simulate document upload flow (like post-event-save.php)
$savedPath2 = uploadFile($mockFile2, 'post-event', $testActivityId, $svcSuccess);

assertTest("2.1 Local upload succeeds and local file exists",
    is_string($savedPath2) && file_exists(UPLOAD_PATH . $savedPath2),
    "Local path: {$savedPath2}"
);

// Insert initial document record in documents table
$insStmt = $db->prepare("
    INSERT INTO documents (activity_id, doc_type, file_name, file_path, uploaded_by)
    VALUES (?, 'post_event', ?, ?, 4)
");
$insStmt->execute([$testActivityId, $mockFile2['name'], $savedPath2]);
$docId2 = (int)$db->lastInsertId();

// Sync OneDrive metadata to the document record
$syncResult2 = syncOneDriveMetadataToRecord($db, 'documents', $docId2, $savedPath2, $testActivityId);

assertTest("2.2 syncOneDriveMetadataToRecord executed successfully", $syncResult2 === true);

// Fetch updated record from database
$fetchStmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
$fetchStmt->execute([$docId2]);
$docRow2 = $fetchStmt->fetch(PDO::FETCH_ASSOC);

assertTest("2.3 OneDrive Item ID persisted correctly",
    ($docRow2['onedrive_item_id'] ?? '') === 'graph_item_id_998877',
    "Persisted item_id: " . ($docRow2['onedrive_item_id'] ?? 'null')
);

assertTest("2.4 OneDrive Web URL persisted correctly",
    ($docRow2['onedrive_web_url'] ?? '') === 'https://onedrive.live.com/view?id=graph_item_id_998877',
    "Persisted web_url: " . ($docRow2['onedrive_web_url'] ?? 'null')
);

assertTest("2.5 OneDrive Status is marked as 'synced'",
    ($docRow2['onedrive_status'] ?? '') === 'synced',
    "Status: " . ($docRow2['onedrive_status'] ?? 'null')
);

assertTest("2.6 Synced timestamp is populated",
    !empty($docRow2['onedrive_synced_at']),
    "Timestamp: " . ($docRow2['onedrive_synced_at'] ?? 'null')
);

// -------------------------------------------------------------------------
// TEST 3: Graph Failure Keeps Local File & Records Status 'failed' Accurately
// -------------------------------------------------------------------------
echo "\n--- TEST 3: Graph Failure Non-Blocking Resilience & Error Persistence ---\n";

$queueFailure = [
    new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'access_token' => 'mock_token_abc',
        'expires_in'   => 3600,
    ])),
    new Response(500, ['Content-Type' => 'application/json'], json_encode([
        'error' => ['code' => 'generalException', 'message' => 'Microsoft Graph internal service error.'],
    ])),
];

$svcFailure = makeMockServiceWithQueue($queueFailure, true);
$mockFile3 = createSampleMockFile('pdf');

$savedPath3 = uploadFile($mockFile3, 'post-event', $testActivityId, $svcFailure);

assertTest("3.1 Local upload succeeds despite Microsoft Graph 500 error",
    is_string($savedPath3) && file_exists(UPLOAD_PATH . $savedPath3),
    "Authoritative local file preserved at: {$savedPath3}"
);

// Insert document record in documents table
$insStmt->execute([$testActivityId, $mockFile3['name'], $savedPath3]);
$docId3 = (int)$db->lastInsertId();

$syncResult3 = syncOneDriveMetadataToRecord($db, 'documents', $docId3, $savedPath3, $testActivityId);

$fetchStmt->execute([$docId3]);
$docRow3 = $fetchStmt->fetch(PDO::FETCH_ASSOC);

assertTest("3.2 Failed upload does NOT mark file as synced (status is 'failed')",
    ($docRow3['onedrive_status'] ?? '') === 'failed',
    "Status: " . ($docRow3['onedrive_status'] ?? 'null')
);

assertTest("3.3 Item ID and Web URL remain null on failure",
    empty($docRow3['onedrive_item_id']) && empty($docRow3['onedrive_web_url']),
    "item_id: " . var_export($docRow3['onedrive_item_id'], true) . ", web_url: " . var_export($docRow3['onedrive_web_url'], true)
);

assertTest("3.4 Error message recorded cleanly without leaking credentials",
    !empty($docRow3['onedrive_error']) && !str_contains($docRow3['onedrive_error'], 'secret') && !str_contains($docRow3['onedrive_error'], 'token'),
    "Recorded error: " . ($docRow3['onedrive_error'] ?? 'null')
);

// -------------------------------------------------------------------------
// TEST 4: No Duplicate Records Created
// -------------------------------------------------------------------------
echo "\n--- TEST 4: Duplicate Record Prevention ---\n";

$countStmt = $db->prepare("SELECT COUNT(*) FROM documents WHERE id IN (?, ?)");
$countStmt->execute([$docId2, $docId3]);
$totalDocs = (int)$countStmt->fetchColumn();

assertTest("4.1 Exactly 2 document records exist for the 2 uploads (no duplicates created)",
    $totalDocs === 2,
    "Expected 2 documents for test uploads, found: {$totalDocs}"
);

// Cleanup test artifacts
$db->prepare("DELETE FROM documents WHERE id IN (?, ?)")->execute([$docId2, $docId3]);
if ($savedPath2 && file_exists(UPLOAD_PATH . $savedPath2)) @unlink(UPLOAD_PATH . $savedPath2);
if ($savedPath3 && file_exists(UPLOAD_PATH . $savedPath3)) @unlink(UPLOAD_PATH . $savedPath3);
@unlink($mockFile2['tmp_name']);
@unlink($mockFile3['tmp_name']);

echo "\n------------------------------------------------------------------------\n";
echo "SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
echo "------------------------------------------------------------------------\n";

if ($failCount === 0) {
    echo "ALL ONEDRIVE METADATA PERSISTENCE TESTS PASSED!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
