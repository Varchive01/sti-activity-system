<?php
/**
 * Test Suite: Dean Accomplishment / Performance Report Task Progress Integration
 *
 * Verifies:
 * 1. Open Dean report for an activity with tasks (HTTP 200)
 * 2. All assigned tasks appear in the "Task & Performance Summary" section
 * 3. Completion percentages match Task Monitoring logic
 * 4. Delayed / Timing status matches Task Monitoring logic (Delayed, At Risk, On Track, Completed)
 * 5. Spearheading Department, Committee, Member values match existing data
 * 6. Summary totals are accurate:
 *    - Total Tasks
 *    - Completed
 *    - In Progress
 *    - Delayed
 *    - Overall Completion %
 * 7. Activity with no tasks displays a clean empty state
 * 8. Access control: Dean can view the report; unauthenticated redirected
 * 9. CSV export contains the Task & Performance Summary section and totals
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();
$baseUrl = 'http://localhost/sti-activity-system';

function makeRequest(string $url, string $method = 'GET', array $data = [], ?string $cookie = null): array {
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15
    ];

    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
    }

    if ($cookie) {
        $opts[CURLOPT_COOKIE] = $cookie;
    }

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerStr = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $headerStr, $matches);
    $cookies = $matches[1] ?? [];

    return [
        'code' => $httpCode,
        'headers' => $headerStr,
        'body' => $body,
        'cookies' => $cookies
    ];
}

function loginUser(string $baseUrl, string $email, string $password): string {
    $res = makeRequest("{$baseUrl}/auth/login.php", 'POST', [
        'email' => $email,
        'password' => $password
    ]);

    if (!empty($res['cookies'])) {
        return end($res['cookies']);
    }
    return '';
}

$passedTests = 0;
$failedTests = 0;

function assertCondition(string $name, bool $condition, string $details = ""): void {
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
echo "   DEAN REPORT TASK & PERFORMANCE SUMMARY TEST SUITE        \n";
echo "============================================================\n\n";

$deanCookie = loginUser($baseUrl, 'dean@sti.edu', 'password');
assertCondition("Dean authenticated with active session", !empty($deanCookie));

// 1. Fetch test users
$facultyUser = $db->query("SELECT id, name, role, department FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$memberUser = $db->query("SELECT id, name, role FROM users WHERE role = 'faculty' AND id != {$facultyUser['id']} LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$memberUser) {
    $memberUser = $db->query("SELECT id, name, role FROM users WHERE id != {$facultyUser['id']} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}

$facultyId = (int)$facultyUser['id'];
$memberId = (int)$memberUser['id'];

// Set proponent faculty department for testing
$testDept = 'Information Technology';
$db->prepare("UPDATE users SET department = ? WHERE id = ?")->execute([$testDept, $facultyId]);

// 2. Create test activities:
// Activity A: with tasks
$stmt = $db->prepare("
    INSERT INTO activities (title, faculty_id, event_date, venue, status, target_participants, source)
    VALUES (?, ?, '2026-11-25', 'STI Gym', 'completed', 200, 'faculty')
");
$stmt->execute(['Test Annual Tech Summit with Tasks', $facultyId]);
$activityWithTasksId = (int)$db->lastInsertId();

// Activity B: without tasks (empty state)
$stmt->execute(['Test Activity Empty Tasks', $facultyId]);
$activityNoTasksId = (int)$db->lastInsertId();

echo "Created Test Activities: With Tasks ID = {$activityWithTasksId}, Empty ID = {$activityNoTasksId}\n";

// 3. Create test tasks for Activity A
$today = date('Y-m-d');
$pastDate = date('Y-m-d', strtotime('-5 days'));
$futureDate = date('Y-m-d', strtotime('+10 days'));
$soonDate = date('Y-m-d', strtotime('+2 days')); // Within 3 days -> At Risk

// Task 1: Completed task (100%)
$task1Stmt = $db->prepare("
    INSERT INTO faculty_tasks (activity_id, assigned_user_id, committee, task_title, task_description, due_date, status, completion_pct, created_by)
    VALUES (?, ?, 'Program Committee', 'Prepare Event Flow', 'Finalize stage timeline', ?, 'Completed', 100, ?)
");
$task1Stmt->execute([$activityWithTasksId, $memberId, $pastDate, $facultyId]);

// Task 2: In Progress task (50%) - On Track
$task2Stmt = $db->prepare("
    INSERT INTO faculty_tasks (activity_id, assigned_user_id, committee, task_title, task_description, due_date, status, completion_pct, created_by)
    VALUES (?, ?, 'Logistics Committee', 'Arrange Venue Audio', 'Setup microphones', ?, 'In Progress', 50, ?)
");
$task2Stmt->execute([$activityWithTasksId, $memberId, $futureDate, $facultyId]);

// Task 3: In Progress task (30%) - At Risk (due in 2 days)
$task3Stmt = $db->prepare("
    INSERT INTO faculty_tasks (activity_id, assigned_user_id, committee, task_title, task_description, due_date, status, completion_pct, created_by)
    VALUES (?, ?, 'Technical Committee', 'Configure Streaming', 'Test OBS feeds', ?, 'In Progress', 30, ?)
");
$task3Stmt->execute([$activityWithTasksId, $memberId, $soonDate, $facultyId]);

// Task 4: Delayed task (overdue & incomplete) - 25%
$task4Stmt = $db->prepare("
    INSERT INTO faculty_tasks (activity_id, assigned_user_id, committee, task_title, task_description, due_date, status, completion_pct, created_by)
    VALUES (?, ?, 'Marketing Committee', 'Print Banners', 'Deliver roll-up banners', ?, 'In Progress', 25, ?)
");
$task4Stmt->execute([$activityWithTasksId, $memberId, $pastDate, $facultyId]);

echo "Created 4 Test Tasks across 4 committees.\n\n";

// -------------------------------------------------------------
// TEST 1: Dean opens report for activity with tasks
// -------------------------------------------------------------
echo "--- 1. Dean Accomplishment / Performance Report Load ---\n";
$reportUrl = "{$baseUrl}/dean/generate-report.php?activity_id={$activityWithTasksId}";
$res = makeRequest($reportUrl, 'GET', [], $deanCookie);

assertCondition("Dean can load report for activity with tasks (HTTP 200)", $res['code'] === 200, "Code: " . $res['code']);
assertCondition("Task & Performance Summary section exists in DOM", str_contains($res['body'], 'id="taskPerformanceSummarySection"'));
assertCondition("Section heading 'Task & Performance Summary' rendered", str_contains($res['body'], 'Task &amp; Performance Summary') || str_contains($res['body'], 'Task & Performance Summary'));
assertCondition("Task & Performance table exists in DOM", str_contains($res['body'], 'id="taskPerformanceTable"'));

// -------------------------------------------------------------
// TEST 2: Confirm all 9 table columns exist
// -------------------------------------------------------------
echo "\n--- 2. Required 9 Table Columns Verification ---\n";
$requiredHeaders = [
    'Activity',
    'Spearheading Department',
    'Committee',
    'Member',
    'Task',
    'Due Date',
    'Completion %',
    'Status',
    'Timing'
];
foreach ($requiredHeaders as $header) {
    assertCondition("Table column header '{$header}' present", str_contains($res['body'], "<th>{$header}</th>"));
}

// -------------------------------------------------------------
// TEST 3: Confirm task rows and data accuracy
// -------------------------------------------------------------
echo "\n--- 3. Task Rows & Assigned Data Accuracy ---\n";
assertCondition("Task 1 'Prepare Event Flow' present", str_contains($res['body'], 'Prepare Event Flow'));
assertCondition("Task 2 'Arrange Venue Audio' present", str_contains($res['body'], 'Arrange Venue Audio'));
assertCondition("Task 3 'Configure Streaming' present", str_contains($res['body'], 'Configure Streaming'));
assertCondition("Task 4 'Print Banners' present", str_contains($res['body'], 'Print Banners'));

assertCondition("Spearheading Department 'Information Technology' present", str_contains($res['body'], 'Information Technology'));
assertCondition("Committee 'Program Committee' present", str_contains($res['body'], 'Program Committee'));
assertCondition("Committee 'Logistics Committee' present", str_contains($res['body'], 'Logistics Committee'));
assertCondition("Committee 'Technical Committee' present", str_contains($res['body'], 'Technical Committee'));
assertCondition("Committee 'Marketing Committee' present", str_contains($res['body'], 'Marketing Committee'));
assertCondition("Assigned member '{$memberUser['name']}' present", str_contains($res['body'], htmlspecialchars($memberUser['name'])));

// -------------------------------------------------------------
// TEST 4: Completion percentage and Timing status parity
// -------------------------------------------------------------
echo "\n--- 4. Completion % and Timing Status Parity ---\n";
assertCondition("Task 1 completion percentage shows 100%", str_contains($res['body'], '100%'));
assertCondition("Task 2 completion percentage shows 50%", str_contains($res['body'], '50%'));
assertCondition("Task 3 completion percentage shows 30%", str_contains($res['body'], '30%'));
assertCondition("Task 4 completion percentage shows 25%", str_contains($res['body'], '25%'));

assertCondition("Completed timing badge present", str_contains($res['body'], 'Completed'));
assertCondition("On Track timing badge present", str_contains($res['body'], 'On Track'));
assertCondition("At Risk timing badge present (due within 3 days)", str_contains($res['body'], 'At Risk'));
assertCondition("Delayed (Overdue) timing badge present for past due task", str_contains($res['body'], 'Delayed'));

// -------------------------------------------------------------
// TEST 5: Compact Summary Totals Verification
// -------------------------------------------------------------
echo "\n--- 5. Compact Summary Totals Verification ---\n";
// Total tasks = 4
// Completed = 1
// In Progress = 3
// Delayed = 1
// Overall completion % = round((100 + 50 + 30 + 25) / 4) = round(205 / 4) = 51%
assertCondition("Summary Total Tasks label and value 4 present", str_contains($res['body'], 'Total Tasks') && str_contains($res['body'], '>4<'));
assertCondition("Summary Completed label and value 1 present", str_contains($res['body'], 'Completed') && str_contains($res['body'], '>1<'));
assertCondition("Summary In Progress label and value 3 present", str_contains($res['body'], 'In Progress') && str_contains($res['body'], '>3<'));
assertCondition("Summary Delayed label and value 1 present", str_contains($res['body'], 'Delayed') && str_contains($res['body'], '>1<'));
assertCondition("Summary Overall Completion % calculated correctly (51%)", str_contains($res['body'], '51%'));

// -------------------------------------------------------------
// TEST 6: Activity with No Tasks - Clean Empty State
// -------------------------------------------------------------
echo "\n--- 6. Clean Empty State (Activity with No Tasks) ---\n";
$emptyReportUrl = "{$baseUrl}/dean/generate-report.php?activity_id={$activityNoTasksId}";
$emptyRes = makeRequest($emptyReportUrl, 'GET', [], $deanCookie);

assertCondition("Dean can load report for activity with no tasks (HTTP 200)", $emptyRes['code'] === 200);
assertCondition("Task & Performance Summary section exists for empty activity", str_contains($emptyRes['body'], 'id="taskPerformanceSummarySection"'));
assertCondition("Clean empty fallback message displayed", str_contains($emptyRes['body'], 'No task assignments or progress records recorded for this activity'));
assertCondition("Empty summary Total Tasks = 0", str_contains($emptyRes['body'], '>0<'));
assertCondition("Empty summary Overall Completion % = 0%", str_contains($emptyRes['body'], '0%'));

// -------------------------------------------------------------
// TEST 7: CSV Export Verification
// -------------------------------------------------------------
echo "\n--- 7. CSV Export Verification (?export=csv) ---\n";
$csvUrl = "{$baseUrl}/dean/generate-report.php?activity_id={$activityWithTasksId}&export=csv";
$csvRes = makeRequest($csvUrl, 'GET', [], $deanCookie);

assertCondition("CSV request returns HTTP 200 OK", $csvRes['code'] === 200);
assertCondition("CSV contains '--- TASK & PERFORMANCE SUMMARY ---' section", str_contains($csvRes['body'], '--- TASK & PERFORMANCE SUMMARY ---'));
assertCondition("CSV contains Total Tasks total = 4", str_contains($csvRes['body'], 'Total Tasks",4') || str_contains($csvRes['body'], 'Total Tasks,4'));
assertCondition("CSV contains Completed total = 1", str_contains($csvRes['body'], 'Completed,1'));
assertCondition("CSV contains In Progress total = 3", str_contains($csvRes['body'], 'In Progress",3') || str_contains($csvRes['body'], 'In Progress,3'));
assertCondition("CSV contains Delayed total = 1", str_contains($csvRes['body'], 'Delayed,1'));
assertCondition("CSV contains Overall Completion % = 51%", str_contains($csvRes['body'], 'Overall Completion %",51%') || str_contains($csvRes['body'], 'Overall Completion %,51%'));
assertCondition("CSV contains task row for 'Prepare Event Flow'", str_contains($csvRes['body'], 'Prepare Event Flow'));
assertCondition("CSV contains task row for 'Arrange Venue Audio'", str_contains($csvRes['body'], 'Arrange Venue Audio'));
assertCondition("CSV contains spearhead department 'Information Technology'", str_contains($csvRes['body'], 'Information Technology'));

// -------------------------------------------------------------
// TEST 8: Authorization Enforcement
// -------------------------------------------------------------
echo "\n--- 8. Role Authorization Enforcement ---\n";
$unauthRes = makeRequest($reportUrl, 'GET');
assertCondition("Unauthenticated request redirected or blocked", $unauthRes['code'] === 302 || $unauthRes['code'] === 401 || $unauthRes['code'] === 403);

// -------------------------------------------------------------
// Cleanup Fixtures
// -------------------------------------------------------------
echo "\n--- Cleaning Up Fixtures ---\n";
$db->prepare("DELETE FROM faculty_tasks WHERE activity_id IN (?, ?)")->execute([$activityWithTasksId, $activityNoTasksId]);
$db->prepare("DELETE FROM activities WHERE id IN (?, ?)")->execute([$activityWithTasksId, $activityNoTasksId]);
$db->prepare("UPDATE users SET department = 'IT Department' WHERE email = 'faculty@sti.edu'")->execute();
echo "  [DONE] Test fixtures cleaned up.\n\n";

echo "============================================================\n";
echo "  RESULTS: Passed = {$passedTests}, Failed = {$failedTests}\n";
echo "============================================================\n";

exit($failedTests === 0 ? 0 : 1);
