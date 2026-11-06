<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('dean');
$user = currentUser();
$db = getDB();
$deanId = (int)$user['id'];

$search     = sanitize($_GET['q'] ?? '');
$filterStat = sanitize($_GET['status'] ?? '');
$filterSrc  = sanitize($_GET['source'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 15;
$offset     = ($page - 1) * $perPage;

// Dean scoping: Only activities that have reached the Dean for final approval ('pending_final_approval'),
// have been approved or completed, or were returned/rejected by the Dean directly.
// Preliminary review stages (submitted, under_review, endorsed) and pre-Dean rejections are excluded.
$where  = "(a.status IN ('pending_final_approval', 'approved', 'completed') OR (a.status IN ('returned_for_revision', 'rejected') AND EXISTS (SELECT 1 FROM approval_logs al WHERE al.activity_id = a.id AND al.reviewer_id = ?)))";
$params = [$deanId];

if ($search) {
  $where .= " AND a.title LIKE ?";
  $params[] = "%$search%";
}
$hasArchivedCol = hasActivityArchiveColumn($db);
if ($filterStat === 'archived') {
  if ($hasArchivedCol) {
    $where .= " AND a.is_archived = 1";
  } else {
    $where .= " AND 1=0";
  }
} else {
  if ($hasArchivedCol) {
    $where .= " AND (a.is_archived IS NULL OR a.is_archived = 0)";
  }
if ($filterStat) {
  $filterStatLower = strtolower($filterStat);
  if (in_array($filterStatLower, ['draft', 'submitted', 'under_review', 'endorsed', 'resubmitted'], true)) {
    $where .= " AND 1=0";
  } elseif (in_array($filterStatLower, ['pending_final_approval', 'approved', 'completed'], true)) {
    $where .= " AND a.status = ?";
    $params[] = $filterStat;
  } elseif (in_array($filterStatLower, ['returned_for_revision', 'rejected'], true)) {
    $where .= " AND a.status = ? AND EXISTS (SELECT 1 FROM approval_logs al WHERE al.activity_id = a.id AND al.reviewer_id = ?)";
    $params[] = $filterStat;
    $params[] = $deanId;
  } else {
    $where .= " AND a.status = ?";
    $params[] = $filterStat;
  }
}
}
if ($filterSrc) {
  $where .= " AND a.source = ?";
  $params[] = $filterSrc;
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
<?php include __DIR__ . '/../includes/head-theme-init.php'; ?>
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

<body class="theme-dean">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">All Activities — System View</div>
      <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
        <button type="button" class="btn btn-outline btn-sm" onclick="openUniversalArchiveModal()" style="display:inline-flex;align-items:center;gap:6px;" title="Archive completed activities by month or overall"><span>📦</span> Universal Archive</button>
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
        <a href="<?= BASE_URL ?>/dean/generate-report.php" class="btn btn-primary btn-sm">Generate Report</a>
        <!-- User Profile Control -->
        <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
      </div>
    </header>
    <div class="content">

      <!-- Search & Filters -->
      <form method="GET" class="filters">
        <input type="text" name="q" class="form-control" placeholder="Search title…" value="<?= htmlspecialchars($search) ?>" style="max-width:260px;">
        <select name="status" class="form-control" style="max-width:190px;">
          <option value="">All Statuses</option>
          <option value="archived" <?= ($filterStat==='archived')?'selected':'' ?>>📦 Archived</option>
          <option value="pending_final_approval" <?= $filterStat === 'pending_final_approval' ? 'selected' : '' ?>>Pending Final Approval</option>
          <option value="approved" <?= $filterStat === 'approved' ? 'selected' : '' ?>>Approved</option>
          <option value="completed" <?= $filterStat === 'completed' ? 'selected' : '' ?>>Completed</option>
          <option value="returned_for_revision" <?= $filterStat === 'returned_for_revision' ? 'selected' : '' ?>>Returned for Revision</option>
          <option value="rejected" <?= $filterStat === 'rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
        <select name="source" class="form-control" style="max-width:170px;">
          <option value="">All Sources</option>
          <option value="student_org" <?= $filterSrc === 'student_org' ? 'selected' : '' ?>>Student Org</option>
          <option value="faculty" <?= $filterSrc === 'faculty' ? 'selected' : '' ?>>Faculty</option>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($search || $filterStat || $filterSrc): ?><a href="activities.php" class="btn btn-outline">Clear</a><?php endif; ?>
        <span class="filters-count" style="margin-left:auto;font-size:0.82rem;color:var(--text-muted);font-weight:500;"><?= number_format($totalCount) ?> total</span>
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
                  <th style="width:40px;">#</th>
                  <th>Title</th>
                  <th>Faculty</th>
                  <th style="width:110px;">Source</th>
                  <th style="width:115px;">Date</th>
                  <th style="width:125px;">Status</th>
                  <th style="width:85px;">KPI</th>
                  <th style="width:110px;text-align:right;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($activities)): ?>
                  <tr>
                    <td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted);">No activities found.</td>
                  </tr>
                <?php else:
                  $i = ($page - 1) * $perPage;
                  foreach ($activities as $a):
                    $kpi = $db->prepare("SELECT AVG(rating) as avg FROM kpi_evaluations WHERE activity_id=?");
                    $kpi->execute([$a['id']]);
                    $kpiVal = $kpi->fetchColumn();
                    $i++;
                ?>
                  <tr>
                    <td class="text-muted"><?= $i ?></td>
                    <td><strong><a href="<?= BASE_URL ?>/dean/view-activity.php?id=<?= $a['id'] ?>" style="color:var(--text-main);text-decoration:none;"><?= htmlspecialchars($a['title']) ?></a></strong></td>
                    <td><?= htmlspecialchars($a['fn']) ?></td>
                    <td><span class="badge badge-secondary"><?= ucfirst(str_replace('_', ' ', $a['source'])) ?></span></td>
                    <td><?= $a['event_date'] ? date('M j, Y', strtotime($a['event_date'])) : '—' ?></td>
                    <td><?= getStatusBadge($a['status']) ?></td>
                    <td><?= $kpiVal ? '<strong>' . number_format($kpiVal, 1) . '</strong>/4' : '—' ?></td>
                    <td style="text-align:right;">
                      <div style="display:inline-flex;gap:6px;align-items:center;justify-content:flex-end;">
                        <?php if ($a['status'] === 'pending_final_approval'): ?>
                          <a href="<?= BASE_URL ?>/dean/review.php?id=<?= $a['id'] ?>" class="btn btn-primary btn-sm">Review</a>
                        <?php else: ?>
                          <a href="<?= BASE_URL ?>/dean/view-activity.php?id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">View</a>
                          <?php if (!empty($a['is_archived'])): ?>
                            <button type="button" class="btn btn-outline btn-sm" onclick="confirmUnarchiveActivity(<?= (int)$a['id'] ?>, '<?= htmlspecialchars(addslashes($a['title']), ENT_QUOTES) ?>')">Unarchive</button>
                          <?php elseif ($a['status'] === 'completed'): ?>
                            <button type="button" class="btn btn-outline btn-sm" onclick="confirmArchiveSingleActivity(<?= (int)$a['id'] ?>, '<?= htmlspecialchars(addslashes($a['title']), ENT_QUOTES) ?>')">Archive</button>
                          <?php endif; ?>
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
            <a href="?page=<?= $page - 1 ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($filterStat) ?>&source=<?= urlencode($filterSrc) ?>">← Prev</a>
          <?php endif; ?>
          <?php for ($p = 1; $p <= $totalPages; $p++):
            if (abs($p - $page) <= 2 || $p === 1 || $p === $totalPages): ?>
              <<?= $p === $page ? 'span class="active"' : 'a href="?page=' . $p . '&q=' . urlencode($search) . '&status=' . urlencode($filterStat) . '&source=' . urlencode($filterSrc) . '"' ?>><?= $p ?></<?= $p === $page ? 'span' : 'a' ?>>
            <?php elseif (abs($p - $page) === 3): echo '<span>…</span>';
            endif;
          endfor; ?>
          <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($filterStat) ?>&source=<?= urlencode($filterSrc) ?>">Next →</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

    </div>
  </div>
</body>

</html>
