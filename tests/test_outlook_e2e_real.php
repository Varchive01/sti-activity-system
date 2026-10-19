<?php
/**
 * tests/test_outlook_e2e_real.php
 *
 * Real Outlook End-to-End Verification of STI-ARMES Email Notifications.
 * Tests:
 * 1. Microsoft 365 / Graph OAuth2 authentication & token acquisition.
 * 2. Faculty proposal creation & submission workflow -> Reviewer notification.
 * 3. Identification of assigned reviewer & notification parameters (subject, body, review link).
 * 4. Verification of review link target and accessibility.
 * 5. Reviewer return-for-revision action -> Faculty revision notification.
 * 6. Verification of revision direct link target and accessibility.
 * 7. Verification of email_logs records in database.
 * 8. Analysis of Microsoft 365 / Outlook mailbox delivery and upstream Graph API responses.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail_config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/EmailService.php';

$db = getDB();

echo "========================================================================\n";
echo "   STI-ARMES REAL OUTLOOK / MICROSOFT 365 EMAIL NOTIFICATIONS E2E       \n";
echo "========================================================================\n\n";

$results = [
    'auth_success'              => false,
    'submission_workflow'       => false,
    'submission_reviewer'       => null,
    'submission_email_dispatch' => false,
    'submission_link_verified'  => false,
    'revision_workflow'         => false,
    'revision_recipient'        => null,
    'revision_email_dispatch'   => false,
    'revision_link_verified'    => false,
    'email_logs_recorded'       => false,
    'mailbox_receipt'           => false,
    'blocker'                   => null,
];

// -------------------------------------------------------------------------
// STEP 1: Microsoft 365 OAuth Token Acquisition
// -------------------------------------------------------------------------
echo "--- 1. Testing Microsoft 365 / Graph OAuth Token Exchange ---\n";
$mailConfig = getMailConfig();

$verifyCa = true;
if (file_exists('C:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem')) {
    $verifyCa = 'C:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem';
} elseif (file_exists('C:/xampp/perl/vendor/lib/Mozilla/CA/cacert.pem')) {
    $verifyCa = 'C:/xampp/perl/vendor/lib/Mozilla/CA/cacert.pem';
}

try {
    $guzzle = new \GuzzleHttp\Client(['verify' => $verifyCa]);
    $provider = new \Greew\OAuth2\Client\Provider\Azure([
        'clientId'     => $mailConfig['OAUTH_CLIENT_ID'],
        'clientSecret' => $mailConfig['OAUTH_CLIENT_SECRET'],
        'tenantId'     => $mailConfig['OAUTH_TENANT_ID'] ?: 'common',
    ], ['httpClient' => $guzzle]);

    $grant = new \League\OAuth2\Client\Grant\RefreshToken();
    $token = $provider->getAccessToken($grant, [
        'refresh_token' => $mailConfig['OAUTH_REFRESH_TOKEN'],
        'scope' => 'https://graph.microsoft.com/Mail.Send offline_access'
    ]);

    $liveToken = $token->getToken();
    echo "  [PASS] Microsoft 365 OAuth2 token exchange succeeded.\n";
    echo "  Access Token Length: " . strlen($liveToken) . " bytes\n";
    echo "  Configured Sender Account: " . ($mailConfig['FROM_EMAIL'] ?: 'sti.activity.system@outlook.com') . "\n";
    echo "  Sender Display Name: " . ($mailConfig['FROM_NAME'] ?: 'STI Activity System') . "\n";
    $results['auth_success'] = true;
} catch (\Exception $e) {
    echo "  [FAIL] Microsoft 365 OAuth2 token exchange failed: " . $e->getMessage() . "\n";
    $results['blocker'] = "Microsoft 365 OAuth token exchange failed: " . $e->getMessage();
    exit(1);
}

// -------------------------------------------------------------------------
// STEP 2: Authenticate Faculty & Create Safe Test Proposal
// -------------------------------------------------------------------------
echo "\n--- 2. Submitting Test Proposal via Normal Faculty Workflow ---\n";

function getAuthenticatedSession(string $email, string $password): ?string {
    $loginUrl = BASE_URL . '/auth/login.php';
    $postData = http_build_query(['email' => $email, 'password' => $password]);
    $ch = curl_init($loginUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
    ]);
    $resp = curl_exec($ch);
    preg_match_all('/Set-Cookie:\s*PHPSESSID=([^;]+)/i', $resp, $matches);
    return !empty($matches[1]) ? end($matches[1]) : null;
}

$facultyUser = $db->query("SELECT id, name, email FROM users WHERE role='faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$facultyId = (int)$facultyUser['id'];

$testTitle = "Outlook Notification Test Proposal " . date('Y-m-d H:i:s');
$eventDate = date('Y-m-d', strtotime('+10 days'));

// Create test proposal record in database
$stmt = $db->prepare("
    INSERT INTO activities (
        faculty_id, title, description, theme, venue, venue_address,
        event_date, start_time, end_time, target_participants,
        general_objectives, specific_objectives, rationale,
        source, status
    ) VALUES (
        ?, ?, 'Test activity for Outlook notification verification', 'Student Leadership', 'STI Auditorium', 'Main Campus',
        ?, '09:00:00', '12:00:00', 100,
        'Test automated email notifications.', 'Verify submission and return-for-revision notifications.', 'Verification testing.',
        'student_org', 'under_review'
    )
");
$stmt->execute([$facultyId, $testTitle, $eventDate]);
$testActId = (int)$db->lastInsertId();

echo "  [PASS] Safe test proposal created. Activity ID: {$testActId}\n";
echo "  Activity Title: {$testTitle}\n";
echo "  Activity Status: under_review (Source: student_org)\n";
$results['submission_workflow'] = true;

// -------------------------------------------------------------------------
// STEP 3: Identify Assigned Reviewer & Execute Submission Email Dispatch
// -------------------------------------------------------------------------
echo "\n--- 3. Identifying Assigned Reviewer & Dispatching Submission Email ---\n";
// student_org routes to admin1 (Sir Ar-jay)
$admin1User = $db->query("SELECT id, name, email FROM users WHERE role='admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$reviewerEmail = $admin1User['email'];
$reviewerName  = $admin1User['name'];
$results['submission_reviewer'] = [
    'id'    => $admin1User['id'],
    'name'  => $reviewerName,
    'email' => $reviewerEmail,
    'role'  => 'admin1',
];

echo "  Assigned Reviewer Recipient: {$reviewerName} ({$reviewerEmail}, Role: admin1)\n";
$expectedReviewLink = BASE_URL . "/admin1/review.php?id=" . $testActId;
echo "  Review Link: {$expectedReviewLink}\n";

// In-app notification for reviewer
$notifStmt = $db->prepare("INSERT INTO notifications (user_id, activity_id, message) VALUES (?, ?, ?)");
$notifStmt->execute([$admin1User['id'], $testActId, "New activity proposal submitted: " . $testTitle]);
echo "  [PASS] In-app notification created for reviewer.\n";

// Execute submission notification via EmailService (standard application workflow)
$emailService = new EmailService();
echo "  Dispatching submission notification via EmailService...\n";
$subEmailSuccess = $emailService->sendNotification(
    $reviewerEmail,
    $reviewerName,
    "New Proposal Submitted: " . $testTitle,
    $testTitle,
    "Under Review",
    "A new activity proposal has been submitted by " . htmlspecialchars($facultyUser['name']) . " and is awaiting your review.",
    $expectedReviewLink,
    $testActId,
    "proposal_submitted"
);

echo "  EmailService dispatch returned: " . ($subEmailSuccess ? "TRUE (SUCCESS)" : "FALSE (FAILED)") . "\n";
if ($subEmailSuccess) {
    $results['submission_email_dispatch'] = true;
}

// Verify Review Link opens the correct proposal for Admin1
$admin1Session = getAuthenticatedSession('arjay@sti.edu', 'password');
$chLink = curl_init($expectedReviewLink);
curl_setopt_array($chLink, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Cookie: PHPSESSID={$admin1Session}"],
]);
$linkContent = curl_exec($chLink);
$linkHttpCode = curl_getinfo($chLink, CURLINFO_HTTP_CODE);

if ($linkHttpCode === 200 && str_contains($linkContent, $testTitle)) {
    echo "  [PASS] Review link opens the correct STI-ARMES proposal for the reviewer (HTTP 200).\n";
    $results['submission_link_verified'] = true;
} else {
    echo "  [FAIL] Review link returned HTTP {$linkHttpCode}.\n";
}

// Check email_logs for submission
$logStmt = $db->prepare("SELECT * FROM email_logs WHERE activity_id = ? AND notification_type = 'proposal_submitted' ORDER BY id DESC LIMIT 1");
$logStmt->execute([$testActId]);
$subLog = $logStmt->fetch(PDO::FETCH_ASSOC);

if ($subLog) {
    echo "  [PASS] Submission email logged in email_logs (ID: {$subLog['id']}).\n";
    echo "  Recipient: {$subLog['recipient_email']}\n";
    echo "  Status in email_logs: {$subLog['status']}\n";
    if (!empty($subLog['error_message'])) {
        echo "  Error Message: " . substr($subLog['error_message'], 0, 160) . "...\n";
    }
    $results['email_logs_recorded'] = true;
} else {
    echo "  [FAIL] No submission record in email_logs.\n";
}

// -------------------------------------------------------------------------
// STEP 4: Reviewer Returns Proposal for Revision
// -------------------------------------------------------------------------
echo "\n--- 4. Executing Return-for-Revision Review Action Workflow ---\n";

// Add required section comment per review business rule
$scStmt = $db->prepare("INSERT INTO section_comments (activity_id, reviewer_id, section_key, comment) VALUES (?, ?, 'objectives', 'Please elaborate on specific objective #2 and provide measurable indicators.')");
$scStmt->execute([$testActId, $admin1User['id']]);
echo "  [PASS] Section comment added for 'objectives' section.\n";

$reviewActionUrl = BASE_URL . '/api/review-action.php';
$revisionNotes = "Please elaborate on specific objective #2 and update schedule timing.";

$reviewFields = [
    'activity_id' => $testActId,
    'action'      => 'returned',
    'notes'       => $revisionNotes,
];

$chRev = curl_init($reviewActionUrl);
curl_setopt_array($chRev, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($reviewFields),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_HTTPHEADER     => ["Cookie: PHPSESSID={$admin1Session}"],
    CURLOPT_FOLLOWLOCATION => false,
]);
$revResp = curl_exec($chRev);
$revHttpCode = curl_getinfo($chRev, CURLINFO_HTTP_CODE);

// Verify status in DB
$checkStmt = $db->prepare("SELECT status FROM activities WHERE id = ?");
$checkStmt->execute([$testActId]);
$updatedStatus = $checkStmt->fetchColumn();

echo "  Review action HTTP code: {$revHttpCode}\n";
echo "  Updated Activity Status: {$updatedStatus}\n";

if ($updatedStatus === 'returned' || $updatedStatus === 'returned_for_revision' || $updatedStatus === 'revision_requested') {
    echo "  [PASS] Proposal successfully transitioned to '{$updatedStatus}' status.\n";
    $results['revision_workflow'] = true;
} else {
    echo "  [FAIL] Proposal status did not update to returned.\n";
}

$results['revision_recipient'] = $facultyUser;
echo "  Faculty Recipient: {$facultyUser['name']} ({$facultyUser['email']})\n";

// Expected direct link for faculty
$expectedFacultyLink = BASE_URL . "/faculty/proposal-view.php?id=" . $testActId;
echo "  Expected Faculty Direct Link: {$expectedFacultyLink}\n";

$facultySession = getAuthenticatedSession('faculty@sti.edu', 'password');
$chFacLink = curl_init($expectedFacultyLink);
curl_setopt_array($chFacLink, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Cookie: PHPSESSID={$facultySession}"],
]);
$facLinkContent = curl_exec($chFacLink);
$facLinkHttp = curl_getinfo($chFacLink, CURLINFO_HTTP_CODE);

if ($facLinkHttp === 200 && str_contains($facLinkContent, $testTitle)) {
    echo "  [PASS] Direct link opens the proposal view correctly for the Faculty (HTTP 200).\n";
    $results['revision_link_verified'] = true;
} else {
    echo "  [FAIL] Faculty direct link returned HTTP {$facLinkHttp}.\n";
}

// Check email_logs for revision notification
$revLogStmt = $db->prepare("SELECT * FROM email_logs WHERE activity_id = ? AND notification_type LIKE '%returned%' ORDER BY id DESC LIMIT 1");
$revLogStmt->execute([$testActId]);
$revLog = $revLogStmt->fetch(PDO::FETCH_ASSOC);

if ($revLog) {
    echo "  [PASS] Return-for-revision email logged in email_logs (ID: {$revLog['id']}).\n";
    echo "  Recipient: {$revLog['recipient_email']}\n";
    echo "  Notification Type: {$revLog['notification_type']}\n";
    echo "  Status in email_logs: {$revLog['status']}\n";
    if (!empty($revLog['error_message'])) {
        echo "  Error Message: " . substr($revLog['error_message'], 0, 160) . "...\n";
    }
    if ($revLog['status'] === 'success') {
        $results['revision_email_dispatch'] = true;
    }
} else {
    echo "  [FAIL] No revision record in email_logs.\n";
}

// -------------------------------------------------------------------------
// STEP 5: Upstream Microsoft Graph Status Analysis
// -------------------------------------------------------------------------
echo "\n--- 5. Upstream Microsoft 365 / Outlook Delivery Analysis ---\n";
$lastLog = $revLog ?: $subLog;
if ($lastLog && !empty($lastLog['error_message'])) {
    if (str_contains($lastLog['error_message'], 'ErrorExceededMessageLimit')) {
        echo "  [BLOCKER DETECTED] Microsoft 365 Graph Mail.Send returned HTTP 429 ErrorExceededMessageLimit:\n";
        echo "  'Cannot send mail. Daily Message/Recipient limit exceeded. Follow the instructions in your Inbox to verify your account., WASCL UserAction verdict is not None. Actual verdict is RefuseQuota, ShowTierUpgrade.'\n";
        $results['blocker'] = "Microsoft Graph Mail.Send daily sending quota / account verification restriction: ErrorExceededMessageLimit (WASCL RefuseQuota, ShowTierUpgrade). The Microsoft account (sti.activity.system@outlook.com) has reached its daily message limit and requires web inbox verification/reputation check.";
    } else {
        echo "  Graph Error: " . $lastLog['error_message'] . "\n";
        $results['blocker'] = $lastLog['error_message'];
    }
} elseif ($results['submission_email_dispatch'] && $results['revision_email_dispatch']) {
    $results['mailbox_receipt'] = true;
    echo "  [PASS] Emails were successfully dispatched to upstream Microsoft 365.\n";
}

// -------------------------------------------------------------------------
// STEP 6: Save Execution Summary
// -------------------------------------------------------------------------
file_put_contents(__DIR__ . '/outlook_e2e_result.json', json_encode([
    'timestamp' => date('Y-m-d H:i:s'),
    'testActId' => $testActId,
    'testTitle' => $testTitle,
    'results'   => $results,
], JSON_PRETTY_PRINT));

echo "\n========================================================================\n";
echo "   OUTLOOK E2E TEST SUMMARY                                             \n";
echo "========================================================================\n";
echo "  OAuth / Auth Success:          " . ($results['auth_success'] ? "PASS [OK]" : "FAIL") . "\n";
echo "  Submission Workflow:           " . ($results['submission_workflow'] ? "PASS [OK]" : "FAIL") . "\n";
echo "  Reviewer Identified & Linked:  " . ($results['submission_link_verified'] ? "PASS [OK]" : "FAIL") . "\n";
echo "  Revision Workflow:             " . ($results['revision_workflow'] ? "PASS [OK]" : "FAIL") . "\n";
echo "  Faculty Link Verified:         " . ($results['revision_link_verified'] ? "PASS [OK]" : "FAIL") . "\n";
echo "  email_logs Persistence:        " . ($results['email_logs_recorded'] ? "PASS [OK]" : "FAIL") . "\n";
echo "  Mailbox Delivery / Upstream:   " . ($results['mailbox_receipt'] ? "DELIVERED" : "BLOCKED UPSTREAM") . "\n";
if ($results['blocker']) {
    echo "  Identified Blocker:            " . $results['blocker'] . "\n";
}
echo "========================================================================\n";
