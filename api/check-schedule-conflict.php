<?php
/**
 * check-schedule-conflict.php
 *
 * REST JSON API endpoint for scheduling conflict checks.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/schedule_conflict.php';

requireRole('faculty');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

// Support both JSON body and POST form data
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);
if (!is_array($input)) {
    $input = $_POST;
}

$venue      = trim((string)($input['venue'] ?? ''));
$eventDate  = trim((string)($input['event_date'] ?? ''));
$startTime  = trim((string)($input['start_time'] ?? ''));
$endTime    = trim((string)($input['end_time'] ?? ''));
$activityId = (int)($input['activity_id'] ?? 0);

if (!$venue || !$eventDate || !$startTime || !$endTime) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing required fields.',
        'details' => 'venue, event_date, start_time, and end_time are required.'
    ]);
    exit;
}

$timeCheck = validateScheduleTimeRange($startTime, $endTime, true);
if (!$timeCheck['valid']) {
    http_response_code(400);
    echo json_encode([
        'error' => $timeCheck['error'],
        'code'  => $timeCheck['code']
    ]);
    exit;
}

try {
    $result = checkScheduleConflict($venue, $eventDate, $startTime, $endTime, $activityId);
    echo json_encode($result);
    exit;
} catch (Exception $e) {
    error_log("Exception in check-schedule-conflict: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error occurred.',
        'details' => $e->getMessage()
    ]);
    exit;
}
