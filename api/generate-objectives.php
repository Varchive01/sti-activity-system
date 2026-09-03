<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

// Read raw JSON input
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

if (!$input) {
    echo json_encode(['error' => 'Invalid JSON input.']);
    exit;
}

$title = $input['title'] ?? '';
$source = $input['source'] ?? '';
$target_participants = $input['target_participants'] ?? '';
$theme = $input['theme'] ?? '';
$involved_subjects = $input['involved_subjects'] ?? '';
$rationale = $input['rationale'] ?? '';

// Build the prompt
$prompt = "Act as an academic advisor and systems analyst. Analyze the following college activity proposal details and generate 1 General Objective and 3 to 5 Specific Objectives.

ACTIVITY DETAILS:
- Title: " . $title . "
- Organizer/Source: " . $source . "
- Target Participants: " . $target_participants . "
- Theme: " . $theme . "
- Involved Subjects: " . $involved_subjects . "
- Rationale: " . $rationale . "

GUIDELINES:
1. The General Objective should be a concise, overarching goal of the activity.
2. The Specific Objectives (3-5 items) must be SMART (Specific, Measurable, Achievable, Relevant, Time-bound) where possible and use action verbs.
3. Align with the activity details and academic context. Do NOT invent important details not provided.
4. Output MUST be formatted as a valid JSON object exactly as follows, with no extra markdown (no ```json):

{
  \"general_objective\": \"The general objective text...\",
  \"specific_objectives\": \"1. First specific objective...\\n2. Second specific objective...\\n3. Third specific objective...\"
}";

// Call Gemini API
$apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
if (empty($apiKey) || $apiKey === 'YOUR_API_KEY_HERE') {
    echo json_encode([
        'error' => 'Configuration Error: Gemini API key is missing.',
        'details' => 'Please configure the GEMINI_API_KEY. You can do this by creating a .env file in the root directory (sti-activity-system/.env) containing the line: GEMINI_API_KEY="your_actual_api_key_here", or by setting it as a server environment variable.'
    ]);
    exit;
}

$data = [
    'contents' => [
        [
            'parts' => [
                ['text' => $prompt]
            ]
        ]
    ]
];

$modelsToTry = ['gemini-flash-latest', 'gemini-3.6-flash'];
$response = '';
$httpCode = 0;
$curlError = '';
$success = false;

foreach ($modelsToTry as $modelName) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $modelName . ':generateContent?key=' . $apiKey;
    $maxAttempts = 2;
    $attempt = 0;
    
    while ($attempt < $maxAttempts) {
        $attempt++;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 40);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        @curl_close($ch);
        
        if (!$curlError && $httpCode === 200) {
            $success = true;
            break 2;
        }
        
        // Retry on 503 or 429
        if ($httpCode === 503 || $httpCode === 429 || strpos($response, 'UNAVAILABLE') !== false) {
            if ($attempt < $maxAttempts) {
                $sleepSeconds = 2;
                error_log("Gemini API Model $modelName returned HTTP $httpCode in generate-objectives. Attempt $attempt/$maxAttempts. Retrying in {$sleepSeconds}s...");
                sleep($sleepSeconds);
                continue;
            }
        }
        break;
    }
    
    error_log("Gemini API Model $modelName failed in generate-objectives. Trying fallback model...");
}

if ($curlError) {
    error_log("Gemini API cURL Error (generate-objectives): " . $curlError);
    echo json_encode(['error' => 'cURL Error: ' . $curlError]);
    exit;
}

if ($httpCode !== 200) {
    error_log("Gemini API Error (HTTP $httpCode) in generate-objectives: " . $response);
    echo json_encode([
        'error' => 'API Error: HTTP ' . $httpCode,
        'details' => 'Google API Response: ' . $response
    ]);
    exit;
}

$result = json_decode($response, true);

// Extract the generated text
$generatedText = '';
if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
    $generatedText = trim($result['candidates'][0]['content']['parts'][0]['text']);
    
    // Strip markdown formatting if any
    $generatedText = preg_replace('/```json\s*/', '', $generatedText);
    $generatedText = preg_replace('/```\s*/', '', $generatedText);

    $objectives = json_decode($generatedText, true);

    if ($objectives && isset($objectives['general_objective']) && isset($objectives['specific_objectives'])) {
        echo json_encode([
            'success' => true,
            'general_objective' => $objectives['general_objective'],
            'specific_objectives' => $objectives['specific_objectives']
        ]);
        exit;
    }
}

echo json_encode([
    'error' => 'Failed to parse the generated objectives from Gemini API.',
    'raw_response' => $generatedText
]);
