<?php
/**
 * tests/test_phase3_capstone_revisions.php
 *
 * Automated Verification Test Suite for Phase 3 Capstone Defense Revisions:
 * 1. Global Self-Healing Database Columns (post_event, faculty_tasks, activities.evaluation_stakeholder)
 * 2. Committee Head Task Completion Verification & Personnel Performance Measurement
 * 3. Equipment Return & Condition Status Report (Returned Properly, Damaged, Needs Replacement)
 * 4. Expense Breakdown vs. Budget Utilization Module (Under Budget, Fully Utilized, Over Budget)
 * 5. Event Photos (Minimum 5 Photos Instruction & Counter) + Cloud Drive Media Link
 * 6. Stakeholder-Specific Evaluation Tools (Students, Faculty, Speakers/Judges, Committee/Peers, Multi-Stakeholder)
 * 7. AI-Assisted Auto-Generated Assessment Report (Narrative + Auto-Generated PDF) & Improvement Recommendations
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/PostEventAssessmentHelper.php';

$db = getDB();
ensureOfficialDefenseAccounts($db);

$passed = 0;
$failed = 0;

function assertPhase3(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) {
        echo "  [PASS] {$label}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$label}\n";
        $failed++;
    }
}

echo "=== PHASE 3 CAPSTONE REVISIONS VERIFICATION ===\n\n";

// 1. Verify Self-Healing Database Schema Columns
$peCols = $db->query("SHOW COLUMNS FROM post_event")->fetchAll(PDO::FETCH_COLUMN);
$expectedPeCols = [
    'actual_expenses', 'approved_budget', 'budget_variance', 'budget_status',
    'expense_breakdown_path', 'equipment_report_json', 'media_drive_link',
    'comments_summary', 'ai_recommendations', 'ai_assessment_summary'
];
foreach ($expectedPeCols as $c) {
    assertPhase3(in_array($c, $peCols, true), "post_event table has self-healed column '{$c}'");
}

$ftCols = $db->query("SHOW COLUMNS FROM faculty_tasks")->fetchAll(PDO::FETCH_COLUMN);
$expectedFtCols = [
    'verified_by_head', 'verified_at', 'verification_status', 'performance_rating', 'performance_remarks'
];
foreach ($expectedFtCols as $c) {
    assertPhase3(in_array($c, $ftCols, true), "faculty_tasks table has self-healed column '{$c}'");
}

$actCols = $db->query("SHOW COLUMNS FROM activities")->fetchAll(PDO::FETCH_COLUMN);
assertPhase3(in_array('evaluation_stakeholder', $actCols, true), "activities table has self-healed column 'evaluation_stakeholder'");

// 2. Verify Budget Utilization Calculations (Under Budget, Fully Utilized, Over Budget)
$evalUnder = PostEventAssessmentHelper::evaluateBudgetStatus(15000.00, 12500.00);
$evalExact = PostEventAssessmentHelper::evaluateBudgetStatus(15000.00, 15000.00);
$evalOver  = PostEventAssessmentHelper::evaluateBudgetStatus(15000.00, 18250.00);

assertPhase3($evalUnder['status'] === 'under_budget' && abs($evalUnder['variance'] - (-2500.00)) < 0.01, "Budget calculator detects Under Budget (-2,500.00 variance)");
assertPhase3($evalExact['status'] === 'fully_utilized' && abs($evalExact['variance']) < 0.01, "Budget calculator detects Fully Utilized (0.00 variance)");
assertPhase3($evalOver['status'] === 'over_budget' && abs($evalOver['variance'] - 3250.00) < 0.01, "Budget calculator detects Over Budget (+3,250.00 variance)");

// 3. Verify AI-Assisted Assessment & Recommendations Synthesis + Auto-Generated PDF Report
$sampleActId = (int)$db->query("SELECT id FROM activities ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($sampleActId > 0) {
    $synth = PostEventAssessmentHelper::generateAssessmentAndRecommendations($db, $sampleActId, [
        'target_attendance'  => 100,
        'actual_attendance'  => 96,
        'satisfaction_score' => 4.8,
        'approved_budget'    => 10000.00,
        'actual_expenses'    => 9200.00,
        'equipment_items'    => [
            ['item_name' => 'PA Sound System', 'quantity' => 1, 'status' => 'returned_properly', 'remarks' => 'Returned in good order'],
            ['item_name' => 'HDMI Cable', 'quantity' => 2, 'status' => 'damaged', 'remarks' => 'Connector bent']
        ]
    ]);

    assertPhase3(!empty($synth['comments_summary']) && !empty($synth['ai_recommendations']) && !empty($synth['ai_assessment_summary']),
        "PostEventAssessmentHelper synthesizes comments summary, AI recommendations, and AI assessment narrative");

    $pdfRelPath = PostEventAssessmentHelper::autoGenerateAssessmentPdfFile($db, $sampleActId, $synth, 387);
    $fullPdfPath = (defined('UPLOAD_PATH') ? UPLOAD_PATH : (__DIR__ . '/../uploads/')) . $pdfRelPath;
    assertPhase3(!empty($pdfRelPath) && file_exists($fullPdfPath) && filesize($fullPdfPath) > 500,
        "Auto-generated AI-assisted PDF Assessment Report created on disk ({$pdfRelPath})");
}

// 4. Verify UI & API Integration Markers across Target Files
$postEventPhp = file_get_contents(__DIR__ . '/../faculty/post-event.php');
assertPhase3(
    str_contains($postEventPhp, 'expenseBreakdownSection') &&
    str_contains($postEventPhp, 'equipmentReportSection') &&
    str_contains($postEventPhp, 'personnelPerformanceSection') &&
    str_contains($postEventPhp, 'photoRequirementBadge') &&
    str_contains($postEventPhp, 'media_drive_link') &&
    str_contains($postEventPhp, 'aiAssessmentAndRecommendationsSection'),
    "faculty/post-event.php contains all 6 Phase 3 post-event modules & instructions"
);

$phase2Enh = file_get_contents(__DIR__ . '/../includes/phase2-proposal-enhancements.php');
$genEvalPhp = file_get_contents(__DIR__ . '/../api/generate-evaluation.php');
assertPhase3(
    str_contains($phase2Enh, 'eval_stakeholder_type_select') &&
    str_contains($genEvalPhp, 'stakeholder_type') &&
    str_contains($genEvalPhp, 'speakers_judges'),
    "Stakeholder-specific evaluation tools wired in proposal wizard and api/generate-evaluation.php"
);

$taskApiPhp = file_get_contents(__DIR__ . '/../api/task-assignment.php');
$p3DetailsPhp = file_get_contents(__DIR__ . '/../includes/phase3-post-event-details.php');
assertPhase3(
    str_contains($taskApiPhp, "action === 'verify_task'") &&
    str_contains($p3DetailsPhp, 'openPhase3TaskVerifyModal'),
    "Committee head task verification & personnel performance measurement wired in api/task-assignment.php and shared view template"
);

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
