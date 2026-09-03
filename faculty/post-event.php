<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');
$user = currentUser();
$db   = getDB();

// Approved activities without a post-event report
$approved = $db->prepare("
    SELECT a.* FROM activities a
    LEFT JOIN post_event pe ON a.id = pe.activity_id
    WHERE a.faculty_id=? AND a.status='approved' AND pe.id IS NULL
    ORDER BY a.event_date DESC
");
$approved->execute([$user['id']]);
$eligibleActivities = $approved->fetchAll();

// Already submitted reports
$submitted = $db->prepare("
    SELECT a.title, a.event_date, pe.actual_attendance, pe.target_attendance, pe.satisfaction_score
    FROM post_event pe
    JOIN activities a ON pe.activity_id=a.id
    WHERE a.faculty_id=?
    ORDER BY pe.submitted_at DESC
");
$submitted->execute([$user['id']]);
$reports = $submitted->fetchAll();

$success    = isset($_GET['saved']);
$selectedId = (int)($_GET['activity_id'] ?? 0);
$selectedActivity = null;
if ($selectedId) {
  $sa = $db->prepare("SELECT * FROM activities WHERE id=? AND faculty_id=? AND status='approved'");
  $sa->execute([$selectedId, $user['id']]);
  $selectedActivity = $sa->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Upload Post-Event Report – STI Activity System</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
  <style>
    .activity-card {
      background: var(--bg-base);
      border: 2px solid var(--border);
      border-radius: 10px;
      padding: 14px 18px;
      margin-bottom: 10px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      transition: border-color .2s;
    }

    .activity-card.selected {
      border-color: var(--accent);
      background: var(--accent-lt);
    }

    .activity-card .title {
      font-weight: 600;
      font-size: .88rem;
    }

    .activity-card .meta {
      font-size: .75rem;
      color: var(--text-muted);
      margin-top: 2px;
    }

    .info-box {
      background: var(--bg-base);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 18px 20px;
      margin-bottom: 18px;
    }

    .info-box h3 {
      font-size: .88rem;
      font-weight: 700;
      margin-bottom: 10px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
  </style>
</head>

<body class="theme-faculty">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">Upload Post-Event Report</div>
    </header>
    <div class="content">
      <?php if ($success): ?>
        <div class="alert alert-success">✓ Post-event report uploaded successfully!</div>
      <?php endif; ?>

      <div style="display:grid;grid-template-columns:1fr 1.5fr;gap:24px;align-items:start;">

        <!-- Left: Select activity -->
        <div>
          <div class="card">
            <div class="card-header">
              <h2>Select Activity</h2>
            </div>
            <div class="card-body">
              <?php if (empty($eligibleActivities)): ?>
                <div style="text-align:center;padding:24px;color:var(--text-muted);">
                  <div style="font-size:2rem;margin-bottom:8px;">✓</div>
                  <div class="text-sm">All approved activities have reports uploaded.</div>
                </div>
                <?php else: foreach ($eligibleActivities as $a): ?>
                  <div class="activity-card <?= $selectedId === $a['id'] ? 'selected' : '' ?>">
                    <div>
                      <div class="title"><?= htmlspecialchars($a['title']) ?></div>
                      <div class="meta"><?= $a['event_date'] ? date('F j, Y', strtotime($a['event_date'])) : '—' ?> · <?= htmlspecialchars($a['venue'] ?? '') ?></div>
                    </div>
                    <a href="?activity_id=<?= $a['id'] ?>" class="btn btn-primary btn-sm">Upload</a>
                  </div>
              <?php endforeach;
              endif; ?>
            </div>
          </div>

          <!-- Submitted Reports -->
          <?php if (!empty($reports)): ?>
            <div class="card" style="margin-top:16px;">
              <div class="card-header">
                <h2>Submitted Reports</h2>
              </div>
              <div class="card-body" style="padding:0;">
                <table>
                  <thead>
                    <tr>
                      <th>Activity</th>
                      <th>Attendance</th>
                      <th>Score</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($reports as $r): ?>
                      <tr>
                        <td>
                          <strong><?= htmlspecialchars($r['title']) ?></strong><br>
                          <span class="text-sm text-muted"><?= $r['event_date'] ? date('M j, Y', strtotime($r['event_date'])) : '—' ?></span>
                        </td>
                        <td><?= isset($r['actual_attendance']) ? htmlspecialchars($r['actual_attendance']) : '0' ?>/<?= isset($r['target_attendance']) ? htmlspecialchars($r['target_attendance']) : '0' ?></td>
                        <td><span class="badge badge-success"><?= isset($r['satisfaction_score']) ? number_format($r['satisfaction_score'], 1) : '0.0' ?>/5</span></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <!-- Right: Upload Form -->
        <div>
          <?php if ($selectedActivity): ?>
            <div class="card">
              <div class="card-header">
                <h2>📋 <?= htmlspecialchars($selectedActivity['title']) ?></h2>
              </div>
              <div class="card-body">

                <div class="info-box">
                  <h3>ℹ️ How attendance and KPI ratings are collected</h3>
                  <p class="text-sm text-muted" style="line-height:1.6;">
                    Attendance count and KPI ratings are automatically gathered from students who scan the
                    <strong>QR code or evaluation link</strong> set up during the proposal. Your role here
                    is to upload any post-event documentation (photos, reports, certificates) and confirm
                    the event was conducted.
                  </p>
                </div>

                <form method="POST" action="<?= BASE_URL ?>/api/post-event-save.php" enctype="multipart/form-data">
                  <input type="hidden" name="activity_id" value="<?= $selectedActivity['id'] ?>">
                  <input type="hidden" name="target_attendance" value="<?= $selectedActivity['target_participants'] ?>">

                  <!-- Supporting Documents Only -->
                  <div class="info-box">
                    <h3>📎 Supporting Documents</h3>
                    <div class="form-group">
                      <label class="form-label">Upload Post-Event Files</label>
                      <input type="file" name="post_event_files[]" class="form-control" multiple
                        accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                      <small class="text-muted" style="font-size:.75rem;margin-top:4px;display:block;">
                        Accepted: photos, narrative report, attendance sheet, certificates (PDF, DOC, JPG, PNG)
                      </small>
                    </div>
                  </div>

                  <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:14px;">
                    ✅ Confirm Event Conducted &amp; Upload Documents
                  </button>
                </form>
              </div>
            </div>

          <?php else: ?>
            <div class="card">
              <div class="card-body" style="text-align:center;padding:60px;">
                <div style="font-size:3rem;margin-bottom:12px;">📋</div>
                <div style="font-weight:600;margin-bottom:6px;">Select an Activity</div>
                <div class="text-muted text-sm">Choose an approved activity on the left to upload its post-event report.</div>
              </div>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </div>
</body>

</html>