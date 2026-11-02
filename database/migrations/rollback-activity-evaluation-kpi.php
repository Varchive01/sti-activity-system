<?php
/**
 * Rollback: Revert Activity Evaluation and KPI Measurement columns from activity_participants
 * STI-ARMES
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden: Rollback scripts can only be executed via CLI.');
}
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

$db = getDB();

try {
    echo "Starting rollback: Activity Evaluation and KPI Measurement...\n";

    $cols = ['learning_kpi_result', 'post_test_score', 'pre_test_score', 'actual_attendees'];

    foreach ($cols as $colName) {
        $check = $db->query("SHOW COLUMNS FROM activity_participants LIKE '$colName'");
        if ($check->rowCount() > 0) {
            $db->exec("ALTER TABLE activity_participants DROP COLUMN $colName");
            echo "Dropped column '$colName' from 'activity_participants'.\n";
        } else {
            echo "Column '$colName' does not exist in 'activity_participants'.\n";
        }
    }

    echo "Rollback completed successfully.\n";
} catch (Exception $e) {
    echo "Rollback failed: " . $e->getMessage() . "\n";
    exit(1);
}

