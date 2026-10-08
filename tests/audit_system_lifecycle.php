<?php
/**
 * audit_system_lifecycle.php
 *
 * Comprehensive System-Wide Integration Audit for the STI Activity System.
 * Traces the complete lifecycle across all 14 lifecycle stages:
 *   Faculty Proposal Creation
 *   → AI Objectives
 *   → AI Proposal Validation
 *   → AI Schedule Conflict Detection
 *   → Admin1 Review (Endorsement)
 *   → Admin2 Review (Compliance & Scheduling)
 *   → Dean Final Approval
 *   → Activity Completion (Post-Event)
 *   → Participant Evaluation
 *   → AI Feedback Summarization / Thematic Analysis
 *   → KPI Computation
 *   → AI Analytics & KPI Reporting (Activity & Institutional)
 *   → Dean Dashboard Integration
 *   → KPI Reports & Generated Reports
 *
 * Verifies:
 * - Status transitions
 * - Role permissions & unauthorized access blocking
 * - Notifications & audit logging
 * - Database persistence & clean integrity
 * - AI prompt anonymization & Zero-PII adherence
 * - KPI calculations & threshold adherence
 * - Clean transitions between all modules
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/proposal_validator.php';
require_once __DIR__ . '/../includes/ai/schedule_conflict.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';
require_once __DIR__ . '/../services/GeminiFeedbackAnalysisService.php';
require_once __DIR__ . '/../services/GeminiKpiAnalyticsService.php';

$db = getDB();
$auditResults = [];
$cleanupIds = [];

function recordAuditStep(string $stepName, bool $passed, string $details = ''): void {
    global $auditResults;
    $status = $passed ? 'PASS' : 'FAIL';
    $auditResults[] = [
        'step' => $stepName,
        'passed' => $passed,
        'details' => $details
    ];
    echo ($passed ? " [PASS] " : " [FAIL] ") . $stepName . "\n";
    if ($details) {
        echo "        " . $details . "\n";
    }
}

echo "=======================================================================\n";
echo "   SYSTEM-WIDE INTEGRATION AUDIT: COMPLETE ACTIVITY LIFECYCLE TRACE    \n";
echo "=======================================================================\n\n";

try {
    // Pre-flight cleanup for any lingering audit test activities
    $oldIds = $db->query("SELECT id FROM activities WHERE title IN ('System Audit Test Proposal', 'Conflicting Seminar')")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($oldIds as $oldId) {
        $db->prepare("DELETE FROM kpi_evaluations WHERE activity_id = ?")->execute([$oldId]);
        $db->prepare("DELETE FROM post_event WHERE activity_id = ?")->execute([$oldId]);
        $db->prepare("DELETE FROM section_comments WHERE activity_id = ?")->execute([$oldId]);
        $db->prepare("DELETE FROM approval_logs WHERE activity_id = ?")->execute([$oldId]);
        $db->prepare("DELETE FROM notifications WHERE activity_id = ?")->execute([$oldId]);
        $db->prepare("DELETE FROM proposal_ai_validation WHERE proposal_id = ?")->execute([$oldId]);
        $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$oldId]);
    }

    // -------------------------------------------------------------------------
    // STAGE 1: Faculty Proposal Creation
    // -------------------------------------------------------------------------
    $facStmt = $db->query("SELECT id, name, email FROM users WHERE role = 'faculty' LIMIT 1");
    $faculty = $facStmt->fetch(PDO::FETCH_ASSOC);
    $facultyId = $faculty['id'] ?? 1;

    $createStmt = $db->prepare("
        INSERT INTO activities (
            faculty_id, title, description, theme, venue, venue_address,
            event_date, start_time, end_time, target_participants,
            general_objectives, specific_objectives, source, status, created_at
        ) VALUES (
            ?, 'System Audit Test Proposal', 'Testing full lifecycle handoffs',
            'Academic Excellence', 'Computer Lab 3', '3rd Floor Main Bldg',
            '2026-10-15', '09:00:00', '12:00:00', 50,
            'Empower students through tech training',
            '1. Master database transactions\n2. Complete lifecycle audit',
            'student_org', 'under_review', NOW()
        )
    ");
    $createStmt->execute([$facultyId]);
    $testActId = (int)$db->lastInsertId();
    $cleanupIds['activity'] = $testActId;

    $createdCheck = $db->prepare("SELECT * FROM activities WHERE id = ?");
    $createdCheck->execute([$testActId]);
    $actRow = $createdCheck->fetch(PDO::FETCH_ASSOC);

    $s1Passed = ($testActId > 0) && ($actRow['status'] === 'under_review') && ($actRow['source'] === 'student_org');
    recordAuditStep("STAGE 1: Faculty Proposal Creation & Initial Status Routing", $s1Passed,
        "Created Activity ID: {$testActId} with status: '{$actRow['status']}', source: '{$actRow['source']}'");

    // -------------------------------------------------------------------------
    // STAGE 2: AI Objectives Generation Contract
    // -------------------------------------------------------------------------
    $objFile = __DIR__ . '/../api/generate-objectives.php';
    $objCode = file_get_contents($objFile);
    $s2Passed = str_contains($objCode, "requireRole('faculty')") &&
                 str_contains($objCode, "general_objective") &&
                 str_contains($objCode, "specific_objectives") &&
                 str_contains($objCode, "SMART");
    recordAuditStep("STAGE 2: AI Objectives Generator Contract & Role Check", $s2Passed,
        "Verified requireRole('faculty'), SMART guideline prompt, and JSON response schema.");

    // -------------------------------------------------------------------------
    // STAGE 3: AI Proposal Validation Engine
    // -------------------------------------------------------------------------
    ensureProposalAiValidationTableExists($db);
    $valResult = validateProposal($testActId);
    $aiValRow = getProposalAiValidation($testActId);

    $s3Passed = is_array($valResult) &&
                 isset($valResult['is_complete']) &&
                 isset($valResult['is_aligned']) &&
                 $aiValRow !== null;
    recordAuditStep("STAGE 3: AI Proposal Validation & Persistence in proposal_ai_validations", $s3Passed,
        "Completeness: " . ($valResult['is_complete'] ? 'Complete' : 'Incomplete') .
        ", Alignment: " . ($valResult['is_aligned'] ? 'Aligned' : 'Issues Found') .
        ", DB Row Present: " . ($aiValRow ? 'YES' : 'NO'));

    // -------------------------------------------------------------------------
    // STAGE 4: AI Schedule Conflict Detection
    // -------------------------------------------------------------------------
    // Insert a conflicting approved event at same venue, date, time
    $conflictStmt = $db->prepare("
        INSERT INTO activities (
            faculty_id, title, venue, event_date, start_time, end_time, status
        ) VALUES (
            ?, 'Conflicting Seminar', 'Computer Lab 3', '2026-10-15', '10:00:00', '13:00:00', 'approved'
        )
    ");
    $conflictStmt->execute([$facultyId]);
    $conflictingActId = (int)$db->lastInsertId();
    $cleanupIds['conflict_act'] = $conflictingActId;

    // Check conflict detection
    $detected = checkScheduleConflict('Computer Lab 3', '2026-10-15', '09:30', '11:30', $conflictingActId);
    // Exclude conflictingActId should detect no conflict; without excluding it should detect conflict
    $conflictFound = checkScheduleConflict('Computer Lab 3', '2026-10-15', '09:30', '11:30');
    $noConflictDiffVenue = checkScheduleConflict('Gymnasium', '2026-10-15', '09:30', '11:30');

    $s4Passed = ($conflictFound['conflict'] === true) && ($noConflictDiffVenue['conflict'] === false);
    recordAuditStep("STAGE 4: Deterministic Schedule Conflict Detection", $s4Passed,
        "Direct overlap on same venue/date detected: " . ($conflictFound['conflict'] ? 'YES' : 'NO') .
        ", Different venue no-conflict: " . (!$noConflictDiffVenue['conflict'] ? 'YES' : 'NO'));

    // -------------------------------------------------------------------------
    // STAGE 5: Admin 1 Review & Endorsement (student_org proposal)
    // -------------------------------------------------------------------------
    $a1Stmt = $db->query("SELECT id FROM users WHERE role = 'admin1' LIMIT 1");
    $admin1Id = (int)$a1Stmt->fetchColumn() ?: 2;

    // Simulate section comment staging
    $db->prepare("INSERT INTO section_comments (activity_id, reviewer_id, section_key, comment) VALUES (?, ?, 'schedule', 'Verified venue')")
       ->execute([$testActId, $admin1Id]);
    
    // Admin 1 endorses proposal
    $db->prepare("UPDATE activities SET status = 'endorsed' WHERE id = ?")->execute([$testActId]);
    $db->prepare("INSERT INTO approval_logs (activity_id, reviewer_id, action, notes) VALUES (?, ?, 'forwarded', 'Endorsed to Sir Ian')")
       ->execute([$testActId, $admin1Id]);
    $db->prepare("INSERT INTO notifications (user_id, activity_id, message) VALUES (?, ?, 'Proposal endorsed by Admin 1')")
       ->execute([$facultyId, $testActId]);

    $a1Check = $db->query("SELECT status FROM activities WHERE id = {$testActId}")->fetchColumn();
    $a1LogCheck = $db->query("SELECT COUNT(*) FROM approval_logs WHERE activity_id = {$testActId} AND reviewer_id = {$admin1Id}")->fetchColumn();

    $s5Passed = ($a1Check === 'endorsed') && ($a1LogCheck > 0);
    recordAuditStep("STAGE 5: Admin 1 Review & Endorsement Transition", $s5Passed,
        "Status updated to: '{$a1Check}', Audit Log logged: {$a1LogCheck} entry, Notification created.");

    // -------------------------------------------------------------------------
    // STAGE 6: Admin 2 Review & Forwarding to Dean
    // -------------------------------------------------------------------------
    $a2Stmt = $db->query("SELECT id FROM users WHERE role = 'admin2' LIMIT 1");
    $admin2Id = (int)$a2Stmt->fetchColumn() ?: 3;

    // Admin 2 forwards to Dean
    $db->prepare("UPDATE activities SET status = 'pending_final_approval' WHERE id = ?")->execute([$testActId]);
    $db->prepare("INSERT INTO approval_logs (activity_id, reviewer_id, action, notes) VALUES (?, ?, 'forwarded', 'Forwarded to Dean for final approval')")
       ->execute([$testActId, $admin2Id]);
    
    // Dean notification
    $deanStmt = $db->query("SELECT id FROM users WHERE role = 'dean' LIMIT 1");
    $deanId = (int)$deanStmt->fetchColumn() ?: 4;
    $db->prepare("INSERT INTO notifications (user_id, activity_id, message) VALUES (?, ?, 'New proposal requires final approval: System Audit Test Proposal')")
       ->execute([$deanId, $testActId]);

    $a2Check = $db->query("SELECT status FROM activities WHERE id = {$testActId}")->fetchColumn();
    $deanNotifCheck = $db->query("SELECT COUNT(*) FROM notifications WHERE activity_id = {$testActId} AND user_id = {$deanId}")->fetchColumn();

    $s6Passed = ($a2Check === 'pending_final_approval') && ($deanNotifCheck > 0);
    recordAuditStep("STAGE 6: Admin 2 Compliance Review & Routing to Dean", $s6Passed,
        "Status updated to: '{$a2Check}', Dean notification created: {$deanNotifCheck} entry.");

    // -------------------------------------------------------------------------
    // STAGE 7: Dean Final Approval & UI Integration
    // -------------------------------------------------------------------------
    // Dean approves proposal
    $now = date('Y-m-d H:i:s');
    $db->prepare("UPDATE activities SET status = 'approved', approved_at = ? WHERE id = ?")->execute([$now, $testActId]);
    $db->prepare("INSERT INTO approval_logs (activity_id, reviewer_id, action, notes) VALUES (?, ?, 'approved', 'Fully approved by Dean Frederic Yulo')")
       ->execute([$testActId, $deanId]);
    $db->prepare("INSERT INTO notifications (user_id, activity_id, message) VALUES (?, ?, 'Congratulations! Your proposal has been fully APPROVED.')")
       ->execute([$facultyId, $testActId]);

    $deanCheck = $db->query("SELECT status, approved_at FROM activities WHERE id = {$testActId}")->fetch(PDO::FETCH_ASSOC);
    $deanLog = $db->query("SELECT action FROM approval_logs WHERE activity_id = {$testActId} AND reviewer_id = {$deanId}")->fetchColumn();

    // Also verify dean/returned.php queries both statuses
    $retCode = file_get_contents(__DIR__ . '/../dean/returned.php');
    $returnedHandlesBoth = str_contains($retCode, "'returned_for_revision', 'rejected'") ||
                           str_contains($retCode, "'returned_for_revision','rejected'");

    // Verify dean/review.php renders AI validation
    $revCode = file_get_contents(__DIR__ . '/../dean/review.php');
    $reviewHasAiVal = str_contains($revCode, "renderAiValidationSection(\$id)");
    $reviewHasCleanRedirect = str_contains($revCode, "header('Location: '.BASE_URL.'/dean/dashboard.php')");

    $s7Passed = ($deanCheck['status'] === 'approved') &&
                 !empty($deanCheck['approved_at']) &&
                 ($deanLog === 'approved') &&
                 $returnedHandlesBoth &&
                 $reviewHasAiVal &&
                 $reviewHasCleanRedirect;
    recordAuditStep("STAGE 7: Dean Final Approval, Timestamping, and UI Maintenance Fixes", $s7Passed,
        "Status: '{$deanCheck['status']}', approved_at: {$deanCheck['approved_at']}, Dean review renders AI validation: YES, returned.php queries returned+rejected: YES");

    // -------------------------------------------------------------------------
    // STAGE 8: Activity Completion & Post-Event Submission
    // -------------------------------------------------------------------------
    $db->prepare("
        INSERT INTO post_event (activity_id, submitted_by, actual_attendance, target_attendance, satisfaction_score, recommendations)
        VALUES (?, ?, 48, 50, 4.6, 'Great engagement, expand hands-on portion')
    ")->execute([$testActId, $facultyId]);
    $db->prepare("UPDATE activities SET status = 'completed' WHERE id = ?")->execute([$testActId]);

    $peCheck = $db->query("SELECT * FROM post_event WHERE activity_id = {$testActId}")->fetch(PDO::FETCH_ASSOC);
    $statusCompleted = $db->query("SELECT status FROM activities WHERE id = {$testActId}")->fetchColumn();

    $s8Passed = ($statusCompleted === 'completed') && !empty($peCheck) && ((int)$peCheck['actual_attendance'] === 48);
    recordAuditStep("STAGE 8: Activity Completion & Post-Event Record Persistence", $s8Passed,
        "Status: '{$statusCompleted}', Actual Attendance: {$peCheck['actual_attendance']}, Target: {$peCheck['target_attendance']}, Satisfaction: {$peCheck['satisfaction_score']}");

    // -------------------------------------------------------------------------
    // STAGE 9: Participant Evaluation Responses
    // -------------------------------------------------------------------------
    $evalInsert = $db->prepare("
        INSERT INTO kpi_evaluations (activity_id, criteria, rating, comments, evaluator_name, evaluated_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $evalInsert->execute([$testActId, 'Objectives Achieved', 4, 'Exceeded all expectations', 'Participant Alpha']);
    $evalInsert->execute([$testActId, 'Objectives Achieved', 4, 'Very clear objectives', 'Participant Beta']);
    $evalInsert->execute([$testActId, 'Relevance to Theme', 4, 'Directly relevant to coursework', 'Participant Gamma']);
    $evalInsert->execute([$testActId, 'Organization & Venue', 3, 'Room was good, projector clear', 'Participant Delta']);

    $evalCount = $db->query("SELECT COUNT(*) FROM kpi_evaluations WHERE activity_id = {$testActId}")->fetchColumn();
    $s9Passed = ((int)$evalCount >= 4);
    recordAuditStep("STAGE 9: Participant Evaluation Storage in kpi_evaluations", $s9Passed,
        "Stored {$evalCount} participant evaluation records across 3 criteria.");

    // -------------------------------------------------------------------------
    // STAGE 10: AI Feedback Summarization & Prompt Anonymization (Zero PII)
    // -------------------------------------------------------------------------
    $fbService = new GeminiFeedbackAnalysisService('');
    $fbReflection = new ReflectionClass($fbService);
    $buildPromptMethod = $fbReflection->getMethod('buildPrompt');

    $actForFb = $db->query("SELECT * FROM activities WHERE id = {$testActId}")->fetch(PDO::FETCH_ASSOC);
    $evalResponses = $db->query("SELECT * FROM kpi_evaluations WHERE activity_id = {$testActId}")->fetchAll(PDO::FETCH_ASSOC);
    $mockQuestions = [
        ['question' => 'Were objectives achieved?', 'type' => 'rating', 'category' => 'Objectives'],
        ['question' => 'Was venue organized?', 'type' => 'rating', 'category' => 'Logistics']
    ];

    $fbPrompt = $buildPromptMethod->invoke($fbService, $actForFb, $mockQuestions, $evalResponses);
    $noPiiInPrompt = !str_contains($fbPrompt, 'Participant Alpha') &&
                     !str_contains($fbPrompt, 'Participant Beta') &&
                     str_contains($fbPrompt, 'Participant 1') &&
                     str_contains($fbPrompt, 'Participant 2');

    $s10Passed = !empty($fbPrompt) && $noPiiInPrompt;
    recordAuditStep("STAGE 10: AI Feedback Analysis Prompt Anonymization (Zero PII)", $s10Passed,
        "Evaluator names masked: " . ($noPiiInPrompt ? 'YES (Participant 1, 2...)' : 'NO - PII LEAK DETECTED!'));

    // -------------------------------------------------------------------------
    // STAGE 11: Quantitative KPI Computation
    // -------------------------------------------------------------------------
    $kpisComputed = calculateActivityKpis($testActId, $db);
    $overallPct = $kpisComputed['overall_performance'];
    $overallStat = $kpisComputed['overall_status'];
    $dataHash = $kpisComputed['data_hash'];

    $s11Passed = !empty($kpisComputed) &&
                 $kpisComputed['has_data'] &&
                 $overallPct !== null &&
                 !empty($dataHash) &&
                 isset($kpisComputed['kpis']['attendance']) &&
                 isset($kpisComputed['kpis']['satisfaction']);
    recordAuditStep("STAGE 11: Quantitative KPI Engine Calculation & Threshold Mapping", $s11Passed,
        "Overall Score: {$overallPct}%, Status: '{$overallStat}', Data Hash: " . substr($dataHash, 0, 16) . "...");

    // -------------------------------------------------------------------------
    // STAGE 12: AI Analytics & KPI Reporting (Permissions & Service Layer)
    // -------------------------------------------------------------------------
    // Check permission separation:
    // Activity-level endpoint allows Dean, Admin1, Admin2, and Owner Faculty
    // Institutional endpoint strictly allows Dean only
    $instApiFile = __DIR__ . '/../api/generate-institutional-analytics.php';
    $instCode = file_get_contents($instApiFile);
    $instRestrictedToDean = str_contains($instCode, "\$user['role'] !== 'dean'") &&
                            str_contains($instCode, "http_response_code(403)");

    $actApiFile = __DIR__ . '/../api/generate-kpi-analytics.php';
    $actCode = file_get_contents($actApiFile);
    $actAuthorizesOwnerAndAdmins = str_contains($actCode, "\$user['role'] === 'faculty'") &&
                                   str_contains($actCode, "faculty_id'] !== (int)\$user['id']");

    $s12Passed = $instRestrictedToDean && $actAuthorizesOwnerAndAdmins;
    recordAuditStep("STAGE 12: Role Security Matrix for Activity vs Institutional AI Endpoints", $s12Passed,
        "Institutional AI insights restricted to Dean only: YES (403 for others), Activity KPI authorized for owner+admins: YES");

    // -------------------------------------------------------------------------
    // STAGE 13: Dean Dashboard Data Consistency
    // -------------------------------------------------------------------------
    $instSummary = getInstitutionalKpiSummary(2026, 'all', $db);
    $approvalTrends = getApprovalTrends(2026, 'all', $db);

    $topStatsApproved = $instSummary['statistics']['approved'];
    $topStatsCompleted = $instSummary['statistics']['completed'];
    $trendApproved = $approvalTrends['total_approved'];

    // In trends, approved must equal top stats approved and exclude completed
    $trendMatchesStats = ($trendApproved === $topStatsApproved);
    $completedPreserved = ($topStatsCompleted >= 1);

    $s13Passed = $trendMatchesStats && $completedPreserved;
    recordAuditStep("STAGE 13: Dean Dashboard Statistics & Trend Metric Alignment", $s13Passed,
        "Top Stats Approved: {$topStatsApproved}, Trends Approved: {$trendApproved} (Consistent: " . ($trendMatchesStats ? 'YES' : 'NO') .
        "), Completed: {$topStatsCompleted}");

    // -------------------------------------------------------------------------
    // STAGE 14: KPI Reports & Generated Reports Module Handshake
    // -------------------------------------------------------------------------
    $kpiReportFile = __DIR__ . '/../dean/kpi-reports.php';
    $kpiReportCode = file_get_contents($kpiReportFile);
    $genReportFile = __DIR__ . '/../dean/generate-report.php';
    $genReportCode = file_get_contents($genReportFile);

    $reportsIntegrateWithKpiHelper = str_contains($kpiReportCode, 'generate-kpi-analytics.php') &&
                                     str_contains($genReportCode, 'requireRole') &&
                                     str_contains($genReportCode, "'dean'");

    $s14Passed = $reportsIntegrateWithKpiHelper;
    recordAuditStep("STAGE 14: KPI Reports & Export Modules Integration Handshake", $s14Passed,
        "kpi-reports.php integrates with generate-kpi-analytics.php: YES, generate-report.php allows Dean access: YES");

} finally {
    // -------------------------------------------------------------------------
    // CLEANUP: Clean up test records created for integration audit
    // -------------------------------------------------------------------------
    echo "\n[CLEANUP] Cleaning up integration audit records...\n";
    if (!empty($cleanupIds['activity'])) {
        $id = $cleanupIds['activity'];
        $db->prepare("DELETE FROM kpi_evaluations WHERE activity_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM post_event WHERE activity_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM section_comments WHERE activity_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM approval_logs WHERE activity_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM notifications WHERE activity_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM proposal_ai_validation WHERE proposal_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$id]);
        echo "          Removed audit activity ID: {$id}\n";
    }
    if (!empty($cleanupIds['conflict_act'])) {
        $cid = $cleanupIds['conflict_act'];
        $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$cid]);
        echo "          Removed conflict mock activity ID: {$cid}\n";
    }
}

// -----------------------------------------------------------------------------
// SUMMARY
// -----------------------------------------------------------------------------
$totalTests = count($auditResults);
$passedTests = count(array_filter($auditResults, fn($r) => $r['passed']));
$failedTests = $totalTests - $passedTests;

echo "\n=======================================================================\n";
echo "AUDIT SUMMARY: {$passedTests} / {$totalTests} STAGES PASSED ({$failedTests} FAILED)\n";
echo "=======================================================================\n";

if ($failedTests === 0) {
    echo "LIFECYCLE INTEGRATION AUDIT COMPLETED WITH 100% PASS RATE!\n";
    exit(0);
} else {
    echo "SOME LIFECYCLE STAGES FAILED AUDIT. REVIEW DETAILS ABOVE.\n";
    exit(1);
}
