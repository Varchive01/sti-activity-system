<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';
requireRole('admin1');
$user = currentUser();
$db   = getDB();

// Stagnant proposals escalation check
checkProposalEscalations($db, $user['id'], $user['role']);

// Task progress reminders check
checkTaskProgressReminders($db, $user['id'], $user['role']);

// Period and Year Filter (Preserving existing semester conventions)
$selectedYear = (int)($_GET['year'] ?? date('Y'));
$selectedPeriod = sanitize($_GET['period'] ?? 'all');
$availableYears = range(date('Y'), date('Y') - 4);

$periodBounds = buildPeriodWhereClause($selectedYear, $selectedPeriod, 'a.event_date');
$whereClause = $periodBounds['where'];
$params = $periodBounds['params'];

// Approval Trends for Selected Period (scoped to student_org)
$approvalTrends = getApprovalTrends($selectedYear, $selectedPeriod, $db, 'student_org');

// Notifications
$unreadStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unreadStmt->execute([$user['id']]);
$unreadCount = (int)$unreadStmt->fetchColumn();

$notifStmt = $db->prepare("
    SELECT n.*, a.title as activity_title
    FROM notifications n
    LEFT JOIN activities a ON n.activity_id = a.id
    WHERE n.user_id = ?
    ORDER BY n.created_at DESC
    LIMIT 10
");
$notifStmt->execute([$user['id']]);
$notifications = $notifStmt->fetchAll();

$initial = strtoupper(substr($user['name'], 0, 1));

function timeAgo($datetime)
{
  $diff = time() - strtotime($datetime);
  if ($diff < 60)     return 'Just now';
  if ($diff < 3600)   return floor($diff / 60) . 'm ago';
  if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
  if ($diff < 604800) return floor($diff / 86400) . 'd ago';
  return date('M j', strtotime($datetime));
}

$statsStmt = $db->prepare("
  SELECT
    SUM(a.status IN ('under_review','resubmitted')) as pending,
    SUM(a.status='approved') as approved,
    SUM(a.status='returned_for_revision') as returned,
    SUM(a.status='rejected') as rejected
  FROM activities a
  WHERE a.source='student_org' AND {$whereClause}
");
$statsStmt->execute($params);
$stats = $statsStmt->fetch() ?: [];

$pendingStmt = $db->prepare("
  SELECT a.*, u.name as faculty_name FROM activities a
  JOIN users u ON a.faculty_id=u.id
  WHERE a.source='student_org' AND a.status IN ('under_review','resubmitted') AND {$whereClause}
  ORDER BY a.submitted_at DESC LIMIT 10
");
$pendingStmt->execute($params);
$pending = $pendingStmt->fetchAll();

$recent_actions = $db->prepare("
  SELECT al.*, a.title, a.status as current_status, a.id as activity_id, u.name as faculty_name FROM approval_logs al
  JOIN activities a ON al.activity_id=a.id
  JOIN users u ON a.faculty_id=u.id
  WHERE al.reviewer_id=? AND a.source='student_org' AND {$whereClause}
  ORDER BY al.acted_at DESC LIMIT 6
");
$recent_actions->execute(array_merge([$user['id']], $params));
$actions = $recent_actions->fetchAll();

// KPI Performance query for completed student-org activities
$kpiStmt = $db->prepare("
  SELECT 
    AVG(sub.avg_rating) as avg_rating,
    COUNT(sub.activity_id) as events_evaluated,
    COUNT(a.id) as completed_activities,
    SUM(pe.actual_attendance) as actual_att,
    SUM(pe.target_attendance) as target_att
  FROM activities a
  LEFT JOIN (
    SELECT activity_id, AVG(rating) as avg_rating 
    FROM kpi_evaluations 
    GROUP BY activity_id
  ) sub ON a.id = sub.activity_id
  LEFT JOIN post_event pe ON a.id = pe.activity_id
  WHERE a.source = 'student_org' AND a.status = 'completed' AND {$whereClause}
");
$kpiStmt->execute($params);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sir Ar-jay Dashboard – STI Activity System</title>
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
    /* ── Institutional Color Scheme Alignment (Match Faculty Dashboard) ── */
    :root,
    body.theme-arjay,
    .theme-arjay,
    aside.sidebar.theme-arjay {
      --accent: var(--sti-blue, #0284C7);
      --accent-lt: #E0F2FE;
    }

    [data-theme="dark"] body.theme-arjay,
    [data-theme="dark"] .theme-arjay,
    [data-theme="dark"] aside.sidebar.theme-arjay {
      --accent: #38BDF8;
      --accent-lt: rgba(2, 132, 199, 0.22);
    }

    /* Active Sidebar Nav Item (STI Blue matching Faculty) */
    .sidebar-nav a.active,
    aside.sidebar.theme-arjay .sidebar-nav a.active {
      background: var(--accent, var(--sti-blue)) !important;
      color: #fff !important;
      font-weight: 600;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    }

    [data-theme="dark"] .sidebar-nav a.active,
    [data-theme="dark"] aside.sidebar.theme-arjay .sidebar-nav a.active {
      background: var(--accent, #38BDF8) !important;
      color: #fff !important;
    }

    /* ── Institutional Typography: Plus Jakarta Sans (Match Faculty Dashboard) ── */
    .page-title,
    .topbar .page-title,
    .card-header h2,
    .card-header h3,
    .dashboard-main .card-header h2,
    .dashboard-rail .card-header h2,
    .stat-val,
    a.stat-card .stat-val,
    .stat-grid a.stat-card .stat-val,
    .stat-label,
    a.stat-card .stat-label,
    .stat-grid a.stat-card .stat-label,
    th, td,
    .activity-table th,
    .activity-table td,
    .rail-table th,
    .rail-table td,
    .act-title,
    .act-date,
    .act-faculty,
    .rail-act-title,
    .rail-act-faculty,
    .badge,
    .btn,
    .topbar-user-name,
    .topbar-user-role,
    .month-label,
    .month-val,
    label, input, button, select, textarea {
      font-family: 'Plus Jakarta Sans', sans-serif;
    }

    /* ── Content & Container Spacing ── */
    .content {
      padding: 24px;
    }

    .dashboard-layout {
      gap: 20px;
    }

    .dashboard-main {
      gap: 16px;
    }

    .dashboard-rail {
      gap: 16px;
    }

    .dashboard-rail-sticky {
      top: 80px;
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
      border-radius: 50% !important;
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
      background: var(--border-light, rgba(0, 0, 0, 0.05)) !important;
      color: var(--text-main);
      border: none !important;
      box-shadow: none !important;
      transform: translateY(-1px);
    }

    [data-theme="dark"] #themeToggleBtn:hover,
    [data-theme="dark"] #notifBellBtn:hover,
    [data-theme="dark"] .theme-toggle-btn:hover,
    [data-theme="dark"] .notif-bell-btn:hover {
      background: rgba(255, 255, 255, 0.08) !important;
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
      outline: 2px solid var(--accent, var(--sti-blue));
      outline-offset: 2px;
      border-radius: 50% !important;
    }

    /* ── Topbar Filter & Form Controls ── */
    .topbar select.form-control,
    .topbar .form-control {
      background: var(--bg-card);
      color: var(--text-main);
      border: 1px solid var(--border);
      border-radius: 8px;
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 0.82rem;
      font-weight: 500;
      transition: border-color .15s ease, box-shadow .15s ease;
      outline: none;
    }
    .topbar select.form-control:focus {
      border-color: var(--accent, var(--sti-blue));
      box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.15);
    }
    .topbar .btn-outline {
      border: 1px solid var(--border);
      color: var(--text-main);
      background: var(--bg-card);
      border-radius: 8px;
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 0.80rem;
      font-weight: 600;
      transition: all .15s ease;
    }
    .topbar .btn-outline:hover {
      border-color: var(--accent, var(--sti-blue));
      color: var(--accent, var(--sti-blue));
      background: var(--accent-lt, rgba(2, 132, 199, 0.08));
    }

    /* ── 1. Compact Horizontal KPI Stat Cards (Exact Faculty Match: 70px height, 10px radius, 14px gap) ── */
    .stat-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 14px;
      align-items: center;
      width: 100%;
      margin-bottom: 0 !important;
    }

    .stat-card,
    a.stat-card,
    .stat-grid a.stat-card {
      position: relative;
      background: var(--bg-card);
      border-radius: 10px;
      border: 1px solid var(--border);
      box-shadow: var(--shadow-sm);
      padding: 10px 14px;
      display: flex;
      flex-direction: row;
      align-items: center;
      justify-content: flex-start;
      text-align: left;
      text-decoration: none !important;
      color: inherit !important;
      cursor: pointer;
      width: 100%;
      min-width: 0;
      height: 70px;
      min-height: 70px;
      max-height: 70px;
      box-sizing: border-box;
      gap: 12px;
      overflow: hidden;
      transition: transform 0.18s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.18s ease, border-color 0.18s ease;
    }

    .stat-card:hover,
    a.stat-card:hover,
    .stat-grid a.stat-card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
      border-color: var(--accent, var(--sti-blue));
    }

    .stat-icon {
      width: 36px;
      height: 36px;
      min-width: 36px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      margin-bottom: 0;
    }

    .stat-icon svg {
      width: 16px;
      height: 16px;
    }

    .stat-card > div:not(.stat-icon):not(.stat-strip) {
      min-width: 0;
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      justify-content: center;
      text-align: left;
    }

    a.stat-card .stat-val,
    .stat-grid a.stat-card .stat-val,
    .stat-card .stat-val {
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 1.38rem;
      font-weight: 800;
      line-height: 1;
      color: var(--text-main) !important;
      letter-spacing: -0.02em;
    }

    a.stat-card .stat-label,
    .stat-grid a.stat-card .stat-label,
    .stat-card .stat-label {
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 0.73rem;
      font-weight: 600;
      color: var(--text-muted) !important;
      line-height: 1.15;
      margin-top: 2px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      text-align: left;
    }

    /* ── 2. Shared Card Styling (14px radius, shared border, shared background, shared shadow) ── */
    .dashboard-main .card,
    .dashboard-rail .card {
      background: var(--bg-card);
      border-radius: 14px;
      border: 1px solid var(--border);
      box-shadow: var(--shadow-sm);
      overflow: hidden;
    }

    .dashboard-main .card-header,
    .dashboard-rail .card-header {
      padding: 14px 20px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      background: var(--bg-card);
    }

    .dashboard-main .card-header h2,
    .dashboard-rail .card-header h2 {
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 1.02rem;
      font-weight: 700;
      color: var(--text-main);
      margin: 0;
      letter-spacing: -0.01em;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .dashboard-rail .card-header h2 {
      font-size: 0.95rem;
    }

    .dashboard-main .card-header .btn {
      padding: 4px 12px;
      height: 28px;
      font-size: 0.75rem;
      font-weight: 600;
      border-radius: 7px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      transition: all 0.15s ease;
      font-family: 'Plus Jakarta Sans', sans-serif;
    }

    .dashboard-main .card-header .btn-primary {
      background: var(--accent, var(--sti-blue));
      border: 1px solid var(--accent, var(--sti-blue));
      color: #fff;
    }

    .dashboard-main .card-header .btn-primary:hover {
      filter: brightness(1.1);
      box-shadow: 0 2px 6px rgba(2, 132, 199, 0.25);
    }

    .dashboard-main .card-body {
      background: var(--bg-card);
    }

    .dashboard-main .card-body.chart-body {
      padding: 16px 20px;
    }

    /* ── Review Queue Table ── */
    .dashboard-main .table-wrap {
      border: none;
      border-radius: 0;
      background: transparent;
      overflow-x: auto;
      scrollbar-width: thin;
    }

    .activity-table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
      box-sizing: border-box;
    }

    .activity-table th,
    .activity-table td {
      box-sizing: border-box;
      overflow: hidden;
    }

    .activity-table th {
      padding: 10px 14px;
      font-size: 0.70rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--text-muted);
      border-bottom: 1px solid var(--border);
      background: var(--bg-base);
      text-align: left;
      white-space: nowrap;
      font-family: 'Plus Jakarta Sans', sans-serif;
    }

    .activity-table td {
      padding: 11px 14px;
      border-bottom: 1px solid var(--border);
      vertical-align: middle;
      color: var(--text-main);
      font-size: 0.82rem;
      transition: background 0.12s ease;
      font-family: 'Plus Jakarta Sans', sans-serif;
    }

    .activity-table tbody tr:last-child td {
      border-bottom: none;
    }

    .activity-table tbody tr:hover td {
      background: rgba(2, 132, 199, 0.03);
    }

    .col-title { width: 34%; }
    .col-faculty { width: 20%; }
    .col-source { width: 14%; }
    .col-status { width: 17%; }
    .col-actions { width: 15%; text-align: right; }
    .activity-table th.col-actions { text-align: right; }

    .act-title {
      font-weight: 600;
      color: var(--text-main);
      font-size: 0.86rem;
      line-height: 1.35;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
      word-break: break-word;
      font-family: 'Plus Jakarta Sans', sans-serif;
    }

    .act-date {
      color: var(--text-muted);
      font-size: 0.75rem;
      font-weight: 500;
      white-space: nowrap;
      margin-top: 2px;
      display: inline-block;
      font-family: 'Plus Jakarta Sans', sans-serif;
    }

    .act-faculty {
      font-weight: 500;
      color: var(--text-main);
      font-size: 0.82rem;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      display: block;
      font-family: 'Plus Jakarta Sans', sans-serif;
    }

    .act-actions {
      display: inline-flex;
      align-items: center;
      justify-content: flex-end;
      gap: 4px;
      white-space: nowrap;
    }

    .activity-table td .btn {
      padding: 3px 10px;
      height: 26px;
      font-size: 0.72rem;
      font-weight: 600;
      border-radius: 6px;
      box-sizing: border-box;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      font-family: 'Plus Jakarta Sans', sans-serif;
    }

    .activity-table td .btn-primary {
      background: var(--accent, var(--sti-blue));
      border: 1px solid var(--accent, var(--sti-blue));
      color: #fff;
    }

    .activity-table td .btn-primary:hover {
      filter: brightness(1.1);
      box-shadow: 0 2px 6px rgba(2, 132, 199, 0.25);
    }

    .activity-table .badge {
      padding: 2.5px 8px;
      font-size: 0.72rem;
      font-weight: 700;
      border-radius: 999px;
      white-space: nowrap;
      line-height: 1.35;
      font-family: 'Plus Jakarta Sans', sans-serif;
    }

    /* ── Proposal Approval Trends Grouped Column Chart ── */
    .chart-container-wrap {
      position: relative;
      width: 100%;
      user-select: none;
    }
    .trends-chart-svg {
      width: 100%;
      height: auto;
      max-height: 220px;
      display: block;
      overflow: visible;
    }
    .chart-grid-line {
      stroke: var(--border);
      stroke-width: 1;
      shape-rendering: crispEdges;
    }
    .chart-axis-line {
      stroke: var(--border);
      stroke-width: 1.5;
      shape-rendering: crispEdges;
    }
    .chart-axis-title {
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      fill: var(--text-muted);
    }
    .chart-axis-text {
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 11px;
      font-weight: 600;
      fill: var(--text-muted);
    }
    .chart-month-label {
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 11px;
      font-weight: 600;
      fill: var(--text-muted);
      transition: fill 0.15s ease;
    }
    .chart-val-text {
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 11px;
      font-weight: 800;
      pointer-events: none;
    }
    .val-approved {
      fill: #15803D;
    }
    .val-returned {
      fill: #DC2626;
    }
    [data-theme="dark"] .val-approved {
      fill: #4ADE80;
    }
    [data-theme="dark"] .val-returned {
      fill: #F87171;
    }
    .chart-bar {
      transition: opacity 0.15s ease, filter 0.15s ease;
      cursor: pointer;
    }
    .chart-bar:hover {
      opacity: 0.88;
      filter: brightness(1.1);
    }
    .bar-approved {
      fill: #16A34A;
    }
    .bar-returned {
      fill: #DC2626;
    }
    [data-theme="dark"] .bar-approved {
      fill: #22C55E;
    }
    [data-theme="dark"] .bar-returned {
      fill: #EF4444;
    }
    .chart-bar-zero {
      fill: var(--border);
      opacity: 0.6;
    }
    [data-theme="dark"] .chart-bar-zero {
      fill: rgba(255, 255, 255, 0.15);
    }
    .chart-hover-slot {
      cursor: pointer;
      transition: fill 0.15s ease;
    }
    .chart-hover-slot:hover,
    .chart-hover-slot.active {
      fill: rgba(2, 132, 199, 0.06);
    }
    [data-theme="dark"] .chart-hover-slot:hover,
    [data-theme="dark"] .chart-hover-slot.active {
      fill: rgba(56, 189, 248, 0.08);
    }
    .legend-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      display: inline-block;
      margin-right: 5px;
      flex-shrink: 0;
    }
    .legend-dot.approved-dot {
      background: #16A34A;
    }
    .legend-dot.returned-dot {
      background: #DC2626;
    }
    [data-theme="dark"] .legend-dot.approved-dot {
      background: #22C55E;
    }
    [data-theme="dark"] .legend-dot.returned-dot {
      background: #EF4444;
    }

    /* Floating Tooltip */
    .chart-tooltip {
      position: absolute;
      pointer-events: none;
      opacity: 0;
      visibility: hidden;
      transform: translate(-50%, -100%);
      transition: opacity 0.15s ease, visibility 0.15s ease;
      background: #0A1628;
      color: #F8FAFC;
      border: 1px solid #1E2D45;
      padding: 8px 12px;
      border-radius: 8px;
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 0.75rem;
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.35);
      z-index: 50;
      white-space: nowrap;
    }
    .chart-tooltip.show {
      opacity: 1;
      visibility: visible;
    }
    [data-theme="dark"] .chart-tooltip {
      background: #15243C;
      border-color: #1E2D45;
      color: #F8FAFC;
    }
    .chart-tooltip-title {
      font-weight: 700;
      margin-bottom: 4px;
      font-size: 0.78rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.12);
      padding-bottom: 3px;
      color: #FFFFFF;
    }
    .chart-tooltip-row {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-top: 3px;
      font-size: 0.74rem;
    }
    .chart-tooltip-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      flex-shrink: 0;
    }

    /* ── KPI Performance Card ── */
    .dashboard-rail .kpi-card {
      padding: 16px 18px !important;
    }
    .kpi-bar {
      height: 8px;
      background: var(--border);
      border-radius: 999px;
      overflow: hidden;
      margin-top: 8px;
    }
    .kpi-fill {
      height: 100%;
      background: var(--accent, var(--sti-blue));
      border-radius: 999px;
      transition: width .5s;
    }

    /* ── My Recent Actions Rail Table ── */
    .rail-table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
      box-sizing: border-box;
    }

    .rail-table th,
    .rail-table td {
      box-sizing: border-box;
      overflow: hidden;
      padding: 8px 10px;
      font-size: 0.76rem;
      vertical-align: middle;
      border-bottom: 1px solid var(--border);
      font-family: 'Plus Jakarta Sans', sans-serif;
    }

    .rail-table th {
      font-size: 0.65rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--text-muted);
      background: var(--bg-base);
      white-space: nowrap;
    }

    .rail-table tbody tr:last-child td {
      border-bottom: none;
    }

    .rail-table tbody tr:hover td {
      background: rgba(2, 132, 199, 0.03);
    }

    .rail-col-act { width: 40%; }
    .rail-col-action { width: 22%; }
    .rail-col-status { width: 23%; }
    .rail-col-date { width: 15%; text-align: right; }
    .rail-table th.rail-col-date { text-align: right; }

    .rail-table td a {
      color: var(--text-main);
      text-decoration: none;
      display: block;
    }

    .rail-table td a:hover .rail-act-title {
      color: var(--accent, var(--sti-blue));
    }

    .rail-act-title {
      font-weight: 600;
      color: var(--text-main);
      display: -webkit-box;
      -webkit-line-clamp: 1;
      -webkit-box-orient: vertical;
      overflow: hidden;
      line-height: 1.3;
      font-family: 'Plus Jakarta Sans', sans-serif;
    }

    .rail-act-faculty {
      font-size: 0.70rem;
      color: var(--text-muted);
      display: block;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      font-family: 'Plus Jakarta Sans', sans-serif;
    }

    .rail-table .badge,
    .rail-table .action-badge {
      padding: 2px 7px;
      font-size: 0.64rem;
      font-weight: 700;
      border-radius: 999px;
      white-space: nowrap;
      display: inline-block;
      line-height: 1.3;
      font-family: 'Plus Jakarta Sans', sans-serif;
    }

    .action-badge.act-approved {
      background: rgba(22, 163, 74, 0.12);
      color: #15803D;
      border: 1px solid rgba(22, 163, 74, 0.25);
    }
    .action-badge.act-returned {
      background: rgba(245, 158, 11, 0.12);
      color: #B45309;
      border: 1px solid rgba(245, 158, 11, 0.25);
    }
    .action-badge.act-rejected {
      background: rgba(239, 68, 68, 0.12);
      color: #DC2626;
      border: 1px solid rgba(239, 68, 68, 0.25);
    }

    .rail-date {
      font-size: 0.70rem;
      color: var(--text-muted);
      white-space: nowrap;
      font-family: 'Plus Jakarta Sans', sans-serif;
    }

    /* ── Dark Mode Contrast & Token Overrides ── */
    [data-theme="dark"] .dashboard-main .card,
    [data-theme="dark"] .dashboard-rail .card {
      background: var(--bg-card);
      border-color: var(--border);
    }
    [data-theme="dark"] .dashboard-main .card-header,
    [data-theme="dark"] .dashboard-rail .card-header {
      background: var(--bg-card);
      border-color: var(--border);
    }
    [data-theme="dark"] .dashboard-main .card-body {
      background: var(--bg-card);
    }
    [data-theme="dark"] .stat-card,
    [data-theme="dark"] a.stat-card,
    [data-theme="dark"] .stat-grid a.stat-card {
      background: var(--bg-card);
      border-color: var(--border);
    }
    [data-theme="dark"] .stat-icon {
      background: rgba(2, 132, 199, 0.22);
    }
    [data-theme="dark"] .stat-icon svg {
      color: #38BDF8;
    }
    [data-theme="dark"] .activity-table th,
    [data-theme="dark"] .rail-table th {
      background: var(--bg-base);
      border-color: var(--border);
      color: var(--text-muted);
    }
    [data-theme="dark"] .activity-table td,
    [data-theme="dark"] .rail-table td {
      border-color: var(--border);
      color: var(--text-main);
    }
    [data-theme="dark"] .activity-table tbody tr:hover td,
    [data-theme="dark"] .rail-table tbody tr:hover td {
      background: rgba(255, 255, 255, 0.03);
    }
    [data-theme="dark"] .topbar select.form-control,
    [data-theme="dark"] .topbar .form-control {
      background: var(--bg-card);
      border-color: var(--border);
      color: var(--text-main);
    }
    [data-theme="dark"] .topbar .btn-outline {
      background: var(--bg-card);
      border-color: var(--border);
      color: var(--text-main);
    }
    [data-theme="dark"] .topbar .btn-outline:hover {
      background: rgba(2, 132, 199, 0.20);
      border-color: #38BDF8;
      color: #7DD3FC;
    }
    [data-theme="dark"] .dashboard-main .card-header .btn-primary,
    [data-theme="dark"] .activity-table td .btn-primary {
      background: var(--accent, #38BDF8);
      border-color: var(--accent, #38BDF8);
      color: #fff;
    }
    [data-theme="dark"] .dashboard-main .card-header .btn-primary:hover,
    [data-theme="dark"] .activity-table td .btn-primary:hover {
      box-shadow: 0 2px 8px rgba(56, 189, 248, 0.35);
      filter: brightness(1.1);
    }
    [data-theme="dark"] .kpi-fill {
      background: var(--accent, #38BDF8);
    }
    [data-theme="dark"] .action-badge.act-approved {
      background: rgba(34, 197, 94, 0.15);
      color: #4ADE80;
      border-color: rgba(34, 197, 94, 0.35);
    }
    [data-theme="dark"] .action-badge.act-returned {
      background: rgba(245, 158, 11, 0.15);
      color: #FBBF24;
      border-color: rgba(245, 158, 11, 0.35);
    }
    [data-theme="dark"] .action-badge.act-rejected {
      background: rgba(239, 68, 68, 0.15);
      color: #F87171;
      border-color: rgba(239, 68, 68, 0.35);
    }
    [data-theme="dark"] .kpi-bar {
      background: var(--border);
    }

    /* ── Responsive adjustments ── */
    @media (max-width: 1024px) {
      .dashboard-layout {
        grid-template-columns: 1fr;
        gap: 20px;
      }
      .dashboard-rail-sticky {
        position: static;
      }
    }

    @media (max-width: 850px) {
      .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 10px;
      }
      .col-source {
        display: none;
      }
      .col-title {
        width: 44%;
      }
      .col-faculty {
        width: 24%;
      }
      .col-status {
        width: 18%;
      }
      .col-actions {
        width: 14%;
      }
    }

    @media (max-width: 640px) {
      .content {
        padding: 16px;
      }
      .col-faculty {
        display: none;
      }
      .col-title {
        width: 55%;
      }
      .col-status {
        width: 25%;
      }
      .col-actions {
        width: 20%;
      }
    }
  </style>
</head>

<body class="theme-arjay">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">Review Dashboard — Sir Ar-jay</div>
      <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
        <!-- Year and Reporting Period Filter -->
        <form method="GET" style="display:flex;gap:6px;align-items:center;">
          <select name="year" class="form-control" style="width:90px;padding:5px 8px;font-size:0.82rem;">
            <?php foreach ($availableYears as $y): ?>
              <option value="<?= $y ?>" <?= $selectedYear === $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endforeach; ?>
          </select>
          <select name="period" class="form-control" style="width:145px;padding:5px 8px;font-size:0.82rem;">
            <option value="all" <?= $selectedPeriod === 'all' ? 'selected' : '' ?>>Whole Year</option>
            <option value="1st_sem" <?= $selectedPeriod === '1st_sem' ? 'selected' : '' ?>>1st Sem (Jun–Oct)</option>
            <option value="2nd_sem" <?= $selectedPeriod === '2nd_sem' ? 'selected' : '' ?>>2nd Sem (Nov–Mar)</option>
          </select>
          <button type="submit" class="btn btn-outline btn-sm" style="padding:5px 10px;font-size:0.8rem;">Apply</button>
        </form>

        <!-- Topbar Notification Component & Free-standing Theme Toggle -->
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
        <!-- User Profile Control -->
        <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
      </div>
    </header>
  <div class="content">

    <div class="dashboard-layout">
      <!-- Main Monitoring & Review Area (~68%) -->
      <div class="dashboard-main">

        <!-- Stat Cards -->
        <div class="stat-grid" style="margin-bottom:0;">
          <a href="<?= BASE_URL ?>/admin1/pending.php" class="stat-card stat-card-pending">
            <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
            <div>
              <div class="stat-val"><?= $stats['pending'] ?? 0 ?></div>
              <div class="stat-label">Pending Reviews</div>
            </div>
            <div class="stat-strip stat-strip-pending"></div>
          </a>
          <a href="<?= BASE_URL ?>/admin1/approved.php" class="stat-card stat-card-approved">
            <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
            <div>
              <div class="stat-val"><?= $stats['approved'] ?? 0 ?></div>
              <div class="stat-label">Approved</div>
            </div>
            <div class="stat-strip stat-strip-approved"></div>
          </a>
          <a href="<?= BASE_URL ?>/admin1/returned.php" class="stat-card stat-card-returned">
            <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></div>
            <div>
              <div class="stat-val"><?= $stats['returned'] ?? 0 ?></div>
              <div class="stat-label">Returned for Revision</div>
            </div>
            <div class="stat-strip stat-strip-returned"></div>
          </a>
          <a href="<?= BASE_URL ?>/admin1/activities.php?status=rejected" class="stat-card stat-card-rejected">
            <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
            <div>
              <div class="stat-val"><?= $stats['rejected'] ?? 0 ?></div>
              <div class="stat-label">Rejected</div>
            </div>
            <div class="stat-strip stat-strip-rejected"></div>
          </a>
        </div>

        <!-- Review Queue -->
        <div class="card">
          <div class="card-header">
            <h2>📥 Review Queue</h2>
            <a href="<?= BASE_URL ?>/admin1/pending.php" class="btn btn-primary btn-sm">View All</a>
          </div>
          <div class="card-body" style="padding:0;">
            <div class="table-wrap">
              <table class="activity-table">
                <thead><tr><th class="col-title">Activity</th><th class="col-faculty">Faculty</th><th class="col-source">Source</th><th class="col-status">Status</th><th class="col-actions">Action</th></tr></thead>
                <tbody>
                  <?php if (empty($pending)): ?>
                  <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--text-muted);">Queue is clear ✓</td></tr>
                  <?php else: foreach ($pending as $p): ?>
                  <tr>
                    <td class="col-title">
                      <div class="act-title"><?= htmlspecialchars($p['title']) ?></div>
                      <span class="act-date"><?= $p['event_date'] ? date('M j, Y', strtotime($p['event_date'])) : '—' ?></span>
                    </td>
                    <td class="col-faculty"><span class="act-faculty"><?= htmlspecialchars($p['faculty_name']) ?></span></td>
                    <td class="col-source"><span class="badge badge-secondary"><?= ucfirst(str_replace('_',' ',$p['source'])) ?></span></td>
                    <td class="col-status"><?= getStatusBadge($p['status']) ?></td>
                    <td class="col-actions"><div class="act-actions"><a href="<?= BASE_URL ?>/admin1/review.php?id=<?= $p['id'] ?>" class="btn btn-primary btn-sm">Review</a></div></td>
                  </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Approval Trends Card -->
        <div class="card">
          <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h2>📈 Proposal Approval Trends — <?= $selectedYear ?></h2>
            <span class="text-xs text-muted">
              Approved: <strong class="chart-summary-approved" style="color:#16A34A;"><?= $approvalTrends['total_approved'] ?></strong> &bull; 
              Returned/Rejected: <strong class="chart-summary-returned" style="color:#DC2626;"><?= $approvalTrends['total_returned'] ?></strong>
            </span>
          </div>
          <div class="card-body chart-body">
            <?php
            // Ensure all 12 calendar months (Jan–Dec) are represented for the grouped column chart
            $trendLookup = [];
            if (!empty($approvalTrends['months'])) {
                foreach ($approvalTrends['months'] as $tm) {
                    $trendLookup[(int)$tm['month_num']] = $tm;
                }
            }
            $chartMaxVal = 0;
            $chartMonths = [];
            for ($m = 1; $m <= 12; $m++) {
                $mName = date('M', mktime(0, 0, 0, $m, 1));
                $appCount = (int)($trendLookup[$m]['approved'] ?? 0);
                $retCount = (int)($trendLookup[$m]['returned'] ?? 0);
                $chartMaxVal = max($chartMaxVal, $appCount, $retCount);
                $chartMonths[] = [
                    'num' => $m,
                    'name' => $mName,
                    'approved' => $appCount,
                    'returned' => $retCount
                ];
            }

            // Automatic Y-axis scaling based on maximum series value
            if ($chartMaxVal <= 4) {
                $yMax = 4;
                $yTicks = [0, 1, 2, 3, 4];
            } elseif ($chartMaxVal <= 8) {
                $yMax = (int)(ceil($chartMaxVal / 2) * 2);
                $yTicks = range(0, $yMax, 2);
            } elseif ($chartMaxVal <= 20) {
                $yMax = (int)(ceil($chartMaxVal / 5) * 5);
                $yTicks = range(0, $yMax, 5);
            } else {
                $step = max(5, (int)(ceil($chartMaxVal / 4 / 5) * 5));
                $yMax = (int)(ceil($chartMaxVal / $step) * $step);
                $yTicks = range(0, $yMax, $step);
            }

            $svgW = 720;
            $svgH = 210;
            $plotLeft = 45;
            $plotRight = 700;
            $plotTop = 25;
            $plotBottom = 175;
            $plotW = $plotRight - $plotLeft;
            $plotH = $plotBottom - $plotTop;
            $colW = $plotW / 12;
            $barW = 12;
            $barGap = 4;
            ?>
            <div class="chart-container-wrap">
              <svg viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" preserveAspectRatio="xMidYMid meet" class="trends-chart-svg" aria-label="Proposal Approval Trends Grouped Column Chart">
                <!-- Y-Axis Title -->
                <text x="<?= $plotLeft ?>" y="14" class="chart-axis-title">PROPOSALS</text>

                <!-- Horizontal Grid Lines and Y-Axis Ticks -->
                <?php foreach ($yTicks as $tick): 
                  $yPos = $plotBottom - ($tick / $yMax) * $plotH;
                ?>
                  <line x1="<?= $plotLeft ?>" y1="<?= $yPos ?>" x2="<?= $plotRight ?>" y2="<?= $yPos ?>" class="chart-grid-line" stroke-dasharray="<?= $tick > 0 ? '4 4' : 'none' ?>" opacity="<?= $tick > 0 ? '0.6' : '1' ?>" />
                  <text x="<?= $plotLeft - 9 ?>" y="<?= $yPos + 4 ?>" text-anchor="end" class="chart-axis-text"><?= $tick ?></text>
                <?php endforeach; ?>

                <!-- Baseline Axis -->
                <line x1="<?= $plotLeft ?>" y1="<?= $plotBottom ?>" x2="<?= $plotRight ?>" y2="<?= $plotBottom ?>" class="chart-axis-line" />

                <!-- Month Columns (Grouped Bars) -->
                <?php foreach ($chartMonths as $i => $cm): 
                  $centerX = $plotLeft + ($i + 0.5) * $colW;
                  $xApp = $centerX - $barW - ($barGap / 2);
                  $xRet = $centerX + ($barGap / 2);
                  $slotX = $plotLeft + $i * $colW;

                  // Approved bar height and position
                  $appH = $cm['approved'] > 0 ? max(6, round(($cm['approved'] / $yMax) * $plotH)) : 0;
                  $appY = $plotBottom - $appH;

                  // Returned/Rejected bar height and position
                  $retH = $cm['returned'] > 0 ? max(6, round(($cm['returned'] / $yMax) * $plotH)) : 0;
                  $retY = $plotBottom - $retH;
                ?>
                  <g class="month-column-group" data-month-index="<?= $i ?>">
                    <!-- Approved Bar (Green) -->
                    <?php if ($cm['approved'] > 0): ?>
                      <rect x="<?= $xApp ?>" y="<?= $appY ?>" width="<?= $barW ?>" height="<?= $appH ?>" rx="3" class="chart-bar bar-approved">
                        <title><?= $cm['name'] ?>: <?= $cm['approved'] ?> Approved</title>
                      </rect>
                      <text x="<?= $xApp + ($barW / 2) ?>" y="<?= $appY - 5 ?>" text-anchor="middle" class="chart-val-text val-approved"><?= $cm['approved'] ?></text>
                    <?php else: ?>
                      <rect x="<?= $xApp ?>" y="<?= $plotBottom - 2 ?>" width="<?= $barW ?>" height="2" rx="1" class="chart-bar-zero" />
                    <?php endif; ?>

                    <!-- Returned/Rejected Bar (Red) -->
                    <?php if ($cm['returned'] > 0): ?>
                      <rect x="<?= $xRet ?>" y="<?= $retY ?>" width="<?= $barW ?>" height="<?= $retH ?>" rx="3" class="chart-bar bar-returned">
                        <title><?= $cm['name'] ?>: <?= $cm['returned'] ?> Returned/Rejected</title>
                      </rect>
                      <text x="<?= $xRet + ($barW / 2) ?>" y="<?= $retY - 5 ?>" text-anchor="middle" class="chart-val-text val-returned"><?= $cm['returned'] ?></text>
                    <?php else: ?>
                      <rect x="<?= $xRet ?>" y="<?= $plotBottom - 2 ?>" width="<?= $barW ?>" height="2" rx="1" class="chart-bar-zero" />
                    <?php endif; ?>

                    <!-- Month Label on X-Axis -->
                    <text x="<?= $centerX ?>" y="<?= $plotBottom + 20 ?>" text-anchor="middle" class="chart-month-label"><?= $cm['name'] ?></text>

                    <!-- Transparent Column Hover Slot for Tooltip Interaction -->
                    <rect x="<?= $slotX ?>" y="<?= $plotTop ?>" width="<?= $colW ?>" height="<?= $plotH + 28 ?>" class="chart-hover-slot" data-month="<?= $cm['name'] ?>" data-year="<?= $selectedYear ?>" data-approved="<?= $cm['approved'] ?>" data-returned="<?= $cm['returned'] ?>" fill="rgba(0,0,0,0.0001)" />
                  </g>
                <?php endforeach; ?>
              </svg>

              <!-- Floating Tooltip Popover -->
              <div class="chart-tooltip" id="chartTooltip" role="tooltip" aria-hidden="true">
                <div class="chart-tooltip-title" id="ttMonth">Month Year</div>
                <div class="chart-tooltip-row">
                  <span class="chart-tooltip-dot" style="background:#16A34A;"></span>
                  <span>Approved: <strong id="ttApproved">0</strong></span>
                </div>
                <div class="chart-tooltip-row">
                  <span class="chart-tooltip-dot" style="background:#DC2626;"></span>
                  <span>Returned / Rejected: <strong id="ttReturned">0</strong></span>
                </div>
              </div>
            </div>

            <!-- Legend -->
            <div class="flex gap-3 text-xs" style="margin-top:12px;justify-content:center;">
              <span style="display:inline-flex;align-items:center;"><span class="legend-dot approved-dot"></span>Approved Proposals</span>
              <span style="display:inline-flex;align-items:center;"><span class="legend-dot returned-dot"></span>Returned / Rejected</span>
            </div>
          </div>
        </div>

      </div><!-- dashboard-main -->

      <!-- Right Secondary / Monitoring Rail (~32%) -->
      <div class="dashboard-rail">
        <div class="dashboard-rail-sticky">

          <!-- KPI Performance Card -->
          <div class="card kpi-card">
            <div class="flex items-center justify-between" style="margin-bottom:8px;">
              <span class="text-sm fw-bold" style="color:var(--text-main);">Overall KPI Performance</span>
              <?php if (!empty($kpi['avg_rating'])): ?>
                <span class="badge badge-success"><?= number_format($kpi['avg_rating'], 2) ?> / 4.00</span>
              <?php else: ?>
                <span class="badge badge-secondary">Insufficient Data</span>
              <?php endif; ?>
            </div>
            <div class="kpi-bar">
              <div class="kpi-fill" style="width:<?= !empty($kpi['avg_rating']) ? min(100, round(($kpi['avg_rating'] / 4) * 100)) : 0 ?>%;"></div>
            </div>
            <div class="text-sm text-muted" style="margin-top:8px;line-height:1.45;">
              <?php if (!empty($kpi['avg_rating'])): ?>
                <?= (int)$kpi['events_evaluated'] ?> evaluated of <?= (int)$kpi['completed_activities'] ?> completed activity<?= $kpi['completed_activities'] == 1 ? '' : 'ies' ?>
                <?php if (!empty($kpi['target_att']) && $kpi['target_att'] > 0 && isset($kpi['actual_att'])): ?>
                  <br>Attendance: <?= round(($kpi['actual_att'] / $kpi['target_att']) * 100, 1) ?>% (<?= $kpi['actual_att'] ?>/<?= $kpi['target_att'] ?>)
                <?php endif; ?>
              <?php else: ?>
                No completed student organization evaluations for the selected period
              <?php endif; ?>
            </div>
          </div>

          <!-- Recent Actions -->
          <div class="card">
            <div class="card-header">
              <h2>📋 My Recent Actions</h2>
            </div>
            <div class="card-body" style="padding:0;">
              <div class="table-wrap">
                <table class="rail-table">
                  <thead>
                    <tr>
                      <th class="rail-col-act">Activity</th>
                      <th class="rail-col-action">Action</th>
                      <th class="rail-col-status">Status</th>
                      <th class="rail-col-date">Date</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($actions)): ?>
                      <tr>
                        <td colspan="4" style="text-align:center;padding:24px;color:var(--text-muted);">No actions yet.</td>
                      </tr>
                      <?php else: foreach ($actions as $a): ?>
                        <tr>
                          <td class="rail-col-act"><a href="<?= BASE_URL ?>/admin1/view-activity.php?id=<?= $a['activity_id'] ?>"><span class="rail-act-title"><?= htmlspecialchars($a['title']) ?></span><span class="rail-act-faculty"><?= htmlspecialchars($a['faculty_name']) ?></span></a></td>
                          <td class="rail-col-action"><span class="action-badge act-<?= $a['action'] === 'forwarded' ? 'approved' : $a['action'] ?>"><?= ucfirst($a['action']) ?></span></td>
                          <td class="rail-col-status"><?= getStatusBadge($a['current_status']) ?></td>
                          <td class="rail-col-date"><span class="rail-date"><?= date('M j', strtotime($a['acted_at'])) ?></span></td>
                        </tr>
                    <?php endforeach;
                    endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

        </div><!-- dashboard-rail-sticky -->
      </div><!-- dashboard-rail -->

    </div><!-- dashboard-layout -->

  </div>

  <!-- Interactive Tooltip Script for Proposal Approval Trends Chart -->
  <script>
    (function() {
      const container = document.querySelector('.chart-container-wrap');
      const tooltip = document.getElementById('chartTooltip');
      if (!container || !tooltip) return;

      const slots = container.querySelectorAll('.chart-hover-slot');
      slots.forEach(slot => {
        slot.addEventListener('mouseenter', function() {
          const month = this.getAttribute('data-month');
          const year = this.getAttribute('data-year');
          const approved = this.getAttribute('data-approved');
          const returned = this.getAttribute('data-returned');

          const ttMonth = document.getElementById('ttMonth');
          const ttApproved = document.getElementById('ttApproved');
          const ttReturned = document.getElementById('ttReturned');

          if (ttMonth) ttMonth.textContent = month + ' ' + year;
          if (ttApproved) ttApproved.textContent = approved;
          if (ttReturned) ttReturned.textContent = returned;

          tooltip.classList.add('show');
          tooltip.setAttribute('aria-hidden', 'false');
        });

        slot.addEventListener('mousemove', function(e) {
          const rect = container.getBoundingClientRect();
          const x = e.clientX - rect.left;
          const y = e.clientY - rect.top;
          const tooltipWidth = tooltip.offsetWidth || 140;
          const halfWidth = tooltipWidth / 2;
          const clampedX = Math.max(halfWidth + 8, Math.min(rect.width - halfWidth - 8, x));
          tooltip.style.left = clampedX + 'px';
          if (y < 70) {
            tooltip.style.top = (y + 16) + 'px';
            tooltip.style.transform = 'translate(-50%, 0)';
          } else {
            tooltip.style.top = (y - 12) + 'px';
            tooltip.style.transform = 'translate(-50%, -100%)';
          }
        });

        slot.addEventListener('mouseleave', function() {
          tooltip.classList.remove('show');
          tooltip.setAttribute('aria-hidden', 'true');
        });
      });
    })();
  </script>
</body>

</html>