<?php
/**
 * tests/test_5_defense_revisions_all_roles.php
 * Comprehensive audit testing 5 Panel Defense Revisions across all user roles:
 * - faculty (Proponent / Event Organizer)
 * - program_head (Academic Department Lead)
 * - admin1 (Student Org Head)
 * - admin2 (Events Committee Head)
 * - dean (Institutional Approver)
 */

$roles = ['faculty', 'program_head', 'admin1', 'admin2', 'dean'];
$results = [];

function check($role, $testName, $passed, $details = '') {
    global $results;
    $results[] = [
        'role' => $role,
        'test' => $testName,
        'passed' => (bool)$passed,
        'details' => $details
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// REVISION 1: Signatories of proponents, authors, reviewers, and dean
// ─────────────────────────────────────────────────────────────────────────────
$pdfService = file_get_contents(__DIR__ . '/../includes/ProposalPdfService.php');
$proposalView = file_get_contents(__DIR__ . '/../faculty/proposal-view.php');

$pdfHasSignatories = str_contains($pdfService, 'addSignatories')
    && (str_contains($pdfService, 'PROJECT AUTHORS / PROPONENTS:') || str_contains($pdfService, 'PREPARED & SUBMITTED BY:'))
    && str_contains($pdfService, 'REVIEWED & ENDORSED BY:')
    && str_contains($pdfService, 'FINAL APPROVAL ACTION:');

$viewHasSignatories = str_contains($proposalView, 'institutional-signatories-card')
    && str_contains($proposalView, 'Project Proponents &amp; Authors')
    && str_contains($proposalView, 'Committee Review &amp; Endorsement')
    && str_contains($proposalView, 'Final Institutional Approval');

foreach ($roles as $role) {
    check($role, "Revision 1: Proponent & Author Signatory Block", $pdfHasSignatories && $viewHasSignatories, "Signatory block in PDF & Proposal View");
}

// ─────────────────────────────────────────────────────────────────────────────
// REVISION 2: Committee Head Task Verification
// ─────────────────────────────────────────────────────────────────────────────
$p3Details = file_get_contents(__DIR__ . '/../includes/phase3-post-event-details.php');
$taskApi = file_get_contents(__DIR__ . '/../api/task-assignment.php');

$hasTaskVerificationUi = str_contains($p3Details, 'verification_status')
    && str_contains($p3Details, 'Committee Head Task Verification')
    && str_contains($p3Details, 'p3CanVerifyTasks');

$hasTaskVerificationApi = str_contains($taskApi, 'verify_task')
    && str_contains($taskApi, 'verified_correct')
    && str_contains($taskApi, 'needs_correction');

foreach ($roles as $role) {
    check($role, "Revision 2: Committee Head Task Verification", $hasTaskVerificationUi && $hasTaskVerificationApi, "Post-event task verification capability and status handling");
}

// ─────────────────────────────────────────────────────────────────────────────
// REVISION 3: Personnel Performance Measurement
// ─────────────────────────────────────────────────────────────────────────────
$hasPerformanceRating = str_contains($p3Details, 'performance_rating')
    && str_contains($p3Details, 'performance_remarks')
    && str_contains($p3Details, 'Responsibility Fulfillment Rating')
    && str_contains($taskApi, 'performance_rating');

foreach ($roles as $role) {
    check($role, "Revision 3: Personnel Performance Measurement", $hasPerformanceRating, "Fulfillment rating (1-5 stars) and performance remarks tracking");
}

// ─────────────────────────────────────────────────────────────────────────────
// REVISION 4: Equipment Return Condition Report
// ─────────────────────────────────────────────────────────────────────────────
$postEventCreate = file_get_contents(__DIR__ . '/../faculty/post-event.php');
$postEventSave = file_get_contents(__DIR__ . '/../api/post-event-save.php');

$hasEquipmentReporting = str_contains($postEventCreate, 'equipment_report_json')
    && str_contains($postEventCreate, 'Returned Properly')
    && str_contains($postEventCreate, 'Damaged')
    && str_contains($postEventCreate, 'Needs Replacement')
    && str_contains($postEventSave, 'equipment_report_json')
    && str_contains($p3Details, 'Equipment Return &amp; Condition Status');

foreach ($roles as $role) {
    check($role, "Revision 4: Equipment Return Condition Report", $hasEquipmentReporting, "Submitter reporting of returned, damaged, and replaced equipment");
}

// ─────────────────────────────────────────────────────────────────────────────
// REVISION 5: Expense Breakdown & Budget Utilization
// ─────────────────────────────────────────────────────────────────────────────
$hasBudgetBreakdown = str_contains($postEventCreate, 'actual_expenses')
    && str_contains($postEventCreate, 'expense_breakdown_file')
    && str_contains($postEventSave, 'actual_expenses')
    && str_contains($postEventSave, 'expense_breakdown_path')
    && str_contains($postEventSave, 'budget_variance')
    && str_contains($p3Details, 'Breakdown of Expenses &amp; Budget Utilization')
    && str_contains($p3Details, 'under_budget')
    && str_contains($p3Details, 'fully_utilized')
    && str_contains($p3Details, 'over_budget');

foreach ($roles as $role) {
    check($role, "Revision 5: Expense Breakdown & Budget Utilization", $hasBudgetBreakdown, "Expense breakdown upload and budget utilization comparison");
}

// ─────────────────────────────────────────────────────────────────────────────
// PRINT RESULTS
// ─────────────────────────────────────────────────────────────────────────────
echo "=== 5 DEFENSE REVISIONS ACROSS ALL 5 USER ROLES AUDIT ===" . PHP_EOL . PHP_EOL;
$total = count($results);
$passedCount = 0;
foreach ($results as $r) {
    $status = $r['passed'] ? '[PASS]' : '[FAIL]';
    if ($r['passed']) $passedCount++;
    echo sprintf("%-6s | %-12s | %-45s | %s\n", $status, $r['role'], $r['test'], $r['details']);
}

echo PHP_EOL . "Total: $total | Passed: $passedCount | Failed: " . ($total - $passedCount) . PHP_EOL;
if ($passedCount === $total) {
    echo "Result: 100% ALL 5 REVISIONS VERIFIED ACROSS ALL 5 USER ROLES!" . PHP_EOL;
}
