<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/proposal_validator.php';
requireRole('admin2');
$user = currentUser();
$db   = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: '.BASE_URL.'/admin2/dashboard.php'); exit; }

$act = $db->prepare("SELECT a.*,u.name as faculty_name,u.email as faculty_email FROM activities a JOIN users u ON a.faculty_id=u.id WHERE a.id=?");
$act->execute([$id]);
$activity = $act->fetch();
if (!$activity) { die('Activity not found or not accessible.'); }

$materials = $db->prepare("SELECT * FROM materials WHERE activity_id=?");
$materials->execute([$id]);
$mats = $materials->fetchAll();

$program = $db->prepare("SELECT * FROM program_sequence WHERE activity_id=? ORDER BY sort_order,time_slot");
$program->execute([$id]);
$prog = $program->fetchAll();

$manpower = $db->prepare("SELECT * FROM manpower WHERE activity_id=?");
$manpower->execute([$id]);
$mp = $manpower->fetchAll();

$schedules = $db->prepare("SELECT * FROM schedules WHERE activity_id=? ORDER BY sched_date");
$schedules->execute([$id]);
$scheds = $schedules->fetchAll();

$guideStmt = $db->prepare("SELECT * FROM guidelines WHERE activity_id=?");
$guideStmt->execute([$id]);
$guide = $guideStmt->fetch();

$ftStmt = $db->prepare("SELECT * FROM faculty_tasks WHERE activity_id=?");
$ftStmt->execute([$id]);
$ftasks = $ftStmt->fetchAll();

$fpStmt = $db->prepare("SELECT * FROM floor_plans WHERE activity_id=?");
$fpStmt->execute([$id]);
$fp = $fpStmt->fetch();

$kpiStmt = $db->prepare("SELECT * FROM kpi_evaluations WHERE activity_id=?");
$kpiStmt->execute([$id]);
$kpis = $kpiStmt->fetchAll();

$logs = $db->prepare("SELECT al.*,u.name FROM approval_logs al JOIN users u ON al.reviewer_id=u.id WHERE al.activity_id=? ORDER BY al.acted_at DESC");
$logs->execute([$id]);
$history = $logs->fetchAll();

// Staged section comments for this reviewer
$scStmt = $db->prepare("SELECT section_key,comment FROM section_comments WHERE activity_id=? AND reviewer_id=?");
$scStmt->execute([$id, $user['id']]);
$stagedComments = [];
foreach ($scStmt->fetchAll() as $sc) {
    $stagedComments[$sc['section_key']] = $sc['comment'];
}
$stagedCount = count($stagedComments);

$totalBudget = array_sum(array_column($mats, 'est_cost'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Review Proposal – STI Activity System</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
<style>
/* ── Sticky Action Bar ── */
.review-action-bar {
  position: sticky; top: 64px; z-index: 45;
  background: rgba(255, 255, 255, 0.96);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  border-bottom: 1px solid var(--border);
  padding: 14px 28px;
  display: flex; align-items: center; justify-content: space-between;
  gap: 16px; flex-wrap: wrap;
  box-shadow: 0 4px 20px rgba(10, 22, 40, 0.05);
}
.review-action-bar .proposal-meta { flex: 1; min-width: 0; }
.review-action-bar .proposal-title {
  font-family: 'Syne', sans-serif;
  font-size: 1.15rem; font-weight: 800;
  color: var(--sti-navy);
  letter-spacing: -0.01em;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  line-height: 1.3;
}
.review-action-bar .btn {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 0.82rem; font-weight: 600;
  padding: 0 16px; height: 38px;
  border-radius: var(--radius-sm);
  white-space: nowrap;
  transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
  box-shadow: var(--shadow-sm);
}
.review-action-bar .btn:hover { transform: translateY(-1px); box-shadow: var(--shadow); }
.review-action-bar .btn-sm { height: 38px; padding: 0 14px; }
.review-action-bar button[onclick*="return"] {
  border-color: var(--border);
  color: var(--sti-navy);
  background: #fff;
}
.review-action-bar button[onclick*="return"]:hover {
  background: var(--sti-gold-lt);
  border-color: var(--sti-gold);
  color: #92400e;
}

/* ── Section Card with Request Revision ── */
.review-section {
  margin-bottom: 24px;
  border-radius: var(--radius);
  border: 1px solid var(--border);
  background: #fff;
  overflow: hidden;
  box-shadow: 0 2px 8px rgba(10, 22, 40, 0.04);
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.review-section.flagged {
  border-color: var(--sti-gold);
  box-shadow: 0 0 0 2px rgba(244, 169, 0, 0.25), 0 4px 16px rgba(244, 169, 0, 0.08);
}
.review-section-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 22px; background: var(--bg-base); border-bottom: 1px solid var(--border);
  gap: 12px;
}
.review-section-header h2 {
  font-family: 'Syne', sans-serif;
  font-size: 0.98rem; font-weight: 700;
  color: var(--sti-navy);
  margin: 0; flex: 1;
  letter-spacing: -0.01em;
}
.btn-request-revision {
  background: #fff;
  border: 1.5px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 6px 14px;
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 0.75rem; font-weight: 700;
  cursor: pointer;
  color: var(--text-muted-dark);
  white-space: nowrap;
  display: inline-flex; align-items: center; gap: 6px;
  transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
.btn-request-revision:hover {
  background: var(--sti-gold-lt);
  border-color: var(--sti-gold);
  color: #92400e;
  box-shadow: 0 2px 6px rgba(244, 169, 0, 0.18);
  transform: translateY(-1px);
}
.btn-request-revision.has-comment {
  background: var(--sti-gold-lt);
  border-color: var(--sti-gold);
  color: #92400e;
  font-weight: 700;
}
.review-section-body { padding: 20px 24px; }

/* ── Inline Comment Box ── */
.section-comment-box {
  display: none;
  background: #FFFDF5;
  border-top: 1px solid rgba(244, 169, 0, 0.3);
  padding: 16px 22px; gap: 12px;
}
.section-comment-box.open {
  display: flex; align-items: flex-start; gap: 12px; flex-wrap: wrap;
  animation: commentSlideDown 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes commentSlideDown {
  from { opacity: 0; transform: translateY(-4px); }
  to { opacity: 1; transform: translateY(0); }
}
.section-comment-box textarea {
  flex: 1; min-width: 220px; min-height: 76px;
  border: 1.5px solid rgba(244, 169, 0, 0.4);
  border-radius: var(--radius-sm);
  padding: 10px 14px;
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 0.85rem; line-height: 1.45;
  resize: vertical;
  background: #fff;
  color: var(--text-main);
  transition: border-color 0.2s, box-shadow 0.2s;
}
.section-comment-box textarea:focus {
  outline: none;
  border-color: var(--sti-gold);
  box-shadow: 0 0 0 3px rgba(244, 169, 0, 0.2);
}
.btn-save-comment {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-weight: 700; font-size: 0.78rem;
  padding: 8px 16px; border-radius: var(--radius-sm);
  white-space: nowrap;
  background: var(--sti-gold); color: var(--sti-navy);
  border: none; cursor: pointer;
  display: inline-flex; align-items: center; gap: 6px;
  transition: all 0.2s ease;
}
.btn-save-comment:hover {
  background: var(--sti-gold-hover);
  box-shadow: 0 2px 6px rgba(244, 169, 0, 0.25);
  transform: translateY(-1px);
}
.comment-saved-notice {
  font-size: 0.72rem; color: #78350F; font-weight: 600;
  display: flex; align-items: center; gap: 4px;
}
.section-flagged-banner {
  background: var(--sti-gold-lt);
  border-top: 1px solid rgba(244, 169, 0, 0.3);
  padding: 10px 22px; font-size: 0.82rem; font-weight: 500;
  color: #78350F;
  display: flex; align-items: center; gap: 8px;
}
.btn-remove-comment {
  background: none; border: none; cursor: pointer;
  color: #92400e; font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 0.75rem; font-weight: 700;
  padding: 2px 8px; border-radius: 4px;
  transition: background 0.15s, color 0.15s;
}
.btn-remove-comment:hover {
  background: rgba(146, 64, 14, 0.1);
  color: #78350F;
}

/* ── Info Grid ── */
.info-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.info-block {
  background: var(--bg-base);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 12px 16px;
  transition: background 0.15s, border-color 0.15s;
}
.info-block:hover {
  background: #fff;
  border-color: rgba(0, 114, 206, 0.2);
}
.info-block .lbl {
  font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.5px;
  color: var(--text-muted); font-weight: 700;
}
.info-block .val {
  font-size: 0.9rem; font-weight: 600;
  color: var(--sti-navy);
  margin-top: 3px;
}

/* ── Flagged count badge ── */
.flagged-badge {
  background: var(--sti-gold);
  color: var(--sti-navy);
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 0.72rem; font-weight: 800; letter-spacing: 0.02em;
  padding: 2px 9px; border-radius: 99px;
  display: inline-flex; align-items: center; gap: 4px;
  box-shadow: 0 1px 3px rgba(244, 169, 0, 0.35);
  animation: badgePopIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes badgePopIn {
  from { transform: scale(0.85); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}

/* ── Action Modals ── */
.modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(10, 22, 40, 0.6);
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
  z-index: 200; align-items: center; justify-content: center;
  padding: 16px;
}
.modal-overlay.open {
  display: flex;
  animation: modalOverlayFade 0.2s ease-out;
}
@keyframes modalOverlayFade {
  from { opacity: 0; }
  to { opacity: 1; }
}
.modal {
  background: #fff;
  border-radius: var(--radius);
  padding: 28px 32px;
  max-width: 520px; width: 100%;
  box-shadow: 0 20px 40px rgba(10, 22, 40, 0.2);
  border: 1px solid var(--border);
  position: relative;
  animation: modalContentZoom 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes modalContentZoom {
  from { transform: scale(0.96); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}
.modal h3 {
  font-family: 'Syne', sans-serif;
  font-size: 1.18rem; font-weight: 800;
  color: var(--sti-navy);
  margin-bottom: 8px; letter-spacing: -0.01em;
}
.modal p { color: var(--text-body); line-height: 1.5; }
.modal .form-label {
  font-weight: 600; font-size: 0.82rem;
  color: var(--sti-navy); margin-bottom: 6px; display: block;
}
.modal .form-control {
  width: 100%; border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 10px 14px;
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 0.85rem;
  transition: border-color 0.2s, box-shadow 0.2s;
  box-sizing: border-box;
}
.modal .form-control:focus {
  outline: none;
  border-color: var(--sti-blue);
  box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.15);
}
.modal .alert {
  border-radius: var(--radius-sm);
  padding: 10px 14px; margin-bottom: 14px;
  font-size: 0.82rem; line-height: 1.45;
}
.modal .alert-warning {
  background: var(--sti-gold-lt);
  border: 1px solid rgba(244, 169, 0, 0.4);
  color: #78350F;
}
.modal .alert-danger {
  background: #FEF2F2;
  border: 1px solid #FCA5A5;
  color: #991B1B;
}
.modal .flex.gap-2 {
  display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;
}
#modal-return .modal { border-top: 4px solid var(--sti-gold); }
#modal-reject .modal { border-top: 4px solid var(--sti-red); }
#modal-approve .modal,
#modal-forward .modal { border-top: 4px solid var(--sti-blue); }

/* ── Section label ── */
.sl {
  font-size: 0.72rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.5px;
  color: var(--text-muted); margin-bottom: 6px;
}

/* ── Budget total row ── */
.budget-total {
  display: flex; justify-content: flex-end;
  padding: 12px 18px;
  background: var(--bg-base);
  font-weight: 700; font-size: 0.95rem;
  color: var(--sti-navy);
  border-top: 1px solid var(--border);
  border-bottom-left-radius: var(--radius);
  border-bottom-right-radius: var(--radius);
}

@media (max-width: 768px) {
  .review-action-bar { padding: 12px 16px; }
  .review-section-header { padding: 12px 16px; }
  .review-section-body { padding: 16px; }
  .info-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body class="theme-ian">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">

  <!-- Shared Topbar -->
  <header class="topbar">
    <div class="page-title">Review Proposal</div>
    <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
      <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>

  <div class="review-action-bar">
    <div class="proposal-meta">
      <div class="proposal-title"><?= htmlspecialchars($activity['title']) ?></div>
      <div class="text-sm text-muted">
        by <?= htmlspecialchars($activity['faculty_name']) ?>
        · Submitted <?= $activity['submitted_at'] ? date('M j, Y', strtotime($activity['submitted_at'])) : '—' ?>
        · <?= getStatusBadge($activity['status']) ?>
        <?php if ($stagedCount > 0): ?>
          &nbsp;<span class="flagged-badge"><?= $stagedCount ?> section<?= $stagedCount > 1 ? 's' : '' ?> flagged</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="flex gap-2">
      <a href="<?= BASE_URL ?>/admin2/dashboard.php" class="btn btn-outline btn-sm">← Back</a>
      <button class="btn btn-outline" onclick="openModal('return')">↩ Return for Revision</button>
      <button class="btn btn-danger"  onclick="openModal('reject')">✕ Reject</button>
      <button class="btn btn-success" onclick="openModal('forward')">✓ Approve Activity</button>
    </div>
  </div>

  <div class="content">

    <?php renderSectionHeader('event_details', '📋 Event Details', $stagedComments); ?>
      <div class="info-grid" style="margin-bottom:14px;">
        <div class="info-block"><div class="lbl">Event Title</div><div class="val"><?= htmlspecialchars($activity['title']) ?></div></div>
        <div class="info-block"><div class="lbl">Event Date</div><div class="val"><?= $activity['event_date'] ? date('F j, Y', strtotime($activity['event_date'])) : '—' ?></div></div>
        <div class="info-block"><div class="lbl">Time</div><div class="val"><?= $activity['start_time'] ? date('g:i A', strtotime($activity['start_time'])) : '—' ?> – <?= $activity['end_time'] ? date('g:i A', strtotime($activity['end_time'])) : '—' ?></div></div>
        <div class="info-block"><div class="lbl">Venue</div><div class="val"><?= htmlspecialchars($activity['venue'] ?? '—') ?></div></div>
        <div class="info-block"><div class="lbl">Venue Address</div><div class="val"><?= htmlspecialchars($activity['venue_address'] ?? '—') ?></div></div>
        <div class="info-block"><div class="lbl">Target Participants</div><div class="val"><?= number_format($activity['target_participants']) ?></div></div>
        <div class="info-block"><div class="lbl">Source</div><div class="val"><?= ucfirst(str_replace('_',' ',$activity['source'])) ?></div></div>
        <div class="info-block"><div class="lbl">Theme</div><div class="val"><?= htmlspecialchars($activity['theme'] ?? '—') ?></div></div>
      </div>
    <?php renderSectionClose('event_details', $stagedComments, $id); ?>

    <?php renderSectionHeader('objectives', '🎯 Objectives & Academic Alignment', $stagedComments); ?>
      <div class="sl">General Objectives</div>
      <p style="margin-bottom:14px;"><?= nl2br(htmlspecialchars($activity['general_objectives'] ?? '—')) ?></p>
      <div class="sl">Specific Objectives</div>
      <p><?= nl2br(htmlspecialchars($activity['specific_objectives'] ?? '—')) ?></p>
      <?php if (!empty($activity['involved_subjects'])): ?>
      <div class="sl" style="margin-top:14px;">Involved Subjects</div>
      <p><?= htmlspecialchars($activity['involved_subjects']) ?></p>
      <?php endif; ?>
      <?php if (!empty($activity['rationale'])): ?>
      <div class="sl" style="margin-top:14px;">Rationale</div>
      <p><?= nl2br(htmlspecialchars($activity['rationale'])) ?></p>
      <?php endif; ?>
    <?php renderSectionClose('objectives', $stagedComments, $id); ?>

    <?php renderSectionHeader('materials', '💰 Materials & Budget', $stagedComments); ?>
      <?php if (!empty($mats)): ?>
        <table style="width:100%;"><thead><tr><th>Item</th><th>Description</th><th>Qty</th><th>Provider</th><th>Est. Cost</th></tr></thead>
        <tbody><?php foreach ($mats as $m): ?>
          <tr><td><?= htmlspecialchars($m['item_name']) ?></td><td><?= htmlspecialchars($m['description']??'') ?></td><td><?= $m['quantity'] ?></td><td><?= htmlspecialchars($m['provider']??'') ?></td><td>₱<?= number_format($m['est_cost'],2) ?></td></tr>
        <?php endforeach; ?></tbody></table>
        <div class="budget-total">Grand Total: ₱<?= number_format($totalBudget,2) ?></div>
      <?php else: ?><p class="text-muted">No materials listed.</p><?php endif; ?>
    <?php renderSectionClose('materials', $stagedComments, $id); ?>

    <?php renderSectionHeader('floor_plan', '🗺️ Floor Plan', $stagedComments); ?>
      <?php if ($fp): ?>
        <?php
          $floorsList = [];
          if (!empty($fp['canvas_json'])) {
              $parsedFp = json_decode($fp['canvas_json'], true);
              if ($parsedFp && !empty($parsedFp['floors'])) {
                  foreach ($parsedFp['floors'] as $fKey => $fVal) {
                      $floorsList[] = $fVal['name'] ?? ucfirst($fKey) . ' Floor';
                  }
              }
          }
          if (empty($floorsList) && (!empty($fp['file_path']) || !empty($fp['canvas_json']))) {
              $floorsList = ['Ground Floor'];
          }
        ?>
        <?php if (!empty($floorsList)): ?>
          <div style="margin-bottom:10px; display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
            <span style="font-size:0.75rem; font-weight:600; color:#64748b;">Included Floors:</span>
            <?php foreach ($floorsList as $flName): ?>
              <span class="badge" style="background:#0056b3; color:#fff; font-size:0.75rem; padding:3px 8px; border-radius:4px;">🏢 <?= htmlspecialchars($flName) ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php $ext = strtolower(pathinfo($fp['file_path'] ?? '', PATHINFO_EXTENSION)); $isImg = in_array($ext,['png','jpg','jpeg','gif','webp']); ?>
        <?php $fpUrl = !empty($fp['file_path']) ? (str_starts_with($fp['file_path'], 'http') ? $fp['file_path'] : BASE_URL . '/' . $fp['file_path']) : ''; ?>
        <?php if($isImg && $fpUrl): ?>
          <img src="<?= htmlspecialchars($fpUrl) ?>" style="max-width:100%;border-radius:8px;margin-bottom:12px;" alt="Floor Plan">
        <?php elseif($fpUrl): ?>
          <a href="<?= htmlspecialchars($fpUrl) ?>" target="_blank" class="btn btn-outline btn-sm" style="margin-bottom:12px;">📎 View Floor Plan File</a>
        <?php endif; ?>
        <?php if (!empty($fp['notes'])): ?><div class="sl" style="margin-top:10px;">Notes</div><p><?= nl2br(htmlspecialchars($fp['notes'])) ?></p><?php endif; ?>
      <?php else: ?><p class="text-muted">No floor plan uploaded.</p><?php endif; ?>
    <?php renderSectionClose('floor_plan', $stagedComments, $id); ?>

    <?php renderSectionHeader('people', '👥 People & Manpower', $stagedComments); ?>
      <?php if (!empty($mp)): ?>
        <table style="width:100%;"><thead><tr><th>Role</th><th>Assigned Person</th><th>Type</th></tr></thead>
        <tbody><?php foreach ($mp as $m): ?>
          <tr><td><?= htmlspecialchars($m['role']) ?></td><td><?= htmlspecialchars($m['assigned_person']??'—') ?></td><td><span class="badge badge-secondary"><?= ucfirst($m['type']) ?></span></td></tr>
        <?php endforeach; ?></tbody></table>
      <?php else: ?><p class="text-muted">No manpower assigned.</p><?php endif; ?>
    <?php renderSectionClose('people', $stagedComments, $id); ?>

    <?php renderSectionHeader('schedule', '📅 Schedule', $stagedComments); ?>
      <?php if (!empty($scheds)): ?>
        <table style="width:100%;"><thead><tr><th>Date</th><th>Event</th><th>Venue</th><th>Organizer</th></tr></thead>
        <tbody><?php foreach ($scheds as $s): ?>
          <tr><td><?= $s['sched_date'] ? date('M j, Y', strtotime($s['sched_date'])) : '—' ?></td><td><?= htmlspecialchars($s['event_name']) ?></td><td><?= htmlspecialchars($s['venue']??'—') ?></td><td><?= htmlspecialchars($s['organizer']??'—') ?></td></tr>
        <?php endforeach; ?></tbody></table>
      <?php else: ?><p class="text-muted">No schedule entries.</p><?php endif; ?>
      <?php if (!empty($prog)): ?>
        <div class="sl" style="margin-top:18px;">Program Sequence</div>
        <table style="width:100%;"><thead><tr><th>Time</th><th>Segment</th><th>Description</th><th>Person In-Charge</th></tr></thead>
        <tbody><?php foreach ($prog as $p): ?>
          <tr><td><?= $p['time_slot'] ? date('g:i A', strtotime($p['time_slot'])) : '—' ?></td><td><strong><?= htmlspecialchars($p['segment']) ?></strong></td><td><?= htmlspecialchars($p['description']??'') ?></td><td><?= htmlspecialchars($p['person_ic']??'') ?></td></tr>
        <?php endforeach; ?></tbody></table>
      <?php endif; ?>
    <?php renderSectionClose('schedule', $stagedComments, $id); ?>

    <?php renderSectionHeader('guidelines', '📎 Guidelines', $stagedComments); ?>
      <?php if ($guide): ?>
        <div class="sl">Mechanics / Guidelines</div><p><?= nl2br(htmlspecialchars($guide['mechanics']??'—')) ?></p>
        <div class="sl" style="margin-top:12px;">Criteria for Judging</div><p><?= nl2br(htmlspecialchars($guide['criteria']??'—')) ?></p>
        <div class="sl" style="margin-top:12px;">Scoring System</div><p><?= nl2br(htmlspecialchars($guide['scoring_system']??'—')) ?></p>
        <div class="sl" style="margin-top:12px;">Special Awards</div><p><?= nl2br(htmlspecialchars($guide['special_awards']??'—')) ?></p>
      <?php else: ?><p class="text-muted">No guidelines entered.</p><?php endif; ?>
    <?php renderSectionClose('guidelines', $stagedComments, $id); ?>

    <?php renderSectionHeader('faculty_tasks', '📋 Faculty Tasks & Responsibilities', $stagedComments); ?>
      <?php if (!empty($ftasks)): ?>
        <table style="width:100%;"><thead><tr><th>Faculty Name</th><th>Assigned Task</th><th>Contribution</th><th>Role in Event</th></tr></thead>
        <tbody><?php foreach ($ftasks as $f): ?>
          <tr><td><?= htmlspecialchars($f['faculty_name']) ?></td><td><?= htmlspecialchars($f['assigned_task']??'') ?></td><td><?= htmlspecialchars($f['contribution_desc']??'') ?></td><td><?= htmlspecialchars($f['role_in_event']??'') ?></td></tr>
        <?php endforeach; ?></tbody></table>
      <?php else: ?><p class="text-muted">No faculty tasks assigned.</p><?php endif; ?>
    <?php renderSectionClose('faculty_tasks', $stagedComments, $id); ?>

    <?php renderSectionHeader('kpi', '📊 KPI & Evaluation', $stagedComments); ?>
      <?php if (!empty($kpis)): ?>
        <table style="width:100%;"><thead><tr><th>Indicator</th><th>Target Metric</th><th>Evaluation Method</th></tr></thead>
        <tbody><?php foreach ($kpis as $k): ?>
          <tr><td><?= htmlspecialchars($k['indicator']??'') ?></td><td><?= htmlspecialchars($k['target_metric']??'') ?></td><td><?= htmlspecialchars($k['evaluation_method']??'') ?></td></tr>
        <?php endforeach; ?></tbody></table>
      <?php else: ?><p class="text-muted">No KPI criteria defined.</p><?php endif; ?>
      <?php if (!empty($activity['evaluation_method'])): ?>
        <div class="sl" style="margin-top:14px;">Google Form Link</div>
        <a href="<?= htmlspecialchars($activity['evaluation_method']) ?>" target="_blank"><?= htmlspecialchars($activity['evaluation_method']) ?></a>
      <?php endif; ?>

      <?php if (!empty($activity['evaluation_questions'])): ?>
        <div style="margin-top:20px; border-top: 1px dashed var(--border); padding-top: 15px;">
          <div class="sl" style="font-weight:700; margin-bottom:10px;">📋 AI-Generated Evaluation Tool</div>
          <?= renderAiEvaluationQuestions($activity['evaluation_questions']) ?>
        </div>
      <?php endif; ?>
    <?php renderSectionClose('kpi', $stagedComments, $id); ?>

    <?php if (!empty($history)): ?>
    <div class="review-section">
      <div class="review-section-header"><h2>🕓 Approval History</h2></div>
      <div class="review-section-body" style="padding:0;">
        <table><thead><tr><th>Reviewer</th><th>Action</th><th>Notes</th><th>Date</th></tr></thead>
        <tbody><?php foreach ($history as $h): ?>
          <tr><td><?= htmlspecialchars($h['name']) ?></td><td><?= getStatusBadge($h['action']) ?></td><td><?= htmlspecialchars($h['notes']??'—') ?></td><td><?= date('M j, Y g:i A', strtotime($h['acted_at'])) ?></td></tr>
        <?php endforeach; ?></tbody></table>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- content -->
</div><!-- main-wrap -->

<?php foreach ([
  'forward' => ['Approve Activity',     'success', 'approved', false],
  'return'  => ['Return for Revision',  'warning', 'returned', false],
  'reject'  => ['Reject Proposal',      'danger',  'rejected', true],
] as $key => [$label, $cls, $action, $required]): ?>
<div class="modal-overlay" id="modal-<?= $key ?>">
  <div class="modal">
    <h3 style="font-family:'Syne',sans-serif;margin-bottom:8px;"><?= $label ?></h3>
      <?php if ($key === 'return'): ?>
      <?php if ($stagedCount > 0): ?>
      <div class="alert alert-warning" style="margin-bottom:12px;font-size:.82rem;">
        <strong><?= $stagedCount ?> section<?= $stagedCount > 1 ? 's' : '' ?> flagged</strong> — these section-specific comments will be sent to the faculty.
      </div>
      <?php else: ?>
      <div class="alert alert-danger" style="margin-bottom:12px;font-size:.82rem;">
        You cannot return this proposal without adding a comment to the section you want the faculty to revise.
      </div>
      <?php endif; ?>
    <?php endif; ?>
    <p class="text-sm text-muted" style="margin-bottom:16px;">Activity: <strong><?= htmlspecialchars($activity['title']) ?></strong></p>
    <form method="POST" action="<?= BASE_URL ?>/api/review-action.php">
      <input type="hidden" name="activity_id" value="<?= $id ?>">
      <input type="hidden" name="reviewer_id" value="<?= $user['id'] ?>">
      <input type="hidden" name="action" value="<?= $action ?>">
      <input type="hidden" name="role" value="ian">
      <?php if ($action === 'rejected'): ?>
      <div class="form-group">
        <label class="form-label">Notes / Comments <?= $required ? '*' : '' ?></label>
        <textarea name="notes" class="form-control" <?= $required ? 'required' : '' ?> rows="4" placeholder="Add your review notes..."></textarea>
      </div>
      <?php elseif ($action === 'returned'): ?>
        <p class="text-sm" style="margin-bottom:16px;">Are you sure you want to return this proposal for revision?</p>
      <?php else: ?>
        <p class="text-sm" style="margin-bottom:16px;">Are you sure you want to approve this proposal? No comment is required.</p>
      <?php endif; ?>
      <div class="flex gap-2" style="justify-content:flex-end;">
        <button type="button" class="btn btn-outline" onclick="closeModal('<?= $key ?>')">Cancel</button>
        <?php if ($key === 'return' && $stagedCount === 0): ?>
        <button type="button" class="btn btn-<?= $cls ?>" disabled style="opacity: 0.5; cursor: not-allowed;"><?= $label ?></button>
        <?php else: ?>
        <button type="submit" class="btn btn-<?= $cls ?>"><?= $label ?></button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>

<script>
const BASE_URL = '<?= BASE_URL ?>';
const ACTIVITY_ID = <?= $id ?>;

function openModal(k) { document.getElementById('modal-'+k).classList.add('open'); }
function closeModal(k) { document.getElementById('modal-'+k).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(m => m.addEventListener('click', e => { if(e.target===m) m.classList.remove('open'); }));

document.querySelectorAll('.btn-request-revision').forEach(btn => {
  btn.addEventListener('click', () => {
    const key = btn.dataset.section;
    const box = document.getElementById('cbox-' + key);
    box.classList.toggle('open');
    if (box.classList.contains('open')) box.querySelector('textarea').focus();
  });
});

document.querySelectorAll('.btn-save-comment').forEach(btn => {
  btn.addEventListener('click', () => {
    const key     = btn.dataset.section;
    const textarea= document.getElementById('ctxt-' + key);
    const comment = textarea.value.trim();
    if (!comment) { textarea.focus(); return; }
    fetch(BASE_URL + '/api/section-comment.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ action:'save', activity_id:ACTIVITY_ID, section_key:key, comment })
    }).then(r=>r.json()).then(d => {
      if (!d.success) return;
      markSectionFlagged(key, comment);
      updateFlaggedBadge();
    });
  });
});

document.querySelectorAll('.btn-remove-comment').forEach(btn => {
  btn.addEventListener('click', () => {
    const key = btn.dataset.section;
    if (!confirm('Remove this section comment?')) return;
    fetch(BASE_URL + '/api/section-comment.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ action:'delete', activity_id:ACTIVITY_ID, section_key:key })
    }).then(r=>r.json()).then(d => {
      if (!d.success) return;
      clearSectionFlagged(key);
      updateFlaggedBadge();
    });
  });
});

function markSectionFlagged(key, comment) {
  const card   = document.getElementById('section-card-' + key);
  const revBtn = document.getElementById('revbtn-' + key);
  const banner = document.getElementById('flagged-banner-' + key);
  const bannerText = document.getElementById('flagged-text-' + key);
  card.classList.add('flagged');
  revBtn.classList.add('has-comment');
  revBtn.innerHTML = '✏️ Comment Added';
  if (banner) { banner.style.display='flex'; if(bannerText) bannerText.textContent=comment; }
  document.getElementById('cbox-' + key)?.classList.remove('open');
}

function clearSectionFlagged(key) {
  const card     = document.getElementById('section-card-' + key);
  const revBtn   = document.getElementById('revbtn-' + key);
  const banner   = document.getElementById('flagged-banner-' + key);
  const textarea = document.getElementById('ctxt-' + key);
  card.classList.remove('flagged');
  revBtn.classList.remove('has-comment');
  revBtn.innerHTML = '📝 Request Revision';
  if (banner) banner.style.display='none';
  if (textarea) textarea.value='';
}

function updateFlaggedBadge() {
  const flagged = document.querySelectorAll('.review-section.flagged').length;
  let badge = document.querySelector('.flagged-badge');
  const metaDiv = document.querySelector('.proposal-meta .text-sm');
  if (flagged > 0) {
    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'flagged-badge';
      metaDiv.appendChild(document.createTextNode('\u00a0'));
      metaDiv.appendChild(badge);
    }
    badge.textContent = flagged + ' section' + (flagged>1?'s':'') + ' flagged';
  } else if (badge) {
    badge.previousSibling?.nodeType === 3 && badge.previousSibling.remove();
    badge.remove();
  }

  // Toggle return modal state
  const returnModal = document.getElementById('modal-return');
  if (returnModal) {
    const alertBox = returnModal.querySelector('.alert');
    const returnBtn = returnModal.querySelector('form .flex button:last-child');
    if (flagged > 0) {
      if (alertBox) {
        alertBox.className = 'alert alert-warning';
        alertBox.style.marginBottom = '12px';
        alertBox.style.fontSize = '.82rem';
        alertBox.innerHTML = `<strong>${flagged} section${flagged>1?'s':''} flagged</strong> — these section-specific comments will be sent to the faculty.`;
      }
      if (returnBtn) {
        returnBtn.disabled = false;
        returnBtn.style.opacity = '1';
        returnBtn.style.cursor = 'pointer';
        returnBtn.type = 'submit';
      }
    } else {
      if (alertBox) {
        alertBox.className = 'alert alert-danger';
        alertBox.style.marginBottom = '12px';
        alertBox.style.fontSize = '.82rem';
        alertBox.innerHTML = 'You cannot return this proposal without adding a comment to the section you want the faculty to revise.';
      }
      if (returnBtn) {
        returnBtn.disabled = true;
        returnBtn.style.opacity = '0.5';
        returnBtn.style.cursor = 'not-allowed';
        returnBtn.type = 'button';
      }
    }
  }
}
</script>
</body>
</html>

<?php
function renderSectionHeader(string $key, string $title, array $staged): void {
    $hasCmt = isset($staged[$key]);
    echo "<div class=\"review-section" . ($hasCmt ? ' flagged' : '') . "\" id=\"section-card-{$key}\">";
    echo "<div class=\"review-section-header\">";
    echo "<h2>{$title}</h2>";
    $btnCls   = $hasCmt ? ' has-comment' : '';
    $btnLabel = $hasCmt ? '✏️ Comment Added' : '📝 Request Revision';
    echo "<button class=\"btn-request-revision{$btnCls}\" id=\"revbtn-{$key}\" data-section=\"{$key}\">{$btnLabel}</button>";
    echo "</div>";
    // Existing staged banner
    $bannerStyle = $hasCmt ? 'display:flex;' : 'display:none;';
    $cmt = $hasCmt ? htmlspecialchars($staged[$key]) : '';
    echo "<div class=\"section-flagged-banner\" id=\"flagged-banner-{$key}\" style=\"{$bannerStyle}\">";
    echo "⚠️ <span id=\"flagged-text-{$key}\">{$cmt}</span>";
    echo "&nbsp;<button class=\"btn-remove-comment\" data-section=\"{$key}\" style=\"margin-left:auto;background:none;border:none;cursor:pointer;color:#92400E;font-size:.75rem;font-weight:700;\">✕ Remove</button>";
    echo "</div>";
    echo "<div class=\"review-section-body\">";
}

function renderSectionClose(string $key, array $staged, int $actId): void {
    $existing = htmlspecialchars($staged[$key] ?? '', ENT_QUOTES);
    echo "</div>"; // close review-section-body
    echo "<div class=\"section-comment-box\" id=\"cbox-{$key}\">";
    echo "<textarea id=\"ctxt-{$key}\" placeholder=\"Describe what needs to be revised in this section...\">{$existing}</textarea>";
    echo "<div style=\"display:flex;flex-direction:column;gap:6px;\">";
    echo "<button class=\"btn btn-sm btn-warning btn-save-comment\" data-section=\"{$key}\">💾 Save Comment</button>";
    echo "</div>";
    echo "</div>";
    echo "</div>"; // close review-section
}
?>
