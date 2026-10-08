<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('dean');

$themeClass  = 'theme-dean';
$currentRole = 'dean';
$pageTitle   = 'User Management – Dean';

require_once __DIR__ . '/../includes/user-management-view.php';
