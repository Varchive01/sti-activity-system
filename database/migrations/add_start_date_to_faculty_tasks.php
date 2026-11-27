<?php
require_once __DIR__ . '/../../config/database.php';
$db = getDB();

try {
    $col = $db->query("SHOW COLUMNS FROM faculty_tasks LIKE 'start_date'")->fetch();
    if (!$col) {
        $db->exec("ALTER TABLE faculty_tasks ADD COLUMN start_date DATE NULL DEFAULT NULL AFTER role_in_event");
        echo "[SUCCESS] Added 'start_date' column to 'faculty_tasks' table.\n";
    } else {
        echo "[INFO] 'start_date' column already exists in 'faculty_tasks' table.\n";
    }
} catch (\Throwable $e) {
    echo "[ERROR] Failed to add start_date: " . $e->getMessage() . "\n";
    exit(1);
}
