<?php
/**
 * tests/test_clear_sections_system_modal.php
 * Verification that clearAllParticipantSections uses STI-ARMES consistent STIModal dialog
 * across all accounts with proposal creation/editing:
 * - faculty
 * - program_head
 * - admin1 (Student Org Head)
 * - admin2 (Events Committee Head)
 */

echo "=======================================================\n";
echo " STI-ARMES Consistent System Modal Verification\n";
echo "=======================================================\n\n";

$allPassed = true;

function testAssert($desc, $condition) {
    global $allPassed;
    if ($condition) {
        echo " [PASS] $desc\n";
    } else {
        echo " [FAIL] $desc\n";
        $allPassed = false;
    }
}

$createContent = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');
$editContent = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');
$topbarContent = file_get_contents(__DIR__ . '/../includes/topbar-profile.php');

// 1. Verify window.STIModal is defined in topbar-profile.php
testAssert("topbar-profile.php defines window.STIModal",
    strpos($topbarContent, 'window.STIModal = {') !== false);
testAssert("topbar-profile.php defines STIModal.confirm()",
    strpos($topbarContent, 'confirm: function(message, opts)') !== false);
testAssert("topbar-profile.php defines STIModal.alert()",
    strpos($topbarContent, 'alert: function(message, opts)') !== false);

// 2. Verify proposal-create.php clearAllParticipantSections uses window.STIModal.confirm
testAssert("proposal-create.php clearAllParticipantSections is an async function",
    preg_match('/async\s+function\s+clearAllParticipantSections\s*\(/', $createContent) === 1);
testAssert("proposal-create.php calls window.STIModal.confirm with danger variant & Clear All Sections title",
    strpos($createContent, "title: 'Clear All Sections?'") !== false &&
    strpos($createContent, "variant: 'danger'") !== false &&
    strpos($createContent, "confirmText: 'Yes, Clear All'") !== false);
testAssert("proposal-create.php DOES NOT use raw standalone if (!confirm(...)) in clearAllParticipantSections",
    strpos($createContent, "if (!confirm('Are you sure you want to clear all section rows?')) return;") === false);

// 3. Verify proposal-edit.php clearAllParticipantSections uses window.STIModal.confirm
testAssert("proposal-edit.php clearAllParticipantSections is an async function",
    preg_match('/async\s+function\s+clearAllParticipantSections\s*\(/', $editContent) === 1);
testAssert("proposal-edit.php calls window.STIModal.confirm with danger variant & Clear All Sections title",
    strpos($editContent, "title: 'Clear All Sections?'") !== false &&
    strpos($editContent, "variant: 'danger'") !== false &&
    strpos($editContent, "confirmText: 'Yes, Clear All'") !== false);
testAssert("proposal-edit.php DOES NOT use raw standalone if (!confirm(...)) in clearAllParticipantSections",
    strpos($editContent, "if (!confirm('Are you sure you want to clear all section rows?')) return;") === false);

// 4. Verify all 4 proposal roles use proposal-create.php and proposal-edit.php
testAssert("proposal-create.php is authorized for faculty, program_head, admin1, and admin2",
    strpos($createContent, "requireRole('faculty', 'program_head', 'admin1', 'admin2')") !== false);
testAssert("proposal-edit.php is authorized for faculty, program_head, admin1, and admin2",
    strpos($editContent, "requireRole('faculty', 'program_head', 'admin1', 'admin2')") !== false);

echo "\n-------------------------------------------------------\n";
if ($allPassed) {
    echo " ALL TESTS PASSED! Pop-up notification is completely consistent with STI-ARMES UI.\n";
    exit(0);
} else {
    echo " ONE OR MORE TESTS FAILED.\n";
    exit(1);
}
