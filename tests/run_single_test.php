<?php
/**
 * run_single_test.php
 *
 * Mocks authentication and calls generate-kpi-analytics.php REST API via CLI.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_id'] = 4; // Dean
$_SESSION['user_role'] = 'dean';
$_SESSION['user_name'] = 'Frederic Yulo';
$_SESSION['user_email'] = 'dean@sti.edu';
$_SESSION['user_dept'] = 'Administration';
$_SESSION['last_activity'] = time();
define('SESSION_TIMEOUT', 3600);

$action = $argv[1] ?? 'check';
$activityId = (int)($argv[2] ?? 118);

$_POST = [
    'activity_id' => $activityId,
    'action' => $action
];

// Display errors in testing
ini_set('display_errors', '1');
error_reporting(E_ALL);

include __DIR__ . '/../api/generate-kpi-analytics.php';
