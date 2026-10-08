<?php
/**
 * tests/test_member_performance.php
 *
 * Automated verification suite for Member Performance summary on Dean Task Monitoring.
 *
 * Verifies:
 * 1. Test tasks assigned to multiple members (Maria Santos, Ar-jay Agabayani, Ian Jade Barangan, and Unassigned).
 * 2. Totals per member (Total, Completed, In Progress, Not Started, Delayed).
 * 3. Average completion percentages calculated accurately.
 * 4. Delayed count uses actual task state / overdue due date.
 * 5. Due-soon tasks (within 3 days) affect At Risk correctly.
 * 6. Filters update the Member Performance summary (e.g. member, timing).
 * 7. Dean can view the Member Performance section and table.
 * 8. Other roles (Faculty, Admin) cannot access Dean-only monitoring (403 Forbidden).
 * 9. Clean up test records.
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
echo "     DEAN MEMBER PERFORMANCE SUMMARY VERIFICATION SUITE     \n";
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

// ── 1. Create Test Activity & Tasks Assigned to Multiple Members ─────
$leaderUser = $db->query("SELECT id, name, department FROM users WHERE email = 'faculty@sti.edu'")->fetch(PDO::FETCH_ASSOC);

$actStmt = $db->prepare("
    INSERT INTO activities (faculty_id, title, description, general_objectives, event_date, status, source) 
    VALUES (?, 'Member Performance Showcase', 'Testing individual member summary metrics and filtering', 'Verify member calculations', '2026-12-20', 'approved', 'faculty')
");
$actStmt->execute([$leaderUser['id']]);
$testActivityId = (int)$db->lastInsertId();
assertCheck($testActivityId > 0, "Test activity created with ID: {$testActivityId}");

$today = date('Y-m-d');
$dueOverdue = date('Y-m-d', strtotime('-4 days'));
$dueTomorrow = date('Y-m-d', strtotime('+1 day'));
$dueNextWeek = date('Y-m-d', strtotime('+7 days'));
$dueNextMonth = date('Y-m-d', strtotime('+25 days'));

// Member A: Prof. Maria Santos
// Task 1: Completed (100%)
$res1 = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'create',
    'activity_id' => $testActivityId,
    'task_title' => 'Program Schedule Draft',
    'committee' => 'Program Committee',
    'assigned_member' => 'Prof. Maria Santos',
    'role' => 'Program Head',
    'due_date' => $dueNextMonth,
    'status' => 'Completed'
], $facultyCookie);
$t1Id = json_decode($res1['body'], true)['task_id'] ?? 0;

// Task 2: In Progress (50%)
$res2 = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'create',
    'activity_id' => $testActivityId,
    'task_title' => 'Speaker Presentation Review',
    'committee' => 'Program Committee',
    'assigned_member' => 'Prof. Maria Santos',
    'role' => 'Program Head',
    'due_date' => $dueNextMonth,
    'status' => 'In Progress'
], $facultyCookie);
$t2Id = json_decode($res2['body'], true)['task_id'] ?? 0;

// Member B: Ar-jay Agabayani
// Task 3: In Progress, Due Tomorrow (within 3 days -> At Risk, 40%)
$res3 = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'create',
    'activity_id' => $testActivityId,
    'task_title' => 'Audio System Calibration',
    'task_description' => 'Current Progress: 40% completed',
    'committee' => 'Technical Committee',
    'assigned_member' => 'Ar-jay Agabayani',
    'role' => 'Audio Lead',
    'due_date' => $dueTomorrow,
    'status' => 'In Progress'
], $facultyCookie);
$t3Id = json_decode($res3['body'], true)['task_id'] ?? 0;

// Member C: Ian Jade Barangan
// Task 4: In Progress, Overdue (due 4 days ago -> Delayed, 20%)
$res4 = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'create',
    'activity_id' => $testActivityId,
    'task_title' => 'Venue Setup & Chandelier Inspection (20%)',
    'committee' => 'Logistics Committee',
    'assigned_member' => 'Ian Jade Barangan',
    'role' => 'Venue Coordinator',
    'due_date' => $dueOverdue,
    'status' => 'In Progress'
], $facultyCookie);
$t4Id = json_decode($res4['body'], true)['task_id'] ?? 0;

// Task 5: Status Delayed (Delayed, 30%)
$res5 = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'create',
    'activity_id' => $testActivityId,
    'task_title' => 'Stage Banner Rigging (30%)',
    'committee' => 'Logistics Committee',
    'assigned_member' => 'Ian Jade Barangan',
    'role' => 'Venue Coordinator',
    'due_date' => $dueNextWeek,
    'status' => 'Delayed'
], $facultyCookie);
$t5Id = json_decode($res5['body'], true)['task_id'] ?? 0;

// Member D: Unassigned member
// Task 6: No assigned member, Not Started (0%)
$res6 = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'create',
    'activity_id' => $testActivityId,
    'task_title' => 'Post-Event Cleanliness Drive',
    'committee' => 'Logistics Committee',
    'assigned_member' => '',
    'role' => '',
    'due_date' => $dueNextWeek,
    'status' => 'Not Started'
], $facultyCookie);
$t6Id = json_decode($res6['body'], true)['task_id'] ?? 0;

assertCheck($t1Id && $t2Id && $t3Id && $t4Id && $t5Id && $t6Id, "All 6 test tasks created across multiple members");

// ── 2. Dean View Verification ────────────────────────────────────────
echo "\n--- Dean Member Performance View & Calculations ---\n";
$deanRes = makeRequest("{$baseUrl}/dean/task-monitoring.php?activity_id={$testActivityId}", 'GET', [], $deanCookie);
assertCheck($deanRes['code'] === 200, "Dean loads task monitoring with activity filter (HTTP 200)");

$html = $deanRes['body'];

// Confirm Member Performance section & table presence
assertCheck(strpos($html, 'id="memberPerformanceSection"') !== false, "Member Performance section present in DOM");
assertCheck(strpos($html, 'id="memberPerformanceTable"') !== false, "Member Performance table present in DOM");

// Confirm required table headers: Member | Committee | Total | Completed | In Progress | Delayed | Avg Completion | Status
assertCheck(strpos($html, '>Member</th>') !== false, "Table header 'Member' present");
assertCheck(strpos($html, '>Committee</th>') !== false, "Table header 'Committee' present");
assertCheck(strpos($html, '>Total</th>') !== false, "Table header 'Total' present");
assertCheck(strpos($html, '>Completed</th>') !== false, "Table header 'Completed' present");
assertCheck(strpos($html, '>In Progress</th>') !== false, "Table header 'In Progress' present");
assertCheck(strpos($html, '>Delayed</th>') !== false, "Table header 'Delayed' present");
assertCheck(strpos($html, '>Avg Completion</th>') !== false, "Table header 'Avg Completion' present");
assertCheck(strpos($html, '>Status</th>') !== false, "Table header 'Status' present");

// ── 3. Parse & Verify Each Member's Calculated Metrics ───────────────
echo "\n--- Member-Specific Metrics Verification ---\n";

// Helper function to extract a member's data row attributes
function parseMemberRow(string $html, string $memberName): ?array {
    $pattern = '/<tr[^>]*data-member="' . preg_quote($memberName, '/') . '"([^>]*)>/i';
    if (!preg_match($pattern, $html, $match)) {
        return null;
    }
    $attrs = $match[1];
    $data = [];
    if (preg_match('/data-committee="([^"]*)"/', $attrs, $m)) $data['committee'] = $m[1];
    if (preg_match('/data-total="(\d+)"/', $attrs, $m)) $data['total'] = (int)$m[1];
    if (preg_match('/data-completed="(\d+)"/', $attrs, $m)) $data['completed'] = (int)$m[1];
    if (preg_match('/data-in-progress="(\d+)"/', $attrs, $m)) $data['in_progress'] = (int)$m[1];
    if (preg_match('/data-not-started="(\d+)"/', $attrs, $m)) $data['not_started'] = (int)$m[1];
    if (preg_match('/data-delayed="(\d+)"/', $attrs, $m)) $data['delayed'] = (int)$m[1];
    if (preg_match('/data-avg-completion="([\d\.]+)"/', $attrs, $m)) $data['avg_completion'] = (float)$m[1];
    if (preg_match('/data-status="([^"]+)"/', $attrs, $m)) $data['status'] = $m[1];
    return $data;
}

// 1. Prof. Maria Santos: 2 tasks (1 completed 100%, 1 in progress 50% -> avg = 75%, delayed = 0 -> On Track)
$maria = parseMemberRow($html, 'Prof. Maria Santos');
assertCheck($maria !== null, "Prof. Maria Santos row found in Member Performance table");
if ($maria) {
    assertCheck($maria['total'] === 2, "Maria total tasks = 2 (actual: {$maria['total']})");
    assertCheck($maria['completed'] === 1, "Maria completed tasks = 1 (actual: {$maria['completed']})");
    assertCheck($maria['in_progress'] === 1, "Maria in progress tasks = 1 (actual: {$maria['in_progress']})");
    assertCheck($maria['delayed'] === 0, "Maria delayed tasks = 0 (actual: {$maria['delayed']})");
    assertCheck($maria['avg_completion'] == 75.0, "Maria avg completion = 75% (actual: {$maria['avg_completion']}%)");
    assertCheck($maria['status'] === 'On Track', "Maria status = 'On Track' (actual: '{$maria['status']}')");
    assertCheck(strpos($maria['committee'], 'Program Committee') !== false, "Maria committee reflects 'Program Committee'");
}

// 2. Ar-jay Agabayani: 1 task (in progress 40%, due tomorrow -> At Risk)
$arjay = parseMemberRow($html, 'Ar-jay Agabayani');
assertCheck($arjay !== null, "Ar-jay Agabayani row found in Member Performance table");
if ($arjay) {
    assertCheck($arjay['total'] === 1, "Ar-jay total tasks = 1 (actual: {$arjay['total']})");
    assertCheck($arjay['completed'] === 0, "Ar-jay completed tasks = 0 (actual: {$arjay['completed']})");
    assertCheck($arjay['in_progress'] === 1, "Ar-jay in progress tasks = 1 (actual: {$arjay['in_progress']})");
    assertCheck($arjay['delayed'] === 0, "Ar-jay delayed tasks = 0 (actual: {$arjay['delayed']})");
    assertCheck($arjay['avg_completion'] == 40.0, "Ar-jay avg completion = 40% (actual: {$arjay['avg_completion']}%)");
    assertCheck($arjay['status'] === 'At Risk', "Ar-jay status = 'At Risk' (due tomorrow within 3d; actual: '{$arjay['status']}')");
    assertCheck(strpos($arjay['committee'], 'Technical Committee') !== false, "Ar-jay committee reflects 'Technical Committee'");
}

// 3. Ian Jade Barangan: 2 tasks (1 overdue in progress [20%], 1 Delayed status [30%] -> avg = 25%, delayed = 2 -> Delayed)
$ian = parseMemberRow($html, 'Ian Jade Barangan');
assertCheck($ian !== null, "Ian Jade Barangan row found in Member Performance table");
if ($ian) {
    assertCheck($ian['total'] === 2, "Ian total tasks = 2 (actual: {$ian['total']})");
    assertCheck($ian['completed'] === 0, "Ian completed tasks = 0 (actual: {$ian['completed']})");
    assertCheck($ian['delayed'] === 2, "Ian delayed tasks = 2 (verifying overdue date + status; actual: {$ian['delayed']})");
    assertCheck($ian['avg_completion'] == 25.0, "Ian avg completion = 25% (actual: {$ian['avg_completion']}%)");
    assertCheck($ian['status'] === 'Delayed', "Ian status = 'Delayed' (actual: '{$ian['status']}')");
    assertCheck(strpos($ian['committee'], 'Logistics Committee') !== false, "Ian committee reflects 'Logistics Committee'");
}

// 4. Unassigned: 1 task (empty member -> grouped as 'Unassigned', Not Started 0% -> On Track)
$unassigned = parseMemberRow($html, 'Unassigned');
assertCheck($unassigned !== null, "Unassigned member row found in Member Performance table");
if ($unassigned) {
    assertCheck($unassigned['total'] === 1, "Unassigned total tasks = 1 (actual: {$unassigned['total']})");
    assertCheck($unassigned['not_started'] === 1, "Unassigned not started = 1 (actual: {$unassigned['not_started']})");
    assertCheck($unassigned['completed'] === 0, "Unassigned completed = 0 (actual: {$unassigned['completed']})");
    assertCheck($unassigned['delayed'] === 0, "Unassigned delayed = 0 (actual: {$unassigned['delayed']})");
    assertCheck($unassigned['avg_completion'] == 0.0, "Unassigned avg completion = 0% (actual: {$unassigned['avg_completion']}%)");
    assertCheck($unassigned['status'] === 'On Track', "Unassigned status = 'On Track' (actual: '{$unassigned['status']}')");
}

// ── 4. Verify Filters Update Member Performance Summary ──────────────
echo "\n--- Filter Responsiveness Verification ---\n";

// Filter by Member: Ar-jay Agabayani
$filterMemberRes = makeRequest("{$baseUrl}/dean/task-monitoring.php?activity_id={$testActivityId}&member=" . urlencode('Ar-jay Agabayani'), 'GET', [], $deanCookie);
assertCheck($filterMemberRes['code'] === 200, "Member filter loads successfully (HTTP 200)");
$filterMemberHtml = $filterMemberRes['body'];

$filterArjay = parseMemberRow($filterMemberHtml, 'Ar-jay Agabayani');
$filterMaria = parseMemberRow($filterMemberHtml, 'Prof. Maria Santos');
$filterIan   = parseMemberRow($filterMemberHtml, 'Ian Jade Barangan');

assertCheck($filterArjay !== null && $filterArjay['total'] === 1, "Member filter includes Ar-jay Agabayani");
assertCheck($filterMaria === null, "Member filter excludes Maria Santos");
assertCheck($filterIan === null, "Member filter excludes Ian Jade Barangan");

// Filter by Timing: Delayed
$filterTimingRes = makeRequest("{$baseUrl}/dean/task-monitoring.php?activity_id={$testActivityId}&timing=Delayed", 'GET', [], $deanCookie);
assertCheck($filterTimingRes['code'] === 200, "Timing=Delayed filter loads successfully (HTTP 200)");
$filterTimingHtml = $filterTimingRes['body'];

$timingIan   = parseMemberRow($filterTimingHtml, 'Ian Jade Barangan');
$timingMaria = parseMemberRow($filterTimingHtml, 'Prof. Maria Santos');
$timingArjay = parseMemberRow($filterTimingHtml, 'Ar-jay Agabayani');

assertCheck($timingIan !== null && $timingIan['total'] === 2, "Timing=Delayed filter includes Ian Jade Barangan (delayed tasks)");
assertCheck($timingMaria === null, "Timing=Delayed filter excludes on-track Maria Santos");
assertCheck($timingArjay === null, "Timing=Delayed filter excludes at-risk Ar-jay Agabayani");

// ── 5. Authorization Enforcement Checks ──────────────────────────────
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

// ── 6. Cleanup Test Records ──────────────────────────────────────────
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
