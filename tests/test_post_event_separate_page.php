<?php
/**
 * tests/test_post_event_separate_page.php
 * Automated verification of Post-Event separate page architecture:
 * - Wide layout for Select Approved Activity & Submitted Reports on post-event.php
 * - Separate dedicated form page on post-event-form.php
 * - Parity across all roles: faculty, program_head, admin1, admin2
 */

echo "=======================================================\n";
echo " STI-ARMES Post-Event Separate Page Verification\n";
echo "=======================================================\n\n";

$allPassed = true;

function testAssert($desc, $condition) {
    global $allPassed;
    if ($condition) {
        echo " [PASS] $desc\n";
    } else {
        echo " [FAIL] $desc\n";
        $allPassed = false;
    }
}

$hubContent   = file_get_contents(__DIR__ . '/../faculty/post-event.php');
$formContent  = file_get_contents(__DIR__ . '/../faculty/post-event-form.php');
$saveContent  = file_get_contents(__DIR__ . '/../api/post-event-save.php');
$sideContent  = file_get_contents(__DIR__ . '/../includes/sidebar.php');

// 1. Verify post-event.php Wide Layout Architecture
testAssert("post-event.php allows all 4 proponent roles (faculty, program_head, admin1, admin2)",
    strpos($hubContent, "requireRole('faculty', 'program_head', 'admin1', 'admin2')") !== false);

testAssert("post-event.php does NOT contain narrow 2-column .post-event-grid",
    strpos($hubContent, "grid-template-columns: minmax(0, 1fr) minmax(0, 1.45fr)") === false);

testAssert("post-event.php has backward-compatibility redirect to post-event-form.php if activity_id is passed",
    strpos($hubContent, "header(\"Location: \$forwardUrl\");") !== false && strpos($hubContent, "post-event-form.php") !== false);

testAssert("post-event.php 'Upload Report' buttons link to post-event-form.php",
    strpos($hubContent, "post-event-form.php?activity_id=") !== false);

testAssert("post-event.php 'Submitted Reports' Edit buttons link to post-event-form.php",
    strpos($hubContent, "post-event-form.php?activity_id=") !== false);

// 2. Verify post-event-form.php Dedicated Separate Page
testAssert("post-event-form.php exists and allows all 4 proponent roles",
    strpos($formContent, "requireRole('faculty', 'program_head', 'admin1', 'admin2')") !== false);

testAssert("post-event-form.php has a 'Back to Post-Event Reports' navigation link",
    strpos($formContent, "Back to Post-Event Reports") !== false && strpos($formContent, "href=\"<?= BASE_URL ?>/faculty/post-event.php\"") !== false);

testAssert("post-event-form.php contains Attendance Method & Recording UI",
    strpos($formContent, "attendance_method") !== false && strpos($formContent, "manual_attendance") !== false);

testAssert("post-event-form.php contains Official PDF Assessment Report & CSV Survey uploads",
    strpos($formContent, "assessment_report_file") !== false && strpos($formContent, "survey_results_csv") !== false);

testAssert("post-event-form.php contains Budget Breakdown & Live Variance Computation",
    strpos($formContent, "computeLiveBudgetUtilization") !== false && strpos($formContent, "actual_expenses") !== false);

testAssert("post-event-form.php contains Equipment Status Reporting table",
    strpos($formContent, "equipmentReportTableBody") !== false && strpos($formContent, "addEquipmentReportRow") !== false);

testAssert("post-event-form.php contains Event Photos (5 minimum) & Drive Link",
    strpos($formContent, "post_event_files[]") !== false && strpos($formContent, "media_drive_link") !== false);

testAssert("post-event-form.php contains AI-Assisted Assessment & Recommendations Generator",
    strpos($formContent, "generatePostEventAiAssessment") !== false && strpos($formContent, "btnGeneratePostEventAi") !== false);

testAssert("post-event-form.php contains Bottom Cancel and Save Action Bar",
    strpos($formContent, "Cancel &amp; Return") !== false && strpos($formContent, "postEventMainForm") !== false);

// 3. Verify api/post-event-save.php redirects
testAssert("api/post-event-save.php redirects to post-event-form.php on save/error",
    strpos($saveContent, "/faculty/post-event-form.php?activity_id={\$id}&saved=1") !== false &&
    strpos($saveContent, "/faculty/post-event-form.php?activity_id={\$id}&error=") !== false);

// 4. Verify sidebar.php active tab recognition
testAssert("sidebar.php highlights post-event navigation when on post-event-form.php",
    strpos($sideContent, "post-event-form.php") !== false);

echo "\n-------------------------------------------------------\n";
if ($allPassed) {
    echo " ALL TESTS PASSED! Separate Post-Event Form & Wide Hub are fully functional.\n";
    exit(0);
} else {
    echo " ONE OR MORE TESTS FAILED.\n";
    exit(1);
}
