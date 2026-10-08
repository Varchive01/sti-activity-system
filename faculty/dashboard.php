<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');

$user = currentUser();
$db   = getDB();

$stats = $db->prepare("
    SELECT
        COUNT(*) as total,
        SUM(status='draft') as drafts,
        SUM(status='submitted' OR status='under_review' OR status='endorsed' OR status='pending_final_approval' OR status='resubmitted') as pending,
        SUM(status='approved') as approved,
        SUM(status='returned_for_revision') as revisions,
        SUM(status='completed') as completed
    FROM activities WHERE faculty_id = ?
");
$stats->execute([$user['id']]);
$s = $stats->fetch();
$tot = max(1, (int)($s['total'] ?? 0));
$pPending = round(((int)($s['pending'] ?? 0) / $tot) * 100, 1);
$pApproved = round(((int)($s['approved'] ?? 0) / $tot) * 100, 1);
$pCompleted = round(((int)($s['completed'] ?? 0) / $tot) * 100, 1);

$recent = $db->prepare("
    SELECT a.*, u.name as faculty_name FROM activities a
    JOIN users u ON a.faculty_id = u.id
    WHERE a.faculty_id = ?
    ORDER BY a.updated_at DESC LIMIT 8
");
$recent->execute([$user['id']]);
$activities = $recent->fetchAll();

// Stagnant proposals check (3 days without update in pending review status)
checkProposalEscalations($db, $user['id'], $user['role']);

// Task progress reminders check (completed, about to be delayed, delayed)
checkTaskProgressReminders($db, $user['id'], $user['role']);

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
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Faculty Dashboard – STI Activity System</title>
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
    /* Topbar notification and theme styles loaded from notification-topbar-widget.php */
    /* ── Phase 2G: Faculty Dashboard Visual Refinements ── */
    .content {
      padding: 24px;
    }

    .dashboard-layout {
      grid-template-columns: 1fr;
      gap: 20px;
    }

    .dashboard-main {
      gap: 16px;
      width: 100%;
    }

    /* 1. Compact Horizontal KPI Stat Cards - Exact Reference Match */
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

    /* Light mode / default icon badges */
    .stat-card-total .stat-icon { background: rgba(2, 132, 199, 0.1); }
    .stat-card-total .stat-icon svg { color: var(--accent, #0284c7); }
    .stat-card-pending .stat-icon { background: rgba(245, 158, 11, 0.12); }
    .stat-card-pending .stat-icon svg { color: #d97706; }
    .stat-card-approved .stat-icon { background: rgba(16, 185, 129, 0.12); }
    .stat-card-approved .stat-icon svg { color: #059669; }
    .stat-card-revision .stat-icon { background: rgba(100, 116, 139, 0.12); }
    .stat-card-revision .stat-icon svg { color: #475569; }
    .stat-card-completed .stat-icon { background: rgba(2, 132, 199, 0.1); }
    .stat-card-completed .stat-icon svg { color: var(--accent, #0284c7); }

    /* Dark mode icon badges */
    [data-theme="dark"] .stat-card-total .stat-icon { background: rgba(56, 189, 248, 0.15); }
    [data-theme="dark"] .stat-card-total .stat-icon svg { color: #38bdf8; }
    [data-theme="dark"] .stat-card-pending .stat-icon { background: rgba(251, 191, 36, 0.15); }
    [data-theme="dark"] .stat-card-pending .stat-icon svg { color: #fbbf24; }
    [data-theme="dark"] .stat-card-approved .stat-icon { background: rgba(52, 211, 153, 0.15); }
    [data-theme="dark"] .stat-card-approved .stat-icon svg { color: #34d399; }
    [data-theme="dark"] .stat-card-revision .stat-icon { background: rgba(148, 163, 184, 0.15); }
    [data-theme="dark"] .stat-card-revision .stat-icon svg { color: #94a3b8; }
    [data-theme="dark"] .stat-card-completed .stat-icon { background: rgba(56, 189, 248, 0.15); }
    [data-theme="dark"] .stat-card-completed .stat-icon svg { color: #38bdf8; }

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
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 1.38rem;
      font-weight: 800;
      line-height: 1;
      color: var(--text-main) !important;
      letter-spacing: -0.02em;
    }

    a.stat-card .stat-label,
    .stat-grid a.stat-card .stat-label {
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
    .stat-strip-revision { background: #64748b; }
    .stat-strip-completed { background: #0284c7; }
    [data-theme="dark"] .stat-strip-completed { background: #38bdf8; }

    /* 2. Recent Activities Section Refinement (Phase 2G-1) */
    .dashboard-main .card {
      background: var(--bg-card);
      border-radius: 14px;
      border: 1px solid var(--border);
      box-shadow: var(--shadow-sm);
      overflow: hidden;
    }

    .dashboard-main .card-header {
      padding: 14px 20px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      background: var(--bg-card);
      flex-wrap: wrap;
    }

    .dashboard-main .card-header h2 {
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 1.02rem;
      font-weight: 700;
      color: var(--text-main);
      margin: 0;
      letter-spacing: -0.01em;
    }

    .dashboard-main .card-header-actions {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .dashboard-main .card-header .btn-primary {
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
    }

    .dashboard-main .card-header .btn-primary:hover {
      filter: brightness(1.08);
      box-shadow: 0 2px 8px rgba(2, 132, 199, 0.25);
    }

    .dashboard-main .card-header .btn-outline {
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
    }

    .dashboard-main .card-header .btn-outline:hover {
      border-color: var(--accent, var(--sti-blue));
      color: var(--accent, var(--sti-blue));
      background: var(--accent-lt, rgba(2, 132, 199, 0.08));
    }

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
    }

    [data-theme="dark"] .activity-table th {
      background: rgba(255, 255, 255, 0.03);
    }

    .activity-table td {
      padding: 9px 8px;
      border-bottom: 1px solid var(--border);
      vertical-align: middle;
      color: var(--text-main);
      font-size: 0.85rem;
      transition: background 0.12s ease;
    }

    .activity-table tbody tr:last-child td {
      border-bottom: none;
    }

    .activity-table tbody tr:hover td {
      background: rgba(2, 132, 199, 0.03);
    }

    [data-theme="dark"] .activity-table tbody tr:hover td {
      background: rgba(255, 255, 255, 0.03);
    }

    /* Column Widths & Proportions */
    .col-title {
      width: 33%;
    }

    .col-date {
      width: 13%;
    }

    .col-venue {
      width: 14%;
    }

    .col-source {
      width: 10%;
    }

    .col-status {
      width: 16%;
    }

    .col-actions {
      width: 14%;
      text-align: right;
    }

    .activity-table th.col-actions,
    .activity-table td.col-actions {
      text-align: right;
      padding-right: 12px;
    }

    /* Content styling within cells */
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

    .act-venue {
      color: var(--text-muted);
      font-size: 0.85rem;
      line-height: 1.25;
      display: block;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
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
    }

    .activity-table td .btn-outline {
      border-color: var(--border);
      color: var(--text-main);
      background: transparent;
    }

    .activity-table td .btn-outline:hover {
      border-color: var(--accent, var(--sti-blue));
      color: var(--accent, var(--sti-blue));
      background: var(--accent-lt, rgba(2, 132, 199, 0.08));
    }

    .activity-table td .btn-primary {
      background: var(--accent, var(--sti-blue));
      border-color: var(--accent, var(--sti-blue));
      color: #fff;
    }

    .activity-table td .btn-primary:hover {
      filter: brightness(1.1);
      box-shadow: 0 2px 6px rgba(2, 132, 199, 0.25);
    }

    .activity-table td .btn-success {
      background: #16A34A;
      border: 1px solid #16A34A;
      color: #fff;
    }

    .activity-table td .btn-success:hover {
      filter: brightness(1.08);
      box-shadow: 0 2px 6px rgba(22, 163, 74, 0.25);
    }

    [data-theme="dark"] .activity-table td .btn-success {
      background: rgba(34, 197, 94, 0.18);
      border-color: rgba(74, 222, 128, 0.35);
      color: #4ADE80;
    }

    [data-theme="dark"] .activity-table td .btn-success:hover {
      background: rgba(34, 197, 94, 0.28);
      color: #86EFAC;
    }

    .activity-table .badge {
      padding: 2.5px 8px;
      font-size: 0.74rem;
      font-weight: 700;
      border-radius: 999px;
      white-space: nowrap;
      line-height: 1.3;
    }

    /* Responsive adjustments */
    @media (max-width: 1024px) {
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
      .dashboard-layout {
        grid-template-columns: 1fr;
        gap: 20px;
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
      .dashboard-main .card-header {
        gap: 10px;
      }
    }

    /* ── Typography Consistency: All Visible Text on Faculty Dashboard uses Plus Jakarta Sans ── */
    body.theme-faculty,
    body.theme-faculty *,
    body.theme-faculty *::before,
    body.theme-faculty *::after,
    body,
    h1, h2, h3, h4, h5, h6,
    .page-title,
    .topbar .page-title,
    .brand,
    .sidebar-logo .brand,
    .card-header h2,
    .card-header h3,
    .dashboard-main .card-header h2,
    .stat-val,
    a.stat-card .stat-val,
    .stat-grid a.stat-card .stat-val,
    .stat-label,
    a.stat-card .stat-label,
    .stat-grid a.stat-card .stat-label,
    th, td,
    .activity-table th,
    .activity-table td,
    .act-title,
    .act-date,
    .act-venue,
    .badge,
    .btn,
    .topbar-user-name,
    .topbar-user-role,
    .notif-dropdown,
    .notif-header h3,
    .notif-modal h3,
    .notif-msg,
    .notif-time,
    label,
    input,
    button,
    select,
    textarea {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }
  </style>
</head>

<body class="theme-faculty">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">

    <!-- Topbar -->
    <header class="topbar">
      <div class="page-title">Faculty Dashboard</div>
      <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
        <!-- User Profile Control -->
        <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
      </div>
    </header>

    <div class="content">

      <div class="dashboard-layout">
        <!-- Main Work Area (~68%) -->
        <div class="dashboard-main">

          <!-- Stats -->
          <div class="stat-grid" style="margin-bottom:0;">
            <a href="<?= BASE_URL ?>/faculty/activities.php" class="stat-card stat-card-total">
              <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg></div>
              <div>
                <div class="stat-val"><?= $s['total'] ?></div>
                <div class="stat-label">Total</div>
              </div>
              <div class="stat-strip stat-strip-total">
                <span style="width:<?= $pPending ?>%;background:#f59e0b;"></span>
                <span style="width:<?= $pApproved ?>%;background:#10b981;"></span>
                <span style="width:<?= $pCompleted ?>%;background:#0284c7;"></span>
              </div>
            </a>
            <a href="<?= BASE_URL ?>/faculty/pending.php" class="stat-card stat-card-pending">
              <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg></div>
              <div>
                <div class="stat-val"><?= $s['pending'] ?></div>
                <div class="stat-label">Pending</div>
              </div>
              <div class="stat-strip stat-strip-pending"></div>
            </a>
            <a href="<?= BASE_URL ?>/faculty/approved.php" class="stat-card stat-card-approved">
              <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg></div>
              <div>
                <div class="stat-val"><?= $s['approved'] ?></div>
                <div class="stat-label">Approved</div>
              </div>
              <div class="stat-strip stat-strip-approved"></div>
            </a>
            <a href="<?= BASE_URL ?>/faculty/returned.php" class="stat-card stat-card-revision">
              <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg></div>
              <div>
                <div class="stat-val"><?= $s['revisions'] ?></div>
                <div class="stat-label">Revision</div>
              </div>
              <div class="stat-strip stat-strip-revision"></div>
            </a>
            <a href="<?= BASE_URL ?>/faculty/activities.php?status=completed" class="stat-card stat-card-completed">
              <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
                </svg></div>
              <div>
                <div class="stat-val"><?= $s['completed'] ?></div>
                <div class="stat-label">Completed</div>
              </div>
              <div class="stat-strip stat-strip-completed"></div>
            </a>
          </div>

          <!-- Recent Activities -->
          <div class="card">
            <div class="card-header">
              <h2>Recent Activities</h2>
              <div class="card-header-actions">
                <a href="<?= BASE_URL ?>/faculty/proposal-create.php" class="btn btn-primary btn-sm">
                  <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:14px;height:14px">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                  </svg>
                  New Activity Proposal
                </a>
                <a href="<?= BASE_URL ?>/faculty/activities.php" class="btn btn-outline btn-sm">View All</a>
              </div>
            </div>
            <div class="card-body" style="padding:0;">
              <div class="table-wrap">
                <table class="activity-table">
                  <thead>
                    <tr>
                      <th class="col-title">Activity Title</th>
                      <th class="col-date">Date</th>
                      <th class="col-venue">Venue</th>
                      <th class="col-source">Source</th>
                      <th class="col-status">Status</th>
                      <th class="col-actions">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($activities)): ?>
                      <tr>
                        <td colspan="6" style="text-align:center;padding:32px;color:var(--text-muted);">
                          No activities yet. <a href="<?= BASE_URL ?>/faculty/proposal-create.php">Create your first proposal →</a>
                        </td>
                      </tr>
                      <?php else: foreach ($activities as $act): ?>
                        <tr>
                          <td class="col-title"><div class="act-title"><?= htmlspecialchars($act['title']) ?></div></td>
                          <td class="col-date"><span class="act-date"><?= $act['event_date'] ? date('M j, Y', strtotime($act['event_date'])) : '—' ?></span></td>
                          <td class="col-venue"><span class="act-venue" title="<?= htmlspecialchars($act['venue'] ?? '') ?>"><?= htmlspecialchars($act['venue'] ?? '—') ?></span></td>
                          <td class="col-source"><span class="badge badge-secondary"><?= ucfirst(str_replace('_', ' ', $act['source'])) ?></span></td>
                          <td class="col-status"><?= getStatusBadge($act['status']) ?></td>
                          <td class="col-actions">
                            <div class="act-actions">
                              <a href="<?= BASE_URL ?>/faculty/proposal-view.php?id=<?= $act['id'] ?>" class="btn btn-outline btn-sm">View</a>
                              <?php if (in_array($act['status'], ['draft', 'returned_for_revision'])): ?>
                                <a href="<?= BASE_URL ?>/faculty/proposal-edit.php?id=<?= $act['id'] ?>" class="btn btn-primary btn-sm">Edit</a>
                              <?php endif; ?>
                              <?php if ($act['status'] === 'approved'): ?>
                                <a href="<?= BASE_URL ?>/faculty/post-event.php?activity_id=<?= $act['id'] ?>" class="btn btn-success btn-sm">Post-Event</a>
                              <?php endif; ?>
                            </div>
                          </td>
                        </tr>
                    <?php endforeach;
                    endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

        </div><!-- dashboard-main -->
      </div><!-- dashboard-layout -->

    </div><!-- content -->
  </div><!-- main-wrap -->
</body>

</html>