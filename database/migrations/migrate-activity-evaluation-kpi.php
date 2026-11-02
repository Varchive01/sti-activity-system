<?php
/**
 * Migration: Add Activity Evaluation and KPI Measurement columns to activity_participants
 * STI-ARMES
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden: Migration scripts can only be executed via CLI.');
}
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

$db = getDB();

try {
    echo "Starting migration: Activity Evaluation and KPI Measurement...\n";

    // 1. Ensure activity_participants table exists
    $db->exec("CREATE TABLE IF NOT EXISTS activity_participants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        activity_id INT NOT NULL,
        row_no INT NOT NULL DEFAULT 1,
        subject_section VARCHAR(255) NOT NULL DEFAULT '',
        participants_count INT NOT NULL DEFAULT 0,
        teacher_name VARCHAR(255) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_activity_id (activity_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 2. Add KPI columns to activity_participants if they don't already exist
    $cols = [
        'actual_attendees' => "ALTER TABLE activity_participants ADD COLUMN actual_attendees INT NULL DEFAULT NULL AFTER participants_count",
        'pre_test_score' => "ALTER TABLE activity_participants ADD COLUMN pre_test_score DECIMAL(5,2) NULL DEFAULT NULL AFTER actual_attendees",
        'post_test_score' => "ALTER TABLE activity_participants ADD COLUMN post_test_score DECIMAL(5,2) NULL DEFAULT NULL AFTER pre_test_score",
        'learning_kpi_result' => "ALTER TABLE activity_participants ADD COLUMN learning_kpi_result VARCHAR(100) NULL DEFAULT NULL AFTER post_test_score"
    ];

    foreach ($cols as $colName => $alterSql) {
        $check = $db->query("SHOW COLUMNS FROM activity_participants LIKE '$colName'");
        if ($check->rowCount() === 0) {
            $db->exec($alterSql);
            echo "Added column '$colName' to 'activity_participants'.\n";
        } else {
            echo "Column '$colName' already exists in 'activity_participants'.\n";
        }
    }

    echo "Migration completed successfully.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

