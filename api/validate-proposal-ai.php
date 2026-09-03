<?php
/**
 * validate-proposal-ai.php
 *
 * REST JSON API endpoint for Activity Proposal validation using Google Gemini API.
 * First performs standard PHP validation on required fields. If valid, runs AI analysis.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/GeminiProposalValidationService.php';

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

// Required fields to validate before sending to Gemini API
$requiredFields = [
    'title'               => 'Activity Title',
    'event_date'          => 'Event Date',
    'start_time'          => 'Start Time',
    'end_time'            => 'End Time',
    'venue'               => 'Venue',
    'target_participants' => 'Target Participants',
    'theme'               => 'Theme',
    'general_objectives'  => 'General Objectives',
    'specific_objectives' => 'Specific Objectives',
    'involved_subjects'   => 'Involved Subjects',
    'rationale'           => 'Rationale'
];

$missingFields = [];
foreach ($requiredFields as $fieldKey => $fieldLabel) {
    $val = trim((string)($input[$fieldKey] ?? ''));
    if ($val === '' || ($fieldKey === 'target_participants' && (int)$val <= 0)) {
        $missingFields[] = $fieldLabel;
    }
}

if (!empty($missingFields)) {
    echo json_encode([
        'success'           => false,
        'validation_passed' => false,
        'error'             => 'Please complete all required fields before submitting for AI Proposal Validation.',
        'missing_fields'    => $missingFields
    ]);
    exit;
}

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    $service = new GeminiProposalValidationService($apiKey);

    // Compute canonical payload and hash
    $canonical = $service->getCanonicalProposalPayload($input);
    $dataHash = $service->calculateProposalHash($canonical);

    $aiValidation = $service->validateProposal($input);

    // Cache the result in server session indexed by the calculated hash
    if (!isset($_SESSION['last_ai_validation'])) {
        $_SESSION['last_ai_validation'] = [];
    }
    $_SESSION['last_ai_validation'][$dataHash] = [
        'result'    => $aiValidation,
        'timestamp' => time()
    ];

    echo json_encode([
        'success'           => true,
        'validation_passed' => true,
        'data_hash'         => $dataHash,
        'ai_validation'     => $aiValidation
    ]);
    exit;

} catch (Exception $e) {
    error_log("AI Proposal Validation Exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success'           => false,
        'validation_passed' => false,
        'error'             => 'An internal server error occurred while analyzing the proposal.'
    ]);
    exit;
}
