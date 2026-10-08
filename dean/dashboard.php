<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';
requireRole('dean');
$user = currentUser();
$db   = getDB();

// Stagnant proposals escalation check
checkProposalEscalations($db, $user['id'], $user['role']);

// Task progress reminders check (monitored activities; Dean receives no individual task spam)
checkTaskProgressReminders($db, $user['id'], $user['role']);

// Period and Year Filter (Preserving existing semester conventions)
$selectedYear = (int)($_GET['year'] ?? date('Y'));
$selectedPeriod = sanitize($_GET['period'] ?? 'all');
$availableYears = range(date('Y'), date('Y') - 4);

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

// 1. Centralized Institutional KPI Summary (Deterministic PHP logic)
$summary = getInstitutionalKpiSummary($selectedYear, $selectedPeriod, $db);
$stats = $summary['statistics'];
$kpiPerf = $summary['kpi_performance'];

$dTot = max(1, (int)($stats['total'] ?? 0));
$pDeanPending = round(((int)($stats['pending'] ?? 0) / $dTot) * 100, 1);
$pDeanApproved = round(((int)($stats['approved'] ?? 0) / $dTot) * 100, 1);
$pDeanCompleted = round(((int)($stats['completed'] ?? 0) / $dTot) * 100, 1);
$pDeanReturned = round(((int)($stats['returned_rejected'] ?? 0) / $dTot) * 100, 1);

// 2. Approval Trends for Selected Period
$approvalTrends = getApprovalTrends($selectedYear, $selectedPeriod, $db);

// 3. Status and Source Breakdown for Selected Period
$periodBounds = buildPeriodWhereClause($selectedYear, $selectedPeriod, 'a.event_date');
$whereClause = $periodBounds['where'];
$params = $periodBounds['params'];

$statusStmt = $db->prepare("
  SELECT status, COUNT(*) as cnt FROM activities a WHERE {$whereClause} GROUP BY status ORDER BY cnt DESC
");
$statusStmt->execute($params);
$by_status = $statusStmt->fetchAll();

$sourceStmt = $db->prepare("
  SELECT source, COUNT(*) as cnt FROM activities a WHERE {$whereClause} GROUP BY source
");
$sourceStmt->execute($params);
$by_source = $sourceStmt->fetchAll();

// 4. Recent Activities (System-wide View)
$recentStmt = $db->prepare("
  SELECT a.*, u.name as fname 
  FROM activities a 
  JOIN users u ON a.faculty_id=u.id 
  WHERE {$whereClause}
  ORDER BY a.updated_at DESC LIMIT 10
");
$recentStmt->execute($params);
$recent_all = $recentStmt->fetchAll();
if (empty($recent_all)) {
    // If no activities in the filtered period, show latest 10 overall
    $recent_all = $db->query("
      SELECT a.*, u.name as fname FROM activities a JOIN users u ON a.faculty_id=u.id
      ORDER BY a.updated_at DESC LIMIT 10
    ")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dean Dashboard – STI Activity System</title>
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
    body.theme-dean,
    .theme-dean,
    aside.sidebar.theme-dean {
      --accent: var(--sti-blue, #0284C7);
      --accent-lt: #E0F2FE;
      --text: var(--text-main);
    }

    [data-theme="dark"] body.theme-dean,
    [data-theme="dark"] .theme-dean,
    [data-theme="dark"] aside.sidebar.theme-dean {
      --accent: #38BDF8;
      --accent-lt: rgba(2, 132, 199, 0.22);
      --text: var(--text-main);
    }

    /* ── Typography Standardization: Plus Jakarta Sans Everywhere (No Syne) ── */
    body.theme-faculty,
    body.theme-faculty *,
    body.theme-faculty *::before,
    body.theme-faculty *::after,
    body.theme-dean,
    body.theme-dean *,
    body.theme-dean *::before,
    body.theme-dean *::after,
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
    table th, table td,
    .activity-table th,
    .activity-table td,
    .badge,
    .btn,
    .topbar-user-name,
    .topbar-user-role,
    .month-label,
    .month-val,
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
      min-width: 0;
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
      border-radius: 7px;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
      font-size: 0.78rem;
      font-weight: 500;
      height: 28px;
      padding: 2px 8px;
      transition: border-color .15s ease, box-shadow .15s ease;
      outline: none;
    }
    .topbar select.form-control:focus {
      border-color: var(--accent, var(--sti-blue));
      box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.15);
    }
    .topbar .btn-outline {
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
      transition: all .15s ease;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
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
      background: transparent;
      border-color: var(--border);
      color: var(--text-secondary);
    }
    [data-theme="dark"] .topbar .btn-outline:hover {
      background: rgba(2, 132, 199, 0.20);
      border-color: #38BDF8;
      color: #7DD3FC;
    }

    /* ── 1. Compact Horizontal KPI Stat Cards (Exact Faculty Parity) ── */
    .stat-grid {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
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
    .stat-card-total .stat-icon { background: rgba(2, 132, 199, 0.1); }
    .stat-card-total .stat-icon svg { color: var(--accent, #0284c7); }
    .stat-card-pending .stat-icon { background: rgba(245, 158, 11, 0.12); }
    .stat-card-pending .stat-icon svg { color: #d97706; }
    .stat-card-approved .stat-icon { background: rgba(16, 185, 129, 0.12); }
    .stat-card-approved .stat-icon svg { color: #059669; }
    .stat-card-completed .stat-icon { background: rgba(2, 132, 199, 0.1); }
    .stat-card-completed .stat-icon svg { color: var(--accent, #0284c7); }
    .stat-card-danger .stat-icon,
    .stat-card-returned .stat-icon { background: rgba(239, 68, 68, 0.12); }
    .stat-card-danger .stat-icon svg,
    .stat-card-returned .stat-icon svg { color: #dc2626; }

    /* Dark mode icon badges */
    [data-theme="dark"] .stat-card-total .stat-icon { background: rgba(56, 189, 248, 0.15); }
    [data-theme="dark"] .stat-card-total .stat-icon svg { color: #38bdf8; }
    [data-theme="dark"] .stat-card-pending .stat-icon { background: rgba(251, 191, 36, 0.15); }
    [data-theme="dark"] .stat-card-pending .stat-icon svg { color: #fbbf24; }
    [data-theme="dark"] .stat-card-approved .stat-icon { background: rgba(52, 211, 153, 0.15); }
    [data-theme="dark"] .stat-card-approved .stat-icon svg { color: #34d399; }
    [data-theme="dark"] .stat-card-completed .stat-icon { background: rgba(56, 189, 248, 0.15); }
    [data-theme="dark"] .stat-card-completed .stat-icon svg { color: #38bdf8; }
    [data-theme="dark"] .stat-card-danger .stat-icon,
    [data-theme="dark"] .stat-card-returned .stat-icon { background: rgba(239, 68, 68, 0.18); }
    [data-theme="dark"] .stat-card-danger .stat-icon svg,
    [data-theme="dark"] .stat-card-returned .stat-icon svg { color: #f87171; }

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

    .stat-strip-total {
      background: rgba(203, 213, 225, 0.4);
    }
    [data-theme="dark"] .stat-strip-total {
      background: rgba(148, 163, 184, 0.2);
    }
    .stat-strip-total span {
      height: 100%;
      display: block;
    }
    .stat-strip-pending { background: #f59e0b; }
    .stat-strip-approved { background: #10b981; }
    .stat-strip-completed { background: #0284c7; }
    [data-theme="dark"] .stat-strip-completed { background: #38bdf8; }
    .stat-strip-danger,
    .stat-strip-returned { background: #ef4444; }
    [data-theme="dark"] .stat-strip-danger,
    [data-theme="dark"] .stat-strip-returned { background: #f87171; }

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
      display: flex;
      align-items: center;
      gap: 8px;
      letter-spacing: -0.01em;
    }

    .dashboard-main .card-header h3,
    .dashboard-rail .card-header h3 {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
      font-size: 0.95rem;
      font-weight: 700;
      color: var(--text-main);
      margin: 0;
    }

    .dashboard-main .card-body,
    .dashboard-rail .card-body {
      background: var(--bg-card);
      padding: 18px 20px;
    }

    /* ── 3. Buttons (Exact Faculty Parity) ── */
    .btn-primary,
    .dashboard-main .card-header .btn-primary,
    .dashboard-rail .card-header .btn-primary,
    table td .btn-primary,
    .activity-table td .btn-primary {
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

    .btn-primary:hover,
    .dashboard-main .card-header .btn-primary:hover,
    .dashboard-rail .card-header .btn-primary:hover,
    table td .btn-primary:hover,
    .activity-table td .btn-primary:hover {
      filter: brightness(1.08);
      box-shadow: 0 2px 8px rgba(2, 132, 199, 0.25);
    }

    .btn-outline,
    .dashboard-main .card-header .btn-outline,
    .dashboard-rail .card-header .btn-outline,
    table td .btn-outline,
    .activity-table td .btn-outline {
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

    .btn-outline:hover,
    .dashboard-main .card-header .btn-outline:hover,
    .dashboard-rail .card-header .btn-outline:hover,
    table td .btn-outline:hover,
    .activity-table td .btn-outline:hover {
      border-color: var(--accent, var(--sti-blue));
      color: var(--accent, var(--sti-blue));
      background: var(--accent-lt, rgba(2, 132, 199, 0.08));
    }

    /* ── 4. Tables (Exact Faculty Parity) ── */
    .dashboard-main .table-wrap,
    .dashboard-rail .table-wrap {
      border: none;
      border-radius: 0;
      background: transparent;
      overflow-x: auto;
      scrollbar-width: thin;
    }

    table,
    .activity-table {
      width: 100%;
      border-collapse: collapse;
      box-sizing: border-box;
    }

    table th,
    table td,
    .activity-table th,
    .activity-table td {
      box-sizing: border-box;
    }

    table th,
    .activity-table th {
      padding: 9px 12px;
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

    [data-theme="dark"] table th,
    [data-theme="dark"] .activity-table th {
      background: rgba(255, 255, 255, 0.03);
    }

    table td,
    .activity-table td {
      padding: 10px 12px;
      border-bottom: 1px solid var(--border);
      vertical-align: middle;
      color: var(--text-main);
      font-size: 0.85rem;
      transition: background 0.12s ease;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }

    table tbody tr:last-child td,
    .activity-table tbody tr:last-child td {
      border-bottom: none;
    }

    table tbody tr:hover td,
    .activity-table tbody tr:hover td {
      background: rgba(2, 132, 199, 0.035);
    }

    [data-theme="dark"] table tbody tr:hover td,
    [data-theme="dark"] .activity-table tbody tr:hover td {
      background: rgba(255, 255, 255, 0.03);
    }

    table td .btn,
    .activity-table td .btn {
      padding: 3px 8px;
      height: 26px;
      font-size: 0.72rem;
      border-radius: 6px;
    }

    /* Table column widths and cell components matching Faculty */
    .activity-table .col-title { width: 32%; }
    .activity-table .col-faculty { width: 18%; }
    .activity-table .col-date { width: 14%; }
    .activity-table .col-source { width: 12%; }
    .activity-table .col-status { width: 14%; }
    .activity-table .col-actions { width: 10%; text-align: right; }

    .activity-table th.col-actions,
    .activity-table td.col-actions {
      text-align: right;
      padding-right: 12px;
    }

    .act-title {
      font-size: 0.90rem;
      font-weight: 600;
      color: var(--text-main);
      line-height: 1.3;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
      word-break: break-word;
    }

    .act-date {
      color: var(--text-secondary);
      font-size: 0.85rem;
      font-weight: 500;
      line-height: 1.25;
      white-space: nowrap;
    }

    .act-actions {
      display: inline-flex;
      align-items: center;
      justify-content: flex-end;
      gap: 4px;
      white-space: nowrap;
    }

    /* ── 5. Dean KPI & Institutional Widgets ── */
    .big-metric { text-align: center; padding: 20px 16px; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .big-metric .val { font-size: 2.4rem; font-weight: 800; color: var(--accent); }
    .big-metric .lbl { font-size: .75rem; color: var(--text-muted); font-weight: 600; margin-top: 4px; }
    .source-bar { display: flex; gap: 0; height: 14px; border-radius: 999px; overflow: hidden; margin: 12px 0; }
    .source-bar .seg-so { background: #16A34A; }
    .source-bar .seg-fac { background: var(--accent, #0284C7); }
    .legend-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 5px; }

    .kpi-target-strip { display: grid; grid-template-columns: 1.3fr 1fr 1fr 1fr; gap: 12px; margin-bottom: 18px; }
    .kpi-metrics-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }

    /* ── 6. Dark Mode Overrides ── */
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
    [data-theme="dark"] .dashboard-main .card-body,
    [data-theme="dark"] .dashboard-rail .card-body {
      background: var(--bg-card);
    }
    [data-theme="dark"] .stat-card,
    [data-theme="dark"] a.stat-card,
    [data-theme="dark"] .stat-grid a.stat-card {
      background: var(--bg-card);
      border-color: var(--border);
    }
    [data-theme="dark"] .btn-primary,
    [data-theme="dark"] .dashboard-main .card-header .btn-primary,
    [data-theme="dark"] table td .btn-primary {
      background: var(--accent, #38BDF8);
      border-color: var(--accent, #38BDF8);
      color: #fff;
    }
    [data-theme="dark"] .btn-primary:hover,
    [data-theme="dark"] .dashboard-main .card-header .btn-primary:hover,
    [data-theme="dark"] table td .btn-primary:hover {
      box-shadow: 0 2px 8px rgba(56, 189, 248, 0.35);
      filter: brightness(1.1);
    }

    /* ── 7. Responsive Adjustments ── */
    @media (max-width: 1100px) {
      .stat-grid {
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)) !important;
      }
    }

    @media (max-width: 1024px) {
      .dashboard-layout {
        grid-template-columns: 1fr;
        gap: 20px;
      }
      .dashboard-rail-sticky {
        position: static;
      }
      .topbar {
        height: auto;
        min-height: 64px;
        max-height: none;
        padding: 12px 20px;
        flex-wrap: wrap;
        gap: 12px;
      }
      .topbar-right {
        flex-wrap: wrap;
        gap: 8px;
      }
      .topbar-right .text-sm.text-muted {
        display: none;
      }
      .kpi-target-strip {
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)) !important;
      }
      .kpi-metrics-row {
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)) !important;
      }
      #ai-insights-card .card-header {
        flex-wrap: wrap;
        gap: 10px;
      }
      #ai-insights-body [style*="grid-template-columns:1fr 1fr"],
      #ai-insights-body [style*="grid-template-columns: 1fr 1fr"] {
        grid-template-columns: 1fr !important;
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
    }
  </style>
</head>
<body class="theme-faculty theme-dean">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">Dean's Overview Dashboard</div>
    <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
        <!-- Year and Reporting Period Filter -->
        <form method="GET" style="display:flex;gap:6px;align-items:center;">
          <select name="year" class="form-control" style="width:85px;padding:3px 8px;font-size:0.78rem;height:28px;">
            <?php foreach ($availableYears as $y): ?>
              <option value="<?= $y ?>" <?= $selectedYear === $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endforeach; ?>
          </select>
          <select name="period" class="form-control" style="width:140px;padding:3px 8px;font-size:0.78rem;height:28px;">
            <option value="all" <?= $selectedPeriod === 'all' ? 'selected' : '' ?>>Whole Year</option>
            <option value="1st_sem" <?= $selectedPeriod === '1st_sem' ? 'selected' : '' ?>>1st Sem (Jun–Oct)</option>
            <option value="2nd_sem" <?= $selectedPeriod === '2nd_sem' ? 'selected' : '' ?>>2nd Sem (Nov–Mar)</option>
          </select>
          <button type="submit" class="btn btn-outline btn-sm" style="padding:4px 10px;height:28px;font-size:0.75rem;">Apply</button>
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

        <!-- 1. Top Stats (Activity Statistics) -->
        <div class="stat-grid" style="margin-bottom:0;">
          <a href="<?= BASE_URL ?>/dean/activities.php" class="stat-card stat-card-total">
            <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg></div>
            <div>
              <div class="stat-val"><?= $stats['total'] ?></div>
              <div class="stat-label">Total Activities</div>
            </div>
            <div class="stat-strip stat-strip-total">
              <span style="width:<?= $pDeanPending ?>%;background:#f59e0b;"></span>
              <span style="width:<?= $pDeanApproved ?>%;background:#10b981;"></span>
              <span style="width:<?= $pDeanCompleted ?>%;background:#0284c7;"></span>
              <span style="width:<?= $pDeanReturned ?>%;background:#ef4444;"></span>
            </div>
          </a>
          <a href="<?= BASE_URL ?>/dean/pending.php" class="stat-card stat-card-pending">
            <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
            <div>
              <div class="stat-val"><?= $stats['pending'] ?></div>
              <div class="stat-label">Pending Proposals</div>
            </div>
            <div class="stat-strip stat-strip-pending"></div>
          </a>
          <a href="<?= BASE_URL ?>/dean/approved.php" class="stat-card stat-card-approved">
            <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
            <div>
              <div class="stat-val"><?= $stats['approved'] ?></div>
              <div class="stat-label">Approved Activities</div>
            </div>
            <div class="stat-strip stat-strip-approved"></div>
          </a>
          <a href="<?= BASE_URL ?>/dean/activities.php?status=completed" class="stat-card stat-card-completed">
            <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg></div>
            <div>
              <div class="stat-val"><?= $stats['completed'] ?></div>
              <div class="stat-label">Completed Activities</div>
            </div>
            <div class="stat-strip stat-strip-completed"></div>
          </a>
          <a href="<?= BASE_URL ?>/dean/returned.php" class="stat-card stat-card-danger">
            <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg></div>
            <div>
              <div class="stat-val"><?= $stats['returned_rejected'] ?></div>
              <div class="stat-label">Returned / Rejected</div>
            </div>
            <div class="stat-strip stat-strip-danger"></div>
          </a>
        </div>

        <!-- 2. Approval Trends Card -->
        <div class="card">
          <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h2>📈 Proposal Approval Trends — <?= $selectedYear ?></h2>
            <span class="text-xs text-muted">
              Approved: <strong style="color:#16A34A;"><?= $approvalTrends['total_approved'] ?></strong> &bull; 
              Returned/Rejected: <strong style="color:#DC2626;"><?= $approvalTrends['total_returned'] ?></strong>
            </span>
          </div>
          <div class="card-body">
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
              <span><span class="legend-dot" style="background:#16A34A;"></span>Approved Proposals</span>
              <span><span class="legend-dot" style="background:#DC2626;"></span>Returned / Rejected</span>
            </div>
          </div>
        </div>

        <!-- 3. KPI Performance Overview -->
        <div class="card">
          <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <div>
              <h2>📊 KPI Performance Overview</h2>
              <span class="text-xs text-muted">Evaluated from <?= $kpiPerf['completed_with_kpi'] ?> completed activity report(s) with KPI data</span>
            </div>
            <a href="<?= BASE_URL ?>/dean/kpi-reports.php?year=<?= $selectedYear ?>" class="btn btn-primary btn-sm">
              View KPI Reports &rarr;
            </a>
          </div>
          <div class="card-body">
            <!-- Target Achievement Strip -->
            <div class="kpi-target-strip">
              <div style="background:var(--bg-base);border:1px solid var(--border);border-radius:10px;padding:14px 16px;display:flex;justify-content:space-between;align-items:center;">
                <div>
                  <div style="font-size:0.75rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;">Overall KPI Score</div>
                  <div style="font-size:1.8rem;font-weight:800;color:var(--accent);margin-top:2px;">
                    <?= $kpiPerf['overall_performance'] !== null ? ($kpiPerf['overall_performance'] . '%') : '—' ?>
                  </div>
                </div>
                <div>
                  <span class="badge <?= getKpiStatusClass($kpiPerf['overall_status']) ?>"><?= $kpiPerf['overall_status'] ?></span>
                </div>
              </div>
              <div style="background:var(--bg-base);border:1px solid var(--border);border-radius:10px;padding:14px 16px;text-align:center;">
                <div style="font-size:0.75rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;">Meeting Target (&ge;90%)</div>
                <div style="font-size:1.6rem;font-weight:800;color:#16A34A;margin-top:2px;">
                  <?= $kpiPerf['meeting_targets'] ?>
                </div>
                <div style="font-size:0.68rem;color:var(--text-muted);margin-top:2px;">activities</div>
              </div>
              <div style="background:var(--bg-base);border:1px solid var(--border);border-radius:10px;padding:14px 16px;text-align:center;">
                <div style="font-size:0.75rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;">Near Target (70–89%)</div>
                <div style="font-size:1.6rem;font-weight:800;color:#D97706;margin-top:2px;">
                  <?= $kpiPerf['near_targets'] ?>
                </div>
                <div style="font-size:0.68rem;color:var(--text-muted);margin-top:2px;">activities</div>
              </div>
              <div style="background:var(--bg-base);border:1px solid var(--border);border-radius:10px;padding:14px 16px;text-align:center;">
                <div style="font-size:0.75rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;">Below Target (&lt;70%)</div>
                <div style="font-size:1.6rem;font-weight:800;color:#DC2626;margin-top:2px;">
                  <?= $kpiPerf['below_targets'] ?>
                </div>
                <div style="font-size:0.68rem;color:var(--text-muted);margin-top:2px;">activities</div>
              </div>
            </div>

            <!-- Metric Cards -->
            <div class="kpi-metrics-row">
              <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:16px;text-align:center;">
                <div style="font-size:2rem;font-weight:800;color:var(--accent);"><?= $kpiPerf['avg_kpi_rating'] ?></div>
                <div style="font-size:0.75rem;color:var(--text-muted);font-weight:600;margin-top:4px;">Avg KPI Rating (out of 4.0)</div>
              </div>
              <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:16px;text-align:center;">
                <div style="font-size:2rem;font-weight:800;color:var(--accent);"><?= $kpiPerf['avg_satisfaction'] ?></div>
                <div style="font-size:0.75rem;color:var(--text-muted);font-weight:600;margin-top:4px;">Avg Satisfaction Score (out of 5.0)</div>
              </div>
              <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:16px;text-align:center;">
                <div style="font-size:2rem;font-weight:800;color:var(--accent);"><?= $kpiPerf['attendance_rate'] ?></div>
                <div style="font-size:0.75rem;color:var(--text-muted);font-weight:600;margin-top:4px;">Overall Attendance Rate</div>
              </div>
            </div>
          </div>
        </div>

        <!-- 4. AI Dashboard Insights -->
        <div class="card" id="ai-insights-card">
          <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div style="display:flex;align-items:center;gap:8px;">
              <h2>🤖 AI KPI &amp; Institutional Analytics</h2>
              <span id="ai-stale-badge" class="badge badge-warning" style="display:none;">Outdated Data</span>
            </div>
            <button id="update-insights-btn" class="btn btn-outline btn-sm" style="display:none;">Update AI Insights</button>
          </div>
          <div class="card-body" id="ai-insights-body">
            <div style="text-align:center; padding:24px;">
              <div style="font-size:1.5rem; margin-bottom:8px;">🤖</div>
              <div class="text-sm text-muted">Checking institutional insights...</div>
            </div>
          </div>
          <div style="font-size:0.75rem; color:var(--text-muted); text-align:center; padding-bottom:12px; font-style:italic;">
            * AI-generated insights are advisory only and should be reviewed by authorized administrators before making decisions.
          </div>
        </div>

        <!-- 5. All Activities (System-wide View) -->
        <div class="card">
          <div class="card-header">
            <h2>All Activities — System-wide View</h2>
            <a href="<?= BASE_URL ?>/dean/activities.php" class="btn btn-outline btn-sm">View All</a>
          </div>
          <div class="card-body" style="padding:0;">
            <div class="table-wrap">
              <table class="activity-table">
                <thead>
                  <tr>
                    <th class="col-title">Title</th>
                    <th class="col-faculty">Faculty</th>
                    <th class="col-date">Date</th>
                    <th class="col-source">Source</th>
                    <th class="col-status">Status</th>
                    <th class="col-actions">Action</th>
                  </tr>
                </thead>
                <tbody>
                <?php if (empty($recent_all)): ?>
                  <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-muted);">No activities found.</td></tr>
                <?php else: foreach ($recent_all as $a): ?>
                  <tr>
                    <td class="col-title"><div class="act-title"><?= htmlspecialchars($a['title']) ?></div></td>
                    <td class="col-faculty" style="color:var(--text-secondary);font-size:0.85rem;font-weight:500;"><?= htmlspecialchars($a['fname']) ?></td>
                    <td class="col-date"><span class="act-date"><?= $a['event_date'] ? date('M j, Y', strtotime($a['event_date'])) : '—' ?></span></td>
                    <td class="col-source"><span class="badge badge-secondary"><?= ucfirst(str_replace('_',' ',$a['source'])) ?></span></td>
                    <td class="col-status"><?= getStatusBadge($a['status']) ?></td>
                    <td class="col-actions">
                      <div class="act-actions">
                        <?php if ($a['status'] === 'pending_final_approval'): ?>
                          <a href="<?= BASE_URL ?>/dean/review.php?id=<?= $a['id'] ?>" class="btn btn-primary btn-sm">Review</a>
                        <?php else: ?>
                          <a href="<?= BASE_URL ?>/dean/view-activity.php?id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">View</a>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

      </div><!-- dashboard-main -->

      <!-- Right Widget Rail (~32%) -->
      <div class="dashboard-rail">
        <div class="dashboard-rail-sticky">

          <!-- Quick Actions -->
          <div class="card">
            <div class="card-header"><h2>Institutional Quick Actions</h2></div>
            <div class="card-body" style="display:flex;flex-direction:column;gap:10px;padding:20px;">
              <a href="<?= BASE_URL ?>/dean/pending.php" class="btn btn-outline btn-sm" style="justify-content:center;">Review Pending Proposals</a>
              <a href="<?= BASE_URL ?>/dean/approved.php" class="btn btn-outline btn-sm" style="justify-content:center;">Approved Activities</a>
              <a href="<?= BASE_URL ?>/dean/kpi-reports.php?year=<?= $selectedYear ?>" class="btn btn-outline btn-sm" style="justify-content:center;">Individual KPI Analyzer</a>
              <a href="<?= BASE_URL ?>/dean/generate-report.php?year=<?= $selectedYear ?>" class="btn btn-outline btn-sm" style="justify-content:center;">Export Institutional Report</a>
            </div>
          </div>

          <!-- Status Breakdown Card -->
          <div class="card">
            <div class="card-header"><h2>Status Breakdown</h2></div>
            <div class="card-body" style="padding:0;">
              <table><tbody>
              <?php if (empty($by_status)): ?>
                <tr><td colspan="2" style="text-align:center;padding:24px;color:var(--text-muted);">No records in period.</td></tr>
              <?php else: foreach ($by_status as $bs): ?>
              <tr>
                <td><?= getStatusBadge($bs['status']) ?></td>
                <td style="text-align:right;font-weight:700;"><?= $bs['cnt'] ?></td>
              </tr>
              <?php endforeach; endif; ?>
              </tbody></table>
            </div>
          </div>

          <!-- Source Breakdown -->
          <div class="card">
            <div class="card-header"><h2>Activities by Source — <?= $selectedYear ?></h2></div>
            <div class="card-body">
              <?php
              $soCount  = 0; $facCount = 0;
              foreach ($by_source as $bs) {
                if ($bs['source'] === 'student_org') $soCount = $bs['cnt'];
                else $facCount = $bs['cnt'];
              }
              $total = max($soCount + $facCount, 1);
              ?>
              <div class="source-bar">
                <div class="seg-so" style="width:<?= ($soCount/$total)*100 ?>%"></div>
                <div class="seg-fac" style="flex:1"></div>
              </div>
              <div class="flex gap-3 text-sm" style="margin-top:10px;flex-direction:column;gap:6px;">
                <span><span class="legend-dot" style="background:#16A34A;"></span>Student Org: <strong><?= $soCount ?></strong></span>
                <span><span class="legend-dot" style="background:#0284C7;"></span>Faculty: <strong><?= $facCount ?></strong></span>
              </div>
            </div>
          </div>

        </div><!-- dashboard-rail-sticky -->
      </div><!-- dashboard-rail -->

    </div><!-- dashboard-layout -->
  </div><!-- content -->
</div><!-- main-wrap -->

<!-- notifications.js included via notification-topbar-widget.php -->
<script>
(function() {
  const BASE_URL = '<?= BASE_URL ?>';

// --- AI Institutional Insights Integration ---
    const insightsBody = document.getElementById('ai-insights-body');
    const updateBtn = document.getElementById('update-insights-btn');
    const staleBadge = document.getElementById('ai-stale-badge');

    const selectedYear = <?= (int)$selectedYear ?>;
    const selectedPeriod = <?= json_encode($selectedPeriod) ?>;

    function loadAiInsights(action = 'check') {
      if (!insightsBody) return;
      
      if (action === 'analyze') {
        updateBtn.style.display = 'none';
        if (staleBadge) staleBadge.style.display = 'none';
        let step = 0;
        const steps = [
          '🤖 Analyzing KPI performance...',
          '📊 Reviewing activity results...',
          '💡 Preparing institutional insights...'
        ];
        insightsBody.innerHTML = `<div style="text-align:center; padding:30px;">
          <div class="spinner" style="margin: 0 auto 12px; width:24px; height:24px; border:2px solid var(--border); border-top-color:var(--accent); border-radius:50%; animation:spin 1s linear infinite;"></div>
          <div id="ai-loading-step" style="font-weight:600; color:var(--text-muted);">${steps[0]}</div>
        </div>`;
        
        const interval = setInterval(() => {
          step++;
          const el = document.getElementById('ai-loading-step');
          if (el && steps[step]) {
            el.textContent = steps[step];
          } else {
            clearInterval(interval);
          }
        }, 2000);
      }

      const params = new URLSearchParams({
        year: selectedYear,
        period: selectedPeriod,
        action: action
      });

      fetch(BASE_URL + '/api/generate-institutional-analytics.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
      })
      .then(r => {
        return r.json().then(data => ({ ok: r.ok, status: r.status, data }));
      })
      .then(res => {
        const d = res.data;
        if (!res.ok) {
          throw new Error(d.error || 'Server status ' + res.status);
        }

        if (d.status === 'empty') {
          updateBtn.style.display = 'none';
          if (staleBadge) staleBadge.style.display = 'none';
          insightsBody.innerHTML = `<div class="text-muted text-sm" style="text-align:center; padding:24px;">
            ${escapeHtml(d.message || 'No completed activity KPI data is available for AI analysis yet.')}
          </div>`;
        } else if (d.status === 'not_generated') {
          updateBtn.style.display = 'none';
          if (staleBadge) staleBadge.style.display = 'none';
          insightsBody.innerHTML = `<div style="text-align:center; padding:24px;">
            <p class="text-muted text-sm" style="margin-bottom:12px;">Institutional KPI insights have not been generated for this reporting period.</p>
            <button id="generate-insights-btn" class="btn btn-primary btn-sm">Generate AI Insights</button>
          </div>`;
          document.getElementById('generate-insights-btn')?.addEventListener('click', () => loadAiInsights('analyze'));
        } else if (d.status === 'generated' || d.status === 'success') {
          const insights = d.analytics;
          if (d.is_outdated) {
            updateBtn.style.display = 'inline-block';
            updateBtn.textContent = 'New Data Available — Update Insights';
            if (staleBadge) staleBadge.style.display = 'inline-block';
          } else {
            updateBtn.style.display = 'none';
            if (staleBadge) staleBadge.style.display = 'none';
          }

          let strengthsList = (insights.strengths || []).map(s => `<li>✓ ${escapeHtml(s)}</li>`).join('');
          let attentionList = (insights.areas_of_attention || []).map(a => `<li>⚠ ${escapeHtml(a)}</li>`).join('');
          let recsList = (insights.recommendations || []).map((r, i) => `<li>${i+1}. ${escapeHtml(r)}</li>`).join('');

          insightsBody.innerHTML = `
            <div style="margin-bottom:16px;">
              <strong style="display:block; margin-bottom:6px; font-size:0.9rem; color:var(--text);">INSTITUTIONAL PERFORMANCE SUMMARY</strong>
              <p style="font-size:0.85rem; line-height:1.5; color:var(--text-muted); margin:0;">${escapeHtml(insights.overall_insight || insights.performance_summary || '')}</p>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
              <div>
                <strong style="display:block; margin-bottom:6px; font-size:0.85rem; color:#16A34A;">COMMON STRENGTHS</strong>
                <ul style="list-style:none; padding:0; margin:0; font-size:0.8rem; line-height:1.4; color:var(--text-muted); display:flex; flex-direction:column; gap:6px;">
                  ${strengthsList || '<li>No significant strengths observed.</li>'}
                </ul>
              </div>
              <div>
                <strong style="display:block; margin-bottom:6px; font-size:0.85rem; color:#D97706;">AREAS NEEDING ATTENTION</strong>
                <ul style="list-style:none; padding:0; margin:0; font-size:0.8rem; line-height:1.4; color:var(--text-muted); display:flex; flex-direction:column; gap:6px;">
                  ${attentionList || '<li>No areas requiring urgent attention.</li>'}
                </ul>
              </div>
            </div>
            <div>
              <strong style="display:block; margin-bottom:6px; font-size:0.85rem; color:var(--accent);">STRATEGIC RECOMMENDATIONS</strong>
              <ul style="list-style:none; padding:0; margin:0; font-size:0.8rem; line-height:1.4; color:var(--text-muted); display:flex; flex-direction:column; gap:6px;">
                ${recsList || '<li>No recommendations generated.</li>'}
              </ul>
            </div>
            <div style="margin-top:14px; font-size:0.75rem; color:var(--text-muted); border-top:1px solid var(--border); padding-top:8px; display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px;">
              <span><strong>Confidence:</strong> ${escapeHtml(insights.confidence_note || 'Evaluated from verified database records.')}</span>
              <span>Last updated: ${escapeHtml(d.last_updated || 'Just now')}</span>
            </div>
          `;
        }
      })
      .catch(err => {
        console.error('AI Insights Error:', err);
        updateBtn.style.display = 'none';
        if (staleBadge) staleBadge.style.display = 'none';
        insightsBody.innerHTML = `<div style="text-align:center; padding:24px;">
          <div style="font-size:1.8rem; margin-bottom:8px;">⚠️</div>
          <p class="text-sm" style="margin-bottom:6px; font-weight:600; color:var(--text);">AI insights are temporarily unavailable.</p>
          <p class="text-xs text-muted" style="margin-bottom:12px;">Statistical and KPI metrics remain fully available.</p>
          <button id="retry-insights-btn" class="btn btn-outline btn-sm">Retry</button>
        </div>`;
        document.getElementById('retry-insights-btn')?.addEventListener('click', () => loadAiInsights('check'));
      });
    }

    function escapeHtml(text) {
      if (!text) return '';
      return text.toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    }

    updateBtn?.addEventListener('click', () => loadAiInsights('analyze'));
    
    // Initial load
    loadAiInsights('check');

    function formatDate(str) {
      const d = new Date(str);
      return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ', ' +
             d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }
  })();
  </script>
</body>
</html>
