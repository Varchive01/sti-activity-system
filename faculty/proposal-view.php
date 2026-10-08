<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/QrCodeService.php';
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
$ft   = $db->prepare("SELECT ft.*, u.name as assigned_user_name FROM faculty_tasks ft LEFT JOIN users u ON ft.assigned_user_id = u.id WHERE ft.activity_id=? ORDER BY ft.id ASC"); $ft->execute([$id]); $ftasks=$ft->fetchAll();
$fp   = $db->prepare("SELECT * FROM floor_plans WHERE activity_id=?"); $fp->execute([$id]); $floorplan=$fp->fetch();
$kpiStmt = $db->prepare("SELECT * FROM kpi_evaluations WHERE activity_id=?"); $kpiStmt->execute([$id]); $kpis=$kpiStmt->fetchAll();

$logs = $db->prepare("SELECT al.*,u.name FROM approval_logs al JOIN users u ON al.reviewer_id=u.id WHERE al.activity_id=? ORDER BY al.acted_at DESC"); $logs->execute([$id]); $history=$logs->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>View Proposal – STI</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
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
    h1, h2, h3, h4, h5, h6,
    .page-title,
    .card-header h2,
    .card-header h3,
    .btn,
    .badge,
    .topbar-user-name,
    .topbar-user-role,
    .info-tile,
    .detail-table,
    label, input, button, select, textarea {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }

    /* ── Content & Container Spacing ── */
    .content {
      padding: 24px;
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
      .no-print, .sidebar, .topbar { display: none !important; }
      .main-wrap { margin-left: 0 !important; width: 100% !important; }
      .content { padding: 0 !important; }
      .evaluation-qr-section { page-break-inside: avoid; break-inside: avoid; display: flex !important; }
      .qr-container { border: 1px solid #000 !important; box-shadow: none !important; }
      .card { box-shadow: none !important; border: 1px solid #ccc !important; }
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
      <?php if (in_array($activity['status'],['draft','returned_for_revision'])): ?>
      <a href="<?= BASE_URL ?>/faculty/proposal-edit.php?id=<?= $id ?>" class="btn btn-primary btn-sm">Edit Proposal</a>
      <?php endif; ?>
      <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>

  <div class="content">
    <?php if ($activity['revision_notes']): ?>
    <div class="alert alert-warning" style="margin-bottom: 20px; border-radius: 12px; padding: 14px 18px; border: 1px solid rgba(245, 158, 11, 0.3); background: rgba(245, 158, 11, 0.08); display: flex; align-items: flex-start; gap: 10px;">
      <span style="font-size: 1.1rem; line-height: 1;">⚠️</span>
      <div>
        <strong style="color: var(--warning, #F59E0B); display: block; margin-bottom: 2px;">Revision Notes</strong>
        <span style="color: var(--text-main); font-size: 0.88rem;"><?= htmlspecialchars($activity['revision_notes']) ?></span>
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
      </div>
      <div class="card-body">
        <div class="info-grid">
          <?php foreach([
            'Date'=>$activity['event_date'] ? date('F j, Y',strtotime($activity['event_date'])) : '—',
            'Time'=>($activity['start_time']??'—') . ' – ' . ($activity['end_time']??'—'),
            'Venue'=>$activity['venue']??'—',
            'Theme'=>$activity['theme']??'—',
            'Source'=>ucfirst(str_replace('_',' ',$activity['source'])),
            'Target'=>number_format($activity['target_participants']).' participants',
          ] as $label=>$val): ?>
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
          <div class="obj-val"><?= nl2br(htmlspecialchars($activity['general_objectives']??'—')) ?></div>
        </div>
        <div class="obj-block">
          <div class="obj-lbl">Specific Objectives</div>
          <div class="obj-val"><?= nl2br(htmlspecialchars($activity['specific_objectives']??'—')) ?></div>
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
        <span class="badge badge-secondary"><?= count($materials) ?> item<?= count($materials)>1?'s':'' ?></span>
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
              <?php foreach($materials as $m): ?>
              <tr>
                <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($m['item_name']) ?></td>
                <td style="color:var(--text-muted);"><?= htmlspecialchars($m['description']??'—') ?></td>
                <td style="text-align:center; font-weight:600;"><?= $m['quantity'] ?></td>
                <td><?= htmlspecialchars($m['provider']??'—') ?></td>
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
    <?php if (!empty($activity['poster_path'])): ?>
    <?php $posterUrl = str_starts_with($activity['poster_path'], 'http') ? $activity['poster_path'] : BASE_URL . '/' . $activity['poster_path']; ?>
    <div class="card">
      <div class="card-header">
        <h2>Event Poster</h2>
      </div>
      <div class="card-body" style="text-align:center; padding: 24px; background: var(--bg-base);">
        <a href="<?= htmlspecialchars($posterUrl) ?>" target="_blank" style="display:inline-block;">
          <img src="<?= htmlspecialchars($posterUrl) ?>" alt="Event Poster" style="max-width: 100%; max-height: 500px; border-radius: 10px; border: 1px solid var(--border); box-shadow: var(--shadow-sm);">
        </a>
      </div>
    </div>
    <?php endif; ?>

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
            $fpUrl = str_starts_with($floorplan['file_path'], 'http') ? $floorplan['file_path'] : BASE_URL . '/' . $floorplan['file_path'];
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
              <?php foreach($manpower as $m): ?>
              <tr>
                <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($m['role']) ?></td>
                <td><?= htmlspecialchars($m['assigned_person']??'—') ?></td>
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
              <?php foreach($schedules as $s): ?>
              <tr>
                <td><span class="time-badge"><?= htmlspecialchars($s['sched_date']) ?></span></td>
                <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($s['event_name']) ?></td>
                <td><?= htmlspecialchars($s['venue']??'—') ?></td>
                <td><?= htmlspecialchars($s['organizer']??'—') ?></td>
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
              <?php foreach($program as $p): ?>
              <tr>
                <td><span class="time-badge">⏱ <?= htmlspecialchars($p['time_slot']) ?></span></td>
                <td><strong style="color:var(--text-main); font-weight:600;"><?= htmlspecialchars($p['segment']) ?></strong></td>
                <td style="color:var(--text-muted);"><?= htmlspecialchars($p['description']??'—') ?></td>
                <td><?= htmlspecialchars($p['person_ic']??'—') ?></td>
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
          <?php foreach([
            'Mechanics'=>$guidelines['mechanics'],
            'Criteria for Judging'=>$guidelines['criteria'],
            'Scoring System'=>$guidelines['scoring_system'],
            'Special Awards'=>$guidelines['special_awards']
          ] as $l=>$v): if(!$v) continue; ?>
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
                <?php foreach($ftasks as $f): 
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
                  <td><?= $tCommittee !== '—' ? '<span class="badge badge-secondary">'.htmlspecialchars($tCommittee).'</span>' : '—' ?></td>
                  <td><strong><?= htmlspecialchars($tPerson) ?></strong></td>
                  <td><?= htmlspecialchars($tRole) ?></td>
                  <td><?= $tDue !== '—' ? '<span class="time-badge">📅 '.htmlspecialchars($tDue).'</span>' : '—' ?></td>
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
              <?php foreach($kpis as $k): ?>
              <tr>
                <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($k['indicator']) ?></td>
                <td><?= htmlspecialchars($k['target_metric']??'—') ?></td>
                <td><?= htmlspecialchars($k['evaluation_method']??'—') ?></td>
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
              <button type="button" class="btn btn-outline btn-sm" onclick="window.print()" style="cursor: pointer;">
                🖨️ Print QR Code
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
        <span class="badge badge-secondary"><?= count($history) ?> log<?= count($history)>1?'s':'' ?></span>
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
              <?php foreach($history as $h): ?>
              <tr>
                <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($h['name']) ?></td>
                <td><?= getStatusBadge($h['action']) ?></td>
                <td style="color:var(--text-muted); font-size:0.85rem; font-style:italic;"><?= htmlspecialchars($h['notes']??'—') ?></td>
                <td style="white-space:nowrap; font-size:0.82rem; color:var(--text-muted);"><?= date('M j, Y g:i A', strtotime($h['acted_at'])) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- content -->
</div><!-- main-wrap -->
</body>
</html>
