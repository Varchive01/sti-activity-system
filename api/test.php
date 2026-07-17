<?php
require '../config/config.php';
require '../config/database.php';
$db=getDB();
$q=$db->query('DESCRIBE floor_plans');
print_r($q->fetchAll(PDO::FETCH_ASSOC));
$q=$db->query('DESCRIBE activities');
print_r($q->fetchAll(PDO::FETCH_ASSOC));
