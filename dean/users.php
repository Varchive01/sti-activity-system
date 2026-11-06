<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('dean');

// User Management is exclusively assigned to Admin 2 (System Administrator).
header('Location: ' . BASE_URL . '/dean/dashboard.php?error=' . urlencode('User Management and role assignments are restricted exclusively to the System Administrator (Admin 2).'));
exit;
