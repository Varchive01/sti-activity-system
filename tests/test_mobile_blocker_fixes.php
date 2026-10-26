<?php
/**
 * Test Suite: Mobile Blocker Fixes Verification
 */

$results = [];

function check($testName, $passed, $details = '') {
    global $results;
    $results[] = [
        'name' => $testName,
        'passed' => $passed,
        'details' => $details
    ];
    echo ($passed ? "[PASS] " : "[FAIL] ") . $testName . ($details ? " ($details)" : "") . "\n";
}

// 1. Verify Priority 2: faculty/activities.php viewport
$activitiesContent = file_get_contents(__DIR__ . '/../faculty/activities.php');
check(
    "Priority 2: faculty/activities.php contains viewport meta tag",
    strpos($activitiesContent, '<meta name="viewport" content="width=device-width, initial-scale=1.0">') !== false,
    "Viewport meta tag found in head"
);

// 2. Verify Priority 1: wizard overflow in proposal-create.php, proposal-edit.php, and main.css
$mainCss = file_get_contents(__DIR__ . '/../assets/css/main.css');
$createContent = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');
$editContent = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');

check(
    "Priority 1: assets/css/main.css wizard-steps has overflow-x: auto and no overflow: hidden",
    preg_match('/\.wizard-steps\s*\{[^}]*overflow-x:\s*auto/', $mainCss) === 1 &&
    !preg_match('/\.wizard-steps\s*\{[^}]*overflow:\s*hidden/', $mainCss),
    "wizard-steps uses horizontal scroll containment"
);

check(
    "Priority 1: faculty/proposal-create.php wizard-steps has overflow-x: auto",
    preg_match('/\.wizard-steps\s*\{[^}]*overflow-x:\s*auto/s', $createContent) === 1,
    "proposal-create.php styles updated"
);

check(
    "Priority 1: faculty/proposal-edit.php wizard-steps has overflow-x: auto",
    preg_match('/\.wizard-steps\s*\{[^}]*overflow-x:\s*auto/s', $editContent) === 1,
    "proposal-edit.php styles updated"
);

// 3. Verify Priority 3: Dynamic tables horizontal scroll containment
check(
    "Priority 3: assets/css/main.css dynamic-table has overflow-x: auto",
    preg_match('/\.dynamic-table\s*\{[^}]*overflow-x:\s*auto/s', $mainCss) === 1,
    "main.css dynamic-table scroll enabled"
);

check(
    "Priority 3: faculty/proposal-create.php dynamic-table has overflow-x: auto and min-width on table",
    preg_match('/\.dynamic-table\s*\{[^}]*overflow-x:\s*auto/s', $createContent) === 1 &&
    strpos($createContent, '.dynamic-table table') !== false,
    "proposal-create.php table columns protected"
);

check(
    "Priority 3: faculty/proposal-edit.php dynamic-table has overflow-x: auto and min-width on table",
    preg_match('/\.dynamic-table\s*\{[^}]*overflow-x:\s*auto/s', $editContent) === 1 &&
    strpos($editContent, '.dynamic-table table') !== false,
    "proposal-edit.php table columns protected"
);

// 4. Verify Priority 4: Dean topbar and touch targets
$deanDashboard = file_get_contents(__DIR__ . '/../dean/dashboard.php');
$facultyDashboard = file_get_contents(__DIR__ . '/../faculty/dashboard.php');

check(
    "Priority 4: dean/dashboard.php topbar uses display: contents for 2-row layout",
    strpos($deanDashboard, '.topbar-right {') !== false &&
    strpos($deanDashboard, 'display: contents !important;') !== false,
    "Prevents 3-row wrapping"
);

check(
    "Priority 4: dean/dashboard.php filter inputs enlarged to ~42px touch targets",
    preg_match('/\.topbar-filter-form select\[name="year"\]\s*\{[^}]*height:\s*42px/s', $deanDashboard) === 1 &&
    preg_match('/\.topbar-filter-form select\[name="period"\]\s*\{[^}]*height:\s*42px/s', $deanDashboard) === 1,
    "42px touch target on mobile"
);

check(
    "Priority 4: faculty/dashboard.php activity action buttons enlarged to ~38px on mobile",
    preg_match('/\.activity-table td \.btn\s*\{[^}]*height:\s*38px/s', $facultyDashboard) === 1,
    "Mobile touch targets active"
);

check(
    "Priority 4: faculty/activities.php activity action buttons enlarged on mobile",
    preg_match('/\.activity-table td \.btn\s*\{[^}]*height:\s*38px/s', $activitiesContent) === 1,
    "Mobile touch targets active"
);

echo "\nVerification complete: " . count(array_filter($results, fn($r) => $r['passed'])) . " / " . count($results) . " passed.\n";
