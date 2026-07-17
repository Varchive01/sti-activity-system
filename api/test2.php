<?php
require '../config/config.php';
require '../config/database.php';
$db=getDB();
$q=$db->query('DESCRIBE kpi_evaluations');
print_r($q->fetchAll(PDO::FETCH_ASSOC));
