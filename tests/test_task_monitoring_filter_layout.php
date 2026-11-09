<?php
/**
 * Automated Verification Suite for Task Monitoring Filter Bar Layout
 * Verifies that the filter bars across Dean, Program Head, and User Tasks
 * have the unified 4-column balanced layout, matching heights, and clean styling.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

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
echo "  TASK MONITORING FILTER BAR LAYOUT VERIFICATION\n";
echo "============================================================\n\n";

// 1. Dean Task Monitoring Inspection
echo "--- 1. Dean Task Monitoring Filter Bar Inspection ---\n";
$deanCookie = loginUser($baseUrl, 'dean@sti.edu', 'password');
assertCheck(!empty($deanCookie), "Dean authenticated with active session");

$deanPage = makeRequest("{$baseUrl}/dean/task-monitoring.php", 'GET', [], $deanCookie);
assertCheck($deanPage['code'] === 200, "Dean Task Monitoring loads with HTTP 200");
$deanHtml = $deanPage['body'];

assertCheck(str_contains($deanHtml, 'class="filter-card"'), "Dean page contains .filter-card wrapper");
assertCheck(str_contains($deanHtml, 'class="filter-grid"'), "Dean page contains .filter-grid form");
assertCheck(str_contains($deanHtml, 'grid-template-columns: repeat(4, 1fr)'), "Dean CSS defines 4-column balanced desktop grid");
assertCheck(str_contains($deanHtml, '@media (max-width: 1024px)'), "Dean CSS defines tablet responsive layout");
assertCheck(str_contains($deanHtml, '@media (max-width: 640px)'), "Dean CSS defines mobile responsive layout");
assertCheck(str_contains($deanHtml, 'class="filter-group filter-group-search"'), "Dean search filter group has filter-group-search class");
assertCheck(str_contains($deanHtml, 'height: 38px;'), "Dean form controls styled with synchronized 38px height");
assertCheck(str_contains($deanHtml, 'border-radius: 8px;'), "Dean form controls styled with modern 8px rounded corners");

// Check all 7 labels in Dean filter
assertCheck(str_contains($deanHtml, 'Activity</label>'), "Dean filter has Activity label");
assertCheck(str_contains($deanHtml, 'Spearhead / Source</label>'), "Dean filter has Spearhead / Source label");
assertCheck(str_contains($deanHtml, 'Committee</label>'), "Dean filter has Committee label");
assertCheck(str_contains($deanHtml, 'Assigned Member</label>'), "Dean filter has Assigned Member label");
assertCheck(str_contains($deanHtml, 'Status</label>'), "Dean filter has Status label");
assertCheck(str_contains($deanHtml, 'Timing Indicator</label>'), "Dean filter has Timing Indicator label");
assertCheck(str_contains($deanHtml, 'Search Task, Activity, Role</label>'), "Dean filter has Search Task, Activity, Role label");

// 2. Program Head Tasks Inspection
echo "\n--- 2. Program Head Department Task Monitoring Inspection ---\n";
$phCookie = loginUser($baseUrl, 'faculty@sti.edu', 'password'); // Program head / faculty test user
assertCheck(!empty($phCookie), "Program Head / Faculty logged in successfully");

$phPage = makeRequest("{$baseUrl}/faculty/tasks.php", 'GET', [], $phCookie);
assertCheck($phPage['code'] === 200, "PH Task Monitoring loads with HTTP 200");
$phHtml = $phPage['body'];

assertCheck(str_contains($phHtml, 'class="filter-card"'), "PH page contains .filter-card wrapper");
assertCheck(str_contains($phHtml, 'class="filter-grid"'), "PH page contains .filter-grid form");
assertCheck(str_contains($phHtml, 'Status</label>'), "PH filter has Status label");
assertCheck(str_contains($phHtml, 'Timing Indicator</label>'), "PH filter has Timing Indicator label");
assertCheck(str_contains($phHtml, 'Search Task, Activity, Role</label>'), "PH filter has Search Task, Activity, Role label");

// 3. Admin Tasks Inspection
echo "\n--- 3. Admin Tasks Inspection ---\n";
$adminCookie = loginUser($baseUrl, 'arjay@sti.edu', 'password');
assertCheck(!empty($adminCookie), "Admin 1 (arjay@sti.edu) logged in successfully");

$adminPage = makeRequest("{$baseUrl}/admin1/tasks.php", 'GET', [], $adminCookie);
assertCheck($adminPage['code'] === 200, "Admin 1 Tasks loads with HTTP 200");
$adminHtml = $adminPage['body'];

assertCheck(str_contains($adminHtml, 'class="filter-card"'), "Admin 1 page contains .filter-card wrapper");
assertCheck(str_contains($adminHtml, 'class="filter-grid"'), "Admin 1 page contains .filter-grid form");

// 4. Functional Filter Submission Test
echo "\n--- 4. Functional Filter Submission Verification ---\n";
$filteredPage = makeRequest("{$baseUrl}/dean/task-monitoring.php?status=Completed&timing=Completed", 'GET', [], $deanCookie);
assertCheck($filteredPage['code'] === 200, "Dean status & timing filter query returns HTTP 200");

$phFilterPage = makeRequest("{$baseUrl}/faculty/tasks.php?status=Completed", 'GET', [], $phCookie);
assertCheck($phFilterPage['code'] === 200, "PH status filter query returns HTTP 200");

echo "\n============================================================\n";
echo "  TEST SUMMARY: {$passCount} passed, {$failCount} failed\n";
echo "============================================================\n";

if ($failCount > 0) {
    exit(1);
}
