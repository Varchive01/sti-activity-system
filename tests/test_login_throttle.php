<?php
/**
 * Test Suite: Minimal Login Brute-Force Protection
 *
 * Requirements:
 * 1. Normal valid login still works.
 * 2. Invalid login increments the throttle.
 * 3. Repeated failures (>= 5) are temporarily blocked with generic message & HTTP 429.
 * 4. Successful authentication clears the failed-attempt state.
 * 5. First-login password-change flow still works and redirects to change-password.php.
 * 6. Session regeneration remains intact.
 * 7. Error messages are generic and do not reveal account existence.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();

$passedTests = 0;
$failedTests = 0;

function assertCheck(string $name, bool $condition, string $details = ""): void {
    global $passedTests, $failedTests;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$name}\n";
    } else {
        $failedTests++;
        echo "  [FAIL] {$name}\n";
        if (!empty($details)) {
            echo "         Details: {$details}\n";
        }
    }
}

echo "============================================================\n";
echo "  Login Brute-Force Protection Test Suite\n";
echo "============================================================\n\n";

$throttleDir = (is_dir('C:/xampp/tmp') && is_writable('C:/xampp/tmp')) ? 'C:/xampp/tmp' : sys_get_temp_dir();

function getThrottleFilePath(string $email, string $ip = '127.0.0.1'): string {
    global $throttleDir;
    $key = hash('sha256', $ip . '|' . strtolower(trim($email)));
    return rtrim($throttleDir, '/\\') . DIRECTORY_SEPARATOR . 'sti_throttle_' . $key . '.json';
}

function cleanupThrottleFile(string $email, string $ip = '127.0.0.1'): void {
    $file = getThrottleFilePath($email, $ip);
    if (file_exists($file)) {
        @unlink($file);
    }
}

// Create temporary test users
$testEmailNormal = 'test_throttle_user_' . bin2hex(random_bytes(4)) . '@sti.edu';
$testEmailMustChange = 'test_mustchange_user_' . bin2hex(random_bytes(4)) . '@sti.edu';
$testPassword = 'Password123!';
$hashedPassword = password_hash($testPassword, PASSWORD_DEFAULT);

$stmt = $db->prepare("INSERT INTO users (name, email, password, role, department, must_change_password) VALUES (?, ?, ?, 'faculty', 'IT', ?)");
$stmt->execute(['Throttle Normal User', $testEmailNormal, $hashedPassword, 0]);
$normalUserId = (int)$db->lastInsertId();

$stmt->execute(['Throttle MustChange User', $testEmailMustChange, $hashedPassword, 1]);
$mustChangeUserId = (int)$db->lastInsertId();

echo "Created test users:\n";
echo " - Normal: {$testEmailNormal} (ID: {$normalUserId})\n";
echo " - MustChange: {$testEmailMustChange} (ID: {$mustChangeUserId})\n\n";

// Use 127.0.0.1 so REMOTE_ADDR is consistently 127.0.0.1
$loginUrl = 'http://127.0.0.1/sti-activity-system/auth/login.php';

function postLogin(string $url, string $email, string $password, ?string $cookieJar = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'email' => $email,
        'password' => $password
    ]));

    if ($cookieJar !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }

    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($res, 0, $hSize);
    $body = substr($res, $hSize);

    return ['code' => $code, 'headers' => $headers, 'body' => $body];
}

try {
    // -----------------------------------------------------------------
    // 1. Valid login works normally and redirects to dashboard
    // -----------------------------------------------------------------
    echo "--- 1. Valid Login Verification ---\n";
    cleanupThrottleFile($testEmailNormal);
    $cookieFile1 = $throttleDir . DIRECTORY_SEPARATOR . 'cookie_test1_' . bin2hex(random_bytes(4)) . '.txt';

    $res = postLogin($loginUrl, $testEmailNormal, $testPassword, $cookieFile1);
    assertCheck(
        "Valid login responds with HTTP 302 redirect",
        $res['code'] === 302,
        "HTTP code: {$res['code']}"
    );
    assertCheck(
        "Valid login redirects to faculty dashboard",
        str_contains($res['headers'], 'Location: ') && str_contains($res['headers'], 'faculty/dashboard.php'),
        "Headers: " . $res['headers']
    );
    assertCheck(
        "Session regeneration creates session cookie on successful login",
        str_contains($res['headers'], 'Set-Cookie: PHPSESSID='),
        "Headers: " . $res['headers']
    );
    if (file_exists($cookieFile1)) @unlink($cookieFile1);

    // -----------------------------------------------------------------
    // 2. First-login password-change flow works
    // -----------------------------------------------------------------
    echo "\n--- 2. First-Login Password-Change Flow ---\n";
    cleanupThrottleFile($testEmailMustChange);
    $cookieFile2 = $throttleDir . DIRECTORY_SEPARATOR . 'cookie_test2_' . bin2hex(random_bytes(4)) . '.txt';

    $res = postLogin($loginUrl, $testEmailMustChange, $testPassword, $cookieFile2);
    assertCheck(
        "Must-change-password user redirected to change-password.php",
        $res['code'] === 302 && str_contains($res['headers'], 'auth/change-password.php'),
        "HTTP code: {$res['code']}, Headers: {$res['headers']}"
    );
    if (file_exists($cookieFile2)) @unlink($cookieFile2);

    // -----------------------------------------------------------------
    // 3. Failed attempts increment throttle and 5th failure blocks
    // -----------------------------------------------------------------
    echo "\n--- 3. Failed Login Throttling ---\n";
    $victimEmail = 'bruteforce_target_' . bin2hex(random_bytes(4)) . '@sti.edu';
    $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Victim', ?, 'fake', 'faculty', 'IT')")->execute([$victimEmail]);
    $victimId = (int)$db->lastInsertId();
    cleanupThrottleFile($victimEmail);

    $attemptCodes = [];
    $attemptBodies = [];

    for ($i = 1; $i <= 5; $i++) {
        $r = postLogin($loginUrl, $victimEmail, 'WrongPass_' . $i);
        $attemptCodes[$i] = $r['code'];
        $attemptBodies[$i] = $r['body'];
    }

    assertCheck(
        "Attempts 1 to 4 return HTTP 200 with generic 'Invalid email or password.'",
        $attemptCodes[1] === 200 && $attemptCodes[4] === 200 &&
        str_contains($attemptBodies[1], 'Invalid email or password.') &&
        !str_contains($attemptBodies[1], 'Too many failed login attempts'),
        "Code 1: {$attemptCodes[1]}, Code 4: {$attemptCodes[4]}"
    );

    assertCheck(
        "5th failed attempt triggers HTTP 429 and generic 'Too many failed login attempts'",
        $attemptCodes[5] === 429 && str_contains($attemptBodies[5], 'Too many failed login attempts'),
        "Code 5: {$attemptCodes[5]}"
    );

    // Attempt 6 (while locked)
    $r6 = postLogin($loginUrl, $victimEmail, 'EvenCorrectOrWrongPass');
    assertCheck(
        "Subsequent attempt during lockout is blocked immediately with HTTP 429",
        $r6['code'] === 429 && str_contains($r6['body'], 'Too many failed login attempts'),
        "Code 6: {$r6['code']}"
    );

    // Verify throttle file exists and contains count >= 5
    $throttleFilePath = getThrottleFilePath($victimEmail);
    assertCheck(
        "Throttle file is created with count and lockout timestamp",
        file_exists($throttleFilePath) &&
        ($data = json_decode(file_get_contents($throttleFilePath), true)) &&
        ($data['count'] ?? 0) >= 5 &&
        ($data['locked_until'] ?? 0) > time(),
        "Throttle file data: " . (file_exists($throttleFilePath) ? file_get_contents($throttleFilePath) : 'missing')
    );

    // -----------------------------------------------------------------
    // 4. Non-existent account behavior is identical (no enumeration)
    // -----------------------------------------------------------------
    echo "\n--- 4. Non-Existent Account Security & Error Genericity ---\n";
    $nonExistentEmail = 'nonexistent_' . bin2hex(random_bytes(4)) . '@example.com';
    cleanupThrottleFile($nonExistentEmail);

    $rNone = postLogin($loginUrl, $nonExistentEmail, 'AnyPassword');
    assertCheck(
        "Non-existent account yields exact same generic 'Invalid email or password.' message",
        $rNone['code'] === 200 && str_contains($rNone['body'], 'Invalid email or password.'),
        "Body preview: " . substr(strip_tags($rNone['body']), 0, 100)
    );

    // -----------------------------------------------------------------
    // 5. Successful login resets failed attempts
    // -----------------------------------------------------------------
    echo "\n--- 5. Reset on Successful Authentication ---\n";
    $resetTestEmail = 'reset_test_' . bin2hex(random_bytes(4)) . '@sti.edu';
    $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Reset User', ?, ?, 'faculty', 'IT')")
       ->execute([$resetTestEmail, $hashedPassword]);
    $resetUserId = (int)$db->lastInsertId();
    cleanupThrottleFile($resetTestEmail);

    // Fail 2 times
    postLogin($loginUrl, $resetTestEmail, 'WrongPassword1');
    postLogin($loginUrl, $resetTestEmail, 'WrongPassword2');

    $resetThrottleFile = getThrottleFilePath($resetTestEmail);
    assertCheck(
        "Throttle file has count = 2 after 2 failed attempts",
        file_exists($resetThrottleFile) &&
        ($d = json_decode(file_get_contents($resetThrottleFile), true)) &&
        ($d['count'] ?? 0) === 2,
        "Throttle data: " . (file_exists($resetThrottleFile) ? file_get_contents($resetThrottleFile) : 'missing')
    );

    // Now log in with correct password
    $cookieReset = $throttleDir . DIRECTORY_SEPARATOR . 'cookie_reset_' . bin2hex(random_bytes(4)) . '.txt';
    $resSuccess = postLogin($loginUrl, $resetTestEmail, $testPassword, $cookieReset);
    assertCheck(
        "Successful login succeeds with HTTP 302 redirect",
        $resSuccess['code'] === 302,
        "Code: {$resSuccess['code']}"
    );
    assertCheck(
        "Throttle file is unlinked / removed upon successful login",
        !file_exists($resetThrottleFile),
        "Throttle file still exists!"
    );
    if (file_exists($cookieReset)) @unlink($cookieReset);

    // -----------------------------------------------------------------
    // 6. Lockout expiry allows legitimate retries (no permanent lockout)
    // -----------------------------------------------------------------
    echo "\n--- 6. Temporary Lockout Expiration ---\n";
    // Simulate expired lockout by writing expired locked_until timestamp
    $expiredData = [
        'count' => 5,
        'locked_until' => time() - 5, // expired 5 seconds ago
        'last_attempt' => time() - 10
    ];
    file_put_contents($resetThrottleFile, json_encode($expiredData));

    // Attempting login now with correct credentials should succeed
    $resRecovered = postLogin($loginUrl, $resetTestEmail, $testPassword);
    assertCheck(
        "Expired lockout allows login attempt and succeeds on correct credentials",
        $resRecovered['code'] === 302,
        "Code: {$resRecovered['code']}"
    );

    // -----------------------------------------------------------------
    // 7. Logout behavior remains intact
    // -----------------------------------------------------------------
    echo "\n--- 7. Logout Behavior Verification ---\n";
    $cookieLogout = $throttleDir . DIRECTORY_SEPARATOR . 'cookie_logout_' . bin2hex(random_bytes(4)) . '.txt';
    postLogin($loginUrl, $testEmailNormal, $testPassword, $cookieLogout);
    
    // Call logout.php
    $ch = curl_init('http://127.0.0.1/sti-activity-system/auth/logout.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieLogout);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieLogout);
    $resLogout = curl_exec($ch);
    $codeLogout = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    assertCheck(
        "Logout endpoint responds with redirect to login",
        $codeLogout === 302 && str_contains($resLogout, 'auth/login.php'),
        "Logout code: {$codeLogout}"
    );
    if (file_exists($cookieLogout)) @unlink($cookieLogout);

    // Clean up
    cleanupThrottleFile($victimEmail);
    cleanupThrottleFile($nonExistentEmail);
    cleanupThrottleFile($resetTestEmail);
    cleanupThrottleFile($testEmailNormal);
    cleanupThrottleFile($testEmailMustChange);

    $db->prepare("DELETE FROM users WHERE id IN (?, ?, ?, ?)")
       ->execute([$normalUserId, $mustChangeUserId, $victimId, $resetUserId]);

} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failedTests++;
}

echo "\n============================================================\n";
echo "  SUMMARY: {$passedTests} passed, {$failedTests} failed\n";
echo "============================================================\n";

if ($failedTests > 0) {
    exit(1);
}
exit(0);
