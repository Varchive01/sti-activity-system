<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';
requireRole('admin1');
$user = currentUser();
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: '.BASE_URL.'/admin1/dashboard.php'); exit; }

$act  = $db->prepare("SELECT a.*,u.name as fn,u.email as fe FROM activities a JOIN users u ON a.faculty_id=u.id WHERE a.id=?");
$act->execute([$id]); $activity=$act->fetch();
if (!$activity || !canUserAccessActivity((int)$id, 'view', (int)$user['id'], $user['role'], $user['dept'] ?? '')) {
  die('Activity not found or not accessible.');
}

$mats = $db->prepare("SELECT * FROM materials WHERE activity_id=?"); $mats->execute([$id]); $materials=$mats->fetchAll();

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
$gl   = $db->prepare("SELECT * FROM guidelines WHERE activity_id=?"); $gl->execute([$id]); $guidelines=$gl->fetch();
$pe   = $db->prepare("SELECT * FROM post_event WHERE activity_id=?"); $pe->execute([$id]); $postEvent=$pe->fetch();
$docs = $db->prepare("SELECT * FROM documents WHERE activity_id=? ORDER BY uploaded_at DESC"); $docs->execute([$id]); $documents=$docs->fetchAll();
$psStmt = $db->prepare("SELECT * FROM activity_participants WHERE activity_id=? ORDER BY row_no ASC, id ASC"); $psStmt->execute([$id]); $partSections=$psStmt->fetchAll();
$kpis = $db->prepare("SELECT criteria,AVG(rating) as avg FROM kpi_evaluations WHERE activity_id=? GROUP BY criteria"); $kpis->execute([$id]); $kpiData=$kpis->fetchAll();
$rca  = $db->prepare("SELECT * FROM root_cause_analysis WHERE activity_id=?"); $rca->execute([$id]); $rcas=$rca->fetchAll();
$logs = $db->prepare("SELECT al.*,u.name,u.role as reviewer_role FROM approval_logs al JOIN users u ON al.reviewer_id=u.id WHERE al.activity_id=? ORDER BY al.acted_at"); $logs->execute([$id]); $history=$logs->fetchAll();

$currentApprover = 'None';
if (in_array($activity['status'], ['submitted', 'under_review', 'resubmitted'])) {
    $currentApprover = 'Student Organization Head (Committee Review)';
} elseif (in_array($activity['status'], ['endorsed', 'pending_final_approval'])) {
    $currentApprover = 'Dean (Final Approval)';
} elseif ($activity['status'] === 'returned_for_revision') {
    $currentApprover = 'Faculty / Proponent (Revision)';
} elseif ($activity['status'] === 'draft') {
    $currentApprover = 'Faculty / Proponent (Draft)';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>View Activity – STI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=1.0.7">
<style>
.timeline-line{width:1px;background:var(--border);margin:14px 4px 0;flex:0 0 1px;}
.kpi-star{color:var(--sti-gold);}
</style>
</head>
<body class="theme-arjay">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">Activity Detail</div>
    <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <a href="<?= BASE_URL ?>/admin1/activities.php" class="btn btn-outline btn-sm">← Back</a>
      <?php if (in_array($activity['status'], ['draft', 'returned_for_revision'], true) && canUserAccessActivity((int)$id, 'modify', (int)$user['id'], $user['role'], $user['dept'] ?? '')): ?>
        <a href="<?= BASE_URL ?>/faculty/proposal-edit.php?id=<?= $id ?>" class="btn btn-primary btn-sm">Edit Proposal</a>
      <?php endif; ?>
      <?php if (in_array($activity['status'], ['under_review', 'submitted', 'resubmitted'], true) && ($activity['source'] ?? '') === 'student_org'): ?>
        <a href="<?= BASE_URL ?>/admin1/review.php?id=<?= $activity['id'] ?>" class="btn btn-primary btn-sm">Review Proposal</a>
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
        <h1 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:1.4rem;font-weight:800;"><?= htmlspecialchars($activity['title']) ?></h1>
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

    <!-- Task & Role Assignments -->
    <div class="section-title">📋 Task & Role Assignments (<?= count($ftasks) ?>)</div>
    <div style="margin-bottom: 12px; padding: 10px 16px; background: var(--bg-base); border: 1px solid var(--border); border-radius: 6px; font-size: 0.85rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
      <div>
        <span style="font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.5px;">Activity Leader:</span>
        <strong style="color: var(--text-main); margin-left: 4px;"><?= htmlspecialchars($activity['fn']) ?></strong>
        <span class="badge badge-primary" style="font-size: 0.7rem; padding: 2px 7px; margin-left: 6px;">Spearhead</span>
      </div>
      <div style="font-size: 0.8rem; color: var(--text-muted);">
        Distribute work among committees & members
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
              <th style="width: 25%;">Task & Description</th>
              <th style="width: 17%;">Committee</th>
              <th style="width: 18%;">Assigned Member</th>
              <th style="width: 16%;">Role</th>
              <th style="width: 12%;">Target Date</th>
              <th style="width: 12%;">Status</th>
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
              $isOverdue = (!empty($f['due_date']) && date('Y-m-d', strtotime($f['due_date'])) < date('Y-m-d') && $tStatus !== 'Completed');
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
                <?php if ($tDue !== '—'): ?>
                  <span class="time-badge" style="<?= $isOverdue ? 'color:#ef4444;border-color:rgba(239,68,68,0.4);background:rgba(239,68,68,0.08);' : '' ?>">
                    📅 <?= htmlspecialchars($tDue) ?>
                  </span>
                  <?php if ($isOverdue): ?>
                    <span class="badge badge-danger" style="background:#ef4444;color:#fff;font-size:0.68rem;margin-left:4px;">Overdue</span>
                  <?php endif; ?>
                <?php else: ?>
                  —
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
          $admin1Tiles = [
            'Actual Attendance' => $postEvent['actual_attendance'],
            'Target'            => $postEvent['target_attendance'],
            'Attendance Rate'   => $attPct . '%',
            'Satisfaction Score'=> (isset($postEvent['satisfaction_score']) && $postEvent['satisfaction_score'] !== null) ? number_format($postEvent['satisfaction_score'], 1) . '/5' : '—'
          ];
          if (!empty($postEvent['manual_attendance'])) {
            $admin1Tiles['Paper-Based Count'] = (int)$postEvent['manual_attendance'];
          }
          foreach($admin1Tiles as $l=>$v):
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
      <div>
        <?php
        $actKpiCalc = function_exists('calculateActivityKpis') ? calculateActivityKpis($id, $db) : [];
        $definedKpiRows = !empty($actKpiCalc['all_kpi_rows']) ? $actKpiCalc['all_kpi_rows'] : [];
        ?>
        <?php if (!empty($definedKpiRows)): ?>
        <div class="info-tile" style="margin-bottom:14px;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <div class="lbl" style="margin:0;">Key Performance Indicators (KPIs)</div>
            <?php if (!empty($actKpiCalc['overall_performance'])): ?>
              <span class="badge <?= getKpiStatusClass($actKpiCalc['overall_status'] ?? '') ?>"><?= $actKpiCalc['overall_performance'] ?>% Overall</span>
            <?php endif; ?>
          </div>
          <div style="display:flex; flex-direction:column; gap:8px;">
            <?php foreach ($definedKpiRows as $dk):
              $dCls = getKpiStatusClass($dk['status'] ?? 'Insufficient Data');
            ?>
            <div style="background:var(--bg-base); border:1px solid var(--border); border-radius:6px; padding:8px 12px;">
              <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:4px;">
                <div>
                  <strong style="font-size:0.82rem; color:var(--text-main);"><?= htmlspecialchars($dk['indicator'] ?? '') ?></strong>
                  <small class="badge badge-secondary" style="font-size:0.68rem; margin-left:4px;"><?= htmlspecialchars($dk['evaluation_method'] ?? '') ?></small>
                </div>
                <span class="badge <?= $dCls ?>" style="font-size:0.75rem;"><?= htmlspecialchars($dk['achievement'] ?? '') ?> (<?= htmlspecialchars($dk['status'] ?? '') ?>)</span>
              </div>
              <div style="font-size:0.75rem; color:var(--text-muted); margin-top:3px;">
                Target: <strong><?= htmlspecialchars($dk['target'] ?? '—') ?></strong> | Actual: <strong><?= htmlspecialchars($dk['actual'] ?? '—') ?></strong>
              </div>
              <?php if (!empty($dk['calculation_details'])): ?>
                <div style="font-size:0.72rem; color:var(--text-muted); margin-top:3px; line-height:1.3; font-style:italic;">
                  <?= htmlspecialchars($dk['calculation_details']) ?>
                </div>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($kpiData)): ?>
        <div class="info-tile">
          <div class="lbl" style="margin-bottom:12px;">Survey Questions Breakdown</div>
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

