<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$stmt = $db->query("SELECT email, role FROM users WHERE role = 'faculty' LIMIT 3");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
