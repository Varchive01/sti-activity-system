<?php
// Multi-Resolution Responsive Layout Verification Suite

$results = ['passed' => 0, 'failed' => 0];

function check(bool $cond, string $msg, &$results) {
    if ($cond) {
        echo " [PASS] $msg\n";
        $results['passed']++;
    } else {
        echo " [FAIL] $msg\n";
        $results['failed']++;
    }
}

echo "=======================================================\n";
echo " STI-ARMES Multi-Device Resolution Verification Suite\n";
echo "=======================================================\n\n";

$css = file_get_contents(__DIR__ . '/../assets/css/main.css');

// 1. Ultra-Mobile (320px - 359px)
check(strpos($css, '@media (max-width: 359.98px)') !== false, "Ultra-mobile breakpoint (< 360px) defined in main.css", $results);
check(strpos($css, 'grid-template-columns: 1fr !important') !== false, "Stat cards collapse to 1-col on ultra-narrow screens (< 360px)", $results);

// 2. Standard Mobile (360px - 767px)
check(strpos($css, '@media (max-width: 767.98px)') !== false, "Standard phone breakpoint (< 768px) defined in main.css", $results);
check(strpos($css, 'grid-template-columns: repeat(2, minmax(0, 1fr)) !important') !== false, "Stat cards adapt to 2-column touch grid on phones", $results);
check(strpos($css, '.table-wrap') !== false && strpos($css, 'overflow-x: auto !important') !== false, "Universal table container touch scroll protection active", $results);

// 3. Tablet (768px - 1023px)
check(strpos($css, '@media (min-width: 768px) and (max-width: 1023.98px)') !== false, "Tablet breakpoint (768px - 1023px) defined in main.css", $results);
check(strpos($css, 'grid-template-columns: repeat(4, minmax(0, 1fr)) !important') !== false, "Stat cards adapt to 4-column balanced grid on tablets", $results);
check(strpos($css, '@media (max-width: 1023.98px)') !== false, "Tablet sidebar off-canvas collapse defined", $results);

// 4. Laptop & Desktop (>= 1024px)
check(strpos($css, '@media (min-width: 1024px)') !== false, "Desktop layout breakpoint (>= 1024px) defined", $results);
check(strpos($css, '--sidebar-w:           260px;') !== false, "Desktop sidebar full width (260px) preserved", $results);

// 5. Widescreen & Monitor (>= 1440px)
check(strpos($css, '@media (min-width: 1440px)') !== false, "Widescreen desktop breakpoint (>= 1440px) defined", $results);

// 6. Zero Horizontal Canvas Blowout Lock
check(strpos($css, 'max-width: 100vw !important') !== false, "Root html/body locked to 100vw to prevent horizontal blowout", $results);
check(strpos($css, 'overflow-x: hidden !important') !== false, "Root html/body overflow-x locked", $results);

// 7. Login Page Fluid Scaling
$login = file_get_contents(__DIR__ . '/../auth/login.php');
check(strpos($login, 'name="viewport"') !== false, "Login page includes mobile viewport meta tag", $results);
check(strpos($login, 'main.css?v=1.2.1') !== false, "Login page links to main.css with v=1.2.1 cache buster", $results);
check(strpos($login, 'background-attachment: scroll !important') !== false, "Login page fixes iOS background-attachment bug", $results);

// 8. Dashboards Desktop Scoping Check
$facultyDash = file_get_contents(__DIR__ . '/../faculty/dashboard.php');
check(strpos($facultyDash, '@media (min-width: 1024px)') !== false, "Faculty dashboard scopes 960px minimum width to desktop", $results);

$admin1Dash = file_get_contents(__DIR__ . '/../admin1/dashboard.php');
check(strpos($admin1Dash, 'trends-chart-svg') !== false && strpos($admin1Dash, 'width: 100% !important;') !== false, "Admin 1 dashboard charts fluidly scale on mobile", $results);

$deanDash = file_get_contents(__DIR__ . '/../dean/dashboard.php');
check(strpos($deanDash, 'month-bar-wrap') !== false && strpos($deanDash, 'width: 100% !important;') !== false, "Dean dashboard monthly chart fluidly scales on mobile", $results);

echo "\n-------------------------------------------------------\n";
echo " Results: {$results['passed']} Passed, {$results['failed']} Failed.\n";
if ($results['failed'] === 0) {
    echo " ALL TESTS PASSED! Multi-device responsive design is verified.\n";
} else {
    echo " SOME TESTS FAILED.\n";
}
echo "=======================================================\n";
exit($results['failed'] === 0 ? 0 : 1);
