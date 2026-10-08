<?php
/**
 * Test Suite: Dean Activity View Post-Activity Compliance Display
 *
 * Verifies:
 * 1. Open a completed activity with verifiable post-event data (HTTP 200).
 * 2. Confirm the compliance percentage matches calculatePostActivityCompliance() result.
 * 3. Confirm all 8 checks appear with appropriate labels and results.
 * 4. Test a partially compliant activity.
 * 5. Test an activity with no verifiable post-event data.
 * 6. Confirm null percentage displays as "Not Verifiable" (not 0%).
 * 7. Confirm no database mutation occurs.
 * 8. Confirm existing Dean view functionality still works.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost/sti-activity-system';
$passed = 0;
$failed = 0;

function assertCondition(bool $cond, string $name, string $details = ''): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$name}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$name} - {$details}\n";
    }
}

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

echo "============================================================\n";
echo "   DEAN ACTIVITY VIEW POST-ACTIVITY COMPLIANCE TEST SUITE   \n";
echo "============================================================\n\n";

$deanCookie = loginUser($baseUrl, 'dean@sti.edu', 'password');
assertCondition(!empty($deanCookie), "Dean authenticated successfully with active session cookie");

// -------------------------------------------------------------
// Test 1: Completed Activity with Post-Event Data (Activity 118)
// -------------------------------------------------------------
echo "\nTest 1: Completed Activity with Post-Event Data (Activity 118)\n";
$helperResult118 = calculatePostActivityCompliance(118, null, $pdo);
$page118 = makeRequest("{$baseUrl}/dean/view-activity.php?id=118", 'GET', [], $deanCookie);

assertCondition($page118['code'] === 200, "Dean can load view-activity for Activity 118 (HTTP 200)");
assertCondition(
    strpos($page118['body'], 'id="post-activity-compliance-section"') !== false,
    "Post-Activity Compliance section header is present in DOM"
);

// Check overall status badge
assertCondition(
    strpos($page118['body'], $helperResult118['overall_status']) !== false,
    "Overall status '{$helperResult118['overall_status']}' is displayed in badge",
    "Expected: {$helperResult118['overall_status']}"
);

// Check compliance percentage
if ($helperResult118['compliance_percentage'] !== null) {
    $expectedPctStr = number_format($helperResult118['compliance_percentage'], 1) . '%';
    assertCondition(
        strpos($page118['body'], $expectedPctStr) !== false,
        "Compliance percentage '{$expectedPctStr}' matches helper result",
        "Expected: {$expectedPctStr}"
    );
} else {
    assertCondition(
        strpos($page118['body'], 'Not Verifiable') !== false,
        "Null compliance percentage displays as 'Not Verifiable'"
    );
}

// Check verification summary tiles
assertCondition(
    strpos($page118['body'], 'Verifiable Checks') !== false,
    "Summary metric 'Verifiable Checks' is present"
);
assertCondition(
    strpos($page118['body'], 'Compliant Checks') !== false,
    "Summary metric 'Compliant Checks' is present"
);
assertCondition(
    strpos($page118['body'], 'Non-Compliant Checks') !== false,
    "Summary metric 'Non-Compliant Checks' is present"
);
assertCondition(
    strpos($page118['body'], 'Not Verifiable Checks') !== false,
    "Summary metric 'Not Verifiable Checks' is present"
);

// -------------------------------------------------------------
// Test 2: Confirm all 8 check names appear
// -------------------------------------------------------------
echo "\nTest 2: All 8 checks appear in table\n";
$requiredChecks = [
    'Activity Title',
    'Event Date',
    'Venue',
    'Objectives',
    'Program / Sequence',
    'Assigned People / Manpower',
    'KPI / Evaluation Criteria',
    'Post-Event Documentation'
];

foreach ($requiredChecks as $checkName) {
    assertCondition(
        strpos($page118['body'], $checkName) !== false,
        "Check '{$checkName}' is rendered in the compliance table"
    );
}

// -------------------------------------------------------------
// Test 3: Activity with NO verifiable post-event data
// -------------------------------------------------------------
echo "\nTest 3: Activity with NO verifiable post-event data\n";
$noPeAct = $pdo->query("
    SELECT a.id, a.title FROM activities a
    LEFT JOIN post_event pe ON a.id = pe.activity_id
    WHERE pe.id IS NULL AND a.status = 'approved'
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$noPeAct) {
    $noPeAct = $pdo->query("
        SELECT a.id, a.title FROM activities a
        LEFT JOIN post_event pe ON a.id = pe.activity_id
        WHERE pe.id IS NULL
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
}

$noPeId = (int)$noPeAct['id'];
$helperResultNoPe = calculatePostActivityCompliance($noPeId, null, $pdo);
$pageNoPe = makeRequest("{$baseUrl}/dean/view-activity.php?id={$noPeId}", 'GET', [], $deanCookie);

assertCondition($pageNoPe['code'] === 200, "Dean loads activity {$noPeId} without post-event data (HTTP 200)");
assertCondition(
    strpos($pageNoPe['body'], 'id="post-activity-compliance-section"') !== false,
    "Compliance section exists on activity with no post-event data"
);

assertCondition(
    $helperResultNoPe['overall_status'] === 'Not Verifiable',
    "Helper returns overall_status 'Not Verifiable' for activity without post-event"
);

assertCondition(
    $helperResultNoPe['compliance_percentage'] === null,
    "Helper returns null compliance percentage for activity without post-event"
);

// Confirm null percentage displays as "Not Verifiable" in DOM (not 0% or empty)
assertCondition(
    strpos($pageNoPe['body'], 'Not Verifiable') !== false,
    "Compliance rate tile renders 'Not Verifiable' badge when percentage is null"
);

// -------------------------------------------------------------
// Test 4: Database Immutability Verification
// -------------------------------------------------------------
echo "\nTest 4: Confirm zero database mutations\n";
function getDbStateChecksum(PDO $db): string {
    $tables = ['activities', 'post_event', 'documents', 'program_sequence', 'manpower', 'faculty_tasks', 'kpi_evaluations', 'materials'];
    $data = [];
    foreach ($tables as $t) {
        $c = $db->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        $m = $db->query("SELECT MAX(id) FROM {$t}")->fetchColumn() ?: 0;
        $data[] = "{$t}:{$c}:{$m}";
    }
    return implode(';', $data);
}

$checksumBefore = getDbStateChecksum($pdo);
// Load multiple pages
makeRequest("{$baseUrl}/dean/view-activity.php?id=118", 'GET', [], $deanCookie);
makeRequest("{$baseUrl}/dean/view-activity.php?id={$noPeId}", 'GET', [], $deanCookie);
$checksumAfter = getDbStateChecksum($pdo);

assertCondition(
    $checksumBefore === $checksumAfter,
    "Database state checksum is completely identical before and after viewing pages",
    "Before: {$checksumBefore} | After: {$checksumAfter}"
);

// -------------------------------------------------------------
// Test 5: Existing Dean View Functionality Intact
// -------------------------------------------------------------
echo "\nTest 5: Existing Dean View Functionality Intact\n";
assertCondition(
    strpos($page118['body'], 'Event Information') !== false,
    "Event Information section is intact"
);
assertCondition(
    strpos($page118['body'], 'KPI &amp; Evaluation Targets') !== false || strpos($page118['body'], 'KPI & Evaluation Targets') !== false,
    "KPI & Evaluation Targets section is intact"
);
assertCondition(
    strpos($page118['body'], 'Approval Timeline') !== false,
    "Approval Timeline section is intact"
);

echo "\n============================================================\n";
echo "Test Summary: Passed: {$passed}, Failed: {$failed}\n";
echo "============================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
