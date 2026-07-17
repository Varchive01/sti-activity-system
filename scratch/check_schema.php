<?php
require 'c:/xampp/htdocs/sti-activity-system/config/config.php';
require 'c:/xampp/htdocs/sti-activity-system/config/database.php';
$db = getDB();
$schema = $db->query('SHOW COLUMNS FROM approval_logs')->fetchAll(PDO::FETCH_ASSOC);
print_r($schema);
