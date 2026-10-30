<?php
/**
 * test_proposal_png_upload.php
 *
 * Dedicated verification test suite for creating an activity proposal
 * with floor_plan_required = 1 and an attached PNG floor plan file.
 *
 * Requirements:
 * 1. Submit proposal with floor_plan_required = 1 and attached PNG fixture via floor_plan_file.
 * 2. Verify endpoint accepts request and creates the activity.
 * 3. Verify database floor_plans record is created with valid file_path.
 * 4. Verify stored floor plan file exists in uploads/floorplans/ and bytes match submitted PNG.
 * 5. Safely clean up only the test activity, floor_plans record, and created file.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "========================================================================\n";
echo "  TEST SUITE: PNG FLOOR PLAN UPLOAD DURING PROPOSAL CREATION\n";
echo "  Verifying floor_plan_file upload handling in api/proposal-save.php\n";
echo "========================================================================\n\n";

$db = getDB();

$passCount = 0;
$failCount = 0;

function runTest(string $title, bool $condition, string $details = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] {$title}\n";
    } else {
        $failCount++;
        echo " [FAIL] {$title}\n";
    }
    if ($details) {
        echo "        Details: {$details}\n";
    }
}

// 1. Locate an existing faculty user for session context
$faculty = $db->query("SELECT id, name, email FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$faculty) {
    echo "❌ Blocker: No faculty user found in database.\n";
    exit(1);
}
$facultyId = (int)$faculty['id'];
echo "[SETUP] Using Faculty User ID: {$facultyId} ({$faculty['email']})\n";

// 2. Create isolated temporary directory and valid minimal PNG fixture
$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sti_png_upload_' . uniqid();
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}

$validPngBytes = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
);
$fixturePath = $tempDir . DIRECTORY_SEPARATOR . 'staging_floor_plan.png';
file_put_contents($fixturePath, $validPngBytes);
$fixtureSize = strlen($validPngBytes);
echo "[SETUP] Created minimal PNG fixture: {$fixturePath} ({$fixtureSize} bytes)\n\n";

// 3. Subprocess execution harness simulating multipart upload with TEST_RUNNER
function executeProposalSaveWithFile(array $postData, array $filesData, int $facultyId): string {
    $script = realpath(__DIR__ . '/../api/proposal-save.php');
    $code = "<?php
    define('TEST_RUNNER', true);
    if (session_status() === PHP_SESSION_NONE) session_start();
    \$_SESSION['user_id'] = {$facultyId};
    \$_SESSION['user_name'] = 'Test Faculty';
    \$_SESSION['user_email'] = 'faculty@sti.edu';
    \$_SESSION['role'] = 'faculty';
    \$_SESSION['user_role'] = 'faculty';
    \$_SESSION['last_activity'] = time();
    \$_POST = " . var_export($postData, true) . ";
    \$_FILES = " . var_export($filesData, true) . ";
    ob_start();
    try {
        include " . var_export($script, true) . ";
    } catch (Throwable \$e) {
        echo 'EXCEPTION: ' . \$e->getMessage();
    }
    ";

    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open('php', $desc, $pipes);
    if (!is_resource($proc)) return 'PROC_ERROR';

    fwrite($pipes[0], $code);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    return $out . $err;
}

$uniqueTitle = 'Verification Test PNG Upload ' . time() . '_' . mt_rand(1000, 9999);
$testFutureDate = date('Y-m-d', strtotime('+7 days'));

$postPayload = [
    'title'                => $uniqueTitle,
    'description'          => 'Automated test activity verifying PNG floor plan file upload.',
    'theme'                => 'Robotics Exhibition',
    'venue'                => 'Main Gymnasium Hall',
    'venue_address'        => 'Campus Gym Floor 1',
    'event_date'           => $testFutureDate,
    'start_time'           => '08:00:00',
    'end_time'             => '17:00:00',
    'target_participants'  => '150',
    'source'               => 'faculty',
    'action'               => 'draft',
    'floor_plan_required'  => '1', // Floor plan is REQUIRED
    'floor_plan_notes'     => 'Staging area, power outlets, and robot testing arena layout'
];

$filesPayload = [
    'floor_plan_file' => [
        'name'     => 'staging_floor_plan.png',
        'type'     => 'image/png',
        'tmp_name' => $fixturePath,
        'error'    => UPLOAD_ERR_OK,
        'size'     => $fixtureSize
    ]
];

$createdActivityId = null;
$createdFilePath = null;
$createdFullDiskPath = null;

try {
    echo "--- Executing Proposal Creation with PNG Floor Plan File ---\n";
    $output = executeProposalSaveWithFile($postPayload, $filesPayload, $facultyId);

    // 1. Verify activity creation
    $actStmt = $db->prepare("SELECT id, title, floor_plan_required, status FROM activities WHERE title = ? ORDER BY id DESC LIMIT 1");
    $actStmt->execute([$uniqueTitle]);
    $createdActivity = $actStmt->fetch(PDO::FETCH_ASSOC);

    if ($createdActivity) {
        $createdActivityId = (int)$createdActivity['id'];
    }

    runTest(
        "Proposal creation succeeds with uploaded PNG floor plan",
        $createdActivityId !== null && $createdActivityId > 0,
        $createdActivityId ? "Created Activity ID: {$createdActivityId}" : "Output: {$output}"
    );

    // 2. Verify floor_plan_required column
    $actualFpReq = $createdActivity ? (int)$createdActivity['floor_plan_required'] : null;
    runTest(
        "Saved activity record has floor_plan_required = 1",
        $actualFpReq === 1,
        "Actual database value: " . var_export($actualFpReq, true)
    );

    // 3. Verify floor_plans table record
    $fpStmt = $db->prepare("SELECT id, activity_id, file_path, notes, canvas_json FROM floor_plans WHERE activity_id = ?");
    $fpStmt->execute([$createdActivityId]);
    $fpRecord = $fpStmt->fetch(PDO::FETCH_ASSOC);

    $fpRecordExists = ($fpRecord !== false && !empty($fpRecord['file_path']));
    if ($fpRecordExists) {
        $createdFilePath = $fpRecord['file_path'];
    }

    runTest(
        "Database contains floor_plans record with stored file_path",
        $fpRecordExists && str_starts_with($createdFilePath, 'floorplans/'),
        $fpRecordExists ? "Record ID: {$fpRecord['id']}, Stored Path: {$createdFilePath}" : "No valid floor_plans record found"
    );

    // 4. Verify stored file on disk
    $createdFullDiskPath = UPLOAD_PATH . $createdFilePath;
    $fileExistsOnDisk = file_exists($createdFullDiskPath);
    runTest(
        "Stored floor plan file exists on disk in uploads/floorplans/",
        $fileExistsOnDisk,
        "Disk path: {$createdFullDiskPath} (Exists: " . ($fileExistsOnDisk ? "YES" : "NO") . ")"
    );

    // 5. Verify byte content parity
    $diskBytes = $fileExistsOnDisk ? file_get_contents($createdFullDiskPath) : '';
    $bytesMatch = ($diskBytes === $validPngBytes);
    $diskSize = strlen($diskBytes);
    runTest(
        "Stored file bytes exactly match submitted PNG fixture",
        $bytesMatch,
        "Submitted bytes: {$fixtureSize}, Disk bytes: {$diskSize}, Exact Match: " . ($bytesMatch ? "YES" : "NO")
    );

} finally {
    echo "\n--- Cleaning Up Test Artifacts ---\n";

    // Clean up created floor plan file on disk
    if ($createdFullDiskPath && file_exists($createdFullDiskPath)) {
        $unlinked = @unlink($createdFullDiskPath);
        echo "  [CLEANUP] Deleted uploaded file {$createdFullDiskPath}: " . ($unlinked ? "SUCCESS" : "FAILED") . "\n";
    }

    // Clean up test activity and associated records from database
    if ($createdActivityId) {
        $db->prepare("DELETE FROM floor_plans WHERE activity_id = ?")->execute([$createdActivityId]);
        $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$createdActivityId]);
        $verifyAct = $db->query("SELECT COUNT(*) FROM activities WHERE id = {$createdActivityId}")->fetchColumn();
        $verifyFp = $db->query("SELECT COUNT(*) FROM floor_plans WHERE activity_id = {$createdActivityId}")->fetchColumn();
        echo "  [CLEANUP] Activity ID {$createdActivityId} deleted: " . ($verifyAct == 0 ? "SUCCESS" : "FAILED") . "\n";
        echo "  [CLEANUP] floor_plans record deleted: " . ($verifyFp == 0 ? "SUCCESS" : "FAILED") . "\n";
    }

    // Clean up temporary fixture directory
    if (file_exists($fixturePath)) {
        @unlink($fixturePath);
    }
    if (is_dir($tempDir)) {
        @rmdir($tempDir);
        echo "  [CLEANUP] Temporary fixture directory removed: SUCCESS\n";
    }
}

echo "\n========================================================================\n";
echo "  SUMMARY: Total Tests: " . ($passCount + $failCount) . " | Passed: {$passCount} | Failed: {$failCount}\n";
echo "========================================================================\n";

exit($failCount > 0 ? 1 : 0);
