<?php
// tests/test_submit_button_and_update_500.php
$testCases = [
    'test_submit_draft'      => 'Submit draft proposal for approval via proposal-update.php',
    'test_resubmit_returned' => 'Resubmit returned_for_revision proposal via proposal-update.php',
    'test_task_assignment'   => 'Task assignment with admin1/admin2 roles in proposal-update.php',
    'test_save_submit_new'   => 'New proposal creation and submit via proposal-save.php'
];

$selected = $argv[1] ?? null;

if ($selected) {
    runSingleTest($selected);
    exit;
}

echo "========================================================\n";
echo " VERIFYING PROPOSAL SUBMIT & RESUBMISSION (NO 500 ERRORS)\n";
echo "========================================================\n\n";

$allPassed = true;
foreach ($testCases as $case => $desc) {
    $cmd = "php " . escapeshellarg(__FILE__) . " " . escapeshellarg($case);
    $output = [];
    $returnVar = 0;
    exec($cmd, $output, $returnVar);
    $outStr = implode("\n", $output);

    $json = json_decode($outStr, true);
    $isSuccess = ($returnVar === 0) && is_array($json) && !empty($json['success']);

    if ($isSuccess) {
        $statusStr = $json['status'] ?? 'ok';
        $actIdStr = $json['activity_id'] ?? '';
        echo " [PASS] {$desc} (Activity #{$actIdStr}, Status: {$statusStr})\n";
    } else {
        echo " [FAIL] {$desc}\n";
        echo " Output:\n" . $outStr . "\n";
        $allPassed = false;
    }
}

echo "\n========================================================\n";
echo " FINAL RESULT: " . ($allPassed ? "ALL 4 SUITES PASSED!" : "SOME SUITES FAILED") . "\n";
echo "========================================================\n";
exit($allPassed ? 0 : 1);

function safeCleanup(PDO $db, int $actId) {
    if ($actId <= 0) return;
    try {
        $db->exec("SET FOREIGN_KEY_CHECKS = 0");
        $db->prepare("DELETE FROM documents WHERE activity_id = ?")->execute([$actId]);
        $db->prepare("DELETE FROM approval_logs WHERE activity_id = ?")->execute([$actId]);
        $db->prepare("DELETE FROM faculty_tasks WHERE activity_id = ?")->execute([$actId]);
        $db->prepare("DELETE FROM activity_participants WHERE activity_id = ?")->execute([$actId]);
        $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$actId]);
        $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    } catch (\Throwable $e) {}
}

function runSingleTest(string $case) {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/functions.php';

    $db = getDB();
    $facultyUser = $db->query("SELECT * FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $admin1User  = $db->query("SELECT * FROM users WHERE role = 'admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $admin2User  = $db->query("SELECT * FROM users WHERE role = 'admin2' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    $_SESSION['user_id'] = $facultyUser['id'];
    $_SESSION['user_role'] = $facultyUser['role'];
    $_SESSION['department'] = $facultyUser['department'];
    $_SESSION['last_activity'] = time();

    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    if ($case === 'test_submit_draft') {
        $title = "Draft Submit Test " . time();
        $ins = $db->prepare("
            INSERT INTO activities (
                faculty_id, title, description, theme, venue, venue_address,
                event_date, start_time, end_time, target_participants,
                general_objectives, specific_objectives, involved_subjects,
                rationale, evaluation_method, source, status, submitted_at, evaluation_questions
            ) VALUES (
                ?, ?, 'Desc', 'Theme', 'Room 101', 'Main Campus',
                ?, '09:00:00', '11:00:00', 40,
                'General Objectives long text', 'Specific Objectives long text', 'IT101',
                'Rationale long text for automated testing', 'Student Evaluation', 'faculty', 'draft', NULL, '[]'
            )
        ");
        $ins->execute([$facultyUser['id'], $title, $tomorrow]);
        $actId = (int)$db->lastInsertId();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'activity_id' => $actId,
            'faculty_id'  => $facultyUser['id'],
            'action'      => 'submit',
            'last_known_updated_at' => date('Y-m-d H:i:s'),
            'title'       => $title,
            'theme'       => 'Theme Updated',
            'venue'       => 'Room 101',
            'venue_address' => 'Main Campus',
            'event_date'  => $tomorrow,
            'start_time'  => '09:00',
            'end_time'    => '11:00',
            'target_participants' => 40,
            'general_objectives' => 'General Objectives long text',
            'specific_objectives' => 'Specific Objectives long text',
            'involved_subjects' => 'IT101',
            'rationale'   => 'Rationale long text for automated testing',
            'evaluation_method' => 'Student Evaluation',
            'evaluation_questions' => '[]',
            'floor_plan_required' => '0',
            'kpi_criteria' => ['Criteria A'],
            'kpi_rating'   => ['5'],
            'is_ajax_sync' => '1',
        ];

        register_shutdown_function(function() use ($db, $actId) {
            safeCleanup($db, $actId);
        });

        require __DIR__ . '/../api/proposal-update.php';
        exit;
    }

    if ($case === 'test_resubmit_returned') {
        $title = "Resubmit Test " . time();
        $ins = $db->prepare("
            INSERT INTO activities (
                faculty_id, title, description, theme, venue, venue_address,
                event_date, start_time, end_time, target_participants,
                general_objectives, specific_objectives, involved_subjects,
                rationale, evaluation_method, source, status, submitted_at, revision_notes, evaluation_questions
            ) VALUES (
                ?, ?, 'Desc', 'Theme', 'Room 202', 'Main Campus',
                ?, '10:00:00', '12:00:00', 35,
                'General Objectives long text', 'Specific Objectives long text', 'CS102',
                'Rationale long text for automated testing', 'Student Evaluation', 'faculty', 'returned_for_revision', NOW(), 'Please revise venue', '[]'
            )
        ");
        $ins->execute([$facultyUser['id'], $title, $tomorrow]);
        $actId = (int)$db->lastInsertId();

        if (!empty($admin2User)) {
            $logIns = $db->prepare("INSERT INTO approval_logs (activity_id, reviewer_id, action, notes, acted_at) VALUES (?, ?, 'returned_for_revision', 'Revise venue', NOW())");
            $logIns->execute([$actId, $admin2User['id']]);
        }

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'activity_id' => $actId,
            'faculty_id'  => $facultyUser['id'],
            'action'      => 'submit',
            'last_known_updated_at' => date('Y-m-d H:i:s'),
            'title'       => $title,
            'theme'       => 'Theme Revised',
            'venue'       => 'Auditorium',
            'venue_address' => 'Main Campus',
            'event_date'  => $tomorrow,
            'start_time'  => '10:00',
            'end_time'    => '12:00',
            'target_participants' => 35,
            'general_objectives' => 'General Objectives long text',
            'specific_objectives' => 'Specific Objectives long text',
            'involved_subjects' => 'CS102',
            'rationale'   => 'Rationale long text for automated testing',
            'evaluation_method' => 'Student Evaluation',
            'evaluation_questions' => '[]',
            'floor_plan_required' => '0',
            'kpi_criteria' => ['Criteria B'],
            'kpi_rating'   => ['4'],
            'is_ajax_sync' => '1',
        ];

        register_shutdown_function(function() use ($db, $actId) {
            safeCleanup($db, $actId);
        });

        require __DIR__ . '/../api/proposal-update.php';
        exit;
    }

    if ($case === 'test_task_assignment') {
        $title = "Task Assignment Test " . time();
        $ins = $db->prepare("
            INSERT INTO activities (
                faculty_id, title, description, theme, venue, venue_address,
                event_date, start_time, end_time, target_participants,
                general_objectives, specific_objectives, involved_subjects,
                rationale, evaluation_method, source, status, submitted_at, evaluation_questions
            ) VALUES (
                ?, ?, 'Desc', 'Theme', 'Lab 1', 'Main Campus',
                ?, '13:00:00', '15:00:00', 25,
                'General Objectives long text', 'Specific Objectives long text', 'IT201',
                'Rationale long text for automated testing', 'Student Evaluation', 'faculty', 'draft', NULL, '[]'
            )
        ");
        $ins->execute([$facultyUser['id'], $title, $tomorrow]);
        $actId = (int)$db->lastInsertId();

        $assignedUser = !empty($admin1User) ? $admin1User : $facultyUser;

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'activity_id' => $actId,
            'faculty_id'  => $facultyUser['id'],
            'action'      => 'draft',
            'title'       => $title,
            'theme'       => 'Theme 3',
            'venue'       => 'Lab 1',
            'venue_address' => 'Main Campus',
            'event_date'  => $tomorrow,
            'start_time'  => '13:00',
            'end_time'    => '15:00',
            'target_participants' => 25,
            'general_objectives' => 'General Objectives long text',
            'specific_objectives' => 'Specific Objectives long text',
            'involved_subjects' => 'IT201',
            'rationale'   => 'Rationale long text for automated testing',
            'ft_task'     => ['Technical Setup Support'],
            'ft_user_id'  => [$assignedUser['id']],
            'ft_name'     => [$assignedUser['name']],
            'ft_role'     => ['Head Coordinator'],
            'ft_due_date' => [$tomorrow],
            'is_ajax_sync' => '1',
        ];

        register_shutdown_function(function() use ($db, $actId) {
            safeCleanup($db, $actId);
        });

        require __DIR__ . '/../api/proposal-update.php';
        exit;
    }

    if ($case === 'test_save_submit_new') {
        $title = "New Proposal Submit Test " . time();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'faculty_id'  => $facultyUser['id'],
            'action'      => 'submit',
            'title'       => $title,
            'description' => 'New proposal description',
            'theme'       => 'Innovation Theme',
            'venue'       => 'AVR',
            'venue_address' => 'Academic Center',
            'event_date'  => $tomorrow,
            'start_time'  => '08:00',
            'end_time'    => '11:00',
            'target_participants' => 60,
            'general_objectives' => 'General Objectives long text',
            'specific_objectives' => 'Specific Objectives long text',
            'involved_subjects' => 'GE101',
            'rationale'   => 'Rationale long text for automated testing',
            'evaluation_method' => 'Student Evaluation',
            'evaluation_questions' => '[]',
            'floor_plan_required' => '0',
            'kpi_criteria' => ['Participant Attendance Metric'],
            'kpi_rating'   => ['4.8'],
            'is_ajax_sync' => '1',
        ];

        register_shutdown_function(function() use ($db, $title) {
            $row = $db->query("SELECT id FROM activities WHERE title = " . $db->quote($title))->fetch(PDO::FETCH_ASSOC);
            if (!empty($row['id'])) {
                safeCleanup($db, (int)$row['id']);
            }
        });

        require __DIR__ . '/../api/proposal-save.php';
        exit;
    }
}
