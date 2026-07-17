<?php
require 'c:/xampp/htdocs/sti-activity-system/config/config.php';
require 'c:/xampp/htdocs/sti-activity-system/config/database.php';
$db = getDB();
$users = $db->query("SELECT id, name, role FROM users")->fetchAll(PDO::FETCH_ASSOC);
print_r($users);
