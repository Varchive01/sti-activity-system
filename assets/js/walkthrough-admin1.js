/**
 * Admin1-specific Guided Walkthrough Steps
 *
 * Registered into window.STIWalkthrough for the Admin1 role (Sir Ar-jay).
 * Covers core proposal review queue, monitoring, and navigation.
 */

(function () {
  'use strict';

  const admin1Steps = [
    {
      target: 'aside.sidebar',
      title: 'Admin1 Navigation',
      description: 'The sidebar provides access to proposal review, activities, calendar, reports, and monitoring.'
    },
    {
      target: 'a.sidebar-nav-item[href*="admin1/dashboard.php"]',
      title: 'Admin1 Dashboard',
      description: 'The dashboard summarizes submitted proposals, review activity, KPI information, and trends.'
    },
    {
      target: 'a.sidebar-nav-item[href*="admin1/pending.php"]',
      title: 'Review Queue',
      description: 'This is where submitted student organization proposals are reviewed and acted on.'
    },
    {
      target: 'a.sidebar-nav-item[href*="admin1/activities.php"]',
      title: 'Activities',
      description: 'Access and monitor all student organization activities handled by Admin1.'
    },
    {
      target: 'a.sidebar-nav-item[href*="admin1/calendar.php"]',
      title: 'Calendar',
      description: 'View scheduled activities and approved event timelines on the calendar.'
    },
    {
      target: '#notifBellBtn',
      title: 'Notifications',
      description: 'Workflow updates, reminders, and review alerts appear here in real time.'
    },
    {
      target: '#topbarProfile',
      title: 'Profile',
      description: 'Account settings, password change, profile picture, and logout are available here.'
    },
    {
      target: '#sidebar-help-btn',
      title: 'Help',
      description: 'Click Help anytime to reopen this guided walkthrough whenever you need guidance.'
    }
  ];

  function registerAdmin1Tour() {
    if (typeof window.STIWalkthrough !== 'undefined') {
      window.STIWalkthrough.registerSteps('admin1', admin1Steps);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', registerAdmin1Tour);
  } else {
    registerAdmin1Tour();
  }
})();
