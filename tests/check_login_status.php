<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

echo "=== LOCAL DATABASE TEST USERS ===\n";
$db = getDB();
$emails = ['dave@sti.edu', 'faculty@sti.edu', 'tan@sti.edu', 'aldwin@sti.edu', 'arjay@sti.edu', 'ian@sti.edu', 'dean@sti.edu'];
$inClause = "'" . implode("','", $emails) . "'";
$stmt = $db->query("SELECT id, name, email, role, password, must_change_password FROM users WHERE email IN ($inClause)");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($users) . " users locally:\n";
foreach ($users as $u) {
    $pwCheck = password_verify('password', $u['password']) ? 'VALID' : 'INVALID';
    echo "- [ID: {$u['id']}] {$u['name']} ({$u['email']}) [Role: {$u['role']}, must_change: {$u['must_change_password']}] => Password 'password' is $pwCheck\n";
}

echo "\n=== TESTING LOCAL LOGIN FUNCTION ===\n";
foreach ($emails as $email) {
    $res = login($email, 'password');
    echo "login('$email', 'password'): " . ($res ? "SUCCESS (Role: {$res['role']}, ID: {$res['id']})" : "FAILED") . "\n";
}

echo "\n=== TESTING LIVE SITE (https://sti-armes.freedev.app/auth/login.php) ===\n";
$liveUrl = 'https://sti-armes.freedev.app/auth/login.php';

foreach (['dave@sti.edu', 'faculty@sti.edu', 'dean@sti.edu'] as $testEmail) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $liveUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'email' => $testEmail,
        'password' => 'password',
        'quick_test_selected' => '1'
    ]));
    curl_setopt($ch, CURLOPT_COOKIEJAR, '');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);

    echo "POST $testEmail => HTTP $httpCode\n";
    if (preg_match('/Location:\s*([^\r\n]+)/i', $headers, $loc)) {
        echo "  Redirect Location: " . trim($loc[1]) . "\n";
    }
    if (strpos($body, 'Invalid institutional email or password') !== false) {
        echo "  Result: ERROR 'Invalid institutional email or password'\n";
    } elseif ($httpCode === 302 || $httpCode === 301) {
        echo "  Result: SUCCESS (Redirected)\n";
    } else {
        echo "  Result: Non-redirect response. Body sample:\n" . substr(strip_tags($body), 0, 300) . "\n";
    }
}
