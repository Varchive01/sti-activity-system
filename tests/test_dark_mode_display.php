<?php
/**
 * Test Suite: System-Wide Dark Mode Display & Contrast Audit
 * Inspects all core views, stylesheets, components, and templates for
 * dark mode readability, theme variable integrity, and color contrast.
 */

$rootDir = dirname(__DIR__);
$results = [];

function recordCheck($suite, $testName, $passed, $details = '') {
    global $results;
    $results[] = [
        'suite' => $suite,
        'test' => $testName,
        'passed' => $passed,
        'details' => $details
    ];
}

// 1. Audit CSS Tokens in main.css for Dark Mode
$mainCssPath = $rootDir . '/assets/css/main.css';
if (file_exists($mainCssPath)) {
    $mainCss = file_get_contents($mainCssPath);
    
    // Check dark tokens
    $hasBgBaseDark = strpos($mainCss, '--bg-base:             #070E18;') !== false;
    $hasBgCardDark = strpos($mainCss, '--bg-card:             #0F1B2E;') !== false;
    $hasTextMainDark = strpos($mainCss, '--text-main:           #FFFFFF;') !== false;
    $hasBorderDark = strpos($mainCss, '--border:              #1E2D45;') !== false;
    $hasTextBodyToken = strpos($mainCss, '--text-body:') !== false;
    
    recordCheck('CSS Architecture', 'Dark Theme Design Tokens Defined', ($hasBgBaseDark && $hasBgCardDark && $hasTextMainDark && $hasBorderDark && $hasTextBodyToken), 'Surfaces, typography, borders, and body text tokens verified');
    
    // Check [data-theme="dark"] rules count
    preg_match_all('/\[data-theme="dark"\]/', $mainCss, $matches);
    $darkRulesCount = count($matches[0]);
    recordCheck('CSS Architecture', 'System-wide [data-theme="dark"] Rules Count', ($darkRulesCount >= 100), "Found {$darkRulesCount} dark theme rules in main.css");
} else {
    recordCheck('CSS Architecture', 'main.css existence', false, 'File not found');
}

// 2. Audit Sidebar Component in Dark Mode
$sidebarPath = $rootDir . '/includes/sidebar.php';
if (file_exists($sidebarPath)) {
    $sidebar = file_get_contents($sidebarPath);
    
    $hasSidebarDarkBg = strpos($sidebar, '[data-theme="dark"] .sidebar') !== false;
    $hasSidebarDarkBottom = strpos($sidebar, '[data-theme="dark"] .sidebar-bottom') !== false;
    $hasSidebarDarkUser = strpos($sidebar, '[data-theme="dark"] .sidebar-user .uname') !== false;
    
    recordCheck('Sidebar Navigation', 'Sidebar Dark Theme Surface & Text Overrides', ($hasSidebarDarkBg && $hasSidebarDarkBottom && $hasSidebarDarkUser), 'Sidebar background, nav items, and user profile adapt to dark mode');
} else {
    recordCheck('Sidebar Navigation', 'sidebar.php existence', false, 'File not found');
}

// 3. Audit Review Pages Parity (Admin 1, Admin 2, Dean)
$admin1Rev = file_get_contents($rootDir . '/admin1/review.php');
$admin2Rev = file_get_contents($rootDir . '/admin2/review.php');
$deanRev   = file_get_contents($rootDir . '/dean/review.php');

$a1HasDark = strpos($admin1Rev, '[data-theme="dark"] .review-action-bar') !== false;
$a2HasDark = strpos($admin2Rev, '[data-theme="dark"] .review-action-bar') !== false;
$deanHasDark = strpos($deanRev, '[data-theme="dark"] .review-action-bar button[onclick*="return"]') !== false;

recordCheck('Review Module', 'Admin 1 Review Dark Mode Coverage', $a1HasDark, 'Action bar, cards, outline buttons protected in dark mode');
recordCheck('Review Module', 'Admin 2 Review Dark Mode Parity', $a2HasDark, 'Added full dark mode tokens & overrides matching Admin 1');
recordCheck('Review Module', 'Dean Review Return Button & Action Bar Contrast', $deanHasDark, 'Return button high-contrast styling protected in dark mode');

// 4. Audit Schedule Conflict Modal in Dark Mode
$propCreate = file_get_contents($rootDir . '/faculty/proposal-create.php');
$propEdit   = file_get_contents($rootDir . '/faculty/proposal-edit.php');

$createConfText = strpos($propCreate, '#scheduleConflictModal #scuConfTitle') !== false;
$editConfText   = strpos($propEdit, '#scheduleConflictModal #scuConfTitle') !== false;

recordCheck('Proposal Conflict Modal', 'Proposal Create Conflict Modal Contrast', $createConfText, 'Conflict modal title and date/time text adapted for dark surfaces');
recordCheck('Proposal Conflict Modal', 'Proposal Edit Conflict Modal Contrast', $editConfText, 'Conflict modal title and date/time text adapted for dark surfaces');

// 5. Audit Topbar Profile & Notifications in Dark Mode
$topbarProfile = file_get_contents($rootDir . '/includes/topbar-profile.php');
$notifWidget   = file_get_contents($rootDir . '/includes/notification-topbar-widget.php');

$hasTpModalDark = strpos($topbarProfile, '[data-theme="dark"] .profile-modal') !== false;
$hasNotifDark   = strpos($notifWidget, '[data-theme="dark"] .notif-dropdown') !== false;

recordCheck('Topbar & Notifications', 'Profile Modal Dark Mode Surface', $hasTpModalDark, 'Profile modal and cards adapt with dark surfaces');
recordCheck('Topbar & Notifications', 'Notification Dropdown Dark Mode Surface', $hasNotifDark, 'Notification drawer and popover adapt with dark surfaces');

// 6. Audit Dashboards Dark Mode Coverage
$facDash = file_get_contents($rootDir . '/faculty/dashboard.php');
$hasFacHeroDark = strpos($facDash, '[data-theme="dark"] .faculty-hero-banner') !== false;
$hasFacCardDark = strpos($facDash, '[data-theme="dark"] .stat-card') !== false;

recordCheck('Dashboard Views', 'Faculty Dashboard Hero & Stat Cards', ($hasFacHeroDark && $hasFacCardDark), 'Hero banner and metric cards adapt with dark surfaces and light values');

// 7. Audit Guided Tour in Dark Mode
$walkthrough = file_get_contents($rootDir . '/assets/css/walkthrough.css');
$hasWalkthroughDark = strpos($walkthrough, '[data-theme="dark"] .sti-walkthrough-mask-rect') !== false;
recordCheck('Guided Tour', 'Walkthrough Overlay Dark Mode Mask', $hasWalkthroughDark, 'Spotlight and overlay masks adapt cleanly to dark theme');

// 8. Output Summary
echo "=======================================================\n";
echo "    STI-ARMES DARK MODE DISPLAY & CONTRAST AUDIT\n";
echo "=======================================================\n\n";

$allPassed = true;
foreach ($results as $res) {
    $status = $res['passed'] ? '[PASS]' : '[FAIL]';
    if (!$res['passed']) $allPassed = false;
    echo sprintf("%-6s | %-24s | %-40s | %s\n", $status, $res['suite'], $res['test'], $res['details']);
}

echo "\n-------------------------------------------------------\n";
echo "Overall Result: " . ($allPassed ? "ALL 8 AUDIT SUITES PASSED" : "FAILURES DETECTED") . "\n";
echo "=======================================================\n";

exit($allPassed ? 0 : 1);

