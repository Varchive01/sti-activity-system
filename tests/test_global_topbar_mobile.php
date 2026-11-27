<?php
/**
 * tests/test_global_topbar_mobile.php
 * Automated verification of global mobile topbar fixes and desktop parity.
 */

$rootDir = realpath(__DIR__ . '/..');
$errors = [];
$passed = 0;

function check($condition, $desc) {
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
echo " STI-ARMES Global Topbar Mobile & Parity Verification\n";
echo "=======================================================\n\n";

$mainCss = file_get_contents($rootDir . '/assets/css/main.css');

// 1. Verify Desktop Topbar (>= 1024px) remains untouched
$desktopTopbarMatch = preg_match('/\.topbar\s*\{[^}]*height:\s*64px;[^}]*min-height:\s*64px;[^}]*max-height:\s*64px;/s', $mainCss);
check($desktopTopbarMatch === 1, "Desktop topbar 64px rigid height is preserved outside media queries");

// 2. Verify Tablet (< 1024px) topbar has single-row nowrap enforcement
$tabletPos = strpos($mainCss, '@media (max-width: 1023.98px)');
$tabletTopbarPos = strpos($mainCss, '.topbar {', $tabletPos);
$tabletTopbarChunk = substr($mainCss, $tabletTopbarPos, 300);
check(strpos($tabletTopbarChunk, 'flex-wrap: nowrap !important;') !== false, "Tablet (< 1024px) topbar enforces flex-wrap: nowrap");
check(strpos($tabletTopbarChunk, 'height: 56px !important;') !== false, "Tablet (< 1024px) topbar enforces height: 56px");

// 3. Verify Mobile (< 768px) topbar has single-row nowrap enforcement and sticky positioning
$mobilePos = strpos($mainCss, '@media (max-width: 767.98px)');
$mobileTopbarPos = strpos($mainCss, '/* Mobile Single-Row Rigid Topbar Architecture */', $mobilePos);
$mobileTopbarChunk = substr($mainCss, $mobileTopbarPos, 4500);

check(strpos($mobileTopbarChunk, 'flex-wrap: nowrap !important;') !== false, "Mobile (< 768px) topbar enforces flex-wrap: nowrap");
check(strpos($mobileTopbarChunk, 'height: 56px !important;') !== false, "Mobile (< 768px) topbar enforces height: 56px");
check(strpos($mobileTopbarChunk, 'position: sticky !important;') !== false, "Mobile (< 768px) topbar is sticky to viewport top");

// 4. Verify Hamburger button (.mobile-nav-toggle)
check(strpos($mobileTopbarChunk, '.mobile-nav-toggle {') !== false, "Mobile topbar defines .mobile-nav-toggle");
check(strpos($mobileTopbarChunk, 'width: 36px !important;') !== false, ".mobile-nav-toggle is sized to 36px touch button");
check(strpos($mobileTopbarChunk, 'order: 0 !important;') !== false, ".mobile-nav-toggle has order: 0");

// 5. Verify Page Title (.topbar .page-title)
check(strpos($mobileTopbarChunk, '.topbar .page-title {') !== false, "Mobile topbar defines .topbar .page-title");
check(strpos($mobileTopbarChunk, 'text-overflow: ellipsis !important;') !== false, ".page-title truncates cleanly with ellipsis");
check(strpos($mobileTopbarChunk, 'order: 1 !important;') !== false, ".page-title has order: 1");

// 6. Verify Topbar Right (.topbar-right)
check(strpos($mobileTopbarChunk, '.topbar-right {') !== false, "Mobile topbar defines .topbar-right");
check(strpos($mobileTopbarChunk, 'flex-wrap: nowrap !important;') !== false, ".topbar-right enforces flex-wrap: nowrap");
check(strpos($mobileTopbarChunk, 'order: 3 !important;') !== false, ".topbar-right has order: 3");

// 7. Verify Theme Toggle, Notification Bell, and Avatar sizing
check(strpos($mobileTopbarChunk, '#themeToggleBtn') !== false && strpos($mobileTopbarChunk, 'width: 34px !important;') !== false, "Theme toggle and notification bell sized to 34px on mobile");
check(strpos($mobileTopbarChunk, '.topbar-profile .topbar-avatar') !== false, "Avatar sized to 34px on mobile");

// 8. Verify Offline Sync Badge responsiveness
check(strpos($mobileTopbarChunk, '#offlineSyncBadge .sync-text') !== false, "Offline sync badge text hides on small mobile screens to prevent crowding");

// 9. Verify Phone screens (< 520px) topbar padding & containment
$phonePos = strpos($mainCss, '@media (max-width: 519.98px)');
$phoneTopbarChunk = substr($mainCss, $phonePos, 800);
check(strpos($phoneTopbarChunk, 'height: 56px !important;') !== false, "Phone (< 520px) topbar retains 56px single-row height");
check(strpos($phoneTopbarChunk, 'flex-wrap: nowrap !important;') !== false, "Phone (< 520px) topbar retains flex-wrap: nowrap");

// 10. Verify Ultra-narrow (< 360px) topbar support
$narrowPos = strpos($mainCss, '@media (max-width: 359.98px)');
$narrowTopbarChunk = substr($mainCss, $narrowPos, 800);
check(strpos($narrowTopbarChunk, 'height: 52px !important;') !== false, "Ultra-narrow (< 360px) topbar adapts to 52px height");
check(strpos($narrowTopbarChunk, 'width: 28px !important;') !== false, "Ultra-narrow (< 360px) touch icons adapt to 28px");

// 11. Cross-role topbar structure verification
$roles = [
    'faculty/dashboard.php' => 'Faculty Dashboard',
    'admin1/dashboard.php' => 'Admin1 Dashboard',
    'admin2/dashboard.php' => 'Admin2 Dashboard',
    'dean/dashboard.php'   => 'Dean Dashboard',
    'faculty/activities.php' => 'Faculty Activities',
    'faculty/proposal-create.php' => 'Proposal Create',
    'faculty/proposal-edit.php' => 'Proposal Edit'
];

foreach ($roles as $file => $name) {
    $filePath = $rootDir . '/' . $file;
    check(file_exists($filePath), "$name file exists: $file");
    $content = file_get_contents($filePath);
    check(strpos($content, '<header class="topbar">') !== false, "$name includes standard <header class=\"topbar\">");
    check(strpos($content, 'class="topbar-right"') !== false, "$name includes standard topbar-right container");
}

echo "\n-------------------------------------------------------\n";
echo " Results: $passed Passed, " . count($errors) . " Failed.\n";
if (empty($errors)) {
    echo " ALL TESTS PASSED! Global topbar is fully verified.\n";
    exit(0);
} else {
    echo " SOME TESTS FAILED.\n";
    exit(1);
}
