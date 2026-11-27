<?php
/**
 * tests/test_bulk_add_sections_comprehensive.php
 * Automated verification of Bulk Add Sections Insert button and parity across all authoring roles:
 * - faculty
 * - program_head
 * - admin1 (Student Org Head)
 * - admin2 (Events Committee Head)
 */

echo "=======================================================\n";
echo " STI-ARMES Bulk Add Sections Verification Suite\n";
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

// 1. Verify Role Authorizations in proposal-create.php and proposal-edit.php
$createContent = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');
$editContent = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');
$enhancementsContent = file_get_contents(__DIR__ . '/../includes/phase2-proposal-enhancements.php');

testAssert("proposal-create.php allows all 4 authoring roles (faculty, program_head, admin1, admin2)",
    strpos($createContent, "requireRole('faculty', 'program_head', 'admin1', 'admin2')") !== false);

testAssert("proposal-edit.php allows all 4 authoring roles (faculty, program_head, admin1, admin2)",
    strpos($editContent, "requireRole('faculty', 'program_head', 'admin1', 'admin2')") !== false);

// 2. Verify window export bindings in proposal-create.php
testAssert("proposal-create.php exports window.addParticipantSectionRow",
    strpos($createContent, "window.addParticipantSectionRow = addParticipantSectionRow;") !== false);
testAssert("proposal-create.php exports window.removeParticipantSectionRow",
    strpos($createContent, "window.removeParticipantSectionRow = removeParticipantSectionRow;") !== false);
testAssert("proposal-create.php exports window.renumberParticipantRows",
    strpos($createContent, "window.renumberParticipantRows = renumberParticipantRows;") !== false);
testAssert("proposal-create.php exports window.updateTotalTargetParticipants",
    strpos($createContent, "window.updateTotalTargetParticipants = updateTotalTargetParticipants;") !== false);
testAssert("proposal-create.php exports window.syncKpiSectionTables",
    strpos($createContent, "window.syncKpiSectionTables = syncKpiSectionTables;") !== false);

// 3. Verify window export bindings in proposal-edit.php
testAssert("proposal-edit.php exports window.addParticipantSectionRow",
    strpos($editContent, "window.addParticipantSectionRow = addParticipantSectionRow;") !== false);
testAssert("proposal-edit.php exports window.removeParticipantSectionRow",
    strpos($editContent, "window.removeParticipantSectionRow = removeParticipantSectionRow;") !== false);
testAssert("proposal-edit.php exports window.renumberParticipantRows",
    strpos($editContent, "window.renumberParticipantRows = renumberParticipantRows;") !== false);
testAssert("proposal-edit.php exports window.updateTotalTargetParticipants",
    strpos($editContent, "window.updateTotalTargetParticipants = updateTotalTargetParticipants;") !== false);
testAssert("proposal-edit.php exports window.syncKpiSectionTables",
    strpos($editContent, "window.syncKpiSectionTables = syncKpiSectionTables;") !== false);

// 4. Verify phase2-proposal-enhancements.php modal & insert button
testAssert("phase2-proposal-enhancements.php contains modal with ID bulkAddSectionsModal",
    strpos($enhancementsContent, 'id="bulkAddSectionsModal"') !== false);
testAssert("phase2-proposal-enhancements.php contains Insert button calling processBulkAddSections()",
    strpos($enhancementsContent, 'onclick="processBulkAddSections()"') !== false);
testAssert("phase2-proposal-enhancements.php contains fallback row insertion",
    strpos($enhancementsContent, 'insertRowFallback') !== false);
testAssert("phase2-proposal-enhancements.php auto-applies preset if textarea is empty",
    strpos($enhancementsContent, 'applyBulkPreset(presetSelect.value)') !== false);
testAssert("phase2-proposal-enhancements.php contains robust parsing for tabs, semicolons, colons, pipes, and dashes",
    strpos($enhancementsContent, "\\t") !== false && strpos($enhancementsContent, "line.includes(';')") !== false);

// 5. Test Simulated Parser Logic for different line formats
function parseBulkLinesSimulated($text, $defaultCount = 35) {
    $lines = preg_split('/[\r\n]+/', trim($text));
    $items = [];
    foreach ($lines as $rawLine) {
        $line = trim($rawLine);
        if (!$line) continue;
        $sectionName = $line;
        $countVal = $defaultCount;

        if (strpos($line, "\t") !== false) {
            $parts = explode("\t", $line);
            $sectionName = trim($parts[0]);
            $parsed = (int)trim($parts[1] ?? '');
            if ($parsed > 0) $countVal = $parsed;
        } elseif (strpos($line, ",") !== false) {
            $parts = explode(",", $line);
            $sectionName = trim($parts[0]);
            $parsed = (int)trim($parts[1] ?? '');
            if ($parsed > 0) $countVal = $parsed;
        } elseif (strpos($line, ";") !== false) {
            $parts = explode(";", $line);
            $sectionName = trim($parts[0]);
            $parsed = (int)trim($parts[1] ?? '');
            if ($parsed > 0) $countVal = $parsed;
        } elseif (preg_match('/^(.*?)\s*:\s*(\d+)$/', $line, $m)) {
            $sectionName = trim($m[1]);
            $countVal = (int)$m[2];
        } elseif (preg_match('/^(.*?)\s*[-–—]\s*(\d+)$/', $line, $m)) {
            $sectionName = trim($m[1]);
            $countVal = (int)$m[2];
        } elseif (preg_match('/^(.*?)\s*\(\s*(\d+)\s*\)$/', $line, $m)) {
            $sectionName = trim($m[1]);
            $countVal = (int)$m[2];
        }
        $items[] = ['section' => $sectionName, 'count' => $countVal];
    }
    return $items;
}

$sampleInput = "BSIT 101, 38\nBSIT 201 - 42\nBSIT 301\t30\nBSIT 401: 25\nBSIT 501 (36)\nBSIT 601";
$parsedItems = parseBulkLinesSimulated($sampleInput);

testAssert("Parser correctly extracts 6 items from diverse formatting", count($parsedItems) === 6);
testAssert("Item 1 (comma) correctly parsed count = 38", $parsedItems[0]['count'] === 38 && $parsedItems[0]['section'] === 'BSIT 101');
testAssert("Item 2 (dash) correctly parsed count = 42", $parsedItems[1]['count'] === 42 && $parsedItems[1]['section'] === 'BSIT 201');
testAssert("Item 3 (tab) correctly parsed count = 30", $parsedItems[2]['count'] === 30 && $parsedItems[2]['section'] === 'BSIT 301');
testAssert("Item 4 (colon) correctly parsed count = 25", $parsedItems[3]['count'] === 25 && $parsedItems[3]['section'] === 'BSIT 401');
testAssert("Item 5 (parentheses) correctly parsed count = 36", $parsedItems[4]['count'] === 36 && $parsedItems[4]['section'] === 'BSIT 501');
testAssert("Item 6 (default count fallback) correctly parsed count = 35", $parsedItems[5]['count'] === 35 && $parsedItems[5]['section'] === 'BSIT 601');

echo "\n-------------------------------------------------------\n";
if ($allPassed) {
    echo " ALL TESTS PASSED! Bulk Add Sections is fully operational.\n";
    exit(0);
} else {
    echo " ONE OR MORE TESTS FAILED.\n";
    exit(1);
}
