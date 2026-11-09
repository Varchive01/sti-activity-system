<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';

$db = getDB();

echo "========================================================\n";
echo "TRANSACTION TEST: DEAN DASHBOARD CARDS PARITY TEST\n";
echo "========================================================\n\n";

$db->beginTransaction();

try {
    // 1. Get an admin1 user and an admin2 user
    $admin1 = $db->query("SELECT id FROM users WHERE role = 'admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $admin2 = $db->query("SELECT id FROM users WHERE role = 'admin2' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $dean = $db->query("SELECT id FROM users WHERE role = 'dean' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $faculty = $db->query("SELECT id FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    $admin1Id = (int)$admin1['id'];
    $admin2Id = (int)$admin2['id'];
    $deanId = (int)$dean['id'];
    $facultyId = (int)$faculty['id'];

    // 2. Insert Activity 1: Returned for Revision by Admin 1
    $stmt1 = $db->prepare("
        INSERT INTO activities (title, faculty_id, source, event_date, status, created_at, updated_at)
        VALUES ('Test Return by Admin 1', ?, 'student_org', '2026-11-20', 'returned_for_revision', NOW(), NOW())
    ");
    $stmt1->execute([$facultyId]);
    $act1Id = (int)$db->lastInsertId();

    $log1 = $db->prepare("
        INSERT INTO approval_logs (activity_id, reviewer_id, action, notes, acted_at)
        VALUES (?, ?, 'returned_for_revision', 'Needs budget detail', NOW())
    ");
    $log1->execute([$act1Id, $admin1Id]);

    // 3. Insert Activity 2: Rejected by Admin 2
    $stmt2 = $db->prepare("
        INSERT INTO activities (title, faculty_id, source, event_date, status, created_at, updated_at)
        VALUES ('Test Reject by Admin 2', ?, 'student_org', '2026-11-25', 'rejected', NOW(), NOW())
    ");
    $stmt2->execute([$facultyId]);
    $act2Id = (int)$db->lastInsertId();

    $log2 = $db->prepare("
        INSERT INTO approval_logs (activity_id, reviewer_id, action, notes, acted_at)
        VALUES (?, ?, 'rejected', 'Venue conflict and overlaps exams', NOW())
    ");
    $log2->execute([$act2Id, $admin2Id]);

    // 4. Query with Dean ID
    $summary = getInstitutionalKpiSummary(2026, 'all', $db, $deanId);
    $stats = $summary['statistics'];

    echo "Stats on Dean Dashboard with Admin 1 Return & Admin 2 Rejection:\n";
    echo "  - Total: {$stats['total']}\n";
    echo "  - Pending: {$stats['pending']}\n";
    echo "  - Approved: {$stats['approved']}\n";
    echo "  - Completed: {$stats['completed']}\n";
    echo "  - Returned: {$stats['returned']}\n";
    echo "  - Rejected: {$stats['rejected']}\n";

    assert($stats['returned'] >= 1, "Returned count MUST include Admin 1 return!");
    assert($stats['rejected'] >= 1, "Rejected count MUST include Admin 2 rejection!");
    
    // Check All Activities page count parity
    $allCount = (int)$db->query("SELECT COUNT(*) FROM activities WHERE status != 'draft' AND YEAR(event_date) = 2026")->fetchColumn();
    assert($stats['total'] === $allCount, "Dean Total ({$stats['total']}) must equal all non-draft ({$allCount})!");

    echo "\n[SUCCESS] ALL CARDS PARITY AND INCLUSION CHECKS PASSED!\n";
} finally {
    $db->rollBack();
    echo "[INFO] Transaction rolled back cleanly.\n";
}
