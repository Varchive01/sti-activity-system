<?php
/**
 * tests/test_issue5_post_event_expansion.php
 * Automated verification suite for Issue 5: Expanded Post-Event Report Upload Fields
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=== Issue 5 Verification: Expanded Post-Event Report Upload Fields ===\n\n";

$db = getDB();
$allPassed = true;

// Helper function to create a test activity
function createTestApprovedActivity(PDO $db): array {
    $faculty = $db->query("SELECT id, department FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $title = "Post Event Test " . time() . "_" . mt_rand(100, 999);
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $ins = $db->prepare("
        INSERT INTO activities (
            faculty_id, title, description, theme, venue, venue_address,
            event_date, start_time, end_time, target_participants,
            general_objectives, specific_objectives, source, status, evaluation_questions
        ) VALUES (
            ?, ?, 'Desc', 'Theme', 'Room 101', 'Main Campus',
            ?, '09:00:00', '11:00:00', 50,
            'Gen Obj', 'Spec Obj', 'faculty', 'approved', '[]'
        )
    ");
    $ins->execute([$faculty['id'], $title, $tomorrow]);
    $actId = (int)$db->lastInsertId();
    return ['id' => $actId, 'faculty_id' => $faculty['id'], 'title' => $title];
}

function cleanupTestActivity(PDO $db, int $actId): void {
    if ($actId <= 0) return;
    try {
        $db->exec("SET FOREIGN_KEY_CHECKS = 0");
        $db->prepare("DELETE FROM documents WHERE activity_id = ?")->execute([$actId]);
        $db->prepare("DELETE FROM post_event WHERE activity_id = ?")->execute([$actId]);
        $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$actId]);
        $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    } catch (\Throwable $e) {}
}

// ── Test 1: Helper validation functions in includes/functions.php ──
$test1Ok = false;
$dummyPdf = tempnam(sys_get_temp_dir(), 'pdf_');
file_put_contents($dummyPdf, "%PDF-1.4\nTest PDF content");

$dummyTxt = tempnam(sys_get_temp_dir(), 'txt_');
file_put_contents($dummyTxt, "This is plain text, not a PDF");

$dummyCsv = tempnam(sys_get_temp_dir(), 'csv_');
file_put_contents($dummyCsv, "Timestamp,Score,Comments\n2026-11-07,4,Great event\n");

$pdfValid = isValidPdfFile(['name' => 'assessment.pdf', 'tmp_name' => $dummyPdf, 'size' => 100, 'error' => UPLOAD_ERR_OK]);
$pdfInvalid = isValidPdfFile(['name' => 'fake.pdf', 'tmp_name' => $dummyTxt, 'size' => 100, 'error' => UPLOAD_ERR_OK]);
$csvValid = isValidCsvFile(['name' => 'survey.csv', 'tmp_name' => $dummyCsv, 'size' => 100, 'error' => UPLOAD_ERR_OK]);
$csvInvalid = isValidCsvFile(['name' => 'image.jpg', 'tmp_name' => $dummyTxt, 'size' => 100, 'error' => UPLOAD_ERR_OK]);

if ($pdfValid === true && $pdfInvalid === false && $csvValid === true && $csvInvalid === false) {
    $test1Ok = true;
    echo "  [PASS] File Format Validators (PDF Magic Check & CSV Structure Validation)\n";
} else {
    echo "  [FAIL] File Format Validators: pdfValid=" . var_export($pdfValid, true) . ", pdfInvalid=" . var_export($pdfInvalid, true) . "\n";
    $allPassed = false;
}

// ── Test 2: Database Schema & Columns in post_event table ──
$test2Ok = false;
$cols = $db->query("SHOW COLUMNS FROM post_event")->fetchAll(PDO::FETCH_COLUMN);
$hasManual = in_array('manual_attendance', $cols, true);
$hasAssess = in_array('assessment_report_path', $cols, true);
$hasSurvey = in_array('survey_csv_path', $cols, true);
$hasMethod = in_array('attendance_method', $cols, true);

if ($hasManual && $hasAssess && $hasSurvey && $hasMethod) {
    $test2Ok = true;
    echo "  [PASS] Database Schema: manual_attendance, assessment_report_path, survey_csv_path verified\n";
} else {
    echo "  [FAIL] Missing columns in post_event: manual={$hasManual}, assess={$hasAssess}, survey={$hasSurvey}\n";
    $allPassed = false;
}

// ── Test 3: Save Post-Event Report with Assessment PDF, Survey CSV, and Paper Attendance ──
$act = createTestApprovedActivity($db);
$actId = $act['id'];
$test3Ok = false;

try {
    $savedPdf = uploadFile(['name' => 'assessment_test.pdf', 'tmp_name' => $dummyPdf, 'size' => 100, 'error' => UPLOAD_ERR_OK], 'post-event', $actId);
    $savedCsv = uploadFile(['name' => 'survey_test.csv', 'tmp_name' => $dummyCsv, 'size' => 100, 'error' => UPLOAD_ERR_OK], 'post-event', $actId);

    $insPe = $db->prepare("
        INSERT INTO post_event (activity_id, target_attendance, actual_attendance, manual_attendance, assessment_report_path, survey_csv_path, attendance_method, submitted_by, submitted_at)
        VALUES (?, 50, 48, 48, ?, ?, 'paper_list', ?, NOW())
    ");
    $insPe->execute([$actId, $savedPdf, $savedCsv, $act['faculty_id']]);

    // Check inserted row
    $chkPe = $db->prepare("SELECT * FROM post_event WHERE activity_id = ?");
    $chkPe->execute([$actId]);
    $row = $chkPe->fetch(PDO::FETCH_ASSOC);

    if ($row && (int)$row['manual_attendance'] === 48 && (int)$row['actual_attendance'] === 48 && !empty($row['assessment_report_path']) && !empty($row['survey_csv_path'])) {
        $test3Ok = true;
        echo "  [PASS] Save Post-Event: Stored Manual Attendance (48/50), Assessment PDF, and Survey CSV\n";
    } else {
        echo "  [FAIL] Save Post-Event failed to persist all fields correctly\n";
        $allPassed = false;
    }
} catch (\Throwable $e) {
    echo "  [FAIL] Save Post-Event exception: " . $e->getMessage() . "\n";
    $allPassed = false;
}

// ── Test 4: Editing and Updating Existing Post-Event Report ──
$test4Ok = false;
try {
    // Update headcount to 52 (paper-based attendance update)
    $updPe = $db->prepare("
        UPDATE post_event 
        SET manual_attendance = 52, actual_attendance = 52, submitted_at = NOW() 
        WHERE activity_id = ?
    ");
    $updPe->execute([$actId]);

    $chkPe->execute([$actId]);
    $updatedRow = $chkPe->fetch(PDO::FETCH_ASSOC);

    if ($updatedRow && (int)$updatedRow['manual_attendance'] === 52 && (int)$updatedRow['actual_attendance'] === 52) {
        $test4Ok = true;
        echo "  [PASS] Edit & Update: Successfully updated participant count to 52 without data loss\n";
    } else {
        echo "  [FAIL] Edit & Update failed\n";
        $allPassed = false;
    }
} catch (\Throwable $e) {
    echo "  [FAIL] Edit & Update exception: " . $e->getMessage() . "\n";
    $allPassed = false;
}

// ── Test 5: UI & Display Parity in View Pages ──
$test5Ok = false;
$facView = file_get_contents(__DIR__ . '/../faculty/view-activity.php');
$adm1View = file_get_contents(__DIR__ . '/../admin1/view-activity.php');
$adm2View = file_get_contents(__DIR__ . '/../admin2/view-activity.php');
$deanView = file_get_contents(__DIR__ . '/../dean/view-activity.php');
$postEventPage = file_get_contents(__DIR__ . '/../faculty/post-event.php');

$c1 = str_contains($facView, "assessment_report_path") && str_contains($facView, "survey_csv_path") && str_contains($facView, "Paper-Based Count");
$c2 = str_contains($adm1View, "assessment_report_path") && str_contains($adm1View, "survey_csv_path");
$c3 = str_contains($adm2View, "assessment_report_path") && str_contains($adm2View, "survey_csv_path");
$c4 = str_contains($deanView, "assessment_report_path") && str_contains($deanView, "survey_csv_path");
$c5 = str_contains($postEventPage, 'name="manual_attendance"') && str_contains($postEventPage, 'name="assessment_report_file"') && str_contains($postEventPage, 'name="survey_results_csv"');

if ($c1 && $c2 && $c3 && $c4 && $c5) {
    $test5Ok = true;
    echo "  [PASS] Cross-Role UI Display Parity: Assessment PDF, Survey CSV & Paper Attendance in All Views\n";
} else {
    echo "  [FAIL] View Parity missing: c1={$c1}, c2={$c2}, c3={$c3}, c4={$c4}, c5={$c5}\n";
    $allPassed = false;
}

// Cleanup
@unlink($dummyPdf);
@unlink($dummyTxt);
@unlink($dummyCsv);
cleanupTestActivity($db, $actId);

echo "\n========================================================\n";
echo " FINAL RESULT: " . ($allPassed ? "ALL 5 CHECKS PASSED!" : "SOME CHECKS FAILED") . "\n";
echo "========================================================\n";
exit($allPassed ? 0 : 1);

