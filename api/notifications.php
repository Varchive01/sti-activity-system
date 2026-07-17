<?php
// /api/notifications.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
startSession();

header('Content-Type: application/json');

$user = currentUser();
if (!$user || !$user['id']) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db   = getDB();
$body = json_decode(file_get_contents('php://input'), true);
$action = $body['action'] ?? '';

switch ($action) {

    case 'mark_read':
        $id = (int)($body['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false]);
            exit;
        }
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user['id']]);
        echo json_encode(['success' => $stmt->rowCount() > 0]);
        break;

    case 'mark_all_read':
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user['id']]);
        echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
        break;

    case 'delete':
        $id = (int)($body['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false]);
            exit;
        }
        $stmt = $db->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user['id']]);
        echo json_encode(['success' => true]);
        break;

    case 'delete_all':
        $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
