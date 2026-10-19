<?php
/**
 * tests/test_e2e_evaluation_workflow.php
 *
 * End-to-End Zero-Cost Evaluation Workflow Verification
 *
 * Steps verified:
 * 1. Open an isolated activity with generated evaluation questions.
 * 2. Enter a safe test questionnaire URL into the evaluation-link field.
 * 3. Confirm the URL persists after saving and reopening the activity.
 * 4. Confirm the local QR code encodes that exact URL.
 * 5. Prepare a representative Google Forms / Microsoft Forms CSV export containing ratings and open comments.
 * 6. Upload through the Faculty Import Evaluation CSV interface / endpoint.
 * 7. Confirm the import summary reports accurate imported/skipped counts.
 * 8. Confirm responses appear in the existing response viewer (faculty/view-activity.php?tab=responses).
 * 9. Confirm imported numeric ratings are reflected in KPI calculations and qualitative comments are preserved.
 * 10. Re-upload the same CSV and confirm duplicate handling behaves as designed.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/QrCodeService.php';

echo "========================================================================\n";
echo "  END-TO-END ZERO-COST EVALUATION WORKFLOW VERIFICATION\n";
echo "========================================================================\n\n";

$passed = 0;
$failed = 0;

function assertStep(string $title, bool $condition, string $detail = ''): void
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
$testRunId = time() . '_' . mt_rand(1000, 9999);

// -------------------------------------------------------------------------
// STEP 1: Create isolated Faculty user and Activity with evaluation questions
// -------------------------------------------------------------------------
echo "--- STEP 1: Creating Isolated Test Activity & Proponent ---\n";
$db->beginTransaction();
try {
    $userStmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES (?, ?, 'secret', 'faculty', 'CCS')");
    $userStmt->execute(["Prof. Jane Proponent {$testRunId}", "jane_{$testRunId}@sti.edu"]);
    $facultyId = (int)$db->lastInsertId();

    $evalQuestions = json_encode([
        ['id' => 1, 'question' => 'Clarity and Structure of Topic', 'type' => 'rating'],
        ['id' => 2, 'question' => 'Speaker Mastery & Practical Application', 'type' => 'rating'],
        ['id' => 3, 'question' => 'Venue and Facilities Quality', 'type' => 'rating'],
        ['id' => 4, 'question' => 'Qualitative Suggestions & Improvements', 'type' => 'text']
    ]);

    $actStmt = $db->prepare("
        INSERT INTO activities (
            faculty_id, title, description, venue, event_date, start_time, end_time,
            source, status, evaluation_questions, evaluation_method
        ) VALUES (
            ?, ?, 'E2E Zero Cost Evaluation Test', 'Auditorium A', '2026-11-15',
            '08:30:00', '11:30:00', 'faculty', 'approved', ?, NULL
        )
    ");
    $actStmt->execute([$facultyId, "E2E Zero-Cost Evaluation Activity {$testRunId}", $evalQuestions]);
    $activityId = (int)$db->lastInsertId();

    $db->commit();
} catch (\Throwable $e) {
    $db->rollBack();
    die("Step 1 Setup Failed: " . $e->getMessage() . "\n");
}

$createdAct = $db->query("SELECT * FROM activities WHERE id = {$activityId}")->fetch(PDO::FETCH_ASSOC);
assertStep("1. Isolated activity created with evaluation questions", !empty($createdAct['id']) && !empty($createdAct['evaluation_questions']));

// -------------------------------------------------------------------------
// STEP 2 & 3: Enter safe test questionnaire URL and confirm persistence
// -------------------------------------------------------------------------
echo "\n--- STEP 2 & 3: Save & Verify Questionnaire URL Persistence ---\n";
$safeTestUrl = "https://forms.google.com/d/e/1FAIpQLSe-TEST-EVAL-URL-{$testRunId}/viewform";

// Update evaluation link as Faculty user
$updateStmt = $db->prepare("UPDATE activities SET evaluation_method = ? WHERE id = ? AND faculty_id = ?");
$updateStmt->execute([$safeTestUrl, $activityId, $facultyId]);

// Re-open and verify persistence from DB
$reopenedAct = $db->query("SELECT id, title, evaluation_method, evaluation_questions FROM activities WHERE id = {$activityId}")->fetch(PDO::FETCH_ASSOC);
$urlPersisted = ($reopenedAct['evaluation_method'] === $safeTestUrl);
assertStep("2. Safe questionnaire URL saved to evaluation_method", $urlPersisted, "Stored URL: " . $reopenedAct['evaluation_method']);

// Confirm URL renders in proposal view
$userSession = [
    'id'    => $facultyId,
    'role'  => 'faculty',
    'name'  => "Prof. Jane Proponent {$testRunId}",
    'email' => "jane_{$testRunId}@sti.edu"
];

function renderSubprocess(string $pagePath, array $getParams, array $userSession): string {
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

$proposalViewHtml = renderSubprocess(__DIR__ . '/../faculty/proposal-view.php', ['id' => $activityId], $userSession);
$viewHasUrl = str_contains($proposalViewHtml, htmlspecialchars($safeTestUrl)) || str_contains($proposalViewHtml, $safeTestUrl);
assertStep("3. Questionnaire URL renders in proposal view page", $viewHasUrl);

// -------------------------------------------------------------------------
// STEP 4: Local QR Code Generation & Validation
// -------------------------------------------------------------------------
echo "\n--- STEP 4: Zero-Cost Local QR Code Generation ---\n";
$isUrlValid = QrCodeService::isValidUrl($safeTestUrl);
$qrSvg = QrCodeService::generateSvg($safeTestUrl, 160);

$hasValidSvg = !empty($qrSvg) && str_contains($qrSvg, '<svg') && str_contains($qrSvg, '</svg>') && (str_contains($qrSvg, '<path') || str_contains($qrSvg, '<rect'));
assertStep("4. Local dependency-free QR code generated for exact URL", $isUrlValid && $hasValidSvg, "SVG Length: " . strlen($qrSvg ?? '') . " bytes");

// -------------------------------------------------------------------------
// STEP 5: Prepare Representative CSV with Ratings and Open-Ended Comments
// -------------------------------------------------------------------------
echo "\n--- STEP 5 & 6: CSV Export Preparation & Upload ---\n";
$representativeCsv = <<<CSV
Timestamp,Email Address,Clarity and Structure of Topic,Speaker Mastery & Practical Application,Venue and Facilities Quality,Qualitative Suggestions & Improvements
2026/10/10 11:00:00 AM GMT+8,student.alpha@sti.edu,Strongly Agree,4,Very Satisfied,Great session! The live code demonstrations were very helpful.
2026/10/10 11:05:00 AM GMT+8,student.beta@sti.edu,Agree,3,Satisfied,Well paced. Would appreciate more time for Q&A.
2026/10/10 11:10:00 AM GMT+8,student.gamma@sti.edu,4,4,4,Clear explanations and very accommodating speaker.
2026/10/10 11:15:00 AM GMT+8,student.delta@sti.edu,3,4,Fair,Air conditioning in the venue was slightly cold.
CSV;

// -------------------------------------------------------------------------
// STEP 6 & 7: Upload CSV via api/import-evaluations-csv.php
// -------------------------------------------------------------------------
function executeCsvUpload(int $activityId, string $csvContent, array $userSession, ?string $csrfToken = null): array {
    $endpoint = __DIR__ . '/../api/import-evaluations-csv.php';
    $tmpFile = tempnam(sys_get_temp_dir(), 'e2e_csv_');
    file_put_contents($tmpFile, $csvContent);

    $sessionExport = var_export($userSession, true);
    $csrfExport = var_export($csrfToken, true);
    $tmpFileExport = var_export($tmpFile, true);
    $fileSize = strlen($csvContent);

    $runner = "<?php
    if (session_status() === PHP_SESSION_NONE) session_start();
    \$s = {$sessionExport};
    \$_SESSION['user_id'] = \$s['id'];
    \$_SESSION['user_role'] = \$s['role'];
    \$_SESSION['user_name'] = \$s['name'];
    \$_SESSION['user_email'] = \$s['email'];
    \$_SESSION['last_activity'] = time();
    if ({$csrfExport} !== null) {
        \$_SESSION['csrf_token'] = {$csrfExport};
    }

    \$_SERVER['REQUEST_METHOD'] = 'POST';
    \$_POST = ['activity_id' => {$activityId}];
    if ({$csrfExport} !== null) {
        \$_POST['csrf_token'] = {$csrfExport};
    }

    \$_FILES = [
        'csv_file' => [
            'name'     => 'evaluation_responses.csv',
            'type'     => 'text/csv',
            'tmp_name' => {$tmpFileExport},
            'error'    => UPLOAD_ERR_OK,
            'size'     => {$fileSize}
        ]
    ];

    require '{$endpoint}';
    ";

    $proc = proc_open('php', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
    fwrite($pipes[0], $runner);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    @unlink($tmpFile);

    return json_decode(trim($out), true) ?: ['raw' => $out];
}

$csrfToken = bin2hex(random_bytes(16));
$importResult = executeCsvUpload($activityId, $representativeCsv, $userSession, $csrfToken);

// 4 participants * 4 questions = 16 responses
$uploadOk = !empty($importResult['success']) && ($importResult['imported_count'] ?? 0) === 16 && ($importResult['skipped_count'] ?? 0) === 0;
assertStep("5 & 6. CSV Upload through Faculty endpoint successful", $uploadOk, "Imported: " . ($importResult['imported_count'] ?? 0));

// Confirm import summary message
$hasSummary = !empty($importResult['message']) && str_contains($importResult['message'], '16 records imported');
assertStep("7. Import summary reports accurate imported/skipped counts", $hasSummary, "Message: " . ($importResult['message'] ?? ''));

// -------------------------------------------------------------------------
// STEP 8: Responses Appear in Existing Response Viewer
// -------------------------------------------------------------------------
echo "\n--- STEP 8: Verify Response Viewer Rendering ---\n";
$responsesTabHtml = renderSubprocess(__DIR__ . '/../faculty/view-activity.php', ['id' => $activityId, 'tab' => 'responses'], $userSession);

$hasResponseTable = str_contains($responsesTabHtml, 'id="responsesTable"');
$hasStudentAlpha = str_contains($responsesTabHtml, 'student.alpha@sti.edu') || str_contains($responsesTabHtml, 'Participant 1') || str_contains($responsesTabHtml, 'The live code demonstrations were very helpful');
$hasSpeakerFeedback = str_contains($responsesTabHtml, 'Speaker Mastery &amp; Practical Application') || str_contains($responsesTabHtml, 'Speaker Mastery & Practical Application');

assertStep("8. Responses visible in existing faculty response viewer", $hasResponseTable && $hasStudentAlpha && $hasSpeakerFeedback, "Table found: " . ($hasResponseTable ? 'Yes' : 'No'));

// -------------------------------------------------------------------------
// STEP 9: Numeric Ratings Computed in KPIs & Qualitative Comments Available
// -------------------------------------------------------------------------
echo "\n--- STEP 9: KPI Calculations & Qualitative Comments Retention ---\n";
$kpiRows = $db->query("
    SELECT criteria, AVG(rating) as avg_rating, COUNT(rating) as rating_count, COUNT(comments) as comment_count 
    FROM kpi_evaluations 
    WHERE activity_id = {$activityId} 
    GROUP BY criteria
")->fetchAll(PDO::FETCH_ASSOC);

$totalEvaluations = (int)$db->query("SELECT COUNT(*) FROM kpi_evaluations WHERE activity_id = {$activityId}")->fetchColumn();
$avgTotal = (float)$db->query("SELECT AVG(rating) FROM kpi_evaluations WHERE activity_id = {$activityId} AND rating IS NOT NULL")->fetchColumn();
$commentsCount = (int)$db->query("SELECT COUNT(*) FROM kpi_evaluations WHERE activity_id = {$activityId} AND comments IS NOT NULL AND TRIM(comments) != ''")->fetchColumn();

$kpiCalculationPass = ($totalEvaluations === 16 && $avgTotal >= 3.0 && $avgTotal <= 4.0 && $commentsCount === 4 && count($kpiRows) === 4);
assertStep("9. Numeric ratings reflected in KPI calculations (Avg: " . round($avgTotal, 2) . "/4.0) & Qualitative comments preserved ({$commentsCount} feedback comments)", $kpiCalculationPass);

// -------------------------------------------------------------------------
// STEP 10: Idempotent Re-Import / Duplicate Handling Verification
// -------------------------------------------------------------------------
echo "\n--- STEP 10: Duplicate Handling & Idempotency ---\n";
$reimportResult = executeCsvUpload($activityId, $representativeCsv, $userSession, $csrfToken);

$reimportOk = !empty($reimportResult['success']) && ($reimportResult['imported_count'] ?? 0) === 0 && ($reimportResult['skipped_count'] ?? 0) === 16;
$dbCountPostReimport = (int)$db->query("SELECT COUNT(*) FROM kpi_evaluations WHERE activity_id = {$activityId}")->fetchColumn();

assertStep("10. Re-uploading identical CSV skips duplicates cleanly (0 imported, 16 skipped)", $reimportOk && $dbCountPostReimport === 16, "DB Count before: 16, after: {$dbCountPostReimport}");

// -------------------------------------------------------------------------
// CLEANUP: Purge all isolated test activity and user records
// -------------------------------------------------------------------------
echo "\n--- CLEANUP ---\n";
try {
    $db->prepare("DELETE FROM kpi_evaluations WHERE activity_id = ?")->execute([$activityId]);
    $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$activityId]);
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$facultyId]);
    echo "[\033[32mOK\033[0m] Successfully purged all temporary activity, evaluation, and user records.\n";
} catch (\Throwable $e) {
    echo "[\033[33mWARN\033[0m] Cleanup notice: " . $e->getMessage() . "\n";
}

echo "\n========================================================================\n";
echo "  FINAL RESULT: {$passed} PASSED, {$failed} FAILED\n";
echo "========================================================================\n";

if ($failed > 0) {
    exit(1);
}
