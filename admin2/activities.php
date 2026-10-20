<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin2');
$user=$db=null; $user=currentUser(); $db=getDB();
$search=$filterStat=''; $page=1; $perPage=15; $offset=0;
if(isset($_GET['q']))      $search    =sanitize($_GET['q']);
if(isset($_GET['status'])) $filterStat=sanitize($_GET['status']);
if(isset($_GET['page']))   $page      =max(1,(int)$_GET['page']);
$offset=($page-1)*$perPage;
$where="a.status != 'draft'"; $params=[];
if($search)     {$where.=" AND a.title LIKE ?"; $params[]="%$search%";}
if($filterStat) {
  if (strtolower($filterStat) === 'draft') {
    $where .= " AND 1=0";
  } else {
    $where .= " AND a.status=?";
    $params[] = $filterStat;
  }
}
$total=$db->prepare("SELECT COUNT(*) FROM activities a WHERE $where"); $total->execute($params); $totalCount=$total->fetchColumn(); $totalPages=ceil($totalCount/$perPage);
$acts=$db->prepare("SELECT a.*,u.name as fn FROM activities a JOIN users u ON a.faculty_id=u.id WHERE $where ORDER BY a.updated_at DESC LIMIT $perPage OFFSET $offset"); $acts->execute($params); $activities=$acts->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>All Activities – STI Activity System</title>
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
    /* ── Typography & Parity: Strict Plus Jakarta Sans ── */
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
  </style>
</head>
<body class="theme-ian">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">All Activities</div>
      <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
        <!-- User Profile Control -->
        <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
      </div>
    </header>
    <div class="content">

      <!-- Search & Filters -->
      <form method="GET" class="filters">
        <input type="text" name="q" class="form-control" placeholder="Search activities…" value="<?= htmlspecialchars($search) ?>" style="max-width:280px;">
        <select name="status" class="form-control" style="max-width:180px;">
          <option value="">All Statuses</option>
          <?php foreach (['submitted', 'under_review', 'endorsed', 'pending_final_approval', 'returned_for_revision', 'resubmitted', 'approved', 'completed', 'rejected'] as $s): ?>
            <option value="<?= $s ?>" <?= $filterStat === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($search || $filterStat): ?><a href="activities.php" class="btn btn-outline">Clear</a><?php endif; ?>
        <span class="filters-count" style="margin-left:auto;font-size:0.82rem;color:var(--text-muted);font-weight:500;"><?= number_format($totalCount) ?> activit<?= $totalCount === 1 ? 'y' : 'ies' ?></span>
      </form>

      <!-- Activities Table Card -->
      <div class="card">
        <div class="card-header">
          <h2>📋 All Activities</h2>
        </div>
        <div class="card-body" style="padding:0;">
          <div class="table-wrap">
            <table class="activity-table">
              <thead>
                <tr>
                  <th style="width:34%;">Title</th>
                  <th style="width:18%;">Faculty</th>
                  <th style="width:12%;">Source</th>
                  <th style="width:12%;">Date</th>
                  <th style="width:12%;">Status</th>
                  <th style="width:12%;text-align:right;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($activities)): ?>
                  <tr>
                    <td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">No activities found.</td>
                  </tr>
                <?php else: foreach ($activities as $a): ?>
                  <tr>
                    <td><strong><a href="<?= BASE_URL ?>/admin2/view-activity.php?id=<?= $a['id'] ?>" style="color:var(--text-main);text-decoration:none;"><?= htmlspecialchars($a['title']) ?></a></strong></td>
                    <td><?= htmlspecialchars($a['fn']) ?></td>
                    <td><span class="badge badge-secondary"><?= ucfirst(str_replace('_', ' ', $a['source'])) ?></span></td>
                    <td><?= $a['event_date'] ? date('M j, Y', strtotime($a['event_date'])) : '—' ?></td>
                    <td><?= getStatusBadge($a['status']) ?></td>
                    <td style="text-align:right;">
                      <div style="display:inline-flex;gap:6px;align-items:center;justify-content:flex-end;">
                        <a href="<?= BASE_URL ?>/admin2/view-activity.php?id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">View</a>
                        <?php
                        $canAdmin2Review =
                            ($a['source'] === 'faculty' && in_array($a['status'], ['submitted', 'resubmitted'], true))
                            || ($a['source'] === 'student_org' && $a['status'] === 'endorsed');
                        if ($canAdmin2Review): ?>
                          <a href="<?= BASE_URL ?>/admin2/review.php?id=<?= $a['id'] ?>" class="btn btn-primary btn-sm">Review</a>
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

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
        <div class="pagination">
          <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($filterStat) ?>">« Prev</a>
          <?php endif; ?>
          <?php for ($p = 1; $p <= $totalPages; $p++):
            if (abs($p - $page) <= 2 || $p === 1 || $p === $totalPages): ?>
              <<?= $p === $page ? 'span class="active"' : 'a href="?page=' . $p . '&q=' . urlencode($search) . '&status=' . urlencode($filterStat) . '"' ?>><?= $p ?></<?= $p === $page ? 'span' : 'a' ?>>
            <?php elseif (abs($p - $page) === 3): echo '<span>…</span>';
            endif;
          endfor; ?>
          <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($filterStat) ?>">Next »</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

    </div>
  </div>
</body>
</html>
