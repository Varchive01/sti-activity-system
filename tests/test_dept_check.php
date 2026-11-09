<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$stmt = $db->query("SELECT DISTINCT department FROM users");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt2 = $db->query("SELECT u.department, COUNT(*) as cnt FROM activities a JOIN users u ON a.faculty_id = u.id WHERE a.status != 'draft' GROUP BY u.department");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));

