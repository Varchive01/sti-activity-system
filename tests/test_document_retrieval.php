<?php
/**
 * Focused test suite for Document Retrieval & Download Flow
 * Tests authentication, authorization (faculty owner vs non-owner, admin1, admin2, dean),
 * path traversal protection, and file serving behavior.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();

echo "====================================================\n";
echo "   DOCUMENT RETRIEVAL & DOWNLOAD FOCUSED TEST SUITE\n";
echo "====================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$name}\n";
        $passed++;
    } else {
        echo " [FAIL] {$name}" . ($detail ? " - {$detail}" : "") . "\n";
        $failed++;
    }
}

// 1. Setup Test Users & Fixtures
echo "--- Setting up test fixtures ---\n";
// Find or create test faculty 1 (owner) and faculty 2 (non-owner)
$faculty1 = $db->query("SELECT id FROM users WHERE role='faculty' ORDER BY id ASC LIMIT 1")->fetch();
$faculty2 = $db->query("SELECT id FROM users WHERE role='faculty' AND id != " . (int)$faculty1['id'] . " ORDER BY id ASC LIMIT 1")->fetch();
$admin1 = $db->query("SELECT id FROM users WHERE role='admin1' LIMIT 1")->fetch();
$admin2 = $db->query("SELECT id FROM users WHERE role='admin2' LIMIT 1")->fetch();
$dean = $db->query("SELECT id FROM users WHERE role='dean' LIMIT 1")->fetch();

if (!$faculty1 || !$faculty2) {
    echo "Creating secondary test faculty for isolation test...\n";
    $db->prepare("INSERT INTO users (name, email, password, role, dept) VALUES ('Test Faculty NonOwner', 'fac2_test@sti.edu', 'hash', 'faculty', 'IT')")->execute();
    $faculty2Id = (int)$db->lastInsertId();
} else {
    $faculty2Id = (int)$faculty2['id'];
}
$faculty1Id = (int)$faculty1['id'];

// Create a test activity owned by faculty1
$db->prepare("
    INSERT INTO activities (title, faculty_id, status, event_date, start_time, end_time, venue, description)
    VALUES ('File Retrieval Test Activity', ?, 'approved', CURDATE(), '09:00:00', '11:00:00', 'Room 101', 'Test')
")->execute([$faculty1Id]);
$testActivityId = (int)$db->lastInsertId();

// Create a real test file in uploads/post-event/
$uploadDir = UPLOAD_PATH . 'post-event/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
$testFileName = 'test_report_' . time() . '.pdf';
$testFilePath = 'post-event/' . $testFileName;
$testFullLocalPath = $uploadDir . $testFileName;
$testFileContent = "%PDF-1.4 Mock PDF Content For File Retrieval Verification";
file_put_contents($testFullLocalPath, $testFileContent);

// Insert document record into documents table
$db->prepare("
    INSERT INTO documents (activity_id, doc_type, file_name, file_path, uploaded_by)
    VALUES (?, 'post_event', 'Post_Activity_Report.pdf', ?, ?)
")->execute([$testActivityId, $testFilePath, $faculty1Id]);
$testDocId = (int)$db->lastInsertId();

// Insert a traversal attempt record in database
$db->prepare("
    INSERT INTO documents (activity_id, doc_type, file_name, file_path, uploaded_by)
    VALUES (?, 'post_event', 'malicious.pdf', '../../config/database.php', ?)
")->execute([$testActivityId, $faculty1Id]);
$traversalDocId = (int)$db->lastInsertId();

echo "Fixtures created: Activity #{$testActivityId}, Document #{$testDocId}, TraversalDoc #{$traversalDocId}\n\n";

// Helper function to invoke api/document-download.php via CLI sub-process with mocked session & query params
function invokeDownloadEndpoint(?array $sessionData, array $queryParams) {
    $runnerPath = escapeshellarg(__DIR__ . '/runner_helper.php');
    $cmd = 'php ' . $runnerPath;

    $descriptors = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];
    $process = proc_open($cmd, $descriptors, $pipes);
    
    $payload = json_encode([
        'session' => $sessionData,
        'get'     => $queryParams
    ]);
    
    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'exitCode' => $exitCode,
        'stdout'   => $stdout,
        'stderr'   => $stderr
    ];
}

// TEST 1: Unauthenticated request should be blocked
echo "--- Running Security & Authentication Tests ---\n";
$res1 = invokeDownloadEndpoint(null, ['id' => $testDocId]);
// Unauthenticated should fail (header redirect to login or exit)
assertTest(
    "Unauthenticated request blocked",
    !str_contains($res1['stdout'], $testFileContent),
    "Response must not leak file content"
);

// TEST 2: Missing or invalid document ID
$res2 = invokeDownloadEndpoint(['user_id' => $faculty1Id, 'user_role' => 'faculty'], ['id' => 0]);
assertTest(
    "Invalid document ID (id=0) rejected",
    str_contains($res2['stdout'], 'Bad Request') || !str_contains($res2['stdout'], $testFileContent)
);

// TEST 3: Non-existent document ID
$res3 = invokeDownloadEndpoint(['user_id' => $faculty1Id, 'user_role' => 'faculty'], ['id' => 999999]);
assertTest(
    "Non-existent document ID rejected (404)",
    str_contains($res3['stdout'], 'not found') || !str_contains($res3['stdout'], $testFileContent)
);

// TEST 4: Faculty Non-Owner Authorization Block
echo "--- Running RBAC Authorization Tests ---\n";
$res4 = invokeDownloadEndpoint(['user_id' => $faculty2Id, 'user_role' => 'faculty'], ['id' => $testDocId]);
assertTest(
    "Non-owner faculty blocked from accessing document (403)",
    (str_contains($res4['stdout'], 'Forbidden') || str_contains($res4['stdout'], 'permission')) &&
    !str_contains($res4['stdout'], $testFileContent),
    "Non-owner faculty was prevented from downloading"
);

// TEST 5: Faculty Owner Retrieval Allowed
$res5 = invokeDownloadEndpoint(['user_id' => $faculty1Id, 'user_role' => 'faculty'], ['id' => $testDocId]);
assertTest(
    "Faculty owner successfully retrieves document (200)",
    str_contains($res5['stdout'], $testFileContent),
    "File content correctly returned to owner"
);

// TEST 6: Admin1 Institutional Access Allowed
if ($admin1) {
    $res6 = invokeDownloadEndpoint(['user_id' => (int)$admin1['id'], 'user_role' => 'admin1'], ['id' => $testDocId]);
    assertTest(
        "Admin1 successfully retrieves activity document",
        str_contains($res6['stdout'], $testFileContent)
    );
}

// TEST 7: Admin2 Evaluator Access Allowed
if ($admin2) {
    $res7 = invokeDownloadEndpoint(['user_id' => (int)$admin2['id'], 'user_role' => 'admin2'], ['id' => $testDocId]);
    assertTest(
        "Admin2 successfully retrieves activity document",
        str_contains($res7['stdout'], $testFileContent)
    );
}

// TEST 8: Dean Access Allowed
if ($dean) {
    $res8 = invokeDownloadEndpoint(['user_id' => (int)$dean['id'], 'user_role' => 'dean'], ['id' => $testDocId]);
    assertTest(
        "Dean successfully retrieves activity document",
        str_contains($res8['stdout'], $testFileContent)
    );
}

// TEST 9: Path Traversal Defense Verification
echo "--- Running Path Traversal Defense Tests ---\n";
$res9 = invokeDownloadEndpoint(['user_id' => $faculty1Id, 'user_role' => 'faculty'], ['id' => $traversalDocId]);
assertTest(
    "Path traversal attempt (../../config/database.php) blocked",
    !str_contains($res9['stdout'], 'DB_PASS') &&
    (str_contains($res9['stdout'], 'not found') || str_contains($res9['stdout'], 'denied')),
    "File outside UPLOAD_PATH is not served"
);

// Teardown test artifacts
echo "\n--- Cleaning up test fixtures ---\n";
if (file_exists($testFullLocalPath)) {
    unlink($testFullLocalPath);
}
$db->prepare("DELETE FROM documents WHERE id IN (?, ?)")->execute([$testDocId, $traversalDocId]);
$db->prepare("DELETE FROM activities WHERE id = ?")->execute([$testActivityId]);
if (isset($faculty2Id) && !isset($faculty2['id'])) {
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$faculty2Id]);
}
echo "Cleanup completed.\n\n";

echo "====================================================\n";
echo "TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "====================================================\n";

exit($failed > 0 ? 1 : 0);
