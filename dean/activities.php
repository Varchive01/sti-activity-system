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
$filterDept = sanitize($_GET['department'] ?? '');
$filterDate = sanitize($_GET['date'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 15;
$offset     = ($page - 1) * $perPage;

// Discover available departments from database
$deptList = [];
try {
    $deptStmt = $db->query("
        SELECT DISTINCT department FROM users 
        WHERE department IS NOT NULL AND TRIM(department) != ''
        ORDER BY department ASC
    ");
    $deptList = $deptStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (\Throwable $e) {}

// Dean institutional oversight: The Dean can view all officially submitted proposals across all departments
// and activity sources, regardless of current status (submitted, under_review, endorsed, pending_final_approval,
// approved, completed, returned_for_revision, rejected).
// Private unsubmitted drafts belonging to faculty/program heads remain hidden.
$where = "(a.status != 'draft' OR a.faculty_id = ? OR EXISTS (SELECT 1 FROM proposal_collaborators pc WHERE pc.activity_id = a.id AND pc.user_id = ? AND pc.status = 'active'))";
$params = [$deanId, $deanId];

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
    if ($filterStatLower === 'draft') {
      $where .= " AND a.status = 'draft' AND (a.faculty_id = ? OR EXISTS (SELECT 1 FROM proposal_collaborators pc WHERE pc.activity_id = a.id AND pc.user_id = ? AND pc.status = 'active'))";
      $params[] = $deanId;
      $params[] = $deanId;
    } elseif (in_array($filterStatLower, ['under_review', 'submitted'], true)) {
      $where .= " AND a.status IN ('submitted', 'under_review')";
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

if ($filterDept) {
  $normDeptFilter = function_exists('normalizeDepartmentName') ? normalizeDepartmentName($filterDept) : $filterDept;
  if ($normDeptFilter === 'IT') {
    $where .= " AND (u.department = 'IT Department' OR u.department LIKE '%IT%' OR u.department LIKE '%Information Technology%')";
  } elseif ($normDeptFilter === 'THM') {
    $where .= " AND (u.department = 'THM Department' OR u.department LIKE '%THM%' OR u.department LIKE '%Hospitality%' OR u.department LIKE '%Tourism%')";
  } elseif ($normDeptFilter === 'GE') {
    $where .= " AND (u.department = 'G.E. Department' OR u.department LIKE '%G.E%' OR u.department LIKE '%General Education%')";
  } else {
    $where .= " AND u.department = ?";
    $params[] = $filterDept;
  }
}

if ($filterDate) {
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $where .= " AND a.event_date = ?";
    $params[] = $filterDate;
  } elseif (preg_match('/^\d{4}-\d{2}$/', $filterDate)) {
    $where .= " AND DATE_FORMAT(a.event_date, '%Y-%m') = ?";
    $params[] = $filterDate;
  } elseif (preg_match('/^\d{4}$/', $filterDate)) {
    $where .= " AND YEAR(a.event_date) = ?";
    $params[] = $filterDate;
  }
}

$total = $db->prepare("SELECT COUNT(*) FROM activities a JOIN users u ON a.faculty_id = u.id WHERE $where");
$total->execute($params);
$totalCount = (int)$total->fetchColumn();
$totalPages = ceil($totalCount / $perPage);

$acts = $db->prepare("
  SELECT a.*, u.name as fn, u.department as dept,
         (SELECT al.notes FROM approval_logs al WHERE al.activity_id = a.id AND al.action IN ('returned', 'rejected', 'returned_for_revision') ORDER BY al.id DESC LIMIT 1) as last_review_notes,
         (SELECT u_rev.name FROM approval_logs al JOIN users u_rev ON al.reviewer_id = u_rev.id WHERE al.activity_id = a.id AND al.action IN ('returned', 'rejected', 'returned_for_revision') ORDER BY al.id DESC LIMIT 1) as last_reviewer_name,
         (SELECT u_rev.role FROM approval_logs al JOIN users u_rev ON al.reviewer_id = u_rev.id WHERE al.activity_id = a.id AND al.action IN ('returned', 'rejected', 'returned_for_revision') ORDER BY al.id DESC LIMIT 1) as last_reviewer_role
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
      <form method="GET" class="filters" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:20px;">
        <input type="text" name="q" class="form-control" placeholder="Search title…" value="<?= htmlspecialchars($search) ?>" style="max-width:220px;">
        
        <select name="status" class="form-control" style="max-width:180px;">
          <option value="">All Statuses</option>
          <option value="under_review" <?= in_array($filterStat, ['under_review', 'submitted'], true) ? 'selected' : '' ?>>Under Review</option>
          <option value="endorsed" <?= $filterStat === 'endorsed' ? 'selected' : '' ?>>Endorsed</option>
          <option value="pending_final_approval" <?= $filterStat === 'pending_final_approval' ? 'selected' : '' ?>>Pending Final Approval</option>
          <option value="approved" <?= $filterStat === 'approved' ? 'selected' : '' ?>>Approved</option>
          <option value="completed" <?= $filterStat === 'completed' ? 'selected' : '' ?>>Completed</option>
          <option value="returned_for_revision" <?= $filterStat === 'returned_for_revision' ? 'selected' : '' ?>>Returned for Revision</option>
          <option value="rejected" <?= $filterStat === 'rejected' ? 'selected' : '' ?>>Rejected</option>
          <option value="archived" <?= ($filterStat==='archived')?'selected':'' ?>>📦 Archived</option>
        </select>

        <select name="department" class="form-control" style="max-width:180px;">
          <option value="">All Departments</option>
          <?php foreach ($deptList as $dName): ?>
            <option value="<?= htmlspecialchars($dName) ?>" <?= $filterDept === $dName ? 'selected' : '' ?>><?= htmlspecialchars($dName) ?></option>
          <?php endforeach; ?>
        </select>

        <select name="source" class="form-control" style="max-width:150px;">
          <option value="">All Sources</option>
          <option value="student_org" <?= $filterSrc === 'student_org' ? 'selected' : '' ?>>Student Org</option>
          <option value="faculty" <?= $filterSrc === 'faculty' ? 'selected' : '' ?>>Faculty</option>
        </select>

        <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($filterDate) ?>" style="max-width:150px;" title="Filter by event date">

        <button type="submit" class="btn btn-primary btn-sm" style="height:36px;padding:0 14px;">Filter</button>
        <?php if ($search || $filterStat || $filterSrc || $filterDept || $filterDate): ?>
          <a href="activities.php" class="btn btn-outline btn-sm" style="height:36px;display:inline-flex;align-items:center;padding:0 12px;">Clear</a>
        <?php endif; ?>
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
                  <th style="width:35px;">#</th>
                  <th>Title</th>
                  <th>Faculty / Proponent</th>
                  <th style="width:130px;">Department</th>
                  <th style="width:105px;">Source</th>
                  <th style="width:110px;">Date</th>
                  <th style="width:155px;">Status</th>
                  <th style="width:75px;">KPI</th>
                  <th style="width:105px;text-align:right;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($activities)): ?>
                  <tr>
                    <td colspan="9" style="text-align:center;padding:40px;color:var(--text-muted);">No activities found matching your criteria.</td>
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
                    <td><?= htmlspecialchars($a['dept'] ?: '—') ?></td>
                    <td><span class="badge badge-secondary"><?= ucfirst(str_replace('_', ' ', $a['source'])) ?></span></td>
                    <td><?= $a['event_date'] ? date('M j, Y', strtotime($a['event_date'])) : '—' ?></td>
                    <td>
                      <?= getStatusBadge($a['status']) ?>
                      <?php if (in_array($a['status'], ['returned_for_revision', 'rejected'], true)): ?>
                        <?php if (!empty($a['last_reviewer_name'])): ?>
                          <div style="font-size:0.72rem;color:var(--text-muted);margin-top:3px;line-height:1.2;" title="<?= htmlspecialchars($a['last_review_notes'] ?: $a['revision_notes'] ?: 'No notes provided') ?>">
                            By <?= htmlspecialchars($a['last_reviewer_name']) ?> <span style="opacity:0.85;">(<?= htmlspecialchars(ucwords(str_replace('_',' ',$a['last_reviewer_role'] ?? ''))) ?>)</span>
                          </div>
                        <?php elseif (!empty($a['revision_notes'])): ?>
                          <div style="font-size:0.72rem;color:var(--text-muted);margin-top:3px;line-height:1.2;" title="<?= htmlspecialchars($a['revision_notes']) ?>">
                            Has review notes
                          </div>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>
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
      <?php if ($totalPages > 1): 
        $filterQueryArr = array_filter([
          'q'          => $search,
          'status'     => $filterStat,
          'source'     => $filterSrc,
          'department' => $filterDept,
          'date'       => $filterDate,
        ], fn($v) => $v !== '' && $v !== null);
        $filterQueryStr = http_build_query($filterQueryArr);
        $pageUrl = fn($p) => '?page=' . $p . ($filterQueryStr ? '&' . $filterQueryStr : '');
      ?>
        <div class="pagination">
          <?php if ($page > 1): ?>
            <a href="<?= $pageUrl($page - 1) ?>">← Prev</a>
          <?php endif; ?>
          <?php for ($p = 1; $p <= $totalPages; $p++):
            if (abs($p - $page) <= 2 || $p === 1 || $p === $totalPages): ?>
              <<?= $p === $page ? 'span class="active"' : 'a href="' . $pageUrl($p) . '"' ?>><?= $p ?></<?= $p === $page ? 'span' : 'a' ?>>
            <?php elseif (abs($p - $page) === 3): echo '<span>…</span>';
            endif;
          endfor; ?>
          <?php if ($page < $totalPages): ?>
            <a href="<?= $pageUrl($page + 1) ?>">Next →</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

    </div>
  </div>
</body>

</html>
