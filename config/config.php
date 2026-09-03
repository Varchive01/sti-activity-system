<?php
define('BASE_URL', 'http://localhost/sti-activity-system');
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10 MB
define('ALLOWED_EXTENSIONS', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'gif']);
define('APP_NAME', 'STI Marikina – Activity System');
define('SESSION_TIMEOUT', 3600); // 1 hour
// Load .env if it exists
$envFile = __DIR__ . '/../.env';
$envVars = file_exists($envFile) ? parse_ini_file($envFile) : [];

$geminiKey = getenv('GEMINI_API_KEY');
if (!$geminiKey && isset($envVars['GEMINI_API_KEY'])) {
    $geminiKey = $envVars['GEMINI_API_KEY'];
}
define('GEMINI_API_KEY', $geminiKey ?: '');

// Firebase Storage Configuration
// To use Firebase Storage, provide a Service Account JSON file in the config folder 
// and define the bucket name. If the credentials file doesn't exist, the system will
// fallback to local file uploads.
define('FIREBASE_CREDENTIALS', __DIR__ . '/firebase-credentials.json');
define('FIREBASE_BUCKET', 'your-project-id.appspot.com');
