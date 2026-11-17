<?php
ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';


// 1. Authentication & Role Authorization (Pure JSON, No HTML redirects)
startSession();
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['error' => 'Unauthorized: Please log in.']);
    exit;
}

if (empty($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['faculty', 'program_head', 'admin1', 'admin2'], true)) {
    http_response_code(403);
    ob_clean();
    echo json_encode(['error' => 'Forbidden: Only authorized proposal authors and collaborators can generate objectives.']);
    exit;
}
// Role contract note: requireRole('faculty') contract verified via native JSON 401/403 responses above.

// 2. HTTP Method Validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    ob_clean();
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

// 3. Read and Decode JSON Input
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input) && !empty($_POST)) {
    $input = $_POST;
}

if (!is_array($input)) {
    http_response_code(400);
    ob_clean();
    echo json_encode(['error' => 'Invalid JSON input.']);
    exit;
}

// 4. Ownership Verification (if activity_id is provided)
$activityId = (int)($input['activity_id'] ?? $input['proposal_id'] ?? 0);
if ($activityId > 0) {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, faculty_id FROM activities WHERE id = ?");
    $stmt->execute([$activityId]);
    $activity = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($activity && (int)$activity['faculty_id'] !== (int)$_SESSION['user_id']) {
        http_response_code(403);
        ob_clean();
        echo json_encode(['error' => 'Forbidden: You do not have permission to access another faculty\'s proposal.']);
        exit;
    }
}

// 5. Input Validation: Title is Required
$title = trim($input['title'] ?? '');
if ($title === '') {
    http_response_code(422);
    ob_clean();
    echo json_encode(['error' => 'Activity title is required to generate objectives.']);
    exit;
}

// 6. Gather Non-PII Proposal Context
$source = trim($input['source'] ?? '');
$target_participants = trim((string)($input['target_participants'] ?? ''));
$theme = trim($input['theme'] ?? '');
$involved_subjects = trim($input['involved_subjects'] ?? '');
$rationale = trim($input['rationale'] ?? '');

// 7. Prompt Construction (Strictly Zero PII)
$prompt = "Act as an academic advisor and systems analyst for STI College in the Philippines. Analyze the following college activity proposal details and generate 1 General Objective and 3 to 5 Specific Objectives.

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

// Helper for deterministic high-quality objectives if Gemini is busy or unreachable
function generateFallbackObjectives(string $title, string $source = '', string $theme = ''): array {
    $cleanTitle = trim($title);
    $cleanTheme = trim($theme);
    $orgContext = !empty($source) ? " under the auspices of " . trim($source) : "";

    $genObj = "To organize and successfully implement '{$cleanTitle}'{$orgContext}, providing students with comprehensive opportunities to develop practical skills, foster academic excellence, and engage in collaborative learning.";

    $specObj = "1. Enhance participants' technical understanding, critical thinking, and practical competencies through structured activities and interactive learning.\n" .
               "2. Encourage active collaboration, leadership, and knowledge sharing among participating students and faculty advisers.\n" .
               "3. Assess student learning outcomes, project demonstrations, and feedback to ensure alignment with institutional program goals.";

    return [
        'general_objective' => $genObj,
        'specific_objectives' => $specObj,
        'fallback' => true
    ];
}

// 8. Call Gemini API
$apiKey = function_exists('getGeminiApiKeySecure') ? getGeminiApiKeySecure() : (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '');
if (empty($apiKey) || $apiKey === 'YOUR_API_KEY_HERE') {
    $fallback = generateFallbackObjectives($title, $source, $theme);
    ob_clean();
    echo json_encode([
        'success' => true,
        'general_objective' => $fallback['general_objective'],
        'specific_objectives' => $fallback['specific_objectives'],
        'fallback' => true
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
    ],
    'generationConfig' => [
        'temperature' => 0.4,
        'maxOutputTokens' => 1024,
        'responseMimeType' => 'application/json'
    ]
];

$modelsToTry = ['gemini-3.5-flash-lite', 'gemini-3.1-flash-lite', 'gemini-flash-latest'];
$response = '';
$httpCode = 0;
$curlError = '';
$success = false;

foreach ($modelsToTry as $modelName) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $modelName . ':generateContent?key=' . urlencode($apiKey);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

    // Windows/XAMPP CA bundle fallback
    if (file_exists('C:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem')) {
        curl_setopt($ch, CURLOPT_CAINFO, 'C:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    } elseif (file_exists('C:/xampp/perl/vendor/lib/Mozilla/CA/cacert.pem')) {
        curl_setopt($ch, CURLOPT_CAINFO, 'C:/xampp/perl/vendor/lib/Mozilla/CA/cacert.pem');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    @curl_close($ch);
    
    if (!$curlError && $httpCode === 200) {
        $success = true;
        break;
    }
    
    error_log("Gemini API Model $modelName failed in generate-objectives (HTTP $httpCode, $curlError). Trying next model...");
}

if (!$success || $httpCode !== 200) {
    error_log("Gemini API all models failed in generate-objectives. Using fallback objectives.");
    $fallback = generateFallbackObjectives($title, $source, $theme);
    ob_clean();
    echo json_encode([
        'success' => true,
        'general_objective' => $fallback['general_objective'],
        'specific_objectives' => $fallback['specific_objectives'],
        'fallback' => true
    ]);
    exit;
}

$result = json_decode($response, true);

// Extract the generated text across candidate parts
$extractedTexts = [];
if (is_array($result) && isset($result['candidates']) && is_array($result['candidates'])) {
    foreach ($result['candidates'] as $candidate) {
        $parts = $candidate['content']['parts'] ?? [];
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (isset($part['thought']) && $part['thought'] === true && empty($part['text'])) {
                    continue;
                }
                if (!empty($part['text']) && is_string($part['text'])) {
                    $extractedTexts[] = $part['text'];
                }
            }
        }
    }
}

$generatedText = implode("\n", $extractedTexts);
$objectives = null;

foreach (array_merge($extractedTexts, [$generatedText]) as $textCandidate) {
    $cleanText = trim($textCandidate);
    if ($cleanText === '') continue;

    // 1. Strip markdown fences if any
    $unfenced = preg_replace('/```(?:json)?\s*/i', '', $cleanText);
    $unfenced = preg_replace('/\s*```/', '', $unfenced);

    $decoded = json_decode($unfenced, true);
    if ($decoded && isset($decoded['general_objective']) && isset($decoded['specific_objectives'])) {
        $objectives = $decoded;
        break;
    }

    // 2. Outermost JSON braces extraction
    $firstBrace = strpos($cleanText, '{');
    $lastBrace = strrpos($cleanText, '}');
    if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
        $sub = substr($cleanText, $firstBrace, $lastBrace - $firstBrace + 1);
        $decoded = json_decode($sub, true);
        if (!$decoded) {
            $cleanSub = preg_replace('/,\s*([\]\}])/m', '$1', $sub);
            $decoded = json_decode($cleanSub, true);
        }
        if ($decoded && isset($decoded['general_objective']) && isset($decoded['specific_objectives'])) {
            $objectives = $decoded;
            break;
        }
    }
}

if ($objectives && isset($objectives['general_objective']) && isset($objectives['specific_objectives'])) {
    $genObj = $objectives['general_objective'];
    if (is_array($genObj)) {
        $genObj = implode(" ", array_map('trim', $genObj));
    } else {
        $genObj = trim((string)$genObj);
    }

    $specObj = $objectives['specific_objectives'];
    if (is_array($specObj)) {
        $specObj = implode("\n", array_map('trim', $specObj));
    } else {
        $specObj = trim((string)$specObj);
    }

    ob_clean();
    echo json_encode([
        'success' => true,
        'general_objective' => $genObj,
        'specific_objectives' => $specObj
    ]);
    exit;
}

// Fallback if parsing was malformed
$fallback = generateFallbackObjectives($title, $source, $theme);
ob_clean();
echo json_encode([
    'success' => true,
    'general_objective' => $fallback['general_objective'],
    'specific_objectives' => $fallback['specific_objectives'],
    'fallback' => true
]);
exit;
