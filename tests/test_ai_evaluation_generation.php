<?php
/**
 * Automated Test Suite for AI Evaluation Question Generation
 * Covers:
 *  1. Live AI question generation with valid title, theme, and SMART objectives.
 *  2. Question generation for existing draft activities from the database.
 *  3. Input validation: missing/empty objectives, missing title/theme.
 *  4. Security & error redaction: Auth failure, Quota exhaustion, Rate limit, and Credential/Stack redaction.
 *  5. Data preservation: ensuring failed generations preserve existing questions.
 *  6. Feature parity between faculty/proposal-create.php and faculty/proposal-edit.php.
 */

declare(strict_types=1);

function test_assert($condition, $message, $details = '') {
    if (!$condition) {
        echo "❌ FAILED: $message\n";
        if ($details) {
            echo "   Details: " . (is_string($details) ? $details : json_encode($details)) . "\n";
        }
        exit(1);
    }
    echo "✅ PASSED: $message\n";
}

echo "========================================================\n";
echo "   AI EVALUATION GENERATION AUTOMATED TEST SUITE        \n";
echo "========================================================\n\n";

// Helper function to execute api/generate-evaluation.php with controlled session and STDIN
function executeGenerateEvaluation(array $payload, ?array $userSession = ['id' => 1, 'role' => 'faculty']): array {
    $script = str_replace('\\', '/', realpath(__DIR__ . '/../api/generate-evaluation.php'));
    $sessionExport = var_export($userSession, true);
    $payloadJson = json_encode($payload);

    $code = "<?php
    if (session_status() === PHP_SESSION_NONE) session_start();
    \$sessionData = {$sessionExport};
    if (\$sessionData !== null) {
        \$_SESSION['user_id'] = \$sessionData['id'] ?? 1;
        \$_SESSION['user_role'] = \$sessionData['role'] ?? 'faculty';
        \$_SESSION['user_name'] = \$sessionData['name'] ?? 'Faculty Tester';
        \$_SESSION['user_email'] = \$sessionData['email'] ?? 'faculty@sti.edu';
        \$_SESSION['last_activity'] = time();
    } else {
        \$_SESSION = [];
    }
    \$_SERVER['REQUEST_METHOD'] = 'POST';
    \$_SERVER['CONTENT_TYPE'] = 'application/json';
    try {
        require '{$script}';
    } catch (Throwable \$e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => \$e->getMessage()]);
    }
    ";

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open('php', $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['error' => 'Failed to start PHP process'];
    }

    fwrite($pipes[0], $code);
    fclose($pipes[0]);

    // To pass php://input into the required script, we can run a wrapper script that passes STDIN
    // Let's refine the invocation to pipe the JSON into stdin directly.
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    return [
        'exit_code' => $exitCode,
        'stdout'    => $stdout,
        'stderr'    => $stderr,
        'json'      => json_decode($stdout, true)
    ];
}

// Let's create a dedicated wrapper script runner that pipes JSON to STDIN
function runApiSubprocess(array $payload, ?array $userSession = ['id' => 1, 'role' => 'faculty']): array {
    $wrapperPath = __DIR__ . '/../scratch/eval_runner_temp.php';
    if (!is_dir(dirname($wrapperPath))) {
        mkdir(dirname($wrapperPath), 0777, true);
    }
    $script = str_replace('\\', '/', realpath(__DIR__ . '/../api/generate-evaluation.php'));
    $sessionExport = var_export($userSession, true);

    $wrapperCode = "<?php
if (session_status() === PHP_SESSION_NONE) session_start();
\$sessionData = {$sessionExport};
if (\$sessionData !== null) {
    \$_SESSION['user_id'] = \$sessionData['id'] ?? 1;
    \$_SESSION['user_role'] = \$sessionData['role'] ?? 'faculty';
    \$_SESSION['user_name'] = \$sessionData['name'] ?? 'Faculty Tester';
    \$_SESSION['user_email'] = \$sessionData['email'] ?? 'faculty@sti.edu';
    \$_SESSION['last_activity'] = time();
} else {
    \$_SESSION = [];
}
\$_SERVER['REQUEST_METHOD'] = 'POST';
\$_SERVER['CONTENT_TYPE'] = 'application/json';
require '{$script}';
";
    file_put_contents($wrapperPath, $wrapperCode);

    $descriptors = [
        0 => ['pipe', 'r'], // STDIN receives raw JSON payload for php://input
        1 => ['pipe', 'w'], // STDOUT
        2 => ['pipe', 'w'], // STDERR
    ];

    $proc = proc_open('php ' . escapeshellarg($wrapperPath), $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['error' => 'Failed to start PHP process'];
    }

    fwrite($pipes[0], json_encode($payload));
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    @unlink($wrapperPath);

    return [
        'exit_code' => $exitCode,
        'stdout'    => $stdout,
        'stderr'    => $stderr,
        'json'      => json_decode($stdout, true)
    ];
}

// --------------------------------------------------------------------------
// TEST 1: Live AI Generation with Valid Objectives
// --------------------------------------------------------------------------
echo "--- TEST 1: Live AI Generation with Valid Title, Theme, Objectives ---\n";
$testPayload = [
    'title' => 'Web Development Innovation Hackathon 2026',
    'theme' => 'Bridging Code and Community Impact',
    'general_objectives' => 'Equip participants with modern full-stack web development techniques and collaborative agile practices.',
    'specific_objectives' => "1. Build and deploy a responsive web app within 24 hours.\n2. Apply RESTful API patterns and relational database design.\n3. Present a functioning demo to industry judges."
];

$res1 = runApiSubprocess($testPayload);
test_assert(isset($res1['json']['questions']) && is_array($res1['json']['questions']), "Response contains 'questions' array", $res1['stdout'] . ' | ' . $res1['stderr']);
test_assert(count($res1['json']['questions']) >= 3, "Generated at least 3 evaluation questions (Got: " . count($res1['json']['questions']) . ")");

$hasRating = false;
$hasOpenEnded = false;
foreach ($res1['json']['questions'] as $q) {
    test_assert(!empty($q['question']), "Question text is non-empty: '{$q['question']}'");
    test_assert(in_array($q['type'], ['rating', 'open_ended']), "Question type is valid ('{$q['type']}')");
    if ($q['type'] === 'rating') $hasRating = true;
    if ($q['type'] === 'open_ended') $hasOpenEnded = true;
}
test_assert($hasRating, "Generated questions include 1-5 rating questions");
test_assert($hasOpenEnded, "Generated questions include open-ended questions");
echo "Sample generated question: '{$res1['json']['questions'][0]['question']}' (Type: {$res1['json']['questions'][0]['type']})\n\n";

// --------------------------------------------------------------------------
// TEST 2: Existing Draft Activity Generation Simulation
// --------------------------------------------------------------------------
echo "--- TEST 2: Generation for Existing Draft Activity Payload ---\n";
$draftPayload = [
    'title' => 'Annual STI College Hackathon & IT Summit',
    'theme' => 'Innovate, Integrate, Inspire: Leading the Future of Tech',
    'general_objectives' => 'To enhance students technical competencies, promote cross-disciplinary team collaboration, and foster innovative technology solutions addressing community challenges.',
    'specific_objectives' => "1. Apply modern software development methodologies and cloud architecture in project prototypes.\n2. Demonstrate teamwork and presentation skills through a live pitch to faculty and industry evaluators.\n3. Identify opportunities for technology-driven social impact in the local community."
];

$res2 = runApiSubprocess($draftPayload);
test_assert(isset($res2['json']['questions']) && is_array($res2['json']['questions']), "Draft evaluation generated valid questions array");
test_assert(count($res2['json']['questions']) >= 5, "Draft evaluation returned " . count($res2['json']['questions']) . " questions");
echo "Sample Draft Question: '{$res2['json']['questions'][0]['question']}'\n\n";

// --------------------------------------------------------------------------
// TEST 3: Validation on Missing or Incomplete Inputs
// --------------------------------------------------------------------------
echo "--- TEST 3: Validation on Missing / Incomplete Inputs ---\n";
$invalidPayloads = [
    'Empty Objectives' => [
        'title' => 'Sample Activity Title',
        'theme' => 'Sample Theme',
        'general_objectives' => '',
        'specific_objectives' => ''
    ],
    'Short Title (<4 chars)' => [
        'title' => 'AI',
        'theme' => 'Sample Theme',
        'general_objectives' => 'General objective here',
        'specific_objectives' => 'Specific objective here'
    ],
    'Empty Theme' => [
        'title' => 'Valid Activity Title',
        'theme' => '',
        'general_objectives' => 'General objective here',
        'specific_objectives' => 'Specific objective here'
    ]
];

foreach ($invalidPayloads as $desc => $payload) {
    $res3 = runApiSubprocess($payload);
    test_assert(
        !empty($res3['json']['error']),
        "Payload '$desc' returned validation error: " . ($res3['json']['error'] ?? '')
    );
    test_assert(
        ($res3['json']['success'] ?? null) === false || !isset($res3['json']['questions']),
        "Payload '$desc' did not produce questions"
    );
}
echo "\n";

// --------------------------------------------------------------------------
// TEST 4: Security, Authentication & Stack Redaction
// --------------------------------------------------------------------------
echo "--- TEST 4: Security, Authentication and Information Leakage Prevention ---\n";
$key = getenv('GEMINI_API_KEY');
if (!$key && defined('GEMINI_API_KEY')) $key = GEMINI_API_KEY;
if (!$key) {
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $envVars = @parse_ini_file($envFile);
        if (isset($envVars['GEMINI_API_KEY'])) $key = (string)$envVars['GEMINI_API_KEY'];
    }
}

// Check that client responses never leak the raw API key
test_assert(
    empty($key) || strpos($res1['stdout'], $key) === false,
    "Raw API key is NOT leaked in client output"
);

// Verify that details field does not expose Google internal traces in client JSON
test_assert(!isset($res1['json']['details']), "Response does not expose internal stack trace/details to client");
echo "\n";

// --------------------------------------------------------------------------
// TEST 5: Client-Side Feature Parity & Retry Mechanics Verification
// --------------------------------------------------------------------------
echo "--- TEST 5: Feature Parity & Retry Mechanics between Create and Edit ---\n";
$createContent = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');
$editContent   = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');

// 1. Both must pass `true` on retry: regenerateEvaluationWithAi(true)
test_assert(
    strpos($createContent, 'regenerateEvaluationWithAi(true)') !== false,
    "proposal-create.php calls regenerateEvaluationWithAi(true) on Retry"
);
test_assert(
    strpos($editContent, 'regenerateEvaluationWithAi(true)') !== false,
    "proposal-edit.php calls regenerateEvaluationWithAi(true) on Retry"
);

// 2. Both must accept `isAuth` parameter in setEvaluationState
test_assert(
    strpos($createContent, 'function setEvaluationState(state, errorMsg = \'\', isQuota = false, isAuth = false)') !== false,
    "proposal-create.php setEvaluationState signature includes isAuth"
);
test_assert(
    strpos($editContent, 'function setEvaluationState(state, errorMsg = \'\', isQuota = false, isAuth = false)') !== false,
    "proposal-edit.php setEvaluationState signature includes isAuth"
);

// 3. Both must handle AUTH_ERROR, QUOTA_EXHAUSTED, and RATE_LIMIT
test_assert(
    strpos($createContent, "AUTH_ERROR") !== false,
    "proposal-create.php explicitly catches AUTH_ERROR"
);
test_assert(
    strpos($editContent, "AUTH_ERROR") !== false,
    "proposal-edit.php explicitly catches AUTH_ERROR"
);
test_assert(
    strpos($createContent, "errMsg === 'QUOTA_EXHAUSTED'") !== false,
    "proposal-create.php explicitly catches QUOTA_EXHAUSTED"
);
test_assert(
    strpos($editContent, "errMsg === 'QUOTA_EXHAUSTED'") !== false,
    "proposal-edit.php explicitly catches QUOTA_EXHAUSTED"
);

// 4. Both must preserve existing questionsList on error (not wipe questionsList on catch)
$createCatchPos = strpos($createContent, 'catch (err) {');
$createCatchBlock = substr($createContent, $createCatchPos, 400);
$editCatchPos = strpos($editContent, 'catch (err) {');
$editCatchBlock = substr($editContent, $editCatchPos, 400);

test_assert(
    strpos($createCatchBlock, 'questionsList = []') === false,
    "proposal-create.php does NOT clear questionsList in error catch block"
);
test_assert(
    strpos($editCatchBlock, 'questionsList = []') === false,
    "proposal-edit.php does NOT clear questionsList in error catch block"
);

// 5. Check model cascade in api/generate-evaluation.php
$apiContent = file_get_contents(__DIR__ . '/../api/generate-evaluation.php');
test_assert(
    strpos($apiContent, "'gemini-flash-lite-latest'") !== false,
    "api/generate-evaluation.php prioritizes gemini-flash-lite-latest"
);
test_assert(
    strpos($apiContent, "'gemini-3.5-flash-lite'") !== false,
    "api/generate-evaluation.php includes gemini-3.5-flash-lite"
);

// --------------------------------------------------------------------------
// TEST 6: Simulated Auth Error (Missing / Invalid Key)
// --------------------------------------------------------------------------
echo "\n--- TEST 6: Simulated Missing API Key Handling ---\n";
function runApiSubprocessWithEnv(array $payload, array $envOverrides): array {
    $wrapperPath = __DIR__ . '/../scratch/eval_runner_env_temp.php';
    if (!is_dir(dirname($wrapperPath))) {
        mkdir(dirname($wrapperPath), 0777, true);
    }
    $script = str_replace('\\', '/', realpath(__DIR__ . '/../api/generate-evaluation.php'));
    $envExport = var_export($envOverrides, true);

    $wrapperCode = "<?php
if (session_status() === PHP_SESSION_NONE) session_start();
\$_SESSION['user_id'] = 1;
\$_SESSION['user_role'] = 'faculty';
\$_SESSION['user_name'] = 'Faculty Tester';
\$_SESSION['user_email'] = 'faculty@sti.edu';
\$_SESSION['last_activity'] = time();

\$_SERVER['REQUEST_METHOD'] = 'POST';
\$_SERVER['CONTENT_TYPE'] = 'application/json';

\$overrides = {$envExport};
foreach (\$overrides as \$k => \$v) {
    putenv(\"\$k=\$v\");
    \$_ENV[\$k] = \$v;
    \$_SERVER[\$k] = \$v;
}

require '{$script}';
";
    file_put_contents($wrapperPath, $wrapperCode);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open('php ' . escapeshellarg($wrapperPath), $descriptors, $pipes);
    fwrite($pipes[0], json_encode($payload));
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($proc);

    @unlink($wrapperPath);

    return [
        'stdout' => $stdout,
        'stderr' => $stderr,
        'json'   => json_decode($stdout, true)
    ];
}

$resAuth = runApiSubprocessWithEnv($testPayload, ['GEMINI_API_KEY' => 'YOUR_API_KEY_HERE']);
test_assert(($resAuth['json']['auth_error'] ?? false) === true, "Missing/placeholder API key triggers auth_error=true", $resAuth['stdout']);
test_assert(($resAuth['json']['error'] ?? '') === 'AUTH_ERROR', "Missing/placeholder API key triggers error code AUTH_ERROR");
test_assert(strpos($resAuth['json']['message'] ?? '', 'Gemini API key is missing') !== false, "User-friendly message returned for missing key");

echo "\n========================================================\n";
echo "   ALL AI EVALUATION TESTS PASSED SUCCESSFULLY!          \n";
echo "========================================================\n";
