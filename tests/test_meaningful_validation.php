<?php
/**
 * test_meaningful_validation.php
 *
 * Automated verification test suite for Meaningful-Input Validation:
 * - Tests descriptive field checks (a, 7, asdf, qwerty, 12345)
 * - Tests legitimate one-word titles and venues (not rejected)
 * - Tests meaningful descriptive text acceptance
 * - Tests backend API endpoint validation (api/validate-proposal-ai.php)
 * - Tests backend submission gating (api/proposal-save.php & api/proposal-update.php)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/meaningful_validation.php';

$totalTests = 0;
$passedTests = 0;

function assertCheck($desc, $condition, $details = '') {
    global $totalTests, $passedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$desc}\n";
    } else {
        echo "  [FAIL] {$desc}" . ($details ? " -> {$details}" : "") . "\n";
    }
}

echo "========================================================\n";
echo "1. Unit Tests: Descriptive Fields Checks (a, 7, asdf, qwerty, 12345)\n";
echo "========================================================\n";

$flaggedSamples = ['a', '7', 'asd', 'asdd', 'asfasdas', 'asdasjfss', 'asdf', 'qwerty', '12345', '   asdf   ', 'aaaa', 'test test test test'];

foreach ($flaggedSamples as $badVal) {
    $errRationale = validateProposalFieldMeaningful('rationale', $badVal);
    assertCheck("Descriptive 'rationale' rejects '{$badVal}'", $errRationale !== null, $errRationale ?? '');

    $errGenObj = validateProposalFieldMeaningful('general_objectives', $badVal);
    assertCheck("Descriptive 'general_objectives' rejects '{$badVal}'", $errGenObj !== null, $errGenObj ?? '');

    $errSpecObj = validateProposalFieldMeaningful('specific_objectives', $badVal);
    assertCheck("Descriptive 'specific_objectives' rejects '{$badVal}'", $errSpecObj !== null, $errSpecObj ?? '');

    $errFloor = validateProposalFieldMeaningful('floor_plan_notes', $badVal);
    assertCheck("Descriptive 'floor_plan_notes' rejects '{$badVal}'", $errFloor !== null, $errFloor ?? '');

    $errMech = validateProposalFieldMeaningful('guidelines_mechanics', $badVal);
    assertCheck("Descriptive 'guidelines_mechanics' rejects '{$badVal}'", $errMech !== null, $errMech ?? '');
}

echo "\n========================================================\n";
echo "2. Unit Tests: Short Field Checks (title, venue, theme, address, subjects, participants)\n";
echo "========================================================\n";

foreach (['a', '7', 'asd', 'asdd', 'asfasdas', 'asdasjfss', 'asdf', 'qwerty', '12345'] as $badVal) {
    $errTitle = validateProposalFieldMeaningful('title', $badVal);
    assertCheck("Field 'title' rejects '{$badVal}'", $errTitle !== null, $errTitle ?? '');

    $errVenue = validateProposalFieldMeaningful('venue', $badVal);
    assertCheck("Field 'venue' rejects '{$badVal}'", $errVenue !== null, $errVenue ?? '');

    $errTheme = validateProposalFieldMeaningful('theme', $badVal);
    assertCheck("Field 'theme' rejects '{$badVal}'", $errTheme !== null, $errTheme ?? '');

    $errAddr = validateProposalFieldMeaningful('venue_address', $badVal);
    assertCheck("Field 'venue_address' rejects '{$badVal}'", $errAddr !== null, $errAddr ?? '');

    $errSubj = validateProposalFieldMeaningful('involved_subjects', $badVal);
    assertCheck("Field 'involved_subjects' rejects '{$badVal}'", $errSubj !== null, $errSubj ?? '');
}

// Numeric participant checks
foreach (['asd', 'asdd', '0', '-5'] as $badNum) {
    $errPart = validateProposalFieldMeaningful('target_participants', $badNum);
    assertCheck("Field 'target_participants' rejects invalid '{$badNum}'", $errPart !== null, $errPart ?? '');
}
foreach (['1', '100', '250', 50] as $goodNum) {
    $errPart = validateProposalFieldMeaningful('target_participants', $goodNum);
    assertCheck("Field 'target_participants' accepts valid '{$goodNum}'", $errPart === null, $errPart ?? '');
}

// Legitimate subject codes
foreach (['DSA', 'ITE314', 'CS101', 'GE102', 'PE2', 'NSTP'] as $goodSubj) {
    $errSubj = validateProposalFieldMeaningful('involved_subjects', $goodSubj);
    assertCheck("Field 'involved_subjects' accepts valid code '{$goodSubj}'", $errSubj === null, $errSubj ?? '');
}

echo "\n========================================================\n";
echo "3. Unit Tests: Legitimate Single-Word Titles & Venues (Must NOT be rejected)\n";
echo "========================================================\n";

$validSingleWordTitles = [
    'Hackathon',
    'Orientation',
    'Intramurals',
    'Robotics',
    'Webinar',
    'Summit',
    'Symposium',
    'Colloquium',
    'Expo',
    'Camp'
];

foreach ($validSingleWordTitles as $t) {
    $err = validateProposalFieldMeaningful('title', $t);
    assertCheck("Legitimate one-word title '{$t}' is accepted", $err === null, $err ?? '');
}

$validSingleWordVenues = [
    'Auditorium',
    'Gymnasium',
    'Gym',
    'AVR',
    'Library',
    'Cafeteria',
    'Online',
    'Zoom',
    'Field',
    'Court'
];

foreach ($validSingleWordVenues as $v) {
    $err = validateProposalFieldMeaningful('venue', $v);
    assertCheck("Legitimate one-word venue '{$v}' is accepted", $err === null, $err ?? '');
}

echo "\n========================================================\n";
echo "4. Unit Tests: Meaningful Descriptive Text Acceptance\n";
echo "========================================================\n";

$meaningfulObjectives = "To introduce incoming freshman students to university policies, support structures, and the academic grading system.";
$errObj = validateProposalFieldMeaningful('general_objectives', $meaningfulObjectives);
assertCheck("Substantive general objectives accepted", $errObj === null, $errObj ?? '');

$meaningfulRationale = "This activity provides essential transition support for new students to adapt quickly and succeed in their college curriculum.";
$errRat = validateProposalFieldMeaningful('rationale', $meaningfulRationale);
assertCheck("Substantive rationale accepted", $errRat === null, $errRat ?? '');

$meaningfulFloorPlan = "Main entrance on the East corridor with 4 rows of 50 monobloc chairs facing the elevated stage.";
$errFp = validateProposalFieldMeaningful('floor_plan_notes', $meaningfulFloorPlan);
assertCheck("Substantive floor plan notes accepted", $errFp === null, $errFp ?? '');

echo "\n========================================================\n";
echo "5. Full Payload Validation Tests (validateProposalMeaningfulInput)\n";
echo "========================================================\n";

$badPayload = [
    'title' => 'asdf',
    'venue' => '7',
    'venue_address' => '12345',
    'theme' => 'qwerty',
    'general_objectives' => 'a',
    'specific_objectives' => 'asdf',
    'rationale' => 'qwerty',
    'involved_subjects' => '7'
];

$resBad = validateProposalMeaningfulInput($badPayload, true);
assertCheck("Garbage payload fails full validation", $resBad['valid'] === false);
assertCheck("Garbage payload flags title", isset($resBad['errors']['title']));
assertCheck("Garbage payload flags venue", isset($resBad['errors']['venue']));
assertCheck("Garbage payload flags rationale", isset($resBad['errors']['rationale']));
assertCheck("Garbage payload flags general_objectives", isset($resBad['errors']['general_objectives']));

$goodPayload = [
    'title' => 'Freshmen Academic Orientation 2026',
    'venue' => 'Auditorium',
    'venue_address' => '289 L. de Guzman St., Concepcion I, Marikina City',
    'theme' => 'Igniting Excellence and Inspiring Future Achievers',
    'involved_subjects' => 'GE101, ITE111',
    'general_objectives' => 'To welcome new students and familiarize them with college degree paths and facilities.',
    'specific_objectives' => '1. Present academic guidelines. 2. Meet faculty advisers. 3. Tour computer laboratories.',
    'rationale' => 'Orientation is vital for facilitating a seamless transition from high school to tertiary degree programs.',
    'floor_plan_notes' => 'Auditorium ground floor seating for 300 students with audiovisual booth on the west side.'
];

$resGood = validateProposalMeaningfulInput($goodPayload, true);
assertCheck("Meaningful proposal payload passes validation", $resGood['valid'] === true, json_encode($resGood['errors']));

echo "\n========================================================\n";
echo "6. API Subprocess Tests: api/validate-proposal-ai.php\n";
echo "========================================================\n";

// Helper to run endpoint as CLI subprocess with simulated faculty session
function runEndpoint(string $scriptPath, array $postData, array $sessionData = []): array {
    $phpCode = '
    $_SERVER["REQUEST_METHOD"] = "POST";
    $_SERVER["DOCUMENT_ROOT"] = "C:/xampp/htdocs/sti-activity-system";
    $_POST = ' . var_export($postData, true) . ';
    $_REQUEST = $_POST;
    session_start();
    $_SESSION = ' . var_export($sessionData, true) . ';
    ob_start();
    require ' . var_export($scriptPath, true) . ';
    $output = ob_get_clean();
    echo $output;
    ';

    $tempScript = __DIR__ . '/scratch_test_runner.php';
    file_put_contents($tempScript, "<?php\n" . $phpCode);
    $output = (string)shell_exec('php ' . escapeshellarg($tempScript));
    @unlink($tempScript);
    $jsonStart = strpos($output, '{');
    $json = ($jsonStart !== false) ? json_decode(substr($output, $jsonStart), true) : null;
    return ['raw' => $output, 'json' => $json];
}

$db = getDB();
$facUser = $db->query("SELECT * FROM users WHERE role='faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$facSession = [
    'user_id'              => $facUser['id'],
    'user_role'            => 'faculty',
    'role'                 => 'faculty',
    'user_name'            => $facUser['name'],
    'name'                 => $facUser['name'],
    'user_email'           => $facUser['email'],
    'email'                => $facUser['email'],
    'must_change_password' => 0
];

// Test 6.1: validate-proposal-ai.php rejects empty required fields
$emptyPost = ['title' => ''];
$respEmpty = runEndpoint(__DIR__ . '/../api/validate-proposal-ai.php', $emptyPost, $facSession);
assertCheck("validate-proposal-ai.php flags missing required fields",
    isset($respEmpty['json']['validation_passed']) && $respEmpty['json']['validation_passed'] === false && strpos($respEmpty['json']['error'], 'complete all required fields') !== false
);

// Test 6.2: validate-proposal-ai.php rejects filled but meaningless content (asdf, qwerty, 12345)
$meaninglessPost = [
    'title' => 'asdf',
    'event_date' => date('Y-m-d', strtotime('+3 days')),
    'start_time' => '09:00',
    'end_time' => '12:00',
    'venue' => '12345',
    'target_participants' => 100,
    'theme' => 'qwerty',
    'general_objectives' => 'a',
    'specific_objectives' => '7',
    'involved_subjects' => 'asdf',
    'rationale' => 'qwerty'
];
$respMeaningless = runEndpoint(__DIR__ . '/../api/validate-proposal-ai.php', $meaninglessPost, $facSession);
assertCheck("validate-proposal-ai.php rejects meaningless content (asdf, 12345, qwerty)",
    isset($respMeaningless['json']['validation_passed']) &&
    $respMeaningless['json']['validation_passed'] === false &&
    strpos($respMeaningless['json']['error'], 'insufficient or meaningless text') !== false
);

// Test 6.2b: validate-proposal-ai.php specifically rejects 'asd' as title
$asdPost = $meaninglessPost;
$asdPost['title'] = 'asd';
$respAsd = runEndpoint(__DIR__ . '/../api/validate-proposal-ai.php', $asdPost, $facSession);
assertCheck("validate-proposal-ai.php specifically rejects 'asd' as title",
    isset($respAsd['json']['validation_passed']) &&
    $respAsd['json']['validation_passed'] === false &&
    strpos($respAsd['json']['error'], 'insufficient or meaningless text') !== false
);

// Test 6.3: validate-proposal-ai.php accepts legitimate one-word title/venue and meaningful text
$validPost = [
    'title' => 'Hackathon',
    'event_date' => date('Y-m-d', strtotime('+7 days')),
    'start_time' => '08:00',
    'end_time' => '17:00',
    'venue' => 'Gymnasium',
    'target_participants' => 200,
    'theme' => 'Building Next-Generation Scalable Software Solutions',
    'general_objectives' => 'To promote collaborative algorithmic problem-solving and software development skills.',
    'specific_objectives' => '1. Complete coding challenges. 2. Implement automated test suites. 3. Present project solutions to judges.',
    'involved_subjects' => 'ITE314, CS202',
    'rationale' => 'Hackathons are essential hands-on learning experiences that bridge classroom theory with industry practices.'
];
$respValid = runEndpoint(__DIR__ . '/../api/validate-proposal-ai.php', $validPost, $facSession);
assertCheck("validate-proposal-ai.php passes meaningful payload with one-word title 'Hackathon' and venue 'Gymnasium'",
    isset($respValid['json']['validation_passed']) && $respValid['json']['validation_passed'] === true
);

echo "\n========================================================\n";
echo "7. Backend Subprocess Tests: api/proposal-save.php (Direct Submission Gating)\n";
echo "========================================================\n";

// Test 7.1: proposal-save.php rejects submission with meaningless rationale
function runSaveEndpoint(string $scriptPath, array $postData, array $sessionData = []): string {
    $code = file_get_contents($scriptPath);
    $replacedCode = preg_replace('/\bheader\s*\(/', 'mock_header(', $code);

    $runner = '<?php
    function mock_header($h) {
        echo "REDIRECT_HEADER: " . $h . "\n";
    }
    $_SERVER["REQUEST_METHOD"] = "POST";
    $_SERVER["DOCUMENT_ROOT"] = "C:/xampp/htdocs/sti-activity-system";
    $_POST = ' . var_export($postData, true) . ';
    $_REQUEST = $_POST;
    session_start();
    $_SESSION = ' . var_export($sessionData, true) . ';
    ?>' . $replacedCode;

    $tempScript = __DIR__ . '/scratch_save_runner.php';
    file_put_contents($tempScript, $runner);
    $output = (string)shell_exec('php ' . escapeshellarg($tempScript) . ' 2>&1');
    @unlink($tempScript);
    return $output;
}

$badSavePost = [
    'action' => 'submit',
    'event_date' => date('Y-m-d', strtotime('+10 days')),
    'start_time' => '09:00',
    'end_time' => '12:00',
    'title' => 'asdf',
    'venue' => 'Auditorium',
    'theme' => 'Valid Theme Here For Testing Purposes',
    'general_objectives' => 'Valid Objectives for the Activity with Enough Length',
    'specific_objectives' => 'Valid Specific Objectives for the Activity with Enough Length',
    'rationale' => 'asdf',
    'target_participants' => 50,
    'kpi_indicator' => ['Number of attendees']
];

$saveHeaders = runSaveEndpoint(__DIR__ . '/../api/proposal-save.php', $badSavePost, $facSession);
assertCheck("proposal-save.php redirects with error=invalid_meaningful_input for submit with 'asdf'",
    strpos($saveHeaders, 'error=invalid_meaningful_input') !== false
);

// Test 7.1b: proposal-save.php specifically rejects 'asd' as title
$asdSavePost = $badSavePost;
$asdSavePost['title'] = 'asd';
$asdSavePost['rationale'] = 'Valid Rationale for the Activity with Enough Substantive Length';
$saveHeadersAsd = runSaveEndpoint(__DIR__ . '/../api/proposal-save.php', $asdSavePost, $facSession);
assertCheck("proposal-save.php redirects with error=invalid_meaningful_input for submit with title 'asd'",
    strpos($saveHeadersAsd, 'error=invalid_meaningful_input') !== false
);

// Test 7.2: proposal-update.php rejects resubmission with meaningless title
$tmpStmt = $db->prepare("INSERT INTO activities (faculty_id, title, status, event_date, start_time, end_time, created_at, updated_at) VALUES (?, 'Test Draft Activity', 'draft', '2026-11-01', '09:00', '12:00', NOW(), NOW())");
$tmpStmt->execute([$facUser['id']]);
$tempActId = (int)$db->lastInsertId();

$badUpdatePost = $badSavePost;
$badUpdatePost['activity_id'] = $tempActId;
$updateHeaders = runSaveEndpoint(__DIR__ . '/../api/proposal-update.php', $badUpdatePost, $facSession);

// Clean up temporary draft activity
$db->exec("DELETE FROM activities WHERE id = " . $tempActId);

assertCheck("proposal-update.php redirects with error=invalid_meaningful_input for submit with 'asdf'",
    strpos($updateHeaders, 'error=invalid_meaningful_input') !== false
);

echo "\n========================================================\n";
echo "SUMMARY: {$passedTests} / {$totalTests} tests passed.\n";
echo "========================================================\n";

if ($passedTests === $totalTests) {
    echo "SUCCESS: All meaningful-input validation tests passed successfully!\n";
    exit(0);
} else {
    echo "ERROR: Some tests failed.\n";
    exit(1);
}
