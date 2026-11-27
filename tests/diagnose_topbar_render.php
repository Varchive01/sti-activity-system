<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$db = getDB();
$u = $db->query("SELECT * FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$u) {
    die("No faculty user found in DB\n");
}
startSession();
$_SESSION['user_id'] = $u['id'];
$_SESSION['user_role'] = 'faculty';
$_SESSION['last_activity'] = time();
$_SESSION['user'] = $u;

ob_start();
include __DIR__ . '/../faculty/dashboard.php';
$html = ob_get_clean();

echo "HTML length: " . strlen($html) . "\n";
echo "Has notifBellBtn: " . (strpos($html, 'notifBellBtn') !== false ? 'YES' : 'NO') . "\n";
echo "Has topbarProfile: " . (strpos($html, 'topbarProfile') !== false ? 'YES' : 'NO') . "\n";
echo "Has notifDropdown: " . (strpos($html, 'notifDropdown') !== false ? 'YES' : 'NO') . "\n";
echo "Has topbarProfilePopover: " . (strpos($html, 'topbarProfilePopover') !== false ? 'YES' : 'NO') . "\n";
echo "Has notifications.js: " . (strpos($html, 'notifications.js') !== false ? 'YES' : 'NO') . "\n";

$topbarStart = strpos($html, '<header class="topbar">');
$topbarEnd = strpos($html, '</header>');
echo "\n--- TOPBAR HTML (first 1000 chars) ---\n";
echo substr($html, $topbarStart, 1000) . "\n";
