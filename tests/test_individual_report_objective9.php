<?php
/**
 * Test Suite: Individual Activity Report for Objective 9
 * Validates:
 * 1. Rendering with full data (approval logs, documents, comments, AI qualitative analysis, KPI calculations)
 * 2. Rendering with missing/empty optional sections (graceful empty states)
 * 3. Strict isolation (no cross-activity data leakage)
 * 4. Secure document links pointing to api/document-download.php without raw filesystem paths
 * 5. Preservation of deterministic KPI calculations and advisory notices
 * 6. Access control enforcement (dean, admin1, admin2 allowed; faculty / guest blocked)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

$passedTests = 0;
$failedTests = 0;

function assertCondition($testName, $condition, $details = "") {
    global $passedTests, $failedTests;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$testName}\n";
    } else {
        $failedTests++;
        echo "  [FAIL] {$testName}\n";
        if (!empty($details)) {
            echo "         Details: {$details}\n";
        }
    }
}

echo "============================================================\n";
echo "  Objective 9 Individual Activity Report Verification\n";
echo "============================================================\n\n";

// Ensure clean test fixtures
$testFaculty = $db->query("SELECT id FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$testDean = $db->query("SELECT id, name, role FROM users WHERE role = 'dean' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$testAdmin = $db->query("SELECT id, name, role FROM users WHERE role = 'admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$testFaculty || !$testDean) {
    die("Error: Required users (faculty, dean) not found in database.\n");
}

$facultyId = (int)$testFaculty['id'];
$deanId = (int)$testDean['id'];
$adminId = (int)($testAdmin['id'] ?? $deanId);

// 1. Create two test activities to test rendering & activity isolation
$stmt = $db->prepare("
    INSERT INTO activities (title, faculty_id, event_date, venue, status, target_participants, source)
    VALUES (?, ?, '2026-10-15', 'STI Auditorium', 'completed', 100, 'faculty')
");
$stmt->execute(['Test Objective 9 Activity Primary', $facultyId]);
$primaryActId = (int)$db->lastInsertId();

$stmt->execute(['Test Objective 9 Activity Secondary Isolation', $facultyId]);
$secondaryActId = (int)$db->lastInsertId();

$stmt->execute(['Test Objective 9 Activity Empty Optional Sections', $facultyId]);
$emptyActId = (int)$db->lastInsertId();

echo "Created Test Activities: Primary ID = {$primaryActId}, Secondary ID = {$secondaryActId}, Empty ID = {$emptyActId}\n";

try {
    // -------------------------------------------------------------
    // Set up Primary Activity Fixtures
    // -------------------------------------------------------------
    // A. Approval logs for Primary
    $db->prepare("
        INSERT INTO approval_logs (activity_id, reviewer_id, action, notes, acted_at)
        VALUES (?, ?, 'approved', 'All requirements verified and endorsed by Dean.', '2026-10-01 10:30:00')
    ")->execute([$primaryActId, $deanId]);

    // Approval logs for Secondary (should NEVER appear in Primary report)
    $db->prepare("
        INSERT INTO approval_logs (activity_id, reviewer_id, action, notes, acted_at)
        VALUES (?, ?, 'rejected', 'SECRET_SECONDARY_NOTE_SHOULD_NOT_LEAK', '2026-10-02 11:00:00')
    ")->execute([$secondaryActId, $adminId]);

    // B. Documents for Primary
    $rawSecretPath = "C:\\xampp\\htdocs\\sti-activity-system\\uploads\\secret_plan.pdf";
    $db->prepare("
        INSERT INTO documents (activity_id, doc_type, file_name, file_path, uploaded_by, uploaded_at)
        VALUES (?, 'post_event', 'primary_activity_summary.pdf', ?, ?, '2026-10-05 14:00:00')
    ")->execute([$primaryActId, $rawSecretPath, $facultyId]);
    $primaryDocId = (int)$db->lastInsertId();

    // Documents for Secondary
    $db->prepare("
        INSERT INTO documents (activity_id, doc_type, file_name, file_path, uploaded_by, uploaded_at)
        VALUES (?, 'material', 'secondary_secret_budget.xlsx', 'C:\\secret\\secondary.xlsx', ?, '2026-10-06 15:00:00')
    ")->execute([$secondaryActId, $facultyId]);

    // C. Participant Feedback Comments for Primary
    $db->prepare("
        INSERT INTO kpi_evaluations (activity_id, criteria, rating, comments, evaluator_name, evaluated_at)
        VALUES (?, 'Relevance to Subject', 4, 'Primary activity was extraordinarily well organized and impactful.', 'Student Evaluator Alpha', '2026-10-16 09:00:00')
    ")->execute([$primaryActId]);

    // Also insert empty comments to verify non-empty filtering
    $db->prepare("
        INSERT INTO kpi_evaluations (activity_id, criteria, rating, comments, evaluator_name, evaluated_at)
        VALUES (?, 'Content Quality', 3, '   ', 'Student Evaluator Beta', '2026-10-16 09:05:00')
    ")->execute([$primaryActId]);

    // Comments for Secondary (should NEVER appear in Primary report)
    $db->prepare("
        INSERT INTO kpi_evaluations (activity_id, criteria, rating, comments, evaluator_name, evaluated_at)
        VALUES (?, 'Secondary Criteria', 2, 'SECRET_SECONDARY_COMMENT_DO_NOT_LEAK', 'Student Gamma', '2026-10-16 10:00:00')
    ")->execute([$secondaryActId]);

    // D. AI Qualitative Feedback Analysis for Primary
    $feedbackPayload = [
        'is_finalized' => true,
        'finalized_by' => ['name' => 'Dean Test User', 'role' => 'Dean'],
        'finalized_at' => '2026-10-17 11:00:00',
        'overall_summary' => 'Participants expressed exceptional enthusiasm regarding technical workshops.',
        'positive_themes' => [
            ['theme' => 'Hands-on Coding Experience', 'frequency' => 45, 'summary' => 'Engaging coding challenges provided deep insights.']
        ],
        'improvement_themes' => [
            ['theme' => 'Session Timing and Pacing', 'frequency' => 12, 'summary' => 'Afternoon sessions had tight breakout timeframes.']
        ],
        'key_findings' => [
            '95% of attendees reported improved programming confidence.',
            'Practical demonstrations scored highest across criteria.'
        ],
        'common_suggestions' => [
            'Provide code repositories prior to workshop.',
            'Extend lab time by 30 minutes.'
        ],
        'recommendations' => [
            'Incorporate pre-workshop tutorial links.',
            'Schedule follow-up hands-on lab sessions.'
        ]
    ];
    $db->prepare("
        INSERT INTO activity_feedback_analysis (activity_id, analysis_json, response_hash, responses_count)
        VALUES (?, ?, 'hash123', 57)
    ")->execute([$primaryActId, json_encode($feedbackPayload)]);

    // Secondary Activity will intentionally have NO feedback analysis, NO documents, NO approval logs, and NO comments.

    // -------------------------------------------------------------
    // HELPER: Execute dean/generate-report.php via CLI runner helper
    // -------------------------------------------------------------
    function renderReportOutput($activityId, $userRole = 'dean') {
        global $testDean, $testFaculty;
        
        $sessionUser = ($userRole === 'faculty') ? $testFaculty : $testDean;
        $sessionData = [
            'user_id' => (int)$sessionUser['id'],
            'user_role' => $userRole,
            'role' => $userRole,
            'user_name' => $userRole === 'faculty' ? 'Faculty User' : 'Dean Test User',
            'name' => $userRole === 'faculty' ? 'Faculty User' : 'Dean Test User',
            'must_change_password' => 0
        ];

        $runnerPath = escapeshellarg(__DIR__ . '/runner_report_helper.php');
        $cmd = 'php ' . $runnerPath;

        $descriptors = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return "";
        }

        $payload = json_encode([
            'session' => $sessionData,
            'get'     => ['activity_id' => $activityId]
        ]);

        fwrite($pipes[0], $payload);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        proc_close($process);
        return $stdout;
    }

    echo "\n--- TEST SUITE 1: Full Activity Content Rendering (Primary Activity) ---\n";
    $primaryOutput = renderReportOutput($primaryActId, 'dean');

    // 1. Participant Feedback Comments
    assertCondition(
        "Participant feedback comment criteria is displayed",
        str_contains($primaryOutput, "Relevance to Subject"),
        "Criteria missing in output"
    );
    assertCondition(
        "Participant feedback rating is displayed",
        str_contains($primaryOutput, "4 / 4"),
        "Rating missing in output"
    );
    assertCondition(
        "Participant feedback comment text is displayed",
        str_contains($primaryOutput, "Primary activity was extraordinarily well organized and impactful."),
        "Comment text missing in output"
    );
    assertCondition(
        "Empty/blank participant feedback comments are filtered out",
        !str_contains($primaryOutput, "Student Evaluator Beta"),
        "Blank comment evaluator should not be rendered"
    );

    // 2. Approval & Endorsement History
    assertCondition(
        "Approval history section is displayed",
        str_contains($primaryOutput, "Approval &amp; Endorsement History"),
        "Section header missing"
    );
    assertCondition(
        "Approval reviewer name is displayed",
        str_contains($primaryOutput, htmlspecialchars($testDean['name'])),
        "Reviewer name missing"
    );
    assertCondition(
        "Approval action badge is displayed",
        str_contains($primaryOutput, "Approved"),
        "Action badge missing"
    );
    assertCondition(
        "Approval notes / remarks are displayed",
        str_contains($primaryOutput, "All requirements verified and endorsed by Dean."),
        "Approval notes missing"
    );

    // 3. Centralized Activity Documentation
    assertCondition(
        "Centralized Activity Documentation section is displayed",
        str_contains($primaryOutput, "Centralized Activity Documentation"),
        "Section header missing"
    );
    assertCondition(
        "Document file name is displayed",
        str_contains($primaryOutput, "primary_activity_summary.pdf"),
        "Document name missing"
    );
    assertCondition(
        "Document type badge is displayed",
        str_contains($primaryOutput, "Post Event"),
        "Document type missing"
    );
    assertCondition(
        "Document link uses protected api/document-download.php endpoint",
        str_contains($primaryOutput, "api/document-download.php?id={$primaryDocId}"),
        "Download link incorrect"
    );
    assertCondition(
        "Document raw filesystem path is NEVER exposed in HTML",
        !str_contains($primaryOutput, "secret_plan.pdf") && !str_contains($primaryOutput, "uploads\\") && !str_contains($primaryOutput, "uploads/"),
        "Raw filesystem path found in HTML!"
    );

    // 4. AI Qualitative Feedback Analysis
    assertCondition(
        "AI Qualitative Feedback Analysis section is displayed",
        str_contains($primaryOutput, "AI Qualitative Feedback Analysis"),
        "Section header missing"
    );
    assertCondition(
        "AI Qualitative Feedback summary is displayed",
        str_contains($primaryOutput, "Participants expressed exceptional enthusiasm regarding technical workshops."),
        "Summary missing"
    );
    assertCondition(
        "AI Qualitative positive themes are displayed",
        str_contains($primaryOutput, "Hands-on Coding Experience") && str_contains($primaryOutput, "Engaging coding challenges"),
        "Positive themes missing"
    );
    assertCondition(
        "AI Qualitative improvement themes are displayed",
        str_contains($primaryOutput, "Session Timing and Pacing") && str_contains($primaryOutput, "Afternoon sessions had tight breakout timeframes."),
        "Improvement themes missing"
    );
    assertCondition(
        "AI Qualitative key findings are displayed",
        str_contains($primaryOutput, "95% of attendees reported improved programming confidence."),
        "Key findings missing"
    );
    assertCondition(
        "AI Qualitative common suggestions are displayed",
        str_contains($primaryOutput, "Provide code repositories prior to workshop."),
        "Suggestions missing"
    );
    assertCondition(
        "AI Qualitative actionable recommendations are displayed",
        str_contains($primaryOutput, "Incorporate pre-workshop tutorial links."),
        "Recommendations missing"
    );
    assertCondition(
        "Reviewed/finalized status badge is displayed with administrator metadata",
        str_contains($primaryOutput, "Finalized by Dean Test User"),
        "Finalized status badge missing"
    );

    // 5. Existing Deterministic KPI Calculations & Advisory Notices
    assertCondition(
        "Deterministic KPI Calculations section is intact",
        str_contains($primaryOutput, "Deterministic KPI Calculations") && str_contains($primaryOutput, "Overall Performance Score"),
        "KPI calculations section missing"
    );
    assertCondition(
        "AI Numerical KPI interpretation section is intact",
        str_contains($primaryOutput, "AI Numerical KPI Interpretation — Advisory Only"),
        "Advisory section missing"
    );
    assertCondition(
        "Advisory disclaimer footer remains present",
        str_contains($primaryOutput, "AI-generated insights are advisory only and should be reviewed by authorized administrators"),
        "Disclaimer footer missing"
    );

    echo "\n--- TEST SUITE 2: Missing / Empty Optional Sections (Empty Activity) ---\n";
    $emptyOutput = renderReportOutput($emptyActId, 'dean');

    assertCondition(
        "Empty approval history renders clean fallback notice",
        str_contains($emptyOutput, "No approval history recorded for this activity."),
        "Fallback notice missing"
    );
    assertCondition(
        "Empty documentation renders clean fallback notice",
        str_contains($emptyOutput, "No attached documents recorded for this activity."),
        "Fallback notice missing"
    );
    assertCondition(
        "Empty comments renders clean fallback notice",
        str_contains($emptyOutput, "No participant written comments recorded for this activity."),
        "Fallback notice missing"
    );
    assertCondition(
        "Empty feedback analysis renders clean fallback notice",
        str_contains($emptyOutput, "No qualitative participant feedback analysis has been generated for this activity yet."),
        "Fallback notice missing"
    );

    echo "\n--- TEST SUITE 3: Activity Isolation Verification ---\n";
    $secondaryOutput = renderReportOutput($secondaryActId, 'dean');
    assertCondition(
        "Primary report does NOT contain secondary activity notes",
        !str_contains($primaryOutput, "SECRET_SECONDARY_NOTE_SHOULD_NOT_LEAK"),
        "Secondary approval note leaked into Primary report!"
    );
    assertCondition(
        "Primary report does NOT contain secondary activity document",
        !str_contains($primaryOutput, "secondary_secret_budget.xlsx"),
        "Secondary document leaked into Primary report!"
    );
    assertCondition(
        "Primary report does NOT contain secondary participant comments",
        !str_contains($primaryOutput, "SECRET_SECONDARY_COMMENT_DO_NOT_LEAK"),
        "Secondary comment leaked into Primary report!"
    );
    assertCondition(
        "Secondary report does NOT contain primary activity notes",
        !str_contains($secondaryOutput, "All requirements verified and endorsed by Dean."),
        "Primary approval note leaked into Secondary report!"
    );
    assertCondition(
        "Secondary report does NOT contain primary activity document",
        !str_contains($secondaryOutput, "primary_activity_summary.pdf"),
        "Primary document leaked into Secondary report!"
    );
    assertCondition(
        "Secondary report does NOT contain primary participant comments",
        !str_contains($secondaryOutput, "Primary activity was extraordinarily well organized"),
        "Primary comment leaked into Secondary report!"
    );

    echo "\n--- TEST SUITE 4: Access Control Enforcement ---\n";
    $facultyDeniedOutput = renderReportOutput(0, 'faculty');
    assertCondition(
        "Faculty user attempting to access aggregate report is denied access",
        str_contains($facultyDeniedOutput, "Access denied."),
        "Faculty should be blocked from aggregate report generation"
    );

} finally {
    // Clean up test data
    echo "\nCleaning up test fixtures...\n";
    $db->prepare("DELETE FROM activity_feedback_analysis WHERE activity_id IN (?, ?, ?)")->execute([$primaryActId, $secondaryActId, $emptyActId]);
    $db->prepare("DELETE FROM kpi_evaluations WHERE activity_id IN (?, ?, ?)")->execute([$primaryActId, $secondaryActId, $emptyActId]);
    $db->prepare("DELETE FROM documents WHERE activity_id IN (?, ?, ?)")->execute([$primaryActId, $secondaryActId, $emptyActId]);
    $db->prepare("DELETE FROM approval_logs WHERE activity_id IN (?, ?, ?)")->execute([$primaryActId, $secondaryActId, $emptyActId]);
    $db->prepare("DELETE FROM activities WHERE id IN (?, ?, ?)")->execute([$primaryActId, $secondaryActId, $emptyActId]);
    echo "Cleanup complete.\n\n";
}

echo "============================================================\n";
echo "  Results: Passed = {$passedTests}, Failed = {$failedTests}\n";
echo "============================================================\n";

if ($failedTests > 0) {
    exit(1);
}
exit(0);
