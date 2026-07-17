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

// Upcoming events list (all future/current month)
$upcoming = $db->prepare("
    SELECT id, title, event_date, status, venue FROM activities
    WHERE faculty_id=? AND event_date >= CURDATE()
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
    /* ── Layout ── */
    .cal-layout {
      display: grid;
      grid-template-columns: 1fr 280px;
      gap: 20px;
      align-items: start;
    }

    /* ── Toolbar ── */
    .cal-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 20px;
      gap: 12px;
    }

    .cal-nav {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .cal-nav-btn {
      width: 34px;
      height: 34px;
      border: 1px solid var(--border);
      border-radius: 50%;
      background: #fff;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: .9rem;
      color: var(--text-muted);
      transition: background .15s, border-color .15s;
      text-decoration: none;
    }

    .cal-nav-btn:hover {
      background: var(--bg-base);
      border-color: #999;
      color: var(--text);
    }

    .cal-month-title {
      font-family: 'Syne', sans-serif;
      font-size: 1.25rem;
      font-weight: 800;
      color: var(--text);
      min-width: 180px;
      text-align: center;
    }

    .cal-today-btn {
      padding: 6px 16px;
      border: 1px solid var(--border);
      border-radius: 20px;
      background: #fff;
      font-size: .8rem;
      font-weight: 600;
      cursor: pointer;
      color: var(--text);
      text-decoration: none;
      transition: background .15s;
    }

    .cal-today-btn:hover {
      background: var(--bg-base);
    }

    /* ── Calendar Grid ── */
    .cal-grid {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      border: 1px solid #E5E7EB;
      border-radius: 12px;
      overflow: hidden;
    }

    .cal-day-name {
      background: #fff;
      text-align: center;
      padding: 10px 4px;
      font-size: .72rem;
      font-weight: 600;
      color: #6B7280;
      text-transform: uppercase;
      letter-spacing: .4px;
      border-bottom: 1px solid #E5E7EB;
    }

    .cal-cell {
      background: #fff;
      min-height: 110px;
      padding: 6px;
      border-right: 1px solid #E5E7EB;
      border-bottom: 1px solid #E5E7EB;
      position: relative;
      transition: background .12s;
      cursor: default;
    }

    .cal-cell:hover {
      background: #FAFAFA;
    }

    .cal-cell.other-month {
      background: #FAFAFA;
    }

    .cal-cell.other-month .day-num {
      color: #D1D5DB;
    }

    .cal-cell.today {
      background: #fff;
    }

    .day-num {
      font-size: .78rem;
      font-weight: 600;
      color: #374151;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 26px;
      height: 26px;
      border-radius: 50%;
      margin-bottom: 3px;
    }

    .cal-cell.today .day-num {
      background: #1A73E8;
      color: #fff;
      font-weight: 700;
    }

    /* ── Events on grid ── */
    .cal-event {
      display: flex;
      align-items: center;
      gap: 5px;
      border-radius: 4px;
      padding: 2px 6px;
      font-size: .67rem;
      font-weight: 600;
      margin-bottom: 2px;
      cursor: pointer;
      transition: filter .12s;
      overflow: hidden;
      white-space: nowrap;
      text-overflow: ellipsis;
    }

    .cal-event:hover {
      filter: brightness(.93);
    }

    .cal-event-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      flex-shrink: 0;
    }

    .cal-event-text {
      overflow: hidden;
      white-space: nowrap;
      text-overflow: ellipsis;
    }

    /* ── More link ── */
    .cal-more {
      font-size: .65rem;
      color: #6B7280;
      font-weight: 600;
      cursor: pointer;
      padding: 1px 4px;
      border-radius: 4px;
    }

    .cal-more:hover {
      background: #F3F4F6;
    }

    /* ── Legend ── */
    .cal-legend {
      display: flex;
      flex-wrap: wrap;
      gap: 8px 16px;
      margin-top: 14px;
      padding-top: 14px;
      border-top: 1px solid #E5E7EB;
    }

    .legend-item {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: .72rem;
      color: #374151;
      font-weight: 500;
    }

    .legend-chip {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 2px 8px;
      border-radius: 4px;
      font-size: .69rem;
      font-weight: 600;
    }

    .legend-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      flex-shrink: 0;
    }

    /* ── Right Sidebar ── */
    .cal-sidebar .card {
      margin-bottom: 16px;
    }

    .cal-sidebar .card-header h2 {
      font-size: .85rem;
    }

    /* Mini calendar */
    .mini-cal {
      width: 100%;
    }

    .mini-cal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 8px;
    }

    .mini-cal-title {
      font-size: .8rem;
      font-weight: 700;
      color: var(--text);
    }

    .mini-nav {
      background: none;
      border: none;
      cursor: pointer;
      color: var(--text-muted);
      font-size: .8rem;
      padding: 2px 6px;
      border-radius: 4px;
      transition: background .12s;
    }

    .mini-nav:hover {
      background: var(--bg-base);
    }

    .mini-grid {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      gap: 2px;
      text-align: center;
    }

    .mini-day-name {
      font-size: .6rem;
      color: #9CA3AF;
      font-weight: 600;
      padding: 2px 0;
    }

    .mini-day {
      font-size: .72rem;
      color: #374151;
      padding: 3px 2px;
      border-radius: 50%;
      cursor: pointer;
      transition: background .12s;
      aspect-ratio: 1;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .mini-day:hover {
      background: #F3F4F6;
    }

    .mini-day.today {
      background: #1A73E8;
      color: #fff;
      font-weight: 700;
    }

    .mini-day.has-event {
      font-weight: 700;
      color: #1A73E8;
    }

    .mini-day.other-month {
      color: #D1D5DB;
    }

    /* Upcoming events */
    .upcoming-item {
      display: flex;
      gap: 12px;
      padding: 10px 0;
      border-bottom: 1px solid #F3F4F6;
      cursor: pointer;
      transition: background .12s;
    }

    .upcoming-item:last-child {
      border-bottom: none;
    }

    .upcoming-item:hover {
      opacity: .8;
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
      color: #6B7280;
    }

    .upcoming-date-box .day {
      font-size: 1.2rem;
      font-weight: 800;
      color: #111;
      line-height: 1;
    }

    .upcoming-info {
      flex: 1;
      min-width: 0;
    }

    .upcoming-title {
      font-size: .78rem;
      font-weight: 600;
      color: #111;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .upcoming-meta {
      font-size: .68rem;
      color: #9CA3AF;
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
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 8px 30px rgba(0, 0, 0, .18);
      padding: 20px;
      width: 280px;
      border: 1px solid #E5E7EB;
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
      color: #111;
      flex: 1;
    }

    .event-popup-close {
      background: none;
      border: none;
      cursor: pointer;
      color: #9CA3AF;
      font-size: 1rem;
      line-height: 1;
      padding: 0 0 0 8px;
    }

    .event-popup-row {
      display: flex;
      gap: 8px;
      align-items: flex-start;
      margin-bottom: 8px;
      font-size: .78rem;
      color: #374151;
    }

    .event-popup-row svg {
      width: 14px;
      height: 14px;
      flex-shrink: 0;
      margin-top: 1px;
      color: #9CA3AF;
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
      background: #1A73E8;
      color: #fff;
    }

    .popup-btn.ghost {
      background: #F3F4F6;
      color: #374151;
    }
  </style>
</head>

<body class="theme-faculty">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">Activity Calendar</div>
      <div class="topbar-right">
        <a href="<?= BASE_URL ?>/faculty/proposal-create.php" class="btn btn-primary btn-sm">+ New Proposal</a>
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

          <!-- Mini Calendar -->
          <div class="card">
            <div class="card-body" style="padding:16px;">
              <div class="mini-cal">
                <div class="mini-cal-header">
                  <a href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="mini-nav">&#8249;</a>
                  <div class="mini-cal-title"><?= date('F Y', mktime(0, 0, 0, $month, 1, $year)) ?></div>
                  <a href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="mini-nav">&#8250;</a>
                </div>
                <div class="mini-grid">
                  <?php foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $d): ?>
                    <div class="mini-day-name"><?= $d ?></div>
                  <?php endforeach; ?>
                  <?php
                  for ($i = 0; $i < $firstDayOfWeek; $i++) echo '<div class="mini-day other-month"></div>';
                  for ($d = 1; $d <= $daysInMonth; $d++):
                    $cls = '';
                    if ($d === $today && $month === $todayMonth && $year === $todayYear) $cls = 'today';
                    elseif (!empty($monthEvents[$d])) $cls = 'has-event';
                  ?>
                    <a href="?month=<?= $month ?>&year=<?= $year ?>#cell-<?= $d ?>" class="mini-day <?= $cls ?>"><?= $d ?></a>
                  <?php endfor; ?>
                  <?php for ($i = 0; $i < $trailing; $i++) echo '<div class="mini-day other-month"></div>'; ?>
                </div>
              </div>
            </div>
          </div>

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