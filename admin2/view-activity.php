<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin2');
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: '.BASE_URL.'/admin2/dashboard.php'); exit; }

$act  = $db->prepare("SELECT a.*,u.name as fn,u.email as fe FROM activities a JOIN users u ON a.faculty_id=u.id WHERE a.id=?");
$act->execute([$id]); $activity=$act->fetch();
if (!$activity) die('Not found.');

$mats = $db->prepare("SELECT * FROM materials WHERE activity_id=?"); $mats->execute([$id]); $materials=$mats->fetchAll();
$prog = $db->prepare("SELECT * FROM program_sequence WHERE activity_id=? ORDER BY sort_order"); $prog->execute([$id]); $program=$prog->fetchAll();
$mp   = $db->prepare("SELECT * FROM manpower WHERE activity_id=?"); $mp->execute([$id]); $manpower=$mp->fetchAll();
$ft   = $db->prepare("SELECT * FROM faculty_tasks WHERE activity_id=?"); $ft->execute([$id]); $ftasks=$ft->fetchAll();
$gl   = $db->prepare("SELECT * FROM guidelines WHERE activity_id=?"); $gl->execute([$id]); $guidelines=$gl->fetch();
$pe   = $db->prepare("SELECT * FROM post_event WHERE activity_id=?"); $pe->execute([$id]); $postEvent=$pe->fetch();
$kpis = $db->prepare("SELECT criteria,AVG(rating) as avg FROM kpi_evaluations WHERE activity_id=? GROUP BY criteria"); $kpis->execute([$id]); $kpiData=$kpis->fetchAll();
$rca  = $db->prepare("SELECT * FROM root_cause_analysis WHERE activity_id=?"); $rca->execute([$id]); $rcas=$rca->fetchAll();
$logs = $db->prepare("SELECT al.*,u.name,u.role as reviewer_role FROM approval_logs al JOIN users u ON al.reviewer_id=u.id WHERE al.activity_id=? ORDER BY al.acted_at"); $logs->execute([$id]); $history=$logs->fetchAll();

$currentApprover = 'None';
if (in_array($activity['status'], ['under_review', 'resubmitted'])) {
    $currentApprover = 'Events Committee Head';
} elseif (in_array($activity['status'], ['endorsed', 'pending_final_approval'])) {
    $currentApprover = 'Dean';
} elseif ($activity['status'] === 'returned_for_revision') {
    $currentApprover = 'Student Organization (Faculty)';
} elseif (in_array($activity['status'], ['draft', 'submitted'])) {
    $currentApprover = 'Student Organization (Faculty)';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>View Activity – STI</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
<style>
.section-title{font-family:'Syne',sans-serif;font-size:.95rem;font-weight:800;padding:14px 0 8px;border-bottom:2px solid var(--accent);margin-bottom:14px;color:var(--accent);}
.info-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px;}
.info-tile{background:var(--bg-base);border-radius:8px;padding:12px 14px;}
.info-tile .lbl{font-size:.68rem;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);font-weight:700;}
.info-tile .val{font-weight:600;margin-top:3px;font-size:.88rem;}
.timeline-item{display:flex;gap:14px;margin-bottom:14px;}
.timeline-dot{width:10px;height:10px;border-radius:50%;background:var(--accent);margin-top:4px;flex-shrink:0;}
.timeline-line{width:1px;background:var(--border);margin:14px 4px 0;flex:0 0 1px;}
.kpi-star{color:var(--sti-gold);}
</style>
</head>
<body class="theme-ian">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">Activity Detail</div>
    <div class="topbar-right">
      <a href="<?= BASE_URL ?>/admin2/activities.php" class="btn btn-outline btn-sm">← Back</a>
      <button class="btn btn-primary btn-sm" onclick="window.print()">🖨 Print</button>
    </div>
  </header>
  <div class="content">

    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
      <div>
        <h1 style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;"><?= htmlspecialchars($activity['title']) ?></h1>
        <div class="text-sm text-muted" style="margin-top:4px;">Submitted by <?= htmlspecialchars($activity['fn']) ?> · <?= $activity['submitted_at'] ? date('F j, Y', strtotime($activity['submitted_at'])) : 'Not yet submitted' ?></div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;">
        <?= getStatusBadge($activity['status']) ?>
        <span class="badge badge-secondary"><?= ucfirst(str_replace('_',' ',$activity['source'])) ?></span>
      </div>
    </div>

    <!-- Event Info -->
    <div class="section-title">📄 Event Information</div>
    <div class="info-grid">
      <?php foreach([
        'Date'=>$activity['event_date']?date('F j, Y',strtotime($activity['event_date'])):'—',
        'Time'=>($activity['start_time']??'—').' – '.($activity['end_time']??'—'),
        'Venue'=>$activity['venue']??'—',
        'Theme'=>$activity['theme']??'—',
        'Target Participants'=>number_format($activity['target_participants']),
        'Address'=>$activity['venue_address']??'—',
      ] as $l=>$v): ?>
      <div class="info-tile"><div class="lbl"><?= $l ?></div><div class="val"><?= htmlspecialchars($v) ?></div></div>
      <?php endforeach; ?>
    </div>
    <div style="margin-bottom:18px;">
      <div class="text-sm fw-bold" style="margin-bottom:4px;">General Objectives</div>
      <div class="text-sm"><?= nl2br(htmlspecialchars($activity['general_objectives']??'—')) ?></div>
    </div>
    <div style="margin-bottom:24px;">
      <div class="text-sm fw-bold" style="margin-bottom:4px;">Specific Objectives</div>
      <div class="text-sm"><?= nl2br(htmlspecialchars($activity['specific_objectives']??'—')) ?></div>
    </div>

    <?php if (!empty($activity['poster_path'])): ?>
    <?php $posterUrl = str_starts_with($activity['poster_path'], 'http') ? $activity['poster_path'] : BASE_URL . '/' . $activity['poster_path']; ?>
    <div class="section-title">🖼️ Event Poster</div>
    <div style="margin-bottom:24px; text-align:center;">
      <a href="<?= htmlspecialchars($posterUrl) ?>" target="_blank">
        <img src="<?= htmlspecialchars($posterUrl) ?>" alt="Event Poster" style="max-width: 100%; max-height: 500px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
      </a>
    </div>
    <?php endif; ?>

    <!-- Materials -->
    <?php if (!empty($materials)): ?>
    <div class="section-title">📦 Materials Needed</div>
    <div class="table-wrap" style="margin-bottom:24px;">
      <table><thead><tr><th>Item</th><th>Description</th><th>Qty</th><th>Provider</th><th>Est. Cost</th></tr></thead>
      <tbody><?php foreach($materials as $m): ?>
      <tr><td><?= htmlspecialchars($m['item_name']) ?></td><td><?= htmlspecialchars($m['description']??'') ?></td><td><?= $m['quantity'] ?></td><td><?= htmlspecialchars($m['provider']??'') ?></td><td>₱<?= number_format($m['est_cost'],2) ?></td></tr>
      <?php endforeach; ?></tbody>
      <tfoot><tr><td colspan="4" style="text-align:right;font-weight:700;">Total Est. Cost:</td><td>₱<?= number_format(array_sum(array_map(fn($m)=>$m['est_cost']*$m['quantity'],$materials)),2) ?></td></tr></tfoot>
      </table>
    </div>
    <?php endif; ?>

    <!-- Program -->
    <?php if (!empty($program)): ?>
    <div class="section-title">🎯 Program Sequence</div>
    <div class="table-wrap" style="margin-bottom:24px;">
      <table><thead><tr><th>Time</th><th>Segment</th><th>Description</th><th>Person In-Charge</th></tr></thead>
      <tbody><?php foreach($program as $p): ?>
      <tr><td><?= $p['time_slot'] ?></td><td><strong><?= htmlspecialchars($p['segment']) ?></strong></td><td><?= htmlspecialchars($p['description']??'') ?></td><td><?= htmlspecialchars($p['person_ic']??'') ?></td></tr>
      <?php endforeach; ?></tbody></table>
    </div>
    <?php endif; ?>

    <!-- Manpower -->
    <?php if (!empty($manpower)): ?>
    <div class="section-title">👥 Manpower / People</div>
    <div class="table-wrap" style="margin-bottom:24px;">
      <table><thead><tr><th>Role</th><th>Assigned Person</th><th>Type</th></tr></thead>
      <tbody><?php foreach($manpower as $m): ?>
      <tr><td><?= htmlspecialchars($m['role']) ?></td><td><?= htmlspecialchars($m['assigned_person']??'') ?></td><td><span class="badge badge-secondary"><?= ucfirst($m['type']) ?></span></td></tr>
      <?php endforeach; ?></tbody></table>
    </div>
    <?php endif; ?>

    <!-- Faculty Tasks -->
    <?php if (!empty($ftasks)): ?>
    <div class="section-title">🧑‍🏫 Faculty Tasks & Contributions</div>
    <div class="table-wrap" style="margin-bottom:24px;">
      <table><thead><tr><th>Faculty</th><th>Assigned Task</th><th>Contribution</th><th>Role</th></tr></thead>
      <tbody><?php foreach($ftasks as $f): ?>
      <tr><td><?= htmlspecialchars($f['faculty_name']) ?></td><td><?= htmlspecialchars($f['assigned_task']??'') ?></td><td><?= htmlspecialchars($f['contribution_desc']??'') ?></td><td><?= htmlspecialchars($f['role_in_event']??'') ?></td></tr>
      <?php endforeach; ?></tbody></table>
    </div>
    <?php endif; ?>

    <!-- Guidelines -->
    <?php if ($guidelines): ?>
    <div class="section-title">📋 Event Guidelines</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:24px;">
      <?php foreach(['Mechanics'=>$guidelines['mechanics'],'Criteria'=>$guidelines['criteria'],'Scoring'=>$guidelines['scoring_system'],'Special Awards'=>$guidelines['special_awards']] as $l=>$v): if(!$v) continue; ?>
      <div class="info-tile"><div class="lbl"><?= $l ?></div><div style="font-size:.82rem;margin-top:6px;"><?= nl2br(htmlspecialchars($v)) ?></div></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Post-Event -->
    <?php if ($postEvent): ?>
    <div class="section-title">📊 Post-Event Results</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">
      <div>
        <div class="info-grid" style="grid-template-columns:1fr 1fr 1fr;">
          <?php
          $attPct = $postEvent['target_attendance'] > 0 ? round(($postEvent['actual_attendance']/$postEvent['target_attendance'])*100) : 0;
          foreach(['Actual Attendance'=>$postEvent['actual_attendance'],'Target'=>$postEvent['target_attendance'],'Attendance Rate'=>$attPct.'%','Satisfaction Score'=>number_format($postEvent['satisfaction_score'],1).'/5'] as $l=>$v):
          ?>
          <div class="info-tile"><div class="lbl"><?= $l ?></div><div class="val"><?= $v ?></div></div>
          <?php endforeach; ?>
        </div>
        <?php if ($postEvent['observations']): ?>
        <div class="info-tile" style="margin-top:10px;"><div class="lbl">Observations</div><div style="font-size:.82rem;margin-top:6px;"><?= nl2br(htmlspecialchars($postEvent['observations'])) ?></div></div>
        <?php endif; ?>
        <?php if ($postEvent['recommendations']): ?>
        <div class="info-tile" style="margin-top:10px;"><div class="lbl">Recommendations</div><div style="font-size:.82rem;margin-top:6px;"><?= nl2br(htmlspecialchars($postEvent['recommendations'])) ?></div></div>
        <?php endif; ?>
      </div>
      <div>
        <?php if (!empty($kpiData)): ?>
        <div class="info-tile">
          <div class="lbl" style="margin-bottom:12px;">KPI Breakdown</div>
          <?php foreach($kpiData as $k):
            $stars=round($k['avg']); ?>
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
            <span style="font-size:.78rem;"><?= htmlspecialchars($k['criteria']) ?></span>
            <div>
              <span class="kpi-star"><?= str_repeat('★',$stars).str_repeat('☆',4-$stars) ?></span>
              <strong style="font-size:.75rem;margin-left:4px;"><?= number_format($k['avg'],1) ?></strong>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- RCA -->
    <?php if (!empty($rcas)): ?>
    <div class="section-title">🔍 Root Cause Analysis</div>
    <?php foreach($rcas as $r): ?>
    <div class="info-tile" style="margin-bottom:12px;">
      <div style="display:flex;gap:10px;margin-bottom:8px;"><span class="badge badge-warning"><?= strtoupper(str_replace('_',' ',$r['method']??'')) ?></span></div>
      <div class="text-sm fw-bold">Problem:</div>
      <div class="text-sm" style="margin-bottom:8px;"><?= nl2br(htmlspecialchars($r['problem']??'')) ?></div>
      <div class="text-sm fw-bold">Causes:</div>
      <div class="text-sm" style="margin-bottom:8px;"><?= nl2br(htmlspecialchars($r['causes']??'')) ?></div>
      <div class="text-sm fw-bold">Analysis:</div>
      <div class="text-sm"><?= nl2br(htmlspecialchars($r['explanation']??'')) ?></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Approval Timeline -->
    <div class="section-title">📌 Approval Timeline</div>
    <div style="margin-bottom:24px; background:var(--bg-base); padding:16px; border-radius:8px;">
      <div style="margin-bottom:16px; padding-bottom:16px; border-bottom:1px solid var(--border);">
        <div style="display:flex; gap:20px; flex-wrap:wrap;">
          <div>
            <div style="font-size:0.75rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">Current Status</div>
            <div style="margin-top:4px;"><?= getStatusBadge($activity['status']) ?></div>
          </div>
          <div>
            <div style="font-size:0.75rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">Current Approver</div>
            <div style="margin-top:4px; font-weight:600; font-size:0.9rem;"><?= $currentApprover ?></div>
          </div>
        </div>
      </div>
      
      <?php if (!empty($history)): ?>
      <div style="font-size:0.85rem; font-weight:700; margin-bottom:12px; color:var(--text-muted);">APPROVAL HISTORY</div>
      <?php foreach($history as $h): ?>
      <div class="timeline-item">
        <div>
          <div class="timeline-dot" style="background:<?= in_array($h['action'],['approved','forwarded'])?'#16A34A':($h['action']==='returned'?'#D97706':'#DC2626') ?>"></div>
        </div>
        <div>
          <div style="font-size:.83rem;font-weight:600;"><?= htmlspecialchars($h['name']) ?> <span style="color:var(--text-muted); font-weight:400;">(<?= htmlspecialchars(ucwords(str_replace('_',' ',$h['reviewer_role']??''))) ?>)</span> — <?= ucfirst($h['action']) ?></div>
          <div class="text-sm text-muted"><?= date('F j, Y g:i A', strtotime($h['acted_at'])) ?></div>
          <?php if ($h['notes']): ?><div class="text-sm" style="margin-top:4px;font-style:italic;color:#4B5563;">"<?= nl2br(htmlspecialchars($h['notes'])) ?>"</div><?php endif; ?>
        </div>
      </div>
      <?php endforeach; else: ?>
      <div class="text-sm text-muted">No approval history yet.</div>
      <?php endif; ?>
    </div>

  </div>
</div>
</body>
</html>
