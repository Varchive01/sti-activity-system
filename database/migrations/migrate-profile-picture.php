<?php
/**
 * database/migrations/migrate-profile-picture.php
 *
 * Idempotent migration script ensuring the `profile_picture` column exists
 * on the `users` table.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden: Migration scripts can only be executed via CLI.');
}

require_once __DIR__ . '/../../config/database.php';

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
    $avatarsDir = __DIR__ . '/../../uploads/avatars';
    if (!is_dir($avatarsDir)) {
        mkdir($avatarsDir, 0755, true);
    }

    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . PHP_EOL;
}
