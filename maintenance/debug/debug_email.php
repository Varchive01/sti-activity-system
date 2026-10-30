<?php
require 'includes/EmailService.php';

echo "Sending test email via EmailService...<br><br>";

$emailService = new EmailService();

// We will send an email to ourselves to test
$recipient = 'sti.activity.system@outlook.com';
$name = 'Admin';
$subject = 'Test from Graph API';
$proposalTitle = 'Microsoft Graph Test';
$status = 'Testing';
$message = 'This is a test email sent using the Microsoft Graph API. If you are reading this, it works!';
$actionLink = 'http://localhost/sti-activity-system/';

$success = $emailService->sendNotification($recipient, $name, $subject, $proposalTitle, $status, $message, $actionLink);

if ($success) {
    echo "<strong style='color:green'>Email successfully sent! Microsoft Graph API is working.</strong><br>";
} else {
    echo "<strong style='color:red'>Failed to send email. Check the PHP error log for details.</strong><br>";
}
