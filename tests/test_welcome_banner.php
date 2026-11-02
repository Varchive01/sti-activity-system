<?php
/**
 * tests/test_welcome_banner.php
 *
 * Automated verification test suite for Task 17: Role-Specific Dashboard Welcome Banner.
 * Tests banner rendering, role configurations, button destinations, and permission gates.
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
        echo "  ✓ PASS: {$description}\n";
    } else {
        $failCount++;
        echo "  ✗ FAIL: {$description}" . ($details ? " ({$details})" : "") . "\n";
    }
}

echo "====================================================================\n";
echo "Task 17: Role-Specific Dashboard Welcome Banner Test Suite\n";
echo "====================================================================\n\n";

// Test 1: Verify background image asset
echo "--- 1. Background Asset Inspection ---\n";
$bgImagePath = __DIR__ . '/../assets/img/sti-marikina-building.jpg';
it("Background image exists at assets/img/sti-marikina-building.jpg", file_exists($bgImagePath));
it("Background image is non-empty (> 50KB)", file_exists($bgImagePath) && filesize($bgImagePath) > 50000);

// Helper function to render component in isolation
function renderWelcomeBanner(array $mockUser): string {
    $user = $mockUser;
    ob_start();
    include __DIR__ . '/../includes/dashboard-welcome-banner.php';
    return ob_get_clean();
}

// Test 2: Faculty Role Banner
echo "\n--- 2. Faculty Role Banner ---\n";
$facultyHtml = renderWelcomeBanner(['id' => 10, 'name' => 'Prof. Alan Turing', 'role' => 'faculty']);
it("Faculty banner contains 'FACULTY ACTIVITY PORTAL' label", strpos($facultyHtml, 'FACULTY ACTIVITY PORTAL') !== false);
it("Faculty banner heading contains 'Welcome back, Prof. Alan Turing!'", strpos($facultyHtml, 'Welcome back, Prof. Alan Turing!') !== false);
it("Faculty banner contains faculty description", strpos($facultyHtml, 'Manage your activity proposals, track their progress, and complete post-event requirements.') !== false);
it("Faculty banner has 'New Activity Proposal' button", strpos($facultyHtml, 'New Activity Proposal') !== false);
it("Faculty 'New Activity Proposal' links to /faculty/proposal-create.php", strpos($facultyHtml, '/faculty/proposal-create.php') !== false);
it("Faculty banner has 'Calendar' button", strpos($facultyHtml, 'Calendar') !== false);
it("Faculty 'Calendar' links to /faculty/calendar.php", strpos($facultyHtml, '/faculty/calendar.php') !== false);

// Test 2b: Permission Restriction Enforcement
$user = ['id' => 11, 'name' => 'Restricted Faculty', 'role' => 'faculty'];
$canCreateProposal = false;
ob_start();
include __DIR__ . '/../includes/dashboard-welcome-banner.php';
$restrictedFacultyHtml = ob_get_clean();
it("Faculty without proposal permission does NOT receive 'New Activity Proposal' button", strpos($restrictedFacultyHtml, 'New Activity Proposal') === false);

// Test 3: Program Head Role Banner
echo "\n--- 3. Program Head Role Banner ---\n";
$phHtml = renderWelcomeBanner(['id' => 20, 'name' => 'Dr. Ada Lovelace', 'role' => 'program_head', 'dept' => 'IT Department']);
it("Program Head banner contains 'PROGRAM HEAD PORTAL' label", strpos($phHtml, 'PROGRAM HEAD PORTAL') !== false);
it("Program Head banner heading contains 'Welcome back, Dr. Ada Lovelace!'", strpos($phHtml, 'Welcome back, Dr. Ada Lovelace!') !== false);
it("Program Head banner contains program head description", strpos(html_entity_decode($phHtml, ENT_QUOTES), "Oversee your department's activities, monitor proposal progress, and manage departmental tasks.") !== false);
it("Program Head banner has 'Department Activities' button", strpos($phHtml, 'Department Activities') !== false);
it("Program Head 'Department Activities' links to /faculty/activities.php", strpos($phHtml, '/faculty/activities.php') !== false);
it("Program Head banner has 'New Activity Proposal' button (authorized)", strpos($phHtml, 'New Activity Proposal') !== false);
it("Program Head 'New Activity Proposal' links to /faculty/proposal-create.php", strpos($phHtml, '/faculty/proposal-create.php') !== false);
it("Program Head banner has 'Calendar' button", strpos($phHtml, 'Calendar') !== false);
it("Program Head 'Calendar' links to /faculty/calendar.php", strpos($phHtml, '/faculty/calendar.php') !== false);

// Test 4: Admin 1 Role Banner
echo "\n--- 4. Admin 1 Role Banner ---\n";
$admin1Html = renderWelcomeBanner(['id' => 30, 'name' => 'Sir Ar-jay Agbayani', 'role' => 'admin1']);
it("Admin 1 banner contains 'SYSTEM ADMINISTRATION PORTAL' label", strpos($admin1Html, 'SYSTEM ADMINISTRATION PORTAL') !== false);
it("Admin 1 banner heading contains 'Welcome back, Sir Ar-jay Agbayani!'", strpos($admin1Html, 'Welcome back, Sir Ar-jay Agbayani!') !== false);
it("Admin 1 banner contains admin1 description", strpos($admin1Html, 'Manage system users, maintain access permissions, and oversee STI-ARMES configuration.') !== false);
it("Admin 1 banner has 'Pending Reviews' button", strpos($admin1Html, 'Pending Reviews') !== false);
it("Admin 1 'Pending Reviews' links to /admin1/pending.php", strpos($admin1Html, '/admin1/pending.php') !== false);
it("Admin 1 banner has 'Calendar' button", strpos($admin1Html, 'Calendar') !== false);
it("Admin 1 'Calendar' links to /admin1/calendar.php", strpos($admin1Html, '/admin1/calendar.php') !== false);
it("Admin 1 banner DOES NOT contain 'New Activity Proposal'", strpos($admin1Html, 'New Activity Proposal') === false);
it("Admin 1 banner buttons DO NOT cancel navigation with return false", strpos($admin1Html, 'return false') === false);

// Test 5: Admin 2 Role Banner
echo "\n--- 5. Admin 2 Role Banner ---\n";
$admin2Html = renderWelcomeBanner(['id' => 40, 'name' => 'Sir Ian Barangan', 'role' => 'admin2']);
it("Admin 2 banner contains 'ADMINISTRATIVE MANAGEMENT PORTAL' label", strpos($admin2Html, 'ADMINISTRATIVE MANAGEMENT PORTAL') !== false);
it("Admin 2 banner heading contains 'Welcome back, Sir Ian Barangan!'", strpos($admin2Html, 'Welcome back, Sir Ian Barangan!') !== false);
it("Admin 2 banner contains admin2 description", strpos($admin2Html, 'Manage activity proposals, coordinate administrative processing, and monitor activity schedules.') !== false);
it("Admin 2 banner has 'Proposal Management' button", strpos($admin2Html, 'Proposal Management') !== false);
it("Admin 2 'Proposal Management' links to /admin2/activities.php", strpos($admin2Html, '/admin2/activities.php') !== false);
it("Admin 2 banner has 'Calendar' button", strpos($admin2Html, 'Calendar') !== false);
it("Admin 2 'Calendar' links to /admin2/calendar.php", strpos($admin2Html, '/admin2/calendar.php') !== false);
it("Admin 2 banner DOES NOT contain 'New Activity Proposal'", strpos($admin2Html, 'New Activity Proposal') === false);

// Test 6: Dean Role Banner
echo "\n--- 6. Dean Role Banner ---\n";
$deanHtml = renderWelcomeBanner(['id' => 50, 'name' => 'Dean Ferdinand Dela Cruz', 'role' => 'dean']);
it("Dean banner contains 'DEAN\'S MANAGEMENT PORTAL' label", strpos(html_entity_decode($deanHtml, ENT_QUOTES), "DEAN'S MANAGEMENT PORTAL") !== false);
it("Dean banner heading contains 'Welcome back, Dean Ferdinand Dela Cruz!'", strpos($deanHtml, 'Welcome back, Dean Ferdinand Dela Cruz!') !== false);
it("Dean banner contains dean description", strpos($deanHtml, 'Review activity proposals, oversee institutional activities, and monitor departmental performance.') !== false);
it("Dean banner has 'Pending Approvals' button", strpos($deanHtml, 'Pending Approvals') !== false);
it("Dean 'Pending Approvals' links to /dean/pending.php", strpos($deanHtml, '/dean/pending.php') !== false);
it("Dean banner has 'Calendar' button", strpos($deanHtml, 'Calendar') !== false);
it("Dean 'Calendar' links to /dean/calendar.php", strpos($deanHtml, '/dean/calendar.php') !== false);
it("Dean banner DOES NOT contain 'New Activity Proposal'", strpos($deanHtml, 'New Activity Proposal') === false);

// Test 7: CSS & Responsive Styling
echo "\n--- 7. CSS & Responsive Styling ---\n";
it("Banner CSS includes dark mode background rule", strpos($facultyHtml, '[data-theme="dark"] .dashboard-hero-banner') !== false);
it("Banner CSS includes tablet breakpoint (max-width: 900px)", strpos($facultyHtml, '@media (max-width: 900px)') !== false);
it("Banner CSS includes mobile breakpoint (max-width: 480px)", strpos($facultyHtml, '@media (max-width: 480px)') !== false);
it("Banner CSS includes subtle mask-image gradient overlay", strpos($facultyHtml, 'mask-image:') !== false);
it("Banner CSS includes focus-visible rules for keyboard accessibility", strpos($facultyHtml, ':focus-visible') !== false);

// Test 8: Dashboard File Inclusions
echo "\n--- 8. Dashboard File Integration ---\n";
$filesToCheck = [
    'faculty/dashboard.php',
    'program_head/dashboard.php',
    'admin1/dashboard.php',
    'admin2/dashboard.php',
    'dean/dashboard.php'
];
foreach ($filesToCheck as $file) {
    $content = file_get_contents(__DIR__ . '/../' . $file);
    it("{$file} includes dashboard-welcome-banner.php", strpos($content, 'includes/dashboard-welcome-banner.php') !== false);
}

echo "\n====================================================================\n";
echo "SUMMARY: {$passCount}/{$testCount} tests passed. ({$failCount} failed)\n";
echo "====================================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
