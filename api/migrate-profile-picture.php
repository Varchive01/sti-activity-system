<?php
/**
 * api/migrate-profile-picture.php
 *
 * Idempotent migration script ensuring the `profile_picture` column exists
 * on the `users` table.
 */

require_once __DIR__ . '/../config/database.php';

try {
    $db = getDB();
    $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'profile_picture'");
    $col = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$col) {
        $db->exec("ALTER TABLE `users` ADD COLUMN `profile_picture` VARCHAR(500) NULL DEFAULT NULL AFTER `department`");
        $result = ['success' => true, 'action' => 'column_added'];
    } else {
        $result = ['success' => true, 'action' => 'already_exists'];
    }

    // Ensure uploads/avatars directory exists
    $avatarsDir = __DIR__ . '/../uploads/avatars';
    if (!is_dir($avatarsDir)) {
        mkdir($avatarsDir, 0755, true);
    }

    if (php_sapi_name() === 'cli') {
        echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        header('Content-Type: application/json');
        echo json_encode($result);
    }
} catch (Exception $e) {
    if (php_sapi_name() === 'cli') {
        echo "Migration failed: " . $e->getMessage() . PHP_EOL;
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
