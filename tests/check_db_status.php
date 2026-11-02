<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();
echo "Active DB Name: " . DB_NAME . "\n";
echo "Host: " . DB_HOST . ":" . DB_PORT . "\n";

$stmt = $db->query("SHOW COLUMNS FROM users LIKE 'role'");
$col = $stmt->fetch();
echo "users.role Type: " . ($col['Type'] ?? 'unknown') . "\n\n";

$counts = $db->query("SELECT role, COUNT(*) as cnt FROM users GROUP BY role ORDER BY cnt DESC")->fetchAll();
echo "Current Accounts by Role:\n";
foreach ($counts as $r) {
    echo " - " . str_pad($r['role'], 15) . ": " . $r['cnt'] . "\n";
}

$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "\nTotal Tables in Database: " . count($tables) . "\n";
echo "Key Tables:\n";
foreach ($tables as $t) {
    if (in_array($t, ['users', 'activities', 'faculty_tasks', 'post_event', 'kpi_evaluations', 'notifications', 'task_deliverables', 'approval_logs', 'floor_plans', 'materials', 'schedules', 'program_sequence', 'guidelines'], true)) {
        $c = $db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo " - " . str_pad($t, 22) . ": $c rows\n";
    }
}

