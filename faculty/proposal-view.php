<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/QrCodeService.php';
requireRole('faculty', 'program_head');
$user = currentUser();
$db   = getDB();
$id   = (int)($_GET['id'] ?? 0);

$act = $db->prepare("SELECT a.*,u.name as faculty_name FROM activities a JOIN users u ON a.faculty_id=u.id WHERE a.id=?");
$act->execute([$id]);
$activity = $act->fetch();
if (!$activity) {
  header('Location: ' . BASE_URL . '/faculty/activities.php');
  exit;
}
if (!canUserAccessActivity($id, 'view', $user['id'], $user['role'], $user['dept'])) {
  http_response_code(403);
  die('Unauthorized access: You do not have permission to view this proposal.');
}

// Collaborative Proposal Data (Task 14)
$hasCollab = hasCollaboratorsTable($db);
$collaborators = $hasCollab ? getProposalCollaborators($id, $db) : [];
$contributionLogs = $hasCollab ? getProposalContributionLogs($id, 25, $db) : [];
$canEditProposal = in_array($activity['status'], ['draft', 'returned_for_revision']) && canUserAccessActivity($id, 'modify', $user['id'], $user['role'], $user['dept']);

$mats = $db->prepare("SELECT * FROM materials WHERE activity_id=?");
$mats->execute([$id]);
$materials = $mats->fetchAll();
$prog = $db->prepare("SELECT * FROM program_sequence WHERE activity_id=? ORDER BY sort_order");
$prog->execute([$id]);
$program = $prog->fetchAll();
$mp   = $db->prepare("SELECT * FROM manpower WHERE activity_id=?");
$mp->execute([$id]);
$manpower = $mp->fetchAll();
$sc   = $db->prepare("SELECT * FROM schedules WHERE activity_id=?");
$sc->execute([$id]);
$schedules = $sc->fetchAll();
$gl   = $db->prepare("SELECT * FROM guidelines WHERE activity_id=?");
$gl->execute([$id]);
$guidelines = $gl->fetch();

$hasTasks = hasFacultyTasksTable($db);
$ftasks = [];
if ($hasTasks) {
  try {
    $ft = $db->prepare("SELECT ft.*, u.name as assigned_user_name FROM faculty_tasks ft LEFT JOIN users u ON ft.assigned_user_id = u.id WHERE ft.activity_id=? ORDER BY ft.id ASC");
    $ft->execute([$id]);
    $ftasks = $ft->fetchAll();
  } catch (\Throwable $e) {
    $ftasks = [];
  }
}

$fp   = $db->prepare("SELECT * FROM floor_plans WHERE activity_id=?");
$fp->execute([$id]);
$floorplan = $fp->fetch();
$kpiStmt = $db->prepare("SELECT * FROM kpi_evaluations WHERE activity_id=?");
$kpiStmt->execute([$id]);
$kpis = $kpiStmt->fetchAll();

$logs = $db->prepare("SELECT al.*,u.name FROM approval_logs al JOIN users u ON al.reviewer_id=u.id WHERE al.activity_id=? ORDER BY al.acted_at DESC");
$logs->execute([$id]);
$history = $logs->fetchAll();

// Prepare structured proposal data for client-side jsPDF export
$proposalPdfData = [
  'id' => (int)$activity['id'],
  'title' => $activity['title'] ?? 'Untitled Proposal',
  'status' => ($activity['status'] === 'submitted') ? 'Under Review' : ucfirst(str_replace('_', ' ', $activity['status'] ?? 'draft')),
  'proponent' => $activity['faculty_name'] ?? 'Faculty Proponent',
  'source' => ucfirst(str_replace('_', ' ', $activity['source'] ?? '')),
  'event_date' => $activity['event_date'] ? date('F j, Y', strtotime($activity['event_date'])) : '—',
  'time' => (($activity['start_time'] ?? '—') . ' – ' . ($activity['end_time'] ?? '—')),
  'venue' => $activity['venue'] ?? '—',
  'theme' => $activity['theme'] ?? '—',
  'target_participants' => number_format((int)($activity['target_participants'] ?? 0)) . ' participants',
  'revision_notes' => $activity['revision_notes'] ?? '',
  'general_objectives' => $activity['general_objectives'] ?? '',
  'specific_objectives' => $activity['specific_objectives'] ?? '',
  'involved_subjects' => $activity['involved_subjects'] ?? '',
  'rationale' => $activity['rationale'] ?? '',
  'materials' => array_map(function ($m) {
    return [
      $m['item_name'] ?? '',
      $m['description'] ?? '—',
      (string)($m['quantity'] ?? 0),
      $m['provider'] ?? '—',
      'PHP ' . number_format((float)($m['est_cost'] ?? 0), 2)
    ];
  }, $materials),
  'manpower' => array_map(function ($m) {
    return [
      $m['role'] ?? '',
      $m['assigned_person'] ?? '—',
      ucfirst($m['type'] ?? '')
    ];
  }, $manpower),
  'schedules' => array_map(function ($s) {
    return [
      $s['sched_date'] ?? '',
      $s['event_name'] ?? '',
      $s['venue'] ?? '—',
      $s['organizer'] ?? '—'
    ];
  }, $schedules),
  'program' => array_map(function ($p) {
    return [
      $p['time_slot'] ?? '',
      $p['segment'] ?? '',
      $p['description'] ?? '—',
      $p['person_ic'] ?? '—'
    ];
  }, $program),
  'guidelines' => array_filter([
    'Mechanics' => $guidelines['mechanics'] ?? '',
    'Criteria for Judging' => $guidelines['criteria'] ?? '',
    'Scoring System' => $guidelines['scoring_system'] ?? '',
    'Special Awards' => $guidelines['special_awards'] ?? ''
  ]),
  'tasks' => array_map(function ($f) {
    return [
      $f['task_title'] ?: ($f['assigned_task'] ?: 'Untitled Task'),
      $f['committee'] ?: '—',
      $f['assigned_user_name'] ?: ($f['faculty_name'] ?: '—'),
      $f['role_in_event'] ?: '—',
      !empty($f['due_date']) ? date('M j, Y', strtotime($f['due_date'])) : '—',
      $f['status'] ?? 'Not Started'
    ];
  }, $ftasks),
  'kpis' => array_map(function ($k) {
    return [
      $k['criteria'] ?? '',
      $k['target_value'] ?? ($k['target_metric'] ?? '—'),
      $k['evaluation_method'] ?? '—'
    ];
  }, $kpis),
  'history' => array_map(function ($h) {
    return [
      $h['name'] ?? 'Reviewer',
      ucfirst(str_replace('_', ' ', $h['action'] ?? '')),
      $h['notes'] ?? '—',
      date('M j, Y g:i A', strtotime($h['acted_at']))
    ];
  }, $history)
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
<?php include __DIR__ . '/../includes/head-theme-init.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>View Proposal – STI</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
  <!-- jsPDF & autoTable CDN for Client-Side PDF Export -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
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
    /* ── Consistent Typography: Plus Jakarta Sans Only ── */
    body,
    h1,
    h2,
    h3,
    h4,
    h5,
    h6,
    .page-title,
    .card-header h2,
    .card-header h3,
    .btn,
    .badge,
    .topbar-user-name,
    .topbar-user-role,
    .info-tile,
    .detail-table,
    label,
    input,
    button,
    select,
    textarea {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }

    /* ── Content & Container Spacing ── */
    .content {
      padding: var(--content-padding-y, 28px) var(--content-padding-x, 32px);
      box-sizing: border-box;
    }

    /* ── Cards Design Token Integration ── */
    .card {
      background: var(--bg-card);
      border-radius: 14px;
      border: 1px solid var(--border);
      box-shadow: var(--shadow-sm);
      margin-bottom: 20px;
      overflow: hidden;
    }

    .card-header {
      padding: 14px 20px;
      border-bottom: 1px solid var(--border);
      background: var(--bg-card);
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
    }

    .card-header h2 {
      font-size: 1.02rem;
      font-weight: 700;
      color: var(--text-main);
      margin: 0;
      letter-spacing: -0.01em;
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    .card-body {
      padding: 20px;
    }

    .sub-section-title {
      font-size: 0.9rem;
      font-weight: 700;
      color: var(--text-main);
      padding: 12px 20px 8px;
      margin: 0;
      background: var(--bg-card);
      border-bottom: 1px solid var(--border);
    }

    /* ── Information Grid & Tiles ── */
    .info-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 12px;
    }

    .info-tile {
      background: var(--bg-base);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 12px 16px;
      transition: border-color .15s ease, transform .15s ease;
    }

    .info-tile:hover {
      border-color: var(--accent, var(--sti-blue));
    }

    .info-tile .lbl {
      font-size: 0.68rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--text-muted);
      font-weight: 700;
      margin-bottom: 4px;
    }

    .info-tile .val {
      font-size: 0.92rem;
      font-weight: 600;
      color: var(--text-main);
      line-height: 1.4;
      word-break: break-word;
    }

    /* ── Objective Blocks ── */
    .obj-block {
      background: var(--bg-base);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 14px 16px;
      margin-bottom: 12px;
    }

    .obj-block:last-child {
      margin-bottom: 0;
    }

    .obj-block .obj-lbl {
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--text-muted);
      font-weight: 700;
      margin-bottom: 6px;
    }

    .obj-block .obj-val {
      font-size: 0.88rem;
      color: var(--text-main);
      line-height: 1.6;
    }

    /* ── Tables ── */
    .table-wrap {
      width: 100%;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }

    .detail-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.85rem;
      text-align: left;
    }

    .detail-table th {
      background: var(--bg-card);
      padding: 10px 16px;
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--text-muted);
      border-bottom: 1px solid var(--border);
      white-space: nowrap;
    }

    [data-theme="dark"] .detail-table th {
      background: rgba(255, 255, 255, 0.03);
    }

    .detail-table td {
      padding: 11px 16px;
      border-bottom: 1px solid var(--border);
      vertical-align: middle;
      color: var(--text-main);
      font-size: 0.85rem;
      transition: background 0.12s ease;
    }

    .detail-table tbody tr:last-child td {
      border-bottom: none;
    }

    .detail-table tbody tr:hover td {
      background: rgba(2, 132, 199, 0.03);
    }

    [data-theme="dark"] .detail-table tbody tr:hover td {
      background: rgba(255, 255, 255, 0.03);
    }

    /* ── Buttons & Badges ── */
    .btn-sm {
      padding: 5px 12px;
      height: 28px;
      font-size: 0.75rem;
      font-weight: 600;
      border-radius: 7px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      text-decoration: none;
      transition: all 0.15s ease;
      white-space: nowrap;
      box-sizing: border-box;
      line-height: 1;
    }

    .btn-primary {
      background: var(--accent, var(--sti-blue));
      border: 1px solid var(--accent, var(--sti-blue));
      color: #fff;
    }

    .btn-primary:hover {
      filter: brightness(1.08);
      box-shadow: 0 2px 8px rgba(2, 132, 199, 0.25);
    }

    .btn-outline {
      border: 1px solid var(--border);
      color: var(--text-secondary);
      background: transparent;
    }

    .btn-outline:hover {
      border-color: var(--accent, var(--sti-blue));
      color: var(--accent, var(--sti-blue));
      background: rgba(2, 132, 199, 0.06);
    }

    .time-badge {
      background: var(--bg-base);
      border: 1px solid var(--border);
      padding: 3px 8px;
      border-radius: 6px;
      font-size: 0.75rem;
      font-weight: 600;
      color: var(--text-main);
      display: inline-block;
      white-space: nowrap;
    }

    .floor-badge {
      background: rgba(2, 132, 199, 0.12);
      color: var(--accent, var(--sti-blue));
      border: 1px solid rgba(2, 132, 199, 0.25);
      font-size: 0.75rem;
      font-weight: 600;
      padding: 3px 8px;
      border-radius: 6px;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }

    /* ── Free-Standing Topbar Icons (No Permanent Box) ── */
    #themeToggleBtn,
    #notifBellBtn,
    .theme-toggle-btn,
    .notif-bell-btn {
      width: 38px;
      height: 38px;
      min-width: 38px;
      min-height: 38px;
      border-radius: 50%;
      background: transparent !important;
      border: none !important;
      box-shadow: none !important;
      color: var(--text-muted);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: background .18s ease, color .18s ease, transform .15s ease;
      position: relative;
      outline: none;
      padding: 0;
      box-sizing: border-box;
      line-height: 1;
    }

    #themeToggleBtn:hover,
    #notifBellBtn:hover,
    .theme-toggle-btn:hover,
    .notif-bell-btn:hover {
      background: var(--border-light) !important;
      color: var(--text-main);
      border: none !important;
      box-shadow: none !important;
      transform: translateY(-1px);
    }

    #themeToggleBtn:active,
    #notifBellBtn:active,
    .theme-toggle-btn:active,
    .notif-bell-btn:active {
      transform: translateY(0) scale(0.92);
      box-shadow: none !important;
    }

    #themeToggleBtn:focus-visible,
    #notifBellBtn:focus-visible,
    .theme-toggle-btn:focus-visible,
    .notif-bell-btn:focus-visible {
      outline: 2px solid var(--sti-blue);
      outline-offset: 2px;
      border-radius: 50%;
    }

    /* ── Dark Mode Contrast Polishing ── */
    [data-theme="dark"] .obj-block {
      background: rgba(255, 255, 255, 0.02);
    }

    [data-theme="dark"] .info-tile {
      background: rgba(255, 255, 255, 0.02);
    }

    [data-theme="dark"] .time-badge {
      background: rgba(255, 255, 255, 0.04);
    }

    /* Print styles */
    @media print {

      .no-print,
      .sidebar,
      .topbar {
        display: none !important;
      }

      .main-wrap {
        margin-left: 0 !important;
        width: 100% !important;
      }

      .content {
        padding: 0 !important;
      }

      .evaluation-qr-section {
        page-break-inside: avoid;
        break-inside: avoid;
        display: flex !important;
      }

      .qr-container {
        border: 1px solid #000 !important;
        box-shadow: none !important;
      }

      .card {
        box-shadow: none !important;
        border: 1px solid #ccc !important;
      }
    }

    /* Dedicated Evaluation QR Flyer Print Window Styles (used when printing flyer) */
    @page qr-flyer-page {
      size: A4 portrait;
      margin: 15mm;
    }
    @media print {
      body.print-qr-flyer-active * {
        visibility: hidden !important;
      }
      body.print-qr-flyer-active #qr-flyer-print-container,
      body.print-qr-flyer-active #qr-flyer-print-container * {
        visibility: visible !important;
      }
      body.print-qr-flyer-active #qr-flyer-print-container {
        position: fixed !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        height: 100% !important;
        margin: 0 !important;
        padding: 24px !important;
        box-sizing: border-box !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: center !important;
        background: #ffffff !important;
        page-break-inside: avoid !important;
      }
    }
  </style>
</head>

<body class="theme-faculty">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <!-- Topbar -->
  <header class="topbar">
    <div class="page-title">View Proposal</div>
    <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
      <a href="<?= BASE_URL ?>/faculty/activities.php" class="btn btn-outline btn-sm">← Back</a>
      <button type="button" id="exportPdfBtn" class="btn btn-outline btn-sm" onclick="exportProposalPdf()" style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;">
        📄 Export PDF
      </button>
      <?php if ($canEditProposal): ?>
      <a href="<?= BASE_URL ?>/faculty/proposal-edit.php?id=<?= $id ?>" class="btn btn-primary btn-sm">Edit Proposal</a>
      <?php endif; ?>
      <?php if ($activity['status'] === 'draft' && $canEditProposal): ?>
      <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteDraft(<?= (int)$id ?>, '<?= htmlspecialchars(addslashes($activity['title']), ENT_QUOTES) ?>')">Delete Draft</button>
      <?php endif; ?>
      <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>

  <div class="content">
    <?php if (!empty($activity['revision_notes'])): ?>
      <?php
        $isRejected = ($activity['status'] === 'rejected');
        $bannerHeading = $isRejected ? 'Rejection Reason' : 'Revision Notes';
        $bannerIcon = $isRejected ? '🚫' : '⚠️';
        $alertClass = $isRejected ? 'alert-danger' : 'alert-warning';
        $borderColor = $isRejected ? 'rgba(239, 68, 68, 0.3)' : 'rgba(245, 158, 11, 0.3)';
        $bgColor = $isRejected ? 'rgba(239, 68, 68, 0.08)' : 'rgba(245, 158, 11, 0.08)';
        $headingColor = $isRejected ? 'var(--danger, #EF4444)' : 'var(--warning, #F59E0B)';

        $displayNote = $activity['revision_notes'];
        if ($isRejected) {
          if (!preg_match('/^Disapproved:\s*/i', $displayNote)) {
            $displayNote = 'Disapproved: ' . $displayNote;
          }
        } else {
          if (preg_match('/^Disapproved:\s*/i', $displayNote)) {
            $displayNote = preg_replace('/^Disapproved:\s*/i', '', $displayNote);
          }
        }
      ?>
      <div class="alert <?= $alertClass ?>" style="margin-bottom: 20px; border-radius: 12px; padding: 14px 18px; border: 1px solid <?= $borderColor ?>; background: <?= $bgColor ?>; display: flex; align-items: flex-start; gap: 10px;">
        <span style="font-size: 1.1rem; line-height: 1;"><?= $bannerIcon ?></span>
        <div>
          <strong style="color: <?= $headingColor ?>; display: block; margin-bottom: 2px;"><?= $bannerHeading ?></strong>
          <span style="color: var(--text-main); font-size: 0.88rem;"><?= htmlspecialchars($displayNote) ?></span>
        </div>
      </div>
    <?php endif; ?>

      <!-- Main Activity Information -->
      <div class="card">
        <div class="card-header">
          <h2>
            <span><?= htmlspecialchars($activity['title']) ?></span>
            <?= getStatusBadge($activity['status']) ?>
          </h2>
          <div>
            <button type="button" class="btn btn-outline btn-sm" onclick="exportProposalPdf()" style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;">
              📄 Export PDF
            </button>
          </div>
        </div>
        <div class="card-body">
          <div class="info-grid">
            <?php foreach (
              [
                'Date' => $activity['event_date'] ? date('F j, Y', strtotime($activity['event_date'])) : '—',
                'Time' => ($activity['start_time'] ?? '—') . ' – ' . ($activity['end_time'] ?? '—'),
                'Venue' => $activity['venue'] ?? '—',
                'Theme' => $activity['theme'] ?? '—',
                'Source' => ucfirst(str_replace('_', ' ', $activity['source'])),
                'Target' => number_format($activity['target_participants']) . ' participants',
              ] as $label => $val
            ): ?>
              <div class="info-tile">
                <div class="lbl"><?= $label ?></div>
                <div class="val"><?= htmlspecialchars($val) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Objectives & Academic Alignment -->
      <div class="card">
        <div class="card-header">
          <h2>Objectives & Academic Alignment</h2>
        </div>
        <div class="card-body">
          <div class="obj-block">
            <div class="obj-lbl">General Objectives</div>
            <div class="obj-val"><?= nl2br(htmlspecialchars($activity['general_objectives'] ?? '—')) ?></div>
          </div>
          <div class="obj-block">
            <div class="obj-lbl">Specific Objectives</div>
            <div class="obj-val"><?= nl2br(htmlspecialchars($activity['specific_objectives'] ?? '—')) ?></div>
          </div>
          <?php if (!empty($activity['involved_subjects'])): ?>
            <div class="obj-block">
              <div class="obj-lbl">Involved Subjects</div>
              <div class="obj-val"><?= htmlspecialchars($activity['involved_subjects']) ?></div>
            </div>
          <?php endif; ?>
          <?php if (!empty($activity['rationale'])): ?>
            <div class="obj-block">
              <div class="obj-lbl">Rationale</div>
              <div class="obj-val"><?= nl2br(htmlspecialchars($activity['rationale'])) ?></div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Materials Needed -->
      <?php if (!empty($materials)): ?>
        <div class="card">
          <div class="card-header">
            <h2>Materials Needed</h2>
            <span class="badge badge-secondary"><?= count($materials) ?> item<?= count($materials) > 1 ? 's' : '' ?></span>
          </div>
          <div class="card-body" style="padding:0;">
            <div class="table-wrap">
              <table class="detail-table">
                <thead>
                  <tr>
                    <th>Item</th>
                    <th>Description</th>
                    <th style="text-align:center;">Qty</th>
                    <th>Provider</th>
                    <th style="text-align:right;">Cost</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($materials as $m): ?>
                    <tr>
                      <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($m['item_name']) ?></td>
                      <td style="color:var(--text-muted);"><?= htmlspecialchars($m['description'] ?? '—') ?></td>
                      <td style="text-align:center; font-weight:600;"><?= $m['quantity'] ?></td>
                      <td><?= htmlspecialchars($m['provider'] ?? '—') ?></td>
                      <td style="text-align:right; font-weight:600; color:var(--text-main);">₱<?= number_format($m['est_cost'], 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- Event Poster -->
      <div class="card">
        <div class="card-header">
          <h2>Event Poster</h2>
        </div>
        <div class="card-body" style="text-align:center; padding: 24px; background: var(--bg-base);">
          <?php if (!empty($activity['poster_path'])): ?>
            <?php
            $posterUrl = str_starts_with($activity['poster_path'], 'http')
              ? $activity['poster_path']
              : BASE_URL . '/api/document-download.php?activity_id=' . (int)$activity['id'] . '&type=poster';
            ?>
            <a href="<?= htmlspecialchars($posterUrl) ?>" target="_blank" style="display:inline-block;">
              <img src="<?= htmlspecialchars($posterUrl) ?>" alt="Event Poster" style="max-width: 100%; max-height: 500px; border-radius: 10px; border: 1px solid var(--border); box-shadow: var(--shadow-sm);">
            </a>
            <div style="margin-top: 14px;">
              <a href="<?= htmlspecialchars($posterUrl) ?>" target="_blank" class="btn btn-outline btn-sm">🖼️ View Full Poster Image</a>
            </div>
          <?php else: ?>
            <p class="text-muted" style="margin: 0;">No event poster uploaded for this proposal.</p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Floor Plan -->
      <?php if ($floorplan): ?>
        <div class="card">
          <div class="card-header">
            <h2>Floor Plan</h2>
          </div>
          <div class="card-body">
            <?php
            $floorsList = [];
            if (!empty($floorplan['canvas_json'])) {
              $parsedFp = json_decode($floorplan['canvas_json'], true);
              if ($parsedFp && !empty($parsedFp['floors'])) {
                foreach ($parsedFp['floors'] as $fKey => $fVal) {
                  $floorsList[] = $fVal['name'] ?? ucfirst($fKey) . ' Floor';
                }
              }
            }
            if (empty($floorsList) && (!empty($floorplan['file_path']) || !empty($floorplan['canvas_json']))) {
              $floorsList = ['Ground Floor'];
            }
            ?>
            <?php if (!empty($floorsList)): ?>
              <div style="margin-bottom:14px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <span style="font-size:0.8rem; font-weight:600; color:var(--text-muted);">Included Floors:</span>
                <?php foreach ($floorsList as $flName): ?>
                  <span class="floor-badge">🏢 <?= htmlspecialchars($flName) ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if (!empty($floorplan['file_path'])): ?>
              <?php
              $fpUrl = str_starts_with($floorplan['file_path'], 'http')
                ? $floorplan['file_path']
                : BASE_URL . '/api/document-download.php?activity_id=' . (int)$activity['id'] . '&type=floorplan';
              $ext = strtolower(pathinfo($floorplan['file_path'], PATHINFO_EXTENSION));
              $isImg = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp']);
              ?>
              <?php if ($isImg): ?>
                <div style="margin-bottom:14px; text-align:center; padding:16px; background:var(--bg-base); border-radius:10px; border:1px solid var(--border);">
                  <img src="<?= htmlspecialchars($fpUrl) ?>" style="max-width:100%; max-height:480px; border-radius:8px; object-fit:contain;" alt="Floor Plan">
                </div>
              <?php endif; ?>
              <a href="<?= htmlspecialchars($fpUrl) ?>" target="_blank" class="btn btn-outline btn-sm">📎 View Full Floor Plan Image</a>
            <?php endif; ?>
            <?php if (!empty($floorplan['notes'])): ?>
              <div class="obj-block" style="margin-top:14px;">
                <div class="obj-lbl">Notes</div>
                <div class="obj-val"><?= nl2br(htmlspecialchars($floorplan['notes'])) ?></div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- People / Manpower -->
      <?php if (!empty($manpower)): ?>
        <div class="card">
          <div class="card-header">
            <h2>People / Manpower</h2>
            <span class="badge badge-secondary"><?= count($manpower) ?> assigned</span>
          </div>
          <div class="card-body" style="padding:0;">
            <div class="table-wrap">
              <table class="detail-table">
                <thead>
                  <tr>
                    <th>Role</th>
                    <th>Assigned Person</th>
                    <th>Type</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($manpower as $m): ?>
                    <tr>
                      <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($m['role']) ?></td>
                      <td><?= htmlspecialchars($m['assigned_person'] ?? '—') ?></td>
                      <td><span class="badge badge-secondary"><?= ucfirst($m['type']) ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- Schedule & Program Flow -->
      <?php if (!empty($schedules) || !empty($program)): ?>
        <div class="card">
          <div class="card-header">
            <h2>Schedule & Program</h2>
          </div>
          <div class="card-body" style="padding:0;">
            <?php if (!empty($schedules)): ?>
              <div class="sub-section-title">Schedule of Activities</div>
              <div class="table-wrap">
                <table class="detail-table">
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Event</th>
                      <th>Venue</th>
                      <th>Organizer</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($schedules as $s): ?>
                      <tr>
                        <td><span class="time-badge"><?= htmlspecialchars($s['sched_date']) ?></span></td>
                        <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($s['event_name']) ?></td>
                        <td><?= htmlspecialchars($s['venue'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($s['organizer'] ?? '—') ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <?php if (!empty($program)): ?>
              <div class="sub-section-title" style="<?= !empty($schedules) ? 'border-top: 1px solid var(--border);' : '' ?>">Program Flow</div>
              <div class="table-wrap">
                <table class="detail-table">
                  <thead>
                    <tr>
                      <th style="width:18%;">Time</th>
                      <th style="width:25%;">Segment</th>
                      <th style="width:35%;">Description</th>
                      <th style="width:22%;">Person In-Charge</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($program as $p): ?>
                      <tr>
                        <td><span class="time-badge">⏱ <?= htmlspecialchars($p['time_slot']) ?></span></td>
                        <td><strong style="color:var(--text-main); font-weight:600;"><?= htmlspecialchars($p['segment']) ?></strong></td>
                        <td style="color:var(--text-muted);"><?= htmlspecialchars($p['description'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($p['person_ic'] ?? '—') ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Guidelines -->
      <?php if ($guidelines): ?>
        <div class="card">
          <div class="card-header">
            <h2>Guidelines</h2>
          </div>
          <div class="card-body">
            <div class="info-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">
              <?php foreach (
                [
                  'Mechanics' => $guidelines['mechanics'],
                  'Criteria for Judging' => $guidelines['criteria'],
                  'Scoring System' => $guidelines['scoring_system'],
                  'Special Awards' => $guidelines['special_awards']
                ] as $l => $v
              ): if (!$v) continue; ?>
                <div class="obj-block" style="margin-bottom:0;">
                  <div class="obj-lbl"><?= $l ?></div>
                  <div class="obj-val"><?= nl2br(htmlspecialchars($v)) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- Task & Role Assignments -->
      <div class="card">
        <div class="card-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
          <h2>Task & Role Assignments</h2>
          <div style="display: flex; align-items: center; gap: 8px;">
            <span class="badge badge-secondary"><?= count($ftasks) ?> assigned</span>
            <a href="<?= BASE_URL ?>/faculty/view-activity.php?id=<?= $id ?>" class="btn btn-outline btn-sm">Manage Assignments →</a>
          </div>
        </div>
        <div class="card-body" style="padding:0;">
          <?php if (empty($ftasks)): ?>
            <div style="padding: 24px; text-align: center; color: var(--text-muted); font-size: 0.85rem;">
              No task assignments created yet for this activity.
            </div>
          <?php else: ?>
            <div class="table-wrap">
              <table class="detail-table">
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
                  <?php foreach ($ftasks as $f):
                    $tTitle = $f['task_title'] ?: $f['assigned_task'] ?: 'Untitled Task';
                    $tDesc = $f['task_description'] ?: $f['contribution_desc'] ?: '';
                    $tCommittee = $f['committee'] ?: '—';
                    $tPerson = $f['assigned_user_name'] ?: $f['faculty_name'] ?: '—';
                    $tRole = $f['role_in_event'] ?: '—';
                    $tDue = !empty($f['due_date']) ? date('M j, Y', strtotime($f['due_date'])) : '—';
                    $tStatus = in_array($f['status'], ['Not Started', 'In Progress', 'Completed', 'Delayed']) ? $f['status'] : 'Not Started';
                  ?>
                    <tr>
                      <td>
                        <div style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($tTitle) ?></div>
                        <?php if (!empty($tDesc)): ?>
                          <small style="color:var(--text-muted); display: block; margin-top: 2px;"><?= nl2br(htmlspecialchars($tDesc)) ?></small>
                        <?php endif; ?>
                      </td>
                      <td><?= $tCommittee !== '—' ? '<span class="badge badge-secondary">' . htmlspecialchars($tCommittee) . '</span>' : '—' ?></td>
                      <td><strong><?= htmlspecialchars($tPerson) ?></strong></td>
                      <td><?= htmlspecialchars($tRole) ?></td>
                      <td><?= $tDue !== '—' ? '<span class="time-badge">📅 ' . htmlspecialchars($tDue) . '</span>' : '—' ?></td>
                      <td><?= getTaskStatusBadge($tStatus) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- KPI & Evaluation -->
      <div class="card">
        <div class="card-header">
          <h2>KPI & Evaluation</h2>
        </div>
        <div class="card-body" style="padding:0;">
          <?php if (!empty($kpis)): ?>
            <div class="table-wrap">
              <table class="detail-table">
                <thead>
                  <tr>
                    <th>Indicator</th>
                    <th>Target Metric</th>
                    <th>Evaluation Method</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($kpis as $k): ?>
                    <tr>
                      <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($k['indicator']) ?></td>
                      <td><?= htmlspecialchars($k['target_metric'] ?? '—') ?></td>
                      <td><?= htmlspecialchars($k['evaluation_method'] ?? '—') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
          <div style="padding:16px 20px; font-size:0.88rem; color:var(--text-main); border-top: <?= !empty($kpis) ? '1px solid var(--border)' : 'none' ?>;">
            <strong>Google Form Link:</strong>
            <?php if (!empty($activity['evaluation_method'])): ?>
              <a href="<?= htmlspecialchars($activity['evaluation_method']) ?>" target="_blank" style="color:var(--accent, var(--sti-blue)); text-decoration:none; font-weight:600; word-break:break-all;"><?= htmlspecialchars($activity['evaluation_method']) ?></a>
            <?php else: ?>
              <span style="color:var(--text-muted);">—</span>
            <?php endif; ?>
          </div>
          <?php if (QrCodeService::isValidUrl($activity['evaluation_method'] ?? '')): ?>
            <div class="evaluation-qr-section" style="padding: 16px 20px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
              <div class="qr-container" style="background: #ffffff; padding: 10px; border: 1px solid var(--border); border-radius: 10px; box-shadow: var(--shadow-sm); display: inline-block;">
                <?= QrCodeService::generateSvg($activity['evaluation_method'], 150) ?>
              </div>
              <div class="qr-info" style="flex: 1; min-width: 220px;">
                <div style="font-size: 0.95rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px;">
                  Evaluation Questionnaire QR Code
                </div>
                <p style="font-size: 0.825rem; color: var(--text-muted); margin: 0 0 12px 0; line-height: 1.4;">
                  Scan this QR code with any mobile device to immediately open the Google Form evaluation questionnaire.
                </p>
                <div class="no-print">
                  <button type="button" class="btn btn-outline btn-sm" onclick="printEvaluationFlyer()" style="cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                    🖨️ Print QR Code Flyer
                  </button>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- AI Evaluation Questions -->
      <?php if (!empty($activity['evaluation_questions'])): ?>
        <div class="card">
          <div class="card-header">
            <h2>📋 AI-Generated Evaluation Tool</h2>
          </div>
          <div class="card-body">
            <div style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 14px; line-height: 1.4;">
              These evaluation questions are automatically generated based on the activity objectives and KPIs. Reviewers can scan them below.
            </div>
            <?= renderAiEvaluationQuestions($activity['evaluation_questions']) ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- AI Proposal Validator Section -->
      <?php if ($activity['status'] !== 'draft'): ?>
        <?php
        require_once __DIR__ . '/../includes/ai/proposal_validator.php';
        renderAiValidationSection($id, false);
        ?>
      <?php endif; ?>

      <!-- Approval History -->
      <?php if (!empty($history)): ?>
        <div class="card">
          <div class="card-header">
            <h2>Approval History</h2>
            <span class="badge badge-secondary"><?= count($history) ?> log<?= count($history) > 1 ? 's' : '' ?></span>
          </div>
          <div class="card-body" style="padding:0;">
            <div class="table-wrap">
              <table class="detail-table">
                <thead>
                  <tr>
                    <th>Reviewer</th>
                    <th>Action</th>
                    <th>Notes</th>
                    <th>Date</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($history as $h): ?>
                    <tr>
                      <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($h['name']) ?></td>
                      <td><?= getStatusBadge($h['action']) ?></td>
                      <td style="color:var(--text-muted); font-size:0.85rem; font-style:italic;"><?= htmlspecialchars($h['notes'] ?? '—') ?></td>
                      <td style="white-space:nowrap; font-size:0.82rem; color:var(--text-muted);"><?= date('M j, Y g:i A', strtotime($h['acted_at'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php endif; ?>

    <!-- Proposal Collaborators & Contribution History (Task 14) -->
    <div class="card">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h2>👥 Proposal Collaborators &amp; Contribution History</h2>
        <span class="badge badge-secondary"><?= count($collaborators) ?> Member<?= count($collaborators) > 1 ? 's' : '' ?></span>
      </div>
      <div class="card-body">
        <h4 style="font-size:0.95rem; font-weight:700; margin-bottom:12px; color:var(--text-main);">Assigned Collaborators</h4>
        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap:12px; margin-bottom:24px;">
          <?php foreach ($collaborators as $collab): ?>
            <?php
              $bgCol = $collab['role'] === 'owner' ? 'rgba(2, 132, 199, 0.08)' : ($collab['role'] === 'co_author' ? 'rgba(16, 185, 129, 0.08)' : 'rgba(100, 116, 139, 0.08)');
              $borderCol = $collab['role'] === 'owner' ? '#38BDF8' : ($collab['role'] === 'co_author' ? '#6EE7B7' : 'var(--border)');
              $roleIcon = $collab['role'] === 'owner' ? '👑' : ($collab['role'] === 'co_author' ? '✍️' : '👤');
            ?>
            <div style="border:1px solid <?= $borderCol ?>; background:<?= $bgCol ?>; padding:12px 14px; border-radius:8px;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                <span style="font-weight:700; font-size:0.9rem; color:var(--text-main);">
                  <?= $roleIcon ?> <?= htmlspecialchars($collab['name']) ?>
                </span>
              </div>
              <div style="font-size:0.8rem; color:var(--text-muted); margin-bottom:4px;">
                <strong>Role:</strong> <?= htmlspecialchars(formatCollaboratorRole($collab['role'])) ?>
              </div>
              <div style="font-size:0.8rem; color:var(--text-muted);">
                <strong>Dept:</strong> <?= htmlspecialchars($collab['department'] ?? '—') ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <h4 style="font-size:0.95rem; font-weight:700; margin-bottom:12px; color:var(--text-main);">Recorded Contribution History</h4>
        <?php if (!empty($contributionLogs)): ?>
          <div class="table-wrap">
            <table class="detail-table">
              <thead>
                <tr>
                  <th>Date &amp; Time</th>
                  <th>Faculty Contributor</th>
                  <th>Action</th>
                  <th>Section</th>
                  <th>Details</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($contributionLogs as $log): ?>
                  <tr>
                    <td style="white-space:nowrap; font-size:0.82rem; color:var(--text-muted);">
                      <?= date('M j, Y g:i A', strtotime($log['created_at'])) ?>
                    </td>
                    <td style="font-weight:600; color:var(--text-main);">
                      <?= htmlspecialchars($log['user_name'] ?? 'Faculty') ?>
                      <span style="font-size:0.75rem; color:var(--text-muted); display:block; font-weight:normal;">
                        (<?= htmlspecialchars($log['role_label'] ?? formatCollaboratorRole($log['user_role'] ?? 'contributor')) ?>)
                      </span>
                    </td>
                    <td style="font-weight:500; font-size:0.85rem;">
                      <?= htmlspecialchars(ucwords(str_replace('_', ' ', $log['action']))) ?>
                    </td>
                    <td style="color:var(--text-muted); font-size:0.85rem;">
                      <?= htmlspecialchars($log['section'] ?? 'General') ?>
                    </td>
                    <td style="font-size:0.82rem; color:var(--text-muted);">
                      <?= htmlspecialchars($log['details'] ?? '—') ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p style="font-size:0.85rem; color:var(--text-muted); font-style:italic; margin:0;">
            No contribution logs recorded yet for this proposal.
          </p>
        <?php endif; ?>
      </div>
    </div>

    </div><!-- content -->
  </div><!-- main-wrap -->

  <!-- Client-Side jsPDF Proposal Exporter -->
  <script>
    const PROPOSAL_PDF_DATA = <?= json_encode($proposalPdfData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    function exportProposalPdf() {
      if (typeof window.jspdf === 'undefined' || !window.jspdf.jsPDF) {
        alert('PDF export library is still loading or unavailable. Please check your internet connection and try again.');
        return;
      }

      const {
        jsPDF
      } = window.jspdf;
      const doc = new jsPDF({
        orientation: 'portrait',
        unit: 'mm',
        format: 'a4'
      });

      const pageWidth = 210;
      const pageHeight = 297;
      const marginX = 14;
      const contentWidth = pageWidth - (marginX * 2); // 182mm
      let currentY = 16;

      function ensureSpace(neededHeight) {
        if (currentY + neededHeight > pageHeight - 18) {
          doc.addPage();
          currentY = 18;
        }
      }

      function addSectionHeading(title) {
        ensureSpace(14);
        currentY += 3;
        doc.setFillColor(241, 245, 249);
        doc.roundedRect(marginX, currentY - 4.5, contentWidth, 8, 1, 1, 'F');
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(9.5);
        doc.setTextColor(0, 56, 118); // STI Blue
        doc.text(title, marginX + 3, currentY + 1);
        currentY += 7;
        doc.setTextColor(30, 41, 59);
        doc.setFont('helvetica', 'normal');
      }

      function addFieldBlock(label, val) {
        if (!val || !val.trim() || val.trim() === '—') return;
        ensureSpace(11);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(8.5);
        doc.setTextColor(71, 85, 105);
        doc.text(label, marginX, currentY);
        currentY += 3.8;

        doc.setFont('helvetica', 'normal');
        doc.setFontSize(8.5);
        doc.setTextColor(30, 41, 59);
        const splitLines = doc.splitTextToSize(val, contentWidth);
        for (let i = 0; i < splitLines.length; i++) {
          ensureSpace(4.5);
          doc.text(splitLines[i], marginX, currentY);
          currentY += 4.2;
        }
        currentY += 1.5;
      }

      // --- HEADER BANNER ---
      doc.setFillColor(0, 56, 118);
      doc.rect(marginX, currentY - 2, contentWidth, 2, 'F');
      currentY += 4;

      doc.setFont('helvetica', 'bold');
      doc.setFontSize(8);
      doc.setTextColor(0, 56, 118);
      doc.text('STI ACTIVITY & RESOURCE MANAGEMENT SYSTEM (STI-ARMES)', marginX, currentY);

      currentY += 6;
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(15);
      doc.setTextColor(15, 23, 42);
      const titleLines = doc.splitTextToSize(PROPOSAL_PDF_DATA.title || 'Activity Proposal', contentWidth - 45);
      for (let i = 0; i < titleLines.length; i++) {
        doc.text(titleLines[i], marginX, currentY);
        currentY += 6;
      }

      // Status Badge on top right
      doc.setFontSize(9);
      doc.setTextColor(71, 85, 105);
      const statusStr = 'Status: ' + (PROPOSAL_PDF_DATA.status || 'Draft');
      doc.text(statusStr, pageWidth - marginX, currentY - (titleLines.length * 6) + 4, {
        align: 'right'
      });

      // Thin separator
      doc.setDrawColor(226, 232, 240);
      doc.setLineWidth(0.4);
      doc.line(marginX, currentY, pageWidth - marginX, currentY);
      currentY += 4;

      // --- REVISION NOTES / REJECTION REASON IF PRESENT ---
      if (PROPOSAL_PDF_DATA.revision_notes && PROPOSAL_PDF_DATA.revision_notes.trim()) {
        ensureSpace(14);
        const isRejected = (PROPOSAL_PDF_DATA.status && PROPOSAL_PDF_DATA.status.toLowerCase() === 'rejected');
        const boxFill = isRejected ? [254, 242, 242] : [254, 243, 199];
        const boxStroke = isRejected ? [239, 68, 68] : [245, 158, 11];
        const textColor = isRejected ? [185, 28, 28] : [180, 83, 9];
        const labelHeading = isRejected ? 'Rejection Reason: ' : 'Revision Notes: ';

        let pdfNote = PROPOSAL_PDF_DATA.revision_notes;
        if (isRejected) {
          if (!pdfNote.toLowerCase().startsWith('disapproved:')) {
            pdfNote = 'Disapproved: ' + pdfNote;
          }
        } else {
          pdfNote = pdfNote.replace(/^disapproved:\s*/i, '');
        }

        doc.setFillColor(boxFill[0], boxFill[1], boxFill[2]);
        doc.setDrawColor(boxStroke[0], boxStroke[1], boxStroke[2]);
        const revLines = doc.splitTextToSize(labelHeading + pdfNote, contentWidth - 6);
        const boxH = (revLines.length * 4) + 6;
        doc.roundedRect(marginX, currentY, contentWidth, boxH, 1.5, 1.5, 'FD');
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(8);
        doc.setTextColor(textColor[0], textColor[1], textColor[2]);
        for (let i = 0; i < revLines.length; i++) {
          doc.text(revLines[i], marginX + 3, currentY + 4 + (i * 4));
        }
        currentY += boxH + 4;
      }

      // --- BASIC INFORMATION GRID ---
      addSectionHeading('Activity Overview');
      const basicInfoBody = [
        [{
            content: 'Event Date:',
            styles: {
              fontStyle: 'bold',
              textColor: [71, 85, 105],
              cellWidth: 26
            }
          },
          {
            content: PROPOSAL_PDF_DATA.event_date || '—',
            styles: {
              cellWidth: 65
            }
          },
          {
            content: 'Proponent:',
            styles: {
              fontStyle: 'bold',
              textColor: [71, 85, 105],
              cellWidth: 26
            }
          },
          {
            content: PROPOSAL_PDF_DATA.proponent || '—',
            styles: {
              cellWidth: 65
            }
          }
        ],
        [{
            content: 'Time Slot:',
            styles: {
              fontStyle: 'bold',
              textColor: [71, 85, 105]
            }
          },
          {
            content: PROPOSAL_PDF_DATA.time || '—'
          },
          {
            content: 'Source:',
            styles: {
              fontStyle: 'bold',
              textColor: [71, 85, 105]
            }
          },
          {
            content: PROPOSAL_PDF_DATA.source || '—'
          }
        ],
        [{
            content: 'Venue:',
            styles: {
              fontStyle: 'bold',
              textColor: [71, 85, 105]
            }
          },
          {
            content: PROPOSAL_PDF_DATA.venue || '—'
          },
          {
            content: 'Participants:',
            styles: {
              fontStyle: 'bold',
              textColor: [71, 85, 105]
            }
          },
          {
            content: PROPOSAL_PDF_DATA.target_participants || '—'
          }
        ],
        [{
            content: 'Theme:',
            styles: {
              fontStyle: 'bold',
              textColor: [71, 85, 105]
            }
          },
          {
            content: PROPOSAL_PDF_DATA.theme || '—',
            colSpan: 3
          }
        ]
      ];

      doc.autoTable({
        startY: currentY,
        margin: {
          left: marginX,
          right: marginX
        },
        body: basicInfoBody,
        theme: 'plain',
        styles: {
          fontSize: 8.5,
          cellPadding: 1.8,
          textColor: [30, 41, 59]
        },
        columnStyles: {
          0: {
            cellWidth: 25
          },
          1: {
            cellWidth: 66
          },
          2: {
            cellWidth: 25
          },
          3: {
            cellWidth: 66
          }
        }
      });
      currentY = doc.lastAutoTable.finalY + 4;

      // --- OBJECTIVES & ALIGNMENT ---
      if (PROPOSAL_PDF_DATA.general_objectives || PROPOSAL_PDF_DATA.specific_objectives || PROPOSAL_PDF_DATA.rationale) {
        addSectionHeading('Objectives & Academic Alignment');
        addFieldBlock('General Objectives:', PROPOSAL_PDF_DATA.general_objectives);
        addFieldBlock('Specific Objectives:', PROPOSAL_PDF_DATA.specific_objectives);
        addFieldBlock('Involved Subjects:', PROPOSAL_PDF_DATA.involved_subjects);
        addFieldBlock('Rationale:', PROPOSAL_PDF_DATA.rationale);
      }

      // --- GUIDELINES ---
      if (PROPOSAL_PDF_DATA.guidelines && Object.keys(PROPOSAL_PDF_DATA.guidelines).length > 0) {
        addSectionHeading('Event Guidelines');
        for (const [gLabel, gVal] of Object.entries(PROPOSAL_PDF_DATA.guidelines)) {
          addFieldBlock(gLabel + ':', gVal);
        }
      }

      // --- SCHEDULE OF ACTIVITIES ---
      if (PROPOSAL_PDF_DATA.schedules && PROPOSAL_PDF_DATA.schedules.length > 0) {
        addSectionHeading('Schedule of Activities');
        doc.autoTable({
          startY: currentY,
          margin: {
            left: marginX,
            right: marginX
          },
          head: [
            ['Date', 'Event Name', 'Venue', 'Organizer']
          ],
          body: PROPOSAL_PDF_DATA.schedules,
          theme: 'grid',
          styles: {
            fontSize: 8,
            cellPadding: 2,
            overflow: 'linebreak',
            textColor: [30, 41, 59]
          },
          headStyles: {
            fillColor: [0, 56, 118],
            textColor: 255,
            fontStyle: 'bold'
          },
          alternateRowStyles: {
            fillColor: [248, 250, 252]
          },
          columnStyles: {
            0: {
              cellWidth: 32
            },
            1: {
              cellWidth: 65
            },
            2: {
              cellWidth: 45
            },
            3: {
              cellWidth: 40
            }
          }
        });
        currentY = doc.lastAutoTable.finalY + 4;
      }

      // --- PROGRAM SEQUENCE ---
      if (PROPOSAL_PDF_DATA.program && PROPOSAL_PDF_DATA.program.length > 0) {
        addSectionHeading('Program Flow & Sequence');
        doc.autoTable({
          startY: currentY,
          margin: {
            left: marginX,
            right: marginX
          },
          head: [
            ['Time Slot', 'Segment', 'Description', 'Person In-Charge']
          ],
          body: PROPOSAL_PDF_DATA.program,
          theme: 'grid',
          styles: {
            fontSize: 8,
            cellPadding: 2,
            overflow: 'linebreak',
            textColor: [30, 41, 59]
          },
          headStyles: {
            fillColor: [0, 56, 118],
            textColor: 255,
            fontStyle: 'bold'
          },
          alternateRowStyles: {
            fillColor: [248, 250, 252]
          },
          columnStyles: {
            0: {
              cellWidth: 32
            },
            1: {
              cellWidth: 45
            },
            2: {
              cellWidth: 65
            },
            3: {
              cellWidth: 40
            }
          }
        });
        currentY = doc.lastAutoTable.finalY + 4;
      }

      // --- COMMITTEE TASKS & ASSIGNMENTS ---
      if (PROPOSAL_PDF_DATA.tasks && PROPOSAL_PDF_DATA.tasks.length > 0) {
        addSectionHeading('Committee Tasks & Role Assignments');
        doc.autoTable({
          startY: currentY,
          margin: {
            left: marginX,
            right: marginX
          },
          head: [
            ['Task Title', 'Committee', 'Assigned Member', 'Role in Event', 'Target Date', 'Status']
          ],
          body: PROPOSAL_PDF_DATA.tasks,
          theme: 'grid',
          styles: {
            fontSize: 8,
            cellPadding: 2,
            overflow: 'linebreak',
            textColor: [30, 41, 59]
          },
          headStyles: {
            fillColor: [0, 56, 118],
            textColor: 255,
            fontStyle: 'bold'
          },
          alternateRowStyles: {
            fillColor: [248, 250, 252]
          },
          columnStyles: {
            0: {
              cellWidth: 47
            },
            1: {
              cellWidth: 35
            },
            2: {
              cellWidth: 35
            },
            3: {
              cellWidth: 25
            },
            4: {
              cellWidth: 22
            },
            5: {
              cellWidth: 18
            }
          }
        });
        currentY = doc.lastAutoTable.finalY + 4;
      }

      // --- MANPOWER ---
      if (PROPOSAL_PDF_DATA.manpower && PROPOSAL_PDF_DATA.manpower.length > 0) {
        addSectionHeading('Manpower Allocation');
        doc.autoTable({
          startY: currentY,
          margin: {
            left: marginX,
            right: marginX
          },
          head: [
            ['Role / Responsibility', 'Assigned Person', 'Type']
          ],
          body: PROPOSAL_PDF_DATA.manpower,
          theme: 'grid',
          styles: {
            fontSize: 8,
            cellPadding: 2,
            overflow: 'linebreak',
            textColor: [30, 41, 59]
          },
          headStyles: {
            fillColor: [0, 56, 118],
            textColor: 255,
            fontStyle: 'bold'
          },
          alternateRowStyles: {
            fillColor: [248, 250, 252]
          },
          columnStyles: {
            0: {
              cellWidth: 72
            },
            1: {
              cellWidth: 70
            },
            2: {
              cellWidth: 40
            }
          }
        });
        currentY = doc.lastAutoTable.finalY + 4;
      }

      // --- MATERIALS NEEDED ---
      if (PROPOSAL_PDF_DATA.materials && PROPOSAL_PDF_DATA.materials.length > 0) {
        addSectionHeading('Materials & Resources Needed');
        doc.autoTable({
          startY: currentY,
          margin: {
            left: marginX,
            right: marginX
          },
          head: [
            ['Item Name', 'Description', 'Qty', 'Provider', 'Est. Cost']
          ],
          body: PROPOSAL_PDF_DATA.materials,
          theme: 'grid',
          styles: {
            fontSize: 8,
            cellPadding: 2,
            overflow: 'linebreak',
            textColor: [30, 41, 59]
          },
          headStyles: {
            fillColor: [0, 56, 118],
            textColor: 255,
            fontStyle: 'bold'
          },
          alternateRowStyles: {
            fillColor: [248, 250, 252]
          },
          columnStyles: {
            0: {
              cellWidth: 42
            },
            1: {
              cellWidth: 60
            },
            2: {
              cellWidth: 15,
              halign: 'center'
            },
            3: {
              cellWidth: 35
            },
            4: {
              cellWidth: 30,
              halign: 'right'
            }
          }
        });
        currentY = doc.lastAutoTable.finalY + 4;
      }

      // --- KPI & EVALUATION ---
      if (PROPOSAL_PDF_DATA.kpis && PROPOSAL_PDF_DATA.kpis.length > 0) {
        addSectionHeading('KPI & Evaluation Alignment');
        doc.autoTable({
          startY: currentY,
          margin: {
            left: marginX,
            right: marginX
          },
          head: [
            ['Indicator / Criteria', 'Target Metric', 'Evaluation Method']
          ],
          body: PROPOSAL_PDF_DATA.kpis,
          theme: 'grid',
          styles: {
            fontSize: 8,
            cellPadding: 2,
            overflow: 'linebreak',
            textColor: [30, 41, 59]
          },
          headStyles: {
            fillColor: [0, 56, 118],
            textColor: 255,
            fontStyle: 'bold'
          },
          alternateRowStyles: {
            fillColor: [248, 250, 252]
          },
          columnStyles: {
            0: {
              cellWidth: 70
            },
            1: {
              cellWidth: 50
            },
            2: {
              cellWidth: 62
            }
          }
        });
        currentY = doc.lastAutoTable.finalY + 4;
      }

      // --- APPROVAL HISTORY ---
      if (PROPOSAL_PDF_DATA.history && PROPOSAL_PDF_DATA.history.length > 0) {
        addSectionHeading('Approval & Review History');
        doc.autoTable({
          startY: currentY,
          margin: {
            left: marginX,
            right: marginX
          },
          head: [
            ['Reviewer', 'Action Taken', 'Remarks / Notes', 'Date & Time']
          ],
          body: PROPOSAL_PDF_DATA.history,
          theme: 'grid',
          styles: {
            fontSize: 8,
            cellPadding: 2,
            overflow: 'linebreak',
            textColor: [30, 41, 59]
          },
          headStyles: {
            fillColor: [0, 56, 118],
            textColor: 255,
            fontStyle: 'bold'
          },
          alternateRowStyles: {
            fillColor: [248, 250, 252]
          },
          columnStyles: {
            0: {
              cellWidth: 40
            },
            1: {
              cellWidth: 32
            },
            2: {
              cellWidth: 68
            },
            3: {
              cellWidth: 42
            }
          }
        });
        currentY = doc.lastAutoTable.finalY + 4;
      }

      // --- FOOTERS ON ALL PAGES ---
      const totalPages = doc.internal.getNumberOfPages();
      const exportTimestamp = new Date().toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
      });
      for (let p = 1; p <= totalPages; p++) {
        doc.setPage(p);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(7.5);
        doc.setTextColor(148, 163, 184);
        doc.setDrawColor(226, 232, 240);
        doc.setLineWidth(0.3);
        doc.line(marginX, pageHeight - 12, pageWidth - marginX, pageHeight - 12);
        doc.text('STI Activity & Resource Management System (STI-ARMES) — Official Proposal Export', marginX, pageHeight - 8);
        doc.text('Page ' + p + ' of ' + totalPages + ' (' + exportTimestamp + ')', pageWidth - marginX, pageHeight - 8, {
          align: 'right'
        });
      }

      // --- SANITIZE FILENAME & DOWNLOAD ---
      let safeTitle = (PROPOSAL_PDF_DATA.title || '')
        .trim()
        .replace(/[^a-zA-Z0-9_\-\s]/g, '')
        .replace(/\s+/g, '_')
        .substring(0, 50);

      if (!safeTitle) {
        safeTitle = 'Proposal_' + (PROPOSAL_PDF_DATA.id || 'Export');
      }
      const filename = safeTitle + '_Proposal.pdf';

      doc.save(filename);
    }

    /**
     * Dedicated Single-Page Participant Evaluation QR Flyer Print
     * Generates a clean, standalone printable handout for event participants
     * without printing the 10-page proposal document.
     */
    function printEvaluationFlyer() {
      const qrContainer = document.querySelector('.evaluation-qr-section .qr-container');
      const qrSvg = qrContainer ? qrContainer.innerHTML.trim() : '';
      const title = <?= json_encode($activity['title'] ?? 'Activity Evaluation') ?>;
      const eventDate = <?= json_encode(!empty($activity['start_date']) ? date('F j, Y', strtotime($activity['start_date'])) : 'TBA') ?>;
      const venue = <?= json_encode($activity['venue'] ?? 'STI College Marikina') ?>;
      const evalUrl = <?= json_encode($activity['evaluation_method'] ?? '') ?>;
      const dept = <?= json_encode($activity['department'] ?? 'STI College') ?>;

      if (!qrSvg) {
        alert('QR code is not available for printing.');
        return;
      }

      // Open a clean, self-contained popup print window
      const printWin = window.open('', '_blank', 'width=800,height=900,menubar=no,toolbar=no,location=no,status=no');
      if (!printWin) {
        // Fallback to in-page isolation print if popups are blocked
        printEvaluationFlyerInPage(title, eventDate, venue, evalUrl, dept, qrSvg);
        return;
      }

      const flyerHtml = `
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Evaluation QR Flyer - ${escapeHtml(title)}</title>
  <style>
    @page {
      size: A4 portrait;
      margin: 15mm;
    }
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      -webkit-print-color-adjust: exact !important;
      print-color-adjust: exact !important;
    }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      background: #ffffff;
      color: #0f172a;
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      padding: 20px;
    }
    .flyer-card {
      width: 100%;
      max-width: 620px;
      border: 2px solid #0284c7;
      border-radius: 16px;
      padding: 36px 32px;
      text-align: center;
      background: #ffffff;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
    }
    .brand-header {
      border-bottom: 2px solid #e2e8f0;
      padding-bottom: 18px;
      margin-bottom: 24px;
    }
    .brand-title {
      font-size: 24px;
      font-weight: 800;
      color: #0284c7;
      letter-spacing: -0.5px;
    }
    .brand-sub {
      font-size: 13px;
      font-weight: 600;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      margin-top: 4px;
    }
    .flyer-badge {
      display: inline-block;
      background: #e0f2fe;
      color: #0369a1;
      font-size: 12px;
      font-weight: 700;
      padding: 4px 12px;
      border-radius: 20px;
      margin-bottom: 16px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .activity-title {
      font-size: 22px;
      font-weight: 700;
      color: #0f172a;
      line-height: 1.3;
      margin-bottom: 12px;
    }
    .meta-box {
      display: flex;
      justify-content: center;
      gap: 24px;
      font-size: 13px;
      color: #475569;
      margin-bottom: 24px;
      flex-wrap: wrap;
    }
    .meta-item {
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .qr-frame {
      display: inline-block;
      background: #ffffff;
      padding: 16px;
      border: 3px solid #0f172a;
      border-radius: 16px;
      margin-bottom: 20px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
    }
    .qr-frame svg {
      display: block;
      width: 230px !important;
      height: 230px !important;
    }
    .instructions-title {
      font-size: 16px;
      font-weight: 700;
      color: #0f172a;
      margin-bottom: 6px;
    }
    .instructions-text {
      font-size: 13px;
      color: #64748b;
      line-height: 1.5;
      max-width: 440px;
      margin: 0 auto 16px auto;
    }
    .url-fallback {
      font-size: 11px;
      color: #94a3b8;
      word-break: break-all;
      border-top: 1px dashed #cbd5e1;
      padding-top: 14px;
    }
    .url-fallback a {
      color: #0284c7;
      text-decoration: none;
      font-weight: 600;
    }
    @media print {
      body {
        padding: 0;
        background: transparent;
      }
      .flyer-card {
        border-color: #0284c7 !important;
        box-shadow: none !important;
      }
    }
  </style>
</head>
<body>
  <div class="flyer-card">
    <div class="brand-header">
      <div class="brand-title">STI COLLEGE MARIKINA</div>
      <div class="brand-sub">Activity &amp; Resource Management System (STI-ARMES)</div>
    </div>
    <span class="flyer-badge">Participant Evaluation Questionnaire</span>
    <h1 class="activity-title">${escapeHtml(title)}</h1>
    <div class="meta-box">
      <div class="meta-item">📅 <strong>Date:</strong> ${escapeHtml(eventDate)}</div>
      <div class="meta-item">📍 <strong>Venue:</strong> ${escapeHtml(venue)}</div>
      <div class="meta-item">🏛️ <strong>Dept:</strong> ${escapeHtml(dept)}</div>
    </div>
    <div class="qr-frame">
      ${qrSvg}
    </div>
    <div class="instructions-title">Scan QR Code to Evaluate</div>
    <p class="instructions-text">
      Open your smartphone camera or any QR scanner app and point it at the QR code above to immediately access the online evaluation form.
    </p>
    <div class="url-fallback">
      Direct Link: <a href="${escapeHtml(evalUrl)}">${escapeHtml(evalUrl)}</a>
    </div>
  </div>
  <script>
    window.addEventListener('load', function() {
      setTimeout(function() {
        window.focus();
        window.print();
      }, 250);
    });
  <\/script>
</body>
</html>
      `;

      printWin.document.open();
      printWin.document.write(flyerHtml);
      printWin.document.close();
    }

    function printEvaluationFlyerInPage(title, eventDate, venue, evalUrl, dept, qrSvg) {
      let printContainer = document.getElementById('qr-flyer-print-container');
      if (!printContainer) {
        printContainer = document.createElement('div');
        printContainer.id = 'qr-flyer-print-container';
        document.body.appendChild(printContainer);
      }

      printContainer.innerHTML = `
        <div style="border: 2px solid #0284c7; border-radius: 16px; padding: 36px 32px; text-align: center; max-width: 600px; width: 100%; margin: auto; background: #ffffff;">
          <div style="font-size: 22px; font-weight: 800; color: #0284c7; margin-bottom: 4px;">STI COLLEGE MARIKINA</div>
          <div style="font-size: 12px; font-weight: 600; color: #64748b; letter-spacing: 0.8px; text-transform: uppercase; margin-bottom: 16px;">Activity &amp; Resource Management System</div>
          <div style="display: inline-block; background: #e0f2fe; color: #0369a1; font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 20px; margin-bottom: 14px;">PARTICIPANT EVALUATION</div>
          <div style="font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 12px;">${escapeHtml(title)}</div>
          <div style="font-size: 12px; color: #475569; margin-bottom: 20px;">📅 ${escapeHtml(eventDate)} &nbsp;|&nbsp; 📍 ${escapeHtml(venue)}</div>
          <div style="display: inline-block; padding: 12px; border: 2px solid #000; border-radius: 12px; margin-bottom: 16px;">
            ${qrSvg}
          </div>
          <div style="font-size: 15px; font-weight: 700; color: #0f172a; margin-bottom: 6px;">Scan to Complete Evaluation</div>
          <div style="font-size: 12px; color: #64748b; max-width: 440px; margin: 0 auto 12px auto;">Scan this code with your phone camera to open the Google Form questionnaire.</div>
          <div style="font-size: 10px; color: #94a3b8; word-break: break-all;">Link: ${escapeHtml(evalUrl)}</div>
        </div>
      `;

      document.body.classList.add('print-qr-flyer-active');
      window.print();
      setTimeout(function() {
        document.body.classList.remove('print-qr-flyer-active');
      }, 500);
    }

    function escapeHtml(str) {
      if (!str) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }
  </script>
</body>

</html>