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
    SELECT a.id, a.title, a.event_date, a.status, a.venue, a.source, u.name as faculty_name
    FROM activities a JOIN users u ON a.faculty_id=u.id
    WHERE a.source='student_org' AND a.status != 'draft' AND MONTH(a.event_date)=? AND YEAR(a.event_date)=?
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
    WHERE a.source='student_org' AND a.status = 'approved' AND a.event_date >= CURDATE()
    ORDER BY a.event_date ASC LIMIT 8
");
$upcoming->execute();
$upcomingList = $upcoming->fetchAll();

$daysInMonth    = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$firstDayOfWeek = (int)date('w', mktime(0, 0, 0, $month, 1, $year));
$monthName      = date('F Y', mktime(0, 0, 0, $month, 1, $year));

$statusConfig = [
  'draft'                  => ['color' => '#6B7280', 'bg' => '#F3F4F6', 'label' => 'Draft'],
  'submitted'              => ['color' => '#1D4ED8', 'bg' => '#DBEAFE', 'label' => 'Submitted'],
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
  'Submitted'                  => ['color' => '#1D4ED8', 'bg' => '#DBEAFE'],
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
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Calendar – STI Activity System</title>
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
                  $cfg   = $statusConfig[$ev['status']] ?? ['color' => '#6B7280', 'bg' => '#F3F4F6'];
                  $venue = htmlspecialchars($ev['venue'] ?? '');
                  $date  = date('F j, Y', strtotime($ev['event_date']));
                  $organizer = htmlspecialchars($ev['faculty_name'] ?? '');
                ?>
                  <div class="cal-event"
                    style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>;"
                    onclick="showPopup(event, <?= $ev['id'] ?>, '<?= addslashes(htmlspecialchars($ev['title'])) ?>', '<?= $date ?>', '<?= $venue ?>', '<?= $cfg['color'] ?>', '<?= $cfg['bg'] ?>', '<?= addslashes($statusConfig[$ev['status']]['label'] ?? $ev['status']) ?>', '<?= addslashes($organizer) ?>')">
                    <div class="cal-event-dot" style="background:<?= $cfg['color'] ?>"></div>
                    <span class="cal-event-text"><?= htmlspecialchars(mb_strimwidth($ev['title'], 0, 20, '…')) ?></span>
                  </div>
                <?php endforeach; ?>
                <?php if ($extra > 0): ?>
                  <div class="cal-more">+<?= $extra ?> more</div>
                <?php endif; ?>
              </div>
            <?php endfor; ?>

            <?php
            $trailing = (7 - (($firstDayOfWeek + $daysInMonth) % 7)) % 7;
            for ($i = 0; $i < $trailing; $i++) echo '<div class="cal-cell other-month"></div>';
            ?>
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

  <!-- Event Popup -->
  <div class="event-popup" id="eventPopup">
    <div class="event-popup-header">
      <div class="event-popup-title" id="popupTitle"></div>
      <button class="event-popup-close" onclick="closePopup()">✕</button>
    </div>
    <div style="margin-bottom:10px;">
      <span class="status-chip" id="popupStatus" style="font-size:.7rem;"></span>
    </div>
    <div class="event-popup-row">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
      </svg>
      <span id="popupDate"></span>
    </div>
    <div class="event-popup-row" id="popupVenueRow">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
      </svg>
      <span id="popupVenue"></span>
    </div>
    <div class="event-popup-actions">
      <a href="#" id="popupViewBtn" class="popup-btn primary">View Details</a>
      <button class="popup-btn ghost" onclick="closePopup()">Close</button>
    </div>
  </div>

  <script>
    const BASE_URL = '<?= BASE_URL ?>';
    let activePopup = null;

    function showPopup(e, id, title, date, venue, color, bg, status, organizer) {
      e.stopPropagation();
      const popup = document.getElementById('eventPopup');

      document.getElementById('popupTitle').textContent = title;
      document.getElementById('popupDate').textContent = date + (organizer ? ' · ' + organizer : '');
      document.getElementById('popupStatus').textContent = status;
      document.getElementById('popupStatus').style.background = bg;
      document.getElementById('popupStatus').style.color = color;
      document.getElementById('popupViewBtn').href = BASE_URL + '/admin1/view-activity.php?id=' + id;

      const venueRow = document.getElementById('popupVenueRow');
      document.getElementById('popupVenue').textContent = venue || 'Venue TBD';
      venueRow.style.display = 'flex';

      // Position near click
      const x = e.clientX,
        y = e.clientY;
      const pw = 280,
        ph = 200;
      const vw = window.innerWidth,
        vh = window.innerHeight;
      popup.style.left = (x + pw + 10 > vw ? x - pw - 10 : x + 10) + 'px';
      popup.style.top = (y + ph > vh ? vh - ph - 10 : y) + 'px';
      popup.classList.add('open');
      activePopup = popup;
    }

    function closePopup() {
      document.getElementById('eventPopup').classList.remove('open');
      activePopup = null;
    }

    document.addEventListener('click', e => {
      if (activePopup && !activePopup.contains(e.target)) closePopup();
    });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') closePopup();
    });
  </script>
</body>
</html>
