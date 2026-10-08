<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');
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
$prevYear = $year;
if ($prevMonth < 1) {
  $prevMonth = 12;
  $prevYear--;
}
$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) {
  $nextMonth = 1;
  $nextYear++;
}

$events = $db->prepare("
    SELECT id, title, event_date, status, venue FROM activities
    WHERE faculty_id=? AND MONTH(event_date)=? AND YEAR(event_date)=?
    ORDER BY event_date
");
$events->execute([$user['id'], $month, $year]);
$monthEvents = [];
foreach ($events->fetchAll() as $e) {
  $day = (int)date('j', strtotime($e['event_date']));
  $monthEvents[$day][] = $e;
}

// Upcoming events list (all future/current approved activities for faculty)
$upcoming = $db->prepare("
    SELECT id, title, event_date, status, venue FROM activities
    WHERE faculty_id=? AND status='approved' AND event_date >= CURDATE()
    ORDER BY event_date ASC LIMIT 8
");
$upcoming->execute([$user['id']]);
$upcomingList = $upcoming->fetchAll();

$daysInMonth    = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$firstDayOfWeek = (int)date('w', mktime(0, 0, 0, $month, 1, $year));
$monthName      = date('F Y', mktime(0, 0, 0, $month, 1, $year));

$statusConfig = [
  'draft'                  => ['color' => '#6B7280', 'bg' => '#F3F4F6', 'label' => 'Draft'],
  'submitted'              => ['color' => '#1D4ED8', 'bg' => '#DBEAFE', 'label' => 'Submitted'],
  'under_review'           => ['color' => '#D97706', 'bg' => '#FEF3C7', 'label' => 'Under Review'],
  'endorsed'               => ['color' => '#7C3AED', 'bg' => '#EDE9FE', 'label' => 'Endorsed'],
  'pending_final_approval' => ['color' => '#7C3AED', 'bg' => '#EDE9FE', 'label' => 'Subject for Final Approval'],
  'resubmitted'            => ['color' => '#D97706', 'bg' => '#FEF3C7', 'label' => 'Under Review'],
  'approved'               => ['color' => '#15803D', 'bg' => '#DCFCE7', 'label' => 'Approved'],
  'completed'              => ['color' => '#CA8A04', 'bg' => '#FEF9C3', 'label' => 'Completed'],
  'returned_for_revision'  => ['color' => '#DC2626', 'bg' => '#FEE2E2', 'label' => 'Returned for Revision'],
  'rejected'               => ['color' => '#DC2626', 'bg' => '#FEE2E2', 'label' => 'Rejected'],
];

// Legend entries in your specified order
$legend = [
  'Draft'                    => ['color' => '#6B7280', 'bg' => '#F3F4F6'],
  'Submitted'                => ['color' => '#1D4ED8', 'bg' => '#DBEAFE'],
  'Under Review'             => ['color' => '#D97706', 'bg' => '#FEF3C7'],
  'Subject for Final Approval' => ['color' => '#7C3AED', 'bg' => '#EDE9FE'],
  'Approved'                 => ['color' => '#15803D', 'bg' => '#DCFCE7'],
  'Ongoing'                  => ['color' => '#0369A1', 'bg' => '#E0F2FE'],
  'Completed'                => ['color' => '#CA8A04', 'bg' => '#FEF9C3'],
  'Returned for Revision'    => ['color' => '#DC2626', 'bg' => '#FEE2E2'],
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
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
  <style>
    /* ── Right Sidebar ── */
    .cal-sidebar .card {
      margin-bottom: 16px;
    }

    .cal-sidebar .card-header h2 {
      font-size: .85rem;
      color: var(--text-main);
    }

    /* Upcoming events */
    .upcoming-item {
      display: flex;
      gap: 12px;
      padding: 10px 0;
      border-bottom: 1px solid var(--border);
      cursor: pointer;
      transition: background .12s, opacity .12s;
    }

    .upcoming-item:last-child {
      border-bottom: none;
    }

    .upcoming-item:hover {
      opacity: .85;
    }

    .upcoming-date-box {
      text-align: center;
      min-width: 38px;
      flex-shrink: 0;
    }

    .upcoming-date-box .month {
      font-size: .6rem;
      font-weight: 700;
      text-transform: uppercase;
      color: var(--text-muted);
    }

    .upcoming-date-box .day {
      font-size: 1.2rem;
      font-weight: 800;
      color: var(--text-main);
      line-height: 1;
    }

    .upcoming-info {
      flex: 1;
      min-width: 0;
    }

    .upcoming-title {
      font-size: .78rem;
      font-weight: 600;
      color: var(--text-main);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .upcoming-meta {
      font-size: .68rem;
      color: var(--text-muted);
      margin-top: 2px;
    }

    .status-chip {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 2px 8px;
      border-radius: 12px;
      font-size: .63rem;
      font-weight: 700;
    }

    /* Event detail popup */
    .event-popup {
      display: none;
      position: fixed;
      z-index: 500;
      background: var(--bg-card);
      border-radius: 12px;
      box-shadow: var(--shadow-lg);
      padding: 20px;
      width: 280px;
      border: 1px solid var(--border);
    }

    .event-popup.open {
      display: block;
    }

    .event-popup-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      margin-bottom: 12px;
    }

    .event-popup-title {
      font-size: .88rem;
      font-weight: 700;
      color: var(--text-main);
      flex: 1;
    }

    .event-popup-close {
      background: none;
      border: none;
      cursor: pointer;
      color: var(--text-muted);
      font-size: 1rem;
      line-height: 1;
      padding: 0 0 0 8px;
      transition: color .12s;
    }

    .event-popup-close:hover {
      color: var(--text-main);
    }

    .event-popup-row {
      display: flex;
      gap: 8px;
      align-items: flex-start;
      margin-bottom: 8px;
      font-size: .78rem;
      color: var(--text-secondary);
    }

    .event-popup-row svg {
      width: 14px;
      height: 14px;
      flex-shrink: 0;
      margin-top: 1px;
      color: var(--text-muted);
    }

    .event-popup-actions {
      display: flex;
      gap: 8px;
      margin-top: 14px;
    }

    .popup-btn {
      flex: 1;
      padding: 7px;
      border-radius: 8px;
      font-size: .75rem;
      font-weight: 700;
      cursor: pointer;
      border: none;
      text-align: center;
      text-decoration: none;
      display: inline-block;
      transition: opacity .15s;
    }

    .popup-btn:hover {
      opacity: .85;
    }

    .popup-btn.primary {
      background: var(--sti-blue);
      color: #fff;
    }

    .popup-btn.ghost {
      background: var(--border-light);
      color: var(--text-main);
      border: 1px solid var(--border);
    }

    /* Dark Theme Specific Refinements for Activity Calendar */
    [data-theme="dark"] .cal-day-name {
      background: var(--bg-card-elevated);
      color: var(--text-muted);
    }

    [data-theme="dark"] .cal-cell {
      background: var(--bg-card);
    }

    [data-theme="dark"] .cal-cell:hover {
      background: var(--bg-card-elevated);
    }

    [data-theme="dark"] .cal-cell.other-month {
      background: rgba(0, 0, 0, 0.22);
    }

    [data-theme="dark"] .cal-cell.other-month .day-num {
      color: var(--text-muted);
      opacity: 0.35;
    }

    [data-theme="dark"] .cal-cell.today {
      background: var(--bg-card);
    }

    [data-theme="dark"] .cal-more:hover {
      background: var(--border-light);
      color: var(--text-main);
    }

    [data-theme="dark"] .mini-day.has-event {
      color: var(--sti-blue-hover);
    }

    [data-theme="dark"] .upcoming-item:hover {
      background: rgba(255, 255, 255, 0.03);
    }
  </style>
</head>

<body class="theme-faculty">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">Activity Calendar</div>
      <div class="topbar-right">
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
        <a href="<?= BASE_URL ?>/faculty/proposal-create.php" class="btn btn-primary btn-sm">+ New Proposal</a>
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
                ?>
                  <div class="cal-event"
                    style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>;"
                    onclick="showPopup(event, <?= $ev['id'] ?>, '<?= addslashes(htmlspecialchars($ev['title'])) ?>', '<?= $date ?>', '<?= $venue ?>', '<?= $cfg['color'] ?>', '<?= $cfg['bg'] ?>', '<?= addslashes($statusConfig[$ev['status']]['label'] ?? $ev['status']) ?>')">
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
                  <div class="upcoming-item" onclick="location.href='<?= BASE_URL ?>/faculty/proposal-view.php?id=<?= $ev['id'] ?>'">
                    <div class="upcoming-date-box">
                      <div class="month"><?= date('M', strtotime($ev['event_date'])) ?></div>
                      <div class="day"><?= date('j', strtotime($ev['event_date'])) ?></div>
                    </div>
                    <div class="upcoming-info">
                      <div class="upcoming-title"><?= htmlspecialchars($ev['title']) ?></div>
                      <div class="upcoming-meta"><?= htmlspecialchars($ev['venue'] ?? 'Venue TBD') ?></div>
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

    function showPopup(e, id, title, date, venue, color, bg, status) {
      e.stopPropagation();
      const popup = document.getElementById('eventPopup');

      document.getElementById('popupTitle').textContent = title;
      document.getElementById('popupDate').textContent = date;
      document.getElementById('popupStatus').textContent = status;
      document.getElementById('popupStatus').style.background = bg;
      document.getElementById('popupStatus').style.color = color;
      document.getElementById('popupViewBtn').href = BASE_URL + '/faculty/proposal-view.php?id=' + id;

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