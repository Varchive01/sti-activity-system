<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/proposal_validator.php';
requireRole('admin1');
$user = currentUser();
$db   = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: '.BASE_URL.'/admin1/dashboard.php'); exit; }

$act = $db->prepare("SELECT a.*,u.name as faculty_name,u.email as faculty_email FROM activities a JOIN users u ON a.faculty_id=u.id WHERE a.id=? AND source='student_org'");
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

// Load any staged section comments for this reviewer
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
  position: sticky; top: 0; z-index: 50;
  background: #fff; border-bottom: 2px solid var(--border);
  padding: 14px 24px;
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap;
  box-shadow: 0 2px 8px rgba(0,0,0,.08);
}
.review-action-bar .proposal-meta { flex: 1; min-width: 0; }
.review-action-bar .proposal-title { font-family:'Syne',sans-serif; font-size:1.05rem; font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* ── Section Card with Request Revision ── */
.review-section { margin-bottom: 20px; border-radius: var(--radius); border: 1px solid var(--border); background: #fff; overflow: hidden; }
.review-section.flagged { border-color: #F59E0B; box-shadow: 0 0 0 2px rgba(245,158,11,.18); }
.review-section-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 20px; background: var(--bg-base); border-bottom: 1px solid var(--border);
  gap: 10px;
}
.review-section-header h2 { font-size: .92rem; font-weight: 700; margin: 0; flex: 1; }
.btn-request-revision {
  background: none; border: 1.5px solid #D1D5DB; border-radius: 7px;
  padding: 5px 12px; font-size: .72rem; font-weight: 700; cursor: pointer;
  color: #6B7280; white-space: nowrap;
  display: flex; align-items: center; gap: 5px;
  transition: background .15s, border-color .15s, color .15s;
}
.btn-request-revision:hover { background: #FEF9C3; border-color: #F59E0B; color: #92400E; }
.btn-request-revision.has-comment { background: #FEF3C7; border-color: #F59E0B; color: #92400E; }
.review-section-body { padding: 18px 20px; }

/* ── Inline Comment Box ── */
.section-comment-box {
  display: none; background: #FFFBEB; border-top: 1px solid #FDE68A;
  padding: 14px 20px; gap: 10px;
}
.section-comment-box.open { display: flex; align-items: flex-start; gap: 10px; flex-wrap: wrap; }
.section-comment-box textarea {
  flex: 1; min-width: 200px; min-height: 70px; border: 1px solid #FCD34D;
  border-radius: 6px; padding: 8px 10px; font-size: .82rem; resize: vertical;
  background: #fff;
}
.section-comment-box textarea:focus { outline: none; border-color: #F59E0B; box-shadow: 0 0 0 2px rgba(245,158,11,.2); }
.comment-saved-notice { font-size: .7rem; color: #78350F; font-weight: 600; display: flex; align-items: center; gap: 4px; }
.section-flagged-banner {
  background: #FEF3C7; border-top: 1px solid #FDE68A;
  padding: 8px 20px; font-size: .78rem; color: #78350F;
  display: flex; align-items: flex-start; gap: 6px;
}

/* ── Info Grid ── */
.info-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; }
.info-block { background: var(--bg-base); border-radius: 8px; padding: 12px 14px; }
.info-block .lbl { font-size: .68rem; text-transform: uppercase; letter-spacing: .4px; color: var(--text-muted); font-weight: 700; }
.info-block .val { font-size: .88rem; font-weight: 600; margin-top: 3px; }

/* ── Flagged count badge ── */
.flagged-badge {
  background: #F59E0B; color: #fff;
  font-size: .68rem; font-weight: 800;
  padding: 2px 7px; border-radius: 99px;
}

/* ── Action Modals ── */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:200; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal { background:#fff; border-radius:var(--radius); padding:28px; max-width:500px; width:100%; box-shadow:var(--shadow-lg); }

/* ── Section label ── */
.sl { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); margin-bottom:6px; }

/* ── Budget total row ── */
.budget-total { display:flex; justify-content:flex-end; padding:10px 14px; background:var(--bg-base); font-weight:700; font-size:.9rem; border-top:1px solid var(--border); }
</style>
</head>
<body class="theme-arjay">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">

  <!-- Sticky Action Bar -->
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
      <a href="<?= BASE_URL ?>/admin1/dashboard.php" class="btn btn-outline btn-sm">← Back</a>
      <button class="btn btn-outline" onclick="openModal('return')">↩ Return for Revision</button>
      <button class="btn btn-danger"  onclick="openModal('reject')">✕ Reject</button>
      <button class="btn btn-success" onclick="openModal('forward')">✓ Approve &amp; Forward to Sir Ian</button>
    </div>
  </div>

  <div class="content">

    <!-- ── SECTION 1: Event Details ── -->
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

    <!-- ── SECTION 2: Objectives ── -->
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

    <!-- ── SECTION 3: Materials & Budget ── -->
    <?php renderSectionHeader('materials', '💰 Materials & Budget', $stagedComments); ?>
      <?php if (!empty($mats)): ?>
        <table style="width:100%;">
          <thead><tr><th>Item</th><th>Description</th><th>Qty</th><th>Provider</th><th>Est. Cost</th></tr></thead>
          <tbody>
            <?php foreach ($mats as $m): ?>
            <tr>
              <td><?= htmlspecialchars($m['item_name']) ?></td>
              <td><?= htmlspecialchars($m['description'] ?? '') ?></td>
              <td><?= $m['quantity'] ?></td>
              <td><?= htmlspecialchars($m['provider'] ?? '') ?></td>
              <td>₱<?= number_format($m['est_cost'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="budget-total">Grand Total: ₱<?= number_format($totalBudget, 2) ?></div>
      <?php else: ?><p class="text-muted">No materials listed.</p><?php endif; ?>
    <?php renderSectionClose('materials', $stagedComments, $id); ?>

    <!-- ── SECTION 4: Floor Plan ── -->
    <?php renderSectionHeader('floor_plan', '🗺️ Floor Plan', $stagedComments); ?>
      <?php if ($fp): ?>
        <div class="sl">Floor Plan File</div>
        <?php $ext = strtolower(pathinfo($fp['file_path'], PATHINFO_EXTENSION)); $isImg = in_array($ext,['png','jpg','jpeg','gif','webp']); ?>
        <?php $fpUrl = str_starts_with($fp['file_path'], 'http') ? $fp['file_path'] : BASE_URL . '/' . $fp['file_path']; ?>
        <?php if($isImg): ?>
        <img src="<?= htmlspecialchars($fpUrl) ?>" style="max-width:100%;border-radius:8px;margin-bottom:12px;" alt="Floor Plan">
        <?php else: ?>
        <a href="<?= htmlspecialchars($fpUrl) ?>" target="_blank" class="btn btn-outline btn-sm" style="margin-bottom:12px;">📎 View Floor Plan File</a>
        <?php endif; ?>
        <?php if ($fp['notes']): ?>
          <div class="sl" style="margin-top:10px;">Notes</div>
          <p><?= nl2br(htmlspecialchars($fp['notes'])) ?></p>
        <?php endif; ?>
        <?php if (!empty($activity['floor_plan_json'])): ?>
          <div class="sl" style="margin-top:10px;">Interactive Layout Data</div>
          <p class="text-muted text-sm">Canvas layout saved (<?= strlen($activity['floor_plan_json']) ?> bytes)</p>
        <?php endif; ?>
      <?php else: ?><p class="text-muted">No floor plan uploaded.</p><?php endif; ?>
    <?php renderSectionClose('floor_plan', $stagedComments, $id); ?>

    <!-- ── SECTION 5: People & Manpower ── -->
    <?php renderSectionHeader('people', '👥 People & Manpower', $stagedComments); ?>
      <?php if (!empty($mp)): ?>
        <table style="width:100%;">
          <thead><tr><th>Role</th><th>Assigned Person</th><th>Type</th></tr></thead>
          <tbody>
            <?php foreach ($mp as $m): ?>
            <tr>
              <td><?= htmlspecialchars($m['role']) ?></td>
              <td><?= htmlspecialchars($m['assigned_person'] ?? '—') ?></td>
              <td><span class="badge badge-secondary"><?= ucfirst($m['type']) ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?><p class="text-muted">No manpower assigned.</p><?php endif; ?>
    <?php renderSectionClose('people', $stagedComments, $id); ?>

    <!-- ── SECTION 6: Schedule ── -->
    <?php renderSectionHeader('schedule', '📅 Schedule', $stagedComments); ?>
      <?php if (!empty($scheds)): ?>
        <table style="width:100%;">
          <thead><tr><th>Date</th><th>Event</th><th>Venue</th><th>Organizer</th></tr></thead>
          <tbody>
            <?php foreach ($scheds as $s): ?>
            <tr>
              <td><?= $s['sched_date'] ? date('M j, Y', strtotime($s['sched_date'])) : '—' ?></td>
              <td><?= htmlspecialchars($s['event_name']) ?></td>
              <td><?= htmlspecialchars($s['venue'] ?? '—') ?></td>
              <td><?= htmlspecialchars($s['organizer'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?><p class="text-muted">No schedule entries.</p><?php endif; ?>

      <?php if (!empty($prog)): ?>
        <div class="sl" style="margin-top:18px;">Program Sequence</div>
        <table style="width:100%;">
          <thead><tr><th>Time</th><th>Segment</th><th>Description</th><th>Person In-Charge</th></tr></thead>
          <tbody>
            <?php foreach ($prog as $p): ?>
            <tr>
              <td><?= $p['time_slot'] ? date('g:i A', strtotime($p['time_slot'])) : '—' ?></td>
              <td><strong><?= htmlspecialchars($p['segment']) ?></strong></td>
              <td><?= htmlspecialchars($p['description'] ?? '') ?></td>
              <td><?= htmlspecialchars($p['person_ic'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    <?php renderSectionClose('schedule', $stagedComments, $id); ?>

    <!-- ── SECTION 7: Guidelines ── -->
    <?php renderSectionHeader('guidelines', '📎 Guidelines', $stagedComments); ?>
      <?php if ($guide): ?>
        <div class="sl">Mechanics / Guidelines</div><p><?= nl2br(htmlspecialchars($guide['mechanics'] ?? '—')) ?></p>
        <div class="sl" style="margin-top:12px;">Criteria for Judging</div><p><?= nl2br(htmlspecialchars($guide['criteria'] ?? '—')) ?></p>
        <div class="sl" style="margin-top:12px;">Scoring System</div><p><?= nl2br(htmlspecialchars($guide['scoring_system'] ?? '—')) ?></p>
        <div class="sl" style="margin-top:12px;">Special Awards</div><p><?= nl2br(htmlspecialchars($guide['special_awards'] ?? '—')) ?></p>
      <?php else: ?><p class="text-muted">No guidelines entered.</p><?php endif; ?>
    <?php renderSectionClose('guidelines', $stagedComments, $id); ?>

    <!-- ── SECTION 8: Faculty Tasks ── -->
    <?php renderSectionHeader('faculty_tasks', '📋 Faculty Tasks & Responsibilities', $stagedComments); ?>
      <?php if (!empty($ftasks)): ?>
        <table style="width:100%;">
          <thead><tr><th>Faculty Name</th><th>Assigned Task</th><th>Contribution</th><th>Role in Event</th></tr></thead>
          <tbody>
            <?php foreach ($ftasks as $f): ?>
            <tr>
              <td><?= htmlspecialchars($f['faculty_name']) ?></td>
              <td><?= htmlspecialchars($f['assigned_task'] ?? '') ?></td>
              <td><?= htmlspecialchars($f['contribution_desc'] ?? '') ?></td>
              <td><?= htmlspecialchars($f['role_in_event'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?><p class="text-muted">No faculty tasks assigned.</p><?php endif; ?>
    <?php renderSectionClose('faculty_tasks', $stagedComments, $id); ?>

    <!-- ── SECTION 9: KPI ── -->
    <?php renderSectionHeader('kpi', '📊 KPI & Evaluation', $stagedComments); ?>
      <?php if (!empty($kpis)): ?>
        <table style="width:100%;">
          <thead><tr><th>Indicator</th><th>Target Metric</th><th>Evaluation Method</th></tr></thead>
          <tbody>
            <?php foreach ($kpis as $k): ?>
            <tr>
              <td><?= htmlspecialchars($k['indicator'] ?? '') ?></td>
              <td><?= htmlspecialchars($k['target_metric'] ?? '') ?></td>
              <td><?= htmlspecialchars($k['evaluation_method'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
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

    <!-- ── Approval History ── -->
    <?php if (!empty($history)): ?>
    <div class="review-section">
      <div class="review-section-header"><h2>🕓 Approval History</h2></div>
      <div class="review-section-body" style="padding:0;">
        <table><thead><tr><th>Reviewer</th><th>Action</th><th>Notes</th><th>Date</th></tr></thead>
        <tbody><?php foreach ($history as $h): ?>
          <tr>
            <td><?= htmlspecialchars($h['name']) ?></td>
            <td><?= getStatusBadge($h['action']) ?></td>
            <td><?= htmlspecialchars($h['notes'] ?? '—') ?></td>
            <td><?= date('M j, Y g:i A', strtotime($h['acted_at'])) ?></td>
          </tr>
        <?php endforeach; ?></tbody></table>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- content -->
</div><!-- main-wrap -->

<!-- Action Modals -->
<?php foreach ([
  'forward' => ['Approve & Forward to Sir Ian', 'success', 'approved', false],
  'return'  => ['Return for Revision',          'warning', 'returned', false],
  'reject'  => ['Reject Proposal',              'danger',  'rejected', true],
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
      <input type="hidden" name="role" value="arjay">
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

// ── Modal helpers ──
function openModal(k) { document.getElementById('modal-'+k).classList.add('open'); }
function closeModal(k) { document.getElementById('modal-'+k).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(m => m.addEventListener('click', e => { if (e.target===m) m.classList.remove('open'); }));

// ── Section comment toggling ──
document.querySelectorAll('.btn-request-revision').forEach(btn => {
  btn.addEventListener('click', () => {
    const key = btn.dataset.section;
    const box = document.getElementById('cbox-' + key);
    box.classList.toggle('open');
    if (box.classList.contains('open')) {
      box.querySelector('textarea').focus();
    }
  });
});

// ── Save comment ──
document.querySelectorAll('.btn-save-comment').forEach(btn => {
  btn.addEventListener('click', () => {
    const key      = btn.dataset.section;
    const textarea = document.getElementById('ctxt-' + key);
    const comment  = textarea.value.trim();
    if (!comment) { textarea.focus(); return; }

    fetch(BASE_URL + '/api/section-comment.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'save', activity_id: ACTIVITY_ID, section_key: key, comment })
    })
    .then(r => r.json())
    .then(d => {
      if (!d.success) return;
      markSectionFlagged(key, comment);
      // Update flagged count badge
      updateFlaggedBadge();
    });
  });
});

// ── Remove comment ──
document.querySelectorAll('.btn-remove-comment').forEach(btn => {
  btn.addEventListener('click', () => {
    const key = btn.dataset.section;
    if (!confirm('Remove this section comment?')) return;
    fetch(BASE_URL + '/api/section-comment.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'delete', activity_id: ACTIVITY_ID, section_key: key })
    })
    .then(r => r.json())
    .then(d => {
      if (!d.success) return;
      clearSectionFlagged(key);
      updateFlaggedBadge();
    });
  });
});

function markSectionFlagged(key, comment) {
  const card    = document.getElementById('section-card-' + key);
  const revBtn  = document.getElementById('revbtn-' + key);
  const banner  = document.getElementById('flagged-banner-' + key);
  const bannerText = document.getElementById('flagged-text-' + key);
  card.classList.add('flagged');
  revBtn.classList.add('has-comment');
  revBtn.innerHTML = '✏️ Comment Added';
  if (banner) {
    banner.style.display = 'flex';
    if (bannerText) bannerText.textContent = comment;
  }
  // Close the box
  document.getElementById('cbox-' + key)?.classList.remove('open');
}

function clearSectionFlagged(key) {
  const card    = document.getElementById('section-card-' + key);
  const revBtn  = document.getElementById('revbtn-' + key);
  const banner  = document.getElementById('flagged-banner-' + key);
  const textarea = document.getElementById('ctxt-' + key);
  card.classList.remove('flagged');
  revBtn.classList.remove('has-comment');
  revBtn.innerHTML = '📝 Request Revision';
  if (banner) banner.style.display = 'none';
  if (textarea) textarea.value = '';
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
// ── Helpers ──────────────────────────────────────────────────────────────────
function renderSectionHeader(string $key, string $title, array $staged): void {
    $hasCmt = isset($staged[$key]);
    $cardCls = $hasCmt ? ' flagged' : '';
    echo "<div class=\"review-section{$cardCls}\" id=\"section-card-{$key}\">";
    echo "<div class=\"review-section-header\">";
    echo "<h2>{$title}</h2>";
    $btnCls = $hasCmt ? ' has-comment' : '';
    $btnLabel = $hasCmt ? '✏️ Comment Added' : '📝 Request Revision';
    echo "<button class=\"btn-request-revision{$btnCls}\" id=\"revbtn-{$key}\" data-section=\"{$key}\">{$btnLabel}</button>";
    echo "</div>";
    // Pre-existing staged comment banner
    if ($hasCmt) {
        $cmt = htmlspecialchars($staged[$key]);
        echo "<div class=\"section-flagged-banner\" id=\"flagged-banner-{$key}\" style=\"display:flex;\">";
        echo "⚠️ <span id=\"flagged-text-{$key}\">{$cmt}</span>";
        echo "&nbsp;<button class=\"btn-remove-comment\" data-section=\"{$key}\" style=\"margin-left:auto;background:none;border:none;cursor:pointer;color:#92400E;font-size:.75rem;font-weight:700;\">✕ Remove</button>";
        echo "</div>";
    } else {
        echo "<div class=\"section-flagged-banner\" id=\"flagged-banner-{$key}\" style=\"display:none;\">";
        echo "⚠️ <span id=\"flagged-text-{$key}\"></span>";
        echo "&nbsp;<button class=\"btn-remove-comment\" data-section=\"{$key}\" style=\"margin-left:auto;background:none;border:none;cursor:pointer;color:#92400E;font-size:.75rem;font-weight:700;\">✕ Remove</button>";
        echo "</div>";
    }
    echo "<div class=\"review-section-body\">";
}

function renderSectionClose(string $key, array $staged, int $actId): void {
    $existing = htmlspecialchars($staged[$key] ?? '', ENT_QUOTES);
    echo "</div>"; // close review-section-body
    // Inline comment box
    echo "<div class=\"section-comment-box\" id=\"cbox-{$key}\">";
    echo "<textarea id=\"ctxt-{$key}\" placeholder=\"Describe what needs to be revised in this section...\" style=\"flex:1;\">{$existing}</textarea>";
    echo "<div style=\"display:flex;flex-direction:column;gap:6px;\">";
    echo "<button class=\"btn btn-sm btn-warning btn-save-comment\" data-section=\"{$key}\">💾 Save Comment</button>";
    echo "</div>";
    echo "</div>";
    echo "</div>"; // close review-section
}
?>
