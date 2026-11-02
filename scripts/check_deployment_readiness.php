<?php
/**
 * scripts/check_deployment_readiness.php
 * Verifies system environment, PHP extensions, upload directories, and config availability.
 */

echo "=== DEPLOYMENT READINESS AUDIT ===\n\n";

// 1. PHP Version
echo "1. PHP Version: " . PHP_VERSION . "\n";
if (version_compare(PHP_VERSION, '8.0.0', '>=')) {
    echo "   [PASS] PHP version meets minimum requirement (8.0+)\n";
} else {
    echo "   [FAIL] PHP version is below 8.0\n";
}

// 2. Required PHP Extensions
echo "\n2. Required PHP Extensions:\n";
$requiredExts = ['pdo', 'pdo_mysql', 'curl', 'mbstring', 'openssl', 'json', 'gd', 'zip', 'fileinfo'];
foreach ($requiredExts as $ext) {
    if (extension_loaded($ext)) {
        echo "   [PASS] Extension '$ext' loaded\n";
    } else {
        echo "   [FAIL] Extension '$ext' missing\n";
    }
}

// 3. Upload and Storage Directories
echo "\n3. Upload and Storage Directories:\n";
$uploadDirs = [
    'uploads',
    'uploads/avatars',
    'uploads/posters',
    'uploads/floorplans',
    'uploads/deliverables',
    'backups'
];

$basePath = dirname(__DIR__);
foreach ($uploadDirs as $dir) {
    $full = $basePath . '/' . $dir;
    if (!is_dir($full)) {
        @mkdir($full, 0755, true);
    }
    $writable = is_writable($full);
    if (is_dir($full) && $writable) {
        echo "   [PASS] Directory '$dir' exists and is writable\n";
    } else {
        echo "   [WARN] Directory '$dir' " . (is_dir($full) ? "exists but is NOT writable" : "missing") . "\n";
    }
}

// 4. Configuration Template & Environment Variables
echo "\n4. Configuration & Environment Variables:\n";
$envExample = $basePath . '/.env.example';
$hasEnvExample = file_exists($envExample);
echo "   " . ($hasEnvExample ? "[PASS]" : "[WARN]") . " .env.example template present\n";

$envFile = $basePath . '/.env';
$hasEnv = file_exists($envFile);
echo "   " . ($hasEnv ? "[INFO]" : "[WARN]") . " .env file " . ($hasEnv ? "present" : "not found (uses default XAMPP/server config)") . "\n";

// Check configuration keys defined without echoing sensitive values
$expectedKeys = [
    'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS',
    'BASE_URL',
    'MICROSOFT_CLIENT_ID', 'MICROSOFT_CLIENT_SECRET', 'MICROSOFT_REDIRECT_URI',
    'GEMINI_API_KEY'
];

echo "\n5. Key Configuration Parameters Status:\n";
require_once $basePath . '/config/config.php';
require_once $basePath . '/config/database.php';

echo "   - DB_HOST: " . (defined('DB_HOST') && DB_HOST ? "[CONFIGURED]" : "[DEFAULT: localhost]") . "\n";
echo "   - DB_NAME: " . (defined('DB_NAME') && DB_NAME ? "[CONFIGURED: " . DB_NAME . "]" : "[MISSING]") . "\n";
echo "   - BASE_URL: " . (defined('BASE_URL') && BASE_URL ? "[CONFIGURED: " . BASE_URL . "]" : "[MISSING]") . "\n";

$hasGemini = !empty(getenv('GEMINI_API_KEY')) || (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '');
echo "   - GEMINI_API_KEY: " . ($hasGemini ? "[SET]" : "[NOT SET - local AI fallback/mock]") . "\n";

$hasMsSso = !empty(getenv('MICROSOFT_CLIENT_ID')) || defined('MICROSOFT_CLIENT_ID');
echo "   - MICROSOFT_CLIENT_ID: " . ($hasMsSso ? "[SET]" : "[NOT SET - local auth active]") . "\n";

// 6. Session & Security Configuration
echo "\n6. Session & Security Configuration:\n";
echo "   - session.use_strict_mode: " . (ini_get('session.use_strict_mode') ? '1 [OK]' : '0 [Recommendation: enable in production php.ini]') . "\n";
echo "   - session.cookie_httponly: " . (ini_get('session.cookie_httponly') ? '1 [OK]' : '0 [Handled in session_start options in code]') . "\n";

echo "\n=== AUDIT COMPLETE ===\n";

