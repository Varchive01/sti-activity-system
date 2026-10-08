<?php
// Usage: include with $role set to: faculty | admin1 | admin2 | dean
$user = currentUser();
$themeMap = ['faculty' => 'theme-faculty', 'admin1' => 'theme-arjay', 'admin2' => 'theme-ian', 'dean' => 'theme-dean'];
$theme = $themeMap[$user['role']] ?? 'theme-faculty';
$initial = strtoupper(substr($user['name'], 0, 1));


if (!function_exists('sidebarTimeAgo')) {
  function sidebarTimeAgo($datetime)
  {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', strtotime($datetime));
  }
}

$navs = [
  'faculty' => [
    ['group' => 'Activity Management', 'links' => [
      ['icon' => 'home', 'label' => 'Dashboard', 'href' => BASE_URL . '/faculty/dashboard.php'],
      ['icon' => 'plus-circle', 'label' => 'New Proposal', 'href' => BASE_URL . '/faculty/proposal-create.php'],
      ['icon' => 'list', 'label' => 'My Activities', 'href' => BASE_URL . '/faculty/activities.php'],
      ['icon' => 'calendar', 'label' => 'Calendar', 'href' => BASE_URL . '/faculty/calendar.php'],
    ]],
    ['group' => 'Post-Event', 'links' => [
      ['icon' => 'upload', 'label' => 'Upload Report', 'href' => BASE_URL . '/faculty/post-event.php'],
      ['icon' => 'bar-chart', 'label' => 'KPI Results', 'href' => BASE_URL . '/faculty/kpi.php'],
    ]],
  ],
  'admin1' => [
    ['group' => 'Review Queue', 'links' => [
      ['icon' => 'home', 'label' => 'Dashboard', 'href' => BASE_URL . '/admin1/dashboard.php'],
      ['icon' => 'inbox', 'label' => 'Pending Reviews', 'href' => BASE_URL . '/admin1/pending.php'],
      ['icon' => 'check-circle', 'label' => 'Approved', 'href' => BASE_URL . '/admin1/approved.php'],
      ['icon' => 'x-circle', 'label' => 'Returned / Rejected', 'href' => BASE_URL . '/admin1/returned.php'],
    ]],
    ['group' => 'Monitoring', 'links' => [
      ['icon' => 'calendar', 'label' => 'Calendar', 'href' => BASE_URL . '/admin1/calendar.php'],
      ['icon' => 'activity', 'label' => 'All Activities', 'href' => BASE_URL . '/admin1/activities.php'],
    ]],
    ['group' => 'Reports', 'links' => [
      ['icon' => 'file-text', 'label' => 'Generate Report', 'href' => BASE_URL . '/admin1/generate-report.php'],
    ]],
  ],
  'admin2' => [
    ['group' => 'Review Queue', 'links' => [
      ['icon' => 'home', 'label' => 'Dashboard', 'href' => BASE_URL . '/admin2/dashboard.php'],
      ['icon' => 'inbox', 'label' => 'Pending Reviews', 'href' => BASE_URL . '/admin2/pending.php'],
      ['icon' => 'check-circle', 'label' => 'Approved', 'href' => BASE_URL . '/admin2/approved.php'],
      ['icon' => 'x-circle', 'label' => 'Returned / Rejected', 'href' => BASE_URL . '/admin2/returned.php'],
    ]],
    ['group' => 'Reports', 'links' => [
      ['icon' => 'bar-chart', 'label' => 'KPI Overview', 'href' => BASE_URL . '/admin2/kpi-overview.php'],
      ['icon' => 'file-text', 'label' => 'Generate Report', 'href' => BASE_URL . '/dean/generate-report.php'],
      ['icon' => 'calendar', 'label' => 'Calendar', 'href' => BASE_URL . '/admin2/calendar.php'],
      ['icon' => 'activity', 'label' => 'All Activities', 'href' => BASE_URL . '/admin2/activities.php'],
    ]],
    ['group' => 'Administration', 'links' => [
      ['icon' => 'users', 'label' => 'User Management', 'href' => BASE_URL . '/admin2/users.php'],
    ]],
  ],
  'dean' => [
    ['group' => 'Final Approval', 'links' => [
      ['icon' => 'home', 'label' => 'Dashboard', 'href' => BASE_URL . '/dean/dashboard.php'],
      ['icon' => 'inbox', 'label' => 'Pending Approvals', 'href' => BASE_URL . '/dean/pending.php'],
      ['icon' => 'check-circle', 'label' => 'Approved', 'href' => BASE_URL . '/dean/approved.php'],
      ['icon' => 'x-circle', 'label' => 'Returned / Rejected', 'href' => BASE_URL . '/dean/returned.php'],
    ]],
    ['group' => 'Overview', 'links' => [
      ['icon' => 'activity', 'label' => 'All Activities', 'href' => BASE_URL . '/dean/activities.php'],
      ['icon' => 'list', 'label' => 'Task Monitoring', 'href' => BASE_URL . '/dean/task-monitoring.php'],
      ['icon' => 'calendar', 'label' => 'Calendar', 'href' => BASE_URL . '/dean/calendar.php'],
    ]],
    ['group' => 'Reports', 'links' => [
      ['icon' => 'bar-chart', 'label' => 'KPI Reports', 'href' => BASE_URL . '/dean/kpi-reports.php'],
      ['icon' => 'file-text', 'label' => 'Generate Report', 'href' => BASE_URL . '/dean/generate-report.php'],
    ]],
    ['group' => 'Administration', 'links' => [
      ['icon' => 'users', 'label' => 'User Management', 'href' => BASE_URL . '/dean/users.php'],
    ]],
  ],
];

$links = $navs[$user['role']] ?? $navs['faculty'];

if (!function_exists('sidebarIcon')) {
  function sidebarIcon($name)
  {
    $icons = [
      'home'         => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>',
      'plus-circle'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"/>',
      'list'         => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>',
      'calendar'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>',
      'upload'       => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>',
      'bar-chart'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>',
      'inbox'        => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>',
      'check-circle' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>',
      'x-circle'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>',
      'activity'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>',
      'file-text'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
      'users'        => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>',
      'help'         => '<circle cx="12" cy="12" r="10" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>',
      'logout'       => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>',
    ];
    $path = $icons[$name] ?? $icons['activity'];
    return '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">' . $path . '</svg>';
  }
}
?>
<script>
  // Apply persistent sidebar state immediately on page load to prevent layout jank
  (function() {
    try {
      const collapsed = localStorage.getItem('sidebar-collapsed') === 'true';
      if (collapsed) {
        document.documentElement.classList.add('sidebar-collapsed');
        if (document.body) {
          document.body.classList.add('sidebar-collapsed');
        }
      }
    } catch (e) {
      console.warn("Failed to apply sidebar state early:", e);
    }
  })();
</script>

<aside class="sidebar <?= $theme ?>">
  <!-- 1. Top Header: Logo & Sidebar Toggle -->
  <div class="sidebar-logo">
    <div class="brand-container">
      <div class="brand">STI <span>Marikina</span></div>
      <div class="sub">Activity Management System</div>
    </div>
    <div class="sidebar-toggle-container">
      <button type="button" id="sidebar-toggle-btn" class="sidebar-toggle" aria-label="Toggle sidebar">
        <!-- Close icon (pointing left) - shown when sidebar is expanded -->
        <svg class="icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect width="18" height="18" x="3" y="3" rx="2" />
          <path d="M9 3v18" />
          <path d="m16 15-3-3 3-3" />
        </svg>
        <!-- Open icon (pointing right) - shown when sidebar is collapsed -->
        <svg class="icon-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect width="18" height="18" x="3" y="3" rx="2" />
          <path d="M9 3v18" />
          <path d="m14 9 3 3-3 3" />
        </svg>
      </button>
      <span class="sidebar-tooltip tooltip-close">Close sidebar</span>
      <span class="sidebar-tooltip tooltip-open">Open sidebar</span>
    </div>
  </div>

  <!-- 2. Middle Navigation Section -->
  <nav class="sidebar-nav">
    <?php foreach ($links as $section): ?>
      <div class="nav-group">
        <div class="nav-label"><?= $section['group'] ?></div>
        <?php foreach ($section['links'] as $link):
          $active = strpos($_SERVER['PHP_SELF'], basename($link['href'])) !== false ? 'active' : ''; ?>
          <a href="<?= $link['href'] ?>" class="sidebar-nav-item <?= $active ?>" aria-label="<?= htmlspecialchars($link['label']) ?>">
            <span class="nav-icon"><?= sidebarIcon($link['icon']) ?></span>
            <span class="nav-label-text"><?= $link['label'] ?></span>
            <span class="sidebar-tooltip"><?= htmlspecialchars($link['label']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </nav>

  <!-- 3. Bottom Utility Area -->
  <div class="sidebar-bottom">
    <!-- Help Button Placeholder -->
    <button type="button" class="sidebar-bottom-btn sidebar-help-btn" id="sidebar-help-btn" aria-label="Help">
      <span class="btn-icon"><?= sidebarIcon('help') ?></span>
      <span class="btn-label-text">Help</span>
      <span class="sidebar-tooltip">Help</span>
    </button>
  </div>
</aside>


<script>
  (function() {
    const toggleBtn = document.getElementById('sidebar-toggle-btn');
    if (toggleBtn) {
      toggleBtn.addEventListener('click', () => {
        const isCollapsed = document.documentElement.classList.toggle('sidebar-collapsed');
        if (document.body) {
          document.body.classList.toggle('sidebar-collapsed', isCollapsed);
        }
        localStorage.setItem('sidebar-collapsed', isCollapsed ? 'true' : 'false');
      });
    }
  })();
</script>
