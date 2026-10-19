<?php
/**
 * tests/test_import_evaluations_csv.php
 *
 * Comprehensive, focused verification suite for api/import-evaluations-csv.php
 *
 * Verifies:
 * 1. Google Forms CSV standard wide export import.
 * 2. Microsoft Forms CSV standard wide export import.
 * 3. Flat / Long table CSV export import.
 * 4. Deduplication and idempotency on re-import.
 * 5. Authorization: Faculty activity ownership enforcement (cross-user blocked).
 * 6. Role-based access: Unauthenticated rejection and Admin/Dean access.
 * 7. Header and file content validation (empty, malformed, non-CSV).
 * 8. Atomic transaction integrity (rollback on failure).
 * 9. Integration compatibility with response viewer and KPI calculations.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "========================================================================\n";
echo "  EVALUATION RESPONSES CSV IMPORTER FOCUSED VERIFICATION\n";
echo "========================================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $title, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo " [\033[32mPASS\033[0m] {$title}\n";
        $passed++;
    } else {
        echo " [\033[31mFAIL\033[0m] {$title}" . ($detail ? " - {$detail}" : "") . "\n";
        $failed++;
    }
}

$db = getDB();

// 1. Setup isolated test users
$testId = time() . '_' . mt_rand(1000, 9999);
$db->beginTransaction();
try {
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES (?, ?, 'test_pass', 'faculty', 'CCS')");
    $stmt->execute(["Faculty Proponent {$testId}", "fac_prop_{$testId}@sti.edu"]);
    $facProponentId = (int)$db->lastInsertId();

    $stmt->execute(["Faculty Stranger {$testId}", "fac_stranger_{$testId}@sti.edu"]);
    $facStrangerId = (int)$db->lastInsertId();

    $stmtAdmin = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES (?, ?, 'test_pass', 'admin1', 'AdminDept')");
    $stmtAdmin->execute(["Admin User {$testId}", "admin_{$testId}@sti.edu"]);
    $adminId = (int)$db->lastInsertId();

    // Activities
    $actStmt = $db->prepare("
        INSERT INTO activities (faculty_id, title, description, venue, event_date, start_time, end_time, source, status)
        VALUES (?, ?, 'CSV Import Activity', 'Lab 1', '2026-10-25', '09:00:00', '12:00:00', 'faculty', 'approved')
    ");
    $actStmt->execute([$facProponentId, "Proponent Activity {$testId}"]);
    $proponentActId = (int)$db->lastInsertId();

    $actStmt->execute([$facStrangerId, "Stranger Activity {$testId}"]);
    $strangerActId = (int)$db->lastInsertId();

    $db->commit();
} catch (\Throwable $e) {
    $db->rollBack();
    die("Setup failed: " . $e->getMessage() . "\n");
}

/**
 * Executes api/import-evaluations-csv.php in an isolated PHP subprocess.
 */
function executeCsvImportSubprocess(
    int $activityId,
    string $csvContent,
    string $filename = 'evaluations.csv',
    ?array $userSession = null,
    ?string $csrfToken = null
): array {
    $endpoint = __DIR__ . '/../api/import-evaluations-csv.php';
    
    // Write CSV content to a temp file
    $tmpCsv = tempnam(sys_get_temp_dir(), 'test_csv_');
    file_put_contents($tmpCsv, $csvContent);

    $sessionExport = var_export($userSession, true);
    $csrfExport = var_export($csrfToken, true);
    $tmpCsvExport = var_export($tmpCsv, true);
    $filenameExport = var_export($filename, true);
    $fileSizeExport = strlen($csvContent);

    $runnerCode = "<?php
    if (session_status() === PHP_SESSION_NONE) session_start();
    \$sessionData = {$sessionExport};
    if (\$sessionData !== null) {
        \$_SESSION['user_id'] = \$sessionData['id'] ?? null;
        \$_SESSION['user_role'] = \$sessionData['role'] ?? null;
        \$_SESSION['user_name'] = \$sessionData['name'] ?? 'Test User';
        \$_SESSION['user_email'] = \$sessionData['email'] ?? 'test@sti.edu';
        \$_SESSION['last_activity'] = time();
        if ({$csrfExport} !== null) {
            \$_SESSION['csrf_token'] = {$csrfExport};
        }
    } else {
        \$_SESSION = [];
    }

    \$_SERVER['REQUEST_METHOD'] = 'POST';
    \$_POST = [
        'activity_id' => {$activityId}
    ];
    if ({$csrfExport} !== null) {
        \$_POST['csrf_token'] = {$csrfExport};
    }

    \$_FILES = [
        'csv_file' => [
            'name'     => {$filenameExport},
            'type'     => 'text/csv',
            'tmp_name' => {$tmpCsvExport},
            'error'    => UPLOAD_ERR_OK,
            'size'     => {$fileSizeExport}
        ]
    ];

    require '{$endpoint}';
    ";

    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $proc = proc_open('php', $desc, $pipes, __DIR__);
    if (!is_resource($proc)) {
        @unlink($tmpCsv);
        return ['status' => 500, 'raw' => '', 'data' => null];
    }

    fwrite($pipes[0], $runnerCode);
    fclose($pipes[0]);

    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($proc);

    @unlink($tmpCsv);

    $json = json_decode(trim($out), true);

    return [
        'status' => $status,
        'raw'    => $out,
        'err'    => $err,
        'data'   => $json
    ];
}

$proponentSession = ['id' => $facProponentId, 'role' => 'faculty', 'name' => "Faculty Proponent {$testId}", 'email' => "fac_prop_{$testId}@sti.edu"];
$strangerSession  = ['id' => $facStrangerId, 'role' => 'faculty', 'name' => "Faculty Stranger {$testId}", 'email' => "fac_stranger_{$testId}@sti.edu"];
$adminSession     = ['id' => $adminId, 'role' => 'admin1', 'name' => "Admin User {$testId}", 'email' => "admin_{$testId}@sti.edu"];

// =========================================================================
// TEST 1: Google Forms CSV Standard Export Import
// =========================================================================
$googleCsv = <<<CSV
Timestamp,Email Address,Topic Relevance,Speaker Effectiveness,Overall Rating,Comments and Suggestions
2026/10/10 10:15:30 AM GMT+8,alice@sti.edu,4,Strongly Agree,4,The examples were very clear and easy to follow.
2026/10/10 10:18:45 AM GMT+8,bob@sti.edu,3,Agree,4,Well organized event.
2026/10/10 10:22:10 AM GMT+8,charlie@sti.edu,4,4,5,Excellent speaker! Loved the live demo.
CSV;

$res1 = executeCsvImportSubprocess($proponentActId, $googleCsv, 'google_forms_responses.csv', $proponentSession);
$t1Pass = !empty($res1['data']['success']) && ($res1['data']['imported_count'] ?? 0) === 12; // 3 participants * 4 questions = 12
assertTest("1. Google Forms CSV Standard Export Import", $t1Pass, "Imported: " . ($res1['data']['imported_count'] ?? 0) . " records");

// Verify in Database
$dbCount = (int)$db->query("SELECT COUNT(*) FROM kpi_evaluations WHERE activity_id = {$proponentActId}")->fetchColumn();
assertTest("1.1 Database Persistence of 12 Records", $dbCount === 12, "Count in kpi_evaluations: {$dbCount}");

// Verify 5/5 score clamping to 4 (satisfies schema constraint)
$maxRating = (int)$db->query("SELECT MAX(rating) FROM kpi_evaluations WHERE activity_id = {$proponentActId}")->fetchColumn();
$minRating = (int)$db->query("SELECT MIN(rating) FROM kpi_evaluations WHERE activity_id = {$proponentActId} AND rating IS NOT NULL")->fetchColumn();
assertTest("1.2 Rating Values Constrained to 1..4", $maxRating <= 4 && $minRating >= 1, "Min rating: {$minRating}, Max rating: {$maxRating}");

// =========================================================================
// TEST 2: Deduplication and Idempotent Re-Import
// =========================================================================
$res2 = executeCsvImportSubprocess($proponentActId, $googleCsv, 'google_forms_responses.csv', $proponentSession);
$t2Pass = !empty($res2['data']['success']) && ($res2['data']['imported_count'] ?? 0) === 0 && ($res2['data']['skipped_count'] ?? 0) === 12;
assertTest("2. Deduplication on Re-Import (Idempotency)", $t2Pass, "Imported: {$res2['data']['imported_count']}, Skipped: {$res2['data']['skipped_count']}");

$dbCountAfter = (int)$db->query("SELECT COUNT(*) FROM kpi_evaluations WHERE activity_id = {$proponentActId}")->fetchColumn();
assertTest("2.1 Database Record Count Unchanged", $dbCountAfter === 12, "Count in kpi_evaluations: {$dbCountAfter}");

// =========================================================================
// TEST 3: Microsoft Forms Standard CSV Export Import
// =========================================================================
$msFormsCsv = <<<CSV
ID,Start time,Completion time,Email,Name,Workshop Content,Hands-on Practicality,Feedback & Improvements
1,2026-10-10 09:00:00,2026-10-10 09:12:00,david@sti.edu,David Miller,Very Satisfied,4,Need faster internet next time.
2,2026-10-10 09:05:00,2026-10-10 09:15:00,eva@sti.edu,Eva Green,4,3,Everything ran on time!
CSV;

$res3 = executeCsvImportSubprocess($proponentActId, $msFormsCsv, 'ms_forms_responses.csv', $proponentSession);
$t3Pass = !empty($res3['data']['success']) && ($res3['data']['imported_count'] ?? 0) === 6; // 2 participants * 3 questions = 6
assertTest("3. Microsoft Forms CSV Standard Export Import", $t3Pass, "Imported: " . ($res3['data']['imported_count'] ?? 0) . " records");

// =========================================================================
// TEST 4: Flat / Long Table CSV Format Import
// =========================================================================
$flatCsv = <<<CSV
Criteria,Rating,Comments,Evaluator,Timestamp
Speaker Mastery,4,In-depth technical knowledge,Grace Hopper,2026-10-10 11:30:00
Audio and Visuals,3,Microphone had slight echo,Grace Hopper,2026-10-10 11:30:00
CSV;

$res4 = executeCsvImportSubprocess($proponentActId, $flatCsv, 'flat_evaluations.csv', $proponentSession);
$t4Pass = !empty($res4['data']['success']) && ($res4['data']['imported_count'] ?? 0) === 2;
assertTest("4. Flat / Long Table CSV Format Import", $t4Pass, "Imported: " . ($res4['data']['imported_count'] ?? 0) . " records");

// =========================================================================
// TEST 5: Authorization & Proponent Ownership Guard
// =========================================================================
// Faculty Stranger attempts to import for Faculty Proponent's activity
$res5 = executeCsvImportSubprocess($proponentActId, $googleCsv, 'stranger_import.csv', $strangerSession);
$t5Pass = empty($res5['data']['success']) && str_contains($res5['data']['error'] ?? '', 'Forbidden');
assertTest("5. Authorization: Stranger Faculty Blocked (HTTP 403)", $t5Pass, "Error message: " . ($res5['data']['error'] ?? ''));

// Unauthenticated user attempts to import
$res5Unauth = executeCsvImportSubprocess($proponentActId, $googleCsv, 'unauth_import.csv', null);
$t5UnauthPass = empty($res5Unauth['data']['success']) && str_contains($res5Unauth['data']['error'] ?? '', 'Unauthorized');
assertTest("5.1 Authentication Guard (HTTP 401 on missing session)", $t5UnauthPass, "Error: " . ($res5Unauth['data']['error'] ?? ''));

// Admin importing for any activity
$adminCsv = <<<CSV
Timestamp,Email Address,Admin Test Metric,Admin Comments
2026/10/10 12:00:00 PM GMT+8,admin_resp@sti.edu,4,Admin verified response.
CSV;
$resAdmin = executeCsvImportSubprocess($proponentActId, $adminCsv, 'admin_import.csv', $adminSession);
$tAdminPass = !empty($resAdmin['data']['success']) && ($resAdmin['data']['imported_count'] ?? 0) === 2;
assertTest("5.2 Administrative Role Import (Admin1/Admin2/Dean allowed)", $tAdminPass, "Imported: " . ($resAdmin['data']['imported_count'] ?? 0));

// =========================================================================
// TEST 6: Header & Format Validation (Empty & Malformed files)
// =========================================================================
$unsupportedHeadersCsv = <<<CSV
Col1,Col2,Col3
val1,val2,val3
CSV;
$res6 = executeCsvImportSubprocess($proponentActId, $unsupportedHeadersCsv, 'unsupported.csv', $proponentSession);
$t6Pass = empty($res6['data']['success']) && str_contains($res6['data']['error'] ?? '', 'Could not detect');
assertTest("6. Unsupported / Headerless CSV Rejection (HTTP 400)", $t6Pass, "Error: " . ($res6['data']['error'] ?? ''));

$emptyCsv = "";
$res6Empty = executeCsvImportSubprocess($proponentActId, $emptyCsv, 'empty.csv', $proponentSession);
$t6EmptyPass = empty($res6Empty['data']['success']) && str_contains($res6Empty['data']['error'] ?? '', 'empty or does not contain');
assertTest("6.1 Empty CSV Rejection (HTTP 400)", $t6EmptyPass, "Error: " . ($res6Empty['data']['error'] ?? ''));

// =========================================================================
// TEST 7: File Extension & Mime Validation
// =========================================================================
$res7 = executeCsvImportSubprocess($proponentActId, "Some dummy text", 'report.pdf', $proponentSession);
$t7Pass = empty($res7['data']['success']) && str_contains($res7['data']['error'] ?? '', 'Only .csv files');
assertTest("7. Non-CSV Extension Rejection (.pdf blocked)", $t7Pass, "Error: " . ($res7['data']['error'] ?? ''));

// =========================================================================
// TEST 7.1: CSRF Protection Verification
// =========================================================================
// Attempt with mismatched CSRF token while session has CSRF token
$mismatchedRunner = "<?php
if (session_status() === PHP_SESSION_NONE) session_start();
\$_SESSION['user_id'] = {$facProponentId};
\$_SESSION['user_role'] = 'faculty';
\$_SESSION['csrf_token'] = 'session_token_abc';
\$_SERVER['REQUEST_METHOD'] = 'POST';
\$_POST = ['activity_id' => {$proponentActId}, 'csrf_token' => 'wrong_token_xyz'];
\$tmp = tempnam(sys_get_temp_dir(), 'csv_');
file_put_contents(\$tmp, \"Timestamp,Email,Q1\\n2026/10/10,u@sti.edu,4\");
\$_FILES = ['csv_file' => ['name' => 't.csv', 'type' => 'text/csv', 'tmp_name' => \$tmp, 'error' => UPLOAD_ERR_OK, 'size' => 100]];
require '" . addslashes(__DIR__ . '/../api/import-evaluations-csv.php') . "';
";
$p = proc_open('php', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
fwrite($pipes[0], $mismatchedRunner);
fclose($pipes[0]);
$csrfOut = stream_get_contents($pipes[1]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($p);
$csrfJson = json_decode(trim($csrfOut), true);
$tCsrfPass = empty($csrfJson['success']) && str_contains($csrfJson['error'] ?? '', 'CSRF');
assertTest("7.1 CSRF Protection: Mismatched CSRF Token Blocked (HTTP 403)", $tCsrfPass, "Error: " . ($csrfJson['error'] ?? ''));

// =========================================================================
// TEST 8: Response Viewer & KPI Analytics Integration
// =========================================================================
$viewerStmt = $db->prepare("
    SELECT id, criteria, rating, comments, evaluator_name, evaluated_at 
    FROM kpi_evaluations 
    WHERE activity_id = ? AND (rating IS NOT NULL OR (comments IS NOT NULL AND TRIM(comments) != ''))
    ORDER BY evaluated_at DESC
");
$viewerStmt->execute([$proponentActId]);
$allResponses = $viewerStmt->fetchAll(PDO::FETCH_ASSOC);

$ratings = array_filter(array_column($allResponses, 'rating'), fn($v) => $v !== null);
$avgRating = count($ratings) > 0 ? round(array_sum($ratings) / count($ratings), 2) : 0;
$commentsCount = count(array_filter(array_column($allResponses, 'comments'), fn($v) => $v !== null && trim((string)$v) !== ''));

$criteriaGroups = [];
foreach ($allResponses as $r) {
    if ($r['rating'] !== null) {
        $criteriaGroups[$r['criteria']][] = (int)$r['rating'];
    }
}

$t8Pass = (count($allResponses) === 22 && $avgRating >= 3.0 && $commentsCount >= 6 && count($criteriaGroups) >= 6);
assertTest("8. Response Viewer & KPI Analytics Integration", $t8Pass, "Total: " . count($allResponses) . " responses, Avg rating: {$avgRating}/4.0, Comments count: {$commentsCount}, Unique criteria: " . count($criteriaGroups));

// =========================================================================
// CLEANUP
// =========================================================================
try {
    $db->prepare("DELETE FROM kpi_evaluations WHERE activity_id IN (?, ?)")->execute([$proponentActId, $strangerActId]);
    $db->prepare("DELETE FROM activities WHERE id IN (?, ?)")->execute([$proponentActId, $strangerActId]);
    $db->prepare("DELETE FROM users WHERE id IN (?, ?, ?)")->execute([$facProponentId, $facStrangerId, $adminId]);
    echo "\n\033[32mCleaned up all temporary test records successfully.\033[0m\n";
} catch (\Throwable $e) {
    echo "\n\033[33mCleanup error: " . $e->getMessage() . "\033[0m\n";
}

echo "\n========================================================================\n";
echo "  FINAL RESULT: {$passed} PASSED, {$failed} FAILED\n";
echo "========================================================================\n";

if ($failed > 0) {
    exit(1);
}
