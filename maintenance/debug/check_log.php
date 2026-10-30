<?php
require 'config/config.php';
require 'config/database.php';
$db = getDB();
$stmt = $db->query('SELECT error_message FROM email_logs ORDER BY id DESC LIMIT 1');
print_r($stmt->fetch());
