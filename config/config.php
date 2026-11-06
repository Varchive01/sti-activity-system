<?php
date_default_timezone_set('Asia/Manila');

// 1. Load .env file safely if it exists
$envFile = dirname(__DIR__) . '/.env';
$envVars = [];
if (file_exists($envFile)) {
    $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) continue;
            $parts = explode('=', $trimmed, 2);
            if (count($parts) === 2) {
                $k = trim($parts[0]);
                $v = trim($parts[1]);
                $v = trim($v, "\"' \t\n\r\0\x0B");
                $envVars[$k] = $v;
            }
        }
    }
}

// 2. Protocol & Host Detection (Supports reverse proxies such as Railway, Render, Cloudflare, InfinityFree)
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && str_contains(strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']), 'https'))
    || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PORT']) && (int)$_SERVER['HTTP_X_FORWARDED_PORT'] === 443)
    || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    || (!empty($_SERVER['HTTP_CF_VISITOR']) && str_contains($_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"'))
    || (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) === 'on');
$protocol = $isHttps ? 'https://' : 'http://';

$host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
if (str_contains($host, ',')) {
    $host = trim(explode(',', $host)[0]);
}

// For live production hosts behind SSL reverse proxies, default protocol to https:// to prevent redirect loops
$isLocalHost = empty($host) || in_array(strtolower(explode(':', $host)[0]), ['localhost', '127.0.0.1', '::1'], true);
if (!$isLocalHost && !$isHttps) {
    $protocol = 'https://';
}

// 3. BASE_URL Resolution
// Priority 1: Explicit environment variable (APP_URL or BASE_URL)
$explicitAppUrl = getenv('APP_URL') ?: (getenv('BASE_URL') ?: ($envVars['APP_URL'] ?? ($envVars['BASE_URL'] ?? null)));

if (!empty($explicitAppUrl)) {
    $baseUrl = rtrim((string)$explicitAppUrl, '/');
} else {
    // Priority 2: Auto-detection for local subdirectories vs production root domains
    $docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $appRoot = realpath(__DIR__ . '/..');
    $subDir = '';

    if ($docRoot && $appRoot) {
        $normDoc = str_replace('\\', '/', $docRoot);
        $normApp = str_replace('\\', '/', $appRoot);
        if (str_starts_with($normApp, $normDoc)) {
            $subDir = substr($normApp, strlen($normDoc));
        }
    } elseif (!empty($_SERVER['SCRIPT_NAME'])) {
        $normScript = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
        if (str_contains($normScript, '/sti-activity-system/')) {
            $subDir = '/sti-activity-system';
        }
    }

    // Default to /sti-activity-system on local CLI or localhost when no subDir was detected
    if ($subDir === '' && ($host === 'localhost' || $host === '127.0.0.1')) {
        if (basename(realpath(__DIR__ . '/..')) === 'sti-activity-system') {
            $subDir = '/sti-activity-system';
        }
    }

    $subDir = rtrim(str_replace('\\', '/', $subDir), '/');
    $baseUrl = $protocol . $host . $subDir;
}

define('BASE_URL', $baseUrl);
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10 MB
define('ALLOWED_EXTENSIONS', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'gif', 'webp']);
define('ALLOWED_POSTER_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp', 'gif']);
define('ALLOWED_POSTER_MIMES', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
define('APP_NAME', 'STI Marikina – Activity System');
define('SESSION_TIMEOUT', 3600); // 1 hour

// 4. API & External Service Keys
$geminiKey = getenv('GEMINI_API_KEY');
if (!$geminiKey && isset($envVars['GEMINI_API_KEY'])) {
    $geminiKey = $envVars['GEMINI_API_KEY'];
}
define('GEMINI_API_KEY', $geminiKey ?: '');

// Firebase Storage Configuration (optional cloud storage fallback)
define('FIREBASE_CREDENTIALS', __DIR__ . '/firebase-credentials.json');
define('FIREBASE_BUCKET', 'your-project-id.appspot.com');
