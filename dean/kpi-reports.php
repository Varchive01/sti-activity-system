<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('dean');
$db = getDB();
$year = (int)($_GET['year'] ?? date('Y'));
$bySource = $db->query("SELECT a.source, AVG(k.rating) as avg, COUNT(DISTINCT a.id) as cnt FROM activities a LEFT JOIN kpi_evaluations k ON a.id=k.activity_id WHERE YEAR(a.event_date)=$year GROUP BY a.source")->fetchAll();
try {
    $topPerf = $db->query("SELECT a.title, a.event_date, u.name as fn, AVG(k.rating) as avg_kpi, pe.satisfaction_score, pe.actual_attendance, pe.target_attendance FROM activities a JOIN users u ON a.faculty_id=u.id LEFT JOIN kpi_evaluations k ON a.id=k.activity_id LEFT JOIN post_event pe ON a.id=pe.activity_id WHERE YEAR(a.event_date)=$year GROUP BY a.id HAVING avg_kpi IS NOT NULL ORDER BY avg_kpi DESC LIMIT 10")->fetchAll();
} catch (PDOException $e) {
    // Legacy schema where post_event columns are removed
    $topPerf = $db->query("SELECT a.title, a.event_date, u.name as fn, AVG(k.rating) as avg_kpi, NULL AS satisfaction_score, NULL AS actual_attendance, NULL AS target_attendance FROM activities a JOIN users u ON a.faculty_id=u.id LEFT JOIN kpi_evaluations k ON a.id=k.activity_id LEFT JOIN post_event pe ON a.id=pe.activity_id WHERE YEAR(a.event_date)=$year GROUP BY a.id HAVING avg_kpi IS NOT NULL ORDER BY avg_kpi DESC LIMIT 10")->fetchAll();
}
$monthly = $db->query("SELECT MONTH(a.event_date) as m, COUNT(*) as cnt, AVG(k.rating) as avg_kpi FROM activities a LEFT JOIN kpi_evaluations k ON a.id=k.activity_id WHERE YEAR(a.event_date)=$year AND a.status IN ('approved','completed') GROUP BY MONTH(a.event_date) ORDER BY m")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>KPI Reports – STI</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
  <style>
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
      font-size: .62rem;
      color: var(--text-muted);
      font-weight: 700;
      margin-top: 4px;
    }

    .month-val {
      position: absolute;
      top: -18px;
      left: 50%;
      transform: translateX(-50%);
      font-size: .63rem;
      font-weight: 700;
      white-space: nowrap;
    }
  </style>
</head>

<body class="theme-dean">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">KPI Reports</div>
      <div class="topbar-right">
        <form method="GET" style="display:flex;gap:8px;align-items:center;">
          <select name="year" class="form-control" style="width:100px;">
            <?php foreach (range(date('Y'), date('Y') - 4) as $y): ?>
              <option <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-primary btn-sm">Apply</button>
        </form>
      </div>
    </header>
    <div class="content">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
        <!-- By Source -->
        <div class="card">
          <div class="card-header">
            <h2>KPI by Source — <?= $year ?></h2>
          </div>
          <div class="card-body">
            <?php foreach ($bySource as $bs): $pct = round(($bs['avg'] / 4) * 100); ?>
              <div style="margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                  <span class="fw-bold"><?= ucfirst(str_replace('_', ' ', $bs['source'])) ?></span>
                  <span><?= number_format($bs['avg'], 2) ?>/4.0 &nbsp;<span class="text-muted text-sm">(<?= $bs['cnt'] ?> events)</span></span>
                </div>
                <div style="height:10px;background:var(--border);border-radius:999px;overflow:hidden;">
                  <div style="width:<?= $pct ?>%;height:100%;background:var(--accent);border-radius:999px;"></div>
                </div>
              </div>
            <?php endforeach; ?>
            <?php if (empty($bySource)): ?><div class="text-muted text-sm" style="text-align:center;padding:20px;">No data for <?= $year ?>.</div><?php endif; ?>
          </div>
        </div>

        <!-- Monthly Events -->
        <div class="card">
          <div class="card-header">
            <h2>Monthly Activity Count — <?= $year ?></h2>
          </div>
          <div class="card-body">
            <?php
            $monthlyMap = [];
            foreach ($monthly as $m) $monthlyMap[$m['m']] = $m;
            $maxCnt = max(array_map(fn($m) => $m['cnt'], $monthly ?: [['cnt' => 1]]), 1);
            ?>
            <div class="month-bar-wrap">
              <?php for ($m = 1; $m <= 12; $m++):
                $data = $monthlyMap[$m] ?? ['cnt' => 0, 'avg_kpi' => null];
                $cnt = isset($data['cnt']) ? (int)$data['cnt'] : 0;
                $max = isset($maxCnt) && !is_array($maxCnt) ? (int)$maxCnt : 1;

                $h = max(round(($cnt / $max) * 100), 4);
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

      <!-- Top Performing Events -->
      <div class="card">
        <div class="card-header">
          <h2>🏆 Top Performing Events — <?= $year ?></h2>
        </div>
        <div class="card-body" style="padding:0;">
          <table>
            <thead>
              <tr>
                <th>Rank</th>
                <th>Activity</th>
                <th>Faculty</th>
                <th>Date</th>
                <th>KPI Avg</th>
                <th>Satisfaction</th>
                <th>Attendance %</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($topPerf)): ?><tr>
                  <td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted);">No KPI data for <?= $year ?>.</td>
                </tr>
                <?php else: foreach ($topPerf as $i => $tp):
                  $attPct = $tp['target_attendance'] > 0 ? round(($tp['actual_attendance'] / $tp['target_attendance']) * 100) : null;
                  $medal = $i === 0 ? '🥇' : ($i === 1 ? '🥈' : ($i === 2 ? '🥉' : ''));
                ?>
                  <tr>
                    <td style="text-align:center;font-size:1.1rem;"><?= $medal ?: $i + 1 ?></td>
                    <td><strong><?= htmlspecialchars($tp['title']) ?></strong></td>
                    <td><?= htmlspecialchars($tp['fn']) ?></td>
                    <td><?= $tp['event_date'] ? date('M j, Y', strtotime($tp['event_date'])) : '—' ?></td>
                    <td><?php
                        $k = $tp['avg_kpi'];
                        $cls = $k >= 3.5 ? 'badge-success' : ($k >= 2.5 ? 'badge-info' : ($k >= 1.5 ? 'badge-warning' : 'badge-danger'));
                        ?><span class="badge <?= $cls ?>"><?= number_format($k, 2) ?></span></td>
                    <td><?= $tp['satisfaction_score'] ? number_format($tp['satisfaction_score'], 1) . '/5' : '—' ?></td>
                    <td><?= $attPct !== null ? '<strong>' . $attPct . '%</strong>' : '—' ?></td>
                  </tr>
              <?php endforeach;
              endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</body>

</html>