<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin1');
$role = 'admin1';
$themeClass = 'theme-arjay';
$backUrl = BASE_URL . '/admin1/post-event.php';

require __DIR__ . '/../includes/post-event-view-template.php';

