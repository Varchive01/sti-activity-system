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
