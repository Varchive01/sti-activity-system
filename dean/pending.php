<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('dean');
$user = currentUser();
$db   = getDB();

$search = sanitize($_GET['q'] ?? '');
$where  = "a.status = 'pending_final_approval'";
$params = [];
if ($search) {
  $where .= " AND (a.title LIKE ? OR u.name LIKE ?)";
  $params = ["%$search%", "%$search%"];
}

$stmt = $db->prepare("
    SELECT a.*, u.name as faculty_name, u.email as faculty_email FROM activities a
    JOIN users u ON a.faculty_id = u.id
    WHERE $where
    ORDER BY a.submitted_at ASC
");
$stmt->execute($params);
$pending = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pending Reviews – STI Activity System</title>
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
    /* ── Institutional Colors & Typography: Strict Plus Jakarta Sans ── */
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

    body,
    body *,
    h1, h2, h3, h4, h5, h6,
    .page-title,
    .topbar .page-title,
    .card-header h2,
    .btn,
    .badge,
    .form-control,
    label,
    select,
    input {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }

    /* ── Proposal Row Card Styling (Role Parity) ── */
    .proposal-row {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 14px;
      box-shadow: var(--shadow-sm);
      padding: 18px 20px;
      margin-bottom: 12px;
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 16px;
      align-items: center;
      transition: box-shadow .2s ease, border-color .15s ease;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }

    .proposal-row:hover {
      box-shadow: var(--shadow);
      border-color: var(--accent, var(--sti-blue));
    }

    .proposal-row .title {
      font-weight: 700;
      font-size: .95rem;
      margin-bottom: 6px;
      color: var(--text-main);
      line-height: 1.35;
    }

    .proposal-row .title a {
      color: var(--text-main);
      text-decoration: none;
      transition: color .12s ease;
    }

    .proposal-row .title a:hover {
      color: var(--accent, var(--sti-blue));
    }

    .proposal-row .meta {
      font-size: .78rem;
      color: var(--text-muted);
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      align-items: center;
    }

    .meta-pill {
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }

    .days-badge {
      background: #FEF3C7;
      color: #B45309;
      border-radius: 6px;
      padding: 4px 10px;
      font-size: .72rem;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
    }

    .days-badge.urgent {
      background: #FEE2E2;
      color: #B91C1C;
    }

    [data-theme="dark"] .days-badge {
      background: rgba(245, 158, 11, 0.18);
      color: #FBBF24;
    }

    [data-theme="dark"] .days-badge.urgent {
      background: rgba(239, 68, 68, 0.18);
      color: #F87171;
    }

    /* ── Table Styling (Conventions Parity) ── */
    .activity-table {
      width: 100%;
      border-collapse: collapse;
      box-sizing: border-box;
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
    }

    .activity-table tbody tr:hover td {
      background: rgba(2, 132, 199, 0.03);
    }

    [data-theme="dark"] .activity-table tbody tr:hover td {
      background: rgba(255, 255, 255, 0.03);
    }

    [data-theme="dark"] .card {
      background: var(--bg-card);
      border-color: var(--border);
    }
  </style>
</head>

<body class="theme-faculty theme-dean">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">Pending Review Queue</div>
      <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
        <span class="badge badge-warning" style="font-size:.8rem;padding:6px 12px;"><?= count($pending) ?> awaiting review</span>
        <!-- User Profile Control -->
        <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
      </div>
    </header>
    <div class="content">

      <!-- Search & Filters Toolbar -->
      <form method="GET" class="filters">
        <input type="text" name="q" class="form-control" placeholder="Search by title or faculty name…" value="<?= htmlspecialchars($search) ?>" style="max-width:380px;">
        <button type="submit" class="btn btn-primary">Search</button>
        <?php if ($search): ?><a href="pending.php" class="btn btn-outline">Clear</a><?php endif; ?>
        <span class="filters-count" style="margin-left:auto;font-size:0.82rem;color:var(--text-muted);font-weight:500;"><?= count($pending) ?> proposal<?= count($pending) === 1 ? '' : 's' ?></span>
      </form>

      <?php if (empty($pending)): ?>
        <div class="card" style="border-radius:14px;border:1px solid var(--border);box-shadow:var(--shadow-sm);background:var(--bg-card);">
          <div class="card-body" style="text-align:center;padding:60px;">
            <div style="font-size:3rem;margin-bottom:12px;">🎉</div>
            <div style="font-weight:700;font-size:1.05rem;color:var(--text-main);margin-bottom:6px;">Queue is Clear!</div>
            <div class="text-muted text-sm">No proposals are awaiting final approval.</div>
          </div>
        </div>
      <?php else: ?>
        <?php foreach ($pending as $p):
          $daysWaiting = $p['submitted_at'] ? (int)((time() - strtotime($p['submitted_at'])) / 86400) : 0;
          $urgent = $daysWaiting >= 3;
        ?>
          <div class="proposal-row">
            <div>
              <div class="title">
                <a href="<?= BASE_URL ?>/dean/review.php?id=<?= $p['id'] ?>"><?= htmlspecialchars($p['title']) ?></a>
              </div>
              <div class="meta">
                <span class="meta-pill">👤 <?= htmlspecialchars($p['faculty_name']) ?></span>
                <span class="badge badge-secondary" style="font-size:0.72rem;font-weight:600;"><?= ucfirst(str_replace('_', ' ', $p['source'])) ?></span>
                <span class="meta-pill">📅 <?= $p['event_date'] ? date('M j, Y', strtotime($p['event_date'])) : 'Date TBD' ?></span>
                <span class="meta-pill">📍 <?= htmlspecialchars($p['venue'] ?? '—') ?></span>
                <span class="meta-pill">👥 <?= number_format($p['target_participants']) ?> participants</span>
                <?= getStatusBadge($p['status']) ?>
                <span class="days-badge <?= $urgent ? 'urgent' : '' ?>"><?= $daysWaiting === 0 ? 'Today' : "{$daysWaiting}d waiting" ?></span>
              </div>
              <?php if (!empty($p['theme'])): ?><div class="text-sm text-muted" style="margin-top:6px;font-size:0.78rem;">Theme: <?= htmlspecialchars($p['theme']) ?></div><?php endif; ?>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px;min-width:160px;">
              <a href="<?= BASE_URL ?>/dean/review.php?id=<?= $p['id'] ?>" class="btn btn-primary" style="justify-content:center;">Review Proposal</a>
              <div class="text-sm text-muted" style="text-align:center;">Submitted <?= $p['submitted_at'] ? date('M j, Y', strtotime($p['submitted_at'])) : '—' ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

    </div>
  </div>
</body>

</html>
