<?php
/**
 * tests/test_evaluation_csv_ui.php
 *
 * Verification suite for Evaluation CSV Import UI integration on Faculty pages.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "========================================================================\n";
echo "  EVALUATION CSV IMPORT UI INTEGRATION VERIFICATION\n";
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

// 1. Check file contents of faculty/view-activity.php
$viewActivityContent = file_get_contents(__DIR__ . '/../faculty/view-activity.php');

$hasImportBtn = str_contains($viewActivityContent, 'Import Evaluation CSV') && str_contains($viewActivityContent, 'openCsvImportModal()');
assertTest("1. faculty/view-activity.php has 'Import Evaluation CSV' Action Button", $hasImportBtn);

$hasImportModal = str_contains($viewActivityContent, 'id="csvImportModal"') && str_contains($viewActivityContent, 'id="csvImportForm"');
assertTest("2. faculty/view-activity.php has #csvImportModal and #csvImportForm", $hasImportModal);

$hasCsrfAndEndpoint = str_contains($viewActivityContent, 'api/import-evaluations-csv.php') && str_contains($viewActivityContent, 'generateCsrfToken()');
assertTest("3. faculty/view-activity.php sends CSRF token & posts to api/import-evaluations-csv.php", $hasCsrfAndEndpoint);

$hasFormatInfo = str_contains($viewActivityContent, 'Google Forms') && str_contains($viewActivityContent, 'Microsoft Forms');
assertTest("4. faculty/view-activity.php provides clear Google/MS Forms format guidance", $hasFormatInfo);

// 2. Check file contents of faculty/post-event.php
$postEventContent = file_get_contents(__DIR__ . '/../faculty/post-event.php');

$hasPostEventForm = str_contains($postEventContent, 'id="postEventCsvImportForm"') && str_contains($postEventContent, 'submitPostEventCsvImport');
assertTest("5. faculty/post-event.php has Evaluation CSV Import Section & Form", $hasPostEventForm);

$hasPostEventEndpoint = str_contains($postEventContent, 'api/import-evaluations-csv.php') && str_contains($postEventContent, 'generateCsrfToken()');
assertTest("6. faculty/post-event.php connects to api/import-evaluations-csv.php with CSRF protection", $hasPostEventEndpoint);

$hasPostEventFormatInfo = str_contains($postEventContent, 'Google Forms') && str_contains($postEventContent, 'Microsoft Forms');
assertTest("7. faculty/post-event.php identifies supported Google Forms and MS Forms exports", $hasPostEventFormatInfo);

// 3. Isolated Live Activity Test for HTML Rendering
$testSuffix = time() . '_' . mt_rand(100, 999);
$db->beginTransaction();
try {
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES (?, ?, 'pass', 'faculty', 'CCS')");
    $stmt->execute(["Faculty Tester {$testSuffix}", "fac_tester_{$testSuffix}@sti.edu"]);
    $testFacId = (int)$db->lastInsertId();

    $stmt = $db->prepare("
        INSERT INTO activities (faculty_id, title, description, venue, event_date, start_time, end_time, source, status)
        VALUES (?, ?, 'Testing CSV UI Rendering', 'Lab 2', '2026-10-30', '08:00:00', '12:00:00', 'faculty', 'approved')
    ");
    $stmt->execute([$testFacId, "CSV UI Test Activity {$testSuffix}"]);
    $testActId = (int)$db->lastInsertId();

    $db->commit();
} catch (\Throwable $e) {
    $db->rollBack();
    die("Setup error: " . $e->getMessage() . "\n");
}

// Render faculty/view-activity.php?id=X&tab=responses in subprocess
function renderPageSubprocess(string $pagePath, array $getParams, array $userSession): string {
    $getExport = var_export($getParams, true);
    $sessionExport = var_export($userSession, true);
    $escapedPage = addslashes($pagePath);

    $code = "<?php
    if (session_status() === PHP_SESSION_NONE) session_start();
    \$_SESSION['user_id'] = {$sessionExport}['id'];
    \$_SESSION['user_role'] = {$sessionExport}['role'];
    \$_SESSION['user_name'] = {$sessionExport}['name'];
    \$_SESSION['user_email'] = {$sessionExport}['email'];
    \$_SESSION['last_activity'] = time();
    \$_GET = {$getExport};
    \$_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    require '{$escapedPage}';
    echo ob_get_clean();
    ";

    $proc = proc_open('php', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
    fwrite($pipes[0], $code);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return $out;
}

$facSession = [
    'id'    => $testFacId,
    'role'  => 'faculty',
    'name'  => "Faculty Tester {$testSuffix}",
    'email' => "fac_tester_{$testSuffix}@sti.edu"
];

$renderedResponsesTab = renderPageSubprocess(__DIR__ . '/../faculty/view-activity.php', ['id' => $testActId, 'tab' => 'responses'], $facSession);
$renderedPostEvent    = renderPageSubprocess(__DIR__ . '/../faculty/post-event.php', ['activity_id' => $testActId], $facSession);

assertTest("8. view-activity.php?tab=responses renders CSV import button in browser DOM", str_contains($renderedResponsesTab, 'Import Evaluation CSV'));
assertTest("9. view-activity.php?tab=responses renders hidden activity_id ({$testActId}) and CSRF token", str_contains($renderedResponsesTab, "value=\"{$testActId}\"") && str_contains($renderedResponsesTab, 'name="csrf_token"'));
assertTest("10. post-event.php?activity_id={$testActId} renders CSV upload form", str_contains($renderedPostEvent, 'id="postEventCsvImportForm"') && str_contains($renderedPostEvent, "value=\"{$testActId}\""));

// Cleanup
$db->prepare("DELETE FROM activities WHERE id = ?")->execute([$testActId]);
$db->prepare("DELETE FROM users WHERE id = ?")->execute([$testFacId]);

echo "\n========================================================================\n";
echo "  FINAL RESULT: {$passed} PASSED, {$failed} FAILED\n";
echo "========================================================================\n";

if ($failed > 0) {
    exit(1);
}
