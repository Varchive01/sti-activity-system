<?php
require 'c:/xampp/htdocs/sti-activity-system/config/config.php';
require 'c:/xampp/htdocs/sti-activity-system/config/database.php';
$db = getDB();
$db->query("UPDATE approval_logs SET action = 'returned_for_revision' WHERE action = ''");
echo 'Fixed existing logs';
