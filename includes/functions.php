<?php
function sanitize(string $val): string
{
    return htmlspecialchars(strip_tags(trim($val)), ENT_QUOTES, 'UTF-8');
}

function jsonResponse(mixed $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function uploadFile(array $file, string $subdir, int $activityId): string|false
{
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS)) return false;
    if ($file['size'] > MAX_FILE_SIZE) return false;
    
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
    
    if (move_uploaded_file($file['tmp_name'], $dir . $name)) {
        return $subdir . '/' . $name;
    }
    return false;
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

function getNotifications(int $userId): array
{
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 10');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}
