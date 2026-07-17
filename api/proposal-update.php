<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');

$user   = currentUser();
$db     = getDB();
$id     = (int)($_POST['activity_id'] ?? 0);
$action = $_POST['action'] ?? 'draft';

// Verify ownership
$check = $db->prepare("SELECT * FROM activities WHERE id=? AND faculty_id=?");
$check->execute([$id, $user['id']]);
if (!$check->fetch()) { header('Location: '.BASE_URL.'/faculty/activities.php'); exit; }

// Fetch current status to prevent losing returned_for_revision state
$curr = $db->prepare("SELECT status FROM activities WHERE id=?");
$curr->execute([$id]);
$currStatus = $curr->fetchColumn();

if ($action === 'submit') {
    $newStatus = 'submitted';
    $clearNotes = true;
} else {
    // If it was returned for revision, keep that status when saving as draft
    $newStatus = ($currStatus === 'returned_for_revision') ? 'returned_for_revision' : 'draft';
    $clearNotes = false;
}

$source = sanitize($_POST['source'] ?? 'faculty');

try {
    $db->beginTransaction();

    if ($clearNotes) {
        $db->prepare("UPDATE activities SET
            title=?,theme=?,venue=?,venue_address=?,event_date=?,start_time=?,end_time=?,
            target_participants=?,general_objectives=?,specific_objectives=?,
            involved_subjects=?,rationale=?,evaluation_method=?,source=?,
            status=?,revision_notes=NULL,revision_sections=NULL,submitted_at=?,updated_at=NOW()
            WHERE id=?")->execute([
            sanitize($_POST['title']??''), sanitize($_POST['theme']??''), sanitize($_POST['venue']??''), sanitize($_POST['venue_address']??''),
            $_POST['event_date']??null, $_POST['start_time']??null, $_POST['end_time']??null,
            (int)($_POST['target_participants']??0),
            sanitize($_POST['general_objectives']??''), sanitize($_POST['specific_objectives']??''),
            sanitize($_POST['involved_subjects']??''), sanitize($_POST['rationale']??''),
            sanitize($_POST['evaluation_method']??''),
            $source, $newStatus,
            date('Y-m-d H:i:s'), $id
        ]);
    } else {
        $db->prepare("UPDATE activities SET
            title=?,theme=?,venue=?,venue_address=?,event_date=?,start_time=?,end_time=?,
            target_participants=?,general_objectives=?,specific_objectives=?,
            involved_subjects=?,rationale=?,evaluation_method=?,source=?,
            status=?,updated_at=NOW()
            WHERE id=?")->execute([
            sanitize($_POST['title']??''), sanitize($_POST['theme']??''), sanitize($_POST['venue']??''), sanitize($_POST['venue_address']??''),
            $_POST['event_date']??null, $_POST['start_time']??null, $_POST['end_time']??null,
            (int)($_POST['target_participants']??0),
            sanitize($_POST['general_objectives']??''), sanitize($_POST['specific_objectives']??''),
            sanitize($_POST['involved_subjects']??''), sanitize($_POST['rationale']??''),
            sanitize($_POST['evaluation_method']??''),
            $source, $newStatus, $id
        ]);
    }

    // Replace child tables
    $db->prepare("DELETE FROM materials WHERE activity_id=?")->execute([$id]);
    if (!empty($_POST['mat_item'])) {
        $ms = $db->prepare("INSERT INTO materials(activity_id,item_name,description,quantity,provider,est_cost)VALUES(?,?,?,?,?,?)");
        foreach ($_POST['mat_item'] as $i=>$name) {
            if (!trim($name)) continue;
            $ms->execute([$id,sanitize($name),sanitize($_POST['mat_desc'][$i]??''),(int)($_POST['mat_qty'][$i]??1),sanitize($_POST['mat_provider'][$i]??''),(float)($_POST['mat_cost'][$i]??0)]);
        }
    }

    $db->prepare("DELETE FROM program_sequence WHERE activity_id=?")->execute([$id]);
    if (!empty($_POST['prog_segment'])) {
        $ps = $db->prepare("INSERT INTO program_sequence(activity_id,time_slot,segment,description,person_ic,sort_order)VALUES(?,?,?,?,?,?)");
        foreach ($_POST['prog_segment'] as $i=>$seg) {
            if (!trim($seg)) continue;
            $ps->execute([$id,$_POST['prog_time'][$i]??null,sanitize($seg),sanitize($_POST['prog_desc'][$i]??''),sanitize($_POST['prog_pic'][$i]??''),$i]);
        }
    }

    $db->prepare("DELETE FROM manpower WHERE activity_id=?")->execute([$id]);
    if (!empty($_POST['mp_role'])) {
        $mp = $db->prepare("INSERT INTO manpower(activity_id,role,assigned_person,type)VALUES(?,?,?,?)");
        foreach ($_POST['mp_role'] as $i=>$role) {
            if (!trim($role)) continue;
            $mp->execute([$id,sanitize($role),sanitize($_POST['mp_person'][$i]??''),$_POST['mp_type'][$i]??'faculty']);
        }
    }

    $db->prepare("DELETE FROM schedules WHERE activity_id=?")->execute([$id]);
    if (!empty($_POST['sched_event'])) {
        $sc = $db->prepare("INSERT INTO schedules(activity_id,sched_date,event_name,venue,organizer)VALUES(?,?,?,?,?)");
        foreach ($_POST['sched_event'] as $i=>$ev) {
            if (!trim($ev)) continue;
            $sc->execute([$id,$_POST['sched_date'][$i]??null,sanitize($ev),sanitize($_POST['sched_venue'][$i]??''),sanitize($_POST['sched_organizer'][$i]??'')]);
        }
    }

    // Guidelines upsert
    $db->prepare("DELETE FROM guidelines WHERE activity_id=?")->execute([$id]);
    $db->prepare("INSERT INTO guidelines(activity_id,mechanics,criteria,scoring_system,special_awards)VALUES(?,?,?,?,?)")->execute([
        $id,sanitize($_POST['guidelines_mechanics']??''),sanitize($_POST['guidelines_criteria']??''),
        sanitize($_POST['guidelines_scoring']??''),sanitize($_POST['guidelines_awards']??'')
    ]);

    $db->prepare("DELETE FROM faculty_tasks WHERE activity_id=?")->execute([$id]);
    if (!empty($_POST['ft_name'])) {
        $ft = $db->prepare("INSERT INTO faculty_tasks(activity_id,faculty_name,assigned_task,contribution_desc,role_in_event)VALUES(?,?,?,?,?)");
        foreach ($_POST['ft_name'] as $i=>$name) {
            if (!trim($name)) continue;
            $ft->execute([$id,sanitize($name),sanitize($_POST['ft_task'][$i]??''),sanitize($_POST['ft_contribution'][$i]??''),sanitize($_POST['ft_role'][$i]??'')]);
        }
    }

    // Floor plan update
    $path = null;
    $hasNewImage = false;
    if (!empty($_FILES['floor_plan_file']['name'])) {
        $path = uploadFile($_FILES['floor_plan_file'], 'floorplans', $id);
        $hasNewImage = true;
    } elseif (!empty($_POST['floor_plan_image'])) {
        $data = $_POST['floor_plan_image'];
        list($type, $data) = explode(';', $data);
        list(, $data)      = explode(',', $data);
        $data = base64_decode($data);
        $filename = 'fp_' . $id . '_' . time() . '.png';
        $uploadDir = __DIR__ . '/../uploads/floorplans/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        file_put_contents($uploadDir . $filename, $data);
        $path = 'uploads/floorplans/' . $filename;
        $hasNewImage = true;
    }

    if ($hasNewImage) {
        $db->prepare("DELETE FROM floor_plans WHERE activity_id=?")->execute([$id]);
        $fp = $db->prepare("INSERT INTO floor_plans(activity_id,file_path,notes,canvas_json) VALUES (?,?,?,?)");
        $fp->execute([$id, $path, sanitize($_POST['floor_plan_notes']??''), $_POST['floor_plan_json'] ?? null]);
    } else {
        if (!empty($_POST['floor_plan_json'])) {
            $db->prepare("UPDATE floor_plans SET notes=?, canvas_json=? WHERE activity_id=?")
               ->execute([sanitize($_POST['floor_plan_notes']??''), $_POST['floor_plan_json'], $id]);
        } else {
            $db->prepare("UPDATE floor_plans SET notes=? WHERE activity_id=?")
               ->execute([sanitize($_POST['floor_plan_notes']??''), $id]);
        }
    }

    // 8.5 Poster update
    if (!empty($_FILES['poster_file']['name'])) {
        $posterPath = uploadFile($_FILES['poster_file'], 'posters', $id);
        if ($posterPath) {
            $db->prepare("UPDATE activities SET poster_path=? WHERE id=?")->execute([$posterPath, $id]);
        }
    }

    // KPIs
    $db->prepare("DELETE FROM kpi_evaluations WHERE activity_id=?")->execute([$id]);
    if (!empty($_POST['kpi_indicator'])) {
        $kpi = $db->prepare("INSERT INTO kpi_evaluations(activity_id,indicator,target_metric,evaluation_method)VALUES(?,?,?,?)");
        foreach ($_POST['kpi_indicator'] as $i=>$ind) {
            if (!trim($ind)) continue;
            $kpi->execute([$id,sanitize($ind),sanitize($_POST['kpi_target'][$i]??''),sanitize($_POST['kpi_method'][$i]??'')]);
        }
    }

    // Routing on resubmit
    if ($newStatus === 'submitted') {
        // Find last return reviewer role
        $logStmt = $db->prepare("
            SELECT al.*, u.role
            FROM approval_logs al
            JOIN users u ON al.reviewer_id = u.id
            WHERE al.activity_id = ? AND al.action = 'returned_for_revision'
            ORDER BY al.acted_at DESC LIMIT 1
        ");
        $logStmt->execute([$id]);
        $lastReturn = $logStmt->fetch();

        if ($lastReturn) {
            if ($lastReturn['role'] === 'admin1') {
                $nextStatus = 'under_review';
                $notifyRole = 'admin1';
            } elseif ($lastReturn['role'] === 'dean') {
                $nextStatus = 'pending_final_approval';
                $notifyRole = 'dean';
            } else {
                $nextStatus = 'submitted'; // Ian catches 'submitted'
                $notifyRole = 'admin2';
            }
        } else {
            $nextStatus = ($source === 'student_org') ? 'under_review' : 'submitted';
            $notifyRole = ($source === 'student_org') ? 'admin1' : 'admin2';
        }

        $db->prepare("UPDATE activities SET status=?, revision_sections=NULL WHERE id=?")->execute([$nextStatus,$id]);

        $admins = $db->prepare("SELECT id FROM users WHERE role=?");
        $admins->execute([$notifyRole]);
        $ns = $db->prepare("INSERT INTO notifications(user_id,activity_id,message)VALUES(?,?,?)");
        foreach ($admins->fetchAll() as $admin) {
            $ns->execute([$admin['id'],$id,"Resubmitted proposal: ".sanitize($_POST['title']??'')]);
        }
    }

    $db->commit();
    header("Location: ".BASE_URL."/faculty/proposal-view.php?id={$id}&updated=1");
    exit;
} catch (Exception $e) {
    $db->rollBack();
    header("Location: ".BASE_URL."/faculty/proposal-edit.php?id={$id}&error=1");
    exit;
}
