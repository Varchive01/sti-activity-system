<?php
/**
 * tests/test_issue13_remove_duplicate_export_pdf.php
 *
 * Verification suite for Issue 13:
 * Remove the duplicate Export PDF button on the View Proposal page.
 *
 * Verifies:
 * 1. Exactly one Export PDF button exists in faculty/proposal-view.php.
 * 2. The remaining Export PDF button is retained in the topbar header.
 * 3. The duplicate button in the card header beside the proposal title is removed.
 * 4. All other topbar header actions (Back button, Edit button, Delete Draft, Notifications, Profile) are preserved.
 * 5. exportProposalPdf() javascript function is present, intact, and properly wired.
 */

$passed = 0;
$failed = 0;

function assertCondition(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) {
        echo "  [PASS] {$msg}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$msg}\n";
        $failed++;
    }
}

echo "=== Issue 13: Remove Duplicate Export PDF Button Verification ===\n\n";

$viewFile = __DIR__ . '/../faculty/proposal-view.php';
assertCondition(file_exists($viewFile), "faculty/proposal-view.php exists");

$content = file_get_contents($viewFile);

// ── TEST 1: Count of Export PDF Buttons in HTML ──────────────────────────
echo "1. Checking Export PDF Button Count...\n";

// Count occurrences of "Export PDF" in the file
preg_match_all('/Export\s+PDF/i', $content, $matches);
$matchCount = count($matches[0]);
// In our file, there's the button text and maybe in comments or js function
echo "  [INFO] Total text occurrences of 'Export PDF': {$matchCount}\n";

// Count actual <button ... Export PDF ... </button>
preg_match_all('/<button[^>]*>[\s\S]*?Export\s+PDF[\s\S]*?<\/button>/i', $content, $buttonMatches);
$buttonCount = count($buttonMatches[0]);
assertCondition($buttonCount === 1, "Exactly 1 Export PDF button exists in HTML (found {$buttonCount})");

// ── TEST 2: Location of Retained Button ──────────────────────────────────
echo "\n2. Verifying Retained Button Location in Page Header...\n";

$topbarStart = strpos($content, '<header class="topbar">');
$topbarEnd   = strpos($content, '</header>');
assertCondition($topbarStart !== false && $topbarEnd !== false, "Topbar header found in template");

$topbarContent = substr($content, $topbarStart, $topbarEnd - $topbarStart);
$hasTopBarExportBtn = str_contains($topbarContent, 'id="exportPdfBtn"') && str_contains($topbarContent, 'exportProposalPdf()');
assertCondition($hasTopBarExportBtn, "Export PDF button is present in the topbar header with id='exportPdfBtn'");

// ── TEST 3: Duplicate Button Removed from Card Header ───────────────────
echo "\n3. Verifying Duplicate Button Removed from Card Header Beside Title...\n";

// Find main activity card header
$cardHeaderPos = strpos($content, '<div class="card-header">');
assertCondition($cardHeaderPos !== false, "Main activity card-header found");

$cardBodyPos = strpos($content, '<div class="card-body">', $cardHeaderPos);
$cardHeaderContent = substr($content, $cardHeaderPos, $cardBodyPos - $cardHeaderPos);

$hasCardHeaderExportBtn = str_contains($cardHeaderContent, 'Export PDF') || str_contains($cardHeaderContent, 'exportProposalPdf');
assertCondition(!$hasCardHeaderExportBtn, "No Export PDF button exists inside card-header beside the activity title");

// ── TEST 4: Preservation of Other Header Actions ────────────────────────
echo "\n4. Verifying Other Header Actions Are Intact...\n";

assertCondition(str_contains($topbarContent, '← Back'), "Topbar retains '← Back' navigation link");
assertCondition(str_contains($topbarContent, 'Edit Proposal'), "Topbar retains 'Edit Proposal' action");
assertCondition(str_contains($topbarContent, 'confirmDeleteDraft'), "Topbar retains 'Delete Draft' action");
assertCondition(str_contains($topbarContent, 'notification-topbar-widget.php'), "Topbar retains notifications widget");
assertCondition(str_contains($topbarContent, 'topbar-profile.php'), "Topbar retains user profile control");

// ── TEST 5: PDF Generator Script Functionality ──────────────────────────
echo "\n5. Verifying exportProposalPdf() Javascript Implementation...\n";

assertCondition(str_contains($content, 'function exportProposalPdf()'), "exportProposalPdf() function is defined");
assertCondition(str_contains($content, 'PROPOSAL_PDF_DATA'), "PROPOSAL_PDF_DATA is exported for PDF generation");
assertCondition(str_contains($content, 'doc.save('), "PDF download/save trigger is intact in script");

// ── SUMMARY ─────────────────────────────────────────────────────────────
echo "\n=== TEST RESULTS SUMMARY ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";

if ($failed === 0) {
    echo ">>> ALL ISSUE 13 CHECKS PASSED! <<<\n";
    exit(0);
} else {
    echo ">>> SOME CHECKS FAILED! <<<\n";
    exit(1);
}

