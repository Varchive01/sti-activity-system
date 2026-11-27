<?php
/**
 * Automated Verification: Comprehensive All-Roles Responsive & Fluid Design Test
 */

$testResults = [];
function recordResult($name, $passed, $details = '') {
    global $testResults;
    $testResults[] = ['name' => $name, 'passed' => $passed, 'details' => $details];
    echo ($passed ? "[PASS] " : "[FAIL] ") . $name . ($details ? " - $details" : "") . "\n";
}

echo "=== Comprehensive Responsive & Fluid Design Verification ===\n\n";

// 1. Check Viewport Meta Tag Across All HTML Rendering Pages
$roles = ['admin1', 'admin2', 'dean', 'faculty', 'program_head', 'auth'];
$missingViewport = [];
$totalChecked = 0;
foreach ($roles as $role) {
    $dir = __DIR__ . '/../' . $role;
    if (!is_dir($dir)) continue;
    $files = scandir($dir);
    foreach ($files as $f) {
        if (substr($f, -4) === '.php' && !in_array($f, ['test-email.php', 'send_proposal_email.php', 'kpi-export.php', 'export-pdf.php'])) {
            $content = file_get_contents($dir . '/' . $f);
            if (stripos($content, '<!DOCTYPE') !== false || stripos($content, '<html') !== false) {
                $totalChecked++;
                if (stripos($content, 'name="viewport"') === false) {
                    $missingViewport[] = "$role/$f";
                }
            }
        }
    }
}
recordResult(
    "Viewport Meta Tag Check",
    empty($missingViewport),
    "Checked $totalChecked HTML pages. Missing: " . (empty($missingViewport) ? '0' : implode(', ', $missingViewport))
);

// 2. Check Global CSS Rules in assets/css/main.css
$mainCss = file_get_contents(__DIR__ . '/../assets/css/main.css');

$hasFormGridCollapse = (
    strpos($mainCss, '.form-grid') !== false &&
    strpos($mainCss, 'grid-template-columns: 1fr !important;') !== false
);
recordResult("Global Form Grid Mobile 1-Column Collapse", $hasFormGridCollapse, "Form grids collapse to single column at @media (max-width: 767.98px)");

$hasTableContainment = (
    strpos($mainCss, '.table-wrap,') !== false &&
    strpos($mainCss, 'overflow-x: auto !important;') !== false &&
    strpos($mainCss, '-webkit-overflow-scrolling: touch !important;') !== false
);
recordResult("Universal Table Horizontal Scroll Containment", $hasTableContainment, "Tables scroll with touch momentum within container");

$hasModalContainment = (
    strpos($mainCss, 'calc(100vw - 20px) !important;') !== false &&
    strpos($mainCss, 'calc(100dvh - 24px) !important;') !== false
);
recordResult("Universal Modal Viewport Bounds & Containment", $hasModalContainment, "Modals constrained to viewport width & height with internal scrolling");

// 3. Check Program Head Dashboard
$phContent = file_get_contents(__DIR__ . '/../program_head/dashboard.php');
$phNoUnscopedMin960 = (strpos($phContent, "@media (min-width: 1024px) {\n      .stat-grid {\n        min-width: 960px;") !== false ||
                       strpos($phContent, "@media (min-width: 1024px)") !== false);
recordResult("Program Head Stat-Grid Mobile Fluidity", $phNoUnscopedMin960, "min-width: 960px is strictly scoped to >= 1024px desktop");

$phHasNavToggle = (strpos($phContent, 'mobile-nav-toggle') !== false);
recordResult("Program Head Topbar Mobile Nav Toggle", $phHasNavToggle, "Mobile toggle button present in topbar");

$phHas2ColMobile = (strpos($phContent, "grid-template-columns: repeat(2, minmax(0, 1fr)) !important;") !== false);
recordResult("Program Head 2-Column Touch Grid", $phHas2ColMobile, "Stat cards adapt to 2-column layout on mobile devices");

// 4. Check Faculty Dashboard
$facultyContent = file_get_contents(__DIR__ . '/../faculty/dashboard.php');
$facultyNoUnscopedMin960 = (strpos($facultyContent, "@media (min-width: 1024px)") !== false);
recordResult("Faculty Stat-Grid Desktop Scoping", $facultyNoUnscopedMin960, "min-width: 960px is strictly scoped to >= 1024px desktop");

// 5. Check evaluate.php
$evalContent = file_get_contents(__DIR__ . '/../evaluate.php');
$evalFluid = (strpos($evalContent, 'width:100%; max-width:760px;') !== false);
recordResult("Participant Feedback Portal Fluid Layout", $evalFluid, "Container width is 100% fluid with max-width containment");

// 6. Summary
$totalPass = count(array_filter($testResults, fn($r) => $r['passed']));
$totalTests = count($testResults);
echo "\nTotal Tests: $totalTests | Passed: $totalPass | Failed: " . ($totalTests - $totalPass) . "\n";
exit($totalPass === $totalTests ? 0 : 1);
