<?php
// tests/test_issue4_offline_draft_sync.php
$suites = [
    'suite1_static_js_checks' => 'Offline Draft Serialization & Progress Tracking',
    'suite2_recovery_checks'  => 'Accidental Exit & Submission Error Auto-Recovery',
    'suite3_server_sync'      => 'Server Synchronization Endpoint Verification',
    'suite4_duplicate_check'  => 'Duplicate Prevention & Re-synchronization Updates',
    'suite5_conflict_check'   => 'Conflict Detection & Safe Overwrite Resolution'
];

$selected = $argv[1] ?? null;
if ($selected) {
    runSuite($selected);
    exit;
}

echo "=== Issue 4 Verification: Automatic Proposal Drafts & Progress Synchronization ===\n\n";

$allPassed = true;
foreach ($suites as $key => $desc) {
    $cmd = "php " . escapeshellarg(__FILE__) . " " . escapeshellarg($key);
    $output = [];
    $ret = 0;
    exec($cmd, $output, $ret);
    $outStr = implode("\n", $output);

    $isPass = false;
    if ($ret === 0 && str_contains($outStr, 'SUITE_RESULT:PASS')) {
        $isPass = true;
    } elseif ($key === 'suite3_server_sync') {
        $json = json_decode($outStr, true);
        $isPass = is_array($json) && !empty($json['success']) && !empty($json['activity_id']);
    } elseif ($key === 'suite4_duplicate_check') {
        $json = json_decode($outStr, true);
        $isPass = is_array($json) && !empty($json['success']) && ($json['status'] ?? '') === 'draft';
    } elseif ($key === 'suite5_conflict_check') {
        $json = json_decode($outStr, true);
        $isPass = is_array($json) && !empty($json['conflict']) && ($json['error_code'] ?? '') === 'conflict_requires_review';
    }

    if ($isPass) {
        echo "  [PASS] {$desc}\n";
    } else {
        echo "  [FAIL] {$desc}\n";
        echo "  Output:\n" . $outStr . "\n";
        $allPassed = false;
    }
}

echo "\n========================================================\n";
echo " FINAL RESULT: " . ($allPassed ? "ALL 5 SUITES PASSED!" : "SOME SUITES FAILED") . "\n";
echo "========================================================\n";
exit($allPassed ? 0 : 1);

function runSuite(string $suite) {
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

    $_SESSION['user_id'] = $facultyUser['id'];
    $_SESSION['user_role'] = $facultyUser['role'];
    $_SESSION['department'] = $facultyUser['department'];
    $_SESSION['last_activity'] = time();

    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    if ($suite === 'suite1_static_js_checks') {
        $jsFile = file_get_contents(__DIR__ . '/../assets/js/offline-drafts.js');
        $c1 = str_contains($jsFile, "data['wizard_current_step'] = window.currentStep;");
        $c2 = str_contains($jsFile, "data['wizard_max_step'] = window.maxUnlockedStep;");
        $c3 = str_contains($jsFile, "window.showStep(targetStep)");
        $c4 = str_contains($jsFile, "initTeacherMultiSelect(container, hiddenInput.value)");
        $c5 = str_contains($jsFile, "isFormForThisDraft");

        if ($c1 && $c2 && $c3 && $c4 && $c5) {
            echo "SUITE_RESULT:PASS\n";
            exit(0);
        }
        echo "SUITE_RESULT:FAIL\n";
        exit(1);
    }

    if ($suite === 'suite2_recovery_checks') {
        $jsFile = file_get_contents(__DIR__ . '/../assets/js/offline-drafts.js');
        $editFile = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');
        $createFile = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');
        $viewFile = file_get_contents(__DIR__ . '/../faculty/proposal-view.php');

        $c1 = str_contains($editFile, "OfflineDraftManager.saveDraftSynchronously");
        $c2 = str_contains($createFile, "OfflineDraftManager.saveDraftSynchronously");
        $c3 = str_contains($jsFile, "isSubmissionError");
        $c4 = str_contains($jsFile, "progress and entered inputs were safely preserved") || str_contains($jsFile, "Entered Information & Progress Preserved");
        $c5 = str_contains($viewFile, "OfflineDraftManager.deleteDraft");

        if ($c1 && $c2 && $c3 && $c4 && $c5) {
            echo "SUITE_RESULT:PASS\n";
            exit(0);
        }
        echo "SUITE_RESULT:FAIL\n";
        exit(1);
    }

    if ($suite === 'suite3_server_sync') {
        $syncTitle = "Offline Synced Proposal " . time();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'faculty_id'  => $facultyUser['id'],
            'action'      => 'draft',
            'is_ajax_sync' => '1',
            'title'       => $syncTitle,
            'description' => 'Draft description',
            'theme'       => 'Draft Theme',
            'venue'       => 'Room 303',
            'venue_address' => 'Main Campus',
            'event_date'  => $tomorrow,
            'start_time'  => '14:00',
            'end_time'    => '16:00',
            'target_participants' => 30,
            'general_objectives' => 'Draft general objectives',
            'specific_objectives' => 'Draft specific objectives',
            'involved_subjects' => 'IT102',
            'rationale'   => 'Draft rationale',
            'part_section' => ['BSIT 101'],
            'part_count'   => [30],
            'part_teacher' => ['Mr. Dave Lord M. Rubaya']
        ];

        register_shutdown_function(function() use ($db, $syncTitle) {
            $row = $db->query("SELECT id FROM activities WHERE title = " . $db->quote($syncTitle))->fetch(PDO::FETCH_ASSOC);
            if (!empty($row['id'])) {
                $db->exec("SET FOREIGN_KEY_CHECKS = 0");
                $db->prepare("DELETE FROM activity_participants WHERE activity_id = ?")->execute([$row['id']]);
                $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$row['id']]);
                $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            }
        });

        require __DIR__ . '/../api/proposal-save.php';
        exit;
    }

    if ($suite === 'suite4_duplicate_check') {
        $syncTitle = "Dup Check Proposal " . time();

        // 1. Initial insert
        $ins = $db->prepare("INSERT INTO activities (faculty_id, title, theme, venue, event_date, status) VALUES (?, ?, 'Theme', 'Room 1', ?, 'draft')");
        $ins->execute([$facultyUser['id'], $syncTitle, $tomorrow]);
        $actId = (int)$db->lastInsertId();

        // 2. Re-sync update
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'activity_id' => $actId,
            'faculty_id'  => $facultyUser['id'],
            'action'      => 'draft',
            'is_ajax_sync' => '1',
            'title'       => $syncTitle . " Updated",
            'theme'       => 'Theme Updated',
            'venue'       => 'Room 1',
            'event_date'  => $tomorrow,
        ];

        register_shutdown_function(function() use ($db, $actId) {
            $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$actId]);
        });

        require __DIR__ . '/../api/proposal-update.php';
        exit;
    }

    if ($suite === 'suite5_conflict_check') {
        $syncTitle = "Conflict Proposal " . time();
        $ins = $db->prepare("INSERT INTO activities (faculty_id, title, theme, venue, event_date, status, updated_at) VALUES (?, ?, 'Theme', 'Room 1', ?, 'draft', NOW() + INTERVAL 10 SECOND)");
        $ins->execute([$facultyUser['id'], $syncTitle, $tomorrow]);
        $actId = (int)$db->lastInsertId();

        // Simulate a second collaborator modifying the server proposal
        $collabUser = $db->query("SELECT id FROM users WHERE id != {$facultyUser['id']} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($collabUser) {
            try {
                $db->prepare("INSERT INTO proposal_collaborators (activity_id, user_id, status) VALUES (?, ?, 'active')")->execute([$actId, $collabUser['id']]);
                $db->prepare("INSERT INTO proposal_contribution_logs (activity_id, user_id, action, section_edited, details) VALUES (?, ?, 'updated_draft', 'Details', 'Other edit')")->execute([$actId, $collabUser['id']]);
            } catch (\Throwable $e) {}
        }

        // Client timestamp older than server
        $clientOldTime = date('Y-m-d H:i:s', time() - 100);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'activity_id' => $actId,
            'faculty_id'  => $facultyUser['id'],
            'action'      => 'draft',
            'is_ajax_sync' => '1',
            'last_known_updated_at' => $clientOldTime,
            'title'       => $syncTitle . " Outdated Edit",
        ];

        register_shutdown_function(function() use ($db, $actId) {
            try {
                $db->prepare("DELETE FROM proposal_collaborators WHERE activity_id = ?")->execute([$actId]);
                $db->prepare("DELETE FROM proposal_contribution_logs WHERE activity_id = ?")->execute([$actId]);
                $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$actId]);
            } catch (\Throwable $e) {}
        });

        require __DIR__ . '/../api/proposal-update.php';
        exit;
    }
}
