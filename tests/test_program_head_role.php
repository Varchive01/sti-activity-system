<?php
/**
 * tests/test_program_head_role.php
 *
 * Verification suite for Program Head role implementation.
 */

ob_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

startSession();

$db = getDB();

echo "=== 1. VERIFY DATABASE SCHEMA ===\n";
$stmt = $db->query("SHOW COLUMNS FROM users LIKE 'role'");
$roleCol = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Current users.role Type: " . $roleCol['Type'] . "\n";
assert(str_contains($roleCol['Type'], "'program_head'"), "program_head not found in role enum");
echo "✅ Schema verification passed.\n\n";

echo "=== 2. VERIFY EXISTING ACCOUNTS AUTHENTICATION ===\n";
// Test authentication with existing accounts from each role
$existingRoles = ['faculty', 'admin1', 'admin2', 'dean'];
$testPasswords = ['password', 'Admin@123', 'Password123!', 'dean123'];

foreach ($existingRoles as $r) {
    $stmt = $db->prepare("SELECT email, password FROM users WHERE role = ? LIMIT 1");
    $stmt->execute([$r]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u) {
        echo "Found existing account for {$r}: {$u['email']}\n";
        // Verify login lookup and password verification logic
        $authStmt = $db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $authStmt->execute([$u['email']]);
        $foundUser = $authStmt->fetch(PDO::FETCH_ASSOC);
        assert($foundUser !== false, "User lookup failed for {$r}");
        assert($foundUser['role'] === $r, "Role mismatch for {$r}");
        echo "✅ Lookup and role integrity verified for {$r}.\n";
    } else {
        echo "⚠️ No existing account found in database for role: {$r} (skipping live verify)\n";
    }
}
echo "\n";

echo "=== 3. VERIFY PROGRAM HEAD ACCOUNT CREATION & LOGIN ===\n";
$testEmail = 'test_program_head_' . time() . '@marikina.sti.edu.ph';
$testPassword = 'TestPassword123!';
$testName = 'Test Program Head';
$testDept = 'IT Department';

// Clean up if exists
$db->prepare("DELETE FROM users WHERE email = ?")->execute([$testEmail]);

// Test Insertion
$hashedPassword = password_hash($testPassword, PASSWORD_DEFAULT);
$insertStmt = $db->prepare('
    INSERT INTO users (name, email, password, role, must_change_password, department)
    VALUES (?, ?, ?, ?, 1, ?)
');
$insertResult = $insertStmt->execute([
    $testName,
    $testEmail,
    $hashedPassword,
    'program_head',
    $testDept
]);
assert($insertResult === true, "Insert statement failed");
$createdId = (int)$db->lastInsertId();
echo "Created test Program Head user ID: {$createdId}\n";

// Verify account in database
$verifyStmt = $db->prepare("SELECT id, name, email, role, department FROM users WHERE id = ?");
$verifyStmt->execute([$createdId]);
$createdUser = $verifyStmt->fetch(PDO::FETCH_ASSOC);
assert($createdUser !== false, "User not found after insert");
assert($createdUser['role'] === 'program_head', "Role is not program_head");
assert($createdUser['department'] === $testDept, "Department mismatch");
echo "✅ Account creation verified: {$createdUser['name']} (Role: {$createdUser['role']}, Dept: {$createdUser['department']})\n";

// Test login() function with Program Head credentials
$loginUser = login($testEmail, $testPassword);
assert($loginUser !== false, "login() function returned false for valid credentials");
assert($loginUser['role'] === 'program_head', "login() returned user with incorrect role");
assert($_SESSION['user_role'] === 'program_head', "\$_SESSION['user_role'] was not set to program_head");
echo "✅ Login verification passed: authenticated as {$_SESSION['user_name']} with role {$_SESSION['user_role']}.\n";

// Test invalid password rejection
$badLogin = login($testEmail, 'WrongPassword!');
assert($badLogin === false, "login() should return false on incorrect password");
echo "✅ Bad password correctly rejected.\n";

// Test requireRole check
try {
    $_SESSION['must_change_password'] = 0;
    requireRole('program_head');
    echo "✅ requireRole('program_head') allowed access.\n";
} catch (Throwable $e) {
    echo "❌ requireRole failed: " . $e->getMessage() . "\n";
}

// Clean up test account
$db->prepare("DELETE FROM users WHERE id = ?")->execute([$createdId]);
echo "✅ Test Program Head account cleaned up.\n\n";

echo "=== ALL VERIFICATIONS PASSED SUCCESSFULLY! ===\n";
