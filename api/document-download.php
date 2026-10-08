<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// 1. Enforce authenticated session
requireLogin();

$user = currentUser();
if (empty($user['id'])) {
    http_response_code(401);
    die('Unauthorized.');
}

// 2. Validate document or deliverable ID from query parameter
$docId = (int)($_GET['id'] ?? 0);
$deliverableId = (int)($_GET['deliverable_id'] ?? 0);

if ($docId <= 0 && $deliverableId <= 0) {
    http_response_code(400);
    die('Bad Request: Invalid document or deliverable ID.');
}

$db = getDB();
$role = $user['role'] ?? '';
$isAuthorized = false;
$storedPath = '';
$originalName = '';

if ($deliverableId > 0) {
    // 3a. Retrieve deliverable record and associated task + activity
    $stmt = $db->prepare("
        SELECT td.*, ft.assigned_user_id, ft.task_title, a.faculty_id, a.title AS activity_title 
        FROM task_deliverables td
        JOIN faculty_tasks ft ON td.task_id = ft.id
        JOIN activities a ON td.activity_id = a.id
        WHERE td.id = ?
        LIMIT 1
    ");
    $stmt->execute([$deliverableId]);
    $deliv = $stmt->fetch();

    if (!$deliv) {
        http_response_code(404);
        die('Deliverable not found.');
    }

    // 4a. Role-based authorization for deliverable
    if (in_array($role, ['admin1', 'admin2', 'dean'], true)) {
        // Institutional administrators/evaluators and Dean have read-only access
        $isAuthorized = true;
    } elseif ($role === 'faculty') {
        // Faculty can access if they are the activity leader, assigned user, or uploader
        if ((int)$deliv['faculty_id'] === (int)$user['id'] || 
            (int)$deliv['assigned_user_id'] === (int)$user['id'] || 
            (int)$deliv['uploaded_by'] === (int)$user['id']) {
            $isAuthorized = true;
        }
    }

    if (!$isAuthorized) {
        http_response_code(403);
        die('Forbidden: You do not have permission to access this deliverable.');
    }

    $storedPath = trim((string)($deliv['file_path'] ?? ''));
    $originalName = $deliv['file_name'] ?: basename($storedPath);
} else {
    // 3b. Retrieve document record and associated activity
    $stmt = $db->prepare("
        SELECT d.*, a.faculty_id, a.title AS activity_title 
        FROM documents d
        JOIN activities a ON d.activity_id = a.id
        WHERE d.id = ?
        LIMIT 1
    ");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();

    if (!$doc) {
        http_response_code(404);
        die('Document not found.');
    }

    // 4b. Role-based authorization
    if (in_array($role, ['admin1', 'admin2', 'dean'], true)) {
        // Institutional administrators/evaluators have full access to activity documents
        $isAuthorized = true;
    } elseif ($role === 'faculty') {
        // Faculty can access if they own the activity or uploaded the document
        if ((int)$doc['faculty_id'] === (int)$user['id'] || (int)$doc['uploaded_by'] === (int)$user['id']) {
            $isAuthorized = true;
        }
    }

    if (!$isAuthorized) {
        http_response_code(403);
        die('Forbidden: You do not have permission to access this document.');
    }

    $storedPath = trim((string)($doc['file_path'] ?? ''));
    $originalName = $doc['file_name'] ?: basename($storedPath);
}

// 5. Secure file path resolution and traversal prevention
if ($storedPath === '') {
    http_response_code(404);
    die('Document file path not recorded.');
}

// Handle remote cloud URL if stored
if (preg_match('#^https?://#i', $storedPath)) {
    header('Location: ' . $storedPath);
    exit;
}

$baseUploadDir = realpath(UPLOAD_PATH);
if (!$baseUploadDir || !is_dir($baseUploadDir)) {
    http_response_code(500);
    die('Server configuration error: Upload storage directory not found.');
}

// Normalize stored path relative to UPLOAD_PATH
$cleanRelative = ltrim(str_replace('\\', '/', $storedPath), '/');
if (str_starts_with($cleanRelative, 'uploads/')) {
    $cleanRelative = substr($cleanRelative, 8);
}

$targetFullPath = realpath($baseUploadDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanRelative));

// Traversal and existence verification
$canonicalBase = rtrim(str_replace('\\', '/', $baseUploadDir), '/') . '/';
$canonicalTarget = str_replace('\\', '/', $targetFullPath ?: '');

if (!$targetFullPath || !is_file($targetFullPath) || !str_starts_with($canonicalTarget, $canonicalBase)) {
    http_response_code(404);
    die('Document file not found on server.');
}

// 6. Serve the file safely
$mime = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $detected = @finfo_file($finfo, $targetFullPath);
        @finfo_close($finfo);
        if ($detected) $mime = $detected;
    }
} elseif (function_exists('mime_content_type')) {
    $detected = @mime_content_type($targetFullPath);
    if ($detected) $mime = $detected;
}

if ($mime === 'application/octet-stream') {
    $ext = strtolower(pathinfo($targetFullPath, PATHINFO_EXTENSION));
    $mimeMap = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'txt'  => 'text/plain',
        'csv'  => 'text/csv',
    ];
    if (isset($mimeMap[$ext])) {
        $mime = $mimeMap[$ext];
    }
}

$finalFilename = $originalName ?: basename($targetFullPath);
$safeFilename  = basename(str_replace(["\r", "\n"], '', $finalFilename));
$isDownload   = isset($_GET['download']) && $_GET['download'] === '1';
$disposition  = $isDownload ? 'attachment' : 'inline';

while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($safeFilename) . '"; filename*=UTF-8\'\'' . rawurlencode($safeFilename));
header('Content-Length: ' . filesize($targetFullPath));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

readfile($targetFullPath);
exit;
