<?php
/**
 * test_rule_based_schedule_conflict.php
 *
 * Automated verification test suite for rule-based Schedule Conflict Detection & Dark Mode Parity.
 * 100% independent of external AI services.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/schedule_conflict.php';

$db = getDB();

echo "=======================================================================\n";
echo "   RULE-BASED SCHEDULE CONFLICT & THEME PARITY VERIFICATION SUITE       \n";
echo "=======================================================================\n";

$passCount = 0;
$failCount = 0;

function assertScheduleTest(string $category, string $description, bool $condition, ?string $details = null): void
{
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        printf(" [PASS] | %-20s | %-45s\n", $category, $description);
    } else {
        $failCount++;
        printf(" [FAIL] | %-20s | %-45s | Details: %s\n", $category, $description, $details ?? 'Assertion failed');
    }
}

// ── SETUP: Seed a temporary approved activity for deterministic overlap testing ──
$testDate = date('Y-m-d', strtotime('+30 days'));
// Check if Sunday, shift to Monday
if (date('N', strtotime($testDate)) === '7') {
    $testDate = date('Y-m-d', strtotime($testDate . ' +1 day'));
}

$testVenue = 'Gymnasium';
$testStartTime = '09:00:00';
$testEndTime = '12:00:00';

// Ensure a faculty user exists
$user = $db->query("SELECT id FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$userId = (int)($user['id'] ?? 1);

// Remove any prior test activities for this slot
$db->prepare("DELETE FROM activities WHERE title = 'Automated Schedule Overlap Test Activity'")->execute();

$insertStmt = $db->prepare("
    INSERT INTO activities (
        title, faculty_id, venue, event_date, start_time, end_time, status, created_at, updated_at
    ) VALUES (
        'Automated Schedule Overlap Test Activity', ?, ?, ?, ?, ?, 'approved', NOW(), NOW()
    )
");
$insertStmt->execute([$userId, $testVenue, $testDate, $testStartTime, $testEndTime]);
$seededActivityId = (int)$db->lastInsertId();

try {
    // ── TEST 1: Exact Overlapping Schedule (Same Venue, Same Date, Overlapping Time) ──
    // Proposed: 10:00 to 14:00 (overlaps 09:00-12:00)
    $res1 = checkScheduleConflict($testVenue, $testDate, '10:00', '14:00', 0);
    assertScheduleTest(
        'Overlap Detection',
        'Direct time overlap in same venue flagged as conflict',
        $res1['conflict'] === true && !empty($res1['conflicting_activity']) && $res1['conflicting_activity']['title'] === 'Automated Schedule Overlap Test Activity'
    );

    // ── TEST 2: Enclosing Time Overlap (Proposed starts before and ends after) ──
    // Proposed: 08:00 to 13:00 (encloses 09:00-12:00)
    $res2 = checkScheduleConflict($testVenue, $testDate, '08:00', '13:00', 0);
    assertScheduleTest(
        'Overlap Detection',
        'Enclosing time span flagged as conflict',
        $res2['conflict'] === true
    );

    // ── TEST 3: Subset Time Overlap (Proposed inside existing) ──
    // Proposed: 09:30 to 11:30 (inside 09:00-12:00)
    $res3 = checkScheduleConflict($testVenue, $testDate, '09:30', '11:30', 0);
    assertScheduleTest(
        'Overlap Detection',
        'Contained subset time interval flagged as conflict',
        $res3['conflict'] === true
    );

    // ── TEST 4: Same Date & Venue, NON-OVERLAPPING Time (Afternoon Slot) ──
    // Proposed: 13:00 to 17:00 (Starts after 12:00)
    // CRUCIAL REQUIREMENT: Must NOT classify as conflict simply because it is on the same date!
    $res4 = checkScheduleConflict($testVenue, $testDate, '13:00', '17:00', 0);
    assertScheduleTest(
        'Non-Overlap Same Date',
        'Same date & venue but non-overlapping time is ALLOWED',
        $res4['conflict'] === false && ($res4['availability_confirmed'] ?? false) === true
    );

    // ── TEST 5: Same Date & Venue, NON-OVERLAPPING Time (Morning Slot) ──
    // Proposed: 06:00 to 08:30 (Ends before 09:00)
    $res5 = checkScheduleConflict($testVenue, $testDate, '06:00', '08:30', 0);
    assertScheduleTest(
        'Non-Overlap Same Date',
        'Earlier non-overlapping morning time is ALLOWED',
        $res5['conflict'] === false
    );

    // ── TEST 6: Same Date & Time, DIFFERENT Venue ──
    // Proposed: 09:00 to 12:00 at Auditorium (different venue from Gymnasium)
    $res6 = checkScheduleConflict('Auditorium', $testDate, '09:00', '12:00', 0);
    assertScheduleTest(
        'Different Venue',
        'Different venue on same date & time is ALLOWED',
        $res6['conflict'] === false
    );

    // ── TEST 7: Invalid Time Interval (End Time <= Start Time) ──
    // Proposed: 14:00 to 10:00
    $res7 = checkScheduleConflict($testVenue, $testDate, '14:00', '10:00', 0);
    assertScheduleTest(
        'Time Integrity',
        'End time earlier than start time flagged as invalid',
        $res7['conflict'] === true && ($res7['error_type'] ?? '') === 'invalid_time_range'
    );

    // Proposed: 10:00 to 10:00 (zero duration)
    $res7b = checkScheduleConflict($testVenue, $testDate, '10:00', '10:00', 0);
    assertScheduleTest(
        'Time Integrity',
        'Equal start and end time flagged as invalid',
        $res7b['conflict'] === true && ($res7b['error_type'] ?? '') === 'invalid_time_range'
    );

    // ── TEST 8: Missing Required Scheduling Info ──
    $res8 = checkScheduleConflict('', $testDate, '10:00', '12:00', 0);
    assertScheduleTest(
        'Completeness Check',
        'Missing venue returns preliminary status without crashing',
        ($res8['is_preliminary'] ?? false) === true
    );

    $res8b = checkScheduleConflict($testVenue, '', '10:00', '12:00', 0);
    assertScheduleTest(
        'Completeness Check',
        'Missing date returns preliminary status',
        ($res8b['is_preliminary'] ?? false) === true
    );

    // ── TEST 9: Edit Mode Self-Exclusion ──
    // When editing the same activity, it must not conflict with itself
    $res9 = checkScheduleConflict($testVenue, $testDate, '09:00', '12:00', $seededActivityId);
    assertScheduleTest(
        'Edit Mode Exclusion',
        'Activity does not conflict with itself when excluded by ID',
        $res9['conflict'] === false
    );

    // ── TEST 10: Deterministic Alternative Slot Suggestions ──
    $alts = getDeterministicAlternativeSlots($db, $testVenue, $testDate, '09:00', '12:00', 0);
    assertScheduleTest(
        'Alternative Slots',
        'Deterministic alternatives generated without external AI',
        is_array($alts) && count($alts) > 0 && !empty($alts[0]['venue']) && !empty($alts[0]['date'])
    );

    // ── TEST 11: Zero Gemini / AI References in schedule_conflict.php ──
    $fileContent = file_get_contents(__DIR__ . '/../includes/schedule_conflict.php');
    assertScheduleTest(
        'Zero AI Dependency',
        'No Google Gemini API endpoints or cURL in schedule_conflict.php',
        strpos($fileContent, 'generativelanguage.googleapis.com') === false &&
        strpos($fileContent, 'getGeminiApiKeySecure') === false &&
        strpos($fileContent, 'curl_exec') === false
    );

    // ── TEST 12: formatCollaboratorRole Null-Safety (No 500 TypeError) ──
    $rNull = formatCollaboratorRole(null);
    $rEmpty = formatCollaboratorRole('');
    $rOwner = formatCollaboratorRole('owner');
    $rCoAuthor = formatCollaboratorRole('co_author');
    assertScheduleTest(
        'Role Null-Safety',
        'formatCollaboratorRole safely handles null without PHP 8 TypeError',
        $rNull === 'Contributor' && $rEmpty === 'Contributor' && $rOwner === 'Proposal Owner' && $rCoAuthor === 'Co-Author'
    );

    // ── TEST 13: renderAiEvaluationQuestions Theme Independence ──
    $jsonSample = json_encode([
        ['question' => 'How clearly were objectives stated?', 'type' => 'rating'],
        ['question' => 'What suggestions do you have for next time?', 'type' => 'open_ended']
    ]);
    $htmlOutput = renderAiEvaluationQuestions($jsonSample);
    assertScheduleTest(
        'Evaluation Questions',
        'Questions rendered using clean semantic CSS classes without inline white backgrounds',
        strpos($htmlOutput, 'class="ai-question-item"') !== false &&
        strpos($htmlOutput, 'background: var(--bg-card-elevated, var(--bg-card, #f8f9fa))') === false &&
        strpos($htmlOutput, 'Question 1') !== false &&
        strpos($htmlOutput, 'Question 2') !== false
    );

} finally {
    // ── CLEANUP: Delete seeded activity ──
    $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$seededActivityId]);
}

echo "-----------------------------------------------------------------------\n";
printf("Summary: %d Passed, %d Failed (Total: %d)\n", $passCount, $failCount, $passCount + $failCount);
echo "=======================================================================\n";

if ($failCount > 0) {
    exit(1);
}
