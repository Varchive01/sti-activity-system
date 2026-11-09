<?php
/**
 * Test Suite: Student Org Head vs Faculty Activity Global Scoping & Isolation
 *
 * Verifies:
 * 1. canUserAccessActivity() strictly blocks admin1 (Student Org Head) from faculty activities
 * 2. admin1/activities.php strictly isolates to student_org source
 * 3. admin1/view-activity.php blocks direct access to faculty activities
 * 4. admin1/review.php blocks review of faculty activities
 * 5. admin2 (Events Committee Head) retains full access to review faculty activities
 * 6. Live HTTP session requests verify isolation between admin1 and admin2
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
echo "  TEST SUITE: STUDENT ORG VS FACULTY ACTIVITY ISOLATION\n";
echo "============================================================\n\n";

$db = getDB();

// 1. Static code assertions
echo "--- 1. Static Scoping & Protection Assertions ---\n";
$facFile = file_get_contents($rootDir . '/includes/functions.php');
assertTest(strpos($facFile, "if (\$userRole === 'admin1')") !== false,
    "canUserAccessActivity contains dedicated rule for admin1",
    "canUserAccessActivity missing dedicated rule for admin1");
assertTest(strpos($facFile, "return (\$actSource === 'student_org' || \$isCollab);") !== false,
    "canUserAccessActivity strictly restricts admin1 view access to student_org source",
    "canUserAccessActivity does not restrict admin1 to student_org source");

$actFile = file_get_contents($rootDir . '/admin1/activities.php');
assertTest(strpos($actFile, "a.source = 'student_org'") !== false,
    "admin1/activities.php queries strictly filter by a.source = 'student_org'",
    "admin1/activities.php lacks a.source = 'student_org' filter");
assertTest(strpos($actFile, "(\$a['source'] ?? '') === 'student_org'") !== false,
    "admin1/activities.php Review button requires student_org source",
    "admin1/activities.php Review button missing student_org check");

$viewFile = file_get_contents($rootDir . '/admin1/view-activity.php');
assertTest(strpos($viewFile, "canUserAccessActivity") !== false,
    "admin1/view-activity.php enforces canUserAccessActivity access check",
    "admin1/view-activity.php missing canUserAccessActivity check");
assertTest(strpos($viewFile, "(\$activity['source'] ?? '') === 'student_org'") !== false,
    "admin1/view-activity.php Review button requires student_org source",
    "admin1/view-activity.php Review button missing student_org check");

$dashFile = file_get_contents($rootDir . '/admin1/dashboard.php');
assertTest(strpos($dashFile, "source='student_org'") !== false,
    "admin1/dashboard.php dynamically discovers years scoped to student_org",
    "admin1/dashboard.php dynamic years query not scoped");
assertTest(strpos($dashFile, "a.source = 'student_org' AND a.status = 'completed'") !== false,
    "admin1/dashboard.php KPI metrics strictly scoped to completed student_org activities",
    "admin1/dashboard.php KPI metrics not scoped to student_org");

$calFile = file_get_contents($rootDir . '/admin1/calendar.php');
assertTest(strpos($calFile, "a.source = 'student_org'") !== false,
    "admin1/calendar.php scopes events to student_org source",
    "admin1/calendar.php events query not scoped to student_org");

// 2. Unit testing canUserAccessActivity() logic
echo "\n--- 2. Direct Logic Verification (canUserAccessActivity) ---\n";
// Find or create test activities
$testFacultyActId = 0;
$testStudentOrgActId = 0;

$stmt = $db->query("SELECT id FROM activities WHERE source = 'faculty' AND status IN ('submitted', 'under_review') LIMIT 1");
$testFacultyActId = (int)$stmt->fetchColumn();

$stmt = $db->query("SELECT id FROM activities WHERE source = 'student_org' AND status IN ('submitted', 'under_review') LIMIT 1");
$testStudentOrgActId = (int)$stmt->fetchColumn();

// If not found, create minimal mock activities
if (!$testFacultyActId) {
    $ins = $db->prepare("INSERT INTO activities (faculty_id, title, event_date, source, status, submitted_at) VALUES (387, 'Unit Test Faculty Proposal', CURDATE() + INTERVAL 5 DAY, 'faculty', 'submitted', NOW())");
    $ins->execute();
    $testFacultyActId = (int)$db->lastInsertId();
}
if (!$testStudentOrgActId) {
    $ins = $db->prepare("INSERT INTO activities (faculty_id, title, event_date, source, status, submitted_at) VALUES (387, 'Unit Test Student Org Proposal', CURDATE() + INTERVAL 5 DAY, 'student_org', 'under_review', NOW())");
    $ins->execute();
    $testStudentOrgActId = (int)$db->lastInsertId();
}

$admin1UserId = 2; // Ar-jay Agabayani (admin1)
$admin2UserId = 3; // Ian Jade Barangan (admin2)

// Test Admin 1 trying to access Faculty activity
$admin1AccessFaculty = canUserAccessActivity($testFacultyActId, 'view', $admin1UserId, 'admin1', 'IT Department');
assertTest($admin1AccessFaculty === false,
    "Admin 1 is DENIED access to faculty-initiated activity (ID: {$testFacultyActId})",
    "Admin 1 was incorrectly GRANTED access to faculty activity");

// Test Admin 1 accessing Student Org activity
$admin1AccessStudentOrg = canUserAccessActivity($testStudentOrgActId, 'view', $admin1UserId, 'admin1', 'IT Department');
assertTest($admin1AccessStudentOrg === true,
    "Admin 1 is GRANTED access to student_org activity (ID: {$testStudentOrgActId})",
    "Admin 1 was incorrectly DENIED access to student_org activity");

// Test Admin 2 accessing Faculty activity (should be allowed because Admin 2 is Events Committee Head)
$admin2AccessFaculty = canUserAccessActivity($testFacultyActId, 'view', $admin2UserId, 'admin2', 'Events Committee Head');
assertTest($admin2AccessFaculty === true,
    "Admin 2 (Events Committee Head) is GRANTED access to faculty activity (ID: {$testFacultyActId})",
    "Admin 2 was incorrectly DENIED access to faculty activity");

// 3. HTTP Simulation testing via cURL
echo "\n--- 3. HTTP Session & Authorization Boundary Testing ---\n";

function makeHttpRequest(string $url, string $method = 'GET', array $data = [], ?string $cookie = null): array {
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
    $res = makeHttpRequest(BASE_URL . '/auth/login.php', 'POST', [
        'email' => $email,
        'password' => $password
    ]);
    if (preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $res['headers'], $matches)) {
        return !empty($matches[1]) ? end($matches[1]) : null;
    }
    return null;
}

// Login as admin1 (Student Org Head)
$admin1Cookie = loginUser('arjay@sti.edu', 'password');
assertTest(!empty($admin1Cookie), "Admin 1 authenticated successfully via HTTP", "Admin 1 login failed");

// Check admin1/activities.php
$actResp = makeHttpRequest(BASE_URL . '/admin1/activities.php', 'GET', [], $admin1Cookie);
assertTest($actResp['status'] === 200, "Admin 1 loads activities.php (HTTP 200)", "Admin 1 activities.php failed (HTTP {$actResp['status']})");

// Query the title of $testFacultyActId
$facTitleStmt = $db->prepare("SELECT title FROM activities WHERE id = ?");
$facTitleStmt->execute([$testFacultyActId]);
$facTitle = $facTitleStmt->fetchColumn() ?: 'Unit Test Faculty Proposal';

assertTest(strpos($actResp['body'], $facTitle) === false,
    "Admin 1 activities.php does NOT contain faculty proposal '{$facTitle}'",
    "Admin 1 activities.php leaked faculty proposal");

// Check admin1/view-activity.php on faculty activity
$viewResp = makeHttpRequest(BASE_URL . '/admin1/view-activity.php?id=' . $testFacultyActId, 'GET', [], $admin1Cookie);
assertTest(strpos($viewResp['body'], 'Activity not found or not accessible') !== false,
    "Admin 1 accessing faculty activity view is blocked with 'Activity not found or not accessible.'",
    "Admin 1 was able to view faculty activity");

// Check admin1/review.php on faculty activity
$revResp = makeHttpRequest(BASE_URL . '/admin1/review.php?id=' . $testFacultyActId, 'GET', [], $admin1Cookie);
assertTest(strpos($revResp['body'], 'Activity not found or not accessible') !== false,
    "Admin 1 accessing faculty activity review is blocked with 'Activity not found or not accessible.'",
    "Admin 1 was able to access faculty activity review page");

// Login as admin2 (Events Committee Head)
$admin2Cookie = loginUser('ian.barangan@marikina.sti.edu.ph', 'password');
assertTest(!empty($admin2Cookie), "Admin 2 authenticated successfully via HTTP", "Admin 2 login failed");

// Check admin2/pending.php shows faculty proposal
$pendingResp = makeHttpRequest(BASE_URL . '/admin2/pending.php', 'GET', [], $admin2Cookie);
assertTest($pendingResp['status'] === 200, "Admin 2 loads pending.php (HTTP 200)", "Admin 2 pending.php failed (HTTP {$pendingResp['status']})");
assertTest(strpos($pendingResp['body'], $facTitle) !== false,
    "Admin 2 pending queue correctly includes faculty proposal '{$facTitle}' for Events Committee review",
    "Admin 2 pending queue missing faculty proposal");

// Check admin2/view-activity.php can view faculty proposal
$viewResp2 = makeHttpRequest(BASE_URL . '/admin2/view-activity.php?id=' . $testFacultyActId, 'GET', [], $admin2Cookie);
assertTest($viewResp2['status'] === 200 && strpos($viewResp2['body'], $facTitle) !== false,
    "Admin 2 (Events Committee Head) successfully views faculty proposal",
    "Admin 2 was unable to view faculty proposal (HTTP {$viewResp2['status']})");

echo "\n--- Test Summary ---\n";
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

echo "\nAll Student Org vs Faculty Activity Isolation tests PASSED successfully!\n";
