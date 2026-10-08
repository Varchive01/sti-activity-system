<?php
/**
 * api/profile-avatar.php
 *
 * Secure server-side endpoint for serving user profile pictures.
 * Enforces authenticated session, prevents directory traversal,
 * validates file existence, and serves correct image content types.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// 1. Enforce authenticated session
startSession();
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    die('Unauthorized.');
}
requireLogin();

$currentUser = currentUser();

// 2. Validate requested user ID
$targetUserId = isset($_GET['id']) ? (int)$_GET['id'] : (int)$currentUser['id'];
if ($targetUserId <= 0) {
    http_response_code(400);
    die('Invalid user ID.');
}

// 3. Retrieve user profile info from database
$db = getDB();
$stmt = $db->prepare('SELECT id, name, role, profile_picture FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$targetUserId]);
$targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$targetUser) {
    http_response_code(404);
    die('User not found.');
}

$storedPath = trim((string)($targetUser['profile_picture'] ?? ''));

// Helper to output SVG fallback avatar if no picture exists
function serveSvgAvatar(string $name): void {
    $initial = strtoupper(substr(trim($name) ?: 'U', 0, 1));
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">'
         . '<circle cx="64" cy="64" r="64" fill="#0284C7"/>'
         . '<text x="50%" y="54%" text-anchor="middle" dominant-baseline="middle" fill="#FFFFFF" '
         . 'font-family="system-ui, -apple-system, sans-serif" font-size="54" font-weight="700">'
         . htmlspecialchars($initial)
         . '</text></svg>';
    header('Content-Type: image/svg+xml');
    header('Cache-Control: private, max-age=3600');
    echo $svg;
    exit;
}

if ($storedPath === '') {
    serveSvgAvatar($targetUser['name'] ?? 'User');
}

// Handle remote cloud URL if stored
if (preg_match('#^https?://#i', $storedPath)) {
    header('Location: ' . $storedPath);
    exit;
}

// 4. Secure path resolution and traversal prevention
$baseUploadDir = realpath(UPLOAD_PATH);
if (!$baseUploadDir || !is_dir($baseUploadDir)) {
    serveSvgAvatar($targetUser['name'] ?? 'User');
}

$cleanRelative = ltrim(str_replace('\\', '/', $storedPath), '/');
if (str_starts_with($cleanRelative, 'uploads/')) {
    $cleanRelative = substr($cleanRelative, 8);
}

$targetFullPath = realpath($baseUploadDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanRelative));
$canonicalBase  = rtrim(str_replace('\\', '/', $baseUploadDir), '/') . '/';
$canonicalTarget = str_replace('\\', '/', $targetFullPath ?: '');

if (!$targetFullPath || !is_file($targetFullPath) || !str_starts_with($canonicalTarget, $canonicalBase)) {
    serveSvgAvatar($targetUser['name'] ?? 'User');
}

// 5. Verify allowed image types
$allowedMimes = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

$detectedMime = getUploadedImageMime($targetFullPath);

if (!$detectedMime || !isset($allowedMimes[$detectedMime])) {
    serveSvgAvatar($targetUser['name'] ?? 'User');
}

// 6. ETag and Caching headers
$mtime = filemtime($targetFullPath);
$etag = '"' . md5($targetFullPath . $mtime) . '"';

header('Content-Type: ' . $detectedMime);
header('Content-Length: ' . filesize($targetFullPath));
header('Cache-Control: private, max-age=86400, must-revalidate');
header('ETag: ' . $etag);

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

readfile($targetFullPath);
exit;
