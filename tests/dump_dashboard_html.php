<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$db = getDB();
$u = $db->query("SELECT * FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
startSession();
$_SESSION['user_id'] = $u['id'];
$_SESSION['user_role'] = 'faculty';
$_SESSION['last_activity'] = time();
$_SESSION['user'] = $u;

ob_start();
include __DIR__ . '/../faculty/dashboard.php';
$html = ob_get_clean();

file_put_contents(__DIR__ . '/test_faculty_dashboard_rendered.html', $html);
echo "Wrote " . strlen($html) . " bytes to test_faculty_dashboard_rendered.html\n";
