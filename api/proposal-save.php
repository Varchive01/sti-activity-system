<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');

$user   = currentUser();
$db     = getDB();
$action = $_POST['action'] ?? 'draft';
$status = ($action === 'submit') ? 'submitted' : 'draft';

try {
    $db->beginTransaction();

    // 1. Insert activity
    $stmt = $db->prepare("INSERT INTO activities
        (faculty_id,title,description,theme,venue,venue_address,event_date,start_time,end_time,
         target_participants,general_objectives,specific_objectives,involved_subjects,rationale,
         evaluation_method,source,status,submitted_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $stmt->execute([
        $user['id'],
        sanitize($_POST['title'] ?? ''),
        sanitize($_POST['description'] ?? ''),
        sanitize($_POST['theme'] ?? ''),
        sanitize($_POST['venue'] ?? ''),
        sanitize($_POST['venue_address'] ?? ''),
        $_POST['event_date'] ?: null,
        $_POST['start_time'] ?: null,
        $_POST['end_time'] ?: null,
        (int)($_POST['target_participants'] ?? 0),
        sanitize($_POST['general_objectives'] ?? ''),
        sanitize($_POST['specific_objectives'] ?? ''),
        sanitize($_POST['involved_subjects'] ?? ''),
        sanitize($_POST['rationale'] ?? ''),
        sanitize($_POST['evaluation_method'] ?? ''),
        $_POST['source'] ?? 'faculty',
        $status,
        ($status === 'submitted') ? date('Y-m-d H:i:s') : null,
    ]);
    $actId = $db->lastInsertId();

    // 2. Materials
    if (!empty($_POST['mat_item'])) {
        $ms = $db->prepare("INSERT INTO materials (activity_id,item_name,description,quantity,provider,est_cost) VALUES (?,?,?,?,?,?)");
        foreach ($_POST['mat_item'] as $i => $name) {
            if (!trim($name)) continue;
            $ms->execute([$actId, sanitize($name), sanitize($_POST['mat_desc'][$i]??''),
                (int)($_POST['mat_qty'][$i]??1), sanitize($_POST['mat_provider'][$i]??''),
                (float)($_POST['mat_cost'][$i]??0)]);
        }
    }

    // 3. Program
    if (!empty($_POST['prog_segment'])) {
        $ps = $db->prepare("INSERT INTO program_sequence (activity_id,time_slot,segment,description,person_ic,sort_order) VALUES (?,?,?,?,?,?)");
        foreach ($_POST['prog_segment'] as $i => $seg) {
            if (!trim($seg)) continue;
            $ps->execute([$actId, $_POST['prog_time'][$i]??null, sanitize($seg),
                sanitize($_POST['prog_desc'][$i]??''), sanitize($_POST['prog_pic'][$i]??''), $i]);
        }
    }

    // 4. Manpower
    if (!empty($_POST['mp_role'])) {
        $mp = $db->prepare("INSERT INTO manpower (activity_id,role,assigned_person,type) VALUES (?,?,?,?)");
        foreach ($_POST['mp_role'] as $i => $role) {
            if (!trim($role)) continue;
            $mp->execute([$actId, sanitize($role), sanitize($_POST['mp_person'][$i]??''), $_POST['mp_type'][$i]??'faculty']);
        }
    }

    // 5. Schedule
    if (!empty($_POST['sched_event'])) {
        $sc = $db->prepare("INSERT INTO schedules (activity_id,sched_date,event_name,venue,organizer) VALUES (?,?,?,?,?)");
        foreach ($_POST['sched_event'] as $i => $ev) {
            if (!trim($ev)) continue;
            $sc->execute([$actId, $_POST['sched_date'][$i]??null, sanitize($ev),
                sanitize($_POST['sched_venue'][$i]??''), sanitize($_POST['sched_organizer'][$i]??'')]);
        }
    }

    // 6. Guidelines
    $gl = $db->prepare("INSERT INTO guidelines (activity_id,mechanics,criteria,scoring_system,special_awards) VALUES (?,?,?,?,?)");
    $gl->execute([$actId, sanitize($_POST['guidelines_mechanics']??''), sanitize($_POST['guidelines_criteria']??''),
        sanitize($_POST['guidelines_scoring']??''), sanitize($_POST['guidelines_awards']??'')]);

    // 7. Faculty Tasks
    if (!empty($_POST['ft_name'])) {
        $ft = $db->prepare("INSERT INTO faculty_tasks (activity_id,faculty_name,assigned_task,contribution_desc,role_in_event) VALUES (?,?,?,?,?)");
        foreach ($_POST['ft_name'] as $i => $name) {
            if (!trim($name)) continue;
            $ft->execute([$actId, sanitize($name), sanitize($_POST['ft_task'][$i]??''),
                sanitize($_POST['ft_contribution'][$i]??''), sanitize($_POST['ft_role'][$i]??'')]);
        }
    }

    // 8. Floor Plan upload
    $path = null;
    if (!empty($_FILES['floor_plan_file']['name'])) {
        $path = uploadFile($_FILES['floor_plan_file'], 'floorplans', $actId);
    } elseif (!empty($_POST['floor_plan_image'])) {
        // Base64 image
        $data = $_POST['floor_plan_image'];
        list($type, $data) = explode(';', $data);
        list(, $data)      = explode(',', $data);
        $data = base64_decode($data);
        $filename = 'fp_' . $actId . '_' . time() . '.png';
        $uploadDir = __DIR__ . '/../uploads/floorplans/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        file_put_contents($uploadDir . $filename, $data);
        $path = 'uploads/floorplans/' . $filename;
    }
    
    if ($path || !empty($_POST['floor_plan_json'])) {
        $fp = $db->prepare("INSERT INTO floor_plans (activity_id,file_path,notes,canvas_json) VALUES (?,?,?,?)");
        $fp->execute([$actId, $path, sanitize($_POST['floor_plan_notes']??''), $_POST['floor_plan_json'] ?? null]);
    }

    // 8.5 Poster upload
    if (!empty($_FILES['poster_file']['name'])) {
        $posterPath = uploadFile($_FILES['poster_file'], 'posters', $actId);
        if ($posterPath) {
            $db->prepare("UPDATE activities SET poster_path=? WHERE id=?")->execute([$posterPath, $actId]);
        }
    }

    // 9. KPIs
    if (!empty($_POST['kpi_indicator'])) {
        $kpi = $db->prepare("INSERT INTO kpi_evaluations (activity_id, indicator, target_metric, evaluation_method) VALUES (?,?,?,?)");
        foreach ($_POST['kpi_indicator'] as $i => $ind) {
            if (!trim($ind)) continue;
            $kpi->execute([
                $actId, 
                sanitize($ind), 
                sanitize($_POST['kpi_target'][$i] ?? ''), 
                sanitize($_POST['kpi_method'][$i] ?? '')
            ]);
        }
    }

    // 9. Routing logic
    if ($status === 'submitted') {
        $source = $_POST['source'] ?? 'faculty';
        $nextStatus = ($source === 'student_org') ? 'under_review' : 'submitted';
        $db->prepare("UPDATE activities SET status=? WHERE id=?")->execute([$nextStatus, $actId]);

        // Notify appropriate admin
        $notifyRole = ($source === 'student_org') ? 'admin1' : 'admin2';
        $admins = $db->prepare("SELECT id FROM users WHERE role=?");
        $admins->execute([$notifyRole]);
        $notifStmt = $db->prepare("INSERT INTO notifications (user_id,activity_id,message) VALUES (?,?,?)");
        foreach ($admins->fetchAll() as $admin) {
            $notifStmt->execute([$admin['id'], $actId, "New activity proposal submitted: " . sanitize($_POST['title']??'')]);
        }
    }

    $db->commit();
    header("Location: " . BASE_URL . "/faculty/dashboard.php?saved=1");
    exit;

} catch (Exception $e) {
    $db->rollBack();
    header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=1");
    exit;
}
