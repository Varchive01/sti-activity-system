<?php
/**
 * Focused Verification Suite for Task Progress Reminders
 * 
 * Verifies:
 * 1. Create a test task due soon and incomplete.
 * 2. Confirm an "about to be delayed" notification is generated.
 * 3. Create an overdue incomplete task.
 * 4. Confirm a delayed notification is generated.
 * 5. Set a task to 100%.
 * 6. Confirm a completion notification is generated.
 * 7. Run the same reminder check again.
 * 8. Confirm duplicate reminders are NOT created.
 * 9. Confirm the intended assigned user/leader recipients.
 * 10. Confirm Dean/Admin read-only permissions remain unchanged.
 * 11. Verify Outlook/email logging if the task reminder uses email.
 * 12. Confirm state/date changes allow the next appropriate reminder.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/EmailService.php';

$db = getDB();

echo "============================================================\n";
echo " TASK PROGRESS REMINDERS VERIFICATION SUITE\n";
echo "============================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $title, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$title}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$title}" . ($detail ? " - {$detail}" : "") . "\n";
        $failed++;
    }
}

// -------------------------------------------------------------
// 1. Setup Test Users
// -------------------------------------------------------------
$testPwd = password_hash('Password123!', PASSWORD_DEFAULT);
$unique = time() . '_' . rand(100, 999);

$leaderEmail   = "task_leader_{$unique}@sti.edu";
$assignedEmail = "task_member_{$unique}@sti.edu";
$unrelatedEmail= "task_unrelated_{$unique}@sti.edu";
$deanEmail     = "task_dean_{$unique}@sti.edu";

$db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Prof. Leader', ?, ?, 'faculty', 'Information Technology')")->execute([$leaderEmail, $testPwd]);
$leaderId = (int)$db->lastInsertId();

$db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Prof. Assigned Member', ?, ?, 'faculty', 'Information Technology')")->execute([$assignedEmail, $testPwd]);
$assignedUserId = (int)$db->lastInsertId();

$db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Prof. Unrelated', ?, ?, 'faculty', 'General Education')")->execute([$unrelatedEmail, $testPwd]);
$unrelatedId = (int)$db->lastInsertId();

$db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Dr. Test Dean', ?, ?, 'dean', 'Administration')")->execute([$deanEmail, $testPwd]);
$deanId = (int)$db->lastInsertId();

assertTest("Test users created (Leader: {$leaderId}, Member: {$assignedUserId}, Unrelated: {$unrelatedId}, Dean: {$deanId})", 
    $leaderId > 0 && $assignedUserId > 0 && $unrelatedId > 0 && $deanId > 0);

// -------------------------------------------------------------
// 2. Setup Test Activity
// -------------------------------------------------------------
$actStmt = $db->prepare("
    INSERT INTO activities (faculty_id, title, description, venue, event_date, start_time, end_time, status)
    VALUES (?, 'Task Progress Reminder Summit', 'Testing task progress reminders', 'Main Hall', CURDATE() + INTERVAL 10 DAY, '09:00:00', '17:00:00', 'approved')
");
$actStmt->execute([$leaderId]);
$testActId = (int)$db->lastInsertId();
assertTest("Test activity created with ID {$testActId}", $testActId > 0);

// -------------------------------------------------------------
// STEP 1 & 2: Task Due Soon (About to be delayed)
// -------------------------------------------------------------
echo "\n--- 1-2. Testing Task Due Soon (About to be delayed) ---\n";

// Task due in 2 days (approaching deadline <= 3 days), incomplete (0%)
$db->prepare("
    INSERT INTO faculty_tasks (activity_id, assigned_user_id, faculty_name, task_title, role_in_event, due_date, status, completion_pct, created_by)
    VALUES (?, ?, 'Prof. Assigned Member', 'Prepare Audio Visual Equipment', 'AV Lead', CURDATE() + INTERVAL 2 DAY, 'Not Started', 0, ?)
")->execute([$testActId, $assignedUserId, $leaderId]);
$taskDueSoonId = (int)$db->lastInsertId();
assertTest("Task 1 (Due Soon) created with ID {$taskDueSoonId}", $taskDueSoonId > 0);

// Run task reminder check
$res1 = checkTaskProgressReminders($db, $leaderId, 'faculty', $testActId);
assertTest("Reminder check ran and inspected tasks", $res1['checked'] > 0);
assertTest("Reminder notifications dispatched", $res1['notified'] >= 2, "Notified count: {$res1['notified']}");

// Confirm assigned member received task-specific reminder
$memberNotifCount = (int)$db->query("
    SELECT COUNT(*) FROM notifications 
    WHERE user_id = {$assignedUserId} AND activity_id = {$testActId} AND (message LIKE '%Due Soon%' OR message LIKE '%due%')
")->fetchColumn();
assertTest("Assigned member received 'About to be delayed' task-specific reminder", $memberNotifCount > 0);

// Confirm activity leader received monitoring reminder
$leaderNotifCount = (int)$db->query("
    SELECT COUNT(*) FROM notifications 
    WHERE user_id = {$leaderId} AND activity_id = {$testActId} AND (message LIKE '%Monitoring%' OR message LIKE '%due%')
")->fetchColumn();
assertTest("Activity leader received 'About to be delayed' monitoring reminder", $leaderNotifCount > 0);

// Confirm unrelated faculty received ZERO notifications
$unrelatedNotifCount = (int)$db->query("
    SELECT COUNT(*) FROM notifications WHERE user_id = {$unrelatedId} AND activity_id = {$testActId}
")->fetchColumn();
assertTest("Unrelated faculty received 0 notifications for this task", $unrelatedNotifCount === 0);

// Confirm Dean received ZERO notifications for this individual task
$deanNotifCount = (int)$db->query("
    SELECT COUNT(*) FROM notifications WHERE user_id = {$deanId} AND activity_id = {$testActId}
")->fetchColumn();
assertTest("Dean received 0 individual task spam notifications", $deanNotifCount === 0);

// -------------------------------------------------------------
// STEP 3 & 4: Overdue Incomplete Task (Delayed)
// -------------------------------------------------------------
echo "\n--- 3-4. Testing Overdue Incomplete Task (Delayed) ---\n";

// Task past due (due 2 days ago), incomplete (status In Progress, 40%)
$db->prepare("
    INSERT INTO faculty_tasks (activity_id, assigned_user_id, faculty_name, task_title, role_in_event, due_date, status, completion_pct, created_by)
    VALUES (?, ?, 'Prof. Assigned Member', 'Catering & Refreshments Order', 'Logistics Lead', CURDATE() - INTERVAL 2 DAY, 'In Progress', 40, ?)
")->execute([$testActId, $assignedUserId, $leaderId]);
$taskDelayedId = (int)$db->lastInsertId();
assertTest("Task 2 (Delayed) created with ID {$taskDelayedId}", $taskDelayedId > 0);

// Run reminder check
$res2 = checkTaskProgressReminders($db, $leaderId, 'faculty', $testActId);
assertTest("Reminder check processed delayed task", $res2['notified'] >= 2);

// Confirm assigned member received delayed notification
$memberDelayedCount = (int)$db->query("
    SELECT COUNT(*) FROM notifications 
    WHERE user_id = {$assignedUserId} AND activity_id = {$testActId} AND (message LIKE '%Delayed%' OR message LIKE '%overdue%')
")->fetchColumn();
assertTest("Assigned member received 'Delayed' task notification", $memberDelayedCount > 0);

// Confirm activity leader received delayed monitoring notification
$leaderDelayedCount = (int)$db->query("
    SELECT COUNT(*) FROM notifications 
    WHERE user_id = {$leaderId} AND activity_id = {$testActId} AND (message LIKE '%Delayed%' OR message LIKE '%overdue%')
")->fetchColumn();
assertTest("Activity leader received 'Delayed' monitoring notification", $leaderDelayedCount > 0);

// -------------------------------------------------------------
// STEP 5 & 6: Completed Task (100%)
// -------------------------------------------------------------
echo "\n--- 5-6. Testing Completed Task (100%) ---\n";

// Create a task that reaches 100% / Completed
$db->prepare("
    INSERT INTO faculty_tasks (activity_id, assigned_user_id, faculty_name, task_title, role_in_event, due_date, status, completion_pct, created_by)
    VALUES (?, ?, 'Prof. Assigned Member', 'Keynote Speaker Invitation Plaque', 'Liaison', CURDATE() + INTERVAL 5 DAY, 'Completed', 100, ?)
")->execute([$testActId, $assignedUserId, $leaderId]);
$taskCompletedId = (int)$db->lastInsertId();
assertTest("Task 3 (Completed) created with ID {$taskCompletedId}", $taskCompletedId > 0);

// Run reminder check
$res3 = checkTaskProgressReminders($db, $leaderId, 'faculty', $testActId);
assertTest("Reminder check processed completed task", $res3['notified'] >= 1);

// Confirm activity leader received completion notification
$leaderCompletedCount = (int)$db->query("
    SELECT COUNT(*) FROM notifications 
    WHERE user_id = {$leaderId} AND activity_id = {$testActId} AND (message LIKE '%Completed%' OR message LIKE '%100%')
")->fetchColumn();
assertTest("Activity leader received completion notification", $leaderCompletedCount > 0);

// -------------------------------------------------------------
// STEP 7 & 8: Deduplication on Repeated Runs
// -------------------------------------------------------------
echo "\n--- 7-8. Testing Deduplication on Repeated Runs ---\n";

$notifsBefore = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE activity_id = {$testActId}")->fetchColumn();
$emailsBefore = (int)$db->query("SELECT COUNT(*) FROM email_logs WHERE activity_id = {$testActId}")->fetchColumn();
$logsBefore   = (int)$db->query("SELECT COUNT(*) FROM task_reminder_logs WHERE activity_id = {$testActId}")->fetchColumn();

// Re-run reminder checks multiple times (simulating repeat dashboard visits)
checkTaskProgressReminders($db, $leaderId, 'faculty', $testActId);
checkTaskProgressReminders($db, $assignedUserId, 'faculty', $testActId);
checkTaskProgressReminders($db, $deanId, 'dean', $testActId);
checkTaskProgressReminders($db, null, null, $testActId);

$notifsAfter = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE activity_id = {$testActId}")->fetchColumn();
$emailsAfter = (int)$db->query("SELECT COUNT(*) FROM email_logs WHERE activity_id = {$testActId}")->fetchColumn();
$logsAfter   = (int)$db->query("SELECT COUNT(*) FROM task_reminder_logs WHERE activity_id = {$testActId}")->fetchColumn();

assertTest("No duplicate in-app notifications generated on repeat checks", $notifsBefore === $notifsAfter, "Before: {$notifsBefore}, After: {$notifsAfter}");
assertTest("No duplicate emails generated on repeat checks", $emailsBefore === $emailsAfter, "Before: {$emailsBefore}, After: {$emailsAfter}");
assertTest("No duplicate reminder log entries generated on repeat checks", $logsBefore === $logsAfter, "Before: {$logsBefore}, After: {$logsAfter}");

// -------------------------------------------------------------
// STEP 9: Confirm Recipient Scoping and Isolation
// -------------------------------------------------------------
echo "\n--- 9. Recipient Scoping & Cross-Activity Security ---\n";

// Verify all notifications for this activity are ONLY for the leader or the assigned user
$otherUserNotifs = (int)$db->query("
    SELECT COUNT(*) FROM notifications 
    WHERE activity_id = {$testActId} AND user_id NOT IN ({$leaderId}, {$assignedUserId})
")->fetchColumn();
assertTest("Zero notifications generated for unauthorized / unrelated users", $otherUserNotifs === 0);

// -------------------------------------------------------------
// STEP 10: Dean / Admin Read-Only Permissions Maintained
// -------------------------------------------------------------
echo "\n--- 10. Dean / Admin Read-Only Permissions Maintained ---\n";

$apiUrl = BASE_URL . '/api/task-assignment.php';
$loginUrl = BASE_URL . '/auth/login.php';

function curlPost(string $url, array $fields, ?string $cookie = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($fields),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    if ($cookie) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($raw, $hSize);
    return ['code' => $code, 'body' => $body, 'decoded' => json_decode($body, true)];
}

function getLoginCookie(string $email, string $pwd): string {
    global $loginUrl;
    $ch = curl_init($loginUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['email' => $email, 'password' => $pwd]),
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $raw = curl_exec($ch);
    preg_match_all('/Set-Cookie:\s*(PHPSESSID=[^;]+)/i', $raw, $m);
    return !empty($m[1]) ? end($m[1]) : '';
}

$deanCookie = getLoginCookie($deanEmail, 'Password123!');
assertTest("Dean authenticated successfully", !empty($deanCookie));

// Dean cannot mutate task
$deanCreate = curlPost($apiUrl, [
    'action' => 'create',
    'activity_id' => $testActId,
    'task_title' => 'Dean Unauthorized Task'
], $deanCookie);
assertTest("Dean blocked from CREATE task (HTTP 403)", $deanCreate['code'] === 403);

$deanUpdate = curlPost($apiUrl, [
    'action' => 'update_status',
    'activity_id' => $testActId,
    'id' => $taskDueSoonId,
    'status' => 'Completed'
], $deanCookie);
assertTest("Dean blocked from UPDATE_STATUS task (HTTP 403)", $deanUpdate['code'] === 403);

$deanDelete = curlPost($apiUrl, [
    'action' => 'delete',
    'activity_id' => $testActId,
    'id' => $taskDueSoonId
], $deanCookie);
assertTest("Dean blocked from DELETE task (HTTP 403)", $deanDelete['code'] === 403);

// -------------------------------------------------------------
// STEP 11: Outlook / Email Logging Verification
// -------------------------------------------------------------
echo "\n--- 11. Outlook / Email Logging Verification ---\n";

$emailLogsCount = (int)$db->query("
    SELECT COUNT(*) FROM email_logs 
    WHERE activity_id = {$testActId} AND notification_type LIKE 'task_%'
")->fetchColumn();
assertTest("Email logs present for task reminders in email_logs", $emailLogsCount > 0, "Email logs count: {$emailLogsCount}");

$dueSoonEmail = (int)$db->query("
    SELECT COUNT(*) FROM email_logs 
    WHERE activity_id = {$testActId} AND notification_type LIKE 'task_due_soon_%'
")->fetchColumn();
assertTest("Task due soon email logged in email_logs", $dueSoonEmail > 0);

$delayedEmail = (int)$db->query("
    SELECT COUNT(*) FROM email_logs 
    WHERE activity_id = {$testActId} AND notification_type LIKE 'task_delayed_%'
")->fetchColumn();
assertTest("Task delayed email logged in email_logs", $delayedEmail > 0);

$completedEmail = (int)$db->query("
    SELECT COUNT(*) FROM email_logs 
    WHERE activity_id = {$testActId} AND notification_type LIKE 'task_completed_%'
")->fetchColumn();
assertTest("Task completed email logged in email_logs", $completedEmail > 0);

// -------------------------------------------------------------
// STEP 12: Date Change Allows Next Reminder
// -------------------------------------------------------------
echo "\n--- 12. Date Change Allows Next Reminder ---\n";

// Update task 1 due date to 1 day from now (new date)
$db->prepare("UPDATE faculty_tasks SET due_date = CURDATE() + INTERVAL 1 DAY WHERE id = ?")->execute([$taskDueSoonId]);

$notifsBeforeDateChange = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE activity_id = {$testActId}")->fetchColumn();
checkTaskProgressReminders($db, $leaderId, 'faculty', $testActId);
$notifsAfterDateChange = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE activity_id = {$testActId}")->fetchColumn();

assertTest("Updated due date generates appropriate new reminder cycle", $notifsAfterDateChange > $notifsBeforeDateChange, 
    "Before: {$notifsBeforeDateChange}, After: {$notifsAfterDateChange}");

// Repeat again with the new date -> should be deduplicated
checkTaskProgressReminders($db, $leaderId, 'faculty', $testActId);
$notifsRepeatNewDate = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE activity_id = {$testActId}")->fetchColumn();
assertTest("New date reminder is properly deduplicated on subsequent runs", $notifsAfterDateChange === $notifsRepeatNewDate);

// -------------------------------------------------------------
// CLEANUP
// -------------------------------------------------------------
echo "\n--- Cleanup Test Records ---\n";
$db->prepare("DELETE FROM task_reminder_logs WHERE activity_id = ?")->execute([$testActId]);
$db->prepare("DELETE FROM notifications WHERE activity_id = ?")->execute([$testActId]);
$db->prepare("DELETE FROM email_logs WHERE activity_id = ?")->execute([$testActId]);
$db->prepare("DELETE FROM faculty_tasks WHERE activity_id = ?")->execute([$testActId]);
$db->prepare("DELETE FROM activities WHERE id = ?")->execute([$testActId]);
$db->prepare("DELETE FROM users WHERE id IN (?, ?, ?, ?)")->execute([$leaderId, $assignedUserId, $unrelatedId, $deanId]);
echo "  [DONE] Test records successfully cleaned up.\n";

echo "\n============================================================\n";
echo " VERIFICATION SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "============================================================\n";

if ($failed > 0) {
    exit(1);
}
