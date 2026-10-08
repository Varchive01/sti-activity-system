<?php
/**
 * tests/test_committee_monitoring.php
 *
 * Automated verification suite for Committee Monitoring summary on Dean Task Monitoring.
 *
 * Verifies:
 * 1. Test tasks across several committees (Logistics, Program, Technical, and Unassigned).
 * 2. Totals are correct per committee (Total, Completed, In Progress, Not Started, Delayed).
 * 3. Completion averages are mathematically correct.
 * 4. Delayed count uses actual task state / overdue due date.
 * 5. Dean can view the Committee Monitoring section and table.
 * 6. Other roles (Faculty, Admin) cannot access Dean-only monitoring (403 Forbidden).
 * 7. Clean up test records.
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
echo "    DEAN COMMITTEE MONITORING SUMMARY VERIFICATION SUITE    \n";
echo "============================================================\n\n";

require_once __DIR__ . '/../config/database.php';
$db = getDB();

// ── 0. Authenticate Sessions ─────────────────────────────────────────
$deanCookie = loginUser($baseUrl, 'dean@sti.edu', 'password');
assertCheck(!empty($deanCookie), "Dean authenticated with active session");

$facultyCookie = loginUser($baseUrl, 'faculty@sti.edu', 'password');
assertCheck(!empty($facultyCookie), "Faculty authenticated with active session");

$adminCookie = loginUser($baseUrl, 'arjay@sti.edu', 'password');
assertCheck(!empty($adminCookie), "Admin authenticated with active session");

// ── 1. Create Test Activity & Diverse Tasks Across Committees ───────
$leaderUser = $db->query("SELECT id, name, department FROM users WHERE email = 'faculty@sti.edu'")->fetch(PDO::FETCH_ASSOC);
$memberUser = $db->query("SELECT id, name FROM users WHERE email = 'arjay@sti.edu'")->fetch(PDO::FETCH_ASSOC);

$actStmt = $db->prepare("
    INSERT INTO activities (faculty_id, title, description, general_objectives, event_date, status, source) 
    VALUES (?, 'Committee Monitoring Test Showcase', 'Testing committee summary metrics and risk states', 'Verify calculations', '2026-12-15', 'approved', 'faculty')
");
$actStmt->execute([$leaderUser['id']]);
$testActivityId = (int)$db->lastInsertId();
assertCheck($testActivityId > 0, "Test activity created with ID: {$testActivityId}");

$today = date('Y-m-d');
$dueOverdue = date('Y-m-d', strtotime('-4 days'));
$dueTomorrow = date('Y-m-d', strtotime('+1 day'));
$dueNextWeek = date('Y-m-d', strtotime('+7 days'));
$dueNextMonth = date('Y-m-d', strtotime('+25 days'));

// Logistics Committee:
// Task 1: Completed (100%)
$res1 = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'create',
    'activity_id' => $testActivityId,
    'task_title' => 'Logistics Task 1 - Venue Booking',
    'committee' => 'Logistics Committee',
    'assigned_member' => 'Ian Barangan',
    'role' => 'Logistics Officer',
    'due_date' => $dueNextMonth,
    'status' => 'Completed'
], $facultyCookie);
$t1Id = json_decode($res1['body'], true)['task_id'] ?? 0;

// Task 2: In Progress (50%)
$res2 = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'create',
    'activity_id' => $testActivityId,
    'task_title' => 'Logistics Task 2 - Catering Service',
    'committee' => 'Logistics Committee',
    'assigned_member' => 'Ian Barangan',
    'role' => 'Logistics Officer',
    'due_date' => $dueNextMonth,
    'status' => 'In Progress'
], $facultyCookie);
$t2Id = json_decode($res2['body'], true)['task_id'] ?? 0;

// Program Committee:
// Task 3: In Progress, Due Tomorrow (At Risk, 40% via description regex)
$res3 = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'create',
    'activity_id' => $testActivityId,
    'task_title' => 'Program Task 1 - Guest Speaker Flow',
    'task_description' => 'Current Progress: 40% completed',
    'committee' => 'Program Committee',
    'assigned_member' => 'Maria Santos',
    'role' => 'Program Head',
    'due_date' => $dueTomorrow,
    'status' => 'In Progress'
], $facultyCookie);
$t3Id = json_decode($res3['body'], true)['task_id'] ?? 0;

// Technical Committee:
// Task 4: In Progress, Overdue (due 4 days ago -> Delayed timing, 20% via title regex)
$res4 = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'create',
    'activity_id' => $testActivityId,
    'task_title' => 'Technical Task 1 - Audio Cables (20%)',
    'committee' => 'Technical Committee',
    'assigned_member' => 'Ar-jay Agabayani',
    'role' => 'Tech Specialist',
    'due_date' => $dueOverdue,
    'status' => 'In Progress'
], $facultyCookie);
$t4Id = json_decode($res4['body'], true)['task_id'] ?? 0;

// Task 5: Status Delayed (Delayed, 30% via title regex)
$res5 = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'create',
    'activity_id' => $testActivityId,
    'task_title' => 'Technical Task 2 - Projector Rigging (30%)',
    'committee' => 'Technical Committee',
    'assigned_member' => 'Ar-jay Agabayani',
    'role' => 'Tech Specialist',
    'due_date' => $dueNextWeek,
    'status' => 'Delayed'
], $facultyCookie);
$t5Id = json_decode($res5['body'], true)['task_id'] ?? 0;

// Unassigned Committee:
// Task 6: No committee (empty string), Not Started (0%)
$res6 = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'create',
    'activity_id' => $testActivityId,
    'task_title' => 'General Task - Floor Cleanup',
    'committee' => '',
    'assigned_member' => 'Volunteer',
    'role' => 'Assistant',
    'due_date' => $dueNextWeek,
    'status' => 'Not Started'
], $facultyCookie);
$t6Id = json_decode($res6['body'], true)['task_id'] ?? 0;

assertCheck($t1Id && $t2Id && $t3Id && $t4Id && $t5Id && $t6Id, "All 6 test tasks created across 4 committee categories");

// ── 2. Dean View Verification ────────────────────────────────────────
echo "\n--- Dean Committee Monitoring View & Calculations ---\n";
$deanRes = makeRequest("{$baseUrl}/dean/task-monitoring.php?activity_id={$testActivityId}", 'GET', [], $deanCookie);
assertCheck($deanRes['code'] === 200, "Dean loads task monitoring with activity filter (HTTP 200)");

$html = $deanRes['body'];

// Confirm Committee Monitoring section presence
assertCheck(strpos($html, 'id="committeeMonitoringSection"') !== false, "Committee Monitoring section present in DOM");
assertCheck(strpos($html, 'id="committeeMonitoringTable"') !== false, "Committee Monitoring table present in DOM");

// Confirm required table headers: Committee | Total | Completed | In Progress | Delayed | Avg Completion | Status
assertCheck(strpos($html, '>Committee</th>') !== false, "Table header 'Committee' present");
assertCheck(strpos($html, '>Total</th>') !== false, "Table header 'Total' present");
assertCheck(strpos($html, '>Completed</th>') !== false, "Table header 'Completed' present");
assertCheck(strpos($html, '>In Progress</th>') !== false, "Table header 'In Progress' present");
assertCheck(strpos($html, '>Delayed</th>') !== false, "Table header 'Delayed' present");
assertCheck(strpos($html, '>Avg Completion</th>') !== false, "Table header 'Avg Completion' present");
assertCheck(strpos($html, '>Status</th>') !== false, "Table header 'Status' present");

// ── 3. Parse & Verify Each Committee's Calculated Metrics ────────────
echo "\n--- Committee-Specific Metrics Verification ---\n";

// Helper function to extract a committee's data row attributes
function parseCommitteeRow(string $html, string $committeeName): ?array {
    $pattern = '/<tr[^>]*data-committee="' . preg_quote($committeeName, '/') . '"([^>]*)>/i';
    if (!preg_match($pattern, $html, $match)) {
        return null;
    }
    $attrs = $match[1];
    $data = [];
    if (preg_match('/data-total="(\d+)"/', $attrs, $m)) $data['total'] = (int)$m[1];
    if (preg_match('/data-completed="(\d+)"/', $attrs, $m)) $data['completed'] = (int)$m[1];
    if (preg_match('/data-in-progress="(\d+)"/', $attrs, $m)) $data['in_progress'] = (int)$m[1];
    if (preg_match('/data-not-started="(\d+)"/', $attrs, $m)) $data['not_started'] = (int)$m[1];
    if (preg_match('/data-delayed="(\d+)"/', $attrs, $m)) $data['delayed'] = (int)$m[1];
    if (preg_match('/data-avg-completion="([\d\.]+)"/', $attrs, $m)) $data['avg_completion'] = (float)$m[1];
    if (preg_match('/data-status="([^"]+)"/', $attrs, $m)) $data['status'] = $m[1];
    return $data;
}

// 1. Logistics Committee: 2 tasks (1 completed 100%, 1 in progress 50% -> avg = 75%, delayed = 0 -> On Track)
$logistics = parseCommitteeRow($html, 'Logistics Committee');
assertCheck($logistics !== null, "Logistics Committee row found in table");
if ($logistics) {
    assertCheck($logistics['total'] === 2, "Logistics total tasks = 2 (actual: {$logistics['total']})");
    assertCheck($logistics['completed'] === 1, "Logistics completed tasks = 1 (actual: {$logistics['completed']})");
    assertCheck($logistics['in_progress'] === 1, "Logistics in progress tasks = 1 (actual: {$logistics['in_progress']})");
    assertCheck($logistics['delayed'] === 0, "Logistics delayed tasks = 0 (actual: {$logistics['delayed']})");
    assertCheck($logistics['avg_completion'] == 75.0, "Logistics avg completion = 75% (actual: {$logistics['avg_completion']}%)");
    assertCheck($logistics['status'] === 'On Track', "Logistics status = 'On Track' (actual: '{$logistics['status']}')");
}

// 2. Program Committee: 1 task (in progress 40%, due tomorrow -> At Risk)
$program = parseCommitteeRow($html, 'Program Committee');
assertCheck($program !== null, "Program Committee row found in table");
if ($program) {
    assertCheck($program['total'] === 1, "Program total tasks = 1 (actual: {$program['total']})");
    assertCheck($program['completed'] === 0, "Program completed tasks = 0 (actual: {$program['completed']})");
    assertCheck($program['in_progress'] === 1, "Program in progress tasks = 1 (actual: {$program['in_progress']})");
    assertCheck($program['delayed'] === 0, "Program delayed tasks = 0 (actual: {$program['delayed']})");
    assertCheck($program['avg_completion'] == 40.0, "Program avg completion = 40% (actual: {$program['avg_completion']}%)");
    assertCheck($program['status'] === 'At Risk', "Program status = 'At Risk' (actual: '{$program['status']}')");
}

// 3. Technical Committee: 2 tasks (1 overdue In Progress [20%], 1 Delayed status [30%] -> avg = 25%, delayed = 2 -> Delayed)
$tech = parseCommitteeRow($html, 'Technical Committee');
assertCheck($tech !== null, "Technical Committee row found in table");
if ($tech) {
    assertCheck($tech['total'] === 2, "Technical total tasks = 2 (actual: {$tech['total']})");
    assertCheck($tech['completed'] === 0, "Technical completed tasks = 0 (actual: {$tech['completed']})");
    assertCheck($tech['delayed'] === 2, "Technical delayed tasks = 2 (verifying overdue date + status; actual: {$tech['delayed']})");
    assertCheck($tech['avg_completion'] == 25.0, "Technical avg completion = 25% (actual: {$tech['avg_completion']}%)");
    assertCheck($tech['status'] === 'Delayed', "Technical status = 'Delayed' (actual: '{$tech['status']}')");
}

// 4. Unassigned: 1 task (empty committee -> grouped as 'Unassigned', Not Started 0% -> On Track)
$unassigned = parseCommitteeRow($html, 'Unassigned');
assertCheck($unassigned !== null, "Unassigned committee row found in table");
if ($unassigned) {
    assertCheck($unassigned['total'] === 1, "Unassigned total tasks = 1 (actual: {$unassigned['total']})");
    assertCheck($unassigned['not_started'] === 1, "Unassigned not started = 1 (actual: {$unassigned['not_started']})");
    assertCheck($unassigned['completed'] === 0, "Unassigned completed = 0 (actual: {$unassigned['completed']})");
    assertCheck($unassigned['delayed'] === 0, "Unassigned delayed = 0 (actual: {$unassigned['delayed']})");
    assertCheck($unassigned['avg_completion'] == 0.0, "Unassigned avg completion = 0% (actual: {$unassigned['avg_completion']}%)");
    assertCheck($unassigned['status'] === 'On Track', "Unassigned status = 'On Track' (actual: '{$unassigned['status']}')");
}

// ── 4. Authorization Enforcement Checks ──────────────────────────────
echo "\n--- Authorization & Role Restriction Verification ---\n";
// Dean can access
assertCheck($deanRes['code'] === 200, "Dean access confirmed (HTTP 200)");

// Faculty cannot access dean/task-monitoring.php (403 Forbidden)
$facultyAccess = makeRequest("{$baseUrl}/dean/task-monitoring.php", 'GET', [], $facultyCookie);
assertCheck($facultyAccess['code'] === 403, "Faculty denied access to Dean Task Monitoring (HTTP 403 Forbidden)");

// Admin cannot access dean/task-monitoring.php (403 Forbidden)
$adminAccess = makeRequest("{$baseUrl}/dean/task-monitoring.php", 'GET', [], $adminCookie);
assertCheck($adminAccess['code'] === 403, "Admin denied access to Dean Task Monitoring (HTTP 403 Forbidden)");

// Unauthenticated user redirected (HTTP 302)
$unauthAccess = makeRequest("{$baseUrl}/dean/task-monitoring.php", 'GET', [], null);
assertCheck($unauthAccess['code'] === 302, "Unauthenticated request redirected to login (HTTP 302)");

// ── 5. Cleanup Test Records ──────────────────────────────────────────
echo "\n--- Cleanup Test Records ---\n";
$db->prepare("DELETE FROM faculty_tasks WHERE activity_id = ?")->execute([$testActivityId]);
$db->prepare("DELETE FROM activities WHERE id = ?")->execute([$testActivityId]);
echo "  [DONE] Test records cleaned up.\n";

// ── Summary ──────────────────────────────────────────────────────────
echo "\n============================================================\n";
echo "  TEST SUMMARY: {$passCount} passed, {$failCount} failed\n";
echo "============================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
