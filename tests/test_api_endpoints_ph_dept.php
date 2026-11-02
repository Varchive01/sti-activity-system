<?php
/**
 * tests/test_api_endpoints_ph_dept.php
 *
 * Runs isolated sub-processes testing api/user-create.php and api/user-update.php.
 */

function runCase(array $post, string $script): array {
    $descriptor = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"],
    ];
    $process = proc_open("php scratch/runner.php", $descriptor, $pipes);
    if (!is_resource($process)) {
        return ['error' => 'proc_open failed'];
    }
    fwrite($pipes[0], json_encode(['post' => $post, 'script' => $script]));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return json_decode(trim($output), true) ?: ['raw' => $output];
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
$db = getDB();

echo "1. Testing user-create with missing department:\n";
$res1 = runCase([
    'name' => 'PH Missing Dept',
    'email' => 'ph_test_nodept@test.com',
    'role' => 'program_head',
    'department' => '',
    'password' => 'Pass123!'
], 'api/user-create.php');
echo "Result: " . json_encode($res1) . "\n";
assert(($res1['error_code'] ?? '') === 'missing_program_head_department');

echo "\n2. Testing user-create with Administration department:\n";
$res2 = runCase([
    'name' => 'PH Admin Dept',
    'email' => 'ph_test_admin@test.com',
    'role' => 'program_head',
    'department' => 'Administration',
    'password' => 'Pass123!'
], 'api/user-create.php');
echo "Result: " . json_encode($res2) . "\n";
assert(($res2['error_code'] ?? '') === 'missing_program_head_department');

echo "\n3. Testing user-create with 'IT':\n";
$testEmail = 'ph_test_it_' . time() . '@test.com';
$res3 = runCase([
    'name' => 'PH Valid IT',
    'email' => $testEmail,
    'role' => 'program_head',
    'department' => 'IT',
    'password' => 'Pass123!'
], 'api/user-create.php');
echo "Result: " . json_encode($res3) . "\n";
assert(($res3['success'] ?? false) === true);
$userId = (int)$res3['user_id'];

// Check normalized department in DB
$dept = $db->query("SELECT department FROM users WHERE id = {$userId}")->fetchColumn();
echo "Stored Department in DB: {$dept}\n";
assert($dept === 'IT Department');

echo "\n4. Testing user-update to 'THM':\n";
$res4 = runCase([
    'user_id' => $userId,
    'name' => 'PH Valid THM',
    'email' => $testEmail,
    'role' => 'program_head',
    'department' => 'THM'
], 'api/user-update.php');
echo "Result: " . json_encode($res4) . "\n";
assert(($res4['success'] ?? false) === true);
$dept = $db->query("SELECT department FROM users WHERE id = {$userId}")->fetchColumn();
echo "Stored Department in DB: {$dept}\n";
assert($dept === 'THM Department');

echo "\n5. Testing user-update to 'GE':\n";
$res5 = runCase([
    'user_id' => $userId,
    'name' => 'PH Valid GE',
    'email' => $testEmail,
    'role' => 'program_head',
    'department' => 'GE'
], 'api/user-update.php');
echo "Result: " . json_encode($res5) . "\n";
assert(($res5['success'] ?? false) === true);
$dept = $db->query("SELECT department FROM users WHERE id = {$userId}")->fetchColumn();
echo "Stored Department in DB: {$dept}\n";
assert($dept === 'G.E. Department');

echo "\n6. Testing user-update with invalid empty department:\n";
$res6 = runCase([
    'user_id' => $userId,
    'name' => 'PH Empty Dept',
    'email' => $testEmail,
    'role' => 'program_head',
    'department' => ''
], 'api/user-update.php');
echo "Result: " . json_encode($res6) . "\n";
assert(($res6['error_code'] ?? '') === 'missing_program_head_department');

// Cleanup
$db->query("DELETE FROM users WHERE id = {$userId}");
echo "\n✅ Test account {$userId} cleaned up successfully.\n";
echo "=== ALL API SUITE TESTS PASSED! ===\n";
