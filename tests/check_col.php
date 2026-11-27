<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$cols = $db->query("SHOW COLUMNS FROM approval_logs LIKE 'action'")->fetch(PDO::FETCH_ASSOC);
print_r($cols);
