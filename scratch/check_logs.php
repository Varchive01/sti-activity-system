<?php
require 'c:/xampp/htdocs/sti-activity-system/config/config.php';
require 'c:/xampp/htdocs/sti-activity-system/config/database.php';
$db = getDB();
$logs = $db->query("SELECT al.*, u.role, u.name FROM approval_logs al JOIN users u ON al.reviewer_id=u.id ORDER BY acted_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
print_r($logs);
