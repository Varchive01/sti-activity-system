<?php
// tests/test_pending_isolation.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "======================================================================\n";
echo " PENDING & RETURNED PROPOSALS ISOLATION TEST SUITE\n";
echo "======================================================================\n";

$db = getDB();
$pass = 0;
$fail = 0;

function assertCheck($cond, $msg) {
    global $pass, $fail;
    if ($cond) {
        echo " [PASS] $msg\n";
        $pass++;
    } else {
        echo " [FAIL] $msg\n";
        $fail++;
    }
}

// 1. Fetch Dave (Program Head) and Maria (Faculty)
$uStmt = $db->query("SELECT id, name, role, department FROM users WHERE name LIKE '%Dave%' LIMIT 1");
$dave = $uStmt->fetch(PDO::FETCH_ASSOC);

$mStmt = $db->query("SELECT id, name, role, department FROM users WHERE name LIKE '%Maria%' LIMIT 1");
$maria = $mStmt->fetch(PDO::FETCH_ASSOC);

if (!$dave || !$maria) {
    echo "Could not find Dave and Maria in database.\n";
    exit(1);
}

// 2. Insert test proposals
$insAct = $db->prepare("
    INSERT INTO activities (faculty_id, title, description, venue, event_date, status, created_at, updated_at)
    VALUES (?, ?, 'Pending isolation test', 'Auditorium', '2027-02-14', 'under_review', NOW(), NOW())
");

// Maria's proposal
$insAct->execute([$maria['id'], 'Maria Test Proposal Alpha']);
$mariaActId = (int)$db->lastInsertId();

// Dave's proposal
$insAct->execute([$dave['id'], 'Dave Test Proposal Beta']);
$daveActId = (int)$db->lastInsertId();

// 3. Query Dave's pending proposals using the new pending logic
$hasCollabTable = true;
$whereDave = "WHERE (a.faculty_id = {$dave['id']} OR EXISTS (SELECT 1 FROM proposal_collaborators pc WHERE pc.activity_id = a.id AND pc.user_id = {$dave['id']} AND pc.status = 'active')) AND a.status IN ('submitted', 'under_review', 'endorsed', 'pending_final_approval', 'resubmitted')";

$davePending = $db->query("SELECT a.id, a.title FROM activities a $whereDave")->fetchAll(PDO::FETCH_ASSOC);
$daveTitles = array_column($davePending, 'title');

assertCheck(in_array('Dave Test Proposal Beta', $daveTitles, true), "Dave sees his own pending proposal.");
assertCheck(!in_array('Maria Test Proposal Alpha', $daveTitles, true), "Dave does NOT see Maria's pending proposal when not a collaborator.");

// 4. Query Maria's pending proposals
$whereMaria = "WHERE (a.faculty_id = {$maria['id']} OR EXISTS (SELECT 1 FROM proposal_collaborators pc WHERE pc.activity_id = a.id AND pc.user_id = {$maria['id']} AND pc.status = 'active')) AND a.status IN ('submitted', 'under_review', 'endorsed', 'pending_final_approval', 'resubmitted')";

$mariaPending = $db->query("SELECT a.id, a.title FROM activities a $whereMaria")->fetchAll(PDO::FETCH_ASSOC);
$mariaTitles = array_column($mariaPending, 'title');

assertCheck(in_array('Maria Test Proposal Alpha', $mariaTitles, true), "Maria sees her own pending proposal.");
assertCheck(!in_array('Dave Test Proposal Beta', $mariaTitles, true), "Maria does NOT see Dave's pending proposal.");

// 5. Test Collaboration: Add Dave as collaborator to Maria's proposal
$insCollab = $db->prepare("INSERT INTO proposal_collaborators (activity_id, user_id, role, added_by, status) VALUES (?, ?, 'co_author', ?, 'active')");
$insCollab->execute([$mariaActId, $dave['id'], $maria['id']]);

$davePendingAfterCollab = $db->query("SELECT a.id, a.title FROM activities a $whereDave")->fetchAll(PDO::FETCH_ASSOC);
$daveTitlesAfterCollab = array_column($davePendingAfterCollab, 'title');

assertCheck(in_array('Maria Test Proposal Alpha', $daveTitlesAfterCollab, true), "When Dave IS explicitly added as a collaborator, Maria's proposal DOES appear in Dave's pending list.");

// Clean up
$db->exec("DELETE FROM proposal_collaborators WHERE activity_id IN ({$mariaActId}, {$daveActId})");
$db->exec("DELETE FROM activities WHERE id IN ({$mariaActId}, {$daveActId})");

echo "======================================================================\n";
echo " SUMMARY: Passed: $pass | Failed: $fail\n";
echo "======================================================================\n";

