<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$logs = $db->query("
    SELECT al.*, u.name as reviewer_name, u.role as reviewer_role, a.title 
    FROM approval_logs al
    JOIN users u ON al.reviewer_id = u.id
    JOIN activities a ON al.activity_id = a.id
    ORDER BY al.id DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
print_r($logs);

