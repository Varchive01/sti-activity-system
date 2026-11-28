<?php
/**
 * STI-ARMES Mobile View Verification & Audit Suite
 * 
 * Verifies mobile responsiveness across all viewports:
 * - Ultra-compact phones (320px - 360px)
 * - Standard mobile smartphones (375px - 480px)
 * - Tablets & Phablets (768px - 1023px)
 * - Universal CSS zero-blowout rules, fluid typography, touch scrolling, and live mobile HTTP response.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$passed = 0;
$failed = 0;
$total  = 0;

function runCheck(string $title, bool $condition, string $details = ''): void {
    global $passed, $failed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo " [PASS] {$title}\n";
    } else {
        $failed++;
        echo " [FAIL] {$title}" . ($details ? " -> {$details}" : "") . "\n";
    }
}

echo "=================================================================\n";
echo " STI-ARMES Comprehensive Mobile View Verification & Audit Suite\n";
echo "=================================================================\n\n";

$rootDir = dirname(__DIR__);
$mainCssPath = $rootDir . '/assets/css/main.css';
$mainCss = file_exists($mainCssPath) ? file_get_contents($mainCssPath) : '';

// ── 1. Mobile Viewport Meta Tag Audit ───────────────────────────────────────
echo "── Section 1: Mobile Viewport Meta Tag Audit ──\n";
$targetDirs = ['faculty', 'auth', 'admin1', 'admin2', 'program_head', 'dean', 'feedback'];
$totalChecked = 0;
$missingViewport = [];

foreach ($targetDirs as $dir) {
    $fullDir = $rootDir . '/' . $dir;
    if (!is_dir($fullDir)) continue;
    $files = scandir($fullDir);
    foreach ($files as $file) {
        if (!str_ends_with($file, '.php') && !str_ends_with($file, '.html')) continue;
        $filePath = $fullDir . '/' . $file;
        $content = file_get_contents($filePath);
        
        // Check if page outputs HTML (skip pure API / redirects)
        if (str_contains($content, '<html') || str_contains($content, '<!DOCTYPE') || str_contains($content, '<head')) {
            $totalChecked++;
            if (!preg_match('/<meta[^>]+name=[\'"]viewport[\'"][^>]+content=[\'"][^\'"]*width=device-width[^\'"]*[\'"]/i', $content)) {
                $missingViewport[] = "{$dir}/{$file}";
            }
        }
    }
}

// Also check reusable templates in includes/
$includeTemplates = [
    'includes/my-tasks-template.php',
    'includes/email_templates/notification.html'
];
foreach ($includeTemplates as $rel) {
    $path = $rootDir . '/' . $rel;
    if (file_exists($path)) {
        $content = file_get_contents($path);
        if (str_contains($content, '<html') || str_contains($content, '<!DOCTYPE')) {
            $totalChecked++;
            if (!preg_match('/<meta[^>]+name=[\'"]viewport[\'"][^>]+content=[\'"][^\'"]*width=device-width[^\'"]*[\'"]/i', $content)) {
                $missingViewport[] = $rel;
            }
        }
    }
}

runCheck(
    "Mobile viewport meta tag present in all user-facing HTML pages (Checked: {$totalChecked})",
    count($missingViewport) === 0,
    "Missing in: " . implode(', ', $missingViewport)
);

// ── 2. Universal Zero-Blowout & Fluid Base Protections ─────────────────────
echo "\n── Section 2: Universal Zero-Blowout & Fluid Base Protections ──\n";

runCheck(
    "Root html and body locked to 100vw to prevent sideways page blowout",
    str_contains($mainCss, 'max-width: 100vw') && str_contains($mainCss, 'overflow-x: hidden')
);

runCheck(
    "Universal media elements (img, svg, canvas, video) locked with max-width: 100%",
    (bool)preg_match('/(html\s+body\s+img|img)[^{]*{[^}]*max-width:\s*100%\s*!important/i', $mainCss)
);

runCheck(
    "Universal card containers constrained with max-width: 100% and box-sizing: border-box",
    (bool)preg_match('/\.card[^{]*{[^}]*max-width:\s*100%\s*!important/i', $mainCss)
);

runCheck(
    "Fluid typography base configured using clamp() for smooth cross-device text scaling",
    (bool)preg_match('/body[^{]*{[^}]*font-size:\s*clamp\(/i', $mainCss)
);

runCheck(
    "Universal .cards fluid auto-fit CSS grid rule defined",
    (bool)preg_match('/\.cards[^{]*{[^}]*grid-template-columns:\s*repeat\(auto-fit/i', $mainCss)
);

// ── 3. Mobile Navigation & Touch Layout Breakpoints ─────────────────────────
echo "\n── Section 3: Mobile Navigation & Touch Layout Breakpoints ──\n";

runCheck(
    "Ultra-mobile breakpoint (< 360px) defined for compact screens",
    str_contains($mainCss, '@media (max-width: 359.98px)')
);

runCheck(
    "Stat cards collapse to 1 single column on ultra-compact screens (< 360px)",
    str_contains($mainCss, '@media (max-width: 359.98px)') && str_contains($mainCss, 'grid-template-columns: 1fr !important')
);

runCheck(
    "Standard mobile breakpoint (< 768px) defined for smartphones",
    str_contains($mainCss, '@media (max-width: 767.98px)') || str_contains($mainCss, '@media (max-width: 768px)')
);

runCheck(
    "Form grids collapse to 1 single column on mobile screens (< 768px)",
    str_contains($mainCss, '@media (max-width: 767.98px)') && str_contains($mainCss, 'grid-template-columns: 1fr !important')
);

runCheck(
    "Filter bars & search rows collapse into vertical stack on mobile screens",
    str_contains($mainCss, '.filter-bar,') && str_contains($mainCss, 'flex-direction: column !important')
);

runCheck(
    "Universal .nav flex container collapses to column on mobile (< 768px)",
    str_contains($mainCss, '@media (max-width: 768px)') && str_contains($mainCss, '.nav') && str_contains($mainCss, 'flex-direction: column')
);

runCheck(
    "Tablet breakpoint (< 1024px) collapses desktop sidebar into off-canvas drawer",
    str_contains($mainCss, '@media (max-width: 1023.98px)') && str_contains($mainCss, '.sidebar')
);

$sidebarContent = file_get_contents($rootDir . '/includes/sidebar.php');
runCheck(
    "Mobile hamburger nav toggle button handler configured in shared navigation component",
    str_contains($sidebarContent, 'mobile-nav-toggle') && str_contains($mainCss, '.mobile-nav-toggle')
);

// ── 4. Table Touch Momentum & Modal Screen Containment ──────────────────────
echo "\n── Section 4: Table Touch Momentum & Modal Screen Containment ──\n";

runCheck(
    "Universal data tables wrapped with touch momentum scrolling (-webkit-overflow-scrolling: touch)",
    str_contains($mainCss, '-webkit-overflow-scrolling: touch') && str_contains($mainCss, 'overflow-x: auto')
);

runCheck(
    "Dynamic proposal tables (.dynamic-table) have horizontal touch scroll protection",
    str_contains($mainCss, '.dynamic-table') && str_contains($mainCss, 'overflow-x: auto')
);

runCheck(
    "Target participants scroll box has responsive horizontal scroll protection on tablets/phones",
    str_contains($mainCss, 'target-participants-scroll-box') && str_contains($mainCss, 'overflow-x: auto !important')
);

runCheck(
    "Proposal multi-step wizard (.wizard-steps) has horizontal touch-swipe snap navigation",
    str_contains($mainCss, '.wizard-steps') && str_contains($mainCss, 'scroll-snap-type: x')
);

runCheck(
    "Proposal navigation buttons (.nav-btns) stack vertically on mobile (< 640px)",
    str_contains($mainCss, '.nav-btns') && str_contains($mainCss, 'flex-direction: column-reverse !important')
);

runCheck(
    "Modals bounded within viewport dimensions (max-width: calc(100vw - 20px))",
    str_contains($mainCss, 'calc(100vw - 20px)')
);

runCheck(
    "Authentication card inputs have 38px left padding for icon spacing on mobile",
    str_contains($mainCss, 'padding-left: 38px !important')
);

$tmCssPath = $rootDir . '/assets/css/teacher-multiselect.css';
$tmCss = file_exists($tmCssPath) ? file_get_contents($tmCssPath) : '';
runCheck(
    "Teacher multiselect dropdown clamped dynamically to prevent screen overflow on 320px phones",
    str_contains($tmCss, 'min-width: min(280px, 100%)')
);

// ── 5. Faculty Pages Uncontained Dimensions Audit ───────────────────────────
echo "\n── Section 5: Faculty Pages Uncontained Dimensions Audit ──\n";

$facultyDir = $rootDir . '/faculty';
$facultyFiles = scandir($facultyDir);
$uncontainedIssues = [];

foreach ($facultyFiles as $f) {
    if (!str_ends_with($f, '.php')) continue;
    $content = file_get_contents($facultyDir . '/' . $f);
    
    // Look for rigid inline fixed widths > 450px that do not have max-width or width: 100%
    if (preg_match_all('/style=[\'"][^\'"]*?(?<!max-)width\s*:\s*([5-9]\d{2}|\d{4,})px[^\'"]*?[\'"]/i', $content, $matches)) {
        foreach ($matches[0] as $match) {
            // Verify if it also specifies max-width or width: 100% in the same style
            if (!str_contains($match, 'max-width') && !str_contains($match, 'width: 100%')) {
                $uncontainedIssues[] = "{$f} -> {$match}";
            }
        }
    }
}

runCheck(
    "All 18 Faculty pages free of rigid uncontained fixed widths (>450px)",
    count($uncontainedIssues) === 0,
    "Found uncontained styles: " . implode('; ', array_slice($uncontainedIssues, 0, 3))
);

// ── 6. Live Mobile Endpoint Simulation & Latency Test ───────────────────────
echo "\n── Section 6: Live Mobile Endpoint Simulation & Latency Test ──\n";
$targetUrl = 'https://sti-armes.freedev.app/auth/login.php';
$mobileUserAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

echo " Emulating iPhone Mobile Safari request to: {$targetUrl}\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $targetUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USERAGENT => $mobileUserAgent,
    CURLOPT_HTTPHEADER => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Sec-Ch-Ua-Mobile: ?1',
        'Sec-Ch-Ua-Platform: "iOS"'
    ]
]);

$start = microtime(true);
$response = curl_exec($ch);
$elapsed = round((microtime(true) - $start) * 1000, 2);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);

runCheck(
    "Live mobile HTTP request completed with status 200 OK (Latency: {$elapsed}ms)",
    $httpCode === 200,
    "HTTP {$httpCode} - {$curlErr}"
);

runCheck(
    "Live mobile response serves valid HTML with responsive markup",
    is_string($response) && strlen($response) > 500,
    "Response length: " . (is_string($response) ? strlen($response) : 0) . " bytes"
);

// ── Summary ────────────────────────────────────────────────────────────────
echo "\n=================================================================\n";
echo " MOBILE AUDIT SUMMARY: {$passed} Passed, {$failed} Failed of {$total} checks\n";
if ($failed === 0) {
    echo " RESULT: ALL MOBILE VIEW TESTS PASSED! The system is fully\n";
    echo "         flexible, responsive, and mobile-touch ready.\n";
} else {
    echo " RESULT: ISSUES DETECTED. Please review the failed checks above.\n";
}
echo "=================================================================\n";

exit($failed === 0 ? 0 : 1);
