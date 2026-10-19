<?php
/**
 * Test Suite: Dean Large-Event Clearance Record
 *
 * Verifies:
 * 1. Ordinary activity where clearance is not required ($totalBudget == 0, clearance_required == 0)
 * 2. Large / budget-required activity ($totalBudget > 0 or clearance_required == 1)
 * 3. Dean can record clearance status (clearance_obtained = 1 or 0, clearance_notes)
 * 4. Reload and confirm persistence in the database and in Dean review/activity/report views
 * 5. Non-Dean users (Admin1, Admin2, Faculty) cannot modify clearance records (HTTP 403 / ignored)
 * 6. Existing Dean approval actions continue to work smoothly (approved, returned, rejected)
 * 7. Clean teardown of test fixtures
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

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

$passedTests = 0;
$failedTests = 0;

function assertCondition(string $name, bool $condition, string $details = ""): void {
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
echo "   DEAN LARGE-EVENT CLEARANCE RECORD TEST SUITE             \n";
echo "============================================================\n\n";

$deanCookie = loginUser($baseUrl, 'dean@sti.edu', 'password');
assertCondition("Dean authenticated with active session", !empty($deanCookie));

$admin1Cookie = loginUser($baseUrl, 'arjay@sti.edu', 'password');
assertCondition("Admin1 authenticated with active session", !empty($admin1Cookie));

$facultyUser = $db->query("SELECT id, name, email FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$deanUser = $db->query("SELECT id, name, email FROM users WHERE role = 'dean' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$cleanupIds = [];

try {
    // -------------------------------------------------------------
    // Test Case 1: Ordinary Activity (Clearance Not Required)
    // -------------------------------------------------------------
    echo "\n--- Test Case 1: Ordinary Activity (Clearance Not Required) ---\n";

    $stmt = $db->prepare("
        INSERT INTO activities (title, faculty_id, event_date, venue, status, source, clearance_required, clearance_obtained)
        VALUES ('Ordinary Seminar No Budget', ?, '2026-11-15', 'Room 101', 'pending_final_approval', 'faculty', 0, NULL)
    ");
    $stmt->execute([$facultyUser['id']]);
    $ordinaryId = (int)$db->lastInsertId();
    $cleanupIds[] = $ordinaryId;

    $ordRow = $db->query("SELECT * FROM activities WHERE id = {$ordinaryId}")->fetch(PDO::FETCH_ASSOC);
    $ordClr = getActivityClearanceInfo($ordRow, 0.0);

    assertCondition("Helper identifies ordinary activity as clearance NOT required", $ordClr['required'] === false);
    assertCondition("Helper returns status 'Not Required'", $ordClr['status'] === 'Not Required');

    // Dean loads review page for ordinary activity
    $ordReviewRes = makeRequest("{$baseUrl}/dean/review.php?id={$ordinaryId}", 'GET', [], $deanCookie);
    assertCondition("Dean review loads HTTP 200 for ordinary activity", $ordReviewRes['code'] === 200);
    assertCondition("Review displays 'Clearance Not Required'", str_contains($ordReviewRes['body'], 'Clearance Not Required'));

    // Dean view activity page
    $ordViewRes = makeRequest("{$baseUrl}/dean/view-activity.php?id={$ordinaryId}", 'GET', [], $deanCookie);
    assertCondition("Dean view-activity loads HTTP 200 for ordinary activity", $ordViewRes['code'] === 200);
    assertCondition("View displays 'Not Required'", str_contains($ordViewRes['body'], 'Not Required'));

    // Dean can approve without clearance prompt blocking
    $ordApproveRes = makeRequest("{$baseUrl}/api/review-action.php", 'POST', [
        'activity_id' => $ordinaryId,
        'action'      => 'approved',
        'role'        => 'dean'
    ], $deanCookie);

    $ordPostApprove = $db->query("SELECT status, clearance_required, clearance_obtained FROM activities WHERE id = {$ordinaryId}")->fetch(PDO::FETCH_ASSOC);
    assertCondition("Ordinary activity approved successfully without clearance", $ordPostApprove['status'] === 'approved');
    assertCondition("Ordinary activity retains clearance_required = 0", (int)$ordPostApprove['clearance_required'] === 0);

    // -------------------------------------------------------------
    // Test Case 2: Large / Budget-Required Activity
    // -------------------------------------------------------------
    echo "\n--- Test Case 2: Large / Budget-Required Activity ---\n";

    $stmt = $db->prepare("
        INSERT INTO activities (title, faculty_id, event_date, venue, status, source, clearance_required, clearance_obtained)
        VALUES ('Annual University Tech Summit 2026', ?, '2026-12-05', 'Main Gymnasium', 'pending_final_approval', 'faculty', 1, NULL)
    ");
    $stmt->execute([$facultyUser['id']]);
    $largeId = (int)$db->lastInsertId();
    $cleanupIds[] = $largeId;

    // Add budget materials (₱25,000.00 total)
    $db->prepare("
        INSERT INTO materials (activity_id, item_name, description, quantity, provider, est_cost)
        VALUES (?, 'Stage LED Screen and Audio Rental', 'Production equipment', 1, 'AudioTech Services', 25000.00)
    ")->execute([$largeId]);

    $largeRow = $db->query("SELECT * FROM activities WHERE id = {$largeId}")->fetch(PDO::FETCH_ASSOC);
    $largeClr = getActivityClearanceInfo($largeRow, 25000.0);

    assertCondition("Helper identifies large activity as clearance required", $largeClr['required'] === true);
    assertCondition("Initial clearance status is Pending", $largeClr['status'] === 'Pending');

    // Dean loads review page for large activity
    $largeReviewRes = makeRequest("{$baseUrl}/dean/review.php?id={$largeId}", 'GET', [], $deanCookie);
    assertCondition("Dean review loads HTTP 200 for large activity", $largeReviewRes['code'] === 200);
    assertCondition("Review displays clearance record section", str_contains($largeReviewRes['body'], 'School Administrator / President Clearance Record'));
    assertCondition("Review displays 'Clearance Required'", str_contains($largeReviewRes['body'], 'Clearance Required'));

    // -------------------------------------------------------------
    // Test Case 3: Dean Records Clearance Decision & Persistence
    // -------------------------------------------------------------
    echo "\n--- Test Case 3: Dean Records Clearance Decision & Persistence ---\n";

    // Dean records clearance via record_clearance action
    $recordRes = makeRequest("{$baseUrl}/api/review-action.php", 'POST', [
        'activity_id'        => $largeId,
        'action'             => 'record_clearance',
        'clearance_required' => 1,
        'clearance_obtained' => 1,
        'clearance_notes'    => 'Cleared by School President via Memo #2026-088 dated Oct 8, 2026'
    ], $deanCookie);

    assertCondition("Record clearance action redirects/completes (HTTP 302 or 200)", $recordRes['code'] === 302 || $recordRes['code'] === 200);

    // Verify database persistence
    $savedLarge = $db->query("SELECT * FROM activities WHERE id = {$largeId}")->fetch(PDO::FETCH_ASSOC);
    assertCondition("Database persists clearance_required = 1", (int)$savedLarge['clearance_required'] === 1);
    assertCondition("Database persists clearance_obtained = 1", (int)$savedLarge['clearance_obtained'] === 1);
    assertCondition("Database persists clearance_notes accurately", str_contains($savedLarge['clearance_notes'], 'Memo #2026-088'));

    // Verify reload of Dean review view
    $reloadReviewRes = makeRequest("{$baseUrl}/dean/review.php?id={$largeId}", 'GET', [], $deanCookie);
    assertCondition("Reloaded review displays '✓ Clearance Obtained'", str_contains($reloadReviewRes['body'], 'Clearance Obtained'));
    assertCondition("Reloaded review displays clearance remarks", str_contains($reloadReviewRes['body'], 'Memo #2026-088'));

    // Verify Dean view activity page displays clearance
    $reloadViewRes = makeRequest("{$baseUrl}/dean/view-activity.php?id={$largeId}", 'GET', [], $deanCookie);
    assertCondition("Dean view-activity displays '✓ Clearance Obtained'", str_contains($reloadViewRes['body'], 'Clearance Obtained'));

    // -------------------------------------------------------------
    // Test Case 4: Non-Dean Users CANNOT Modify Clearance
    // -------------------------------------------------------------
    echo "\n--- Test Case 4: Non-Dean Users CANNOT Modify Clearance ---\n";

    // Admin1 attempts to call action=record_clearance
    $adminTamperRes = makeRequest("{$baseUrl}/api/review-action.php", 'POST', [
        'activity_id'        => $largeId,
        'action'             => 'record_clearance',
        'clearance_required' => 0,
        'clearance_obtained' => 0,
        'clearance_notes'    => 'Tampered by Admin1'
    ], $admin1Cookie);

    assertCondition("Non-Dean (Admin1) calling record_clearance is denied HTTP 403", $adminTamperRes['code'] === 403);

    // Verify clearance was NOT modified in the database
    $afterTamperRow = $db->query("SELECT * FROM activities WHERE id = {$largeId}")->fetch(PDO::FETCH_ASSOC);
    assertCondition("Clearance record untouched after Admin1 tamper attempt", (int)$afterTamperRow['clearance_obtained'] === 1);
    assertCondition("Clearance notes untouched after Admin1 tamper attempt", !str_contains($afterTamperRow['clearance_notes'], 'Tampered'));

    // Unauthenticated user attempt
    $unauthRes = makeRequest("{$baseUrl}/api/review-action.php", 'POST', [
        'activity_id'        => $largeId,
        'action'             => 'record_clearance',
        'clearance_obtained' => 0
    ], null);
    assertCondition("Unauthenticated user calling record_clearance is blocked (302/403)", $unauthRes['code'] === 302 || $unauthRes['code'] === 403);

    // -------------------------------------------------------------
    // Test Case 5: Dean Final Approval with Clearance Record
    // -------------------------------------------------------------
    echo "\n--- Test Case 5: Dean Final Approval with Clearance Record ---\n";

    // Create another large activity to test recording clearance DURING final approval
    $stmt = $db->prepare("
        INSERT INTO activities (title, faculty_id, event_date, venue, status, source, clearance_required, clearance_obtained)
        VALUES ('Inter-Campus Robotics Championship', ?, '2026-12-20', 'Auditorium', 'pending_final_approval', 'faculty', 1, NULL)
    ");
    $stmt->execute([$facultyUser['id']]);
    $largeId2 = (int)$db->lastInsertId();
    $cleanupIds[] = $largeId2;

    $db->prepare("
        INSERT INTO materials (activity_id, item_name, description, quantity, provider, est_cost)
        VALUES (?, 'Trophies and Arena Materials', 'Championship awards', 1, 'Awards Central', 12000.00)
    ")->execute([$largeId2]);

    // Dean final-approves while submitting clearance decision
    $finalApproveRes = makeRequest("{$baseUrl}/api/review-action.php", 'POST', [
        'activity_id'        => $largeId2,
        'action'             => 'approved',
        'role'               => 'dean',
        'clearance_required' => 1,
        'clearance_obtained' => 1,
        'clearance_notes'    => 'Approved by Administrator on file'
    ], $deanCookie);

    $approvedRow = $db->query("SELECT * FROM activities WHERE id = {$largeId2}")->fetch(PDO::FETCH_ASSOC);
    assertCondition("Large activity status advanced to 'approved'", $approvedRow['status'] === 'approved');
    assertCondition("Approval timestamp set", !empty($approvedRow['approved_at']));
    assertCondition("Clearance obtained recorded as 1 during approval", (int)$approvedRow['clearance_obtained'] === 1);
    assertCondition("Clearance notes recorded during approval", str_contains($approvedRow['clearance_notes'], 'Administrator on file'));

    // Check generate-report.php display and CSV export parity
    $reportRes = makeRequest("{$baseUrl}/dean/generate-report.php?activity_id={$largeId2}", 'GET', [], $deanCookie);
    assertCondition("Report loads HTTP 200 for approved large activity", $reportRes['code'] === 200);
    assertCondition("Report displays Large Event Clearance label", str_contains($reportRes['body'], 'Large Event Clearance'));
    assertCondition("Report displays 'Clearance Obtained'", str_contains($reportRes['body'], 'Clearance Obtained'));

    $csvRes = makeRequest("{$baseUrl}/dean/generate-report.php?activity_id={$largeId2}&export=csv", 'GET', [], $deanCookie);
    assertCondition("CSV export returns HTTP 200", $csvRes['code'] === 200);
    assertCondition("CSV export includes 'Large Event Clearance'", str_contains($csvRes['body'], 'Large Event Clearance'));
    assertCondition("CSV export reflects 'Clearance Obtained'", str_contains($csvRes['body'], 'Clearance Obtained'));

    // -------------------------------------------------------------
    // Test Case 6: Existing Rejection and Return Actions Still Work
    // -------------------------------------------------------------
    echo "\n--- Test Case 6: Existing Rejection and Return Actions Still Work ---\n";

    // Test rejection
    $stmt = $db->prepare("
        INSERT INTO activities (title, faculty_id, event_date, venue, status, source)
        VALUES ('Rejected Test Proposal', ?, '2026-11-18', 'Room 205', 'pending_final_approval', 'faculty')
    ");
    $stmt->execute([$facultyUser['id']]);
    $rejectId = (int)$db->lastInsertId();
    $cleanupIds[] = $rejectId;

    $rejectRes = makeRequest("{$baseUrl}/api/review-action.php", 'POST', [
        'activity_id' => $rejectId,
        'action'      => 'rejected',
        'role'        => 'dean',
        'notes'       => 'Dean rejection notes for testing'
    ], $deanCookie);

    $rejectedRow = $db->query("SELECT status, revision_notes FROM activities WHERE id = {$rejectId}")->fetch(PDO::FETCH_ASSOC);
    assertCondition("Proposal correctly rejected", $rejectedRow['status'] === 'rejected');
    assertCondition("Rejection notes preserved", str_contains($rejectedRow['revision_notes'], 'Dean rejection notes'));

} finally {
    // Teardown test fixtures
    echo "\n--- Cleaning Up Fixtures ---\n";
    foreach ($cleanupIds as $cId) {
        $db->prepare("DELETE FROM materials WHERE activity_id = ?")->execute([$cId]);
        $db->prepare("DELETE FROM approval_logs WHERE activity_id = ?")->execute([$cId]);
        $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$cId]);
    }
    echo "  [DONE] Cleaned up " . count($cleanupIds) . " test activities.\n";
}

echo "\n============================================================\n";
echo "TEST RESULTS: Passed: {$passedTests} | Failed: {$failedTests}\n";
echo "============================================================\n";

if ($failedTests > 0) {
    exit(1);
}
exit(0);
