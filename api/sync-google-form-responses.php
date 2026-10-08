<?php
// api/sync-google-form-responses.php

ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/GoogleOAuthService.php';

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

header('Content-Type: application/json');

/**
 * GoogleFormsResponseService
 *
 * Lightweight service extension to fetch Google Forms participant responses
 * via Google Forms REST API v1.
 */
class GoogleFormsResponseService
{
    private GoogleOAuthService $oauthService;
    private ClientInterface $httpClient;

    public function __construct(?GoogleOAuthService $oauth = null, ?ClientInterface $client = null)
    {
        $this->oauthService = $oauth ?? new GoogleOAuthService();
        $this->httpClient = $client ?? new Client(['timeout' => 20.0, 'http_errors' => false]);
    }

    /**
     * Retrieves participant responses for a given Google Form.
     */
    public function getResponses(string $formId): array
    {
        $formId = trim($formId);
        if (empty($formId)) {
            return [
                'success' => false,
                'error'   => 'Form ID is required to retrieve responses.',
            ];
        }

        $tokenResult = $this->oauthService->getAccessToken();
        if (empty($tokenResult['success']) || empty($tokenResult['access_token'])) {
            return [
                'success' => false,
                'error'   => $tokenResult['error'] ?? 'Failed to obtain Google OAuth access token.',
            ];
        }

        $accessToken = $tokenResult['access_token'];
        $url = 'https://forms.googleapis.com/v1/forms/' . rawurlencode($formId) . '/responses';

        try {
            $response = $this->httpClient->request('GET', $url, [
                'http_errors' => false,
                'headers'     => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept'        => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string)$response->getBody();
            $data = json_decode($body, true);

            if ($statusCode < 200 || $statusCode >= 300 || !is_array($data)) {
                $errorMsg = '';
                if (is_array($data) && !empty($data['error'])) {
                    $rawMsg = is_array($data['error']) ? ($data['error']['message'] ?? $data['error']['status'] ?? '') : $data['error'];
                    $errorMsg = is_string($rawMsg) ? preg_replace('/[^\w\s\.\-:,]/', '', $rawMsg) : '';
                }
                if (empty($errorMsg)) {
                    $errorMsg = "Google Forms API request failed with HTTP {$statusCode}.";
                }

                $safeError = str_replace($accessToken, '[REDACTED]', $errorMsg);
                return [
                    'success' => false,
                    'error'   => $safeError,
                ];
            }

            return [
                'success'   => true,
                'responses' => $data['responses'] ?? [],
                'data'      => $data,
            ];
        } catch (\Throwable $e) {
            error_log('GoogleFormsResponseService error: ' . $e->getMessage());
            return [
                'success' => false,
                'error'   => 'Network or communication error while communicating with Google Forms API.',
            ];
        }
    }
}

// Helper to send sanitized JSON responses
function sendSyncJsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    $json = json_encode($payload);
    if (isset($_SESSION['access_token']) && is_string($_SESSION['access_token']) && strlen($_SESSION['access_token']) > 5) {
        $json = str_replace($_SESSION['access_token'], '[REDACTED]', $json);
    }
    echo $json;
    exit;
}

// Helper to extract Form ID from responder URL or identifier
function extractFormId(?string $url): ?string
{
    if (empty($url)) {
        return null;
    }
    $url = trim($url);

    // Standard Google Forms URL patterns
    if (preg_match('#/forms/d/(?:e/)?([a-zA-Z0-9_\-]+)#', $url, $m)) {
        return $m[1];
    }

    // Direct alphanumeric ID
    if (preg_match('/^[a-zA-Z0-9_\-]{15,}$/', $url)) {
        return $url;
    }

    return null;
}

// Helper to safely extract answer text value from Google Form answer structure
function parseAnswerValue(mixed $answer): ?string
{
    if (is_string($answer) || is_numeric($answer)) {
        return trim((string)$answer);
    }
    if (is_array($answer)) {
        if (isset($answer['textAnswers']['answers'][0]['value'])) {
            return trim((string)$answer['textAnswers']['answers'][0]['value']);
        }
        if (isset($answer['value'])) {
            return trim((string)$answer['value']);
        }
        if (isset($answer['answers'][0]['value'])) {
            return trim((string)$answer['answers'][0]['value']);
        }
        if (isset($answer[0]['value'])) {
            return trim((string)$answer[0]['value']);
        }
    }
    return null;
}

// 1. Require authentication & session
startSession();

if (empty($_SESSION['user_id'])) {
    sendSyncJsonResponse(401, [
        'success' => false,
        'error'   => 'Authentication required.',
    ]);
}

$user = currentUser();
if (empty($user['id'])) {
    sendSyncJsonResponse(401, [
        'success' => false,
        'error'   => 'Authentication required.',
    ]);
}

// Check role authorization
$allowedRoles = ['faculty', 'dean', 'admin1', 'admin2'];
if (!in_array($user['role'], $allowedRoles, true)) {
    sendSyncJsonResponse(403, [
        'success' => false,
        'error'   => 'Unauthorized access.',
    ]);
}

// 2. Accept activity_id
$rawInput = file_get_contents('php://input');
$jsonData = !empty($rawInput) ? json_decode($rawInput, true) : null;
$activityId = (int)($_POST['activity_id'] ?? ($jsonData['activity_id'] ?? ($_GET['activity_id'] ?? 0)));

if ($activityId <= 0) {
    sendSyncJsonResponse(400, [
        'success' => false,
        'error'   => 'Valid activity ID is required.',
    ]);
}

// 3. Load activity from database
$db = $GLOBALS['testDb'] ?? getDB();
$stmt = $db->prepare("SELECT * FROM activities WHERE id = ?");
$stmt->execute([$activityId]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    sendSyncJsonResponse(404, [
        'success' => false,
        'error'   => 'Activity not found.',
    ]);
}

// Verify authorized user
if ($user['role'] === 'faculty' && (int)$activity['faculty_id'] !== (int)$user['id']) {
    sendSyncJsonResponse(403, [
        'success' => false,
        'error'   => 'You are not authorized to sync evaluations for this activity.',
    ]);
}

// 4. Obtain Google Form ID
$formId = extractFormId($activity['evaluation_method'] ?? '');
if (!$formId) {
    $fallbackFormId = trim((string)($_POST['form_id'] ?? ($jsonData['form_id'] ?? '')));
    if (!empty($fallbackFormId)) {
        $formId = extractFormId($fallbackFormId);
    }
}

if (empty($formId)) {
    sendSyncJsonResponse(400, [
        'success' => false,
        'error'   => 'No Google Form is associated with this activity.',
    ]);
}

// Load and decode activities.evaluation_questions
$rawQuestions = $activity['evaluation_questions'] ?? null;
if (empty($rawQuestions) || !is_string($rawQuestions)) {
    sendSyncJsonResponse(400, [
        'success' => false,
        'error'   => 'Evaluation questionnaire is missing for this activity.',
    ]);
}

$decodedQuestions = json_decode($rawQuestions, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($decodedQuestions)) {
    sendSyncJsonResponse(400, [
        'success' => false,
        'error'   => 'Evaluation questionnaire contains invalid or malformed data.',
    ]);
}

$questionsList = $decodedQuestions;
if (isset($decodedQuestions['questions']) && is_array($decodedQuestions['questions'])) {
    $questionsList = $decodedQuestions['questions'];
}

if (empty($questionsList) || !is_array($questionsList)) {
    sendSyncJsonResponse(400, [
        'success' => false,
        'error'   => 'Evaluation questionnaire has no questions.',
    ]);
}

// 5 & 6. Retrieve participant responses from Google Form
$syncService = $GLOBALS['testGoogleFormsResponseService'] ?? new GoogleFormsResponseService(
    $GLOBALS['testOAuthService'] ?? null,
    $GLOBALS['testHttpClient'] ?? null
);
$fetchResult = $syncService->getResponses($formId);

if (empty($fetchResult['success'])) {
    $errorMsg = $fetchResult['error'] ?? 'Failed to retrieve responses from Google Forms.';
    sendSyncJsonResponse(500, [
        'success' => false,
        'error'   => $errorMsg,
    ]);
}

$rawResponses = $fetchResult['responses'] ?? [];
$totalResponses = count($rawResponses);

if ($totalResponses === 0) {
    sendSyncJsonResponse(200, [
        'success'         => true,
        'synced_count'    => 0,
        'skipped_count'   => 0,
        'total_responses' => 0,
        'message'         => 'No responses available to synchronize.',
    ]);
}

// 7, 8, 9. Map answers, ensure idempotency, and store valid records
$syncedRecordsCount = 0;
$skippedDuplicatesCount = 0;
$malformedAnswersCount = 0;

// Prepare check statement for idempotency
$checkStmt = $db->prepare("
    SELECT id FROM kpi_evaluations 
    WHERE activity_id = ? AND criteria = ? AND evaluator_name = ?
    LIMIT 1
");

// Prepare insert statement
$insertStmt = $db->prepare("
    INSERT INTO kpi_evaluations (activity_id, criteria, rating, comments, evaluator_name, evaluated_at)
    VALUES (?, ?, ?, ?, ?, ?)
");

foreach ($rawResponses as $respIdx => $resp) {
    if (!is_array($resp)) {
        continue;
    }

    $responseId = trim((string)($resp['responseId'] ?? ('resp_' . $respIdx)));
    $respondentEmail = trim((string)($resp['respondentEmail'] ?? ''));
    $evaluatorBase = !empty($respondentEmail) ? $respondentEmail : 'Participant';
    $evaluatorIdentifier = substr("{$evaluatorBase} [GF:{$responseId}]", 0, 150);

    // Timestamp
    $rawTime = $resp['lastSubmittedTime'] ?? ($resp['createTime'] ?? null);
    $evaluatedAt = $rawTime ? date('Y-m-d H:i:s', strtotime($rawTime)) : date('Y-m-d H:i:s');

    $answers = $resp['answers'] ?? [];

    foreach ($questionsList as $qIdx => $q) {
        if (!is_array($q)) {
            continue;
        }

        $criteria = trim((string)($q['question'] ?? ($q['title'] ?? '')));
        if ($criteria === '') {
            continue;
        }

        $qId = trim((string)($q['id'] ?? ''));
        $googleQId = trim((string)($q['google_question_id'] ?? ($q['question_id'] ?? '')));
        $googleItemId = trim((string)($q['google_item_id'] ?? ''));
        $qType = strtolower(trim((string)($q['type'] ?? 'rating')));

        // Find answer in response answers map
        $rawAnswer = null;

        // 1. Primary: Match using persisted Google question ID or item ID
        if ($googleQId !== '' && isset($answers[$googleQId])) {
            $rawAnswer = $answers[$googleQId];
        } elseif ($googleItemId !== '' && isset($answers[$googleItemId])) {
            $rawAnswer = $answers[$googleItemId];
        } elseif ($googleQId !== '') {
            foreach ($answers as $ansKey => $ansObj) {
                if (is_array($ansObj) && isset($ansObj['questionId']) && (string)$ansObj['questionId'] === $googleQId) {
                    $rawAnswer = $ansObj;
                    break;
                }
            }
        }

        // 2. Backward compatibility: Match using local question ID (e.g. q1, q2) or criteria text
        if ($rawAnswer === null && $qId !== '' && isset($answers[$qId])) {
            $rawAnswer = $answers[$qId];
        } elseif ($rawAnswer === null && isset($answers[$criteria])) {
            $rawAnswer = $answers[$criteria];
        } elseif ($rawAnswer === null && isset($answers[$qIdx])) {
            $rawAnswer = $answers[$qIdx];
        } elseif ($rawAnswer === null && $qId !== '') {
            // Search through answers for matching questionId
            foreach ($answers as $ansKey => $ansObj) {
                if (is_array($ansObj) && isset($ansObj['questionId']) && (string)$ansObj['questionId'] === $qId) {
                    $rawAnswer = $ansObj;
                    break;
                }
            }
        }

        if ($rawAnswer === null) {
            continue; // Question unanswered by respondent
        }

        $answerVal = parseAnswerValue($rawAnswer);
        if ($answerVal === null || $answerVal === '') {
            continue;
        }

        $rating = null;
        $comments = null;

        if ($qType === 'rating') {
            if (!is_numeric($answerVal)) {
                $malformedAnswersCount++;
                continue;
            }
            $ratingInt = (int)$answerVal;
            if ($ratingInt < 1 || $ratingInt > 4) {
                $malformedAnswersCount++;
                continue;
            }
            $rating = $ratingInt;
        } else {
            // Open-ended / text
            $comments = $answerVal;
        }

        // 9. Idempotency check: verify record doesn't already exist
        $checkStmt->execute([$activityId, $criteria, $evaluatorIdentifier]);
        $existingId = $checkStmt->fetchColumn();

        if ($existingId) {
            $skippedDuplicatesCount++;
            continue;
        }

        // 8. Store valid response without deleting existing records
        $insertStmt->execute([
            $activityId,
            $criteria,
            $rating,
            $comments,
            $evaluatorIdentifier,
            $evaluatedAt,
        ]);

        $syncedRecordsCount++;
    }
}

sendSyncJsonResponse(200, [
    'success'           => true,
    'total_responses'   => $totalResponses,
    'synced_count'      => $syncedRecordsCount,
    'skipped_count'     => $skippedDuplicatesCount,
    'malformed_count'   => $malformedAnswersCount,
    'message'           => "Synchronized {$syncedRecordsCount} response items ({$skippedDuplicatesCount} existing items skipped).",
]);
