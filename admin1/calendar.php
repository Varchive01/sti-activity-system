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
if ($month < 1) {
  $month = 12;
  $year--;
}
if ($month > 12) {
  $month = 1;
  $year++;
}

$prevMonth = $month - 1;
$prevYear  = $year;
if ($prevMonth < 1) {
  $prevMonth = 12;
  $prevYear--;
}
$nextMonth = $month + 1;
$nextYear  = $year;
if ($nextMonth > 12) {
  $nextMonth = 1;
  $nextYear++;
}

$events = $db->prepare("
    SELECT a.id, a.title, a.event_date, a.end_date, a.start_time, a.end_time, a.status, a.venue, a.source, a.description, a.target_participants, a.faculty_id, u.name as faculty_name
    FROM activities a JOIN users u ON a.faculty_id=u.id
    WHERE (a.source = 'student_org' OR u.role = 'program_head') AND a.status != 'draft' AND MONTH(a.event_date)=? AND YEAR(a.event_date)=?
    ORDER BY a.event_date
");
$events->execute([$month, $year]);
$monthEvents = [];
foreach ($events->fetchAll() as $e) {
  $day = (int)date('j', strtotime($e['event_date']));
  $monthEvents[$day][] = $e;
}

// Upcoming events list (future/current approved student_org activities)
$upcoming = $db->prepare("
    SELECT a.id, a.title, a.event_date, a.status, a.venue, u.name as faculty_name 
    FROM activities a JOIN users u ON a.faculty_id=u.id
    WHERE (a.source = 'student_org' OR u.role = 'program_head') AND a.status = 'approved' AND a.event_date >= CURDATE()
    ORDER BY a.event_date ASC LIMIT 8
");
$upcoming->execute();
$upcomingList = $upcoming->fetchAll();

$daysInMonth    = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$firstDayOfWeek = (int)date('w', mktime(0, 0, 0, $month, 1, $year));
$monthName      = date('F Y', mktime(0, 0, 0, $month, 1, $year));

$statusConfig = [
  'draft'                  => ['color' => '#6B7280', 'bg' => '#F3F4F6', 'label' => 'Draft'],
  'submitted'              => ['color' => '#D97706', 'bg' => '#FEF3C7', 'label' => 'Submitted'],
  'under_review'           => ['color' => '#D97706', 'bg' => '#FEF3C7', 'label' => 'Under Review'],
  'endorsed'               => ['color' => '#0284C7', 'bg' => '#E0F2FE', 'label' => 'Endorsed'],
  'pending_final_approval' => ['color' => '#0284C7', 'bg' => '#E0F2FE', 'label' => 'Subject for Final Approval'],
  'resubmitted'            => ['color' => '#D97706', 'bg' => '#FEF3C7', 'label' => 'Under Review'],
  'approved'               => ['color' => '#15803D', 'bg' => '#DCFCE7', 'label' => 'Approved'],
  'completed'              => ['color' => '#CA8A04', 'bg' => '#FEF9C3', 'label' => 'Completed'],
  'returned_for_revision'  => ['color' => '#DC2626', 'bg' => '#FEE2E2', 'label' => 'Returned for Revision'],
  'rejected'               => ['color' => '#DC2626', 'bg' => '#FEE2E2', 'label' => 'Rejected'],
];

$legend = [
  'Under Review'               => ['color' => '#D97706', 'bg' => '#FEF3C7'],
  'Subject for Final Approval' => ['color' => '#0284C7', 'bg' => '#E0F2FE'],
  'Approved'                   => ['color' => '#15803D', 'bg' => '#DCFCE7'],
  'Ongoing'                    => ['color' => '#0369A1', 'bg' => '#E0F2FE'],
  'Completed'                  => ['color' => '#CA8A04', 'bg' => '#FEF9C3'],
  'Returned for Revision'      => ['color' => '#DC2626', 'bg' => '#FEE2E2'],
];

$today      = (int)date('j');
$todayMonth = (int)date('n');
$todayYear  = (int)date('Y');
?>
<!DOCTYPE html>
<html lang="en">

<head>
<?php include __DIR__ . '/../includes/head-theme-init.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Calendar – STI Activity System</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=<?= time() ?>">
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
</head>

<body class="theme-arjay">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">Activity Calendar</div>
      <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
        <!-- User Profile Control -->
        <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
      </div>
    </header>
    <div class="content">

      <div class="cal-layout">

        <!-- Main Calendar -->
        <div>
          <!-- Toolbar -->
          <div class="cal-toolbar">
            <div class="cal-nav">
              <a href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="cal-nav-btn" title="Previous month">&#8249;</a>
              <a href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="cal-nav-btn" title="Next month">&#8250;</a>
              <span class="cal-month-title"><?= $monthName ?></span>
            </div>
            <a href="?month=<?= date('n') ?>&year=<?= date('Y') ?>" class="cal-today-btn">Today</a>
          </div>

          <!-- Grid -->
          <div class="cal-grid-scroll">
            <div class="cal-grid">
            <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $d): ?>
              <div class="cal-day-name"><?= $d ?></div>
            <?php endforeach; ?>

            <?php
            for ($i = 0; $i < $firstDayOfWeek; $i++) echo '<div class="cal-cell other-month"></div>';
            for ($day = 1; $day <= $daysInMonth; $day++):
              $isToday = ($day === $today && $month === $todayMonth && $year === $todayYear);
              $dayEvents = $monthEvents[$day] ?? [];
              $maxShow = 3;
              $extra   = max(0, count($dayEvents) - $maxShow);
            ?>
              <div class="cal-cell <?= $isToday ? 'today' : '' ?>" id="cell-<?= $day ?>">
                <div class="day-num"><?= $day ?></div>
                <?php foreach (array_slice($dayEvents, 0, $maxShow) as $ev):
                  $cfg              = $statusConfig[$ev['status']] ?? ['color' => '#6B7280', 'bg' => '#F3F4F6'];
                  $dateFormatted    = date('F j, Y', strtotime($ev['event_date']));
                  $endDateFormatted = (!empty($ev['end_date']) && $ev['end_date'] !== $ev['event_date']) ? date('F j, Y', strtotime($ev['end_date'])) : '';
                  $timeFormatted    = '';
                  if (!empty($ev['start_time'])) {
                    $timeFormatted = date('g:i A', strtotime($ev['start_time']));
                    if (!empty($ev['end_time'])) {
                      $timeFormatted .= ' – ' . date('g:i A', strtotime($ev['end_time']));
                    }
                  }
                  $canEdit   = in_array($ev['status'], ['draft', 'submitted', 'returned_for_revision']) && canUserAccessActivity((int)$ev['id'], 'modify', $user['id'], $user['role'], $user['dept']);
                  $canDelete = ($ev['faculty_id'] == $user['id'] && in_array($ev['status'], ['draft', 'returned_for_revision']));
                  $eventJson = htmlspecialchars(json_encode([
                    'id'            => (int)$ev['id'],
                    'title'         => $ev['title'],
                    'date'          => $dateFormatted,
                    'endDate'       => $endDateFormatted,
                    'time'          => $timeFormatted,
                    'venue'         => $ev['venue'] ?? 'Campus Venue',
                    'status'        => $statusConfig[$ev['status']]['label'] ?? ucfirst($ev['status']),
                    'statusColor'   => $cfg['color'],
                    'statusBg'      => $cfg['bg'],
                    'category'      => ucfirst(str_replace('_', ' ', $ev['source'] ?? 'Student Org Activity')),
                    'categoryColor' => $cfg['color'],
                    'categoryBg'    => $cfg['bg'],
                    'proponent'     => $ev['faculty_name'] ?? 'Student Org Lead',
                    'visibility'    => 'STI College',
                    'description'   => $ev['description'] ?? '',
                    'viewUrl'       => BASE_URL . '/admin1/view-activity.php?id=' . (int)$ev['id'],
                    'editUrl'       => $canEdit ? BASE_URL . '/admin1/proposal-edit.php?id=' . (int)$ev['id'] : null,
                    'canDelete'     => $canDelete
                  ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                ?>
                  <div class="cal-event"
                    style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>;"
                    data-event='<?= $eventJson ?>'
                    onclick="handleCalendarEventClick(this, event)">
                    <div class="cal-event-dot" style="background:<?= $cfg['color'] ?>"></div>
                    <span class="cal-event-text"><?= htmlspecialchars($ev['title']) ?></span>
                  </div>
                <?php endforeach; ?>
                <?php if ($extra > 0): ?>
                  <div class="cal-more" onclick="event.stopPropagation();">+<?= $extra ?> more</div>
                <?php endif; ?>
              </div>
            <?php endfor; ?>

            <?php
            $trailing = (7 - (($firstDayOfWeek + $daysInMonth) % 7)) % 7;
            for ($i = 0; $i < $trailing; $i++) echo '<div class="cal-cell other-month"></div>';
            ?>
            </div>
          </div>

          <!-- Legend -->
          <div class="cal-legend">
            <?php foreach ($legend as $label => $cfg): ?>
              <div class="legend-item">
                <div class="legend-chip" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>;">
                  <div class="legend-dot" style="background:<?= $cfg['color'] ?>"></div>
                  <?= $label ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Right Sidebar -->
        <div class="cal-sidebar">

          <!-- Upcoming Events -->
          <div class="card">
            <div class="card-header">
              <h2>📅 Upcoming Events</h2>
            </div>
            <div class="card-body" style="padding:4px 16px 12px;">
              <?php if (empty($upcomingList)): ?>
                <p style="font-size:.78rem;color:var(--text-muted);text-align:center;padding:20px 0;">No upcoming events.</p>
                <?php else: foreach ($upcomingList as $ev):
                  $cfg = $statusConfig[$ev['status']] ?? ['color' => '#6B7280', 'bg' => '#F3F4F6', 'label' => 'Draft'];
                ?>
                  <div class="upcoming-item" onclick="location.href='<?= BASE_URL ?>/admin1/view-activity.php?id=<?= $ev['id'] ?>'">
                    <div class="upcoming-date-box">
                      <div class="month"><?= date('M', strtotime($ev['event_date'])) ?></div>
                      <div class="day"><?= date('j', strtotime($ev['event_date'])) ?></div>
                    </div>
                    <div class="upcoming-info">
                      <div class="upcoming-title"><?= htmlspecialchars($ev['title']) ?></div>
                      <div class="upcoming-meta"><?= htmlspecialchars($ev['venue'] ?? 'Venue TBD') ?> · <?= htmlspecialchars($ev['faculty_name'] ?? '') ?></div>
                      <div style="margin-top:4px;">
                        <span class="status-chip" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>;">
                          <?= $cfg['label'] ?>
                        </span>
                      </div>
                    </div>
                  </div>
              <?php endforeach;
              endif; ?>
            </div>
          </div>

        </div><!-- cal-sidebar -->
      </div><!-- cal-layout -->

    </div>
  </div>

  <!-- Unified Google Calendar Floating Popup Script -->
  <script src="<?= BASE_URL ?>/assets/js/gcal-popup.js?v=<?= time() ?>"></script>
  <script>
    const BASE_URL = '<?= BASE_URL ?>';

    function handleCalendarEventClick(el, e) {
      if (e) e.stopPropagation();
      const raw = el.getAttribute('data-event');
      if (!raw) return;
      try {
        const data = JSON.parse(raw);
        if (data.canDelete) {
          data.onDelete = function() {
            fetch(BASE_URL + '/api/proposal-delete.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ id: data.id })
            })
            .then(r => r.json())
            .then(res => {
              if (res.success) {
                window.location.reload();
              } else {
                alert(res.error || 'Failed to delete proposal.');
              }
            })
            .catch(() => alert('Network error deleting proposal.'));
          };
        }
        openGCalPopup(el, data);
      } catch (err) {
        console.error('Error opening event details:', err);
      }
    }
  </script>
</body>
</html>
