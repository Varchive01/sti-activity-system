<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';
requireRole('faculty', 'program_head');
$user = currentUser();
$db   = getDB();

$isProgramHead = ($user['role'] === 'program_head');

if ($isProgramHead) {
    // 1. Identify Program Head's assigned department
    $dept = resolveUserDepartment($db, $user);
    $normDept = normalizeDepartmentName($dept);
    $deptCond = buildDepartmentSqlCondition('u.department', $dept);

    // 2. Fetch all activities in this department (for aggregate performance metrics)
    $deptActsStmt = $db->prepare("
        SELECT a.*, u.name as faculty_name, u.department as faculty_dept,
               pe.actual_attendance, pe.target_attendance, pe.satisfaction_score, pe.recommendations,
               AVG(k.rating) as avg_kpi, COUNT(k.id) as kpi_count
        FROM activities a
        JOIN users u ON a.faculty_id = u.id
        LEFT JOIN post_event pe ON a.id = pe.activity_id
        LEFT JOIN kpi_evaluations k ON a.id = k.activity_id
        WHERE (({$deptCond} AND a.status != 'draft') OR a.faculty_id = ?)
        GROUP BY a.id
        ORDER BY a.event_date DESC
    ");
    $deptActsStmt->execute([$user['id']]);
    $deptActivities = $deptActsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Compute Department Performance Summaries
    $deptTotal = count($deptActivities);
    $deptCompleted = count(array_filter($deptActivities, fn($a) => $a['status'] === 'completed'));
    $deptApproved = count(array_filter($deptActivities, fn($a) => $a['status'] === 'approved'));
    $deptCompletionRate = $deptTotal > 0 ? number_format(($deptCompleted / $deptTotal) * 100, 1) . '%' : '0.0%';

    $targetSum = array_sum(array_map(fn($a) => (int)($a['target_attendance'] ?: $a['target_participants'] ?: 0), array_filter($deptActivities, fn($a) => $a['actual_attendance'] !== null)));
    $actualSum = array_sum(array_map(fn($a) => (int)($a['actual_attendance'] ?? 0), $deptActivities));
    $deptTurnout = $targetSum > 0 ? number_format(min(100.0, ($actualSum / $targetSum) * 100), 1) . '%' : 'No data';

    $satActs = array_filter($deptActivities, fn($a) => $a['satisfaction_score'] !== null && $a['satisfaction_score'] !== '');
    $deptAvgSat = !empty($satActs) ? number_format(array_sum(array_column($satActs, 'satisfaction_score')) / count($satActs), 1) . ' / 5.0' : 'No data';

    $kpiActs = array_filter($deptActivities, fn($a) => !empty($a['avg_kpi']));
    $deptAvgKpi = !empty($kpiActs) ? (array_sum(array_column($kpiActs, 'avg_kpi')) / count($kpiActs)) : null;

    // 4. Monthly Activity & KPI Performance (current year)
    $curYear = (int)date('Y');
    $phMonthlyStmt = $db->prepare("
        SELECT MONTH(a.event_date) as m, COUNT(DISTINCT a.id) as cnt, AVG(k.rating) as avg_kpi 
        FROM activities a 
        JOIN users u ON a.faculty_id = u.id 
        LEFT JOIN kpi_evaluations k ON a.id = k.activity_id 
        WHERE ({$deptCond} OR a.faculty_id = ?) AND a.status IN ('approved', 'completed') AND YEAR(a.event_date) = ?
        GROUP BY MONTH(a.event_date) 
        ORDER BY m
    ");
    $phMonthlyStmt->execute([$user['id'], $curYear]);
    $phMonthly = $phMonthlyStmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Detailed post-event reports for card view
    $reports = $db->prepare("
        SELECT a.id as activity_id, a.title, a.event_date, a.target_participants, a.status as activity_status, pe.*,
               AVG(k.rating) as avg_kpi, COUNT(k.id) as kpi_count
        FROM activities a
        JOIN users u ON a.faculty_id = u.id
        JOIN post_event pe ON a.id = pe.activity_id
        LEFT JOIN kpi_evaluations k ON a.id = k.activity_id
        WHERE ({$deptCond} OR a.faculty_id = ?)
        GROUP BY a.id, pe.id
        ORDER BY a.event_date DESC
    ");
    $reports->execute([$user['id']]);
} else {
    // Faculty: Isolated strictly to personal activities
    $reports = $db->prepare("
        SELECT a.id as activity_id, a.title, a.event_date, a.target_participants, a.status as activity_status, pe.*,
               AVG(k.rating) as avg_kpi, COUNT(k.id) as kpi_count
        FROM activities a
        JOIN post_event pe ON a.id=pe.activity_id
        LEFT JOIN kpi_evaluations k ON a.id=k.activity_id
        WHERE a.faculty_id=?
        GROUP BY a.id, pe.id
        ORDER BY a.event_date DESC
    ");
    $reports->execute([$user['id']]);
}
$allReports = $reports->fetchAll(PDO::FETCH_ASSOC);

function kpiClass($avg) {
    if ($avg >= 3.5) return ['Excellent','badge-success'];
    if ($avg >= 2.5) return ['Very Satisfactory','badge-info'];
    if ($avg >= 1.5) return ['Satisfactory','badge-warning'];
    return ['Needs Improvement','badge-danger'];
}
function attendanceClass($actual, $target) {
    if (!$target || $actual === null) return ['—','badge-secondary'];
    $acceptableTarget = $target * 0.95;
    $pct = min(100.0, round(($actual / $acceptableTarget) * 100, 1));
    $status = getKpiStatus($pct);
    return [$status, getKpiStatusClass($status)];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $isProgramHead ? 'Department KPI & Evaluation Reports – STI' : 'KPI Results – STI Activity System' ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
<style>
/* Typography parity */
body, body *, h1, h2, h3, h4, h5, h6, .page-title, .card-header h2, .btn, .badge, .form-control, label, select {
  font-family: 'Plus Jakarta Sans', sans-serif !important;
}

/* Department KPI Summary Strip */
.summary-strip {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 20px;
}
.summary-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 16px 14px;
  text-align: center;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.summary-card .val {
  font-family: 'Plus Jakarta Sans', sans-serif !important;
  font-size: 1.45rem;
  font-weight: 800;
  color: var(--text-main);
  line-height: 1.2;
}
.summary-card .lbl {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  color: var(--text-muted);
  margin-top: 5px;
  letter-spacing: 0.3px;
}
@media (max-width: 992px) {
  .summary-strip {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
@media (max-width: 600px) {
  .summary-strip {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 360px) {
  .summary-strip {
    grid-template-columns: 1fr !important;
  }
}

/* Monthly chart styling matching dean/kpi-reports.php */
.month-bar-wrap {
  display: flex;
  align-items: flex-end;
  gap: 8px;
  height: 120px;
  margin: 14px 0;
}
.month-bar {
  flex: 1;
  border-radius: 6px 6px 0 0;
  min-width: 0;
  transition: opacity .2s;
  cursor: default;
  position: relative;
}
.month-bar:hover {
  opacity: .85;
}
.month-label {
  text-align: center;
  font-size: .72rem;
  color: var(--text-muted);
  font-weight: 700;
  margin-top: 4px;
}
.month-val {
  position: absolute;
  top: -18px;
  left: 50%;
  transform: translateX(-50%);
  font-size: .75rem;
  font-weight: 700;
  white-space: nowrap;
}
.month-chart-scroll-wrap {
  width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: thin;
  padding-bottom: 4px;
}

/* KPI Detailed Card */
.kpi-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 22px;
  margin-bottom: 18px;
}
.kpi-card .event-name {
  font-family: 'Plus Jakarta Sans', sans-serif !important;
  font-size: 1.05rem;
  font-weight: 800;
  margin-bottom: 4px;
}
.kpi-metrics {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin: 16px 0;
}
.kpi-metric {
  background: var(--bg-base);
  border-radius: 8px;
  padding: 12px 14px;
  text-align: center;
}
.kpi-metric .val {
  font-family: 'Plus Jakarta Sans', sans-serif !important;
  font-size: 1.35rem;
  font-weight: 800;
}
.kpi-metric .lbl {
  font-size: .68rem;
  color: var(--text-muted);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .3px;
}
.progress-bar {
  height: 8px;
  background: var(--border);
  border-radius: 999px;
  overflow: hidden;
  margin-top: 8px;
}
.progress-fill {
  height: 100%;
  border-radius: 999px;
  background: var(--accent);
}
.criteria-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-top: 12px;
}
.criteria-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--bg-base);
  border-radius: 6px;
  padding: 8px 12px;
  font-size: .78rem;
}
.star-rating {
  color: var(--sti-gold);
  font-size: .9rem;
}
</style>
</head>
<body class="theme-faculty">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title"><?= $isProgramHead ? 'KPI & Evaluation Reports' : 'KPI Results' ?></div>
  
    <div class="topbar-right">
      <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>
  <div class="content">

    <?php if ($isProgramHead): ?>
      <!-- ── PROGRAM HEAD DEDICATED KPI OVERVIEW ── -->
      <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
        <div>
          <h2 style="font-size:1.25rem; font-weight:800; margin:0; color:var(--text-main);">
            Department KPI & Performance Overview — <?= htmlspecialchars($dept) ?>
          </h2>
          <div class="text-sm text-muted">Institutional metrics and evaluation benchmarks scoped to <?= htmlspecialchars($dept) ?></div>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
          <a href="<?= BASE_URL ?>/faculty/generate-report.php" class="btn btn-primary btn-sm" style="display:inline-flex; align-items:center; gap:6px;">
            📄 Generate Department Report
          </a>
          <a href="<?= BASE_URL ?>/dean/generate-report.php?export=csv" class="btn btn-outline btn-sm" style="display:inline-flex; align-items:center; gap:6px;">
            📊 Export Department CSV
          </a>
        </div>
      </div>

      <!-- Department Metrics Strip -->
      <div class="summary-strip">
        <div class="summary-card">
          <div class="val"><?= $deptCompleted ?> / <?= $deptTotal ?></div>
          <div class="lbl">Activities Completed / Total</div>
        </div>
        <div class="summary-card">
          <div class="val"><?= $deptCompletionRate ?></div>
          <div class="lbl">Execution Rate</div>
        </div>
        <div class="summary-card">
          <div class="val"><?= $deptTurnout ?></div>
          <div class="lbl">Attendance Turnout</div>
        </div>
        <div class="summary-card">
          <div class="val">
            <?php if ($deptAvgKpi !== null): ?>
              <?= number_format($deptAvgKpi, 2) ?> <span style="font-size:0.85rem; font-weight:600; color:var(--text-muted);">/ 4.0</span>
            <?php else: ?>
              <span style="font-size:1rem; color:var(--text-muted);">No data</span>
            <?php endif; ?>
          </div>
          <div class="lbl">Average KPI Achievement</div>
        </div>
        <div class="summary-card">
          <div class="val"><?= $deptAvgSat ?></div>
          <div class="lbl">Student Satisfaction</div>
        </div>
      </div>

      <!-- Monthly Department Activity & KPI Chart -->
      <div class="card" style="margin-bottom:20px;">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
          <h2>📅 Monthly Department Activity & KPI Trend — Year <?= $curYear ?></h2>
          <span class="badge badge-secondary"><?= htmlspecialchars($dept) ?></span>
        </div>
        <div class="card-body">
          <?php
          $monthlyMap = [];
          foreach ($phMonthly as $pm) $monthlyMap[(int)$pm['m']] = $pm;
          $maxCnt = max(array_merge([1], array_map(fn($pm) => (int)$pm['cnt'], $phMonthly ?: [])));
          ?>
          <div class="month-chart-scroll-wrap">
            <div class="month-bar-wrap">
              <?php for ($m = 1; $m <= 12; $m++):
                $data = $monthlyMap[$m] ?? ['cnt' => 0, 'avg_kpi' => null];
                $cnt = (int)$data['cnt'];
                $h = max(round(($cnt / $maxCnt) * 100), 4);
                $avgKpi = $data['avg_kpi'];
                $col = $avgKpi !== null
                  ? ($avgKpi >= 3.5 ? '#16A34A' : ($avgKpi >= 2.5 ? '#0284C7' : '#D97706'))
                  : '#E5E7EB';
              ?>
                <div style="flex:1;display:flex;flex-direction:column;align-items:center;">
                  <div style="flex:1;width:100%;display:flex;align-items:flex-end;">
                    <div class="month-bar" style="width:100%;height:<?= $h ?>%;background:<?= $col ?>;position:relative;" title="<?= date('F', mktime(0, 0, 0, $m, 1)) ?>: <?= $data['cnt'] ?> events<?= $data['avg_kpi'] ? ' · KPI ' . number_format($data['avg_kpi'], 1) : '' ?>">
                      <?php if ($data['cnt'] > 0): ?><span class="month-val"><?= $data['cnt'] ?></span><?php endif; ?>
                    </div>
                  </div>
                  <div class="month-label"><?= date('M', mktime(0, 0, 0, $m, 1)) ?></div>
                </div>
              <?php endfor; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Comparative Department Activity Performance Table -->
      <div class="card" style="margin-bottom:24px;">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
          <h2>📊 Department Activity Performance Register</h2>
          <span class="badge badge-secondary"><?= $deptTotal ?> Activities Recorded</span>
        </div>
        <div class="card-body" style="padding:0;">
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Activity Title</th>
                  <th>Faculty Proponent</th>
                  <th>Event Date</th>
                  <th>Status</th>
                  <th>Target Att.</th>
                  <th>Actual Att.</th>
                  <th>Turnout</th>
                  <th>Avg KPI / 4.0</th>
                  <th>Satisfaction</th>
                  <th style="text-align:center;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($deptActivities)): ?>
                  <tr>
                    <td colspan="10" style="text-align:center; padding:32px; color:var(--text-muted);">
                      No activities recorded for <?= htmlspecialchars($dept) ?> yet.
                    </td>
                  </tr>
                <?php else: foreach ($deptActivities as $da):
                  $tAtt = (int)($da['target_attendance'] ?: $da['target_participants'] ?: 0);
                  $actAtt = $da['actual_attendance'] !== null ? (int)$da['actual_attendance'] : null;
                  $attPct = ($tAtt > 0 && $actAtt !== null) ? min(100, round(($actAtt / $tAtt) * 100)) : null;
                ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($da['title']) ?></strong></td>
                    <td><?= htmlspecialchars($da['faculty_name']) ?></td>
                    <td><?= $da['event_date'] ? date('M j, Y', strtotime($da['event_date'])) : '—' ?></td>
                    <td><?= getStatusBadge($da['status']) ?></td>
                    <td><?= $tAtt ?: '—' ?></td>
                    <td><?= $actAtt !== null ? $actAtt : '—' ?></td>
                    <td>
                      <?php if ($attPct !== null): ?>
                        <span style="font-weight:700; color:<?= $attPct >= 90 ? '#16A34A' : ($attPct >= 70 ? '#D97706' : '#DC2626') ?>;"><?= $attPct ?>%</span>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($da['avg_kpi'])):
                        $kVal = (float)$da['avg_kpi'];
                        $kBadge = $kVal >= 3.5 ? 'badge-success' : ($kVal >= 2.5 ? 'badge-info' : ($kVal >= 1.5 ? 'badge-warning' : 'badge-danger'));
                      ?>
                        <span class="badge <?= $kBadge ?>"><?= number_format($kVal, 2) ?></span>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?= ($da['satisfaction_score'] !== null && $da['satisfaction_score'] !== '') ? number_format((float)$da['satisfaction_score'], 1) . ' / 5' : '—' ?>
                    </td>
                    <td style="text-align:center; white-space:nowrap;">
                      <a href="<?= BASE_URL ?>/dean/generate-report.php?activity_id=<?= $da['id'] ?>" target="_blank" class="btn btn-outline btn-sm" style="padding:4px 8px; font-size:0.75rem;">
                        Report
                      </a>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div style="margin-bottom:12px;">
        <h3 style="font-size:1.1rem; font-weight:800; margin:0; color:var(--text-main);">
          Detailed Post-Event KPI Evaluations
        </h3>
        <div class="text-sm text-muted">Complete rubric criteria evaluations and observations submitted via post-event reports</div>
      </div>
    <?php endif; ?>

    <!-- ── DETAILED POST-EVENT KPI EVALUATIONS LIST ── -->
    <?php if (empty($allReports)): ?>
    <div class="card">
      <div class="card-body" style="text-align:center;padding:60px;">
        <div style="font-size:3rem;margin-bottom:12px;">📊</div>
        <div style="font-weight:600;margin-bottom:6px;">No Post-Event KPI Data Yet</div>
        <div class="text-muted text-sm">Submit post-event reports to see detailed rubric breakdowns here.</div>
        <a href="<?= BASE_URL ?>/faculty/post-event.php" class="btn btn-primary" style="margin-top:16px;">Upload Post-Event Report</a>
      </div>
    </div>
    <?php else: ?>

    <?php foreach ($allReports as $r):
      [$kpiLabel, $kpiClass] = kpiClass($r['avg_kpi'] ?? 0);
      [$attLabel, $attClass] = attendanceClass($r['actual_attendance'], $r['target_attendance']);
      $attPct = ($r['target_attendance'] > 0 && isset($r['actual_attendance'])) ? min(100, round(($r['actual_attendance'] / $r['target_attendance']) * 100)) : 0;
      $kpiPct = $r['avg_kpi'] > 0 ? round(($r['avg_kpi']/4)*100) : 0;

      // Get individual KPI breakdown
      $breakdown = $db->prepare("SELECT criteria, AVG(rating) as avg FROM kpi_evaluations WHERE activity_id=? GROUP BY criteria");
      $breakdown->execute([$r['activity_id']]);
      $kpiDetails = $breakdown->fetchAll();
    ?>
    <div class="kpi-card">
      <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:8px;">
        <div>
          <div class="event-name"><?= htmlspecialchars($r['title']) ?></div>
          <div class="text-sm text-muted"><?= $r['event_date'] ? date('F j, Y', strtotime($r['event_date'])) : '—' ?></div>
        </div>
        <div>
          <a href="<?= BASE_URL ?>/dean/generate-report.php?activity_id=<?= $r['activity_id'] ?>" target="_blank" class="btn btn-outline btn-sm" style="display:inline-flex; align-items:center; gap:4px;">
            📄 View Full Report
          </a>
        </div>
      </div>

      <div class="kpi-metrics">
        <div class="kpi-metric">
          <div class="val"><?= $r['actual_attendance'] !== null ? $r['actual_attendance'] : '—' ?></div>
          <div class="lbl">Actual Attendance</div>
        </div>
        <div class="kpi-metric">
          <div class="val"><?= $r['target_attendance'] ?: '—' ?></div>
          <div class="lbl">Target</div>
        </div>
        <div class="kpi-metric">
          <div class="val"><?= $r['target_attendance'] > 0 ? $attPct . '%' : '—' ?></div>
          <div class="lbl">Attendance Rate</div>
        </div>
        <div class="kpi-metric">
          <div class="val"><?= $r['avg_kpi'] ? number_format($r['avg_kpi'],2) : '—' ?></div>
          <div class="lbl">Avg KPI / 4.0</div>
        </div>
      </div>

      <div style="display:flex;gap:8px;margin-bottom:14px; flex-wrap:wrap;">
        <span class="badge <?= $attClass ?>"><?= $attLabel ?></span>
        <?php if ($r['avg_kpi']): ?><span class="badge <?= $kpiClass ?>"><?= $kpiLabel ?></span><?php endif; ?>
        <?php if ($r['satisfaction_score']): ?><span class="badge badge-primary">Satisfaction: <?= number_format($r['satisfaction_score'],1) ?>/5</span><?php endif; ?>
      </div>

      <!-- Attendance bar -->
      <?php if ($r['target_attendance'] > 0): ?>
      <div style="margin-bottom:14px;">
        <div class="flex justify-between text-sm" style="margin-bottom:4px;">
          <span class="text-muted">Attendance Progress</span>
          <span class="fw-bold"><?= $attPct ?>%</span>
        </div>
        <div class="progress-bar"><div class="progress-fill" style="width:<?= $attPct ?>%;background:<?= $attPct>=90?'var(--success)':($attPct>=70?'var(--warning)':'var(--danger)') ?>"></div></div>
      </div>
      <?php endif; ?>

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
      <?php if (!empty($r['observations'])): ?>
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
