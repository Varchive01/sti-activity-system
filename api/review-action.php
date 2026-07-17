<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin1', 'admin2', 'dean');

$user   = currentUser();
$db     = getDB();
$actId  = (int)($_POST['activity_id'] ?? 0);
$action = $_POST['action'] ?? '';
$role   = $_POST['role'] ?? '';
$notes  = sanitize($_POST['notes'] ?? '');

if (!$actId || !$action) { header('Location: ' . BASE_URL . '/'); exit; }

$activity = $db->prepare("SELECT * FROM activities WHERE id=?");
$activity->execute([$actId]);
$act = $activity->fetch();
if (!$act) die('Activity not found.');

// Determine new status
$newStatus = match(true) {
    $action === 'returned' => 'returned_for_revision',
    $action === 'rejected' => 'rejected',
    $action === 'approved' && $role === 'arjay' => 'endorsed',
    $action === 'approved' && $role === 'ian'   => 'pending_final_approval',
    $action === 'approved' && $role === 'dean'  => 'approved',
    default => $act['status'],
};

$logAction = ($action === 'returned') ? 'returned_for_revision' : (($action === 'approved' && in_array($role, ['arjay', 'ian'])) ? 'forwarded' : $action);

// Enforce comment requirement for rejection
if ($action === 'rejected' && trim($notes) === '') {
    $redirectMap = ['arjay' => 'admin1', 'ian' => 'admin2', 'dean' => 'dean'];
    $dir = $redirectMap[$role] ?? 'admin2';
    header("Location: " . BASE_URL . "/{$dir}/review.php?id={$actId}&error=" . urlencode("You must provide a comment when rejecting a proposal."));
    exit;
}

// ── Bundle section comments when returning ────────────────────────────────────
$revisionSectionsJson = null;
$sectionSummary       = '';
if ($action === 'returned') {
    $scStmt = $db->prepare("
        SELECT section_key, comment
        FROM section_comments
        WHERE activity_id = ? AND reviewer_id = ?
        ORDER BY section_key
    ");
    $scStmt->execute([$actId, $user['id']]);
    $sectionRows = $scStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($sectionRows)) {
        // Enforce requirement: Approvers cannot return a proposal without adding a comment to the section they want the faculty to revise.
        $redirectMap = ['arjay' => 'admin1', 'ian' => 'admin2', 'dean' => 'dean'];
        $dir = $redirectMap[$role] ?? 'admin2';
        header("Location: " . BASE_URL . "/{$dir}/review.php?id={$actId}&error=" . urlencode("You must add a comment to at least one section before returning the proposal for revision."));
        exit;
    }

    $sectionMap = [];
    $summaryParts = [];
    foreach ($sectionRows as $row) {
        $sectionMap[$row['section_key']] = $row['comment'];
        $label = ucwords(str_replace('_', ' ', $row['section_key']));
        $summaryParts[] = "[{$label}]: {$row['comment']}";
    }
    $revisionSectionsJson = json_encode($sectionMap, JSON_UNESCAPED_UNICODE);
    $sectionSummary = implode(' | ', $summaryParts);

    // Merge section summary into the general notes
    if ($sectionSummary) {
        $notes = $notes
            ? $notes . "\n\nSection-specific comments:\n" . $sectionSummary
            : "Section-specific comments:\n" . $sectionSummary;
    }
}


$db->beginTransaction();
try {
    // Update activity
    $db->prepare("
        UPDATE activities
        SET status=?, revision_notes=?, revision_sections=?, approved_at=?
        WHERE id=?
    ")->execute([
        $newStatus,
        $notes,
        $revisionSectionsJson,
        ($newStatus === 'approved') ? date('Y-m-d H:i:s') : null,
        $actId
    ]);

    // Approval log
    $db->prepare("INSERT INTO approval_logs (activity_id,reviewer_id,action,notes) VALUES (?,?,?,?)")
       ->execute([$actId, $user['id'], $logAction, $notes]);

    // Clear staged section comments after bundling them into revision_sections
    if ($action === 'returned') {
        $db->prepare("DELETE FROM section_comments WHERE activity_id = ? AND reviewer_id = ?")
           ->execute([$actId, $user['id']]);
    }

    // Notify faculty
    $notifMsg = match($logAction) {
        'forwarded' => "Your proposal '{$act['title']}' has been forwarded to the next reviewer.",
        'approved'  => "Congratulations! Your proposal '{$act['title']}' has been fully APPROVED.",
        'returned'  => "Your proposal '{$act['title']}' has been returned for revision. Please check the section comments.",
        'rejected'  => "Your proposal '{$act['title']}' has been rejected. Notes: {$notes}",
        default     => "Your proposal '{$act['title']}' status has been updated.",
    };
    $db->prepare("INSERT INTO notifications (user_id,activity_id,message) VALUES (?,?,?)")
       ->execute([$act['faculty_id'], $actId, $notifMsg]);

    // If endorsed, notify Ian
    if ($newStatus === 'endorsed') {
        $admins = $db->prepare("SELECT id FROM users WHERE role='admin2'");
        $admins->execute();
        $ns = $db->prepare("INSERT INTO notifications (user_id,activity_id,message) VALUES (?,?,?)");
        foreach ($admins->fetchAll() as $admin) {
            $ns->execute([$admin['id'], $actId, "New endorsed proposal forwarded by Sir Ar-jay: " . sanitize($act['title'])]);
        }
    }

    // If pending_final_approval, notify Dean
    if ($newStatus === 'pending_final_approval') {
        $deans = $db->prepare("SELECT id FROM users WHERE role='dean'");
        $deans->execute();
        $ns = $db->prepare("INSERT INTO notifications (user_id,activity_id,message) VALUES (?,?,?)");
        foreach ($deans->fetchAll() as $d) {
            $ns->execute([$d['id'], $actId, "New proposal requires final approval: " . sanitize($act['title'])]);
        }
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
}

$redirectMap = ['arjay' => 'admin1', 'ian' => 'admin2', 'dean' => 'dean'];
$dir = $redirectMap[$role] ?? 'admin2';
header("Location: " . BASE_URL . "/{$dir}/dashboard.php?action_done=1");
exit;
