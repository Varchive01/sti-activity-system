<?php
/**
 * schedule_conflict.php
 *
 * Hardcoded, Rule-Based Schedule Conflict Detection Module.
 * Completely independent of Google Gemini API or any external AI services (100% offline).
 *
 * Conflict Detection Rules:
 * 1. Venue Matching: Compares normalized venue names (case-insensitive, trimmed).
 * 2. Date Matching: Compares activity date (YYYY-MM-DD).
 * 3. Exact Mathematical Time Overlap:
 *    Two activities in the same venue on the same date conflict IF AND ONLY IF:
 *    (proposed_start < existing_end) AND (proposed_end > existing_start).
 *    Activities on the same date with NON-OVERLAPPING times (e.g. 08:00–12:00 vs 13:00–17:00)
 *    are NOT conflicts and are fully permitted.
 * 4. Time Interval Integrity:
 *    End Time must be strictly later than Start Time.
 * 5. Rule-Based Alternative Slot Generation:
 *    Calculates available time windows deterministically across campus venues and adjacent days.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../functions.php';

/**
 * Checks if a proposed activity schedule conflicts with any approved or scheduled activity.
 * 100% deterministic rule-based evaluation.
 *
 * @param string $proposedVenue
 * @param string $proposedDate      YYYY-MM-DD
 * @param string $proposedStartTime HH:MM or HH:MM:SS
 * @param string $proposedEndTime   HH:MM or HH:MM:SS
 * @param int    $excludeActivityId Used in edit mode to prevent conflict with itself
 * @return array
 */
function checkScheduleConflict(
    string $proposedVenue,
    string $proposedDate,
    string $proposedStartTime,
    string $proposedEndTime,
    int $excludeActivityId = 0
): array {
    $db = getDB();

    $proposedVenue = trim($proposedVenue);
    $proposedDate  = trim($proposedDate);
    $proposedStart = trim($proposedStartTime);
    $proposedEnd   = trim($proposedEndTime);

    // Rule 1: Validate required fields
    if ($proposedVenue === '' || $proposedDate === '' || $proposedStart === '' || $proposedEnd === '') {
        return [
            'conflict'       => false,
            'is_preliminary' => true,
            'message'        => 'Required scheduling fields are incomplete.'
        ];
    }

    // Rule 2: Validate start time vs end time
    $sMins = function_exists('parseTimeToMinutes') ? parseTimeToMinutes($proposedStart) : null;
    $eMins = function_exists('parseTimeToMinutes') ? parseTimeToMinutes($proposedEnd) : null;

    if ($sMins === null || $eMins === null || $eMins <= $sMins) {
        return [
            'conflict'     => true,
            'error_type'   => 'invalid_time_range',
            'message'      => 'End Time must be strictly later than Start Time.',
            'conflicting_activity' => [
                'title'      => 'Invalid Time Sequence',
                'event_date' => $proposedDate,
                'start_time' => $proposedStart,
                'end_time'   => $proposedEnd,
                'venue'      => $proposedVenue
            ],
            'alternatives' => []
        ];
    }

    // Rule 3: Query database for conflicting approved activities using the same venue on the same date
    $hasArchived = function_exists('hasActivityArchiveColumn') ? hasActivityArchiveColumn($db) : false;
    $archivedCond = $hasArchived ? " AND (is_archived IS NULL OR is_archived = 0)" : "";

    $sql = "SELECT id, title, event_date, start_time, end_time, venue 
            FROM activities 
            WHERE status = 'approved'
              AND TRIM(LOWER(venue)) = TRIM(LOWER(:venue))
              AND event_date = :event_date
              AND start_time < :proposed_end
              AND end_time > :proposed_start
              AND id != :exclude_id
              {$archivedCond}
            ORDER BY start_time ASC
            LIMIT 1";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':venue'          => $proposedVenue,
        ':event_date'     => $proposedDate,
        ':proposed_start' => $proposedStart,
        ':proposed_end'   => $proposedEnd,
        ':exclude_id'     => $excludeActivityId
    ]);

    $conflict = $stmt->fetch(PDO::FETCH_ASSOC);

    // If no time overlap in this venue on this date, there is NO conflict
    if (!$conflict) {
        return [
            'conflict'               => false,
            'is_preliminary'         => false,
            'availability_confirmed' => true,
            'message'                => 'Schedule is available.'
        ];
    }

    // Rule 4: If conflict detected, find deterministic alternative slots
    $alternatives = getDeterministicAlternativeSlots(
        $db,
        $proposedVenue,
        $proposedDate,
        $proposedStart,
        $proposedEnd,
        $excludeActivityId
    );

    return [
        'conflict'             => true,
        'rule_based'           => true,
        'conflicting_activity' => [
            'title'      => $conflict['title'],
            'event_date' => $conflict['event_date'],
            'start_time' => date('g:i A', strtotime($conflict['start_time'])),
            'end_time'   => date('g:i A', strtotime($conflict['end_time'])),
            'venue'      => $conflict['venue']
        ],
        'alternatives'         => $alternatives,
        'message'              => sprintf(
            'Schedule conflict: Venue "%s" is already booked by "%s" from %s to %s on %s.',
            $conflict['venue'],
            $conflict['title'],
            date('g:i A', strtotime($conflict['start_time'])),
            date('g:i A', strtotime($conflict['end_time'])),
            date('F j, Y', strtotime($conflict['event_date']))
        )
    ];
}

/**
 * Deterministic rule-based alternative slot generator.
 * Zero external API calls. Computes open slots based on school operating guidelines.
 *
 * @param PDO    $db
 * @param string $venue
 * @param string $date
 * @param string $startTime
 * @param string $endTime
 * @param int    $excludeActivityId
 * @return array
 */
function getDeterministicAlternativeSlots(
    PDO $db,
    string $venue,
    string $date,
    string $startTime,
    string $endTime,
    int $excludeActivityId
): array {
    $alternatives = [];
    $hasArchived = function_exists('hasActivityArchiveColumn') ? hasActivityArchiveColumn($db) : false;
    $archivedCond = $hasArchived ? " AND (is_archived IS NULL OR is_archived = 0)" : "";

    $commonVenues = ['Gymnasium', 'Auditorium', 'AVR', 'STI College Marikina Campus'];

    // Strategy 1: Other campus venues on the exact same date and time
    foreach ($commonVenues as $v) {
        if (trim(strtolower($v)) === trim(strtolower($venue))) {
            continue;
        }

        $stmtCheck = $db->prepare("
            SELECT COUNT(*) 
            FROM activities 
            WHERE status = 'approved'
              AND TRIM(LOWER(venue)) = TRIM(LOWER(:venue))
              AND event_date = :event_date
              AND start_time < :end_time
              AND end_time > :start_time
              AND id != :exclude_id
              {$archivedCond}
        ");
        $stmtCheck->execute([
            ':venue'      => $v,
            ':event_date' => $date,
            ':start_time' => $startTime,
            ':end_time'   => $endTime,
            ':exclude_id' => $excludeActivityId
        ]);

        if ((int)$stmtCheck->fetchColumn() === 0) {
            $alternatives[] = [
                'date'       => $date,
                'start_time' => date('H:i', strtotime($startTime)),
                'end_time'   => date('H:i', strtotime($endTime)),
                'venue'      => $v,
                'reason'     => 'Available at ' . $v . ' on the same date and time'
            ];
            if (count($alternatives) >= 3) {
                return $alternatives;
            }
        }
    }

    // Strategy 2: Shifted time windows on the same date and same venue
    $shifts = [7200, -7200, 10800, -10800, 14400]; // 2h, -2h, 3h, -3h, 4h
    $duration = strtotime($endTime) - strtotime($startTime);

    foreach ($shifts as $shift) {
        $candStart = date('H:i', strtotime($startTime) + $shift);
        $candEnd   = date('H:i', strtotime($candStart) + $duration);

        // Ensure reasonable campus operating hours (08:00 to 18:00)
        if (strtotime($candStart) < strtotime('08:00') || strtotime($candEnd) > strtotime('18:00') || $candStart >= $candEnd) {
            continue;
        }

        $stmtCheck = $db->prepare("
            SELECT COUNT(*) 
            FROM activities 
            WHERE status = 'approved'
              AND TRIM(LOWER(venue)) = TRIM(LOWER(:venue))
              AND event_date = :event_date
              AND start_time < :end_time
              AND end_time > :start_time
              AND id != :exclude_id
              {$archivedCond}
        ");
        $stmtCheck->execute([
            ':venue'      => $venue,
            ':event_date' => $date,
            ':start_time' => $candStart,
            ':end_time'   => $candEnd,
            ':exclude_id' => $excludeActivityId
        ]);

        if ((int)$stmtCheck->fetchColumn() === 0) {
            $alternatives[] = [
                'date'       => $date,
                'start_time' => $candStart,
                'end_time'   => $candEnd,
                'venue'      => $venue,
                'reason'     => 'Available time slot on the same date at ' . $venue
            ];
            if (count($alternatives) >= 3) {
                return $alternatives;
            }
        }
    }

    // Strategy 3: Next consecutive open weekdays (+1 to +7 days, excluding Sunday)
    for ($i = 1; $i <= 7; $i++) {
        $candDate = date('Y-m-d', strtotime($date . " +{$i} days"));
        if (date('N', strtotime($candDate)) === '7') { // Skip Sunday maintenance
            continue;
        }

        $stmtCheck = $db->prepare("
            SELECT COUNT(*) 
            FROM activities 
            WHERE status = 'approved'
              AND TRIM(LOWER(venue)) = TRIM(LOWER(:venue))
              AND event_date = :event_date
              AND start_time < :end_time
              AND end_time > :start_time
              AND id != :exclude_id
              {$archivedCond}
        ");
        $stmtCheck->execute([
            ':venue'      => $venue,
            ':event_date' => $candDate,
            ':start_time' => $startTime,
            ':end_time'   => $endTime,
            ':exclude_id' => $excludeActivityId
        ]);

        if ((int)$stmtCheck->fetchColumn() === 0) {
            $alternatives[] = [
                'date'       => $candDate,
                'start_time' => date('H:i', strtotime($startTime)),
                'end_time'   => date('H:i', strtotime($endTime)),
                'venue'      => $venue,
                'reason'     => 'Available on ' . date('l, M j', strtotime($candDate)) . ' at ' . $venue
            ];
            if (count($alternatives) >= 3) {
                break;
            }
        }
    }

    return $alternatives;
}
