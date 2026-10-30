<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin2');
$role = 'admin2';
$themeClass = 'theme-ian';
$viewActivityBase = BASE_URL . '/admin2/view-activity.php';
$allActivitiesBase = BASE_URL . '/admin2/activities.php';
$pageTitle = 'My Assigned Tasks';

require __DIR__ . '/../includes/my-tasks-template.php';

