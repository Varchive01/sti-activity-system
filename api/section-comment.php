<?php
/**
 * api/section-comment.php
 * AJAX endpoint for per-section reviewer comments (staged before Return for Revision).
 *
 * POST body (JSON):
 *   { action: 'save',   activity_id, section_key, comment }
 *   { action: 'delete', activity_id, section_key }
 *   { action: 'list',   activity_id }
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Only reviewers may call this endpoint
requireRole('admin1', 'admin2', 'dean');

header('Content-Type: application/json');

$user   = currentUser();
$db     = getDB();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';
$actId  = (int)($input['activity_id'] ?? 0);

if (!$actId) {
    echo json_encode(['success' => false, 'error' => 'Missing activity_id']);
    exit;
}

// Verify activity exists
$actCheck = $db->prepare("SELECT id FROM activities WHERE id = ?");
$actCheck->execute([$actId]);
if (!$actCheck->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Activity not found']);
    exit;
}

switch ($action) {

    case 'save': {
        $sectionKey = preg_replace('/[^a-z_]/', '', strtolower($input['section_key'] ?? ''));
        $comment    = trim($input['comment'] ?? '');
        if (!$sectionKey || $comment === '') {
            echo json_encode(['success' => false, 'error' => 'section_key and comment are required']);
            exit;
        }
        // Upsert — one comment per reviewer per section per activity
        $stmt = $db->prepare("
            INSERT INTO section_comments (activity_id, reviewer_id, section_key, comment)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE comment = VALUES(comment), updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$actId, $user['id'], $sectionKey, $comment]);
        echo json_encode(['success' => true, 'section_key' => $sectionKey]);
        break;
    }

    case 'delete': {
        $sectionKey = preg_replace('/[^a-z_]/', '', strtolower($input['section_key'] ?? ''));
        if (!$sectionKey) {
            echo json_encode(['success' => false, 'error' => 'section_key required']);
            exit;
        }
        $stmt = $db->prepare("
            DELETE FROM section_comments
            WHERE activity_id = ? AND reviewer_id = ? AND section_key = ?
        ");
        $stmt->execute([$actId, $user['id'], $sectionKey]);
        echo json_encode(['success' => true]);
        break;
    }

    case 'list': {
        $stmt = $db->prepare("
            SELECT section_key, comment, updated_at
            FROM section_comments
            WHERE activity_id = ? AND reviewer_id = ?
        ");
        $stmt->execute([$actId, $user['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Index by section_key for easy lookup on the client
        $map = [];
        foreach ($rows as $r) {
            $map[$r['section_key']] = ['comment' => $r['comment'], 'updated_at' => $r['updated_at']];
        }
        echo json_encode(['success' => true, 'comments' => $map]);
        break;
    }

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
