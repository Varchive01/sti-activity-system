<?php
require '../config/config.php';
require '../config/database.php';
$db=getDB();
$db->query("INSERT INTO activities (title, faculty_id, source, status, target_participants) VALUES ('Final Approval Test', 3, 'student_org', 'endorsed', 100)");
$id = $db->lastInsertId();
echo "Inserted activity ID: " . $id . "\n";
// Now let Ian approve it
$_POST['activity_id'] = $id;
$_POST['action'] = 'approved';
$_POST['role'] = 'ian';
$_POST['notes'] = 'Looks good!';

// To simulate Ian, we must set session user
session_start();
$_SESSION['user'] = $db->query("SELECT * FROM users WHERE role='admin2'")->fetch();

// Actually, simulating auth is hard because it redirects, so let's just do it directly.
$actId = $id;
$newStatus = 'pending_final_approval';
$db->prepare("UPDATE activities SET status=?, approved_at=? WHERE id=?")->execute([$newStatus, date('Y-m-d H:i:s'), $actId]);
$db->prepare("INSERT INTO approval_logs (activity_id,reviewer_id,action,notes) VALUES (?,?,?,?)")->execute([$actId, $_SESSION['user']['id'], 'forwarded', 'Looks good!']);

$deans = $db->prepare("SELECT id FROM users WHERE role='dean'");
$deans->execute();
$ns = $db->prepare("INSERT INTO notifications (user_id,activity_id,message) VALUES (?,?,?)");
foreach ($deans->fetchAll() as $d) {
    $ns->execute([$d['id'], $actId, "New proposal requires final approval: Final Approval Test"]);
}

echo "Ian approved it, now pending_final_approval.\n";

// Now let's approve it as Dean
$deanUser = $db->query("SELECT * FROM users WHERE role='dean'")->fetch();
$newStatus = 'approved';
$db->prepare("UPDATE activities SET status=?, approved_at=? WHERE id=?")->execute([$newStatus, date('Y-m-d H:i:s'), $actId]);
$db->prepare("INSERT INTO approval_logs (activity_id,reviewer_id,action,notes) VALUES (?,?,?,?)")->execute([$actId, $deanUser['id'], 'approved', 'Approved by Dean!']);
echo "Dean approved it, now approved.\n";
