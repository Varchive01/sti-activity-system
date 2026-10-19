/**
 * Dean-specific Guided Walkthrough Steps
 *
 * Registered into window.STIWalkthrough for the Dean role.
 * Covers Dean navigation, dashboard, pending approvals, task monitoring,
 * calendar, KPI/reports, user management, notifications, profile, and help.
 */

(function () {
  'use strict';

  const deanSteps = [
    {
      target: 'aside.sidebar',
      title: 'Dean Navigation',
      description: 'The sidebar provides access to approvals, monitoring, calendar, reports, and user management.'
    },
    {
      target: 'a.sidebar-nav-item[href*="dean/dashboard.php"]',
      title: 'Dean Dashboard',
      description: 'The dashboard provides institutional activity statistics, approval information, KPI results, and AI insights.'
    },
    {
      target: 'a.sidebar-nav-item[href*="dean/pending.php"]',
      title: 'Pending Approvals',
      description: 'Proposals forwarded for final Dean action appear here for institutional review and clearance.'
    },
    {
      target: 'a.sidebar-nav-item[href*="dean/task-monitoring.php"]',
      title: 'Task Monitoring',
      description: 'The Dean can monitor tasks, committees, members, completion, deadlines, and Gantt progress.'
    },
    {
      target: 'a.sidebar-nav-item[href*="dean/calendar.php"]',
      title: 'Calendar',
      description: 'Approved activities are shown on the institutional calendar.'
    },
    {
      target: 'a.sidebar-nav-item[href*="dean/kpi-reports.php"]',
      title: 'KPI / Reports',
      description: 'Review institutional performance, KPI results, and generated activity and compliance reports.'
    },
    {
      target: 'a.sidebar-nav-item[href*="dean/users.php"]',
      title: 'User Management',
      description: 'The Dean manages authorized system accounts and administrative roles according to their permissions.'
    },
    {
      target: '#notifBellBtn',
      title: 'Notifications',
      description: 'Workflow updates, reminders, and task alerts appear here in real time.'
    },
    {
      target: '#topbarProfile',
      title: 'Profile',
      description: 'Account settings, password change, profile picture, and logout are available here.'
    },
    {
      target: '#sidebar-help-btn',
      title: 'Help',
      description: 'Help can reopen this guided walkthrough anytime you need guidance.'
    }
  ];

  function registerDeanTour() {
    if (typeof window.STIWalkthrough !== 'undefined') {
      window.STIWalkthrough.registerSteps('dean', deanSteps);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', registerDeanTour);
  } else {
    registerDeanTour();
  }
})();
