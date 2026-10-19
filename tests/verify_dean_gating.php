<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();
$baseUrl = 'http://localhost/sti-activity-system';

echo "=======================================================\n";
echo "  VERIFYING DEAN REVIEW PAGE ENTRY-STAGE VALIDATION\n";
echo "=======================================================\n\n";

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

// 1. Authenticate as Dean
$loginRes = makeReq("{$baseUrl}/auth/login.php", 'POST', [
    'email' => 'dean@sti.edu',
    'password' => 'password'
]);

$sessionCookie = null;
foreach ($loginRes['cookies'] as $c) {
    if (str_starts_with($c, 'PHPSESSID=')) {
        $sessionCookie = $c;
    }
}

if (!$sessionCookie) {
    echo "FAIL: Could not obtain Dean session cookie.\n";
    exit(1);
}
echo "[PASS] Authenticated as Dean (Session: {$sessionCookie})\n\n";

// Helper to create test activity
$facultyId = 1;
$testIds = [];

function createTestActivity(PDO $db, string $status, string $title, int $facultyId): int {
    $stmt = $db->prepare("
        INSERT INTO activities (
            title, faculty_id, status, source, target_participants, 
            event_date, start_time, end_time, venue, general_objectives, 
            specific_objectives, created_at, updated_at
        ) VALUES (
            ?, ?, ?, 'faculty', 100, 
            CURDATE(), '09:00:00', '12:00:00', 'Gymnasium', 'Objectives', 
            'Specific Objectives', NOW(), NOW()
        )
    ");
    $stmt->execute([$title, $facultyId, $status]);
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
    // Test 1: pending_final_approval -> Opens review page (HTTP 200)
    echo "--- Test 1: pending_final_approval stage ---\n";
    $idPending = createTestActivity($db, 'pending_final_approval', 'Test Gating Pending Final', $facultyId);
    $testIds[] = $idPending;
    $resPending = makeReq("{$baseUrl}/dean/review.php?id={$idPending}", 'GET', [], $sessionCookie);
    assertTest("HTTP status is 200", $resPending['code'] === 200, "Code: {$resPending['code']}");
    assertTest("Page contains review controls", strpos($resPending['body'], 'Final Approve') !== false && strpos($resPending['body'], 'Return for Revision') !== false);

    // Test 2: approved -> Redirects to view-activity.php
    echo "\n--- Test 2: approved stage ---\n";
    $idApproved = createTestActivity($db, 'approved', 'Test Gating Approved', $facultyId);
    $testIds[] = $idApproved;
    $resApproved = makeReq("{$baseUrl}/dean/review.php?id={$idApproved}", 'GET', [], $sessionCookie);
    assertTest("HTTP status is 302 Redirect", $resApproved['code'] === 302, "Code: {$resApproved['code']}");
    assertTest("Redirect target is view-activity.php", strpos($resApproved['location'] ?? '', "dean/view-activity.php?id={$idApproved}") !== false, "Location: {$resApproved['location']}");

    // Test 3: rejected -> Redirects to view-activity.php
    echo "\n--- Test 3: rejected stage ---\n";
    $idRejected = createTestActivity($db, 'rejected', 'Test Gating Rejected', $facultyId);
    $testIds[] = $idRejected;
    $resRejected = makeReq("{$baseUrl}/dean/review.php?id={$idRejected}", 'GET', [], $sessionCookie);
    assertTest("HTTP status is 302 Redirect", $resRejected['code'] === 302, "Code: {$resRejected['code']}");
    assertTest("Redirect target is view-activity.php", strpos($resRejected['location'] ?? '', "dean/view-activity.php?id={$idRejected}") !== false, "Location: {$resRejected['location']}");

    // Test 4: returned_for_revision -> Redirects to view-activity.php
    echo "\n--- Test 4: returned_for_revision stage ---\n";
    $idReturned = createTestActivity($db, 'returned_for_revision', 'Test Gating Returned', $facultyId);
    $testIds[] = $idReturned;
    $resReturned = makeReq("{$baseUrl}/dean/review.php?id={$idReturned}", 'GET', [], $sessionCookie);
    assertTest("HTTP status is 302 Redirect", $resReturned['code'] === 302, "Code: {$resReturned['code']}");
    assertTest("Redirect target is view-activity.php", strpos($resReturned['location'] ?? '', "dean/view-activity.php?id={$idReturned}") !== false, "Location: {$resReturned['location']}");

    // Test 5: draft, submitted, under_review, endorsed, resubmitted -> All redirect to view-activity.php
    echo "\n--- Test 5: Other intermediate/early stages ---\n";
    $otherStatuses = ['draft', 'submitted', 'under_review', 'endorsed', 'resubmitted'];
    foreach ($otherStatuses as $st) {
        $idOther = createTestActivity($db, $st, "Test Gating {$st}", $facultyId);
        $testIds[] = $idOther;
        $resOther = makeReq("{$baseUrl}/dean/review.php?id={$idOther}", 'GET', [], $sessionCookie);
        assertTest("Status '{$st}' redirects (302) to view-activity.php", 
            $resOther['code'] === 302 && strpos($resOther['location'] ?? '', "dean/view-activity.php?id={$idOther}") !== false,
            "Code: {$resOther['code']}, Location: {$resOther['location']}"
        );
    }

    // Test 6: Verify approval flow from pending_final_approval
    echo "\n--- Test 6: Approval action execution for pending_final_approval ---\n";
    $idToApprove = createTestActivity($db, 'pending_final_approval', 'Test Gating To Approve', $facultyId);
    $testIds[] = $idToApprove;
    $resApproveAction = makeReq("{$baseUrl}/api/review-action.php", 'POST', [
        'activity_id' => $idToApprove,
        'action' => 'approved',
        'notes' => 'Approved in automated gating test'
    ], $sessionCookie);
    assertTest("Approval action redirects (302)", $resApproveAction['code'] === 302, "Code: {$resApproveAction['code']}");
    
    // Check DB status
    $stmtCheck = $db->prepare("SELECT status FROM activities WHERE id = ?");
    $stmtCheck->execute([$idToApprove]);
    $updatedStatus = $stmtCheck->fetchColumn();
    assertTest("Activity status updated to 'approved'", $updatedStatus === 'approved', "Status: {$updatedStatus}");

    // Now re-requesting dean/review.php for this freshly approved activity MUST redirect!
    $resPostApproved = makeReq("{$baseUrl}/dean/review.php?id={$idToApprove}", 'GET', [], $sessionCookie);
    assertTest("Immediately re-visiting dean/review.php?id={$idToApprove} redirects (302) to view-activity.php",
        $resPostApproved['code'] === 302 && strpos($resPostApproved['location'] ?? '', "dean/view-activity.php?id={$idToApprove}") !== false,
        "Code: {$resPostApproved['code']}, Location: {$resPostApproved['location']}"
    );

} finally {
    // Teardown test records
    if (!empty($testIds)) {
        $inClause = implode(',', array_map('intval', $testIds));
        $db->exec("DELETE FROM approval_logs WHERE activity_id IN ({$inClause})");
        $db->exec("DELETE FROM notifications WHERE activity_id IN ({$inClause})");
        $db->exec("DELETE FROM activities WHERE id IN ({$inClause})");
    }
    echo "\n[CLEANUP] Removed " . count($testIds) . " test activity records.\n";
}

echo "\n=======================================================\n";
echo "OVERALL STATUS: " . ($allPassed ? "ALL TESTS PASSED" : "TESTS FAILED") . "\n";
echo "=======================================================\n";

exit($allPassed ? 0 : 1);
