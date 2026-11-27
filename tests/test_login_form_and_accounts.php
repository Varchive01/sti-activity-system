<?php
/**
 * Test Suite: Global Self-Healing Login Form and 7 Defense Accounts Verification
 * 
 * Verifies:
 * 1. Global self-healing when database accounts have legacy @marikina.sti.edu.ph emails or corrupted passwords.
 * 2. Direct login() authentication for all 7 official accounts.
 * 3. Live local HTTP POST against auth/login.php for all 7 accounts, confirming HTTP 302 redirect to their role dashboard.
 * 4. Quick Test Accounts resilience when browser password managers clear the password field.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

function assertCondition($cond, $passMsg, $failMsg) {
    if ($cond) {
        echo "[PASS] {$passMsg}\n";
    } else {
        echo "[FAIL] {$failMsg}\n";
        exit(1);
    }
}

echo "=== STARTING GLOBAL SELF-HEALING LOGIN & DEFENSE ACCOUNTS VERIFICATION ===\n\n";

$db = getDB();

// Step 1: Simulate unmigrated live server database state (legacy emails & mismatched password hashes)
$corruptedHash = password_hash('wrong_random_password_' . time(), PASSWORD_DEFAULT);
$db->prepare("UPDATE users SET email = 'payod.332445@marikina.sti.edu.ph', password = ? WHERE id = 387")->execute([$corruptedHash]);
$db->prepare("UPDATE users SET email = 'hular.370125@marikina.sti.edu.ph', password = ? WHERE id = 599")->execute([$corruptedHash]);
$db->prepare("UPDATE users SET email = 'tristan.unabia@marikina.sti.edu.ph', password = ? WHERE id = 601")->execute([$corruptedHash]);
$db->prepare("UPDATE users SET email = 'aldwin.alminar@marikina.sti.edu.ph', password = ? WHERE id = 600")->execute([$corruptedHash]);
$db->prepare("UPDATE users SET password = ? WHERE id = 2")->execute([$corruptedHash]);
$db->prepare("UPDATE users SET password = ? WHERE id = 704")->execute([$corruptedHash]);
$db->prepare("UPDATE users SET email = 'robles.376003@marikina.sti.edu.ph', password = ? WHERE id = 4")->execute([$corruptedHash]);

echo "[INFO] Simulated unmigrated live database state (legacy emails & mismatched password hashes).\n";

$accounts = [
    [
        'label'        => 'Faculty (Maria Santos)',
        'email'        => 'faculty@sti.edu',
        'pass'         => 'password',
        'expectedRole' => 'faculty',
        'expectedDir'  => 'faculty'
    ],
    [
        'label'        => 'Sir Dave (IT Program Head)',
        'email'        => 'dave@sti.edu',
        'pass'         => 'password',
        'expectedRole' => 'program_head',
        'expectedDir'  => 'program_head'
    ],
    [
        'label'        => 'Sir Tan (G.E. Program Head)',
        'email'        => 'tan@sti.edu',
        'pass'         => 'password',
        'expectedRole' => 'program_head',
        'expectedDir'  => 'program_head'
    ],
    [
        'label'        => 'Sir Aldwin (THM Program Head)',
        'email'        => 'aldwin@sti.edu',
        'pass'         => 'password',
        'expectedRole' => 'program_head',
        'expectedDir'  => 'program_head'
    ],
    [
        'label'        => 'Sir Arjay (Admin 1)',
        'email'        => 'arjay@sti.edu',
        'pass'         => 'password',
        'expectedRole' => 'admin1',
        'expectedDir'  => 'admin1'
    ],
    [
        'label'        => 'Sir Ian (Admin 2)',
        'email'        => 'ian@sti.edu',
        'pass'         => 'password',
        'expectedRole' => 'admin2',
        'expectedDir'  => 'admin2'
    ],
    [
        'label'        => 'Dean (Frederic Yulo)',
        'email'        => 'dean@sti.edu',
        'pass'         => 'password',
        'expectedRole' => 'dean',
        'expectedDir'  => 'dean'
    ]
];

// Step 2: Test HTTP POST against auth/login.php from the simulated unmigrated database state!
foreach ($accounts as $acc) {
    $ch = curl_init('http://localhost/sti-activity-system/auth/login.php');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'email'    => $acc['email'],
        'password' => $acc['pass']
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);

    $expectedTarget = '/' . $acc['expectedDir'] . '/dashboard.php';
    assertCondition(
        $httpCode === 302 && str_contains($redirectUrl, $expectedTarget),
        "HTTP POST Login for {$acc['label']} ({$acc['email']}) -> HTTP 302 Redirect to {$expectedTarget}",
        "HTTP POST Login failed for {$acc['label']} ({$acc['email']}): HTTP {$httpCode}, Redirect: {$redirectUrl}"
    );

    // Also verify database row was self-healed
    $stmt = $db->prepare("SELECT id, name, email, password, role, department, must_change_password FROM users WHERE LOWER(TRIM(email)) = ?");
    $stmt->execute([strtolower($acc['email'])]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    assertCondition(!empty($u), "Database record self-healed to email '{$acc['email']}'", "Database record missing for '{$acc['email']}'");
    assertCondition($u['role'] === $acc['expectedRole'], "Account {$acc['label']} has role '{$acc['expectedRole']}'", "Account {$acc['label']} has wrong role '{$u['role']}'");
    assertCondition(password_verify($acc['pass'], $u['password']), "Password hash in DB verifies 'password' for {$acc['email']}", "Password hash in DB failed for {$acc['email']}");
}

// Step 3: Verify Quick Test Account fallback when browser password manager clears password field
$ch = curl_init('http://localhost/sti-activity-system/auth/login.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'email'               => 'dave@sti.edu',
    'password'            => '',
    'quick_test_selected' => '1'
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$redirectUrl = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
assertCondition(
    $httpCode === 302 && str_contains($redirectUrl, '/program_head/dashboard.php'),
    "Quick Test Account autofill-cleared password fallback for dave@sti.edu -> HTTP 302 Redirect to /program_head/dashboard.php",
    "Quick Test Account fallback failed: HTTP {$httpCode}"
);

echo "\n=== ALL 7 DEFENSE ACCOUNTS & GLOBAL SELF-HEALING LOGIN TESTS PASSED! ===\n";
