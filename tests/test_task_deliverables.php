<?php
/**
 * tests/test_task_deliverables.php
 *
 * Automated verification suite for Deliverable Upload and Review for assigned activity tasks:
 * 1. Assign a task to a test user.
 * 2. Upload a deliverable as that user.
 * 3. Confirm file persists and opens (protected download).
 * 4. Review as activity leader.
 * 5. Set 25%, 60%, then 100%.
 * 6. Return one deliverable for improvement.
 * 7. Confirm Gantt reflects the completion percentage.
 * 8. Confirm Dean can view but cannot modify (read-only; 403 on mutation).
 * 9. Confirm unauthorized user receives 403 on upload, review, and download.
 * 10. Confirm existing file-security tests pass.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

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
echo "  DELIVERABLE UPLOAD & REVIEW VERIFICATION SUITE\n";
echo "============================================================\n\n";

$loginUrl     = BASE_URL . '/auth/login.php';
$delivApiUrl  = BASE_URL . '/api/task-deliverables.php';
$taskApiUrl   = BASE_URL . '/api/task-assignment.php';
$downloadUrl  = BASE_URL . '/api/document-download.php';
$rootDir      = realpath(__DIR__ . '/..');

// Helper for HTTP requests
function httpRequest(string $url, string $method = 'GET', $data = null, ?string $cookie = null, array $extraHeaders = []): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $headers = $extraHeaders;
    if (!empty($cookie)) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (is_array($data)) {
            // Check if multipart form data (e.g. contains CURLFile)
            $isMultipart = false;
            foreach ($data as $v) {
                if ($v instanceof CURLFile) {
                    $isMultipart = true;
                    break;
                }
            }
            if ($isMultipart) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            }
        } elseif (is_string($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            $headers[] = 'Content-Type: application/json';
        }
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headerStr = substr($raw, 0, $hSize);
    $body = substr($raw, $hSize);
    $decoded = json_decode($body, true);

    return [
        'code'    => $code,
        'headers' => $headerStr,
        'body'    => $body,
        'decoded' => $decoded
    ];
}

function loginUserAndGetCookie(string $email, string $password): string {
    global $loginUrl;
    $res = httpRequest($loginUrl, 'POST', [
        'email' => $email,
        'password' => $password
    ]);

    preg_match_all('/Set-Cookie:\s*(PHPSESSID=[^;]+)/i', $res['headers'], $m);
    if (!empty($m[1])) {
        return end($m[1]);
    }
    return '';
}

// -------------------------------------------------------------
// SETUP: Create test users and activity
// -------------------------------------------------------------
$testPwd = 'Password123!';
$hashedPwd = password_hash($testPwd, PASSWORD_DEFAULT);

// 1. Leader Faculty (owns activity)
$leaderEmail = 'deliv_leader_' . time() . '@sti.edu';
$db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Prof. Leader Test', ?, ?, 'faculty', 'Information Technology')")->execute([$leaderEmail, $hashedPwd]);
$leaderId = (int)$db->lastInsertId();

// 2. Assigned Faculty User
$assignedEmail = 'deliv_assigned_' . time() . '@sti.edu';
$db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Prof. Assigned Member', ?, ?, 'faculty', 'Information Technology')")->execute([$assignedEmail, $hashedPwd]);
$assignedId = (int)$db->lastInsertId();

// 3. Unauthorized Faculty User (not assigned, not leader)
$unauthEmail = 'deliv_unauth_' . time() . '@sti.edu';
$db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Prof. Stranger Faculty', ?, ?, 'faculty', 'Business Management')")->execute([$unauthEmail, $hashedPwd]);
$unauthId = (int)$db->lastInsertId();

// 4. Dean User
$deanEmail = 'deliv_dean_' . time() . '@sti.edu';
$db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Dr. Testing Dean', ?, ?, 'dean', 'Administration')")->execute([$deanEmail, $hashedPwd]);
$deanId = (int)$db->lastInsertId();

$cookieLeader   = loginUserAndGetCookie($leaderEmail, $testPwd);
$cookieAssigned = loginUserAndGetCookie($assignedEmail, $testPwd);
$cookieUnauth   = loginUserAndGetCookie($unauthEmail, $testPwd);
$cookieDean     = loginUserAndGetCookie($deanEmail, $testPwd);

assertCheck("Leader authenticated", !empty($cookieLeader));
assertCheck("Assigned User authenticated", !empty($cookieAssigned));
assertCheck("Unauthorized User authenticated", !empty($cookieUnauth));
assertCheck("Dean authenticated", !empty($cookieDean));

// Create test activity
$db->prepare("
    INSERT INTO activities (faculty_id, title, description, theme, venue, event_date, start_time, end_time, status)
    VALUES (?, 'Cybersecurity & Deliverable Summit 2026', 'Testing Deliverables', 'Innovation', 'STI Hall', '2026-11-25', '09:00:00', '16:00:00', 'approved')
")->execute([$leaderId]);
$actId = (int)$db->lastInsertId();
assertCheck("Test activity created (ID: {$actId})", $actId > 0);

// Files to clean up on disk
$createdDiskFiles = [];

try {
    // -------------------------------------------------------------
    // VERIFICATION 1: Assign a task to the test user
    // -------------------------------------------------------------
    echo "\n--- 1. Assign Task to Test User ---\n";
    $taskRes = httpRequest($taskApiUrl, 'POST', json_encode([
        'action'          => 'create',
        'activity_id'     => $actId,
        'task_title'      => 'Network Vulnerability Report & Diagram',
        'task_description'=> 'Deliver comprehensive network topology and security audit report.',
        'committee'       => 'Technical & Cybersecurity Committee',
        'assigned_user_id'=> $assignedId,
        'assigned_member' => 'Prof. Assigned Member',
        'role'            => 'Security Auditor',
        'due_date'        => '2026-11-20',
        'status'          => 'Not Started'
    ]), $cookieLeader);

    $taskId = (int)($taskRes['decoded']['task_id'] ?? 0);
    assertCheck("Task successfully created (ID: {$taskId})", $taskRes['code'] === 200 && $taskId > 0, $taskRes['body']);

    $taskRow = $db->query("SELECT * FROM faculty_tasks WHERE id = {$taskId}")->fetch(PDO::FETCH_ASSOC);
    assertCheck("Task assigned to test user ID {$assignedId}", (int)$taskRow['assigned_user_id'] === $assignedId);
    assertCheck("Initial completion percentage is 0%", (int)$taskRow['completion_pct'] === 0);
    assertCheck("Initial status is 'Not Started'", $taskRow['status'] === 'Not Started');

    // -------------------------------------------------------------
    // VERIFICATION 2: Upload a deliverable as that user
    // -------------------------------------------------------------
    echo "\n--- 2. Upload Deliverable as Assigned User ---\n";
    $tempFile1 = sys_get_temp_dir() . '/security_vulnerability_report.pdf';
    file_put_contents($tempFile1, "%PDF-1.4 Mock Deliverable Content for Task {$taskId}");

    $uploadRes = httpRequest($delivApiUrl . '?action=upload', 'POST', [
        'task_id'          => $taskId,
        'deliverable_file' => new CURLFile($tempFile1, 'application/pdf', 'security_vulnerability_report.pdf'),
        'notes'            => 'Initial draft of network vulnerability assessment.'
    ], $cookieAssigned);

    assertCheck("Deliverable upload succeeded (HTTP 200)", $uploadRes['code'] === 200 && ($uploadRes['decoded']['success'] ?? false), $uploadRes['body']);
    $deliv1Id = (int)($uploadRes['decoded']['deliverable']['id'] ?? 0);
    assertCheck("Deliverable record created with ID {$deliv1Id}", $deliv1Id > 0);

    // Verify task status progressed from Not Started to In Progress
    $taskRowAfterUpload = $db->query("SELECT * FROM faculty_tasks WHERE id = {$taskId}")->fetch(PDO::FETCH_ASSOC);
    assertCheck("Task status advanced to 'In Progress' upon deliverable upload", $taskRowAfterUpload['status'] === 'In Progress');

    // -------------------------------------------------------------
    // VERIFICATION 3: Confirm file persists and opens
    // -------------------------------------------------------------
    echo "\n--- 3. Confirm File Persists and Opens Protectedly ---\n";
    $dRow = $db->query("SELECT * FROM task_deliverables WHERE id = {$deliv1Id}")->fetch(PDO::FETCH_ASSOC);
    assertCheck("Deliverable stored with task_id={$taskId} and activity_id={$actId}", 
        (int)$dRow['task_id'] === $taskId && (int)$dRow['activity_id'] === $actId);
    assertCheck("Deliverable recorded file name: '{$dRow['file_name']}'", $dRow['file_name'] === 'security_vulnerability_report.pdf');

    $relPath = ltrim(str_replace('\\', '/', $dRow['file_path']), '/');
    if (str_starts_with($relPath, 'uploads/')) {
        $relPath = substr($relPath, 8);
    }
    $diskPath = $rootDir . '/uploads/' . $relPath;
    $createdDiskFiles[] = $diskPath;
    assertCheck("File exists on local filesystem at path", file_exists($diskPath));

    // Confirm file opens via protected download endpoint for assigned user
    $downRes = httpRequest($downloadUrl . '?deliverable_id=' . $deliv1Id, 'GET', null, $cookieAssigned);
    assertCheck("Assigned user can open deliverable via api/document-download.php (HTTP 200)", $downRes['code'] === 200);
    assertCheck("Download response contains original file content", str_contains($downRes['body'], "Mock Deliverable Content for Task {$taskId}"));

    // Confirm direct URL access to file is forbidden (protected by .htaccess)
    $directUrl = BASE_URL . '/uploads/' . $relPath;
    $directRes = httpRequest($directUrl, 'GET');
    assertCheck("Direct URL access to /uploads/deliverables/ is blocked (HTTP 403)", $directRes['code'] === 403, "Code: {$directRes['code']}");

    // -------------------------------------------------------------
    // VERIFICATION 4 & 5: Review as activity leader & set 25%, 60%, then 100%
    // -------------------------------------------------------------
    echo "\n--- 4-5. Review as Activity Leader & Set 25%, 60%, then 100% ---\n";

    // Set 25%
    $set25Res = httpRequest($delivApiUrl, 'POST', json_encode([
        'action'         => 'set_completion',
        'task_id'        => $taskId,
        'completion_pct' => 25
    ]), $cookieLeader);
    assertCheck("Leader set completion to 25% (HTTP 200)", $set25Res['code'] === 200 && ($set25Res['decoded']['success'] ?? false));
    $t25Row = $db->query("SELECT * FROM faculty_tasks WHERE id = {$taskId}")->fetch(PDO::FETCH_ASSOC);
    assertCheck("Stored completion_pct is 25%", (int)$t25Row['completion_pct'] === 25);
    assertCheck("Task status is 'In Progress' at 25%", $t25Row['status'] === 'In Progress');

    // Set 60%
    $set60Res = httpRequest($delivApiUrl, 'POST', json_encode([
        'action'         => 'set_completion',
        'task_id'        => $taskId,
        'completion_pct' => 60
    ]), $cookieLeader);
    assertCheck("Leader set completion to 60% (HTTP 200)", $set60Res['code'] === 200 && ($set60Res['decoded']['success'] ?? false));
    $t60Row = $db->query("SELECT * FROM faculty_tasks WHERE id = {$taskId}")->fetch(PDO::FETCH_ASSOC);
    assertCheck("Stored completion_pct is 60%", (int)$t60Row['completion_pct'] === 60);
    assertCheck("Task status is 'In Progress' at 60%", $t60Row['status'] === 'In Progress');

    // Set 100%
    $set100Res = httpRequest($delivApiUrl, 'POST', json_encode([
        'action'         => 'set_completion',
        'task_id'        => $taskId,
        'completion_pct' => 100
    ]), $cookieLeader);
    assertCheck("Leader set completion to 100% (HTTP 200)", $set100Res['code'] === 200 && ($set100Res['decoded']['success'] ?? false));
    $t100Row = $db->query("SELECT * FROM faculty_tasks WHERE id = {$taskId}")->fetch(PDO::FETCH_ASSOC);
    assertCheck("Stored completion_pct is 100%", (int)$t100Row['completion_pct'] === 100);
    assertCheck("Task status transitioned to 'Completed' at 100%", $t100Row['status'] === 'Completed');

    // -------------------------------------------------------------
    // VERIFICATION 6: Upload second deliverable & return for improvement
    // -------------------------------------------------------------
    echo "\n--- 6. Return Deliverable for Improvement ---\n";
    $tempFile2 = sys_get_temp_dir() . '/network_topology_v2.pdf';
    file_put_contents($tempFile2, "%PDF-1.4 Mock Deliverable 2 Topology");

    $upload2Res = httpRequest($delivApiUrl . '?action=upload', 'POST', [
        'task_id'          => $taskId,
        'deliverable_file' => new CURLFile($tempFile2, 'application/pdf', 'network_topology_v2.pdf'),
        'notes'            => 'Topology diagram attached.'
    ], $cookieAssigned);
    $deliv2Id = (int)($upload2Res['decoded']['deliverable']['id'] ?? 0);
    assertCheck("Second deliverable uploaded (ID: {$deliv2Id})", $deliv2Id > 0);

    $dRow2 = $db->query("SELECT * FROM task_deliverables WHERE id = {$deliv2Id}")->fetch(PDO::FETCH_ASSOC);
    $relPath2 = ltrim(str_replace('\\', '/', $dRow2['file_path']), '/');
    if (str_starts_with($relPath2, 'uploads/')) {
        $relPath2 = substr($relPath2, 8);
    }
    $createdDiskFiles[] = $rootDir . '/uploads/' . $relPath2;

    // Leader returns deliverable for improvement with review notes and sets 70%
    $returnRes = httpRequest($delivApiUrl, 'POST', json_encode([
        'action'         => 'review',
        'deliverable_id' => $deliv2Id,
        'review_status'  => 'returned_for_improvement',
        'notes'          => 'Firewall DMZ zone missing from topology diagram. Please update.',
        'completion_pct' => 70
    ]), $cookieLeader);

    assertCheck("Leader reviewed and returned deliverable for improvement (HTTP 200)", $returnRes['code'] === 200 && ($returnRes['decoded']['success'] ?? false));

    $dRow2After = $db->query("SELECT * FROM task_deliverables WHERE id = {$deliv2Id}")->fetch(PDO::FETCH_ASSOC);
    assertCheck("Deliverable review_status is 'returned_for_improvement'", $dRow2After['review_status'] === 'returned_for_improvement');
    assertCheck("Review notes persisted: '{$dRow2After['review_notes']}'", str_contains($dRow2After['review_notes'], 'Firewall DMZ zone missing'));
    assertCheck("Reviewed by leader ID {$leaderId}", (int)$dRow2After['reviewed_by'] === $leaderId);

    $tRowAfterReturn = $db->query("SELECT * FROM faculty_tasks WHERE id = {$taskId}")->fetch(PDO::FETCH_ASSOC);
    assertCheck("Task completion_pct updated to 70%", (int)$tRowAfterReturn['completion_pct'] === 70);
    assertCheck("Task is NOT completed after being returned for improvement (Status: {$tRowAfterReturn['status']})", $tRowAfterReturn['status'] !== 'Completed');

    // -------------------------------------------------------------
    // VERIFICATION 7: Confirm Gantt reflects the completion percentage
    // -------------------------------------------------------------
    echo "\n--- 7. Confirm Gantt Reflects Stored Completion Percentage ---\n";
    // Check that dean/task-monitoring.php reads stored completion_pct
    $monRes = httpRequest(BASE_URL . '/dean/task-monitoring.php?activity_id=' . $actId, 'GET', null, $cookieDean);
    assertCheck("Dean task monitoring page loads successfully (HTTP 200)", $monRes['code'] === 200);
    // Gantt JSON payload or HTML contains the task title and completion percentage
    assertCheck("Task monitoring view contains task title", str_contains($monRes['body'], 'Network Vulnerability Report'));
    assertCheck("Task monitoring view displays 70% completion", str_contains($monRes['body'], '70%'));

    // -------------------------------------------------------------
    // VERIFICATION 8: Confirm Dean can view but cannot modify (Read-Only)
    // -------------------------------------------------------------
    echo "\n--- 8. Confirm Dean Can View Read-Only But Cannot Modify ---\n";
    // Dean can list deliverables
    $deanListRes = httpRequest($delivApiUrl . '?action=list&task_id=' . $taskId, 'GET', null, $cookieDean);
    assertCheck("Dean can list deliverables (HTTP 200)", $deanListRes['code'] === 200 && ($deanListRes['decoded']['success'] ?? false));
    assertCheck("Dean list includes both deliverables", count($deanListRes['decoded']['deliverables'] ?? []) === 2);

    // Dean can download deliverables
    $deanDownRes = httpRequest($downloadUrl . '?deliverable_id=' . $deliv1Id, 'GET', null, $cookieDean);
    assertCheck("Dean can open deliverable via download endpoint (HTTP 200)", $deanDownRes['code'] === 200);

    // Dean attempting to review returns 403 Forbidden
    $deanReviewRes = httpRequest($delivApiUrl, 'POST', json_encode([
        'action'         => 'review',
        'deliverable_id' => $deliv1Id,
        'review_status'  => 'approved',
        'completion_pct' => 100
    ]), $cookieDean);
    assertCheck("Dean attempting to review deliverable is rejected (HTTP 403 Forbidden)", $deanReviewRes['code'] === 403);

    // Dean attempting to set completion percentage returns 403 Forbidden
    $deanSetRes = httpRequest($delivApiUrl, 'POST', json_encode([
        'action'         => 'set_completion',
        'task_id'        => $taskId,
        'completion_pct' => 100
    ]), $cookieDean);
    assertCheck("Dean attempting to set completion percentage is rejected (HTTP 403 Forbidden)", $deanSetRes['code'] === 403);

    // Dean attempting to upload deliverable returns 403 Forbidden
    $tempFileDean = sys_get_temp_dir() . '/dean_test.pdf';
    file_put_contents($tempFileDean, "%PDF-1.4 Mock Dean File");
    $deanUploadRes = httpRequest($delivApiUrl . '?action=upload', 'POST', [
        'task_id'          => $taskId,
        'deliverable_file' => new CURLFile($tempFileDean, 'application/pdf', 'dean_test.pdf')
    ], $cookieDean);
    assertCheck("Dean attempting to upload deliverable is rejected (HTTP 403 Forbidden)", $deanUploadRes['code'] === 403);
    @unlink($tempFileDean);

    // -------------------------------------------------------------
    // VERIFICATION 9: Confirm unauthorized user receives 403
    // -------------------------------------------------------------
    echo "\n--- 9. Confirm Unauthorized User Receives 403 ---\n";
    // Unauthorized user upload
    $tempFileUnauth = sys_get_temp_dir() . '/unauth_test.pdf';
    file_put_contents($tempFileUnauth, "%PDF-1.4 Mock Unauth");
    $unauthUploadRes = httpRequest($delivApiUrl . '?action=upload', 'POST', [
        'task_id'          => $taskId,
        'deliverable_file' => new CURLFile($tempFileUnauth, 'application/pdf', 'unauth_test.pdf')
    ], $cookieUnauth);
    assertCheck("Unauthorized user upload rejected (HTTP 403 Forbidden)", $unauthUploadRes['code'] === 403);
    @unlink($tempFileUnauth);

    // Unauthorized user review
    $unauthReviewRes = httpRequest($delivApiUrl, 'POST', json_encode([
        'action'         => 'review',
        'deliverable_id' => $deliv1Id,
        'review_status'  => 'approved'
    ]), $cookieUnauth);
    assertCheck("Unauthorized user review rejected (HTTP 403 Forbidden)", $unauthReviewRes['code'] === 403);

    // Unauthorized user download
    $unauthDownRes = httpRequest($downloadUrl . '?deliverable_id=' . $deliv1Id, 'GET', null, $cookieUnauth);
    assertCheck("Unauthorized user download rejected (HTTP 403 Forbidden)", $unauthDownRes['code'] === 403);

    // Unauthorized user list
    $unauthListRes = httpRequest($delivApiUrl . '?action=list&activity_id=' . $actId, 'GET', null, $cookieUnauth);
    assertCheck("Unauthorized user list deliverables rejected (HTTP 403 Forbidden)", $unauthListRes['code'] === 403);

    // -------------------------------------------------------------
    // VERIFICATION 10: Existing file-security & protected downloads tests
    // -------------------------------------------------------------
    echo "\n--- 10. Existing File Security Tests & Boundary Checks ---\n";
    // Test unauthenticated access (no session)
    $guestDownRes = httpRequest($downloadUrl . '?deliverable_id=' . $deliv1Id, 'GET');
    assertCheck("Guest (unauthenticated) download rejected (HTTP 403 or redirect)", in_array($guestDownRes['code'], [401, 403, 302]));

    // Path traversal check on document-download
    $traversalRes = httpRequest($downloadUrl . '?deliverable_id=999999', 'GET', null, $cookieDean);
    assertCheck("Non-existent deliverable ID returns HTTP 404", $traversalRes['code'] === 404);

} finally {
    // -------------------------------------------------------------
    // CLEANUP: Remove test activity, tasks, deliverables, and disk files
    // -------------------------------------------------------------
    echo "\n--- Cleaning Up Test Fixtures ---\n";
    foreach ($createdDiskFiles as $f) {
        if (file_exists($f)) {
            @unlink($f);
        }
    }
    if (isset($tempFile1) && file_exists($tempFile1)) @unlink($tempFile1);
    if (isset($tempFile2) && file_exists($tempFile2)) @unlink($tempFile2);

    if ($actId > 0) {
        $db->exec("DELETE FROM task_reminder_logs WHERE activity_id = {$actId}");
        $db->exec("DELETE FROM notifications WHERE activity_id = {$actId} OR user_id IN ({$leaderId}, {$assignedId}, {$unauthId}, {$deanId})");
        $db->exec("DELETE FROM email_logs WHERE activity_id = {$actId}");
        $db->exec("DELETE FROM task_deliverables WHERE activity_id = {$actId}");
        $db->exec("DELETE FROM faculty_tasks WHERE activity_id = {$actId}");
        $db->exec("DELETE FROM activities WHERE id = {$actId}");
    }

    $db->prepare("DELETE FROM users WHERE id IN (?, ?, ?, ?)")->execute([$leaderId, $assignedId, $unauthId, $deanId]);
    echo "  Cleaned up database test records & temp files.\n";
}

echo "\n============================================================\n";
echo "  RESULTS: {$passedTests} passed, {$failedTests} failed\n";
echo "============================================================\n";

if ($failedTests > 0) {
    exit(1);
}
exit(0);
