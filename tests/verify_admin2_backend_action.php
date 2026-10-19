<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();
$baseUrl = 'http://localhost/sti-activity-system';

echo "===============================================================\n";
echo "  VERIFYING ADMIN 2 BACKEND REVIEW AUTHORIZATION IN REAL ACTIONS\n";
echo "===============================================================\n\n";

function makeReq(string $url, string $method = 'GET', array $data = [], ?string $cookie = null): array {
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10
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

    $location = null;
    if (preg_match('/^Location:\s*([^\r\n]+)/mi', $headerStr, $locMatch)) {
        $location = trim($locMatch[1]);
    }

    return [
        'code' => $httpCode,
        'headers' => $headerStr,
        'body' => $body,
        'cookies' => $cookies,
        'location' => $location
    ];
}

// 1. Authenticate as Admin 2 (ian@sti.edu)
$loginRes = makeReq("{$baseUrl}/auth/login.php", 'POST', [
    'email' => 'ian@sti.edu',
    'password' => 'password'
]);

$sessionCookie = null;
foreach ($loginRes['cookies'] as $c) {
    if (str_starts_with($c, 'PHPSESSID=')) {
        $sessionCookie = $c;
    }
}

if (!$sessionCookie) {
    echo "FAIL: Could not obtain Admin 2 session cookie.\n";
    exit(1);
}
echo "[PASS] Authenticated as Admin 2 (Session: {$sessionCookie})\n\n";

// Fetch Admin 2 user ID
$admin2User = $db->query("SELECT id FROM users WHERE email = 'ian@sti.edu'")->fetch(PDO::FETCH_ASSOC);
$admin2Id = (int)$admin2User['id'];

// Helper to create test activity
$facultyId = 1;
$testIds = [];

function createTestActivity(PDO $db, string $source, string $status, string $title, int $facultyId): int {
    $stmt = $db->prepare("
        INSERT INTO activities (
            title, faculty_id, status, source, target_participants, 
            event_date, start_time, end_time, venue, general_objectives, 
            specific_objectives, created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, 100, 
            CURDATE(), '09:00:00', '12:00:00', 'Auditorium', 'Objectives', 
            'Specific Objectives', NOW(), NOW()
        )
    ");
    $stmt->execute([$title, $facultyId, $status, $source]);
    return (int)$db->lastInsertId();
}

$allPassed = true;
function assertTest(string $desc, bool $condition, string $extra = '') {
    global $allPassed;
    if ($condition) {
        echo "  [PASS] {$desc}" . ($extra ? " ({$extra})" : "") . "\n";
    } else {
        echo "  [FAIL] {$desc}" . ($extra ? " ({$extra})" : "") . "\n";
        $allPassed = false;
    }
}

try {
    // -------------------------------------------------------------
    // Verification 1: Admin 2 processes faculty + submitted -> approved (forwarded to Dean)
    // -------------------------------------------------------------
    echo "--- Verification 1: Faculty + submitted ---\n";
    $idFacSubmitted = createTestActivity($db, 'faculty', 'submitted', 'Test Gating Fac Submitted', $facultyId);
    $testIds[] = $idFacSubmitted;

    $resFacSubmitted = makeReq("{$baseUrl}/api/review-action.php", 'POST', [
        'activity_id' => $idFacSubmitted,
        'action' => 'approved',
        'notes' => 'Forwarding submitted faculty proposal'
    ], $sessionCookie);

    assertTest("Review action response code is 302 (Redirect on success)", $resFacSubmitted['code'] === 302, "Code: {$resFacSubmitted['code']}");
    $status1 = $db->query("SELECT status FROM activities WHERE id = {$idFacSubmitted}")->fetchColumn();
    assertTest("Status transitioned to 'pending_final_approval'", $status1 === 'pending_final_approval', "Status: {$status1}");

    // -------------------------------------------------------------
    // Verification 2: Admin 2 processes faculty + resubmitted -> approved (forwarded to Dean)
    // -------------------------------------------------------------
    echo "\n--- Verification 2: Faculty + resubmitted ---\n";
    $idFacResubmitted = createTestActivity($db, 'faculty', 'resubmitted', 'Test Gating Fac Resubmitted', $facultyId);
    $testIds[] = $idFacResubmitted;

    $resFacResubmitted = makeReq("{$baseUrl}/api/review-action.php", 'POST', [
        'activity_id' => $idFacResubmitted,
        'action' => 'approved',
        'notes' => 'Forwarding resubmitted faculty proposal to Dean'
    ], $sessionCookie);

    assertTest("Review action response code is 302 (Allowed)", $resFacResubmitted['code'] === 302, "Code: {$resFacResubmitted['code']}");
    $status2 = $db->query("SELECT status FROM activities WHERE id = {$idFacResubmitted}")->fetchColumn();
    assertTest("Status transitioned from 'resubmitted' to 'pending_final_approval'", $status2 === 'pending_final_approval', "Status: {$status2}");

    // Also verify logs
    $log2 = $db->query("SELECT action, notes FROM approval_logs WHERE activity_id = {$idFacResubmitted} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assertTest("Approval log records 'forwarded'", ($log2['action'] ?? '') === 'forwarded', "Action: " . ($log2['action'] ?? 'none'));

    // -------------------------------------------------------------
    // Verification 3: Admin 2 processes student_org + endorsed -> approved (forwarded to Dean)
    // -------------------------------------------------------------
    echo "\n--- Verification 3: Student Org + endorsed ---\n";
    $idOrgEndorsed = createTestActivity($db, 'student_org', 'endorsed', 'Test Gating Org Endorsed', $facultyId);
    $testIds[] = $idOrgEndorsed;

    $resOrgEndorsed = makeReq("{$baseUrl}/api/review-action.php", 'POST', [
        'activity_id' => $idOrgEndorsed,
        'action' => 'approved',
        'notes' => 'Forwarding endorsed student_org proposal'
    ], $sessionCookie);

    assertTest("Review action response code is 302 (Allowed)", $resOrgEndorsed['code'] === 302, "Code: {$resOrgEndorsed['code']}");
    $status3 = $db->query("SELECT status FROM activities WHERE id = {$idOrgEndorsed}")->fetchColumn();
    assertTest("Status transitioned to 'pending_final_approval'", $status3 === 'pending_final_approval', "Status: {$status3}");

    // -------------------------------------------------------------
    // Verification 4: Admin 2 BLOCKED from reviewing student_org + submitted (HTTP 403)
    // -------------------------------------------------------------
    echo "\n--- Verification 4: Student Org + submitted (MUST BE REJECTED) ---\n";
    $idOrgSubmitted = createTestActivity($db, 'student_org', 'submitted', 'Test Gating Org Submitted', $facultyId);
    $testIds[] = $idOrgSubmitted;

    $resOrgSubmitted = makeReq("{$baseUrl}/api/review-action.php", 'POST', [
        'activity_id' => $idOrgSubmitted,
        'action' => 'approved',
        'notes' => 'Tamper attempt on student_org submitted'
    ], $sessionCookie);

    assertTest("Review action blocked with HTTP 403", $resOrgSubmitted['code'] === 403, "Code: {$resOrgSubmitted['code']}");
    assertTest("Error body confirms invalid stage", strpos($resOrgSubmitted['body'], 'Proposal is not in a valid stage') !== false);
    $status4 = $db->query("SELECT status FROM activities WHERE id = {$idOrgSubmitted}")->fetchColumn();
    assertTest("Activity status unchanged ('submitted')", $status4 === 'submitted', "Status: {$status4}");

    // -------------------------------------------------------------
    // Verification 5: Admin 2 BLOCKED on other invalid stages (HTTP 403)
    // -------------------------------------------------------------
    echo "\n--- Verification 5: Other invalid stages (MUST BE REJECTED) ---\n";
    $invalidCombinations = [
        ['source' => 'student_org', 'status' => 'resubmitted', 'name' => 'student_org + resubmitted'],
        ['source' => 'faculty',     'status' => 'under_review', 'name' => 'faculty + under_review'],
        ['source' => 'faculty',     'status' => 'pending_final_approval', 'name' => 'faculty + pending_final_approval'],
        ['source' => 'faculty',     'status' => 'draft',        'name' => 'faculty + draft'],
        ['source' => 'faculty',     'status' => 'approved',     'name' => 'faculty + approved'],
    ];

    foreach ($invalidCombinations as $inv) {
        $idInv = createTestActivity($db, $inv['source'], $inv['status'], "Test Gating {$inv['name']}", $facultyId);
        $testIds[] = $idInv;

        $resInv = makeReq("{$baseUrl}/api/review-action.php", 'POST', [
            'activity_id' => $idInv,
            'action' => 'approved',
            'notes' => 'Tamper attempt'
        ], $sessionCookie);

        assertTest("Stage '{$inv['name']}' blocked with HTTP 403", 
            $resInv['code'] === 403 && strpos($resInv['body'], 'Proposal is not in a valid stage') !== false,
            "Code: {$resInv['code']}"
        );
        $stDb = $db->query("SELECT status FROM activities WHERE id = {$idInv}")->fetchColumn();
        assertTest("Status unchanged for '{$inv['name']}'", $stDb === $inv['status'], "Status: {$stDb}");
    }

    // -------------------------------------------------------------
    // Verification 6: Admin 2 returning a resubmitted faculty proposal
    // -------------------------------------------------------------
    echo "\n--- Verification 6: Admin 2 returning a faculty resubmitted proposal ---\n";
    $idFacReturn = createTestActivity($db, 'faculty', 'resubmitted', 'Test Return Resubmitted', $facultyId);
    $testIds[] = $idFacReturn;

    // Stage section comment so return is permitted
    $db->prepare("INSERT INTO section_comments (activity_id, reviewer_id, section_key, comment, created_at) VALUES (?, ?, 'event_details', 'Please clarify schedule', NOW())")
       ->execute([$idFacReturn, $admin2Id]);

    $resFacReturn = makeReq("{$baseUrl}/api/review-action.php", 'POST', [
        'activity_id' => $idFacReturn,
        'action' => 'returned',
        'notes' => 'Returning resubmitted proposal for revision'
    ], $sessionCookie);

    assertTest("Return action succeeds (302 Redirect)", $resFacReturn['code'] === 302, "Code: {$resFacReturn['code']}");
    $status6 = $db->query("SELECT status FROM activities WHERE id = {$idFacReturn}")->fetchColumn();
    assertTest("Status transitioned to 'returned_for_revision'", $status6 === 'returned_for_revision', "Status: {$status6}");

    // Staged comments bundled and cleared
    $stagedRemaining = (int)$db->query("SELECT COUNT(*) FROM section_comments WHERE activity_id = {$idFacReturn}")->fetchColumn();
    assertTest("Staged comments cleaned up after bundle", $stagedRemaining === 0, "Remaining: {$stagedRemaining}");

    // Notification created for faculty
    $notifCount = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE activity_id = {$idFacReturn} AND user_id = {$facultyId}")->fetchColumn();
    assertTest("Faculty received notification", $notifCount > 0, "Count: {$notifCount}");

} finally {
    // Teardown
    if (!empty($testIds)) {
        $inClause = implode(',', array_map('intval', $testIds));
        $db->exec("DELETE FROM section_comments WHERE activity_id IN ({$inClause})");
        $db->exec("DELETE FROM approval_logs WHERE activity_id IN ({$inClause})");
        $db->exec("DELETE FROM notifications WHERE activity_id IN ({$inClause})");
        $db->exec("DELETE FROM activities WHERE id IN ({$inClause})");
    }
    echo "\n[CLEANUP] Removed " . count($testIds) . " test activity records and associated logs/notifications.\n";
}

echo "\n===============================================================\n";
echo "OVERALL STATUS: " . ($allPassed ? "ALL VERIFICATIONS PASSED" : "VERIFICATIONS FAILED") . "\n";
echo "===============================================================\n";

exit($allPassed ? 0 : 1);
