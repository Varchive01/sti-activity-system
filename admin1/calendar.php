<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin1');
$user = currentUser();
$db   = getDB();

$month = (int)($_GET['month'] ?? date('n'));
$year  = (int)($_GET['year']  ?? date('Y'));
if ($month < 1) { $month = 12; $year--; }
if ($month > 12){ $month = 1;  $year++; }
$prevMonth=$month-1;$prevYear=$year;if($prevMonth<1){$prevMonth=12;$prevYear--;}
$nextMonth=$month+1;$nextYear=$year;if($nextMonth>12){$nextMonth=1;$nextYear++;}

$events = $db->prepare("
    SELECT a.id, a.title, a.event_date, a.status, a.venue, a.source, u.name as faculty_name
    FROM activities a JOIN users u ON a.faculty_id=u.id
    WHERE MONTH(a.event_date)=? AND YEAR(a.event_date)=?
    ORDER BY a.event_date
");
$events->execute([$month,$year]);
$monthEvents=[];
foreach($events->fetchAll() as $e) { $day=(int)date('j',strtotime($e['event_date'])); $monthEvents[$day][]=$e; }

$daysInMonth=cal_days_in_month(CAL_GREGORIAN,$month,$year);
$firstDayOfWeek=(int)date('w',mktime(0,0,0,$month,1,$year));
$monthName=date('F Y',mktime(0,0,0,$month,1,$year));

$statusColors=['draft'=>'#6B7A99','submitted'=>'#1D4ED8','under_review'=>'#B45309',
    'endorsed'=>'#0369A1','pending_final_approval'=>'#0369A1','returned_for_revision'=>'#B91C1C','approved'=>'#15803D',
    'completed'=>'#374151','rejected'=>'#BE123C','resubmitted'=>'#D97706'];

// Month events list for sidebar
$listEvents = $db->prepare("SELECT a.*, u.name as fn FROM activities a JOIN users u ON a.faculty_id=u.id WHERE MONTH(a.event_date)=? AND YEAR(a.event_date)=? ORDER BY a.event_date");
$listEvents->execute([$month,$year]);
$allMonthEvents = $listEvents->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Calendar – STI Activity System</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
<style>
.cal-layout{display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start;}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:1px;background:var(--border);border-radius:var(--radius);overflow:hidden;}
.cal-day-name{background:var(--sti-navy);color:rgba(255,255,255,.7);text-align:center;padding:10px 4px;font-size:.7rem;font-weight:700;text-transform:uppercase;}
.cal-cell{background:var(--bg-card);min-height:90px;padding:6px;transition:background .15s;cursor:default;}
.cal-cell:hover{background:var(--bg-base);}
.cal-cell.today{background:#F0FDF4;}
.cal-cell.other-month{background:#F9FAFB;opacity:.4;}
.day-num{font-size:.78rem;font-weight:700;color:var(--text-muted);margin-bottom:3px;display:inline-block;}
.cal-cell.today .day-num{background:var(--accent);color:#fff;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:.72rem;}
.cal-event{border-radius:3px;padding:2px 5px;font-size:.63rem;font-weight:600;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#fff;cursor:pointer;}
.event-list-item{padding:10px 14px;border-radius:8px;border:1px solid var(--border);margin-bottom:8px;cursor:pointer;transition:border-color .2s;}
.event-list-item:hover{border-color:var(--accent);}
.event-list-item .etitle{font-weight:600;font-size:.82rem;}
.event-list-item .emeta{font-size:.72rem;color:var(--text-muted);margin-top:2px;}
</style>
</head>
<body class="theme-arjay">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">System Activity Calendar</div>
  </header>
  <div class="content">

    <div class="cal-layout">
    <div>
      <div class="card">
        <div class="card-body">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
            <a href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="btn btn-outline btn-sm">← Prev</a>
            <div style="font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;"><?= $monthName ?></div>
            <a href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="btn btn-outline btn-sm">Next →</a>
          </div>
          <div class="cal-grid">
            <?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
            <div class="cal-day-name"><?= $d ?></div>
            <?php endforeach; ?>
            <?php
            $today=(int)date('j');$tm=(int)date('n');$ty=(int)date('Y');
            for($i=0;$i<$firstDayOfWeek;$i++) echo '<div class="cal-cell other-month"></div>';
            for($day=1;$day<=$daysInMonth;$day++):
              $isToday=($day===$today&&$month===$tm&&$year===$ty);
            ?>
            <div class="cal-cell <?= $isToday?'today':'' ?>">
              <div class="day-num"><?= $day ?></div>
              <?php if (!empty($monthEvents[$day])): foreach($monthEvents[$day] as $ev):
                $col=$statusColors[$ev['status']]??'#6B7A99';
              ?>
              <div class="cal-event" style="background:<?= $col ?>" title="<?= htmlspecialchars($ev['title'].' – '.$ev['faculty_name']) ?>" onclick="location.href='<?= BASE_URL ?>/dean/view-activity.php?id=<?= $ev['id'] ?>'">
                <?= htmlspecialchars(mb_strimwidth($ev['title'],0,20,'…')) ?>
              </div>
              <?php endforeach; endif; ?>
            </div>
            <?php endfor;
            $trailing=(7-(($firstDayOfWeek+$daysInMonth)%7))%7;
            for($i=0;$i<$trailing;$i++) echo '<div class="cal-cell other-month"></div>';
            ?>
          </div>

          <!-- Legend -->
          <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:14px;">
            <?php foreach(['Approved'=>'#15803D','Completed'=>'#374151','Under Review'=>'#B45309','For Revision'=>'#B91C1C','Draft'=>'#6B7A99'] as $l=>$c): ?>
            <div style="display:flex;align-items:center;gap:5px;font-size:.7rem;color:var(--text-muted);">
              <div style="width:10px;height:10px;background:<?= $c ?>;border-radius:3px;"></div><?= $l ?>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Events List -->
    <div class="card">
      <div class="card-header"><h2>Events this Month</h2><span class="badge badge-secondary"><?= count($allMonthEvents) ?></span></div>
      <div class="card-body">
        <?php if (empty($allMonthEvents)): ?>
        <div style="text-align:center;padding:30px;color:var(--text-muted);">No events this month.</div>
        <?php else: foreach($allMonthEvents as $ev): ?>
        <div class="event-list-item" onclick="location.href='<?= BASE_URL ?>/dean/view-activity.php?id=<?= $ev['id'] ?>'">
          <div class="etitle"><?= htmlspecialchars($ev['title']) ?></div>
          <div class="emeta">
            <?= $ev['event_date'] ? date('M j, Y',strtotime($ev['event_date'])) : '—' ?><br>
            <?= htmlspecialchars($ev['fn']) ?> · <?= getStatusBadge($ev['status']) ?>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    </div>
  </div>
</div>
</body>
</html>
