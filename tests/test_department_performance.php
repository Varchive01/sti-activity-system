<?php
/**
 * tests/test_department_performance.php
 *
 * Automated verification suite for Spearhead / Department Performance summary on Dean Task Monitoring.
 *
 * Verifies:
 * 1. Test activities across different spearheading departments (IT Department, General Education, Unassigned).
 * 2. Activity counts per department (multiple activities under one department verified).
 * 3. Total task counts per department.
 * 4. Completed, In Progress, Delayed counts per department.
 * 5. Average task completion percentage calculated accurately.
 * 6. Status calculation (On Track, At Risk, Delayed).
 * 7. Filters update the Department Performance summary.
 * 8. Dean can view the Spearhead / Department Performance section.
 * 9. Faculty / Admin roles cannot access Dean monitoring (403 Forbidden).
 * 10. Clean up test records.
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
echo "   DEAN DEPARTMENT PERFORMANCE SUMMARY VERIFICATION SUITE   \n";
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

// ── 1. Create/Ensure Test Users for Distinct Departments ────────────
// Leader 1: IT Department (faculty@sti.edu)
$db->prepare("UPDATE users SET department = 'IT Department' WHERE email = 'faculty@sti.edu'")->execute();
$userIT = $db->query("SELECT id, name, department FROM users WHERE email = 'faculty@sti.edu'")->fetch(PDO::FETCH_ASSOC);

// Leader 2: General Education
$userGenEd = $db->query("SELECT id, name, department FROM users WHERE department = 'General Education' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$userGenEd) {
    $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Prof. GenEd Lead', 'gened_lead_test@sti.edu', ?, 'faculty', 'General Education')")
       ->execute([password_hash('password', PASSWORD_DEFAULT)]);
    $userGenEd = [
        'id' => (int)$db->lastInsertId(),
        'name' => 'Prof. GenEd Lead',
        'department' => 'General Education'
    ];
}

// Leader 3: No Department (Unassigned)
$userNoDept = $db->query("SELECT id, name, department FROM users WHERE department IS NULL OR department = '' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$userNoDept) {
    $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Proponent NoDept', 'nodept_lead_test@sti.edu', ?, 'faculty', NULL)")
       ->execute([password_hash('password', PASSWORD_DEFAULT)]);
    $userNoDept = [
        'id' => (int)$db->lastInsertId(),
        'name' => 'Proponent NoDept',
        'department' => null
    ];
}

assertCheck($userIT && $userGenEd && $userNoDept, "Verified 3 proponent leaders across IT Department, General Education, and Unassigned");

// ── 2. Create Test Activities & Tasks ────────────────────────────────
$today = date('Y-m-d');
$dueOverdue = date('Y-m-d', strtotime('-4 days'));
$dueTomorrow = date('Y-m-d', strtotime('+1 day'));
$dueNextMonth = date('Y-m-d', strtotime('+25 days'));

// Unique search token for test isolation
$testToken = 'DeptPerfTest_' . time();

// Activity 1: IT Department (Activity A)
$actStmt = $db->prepare("
    INSERT INTO activities (faculty_id, title, description, general_objectives, event_date, status, source) 
    VALUES (?, 'IT Showcase A [{$testToken}]', 'Testing department aggregation', 'Objectives', '2026-12-10', 'approved', 'faculty')
");
$actStmt->execute([$userIT['id']]);
$actId1 = (int)$db->lastInsertId();

// Task 1 under Act 1: Completed (100%)
$res1 = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'create',
    'activity_id' => $actId1,
    'task_title' => "IT Task 1 - Setup [{$testToken}]",
    'committee' => 'Technical',
    'assigned_member' => 'Maria Santos',
    'due_date' => $dueNextMonth,
    'status' => 'Completed'
], $facultyCookie);
$t1Id = json_decode($res1['body'], true)['task_id'] ?? 0;

// Task 2 under Act 1: In Progress (50%)
$res2 = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'create',
    'activity_id' => $actId1,
    'task_title' => "IT Task 2 - Testing [{$testToken}]",
    'committee' => 'Technical',
    'assigned_member' => 'Maria Santos',
    'due_date' => $dueNextMonth,
    'status' => 'In Progress'
], $facultyCookie);
$t2Id = json_decode($res2['body'], true)['task_id'] ?? 0;

// Activity 2: IT Department (Activity B - second activity under IT Department!)
$actStmt->execute([$userIT['id']]);
$actId2 = (int)$db->lastInsertId();
$db->prepare("UPDATE activities SET title = 'IT Showcase B [{$testToken}]' WHERE id = ?")->execute([$actId2]);

// Task 3 under Act 2: In Progress, Due Tomorrow (within 3 days -> At Risk, 40%)
$res3 = makeRequest("{$baseUrl}/api/task-assignment.php", 'POST', [
    'action' => 'create',
    'activity_id' => $actId2,
    'task_title' => "IT Task 3 - Rehearsal [{$testToken}]",
    'task_description' => 'Current Progress: 40% completed',
    'committee' => 'Logistics',
    'assigned_member' => 'Maria Santos',
    'due_date' => $dueTomorrow,
    'status' => 'In Progress'
], $facultyCookie);
$t3Id = json_decode($res3['body'], true)['task_id'] ?? 0;

// Activity 3: General Education (Activity C)
// Login as userGenEd or insert tasks directly
$actStmt->execute([$userGenEd['id']]);
$actId3 = (int)$db->lastInsertId();
$db->prepare("UPDATE activities SET title = 'GenEd Showcase [{$testToken}]' WHERE id = ?")->execute([$actId3]);

// Task 4 under Act 3: In Progress, Overdue (due 4 days ago -> Delayed, 20%)
$taskInsert = $db->prepare("
    INSERT INTO faculty_tasks (activity_id, faculty_name, committee, task_title, due_date, status, created_by)
    VALUES (?, 'GenEd Member', 'Academics', ?, ?, 'In Progress', ?)
");
$taskInsert->execute([$actId3, "GenEd Task 1 - Syllabus (20%) [{$testToken}]", $dueOverdue, $userGenEd['id']]);
$t4Id = (int)$db->lastInsertId();

// Task 5 under Act 3: Delayed status (30%)
$taskInsert->execute([$actId3, "GenEd Task 2 - Handouts (30%) [{$testToken}]", $dueNextMonth, $userGenEd['id']]);
$t5Id = (int)$db->lastInsertId();
$db->prepare("UPDATE faculty_tasks SET status = 'Delayed' WHERE id = ?")->execute([$t5Id]);

// Activity 4: Unassigned Department (Activity D)
$actStmt->execute([$userNoDept['id']]);
$actId4 = (int)$db->lastInsertId();
$db->prepare("UPDATE activities SET title = 'NoDept Showcase [{$testToken}]' WHERE id = ?")->execute([$actId4]);

// Task 6 under Act 4: Not Started (0%)
$taskInsertNotStarted = $db->prepare("
    INSERT INTO faculty_tasks (activity_id, faculty_name, committee, task_title, due_date, status, created_by)
    VALUES (?, 'NoDept Member', 'General', ?, ?, 'Not Started', ?)
");
$taskInsertNotStarted->execute([$actId4, "NoDept Task 1 - Planning [{$testToken}]", $dueNextMonth, $userNoDept['id']]);
$t6Id = (int)$db->lastInsertId();

assertCheck($t1Id && $t2Id && $t3Id && $t4Id && $t5Id && $t6Id, "Created 6 tasks across 4 activities and 3 department categories");

// ── 3. Dean View Verification ────────────────────────────────────────
echo "\n--- Dean Spearhead / Department Performance View & Calculations ---\n";
$deanRes = makeRequest("{$baseUrl}/dean/task-monitoring.php?q=" . urlencode($testToken), 'GET', [], $deanCookie);
assertCheck($deanRes['code'] === 200, "Dean loads task monitoring filtered by test token (HTTP 200)");

$html = $deanRes['body'];

// Confirm Spearheading Department Performance section & table presence
assertCheck(strpos($html, 'id="departmentPerformanceSection"') !== false, "Department Performance section present in DOM");
assertCheck(strpos($html, 'id="departmentPerformanceTable"') !== false, "Department Performance table present in DOM");

// Confirm required table headers: Spearheading Department | Activities | Total Tasks | Completed | In Progress | Delayed | Avg Completion | Status
assertCheck(strpos($html, '>Spearheading Department</th>') !== false, "Table header 'Spearheading Department' present");
assertCheck(strpos($html, '>Activities</th>') !== false, "Table header 'Activities' present");
assertCheck(strpos($html, '>Total Tasks</th>') !== false, "Table header 'Total Tasks' present");
assertCheck(strpos($html, '>Completed</th>') !== false, "Table header 'Completed' present");
assertCheck(strpos($html, '>In Progress</th>') !== false, "Table header 'In Progress' present");
assertCheck(strpos($html, '>Delayed</th>') !== false, "Table header 'Delayed' present");
assertCheck(strpos($html, '>Avg Completion</th>') !== false, "Table header 'Avg Completion' present");
assertCheck(strpos($html, '>Status</th>') !== false, "Table header 'Status' present");

// ── 4. Parse & Verify Each Department's Calculated Metrics ───────────
echo "\n--- Department-Specific Metrics Verification ---\n";

function parseDepartmentRow(string $html, string $deptName): ?array {
    $pattern = '/<tr[^>]*data-department="' . preg_quote($deptName, '/') . '"([^>]*)>/i';
    if (!preg_match($pattern, $html, $match)) {
        return null;
    }
    $attrs = $match[1];
    $data = [];
    if (preg_match('/data-activities="(\d+)"/', $attrs, $m)) $data['activities'] = (int)$m[1];
    if (preg_match('/data-total-tasks="(\d+)"/', $attrs, $m)) $data['total_tasks'] = (int)$m[1];
    if (preg_match('/data-completed="(\d+)"/', $attrs, $m)) $data['completed'] = (int)$m[1];
    if (preg_match('/data-in-progress="(\d+)"/', $attrs, $m)) $data['in_progress'] = (int)$m[1];
    if (preg_match('/data-not-started="(\d+)"/', $attrs, $m)) $data['not_started'] = (int)$m[1];
    if (preg_match('/data-delayed="(\d+)"/', $attrs, $m)) $data['delayed'] = (int)$m[1];
    if (preg_match('/data-avg-completion="([\d\.]+)"/', $attrs, $m)) $data['avg_completion'] = (float)$m[1];
    if (preg_match('/data-status="([^"]+)"/', $attrs, $m)) $data['status'] = $m[1];
    return $data;
}

// 1. IT Department: 2 activities (Act 1 & Act 2), 3 tasks (1 completed 100%, 1 in progress 50%, 1 in progress 40% -> avg = 63.3%, At Risk)
$itDept = parseDepartmentRow($html, 'IT Department');
assertCheck($itDept !== null, "IT Department row found in Department Performance table");
if ($itDept) {
    assertCheck($itDept['activities'] === 2, "IT Department distinct activities = 2 (actual: {$itDept['activities']})");
    assertCheck($itDept['total_tasks'] === 3, "IT Department total tasks = 3 (actual: {$itDept['total_tasks']})");
    assertCheck($itDept['completed'] === 1, "IT Department completed tasks = 1 (actual: {$itDept['completed']})");
    assertCheck($itDept['in_progress'] === 2, "IT Department in progress tasks = 2 (actual: {$itDept['in_progress']})");
    assertCheck($itDept['delayed'] === 0, "IT Department delayed tasks = 0 (actual: {$itDept['delayed']})");
    assertCheck($itDept['avg_completion'] == 63.3, "IT Department avg completion = 63.3% (actual: {$itDept['avg_completion']}%)");
    assertCheck($itDept['status'] === 'At Risk', "IT Department status = 'At Risk' (actual: '{$itDept['status']}')");
}

// 2. General Education: 1 activity (Act 3), 2 tasks (1 overdue In Progress [20%], 1 Delayed status [30%] -> avg = 25%, Delayed)
$genEd = parseDepartmentRow($html, 'General Education');
assertCheck($genEd !== null, "General Education row found in Department Performance table");
if ($genEd) {
    assertCheck($genEd['activities'] === 1, "General Education distinct activities = 1 (actual: {$genEd['activities']})");
    assertCheck($genEd['total_tasks'] === 2, "General Education total tasks = 2 (actual: {$genEd['total_tasks']})");
    assertCheck($genEd['completed'] === 0, "General Education completed tasks = 0 (actual: {$genEd['completed']})");
    assertCheck($genEd['delayed'] === 2, "General Education delayed tasks = 2 (overdue + status; actual: {$genEd['delayed']})");
    assertCheck($genEd['avg_completion'] == 25.0, "General Education avg completion = 25% (actual: {$genEd['avg_completion']}%)");
    assertCheck($genEd['status'] === 'Delayed', "General Education status = 'Delayed' (actual: '{$genEd['status']}')");
}

// 3. Unassigned: 1 activity (Act 4), 1 task (Not Started 0% -> On Track)
$unassigned = parseDepartmentRow($html, 'Unassigned');
assertCheck($unassigned !== null, "Unassigned department row found in Department Performance table");
if ($unassigned) {
    assertCheck($unassigned['activities'] === 1, "Unassigned distinct activities = 1 (actual: {$unassigned['activities']})");
    assertCheck($unassigned['total_tasks'] === 1, "Unassigned total tasks = 1 (actual: {$unassigned['total_tasks']})");
    assertCheck($unassigned['not_started'] === 1, "Unassigned not started = 1 (actual: {$unassigned['not_started']})");
    assertCheck($unassigned['completed'] === 0, "Unassigned completed = 0 (actual: {$unassigned['completed']})");
    assertCheck($unassigned['delayed'] === 0, "Unassigned delayed = 0 (actual: {$unassigned['delayed']})");
    assertCheck($unassigned['avg_completion'] == 0.0, "Unassigned avg completion = 0% (actual: {$unassigned['avg_completion']}%)");
    assertCheck($unassigned['status'] === 'On Track', "Unassigned status = 'On Track' (actual: '{$unassigned['status']}')");
}

// ── 5. Verify Filters Update Department Performance Summary ──────────
echo "\n--- Filter Responsiveness Verification ---\n";

// Filter by Dept/Source: dept:General Education
$filterDeptRes = makeRequest("{$baseUrl}/dean/task-monitoring.php?q=" . urlencode($testToken) . "&dept_source=" . urlencode('dept:General Education'), 'GET', [], $deanCookie);
assertCheck($filterDeptRes['code'] === 200, "Dept filter loads successfully (HTTP 200)");
$filterDeptHtml = $filterDeptRes['body'];

$filterGenEd = parseDepartmentRow($filterDeptHtml, 'General Education');
$filterIT    = parseDepartmentRow($filterDeptHtml, 'IT Department');
$filterUnass = parseDepartmentRow($filterDeptHtml, 'Unassigned');

assertCheck($filterGenEd !== null && $filterGenEd['total_tasks'] === 2, "Dept filter includes General Education");
assertCheck($filterIT === null, "Dept filter excludes IT Department");
assertCheck($filterUnass === null, "Dept filter excludes Unassigned");

// Filter by Timing: Delayed
$filterTimingRes = makeRequest("{$baseUrl}/dean/task-monitoring.php?q=" . urlencode($testToken) . "&timing=Delayed", 'GET', [], $deanCookie);
assertCheck($filterTimingRes['code'] === 200, "Timing=Delayed filter loads successfully (HTTP 200)");
$filterTimingHtml = $filterTimingRes['body'];

$timingGenEd = parseDepartmentRow($filterTimingHtml, 'General Education');
$timingIT    = parseDepartmentRow($filterTimingHtml, 'IT Department');

assertCheck($timingGenEd !== null && $timingGenEd['delayed'] === 2, "Timing=Delayed filter includes General Education (delayed tasks)");
assertCheck($timingIT === null, "Timing=Delayed filter excludes non-delayed IT Department tasks");

// Filter by Activity: Act 1 (IT Showcase A)
$filterActRes = makeRequest("{$baseUrl}/dean/task-monitoring.php?activity_id={$actId1}", 'GET', [], $deanCookie);
assertCheck($filterActRes['code'] === 200, "Activity filter loads successfully (HTTP 200)");
$filterActHtml = $filterActRes['body'];

$actIT = parseDepartmentRow($filterActHtml, 'IT Department');
assertCheck($actIT !== null && $actIT['activities'] === 1 && $actIT['total_tasks'] === 2, "Activity filter isolates Act 1 under IT Department (1 activity, 2 tasks)");

// ── 6. Authorization Enforcement Checks ──────────────────────────────
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

// ── 7. Cleanup Test Records ──────────────────────────────────────────
echo "\n--- Cleanup Test Records ---\n";
$allTestActIds = [$actId1, $actId2, $actId3, $actId4];
$inClause = implode(',', $allTestActIds);
$db->exec("DELETE FROM faculty_tasks WHERE activity_id IN ({$inClause})");
$db->exec("DELETE FROM activities WHERE id IN ({$inClause})");
echo "  [DONE] Test activities and tasks cleaned up.\n";

// ── Summary ──────────────────────────────────────────────────────────
echo "\n============================================================\n";
echo "  TEST SUMMARY: {$passCount} passed, {$failCount} failed\n";
echo "============================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
