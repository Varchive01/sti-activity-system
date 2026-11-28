<?php
/**
 * Automated Verification: Google Calendar Layout & Floating Popup Parity
 * STI-ARMES System-wide Test
 */

$rootDir = dirname(__DIR__);
$errors = [];
$passes = [];

function check($desc, $cond) {
    global $errors, $passes;
    if ($cond) {
        $passes[] = "[PASS] $desc";
        echo "[PASS] $desc\n";
    } else {
        $errors[] = "[FAIL] $desc";
        echo "[FAIL] $desc\n";
    }
}

echo "=================================================================\n";
echo " STI-ARMES Google Calendar Layout & Floating Popup Audit\n";
echo "=================================================================\n\n";

// 1. Check PHP syntax across all calendar-related files
$filesToCheck = [
    'faculty/calendar.php',
    'admin1/calendar.php',
    'admin2/calendar.php',
    'dean/calendar.php',
    'faculty/proposal-create.php',
    'faculty/proposal-edit.php',
    'includes/phase2-proposal-enhancements.php'
];

foreach ($filesToCheck as $f) {
    $full = "$rootDir/$f";
    $output = [];
    $returnVar = 0;
    exec("php -l " . escapeshellarg($full), $output, $returnVar);
    check("PHP syntax check for $f", $returnVar === 0);
}

// 2. Verify mb_strimwidth with ellipses is removed from calendar files
$calendarFiles = ['faculty/calendar.php', 'admin1/calendar.php', 'admin2/calendar.php', 'dean/calendar.php'];
foreach ($calendarFiles as $f) {
    $content = file_get_contents("$rootDir/$f");
    check("$f: mb_strimwidth is completely removed", !str_contains($content, 'mb_strimwidth'));
    check("$f: includes assets/js/gcal-popup.js", str_contains($content, 'assets/js/gcal-popup.js'));
    check("$f: contains handleCalendarEventClick handler", str_contains($content, 'handleCalendarEventClick'));
    check("$f: contains cal-main-col container for unconstrained zero-expansion", str_contains($content, 'cal-main-col'));
    check("$f: retrieves full proposal fields (description, start_time, end_time, etc.)", str_contains($content, 'a.description') && str_contains($content, 'a.start_time'));
}

// 3. Verify phase2-proposal-enhancements.php contains schedule calendar Google Calendar parity
$phase2Content = file_get_contents("$rootDir/includes/phase2-proposal-enhancements.php");
check("phase2-proposal-enhancements.php: contains handleTimelinePillClick", str_contains($phase2Content, 'handleTimelinePillClick'));
check("phase2-proposal-enhancements.php: includes assets/js/gcal-popup.js", str_contains($phase2Content, 'assets/js/gcal-popup.js'));
check("phase2-proposal-enhancements.php: uses schedule-cal-cell with fixed containment", str_contains($phase2Content, 'schedule-cal-cell'));
check("phase2-proposal-enhancements.php: uses sched-cal-event-pill", str_contains($phase2Content, 'sched-cal-event-pill'));

// 4. Verify main.css contains Google Calendar popup and fixed cell dimensions
$cssContent = file_get_contents("$rootDir/assets/css/main.css");
check("main.css: contains .gcal-event-popup with light theme background #ffffff", str_contains($cssContent, '.gcal-event-popup') && str_contains($cssContent, '#ffffff'));
check("main.css: contains .gcal-theme-light with white background", str_contains($cssContent, '.gcal-theme-light') && str_contains($cssContent, '#ffffff'));
check("main.css: contains [data-theme=\"dark\"] .gcal-event-popup with dark theme background #1f1f1f", str_contains($cssContent, '[data-theme="dark"] .gcal-event-popup') && str_contains($cssContent, '#1f1f1f'));
check("main.css: contains .gcal-theme-dark with dark charcoal background", str_contains($cssContent, '.gcal-theme-dark') && str_contains($cssContent, '#1f1f1f'));
check("main.css: contains fixed grid-auto-rows for .cal-grid", str_contains($cssContent, 'grid-auto-rows: 116px'));
check("main.css: contains text-overflow: clip to prevent ellipses from distorting cells", str_contains($cssContent, 'text-overflow: clip'));
check("main.css: contains calc(100% / 7) for calendar column zero-expansion", str_contains($cssContent, 'grid-template-columns: repeat(7, calc(100% / 7))'));
check("main.css: contains mobile containment without horizontal scroll blowout", str_contains($cssContent, '#schedule-calendar-grid') && str_contains($cssContent, 'grid-auto-rows: 88px !important;'));

// 5. Verify gcal-popup.js exists and has all required methods
$jsPath = "$rootDir/assets/js/gcal-popup.js";
check("assets/js/gcal-popup.js: file exists", file_exists($jsPath));
$jsContent = file_get_contents($jsPath);
check("assets/js/gcal-popup.js: exports openGCalPopup", str_contains($jsContent, 'window.openGCalPopup = openGCalPopup'));
check("assets/js/gcal-popup.js: exports closeGCalPopup", str_contains($jsContent, 'window.closeGCalPopup = closeGCalPopup'));
check("assets/js/gcal-popup.js: contains gcalPopupSubtitle for formatted date/time line", str_contains($jsContent, 'gcalPopupSubtitle'));
check("assets/js/gcal-popup.js: contains gcalPopupColorMarker for event color indicator", str_contains($jsContent, 'gcalPopupColorMarker'));
check("assets/js/gcal-popup.js: contains Escape key dismiss listener", str_contains($jsContent, "'Escape'") || str_contains($jsContent, '"Escape"'));
check("assets/js/gcal-popup.js: contains click outside dismiss listener", str_contains($jsContent, 'addEventListener(\'click\''));
check("assets/js/gcal-popup.js: contains mobile responsive containment", str_contains($jsContent, 'vw <= 640') || str_contains($jsContent, 'vw <= 600'));

echo "\n-----------------------------------------------------------------\n";
echo "SUMMARY: " . count($passes) . " Passed, " . count($errors) . " Failed.\n";
if (empty($errors)) {
    echo "ALL CHECKS PASSED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "FAILURES DETECTED:\n" . implode("\n", $errors) . "\n";
    exit(1);
}
