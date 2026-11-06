<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty', 'program_head');
$user = currentUser();
$db   = getDB();

$hasCollabTable = true;
try {
  $db->query("SELECT 1 FROM proposal_collaborators LIMIT 1");
} catch (\PDOException $e) {
  $hasCollabTable = false;
}

// User department resolution
$userDept = resolveUserDepartment($db, $user);

// Returned proposals are strictly scoped to proposals where the user is the primary author/owner
// or an explicitly added active co-author/collaborator who has editing permissions.
if ($hasCollabTable) {
  $where = "WHERE (a.faculty_id = {$user['id']} OR EXISTS (SELECT 1 FROM proposal_collaborators pc WHERE pc.activity_id = a.id AND pc.user_id = {$user['id']} AND pc.status = 'active')) AND a.status IN ('returned_for_revision', 'rejected')";
} else {
  $where = "WHERE a.faculty_id = {$user['id']} AND a.status IN ('returned_for_revision', 'rejected')";
}

$activities = $db->query("SELECT a.*,u.name as faculty_name FROM activities a JOIN users u ON a.faculty_id=u.id $where ORDER BY a.updated_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta charset="UTF-8">
  <title>Returned &amp; Rejected – STI Activity System</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
</head>

<body class="theme-faculty">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">Returned &amp; Rejected</div>

      <div class="topbar-right">
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
        <!-- User Profile Control -->
        <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
      </div>
    </header>
    <div class="content">
      <div class="card">
        <div class="card-header">
          <h2>Returned &amp; Rejected</h2>
        </div>
        <div class="card-body" style="padding:0;">
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Title</th>
                  <th>Faculty</th>
                  <th>Date</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($activities)): ?>
                  <tr>
                    <td colspan="5" style="text-align:center;padding:32px;color:var(--text-muted);">No records found.</td>
                  </tr>
                  <?php else: foreach ($activities as $a): ?>
                    <tr>
                      <td><strong><?= htmlspecialchars($a['title']) ?></strong></td>
                      <td><?= htmlspecialchars($a['faculty_name']) ?></td>
                      <td><?= $a['event_date'] ? date('M j, Y', strtotime($a['event_date'])) : '—' ?></td>
                      <td><?= getStatusBadge($a['status']) ?></td>
                      <td><a href="<?= BASE_URL ?>/faculty/view-activity.php?id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">View</a></td>
                    </tr>
                <?php endforeach;
                endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>

</html>