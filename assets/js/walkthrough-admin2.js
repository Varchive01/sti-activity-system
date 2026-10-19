/**
 * Admin2-specific Guided Walkthrough Steps
 *
 * Registered into window.STIWalkthrough for the Admin2 role (Sir Ian).
 * Covers proposal review, activities, calendar, KPI/monitoring tools, and user management.
 */

(function () {
  'use strict';

  const admin2Steps = [
    {
      target: 'aside.sidebar',
      title: 'Admin2 Navigation',
      description: 'The sidebar provides access to proposal review, activities, calendar, KPI/monitoring tools, and user management.'
    },
    {
      target: 'a.sidebar-nav-item[href*="admin2/dashboard.php"]',
      title: 'Admin2 Dashboard',
      description: 'The dashboard summarizes faculty proposals, review activity, KPI information, and upcoming activities.'
    },
    {
      target: 'a.sidebar-nav-item[href*="admin2/pending.php"]',
      title: 'Review Queue',
      description: 'This is where faculty proposals requiring Admin2 action are reviewed.'
    },
    {
      target: 'a.sidebar-nav-item[href*="admin2/activities.php"]',
      title: 'Activities',
      description: 'Access and monitor faculty activity records and ongoing proposals handled by Admin2.'
    },
    {
      target: 'a.sidebar-nav-item[href*="admin2/calendar.php"]',
      title: 'Calendar',
      description: 'View approved scheduled activities, event dates, and institutional timelines.'
    },
    {
      target: 'a.sidebar-nav-item[href*="admin2/users.php"]',
      title: 'User Management',
      description: 'Manage authorized faculty accounts and account permissions according to your role.'
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

  function registerAdmin2Tour() {
    if (typeof window.STIWalkthrough !== 'undefined') {
      window.STIWalkthrough.registerSteps('admin2', admin2Steps);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', registerAdmin2Tour);
  } else {
    registerAdmin2Tour();
  }
})();
