<?php
require 'includes/EmailService.php';

$emailService = new EmailService();

// We need to access PHPMailer instance directly to enable debug mode.
// Since it's private, we'll just create a new PHPMailer instance here with the config.

require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

$config = getMailConfig();
$mail = new PHPMailer(true);
try {
    $mail->SMTPDebug = SMTP::DEBUG_SERVER;
    $mail->isSMTP();
    $mail->Host       = $config['SMTP_HOST'];
    
    // Check if OAuth is configured
    if (!empty($config['OAUTH_REFRESH_TOKEN'])) {
        $mail->SMTPAuth = true;
        $mail->AuthType = 'XOAUTH2';
        
        $provider = new \Greew\OAuth2\Client\Provider\Azure([
            'clientId'     => $config['OAUTH_CLIENT_ID'],
            'clientSecret' => $config['OAUTH_CLIENT_SECRET'],
            'tenantId'     => $config['OAUTH_TENANT_ID'] ?: 'common',
        ]);
        
        $mail->setOAuth(
            new \PHPMailer\PHPMailer\OAuth(
                [
                    'provider'     => $provider,
                    'clientId'     => $config['OAUTH_CLIENT_ID'],
                    'clientSecret' => $config['OAUTH_CLIENT_SECRET'],
                    'refreshToken' => $config['OAUTH_REFRESH_TOKEN'],
                    'userName'     => $config['SMTP_USERNAME'],
                ]
            )
        );
    } else {
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['SMTP_USERNAME'];
        $mail->Password   = $config['SMTP_PASSWORD'];
    }
    $mail->SMTPSecure = $config['SMTP_ENCRYPTION'];
    $mail->Port       = $config['SMTP_PORT'];

    $mail->setFrom($config['FROM_EMAIL'], $config['FROM_NAME']);
    $mail->addAddress('sti.activity.system@outlook.com');
    $mail->Subject = 'Debug test';
    $mail->Body = 'Debug test body';
    $mail->send();
    echo "Sent!";
} catch (Exception $e) {
    echo "Error: {$mail->ErrorInfo}";
}
