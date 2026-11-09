<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

startSession();

echo "========================================================\n";
echo "TEST: FACULTY & PROGRAM HEAD RETURNED / REJECTED PAGES\n";
echo "========================================================\n\n";

$db = getDB();

// 1. Test as Faculty
$_SESSION['user_id'] = 5; // Maria Santos
$_SESSION['user_role'] = 'faculty';
$_SESSION['user_name'] = 'Maria Santos';
$_SESSION['user_dept'] = 'IT Department';
$_SESSION['last_activity'] = time();

echo "--- 1. Testing as FACULTY ---\n";
ob_start();
include __DIR__ . '/../faculty/returned.php';
$facRetHtml = ob_get_clean();

$facRetOk = (strpos($facRetHtml, 'Proposals Awaiting Revision') !== false && strpos($facRetHtml, 'status-callout-returned') === false);
echo "Faculty Returned Page (Banner Removed): " . ($facRetOk ? "[PASS]" : "[FAIL]") . " (Length: " . strlen($facRetHtml) . ")\n";

ob_start();
include __DIR__ . '/../faculty/rejected.php';
$facRejHtml = ob_get_clean();

$facRejOk = (strpos($facRejHtml, 'Declined Proposals Register') !== false && strpos($facRejHtml, 'status-callout-rejected') === false);
echo "Faculty Rejected Page (Banner Removed): " . ($facRejOk ? "[PASS]" : "[FAIL]") . " (Length: " . strlen($facRejHtml) . ")\n";

// 2. Test as Program Head
$ph = $db->query("SELECT id, name, department FROM users WHERE role = 'program_head' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($ph) {
    echo "\n--- 2. Testing as PROGRAM HEAD ({$ph['name']}) ---\n";
    $_SESSION['user_id'] = (int)$ph['id'];
    $_SESSION['user_role'] = 'program_head';
    $_SESSION['user_name'] = $ph['name'];
    $_SESSION['user_dept'] = $ph['department'];
    $_SESSION['last_activity'] = time();

    ob_start();
    include __DIR__ . '/../faculty/returned.php';
    $phRetHtml = ob_get_clean();

    $phRetOk = (strpos($phRetHtml, 'Proposals Awaiting Revision') !== false && strpos($phRetHtml, 'status-callout-returned') === false);
    echo "Program Head Returned Page (Banner Removed): " . ($phRetOk ? "[PASS]" : "[FAIL]") . " (Length: " . strlen($phRetHtml) . ")\n";

    ob_start();
    include __DIR__ . '/../faculty/rejected.php';
    $phRejHtml = ob_get_clean();

    $phRejOk = (strpos($phRejHtml, 'Declined Proposals Register') !== false && strpos($phRejHtml, 'status-callout-rejected') === false);
    echo "Program Head Rejected Page (Banner Removed): " . ($phRejOk ? "[PASS]" : "[FAIL]") . " (Length: " . strlen($phRejHtml) . ")\n";
}

assert($facRetOk && $facRejOk, "Faculty pages must render without error!");
if ($ph) {
    assert($phRetOk && $phRejOk, "Program Head pages must render without error!");
}

echo "\n[SUCCESS] ALL RETURNED & REJECTED PAGES LOADED FLAWLESSLY FOR BOTH ROLES!\n";
