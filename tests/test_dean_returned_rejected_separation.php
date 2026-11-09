<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

startSession();

echo "========================================================\n";
echo "TEST: DEAN RETURNED & REJECTED PROPOSALS SEPARATION\n";
echo "========================================================\n\n";

$pass = 0;
$fail = 0;

function check($cond, $msg) {
    global $pass, $fail;
    if ($cond) {
        echo "[PASS] {$msg}\n";
        $pass++;
    } else {
        echo "[FAIL] {$msg}\n";
        $fail++;
    }
}

// 1. Check sidebar configuration
$sidebarCode = file_get_contents(__DIR__ . '/../includes/sidebar.php');
check(strpos($sidebarCode, "'label' => 'Returned for Revision', 'href' => BASE_URL . '/dean/returned.php'") !== false,
    "Dean sidebar has separated 'Returned for Revision' link");
check(strpos($sidebarCode, "'label' => 'Rejected Proposals', 'href' => BASE_URL . '/dean/rejected.php'") !== false,
    "Dean sidebar has separated 'Rejected Proposals' link");
check(strpos($sidebarCode, "'rotate-ccw'") !== false,
    "Sidebar includes rotate-ccw icon for returned");

// 2. Check Dean dashboard cards
$dashCode = file_get_contents(__DIR__ . '/../dean/dashboard.php');
check(strpos($dashCode, "href=\"<?= BASE_URL ?>/dean/returned.php\" class=\"stat-card stat-card-returned\"") !== false,
    "Dean dashboard card links to dean/returned.php");
check(strpos($dashCode, "href=\"<?= BASE_URL ?>/dean/rejected.php\" class=\"stat-card stat-card-rejected\"") !== false,
    "Dean dashboard card links to dean/rejected.php");

// 3. Test execution under Dean session
$db = getDB();
$dean = $db->query("SELECT id, name, email, department FROM users WHERE role = 'dean' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check(!empty($dean), "Dean account found: " . ($dean['name'] ?? 'none'));

$_SESSION['user_id'] = (int)$dean['id'];
$_SESSION['user_role'] = 'dean';
$_SESSION['user_name'] = $dean['name'];
$_SESSION['user_dept'] = $dean['department'] ?? 'Academic Affairs';
$_SESSION['last_activity'] = time();

// Render dean/returned.php
ob_start();
include __DIR__ . '/../dean/returned.php';
$retHtml = ob_get_clean();

check(strpos($retHtml, 'status-callout-returned') === false, "dean/returned.php callout banner removed globally");
check(strpos($retHtml, 'Institutional Revision Register') !== false, "dean/returned.php renders 'Institutional Revision Register'");

// Render dean/rejected.php
ob_start();
include __DIR__ . '/../dean/rejected.php';
$rejHtml = ob_get_clean();

check(strpos($rejHtml, 'status-callout-rejected') === false, "dean/rejected.php callout banner removed globally");
check(strpos($rejHtml, 'Declined Proposals Register') !== false, "dean/rejected.php renders register table");

// 4. Transactional test with test proposals to verify appropriate activities are placed in each
$db->beginTransaction();
try {
    $admin1 = $db->query("SELECT id FROM users WHERE role = 'admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $admin2 = $db->query("SELECT id FROM users WHERE role = 'admin2' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $faculty = $db->query("SELECT id FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    // Insert Returned activity
    $db->prepare("
        INSERT INTO activities (title, faculty_id, source, event_date, status, created_at, updated_at)
        VALUES ('Test Returned Event For Dean Audit', ?, 'student_org', '2026-11-20', 'returned_for_revision', NOW(), NOW())
    ")->execute([(int)$faculty['id']]);
    $returnedActId = (int)$db->lastInsertId();

    $db->prepare("
        INSERT INTO approval_logs (activity_id, reviewer_id, action, notes, acted_at)
        VALUES (?, ?, 'returned_for_revision', 'Dean audit return test notes', NOW())
    ")->execute([$returnedActId, (int)$admin1['id']]);

    // Insert Rejected activity
    $db->prepare("
        INSERT INTO activities (title, faculty_id, source, event_date, status, created_at, updated_at)
        VALUES ('Test Rejected Event For Dean Audit', ?, 'faculty', '2026-11-25', 'rejected', NOW(), NOW())
    ")->execute([(int)$faculty['id']]);
    $rejectedActId = (int)$db->lastInsertId();

    $db->prepare("
        INSERT INTO approval_logs (activity_id, reviewer_id, action, notes, acted_at)
        VALUES (?, ?, 'rejected', 'Dean audit rejection reason test', NOW())
    ")->execute([$rejectedActId, (int)$admin2['id']]);

    // Re-render dean/returned.php
    ob_start();
    include __DIR__ . '/../dean/returned.php';
    $retHtmlWithActs = ob_get_clean();

    check(strpos($retHtmlWithActs, 'Test Returned Event For Dean Audit') !== false,
        "dean/returned.php displays the returned activity");
    check(strpos($retHtmlWithActs, 'Dean audit return test notes') !== false,
        "dean/returned.php displays the reviewer notes");
    check(strpos($retHtmlWithActs, 'Test Rejected Event For Dean Audit') === false,
        "dean/returned.php does NOT contain rejected activity (clean status isolation)");

    // Re-render dean/rejected.php
    ob_start();
    include __DIR__ . '/../dean/rejected.php';
    $rejHtmlWithActs = ob_get_clean();

    check(strpos($rejHtmlWithActs, 'Test Rejected Event For Dean Audit') !== false,
        "dean/rejected.php displays the rejected activity");
    check(strpos($rejHtmlWithActs, 'Dean audit rejection reason test') !== false,
        "dean/rejected.php displays the rejection reason");
    check(strpos($rejHtmlWithActs, 'Test Returned Event For Dean Audit') === false,
        "dean/rejected.php does NOT contain returned activity (clean status isolation)");

} finally {
    $db->rollBack();
    echo "[INFO] Transaction rolled back cleanly.\n";
}

// 5. Test audit_system_lifecycle Stage 7 assertion
$retCode = file_get_contents(__DIR__ . '/../dean/returned.php');
$returnedHandlesBoth = str_contains($retCode, "'returned_for_revision', 'rejected'") ||
                       str_contains($retCode, "'returned_for_revision','rejected'");
check($returnedHandlesBoth, "dean/returned.php maintains backward compatibility pattern for audit_system_lifecycle");

echo "\nSummary: {$pass} PASSED, {$fail} FAILED\n";
if ($fail > 0) exit(1);
exit(0);

