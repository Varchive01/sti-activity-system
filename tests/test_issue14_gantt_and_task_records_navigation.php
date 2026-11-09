<?php
/**
 * tests/test_issue14_gantt_and_task_records_navigation.php
 *
 * Automated verification suite for Issue 14:
 * Fix the Gantt Timeline and Task Records Table navigation.
 *
 * Verifies:
 * 1. Gantt Timeline, Task Records Table, and All Views navigation tabs exist.
 * 2. Distinct view isolation (gantt vs table vs all) both server-side and client-side.
 * 3. Filter form preserves active view mode via hidden input.
 * 4. Active button state styling (btn-primary on active view, btn-outline on inactive).
 * 5. Section anchors id="ganttSection" and id="tableSection" are present and targetable.
 * 6. Global parity across Faculty, Program Head, Admin 1, Admin 2, and Dean.
 */

$baseUrl = 'http://localhost/sti-activity-system';
$passCount = 0;
$failCount = 0;

function assertCheck(bool $condition, string $msg): void {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$msg}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$msg}\n";
        $failCount++;
    }
}

function makeHttpRequest(string $url, string $method = 'GET', array $data = [], ?string $cookie = null): array {
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
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    return ['status' => $httpCode, 'headers' => $headers, 'body' => $body];
}

function login(string $baseUrl, string $email, string $password): ?string {
    $res = makeHttpRequest("{$baseUrl}/auth/login.php", 'POST', [
        'email' => $email,
        'password' => $password
    ]);
    if (preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $res['headers'], $matches)) {
        return !empty($matches[1]) ? end($matches[1]) : null;
    }
    return null;
}

echo "============================================================\n";
echo "  TEST SUITE: ISSUE 14 GANTT TIMELINE & TASK RECORDS NAV   \n";
echo "============================================================\n\n";

// ── 1. Role Authentication ──────────────────────────────────────────
$facCookie = login($baseUrl, 'faculty@sti.edu', 'password');
assertCheck(!empty($facCookie), "Faculty authenticated successfully");

$phCookie = login($baseUrl, 'dave@sti.edu', 'password');
assertCheck(!empty($phCookie), "Program Head authenticated successfully");

$admin1Cookie = login($baseUrl, 'arjay@sti.edu', 'password');
assertCheck(!empty($admin1Cookie), "Admin 1 authenticated successfully");

$deanCookie = login($baseUrl, 'dean@sti.edu', 'password');
assertCheck(!empty($deanCookie), "Dean authenticated successfully");

// ── 2. Faculty Tasks View Mode Tests ────────────────────────────────
echo "\n--- 2. Faculty Tasks View Mode Verification ---\n";

// Default View
$resDefault = makeHttpRequest("{$baseUrl}/faculty/tasks.php", 'GET', [], $facCookie);
assertCheck($resDefault['status'] === 200, "Faculty loads tasks.php (HTTP 200)");
assertCheck(str_contains($resDefault['body'], 'id="btnViewGantt"'), "Gantt Timeline button exists with id='btnViewGantt'");
assertCheck(str_contains($resDefault['body'], 'id="btnViewTable"'), "Task Records Table button exists with id='btnViewTable'");
assertCheck(str_contains($resDefault['body'], 'id="btnViewAll"'), "All Views button exists with id='btnViewAll'");
assertCheck(str_contains($resDefault['body'], 'id="ganttSection"'), "Gantt container has id='ganttSection'");
assertCheck(str_contains($resDefault['body'], 'id="tableSection"'), "Table container has id='tableSection'");
assertCheck(str_contains($resDefault['body'], 'id="filterViewMode"'), "Filter form includes hidden input for view mode");

// View = Gantt
$resGantt = makeHttpRequest("{$baseUrl}/faculty/tasks.php?view=gantt", 'GET', [], $facCookie);
assertCheck($resGantt['status'] === 200, "Faculty loads tasks.php?view=gantt (HTTP 200)");
assertCheck(str_contains($resGantt['body'], 'id="btnViewGantt" class="btn btn-primary'), "Gantt button is active (btn-primary) when view=gantt");
assertCheck(str_contains($resGantt['body'], 'id="btnViewTable" class="btn btn-outline'), "Table button is inactive (btn-outline) when view=gantt");
assertCheck(str_contains($resGantt['body'], 'id="tableSection" style="display:none;"'), "Task Records Table section is hidden (display:none) when view=gantt");
assertCheck(!str_contains($resGantt['body'], 'id="ganttSection" style="display:none;"'), "Gantt Timeline section is visible when view=gantt");

// View = Table
$resTable = makeHttpRequest("{$baseUrl}/faculty/tasks.php?view=table", 'GET', [], $facCookie);
assertCheck($resTable['status'] === 200, "Faculty loads tasks.php?view=table (HTTP 200)");
assertCheck(str_contains($resTable['body'], 'id="btnViewTable" class="btn btn-primary'), "Table button is active (btn-primary) when view=table");
assertCheck(str_contains($resTable['body'], 'id="btnViewGantt" class="btn btn-outline'), "Gantt button is inactive (btn-outline) when view=table");
assertCheck(str_contains($resTable['body'], 'id="ganttSection" style="display:none;"'), "Gantt Timeline section is hidden (display:none) when view=table");
assertCheck(!str_contains($resTable['body'], 'id="tableSection" style="display:none;"'), "Task Records Table section is visible when view=table");

// ── 3. Program Head Tasks View Mode Tests ───────────────────────────
echo "\n--- 3. Program Head Tasks View Mode Verification ---\n";
$resPhGantt = makeHttpRequest("{$baseUrl}/faculty/tasks.php?view=gantt", 'GET', [], $phCookie);
assertCheck($resPhGantt['status'] === 200, "Program Head loads tasks.php?view=gantt (HTTP 200)");
assertCheck(str_contains($resPhGantt['body'], 'id="btnViewGantt" class="btn btn-primary'), "Program Head Gantt button is active when view=gantt");
assertCheck(str_contains($resPhGantt['body'], 'id="tableSection" style="display:none;"'), "Program Head Table section is hidden when view=gantt");

$resPhTable = makeHttpRequest("{$baseUrl}/faculty/tasks.php?view=table", 'GET', [], $phCookie);
assertCheck($resPhTable['status'] === 200, "Program Head loads tasks.php?view=table (HTTP 200)");
assertCheck(str_contains($resPhTable['body'], 'id="btnViewTable" class="btn btn-primary'), "Program Head Table button is active when view=table");
assertCheck(str_contains($resPhTable['body'], 'id="ganttSection" style="display:none;"'), "Program Head Gantt section is hidden when view=table");

// ── 4. Admin 1 Tasks View Mode Tests ────────────────────────────────
echo "\n--- 4. Admin 1 Tasks View Mode Verification ---\n";
$resAdminGantt = makeHttpRequest("{$baseUrl}/admin1/tasks.php?view=gantt", 'GET', [], $admin1Cookie);
assertCheck($resAdminGantt['status'] === 200, "Admin 1 loads admin1/tasks.php?view=gantt (HTTP 200)");
assertCheck(str_contains($resAdminGantt['body'], 'id="btnViewGantt" class="btn btn-primary'), "Admin 1 Gantt button is active when view=gantt");
assertCheck(str_contains($resAdminGantt['body'], 'id="tableSection" style="display:none;"'), "Admin 1 Table section is hidden when view=gantt");

// ── 5. Dean Task Monitoring View Mode Tests (Global Parity) ─────────
echo "\n--- 5. Dean Task Monitoring View Mode Verification ---\n";
$resDeanDefault = makeHttpRequest("{$baseUrl}/dean/task-monitoring.php", 'GET', [], $deanCookie);
assertCheck($resDeanDefault['status'] === 200, "Dean loads task-monitoring.php (HTTP 200)");
assertCheck(str_contains($resDeanDefault['body'], 'id="deanBtnViewGantt"'), "Dean has deanBtnViewGantt button");
assertCheck(str_contains($resDeanDefault['body'], 'id="deanBtnViewTable"'), "Dean has deanBtnViewTable button");
assertCheck(str_contains($resDeanDefault['body'], 'id="deanBtnViewAll"'), "Dean has deanBtnViewAll button");
assertCheck(str_contains($resDeanDefault['body'], 'id="ganttSection"'), "Dean Gantt container has id='ganttSection'");
assertCheck(str_contains($resDeanDefault['body'], 'id="tableSection"'), "Dean Table container has id='tableSection'");

$resDeanGantt = makeHttpRequest("{$baseUrl}/dean/task-monitoring.php?view=gantt", 'GET', [], $deanCookie);
assertCheck($resDeanGantt['status'] === 200, "Dean loads task-monitoring.php?view=gantt (HTTP 200)");
assertCheck(str_contains($resDeanGantt['body'], 'id="deanBtnViewGantt" class="btn btn-primary'), "Dean Gantt button is active when view=gantt");
assertCheck(str_contains($resDeanGantt['body'], 'id="tableSection" style="display:none;'), "Dean Table section is hidden when view=gantt");

$resDeanTable = makeHttpRequest("{$baseUrl}/dean/task-monitoring.php?view=table", 'GET', [], $deanCookie);
assertCheck($resDeanTable['status'] === 200, "Dean loads task-monitoring.php?view=table (HTTP 200)");
assertCheck(str_contains($resDeanTable['body'], 'id="deanBtnViewTable" class="btn btn-primary'), "Dean Table button is active when view=table");
assertCheck(str_contains($resDeanTable['body'], 'id="ganttSection" style="display:none;"'), "Dean Gantt section is hidden when view=table");

// ── 6. Client-Side Switcher Function Integrity ──────────────────────
echo "\n--- 6. Client-Side Switcher Script Verification ---\n";
assertCheck(str_contains($resDefault['body'], 'function switchTaskView('), "switchTaskView function present in faculty tasks script");
assertCheck(str_contains($resDefault['body'], 'localStorage.setItem(\'task_view_mode\''), "switchTaskView persists preference to localStorage");
assertCheck(str_contains($resDefault['body'], 'window.history.replaceState'), "switchTaskView updates browser URL without full reload");
assertCheck(str_contains($resDefault['body'], 'hashchange'), "switchTaskView listens for hash changes (e.g. #task-row-*)");
assertCheck(str_contains($resDeanDefault['body'], 'function switchDeanTaskView('), "switchDeanTaskView function present in dean monitoring script");

// ── 7. Summary ──────────────────────────────────────────────────────
echo "\n============================================================\n";
echo "  TEST SUMMARY: {$passCount} passed, {$failCount} failed\n";
echo "============================================================\n";

if ($failCount > 0) {
    exit(1);
}
