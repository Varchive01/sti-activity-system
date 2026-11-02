<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty', 'program_head');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

// Read raw JSON input
$inputJSON = file_get_contents('php://input');
$input = !empty($inputJSON) ? json_decode($inputJSON, true) : null;
if (!is_array($input) && !empty($_POST)) {
    $input = $_POST;
}
if (!is_array($input) && php_sapi_name() === 'cli') {
    $cliInput = @stream_get_contents(STDIN);
    if (!empty($cliInput)) {
        $input = json_decode($cliInput, true);
    }
}

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid JSON input.'
    ]);
    exit;
}

$title = trim($input['title'] ?? '');
$theme = trim($input['theme'] ?? '');
$general_objectives = trim($input['general_objectives'] ?? '');
$specific_objectives = trim($input['specific_objectives'] ?? '');

// Strict validation: non-empty title (>=4 chars), theme, and at least one objective
if (empty($title) || strlen($title) < 4 || empty($theme) || (empty($general_objectives) && empty($specific_objectives))) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Activity title (at least 4 characters), theme, and at least one objective (general or specific) are required to generate evaluation questions.',
        'success' => false
    ]);
    exit;
}

// Build the prompt focused solely on Title, Theme, and Objectives
$prompt = "Act as an academic evaluation expert and systems analyst. Analyze the following college activity proposal details and generate a highly tailored activity-specific evaluation questionnaire/tool for participants.

ACTIVITY DETAILS:
- Title: " . $title . "
- Theme: " . $theme . "
- General Objective: " . ($general_objectives ?: 'None specified') . "
- Specific Objectives: " . ($specific_objectives ?: 'None specified') . "

CRITICAL GUIDELINES:
1. **Objective Alignment is Mandatory**: Evaluation questions must primarily measure whether the activity's General Objectives and Specific Objectives were actually achieved within the context of the title and theme.
2. **At Least One Question Per Goal**: For every General Objective and Specific Objective listed above, you MUST generate at least one specific, direct evaluation question that can gather concrete evidence of its achievement.
3. **No Generic/Boilerplate Questions**: Do not generate generic event questions (e.g., \"Did you find the topic interesting?\", \"How would you rate the event?\") merely because they relate to the topic. Every core question must align with a specific objective or the stated theme.
4. **Supplementary Questions Limit**: Questions assessing general satisfaction, facilitator performance, presentation quality, venue, or organization are strictly supplementary. They must NOT replace or dominate the objective-aligned questions.
5. **Appropriate Question Types**: Do not force every question to use the same type. Use exactly one of these two question types:
   - Use rating-scale questions (type: \"rating\") for structured, scalable feedback where quantitative metrics are helpful (e.g. agreement level or frequency).
   - Use open-ended text questions (type: \"open_ended\") for qualitative feedback, suggestions, detailed explanations, or descriptive evidence of achievement.
6. Generate a mix of exactly 6 to 10 rating-scale questions (type: \"rating\") and exactly 2 to 3 open-ended/text questions (type: \"open_ended\").
7. Output MUST be formatted as a valid JSON object matching the following structure without markdown formatting tags (no ```json):
{
  \"questions\": [
    {
      \"id\": \"q1\",
      \"question\": \"[Direct question measuring achievement of specific objective or theme]\",
      \"type\": \"rating\",
      \"required\": true
    },
    ...
  ]
}
";

// Call Gemini API
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
            $envFile = __DIR__ . '/../.env';
            if (file_exists($envFile)) {
                $envVars = @parse_ini_file($envFile);
                if (isset($envVars['GEMINI_API_KEY'])) {
                    $key = (string)$envVars['GEMINI_API_KEY'];
                }
            }
        }
        return trim((string)$key);
    }
}

$apiKey = getGeminiApiKeySecure();
if (empty($apiKey) || $apiKey === 'YOUR_API_KEY_HERE') {
    http_response_code(500);
    echo json_encode([
        'success'    => false,
        'error'      => 'AUTH_ERROR',
        'auth_error' => true,
        'message'    => 'Configuration Error: Gemini API key is missing. Please configure GEMINI_API_KEY in the environment or .env file.'
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

$modelsToTry = [
    'gemini-flash-lite-latest',
    'gemini-3.5-flash-lite',
    'gemini-3.1-flash-lite',
    'gemini-3.7-flash',
    'gemini-3.6-flash',
    'gemini-flash-latest',
    'gemini-3.5-flash'
];

// Windows/XAMPP CA certificate bundle resolution
$caBundle = null;
if (class_exists('GoogleOAuthService') && method_exists('GoogleOAuthService', 'resolveCaBundle')) {
    $caBundle = GoogleOAuthService::resolveCaBundle();
} elseif (file_exists('C:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem')) {
    $caBundle = 'C:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem';
} elseif (file_exists('C:/xampp/perl/vendor/lib/Mozilla/CA/cacert.pem')) {
    $caBundle = 'C:/xampp/perl/vendor/lib/Mozilla/CA/cacert.pem';
}

$response = '';
$httpCode = 0;
$curlError = '';
$success = false;

foreach ($modelsToTry as $modelName) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $modelName . ':generateContent?key=' . urlencode($apiKey);
    $maxAttempts = 2;
    $attempt = 0;
    
    while ($attempt < $maxAttempts) {
        $attempt++;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        if ($caBundle && file_exists($caBundle)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        @curl_close($ch);
        
        if (!$curlError && $httpCode === 200) {
            $success = true;
            break 2; // Break both loops, we are successful!
        }
        
        // If quota is exhausted (daily/tier limit), stop retrying this model
        if (strpos($response, 'RESOURCE_EXHAUSTED') !== false || strpos($response, 'Quota exceeded') !== false || strpos($response, 'quotaMetric') !== false) {
            error_log("Gemini API quota exhausted for model $modelName in generate-evaluation. Trying next model.");
            break;
        }

        // Bounded retry on temporary 503 or transient 429 rate limit
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
    
    error_log("Gemini API Model $modelName returned HTTP $httpCode. Trying fallback model...");
}

if ($curlError && !$success) {
    error_log("Gemini API cURL Error (generate-evaluation): " . $curlError);
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error'   => 'NETWORK_ERROR',
        'message' => 'Network error: Unable to connect to AI service. Please check your internet connection and try again.'
    ]);
    exit;
}

if ($httpCode !== 200) {
    error_log("Gemini API Error (HTTP $httpCode) in generate-evaluation: " . $response);
    $isQuotaExhausted = (strpos($response, 'RESOURCE_EXHAUSTED') !== false || strpos($response, 'Quota exceeded') !== false || strpos($response, 'quotaMetric') !== false);
    $isRateLimited = ($httpCode === 429);
    $isAuthError = ($httpCode === 400 && (strpos($response, 'API_KEY_INVALID') !== false || strpos($response, 'API key not valid') !== false)) || $httpCode === 401 || $httpCode === 403;

    $userMessage = 'Unable to generate evaluation questions right now. Please try again.';
    $errorCode = 'API Error: HTTP ' . $httpCode;
    $httpStatusOut = 502;
    if ($isAuthError) {
        $errorCode = 'AUTH_ERROR';
        $userMessage = 'Authentication Error: Gemini API key is invalid or unauthorized.';
        $httpStatusOut = 401;
    } elseif ($isQuotaExhausted) {
        $errorCode = 'QUOTA_EXHAUSTED';
        $userMessage = 'AI service quota has been reached. You can add and customize evaluation questions manually.';
        $httpStatusOut = 429;
    } elseif ($isRateLimited) {
        $errorCode = 'RATE_LIMIT';
        $userMessage = 'AI service rate limit reached. Please wait a moment and try again.';
        $httpStatusOut = 429;
    } elseif ($httpCode >= 500) {
        $errorCode = 'SERVER_ERROR';
        $userMessage = 'The AI service is temporarily unavailable. Please try again in a few moments.';
        $httpStatusOut = 503;
    }

    http_response_code($httpStatusOut);
    echo json_encode([
        'success'         => false,
        'error'           => $errorCode,
        'quota_exhausted' => $isQuotaExhausted,
        'rate_limited'    => $isRateLimited,
        'auth_error'      => $isAuthError,
        'message'         => $userMessage
    ]);
    exit;
}

$result = json_decode($response, true);

// Extract the generated text
$generatedText = '';
if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
    $generatedText = trim($result['candidates'][0]['content']['parts'][0]['text']);
    
    // 1. Direct or unfenced decode attempt
    $unfenced = preg_replace('/```(?:json)?\s*/i', '', $generatedText);
    $unfenced = trim(preg_replace('/\s*```/', '', $unfenced));
    $decoded = json_decode($unfenced, true);

    // 2. Outermost JSON extraction if direct decode failed
    if (!$decoded || !is_array($decoded)) {
        $firstBrace = strpos($generatedText, '{');
        $lastBrace = strrpos($generatedText, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $sub = substr($generatedText, $firstBrace, $lastBrace - $firstBrace + 1);
            $decoded = json_decode($sub, true);
            if (!$decoded) {
                // Strip trailing commas before closing braces/brackets
                $cleanSub = preg_replace('/,\s*([\]\}])/m', '$1', $sub);
                $decoded = json_decode($cleanSub, true);
            }
        }
    }

    // 3. Outermost array brackets extraction if Gemini returned [...] directly
    if (!$decoded || !is_array($decoded)) {
        $firstBracket = strpos($generatedText, '[');
        $lastBracket = strrpos($generatedText, ']');
        if ($firstBracket !== false && $lastBracket !== false && $lastBracket > $firstBracket) {
            $subArr = substr($generatedText, $firstBracket, $lastBracket - $firstBracket + 1);
            $decoded = json_decode($subArr, true);
            if (!$decoded) {
                $cleanSubArr = preg_replace('/,\s*([\]\}])/m', '$1', $subArr);
                $decoded = json_decode($cleanSubArr, true);
            }
        }
    }

    if (is_array($decoded)) {
        $questions = null;
        if (isset($decoded['questions']) && is_array($decoded['questions'])) {
            $questions = $decoded['questions'];
        } else if (is_numeric(key($decoded))) {
            $questions = $decoded;
        }

        if (is_array($questions) && !empty($questions)) {
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
