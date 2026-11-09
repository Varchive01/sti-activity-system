<?php
/**
 * Test Suite: Manpower Dark Mode Styling & Evaluation Form Link Reversion
 *
 * Verifies:
 * 1. Manpower Summary Table uses theme variables (var(--bg-card), var(--text-main), var(--border))
 *    rather than hardcoded light styles (#ffffff, #1e293b, etc.) across both proposal-create.php and proposal-edit.php.
 * 2. Step 9 Evaluation Form Link restores the previous Google Forms layout with no MS Forms buttons.
 */

echo "=== Running Manpower Dark Mode & Evaluation Link Verification Suite ===\n\n";

$passCount = 0;
$failCount = 0;

function runTest(string $desc, bool $condition, string $detail = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$desc}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$desc}" . ($detail ? " - {$detail}" : '') . "\n";
        $failCount++;
    }
}

$createContent = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');
$editContent = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');

// 1. Manpower summary in proposal-create.php
runTest(
    "proposal-create.php manpower table footer uses var(--bg-card) and var(--text-main)",
    strpos($createContent, '<tr style="border-top: 1px solid var(--border); background: var(--bg-card); font-weight: 600;">') !== false &&
    strpos($createContent, 'id="mp-sum-faculty" style="padding: 10px 14px; font-size: 0.82rem; color: var(--text-main); text-align: center; background: var(--bg-card);"') !== false
);

runTest(
    "proposal-create.php dynamic summary rows use var(--bg-card) and var(--text-main)",
    strpos($createContent, 'tr.style.background = \'var(--bg-card)\';') !== false &&
    strpos($createContent, 'color: var(--text-main); background: var(--bg-card);') !== false
);

// 2. Manpower summary in proposal-edit.php
runTest(
    "proposal-edit.php manpower table footer uses var(--bg-card) and var(--text-main)",
    strpos($editContent, '<tr style="border-top: 1px solid var(--border); background: var(--bg-card); font-weight: 600;">') !== false &&
    strpos($editContent, 'id="mp-sum-faculty" style="padding: 10px 14px; font-size: 0.82rem; color: var(--text-main); text-align: center; background: var(--bg-card);"') !== false
);

runTest(
    "proposal-edit.php dynamic summary rows use var(--bg-card) and var(--text-main)",
    strpos($editContent, 'tr.style.background = \'var(--bg-card)\';') !== false &&
    strpos($editContent, 'color: var(--text-main); background: var(--bg-card);') !== false
);

// 3. Evaluation Form Link section reverted to original style without MS Forms
runTest(
    "proposal-create.php restores Google Forms Evaluation Link label and style",
    strpos($createContent, '<label class="form-label" style="font-weight: 600;">Google Forms Evaluation Link <span class="text-danger">*</span></label>') !== false &&
    strpos($createContent, 'placeholder="https://forms.gle/..."') !== false &&
    strpos($createContent, '<span id="btnCreateGoogleFormText">Create Google Form</span>') !== false &&
    strpos($createContent, 'Copy Questions for MS Forms') === false &&
    strpos($createContent, 'Open Microsoft Forms') === false
);

runTest(
    "proposal-edit.php restores Google Forms Evaluation Link label and style",
    strpos($editContent, '<label class="form-label" style="font-weight: 600;">Google Forms Evaluation Link <span class="text-danger">*</span></label>') !== false &&
    strpos($editContent, 'placeholder="https://forms.gle/..."') !== false &&
    strpos($editContent, '<span id="btnCreateGoogleFormText">Create Google Form</span>') !== false &&
    strpos($editContent, 'Copy Questions for MS Forms') === false &&
    strpos($editContent, 'Open Microsoft Forms') === false
);

echo "\nSummary: {$passCount} Passed, {$failCount} Failed\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);

