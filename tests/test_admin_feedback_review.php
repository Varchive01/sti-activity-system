<?php
/**
 * Focused Verification Suite for Administrator Review/Edit/Finalization of AI Feedback Analysis
 *
 * Requirements tested:
 * 1. Administrator can view/check analysis.
 * 2. Authorized administrator can edit analysis (summary, positive/improvement themes, suggestions, key findings, recommendations).
 * 3. Finalize action persists the reviewed version.
 * 4. Finalized state is distinguishable from unreviewed AI-generated analysis.
 * 5. Unauthorized user (faculty or unauthenticated) cannot edit or finalize.
 * 6. Participant responses in kpi_evaluations remain completely unchanged.
 * 7. Automatic AI analysis generation does not overwrite reviewed/finalized results.
 * 8. Existing AI analysis generation still works.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "========================================================================\n";
echo "  ADMINISTRATOR FEEDBACK ANALYSIS REVIEW/EDIT/FINALIZATION TEST\n";
echo "========================================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $title, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$title}\n";
        $passed++;
    } else {
        echo " [FAIL] {$title}" . ($detail ? " - {$detail}" : "") . "\n";
        $failed++;
    }
}

$db = getDB();

// Setup test users
$adminUser = $db->query("SELECT id, name, email, role FROM users WHERE role IN ('dean', 'admin1', 'admin2') ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$facultyUser = $db->query("SELECT id, name, email, role FROM users WHERE role='faculty' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$adminUser) {
    $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Test Admin', 'admin_rev@sti.edu', 'hash', 'admin2', 'Academic')")->execute();
    $adminUser = ['id' => (int)$db->lastInsertId(), 'name' => 'Test Admin', 'email' => 'admin_rev@sti.edu', 'role' => 'admin2'];
}
if (!$facultyUser) {
    $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Test Faculty', 'fac_rev@sti.edu', 'hash', 'faculty', 'IT')")->execute();
    $facultyUser = ['id' => (int)$db->lastInsertId(), 'name' => 'Test Faculty', 'email' => 'fac_rev@sti.edu', 'role' => 'faculty'];
}

// Setup test activity with evaluation questions
$stmt = $db->prepare("
    INSERT INTO activities (
        faculty_id, title, description, theme, venue, event_date,
        start_time, end_time, source, status, evaluation_questions
    ) VALUES (
        ?, 'Review Workflow Test Activity', 'Testing Admin Review & Finalize', 'AI Workshop', 'Room 302', '2026-11-25',
        '10:00:00', '15:00:00', 'faculty', 'approved', ?
    )
");
$testQuestions = [
    ['id' => 'q1', 'text' => 'Content relevance', 'type' => 'rating', 'required' => true],
    ['id' => 'q2', 'text' => 'Speaker delivery', 'type' => 'rating', 'required' => true],
    ['id' => 'q3', 'text' => 'Comments and suggestions', 'type' => 'open_ended', 'required' => false]
];
$stmt->execute([$facultyUser['id'], json_encode($testQuestions)]);
$activityId = (int)$db->lastInsertId();

// Insert dummy participant responses in kpi_evaluations
$insertKpi = $db->prepare("INSERT INTO kpi_evaluations (activity_id, criteria, rating, comments, evaluator_name) VALUES (?, ?, ?, ?, ?)");
$insertKpi->execute([$activityId, 'Content relevance', 4, 'Excellent content and interactive delivery.', 'Student A']);
$insertKpi->execute([$activityId, 'Speaker delivery', 3, 'Great presentation, but needed more time for questions.', 'Student B']);
$insertKpi->execute([$activityId, 'Overall feedback', 4, 'Very helpful practical examples. Would attend again.', 'Student C']);

// Take snapshot of kpi_evaluations
$origKpiStmt = $db->prepare("SELECT id, activity_id, criteria, rating, comments, evaluator_name FROM kpi_evaluations WHERE activity_id = ? ORDER BY id ASC");
$origKpiStmt->execute([$activityId]);
$origKpiSnapshot = $origKpiStmt->fetchAll(PDO::FETCH_ASSOC);

// Subprocess runner for api/generate-feedback-analysis.php
function executeFeedbackAnalysis(array $postData, ?array $userSession = null): array
{
    $script = __DIR__ . '/../api/generate-feedback-analysis.php';
    $postExport = var_export($postData, true);
    $sessionExport = var_export($userSession, true);

    $code = "<?php
    if (session_status() === PHP_SESSION_NONE) session_start();
    \$sessionData = {$sessionExport};
    if (\$sessionData !== null) {
        \$_SESSION['user_id'] = \$sessionData['id'] ?? null;
        \$_SESSION['user_role'] = \$sessionData['role'] ?? null;
        \$_SESSION['user_name'] = \$sessionData['name'] ?? 'Test User';
        \$_SESSION['user_email'] = \$sessionData['email'] ?? 'test@sti.edu';
        \$_SESSION['last_activity'] = time();
    } else {
        \$_SESSION = [];
    }

    \$_POST = {$postExport};
    \$_GET = {$postExport};
    \$_SERVER['REQUEST_METHOD'] = 'POST';

    try {
        require '{$script}';
    } catch (Throwable \$e) {
        http_response_code(500);
        echo json_encode(['error' => \$e->getMessage()]);
    }
    ";

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $process = proc_open('php', $descriptors, $pipes);
    if (!is_resource($process)) {
        return ['status' => 500, 'body' => null, 'raw' => 'Proc open failed'];
    }

    fwrite($pipes[0], $code);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    // Extract JSON from output
    $json = null;
    $pos = strpos($stdout, '{');
    if ($pos !== false) {
        $json = json_decode(substr($stdout, $pos), true);
    }

    // Extract HTTP status code from header simulation or JSON
    $status = 200;
    if ($json && isset($json['error'])) {
        if (str_contains(strtolower($json['error']), 'unauthorized') || str_contains(strtolower($json['error']), 'forbidden')) {
            $status = (isset($json['error']) && str_contains(strtolower($json['error']), 'only administrators')) ? 403 : 401;
        } elseif (str_contains(strtolower($json['error']), 'cannot be automatically overwritten')) {
            $status = 409;
        } elseif (str_contains(strtolower($json['error']), 'not found')) {
            $status = 404;
        } else {
            $status = 400;
        }
    }

    return [
        'status' => $status,
        'body' => $json,
        'raw' => $stdout,
        'stderr' => $stderr,
        'exitCode' => $exitCode
    ];
}

try {
    // -------------------------------------------------------------
    // TEST 1: Initial check state
    // -------------------------------------------------------------
    $res1 = executeFeedbackAnalysis(['activity_id' => $activityId, 'action' => 'check'], $adminUser);
    assertTest(
        "Initial check indicates analysis not yet generated",
        isset($res1['body']['status']) && $res1['body']['status'] === 'not_generated',
        "Status was: " . ($res1['body']['status'] ?? 'null')
    );

    // -------------------------------------------------------------
    // TEST 2: Generate initial AI analysis
    // -------------------------------------------------------------
    $res2 = executeFeedbackAnalysis(['activity_id' => $activityId, 'action' => 'analyze'], $adminUser);
    assertTest(
        "AI feedback analysis generates successfully",
        isset($res2['body']['status']) && $res2['body']['status'] === 'success' && isset($res2['body']['analysis']),
        "Response: " . substr($res2['raw'], 0, 150)
    );
    assertTest(
        "Initial generated analysis is unreviewed and unfinalized",
        isset($res2['body']['is_finalized']) && $res2['body']['is_finalized'] === false,
        "is_finalized was: " . var_export($res2['body']['is_finalized'] ?? null, true)
    );

    // -------------------------------------------------------------
    // TEST 3: Administrator can view/check generated analysis
    // -------------------------------------------------------------
    $res3 = executeFeedbackAnalysis(['activity_id' => $activityId, 'action' => 'check'], $adminUser);
    assertTest(
        "Administrator can view generated analysis via check action",
        isset($res3['body']['status']) && $res3['body']['status'] === 'generated' && isset($res3['body']['analysis']),
        "Status was: " . ($res3['body']['status'] ?? 'null')
    );
    assertTest(
        "Check action reports analysis as unfinalized initially",
        isset($res3['body']['is_finalized']) && $res3['body']['is_finalized'] === false,
        "is_finalized was: " . var_export($res3['body']['is_finalized'] ?? null, true)
    );

    // -------------------------------------------------------------
    // TEST 4: Unauthorized user (faculty or guest) cannot edit or finalize
    // -------------------------------------------------------------
    $resFacultyEdit = executeFeedbackAnalysis([
        'activity_id' => $activityId,
        'action' => 'edit',
        'summary' => 'Faculty unauthorized edit attempt'
    ], $facultyUser);
    assertTest(
        "Faculty user is forbidden from editing analysis (HTTP 403)",
        $resFacultyEdit['status'] === 403 || (isset($resFacultyEdit['body']['error']) && str_contains($resFacultyEdit['body']['error'], 'Only administrators')),
        "Status: {$resFacultyEdit['status']}, Error: " . ($resFacultyEdit['body']['error'] ?? '')
    );

    $resFacultyFinalize = executeFeedbackAnalysis([
        'activity_id' => $activityId,
        'action' => 'finalize'
    ], $facultyUser);
    assertTest(
        "Faculty user is forbidden from finalizing analysis (HTTP 403)",
        $resFacultyFinalize['status'] === 403 || (isset($resFacultyFinalize['body']['error']) && str_contains($resFacultyFinalize['body']['error'], 'Only administrators')),
        "Status: {$resFacultyFinalize['status']}, Error: " . ($resFacultyFinalize['body']['error'] ?? '')
    );

    $resGuestEdit = executeFeedbackAnalysis([
        'activity_id' => $activityId,
        'action' => 'edit',
        'summary' => 'Guest attempt'
    ], null);
    assertTest(
        "Unauthenticated guest is rejected (HTTP 401)",
        $resGuestEdit['status'] === 401 || (isset($resGuestEdit['body']['error']) && str_contains($resGuestEdit['body']['error'], 'Unauthorized')),
        "Status: {$resGuestEdit['status']}"
    );

    // -------------------------------------------------------------
    // TEST 5: Authorized administrator can review & edit analysis
    // -------------------------------------------------------------
    $editedSummary = 'Admin Reviewed: Outstanding workshop with active participant engagement.';
    $editedPosThemes = [
        ['theme' => 'Engaging Speaker Delivery', 'summary' => 'Participants appreciated the clear presentation and expertise.', 'frequency' => 3]
    ];
    $editedImpThemes = [
        ['theme' => 'Q&A Time Management', 'summary' => 'More time should be allocated for questions.', 'frequency' => 2]
    ];
    $editedFindings = "Interactive format kept attention\nContent was directly applicable";
    $editedSuggestions = "Provide downloadable workshop slides\nOffer follow-up advanced track";
    $editedRecs = "Allocate minimum 30 minutes for Q&A\nDistribute workshop materials prior to session";

    $resEdit = executeFeedbackAnalysis([
        'activity_id' => $activityId,
        'action' => 'edit',
        'summary' => $editedSummary,
        'positive_themes' => json_encode($editedPosThemes),
        'improvement_themes' => json_encode($editedImpThemes),
        'key_findings' => $editedFindings,
        'common_suggestions' => $editedSuggestions,
        'recommendations' => $editedRecs
    ], $adminUser);

    assertTest(
        "Authorized administrator edit returns success",
        isset($resEdit['body']['status']) && $resEdit['body']['status'] === 'success',
        "Raw: " . substr($resEdit['raw'], 0, 150)
    );
    assertTest(
        "Edit marks analysis as reviewed draft (is_reviewed = true, is_finalized = false)",
        !empty($resEdit['body']['is_reviewed']) && empty($resEdit['body']['is_finalized']),
        "is_reviewed: " . var_export($resEdit['body']['is_reviewed'] ?? null, true)
    );
    assertTest(
        "Edited summary is persisted in response",
        isset($resEdit['body']['analysis']['overall_summary']) && $resEdit['body']['analysis']['overall_summary'] === $editedSummary,
        "Summary: " . ($resEdit['body']['analysis']['overall_summary'] ?? 'null')
    );
    assertTest(
        "Edited key findings and suggestions are properly parsed",
        isset($resEdit['body']['analysis']['key_findings']) && count($resEdit['body']['analysis']['key_findings']) === 2 &&
        isset($resEdit['body']['analysis']['common_suggestions']) && count($resEdit['body']['analysis']['common_suggestions']) === 2,
        "Findings count: " . count($resEdit['body']['analysis']['key_findings'] ?? [])
    );

    // Verify DB persistence of edits
    $dbAnalysisStmt = $db->prepare("SELECT analysis_json FROM activity_feedback_analysis WHERE activity_id = ?");
    $dbAnalysisStmt->execute([$activityId]);
    $dbRow = $dbAnalysisStmt->fetch(PDO::FETCH_ASSOC);
    $dbDecoded = json_decode($dbRow['analysis_json'], true) ?: [];

    assertTest(
        "Database persists reviewed state and administrator info",
        !empty($dbDecoded['is_reviewed']) && isset($dbDecoded['reviewed_by']['name']),
        "DB reviewed_by: " . var_export($dbDecoded['reviewed_by'] ?? null, true)
    );

    // -------------------------------------------------------------
    // TEST 6: Automatic AI analysis regeneration is prevented when edited/reviewed (Req 13)
    // -------------------------------------------------------------
    $resBlockedRegen = executeFeedbackAnalysis([
        'activity_id' => $activityId,
        'action' => 'analyze'
    ], $adminUser);
    assertTest(
        "Automatic AI regeneration blocked when analysis is reviewed/edited (HTTP 409 Conflict)",
        $resBlockedRegen['status'] === 409 || (isset($resBlockedRegen['body']['error']) && str_contains($resBlockedRegen['body']['error'], 'cannot be automatically overwritten')),
        "Status: {$resBlockedRegen['status']}, Error: " . ($resBlockedRegen['body']['error'] ?? '')
    );

    // -------------------------------------------------------------
    // TEST 7: Authorized administrator can finalize analysis
    // -------------------------------------------------------------
    $resFinalize = executeFeedbackAnalysis([
        'activity_id' => $activityId,
        'action' => 'finalize'
    ], $adminUser);

    assertTest(
        "Finalize action returns success",
        isset($resFinalize['body']['status']) && $resFinalize['body']['status'] === 'success',
        "Status: " . ($resFinalize['body']['status'] ?? 'null')
    );
    assertTest(
        "Finalized response reports is_finalized = true",
        !empty($resFinalize['body']['is_finalized']),
        "is_finalized was: " . var_export($resFinalize['body']['is_finalized'] ?? null, true)
    );
    assertTest(
        "Finalized response contains finalized_by with admin role and name",
        isset($resFinalize['body']['finalized_by']['name']) && isset($resFinalize['body']['finalized_by']['role']),
        "finalized_by: " . var_export($resFinalize['body']['finalized_by'] ?? null, true)
    );
    assertTest(
        "Finalized timestamp is present",
        !empty($resFinalize['body']['finalized_at']),
        "finalized_at: " . var_export($resFinalize['body']['finalized_at'] ?? null, true)
    );

    // -------------------------------------------------------------
    // TEST 8: Finalized state is distinguishable from unreviewed AI analysis
    // -------------------------------------------------------------
    $resCheckFinal = executeFeedbackAnalysis(['activity_id' => $activityId, 'action' => 'check'], $adminUser);
    assertTest(
        "Check action reports distinguishable finalized status",
        isset($resCheckFinal['body']['is_finalized']) && $resCheckFinal['body']['is_finalized'] === true &&
        !empty($resCheckFinal['body']['finalized_by']) && !empty($resCheckFinal['body']['finalized_at']),
        "is_finalized: " . var_export($resCheckFinal['body']['is_finalized'] ?? null, true)
    );

    // Finalized analysis also blocks unforced regeneration
    $resBlockedFinalRegen = executeFeedbackAnalysis([
        'activity_id' => $activityId,
        'action' => 'analyze'
    ], $adminUser);
    assertTest(
        "Finalized analysis cannot be overwritten by automatic analyze call",
        $resBlockedFinalRegen['status'] === 409 || (isset($resBlockedFinalRegen['body']['error']) && str_contains($resBlockedFinalRegen['body']['error'], 'cannot be automatically overwritten')),
        "Status: {$resBlockedFinalRegen['status']}"
    );

    // -------------------------------------------------------------
    // TEST 9: Participant responses in kpi_evaluations remain completely unchanged
    // -------------------------------------------------------------
    $afterKpiStmt = $db->prepare("SELECT id, activity_id, criteria, rating, comments, evaluator_name FROM kpi_evaluations WHERE activity_id = ? ORDER BY id ASC");
    $afterKpiStmt->execute([$activityId]);
    $afterKpiSnapshot = $afterKpiStmt->fetchAll(PDO::FETCH_ASSOC);

    assertTest(
        "Participant responses count remains identical",
        count($origKpiSnapshot) === count($afterKpiSnapshot),
        "Before: " . count($origKpiSnapshot) . ", After: " . count($afterKpiSnapshot)
    );
    assertTest(
        "Participant responses data, ratings, and comments remain completely untouched",
        serialize($origKpiSnapshot) === serialize($afterKpiSnapshot),
        "Original snapshot differs from post-review snapshot!"
    );

    // -------------------------------------------------------------
    // TEST 10: Explicit forced regeneration still works (Preserve AI logic)
    // -------------------------------------------------------------
    $resForced = executeFeedbackAnalysis([
        'activity_id' => $activityId,
        'action' => 'analyze',
        'force' => 1
    ], $adminUser);
    assertTest(
        "Explicit forced regeneration works when force=1 is specified",
        isset($resForced['body']['status']) && $resForced['body']['status'] === 'success',
        "Status: " . ($resForced['body']['status'] ?? 'null')
    );

} finally {
    // Cleanup test data
    $db->prepare("DELETE FROM activity_feedback_analysis WHERE activity_id = ?")->execute([$activityId]);
    $db->prepare("DELETE FROM kpi_evaluations WHERE activity_id = ?")->execute([$activityId]);
    $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$activityId]);
}

echo "\n========================================================================\n";
echo "  SUMMARY: {$passed} PASSED, {$failed} FAILED\n";
echo "========================================================================\n";

exit($failed > 0 ? 1 : 0);
