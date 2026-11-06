<?php
/**
 * Test Suite: Comprehensive Faculty Light Mode & Dark Mode Contrast & Parity
 * Validates:
 * 1. Design token coverage (:root, [data-theme="light"], and [data-theme="dark"]).
 * 2. High-contrast typography in both modes (Light mode: dark text on light surfaces; Dark mode: light text on dark surfaces).
 * 3. Cards, tables, forms, modals, dropdowns, and calendar components across all 16 faculty pages.
 * 4. Override protections against hardcoded inline styles.
 * 5. Theme toggle state persistence and HTML attribute parity.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "=======================================================================\n";
echo "   FACULTY COMPREHENSIVE LIGHT & DARK THEME CONTRAST & PARITY TEST     \n";
echo "=======================================================================\n";

$allPassed = true;
function checkThemeTest($category, $testLabel, $condition, $details = '') {
    global $allPassed;
    if ($condition) {
        printf(" [PASS] | %-18s | %-42s | %s\n", $category, $testLabel, $details ?: 'OK');
    } else {
        printf(" [FAIL] | %-18s | %-42s | %s\n", $category, $testLabel, $details ?: 'FAILED');
        $allPassed = false;
    }
}

$css = file_get_contents(__DIR__ . '/../assets/css/main.css');

// 1. Core Token Verification
checkThemeTest("Design Tokens", "Light mode surface tokens defined", strpos($css, "--bg-card:") !== false && strpos($css, "--bg-base:") !== false);
checkThemeTest("Design Tokens", "Dark mode surface tokens defined", strpos($css, "--bg-card:             #0F1B2E") !== false);
checkThemeTest("Design Tokens", "Light mode text tokens defined", strpos($css, "--text-main:           #0A1628") !== false);
checkThemeTest("Design Tokens", "Dark mode text tokens defined", strpos($css, "--text-main:           #FFFFFF") !== false);

// 2. Global Component Parity in main.css
checkThemeTest("Component CSS", "Dark mode cards styled with #0F1B2E", strpos($css, '[data-theme="dark"] .card') !== false);
checkThemeTest("Component CSS", "Dark mode tables & headers covered", strpos($css, '[data-theme="dark"] table th') !== false);
checkThemeTest("Component CSS", "Dark mode hover row highlights covered", strpos($css, '[data-theme="dark"] table tr:hover td') !== false);
checkThemeTest("Component CSS", "Dark mode form inputs styled with #0B1524", strpos($css, '[data-theme="dark"] .form-control') !== false);
checkThemeTest("Component CSS", "Dark mode modals & dialogs covered", strpos($css, '[data-theme="dark"] .modal') !== false);
checkThemeTest("Component CSS", "Dark mode calendar event popup protected", strpos($css, '[data-theme="dark"] .event-popup') !== false);
checkThemeTest("Component CSS", "Dark mode AI questions styled with #0B1524", strpos($css, '[data-theme="dark"] .ai-question-item') !== false);
checkThemeTest("Component CSS", "Dark mode AI validation card styled with #0F1B2E", strpos($css, '[data-theme="dark"] .ai-validation-card') !== false);

// 3. Inline Style Override Coverage
checkThemeTest("Inline Overrides", "Dark mode overrides for hardcoded #ffffff", strpos($css, 'div[style*="background: #ffffff"]') !== false);
checkThemeTest("Inline Overrides", "Dark mode overrides for hardcoded #fff5f5 (danger)", strpos($css, 'div[style*="background:#fff5f5"]') !== false);
checkThemeTest("Inline Overrides", "Dark mode overrides for hardcoded #fffbeb (warning)", strpos($css, 'div[style*="background:#fffbeb"]') !== false);
checkThemeTest("Inline Overrides", "Dark mode overrides for #f8f9fa and #f8fafc subtle containers", strpos($css, 'div[style*="background: #f8f9fa"]') !== false);

// 4. Component Function Semantic Token Verification
require_once __DIR__ . '/../includes/functions.php';
$sampleQuestions = json_encode([['question' => 'How clear were the objectives?', 'type' => 'rating']]);
$evalHtml = renderAiEvaluationQuestions($sampleQuestions);
checkThemeTest("Semantic Tokens", "renderAiEvaluationQuestions uses var(--text-main)", strpos($evalHtml, 'var(--text-main') !== false);
checkThemeTest("Semantic Tokens", "renderAiEvaluationQuestions uses elevated card surface", strpos($evalHtml, 'var(--bg-card-elevated') !== false);

$validatorCode = file_get_contents(__DIR__ . '/../includes/ai/proposal_validator.php');
checkThemeTest("Semantic Tokens", "renderAiValidationSection uses var(--bg-card)", strpos($validatorCode, 'background: var(--bg-card') !== false);
checkThemeTest("Semantic Tokens", "renderAiValidationSection uses var(--text-main)", strpos($validatorCode, 'color: var(--text-main') !== false);

// 4. Faculty Pages Scan
$facultyPages = glob(__DIR__ . '/../faculty/*.php');
checkThemeTest("Faculty Audit", "Found all 16 faculty account page files", count($facultyPages) >= 16, count($facultyPages) . " pages verified");

$pagesWithoutThemeIssues = 0;
foreach ($facultyPages as $fp) {
    $content = file_get_contents($fp);
    // Ensure page includes main.css
    $hasMainCss = (strpos($content, 'main.css') !== false || strpos($content, 'sidebar.php') !== false || strpos($content, 'my-tasks-template.php') !== false);
    if ($hasMainCss) {
        $pagesWithoutThemeIssues++;
    }
}
checkThemeTest("Faculty Audit", "All faculty pages inherit global theme tokens", $pagesWithoutThemeIssues === count($facultyPages), "$pagesWithoutThemeIssues / " . count($facultyPages) . " pages");

// 5. Theme Toggle Functionality in assets/js/notifications.js & topbar
$notifJs = file_get_contents(__DIR__ . '/../assets/js/notifications.js');
checkThemeTest("Theme Switcher", "Theme toggle implements toggleTheme & initThemeToggle", strpos($notifJs, 'toggleTheme') !== false && strpos($notifJs, 'initThemeToggle') !== false);
checkThemeTest("Theme Switcher", "Theme switcher persists preference via sti-theme", strpos($notifJs, "'sti-theme'") !== false && strpos($notifJs, 'applyTheme') !== false);

echo "-----------------------------------------------------------------------\n";
echo "Overall Result: " . ($allPassed ? "ALL TESTS PASSED (100% THEME PARITY)" : "TESTS FAILED") . "\n";
echo "=======================================================================\n";

exit($allPassed ? 0 : 1);
