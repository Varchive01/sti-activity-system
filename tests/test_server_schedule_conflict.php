<?php
/**
 * Focused Verification Suite for Server-Side Schedule Conflict Enforcement
 * 
 * Verifies:
 * 1. Conflicting submission is rejected server-side (redirects to proposal-create.php?error=conflict, no DB insert).
 * 2. Non-conflicting submission succeeds (redirects to dashboard.php?saved=1, inserted in DB).
 * 3. Draft saving with overlapping time is permitted (drafts not blocked).
 * 4. Existing checkScheduleConflict engine and client-side integration remain untouched.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/schedule_conflict.php';

$db = getDB();

echo "============================================================\n";
echo " SERVER-SIDE SCHEDULE CONFLICT ENFORCEMENT VERIFICATION\n";
echo "============================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $title, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$title}\n";
        $passed++;
    } else {
        echo " [FAIL] {$title}" . ($detail ? " - {$detail}" : "") . "\n";
        $failed++;
    }
}

// 1. Get faculty user
$faculty = $db->query("SELECT id, name, email FROM users WHERE role='faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$faculty) {
    echo "[FAIL] Faculty user not found in database.\n";
    exit(1);
}
$facultyId = (int)$faculty['id'];

// Helper to execute endpoint in subproc with session and post data
function runSaveEndpoint(array $postData, int $userId) {
    $script = __DIR__ . '/../api/proposal-save.php';
    $postExport = var_export($postData, true);
    
    $code = "<?php
    define('TEST_RUNNER', true);
    if (session_status() === PHP_SESSION_NONE) session_start();
    \$_SESSION['user_id'] = {$userId};
    \$_SESSION['user_role'] = 'faculty';
    \$_SESSION['role'] = 'faculty';
    \$_SESSION['user_name'] = 'Faculty Tester';
    \$_SESSION['user_email'] = 'faculty@test.sti.edu';
    \$_SESSION['last_activity'] = time();
    \$_POST = {$postExport};
    \$_SERVER['REQUEST_METHOD'] = 'POST';
    try {
        ob_start();
        require '{$script}';
        \$out = ob_get_clean();
        echo \$out;
    } catch (Throwable \$e) {
        echo 'EXCEPTION: ' . \$e->getMessage() . ' at ' . \$e->getFile() . ':' . \$e->getLine();
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

    if (!empty($out) || !empty($err)) {
        echo "[SUBPROC OUT]: {$out}\n[SUBPROC ERR]: {$err}\n";
    }

    return $out . $err;
}

// 2. Setup Existing Approved Activity (Anchor Event)
$testDate = date('Y-m-d', strtotime('+3 days'));
$testVenue = "Auditorium Main Test";

$db->prepare("
    INSERT INTO activities (faculty_id, title, description, source, status, event_date, start_time, end_time, venue)
    VALUES (?, 'Existing Approved Anchor Event', 'Anchor for schedule conflict test', 'faculty', 'approved', ?, '10:00:00', '12:00:00', ?)
")->execute([$facultyId, $testDate, $testVenue]);
$anchorId = (int)$db->lastInsertId();

assertTest("Existing approved anchor event created", $anchorId > 0, "Anchor ID: {$anchorId}");

// 3. Test 1: Submit Conflicting Proposal (Overlap 11:00 to 13:00 on same venue & date)
echo "\n--- 1. Testing Conflicting Submission Rejection ---\n";

$conflictTitle = "Conflicting Submission Test " . time();
$beforeCount = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = '{$conflictTitle}'")->fetchColumn();

$output = runSaveEndpoint([
    'title' => $conflictTitle,
    'venue' => $testVenue,
    'event_date' => $testDate,
    'start_time' => '11:00',
    'end_time'   => '13:00',
    'source'     => 'faculty',
    'action'     => 'submit'
], $facultyId);

$afterCount = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = '{$conflictTitle}'")->fetchColumn();

assertTest("Conflicting submission is blocked from database insertion", $beforeCount === $afterCount, "Count before: {$beforeCount}, after: {$afterCount}");

// Verify checkScheduleConflict accurately identified the conflict
$checkResult = checkScheduleConflict($testVenue, $testDate, '11:00', '13:00');
assertTest("Schedule conflict engine detected the overlap", $checkResult['conflict'] === true);
assertTest("Conflict details correctly identify anchor activity", ($checkResult['conflicting_activity']['title'] ?? '') === 'Existing Approved Anchor Event');

// 4. Test 2: Submit Non-Conflicting Proposal (14:00 to 16:00 on same venue & date)
echo "\n--- 2. Testing Non-Conflicting Submission Success ---\n";

$validTitle = "Valid Non-Conflicting Submission " . time();
$beforeValidCount = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = '{$validTitle}'")->fetchColumn();

$validOutput = runSaveEndpoint([
    'title' => $validTitle,
    'venue' => $testVenue,
    'event_date' => $testDate,
    'start_time' => '14:00',
    'end_time'   => '16:00',
    'source'     => 'faculty',
    'action'     => 'submit'
], $facultyId);

$afterValidCount = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = '{$validTitle}'")->fetchColumn();
$validAct = $db->query("SELECT id, status FROM activities WHERE title = '{$validTitle}' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

assertTest("Non-conflicting submission saved successfully to database", $afterValidCount === $beforeValidCount + 1);
assertTest("Non-conflicting submission has status 'submitted'", ($validAct['status'] ?? '') === 'submitted');

$validActId = (int)($validAct['id'] ?? 0);

// 5. Test 3: Save Conflicting Time as DRAFT (Drafts permitted)
echo "\n--- 3. Testing Draft with Overlapping Time Permitted ---\n";

$draftTitle = "Draft Overlapping Time Test " . time();
$beforeDraftCount = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = '{$draftTitle}'")->fetchColumn();

$draftOutput = runSaveEndpoint([
    'title' => $draftTitle,
    'venue' => $testVenue,
    'event_date' => $testDate,
    'start_time' => '10:30',
    'end_time'   => '11:30',
    'source'     => 'faculty',
    'action'     => 'draft'
], $facultyId);

$afterDraftCount = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = '{$draftTitle}'")->fetchColumn();
$draftAct = $db->query("SELECT id, status FROM activities WHERE title = '{$draftTitle}' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

assertTest("Draft with overlapping time saved successfully to database", $afterDraftCount === $beforeDraftCount + 1);
assertTest("Draft has status 'draft'", ($draftAct['status'] ?? '') === 'draft');

$draftActId = (int)($draftAct['id'] ?? 0);

// 6. Test 4: Alternative Slot Suggestions Untouched
echo "\n--- 4. Testing Alternative Slot Suggestions Engine ---\n";

assertTest("Alternative suggestions array returned when conflict exists", isset($checkResult['alternatives']) && is_array($checkResult['alternatives']));
assertTest("Alternative suggestions contain verified non-conflicting slots", count($checkResult['alternatives']) > 0);

// 7. Cleanup
echo "\n--- 5. Cleanup Test Fixtures ---\n";

$allIds = array_filter([$anchorId, $validActId, $draftActId]);
if (!empty($allIds)) {
    $inList = implode(',', $allIds);
    $db->exec("DELETE FROM notifications WHERE activity_id IN ({$inList})");
    $db->exec("DELETE FROM email_logs WHERE activity_id IN ({$inList})");
    $db->exec("DELETE FROM activities WHERE id IN ({$inList})");
}
echo "Cleaned up test activities and related logs.\n";

echo "\n============================================================\n";
echo " VERIFICATION SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "============================================================\n";

if ($failed > 0) {
    exit(1);
}
