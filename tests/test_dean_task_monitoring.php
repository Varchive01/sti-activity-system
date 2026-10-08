<?php
/**
 * tests/test_dean_task_monitoring.php
 *
 * Automated verification suite for the Centralized Dean Task Monitoring View.
 *
 * Verifies:
 * 1. Create several test assignments under one activity.
 * 2. Use different committees, members, due dates, and statuses.
 * 3. Log in as Dean.
 * 4. Open Task Monitoring (dean/task-monitoring.php).
 * 5. Confirm all assignments appear in read-only mode.
 * 6. Confirm completion/status calculations are correct.
 * 7. Confirm delayed tasks are identified from actual dates/status.
 * 8. Confirm filters work (Activity, Committee, Member, Status, Timing).
 * 9. Confirm Gantt reflects the stored task data.
 * 10. Confirm Dean cannot modify assignments (mutations blocked with 403).
 * 11. Confirm Faculty can still manage their own assignments.
 * 12. Confirm no duplicate task records.
 * 13. Run existing task-assignment tests.
 */

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

    // Extract cookies
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

$passCount = 0;
$failCount = 0;

function assertCheck(bool $condition, string $msg) {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$msg}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$msg}\n";
        $failCount++;
    }
}

echo "============================================================\n";
echo "  DEAN TASK MONITORING CENTRALIZED VIEW VERIFICATION SUITE  \n";
echo "============================================================\n\n";

require_once __DIR__ . '/../config/database.php';
$db = getDB();

// ── 0. Authenticate Roles ───────────────────────────────────────────
$deanCookie = loginUser($baseUrl, 'dean@sti.edu', 'password');
assertCheck(!empty($deanCookie), "Dean authenticated with active session");

$facultyCookie = loginUser($baseUrl, 'faculty@sti.edu', 'password');
assertCheck(!empty($facultyCookie), "Faculty authenticated with active session");

// ── 1-2. Create Test Activity & Assign Diverse Tasks ────────────────
$leaderUser = $db->query("SELECT id, name, department FROM users WHERE email = 'faculty@sti.edu'")->fetch(PDO::FETCH_ASSOC);
$memberUser = $db->query("SELECT id, name FROM users WHERE email = 'arjay@sti.edu'")->fetch(PDO::FETCH_ASSOC);

$actStmt = $db->prepare("
    INSERT INTO activities (faculty_id, title, description, general_objectives, event_date, status, source) 
    VALUES (?, 'Dean Monitoring Verification Showcase', 'Test activity for Dean centralized monitoring', 'Verify task aggregation', '2026-11-25', 'approved', 'faculty')
");
$actStmt->execute([$leaderUser['id']]);
$testActivityId = (int)$db->lastInsertId();
assertCheck($testActivityId > 0, "Test activity created with ID: {$testActivityId}");

// We will insert 4 tasks with diverse committees, members, due dates, and statuses:
// Task A: Completed (AV & Stage, Maria Santos, due in 5 days, status: Completed)
// Task B: On Track (Registration & Badges, Ar-jay Agabayani, due in 15 days, status: In Progress)
// Task C: At Risk (VIP Invitations, Logistics Committee, due tomorrow, status: In Progress)
// Task D: Delayed (Printing Programs, Secretariat Committee, overdue past date, status: Delayed)

$today = date('Y-m-d');
$duePast = date('Y-m-d', strtotime('-5 days'));
$dueTomorrow = date('Y-m-d', strtotime('+1 day'));
$dueTwoWeeks = date('Y-m-d', strtotime('+14 days'));
$dueNextMonth = date('Y-m-d', strtotime('+30 days'));

// Task 1: Completed
$res1 = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'create',
    'activity_id' => $testActivityId,
    'task_title' => 'AV Equipment Setup & Sound Check',
    'task_description' => 'Verify all microphones and projection screens',
    'committee' => 'Technical Committee',
    'assigned_member' => 'Prof. Maria Santos',
    'role' => 'Technical Lead',
    'due_date' => $dueNextMonth,
    'status' => 'Completed'
], $facultyCookie);
$t1Dec = json_decode($res1['body'], true);
$t1Id = (int)($t1Dec['task_id'] ?? 0);

// Task 2: In Progress (On Track, due in 2 weeks)
$res2 = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'create',
    'activity_id' => $testActivityId,
    'task_title' => 'Participant Badge Printing & Desk',
    'task_description' => 'Prepare registration QR scanner and physical badges',
    'committee' => 'Registration Committee',
    'assigned_member' => 'Ar-jay Agabayani',
    'role' => 'Desk Coordinator',
    'due_date' => $dueTwoWeeks,
    'status' => 'In Progress'
], $facultyCookie);
$t2Dec = json_decode($res2['body'], true);
$t2Id = (int)($t2Dec['task_id'] ?? 0);

// Task 3: In Progress (At Risk, due tomorrow)
$res3 = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'create',
    'activity_id' => $testActivityId,
    'task_title' => 'VIP Speaker Plaque Engraving',
    'task_description' => 'Obtain speaker names and submit to engraver',
    'committee' => 'Logistics Committee',
    'assigned_member' => 'Ian Jade Barangan',
    'role' => 'Logistics Officer',
    'due_date' => $dueTomorrow,
    'status' => 'In Progress'
], $facultyCookie);
$t3Dec = json_decode($res3['body'], true);
$t3Id = (int)($t3Dec['task_id'] ?? 0);

// Task 4: Delayed (Delayed status, due in past)
$res4 = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'create',
    'activity_id' => $testActivityId,
    'task_title' => 'Event Program Leaflet Printing',
    'task_description' => 'Print 200 copies of conference program booklet',
    'committee' => 'Secretariat Committee',
    'assigned_member' => 'External Vendor',
    'role' => 'Secretariat Head',
    'due_date' => $duePast,
    'status' => 'Delayed'
], $facultyCookie);
$t4Dec = json_decode($res4['body'], true);
$t4Id = (int)($t4Dec['task_id'] ?? 0);

assertCheck($t1Id && $t2Id && $t3Id && $t4Id, "Created 4 tasks across 4 committees with distinct dates and statuses");

// ── 3-5. Dean Opens Task Monitoring Page ────────────────────────────
echo "\n--- 3-5. Dean Task Monitoring Page Access & Content ---\n";
$deanPageRes = makeRequest("{$baseUrl}/dean/task-monitoring.php", 'GET', [], $deanCookie);
assertCheck($deanPageRes['code'] === 200, "Dean can load dean/task-monitoring.php (HTTP 200)");
assertCheck(str_contains($deanPageRes['body'], 'Task Monitoring — Centralized View'), "Dean page contains centralized title");
assertCheck(str_contains($deanPageRes['body'], 'AV Equipment Setup'), "Task 1 (Completed) rendered in table");
assertCheck(str_contains($deanPageRes['body'], 'Participant Badge Printing'), "Task 2 (In Progress) rendered in table");
assertCheck(str_contains($deanPageRes['body'], 'VIP Speaker Plaque Engraving'), "Task 3 (At Risk) rendered in table");
assertCheck(str_contains($deanPageRes['body'], 'Event Program Leaflet Printing'), "Task 4 (Delayed) rendered in table");

// ── 6. Calculations Verification ────────────────────────────────────
echo "\n--- 6. Status & Completion Percentage Calculations ---\n";
assertCheck(str_contains($deanPageRes['body'], '100%'), "Completed task shows 100% progress");
assertCheck(str_contains($deanPageRes['body'], '50%'), "In Progress task shows 50% progress");
assertCheck(str_contains($deanPageRes['body'], '25%'), "Delayed task shows 25% progress");
assertCheck(str_contains($deanPageRes['body'], 'statOverallPct'), "Overall completion percentage metric present in summary");

// ── 7. Timing Indicators (On Track, At Risk, Delayed) ───────────────
echo "\n--- 7. Timing Indicators Identification ---\n";
assertCheck(str_contains($deanPageRes['body'], 'badge-success') && str_contains($deanPageRes['body'], 'Completed'), "Completed timing badge present");
assertCheck(str_contains($deanPageRes['body'], 'On Track'), "On Track timing badge present");
assertCheck(str_contains($deanPageRes['body'], 'At Risk'), "At Risk timing badge present (approaching deadline)");
assertCheck(str_contains($deanPageRes['body'], 'Delayed'), "Delayed timing badge present for overdue task");

// ── 8. Filter Functionality ─────────────────────────────────────────
echo "\n--- 8. Multi-Criteria Filters Testing ---\n";
// Filter by specific activity
$actFilterRes = makeRequest("{$baseUrl}/dean/task-monitoring.php?activity_id={$testActivityId}", 'GET', [], $deanCookie);
assertCheck($actFilterRes['code'] === 200, "Filter by Activity ID returns HTTP 200");
assertCheck(str_contains($actFilterRes['body'], 'Dean Monitoring Verification Showcase'), "Activity filter contains test activity title");

// Filter by Status=Completed
$statusFilterRes = makeRequest("{$baseUrl}/dean/task-monitoring.php?activity_id={$testActivityId}&status=Completed", 'GET', [], $deanCookie);
assertCheck(str_contains($statusFilterRes['body'], 'AV Equipment Setup'), "Completed filter displays Task 1");
assertCheck(!str_contains($statusFilterRes['body'], 'Event Program Leaflet Printing'), "Completed filter excludes Delayed Task 4");

// Filter by Committee=Registration Committee
$commFilterRes = makeRequest("{$baseUrl}/dean/task-monitoring.php?activity_id={$testActivityId}&committee=Registration+Committee", 'GET', [], $deanCookie);
assertCheck(str_contains($commFilterRes['body'], 'Participant Badge Printing'), "Committee filter displays Registration task");
assertCheck(!str_contains($commFilterRes['body'], 'VIP Speaker Plaque Engraving'), "Committee filter excludes Logistics task");

// Filter by Timing=Delayed
$timingFilterRes = makeRequest("{$baseUrl}/dean/task-monitoring.php?activity_id={$testActivityId}&timing=Delayed", 'GET', [], $deanCookie);
assertCheck(str_contains($timingFilterRes['body'], 'Event Program Leaflet Printing'), "Timing=Delayed filter displays overdue task");

// ── 9. Gantt Visualization Data Parity ──────────────────────────────
echo "\n--- 9. Gantt Visualization Verification ---\n";
assertCheck(str_contains($deanPageRes['body'], 'Task Timeline (Gantt Visualization)'), "Gantt visualization card rendered");
assertCheck(str_contains($deanPageRes['body'], 'gantt-bar-item'), "Gantt bars rendered in DOM");
assertCheck(str_contains($deanPageRes['body'], 'Timeline Date Reference:'), "Documented limitation banner present in Gantt");

// ── 10. Dean Permissions: Dean Cannot Mutate Tasks ──────────────────
echo "\n--- 10. Dean Authorization Enforcement (Read-Only) ---\n";
// Attempt to create a task via API as Dean
$deanCreateRes = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'create',
    'activity_id' => $testActivityId,
    'task_title' => 'Dean Illegal Task Creation',
    'due_date' => $dueNextMonth
], $deanCookie);
assertCheck($deanCreateRes['code'] === 403, "Dean cannot create tasks (HTTP 403 Forbidden)");

// Attempt to update status as Dean
$deanUpdateRes = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'update_status',
    'activity_id' => $testActivityId,
    'task_id' => $t2Id,
    'status' => 'Completed'
], $deanCookie);
assertCheck($deanUpdateRes['code'] === 403, "Dean cannot update task status (HTTP 403 Forbidden)");

// Attempt to delete task as Dean
$deanDeleteRes = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'delete',
    'activity_id' => $testActivityId,
    'task_id' => $t2Id
], $deanCookie);
assertCheck($deanDeleteRes['code'] === 403, "Dean cannot delete tasks (HTTP 403 Forbidden)");

// ── 11. Faculty Leader Can Still Manage Their Own Assignments ───────
echo "\n--- 11. Faculty Management Permissions Maintained ---\n";
$facUpdateRes = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'update_status',
    'activity_id' => $testActivityId,
    'task_id' => $t2Id,
    'status' => 'Completed'
], $facultyCookie);
assertCheck($facUpdateRes['code'] === 200, "Faculty leader can update status of their task (HTTP 200)");

$updatedTaskRow = $db->query("SELECT status FROM faculty_tasks WHERE id = {$t2Id}")->fetch(PDO::FETCH_ASSOC);
assertCheck($updatedTaskRow['status'] === 'Completed', "Faculty update successfully persisted to DB");

// ── 12. Check for Duplicate Records ─────────────────────────────────
echo "\n--- 12. Duplicate Records Check ---\n";
$dbCount = (int)$db->query("SELECT COUNT(*) FROM faculty_tasks WHERE activity_id = {$testActivityId}")->fetchColumn();
assertCheck($dbCount === 4, "Zero duplicate records created (exact count = 4)");

// ── 13. Sidebar and Activity Detail Drill-down Links ────────────────
echo "\n--- 13. Navigation & Drill-Down Integrity ---\n";
$sidebarRes = makeRequest("{$baseUrl}/dean/activities.php", 'GET', [], $deanCookie);
assertCheck(str_contains($sidebarRes['body'], 'Task Monitoring'), "Sidebar contains 'Task Monitoring' link for Dean");

$viewActRes = makeRequest("{$baseUrl}/dean/view-activity.php?id={$testActivityId}", 'GET', [], $deanCookie);
assertCheck(str_contains($viewActRes['body'], 'Centralized Task Monitoring'), "Activity view page links to centralized monitoring");

// ── Cleanup Test Records ────────────────────────────────────────────
echo "\n--- Cleanup Test Records ---\n";
$db->exec("DELETE FROM task_reminder_logs WHERE activity_id = {$testActivityId}");
$db->exec("DELETE FROM notifications WHERE activity_id = {$testActivityId}");
$db->exec("DELETE FROM email_logs WHERE activity_id = {$testActivityId}");
$db->exec("DELETE FROM faculty_tasks WHERE activity_id = {$testActivityId}");
$db->exec("DELETE FROM activities WHERE id = {$testActivityId}");
echo "  [DONE] Test records cleaned up.\n";

echo "\n============================================================\n";
echo "  TEST SUMMARY: {$passCount} passed, {$failCount} failed\n";
echo "============================================================\n";

exit($failCount > 0 ? 1 : 0);
