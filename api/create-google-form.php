<?php
// api/create-google-form.php

ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/GoogleFormsService.php';

header('Content-Type: application/json');

// Helper to send sanitized JSON responses
function sendJsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);

    // Security check: ensure no access tokens or client secrets are exposed in output
    $json = json_encode($payload);
    if (isset($_SESSION['access_token']) && is_string($_SESSION['access_token']) && strlen($_SESSION['access_token']) > 5) {
        $json = str_replace($_SESSION['access_token'], '[REDACTED]', $json);
    }
    echo $json;
    exit;
}

// 1. Require existing authentication/session mechanism
startSession();

if (empty($_SESSION['user_id'])) {
    sendJsonResponse(401, [
        'success' => false,
        'error'   => 'Authentication required.'
    ]);
}

if (defined('SESSION_TIMEOUT') && isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
    session_destroy();
    sendJsonResponse(401, [
        'success' => false,
        'error'   => 'Session expired. Please log in again.'
    ]);
}
$_SESSION['last_activity'] = time();

$user = currentUser();
if (empty($user['id'])) {
    sendJsonResponse(401, [
        'success' => false,
        'error'   => 'Authentication required.'
    ]);
}

// Check role authorization
$allowedRoles = ['faculty', 'dean', 'admin1', 'admin2'];
if (!in_array($user['role'], $allowedRoles, true)) {
    sendJsonResponse(403, [
        'success' => false,
        'error'   => 'Unauthorized access.'
    ]);
}

// 2. Accept activity_id (POST, raw JSON body, or GET)
$rawInput = file_get_contents('php://input');
$jsonData = !empty($rawInput) ? json_decode($rawInput, true) : null;
$activityId = (int)($_POST['activity_id'] ?? ($jsonData['activity_id'] ?? ($_GET['activity_id'] ?? 0)));

if ($activityId <= 0) {
    sendJsonResponse(400, [
        'success' => false,
        'error'   => 'Valid activity ID is required.'
    ]);
}

// Database instance (allow mock/test injection if provided)
$db = $GLOBALS['testDb'] ?? getDB();

// 3. Load that activity from the database
$stmt = $db->prepare("SELECT * FROM activities WHERE id = ?");
$stmt->execute([$activityId]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    sendJsonResponse(404, [
        'success' => false,
        'error'   => 'Activity not found.'
    ]);
}

// 4. Verify authenticated user is authorized to create/manage evaluation form for this activity
if ($user['role'] === 'faculty' && (int)$activity['faculty_id'] !== (int)$user['id']) {
    sendJsonResponse(403, [
        'success' => false,
        'error'   => 'You are not authorized to manage evaluation forms for this activity.'
    ]);
}

// 12. If database already has a Google Form URL, do not create duplicate
$existingEvaluationMethod = trim((string)($activity['evaluation_method'] ?? ''));
$isExistingGoogleForm = !empty($existingEvaluationMethod) && (
    str_contains($existingEvaluationMethod, 'forms.gle') ||
    str_contains($existingEvaluationMethod, 'docs.google.com/forms') ||
    str_contains($existingEvaluationMethod, 'forms.google.com')
);

if ($isExistingGoogleForm) {
    sendJsonResponse(409, [
        'success'       => false,
        'error'         => 'A Google Form has already been created for this activity.',
        'responder_uri' => $existingEvaluationMethod,
        'form_url'      => $existingEvaluationMethod
    ]);
}

// 5. Read and decode activities.evaluation_questions
$rawQuestions = $activity['evaluation_questions'] ?? null;
if (empty($rawQuestions) || !is_string($rawQuestions)) {
    sendJsonResponse(400, [
        'success' => false,
        'error'   => 'Evaluation questionnaire is missing for this activity.'
    ]);
}

$decodedQuestions = json_decode($rawQuestions, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($decodedQuestions)) {
    sendJsonResponse(400, [
        'success' => false,
        'error'   => 'Evaluation questionnaire contains invalid or malformed JSON data.'
    ]);
}

$hasQuestionsWrapper = false;
$questionsList = $decodedQuestions;
if (isset($decodedQuestions['questions']) && is_array($decodedQuestions['questions'])) {
    $questionsList = $decodedQuestions['questions'];
    $hasQuestionsWrapper = true;
}

// 6. Reject request safely if questionnaire is missing or invalid
if (empty($questionsList) || !is_array($questionsList)) {
    sendJsonResponse(400, [
        'success' => false,
        'error'   => 'Evaluation questionnaire has no questions.'
    ]);
}

// Build Google Forms batchUpdate requests from existing evaluation questions
$requests = [];
$locationIndex = 0;

foreach ($questionsList as $idx => $q) {
    if (!is_array($q)) {
        sendJsonResponse(400, [
            'success' => false,
            'error'   => "Evaluation question at position " . ($idx + 1) . " is invalid."
        ]);
    }

    $questionTitle = trim((string)($q['question'] ?? $q['title'] ?? ''));
    if ($questionTitle === '') {
        sendJsonResponse(400, [
            'success' => false,
            'error'   => "Evaluation question at position " . ($idx + 1) . " is missing question text."
        ]);
    }

    $rawType = strtolower(trim((string)($q['type'] ?? '')));
    $isRequired = isset($q['required']) ? (bool)$q['required'] : true;

    if ($rawType === 'rating') {
        $questionItem = [
            'question' => [
                'required'      => $isRequired,
                'scaleQuestion' => [
                    'low'  => 1,
                    'high' => 4,
                ],
            ],
        ];
    } elseif ($rawType === 'open_ended' || $rawType === 'text') {
        $questionItem = [
            'question' => [
                'required'     => $isRequired,
                'textQuestion' => [
                    'paragraph' => true,
                ],
            ],
        ];
    } else {
        sendJsonResponse(400, [
            'success' => false,
            'error'   => "Unsupported question type '{$rawType}' at position " . ($idx + 1) . "."
        ]);
    }

    // 10. Do not invent, rewrite, or regenerate questions
    $requests[] = [
        'createItem' => [
            'item'     => [
                'title'        => $questionTitle,
                'questionItem' => $questionItem,
            ],
            'location' => [
                'index' => $locationIndex++,
            ],
        ],
    ];
}

// 7. Use existing GoogleFormsService (allows test injection)
$formsService = $GLOBALS['testGoogleFormsService'] ?? new GoogleFormsService();

// 8. Create Google Form using activity title
$formTitle = trim((string)($activity['title'] ?? '')) ?: 'Activity Evaluation';
$createResult = $formsService->createForm($formTitle);

if (empty($createResult['success']) || empty($createResult['form_id'])) {
    $errorMsg = $createResult['error'] ?? 'Failed to create Google Form.';
    sendJsonResponse(500, [
        'success' => false,
        'error'   => $errorMsg
    ]);
}

$formId = $createResult['form_id'];
$responderUri = $createResult['responder_uri'] ?? '';
if (empty($responderUri)) {
    $responderUri = "https://docs.google.com/forms/d/e/{$formId}/viewform";
}

// 9. Use batchUpdate to add existing evaluation questions
$batchResult = $formsService->batchUpdate($formId, $requests);

if (empty($batchResult['success'])) {
    $errorMsg = $batchResult['error'] ?? 'Failed to populate evaluation questions in Google Form.';
    sendJsonResponse(500, [
        'success' => false,
        'error'   => $errorMsg
    ]);
}

// 10. Persist Google's assigned questionId & itemId alongside evaluation questions
if (!empty($batchResult['replies']) && is_array($batchResult['replies'])) {
    foreach ($questionsList as $idx => &$q) {
        $reply = $batchResult['replies'][$idx]['createItem'] ?? null;
        if (!$reply || !is_array($reply)) {
            continue;
        }

        $googleQId = null;
        if (isset($reply['questionId'])) {
            if (is_array($reply['questionId']) && !empty($reply['questionId'])) {
                $googleQId = (string)$reply['questionId'][0];
            } elseif (is_string($reply['questionId']) && $reply['questionId'] !== '') {
                $googleQId = $reply['questionId'];
            }
        }
        if ($googleQId === null && !empty($reply['itemId'])) {
            $googleQId = (string)$reply['itemId'];
        }

        if ($googleQId !== null) {
            $q['google_question_id'] = $googleQId;
        }
        if (!empty($reply['itemId'])) {
            $q['google_item_id'] = (string)$reply['itemId'];
        }
    }
    unset($q);

    if ($hasQuestionsWrapper) {
        $decodedQuestions['questions'] = $questionsList;
        $updatedQuestionsJson = json_encode($decodedQuestions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        $updatedQuestionsJson = json_encode($questionsList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $updateStmt = $db->prepare("UPDATE activities SET evaluation_method = ?, evaluation_questions = ?, updated_at = NOW() WHERE id = ?");
    $updateStmt->execute([$responderUri, $updatedQuestionsJson, $activityId]);
} else {
    // 11. Store returned Google Form responder URL in activities.evaluation_method field
    $updateStmt = $db->prepare("UPDATE activities SET evaluation_method = ?, updated_at = NOW() WHERE id = ?");
    $updateStmt->execute([$responderUri, $activityId]);
}

// Respond with success and non-sensitive information
sendJsonResponse(200, [
    'success'       => true,
    'form_id'       => $formId,
    'responder_uri' => $responderUri,
    'form_url'      => $responderUri,
    'questions_count' => count($requests),
    'message'       => 'Google Form created and evaluation questions populated successfully.'
]);
