<?php
/**
 * tests/test_task_role_assignment.php
 *
 * Automated verification test suite for Task & Role Assignment foundation:
 * 1. Create/use one test activity.
 * 2. Assign at least 3 tasks.
 * 3. Assign tasks to different people/roles.
 * 4. Assign different due dates.
 * 5. Save.
 * 6. Reload the activity and confirm assignments persist.
 * 7. Edit one assignment and confirm persistence.
 * 8. Change a task to In Progress and then Completed.
 * 9. Confirm unauthorized users cannot modify another person's activity assignments.
 * 10. Confirm Dean can view the assignments.
 * 11. Confirm no duplicate assignment records are created on save.
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
echo "  TASK & ROLE ASSIGNMENT FOUNDATION VERIFICATION SUITE\n";
echo "============================================================\n\n";

$loginUrl = BASE_URL . '/auth/login.php';
$apiUrl   = BASE_URL . '/api/task-assignment.php';

// Helper for HTTP requests passing cookie string
function httpRequest(string $url, string $method = 'GET', $data = null, ?string $cookie = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $headers = [];
    if (!empty($cookie)) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (is_array($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
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
        'code' => $code,
        'headers' => $headerStr,
        'body' => $body,
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
// SETUP: Prepare users and credentials
// -------------------------------------------------------------
$testPwd = 'Password123!';
$hashedPwd = password_hash($testPwd, PASSWORD_DEFAULT);

// 1. Leader Faculty (owns the activity)
$leaderEmail = 'faculty_leader_' . time() . '@sti.edu';
$db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Prof. Maria Leader', ?, ?, 'faculty', 'Information Technology')")->execute([$leaderEmail, $hashedPwd]);
$leaderId = (int)$db->lastInsertId();

// 2. Other Faculty (Unauthorized faculty user)
$otherFacEmail = 'faculty_other_' . time() . '@sti.edu';
$db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Prof. John Other', ?, ?, 'faculty', 'General Education')")->execute([$otherFacEmail, $hashedPwd]);
$otherFacId = (int)$db->lastInsertId();

// 3. Dean
$deanEmail = 'dean_test_' . time() . '@sti.edu';
$db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Dr. Frederic Dean', ?, ?, 'dean', 'Administration')")->execute([$deanEmail, $hashedPwd]);
$deanId = (int)$db->lastInsertId();

$cookieLeader = loginUserAndGetCookie($leaderEmail, $testPwd);
$cookieOther  = loginUserAndGetCookie($otherFacEmail, $testPwd);
$cookieDean   = loginUserAndGetCookie($deanEmail, $testPwd);

assertCheck("Leader authenticated with active session", !empty($cookieLeader));
assertCheck("Other Faculty authenticated with active session", !empty($cookieOther));
assertCheck("Dean authenticated with active session", !empty($cookieDean));

// -------------------------------------------------------------
// STEP 1: Create one test activity owned by leader
// -------------------------------------------------------------
echo "\n--- 1. Create Test Activity ---\n";
$actStmt = $db->prepare("
    INSERT INTO activities (faculty_id, title, description, theme, venue, event_date, start_time, end_time, status)
    VALUES (?, 'Foundation IT Summit 2026', 'Annual Technology Conference', 'Innovations', 'STI Auditorium', '2026-11-20', '08:00:00', '17:00:00', 'approved')
");
$actStmt->execute([$leaderId]);
$testActId = (int)$db->lastInsertId();

assertCheck("Test activity created with ID: {$testActId}", $testActId > 0);

// Populate manpower in test activity to verify integration with existing People
$db->prepare("INSERT INTO manpower (activity_id, role, assigned_person, type) VALUES (?, 'Medic', 'Nurse Joy', 'staff')")->execute([$testActId]);
$db->prepare("INSERT INTO manpower (activity_id, role, assigned_person, type) VALUES (?, 'Emcee', 'Prof. Smith', 'faculty')")->execute([$testActId]);

// -------------------------------------------------------------
// STEP 2, 3, 4, 5: Assign at least 3 tasks with distinct people, roles, due dates, statuses
// -------------------------------------------------------------
echo "\n--- 2-5. Assign 3 Tasks with Distinct People, Roles, Due Dates, and Initial Statuses ---\n";

// Task 1
$t1Res = httpRequest($apiUrl, 'POST', json_encode([
    'action' => 'create',
    'activity_id' => $testActId,
    'task_title' => 'Technical Stage & AV Setup',
    'task_description' => 'Setup sound mixing console, 4 wireless microphones, and projector display.',
    'committee' => 'Technical & Logistics Committee',
    'assigned_member' => 'Ian Jade Barangan',
    'role' => 'AV Support Lead',
    'due_date' => '2026-11-15',
    'status' => 'Not Started'
]), $cookieLeader);
$t1Id = $t1Res['decoded']['task_id'] ?? 0;
assertCheck("Task 1 created: 'Technical Stage & AV Setup' (ID: {$t1Id}, Status: Not Started, Due: 2026-11-15)", $t1Res['code'] === 200 && $t1Id > 0, $t1Res['body']);

// Task 2
$t2Res = httpRequest($apiUrl, 'POST', json_encode([
    'action' => 'create',
    'activity_id' => $testActId,
    'task_title' => 'Participant Registration Desk & Kit Distribution',
    'task_description' => 'Assemble registration tables, barcode scanners, and attendee ID lanyards.',
    'committee' => 'Registration & Ushering Committee',
    'assigned_member' => 'Ar-jay Agabayani',
    'role' => 'Head Usher',
    'due_date' => '2026-11-18',
    'status' => 'In Progress'
]), $cookieLeader);
$t2Id = $t2Res['decoded']['task_id'] ?? 0;
assertCheck("Task 2 created: 'Participant Registration Desk' (ID: {$t2Id}, Status: In Progress, Due: 2026-11-18)", $t2Res['code'] === 200 && $t2Id > 0, $t2Res['body']);

// Task 3
$t3Res = httpRequest($apiUrl, 'POST', json_encode([
    'action' => 'create',
    'activity_id' => $testActId,
    'task_title' => 'Guest Speaker Plaque & Awards Preparation',
    'task_description' => 'Order customized plaques and print certificates of appreciation with official seal.',
    'committee' => 'Events Committee',
    'assigned_member' => 'Maria Santos',
    'role' => 'Awards Coordinator',
    'due_date' => '2026-11-10',
    'status' => 'Delayed'
]), $cookieLeader);
$t3Id = $t3Res['decoded']['task_id'] ?? 0;
assertCheck("Task 3 created: 'Guest Speaker Plaque & Awards' (ID: {$t3Id}, Status: Delayed, Due: 2026-11-10)", $t3Res['code'] === 200 && $t3Id > 0, $t3Res['body']);

// -------------------------------------------------------------
// STEP 6: Reload the activity and confirm assignments persist & no duplicates
// -------------------------------------------------------------
echo "\n--- 6. Reload Activity & Confirm Assignments Persist Without Duplication ---\n";
$listRes = httpRequest($apiUrl . '?action=list&activity_id=' . $testActId, 'GET', null, $cookieLeader);
$tasks = $listRes['decoded']['tasks'] ?? [];

assertCheck("API returned HTTP 200 on reload", $listRes['code'] === 200);
assertCheck("Activity Leader identified: '{$listRes['decoded']['activity']['leader_name']}'", !empty($listRes['decoded']['activity']['leader_name']));
assertCheck("Exactly 3 task assignments returned (no duplication)", count($tasks) === 3, "Count: " . count($tasks));

$dbCount = (int)$db->query("SELECT COUNT(*) FROM faculty_tasks WHERE activity_id = {$testActId}")->fetchColumn();
assertCheck("Database row count equals exactly 3", $dbCount === 3, "DB Count: {$dbCount}");

// -------------------------------------------------------------
// STEP 7: Edit one assignment and confirm persistence
// -------------------------------------------------------------
echo "\n--- 7. Edit One Assignment and Confirm Persistence ---\n";
$editRes = httpRequest($apiUrl, 'POST', json_encode([
    'action' => 'update',
    'activity_id' => $testActId,
    'id' => $t1Id,
    'task_title' => 'Technical Stage & AV Setup (High Priority)',
    'task_description' => 'Updated: Added live-stream backup encoder and backup wireless lavaliers.',
    'committee' => 'Technical & Logistics Committee',
    'assigned_member' => 'Ian Jade Barangan',
    'role' => 'Senior Technical Lead',
    'due_date' => '2026-11-14',
    'status' => 'In Progress'
]), $cookieLeader);

assertCheck("Edit request succeeded (HTTP 200)", $editRes['code'] === 200 && ($editRes['decoded']['success'] ?? false));

// Reload and verify updated values
$checkEditStmt = $db->prepare("SELECT * FROM faculty_tasks WHERE id = ?");
$checkEditStmt->execute([$t1Id]);
$editedRow = $checkEditStmt->fetch(PDO::FETCH_ASSOC);

assertCheck("Task title persisted update: '{$editedRow['task_title']}'", $editedRow['task_title'] === 'Technical Stage & AV Setup (High Priority)');
assertCheck("Role persisted update: '{$editedRow['role_in_event']}'", $editedRow['role_in_event'] === 'Senior Technical Lead');
assertCheck("Due date persisted update: '{$editedRow['due_date']}'", $editedRow['due_date'] === '2026-11-14');
assertCheck("Status persisted update: '{$editedRow['status']}'", $editedRow['status'] === 'In Progress');

// Verify total count did not increase after edit
$dbCountAfterEdit = (int)$db->query("SELECT COUNT(*) FROM faculty_tasks WHERE activity_id = {$testActId}")->fetchColumn();
assertCheck("No duplicate record created after edit (count remains 3)", $dbCountAfterEdit === 3);

// -------------------------------------------------------------
// STEP 8: Change a task to In Progress and then Completed
// -------------------------------------------------------------
echo "\n--- 8. Transition Status: In Progress -> Completed ---\n";

// Change Task 2 status to Completed
$statRes = httpRequest($apiUrl, 'POST', json_encode([
    'action' => 'update_status',
    'activity_id' => $testActId,
    'id' => $t2Id,
    'status' => 'Completed'
]), $cookieLeader);

assertCheck("Status update to Completed succeeded (HTTP 200)", $statRes['code'] === 200 && ($statRes['decoded']['success'] ?? false));

$dbStatus = $db->query("SELECT status FROM faculty_tasks WHERE id = {$t2Id}")->fetchColumn();
assertCheck("Database reflects updated status 'Completed'", $dbStatus === 'Completed', "DB status: {$dbStatus}");

// -------------------------------------------------------------
// STEP 9: Confirm unauthorized users cannot modify another person's activity assignments
// -------------------------------------------------------------
echo "\n--- 9. Authorization: Unauthorized Users Cannot Modify Assignments ---\n";

// Other faculty cannot create assignment for this activity
$unauthCreate = httpRequest($apiUrl, 'POST', json_encode([
    'action' => 'create',
    'activity_id' => $testActId,
    'task_title' => 'Malicious Task Injection'
]), $cookieOther);
assertCheck("Unauthorized faculty blocked from CREATE (HTTP 403)", $unauthCreate['code'] === 403, "Code: {$unauthCreate['code']}");

// Other faculty cannot update assignment
$unauthUpdate = httpRequest($apiUrl, 'POST', json_encode([
    'action' => 'update',
    'activity_id' => $testActId,
    'id' => $t1Id,
    'task_title' => 'Tampered Title'
]), $cookieOther);
assertCheck("Unauthorized faculty blocked from UPDATE (HTTP 403)", $unauthUpdate['code'] === 403, "Code: {$unauthUpdate['code']}");

// Other faculty cannot update status
$unauthStatus = httpRequest($apiUrl, 'POST', json_encode([
    'action' => 'update_status',
    'activity_id' => $testActId,
    'id' => $t1Id,
    'status' => 'Completed'
]), $cookieOther);
assertCheck("Unauthorized faculty blocked from UPDATE_STATUS (HTTP 403)", $unauthStatus['code'] === 403, "Code: {$unauthStatus['code']}");

// Other faculty cannot delete assignment
$unauthDelete = httpRequest($apiUrl, 'POST', json_encode([
    'action' => 'delete',
    'activity_id' => $testActId,
    'id' => $t1Id
]), $cookieOther);
assertCheck("Unauthorized faculty blocked from DELETE (HTTP 403)", $unauthDelete['code'] === 403, "Code: {$unauthDelete['code']}");

// Other faculty cannot list/view assignments of unrelated activity
$unauthList = httpRequest($apiUrl . '?action=list&activity_id=' . $testActId, 'GET', null, $cookieOther);
assertCheck("Unauthorized faculty blocked from LIST (HTTP 403)", $unauthList['code'] === 403, "Code: {$unauthList['code']}");

// Other faculty blocked from faculty/view-activity.php for unrelated activity
$unauthPage = httpRequest(BASE_URL . '/faculty/view-activity.php?id=' . $testActId, 'GET', null, $cookieOther);
assertCheck("Unauthorized faculty blocked from view-activity.php page", str_contains($unauthPage['body'], 'Unauthorized access'));

// -------------------------------------------------------------
// STEP 10: Confirm Dean can view assignments
// -------------------------------------------------------------
echo "\n--- 10. Dean Access: Confirm Dean Can View Assignments ---\n";

$deanApiRes = httpRequest($apiUrl . '?action=list&activity_id=' . $testActId, 'GET', null, $cookieDean);
assertCheck("Dean can retrieve assignments list via API (HTTP 200)", $deanApiRes['code'] === 200);
assertCheck("Dean receives all 3 tasks in response", count($deanApiRes['decoded']['tasks'] ?? []) === 3);
assertCheck("Leader identified for Dean: '{$deanApiRes['decoded']['activity']['leader_name']}'", !empty($deanApiRes['decoded']['activity']['leader_name']));

// Dean cannot mutate assignments
$deanCreate = httpRequest($apiUrl, 'POST', json_encode([
    'action' => 'create',
    'activity_id' => $testActId,
    'task_title' => 'Dean Creating Task'
]), $cookieDean);
assertCheck("Dean is restricted to VIEW only (mutations return HTTP 403)", $deanCreate['code'] === 403);

// Dean HTML view page renders Task & Role Assignments table
$deanPageRes = httpRequest(BASE_URL . '/dean/view-activity.php?id=' . $testActId, 'GET', null, $cookieDean);
assertCheck("Dean view-activity.php responds with HTTP 200", $deanPageRes['code'] === 200);
assertCheck("Dean page contains 'Task & Role Assignments'", str_contains($deanPageRes['body'], 'Task & Role Assignments'));
assertCheck("Dean page renders task titles", str_contains($deanPageRes['body'], htmlspecialchars('Technical Stage & AV Setup (High Priority)')));
assertCheck("Dean page renders status badges", str_contains($deanPageRes['body'], 'badge-success') || str_contains($deanPageRes['body'], 'Completed'));

// -------------------------------------------------------------
// STEP 11: Confirm Leader Page Renders Correctly
// -------------------------------------------------------------
echo "\n--- 11. Leader View Page Renders Task Management UI ---\n";

$leaderPageRes = httpRequest(BASE_URL . '/faculty/view-activity.php?id=' . $testActId, 'GET', null, $cookieLeader);
assertCheck("Leader view-activity.php responds with HTTP 200", $leaderPageRes['code'] === 200);
assertCheck("Leader page contains 'Task & Role Assignments'", str_contains($leaderPageRes['body'], 'Task & Role Assignments'));
assertCheck("Leader page contains '+ Add Task Assignment' button", str_contains($leaderPageRes['body'], '+ Add Task Assignment'));
assertCheck("Leader page contains task modal", str_contains($leaderPageRes['body'], 'taskAssignmentModal'));
assertCheck("Leader page identifies Activity Leader Spearhead", str_contains($leaderPageRes['body'], 'Spearhead'));

// -------------------------------------------------------------
// STEP 12: Delete Task Assignment
// -------------------------------------------------------------
echo "\n--- 12. Delete Task Assignment ---\n";
$delRes = httpRequest($apiUrl, 'POST', json_encode([
    'action' => 'delete',
    'activity_id' => $testActId,
    'id' => $t3Id
]), $cookieLeader);

assertCheck("Delete task request succeeded (HTTP 200)", $delRes['code'] === 200 && ($delRes['decoded']['success'] ?? false));

$dbRemaining = (int)$db->query("SELECT COUNT(*) FROM faculty_tasks WHERE activity_id = {$testActId}")->fetchColumn();
assertCheck("Database reflects exactly 2 tasks remaining after deletion", $dbRemaining === 2, "Remaining: {$dbRemaining}");

// -------------------------------------------------------------
// CLEANUP
// -------------------------------------------------------------
echo "\n--- Cleanup Test Records ---\n";
$db->exec("DELETE FROM task_reminder_logs WHERE activity_id = {$testActId}");
$db->exec("DELETE FROM notifications WHERE activity_id = {$testActId} OR user_id IN ({$leaderId}, {$otherFacId}, {$deanId})");
$db->exec("DELETE FROM email_logs WHERE activity_id = {$testActId}");
$db->exec("DELETE FROM faculty_tasks WHERE activity_id = {$testActId}");
$db->exec("DELETE FROM manpower WHERE activity_id = {$testActId}");
$db->exec("DELETE FROM activities WHERE id = {$testActId}");
$db->exec("DELETE FROM users WHERE id IN ({$leaderId}, {$otherFacId}, {$deanId})");
echo "  [DONE] Test records cleaned up.\n";

echo "\n============================================================\n";
echo "  TEST SUMMARY: {$passedTests} passed, {$failedTests} failed\n";
echo "============================================================\n";

if ($failedTests > 0) {
    exit(1);
}
