<?php
/**
 * schedule_conflict.php
 *
 * Hybrid schedule conflict detection module using deterministic database checks
 * and Google Gemini API for suggestion of alternative dates/times.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

if (!function_exists('getGeminiApiKeySecure')) {
    function getGeminiApiKeySecure(): string
    {
        $key = getenv('GEMINI_API_KEY');
        if (!$key && defined('GEMINI_API_KEY')) {
            $key = GEMINI_API_KEY;
        }
        if (!$key && isset($_ENV['GEMINI_API_KEY'])) {
            $key = $_ENV['GEMINI_API_KEY'];
        }
        if (!$key && isset($_SERVER['GEMINI_API_KEY'])) {
            $key = $_SERVER['GEMINI_API_KEY'];
        }
        if (!$key) {
            $envFile = __DIR__ . '/../../.env';
            if (file_exists($envFile)) {
                $envVars = parse_ini_file($envFile);
                if (isset($envVars['GEMINI_API_KEY'])) {
                    $key = (string)$envVars['GEMINI_API_KEY'];
                }
            }
        }
        return trim((string)$key);
    }
}

/**
 * Checks if a proposed date/time/venue overlaps with any fully approved activity.
 * If yes, calls Gemini to suggest 3 verified alternative open slots.
 *
 * @param string $proposedVenue
 * @param string $proposedDate      YYYY-MM-DD
 * @param string $proposedStartTime HH:MM
 * @param string $proposedEndTime   HH:MM
 * @param int    $excludeActivityId Used in edit mode to prevent conflict with itself
 * @return array
 */
function checkScheduleConflict(string $proposedVenue, string $proposedDate, string $proposedStartTime, string $proposedEndTime, int $excludeActivityId = 0): array
{
    $db = getDB();

    $proposedVenue = trim($proposedVenue);
    $proposedDate  = trim($proposedDate);
    $proposedStart = trim($proposedStartTime);
    $proposedEnd   = trim($proposedEndTime);

    // 1. Factual Conflict Check (Database)
    // Overlap formula: start_time < proposed_end AND end_time > proposed_start
    $sql = "SELECT id, title, event_date, start_time, end_time, venue 
            FROM activities 
            WHERE status = 'approved'
              AND TRIM(LOWER(venue)) = TRIM(LOWER(:venue))
              AND event_date = :event_date
              AND start_time < :proposed_end
              AND end_time > :proposed_start
              AND id != :exclude_id
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

    if (!$conflict) {
        return [
            'conflict'     => false
        ];
    }

    // 2. Fetch all approved activities at all venues for the next 14 days to construct context
    $startDate = $proposedDate;
    $endDate   = date('Y-m-d', strtotime($proposedDate . ' +14 days'));

    $sqlBookings = "SELECT id, title, event_date, start_time, end_time, venue 
                    FROM activities 
                    WHERE status = 'approved'
                      AND event_date BETWEEN :start_date AND :end_date
                      AND id != :exclude_id";

    $stmtBookings = $db->prepare($sqlBookings);
    $stmtBookings->execute([
        ':start_date' => $startDate,
        ':end_date'   => $endDate,
        ':exclude_id' => $excludeActivityId
    ]);

    $bookings = $stmtBookings->fetchAll(PDO::FETCH_ASSOC);

    // Format bookings list for the prompt
    $bookingsText = '';
    foreach ($bookings as $b) {
        $bookingsText .= "- \"{$b['title']}\" at venue \"{$b['venue']}\" on {$b['event_date']} from {$b['start_time']} to {$b['end_time']}\n";
    }
    if (empty($bookingsText)) {
        $bookingsText = "No other approved bookings found in this 14-day window.\n";
    }

    $duration = (strtotime($proposedEnd) - strtotime($proposedStart)) / 60; // duration in minutes

    // 3. Construct Gemini Prompt
    $prompt = "You are an AI scheduling assistant for STI College Marikina.
A scheduling conflict was detected for a proposed activity:
- Venue: {$proposedVenue}
- Desired Date: {$proposedDate}
- Desired Time: {$proposedStart} to {$proposedEnd} (Duration: {$duration} minutes)

Here is a list of ALL existing approved bookings at this venue and other school venues from {$startDate} to {$endDate}:
{$bookingsText}

Suggest 3 alternative available slots for this activity over the next 14 days starting from {$proposedDate}.
Rules:
1. The alternative slots must NOT overlap with any of the existing approved bookings listed above.
2. Prioritize same-date alternatives:
   - First, check if there is an alternative time slot on the SAME date (at the same venue) that is open.
   - Second, check if the SAME date/time is available at a DIFFERENT venue (choose from 'Gymnasium', 'Auditorium', 'AVR', or 'STI College Marikina Campus').
   - Only suggest a different date if there are no available options on the same date.
3. The alternative slots must be on a weekday (Monday to Saturday; Sunday is a maintenance day and closed).
4. The slots should ideally be within reasonable hours (e.g. 08:00 to 18:00).
5. Each suggested slot must have the exact same duration of {$duration} minutes.

Format your response as a valid JSON object matching this schema exactly:
{
  \"alternatives\": [
    {
      \"date\": \"YYYY-MM-DD\",
      \"start_time\": \"HH:MM\",
      \"end_time\": \"HH:MM\",
      \"venue\": \"Name of the venue (e.g. Gymnasium, Auditorium, AVR, or STI College Marikina Campus)\",
      \"reason\": \"Reason why this is a good alternative\"
    }
  ]
}
Do not include any markdown styling, backticks, or text before/after the JSON response.";

    // 4. Invoke Gemini API
    $apiKey = getGeminiApiKeySecure();
    $suggestedAlternatives = [];

    if (!empty($apiKey)) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=' . $apiKey;
        $postData = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_TIMEOUT, 8); // 8 second timeout

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        @curl_close($ch);

        if ($httpCode === 200 && $response) {
            $respData = json_decode($response, true);
            $rawText = $respData['candidates'][0]['content']['parts'][0]['text'] ?? '';
            // Strip markdown JSON wrappers if any
            $cleanedJson = preg_replace('/^```(?:json)?\s*|```\s*$/i', '', trim($rawText));
            $jsonParsed = json_decode($cleanedJson, true);

            if (isset($jsonParsed['alternatives']) && is_array($jsonParsed['alternatives'])) {
                $suggestedAlternatives = $jsonParsed['alternatives'];
            }
        }
    }

    // 5. Deterministic PHP/MySQL Verification of Gemini Suggestions
    $verifiedAlternatives = [];
    foreach ($suggestedAlternatives as $alt) {
        $altDate  = trim($alt['date'] ?? '');
        $altStart = trim($alt['start_time'] ?? '');
        $altEnd   = trim($alt['end_time'] ?? '');
        $altVenue = trim($alt['venue'] ?? $proposedVenue);

        if (!$altDate || !$altStart || !$altEnd || !$altVenue) {
            continue;
        }

        // Validate formats
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $altDate) || 
            !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $altStart) || 
            !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $altEnd)) {
            continue;
        }

        // Verify if Sunday
        if (date('N', strtotime($altDate)) === '7') {
            continue;
        }

        // Factual check in DB for this alternative
        $stmtCheck = $db->prepare("
            SELECT COUNT(*) 
            FROM activities 
            WHERE status = 'approved'
              AND TRIM(LOWER(venue)) = TRIM(LOWER(:venue))
              AND event_date = :event_date
              AND start_time < :end_time
              AND end_time > :start_time
              AND id != :exclude_id
        ");
        $stmtCheck->execute([
            ':venue'      => $altVenue,
            ':event_date' => $altDate,
            ':start_time' => $altStart,
            ':end_time'   => $altEnd,
            ':exclude_id' => $excludeActivityId
        ]);
        $isOverlap = (int)$stmtCheck->fetchColumn() > 0;

        if (!$isOverlap) {
            $verifiedAlternatives[] = [
                'date'       => $altDate,
                'start_time' => date('H:i', strtotime($altStart)),
                'end_time'   => date('H:i', strtotime($altEnd)),
                'venue'      => htmlspecialchars($altVenue),
                'reason'     => htmlspecialchars($alt['reason'] ?? 'Available slot')
            ];
            if (count($verifiedAlternatives) >= 3) {
                break;
            }
        }
    }

    // If Gemini failed or didn't return valid verified slots, generate fallback deterministic slots
    if (empty($verifiedAlternatives)) {
        $verifiedAlternatives = getDeterministicFallbackSlots($db, $proposedVenue, $proposedDate, $proposedStart, $proposedEnd, $excludeActivityId);
    }

    return [
        'conflict'            => true,
        'conflicting_activity' => [
            'title'      => $conflict['title'],
            'event_date' => $conflict['event_date'],
            'start_time' => date('g:i A', strtotime($conflict['start_time'])),
            'end_time'   => date('g:i A', strtotime($conflict['end_time'])),
            'venue'      => $conflict['venue']
        ],
        'alternatives'        => $verifiedAlternatives
    ];
}

/**
 * Deterministic schedule search fallback if Gemini suggestions fail verification or Gemini is offline.
 */
function getDeterministicFallbackSlots(PDO $db, string $venue, string $date, string $startTime, string $endTime, int $excludeActivityId): array
{
    $fallbacks = [];
    $duration = strtotime($endTime) - strtotime($startTime);

    $commonVenues = ['Gymnasium', 'Auditorium', 'AVR', 'STI College Marikina Campus'];

    // 1. Try other venues on the SAME date/time
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
        ");
        $stmtCheck->execute([
            ':venue'      => $v,
            ':event_date' => $date,
            ':start_time' => $startTime,
            ':end_time'   => $endTime,
            ':exclude_id' => $excludeActivityId
        ]);
        $isOverlap = (int)$stmtCheck->fetchColumn() > 0;

        if (!$isOverlap) {
            $fallbacks[] = [
                'date'       => $date,
                'start_time' => date('H:i', strtotime($startTime)),
                'end_time'   => date('H:i', strtotime($endTime)),
                'venue'      => $v,
                'reason'     => 'Available at ' . $v . ' on the same date/time'
            ];
            if (count($fallbacks) >= 3) {
                return $fallbacks;
            }
        }
    }

    // 2. Try other times on the SAME date/venue (e.g. shift forward by 1h, 2h, 3h, or backward by 1h, 2h, 3h)
    $shifts = [3600, -3600, 7200, -7200, 10800, -10800];
    foreach ($shifts as $shift) {
        $candStart = date('H:i', strtotime($startTime) + $shift);
        $candEnd = date('H:i', strtotime($endTime) + $shift);

        // Ensure reasonable hours (08:00 to 18:00)
        if (strtotime($candStart) < strtotime('08:00') || strtotime($candEnd) > 18 * 3600 + strtotime('00:00') || $candStart >= $candEnd) {
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
        ");
        $stmtCheck->execute([
            ':venue'      => $venue,
            ':event_date' => $date,
            ':start_time' => $candStart,
            ':end_time'   => $candEnd,
            ':exclude_id' => $excludeActivityId
        ]);
        $isOverlap = (int)$stmtCheck->fetchColumn() > 0;

        if (!$isOverlap) {
            $fallbacks[] = [
                'date'       => $date,
                'start_time' => $candStart,
                'end_time'   => $candEnd,
                'venue'      => $venue,
                'reason'     => 'Shifted time slot on the same date'
            ];
            if (count($fallbacks) >= 3) {
                return $fallbacks;
            }
        }
    }

    // 3. Try +1 day, +2 days, etc.
    for ($i = 1; $i <= 7; $i++) {
        $candDate = date('Y-m-d', strtotime($date . " +{$i} days"));
        if (date('N', strtotime($candDate)) === '7') {
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
        ");
        $stmtCheck->execute([
            ':venue'      => $venue,
            ':event_date' => $candDate,
            ':start_time' => $startTime,
            ':end_time'   => $endTime,
            ':exclude_id' => $excludeActivityId
        ]);
        $isOverlap = (int)$stmtCheck->fetchColumn() > 0;

        if (!$isOverlap) {
            $fallbacks[] = [
                'date'       => $candDate,
                'start_time' => date('H:i', strtotime($startTime)),
                'end_time'   => date('H:i', strtotime($endTime)),
                'venue'      => $venue,
                'reason'     => 'Deterministic scheduling shift (+ ' . $i . ' days)'
            ];
            if (count($fallbacks) >= 3) {
                break;
            }
        }
    }
    return $fallbacks;
}
