<?php
// Usage: include with $role set to: faculty | admin1 | admin2 | dean
$user = currentUser();
$themeMap = ['faculty' => 'theme-faculty', 'admin1' => 'theme-arjay', 'admin2' => 'theme-ian', 'dean' => 'theme-dean'];
$theme = $themeMap[$user['role']] ?? 'theme-faculty';
$initial = strtoupper(substr($user['name'], 0, 1));

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
      ['icon' => 'calendar', 'label' => 'Calendar', 'href' => BASE_URL . '/admin2/calendar.php'],
      ['icon' => 'activity', 'label' => 'All Activities', 'href' => BASE_URL . '/admin2/activities.php'],
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
      ['icon' => 'calendar', 'label' => 'Calendar', 'href' => BASE_URL . '/dean/calendar.php'],
    ]],
    ['group' => 'Reports', 'links' => [
      ['icon' => 'bar-chart', 'label' => 'KPI Reports', 'href' => BASE_URL . '/dean/kpi-reports.php'],
      ['icon' => 'file-text', 'label' => 'Generate Report', 'href' => BASE_URL . '/dean/generate-report.php'],
    ]],
  ],
];

$links = $navs[$user['role']] ?? $navs['faculty'];

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
  ];
  $path = $icons[$name] ?? $icons['activity'];
  return '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">' . $path . '</svg>';
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

<style>
/* Clean standalone square toggle button styling */
.sidebar-logo {
  min-height: 72px !important;
}
.sidebar-collapsed .sidebar-logo {
  padding: 20px 0 !important;
  min-height: 72px !important;
  display: flex !important;
  justify-content: center !important;
  align-items: center !important;
}
.sidebar-toggle-container {
  position: absolute !important;
  top: 20px !important;
  right: 18px !important;
  z-index: 10 !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  flex-shrink: 0 !important;
  width: 32px !important;
  height: 32px !important;
}
.sidebar-toggle {
  background: transparent !important; /* no visible background container by default */
  border: none !important; /* clean standalone icon style */
  color: rgba(255, 255, 255, 0.75) !important;
  cursor: pointer !important;
  width: 32px !important;
  height: 32px !important;
  border-radius: 6px !important; /* square with slightly rounded corners */
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
  outline: none !important;
  padding: 0 !important;
  flex-shrink: 0 !important;
  box-shadow: none !important;
}
.sidebar-toggle:hover {
  background: rgba(255, 255, 255, 0.1) !important; /* subtle hover background */
  color: #fff !important;
}
.sidebar-toggle:active {
  transform: scale(0.95) !important;
}
.sidebar-toggle svg {
  width: 18px !important;
  height: 18px !important;
}
.sidebar-collapsed .sidebar-toggle {
  margin-top: 0 !important;
}
/* Tooltip styling */
.sidebar-toggle-container .sidebar-tooltip {
  left: 100% !important;
  transform: translateY(-50%) translateX(6px) scale(0.95) !important;
}
.sidebar-toggle-container:hover .sidebar-tooltip {
  transform: translateY(-50%) translateX(12px) scale(1) !important;
}
</style>

<aside class="sidebar <?= $theme ?>">
  <div class="sidebar-logo">
    <div class="brand-container">
      <div class="brand">STI <span>Marikina</span></div>
      <div class="sub">Activity Management System</div>
    </div>
    <div class="sidebar-toggle-container">
      <button type="button" id="sidebar-toggle-btn" class="sidebar-toggle">
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
  <div class="sidebar-user">
    <div class="avatar"><?= $initial ?></div>
    <div>
      <div class="uname"><?= htmlspecialchars($user['name']) ?></div>
      <div class="urole"><?= ucwords(str_replace('_', ' ', $user['role'])) ?></div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <?php foreach ($links as $section): ?>
      <div class="nav-label"><?= $section['group'] ?></div>
      <?php foreach ($section['links'] as $link):
        $active = strpos($_SERVER['PHP_SELF'], basename($link['href'])) !== false ? 'active' : ''; ?>
        <a href="<?= $link['href'] ?>" class="<?= $active ?>">
          <?= sidebarIcon($link['icon']) ?>
          <?= $link['label'] ?>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-footer">
    <a href="<?= BASE_URL ?>/auth/logout.php">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:15px;height:15px">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
      </svg>
      Logout
    </a>
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
