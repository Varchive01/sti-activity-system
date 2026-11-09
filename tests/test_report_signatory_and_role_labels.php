<?php
/**
 * Test Suite: Report Signatory & Role Labels Verification
 *
 * Verifies:
 * 1. Role label mapping functions in includes/functions.php
 * 2. HTML output of formal sign-off boxes in dean/generate-report.php
 * 3. Client-side PDF export data object (REPORT_PDF_DATA)
 * 4. Role-specific report titles and office headers for admin1, admin2, program_head, and dean
 */

$rootDir = realpath(__DIR__ . '/..');
require_once $rootDir . '/config/config.php';
require_once $rootDir . '/config/database.php';
require_once $rootDir . '/includes/functions.php';

$errors = [];
$successes = [];

function assertTest($condition, $successMsg, $failMsg) {
    global $errors, $successes;
    if ($condition) {
        $successes[] = "[PASS] " . $successMsg;
    } else {
        $errors[] = "[FAIL] " . $failMsg;
    }
}

echo "============================================================\n";
echo "  TEST SUITE: REPORT SIGNATORY & ROLE LABELS               \n";
echo "============================================================\n\n";

// 1. Function unit tests
assertTest(getUserRoleLabel('admin1') === 'Student Organization Head', 
    "getUserRoleLabel('admin1') === 'Student Organization Head'",
    "getUserRoleLabel('admin1') failed: " . getUserRoleLabel('admin1'));

assertTest(getUserRoleLabel('admin2') === 'Events Committee Head', 
    "getUserRoleLabel('admin2') === 'Events Committee Head'",
    "getUserRoleLabel('admin2') failed: " . getUserRoleLabel('admin2'));

assertTest(getUserRoleLabel('dean') === 'Dean', 
    "getUserRoleLabel('dean') === 'Dean'",
    "getUserRoleLabel('dean') failed: " . getUserRoleLabel('dean'));

assertTest(getUserRoleLabel('program_head') === 'Program Head', 
    "getUserRoleLabel('program_head') === 'Program Head'",
    "getUserRoleLabel('program_head') failed: " . getUserRoleLabel('program_head'));

assertTest(getUserRoleLabel('faculty') === 'Faculty', 
    "getUserRoleLabel('faculty') === 'Faculty'",
    "getUserRoleLabel('faculty') failed: " . getUserRoleLabel('faculty'));

assertTest(strpos(getRoleOfficeLabel('admin1'), 'Student Organizations Office') !== false,
    "getRoleOfficeLabel('admin1') contains 'Student Organizations Office'",
    "getRoleOfficeLabel('admin1') failed: " . getRoleOfficeLabel('admin1'));

assertTest(strpos(getRoleReportTitle('admin1'), 'Student Organization Activity') !== false,
    "getRoleReportTitle('admin1') contains 'Student Organization Activity'",
    "getRoleReportTitle('admin1') failed: " . getRoleReportTitle('admin1'));

// 2. HTTP Session verification
function makeReq(string $url, string $method = 'GET', array $data = [], ?string $cookie = null): array {
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false
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

function loginUser(string $email, string $password): ?string {
    $res = makeReq(BASE_URL . '/auth/login.php', 'POST', [
        'email' => $email,
        'password' => $password
    ]);
    if (preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $res['headers'], $matches)) {
        return !empty($matches[1]) ? end($matches[1]) : null;
    }
    return null;
}

echo "\n--- HTTP Live Testing for Signatory & Role Labels ---\n";

// Login as admin1 (Student Org Head)
$admin1Cookie = loginUser('arjay@sti.edu', 'password');
assertTest(!empty($admin1Cookie), "Admin 1 logged in successfully", "Admin 1 login failed");

$repResp = makeReq(BASE_URL . '/dean/generate-report.php', 'GET', [], $admin1Cookie);
assertTest($repResp['status'] === 200, "Admin 1 loads generate-report.php (HTTP 200)", "Report load failed (HTTP {$repResp['status']})");

// Check signatory box for Admin 1
assertTest(strpos($repResp['body'], 'Student Organization Head, STI College Marikina') !== false,
    "Formal Sign-off Box displays 'Student Organization Head, STI College Marikina'",
    "Formal Sign-off Box missing 'Student Organization Head, STI College Marikina'");

assertTest(strpos($repResp['body'], 'Admin1, STI College Marikina') === false,
    "Formal Sign-off Box does NOT contain raw 'Admin1, STI College Marikina'",
    "Formal Sign-off Box still contains raw 'Admin1, STI College Marikina'");

// Check jsPDF REPORT_PDF_DATA
assertTest(strpos($repResp['body'], "'preparer_role': 'Student Organization Head'") !== false || strpos($repResp['body'], '"preparer_role":"Student Organization Head"') !== false,
    "jsPDF data object sets preparer_role to 'Student Organization Head'",
    "jsPDF data object missing proper preparer_role");

// Check report title for Admin 1
assertTest(strpos($repResp['body'], 'Student Organization Activity Performance and Compliance Report') !== false,
    "Report title dynamically set to 'Student Organization Activity Performance and Compliance Report'",
    "Report title not updated for Student Org Head");

// Login as admin2 (Events Committee Head)
$admin2Cookie = loginUser('ian.barangan@marikina.sti.edu.ph', 'password');
assertTest(!empty($admin2Cookie), "Admin 2 logged in successfully", "Admin 2 login failed");

$repResp2 = makeReq(BASE_URL . '/dean/generate-report.php', 'GET', [], $admin2Cookie);
assertTest($repResp2['status'] === 200, "Admin 2 loads generate-report.php (HTTP 200)", "Admin 2 Report load failed");
assertTest(strpos($repResp2['body'], 'Events Committee Head, STI College Marikina') !== false,
    "Admin 2 Formal Sign-off Box displays 'Events Committee Head, STI College Marikina'",
    "Admin 2 Formal Sign-off Box missing 'Events Committee Head, STI College Marikina'");

// Login as Dean
$deanCookie = loginUser('dean@sti.edu', 'password');
assertTest(!empty($deanCookie), "Dean logged in successfully", "Dean login failed");

$repRespDean = makeReq(BASE_URL . '/dean/generate-report.php', 'GET', [], $deanCookie);
assertTest($repRespDean['status'] === 200, "Dean loads generate-report.php (HTTP 200)", "Dean Report load failed");
assertTest(strpos($repRespDean['body'], 'Dean, STI College Marikina') !== false,
    "Dean Formal Sign-off Box displays 'Dean, STI College Marikina'",
    "Dean Formal Sign-off Box missing 'Dean, STI College Marikina'");

echo "\n--- Test Results ---\n";
foreach ($successes as $s) {
    echo $s . "\n";
}
if (!empty($errors)) {
    echo "\nERRORS:\n";
    foreach ($errors as $e) {
        echo $e . "\n";
    }
    exit(1);
}

echo "\nAll Report Signatory & Role Label checks PASSED!\n";

