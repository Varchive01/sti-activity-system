<?php
// tests/test_all_roles_microsoft_flow.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Set session save path to match Apache's XAMPP session storage
$savePath = is_dir('C:/xampp/tmp') ? 'C:/xampp/tmp' : session_save_path();
if ($savePath) {
    session_save_path($savePath);
}

echo "================================================================\n";
echo " STI-ARMES ALL 5 ROLES MICROSOFT AUTHENTICATION & ROUTING TEST \n";
echo "================================================================\n\n";

$db = getDB();

// Test accounts across all 5 roles
$testRoles = [
    'faculty'      => 'faculty@sti.edu',
    'admin1'       => 'admin1@sti.edu',
    'admin2'       => 'admin2@sti.edu',
    'dean'         => 'dean@sti.edu',
    'program_head' => 'dave@sti.edu'
];

$expectedDashboards = [
    'faculty'      => 'faculty/dashboard.php',
    'admin1'       => 'admin1/dashboard.php',
    'admin2'       => 'admin2/dashboard.php',
    'dean'         => 'dean/dashboard.php',
    'program_head' => 'program_head/dashboard.php'
];

$allPassed = true;

foreach ($testRoles as $role => $email) {
    echo "------------------------------------------------------------\n";
    echo "Testing Role: [{$role}] (Email: {$email})\n";
    echo "------------------------------------------------------------\n";

    // 1. Fetch user from database
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $stmtFallback = $db->prepare("SELECT * FROM users WHERE role = ? LIMIT 1");
        $stmtFallback->execute([$role]);
        $user = $stmtFallback->fetch(PDO::FETCH_ASSOC);
    }

    if (!$user) {
        echo "❌ User record not found for role {$role}\n";
        $allPassed = false;
        continue;
    }

    echo " ✓ User record located: {$user['name']} (ID: {$user['id']}, Role: {$user['role']})\n";

    // 2. Create session file directly in session save directory so Apache reads it
    $sessionId = bin2hex(random_bytes(16));
    $sessData = [
        'user_id'       => $user['id'],
        'user_email'    => $user['email'],
        'user_name'     => $user['name'],
        'user_role'     => $user['role'],
        'auth_method'   => 'microsoft',
        'auth_provider' => 'microsoft',
        'last_activity' => time()
    ];

    // Format PHP serialized session data
    $serializedSession = '';
    foreach ($sessData as $k => $v) {
        $serializedSession .= $k . '|' . serialize($v);
    }

    $sessFile = rtrim($savePath, '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $sessionId;
    file_put_contents($sessFile, $serializedSession);

    // 3. Test Redirection from auth/login.php when authenticated via Microsoft SSO
    $loginUrl = 'http://localhost/sti-activity-system/auth/login.php';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $loginUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_COOKIE, "PHPSESSID={$sessionId}");
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);

    $expectedTarget = $expectedDashboards[$role];
    $isRedirectOk = ($httpCode === 302 && str_contains($redirectUrl, $expectedTarget));

    echo sprintf(" - Login Redirect: HTTP %d -> %s [%s]\n",
        $httpCode,
        $redirectUrl,
        $isRedirectOk ? 'PASS' : 'FAIL'
    );

    if (!$isRedirectOk) {
        $allPassed = false;
    }

    // 4. Test Dashboard Direct Access with Microsoft Session
    $dashUrl = "http://localhost/sti-activity-system/{$expectedTarget}";
    $chDash = curl_init();
    curl_setopt($chDash, CURLOPT_URL, $dashUrl);
    curl_setopt($chDash, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chDash, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($chDash, CURLOPT_COOKIE, "PHPSESSID={$sessionId}");
    $dashRes = curl_exec($chDash);
    $dashCode = curl_getinfo($chDash, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($chDash, CURLINFO_EFFECTIVE_URL);

    $isDashOk = ($dashCode === 200 && str_contains($finalUrl, $expectedTarget) && !str_contains((string)$dashRes, 'Access Denied'));

    echo sprintf(" - Dashboard Access: HTTP %d at %s [%s]\n",
        $dashCode,
        $finalUrl,
        $isDashOk ? 'PASS' : 'FAIL'
    );

    if (!$isDashOk) {
        $allPassed = false;
    }

    // Clean up temporary session file
    if (file_exists($sessFile)) {
        @unlink($sessFile);
    }
}

echo "\n================================================================\n";
echo $allPassed ? " ALL 5 ROLES AUTHENTICATION & ROUTING CHECKS PASSED!\n" : " SOME CHECKS FAILED!\n";
echo "================================================================\n";
exit($allPassed ? 0 : 1);
