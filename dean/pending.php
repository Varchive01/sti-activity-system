<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('dean');
$user = currentUser();
$db   = getDB();

// Fetch all activities relevant to this role
$where = "WHERE a.status = 'pending_final_approval'";

$activities = $db->query("SELECT a.*,u.name as faculty_name FROM activities a JOIN users u ON a.faculty_id=u.id $where ORDER BY a.updated_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pending – STI Activity System</title>
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
  th, td,
  table th, table td,
  .activity-table th,
  .activity-table td,
  .act-title,
  .act-faculty,
  .act-date,
  .badge,
  .btn,
  .topbar-user-name,
  .topbar-user-role,
  .notif-dropdown,
  label, input, button, select, textarea {
    font-family: 'Plus Jakarta Sans', sans-serif !important;
  }

  /* ── Content & Container Spacing ── */
  .content {
    padding: 24px;
  }

  /* ── Card Styling (Exact Faculty Parity) ── */
  .card {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
  }

  .card-header {
    padding: 16px 22px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--bg-card);
  }

  .card-header h2 {
    font-family: 'Plus Jakarta Sans', sans-serif !important;
    font-size: 1.02rem;
    font-weight: 700;
    color: var(--text-main);
    margin: 0;
    letter-spacing: -0.01em;
  }

  /* ── Table Wrapper & Layout ── */
  .table-wrap {
    border: none;
    border-radius: 0;
    background: transparent;
    overflow-x: auto;
    scrollbar-width: thin;
  }

  .activity-table {
    width: 100%;
    border-collapse: collapse;
    box-sizing: border-box;
  }

  .activity-table th,
  .activity-table td {
    box-sizing: border-box;
    overflow: hidden;
  }

  .activity-table th {
    padding: 11px 16px;
    font-size: 0.68rem;
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

  [data-theme="dark"] .activity-table th {
    background: rgba(255, 255, 255, 0.03);
  }

  .activity-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
    color: var(--text-main);
    font-size: 0.82rem;
    transition: background 0.12s ease;
    font-family: 'Plus Jakarta Sans', sans-serif !important;
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

  /* Flexible Column Sizing matching Faculty baseline */
  .col-title   { width: auto; min-width: 220px; }
  .col-faculty { width: 180px; }
  .col-date    { width: 130px; }
  .col-status  { width: 140px; }
  .col-actions { width: 120px; text-align: right; }

  .activity-table th.col-actions,
  .activity-table td.col-actions {
    text-align: right;
    padding-right: 18px;
  }

  /* Cell typography */
  .act-title {
    font-size: 0.90rem;
    font-weight: 600;
    color: var(--text-main);
    line-height: 1.35;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    word-break: break-word;
    text-decoration: none;
    transition: color 0.12s ease;
  }

  .act-title:hover {
    color: var(--accent, var(--sti-blue));
  }

  .act-faculty {
    color: var(--text-secondary);
    font-size: 0.82rem;
    font-weight: 500;
  }

  .act-date {
    color: var(--text-secondary);
    font-size: 0.78rem;
    font-weight: 500;
    white-space: nowrap;
  }

  /* Actions column and buttons */
  .act-actions {
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
    white-space: nowrap;
  }

  .activity-table td .btn {
    padding: 4px 10px;
    height: 27px;
    font-size: 0.72rem;
    font-weight: 600;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    box-sizing: border-box;
    white-space: nowrap;
    flex-shrink: 0;
    transition: all 0.15s ease;
    font-family: 'Plus Jakarta Sans', sans-serif !important;
  }

  .activity-table td .btn-outline {
    border: 1px solid var(--border);
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
    border: 1px solid var(--accent, var(--sti-blue));
    color: #fff;
  }

  .activity-table td .btn-primary:hover {
    filter: brightness(1.08);
    box-shadow: 0 2px 6px rgba(2, 132, 199, 0.25);
  }

  [data-theme="dark"] .activity-table td .btn-primary {
    background: var(--accent, #38BDF8);
    border-color: var(--accent, #38BDF8);
    color: #0A1628;
  }

  [data-theme="dark"] .activity-table td .btn-outline {
    border-color: var(--border);
    color: var(--text-main);
    background: transparent;
  }

  [data-theme="dark"] .activity-table td .btn-outline:hover {
    background: rgba(2, 132, 199, 0.20);
    border-color: #38BDF8;
    color: #7DD3FC;
  }

  /* Badges */
  .activity-table .badge {
    padding: 3px 8px;
    font-size: 0.68rem;
    font-weight: 700;
    border-radius: 999px;
    white-space: nowrap;
    line-height: 1.35;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-family: 'Plus Jakarta Sans', sans-serif !important;
  }

  /* Empty state */
  .empty-state {
    text-align: center;
    padding: 48px 24px;
    color: var(--text-muted);
  }

  .empty-state-icon {
    font-size: 2rem;
    margin-bottom: 10px;
    opacity: 0.7;
  }

  .empty-state-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 4px;
  }

  .empty-state-desc {
    font-size: 0.82rem;
    color: var(--text-muted);
  }

  /* Dark mode card overrides */
  [data-theme="dark"] .card {
    background: var(--bg-card);
    border-color: var(--border);
  }

  [data-theme="dark"] .card-header {
    background: var(--bg-card);
    border-color: var(--border);
  }

  [data-theme="dark"] .card-body {
    background: var(--bg-card);
  }
</style>
</head>
<body class="theme-faculty theme-dean">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">Pending</div>
  
    <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
      <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>
  <div class="content">
    <div class="card">
      <div class="card-header">
        <h2>Pending</h2>
        <span style="font-size:0.80rem;color:var(--text-muted);font-weight:500;">
          <?= count($activities) ?> <?= count($activities) === 1 ? 'proposal' : 'proposals' ?> awaiting review
        </span>
      </div>
      <div class="card-body" style="padding:0;">
        <div class="table-wrap">
          <table class="activity-table">
            <thead>
              <tr>
                <th class="col-title">Title</th>
                <th class="col-faculty">Faculty</th>
                <th class="col-date">Date</th>
                <th class="col-status">Status</th>
                <th class="col-actions">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($activities)): ?>
            <tr>
              <td colspan="5" class="empty-state">
                <div class="empty-state-icon">📋</div>
                <div class="empty-state-title">No records found.</div>
                <div class="empty-state-desc">There are currently no proposals awaiting final approval.</div>
              </td>
            </tr>
            <?php else: foreach ($activities as $a): ?>
            <tr>
              <td class="col-title">
                <a href="<?= BASE_URL ?>/dean/view-activity.php?id=<?= $a['id'] ?>" class="act-title"><?= htmlspecialchars($a['title']) ?></a>
              </td>
              <td class="col-faculty">
                <span class="act-faculty"><?= htmlspecialchars($a['faculty_name']) ?></span>
              </td>
              <td class="col-date">
                <span class="act-date"><?= $a['event_date'] ? date('M j, Y', strtotime($a['event_date'])) : '—' ?></span>
              </td>
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
  </div>
</div>
</body>
</html>
