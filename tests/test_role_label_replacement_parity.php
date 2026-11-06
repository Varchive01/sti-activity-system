<?php
/**
 * tests/test_role_label_replacement_parity.php
 *
 * Dedicated verification suite for the global replacement of:
 * - "Admin 1" -> "Student Organization Head"
 * - "Admin 2" -> "Events Committee Head"
 *
 * Checks:
 * 1. User Management role label table mapping
 * 2. User Management Add User modal select options & help text
 * 3. User Management Edit User modal select options & help text
 * 4. Topbar profile role title display mapping
 * 5. Admin 2 user management page title
 * 6. View-activity current approver labels
 * 7. Review action forwarder notes
 * 8. User API error messages
 * 9. Guided walkthrough step titles and descriptions
 */

echo "=======================================================================\n";
echo "    ROLE LABEL REPLACEMENT PARITY TEST SUITE (ADMIN 1 & ADMIN 2)       \n";
echo "=======================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertCheck(string $category, string $title, bool $condition, string $detail = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo sprintf(" [PASS] | %-20s | %-45s | %s\n", $category, $title, $detail ?: 'OK');
    } else {
        $failCount++;
        echo sprintf(" [FAIL] | %-20s | %-45s | %s\n", $category, $title, $detail ?: 'FAILED');
    }
}

// 1. User Management View
$umViewContent = file_get_contents(__DIR__ . '/../includes/user-management-view.php');

assertCheck(
    'User Management View',
    'Table roleLabels maps admin1 to Student Organization Head',
    str_contains($umViewContent, "'admin1'       => 'Student Organization Head'")
);

assertCheck(
    'User Management View',
    'Table roleLabels maps admin2 to Events Committee Head',
    str_contains($umViewContent, "'admin2'       => 'Events Committee Head'")
);

assertCheck(
    'User Management View',
    'Add modal option displays Student Organization Head',
    str_contains($umViewContent, '<option value="admin1">Student Organization Head</option>')
);

assertCheck(
    'User Management View',
    'Add modal option displays Events Committee Head',
    str_contains($umViewContent, '<option value="admin2">Events Committee Head</option>')
);

assertCheck(
    'User Management View',
    'Add modal Dean help text updated to new role titles',
    str_contains($umViewContent, 'Dean can create Faculty, Program Head, Student Organization Head, and Events Committee Head accounts.')
);

assertCheck(
    'User Management View',
    'Edit modal option displays Student Organization Head',
    str_contains($umViewContent, '<option value="admin1">Student Organization Head</option>')
);

assertCheck(
    'User Management View',
    'Edit modal option displays Events Committee Head',
    str_contains($umViewContent, '<option value="admin2">Events Committee Head</option>')
);

assertCheck(
    'User Management View',
    'Edit modal Dean help text updated to new role titles',
    str_contains($umViewContent, 'Dean can modify roles between Faculty, Program Head, Student Organization Head, and Events Committee Head.')
);

assertCheck(
    'User Management View',
    'No displayed "Admin 1" options or labels remaining',
    !preg_match('/>Admin 1<\/option>/', $umViewContent) && !preg_match("/'admin1'\s*=>\s*'Admin 1'/", $umViewContent)
);

assertCheck(
    'User Management View',
    'No displayed "Admin 2" options or labels remaining',
    !preg_match('/>Admin 2<\/option>/', $umViewContent) && !preg_match("/'admin2'\s*=>\s*'Admin 2'/", $umViewContent)
);

// 2. Topbar Profile Role Display
$topbarContent = file_get_contents(__DIR__ . '/../includes/topbar-profile.php');

assertCheck(
    'Topbar Profile',
    'Role display map includes Student Organization Head for admin1',
    str_contains($topbarContent, "'admin1'       => 'Student Organization Head'")
);

assertCheck(
    'Topbar Profile',
    'Role display map includes Events Committee Head for admin2',
    str_contains($topbarContent, "'admin2'       => 'Events Committee Head'")
);

// 3. Page Title & Current Approver
$admin2UsersContent = file_get_contents(__DIR__ . '/../admin2/users.php');
assertCheck(
    'Admin2 Users Page',
    'Page title updated to User Management – Events Committee Head',
    str_contains($admin2UsersContent, "\$pageTitle   = 'User Management – Events Committee Head';")
);

$a1ViewContent = file_get_contents(__DIR__ . '/../admin1/view-activity.php');
assertCheck(
    'Admin1 View Activity',
    'Current approver updated to Student Organization Head',
    str_contains($a1ViewContent, "'Student Organization Head (Committee Review)'")
);

$a2ViewContent = file_get_contents(__DIR__ . '/../admin2/view-activity.php');
assertCheck(
    'Admin2 View Activity',
    'Current approver updated to Student Organization Head / Events Committee Head',
    str_contains($a2ViewContent, "'Student Organization Head (Committee Review)'") &&
    str_contains($a2ViewContent, "'Events Committee Head (Review)'")
);

// 4. API Endpoints
$revActionContent = file_get_contents(__DIR__ . '/../api/review-action.php');
assertCheck(
    'Review Action API',
    'Notification forwarder note updated to Student Organization Head',
    str_contains($revActionContent, 'Student Organization Head (Committee Review)')
);

$userCreateContent = file_get_contents(__DIR__ . '/../api/user-create.php');
assertCheck(
    'User Create API',
    'Error message references Events Committee Head',
    str_contains($userCreateContent, 'Events Committee Head is not authorized to create administrative accounts.')
);

$userDeleteContent = file_get_contents(__DIR__ . '/../api/user-delete.php');
assertCheck(
    'User Delete API',
    'Error message references Events Committee Head',
    str_contains($userDeleteContent, 'Events Committee Head is authorized to delete Faculty accounts only.')
);

$userResetContent = file_get_contents(__DIR__ . '/../api/user-reset-password.php');
assertCheck(
    'User Reset API',
    'Error message references Events Committee Head',
    str_contains($userResetContent, 'Events Committee Head is not authorized to set passwords for administrative accounts.')
);

$userUpdateContent = file_get_contents(__DIR__ . '/../api/user-update.php');
assertCheck(
    'User Update API',
    'Error message references Events Committee Head',
    str_contains($userUpdateContent, 'Events Committee Head is not authorized to edit administrative accounts.') &&
    str_contains($userUpdateContent, 'Events Committee Head cannot promote accounts to administrative roles.')
);

// 5. Walkthrough JS
$wt1Content = file_get_contents(__DIR__ . '/../assets/js/walkthrough-admin1.js');
assertCheck(
    'Walkthrough Admin 1',
    'Step titles reference Student Organization Head',
    str_contains($wt1Content, 'Student Organization Head Navigation') &&
    str_contains($wt1Content, 'Student Organization Head Dashboard') &&
    str_contains($wt1Content, 'handled by Student Organization Head')
);

$wt2Content = file_get_contents(__DIR__ . '/../assets/js/walkthrough-admin2.js');
assertCheck(
    'Walkthrough Admin 2',
    'Step titles reference Events Committee Head',
    str_contains($wt2Content, 'Events Committee Head Navigation') &&
    str_contains($wt2Content, 'Events Committee Head Dashboard') &&
    str_contains($wt2Content, 'requiring Events Committee Head action') &&
    str_contains($wt2Content, 'handled by Events Committee Head')
);

echo "-----------------------------------------------------------------------\n";
echo sprintf("Summary: %d Passed, %d Failed (Total: %d)\n", $passCount, $failCount, $passCount + $failCount);
echo "=======================================================================\n";

if ($failCount > 0) {
    exit(1);
}
