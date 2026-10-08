<?php
/**
 * Test Suite: Uploads Directory Protection Verification
 *
 * Requirements:
 * 1. Unauthenticated direct request to /uploads/ must not expose a directory listing (HTTP 403).
 * 2. Unauthenticated direct request to a known uploaded file must be denied (HTTP 403).
 * 3. Existing api/document-download.php access for an authorized user must still work.
 * 4. Unauthorized users must remain denied through the protected endpoint.
 * 5. Existing upload functionality must remain intact.
 * 6. No existing uploaded files are deleted.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

$passedTests = 0;
$failedTests = 0;

function assertCheck(string $name, bool $condition, string $details = ""): void {
    global $passedTests, $failedTests;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$name}\n";
    } else {
        $failedTests++;
        echo "  [FAIL] {$name}\n";
        if (!empty($details)) {
            echo "         Details: {$details}\n";
        }
    }
}

echo "============================================================\n";
echo "  Uploads Directory Protection Verification Test\n";
echo "============================================================\n\n";

// -------------------------------------------------------------
// TEST 1: Direct Web Access Blocking on /uploads/ and files
// -------------------------------------------------------------
echo "--- 1. Direct Web Access Blocking ---\n";

function getHttpStatus(string $url): int {
    $headers = @get_headers($url);
    if (!$headers || !isset($headers[0])) {
        return 0;
    }
    if (preg_match('/HTTP\/\d\.\d\s+(\d+)/i', $headers[0], $m)) {
        return (int)$m[1];
    }
    return 0;
}

// 1a. Root of /uploads/
$uploadsDirStatus = getHttpStatus('http://localhost/sti-activity-system/uploads/');
assertCheck(
    "Direct request to /uploads/ returns HTTP 403 Forbidden (no directory index)",
    $uploadsDirStatus === 403,
    "Expected 403, got: {$uploadsDirStatus}"
);

// 1b. Subdirectory /uploads/posters/
$postersDirStatus = getHttpStatus('http://localhost/sti-activity-system/uploads/posters/');
assertCheck(
    "Direct request to /uploads/posters/ returns HTTP 403 Forbidden",
    $postersDirStatus === 403,
    "Expected 403, got: {$postersDirStatus}"
);

// 1c. Subdirectory /uploads/post-event/
$postEventDirStatus = getHttpStatus('http://localhost/sti-activity-system/uploads/post-event/');
assertCheck(
    "Direct request to /uploads/post-event/ returns HTTP 403 Forbidden",
    $postEventDirStatus === 403,
    "Expected 403, got: {$postEventDirStatus}"
);

// 1d. Known uploaded file in posters
$posterFileStatus = getHttpStatus('http://localhost/sti-activity-system/uploads/posters/141_1788762075_6a9e57db62c73.png');
assertCheck(
    "Direct request to uploaded poster file returns HTTP 403 Forbidden",
    $posterFileStatus === 403,
    "Expected 403, got: {$posterFileStatus}"
);

// 1e. Known uploaded file in post-event
$postEventFileStatus = getHttpStatus('http://localhost/sti-activity-system/uploads/post-event/1_1774129869_69bf12cd7aa14.png');
assertCheck(
    "Direct request to uploaded post-event file returns HTTP 403 Forbidden",
    $postEventFileStatus === 403,
    "Expected 403, got: {$postEventFileStatus}"
);

// -------------------------------------------------------------
// TEST 2: Protected Endpoint Delivery (api/document-download.php)
// -------------------------------------------------------------
echo "\n--- 2. Protected Endpoint Delivery (api/document-download.php) ---\n";

// Find test users
$faculty1 = $db->query("SELECT id, name, role FROM users WHERE role = 'faculty' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$faculty2 = $db->query("SELECT id, name, role FROM users WHERE role = 'faculty' AND id != {$faculty1['id']} ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$dean = $db->query("SELECT id, name, role FROM users WHERE role = 'dean' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$fac1Id = (int)$faculty1['id'];
$fac2Id = (int)$faculty2['id'];
$deanId = (int)$dean['id'];

// Create a test activity owned by faculty1
$db->prepare("
    INSERT INTO activities (title, faculty_id, status, event_date, source)
    VALUES ('Protection Test Activity', ?, 'approved', CURDATE(), 'faculty')
")->execute([$fac1Id]);
$testActId = (int)$db->lastInsertId();

// Create test file in uploads/post-event/
$uploadDir = UPLOAD_PATH . 'post-event/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
$testFileName = 'verify_protection_' . time() . '.pdf';
$testFilePath = 'post-event/' . $testFileName;
$testFullLocalPath = $uploadDir . $testFileName;
$testFileContent = "%PDF-1.4 Protected File Content Verification Under .htaccess";
file_put_contents($testFullLocalPath, $testFileContent);

// Insert document record
$db->prepare("
    INSERT INTO documents (activity_id, doc_type, file_name, file_path, uploaded_by)
    VALUES (?, 'post_event', 'Protected_Report.pdf', ?, ?)
")->execute([$testActId, $testFilePath, $fac1Id]);
$testDocId = (int)$db->lastInsertId();

// Helper to invoke api/document-download.php via runner helper
function invokeDownload(?array $sessionData, int $docId): array {
    $runnerPath = escapeshellarg(__DIR__ . '/runner_helper.php');
    $cmd = 'php ' . $runnerPath;

    $descriptors = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];
    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        return ['status' => 500, 'stdout' => '', 'stderr' => ''];
    }

    $payload = json_encode([
        'session' => $sessionData,
        'get'     => ['id' => $docId]
    ]);

    fwrite($pipes[0], $payload);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    return ['status' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
}

try {
    // 2a. Unauthenticated access via endpoint must be blocked
    $unauthRes = invokeDownload(null, $testDocId);
    assertCheck(
        "Unauthenticated access via document-download.php is blocked",
        !str_contains($unauthRes['stdout'], $testFileContent),
        "Unauthenticated user should not receive file contents"
    );

    // 2b. Non-owner faculty access via endpoint must be denied
    $nonOwnerRes = invokeDownload([
        'user_id' => $fac2Id,
        'user_role' => 'faculty',
        'role' => 'faculty'
    ], $testDocId);
    assertCheck(
        "Non-owner faculty access via document-download.php is denied (Forbidden)",
        str_contains($nonOwnerRes['stdout'], 'Forbidden') && !str_contains($nonOwnerRes['stdout'], $testFileContent),
        "Non-owner should receive 403 Forbidden"
    );

    // 2c. Owner faculty access via endpoint MUST succeed
    $ownerRes = invokeDownload([
        'user_id' => $fac1Id,
        'user_role' => 'faculty',
        'role' => 'faculty'
    ], $testDocId);
    assertCheck(
        "Owner faculty successfully downloads file through document-download.php",
        str_contains($ownerRes['stdout'], $testFileContent),
        "Owner failed to retrieve file content through protected endpoint"
    );

    // 2d. Dean access via endpoint MUST succeed
    $deanRes = invokeDownload([
        'user_id' => $deanId,
        'user_role' => 'dean',
        'role' => 'dean'
    ], $testDocId);
    assertCheck(
        "Dean successfully downloads file through document-download.php",
        str_contains($deanRes['stdout'], $testFileContent),
        "Dean failed to retrieve file content through protected endpoint"
    );

    // -------------------------------------------------------------
    // TEST 3: Upload Functionality Remains Intact
    // -------------------------------------------------------------
    echo "\n--- 3. Upload Functionality Verification ---\n";
    $testUploadName = 'test_upload_intact_' . time() . '.png';
    $targetPath = UPLOAD_PATH . 'posters/' . $testUploadName;
    $bytesWritten = file_put_contents($targetPath, "MOCK_POSTER_IMAGE_DATA");
    assertCheck(
        "Filesystem write/upload to uploads/ remains fully functional",
        $bytesWritten !== false && file_exists($targetPath),
        "Could not write file to uploads directory"
    );
    @unlink($targetPath);

} finally {
    echo "\nCleaning up test fixtures...\n";
    if (file_exists($testFullLocalPath)) {
        @unlink($testFullLocalPath);
    }
    $db->prepare("DELETE FROM documents WHERE id = ?")->execute([$testDocId]);
    $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$testActId]);
    echo "Cleanup complete.\n\n";
}

echo "============================================================\n";
echo "  Results: Passed = {$passedTests}, Failed = {$failedTests}\n";
echo "============================================================\n";

if ($failedTests > 0) {
    exit(1);
}
exit(0);
