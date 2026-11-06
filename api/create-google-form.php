<?php
// api/create-google-form.php

ob_start();
ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/GoogleFormsService.php';

// Helper to send sanitized JSON responses without buffer leaks
function sendJsonResponse(int $statusCode, array $payload): void
{
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

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
$allowedRoles = ['faculty', 'dean', 'admin1', 'admin2', 'program_head'];
if (!in_array($user['role'], $allowedRoles, true)) {
    sendJsonResponse(403, [
        'success' => false,
        'error'   => 'Unauthorized access.'
    ]);
}

// 2. Accept activity_id, title, and questions (POST, raw JSON body, or GET)
$rawInput = file_get_contents('php://input');
$jsonData = !empty($rawInput) ? json_decode($rawInput, true) : null;
$inputData = is_array($jsonData) ? $jsonData : $_POST;

$activityId = (int)($inputData['activity_id'] ?? ($_GET['activity_id'] ?? 0));
$formTitle  = trim((string)($inputData['title'] ?? ''));
$rawQuestionsInput = $inputData['questions'] ?? null;

// Database instance (allow mock/test injection if provided)
$db = $GLOBALS['testDb'] ?? getDB();
$activity = null;

// 3. If activity_id > 0, load that activity from the database and verify authorization
if ($activityId > 0) {
    $stmt = $db->prepare("SELECT * FROM activities WHERE id = ?");
    $stmt->execute([$activityId]);
    $activity = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activity) {
        sendJsonResponse(404, [
            'success' => false,
            'error'   => 'Activity not found.'
        ]);
    }

    // 4. Verify authenticated user is authorized to manage evaluation forms for this activity
    if (!canUserAccessActivity($activityId, 'modify', $user['id'], $user['role'], $user['dept'])) {
        sendJsonResponse(403, [
            'success' => false,
            'error'   => 'You are not authorized to manage evaluation forms for this activity.'
        ]);
    }

    if ($formTitle === '' && !empty($activity['title'])) {
        $formTitle = trim((string)$activity['title']);
    }

    // If database already has a Google Form URL and no updated questions were provided, do not recreate
    $existingEvaluationMethod = trim((string)($activity['evaluation_method'] ?? ''));
    $isExistingGoogleForm = !empty($existingEvaluationMethod) && (
        str_contains($existingEvaluationMethod, 'forms.gle') ||
        str_contains($existingEvaluationMethod, 'docs.google.com/forms') ||
        str_contains($existingEvaluationMethod, 'forms.google.com')
    );

    if ($isExistingGoogleForm && empty($rawQuestionsInput) && empty($inputData['force'])) {
        sendJsonResponse(409, [
            'success'       => false,
            'error'         => 'A Google Form has already been created for this activity.',
            'responder_uri' => $existingEvaluationMethod,
            'form_url'      => $existingEvaluationMethod
        ]);
    }
}

// 5. Read and decode evaluation questions (from payload if provided, or from DB if activityId > 0)
$decodedQuestions = null;
$hasQuestionsWrapper = false;
$questionsList = null;

if ($rawQuestionsInput !== null) {
    if (is_string($rawQuestionsInput)) {
        $decodedQuestions = json_decode($rawQuestionsInput, true);
    } elseif (is_array($rawQuestionsInput)) {
        $decodedQuestions = $rawQuestionsInput;
    }
    if (is_array($decodedQuestions)) {
        if (isset($decodedQuestions['questions']) && is_array($decodedQuestions['questions'])) {
            $questionsList = $decodedQuestions['questions'];
            $hasQuestionsWrapper = true;
        } else {
            $questionsList = $decodedQuestions;
        }
    }
}

// Fallback to database activities.evaluation_questions if questions were not in payload
if ($questionsList === null && $activity !== null) {
    $rawDbQuestions = $activity['evaluation_questions'] ?? null;
    if (!empty($rawDbQuestions) && is_string($rawDbQuestions)) {
        $decodedQuestions = json_decode($rawDbQuestions, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedQuestions)) {
            if (isset($decodedQuestions['questions']) && is_array($decodedQuestions['questions'])) {
                $questionsList = $decodedQuestions['questions'];
                $hasQuestionsWrapper = true;
            } else {
                $questionsList = $decodedQuestions;
            }
        } else {
            sendJsonResponse(400, [
                'success' => false,
                'error'   => 'Evaluation questionnaire contains invalid or malformed JSON data.'
            ]);
        }
    }
}

if ($questionsList === null) {
    sendJsonResponse(400, [
        'success' => false,
        'error'   => 'Evaluation questionnaire is missing for this activity.'
    ]);
}

// 6. Reject request safely if questionnaire is empty
if (empty($questionsList) || !is_array($questionsList)) {
    sendJsonResponse(400, [
        'success' => false,
        'error'   => 'Evaluation questionnaire has no questions.'
    ]);
}

// Automatically include "Section" question at the beginning of the form (Short answer, Required)
// Prevent duplicate Section questions if regenerated or if already present
$existingSectionQuestion = null;
$evaluationQuestions = [];

foreach ($questionsList as $idx => $q) {
    if (!is_array($q)) {
        sendJsonResponse(400, [
            'success' => false,
            'error'   => "Evaluation question at position " . ($idx + 1) . " is invalid."
        ]);
    }

    $qText = trim((string)($q['question'] ?? $q['title'] ?? ''));
    if ($qText === '') {
        sendJsonResponse(400, [
            'success' => false,
            'error'   => "Evaluation question at position " . ($idx + 1) . " is missing question text."
        ]);
    }

    if (strcasecmp($qText, 'Section') === 0) {
        if ($existingSectionQuestion === null) {
            $existingSectionQuestion = $q;
            $existingSectionQuestion['question'] = 'Section';
            $existingSectionQuestion['type'] = 'short_answer';
            $existingSectionQuestion['required'] = true;
        }
        // Deduplicate: drop any additional Section questions
        continue;
    }

    $evaluationQuestions[] = $q;
}

if ($existingSectionQuestion !== null) {
    $sectionItem = $existingSectionQuestion;
} else {
    $sectionItem = [
        'id'       => 'section_q',
        'question' => 'Section',
        'type'     => 'short_answer',
        'required' => true,
    ];
}

// Place the Section question near the beginning of the form, before the evaluation questions
$questionsList = array_merge([$sectionItem], $evaluationQuestions);

// Build Google Forms batchUpdate requests from evaluation questions
$requests = [];
$locationIndex = 0;

foreach ($questionsList as $idx => $q) {
    $questionTitle = trim((string)($q['question'] ?? $q['title'] ?? ''));
    $rawType = strtolower(trim((string)($q['type'] ?? '')));
    $isRequired = isset($q['required']) ? (bool)$q['required'] : true;

    if (strcasecmp($questionTitle, 'Section') === 0) {
        // Section question is always a required Short Answer question
        $questionItem = [
            'question' => [
                'required'     => true,
                'textQuestion' => [
                    'paragraph' => false,
                ],
            ],
        ];
    } elseif ($rawType === 'rating') {
        $questionItem = [
            'question' => [
                'required'      => $isRequired,
                'scaleQuestion' => [
                    'low'  => 1,
                    'high' => 4,
                ],
            ],
        ];
    } elseif ($rawType === 'short_answer' || $rawType === 'short-answer' || $rawType === 'short_text' || $rawType === 'short' || ($rawType === 'text' && isset($q['paragraph']) && !$q['paragraph'])) {
        $questionItem = [
            'question' => [
                'required'     => $isRequired,
                'textQuestion' => [
                    'paragraph' => false,
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

// 8. Create Google Form using title
if (empty($formTitle)) {
    $formTitle = !empty($activity['title']) ? trim((string)$activity['title']) : 'Activity Evaluation';
}
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

// 10. Persist Google's assigned questionId & itemId alongside evaluation questions (ONLY if activity exists in DB)
if ($activityId > 0) {
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
            $q['google_form_id'] = $formId;
        }
        unset($q);

        if ($hasQuestionsWrapper) {
            $decodedQuestions['questions'] = $questionsList;
            $decodedQuestions['google_form_id'] = $formId;
            $updatedQuestionsJson = json_encode($decodedQuestions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $updatedQuestionsJson = json_encode($questionsList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $updateStmt = $db->prepare("UPDATE activities SET evaluation_method = ?, google_form_link = ?, evaluation_questions = ?, updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$responderUri, $formId, $updatedQuestionsJson, $activityId]);
    } else {
        // Store returned Google Form responder URL in activities.evaluation_method and API form ID in google_form_link
        $updateStmt = $db->prepare("UPDATE activities SET evaluation_method = ?, google_form_link = ?, updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$responderUri, $formId, $activityId]);
    }

    // Retrieve fresh updated_at timestamp to maintain client concurrency synchronization
    $tsStmt = $db->prepare("SELECT updated_at FROM activities WHERE id = ?");
    $tsStmt->execute([$activityId]);
    $newUpdatedAt = $tsStmt->fetchColumn() ?: date('Y-m-d H:i:s');

    // Record contribution log for the current user so conflict detector recognizes the author
    if (function_exists('logProposalContribution') && !empty($user['id'])) {
        logProposalContribution((int)$activityId, (int)$user['id'], 'Updated Evaluation Method', 'Evaluation & KPI', 'Configured automated Google Form evaluation tool.', $db);
    }
}

// Respond with success and non-sensitive information
sendJsonResponse(200, [
    'success'         => true,
    'form_id'         => $formId,
    'responder_uri'   => $responderUri,
    'form_url'        => $responderUri,
    'updated_at'      => $newUpdatedAt ?? null,
    'questions_count' => count($requests),
    'message'         => 'Google Form created successfully! Link populated.'
]);
