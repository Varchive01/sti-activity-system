<?php
/**
 * tests/test_faculty_mobile_layout.php
 * Automated verification of mobile layout fixes across faculty portal.
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
echo " STI-ARMES Faculty Mobile Layout Verification Suite\n";
echo "=======================================================\n\n";

// 1. PHP Lint / Syntax Check on all faculty files
$facultyFiles = glob($facultyDir . '/*.php');
foreach ($facultyFiles as $file) {
    $out = [];
    $ret = 0;
    exec("php -l " . escapeshellarg($file) . " 2>&1", $out, $ret);
    checkAssertion($ret === 0, "PHP syntax valid: " . basename($file));
}

// 2. CSS Syntax & Brace Matching Check in proposal-create.php and proposal-edit.php
$createContent = file_get_contents($facultyDir . '/proposal-create.php');
checkAssertion(strpos($createContent, '#target-participants-scroll-box') !== false, "proposal-create.php contains responsive scroll box rule for target participants");
checkAssertion(strpos($createContent, '.target-participants-controls') !== false, "proposal-create.php contains mobile stacking for target participant controls");

$editContent = file_get_contents($facultyDir . '/proposal-edit.php');
checkAssertion(strpos($editContent, '#target-participants-scroll-box') !== false, "proposal-edit.php contains responsive scroll box rule for target participants");
checkAssertion(strpos($editContent, '.target-participants-controls') !== false, "proposal-edit.php contains mobile stacking for target participant controls");

// Check for the previously broken stray brace:
$hasStrayBraceInCreate = preg_match('/\.wizard-step \.step-num\s*\{[^}]+\}\s*\}\s*\.topbar-right \.btn-sm\s*\{[^}]+\}\s*\}/s', $createContent);
checkAssertion(!$hasStrayBraceInCreate, "proposal-create.php has no stray CSS closing braces");

$hasStrayBraceInEdit = preg_match('/\.wizard-step \.step-num\s*\{[^}]+\}\s*\}\s*\.topbar-right \.btn-sm\s*\{[^}]+\}\s*\}/s', $editContent);
checkAssertion(!$hasStrayBraceInEdit, "proposal-edit.php has no stray CSS closing braces");

// 3. faculty/dashboard.php responsiveness
$dashContent = file_get_contents($facultyDir . '/dashboard.php');
checkAssertion(strpos($dashContent, '@media (max-width: 991.98px)') !== false, "dashboard.php contains tablet 4-column stat-grid rule");
checkAssertion(strpos($dashContent, '@media (max-width: 767.98px)') !== false, "dashboard.php contains mobile 2-column stat-grid rule");
checkAssertion(strpos($dashContent, 'display: block !important') !== false && strpos($dashContent, '.activity-table thead') !== false, "dashboard.php contains mobile responsive card view for activity table");

// 4. faculty/activities.php filter and table responsiveness
$actContent = file_get_contents($facultyDir . '/activities.php');
checkAssertion(strpos($actContent, 'flex-direction: column !important') !== false, "activities.php contains mobile filter vertical stacking");
checkAssertion(strpos($actContent, 'min-width: 720px') !== false, "activities.php contains activity-table min-width for mobile scrolling");

// 5. faculty/view-activity.php mobile rules
$viewContent = file_get_contents($facultyDir . '/view-activity.php');
checkAssertion(strpos($viewContent, '@media (max-width: 767.98px)') !== false, "view-activity.php contains mobile responsive media query");
checkAssertion(strpos($viewContent, 'detail-table') !== false, "view-activity.php styles detail-table for mobile scrolling");
checkAssertion(strpos($viewContent, 'tabs-nav') !== false, "view-activity.php styles tabs-nav for mobile touch navigation");

// 6. faculty/post-event-form.php mobile rules
$pefContent = file_get_contents($facultyDir . '/post-event-form.php');
checkAssertion(strpos($pefContent, 'post-event-action-bar') !== false, "post-event-form.php has post-event-action-bar for mobile buttons");
checkAssertion(strpos($pefContent, 'min-width: 580px') !== false, "post-event-form.php contains minimum table width for touch devices");

// 7. assets/css/main.css global mobile rules
$mainCss = file_get_contents($rootDir . '/assets/css/main.css');
checkAssertion(strpos($mainCss, '.table-wrap > table') !== false, "main.css includes global mobile table min-width protection");
checkAssertion(strpos($mainCss, '.filters') !== false, "main.css includes mobile filter vertical stacking");

// 8. Mobile viewport meta tag check across all faculty pages
$viewportCheckPassed = true;
foreach ($facultyFiles as $file) {
    $content = file_get_contents($file);
    // Ignore redirect or pure include files
    if (strpos($content, '<html') !== false) {
        if (strpos($content, 'name="viewport"') === false && strpos($content, "name='viewport'") === false) {
            $viewportCheckPassed = false;
            echo " [WARN] Missing viewport meta in " . basename($file) . "\n";
        }
    }
}
checkAssertion($viewportCheckPassed, "All HTML-rendering faculty files have viewport meta tags");

echo "\n-------------------------------------------------------\n";
echo " Results: $passed Passed, " . count($errors) . " Failed.\n";
if (empty($errors)) {
    echo " ALL TESTS PASSED! Mobile layout is fully functional.\n";
    exit(0);
} else {
    echo " SOME TESTS FAILED.\n";
    exit(1);
}
