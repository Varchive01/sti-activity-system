<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin1');
$user = currentUser();
$db   = getDB();

// Fetch all activities relevant to this role
$where = '';
if ($user['role'] === 'faculty') $where = "WHERE a.faculty_id = {$user['id']}";
elseif ($user['role'] === 'admin1') $where = "WHERE a.source = 'student_org'";

$activities = $db->query("SELECT a.*,u.name as faculty_name FROM activities a JOIN users u ON a.faculty_id=u.id $where ORDER BY a.updated_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Kpi Reports – STI Activity System</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
</head>
<body class="theme-arjay">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">Kpi Reports</div>
  </header>
  <div class="content">
    <div class="card">
      <div class="card-header"><h2>Kpi Reports</h2></div>
      <div class="card-body" style="padding:0;">
        <div class="table-wrap">
          <table>
            <thead><tr><th>Title</th><th>Faculty</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (empty($activities)): ?>
            <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text-muted);">No records found.</td></tr>
            <?php else: foreach ($activities as $a): ?>
            <tr>
              <td><strong><?= htmlspecialchars($a['title']) ?></strong></td>
              <td><?= htmlspecialchars($a['faculty_name']) ?></td>
              <td><?= $a['event_date'] ? date('M j, Y', strtotime($a['event_date'])) : '—' ?></td>
              <td><?= getStatusBadge($a['status']) ?></td>
              <td><a href="<?= BASE_URL ?>/admin1/view-activity.php?id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">View</a></td>
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
