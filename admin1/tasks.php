<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin1');
$role = 'admin1';
$themeClass = 'theme-arjay';
$viewActivityBase = BASE_URL . '/admin1/view-activity.php';
$allActivitiesBase = BASE_URL . '/admin1/activities.php';
$pageTitle = 'My Assigned Tasks';

require __DIR__ . '/../includes/my-tasks-template.php';

