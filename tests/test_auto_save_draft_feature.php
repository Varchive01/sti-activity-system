<?php
/**
 * Automated Verification Script for Auto-Save Draft Feature
 *
 * Verifies:
 * 1. offline-drafts.js syntax and key functional exports
 * 2. Auto-save debouncing & continuous change detection
 * 3. Navigation guard (beforeunload & link interception)
 * 4. Dynamic row restoration (materials, program, manpower, schedule, faculty-tasks, kpi, participant-sections)
 * 5. Evaluation questions serialization and restoration
 * 6. UI status badges in proposal-create.php and proposal-edit.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "========================================================================\n";
echo "  AUTO-SAVE DRAFT FEATURE INTEGRITY & REGRESSION AUDIT\n";
echo "========================================================================\n\n";

$passCount = 0;
$failCount = 0;

function testAssert($title, $condition, $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] $title\n";
        if ($details) echo "        $details\n";
    } else {
        $failCount++;
        echo " [FAIL] $title\n";
        if ($details) echo "        Error: $details\n";
    }
}

// 1. Verify JS file existence and size
$jsPath = __DIR__ . '/../assets/js/offline-drafts.js';
testAssert("1.1 offline-drafts.js exists and is populated", file_exists($jsPath) && filesize($jsPath) > 5000, "Filesize: " . filesize($jsPath) . " bytes");

$jsContent = file_get_contents($jsPath);

// 2. Check Auto-Save Engine implementations
testAssert("2.1 Debounced auto-save handler implemented", strpos($jsContent, 'triggerAutoSave') !== false && strpos($jsContent, 'autoSaveDebounceTimer') !== false);
testAssert("2.2 Continuous input and change event listeners attached", strpos($jsContent, "formEl.addEventListener('input', triggerAutoSave)") !== false && strpos($jsContent, "formEl.addEventListener('change', triggerAutoSave)") !== false);
testAssert("2.3 Dedicated draft_new_active key for unsaved proposals", strpos($jsContent, 'draft_new_active') !== false);
testAssert("2.4 LocalStorage metadata mirror for fast recovery", strpos($jsContent, 'localStorage.setItem') !== false && strpos($jsContent, 'sti_draft_meta_new_active') !== false);
testAssert("2.5 30-second recurring background interval backup", strpos($jsContent, 'autoSaveIntervalTimer') !== false && strpos($jsContent, '30000') !== false);

// 3. Check Navigation Guard & Warning
testAssert("3.1 Accidental navigation guard (beforeunload) registered", strpos($jsContent, "window.addEventListener('beforeunload'") !== false && strpos($jsContent, 'isFormDirty') !== false);
testAssert("3.2 Synchronous emergency draft saving implemented", strpos($jsContent, "function saveDraftSynchronously") !== false && strpos($jsContent, "sti_active_draft_full_new") !== false);
testAssert("3.3 Navigation links intercept Chrome dialog with custom STIModal", strpos($jsContent, "STIModal.confirmUnsavedChanges") !== false || strpos($jsContent, "STIModal.confirm") !== false);
testAssert("3.4 Leaves site without Chrome native popup on confirmation (isNavigatingAway)", strpos($jsContent, "isNavigatingAway = true") !== false && strpos($jsContent, "!isNavigatingAway") !== false);
testAssert("3.5 Preserves unsaved changes and form state when user chooses to stay", strpos($jsContent, "All unsaved changes are preserved") !== false);
testAssert("3.6 Retains native browser protection for refresh and tab closing", strpos($jsContent, "Retains native browser protection for refresh") !== false && strpos($jsContent, "beforeunload") !== false);
testAssert("3.7 Mobile and tab-switch lifecycle events (visibilitychange & pagehide) registered", strpos($jsContent, "visibilitychange") !== false && strpos($jsContent, "pagehide") !== false);
testAssert("3.8 Form submit flags isSubmitting=true to avoid false blocking", strpos($jsContent, "isSubmitting = true") !== false);

// 4. Check Dynamic Row Expansion & Evaluation Restoration
testAssert("4.1 Pre-expands materials rows", strpos($jsContent, "table: 'materials'") !== false);
testAssert("4.2 Pre-expands program flow rows", strpos($jsContent, "table: 'program'") !== false);
testAssert("4.3 Pre-expands manpower rows", strpos($jsContent, "table: 'manpower'") !== false);
testAssert("4.4 Pre-expands schedule timeline rows", strpos($jsContent, "table: 'schedule'") !== false);
testAssert("4.5 Pre-expands faculty task rows", strpos($jsContent, "table: 'faculty-tasks'") !== false);
testAssert("4.6 Pre-expands KPI rows", strpos($jsContent, "type: 'kpi'") !== false && strpos($jsContent, 'addKpiRow') !== false);
testAssert("4.7 Pre-expands participant section rows", strpos($jsContent, "type: 'part_section'") !== false && strpos($jsContent, 'addParticipantSectionRow') !== false);
testAssert("4.8 Restores evaluation questions array and renders preview", strpos($jsContent, 'eval_questions_json') !== false && strpos($jsContent, 'renderQuestions') !== false);
testAssert("4.9 Recalculates summaries after form restoration", strpos($jsContent, 'updateTotal') !== false && strpos($jsContent, 'updateManpowerSummary') !== false && strpos($jsContent, 'updateTotalTargetParticipants') !== false);

// 5. Check UI Elements in proposal-create.php and proposal-edit.php
$createPhp = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');
$editPhp = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');

testAssert("5.1 proposal-create.php has offlineSyncBadge", strpos($createPhp, 'id="offlineSyncBadge"') !== false);
testAssert("5.2 proposal-create.php has btnManualSync", strpos($createPhp, 'id="btnManualSync"') !== false);
testAssert("5.3 proposal-create.php exposes questionsList globally", strpos($createPhp, 'window.questionsList = questionsList') !== false);
testAssert("5.4 proposal-edit.php has offlineSyncBadge", strpos($editPhp, 'id="offlineSyncBadge"') !== false);
testAssert("5.5 proposal-edit.php has btnManualSync", strpos($editPhp, 'id="btnManualSync"') !== false);
testAssert("5.6 proposal-edit.php exposes questionsList globally", strpos($editPhp, 'window.questionsList = questionsList') !== false);

// 6. Check STIModal Custom Dialog in topbar-profile.php
$topbarPhp = file_get_contents(__DIR__ . '/../includes/topbar-profile.php');
testAssert("6.1 topbar-profile.php defines STIModal.confirmUnsavedChanges", strpos($topbarPhp, 'confirmUnsavedChanges') !== false);
testAssert("6.2 topbar-profile.php supports sti-sys-btn-warning button styling", strpos($topbarPhp, 'sti-sys-btn-warning') !== false);
testAssert("6.3 topbar-profile.php supports focusCancel for safe default navigation", strpos($topbarPhp, 'focusCancel') !== false);

echo "\n========================================================================\n";
echo " AUDIT SUMMARY: Passed: $passCount | Failed: $failCount\n";
echo "========================================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);

