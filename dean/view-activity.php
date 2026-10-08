<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('dean');
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: '.BASE_URL.'/dean/dashboard.php'); exit; }

$act  = $db->prepare("SELECT a.*,u.name as fn,u.email as fe FROM activities a JOIN users u ON a.faculty_id=u.id WHERE a.id=?");
$act->execute([$id]); $activity=$act->fetch();
if (!$activity) die('Not found.');

$mats = $db->prepare("SELECT * FROM materials WHERE activity_id=?"); $mats->execute([$id]); $materials=$mats->fetchAll();

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
$logs = $db->prepare("SELECT al.*,u.name FROM approval_logs al JOIN users u ON al.reviewer_id=u.id WHERE al.activity_id=? ORDER BY al.acted_at"); $logs->execute([$id]); $history=$logs->fetchAll();

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
      <button class="btn btn-primary btn-sm" onclick="window.print()">🖨 Print</button>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>
  <div class="content">

    <!-- Tabs Navigation -->
    <?php
    $showTabs = ($activity['status'] === 'completed' || $postEvent);
    $activeTab = $_GET['tab'] ?? 'overview';
    if (!in_array($activeTab, ['overview', 'responses', 'ai-analysis'])) {
        $activeTab = 'overview';
    }
    if ($showTabs):
    ?>
    <div class="tabs-nav no-print">
      <a href="?id=<?= $id ?>&tab=overview" class="tab-link <?= $activeTab === 'overview' ? 'active' : '' ?>">Overview</a>
      <a href="?id=<?= $id ?>&tab=responses" class="tab-link <?= $activeTab === 'responses' ? 'active' : '' ?>">Evaluation Responses</a>
      <a href="?id=<?= $id ?>&tab=ai-analysis" class="tab-link <?= $activeTab === 'ai-analysis' ? 'active' : '' ?>">🤖 AI Feedback Analysis</a>
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

      <div>
        <h1 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:1.4rem;font-weight:800;color:var(--text-main);"><?= htmlspecialchars($activity['title']) ?></h1>
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

    <!-- Task & Role Assignments -->
    <div class="section-title">📋 Task & Role Assignments (<?= count($ftasks) ?>)</div>
    <div style="margin-bottom: 12px; padding: 10px 16px; background: var(--bg-subtle, #f8f9fa); border: 1px solid var(--border-color, #e5e7eb); border-radius: 6px; font-size: 0.85rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
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
              <td><?= $tDue !== '—' ? '<span class="time-badge">📅 '.htmlspecialchars($tDue).'</span>' : '—' ?></td>
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
                      <div style="font-size:0.75rem;background:var(--bg-subtle,#f8f9fa);padding:4px 8px;border-radius:6px;border:1px solid var(--border-color,#e5e7eb);">
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
        <div class="info-grid" style="grid-template-columns:1fr 1fr 1fr;">
          <?php
          $attPct = $postEvent['target_attendance'] > 0 ? round(($postEvent['actual_attendance']/$postEvent['target_attendance'])*100) : 0;
          foreach(['Actual Attendance'=>$postEvent['actual_attendance'],'Target'=>$postEvent['target_attendance'],'Attendance Rate'=>$attPct.'%','Satisfaction Score'=>number_format($postEvent['satisfaction_score'],1).'/5'] as $l=>$v):
          ?>
          <div class="info-tile"><div class="lbl"><?= $l ?></div><div class="val"><?= $v ?></div></div>
          <?php endforeach; ?>
        </div>
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
            <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:var(--bg-subtle,#f8f9fa);border:1px solid var(--border-color,#e5e7eb);border-radius:6px;">
              <div style="display:flex;align-items:center;gap:8px;overflow:hidden;min-width:0;">
                <span style="font-size:1.1rem;flex-shrink:0;">📄</span>
                <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                  <span style="font-size:0.85rem;font-weight:600;display:block;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($doc['file_name'] ?: basename($doc['file_path'])) ?></span>
                  <small style="color:var(--text-muted);font-size:0.75rem;"><?= date('M d, Y h:i A', strtotime($doc['uploaded_at'])) ?></small>
                </div>
              </div>
              <div style="display:flex;gap:6px;flex-shrink:0;margin-left:12px;">
                <a href="<?= BASE_URL ?>/api/document-download.php?id=<?= $doc['id'] ?>" target="_blank" class="btn btn-outline btn-sm" style="padding:4px 8px;font-size:0.75rem;">View</a>
                <a href="<?= BASE_URL ?>/api/document-download.php?id=<?= $doc['id'] ?>&download=1" class="btn btn-primary btn-sm" style="padding:4px 8px;font-size:0.75rem;">Download</a>
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

    <!-- KPI Targets -->
    <?php if (!empty($kpiData)): ?>
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
          <div class="timeline-dot" style="background:<?= in_array($h['action'],['approved','forwarded'])?'#16A34A':($h['action']==='returned'?'#D97706':'#DC2626') ?>"></div>
        </div>
        <div>
          <div style="font-size:.83rem;font-weight:600;"><?= htmlspecialchars($h['name']) ?> — <?= ucfirst($h['action']) ?></div>
          <div class="text-sm text-muted"><?= date('F j, Y g:i A', strtotime($h['acted_at'])) ?></div>
          <?php if ($h['notes']): ?><div class="text-sm" style="margin-top:4px;font-style:italic;">"<?= htmlspecialchars($h['notes']) ?>"</div><?php endif; ?>
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

    <!-- AI Feedback Analysis Tab -->
    <?php if ($activeTab === 'ai-analysis'): ?>
        <div id="ai-analysis-container">
           <div class="card">
             <div class="card-body" style="text-align:center; padding:50px;">
               <div class="spinner-border text-primary" role="status" style="width: 2rem; height: 2rem; display:inline-block; border: 0.2em solid currentColor; border-right-color: transparent; border-radius: 50%; animation: spin .75s linear infinite; margin-bottom: 12px; color: var(--accent);"></div>
               <p class="text-muted">Loading analysis status...</p>
             </div>
           </div>
        </div>
        
        <script>
          document.addEventListener('DOMContentLoaded', function() {
            checkAnalysisStatus();
          });

          async function checkAnalysisStatus() {
            const container = document.getElementById('ai-analysis-container');
            try {
              const res = await fetch('<?= BASE_URL ?>/api/generate-feedback-analysis.php?activity_id=<?= $id ?>');
              const data = await res.json();
              if (data.status === 'empty') {
                container.innerHTML = `
                  <div class="card">
                    <div class="card-body" style="text-align:center; padding:60px;">
                      <div style="font-size:3rem; margin-bottom:12px;">💬</div>
                      <h3 style="font-weight:700; margin-bottom:6px;">No Participant Feedback Yet</h3>
                      <p class="text-muted text-sm" style="max-width: 500px; margin: 0 auto;">Participant evaluation responses will appear here once participants complete the evaluation tool.</p>
                    </div>
                  </div>
                `;
              } else if (data.status === 'not_generated') {
                const count = data.responses_count;
                const isLimited = (count < 3);
                let warningText = '';
                if (isLimited) {
                  warningText = `
                    <div style="background:#fffbeb; border:1px solid #fef3c7; border-left:4px solid #f59e0b; padding:12px 16px; border-radius:6px; color:#b45309; font-size:0.82rem; margin-bottom:20px; text-align:left;">
                      <strong>Limited Feedback Data:</strong> There are currently only ${count} response(s). AI-generated themes may not fully represent the overall participant experience.
                    </div>
                  `;
                }
                container.innerHTML = `
                  <div class="card">
                    <div class="card-body" style="text-align:center; padding:60px;">
                      <div style="font-size:3rem; margin-bottom:12px;">🤖</div>
                      <h3 style="font-weight:700; margin-bottom:6px;">AI Participant Feedback Analysis</h3>
                      <p class="text-muted text-sm" style="max-width: 500px; margin: 0 auto; margin-bottom:20px;">Analyze recurring positive themes, improvement areas, tone, and receive actionable recommendations.</p>
                      ${warningText}
                      <button class="btn btn-primary" onclick="runAnalysis()" style="padding: 10px 24px; font-weight:700;">🤖 Generate AI Analysis</button>
                    </div>
                  </div>
                `;
              } else {
                renderAnalysis(data);
              }
            } catch (e) {
              container.innerHTML = `
                <div class="card">
                  <div class="card-body" style="text-align:center; padding:40px; color:var(--danger);">
                    ⚠️ Failed to load analysis status. Please try refreshing.
                  </div>
                </div>
              `;
            }
          }

          async function runAnalysis() {
            const container = document.getElementById('ai-analysis-container');
            let step = 1;
            container.innerHTML = `
              <div class="card">
                <div class="card-body" style="text-align:center; padding:60px;">
                  <div class="spinner-border text-primary" role="status" style="width: 2.5rem; height: 2.5rem; display:inline-block; border: 0.25em solid currentColor; border-right-color: transparent; border-radius: 50%; animation: spin .75s linear infinite; margin-bottom:20px; color: var(--accent);"></div>
                  <h3 id="loading-title" style="font-weight:700; margin-bottom:6px; color:var(--text-main);">🤖 Analyzing participant feedback...</h3>
                  <p class="text-muted text-sm">Please wait while Gemini processes the dataset.</p>
                </div>
              </div>
            `;

            const loadingTitle = document.getElementById('loading-title');
            const interval = setInterval(() => {
              step++;
              if (step === 2) {
                if (loadingTitle) loadingTitle.innerHTML = '🔍 Grouping recurring themes...';
              } else if (step === 3) {
                if (loadingTitle) loadingTitle.innerHTML = '🎯 Preparing recommendations...';
              }
            }, 3000);

            try {
              const formData = new FormData();
              formData.append('activity_id', '<?= $id ?>');
              formData.append('action', 'analyze');
              
              const res = await fetch('<?= BASE_URL ?>/api/generate-feedback-analysis.php', {
                method: 'POST',
                body: formData
              });
              clearInterval(interval);

              if (!res.ok) throw new Error("API Failure");
              const data = await res.json();
              renderAnalysis(data);
            } catch (e) {
              clearInterval(interval);
              container.innerHTML = `
                <div class="card">
                  <div class="card-body" style="text-align:center; padding:50px;">
                    <div style="font-size:3rem; margin-bottom:12px; color: var(--danger);">⚠️</div>
                    <h3 style="font-weight:700; margin-bottom:6px; color: var(--danger);">AI Analysis Temporarily Unavailable</h3>
                    <p class="text-muted text-sm" style="margin-bottom:20px;">Your evaluation results are still available. You can retry the AI analysis below.</p>
                    <button class="btn btn-primary" onclick="runAnalysis()" style="padding: 10px 20px;">Retry Analysis</button>
                  </div>
                </div>
              `;
            }
          }

          function renderAnalysis(data) {
            window.currentAnalysisData = data;
            const container = document.getElementById('ai-analysis-container');
            const analysis = data.analysis;
            
            const isFinalized = !!(data.is_finalized || analysis.is_finalized);
            const isReviewed = !!(data.is_reviewed || analysis.is_reviewed);
            const finBy = data.finalized_by || analysis.finalized_by;
            const finAt = data.finalized_at || analysis.finalized_at;

            let statusBadge = '';
            let summaryBadge = '';
            if (isFinalized) {
              statusBadge = `<span class="badge" style="background:#15803d; color:#fff; padding:6px 14px; border-radius:999px; font-size:0.75rem; font-weight:700;">🔒 Finalized by ${escapeHtml(finBy?.name || 'Administrator')} (${escapeHtml(finBy?.role || 'Admin')}) on ${formatDate(finAt)}</span>`;
              summaryBadge = `<span style="font-size:0.72rem; color:#15803d; background:#ecfdf5; padding:4px 10px; border-radius:4px; font-weight:700; border:1px solid #a7f3d0;">🔒 Administrator Finalized</span>`;
            } else if (isReviewed) {
              statusBadge = `<span class="badge" style="background:#d97706; color:#fff; padding:6px 14px; border-radius:999px; font-size:0.75rem; font-weight:700;">✏️ Admin Reviewed (Draft)</span>`;
              summaryBadge = `<span style="font-size:0.72rem; color:#b45309; background:#fffbeb; padding:4px 10px; border-radius:4px; font-weight:700; border:1px solid #fef3c7;">✏️ Admin Reviewed Draft</span>`;
            } else {
              statusBadge = `<span class="badge" style="background:#64748b; color:#fff; padding:6px 14px; border-radius:999px; font-size:0.75rem; font-weight:700;">🤖 Unreviewed AI Draft</span>`;
              summaryBadge = `<span style="font-size:0.72rem; color:var(--text-muted); background:var(--bg-base); padding:4px 10px; border-radius:4px; font-weight:600; border:1px solid var(--border);">🤖 AI-generated summary</span>`;
            }

            let outdatedAlert = '';
            if (data.is_outdated && !isFinalized && !isReviewed) {
              outdatedAlert = `
                <div class="no-print" style="background:#fffbeb; border:1px solid #fef3c7; border-left:4px solid #d97706; padding:14px 18px; border-radius:8px; color:#92400e; display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
                  <div>
                    <strong style="font-size:0.88rem;">New participant responses are available!</strong>
                    <div style="font-size:0.8rem; margin-top:2px;">There are ${data.new_responses_count} new response(s) submitted since the last analysis.</div>
                  </div>
                  <button class="btn btn-warning btn-sm" onclick="runAnalysis()" style="background:#d97706; border-color:#d97706; color:#fff; font-weight:700;">🔄 Update AI Analysis</button>
                </div>
              `;
            }

            const isLimited = (data.responses_count < 3);
            let limitedWarning = '';
            if (isLimited) {
              limitedWarning = `
                <div style="background:#fffbeb; border:1px solid #fef3c7; border-left:4px solid #f59e0b; padding:12px 16px; border-radius:6px; color:#b45309; font-size:0.82rem; margin-bottom:20px;">
                  <strong>Limited Feedback Data:</strong> This analysis is based on only ${data.responses_count} response(s). Findings may not represent the overall participant experience.
                </div>
              `;
            }

            let posHtml = '';
            if (analysis.positive_themes && analysis.positive_themes.length > 0) {
              analysis.positive_themes.forEach((t, i) => {
                posHtml += `
                  <div style="background:#fff; border:1px solid var(--border); border-left:4px solid #16a34a; border-radius:8px; padding:14px 16px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                      <strong style="font-size:0.9rem; color:var(--text-main);">${i+1}. ${escapeHtml(t.theme)}</strong>
                      <span class="badge badge-success" style="font-size:0.7rem; font-weight:700; background:#d1e7dd; color:#10b981;">${t.frequency} responses</span>
                    </div>
                    <p class="text-muted" style="font-size:0.8rem; margin:0; line-height:1.4;">${escapeHtml(t.summary)}</p>
                  </div>
                `;
              });
            } else {
              posHtml = '<div class="text-muted text-sm">No positive themes identified.</div>';
            }

            let impHtml = '';
            if (analysis.improvement_themes && analysis.improvement_themes.length > 0) {
              analysis.improvement_themes.forEach((t, i) => {
                impHtml += `
                  <div style="background:#fff; border:1px solid var(--border); border-left:4px solid #d97706; border-radius:8px; padding:14px 16px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                      <strong style="font-size:0.9rem; color:var(--text-main);">${i+1}. ${escapeHtml(t.theme)}</strong>
                      <span class="badge badge-warning" style="font-size:0.7rem; font-weight:700; background:#fef3c7; color:#d97706;">${t.frequency} responses</span>
                    </div>
                    <p class="text-muted" style="font-size:0.8rem; margin:0; line-height:1.4;">${escapeHtml(t.summary)}</p>
                  </div>
                `;
              });
            } else {
              impHtml = '<div class="text-muted text-sm">No improvement areas identified.</div>';
            }

            let findingsHtml = '';
            if (analysis.key_findings && analysis.key_findings.length > 0) {
              analysis.key_findings.forEach(f => {
                findingsHtml += `<li style="font-size:0.82rem; margin-bottom:8px; line-height:1.4; color:var(--text-main);">🔍 ${escapeHtml(f)}</li>`;
              });
            } else {
              findingsHtml = '<div class="text-muted text-sm">No key findings recorded.</div>';
            }

            let sugHtml = '';
            if (analysis.common_suggestions && analysis.common_suggestions.length > 0) {
              analysis.common_suggestions.forEach(s => {
                sugHtml += `<li style="font-size:0.82rem; margin-bottom:8px; line-height:1.4; color:var(--text-main);">💡 ${escapeHtml(s)}</li>`;
              });
            } else {
              sugHtml = '<div class="text-muted text-sm">No suggestions provided.</div>';
            }

            let recHtml = '';
            if (analysis.recommendations && analysis.recommendations.length > 0) {
              analysis.recommendations.forEach(r => {
                recHtml += `<li style="font-size:0.82rem; margin-bottom:8px; line-height:1.4; color:var(--text-main);">🎯 ${escapeHtml(r)}</li>`;
              });
            } else {
              recHtml = '<div class="text-muted text-sm">No recommendations generated.</div>';
            }

            const toneLabel = analysis.overall_feedback?.label || 'Mostly Positive';
            let toneBadgeClass = 'badge-success';
            if (toneLabel === 'Mixed') toneBadgeClass = 'badge-info';
            if (toneLabel === 'Mostly Negative' || toneLabel === 'Negative') toneBadgeClass = 'badge-danger';

            container.innerHTML = `
              ${outdatedAlert}
              ${limitedWarning}
              
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:8px;">
                <div>
                  <h2 style="font-family:'Plus Jakarta Sans',sans-serif; font-size:1.1rem; font-weight:800; color:var(--text-main); margin:0;">Participant Feedback Analysis</h2>
                  <div class="text-sm text-muted" style="margin-top:2px;">Last analyzed: ${formatDate(data.last_updated)}</div>
                </div>
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                  ${statusBadge}
                  <button class="btn btn-primary btn-sm no-print" onclick="openReviewModal()">✏️ Review & Edit</button>
                  ${!isFinalized ? `<button class="btn btn-success btn-sm no-print" onclick="confirmFinalizeAnalysis()" style="background:#15803d; border-color:#15803d; color:#fff; font-weight:700;">🔒 Finalize Analysis</button>` : ''}
                  ${!isFinalized && !isReviewed ? `<button class="btn btn-outline btn-sm no-print" onclick="runAnalysis()">🔄 Update Analysis</button>` : ''}
                </div>
              </div>

              <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-bottom:20px;">
                <div class="info-tile" style="text-align:center;">
                  <div class="lbl">👥 Responses</div>
                  <div class="val" style="font-size:1.4rem; color:var(--accent);">${data.responses_count}</div>
                </div>
                <div class="info-tile" style="text-align:center;">
                  <div class="lbl">⭐ Average Rating</div>
                  <div class="val" style="font-size:1.4rem; color:var(--accent);">${calculateAverageRating()} / 4.0</div>
                </div>
                <div class="info-tile" style="text-align:center;">
                  <div class="lbl">👍 Overall Tone</div>
                  <div class="val" style="margin-top:6px;">
                    <span class="badge ${toneBadgeClass}" style="font-size:0.85rem; padding:4px 10px;">${toneLabel}</span>
                  </div>
                </div>
                <div class="info-tile" style="text-align:center;">
                  <div class="lbl">💬 Comments Analyzed</div>
                  <div class="val" style="font-size:1.4rem; color:var(--accent);">${calculateCommentsAnalyzed()}</div>
                </div>
              </div>

              <div class="card" style="margin-bottom:20px;">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; padding:12px 20px;">
                  <h3 style="font-size:0.88rem; font-weight:700; margin:0;">Feedback Summary</h3>
                  ${summaryBadge}
                </div>
                <div class="card-body" style="padding:16px 20px;">
                  <p style="font-size:0.88rem; line-height:1.6; margin:0; color:var(--text-main); font-style:italic;">
                    "${escapeHtml(analysis.overall_summary)}"
                  </p>
                  <p class="text-sm text-muted" style="margin-top:8px; font-size:0.75rem;">
                    <strong>Explanation:</strong> ${escapeHtml(analysis.overall_feedback?.explanation || '')}
                  </p>
                </div>
              </div>

              <div class="card" style="margin-bottom:20px;">
                <div class="card-header"><h3 style="font-size:0.88rem; font-weight:700; margin:0;">Evaluation Ratings breakdown</h3></div>
                <div class="card-body" style="padding:16px 20px;">
                  <div style="display:flex; flex-direction:column; gap:12px;">
                    ${renderRatingBars()}
                  </div>
                </div>
              </div>

              <div class="themes-layout" style="margin-bottom:20px; display:flex; gap:20px; align-items:start;">
                <div class="card" style="flex:1;">
                  <div class="card-header" style="border-bottom:2px solid #16a34a;"><h3 style="font-size:0.88rem; font-weight:700; margin:0; color:#16a34a;">🟢 What Participants Liked</h3></div>
                  <div class="card-body" style="padding:16px; display:flex; flex-direction:column; gap:10px;">
                    ${posHtml}
                  </div>
                </div>
                <div class="card" style="flex:1;">
                  <div class="card-header" style="border-bottom:2px solid #d97706;"><h3 style="font-size:0.88rem; font-weight:700; margin:0; color:#d97706;">Area for Improvement</h3></div>
                  <div class="card-body" style="padding:16px; display:flex; flex-direction:column; gap:10px;">
                    ${impHtml}
                  </div>
                </div>
              </div>

              <div class="card" style="margin-bottom:20px;">
                <div class="card-header"><h3 style="font-size:0.88rem; font-weight:700; margin:0;">🔍 Key Findings</h3></div>
                <div class="card-body" style="padding:16px 20px;">
                  <ul style="margin:0; padding-left:0; list-style:none;">
                    ${findingsHtml}
                  </ul>
                </div>
              </div>

              <div class="suggestions-layout" style="margin-bottom:20px; display:flex; gap:20px; align-items:start;">
                <div class="card" style="flex:1;">
                  <div class="card-header"><h3 style="font-size:0.88rem; font-weight:700; margin:0;">💡 Common Suggestions</h3></div>
                  <div class="card-body" style="padding:16px 20px;">
                    <ul style="margin:0; padding-left:0; list-style:none;">
                      ${sugHtml}
                    </ul>
                  </div>
                </div>
                <div class="card" style="flex:1;">
                  <div class="card-header"><h3 style="font-size:0.88rem; font-weight:700; margin:0;">🎯 Recommended Improvements</h3></div>
                  <div class="card-body" style="padding:16px 20px;">
                    <ul style="margin:0; padding-left:0; list-style:none;">
                      ${recHtml}
                    </ul>
                  </div>
                </div>
              </div>
            `;
          }

          function openReviewModal() {
            if (!window.currentAnalysisData || !window.currentAnalysisData.analysis) {
              alert('No analysis loaded to review.');
              return;
            }
            const a = window.currentAnalysisData.analysis;
            
            let posThemesHtml = '';
            (a.positive_themes || []).forEach((t) => {
              posThemesHtml += `
                <div class="theme-edit-row" style="background:var(--bg-base); border:1px solid var(--border); border-radius:6px; padding:10px; margin-bottom:8px;">
                  <div style="display:flex; gap:8px; margin-bottom:6px;">
                    <input type="text" class="form-control form-control-sm pos-theme-name" placeholder="Theme title" value="${escapeHtml(t.theme || '')}" style="font-weight:600; flex:1;">
                    <input type="number" class="form-control form-control-sm pos-theme-freq" placeholder="Freq" value="${t.frequency || 1}" style="width:70px;">
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.theme-edit-row').remove()">✕</button>
                  </div>
                  <textarea class="form-control form-control-sm pos-theme-desc" rows="2" placeholder="Theme summary/details">${escapeHtml(t.summary || '')}</textarea>
                </div>
              `;
            });

            let impThemesHtml = '';
            (a.improvement_themes || []).forEach((t) => {
              impThemesHtml += `
                <div class="theme-edit-row" style="background:var(--bg-base); border:1px solid var(--border); border-radius:6px; padding:10px; margin-bottom:8px;">
                  <div style="display:flex; gap:8px; margin-bottom:6px;">
                    <input type="text" class="form-control form-control-sm imp-theme-name" placeholder="Theme title" value="${escapeHtml(t.theme || '')}" style="font-weight:600; flex:1;">
                    <input type="number" class="form-control form-control-sm imp-theme-freq" placeholder="Freq" value="${t.frequency || 1}" style="width:70px;">
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.theme-edit-row').remove()">✕</button>
                  </div>
                  <textarea class="form-control form-control-sm imp-theme-desc" rows="2" placeholder="Theme summary/details">${escapeHtml(t.summary || '')}</textarea>
                </div>
              `;
            });

            const commonSuggText = (a.common_suggestions || []).join('\n');
            const keyFindingsText = (a.key_findings || []).join('\n');
            const recsText = (a.recommendations || []).join('\n');

            let modalEl = document.getElementById('review-analysis-modal');
            if (!modalEl) {
              modalEl = document.createElement('div');
              modalEl.id = 'review-analysis-modal';
              document.body.appendChild(modalEl);
            }

            modalEl.innerHTML = `
              <div style="position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(15,23,42,0.6); z-index:9999; display:flex; align-items:center; justify-content:center; padding:16px;">
                <div style="background:var(--bg-card); border-radius:12px; border:1px solid var(--border); max-width:850px; width:100%; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);">
                  <div style="padding:16px 24px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
                    <div>
                      <h3 style="font-family:'Plus Jakarta Sans',sans-serif; font-size:1.1rem; font-weight:800; margin:0; color:var(--text-main);">
                        ✏️ Administrator Review & Edit — Feedback Analysis
                      </h3>
                      <p class="text-muted" style="font-size:0.78rem; margin:2px 0 0 0;">
                        Review and adjust AI-extracted feedback themes, suggestions, and recommendations before finalization.
                      </p>
                    </div>
                    <button type="button" onclick="closeReviewModal()" style="border:none; background:none; font-size:1.4rem; cursor:pointer; color:#64748b;">&times;</button>
                  </div>

                  <div style="padding:20px 24px; overflow-y:auto; flex:1; display:flex; flex-direction:column; gap:18px;">
                    <div>
                      <label style="font-weight:700; font-size:0.85rem; display:block; margin-bottom:6px; color:var(--text-main);">Overall Summary</label>
                      <textarea id="edit-summary" class="form-control" rows="3" style="width:100%; border:1px solid var(--border); background:var(--bg-base); color:var(--text-main); border-radius:6px; padding:8px 10px; font-size:0.85rem;">${escapeHtml(a.overall_summary || '')}</textarea>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                      <div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                          <label style="font-weight:700; font-size:0.85rem; color:#16a34a; margin:0;">🟢 Positive Themes</label>
                          <button type="button" class="btn btn-outline btn-sm" onclick="addThemeRow('pos')" style="font-size:0.72rem; padding:2px 8px;">+ Add Theme</button>
                        </div>
                        <div id="pos-themes-container" style="max-height:220px; overflow-y:auto;">
                          ${posThemesHtml}
                        </div>
                      </div>

                      <div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                          <label style="font-weight:700; font-size:0.85rem; color:#d97706; margin:0;">Area for Improvement</label>
                          <button type="button" class="btn btn-outline btn-sm" onclick="addThemeRow('imp')" style="font-size:0.72rem; padding:2px 8px;">+ Add Theme</button>
                        </div>
                        <div id="imp-themes-container" style="max-height:220px; overflow-y:auto;">
                          ${impThemesHtml}
                        </div>
                      </div>
                    </div>

                    <div>
                      <label style="font-weight:700; font-size:0.85rem; display:block; margin-bottom:4px; color:var(--text-main);">🔍 Key Findings <span class="text-muted" style="font-weight:400; font-size:0.75rem;">(One item per line)</span></label>
                      <textarea id="edit-findings" class="form-control" rows="3" style="width:100%; border:1px solid var(--border); background:var(--bg-base); color:var(--text-main); border-radius:6px; padding:8px 10px; font-size:0.85rem;">${escapeHtml(keyFindingsText)}</textarea>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                      <div>
                        <label style="font-weight:700; font-size:0.85rem; display:block; margin-bottom:4px; color:var(--text-main);">💡 Common Suggestions <span class="text-muted" style="font-weight:400; font-size:0.75rem;">(One item per line)</span></label>
                        <textarea id="edit-suggestions" class="form-control" rows="3" style="width:100%; border:1px solid var(--border); background:var(--bg-base); color:var(--text-main); border-radius:6px; padding:8px 10px; font-size:0.85rem;">${escapeHtml(commonSuggText)}</textarea>
                      </div>

                      <div>
                        <label style="font-weight:700; font-size:0.85rem; display:block; margin-bottom:4px; color:var(--text-main);">🎯 Actionable Recommendations <span class="text-muted" style="font-weight:400; font-size:0.75rem;">(One item per line)</span></label>
                        <textarea id="edit-recommendations" class="form-control" rows="3" style="width:100%; border:1px solid var(--border); background:var(--bg-base); color:var(--text-main); border-radius:6px; padding:8px 10px; font-size:0.85rem;">${escapeHtml(recsText)}</textarea>
                      </div>
                    </div>
                  </div>

                  <div style="padding:14px 24px; border-top:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; background:var(--bg-base); border-bottom-left-radius:12px; border-bottom-right-radius:12px;">
                    <button type="button" class="btn btn-outline" onclick="closeReviewModal()">Cancel</button>
                    <div style="display:flex; gap:10px;">
                      <button type="button" class="btn btn-primary" onclick="submitAnalysisReview('edit')" id="btn-save-draft" style="font-weight:700;">💾 Save Reviewed Draft</button>
                      <button type="button" class="btn btn-success" onclick="submitAnalysisReview('finalize')" id="btn-finalize" style="background:#15803d; border-color:#15803d; color:#fff; font-weight:700;">🔒 Finalize Analysis</button>
                    </div>
                  </div>
                </div>
              </div>
            `;
          }

          function addThemeRow(type) {
            const container = document.getElementById(type === 'pos' ? 'pos-themes-container' : 'imp-themes-container');
            if (!container) return;
            const div = document.createElement('div');
            div.className = 'theme-edit-row';
            div.style.cssText = 'background:var(--bg-base); border:1px solid var(--border); border-radius:6px; padding:10px; margin-bottom:8px;';
            const nameClass = type === 'pos' ? 'pos-theme-name' : 'imp-theme-name';
            const freqClass = type === 'pos' ? 'pos-theme-freq' : 'imp-theme-freq';
            const descClass = type === 'pos' ? 'pos-theme-desc' : 'imp-theme-desc';
            div.innerHTML = `
              <div style="display:flex; gap:8px; margin-bottom:6px;">
                <input type="text" class="form-control form-control-sm ${nameClass}" placeholder="Theme title" style="font-weight:600; flex:1;">
                <input type="number" class="form-control form-control-sm ${freqClass}" placeholder="Freq" value="1" style="width:70px;">
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.theme-edit-row').remove()">✕</button>
              </div>
              <textarea class="form-control form-control-sm ${descClass}" rows="2" placeholder="Theme summary/details"></textarea>
            `;
            container.appendChild(div);
          }

          function closeReviewModal() {
            const modalEl = document.getElementById('review-analysis-modal');
            if (modalEl) modalEl.innerHTML = '';
          }

          async function submitAnalysisReview(actionType, useCurrentData = false) {
            let summary = '';
            let posThemes = [];
            let impThemes = [];
            let suggestions = '';
            let findings = '';
            let recs = '';

            if (useCurrentData && window.currentAnalysisData && window.currentAnalysisData.analysis) {
              const a = window.currentAnalysisData.analysis;
              summary = a.overall_summary || '';
              posThemes = a.positive_themes || [];
              impThemes = a.improvement_themes || [];
              suggestions = (a.common_suggestions || []).join('\n');
              findings = (a.key_findings || []).join('\n');
              recs = (a.recommendations || []).join('\n');
            } else {
              const sumEl = document.getElementById('edit-summary');
              if (sumEl) summary = sumEl.value.trim();

              document.querySelectorAll('#pos-themes-container .theme-edit-row').forEach(row => {
                const name = row.querySelector('.pos-theme-name')?.value.trim();
                const freq = parseInt(row.querySelector('.pos-theme-freq')?.value || '1', 10);
                const desc = row.querySelector('.pos-theme-desc')?.value.trim();
                if (name || desc) {
                  posThemes.push({ theme: name || 'Theme', frequency: freq, summary: desc || '' });
                }
              });

              document.querySelectorAll('#imp-themes-container .theme-edit-row').forEach(row => {
                const name = row.querySelector('.imp-theme-name')?.value.trim();
                const freq = parseInt(row.querySelector('.imp-theme-freq')?.value || '1', 10);
                const desc = row.querySelector('.imp-theme-desc')?.value.trim();
                if (name || desc) {
                  impThemes.push({ theme: name || 'Theme', frequency: freq, summary: desc || '' });
                }
              });

              findings = document.getElementById('edit-findings')?.value || '';
              suggestions = document.getElementById('edit-suggestions')?.value || '';
              recs = document.getElementById('edit-recommendations')?.value || '';
            }

            const btnDraft = document.getElementById('btn-save-draft');
            const btnFin = document.getElementById('btn-finalize');
            if (btnDraft) btnDraft.disabled = true;
            if (btnFin) btnFin.disabled = true;

            try {
              const formData = new FormData();
              formData.append('activity_id', '<?= $id ?>');
              formData.append('action', actionType);
              formData.append('summary', summary);
              formData.append('positive_themes', JSON.stringify(posThemes));
              formData.append('improvement_themes', JSON.stringify(impThemes));
              formData.append('key_findings', findings);
              formData.append('common_suggestions', suggestions);
              formData.append('recommendations', recs);

              const res = await fetch('<?= BASE_URL ?>/api/generate-feedback-analysis.php', {
                method: 'POST',
                body: formData
              });

              const data = await res.json();
              if (!res.ok) {
                alert(data.error || 'Failed to update analysis.');
                if (btnDraft) btnDraft.disabled = false;
                if (btnFin) btnFin.disabled = false;
                return;
              }

              closeReviewModal();
              renderAnalysis(data);
            } catch (err) {
              alert('An error occurred while saving analysis.');
              if (btnDraft) btnDraft.disabled = false;
              if (btnFin) btnFin.disabled = false;
            }
          }

          function confirmFinalizeAnalysis() {
            if (confirm('Are you sure you want to finalize this analysis? This records your approval and marks the evaluation analysis as finalized.')) {
              submitAnalysisReview('finalize', true);
            }
          }

          function escapeHtml(text) {
            if (!text) return '';
            return text
              .replace(/&/g, "&amp;")
              .replace(/</g, "&lt;")
              .replace(/>/g, "&gt;")
              .replace(/"/g, "&quot;")
              .replace(/'/g, "&#039;");
          }

          function formatDate(dateStr) {
            if (!dateStr) return '—';
            const d = new Date(dateStr);
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
          }
        </script>
    <?php endif; ?>

    <!-- Print-only block of AI Feedback Summary (appends to bottom of printed Overview tab if analysis exists) -->
    <?php
    $printAnalysisStmt = $db->prepare("SELECT * FROM activity_feedback_analysis WHERE activity_id = ?");
    $printAnalysisStmt->execute([$id]);
    $printAnalysis = $printAnalysisStmt->fetch(PDO::FETCH_ASSOC);
    if ($printAnalysis):
      $pa = json_decode($printAnalysis['analysis_json'], true);
      $paIsFinalized = !empty($pa['is_finalized']);
    ?>
    <div class="print-only print-analysis-section" style="display:none; margin-top:30px; border-top:2px solid #333; padding-top:20px; page-break-before:always;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; border-bottom:1px solid #ddd; padding-bottom:8px;">
        <h2 style="font-family:'Plus Jakarta Sans',sans-serif; font-size:1.2rem; font-weight:800; margin:0;">
          <?= $paIsFinalized ? '✅ Administrator Finalized Feedback Analysis Report' : '🤖 AI Participant Feedback Analysis Report' ?>
        </h2>
        <?php if ($paIsFinalized): ?>
          <span style="font-size:0.8rem; color:#15803d; font-weight:700;">
            🔒 FINALIZED by <?= htmlspecialchars($pa['finalized_by']['name'] ?? 'Administrator') ?> (<?= htmlspecialchars($pa['finalized_by']['role'] ?? 'Dean') ?>) on <?= htmlspecialchars($pa['finalized_at'] ?? '') ?>
          </span>
        <?php else: ?>
          <span style="font-size:0.8rem; color:#64748b; font-weight:600;">
            🤖 UNREVIEWED AI DRAFT
          </span>
        <?php endif; ?>
      </div>
      <p style="font-size:0.9rem; line-height:1.5; font-style:italic; margin-bottom:15px;">
        "<?= htmlspecialchars($pa['overall_summary'] ?? '') ?>"
      </p>

      <?php if (!empty($pa['key_findings'])): ?>
      <div style="margin-bottom:15px;">
        <h3 style="font-size:0.9rem; font-weight:700; margin-bottom:8px;">🔍 Key Findings</h3>
        <ul style="padding-left:16px; margin:0; font-size:0.82rem; line-height:1.4;">
          <?php foreach($pa['key_findings'] as $kf): ?>
            <li><?= htmlspecialchars($kf) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
      
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:15px;">
        <div>
          <h3 style="font-size:0.9rem; font-weight:700; color:#16a34a; margin-bottom:8px;">🟢 Positive Themes</h3>
          <?php foreach(($pa['positive_themes'] ?? []) as $i => $t): ?>
            <div style="margin-bottom:8px; font-size:0.82rem;">
              <strong><?= $i+1 ?>. <?= htmlspecialchars($t['theme']) ?></strong> (<?= $t['frequency'] ?> responses)<br>
              <span style="color:#555;"><?= htmlspecialchars($t['summary']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <div>
          <h3 style="font-size:0.9rem; font-weight:700; color:#d97706; margin-bottom:8px;">Area for Improvement</h3>
          <?php foreach(($pa['improvement_themes'] ?? []) as $i => $t): ?>
            <div style="margin-bottom:8px; font-size:0.82rem;">
              <strong><?= $i+1 ?>. <?= htmlspecialchars($t['theme']) ?></strong> (<?= $t['frequency'] ?> responses)<br>
              <span style="color:#555;"><?= htmlspecialchars($t['summary']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
        <div>
          <h3 style="font-size:0.9rem; font-weight:700; margin-bottom:8px;">💡 Suggestions</h3>
          <ul style="padding-left:16px; margin:0; font-size:0.82rem; line-height:1.4;">
            <?php foreach(($pa['common_suggestions'] ?? []) as $s): ?>
              <li><?= htmlspecialchars($s) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <div>
          <h3 style="font-size:0.9rem; font-weight:700; margin-bottom:8px;">🎯 Actionable Recommendations</h3>
          <ul style="padding-left:16px; margin:0; font-size:0.82rem; line-height:1.4;">
            <?php foreach(($pa['recommendations'] ?? []) as $r): ?>
              <li><?= htmlspecialchars($r) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
    <style>
      @media print {
        .print-only {
          display: block !important;
        }
        .themes-layout, .suggestions-layout {
          display: block !important;
        }
        .themes-layout > .card, .suggestions-layout > .card {
          margin-bottom: 15px;
        }
      }
    </style>
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
</body>
</html>

