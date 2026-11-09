<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

echo "========================================================\n";
echo "TEST: ADMIN 1 & ADMIN 2 RETURNED / REJECTED SEPARATION\n";
echo "========================================================\n\n";

$admin1 = $db->query("SELECT * FROM users WHERE role = 'admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$_SESSION['user_id'] = $admin1['id'];
$_SESSION['user_role'] = 'admin1';
$_SESSION['role'] = 'admin1';
$_SESSION['user_name'] = $admin1['name'];
$_SESSION['user_dept'] = $admin1['department'];
$_SESSION['last_activity'] = time();

// 1. Sidebar verification
ob_start();
include __DIR__ . '/../includes/sidebar.php';
$sidebarHtml = ob_get_clean();

// Check Admin 1 sidebar
assert(strpos($sidebarHtml, '/admin1/returned.php') !== false, "Admin 1 must have /admin1/returned.php in sidebar");
assert(strpos($sidebarHtml, '/admin1/rejected.php') !== false, "Admin 1 must have /admin1/rejected.php in sidebar");
echo "[PASS] Admin 1 sidebar includes separate links for Returned for Revision and Rejected Proposals.\n";

// 2. Admin 1 Returned page render
ob_start();
include __DIR__ . '/../admin1/returned.php';
$admin1RetHtml = ob_get_clean();
assert(strpos($admin1RetHtml, 'Returned for Revision') !== false, "admin1/returned.php must render Returned for Revision title");
echo "[PASS] admin1/returned.php rendered successfully.\n";

// 3. Admin 1 Rejected page render
ob_start();
include __DIR__ . '/../admin1/rejected.php';
$admin1RejHtml = ob_get_clean();
assert(strpos($admin1RejHtml, 'Rejected Proposals') !== false, "admin1/rejected.php must render Rejected Proposals title");
echo "[PASS] admin1/rejected.php rendered successfully.\n";

// 4. Admin 2 verification
$admin2 = $db->query("SELECT * FROM users WHERE role = 'admin2' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$_SESSION['user_id'] = $admin2['id'];
$_SESSION['user_role'] = 'admin2';
$_SESSION['role'] = 'admin2';
$_SESSION['user_name'] = $admin2['name'];
$_SESSION['user_dept'] = $admin2['department'];
$_SESSION['last_activity'] = time();

// Check Admin 2 sidebar
ob_start();
include __DIR__ . '/../includes/sidebar.php';
$sidebarHtml2 = ob_get_clean();
assert(strpos($sidebarHtml2, '/admin2/returned.php') !== false, "Admin 2 must have /admin2/returned.php in sidebar");
assert(strpos($sidebarHtml2, '/admin2/rejected.php') !== false, "Admin 2 must have /admin2/rejected.php in sidebar");
echo "[PASS] Admin 2 sidebar includes separate links for Returned for Revision and Rejected Proposals.\n";

// 5. Admin 2 Returned page render
ob_start();
include __DIR__ . '/../admin2/returned.php';
$admin2RetHtml = ob_get_clean();
assert(strpos($admin2RetHtml, 'Returned for Revision') !== false, "admin2/returned.php must render Returned for Revision title");
echo "[PASS] admin2/returned.php rendered successfully.\n";

// 6. Admin 2 Rejected page render
ob_start();
include __DIR__ . '/../admin2/rejected.php';
$admin2RejHtml = ob_get_clean();
assert(strpos($admin2RejHtml, 'Rejected Proposals') !== false, "admin2/rejected.php must render Rejected Proposals title");
echo "[PASS] admin2/rejected.php rendered successfully.\n";

echo "\n[SUCCESS] ALL ADMIN 1 & ADMIN 2 SEPARATION TESTS PASSED!\n";
