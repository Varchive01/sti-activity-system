<?php
/**
 * test_time_validation.php
 *
 * Comprehensive test suite for Start Time and End Time validation.
 *
 * Requirements tested:
 * 1. Business Rule:
 *    - End Time strictly later than Start Time
 *    - Equal times are invalid
 *    - Earlier End Time is invalid
 *    - Overnight activities are NOT supported (11:00 PM -> 12:00 AM invalid)
 *    - Normalize times to minutes since midnight (12:00 AM = 0, 12:00 PM = 720)
 * 2. Draft Behavior Preservation:
 *    - Incomplete draft: both empty -> VALID
 *    - Incomplete draft: only one provided -> VALID (preserves draft capability)
 *    - Draft with both provided -> validates end > start
 *    - Submit: Start time and End time required, end > start enforced
 * 3. Schedule Rows POST Structure & Independent Row Validation:
 *    - Scalar start_time and end_time verified
 *    - Multi-row schedule structures validated independently
 * 4. Boundary Tests:
 *    - 11:59 AM -> 12:00 PM = VALID (719 -> 720)
 *    - 12:00 AM -> 11:59 PM = VALID (0 -> 1439)
 * 5. Format Normalization Consistency:
 *    - 12:00 AM = 0 minutes
 *    - 12:00 PM = 720 minutes
 *    - "10:00 am", "10:00 AM", "10:00", and "10:00:00" all = 600 minutes
 * 6. Integration Endpoints:
 *    - api/proposal-save.php (Draft vs Submit)
 *    - api/proposal-update.php (Draft vs Submit)
 *    - api/check-schedule-conflict.php (HTTP 400 rejection on invalid order)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "========================================================================\n";
echo "  STI ACTIVITY SYSTEM - START TIME / END TIME VALIDATION TEST SUITE\n";
echo "========================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $name, bool $passed, string $info = '') {
    global $passCount, $failCount;
    if ($passed) {
        $passCount++;
        echo " [PASS] {$name}\n";
    } else {
        $failCount++;
        echo " [FAIL] {$name}\n";
    }
    if ($info) {
        echo "        {$info}\n";
    }
}

// -----------------------------------------------------------------------------
// SECTION 1: Time Normalization to Minutes Since Midnight
// -----------------------------------------------------------------------------
echo "\n--- SECTION 1: Time Normalization to Minutes ---\n";

$m12am = parseTimeToMinutes("12:00 AM");
assertTest("1.1 '12:00 AM' parses to 0 minutes", $m12am === 0, "Got: " . var_export($m12am, true));

$m12pm = parseTimeToMinutes("12:00 PM");
assertTest("1.2 '12:00 PM' parses to 720 minutes", $m12pm === 720, "Got: " . var_export($m12pm, true));

$m1159am = parseTimeToMinutes("11:59 AM");
assertTest("1.3 '11:59 AM' parses to 719 minutes", $m1159am === 719, "Got: " . var_export($m1159am, true));

$m1159pm = parseTimeToMinutes("11:59 PM");
assertTest("1.4 '11:59 PM' parses to 1439 minutes", $m1159pm === 1439, "Got: " . var_export($m1159pm, true));

$n1 = parseTimeToMinutes("10:00 am");
$n2 = parseTimeToMinutes("10:00 AM");
$n3 = parseTimeToMinutes("10:00");
$n4 = parseTimeToMinutes("10:00:00");
$consistent = ($n1 === 600 && $n2 === 600 && $n3 === 600 && $n4 === 600);
assertTest("1.5 Consistency: '10:00 am', '10:00 AM', '10:00', '10:00:00' all equal 600",
    $consistent,
    "Got: [10:00 am => {$n1}, 10:00 AM => {$n2}, 10:00 => {$n3}, 10:00:00 => {$n4}]");

assertTest("1.6 Null or empty string returns null",
    parseTimeToMinutes(null) === null && parseTimeToMinutes("") === null && parseTimeToMinutes("   ") === null);

assertTest("1.7 Invalid time formats return null",
    parseTimeToMinutes("25:00") === null && parseTimeToMinutes("10:60") === null && parseTimeToMinutes("invalid") === null);

// -----------------------------------------------------------------------------
// SECTION 2: Boundary Tests & Business Rules
// -----------------------------------------------------------------------------
echo "\n--- SECTION 2: Boundary Tests & Business Rules ---\n";

// 2.1 Boundary: 11:59 AM -> 12:00 PM = VALID
$b1 = validateScheduleTimeRange("11:59 AM", "12:00 PM", true);
assertTest("2.1 Boundary: 11:59 AM -> 12:00 PM is VALID",
    $b1['valid'] === true && $b1['start_minutes'] === 719 && $b1['end_minutes'] === 720,
    "719 -> 720 minutes");

// 2.2 Boundary: 12:00 AM -> 11:59 PM = VALID
$b2 = validateScheduleTimeRange("12:00 AM", "11:59 PM", true);
assertTest("2.2 Boundary: 12:00 AM -> 11:59 PM is VALID (Full day span)",
    $b2['valid'] === true && $b2['start_minutes'] === 0 && $b2['end_minutes'] === 1439,
    "0 -> 1439 minutes");

// 2.3 Equal times are invalid
$eq1 = validateScheduleTimeRange("10:00 AM", "10:00 AM", true);
assertTest("2.3 Equal times (10:00 AM -> 10:00 AM) are INVALID",
    $eq1['valid'] === false && $eq1['code'] === 'invalid_order',
    "Error: " . ($eq1['error'] ?? ''));

$eq2 = validateScheduleTimeRange("12:00 AM", "12:00 AM", true);
assertTest("2.4 Equal times (12:00 AM -> 12:00 AM) are INVALID",
    $eq2['valid'] === false && $eq2['code'] === 'invalid_order');

$eq3 = validateScheduleTimeRange("12:00 PM", "12:00 PM", true);
assertTest("2.5 Equal times (12:00 PM -> 12:00 PM) are INVALID",
    $eq3['valid'] === false && $eq3['code'] === 'invalid_order');

// 2.6 Earlier end time is invalid
$earlier = validateScheduleTimeRange("10:00 AM", "09:00 AM", true);
assertTest("2.6 Earlier end time (10:00 AM -> 09:00 AM) is INVALID",
    $earlier['valid'] === false && $earlier['code'] === 'invalid_order');

// 2.7 Overnight activities are NOT supported (11:00 PM -> 12:00 AM)
$overnight = validateScheduleTimeRange("11:00 PM", "12:00 AM", true);
assertTest("2.7 Overnight schedule (11:00 PM -> 12:00 AM) is INVALID (1380 <= 0)",
    $overnight['valid'] === false && $overnight['code'] === 'invalid_order',
    "Start: 1380 min, End: 0 min (strictly rejected)");

// 2.8 Valid minimal 1-minute span
$oneMin = validateScheduleTimeRange("10:00 AM", "10:01 AM", true);
assertTest("2.8 Minimal 1-minute span (10:00 AM -> 10:01 AM) is VALID",
    $oneMin['valid'] === true && $oneMin['start_minutes'] === 600 && $oneMin['end_minutes'] === 601);

// -----------------------------------------------------------------------------
// SECTION 3: Draft Behavior Preservation vs Submit Requirement
// -----------------------------------------------------------------------------
echo "\n--- SECTION 3: Draft Behavior Preservation vs Submit ---\n";

// 3.1 Draft with both empty
$draftBothEmpty = validateScheduleTimeRange("", "", false);
assertTest("3.1 Draft: Both times empty -> VALID (allows saving incomplete draft)",
    $draftBothEmpty['valid'] === true);

// 3.2 Draft with only start time
$draftOnlyStart = validateScheduleTimeRange("09:00 AM", "", false);
assertTest("3.2 Draft: Only start time provided -> VALID (preserves existing draft capability)",
    $draftOnlyStart['valid'] === true && $draftOnlyStart['start_minutes'] === 540);

// 3.3 Draft with only end time
$draftOnlyEnd = validateScheduleTimeRange("", "05:00 PM", false);
assertTest("3.3 Draft: Only end time provided -> VALID (preserves existing draft capability)",
    $draftOnlyEnd['valid'] === true && $draftOnlyEnd['end_minutes'] === 1020);

// 3.4 Draft with both provided and valid order
$draftBothValid = validateScheduleTimeRange("08:00 AM", "05:00 PM", false);
assertTest("3.4 Draft: Both provided and valid order -> VALID",
    $draftBothValid['valid'] === true && $draftBothValid['start_minutes'] === 480 && $draftBothValid['end_minutes'] === 1020);

// 3.5 Draft with both provided and invalid order
$draftBothInvalidOrder = validateScheduleTimeRange("05:00 PM", "08:00 AM", false);
assertTest("3.5 Draft: Both provided and invalid order -> INVALID (end > start strictly enforced)",
    $draftBothInvalidOrder['valid'] === false && $draftBothInvalidOrder['code'] === 'invalid_order');

// 3.6 Submit with both empty
$submitBothEmpty = validateScheduleTimeRange("", "", true);
assertTest("3.6 Submit: Both times empty -> REJECTED (missing_time)",
    $submitBothEmpty['valid'] === false && $submitBothEmpty['code'] === 'missing_time');

// 3.7 Submit with missing start
$submitMissingStart = validateScheduleTimeRange("", "05:00 PM", true);
assertTest("3.7 Submit: Missing start time -> REJECTED (missing_start)",
    $submitMissingStart['valid'] === false && $submitMissingStart['code'] === 'missing_start');

// 3.8 Submit with missing end
$submitMissingEnd = validateScheduleTimeRange("08:00 AM", "", true);
assertTest("3.8 Submit: Missing end time -> REJECTED (missing_end)",
    $submitMissingEnd['valid'] === false && $submitMissingEnd['code'] === 'missing_end');

// 3.9 Submit with valid times
$submitValid = validateScheduleTimeRange("08:00 AM", "05:00 PM", true);
assertTest("3.9 Submit: Valid times -> ACCEPTED",
    $submitValid['valid'] === true && $submitValid['start_minutes'] === 480 && $submitValid['end_minutes'] === 1020);

// -----------------------------------------------------------------------------
// SECTION 4: Independent Schedule Rows Validation
// -----------------------------------------------------------------------------
echo "\n--- SECTION 4: Independent Schedule Rows Validation ---\n";

$rowsValid = [
    ['start_time' => '08:00 AM', 'end_time' => '10:00 AM'],
    ['start_time' => '10:30 AM', 'end_time' => '12:00 PM'],
    ['start_time' => '01:00 PM', 'end_time' => '05:00 PM'],
];
$rCheck1 = validateScheduleRows($rowsValid, true);
assertTest("4.1 Multiple valid schedule rows -> ALL VALID",
    $rCheck1['valid'] === true && count($rCheck1['rows']) === 3);

$rowsWithInvalid = [
    ['start_time' => '08:00 AM', 'end_time' => '10:00 AM'],
    ['start_time' => '02:00 PM', 'end_time' => '01:00 PM'], // invalid order
    ['start_time' => '03:00 PM', 'end_time' => '05:00 PM'],
];
$rCheck2 = validateScheduleRows($rowsWithInvalid, true);
assertTest("4.2 Multiple schedule rows with one invalid -> Row 2 rejected, independent tracking",
    $rCheck2['valid'] === false &&
    $rCheck2['rows'][0]['valid'] === true &&
    $rCheck2['rows'][1]['valid'] === false &&
    $rCheck2['rows'][1]['code'] === 'invalid_order' &&
    $rCheck2['rows'][2]['valid'] === true);

// -----------------------------------------------------------------------------
// SECTION 5: Endpoints Integration Testing
// -----------------------------------------------------------------------------
echo "\n--- SECTION 5: Endpoints Integration Testing ---\n";

$db = getDB();
$stmt = $db->query("SELECT id FROM users WHERE role = 'faculty' LIMIT 1");
$facultyId = (int)$stmt->fetchColumn();

function runEndpointSubproc(string $script, array $postData, int $facultyId): string {
    $code = "<?php
    define('TEST_RUNNER', true);
    if (session_status() === PHP_SESSION_NONE) session_start();
    \$_SESSION['user_id'] = {$facultyId};
    \$_SESSION['user_name'] = 'Test Faculty';
    \$_SESSION['user_email'] = 'faculty@sti.edu';
    \$_SESSION['role'] = 'faculty';
    \$_SESSION['user_role'] = 'faculty';
    \$_SESSION['last_activity'] = time();
    \$_SERVER['REQUEST_METHOD'] = 'POST';
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

$tomorrow = date('Y-m-d', strtotime('+1 day'));

// 5.1 api/proposal-save.php: Draft with empty times -> ALLOWED
$saveScript = __DIR__ . '/../api/proposal-save.php';
$beforeCount = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Draft Empty Times'")->fetchColumn();
runEndpointSubproc($saveScript, [
    'title' => 'Test Draft Empty Times',
    'event_date' => $tomorrow,
    'start_time' => '',
    'end_time' => '',
    'source' => 'faculty',
    'action' => 'draft'
], $facultyId);
$afterCount = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Draft Empty Times'")->fetchColumn();
assertTest("5.1 proposal-save.php: Draft with empty times saved successfully",
    $afterCount === $beforeCount + 1,
    "Inserted: " . ($afterCount - $beforeCount));

// 5.2 api/proposal-save.php: Draft with invalid order (10:00 -> 09:00) -> BLOCKED
$beforeCount = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Draft Bad Time'")->fetchColumn();
runEndpointSubproc($saveScript, [
    'title' => 'Test Draft Bad Time',
    'event_date' => $tomorrow,
    'start_time' => '10:00',
    'end_time' => '09:00',
    'source' => 'faculty',
    'action' => 'draft'
], $facultyId);
$afterCount = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Draft Bad Time'")->fetchColumn();
assertTest("5.2 proposal-save.php: Draft with invalid time order rejected",
    $afterCount === $beforeCount,
    "Correctly prevented saving draft with invalid time order");

// 5.3 api/proposal-save.php: Submit with missing times -> BLOCKED
$beforeCount = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Submit Missing Time'")->fetchColumn();
runEndpointSubproc($saveScript, [
    'title' => 'Test Submit Missing Time',
    'event_date' => $tomorrow,
    'start_time' => '',
    'end_time' => '',
    'source' => 'faculty',
    'action' => 'submit'
], $facultyId);
$afterCount = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Submit Missing Time'")->fetchColumn();
assertTest("5.3 proposal-save.php: Submit with missing times rejected",
    $afterCount === $beforeCount);

// 5.4 api/proposal-save.php: Submit with invalid order (11:00 PM -> 12:00 AM) -> BLOCKED
$beforeCount = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Submit Overnight'")->fetchColumn();
runEndpointSubproc($saveScript, [
    'title' => 'Test Submit Overnight',
    'event_date' => $tomorrow,
    'start_time' => '23:00',
    'end_time' => '00:00',
    'source' => 'faculty',
    'action' => 'submit'
], $facultyId);
$afterCount = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Submit Overnight'")->fetchColumn();
assertTest("5.4 proposal-save.php: Submit with overnight/invalid order (23:00 -> 00:00) rejected",
    $afterCount === $beforeCount);

// 5.5 api/proposal-save.php: Submit with valid times -> ACCEPTED
$beforeCount = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Submit Valid Times'")->fetchColumn();
runEndpointSubproc($saveScript, [
    'title' => 'Test Submit Valid Times',
    'event_date' => $tomorrow,
    'start_time' => '09:00',
    'end_time' => '17:00',
    'source' => 'faculty',
    'action' => 'submit'
], $facultyId);
$afterCount = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Submit Valid Times'")->fetchColumn();
assertTest("5.5 proposal-save.php: Submit with valid times (09:00 -> 17:00) accepted",
    $afterCount === $beforeCount + 1);

// 5.6 api/proposal-update.php: Update with invalid time order -> BLOCKED
$updateScript = __DIR__ . '/../api/proposal-update.php';
// Create an activity to update
$stmt = $db->prepare("INSERT INTO activities (faculty_id, title, event_date, start_time, end_time, status) VALUES (?, 'Test Update Time', ?, '08:00', '12:00', 'draft')");
$stmt->execute([$facultyId, $tomorrow]);
$updateActId = (int)$db->lastInsertId();

runEndpointSubproc($updateScript, [
    'activity_id' => $updateActId,
    'title' => 'Test Update Time',
    'event_date' => $tomorrow,
    'start_time' => '14:00',
    'end_time' => '13:00',
    'action' => 'draft'
], $facultyId);
$currTimes = $db->query("SELECT start_time, end_time FROM activities WHERE id = {$updateActId}")->fetch(PDO::FETCH_ASSOC);
assertTest("5.6 proposal-update.php: Draft update with invalid order blocked (times unchanged)",
    $currTimes['start_time'] === '08:00:00' && $currTimes['end_time'] === '12:00:00');

// 5.7 api/proposal-update.php: Update draft with empty times -> ALLOWED
runEndpointSubproc($updateScript, [
    'activity_id' => $updateActId,
    'title' => 'Test Update Time',
    'event_date' => $tomorrow,
    'start_time' => '',
    'end_time' => '',
    'action' => 'draft'
], $facultyId);
$currTimesEmpty = $db->query("SELECT start_time, end_time FROM activities WHERE id = {$updateActId}")->fetch(PDO::FETCH_ASSOC);
assertTest("5.7 proposal-update.php: Draft update with empty times allowed (times set to null)",
    $currTimesEmpty['start_time'] === null && $currTimesEmpty['end_time'] === null);

// 5.8 api/check-schedule-conflict.php: Invalid time range returns HTTP 400
$conflictScript = __DIR__ . '/../api/check-schedule-conflict.php';
$conflictOut = runEndpointSubproc($conflictScript, [
    'venue' => 'STI Auditorium',
    'event_date' => $tomorrow,
    'start_time' => '14:00',
    'end_time' => '13:00'
], $facultyId);
$conflictJson = json_decode($conflictOut, true);
assertTest("5.8 check-schedule-conflict.php rejects invalid time range with error",
    is_array($conflictJson) &&
    isset($conflictJson['error']) &&
    $conflictJson['code'] === 'invalid_order',
    "Response: " . trim($conflictOut));

// -----------------------------------------------------------------------------
// CLEANUP
// -----------------------------------------------------------------------------
$db->exec("DELETE FROM activities WHERE title LIKE 'Test Draft%' OR title LIKE 'Test Submit%'");

echo "\n========================================================================\n";
echo "SUMMARY: Total: " . ($passCount + $failCount) . " | Passed: {$passCount} | Failed: {$failCount}\n";
echo "========================================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
