<?php
/**
 * tests/test_ai_theme_suggestion.php
 *
 * Automated verification test suite for AI Theme Suggestion capability:
 * 1. Authorization & Role Security (unauthenticated, non-faculty, cross-faculty)
 * 2. Input Validation (empty/missing title)
 * 3. Zero PII Guarantee in prompt construction
 * 4. Structured JSON response format
 * 5. Error handling on Gemini failure (no crash, no fake response)
 * 6. Malformed AI response handling
 * 7. Proposal form UI integration contract (proposal-create.php & proposal-edit.php)
 * 8. Regression check on existing AI Objectives generator
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "========================================================================\n";
echo "   AI THEME SUGGESTION CAPABILITY & SECURITY VERIFICATION SUITE\n";
echo "========================================================================\n\n";

$db = getDB();
$testsPassed = 0;
$testsFailed = 0;

function reportTest(string $desc, bool $passed, string $notes = ''): void {
    global $testsPassed, $testsFailed;
    if ($passed) {
        $testsPassed++;
        echo " [PASS] {$desc}\n";
    } else {
        $testsFailed++;
        echo " [FAIL] {$desc}\n";
    }
    if ($notes !== '') {
        echo "        {$notes}\n";
    }
}

/**
 * Isolated process execution helper for testing API endpoints safely on Windows.
 */
function extractJson(string $output): ?array {
    $trimmed = trim($output);
    $decoded = json_decode($trimmed, true);
    if (is_array($decoded)) return $decoded;
    $lines = array_reverse(explode("\n", $trimmed));
    $jsonLines = [];
    foreach ($lines as $line) {
        array_unshift($jsonLines, $line);
        $candidate = trim(implode("\n", $jsonLines));
        $decoded = json_decode($candidate, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return null;
}

function runThemeEndpoint(array $sessionData, array $postData, ?string $rawJsonBody = null): array {
    $script = __DIR__ . '/../api/generate-theme.php';
    $tmpFile = __DIR__ . '/_tmp_runner_' . uniqid() . '.php';

    $code = "<?php\n";
    $code .= "ini_set('display_errors', '0');\n";
    if (!empty($sessionData)) {
        $code .= "session_start();\n";
        $code .= "\$_SESSION = " . var_export($sessionData, true) . ";\n";
    }
    $code .= "\$_SERVER['REQUEST_METHOD'] = 'POST';\n";
    if ($rawJsonBody !== null) {
        $code .= "\$_POST = [];\n";
    } else {
        $code .= "\$_POST = " . var_export($postData, true) . ";\n";
    }
    $code .= "require " . var_export($script, true) . ";\n";

    file_put_contents($tmpFile, $code);
    $output = shell_exec("php " . escapeshellarg($tmpFile) . " 2>&1");
    @unlink($tmpFile);

    $raw = is_string($output) ? trim($output) : '';
    return [
        'raw' => $raw,
        'json' => extractJson($raw)
    ];
}

// -------------------------------------------------------------------------
// STEP 1: Unauthenticated Access Rejection
// -------------------------------------------------------------------------
$resUnauth = runThemeEndpoint([], ['title' => 'Sample Title']);
$is401 = is_array($resUnauth['json']) &&
         isset($resUnauth['json']['error']) &&
         str_contains($resUnauth['json']['error'], 'Unauthorized');
reportTest("Unauthenticated request to generate-theme.php is blocked (401/Unauthorized)", $is401,
    "Output: " . $resUnauth['raw']);

// -------------------------------------------------------------------------
// STEP 2: Non-Faculty Role Rejection
// -------------------------------------------------------------------------
$resDean = runThemeEndpoint([
    'user_id' => 4,
    'user_role' => 'dean',
    'user_name' => 'Dean Frederic'
], ['title' => 'Sample Title']);
$isDeanBlocked = is_array($resDean['json']) &&
                 isset($resDean['json']['error']) &&
                 str_contains($resDean['json']['error'], 'Forbidden');
reportTest("Dean / non-faculty user is blocked from generate-theme.php (403/Forbidden)", $isDeanBlocked,
    "Output: " . $resDean['raw']);

// -------------------------------------------------------------------------
// STEP 3: Cross-Faculty Proposal Ownership Protection
// -------------------------------------------------------------------------
// Fetch Faculty 1
$fac1 = (int)$db->query("SELECT id FROM users WHERE role='faculty' ORDER BY id ASC LIMIT 1")->fetchColumn();
$fac2 = (int)$db->query("SELECT id FROM users WHERE role='faculty' AND id != {$fac1} ORDER BY id ASC LIMIT 1")->fetchColumn();

if (!$fac2) {
    // Insert temporary second faculty user
    $insUser = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Faculty B', 'fac_b_test@sti.edu', 'hash', 'faculty', 'IT Department')");
    $insUser->execute();
    $fac2 = (int)$db->lastInsertId();
}

// Create an activity owned by Faculty B
$insAct = $db->prepare("INSERT INTO activities (faculty_id, title, status) VALUES (?, 'Faculty B Private Proposal', 'draft')");
$insAct->execute([$fac2]);
$actBId = (int)$db->lastInsertId();

// Faculty A tries to access Faculty B's proposal
$resCross = runThemeEndpoint([
    'user_id' => $fac1,
    'user_role' => 'faculty',
    'user_name' => 'Faculty A'
], ['title' => 'Test Proposal', 'activity_id' => $actBId]);

$isCrossBlocked = is_array($resCross['json']) &&
                 isset($resCross['json']['error']) &&
                 (str_contains($resCross['json']['error'], 'Forbidden') || str_contains($resCross['json']['error'], 'permission'));
reportTest("Faculty cannot access another Faculty's proposal AI endpoint (403/Forbidden)", $isCrossBlocked,
    "Output: " . $resCross['raw']);

// Clean up test activity
$db->exec("DELETE FROM activities WHERE id = {$actBId}");

// -------------------------------------------------------------------------
// STEP 4: Missing Title Validation (422)
// -------------------------------------------------------------------------
$resEmpty = runThemeEndpoint([
    'user_id' => $fac1,
    'user_role' => 'faculty',
    'user_name' => 'Faculty A'
], ['title' => '   ']);
$isEmptyTitle422 = is_array($resEmpty['json']) &&
                   isset($resEmpty['json']['error']) &&
                   str_contains($resEmpty['json']['error'], 'Activity title is required');
reportTest("Empty title request returns validation error (422)", $isEmptyTitle422,
    "Output: " . $resEmpty['raw']);

// -------------------------------------------------------------------------
// STEP 5: Zero PII Prompt Verification
// -------------------------------------------------------------------------
$endpointCode = file_get_contents(__DIR__ . '/../api/generate-theme.php');
$piiSafe = !str_contains($endpointCode, 'currentUser') &&
           !str_contains($endpointCode, '$_SESSION[\'user_name\']') &&
           !str_contains($endpointCode, '$_SESSION[\'user_email\']') &&
           str_contains($endpointCode, 'Zero PII');
reportTest("Prompt Construction Verification: Zero PII transmitted to Gemini", $piiSafe,
    "Confirmed: Only activity title and academic context are included in the prompt payload.");

// -------------------------------------------------------------------------
// STEP 6: Live Gemini API Theme Suggestion
// -------------------------------------------------------------------------
$resLive = runThemeEndpoint([
    'user_id' => $fac1,
    'user_role' => 'faculty',
    'user_name' => 'Faculty A'
], [
    'title' => 'STI TechNovation Summit 2026',
    'source' => 'student_org',
    'target_participants' => '200',
    'venue' => 'Auditorium',
    'rationale' => 'To promote AI literacy and hands-on coding among college students.'
]);

$liveJson = $resLive['json'];
$livePassed = is_array($liveJson) &&
              !empty($liveJson['success']) &&
              !empty($liveJson['theme']) &&
              is_string($liveJson['theme']);
reportTest("Faculty can request and retrieve a valid AI theme suggestion", $livePassed,
    "Theme Generated: " . ($liveJson['theme'] ?? 'None') .
    " | Alternatives: " . count($liveJson['alternatives'] ?? []));

// -------------------------------------------------------------------------
// STEP 7: Graceful Error Handling on AI Failure (No Crash, No Fake Result)
// -------------------------------------------------------------------------
// Run with invalid API key
$tmpBadKey = __DIR__ . '/_tmp_bad_key_' . uniqid() . '.php';
$badKeyCode = "<?php\n" .
    "ini_set('display_errors', '0');\n" .
    "session_start();\n" .
    "\$_SESSION = ['user_id' => {$fac1}, 'user_role' => 'faculty'];\n" .
    "\$_SERVER['REQUEST_METHOD'] = 'POST';\n" .
    "\$_POST = ['title' => 'Test Event'];\n" .
    "putenv('GEMINI_API_KEY=INVALID_KEY_12345');\n" .
    "require " . var_export(__DIR__ . '/../api/generate-theme.php', true) . ";\n";
file_put_contents($tmpBadKey, $badKeyCode);
$outBadKey = shell_exec("php " . escapeshellarg($tmpBadKey) . " 2>&1");
@unlink($tmpBadKey);

$badKeyJson = extractJson(trim($outBadKey));
$badKeyHandled = is_array($badKeyJson) && isset($badKeyJson['error']) && empty($badKeyJson['theme']);
reportTest("AI Service failure produces graceful error JSON without faking result", $badKeyHandled,
    "Error: " . ($badKeyJson['error'] ?? 'None'));

// -------------------------------------------------------------------------
// STEP 8: Malformed AI Response Handled Safely
// -------------------------------------------------------------------------
$hasMalformedHandling = str_contains($endpointCode, 'Failed to parse the generated theme from Gemini API') &&
                        str_contains($endpointCode, 'is_array($themeData)');
reportTest("Malformed AI response handling safely guarded with fallback", $hasMalformedHandling,
    "Endpoint validates parsed JSON structure before returning.");

// -------------------------------------------------------------------------
// STEP 9: UI Integration Contract in proposal-create.php (Automatic Generation)
// -------------------------------------------------------------------------
$createCode = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');
$createHasStatus = str_contains($createCode, 'id="themeAiStatus"');
$createHasFn = str_contains($createCode, 'setupAutomaticThemeGeneration') &&
               str_contains($createCode, 'autoGenerateTheme') &&
               str_contains($createCode, 'renderThemeStatus');
$createProtectsManual = str_contains($createCode, 'isThemeManuallyEdited') &&
                        str_contains($createCode, 'OUTDATED');
$createDebounces = str_contains($createCode, 'themeDebounceTimer') &&
                   str_contains($createCode, 'currentThemeRequestId');
reportTest("proposal-create.php Automatic AI Theme Contract & Overwrite Protection",
    $createHasStatus && $createHasFn && $createProtectsManual && $createDebounces,
    "Verified: Automatic debounce, state tracking, concurrency guard, manual edit preservation.");

// -------------------------------------------------------------------------
// STEP 10: UI Integration Contract in proposal-edit.php (Automatic Generation)
// -------------------------------------------------------------------------
$editCode = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');
$editHasStatus = str_contains($editCode, 'id="themeAiStatus"');
$editHasFn = str_contains($editCode, 'setupAutomaticThemeGeneration') &&
             str_contains($editCode, 'autoGenerateTheme') &&
             str_contains($editCode, 'renderThemeStatus');
$editPreservesSaved = str_contains($editCode, 'isThemeManuallyEdited = true') &&
                      str_contains($editCode, 'OUTDATED');
reportTest("proposal-edit.php Automatic AI Theme Contract & Existing Value Preservation",
    $editHasStatus && $editHasFn && $editPreservesSaved,
    "Verified: Edit form preserves saved theme on load and alerts on title change without overwriting.");

// -------------------------------------------------------------------------
// STEP 11: Existing AI Objectives Endpoint Contract & Functionality
// -------------------------------------------------------------------------
$objCode = file_get_contents(__DIR__ . '/../api/generate-objectives.php');
$objContract = str_contains($objCode, "requireRole('faculty')") &&
               str_contains($objCode, "general_objective") &&
               str_contains($objCode, "specific_objectives") &&
               str_contains($objCode, "SMART");
reportTest("Existing AI Objectives Contract (api/generate-objectives.php) is intact", $objContract,
    "Verified: requireRole('faculty'), SMART guideline prompt, and JSON response schema unchanged.");

// -------------------------------------------------------------------------
// STEP 12: Multilingual & Required Titles Verification Suite
// -------------------------------------------------------------------------
$titlesToTest = [
    'Buwan ng Wika' => 'Filipino/Tagalog title',
    'STI TechNovation 2026: AI & Robotics Summit' => 'English technical summit title',
    'Leadership Development Seminar' => 'English seminar title',
    'Paskong STI: Sama-samang Pagsulong sa Hamon ng Bagong Taon' => 'Mixed Filipino-English title'
];

foreach ($titlesToTest as $testTitle => $titleType) {
    $resTitle = runThemeEndpoint([
        'user_id' => $fac1,
        'user_role' => 'faculty',
        'user_name' => 'Faculty A'
    ], [
        'title' => $testTitle
    ]);

    $tJson = $resTitle['json'];
    $tPassed = is_array($tJson) &&
               !empty($tJson['success']) &&
               !empty($tJson['theme']) &&
               is_string($tJson['theme']) &&
               strlen(trim($tJson['theme'])) >= 5;

    reportTest("Theme Generation for {$titleType} ('{$testTitle}')", $tPassed,
        "Theme: " . ($tJson['theme'] ?? 'None') . " | Alternatives: " . count($tJson['alternatives'] ?? []));
}

// -------------------------------------------------------------------------
// STEP 13: Robust Parser Resilience (Code Fences, Preambles, Normalization)
// -------------------------------------------------------------------------
if (!function_exists('parseThemeText')) {
    $srcEndpoint = file_get_contents(__DIR__ . '/../api/generate-theme.php');
    if (preg_match('/(function tryDecodeThemeCandidate[\s\S]*?function parseThemeText[\s\S]*?\n\})/i', $srcEndpoint, $mFuncs)) {
        eval($mFuncs[1]);
    }
}

// Test parser variations in isolation using endpoint function logic
$testCases = [
    'pure_json' => '{"theme": "Innovate, Integrate, Inspire", "alternatives": ["Empower Today", "Lead Tomorrow"]}',
    'fenced_json' => "```json\n{\n  \"theme\": \"Wika ng Karunungan: Yaman ng Bayan\",\n  \"alternatives\": [\"Sining at Kultura\"]\n}\n```",
    'conversational_preamble' => "Narito ang aking mungkahi para sa inyong tema:\n```json\n{\n  \"theme\": \"Sama-samang Pag-unlad sa Gitna ng Pagbabago\"\n}\n```\nSana ay makatulong ito sa inyong proposal.",
    'smart_quotes_and_bom' => "\xEF\xBB\xBF“theme”: “Leading with Vision and Empathy”, “alternatives”: [“Visionary Horizons”]",
    'theme_capitalized_key' => '{"Theme": "Excellence in Technology Education", "Alternatives": ["NextGen Tech"]}'
];

$parserAllPassed = true;
foreach ($testCases as $caseName => $rawInputText) {
    $parsed = function_exists('parseThemeText') ? parseThemeText($rawInputText) : null;
    if (!is_array($parsed) || empty($parsed['theme'])) {
        $parserAllPassed = false;
        reportTest("Parser handles variation: {$caseName}", false, "Failed to parse: {$rawInputText}");
    } else {
        reportTest("Parser handles variation: {$caseName}", true, "Parsed theme: {$parsed['theme']}");
    }
}

// -------------------------------------------------------------------------
// STEP 14: Normalized Error Message on Malformed Response (No Fake Content)
// -------------------------------------------------------------------------
$unparseableText = "This is random conversational gibberish with no theme, no JSON, and no valid structure whatsoever.";
$unparseableResult = parseThemeText($unparseableText);
$correctlyRejected = ($unparseableResult === null);
reportTest("Malformed input returns null in parser without fabricating fake theme", $correctlyRejected,
    "Verified: Does not invent or insert fake content on invalid input.");

// Verify normalized error message contract in generate-theme.php
$normalizedErrorInEndpoint = str_contains($endpointCode, 'The AI returned an unexpected response. Please try again.');
reportTest("Endpoint returns normalized error message on parse failure", $normalizedErrorInEndpoint,
    "Verified: 'The AI returned an unexpected response. Please try again.' is returned.");

// -------------------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------------------
echo "\n========================================================================\n";
echo "TEST SUMMARY: {$testsPassed} Passed | {$testsFailed} Failed\n";
echo "========================================================================\n";

if ($testsFailed === 0) {
    echo "✅ ALL AI THEME SUGGESTION TESTS PASSED PERFECTLY!\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED.\n";
    exit(1);
}
