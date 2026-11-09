<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';
requireRole('dean');
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: '.BASE_URL.'/dean/dashboard.php'); exit; }

$act  = $db->prepare("SELECT a.*,u.name as fn,u.email as fe FROM activities a JOIN users u ON a.faculty_id=u.id WHERE a.id=?");
$act->execute([$id]); $activity=$act->fetch();
if (!$activity) die('Not found.');

$user = currentUser();
if (!canUserAccessActivity($id, 'view', $user['id'], $user['role'], $user['dept'] ?? '')) {
    header('Location: ' . BASE_URL . '/dean/dashboard.php?error=' . urlencode('This proposal is currently in committee review and has not yet reached the Dean approval stage.'));
    exit;
}

$mats = $db->prepare("SELECT * FROM materials WHERE activity_id=?"); $mats->execute([$id]); $materials=$mats->fetchAll();
$psStmt = $db->prepare("SELECT * FROM activity_participants WHERE activity_id=? ORDER BY row_no ASC, id ASC"); $psStmt->execute([$id]); $partSections=$psStmt->fetchAll();

if (function_exists('reconcilePostEventMetrics')) {
    reconcilePostEventMetrics($db, $id);
}

// Fetch raw evaluation responses
$responsesStmt = $db->prepare("
    SELECT id, criteria, rating, comments, evaluator_name, evaluated_at 
    FROM kpi_evaluations 
    WHERE activity_id = ? AND (rating IS NOT NULL OR (comments IS NOT NULL AND TRIM(comments) != ''))
    ORDER BY evaluated_at DESC
");
$responsesStmt->execute([$id]);
$responsesList = $responsesStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics programmatically in PHP
$totalCount = count($responsesList);
$ratings = array_filter(array_column($responsesList, 'rating'), fn($v) => $v !== null);
$avgRating = count($ratings) > 0 ? round(array_sum($ratings) / count($ratings), 2) : 0;
$commentsCount = count(array_filter(array_column($responsesList, 'comments'), fn($v) => !empty(trim($v))));

// Calculate question ratings averages
$questionAverages = [];
$grouped = [];
foreach ($responsesList as $r) {
    if ($r['rating'] !== null) {
        $grouped[$r['criteria']][] = (int)$r['rating'];
    }
}
foreach ($grouped as $qText => $vals) {
    $questionAverages[] = [
        'question' => $qText,
        'average' => round(array_sum($vals) / count($vals), 2),
        'count' => count($vals)
    ];
}
usort($questionAverages, fn($a, $b) => $b['average'] <=> $a['average']);

$prog = $db->prepare("SELECT * FROM program_sequence WHERE activity_id=? ORDER BY sort_order"); $prog->execute([$id]); $program=$prog->fetchAll();
$mp   = $db->prepare("SELECT * FROM manpower WHERE activity_id=?"); $mp->execute([$id]); $manpower=$mp->fetchAll();
$ft   = $db->prepare("SELECT ft.*, u.name as assigned_user_name FROM faculty_tasks ft LEFT JOIN users u ON ft.assigned_user_id = u.id WHERE ft.activity_id=? ORDER BY ft.id ASC"); $ft->execute([$id]); $ftasks=$ft->fetchAll();
$delivStmt = $db->prepare("SELECT td.*, u.name as uploader_name FROM task_deliverables td LEFT JOIN users u ON td.uploaded_by = u.id WHERE td.activity_id = ? ORDER BY td.uploaded_at ASC");
$delivStmt->execute([$id]);
$allDelivs = $delivStmt->fetchAll(PDO::FETCH_ASSOC);
$taskDeliverables = [];
foreach ($allDelivs as $dl) {
    $taskDeliverables[(int)$dl['task_id']][] = $dl;
}
$gl   = $db->prepare("SELECT * FROM guidelines WHERE activity_id=?"); $gl->execute([$id]); $guidelines=$gl->fetch();
$pe   = $db->prepare("SELECT * FROM post_event WHERE activity_id=?"); $pe->execute([$id]); $postEvent=$pe->fetch();
$docs = $db->prepare("SELECT * FROM documents WHERE activity_id=? ORDER BY uploaded_at DESC"); $docs->execute([$id]); $documents=$docs->fetchAll();
$kpis = $db->prepare("SELECT * FROM kpi_evaluations WHERE activity_id=?"); $kpis->execute([$id]); $kpiData=$kpis->fetchAll();
$rca  = $db->prepare("SELECT * FROM root_cause_analysis WHERE activity_id=?"); $rca->execute([$id]); $rcas=$rca->fetchAll();
$logs = $db->prepare("
    SELECT al.*, u.name, u.role as reviewer_role 
    FROM approval_logs al 
    JOIN users u ON al.reviewer_id = u.id 
    WHERE al.activity_id = ? 
    ORDER BY al.acted_at ASC, al.id ASC
");
$logs->execute([$id]);
$history = $logs->fetchAll();

// Post-Activity Compliance calculation
$complianceResult = calculatePostActivityCompliance($id, null, $db);
$complianceStatusBadgeClass = match($complianceResult['overall_status'] ?? '') {
    'Compliant' => 'badge-success',
    'Partially Compliant' => 'badge-warning',
    'Not Compliant', 'Non-Compliant' => 'badge-danger',
    default => 'badge-secondary'
};
?>

<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/head-theme-init.php'; ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>View Activity – STI Activity System</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=1.0.7">
<script>
  (function() {
    try {
      var savedTheme = localStorage.getItem('sti-theme');
      if (savedTheme === 'dark') {
        document.documentElement.dataset.theme = 'dark';
      } else {
        document.documentElement.dataset.theme = 'light';
      }
    } catch (e) {}
  })();
</script>
<style>
/* ── Typography Consistency: All Elements Use Plus Jakarta Sans ── */
body, body *, h1, h2, h3, h4, h5, h6, .page-title, .card-header h2, .card-header h3, .btn, .badge, .topbar-user-name, .topbar-user-role, .info-tile, .detail-table, #responsesTable, .tab-link, label, input, button, select, textarea {
  font-family: 'Plus Jakarta Sans', sans-serif !important;
}
.timeline-line{width:1px;background:var(--border);margin:14px 4px 0;flex:0 0 1px;}
.kpi-star{color:var(--sti-gold);}
</style>
</head>
<body class="theme-dean">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">Activity Detail</div>
    <div class="topbar-right">
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <a href="<?= BASE_URL ?>/dean/activities.php" class="btn btn-outline btn-sm">← Back</a>
      <?php if ($activity['status'] === 'pending_final_approval'): ?>
        <a href="<?= BASE_URL ?>/dean/review.php?id=<?= $activity['id'] ?>" class="btn btn-primary btn-sm">Review Proposal</a>
      <?php endif; ?>
      <button type="button" id="exportPdfBtn" class="btn btn-outline btn-sm" onclick="exportProposalPdf()" title="Download formal PDF document" style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;">📄 Export PDF</button>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>
  <div class="content">

    <!-- Tabs Navigation -->
    <?php
    $showTabs = ($activity['status'] === 'completed' || $postEvent);
    $activeTab = $_GET['tab'] ?? 'overview';
    if (!in_array($activeTab, ['overview', 'responses'])) {
        $activeTab = 'overview';
    }
    if ($showTabs):
    ?>
    <div class="tabs-nav no-print">
      <a href="?id=<?= $id ?>&tab=overview" class="tab-link <?= $activeTab === 'overview' ? 'active' : '' ?>">Overview</a>
      <a href="?id=<?= $id ?>&tab=responses" class="tab-link <?= $activeTab === 'responses' ? 'active' : '' ?>">Evaluation Responses</a>
      </div>
    <style>
      .tab-link:hover {
        color: var(--text-main);
      }
      @media print {
        .no-print {
          display: none !important;
        }
        .main-wrap {
          margin-left: 0 !important;
        }
        .sidebar, .topbar, .tabs-nav {
          display: none !important;
        }
      }
    </style>
    <?php endif; ?>

    <?php if ($activeTab === 'overview'): ?>

    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;flex-wrap:wrap;gap:12px;">

      <div>
        <h1 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:1.4rem;font-weight:800;color:var(--text-main);"><?= htmlspecialchars($activity['title']) ?></h1>
        <div class="text-sm text-muted" style="margin-top:4px;">Submitted by <?= htmlspecialchars($activity['fn']) ?> · <?= $activity['submitted_at'] ? date('F j, Y', strtotime($activity['submitted_at'])) : 'Not yet submitted' ?></div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;">
        <?= getStatusBadge($activity['status']) ?>
        <span class="badge badge-secondary"><?= ucfirst(str_replace('_',' ',$activity['source'])) ?></span>
      </div>
    </div>

    <?php if (in_array($activity['status'], ['returned_for_revision', 'rejected'], true)): 
      $lastDecisionLog = null;
      if (!empty($history)) {
        foreach (array_reverse($history) as $hLog) {
          if (in_array($hLog['action'], ['returned', 'rejected', 'returned_for_revision'], true)) {
            $lastDecisionLog = $hLog;
            break;
          }
        }
      }
      $decisionNotes = $lastDecisionLog['notes'] ?? $activity['revision_notes'] ?? '';
      $decisionBy = $lastDecisionLog ? htmlspecialchars($lastDecisionLog['name']) . ' (' . htmlspecialchars(ucwords(str_replace('_', ' ', $lastDecisionLog['reviewer_role'] ?? ''))) . ')' : 'Previous Approver';
      $isRej = ($activity['status'] === 'rejected');
    ?>
    <div style="margin-bottom:20px;padding:14px 18px;border-radius:10px;background:<?= $isRej ? '#FEF2F2' : '#FFFBEB' ?>;border:1px solid <?= $isRej ? '#FCA5A5' : '#FCD34D' ?>;color:<?= $isRej ? '#991B1B' : '#92400E' ?>;">
      <div style="display:flex;align-items:center;gap:8px;font-weight:700;font-size:0.95rem;margin-bottom:6px;">
        <span><?= $isRej ? '⛔ Proposal Rejected' : '⚠️ Proposal Returned for Revision' ?></span>
        <span style="font-size:0.8rem;font-weight:500;opacity:0.9;">— Decision by <?= $decisionBy ?></span>
      </div>
      <?php if (!empty($decisionNotes)): ?>
        <div style="font-size:0.86rem;line-height:1.5;background:rgba(255,255,255,0.7);padding:10px 12px;border-radius:6px;border:1px solid rgba(0,0,0,0.06);margin-top:6px;">
          <strong>Reason / Review Remarks:</strong><br>
          <?= nl2br(htmlspecialchars($decisionNotes)) ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Event Info -->
    <?php
      $totalBudgetView = !empty($materials) ? array_sum(array_map(fn($m)=>$m['est_cost']*$m['quantity'],$materials)) : 0;
      $clrInfoView = getActivityClearanceInfo($activity, $totalBudgetView);
    ?>
    <div class="section-title">📄 Event Information</div>
    <div class="info-grid">
      <?php foreach([
        'Date'=>$activity['event_date']?date('F j, Y',strtotime($activity['event_date'])):'—',
        'Time'=>($activity['start_time']??'—').' – '.($activity['end_time']??'—'),
        'Venue'=>$activity['venue']??'—',
        'Theme'=>$activity['theme']??'—',
        'Target Participants'=>number_format($activity['target_participants']),
        'Address'=>$activity['venue_address']??'—',
        'Clearance'=>$clrInfoView['status'] === 'Not Required' ? 'Not Required' : $clrInfoView['label'],
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
    <?php if (!empty($activity['involved_subjects'])): ?>
    <div style="margin-bottom:18px;">
      <div class="text-sm fw-bold" style="margin-bottom:4px;">Involved Subjects</div>
      <div class="text-sm"><?= htmlspecialchars($activity['involved_subjects']) ?></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($activity['rationale'])): ?>
    <div style="margin-bottom:24px;">
      <div class="text-sm fw-bold" style="margin-bottom:4px;">Rationale</div>
      <div class="text-sm"><?= nl2br(htmlspecialchars($activity['rationale'])) ?></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($activity['poster_path'])): ?>
    <?php $posterUrl = str_starts_with($activity['poster_path'], 'http') ? $activity['poster_path'] : BASE_URL . '/api/document-download.php?activity_id=' . (int)$activity['id'] . '&type=poster'; ?>
    <div class="section-title">🖼️ Event Poster</div>
    <div style="margin-bottom:24px; text-align:center;">
      <a href="<?= htmlspecialchars($posterUrl) ?>" target="_blank">
        <img src="<?= htmlspecialchars($posterUrl) ?>" alt="Event Poster" style="max-width: 100%; max-height: 500px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
      </a>
    </div>
    <?php endif; ?>

    <!-- Target Participants Breakdown -->
    <?php if (!empty($partSections)): ?>
    <div class="section-title">👥 Target Participants & Subject Teachers</div>
    <div class="table-wrap" style="margin-bottom:24px;">
      <table>
        <thead>
          <tr>
            <th style="width: 50px; text-align: center;">No.</th>
            <th>Involved Subject / Section</th>
            <th style="width: 170px; text-align: center;">Target Participants</th>
            <th>Subject Teacher(s)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($partSections as $pIdx => $ps): ?>
            <tr>
              <td style="text-align: center; font-weight: 600;"><?= $pIdx + 1 ?></td>
              <td style="font-weight: 600;"><?= htmlspecialchars($ps['subject_section'] ?? '—') ?></td>
              <td style="text-align: center;"><?= number_format((int)($ps['participants_count'] ?? 0)) ?></td>
              <td>
                <?php
                  $teachers = array_filter(array_map('trim', explode(',', $ps['teacher_name'] ?? '')));
                  if (empty($teachers)): ?>
                    <span style="color: var(--text-muted); font-style: italic;">None assigned</span>
                  <?php else: ?>
                    <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                      <?php foreach ($teachers as $t): ?>
                        <span class="badge" style="background: rgba(2, 132, 199, 0.1); color: #0284c7; border: 1px solid rgba(2, 132, 199, 0.25); font-size: 0.75rem; padding: 3px 8px; border-radius: 4px; font-weight: 600;">
                          <?= htmlspecialchars($t) ?>
                        </span>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- Materials -->
    <?php if (!empty($materials)): ?>
    <div class="section-title">📦 Materials Needed</div>
    <div class="table-wrap" style="margin-bottom:14px;">
      <table><thead><tr><th>Item</th><th>Description</th><th>Qty</th><th>Provider</th><th>Est. Cost</th></tr></thead>
      <tbody><?php foreach($materials as $m): ?>
      <tr><td><?= htmlspecialchars($m['item_name']) ?></td><td><?= htmlspecialchars($m['description']??'') ?></td><td><?= $m['quantity'] ?></td><td><?= htmlspecialchars($m['provider']??'') ?></td><td>₱<?= number_format($m['est_cost'],2) ?></td></tr>
      <?php endforeach; ?></tbody>
      <tfoot><tr><td colspan="4" style="text-align:right;font-weight:700;">Total Est. Cost:</td><td>₱<?= number_format(array_sum(array_map(fn($m)=>$m['est_cost']*$m['quantity'],$materials)),2) ?></td></tr></tfoot>
      </table>
    </div>
    <?php endif; ?>

    <?php if ($clrInfoView['required'] || !empty($activity['clearance_obtained'])): ?>
    <div id="clearance-view-strip" style="margin-bottom:24px; padding:12px 16px; background:var(--bg-base); border:1px solid var(--border); border-radius:6px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
      <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
        <span style="font-weight:700; color:var(--text-muted); font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px;">School Admin / President Clearance:</span>
        <?= $clrInfoView['badge'] ?>
        <strong style="font-size:0.85rem; color:var(--text-main);"><?= htmlspecialchars($clrInfoView['label']) ?></strong>
        <?php if (!empty($activity['clearance_notes'])): ?>
          <span class="text-sm text-muted" style="font-style:italic;">(<?= htmlspecialchars($activity['clearance_notes']) ?>)</span>
        <?php endif; ?>
      </div>
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

    <!-- Task & Role Assignments -->
    <div class="section-title">📋 Task & Role Assignments (<?= count($ftasks) ?>)</div>
    <div style="margin-bottom: 12px; padding: 10px 16px; background: var(--bg-base); border: 1px solid var(--border); border-radius: 6px; font-size: 0.85rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
      <div>
        <span style="font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.5px;">Activity Leader:</span>
        <strong style="color: var(--text-main); margin-left: 4px;"><?= htmlspecialchars($activity['fn']) ?></strong>
        <span class="badge badge-primary" style="font-size: 0.7rem; padding: 2px 7px; margin-left: 6px;">Spearhead</span>
      </div>
      <div style="display: flex; align-items: center; gap: 8px;">
        <a href="<?= BASE_URL ?>/dean/task-monitoring.php?activity_id=<?= (int)$activity['id'] ?>" class="btn btn-outline btn-sm" style="font-size: 0.75rem; padding: 3px 10px;">
          📊 Centralized Task Monitoring &rarr;
        </a>
      </div>
    </div>
    <div class="table-wrap" style="margin-bottom:24px;">
      <?php if (empty($ftasks)): ?>
        <div style="padding: 24px; text-align: center; color: var(--text-muted); font-size: 0.85rem;">
          No task assignments created yet for this activity.
        </div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th style="width: 20%;">Task & Description</th>
              <th style="width: 12%;">Committee</th>
              <th style="width: 14%;">Assigned Member</th>
              <th style="width: 10%;">Role</th>
              <th style="width: 10%;">Target Date</th>
              <th style="width: 11%;">Completion</th>
              <th style="width: 15%;">Deliverables & Evidence</th>
              <th style="width: 8%;">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($ftasks as $f): 
              $tTitle = $f['task_title'] ?: $f['assigned_task'] ?: 'Untitled Task';
              $tDesc = $f['task_description'] ?: $f['contribution_desc'] ?: '';
              $tCommittee = $f['committee'] ?: '—';
              $tPerson = $f['assigned_user_name'] ?: $f['faculty_name'] ?: '—';
              $tRole = $f['role_in_event'] ?: '—';
              $tDue = !empty($f['due_date']) ? date('M j, Y', strtotime($f['due_date'])) : '—';
              $tStatus = in_array($f['status'], ['Not Started', 'In Progress', 'Completed', 'Delayed']) ? $f['status'] : 'Not Started';
              $tPct = (int)($f['completion_pct'] ?? 0);
              $tDelivs = $taskDeliverables[(int)$f['id']] ?? [];
            ?>
            <tr>
              <td>
                <div style="font-weight: 600; color: var(--text-main);"><?= htmlspecialchars($tTitle) ?></div>
                <?php if (!empty($tDesc)): ?>
                  <small style="color: var(--text-muted); display: block; margin-top: 2px;"><?= nl2br(htmlspecialchars($tDesc)) ?></small>
                <?php endif; ?>
              </td>
              <td><?= $tCommittee !== '—' ? '<span class="badge badge-secondary">'.htmlspecialchars($tCommittee).'</span>' : '—' ?></td>
              <td><strong><?= htmlspecialchars($tPerson) ?></strong></td>
              <td><?= htmlspecialchars($tRole) ?></td>
              <td>
                <?php if ($tDue !== '—'): 
                  $isOverdue = (!empty($f['due_date']) && date('Y-m-d', strtotime($f['due_date'])) < date('Y-m-d') && $tStatus !== 'Completed');
                ?>
                  <span class="time-badge" style="<?= $isOverdue ? 'color:#ef4444;border-color:rgba(239,68,68,0.4);background:rgba(239,68,68,0.08);' : '' ?>">
                    📅 <?= htmlspecialchars($tDue) ?>
                  </span>
                  <?php if ($isOverdue): ?>
                    <span class="badge badge-danger" style="background:#ef4444;color:#fff;font-size:0.68rem;margin-left:4px;">Overdue</span>
                  <?php endif; ?>
                <?php else: ?>
                  <span style="color:var(--text-muted);font-size:0.80rem;">—</span>
                <?php endif; ?>
              </td>
              <td>
                <div style="display:flex;align-items:center;gap:6px;">
                  <div style="flex:1;height:6px;background:var(--border,#e5e7eb);border-radius:999px;overflow:hidden;min-width:40px;">
                    <div style="height:100%;width:<?= $tPct ?>%;background:<?= $tStatus === 'Completed' ? '#10b981' : ($tStatus === 'Delayed' ? '#ef4444' : '#0284c7') ?>;"></div>
                  </div>
                  <span style="font-size:0.75rem;font-weight:700;color:var(--text-main);"><?= $tPct ?>%</span>
                </div>
              </td>
              <td>
                <?php if (!empty($tDelivs)): ?>
                  <div style="display:flex;flex-direction:column;gap:5px;">
                    <?php foreach ($tDelivs as $dl): 
                      $rBadge = ($dl['review_status'] === 'approved') ? 'badge-success' : (($dl['review_status'] === 'returned_for_improvement') ? 'badge-danger' : 'badge-warning');
                      $rLabel = ($dl['review_status'] === 'approved') ? 'Approved' : (($dl['review_status'] === 'returned_for_improvement') ? 'Returned' : 'Pending');
                    ?>
                      <div style="font-size:0.75rem;background:var(--bg-base);padding:4px 8px;border-radius:6px;border:1px solid var(--border);">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;">
                          <a href="<?= BASE_URL ?>/api/document-download.php?deliverable_id=<?= (int)$dl['id'] ?>" target="_blank" style="font-weight:700;color:#0284c7;text-decoration:none;" title="Download / Open file">
                            📎 <?= htmlspecialchars(mb_strimwidth($dl['file_name'], 0, 18, '...')) ?>
                          </a>
                          <span class="badge <?= $rBadge ?>" style="font-size:0.62rem;padding:1px 5px;"><?= $rLabel ?></span>
                        </div>
                        <div style="font-size:0.68rem;color:var(--text-muted);margin-top:2px;">
                          By <?= htmlspecialchars($dl['uploader_name']) ?> &bull; <?= date('M j', strtotime($dl['uploaded_at'])) ?>
                        </div>
                        <?php if (!empty($dl['review_notes'])): ?>
                          <div style="font-size:0.68rem;color:var(--text-secondary);font-style:italic;margin-top:2px;">Feedback: <?= htmlspecialchars($dl['review_notes']) ?></div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <span style="font-size:0.75rem;color:var(--text-muted);font-style:italic;">No files yet</span>
                <?php endif; ?>
              </td>
              <td><?= getTaskStatusBadge($tStatus) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

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
    <?php if ($postEvent || !empty($documents)): ?>
    <div class="section-title">📊 Post-Event Results & Documentation</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">
      <div>
        <?php if ($postEvent): ?>
        <div class="info-grid" style="grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));">
          <?php
          $attPct = ($postEvent['target_attendance'] > 0 && isset($postEvent['actual_attendance'])) ? min(100, round(($postEvent['actual_attendance'] / $postEvent['target_attendance']) * 100)) : 0;
          $deanTiles = [
            'Actual Attendance' => $postEvent['actual_attendance'],
            'Target'            => $postEvent['target_attendance'],
            'Attendance Rate'   => $attPct . '%',
            'Satisfaction Score'=> (isset($postEvent['satisfaction_score']) && $postEvent['satisfaction_score'] !== null) ? number_format($postEvent['satisfaction_score'], 1) . '/5' : '—'
          ];
          if (!empty($postEvent['manual_attendance'])) {
            $deanTiles['Paper-Based Count'] = (int)$postEvent['manual_attendance'];
          }
          foreach($deanTiles as $l=>$v):
          ?>
          <div class="info-tile"><div class="lbl"><?= $l ?></div><div class="val"><?= $v ?></div></div>
          <?php endforeach; ?>
        </div>

        <?php if (!empty($postEvent['assessment_report_path']) || !empty($postEvent['survey_csv_path'])): ?>
        <div class="info-tile" style="margin-top:10px;">
          <div class="lbl" style="margin-bottom:8px;">📑 Core Report Documents</div>
          <div style="display:flex;flex-direction:column;gap:8px;">
            <?php if (!empty($postEvent['assessment_report_path'])):
              $vaPdfName = function_exists('getFriendlyDocumentFilename') ? getFriendlyDocumentFilename($db, $id, $postEvent['assessment_report_path']) : basename($postEvent['assessment_report_path']);
              $vaPdfUrl  = function_exists('resolveUploadedFileUrl') ? resolveUploadedFileUrl($postEvent['assessment_report_path']) : (BASE_URL . '/uploads/' . ltrim($postEvent['assessment_report_path'], '/\\'));
            ?>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.28);border-radius:6px;gap:8px;flex-wrap:wrap;">
              <span style="font-size:0.85rem;font-weight:600;color:var(--text-main);display:flex;align-items:center;gap:6px;">
                <span style="color:#059669;font-weight:bold;">[✓ PDF]</span> Assessment Report: <?= htmlspecialchars($vaPdfName) ?>
              </span>
              <a href="<?= htmlspecialchars($vaPdfUrl) ?>" target="_blank" class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:0.75rem;">View PDF</a>
            </div>
            <?php endif; ?>
            <?php if (!empty($postEvent['survey_csv_path'])):
              $vaCsvName = function_exists('getFriendlyDocumentFilename') ? getFriendlyDocumentFilename($db, $id, $postEvent['survey_csv_path']) : basename($postEvent['survey_csv_path']);
              $vaCsvUrl  = function_exists('resolveUploadedFileUrl') ? resolveUploadedFileUrl($postEvent['survey_csv_path']) : (BASE_URL . '/uploads/' . ltrim($postEvent['survey_csv_path'], '/\\'));
            ?>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:rgba(2,132,199,0.06);border:1px solid rgba(2,132,199,0.2);border-radius:6px;gap:8px;flex-wrap:wrap;">
              <span style="font-size:0.85rem;font-weight:600;color:var(--text-main);display:flex;align-items:center;gap:6px;">
                <span style="color:#0284c7;font-weight:bold;">[✓ CSV]</span> Survey Results: <?= htmlspecialchars($vaCsvName) ?>
              </span>
              <a href="<?= htmlspecialchars($vaCsvUrl) ?>" target="_blank" class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:0.75rem;">Download CSV</a>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($postEvent['observations'])): ?>
        <div class="info-tile" style="margin-top:10px;"><div class="lbl">Observations</div><div style="font-size:.82rem;margin-top:6px;"><?= nl2br(htmlspecialchars($postEvent['observations'])) ?></div></div>
        <?php endif; ?>
        <?php if ($postEvent['recommendations']): ?>
        <div class="info-tile" style="margin-top:10px;"><div class="lbl">Recommendations</div><div style="font-size:.82rem;margin-top:6px;"><?= nl2br(htmlspecialchars($postEvent['recommendations'])) ?></div></div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($documents)): ?>
        <div class="info-tile" style="<?= $postEvent ? 'margin-top:10px;' : '' ?>">
          <div class="lbl" style="margin-bottom:10px;">📎 Attached Documents & Reports (<?= count($documents) ?>)</div>
          <div style="display:flex;flex-direction:column;gap:8px;">
            <?php foreach ($documents as $doc): ?>
            <div class="doc-item-row" style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:var(--bg-base);border:1px solid var(--border);border-radius:8px;">
              <div style="display:flex;align-items:center;gap:10px;overflow:hidden;min-width:0;">
                <span style="font-size:1.15rem;flex-shrink:0;">📄</span>
                <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                  <span style="font-size:0.85rem;font-weight:600;display:block;overflow:hidden;text-overflow:ellipsis;color:var(--text-main);"><?= htmlspecialchars($doc['file_name'] ?: basename($doc['file_path'])) ?></span>
                  <small style="color:var(--text-muted);font-size:0.75rem;"><?= date('M d, Y h:i A', strtotime($doc['uploaded_at'])) ?></small>
                </div>
              </div>
              <div style="display:flex;gap:6px;flex-shrink:0;margin-left:12px;">
                <a href="<?= BASE_URL ?>/api/document-download.php?id=<?= $doc['id'] ?>" target="_blank" class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:0.75rem;">View</a>
                <a href="<?= BASE_URL ?>/api/document-download.php?id=<?= $doc['id'] ?>&download=1" class="btn btn-primary btn-sm" style="padding:4px 10px;font-size:0.75rem;">Download</a>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Post-Activity Compliance -->
    <div class="section-title" id="post-activity-compliance-section" style="display:flex;align-items:center;justify-content:space-between;">
      <span>🛡️ Post-Activity Compliance</span>
      <span class="badge <?= $complianceStatusBadgeClass ?>" style="font-size:0.82rem;padding:4px 10px;text-transform:none;">
        <?= htmlspecialchars($complianceResult['overall_status'] ?? 'Not Verifiable') ?>
      </span>
    </div>

    <div class="info-grid" id="compliance-summary-grid" style="grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:12px;margin-bottom:16px;">
      <div class="info-tile">
        <div class="lbl">Compliance Rate</div>
        <div class="val" style="margin-top:4px;">
          <?php if (($complianceResult['compliance_percentage'] ?? null) !== null): ?>
            <span style="font-size:1.35rem;font-weight:800;"><?= number_format($complianceResult['compliance_percentage'], 1) ?>%</span>
          <?php else: ?>
            <span class="badge badge-secondary" style="font-size:0.82rem;">Not Verifiable</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="info-tile">
        <div class="lbl">Verifiable Checks</div>
        <div class="val" style="margin-top:4px;font-size:1.2rem;font-weight:700;">
          <?= (int)($complianceResult['verifiable_count'] ?? 0) ?> <span class="text-sm text-muted" style="font-size:0.8rem;font-weight:400;">/ <?= (int)($complianceResult['total_checks'] ?? 8) ?></span>
        </div>
      </div>
      <div class="info-tile">
        <div class="lbl">Compliant Checks</div>
        <div class="val" style="margin-top:4px;font-size:1.2rem;font-weight:700;color:var(--success, #16a34a);">
          <?= (int)($complianceResult['compliant_count'] ?? 0) ?>
        </div>
      </div>
      <div class="info-tile">
        <div class="lbl">Non-Compliant Checks</div>
        <div class="val" style="margin-top:4px;font-size:1.2rem;font-weight:700;color:<?= ($complianceResult['non_compliant_count'] ?? 0) > 0 ? 'var(--danger, #dc2626)' : 'inherit' ?>;">
          <?= (int)($complianceResult['non_compliant_count'] ?? 0) ?>
        </div>
      </div>
      <div class="info-tile">
        <div class="lbl">Not Verifiable Checks</div>
        <div class="val" style="margin-top:4px;font-size:1.2rem;font-weight:700;color:var(--text-muted, #64748b);">
          <?= (int)($complianceResult['not_verifiable_count'] ?? 0) ?>
        </div>
      </div>
    </div>

    <div class="table-wrap" id="compliance-table-wrap" style="margin-bottom:24px;">
      <table id="compliance-checks-table" style="width:100%;">
        <thead>
          <tr>
            <th style="width:24%;">Check Name</th>
            <th style="width:18%;">Result</th>
            <th style="width:29%;">Approved / Proposal Value</th>
            <th style="width:29%;">Actual / Post-Event Value</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $complianceNameMap = [
              'Activity title'                    => 'Activity Title',
              'Event date'                        => 'Event Date',
              'Venue'                             => 'Venue',
              'Objectives'                        => 'Objectives',
              'Program/sequence'                  => 'Program / Sequence',
              'Assigned people/manpower'          => 'Assigned People / Manpower',
              'KPI/evaluation criteria'           => 'KPI / Evaluation Criteria',
              'Post-event documentation presence' => 'Post-Event Documentation',
          ];
          foreach (($complianceResult['checks'] ?? []) as $check):
              $checkLabel = $complianceNameMap[$check['field']] ?? $check['field'];
              $resBadgeClass = match($check['result']) {
                  'Compliant' => 'badge-success',
                  'Partially Compliant' => 'badge-warning',
                  'Not Compliant', 'Non-Compliant' => 'badge-danger',
                  default => 'badge-secondary'
              };
          ?>
          <tr>
            <td>
              <strong style="display:block;font-size:0.85rem;color:var(--text-main);"><?= htmlspecialchars($checkLabel) ?></strong>
              <?php if (!empty($check['notes'])): ?>
                <span class="text-sm text-muted" style="display:block;font-size:0.75rem;margin-top:2px;line-height:1.3;"><?= htmlspecialchars($check['notes']) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge <?= $resBadgeClass ?>" style="font-weight:700;">
                <?= htmlspecialchars($check['result']) ?>
              </span>
            </td>
            <td>
              <?php if (!empty($check['approved_value'])): ?>
                <div style="font-size:0.82rem;line-height:1.4;white-space:pre-wrap;"><?= htmlspecialchars($check['approved_value']) ?></div>
              <?php else: ?>
                <span class="badge badge-secondary" style="font-size:0.75rem;">Not Defined</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($check['result'] === 'Not Verifiable' || $check['actual_value'] === null || trim((string)$check['actual_value']) === ''): ?>
                <span class="badge badge-secondary" style="font-size:0.75rem;">Not Verifiable</span>
              <?php else: ?>
                <div style="font-size:0.82rem;line-height:1.4;white-space:pre-wrap;"><?= htmlspecialchars($check['actual_value']) ?></div>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- KPI Targets & Results -->
    <?php
    $deanKpiCalc = function_exists('calculateActivityKpis') ? calculateActivityKpis($id, $db) : [];
    $deanAllKpis = !empty($deanKpiCalc['all_kpi_rows']) ? $deanKpiCalc['all_kpi_rows'] : [];
    ?>
    <?php if (!empty($deanAllKpis)): ?>
    <div class="section-title" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
      <span>📊 Key Performance Indicators (KPIs) & Evaluation Results</span>
      <?php if (!empty($deanKpiCalc['overall_performance'])): ?>
        <span class="badge <?= getKpiStatusClass($deanKpiCalc['overall_status'] ?? '') ?>">
          Overall: <?= $deanKpiCalc['overall_performance'] ?>% (<?= htmlspecialchars($deanKpiCalc['overall_status'] ?? '') ?>)
        </span>
      <?php endif; ?>
    </div>
    <?php if (!empty($deanKpiCalc['deduplication_applied'])): ?>
      <div style="padding:8px 12px; background:var(--bg-subtle, #f0fdf4); border:1px solid #86efac; border-radius:6px; color:#166534; font-size:0.8rem; margin-bottom:12px;">
        <strong>ℹ️ Overlapping Attendees Protection:</strong> Both paper-based attendance and online survey responses were recorded. Overlapping participants have been deduplicated to prevent double-counting.
      </div>
    <?php endif; ?>
    <div class="table-wrap" style="margin-bottom:24px;">
      <table>
        <thead>
          <tr>
            <th>KPI / Indicator</th>
            <th>Target Metric</th>
            <th>Evaluation Method</th>
            <th>Actual Result</th>
            <th>Achievement</th>
            <th>Calculation Details</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($deanAllKpis as $dk):
            $dCls = getKpiStatusClass($dk['status'] ?? 'Insufficient Data');
          ?>
          <tr>
            <td><strong><?= htmlspecialchars($dk['indicator'] ?? $dk['name'] ?? '') ?></strong></td>
            <td><?= htmlspecialchars($dk['target'] ?? '—') ?></td>
            <td><small class="badge badge-secondary" style="font-size:0.75rem;"><?= htmlspecialchars($dk['evaluation_method'] ?? '—') ?></small></td>
            <td><?= htmlspecialchars($dk['actual'] ?? '—') ?></td>
            <td><strong><?= htmlspecialchars($dk['achievement'] ?? '—') ?></strong></td>
            <td><small style="color:var(--text-muted); line-height:1.4; display:block;"><?= htmlspecialchars($dk['calculation_details'] ?? 'Standard calculation') ?></small></td>
            <td><span class="badge <?= $dCls ?>"><?= htmlspecialchars($dk['status'] ?? '—') ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php elseif (!empty($kpiData)): ?>
    <div class="section-title">📊 KPI & Evaluation Targets</div>
    <div class="table-wrap" style="margin-bottom:24px;">
      <table><thead><tr><th>Indicator</th><th>Target Metric</th><th>Evaluation Method</th></tr></thead>
      <tbody><?php foreach($kpiData as $k): ?>
      <tr><td><?= htmlspecialchars($k['indicator']??'') ?></td><td><?= htmlspecialchars($k['target_metric']??'') ?></td><td><?= htmlspecialchars($k['evaluation_method']??'') ?></td></tr>
      <?php endforeach; ?></tbody></table>
    </div>
    <?php endif; ?>
    <?php if (!empty($activity['evaluation_method'])): ?>
    <div style="margin-bottom:24px;">
      <div class="text-sm fw-bold" style="margin-bottom:4px;">Google Form Link</div>
      <a href="<?= htmlspecialchars($activity['evaluation_method']) ?>" target="_blank" class="text-sm"><?= htmlspecialchars($activity['evaluation_method']) ?></a>
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
    <?php if (!empty($history)): ?>
    <div class="section-title">📌 Approval Timeline</div>
    <div style="margin-bottom:24px;">
      <?php foreach($history as $h): ?>
      <div class="timeline-item">
        <div>
          <div class="timeline-dot" style="background:<?= in_array($h['action'],['approved','forwarded'])?'#16A34A':(in_array($h['action'],['returned','returned_for_revision'])?'#D97706':'#DC2626') ?>"></div>
        </div>
        <div>
          <div style="font-size:.83rem;font-weight:600;">
            <?= htmlspecialchars($h['name']) ?> 
            <span style="color:var(--text-muted);font-weight:400;">(<?= htmlspecialchars(ucwords(str_replace('_',' ',$h['reviewer_role']??''))) ?>)</span>
            — <?= ucfirst(str_replace('_', ' ', $h['action'])) ?>
          </div>
          <div class="text-sm text-muted"><?= date('F j, Y g:i A', strtotime($h['acted_at'])) ?></div>
          <?php if (!empty($h['notes'])): ?><div class="text-sm" style="margin-top:4px;font-style:italic;color:var(--text-secondary);">"<?= nl2br(htmlspecialchars($h['notes'])) ?>"</div><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php endif; // End activeTab === 'overview' ?>

    <!-- Responses Tab -->
    <?php if ($activeTab === 'responses'): ?>
        <?php
        $anonNames = [];
        $anonMap = [];
        $anonId = 1;
        foreach ($responsesList as $index => $resRow) {
            $rawName = trim($resRow['evaluator_name'] ?? '');
            if (empty($rawName)) {
                $anonNames[$index] = 'Anonymous Participant';
            } else {
                if (!isset($anonMap[$rawName])) {
                    $anonMap[$rawName] = 'Participant ' . $anonId++;
                }
                $anonNames[$index] = $anonMap[$rawName];
            }
        }
        ?>
        <div class="card">
          <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <h2>📋 Completed Participant Evaluations</h2>
            <span class="badge badge-info"><?= count($responsesList) ?> total responses</span>
          </div>
          <div class="card-body">
            <?php if (empty($responsesList)): ?>
              <div style="text-align:center; padding:40px; color:var(--text-muted);">
                No evaluation responses have been submitted yet.
              </div>
            <?php else: ?>
              <div class="filters" style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
                <input type="text" id="commentsSearch" placeholder="Search comments..." oninput="filterResponses()" class="form-control" style="max-width: 250px;">
                <select id="ratingFilter" onchange="filterResponses()" class="form-control" style="max-width: 180px;">
                  <option value="all">All Responses</option>
                  <option value="4">4 Stars</option>
                  <option value="3">3 Stars</option>
                  <option value="2">2 Stars</option>
                  <option value="1">1 Star</option>
                  <option value="text">Text Comments Only</option>
                </select>
              </div>
              <div class="table-wrap">
                <table id="responsesTable">
                  <thead>
                    <tr>
                      <th style="width: 20%;">Respondent</th>
                      <th style="width: 45%;">Criterion / Question</th>
                      <th style="width: 15%;">Rating</th>
                      <th style="width: 20%;">Feedback Comments</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach($responsesList as $index => $r): ?>
                    <tr class="response-row" data-rating="<?= $r['rating'] !== null ? $r['rating'] : 'text' ?>" data-comment="<?= htmlspecialchars(strtolower($r['comments'] ?? '')) ?>">
                      <td><strong><?= htmlspecialchars($anonNames[$index]) ?></strong></td>
                      <td><?= htmlspecialchars($r['criteria'] ?? '') ?></td>
                      <td>
                        <?php if ($r['rating'] !== null): ?>
                          <span class="kpi-star"><?= str_repeat('★', $r['rating']).str_repeat('☆', 4-$r['rating']) ?></span>
                          <small style="font-weight:700; margin-left:4px;"><?= $r['rating'] ?>/4</small>
                        <?php else: ?>
                          <span class="text-muted">—</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if (!empty($r['comments'])): ?>
                          <span style="font-style: italic;">"<?= htmlspecialchars($r['comments']) ?>"</span>
                        <?php else: ?>
                          <span class="text-muted">—</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <script>
                function filterResponses() {
                  const search = document.getElementById('commentsSearch').value.toLowerCase();
                  const rating = document.getElementById('ratingFilter').value;
                  const rows = document.querySelectorAll('.response-row');
                  
                  rows.forEach(row => {
                    const comment = row.getAttribute('data-comment');
                    const rowRating = row.getAttribute('data-rating');
                    
                    const matchesSearch = comment.includes(search);
                    let matchesRating = true;
                    if (rating === 'text') {
                      matchesRating = (rowRating === 'text');
                    } else if (rating !== 'all') {
                      matchesRating = (rowRating === rating);
                    }
                    
                    if (matchesSearch && matchesRating) {
                      row.style.display = '';
                    } else {
                      row.style.display = 'none';
                    }
                  });
                }
              </script>
            <?php endif; ?>
          </div>
        </div>
    <?php endif; ?>

    <script>
      const ratingData = {
        avgRating: <?= json_encode($avgRating) ?>,
        commentsCount: <?= json_encode($commentsCount) ?>,
        questionAverages: <?= json_encode($questionAverages) ?>
      };

      function calculateAverageRating() {
        return ratingData.avgRating.toFixed(2);
      }

      function calculateCommentsAnalyzed() {
        return ratingData.commentsCount;
      }

      function renderRatingBars() {
        let html = '';
        ratingData.questionAverages.forEach(q => {
          const pct = (q.average / 4) * 100;
          html += `
            <div>
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; font-size:0.8rem;">
                <span style="font-weight:600; color:var(--text-main);">${escapeHtml(q.question)}</span>
                <span style="font-weight:700; color:var(--accent);">${q.average} / 4.0 (${q.count} ratings)</span>
              </div>
              <div style="height:8px; background:var(--border); border-radius:999px; overflow:hidden;">
                <div style="height:100%; border-radius:999px; width:${pct}%; background:var(--accent);"></div>
              </div>
            </div>
          `;
        });
        if (html === '') {
          return '<div class="text-muted text-sm">No rating questions found.</div>';
        }
        return html;
      }
    </script>

  </div>
</div>
<?php include __DIR__ . '/../includes/activity-print-document.php'; ?>
</body>
</html>

