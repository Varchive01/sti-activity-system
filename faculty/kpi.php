<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');
$user = currentUser();
$db   = getDB();

$reports = $db->prepare("
    SELECT a.title, a.event_date, a.target_participants, pe.*,
           AVG(k.rating) as avg_kpi, COUNT(k.id) as kpi_count
    FROM activities a
    JOIN post_event pe ON a.id=pe.activity_id
    LEFT JOIN kpi_evaluations k ON a.id=k.activity_id
    WHERE a.faculty_id=?
    GROUP BY a.id, pe.id
    ORDER BY a.event_date DESC
");
$reports->execute([$user['id']]);
$allReports = $reports->fetchAll();

function kpiClass($avg) {
    if ($avg >= 3.5) return ['Excellent','badge-success'];
    if ($avg >= 2.5) return ['Very Satisfactory','badge-info'];
    if ($avg >= 1.5) return ['Satisfactory','badge-warning'];
    return ['Needs Improvement','badge-danger'];
}
function attendanceClass($actual, $target) {
    if (!$target) return ['—','badge-secondary'];
    $pct = ($actual / $target) * 100;
    if ($pct >= 100) return ['Exceeded Target','badge-success'];
    if ($pct >= 90)  return ['Met Target','badge-info'];
    if ($pct >= 70)  return ['Close to Target','badge-warning'];
    return ['Did Not Meet Target','badge-danger'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>KPI Results – STI Activity System</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
<style>
.kpi-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:22px;margin-bottom:18px;}
.kpi-card .event-name{font-family:'Syne',sans-serif;font-size:1rem;font-weight:800;margin-bottom:4px;}
.kpi-metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:16px 0;}
.kpi-metric{background:var(--bg-base);border-radius:8px;padding:12px 14px;text-align:center;}
.kpi-metric .val{font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;}
.kpi-metric .lbl{font-size:.68rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.3px;}
.progress-bar{height:8px;background:var(--border);border-radius:999px;overflow:hidden;margin-top:8px;}
.progress-fill{height:100%;border-radius:999px;background:var(--accent);}
.criteria-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px;}
.criteria-item{display:flex;align-items:center;justify-content:space-between;background:var(--bg-base);border-radius:6px;padding:8px 12px;font-size:.78rem;}
.star-rating{color:var(--sti-gold);font-size:.9rem;}
</style>
</head>
<body class="theme-faculty">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">KPI Results</div>
  </header>
  <div class="content">
    <?php if (empty($allReports)): ?>
    <div class="card">
      <div class="card-body" style="text-align:center;padding:60px;">
        <div style="font-size:3rem;margin-bottom:12px;">📊</div>
        <div style="font-weight:600;margin-bottom:6px;">No KPI Data Yet</div>
        <div class="text-muted text-sm">Submit post-event reports to see KPI results here.</div>
        <a href="<?= BASE_URL ?>/faculty/post-event.php" class="btn btn-primary" style="margin-top:16px;">Upload Post-Event Report</a>
      </div>
    </div>
    <?php else: ?>

    <?php foreach ($allReports as $r):
      [$kpiLabel, $kpiClass] = kpiClass($r['avg_kpi'] ?? 0);
      [$attLabel, $attClass] = attendanceClass($r['actual_attendance'], $r['target_attendance']);
      $attPct = $r['target_attendance'] > 0 ? min(round(($r['actual_attendance']/$r['target_attendance'])*100),100) : 0;
      $kpiPct = $r['avg_kpi'] > 0 ? round(($r['avg_kpi']/4)*100) : 0;

      // Get individual KPI breakdown
      $breakdown = $db->prepare("SELECT criteria, AVG(rating) as avg FROM kpi_evaluations WHERE activity_id=? GROUP BY criteria");
      $breakdown->execute([$r['activity_id']]);
      $kpiDetails = $breakdown->fetchAll();
    ?>
    <div class="kpi-card">
      <div class="event-name"><?= htmlspecialchars($r['title']) ?></div>
      <div class="text-sm text-muted"><?= $r['event_date'] ? date('F j, Y', strtotime($r['event_date'])) : '—' ?></div>

      <div class="kpi-metrics">
        <div class="kpi-metric">
          <div class="val"><?= $r['actual_attendance'] ?></div>
          <div class="lbl">Actual Attendance</div>
        </div>
        <div class="kpi-metric">
          <div class="val"><?= $r['target_attendance'] ?></div>
          <div class="lbl">Target</div>
        </div>
        <div class="kpi-metric">
          <div class="val"><?= $attPct ?>%</div>
          <div class="lbl">Attendance Rate</div>
        </div>
        <div class="kpi-metric">
          <div class="val"><?= $r['avg_kpi'] ? number_format($r['avg_kpi'],2) : '—' ?></div>
          <div class="lbl">Avg KPI / 4.0</div>
        </div>
      </div>

      <div style="display:flex;gap:8px;margin-bottom:14px;">
        <span class="badge <?= $attClass ?>"><?= $attLabel ?></span>
        <?php if ($r['avg_kpi']): ?><span class="badge <?= $kpiClass ?>"><?= $kpiLabel ?></span><?php endif; ?>
        <?php if ($r['satisfaction_score']): ?><span class="badge badge-primary">Satisfaction: <?= number_format($r['satisfaction_score'],1) ?>/5</span><?php endif; ?>
      </div>

      <!-- Attendance bar -->
      <div style="margin-bottom:14px;">
        <div class="flex justify-between text-sm" style="margin-bottom:4px;">
          <span class="text-muted">Attendance Progress</span>
          <span class="fw-bold"><?= $attPct ?>%</span>
        </div>
        <div class="progress-bar"><div class="progress-fill" style="width:<?= $attPct ?>%;background:<?= $attPct>=90?'var(--success)':($attPct>=70?'var(--warning)':'var(--danger)') ?>"></div></div>
      </div>

      <!-- KPI breakdown -->
      <?php if (!empty($kpiDetails)): ?>
      <div class="text-sm fw-bold" style="margin-bottom:6px;">KPI Breakdown</div>
      <div class="criteria-grid">
        <?php foreach ($kpiDetails as $kd): $stars = round($kd['avg']); ?>
        <div class="criteria-item">
          <span><?= htmlspecialchars($kd['criteria']) ?></span>
          <div>
            <span class="star-rating"><?= str_repeat('★', $stars).str_repeat('☆', 4-$stars) ?></span>
            <strong style="margin-left:4px;font-size:.75rem;"><?= number_format($kd['avg'],1) ?></strong>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Narrative -->
      <?php if ($r['observations']): ?>
      <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
        <div class="text-sm fw-bold" style="margin-bottom:6px;">Observations</div>
        <div class="text-sm text-muted"><?= nl2br(htmlspecialchars($r['observations'])) ?></div>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>
  </div>
</div>
</body>
</html>
