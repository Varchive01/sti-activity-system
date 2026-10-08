<?php
/**
 * Focused Verification Suite for Objective 6 Proposal Escalation Alerts
 * 
 * Verifies:
 * 1. Test fixtures older than 3 days for applicable roles (admin1, admin2, dean, faculty) trigger alerts.
 * 2. Appropriate reviewer and faculty receive an in-app escalation notification.
 * 3. Email dispatch is attempted and logged in email_logs.
 * 4. A second dashboard visit does NOT create duplicate escalations for the same inactivity period.
 * 5. Status / update resets the escalation window.
 * 6. Normal non-stagnant proposals (fresh updated_at) are NOT alerted.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/EmailService.php';

$db = getDB();

echo "============================================================\n";
echo " OBJECTIVE 6: PROPOSAL ESCALATION ALERTS VERIFICATION\n";
echo "============================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $title, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$title}\n";
        $passed++;
    } else {
        echo " [FAIL] {$title}" . ($detail ? " - {$detail}" : "") . "\n";
        $failed++;
    }
}

// 1. Get test users
$faculty = $db->query("SELECT id, name, email FROM users WHERE role='faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin1  = $db->query("SELECT id, name, email FROM users WHERE role='admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin2  = $db->query("SELECT id, name, email FROM users WHERE role='admin2' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$dean    = $db->query("SELECT id, name, email FROM users WHERE role='dean' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$faculty || !$admin1 || !$admin2 || !$dean) {
    echo "[FAIL] Required test users (faculty, admin1, admin2, dean) missing in database.\n";
    exit(1);
}

$facultyId = (int)$faculty['id'];
$admin1Id  = (int)$admin1['id'];
$admin2Id  = (int)$admin2['id'];
$deanId    = (int)$dean['id'];

// 2. Create Test Fixtures
echo "--- 1. Creating Test Fixtures ---\n";

// A. Stagnant for Admin1 (student_org, under_review, 4 days old)
$db->prepare("
    INSERT INTO activities (faculty_id, title, description, source, status, event_date, start_time, end_time, venue, created_at, updated_at)
    VALUES (?, 'Stagnant Org Event Admin1', 'Testing Admin1 escalation', 'student_org', 'under_review', CURDATE(), '10:00:00', '12:00:00', 'Hall A', NOW() - INTERVAL 4 DAY, NOW() - INTERVAL 4 DAY)
")->execute([$facultyId]);
$actAdmin1Id = (int)$db->lastInsertId();

// Ensure MySQL timestamp ON UPDATE didn't reset updated_at
$db->prepare("UPDATE activities SET updated_at = NOW() - INTERVAL 4 DAY WHERE id = ?")->execute([$actAdmin1Id]);

// B. Stagnant for Admin2 (faculty, submitted, 4 days old)
$db->prepare("
    INSERT INTO activities (faculty_id, title, description, source, status, event_date, start_time, end_time, venue, created_at, updated_at)
    VALUES (?, 'Stagnant Faculty Event Admin2', 'Testing Admin2 escalation', 'faculty', 'submitted', CURDATE(), '13:00:00', '15:00:00', 'Lab 1', NOW() - INTERVAL 4 DAY, NOW() - INTERVAL 4 DAY)
")->execute([$facultyId]);
$actAdmin2Id = (int)$db->lastInsertId();
$db->prepare("UPDATE activities SET updated_at = NOW() - INTERVAL 4 DAY WHERE id = ?")->execute([$actAdmin2Id]);

// C. Stagnant for Dean (pending_final_approval, 4 days old)
$db->prepare("
    INSERT INTO activities (faculty_id, title, description, source, status, event_date, start_time, end_time, venue, created_at, updated_at)
    VALUES (?, 'Stagnant Final Event Dean', 'Testing Dean escalation', 'faculty', 'pending_final_approval', CURDATE(), '14:00:00', '16:00:00', 'Auditorium', NOW() - INTERVAL 4 DAY, NOW() - INTERVAL 4 DAY)
")->execute([$facultyId]);
$actDeanId = (int)$db->lastInsertId();
$db->prepare("UPDATE activities SET updated_at = NOW() - INTERVAL 4 DAY WHERE id = ?")->execute([$actDeanId]);

// D. Fresh non-stagnant event (under_review, 1 hour old)
$db->prepare("
    INSERT INTO activities (faculty_id, title, description, source, status, event_date, start_time, end_time, venue, created_at, updated_at)
    VALUES (?, 'Fresh Event Non Stagnant', 'Testing non-stagnant', 'student_org', 'under_review', CURDATE(), '10:00:00', '12:00:00', 'Hall B', NOW(), NOW())
")->execute([$facultyId]);
$actFreshId = (int)$db->lastInsertId();

assertTest("Fixtures successfully created with appropriate ages", $actAdmin1Id > 0 && $actAdmin2Id > 0 && $actDeanId > 0 && $actFreshId > 0);

// --- 2. Testing Escalation Triggers and In-App Notifications ---
echo "\n--- 2. Testing Escalation Trigger and In-App Notifications ---\n";

// Trigger Admin1 dashboard escalation check
$res1 = checkProposalEscalations($db, $admin1Id, 'admin1');
assertTest("Admin1 dashboard check inspected proposals", $res1['checked'] > 0);

// Verify Admin1 received in-app notification
$stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND activity_id = ? AND message LIKE '%Escalation%'");
$stmt->execute([$admin1Id, $actAdmin1Id]);
assertTest("Admin1 received in-app escalation notification", (int)$stmt->fetchColumn() > 0);

// Verify Faculty proponent also received in-app notification for Admin1 stagnant proposal
$stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND activity_id = ? AND message LIKE '%stagnant%'");
$stmt->execute([$facultyId, $actAdmin1Id]);
assertTest("Faculty proponent received in-app stagnant alert for act_admin1", (int)$stmt->fetchColumn() > 0);

// Trigger Admin2 dashboard escalation check
$res2 = checkProposalEscalations($db, $admin2Id, 'admin2');
assertTest("Admin2 dashboard check inspected proposals", $res2['checked'] > 0);

$stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND activity_id = ? AND message LIKE '%Escalation%'");
$stmt->execute([$admin2Id, $actAdmin2Id]);
assertTest("Admin2 received in-app escalation notification", (int)$stmt->fetchColumn() > 0);

$stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND activity_id = ? AND message LIKE '%stagnant%'");
$stmt->execute([$facultyId, $actAdmin2Id]);
assertTest("Faculty proponent received in-app stagnant alert for act_admin2", (int)$stmt->fetchColumn() > 0);

// Trigger Dean dashboard escalation check
$res3 = checkProposalEscalations($db, $deanId, 'dean');
assertTest("Dean dashboard check inspected proposals", $res3['checked'] > 0);

$stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND activity_id = ? AND message LIKE '%Escalation%'");
$stmt->execute([$deanId, $actDeanId]);
assertTest("Dean received in-app escalation notification", (int)$stmt->fetchColumn() > 0);

$stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND activity_id = ? AND message LIKE '%stagnant%'");
$stmt->execute([$facultyId, $actDeanId]);
assertTest("Faculty proponent received in-app stagnant alert for act_dean", (int)$stmt->fetchColumn() > 0);

// --- 3. Testing Email Dispatch and Logging ---
echo "\n--- 3. Testing Email Dispatch & Logging in email_logs ---\n";

$emailLogsAdmin1 = $db->prepare("SELECT COUNT(*) FROM email_logs WHERE activity_id = ? AND notification_type = 'proposal_escalated'");
$emailLogsAdmin1->execute([$actAdmin1Id]);
$emailCount1 = (int)$emailLogsAdmin1->fetchColumn();
assertTest("Email dispatch attempted and logged for act_admin1", $emailCount1 > 0, "Logged count: {$emailCount1}");

$emailLogsAdmin2 = $db->prepare("SELECT COUNT(*) FROM email_logs WHERE activity_id = ? AND notification_type = 'proposal_escalated'");
$emailLogsAdmin2->execute([$actAdmin2Id]);
$emailCount2 = (int)$emailLogsAdmin2->fetchColumn();
assertTest("Email dispatch attempted and logged for act_admin2", $emailCount2 > 0, "Logged count: {$emailCount2}");

$emailLogsDean = $db->prepare("SELECT COUNT(*) FROM email_logs WHERE activity_id = ? AND notification_type = 'proposal_escalated'");
$emailLogsDean->execute([$actDeanId]);
$emailCount3 = (int)$emailLogsDean->fetchColumn();
assertTest("Email dispatch attempted and logged for act_dean", $emailCount3 > 0, "Logged count: {$emailCount3}");

// --- 4. Testing Fresh / Non-Stagnant Proposals ---
echo "\n--- 4. Testing Fresh Non-Stagnant Proposals Not Alerted ---\n";

$freshNotifs = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE activity_id = {$actFreshId}")->fetchColumn();
assertTest("No in-app notifications created for fresh activity", $freshNotifs === 0);

$freshEmails = (int)$db->query("SELECT COUNT(*) FROM email_logs WHERE activity_id = {$actFreshId}")->fetchColumn();
assertTest("No email logs created for fresh activity", $freshEmails === 0);

// --- 5. Testing Deduplication on Second Dashboard Visit ---
echo "\n--- 5. Testing Deduplication on Repeated Dashboard Visits ---\n";

$notifsBefore = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE activity_id IN ({$actAdmin1Id}, {$actAdmin2Id}, {$actDeanId})")->fetchColumn();
$emailsBefore = (int)$db->query("SELECT COUNT(*) FROM email_logs WHERE activity_id IN ({$actAdmin1Id}, {$actAdmin2Id}, {$actDeanId}) AND notification_type = 'proposal_escalated'")->fetchColumn();

// Re-run checks for all roles (simulating repeated visits)
checkProposalEscalations($db, $admin1Id, 'admin1');
checkProposalEscalations($db, $admin2Id, 'admin2');
checkProposalEscalations($db, $deanId, 'dean');
checkProposalEscalations($db, $facultyId, 'faculty');

$notifsAfter = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE activity_id IN ({$actAdmin1Id}, {$actAdmin2Id}, {$actDeanId})")->fetchColumn();
$emailsAfter = (int)$db->query("SELECT COUNT(*) FROM email_logs WHERE activity_id IN ({$actAdmin1Id}, {$actAdmin2Id}, {$actDeanId}) AND notification_type = 'proposal_escalated'")->fetchColumn();

assertTest("No duplicate in-app notifications generated on repeat visits", $notifsBefore === $notifsAfter, "Before: {$notifsBefore}, After: {$notifsAfter}");
assertTest("No duplicate emails generated on repeat visits", $emailsBefore === $emailsAfter, "Before: {$emailsBefore}, After: {$emailsAfter}");

// --- 6. Testing Status / Update Resets Escalation Window ---
echo "\n--- 6. Testing Status / Update Resets Escalation Window ---\n";

// Update actAdmin1 to NOW() (e.g. proponent made an edit or status updated)
$db->prepare("UPDATE activities SET updated_at = NOW() WHERE id = ?")->execute([$actAdmin1Id]);

// Visit Admin1 dashboard again
$notifsBeforeResetCheck = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE activity_id = {$actAdmin1Id}")->fetchColumn();
checkProposalEscalations($db, $admin1Id, 'admin1');
$notifsAfterResetCheck = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE activity_id = {$actAdmin1Id}")->fetchColumn();

assertTest("Freshly updated activity is no longer stagnant (< 3 days)", $notifsBeforeResetCheck === $notifsAfterResetCheck);

// Now simulate that cycle 1 notifications occurred 5 days ago, and proposal was updated 4 days ago
$db->prepare("UPDATE notifications SET created_at = NOW() - INTERVAL 5 DAY WHERE activity_id = ?")->execute([$actAdmin1Id]);
$db->prepare("UPDATE activities SET updated_at = NOW() - INTERVAL 4 DAY WHERE id = ?")->execute([$actAdmin1Id]);

// Visit Admin1 dashboard again
checkProposalEscalations($db, $admin1Id, 'admin1');
$notifsAfterNewStagnancy = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE activity_id = {$actAdmin1Id}")->fetchColumn();

assertTest("New stagnation cycle triggers new escalation notification", $notifsAfterNewStagnancy > $notifsAfterResetCheck, "Before: {$notifsAfterResetCheck}, New Total: {$notifsAfterNewStagnancy}");

// --- 7. Cleanup ---
echo "\n--- 7. Cleanup Test Fixtures ---\n";
$allTestIds = [$actAdmin1Id, $actAdmin2Id, $actDeanId, $actFreshId];
$inClause = implode(',', $allTestIds);
$db->exec("DELETE FROM notifications WHERE activity_id IN ({$inClause})");
$db->exec("DELETE FROM email_logs WHERE activity_id IN ({$inClause})");
$db->exec("DELETE FROM activities WHERE id IN ({$inClause})");
echo "Cleaned up test activities, notifications, and email logs.\n";

echo "\n============================================================\n";
echo " VERIFICATION SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "============================================================\n";

if ($failed > 0) {
    exit(1);
}
