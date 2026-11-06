<?php
/**
 * Test Suite: Outlook Notification Deliverability & Bounce Protection
 * Verifies that:
 * 1. All official faculty and users have valid institutional emails (no @test.com or @invalid).
 * 2. Andrea Gatbonton and Ricky Manliguis have active @marikina.sti.edu.ph emails.
 * 3. EmailService deliverability guard blocks unroutable test domains from hitting Microsoft Graph.
 * 4. Automated test proposals (AutoTest) are safely simulated without sending live mail.
 * 5. Valid institutional emails pass deliverability checks.
 */

$rootDir = dirname(__DIR__);
require_once $rootDir . '/config/config.php';
require_once $rootDir . '/config/database.php';
require_once $rootDir . '/includes/functions.php';
require_once $rootDir . '/includes/EmailService.php';

$db = getDB();
$results = [];

function recordTest($testName, $passed, $details = '') {
    global $results;
    $results[] = [
        'test' => $testName,
        'passed' => $passed,
        'details' => $details
    ];
}

$emailService = new EmailService();

// 1. Verify Andrea Gatbonton has official institutional email
$stmt = $db->prepare("SELECT id, name, email FROM users WHERE name LIKE '%Andrea Gatbonton%' LIMIT 1");
$stmt->execute();
$andrea = $stmt->fetch(PDO::FETCH_ASSOC);
$andreaValid = $andrea && (strpos($andrea['email'], '@marikina.sti.edu.ph') !== false) && (strpos($andrea['email'], '@test.com') === false);
recordTest("Andrea Gatbonton Email Validated", $andreaValid, "Current email: " . ($andrea['email'] ?? 'NOT FOUND'));

// 2. Verify Ricky Manliguis has official institutional email
$stmt = $db->prepare("SELECT id, name, email FROM users WHERE name LIKE '%Ricky Manliguis%' LIMIT 1");
$stmt->execute();
$ricky = $stmt->fetch(PDO::FETCH_ASSOC);
$rickyValid = $ricky && (strpos($ricky['email'], '@marikina.sti.edu.ph') !== false) && (strpos($ricky['email'], '@test.com') === false);
recordTest("Ricky Manliguis Email Validated", $rickyValid, "Current email: " . ($ricky['email'] ?? 'NOT FOUND'));

// 3. Verify zero users have @test.com or @invalid in users table
$stmt = $db->query("SELECT COUNT(*) FROM users WHERE email LIKE '%@test.com%' OR email LIKE '%@invalid%'");
$badCount = (int)$stmt->fetchColumn();
recordTest("Zero Banned Test Domains in Users Table", ($badCount === 0), "Found {$badCount} accounts with test/invalid domains (expected 0)");

// 4. Test deliverability guard against unroutable test domain (@test.com)
$guardTestCom = $emailService->validateRecipientDeliverability('fac_thm_accept@test.com', 'Proposal Review', 'General Proposal');
recordTest(
    "Deliverability Guard: Blocks @test.com",
    ($guardTestCom['deliverable'] === false && strpos($guardTestCom['reason'], 'test domain') !== false),
    "Reason: {$guardTestCom['reason']}"
);

// 5. Test deliverability guard against automated test context
$guardAutoTest = $emailService->validateRecipientDeliverability('faculty@marikina.sti.edu.ph', 'Team Update: Collaborative Hackathon 2026 – AutoTest 1793844558', 'AutoTest Proposal');
recordTest(
    "Deliverability Guard: Blocks Automated AutoTest proposal spam",
    ($guardAutoTest['deliverable'] === false && strpos($guardAutoTest['reason'], 'Automated test') !== false),
    "Reason: {$guardAutoTest['reason']}"
);

// 6. Test deliverability guard against non-existent domain
$guardBadDomain = $emailService->validateRecipientDeliverability('user@domaindoesnotexist999888777.xyz', 'Notice', 'Regular Proposal');
recordTest(
    "Deliverability Guard: Blocks non-existent DNS domains",
    ($guardBadDomain['deliverable'] === false && strpos($guardBadDomain['reason'], 'no valid MX') !== false),
    "Reason: {$guardBadDomain['reason']}"
);

// 7. Test deliverability guard permits valid institutional domain
$guardValid = $emailService->validateRecipientDeliverability('andrea.gatbonton@marikina.sti.edu.ph', 'Activity Update', 'Campus Hackathon 2026');
recordTest(
    "Deliverability Guard: Permits valid @marikina.sti.edu.ph address",
    ($guardValid['deliverable'] === true && $guardValid['reason'] === 'OK'),
    "Normalized email: {$guardValid['normalized_email']}"
);

// 8. Test sendNotification gracefully skips unroutable domain without crashing
$beforeLogs = (int)$db->query("SELECT COUNT(*) FROM email_logs")->fetchColumn();
$sendRes = $emailService->sendNotification('fac_thm_accept@test.com', 'Andrea Gatbonton', 'Collaborative Hackathon', 'Proposal Title', 'pending', 'Test Message', 'http://localhost/test', 914, 'test_guard');
$afterLogs = (int)$db->query("SELECT COUNT(*) FROM email_logs")->fetchColumn();
$lastLog = $db->query("SELECT status, error_message FROM email_logs ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$safeLoggingPassed = ($sendRes === true) && ($afterLogs > $beforeLogs) && ($lastLog['status'] === 'simulated_success');
recordTest(
    "sendNotification: Graceful Skip & NDR Prevention",
    $safeLoggingPassed,
    "Status: " . ($lastLog['status'] ?? 'none') . " | Detail: " . ($lastLog['error_message'] ?? 'none')
);

echo "=======================================================================\n";
echo "      OUTLOOK NOTIFICATION DELIVERABILITY & NDR PREVENTION TEST\n";
echo "=======================================================================\n\n";

$allPassed = true;
foreach ($results as $res) {
    $status = $res['passed'] ? '[PASS]' : '[FAIL]';
    if (!$res['passed']) $allPassed = false;
    echo sprintf("%-6s | %-48s | %s\n", $status, $res['test'], $res['details']);
}

echo "\n-----------------------------------------------------------------------\n";
echo "Overall Result: " . ($allPassed ? "ALL TESTS PASSED (NDR BOUNCE PREVENTED)" : "FAILURES DETECTED") . "\n";
echo "=======================================================================\n";

exit($allPassed ? 0 : 1);

