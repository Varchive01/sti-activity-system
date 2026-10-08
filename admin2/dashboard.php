<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';
requireRole('admin2');
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

// Approval Trends for Selected Period (monitored activities)
$approvalTrends = getApprovalTrends($selectedYear, $selectedPeriod, $db);

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
    SUM(a.status IN ('submitted','endorsed','resubmitted')) as pending,
    SUM(a.status='approved') as approved,
    SUM(a.status='returned_for_revision') as returned,
    SUM(a.status='rejected') as rejected,
    SUM(a.status='completed') as completed,
    COUNT(*) as total
  FROM activities a
  WHERE {$whereClause}
");
$statsStmt->execute($params);
$stats = $statsStmt->fetch() ?: [];

$pendingStmt = $db->prepare("
  SELECT a.*, u.name as faculty_name FROM activities a
  JOIN users u ON a.faculty_id=u.id
  WHERE a.status IN ('submitted','endorsed','resubmitted') AND {$whereClause}
  ORDER BY a.submitted_at DESC LIMIT 10
");
$pendingStmt->execute($params);
$pending = $pendingStmt->fetchAll();

$upcoming = $db->query("
  SELECT * FROM activities WHERE status='approved' AND event_date >= CURDATE()
  ORDER BY event_date ASC LIMIT 5
")->fetchAll();

// KPI averages
$kpiStmt = $db->prepare("
  SELECT AVG(k.rating) as avg_rating, COUNT(DISTINCT k.activity_id) as events_evaluated
  FROM kpi_evaluations k
  JOIN activities a ON k.activity_id = a.id
  WHERE {$whereClause}
");
$kpiStmt->execute($params);
$kpi = $kpiStmt->fetch() ?: [];

$recent_actions = $db->prepare("
  SELECT al.*, a.title, a.status as current_status, a.id as activity_id, u.name as faculty_name FROM approval_logs al
  JOIN activities a ON al.activity_id=a.id
  JOIN users u ON a.faculty_id=u.id
  WHERE al.reviewer_id=? AND {$whereClause}
  ORDER BY al.acted_at DESC LIMIT 6
");
$recent_actions->execute(array_merge([$user['id']], $params));
$actions = $recent_actions->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sir Ian Dashboard – STI Activity System</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=1.0.4">
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
    /* ── Institutional Color Scheme Alignment (Match Faculty Baseline) ── */
    :root,
    body.theme-ian,
    .theme-ian,
    aside.sidebar.theme-ian {
      --accent: var(--sti-blue, #0284C7);
      --accent-lt: #E0F2FE;
    }

    [data-theme="dark"] body.theme-ian,
    [data-theme="dark"] .theme-ian,
    [data-theme="dark"] aside.sidebar.theme-ian {
      --accent: #38BDF8;
      --accent-lt: rgba(2, 132, 199, 0.22);
    }

    /* Active Sidebar Nav Item (STI Blue matching Faculty) */
    .sidebar-nav a.active,
    aside.sidebar.theme-ian .sidebar-nav a.active {
      background: var(--accent, var(--sti-blue)) !important;
      color: #fff !important;
      font-weight: 600;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    }

    [data-theme="dark"] .sidebar-nav a.active,
    [data-theme="dark"] aside.sidebar.theme-ian .sidebar-nav a.active {
      background: var(--accent, #38BDF8) !important;
      color: #fff !important;
    }

    /* ── Typography Standardization: Plus Jakarta Sans Everywhere (No Syne) ── */
    body.theme-faculty,
    body.theme-faculty *,
    body.theme-faculty *::before,
    body.theme-faculty *::after,
    body.theme-ian,
    body.theme-ian *,
    body.theme-ian *::before,
    body.theme-ian *::after,
    body,
    h1, h2, h3, h4, h5, h6,
    .page-title,
    .topbar .page-title,
    .brand,
    .sidebar-logo .brand,
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
    .event-date-badge,
    .event-date-badge .day,
    .event-date-badge .mon,
    label, input, button, select, textarea {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
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

    /* ── Topbar Filter & Form Controls ── */
    .topbar select.form-control,
    .topbar .form-control {
      background: var(--bg-card);
      color: var(--text-main);
      border: 1px solid var(--border);
      border-radius: 8px;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
      font-size: 0.82rem;
      font-weight: 500;
      height: 32px;
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
      font-family: 'Plus Jakarta Sans', sans-serif !important;
      font-size: 0.80rem;
      font-weight: 600;
      height: 32px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: all .15s ease;
    }
    .topbar .btn-outline:hover {
      border-color: var(--accent, var(--sti-blue));
      color: var(--accent, var(--sti-blue));
      background: var(--accent-lt, rgba(2, 132, 199, 0.08));
    }

    /* Dark Mode Topbar Form Controls */
    [data-theme="dark"] select,
    [data-theme="dark"] input,
    [data-theme="dark"] select.form-control,
    [data-theme="dark"] .topbar select.form-control,
    [data-theme="dark"] .form-control {
      background: var(--bg-card) !important;
      background-color: var(--bg-card) !important;
      color: var(--text-main) !important;
      border-color: var(--border) !important;
      color-scheme: dark;
    }
    [data-theme="dark"] select option,
    [data-theme="dark"] .topbar select option {
      background: var(--bg-card) !important;
      background-color: var(--bg-card) !important;
      color: var(--text-main) !important;
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

    /* ── 1. Compact Horizontal KPI Stat Cards (Exact Faculty Parity) ── */
    .stat-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
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

    /* Light mode icon badges */
    .stat-card-pending .stat-icon { background: rgba(245, 158, 11, 0.12); }
    .stat-card-pending .stat-icon svg { color: #d97706; }
    .stat-card-approved .stat-icon { background: rgba(16, 185, 129, 0.12); }
    .stat-card-approved .stat-icon svg { color: #059669; }
    .stat-card-completed .stat-icon { background: rgba(2, 132, 199, 0.1); }
    .stat-card-completed .stat-icon svg { color: var(--accent, #0284c7); }
    .stat-card-kpi .stat-icon { background: rgba(2, 132, 199, 0.1); }
    .stat-card-kpi .stat-icon svg { color: var(--accent, #0284c7); }

    /* Dark mode icon badges */
    [data-theme="dark"] .stat-card-pending .stat-icon { background: rgba(251, 191, 36, 0.15); }
    [data-theme="dark"] .stat-card-pending .stat-icon svg { color: #fbbf24; }
    [data-theme="dark"] .stat-card-approved .stat-icon { background: rgba(52, 211, 153, 0.15); }
    [data-theme="dark"] .stat-card-approved .stat-icon svg { color: #34d399; }
    [data-theme="dark"] .stat-card-completed .stat-icon { background: rgba(56, 189, 248, 0.15); }
    [data-theme="dark"] .stat-card-completed .stat-icon svg { color: #38bdf8; }
    [data-theme="dark"] .stat-card-kpi .stat-icon { background: rgba(56, 189, 248, 0.15); }
    [data-theme="dark"] .stat-card-kpi .stat-icon svg { color: #38bdf8; }

    .stat-card > div:not(.stat-icon):not(.stat-strip) {
      min-width: 0;
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      justify-content: center;
      text-align: left;
    }

    a.stat-card .stat-val,
    .stat-grid a.stat-card .stat-val {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
      font-size: 1.38rem;
      font-weight: 800;
      line-height: 1;
      color: var(--text-main) !important;
      letter-spacing: -0.02em;
    }

    a.stat-card .stat-label,
    .stat-grid a.stat-card .stat-label {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
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

    /* Bottom accent strip */
    .stat-strip {
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      height: 2.5px;
      display: flex;
      border-bottom-left-radius: 9px;
      border-bottom-right-radius: 9px;
      overflow: hidden;
    }

    .stat-strip-pending { background: #f59e0b; }
    .stat-strip-approved { background: #10b981; }
    .stat-strip-completed { background: #0284c7; }
    [data-theme="dark"] .stat-strip-completed { background: #38bdf8; }
    .stat-strip-kpi { background: #0284c7; }
    [data-theme="dark"] .stat-strip-kpi { background: #38bdf8; }

    /* ── 2. Shared Dashboard Card Styling (Exact Faculty Parity) ── */
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
      flex-wrap: wrap;
    }

    .dashboard-main .card-header h2,
    .dashboard-rail .card-header h2 {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
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

    .dashboard-main .card-header .btn-primary,
    .dashboard-rail .card-header .btn-primary {
      padding: 4px 12px;
      height: 28px;
      font-size: 0.75rem;
      font-weight: 600;
      border-radius: 7px;
      border: 1px solid var(--accent, var(--sti-blue));
      color: #fff;
      background: var(--accent, var(--sti-blue));
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      text-decoration: none;
      transition: all 0.15s ease;
      white-space: nowrap;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }

    .dashboard-main .card-header .btn-primary:hover,
    .dashboard-rail .card-header .btn-primary:hover {
      filter: brightness(1.08);
      box-shadow: 0 2px 8px rgba(2, 132, 199, 0.25);
    }

    .dashboard-main .card-header .btn-outline,
    .dashboard-rail .card-header .btn-outline {
      padding: 4px 12px;
      height: 28px;
      font-size: 0.75rem;
      font-weight: 600;
      border-radius: 7px;
      border: 1px solid var(--border);
      color: var(--text-secondary);
      background: transparent;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      transition: all 0.15s ease;
      white-space: nowrap;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }

    .dashboard-main .card-header .btn-outline:hover,
    .dashboard-rail .card-header .btn-outline:hover {
      border-color: var(--accent, var(--sti-blue));
      color: var(--accent, var(--sti-blue));
      background: var(--accent-lt, rgba(2, 132, 199, 0.08));
    }

    .dashboard-main .card-body {
      background: var(--bg-card);
    }

    .dashboard-main .card-body.chart-body {
      padding: 16px 20px;
    }

    /* ── 3. Table Styling (Exact Faculty Parity) ── */
    .dashboard-main .table-wrap,
    .dashboard-rail .table-wrap {
      border: none;
      border-radius: 0;
      background: transparent;
      overflow-x: auto;
      scrollbar-width: thin;
    }

    .activity-table,
    .rail-table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
      box-sizing: border-box;
    }

    .activity-table th,
    .activity-table td,
    .rail-table th,
    .rail-table td {
      box-sizing: border-box;
      overflow: hidden;
    }

    .activity-table th,
    .rail-table th {
      padding: 9px 8px;
      font-size: 0.74rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--text-muted);
      border-bottom: 1px solid var(--border);
      background: var(--border-light, #F8FAFD);
      text-align: left;
      white-space: nowrap;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }

    [data-theme="dark"] .activity-table th,
    [data-theme="dark"] .rail-table th {
      background: rgba(255, 255, 255, 0.03);
    }

    .activity-table td,
    .rail-table td {
      padding: 9px 8px;
      border-bottom: 1px solid var(--border);
      vertical-align: middle;
      color: var(--text-main);
      font-size: 0.85rem;
      transition: background 0.12s ease;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }

    .activity-table tbody tr:last-child td,
    .rail-table tbody tr:last-child td {
      border-bottom: none;
    }

    .activity-table tbody tr:hover td,
    .rail-table tbody tr:hover td {
      background: rgba(2, 132, 199, 0.03);
    }

    [data-theme="dark"] .activity-table tbody tr:hover td,
    [data-theme="dark"] .rail-table tbody tr:hover td {
      background: rgba(255, 255, 255, 0.03);
    }

    .col-title { width: 34%; }
    .col-faculty { width: 20%; }
    .col-source { width: 14%; }
    .col-status { width: 17%; }
    .col-actions { width: 15%; text-align: right; }
    .activity-table th.col-actions { text-align: right; padding-right: 12px; }
    .activity-table td.col-actions { text-align: right; padding-right: 12px; }

    .act-title {
      font-weight: 600;
      color: var(--text-main);
      font-size: 0.90rem;
      line-height: 1.3;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
      word-break: break-word;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }

    .act-date {
      color: var(--text-secondary);
      font-size: 0.85rem;
      font-weight: 500;
      line-height: 1.25;
      white-space: nowrap;
      margin-top: 2px;
      display: inline-block;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }

    .act-faculty {
      font-weight: 500;
      color: var(--text-secondary);
      font-size: 0.85rem;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      display: block;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }

    .act-actions {
      display: inline-flex;
      align-items: center;
      justify-content: flex-end;
      gap: 4px;
      white-space: nowrap;
    }

    .activity-table td .btn {
      padding: 3px 8px;
      height: 26px;
      font-size: 0.72rem;
      font-weight: 600;
      border-radius: 6px;
      gap: 3px;
      box-sizing: border-box;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
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
      font-size: 0.74rem;
      font-weight: 700;
      border-radius: 999px;
      white-space: nowrap;
      line-height: 1.3;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }

    /* ── Proposal Approval Trends Chart ── */
    .month-bar-wrap {
      display: flex;
      align-items: flex-end;
      gap: 8px;
      height: 130px;
      margin: 16px 0 10px;
    }
    .month-bar-col {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      height: 100%;
    }
    .month-bar-inner {
      flex: 1;
      width: 100%;
      display: flex;
      align-items: flex-end;
      justify-content: center;
      gap: 3px;
    }
    .month-bar {
      width: 14px;
      border-radius: 4px 4px 0 0;
      min-width: 0;
      transition: opacity .18s ease, transform .18s ease;
      cursor: default;
      position: relative;
    }
    .month-bar.approved {
      background: #16A34A;
    }
    .month-bar.returned {
      background: #DC2626;
    }
    .month-bar:hover {
      opacity: .85;
      transform: scaleY(1.03);
      transform-origin: bottom;
    }
    .month-label {
      text-align: center;
      font-size: .68rem;
      color: var(--text-muted);
      font-weight: 700;
      margin-top: 8px;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }
    .month-val {
      position: absolute;
      top: -18px;
      left: 50%;
      transform: translateX(-50%);
      font-size: .62rem;
      font-weight: 700;
      white-space: nowrap;
      color: var(--text-main);
      font-family: 'Plus Jakarta Sans', sans-serif !important;
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
    [data-theme="dark"] .legend-dot.approved-dot,
    [data-theme="dark"] .month-bar.approved {
      background: #22C55E;
    }
    [data-theme="dark"] .legend-dot.returned-dot,
    [data-theme="dark"] .month-bar.returned {
      background: #EF4444;
    }

    /* ── Right Rail: KPI Card, Upcoming Events, Recent Actions ── */
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

    /* Upcoming Events */
    .upcoming-event {
      padding: 11px 16px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .upcoming-event:last-child {
      border-bottom: none;
    }
    .event-date-badge {
      background: var(--accent-lt, #E0F2FE);
      color: var(--accent, var(--sti-blue));
      border-radius: 8px;
      padding: 6px 10px;
      text-align: center;
      min-width: 48px;
      flex-shrink: 0;
    }
    .event-date-badge .day {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
      font-size: 1.25rem;
      font-weight: 800;
      line-height: 1;
    }
    .event-date-badge .mon {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
      font-size: 0.65rem;
      font-weight: 700;
      text-transform: uppercase;
      line-height: 1.1;
      margin-top: 2px;
    }

    /* My Recent Actions Rail Table */
    .rail-col-act { width: 40%; }
    .rail-col-action { width: 22%; }
    .rail-col-status { width: 23%; }
    .rail-col-date { width: 15%; text-align: right; }
    .rail-table th.rail-col-date { text-align: right; padding-right: 10px; }
    .rail-table td.rail-col-date { text-align: right; padding-right: 10px; }

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
      font-size: 0.85rem;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }

    .rail-act-faculty {
      font-size: 0.75rem;
      color: var(--text-muted);
      display: block;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      margin-top: 2px;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }

    .rail-table .badge,
    .rail-table .action-badge {
      padding: 2px 7px;
      font-size: 0.68rem;
      font-weight: 700;
      border-radius: 999px;
      white-space: nowrap;
      display: inline-block;
      line-height: 1.3;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
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
      font-size: 0.72rem;
      color: var(--text-muted);
      white-space: nowrap;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
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
    [data-theme="dark"] .event-date-badge {
      background: rgba(2, 132, 199, 0.22);
      color: #38BDF8;
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

    /* ── Responsive Adjustments ── */
    @media (max-width: 1024px) {
      .dashboard-layout {
        grid-template-columns: 1fr;
        gap: 20px;
      }
      .dashboard-rail-sticky {
        position: static;
      }
      .stat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 12px;
      }
      .stat-card,
      a.stat-card,
      .stat-grid a.stat-card {
        width: 100%;
        min-width: 0;
        height: 68px;
        min-height: 68px;
        max-height: 68px;
        padding: 8px 12px;
      }
      .stat-icon {
        width: 32px;
        height: 32px;
        min-width: 32px;
      }
      .stat-icon svg {
        width: 14px;
        height: 14px;
      }
      a.stat-card .stat-val,
      .stat-grid a.stat-card .stat-val {
        font-size: 1.25rem;
      }
      a.stat-card .stat-label,
      .stat-grid a.stat-card .stat-label {
        font-size: 0.70rem;
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
      .stat-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
      }
      .stat-card,
      a.stat-card,
      .stat-grid a.stat-card {
        width: 100%;
        min-width: 0;
        height: 66px;
        min-height: 66px;
        max-height: 66px;
        padding: 8px 10px;
      }
      .stat-icon {
        width: 30px;
        height: 30px;
        min-width: 30px;
      }
      .stat-icon svg {
        width: 14px;
        height: 14px;
      }
      a.stat-card .stat-val,
      .stat-grid a.stat-card .stat-val {
        font-size: 1.15rem;
      }
      a.stat-card .stat-label,
      .stat-grid a.stat-card .stat-label {
        font-size: 0.68rem;
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
<body class="theme-faculty theme-ian">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">Review Dashboard — Sir Ian</div>
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

      <!-- Main Content Column (~68%) -->
      <div class="dashboard-main">

        <!-- Stats -->
        <div class="stat-grid" style="margin-bottom:0;">
          <a href="<?= BASE_URL ?>/admin2/pending.php" class="stat-card stat-card-pending">
            <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
            <div>
              <div class="stat-val"><?= $stats['pending'] ?? 0 ?></div>
              <div class="stat-label">Awaiting Review</div>
            </div>
            <div class="stat-strip stat-strip-pending"></div>
          </a>
          <a href="<?= BASE_URL ?>/admin2/approved.php" class="stat-card stat-card-approved">
            <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
            <div>
              <div class="stat-val"><?= $stats['approved'] ?? 0 ?></div>
              <div class="stat-label">Approved Activities</div>
            </div>
            <div class="stat-strip stat-strip-approved"></div>
          </a>
          <a href="<?= BASE_URL ?>/admin2/activities.php?status=completed" class="stat-card stat-card-completed">
            <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg></div>
            <div>
              <div class="stat-val"><?= $stats['completed'] ?? 0 ?></div>
              <div class="stat-label">Completed Events</div>
            </div>
            <div class="stat-strip stat-strip-completed"></div>
          </a>
          <a href="<?= BASE_URL ?>/admin2/kpi-overview.php" class="stat-card stat-card-kpi">
            <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg></div>
            <div>
              <div class="stat-val"><?= $kpi['avg_rating'] ? number_format($kpi['avg_rating'],1) : '—' ?></div>
              <div class="stat-label">Avg KPI Rating (/ 4.0)</div>
            </div>
            <div class="stat-strip stat-strip-kpi"></div>
          </a>
        </div>

        <!-- Review Queue -->
        <div class="card">
          <div class="card-header">
            <h2>📥 Review Queue</h2>
            <a href="<?= BASE_URL ?>/admin2/pending.php" class="btn btn-outline btn-sm">View All</a>
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
                    <td class="col-actions"><div class="act-actions"><a href="<?= BASE_URL ?>/admin2/review.php?id=<?= $p['id'] ?>" class="btn btn-primary btn-sm">Review</a></div></td>
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
            <div class="month-bar-wrap">
              <?php foreach ($approvalTrends['months'] as $tm): 
                $appH = $approvalTrends['max_count'] > 0 ? max(round(($tm['approved'] / $approvalTrends['max_count']) * 100), ($tm['approved'] > 0 ? 8 : 0)) : 0;
                $retH = $approvalTrends['max_count'] > 0 ? max(round(($tm['returned'] / $approvalTrends['max_count']) * 100), ($tm['returned'] > 0 ? 8 : 0)) : 0;
              ?>
                <div class="month-bar-col">
                  <div class="month-bar-inner">
                    <?php if ($tm['approved'] > 0): ?>
                      <div class="month-bar approved" style="height:<?= $appH ?>%;" title="<?= $tm['month_name'] ?>: <?= $tm['approved'] ?> Approved">
                        <span class="month-val"><?= $tm['approved'] ?></span>
                      </div>
                    <?php endif; ?>
                    <?php if ($tm['returned'] > 0): ?>
                      <div class="month-bar returned" style="height:<?= $retH ?>%;" title="<?= $tm['month_name'] ?>: <?= $tm['returned'] ?> Returned/Rejected">
                        <span class="month-val" style="color:#DC2626;"><?= $tm['returned'] ?></span>
                      </div>
                    <?php endif; ?>
                    <?php if ($tm['approved'] == 0 && $tm['returned'] == 0): ?>
                      <div style="height:4px;width:10px;background:var(--border);border-radius:2px;"></div>
                    <?php endif; ?>
                  </div>
                  <div class="month-label"><?= $tm['month_name'] ?></div>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="flex gap-3 text-xs" style="margin-top:10px;justify-content:center;">
              <span><span class="legend-dot approved-dot"></span>Approved Proposals</span>
              <span><span class="legend-dot returned-dot"></span>Returned / Rejected</span>
            </div>
          </div>
        </div>

      </div><!-- dashboard-main -->

      <!-- Right Widget Rail (~32%) -->
      <div class="dashboard-rail">
        <div class="dashboard-rail-sticky">

          <!-- Overall KPI Performance Card -->
          <?php if (!empty($kpi['avg_rating'])): ?>
          <div class="card kpi-card" style="padding:16px 20px;">
            <div class="flex items-center justify-between" style="margin-bottom:8px;">
              <span class="text-sm fw-bold">Overall KPI Performance</span>
              <span class="badge badge-success"><?= number_format($kpi['avg_rating'],2) ?> / 4.00</span>
            </div>
            <div class="kpi-bar"><div class="kpi-fill" style="width:<?= ($kpi['avg_rating']/4)*100 ?>%"></div></div>
            <div class="text-sm text-muted" style="margin-top:6px;"><?= $kpi['events_evaluated'] ?> events evaluated</div>
          </div>
          <?php endif; ?>

          <!-- Upcoming Approved Events -->
          <div class="card">
            <div class="card-header"><h2>📅 Upcoming Approved Events</h2></div>
            <div class="card-body" style="padding:0;">
              <?php if (empty($upcoming)): ?>
                <div style="padding:24px;text-align:center;color:var(--text-muted);">No upcoming events.</div>
              <?php else: foreach ($upcoming as $ev): ?>
                <div class="upcoming-event">
                  <div class="event-date-badge">
                    <div class="day"><?= date('j', strtotime($ev['event_date'])) ?></div>
                    <div class="mon"><?= date('M', strtotime($ev['event_date'])) ?></div>
                  </div>
                  <div style="min-width:0;flex:1;">
                    <div style="font-weight:600;font-size:.85rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($ev['title']) ?></div>
                    <div class="text-sm text-muted" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($ev['venue'] ?? '—') ?></div>
                  </div>
                </div>
              <?php endforeach; endif; ?>
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
                          <td class="rail-col-act">
                            <a href="<?= BASE_URL ?>/admin2/view-activity.php?id=<?= $a['activity_id'] ?>">
                              <div class="rail-act-title"><?= htmlspecialchars($a['title']) ?></div>
                              <span class="rail-act-faculty"><?= htmlspecialchars($a['faculty_name']) ?></span>
                            </a>
                          </td>
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
  </div><!-- content -->
</div><!-- main-wrap -->
</body>
</html>
