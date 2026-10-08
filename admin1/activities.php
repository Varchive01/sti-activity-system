<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin1');
$user = currentUser();
$db   = getDB();

$search     = sanitize($_GET['q']      ?? '');
$filterStat = sanitize($_GET['status'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 15;
$offset     = ($page - 1) * $perPage;

$where  = "a.source='student_org'";
$params = [];
if ($search) {
  $where .= " AND a.title LIKE ?";
  $params[] = "%$search%";
}
if ($filterStat) {
  $where .= " AND a.status=?";
  $params[] = $filterStat;
}

$total = $db->prepare("SELECT COUNT(*) FROM activities a WHERE $where");
$total->execute($params);
$totalCount = (int)$total->fetchColumn();
$totalPages = ceil($totalCount / $perPage);

$acts = $db->prepare("
  SELECT a.*, u.name as fn 
  FROM activities a 
  JOIN users u ON a.faculty_id = u.id 
  WHERE $where 
  ORDER BY a.updated_at DESC 
  LIMIT $perPage OFFSET $offset
");
$acts->execute($params);
$activities = $acts->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Org Activities – STI Activity System</title>
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
</head>

<body class="theme-arjay">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">Student Org Activities</div>
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
          <?php foreach (['draft', 'submitted', 'under_review', 'endorsed', 'pending_final_approval', 'returned_for_revision', 'resubmitted', 'approved', 'completed', 'rejected'] as $s): ?>
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
                  <th style="width:38%;">Title</th>
                  <th style="width:22%;">Faculty</th>
                  <th style="width:14%;">Date</th>
                  <th style="width:14%;">Status</th>
                  <th style="width:12%;text-align:right;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($activities)): ?>
                  <tr>
                    <td colspan="5" style="text-align:center;padding:40px;color:var(--text-muted);">No activities found.</td>
                  </tr>
                <?php else: foreach ($activities as $a): ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($a['title']) ?></strong></td>
                    <td><?= htmlspecialchars($a['fn']) ?></td>
                    <td><?= $a['event_date'] ? date('M j, Y', strtotime($a['event_date'])) : '—' ?></td>
                    <td><?= getStatusBadge($a['status']) ?></td>
                    <td style="text-align:right;">
                      <div style="display:inline-flex;gap:6px;align-items:center;justify-content:flex-end;">
                        <a href="<?= BASE_URL ?>/admin1/view-activity.php?id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">View</a>
                        <?php if (in_array($a['status'], ['under_review', 'resubmitted'])): ?>
                          <a href="<?= BASE_URL ?>/admin1/review.php?id=<?= $a['id'] ?>" class="btn btn-primary btn-sm">Review</a>
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
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?= $i ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($filterStat) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($filterStat) ?>">Next »</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

    </div>
  </div>
</body>

</html>
