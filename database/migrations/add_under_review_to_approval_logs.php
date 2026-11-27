<?php
require_once __DIR__ . '/../../config/database.php';

try {
    $db = getDB();
    $db->exec("ALTER TABLE approval_logs MODIFY COLUMN action ENUM('approved','rejected','returned_for_revision','resubmitted','forwarded','under_review') NOT NULL");
    echo "Migration successful: 'under_review' added to approval_logs.action ENUM.\n";
} catch (\Throwable $e) {
    echo "Migration failed or already applied: " . $e->getMessage() . "\n";
}
