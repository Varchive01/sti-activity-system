<?php
/**
 * Test Suite: Objective 9 Report Navigation and Role Access
 *
 * Requirements:
 * 1. Admin1 and Admin2 sidebars have the Generate Report entry.
 * 2. Dean navigation is preserved.
 * 3. Faculty can access a report only when:
 *    - the activity belongs to the authenticated faculty user, AND
 *    - the activity status is 'completed'.
 * 4. Faculty cannot access aggregate reports or another faculty's activity report.
 * 5. Faculty CSV ownership restriction works (?export=csv).
 * 6. Dean, Admin1, Admin2 authorization is preserved.
 * 7. Unauthenticated users remain blocked.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

$passedTests = 0;
$failedTests = 0;

function assertCheck(string $name, bool $condition, string $details = ""): void {
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
echo "  Objective 9 Report Navigation & Role Access Tests\n";
echo "============================================================\n\n";

// 1. Verify Sidebar Structure
echo "--- 1. Sidebar Navigation Verification ---\n";
$sidebarContent = file_get_contents(__DIR__ . '/../includes/sidebar.php');

// Test Admin1 sidebar
assertCheck(
    "Admin1 sidebar has Generate Report navigation entry",
    str_contains($sidebarContent, "'admin1'") &&
    preg_match("/'admin1'\s*=>\s*\[.*?'label'\s*=>\s*'Generate Report'.*?'href'\s*=>\s*BASE_URL\s*\.\s*'\/dean\/generate-report\.php'/s", $sidebarContent) === 1,
    "Generate Report entry missing in admin1 sidebar"
);

// Test Admin2 sidebar
assertCheck(
    "Admin2 sidebar has Generate Report navigation entry",
    str_contains($sidebarContent, "'admin2'") &&
    preg_match("/'admin2'\s*=>\s*\[.*?'label'\s*=>\s*'Generate Report'.*?'href'\s*=>\s*BASE_URL\s*\.\s*'\/dean\/generate-report\.php'/s", $sidebarContent) === 1,
    "Generate Report entry missing in admin2 sidebar"
);

// Test Dean sidebar
assertCheck(
    "Dean sidebar preserves Generate Report navigation entry",
    str_contains($sidebarContent, "'dean'") &&
    preg_match("/'dean'\s*=>\s*\[.*?'label'\s*=>\s*'Generate Report'.*?'href'\s*=>\s*BASE_URL\s*\.\s*'\/dean\/generate-report\.php'/s", $sidebarContent) === 1,
    "Generate Report entry missing in dean sidebar"
);

// 2. Setup Test Database Users & Activities
$facultyUsers = $db->query("SELECT id, name, role FROM users WHERE role = 'faculty' ORDER BY id ASC LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
if (count($facultyUsers) < 2) {
    // Create secondary faculty for ownership isolation testing
    $db->prepare("INSERT INTO users (name, email, password, role, dept) VALUES ('Secondary Faculty', 'sec_faculty@sti.edu', 'hash', 'faculty', 'IT')")->execute();
    $fac2Id = (int)$db->lastInsertId();
    $facultyUsers[] = ['id' => $fac2Id, 'name' => 'Secondary Faculty', 'role' => 'faculty'];
}

$faculty1 = $facultyUsers[0];
$faculty2 = $facultyUsers[1];
$fac1Id = (int)$faculty1['id'];
$fac2Id = (int)$faculty2['id'];

$dean = $db->query("SELECT id, name, role FROM users WHERE role = 'dean' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin1 = $db->query("SELECT id, name, role FROM users WHERE role = 'admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin2 = $db->query("SELECT id, name, role FROM users WHERE role = 'admin2' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$deanId = (int)$dean['id'];
$admin1Id = (int)$admin1['id'];
$admin2Id = (int)$admin2['id'];

// Create 3 activities:
// Act 1: Faculty 1 - completed
// Act 2: Faculty 1 - approved (NOT completed)
// Act 3: Faculty 2 - completed
$stmt = $db->prepare("
    INSERT INTO activities (title, faculty_id, event_date, venue, status, target_participants, source)
    VALUES (?, ?, '2026-11-25', 'STI Hall', ?, 100, 'faculty')
");

$stmt->execute(['Fac1 Completed Workshop', $fac1Id, 'completed']);
$act1CompletedId = (int)$db->lastInsertId();

$stmt->execute(['Fac1 Approved Only Workshop', $fac1Id, 'approved']);
$act2ApprovedId = (int)$db->lastInsertId();

$stmt->execute(['Fac2 Completed Seminar', $fac2Id, 'completed']);
$act3OtherFacId = (int)$db->lastInsertId();

echo "Created Test Activities: Act1 (Fac1 Completed) = {$act1CompletedId}, Act2 (Fac1 Approved) = {$act2ApprovedId}, Act3 (Fac2 Completed) = {$act3OtherFacId}\n\n";

// Helper session management
$sessions = [
    'dean' => bin2hex(random_bytes(16)),
    'admin1' => bin2hex(random_bytes(16)),
    'admin2' => bin2hex(random_bytes(16)),
    'fac1' => bin2hex(random_bytes(16)),
    'fac2' => bin2hex(random_bytes(16))
];

function writeSession(string $sid, array $userData): void {
    $data = [
        'user_id' => (int)$userData['id'],
        'user_role' => $userData['role'],
        'role' => $userData['role'],
        'user_name' => $userData['name'],
        'name' => $userData['name'],
        'must_change_password' => 0,
        'last_activity' => time()
    ];
    $content = '';
    foreach ($data as $k => $v) {
        $content .= $k . '|' . serialize($v);
    }
    file_put_contents('C:/xampp/tmp/sess_' . $sid, $content);
}

function clearSession(string $sid): void {
    $p = 'C:/xampp/tmp/sess_' . $sid;
    if (file_exists($p)) @unlink($p);
}

writeSession($sessions['dean'], $dean);
writeSession($sessions['admin1'], $admin1);
writeSession($sessions['admin2'], $admin2);
writeSession($sessions['fac1'], $faculty1);
writeSession($sessions['fac2'], $faculty2);

function requestReport(string $url, ?string $sid): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    if ($sid !== null) {
        curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $sid);
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($res, 0, $hSize);
    $body = substr($res, $hSize);
    return ['code' => $code, 'headers' => $headers, 'body' => $body];
}

$baseUrl = 'http://localhost/sti-activity-system/dean/generate-report.php';

try {
    // -------------------------------------------------------------
    // TEST 2: Admin1 Access
    // -------------------------------------------------------------
    echo "--- 2. Admin1 Access Verification ---\n";
    $resAdmin1Ind = requestReport($baseUrl . '?activity_id=' . $act1CompletedId, $sessions['admin1']);
    assertCheck("Admin1 can access individual activity report", $resAdmin1Ind['code'] === 200 && str_contains($resAdmin1Ind['body'], 'Fac1 Completed Workshop'));

    $resAdmin1Agg = requestReport($baseUrl, $sessions['admin1']);
    assertCheck("Admin1 can access aggregate report", $resAdmin1Agg['code'] === 200 && str_contains($resAdmin1Agg['body'], 'Generate Report'));

    $resAdmin1Csv = requestReport($baseUrl . '?activity_id=' . $act1CompletedId . '&export=csv', $sessions['admin1']);
    assertCheck("Admin1 can export CSV", $resAdmin1Csv['code'] === 200 && stripos($resAdmin1Csv['headers'], 'Content-Type: text/csv') !== false);

    // -------------------------------------------------------------
    // TEST 3: Admin2 Access
    // -------------------------------------------------------------
    echo "--- 3. Admin2 Access Verification ---\n";
    $resAdmin2Ind = requestReport($baseUrl . '?activity_id=' . $act1CompletedId, $sessions['admin2']);
    assertCheck("Admin2 can access individual activity report", $resAdmin2Ind['code'] === 200 && str_contains($resAdmin2Ind['body'], 'Fac1 Completed Workshop'));

    $resAdmin2Agg = requestReport($baseUrl, $sessions['admin2']);
    assertCheck("Admin2 can access aggregate report", $resAdmin2Agg['code'] === 200 && str_contains($resAdmin2Agg['body'], 'Generate Report'));

    $resAdmin2Csv = requestReport($baseUrl . '?activity_id=' . $act1CompletedId . '&export=csv', $sessions['admin2']);
    assertCheck("Admin2 can export CSV", $resAdmin2Csv['code'] === 200 && stripos($resAdmin2Csv['headers'], 'Content-Type: text/csv') !== false);

    // -------------------------------------------------------------
    // TEST 4: Dean Access
    // -------------------------------------------------------------
    echo "--- 4. Dean Access Verification ---\n";
    $resDeanInd = requestReport($baseUrl . '?activity_id=' . $act1CompletedId, $sessions['dean']);
    assertCheck("Dean can access individual activity report", $resDeanInd['code'] === 200 && str_contains($resDeanInd['body'], 'Fac1 Completed Workshop'));

    $resDeanAgg = requestReport($baseUrl, $sessions['dean']);
    assertCheck("Dean can access aggregate report", $resDeanAgg['code'] === 200 && str_contains($resDeanAgg['body'], 'Generate Report'));

    $resDeanCsv = requestReport($baseUrl . '?activity_id=' . $act1CompletedId . '&export=csv', $sessions['dean']);
    assertCheck("Dean can export CSV", $resDeanCsv['code'] === 200 && stripos($resDeanCsv['headers'], 'Content-Type: text/csv') !== false);

    // -------------------------------------------------------------
    // TEST 5: Faculty Access - Own Completed Activity
    // -------------------------------------------------------------
    echo "--- 5. Faculty Access (Own Completed Activity) ---\n";
    $resFacOwnComp = requestReport($baseUrl . '?activity_id=' . $act1CompletedId, $sessions['fac1']);
    assertCheck("Faculty can access their own completed activity report (HTTP 200)", $resFacOwnComp['code'] === 200 && str_contains($resFacOwnComp['body'], 'Fac1 Completed Workshop'));

    $resFacOwnCompCsv = requestReport($baseUrl . '?activity_id=' . $act1CompletedId . '&export=csv', $sessions['fac1']);
    assertCheck("Faculty can export CSV for their own completed activity", $resFacOwnCompCsv['code'] === 200 && stripos($resFacOwnCompCsv['headers'], 'Content-Type: text/csv') !== false);

    // -------------------------------------------------------------
    // TEST 6: Faculty Access Restrictions
    // -------------------------------------------------------------
    echo "--- 6. Faculty Access Restrictions ---\n";

    // 6a. Own activity but NOT completed (status = approved)
    $resFacOwnAppr = requestReport($baseUrl . '?activity_id=' . $act2ApprovedId, $sessions['fac1']);
    assertCheck(
        "Faculty cannot access own activity if status is not completed (HTTP 403 Access denied)",
        $resFacOwnAppr['code'] === 403 || str_contains($resFacOwnAppr['body'], 'Access denied'),
        "Expected 403, got: " . $resFacOwnAppr['code']
    );

    $resFacOwnApprCsv = requestReport($baseUrl . '?activity_id=' . $act2ApprovedId . '&export=csv', $sessions['fac1']);
    assertCheck(
        "Faculty cannot export CSV for own activity if status is not completed (HTTP 403 Access denied)",
        $resFacOwnApprCsv['code'] === 403 || str_contains($resFacOwnApprCsv['body'], 'Access denied'),
        "Expected 403, got: " . $resFacOwnApprCsv['code']
    );

    // 6b. Other faculty's completed activity
    $resFacOther = requestReport($baseUrl . '?activity_id=' . $act3OtherFacId, $sessions['fac1']);
    assertCheck(
        "Faculty cannot access another faculty's completed activity report (HTTP 403 Access denied)",
        $resFacOther['code'] === 403 || str_contains($resFacOther['body'], 'Access denied'),
        "Expected 403, got: " . $resFacOther['code']
    );

    $resFacOtherCsv = requestReport($baseUrl . '?activity_id=' . $act3OtherFacId . '&export=csv', $sessions['fac1']);
    assertCheck(
        "Faculty cannot export CSV for another faculty's activity (HTTP 403 Access denied)",
        $resFacOtherCsv['code'] === 403 || str_contains($resFacOtherCsv['body'], 'Access denied'),
        "Expected 403, got: " . $resFacOtherCsv['code']
    );

    // 6c. Aggregate report access
    $resFacAgg = requestReport($baseUrl, $sessions['fac1']);
    assertCheck(
        "Faculty cannot access aggregate reports (HTTP 403 Access denied)",
        $resFacAgg['code'] === 403 || str_contains($resFacAgg['body'], 'Access denied'),
        "Expected 403, got: " . $resFacAgg['code']
    );

    // -------------------------------------------------------------
    // TEST 7: Unauthenticated Users Blocked
    // -------------------------------------------------------------
    echo "--- 7. Unauthenticated User Protection ---\n";
    $resUnauthInd = requestReport($baseUrl . '?activity_id=' . $act1CompletedId, null);
    assertCheck(
        "Unauthenticated user cannot access individual report (Redirect 302 or 401)",
        $resUnauthInd['code'] === 302 || $resUnauthInd['code'] === 401 || str_contains($resUnauthInd['body'], 'login.php')
    );

    $resUnauthCsv = requestReport($baseUrl . '?activity_id=' . $act1CompletedId . '&export=csv', null);
    assertCheck(
        "Unauthenticated user cannot access CSV export (Redirect 302 or 401)",
        $resUnauthCsv['code'] === 302 || $resUnauthCsv['code'] === 401 || str_contains($resUnauthCsv['body'], 'login.php')
    );

    $resUnauthAgg = requestReport($baseUrl, null);
    assertCheck(
        "Unauthenticated user cannot access aggregate report (Redirect 302 or 401)",
        $resUnauthAgg['code'] === 302 || $resUnauthAgg['code'] === 401 || str_contains($resUnauthAgg['body'], 'login.php')
    );

} finally {
    echo "\nCleaning up test fixtures...\n";
    foreach ($sessions as $sid) {
        clearSession($sid);
    }
    $db->prepare("DELETE FROM activities WHERE id IN (?, ?, ?)")->execute([$act1CompletedId, $act2ApprovedId, $act3OtherFacId]);
    echo "Cleanup complete.\n\n";
}

echo "============================================================\n";
echo "  Results: Passed = {$passedTests}, Failed = {$failedTests}\n";
echo "============================================================\n";

if ($failedTests > 0) {
    exit(1);
}
exit(0);
