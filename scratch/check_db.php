<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();
$activities = $db->query("SELECT id, title, source, status, faculty_id, updated_at FROM activities ORDER BY id DESC LIMIT 5")->fetchAll();
echo "=== ACTIVITIES ===\n";
print_r($activities);
