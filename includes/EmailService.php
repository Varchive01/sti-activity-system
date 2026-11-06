<?php
// includes/EmailService.php
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}
require_once __DIR__ . '/../config/mail_config.php';
require_once __DIR__ . '/../config/database.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService {
    private $mail = null;
    private $config;
    private $db;

    public function __construct() {
        $this->config = getMailConfig();
        $this->db = getDB();
        
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            $this->mail = new PHPMailer(true);
            $this->setupMailer();
        }
    }

    private function setupMailer() {
        if (!$this->mail) {
            return;
        }
        // If we are using OAuth, we use the Microsoft Graph API, so we skip PHPMailer setup
        if (!empty($this->config['OAUTH_REFRESH_TOKEN'])) {
            return; 
        }
        // Skip SMTP setup if credentials are not configured
        if (empty($this->config['SMTP_USERNAME']) || empty($this->config['FROM_EMAIL'])) {
            return;
        }
        
        try {
            // Server settings
            $this->mail->isSMTP();
            $this->mail->Host       = $this->config['SMTP_HOST'];
            $this->mail->SMTPAuth   = true;
            $this->mail->Username   = $this->config['SMTP_USERNAME'];
            $this->mail->Password   = $this->config['SMTP_PASSWORD'];
            $this->mail->SMTPSecure = $this->config['SMTP_ENCRYPTION'];
            $this->mail->Port       = $this->config['SMTP_PORT'];
            $this->mail->Timeout    = 3;

            // Recipients
            $this->mail->setFrom($this->config['FROM_EMAIL'], $this->config['FROM_NAME']);
            
            // Content setup
            $this->mail->isHTML(true);
            $this->mail->CharSet = 'UTF-8';
        } catch (\Throwable $e) {
            error_log("Mailer Setup Error: " . $e->getMessage());
        }
    }

    /**
     * Send an email notification based on the HTML template
     */
    public function sendNotification($recipientEmail, $recipientName, $subject, $proposalTitle, $status, $message, $actionLink, $activityId = null, $notificationType = 'general') {
        
        // Basic deduplication check (fault-tolerant if email_logs table is absent)
        if ($activityId && $notificationType !== 'general') {
            try {
                $stmt = $this->db->prepare("SELECT id FROM email_logs WHERE activity_id = ? AND recipient_email = ? AND notification_type = ? AND status = 'success' LIMIT 1");
                $stmt->execute([$activityId, $recipientEmail, $notificationType]);
                if ($stmt->fetch()) {
                    return true;
                }
            } catch (\Throwable $e) {
                error_log("Email deduplication check skipped: " . $e->getMessage());
            }
        }

        // Load template
        $templatePath = __DIR__ . '/email_templates/notification.html';
        if (file_exists($templatePath)) {
            $body = file_get_contents($templatePath);
            
            // Replace placeholders
            $body = str_replace('{{RECIPIENT_NAME}}', htmlspecialchars($recipientName), $body);
            $body = str_replace('{{PROPOSAL_TITLE}}', htmlspecialchars($proposalTitle), $body);
            $body = str_replace('{{STATUS}}', htmlspecialchars($status), $body);
            $body = str_replace('{{DATE_TIME}}', date('F j, Y g:i A'), $body);
            $body = str_replace('{{MESSAGE}}', nl2br(htmlspecialchars($message)), $body);
            $body = str_replace('{{ACTION_LINK}}', $actionLink, $body);
            
            $altBody = strip_tags(str_replace(['<br>', '<br/>'], "\n", $body));
        } else {
            $body = "Hello $recipientName,<br><br>Proposal: $proposalTitle<br>Status: $status<br><br>$message<br><br>Link: $actionLink";
            $altBody = strip_tags(str_replace(['<br>', '<br/>'], "\n", $body));
        }

        if (!empty($this->config['OAUTH_REFRESH_TOKEN'])) {
            return $this->sendViaGraphAPI($recipientEmail, $recipientName, $subject, $body, $activityId, $notificationType);
        } else {
            return $this->sendViaPHPMailer($recipientEmail, $recipientName, $subject, $body, $altBody, $activityId, $notificationType);
        }
    }

    private function sendViaGraphAPI($recipientEmail, $recipientName, $subject, $body, $activityId, $notificationType) {
        try {
            $verifyCa = true;
            if (file_exists('C:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem')) {
                $verifyCa = 'C:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem';
            } elseif (file_exists('C:/xampp/perl/vendor/lib/Mozilla/CA/cacert.pem')) {
                $verifyCa = 'C:/xampp/perl/vendor/lib/Mozilla/CA/cacert.pem';
            }

            $guzzle = new \GuzzleHttp\Client(['verify' => $verifyCa]);

            $provider = new \Greew\OAuth2\Client\Provider\Azure([
                'clientId'     => $this->config['OAUTH_CLIENT_ID'],
                'clientSecret' => $this->config['OAUTH_CLIENT_SECRET'],
                'tenantId'     => $this->config['OAUTH_TENANT_ID'] ?: 'common',
            ], ['httpClient' => $guzzle]);
            
            $grant = new \League\OAuth2\Client\Grant\RefreshToken();
            $token = $provider->getAccessToken($grant, [
                'refresh_token' => $this->config['OAUTH_REFRESH_TOKEN'],
                'scope' => 'https://graph.microsoft.com/Mail.Send offline_access'
            ]);
            $accessToken = $token->getToken();

            // When sending from a specific user (the app creator), we use /me/sendMail or /users/{email}/sendMail
            // Using /me assumes the token was delegated by the user and we act on their behalf
            $graphUrl = 'https://graph.microsoft.com/v1.0/me/sendMail';
            
            $payload = [
                'message' => [
                    'subject' => $subject,
                    'body' => [
                        'contentType' => 'HTML',
                        'content' => $body
                    ],
                    'toRecipients' => [
                        [
                            'emailAddress' => [
                                'address' => $recipientEmail,
                                'name' => $recipientName
                            ]
                        ]
                    ]
                ],
                'saveToSentItems' => 'true'
            ];

            $client = new \GuzzleHttp\Client(['verify' => $verifyCa]);
            $response = $client->post($graphUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json'
                ],
                'json' => $payload
            ]);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $this->logEmail($activityId, $recipientEmail, $notificationType, 'success', null);
                return true;
            } else {
                throw new Exception("Graph API returned status code " . $response->getStatusCode());
            }

        } catch (\Throwable $e) {
            error_log("Graph API Sending Failed to $recipientEmail: " . $e->getMessage());
            $this->logEmail($activityId, $recipientEmail, $notificationType, 'failed', $e->getMessage());
            return false;
        }
    }

    private function sendViaPHPMailer($recipientEmail, $recipientName, $subject, $body, $altBody, $activityId, $notificationType) {
        if (!$this->mail || empty($this->config['SMTP_USERNAME']) || empty($this->config['FROM_EMAIL'])) {
            return false;
        }
        try {
            $this->mail->clearAddresses();
            $this->mail->addAddress($recipientEmail, $recipientName);
            
            $this->mail->Subject = $subject;
            $this->mail->Body = $body;
            $this->mail->AltBody = $altBody;

            $this->mail->send();
            
            // Log success
            $this->logEmail($activityId, $recipientEmail, $notificationType, 'success', null);
            return true;
            
        } catch (\Throwable $e) {
            // Log failure
            $errorMsg = $this->mail ? $this->mail->ErrorInfo : $e->getMessage();
            error_log("Mail Sending Failed to $recipientEmail: $errorMsg");
            $this->logEmail($activityId, $recipientEmail, $notificationType, 'failed', $errorMsg);
            
            return false;
        }
    }

    private function logEmail($activityId, $recipientEmail, $notificationType, $status, $errorMessage) {
        try {
            $stmt = $this->db->prepare("INSERT INTO email_logs (activity_id, recipient_email, notification_type, status, error_message) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $activityId,
                $recipientEmail,
                $notificationType,
                $status,
                $errorMessage
            ]);
        } catch (\Throwable $e) {
            error_log("Failed to insert email log: " . $e->getMessage());
        }
    }
}
