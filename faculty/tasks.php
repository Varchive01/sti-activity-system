<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('faculty');
$role = 'faculty';
$themeClass = 'theme-faculty';
$viewActivityBase = BASE_URL . '/faculty/view-activity.php';
$allActivitiesBase = BASE_URL . '/faculty/activities.php';
$pageTitle = 'My Assigned Tasks';

require __DIR__ . '/../includes/my-tasks-template.php';
