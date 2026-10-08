<?php
ob_start();
/**
 * api/generate-theme.php
 *
 * REST JSON API endpoint for generating AI-assisted activity proposal themes using Google Gemini API.
 * Follows the existing AI Objectives architecture with strict authorization, input validation,
 * zero PII transmission, and resilient model fallback.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/proposal_validator.php';

header('Content-Type: application/json; charset=utf-8');

// 1. Authentication & Role Authorization
startSession();
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['error' => 'Unauthorized: Please log in.']);
    exit;
}

if (empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'faculty') {
    http_response_code(403);
    ob_clean();
    echo json_encode(['error' => 'Forbidden: Only faculty can request theme suggestions.']);
    exit;
}

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
if (!is_array($input)) {
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
    echo json_encode(['error' => 'Activity title is required to suggest a theme.']);
    exit;
}

// 6. Gather Non-PII Proposal Context
$source = trim($input['source'] ?? '');
$target_participants = trim((string)($input['target_participants'] ?? ''));
$venue = trim($input['venue'] ?? '');
$involved_subjects = trim($input['involved_subjects'] ?? '');
$rationale = trim($input['rationale'] ?? '');

// 7. Prompt Construction (Strictly Zero PII)
// Note: Objectives are downstream outputs of Title & Theme; they are strictly excluded from Theme generation context.
$promptDetails = "- Title: " . $title . "\n";
if ($source !== '') {
    $promptDetails .= "- Organizer/Source: " . $source . "\n";
}
if ($target_participants !== '') {
    $promptDetails .= "- Target Participants: " . $target_participants . "\n";
}
if ($venue !== '') {
    $promptDetails .= "- Venue: " . $venue . "\n";
}
if ($involved_subjects !== '') {
    $promptDetails .= "- Academic Subjects: " . $involved_subjects . "\n";
}
if ($rationale !== '') {
    $promptDetails .= "- Rationale: " . $rationale . "\n";
}

// Temporary debug logging to record non-PII payload and prompt inputs for analysis
$debugThemeLogPath = __DIR__ . '/../scratch/debug_theme_request.log';
$debugLogEntry = date('[Y-m-d H:i:s] ') . "=== GENERATE THEME REQUEST ===\n"
    . "INPUT TITLE: " . $title . "\n"
    . "FULL NON-PII INPUT PAYLOAD: " . json_encode($input, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
    . "PROMPT DETAILS SENT TO GEMINI:\n" . $promptDetails . "\n"
    . "===============================================\n\n";
@file_put_contents($debugThemeLogPath, $debugLogEntry, FILE_APPEND);

$prompt = "Act as an academic advisor and event branding specialist for STI College in the Philippines. Analyze the following college activity proposal details and generate a concise, inspiring, and relevant theme/tagline for the event.

ACTIVITY DETAILS:
{$promptDetails}
GUIDELINES:
1. Multilingual Title Support: The activity title may be in English, Filipino/Tagalog, or mixed English-Filipino (Taglish).
   - Do NOT assume non-English titles are invalid, and do NOT translate the title unnecessarily before generating the theme.
   - For Filipino/Tagalog or Philippine cultural event titles (such as \"Buwan ng Wika\"), generate culturally authentic, inspiring, and academically appropriate themes. The theme may be in Filipino, English, or mixed Filipino-English depending on standard college practice.
   - For English titles (such as \"STI TechNovation 2026: AI & Robotics Summit\" or \"Leadership Development Seminar\"), generate sharp, inspiring English or bilingual themes.
   - For mixed Filipino-English titles, generate themes matching the tone, spirit, and context of the event.
2. Suggest a concise, catchy, inspiring, and academically relevant theme/tagline (typically 4 to 12 words) suitable for a college activity banner, stage backdrop, or proposal.
3. The theme should directly reflect the activity title and available context.
4. Do not invent unrelated activity details or include personal names or personal data.
5. Return exactly 1 primary suggested theme and 2 alternative themes.
6. Output MUST be formatted as a valid JSON object matching this exact structure without markdown code fences (no ```json):

{
  \"theme\": \"Primary suggested theme here\",
  \"alternatives\": [
    \"Alternative theme option 1\",
    \"Alternative theme option 2\"
  ]
}";

// 8. Gemini API Key Retrieval
$apiKey = function_exists('getGeminiApiKeySecure') ? getGeminiApiKeySecure() : '';
if (empty($apiKey) && defined('GEMINI_API_KEY')) {
    $apiKey = GEMINI_API_KEY;
}

if (empty($apiKey) || $apiKey === 'YOUR_API_KEY_HERE') {
    http_response_code(503);
    ob_clean();
    echo json_encode([
        'error' => 'Configuration Error: Gemini API key is missing.',
        'details' => 'Please configure the GEMINI_API_KEY in the environment or .env file.'
    ]);
    exit;
}

// 9. Call Gemini API with Fallback Models
$payloadData = [
    'contents' => [
        [
            'parts' => [
                ['text' => $prompt]
            ]
        ]
    ],
    'generationConfig' => [
        'temperature' => 0.4,
        'topP' => 0.8,
        'maxOutputTokens' => 1024,
        'responseMimeType' => 'application/json'
    ]
];

$modelsToTry = ['gemini-3.5-flash-lite', 'gemini-3.1-flash-lite', 'gemini-flash-lite-latest', 'gemini-3.7-flash', 'gemini-3.6-flash', 'gemini-flash-latest', 'gemini-3.5-flash'];
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
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payloadData));
        curl_setopt($ch, CURLOPT_TIMEOUT, 40);
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
            break 2;
        }

        // Retry on 503 or 429
        if ($httpCode === 503 || $httpCode === 429 || (is_string($response) && strpos($response, 'UNAVAILABLE') !== false)) {
            if ($attempt < $maxAttempts) {
                $sleepSeconds = 2;
                error_log("Gemini API Model $modelName returned HTTP $httpCode in generate-theme. Attempt $attempt/$maxAttempts. Retrying in {$sleepSeconds}s...");
                sleep($sleepSeconds);
                continue;
            }
        }
        break;
    }

    error_log("Gemini API Model $modelName failed with HTTP $httpCode in generate-theme. Trying fallback model...");
}

if ($curlError) {
    error_log("Gemini API cURL Error (generate-theme): " . $curlError);
    http_response_code(502);
    ob_clean();
    echo json_encode(['error' => 'cURL Error: ' . $curlError]);
    exit;
}

if ($httpCode !== 200) {
    error_log("Gemini API Error (HTTP $httpCode) in generate-theme: " . $response);
    http_response_code(502);
    ob_clean();
    echo json_encode([
        'error' => 'AI Service Error: HTTP ' . $httpCode,
        'details' => 'Unable to retrieve theme from AI provider.'
    ]);
    exit;
}

// 10. Extract and Parse Structured JSON Robustly
/**
 * Decodes candidate JSON and checks for a valid theme field.
 */
function tryDecodeThemeCandidate(string $jsonCandidate): ?array {
    $themeData = json_decode($jsonCandidate, true);
    if (!is_array($themeData)) {
        return null;
    }

    // Flexible key lookup for primary theme
    $theme = '';
    $possibleKeys = ['theme', 'Theme', 'THEME', 'primary_theme', 'suggested_theme', 'tagline', 'tema'];
    foreach ($possibleKeys as $k) {
        if (!empty($themeData[$k]) && is_string($themeData[$k])) {
            $theme = trim($themeData[$k]);
            break;
        }
    }

    if ($theme === '') {
        return null;
    }

    // Flexible key lookup for alternative themes
    $alternatives = [];
    $altKeys = ['alternatives', 'Alternatives', 'alternative_themes', 'options', 'alternatibo'];
    foreach ($altKeys as $k) {
        if (!empty($themeData[$k]) && is_array($themeData[$k])) {
            foreach ($themeData[$k] as $alt) {
                if (is_string($alt) && trim($alt) !== '' && trim($alt) !== $theme) {
                    $alternatives[] = trim($alt);
                }
            }
            break;
        }
    }

    return [
        'theme' => $theme,
        'alternatives' => array_values(array_unique($alternatives))
    ];
}

/**
 * Extracts and normalizes theme data from arbitrary text.
 */
function parseThemeText(string $rawText): ?array {
    $text = trim($rawText);
    if ($text === '') {
        return null;
    }

    // 1. Remove UTF-8 Byte Order Mark (BOM) if present
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);

    // 2. Normalize smart/curly quotes
    $normalized = str_replace(
        ['“', '”', '‘', '’', '`'],
        ['"', '"', "'", "'", "'"],
        $text
    );

    // 3. Extract code fence blocks if any (```json ... ``` or ``` ... ```)
    if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $normalized, $fenceMatches)) {
        $fenceData = tryDecodeThemeCandidate(trim($fenceMatches[1]));
        if ($fenceData !== null) {
            return $fenceData;
        }
    }

    // 4. Try direct decode
    $direct = tryDecodeThemeCandidate($normalized);
    if ($direct !== null) {
        return $direct;
    }

    // 5. Outermost JSON object extraction
    $firstBrace = strpos($normalized, '{');
    $lastBrace = strrpos($normalized, '}');
    if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
        $jsonSub = substr($normalized, $firstBrace, $lastBrace - $firstBrace + 1);
        $subData = tryDecodeThemeCandidate($jsonSub);
        if ($subData !== null) {
            return $subData;
        }

        // Clean trailing commas before } or ]
        $cleanJson = preg_replace('/,\s*([\]\}])/m', '$1', $jsonSub);
        $cleanData = tryDecodeThemeCandidate($cleanJson);
        if ($cleanData !== null) {
            return $cleanData;
        }
    }

    // 6. Fallback regex extraction for "theme": "..."
    if (preg_match('/["\']?(?:theme|tagline|suggested_theme|primary_theme|tema)["\']?\s*:\s*["\']([^"\'\r\n]+)["\']/i', $normalized, $themeMatch)) {
        $theme = trim(stripslashes($themeMatch[1]));
        if ($theme !== '') {
            $alts = [];
            if (preg_match('/["\']?(?:alternatives|alternative_themes|options)["\']?\s*:\s*\[(.*?)\]/is', $normalized, $altBlock)) {
                if (preg_match_all('/["\']([^"\'\r\n]+)["\']/', $altBlock[1], $altMatches)) {
                    foreach ($altMatches[1] as $item) {
                        $cleanAlt = trim(stripslashes($item));
                        if ($cleanAlt !== '' && $cleanAlt !== $theme) {
                            $alts[] = $cleanAlt;
                        }
                    }
                }
            }
            return [
                'theme' => $theme,
                'alternatives' => array_values(array_unique($alts))
            ];
        }
    }

    return null;
}

$themeResult = null;
$geminiResult = json_decode($response, true);
$extractedTexts = [];

if (is_array($geminiResult) && isset($geminiResult['candidates']) && is_array($geminiResult['candidates'])) {
    foreach ($geminiResult['candidates'] as $candidate) {
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

// Try each text part first
foreach ($extractedTexts as $candidateText) {
    $parsed = parseThemeText($candidateText);
    if ($parsed !== null) {
        $themeResult = $parsed;
        break;
    }
}

// If not resolved, try concatenated text parts
if ($themeResult === null && !empty($extractedTexts)) {
    $themeResult = parseThemeText(implode("\n", $extractedTexts));
}

if ($themeResult !== null && !empty($themeResult['theme'])) {
    ob_clean();
    echo json_encode([
        'success' => true,
        'theme' => $themeResult['theme'],
        'alternatives' => $themeResult['alternatives']
    ]);
    exit;
}

// Fallback if failed to parse the generated theme from Gemini API.
error_log("Gemini API Parse Error in generate-theme: Failed to parse the generated theme from Gemini API response.");
http_response_code(422);
ob_clean();
echo json_encode([
    'error' => 'The AI returned an unexpected response. Please try again.',
    'error_type' => 'parse_error'
]);

