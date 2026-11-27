<?php
require_once __DIR__ . '/../../config/database.php';
$db = getDB();

echo "Running Migration: Add end_date to activities...\n";
try {
    $check = $db->query("SHOW COLUMNS FROM activities LIKE 'end_date'")->fetch();
    if (!$check) {
        $db->exec("ALTER TABLE activities ADD COLUMN end_date DATE NULL DEFAULT NULL AFTER event_date");
        // Backfill existing activities so end_date = event_date
        $db->exec("UPDATE activities SET end_date = event_date WHERE end_date IS NULL AND event_date IS NOT NULL");
        echo "[SUCCESS] Added 'end_date' column and backfilled existing activities.\n";
    } else {
        echo "[INFO] 'end_date' column already exists in activities.\n";
    }
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
}
