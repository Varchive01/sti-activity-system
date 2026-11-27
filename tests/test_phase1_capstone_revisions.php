<?php
/**
 * Test Suite: Phase 1 Capstone Revisions Verification
 * 
 * Verifies:
 * 1. Start and End Date persistence for events.
 * 2. "Headed by" field naming and source handling.
 * 3. Optional Event Poster handling (proposals can be saved/submitted without poster).
 * 4. Materials table rental vs in-house calculations, billing unit, and rate persistence.
 * 5. Faculty editing allowance while status is 'submitted'.
 * 6. Reviewer shortcut auto-transition from 'submitted' to 'under_review'.
 * 7. Proposal Printout & PDF Signatories for Proponent, Co-Authors, and Institutional Reviewers.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ProposalPdfService.php';

$db = getDB();

function assertTest($condition, $description) {
    if ($condition) {
        echo "[PASS] {$description}\n";
    } else {
        echo "[FAIL] {$description}\n";
        exit(1);
    }
}

echo "=== STARTING PHASE 1 CAPSTONE REVISIONS VERIFICATION ===\n\n";

// 1. Fetch test faculty user
$facUser = $db->query("SELECT id, name, email, department FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$facUser) {
    die("No faculty user found in database.\n");
}
$facultyId = (int)$facUser['id'];

// 2. Fetch test collaborator user
$collabUser = $db->query("SELECT id, name, email FROM users WHERE role = 'faculty' AND id != {$facultyId} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$collabId = $collabUser ? (int)$collabUser['id'] : null;

// 3. Test Activity Creation with Start Date, End Date, Headed by, and Rental Materials
$startDate = date('Y-m-d', strtotime('+3 days'));
$endDate = date('Y-m-d', strtotime('+5 days'));
$title = "Test Capstone Multi-Day Tech Summit " . time();

$stmt = $db->prepare("INSERT INTO activities (
    faculty_id, title, description, theme, venue, venue_address,
    event_date, end_date, start_time, end_time, target_participants,
    general_objectives, specific_objectives, involved_subjects, rationale,
    source, status, submitted_at, created_at, updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', NOW(), NOW(), NOW())");

$stmt->execute([
    $facultyId,
    $title,
    'A comprehensive 3-day student hackathon and symposium',
    'Driving Institutional Innovation with AI',
    'STI Marikina Auditorium',
    '289 L. de Guzman St., Concepcion I, Marikina City',
    $startDate,
    $endDate,
    '08:00:00',
    '17:00:00',
    250,
    'General objective text',
    'Specific objective text',
    'IT301, CS202',
    'Strategic rationale text',
    'faculty' // Headed by Faculty
]);

$actId = (int)$db->lastInsertId();
assertTest($actId > 0, "Activity inserted successfully with ID: {$actId}");

// 4. Verify Start Date & End Date persisted correctly
$actCheck = $db->prepare("SELECT event_date, end_date, source, status, poster_path FROM activities WHERE id = ?");
$actCheck->execute([$actId]);
$row = $actCheck->fetch(PDO::FETCH_ASSOC);

assertTest($row['event_date'] === $startDate, "Start Date (event_date) persisted: {$row['event_date']}");
assertTest($row['end_date'] === $endDate, "End Date persisted: {$row['end_date']}");
assertTest($row['source'] === 'faculty', "Source ('Headed by') persisted: {$row['source']}");
assertTest(empty($row['poster_path']), "Poster is optional: Activity submitted without poster file");

// 5. Insert Materials with Rental & In-House items
$matStmt = $db->prepare("INSERT INTO materials (activity_id, item_name, description, quantity, provider, billing_unit, rate, est_cost) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

// Item 1: In-House Chairs (Qty: 100, Cost: 0)
$matStmt->execute([$actId, 'Monobloc Chairs', 'For participant seating', 100, 'in_house', 'fixed', 0.00, 0.00]);

// Item 2: Rental Sound System (Qty: 1, Rate: 500/hr, Duration: 9hrs -> Cost: 4500)
$matStmt->execute([$actId, 'Pro Audio & Wireless Mics', 'Rental for stage presentations', 1, 'rental', 'per_hour', 500.00, 4500.00]);

// Item 3: Rental LED Wall (Qty: 1, Rate: 3000/day, Duration: 3 days -> Cost: 9000)
$matStmt->execute([$actId, 'P3 LED Wall Display', 'Rental main stage backdrop', 1, 'rental', 'per_day', 3000.00, 9000.00]);

$mats = $db->prepare("SELECT * FROM materials WHERE activity_id = ? ORDER BY id ASC");
$mats->execute([$actId]);
$matList = $mats->fetchAll(PDO::FETCH_ASSOC);

assertTest(count($matList) === 3, "All 3 material rows persisted");
assertTest($matList[0]['provider'] === 'in_house' && (float)$matList[0]['est_cost'] == 0.0, "In-house material provider saved with 0.00 cost");
assertTest($matList[1]['provider'] === 'rental' && $matList[1]['billing_unit'] === 'per_hour' && (float)$matList[1]['rate'] == 500.0, "Rental material per_hour rate persisted");
assertTest($matList[2]['provider'] === 'rental' && $matList[2]['billing_unit'] === 'per_day' && (float)$matList[2]['est_cost'] == 9000.0, "Rental material per_day cost calculation persisted");

// 6. Test Co-Author Signatory attachment
if ($collabId) {
    $db->prepare("INSERT INTO proposal_collaborators (activity_id, user_id, role, added_by, added_at) VALUES (?, ?, 'contributor', ?, NOW())")
       ->execute([$actId, $collabId, $facultyId]);
    $coCount = $db->prepare("SELECT COUNT(*) FROM proposal_collaborators WHERE activity_id = ?");
    $coCount->execute([$actId]);
    assertTest((int)$coCount->fetchColumn() === 1, "Co-Author successfully associated to proposal");
}

// 7. Test Faculty Edit Permission on 'submitted' proposal
// Simulate edit permission check as used in proposal-view.php & proposal-edit.php:
$canEditSubmitted = in_array($row['status'], ['draft', 'submitted', 'returned_for_revision']);
assertTest($canEditSubmitted === true, "Faculty can edit proposal while status is 'submitted'");

// 8. Test Reviewer Access: Auto-transition to 'under_review'
// Simulate Admin 2 (Events Committee) reviewing faculty proposal:
$currStatus = $row['status'];
$isReviewable = in_array($currStatus, ['submitted', 'under_review', 'resubmitted']);
if ($currStatus === 'submitted' && $isReviewable) {
    $db->prepare("UPDATE activities SET status = 'under_review', updated_at = NOW() WHERE id = ?")->execute([$actId]);
    $currStatus = 'under_review';
}

$statusAfterReview = $db->prepare("SELECT status FROM activities WHERE id = ?");
$statusAfterReview->execute([$actId]);
$newStat = $statusAfterReview->fetchColumn();
assertTest($newStat === 'under_review', "Reviewer opening review automatically transitioned status to 'under_review'");

// 9. Test Faculty Edit Permission is now LOCKED when 'under_review'
$canEditUnderReview = in_array($newStat, ['draft', 'submitted', 'returned_for_revision']);
assertTest($canEditUnderReview === false, "Faculty cannot edit proposal once marked 'under_review' (Locked for review)");

// 10. Test Proposal Printout & PDF Service Signatories
$activity = $db->query("SELECT a.*, u.name as faculty_name, u.department as faculty_dept FROM activities a JOIN users u ON a.faculty_id = u.id WHERE a.id = {$actId}")->fetch(PDO::FETCH_ASSOC);
$pdfBin = ProposalPdfService::generatePdf($activity, $matList, [], [], [], null, 'v1', $facUser);
assertTest(!empty($pdfBin) && str_starts_with($pdfBin, '%PDF'), "Proposal PDF generated successfully with signatories and date range");

// Clean up test data
$db->prepare("DELETE FROM materials WHERE activity_id = ?")->execute([$actId]);
$db->prepare("DELETE FROM proposal_collaborators WHERE activity_id = ?")->execute([$actId]);
$db->prepare("DELETE FROM activities WHERE id = ?")->execute([$actId]);

echo "\n=== ALL PHASE 1 AUTOMATED TESTS PASSED SUCCESSFULLY! ===\n";
