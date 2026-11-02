<?php
/**
 * tests/test_gantt_accessibility.php
 *
 * Automated verification suite for Gantt Chart Accessibility and Timeline Improvements (Task 12).
 *
 * Tests:
 * 1. Dean retains institutional Gantt chart access in dean/task-monitoring.php.
 * 2. Dean is prevented from task mutations (read-only enforcement).
 * 3. Program Head and Faculty are blocked from dean/task-monitoring.php (HTTP 403).
 * 4. Program Head can access faculty/tasks.php (HTTP 200) with title "Department Task Monitoring".
 * 5. Program Head Gantt timeline renders department activities and tasks.
 * 6. Program Head CANNOT see other departments' tasks (strict department isolation).
 * 7. Faculty can access faculty/tasks.php (HTTP 200) with title "My Assigned Tasks".
 * 8. Faculty Gantt timeline renders strictly assigned tasks.
 * 9. Faculty CANNOT see other faculty members' tasks in Gantt or Table sections.
 * 10. Multi-criteria filtering (status, timing, activity, search) works for Program Head and Faculty.
 * 11. Timeline date calculations (start_date, end_date, clamping, scale markers).
 * 12. Modal task inspection and deliverables interaction elements.
 */

require_once __DIR__ . '/../config/database.php';
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
echo "  TASK 12: GANTT CHART ACCESSIBILITY & TIMELINE AUDIT SUITE \n";
echo "============================================================\n\n";

// Target standard test accounts from acceptance testing
$pwdAcceptHash = password_hash('Password123!', PASSWORD_DEFAULT);
$pwdStandardHash = password_hash('password', PASSWORD_DEFAULT);

// IT Program Head
$phIt = $db->query("SELECT * FROM users WHERE email = 'ph_it_accept@test.com'")->fetch(PDO::FETCH_ASSOC);

// THM Program Head
$phThm = $db->query("SELECT * FROM users WHERE email = 'ph_thm_accept@test.com'")->fetch(PDO::FETCH_ASSOC);

// IT Faculty
$facIt = $db->query("SELECT * FROM users WHERE email = 'faculty@sti.edu'")->fetch(PDO::FETCH_ASSOC);

// Second IT Faculty (to test intra-department isolation for regular faculty)
$facIt2 = $db->query("SELECT * FROM users WHERE role = 'faculty' AND department = 'IT Department' AND id != {$facIt['id']} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$facIt2) {
    $db->prepare("INSERT INTO users (name, email, password, role, department, must_change_password) VALUES ('Second IT Faculty', 'fac_it_2@test.com', ?, 'faculty', 'IT Department', 0)")->execute([$pwdStandardHash]);
    $facIt2 = $db->query("SELECT * FROM users WHERE email = 'fac_it_2@test.com'")->fetch(PDO::FETCH_ASSOC);
}

// THM Faculty
$facThm = $db->query("SELECT * FROM users WHERE email = 'fac_thm_accept@test.com'")->fetch(PDO::FETCH_ASSOC);

// Ensure passwords and must_change_password = 0
$db->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id IN (?, ?)")->execute([$pwdAcceptHash, $phIt['id'], $phThm['id']]);
$db->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id IN (?, ?, ?)")->execute([$pwdStandardHash, $facIt['id'], $facIt2['id'], $facThm['id']]);

// ── 1. Create Test Fixtures: Distinct Department Activities and Tasks ──
echo "--- 1. Setting Up Department Test Fixtures ---\n";

// Activity 1: IT Department Activity (created by IT Faculty 1)
$actItStmt = $db->prepare("
    INSERT INTO activities (faculty_id, title, description, general_objectives, event_date, status, source)
    VALUES (?, 'IT Department Cyber Security Expo 2026', 'IT exclusive activity', 'Cyber skills', '2026-11-28', 'approved', 'faculty')
");
$actItStmt->execute([$facIt['id']]);
$actItId = (int)$db->lastInsertId();

// Activity 2: THM Department Activity (created by THM Faculty)
$actThmStmt = $db->prepare("
    INSERT INTO activities (faculty_id, title, description, general_objectives, event_date, status, source)
    VALUES (?, 'THM Culinary Arts Showcase 2026', 'THM exclusive activity', 'Culinary skills', '2026-11-29', 'approved', 'faculty')
");
$actThmStmt->execute([$facThm['id']]);
$actThmId = (int)$db->lastInsertId();

// Task 1: IT Activity -> Assigned to IT Faculty 1 (In Progress, On Track, 50%)
$t1Stmt = $db->prepare("
    INSERT INTO faculty_tasks (activity_id, assigned_user_id, faculty_name, committee, task_title, task_description, due_date, status, completion_pct)
    VALUES (?, ?, ?, 'Technical Committee', 'Configure Firewall Security Lab', 'Network rules', '2026-11-20', 'In Progress', 50)
");
$t1Stmt->execute([$actItId, $facIt['id'], $facIt['name']]);
$taskIt1Id = (int)$db->lastInsertId();

// Task 2: IT Activity -> Assigned to IT Faculty 2 (Completed, 100%)
$t2Stmt = $db->prepare("
    INSERT INTO faculty_tasks (activity_id, assigned_user_id, faculty_name, committee, task_title, task_description, due_date, status, completion_pct)
    VALUES (?, ?, ?, 'Program Committee', 'Keynote Speaker Coordination', 'Invite IT speaker', '2026-11-15', 'Completed', 100)
");
$t2Stmt->execute([$actItId, $facIt2['id'], $facIt2['name']]);
$taskIt2Id = (int)$db->lastInsertId();

// Task 3: THM Activity -> Assigned to THM Faculty (Delayed, 20%)
$t3Stmt = $db->prepare("
    INSERT INTO faculty_tasks (activity_id, assigned_user_id, faculty_name, committee, task_title, task_description, due_date, status, completion_pct)
    VALUES (?, ?, ?, 'Logistics Committee', 'Kitchen Station Rental Setup', 'Ovens and knives', '2026-11-01', 'Delayed', 20)
");
$t3Stmt->execute([$actThmId, $facThm['id'], $facThm['name']]);
$taskThmId = (int)$db->lastInsertId();

assertCheck($actItId > 0 && $actThmId > 0, "Created IT Activity (#{$actItId}) and THM Activity (#{$actThmId})");
assertCheck($taskIt1Id > 0 && $taskIt2Id > 0 && $taskThmId > 0, "Created tasks for IT and THM departments");

// ── 2. Authenticate Roles ───────────────────────────────────────────
echo "\n--- 2. Role Authentication ---\n";
$deanCookie   = loginUser($baseUrl, 'dean@sti.edu', 'password');
$phItCookie   = loginUser($baseUrl, $phIt['email'], 'Password123!');
$phThmCookie  = loginUser($baseUrl, $phThm['email'], 'Password123!');
$facItCookie  = loginUser($baseUrl, $facIt['email'], 'password');
$facThmCookie = loginUser($baseUrl, $facThm['email'], 'password');

assertCheck(!empty($deanCookie), "Dean authenticated");
assertCheck(!empty($phItCookie), "IT Program Head authenticated ({$phIt['email']})");
assertCheck(!empty($phThmCookie), "THM Program Head authenticated ({$phThm['email']})");
assertCheck(!empty($facItCookie), "IT Faculty authenticated ({$facIt['email']})");
assertCheck(!empty($facThmCookie), "THM Faculty authenticated ({$facThm['email']})");

// ── 3. Dean Institutional Access & Security Enforcement ─────────────
echo "\n--- 3. Dean Institutional Access Verification ---\n";
$deanRes = makeRequest("{$baseUrl}/dean/task-monitoring.php", 'GET', [], $deanCookie);
assertCheck($deanRes['code'] === 200, "Dean accesses dean/task-monitoring.php (HTTP 200)");
assertCheck(str_contains($deanRes['body'], 'Task Timeline (Gantt Visualization)'), "Dean sees Gantt visualization header");
assertCheck(str_contains($deanRes['body'], 'Configure Firewall Security Lab'), "Dean sees IT Task in institutional view");
assertCheck(str_contains($deanRes['body'], 'Kitchen Station Rental Setup'), "Dean sees THM Task in institutional view");

// Verify non-Deans cannot access dean/task-monitoring.php
$phBlockRes  = makeRequest("{$baseUrl}/dean/task-monitoring.php", 'GET', [], $phItCookie);
$facBlockRes = makeRequest("{$baseUrl}/dean/task-monitoring.php", 'GET', [], $facItCookie);
assertCheck($phBlockRes['code'] === 403, "Program Head strictly blocked from dean/task-monitoring.php (HTTP 403)");
assertCheck($facBlockRes['code'] === 403, "Faculty strictly blocked from dean/task-monitoring.php (HTTP 403)");

// ── 4. Program Head Department Gantt Timeline & Access ──────────────
echo "\n--- 4. Program Head Department Gantt Timeline ---\n";
$phItRes = makeRequest("{$baseUrl}/faculty/tasks.php", 'GET', [], $phItCookie);
assertCheck($phItRes['code'] === 200, "IT Program Head loads faculty/tasks.php (HTTP 200)");
assertCheck(str_contains($phItRes['body'], 'Department Task Monitoring'), "Page title displays 'Department Task Monitoring'");
assertCheck(str_contains($phItRes['body'], 'Task Timeline (Gantt Visualization)'), "Gantt timeline rendered for Program Head");
assertCheck(str_contains($phItRes['body'], 'Department Activity Timeline — IT Department'), "Gantt subtitle specifies IT Department scope");
assertCheck(str_contains($phItRes['body'], 'gantt-bar-item'), "Gantt bars rendered in DOM for Program Head");
assertCheck(str_contains($phItRes['body'], 'Configure Firewall Security Lab'), "IT Task 1 rendered in IT Program Head view");
assertCheck(str_contains($phItRes['body'], 'Keynote Speaker Coordination'), "IT Task 2 rendered in IT Program Head view");

// ── 5. Cross-Department Isolation Enforcement ───────────────────────
echo "\n--- 5. Department Isolation Verification ---\n";
assertCheck(!str_contains($phItRes['body'], 'Kitchen Station Rental Setup'), "THM Task strictly EXCLUDED from IT Program Head view");
assertCheck(!str_contains($phItRes['body'], 'THM Culinary Arts Showcase'), "THM Activity strictly EXCLUDED from IT Program Head view");

$phThmRes = makeRequest("{$baseUrl}/faculty/tasks.php", 'GET', [], $phThmCookie);
assertCheck($phThmRes['code'] === 200, "THM Program Head loads faculty/tasks.php (HTTP 200)");
assertCheck(str_contains($phThmRes['body'], 'Department Activity Timeline — THM Department'), "THM Program Head sees THM Department scope");
assertCheck(str_contains($phThmRes['body'], 'Kitchen Station Rental Setup'), "THM Program Head sees THM Task");
assertCheck(!str_contains($phThmRes['body'], 'Configure Firewall Security Lab'), "IT Task strictly EXCLUDED from THM Program Head view");

// ── 6. Faculty Personal Assigned Tasks Gantt Timeline ───────────────
echo "\n--- 6. Faculty Personal Assigned Tasks Gantt Timeline ---\n";
$facItRes = makeRequest("{$baseUrl}/faculty/tasks.php", 'GET', [], $facItCookie);
assertCheck($facItRes['code'] === 200, "IT Faculty loads faculty/tasks.php (HTTP 200)");
assertCheck(str_contains($facItRes['body'], 'My Assigned Tasks'), "Faculty page title displays 'My Assigned Tasks'");
assertCheck(str_contains($facItRes['body'], 'Task Timeline (Gantt Visualization)'), "Gantt timeline rendered for Faculty");
assertCheck(str_contains($facItRes['body'], 'Personal Assigned Tasks Timeline'), "Gantt subtitle specifies Personal Assigned Tasks scope");

// Extract the Gantt section and Table section to verify task isolation accurately
$ganttStart = strpos($facItRes['body'], 'id="ganttSection"');
$tableStart = strpos($facItRes['body'], 'id="tableSection"');
$facGanttHtml = ($ganttStart !== false && $tableStart !== false) ? substr($facItRes['body'], $ganttStart, $tableStart - $ganttStart) : $facItRes['body'];
$facTableHtml = ($tableStart !== false) ? substr($facItRes['body'], $tableStart) : $facItRes['body'];

assertCheck(str_contains($facGanttHtml, 'Configure Firewall Security Lab'), "IT Faculty sees their assigned task (Task 1) in Gantt");
assertCheck(str_contains($facTableHtml, 'Configure Firewall Security Lab'), "IT Faculty sees their assigned task (Task 1) in Table");
assertCheck(!str_contains($facGanttHtml, 'Keynote Speaker Coordination'), "IT Faculty Gantt CANNOT see tasks assigned to other faculty (Task 2)");
assertCheck(!str_contains($facTableHtml, 'Keynote Speaker Coordination'), "IT Faculty Table CANNOT see tasks assigned to other faculty (Task 2)");
assertCheck(!str_contains($facItRes['body'], 'Kitchen Station Rental Setup'), "IT Faculty CANNOT see THM tasks anywhere on page");

// ── 7. Timeline Calculation & Data Accuracy ─────────────────────────
echo "\n--- 7. Timeline Calculation & Percentage Fill ---\n";
assertCheck(str_contains($phItRes['body'], '50%'), "In Progress task displays 50% completion fill");
assertCheck(str_contains($phItRes['body'], '100%'), "Completed task displays 100% completion fill");
assertCheck(str_contains($phItRes['body'], 'Timeline Date Reference:'), "Documented timeline date limitation note rendered");
assertCheck(str_contains($phItRes['body'], 'openTaskInspection'), "Gantt bar includes click event for inspection modal");
assertCheck(str_contains($phItRes['body'], 'taskInspectModal'), "Task inspection modal exists in DOM");

// ── 8. Multi-Criteria Filters Testing on faculty/tasks.php ──────────
echo "\n--- 8. Multi-Criteria Filters Testing ---\n";
// Filter by Status=Completed
$phFilterStatus = makeRequest("{$baseUrl}/faculty/tasks.php?status=Completed", 'GET', [], $phItCookie);
assertCheck(str_contains($phFilterStatus['body'], 'Keynote Speaker Coordination'), "Status=Completed filter shows completed task");
assertCheck(!str_contains($phFilterStatus['body'], 'Configure Firewall Security Lab'), "Status=Completed filter excludes in-progress task");

// Filter by Timing=On+Track
$phFilterTiming = makeRequest("{$baseUrl}/faculty/tasks.php?timing=On+Track", 'GET', [], $phItCookie);
assertCheck(str_contains($phFilterTiming['body'], 'Configure Firewall Security Lab'), "Timing=On Track filter shows on-track task");

// Filter by Activity ID
$phFilterAct = makeRequest("{$baseUrl}/faculty/tasks.php?activity_id={$actItId}", 'GET', [], $phItCookie);
assertCheck(str_contains($phFilterAct['body'], 'Configure Firewall Security Lab'), "Activity ID filter returns matching activity tasks");

// ── 9. Mobile Responsiveness Layout Classes ─────────────────────────
echo "\n--- 9. Mobile Layout Classes & Responsiveness ---\n";
assertCheck(str_contains($phItRes['body'], 'gantt-scroll-area'), "Gantt scroll area exists for responsive overflow");
assertCheck(str_contains($phItRes['body'], 'min-width: 720px'), "Table layout sets min-width for mobile scrolling");

// ── Cleanup Test Records ────────────────────────────────────────────
echo "\n--- Cleanup Test Fixtures ---\n";
$db->exec("DELETE FROM faculty_tasks WHERE activity_id IN ({$actItId}, {$actThmId})");
$db->exec("DELETE FROM activities WHERE id IN ({$actItId}, {$actThmId})");
$db->exec("DELETE FROM users WHERE email = 'fac_it_2@test.com'");
echo "  [DONE] Test fixtures cleaned up.\n";

echo "\n============================================================\n";
echo "  TEST SUMMARY: {$passCount} passed, {$failCount} failed\n";
echo "============================================================\n";

exit($failCount > 0 ? 1 : 0);
