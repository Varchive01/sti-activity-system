<?php
// tests/test_init_admin_sql.php
// Focused test suite for first administrator SQL initialization

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "========================================================================\n";
echo "  FIRST ADMINISTRATOR SQL INITIALIZER TEST SUITE\n";
echo "========================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $desc, bool $condition, string $detail = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] $desc\n";
        $passCount++;
    } else {
        echo " [FAIL] $desc" . ($detail ? " ($detail)" : "") . "\n";
        $failCount++;
    }
}

// -----------------------------------------------------------------------------
// 1. CLI Execution & Snippet Generation
// -----------------------------------------------------------------------------
echo "--- 1. CLI Generation & Flag Parsing ---\n";

$phpBin = PHP_BINARY;
$scriptPath = realpath(__DIR__ . '/../scripts/init_admin.php');
$testName = "Test Dean Officer";
$testEmail = "test_dean_" . time() . "@sti.edu";
$testDept = "Computer Studies";

$cmd = "\"$phpBin\" \"$scriptPath\" --sql --name=\"$testName\" --email=\"$testEmail\" --department=\"$testDept\"";
$output = shell_exec($cmd);

assertTest("1.1 CLI executes and returns non-empty output", !empty($output));
assertTest("1.2 Output displays One-Time Temporary Password", str_contains($output, "ONE-TIME TEMPORARY PASSWORD"));
assertTest("1.3 Output contains SQL INSERT statement", str_contains($output, "INSERT INTO `users`"));

// Extract temporary password from output: ">>> <password> <<<"
preg_match('/>>>\s*([^\s<]+)\s*<<</', $output, $pwdMatch);
$extractedPassword = $pwdMatch[1] ?? '';
assertTest("1.4 One-time temporary password extracted (16 chars)", strlen($extractedPassword) === 16, "Length: " . strlen($extractedPassword));

// Extract generated SQL snippet
preg_match('/INSERT INTO `users`[\s\S]+?WHERE NOT EXISTS[\s\S]+?\);/i', $output, $sqlMatch);
$extractedSql = $sqlMatch[0] ?? '';
assertTest("1.5 Valid SQL snippet extracted from output", !empty($extractedSql));

// Extract bcrypt hash from the SQL snippet
preg_match('/\$2y\$[0-9]{2}\$[A-Za-z0-9\.\/]{53}/', $extractedSql, $hashMatch);
$extractedHash = $hashMatch[0] ?? '';
assertTest("1.6 Valid bcrypt hash present in SQL query", !empty($extractedHash));

// Verify that the bcrypt hash validates against the temporary password
$verifyResult = !empty($extractedHash) && !empty($extractedPassword) && password_verify($extractedPassword, $extractedHash);
assertTest("1.7 password_verify confirms hash matches temporary password", $verifyResult);

// Verify must_change_password = 1 is set in SQL
assertTest("1.8 SQL enforces must_change_password = 1", str_contains($extractedSql, ", 1, "));
assertTest("1.9 SQL assigns 'dean' role", str_contains($extractedSql, "'dean'"));
assertTest("1.10 SQL uses idempotent WHERE NOT EXISTS clause", str_contains($extractedSql, "WHERE NOT EXISTS"));

// -----------------------------------------------------------------------------
// 2. Database Idempotency & Overwrite Protection
// -----------------------------------------------------------------------------
echo "\n--- 2. Database Idempotency & Overwrite Protection ---\n";

try {
    $db = getDB();
    // Create an isolated temporary test table mirroring users schema
    $tmpTable = "test_users_init_" . time();
    $db->exec("CREATE TEMPORARY TABLE `{$tmpTable}` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `name` varchar(150) NOT NULL,
        `email` varchar(150) NOT NULL,
        `password` varchar(255) NOT NULL,
        `role` enum('faculty','admin1','admin2','dean') NOT NULL,
        `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
        `department` varchar(100) DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Replace table name in extracted SQL to run against temporary table
    $testSql = str_replace('`users`', "`{$tmpTable}`", $extractedSql);

    // Initial insert into empty table
    $affected1 = $db->exec($testSql);
    assertTest("2.1 Initial SQL execution inserts exactly 1 row", $affected1 === 1, "Affected: $affected1");

    // Verify row properties in DB
    $rowStmt = $db->query("SELECT * FROM `{$tmpTable}` WHERE email = '{$testEmail}'");
    $insertedRow = $rowStmt->fetch(PDO::FETCH_ASSOC);
    assertTest("2.2 Stored name matches input", ($insertedRow['name'] ?? '') === $testName);
    assertTest("2.3 Stored role is 'dean'", ($insertedRow['role'] ?? '') === 'dean');
    assertTest("2.4 Stored must_change_password is 1", (int)($insertedRow['must_change_password'] ?? 0) === 1);
    assertTest("2.5 Stored password matches password_verify", password_verify($extractedPassword, $insertedRow['password'] ?? ''));

    // Second execution with identical SQL (simulate re-running snippet in phpMyAdmin)
    $affected2 = $db->exec($testSql);
    assertTest("2.6 Second SQL execution is rejected by WHERE NOT EXISTS (0 rows inserted)", $affected2 === 0, "Affected: $affected2");

    // Re-verify row count remains 1
    $count = (int)$db->query("SELECT COUNT(*) FROM `{$tmpTable}`")->fetchColumn();
    assertTest("2.7 Total row count remains 1 (no duplicate accounts)", $count === 1);

    // Third execution with a DIFFERENT user name and email while dean exists
    $cmd2 = "\"$phpBin\" \"$scriptPath\" --sql --name=\"Attacker Account\" --email=\"attacker@evil.com\" --department=\"Security\"";
    $output2 = shell_exec($cmd2);
    preg_match('/INSERT INTO `users`[\s\S]+?WHERE NOT EXISTS[\s\S]+?\);/i', $output2, $sqlMatch2);
    $attackerSql = str_replace('`users`', "`{$tmpTable}`", $sqlMatch2[0] ?? '');

    $affected3 = $db->exec($attackerSql);
    assertTest("2.8 New snippet execution with different email is blocked when Dean already exists", $affected3 === 0, "Affected: $affected3");

    $countAfter = (int)$db->query("SELECT COUNT(*) FROM `{$tmpTable}`")->fetchColumn();
    assertTest("2.9 Attacker row was not inserted", $countAfter === 1);

} catch (Throwable $e) {
    assertTest("Database execution error", false, $e->getMessage());
}

// -----------------------------------------------------------------------------
// 3. Web Access Gate Check
// -----------------------------------------------------------------------------
echo "\n--- 3. Web Access Gate Check ---\n";
// HTTP check to ensure scripts/init_admin.php cannot be invoked via browser
$ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
$content = @file_get_contents('http://localhost/sti-activity-system/scripts/init_admin.php', false, $ctx);
$statusLine = $http_response_header[0] ?? '';
preg_match('/HTTP\/\d\.\d\s+(\d+)/', $statusLine, $m);
$httpCode = isset($m[1]) ? (int)$m[1] : 0;

// It should return 403 (or 404 if directory index blocked)
assertTest("3.1 HTTP browser request to scripts/init_admin.php returns 403 Forbidden", $httpCode === 403, "HTTP status: $httpCode");

echo "\n========================================================================\n";
echo " VERIFICATION SUMMARY: Passed: $passCount | Failed: $failCount\n";
echo "========================================================================\n";
exit($failCount > 0 ? 1 : 0);

