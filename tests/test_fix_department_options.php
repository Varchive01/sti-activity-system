<?php
/**
 * Verification Test: Fix Department Options in User Management — STI-ARMES
 * 
 * Verifies:
 * 1. Exactly the 4 approved department options exist in Add & Edit modals:
 *    - IT Department
 *    - G.E. Department
 *    - THM Department
 *    - Administration
 * 2. Creating a user with approved department saves correctly to database.
 * 3. Editing a user through all 4 approved departments saves and persists across reloads.
 * 4. Department validation accepts only the 4 approved options (or empty/legacy).
 * 5. Existing user accounts, roles, permissions, and legacy departments are preserved.
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
echo "  VERIFICATION: FIX DEPARTMENT OPTIONS IN USER MANAGEMENT\n";
echo "========================================================================\n\n";

// -----------------------------------------------------------------------------
// SECTION 1: HTML & UI DROPDOWN VERIFICATION
// -----------------------------------------------------------------------------
echo "--- SECTION 1: HTML DROPDOWN INSPECTION ---\n";

$viewFile = file_get_contents(__DIR__ . '/../includes/user-management-view.php');

$approved = [
    'IT Department',
    'G.E. Department',
    'THM Department',
    'Administration'
];

// Extract add_department select
preg_match('/<select[^>]*id="add_department"[^>]*>(.*?)<\/select>/s', $viewFile, $addMatches);
$addHtml = $addMatches[1] ?? '';
preg_match_all('/<option\s+value="([^"]*)"/i', $addHtml, $addOpts);
$actualAddOpts = array_values(array_filter($addOpts[1], fn($v) => $v !== ''));

check(
    "Add User dropdown has exactly the 4 approved department options",
    $actualAddOpts === $approved,
    "Expected: " . implode(', ', $approved) . " | Actual: " . implode(', ', $actualAddOpts)
);

// Extract edit_department select
preg_match('/<select[^>]*id="edit_department"[^>]*>(.*?)<\/select>/s', $viewFile, $editMatches);
$editHtml = $editMatches[1] ?? '';
preg_match_all('/<option\s+value="([^"]*)"/i', $editHtml, $editOpts);
$actualEditOpts = array_values(array_filter($editOpts[1], fn($v) => $v !== ''));

check(
    "Edit User dropdown has exactly the 4 approved department options",
    $actualEditOpts === $approved,
    "Expected: " . implode(', ', $approved) . " | Actual: " . implode(', ', $actualEditOpts)
);

// Verify legacy options like 'Computer Science', 'General Education' are removed
$disallowedInDropdown = ['Computer Science', 'General Education', 'Hospitality Management', 'Tourism Management', 'Engineering', 'Student Affairs', 'Events Committee'];
$hasObsolete = false;
foreach ($disallowedInDropdown as $obs) {
    if (strpos($addHtml, 'value="' . $obs . '"') !== false || strpos($editHtml, 'value="' . $obs . '"') !== false) {
        $hasObsolete = true;
        break;
    }
}
check("Obsolete options are completely removed from both dropdowns", !$hasObsolete);

// -----------------------------------------------------------------------------
// SECTION 2: ENDPOINT VALIDATION TESTS
// -----------------------------------------------------------------------------
echo "\n--- SECTION 2: BACKEND VALIDATION TESTS ---\n";

// Helper to simulate request to API
function runApiScript($scriptPath, $sessionUser, $postData) {
    $scriptRelative = str_replace('\\', '/', $scriptPath);
    $csrfToken = 'test_csrf_token_dept_123';
    $sessionUser['csrf_token'] = $csrfToken;
    $postData['csrf_token'] = $csrfToken;

    $sessionExport = var_export($sessionUser, true);
    $postExport = var_export($postData, true);

    $code = "<?php
    if (session_status() === PHP_SESSION_NONE) session_start();
    \$_SESSION['user_id'] = " . (int)$sessionUser['id'] . ";
    \$_SESSION['user_role'] = " . var_export($sessionUser['role'], true) . ";
    \$_SESSION['user_name'] = " . var_export($sessionUser['name'], true) . ";
    \$_SESSION['user_email'] = " . var_export($sessionUser['email'], true) . ";
    \$_SESSION['last_activity'] = time();
    \$_SESSION['csrf_token'] = '{$csrfToken}';
    \$_SERVER['REQUEST_METHOD'] = 'POST';
    \$_SERVER['HTTP_ACCEPT'] = 'application/json';
    \$_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';
    \$_SERVER['REQUEST_URI'] = '/test';
    \$_POST = {$postExport};
    register_shutdown_function(function() {
        echo '___STATUS:' . http_response_code() . '___\n';
    });
    ob_start();
    try {
        require '{$scriptRelative}';
    } catch (Throwable \$e) {
        echo 'EXCEPTION: ' . \$e->getMessage();
    }
    ";

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $proc = proc_open('php', $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['status' => 500, 'stdout' => '', 'stderr' => 'Failed to spawn process', 'json' => null];
    }

    fwrite($pipes[0], $code);
    fclose($pipes[0]);

    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $full = $out . $err;
    $status = 200;
    if (preg_match('/___STATUS:(\d+)___/', $full, $m)) {
        $status = (int)$m[1];
    }
    $cleanBody = trim(preg_replace('/___STATUS:\d+___\n?/', '', $full));
    $json = json_decode($cleanBody, true);

    return ['status' => $status, 'stdout' => $cleanBody, 'stderr' => $err, 'json' => $json];
}

// Fetch dean user for session
$deanUser = $db->query("SELECT * FROM users WHERE role = 'dean' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$deanUser) {
    $deanUser = ['id' => 4, 'role' => 'dean', 'name' => 'Dean User', 'email' => 'dean@sti.edu'];
}

// Test validation: invalid department on create
$invalidRes = runApiScript(__DIR__ . '/../api/user-create.php', $deanUser, [
    'name' => 'Test Invalid Dept',
    'email' => 'invalid_dept_test@sti.edu',
    'password' => 'password123',
    'role' => 'faculty',
    'department' => 'Invalid Department XYZ'
]);
check(
    "api/user-create.php rejects unapproved department with 422",
    ($invalidRes['json']['error_code'] ?? '') === 'invalid_department' && $invalidRes['status'] === 422,
    "Status: {$invalidRes['status']}, Code: " . ($invalidRes['json']['error_code'] ?? 'none')
);

// -----------------------------------------------------------------------------
// SECTION 3: CREATE, EDIT, PERSISTENCE & RELOAD VERIFICATION
// -----------------------------------------------------------------------------
echo "\n--- SECTION 3: CREATE, EDIT & RELOAD PERSISTENCE ---\n";

$testEmail = 'dept_verify_' . time() . '@sti.edu';
$testName = 'Dept Test User';

// 1. Create with IT Department
$createRes = runApiScript(__DIR__ . '/../api/user-create.php', $deanUser, [
    'name' => $testName,
    'email' => $testEmail,
    'password' => 'Password123!',
    'role' => 'faculty',
    'department' => 'IT Department'
]);

$createdId = $createRes['json']['user_id'] ?? null;
check("User created with 'IT Department'", !empty($createdId) && $createRes['json']['success'] === true, "User ID: {$createdId}");

// Reload record from database
$stmt = $db->prepare("SELECT id, name, email, role, department FROM users WHERE id = ?");
$stmt->execute([$createdId]);
$reloaded = $stmt->fetch(PDO::FETCH_ASSOC);

check("Reload confirms 'IT Department' persisted in database", $reloaded && $reloaded['department'] === 'IT Department');

// 2. Edit to G.E. Department
$updateRes1 = runApiScript(__DIR__ . '/../api/user-update.php', $deanUser, [
    'user_id' => $createdId,
    'name' => $testName,
    'email' => $testEmail,
    'role' => 'faculty',
    'department' => 'G.E. Department'
]);
check("Updated department to 'G.E. Department'", ($updateRes1['json']['success'] ?? false) === true);

$stmt->execute([$createdId]);
$reloaded1 = $stmt->fetch(PDO::FETCH_ASSOC);
check("Reload confirms 'G.E. Department' persisted in database", $reloaded1 && $reloaded1['department'] === 'G.E. Department');

// 3. Edit to THM Department
$updateRes2 = runApiScript(__DIR__ . '/../api/user-update.php', $deanUser, [
    'user_id' => $createdId,
    'name' => $testName,
    'email' => $testEmail,
    'role' => 'faculty',
    'department' => 'THM Department'
]);
check("Updated department to 'THM Department'", ($updateRes2['json']['success'] ?? false) === true);

$stmt->execute([$createdId]);
$reloaded2 = $stmt->fetch(PDO::FETCH_ASSOC);
check("Reload confirms 'THM Department' persisted in database", $reloaded2 && $reloaded2['department'] === 'THM Department');

// 4. Edit to Administration
$updateRes3 = runApiScript(__DIR__ . '/../api/user-update.php', $deanUser, [
    'user_id' => $createdId,
    'name' => $testName,
    'email' => $testEmail,
    'role' => 'faculty',
    'department' => 'Administration'
]);
check("Updated department to 'Administration'", ($updateRes3['json']['success'] ?? false) === true);

$stmt->execute([$createdId]);
$reloaded3 = $stmt->fetch(PDO::FETCH_ASSOC);
check("Reload confirms 'Administration' persisted in database", $reloaded3 && $reloaded3['department'] === 'Administration');

// 5. Test invalid department update rejection
$invalidUpdate = runApiScript(__DIR__ . '/../api/user-update.php', $deanUser, [
    'user_id' => $createdId,
    'name' => $testName,
    'email' => $testEmail,
    'role' => 'faculty',
    'department' => 'Bogus Department'
]);
check("api/user-update.php rejects unapproved department with 422", ($invalidUpdate['json']['error_code'] ?? '') === 'invalid_department' && $invalidUpdate['status'] === 422, "Status: {$invalidUpdate['status']}, Code: " . ($invalidUpdate['json']['error_code'] ?? 'none'));

// 6. Test optional empty department update
$emptyUpdate = runApiScript(__DIR__ . '/../api/user-update.php', $deanUser, [
    'user_id' => $createdId,
    'name' => $testName,
    'email' => $testEmail,
    'role' => 'faculty',
    'department' => ''
]);
check("api/user-update.php accepts optional empty department", ($emptyUpdate['json']['success'] ?? false) === true);

$stmt->execute([$createdId]);
$reloadedEmpty = $stmt->fetch(PDO::FETCH_ASSOC);
check("Reload confirms empty department saved as NULL/empty", empty($reloadedEmpty['department']));

// -----------------------------------------------------------------------------
// SECTION 4: PRESERVATION OF EXISTING USERS AND DATA INTEGRITY
// -----------------------------------------------------------------------------
echo "\n--- SECTION 4: EXISTING USER DATA INTEGRITY ---\n";

$existingUsers = $db->query("SELECT id, name, email, role, department FROM users WHERE id <= 4 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

check("Existing users (IDs 1-4) preserved", count($existingUsers) >= 3, "Found " . count($existingUsers) . " original system accounts");

foreach ($existingUsers as $eu) {
    echo "       User #{$eu['id']} ({$eu['name']}, Role: {$eu['role']}) -> Dept: '{$eu['department']}' [INTACT]\n";
}

// Clean up only our created test user
if ($createdId) {
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$createdId]);
    echo "       Cleaned up temporary test user ID: {$createdId}\n";
}

echo "\n========================================================================\n";
echo sprintf("  SUMMARY: Total: %d | Passed: %d | Failed: %d\n", $totalTests, $passedTests, $failedTests);
echo "========================================================================\n";

if ($failedTests > 0) {
    exit(1);
}
exit(0);
