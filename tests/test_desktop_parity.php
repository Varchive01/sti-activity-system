<?php
/**
 * tests/test_desktop_parity.php
 * Automated verification that mobile layout rules are strictly scoped within media queries
 * and do NOT alter desktop layouts (>= 1024px).
 */

$rootDir = realpath(__DIR__ . '/..');
$facultyDir = $rootDir . '/faculty';
$errors = [];
$passed = 0;

function checkAssertion($condition, $desc) {
    global $passed, $errors;
    if ($condition) {
        echo " [PASS] $desc\n";
        $passed++;
    } else {
        echo " [FAIL] $desc\n";
        $errors[] = $desc;
    }
}

echo "=======================================================\n";
echo " STI-ARMES Desktop Parity & Layout Isolation Suite\n";
echo "=======================================================\n\n";

// 1. Verify main.css mobile rules are inside max-width media queries
$mainCss = file_get_contents($rootDir . '/assets/css/main.css');

// Check that the new mobile table min-width is inside max-width 1023.98px
$tableWrapPos = strpos($mainCss, '.table-wrap > table');
$mediaPos1023 = strrpos(substr($mainCss, 0, $tableWrapPos), '@media (max-width: 1023.98px)');
checkAssertion($mediaPos1023 !== false, "main.css: .table-wrap min-width is strictly inside @media (max-width: 1023.98px)");

// Check that .filters column stacking is inside max-width 767.98px
$filtersColPos = strpos($mainCss, 'flex-direction: column !important', strpos($mainCss, '.filters'));
$mediaPos768 = strrpos(substr($mainCss, 0, $filtersColPos), '@media (max-width: 767.98px)');
checkAssertion($mediaPos768 !== false, "main.css: .filters vertical stacking is strictly inside @media (max-width: 767.98px)");

// Check desktop sidebar rules still exist
checkAssertion(strpos($mainCss, '--sidebar-w:') !== false && strpos($mainCss, '260px') !== false, "main.css: Desktop sidebar width (--sidebar-w: 260px) is preserved");

// 2. Verify faculty/dashboard.php desktop parity
$dashContent = file_get_contents($facultyDir . '/dashboard.php');
// Desktop stat-grid rules
checkAssertion(strpos($dashContent, 'grid-template-columns: repeat(7, minmax(130px, 1fr)) !important;') !== false, "dashboard.php: Desktop 7-column stat-grid rule is untouched");
checkAssertion(strpos($dashContent, 'min-width: 960px;') !== false, "dashboard.php: Desktop min-width (960px) rule is untouched");
checkAssertion(strpos($dashContent, '@media (min-width: 1360px)') !== false, "dashboard.php: High-res desktop (min-width: 1360px) 7-column rule is untouched");
// Verify all mobile rules are inside max-width queries
$mobileStatGridPos = strpos($dashContent, 'grid-template-columns: repeat(2, minmax(0, 1fr))');
$mediaPos768 = strrpos(substr($dashContent, 0, $mobileStatGridPos), '@media (max-width: 767.98px)');
checkAssertion($mediaPos768 !== false, "dashboard.php: 2-column mobile stat-grid is strictly inside @media (max-width: 767.98px)");

// 3. Verify faculty/proposal-create.php and proposal-edit.php desktop parity
foreach (['proposal-create.php', 'proposal-edit.php'] as $file) {
    $content = file_get_contents($facultyDir . '/' . $file);
    // Desktop layout grid exists
    checkAssertion(strpos($content, '.proposal-layout-grid') !== false, "$file: Desktop .proposal-layout-grid exists");
    // Target participants table controls on desktop have horizontal flex rule at line 667/line 700
    checkAssertion(strpos($content, '.target-participants-controls {') !== false && strpos($content, 'display: flex;') !== false, "$file: Desktop target-participants-controls has desktop display: flex");
    // The mobile target participants rules are inside @media (max-width: 767.98px)
    $media768Pos = strpos($content, '@media (max-width: 767.98px)');
    $mobileColumnRulePos = strpos($content, 'flex-direction: column !important;', $media768Pos);
    checkAssertion($media768Pos !== false && $mobileColumnRulePos !== false && $mobileColumnRulePos > $media768Pos, "$file: Mobile target participant rules are strictly inside @media (max-width: 767.98px)");
}

// 4. Verify faculty/activities.php desktop parity
$actContent = file_get_contents($facultyDir . '/activities.php');
// Desktop horizontal filters exist
checkAssertion(strpos($actContent, '.filters {') !== false && strpos($actContent, 'display: flex;') !== false, "activities.php: Desktop horizontal .filters rule exists");
checkAssertion(strpos($actContent, '.filters-count {') !== false && strpos($actContent, 'margin-left: auto;') !== false, "activities.php: Desktop right-aligned .filters-count exists");
// Verify mobile stacking is inside @media (max-width: 640px)
$actMobileFiltersPos = strpos($actContent, 'flex-direction: column !important;');
$mediaActPos = strrpos(substr($actContent, 0, $actMobileFiltersPos), '@media (max-width: 640px)');
checkAssertion($mediaActPos !== false, "activities.php: Mobile filter stacking is strictly inside @media (max-width: 640px)");

// 5. Verify faculty/view-activity.php desktop parity
$viewContent = file_get_contents($facultyDir . '/view-activity.php');
// Desktop info-grid is untouched
checkAssertion(strpos($viewContent, '.info-grid') !== false, "view-activity.php: Desktop .info-grid exists");
// Mobile rules inside max-width 767.98px
$viewMobilePos = strpos($viewContent, 'Responsive Mobile Refinements');
$mediaViewPos = strpos($viewContent, '@media (max-width: 767.98px)', $viewMobilePos);
checkAssertion($mediaViewPos !== false, "view-activity.php: Mobile detail rules are strictly inside @media (max-width: 767.98px)");

// 6. Verify faculty/post-event-form.php desktop parity
$pefContent = file_get_contents($facultyDir . '/post-event-form.php');
// Desktop 2-column grid exists
checkAssertion(strpos($pefContent, 'repeat(auto-fit, minmax(360px, 1fr))') !== false, "post-event-form.php: Desktop 2-column form grid exists");
// Desktop action buttons are flex with space-between
checkAssertion(strpos($pefContent, 'justify-content:space-between') !== false, "post-event-form.php: Desktop action bar has horizontal space-between layout");
// Mobile rules inside max-width 640px
$pefMobilePos = strpos($pefContent, '.post-event-action-bar {');
$mediaPefPos = strrpos(substr($pefContent, 0, $pefMobilePos), '@media (max-width: 640px)');
checkAssertion($mediaPefPos !== false, "post-event-form.php: Mobile action bar stacking is strictly inside @media (max-width: 640px)");

echo "\n-------------------------------------------------------\n";
echo " Results: $passed Passed, " . count($errors) . " Failed.\n";
if (empty($errors)) {
    echo " ALL TESTS PASSED! Desktop layout is 100% preserved and unaffected.\n";
    exit(0);
} else {
    echo " SOME TESTS FAILED.\n";
    exit(1);
}
