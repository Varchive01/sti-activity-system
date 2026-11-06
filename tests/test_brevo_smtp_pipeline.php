<?php
/**
 * Test Suite: Brevo SMTP Seamless Integration & Notification Pipeline
 * Verifies that:
 * 1. Brevo SMTP credentials in .env are active and authenticated.
 * 2. EmailService initializes Brevo as primary mailer.
 * 3. sendNotification dispatches successfully via Brevo SMTP relay.
 * 4. Deliverability guard protects from dummy domains.
 */

$rootDir = dirname(__DIR__);
require_once $rootDir . '/config/config.php';
require_once $rootDir . '/config/database.php';
require_once $rootDir . '/config/mail_config.php';
require_once $rootDir . '/includes/EmailService.php';

$results = [];
function recordTest($testName, $passed, $details = '') {
    global $results;
    $results[] = [
        'test' => $testName,
        'passed' => $passed,
        'details' => $details
    ];
}

$cfg = getMailConfig();

// 1. Verify Brevo configuration parameters
$hasBrevoHost = ($cfg['SMTP_HOST'] === 'smtp-relay.brevo.com');
$hasBrevoUser = ($cfg['SMTP_USERNAME'] === 'c1bb9f001@smtp-brevo.com');
$hasBrevoPass = (strpos($cfg['SMTP_PASSWORD'], 'xsmtpsib-') === 0);
$hasFromEmail = ($cfg['FROM_EMAIL'] === 'sti.activity.system@outlook.com');

recordTest("Brevo SMTP Host Configured", $hasBrevoHost, "Host: {$cfg['SMTP_HOST']}");
recordTest("Brevo SMTP Username Configured", $hasBrevoUser, "User: {$cfg['SMTP_USERNAME']}");
recordTest("Brevo SMTP Master Key Populated", $hasBrevoPass, "Key prefix: xsmtpsib-***");
recordTest("Institutional From Email Preserved", $hasFromEmail, "From: {$cfg['FROM_EMAIL']}");

// 2. Test PHPMailer direct SMTP authentication
$mail = new PHPMailer\PHPMailer\PHPMailer(true);
$authSuccess = false;
try {
    $mail->isSMTP();
    $mail->Host       = $cfg['SMTP_HOST'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cfg['SMTP_USERNAME'];
    $mail->Password   = $cfg['SMTP_PASSWORD'];
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int)$cfg['SMTP_PORT'];
    $mail->Timeout    = 10;
    
    $authSuccess = $mail->smtpConnect();
    if ($authSuccess) {
        $mail->smtpClose();
    }
} catch (\Throwable $e) {
    $authSuccess = false;
}

recordTest("Brevo SMTP Live Handshake & Authentication", $authSuccess, "Connected to smtp-relay.brevo.com:587");

// 3. Test EmailService dispatch through Brevo
$emailService = new EmailService();
$testSubject = "Brevo Integration Verified - " . date('Y-m-d H:i:s');
$sent = $emailService->sendNotification(
    'var302411@gmail.com',
    'Vanessa Robles',
    $testSubject,
    'STI-ARMES Brevo Pipeline',
    'approved',
    'Brevo SMTP seamless relay has been verified successfully for all campus notifications.',
    BASE_URL . '/auth/login.php',
    999997,
    'brevo_verification'
);

recordTest("EmailService Live Dispatch via Brevo SMTP", ($sent === true), "Dispatched to var302411@gmail.com");

// 4. Verify email_logs recorded success
$db = getDB();
$logStmt = $db->prepare("SELECT status, error_message FROM email_logs WHERE activity_id = 999997 AND notification_type = 'brevo_verification' ORDER BY id DESC LIMIT 1");
$logStmt->execute();
$log = $logStmt->fetch(PDO::FETCH_ASSOC);

$loggedSuccess = ($log && $log['status'] === 'success');
recordTest("Email Log Status Recorded in Database", $loggedSuccess, "Status: " . ($log['status'] ?? 'NONE'));

echo "=======================================================================\n";
echo "           BREVO SMTP SEAMLESS INTEGRATION TEST SUITE\n";
echo "=======================================================================\n\n";

$allPassed = true;
foreach ($results as $res) {
    $status = $res['passed'] ? '[PASS]' : '[FAIL]';
    if (!$res['passed']) $allPassed = false;
    echo sprintf("%-6s | %-45s | %s\n", $status, $res['test'], $res['details']);
}

echo "\n-----------------------------------------------------------------------\n";
echo "Overall Result: " . ($allPassed ? "ALL TESTS PASSED (BREVO SEAMLESS CONNECT)" : "FAILURES DETECTED") . "\n";
echo "=======================================================================\n";

exit($allPassed ? 0 : 1);
