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
$general_objectives = $input['general_objectives'] ?? '';
$specific_objectives = $input['specific_objectives'] ?? '';
$kpis = $input['kpis'] ?? [];

// Format KPIs for the prompt
$kpisFormatted = '';
if (is_array($kpis) && !empty($kpis)) {
    foreach ($kpis as $idx => $kpi) {
        $kpisFormatted .= "\n- Criterion " . ($idx + 1) . ": " . ($kpi['criteria'] ?? $kpi['indicator'] ?? '') . " (Target: " . ($kpi['rating'] ?? $kpi['target'] ?? '3') . ")";
    }
} else {
    $kpisFormatted = 'None provided.';
}

// Build the prompt
$prompt = "Act as an academic evaluation expert and systems analyst. Analyze the following college activity proposal details and generate a highly tailored activity-specific evaluation questionnaire/tool for participants.

ACTIVITY DETAILS:
- Title: " . $title . "
- Source/Organizer: " . $source . "
- Target Participants: " . $target_participants . "
- Theme: " . $theme . "
- Rationale: " . $rationale . "
- Involved Subjects: " . $involved_subjects . "
- General Objective: " . $general_objectives . "
- Specific Objectives: " . $specific_objectives . "
- KPIs (Key Performance Indicators): " . $kpisFormatted . "

CRITICAL GUIDELINES:
1. **Objective & KPI Alignment is Mandatory**: Evaluation questions must primarily measure whether the activity's General Objectives, Specific Objectives, and KPIs/Success Indicators were actually achieved.
2. **At Least One Question Per Goal**: For every single General Objective, Specific Objective, and KPI listed above, you MUST generate at least one specific, direct evaluation question that can gather concrete evidence of its achievement.
3. **No Generic/Boilerplate Questions**: Do not generate generic event questions (e.g., \"Did you find the topic interesting?\", \"How would you rate the event?\") merely because they relate to the topic. Every core question must align with a specific objective or KPI.
4. **Supplementary Questions Limit**: Questions assessing general satisfaction, facilitator performance, presentation quality, venue, or organization are strictly supplementary. They must NOT replace or dominate the objective/KPI-aligned questions.
5. **Appropriate Question Types**: Do not force every question to use the same type. Use exactly one of these two question types:
   - Use rating-scale questions (type: \"rating\") for structured, scalable feedback where quantitative metrics are helpful (e.g. agreement level or frequency).
   - Use open-ended text questions (type: \"open_ended\") for qualitative feedback, suggestions, detailed explanations, or descriptive evidence of achievement.
6. Generate a mix of exactly 6 to 10 rating-scale questions (type: \"rating\") and exactly 2 to 3 open-ended/text questions (type: \"open_ended\").
7. Output MUST be formatted as a valid JSON object matching the following structure without markdown formatting tags (no ```json):
{
  \"questions\": [
    {
      \"id\": \"q1\",
      \"question\": \"[Direct question measuring achievement of specific objective or KPI]\",
      \"type\": \"rating\",
      \"required\": true
    },
    ...
  ]
}
";

// Call Gemini API
$apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
if (empty($apiKey) || $apiKey === 'YOUR_API_KEY_HERE') {
    echo json_encode([
        'error' => 'Configuration Error: Gemini API key is missing.',
        'details' => 'Please configure the GEMINI_API_KEY in the .env file.'
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
            break 2; // Break both loops, we are successful!
        }
        
        // Retry on 503 or 429
        if ($httpCode === 503 || $httpCode === 429 || strpos($response, 'UNAVAILABLE') !== false) {
            if ($attempt < $maxAttempts) {
                $sleepSeconds = 2;
                error_log("Gemini API Model $modelName returned HTTP $httpCode. Attempt $attempt/$maxAttempts. Retrying in {$sleepSeconds}s...");
                sleep($sleepSeconds);
                continue;
            }
        }
        break; // For other errors (like 400/403/404), try next model directly
    }
    
    error_log("Gemini API Model $modelName failed with HTTP $httpCode. Trying fallback model...");
}

if ($curlError) {
    error_log("Gemini API cURL Error (generate-evaluation): " . $curlError);
    echo json_encode(['error' => 'cURL Error: ' . $curlError]);
    exit;
}

if ($httpCode !== 200) {
    error_log("Gemini API Error (HTTP $httpCode) in generate-evaluation: " . $response);
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
    $generatedText = preg_replace('/```json\s*/i', '', $generatedText);
    $generatedText = preg_replace('/```\s*/', '', $generatedText);

    $decoded = json_decode($generatedText, true);

    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $questions = null;
        if (isset($decoded['questions']) && is_array($decoded['questions'])) {
            $questions = $decoded['questions'];
        } else if (is_numeric(key($decoded))) {
            $questions = $decoded;
        }

        if (is_array($questions)) {
            echo json_encode([
                'success' => true,
                'questions' => $questions
            ]);
            exit;
        }
    }
}

error_log("Failed to parse evaluation questions. Raw response: " . $generatedText);
echo json_encode([
    'error' => 'Failed to parse the generated evaluation questions from Gemini API.',
    'raw_response' => $generatedText
]);
