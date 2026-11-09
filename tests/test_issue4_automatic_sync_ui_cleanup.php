<?php
/**
 * Test: Issue 4 Automatic Synchronization & Widget Removal Verification
 */
$act    = file_get_contents(__DIR__ . '/../faculty/activities.php');
$dash   = file_get_contents(__DIR__ . '/../faculty/dashboard.php');
$create = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');
$edit   = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');
$js     = file_get_contents(__DIR__ . '/../assets/js/offline-drafts.js');

$checks = [
    'No localDraftsContainer in activities.php' => !str_contains($act, 'localDraftsContainer'),
    'No localDraftsContainer in dashboard.php'  => !str_contains($dash, 'localDraftsContainer'),
    'No btnManualSync in proposal-create.php'   => !str_contains($create, 'btnManualSync'),
    'No btnManualSync in proposal-edit.php'     => !str_contains($edit, 'btnManualSync'),
    'No manual Sync All button in offline-drafts.js' => !str_contains($js, 'btnSyncAllLocalDrafts'),
    'No Local Offline Proposal Drafts card banner in offline-drafts.js' => !str_contains($js, 'Local Offline Proposal Drafts'),
    'hasMeaningfulDraftData implemented' => str_contains($js, 'hasMeaningfulDraftData'),
    'Automatic server sync debounce implemented' => str_contains($js, 'autoSyncDebounceTimer'),
    'Automatic sync on DOM ready implemented' => str_contains($js, 'await syncAllPendingDrafts()'),
    'Automatic step change sync implemented' => str_contains($js, 'wizard-step-btn')
];

$allPassed = true;
echo "=== Issue 4: Automatic Sync & UI Cleanup Verification ===\n\n";
foreach ($checks as $name => $passed) {
    if ($passed) {
        echo "  [PASS] {$name}\n";
    } else {
        echo "  [FAIL] {$name}\n";
        $allPassed = false;
    }
}

echo "\nResult: " . ($allPassed ? "ALL CHECKS PASSED!\n" : "SOME CHECKS FAILED!\n");
exit($allPassed ? 0 : 1);

