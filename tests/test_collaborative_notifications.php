<?php
/**
 * tests/test_collaborative_notifications.php
 *
 * Automated verification suite for TASK 18:
 * COLLABORATIVE PROPOSAL REAL-TIME IN-SYSTEM AND EMAIL NOTIFICATIONS
 *
 * Uses three designated Faculty accounts:
 * - Faculty A: Owner (Maria Santos / faculty@sti.edu)
 * - Faculty B: Co-Author (Andrea Gatbunton / fac_thm_accept@test.com)
 * - Faculty C: Contributor (Ricky Manliguis / fac_ge_accept@test.com)
 * Plus an Unrelated Faculty account for security isolation verification.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/EmailService.php';


$db = getDB();
$baseUrl = 'http://localhost/sti-activity-system';

function makeRequest(string $url, string $method = 'GET', $data = [], ?string $cookie = null, array $headers = []): array {
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 25
    ];

    $customHeaders = $headers;

    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        if (is_array($data) && (isset($data['poster_file']) || isset($data['floor_plan_file']))) {
            $opts[CURLOPT_POSTFIELDS] = $data;
        } else {
            $opts[CURLOPT_POSTFIELDS] = is_array($data) ? http_build_query($data) : $data;
        }
    }

    if ($cookie) {
        $opts[CURLOPT_COOKIE] = $cookie;
    }

    if (!empty($customHeaders)) {
        $opts[CURLOPT_HTTPHEADER] = $customHeaders;
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

function getSessionId(string $cookie): string {
    if (preg_match('/PHPSESSID=([^;]+)/', $cookie, $m)) {
        return trim($m[1]);
    }
    return '';
}

$passCount = 0;
$failCount = 0;

function assertCheck(bool $condition, string $msg) {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$msg}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$msg}\n";
        $failCount++;
    }
}

echo "======================================================================\n";
echo "  TASK 18: COLLABORATIVE NOTIFICATIONS AUDIT SUITE\n";
echo "======================================================================\n\n";

// 1. Setup & Resolve Accounts
$pwdHash = password_hash('password', PASSWORD_DEFAULT);

$facultyA = $db->query("SELECT * FROM users WHERE email = 'faculty@sti.edu'")->fetch(PDO::FETCH_ASSOC);
$facultyB = $db->query("SELECT * FROM users WHERE email = 'fac_thm_accept@test.com'")->fetch(PDO::FETCH_ASSOC);
if (!$facultyB) {
    $db->prepare("INSERT INTO users (name, email, password, role, department, must_change_password) VALUES ('Andrea Gatbonton', 'fac_thm_accept@test.com', ?, 'faculty', 'THM Department', 0)")->execute([$pwdHash]);
    $facultyB = $db->query("SELECT * FROM users WHERE email = 'fac_thm_accept@test.com'")->fetch(PDO::FETCH_ASSOC);
}
$facultyC = $db->query("SELECT * FROM users WHERE email = 'fac_ge_accept@test.com'")->fetch(PDO::FETCH_ASSOC);
if (!$facultyC) {
    $db->prepare("INSERT INTO users (name, email, password, role, department, must_change_password) VALUES ('Ricky Manliguis', 'fac_ge_accept@test.com', ?, 'faculty', 'G.E. Department', 0)")->execute([$pwdHash]);
    $facultyC = $db->query("SELECT * FROM users WHERE email = 'fac_ge_accept@test.com'")->fetch(PDO::FETCH_ASSOC);
}

$unrelatedFac = $db->query("SELECT * FROM users WHERE email = 'fac_unrelated_test@test.com'")->fetch(PDO::FETCH_ASSOC);
if (!$unrelatedFac) {
    $db->prepare("INSERT INTO users (name, email, password, role, department, must_change_password) VALUES ('Unrelated Faculty Member', 'fac_unrelated_test@test.com', ?, 'faculty', 'THM Department', 0)")->execute([$pwdHash]);
    $unrelatedFac = $db->query("SELECT * FROM users WHERE email = 'fac_unrelated_test@test.com'")->fetch(PDO::FETCH_ASSOC);
}

// Establish authenticated sessions using standard credentials without overwriting database hashes
$cookieA = loginUser($baseUrl, $facultyA['email'], 'password');
$cookieB = loginUser($baseUrl, $facultyB['email'], 'password');
$cookieC = loginUser($baseUrl, $facultyC['email'], 'password');
$cookieUnrelated = loginUser($baseUrl, $unrelatedFac['email'], 'password');

assertCheck(!empty($cookieA) && !empty($cookieB) && !empty($cookieC) && !empty($cookieUnrelated), "All 4 test faculty sessions authenticated successfully.");

// 2. Create clean test proposal owned by Faculty A
$testTitle = "Collaborative Notification Test Proposal " . time();
$tomorrow = date('Y-m-d', strtotime('+3 days'));
$startTime = '09:00';
$endTime = '12:00';

$initObjectives = "To enhance student technical capabilities and professional career readiness.";
$initSpecific = "Complete practical technical hands-on workshop sessions and practical assessments.";
$initRationale = "This activity proposal provides comprehensive student skill enrichment and development.";

$insStmt = $db->prepare("
    INSERT INTO activities (
        faculty_id, title, theme, venue, venue_address, event_date, start_time, end_time,
        target_participants, general_objectives, specific_objectives, involved_subjects,
        rationale, evaluation_method, source, status, floor_plan_required, created_at, updated_at
    ) VALUES (
        ?, ?, 'Technical Enrichment', 'STI Hall', 'STI College Marikina', ?, ?, ?,
        50, ?, ?, 'IT101',
        ?, 'Feedback forms', 'draft', 'draft', 0, NOW(), NOW()
    )
");
$insStmt->execute([$facultyA['id'], $testTitle, $tomorrow, $startTime, $endTime, $initObjectives, $initSpecific, $initRationale]);
$testActId = (int)$db->lastInsertId();

// Register Owner in proposal_collaborators
$db->prepare("
    INSERT INTO proposal_collaborators (activity_id, user_id, role, added_by, status)
    VALUES (?, ?, 'owner', ?, 'active')
    ON DUPLICATE KEY UPDATE role = 'owner', status = 'active'
")->execute([$testActId, $facultyA['id'], $facultyA['id']]);

echo "\nCreated test proposal ID: {$testActId} ('{$testTitle}')\n";

// Clear any prior notifications or email logs for this activity ID
$db->prepare("DELETE FROM notifications WHERE activity_id = ?")->execute([$testActId]);
$db->prepare("DELETE FROM email_logs WHERE activity_id = ?")->execute([$testActId]);

// ── TEST 1 & 2: Faculty A adds Faculty B ──────────────────────────────────────
echo "\n--- TEST 1 & 2: Faculty A adds Faculty B as Co-Author ---\n";
$resAddB = makeRequest("{$baseUrl}/api/collaborators.php", 'POST', [
    'action'      => 'add',
    'activity_id' => $testActId,
    'user_id'     => $facultyB['id'],
    'role'        => 'co_author'
], $cookieA);

$dataAddB = json_decode($resAddB['body'], true);
assertCheck($resAddB['code'] === 200 && !empty($dataAddB['success']), "Requirement 1: Faculty A successfully invited Faculty B as Co-Author via API.");

// Check Faculty B in-system notification
$notifB = $db->query("SELECT * FROM notifications WHERE activity_id = {$testActId} AND user_id = {$facultyB['id']} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($notifB) && str_contains($notifB['message'], 'Co-Author') && str_contains($notifB['message'], $facultyA['name']), "Requirement 2a: Faculty B received in-system invitation notification identifying role (Co-Author) and inviting user ({$facultyA['name']}).");

// Check Faculty B email log
$emailB = $db->query("SELECT * FROM email_logs WHERE activity_id = {$testActId} AND recipient_email = '{$facultyB['email']}' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($emailB) && str_contains($emailB['notification_type'], 'collab_invite'), "Requirement 2b: Faculty B received invitation email notification (logged in email_logs).");

// Verify Faculty A received NO self-notification
$selfNotifA = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE activity_id = {$testActId} AND user_id = {$facultyA['id']}")->fetchColumn();
assertCheck($selfNotifA === 0, "Requirement 14 (Check 1): Faculty A did not receive self-notification on adding collaborator.");

// ── TEST 3, 4 & 5: Faculty A adds Faculty C ───────────────────────────────────
echo "\n--- TEST 3, 4 & 5: Faculty A adds Faculty C as Contributor ---\n";
$resAddC = makeRequest("{$baseUrl}/api/collaborators.php", 'POST', [
    'action'      => 'add',
    'activity_id' => $testActId,
    'user_id'     => $facultyC['id'],
    'role'        => 'contributor'
], $cookieA);

$dataAddC = json_decode($resAddC['body'], true);
assertCheck($resAddC['code'] === 200 && !empty($dataAddC['success']), "Requirement 3: Faculty A successfully invited Faculty C as Contributor via API.");

// Check Faculty C in-system notification
$notifC = $db->query("SELECT * FROM notifications WHERE activity_id = {$testActId} AND user_id = {$facultyC['id']} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($notifC) && str_contains($notifC['message'], 'Contributor') && str_contains($notifC['message'], $facultyA['name']), "Requirement 4a: Faculty C received in-system invitation notification identifying role (Contributor) and inviting user ({$facultyA['name']}).");

// Check Faculty C email log
$emailC = $db->query("SELECT * FROM email_logs WHERE activity_id = {$testActId} AND recipient_email = '{$facultyC['email']}' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($emailC) && str_contains($emailC['notification_type'], 'collab_invite'), "Requirement 4b: Faculty C received invitation email notification (logged in email_logs).");

// Check Requirement 5: Existing collaborator (Faculty B) received membership notification
$teamNotifB = $db->query("SELECT * FROM notifications WHERE activity_id = {$testActId} AND user_id = {$facultyB['id']} AND message LIKE '%{$facultyC['name']}%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($teamNotifB), "Requirement 5a: Existing collaborator Faculty B received in-system notification about Faculty C joining.");

$teamEmailB = $db->query("SELECT * FROM email_logs WHERE activity_id = {$testActId} AND recipient_email = '{$facultyB['email']}' AND notification_type LIKE 'collab_team_add%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($teamEmailB), "Requirement 5b: Existing collaborator Faculty B received team update email notification.");

// ── TEST 6 & 7: Faculty B edits proposal objectives ──────────────────────────
echo "\n--- TEST 6 & 7: Faculty B edits proposal objectives ---\n";
$actRow = $db->query("SELECT * FROM activities WHERE id = {$testActId}")->fetch(PDO::FETCH_ASSOC);
$updatedObjectives = "Advanced Technical Objectives revised by Faculty B at " . time();

$resEditB = makeRequest("{$baseUrl}/api/proposal-update.php", 'POST', [
    'activity_id'        => $testActId,
    'action'             => 'draft',
    'is_ajax_sync'       => '1',
    'title'              => $actRow['title'],
    'theme'              => $actRow['theme'],
    'venue'              => $actRow['venue'],
    'venue_address'      => $actRow['venue_address'],
    'event_date'         => $actRow['event_date'],
    'start_time'         => $actRow['start_time'],
    'end_time'           => $actRow['end_time'],
    'target_participants'=> $actRow['target_participants'],
    'general_objectives' => $updatedObjectives,
    'specific_objectives'=> "Deliver hands-on workshops; provide student assessment reviews.",
    'involved_subjects'  => $actRow['involved_subjects'],
    'rationale'          => $actRow['rationale'],
    'last_known_updated_at' => $actRow['updated_at']
], $cookieB, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);

$dataEditB = json_decode($resEditB['body'], true);
assertCheck($resEditB['code'] === 200 && !empty($dataEditB['success']), "Requirement 6: Faculty B saved draft edits to Objectives.");

// Check database updated
$checkObj = $db->query("SELECT general_objectives FROM activities WHERE id = {$testActId}")->fetchColumn();
assertCheck($checkObj === $updatedObjectives, "Objectives updated in activities table.");

// Check Faculty A received in-system + email notification
$notifA_fromB = $db->query("SELECT * FROM notifications WHERE activity_id = {$testActId} AND user_id = {$facultyA['id']} AND message LIKE '%{$facultyB['name']}%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($notifA_fromB) && str_contains($notifA_fromB['message'], 'Objectives'), "Requirement 7a: Faculty A received in-system notification of Faculty B's Objectives change.");

$emailA_fromB = $db->query("SELECT * FROM email_logs WHERE activity_id = {$testActId} AND recipient_email = '{$facultyA['email']}' AND notification_type LIKE 'collab_upd%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($emailA_fromB), "Requirement 7b: Faculty A received email notification of Objectives change.");

// Check Faculty C received in-system + email notification
$notifC_fromB = $db->query("SELECT * FROM notifications WHERE activity_id = {$testActId} AND user_id = {$facultyC['id']} AND message LIKE '%{$facultyB['name']}%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($notifC_fromB) && str_contains($notifC_fromB['message'], 'Objectives'), "Requirement 7c: Faculty C received in-system notification of Faculty B's Objectives change.");

$emailC_fromB = $db->query("SELECT * FROM email_logs WHERE activity_id = {$testActId} AND recipient_email = '{$facultyC['email']}' AND notification_type LIKE 'collab_upd%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($emailC_fromB), "Requirement 7d: Faculty C received email notification of Objectives change.");

// Check Faculty B received NO self-notification
$selfNotifB = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE activity_id = {$testActId} AND user_id = {$facultyB['id']} AND message LIKE '%{$facultyB['name']}%'")->fetchColumn();
assertCheck($selfNotifB === 0, "Requirement 14 (Check 2): Faculty B did not receive self-notification for their own edits.");

// ── TEST 8 & 9: Faculty C changes the schedule ────────────────────────────────
echo "\n--- TEST 8 & 9: Faculty C changes the schedule ---\n";
$actRow2 = $db->query("SELECT * FROM activities WHERE id = {$testActId}")->fetch(PDO::FETCH_ASSOC);
$newDate = date('Y-m-d', strtotime('+5 days'));

$resEditC = makeRequest("{$baseUrl}/api/proposal-update.php", 'POST', [
    'activity_id'        => $testActId,
    'action'             => 'draft',
    'is_ajax_sync'       => '1',
    'title'              => $actRow2['title'],
    'theme'              => $actRow2['theme'],
    'venue'              => $actRow2['venue'],
    'venue_address'      => $actRow2['venue_address'],
    'event_date'         => $newDate,
    'start_time'         => '10:00',
    'end_time'           => '14:00',
    'target_participants'=> $actRow2['target_participants'],
    'general_objectives' => $actRow2['general_objectives'],
    'specific_objectives'=> $actRow2['specific_objectives'],
    'involved_subjects'  => $actRow2['involved_subjects'],
    'rationale'          => $actRow2['rationale'],
    'last_known_updated_at' => $actRow2['updated_at']
], $cookieC, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);

$dataEditC = json_decode($resEditC['body'], true);
assertCheck($resEditC['code'] === 200 && !empty($dataEditC['success']), "Requirement 8: Faculty C saved draft changes to Schedule.");

// Check Faculty A received in-system + email
$notifA_fromC = $db->query("SELECT * FROM notifications WHERE activity_id = {$testActId} AND user_id = {$facultyA['id']} AND message LIKE '%{$facultyC['name']}%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($notifA_fromC) && str_contains($notifA_fromC['message'], 'Schedule & Program'), "Requirement 9a: Faculty A received in-system notification of Faculty C's Schedule change.");

$emailA_fromC = $db->query("SELECT * FROM email_logs WHERE activity_id = {$testActId} AND recipient_email = '{$facultyA['email']}' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($emailA_fromC) && str_contains($emailA_fromC['notification_type'], 'collab_upd'), "Requirement 9b: Faculty A received email notification of Schedule change.");

// Check Faculty B received in-system + email
$notifB_fromC = $db->query("SELECT * FROM notifications WHERE activity_id = {$testActId} AND user_id = {$facultyB['id']} AND message LIKE '%{$facultyC['name']}%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($notifB_fromC) && str_contains($notifB_fromC['message'], 'Schedule & Program'), "Requirement 9c: Faculty B received in-system notification of Faculty C's Schedule change.");

$emailB_fromC = $db->query("SELECT * FROM email_logs WHERE activity_id = {$testActId} AND recipient_email = '{$facultyB['email']}' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($emailB_fromC) && str_contains($emailB_fromC['notification_type'], 'collab_upd'), "Requirement 9d: Faculty B received email notification of Schedule change.");

// Check Faculty C received NO self-notification
$selfNotifC = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE activity_id = {$testActId} AND user_id = {$facultyC['id']} AND message LIKE '%{$facultyC['name']}%'")->fetchColumn();
assertCheck($selfNotifC === 0, "Requirement 14 (Check 3): Faculty C did not receive self-notification for their own edits.");

// ── TEST 10 & 11: Faculty A uploads an attachment ─────────────────────────────
echo "\n--- TEST 10 & 11: Faculty A uploads an attachment ---\n";
$actRow3 = $db->query("SELECT * FROM activities WHERE id = {$testActId}")->fetch(PDO::FETCH_ASSOC);
// Create a small mock PNG image in scratch for poster
$mockImg = __DIR__ . '/../scratch/test_poster.png';
if (!file_exists($mockImg)) {
    file_put_contents($mockImg, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
}

$cfile = new CURLFile($mockImg, 'image/png', 'test_poster.png');

$resUploadA = makeRequest("{$baseUrl}/api/proposal-update.php", 'POST', [
    'activity_id'        => $testActId,
    'action'             => 'draft',
    'is_ajax_sync'       => '1',
    'title'              => $actRow3['title'],
    'theme'              => $actRow3['theme'],
    'venue'              => $actRow3['venue'],
    'venue_address'      => $actRow3['venue_address'],
    'event_date'         => $actRow3['event_date'],
    'start_time'         => $actRow3['start_time'],
    'end_time'           => $actRow3['end_time'],
    'target_participants'=> $actRow3['target_participants'],
    'general_objectives' => $actRow3['general_objectives'],
    'specific_objectives'=> $actRow3['specific_objectives'],
    'involved_subjects'  => $actRow3['involved_subjects'],
    'rationale'          => $actRow3['rationale'],
    'poster_file'        => $cfile,
    'last_known_updated_at' => $actRow3['updated_at']
], $cookieA, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);

$dataUploadA = json_decode($resUploadA['body'], true);
assertCheck($resUploadA['code'] === 200 && !empty($dataUploadA['success']), "Requirement 10: Faculty A uploaded an attachment.");

// Check Faculty B received in-system + email
$notifB_attach = $db->query("SELECT * FROM notifications WHERE activity_id = {$testActId} AND user_id = {$facultyB['id']} AND message LIKE '%Supporting Documents & Attachments%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($notifB_attach) && str_contains($notifB_attach['message'], $facultyA['name']), "Requirement 11a: Faculty B received in-system notification of Faculty A's Attachment upload.");

$emailB_attach = $db->query("SELECT * FROM email_logs WHERE activity_id = {$testActId} AND recipient_email = '{$facultyB['email']}' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($emailB_attach) && str_contains($emailB_attach['notification_type'], 'collab_upd'), "Requirement 11b: Faculty B received email notification of Attachment upload.");

// Check Faculty C received in-system + email
$notifC_attach = $db->query("SELECT * FROM notifications WHERE activity_id = {$testActId} AND user_id = {$facultyC['id']} AND message LIKE '%Supporting Documents & Attachments%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($notifC_attach) && str_contains($notifC_attach['message'], $facultyA['name']), "Requirement 11c: Faculty C received in-system notification of Faculty A's Attachment upload.");

$emailC_attach = $db->query("SELECT * FROM email_logs WHERE activity_id = {$testActId} AND recipient_email = '{$facultyC['email']}' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($emailC_attach) && str_contains($emailC_attach['notification_type'], 'collab_upd'), "Requirement 11d: Faculty C received email notification of Attachment upload.");

// ── TEST 12 & 13: Faculty A submits the proposal ──────────────────────────────
echo "\n--- TEST 12 & 13: Faculty A submits the proposal ---\n";
$actRow4 = $db->query("SELECT * FROM activities WHERE id = {$testActId}")->fetch(PDO::FETCH_ASSOC);

$submitPayload = [
    'activity_id'        => $testActId,
    'action'             => 'submit',
    'title'              => $actRow4['title'],
    'theme'              => $actRow4['theme'],
    'venue'              => $actRow4['venue'],
    'venue_address'      => $actRow4['venue_address'],
    'event_date'         => $actRow4['event_date'],
    'start_time'         => $actRow4['start_time'],
    'end_time'           => $actRow4['end_time'],
    'target_participants'=> $actRow4['target_participants'],
    'general_objectives' => $actRow4['general_objectives'],
    'specific_objectives'=> $actRow4['specific_objectives'],
    'involved_subjects'  => $actRow4['involved_subjects'],
    'rationale'          => $actRow4['rationale'],
    'evaluation_method'  => 'Feedback forms',
    'kpi_indicator'      => ['Participant satisfaction rating >= 85%'],
    'kpi_target'         => ['85%'],
    'kpi_method'         => ['Post-activity survey form'],
    'last_known_updated_at' => $actRow4['updated_at']
];

// Submit proposal directly without AI validation interception

$resSubmitA = makeRequest("{$baseUrl}/api/proposal-update.php", 'POST', $submitPayload, $cookieA);
assertCheck($resSubmitA['code'] === 200 || $resSubmitA['code'] === 302, "Requirement 12a: Proposal Owner Faculty A submitted the proposal.");

$postSubmitStatus = $db->query("SELECT status FROM activities WHERE id = {$testActId}")->fetchColumn();
assertCheck($postSubmitStatus === 'submitted', "Requirement 12b: Activity proposal status updated to 'submitted'.");

// Check Faculty B received submission in-system + email
$notifB_sub = $db->query("SELECT * FROM notifications WHERE activity_id = {$testActId} AND user_id = {$facultyB['id']} AND message LIKE '%submitted%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($notifB_sub) && str_contains($notifB_sub['message'], $facultyA['name']), "Requirement 13a: Faculty B received in-system submission notification.");

$emailB_sub = $db->query("SELECT * FROM email_logs WHERE activity_id = {$testActId} AND recipient_email = '{$facultyB['email']}' AND notification_type LIKE 'collab_sub%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($emailB_sub), "Requirement 13b: Faculty B received submission email notification.");

// Check Faculty C received submission in-system + email
$notifC_sub = $db->query("SELECT * FROM notifications WHERE activity_id = {$testActId} AND user_id = {$facultyC['id']} AND message LIKE '%submitted%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($notifC_sub) && str_contains($notifC_sub['message'], $facultyA['name']), "Requirement 13c: Faculty C received in-system submission notification.");

$emailC_sub = $db->query("SELECT * FROM email_logs WHERE activity_id = {$testActId} AND recipient_email = '{$facultyC['email']}' AND notification_type LIKE 'collab_sub%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($emailC_sub), "Requirement 13d: Faculty C received submission email notification.");

// ── TEST 14: Strict Exclusion of Self-Notifications ───────────────────────────
echo "\n--- TEST 14: Strict Exclusion of Self-Notifications ---\n";
$selfNotifOwnerSubmit = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE activity_id = {$testActId} AND user_id = {$facultyA['id']} AND message LIKE '%submitted%'")->fetchColumn();
assertCheck($selfNotifOwnerSubmit === 0, "Requirement 14: Proposal Owner Faculty A received 0 self-notifications upon submission.");

// ── TEST 15: Isolation - Unrelated Faculty Receives Nothing ───────────────────
echo "\n--- TEST 15: Isolation - Unrelated Faculty Receives Nothing ---\n";
$unrelatedNotifCount = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE activity_id = {$testActId} AND user_id = {$unrelatedFac['id']}")->fetchColumn();
$unrelatedEmailCount = (int)$db->query("SELECT COUNT(*) FROM email_logs WHERE activity_id = {$testActId} AND recipient_email = '{$unrelatedFac['email']}'")->fetchColumn();
assertCheck($unrelatedNotifCount === 0 && $unrelatedEmailCount === 0, "Requirement 15: Unrelated Faculty received 0 in-system notifications and 0 email notifications.");

// ── TEST 16: Duplicate Notification Prevention ────────────────────────────────
echo "\n--- TEST 16: Duplicate Notification Prevention ---\n";
$notifCountBefore = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE activity_id = {$testActId}")->fetchColumn();
$emailCountBefore = (int)$db->query("SELECT COUNT(*) FROM email_logs WHERE activity_id = {$testActId}")->fetchColumn();

// Immediate duplicate dispatch attempt with identical event parameters
$dupResult = dispatchCollaboratorNotification($testActId, (int)$facultyA['id'], 'proposal_submitted', [
    'proposal_title' => $testTitle,
    'status_label'   => 'Submitted for Review'
], $db);

$notifCountAfter = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE activity_id = {$testActId}")->fetchColumn();
$emailCountAfter = (int)$db->query("SELECT COUNT(*) FROM email_logs WHERE activity_id = {$testActId}")->fetchColumn();

assertCheck($notifCountAfter === $notifCountBefore, "Requirement 16a: Deduplication prevented duplicate in-system notifications on repeated dispatch.");
assertCheck($emailCountAfter === $emailCountBefore, "Requirement 16b: Deduplication prevented duplicate email deliveries on repeated dispatch.");

// ── TEST 17: Email Failure Handling & Proposal Preservation ──────────────────
echo "\n--- TEST 17: Email Failure Handling & Proposal Preservation ---\n";
$failTitle = "Failure Resilience Test " . time();
$db->prepare("
    INSERT INTO activities (
        faculty_id, title, theme, venue, venue_address, event_date, start_time, end_time,
        target_participants, general_objectives, specific_objectives, involved_subjects,
        rationale, evaluation_method, source, status, floor_plan_required, created_at, updated_at
    ) VALUES (
        ?, ?, 'Fail Test', 'STI Room', 'STI Marikina', ?, '08:00', '10:00',
        20, 'Fail Obj Long Enough', 'Fail Spec Long Enough', 'IT', 'Valid substantive rationale for testing failure.', 'None', 'draft', 'draft', 0, NOW(), NOW()
    )
")->execute([$facultyA['id'], $failTitle, $tomorrow]);
$failActId = (int)$db->lastInsertId();

// Add collaborator with a bad/invalid email simulation
$badUserEmail = "simulated_failing_email_" . time() . "@invalid-domain-does-not-exist.local";
$db->prepare("INSERT INTO users (name, email, password, role, department, must_change_password) VALUES ('Bad Email Faculty', ?, ?, 'faculty', 'IT Department', 0)")->execute([$badUserEmail, $pwdHash]);
$badUserId = (int)$db->lastInsertId();

$resAddBad = addProposalCollaborator($failActId, $badUserId, 'contributor', (int)$facultyA['id'], $db);
assertCheck($resAddBad['success'] === true, "Requirement 17a: Proposal collaborator added successfully even when email delivery fails.");

// Verify collaborator was persisted in database
$collabRow = $db->query("SELECT * FROM proposal_collaborators WHERE activity_id = {$failActId} AND user_id = {$badUserId}")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($collabRow) && $collabRow['status'] === 'active', "Requirement 17b: Database record preserved despite email transmission status.");

// Verify in-system notification exists
$notifBad = $db->query("SELECT * FROM notifications WHERE activity_id = {$failActId} AND user_id = {$badUserId}")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($notifBad), "Requirement 17c: In-system notification successfully delivered despite email delivery failure.");

// ── TEST 18: Notification Link Authorization Security ─────────────────────────
echo "\n--- TEST 18: Notification Link Authorization Security ---\n";
// Authorized collaborator (Faculty B) opens proposal view
$resAuthView = makeRequest("{$baseUrl}/faculty/proposal-view.php?id={$testActId}", 'GET', [], $cookieB);
assertCheck($resAuthView['code'] === 200, "Requirement 18a: Active collaborator Faculty B allowed to view shared proposal (HTTP 200).");

// Unrelated faculty member tries to open proposal view
$resUnauthView = makeRequest("{$baseUrl}/faculty/proposal-view.php?id={$testActId}", 'GET', [], $cookieUnrelated);
assertCheck($resUnauthView['code'] === 403 || str_contains($resUnauthView['body'], 'Unauthorized access'), "Requirement 18b: Unrelated faculty access blocked with HTTP 403 / Unauthorized Access.");

// Non-owner (Faculty B) tries to submit proposal
$resIllegalSubmit = makeRequest("{$baseUrl}/api/proposal-update.php", 'POST', [
    'activity_id'  => $testActId,
    'action'       => 'submit',
    'is_ajax_sync' => '1',
    'title'        => $testTitle
], $cookieB, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
assertCheck($resIllegalSubmit['code'] === 403 || str_contains(strtolower($resIllegalSubmit['body']), 'only the proposal owner'), "Requirement 18c: Contributor submission strictly blocked (Owner-only submission enforced).");

// ── TEST 19: Collaborator Removal Notifications ───────────────────────────────
echo "\n--- TEST 19: Collaborator Removal Notifications ---\n";
// Faculty A removes Faculty C from the test proposal
// First reset proposal back to draft for collaborator management
$db->prepare("UPDATE activities SET status = 'draft' WHERE id = {$testActId}")->execute();

$resRemoveC = makeRequest("{$baseUrl}/api/collaborators.php", 'POST', [
    'action'      => 'remove',
    'activity_id' => $testActId,
    'user_id'     => $facultyC['id']
], $cookieA);

$dataRemoveC = json_decode($resRemoveC['body'], true);
assertCheck($resRemoveC['code'] === 200 && !empty($dataRemoveC['success']), "Requirement 19a: Faculty A removed Faculty C from collaborators.");

// Check removed user (Faculty C) received removal notification in-system + email
$notifC_rem = $db->query("SELECT * FROM notifications WHERE activity_id = {$testActId} AND user_id = {$facultyC['id']} AND message LIKE '%removed%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($notifC_rem), "Requirement 19b: Removed collaborator Faculty C received in-system removal notification.");

$emailC_rem = $db->query("SELECT * FROM email_logs WHERE activity_id = {$testActId} AND recipient_email = '{$facultyC['email']}' AND notification_type LIKE 'collab_removed%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($emailC_rem), "Requirement 19c: Removed collaborator Faculty C received removal email notification.");

// Check remaining collaborator (Faculty B) received team update notification
$notifB_rem = $db->query("SELECT * FROM notifications WHERE activity_id = {$testActId} AND user_id = {$facultyB['id']} AND message LIKE '%removed {$facultyC['name']}%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($notifB_rem), "Requirement 19d: Remaining collaborator Faculty B received team update in-system notification.");

$emailB_rem = $db->query("SELECT * FROM email_logs WHERE activity_id = {$testActId} AND recipient_email = '{$facultyB['email']}' AND notification_type LIKE 'collab_team_rem%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($emailB_rem), "Requirement 19e: Remaining collaborator Faculty B received team update email notification.");

// Check Faculty A received NO self-notification for removal
$selfNotifRem = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE activity_id = {$testActId} AND user_id = {$facultyA['id']} AND message LIKE '%removed%'")->fetchColumn();
assertCheck($selfNotifRem === 0, "Requirement 19f: Faculty A received 0 self-notifications upon removing collaborator.");

// Clean up test data safely
$db->prepare("DELETE FROM kpi_evaluations WHERE activity_id IN (?, ?)")->execute([$testActId, $failActId]);
$db->prepare("DELETE FROM floor_plans WHERE activity_id IN (?, ?)")->execute([$testActId, $failActId]);
$db->prepare("DELETE FROM documents WHERE activity_id IN (?, ?)")->execute([$testActId, $failActId]);
$db->prepare("DELETE FROM notifications WHERE activity_id IN (?, ?)")->execute([$testActId, $failActId]);
$db->prepare("DELETE FROM email_logs WHERE activity_id IN (?, ?)")->execute([$testActId, $failActId]);
$db->prepare("DELETE FROM proposal_collaborators WHERE activity_id IN (?, ?)")->execute([$testActId, $failActId]);
$db->prepare("DELETE FROM proposal_contribution_logs WHERE activity_id IN (?, ?)")->execute([$testActId, $failActId]);
$db->prepare("DELETE FROM activities WHERE id IN (?, ?)")->execute([$testActId, $failActId]);
$db->prepare("DELETE FROM users WHERE id = ?")->execute([$badUserId]);

echo "\n======================================================================\n";
echo "  AUDIT RESULTS: {$passCount} PASSED | {$failCount} FAILED\n";
echo "======================================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
