<?php
/**
 * Focused Verification Suite for api/sync-google-form-responses.php
 *
 * Verifies:
 * 1. Valid Google Form response import (rating and open-ended).
 * 2. Multiple responses synchronization.
 * 3. Idempotency: repeating sync does not create duplicate entries.
 * 4. Preservation of existing manual records in kpi_evaluations.
 * 5. Authorization: unauthenticated and unauthorized faculty rejected.
 * 6. Missing activity or missing Google Form URL handling.
 * 7. Malformed answers handling (out-of-range rating, non-numeric).
 * 8. Google API failure handling without token leaks.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "========================================================================\n";
echo "  GOOGLE FORMS RESPONSE SYNCHRONIZATION FOCUSED VERIFICATION\n";
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

// 1. Fetch test faculty users
$faculty1 = $db->query("SELECT id, name, email, role FROM users WHERE role='faculty' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$faculty2 = $db->query("SELECT id, name, email, role FROM users WHERE role='faculty' AND id != " . (int)$faculty1['id'] . " ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$faculty1) {
    $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Sync Test Fac 1', 'sync1_test@sti.edu', 'hash', 'faculty', 'IT')")->execute();
    $faculty1 = ['id' => (int)$db->lastInsertId(), 'name' => 'Sync Test Fac 1', 'email' => 'sync1_test@sti.edu', 'role' => 'faculty'];
}
if (!$faculty2) {
    $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Sync Test Fac 2', 'sync2_test@sti.edu', 'hash', 'faculty', 'CS')")->execute();
    $faculty2 = ['id' => (int)$db->lastInsertId(), 'name' => 'Sync Test Fac 2', 'email' => 'sync2_test@sti.edu', 'role' => 'faculty'];
}

// 2. Helper to create a test activity
function createTestSyncActivity(PDO $db, int $facultyId, string $title, ?string $evalQuestions, ?string $evalMethod): int
{
    $stmt = $db->prepare("
        INSERT INTO activities (
            faculty_id, title, description, theme, venue, event_date,
            start_time, end_time, source, status, evaluation_questions, evaluation_method
        ) VALUES (
            ?, ?, 'Test Description', 'AI & Cloud', 'Auditorium', '2026-11-20',
            '09:00:00', '16:00:00', 'faculty', 'approved', ?, ?
        )
    ");
    $stmt->execute([$facultyId, $title, $evalQuestions, $evalMethod]);
    return (int)$db->lastInsertId();
}

// 3. Subprocess executor for api/sync-google-form-responses.php
function executeSyncGoogleForms(array $postData, ?array $userSession = null, ?array $mockResponses = null, ?string $mockAccessToken = null): array
{
    $script = __DIR__ . '/../api/sync-google-form-responses.php';
    $postExport = var_export($postData, true);
    $sessionExport = var_export($userSession, true);
    $responsesExport = var_export($mockResponses, true);
    $tokenExport = var_export($mockAccessToken ?? 'secret_mock_sync_token_456', true);

    $code = "<?php
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../includes/GoogleOAuthService.php';

    use GuzzleHttp\Client;
    use GuzzleHttp\Handler\MockHandler;
    use GuzzleHttp\HandlerStack;
    use GuzzleHttp\Psr7\Response;

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

        \$GLOBALS['testHttpClient'] = \$mockClient;
        \$GLOBALS['testOAuthService'] = \$oauth;
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
        return ['status' => 500, 'raw_body' => '', 'data' => null];
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

$gQId1 = '78f23a9b';
$gQId2 = '42c8d10e';

$evalQuestions = [
    [
        'id'                 => 'q1',
        'google_question_id' => $gQId1,
        'question'           => 'How effectively did the speaker explain complex concepts?',
        'type'               => 'rating',
        'required'           => true,
    ],
    [
        'id'                 => 'q2',
        'google_question_id' => $gQId2,
        'question'           => 'What was the most valuable takeaway from this session?',
        'type'               => 'open_ended',
        'required'           => false,
    ]
];
$evalQuestionsJson = json_encode($evalQuestions);
$formId1 = '1FAIpQLScFormSync_AAA111';
$formUrl1 = "https://docs.google.com/forms/d/e/{$formId1}/viewform";

$createdActivityIds = [];

try {
    // -------------------------------------------------------------------------
    // 1. Valid response import (rating and open_ended)
    // -------------------------------------------------------------------------
    echo "--- 1. Valid Response Import (Rating & Open-Ended) ---\n";

    $actId1 = createTestSyncActivity($db, (int)$faculty1['id'], 'Cloud Architecture Workshop', $evalQuestionsJson, $formUrl1);
    $createdActivityIds[] = $actId1;

    // Existing pre-sync record in kpi_evaluations to verify preservation
    $preInsert = $db->prepare("
        INSERT INTO kpi_evaluations (activity_id, criteria, rating, comments, evaluator_name, evaluated_at)
        VALUES (?, 'Existing Manual Metric', 4, 'Pre-existing manual evaluation', 'Facilitator Review', NOW())
    ");
    $preInsert->execute([$actId1]);
    $preExistingCount = (int)$db->query("SELECT COUNT(*) FROM kpi_evaluations WHERE activity_id = {$actId1}")->fetchColumn();

    $mockGoogleResponses1 = [
        [
            'status' => 200,
            'body'   => [
                'responses' => [
                    [
                        'responseId'        => 'resp_001',
                        'createTime'        => '2026-09-19T08:30:00Z',
                        'lastSubmittedTime' => '2026-09-19T08:30:00Z',
                        'respondentEmail'   => 'student.alpha@sti.edu',
                        'answers'           => [
                            $gQId1 => [
                                'questionId'  => $gQId1,
                                'textAnswers' => ['answers' => [['value' => '4']]],
                            ],
                            $gQId2 => [
                                'questionId'  => $gQId2,
                                'textAnswers' => ['answers' => [['value' => 'Understanding microservices was great.']]],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $res1 = executeSyncGoogleForms(['activity_id' => $actId1], $faculty1, $mockGoogleResponses1);

    assertTest("1.1 Sync returns valid JSON", $res1['data'] !== null, $res1['raw_body']);
    assertTest("1.2 Sync returns success=true", ($res1['data']['success'] ?? false) === true);
    assertTest("1.3 Synced count is 2 (1 rating, 1 open-ended)", ($res1['data']['synced_count'] ?? 0) === 2);

    // Verify records in DB
    $dbRecords = $db->prepare("SELECT criteria, rating, comments, evaluator_name FROM kpi_evaluations WHERE activity_id = ? ORDER BY id ASC");
    $dbRecords->execute([$actId1]);
    $rows = $dbRecords->fetchAll(PDO::FETCH_ASSOC);

    $ratingRow = null;
    $openRow = null;
    foreach ($rows as $r) {
        if ($r['criteria'] === 'How effectively did the speaker explain complex concepts?') $ratingRow = $r;
        if ($r['criteria'] === 'What was the most valuable takeaway from this session?') $openRow = $r;
    }

    assertTest("1.4 Rating answer stored with rating=4", $ratingRow !== null && (int)$ratingRow['rating'] === 4);
    assertTest("1.5 Open-ended answer stored with comments text", $openRow !== null && str_contains($openRow['comments'], 'microservices'));
    assertTest("1.6 Respondent ID tagged in evaluator_name", str_contains($ratingRow['evaluator_name'] ?? '', 'resp_001'));

    // -------------------------------------------------------------------------
    // 2. Preservation of existing records
    // -------------------------------------------------------------------------
    echo "\n--- 2. Preservation of Existing Records ---\n";

    $chkManual = $db->query("SELECT COUNT(*) FROM kpi_evaluations WHERE activity_id = {$actId1} AND criteria = 'Existing Manual Metric'")->fetchColumn();
    assertTest("2.1 Pre-existing manual records preserved without deletion", (int)$chkManual === 1);

    // -------------------------------------------------------------------------
    // 3. Idempotency (duplicate sync does not duplicate rows)
    // -------------------------------------------------------------------------
    echo "\n--- 3. Idempotency / Duplicate Protection Tests ---\n";

    $countBeforeSecondSync = (int)$db->query("SELECT COUNT(*) FROM kpi_evaluations WHERE activity_id = {$actId1}")->fetchColumn();

    // Re-run sync with identical mock response
    $resDup = executeSyncGoogleForms(['activity_id' => $actId1], $faculty1, $mockGoogleResponses1);

    $countAfterSecondSync = (int)$db->query("SELECT COUNT(*) FROM kpi_evaluations WHERE activity_id = {$actId1}")->fetchColumn();

    assertTest("3.1 Second sync succeeds without error", ($resDup['data']['success'] ?? false) === true);
    assertTest("3.2 Second sync reports 0 newly synced records", ($resDup['data']['synced_count'] ?? -1) === 0);
    assertTest("3.3 Second sync reports 2 skipped duplicate records", ($resDup['data']['skipped_count'] ?? -1) === 2);
    assertTest("3.4 Database row count unchanged on repeated sync", $countBeforeSecondSync === $countAfterSecondSync);

    // -------------------------------------------------------------------------
    // 4. Multiple responses import
    // -------------------------------------------------------------------------
    echo "\n--- 4. Multiple Responses Import Tests ---\n";

    $mockGoogleResponsesMultiple = [
        [
            'status' => 200,
            'body'   => [
                'responses' => [
                    // New respondent 2
                    [
                        'responseId'      => 'resp_002',
                        'respondentEmail' => 'student.beta@sti.edu',
                        'answers'         => [
                            $gQId1 => ['questionId' => $gQId1, 'textAnswers' => ['answers' => [['value' => '3']]]],
                            $gQId2 => ['questionId' => $gQId2, 'textAnswers' => ['answers' => [['value' => 'Good overview.']]]],
                        ],
                    ],
                    // New respondent 3
                    [
                        'responseId'      => 'resp_003',
                        'respondentEmail' => 'student.gamma@sti.edu',
                        'answers'         => [
                            $gQId1 => ['questionId' => $gQId1, 'textAnswers' => ['answers' => [['value' => '4']]]],
                            $gQId2 => ['questionId' => $gQId2, 'textAnswers' => ['answers' => [['value' => 'Excellent examples.']]]],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $resMulti = executeSyncGoogleForms(['activity_id' => $actId1], $faculty1, $mockGoogleResponsesMultiple);

    assertTest("4.1 Multiple responses sync successfully", ($resMulti['data']['success'] ?? false) === true);
    assertTest("4.2 Synced count is 4 for 2 new respondents", ($resMulti['data']['synced_count'] ?? 0) === 4);

    // -------------------------------------------------------------------------
    // 5. Malformed response handling
    // -------------------------------------------------------------------------
    echo "\n--- 5. Malformed Answer Handling Tests ---\n";

    $mockGoogleResponsesMalformed = [
        [
            'status' => 200,
            'body'   => [
                'responses' => [
                    [
                        'responseId'      => 'resp_004',
                        'respondentEmail' => 'student.delta@sti.edu',
                        'answers'         => [
                            // Invalid rating (out of 1-4 bounds: 99)
                            $gQId1 => ['questionId' => $gQId1, 'textAnswers' => ['answers' => [['value' => '99']]]],
                            // Valid open-ended
                            $gQId2 => ['questionId' => $gQId2, 'textAnswers' => ['answers' => [['value' => 'Nice session']]]],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $resMalformed = executeSyncGoogleForms(['activity_id' => $actId1], $faculty1, $mockGoogleResponsesMalformed);

    assertTest("5.1 Malformed rating handled safely without crash", ($resMalformed['data']['success'] ?? false) === true);
    assertTest("5.2 Malformed answer counted as malformed", ($resMalformed['data']['malformed_count'] ?? 0) === 1);
    assertTest("5.3 Valid open-ended in same submission was synced", ($resMalformed['data']['synced_count'] ?? 0) === 1);

    // -------------------------------------------------------------------------
    // 6. Authorization tests
    // -------------------------------------------------------------------------
    echo "\n--- 6. Authorization & Access Control Tests ---\n";

    // 6.1 Unauthenticated request
    $resUnauth = executeSyncGoogleForms(['activity_id' => $actId1], null, []);
    assertTest("6.1 Unauthenticated access returns success=false", ($resUnauth['data']['success'] ?? true) === false);
    assertTest("6.2 Unauthenticated error mentions authentication required", str_contains($resUnauth['data']['error'] ?? '', 'Authentication required'));

    // 6.2 Wrong faculty user
    $resWrongFac = executeSyncGoogleForms(['activity_id' => $actId1], $faculty2, []);
    assertTest("6.3 Unauthorized faculty cannot sync another faculty's activity", ($resWrongFac['data']['success'] ?? true) === false);
    assertTest("6.4 Unauthorized faculty error mentions not authorized", str_contains($resWrongFac['data']['error'] ?? '', 'not authorized'));

    // -------------------------------------------------------------------------
    // 7. Missing activity & Missing Google Form URL
    // -------------------------------------------------------------------------
    echo "\n--- 7. Missing Activity & Missing Form Tests ---\n";

    $resMissingAct = executeSyncGoogleForms(['activity_id' => 8888888], $faculty1, []);
    assertTest("7.1 Missing activity rejected with 404/not found", ($resMissingAct['data']['success'] ?? true) === false && str_contains($resMissingAct['data']['error'] ?? '', 'not found'));

    $actNoForm = createTestSyncActivity($db, (int)$faculty1['id'], 'Activity Without Form', $evalQuestionsJson, null);
    $createdActivityIds[] = $actNoForm;
    $resNoForm = executeSyncGoogleForms(['activity_id' => $actNoForm], $faculty1, []);
    assertTest("7.2 Activity without Google Form URL rejected", ($resNoForm['data']['success'] ?? true) === false && str_contains($resNoForm['data']['error'] ?? '', 'No Google Form'));

    // -------------------------------------------------------------------------
    // 8. Google API failure handling & token leak protection
    // -------------------------------------------------------------------------
    echo "\n--- 8. Google API Failure & Credential Protection Tests ---\n";

    $sensitiveToken = 'super_secret_sync_token_xyz888';
    $mockFailApi = [
        [
            'status' => 403,
            'body'   => [
                'error' => [
                    'code'    => 403,
                    'message' => "The caller does not have permission near token {$sensitiveToken}",
                    'status'  => 'PERMISSION_DENIED',
                ],
            ],
        ],
    ];

    $resFail = executeSyncGoogleForms(['activity_id' => $actId1], $faculty1, $mockFailApi, $sensitiveToken);

    assertTest("8.1 Google API error returns success=false", ($resFail['data']['success'] ?? true) === false);
    assertTest("8.2 Google API error details propagated safely", str_contains($resFail['data']['error'] ?? '', 'permission'));
    assertTest("8.3 Sensitive token is completely redacted from response", !str_contains($resFail['raw_body'], $sensitiveToken));

    // -------------------------------------------------------------------------
    // 9. Backward compatibility for legacy activities without google_question_id
    // -------------------------------------------------------------------------
    echo "\n--- 9. Legacy Question Backward Compatibility Tests ---\n";

    $legacyQuestions = [
        [
            'id'       => 'q1',
            'question' => 'Legacy Question Rating',
            'type'     => 'rating',
            'required' => true,
        ],
        [
            'id'       => 'q2',
            'question' => 'Legacy Question Comments',
            'type'     => 'open_ended',
            'required' => false,
        ]
    ];
    $legacyActId = createTestSyncActivity($db, (int)$faculty1['id'], 'Legacy Activity Workshop', json_encode($legacyQuestions), "https://docs.google.com/forms/d/e/legacy_form_123/viewform");
    $createdActivityIds[] = $legacyActId;

    $mockLegacyResponses = [
        [
            'status' => 200,
            'body'   => [
                'responses' => [
                    [
                        'responseId'        => 'legacy_resp_001',
                        'lastSubmittedTime' => '2026-09-19T09:00:00Z',
                        'respondentEmail'   => 'legacy.student@sti.edu',
                        'answers'           => [
                            'q1' => ['questionId' => 'q1', 'textAnswers' => ['answers' => [['value' => '3']]]],
                            'q2' => ['questionId' => 'q2', 'textAnswers' => ['answers' => [['value' => 'Legacy comment']]]],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $resLegacy = executeSyncGoogleForms(['activity_id' => $legacyActId], $faculty1, $mockLegacyResponses);

    assertTest("9.1 Legacy questions without google_question_id sync successfully", ($resLegacy['data']['success'] ?? false) === true);
    assertTest("9.2 Legacy sync imports both rating and open-ended records", ($resLegacy['data']['synced_count'] ?? 0) === 2);

    // -------------------------------------------------------------------------
    // 10. Realistic Google Question-ID Mapping Regression Test
    // -------------------------------------------------------------------------
    echo "\n--- 10. Realistic Google Question-ID Mapping Regression Test ---\n";

    $realGIdRating = 'google_assigned_scale_987123';
    $realGIdOpen   = 'google_assigned_text_456789';

    $regressionQuestions = [
        [
            'id'                 => 'local_metric_a',
            'google_question_id' => $realGIdRating,
            'question'           => 'Did the keynote meet the stated learning objectives?',
            'type'               => 'rating',
            'required'           => true,
        ],
        [
            'id'                 => 'local_metric_b',
            'google_question_id' => $realGIdOpen,
            'question'           => 'General feedback regarding the keynote delivery and topic',
            'type'               => 'open_ended',
            'required'           => false,
        ],
    ];
    $regressionActId = createTestSyncActivity($db, (int)$faculty1['id'], 'Keynote Evaluation Real Mapping', json_encode($regressionQuestions), "https://docs.google.com/forms/d/e/regression_form_456/viewform");
    $createdActivityIds[] = $regressionActId;

    $mockRealisticGoogleResponses = [
        [
            'status' => 200,
            'body'   => [
                'responses' => [
                    [
                        'responseId'        => 'real_resp_101',
                        'lastSubmittedTime' => '2026-09-22T10:00:00Z',
                        'respondentEmail'   => 'attendee.real@sti.edu',
                        // Answers keyed strictly by Google's generated question IDs (no local IDs or titles)
                        'answers'           => [
                            $realGIdRating => [
                                'questionId'  => $realGIdRating,
                                'textAnswers' => ['answers' => [['value' => '4']]],
                            ],
                            $realGIdOpen => [
                                'questionId'  => $realGIdOpen,
                                'textAnswers' => ['answers' => [['value' => 'The keynote was insightful and actionable.']]],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $resRegression = executeSyncGoogleForms(['activity_id' => $regressionActId], $faculty1, $mockRealisticGoogleResponses);

    assertTest("10.1 Real Google questionId sync returns success=true", ($resRegression['data']['success'] ?? false) === true);
    assertTest("10.2 Real Google questionId sync imported 2 records", ($resRegression['data']['synced_count'] ?? 0) === 2);

    // Verify database records in kpi_evaluations
    $regDbRecords = $db->prepare("SELECT criteria, rating, comments, evaluator_name FROM kpi_evaluations WHERE activity_id = ? ORDER BY id ASC");
    $regDbRecords->execute([$regressionActId]);
    $regRows = $regDbRecords->fetchAll(PDO::FETCH_ASSOC);

    $regRatingRow = null;
    $regOpenRow = null;
    foreach ($regRows as $r) {
        if ($r['criteria'] === 'Did the keynote meet the stated learning objectives?') $regRatingRow = $r;
        if ($r['criteria'] === 'General feedback regarding the keynote delivery and topic') $regOpenRow = $r;
    }

    assertTest("10.3 Rating record matched by google_question_id with rating=4", $regRatingRow !== null && (int)$regRatingRow['rating'] === 4);
    assertTest("10.4 Open-ended record matched by google_question_id with comments text", $regOpenRow !== null && str_contains($regOpenRow['comments'], 'insightful and actionable'));
    assertTest("10.5 Respondent email and responseId tagged in evaluator_name", str_contains($regRatingRow['evaluator_name'] ?? '', 'attendee.real@sti.edu') && str_contains($regRatingRow['evaluator_name'] ?? '', 'real_resp_101'));

} finally {
    // Teardown
    if (!empty($createdActivityIds)) {
        $in = implode(',', array_fill(0, count($createdActivityIds), '?'));
        $db->prepare("DELETE FROM kpi_evaluations WHERE activity_id IN ({$in})")->execute($createdActivityIds);
        $db->prepare("DELETE FROM activities WHERE id IN ({$in})")->execute($createdActivityIds);
    }
}

echo "\n========================================================================\n";
echo " VERIFICATION SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
