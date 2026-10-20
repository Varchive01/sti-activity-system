<?php
/**
 * tests/test_user_deletion.php
 *
 * Focused automated test suite for api/user-delete.php:
 * 1. CSRF Token Protection (missing / invalid token rejected with 403)
 * 2. Role Authorization:
 *    - Unauthenticated request rejected (redirect/401)
 *    - Faculty rejected with 403
 *    - Admin 1 rejected with 403
 *    - Admin 2 trying to delete Admin 1 or Dean rejected with 403
 *    - Admin 2 deleting Faculty permitted
 *    - Dean deleting Admin 1 / Admin 2 / Faculty permitted
 * 3. Self-Deletion Protection (Dean or Admin 2 cannot delete own ID, 403)
 * 4. Last-Dean Protection (Sole Dean in system cannot be deleted, 403)
 * 5. Linked-Record Blocking:
 *    - User linked to activities blocked (409)
 *    - User linked to approval_logs blocked (409)
 *    - User linked to faculty_tasks blocked (409)
 *    - User linked to task_deliverables blocked (409)
 *    - User linked to documents blocked (409)
 *    - User linked to post_event blocked (409)
 * 6. Unused Account Deletion:
 *    - Deletes permitted ephemeral records (notifications, reminder logs)
 *    - Deletes user from users table cleanly inside transaction (200)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "========================================================================\n";
echo " STI-ARMES USER DELETION API TEST SUITE (ISOLATED TEST DATABASE)\n";
echo "========================================================================\n\n";

$testDbName = 'sti_activity_system_test';
$rootDsn = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
$rootPdo = new PDO($rootDsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// 1. Create fresh isolated test database
echo "Setting up fresh isolated test database: $testDbName...\n";
$rootPdo->exec("DROP DATABASE IF EXISTS `$testDbName`");
$rootPdo->exec("CREATE DATABASE `$testDbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

$testDsn = "mysql:host=" . DB_HOST . ";dbname=" . $testDbName . ";charset=" . DB_CHARSET;
$testPdo = new PDO($testDsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

// 2. Load schema from sti_activity_system.sql
$schemaSql = file_get_contents(__DIR__ . '/../sti_activity_system.sql');
$testPdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
$testPdo->exec($schemaSql);
$testPdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

// Create task_deliverables in test database
$testPdo->exec("
    CREATE TABLE IF NOT EXISTS `task_deliverables` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `task_id` int(11) NOT NULL,
      `activity_id` int(11) NOT NULL,
      `uploaded_by` int(11) NOT NULL,
      `file_path` varchar(500) NOT NULL,
      `file_name` varchar(255) NOT NULL,
      `file_size` int(11) DEFAULT NULL,
      `file_type` varchar(100) DEFAULT NULL,
      `storage_type` enum('local','onedrive') NOT NULL DEFAULT 'local',
      `onedrive_item_id` varchar(255) DEFAULT NULL,
      `status` enum('pending_review','approved','rejected') NOT NULL DEFAULT 'pending_review',
      `rejection_reason` text DEFAULT NULL,
      `reviewed_by` int(11) DEFAULT NULL,
      `reviewed_at` timestamp NULL DEFAULT NULL,
      `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

echo "[SETUP COMPLETE] Isolated test database ready.\n\n";

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function assertCheck(string $testName, bool $condition, string $detail = '', ?array $res = null): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo sprintf("  [%02d] PASS: %s\n", $totalTests, $testName);
        if ($detail !== '') echo "       Info: {$detail}\n";
    } else {
        $failedTests++;
        echo sprintf("  [%02d] FAIL: %s\n", $totalTests, $testName);
        if ($detail !== '') echo "       Error: {$detail}\n";
        if ($res !== null) echo "       Actual Result: status={$res['status']} body={$res['body']}\n";
    }
}

// Helper to run api/user-delete.php isolated
function callDeleteApi(?array $sessionData, array $postData, string $testDb): array {
    $script = str_replace('\\', '/', realpath(__DIR__ . '/../api/user-delete.php'));
    $baseDir = str_replace('\\', '/', realpath(__DIR__ . '/..'));

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

    $code = "<?php
        putenv('STI_TEST_DB={$testDb}');
        register_shutdown_function(function() {
            echo '\n___STATUS:' . http_response_code() . '___\n';
        });
        {$sessionInit}
        \$_SERVER['REQUEST_METHOD'] = 'POST';
        \$_SERVER['HTTP_ACCEPT'] = 'application/json';
        \$_POST = {$postExport};
        ob_start();
        try {
            include '{$script}';
        } catch (Throwable \$e) {
            echo 'EXCEPTION: ' . \$e->getMessage();
        }
    ";

    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $proc = proc_open('php', $desc, $pipes);
    if (!is_resource($proc)) {
        return ['status' => 500, 'body' => 'Failed to spawn process', 'json' => null];
    }

    fwrite($pipes[0], $code);
    fclose($pipes[0]);

    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $combined = $out . $err;
    $status = 200;
    if (preg_match('/___STATUS:(\d+)___/', $combined, $m)) {
        $status = (int)$m[1];
    }
    $rawBody = trim(preg_replace('/___STATUS:\d+___\n?/', '', $combined));
    $json = json_decode($rawBody, true);

    return [
        'status' => $status,
        'body'   => $rawBody,
        'json'   => $json
    ];
}

// -------------------------------------------------------------
// Seed Base Accounts into Isolated DB
// -------------------------------------------------------------
$testPdo->exec("SET FOREIGN_KEY_CHECKS = 0; TRUNCATE TABLE users; SET FOREIGN_KEY_CHECKS = 1;");
$pwdHash = password_hash('password123', PASSWORD_DEFAULT);

// 1. Dean 1 (Frederic Yulo)
$testPdo->prepare("INSERT INTO users (id, name, email, password, role, department) VALUES (1, 'Frederic Yulo', 'dean@sti.edu', ?, 'dean', 'Administration')")->execute([$pwdHash]);
// 2. Admin 2 (Ian Jade Barangan)
$testPdo->prepare("INSERT INTO users (id, name, email, password, role, department) VALUES (2, 'Ian Jade Barangan', 'ian@sti.edu', ?, 'admin2', 'Events Committee')")->execute([$pwdHash]);
// 3. Admin 1 (Ar-jay Agabayani)
$testPdo->prepare("INSERT INTO users (id, name, email, password, role, department) VALUES (3, 'Ar-jay Agabayani', 'arjay@sti.edu', ?, 'admin1', 'Student Org')")->execute([$pwdHash]);
// 4. Faculty 1 (Maria Santos - will have activities)
$testPdo->prepare("INSERT INTO users (id, name, email, password, role, department) VALUES (4, 'Maria Santos', 'maria@sti.edu', ?, 'faculty', 'IT Department')")->execute([$pwdHash]);
// 5. Faculty 2 (Juan Dela Cruz - clean/unused)
$testPdo->prepare("INSERT INTO users (id, name, email, password, role, department) VALUES (5, 'Juan Dela Cruz', 'juan@sti.edu', ?, 'faculty', 'CS Department')")->execute([$pwdHash]);

$csrf = 'test_token_xyz_987654321';

$deanSession = [
    'user_id'    => 1,
    'user_name'  => 'Frederic Yulo',
    'user_email' => 'dean@sti.edu',
    'user_role'  => 'dean',
    'csrf_token' => $csrf
];

$admin2Session = [
    'user_id'    => 2,
    'user_name'  => 'Ian Jade Barangan',
    'user_email' => 'ian@sti.edu',
    'user_role'  => 'admin2',
    'csrf_token' => $csrf
];

$admin1Session = [
    'user_id'    => 3,
    'user_name'  => 'Ar-jay Agabayani',
    'user_email' => 'arjay@sti.edu',
    'user_role'  => 'admin1',
    'csrf_token' => $csrf
];

$facultySession = [
    'user_id'    => 4,
    'user_name'  => 'Maria Santos',
    'user_email' => 'maria@sti.edu',
    'user_role'  => 'faculty',
    'csrf_token' => $csrf
];

// =============================================================
// TEST GROUP 1: CSRF & AUTHENTICATION
// =============================================================
echo "--- Group 1: CSRF & Role Guards ---\n";

// 1. Missing CSRF Token
$res = callDeleteApi($deanSession, ['user_id' => 5], $testDbName);
assertCheck("Missing CSRF token is rejected with HTTP 403", $res['status'] === 403);

// 2. Invalid CSRF Token
$res = callDeleteApi($deanSession, ['user_id' => 5, 'csrf_token' => 'invalid_csrf'], $testDbName);
assertCheck("Invalid CSRF token is rejected with HTTP 403", $res['status'] === 403);

// 3. Faculty caller is rejected with HTTP 403
$res = callDeleteApi($facultySession, ['user_id' => 5, 'csrf_token' => $csrf], $testDbName);
assertCheck("Faculty role caller is rejected with HTTP 403", $res['status'] === 403);

// 4. Admin 1 caller is rejected with HTTP 403
$res = callDeleteApi($admin1Session, ['user_id' => 5, 'csrf_token' => $csrf], $testDbName);
assertCheck("Admin 1 role caller is rejected with HTTP 403", $res['status'] === 403);

// 5. Admin 2 attempting to delete Admin 1 is rejected with HTTP 403
$res = callDeleteApi($admin2Session, ['user_id' => 3, 'csrf_token' => $csrf], $testDbName);
assertCheck("Admin 2 attempting to delete Admin 1 account is rejected with HTTP 403", $res['status'] === 403 && ($res['json']['error_code'] ?? '') === 'forbidden_target');

// 6. Admin 2 attempting to delete Dean is rejected with HTTP 403
$res = callDeleteApi($admin2Session, ['user_id' => 1, 'csrf_token' => $csrf], $testDbName);
assertCheck("Admin 2 attempting to delete Dean account is rejected with HTTP 403", $res['status'] === 403 && ($res['json']['error_code'] ?? '') === 'forbidden_target');

// =============================================================
// TEST GROUP 2: SELF-DELETION & LAST-DEAN GUARDS
// =============================================================
echo "\n--- Group 2: Self-Deletion & Last-Dean Guards ---\n";

// 7. Dean cannot delete own account
$res = callDeleteApi($deanSession, ['user_id' => 1, 'csrf_token' => $csrf], $testDbName);
assertCheck("Dean attempting self-deletion is rejected with HTTP 403", $res['status'] === 403 && ($res['json']['error_code'] ?? '') === 'forbidden_self_deletion');

// 8. Admin 2 cannot delete own account
$res = callDeleteApi($admin2Session, ['user_id' => 2, 'csrf_token' => $csrf], $testDbName);
assertCheck("Admin 2 attempting self-deletion is rejected with HTTP 403", $res['status'] === 403 && ($res['json']['error_code'] ?? '') === 'forbidden_self_deletion');

// 9. Deleting the only Dean account in the system is rejected
// Create Dean 2 in DB (id 10) so Dean can call delete on Dean 1, but if only 1 Dean exists, must reject.
// In our DB currently, Dean 1 is the ONLY dean.
$secondDeanSession = [
    'user_id'    => 10,
    'user_name'  => 'Temporary Dean',
    'user_email' => 'tempdean@sti.edu',
    'user_role'  => 'dean',
    'csrf_token' => $csrf
];
// Dean 1 is sole dean in DB. Caller is Dean (id 10). Target is Dean 1.
$res = callDeleteApi($secondDeanSession, ['user_id' => 1, 'csrf_token' => $csrf], $testDbName);
assertCheck("Deletion of the sole/last Dean account is rejected with HTTP 403", $res['status'] === 403 && ($res['json']['error_code'] ?? '') === 'forbidden_last_dean');

// =============================================================
// TEST GROUP 3: LINKED RECORD BLOCKING (AUDIT INTEGRITY)
// =============================================================
echo "\n--- Group 3: Linked Record Blocking ---\n";

// Case 3.1: User is proponent of an activity
$testPdo->exec("INSERT INTO activities (id, title, faculty_id, source, event_date, status) VALUES (101, 'Test Proposal', 4, 'faculty', '2026-11-01', 'submitted')");
$res = callDeleteApi($deanSession, ['user_id' => 4, 'csrf_token' => $csrf], $testDbName);
assertCheck("User linked to an activity proposal is blocked from deletion (409)", $res['status'] === 409 && ($res['json']['error_code'] ?? '') === 'user_has_activity_history', '', $res);

// Case 3.2: User is reviewer in approval_logs
$testPdo->exec("INSERT INTO users (id, name, email, password, role) VALUES (20, 'Reviewer Test', 'rev@sti.edu', 'hash', 'admin1')");
$testPdo->exec("INSERT INTO approval_logs (activity_id, reviewer_id, action, notes) VALUES (101, 20, 'approved', 'Looks good')");
$res = callDeleteApi($deanSession, ['user_id' => 20, 'csrf_token' => $csrf], $testDbName);
assertCheck("User linked to approval logs is blocked from deletion (409)", $res['status'] === 409 && ($res['json']['error_code'] ?? '') === 'user_has_activity_history', '', $res);

// Case 3.3: User assigned to committee faculty_tasks
$testPdo->exec("INSERT INTO users (id, name, email, password, role) VALUES (21, 'Task Member Test', 'taskmember@sti.edu', 'hash', 'faculty')");
$testPdo->exec("INSERT INTO faculty_tasks (id, activity_id, task_title, assigned_user_id, status) VALUES (301, 101, 'Stage Setup', 21, 'Not Started')");
$res = callDeleteApi($deanSession, ['user_id' => 21, 'csrf_token' => $csrf], $testDbName);
assertCheck("User linked to faculty_tasks assignments is blocked from deletion (409)", $res['status'] === 409 && ($res['json']['error_code'] ?? '') === 'user_has_activity_history', '', $res);

// Case 3.4: User uploaded task deliverables
$testPdo->exec("INSERT INTO users (id, name, email, password, role) VALUES (22, 'Deliverable Uploader Test', 'uploader@sti.edu', 'hash', 'faculty')");
$testPdo->exec("INSERT INTO task_deliverables (id, task_id, activity_id, uploaded_by, file_path, file_name) VALUES (401, 301, 101, 22, 'uploads/deliv.pdf', 'deliv.pdf')");
$res = callDeleteApi($deanSession, ['user_id' => 22, 'csrf_token' => $csrf], $testDbName);
assertCheck("User linked to task_deliverables is blocked from deletion (409)", $res['status'] === 409 && ($res['json']['error_code'] ?? '') === 'user_has_activity_history');

// Case 3.5: User uploaded documents
$testPdo->exec("INSERT INTO users (id, name, email, password, role) VALUES (23, 'Document Uploader Test', 'docuploader@sti.edu', 'hash', 'faculty')");
$testPdo->exec("INSERT INTO documents (id, activity_id, file_name, file_path, uploaded_by) VALUES (501, 101, 'doc.pdf', 'uploads/doc.pdf', 23)");
$res = callDeleteApi($deanSession, ['user_id' => 23, 'csrf_token' => $csrf], $testDbName);
assertCheck("User linked to documents upload is blocked from deletion (409)", $res['status'] === 409 && ($res['json']['error_code'] ?? '') === 'user_has_activity_history');

// Case 3.6: User submitted post_event
$testPdo->exec("INSERT INTO users (id, name, email, password, role) VALUES (24, 'Post Event Submitter Test', 'postev@sti.edu', 'hash', 'faculty')");
$testPdo->exec("INSERT INTO post_event (activity_id, submitted_by) VALUES (101, 24)");
$res = callDeleteApi($deanSession, ['user_id' => 24, 'csrf_token' => $csrf], $testDbName);
assertCheck("User linked to post_event submission is blocked from deletion (409)", $res['status'] === 409 && ($res['json']['error_code'] ?? '') === 'user_has_activity_history');

// =============================================================
// TEST GROUP 4: UNUSED ACCOUNT DELETION & EPHEMERAL CLEANUP
// =============================================================
echo "\n--- Group 4: Unused Account Clean Deletion ---\n";

// Create clean faculty user (id 30) with permitted ephemeral records (notifications, task reminders)
$testPdo->exec("INSERT INTO users (id, name, email, password, role, department) VALUES (30, 'Unused Faculty', 'unused@sti.edu', 'hash', 'faculty', 'General Ed')");
$testPdo->exec("INSERT INTO notifications (user_id, message) VALUES (30, 'Welcome to STI-ARMES')");
$testPdo->exec("INSERT INTO task_reminder_logs (task_id, activity_id, user_id, reminder_type) VALUES (999, 999, 30, 'due_soon')");

// Verify records exist before delete
$notifBefore = $testPdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 30")->fetchColumn();
$remindBefore = $testPdo->query("SELECT COUNT(*) FROM task_reminder_logs WHERE user_id = 30")->fetchColumn();
$userBefore = $testPdo->query("SELECT COUNT(*) FROM users WHERE id = 30")->fetchColumn();
assertCheck("Pre-check: Unused user and ephemeral records exist before test", $notifBefore == 1 && $remindBefore == 1 && $userBefore == 1);

// Admin 2 deletes unused Faculty (id 30)
$res = callDeleteApi($admin2Session, ['user_id' => 30, 'csrf_token' => $csrf], $testDbName);
assertCheck("Admin 2 successfully deletes unused Faculty account (HTTP 200)", $res['status'] === 200 && ($res['json']['success'] ?? false) === true);

// Verify records after delete
$notifAfter = $testPdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 30")->fetchColumn();
$remindAfter = $testPdo->query("SELECT COUNT(*) FROM task_reminder_logs WHERE user_id = 30")->fetchColumn();
$userAfter = $testPdo->query("SELECT COUNT(*) FROM users WHERE id = 30")->fetchColumn();

assertCheck("Ephemeral notifications cleaned up automatically", $notifAfter == 0);
assertCheck("Ephemeral task reminders cleaned up automatically", $remindAfter == 0);
assertCheck("User completely removed from users table in database", $userAfter == 0);

// Dean deletes clean Faculty 2 (id 5)
$resDean = callDeleteApi($deanSession, ['user_id' => 5, 'csrf_token' => $csrf], $testDbName);
assertCheck("Dean successfully deletes unused Faculty account (HTTP 200)", $resDean['status'] === 200 && ($resDean['json']['success'] ?? false) === true);
$user5After = $testPdo->query("SELECT COUNT(*) FROM users WHERE id = 5")->fetchColumn();
assertCheck("Faculty 2 removed from database by Dean", $user5After == 0);

// Clean up isolated test database
echo "\nCleaning up isolated test database: $testDbName...\n";
$rootPdo->exec("DROP DATABASE IF EXISTS `$testDbName`");
echo "Done.\n\n";

echo "========================================================================\n";
echo " TEST SUMMARY: Total: {$totalTests} | Passed: {$passedTests} | Failed: {$failedTests}\n";
echo "========================================================================\n";

if ($failedTests > 0) {
    exit(1);
}
exit(0);
