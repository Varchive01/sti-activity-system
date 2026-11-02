<?php
/**
 * tests/test_admin1_banner_destinations.php
 *
 * Dedicated test script for Admin 1 Welcome Banner Destinations & Authorization Verification.
 */

require_once __DIR__ . '/../config/config.php';

$testCount = 0;
$passCount = 0;
$failCount = 0;

function it(string $description, bool $condition, string $details = '') {
    global $testCount, $passCount, $failCount;
    $testCount++;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$description}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$description}" . ($details ? " ({$details})" : "") . "\n";
    }
}

echo "====================================================================\n";
echo "Admin 1 Banner Destinations & Security Audit Test Suite\n";
echo "====================================================================\n\n";

// 1. Render Admin 1 banner
echo "--- 1. Admin 1 Banner Rendering & Button Destinations ---\n";
$user = [
    'id'    => 3,
    'name'  => 'Sir Ar-jay Agbayani',
    'role'  => 'admin1',
    'email' => 'ar-jay.agbayani@marikina.sti.edu.ph',
];

ob_start();
include __DIR__ . '/../includes/dashboard-welcome-banner.php';
$bannerHtml = ob_get_clean();

it("Banner renders without errors", !empty($bannerHtml));
it("Banner heading greets Sir Ar-jay Agbayani", str_contains($bannerHtml, 'Sir Ar-jay Agbayani'));
it("Banner contains 'Pending Reviews' primary button", str_contains($bannerHtml, 'Pending Reviews'));
it("Banner links 'Pending Reviews' to /admin1/pending.php", str_contains($bannerHtml, '/admin1/pending.php'));
it("Banner contains 'Calendar' secondary button", str_contains($bannerHtml, 'Calendar'));
it("Banner links 'Calendar' to /admin1/calendar.php", str_contains($bannerHtml, '/admin1/calendar.php'));
it("Banner DOES NOT link to /admin2/users.php", !str_contains($bannerHtml, '/admin2/users.php'));
it("Banner DOES NOT contain dead hash /admin1/dashboard.php#system-settings", !str_contains($bannerHtml, '#system-settings'));

// 2. Destination Files Physical Existence
echo "\n--- 2. Destination Files Physical Verification ---\n";
$pendingFile = __DIR__ . '/../admin1/pending.php';
$calendarFile = __DIR__ . '/../admin1/calendar.php';

it("Target file admin1/pending.php physically exists", file_exists($pendingFile));
it("Target file admin1/calendar.php physically exists", file_exists($calendarFile));

// 3. Authorization Guards in Target Files
echo "\n--- 3. Target File Server-Side Role Enforcement ---\n";
$pendingContent = file_get_contents($pendingFile);
$calendarContent = file_get_contents($calendarFile);

it("admin1/pending.php enforces requireRole('admin1')", str_contains($pendingContent, "requireRole('admin1')"));
it("admin1/calendar.php enforces requireRole('admin1')", str_contains($calendarContent, "requireRole('admin1')"));

// 4. Sidebar Consistency Verification
echo "\n--- 4. Sidebar Navigation Consistency ---\n";
$sidebarFile = __DIR__ . '/../includes/sidebar.php';
$sidebarContent = file_get_contents($sidebarFile);

// Extract admin1 section from $sidebarContent
$admin1Section = '';
if (preg_match("/'admin1'\s*=>\s*\[(.*?)'admin2'\s*=>/s", $sidebarContent, $matches)) {
    $admin1Section = $matches[1];
}

it("Admin 1 sidebar defines Pending Reviews (/admin1/pending.php)", str_contains($admin1Section, "'/admin1/pending.php'"));
it("Admin 1 sidebar defines Calendar (/admin1/calendar.php)", str_contains($admin1Section, "'/admin1/calendar.php'"));
it("Admin 1 sidebar does NOT contain User Management", !str_contains($admin1Section, 'User Management'));
it("Admin 1 sidebar does NOT contain System Settings", !str_contains($admin1Section, 'System Settings'));

// 5. User Management Access Restriction Audit
echo "\n--- 5. User Management Access Restriction Audit ---\n";
$admin2UsersContent = file_get_contents(__DIR__ . '/../admin2/users.php');
$deanUsersContent   = file_get_contents(__DIR__ . '/../dean/users.php');
$userCreateContent  = file_get_contents(__DIR__ . '/../api/user-create.php');
$userUpdateContent  = file_get_contents(__DIR__ . '/../api/user-update.php');
$userDeleteContent  = file_get_contents(__DIR__ . '/../api/user-delete.php');
$userResetContent   = file_get_contents(__DIR__ . '/../api/user-reset-password.php');

it("admin2/users.php strictly requires admin2 (rejects admin1)", str_contains($admin2UsersContent, "requireRole('admin2')"));
it("dean/users.php strictly requires dean (rejects admin1)", str_contains($deanUsersContent, "requireRole('dean')"));
it("api/user-create.php restricts to dean and admin2 (rejects admin1)", str_contains($userCreateContent, "requireRole('dean', 'admin2')"));
it("api/user-update.php restricts to dean and admin2 (rejects admin1)", str_contains($userUpdateContent, "requireRole('dean', 'admin2')"));
it("api/user-delete.php restricts to dean and admin2 (rejects admin1)", str_contains($userDeleteContent, "requireRole('dean', 'admin2')"));
it("api/user-reset-password.php restricts to dean and admin2 (rejects admin1)", str_contains($userResetContent, "requireRole('dean', 'admin2')"));

echo "\n====================================================================\n";
echo "SUMMARY: {$passCount}/{$testCount} tests passed. ({$failCount} failed)\n";
echo "====================================================================\n";
