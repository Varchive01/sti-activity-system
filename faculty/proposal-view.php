<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');
$user = currentUser();
$db   = getDB();
$id   = (int)($_GET['id'] ?? 0);

$act = $db->prepare("SELECT a.*,u.name as faculty_name FROM activities a JOIN users u ON a.faculty_id=u.id WHERE a.id=? AND a.faculty_id=?");
$act->execute([$id, $user['id']]);
$activity = $act->fetch();
if (!$activity) { header('Location: '.BASE_URL.'/faculty/activities.php'); exit; }

$mats = $db->prepare("SELECT * FROM materials WHERE activity_id=?"); $mats->execute([$id]); $materials=$mats->fetchAll();
$prog = $db->prepare("SELECT * FROM program_sequence WHERE activity_id=? ORDER BY sort_order"); $prog->execute([$id]); $program=$prog->fetchAll();
$mp   = $db->prepare("SELECT * FROM manpower WHERE activity_id=?"); $mp->execute([$id]); $manpower=$mp->fetchAll();
$sc   = $db->prepare("SELECT * FROM schedules WHERE activity_id=?"); $sc->execute([$id]); $schedules=$sc->fetchAll();
$gl   = $db->prepare("SELECT * FROM guidelines WHERE activity_id=?"); $gl->execute([$id]); $guidelines=$gl->fetch();
$ft   = $db->prepare("SELECT * FROM faculty_tasks WHERE activity_id=?"); $ft->execute([$id]); $ftasks=$ft->fetchAll();
$fp   = $db->prepare("SELECT * FROM floor_plans WHERE activity_id=?"); $fp->execute([$id]); $floorplan=$fp->fetch();
$kpiStmt = $db->prepare("SELECT * FROM kpi_evaluations WHERE activity_id=?"); $kpiStmt->execute([$id]); $kpis=$kpiStmt->fetchAll();

$logs = $db->prepare("SELECT al.*,u.name FROM approval_logs al JOIN users u ON al.reviewer_id=u.id WHERE al.activity_id=? ORDER BY al.acted_at DESC"); $logs->execute([$id]); $history=$logs->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>View Proposal – STI</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css"></head>
<body class="theme-faculty">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">View Proposal</div>
    <div class="topbar-right">
      <a href="<?= BASE_URL ?>/faculty/activities.php" class="btn btn-outline btn-sm">← Back</a>
      <?php if (in_array($activity['status'],['draft','returned_for_revision'])): ?>
      <a href="<?= BASE_URL ?>/faculty/proposal-edit.php?id=<?= $id ?>" class="btn btn-primary btn-sm">Edit Proposal</a>
      <?php endif; ?>
    </div>
  </header>
  <div class="content">
    <?php if ($activity['revision_notes']): ?>
    <div class="alert alert-warning">
      <strong>Revision Notes:</strong> <?= htmlspecialchars($activity['revision_notes']) ?>
    </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:16px;">
      <div class="card-header"><h2><?= htmlspecialchars($activity['title']) ?> <?= getStatusBadge($activity['status']) ?></h2></div>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:16px;">
          <?php foreach([
            'Date'=>$activity['event_date'] ? date('F j, Y',strtotime($activity['event_date'])) : '—',
            'Time'=>($activity['start_time']??'—') . ' – ' . ($activity['end_time']??'—'),
            'Venue'=>$activity['venue']??'—',
            'Theme'=>$activity['theme']??'—',
            'Source'=>ucfirst(str_replace('_',' ',$activity['source'])),
            'Target'=>number_format($activity['target_participants']).' participants',
          ] as $label=>$val): ?>
          <div style="background:var(--bg-base);border-radius:8px;padding:12px 14px;">
            <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);font-weight:700;"><?= $label ?></div>
            <div style="font-weight:600;margin-top:3px;"><?= htmlspecialchars($val) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header"><h2>Objectives & Academic Alignment</h2></div>
      <div class="card-body">
        <div class="form-group"><strong>General Objectives</strong><p class="text-sm" style="margin-top:6px;"><?= nl2br(htmlspecialchars($activity['general_objectives']??'—')) ?></p></div>
        <div class="form-group"><strong>Specific Objectives</strong><p class="text-sm" style="margin-top:6px;"><?= nl2br(htmlspecialchars($activity['specific_objectives']??'—')) ?></p></div>
        <?php if (!empty($activity['involved_subjects'])): ?>
        <div class="form-group"><strong>Involved Subjects</strong><p class="text-sm" style="margin-top:6px;"><?= htmlspecialchars($activity['involved_subjects']) ?></p></div>
        <?php endif; ?>
        <?php if (!empty($activity['rationale'])): ?>
        <div class="form-group"><strong>Rationale</strong><p class="text-sm" style="margin-top:6px;"><?= nl2br(htmlspecialchars($activity['rationale'])) ?></p></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($materials)): ?>
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header"><h2>Materials Needed</h2></div>
      <div class="card-body" style="padding:0;">
        <table><thead><tr><th>Item</th><th>Description</th><th>Qty</th><th>Provider</th><th>Cost</th></tr></thead><tbody>
        <?php foreach($materials as $m): ?><tr><td><?=htmlspecialchars($m['item_name'])?></td><td><?=htmlspecialchars($m['description']??'')?></td><td><?=$m['quantity']?></td><td><?=htmlspecialchars($m['provider']??'')?></td><td>₱<?=number_format($m['est_cost'],2)?></td></tr><?php endforeach; ?>
        </tbody></table>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($activity['poster_path'])): ?>
    <?php $posterUrl = str_starts_with($activity['poster_path'], 'http') ? $activity['poster_path'] : BASE_URL . '/' . $activity['poster_path']; ?>
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header"><h2>Event Poster</h2></div>
      <div class="card-body">
        <div style="text-align: center;">
          <a href="<?= htmlspecialchars($posterUrl) ?>" target="_blank">
            <img src="<?= htmlspecialchars($posterUrl) ?>" alt="Event Poster" style="max-width: 100%; max-height: 500px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
          </a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($floorplan): ?>
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header"><h2>Floor Plan</h2></div>
      <div class="card-body">
        <?php if (!empty($floorplan['file_path'])): ?>
          <?php $fpUrl = str_starts_with($floorplan['file_path'], 'http') ? $floorplan['file_path'] : BASE_URL . '/' . $floorplan['file_path']; ?>
          <a href="<?= htmlspecialchars($fpUrl) ?>" target="_blank" class="btn btn-outline btn-sm">View Floor Plan Image</a>
        <?php endif; ?>
        <?php if (!empty($floorplan['notes'])): ?>
          <div style="margin-top:10px;"><strong>Notes:</strong> <p class="text-sm"><?= nl2br(htmlspecialchars($floorplan['notes'])) ?></p></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($manpower)): ?>
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header"><h2>People / Manpower</h2></div>
      <div class="card-body" style="padding:0;">
        <table><thead><tr><th>Role</th><th>Assigned Person</th><th>Type</th></tr></thead><tbody>
        <?php foreach($manpower as $m): ?><tr><td><?=htmlspecialchars($m['role'])?></td><td><?=htmlspecialchars($m['assigned_person']??'')?></td><td><?=ucfirst($m['type'])?></td></tr><?php endforeach; ?>
        </tbody></table>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($schedules) || !empty($program)): ?>
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header"><h2>Schedule & Program</h2></div>
      <div class="card-body" style="padding:0;">
        <?php if (!empty($schedules)): ?>
        <h3 style="padding:14px; margin:0; border-bottom:1px solid var(--border-color);">Schedule of Activities</h3>
        <table style="border-bottom:1px solid var(--border-color);"><thead><tr><th>Date</th><th>Event</th><th>Venue</th><th>Organizer</th></tr></thead><tbody>
        <?php foreach($schedules as $s): ?><tr><td><?=$s['sched_date']?></td><td><?=htmlspecialchars($s['event_name'])?></td><td><?=htmlspecialchars($s['venue']??'')?></td><td><?=htmlspecialchars($s['organizer']??'')?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
        
        <?php if (!empty($program)): ?>
        <h3 style="padding:14px; margin:0; border-bottom:1px solid var(--border-color);">Program Flow</h3>
        <table><thead><tr><th>Time</th><th>Segment</th><th>Description</th><th>Person In-Charge</th></tr></thead><tbody>
        <?php foreach($program as $p): ?><tr><td><?=$p['time_slot']?></td><td><strong><?=htmlspecialchars($p['segment'])?></strong></td><td><?=htmlspecialchars($p['description']??'')?></td><td><?=htmlspecialchars($p['person_ic']??'')?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($guidelines): ?>
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header"><h2>Guidelines</h2></div>
      <div class="card-body">
        <div class="form-group"><strong>Mechanics:</strong><p class="text-sm" style="margin-top:6px;"><?= nl2br(htmlspecialchars($guidelines['mechanics']??'—')) ?></p></div>
        <div class="form-group"><strong>Criteria for Judging:</strong><p class="text-sm" style="margin-top:6px;"><?= nl2br(htmlspecialchars($guidelines['criteria']??'—')) ?></p></div>
        <div class="form-group"><strong>Scoring System:</strong><p class="text-sm" style="margin-top:6px;"><?= nl2br(htmlspecialchars($guidelines['scoring_system']??'—')) ?></p></div>
        <div class="form-group"><strong>Special Awards:</strong><p class="text-sm" style="margin-top:6px;"><?= nl2br(htmlspecialchars($guidelines['special_awards']??'—')) ?></p></div>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($ftasks)): ?>
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header"><h2>Faculty Tasks</h2></div>
      <div class="card-body" style="padding:0;">
        <table><thead><tr><th>Faculty Name</th><th>Assigned Task</th><th>Contribution</th><th>Role</th></tr></thead><tbody>
        <?php foreach($ftasks as $f): ?><tr><td><?=htmlspecialchars($f['faculty_name'])?></td><td><?=htmlspecialchars($f['assigned_task']??'')?></td><td><?=htmlspecialchars($f['contribution_desc']??'')?></td><td><?=htmlspecialchars($f['role_in_event']??'')?></td></tr><?php endforeach; ?>
        </tbody></table>
      </div>
    </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:16px;">
      <div class="card-header"><h2>KPI & Evaluation</h2></div>
      <div class="card-body" style="padding:0;">
        <?php if (!empty($kpis)): ?>
        <table style="border-bottom:1px solid var(--border-color);"><thead><tr><th>Indicator</th><th>Target Metric</th><th>Evaluation Method</th></tr></thead><tbody>
        <?php foreach($kpis as $k): ?><tr><td><?=htmlspecialchars($k['indicator'])?></td><td><?=htmlspecialchars($k['target_metric']??'')?></td><td><?=htmlspecialchars($k['evaluation_method']??'')?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
        <div style="padding:14px;">
          <strong>Google Form Link:</strong> 
          <?php if (!empty($activity['evaluation_method'])): ?>
            <a href="<?= htmlspecialchars($activity['evaluation_method']) ?>" target="_blank"><?= htmlspecialchars($activity['evaluation_method']) ?></a>
          <?php else: ?>
            —
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if (!empty($history)): ?>
    <div class="card">
      <div class="card-header"><h2>Approval History</h2></div>
      <div class="card-body" style="padding:0;">
        <table><thead><tr><th>Reviewer</th><th>Action</th><th>Notes</th><th>Date</th></tr></thead><tbody>
        <?php foreach($history as $h): ?><tr><td><?=htmlspecialchars($h['name'])?></td><td><?=getStatusBadge($h['action'])?></td><td><?=htmlspecialchars($h['notes']??'—')?></td><td><?=date('M j, Y g:i A',strtotime($h['acted_at']))?></td></tr><?php endforeach; ?>
        </tbody></table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
</body></html>
