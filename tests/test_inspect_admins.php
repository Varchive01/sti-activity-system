<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$u1 = $db->query("SELECT * FROM users WHERE role = 'admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$u2 = $db->query("SELECT * FROM users WHERE role = 'admin2' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo "Admin 1: " . $u1['name'] . " (ID: " . $u1['id'] . ")\n";
echo "Admin 2: " . $u2['name'] . " (ID: " . $u2['id'] . ")\n";

// Check approval logs for both
$stmt1 = $db->prepare("SELECT COUNT(*) FROM approval_logs WHERE reviewer_id = ?");
$stmt1->execute([$u1['id']]);
echo "Admin 1 log count: " . $stmt1->fetchColumn() . "\n";

$stmt2 = $db->prepare("SELECT COUNT(*) FROM approval_logs WHERE reviewer_id = ?");
$stmt2->execute([$u2['id']]);
echo "Admin 2 log count: " . $stmt2->fetchColumn() . "\n";

// Check all approval logs
$allLogs = $db->query("SELECT al.*, u.name, u.role FROM approval_logs al JOIN users u ON al.reviewer_id = u.id ORDER BY al.id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
print_r($allLogs);

