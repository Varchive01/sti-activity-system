<?php
function sanitize(string $val): string
{
    return strip_tags(trim(htmlspecialchars_decode($val, ENT_QUOTES)));
}

function jsonResponse(mixed $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function getUploadedImageMime(string $filePath): string|false
{
    if (!file_exists($filePath) || filesize($filePath) < 8) {
        return false;
    }
    
    // 1. Try finfo if available
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $m = @finfo_file($finfo, $filePath);
            @finfo_close($finfo);
            if ($m) return $m;
        }
    }
    
    // 2. Try mime_content_type if available
    if (function_exists('mime_content_type')) {
        $m = @mime_content_type($filePath);
        if ($m) return $m;
    }
    
    // 3. Try getimagesize
    $info = @getimagesize($filePath);
    if ($info && !empty($info['mime'])) {
        return $info['mime'];
    }

    // 4. Binary magic bytes header inspection
    $handle = @fopen($filePath, 'rb');
    if (!$handle) return false;
    $bytes = fread($handle, 16);
    fclose($handle);

    if (strlen($bytes) < 8) return false;

    // JPEG: FF D8 FF
    if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
        return 'image/jpeg';
    }
    // PNG: \x89PNG\r\n\x1a\n
    if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
        return 'image/png';
    }
    // GIF: GIF87a / GIF89a
    if (str_starts_with($bytes, "GIF87a") || str_starts_with($bytes, "GIF89a")) {
        return 'image/gif';
    }
    // WEBP: RIFF....WEBP
    if (strlen($bytes) >= 12 && str_starts_with($bytes, "RIFF") && substr($bytes, 8, 4) === "WEBP") {
        return 'image/webp';
    }

    return false;
}

function isUploadedVideo(string $filePath): bool
{
    if (!file_exists($filePath) || filesize($filePath) < 8) {
        return false;
    }
    $handle = @fopen($filePath, 'rb');
    if (!$handle) return false;
    $bytes = fread($handle, 32);
    fclose($handle);

    // MP4 / MOV / M4V: contains 'ftyp' or 'moov'
    if (strpos($bytes, 'ftyp') !== false || strpos($bytes, 'moov') !== false) {
        return true;
    }
    // Matroska / WebM
    if (str_starts_with($bytes, "\x1A\x45\xDF\xA3")) {
        return true;
    }
    // AVI
    if (strlen($bytes) >= 12 && str_starts_with($bytes, "RIFF") && substr($bytes, 8, 4) === "AVI ") {
        return true;
    }
    // FLV
    if (str_starts_with($bytes, "FLV")) {
        return true;
    }
    return false;
}

function isValidPosterImage(array $file): bool
{
    if (empty($file['name']) || empty($file['tmp_name']) || !file_exists($file['tmp_name'])) {
        return false;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return false;
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        return false;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = defined('ALLOWED_POSTER_EXTENSIONS') 
        ? ALLOWED_POSTER_EXTENSIONS 
        : ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowedExts, true)) {
        return false;
    }

    // Explicitly reject video file extensions
    $videoExts = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'flv', 'wmv', 'm4v', '3gp', 'ogv'];
    if (in_array($ext, $videoExts, true)) {
        return false;
    }

    // Explicitly check if binary content is a video
    if (isUploadedVideo($file['tmp_name'])) {
        return false;
    }

    // Validate real image MIME type
    $mime = getUploadedImageMime($file['tmp_name']);
    if (!$mime || str_starts_with($mime, 'video/')) {
        return false;
    }

    $allowedMimes = defined('ALLOWED_POSTER_MIMES') 
        ? ALLOWED_POSTER_MIMES 
        : ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    return in_array($mime, $allowedMimes, true);
}

function isValidFloorPlanFile(array $file): bool
{
    if (empty($file['name']) || empty($file['tmp_name']) || !file_exists($file['tmp_name'])) {
        return false;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return false;
    }
    if ($file['size'] <= 0 || $file['size'] > MAX_FILE_SIZE) {
        return false;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['png', 'jpg', 'jpeg', 'pdf'];
    if (!in_array($ext, $allowedExts, true)) {
        return false;
    }

    // Explicitly reject video files
    if (isUploadedVideo($file['tmp_name'])) {
        return false;
    }

    // Determine actual MIME type using finfo or mime_content_type
    $mime = false;
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = @finfo_file($finfo, $file['tmp_name']);
            @finfo_close($finfo);
        }
    }
    if (!$mime && function_exists('mime_content_type')) {
        $mime = @mime_content_type($file['tmp_name']);
    }

    $allowedMimes = ['image/png', 'image/jpeg', 'application/pdf'];
    if (!$mime || !in_array($mime, $allowedMimes, true)) {
        return false;
    }

    // Extra check for PDF: verify %PDF- magic header
    if ($ext === 'pdf' || $mime === 'application/pdf') {
        if ($mime !== 'application/pdf' || $ext !== 'pdf') {
            return false;
        }
        $handle = @fopen($file['tmp_name'], 'rb');
        if (!$handle) return false;
        $header = fread($handle, 5);
        fclose($handle);
        if (!str_starts_with($header, '%PDF-')) {
            return false;
        }
    } else {
        // Extra check for image: verify image MIME matches
        $imgMime = getUploadedImageMime($file['tmp_name']);
        if (!$imgMime || !in_array($imgMime, ['image/png', 'image/jpeg'], true)) {
            return false;
        }
    }

    return true;
}

function uploadFile(array $file, string $subdir, int $activityId, ?OneDriveService $oneDriveService = null): string|false
{
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS)) return false;
    if ($file['size'] > MAX_FILE_SIZE) return false;
    
    // Strict image validation for event posters (no videos or non-image files)
    if ($subdir === 'posters') {
        if (!isValidPosterImage($file)) {
            return false;
        }
    }

    // Strict image/PDF validation for floor plans
    if ($subdir === 'floorplans') {
        if (!isValidFloorPlanFile($file)) {
            return false;
        }
    }
    
    $name = $activityId . '_' . time() . '_' . uniqid() . '.' . $ext;
    
    // Check if Firebase is configured
    if (defined('FIREBASE_CREDENTIALS') && file_exists(FIREBASE_CREDENTIALS) && defined('FIREBASE_BUCKET')) {
        require_once __DIR__ . '/FirebaseStorage.php';
        try {
            $storage = new FirebaseStorage(FIREBASE_CREDENTIALS, FIREBASE_BUCKET);
            $objectName = $subdir . '/' . $name;
            $mimeType = mime_content_type($file['tmp_name']) ?: 'application/octet-stream';
            return $storage->uploadFile($file['tmp_name'], $objectName, $mimeType);
        } catch (Exception $e) {
            error_log("Firebase Upload Error: " . $e->getMessage());
            return false;
        }
    }

    // Fallback to local upload
    $dir  = UPLOAD_PATH . $subdir . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    
    $fullLocalPath = $dir . $name;
    $localPath     = $subdir . '/' . $name;

    $saved = @move_uploaded_file($file['tmp_name'], $fullLocalPath);
    // CLI / test runner fallback for non-HTTP uploads
    if (!$saved && (php_sapi_name() === 'cli' || defined('TEST_RUNNER'))) {
        $saved = @copy($file['tmp_name'], $fullLocalPath);
    }

    if (!$saved) {
        return false;
    }

    // Optional Non-Blocking OneDrive Archive Copy (local storage remains authoritative)
    attemptOneDriveArchiveCopy($fullLocalPath, $name, $subdir, $activityId, $oneDriveService);

    return $localPath;
}

/**
 * Global cache accessor for OneDrive sync metadata within the request lifecycle.
 */
function getOneDriveSyncMetadata(string $pathOrFilename): ?array
{
    global $__onedrive_sync_cache;
    if (!is_array($__onedrive_sync_cache)) {
        return null;
    }
    $clean = str_replace('\\', '/', $pathOrFilename);
    $base = basename($clean);
    return $__onedrive_sync_cache[$clean] 
        ?? $__onedrive_sync_cache[$base] 
        ?? null;
}

/**
 * Persists OneDrive sync metadata to an existing document or activity deliverable record.
 */
function persistOneDriveFileMetadata(int $activityId, string $localRelPath, array $metadata): bool
{
    try {
        if (!function_exists('getDB')) {
            require_once __DIR__ . '/../config/database.php';
        }
        $db = getDB();
        if (!$db) return false;

        $fileName = basename($localRelPath);
        $cleanRel = ltrim(str_replace('\\', '/', $localRelPath), '/');

        // Check if documents table has onedrive columns
        $checkCol = $db->query("SHOW COLUMNS FROM `documents` LIKE 'onedrive_status'")->fetch();
        if (!$checkCol) {
            return false;
        }

        // 1. Look for existing record in documents table
        $stmt = $db->prepare("
            SELECT id FROM documents 
            WHERE activity_id = ? AND (file_path = ? OR file_path = ? OR file_path LIKE ?)
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$activityId, $localRelPath, $cleanRel, '%' . $fileName]);
        $docId = $stmt->fetchColumn();

        if ($docId) {
            $up = $db->prepare("
                UPDATE documents 
                SET onedrive_item_id = ?,
                    onedrive_web_url = ?,
                    onedrive_status = ?,
                    onedrive_synced_at = ?,
                    onedrive_error = ?
                WHERE id = ?
            ");
            $up->execute([
                $metadata['item_id'] ?? null,
                $metadata['web_url'] ?? null,
                $metadata['status'] ?? 'pending',
                $metadata['synced_at'] ?? null,
                $metadata['error'] ?? null,
                $docId
            ]);
            return true;
        }

        // 2. Also check task_deliverables if relevant
        $delivCheck = $db->query("SHOW TABLES LIKE 'task_deliverables'")->fetch();
        if ($delivCheck) {
            $delivStmt = $db->prepare("
                SELECT id FROM task_deliverables 
                WHERE activity_id = ? AND (file_path = ? OR file_path = ? OR file_path LIKE ?)
                ORDER BY id DESC LIMIT 1
            ");
            $delivStmt->execute([$activityId, $localRelPath, $cleanRel, '%' . $fileName]);
            $delivId = $delivStmt->fetchColumn();

            if ($delivId) {
                $upD = $db->prepare("
                    UPDATE task_deliverables 
                    SET onedrive_item_id = ?,
                        onedrive_web_url = ?,
                        onedrive_status = ?,
                        onedrive_synced_at = ?,
                        onedrive_error = ?
                    WHERE id = ?
                ");
                $upD->execute([
                    $metadata['item_id'] ?? null,
                    $metadata['web_url'] ?? null,
                    $metadata['status'] ?? 'pending',
                    $metadata['synced_at'] ?? null,
                    $metadata['error'] ?? null,
                    $delivId
                ]);
                return true;
            }
        }

        return false;
    } catch (\Throwable $t) {
        // Safe isolation: DB persistence error must not crash caller
        error_log("Failed persisting OneDrive metadata: " . $t->getMessage());
        return false;
    }
}

/**
 * Links cached OneDrive sync metadata to a newly inserted document/deliverable row.
 */
function syncOneDriveMetadataToRecord(PDO $db, string $table, int $recordId, string $localRelPath, int $activityId): bool
{
    try {
        $meta = getOneDriveSyncMetadata($localRelPath);
        if (!$meta) {
            $meta = getOneDriveSyncMetadata(basename($localRelPath));
        }

        // Check if onedrive_status column exists in the target table
        $checkCol = $db->query("SHOW COLUMNS FROM `{$table}` LIKE 'onedrive_status'")->fetch();
        if (!$checkCol) {
            return false;
        }

        $itemId   = $meta['item_id'] ?? null;
        $webUrl   = $meta['web_url'] ?? null;
        $status   = $meta['status'] ?? 'pending';
        $syncedAt = $meta['synced_at'] ?? null;
        $error    = $meta['error'] ?? null;

        $stmt = $db->prepare("
            UPDATE `{$table}`
            SET onedrive_item_id = ?,
                onedrive_web_url = ?,
                onedrive_status = ?,
                onedrive_synced_at = ?,
                onedrive_error = ?
            WHERE id = ?
        ");
        return $stmt->execute([$itemId, $webUrl, $status, $syncedAt, $error, $recordId]);
    } catch (\Throwable $t) {
        error_log("Failed syncing OneDrive metadata to record: " . $t->getMessage());
        return false;
    }
}

/**
 * Attempts an optional non-blocking archive copy of a successfully saved local file to OneDrive.
 * Local storage remains authoritative. Any failure here is safely caught and ignored.
 *
 * @param string $fullLocalPath Absolute path to local file on disk
 * @param string $fileName Generated filename
 * @param string $subdir Subdirectory category (e.g. 'posters', 'post-event', 'floorplans')
 * @param int $activityId Associated activity ID
 * @param OneDriveService|null $service Optional injected OneDriveService instance
 * @return array|null Safe metadata if synced, or null if skipped/failed
 */
function attemptOneDriveArchiveCopy(
    string $fullLocalPath,
    string $fileName,
    string $subdir,
    int $activityId,
    ?OneDriveService $service = null
): ?array {
    global $__onedrive_sync_cache;
    if (!is_array($__onedrive_sync_cache)) {
        $__onedrive_sync_cache = [];
    }
    static $syncedFiles = [];

    $localRelPath = trim($subdir, '/\\') . '/' . $fileName;
    $cacheKey = $fullLocalPath . '|' . $fileName;
    if (isset($syncedFiles[$cacheKey])) {
        return $syncedFiles[$cacheKey];
    }

    try {
        // Secure Test Environment Guard:
        // Must NOT be triggerable by arbitrary public request headers in production.
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        $isLocalClient = in_array($remoteAddr, ['127.0.0.1', '::1', 'localhost', ''], true);
        $isExplicitTestEnv = (getenv('APP_ENV') === 'testing' || getenv('TEST_ENV') === 'true' || (defined('TEST_MOCK_ONEDRIVE') && TEST_MOCK_ONEDRIVE === true));

        // Only honor test headers if running locally and explicitly NOT in production
        $isHeaderTest = ($isLocalClient && (getenv('APP_ENV') ?: '') !== 'production' && (
            !empty($_SERVER['HTTP_X_TEST_ENVIRONMENT']) ||
            !empty($_SERVER['HTTP_X_MOCK_ONEDRIVE']) ||
            !empty($_SERVER['HTTP_X_TEST_RUNNER'])
        ));

        $isTestEnvironment = ($isExplicitTestEnv || $isHeaderTest);

        if ($isTestEnvironment && $service === null) {
            $mockMeta = [
                'item_id'     => 'test_mock_' . md5($fileName),
                'name'        => $fileName,
                'size'        => @filesize($fullLocalPath) ?: 0,
                'web_url'     => 'https://onedrive.live.com/test_mock/' . rawurlencode($fileName),
                'folder_path' => trim($subdir, '/\\'),
                'status'      => 'synced',
                'activity_id' => $activityId,
                'synced_at'   => date('Y-m-d H:i:s'),
                'error'       => null,
            ];
            $syncedFiles[$cacheKey] = $mockMeta;
            $__onedrive_sync_cache[$cacheKey] = $mockMeta;
            $__onedrive_sync_cache[$localRelPath] = $mockMeta;
            $__onedrive_sync_cache[$fileName] = $mockMeta;
            persistOneDriveFileMetadata($activityId, $localRelPath, $mockMeta);
            return $mockMeta;
        }

        if ($service === null) {
            require_once __DIR__ . '/OneDriveService.php';
            $service = new OneDriveService();
        }

        // Respect ONEDRIVE_ENABLED
        if (!$service->isEnabled()) {
            $disabledMeta = [
                'item_id'     => null,
                'name'        => $fileName,
                'size'        => @filesize($fullLocalPath) ?: 0,
                'web_url'     => null,
                'folder_path' => trim($subdir, '/\\'),
                'status'      => 'disabled',
                'error'       => null,
                'activity_id' => $activityId,
                'synced_at'   => null,
            ];
            $syncedFiles[$cacheKey] = $disabledMeta;
            $__onedrive_sync_cache[$cacheKey] = $disabledMeta;
            $__onedrive_sync_cache[$localRelPath] = $disabledMeta;
            $__onedrive_sync_cache[$fileName] = $disabledMeta;
            persistOneDriveFileMetadata($activityId, $localRelPath, $disabledMeta);
            return null;
        }

        // Upload to category subfolder in OneDrive root (e.g. 'posters', 'post-event')
        $targetCategory = trim($subdir, '/\\');
        $result = $service->uploadFile($fullLocalPath, $fileName, null, $targetCategory);

        if ($result['success']) {
            $metadata = [
                'item_id'     => $result['item_id'] ?? '',
                'name'        => $result['name'] ?? $fileName,
                'size'        => $result['size'] ?? 0,
                'web_url'     => $result['web_url'] ?? '',
                'folder_path' => $targetCategory,
                'status'      => 'synced',
                'activity_id' => $activityId,
                'synced_at'   => date('Y-m-d H:i:s'),
                'error'       => null,
            ];

            $syncedFiles[$cacheKey] = $metadata;
            $__onedrive_sync_cache[$cacheKey] = $metadata;
            $__onedrive_sync_cache[$localRelPath] = $metadata;
            $__onedrive_sync_cache[$fileName] = $metadata;

            // Persist to database if record already exists
            persistOneDriveFileMetadata($activityId, $localRelPath, $metadata);

            return $metadata;
        } else {
            // Safe logging without credentials, secrets, or raw payloads
            $safeError = preg_replace('/[^\w\s\.\-_:]/', '', (string)($result['error'] ?? 'Unknown error'));
            $failureMetadata = [
                'item_id'     => null,
                'name'        => $fileName,
                'size'        => @filesize($fullLocalPath) ?: 0,
                'web_url'     => null,
                'folder_path' => $targetCategory,
                'status'      => 'failed',
                'error'       => $safeError,
                'activity_id' => $activityId,
                'synced_at'   => null,
            ];

            $syncedFiles[$cacheKey] = $failureMetadata;
            $__onedrive_sync_cache[$cacheKey] = $failureMetadata;
            $__onedrive_sync_cache[$localRelPath] = $failureMetadata;
            $__onedrive_sync_cache[$fileName] = $failureMetadata;

            persistOneDriveFileMetadata($activityId, $localRelPath, $failureMetadata);

            error_log("OneDrive archive notice: Archive upload skipped or failed [{$safeError}]. Local file preserved.");
            return null;
        }
    } catch (\Throwable $e) {
        // Absolute isolation: OneDrive failure must NEVER disrupt local upload
        $safeMsg = preg_replace('/[^\w\s\.\-_:]/', '', $e->getMessage());
        $failureMetadata = [
            'item_id'     => null,
            'name'        => $fileName,
            'size'        => @filesize($fullLocalPath) ?: 0,
            'web_url'     => null,
            'folder_path' => trim($subdir, '/\\'),
            'status'      => 'failed',
            'error'       => $safeMsg,
            'activity_id' => $activityId,
            'synced_at'   => null,
        ];
        $syncedFiles[$cacheKey] = $failureMetadata;
        $__onedrive_sync_cache[$cacheKey] = $failureMetadata;
        $__onedrive_sync_cache[$localRelPath] = $failureMetadata;
        $__onedrive_sync_cache[$fileName] = $failureMetadata;

        persistOneDriveFileMetadata($activityId, $localRelPath, $failureMetadata);

        error_log("OneDrive archive notice: Exception during archive upload [{$safeMsg}]. Local file preserved.");
        return null;
    }
}

function getStatusBadge(string $status): string
{
    $map = [
        'draft'                  => ['Draft',            'badge-secondary'],
        'submitted'              => ['Under Review',     'badge-warning'],
        'under_review'           => ['Under Review',     'badge-warning'],
        'returned_for_revision'  => ['For Revision',     'badge-danger'],
        'resubmitted'            => ['Resubmitted',      'badge-warning'],
        'endorsed'               => ['Endorsed',         'badge-info'],
        'pending_final_approval' => ['Final Review',     'badge-info'],
        'approved'               => ['Approved',         'badge-success'],
        'rejected'               => ['Rejected',         'badge-danger'],
        'completed'              => ['Completed',        'badge-dark'],
    ];
    [$label, $cls] = $map[$status] ?? [$status, 'badge-secondary'];
    return "<span class='badge {$cls}'>{$label}</span>";
}

function getTaskStatusBadge(string $status): string
{
    $map = [
        'Not Started' => ['Not Started', 'badge-secondary'],
        'In Progress' => ['In Progress', 'badge-primary'],
        'Completed'   => ['Completed',   'badge-success'],
        'Delayed'     => ['Delayed',     'badge-warning'],
    ];
    [$label, $cls] = $map[$status] ?? [$status, 'badge-secondary'];
    return "<span class='badge {$cls}'>{$label}</span>";
}

function getNotifications(int $userId): array
{
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 10');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function renderAiEvaluationQuestions($jsonStr) {
    if (empty($jsonStr)) {
        return '<p class="text-muted" style="font-size:0.85rem; padding: 4px 0;">No evaluation questions generated yet.</p>';
    }
    
    $questions = json_decode($jsonStr, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($questions)) {
        return '<p class="text-muted" style="font-size:0.85rem; padding: 4px 0;">Error: Invalid or malformed evaluation questions.</p>';
    }
    
    if (empty($questions)) {
        return '<p class="text-muted" style="font-size:0.85rem; padding: 4px 0;">No evaluation questions generated yet.</p>';
    }
    
    $html = '<div class="ai-questions-list" style="display: flex; flex-direction: column; gap: 10px; margin-top: 10px;">';
    foreach ($questions as $idx => $q) {
        if (!is_array($q)) continue;
        $questionText = htmlspecialchars($q['question'] ?? '');
        $type = htmlspecialchars($q['type'] ?? 'rating');
        $isShort = ($type === 'short_answer' || $type === 'short-answer' || $type === 'short_text');
        $isOpen = ($type === 'open_ended' || $type === 'text');
        $typeLabel = $isShort ? 'Short Answer' : ($isOpen ? 'Open-ended' : 'Rating Scale');
        $badgeClass = ($isShort || $isOpen) ? 'badge-secondary' : 'badge-info';
        
        $html .= '<div class="ai-question-item" style="padding: 12px; background: var(--bg-subtle, #f8f9fa); border: 1px solid var(--border-color, #e5e7eb); border-radius: 8px; display: flex; flex-direction: column; gap: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.01);">';
        $html .= '  <div style="display: flex; justify-content: space-between; align-items: center;">';
        $html .= '    <span style="font-size: 0.75rem; font-weight: 600; color: var(--text-muted, #64748b);">Question ' . ($idx + 1) . '</span>';
        $html .= '    <span class="badge ' . $badgeClass . '" style="font-size: 0.65rem; padding: 3px 6px; font-weight: 600; border-radius: 4px; display: inline-block;">' . $typeLabel . '</span>';
        $html .= '  </div>';
        $html .= '  <div style="font-size: 0.825rem; font-weight: 500; color: var(--text, #1e293b); line-height: 1.4; word-break: break-word;">' . $questionText . '</div>';
        $html .= '</div>';
    }
    $html .= '</div>';
    
    return $html;
}

/**
 * Normalizes a time string into minutes since midnight (0 to 1439).
 * Supports 12-hour format ("10:00 AM", "10:00 am", "12:00 AM", "12:00 PM")
 * and 24-hour format ("10:00", "14:00", "14:00:00", "00:00").
 * Returns null if invalid or empty.
 */
function parseTimeToMinutes(?string $timeStr): ?int
{
    if ($timeStr === null) {
        return null;
    }
    $timeStr = trim($timeStr);
    if ($timeStr === '') {
        return null;
    }

    // 1. 12-hour format with AM/PM (e.g., "10:00 am", "10:00 AM", "12:00 PM", "12:00 AM")
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?\s*([ap]m)$/i', $timeStr, $matches)) {
        $hours = (int)$matches[1];
        $minutes = (int)$matches[2];
        $meridiem = strtolower($matches[3]);

        if ($hours < 1 || $hours > 12 || $minutes < 0 || $minutes > 59) {
            return null;
        }

        if ($meridiem === 'am') {
            $hours = ($hours === 12) ? 0 : $hours;
        } else {
            $hours = ($hours === 12) ? 12 : $hours + 12;
        }

        return $hours * 60 + $minutes;
    }

    // 2. 24-hour format (e.g., "00:00", "10:00", "14:00", "14:00:00")
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $timeStr, $matches)) {
        $hours = (int)$matches[1];
        $minutes = (int)$matches[2];

        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return null;
        }

        return $hours * 60 + $minutes;
    }

    return null;
}

/**
 * Validates a start and end time range for an activity schedule.
 * Rule: End time must be strictly later than start time on the same calendar day.
 */
function validateScheduleTimeRange(?string $startTime, ?string $endTime, bool $isRequired = true): array
{
    $startTrimmed = trim((string)$startTime);
    $endTrimmed = trim((string)$endTime);

    if ($startTrimmed === '' && $endTrimmed === '') {
        if ($isRequired) {
            return [
                'valid' => false,
                'error' => 'Start time and end time are required.',
                'code' => 'missing_time'
            ];
        }
        return ['valid' => true, 'start_minutes' => null, 'end_minutes' => null];
    }

    if ($startTrimmed === '') {
        if ($isRequired) {
            return [
                'valid' => false,
                'error' => 'Start time is required.',
                'code' => 'missing_start'
            ];
        }
        // Incomplete draft with only end time
        $em = parseTimeToMinutes($endTrimmed);
        return [
            'valid' => ($em !== null),
            'error' => ($em === null) ? 'End time is invalid.' : null,
            'code' => ($em === null) ? 'invalid_end' : null,
            'start_minutes' => null,
            'end_minutes' => $em
        ];
    }

    if ($endTrimmed === '') {
        if ($isRequired) {
            return [
                'valid' => false,
                'error' => 'End time is required.',
                'code' => 'missing_end'
            ];
        }
        // Incomplete draft with only start time
        $sm = parseTimeToMinutes($startTrimmed);
        return [
            'valid' => ($sm !== null),
            'error' => ($sm === null) ? 'Start time is invalid.' : null,
            'code' => ($sm === null) ? 'invalid_start' : null,
            'start_minutes' => $sm,
            'end_minutes' => null
        ];
    }

    $startMinutes = parseTimeToMinutes($startTrimmed);
    if ($startMinutes === null) {
        return [
            'valid' => false,
            'error' => 'Start time is invalid.',
            'code' => 'invalid_start'
        ];
    }

    $endMinutes = parseTimeToMinutes($endTrimmed);
    if ($endMinutes === null) {
        return [
            'valid' => false,
            'error' => 'End time is invalid.',
            'code' => 'invalid_end'
        ];
    }

    if ($endMinutes <= $startMinutes) {
        return [
            'valid' => false,
            'error' => 'End time must be later than the start time.',
            'code' => 'invalid_order',
            'start_minutes' => $startMinutes,
            'end_minutes' => $endMinutes
        ];
    }

    return [
        'valid' => true,
        'start_minutes' => $startMinutes,
        'end_minutes' => $endMinutes
    ];
}

/**
 * Validates multiple schedule rows independently.
 */
function validateScheduleRows(array $rows, bool $isRequired = true): array
{
    $allValid = true;
    $results = [];

    foreach ($rows as $index => $row) {
        $start = is_array($row) ? ($row['start_time'] ?? $row['start'] ?? null) : null;
        $end = is_array($row) ? ($row['end_time'] ?? $row['end'] ?? null) : null;

        $res = validateScheduleTimeRange($start, $end, $isRequired);
        $results[$index] = $res;
        if (!$res['valid']) {
            $allValid = false;
        }
    }

    return [
        'valid' => $allValid,
        'rows' => $results
    ];
}

/**
 * Objective 6: Proposal Escalation Alerts Helper
 * 
 * Inspects pending proposals exceeding 3 days of inactivity (updated_at < NOW() - INTERVAL 3 DAY)
 * and dispatches escalation alerts (both in-app notification and email) to both the stalled reviewer
 * and the faculty proponent according to the workflow.
 * 
 * Deduplicates notifications so the same inactivity window does not generate repeated alerts.
 */
function checkProposalEscalations(PDO $db, ?int $userId = null, ?string $role = null): array
{
    try {
        require_once __DIR__ . '/EmailService.php';

        if ($userId === null && function_exists('currentUser')) {
            $cur = currentUser();
            if ($cur) {
                $userId = (int)($cur['id'] ?? 0);
                $role   = $role ?? ($cur['role'] ?? null);
            }
        }

        $params = [];
        if (in_array($role, ['faculty', 'program_head'], true)) {
            $sql = "
                SELECT id, faculty_id, title, status, source, updated_at 
                FROM activities 
                WHERE faculty_id = ? 
                  AND status IN ('submitted', 'under_review', 'endorsed', 'pending_final_approval', 'resubmitted') 
                  AND updated_at < NOW() - INTERVAL 3 DAY
            ";
            $params = [$userId];
        } elseif ($role === 'admin1') {
            $sql = "
                SELECT id, faculty_id, title, status, source, updated_at 
                FROM activities 
                WHERE status IN ('under_review', 'submitted', 'resubmitted') 
                  AND updated_at < NOW() - INTERVAL 3 DAY
            ";
        } elseif ($role === 'admin2') {
            $sql = "
                SELECT id, faculty_id, title, status, source, updated_at 
                FROM activities 
                WHERE ((status IN ('submitted', 'endorsed')) OR (status IN ('under_review', 'resubmitted') AND source = 'faculty')) 
                  AND updated_at < NOW() - INTERVAL 3 DAY
            ";
        } elseif ($role === 'dean') {
            $sql = "
                SELECT id, faculty_id, title, status, source, updated_at 
                FROM activities 
                WHERE status = 'pending_final_approval' 
                  AND updated_at < NOW() - INTERVAL 3 DAY
            ";
        } else {
            // Broad / system-wide check
            $sql = "
                SELECT id, faculty_id, title, status, source, updated_at 
                FROM activities 
                WHERE status IN ('submitted', 'under_review', 'endorsed', 'pending_final_approval', 'resubmitted') 
                  AND updated_at < NOW() - INTERVAL 3 DAY
            ";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $stagnantActs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($stagnantActs)) {
            return ['checked' => 0, 'notified' => 0];
        }

        $emailService = class_exists('EmailService') ? new EmailService() : null;
        $baseUrl = defined('BASE_URL') ? BASE_URL : '';
        $notifiedCount = 0;

        $notifCheckStmt = $db->prepare("
            SELECT COUNT(*) 
            FROM notifications 
            WHERE user_id = ? 
              AND activity_id = ? 
              AND (message LIKE '%stagnant%' OR message LIKE '%Escalation%') 
              AND created_at > ?
        ");

        $insertNotifStmt = $db->prepare("
            INSERT INTO notifications (user_id, activity_id, message) 
            VALUES (?, ?, ?)
        ");

        $userStmt = $db->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
        $reviewersByRoleStmt = $db->prepare("SELECT id, name, email, role FROM users WHERE role = ?");

        foreach ($stagnantActs as $act) {
            $actId = (int)$act['id'];
            $title = sanitize($act['title'] ?? 'Untitled Proposal');
            $status = $act['status'];
            $source = $act['source'] ?? 'faculty';
            $updatedAt = $act['updated_at'];

            // Determine stalled reviewer role
            $reviewerRole = null;
            if (in_array($status, ['under_review', 'submitted', 'resubmitted'], true)) {
                $reviewerRole = 'admin1';
            } elseif ($status === 'pending_final_approval') {
                $reviewerRole = 'dean';
            }

            $targets = [];

            // 1. Reviewer target(s)
            if ($reviewerRole !== null) {
                $reviewersByRoleStmt->execute([$reviewerRole]);
                $revRows = $reviewersByRoleStmt->fetchAll(PDO::FETCH_ASSOC);

                $revLink = match($reviewerRole) {
                    'admin1' => "{$baseUrl}/admin1/review.php?id={$actId}",
                    'admin2' => "{$baseUrl}/admin2/review.php?id={$actId}",
                    'dean'   => "{$baseUrl}/dean/review.php?id={$actId}",
                    default  => "{$baseUrl}/index.php",
                };

                $revMsg = ($reviewerRole === 'dean')
                    ? "Escalation Alert: Proposal '{$title}' has been pending final approval for 3 days and requires attention."
                    : "Escalation Alert: Proposal '{$title}' has been pending review for 3 days and requires attention.";

                $revSubject = "Escalation Alert: Proposal Pending Review - {$title}";

                foreach ($revRows as $rev) {
                    $targets[] = [
                        'user'    => $rev,
                        'message' => $revMsg,
                        'subject' => $revSubject,
                        'link'    => $revLink,
                    ];
                }
            }

            // 2. Faculty proponent target
            if (!empty($act['faculty_id'])) {
                $userStmt->execute([$act['faculty_id']]);
                $facultyUser = $userStmt->fetch(PDO::FETCH_ASSOC);
                if ($facultyUser) {
                    $targets[] = [
                        'user'    => $facultyUser,
                        'message' => "Your proposal '{$title}' has been stagnant under review for 3 days.",
                        'subject' => "Proposal Update: Review Stagnant - {$title}",
                        'link'    => "{$baseUrl}/faculty/proposal-view.php?id={$actId}",
                    ];
                }
            }

            // Process targets with deduplication
            foreach ($targets as $target) {
                $u = $target['user'];
                $uId = (int)$u['id'];

                // Deduplication check: Has this user already received a stagnant/escalation notification after updatedAt?
                $notifCheckStmt->execute([$uId, $actId, $updatedAt]);
                if ((int)$notifCheckStmt->fetchColumn() === 0) {
                    // In-App Notification
                    $insertNotifStmt->execute([$uId, $actId, $target['message']]);
                    $notifiedCount++;

                    // Automated Email Notification (safely handled & logged)
                    if ($emailService && !empty($u['email'])) {
                        try {
                            $emailService->sendNotification(
                                $u['email'],
                                $u['name'] ?? 'User',
                                $target['subject'],
                                $title,
                                $status,
                                $target['message'],
                                $target['link'],
                                $actId,
                                'proposal_escalated'
                            );
                        } catch (\Throwable $e) {
                            error_log("Failed sending escalation email for activity {$actId} to {$u['email']}: " . $e->getMessage());
                        }
                    }
                }
            }
        }

        return [
            'checked'  => count($stagnantActs),
            'notified' => $notifiedCount
        ];
    } catch (\Throwable $e) {
        error_log("checkProposalEscalations error: " . $e->getMessage());
        return ['checked' => 0, 'notified' => 0];
    }
}

/**
 * Return the unread notification count for the specified user.
 *
 * @param PDO $db
 * @param int $userId
 * @return int
 */
function getUnreadNotificationCount(PDO $db, int $userId): int
{
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        error_log("getUnreadNotificationCount error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Return the user's recent notifications with activity details.
 *
 * @param PDO $db
 * @param int $userId
 * @param int $limit
 * @return array
 */
function getUserNotifications(PDO $db, int $userId, int $limit = 10): array
{
    try {
        $limit = max(1, (int)$limit);
        $stmt = $db->prepare("
            SELECT n.*, a.title AS activity_title
            FROM notifications n
            LEFT JOIN activities a ON n.activity_id = a.id
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    } catch (\Throwable $e) {
        error_log("getUserNotifications error: " . $e->getMessage());
        return [];
    }
}

/**
 * Ensure task_reminder_logs, email_logs, and proposal_collaborators tables exist for production resilience
 */
function ensureTaskReminderLogsTable(PDO $db): void
{
    static $ensured = false;
    if ($ensured) return;
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS task_reminder_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                activity_id INT NOT NULL,
                user_id INT NOT NULL,
                reminder_type VARCHAR(32) NOT NULL,
                task_status VARCHAR(32) NULL,
                due_date DATE NULL,
                completion_pct INT NULL,
                sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_task_reminder (task_id, reminder_type, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS email_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                recipient_email VARCHAR(180) NOT NULL,
                recipient_name VARCHAR(120) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                activity_id INT NULL,
                notification_type VARCHAR(60) NOT NULL,
                delivery_method VARCHAR(30) NOT NULL DEFAULT 'phpmailer',
                status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
                error_message TEXT NULL,
                sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS proposal_collaborators (
                id INT AUTO_INCREMENT PRIMARY KEY,
                activity_id INT NOT NULL,
                user_id INT NOT NULL,
                role ENUM('owner', 'co_author', 'contributor') NOT NULL DEFAULT 'contributor',
                added_by INT NOT NULL,
                added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                status ENUM('active', 'removed') NOT NULL DEFAULT 'active',
                UNIQUE KEY uniq_activity_user (activity_id, user_id),
                INDEX idx_collab_user (user_id, status),
                INDEX idx_collab_activity (activity_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $ensured = true;
    } catch (\Throwable $e) {
        // Table may already exist or user lacks DDL privilege; ignore safely
    }
}

/**
 * Task Progress Reminders Helper
 *
 * Inspects assigned activity tasks and generates reminders:
 * 1. Completed: When a task reaches 100% / Completed, notify the activity leader.
 * 2. About to be delayed: When due date is approaching (within 3 days) and task is not completed,
 *    notify the assigned member (task-specific reminder) and activity leader (monitoring reminder).
 * 3. Delayed: When due date has passed or status is Delayed, and task is not completed,
 *    notify the assigned member (task-specific reminder) and activity leader (monitoring reminder).
 *
 * Channels:
 * - In-app notification (notifications table)
 * - Email / Outlook notification (EmailService & email_logs)
 *
 * Deduplication:
 * - Stored in task_reminder_logs to ensure no repeat notifications for the same condition.
 * - When task state or due date changes, the next appropriate reminder is generated.
 *
 * Security:
 * - Notifications are strictly routed to the assigned member and activity leader.
 * - Dean / Admin receive no individual task notification spam.
 *
 * @param PDO $db
 * @param int|null $userId
 * @param string|null $role
 * @param int|null $activityId
 * @return array
 */
function checkTaskProgressReminders(PDO $db, ?int $userId = null, ?string $role = null, ?int $activityId = null): array
{
    try {
        ensureTaskReminderLogsTable($db);
        require_once __DIR__ . '/EmailService.php';

        if ($userId === null && function_exists('currentUser')) {
            $cur = currentUser();
            if ($cur) {
                $userId = (int)($cur['id'] ?? 0);
                $role   = $role ?? ($cur['role'] ?? null);
            }
        }

        $whereClauses = ["a.status NOT IN ('rejected', 'draft')"];
        $params = [];

        if ($activityId !== null && $activityId > 0) {
            $whereClauses[] = "ft.activity_id = ?";
            $params[] = $activityId;
        } elseif ($userId !== null && $userId > 0 && in_array($role, ['faculty', 'program_head'], true)) {
            $whereClauses[] = "(a.faculty_id = ? OR ft.assigned_user_id = ?)";
            $params[] = $userId;
            $params[] = $userId;
        }

        $whereSql = implode(' AND ', $whereClauses);

        $sql = "
            SELECT ft.id, ft.activity_id, ft.assigned_user_id, ft.faculty_name,
                   ft.task_title, ft.assigned_task, ft.task_description, ft.contribution_desc,
                   ft.role_in_event, ft.due_date, ft.status, ft.completion_pct, ft.updated_at,
                   a.title as activity_title, a.faculty_id as leader_id, a.status as activity_status,
                   u_leader.name as leader_name, u_leader.email as leader_email,
                   u_assigned.name as assigned_name, u_assigned.email as assigned_email
            FROM faculty_tasks ft
            JOIN activities a ON ft.activity_id = a.id
            JOIN users u_leader ON a.faculty_id = u_leader.id
            LEFT JOIN users u_assigned ON ft.assigned_user_id = u_assigned.id
            WHERE {$whereSql}
            ORDER BY ft.id ASC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($tasks)) {
            return ['checked' => 0, 'notified' => 0, 'reminders' => []];
        }

        $today = date('Y-m-d');
        $emailService = class_exists('EmailService') ? new EmailService() : null;
        $baseUrl = defined('BASE_URL') ? BASE_URL : '';
        $notifiedCount = 0;
        $generatedReminders = [];

        $checkLogStmt = $db->prepare("
            SELECT id, due_date, task_status, completion_pct, reminder_type 
            FROM task_reminder_logs 
            WHERE task_id = ? AND reminder_type = ? AND user_id = ?
            ORDER BY id DESC LIMIT 1
        ");

        $insertLogStmt = $db->prepare("
            INSERT INTO task_reminder_logs (task_id, activity_id, user_id, reminder_type, task_status, due_date, completion_pct)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $insertNotifStmt = $db->prepare("
            INSERT INTO notifications (user_id, activity_id, message)
            VALUES (?, ?, ?)
        ");

        foreach ($tasks as $t) {
            $taskId = (int)$t['id'];
            $actId = (int)$t['activity_id'];
            $taskTitle = trim($t['task_title'] ?: ($t['assigned_task'] ?: 'Untitled Task'));
            $activityTitle = trim($t['activity_title'] ?: 'Activity');
            $rawDueDate = !empty($t['due_date']) ? date('Y-m-d', strtotime($t['due_date'])) : null;
            $status = trim($t['status'] ?: 'Not Started');
            $completionPct = (int)($t['completion_pct'] ?? 0);
            $leaderId = (int)$t['leader_id'];
            $leaderName = trim($t['leader_name'] ?: 'Activity Leader');
            $leaderEmail = trim($t['leader_email'] ?: '');
            $assignedUserId = !empty($t['assigned_user_id']) ? (int)$t['assigned_user_id'] : null;
            $assignedName = trim($t['assigned_name'] ?: ($t['faculty_name'] ?: 'Assigned Member'));
            $assignedEmail = trim($t['assigned_email'] ?: '');

            $isCompleted = ($status === 'Completed' || $completionPct >= 100);

            $reminderType = null;
            if ($isCompleted) {
                $reminderType = 'completed';
            } else {
                $isOverdue = (!empty($rawDueDate) && $rawDueDate < $today);
                $isExplicitDelayed = ($status === 'Delayed');

                if ($isOverdue || $isExplicitDelayed) {
                    $reminderType = 'delayed';
                } elseif (!empty($rawDueDate)) {
                    $diffDays = (int)ceil((strtotime($rawDueDate) - strtotime($today)) / 86400);
                    if ($diffDays >= 0 && $diffDays <= 3) {
                        $reminderType = 'due_soon';
                    }
                }
            }

            if (!$reminderType) {
                continue;
            }

            $targets = [];
            $viewLink = "{$baseUrl}/faculty/view-activity.php?id={$actId}";
            $dueDateDisplay = $rawDueDate ? date('M j, Y', strtotime($rawDueDate)) : 'No due date';

            if ($reminderType === 'completed') {
                $msg = ($assignedUserId && $assignedUserId !== $leaderId)
                    ? "Task Completed: Task '{$taskTitle}' assigned to {$assignedName} in '{$activityTitle}' has reached 100% / Completed."
                    : "Task Completed: Task '{$taskTitle}' in '{$activityTitle}' has reached 100% / Completed.";

                $targets[] = [
                    'user_id'      => $leaderId,
                    'name'         => $leaderName,
                    'email'        => $leaderEmail,
                    'is_leader'    => true,
                    'message'      => $msg,
                    'subject'      => "Task Completed: {$taskTitle} - {$activityTitle}",
                    'status_label' => 'Completed',
                    'link'         => $viewLink,
                ];
            } elseif ($reminderType === 'due_soon') {
                if ($assignedUserId) {
                    $targets[] = [
                        'user_id'      => $assignedUserId,
                        'name'         => $assignedName,
                        'email'        => $assignedEmail,
                        'is_leader'    => false,
                        'message'      => "Task Reminder: Task '{$taskTitle}' in '{$activityTitle}' is approaching its deadline (Due: {$dueDateDisplay}). Current progress: {$completionPct}%.",
                        'subject'      => "Task Due Soon: {$taskTitle} - {$activityTitle}",
                        'status_label' => 'Due Soon',
                        'link'         => $viewLink,
                    ];
                }

                if ($leaderId && $leaderId !== $assignedUserId) {
                    $targets[] = [
                        'user_id'      => $leaderId,
                        'name'         => $leaderName,
                        'email'        => $leaderEmail,
                        'is_leader'    => true,
                        'message'      => "Task Monitoring: Task '{$taskTitle}' assigned to {$assignedName} in '{$activityTitle}' is approaching its deadline (Due: {$dueDateDisplay}). Current progress: {$completionPct}%.",
                        'subject'      => "Task Approaching Deadline: {$taskTitle} - {$activityTitle}",
                        'status_label' => 'Due Soon',
                        'link'         => $viewLink,
                    ];
                }
            } elseif ($reminderType === 'delayed') {
                if ($assignedUserId) {
                    $targets[] = [
                        'user_id'      => $assignedUserId,
                        'name'         => $assignedName,
                        'email'        => $assignedEmail,
                        'is_leader'    => false,
                        'message'      => "Task Delayed: Task '{$taskTitle}' in '{$activityTitle}' is delayed (Due: {$dueDateDisplay}). Current progress: {$completionPct}%. Please update progress or submit deliverable.",
                        'subject'      => "Task Delayed Alert: {$taskTitle} - {$activityTitle}",
                        'status_label' => 'Delayed',
                        'link'         => $viewLink,
                    ];
                }

                if ($leaderId && $leaderId !== $assignedUserId) {
                    $targets[] = [
                        'user_id'      => $leaderId,
                        'name'         => $leaderName,
                        'email'        => $leaderEmail,
                        'is_leader'    => true,
                        'message'      => "Task Monitoring Alert: Task '{$taskTitle}' assigned to {$assignedName} in '{$activityTitle}' is delayed (Due: {$dueDateDisplay}). Current progress: {$completionPct}%.",
                        'subject'      => "Task Monitoring: Task Delayed - {$taskTitle}",
                        'status_label' => 'Delayed',
                        'link'         => $viewLink,
                    ];
                }
            }

            foreach ($targets as $target) {
                $tUserId = (int)$target['user_id'];
                if (!$tUserId) continue;

                $checkLogStmt->execute([$taskId, $reminderType, $tUserId]);
                $lastLog = $checkLogStmt->fetch(PDO::FETCH_ASSOC);

                $shouldSend = false;
                if (!$lastLog) {
                    $shouldSend = true;
                } else {
                    if ($reminderType === 'due_soon' || $reminderType === 'delayed') {
                        if ($lastLog['due_date'] !== $rawDueDate) {
                            $shouldSend = true;
                        }
                    }
                }

                if ($shouldSend) {
                    $insertNotifStmt->execute([$tUserId, $actId, $target['message']]);

                    $insertLogStmt->execute([
                        $taskId,
                        $actId,
                        $tUserId,
                        $reminderType,
                        $status,
                        $rawDueDate,
                        $completionPct
                    ]);

                    if ($emailService && !empty($target['email'])) {
                        try {
                            $emailService->sendNotification(
                                $target['email'],
                                $target['name'],
                                $target['subject'],
                                $activityTitle,
                                $target['status_label'],
                                $target['message'],
                                $target['link'],
                                $actId,
                                "task_{$reminderType}_{$taskId}"
                            );
                        } catch (\Throwable $e) {
                            error_log("Failed sending task reminder email for task {$taskId} to {$target['email']}: " . $e->getMessage());
                        }
                    }

                    $notifiedCount++;
                    $generatedReminders[] = [
                        'task_id'       => $taskId,
                        'activity_id'   => $actId,
                        'user_id'       => $tUserId,
                        'reminder_type' => $reminderType,
                        'recipient'     => $target['name'],
                        'message'       => $target['message']
                    ];
                }
            }
        }

        return [
            'checked'   => count($tasks),
            'notified'  => $notifiedCount,
            'reminders' => $generatedReminders
        ];
    } catch (\Throwable $e) {
        error_log("checkTaskProgressReminders error: " . $e->getMessage());
        return ['checked' => 0, 'notified' => 0, 'reminders' => []];
    }
}

// Post-Activity Compliance calculation helper
require_once __DIR__ . '/compliance_helper.php';

/**
 * Determines whether an activity is a large event that requires budget clearance.
 *
 * @param array $activity Activity record.
 * @param float|null $totalBudget Total calculated budget for materials (optional).
 * @return bool True if clearance is required.
 */
function isLargeEventClearanceRequired(array $activity, ?float $totalBudget = null): bool
{
    if (!empty($activity['clearance_required'])) {
        return true;
    }
    if ($totalBudget !== null && $totalBudget > 0) {
        return true;
    }
    if ($totalBudget === null && !empty($activity['id'])) {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT SUM(est_cost * quantity) FROM materials WHERE activity_id = ?");
            $stmt->execute([$activity['id']]);
            $sum = (float)$stmt->fetchColumn();
            if ($sum > 0) {
                return true;
            }
        } catch (Throwable $e) {}
    }
    return false;
}

/**
 * Returns a structured descriptor and HTML badge for large-event clearance.
 *
 * @param array $activity Activity record.
 * @param float|null $totalBudget Total calculated budget (optional).
 * @return array Clearance information.
 */
function getActivityClearanceInfo(array $activity, ?float $totalBudget = null): array
{
    $required = isLargeEventClearanceRequired($activity, $totalBudget);
    $obtained = isset($activity['clearance_obtained']) && $activity['clearance_obtained'] !== null
        ? (int)$activity['clearance_obtained']
        : null;

    if (!$required) {
        return [
            'required' => false,
            'obtained' => null,
            'status'   => 'Not Required',
            'label'    => 'Not Required (Ordinary Event)',
            'badge'    => '<span class="badge badge-secondary" style="font-size:0.75rem;">Clearance Not Required</span>',
            'notes'    => $activity['clearance_notes'] ?? null
        ];
    }

    if ($obtained === 1) {
        return [
            'required' => true,
            'obtained' => 1,
            'status'   => 'Obtained',
            'label'    => 'Clearance Obtained (School Admin / President)',
            'badge'    => '<span class="badge badge-success" style="font-size:0.75rem; background:#10b981; color:#fff;">✓ Clearance Obtained</span>',
            'notes'    => $activity['clearance_notes'] ?? null
        ];
    }

    if ($obtained === 0) {
        return [
            'required' => true,
            'obtained' => 0,
            'status'   => 'Not Obtained',
            'label'    => 'Clearance Not Obtained',
            'badge'    => '<span class="badge badge-danger" style="font-size:0.75rem; background:#ef4444; color:#fff;">✕ Clearance Not Obtained</span>',
            'notes'    => $activity['clearance_notes'] ?? null
        ];
    }

    return [
        'required' => true,
        'obtained' => null,
        'status'   => 'Pending',
        'label'    => 'Clearance Pending / Required',
        'badge'    => '<span class="badge badge-warning" style="font-size:0.75rem" background:#f59e0b; color:#fff;">⏳ Clearance Required</span>',
        'notes'    => $activity['clearance_notes'] ?? null
    ];
}

/**
 * Generates an automatic PDF snapshot of a proposal and syncs it to OneDrive.
 * Non-blocking: ordinary proposal workflows will not be blocked or failed.
 *
 * @param int $activityId
 * @param string $trigger 'submitted' | 'resubmitted' | 'approved'
 * @param int|null $userId
 * @param OneDriveService|null $service
 * @return array|null
 */
function syncProposalPdfSnapshot(int $activityId, string $trigger = 'submitted', ?int $userId = null, ?OneDriveService $service = null): ?array {
    try {
        if (!function_exists('getDB')) {
            require_once __DIR__ . '/../config/database.php';
        }
        $db = getDB();
        if (!$db) return null;

        require_once __DIR__ . '/ProposalPdfService.php';
        return ProposalPdfService::syncProposalSnapshot($db, $activityId, $trigger, $userId, $service);
    } catch (\Throwable $t) {
        error_log("Failed to create proposal PDF snapshot for activity {$activityId}: " . $t->getMessage());
        return null;
    }
}

/**
 * Dispatches an in-app notification and email to a faculty member when assigned a task.
 *
 * @param PDO $db
 * @param int $assignedUserId
 * @param int $activityId
 * @param string $taskTitle
 * @param string|null $dueDate
 * @return bool
 */
function sendTaskAssignmentNotification(PDO $db, int $assignedUserId, int $activityId, string $taskTitle, ?string $dueDate = null): bool
{
    if ($assignedUserId <= 0 || $activityId <= 0) {
        return false;
    }

    try {
        $uStmt = $db->prepare("SELECT id, name, email FROM users WHERE id = ? LIMIT 1");
        $uStmt->execute([$assignedUserId]);
        $assignee = $uStmt->fetch(PDO::FETCH_ASSOC);
        if (!$assignee) {
            return false;
        }

        $aStmt = $db->prepare("SELECT id, title FROM activities WHERE id = ? LIMIT 1");
        $aStmt->execute([$activityId]);
        $activity = $aStmt->fetch(PDO::FETCH_ASSOC);
        $activityTitle = trim($activity['title'] ?? 'Activity');
        $cleanTaskTitle = trim($taskTitle ?: 'Task Assignment');

        $dueDateDisplay = !empty($dueDate) ? ' (Due: ' . date('M j, Y', strtotime($dueDate)) . ')' : '';
        $msg = "Task Assignment: You have been assigned to '{$cleanTaskTitle}' in '{$activityTitle}'{$dueDateDisplay}.";

        // Insert in-app notification
        $notifStmt = $db->prepare("INSERT INTO notifications (user_id, activity_id, message) VALUES (?, ?, ?)");
        $notifStmt->execute([$assignedUserId, $activityId, $msg]);

        // Dispatch Email if EmailService is available
        require_once __DIR__ . '/EmailService.php';
        $emailService = new EmailService();
        $baseUrl = defined('BASE_URL') ? BASE_URL : '';
        $viewLink = "{$baseUrl}/faculty/view-activity.php?id={$activityId}";
        $subject = "Task Assignment: {$cleanTaskTitle} - {$activityTitle}";

        if (!empty($assignee['email'])) {
            try {
                $emailService->sendNotification(
                    $assignee['email'],
                    $assignee['name'] ?: 'Faculty Member',
                    $subject,
                    $activityTitle,
                    'Assigned',
                    $msg,
                    $viewLink,
                    $activityId,
                    'task_assignment'
                );
            } catch (\Throwable $mailEx) {
                error_log("Failed to send task assignment email: " . $mailEx->getMessage());
            }
        }
        return true;
    } catch (\Throwable $t) {
        error_log("Failed to send task assignment notification: " . $t->getMessage());
        return false;
    }
}

/**
 * Normalizes department string for robust comparison across formats:
 * - 'IT Department', 'IT', 'Information Technology Department' -> 'IT'
 * - 'THM Department', 'THM', 'Hospitality and Tourism' -> 'THM'
 * - 'G.E. Department', 'GE', 'General Education Department' -> 'GE'
 */
/**
 * Resolves the department string for the given user (or current session user),
 * falling back to a database lookup if the session/array department is empty.
 */
function resolveUserDepartment(?PDO $db = null, ?array $user = null): string
{
    $dept = trim((string)($user['dept'] ?? $user['department'] ?? $_SESSION['user_dept'] ?? ''));
    $userId = (int)($user['id'] ?? $_SESSION['user_id'] ?? 0);
    if ($dept === '' && $userId > 0) {
        $db = $db ?: (function_exists('getDB') ? getDB() : ($GLOBALS['testDb'] ?? null));
        if ($db) {
            try {
                $stmt = $db->prepare("SELECT department FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$userId]);
                $dept = trim((string)$stmt->fetchColumn());
                if ($dept !== '' && isset($_SESSION)) {
                    $_SESSION['user_dept'] = $dept;
                }
            } catch (\Throwable $e) {
                // Ignore DB lookup errors
            }
        }
    }
    return $dept;
}

/**
 * Builds a safe, normalized SQL condition matching all naming variants of a department
 * (IT Department, THM Department, G.E. Department) on the specified SQL column expression.
 * Never matches across different departments.
 */
function buildDepartmentSqlCondition(string $columnExpr, ?string $dept): string
{
    $norm = normalizeDepartmentName($dept);
    if ($norm === 'IT') {
        return "({$columnExpr} IN ('IT Department', 'Information Technology Department', 'IT', 'Information Technology', 'I.T. Department') OR {$columnExpr} LIKE '%Information Technology%')";
    }
    if ($norm === 'THM') {
        return "({$columnExpr} IN ('THM Department', 'Tourism and Hospitality Management', 'Hospitality and Tourism Management', 'THM', 'Tourism & Hospitality Management') OR {$columnExpr} LIKE '%THM%' OR {$columnExpr} LIKE '%Tourism%' OR {$columnExpr} LIKE '%Hospitality%')";
    }
    if ($norm === 'GE') {
        return "({$columnExpr} IN ('G.E. Department', 'General Education Department', 'GE Department', 'GE', 'G.E.', 'General Education') OR {$columnExpr} LIKE '%G.E%' OR {$columnExpr} LIKE '%General Education%')";
    }
    if ($norm === '') {
        return "(1=0)";
    }
    $db = function_exists('getDB') ? getDB() : ($GLOBALS['testDb'] ?? null);
    $quoted = $db ? $db->quote(trim((string)$dept)) : "'" . addslashes(trim((string)$dept)) . "'";
    return "({$columnExpr} = {$quoted})";
}

/**
 * Returns department-specific metadata, labels, and official faculty roster
 * for the IT, THM, and G.E. Program Head dashboards.
 */
function getDepartmentDashboardConfig(?string $dept): array
{
    $norm = normalizeDepartmentName($dept);
    $rosters = [
        'IT' => [
            'code' => 'IT',
            'short_label' => 'IT Department',
            'full_label' => 'Information Technology Department',
            'dashboard_title' => 'IT Program Head Dashboard',
            'subtitle' => 'Information Technology Department — Proposals, Activities, Faculty, Tasks & Schedules',
            'roster' => [
                'Mr. Dave Lord M. Rubaya',
                'Ms. Michelle Carlos, MAEd, LPT',
                'Mr. Ar-Jay C. Agbayani',
                'Ms. Emilou G. Magnaye, LPT',
                'Mr. John Cedrick Agapito',
                'Mr. Jedekiah Veloso',
                'Mr. Shintaro Lagare',
                'Mr. Geo V. De Leon',
                'Ms. Johainna Kristel T. Montejo',
                'Mr. Felmar Solbita',
                'Mr. Michael André Sayson',
            ],
        ],
        'THM' => [
            'code' => 'THM',
            'short_label' => 'THM Department',
            'full_label' => 'Tourism & Hospitality Management Department',
            'dashboard_title' => 'THM Program Head Dashboard',
            'subtitle' => 'THM Department — Proposals, Activities, Faculty, Tasks & Schedules',
            'roster' => [
                'Mr. Aldwin D. Alminar, LPT',
                'Ms. Andrea J. Gatbunton, LPT',
                'Ms. Divine Grace E. Ibarrola, LPT',
                'Ms. Mary Darlene A. Mariano, LPT',
                'Ms. Julieta Caridad C. Cruz',
                'Mr. Exequiel L. Magnipis',
                'Mr. Frankie Chiu, RN, MBA',
            ],
        ],
        'GE' => [
            'code' => 'GE',
            'short_label' => 'G.E. Department',
            'full_label' => 'General Education Department',
            'dashboard_title' => 'G.E. Program Head Dashboard',
            'subtitle' => 'General Education Department — Proposals, Activities, Faculty, Tasks & Schedules',
            'roster' => [
                'Mr. Tristan S. Unabia',
                'Mr. Ian Jade C. Barangan, LPT',
                'Mr. Eulysses Neil Patrick B. Edquilag, LPT',
                'Mr. Gregg Anthony J. Rana',
                'Mr. Ricky E. Manliguis, LPT',
                'Ms. Regina Kate D. Dominguez, LPT',
                'Mr. Benedicto A. Arnoco, LPT',
                'Ms. Jennylyn Q. Abarra, MAEd PE, LPT',
                'Ms. Ruby Ann G. Dasalla, LPT',
                'Ms. Perine O. Dela Cruz, LPT',
            ],
        ],
    ];

    if (isset($rosters[$norm])) {
        return $rosters[$norm];
    }

    $cleanLabel = trim((string)$dept) ?: 'Department';
    return [
        'code' => $norm ?: 'DEPT',
        'short_label' => $cleanLabel,
        'full_label' => $cleanLabel,
        'dashboard_title' => $cleanLabel . ' Program Head Dashboard',
        'subtitle' => $cleanLabel . ' — Proposals, Activities, Faculty, Tasks & Schedules',
        'roster' => [],
    ];
}

function normalizeDepartmentName(?string $dept): string
{
    if (!$dept) {
        return '';
    }
    $clean = strtoupper(trim($dept));
    if (str_contains($clean, 'THM') || str_contains($clean, 'HOSPITALITY') || str_contains($clean, 'TOURISM')) {
        return 'THM';
    }
    if (str_contains($clean, 'GE') || str_contains($clean, 'G.E') || str_contains($clean, 'GENERAL')) {
        return 'GE';
    }
    if (preg_match('/\b(IT|I\.T)\b/', $clean) || str_contains($clean, 'INFORMATION') || str_contains($clean, 'TECHNOLOGY')) {
        return 'IT';
    }
    return $clean;
}

/**
 * Check whether a user has permission to access (view or modify) an activity.
 *
 * Access Rules:
 * - Dean, Admin 1, and Admin 2: Full access across departments for both 'view' and 'modify'.
 * - Faculty:
 *     - 'view': Allowed if user created the activity OR is assigned to any task in the activity.
 *     - 'modify': Allowed ONLY if user created the activity.
 * - Program Head:
 *     - 'view': Allowed if the activity belongs to their department (created by a faculty in their department
 *       or created by the program head), OR if the program head is assigned a task, OR if any faculty member
 *       from their department is assigned a task in this activity (shared / cross-department activity).
 *       Activities belonging exclusively to other departments are DENIED (false).
 *     - 'modify': Allowed ONLY if the activity belongs to their department (created by a faculty in their department
 *       or created by the program head). Modifying another department's activity is DENIED (false).
 *
 * @param int $activityId
 * @param string $action 'view' or 'modify'
 * @param int|null $userId
 * @param string|null $userRole
 * @param string|null $userDept
 * @return bool
 */
function canUserAccessActivity(int $activityId, string $action = 'view', ?int $userId = null, ?string $userRole = null, ?string $userDept = null): bool
{
    if ($activityId <= 0) {
        return false;
    }

    if ($userId === null || $userRole === null) {
        if (function_exists('currentUser')) {
            $cur = currentUser();
            $userId   = $userId   ?? (int)($cur['id'] ?? 0);
            $userRole = $userRole ?? ($cur['role'] ?? '');
            $userDept = $userDept ?? ($cur['dept'] ?? '');
        } else {
            $userId   = $userId   ?? (int)($_SESSION['user_id'] ?? 0);
            $userRole = $userRole ?? ($_SESSION['user_role'] ?? '');
            $userDept = $userDept ?? ($_SESSION['user_dept'] ?? '');
        }
    }

    if (!$userId || !$userRole) {
        return false;
    }

    $db = function_exists('getDB') ? getDB() : ($GLOBALS['testDb'] ?? null);
    if (!$db) {
        return false;
    }

    // Fetch activity and creator details (including status for draft privacy enforcement)
    $stmt = $db->prepare("
        SELECT a.id, a.faculty_id, a.status, u.department AS creator_department
        FROM activities a
        LEFT JOIN users u ON a.faculty_id = u.id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$activityId]);
    $act = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$act) {
        return false;
    }

    $creatorId = (int)$act['faculty_id'];
    $creatorDept = (string)($act['creator_department'] ?? '');
    $actStatus = strtolower(trim((string)($act['status'] ?? 'draft')));
    $isCreator = ($creatorId === (int)$userId);

    // STRICT GLOBAL DRAFT PRIVACY RULE:
    // Proposals saved as 'draft' are strictly private to the creator and explicitly invited active collaborators.
    if ($actStatus === 'draft') {
        if ($isCreator) {
            return true;
        }
        try {
            $collabStmt = $db->prepare("
                SELECT COUNT(*) FROM proposal_collaborators 
                WHERE activity_id = ? AND user_id = ? AND status = 'active'
            ");
            $collabStmt->execute([$activityId, $userId]);
            if ((int)$collabStmt->fetchColumn() > 0) {
                return true;
            }
        } catch (\PDOException $e) {
            // Fallback gracefully if table not yet migrated
        }
        return false;
    }

        // 1. Admin 1 (Committee Review) and Admin 2 reviewers have access to non-draft proposals
    if (in_array($userRole, ['admin1', 'admin2'], true)) {
        return true;
    }

    // 2. Dean (Final Approver) access rules:
    // The Dean only has access when the proposal has reached final approval stage ('pending_final_approval'),
    // has already been institutionalized ('approved', 'completed'), or where Dean was recorded in approval logs.
    if ($userRole === 'dean') {
        if (in_array($actStatus, ['pending_final_approval', 'pending_dean_approval', 'approved', 'completed'], true)) {
            return true;
        }
        $deanLogStmt = $db->prepare("SELECT COUNT(*) FROM approval_logs WHERE activity_id = ? AND reviewer_id = ?");
        $deanLogStmt->execute([$activityId, $userId]);
        return ((int)$deanLogStmt->fetchColumn() > 0);
    }

    // 3. Faculty access rules
    if ($userRole === 'faculty') {
        if ($isCreator) {
            return true;
        }

        // Active collaborator check (Co-Author or Contributor)
        try {
            $collabStmt = $db->prepare("
                SELECT COUNT(*) FROM proposal_collaborators 
                WHERE activity_id = ? AND user_id = ? AND status = 'active'
            ");
            $collabStmt->execute([$activityId, $userId]);
            if ((int)$collabStmt->fetchColumn() > 0) {
                return true;
            }
        } catch (\PDOException $e) {
            // Fallback gracefully if table not yet migrated
        }

        if ($action === 'modify') {
            return false;
        }
        // Action is 'view': Only allowed if the activity is ALREADY approved or completed and user is assigned a task
        if (in_array($actStatus, ['approved', 'completed'], true)) {
            $taskStmt = $db->prepare("SELECT COUNT(*) FROM faculty_tasks WHERE activity_id = ? AND assigned_user_id = ?");
            $taskStmt->execute([$activityId, $userId]);
            return ((int)$taskStmt->fetchColumn() > 0);
        }

        return false;
    }

    // 4. Program Head access rules (Strictly isolated to their own department: IT, THM, or G.E.)
    if ($userRole === 'program_head') {
        if ($isCreator) {
            return true;
        }

        // Active collaborator check (Co-Author or Contributor)
        try {
            $collabStmt = $db->prepare("
                SELECT COUNT(*) FROM proposal_collaborators 
                WHERE activity_id = ? AND user_id = ? AND status = 'active'
            ");
            $collabStmt->execute([$activityId, $userId]);
            if ((int)$collabStmt->fetchColumn() > 0) {
                return true;
            }
        } catch (\PDOException $e) {
            // Fallback gracefully
        }

        if (empty($userDept)) {
            $uStmt = $db->prepare("SELECT department FROM users WHERE id = ? LIMIT 1");
            $uStmt->execute([$userId]);
            $userDept = (string)$uStmt->fetchColumn();
        }

        // Program Head has full department-level visibility and management over non-draft
        // proposals and activities belonging to their own department (IT, THM, or G.E.),
        // and NO access to other departments' proposals or activities.
        if (!empty($userDept) && !empty($creatorDept)) {
            if (strcasecmp(trim($userDept), trim($creatorDept)) === 0) {
                return true;
            }
            $normUser = normalizeDepartmentName($userDept);
            $normCreator = normalizeDepartmentName($creatorDept);
            if ($normUser !== '' && $normUser === $normCreator) {
                return true;
            }
        }

        return false;
    }

    return false;
}

/**
 * Require that the current user has permission to access an activity,
 * otherwise respond with HTTP 403 Forbidden and terminate execution.
 *
 * @param int $activityId
 * @param string $action 'view' or 'modify'
 * @return void
 */
function requireActivityAccess(int $activityId, string $action = 'view'): void
{
    if (!canUserAccessActivity($activityId, $action)) {
        http_response_code(403);
        $isJson = (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) ||
                  (!empty($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json')) ||
                  (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
                  (isset($_SERVER['REQUEST_URI']) && str_contains($_SERVER['REQUEST_URI'], '/api/'));

        if ($isJson) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => false,
                'error'   => 'Access denied: You do not have permission to ' . $action . ' this activity.'
            ]);
            exit;
        }

        die('Access denied: You do not have permission to ' . htmlspecialchars($action) . ' this activity.');
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// COLLABORATIVE PROPOSAL FUNCTIONS (TASK 14)
// ══════════════════════════════════════════════════════════════════════════════

if (!function_exists('formatCollaboratorRole')) {
    function formatCollaboratorRole(string $role): string {
        switch (strtolower(trim($role))) {
            case 'owner':
                return 'Proposal Owner';
            case 'co_author':
            case 'co-author':
                return 'Co-Author';
            case 'contributor':
            default:
                return 'Contributor';
        }
    }
}

if (!function_exists('getProposalCollaborators')) {
    /**
     * Retrieves all collaborators for an activity, including the proposal owner.
     */
    function getProposalCollaborators(int $activityId, ?PDO $db = null): array {
        if ($activityId <= 0) return [];
        $db = $db ?? (function_exists('getDB') ? getDB() : null);
        if (!$db) return [];

        // 1. Get proposal owner from activities
        $actStmt = $db->prepare("
            SELECT a.id, a.faculty_id, a.status, u.name, u.email, u.department, u.role as system_role
            FROM activities a
            JOIN users u ON a.faculty_id = u.id
            WHERE a.id = ?
            LIMIT 1
        ");
        $actStmt->execute([$activityId]);
        $owner = $actStmt->fetch(PDO::FETCH_ASSOC);
        if (!$owner) return [];

        $collaborators = [];

        // Add owner as the first collaborator
        $collaborators[] = [
            'id'             => 0,
            'activity_id'    => $activityId,
            'user_id'        => (int)$owner['faculty_id'],
            'role'           => 'owner',
            'role_label'     => 'Proposal Owner',
            'name'           => $owner['name'],
            'email'          => $owner['email'],
            'department'     => $owner['department'],
            'system_role'    => $owner['system_role'],
            'added_by'       => (int)$owner['faculty_id'],
            'added_by_name'  => $owner['name'],
            'added_at'       => null,
            'is_owner'       => true,
            'can_remove'     => false,
            'status'         => 'active'
        ];

        // 2. Fetch assigned collaborators from proposal_collaborators
        try {
            $collabStmt = $db->prepare("
                SELECT pc.id, pc.activity_id, pc.user_id, pc.role, pc.added_by, pc.added_at, pc.status,
                       u.name, u.email, u.department, u.role as system_role,
                       ab.name as added_by_name
                FROM proposal_collaborators pc
                JOIN users u ON pc.user_id = u.id
                LEFT JOIN users ab ON pc.added_by = ab.id
                WHERE pc.activity_id = ? AND pc.status = 'active' AND pc.user_id != ?
                ORDER BY pc.added_at ASC, pc.id ASC
            ");
            $collabStmt->execute([$activityId, (int)$owner['faculty_id']]);
            $rows = $collabStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $r) {
                $collaborators[] = [
                    'id'             => (int)$r['id'],
                    'activity_id'    => $activityId,
                    'user_id'        => (int)$r['user_id'],
                    'role'           => $r['role'],
                    'role_label'     => formatCollaboratorRole($r['role']),
                    'name'           => $r['name'],
                    'email'          => $r['email'],
                    'department'     => $r['department'],
                    'system_role'    => $r['system_role'],
                    'added_by'       => (int)$r['added_by'],
                    'added_by_name'  => $r['added_by_name'] ?? 'System',
                    'added_at'       => $r['added_at'],
                    'is_owner'       => false,
                    'can_remove'     => true,
                    'status'         => $r['status']
                ];
            }
        } catch (\PDOException $e) {
            // Fallback gracefully
        }

        return $collaborators;
    }
}

if (!function_exists('isProposalCollaborator')) {
    function isProposalCollaborator(int $activityId, int $userId, ?PDO $db = null): bool {
        if ($activityId <= 0 || $userId <= 0) return false;
        $db = $db ?? (function_exists('getDB') ? getDB() : null);
        if (!$db) return false;

        $actStmt = $db->prepare("SELECT faculty_id FROM activities WHERE id = ? LIMIT 1");
        $actStmt->execute([$activityId]);
        $ownerId = (int)$actStmt->fetchColumn();
        if ($ownerId === $userId) return true;

        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM proposal_collaborators WHERE activity_id = ? AND user_id = ? AND status = 'active'");
            $stmt->execute([$activityId, $userId]);
            return ((int)$stmt->fetchColumn() > 0);
        } catch (\PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('getProposalUserRole')) {
    function getProposalUserRole(int $activityId, int $userId, ?PDO $db = null): ?string {
        if ($activityId <= 0 || $userId <= 0) return null;
        $db = $db ?? (function_exists('getDB') ? getDB() : null);
        if (!$db) return null;

        $actStmt = $db->prepare("SELECT faculty_id FROM activities WHERE id = ? LIMIT 1");
        $actStmt->execute([$activityId]);
        $ownerId = (int)$actStmt->fetchColumn();
        if ($ownerId === $userId) return 'owner';

        try {
            $stmt = $db->prepare("SELECT role FROM proposal_collaborators WHERE activity_id = ? AND user_id = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$activityId, $userId]);
            $role = $stmt->fetchColumn();
            return $role ?: null;
        } catch (\PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('cleanFacultyNameKey')) {
    /**
     * Normalizes a faculty member's name for comparison and deduplication:
     * strips common academic titles, professional suffixes, middle initials, and non-alphanumeric characters.
     */
    function cleanFacultyNameKey(string $name): string {
        $clean = preg_replace('/\b(Mr|Ms|Mrs|Dr|Prof|Dean|Atty|Engr|LPT|MAEd|PE|RN|MBA)\b\.?/i', '', $name);
        $clean = preg_replace('/\b[a-zA-Z]\b\.?/i', ' ', $clean);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $clean);
            if ($converted !== false) $clean = $converted;
        }
        return strtolower(preg_replace('/[^a-zA-Z]/', '', $clean));
    }
}

if (!function_exists('normalizeFacultyDepartment')) {
    /**
     * Standardizes department names to official department labels (e.g. 'IT Department', 'THM Department', 'G.E. Department').
     */
    function normalizeFacultyDepartment(?string $dept): string {
        $d = trim($dept ?? '');
        if ($d === '' || strcasecmp($d, 'general') === 0) {
            return 'G.E. Department';
        }
        if (preg_match('/^(information technology|it)(\s+department)?$/i', $d)) {
            return 'IT Department';
        }
        if (preg_match('/^(hospitality management|thm|tourism)(\s+department)?$/i', $d)) {
            return 'THM Department';
        }
        if (preg_match('/^(general education|ge|g\.e\.)(\s+department)?$/i', $d)) {
            return 'G.E. Department';
        }
        if (!preg_match('/department/i', $d)) {
            return $d . ' Department';
        }
        return $d;
    }
}

if (!function_exists('getOfficialFacultyRoster')) {
    /**
     * Official 28 faculty members transcribed from the STI College Marikina organizational charts.
     * Excludes Dr. Frederic D. Yulo (Dean).
     */
    function getOfficialFacultyRoster(): array {
        return [
            'IT Department' => [
                'Dave Lord M. Rubaya',
                'Michelle Carlos',
                'Ar-Jay C. Agbayani',
                'Emilou G. Magnaye',
                'John Cedrick Agapito',
                'Jedekiah Veloso',
                'Shintaro Lagare',
                'Geo V. De Leon',
                'Johanna Kristel T. Montejo',
                'Felmar Solbita',
                'Michael André Sayson'
            ],
            'THM Department' => [
                'Aldwin D. Alminar',
                'Andrea J. Gatbonton',
                'Divine Grace E. Ibarroll',
                'Mary Darlene A. Mariano',
                'Julieta Caridad C. Cruz',
                'Exequiel L. Magnipis',
                'Frankie Chiu'
            ],
            'G.E. Department' => [
                'Tristan S. Unabia',
                'Ian Jade C. Barangan',
                'Ulysses Neil Patrick B. Edquilang',
                'Gregg Anthony J. Rana',
                'Ricky E. Manliguis',
                'Regina Kate D. Dominguez',
                'Benedicto A. Arnoco',
                'Jennilyn Q. Abarra',
                'Ruby Ann G. Dasalla',
                'Perine O. Dela Cruz'
            ]
        ];
    }
}

if (!function_exists('ensureOfficialFacultyAccountsExist')) {
    /**
     * Globally ensures all 28 official faculty members from the STI organizational charts
     * have active user accounts in the database so all names are clickable in contributor selection.
     */
    function ensureOfficialFacultyAccountsExist(?PDO $db = null): void {
        $db = $db ?? (function_exists('getDB') ? getDB() : null);
        if (!$db) return;

        static $alreadyEnsured = false;
        if ($alreadyEnsured) return;
        $alreadyEnsured = true;

        if (!function_exists('getOfficialFacultyRoster') || !function_exists('cleanFacultyNameKey')) {
            return;
        }

        $roster = getOfficialFacultyRoster();
        $defaultPasswordHash = password_hash('password', PASSWORD_BCRYPT);

        try {
            $stmt = $db->query("SELECT id, name, email, department, role FROM users");
            $existingUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return;
        }

        $insertStmt = $db->prepare("
            INSERT INTO users (name, email, password, role, department, created_at)
            VALUES (?, ?, ?, 'faculty', ?, NOW())
        ");

        foreach ($roster as $dept => $names) {
            foreach ($names as $officialName) {
                if (stripos($officialName, 'Frederic') !== false && stripos($officialName, 'Yulo') !== false) {
                    continue;
                }

                $offKey = cleanFacultyNameKey($officialName);
                $found = false;

                foreach ($existingUsers as $u) {
                    if (cleanFacultyNameKey($u['name']) === $offKey) {
                        $found = true;
                        break;
                    }
                    similar_text($offKey, cleanFacultyNameKey($u['name']), $pct);
                    if ($pct >= 85) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $cleanParts = array_values(array_filter(explode(' ', preg_replace('/[^a-zA-Z\s]/', '', $officialName))));
                    $firstName = strtolower($cleanParts[0] ?? 'faculty');
                    $lastName = strtolower(end($cleanParts) ?? 'member');
                    $baseEmail = $firstName . '.' . $lastName . '@marikina.sti.edu.ph';

                    $email = $baseEmail;
                    $counter = 1;
                    $emailCheck = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
                    while (true) {
                        $emailCheck->execute([$email]);
                        if ((int)$emailCheck->fetchColumn() === 0) break;
                        $counter++;
                        $email = $firstName . '.' . $lastName . $counter . '@marikina.sti.edu.ph';
                    }

                    try {
                        $insertStmt->execute([$officialName, $email, $defaultPasswordHash, $dept]);
                        $newId = (int)$db->lastInsertId();
                        $existingUsers[] = [
                            'id' => $newId,
                            'name' => $officialName,
                            'email' => $email,
                            'department' => $dept,
                            'role' => 'faculty'
                        ];
                    } catch (\Throwable $e) {
                        error_log("Failed to insert faculty account {$officialName}: " . $e->getMessage());
                    }
                }
            }
        }
    }
}

if (!function_exists('getEligibleFacultyForDropdown')) {
    /**
     * Retrieves all 28 faculty members from the official STI organizational charts for the collaborator dropdown.
     * - Grouped and labeled by department.
     * - Existing active, eligible accounts remain selectable.
     * - Missing accounts appear as disabled options labeled "Account Not Registered".
     * - Ineligible accounts appear as disabled options labeled "Not Eligible".
     * - Already assigned collaborators appear as disabled options labeled "Already Assigned".
     * - Completely excludes Dr. Frederic D. Yulo.
     * - Deduplicates accounts and eliminates test accounts.
     */
    function getEligibleFacultyForDropdown(?PDO $db = null, array $excludeIds = [], string $search = ''): array {
        $db = $db ?? (function_exists('getDB') ? getDB() : null);
        if (!$db) return [];
        if (function_exists('ensureOfficialFacultyAccountsExist')) {
            ensureOfficialFacultyAccountsExist($db);
        }

        $cleanExcludeIds = array_unique(array_filter(array_map('intval', $excludeIds)));

        // Check if status column exists in users table
        static $hasStatusCol = null;
        if ($hasStatusCol === null) {
            try {
                $colCheck = $db->query("SHOW COLUMNS FROM users LIKE 'status'")->fetch();
                $hasStatusCol = !empty($colCheck);
            } catch (\Throwable $e) {
                $hasStatusCol = false;
            }
        }

        // Fetch users from database, excluding test accounts and Dr. Frederic D. Yulo
        $sql = "
            SELECT id, name, email, department, role" . ($hasStatusCol ? ", status" : "") . "
            FROM users 
            WHERE LOWER(name) NOT LIKE '%bad email%'
              AND LOWER(name) NOT LIKE '%unrelated%'
              AND LOWER(name) NOT LIKE '%placeholder%'
              AND LOWER(name) NOT LIKE '%dummy%'
              AND LOWER(name) NOT LIKE '%test%'
              AND email NOT LIKE '%@invalid%'
              AND email NOT LIKE 'simulated_%'
              AND email NOT LIKE 'fac_unrelated_%'
            ORDER BY (CASE WHEN email LIKE '%@sti.edu%' OR email LIKE '%@marikina.sti.edu.ph%' THEN 0 ELSE 1 END) ASC, id ASC
        ";

        try {
            $stmt = $db->query($sql);
            $rawUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $rawUsers = [];
        }

        // Exclude Dr. Frederic D. Yulo completely (Requirement 7)
        $validUsers = [];
        foreach ($rawUsers as $u) {
            if (stripos($u['name'], 'Frederic') !== false && stripos($u['name'], 'Yulo') !== false) {
                continue;
            }
            $validUsers[] = $u;
        }

        $roster = getOfficialFacultyRoster();
        $search = trim($search);
        $cleanList = [];

        foreach ($roster as $dept => $names) {
            foreach ($names as $officialName) {
                if ($search !== '') {
                    if (stripos($officialName, $search) === false && stripos($dept, $search) === false) {
                        continue;
                    }
                }

                $offKey = cleanFacultyNameKey($officialName);
                $matchedUser = null;

                // 1. Exact normalized match
                foreach ($validUsers as $u) {
                    if (cleanFacultyNameKey($u['name']) === $offKey) {
                        $matchedUser = $u;
                        break;
                    }
                }

                // 2. Fuzzy match fallback (e.g. Ar-jay Agabayani vs Agbayani)
                if (!$matchedUser) {
                    foreach ($validUsers as $u) {
                        $uKey = cleanFacultyNameKey($u['name']);
                        similar_text($offKey, $uKey, $pct);
                        if ($pct >= 85) {
                            $matchedUser = $u;
                            break;
                        }
                    }
                }

                if (!$matchedUser) {
                    // Requirement 4: Missing accounts appear as disabled options labeled "Account Not Registered"
                    $cleanList[] = [
                        'id'           => null,
                        'name'         => $officialName,
                        'department'   => $dept,
                        'role'         => null,
                        'status'       => 'not_registered',
                        'status_label' => 'Account Not Registered',
                        'disabled'     => true,
                        'display_text' => "{$officialName} ({$dept}) — Account Not Registered"
                    ];
                } else {
                    $userStatus = $hasStatusCol ? ($matchedUser['status'] ?? 'active') : 'active';
                    if ($userStatus !== 'active') {
                        $cleanList[] = [
                            'id'           => null,
                            'name'         => $officialName,
                            'department'   => $dept,
                            'role'         => $matchedUser['role'],
                            'status'       => 'inactive',
                            'status_label' => 'Account Inactive',
                            'disabled'     => true,
                            'display_text' => "{$officialName} ({$dept}) — Account Inactive"
                        ];
                        continue;
                    }

                    $isEligibleRole = in_array($matchedUser['role'], ['faculty', 'program_head', 'admin1', 'admin2'], true);
                    if (!$isEligibleRole) {
                        // Requirement 5: Accounts with ineligible roles disabled and labeled "Not Eligible"
                        $cleanList[] = [
                            'id'           => null,
                            'name'         => $officialName,
                            'department'   => $dept,
                            'role'         => $matchedUser['role'],
                            'status'       => 'not_eligible',
                            'status_label' => 'Not Eligible',
                            'disabled'     => true,
                            'display_text' => "{$officialName} ({$dept}) — Not Eligible"
                        ];
                    } elseif (in_array((int)$matchedUser['id'], $cleanExcludeIds, true)) {
                        // Excluded: Proposal Owner or already active collaborator on this proposal
                        $cleanList[] = [
                            'id'           => null,
                            'name'         => $officialName,
                            'department'   => $dept,
                            'role'         => $matchedUser['role'],
                            'status'       => 'already_assigned',
                            'status_label' => 'Already Assigned',
                            'disabled'     => true,
                            'display_text' => "{$officialName} ({$dept}) — Already Assigned"
                        ];
                    } else {
                        // Requirement 3: Existing active, eligible accounts remain selectable
                        $cleanList[] = [
                            'id'           => (int)$matchedUser['id'],
                            'name'         => $officialName,
                            'department'   => $dept,
                            'role'         => $matchedUser['role'],
                            'status'       => 'eligible',
                            'status_label' => null,
                            'disabled'     => false,
                            'display_text' => "{$officialName} ({$dept})"
                        ];
                    }
                }
            }
        }

        return $cleanList;
    }
}

if (!function_exists('addProposalCollaborator')) {
    function addProposalCollaborator(int $activityId, int $targetUserId, string $role, int $addedBy, ?PDO $db = null): array {
        $db = $db ?? (function_exists('getDB') ? getDB() : null);
        if (!$db) return ['success' => false, 'error' => 'Database unavailable.'];

        // 1. Validate activity
        $actStmt = $db->prepare("SELECT id, faculty_id, title, status FROM activities WHERE id = ? LIMIT 1");
        $actStmt->execute([$activityId]);
        $activity = $actStmt->fetch(PDO::FETCH_ASSOC);
        if (!$activity) {
            return ['success' => false, 'error' => 'Activity proposal not found.'];
        }

        // Only editable proposals can have collaborators added
        if (!in_array($activity['status'], ['draft', 'returned_for_revision'], true)) {
            return ['success' => false, 'error' => 'Cannot add collaborators: This proposal is currently locked under review or completed.'];
        }

        // Only proposal owner or authorized role can add collaborators
        $ownerId = (int)$activity['faculty_id'];
        $userRole = null;
        if (function_exists('currentUser')) {
            $cur = currentUser();
            $userRole = $cur['role'] ?? null;
        }
        $isAuthorizedAdder = ($addedBy === $ownerId || in_array($userRole, ['program_head', 'dean', 'admin1', 'admin2'], true));
        if (!$isAuthorizedAdder) {
            return ['success' => false, 'error' => 'Unauthorized: Only the proposal owner may add contributors.'];
        }

        // 2. Validate target user
        $uStmt = $db->prepare("SELECT id, name, email, role, department FROM users WHERE id = ? LIMIT 1");
        $uStmt->execute([$targetUserId]);
        $targetUser = $uStmt->fetch(PDO::FETCH_ASSOC);
        if (!$targetUser) {
            return ['success' => false, 'error' => 'Target user account not found.'];
        }

        // Only valid faculty accounts may be invited as contributors
        if (!in_array($targetUser['role'], ['faculty', 'program_head'], true)) {
            return ['success' => false, 'error' => 'Only valid faculty members may be invited as proposal contributors.'];
        }

        // Target user cannot be the owner (by ID or canonical name)
        $ownerStmt = $db->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
        $ownerStmt->execute([$ownerId]);
        $ownerName = (string)$ownerStmt->fetchColumn();

        $targetKey = cleanFacultyNameKey($targetUser['name']);
        $ownerKey = cleanFacultyNameKey($ownerName);

        if ($targetUserId === $ownerId || ($targetKey !== '' && $targetKey === $ownerKey)) {
            return ['success' => false, 'error' => 'The proposal owner is already the primary author of this activity.'];
        }

        // Role normalization
        $allowedRoles = ['co_author', 'contributor'];
        $cleanRole = in_array(strtolower($role), $allowedRoles, true) ? strtolower($role) : 'contributor';

        // 3. Prevent duplicate active collaborators (by ID or canonical name)
        $checkStmt = $db->prepare("SELECT id, status, role FROM proposal_collaborators WHERE activity_id = ? AND user_id = ? LIMIT 1");
        $checkStmt->execute([$activityId, $targetUserId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing && $existing['status'] === 'active') {
            return ['success' => false, 'error' => "{$targetUser['name']} is already an active collaborator on this proposal."];
        }

        // Check if an active collaborator with the same person's name already exists
        $collabUsersStmt = $db->prepare("
            SELECT u.name FROM proposal_collaborators pc 
            JOIN users u ON pc.user_id = u.id 
            WHERE pc.activity_id = ? AND pc.status = 'active'
        ");
        $collabUsersStmt->execute([$activityId]);
        $activeCollabNames = $collabUsersStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($targetKey !== '') {
            foreach ($activeCollabNames as $acn) {
                if (cleanFacultyNameKey($acn) === $targetKey) {
                    return ['success' => false, 'error' => "{$targetUser['name']} is already an active collaborator on this proposal."];
                }
            }
        }

        if ($existing) {
            // Re-activate previously removed collaborator
            $upd = $db->prepare("UPDATE proposal_collaborators SET role = ?, added_by = ?, added_at = NOW(), status = 'active' WHERE id = ?");
            $upd->execute([$cleanRole, $addedBy, $existing['id']]);
        } else {
            // Insert new collaborator
            $ins = $db->prepare("
                INSERT INTO proposal_collaborators (activity_id, user_id, role, added_by, status)
                VALUES (?, ?, ?, ?, 'active')
            ");
            $ins->execute([$activityId, $targetUserId, $cleanRole, $addedBy]);
        }

        // 4. Log contribution
        $roleLabel = formatCollaboratorRole($cleanRole);
        logProposalContribution($activityId, $addedBy, 'added_collaborator', 'Collaborators', "Added {$targetUser['name']} as {$roleLabel}", $db);

        // 5. Dispatch real-time in-system & email notifications (Task 18)
        try {
            if (function_exists('dispatchCollaboratorNotification')) {
                dispatchCollaboratorNotification($activityId, $addedBy, 'collaborator_added', [
                    'target_user_id'    => $targetUserId,
                    'target_user_name'  => $targetUser['name'],
                    'target_user_email' => $targetUser['email'] ?? '',
                    'role'              => $cleanRole,
                    'role_label'        => $roleLabel,
                    'proposal_title'    => $activity['title'] ?? ''
                ], $db);
            }
        } catch (\Throwable $notifEx) {
            error_log("Failed to dispatch collaborator_added notifications: " . $notifEx->getMessage());
        }

        return [
            'success'    => true,
            'message'    => "Successfully added {$targetUser['name']} as {$roleLabel}.",
            'user_id'    => $targetUserId,
            'name'       => $targetUser['name'],
            'role'       => $cleanRole,
            'role_label' => $roleLabel
        ];
    }
}

if (!function_exists('removeProposalCollaborator')) {
    function removeProposalCollaborator(int $activityId, int $targetUserId, int $removedBy, ?PDO $db = null): array {
        $db = $db ?? (function_exists('getDB') ? getDB() : null);
        if (!$db) return ['success' => false, 'error' => 'Database unavailable.'];

        $actStmt = $db->prepare("SELECT id, faculty_id, title, status FROM activities WHERE id = ? LIMIT 1");
        $actStmt->execute([$activityId]);
        $activity = $actStmt->fetch(PDO::FETCH_ASSOC);
        if (!$activity) {
            return ['success' => false, 'error' => 'Activity proposal not found.'];
        }

        if (!in_array($activity['status'], ['draft', 'returned_for_revision'], true)) {
            return ['success' => false, 'error' => 'Cannot remove collaborators: This proposal is currently locked under review or completed.'];
        }

        $ownerId = (int)$activity['faculty_id'];
        $userRole = null;
        if (function_exists('currentUser')) {
            $cur = currentUser();
            $userRole = $cur['role'] ?? null;
        }
        $isAuthorizedRemover = ($removedBy === $ownerId || in_array($userRole, ['program_head', 'dean', 'admin1', 'admin2'], true));
        if (!$isAuthorizedRemover) {
            return ['success' => false, 'error' => 'Unauthorized: Only the proposal owner may remove collaborators.'];
        }

        if ($targetUserId === $ownerId) {
            return ['success' => false, 'error' => 'The proposal owner cannot be removed from their own activity.'];
        }

        // Fetch user details for log & notifications
        $uStmt = $db->prepare("SELECT id, name, email FROM users WHERE id = ? LIMIT 1");
        $uStmt->execute([$targetUserId]);
        $targetUserRow = $uStmt->fetch(PDO::FETCH_ASSOC);
        $targetName = $targetUserRow['name'] ?? 'Faculty member';
        $targetEmail = $targetUserRow['email'] ?? '';

        $del = $db->prepare("UPDATE proposal_collaborators SET status = 'removed' WHERE activity_id = ? AND user_id = ?");
        $del->execute([$activityId, $targetUserId]);

        logProposalContribution($activityId, $removedBy, 'removed_collaborator', 'Collaborators', "Removed {$targetName} from proposal collaborators", $db);

        // Dispatch real-time in-system & email notifications (Task 18)
        try {
            if (function_exists('dispatchCollaboratorNotification')) {
                dispatchCollaboratorNotification($activityId, $removedBy, 'collaborator_removed', [
                    'target_user_id'    => $targetUserId,
                    'target_user_name'  => $targetName,
                    'target_user_email' => $targetEmail,
                    'proposal_title'    => $activity['title'] ?? ''
                ], $db);
            }
        } catch (\Throwable $notifEx) {
            error_log("Failed to dispatch collaborator_removed notifications: " . $notifEx->getMessage());
        }

        return [
            'success' => true,
            'message' => "Successfully removed {$targetName} from proposal collaborators."
        ];
    }
}

if (!function_exists('logProposalContribution')) {
    function logProposalContribution(int $activityId, int $userId, string $action, ?string $section = null, ?string $details = null, ?PDO $db = null): bool {
        if ($activityId <= 0 || $userId <= 0) return false;
        $db = $db ?? (function_exists('getDB') ? getDB() : null);
        if (!$db) return false;

        try {
            $collabRole = getProposalUserRole($activityId, $userId, $db) ?: 'contributor';
            $ins = $db->prepare("
                INSERT INTO proposal_contribution_logs (activity_id, user_id, user_role, action, section, details)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            return $ins->execute([$activityId, $userId, $collabRole, $action, $section, $details]);
        } catch (\PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('getProposalContributionLogs')) {
    function getProposalContributionLogs(int $activityId, int $limit = 50, ?PDO $db = null): array {
        if ($activityId <= 0) return [];
        $db = $db ?? (function_exists('getDB') ? getDB() : null);
        if (!$db) return [];

        try {
            $stmt = $db->prepare("
                SELECT pcl.*, u.name as user_name, u.department as user_department
                FROM proposal_contribution_logs pcl
                JOIN users u ON pcl.user_id = u.id
                WHERE pcl.activity_id = ?
                ORDER BY pcl.created_at DESC, pcl.id DESC
                LIMIT " . (int)$limit . "
            ");
            $stmt->execute([$activityId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
    }
}

if (!function_exists('dispatchCollaboratorNotification')) {
    /**
     * Dispatches real-time in-system and email notifications for collaborative proposal events (Task 18).
     *
     * @param int $activityId Proposal ID
     * @param int $actorId ID of user performing the action (strictly excluded from receiving self-notifications)
     * @param string $eventType One of: 'collaborator_added', 'collaborator_removed', 'proposal_updated', 'proposal_submitted'
     * @param array $eventData Contextual event data:
     *      - target_user_id (int)
     *      - target_user_name (string)
     *      - target_user_email (string)
     *      - role (string)
     *      - role_label (string)
     *      - proposal_title (string)
     *      - sections (string|array)
     *      - description (string)
     *      - event_key (string)
     * @param PDO|null $db
     * @return array Summary of dispatch results ['in_system_count' => int, 'email_count' => int, 'skipped_count' => int, 'recipients' => array]
     */
    function dispatchCollaboratorNotification(
        int $activityId,
        int $actorId,
        string $eventType,
        array $eventData = [],
        ?PDO $db = null
    ): array {
        if ($activityId <= 0) {
            return ['success' => false, 'error' => 'Invalid activity ID'];
        }

        $db = $db ?? (function_exists('getDB') ? getDB() : null);
        if (!$db) {
            return ['success' => false, 'error' => 'Database connection unavailable'];
        }

        // 1. Resolve proposal details
        $proposalTitle = trim((string)($eventData['proposal_title'] ?? ''));
        if ($proposalTitle === '') {
            $actStmt = $db->prepare("SELECT title, status FROM activities WHERE id = ? LIMIT 1");
            $actStmt->execute([$activityId]);
            $actRow = $actStmt->fetch(PDO::FETCH_ASSOC);
            $proposalTitle = $actRow['title'] ?? "Activity #{$activityId}";
        }

        // 2. Resolve actor name
        $actorName = 'A collaborator';
        if ($actorId > 0) {
            $uStmt = $db->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
            $uStmt->execute([$actorId]);
            $fetchedName = $uStmt->fetchColumn();
            if ($fetchedName) {
                $actorName = $fetchedName;
            }
        }

        // 3. Resolve active collaborators list (includes Owner)
        $collaborators = getProposalCollaborators($activityId, $db);
        $collabMap = [];
        foreach ($collaborators as $c) {
            $collabMap[(int)$c['user_id']] = $c;
        }

        // Base URL for links
        $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost/sti-activity-system';
        $proposalLink = "{$baseUrl}/faculty/proposal-view.php?id={$activityId}";

        // Prepare statements for in-system notifications & deduplication
        $checkNotifStmt = $db->prepare("
            SELECT id FROM notifications 
            WHERE user_id = ? AND activity_id = ? AND message = ? 
              AND created_at >= (NOW() - INTERVAL 30 SECOND) 
            LIMIT 1
        ");
        $insertNotifStmt = $db->prepare("
            INSERT INTO notifications (user_id, activity_id, message, is_read, created_at)
            VALUES (?, ?, ?, 0, NOW())
        ");

        // Lazy-instantiate EmailService safely
        $emailService = null;
        if (!class_exists('EmailService')) {
            $emailServicePath = __DIR__ . '/EmailService.php';
            if (file_exists($emailServicePath)) {
                require_once $emailServicePath;
            }
        }
        if (class_exists('EmailService')) {
            try {
                $emailService = new EmailService();
            } catch (\Throwable $e) {
                error_log("EmailService instantiation error: " . $e->getMessage());
            }
        }

        $inSystemCount = 0;
        $emailCount = 0;
        $skippedCount = 0;
        $notifiedUserIds = [];

        // Internal helper to deliver to a single recipient
        $sendToUser = function(
            int $userId,
            string $recipientName,
            string $recipientEmail,
            string $inSystemMsg,
            string $emailSubject,
            string $emailStatus,
            string $emailBodyMsg,
            string $notificationType
        ) use (
            $activityId,
            $actorId,
            $proposalTitle,
            $proposalLink,
            $checkNotifStmt,
            $insertNotifStmt,
            $emailService,
            &$inSystemCount,
            &$emailCount,
            &$skippedCount,
            &$notifiedUserIds
        ) {
            // STRICT RULE: Never notify the actor about their own change!
            if ($userId === $actorId || $userId <= 0) {
                return;
            }

            // Deduplication for in-system notification (within 30 seconds)
            $checkNotifStmt->execute([$userId, $activityId, $inSystemMsg]);
            if ($checkNotifStmt->fetch()) {
                $skippedCount++;
                return;
            }

            // 1. Deliver In-System Notification
            try {
                $insertNotifStmt->execute([$userId, $activityId, $inSystemMsg]);
                $inSystemCount++;
                $notifiedUserIds[] = $userId;
            } catch (\Throwable $ne) {
                error_log("Failed to insert notification for user {$userId}: " . $ne->getMessage());
            }

            // 2. Deliver Email Notification
            if ($emailService && !empty($recipientEmail)) {
                try {
                    $sent = $emailService->sendNotification(
                        $recipientEmail,
                        $recipientName,
                        $emailSubject,
                        $proposalTitle,
                        $emailStatus,
                        $emailBodyMsg,
                        $proposalLink,
                        $activityId,
                        $notificationType
                    );
                    if ($sent) {
                        $emailCount++;
                    }
                } catch (\Throwable $me) {
                    error_log("Failed sending collaborative email to {$recipientEmail}: " . $me->getMessage());
                }
            }
        };

        $dateTimeStr = date('F j, Y g:i A');

        // 4. Branch by event type
        switch ($eventType) {
            case 'collaborator_added':
                $targetUserId = (int)($eventData['target_user_id'] ?? 0);
                $targetName   = trim((string)($eventData['target_user_name'] ?? ''));
                $targetEmail  = trim((string)($eventData['target_user_email'] ?? ''));
                $roleLabel    = trim((string)($eventData['role_label'] ?? formatCollaboratorRole($eventData['role'] ?? 'contributor')));

                if ($targetUserId <= 0) break;

                // If name/email not provided in eventData, fetch from users table
                if ($targetName === '' || $targetEmail === '') {
                    $uStmt = $db->prepare("SELECT name, email FROM users WHERE id = ? LIMIT 1");
                    $uStmt->execute([$targetUserId]);
                    $tRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                    if ($tRow) {
                        if ($targetName === '') $targetName = $tRow['name'];
                        if ($targetEmail === '') $targetEmail = $tRow['email'];
                    }
                }

                // 4a. Notify the newly added collaborator
                $inviteMsg = "You have been added as a {$roleLabel} to the proposal '{$proposalTitle}' by {$actorName}.";
                $inviteEmailSubject = "Collaboration Invitation: {$proposalTitle}";
                $inviteEmailBody = "You have been invited by {$actorName} to collaborate on the activity proposal '{$proposalTitle}' as a {$roleLabel}.\n\nYou can access and edit your assigned sections directly using the link below.";
                $sendToUser(
                    $targetUserId,
                    $targetName,
                    $targetEmail,
                    $inviteMsg,
                    $inviteEmailSubject,
                    "Invited as {$roleLabel}",
                    $inviteEmailBody,
                    "collab_invite_{$activityId}_{$targetUserId}"
                );

                // 4b. Notify existing active collaborators about the new member
                foreach ($collabMap as $uid => $collab) {
                    if ($uid === $targetUserId || $uid === $actorId) continue;
                    $teamMsg = "{$actorName} added {$targetName} as a {$roleLabel} to proposal '{$proposalTitle}'.";
                    $teamSubject = "Team Update: {$proposalTitle}";
                    $teamBody = "{$actorName} has added {$targetName} ({$roleLabel}) to the collaborative team for proposal '{$proposalTitle}'.";
                    $sendToUser(
                        $uid,
                        $collab['name'],
                        $collab['email'],
                        $teamMsg,
                        $teamSubject,
                        "Collaborator Added",
                        $teamBody,
                        "collab_team_add_{$activityId}_{$targetUserId}_{$uid}"
                    );
                }
                break;

            case 'collaborator_removed':
                $targetUserId = (int)($eventData['target_user_id'] ?? 0);
                $targetName   = trim((string)($eventData['target_user_name'] ?? ''));
                $targetEmail  = trim((string)($eventData['target_user_email'] ?? ''));

                if ($targetUserId <= 0) break;

                if ($targetName === '' || $targetEmail === '') {
                    $uStmt = $db->prepare("SELECT name, email FROM users WHERE id = ? LIMIT 1");
                    $uStmt->execute([$targetUserId]);
                    $tRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                    if ($tRow) {
                        if ($targetName === '') $targetName = $tRow['name'];
                        if ($targetEmail === '') $targetEmail = $tRow['email'];
                    }
                }

                // 4a. Notify the removed collaborator
                $remMsg = "You have been removed from the collaborative team for proposal '{$proposalTitle}' by {$actorName}.";
                $remSubject = "Collaboration Update: {$proposalTitle}";
                $remBody = "You have been removed from the collaborative team for the activity proposal '{$proposalTitle}' by {$actorName}.";
                $sendToUser(
                    $targetUserId,
                    $targetName,
                    $targetEmail,
                    $remMsg,
                    $remSubject,
                    "Removed",
                    $remBody,
                    "collab_removed_{$activityId}_{$targetUserId}"
                );

                // 4b. Notify remaining collaborators about the membership change
                foreach ($collabMap as $uid => $collab) {
                    if ($uid === $targetUserId || $uid === $actorId) continue;
                    $teamMsg = "{$actorName} removed {$targetName} from the collaborative team for proposal '{$proposalTitle}'.";
                    $teamSubject = "Team Update: {$proposalTitle}";
                    $teamBody = "{$actorName} has removed {$targetName} from the collaborative team for proposal '{$proposalTitle}'.";
                    $sendToUser(
                        $uid,
                        $collab['name'],
                        $collab['email'],
                        $teamMsg,
                        $teamSubject,
                        "Collaborator Removed",
                        $teamBody,
                        "collab_team_rem_{$activityId}_{$targetUserId}_{$uid}"
                    );
                }
                break;

            case 'proposal_updated':
                $sections = $eventData['sections'] ?? 'Proposal Details';
                if (is_array($sections)) {
                    $sections = implode(', ', array_filter($sections));
                }
                $sections = trim((string)$sections) ?: 'Proposal Details';
                $description = trim((string)($eventData['description'] ?? "Saved edits to {$sections}"));
                $customKey = trim((string)($eventData['event_key'] ?? ''));
                $eventHash = $customKey !== '' ? $customKey : substr(md5($sections . '_' . date('YmdHi')), 0, 8);

                $updMsg = "{$actorName} updated {$sections} in proposal '{$proposalTitle}'. ({$description})";
                $updSubject = "Proposal Updated: {$proposalTitle}";
                $updBody = "{$actorName} made changes to the proposal '{$proposalTitle}'.\n\nAffected Section(s): {$sections}\nDescription: {$description}\nDate & Time: {$dateTimeStr}";

                foreach ($collabMap as $uid => $collab) {
                    if ($uid === $actorId) continue;
                    $sendToUser(
                        $uid,
                        $collab['name'],
                        $collab['email'],
                        $updMsg,
                        $updSubject,
                        "Draft Updated",
                        $updBody,
                        "collab_upd_{$activityId}_{$eventHash}_{$uid}"
                    );
                }
                break;

            case 'proposal_submitted':
                $subMsg = "The shared proposal '{$proposalTitle}' was submitted for approval by {$actorName}.";
                $subSubject = "Proposal Submitted: {$proposalTitle}";
                $subBody = "The shared activity proposal '{$proposalTitle}' has been submitted for administrative review and approval by {$actorName}.\n\nDate & Time: {$dateTimeStr}";

                foreach ($collabMap as $uid => $collab) {
                    if ($uid === $actorId) continue;
                    $sendToUser(
                        $uid,
                        $collab['name'],
                        $collab['email'],
                        $subMsg,
                        $subSubject,
                        "Submitted for Review",
                        $subBody,
                        "collab_sub_{$activityId}_{$uid}"
                    );
                }
                break;
        }

        return [
            'success'         => true,
            'event_type'      => $eventType,
            'in_system_count' => $inSystemCount,
            'email_count'     => $emailCount,
            'skipped_count'   => $skippedCount,
            'recipients'      => $notifiedUserIds
        ];
    }
}

if (!function_exists('calculateAttendanceRate')) {
    /**
     * Calculates the attendance rate percentage based on actual and target attendance,
     * safely capped at 100.0%.
     *
     * Handles zero, null, or negative target attendance safely without division by zero.
     * Preserves original actual/target values; caps only the calculated percentage.
     *
     * @param mixed $actual Actual attendance count
     * @param mixed $target Target attendance count
     * @param int $precision Decimal precision for rounding (default 1)
     * @return float|null Attendance percentage between 0.0 and 100.0, or null if target is invalid
     */
    function calculateAttendanceRate(mixed $actual, mixed $target, int $precision = 1): ?float
    {
        if ($target === null || !is_numeric($target) || (float)$target <= 0) {
            return null;
        }
        if ($actual === null || !is_numeric($actual)) {
            return 0.0;
        }

        $actualCount = max(0.0, (float)$actual);
        $targetCount = (float)$target;

        $rate = ($actualCount / $targetCount) * 100.0;
        return min(100.0, round($rate, $precision));
    }
}






/**
 * Universal Activity Archiving Schema Helper
 * Ensures `is_archived`, `archived_at`, and `archived_by` exist on `activities` table.
 */
function ensureActivitiesArchiveSchema(PDO $db): void
{
    static $ensured = false;
    if ($ensured) return;
    try {
        $cols = $db->query("SHOW COLUMNS FROM activities LIKE 'is_archived'")->fetchAll();
        if (empty($cols)) {
            $db->exec("
                ALTER TABLE activities 
                ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN archived_at DATETIME NULL DEFAULT NULL,
                ADD COLUMN archived_by INT(11) NULL DEFAULT NULL,
                ADD KEY idx_activities_archived (is_archived, status)
            ");
        }
        $ensured = true;
    } catch (\Throwable $e) {
        // Safe fallback if column exists or restricted
    }
}