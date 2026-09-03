<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$db->exec("DELETE FROM activities WHERE title = 'Leadership Seminar' AND venue = 'AVR' AND event_date = '2026-08-20'");
echo "Cleaned up AVR mock activity\n";
