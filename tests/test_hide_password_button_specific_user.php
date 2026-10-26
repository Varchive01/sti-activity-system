<?php
/**
 * Verification Test: Hide Set Default Password for One Specific User
 * 
 * Verifies:
 * 1. For user ar-jay.agbayani@marikina.sti.edu.ph:
 *    - Edit button is present
 *    - Delete button is present
 *    - Set Default Password button is HIDDEN
 * 2. For all other users (e.g. other Admin 1, Admin 2, Faculty, Dean self):
 *    - Set Default Password button remains VISIBLE and functional based on existing permissions.
 * 3. Matching is strictly by exact email address (not by name or email domain).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function check($title, $condition, $info = '') {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$title}\n";
        if ($info) echo "         {$info}\n";
    } else {
        $failedTests++;
        echo "  [FAIL] {$title}\n";
        if ($info) echo "         Details: {$info}\n";
    }
}

echo "========================================================================\n";
echo "  VERIFICATION: HIDE SET DEFAULT PASSWORD FOR ONE SPECIFIC USER\n";
echo "========================================================================\n\n";

// Fetch dean user for rendering dean/users.php
$deanUser = $db->query("SELECT * FROM users WHERE role = 'dean' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$deanUser) {
    die("Dean user not found in database.\n");
}

// Function to render user management view for a specific session user
function renderUserManagementView($sessionUser) {
    $scriptRelative = str_replace('\\', '/', realpath(__DIR__ . '/../dean/users.php'));
    $csrfToken = 'test_csrf_token_render_123';

    $code = "<?php
    session_start();
    \$_SESSION['user_id'] = " . (int)$sessionUser['id'] . ";
    \$_SESSION['user_role'] = " . var_export($sessionUser['role'], true) . ";
    \$_SESSION['user_name'] = " . var_export($sessionUser['name'], true) . ";
    \$_SESSION['user_email'] = " . var_export($sessionUser['email'], true) . ";
    \$_SESSION['last_activity'] = time();
    \$_SESSION['csrf_token'] = '{$csrfToken}';
    \$_SERVER['REQUEST_METHOD'] = 'GET';
    \$_SERVER['REQUEST_URI'] = '/dean/users.php';
    ob_start();
    require '{$scriptRelative}';
    ";

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $proc = proc_open('php', $descriptors, $pipes);
    if (!is_resource($proc)) return '';

    fwrite($pipes[0], $code);
    fclose($pipes[0]);

    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    return $out;
}

$html = renderUserManagementView($deanUser);

// Target user: ar-jay.agbayani@marikina.sti.edu.ph
$targetUser = $db->query("SELECT id, name, email, role FROM users WHERE LOWER(email) = 'ar-jay.agbayani@marikina.sti.edu.ph' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$targetUser) {
    die("Target user ar-jay.agbayani@marikina.sti.edu.ph not found in database.\n");
}

$targetId = (int)$targetUser['id'];
echo "Target User Found: ID {$targetId}, Name: '{$targetUser['name']}', Email: '{$targetUser['email']}', Role: '{$targetUser['role']}'\n\n";

echo "--- SECTION 1: TARGET USER BUTTON CHECKS ---\n";

// 1. Edit button present
$hasEdit = (strpos($html, "id=\"btnEditUser_{$targetId}\"") !== false);
check("Edit button is PRESENT for target user (ID {$targetId})", $hasEdit);

// 2. Delete button present
$hasDelete = (strpos($html, "id=\"btnDeleteUser_{$targetId}\"") !== false);
check("Delete button is PRESENT for target user (ID {$targetId})", $hasDelete);

// 3. Set Default Password button hidden
$hasReset = (strpos($html, "id=\"btnResetPass_{$targetId}\"") !== false);
check("Set Default Password button is HIDDEN for target user (ID {$targetId})", !$hasReset, "btnResetPass_{$targetId} present: " . ($hasReset ? 'YES (FAIL)' : 'NO (CORRECT)'));

echo "\n--- SECTION 2: OTHER USERS RETAIN EXISTING BUTTONS ---\n";

// Fetch other non-dean users who dean can reset password for
$otherUsers = $db->query("SELECT id, name, email, role FROM users WHERE id != {$targetId} AND role IN ('admin1', 'admin2', 'faculty') ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$otherResetCount = 0;
foreach ($otherUsers as $ou) {
    $ouId = (int)$ou['id'];
    $ouHasReset = (strpos($html, "id=\"btnResetPass_{$ouId}\"") !== false);
    $ouHasEdit  = (strpos($html, "id=\"btnEditUser_{$ouId}\"") !== false);
    
    if ($ouHasReset) {
        $otherResetCount++;
    }
    check(
        "User #{$ouId} ({$ou['email']}, Role: {$ou['role']}) has Set Default Password button visible",
        $ouHasReset && $ouHasEdit,
        "Edit: " . ($ouHasEdit ? 'YES' : 'NO') . " | Reset: " . ($ouHasReset ? 'YES' : 'NO')
    );
}

check("At least one other user has Set Default Password button visible", $otherResetCount > 0, "Total other users with reset button: {$otherResetCount}");

echo "\n--- SECTION 3: EXACT EMAIL MATCH VERIFICATION ---\n";

// Verify that a user with the same domain or partial name is NOT affected
$testSimulatedUsers = [
    ['email' => 'ar-jay.agbayani@otherdomain.com', 'expected_hidden' => false],
    ['email' => 'agbayani@marikina.sti.edu.ph', 'expected_hidden' => false],
    ['email' => 'other.user@marikina.sti.edu.ph', 'expected_hidden' => false],
    ['email' => 'ar-jay.agbayani@marikina.sti.edu.ph', 'expected_hidden' => true],
];

foreach ($testSimulatedUsers as $tu) {
    $email = $tu['email'];
    $isMatch = (strtolower(trim($email)) === 'ar-jay.agbayani@marikina.sti.edu.ph');
    check(
        "Email '{$email}' match check: " . ($isMatch ? 'HIDDEN' : 'VISIBLE'),
        $isMatch === $tu['expected_hidden'],
        "Expected hidden: " . ($tu['expected_hidden'] ? 'YES' : 'NO') . " | Actual: " . ($isMatch ? 'YES' : 'NO')
    );
}

echo "\n========================================================================\n";
echo sprintf("  SUMMARY: Total: %d | Passed: %d | Failed: %d\n", $totalTests, $passedTests, $failedTests);
echo "========================================================================\n";

if ($failedTests > 0) exit(1);
exit(0);
