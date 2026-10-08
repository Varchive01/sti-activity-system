<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: '.BASE_URL.'/faculty/activities.php'); exit; }

$act  = $db->prepare("SELECT a.*,u.name as fn,u.email as fe FROM activities a JOIN users u ON a.faculty_id=u.id WHERE a.id=?");
$act->execute([$id]); $activity=$act->fetch();
if (!$activity) die('Not found.');

$user = currentUser();
$isLeader = ($user['role'] === 'faculty' && (int)$activity['faculty_id'] === (int)$user['id']);

// Check if user is an assigned member on any task in this activity
$assignedStmt = $db->prepare("SELECT COUNT(*) FROM faculty_tasks WHERE activity_id = ? AND assigned_user_id = ?");
$assignedStmt->execute([$id, $user['id']]);
$isAssigned = ((int)$assignedStmt->fetchColumn() > 0);

if ($user['role'] === 'faculty' && !$isLeader && !$isAssigned) {
    die('Unauthorized access.');
}

// Check task progress reminders for this activity
checkTaskProgressReminders($db, $user['id'], $user['role'], $id);

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

$mats = $db->prepare("SELECT * FROM materials WHERE activity_id=?"); $mats->execute([$id]); $materials=$mats->fetchAll();
$prog = $db->prepare("SELECT * FROM program_sequence WHERE activity_id=? ORDER BY sort_order"); $prog->execute([$id]); $program=$prog->fetchAll();
$mp   = $db->prepare("SELECT * FROM manpower WHERE activity_id=?"); $mp->execute([$id]); $manpower=$mp->fetchAll();
$ft   = $db->prepare("SELECT ft.*, u.name as assigned_user_name, u.email as assigned_user_email FROM faculty_tasks ft LEFT JOIN users u ON ft.assigned_user_id = u.id WHERE ft.activity_id=? ORDER BY ft.id ASC"); $ft->execute([$id]); $ftasks=$ft->fetchAll();
$delivStmt = $db->prepare("SELECT td.*, u.name as uploader_name, r.name as reviewer_name FROM task_deliverables td JOIN users u ON td.uploaded_by = u.id LEFT JOIN users r ON td.reviewed_by = r.id WHERE td.activity_id = ? ORDER BY td.uploaded_at DESC");
$delivStmt->execute([$id]);
$allDeliverables = $delivStmt->fetchAll(PDO::FETCH_ASSOC);
$taskDeliverables = [];
foreach ($allDeliverables as $d) {
    $taskDeliverables[(int)$d['task_id']][] = $d;
}
$sysUsersStmt = $db->query("SELECT id, name, email, department FROM users WHERE role IN ('faculty','admin1','admin2') ORDER BY name ASC");
$systemUsers = $sysUsersStmt ? $sysUsersStmt->fetchAll() : [];
$gl   = $db->prepare("SELECT * FROM guidelines WHERE activity_id=?"); $gl->execute([$id]); $guidelines=$gl->fetch();
$pe   = $db->prepare("SELECT * FROM post_event WHERE activity_id=?"); $pe->execute([$id]); $postEvent=$pe->fetch();
$docs = $db->prepare("SELECT * FROM documents WHERE activity_id=? ORDER BY uploaded_at DESC"); $docs->execute([$id]); $documents=$docs->fetchAll();
$kpis = $db->prepare("SELECT criteria,AVG(rating) as avg FROM kpi_evaluations WHERE activity_id=? GROUP BY criteria"); $kpis->execute([$id]); $kpiData=$kpis->fetchAll();
$rca  = $db->prepare("SELECT * FROM root_cause_analysis WHERE activity_id=?"); $rca->execute([$id]); $rcas=$rca->fetchAll();
$logs = $db->prepare("SELECT al.*,u.name FROM approval_logs al JOIN users u ON al.reviewer_id=u.id WHERE al.activity_id=? ORDER BY al.acted_at"); $logs->execute([$id]); $history=$logs->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($activity['title'] ?? 'View Activity') ?> – STI</title>
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
/* ── Typography Consistency: All Elements Use Plus Jakarta Sans ── */
body,
h1, h2, h3, h4, h5, h6,
.page-title,
.detail-title,
.card-header h2,
.card-header h3,
.btn,
.badge,
.topbar-user-name,
.topbar-user-role,
.info-tile,
.detail-table,
#responsesTable,
.tab-link,
label, input, button, select, textarea {
  font-family: 'Plus Jakarta Sans', sans-serif !important;
}

.content {
  padding: 24px;
}

/* ── Detail Page Scoped System ── */
.detail-header-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius, 14px);
  box-shadow: var(--shadow-sm);
  margin-bottom: 20px;
}
.detail-title {
  font-family: 'Plus Jakarta Sans', sans-serif !important;
  font-size: 1.45rem;
  font-weight: 800;
  color: var(--text-main);
  line-height: 1.3;
  letter-spacing: -0.02em;
  margin: 0;
}
.detail-section-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius, 14px);
  box-shadow: var(--shadow-sm);
  margin-bottom: 20px;
  overflow: hidden;
}
.detail-section-card .card-header {
  padding: 14px 20px;
  background: var(--bg-card);
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
}
.detail-section-card .card-header h2 {
  font-family: 'Plus Jakarta Sans', sans-serif !important;
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--text-main);
  margin: 0;
  letter-spacing: -0.01em;
  display: flex;
  align-items: center;
  gap: 8px;
}
.detail-section-card .card-body {
  padding: 20px 22px;
}

/* ── Info Grid & Tiles ── */
.detail-section-card .info-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
  gap: 12px;
}
.detail-section-card .info-tile {
  background: var(--bg-base);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 12px 16px;
  box-shadow: none;
  transition: border-color .15s ease, background-color .15s ease;
}
.detail-section-card .info-tile:hover {
  border-color: var(--accent, var(--sti-blue));
}
.detail-section-card .info-tile .lbl {
  font-size: 0.70rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-muted);
  font-weight: 700;
  margin-bottom: 4px;
}
.detail-section-card .info-tile .val {
  font-size: 0.90rem;
  font-weight: 600;
  color: var(--text-main);
  line-height: 1.45;
  word-break: break-word;
}

/* ── Objectives Block ── */
.obj-card-item {
  background: var(--bg-base);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 16px 18px;
  margin-top: 14px;
  transition: border-color .15s ease;
}
.obj-card-item:hover {
  border-color: var(--accent, var(--sti-blue));
}
.obj-card-item .obj-lbl {
  font-size: 0.70rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-muted);
  font-weight: 700;
  margin-bottom: 6px;
}
.obj-card-item .obj-val {
  font-size: 0.88rem;
  color: var(--text-main);
  line-height: 1.6;
}

/* ── Data Tables ── */
.detail-table,
#responsesTable {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.86rem;
}
.detail-table thead tr,
#responsesTable thead tr {
  background: var(--bg-base);
  border-bottom: 1.5px solid var(--border);
}
.detail-table th,
#responsesTable th {
  padding: 12px 18px;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-muted);
  text-align: left;
  white-space: nowrap;
}
.detail-table td,
#responsesTable td {
  padding: 13px 18px;
  border-bottom: 1px solid var(--border);
  color: var(--text-main);
  vertical-align: middle;
}
.detail-table tbody tr:last-child td,
#responsesTable tbody tr:last-child td {
  border-bottom: none;
}
.detail-table tbody tr:hover td,
#responsesTable tbody tr:hover td {
  background: rgba(2, 132, 199, 0.03);
}
.detail-table tfoot td {
  padding: 14px 18px;
  background: var(--bg-base);
  border-top: 1.5px solid var(--border);
}

/* ── Badges & Pills ── */
.time-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 0.75rem;
  font-weight: 600;
  padding: 3px 8px;
  border-radius: 6px;
  background: rgba(2, 132, 199, 0.08);
  color: var(--sti-blue, #0284C7);
  border: 1px solid rgba(2, 132, 199, 0.2);
}
.qty-pill {
  display: inline-block;
  padding: 2px 10px;
  font-size: 0.78rem;
  font-weight: 700;
  border-radius: 999px;
  background: var(--bg-base);
  border: 1px solid var(--border);
  color: var(--text-main);
}

/* ── Timeline Styles ── */
.detail-timeline {
  display: flex;
  flex-direction: column;
  position: relative;
}
.timeline-item {
  display: flex;
  gap: 16px;
  position: relative;
  padding-bottom: 20px;
}
.timeline-item.last-item {
  padding-bottom: 0;
}
.timeline-marker {
  display: flex;
  flex-direction: column;
  align-items: center;
  flex-shrink: 0;
  width: 16px;
  padding-top: 4px;
}
.timeline-dot {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  flex-shrink: 0;
  box-shadow: 0 0 0 3px var(--bg-card);
}
.timeline-line {
  width: 2px;
  background: var(--border);
  flex-grow: 1;
  margin-top: 4px;
}
.timeline-content {
  flex-grow: 1;
  min-width: 0;
}
.timeline-notes {
  margin-top: 8px;
  padding: 10px 14px;
  background: var(--bg-base);
  border: 1px solid var(--border);
  border-radius: 8px;
  font-size: 0.84rem;
  color: var(--text-main);
  line-height: 1.45;
}

/* ── Document Item ── */
.doc-item-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  background: var(--bg-base);
  border: 1px solid var(--border);
  border-radius: 10px;
  transition: border-color .15s ease, background-color .15s ease;
}
.doc-item-row:hover {
  border-color: var(--accent, var(--sti-blue));
}

/* ── KPI Stars ── */
.kpi-star {
  color: var(--sti-gold, #F59E0B);
  font-size: 0.95rem;
  letter-spacing: 2px;
}

/* ── Form Controls (Search & Filters) ── */
.form-control {
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 8px 12px;
  font-size: 0.86rem;
  background: var(--bg-card);
  color: var(--text-main);
  outline: none;
  transition: border-color .15s ease, box-shadow .15s ease;
}
.form-control:focus {
  border-color: var(--accent, var(--sti-blue));
  box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
}

/* ── Action Buttons Polish ── */
.btn-sm {
  padding: 6px 14px;
  font-size: 0.82rem;
  font-weight: 600;
  border-radius: 8px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  text-decoration: none;
  transition: all .15s ease;
  cursor: pointer;
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
  color: var(--text-main);
  background: var(--bg-card);
}
.btn-outline:hover {
  border-color: var(--accent, var(--sti-blue));
  color: var(--accent, var(--sti-blue));
  background: rgba(2, 132, 199, 0.06);
}

/* ── Tabs Navigation Polish ── */
.tabs-nav {
  display: flex;
  gap: 8px;
  border-bottom: 2px solid var(--border);
  margin-bottom: 22px;
  overflow-x: auto;
}
.tab-link {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 18px;
  font-family: 'Plus Jakarta Sans', sans-serif !important;
  font-size: 0.88rem;
  font-weight: 700;
  text-decoration: none;
  color: var(--text-muted);
  border-bottom: 3px solid transparent;
  margin-bottom: -2px;
  border-radius: 8px 8px 0 0;
  white-space: nowrap;
  transition: all .18s ease;
}
.tab-link:hover {
  color: var(--accent, var(--sti-blue));
  background: rgba(2, 132, 199, 0.04);
}
.tab-link.active {
  color: var(--accent, var(--sti-blue)) !important;
  border-bottom-color: var(--accent, var(--sti-blue)) !important;
  background: rgba(2, 132, 199, 0.04);
}

/* ── Free-Standing Topbar Icons ── */
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

/* ── Dark Mode Remnants & Contrast Protection ── */
[data-theme="dark"] .detail-header-card,
[data-theme="dark"] .detail-section-card {
  background: var(--bg-card);
  border-color: var(--border);
}
[data-theme="dark"] .detail-section-card .card-header {
  background: var(--bg-card);
  border-color: var(--border);
}
[data-theme="dark"] .detail-section-card .info-tile,
[data-theme="dark"] .obj-card-item,
[data-theme="dark"] .timeline-notes,
[data-theme="dark"] .doc-item-row {
  background: var(--bg-base);
  border-color: var(--border);
}
[data-theme="dark"] .detail-table thead tr,
[data-theme="dark"] #responsesTable thead tr {
  background: var(--bg-base);
  border-color: var(--border);
}
[data-theme="dark"] .detail-table tfoot td {
  background: var(--bg-base);
  border-color: var(--border);
}
[data-theme="dark"] .detail-table tbody tr:hover td,
[data-theme="dark"] #responsesTable tbody tr:hover td {
  background: rgba(255, 255, 255, 0.02);
}
[data-theme="dark"] .time-badge {
  background: rgba(56, 189, 248, 0.12);
  color: #38BDF8;
  border-color: rgba(56, 189, 248, 0.25);
}
[data-theme="dark"] .qty-pill {
  background: var(--bg-base);
  border-color: var(--border);
  color: var(--text-main);
}
[data-theme="dark"] .timeline-dot {
  box-shadow: 0 0 0 3px var(--bg-card);
}
[data-theme="dark"] .btn-outline {
  background: var(--bg-card);
  border-color: var(--border);
  color: var(--text-main);
}
[data-theme="dark"] .btn-outline:hover {
  background: rgba(2, 132, 199, 0.15);
  border-color: var(--accent, var(--sti-blue));
  color: #38BDF8;
}
[data-theme="dark"] .form-control {
  background: var(--bg-card);
  border-color: var(--border);
  color: var(--text-main);
}

/* AI analysis container and theme cards */
[data-theme="dark"] #ai-analysis-container .card,
[data-theme="dark"] .themes-layout .card,
[data-theme="dark"] .suggestions-layout .card {
  background: var(--bg-card) !important;
  border-color: var(--border) !important;
}
[data-theme="dark"] div[style*="background:#fff"],
[data-theme="dark"] div[style*="background: #fff"],
[data-theme="dark"] div[style*="background:#FFFFFF"],
[data-theme="dark"] div[style*="background: #FFFFFF"] {
  background: var(--bg-card) !important;
  border-color: var(--border) !important;
}
[data-theme="dark"] div[style*="background:#fffbeb"],
[data-theme="dark"] div[style*="background: #fffbeb"] {
  background: rgba(245, 158, 11, 0.12) !important;
  border-color: rgba(245, 158, 11, 0.3) !important;
  color: #FBBF24 !important;
}
[data-theme="dark"] div[style*="background:#fffbeb"] strong,
[data-theme="dark"] div[style*="background: #fffbeb"] strong {
  color: #FCD34D !important;
}
[data-theme="dark"] span[style*="background:#ecfdf5"],
[data-theme="dark"] span[style*="background: #ecfdf5"] {
  background: rgba(16, 185, 129, 0.15) !important;
  border-color: rgba(16, 185, 129, 0.3) !important;
  color: #34D399 !important;
}
[data-theme="dark"] span[style*="background:#d1e7dd"],
[data-theme="dark"] span[style*="background: #d1e7dd"] {
  background: rgba(16, 185, 129, 0.2) !important;
  color: #34D399 !important;
}
[data-theme="dark"] span[style*="background:#fef3c7"],
[data-theme="dark"] span[style*="background: #fef3c7"] {
  background: rgba(245, 158, 11, 0.2) !important;
  color: #FBBF24 !important;
}
[data-theme="dark"] span[style*="color:#555"] {
  color: var(--text-muted) !important;
}

/* Print Styles */
@media print {
  .no-print, .sidebar, .topbar, .tabs-nav {
    display: none !important;
  }
  .main-wrap {
    margin-left: 0 !important;
    width: 100% !important;
  }
  .content {
    padding: 0 !important;
  }
  .card, .detail-header-card, .detail-section-card {
    box-shadow: none !important;
    border: 1px solid #ccc !important;
  }
}
</style>
</head>
<body class="theme-faculty">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">Activity Detail</div>
    <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
      <a href="<?= BASE_URL ?>/faculty/activities.php" class="btn btn-outline btn-sm">← Back</a>
      <?php if ($activity['status'] === 'completed'): ?>
        <a href="<?= BASE_URL ?>/dean/generate-report.php?activity_id=<?= $id ?>" target="_blank" class="btn btn-outline btn-sm">📊 KPI Report</a>
      <?php endif; ?>
      <button class="btn btn-primary btn-sm" onclick="window.print()">🖨 Print</button>
      <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
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
    <?php endif; ?>

    <?php if ($activeTab === 'overview'): ?>
    <div class="card detail-header-card">
      <div class="card-body" style="padding: 22px 24px; display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
        <div style="min-width: 0; flex: 1;">
          <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px; flex-wrap: wrap;">
            <?= getStatusBadge($activity['status']) ?>
            <span class="badge badge-secondary"><?= ucfirst(str_replace('_',' ',$activity['source'])) ?></span>
            <?php if (!empty($activity['event_date'])): ?>
            <span style="font-size: 0.78rem; color: var(--text-muted); font-weight: 600;">📅 <?= date('F j, Y', strtotime($activity['event_date'])) ?></span>
            <?php endif; ?>
          </div>
          <h1 class="detail-title">
            <?= htmlspecialchars($activity['title']) ?>
          </h1>
          <div class="text-sm text-muted" style="margin-top: 8px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 0.82rem;">
            <span>Submitted by <strong style="color: var(--text-main); font-weight: 600;"><?= htmlspecialchars($activity['fn']) ?></strong></span>
            <span>·</span>
            <span><?= $activity['submitted_at'] ? date('F j, Y g:i A', strtotime($activity['submitted_at'])) : 'Not yet submitted' ?></span>
            <?php if (!empty($activity['approved_at'])): ?>
            <span>·</span>
            <span style="color: var(--success, #16A34A); font-weight: 600;">Approved <?= date('M j, Y', strtotime($activity['approved_at'])) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Event Info -->
    <div class="card detail-section-card">
      <div class="card-header">
        <h2><span>📄</span> Event Information</h2>
      </div>
      <div class="card-body">
        <div class="info-grid" style="margin-bottom: 16px;">
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
        <div class="obj-card-item">
          <div class="obj-lbl">General Objectives</div>
          <div class="obj-val"><?= nl2br(htmlspecialchars($activity['general_objectives']??'—')) ?></div>
        </div>
        <div class="obj-card-item">
          <div class="obj-lbl">Specific Objectives</div>
          <div class="obj-val"><?= nl2br(htmlspecialchars($activity['specific_objectives']??'—')) ?></div>
        </div>
      </div>
    </div>

    <?php if (!empty($activity['poster_path'])): ?>
    <?php $posterUrl = str_starts_with($activity['poster_path'], 'http') ? $activity['poster_path'] : BASE_URL . '/' . $activity['poster_path']; ?>
    <div class="card detail-section-card">
      <div class="card-header">
        <h2><span>🖼️</span> Event Poster</h2>
      </div>
      <div class="card-body" style="text-align:center; padding: 24px;">
        <a href="<?= htmlspecialchars($posterUrl) ?>" target="_blank" style="display:inline-block;">
          <img src="<?= htmlspecialchars($posterUrl) ?>" alt="Event Poster" style="max-width: 100%; max-height: 500px; border-radius: var(--radius-sm, 10px); border: 1px solid var(--border); box-shadow: var(--shadow-sm);">
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Materials -->
    <?php if (!empty($materials)): ?>
    <div class="card detail-section-card">
      <div class="card-header">
        <h2><span>📦</span> Materials Needed</h2>
        <span class="badge badge-secondary"><?= count($materials) ?> item<?= count($materials)>1?'s':'' ?></span>
      </div>
      <div class="card-body" style="padding:0;">
        <div class="table-wrap" style="margin:0; border:none; border-radius:0;">
          <table class="detail-table">
            <thead>
              <tr>
                <th>Item</th>
                <th>Description</th>
                <th style="text-align:center;">Qty</th>
                <th>Provider</th>
                <th style="text-align:right;">Est. Cost</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($materials as $m): ?>
              <tr>
                <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($m['item_name']) ?></td>
                <td style="color:var(--text-muted);"><?= htmlspecialchars($m['description']??'—') ?></td>
                <td style="text-align:center;"><span class="qty-pill"><?= $m['quantity'] ?></span></td>
                <td><?= htmlspecialchars($m['provider']??'—') ?></td>
                <td style="text-align:right; font-weight:600; color:var(--text-main);">₱<?= number_format($m['est_cost'],2) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="4" style="text-align:right; font-weight:700; color:var(--text-main);">Total Est. Cost:</td>
                <td style="text-align:right; font-weight:800; color:var(--sti-blue, #0284C7); font-size:0.95rem;">
                  ₱<?= number_format(array_sum(array_map(fn($m)=>$m['est_cost']*$m['quantity'],$materials)),2) ?>
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Program -->
    <?php if (!empty($program)): ?>
    <div class="card detail-section-card">
      <div class="card-header">
        <h2><span>🎯</span> Program Sequence</h2>
        <span class="badge badge-secondary"><?= count($program) ?> segment<?= count($program)>1?'s':'' ?></span>
      </div>
      <div class="card-body" style="padding:0;">
        <div class="table-wrap" style="margin:0; border:none; border-radius:0;">
          <table class="detail-table">
            <thead>
              <tr>
                <th style="width:18%;">Time Slot</th>
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
      </div>
    </div>
    <?php endif; ?>

    <!-- Manpower -->
    <?php if (!empty($manpower)): ?>
    <div class="card detail-section-card">
      <div class="card-header">
        <h2><span>👥</span> Manpower / Assigned Roles</h2>
        <span class="badge badge-secondary"><?= count($manpower) ?> member<?= count($manpower)>1?'s':'' ?></span>
      </div>
      <div class="card-body" style="padding:0;">
        <div class="table-wrap" style="margin:0; border:none; border-radius:0;">
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

    <!-- Task & Role Assignments Foundation -->
    <div class="card detail-section-card" id="taskAssignmentsCard">
      <div class="card-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
        <div style="display: flex; align-items: center; gap: 10px;">
          <h2><span>📋</span> Task & Role Assignments</h2>
          <span class="badge badge-secondary" id="taskCountBadge"><?= count($ftasks) ?> task<?= count($ftasks) !== 1 ? 's' : '' ?></span>
        </div>
        <?php if ($user['role'] === 'faculty' && (int)$activity['faculty_id'] === (int)$user['id']): ?>
          <button type="button" class="btn btn-primary btn-sm" onclick="openTaskModal()">+ Add Task Assignment</button>
        <?php endif; ?>
      </div>

      <!-- Spearhead / Activity Leader Bar -->
      <div style="padding: 10px 20px; background: var(--bg-base); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; font-size: 0.85rem;">
        <div style="display: flex; align-items: center; gap: 8px;">
          <span style="font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.5px;">Activity Leader:</span>
          <strong style="color: var(--text-main);"><?= htmlspecialchars($activity['fn']) ?></strong>
          <span class="badge badge-primary" style="font-size: 0.7rem; padding: 2px 7px;">Spearhead</span>
          <span class="text-muted" style="font-size: 0.78rem;">(<?= htmlspecialchars($activity['fe'] ?? '') ?>)</span>
        </div>
        <div style="font-size: 0.78rem; color: var(--text-muted);">
          <span>Distribute work among committees & members</span>
        </div>
      </div>

      <div class="card-body" style="padding: 0;">
        <?php if (empty($ftasks)): ?>
          <div id="noTasksMsg" style="text-align: center; padding: 36px 20px; color: var(--text-muted);">
            <div style="font-size: 2rem; margin-bottom: 8px;">📋</div>
            <div style="font-weight: 600; font-size: 0.95rem; color: var(--text-main); margin-bottom: 4px;">No Task Assignments Yet</div>
            <p style="font-size: 0.84rem; margin: 0 0 16px 0; max-width: 480px; margin-left: auto; margin-right: auto;">
              As the activity leader, you can create tasks, assign work to specific people and committees, set target deadlines, and track initial progress.
            </p>
            <?php if ($user['role'] === 'faculty' && (int)$activity['faculty_id'] === (int)$user['id']): ?>
              <button type="button" class="btn btn-primary btn-sm" onclick="openTaskModal()">+ Add First Task Assignment</button>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="table-wrap" style="margin: 0; border: none; border-radius: 0;">
            <table class="detail-table" id="tasksTable">
              <thead>
                <tr>
                  <th style="width: 20%;">Task & Description</th>
                  <th style="width: 13%;">Committee</th>
                  <th style="width: 14%;">Assigned Member</th>
                  <th style="width: 10%;">Role</th>
                  <th style="width: 10%;">Target Date</th>
                  <th style="width: 11%;">Completion</th>
                  <th style="width: 18%;">Deliverables & Evidence</th>
                  <th style="width: 10%;">Status</th>
                  <?php if ($isLeader): ?>
                    <th style="width: 4%; text-align: center;">Actions</th>
                  <?php endif; ?>
                </tr>
              </thead>
              <tbody id="tasksTableBody">
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
                <tr id="task-row-<?= (int)$f['id'] ?>">
                  <td>
                    <div style="font-weight: 700; color: var(--text-main); font-size: 0.88rem;"><?= htmlspecialchars($tTitle) ?></div>
                    <?php if (!empty($tDesc)): ?>
                      <div style="color: var(--text-muted); font-size: 0.78rem; margin-top: 2px; line-height: 1.35;"><?= nl2br(htmlspecialchars($tDesc)) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($tCommittee !== '—'): ?>
                      <span class="badge badge-secondary" style="font-size: 0.75rem;"><?= htmlspecialchars($tCommittee) ?></span>
                    <?php else: ?>
                      <span style="color: var(--text-muted); font-size: 0.82rem;">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div style="font-weight: 600; color: var(--text-main); font-size: 0.84rem;"><?= htmlspecialchars($tPerson) ?></div>
                    <?php if (!empty($f['assigned_user_email'])): ?>
                      <div style="font-size: 0.72rem; color: var(--text-muted);"><?= htmlspecialchars($f['assigned_user_email']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span style="font-size: 0.84rem; color: var(--text-main);"><?= htmlspecialchars($tRole) ?></span>
                  </td>
                  <td>
                    <?php if ($tDue !== '—'): ?>
                      <span class="time-badge" style="font-size: 0.78rem;">📅 <?= htmlspecialchars($tDue) ?></span>
                    <?php else: ?>
                      <span style="color: var(--text-muted); font-size: 0.82rem;">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div style="display:flex;align-items:center;gap:6px;">
                      <div style="flex:1;height:6px;background:var(--border-light,#f1f5f9);border-radius:999px;overflow:hidden;min-width:45px;">
                        <div style="height:100%;width:<?= $tPct ?>%;background:<?= $tStatus === 'Completed' ? '#10b981' : ($tStatus === 'Delayed' ? '#ef4444' : '#0284c7') ?>;"></div>
                      </div>
                      <span style="font-size:0.75rem;font-weight:700;color:var(--text-main);min-width:28px;"><?= $tPct ?>%</span>
                    </div>
                    <?php if ($isLeader): ?>
                      <button type="button" class="btn btn-outline btn-sm" style="font-size:0.68rem;padding:2px 6px;margin-top:4px;" onclick='openSetCompletionModal(<?= (int)$f["id"] ?>, <?= json_encode($tTitle) ?>, <?= $tPct ?>)'>Set %</button>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div id="deliv-list-<?= (int)$f['id'] ?>" style="display:flex;flex-direction:column;gap:5px;margin-bottom:6px;">
                      <?php if (!empty($tDelivs)): ?>
                        <?php foreach ($tDelivs as $dl): 
                          $rBadge = ($dl['review_status'] === 'approved') ? 'badge-success' : (($dl['review_status'] === 'returned_for_improvement') ? 'badge-danger' : 'badge-warning');
                          $rLabel = ($dl['review_status'] === 'approved') ? 'Approved' : (($dl['review_status'] === 'returned_for_improvement') ? 'Returned' : 'Pending');
                        ?>
                          <div style="font-size:0.73rem;background:var(--bg-subtle,#f8f9fa);padding:5px 7px;border-radius:6px;border:1px solid var(--border-color,#e5e7eb);">
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
                            <?php if ($isLeader): ?>
                              <div style="margin-top:4px;text-align:right;">
                                <button type="button" class="btn btn-outline btn-sm" style="font-size:0.65rem;padding:1px 6px;" onclick='openReviewDeliverableModal(<?= json_encode($dl) ?>, <?= json_encode($tTitle) ?>, <?= (int)$f["completion_pct"] ?>)'>Review</button>
                              </div>
                            <?php endif; ?>
                          </div>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <span style="font-size:0.72rem;color:var(--text-muted);font-style:italic;">No files yet</span>
                      <?php endif; ?>
                    </div>

                    <?php if ((int)$f['assigned_user_id'] === (int)$user['id'] || $isLeader): ?>
                      <button type="button" class="btn btn-outline btn-sm" style="font-size:0.70rem;padding:2px 8px;" onclick='openUploadDeliverableModal(<?= (int)$f["id"] ?>, <?= json_encode($tTitle) ?>)'>
                        📤 Upload Deliverable
                      </button>
                    <?php endif; ?>
                  </td>
                  <td id="status-cell-<?= (int)$f['id'] ?>">
                    <?php if ($isLeader): ?>
                      <select class="form-control form-control-sm" style="padding: 3px 6px; font-size: 0.76rem; border-radius: 6px; width: auto; font-weight: 600;" onchange="updateTaskStatus(<?= (int)$f['id'] ?>, this.value)">
                        <option value="Not Started" <?= $tStatus === 'Not Started' ? 'selected' : '' ?>>Not Started</option>
                        <option value="In Progress" <?= $tStatus === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="Completed" <?= $tStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="Delayed" <?= $tStatus === 'Delayed' ? 'selected' : '' ?>>Delayed</option>
                      </select>
                    <?php else: ?>
                      <?= getTaskStatusBadge($tStatus) ?>
                    <?php endif; ?>
                  </td>
                  <?php if ($isLeader): ?>
                    <td style="text-align: center;">
                      <div style="display: inline-flex; gap: 4px;">
                        <button type="button" class="btn btn-outline btn-sm" style="padding: 3px 7px; font-size: 0.75rem;" title="Edit Task" onclick='openTaskModal(<?= json_encode($f) ?>)'>✏️</button>
                        <button type="button" class="btn btn-outline btn-sm" style="padding: 3px 7px; font-size: 0.75rem; color: var(--danger, #dc2626);" title="Remove Task" onclick="deleteTask(<?= (int)$f['id'] ?>)">✕</button>
                      </div>
                    </td>
                  <?php endif; ?>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Task & Role Assignment Modal -->
    <div id="taskAssignmentModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15,23,42,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box;">
      <div style="background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border); max-width: 600px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; box-shadow: var(--shadow-lg);">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
          <h3 id="modalTitle" style="margin: 0; font-size: 1.05rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
            <span>📋</span> <span id="modalTitleText">Add Task Assignment</span>
          </h3>
          <button type="button" onclick="closeTaskModal()" style="border: none; background: transparent; font-size: 1.3rem; color: var(--text-muted); cursor: pointer; line-height: 1;">&times;</button>
        </div>

        <form id="taskAssignmentForm" onsubmit="submitTaskForm(event)" style="display: flex; flex-direction: column; overflow: hidden; margin: 0;">
          <input type="hidden" id="modalTaskId" name="id" value="">
          <input type="hidden" name="activity_id" value="<?= (int)$id ?>">

          <div style="padding: 20px; overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 14px;">
            <div id="modalErrorBanner" style="display: none; padding: 10px 14px; background: rgba(220,38,38,0.1); border: 1px solid rgba(220,38,38,0.3); border-radius: 8px; color: var(--danger, #dc2626); font-size: 0.84rem; font-weight: 600;"></div>

            <div>
              <label class="form-label" style="display: block; font-weight: 700; font-size: 0.82rem; margin-bottom: 5px; color: var(--text-main);">
                Task Title <span class="text-danger" style="color: var(--danger, #dc2626);">*</span>
              </label>
              <input type="text" id="modalTaskTitle" name="task_title" class="form-control" style="width: 100%;" placeholder="e.g. Stage Sound & Lights Setup" required>
            </div>

            <div>
              <label class="form-label" style="display: block; font-weight: 700; font-size: 0.82rem; margin-bottom: 5px; color: var(--text-main);">
                Task Description / Contribution
              </label>
              <textarea id="modalTaskDesc" name="task_description" class="form-control" rows="2" style="width: 100%;" placeholder="Describe the specific task deliverables or responsibilities..."></textarea>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
              <div>
                <label class="form-label" style="display: block; font-weight: 700; font-size: 0.82rem; margin-bottom: 5px; color: var(--text-main);">
                  Assigned Committee
                </label>
                <input list="committeeList" id="modalCommittee" name="committee" class="form-control" style="width: 100%;" placeholder="Select or type committee...">
                <datalist id="committeeList">
                  <option value="Events Committee">
                  <option value="Technical & Logistics Committee">
                  <option value="Registration & Ushering Committee">
                  <option value="Program & Stage Committee">
                  <option value="Documentation & Media Committee">
                  <option value="Health & Safety Committee">
                  <option value="Finance & Materials Committee">
                  <option value="Student Organization">
                  <option value="IT Department">
                </datalist>
              </div>

              <div>
                <label class="form-label" style="display: block; font-weight: 700; font-size: 0.82rem; margin-bottom: 5px; color: var(--text-main);">
                  Responsible Role
                </label>
                <input list="rolesList" id="modalRole" name="role" class="form-control" style="width: 100%;" placeholder="e.g. Committee Lead, Lead Usher...">
                <datalist id="rolesList">
                  <option value="Committee Lead">
                  <option value="Medic">
                  <option value="Customer Service">
                  <option value="Usher">
                  <option value="Marshall">
                  <option value="Emcee">
                  <option value="Floor Director">
                  <option value="AV Support">
                  <option value="Tabulator">
                  <option value="Technical Specialist">
                  <?php foreach ($manpower as $mpItem): if (!empty($mpItem['role'])): ?>
                    <option value="<?= htmlspecialchars($mpItem['role']) ?>">
                  <?php endif; endforeach; ?>
                </datalist>
              </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
              <div>
                <label class="form-label" style="display: block; font-weight: 700; font-size: 0.82rem; margin-bottom: 5px; color: var(--text-main);">
                  Assigned Member / User
                </label>
                <input list="membersList" id="modalAssignedMember" name="assigned_member" class="form-control" style="width: 100%;" placeholder="Select or type name...">
                <datalist id="membersList">
                  <?php foreach ($systemUsers as $su): ?>
                    <option value="<?= htmlspecialchars($su['name']) ?>"><?= htmlspecialchars($su['department'] ? $su['name'].' ('.$su['department'].')' : $su['name']) ?></option>
                  <?php endforeach; ?>
                  <?php foreach ($manpower as $mpItem): if (!empty($mpItem['assigned_person'])): ?>
                    <option value="<?= htmlspecialchars($mpItem['assigned_person']) ?>"><?= htmlspecialchars($mpItem['assigned_person'].' ('.$mpItem['role'].')') ?></option>
                  <?php endif; endforeach; ?>
                </datalist>
              </div>

              <div>
                <label class="form-label" style="display: block; font-weight: 700; font-size: 0.82rem; margin-bottom: 5px; color: var(--text-main);">
                  Target / Deadline Date
                </label>
                <input type="date" id="modalDueDate" name="due_date" class="form-control" style="width: 100%;">
              </div>
            </div>

            <div>
              <label class="form-label" style="display: block; font-weight: 700; font-size: 0.82rem; margin-bottom: 5px; color: var(--text-main);">
                Initial Status
              </label>
              <select id="modalStatus" name="status" class="form-control" style="width: 100%;">
                <option value="Not Started">Not Started</option>
                <option value="In Progress">In Progress</option>
                <option value="Completed">Completed</option>
                <option value="Delayed">Delayed</option>
              </select>
            </div>
          </div>

          <div style="padding: 14px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; background: var(--bg-card);">
            <button type="button" class="btn btn-outline btn-sm" onclick="closeTaskModal()">Cancel</button>
            <button type="submit" class="btn btn-primary btn-sm" id="modalSubmitBtn">Save Assignment</button>
          </div>
        </form>
      </div>
    </div>

    <script>
      function openTaskModal(task = null) {
        const modal = document.getElementById('taskAssignmentModal');
        const form = document.getElementById('taskAssignmentForm');
        const errBanner = document.getElementById('modalErrorBanner');
        errBanner.style.display = 'none';

        if (task && task.id) {
          document.getElementById('modalTitleText').textContent = 'Edit Task Assignment';
          document.getElementById('modalSubmitBtn').textContent = 'Save Changes';
          document.getElementById('modalTaskId').value = task.id;
          document.getElementById('modalTaskTitle').value = task.task_title || task.assigned_task || '';
          document.getElementById('modalTaskDesc').value = task.task_description || task.contribution_desc || '';
          document.getElementById('modalCommittee').value = task.committee || '';
          document.getElementById('modalRole').value = task.role_in_event || '';
          document.getElementById('modalAssignedMember').value = task.assigned_user_name || task.faculty_name || '';
          document.getElementById('modalDueDate').value = task.due_date ? task.due_date.substring(0, 10) : '';
          document.getElementById('modalStatus').value = task.status || 'Not Started';
        } else {
          document.getElementById('modalTitleText').textContent = 'Add Task Assignment';
          document.getElementById('modalSubmitBtn').textContent = 'Create Assignment';
          form.reset();
          document.getElementById('modalTaskId').value = '';
          document.getElementById('modalStatus').value = 'Not Started';
        }

        modal.style.display = 'flex';
        document.getElementById('modalTaskTitle').focus();
      }

      function closeTaskModal() {
        const modal = document.getElementById('taskAssignmentModal');
        modal.style.display = 'none';
      }

      async function submitTaskForm(e) {
        e.preventDefault();
        const errBanner = document.getElementById('modalErrorBanner');
        const submitBtn = document.getElementById('modalSubmitBtn');
        errBanner.style.display = 'none';

        const taskId = document.getElementById('modalTaskId').value;
        const payload = {
          action: taskId ? 'update' : 'create',
          activity_id: <?= (int)$id ?>,
          id: taskId || undefined,
          task_title: document.getElementById('modalTaskTitle').value.trim(),
          task_description: document.getElementById('modalTaskDesc').value.trim(),
          committee: document.getElementById('modalCommittee').value.trim(),
          role: document.getElementById('modalRole').value.trim(),
          assigned_member: document.getElementById('modalAssignedMember').value.trim(),
          due_date: document.getElementById('modalDueDate').value || null,
          status: document.getElementById('modalStatus').value
        };

        if (!payload.task_title) {
          errBanner.textContent = 'Please enter a task title.';
          errBanner.style.display = 'block';
          return;
        }

        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving...';

        try {
          const res = await fetch('<?= BASE_URL ?>/api/task-assignment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
          });
          const data = await res.json();
          if (data && data.success) {
            closeTaskModal();
            window.location.reload();
          } else {
            errBanner.textContent = data.error || 'Failed to save task assignment.';
            errBanner.style.display = 'block';
          }
        } catch (err) {
          errBanner.textContent = 'A network error occurred. Please try again.';
          errBanner.style.display = 'block';
        } finally {
          submitBtn.disabled = false;
          submitBtn.textContent = taskId ? 'Save Changes' : 'Create Assignment';
        }
      }

      async function updateTaskStatus(taskId, newStatus) {
        try {
          const res = await fetch('<?= BASE_URL ?>/api/task-assignment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              action: 'update_status',
              activity_id: <?= (int)$id ?>,
              id: taskId,
              status: newStatus
            })
          });
          const data = await res.json();
          if (!data || !data.success) {
            alert(data.error || 'Failed to update task status.');
            window.location.reload();
          }
        } catch (err) {
          alert('Network error while updating task status.');
          window.location.reload();
        }
      }

      async function deleteTask(taskId) {
        if (!confirm('Are you sure you want to remove this task assignment?')) return;
        try {
          const res = await fetch('<?= BASE_URL ?>/api/task-assignment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              action: 'delete',
              activity_id: <?= (int)$id ?>,
              id: taskId
            })
          });
          const data = await res.json();
          if (data && data.success) {
            window.location.reload();
          } else {
            alert(data.error || 'Failed to delete task.');
          }
        } catch (err) {
          alert('Network error while deleting task.');
        }
      }

      // Close modal on escape key or backdrop click
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
          closeTaskModal();
          closeUploadDeliverableModal();
          closeReviewDeliverableModal();
          closeSetCompletionModal();
        }
      });
      document.getElementById('taskAssignmentModal')?.addEventListener('click', (e) => {
        if (e.target.id === 'taskAssignmentModal') closeTaskModal();
      });

      // ── Deliverable Upload Modal Logic ──
      function openUploadDeliverableModal(taskId, taskTitle) {
        document.getElementById('uploadTaskId').value = taskId;
        document.getElementById('uploadTaskTitle').textContent = taskTitle || 'Task #' + taskId;
        document.getElementById('deliverableFileInput').value = '';
        document.getElementById('deliverableNotesInput').value = '';
        document.getElementById('uploadErrorBanner').style.display = 'none';
        document.getElementById('deliverableUploadModal').style.display = 'flex';
      }

      function closeUploadDeliverableModal() {
        document.getElementById('deliverableUploadModal').style.display = 'none';
      }

      async function submitUploadDeliverable(e) {
        e.preventDefault();
        const btn = document.getElementById('uploadSubmitBtn');
        const err = document.getElementById('uploadErrorBanner');
        err.style.display = 'none';
        btn.disabled = true;
        btn.textContent = 'Uploading...';

        try {
          const form = document.getElementById('uploadDeliverableForm');
          const formData = new FormData(form);
          formData.append('action', 'upload');

          const res = await fetch('<?= BASE_URL ?>/api/task-deliverables.php', {
            method: 'POST',
            body: formData
          });
          const data = await res.json();
          if (data && data.success) {
            closeUploadDeliverableModal();
            window.location.reload();
          } else {
            err.textContent = data.error || 'Failed to upload deliverable.';
            err.style.display = 'block';
          }
        } catch (error) {
          err.textContent = 'Network error while uploading deliverable.';
          err.style.display = 'block';
        } finally {
          btn.disabled = false;
          btn.textContent = 'Upload Deliverable';
        }
      }

      // ── Deliverable Review Modal Logic ──
      function openReviewDeliverableModal(deliverable, taskTitle, currentPct) {
        if (!deliverable) return;
        document.getElementById('reviewDeliverableId').value = deliverable.id;
        document.getElementById('reviewFileName').textContent = deliverable.file_name;
        document.getElementById('reviewUploaderInfo').textContent = 'Uploaded by ' + (deliverable.uploader_name || 'Member') + ' on ' + (deliverable.uploaded_at || '');
        document.getElementById('reviewDownloadLink').href = '<?= BASE_URL ?>/api/document-download.php?deliverable_id=' + deliverable.id;
        document.getElementById('reviewStatusSelect').value = deliverable.review_status || 'approved';
        document.getElementById('reviewNotesInput').value = deliverable.review_notes || '';
        document.getElementById('reviewCompletionPctInput').value = currentPct !== undefined ? currentPct : 50;
        document.getElementById('reviewErrorBanner').style.display = 'none';
        document.getElementById('reviewDeliverableModal').style.display = 'flex';
      }

      function closeReviewDeliverableModal() {
        document.getElementById('reviewDeliverableModal').style.display = 'none';
      }

      function setReviewPct(pct) {
        document.getElementById('reviewCompletionPctInput').value = pct;
        if (pct === 100) {
          document.getElementById('reviewStatusSelect').value = 'approved';
        }
      }

      function handleReviewDecisionChange(status) {
        const pctInput = document.getElementById('reviewCompletionPctInput');
        if (status === 'returned_for_improvement') {
          if (parseInt(pctInput.value) >= 100) {
            pctInput.value = 50;
          }
        } else if (status === 'approved' && parseInt(pctInput.value) === 0) {
          pctInput.value = 50;
        }
      }

      async function submitReviewDeliverable(e) {
        e.preventDefault();
        const btn = document.getElementById('reviewSubmitBtn');
        const err = document.getElementById('reviewErrorBanner');
        err.style.display = 'none';
        btn.disabled = true;
        btn.textContent = 'Saving Review...';

        try {
          const deliverableId = document.getElementById('reviewDeliverableId').value;
          const reviewStatus = document.getElementById('reviewStatusSelect').value;
          const notes = document.getElementById('reviewNotesInput').value;
          const completionPct = document.getElementById('reviewCompletionPctInput').value;

          const res = await fetch('<?= BASE_URL ?>/api/task-deliverables.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              action: 'review',
              deliverable_id: deliverableId,
              review_status: reviewStatus,
              notes: notes,
              completion_pct: parseInt(completionPct) || 0
            })
          });
          const data = await res.json();
          if (data && data.success) {
            closeReviewDeliverableModal();
            window.location.reload();
          } else {
            err.textContent = data.error || 'Failed to save review.';
            err.style.display = 'block';
          }
        } catch (error) {
          err.textContent = 'Network error while saving review.';
          err.style.display = 'block';
        } finally {
          btn.disabled = false;
          btn.textContent = 'Save Review & Progress';
        }
      }

      // ── Set Completion Modal Logic ──
      function openSetCompletionModal(taskId, taskTitle, currentPct) {
        document.getElementById('setCompletionTaskId').value = taskId;
        document.getElementById('setCompletionTaskTitle').textContent = taskTitle || 'Task #' + taskId;
        document.getElementById('setCompletionInput').value = currentPct || 0;
        document.getElementById('setCompletionModal').style.display = 'flex';
      }

      function closeSetCompletionModal() {
        document.getElementById('setCompletionModal').style.display = 'none';
      }

      async function submitSetCompletion(e) {
        e.preventDefault();
        const taskId = document.getElementById('setCompletionTaskId').value;
        const pct = document.getElementById('setCompletionInput').value;

        try {
          const res = await fetch('<?= BASE_URL ?>/api/task-deliverables.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              action: 'set_completion',
              task_id: taskId,
              completion_pct: parseInt(pct) || 0
            })
          });
          const data = await res.json();
          if (data && data.success) {
            closeSetCompletionModal();
            window.location.reload();
          } else {
            alert(data.error || 'Failed to update progress.');
          }
        } catch (err) {
          alert('Network error while updating progress.');
        }
      }

      document.getElementById('deliverableUploadModal')?.addEventListener('click', (e) => {
        if (e.target.id === 'deliverableUploadModal') closeUploadDeliverableModal();
      });
      document.getElementById('reviewDeliverableModal')?.addEventListener('click', (e) => {
        if (e.target.id === 'reviewDeliverableModal') closeReviewDeliverableModal();
      });
      document.getElementById('setCompletionModal')?.addEventListener('click', (e) => {
        if (e.target.id === 'setCompletionModal') closeSetCompletionModal();
      });
    </script>

    <!-- Deliverable Upload Modal -->
    <div id="deliverableUploadModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15,23,42,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box;">
      <div style="background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border); max-width: 520px; width: 100%; box-shadow: var(--shadow-lg); overflow: hidden;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
          <h3 style="margin: 0; font-size: 1.05rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
            <span>📤</span> <span>Upload Task Deliverable</span>
          </h3>
          <button type="button" onclick="closeUploadDeliverableModal()" style="border: none; background: transparent; font-size: 1.3rem; color: var(--text-muted); cursor: pointer; line-height: 1;">&times;</button>
        </div>
        <form id="uploadDeliverableForm" onsubmit="submitUploadDeliverable(event)" style="padding: 20px; display: flex; flex-direction: column; gap: 14px; margin: 0;">
          <input type="hidden" id="uploadTaskId" name="task_id" value="">
          <div id="uploadErrorBanner" style="display: none; padding: 10px 14px; background: rgba(220,38,38,0.1); border: 1px solid rgba(220,38,38,0.3); border-radius: 8px; color: var(--danger, #dc2626); font-size: 0.84rem; font-weight: 600;"></div>
          
          <div>
            <label class="form-label" style="display: block; font-weight: 700; font-size: 0.82rem; margin-bottom: 4px; color: var(--text-muted);">Assigned Task</label>
            <div id="uploadTaskTitle" style="font-weight: 700; color: var(--text-main); font-size: 0.92rem;">—</div>
          </div>

          <div>
            <label class="form-label" style="display: block; font-weight: 700; font-size: 0.82rem; margin-bottom: 5px; color: var(--text-main);">
              Deliverable File <span class="text-danger" style="color: var(--danger, #dc2626);">*</span>
            </label>
            <input type="file" id="deliverableFileInput" name="deliverable_file" class="form-control" style="width: 100%;" required>
            <small style="color: var(--text-muted); font-size: 0.72rem; display: block; margin-top: 3px;">
              Accepted: PDF, DOC, DOCX, XLS, XLSX, PNG, JPG, GIF (Max 10MB)
            </small>
          </div>

          <div>
            <label class="form-label" style="display: block; font-weight: 700; font-size: 0.82rem; margin-bottom: 5px; color: var(--text-main);">Notes / Submission Comments</label>
            <textarea id="deliverableNotesInput" name="notes" class="form-control" rows="2" style="width: 100%;" placeholder="e.g. Completed initial design draft for review..."></textarea>
          </div>

          <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 6px;">
            <button type="button" class="btn btn-outline btn-sm" onclick="closeUploadDeliverableModal()">Cancel</button>
            <button type="submit" class="btn btn-primary btn-sm" id="uploadSubmitBtn">Upload Deliverable</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Deliverable Review & Progress Modal -->
    <div id="reviewDeliverableModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15,23,42,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box;">
      <div style="background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border); max-width: 560px; width: 100%; box-shadow: var(--shadow-lg); overflow: hidden;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
          <h3 style="margin: 0; font-size: 1.05rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
            <span>🔍</span> <span>Review Deliverable & Set Progress</span>
          </h3>
          <button type="button" onclick="closeReviewDeliverableModal()" style="border: none; background: transparent; font-size: 1.3rem; color: var(--text-muted); cursor: pointer; line-height: 1;">&times;</button>
        </div>
        <form id="reviewDeliverableForm" onsubmit="submitReviewDeliverable(event)" style="padding: 20px; display: flex; flex-direction: column; gap: 14px; margin: 0;">
          <input type="hidden" id="reviewDeliverableId" name="deliverable_id" value="">
          <div id="reviewErrorBanner" style="display: none; padding: 10px 14px; background: rgba(220,38,38,0.1); border: 1px solid rgba(220,38,38,0.3); border-radius: 8px; color: var(--danger, #dc2626); font-size: 0.84rem; font-weight: 600;"></div>

          <div style="background: var(--bg-subtle, #f8f9fa); padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border-color, #e5e7eb);">
            <div style="font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Deliverable File</div>
            <div id="reviewFileName" style="font-weight: 700; color: var(--text-main); font-size: 0.90rem; margin-top: 2px;">—</div>
            <div id="reviewUploaderInfo" style="font-size: 0.74rem; color: var(--text-muted); margin-top: 2px;">—</div>
            <div style="margin-top: 6px;">
              <a href="#" id="reviewDownloadLink" target="_blank" class="btn btn-outline btn-sm" style="font-size: 0.72rem; padding: 2px 8px;">Download / Open File &rarr;</a>
            </div>
          </div>

          <div>
            <label class="form-label" style="display: block; font-weight: 700; font-size: 0.82rem; margin-bottom: 5px; color: var(--text-main);">Review Decision</label>
            <select id="reviewStatusSelect" name="review_status" class="form-control" style="width: 100%; font-weight: 600;" onchange="handleReviewDecisionChange(this.value)">
              <option value="approved">Approved</option>
              <option value="returned_for_improvement">Return for Improvement</option>
              <option value="pending">Pending Review</option>
            </select>
          </div>

          <div>
            <label class="form-label" style="display: block; font-weight: 700; font-size: 0.82rem; margin-bottom: 5px; color: var(--text-main);">Feedback / Review Notes</label>
            <textarea id="reviewNotesInput" name="notes" class="form-control" rows="2" style="width: 100%;" placeholder="Provide feedback or improvement instructions..."></textarea>
          </div>

          <div>
            <label class="form-label" style="display: block; font-weight: 700; font-size: 0.82rem; margin-bottom: 5px; color: var(--text-main);">
              Set Task Completion Percentage (0–100%)
            </label>
            <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px;">
              <input type="number" id="reviewCompletionPctInput" name="completion_pct" min="0" max="100" class="form-control" style="width: 90px; font-weight: 700; text-align: center;">
              <span style="font-weight: 700; font-size: 0.85rem;">%</span>
              <div style="display: flex; gap: 4px; flex-wrap: wrap; margin-left: 8px;">
                <button type="button" class="btn btn-outline btn-sm" style="font-size: 0.70rem; padding: 2px 7px;" onclick="setReviewPct(25)">25%</button>
                <button type="button" class="btn btn-outline btn-sm" style="font-size: 0.70rem; padding: 2px 7px;" onclick="setReviewPct(60)">60%</button>
                <button type="button" class="btn btn-outline btn-sm" style="font-size: 0.70rem; padding: 2px 7px;" onclick="setReviewPct(100)">100% (Completed)</button>
              </div>
            </div>
            <small style="color: var(--text-muted); font-size: 0.72rem; display: block;">
              0–99% marks task incomplete (In Progress). 100% marks task Completed. Returning for improvement must not mark task completed.
            </small>
          </div>

          <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 6px;">
            <button type="button" class="btn btn-outline btn-sm" onclick="closeReviewDeliverableModal()">Cancel</button>
            <button type="submit" class="btn btn-primary btn-sm" id="reviewSubmitBtn">Save Review & Progress</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Direct Set Completion Modal -->
    <div id="setCompletionModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15,23,42,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box;">
      <div style="background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border); max-width: 440px; width: 100%; box-shadow: var(--shadow-lg); overflow: hidden;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
          <h3 style="margin: 0; font-size: 1.05rem; font-weight: 700; color: var(--text-main);">Set Task Completion</h3>
          <button type="button" onclick="closeSetCompletionModal()" style="border: none; background: transparent; font-size: 1.3rem; color: var(--text-muted); cursor: pointer; line-height: 1;">&times;</button>
        </div>
        <form id="setCompletionForm" onsubmit="submitSetCompletion(event)" style="padding: 20px; display: flex; flex-direction: column; gap: 14px; margin: 0;">
          <input type="hidden" id="setCompletionTaskId" value="">
          <div>
            <div style="font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Task</div>
            <div id="setCompletionTaskTitle" style="font-weight: 700; color: var(--text-main); font-size: 0.90rem;">—</div>
          </div>
          <div>
            <label class="form-label" style="display: block; font-weight: 700; font-size: 0.82rem; margin-bottom: 5px; color: var(--text-main);">Progress Percentage</label>
            <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 10px;">
              <input type="number" id="setCompletionInput" min="0" max="100" class="form-control" style="width: 90px; font-weight: 700; text-align: center;" required>
              <span style="font-weight: 700; font-size: 0.85rem;">%</span>
              <div style="display: flex; gap: 4px; flex-wrap: wrap; margin-left: 8px;">
                <button type="button" class="btn btn-outline btn-sm" style="font-size: 0.70rem; padding: 2px 7px;" onclick="document.getElementById('setCompletionInput').value=25">25%</button>
                <button type="button" class="btn btn-outline btn-sm" style="font-size: 0.70rem; padding: 2px 7px;" onclick="document.getElementById('setCompletionInput').value=60">60%</button>
                <button type="button" class="btn btn-outline btn-sm" style="font-size: 0.70rem; padding: 2px 7px;" onclick="document.getElementById('setCompletionInput').value=100">100%</button>
              </div>
            </div>
          </div>
          <div style="display: flex; justify-content: flex-end; gap: 10px;">
            <button type="button" class="btn btn-outline btn-sm" onclick="closeSetCompletionModal()">Cancel</button>
            <button type="submit" class="btn btn-primary btn-sm">Update Progress</button>
          </div>
        </form>
      </div>
    </div>
    </script>

    <!-- Guidelines -->
    <?php if ($guidelines): ?>
    <div class="card detail-section-card">
      <div class="card-header">
        <h2><span>📋</span> Event Guidelines</h2>
      </div>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:14px;">
          <?php foreach(['Mechanics'=>$guidelines['mechanics'],'Criteria for Judging'=>$guidelines['criteria'],'Scoring System'=>$guidelines['scoring_system'],'Special Awards'=>$guidelines['special_awards']] as $l=>$v): if(!$v) continue; ?>
          <div class="info-tile">
            <div class="lbl"><?= $l ?></div>
            <div style="font-size:0.86rem;margin-top:6px;line-height:1.55;color:var(--text-main);"><?= nl2br(htmlspecialchars($v)) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Post-Event -->
    <?php if ($postEvent || !empty($documents)): ?>
    <div class="card detail-section-card">
      <div class="card-header">
        <h2><span>📊</span> Post-Event Results & Documentation</h2>
      </div>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:20px;">
          <div>
            <?php if ($postEvent): ?>
            <div class="info-grid" style="grid-template-columns:repeat(auto-fit, minmax(140px, 1fr));">
              <?php
              $attPct = $postEvent['target_attendance'] > 0 ? round(($postEvent['actual_attendance']/$postEvent['target_attendance'])*100) : 0;
              foreach(['Actual Attendance'=>$postEvent['actual_attendance'],'Target'=>$postEvent['target_attendance'],'Attendance Rate'=>$attPct.'%','Satisfaction Score'=>number_format($postEvent['satisfaction_score'],1).'/5'] as $l=>$v):
              ?>
              <div class="info-tile"><div class="lbl"><?= $l ?></div><div class="val"><?= $v ?></div></div>
              <?php endforeach; ?>
            </div>
            <?php if (!empty($postEvent['observations'])): ?>
            <div class="info-tile" style="margin-top:12px;">
              <div class="lbl">Observations</div>
              <div style="font-size:0.86rem;margin-top:6px;line-height:1.5;color:var(--text-main);"><?= nl2br(htmlspecialchars($postEvent['observations'])) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($postEvent['recommendations']): ?>
            <div class="info-tile" style="margin-top:12px;">
              <div class="lbl">Recommendations</div>
              <div style="font-size:0.86rem;margin-top:6px;line-height:1.5;color:var(--text-main);"><?= nl2br(htmlspecialchars($postEvent['recommendations'])) ?></div>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (!empty($documents)): ?>
            <div class="info-tile" style="<?= $postEvent ? 'margin-top:12px;' : '' ?>">
              <div class="lbl" style="margin-bottom:12px;">📎 Attached Documents & Reports (<?= count($documents) ?>)</div>
              <div style="display:flex;flex-direction:column;gap:8px;">
                <?php foreach ($documents as $doc): ?>
                <div class="doc-item-row">
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
            <?php if (!empty($kpiData)): ?>
            <div class="info-tile">
              <div class="lbl" style="margin-bottom:14px;">KPI Breakdown</div>
              <?php foreach($kpiData as $k):
                $stars=round($k['avg']); ?>
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid var(--border);">
                <span style="font-size:0.82rem;color:var(--text-main);"><?= htmlspecialchars($k['criteria']) ?></span>
                <div style="display:flex;align-items:center;gap:6px;">
                  <span class="kpi-star"><?= str_repeat('★',$stars).str_repeat('☆',4-$stars) ?></span>
                  <strong style="font-size:0.8rem;color:var(--text-main);"><?= number_format($k['avg'],1) ?></strong>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- RCA -->
    <?php if (!empty($rcas)): ?>
    <div class="card detail-section-card">
      <div class="card-header">
        <h2><span>🔍</span> Root Cause Analysis</h2>
        <span class="badge badge-secondary"><?= count($rcas) ?> analys<?= count($rcas)>1?'es':'is' ?></span>
      </div>
      <div class="card-body">
        <?php foreach($rcas as $r): ?>
        <div class="info-tile" style="margin-bottom:12px;">
          <div style="display:flex;gap:10px;margin-bottom:8px;"><span class="badge badge-warning"><?= strtoupper(str_replace('_',' ',$r['method']??'')) ?></span></div>
          <div class="text-sm fw-bold" style="color:var(--text-main); margin-bottom:4px;">Problem:</div>
          <div class="text-sm" style="margin-bottom:10px; color:var(--text-main); line-height:1.5;"><?= nl2br(htmlspecialchars($r['problem']??'')) ?></div>
          <div class="text-sm fw-bold" style="color:var(--text-main); margin-bottom:4px;">Causes:</div>
          <div class="text-sm" style="margin-bottom:10px; color:var(--text-main); line-height:1.5;"><?= nl2br(htmlspecialchars($r['causes']??'')) ?></div>
          <div class="text-sm fw-bold" style="color:var(--text-main); margin-bottom:4px;">Analysis:</div>
          <div class="text-sm" style="color:var(--text-main); line-height:1.5;"><?= nl2br(htmlspecialchars($r['explanation']??'')) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Approval Timeline -->
    <?php if (!empty($history)): ?>
    <div class="card detail-section-card">
      <div class="card-header">
        <h2><span>📌</span> Approval Timeline & History</h2>
        <span class="badge badge-secondary"><?= count($history) ?> log<?= count($history)>1?'s':'' ?></span>
      </div>
      <div class="card-body" style="padding: 24px;">
        <div class="detail-timeline">
          <?php foreach($history as $idx => $h):
            $dotColor = in_array($h['action'],['approved','forwarded'])?'#16A34A':($h['action']==='returned'?'#D97706':'#DC2626');
          ?>
          <div class="timeline-item <?= $idx === count($history)-1 ? 'last-item' : '' ?>">
            <div class="timeline-marker">
              <div class="timeline-dot" style="background:<?= $dotColor ?>;"></div>
              <?php if ($idx < count($history)-1): ?>
              <div class="timeline-line"></div>
              <?php endif; ?>
            </div>
            <div class="timeline-content">
              <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <span style="font-size:0.88rem; font-weight:700; color:var(--text-main);"><?= htmlspecialchars($h['name']) ?></span>
                <?= getStatusBadge($h['action']) ?>
              </div>
              <div class="text-sm text-muted" style="margin-top:2px; font-size:0.78rem;">
                <?= date('F j, Y a	 g:i A', strtotime($h['acted_at'])) ?>
              </div>
              <?php if (!empty($h['notes'])): ?>
              <div class="timeline-notes" style="border-left:3px solid <?= $dotColor ?>;">
                <span style="font-style:italic;">"<?= htmlspecialchars($h['notes']) ?>"</span>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
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
            const container = document.getElementById('ai-analysis-container');
            const analysis = data.analysis;
            
            const isFinalized = !!(data.is_finalized || analysis.is_finalized);
            const finBy = data.finalized_by || analysis.finalized_by;
            const finAt = data.finalized_at || analysis.finalized_at;

            let statusBadge = '';
            let summaryBadge = '';
            if (isFinalized) {
              statusBadge = `<span class="badge" style="background:#15803d; color:#fff; padding:6px 14px; border-radius:999px; font-size:0.75rem; font-weight:700;">🔒 Finalized by ${escapeHtml(finBy?.name || 'Administrator')} (${escapeHtml(finBy?.role || 'Admin')}) on ${formatDate(finAt)}</span>`;
              summaryBadge = `<span style="font-size:0.72rem; color:#15803d; background:#ecfdf5; padding:4px 10px; border-radius:4px; font-weight:700; border:1px solid #a7f3d0;">🔒 Administrator Finalized</span>`;
            } else {
              statusBadge = `<span class="badge" style="background:#64748b; color:#fff; padding:6px 14px; border-radius:999px; font-size:0.75rem; font-weight:700;">🤖 Unreviewed AI Draft</span>`;
              summaryBadge = `<span style="font-size:0.72rem; color:var(--text-muted); background:var(--bg-base); padding:4px 10px; border-radius:4px; font-weight:600; border:1px solid var(--border);">🤖 AI-generated summary</span>`;
            }

            let outdatedAlert = '';
            if (data.is_outdated && !isFinalized) {
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
                  <div style="background:var(--bg-card); border:1px solid var(--border); border-left:4px solid #16a34a; border-radius:8px; padding:14px 16px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
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
                  <div style="background:var(--bg-card); border:1px solid var(--border); border-left:4px solid #d97706; border-radius:8px; padding:14px 16px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
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
                <div style="display:flex; gap:8px; align-items:center;">
                  ${statusBadge}
                  ${!isFinalized ? `<button class="btn btn-outline btn-sm no-print" onclick="runAnalysis()">🔄 Update Analysis</button>` : ''}
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
    ?>
    <div class="print-only print-analysis-section" style="display:none; margin-top:30px; border-top:2px solid #333; padding-top:20px; page-break-before:always;">
      <h2 style="font-family:'Plus Jakarta Sans',sans-serif; font-size:1.2rem; font-weight:800; margin-bottom:12px;">🤖 AI Participant Feedback Analysis Report</h2>
      <p style="font-size:0.9rem; line-height:1.5; font-style:italic; margin-bottom:15px;">
        "<?= htmlspecialchars($pa['overall_summary'] ?? '') ?>"
      </p>
      
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
