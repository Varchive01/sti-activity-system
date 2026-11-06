<?php
// Usage: include with $role set to: faculty | admin1 | admin2 | dean
$user = currentUser();
$themeMap = ['faculty' => 'theme-faculty', 'admin1' => 'theme-arjay', 'admin2' => 'theme-ian', 'dean' => 'theme-dean', 'program_head' => 'theme-faculty'];
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
  'program_head' => [
    ['group' => 'Department Management', 'links' => [
      ['icon' => 'home', 'label' => 'Dashboard', 'href' => BASE_URL . '/program_head/dashboard.php'],
      ['icon' => 'plus-circle', 'label' => 'New Proposal', 'href' => BASE_URL . '/faculty/proposal-create.php'],
      ['icon' => 'activity', 'label' => 'Department Activities', 'href' => BASE_URL . '/faculty/activities.php'],
      ['icon' => 'inbox', 'label' => 'Pending Proposals', 'href' => BASE_URL . '/faculty/pending.php'],
      ['icon' => 'check-circle', 'label' => 'Approved Activities', 'href' => BASE_URL . '/faculty/approved.php'],
      ['icon' => 'x-circle', 'label' => 'Returned / Rejected', 'href' => BASE_URL . '/faculty/returned.php'],
    ]],
    ['group' => 'Monitoring', 'links' => [
      ['icon' => 'list', 'label' => 'Task Monitoring', 'href' => BASE_URL . '/faculty/tasks.php'],
      ['icon' => 'users', 'label' => 'Faculty Contributors', 'href' => BASE_URL . '/program_head/contributors.php'],
      ['icon' => 'calendar', 'label' => 'Calendar', 'href' => BASE_URL . '/faculty/calendar.php'],
    ]],
    ['group' => 'Post-Event', 'links' => [
      ['icon' => 'upload', 'label' => 'Post-Event', 'href' => BASE_URL . '/faculty/post-event.php'],
      ['icon' => 'bar-chart', 'label' => 'KPI and Evaluation Reports', 'href' => BASE_URL . '/faculty/kpi.php'],
      ['icon' => 'file-text', 'label' => 'Generate Report', 'href' => BASE_URL . '/dean/generate-report.php'],
    ]],
  ],
  'faculty' => [
    ['group' => 'Activity Management', 'links' => [
      ['icon' => 'home', 'label' => 'Dashboard', 'href' => BASE_URL . '/faculty/dashboard.php'],
      ['icon' => 'plus-circle', 'label' => 'New Proposal', 'href' => BASE_URL . '/faculty/proposal-create.php'],
      ['icon' => 'list', 'label' => 'My Activities', 'href' => BASE_URL . '/faculty/activities.php'],
      ['icon' => 'inbox', 'label' => 'Pending Proposals', 'href' => BASE_URL . '/faculty/pending.php'],
      ['icon' => 'check-circle', 'label' => 'Approved Activities', 'href' => BASE_URL . '/faculty/approved.php'],
      ['icon' => 'x-circle', 'label' => 'Returned / Rejected', 'href' => BASE_URL . '/faculty/returned.php'],
      ['icon' => 'check-circle', 'label' => 'My Tasks', 'href' => BASE_URL . '/faculty/tasks.php'],
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
      ['icon' => 'check-circle', 'label' => 'My Tasks', 'href' => BASE_URL . '/admin1/tasks.php'],
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
    ['group' => 'Monitoring', 'links' => [
      ['icon' => 'check-circle', 'label' => 'My Tasks', 'href' => BASE_URL . '/admin2/tasks.php'],
      ['icon' => 'calendar', 'label' => 'Calendar', 'href' => BASE_URL . '/admin2/calendar.php'],
      ['icon' => 'activity', 'label' => 'All Activities', 'href' => BASE_URL . '/admin2/activities.php'],
    ]],
    ['group' => 'Reports', 'links' => [
      ['icon' => 'bar-chart', 'label' => 'KPI Overview', 'href' => BASE_URL . '/admin2/kpi-overview.php'],
      ['icon' => 'file-text', 'label' => 'Generate Report', 'href' => BASE_URL . '/dean/generate-report.php'],
    ]],
    ['group' => 'Administration', 'links' => [
      ['icon' => 'users', 'label' => 'User Management', 'href' => BASE_URL . '/admin2/users.php'],
      ['icon' => 'settings', 'label' => 'System Settings', 'href' => BASE_URL . '/admin2/settings.php'],
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
      'settings'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',
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
      if (collapsed && window.innerWidth >= 1024) {
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
  /* ── Light Mode Sidebar (Default & Explicit) ── */
  html:not([data-theme="dark"]) .sidebar,
  [data-theme="light"] .sidebar {
    background: #FFFFFF !important;
    border-right: 1px solid #E2E8F0 !important;
    box-shadow: 2px 0 16px rgba(0, 0, 0, 0.05) !important;
  }
  html:not([data-theme="dark"]) .sidebar-logo,
  [data-theme="light"] .sidebar-logo {
    border-bottom: 1px solid #E2E8F0 !important;
  }
  html:not([data-theme="dark"]) .sidebar-logo .brand,
  [data-theme="light"] .sidebar-logo .brand {
    color: #0A1628 !important;
  }
  html:not([data-theme="dark"]) .sidebar-logo .brand span,
  [data-theme="light"] .sidebar-logo .brand span {
    color: var(--sti-gold, #F4A900) !important;
  }
  html:not([data-theme="dark"]) .sidebar-logo .sub,
  [data-theme="light"] .sidebar-logo .sub {
    color: #64748B !important;
  }
  html:not([data-theme="dark"]) .sidebar-toggle,
  [data-theme="light"] .sidebar-toggle {
    color: #475569 !important;
  }
  html:not([data-theme="dark"]) .sidebar-toggle:hover,
  [data-theme="light"] .sidebar-toggle:hover {
    background: #F1F5F9 !important;
    color: #0A1628 !important;
  }
  html:not([data-theme="dark"]) .sidebar-nav::-webkit-scrollbar-thumb,
  [data-theme="light"] .sidebar-nav::-webkit-scrollbar-thumb {
    background: rgba(0, 0, 0, 0.12) !important;
    border-radius: 9999px;
  }
  html:not([data-theme="dark"]) .sidebar-nav::-webkit-scrollbar-thumb:hover,
  [data-theme="light"] .sidebar-nav::-webkit-scrollbar-thumb:hover {
    background: rgba(0, 0, 0, 0.22) !important;
  }
  html:not([data-theme="dark"]) .sidebar-nav .nav-label,
  [data-theme="light"] .sidebar-nav .nav-label {
    color: #64748B !important;
  }
  html:not([data-theme="dark"]) .sidebar-nav a.sidebar-nav-item,
  html:not([data-theme="dark"]) .sidebar-nav a,
  [data-theme="light"] .sidebar-nav a.sidebar-nav-item,
  [data-theme="light"] .sidebar-nav a {
    color: #334155 !important;
  }
  html:not([data-theme="dark"]) .sidebar-nav a svg,
  [data-theme="light"] .sidebar-nav a svg {
    color: #64748B !important;
  }
  html:not([data-theme="dark"]) .sidebar-nav a:hover,
  [data-theme="light"] .sidebar-nav a:hover {
    background: #F1F5F9 !important;
    color: #0284C7 !important;
  }
  html:not([data-theme="dark"]) .sidebar-nav a:hover svg,
  [data-theme="light"] .sidebar-nav a:hover svg {
    color: #0284C7 !important;
  }
  html:not([data-theme="dark"]) .sidebar-nav a.active,
  html:not([data-theme="dark"]) aside.sidebar .sidebar-nav a.active,
  [data-theme="light"] .sidebar-nav a.active,
  [data-theme="light"] aside.sidebar .sidebar-nav a.active {
    background: #0284C7 !important;
    color: #FFFFFF !important;
    font-weight: 600 !important;
    box-shadow: 0 2px 8px rgba(2, 132, 199, 0.35) !important;
  }
  html:not([data-theme="dark"]) .sidebar-nav a.active svg,
  html:not([data-theme="dark"]) .sidebar-nav a.active .nav-icon svg,
  [data-theme="light"] .sidebar-nav a.active svg,
  [data-theme="light"] .sidebar-nav a.active .nav-icon svg {
    color: #FFFFFF !important;
  }
  html:not([data-theme="dark"]) .sidebar-bottom,
  [data-theme="light"] .sidebar-bottom {
    border-top: 1px solid #E2E8F0 !important;
    background: #F8FAFC !important;
  }
  html:not([data-theme="dark"]) .sidebar-bottom-btn,
  [data-theme="light"] .sidebar-bottom-btn {
    color: #334155 !important;
  }
  html:not([data-theme="dark"]) .sidebar-bottom-btn svg,
  [data-theme="light"] .sidebar-bottom-btn svg {
    color: #64748B !important;
  }
  html:not([data-theme="dark"]) .sidebar-bottom-btn:hover,
  [data-theme="light"] .sidebar-bottom-btn:hover {
    background: #E2E8F0 !important;
    color: #0A1628 !important;
  }
  html:not([data-theme="dark"]) .sidebar-bottom-btn:hover svg,
  [data-theme="light"] .sidebar-bottom-btn:hover svg {
    color: #0A1628 !important;
  }
  html:not([data-theme="dark"]) .sidebar-help-btn:hover,
  [data-theme="light"] .sidebar-help-btn:hover {
    color: #0284C7 !important;
    background: #E0F2FE !important;
  }
  html:not([data-theme="dark"]) .sidebar-help-btn:hover svg,
  [data-theme="light"] .sidebar-help-btn:hover svg {
    color: #0284C7 !important;
  }
  html:not([data-theme="dark"]) .sidebar .notif-bell-btn.sidebar-bottom-btn,
  [data-theme="light"] .sidebar .notif-bell-btn.sidebar-bottom-btn {
    color: #334155 !important;
  }
  html:not([data-theme="dark"]) .sidebar .notif-bell-btn.sidebar-bottom-btn:hover,
  [data-theme="light"] .sidebar .notif-bell-btn.sidebar-bottom-btn:hover {
    color: #0A1628 !important;
    background: #E2E8F0 !important;
  }
  html:not([data-theme="dark"]) .sidebar-user:hover,
  [data-theme="light"] .sidebar-user:hover {
    background: #F1F5F9 !important;
  }
  html:not([data-theme="dark"]) .sidebar-user .uname,
  [data-theme="light"] .sidebar-user .uname {
    color: #0A1628 !important;
  }
  html:not([data-theme="dark"]) .sidebar-user .urole,
  [data-theme="light"] .sidebar-user .urole {
    color: #64748B !important;
  }
  html:not([data-theme="dark"]) .sidebar-collapsed .sidebar-nav .nav-group:not(:last-child)::after,
  [data-theme="light"] .sidebar-collapsed .sidebar-nav .nav-group:not(:last-child)::after {
    background: #E2E8F0 !important;
  }
  html:not([data-theme="dark"]) .sidebar-collapsed .sidebar-nav a.active,
  [data-theme="light"] .sidebar-collapsed .sidebar-nav a.active {
    background: #0284C7 !important;
    color: #FFFFFF !important;
    border-radius: 12px !important;
    box-shadow: 0 3px 12px rgba(2, 132, 199, 0.35) !important;
  }
  html:not([data-theme="dark"]) .sidebar-collapsed .sidebar-nav a.active svg,
  [data-theme="light"] .sidebar-collapsed .sidebar-nav a.active svg {
    color: #FFFFFF !important;
  }
  html:not([data-theme="dark"]) .sidebar-collapsed .sidebar-nav a:hover,
  [data-theme="light"] .sidebar-collapsed .sidebar-nav a:hover {
    background: #F1F5F9 !important;
    color: #0284C7 !important;
  }
  html:not([data-theme="dark"]) .sidebar-collapsed .sidebar-nav a:hover svg,
  [data-theme="light"] .sidebar-collapsed .sidebar-nav a:hover svg {
    color: #0284C7 !important;
  }
  html:not([data-theme="dark"]) .sidebar-collapsed .sidebar-bottom-btn:hover,
  [data-theme="light"] .sidebar-collapsed .sidebar-bottom-btn:hover {
    background: #E2E8F0 !important;
    color: #0A1628 !important;
  }

  /* ── Dark Mode Sidebar ── */
  [data-theme="dark"] .sidebar {
    background: #0B1524 !important;
    border-right: 1px solid #1E2D45 !important;
    box-shadow: 2px 0 16px rgba(0, 0, 0, 0.45) !important;
  }
  [data-theme="dark"] .sidebar-logo {
    border-bottom: 1px solid #1E2D45 !important;
  }
  [data-theme="dark"] .sidebar-logo .brand {
    color: #F8FAFC !important;
  }
  [data-theme="dark"] .sidebar-logo .brand span {
    color: var(--sti-gold, #FBBF24) !important;
  }
  [data-theme="dark"] .sidebar-logo .sub {
    color: #8899B2 !important;
  }
  [data-theme="dark"] .sidebar-toggle {
    color: #CBD5E1 !important;
  }
  [data-theme="dark"] .sidebar-toggle:hover {
    background: rgba(255, 255, 255, 0.08) !important;
    color: #FFFFFF !important;
  }
  [data-theme="dark"] .sidebar-nav::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.12) !important;
    border-radius: 9999px;
  }
  [data-theme="dark"] .sidebar-nav::-webkit-scrollbar-thumb:hover {
    background: rgba(255, 255, 255, 0.22) !important;
  }
  [data-theme="dark"] .sidebar-nav .nav-label {
    color: #8899B2 !important;
  }
  [data-theme="dark"] .sidebar-nav a.sidebar-nav-item,
  [data-theme="dark"] .sidebar-nav a {
    color: #CBD5E1 !important;
  }
  [data-theme="dark"] .sidebar-nav a svg {
    color: #8899B2 !important;
  }
  [data-theme="dark"] .sidebar-nav a:hover {
    background: rgba(255, 255, 255, 0.08) !important;
    color: #FFFFFF !important;
  }
  [data-theme="dark"] .sidebar-nav a:hover svg {
    color: #38BDF8 !important;
  }
  [data-theme="dark"] .sidebar-nav a.active,
  [data-theme="dark"] aside.sidebar .sidebar-nav a.active {
    background: #0284C7 !important;
    color: #FFFFFF !important;
    font-weight: 600 !important;
    box-shadow: 0 2px 8px rgba(2, 132, 199, 0.35) !important;
  }
  [data-theme="dark"] .sidebar-nav a.active svg,
  [data-theme="dark"] .sidebar-nav a.active .nav-icon svg {
    color: #FFFFFF !important;
  }
  [data-theme="dark"] .sidebar-bottom {
    border-top: 1px solid #1E2D45 !important;
    background: rgba(0, 0, 0, 0.25) !important;
  }
  [data-theme="dark"] .sidebar-bottom-btn {
    color: #CBD5E1 !important;
  }
  [data-theme="dark"] .sidebar-bottom-btn svg {
    color: #8899B2 !important;
  }
  [data-theme="dark"] .sidebar-bottom-btn:hover {
    background: rgba(255, 255, 255, 0.08) !important;
    color: #FFFFFF !important;
  }
  [data-theme="dark"] .sidebar-bottom-btn:hover svg {
    color: #FFFFFF !important;
  }
  [data-theme="dark"] .sidebar-help-btn:hover {
    color: #38BDF8 !important;
    background: rgba(2, 132, 199, 0.20) !important;
  }
  [data-theme="dark"] .sidebar-help-btn:hover svg {
    color: #38BDF8 !important;
  }
  [data-theme="dark"] .sidebar .notif-bell-btn.sidebar-bottom-btn {
    color: #CBD5E1 !important;
  }
  [data-theme="dark"] .sidebar .notif-bell-btn.sidebar-bottom-btn:hover {
    color: #FFFFFF !important;
    background: rgba(255, 255, 255, 0.08) !important;
  }
  [data-theme="dark"] .sidebar-user:hover {
    background: rgba(255, 255, 255, 0.05) !important;
  }
  [data-theme="dark"] .sidebar-user .uname {
    color: #F8FAFC !important;
  }
  [data-theme="dark"] .sidebar-user .urole {
    color: #8899B2 !important;
  }
  [data-theme="dark"] .sidebar-collapsed .sidebar-nav .nav-group:not(:last-child)::after {
    background: #1E2D45 !important;
  }
  [data-theme="dark"] .sidebar-collapsed .sidebar-nav a.active {
    background: #0284C7 !important;
    color: #FFFFFF !important;
    border-radius: 12px !important;
    box-shadow: 0 3px 12px rgba(2, 132, 199, 0.35) !important;
  }
  [data-theme="dark"] .sidebar-collapsed .sidebar-nav a.active svg {
    color: #FFFFFF !important;
  }
  [data-theme="dark"] .sidebar-collapsed .sidebar-nav a:hover {
    background: rgba(255, 255, 255, 0.08) !important;
    color: #FFFFFF !important;
  }
  [data-theme="dark"] .sidebar-collapsed .sidebar-nav a:hover svg {
    color: #38BDF8 !important;
  }
  [data-theme="dark"] .sidebar-collapsed .sidebar-bottom-btn:hover {
    background: rgba(255, 255, 255, 0.08) !important;
    color: #FFFFFF !important;
  }

  /* Mobile drawer toggle & backdrop - hidden by default on desktop (>=1024px) */
  #mobile-nav-toggle,
  .mobile-nav-toggle {
    display: none !important;
  }
  #sidebar-backdrop,
  .sidebar-backdrop {
    display: none !important;
  }

  @media (max-width: 1023.98px) {
    #mobile-nav-toggle,
    .mobile-nav-toggle {
      display: inline-flex !important;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      touch-action: manipulation;
    }
    .mobile-nav-toggle svg,
    .mobile-nav-toggle path {
      pointer-events: none !important;
    }
    #sidebar-backdrop,
    .sidebar-backdrop {
      display: none !important;
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
    }
    html.mobile-drawer-open #sidebar-backdrop,
    body.mobile-drawer-open #sidebar-backdrop,
    html.mobile-drawer-open .sidebar-backdrop,
    body.mobile-drawer-open .sidebar-backdrop {
      display: block !important;
      opacity: 1 !important;
      visibility: visible !important;
      pointer-events: auto !important;
    }
    html.mobile-drawer-open .sidebar,
    body.mobile-drawer-open .sidebar,
    html.sidebar-collapsed.mobile-drawer-open .sidebar,
    body.sidebar-collapsed.mobile-drawer-open .sidebar,
    html.mobile-drawer-open.sidebar-collapsed .sidebar,
    body.mobile-drawer-open.sidebar-collapsed .sidebar {
      transform: translateX(0) !important;
    }
    /* Safeguard: On mobile drawer, ensure off-canvas positioning when closed and readable layout when open */
    .sidebar,
    .sidebar-collapsed .sidebar,
    html.sidebar-collapsed .sidebar,
    body.sidebar-collapsed .sidebar {
      position: fixed !important;
      top: 0 !important;
      left: 0 !important;
      bottom: 0 !important;
      height: 100vh !important;
      height: 100dvh !important;
      width: 280px !important;
      max-width: 85vw !important;
      transform: translateX(-100%) !important;
      transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.28s ease !important;
      z-index: 1050 !important;
      box-shadow: none !important;
      overflow-y: auto !important;
      overflow-x: hidden !important;
    }
    .sidebar-collapsed .sidebar-logo .brand-container {
      display: block !important;
    }
    .sidebar-collapsed .sidebar-nav .nav-label {
      display: block !important;
    }
    .sidebar-collapsed .sidebar-nav a.sidebar-nav-item .nav-label-text {
      display: inline !important;
    }
    .sidebar-collapsed .sidebar-bottom-btn .btn-label-text {
      display: inline !important;
    }
    .sidebar-collapsed .sidebar-nav a.sidebar-nav-item,
    .sidebar-collapsed .sidebar-nav a {
      width: auto !important;
      height: auto !important;
      justify-content: flex-start !important;
      padding: 11px 14px !important;
      margin: 0 0 3px 0 !important;
      font-size: 16px !important;
      gap: 12px !important;
    }
    .sidebar-collapsed .sidebar-bottom-btn {
      width: 100% !important;
      height: auto !important;
      justify-content: flex-start !important;
      padding: 10px 14px !important;
      gap: 12px !important;
    }
    .sidebar-collapsed .sidebar-tooltip {
      display: none !important;
    }
    .main-wrap,
    .sidebar-collapsed .main-wrap,
    html.sidebar-collapsed .main-wrap,
    body.sidebar-collapsed .main-wrap {
      margin-left: 0 !important;
    }
    /* Hide only the "Close sidebar" tooltip on mobile/tablet screens (< 1024px) */
    .sidebar-tooltip.tooltip-close {
      display: none !important;
      visibility: hidden !important;
      opacity: 0 !important;
      pointer-events: none !important;
    }
  }

  @media (min-width: 1024px) {
    #mobile-nav-toggle,
    .mobile-nav-toggle {
      display: none !important;
    }
    #sidebar-backdrop,
    .sidebar-backdrop {
      display: none !important;
    }
  }
</style>

<!-- Off-canvas Mobile Drawer Backdrop -->
<div id="sidebar-backdrop" class="sidebar-backdrop" aria-hidden="true"></div>

<aside class="sidebar <?= $theme ?>" id="app-sidebar" aria-label="Main Navigation">
  <!-- 1. Top Header: Logo & Sidebar Toggle -->
  <div class="sidebar-logo">
    <div class="brand-container">
      <div class="brand">STI <span>Marikina</span></div>
      <div class="sub">Activity Management System</div>
      <?php if (($user['role'] ?? '') === 'program_head' && !empty($user['dept'])): ?>
        <div class="sidebar-dept-badge" style="font-size: 0.72rem; font-weight: 700; color: #0284c7; background: rgba(2,132,199,0.08); padding: 2px 8px; border-radius: 6px; margin-top: 4px; display: inline-block; border: 1px solid rgba(2,132,199,0.2);">
          <?= htmlspecialchars($user['dept']) ?>
        </div>
      <?php endif; ?>
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
    <!-- Help / Guided Walkthrough Button -->
    <button type="button" class="sidebar-bottom-btn sidebar-help-btn" id="sidebar-help-btn" aria-label="Help">
      <span class="btn-icon"><?= sidebarIcon('help') ?></span>
      <span class="btn-label-text">Help</span>
      <span class="sidebar-tooltip">Help</span>
    </button>
  </div>
</aside>

<link rel="stylesheet" href="<?= defined('BASE_URL') ? BASE_URL : '' ?>/assets/css/walkthrough.css">
<script src="<?= defined('BASE_URL') ? BASE_URL : '' ?>/assets/js/walkthrough.js"></script>
<?php if (in_array(($user['role'] ?? ''), ['faculty', 'program_head'], true)): ?>
<script src="<?= defined('BASE_URL') ? BASE_URL : '' ?>/assets/js/walkthrough-faculty.js"></script>
<?php elseif (($user['role'] ?? '') === 'admin1'): ?>
<script src="<?= defined('BASE_URL') ? BASE_URL : '' ?>/assets/js/walkthrough-admin1.js"></script>
<?php elseif (($user['role'] ?? '') === 'admin2'): ?>
<script src="<?= defined('BASE_URL') ? BASE_URL : '' ?>/assets/js/walkthrough-admin2.js"></script>
<?php elseif (($user['role'] ?? '') === 'dean'): ?>
<script src="<?= defined('BASE_URL') ? BASE_URL : '' ?>/assets/js/walkthrough-dean.js"></script>
<?php endif; ?>

<script>
  (function() {
    const toggleBtn = document.getElementById('sidebar-toggle-btn');
    const backdrop = document.getElementById('sidebar-backdrop');

    function closeMobileDrawer() {
      document.documentElement.classList.remove('mobile-drawer-open');
      document.body.classList.remove('mobile-drawer-open');
      const mobileToggle = document.getElementById('mobile-nav-toggle');
      if (mobileToggle) mobileToggle.setAttribute('aria-expanded', 'false');
    }

    function openMobileDrawer() {
      document.documentElement.classList.add('mobile-drawer-open');
      document.body.classList.add('mobile-drawer-open');
      const mobileToggle = document.getElementById('mobile-nav-toggle');
      if (mobileToggle) mobileToggle.setAttribute('aria-expanded', 'true');
    }

    function toggleMobileDrawer() {
      const isOpen = document.body.classList.contains('mobile-drawer-open');
      if (isOpen) {
        closeMobileDrawer();
      } else {
        openMobileDrawer();
      }
    }

    if (toggleBtn) {
      toggleBtn.addEventListener('click', () => {
        if (window.innerWidth < 1024) {
          // On mobile, clicking toggle inside drawer closes it
          closeMobileDrawer();
        } else {
          // On desktop, toggle collapse
          const isCollapsed = document.documentElement.classList.toggle('sidebar-collapsed');
          if (document.body) {
            document.body.classList.toggle('sidebar-collapsed', isCollapsed);
          }
          localStorage.setItem('sidebar-collapsed', isCollapsed ? 'true' : 'false');
        }
      });
    }

    if (backdrop) {
      let lastBackdropTouch = 0;
      backdrop.addEventListener('touchend', (e) => {
        lastBackdropTouch = Date.now();
        e.preventDefault();
        closeMobileDrawer();
      }, { passive: false });
      backdrop.addEventListener('click', (e) => {
        if (Date.now() - lastBackdropTouch < 500) return;
        closeMobileDrawer();
      });
    }

    // Escape key closes mobile drawer
    function handleEscape(e) {
      if ((e.key === 'Escape' || e.key === 'Esc' || e.keyCode === 27) && 
          (document.body.classList.contains('mobile-drawer-open') || document.documentElement.classList.contains('mobile-drawer-open'))) {
        closeMobileDrawer();
      }
    }
    document.addEventListener('keydown', handleEscape, true);
    window.addEventListener('keydown', handleEscape, true);

    // Close drawer when clicking any sidebar navigation link on small screens
    const navLinks = document.querySelectorAll('.sidebar-nav a');
    navLinks.forEach(link => {
      link.addEventListener('click', () => {
        if (window.innerWidth < 1024) {
          closeMobileDrawer();
        }
      });
    });

    // Automatically inject accessible hamburger toggle button into primary .topbar
    function initMobileTopbarButton() {
      const topbar = document.querySelector('.topbar');
      if (!topbar || document.getElementById('mobile-nav-toggle')) return;

      const btn = document.createElement('button');
      btn.type = 'button';
      btn.id = 'mobile-nav-toggle';
      btn.className = 'mobile-nav-toggle';
      btn.setAttribute('aria-label', 'Toggle navigation menu');
      btn.setAttribute('aria-expanded', 'false');
      btn.setAttribute('aria-controls', 'app-sidebar');
      btn.innerHTML = `
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
        </svg>
      `;

      let lastToggleTouch = 0;
      btn.addEventListener('touchend', (e) => {
        lastToggleTouch = Date.now();
        e.preventDefault();
        e.stopPropagation();
        toggleMobileDrawer();
      }, { passive: false });

      btn.addEventListener('click', (e) => {
        if (Date.now() - lastToggleTouch < 500) return;
        e.preventDefault();
        e.stopPropagation();
        toggleMobileDrawer();
      });

      topbar.insertAdjacentElement('afterbegin', btn);
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initMobileTopbarButton);
      window.addEventListener('load', initMobileTopbarButton);
    } else {
      initMobileTopbarButton();
    }
  })();
</script>
