<?php
require_once __DIR__ . '/../../config/database.php';
$db = getDB();
$col = $db->query("SHOW COLUMNS FROM schedules LIKE 'schedule_type'")->fetch();
if (!$col) {
    $db->exec("ALTER TABLE schedules ADD COLUMN schedule_type VARCHAR(50) NOT NULL DEFAULT 'main_event' AFTER event_name");
    echo "COLUMN schedule_type ADDED TO schedules TABLE\n";
} else {
    echo "COLUMN schedule_type ALREADY EXISTS\n";
}
