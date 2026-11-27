<?php
/**
 * api/generate-post-event-assessment.php
 *
 * Phase 3 Capstone Revision Endpoint:
 * Generates an AI-assisted Post-Event Assessment Report narrative,
 * summarizes participant comments & suggestions, and offers AI-generated
 * recommendations for corrections or improvements once final documents/metrics are provided.
 */
ob_start();
ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/PostEventAssessmentHelper.php';

requireLogin();
$user = currentUser();
$db   = getDB();

$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true) ?: [];
$params = array_merge($_GET, $_POST, $jsonInput);

$activityId = (int)($params['activity_id'] ?? 0);
if ($activityId <= 0) {
    if (ob_get_length()) ob_clean();
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Missing or invalid activity_id.']);
    exit;
}

if (!canUserAccessActivity($activityId, 'view', (int)$user['id'], $user['role'], $user['dept'] ?? '')) {
    if (ob_get_length()) ob_clean();
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Forbidden: You do not have permission to access this activity.']);
    exit;
}

$result = PostEventAssessmentHelper::generateAssessmentAndRecommendations($db, $activityId, $params);

if (ob_get_length()) ob_clean();
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success'               => true,
    'activity_id'           => $activityId,
    'comments_summary'      => $result['comments_summary'],
    'ai_recommendations'    => $result['ai_recommendations'],
    'ai_assessment_summary' => $result['ai_assessment_summary'],
    'approved_budget'       => $result['approved_budget'],
    'actual_expenses'       => $result['actual_expenses'],
    'budget_variance'       => $result['budget_variance'],
    'budget_status'         => $result['budget_status'],
    'budget_label'          => $result['budget_label'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
