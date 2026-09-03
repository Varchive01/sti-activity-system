<?php
/**
 * run_inst_test.php
 *
 * Mocks authentication and calls generate-institutional-analytics.php REST API via CLI.
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
$year = (int)($argv[2] ?? 2026);

$_POST = [
    'year' => $year,
    'period' => 'all',
    'action' => $action
];

// Display errors in testing
ini_set('display_errors', '1');
error_reporting(E_ALL);

include __DIR__ . '/../api/generate-institutional-analytics.php';
