<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin2');
$role = 'admin2';
$themeClass = 'theme-ian';
$backUrl = BASE_URL . '/admin2/post-event.php';

require __DIR__ . '/../includes/post-event-view-template.php';

