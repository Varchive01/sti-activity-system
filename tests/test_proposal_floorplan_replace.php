<?php
/**
 * test_proposal_floorplan_replace.php
 *
 * Dedicated verification test suite for replacing an existing floor plan
 * during proposal editing in api/proposal-update.php.
 *
 * Requirements:
 * 1. Create an isolated temporary test activity with an initial floor plan file & record.
 * 2. Submit an edit request via api/proposal-update.php replacing the floor plan with a different valid PNG.
 * 3. Verify update succeeds and floor_plans record points to the replacement file.
 * 4. Verify replacement file exists on disk and its byte content matches replacement fixture.
 * 5. Verify the superseded old floor plan file is automatically removed from disk.
 * 6. Verify exactly one floor_plans record exists (no duplicate rows).
 * 7. Clean up only records and files created by this test.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "========================================================================\n";
echo "  TEST SUITE: FLOOR PLAN REPLACEMENT DURING PROPOSAL EDITING\n";
echo "  Verifying floor plan replacement & old file cleanup in api/proposal-update.php\n";
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

// 2. Prepare distinct binary fixtures for initial vs replacement PNGs
$initialPngBytes = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
); // 1x1 PNG

$replacementPngBytes = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFElEQVR42mNk+M/wHwMDAwMDCAMAAf4C/6u31KAAAAAASUVORK5CYII='
); // 2x2 distinct PNG

$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sti_fp_replace_' . uniqid();
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}

$replacementFixturePath = $tempDir . DIRECTORY_SEPARATOR . 'replacement_floor_plan.png';
file_put_contents($replacementFixturePath, $replacementPngBytes);
$replacementFixtureSize = strlen($replacementPngBytes);

// 3. Create isolated initial test activity in database
$uniqueTitle = 'Test Floor Plan Replace ' . time() . '_' . mt_rand(1000, 9999);
$testFutureDate = date('Y-m-d', strtotime('+10 days'));

$db->prepare("
    INSERT INTO activities (faculty_id, title, description, theme, venue, venue_address, event_date, start_time, end_time, target_participants, source, status, floor_plan_required)
    VALUES (?, ?, 'Temporary activity for replacement test', 'Engineering Summit', 'Hall B', 'Campus Ground', ?, '09:00:00', '16:00:00', 100, 'faculty', 'draft', 1)
")->execute([$facultyId, $uniqueTitle, $testFutureDate]);
$testActId = (int)$db->lastInsertId();

// Create initial floor plan file directly in uploads/floorplans/
$fpUploadsDir = UPLOAD_PATH . 'floorplans/';
if (!is_dir($fpUploadsDir)) {
    mkdir($fpUploadsDir, 0755, true);
}
$initialFileName = $testActId . '_initial_' . time() . '.png';
$initialRelativePath = 'floorplans/' . $initialFileName;
$initialFullDiskPath = $fpUploadsDir . $initialFileName;
file_put_contents($initialFullDiskPath, $initialPngBytes);

// Insert initial floor_plans record
$db->prepare("
    INSERT INTO floor_plans (activity_id, file_path, notes, canvas_json)
    VALUES (?, ?, 'Initial floor plan notes', NULL)
")->execute([$testActId, $initialRelativePath]);

echo "[SETUP] Created test activity ID {$testActId}\n";
echo "[SETUP] Created initial floor plan file: {$initialFullDiskPath}\n";
echo "[SETUP] Created replacement fixture: {$replacementFixturePath} ({$replacementFixtureSize} bytes)\n\n";

// 4. Subprocess execution harness for api/proposal-update.php
function executeProposalUpdateWithFile(array $postData, array $filesData, int $facultyId): string {
    $script = realpath(__DIR__ . '/../api/proposal-update.php');
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

$editPostPayload = [
    'activity_id'          => $testActId,
    'title'                => $uniqueTitle,
    'description'          => 'Updated description during floor plan replacement test.',
    'theme'                => 'Engineering Summit Updated',
    'venue'                => 'Hall B - East Wing',
    'venue_address'        => 'Campus Ground Floor',
    'event_date'           => $testFutureDate,
    'start_time'           => '09:00:00',
    'end_time'             => '17:00:00',
    'target_participants'  => '120',
    'source'               => 'faculty',
    'action'               => 'draft',
    'floor_plan_required'  => '1',
    'floor_plan_notes'     => 'Replacement floor plan with updated safety zones'
];

$editFilesPayload = [
    'floor_plan_file' => [
        'name'     => 'replacement_floor_plan.png',
        'type'     => 'image/png',
        'tmp_name' => $replacementFixturePath,
        'error'    => UPLOAD_ERR_OK,
        'size'     => $replacementFixtureSize
    ]
];

$newFilePath = null;
$newFullDiskPath = null;

try {
    echo "--- Executing Proposal Update (Replacing Floor Plan) ---\n";
    $output = executeProposalUpdateWithFile($editPostPayload, $editFilesPayload, $facultyId);

    // 1. Verify single floor_plans record exists (no duplicate rows)
    $fpCountStmt = $db->prepare("SELECT COUNT(*) FROM floor_plans WHERE activity_id = ?");
    $fpCountStmt->execute([$testActId]);
    $totalFpRecords = (int)$fpCountStmt->fetchColumn();

    runTest(
        "Exactly one floor_plans record exists (no duplicate records created)",
        $totalFpRecords === 1,
        "Total matching rows: {$totalFpRecords}"
    );

    // 2. Fetch updated floor_plans record and verify path was replaced
    $fpStmt = $db->prepare("SELECT id, file_path, notes FROM floor_plans WHERE activity_id = ?");
    $fpStmt->execute([$testActId]);
    $updatedFp = $fpStmt->fetch(PDO::FETCH_ASSOC);

    $newFilePath = $updatedFp ? $updatedFp['file_path'] : null;
    $pathReplaced = ($newFilePath !== null && $newFilePath !== $initialRelativePath && str_starts_with($newFilePath, 'floorplans/'));

    runTest(
        "floor_plans record points to new replacement file path",
        $pathReplaced,
        "Old Path: {$initialRelativePath}, New Path: {$newFilePath}"
    );

    // 3. Verify replacement file exists on disk
    $newFullDiskPath = UPLOAD_PATH . $newFilePath;
    $replacementExistsOnDisk = ($newFullDiskPath && file_exists($newFullDiskPath));

    runTest(
        "Replacement floor plan file exists on disk in uploads/floorplans/",
        $replacementExistsOnDisk,
        "Disk path: {$newFullDiskPath} (Exists: " . ($replacementExistsOnDisk ? "YES" : "NO") . ")"
    );

    // 4. Verify replacement file content matches replacement fixture bytes exactly
    $newDiskBytes = $replacementExistsOnDisk ? file_get_contents($newFullDiskPath) : '';
    $bytesMatch = ($newDiskBytes === $replacementPngBytes);
    $initialBytesMatch = ($newDiskBytes === $initialPngBytes);

    runTest(
        "Replacement file bytes exactly match replacement fixture (and differ from initial)",
        $bytesMatch && !$initialBytesMatch,
        "Disk bytes length: " . strlen($newDiskBytes) . ", Matches Replacement: " . ($bytesMatch ? "YES" : "NO")
    );

    // 5. Verify the superseded initial floor plan file was automatically deleted by proposal-update.php
    $initialFileStillExists = file_exists($initialFullDiskPath);

    runTest(
        "Superseded initial floor plan file was automatically cleaned up from disk",
        !$initialFileStillExists,
        "Initial file path: {$initialFullDiskPath} (Still exists: " . ($initialFileStillExists ? "YES (FAIL)" : "NO (Cleaned up successfully)") . ")"
    );

} finally {
    echo "\n--- Cleaning Up Test Artifacts ---\n";

    // Clean up replacement file if still on disk
    if ($newFullDiskPath && file_exists($newFullDiskPath)) {
        $unlinkedNew = @unlink($newFullDiskPath);
        echo "  [CLEANUP] Deleted replacement file {$newFullDiskPath}: " . ($unlinkedNew ? "SUCCESS" : "FAILED") . "\n";
    }

    // Clean up initial file if it still exists (safeguard)
    if (file_exists($initialFullDiskPath)) {
        @unlink($initialFullDiskPath);
        echo "  [CLEANUP] Safeguard deleted initial file: SUCCESS\n";
    }

    // Clean up test activity and database records
    if ($testActId) {
        $db->prepare("DELETE FROM floor_plans WHERE activity_id = ?")->execute([$testActId]);
        $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$testActId]);
        $checkAct = $db->query("SELECT COUNT(*) FROM activities WHERE id = {$testActId}")->fetchColumn();
        $checkFp = $db->query("SELECT COUNT(*) FROM floor_plans WHERE activity_id = {$testActId}")->fetchColumn();
        echo "  [CLEANUP] Activity ID {$testActId} deleted: " . ($checkAct == 0 ? "SUCCESS" : "FAILED") . "\n";
        echo "  [CLEANUP] floor_plans records deleted: " . ($checkFp == 0 ? "SUCCESS" : "FAILED") . "\n";
    }

    // Clean up temporary fixture directory
    if (file_exists($replacementFixturePath)) {
        @unlink($replacementFixturePath);
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
