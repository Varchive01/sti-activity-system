<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('dean');
$role = 'dean';
$themeClass = 'theme-dean';
$backUrl = BASE_URL . '/dean/post-event.php';

require __DIR__ . '/../includes/post-event-view-template.php';

