<?php
/**
 * test_proposal_pdf_upload.php
 *
 * Dedicated verification test suite for creating an activity proposal
 * with floor_plan_required = 1 and an attached PDF floor plan file.
 *
 * Requirements:
 * 1. Submit proposal with floor_plan_required = 1 and attached PDF fixture via floor_plan_file.
 * 2. Verify endpoint accepts request and creates the activity.
 * 3. Verify database floor_plans record is created with valid file_path (.pdf).
 * 4. Verify stored floor plan file exists in uploads/floorplans/ and bytes match submitted PDF.
 * 5. Safely clean up only the test activity, floor_plans record, and created file.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "========================================================================\n";
echo "  TEST SUITE: PDF FLOOR PLAN UPLOAD DURING PROPOSAL CREATION\n";
echo "  Verifying floor_plan_file PDF upload handling in api/proposal-save.php\n";
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

// 2. Create isolated temporary directory and valid minimal PDF fixture
$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sti_pdf_upload_' . uniqid();
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}

// Authentic minimal PDF binary structure
$validPdfBytes = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \ntrailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n190\n%%EOF\n";

$fixturePath = $tempDir . DIRECTORY_SEPARATOR . 'evacuation_floor_plan.pdf';
file_put_contents($fixturePath, $validPdfBytes);
$fixtureSize = strlen($validPdfBytes);

// Validate fixture independently before submission
$fixtureMime = function_exists('mime_content_type') ? mime_content_type($fixturePath) : 'unknown';
$hasPdfMagic = str_starts_with($validPdfBytes, '%PDF-');
$fixtureIsRealPdf = ($hasPdfMagic && ($fixtureMime === 'application/pdf' || $fixtureMime === 'text/plain'));

echo "[SETUP] Created PDF fixture: {$fixturePath} ({$fixtureSize} bytes)\n";
echo "[SETUP] Fixture validation: Magic = " . ($hasPdfMagic ? '%PDF- (Valid)' : 'Invalid') . ", Detected MIME = {$fixtureMime}\n\n";

if (!$hasPdfMagic) {
    echo "❌ Blocker: Fixture does not contain valid PDF magic bytes.\n";
    @unlink($fixturePath);
    @rmdir($tempDir);
    exit(1);
}

// 3. Subprocess execution harness simulating multipart upload with TEST_RUNNER
function executeProposalSaveWithPdf(array $postData, array $filesData, int $facultyId): string {
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

$uniqueTitle = 'Verification Test PDF Upload ' . time() . '_' . mt_rand(1000, 9999);
$testFutureDate = date('Y-m-d', strtotime('+7 days'));

$postPayload = [
    'title'                => $uniqueTitle,
    'description'          => 'Automated test activity verifying PDF floor plan file upload.',
    'theme'                => 'Campus Safety Awareness',
    'venue'                => 'Main Building Auditorium & Atrium',
    'venue_address'        => 'Main Campus, Ground Floor',
    'event_date'           => $testFutureDate,
    'start_time'           => '10:00:00',
    'end_time'             => '15:00:00',
    'target_participants'  => '250',
    'source'               => 'faculty',
    'action'               => 'draft',
    'floor_plan_required'  => '1', // Floor plan is REQUIRED
    'floor_plan_notes'     => 'Multi-zone emergency evacuation and stage blueprint in PDF format'
];

$filesPayload = [
    'floor_plan_file' => [
        'name'     => 'evacuation_floor_plan.pdf',
        'type'     => 'application/pdf',
        'tmp_name' => $fixturePath,
        'error'    => UPLOAD_ERR_OK,
        'size'     => $fixtureSize
    ]
];

$createdActivityId = null;
$createdFilePath = null;
$createdFullDiskPath = null;

try {
    echo "--- Executing Proposal Creation with PDF Floor Plan File ---\n";
    $output = executeProposalSaveWithPdf($postPayload, $filesPayload, $facultyId);

    // 1. Verify activity creation
    $actStmt = $db->prepare("SELECT id, title, floor_plan_required, status FROM activities WHERE title = ? ORDER BY id DESC LIMIT 1");
    $actStmt->execute([$uniqueTitle]);
    $createdActivity = $actStmt->fetch(PDO::FETCH_ASSOC);

    if ($createdActivity) {
        $createdActivityId = (int)$createdActivity['id'];
    }

    runTest(
        "Proposal creation succeeds with uploaded PDF floor plan",
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

    $hasValidExtension = str_ends_with(strtolower((string)$createdFilePath), '.pdf');
    runTest(
        "Database contains floor_plans record with valid PDF file_path",
        $fpRecordExists && str_starts_with($createdFilePath, 'floorplans/') && $hasValidExtension,
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
    $bytesMatch = ($diskBytes === $validPdfBytes);
    $diskSize = strlen($diskBytes);
    runTest(
        "Stored file bytes exactly match submitted PDF fixture",
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
