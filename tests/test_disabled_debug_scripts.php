<?php
/**
 * Focused Security Test: Disabled Debug/Test API Scripts
 *
 * Requirements:
 * 1. Direct unauthenticated requests to api/test.php, api/test2.php, api/test3.php must be denied (HTTP 403)
 *    and not execute any schema dumps or test insertions.
 * 2. No legitimate production endpoint is broken.
 * 3. Approval workflow (api/review-action.php) remains fully functional.
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
echo "  Disabled Debug/Test API Scripts Security Verification\n";
echo "============================================================\n\n";

function requestHttp(string $url, string $method = 'GET', array $postData = [], ?string $cookie = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    }
    if ($cookie !== null) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($res, 0, $hSize);
    $body = substr($res, $hSize);
    return ['code' => $code, 'headers' => $headers, 'body' => $body];
}

// -------------------------------------------------------------
// 1. Verify Debug Scripts are Disabled and Blocked
// -------------------------------------------------------------
echo "--- 1. Debug/Test Script Denial Verification ---\n";

// Count activities before testing to ensure api/test3.php did NOT insert anything
$initialActCount = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Final Approval Test'")->fetchColumn();

// Test api/test.php
$resTest1 = requestHttp(BASE_URL . '/api/test.php');
assertCheck(
    "api/test.php returns HTTP 403 Forbidden",
    $resTest1['code'] === 403 && str_contains($resTest1['body'], 'Access denied'),
    "Expected 403 Access denied, got: {$resTest1['code']} Body: {$resTest1['body']}"
);
assertCheck(
    "api/test.php does not expose database schema",
    !str_contains($resTest1['body'], 'floor_plans') && !str_contains($resTest1['body'], 'canvas_json')
);

// Test api/test2.php
$resTest2 = requestHttp(BASE_URL . '/api/test2.php');
assertCheck(
    "api/test2.php returns HTTP 403 Forbidden",
    $resTest2['code'] === 403 && str_contains($resTest2['body'], 'Access denied'),
    "Expected 403 Access denied, got: {$resTest2['code']} Body: {$resTest2['body']}"
);
assertCheck(
    "api/test2.php does not expose database schema",
    !str_contains($resTest2['body'], 'kpi_evaluations') && !str_contains($resTest2['body'], 'evaluator_name')
);

// Test api/test3.php
$resTest3 = requestHttp(BASE_URL . '/api/test3.php');
assertCheck(
    "api/test3.php returns HTTP 403 Forbidden",
    $resTest3['code'] === 403 && str_contains($resTest3['body'], 'Access denied'),
    "Expected 403 Access denied, got: {$resTest3['code']} Body: {$resTest3['body']}"
);

$afterActCount = (int)$db->query("SELECT COUNT(*) FROM activities WHERE title = 'Final Approval Test'")->fetchColumn();
assertCheck(
    "api/test3.php did NOT insert test records or execute approval logic",
    $afterActCount === $initialActCount,
    "Activity count changed from {$initialActCount} to {$afterActCount}"
);

// -------------------------------------------------------------
// 2. Verify Legitimate Production Endpoints Remain Intact
// -------------------------------------------------------------
echo "\n--- 2. Legitimate Production Endpoints Verification ---\n";

// Test api/document-download.php
$resDoc = requestHttp(BASE_URL . '/api/document-download.php?id=0');
assertCheck(
    "api/document-download.php functions normally (redirects or returns 400/401/403 for invalid request)",
    in_array($resDoc['code'], [302, 400, 401, 403], true),
    "HTTP Code: {$resDoc['code']}"
);

// Test api/notifications.php
$resNotif = requestHttp(BASE_URL . '/api/notifications.php');
assertCheck(
    "api/notifications.php functions normally (returns JSON auth response)",
    $resNotif['code'] === 200 && str_contains($resNotif['body'], 'Unauthorized'),
    "Body: {$resNotif['body']}"
);

// -------------------------------------------------------------
// 3. Verify Approval Workflow Remains Fully Functional
// -------------------------------------------------------------
echo "\n--- 3. Approval Workflow Functionality Verification ---\n";

// Create test faculty and admin users
$facultyUser = $db->query("SELECT id FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin1User = $db->query("SELECT id, name, role FROM users WHERE role = 'admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin2User = $db->query("SELECT id, name, role FROM users WHERE role = 'admin2' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$deanUser = $db->query("SELECT id, name, role FROM users WHERE role = 'dean' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$facId = (int)$facultyUser['id'];
$admin1Id = (int)$admin1User['id'];
$admin2Id = (int)$admin2User['id'];
$deanId = (int)$deanUser['id'];

// Create a student_org activity under_review
$stmt = $db->prepare("
    INSERT INTO activities (title, faculty_id, status, source, target_participants, event_date)
    VALUES ('Workflow Test Activity', ?, 'under_review', 'student_org', 100, CURDATE())
");
$stmt->execute([$facId]);
$wfActId = (int)$db->lastInsertId();

// Helper to write session files for testing
function makeSession(string $sid, array $u): void {
    $data = [
        'user_id' => (int)$u['id'],
        'user_role' => $u['role'],
        'role' => $u['role'],
        'user_name' => $u['name'],
        'name' => $u['name'],
        'must_change_password' => 0,
        'last_activity' => time()
    ];
    $s = '';
    foreach ($data as $k => $v) $s .= $k . '|' . serialize($v);
    file_put_contents('C:/xampp/tmp/sess_' . $sid, $s);
}

function delSession(string $sid): void {
    $p = 'C:/xampp/tmp/sess_' . $sid;
    if (file_exists($p)) @unlink($p);
}

$sAdmin1 = bin2hex(random_bytes(16));
$sAdmin2 = bin2hex(random_bytes(16));
$sDean = bin2hex(random_bytes(16));

makeSession($sAdmin1, $admin1User);
makeSession($sAdmin2, $admin2User);
makeSession($sDean, $deanUser);

try {
    // Step 1: Admin1 approves (endorses) student_org proposal
    $resWf1 = requestHttp(
        BASE_URL . '/api/review-action.php',
        'POST',
        ['activity_id' => $wfActId, 'action' => 'approved', 'notes' => 'Admin1 endorsed.'],
        'PHPSESSID=' . $sAdmin1
    );
    $statusAfter1 = $db->query("SELECT status FROM activities WHERE id = {$wfActId}")->fetchColumn();
    assertCheck(
        "Admin1 review action successfully transitions status to 'endorsed'",
        $statusAfter1 === 'endorsed',
        "Expected 'endorsed', got: '{$statusAfter1}'"
    );

    // Step 2: Admin2 reviews endorsed student_org proposal -> transitions to pending_final_approval
    $resWf2 = requestHttp(
        BASE_URL . '/api/review-action.php',
        'POST',
        ['activity_id' => $wfActId, 'action' => 'approved', 'notes' => 'Admin2 verified.'],
        'PHPSESSID=' . $sAdmin2
    );
    $statusAfter2 = $db->query("SELECT status FROM activities WHERE id = {$wfActId}")->fetchColumn();
    assertCheck(
        "Admin2 review action successfully transitions status to 'pending_final_approval'",
        $statusAfter2 === 'pending_final_approval',
        "Expected 'pending_final_approval', got: '{$statusAfter2}'"
    );

    // Step 3: Dean grants final approval -> transitions to 'approved'
    $resWf3 = requestHttp(
        BASE_URL . '/api/review-action.php',
        'POST',
        ['activity_id' => $wfActId, 'action' => 'approved', 'notes' => 'Dean approved.'],
        'PHPSESSID=' . $sDean
    );
    $statusAfter3 = $db->query("SELECT status FROM activities WHERE id = {$wfActId}")->fetchColumn();
    assertCheck(
        "Dean review action successfully transitions status to 'approved'",
        $statusAfter3 === 'approved',
        "Expected 'approved', got: '{$statusAfter3}'"
    );

    // Check approval logs recorded
    $logCount = (int)$db->query("SELECT COUNT(*) FROM approval_logs WHERE activity_id = {$wfActId}")->fetchColumn();
    assertCheck(
        "All 3 review stages logged in approval_logs",
        $logCount === 3,
        "Expected 3 logs, found: {$logCount}"
    );

} finally {
    delSession($sAdmin1);
    delSession($sAdmin2);
    delSession($sDean);
    $db->prepare("DELETE FROM approval_logs WHERE activity_id = ?")->execute([$wfActId]);
    $db->prepare("DELETE FROM notifications WHERE activity_id = ?")->execute([$wfActId]);
    $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$wfActId]);
    echo "\nTest cleanup complete.\n\n";
}

echo "============================================================\n";
echo "  Results: Passed = {$passedTests}, Failed = {$failedTests}\n";
echo "============================================================\n";

if ($failedTests > 0) {
    exit(1);
}
exit(0);
