<?php
/**
 * Focused Verification Suite for api/create-google-form.php
 *
 * Verifies:
 * 1. Valid activity & questionnaire creation with mocked Google Forms API.
 * 2. Successful URL persistence to activities.evaluation_method in the database.
 * 3. Missing activity rejection (HTTP 404).
 * 4. Unauthorized user rejection (unauthenticated, wrong faculty, invalid role).
 * 5. Missing / invalid questionnaire handling (null, malformed JSON, empty list, invalid items).
 * 6. Google Form creation failure handling.
 * 7. Batch-update failure handling.
 * 8. Duplicate form protection (rejects if form already exists).
 * 9. Credential / token masking and leak protection.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "========================================================================\n";
echo "  CREATE GOOGLE FORM ENDPOINT UNIT & SECURITY VERIFICATION\n";
echo "========================================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $title, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$title}\n";
        $passed++;
    } else {
        echo " [FAIL] {$title}" . ($detail ? " - {$detail}" : "") . "\n";
        $failed++;
    }
}

$db = getDB();

// 1. Ensure test faculty users exist in DB
$faculty1 = $db->query("SELECT id, name, email, role FROM users WHERE role='faculty' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$faculty2 = $db->query("SELECT id, name, email, role FROM users WHERE role='faculty' AND id != " . (int)$faculty1['id'] . " ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$faculty1) {
    // Create a temporary faculty user if none exists
    $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Test Faculty 1', 'faculty1_test@sti.edu', 'hash', 'faculty', 'IT')")->execute();
    $f1Id = (int)$db->lastInsertId();
    $faculty1 = ['id' => $f1Id, 'name' => 'Test Faculty 1', 'email' => 'faculty1_test@sti.edu', 'role' => 'faculty'];
}

if (!$faculty2) {
    $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Test Faculty 2', 'faculty2_test@sti.edu', 'hash', 'faculty', 'CS')")->execute();
    $f2Id = (int)$db->lastInsertId();
    $faculty2 = ['id' => $f2Id, 'name' => 'Test Faculty 2', 'email' => 'faculty2_test@sti.edu', 'role' => 'faculty'];
}

// 2. Helper to create a temporary test activity
function createTestActivity(PDO $db, int $facultyId, string $title, ?string $evalQuestions, ?string $evalMethod = null): int
{
    $stmt = $db->prepare("
        INSERT INTO activities (
            faculty_id, title, description, theme, venue, event_date,
            start_time, end_time, source, status, evaluation_questions, evaluation_method
        ) VALUES (
            ?, ?, 'Test Description', 'Innovation', 'Gym', '2026-10-15',
            '08:00:00', '12:00:00', 'faculty', 'draft', ?, ?
        )
    ");
    $stmt->execute([$facultyId, $title, $evalQuestions, $evalMethod]);
    return (int)$db->lastInsertId();
}

// 3. Subprocess executor for api/create-google-form.php
function executeCreateGoogleForm(array $postData, ?array $userSession = null, ?array $mockResponses = null, ?string $mockAccessToken = null): array
{
    $script = __DIR__ . '/../api/create-google-form.php';
    $postExport = var_export($postData, true);
    $sessionExport = var_export($userSession, true);
    $responsesExport = var_export($mockResponses, true);
    $tokenExport = var_export($mockAccessToken ?? 'secret_mock_oauth_token_abc999', true);

    $code = "<?php
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../includes/GoogleOAuthService.php';
    require_once __DIR__ . '/../includes/GoogleFormsService.php';

    use GuzzleHttp\Client;
    use GuzzleHttp\Handler\MockHandler;
    use GuzzleHttp\HandlerStack;
    use GuzzleHttp\Psr7\Response;

    // Initialize session
    if (session_status() === PHP_SESSION_NONE) session_start();
    \$sessionData = {$sessionExport};
    if (\$sessionData !== null) {
        \$_SESSION['user_id'] = \$sessionData['id'] ?? null;
        \$_SESSION['user_role'] = \$sessionData['role'] ?? null;
        \$_SESSION['user_name'] = \$sessionData['name'] ?? 'Test User';
        \$_SESSION['user_email'] = \$sessionData['email'] ?? 'test@sti.edu';
        \$_SESSION['last_activity'] = time();
    } else {
        \$_SESSION = [];
    }

    // Set up mocked GoogleFormsService
    \$mockResponses = {$responsesExport};
    if (\$mockResponses !== null) {
        \$guzzleResponses = [];
        foreach (\$mockResponses as \$r) {
            \$guzzleResponses[] = new Response(
                \$r['status'],
                ['Content-Type' => 'application/json'],
                is_array(\$r['body']) ? json_encode(\$r['body']) : (string)\$r['body']
            );
        }

        \$mockHttp = new MockHandler(\$guzzleResponses);
        \$mockClient = new Client(['handler' => HandlerStack::create(\$mockHttp)]);

        \$token = {$tokenExport};
        \$oauthResponses = [];
        for (\$i = 0; \$i < 10; \$i++) {
            \$oauthResponses[] = new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => \$token,
                'expires_in'   => 3600,
                'token_type'   => 'Bearer'
            ]));
        }
        \$mockOAuthHttp = new MockHandler(\$oauthResponses);
        \$oauth = new GoogleOAuthService([
            'GOOGLE_CLIENT_ID'     => 'test-client-id',
            'GOOGLE_CLIENT_SECRET' => 'test-client-secret',
            'GOOGLE_REFRESH_TOKEN' => 'test-refresh-token',
        ], new Client(['handler' => HandlerStack::create(\$mockOAuthHttp)]));

        \$GLOBALS['testGoogleFormsService'] = new GoogleFormsService(\$oauth, \$mockClient);
    }

    \$_POST = {$postExport};
    \$_SERVER['REQUEST_METHOD'] = 'POST';

    try {
        require '{$script}';
    } catch (Throwable \$e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => \$e->getMessage()]);
    }
    ";

    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open('php', $desc, $pipes, __DIR__);
    if (!is_resource($proc)) {
        return ['status' => 500, 'body' => '', 'data' => null];
    }

    fwrite($pipes[0], $code);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    $decoded = json_decode($out, true);
    return [
        'exit_code' => $exitCode,
        'raw_body'  => $out,
        'err'       => $err,
        'data'      => is_array($decoded) ? $decoded : null,
    ];
}

// Sample valid questions
$validQuestions = [
    [
        'id'       => 'q1',
        'question' => 'How clear were the activity instructions?',
        'type'     => 'rating',
        'required' => true,
    ],
    [
        'id'       => 'q2',
        'question' => 'What suggestions do you have for future activities?',
        'type'     => 'open_ended',
        'required' => false,
    ]
];
$validQuestionsJson = json_encode($validQuestions);

$createdActivityIds = [];

try {
    // -------------------------------------------------------------------------
    // TEST 1: Valid activity & questionnaire (Happy path)
    // -------------------------------------------------------------------------
    echo "--- 1. Valid Activity & Questionnaire Test ---\n";

    $actId1 = createTestActivity($db, (int)$faculty1['id'], 'AI Tech Symposium 2026', $validQuestionsJson);
    $createdActivityIds[] = $actId1;

    $mockFormId = '1FAIpQLScForm123_XYZ';
    $mockResponderUri = 'https://docs.google.com/forms/d/e/1FAIpQLScForm123_XYZ/viewform';

    $mockResponses = [
        // 1. forms.create response
        [
            'status' => 200,
            'body'   => [
                'formId'       => $mockFormId,
                'responderUri' => $mockResponderUri,
                'info'         => ['title' => 'AI Tech Symposium 2026'],
            ],
        ],
        // 2. forms.batchUpdate response
        [
            'status' => 200,
            'body'   => [
                'replies' => [
                    ['createItem' => ['itemId' => '00000000', 'questionId' => ['google_q_sec']]],
                    ['createItem' => ['itemId' => '00000001', 'questionId' => ['google_q_001']]],
                    ['createItem' => ['itemId' => '00000002', 'questionId' => ['google_q_002']]],
                ],
            ],
        ],
    ];

    $res1 = executeCreateGoogleForm(['activity_id' => $actId1], $faculty1, $mockResponses);

    assertTest("1.1 Endpoint returns valid JSON", $res1['data'] !== null, $res1['raw_body']);
    assertTest("1.2 Endpoint returns success=true", ($res1['data']['success'] ?? false) === true);
    assertTest("1.3 Endpoint returns correct form_id", ($res1['data']['form_id'] ?? '') === $mockFormId);
    assertTest("1.4 Endpoint returns correct responder_uri", ($res1['data']['responder_uri'] ?? '') === $mockResponderUri);

    // -------------------------------------------------------------------------
    // TEST 2: Successful URL & Question ID persistence in database
    // -------------------------------------------------------------------------
    echo "\n--- 2. URL & Question ID Persistence Verification ---\n";

    $chkStmt = $db->prepare("SELECT evaluation_method, google_form_link, evaluation_questions FROM activities WHERE id = ?");
    $chkStmt->execute([$actId1]);
    $actRow = $chkStmt->fetch(PDO::FETCH_ASSOC);
    $savedMethod = $actRow['evaluation_method'] ?? '';
    $savedQuestions = json_decode($actRow['evaluation_questions'] ?? '[]', true);

    assertTest("2.1 Responder URL persisted in activities.evaluation_method", $savedMethod === $mockResponderUri, "Expected '{$mockResponderUri}', got '{$savedMethod}'");

    $hasGoogleQSec = isset($savedQuestions[0]['google_question_id']) && $savedQuestions[0]['google_question_id'] === 'google_q_sec';
    $hasGoogleQ1 = isset($savedQuestions[1]['google_question_id']) && $savedQuestions[1]['google_question_id'] === 'google_q_001';
    $hasGoogleQ2 = isset($savedQuestions[2]['google_question_id']) && $savedQuestions[2]['google_question_id'] === 'google_q_002';
    $isSectionQ = ($savedQuestions[0]['question'] ?? '') === 'Section' && ($savedQuestions[0]['type'] ?? '') === 'short_answer';
    $preservedQ1 = ($savedQuestions[1]['id'] ?? '') === 'q1' && ($savedQuestions[1]['type'] ?? '') === 'rating';
    $preservedQ2 = ($savedQuestions[2]['id'] ?? '') === 'q2' && ($savedQuestions[2]['type'] ?? '') === 'open_ended';

    assertTest("2.2 Google questionId persisted in activities.evaluation_questions", $hasGoogleQSec && $hasGoogleQ1 && $hasGoogleQ2);
    assertTest("2.3 Original question attributes and ordering preserved", $isSectionQ && $preservedQ1 && $preservedQ2);

    $savedFormLink = $actRow['google_form_link'] ?? '';
    assertTest("2.4 API formId persisted in activities.google_form_link", $savedFormLink === $mockFormId, "Expected '{$mockFormId}', got '{$savedFormLink}'");
    $hasGoogleFormIdOnQ = isset($savedQuestions[0]['google_form_id']) && $savedQuestions[0]['google_form_id'] === $mockFormId;
    assertTest("2.5 API formId persisted in activities.evaluation_questions metadata", $hasGoogleFormIdOnQ);

    // -------------------------------------------------------------------------
    // TEST 3: Duplicate form protection
    // -------------------------------------------------------------------------
    echo "\n--- 3. Duplicate Form Protection Tests ---\n";

    // Calling the endpoint again for an activity that already has a Google Form URL
    $resDup = executeCreateGoogleForm(['activity_id' => $actId1], $faculty1, []);

    assertTest("3.1 Duplicate creation rejected with success=false", ($resDup['data']['success'] ?? true) === false);
    assertTest("3.2 Duplicate creation returns clear message", str_contains($resDup['data']['error'] ?? '', 'already been created'));
    assertTest("3.3 Duplicate creation returns existing responder URI", ($resDup['data']['responder_uri'] ?? '') === $mockResponderUri);

    // -------------------------------------------------------------------------
    // TEST 4: Missing activity
    // -------------------------------------------------------------------------
    echo "\n--- 4. Missing Activity Tests ---\n";

    $resMissing = executeCreateGoogleForm(['activity_id' => 9999999], $faculty1, []);
    assertTest("4.1 Missing activity rejected with success=false", ($resMissing['data']['success'] ?? true) === false);
    assertTest("4.2 Missing activity error states 'Activity not found'", str_contains($resMissing['data']['error'] ?? '', 'Activity not found'));

    // -------------------------------------------------------------------------
    // TEST 5: Unauthorized user tests
    // -------------------------------------------------------------------------
    echo "\n--- 5. Authorization & Authentication Tests ---\n";

    $actId2 = createTestActivity($db, (int)$faculty1['id'], 'Faculty 1 Private Activity', $validQuestionsJson);
    $createdActivityIds[] = $actId2;

    // 5.1 Unauthenticated request (no session)
    $resNoAuth = executeCreateGoogleForm(['activity_id' => $actId2], null, []);
    assertTest("5.1 Unauthenticated user rejected with success=false", ($resNoAuth['data']['success'] ?? true) === false);
    assertTest("5.2 Unauthenticated error mentions authentication required", str_contains($resNoAuth['data']['error'] ?? '', 'Authentication required'));

    // 5.2 Wrong faculty user attempting to manage another faculty's activity
    $resWrongFac = executeCreateGoogleForm(['activity_id' => $actId2], $faculty2, []);
    assertTest("5.3 Faculty cannot manage another faculty's activity", ($resWrongFac['data']['success'] ?? true) === false);
    assertTest("5.4 Unauthorized faculty error mentions not authorized", str_contains($resWrongFac['data']['error'] ?? '', 'not authorized'));

    // 5.3 Invalid role (e.g. student)
    $invalidUser = ['id' => 999, 'role' => 'student', 'name' => 'Student'];
    $resInvalidRole = executeCreateGoogleForm(['activity_id' => $actId2], $invalidUser, []);
    assertTest("5.5 Unauthorized role rejected", ($resInvalidRole['data']['success'] ?? true) === false);

    // -------------------------------------------------------------------------
    // TEST 6: Missing / Invalid Questionnaire tests
    // -------------------------------------------------------------------------
    echo "\n--- 6. Questionnaire Validation Tests ---\n";

    // 6.1 NULL questionnaire
    $actNullQ = createTestActivity($db, (int)$faculty1['id'], 'Null Questions Activity', null);
    $createdActivityIds[] = $actNullQ;
    $resNullQ = executeCreateGoogleForm(['activity_id' => $actNullQ], $faculty1, []);
    assertTest("6.1 Missing/NULL questionnaire rejected", ($resNullQ['data']['success'] ?? true) === false && str_contains($resNullQ['data']['error'] ?? '', 'missing'));

    // 6.2 Malformed JSON
    $actMalformedQ = createTestActivity($db, (int)$faculty1['id'], 'Malformed JSON Activity', '{invalid_json[');
    $createdActivityIds[] = $actMalformedQ;
    $resMalformedQ = executeCreateGoogleForm(['activity_id' => $actMalformedQ], $faculty1, []);
    assertTest("6.2 Malformed JSON questionnaire rejected", ($resMalformedQ['data']['success'] ?? true) === false && str_contains($resMalformedQ['data']['error'] ?? '', 'invalid or malformed'));

    // 6.3 Empty questions array
    $actEmptyQ = createTestActivity($db, (int)$faculty1['id'], 'Empty Array Activity', '[]');
    $createdActivityIds[] = $actEmptyQ;
    $resEmptyQ = executeCreateGoogleForm(['activity_id' => $actEmptyQ], $faculty1, []);
    assertTest("6.3 Empty questionnaire rejected", ($resEmptyQ['data']['success'] ?? true) === false && str_contains($resEmptyQ['data']['error'] ?? '', 'no questions'));

    // 6.4 Question missing question text
    $invalidItemQ = json_encode([['id' => 'q1', 'question' => '', 'type' => 'rating']]);
    $actInvalidItem = createTestActivity($db, (int)$faculty1['id'], 'Blank Question Activity', $invalidItemQ);
    $createdActivityIds[] = $actInvalidItem;
    $resInvalidItem = executeCreateGoogleForm(['activity_id' => $actInvalidItem], $faculty1, []);
    assertTest("6.4 Blank question text rejected", ($resInvalidItem['data']['success'] ?? true) === false && str_contains($resInvalidItem['data']['error'] ?? '', 'missing question text'));

    // 6.5 Unsupported question type
    $unsupportedTypeQ = json_encode([['id' => 'q1', 'question' => 'Rate this', 'type' => 'date_picker']]);
    $actUnsupportedType = createTestActivity($db, (int)$faculty1['id'], 'Unsupported Type Activity', $unsupportedTypeQ);
    $createdActivityIds[] = $actUnsupportedType;
    $resUnsupportedType = executeCreateGoogleForm(['activity_id' => $actUnsupportedType], $faculty1, []);
    assertTest("6.5 Unsupported question type rejected", ($resUnsupportedType['data']['success'] ?? true) === false && str_contains($resUnsupportedType['data']['error'] ?? '', 'Unsupported question type'));

    // -------------------------------------------------------------------------
    // TEST 7: Google Form Creation Failure
    // -------------------------------------------------------------------------
    echo "\n--- 7. Google Form Creation Failure Tests ---\n";

    $actFailCreate = createTestActivity($db, (int)$faculty1['id'], 'Fail Create Activity', $validQuestionsJson);
    $createdActivityIds[] = $actFailCreate;

    $mockFailCreateResponses = [
        [
            'status' => 403,
            'body'   => [
                'error' => [
                    'code'    => 403,
                    'message' => 'The caller does not have permission to create forms in this project.',
                    'status'  => 'PERMISSION_DENIED',
                ],
            ],
        ],
    ];

    $resFailCreate = executeCreateGoogleForm(['activity_id' => $actFailCreate], $faculty1, $mockFailCreateResponses);
    assertTest("7.1 forms.create API failure returns success=false", ($resFailCreate['data']['success'] ?? true) === false);
    assertTest("7.2 Error indicates Google API failure message", str_contains($resFailCreate['data']['error'] ?? '', 'permission'));

    // Ensure database URL was NOT saved
    $chkStmt->execute([$actFailCreate]);
    $failCreateMethod = $chkStmt->fetchColumn();
    assertTest("7.3 evaluation_method remains NULL on create failure", empty($failCreateMethod));

    // -------------------------------------------------------------------------
    // TEST 8: Batch-Update Failure
    // -------------------------------------------------------------------------
    echo "\n--- 8. Google Forms batchUpdate Failure Tests ---\n";

    $actFailBatch = createTestActivity($db, (int)$faculty1['id'], 'Fail Batch Activity', $validQuestionsJson);
    $createdActivityIds[] = $actFailBatch;

    $mockFailBatchResponses = [
        [
            'status' => 200,
            'body'   => [
                'formId'       => '1FAIpQLScBatchFail_777',
                'responderUri' => 'https://docs.google.com/forms/d/e/1FAIpQLScBatchFail_777/viewform',
                'info'         => ['title' => 'Fail Batch Activity'],
            ],
        ],
        [
            'status' => 400,
            'body'   => [
                'error' => [
                    'code'    => 400,
                    'message' => 'Invalid scale bounds for question item.',
                    'status'  => 'INVALID_ARGUMENT',
                ],
            ],
        ],
    ];

    $resFailBatch = executeCreateGoogleForm(['activity_id' => $actFailBatch], $faculty1, $mockFailBatchResponses);
    assertTest("8.1 batchUpdate API failure returns success=false", ($resFailBatch['data']['success'] ?? true) === false);
    assertTest("8.2 batchUpdate error contains API error detail", str_contains($resFailBatch['data']['error'] ?? '', 'Invalid scale bounds'));

    // Ensure database URL was NOT saved
    $chkStmt->execute([$actFailBatch]);
    $failBatchMethod = $chkStmt->fetchColumn();
    assertTest("8.3 evaluation_method remains NULL on batchUpdate failure", empty($failBatchMethod));

    // -------------------------------------------------------------------------
    // TEST 9: Credential / Token Masking & Leak Prevention
    // -------------------------------------------------------------------------
    echo "\n--- 9. Credential & Token Protection Tests ---\n";

    $sensitiveAccessToken = 'super_secret_oauth_access_token_999888777';
    $actLeakCheck = createTestActivity($db, (int)$faculty1['id'], 'Security Check Activity', $validQuestionsJson);
    $createdActivityIds[] = $actLeakCheck;

    $mockLeakResponses = [
        [
            'status' => 400,
            'body'   => [
                'error' => [
                    'code'    => 400,
                    'message' => "Internal error near bearer token {$sensitiveAccessToken}",
                    'status'  => 'INVALID_ARGUMENT',
                ],
            ],
        ],
    ];

    $resLeak = executeCreateGoogleForm(['activity_id' => $actLeakCheck], $faculty1, $mockLeakResponses, $sensitiveAccessToken);
    $rawResponseText = $resLeak['raw_body'];

    assertTest("9.1 Sensitive access token is NOT leaked in API error response", !str_contains($rawResponseText, $sensitiveAccessToken));
    assertTest("9.2 Client secrets are NOT exposed in raw output", !str_contains($rawResponseText, 'test-client-secret'));

    // -------------------------------------------------------------------------
    // TEST 10: Unsaved Proposal Form Creation (activity_id: 0)
    // -------------------------------------------------------------------------
    echo "\n--- 10. Unsaved Proposal Form Creation Tests (activity_id: 0) ---\n";

    $mockUnsavedFormId = 'unsaved_form_xyz789';
    $mockUnsavedResponder = 'https://docs.google.com/forms/d/e/unsaved_form_xyz789/viewform';
    $mockUnsavedResponses = [
        [
            'status' => 200,
            'body'   => [
                'formId'       => $mockUnsavedFormId,
                'responderUri' => $mockUnsavedResponder,
                'revisionId'   => '00000001',
            ],
        ],
        [
            'status' => 200,
            'body'   => [
                'replies' => [
                    ['createItem' => ['itemId' => 'item_unsaved_sec', 'questionId' => ['q_unsaved_sec']]],
                    ['createItem' => ['itemId' => 'item_unsaved_1', 'questionId' => ['q_unsaved_1']]],
                    ['createItem' => ['itemId' => 'item_unsaved_2', 'questionId' => ['q_unsaved_2']]],
                ],
            ],
        ],
    ];

    $dbCountBefore = (int)$db->query("SELECT COUNT(*) FROM activities")->fetchColumn();

    $resUnsaved = executeCreateGoogleForm([
        'activity_id' => 0,
        'title'       => 'Unsaved New Proposal Activity',
        'questions'   => $validQuestions,
    ], $faculty1, $mockUnsavedResponses);

    $dbCountAfter = (int)$db->query("SELECT COUNT(*) FROM activities")->fetchColumn();

    assertTest("10.1 Unsaved proposal (activity_id: 0) returns success=true", ($resUnsaved['data']['success'] ?? false) === true);
    assertTest("10.2 Unsaved proposal returns valid responder_uri", ($resUnsaved['data']['responder_uri'] ?? '') === $mockUnsavedResponder);
    assertTest("10.3 Unsaved proposal returns questions_count", ($resUnsaved['data']['questions_count'] ?? 0) === 3);
    assertTest("10.4 Unsaved proposal does NOT create temporary or dummy activity rows in DB", $dbCountBefore === $dbCountAfter);

    // -------------------------------------------------------------------------
    // TEST 11: Existing Draft with In-Memory Edited Questions
    // -------------------------------------------------------------------------
    echo "\n--- 11. Existing Draft with Edited In-Memory Questions ---\n";

    $editedQuestions = [
        [
            'id'       => 'q_edit_1',
            'question' => 'How relevant was the updated course curriculum?',
            'type'     => 'rating',
            'required' => true,
        ],
        [
            'id'       => 'q_edit_2',
            'question' => 'What additional topics should be covered in the next session?',
            'type'     => 'open_ended',
            'required' => false,
        ],
    ];

    $actIdEdited = createTestActivity($db, (int)$faculty1['id'], 'Draft for Question Edit Test', $validQuestionsJson);
    $createdActivityIds[] = $actIdEdited;

    $mockEditedFormId = 'edited_form_draft_456';
    $mockEditedResponder = 'https://docs.google.com/forms/d/e/edited_form_draft_456/viewform';
    $mockEditedResponses = [
        [
            'status' => 200,
            'body'   => [
                'formId'       => $mockEditedFormId,
                'responderUri' => $mockEditedResponder,
                'revisionId'   => '00000001',
            ],
        ],
        [
            'status' => 200,
            'body'   => [
                'replies' => [
                    ['createItem' => ['itemId' => 'item_ed_sec', 'questionId' => ['q_ed_sec']]],
                    ['createItem' => ['itemId' => 'item_ed_1', 'questionId' => ['q_ed_1']]],
                    ['createItem' => ['itemId' => 'item_ed_2', 'questionId' => ['q_ed_2']]],
                ],
            ],
        ],
    ];

    $resEdited = executeCreateGoogleForm([
        'activity_id' => $actIdEdited,
        'title'       => 'Updated Draft Activity Title',
        'questions'   => $editedQuestions,
    ], $faculty1, $mockEditedResponses);

    assertTest("11.1 Existing draft with updated questions returns success=true", ($resEdited['data']['success'] ?? false) === true);
    assertTest("11.2 Existing draft returns newly generated responder_uri", ($resEdited['data']['responder_uri'] ?? '') === $mockEditedResponder);

    // Verify activity record in DB updated with new link and did not destroy existing activity fields
    $actCheck = $db->query("SELECT * FROM activities WHERE id = {$actIdEdited}")->fetch(PDO::FETCH_ASSOC);
    assertTest("11.3 Existing activity in DB updated with new evaluation_method link", ($actCheck['evaluation_method'] ?? '') === $mockEditedResponder);
    assertTest("11.4 Existing activity in DB updated with new google_form_link ID", ($actCheck['google_form_link'] ?? '') === $mockEditedFormId);
    assertTest("11.5 Existing activity title and description preserved in DB", ($actCheck['title'] ?? '') === 'Draft for Question Edit Test');

    // -------------------------------------------------------------------------
    // TEST 12: Empty In-Memory Questions Rejected
    // -------------------------------------------------------------------------
    echo "\n--- 12. Empty In-Memory Questions Rejected ---\n";

    $resEmpty = executeCreateGoogleForm([
        'activity_id' => 0,
        'title'       => 'No Questions Proposal',
        'questions'   => [],
    ], $faculty1, []);

    assertTest("12.1 Empty questions array rejected with success=false", ($resEmpty['data']['success'] ?? true) === false);
    assertTest("12.2 Rejection message instructs user to generate or add questions", str_contains($resEmpty['data']['error'] ?? '', 'questions'));

    // -------------------------------------------------------------------------
    // TEST 13: Section Question Integration & Deduplication Suite
    // -------------------------------------------------------------------------
    echo "\n--- 13. Section Question Integration & Deduplication Suite ---\n";

    // 13.1 Generation from Create Proposal includes Section question as item 0
    $actIdSecCreate = createTestActivity($db, (int)$faculty1['id'], 'Section Question Create Test', null);
    $createdActivityIds[] = $actIdSecCreate;

    $mockSecResponses1 = [
        ['status' => 200, 'body' => ['formId' => 'form_sec_001', 'responderUri' => 'https://docs.google.com/forms/d/e/form_sec_001/viewform']],
        ['status' => 200, 'body' => ['replies' => [
            ['createItem' => ['itemId' => 'item_sec_1', 'questionId' => ['q_sec_1']]],
            ['createItem' => ['itemId' => 'item_sec_2', 'questionId' => ['q_sec_2']]],
            ['createItem' => ['itemId' => 'item_sec_3', 'questionId' => ['q_sec_3']]],
        ]]],
    ];

    $resSecCreate = executeCreateGoogleForm([
        'activity_id' => 0,
        'title'       => 'Create Proposal With Section Question',
        'questions'   => $validQuestions,
    ], $faculty1, $mockSecResponses1);

    assertTest("13.1 Create proposal (activity_id: 0) includes Section question (questions_count: 3)", ($resSecCreate['data']['questions_count'] ?? 0) === 3);

    // 13.2 Generation from Edit Proposal persists Section question at index 0 in DB
    $resSecEdit = executeCreateGoogleForm([
        'activity_id' => $actIdSecCreate,
        'title'       => 'Edit Proposal With Section Question',
        'questions'   => $validQuestions,
    ], $faculty1, $mockSecResponses1);

    assertTest("13.2 Edit proposal (activity_id: > 0) succeeds", ($resSecEdit['data']['success'] ?? false) === true);

    $dbSecRow = $db->query("SELECT evaluation_questions FROM activities WHERE id = {$actIdSecCreate}")->fetch(PDO::FETCH_ASSOC);
    $dbSecQuestions = json_decode($dbSecRow['evaluation_questions'] ?? '[]', true);

    $firstQ = $dbSecQuestions[0] ?? [];
    $isSectionTitle = ($firstQ['question'] ?? '') === 'Section';
    $isShortAnswer = ($firstQ['type'] ?? '') === 'short_answer';
    $isRequired = ($firstQ['required'] ?? false) === true;
    $hasGoogleId = !empty($firstQ['google_question_id']);

    assertTest("13.3 Section question is first question (index 0) in DB", $isSectionTitle);
    assertTest("13.4 Section question type is short_answer", $isShortAnswer);
    assertTest("13.5 Section question is required (required: true)", $isRequired);
    assertTest("13.6 Section question has Google questionId assigned", $hasGoogleId);

    // 13.7 Verify no student name or student number question exists
    $hasNameOrStudentId = false;
    foreach ($dbSecQuestions as $qItem) {
        $text = strtolower(trim($qItem['question'] ?? ''));
        if (str_contains($text, 'student name') || str_contains($text, 'student number') || str_contains($text, 'student id')) {
            $hasNameOrStudentId = true;
            break;
        }
    }
    assertTest("13.7 Form does NOT ask for student name or student number", !$hasNameOrStudentId);

    // 13.8 Regeneration with Section question already in questionnaire does NOT create duplicate
    $mockSecResponses2 = [
        ['status' => 200, 'body' => ['formId' => 'form_sec_002', 'responderUri' => 'https://docs.google.com/forms/d/e/form_sec_002/viewform']],
        ['status' => 200, 'body' => ['replies' => [
            ['createItem' => ['itemId' => 'item_regen_1', 'questionId' => ['q_regen_1']]],
            ['createItem' => ['itemId' => 'item_regen_2', 'questionId' => ['q_regen_2']]],
            ['createItem' => ['itemId' => 'item_regen_3', 'questionId' => ['q_regen_3']]],
        ]]],
    ];

    // Pass $dbSecQuestions which ALREADY contains the Section question at index 0
    $resRegen = executeCreateGoogleForm([
        'activity_id' => $actIdSecCreate,
        'title'       => 'Regenerated Proposal Form',
        'questions'   => $dbSecQuestions,
        'force'       => true,
    ], $faculty1, $mockSecResponses2);

    assertTest("13.8 Regeneration succeeds with success=true", ($resRegen['data']['success'] ?? false) === true);
    assertTest("13.9 Regeneration does NOT duplicate Section question (count remains 3)", ($resRegen['data']['questions_count'] ?? 0) === 3);

    $dbRegenRow = $db->query("SELECT evaluation_questions FROM activities WHERE id = {$actIdSecCreate}")->fetch(PDO::FETCH_ASSOC);
    $dbRegenQuestions = json_decode($dbRegenRow['evaluation_questions'] ?? '[]', true);

    $sectionCount = 0;
    foreach ($dbRegenQuestions as $qItem) {
        if (strcasecmp(trim($qItem['question'] ?? ''), 'Section') === 0) {
            $sectionCount++;
        }
    }
    assertTest("13.10 Exactly ONE Section question present in regenerated questionnaire", $sectionCount === 1);

    // 13.11 Multiple duplicate Section questions in payload are cleaned up to exactly one
    $multiSectionQuestions = [
        ['question' => 'Section', 'type' => 'short_answer', 'required' => true],
        ['question' => 'How clear were the activity instructions?', 'type' => 'rating'],
        ['question' => 'Section', 'type' => 'short_answer', 'required' => true], // Duplicate
        ['question' => 'What suggestions do you have for future activities?', 'type' => 'open_ended'],
        ['question' => 'SECTION', 'type' => 'text', 'required' => true], // Another duplicate
    ];

    $resMultiSec = executeCreateGoogleForm([
        'activity_id' => 0,
        'title'       => 'Multi Section Dedup Test',
        'questions'   => $multiSectionQuestions,
    ], $faculty1, $mockSecResponses2);

    assertTest("13.11 Multiple duplicate Section questions deduplicated (questions_count: 3)", ($resMultiSec['data']['questions_count'] ?? 0) === 3);

} finally {
    // Teardown: Clean up temporary test activities
    if (!empty($createdActivityIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($createdActivityIds), '?'));
        $delStmt = $db->prepare("DELETE FROM activities WHERE id IN ({$inPlaceholders})");
        $delStmt->execute($createdActivityIds);
    }
}

echo "\n========================================================================\n";
echo " VERIFICATION SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
