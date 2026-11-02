<?php
/**
 * Automated Verification Script for Program Head Backend Access Control
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

echo "=== STI-ARMES Program Head Backend Access Control Verification ===\n\n";

$createdUserIds = [];
$createdActivityIds = [];
$createdTaskIds = [];

try {
    // 1. Setup temporary test users
    $pwd = password_hash('password123', PASSWORD_DEFAULT);

    // Faculty IT
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Test Faculty IT', 'test_fac_it_" . time() . "@test.com', ?, 'faculty', 'IT Department')");
    $stmt->execute([$pwd]);
    $facultyItId = (int)$db->lastInsertId();
    $createdUserIds[] = $facultyItId;

    // Faculty THM
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Test Faculty THM', 'test_fac_thm_" . time() . "@test.com', ?, 'faculty', 'THM Department')");
    $stmt->execute([$pwd]);
    $facultyThmId = (int)$db->lastInsertId();
    $createdUserIds[] = $facultyThmId;

    // Faculty GE
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Test Faculty GE', 'test_fac_ge_" . time() . "@test.com', ?, 'faculty', 'G.E. Department')");
    $stmt->execute([$pwd]);
    $facultyGeId = (int)$db->lastInsertId();
    $createdUserIds[] = $facultyGeId;

    // Program Head IT
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Test PH IT', 'test_ph_it_" . time() . "@test.com', ?, 'program_head', 'IT Department')");
    $stmt->execute([$pwd]);
    $phItId = (int)$db->lastInsertId();
    $createdUserIds[] = $phItId;

    // Program Head THM
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Test PH THM', 'test_ph_thm_" . time() . "@test.com', ?, 'program_head', 'THM Department')");
    $stmt->execute([$pwd]);
    $phThmId = (int)$db->lastInsertId();
    $createdUserIds[] = $phThmId;

    // Program Head GE
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Test PH GE', 'test_ph_ge_" . time() . "@test.com', ?, 'program_head', 'G.E. Department')");
    $stmt->execute([$pwd]);
    $phGeId = (int)$db->lastInsertId();
    $createdUserIds[] = $phGeId;

    // Dean
    $deanId = 4; // Existing dean or fallback
    // Admin1
    $admin1Id = 2;

    // 2. Setup temporary activities
    // IT Activity (exclusive IT)
    $stmt = $db->prepare("INSERT INTO activities (faculty_id, title, status, event_date) VALUES (?, 'Exclusive IT Activity', 'draft', DATE_ADD(CURDATE(), INTERVAL 5 DAY))");
    $stmt->execute([$facultyItId]);
    $itActId = (int)$db->lastInsertId();
    $createdActivityIds[] = $itActId;

    // THM Activity (exclusive THM)
    $stmt = $db->prepare("INSERT INTO activities (faculty_id, title, status, event_date) VALUES (?, 'Exclusive THM Activity', 'draft', DATE_ADD(CURDATE(), INTERVAL 6 DAY))");
    $stmt->execute([$facultyThmId]);
    $thmActId = (int)$db->lastInsertId();
    $createdActivityIds[] = $thmActId;

    // Cross-department Activity: Created by IT, but task assigned to THM faculty member
    $stmt = $db->prepare("INSERT INTO activities (faculty_id, title, status, event_date) VALUES (?, 'Cross-Dept IT Activity with THM Task', 'draft', DATE_ADD(CURDATE(), INTERVAL 7 DAY))");
    $stmt->execute([$facultyItId]);
    $crossActId = (int)$db->lastInsertId();
    $createdActivityIds[] = $crossActId;

    // Add task assigned to THM faculty
    $stmt = $db->prepare("INSERT INTO faculty_tasks (activity_id, assigned_user_id, faculty_name, task_title, status) VALUES (?, ?, 'Test Faculty THM', 'Logistics Support', 'Not Started')");
    $stmt->execute([$crossActId, $facultyThmId]);
    $createdTaskIds[] = (int)$db->lastInsertId();

    $assertCount = 0;
    $passCount = 0;

    function assertCondition($desc, $result, $expected) {
        global $assertCount, $passCount;
        $assertCount++;
        if ($result === $expected) {
            $passCount++;
            echo "[PASS] $desc\n";
        } else {
            echo "[FAIL] $desc (Expected: " . var_export($expected, true) . ", Got: " . var_export($result, true) . ")\n";
        }
    }

    echo "--- 1. IT Program Head Tests ---\n";
    assertCondition("IT PH can view IT Activity", canUserAccessActivity($itActId, 'view', $phItId, 'program_head', 'IT Department'), true);
    assertCondition("IT PH can modify IT Activity", canUserAccessActivity($itActId, 'modify', $phItId, 'program_head', 'IT Department'), true);
    assertCondition("IT PH CANNOT view THM Activity", canUserAccessActivity($thmActId, 'view', $phItId, 'program_head', 'IT Department'), false);
    assertCondition("IT PH CANNOT modify THM Activity", canUserAccessActivity($thmActId, 'modify', $phItId, 'program_head', 'IT Department'), false);

    echo "\n--- 2. THM & GE Program Head Tests ---\n";
    assertCondition("THM PH can view THM Activity", canUserAccessActivity($thmActId, 'view', $phThmId, 'program_head', 'THM Department'), true);
    assertCondition("THM PH can modify THM Activity", canUserAccessActivity($thmActId, 'modify', $phThmId, 'program_head', 'THM Department'), true);
    assertCondition("THM PH CANNOT view IT Activity", canUserAccessActivity($itActId, 'view', $phThmId, 'program_head', 'THM Department'), false);
    assertCondition("THM PH CANNOT modify IT Activity", canUserAccessActivity($itActId, 'modify', $phThmId, 'program_head', 'THM Department'), false);

    assertCondition("GE PH CANNOT view IT Activity", canUserAccessActivity($itActId, 'view', $phGeId, 'program_head', 'G.E. Department'), false);
    assertCondition("GE PH CANNOT view THM Activity", canUserAccessActivity($thmActId, 'view', $phGeId, 'program_head', 'G.E. Department'), false);

    echo "\n--- 3. Shared / Cross-Department Tests ---\n";
    assertCondition("IT PH can view Cross-Dept Activity (Originating dept)", canUserAccessActivity($crossActId, 'view', $phItId, 'program_head', 'IT Department'), true);
    assertCondition("IT PH can modify Cross-Dept Activity (Originating dept)", canUserAccessActivity($crossActId, 'modify', $phItId, 'program_head', 'IT Department'), true);
    assertCondition("THM PH can view Cross-Dept Activity (Assigned THM task)", canUserAccessActivity($crossActId, 'view', $phThmId, 'program_head', 'THM Department'), true);
    assertCondition("THM PH CANNOT modify Cross-Dept Activity (Created by IT)", canUserAccessActivity($crossActId, 'modify', $phThmId, 'program_head', 'THM Department'), false);
    assertCondition("GE PH CANNOT view Cross-Dept Activity (No GE involvement)", canUserAccessActivity($crossActId, 'view', $phGeId, 'program_head', 'G.E. Department'), false);
    assertCondition("GE PH CANNOT modify Cross-Dept Activity", canUserAccessActivity($crossActId, 'modify', $phGeId, 'program_head', 'G.E. Department'), false);

    echo "\n--- 4. Dean & Admin Permissions Intact ---\n";
    assertCondition("Dean can view IT Activity", canUserAccessActivity($itActId, 'view', $deanId, 'dean', 'Administration'), true);
    assertCondition("Dean can modify IT Activity", canUserAccessActivity($itActId, 'modify', $deanId, 'dean', 'Administration'), true);
    assertCondition("Dean can view THM Activity", canUserAccessActivity($thmActId, 'view', $deanId, 'dean', 'Administration'), true);
    assertCondition("Dean can modify THM Activity", canUserAccessActivity($thmActId, 'modify', $deanId, 'dean', 'Administration'), true);
    assertCondition("Admin1 can view IT Activity", canUserAccessActivity($itActId, 'view', $admin1Id, 'admin1', 'Student Org Head'), true);
    assertCondition("Admin1 can view THM Activity", canUserAccessActivity($thmActId, 'view', $admin1Id, 'admin1', 'Student Org Head'), true);

    echo "\n--- 5. Faculty Permissions Intact ---\n";
    assertCondition("Faculty IT can view own IT Activity", canUserAccessActivity($itActId, 'view', $facultyItId, 'faculty', 'IT Department'), true);
    assertCondition("Faculty IT can modify own IT Activity", canUserAccessActivity($itActId, 'modify', $facultyItId, 'faculty', 'IT Department'), true);
    assertCondition("Faculty IT CANNOT view other faculty THM Activity (unassigned)", canUserAccessActivity($thmActId, 'view', $facultyItId, 'faculty', 'IT Department'), false);
    assertCondition("Faculty IT CANNOT modify other faculty THM Activity", canUserAccessActivity($thmActId, 'modify', $facultyItId, 'faculty', 'IT Department'), false);
    assertCondition("Faculty THM can view Cross-Dept Activity (Assigned task)", canUserAccessActivity($crossActId, 'view', $facultyThmId, 'faculty', 'THM Department'), true);
    assertCondition("Faculty THM CANNOT modify Cross-Dept Activity (Not creator)", canUserAccessActivity($crossActId, 'modify', $facultyThmId, 'faculty', 'THM Department'), false);

    echo "\nSummary: {$passCount}/{$assertCount} assertions passed.\n";

} finally {
    // Clean up temporary test data
    echo "\nCleaning up temporary test records...\n";
    if (!empty($createdTaskIds)) {
        $in = implode(',', $createdTaskIds);
        $db->exec("DELETE FROM faculty_tasks WHERE id IN ($in)");
    }
    if (!empty($createdActivityIds)) {
        $in = implode(',', $createdActivityIds);
        $db->exec("DELETE FROM activities WHERE id IN ($in)");
    }
    if (!empty($createdUserIds)) {
        $in = implode(',', $createdUserIds);
        $db->exec("DELETE FROM users WHERE id IN ($in)");
    }
    echo "Cleanup complete.\n";
}

