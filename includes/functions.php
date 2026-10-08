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
    static $syncedFiles = [];

    // Avoid duplicate archive uploads if called multiple times for the same file in a request
    $cacheKey = $fullLocalPath . '|' . $fileName;
    if (isset($syncedFiles[$cacheKey])) {
        return $syncedFiles[$cacheKey];
    }

    try {
        if ($service === null) {
            require_once __DIR__ . '/OneDriveService.php';
            $service = new OneDriveService();
        }

        // Respect ONEDRIVE_ENABLED
        if (!$service->isEnabled()) {
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
            ];

            $syncedFiles[$cacheKey] = $metadata;
            return $metadata;
        } else {
            // Safe logging without credentials, secrets, or raw payloads
            $safeError = preg_replace('/[^\w\s\.\-_:]/', '', (string)($result['error'] ?? 'Unknown error'));
            error_log("OneDrive archive notice: Archive upload skipped or failed [{$safeError}]. Local file preserved.");
            return null;
        }
    } catch (\Throwable $e) {
        // Absolute isolation: OneDrive failure must NEVER disrupt local upload
        $safeMsg = preg_replace('/[^\w\s\.\-_:]/', '', $e->getMessage());
        error_log("OneDrive archive notice: Exception during archive upload [{$safeMsg}]. Local file preserved.");
        return null;
    }
}

function getStatusBadge(string $status): string
{
    $map = [
        'draft'                  => ['Draft',            'badge-secondary'],
        'submitted'              => ['Submitted',        'badge-primary'],
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
        $typeLabel = ($type === 'open_ended' || $type === 'text') ? 'Open-ended' : 'Rating Scale';
        $badgeClass = ($type === 'open_ended' || $type === 'text') ? 'badge-secondary' : 'badge-info';
        
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
    require_once __DIR__ . '/EmailService.php';

    if ($userId === null && function_exists('currentUser')) {
        $cur = currentUser();
        if ($cur) {
            $userId = (int)($cur['id'] ?? 0);
            $role   = $role ?? ($cur['role'] ?? null);
        }
    }

    $params = [];
    if ($role === 'faculty') {
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
            WHERE status IN ('under_review', 'resubmitted') 
              AND source = 'student_org' 
              AND updated_at < NOW() - INTERVAL 3 DAY
        ";
    } elseif ($role === 'admin2') {
        $sql = "
            SELECT id, faculty_id, title, status, source, updated_at 
            FROM activities 
            WHERE ((status IN ('submitted', 'endorsed')) OR (status = 'resubmitted' AND source = 'faculty')) 
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

    $emailService = new EmailService();
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
        if ($status === 'under_review' || ($status === 'resubmitted' && $source === 'student_org')) {
            $reviewerRole = 'admin1';
        } elseif ($status === 'submitted' || $status === 'endorsed' || ($status === 'resubmitted' && $source === 'faculty')) {
            $reviewerRole = 'admin2';
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
                if (!empty($u['email'])) {
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
                    } catch (Throwable $e) {
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
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
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
}

/**
 * Ensure task_reminder_logs table exists for deduplication
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
        $ensured = true;
    } catch (Throwable $e) {
        // Table may already exist
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
    ensureTaskReminderLogsTable($db);
    require_once __DIR__ . '/EmailService.php';

    if ($userId === null && function_exists('currentUser')) {
        $cur = currentUser();
        if ($cur) {
            $userId = (int)($cur['id'] ?? 0);
            $role   = $role ?? ($cur['role'] ?? null);
        }
    }

    $whereClauses = ["a.status != 'rejected'"];
    $params = [];

    if ($activityId !== null && $activityId > 0) {
        $whereClauses[] = "ft.activity_id = ?";
        $params[] = $activityId;
    } elseif ($userId !== null && $userId > 0 && $role === 'faculty') {
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
    $emailService = new EmailService();
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

                if (!empty($target['email'])) {
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
                    } catch (Throwable $e) {
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
}

// Post-Activity Compliance calculation helper
require_once __DIR__ . '/compliance_helper.php';

