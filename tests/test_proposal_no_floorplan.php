<?php
/**
 * test_proposal_no_floorplan.php
 *
 * Dedicated verification test suite for creating an activity proposal
 * when floor_plan_required = 0 (optional floor plan).
 *
 * Requirements:
 * 1. Submit proposal with floor_plan_required = 0 and no floor plan file/notes/shapes.
 * 2. Verify request succeeds and saved activity record has floor_plan_required = 0.
 * 3. Verify no floor_plans database record is created for this activity.
 * 4. Verify no floor plan file is created in uploads/floorplans.
 * 5. Safely clean up only the created test activity record.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "========================================================================\n";
echo "  TEST SUITE: PROPOSAL CREATION WITHOUT REQUIRED FLOOR PLAN\n";
echo "  Verifying floor_plan_required = 0 handling in api/proposal-save.php\n";
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

// 1. Locate an existing faculty user for authentication context
$faculty = $db->query("SELECT id, name, email FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$faculty) {
    echo "❌ Blocker: No faculty user found in database.\n";
    exit(1);
}
$facultyId = (int)$faculty['id'];
echo "[SETUP] Using Faculty User ID: {$facultyId} ({$faculty['email']})\n";

// 2. Snapshot existing floor plan files in uploads/floorplans/
$fpDir = __DIR__ . '/../uploads/floorplans/';
$beforeFiles = is_dir($fpDir) ? scandir($fpDir) : [];
$beforeCount = count($beforeFiles);
echo "[SETUP] Snapshot of uploads/floorplans/: {$beforeCount} entries\n\n";

// Subprocess runner reusing the established pattern from tests/test_proposal_date_validation.php
function executeProposalSave(array $postData, int $facultyId): string {
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
    \$_FILES = [];
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

$uniqueTitle = 'Verification Test No Floor Plan ' . time() . '_' . mt_rand(1000, 9999);
$testFutureDate = date('Y-m-d', strtotime('+5 days'));

$payload = [
    'title'                => $uniqueTitle,
    'description'          => 'Automated test activity verifying floor_plan_required=0 behavior.',
    'theme'                => 'Online Webinar Theme',
    'venue'                => 'Online MS Teams',
    'venue_address'        => 'Online',
    'event_date'           => $testFutureDate,
    'start_time'           => '09:00:00',
    'end_time'             => '11:00:00',
    'target_participants'  => '50',
    'source'               => 'faculty',
    'action'               => 'draft',
    'floor_plan_required'  => '0', // Explicitly NO floor plan required
    'floor_plan_notes'     => '',
    'floor_plan_json'      => '',
    'floor_plan_image'     => ''
];

$createdActivityId = null;

try {
    echo "--- Executing Proposal Save Request ---\n";
    $output = executeProposalSave($payload, $facultyId);

    // Fetch newly created activity from database
    $stmt = $db->prepare("SELECT id, title, floor_plan_required, status FROM activities WHERE title = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$uniqueTitle]);
    $createdActivity = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($createdActivity) {
        $createdActivityId = (int)$createdActivity['id'];
    }

    // CHECK 1: Proposal request succeeded and record was created
    runTest(
        "Proposal creation succeeds without a required floor plan",
        $createdActivityId !== null && $createdActivityId > 0,
        $createdActivityId ? "Created Activity ID: {$createdActivityId}" : "Output: {$output}"
    );

    // CHECK 2: Activity has floor_plan_required = 0 in database
    $actualFpRequired = $createdActivity ? (int)$createdActivity['floor_plan_required'] : null;
    runTest(
        "Saved activity record has floor_plan_required = 0",
        $actualFpRequired === 0,
        "Actual database value: " . var_export($actualFpRequired, true)
    );

    // CHECK 3: No floor_plans database record created
    $fpCountStmt = $db->prepare("SELECT COUNT(*) FROM floor_plans WHERE activity_id = ?");
    $fpCountStmt->execute([$createdActivityId]);
    $fpRowCount = (int)$fpCountStmt->fetchColumn();
    runTest(
        "No empty floor_plans record is created in database",
        $fpRowCount === 0,
        "Matching rows in floor_plans table: {$fpRowCount}"
    );

    // CHECK 4: No new file generated in uploads/floorplans/
    $afterFiles = is_dir($fpDir) ? scandir($fpDir) : [];
    $afterCount = count($afterFiles);
    $diffFiles = array_diff($afterFiles, $beforeFiles);
    $activitySpecificFiles = array_filter($diffFiles, function($f) use ($createdActivityId) {
        return str_starts_with($f, "fp_{$createdActivityId}_");
    });

    runTest(
        "No floor plan file is created in uploads/floorplans/",
        $afterCount === $beforeCount && empty($activitySpecificFiles),
        "Files before: {$beforeCount}, Files after: {$afterCount}, New files: " . json_encode(array_values($diffFiles))
    );

} finally {
    // 5. Cleanup: only remove the test activity created by this run
    if ($createdActivityId) {
        echo "\n[CLEANUP] Cleaning up created test activity ID: {$createdActivityId}...\n";
        $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$createdActivityId]);
        $verifyDeleted = $db->query("SELECT COUNT(*) FROM activities WHERE id = {$createdActivityId}")->fetchColumn();
        echo "          Deletion verification: " . ($verifyDeleted == 0 ? "SUCCESS (Record removed)" : "WARNING (Record still present)") . "\n";
    }
}

echo "\n========================================================================\n";
echo "  SUMMARY: Total Tests: " . ($passCount + $failCount) . " | Passed: {$passCount} | Failed: {$failCount}\n";
echo "========================================================================\n";

exit($failCount > 0 ? 1 : 0);
