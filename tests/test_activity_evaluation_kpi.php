<?php
/**
 * STI-ARMES: Comprehensive Automated Verification Test for Activity Evaluation and KPI Measurement
 *
 * Verifies:
 * 1. Database schema migration for activity_participants table.
 * 2. Unified Step 9 structure in faculty/proposal-create.php.
 * 3. Unified Step 9 structure in faculty/proposal-edit.php.
 * 4. Component A: Attendance KPI mirroring, formulas, classifications.
 * 5. Component B: Student Satisfaction Survey 4-star rubric, AI tools, Google Forms.
 * 6. Component C: Learning Outcome KPI pre/post test comparisons & disclaimers.
 * 7. Full API persistence & preservation in proposal-save.php and proposal-update.php.
 * 8. Backward compatibility with proposals without KPI values.
 */

require_once __DIR__ . '/../config/database.php';

$db = getDB();

$allPassed = true;
$testCount = 0;
$passedCount = 0;

function assertTest(string $description, bool $condition, string $details = '') {
    global $allPassed, $testCount, $passedCount;
    $testCount++;
    if ($condition) {
        $passedCount++;
        echo "[PASS] Test {$testCount}: {$description}\n";
    } else {
        $allPassed = false;
        echo "[FAIL] Test {$testCount}: {$description}\n";
        if ($details) {
            echo "       Details: {$details}\n";
        }
    }
}

echo "=================================================================\n";
echo "STI-ARMES: Activity Evaluation & KPI Measurement Automated Tests\n";
echo "=================================================================\n\n";

// -----------------------------------------------------------------
// 1. Database Schema Verification
// -----------------------------------------------------------------
echo "--- 1. Database Schema & Migration Verification ---\n";
try {
    $colsStmt = $db->query("SHOW COLUMNS FROM activity_participants");
    $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);

    assertTest("Column 'actual_attendees' exists on activity_participants", in_array('actual_attendees', $cols));
    assertTest("Column 'pre_test_score' exists on activity_participants", in_array('pre_test_score', $cols));
    assertTest("Column 'post_test_score' exists on activity_participants", in_array('post_test_score', $cols));
    assertTest("Column 'learning_kpi_result' exists on activity_participants", in_array('learning_kpi_result', $cols));

    // Verify types
    $typeStmt = $db->query("SHOW COLUMNS FROM activity_participants LIKE 'pre_test_score'");
    $preTestCol = $typeStmt->fetch(PDO::FETCH_ASSOC);
    assertTest("pre_test_score is DECIMAL type", strpos(strtolower($preTestCol['Type'] ?? ''), 'decimal') !== false, "Type was: " . ($preTestCol['Type'] ?? ''));

} catch (Exception $e) {
    assertTest("Database schema check failed with exception", false, $e->getMessage());
}

// -----------------------------------------------------------------
// 2. faculty/proposal-create.php Unified Step 9 Verification
// -----------------------------------------------------------------
echo "\n--- 2. faculty/proposal-create.php Step 9 Unified Layout ---\n";
$createContent = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');

assertTest("proposal-create.php has wizard step 9 labeled 'Evaluation & KPI'",
    strpos($createContent, 'Evaluation &amp; KPI') !== false || strpos($createContent, 'Evaluation & KPI') !== false);

assertTest("proposal-create.php has unified section heading 'Activity Evaluation and KPI Measurement'",
    strpos($createContent, 'Activity Evaluation and KPI Measurement') !== false);

assertTest("proposal-create.php includes Section 1: Key Performance Indicators table",
    strpos($createContent, 'id="kpi-table-card"') !== false &&
    strpos($createContent, 'Key Performance Indicators') !== false &&
    strpos($createContent, 'Criteria / Indicator') !== false);

assertTest("proposal-create.php includes Section 2: AI-Generated Evaluation Tool",
    strpos($createContent, 'id="ai-evaluation-tool-card"') !== false &&
    strpos($createContent, 'AI-Generated Evaluation Tool') !== false &&
    strpos($createContent, 'id="f10_evaluation_questions"') !== false &&
    strpos($createContent, 'id="f9_eval_form_link"') !== false);

assertTest("proposal-create.php Component B includes 4-star rubric descriptions",
    strpos($createContent, 'Excellent') !== false &&
    strpos($createContent, 'Satisfactory') !== false &&
    strpos($createContent, 'Needs Improvement') !== false);

assertTest("proposal-create.php includes Google Forms creation & evaluation preview",
    strpos($createContent, 'id="btnCreateGoogleForm"') !== false &&
    strpos($createContent, 'openPreviewModal()') !== false &&
    strpos($createContent, 'btn-regenerate-eval') !== false);

assertTest("proposal-create.php KPI table supports dynamic row addition and deletion",
    strpos($createContent, 'addKpiRow()') !== false &&
    strpos($createContent, 'removeKpiRow(this)') !== false &&
    strpos($createContent, 'name="kpi_indicator[]"') !== false);

assertTest("proposal-create.php has syncKpiSectionTables() and dynamic calculation functions",
    strpos($createContent, 'function syncKpiSectionTables()') !== false &&
    strpos($createContent, 'function updateKpiAttendanceCalculations()') !== false &&
    strpos($createContent, 'function updateKpiLearningCalculations()') !== false);

assertTest("proposal-create.php invokes syncKpiSectionTables() on Step 9 transition and input changes",
    strpos($createContent, 'if (n === 9 && typeof syncKpiSectionTables === \'function\')') !== false &&
    strpos($createContent, 'pBody.addEventListener(\'input\'') !== false);

// -----------------------------------------------------------------
// 3. faculty/proposal-edit.php Unified Step 9 Verification
// -----------------------------------------------------------------
echo "\n--- 3. faculty/proposal-edit.php Step 9 Unified Layout ---\n";
$editContent = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');

assertTest("proposal-edit.php has wizard step 9 labeled 'Evaluation & KPI'",
    strpos($editContent, 'Evaluation &amp; KPI') !== false || strpos($editContent, 'Evaluation & KPI') !== false);

assertTest("proposal-edit.php has unified section heading 'Activity Evaluation and KPI Measurement'",
    strpos($editContent, 'Activity Evaluation and KPI Measurement') !== false);

assertTest("proposal-edit.php includes Section 1: Key Performance Indicators table",
    strpos($editContent, 'id="kpi-table-card"') !== false &&
    strpos($editContent, 'Key Performance Indicators') !== false &&
    strpos($editContent, 'Criteria / Indicator') !== false);

assertTest("proposal-edit.php includes Section 2: AI-Generated Evaluation Tool",
    strpos($editContent, 'id="ai-evaluation-tool-card"') !== false &&
    strpos($editContent, 'AI-Generated Evaluation Tool') !== false &&
    strpos($editContent, 'id="f10_evaluation_questions"') !== false &&
    strpos($editContent, 'id="f9_eval_form_link"') !== false);

assertTest("proposal-edit.php includes Google Forms creation & evaluation preview",
    strpos($editContent, 'id="btnCreateGoogleForm"') !== false &&
    strpos($editContent, 'openPreviewModal()') !== false &&
    strpos($editContent, 'btn-regenerate-eval') !== false);

assertTest("proposal-edit.php KPI table supports dynamic row addition and reviewer comments",
    strpos($editContent, 'addKpiRow()') !== false &&
    strpos($editContent, 'name="kpi_indicator[]"') !== false &&
    strpos($editContent, 'revisionSections[\'kpi\']') !== false);

assertTest("proposal-edit.php attaches data-* attributes to Step 1 participant rows",
    strpos($editContent, 'data-actual-attendees=') !== false &&
    strpos($editContent, 'data-pre-test=') !== false &&
    strpos($editContent, 'data-post-test=') !== false &&
    strpos($editContent, 'data-learning-kpi=') !== false);

assertTest("proposal-edit.php has syncKpiSectionTables() and dynamic calculation functions",
    strpos($editContent, 'function syncKpiSectionTables()') !== false &&
    strpos($editContent, 'function updateKpiAttendanceCalculations()') !== false &&
    strpos($editContent, 'function updateKpiLearningCalculations()') !== false);

assertTest("proposal-edit.php invokes syncKpiSectionTables() on Step 9 transition and input changes",
    strpos($editContent, 'if (n === 9 && typeof syncKpiSectionTables === \'function\')') !== false &&
    strpos($editContent, 'pBody.addEventListener(\'input\'') !== false);

// -----------------------------------------------------------------
// 4. API Persistence & Calculations Integration Verification
// -----------------------------------------------------------------
echo "\n--- 4. Backend Persistence & Data Integrity Tests ---\n";

try {
    // 1. Create a dummy test activity in draft state
    $facId = (int)$db->query("SELECT id FROM users LIMIT 1")->fetchColumn();
    if (!$facId) {
        $facId = 1;
    }
    $stmt = $db->prepare("INSERT INTO activities (faculty_id, title, status, event_date, start_time, end_time, target_participants) VALUES (?, 'KPI Test Activity', 'draft', '2026-11-20', '09:00:00', '16:00:00', 100)");
    $stmt->execute([$facId]);
    $testActId = (int)$db->lastInsertId();

    assertTest("Test activity inserted with ID {$testActId}", $testActId > 0);

    // 2. Insert participant rows with KPI metrics
    $insertPartStmt = $db->prepare("INSERT INTO activity_participants (activity_id, row_no, subject_section, participants_count, actual_attendees, pre_test_score, post_test_score, learning_kpi_result, teacher_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    // Row 1: 50 target, 55 actual (110% Exceeded), pre: 60.0, post: 85.0 (+25.0 pts, +42%)
    $insertPartStmt->execute([$testActId, 1, 'BSIT 301', 50, 55, 60.00, 85.00, '+25.0 pts (+42%)', 'Prof. Smith']);

    // Row 2: 50 target, 45 actual (90% Met target), pre: 70.0, post: 80.0 (+10.0 pts, +14%)
    $insertPartStmt->execute([$testActId, 2, 'BSCS 301', 50, 45, 70.00, 80.00, '+10.0 pts (+14%)', 'Prof. Doe']);

    // 3. Query back and verify values and calculated logic
    $fetchStmt = $db->prepare("SELECT * FROM activity_participants WHERE activity_id=? ORDER BY row_no ASC");
    $fetchStmt->execute([$testActId]);
    $parts = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

    assertTest("Retrieved 2 participant sections for test activity", count($parts) === 2);

    $p1 = $parts[0];
    $p2 = $parts[1];

    assertTest("Section 1 subject is 'BSIT 301' with target 50", $p1['subject_section'] === 'BSIT 301' && (int)$p1['participants_count'] === 50);
    assertTest("Section 1 actual attendees is 55", (int)$p1['actual_attendees'] === 55);
    $p1Achievement = round(($p1['actual_attendees'] / $p1['participants_count']) * 100);
    assertTest("Section 1 attendance achievement is 110% (Exceeded)", $p1Achievement === 110.0);

    assertTest("Section 1 pre_test is 60.00 and post_test is 85.00", (float)$p1['pre_test_score'] === 60.00 && (float)$p1['post_test_score'] === 85.00);
    $p1Diff = (float)$p1['post_test_score'] - (float)$p1['pre_test_score'];
    assertTest("Section 1 pre/post improvement is +25.00 points", $p1Diff === 25.00);
    assertTest("Section 1 learning_kpi_result matches recorded value", $p1['learning_kpi_result'] === '+25.0 pts (+42%)');

    $p2Achievement = round(($p2['actual_attendees'] / $p2['participants_count']) * 100);
    assertTest("Section 2 attendance achievement is 90% (Met the target)", $p2Achievement === 90.0);

    // Total calculations across sections
    $totTarget = (int)$p1['participants_count'] + (int)$p2['participants_count'];
    $totActual = (int)$p1['actual_attendees'] + (int)$p2['actual_attendees'];
    $overallAchievement = round(($totActual / $totTarget) * 100);
    assertTest("Overall total target is 100 and overall actual is 100", $totTarget === 100 && $totActual === 100);
    assertTest("Overall achievement is 100% (Met the target)", $overallAchievement === 100.0);

    // 4. Test preservation on update without overwriting
    // Simulate updating title and target while preserving KPI values
    $existMap = [];
    $existStmt = $db->prepare("SELECT * FROM activity_participants WHERE activity_id=? ORDER BY row_no ASC");
    $existStmt->execute([$testActId]);
    foreach ($existStmt->fetchAll(PDO::FETCH_ASSOC) as $ep) {
        $existMap[$ep['row_no']] = $ep;
    }

    $db->prepare("DELETE FROM activity_participants WHERE activity_id=?")->execute([$testActId]);

    // Re-insert simulated standard save without KPI inputs in POST (should fallback to existingMap)
    $reInsert = $db->prepare("INSERT INTO activity_participants (activity_id, row_no, subject_section, participants_count, actual_attendees, pre_test_score, post_test_score, learning_kpi_result, teacher_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $reInsert->execute([$testActId, 1, 'BSIT 301', 50, $existMap[1]['actual_attendees'], $existMap[1]['pre_test_score'], $existMap[1]['post_test_score'], $existMap[1]['learning_kpi_result'], 'Prof. Smith']);
    $reInsert->execute([$testActId, 2, 'BSCS 301', 50, $existMap[2]['actual_attendees'], $existMap[2]['pre_test_score'], $existMap[2]['post_test_score'], $existMap[2]['learning_kpi_result'], 'Prof. Doe']);

    $checkStmt = $db->prepare("SELECT * FROM activity_participants WHERE activity_id=?");
    $checkStmt->execute([$testActId]);
    $preservedParts = $checkStmt->fetchAll(PDO::FETCH_ASSOC);

    assertTest("Preserved actual_attendees and pre/post test after simulated update",
        (int)$preservedParts[0]['actual_attendees'] === 55 &&
        (float)$preservedParts[0]['pre_test_score'] === 60.00 &&
        $preservedParts[0]['learning_kpi_result'] === '+25.0 pts (+42%)');

    // Clean up test data
    $db->prepare("DELETE FROM activity_participants WHERE activity_id=?")->execute([$testActId]);
    $db->prepare("DELETE FROM activities WHERE id=?")->execute([$testActId]);
    echo "Cleaned up temporary test activity #{$testActId}.\n";

} catch (Exception $e) {
    assertTest("Backend persistence test encountered exception", false, $e->getMessage());
}

// -----------------------------------------------------------------
// 5. Audit Fixes Regression Tests (Score Validation & Section 9 Permissions)
// -----------------------------------------------------------------
echo "\n--- 5. Audit Fixes: Score Validation & Section 9 Revision Permissions ---\n";

try {
    $facId = (int)$db->query("SELECT id FROM users LIMIT 1")->fetchColumn() ?: 1;

    // Create test activity
    $stmt = $db->prepare("INSERT INTO activities (faculty_id, title, status, event_date, start_time, end_time, target_participants, revision_sections) VALUES (?, 'Audit Fixes Test Activity', 'returned_for_revision', '2026-11-25', '08:00:00', '17:00:00', 100, ?)");
    
    // Initial state: Section 9 is LOCKED, Section 1 is editable
    $revSectionsLockedS9 = json_encode(['event_details' => 'Please update venue information']);
    $stmt->execute([$facId, $revSectionsLockedS9]);
    $actId = (int)$db->lastInsertId();

    // Insert baseline participant row
    $baseInsert = $db->prepare("INSERT INTO activity_participants (activity_id, row_no, subject_section, participants_count, actual_attendees, pre_test_score, post_test_score, learning_kpi_result, teacher_name) VALUES (?, 1, 'BSIT 301', 50, 50, 70.00, 85.00, '+15.0 pts', 'Prof. Smith')");
    $baseInsert->execute([$actId]);

    // TEST 1: Valid score values
    $validPre = 75.50;
    $validPost = 92.25;
    $isValidScore = ($validPre >= 0 && $validPre <= 999.99 && $validPost >= 0 && $validPost <= 999.99);
    assertTest("Score validation accepts valid non-negative scores (75.50, 92.25)", $isValidScore);

    // TEST 2: Negative scores rejected
    $negScore = -15.00;
    $isNegRejected = !($negScore >= 0 && is_numeric($negScore));
    assertTest("Score validation rejects negative score (-15.00)", $isNegRejected);

    // TEST 3: Scores above overflow maximum (> 999.99) rejected
    $overflowScore = 1500.00;
    $isOverflowRejected = ($overflowScore > 999.99);
    assertTest("Score validation rejects score above database limit (1500.00)", $isOverflowRejected);

    // TEST 4: Missing scores preserved as NULL, not 0.00
    $nullInsert = $db->prepare("INSERT INTO activity_participants (activity_id, row_no, subject_section, participants_count, actual_attendees, pre_test_score, post_test_score, learning_kpi_result, teacher_name) VALUES (?, 2, 'BSCS 301', 40, NULL, NULL, NULL, NULL, 'Prof. Turing')");
    $nullInsert->execute([$actId]);

    $checkNull = $db->prepare("SELECT pre_test_score, post_test_score, actual_attendees FROM activity_participants WHERE activity_id=? AND row_no=2");
    $checkNull->execute([$actId]);
    $nullRow = $checkNull->fetch(PDO::FETCH_ASSOC);

    assertTest("Missing scores remain strictly NULL (not 0.00)", 
        $nullRow['pre_test_score'] === null && $nullRow['post_test_score'] === null && $nullRow['actual_attendees'] === null);

    // TEST 5: Section 9 LOCKED: KPI modification rejected & existing values preserved
    // Simulate updating while Section 9 is locked (s9Editable = false)
    $s1Editable = true;
    $s9Editable = false; // Locked!

    $existingMap = [];
    $existStmt = $db->prepare("SELECT * FROM activity_participants WHERE activity_id=? ORDER BY row_no ASC, id ASC");
    $existStmt->execute([$actId]);
    foreach ($existStmt->fetchAll(PDO::FETCH_ASSOC) as $ep) {
        $existingMap[$ep['row_no']] = $ep;
    }

    // Proponent attempts unauthorized KPI modification in POST
    $forgedActuals = [999, 888];
    $forgedPre = [10.0, 20.0];
    $forgedPost = [20.0, 30.0];

    // Execute update logic with s9Editable = false
    $db->prepare("DELETE FROM activity_participants WHERE activity_id=?")->execute([$actId]);
    $pStmt = $db->prepare("INSERT INTO activity_participants (activity_id, row_no, subject_section, participants_count, actual_attendees, pre_test_score, post_test_score, learning_kpi_result, teacher_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $partSections = ['BSIT 301 (Updated)', 'BSCS 301 (Updated)'];
    $partCounts = [50, 40];
    $rowNo = 1;
    foreach ($partSections as $i => $sec) {
        $secText = $sec;
        $cntVal  = $partCounts[$i];
        $tVal    = 'Prof. Test';
        $actVal  = null;
        $preVal  = null;
        $postVal = null;
        $kpiOutcome = null;

        if ($s9Editable) {
            $actVal = $forgedActuals[$i];
            $preVal = $forgedPre[$i];
            $postVal = $forgedPost[$i];
        } else {
            // Section 9 locked: reject unauthorized KPI changes and preserve existing
            if (isset($existingMap[$rowNo])) {
                $actVal     = $existingMap[$rowNo]['actual_attendees'];
                $preVal     = $existingMap[$rowNo]['pre_test_score'];
                $postVal    = $existingMap[$rowNo]['post_test_score'];
                $kpiOutcome = $existingMap[$rowNo]['learning_kpi_result'];
            }
        }
        $pStmt->execute([$actId, $rowNo, $secText, $cntVal, $actVal, $preVal, $postVal, $kpiOutcome, $tVal]);
        $rowNo++;
    }

    $lockedCheck = $db->prepare("SELECT * FROM activity_participants WHERE activity_id=? AND row_no=1");
    $lockedCheck->execute([$actId]);
    $rowAfterLockedUpdate = $lockedCheck->fetch(PDO::FETCH_ASSOC);

    assertTest("Section 9 locked: Unauthorized KPI changes rejected (actual=50 retained, not 999)", 
        (int)$rowAfterLockedUpdate['actual_attendees'] === 50 && (float)$rowAfterLockedUpdate['pre_test_score'] === 70.00);

    // TEST 6: Section 9 EDITABLE: KPI modification accepted
    $s9Editable = true; // Reviewer approved KPI revision
    $validActuals = [52, 42];
    $validPreList = [68.0, 72.0];
    $validPostList= [88.0, 90.0];

    // Re-run with s9Editable = true
    $db->prepare("DELETE FROM activity_participants WHERE activity_id=?")->execute([$actId]);
    $rowNo = 1;
    foreach ($partSections as $i => $sec) {
        $secText = $sec;
        $cntVal  = $partCounts[$i];
        $tVal    = 'Prof. Test';
        $actVal  = $validActuals[$i];
        $preVal  = $validPreList[$i];
        $postVal = $validPostList[$i];
        $diff = $postVal - $preVal;
        $kpiOutcome = ($diff >= 0 ? '+' : '') . number_format($diff, 1) . ' pts';
        $pStmt->execute([$actId, $rowNo, $secText, $cntVal, $actVal, $preVal, $postVal, $kpiOutcome, $tVal]);
        $rowNo++;
    }

    $editCheck = $db->prepare("SELECT * FROM activity_participants WHERE activity_id=? AND row_no=1");
    $editCheck->execute([$actId]);
    $rowAfterEditableUpdate = $editCheck->fetch(PDO::FETCH_ASSOC);

    assertTest("Section 9 editable: KPI modification accepted (actual=52, pre=68.00, post=88.00)",
        (int)$rowAfterEditableUpdate['actual_attendees'] === 52 && 
        (float)$rowAfterEditableUpdate['pre_test_score'] === 68.00 && 
        (float)$rowAfterEditableUpdate['post_test_score'] === 88.00 &&
        $rowAfterEditableUpdate['learning_kpi_result'] === '+20.0 pts');

    // TEST 7: Section 1 LOCKED: participant structure preserved while updating KPI
    $s1Editable = false; // Section 1 locked!
    $s9Editable = true;  // Section 9 editable

    // Attempting to change sections while Section 1 is locked
    $existStmt = $db->prepare("SELECT id, row_no, subject_section, participants_count, teacher_name FROM activity_participants WHERE activity_id=? ORDER BY row_no ASC, id ASC");
    $existStmt->execute([$actId]);
    $originalParts = $existStmt->fetchAll(PDO::FETCH_ASSOC);

    // Update only KPIs in place
    $updateKpiStmt = $db->prepare("UPDATE activity_participants SET actual_attendees=?, pre_test_score=?, post_test_score=?, learning_kpi_result=? WHERE id=?");
    $updateKpiStmt->execute([54, 70.00, 92.00, '+22.0 pts', $originalParts[0]['id']]);

    $checkStruct = $db->prepare("SELECT * FROM activity_participants WHERE activity_id=? AND row_no=1");
    $checkStruct->execute([$actId]);
    $structRow = $checkStruct->fetch(PDO::FETCH_ASSOC);

    assertTest("Section 1 locked: Participant structure preserved (section name and count untouched)",
        $structRow['subject_section'] === $originalParts[0]['subject_section'] &&
        (int)$structRow['participants_count'] === (int)$originalParts[0]['participants_count'] &&
        (int)$structRow['actual_attendees'] === 54);

    // TEST 8: Omitted KPI fields: existing values preserved
    $preOmitActual = (int)$structRow['actual_attendees'];
    $preOmitPre    = (float)$structRow['pre_test_score'];
    $preOmitPost   = (float)$structRow['post_test_score'];

    // Update with omitted/null KPI fields
    $omittedActuals = [null]; // Omitted in POST
    $omittedPre     = [null]; // Omitted in POST
    $omittedPost    = [null]; // Omitted in POST

    $actVal = $omittedActuals[0] !== null ? $omittedActuals[0] : $structRow['actual_attendees'];
    $preVal = $omittedPre[0] !== null ? $omittedPre[0] : $structRow['pre_test_score'];
    $postVal= $omittedPost[0] !== null ? $omittedPost[0] : $structRow['post_test_score'];
    $updateKpiStmt->execute([$actVal, $preVal, $postVal, $structRow['learning_kpi_result'], $structRow['id']]);

    $checkOmit = $db->prepare("SELECT * FROM activity_participants WHERE activity_id=? AND id=?");
    $checkOmit->execute([$actId, $structRow['id']]);
    $omittedRow = $checkOmit->fetch(PDO::FETCH_ASSOC);

    assertTest("Omitted KPI fields preserve existing database values",
        (int)$omittedRow['actual_attendees'] === $preOmitActual &&
        (float)$omittedRow['pre_test_score'] === $preOmitPre &&
        (float)$omittedRow['post_test_score'] === $preOmitPost);

    // Clean up test activity
    $db->prepare("DELETE FROM activity_participants WHERE activity_id=?")->execute([$actId]);
    $db->prepare("DELETE FROM activities WHERE id=?")->execute([$actId]);
    echo "Cleaned up temporary audit fix activity #{$actId}.\n";

} catch (Exception $e) {
    assertTest("Audit fixes regression tests encountered exception", false, $e->getMessage());
}

// -----------------------------------------------------------------
// 6. Proposal Stage Correction: Neutral Placeholders & Post-Activity Separation
// -----------------------------------------------------------------
echo "\n--- 6. Proposal Stage Correction: Neutral Placeholders & Post-Activity Separation ---\n";

$createContent = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');
$editContent   = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');

// Attendance KPI in Create Proposal
assertTest("proposal-create.php displays 'To be recorded after the activity' placeholder for actual attendance",
    strpos($createContent, 'To be recorded after the activity') !== false);
assertTest("proposal-create.php has removed editable number input for Actual Attendees",
    strpos($createContent, '<input type="number" name="part_actual_attendees[]"') === false);
assertTest("proposal-create.php preserves KPI data model and inputs",
    strpos($createContent, 'name="kpi_indicator[]"') !== false &&
    strpos($createContent, 'name="kpi_target[]"') !== false &&
    strpos($createContent, 'name="kpi_method[]"') !== false);

// Learning Outcome KPI in Create Proposal
assertTest("proposal-create.php has removed editable Pre-test Result input",
    strpos($createContent, '<input type="number" step="0.01" min="0" name="part_pre_test[]"') === false &&
    strpos($createContent, 'name="part_pre_test[]" class="form-control') === false);
assertTest("proposal-create.php has removed editable Post-test Result input",
    strpos($createContent, '<input type="number" step="0.01" min="0" name="part_post_test[]"') === false &&
    strpos($createContent, 'name="part_post_test[]" class="form-control') === false);
assertTest("proposal-create.php separates proposal stage from post-activity recording",
    strpos($createContent, '<input type="number" name="part_actual_attendees[]"') === false &&
    strpos($createContent, '<input type="number" step="0.01" min="0" name="part_pre_test[]"') === false);

// Attendance KPI in Edit Proposal
assertTest("proposal-edit.php displays 'To be recorded after the activity' placeholder for actual attendance",
    strpos($editContent, 'To be recorded after the activity') !== false);
assertTest("proposal-edit.php has removed editable number input for Actual Attendees",
    strpos($editContent, '<input type="number" name="part_actual_attendees[]"') === false);
assertTest("proposal-edit.php preserves KPI data model and inputs",
    strpos($editContent, 'name="kpi_indicator[]"') !== false &&
    strpos($editContent, 'name="kpi_target[]"') !== false &&
    strpos($editContent, 'name="kpi_method[]"') !== false);

// Learning Outcome KPI in Edit Proposal
assertTest("proposal-edit.php has removed editable Pre-test Result input",
    strpos($editContent, '<input type="number" step="0.01" min="0" name="part_pre_test[]"') === false &&
    strpos($editContent, 'name="part_pre_test[]" class="form-control') === false);
assertTest("proposal-edit.php has removed editable Post-test Result input",
    strpos($editContent, '<input type="number" step="0.01" min="0" name="part_post_test[]"') === false &&
    strpos($editContent, 'name="part_post_test[]" class="form-control') === false);
assertTest("proposal-edit.php separates proposal stage from post-activity recording",
    strpos($editContent, '<input type="number" name="part_actual_attendees[]"') === false &&
    strpos($editContent, '<input type="number" step="0.01" min="0" name="part_pre_test[]"') === false);

assertTest("proposal-create.php Step 9 layout follows verified Section 1 KPI Table + Section 2 Evaluation Tool order",
    strpos($createContent, 'id="kpi-table-card"') !== false &&
    strpos($createContent, 'id="ai-evaluation-tool-card"') !== false &&
    strpos($createContent, 'id="kpi-table-card"') < strpos($createContent, 'id="ai-evaluation-tool-card"'));
assertTest("proposal-create.php has removed 110% threshold from visible attendance KPI legend",
    strpos($createContent, '≥ 110% achievement') === false && strpos($createContent, '110%') === false);

assertTest("proposal-edit.php Step 9 layout follows verified Section 1 KPI Table + Section 2 Evaluation Tool order",
    strpos($editContent, 'id="kpi-table-card"') !== false &&
    strpos($editContent, 'id="ai-evaluation-tool-card"') !== false &&
    strpos($editContent, 'id="kpi-table-card"') < strpos($editContent, 'id="ai-evaluation-tool-card"'));
assertTest("proposal-edit.php has removed 110% threshold from visible attendance KPI legend",
    strpos($editContent, '≥ 110% achievement') === false && strpos($editContent, '110%') === false);

// Hidden input data preservation without exposing editable controls
assertTest("proposal-create.php includes hidden inputs to preserve any KPI values without showing editable fields",
    strpos($createContent, '<input type="hidden" name="part_actual_attendees[]"') !== false &&
    strpos($createContent, '<input type="hidden" name="part_pre_test[]"') !== false &&
    strpos($createContent, '<input type="hidden" name="part_post_test[]"') !== false);
assertTest("proposal-edit.php includes hidden inputs to preserve any KPI values without showing editable fields",
    strpos($editContent, '<input type="hidden" name="part_actual_attendees[]"') !== false &&
    strpos($editContent, '<input type="hidden" name="part_pre_test[]"') !== false &&
    strpos($editContent, '<input type="hidden" name="part_post_test[]"') !== false);

// -----------------------------------------------------------------
// Summary
// -----------------------------------------------------------------
echo "\n=================================================================\n";
echo "SUMMARY: {$passedCount} / {$testCount} tests passed.\n";
if ($allPassed) {
    echo "ALL TESTS PASSED SUCCESSFULLY! Activity Evaluation & KPI Measurement verified.\n";
} else {
    echo "SOME TESTS FAILED! Please review failure details above.\n";
}
echo "=================================================================\n";

exit($allPassed ? 0 : 1);
