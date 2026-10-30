<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden: Migration scripts can only be executed via CLI.');
}
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
$db = getDB();

try {
    // Check if evaluation_questions column exists
    $q = $db->query("SHOW COLUMNS FROM activities LIKE 'evaluation_questions'");
    if ($q->rowCount() === 0) {
        $db->exec("ALTER TABLE activities ADD COLUMN evaluation_questions LONGTEXT NULL");
        echo "Column 'evaluation_questions' added successfully to 'activities' table.\n";
    } else {
        echo "Column 'evaluation_questions' already exists.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
