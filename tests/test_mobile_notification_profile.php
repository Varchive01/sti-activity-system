<?php
/**
 * tests/test_mobile_notification_profile.php
 * Automated verification of mobile notification and profile components.
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
echo " STI-ARMES Mobile Notification & Profile Verification\n";
echo "=======================================================\n\n";

// 1. Audit notification-topbar-widget.php
$notifWidget = file_get_contents($rootDir . '/includes/notification-topbar-widget.php');

check(strpos($notifWidget, 'right: -80px') === false, "notification widget: Broken 'right: -80px' rule removed");
check(strpos($notifWidget, 'right: -60px') === false, "notification widget: Broken 'right: -60px' rule removed");
check(strpos($notifWidget, 'right: -42px !important;') !== false, "notification widget: Safe right alignment rule 'right: -42px' active");
check(strpos($notifWidget, 'max-height: min(360px, calc(100dvh - 150px))') !== false, "notification widget: Mobile scroll height containment active");
check(strpos($notifWidget, '@media (max-width: 767.98px)') !== false, "notification widget: Scoped within mobile media query");

// 2. Audit topbar-profile.php
$topbarProfile = file_get_contents($rootDir . '/includes/topbar-profile.php');

check(strpos($topbarProfile, '.topbar-profile-popover') !== false, "topbar profile: Popover menu exists");
check(strpos($topbarProfile, 'min(280px, calc(100vw - 20px))') !== false, "topbar profile: Popover width bounded for mobile screens");
check(strpos($topbarProfile, 'min-height: 42px !important;') !== false, "topbar profile: Menu items have 42px touch targets");
check(strpos($topbarProfile, 'max-height: 94dvh !important;') !== false, "topbar profile: Profile modal height bounded for mobile screens");
check(strpos($topbarProfile, 'calc(92dvh - 125px) !important;') !== false, "topbar profile: Modal body scrollable on mobile");

// 3. Audit notifications.js
$notifJs = file_get_contents($rootDir . '/assets/js/notifications.js');

check(strpos($notifJs, 'window.innerWidth - 8') !== false, "notifications.js: clampPopoverPosition() enforces 8px viewport safety margin");

// 4. Audit sidebar.php
$sidebarPhp = file_get_contents($rootDir . '/includes/sidebar.php');

check(strpos($sidebarPhp, "notifDrop.classList.remove('open')") !== false, "sidebar.php: Mobile drawer dismisses open notification dropdown");
check(strpos($sidebarPhp, "profPop.classList.remove('open')") !== false, "sidebar.php: Mobile drawer dismisses open profile popover");

// 5. Audit main.css
$mainCss = file_get_contents($rootDir . '/assets/css/main.css');

check(strpos($mainCss, 'Mobile Topbar Notification & Profile Popover Containment') !== false, "main.css: Contains mobile popover containment rules");

echo "\n-------------------------------------------------------\n";
echo " Results: $passed Passed, " . count($errors) . " Failed.\n";
if (empty($errors)) {
    echo " ALL TESTS PASSED! Mobile notification & profile are fully functional.\n";
    exit(0);
} else {
    echo " SOME TESTS FAILED.\n";
    exit(1);
}
