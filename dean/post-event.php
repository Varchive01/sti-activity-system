<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('dean');
$user = currentUser();
$db   = getDB();

$search  = sanitize($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

// Dean oversees all completed institutional activities with post-event records
$where = "a.status = 'completed' AND pe.id IS NOT NULL";
$params = [];

$hasArchivedCol = function_exists('hasActivityArchiveColumn') ? hasActivityArchiveColumn($db) : false;
if ($hasArchivedCol) {
    $where .= " AND (a.is_archived IS NULL OR a.is_archived = 0)";
}

if ($search) {
    $where .= " AND (a.title LIKE ? OR u.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Total Count
$countStmt = $db->prepare("
    SELECT COUNT(*) 
    FROM activities a 
    JOIN users u ON a.faculty_id = u.id 
    JOIN post_event pe ON a.id = pe.activity_id 
    WHERE $where
");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalCount / $perPage);

// Paginated Records
$actStmt = $db->prepare("
    SELECT a.*, u.name AS faculty_name, pe.submitted_at AS pe_submitted_at
    FROM activities a 
    JOIN users u ON a.faculty_id = u.id 
    JOIN post_event pe ON a.id = pe.activity_id 
    WHERE $where 
    ORDER BY pe.submitted_at DESC, a.updated_at DESC 
    LIMIT $perPage OFFSET $offset
");
$actStmt->execute($params);
$activities = $actStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . '/../includes/head-theme-init.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Post-Event Results – STI Activity System</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=1.0.8">
</head>
<body class="theme-dean">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">Post-Event Results</div>
      <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
        <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
      </div>
    </header>

    <div class="content">

      <!-- Search & Filters -->
      <form method="GET" class="filters">
        <input type="text" name="q" class="form-control" placeholder="Search by activity title or proponent…" value="<?= htmlspecialchars($search) ?>" style="max-width:340px;">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($search): ?>
          <a href="post-event.php" class="btn btn-outline">Clear</a>
        <?php endif; ?>
        <span class="filters-count" style="margin-left:auto;font-size:0.82rem;color:var(--text-muted);font-weight:500;">
          <?= number_format($totalCount) ?> result<?= $totalCount === 1 ? '' : 's' ?>
        </span>
      </form>

      <!-- Post-Event Results Table Card -->
      <div class="card">
        <div class="card-header">
          <h2>📊 Completed Activities — Post-Event Documentation</h2>
        </div>
        <div class="card-body" style="padding:0;">
          <div class="table-wrap">
            <table class="activity-table">
              <thead>
                <tr>
                  <th style="width:44%;">Title</th>
                  <th style="width:26%;">Responsible Proponent</th>
                  <th style="width:18%;">Activity Date</th>
                  <th style="width:12%;text-align:right;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($activities)): ?>
                  <tr>
                    <td colspan="4" style="text-align:center;padding:40px;color:var(--text-muted);">
                      No completed activities with post-event documentation found.
                    </td>
                  </tr>
                <?php else: foreach ($activities as $a): ?>
                  <tr>
                    <td>
                      <strong><?= htmlspecialchars($a['title']) ?></strong>
                      <span class="badge <?= ($a['source'] ?? '') === 'student_org' ? 'badge-info' : 'badge-primary' ?>" style="font-size:0.7rem;margin-left:6px;">
                        <?= ($a['source'] ?? '') === 'student_org' ? 'Student Org' : 'Faculty' ?>
                      </span>
                    </td>
                    <td><?= htmlspecialchars($a['faculty_name']) ?></td>
                    <td><?= $a['event_date'] ? date('M j, Y', strtotime($a['event_date'])) : '—' ?></td>
                    <td style="text-align:right;">
                      <a href="<?= BASE_URL ?>/dean/post-event-view.php?id=<?= (int)$a['id'] ?>" class="btn btn-outline btn-sm">View</a>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
        <div class="pagination">
          <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>&q=<?= urlencode($search) ?>">« Prev</a>
          <?php endif; ?>
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?= $i ?>&q=<?= urlencode($search) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>&q=<?= urlencode($search) ?>">Next »</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

    </div>
  </div>
</body>
</html>
