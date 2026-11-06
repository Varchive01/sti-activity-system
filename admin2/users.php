<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin2');

$themeClass  = 'theme-ian';
$currentRole = 'admin2';
$pageTitle   = 'User Management – Events Committee Head';

require_once __DIR__ . '/../includes/user-management-view.php';
