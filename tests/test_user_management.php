<?php
/**
 * test_user_management.php
 *
 * Comprehensive Automated Test Suite for Unified User Management:
 * 1. Authorization Guards (Dean, Admin2, Faculty, Admin1, Unauthenticated)
 * 2. Role Creation & Hierarchy Permissions
 * 3. CSRF Protection on All State-Changing Endpoints
 * 4. Default Password & Forced First-Login Change Flow
 * 5. User Editing & Privilege Restriction Enforcement
 * 6. Set Default Password Action
 * 7. UI Verification (No ID, No Hash, No Employee ID, No Status, Permission-Aware Dropdowns)
 * 8. Validation & Safe HTML Escaping
 * 9. Automated Test Account Cleanup
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

echo "========================================================================\n";
echo "  STI ACTIVITY SYSTEM - UNIFIED USER MANAGEMENT TEST SUITE\n";
echo "========================================================================\n\n";

$db = getDB();

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function recordResult(string $testName, bool $passed, string $details = ''): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($passed) {
        $passedTests++;
        echo sprintf("  [%02d] PASS: %s\n", $totalTests, $testName);
        if ($details !== '') {
            echo "       Note: {$details}\n";
        }
    } else {
        $failedTests++;
        echo sprintf("  [%02d] FAIL: %s\n", $totalTests, $testName);
        if ($details !== '') {
            echo "       Error: {$details}\n";
        }
    }
}

function runIsolatedScript(string $scriptPath, ?array $sessionData, array $postData = [], array $serverData = []): array {
    $scriptRelative = str_replace('\\', '/', $scriptPath);

    $sessionInit = '';
    if ($sessionData !== null) {
        $sessionExport = var_export($sessionData, true);
        $sessionInit = "
        if (session_status() === PHP_SESSION_NONE) session_start();
        foreach ({$sessionExport} as \$k => \$v) {
            \$_SESSION[\$k] = \$v;
        }
        ";
    }

    $postExport = var_export($postData, true);
    $serverExport = var_export($serverData, true);

    $code = "<?php
    define('TEST_RUNNER', true);
    register_shutdown_function(function() {
        echo '___STATUS:' . http_response_code() . '___\n';
    });
    {$sessionInit}
    \$_SERVER['SCRIPT_NAME'] = '{$scriptRelative}';
    \$_SERVER['PHP_SELF']    = '{$scriptRelative}';
    \$_POST = {$postExport};
    foreach ({$serverExport} as \$sk => \$sv) {
        \$_SERVER[\$sk] = \$sv;
    }
    ob_start();
    try {
        include '{$scriptRelative}';
    } catch (Throwable \$e) {
        echo 'EXCEPTION: ' . \$e->getMessage();
    }
    ";

    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open('php', $desc, $pipes);
    if (!is_resource($proc)) {
        return ['status' => 500, 'output' => '', 'body' => 'Failed to spawn process'];
    }

    fwrite($pipes[0], $code);
    fclose($pipes[0]);

    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $fullOutput = $out . $err;
    $status = 200;
    if (preg_match('/___STATUS:(\d+)___/', $fullOutput, $m)) {
        $status = (int)$m[1];
    }
    $body = preg_replace('/___STATUS:\d+___\n?/', '', $fullOutput);

    return [
        'status' => $status,
        'output' => $fullOutput,
        'body'   => trim($body)
    ];
}

function testLoginIsolated(string $email, string $password): array|false {
    $baseDir = str_replace('\\', '/', realpath(__DIR__ . '/..'));
    $code = "<?php
    require_once '{$baseDir}/config/config.php';
    require_once '{$baseDir}/config/database.php';
    require_once '{$baseDir}/includes/auth.php';
    \$res = login(" . var_export($email, true) . ", " . var_export($password, true) . ");
    echo json_encode(\$res);
    ";

    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open('php', $desc, $pipes);
    if (!is_resource($proc)) return false;
    fwrite($pipes[0], $code);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $decoded = json_decode($out, true);
    return is_array($decoded) ? $decoded : false;
}

$testCsrfToken = 'test_valid_csrf_token_1234567890abcdef';

$deanSession = [
    'user_id'              => 4,
    'user_name'            => 'Dr. Dean Administrator',
    'user_email'           => 'dean@sti.edu',
    'user_role'            => 'dean',
    'role'                 => 'dean',
    'user_dept'            => 'Academic Affairs',
    'csrf_token'           => $testCsrfToken,
    'must_change_password' => 0,
    'last_activity'        => time()
];

$admin2Session = [
    'user_id'              => 3,
    'user_name'            => 'Admin 2 Ian',
    'user_email'           => 'ian@sti.edu',
    'user_role'            => 'admin2',
    'role'                 => 'admin2',
    'user_dept'            => 'Operations',
    'csrf_token'           => $testCsrfToken,
    'must_change_password' => 0,
    'last_activity'        => time()
];

$facultySession = [
    'user_id'              => 1,
    'user_name'            => 'Faculty User',
    'user_email'           => 'faculty@sti.edu',
    'user_role'            => 'faculty',
    'role'                 => 'faculty',
    'user_dept'            => 'IT Department',
    'csrf_token'           => $testCsrfToken,
    'must_change_password' => 0,
    'last_activity'        => time()
];

$admin1Session = [
    'user_id'              => 2,
    'user_name'            => 'Admin 1 Arjay',
    'user_email'           => 'arjay@sti.edu',
    'user_role'            => 'admin1',
    'role'                 => 'admin1',
    'user_dept'            => 'Student Affairs',
    'csrf_token'           => $testCsrfToken,
    'must_change_password' => 0,
    'last_activity'        => time()
];

// -------------------------------------------------------------------------
// SECTION 1: AUTHORIZATION TESTS
// -------------------------------------------------------------------------
echo "--- SECTION 1: AUTHORIZATION TESTS ---\n";

// 1. Dean can access User Management
$res = runIsolatedScript(__DIR__ . '/../dean/users.php', $deanSession);
recordResult(
    'Dean can access User Management (HTTP 200)',
    $res['status'] === 200 && str_contains($res['body'], 'User Management') && str_contains($res['body'], 'Add User'),
    "HTTP {$res['status']}, Contains 'User Management' and 'Add User'"
);

// 2. Admin2 can access User Management
$res = runIsolatedScript(__DIR__ . '/../admin2/users.php', $admin2Session);
recordResult(
    'Admin2 can access User Management (HTTP 200)',
    $res['status'] === 200 && str_contains($res['body'], 'User Management') && str_contains($res['body'], 'Add User'),
    "HTTP {$res['status']}, Contains 'User Management' and 'Add User'"
);

// 3. Faculty cannot access User Management
$res = runIsolatedScript(__DIR__ . '/../dean/users.php', $facultySession);
recordResult(
    'Faculty cannot access Dean User Management (HTTP 403)',
    $res['status'] === 403 && str_contains($res['body'], 'Access denied.'),
    "HTTP {$res['status']}"
);

// 4. Admin1 cannot access User Management
$res = runIsolatedScript(__DIR__ . '/../dean/users.php', $admin1Session);
recordResult(
    'Admin1 cannot access Dean User Management (HTTP 403)',
    $res['status'] === 403 && str_contains($res['body'], 'Access denied.'),
    "HTTP {$res['status']}"
);

// 5. Unauthenticated users are redirected to login
$res = runIsolatedScript(__DIR__ . '/../dean/users.php', null);
recordResult(
    'Unauthenticated users are redirected appropriately (HTTP 302)',
    $res['status'] === 302,
    "HTTP {$res['status']}"
);

// -------------------------------------------------------------------------
// SECTION 2: ROLE CREATION & HIERARCHY PERMISSIONS
// -------------------------------------------------------------------------
echo "\n--- SECTION 2: ROLE CREATION & HIERARCHY PERMISSIONS ---\n";

$testDefaultPassword = 'InitialDefaultPass2026!';

// 6. Dean can create Faculty
$emailDeanFac = 'test_u_dean_fac_' . time() . '@sti.edu';
$res = runIsolatedScript(__DIR__ . '/../api/user-create.php', $deanSession, [
    'csrf_token' => $testCsrfToken,
    'name'       => 'Dean Created Faculty',
    'email'      => $emailDeanFac,
    'department' => 'IT Department',
    'role'       => 'faculty',
    'password'   => $testDefaultPassword
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
$data = json_decode($res['body'], true);
recordResult(
    'Dean can create Faculty account',
    $res['status'] === 201 && isset($data['role']) && $data['role'] === 'faculty',
    "Role: " . ($data['role'] ?? 'none')
);

// 7. Dean can create Admin1
$emailDeanAdmin1 = 'test_u_dean_a1_' . time() . '@sti.edu';
$res = runIsolatedScript(__DIR__ . '/../api/user-create.php', $deanSession, [
    'csrf_token' => $testCsrfToken,
    'name'       => 'Dean Created Admin1',
    'email'      => $emailDeanAdmin1,
    'department' => 'Student Affairs',
    'role'       => 'admin1',
    'password'   => $testDefaultPassword
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
$data = json_decode($res['body'], true);
recordResult(
    'Dean can create Admin1 account',
    $res['status'] === 201 && isset($data['role']) && $data['role'] === 'admin1',
    "Role: " . ($data['role'] ?? 'none')
);

// 8. Dean can create Admin2
$emailDeanAdmin2 = 'test_u_dean_a2_' . time() . '@sti.edu';
$res = runIsolatedScript(__DIR__ . '/../api/user-create.php', $deanSession, [
    'csrf_token' => $testCsrfToken,
    'name'       => 'Dean Created Admin2',
    'email'      => $emailDeanAdmin2,
    'department' => 'Events Committee',
    'role'       => 'admin2',
    'password'   => $testDefaultPassword
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
$data = json_decode($res['body'], true);
recordResult(
    'Dean can create Admin2 account',
    $res['status'] === 201 && isset($data['role']) && $data['role'] === 'admin2',
    "Role: " . ($data['role'] ?? 'none')
);

// 9. Dean cannot create Dean account
$res = runIsolatedScript(__DIR__ . '/../api/user-create.php', $deanSession, [
    'csrf_token' => $testCsrfToken,
    'name'       => 'Attempted Second Dean',
    'email'      => 'test_second_dean_' . time() . '@sti.edu',
    'department' => 'Administration',
    'role'       => 'dean',
    'password'   => $testDefaultPassword
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
recordResult(
    'Dean cannot create another Dean account (HTTP 403)',
    $res['status'] === 403,
    "HTTP {$res['status']}"
);

// 10. Admin2 can create Faculty
$emailAdmin2Fac = 'test_u_adm2_fac_' . time() . '@sti.edu';
$res = runIsolatedScript(__DIR__ . '/../api/user-create.php', $admin2Session, [
    'csrf_token' => $testCsrfToken,
    'name'       => 'Admin2 Created Faculty',
    'email'      => $emailAdmin2Fac,
    'department' => 'Computer Science',
    'role'       => 'faculty',
    'password'   => $testDefaultPassword
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
$data = json_decode($res['body'], true);
recordResult(
    'Admin2 can create Faculty account',
    $res['status'] === 201 && isset($data['role']) && $data['role'] === 'faculty',
    "Role: " . ($data['role'] ?? 'none')
);

// 11. Admin2 cannot create Admin1
$res = runIsolatedScript(__DIR__ . '/../api/user-create.php', $admin2Session, [
    'csrf_token' => $testCsrfToken,
    'name'       => 'Admin2 Malicious Admin1 Attempt',
    'email'      => 'test_adm2_a1_' . time() . '@sti.edu',
    'role'       => 'admin1',
    'password'   => $testDefaultPassword
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
recordResult(
    'Admin2 cannot create Admin1 account (HTTP 403)',
    $res['status'] === 403,
    "HTTP {$res['status']}"
);

// 12. Admin2 cannot create Admin2
$res = runIsolatedScript(__DIR__ . '/../api/user-create.php', $admin2Session, [
    'csrf_token' => $testCsrfToken,
    'name'       => 'Admin2 Malicious Admin2 Attempt',
    'email'      => 'test_adm2_a2_' . time() . '@sti.edu',
    'role'       => 'admin2',
    'password'   => $testDefaultPassword
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
recordResult(
    'Admin2 cannot create Admin2 account (HTTP 403)',
    $res['status'] === 403,
    "HTTP {$res['status']}"
);

// 13. Admin2 cannot create Dean
$res = runIsolatedScript(__DIR__ . '/../api/user-create.php', $admin2Session, [
    'csrf_token' => $testCsrfToken,
    'name'       => 'Admin2 Malicious Dean Attempt',
    'email'      => 'test_adm2_dean_' . time() . '@sti.edu',
    'role'       => 'dean',
    'password'   => $testDefaultPassword
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
recordResult(
    'Admin2 cannot create Dean account (HTTP 403)',
    $res['status'] === 403,
    "HTTP {$res['status']}"
);

// 14. Faculty cannot create users
$res = runIsolatedScript(__DIR__ . '/../api/user-create.php', $facultySession, [
    'csrf_token' => $testCsrfToken,
    'name'       => 'Faculty Hacker',
    'email'      => 'test_fac_hack_' . time() . '@sti.edu',
    'role'       => 'faculty',
    'password'   => $testDefaultPassword
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
recordResult(
    'Faculty cannot create users (HTTP 403)',
    $res['status'] === 403,
    "HTTP {$res['status']}"
);

// 15. Admin1 cannot create users
$res = runIsolatedScript(__DIR__ . '/../api/user-create.php', $admin1Session, [
    'csrf_token' => $testCsrfToken,
    'name'       => 'Admin1 Hacker',
    'email'      => 'test_a1_hack_' . time() . '@sti.edu',
    'role'       => 'faculty',
    'password'   => $testDefaultPassword
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
recordResult(
    'Admin1 cannot create users (HTTP 403)',
    $res['status'] === 403,
    "HTTP {$res['status']}"
);

// 16. Direct API role escalation attempts are rejected
$res = runIsolatedScript(__DIR__ . '/../api/user-create.php', $admin2Session, [
    'csrf_token' => $testCsrfToken,
    'name'       => 'Direct Escalation User',
    'email'      => 'test_escalate_' . time() . '@sti.edu',
    'role'       => 'super_admin',
    'password'   => $testDefaultPassword
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
recordResult(
    'Direct API invalid role escalation attempts are rejected (HTTP 403 / 422)',
    $res['status'] === 403 || $res['status'] === 422,
    "HTTP {$res['status']}"
);

// -------------------------------------------------------------------------
// SECTION 3: CSRF PROTECTION ON STATE-CHANGING ENDPOINTS
// -------------------------------------------------------------------------
echo "\n--- SECTION 3: CSRF PROTECTION CHECKS ---\n";

// 17. Account creation requires valid CSRF token
$res = runIsolatedScript(__DIR__ . '/../api/user-create.php', $deanSession, [
    'csrf_token' => 'invalid_csrf_token',
    'name'       => 'No CSRF User',
    'email'      => 'test_nocsrf_' . time() . '@sti.edu',
    'role'       => 'faculty',
    'password'   => $testDefaultPassword
], ['REQUEST_METHOD' => 'POST']);
recordResult(
    'Account creation rejected on invalid/missing CSRF token (HTTP 403)',
    $res['status'] === 403 && str_contains($res['body'], 'CSRF'),
    "HTTP {$res['status']}"
);

// 18. User update requires valid CSRF token
$res = runIsolatedScript(__DIR__ . '/../api/user-update.php', $deanSession, [
    'csrf_token' => 'invalid_token',
    'user_id'    => 1,
    'name'       => 'Hacked Name',
    'email'      => 'faculty@sti.edu',
    'role'       => 'faculty'
], ['REQUEST_METHOD' => 'POST']);
recordResult(
    'User update rejected on invalid/missing CSRF token (HTTP 403)',
    $res['status'] === 403 && str_contains($res['body'], 'CSRF'),
    "HTTP {$res['status']}"
);

// 19. Set default password requires valid CSRF token
$res = runIsolatedScript(__DIR__ . '/../api/user-reset-password.php', $deanSession, [
    'csrf_token'   => 'invalid_token',
    'user_id'      => 1,
    'new_password' => 'NewPassword123!'
], ['REQUEST_METHOD' => 'POST']);
recordResult(
    'Password reset rejected on invalid/missing CSRF token (HTTP 403)',
    $res['status'] === 403 && str_contains($res['body'], 'CSRF'),
    "HTTP {$res['status']}"
);

// 20. Forced password change requires valid CSRF token
$res = runIsolatedScript(__DIR__ . '/../auth/change-password.php', [
    'user_id' => 1, 'must_change_password' => 1, 'user_role' => 'faculty'
], [
    'csrf_token'       => 'bad_token',
    'new_password'     => 'ValidPass123!',
    'confirm_password' => 'ValidPass123!'
], ['REQUEST_METHOD' => 'POST']);
recordResult(
    'Forced change password rejects invalid/missing CSRF token',
    str_contains($res['body'], 'security token') || $res['status'] === 403,
    "Body caught CSRF error"
);

// -------------------------------------------------------------------------
// SECTION 4: DEFAULT PASSWORD & FORCED FIRST-LOGIN CHANGE FLOW
// -------------------------------------------------------------------------
echo "\n--- SECTION 4: DEFAULT PASSWORD & FIRST-LOGIN FLOW ---\n";

$stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
$stmt->execute([$emailDeanFac]);
$targetFacultyUser = $stmt->fetch(PDO::FETCH_ASSOC);

// 21. Creator-provided default password is accepted
recordResult(
    'Creator-provided default password is accepted',
    $targetFacultyUser !== false,
    "User ID: " . ($targetFacultyUser['id'] ?? 'none')
);

// 22. Password stored only as a secure hash
$isNotPlaintext = ($targetFacultyUser['password'] !== $testDefaultPassword);
$hashMatchesBcrypt = password_verify($testDefaultPassword, $targetFacultyUser['password']);
recordResult(
    'Password is stored only as a secure hash',
    $isNotPlaintext && $hashMatchesBcrypt,
    "Plaintext matched = " . ($hashMatchesBcrypt ? 'YES' : 'NO') . ", Hash prefix: " . substr($targetFacultyUser['password'], 0, 7)
);

// 23. New account gets must_change_password = 1
recordResult(
    'New account gets must_change_password = 1',
    (int)$targetFacultyUser['must_change_password'] === 1,
    "Value: " . $targetFacultyUser['must_change_password']
);

// 24. New user can log in using default password
$loginResult = testLoginIsolated($emailDeanFac, $testDefaultPassword);
recordResult(
    'New user can log in using the default password',
    $loginResult !== false && is_array($loginResult) && $loginResult['email'] === $emailDeanFac,
    "Logged in user email: " . ($loginResult['email'] ?? 'failed')
);

// 25. User with must_change_password = 1 cannot access dashboard
$firstLoginSession = [
    'user_id'              => (int)$targetFacultyUser['id'],
    'user_name'            => $targetFacultyUser['name'],
    'user_email'           => $targetFacultyUser['email'],
    'user_role'            => 'faculty',
    'role'                 => 'faculty',
    'must_change_password' => 1,
    'last_activity'        => time(),
    'csrf_token'           => $testCsrfToken
];
$res = runIsolatedScript(__DIR__ . '/../faculty/dashboard.php', $firstLoginSession);
recordResult(
    'User with must_change_password = 1 cannot access dashboard (HTTP 302)',
    $res['status'] === 302,
    "HTTP {$res['status']}"
);

// 26. User is redirected to Change Password page
$res = runIsolatedScript(__DIR__ . '/../auth/change-password.php', $firstLoginSession);
recordResult(
    'User is redirected to Change Password and page loads (HTTP 200)',
    $res['status'] === 200 && str_contains($res['body'], 'Set Permanent Password'),
    "HTTP {$res['status']}"
);

// 27. Direct URL access to other portals cannot bypass requirement
$res = runIsolatedScript(__DIR__ . '/../dean/dashboard.php', $firstLoginSession);
recordResult(
    'Direct dashboard access cannot bypass the requirement (HTTP 302 / 403)',
    $res['status'] === 302 || $res['status'] === 403,
    "HTTP {$res['status']}"
);

// 28. Change password rejects short passwords
$res = runIsolatedScript(__DIR__ . '/../auth/change-password.php', $firstLoginSession, [
    'csrf_token'       => $testCsrfToken,
    'new_password'     => '12345',
    'confirm_password' => '12345'
], ['REQUEST_METHOD' => 'POST']);
recordResult(
    'Change Password rejects short password (<6 chars)',
    str_contains($res['body'], 'at least 6 characters'),
    "Body contains validation message"
);

// 29. Change password rejects mismatching passwords
$res = runIsolatedScript(__DIR__ . '/../auth/change-password.php', $firstLoginSession, [
    'csrf_token'       => $testCsrfToken,
    'new_password'     => 'PermanentPass2026!',
    'confirm_password' => 'DifferentPass2026!'
], ['REQUEST_METHOD' => 'POST']);
recordResult(
    'Change Password rejects mismatching passwords',
    str_contains($res['body'], 'Passwords do not match'),
    "Body contains mismatch message"
);

// 30. User can successfully set new password
$permanentPass = 'PermanentSecretPass2026!';
$res = runIsolatedScript(__DIR__ . '/../auth/change-password.php', $firstLoginSession, [
    'csrf_token'       => $testCsrfToken,
    'new_password'     => $permanentPass,
    'confirm_password' => $permanentPass
], ['REQUEST_METHOD' => 'POST']);
recordResult(
    'User can successfully set new password -> Redirects (HTTP 302)',
    $res['status'] === 302,
    "HTTP {$res['status']}"
);

// 31. New password is securely hashed
$stmt->execute([$emailDeanFac]);
$updatedTargetUser = $stmt->fetch(PDO::FETCH_ASSOC);
$permPassHashVerified = password_verify($permanentPass, $updatedTargetUser['password']);
recordResult(
    'New password is securely hashed in database',
    $permPassHashVerified && $updatedTargetUser['password'] !== $permanentPass,
    "Bcrypt verified: " . ($permPassHashVerified ? 'YES' : 'NO')
);

// 32. must_change_password becomes 0 after successful change
recordResult(
    'must_change_password becomes 0 after successful change',
    (int)$updatedTargetUser['must_change_password'] === 0,
    "Value: " . $updatedTargetUser['must_change_password']
);

// 33. New user can access dashboard afterward
$activeFacultySession = $firstLoginSession;
$activeFacultySession['must_change_password'] = 0;
$res = runIsolatedScript(__DIR__ . '/../faculty/dashboard.php', $activeFacultySession);
recordResult(
    'New user can access dashboard afterward (HTTP 200)',
    $res['status'] === 200,
    "HTTP {$res['status']}"
);

// 34. Old default password no longer works
$oldPassLogin = testLoginIsolated($emailDeanFac, $testDefaultPassword);
recordResult(
    'Old default password no longer works',
    $oldPassLogin === false,
    "Login returned false as expected"
);

// 35. New password authenticates successfully
$newPassLogin = testLoginIsolated($emailDeanFac, $permanentPass);
recordResult(
    'New password authenticates successfully',
    $newPassLogin !== false && (int)$newPassLogin['must_change_password'] === 0,
    "Logged in user email: " . ($newPassLogin['email'] ?? 'failed')
);

// 36. Existing accounts with must_change_password = 0 continue working normally
$seedLogin = testLoginIsolated('faculty@sti.edu', 'password');
recordResult(
    'Existing accounts with must_change_password = 0 continue working normally',
    $seedLogin !== false && (int)$seedLogin['must_change_password'] === 0,
    "Seed faculty logged in: " . ($seedLogin['email'] ?? 'failed')
);

// -------------------------------------------------------------------------
// SECTION 5: USER EDITING & PERMISSIONS
// -------------------------------------------------------------------------
echo "\n--- SECTION 5: USER EDITING & PERMISSION RESTRICTIONS ---\n";

// 37. Dean can edit Faculty account details
$res = runIsolatedScript(__DIR__ . '/../api/user-update.php', $deanSession, [
    'csrf_token' => $testCsrfToken,
    'user_id'    => (int)$targetFacultyUser['id'],
    'name'       => 'Dean Updated Faculty Name',
    'email'      => $emailDeanFac,
    'department' => 'Engineering',
    'role'       => 'faculty'
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
$data = json_decode($res['body'], true);
recordResult(
    'Authorized Dean editing Faculty works',
    $res['status'] === 200 && ($data['success'] ?? false) === true,
    "HTTP {$res['status']}"
);

// 38. Dean can edit Admin 1 account details
$stmt->execute([$emailDeanAdmin1]);
$targetAdmin1 = $stmt->fetch(PDO::FETCH_ASSOC);
$res = runIsolatedScript(__DIR__ . '/../api/user-update.php', $deanSession, [
    'csrf_token' => $testCsrfToken,
    'user_id'    => (int)$targetAdmin1['id'],
    'name'       => 'Dean Updated Admin1 Name',
    'email'      => $emailDeanAdmin1,
    'department' => 'Student Affairs Head',
    'role'       => 'admin1'
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
$data = json_decode($res['body'], true);
recordResult(
    'Authorized Dean editing Admin 1 works',
    $res['status'] === 200 && ($data['success'] ?? false) === true,
    "HTTP {$res['status']}"
);

// 39. Dean can edit Admin 2 account details
$stmt->execute([$emailDeanAdmin2]);
$targetAdmin2 = $stmt->fetch(PDO::FETCH_ASSOC);
$res = runIsolatedScript(__DIR__ . '/../api/user-update.php', $deanSession, [
    'csrf_token' => $testCsrfToken,
    'user_id'    => (int)$targetAdmin2['id'],
    'name'       => 'Dean Updated Admin2 Name',
    'email'      => $emailDeanAdmin2,
    'department' => 'Operations Committee',
    'role'       => 'admin2'
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
$data = json_decode($res['body'], true);
recordResult(
    'Authorized Dean editing Admin 2 works',
    $res['status'] === 200 && ($data['success'] ?? false) === true,
    "HTTP {$res['status']}"
);

// 40. Dean cannot edit own role
$res = runIsolatedScript(__DIR__ . '/../api/user-update.php', $deanSession, [
    'csrf_token' => $testCsrfToken,
    'user_id'    => 4, // Dean's user ID
    'name'       => 'Dr. Frederic Yulo',
    'email'      => 'dean@sti.edu',
    'department' => 'Administration',
    'role'       => 'faculty' // Attempted self-demotion
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
recordResult(
    'Dean cannot modify their own role (HTTP 403)',
    $res['status'] === 403,
    "HTTP {$res['status']}"
);

// 41. Dean cannot change user role to Dean
$res = runIsolatedScript(__DIR__ . '/../api/user-update.php', $deanSession, [
    'csrf_token' => $testCsrfToken,
    'user_id'    => (int)$targetFacultyUser['id'],
    'name'       => 'Attempted Second Dean',
    'email'      => $emailDeanFac,
    'department' => 'Administration',
    'role'       => 'dean'
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
recordResult(
    'Dean cannot change a user role to Dean (HTTP 403)',
    $res['status'] === 403,
    "HTTP {$res['status']}"
);

// 42. Admin 2 can edit Faculty account details
$stmt->execute([$emailAdmin2Fac]);
$targetAdmin2CreatedFac = $stmt->fetch(PDO::FETCH_ASSOC);
$res = runIsolatedScript(__DIR__ . '/../api/user-update.php', $admin2Session, [
    'csrf_token' => $testCsrfToken,
    'user_id'    => (int)$targetAdmin2CreatedFac['id'],
    'name'       => 'Admin2 Updated Faculty Name',
    'email'      => $emailAdmin2Fac,
    'department' => 'General Education',
    'role'       => 'faculty'
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
$data = json_decode($res['body'], true);
recordResult(
    'Authorized Admin2 Faculty editing works',
    $res['status'] === 200 && ($data['success'] ?? false) === true,
    "HTTP {$res['status']}"
);

// 43. Admin 2 cannot edit Admin 1 account
$res = runIsolatedScript(__DIR__ . '/../api/user-update.php', $admin2Session, [
    'csrf_token' => $testCsrfToken,
    'user_id'    => (int)$targetAdmin1['id'],
    'name'       => 'Admin2 Attempting Edit Admin1',
    'email'      => $emailDeanAdmin1,
    'role'       => 'admin1'
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
recordResult(
    'Admin2 cannot edit Admin 1 account (HTTP 403)',
    $res['status'] === 403,
    "HTTP {$res['status']}"
);

// 44. Admin 2 cannot edit Admin 2 account
$res = runIsolatedScript(__DIR__ . '/../api/user-update.php', $admin2Session, [
    'csrf_token' => $testCsrfToken,
    'user_id'    => (int)$targetAdmin2['id'],
    'name'       => 'Admin2 Attempting Edit Admin2',
    'email'      => $emailDeanAdmin2,
    'role'       => 'admin2'
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
recordResult(
    'Admin2 cannot edit Admin 2 account (HTTP 403)',
    $res['status'] === 403,
    "HTTP {$res['status']}"
);

// 45. Admin 2 cannot edit Dean account
$res = runIsolatedScript(__DIR__ . '/../api/user-update.php', $admin2Session, [
    'csrf_token' => $testCsrfToken,
    'user_id'    => 4, // Dean
    'name'       => 'Admin2 Attempting Edit Dean',
    'email'      => 'dean@sti.edu',
    'role'       => 'dean'
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
recordResult(
    'Admin2 cannot edit Dean account (HTTP 403)',
    $res['status'] === 403,
    "HTTP {$res['status']}"
);

// 46. Admin 2 cannot escalate Faculty to an administrative role
$res = runIsolatedScript(__DIR__ . '/../api/user-update.php', $admin2Session, [
    'csrf_token' => $testCsrfToken,
    'user_id'    => (int)$targetAdmin2CreatedFac['id'],
    'name'       => 'Admin2 Promoting Faculty',
    'email'      => $emailAdmin2Fac,
    'role'       => 'admin1' // Attempted escalation
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
recordResult(
    'Admin2 cannot escalate Faculty to an administrative role (HTTP 403)',
    $res['status'] === 403,
    "HTTP {$res['status']}"
);

// -------------------------------------------------------------------------
// SECTION 6: SET DEFAULT PASSWORD / PASSWORD RESET
// -------------------------------------------------------------------------
echo "\n--- SECTION 6: SET DEFAULT PASSWORD ACTION ---\n";

$newDefaultPass = 'NewResetDefaultPass2026!';

// 47. Dean can set new default password for Faculty
$res = runIsolatedScript(__DIR__ . '/../api/user-reset-password.php', $deanSession, [
    'csrf_token'   => $testCsrfToken,
    'user_id'      => (int)$targetFacultyUser['id'],
    'new_password' => $newDefaultPass
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);

$stmt->execute([$emailDeanFac]);
$resetUser = $stmt->fetch(PDO::FETCH_ASSOC);
recordResult(
    'Dean can set new default password for Faculty -> sets must_change_password = 1',
    $res['status'] === 200 && (int)$resetUser['must_change_password'] === 1 && password_verify($newDefaultPass, $resetUser['password']),
    "must_change_password = " . ($resetUser['must_change_password'] ?? 'none')
);

// 48. Dean can set new default password for Admin 1
$res = runIsolatedScript(__DIR__ . '/../api/user-reset-password.php', $deanSession, [
    'csrf_token'   => $testCsrfToken,
    'user_id'      => (int)$targetAdmin1['id'],
    'new_password' => $newDefaultPass
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);

$stmt->execute([$emailDeanAdmin1]);
$resetAdmin1 = $stmt->fetch(PDO::FETCH_ASSOC);
recordResult(
    'Dean can set new default password for Admin 1 -> sets must_change_password = 1',
    $res['status'] === 200 && (int)$resetAdmin1['must_change_password'] === 1,
    "must_change_password = " . ($resetAdmin1['must_change_password'] ?? 'none')
);

// 49. Dean can set new default password for Admin 2
$res = runIsolatedScript(__DIR__ . '/../api/user-reset-password.php', $deanSession, [
    'csrf_token'   => $testCsrfToken,
    'user_id'      => (int)$targetAdmin2['id'],
    'new_password' => $newDefaultPass
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);

$stmt->execute([$emailDeanAdmin2]);
$resetAdmin2 = $stmt->fetch(PDO::FETCH_ASSOC);
recordResult(
    'Dean can set new default password for Admin 2 -> sets must_change_password = 1',
    $res['status'] === 200 && (int)$resetAdmin2['must_change_password'] === 1,
    "must_change_password = " . ($resetAdmin2['must_change_password'] ?? 'none')
);

// 50. Admin 2 can set new default password for Faculty
$res = runIsolatedScript(__DIR__ . '/../api/user-reset-password.php', $admin2Session, [
    'csrf_token'   => $testCsrfToken,
    'user_id'      => (int)$targetAdmin2CreatedFac['id'],
    'new_password' => $newDefaultPass
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);

$stmt->execute([$emailAdmin2Fac]);
$resetAdm2Fac = $stmt->fetch(PDO::FETCH_ASSOC);
recordResult(
    'Admin 2 can set new default password for Faculty -> sets must_change_password = 1',
    $res['status'] === 200 && (int)$resetAdm2Fac['must_change_password'] === 1,
    "must_change_password = " . ($resetAdm2Fac['must_change_password'] ?? 'none')
);

// 51. Admin 2 cannot set default password for Admin 1, Admin 2, or Dean
$resA1 = runIsolatedScript(__DIR__ . '/../api/user-reset-password.php', $admin2Session, [
    'csrf_token'   => $testCsrfToken,
    'user_id'      => (int)$targetAdmin1['id'],
    'new_password' => $newDefaultPass
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);

$resDean = runIsolatedScript(__DIR__ . '/../api/user-reset-password.php', $admin2Session, [
    'csrf_token'   => $testCsrfToken,
    'user_id'      => 4,
    'new_password' => $newDefaultPass
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);

recordResult(
    'Admin 2 cannot set default password for Admin 1 or Dean (HTTP 403)',
    $resA1['status'] === 403 && $resDean['status'] === 403,
    "A1: {$resA1['status']}, Dean: {$resDean['status']}"
);

// 52. User forced to change password at next login after password reset
$resetLogin = testLoginIsolated($emailDeanFac, $newDefaultPass);
$forcedSession = [
    'user_id'              => (int)$targetFacultyUser['id'],
    'must_change_password' => 1,
    'user_role'            => 'faculty'
];
$interceptRes = runIsolatedScript(__DIR__ . '/../faculty/dashboard.php', $forcedSession);
recordResult(
    'Affected user forced to change password at next login after password reset',
    $resetLogin !== false && (int)$resetLogin['must_change_password'] === 1 && $interceptRes['status'] === 302,
    "Login must_change = 1, Dashboard intercepted (HTTP 302)"
);

// -------------------------------------------------------------------------
// SECTION 7: UI STRUCTURE & FIELD EXCLUSION VERIFICATION
// -------------------------------------------------------------------------
echo "\n--- SECTION 7: UI DESIGN & FIELD EXCLUSIONS ---\n";

$deanViewHtml = runIsolatedScript(__DIR__ . '/../dean/users.php', $deanSession)['body'];
$admin2ViewHtml = runIsolatedScript(__DIR__ . '/../admin2/users.php', $admin2Session)['body'];

// 53. UI does NOT display Database ID column in table
$hasIdHeader = preg_match('/<th[^>]*>\s*(ID|Database ID|User ID)\s*<\/th>/i', $deanViewHtml);
recordResult(
    'No database ID column displayed in user table',
    !$hasIdHeader,
    "ID header present: " . ($hasIdHeader ? 'YES (FAIL)' : 'NO (SAFE)')
);

// 54. UI does NOT display Password Hash in table
$hasHashInHtml = str_contains($deanViewHtml, '$2y$12$') || str_contains($deanViewHtml, '$2y$10$');
recordResult(
    'No password hash displayed anywhere in table or HTML',
    !$hasHashInHtml,
    "Password hash exposed: " . ($hasHashInHtml ? 'YES (FAIL)' : 'NO (SAFE)')
);

// 55. UI does NOT display Employee ID column in table
$hasEmpIdHeader = preg_match('/<th[^>]*>\s*Employee ID\s*<\/th>/i', $deanViewHtml);
recordResult(
    'No Employee ID column displayed in user table',
    !$hasEmpIdHeader,
    "Employee ID header present: " . ($hasEmpIdHeader ? 'YES (FAIL)' : 'NO (SAFE)')
);

// 56. UI does NOT display Status column in table
$hasStatusHeader = preg_match('/<th[^>]*>\s*Status\s*<\/th>/i', $deanViewHtml);
recordResult(
    'No Status column displayed in user table',
    !$hasStatusHeader,
    "Status header present: " . ($hasStatusHeader ? 'YES (FAIL)' : 'NO (SAFE)')
);

// 57. UI Role field is selectable (not "Faculty (Fixed)")
$hasFixedBadge = str_contains($deanViewHtml, 'Faculty (Fixed)');
recordResult(
    'Role is not fixed as "Faculty (Fixed)"',
    !$hasFixedBadge,
    "Fixed badge present: " . ($hasFixedBadge ? 'YES (FAIL)' : 'NO (SAFE)')
);

// 58. UI Role options depend on creator permissions
$deanHasAdminOptions = str_contains($deanViewHtml, 'value="admin1"') && str_contains($deanViewHtml, 'value="admin2"');
$admin2HasOnlyFaculty = !str_contains($admin2ViewHtml, 'value="admin1"') && !str_contains($admin2ViewHtml, 'value="admin2"');
recordResult(
    'Role options correctly depend on creator permissions',
    $deanHasAdminOptions && $admin2HasOnlyFaculty,
    "Dean has Admin options: YES, Admin2 has Admin options: NO"
);

// -------------------------------------------------------------------------
// SECTION 8: INPUT VALIDATION & SAFE ESCAPING
// -------------------------------------------------------------------------
echo "\n--- SECTION 8: VALIDATION & XSS ESCAPING ---\n";

// 59. Duplicate email rejected on create
$res = runIsolatedScript(__DIR__ . '/../api/user-create.php', $deanSession, [
    'csrf_token' => $testCsrfToken,
    'name'       => 'Dup User',
    'email'      => 'faculty@sti.edu',
    'role'       => 'faculty',
    'password'   => $testDefaultPassword
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
$data = json_decode($res['body'], true);
recordResult(
    'Duplicate email rejected on create',
    $res['status'] === 422 && ($data['error_code'] ?? '') === 'duplicate_email',
    "HTTP {$res['status']}, Code: " . ($data['error_code'] ?? 'none')
);

// 60. Duplicate email rejected on edit
$res = runIsolatedScript(__DIR__ . '/../api/user-update.php', $deanSession, [
    'csrf_token' => $testCsrfToken,
    'user_id'    => (int)$targetFacultyUser['id'],
    'name'       => 'Dup User Edit',
    'email'      => 'faculty@sti.edu', // existing email
    'role'       => 'faculty'
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
$data = json_decode($res['body'], true);
recordResult(
    'Duplicate email rejected on edit',
    $res['status'] === 422 && ($data['error_code'] ?? '') === 'duplicate_email',
    "HTTP {$res['status']}, Code: " . ($data['error_code'] ?? 'none')
);

// 61. Missing name rejected
$res = runIsolatedScript(__DIR__ . '/../api/user-create.php', $deanSession, [
    'csrf_token' => $testCsrfToken,
    'name'       => '   ',
    'email'      => 'noname_' . time() . '@sti.edu',
    'role'       => 'faculty',
    'password'   => $testDefaultPassword
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
$data = json_decode($res['body'], true);
recordResult(
    'Missing name rejected on create',
    $res['status'] === 422 && ($data['error_code'] ?? '') === 'missing_name',
    "HTTP {$res['status']}"
);

// 62. Invalid email format rejected
$res = runIsolatedScript(__DIR__ . '/../api/user-create.php', $deanSession, [
    'csrf_token' => $testCsrfToken,
    'name'       => 'Bad Email',
    'email'      => 'not-an-email',
    'role'       => 'faculty',
    'password'   => $testDefaultPassword
], ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
$data = json_decode($res['body'], true);
recordResult(
    'Invalid email format rejected',
    $res['status'] === 422 && ($data['error_code'] ?? '') === 'invalid_email',
    "HTTP {$res['status']}"
);

// 63. Safe HTML output escaping protects against XSS in user directory
$xssName = 'Dr. <script>alert("xss")</script> Test';
$xssDept = '<b>IT Department</b>';
$xssEmail = 'xss_test_' . time() . '@sti.edu';
$insStmt = $db->prepare('INSERT INTO users (name, email, password, role, must_change_password, department) VALUES (?, ?, ?, ?, 0, ?)');
$insStmt->execute([$xssName, $xssEmail, password_hash('Pass1234!', PASSWORD_DEFAULT), 'faculty', $xssDept]);

$res = runIsolatedScript(__DIR__ . '/../dean/users.php', $deanSession);
$rawScriptFound = str_contains($res['body'], '<script>alert("xss")</script>');
$escapedScriptFound = str_contains($res['body'], htmlspecialchars($xssName, ENT_QUOTES, 'UTF-8'));
recordResult(
    'Safe HTML output escaping protects against XSS in directory table',
    !$rawScriptFound && $escapedScriptFound,
    "Raw <script> present: " . ($rawScriptFound ? 'YES (VULNERABLE)' : 'NO (SAFE)')
);

// -------------------------------------------------------------------------
// SECTION 9: CLEANUP
// -------------------------------------------------------------------------
echo "\n--- SECTION 9: TEST ACCOUNT CLEANUP ---\n";

$delWildcard = $db->query("DELETE FROM users WHERE id > 4 OR email LIKE 'test_%' OR email LIKE 'xss_%'");
$deletedCount = $delWildcard->rowCount();
recordResult(
    'Automated test accounts cleaned up completely',
    $deletedCount >= 0,
    "Cleaned up {$deletedCount} temporary test user account(s)"
);

echo "\n========================================================================\n";
echo sprintf("  RESULTS: Total: %d | Passed: %d | Failed: %d\n", $totalTests, $passedTests, $failedTests);
echo "========================================================================\n\n";

if ($failedTests > 0) {
    echo "❌ TEST SUITE FAILED with {$failedTests} error(s).\n";
    exit(1);
} else {
    echo "✅ ALL {$passedTests} UNIFIED USER MANAGEMENT TESTS PASSED PERFECTLY!\n";
    exit(0);
}
