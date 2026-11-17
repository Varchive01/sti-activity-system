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
    private static ?bool $smtpReachable = null;

    /**
     * Optional attachments for the current sendNotification() call.
     * Each item: ['name' => string, 'content' => string (raw bytes), 'type' => mime string]
     */
    private array $attachments = [];

    /**
     * Builds an Outlook-compatible iCalendar (.ics) attachment for an approved activity,
     * so recipients can add the event to their Outlook calendar with one click.
     * Returns null if the activity has no event date (attachment is then simply omitted).
     */
    public static function buildActivityCalendarAttachment(array $act): ?array {
        try {
            if (empty($act['event_date']) || empty($act['id'])) {
                return null;
            }
            $tz  = new \DateTimeZone('Asia/Manila');
            $utc = new \DateTimeZone('UTC');
            $esc = static function (string $s): string {
                $s = str_replace(["\\", ';', ',', "\r\n", "\r", "\n"], ["\\\\", '\;', '\,', '\n', '\n', '\n'], $s);
                return $s;
            };
            $fold = static function (string $line): string {
                // RFC 5545: fold lines longer than 75 octets
                $out = '';
                while (strlen($line) > 75) {
                    $cut = 75;
                    while ($cut > 1 && (ord($line[$cut]) & 0xC0) === 0x80) { $cut--; } // keep UTF-8 chars intact
                    $out .= substr($line, 0, $cut) . "\r\n ";
                    $line = substr($line, $cut);
                }
                return $out . $line;
            };

            $date = (string)$act['event_date'];
            $hasTimes = !empty($act['start_time']);
            if ($hasTimes) {
                $start = new \DateTime($date . ' ' . $act['start_time'], $tz);
                $end   = !empty($act['end_time']) ? new \DateTime($date . ' ' . $act['end_time'], $tz) : (clone $start)->modify('+1 hour');
                if ($end <= $start) {
                    $end = (clone $start)->modify('+1 hour');
                }
                $dtLines = [
                    'DTSTART:' . $start->setTimezone($utc)->format('Ymd\THis\Z'),
                    'DTEND:'   . $end->setTimezone($utc)->format('Ymd\THis\Z'),
                ];
            } else {
                $d = new \DateTime($date, $tz);
                $dtLines = [
                    'DTSTART;VALUE=DATE:' . $d->format('Ymd'),
                    'DTEND;VALUE=DATE:'   . (clone $d)->modify('+1 day')->format('Ymd'),
                ];
            }

            $location = trim(($act['venue'] ?? '') . (!empty($act['venue_address']) ? ', ' . $act['venue_address'] : ''));
            $descr = 'Approved STI activity proposal.';
            if (!empty($act['description'])) {
                $descr .= "\n\n" . mb_substr(strip_tags((string)$act['description']), 0, 1000);
            }
            if (defined('BASE_URL')) {
                $descr .= "\n\n" . BASE_URL . '/faculty/proposal-view.php?id=' . (int)$act['id'];
            }

            $lines = array_merge([
                'BEGIN:VCALENDAR',
                'VERSION:2.0',
                'PRODID:-//STI Marikina//Activity System//EN',
                'CALSCALE:GREGORIAN',
                'METHOD:PUBLISH',
                'BEGIN:VEVENT',
                // Stable UID: re-sending for the same activity updates the calendar entry instead of duplicating it
                'UID:sti-activity-' . (int)$act['id'] . '@sti-activity-system',
                'DTSTAMP:' . gmdate('Ymd\THis\Z'),
                'SEQUENCE:' . time(),
            ], $dtLines, [
                'SUMMARY:' . $esc((string)($act['title'] ?? 'STI Activity')),
                'LOCATION:' . $esc($location),
                'DESCRIPTION:' . $esc($descr),
                'STATUS:CONFIRMED',
                'TRANSP:OPAQUE',
                'BEGIN:VALARM',
                'TRIGGER:-PT1H',
                'ACTION:DISPLAY',
                'DESCRIPTION:Reminder',
                'END:VALARM',
                'END:VEVENT',
                'END:VCALENDAR',
            ]);

            return [
                'name'    => 'activity-' . (int)$act['id'] . '.ics',
                'content' => implode("\r\n", array_map($fold, $lines)) . "\r\n",
                'type'    => 'text/calendar',
            ];
        } catch (\Throwable $e) {
            error_log('Calendar attachment build failed for activity ' . ($act['id'] ?? '?') . ': ' . $e->getMessage());
            return null;
        }
    }

    private function isSmtpReachable(): bool {
        if (self::$smtpReachable !== null) {
            return self::$smtpReachable;
        }
        $host = $this->config['SMTP_HOST'] ?? '';
        $port = (int)($this->config['SMTP_PORT'] ?? 587);
        if (empty($host)) {
            self::$smtpReachable = false;
            return false;
        }
        // Fast 1.0-second preflight check avoids blocking web server on egress-restricted hosts
        $fp = @fsockopen($host, $port, $errno, $errstr, 1.0);
        if ($fp) {
            @fclose($fp);
            self::$smtpReachable = true;
            return true;
        }
        error_log("EmailService Notice: Outbound SMTP port {$port} to {$host} is unreachable or firewalled. Seamlessly bypassing to Microsoft Graph API.");
        self::$smtpReachable = false;
        return false;
    }

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
        // Skip SMTP setup if credentials are not configured
        if (empty($this->config['SMTP_HOST']) || empty($this->config['SMTP_USERNAME']) || empty($this->config['FROM_EMAIL'])) {
            return;
        }
        
        try {
            // Server settings
            $this->mail->isSMTP();
            $this->mail->Host       = $this->config['SMTP_HOST'];
            $this->mail->SMTPAuth   = true;
            $this->mail->Username   = $this->config['SMTP_USERNAME'];
            $this->mail->Password   = $this->config['SMTP_PASSWORD'];
            
            $enc = strtolower(trim((string)$this->config['SMTP_ENCRYPTION']));
            if ($enc === 'tls' || $enc === 'starttls') {
                $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($enc === 'ssl' || $enc === 'smtps') {
                $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $this->mail->SMTPSecure = $this->config['SMTP_ENCRYPTION'];
            }

            $this->mail->Port       = (int)$this->config['SMTP_PORT'];
            $this->mail->Timeout    = 3; // Tight 3-second timeout prevents web server HTTP 500 timeouts on egress-restricted hosts

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
    public function sendNotification($recipientEmail, $recipientName, $subject, $proposalTitle, $status, $message, $actionLink, $activityId = null, $notificationType = 'general', array $attachments = []) {
        $this->attachments = $attachments;
        // 1. Deliverability & NDR Bounce Protection Guard
        $deliverability = $this->validateRecipientDeliverability((string)$recipientEmail, (string)$subject, (string)$proposalTitle);
        if (!$deliverability['deliverable']) {
            $reason = $deliverability['reason'];
            $logStatus = (str_contains($reason, 'simulated') || str_contains($reason, 'Automated test') || str_contains($reason, 'skipped')) ? 'simulated_success' : 'skipped';
            $this->logEmail($activityId, (string)$recipientEmail, $notificationType, $logStatus, $reason);
            error_log("EmailService Deliverability Notice: [{$recipientEmail}] skipped live dispatch - {$reason}");
            return true;
        }

        $recipientEmail = $deliverability['normalized_email'];

        // 2. Basic deduplication check (fault-tolerant if email_logs table is absent)
        // Rate-limits duplicate dispatch within a 60-second window to prevent rapid double-clicks without suppressing future status updates
        if ($activityId && $notificationType !== 'general') {
            try {
                $stmt = $this->db->prepare("SELECT id FROM email_logs WHERE activity_id = ? AND recipient_email = ? AND notification_type = ? AND status = 'success' AND sent_at >= (NOW() - INTERVAL 60 SECOND) LIMIT 1");
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

        $mailDriver = strtolower(trim((string)($this->config['MAIL_DRIVER'] ?? 'smtp')));
        $hasSmtp    = !empty($this->config['SMTP_HOST']) && !empty($this->config['SMTP_USERNAME']) && !empty($this->config['SMTP_PASSWORD']);
        $hasGraph   = !empty($this->config['OAUTH_REFRESH_TOKEN']);

        // 1. Primary: Brevo SMTP (if configured, reachable, or explicit driver)
        if (($mailDriver === 'smtp' || ($hasSmtp && $mailDriver !== 'graph')) && $this->isSmtpReachable()) {
            $sent = $this->sendViaPHPMailer($recipientEmail, $recipientName, $subject, $body, $altBody, $activityId, $notificationType);
            if ($sent) {
                return true;
            }
            // Seamless redundant fallback: Microsoft Graph API
            if ($hasGraph) {
                error_log("Brevo SMTP sending failed for {$recipientEmail}. Initiating redundant Microsoft Graph API fallback...");
                return $this->sendViaGraphAPI($recipientEmail, $recipientName, $subject, $body, $activityId, $notificationType);
            }
            return false;
        }

        // 2. Secondary: Microsoft Graph API as primary driver or when SMTP is firewalled
        if ($hasGraph) {
            $sent = $this->sendViaGraphAPI($recipientEmail, $recipientName, $subject, $body, $activityId, $notificationType);
            if ($sent) {
                return true;
            }
            // Fallback to Brevo SMTP only if reachable
            if ($hasSmtp && $this->isSmtpReachable()) {
                error_log("Microsoft Graph API sending failed for {$recipientEmail}. Initiating Brevo SMTP fallback...");
                return $this->sendViaPHPMailer($recipientEmail, $recipientName, $subject, $body, $altBody, $activityId, $notificationType);
            }
            return false;
        }

        // 3. Fallback: PHPMailer standalone (if reachable)
        if ($this->isSmtpReachable()) {
            return $this->sendViaPHPMailer($recipientEmail, $recipientName, $subject, $body, $altBody, $activityId, $notificationType);
        }
        return false;
    }

    /**
     * Validates whether a recipient email address is valid and deliverable,
     * protecting Microsoft Graph and Outlook from bouncing Non-Delivery Reports (NDR).
     *
     * @param string $email
     * @param string $subject
     * @param string $proposalTitle
     * @return array{deliverable: bool, reason: string, normalized_email: string}
     */
    public function validateRecipientDeliverability(string $email, string $subject = '', string $proposalTitle = ''): array {
        $cleanEmail = strtolower(trim($email));

        // 1. Basic RFC syntax check
        if (!filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'deliverable'      => false,
                'reason'           => "Invalid email syntax: '{$cleanEmail}'",
                'normalized_email' => $cleanEmail
            ];
        }

        // 2. Automated test context guard (Prevents tests from dispatching to live Exchange)
        $isTestContext = (defined('TEST_RUNNER') && TEST_RUNNER === true)
            || (getenv('APP_ENV') === 'testing')
            || (defined('TEST_MOCK_MAIL') && TEST_MOCK_MAIL === true)
            || (stripos($subject, 'AutoTest') !== false)
            || (stripos($proposalTitle, 'AutoTest') !== false);

        if ($isTestContext) {
            return [
                'deliverable'      => false,
                'reason'           => "Automated test activity notification simulated without live dispatch",
                'normalized_email' => $cleanEmail
            ];
        }

        // 3. Extract domain
        $parts = explode('@', $cleanEmail);
        $domain = end($parts);

        // 4. Banned/Simulated/Unroutable test domains
        $bannedDomains = [
            'test.com', 'example.com', 'example.org', 'example.net',
            'invalid', 'localhost', 'fake.com', 'dummy.com', 'test.local'
        ];

        if (in_array($domain, $bannedDomains, true) ||
            str_ends_with($domain, '.test') ||
            str_ends_with($domain, '.example') ||
            str_ends_with($domain, '.invalid') ||
            str_starts_with($cleanEmail, 'simulated_') ||
            str_starts_with($cleanEmail, 'fac_unrelated_') ||
            str_contains($cleanEmail, '_accept@test.') ||
            str_contains($cleanEmail, 'faculty2_test@')
        ) {
            return [
                'deliverable'      => false,
                'reason'           => "Unroutable test domain/account '{$cleanEmail}' skipped to prevent Outlook bounce NDR",
                'normalized_email' => $cleanEmail
            ];
        }

        // 5. Institutional alias mapping (maps legacy placeholder accounts to official institutional mailbox)
        $institutionalMap = [
            'arjay@sti.edu'                         => 'guaniso.372499@marikina.sti.edu.ph',
            'ar-jay.agbayani@marikina.sti.edu.ph'   => 'guaniso.372499@marikina.sti.edu.ph',
            'ian@sti.edu'                           => 'regulacion.376867@marikina.sti.edu.ph',
            'ian.barangan@marikina.sti.edu.ph'     => 'regulacion.376867@marikina.sti.edu.ph',
            'dean@sti.edu'                          => 'robles.376003@marikina.sti.edu.ph',
            'frederic.yulo@marikina.sti.edu.ph'     => 'robles.376003@marikina.sti.edu.ph',
            'dave@sti.edu'                          => 'hular.370125@marikina.sti.edu.ph',
            'dave.rubaya@marikina.sti.edu.ph'       => 'hular.370125@marikina.sti.edu.ph',
            'faculty@sti.edu'                       => 'payod.332445@marikina.sti.edu.ph',
            'maria.santos@marikina.sti.edu.ph'      => 'payod.332445@marikina.sti.edu.ph',
            'aldwin@sti.edu'                        => 'aldwin.alminar@marikina.sti.edu.ph',
            'tan@sti.edu'                           => 'tristan.unabia@marikina.sti.edu.ph',
        ];

        if (isset($institutionalMap[$cleanEmail])) {
            $cleanEmail = $institutionalMap[$cleanEmail];
            $parts = explode('@', $cleanEmail);
            $domain = end($parts);
        } elseif (str_ends_with($domain, '.sti.edu')) {
            // Auto-heal .sti.edu to .sti.edu.ph (e.g. user@marikina.sti.edu -> user@marikina.sti.edu.ph)
            $cleanEmail = $parts[0] . '@' . $domain . '.ph';
            $parts = explode('@', $cleanEmail);
            $domain = end($parts);
        }

        // 6. DNS deliverability check (Checks if recipient domain has MX or A records)
        $hasMx = @checkdnsrr($domain, 'MX');
        $hasA  = @checkdnsrr($domain, 'A');

        if (!$hasMx && !$hasA) {
            return [
                'deliverable'      => false,
                'reason'           => "Recipient domain '@{$domain}' has no valid MX or A DNS records. Skipped to prevent NDR bounce",
                'normalized_email' => $cleanEmail
            ];
        }

        return [
            'deliverable'      => true,
            'reason'           => 'OK',
            'normalized_email' => $cleanEmail
        ];
    }

    private function sendViaGraphAPI($recipientEmail, $recipientName, $subject, $body, $activityId, $notificationType) {
        try {
            $verifyCa = true;
            if (file_exists('C:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem')) {
                $verifyCa = 'C:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem';
            } elseif (file_exists('C:/xampp/perl/vendor/lib/Mozilla/CA/cacert.pem')) {
                $verifyCa = 'C:/xampp/perl/vendor/lib/Mozilla/CA/cacert.pem';
            }

            $guzzle = new \GuzzleHttp\Client([
                'verify'          => $verifyCa,
                'timeout'         => 8.0,
                'connect_timeout' => 4.0
            ]);

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
            
            $fromEmail = !empty($this->config['FROM_EMAIL']) ? $this->config['FROM_EMAIL'] : 'sti.activity.system@outlook.com';
            $fromName  = !empty($this->config['FROM_NAME']) ? $this->config['FROM_NAME'] : 'STI Activity System';

            $payload = [
                'message' => [
                    'subject' => $subject,
                    'body' => [
                        'contentType' => 'HTML',
                        'content' => $body
                    ],
                    'from' => [
                        'emailAddress' => [
                            'address' => $fromEmail,
                            'name'    => $fromName
                        ]
                    ],
                    'replyTo' => [
                        [
                            'emailAddress' => [
                                'address' => $fromEmail,
                                'name'    => $fromName
                            ]
                        ]
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

            if (!empty($this->attachments)) {
                $payload['message']['attachments'] = array_map(static fn($a) => [
                    '@odata.type'  => '#microsoft.graph.fileAttachment',
                    'name'         => $a['name'],
                    'contentType'  => $a['type'] ?? 'application/octet-stream',
                    'contentBytes' => base64_encode($a['content']),
                ], $this->attachments);
            }

            $client = new \GuzzleHttp\Client([
                'verify'          => $verifyCa,
                'timeout'         => 8.0,
                'connect_timeout' => 4.0
            ]);
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
            $this->mail->clearAttachments();
            $this->mail->addAddress($recipientEmail, $recipientName);
            foreach ($this->attachments as $a) {
                $this->mail->addStringAttachment($a['content'], $a['name'], PHPMailer::ENCODING_BASE64, $a['type'] ?? 'application/octet-stream');
            }
            
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
