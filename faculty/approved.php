<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty', 'program_head');
$user = currentUser();
$db   = getDB();

$hasCollabTable = hasCollaboratorsTable($db);
$hasTasksTable  = hasFacultyTasksTable($db);

if ($user['role'] === 'program_head') {
    $userDept = resolveUserDepartment($db, $user);
    $deptCond = buildDepartmentSqlCondition('u.department', $userDept);
    $phConds = ["({$deptCond})", "a.faculty_id = {$user['id']}"];
    if ($hasCollabTable) {
        $phConds[] = "EXISTS (SELECT 1 FROM proposal_collaborators pc WHERE pc.activity_id = a.id AND pc.user_id = {$user['id']} AND pc.status = 'active')";
    }
    $where = "WHERE (" . implode(" OR ", $phConds) . ") AND a.status IN ('approved', 'completed')";
} else {
    $facConds = ["a.faculty_id = {$user['id']}"];
    if ($hasCollabTable) {
        $facConds[] = "EXISTS (SELECT 1 FROM proposal_collaborators pc WHERE pc.activity_id = a.id AND pc.user_id = {$user['id']} AND pc.status = 'active')";
    }
    if ($hasTasksTable) {
        $facConds[] = "EXISTS (SELECT 1 FROM faculty_tasks ft WHERE ft.activity_id = a.id AND ft.assigned_user_id = {$user['id']})";
    }
    $where = "WHERE (" . implode(" OR ", $facConds) . ") AND a.status IN ('approved', 'completed')";
}

$activities = [];
try {
    $activities = $db->query("SELECT a.*,u.name as faculty_name FROM activities a JOIN users u ON a.faculty_id=u.id $where ORDER BY a.updated_at DESC")->fetchAll();
} catch (\Throwable $e) {
    error_log("Faculty approved activities query fallback triggered: " . $e->getMessage());
    try {
        $activities = $db->query("SELECT a.*,u.name as faculty_name FROM activities a JOIN users u ON a.faculty_id=u.id WHERE a.faculty_id = {$user['id']} AND a.status IN ('approved', 'completed') ORDER BY a.updated_at DESC")->fetchAll();
    } catch (\Throwable $e2) {
        $activities = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/head-theme-init.php'; ?>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta charset="UTF-8">
<title>Approved Activities – STI Activity System</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
</head>
<body class="theme-faculty">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">Approved Activities</div>
  
    <div class="topbar-right">
      <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>
  <div class="content">
    <div class="card">
      <div class="card-header"><h2>Approved Activities</h2></div>
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
              <td><a href="<?= BASE_URL ?>/faculty/view-activity.php?id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">View</a></td>
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
