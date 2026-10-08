<?php
/**
 * api/profile-update.php
 *
 * Secure server-side endpoint for User Profile Editing.
 * Allows authenticated users to:
 * - Update their own display/full name.
 * - Change their profile picture (JPG, PNG, WEBP <= 2 MB).
 * - Remove their profile picture.
 *
 * Enforces:
 * - Current user's own profile only (cannot modify other users).
 * - CSRF token verification.
 * - Email, Role, and Department remain protected / non-editable.
 * - Server-side image format and size validation.
 * - Immediate session state synchronization.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function sendProfileError(string $message, int $statusCode = 422, string $errorCode = 'validation_error'): void {
    http_response_code($statusCode);
    echo json_encode([
        'success'    => false,
        'error'      => $message,
        'error_code' => $errorCode
    ]);
    exit;
}

// 1. Authoritative Guard: Must be authenticated
startSession();
if (empty($_SESSION['user_id'])) {
    sendProfileError('Unauthorized. Please log in.', 401, 'unauthorized');
}
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendProfileError('Only POST requests are permitted.', 405, 'invalid_method');
}

// 2. CSRF Token Validation
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!validateCsrfToken($csrfToken)) {
    sendProfileError('Invalid or expired security token. Please refresh and try again.', 403, 'csrf_invalid');
}

$currentUser = currentUser();
$userId = (int)($currentUser['id'] ?? 0);
if ($userId <= 0) {
    sendProfileError('User session is invalid.', 401, 'unauthorized');
}

// 3. Fetch authoritative user record from DB
$db = getDB();
$stmt = $db->prepare('SELECT id, name, email, role, department, profile_picture FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$userRecord = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$userRecord) {
    sendProfileError('User account not found.', 404, 'user_not_found');
}

// 4. Validate Full Name
$name = trim((string)($_POST['name'] ?? ''));
if ($name === '') {
    sendProfileError('Full Name is required.', 422, 'missing_name');
}
if (strlen($name) > 150) {
    sendProfileError('Full Name cannot exceed 150 characters.', 422, 'name_too_long');
}

// 5. Handle Profile Picture (Upload, Remove, or Keep Existing)
$newProfilePicture = $userRecord['profile_picture'];
$removePicture = !empty($_POST['remove_picture']) && $_POST['remove_picture'] === '1';

if ($removePicture) {
    // Delete old avatar file from disk if existing
    if (!empty($userRecord['profile_picture'])) {
        $oldPath = realpath(UPLOAD_PATH . $userRecord['profile_picture']);
        $avatarsBase = realpath(UPLOAD_PATH . 'avatars');
        if ($oldPath && $avatarsBase && str_starts_with($oldPath, $avatarsBase) && file_exists($oldPath)) {
            @unlink($oldPath);
        }
    }
    $newProfilePicture = null;
} elseif (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['profile_picture'];

    // Check PHP upload error code
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
            UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive.',
            UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on the server.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.'
        ];
        $msg = $uploadErrors[$file['error']] ?? 'An unknown upload error occurred.';
        sendProfileError($msg, 422, 'upload_failed');
    }

    // Enforce 2 MB limit (2 * 1024 * 1024 bytes = 2097152 bytes)
    $maxBytes = 2 * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        sendProfileError('The profile picture must be 2 MB or smaller.', 422, 'file_too_large');
    }

    // Validate Extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowedExts, true)) {
        sendProfileError('Unsupported image type. Only JPG, PNG, and WEBP files are allowed.', 422, 'invalid_extension');
    }

    // Validate Server-Side MIME Type using multi-layer detection (finfo, mime_content_type, getimagesize, magic bytes)
    $detectedMime = getUploadedImageMime($file['tmp_name']);

    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!$detectedMime || !in_array($detectedMime, $allowedMimes, true)) {
        sendProfileError('The uploaded file is not a valid JPG, PNG, or WEBP image.', 422, 'invalid_mime');
    }

    // Deep content verification with getimagesize
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        sendProfileError('The file is corrupted or is not a valid image format.', 422, 'corrupt_image');
    }

    // Ensure uploads/avatars directory exists
    $avatarsDir = UPLOAD_PATH . 'avatars';
    if (!is_dir($avatarsDir)) {
        if (!@mkdir($avatarsDir, 0755, true) && !is_dir($avatarsDir)) {
            sendProfileError('Server storage directory could not be created.', 500, 'storage_error');
        }
    }

    // Generate unique, collision-proof filename
    $fileName = 'user_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    $targetPath = $avatarsDir . DIRECTORY_SEPARATOR . $fileName;

    $moved = @move_uploaded_file($file['tmp_name'], $targetPath);
    // CLI fallback for test runners
    if (!$moved && (php_sapi_name() === 'cli' || defined('TEST_RUNNER'))) {
        $moved = @copy($file['tmp_name'], $targetPath);
    }

    if (!$moved) {
        sendProfileError('Failed to save profile picture to server storage.', 500, 'save_failed');
    }

    // Delete old avatar file from disk if existing
    if (!empty($userRecord['profile_picture'])) {
        $oldPath = realpath(UPLOAD_PATH . $userRecord['profile_picture']);
        $avatarsBase = realpath($avatarsDir);
        if ($oldPath && $avatarsBase && str_starts_with($oldPath, $avatarsBase) && file_exists($oldPath)) {
            @unlink($oldPath);
        }
    }

    $newProfilePicture = 'avatars/' . $fileName;
}

// 6. Persist Updates in Database
// Note: Email, Role, Department are intentionally untouched and protected.
$updateStmt = $db->prepare('UPDATE users SET name = ?, profile_picture = ? WHERE id = ?');
$updateStmt->execute([$name, $newProfilePicture, $userId]);

// 7. Synchronize Active Session Immediately
$_SESSION['user_name'] = $name;
$_SESSION['user_profile_picture'] = $newProfilePicture;

// 8. Return Fresh Profile State
$avatarUrl = $newProfilePicture
    ? BASE_URL . '/api/profile-avatar.php?id=' . $userId . '&v=' . time()
    : null;

echo json_encode([
    'success' => true,
    'message' => 'Profile updated successfully.',
    'user'    => [
        'id'              => $userId,
        'name'            => $name,
        'email'           => $userRecord['email'],
        'role'            => $userRecord['role'],
        'role_title'      => ucwords(str_replace('_', ' ', $userRecord['role'])),
        'department'      => $userRecord['department'] ?? 'Not Assigned',
        'profile_picture' => $newProfilePicture,
        'avatar_url'      => $avatarUrl,
        'initial'         => strtoupper(substr($name, 0, 1))
    ]
]);
