<?php
/**
 * test_proposal_date_validation.php
 *
 * Dedicated verification suite for the proposal date restriction rule:
 * event_date > today's date (Asia/Manila)
 *
 * Test Matrix:
 * - Yesterday (today - 1)   -> REJECTED
 * - Earlier date (today - 7)-> REJECTED
 * - Previous month          -> REJECTED
 * - Previous year           -> REJECTED
 * - Today                   -> REJECTED
 * - Tomorrow                -> ACCEPTED
 * - Future date             -> ACCEPTED
 * - Direct POST tampering   -> REJECTED
 * - Proposal update checks  -> Verified
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "========================================================================\n";
echo "  STI ACTIVITY SYSTEM - PROPOSAL DATE VALIDATION TEST SUITE\n";
echo "  Rule: event_date > today's date (Authoritative Asia/Manila)\n";
echo "========================================================================\n\n";

$db = getDB();

// Verify Timezone
$currentTz = date_default_timezone_get();
$todayManila = date('Y-m-d');
$tomorrowManila = date('Y-m-d', strtotime('+1 day'));
$yesterdayManila = date('Y-m-d', strtotime('-1 day'));
$earlierDate = date('Y-m-d', strtotime('-7 days'));
$prevMonth = date('Y-m-d', strtotime('-1 month'));
$prevYear = date('Y-m-d', strtotime('-1 year'));
$futureDate = date('Y-m-d', strtotime('+10 days'));

echo "[TIMEZONE] Current PHP Timezone: {$currentTz}\n";
echo "[CALENDAR] Today (Manila):       {$todayManila}\n";
echo "[CALENDAR] Tomorrow (Manila):    {$tomorrowManila}\n";
echo "[CALENDAR] Yesterday (Manila):   {$yesterdayManila}\n\n";

if ($currentTz !== 'Asia/Manila') {
    echo "❌ FAILED: Timezone is not Asia/Manila!\n";
    exit(1);
}

// Find Faculty User
$stmt = $db->query("SELECT id FROM users WHERE role = 'faculty' LIMIT 1");
$facultyId = (int)$stmt->fetchColumn();
if (!$facultyId) {
    echo "❌ FAILED: No faculty user found in database.\n";
    exit(1);
}

$createdActivityIds = [];

// Helper to execute endpoints in an isolated PHP process via proc_open
function runEndpointSubprocess(string $script, array $postData, int $facultyId): string {
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
    ob_start();
    try {
        include " . var_export($script, true) . ";
    } catch (Throwable \$e) {
        echo \$e->getMessage();
    }
    ";

    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open('php', $desc, $pipes);
    if (!is_resource($proc)) return '';

    fwrite($pipes[0], $code);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    return $out . $err;
}

function callProposalSave(array $postData, int $facultyId): array {
    $script = __DIR__ . '/../api/proposal-save.php';
    $output = runEndpointSubprocess($script, $postData, $facultyId);
    return ['output' => $output];
}

function callProposalUpdate(array $postData, int $facultyId): array {
    $script = __DIR__ . '/../api/proposal-update.php';
    $output = runEndpointSubprocess($script, $postData, $facultyId);
    return ['output' => $output];
}

$passCount = 0;
$failCount = 0;

function runTest(string $title, bool $condition, string $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] {$title}\n";
    } else {
        $failCount++;
        echo " [FAIL] {$title}\n";
    }
    if ($details) {
        echo "        {$details}\n";
    }
}

// -----------------------------------------------------------------------------
// TEST 1: Yesterday -> REJECTED
// -----------------------------------------------------------------------------
$countBefore = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Yesterday'")->fetchColumn();
$res1 = callProposalSave([
    'title' => 'Test Yesterday',
    'event_date' => $yesterdayManila,
    'source' => 'faculty',
    'action' => 'draft'
], $facultyId);
$countAfter = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Yesterday'")->fetchColumn();
runTest(
    "TEST 1: Yesterday ({$yesterdayManila}) -> REJECTED",
    ($countAfter === $countBefore),
    "DB rows inserted: 0 (Correctly rejected past date)"
);

// -----------------------------------------------------------------------------
// TEST 2: Earlier Date (7 days ago) -> REJECTED
// -----------------------------------------------------------------------------
$countBefore = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Earlier Date'")->fetchColumn();
$res2 = callProposalSave([
    'title' => 'Test Earlier Date',
    'event_date' => $earlierDate,
    'source' => 'faculty',
    'action' => 'draft'
], $facultyId);
$countAfter = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Earlier Date'")->fetchColumn();
runTest(
    "TEST 2: Earlier Date ({$earlierDate}) -> REJECTED",
    ($countAfter === $countBefore),
    "DB rows inserted: 0 (Correctly rejected earlier date)"
);

// -----------------------------------------------------------------------------
// TEST 3: Previous Month -> REJECTED
// -----------------------------------------------------------------------------
$countBefore = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Prev Month'")->fetchColumn();
$res3 = callProposalSave([
    'title' => 'Test Prev Month',
    'event_date' => $prevMonth,
    'source' => 'faculty',
    'action' => 'draft'
], $facultyId);
$countAfter = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Prev Month'")->fetchColumn();
runTest(
    "TEST 3: Previous Month ({$prevMonth}) -> REJECTED",
    ($countAfter === $countBefore),
    "DB rows inserted: 0 (Correctly rejected past month)"
);

// -----------------------------------------------------------------------------
// TEST 4: Previous Year -> REJECTED
// -----------------------------------------------------------------------------
$countBefore = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Prev Year'")->fetchColumn();
$res4 = callProposalSave([
    'title' => 'Test Prev Year',
    'event_date' => $prevYear,
    'source' => 'faculty',
    'action' => 'draft'
], $facultyId);
$countAfter = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Prev Year'")->fetchColumn();
runTest(
    "TEST 4: Previous Year ({$prevYear}) -> REJECTED",
    ($countAfter === $countBefore),
    "DB rows inserted: 0 (Correctly rejected past year)"
);

// -----------------------------------------------------------------------------
// TEST 5: Today -> REJECTED (Same day not permitted; rule is event_date > today)
// -----------------------------------------------------------------------------
$countBefore = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Today Date'")->fetchColumn();
$res5 = callProposalSave([
    'title' => 'Test Today Date',
    'event_date' => $todayManila,
    'source' => 'faculty',
    'action' => 'draft'
], $facultyId);
$countAfter = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Today Date'")->fetchColumn();
runTest(
    "TEST 5: Today ({$todayManila}) -> REJECTED",
    ($countAfter === $countBefore),
    "DB rows inserted: 0 (Faculty cannot select today; authoritative rule event_date > today verified)"
);

// -----------------------------------------------------------------------------
// TEST 6: Tomorrow -> ACCEPTED (Earliest allowable date)
// -----------------------------------------------------------------------------
$countBefore = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Tomorrow Date'")->fetchColumn();
$res6 = callProposalSave([
    'title' => 'Test Tomorrow Date',
    'event_date' => $tomorrowManila,
    'source' => 'faculty',
    'action' => 'draft'
], $facultyId);
$createdRow = $db->query("SELECT id, event_date FROM activities WHERE title = 'Test Tomorrow Date' ORDER BY id DESC LIMIT 1")->fetch();
$countAfter = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Tomorrow Date'")->fetchColumn();
if ($createdRow) $createdActivityIds[] = (int)$createdRow['id'];

runTest(
    "TEST 6: Tomorrow ({$tomorrowManila}) -> ACCEPTED",
    ($countAfter === $countBefore + 1 && $createdRow && $createdRow['event_date'] === $tomorrowManila),
    "DB row created successfully with Activity ID: " . ($createdRow['id'] ?? 'N/A')
);

// -----------------------------------------------------------------------------
// TEST 7: Future Date (10 days ahead) -> ACCEPTED
// -----------------------------------------------------------------------------
$countBefore = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Future Date'")->fetchColumn();
$res7 = callProposalSave([
    'title' => 'Test Future Date',
    'event_date' => $futureDate,
    'source' => 'faculty',
    'action' => 'draft'
], $facultyId);
$createdRow7 = $db->query("SELECT id, event_date FROM activities WHERE title = 'Test Future Date' ORDER BY id DESC LIMIT 1")->fetch();
$countAfter = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Future Date'")->fetchColumn();
if ($createdRow7) $createdActivityIds[] = (int)$createdRow7['id'];

runTest(
    "TEST 7: Future Date ({$futureDate}) -> ACCEPTED",
    ($countAfter === $countBefore + 1 && $createdRow7 && $createdRow7['event_date'] === $futureDate),
    "DB row created successfully with Activity ID: " . ($createdRow7['id'] ?? 'N/A')
);

// -----------------------------------------------------------------------------
// TEST 8: Direct POST Tampering with Today -> REJECTED Server-side
// -----------------------------------------------------------------------------
$countBefore = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Tampered Today Post'")->fetchColumn();
$res8 = callProposalSave([
    'title' => 'Tampered Today Post',
    'event_date' => $todayManila,
    'source' => 'faculty',
    'action' => 'submit'
], $facultyId);
$countAfter = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Tampered Today Post'")->fetchColumn();
runTest(
    "TEST 8: Direct POST Tampering with Today ({$todayManila}) -> REJECTED",
    ($countAfter === $countBefore),
    "Server-side PHP blocked submission regardless of client tampering"
);

// -----------------------------------------------------------------------------
// TEST 9: Direct POST Tampering with Past Schedule Date -> REJECTED
// -----------------------------------------------------------------------------
$countBefore = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Tampered Sched Date'")->fetchColumn();
$res9 = callProposalSave([
    'title' => 'Tampered Sched Date',
    'event_date' => $tomorrowManila,
    'sched_date' => [$yesterdayManila],
    'sched_event' => ['Sub event'],
    'source' => 'faculty',
    'action' => 'draft'
], $facultyId);
$countAfter = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Tampered Sched Date'")->fetchColumn();
runTest(
    "TEST 9: Direct POST Tampering with Past Schedule Date -> REJECTED",
    ($countAfter === $countBefore),
    "Server-side rejected past schedule item date"
);

// -----------------------------------------------------------------------------
// TEST 10: Proposal Update (Editable Section 1) with Today -> REJECTED
// -----------------------------------------------------------------------------
if (!empty($createdActivityIds)) {
    $targetActId = $createdActivityIds[0];
    callProposalUpdate([
        'activity_id' => $targetActId,
        'title' => 'Updated Title Attempt',
        'event_date' => $todayManila, // Attempt to set to today
        'action' => 'draft'
    ], $facultyId);

    $checkRow = $db->query("SELECT event_date FROM activities WHERE id = {$targetActId}")->fetch();
    runTest(
        "TEST 10: Proposal Update with Today's Date -> REJECTED",
        ($checkRow['event_date'] !== $todayManila),
        "Existing date unchanged; update to today blocked"
    );

    // TEST 11: Proposal Update with Valid Tomorrow/Future Date -> ACCEPTED
    $newFuture = date('Y-m-d', strtotime('+15 days'));
    callProposalUpdate([
        'activity_id' => $targetActId,
        'title' => 'Updated Future Title',
        'event_date' => $newFuture,
        'action' => 'draft'
    ], $facultyId);

    $checkRow2 = $db->query("SELECT title, event_date FROM activities WHERE id = {$targetActId}")->fetch();
    runTest(
        "TEST 11: Proposal Update with Valid Future Date ({$newFuture}) -> ACCEPTED",
        ($checkRow2['event_date'] === $newFuture && $checkRow2['title'] === 'Updated Future Title'),
        "Proposal updated successfully"
    );
}

// -----------------------------------------------------------------------------
// TEST 12: Proposal Update (Locked Section 1) -> Unrelated Fields Update Successfully
// -----------------------------------------------------------------------------
// Create a returned activity where Section 1 is NOT flagged (only materials flagged)
$stmtLock = $db->prepare("INSERT INTO activities (
    faculty_id, title, description, theme, venue, event_date, start_time, end_time,
    status, revision_sections
) VALUES (?, 'Locked Section 1 Activity', 'Desc', 'Theme', 'Gymnasium', '2026-08-01', '08:00:00', '12:00:00', 'returned_for_revision', ?)");
$stmtLock->execute([
    $facultyId,
    json_encode(['materials' => 'Please add more details about chairs'])
]);
$lockedActId = (int)$db->lastInsertId();
$createdActivityIds[] = $lockedActId;

callProposalUpdate([
    'activity_id' => $lockedActId,
    'title' => 'Locked Section 1 Activity (Tampered)', // Should not change
    'event_date' => '2026-08-01', // Historical date
    'mat_item' => ['New Plastic Chairs'],
    'mat_desc' => ['Monobloc white'],
    'mat_qty' => [100],
    'mat_cost' => [50],
    'action' => 'draft'
], $facultyId);

$lockedCheck = $db->query("SELECT event_date FROM activities WHERE id = {$lockedActId}")->fetch();
$matCheck = $db->query("SELECT COUNT(*) FROM materials WHERE activity_id = {$lockedActId}")->fetchColumn();

runTest(
    "TEST 12: Proposal Update with Locked Section 1 -> Other fields update without being blocked",
    ($lockedCheck['event_date'] === '2026-08-01' && (int)$matCheck > 0),
    "Materials updated ({$matCheck} item) and original historical date preserved without error"
);

// CLEANUP
echo "\n[CLEANUP] Cleaning up test activity records...\n";
foreach ($createdActivityIds as $cid) {
    $db->prepare("DELETE FROM materials WHERE activity_id = ?")->execute([$cid]);
    $db->prepare("DELETE FROM program_sequence WHERE activity_id = ?")->execute([$cid]);
    $db->prepare("DELETE FROM manpower WHERE activity_id = ?")->execute([$cid]);
    $db->prepare("DELETE FROM schedules WHERE activity_id = ?")->execute([$cid]);
    $db->prepare("DELETE FROM guidelines WHERE activity_id = ?")->execute([$cid]);
    $db->prepare("DELETE FROM faculty_tasks WHERE activity_id = ?")->execute([$cid]);
    $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$cid]);
}
echo "          Cleaned up " . count($createdActivityIds) . " test records.\n\n";

echo "========================================================================\n";
echo "SUMMARY: Total Tests: " . ($passCount + $failCount) . " | Passed: {$passCount} | Failed: {$failCount}\n";
echo "========================================================================\n";

if ($failCount > 0) {
    exit(1);
}
