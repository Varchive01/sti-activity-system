<?php
/**
 * tests/test_kpi_reporting_improvements.php
 *
 * Automated verification suite for Task 13: KPI and Performance Reporting Improvements.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';

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
echo "  TASK 13: KPI AND PERFORMANCE REPORTING AUDIT SUITE \n";
echo "============================================================\n\n";

// Ensure passwords and clean state for test accounts
$pwdAcceptHash = password_hash('Password123!', PASSWORD_DEFAULT);
$pwdStandardHash = password_hash('password', PASSWORD_DEFAULT);

$dean = $db->query("SELECT * FROM users WHERE email = 'dean@sti.edu'")->fetch(PDO::FETCH_ASSOC);
$phIt = $db->query("SELECT * FROM users WHERE email = 'ph_it_accept@test.com'")->fetch(PDO::FETCH_ASSOC);
$phThm = $db->query("SELECT * FROM users WHERE email = 'ph_thm_accept@test.com'")->fetch(PDO::FETCH_ASSOC);
$facIt = $db->query("SELECT * FROM users WHERE email = 'faculty@sti.edu'")->fetch(PDO::FETCH_ASSOC);

$facThm = $db->query("SELECT * FROM users WHERE email = 'fac_thm_accept@test.com'")->fetch(PDO::FETCH_ASSOC);
if (!$facThm) {
    $db->prepare("INSERT INTO users (name, email, password, role, department, must_change_password) VALUES ('THM Faculty Member', 'fac_thm_accept@test.com', ?, 'faculty', 'THM Department', 0)")->execute([$pwdAcceptHash]);
    $facThm = $db->query("SELECT * FROM users WHERE email = 'fac_thm_accept@test.com'")->fetch(PDO::FETCH_ASSOC);
}

// Reset passwords
$db->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?")->execute([$pwdStandardHash, $dean['id']]);
$db->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id IN (?, ?)")->execute([$pwdAcceptHash, $phIt['id'], $phThm['id']]);
$db->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id IN (?, ?)")->execute([$pwdStandardHash, $facIt['id'], $facThm['id']]);

// Login cookies
$deanCookie = loginUser($baseUrl, 'dean@sti.edu', 'password');
$phItCookie = loginUser($baseUrl, 'ph_it_accept@test.com', 'Password123!');
$phThmCookie = loginUser($baseUrl, 'ph_thm_accept@test.com', 'Password123!');
$facItCookie = loginUser($baseUrl, 'faculty@sti.edu', 'password');

// --- Setup Test Activities in IT and THM Departments ---
echo "--- 1. Setting Up Department Activities Fixtures ---\n";

// IT Completed Activity with post-event and evaluations
$actItStmt = $db->prepare("
    INSERT INTO activities (faculty_id, title, description, general_objectives, event_date, status, source, target_participants)
    VALUES (?, 'IT Cybersecurity Innovation Workshop 2026', 'Cybersecurity training', 'Learn security', '2026-05-15', 'completed', 'faculty', 100)
");
$actItStmt->execute([$facIt['id']]);
$itActId = (int)$db->lastInsertId();

$db->prepare("
    INSERT INTO post_event (activity_id, actual_attendance, target_attendance, satisfaction_score, recommendations)
    VALUES (?, 95, 100, 4.8, 'Expand hands-on lab sessions.')
")->execute([$itActId]);

$db->prepare("INSERT INTO kpi_evaluations (activity_id, criteria, rating, comments, evaluator_name) VALUES (?, 'Relevance', 4, 'Very informative', 'Student A')")->execute([$itActId]);
$db->prepare("INSERT INTO kpi_evaluations (activity_id, criteria, rating, comments, evaluator_name) VALUES (?, 'Organization', 4, 'Well-structured', 'Student B')")->execute([$itActId]);
$db->prepare("INSERT INTO kpi_evaluations (activity_id, criteria, rating, comments, evaluator_name) VALUES (?, 'Speaker Quality', 3, 'Great speaker', 'Student C')")->execute([$itActId]);

// THM Completed Activity with post-event and evaluations
$actThmStmt = $db->prepare("
    INSERT INTO activities (faculty_id, title, description, general_objectives, event_date, status, source, target_participants)
    VALUES (?, 'THM Hospitality Leadership Summit 2026', 'Hospitality symposium', 'Hospitality skills', '2026-06-20', 'completed', 'faculty', 80)
");
$actThmStmt->execute([$facThm['id']]);
$thmActId = (int)$db->lastInsertId();

$db->prepare("
    INSERT INTO post_event (activity_id, actual_attendance, target_attendance, satisfaction_score, recommendations)
    VALUES (?, 75, 80, 4.5, 'Increase venue capacity.')
")->execute([$thmActId]);

$db->prepare("INSERT INTO kpi_evaluations (activity_id, criteria, rating, comments, evaluator_name) VALUES (?, 'Program Content', 4, 'Excellent content', 'Student D')")->execute([$thmActId]);
$db->prepare("INSERT INTO kpi_evaluations (activity_id, criteria, rating, comments, evaluator_name) VALUES (?, 'Coordination', 3, 'Good timing', 'Student E')")->execute([$thmActId]);

// IT Approved (not completed) Activity to test faculty status restriction
$actItApprovedStmt = $db->prepare("
    INSERT INTO activities (faculty_id, title, description, general_objectives, event_date, status, source, target_participants)
    VALUES (?, 'IT Cloud Infrastructure Bootcamp 2026', 'Cloud skills', 'Cloud learning', '2026-10-10', 'approved', 'faculty', 60)
");
$actItApprovedStmt->execute([$facIt['id']]);
$itApprovedActId = (int)$db->lastInsertId();

assertCheck($itActId > 0 && $thmActId > 0 && $itApprovedActId > 0, "Test fixtures created successfully (IT Completed #{$itActId}, THM Completed #{$thmActId}, IT Approved #{$itApprovedActId})");

// --- 2. Dean KPI Reports & Department Filtering (dean/kpi-reports.php) ---
echo "\n--- 2. Dean KPI Reports Interface & Department Filtering ---\n";

$res = makeRequest("{$baseUrl}/dean/kpi-reports.php?year=2026", 'GET', [], $deanCookie);
assertCheck($res['code'] === 200, "Dean accesses dean/kpi-reports.php successfully (HTTP 200)");
assertCheck(strpos($res['body'], 'name="department"') !== false, "dean/kpi-reports.php contains department filter dropdown");
assertCheck(strpos($res['body'], 'IT Department') !== false && strpos($res['body'], 'THM Department') !== false && strpos($res['body'], 'G.E. Department') !== false, "Department selector includes IT, THM, and G.E. departments");

// Dean filtering by IT Department
$resIt = makeRequest("{$baseUrl}/dean/kpi-reports.php?year=2026&department=IT+Department", 'GET', [], $deanCookie);
assertCheck($resIt['code'] === 200, "Dean filters KPI reports by IT Department (HTTP 200)");
assertCheck(strpos($resIt['body'], 'IT Cybersecurity Innovation Workshop') !== false, "IT activity appears in filtered KPI report");
assertCheck(strpos($resIt['body'], 'THM Hospitality Leadership Summit') === false, "THM activity is filtered out when IT Department is selected");

// Dean filtering by THM Department
$resThm = makeRequest("{$baseUrl}/dean/kpi-reports.php?year=2026&department=THM+Department", 'GET', [], $deanCookie);
assertCheck($resThm['code'] === 200, "Dean filters KPI reports by THM Department (HTTP 200)");
assertCheck(strpos($resThm['body'], 'THM Hospitality Leadership Summit') !== false, "THM activity appears in THM filtered report");
assertCheck(strpos($resThm['body'], 'IT Cybersecurity Innovation Workshop') === false, "IT activity is filtered out when THM Department is selected");

// Dean institutional view (All Departments)
$resAll = makeRequest("{$baseUrl}/dean/kpi-reports.php?year=2026&department=all", 'GET', [], $deanCookie);
assertCheck(strpos($resAll['body'], 'IT Cybersecurity Innovation Workshop') !== false && strpos($resAll['body'], 'THM Hospitality Leadership Summit') !== false, "Institutional view (all) includes both IT and THM activities");

// --- 3. Dean Aggregate Report Generator & Department Filter (dean/generate-report.php) ---
echo "\n--- 3. Dean Aggregate Report Generator & Department Filtering ---\n";

$resGen = makeRequest("{$baseUrl}/dean/generate-report.php?year=2026", 'GET', [], $deanCookie);
assertCheck($resGen['code'] === 200, "Dean accesses aggregate report generator (HTTP 200)");
assertCheck(strpos($resGen['body'], 'name="department"') !== false, "Aggregate report filter bar contains Department selector");

// Dean filtering aggregate report by IT Department
$resGenIt = makeRequest("{$baseUrl}/dean/generate-report.php?year=2026&department=IT+Department", 'GET', [], $deanCookie);
assertCheck($resGenIt['code'] === 200, "Dean generates aggregate report filtered to IT Department (HTTP 200)");
assertCheck(strpos($resGenIt['body'], 'IT Cybersecurity Innovation Workshop') !== false, "IT activity is present in IT filtered aggregate report");
assertCheck(strpos($resGenIt['body'], 'THM Hospitality Leadership Summit') === false, "THM activity is absent from IT filtered aggregate report");

// Dean filtering aggregate report by THM Department
$resGenThm = makeRequest("{$baseUrl}/dean/generate-report.php?year=2026&department=THM+Department", 'GET', [], $deanCookie);
assertCheck($resGenThm['code'] === 200, "Dean generates aggregate report filtered to THM Department (HTTP 200)");
assertCheck(strpos($resGenThm['body'], 'THM Hospitality Leadership Summit') !== false, "THM activity is present in THM filtered aggregate report");
assertCheck(strpos($resGenThm['body'], 'IT Cybersecurity Innovation Workshop') === false, "IT activity is absent from THM filtered aggregate report");

// Dean CSV export for aggregate report
$resGenCsv = makeRequest("{$baseUrl}/dean/generate-report.php?year=2026&department=IT+Department&export=csv", 'GET', [], $deanCookie);
assertCheck($resGenCsv['code'] === 200, "Dean exports filtered aggregate report as CSV (HTTP 200)");
assertCheck(strpos($resGenCsv['headers'], 'text/csv') !== false, "Response header confirms Content-Type: text/csv");
assertCheck(strpos($resGenCsv['body'], 'IT Cybersecurity Innovation Workshop') !== false, "CSV body contains IT activity");
assertCheck(strpos($resGenCsv['body'], 'THM Hospitality Leadership Summit') === false, "CSV body excludes THM activity when IT department is filtered");

// --- 4. Program Head Report Access & Backend Department Authorization ---
echo "\n--- 4. Program Head Report Generation & Department Authorization ---\n";

// Program Head unblocked from generate-report.php
$resPhB = makeRequest("{$baseUrl}/dean/generate-report.php?year=2026", 'GET', [], $phItCookie);
assertCheck($resPhB['code'] === 200, "Program Head successfully accesses aggregate report generator (HTTP 200 - previously 403 blocked)");
assertCheck(strpos($resPhB['body'], 'IT Department') !== false, "Program Head view displays authorized department (IT Department)");
assertCheck(strpos($resPhB['body'], 'IT Cybersecurity Innovation Workshop') !== false, "IT Program Head sees IT Department activities");
assertCheck(strpos($resPhB['body'], 'THM Hospitality Leadership Summit') === false, "IT Program Head CANNOT see other department activities (THM)");

// Department parameter tampering prevention
$resTamper = makeRequest("{$baseUrl}/dean/generate-report.php?year=2026&department=THM+Department", 'GET', [], $phItCookie);
assertCheck($resTamper['code'] === 200, "Tampered department request responded (HTTP 200)");
assertCheck(strpos($resTamper['body'], 'THM Hospitality Leadership Summit') === false, "Tampering override: IT Program Head is strictly prevented from seeing THM activities");
assertCheck(strpos($resTamper['body'], 'IT Cybersecurity Innovation Workshop') !== false, "Tampering override: Backend enforced IT Department data for IT Program Head");

// Program Head CSV export for department report
$resPhCsv = makeRequest("{$baseUrl}/dean/generate-report.php?year=2026&export=csv", 'GET', [], $phItCookie);
assertCheck($resPhCsv['code'] === 200, "Program Head exports department report to CSV (HTTP 200)");
assertCheck(strpos($resPhCsv['headers'], 'text/csv') !== false, "CSV export content type header verified");
assertCheck(strpos($resPhCsv['body'], 'IT Cybersecurity Innovation Workshop') !== false, "Department CSV contains IT activities");
assertCheck(strpos($resPhCsv['body'], 'THM Hospitality Leadership Summit') === false, "Department CSV strictly excludes other departments");

// Program Head individual report (Mode A) for department activity
$resPhA = makeRequest("{$baseUrl}/dean/generate-report.php?activity_id={$itActId}", 'GET', [], $phItCookie);
assertCheck($resPhA['code'] === 200, "Program Head accesses individual report for department activity (HTTP 200)");
assertCheck(strpos($resPhA['body'], 'IT Cybersecurity Innovation Workshop') !== false, "Individual report renders correct activity title");

// Program Head individual report BLOCKED for other department activity
$resPhABlocked = makeRequest("{$baseUrl}/dean/generate-report.php?activity_id={$thmActId}", 'GET', [], $phItCookie);
assertCheck($resPhABlocked['code'] === 403, "Program Head is BLOCKED from accessing individual report for another department activity (HTTP 403)");

// Program Head individual CSV export
$resPhIndCsv = makeRequest("{$baseUrl}/dean/generate-report.php?activity_id={$itActId}&export=csv", 'GET', [], $phItCookie);
assertCheck($resPhIndCsv['code'] === 200, "Program Head exports individual activity CSV (HTTP 200)");
assertCheck(strpos($resPhIndCsv['headers'], 'text/csv') !== false, "Individual CSV export Content-Type confirmed");

// --- 5. Faculty Permissions & Restrictions Enforcement ---
echo "\n--- 5. Faculty Permission & Security Safeguards ---\n";

// Faculty blocked from Mode B aggregate report
$resFacB = makeRequest("{$baseUrl}/dean/generate-report.php?year=2026", 'GET', [], $facItCookie);
assertCheck($resFacB['code'] === 403, "Faculty is BLOCKED from aggregate report generator (HTTP 403)");

// Faculty blocked from dean/kpi-reports.php
$resFacKpi = makeRequest("{$baseUrl}/dean/kpi-reports.php", 'GET', [], $facItCookie);
assertCheck($resFacKpi['code'] === 403, "Faculty is BLOCKED from dean/kpi-reports.php (HTTP 403)");

// Faculty can access own completed activity individual report
$resFacAOwn = makeRequest("{$baseUrl}/dean/generate-report.php?activity_id={$itActId}", 'GET', [], $facItCookie);
assertCheck($resFacAOwn['code'] === 200, "Faculty accesses individual report for own completed activity (HTTP 200)");

// Faculty BLOCKED from another faculty's activity report
$resFacAOther = makeRequest("{$baseUrl}/dean/generate-report.php?activity_id={$thmActId}", 'GET', [], $facItCookie);
assertCheck($resFacAOther['code'] === 403, "Faculty is BLOCKED from another faculty's activity report (HTTP 403)");

// Faculty BLOCKED from own activity that is NOT completed (e.g. approved)
$resFacANotComp = makeRequest("{$baseUrl}/dean/generate-report.php?activity_id={$itApprovedActId}", 'GET', [], $facItCookie);
assertCheck($resFacANotComp['code'] === 403, "Faculty is BLOCKED from individual report if activity is not completed (HTTP 403)");

// --- 6. Program Head Dedicated KPI Overview in faculty/kpi.php ---
echo "\n--- 6. Program Head KPI Overview in faculty/kpi.php ---\n";

$resPhKpi = makeRequest("{$baseUrl}/faculty/kpi.php", 'GET', [], $phItCookie);
assertCheck($resPhKpi['code'] === 200, "Program Head accesses faculty/kpi.php (HTTP 200)");
assertCheck(strpos($resPhKpi['body'], 'Department KPI & Performance Overview — IT Department') !== false, "Page renders Department KPI & Performance Overview banner");
assertCheck(strpos($resPhKpi['body'], 'Generate Department Report') !== false, "Overview includes 'Generate Department Report' action link");
assertCheck(strpos($resPhKpi['body'], 'Export Department CSV') !== false, "Overview includes 'Export Department CSV' action link");
assertCheck(strpos($resPhKpi['body'], 'summary-strip') !== false, "Department performance metric summary strip is rendered");
assertCheck(strpos($resPhKpi['body'], 'month-bar-wrap') !== false, "Monthly activity and KPI performance trend chart is rendered");
assertCheck(strpos($resPhKpi['body'], 'Department Activity Performance Register') !== false, "Comparative department activities table is rendered");
assertCheck(strpos($resPhKpi['body'], 'IT Cybersecurity Innovation Workshop') !== false, "IT department activity is listed in performance register");
assertCheck(strpos($resPhKpi['body'], 'THM Hospitality Leadership Summit') === false, "Cross-department isolation: THM activity is excluded for IT Program Head");

// Faculty view of faculty/kpi.php
$resFacKpiPage = makeRequest("{$baseUrl}/faculty/kpi.php", 'GET', [], $facItCookie);
assertCheck($resFacKpiPage['code'] === 200, "Faculty accesses faculty/kpi.php (HTTP 200)");
assertCheck(strpos($resFacKpiPage['body'], 'KPI Results') !== false, "Faculty view renders personal 'KPI Results' page");
assertCheck(strpos($resFacKpiPage['body'], 'IT Cybersecurity Innovation Workshop') !== false, "Faculty sees personal activity");
assertCheck(strpos($resFacKpiPage['body'], 'THM Hospitality Leadership Summit') === false, "Faculty CANNOT see other faculty's activities");

// --- 7. Sidebar Navigation Verification ---
echo "\n--- 7. Cross-Role Sidebar Navigation Verification ---\n";

$sidebarContent = file_get_contents(__DIR__ . '/../includes/sidebar.php');
assertCheck(strpos($sidebarContent, "['icon' => 'bar-chart', 'label' => 'KPI and Evaluation Reports', 'href' => BASE_URL . '/faculty/kpi.php']") !== false, "Program Head sidebar has 'KPI and Evaluation Reports' link");
assertCheck(strpos($sidebarContent, "['icon' => 'file-text', 'label' => 'Generate Report', 'href' => BASE_URL . '/dean/generate-report.php']") !== false, "Program Head sidebar has 'Generate Report' link");

// --- 8. KPI Calculation & Edge Case Integrity Tests ---
echo "\n--- 8. KPI Calculations & Edge Case Handling ---\n";

// Helper function tests
$statusExceeded = getKpiStatus(115.0);
$statusMet = getKpiStatus(95.0);
$statusNear = getKpiStatus(75.0);
$statusBelow = getKpiStatus(50.0);
$statusNull = getKpiStatus(null);

assertCheck($statusExceeded === 'Exceeded the Target', "KPI status threshold >= 110% correctly classifies as 'Exceeded the Target'");
assertCheck($statusMet === 'Met the Target', "KPI status threshold >= 90% correctly classifies as 'Met the Target'");
assertCheck($statusNear === 'Closed to the target', "KPI status threshold >= 70% correctly classifies as 'Closed to the target'");
assertCheck($statusBelow === 'Did not meet the target', "KPI status threshold < 70% correctly classifies as 'Did not meet the target'");
assertCheck($statusNull === 'Insufficient Data', "KPI status with null achievement correctly returns 'Insufficient Data'");

// Edge case: Activity with 0 target attendance (prevent division by zero)
$zeroTargetKpi = calculateActivityKpis($itApprovedActId, $db);
assertCheck(is_array($zeroTargetKpi), "calculateActivityKpis executes safely on activity without post-event data");

// Clean up test activities
echo "\n--- Cleaning Up Test Fixtures ---\n";
$db->prepare("DELETE FROM kpi_evaluations WHERE activity_id IN (?, ?)")->execute([$itActId, $thmActId]);
$db->prepare("DELETE FROM post_event WHERE activity_id IN (?, ?)")->execute([$itActId, $thmActId]);
$db->prepare("DELETE FROM activities WHERE id IN (?, ?, ?)")->execute([$itActId, $thmActId, $itApprovedActId]);
echo "Test fixtures successfully cleaned up.\n\n";

echo "============================================================\n";
echo "  AUDIT RESULTS: {$passCount} Passed, {$failCount} Failed\n";
echo "============================================================\n";

if ($failCount === 0) {
    exit(0);
} else {
    exit(1);
}
