<?php
/**
 * database/migrations/migrate-program-head-role.php
 *
 * Migration to add 'program_head' to users table role ENUM.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden: Migration scripts can only be executed via CLI.');
}

require_once __DIR__ . '/../../config/database.php';

try {
    $db = getDB();
    $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'role'");
    $col = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($col && str_contains($col['Type'], "'program_head'")) {
        $result = ['success' => true, 'action' => 'already_exists', 'type' => $col['Type']];
    } else {
        $db->exec("ALTER TABLE `users` MODIFY COLUMN `role` ENUM('faculty','admin1','admin2','dean','program_head') NOT NULL");
        $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'role'");
        $updatedCol = $stmt->fetch(PDO::FETCH_ASSOC);
        $result = ['success' => true, 'action' => 'role_enum_updated', 'type' => $updatedCol['Type']];
    }

    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . PHP_EOL;
}

