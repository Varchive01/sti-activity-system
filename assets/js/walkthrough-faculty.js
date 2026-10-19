/**
 * Faculty-specific Guided Walkthrough Steps
 *
 * Registered into window.STIWalkthrough for the Faculty role.
 * Covers primary navigation and core workspace elements in the Faculty portal.
 */

(function () {
  'use strict';

  const facultySteps = [
    {
      target: 'aside.sidebar',
      title: 'Faculty Navigation',
      description: 'The sidebar provides fast access to all key areas of your Faculty portal and activities.'
    },
    {
      target: 'a.sidebar-nav-item[href*="faculty/dashboard.php"]',
      title: 'Faculty Dashboard',
      description: 'The dashboard summarizes your active activities, proposal status, and core metrics at a glance.'
    },
    {
      target: 'a[href*="proposal-create.php"]',
      title: 'New Activity Proposal',
      description: 'This is where you create and submit a new activity proposal for institutional review.'
    },
    {
      target: '.dashboard-main .card',
      title: 'Recent Activities',
      description: 'Quickly monitor your recent activity records, update logs, and current approval statuses.'
    },
    {
      target: 'a.sidebar-nav-item[href*="faculty/activities.php"]',
      title: 'My Activities',
      description: 'Access and track your complete list of activity records, drafts, and past submissions.'
    },
    {
      target: 'a.sidebar-nav-item[href*="faculty/calendar.php"]',
      title: 'Faculty Calendar',
      description: 'Keep track of scheduled institutional activities, deadlines, and upcoming event dates.'
    },
    {
      target: '#notifBellBtn',
      title: 'Workflow Notifications',
      description: 'Stay informed with real-time workflow updates, reminders, and proposal alerts.'
    },
    {
      target: '#topbarProfile',
      title: 'Account & Profile',
      description: 'Access profile settings, change your password, update picture, and log out.'
    },
    {
      target: '#sidebar-help-btn',
      title: 'Guided Help',
      description: 'Click Help anytime to reopen this guided walkthrough whenever you need guidance.'
    }
  ];

  function registerFacultyTour() {
    if (typeof window.STIWalkthrough !== 'undefined') {
      window.STIWalkthrough.registerSteps('faculty', facultySteps);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', registerFacultyTour);
  } else {
    registerFacultyTour();
  }
})();
