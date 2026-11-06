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

requireRole('faculty', 'program_head');

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

if ($activityId > 0) {
    $user = currentUser();
    if (!canUserAccessActivity($activityId, 'view', $user['id'], $user['role'], $user['dept'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized access.']);
        exit;
    }
}

if (!$eventDate) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing required fields.',
        'details' => 'event_date is required.'
    ]);
    exit;
}

// Validate event_date format YYYY-MM-DD and calendar validity
$d = DateTime::createFromFormat('Y-m-d', $eventDate);
if (!$d || $d->format('Y-m-d') !== $eventDate) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid date format.',
        'details' => 'event_date must be in YYYY-MM-DD format.'
    ]);
    exit;
}

// If venue or times are omitted/empty, return an explicit preliminary date-only result.
// Never invent arbitrary defaults (e.g. Auditorium, 08:00, 17:00).
if ($venue === '' || $startTime === '' || $endTime === '') {
    echo json_encode([
        'conflict'               => false,
        'is_preliminary'         => true,
        'date_valid'             => true,
        'availability_confirmed' => false,
        'message'                => 'Date selected. Complete the venue and event time to check availability.'
    ]);
    exit;
}

$timeCheck = validateScheduleTimeRange($startTime, $endTime, true);
if (!$timeCheck['valid']) {
    echo json_encode([
        'conflict'               => true,
        'rule_based'             => true,
        'error_type'             => 'invalid_time_range',
        'error'                  => $timeCheck['error'],
        'message'                => $timeCheck['error'],
        'code'                   => $timeCheck['code'],
        'is_preliminary'         => false,
        'date_valid'             => true,
        'availability_confirmed' => false,
        'conflicting_activity'   => [
            'title'      => 'Invalid Time Interval',
            'event_date' => $eventDate,
            'start_time' => $startTime,
            'end_time'   => $endTime,
            'venue'      => $venue
        ],
        'alternatives'           => []
    ]);
    exit;
}

try {
    $result = checkScheduleConflict($venue, $eventDate, $startTime, $endTime, $activityId);
    $result['is_preliminary']         = false;
    $result['date_valid']             = true;
    $result['availability_confirmed'] = ($result['conflict'] === false);
    if (!isset($result['message'])) {
        $result['message'] = $result['conflict']
            ? 'Scheduling conflict detected for the selected venue and time.'
            : 'Schedule is available.';
    }
    echo json_encode($result);
    exit;
} catch (Exception $e) {
    error_log("Exception in check-schedule-conflict: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error occurred while checking schedule conflicts.'
    ]);
    exit;
}
