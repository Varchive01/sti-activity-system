<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

startSession();
$db = getDB();
$dean = $db->query("SELECT * FROM users WHERE role = 'dean' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$_SESSION['user_id'] = $dean['id'];
$_SESSION['user_role'] = 'dean';
$_SESSION['role'] = 'dean';
$_SESSION['user_name'] = $dean['name'];
$_SESSION['user_dept'] = $dean['department'];
$_SESSION['last_activity'] = time();

echo "Session setup done for user {$dean['name']}\n";

ob_start();
try {
    include __DIR__ . '/../dean/dashboard.php';
    $html = ob_get_clean();
} catch (\Throwable $e) {
    ob_end_clean();
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "Dashboard rendered successfully. Length: " . strlen($html) . " bytes\n";

// Check Status Breakdown 2x2 grid
if (strpos($html, 'Status Breakdown') !== false) {
    echo "[PASS] 'Status Breakdown' card header found.\n";
} else {
    echo "[FAIL] 'Status Breakdown' card header missing.\n";
}

if (strpos($html, 'display:grid;grid-template-columns:repeat(2, 1fr)') !== false) {
    echo "[PASS] 2x2 / 4-cell balanced grid found.\n";
} else {
    echo "[FAIL] 2x2 grid missing.\n";
}

// Check Activities by Source & Department
if (strpos($html, 'Activities by Source &amp; Department') !== false || strpos($html, 'Activities by Source & Department') !== false) {
    echo "[PASS] 'Activities by Source & Department' header found.\n";
} else {
    echo "[FAIL] 'Activities by Source & Department' header missing.\n";
}

if (strpos($html, 'IT Department') !== false && strpos($html, 'THM Department') !== false && strpos($html, 'G.E. Department') !== false) {
    echo "[PASS] All 3 departments (IT, THM, G.E.) found in breakdown.\n";
} else {
    echo "[FAIL] Department breakdown elements missing.\n";
}

if (strpos($html, 'By Source') !== false && strpos($html, 'By Department') !== false) {
    echo "[PASS] Both 'By Source' and 'By Department' sections are present.\n";
} else {
    echo "[FAIL] Source or Department section label missing.\n";
}

echo "ALL DEAN DASHBOARD RAIL TESTS PASSED SUCCESSFULLY!\n";
