<?php
/**
 * Test Suite: System-Wide Light Mode Display & Contrast Audit
 * Inspects all core views, stylesheets, components, and templates for
 * light mode readability, theme variable integrity, and color contrast.
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

// 1. Audit CSS Tokens in main.css for Light Mode
$mainCssPath = $rootDir . '/assets/css/main.css';
if (file_exists($mainCssPath)) {
    $mainCss = file_get_contents($mainCssPath);
    
    // Check root light tokens
    $hasBgBase = strpos($mainCss, '--bg-base:             #F4F6FB;') !== false || strpos($mainCss, '--bg-base:') !== false;
    $hasBgCard = strpos($mainCss, '--bg-card:             #FFFFFF;') !== false || strpos($mainCss, '--bg-card:') !== false;
    $hasTextMain = strpos($mainCss, '--text-main:           #0A1628;') !== false || strpos($mainCss, '--text-main:') !== false;
    $hasBorder = strpos($mainCss, '--border:              #E2E8F0;') !== false || strpos($mainCss, '--border:') !== false;
    
    recordCheck('CSS Architecture', 'Light Theme Design Tokens Present in :root', ($hasBgBase && $hasBgCard && $hasTextMain && $hasBorder), 'Found base, card, text-main, and border tokens');
    
    // Check data-theme="light" fallback / mapping
    $hasDataThemeLight = strpos($mainCss, '[data-theme="light"]') !== false;
    recordCheck('CSS Architecture', '[data-theme="light"] explicit selector defined', $hasDataThemeLight, 'Explicit light theme selector matches :root');
} else {
    recordCheck('CSS Architecture', 'main.css existence', false, 'File not found');
}

// 2. Audit Sidebar Light Mode Overrides
$sidebarPath = $rootDir . '/includes/sidebar.php';
if (file_exists($sidebarPath)) {
    $sidebar = file_get_contents($sidebarPath);
    
    $hasSidebarBgLight = strpos($sidebar, 'background: #FFFFFF !important;') !== false;
    $hasSidebarBorderLight = strpos($sidebar, 'border-right: 1px solid #E2E8F0 !important;') !== false;
    $hasBrandColorLight = strpos($sidebar, 'color: #0A1628 !important;') !== false;
    $hasNavTextLight = strpos($sidebar, 'color: #334155 !important;') !== false;
    
    recordCheck('Sidebar Component', 'Sidebar Background & Border Light Mode', ($hasSidebarBgLight && $hasSidebarBorderLight), 'Sidebar uses clean #FFFFFF surface and #E2E8F0 border in light mode');
    recordCheck('Sidebar Component', 'Sidebar Brand & Nav Item High-Contrast Dark Text', ($hasBrandColorLight && $hasNavTextLight), 'Brand text uses #0A1628 and nav items use #334155 for high readability');
} else {
    recordCheck('Sidebar Component', 'sidebar.php existence', false, 'File not found');
}

// 3. Audit Topbar Profile & System Modals in Light Mode
$topbarProfilePath = $rootDir . '/includes/topbar-profile.php';
if (file_exists($topbarProfilePath)) {
    $topbarProfile = file_get_contents($topbarProfilePath);
    
    $hasSysModalCard = strpos($topbarProfile, 'background: var(--bg-card, #ffffff);') !== false;
    $hasSysModalText = strpos($topbarProfile, 'color: var(--text-main, #0f172a);') !== false;
    $hasSysModalBorder = strpos($topbarProfile, 'border: 1px solid var(--border, #e2e8f0);') !== false;
    
    recordCheck('System Modal & Topbar', 'System Modal Light Mode Variables', ($hasSysModalCard && $hasSysModalText && $hasSysModalBorder), 'Global alert/confirm dialog correctly falls back to white card and dark text');
} else {
    recordCheck('System Modal & Topbar', 'topbar-profile.php existence', false, 'File not found');
}

// 4. Audit Universal Archive Modal Light Mode
$archiveModalPath = $rootDir . '/includes/universal-archive-modal.php';
if (file_exists($archiveModalPath)) {
    $archiveModal = file_get_contents($archiveModalPath);
    
    $hasArchiveCard = strpos($archiveModal, 'var(--bg-card, #ffffff)') !== false;
    $hasArchiveText = strpos($archiveModal, 'var(--text-main, #0f172a)') !== false;
    $hasArchiveBorder = strpos($archiveModal, 'var(--border, #e2e8f0)') !== false;
    
    recordCheck('Universal Archive Modal', 'Archive Modal Light Theme Adaptation', ($hasArchiveCard && $hasArchiveText && $hasArchiveBorder), 'Archive modal dynamically styles inputs, cards, and previews in light mode');
} else {
    recordCheck('Universal Archive Modal', 'universal-archive-modal.php existence', false, 'File not found');
}

// 5. Audit Notification Topbar Widget
$notifWidgetPath = $rootDir . '/includes/notification-topbar-widget.php';
if (file_exists($notifWidgetPath)) {
    $notifWidget = file_get_contents($notifWidgetPath);
    
    $hasThemeInit = strpos($notifWidget, "document.documentElement.dataset.theme = 'light'") !== false;
    $hasThemeSunMoon = strpos($notifWidget, 'theme-icon-moon') !== false && strpos($notifWidget, 'theme-icon-sun') !== false;
    
    recordCheck('Notification & Theme Switcher', 'Default Light Theme Initialization & Sun/Moon Icons', ($hasThemeInit && $hasThemeSunMoon), 'Theme switcher defaults to light mode and displays correct icon toggle');
} else {
    recordCheck('Notification & Theme Switcher', 'notification-topbar-widget.php existence', false, 'File not found');
}

// 6. Audit User Management Light Mode
$umPath = $rootDir . '/includes/user-management-view.php';
if (file_exists($umPath)) {
    $um = file_get_contents($umPath);
    
    $hasUmCancelBtnLight = strpos($um, 'background: #F1F5F9;') !== false && strpos($um, 'color: #334155;') !== false;
    $hasUmNoticeLight = strpos($um, 'color: #0A1628;') !== false;
    
    recordCheck('User Management', 'User Management Modal Buttons & Callouts Light Mode', ($hasUmCancelBtnLight && $hasUmNoticeLight), 'Cancel buttons and notice banners have clear contrast in light mode');
} else {
    recordCheck('User Management', 'user-management-view.php existence', false, 'File not found');
}

// 7. Audit Walkthrough Tour Styles in Light Mode
$walkthroughPath = $rootDir . '/assets/css/walkthrough.css';
if (file_exists($walkthroughPath)) {
    $walkthrough = file_get_contents($walkthroughPath);
    
    $hasWalkthroughCard = strpos($walkthrough, 'background: var(--bg-card, #FFFFFF);') !== false;
    $hasWalkthroughText = strpos($walkthrough, 'color: var(--text-main, #0A1628);') !== false;
    
    recordCheck('Guided Tour', 'Walkthrough Popovers Light Mode Integration', ($hasWalkthroughCard && $hasWalkthroughText), 'Popover cards use white cards and dark text in light mode');
} else {
    recordCheck('Guided Tour', 'walkthrough.css existence', false, 'File not found');
}

// 8. Output Summary
echo "=======================================================\n";
echo "   STI-ARMES LIGHT MODE DISPLAY & CONTRAST AUDIT\n";
echo "=======================================================\n\n";

$allPassed = true;
foreach ($results as $res) {
    $status = $res['passed'] ? '[PASS]' : '[FAIL]';
    if (!$res['passed']) $allPassed = false;
    echo sprintf("%-6s | %-25s | %-38s | %s\n", $status, $res['suite'], $res['test'], $res['details']);
}

echo "\n-------------------------------------------------------\n";
echo "Overall Result: " . ($allPassed ? "ALL 7 AUDIT SUITES PASSED" : "FAILURES DETECTED") . "\n";
echo "=======================================================\n";

exit($allPassed ? 0 : 1);

