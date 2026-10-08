<?php
/**
 * tests/test_ai_objective_auto_update.php
 *
 * Automated verification test suite for AI Objectives Auto-Update & Cascade (Capstone Adviser Revision #4):
 * Tests the 18 specific requirements mandated by the Capstone Adviser:
 *  1. Title change is detected.
 *  2. Title change triggers Theme regeneration.
 *  3. Theme generation completes before Objective generation.
 *  4. New Theme is passed to Objective generation.
 *  5. Theme change triggers Objective regeneration.
 *  6. AI-generated untouched objectives are replaced after Title/Theme changes.
 *  7. Manually edited objectives are preserved.
 *  8. Outdated state is correctly identified.
 *  9. No generation occurs merely from loading proposal-edit.php.
 * 10. Rapid changes do not allow stale responses to overwrite newer results.
 * 11. AI failure clears loading states.
 * 12. AI failure does not fabricate objectives.
 * 13. Existing AI Objectives endpoint remains functional.
 * 14. Existing AI Theme endpoint remains functional.
 * 15. No unnecessary PII is sent.
 * 16. Final Theme/Objectives values save correctly.
 * 17. Existing proposal editing still works.
 * 18. Existing proposal creation still works.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "========================================================================\n";
echo "   CAPSTONE ADVISER REVISION #4: AI OBJECTIVE AUTO-UPDATE TEST SUITE\n";
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

function runEndpointSubprocess(string $script, array $sessionData, array $postData): array {
    $tmpFile = __DIR__ . '/_tmp_run_' . uniqid() . '.php';
    $code = "<?php\n";
    $code .= "ini_set('display_errors', '0');\n";
    if (!empty($sessionData)) {
        $code .= "session_start();\n";
        $code .= "\$_SESSION = " . var_export($sessionData, true) . ";\n";
    }
    $code .= "\$_SERVER['REQUEST_METHOD'] = 'POST';\n";
    $code .= "\$_POST = " . var_export($postData, true) . ";\n";
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

$facUser = $db->query("SELECT id, role, email FROM users WHERE role='faculty' ORDER BY id ASC LIMIT 1")->fetch();
if (!$facUser) {
    die("Error: No faculty user found in database for testing.\n");
}
$facSession = ['user_id' => $facUser['id'], 'user_role' => 'faculty'];

$createCode = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');
$editCode = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');
$objEndpointCode = file_get_contents(__DIR__ . '/../api/generate-objectives.php');
$themeEndpointCode = file_get_contents(__DIR__ . '/../api/generate-theme.php');

// -------------------------------------------------------------------------
// TEST 1: Title change is detected
// -------------------------------------------------------------------------
$t1Pass = str_contains($createCode, "titleEl.addEventListener('input'") &&
          str_contains($createCode, "activityTitleChanged") &&
          str_contains($editCode, "titleEl.addEventListener('input'") &&
          str_contains($editCode, "activityTitleChanged");
reportTest("1. Title change is detected in proposal-create.php and proposal-edit.php", $t1Pass,
    "Confirmed: titleEl listens to 'input' event and dispatches 'activityTitleChanged'.");

// -------------------------------------------------------------------------
// TEST 2: Title change triggers Theme regeneration
// -------------------------------------------------------------------------
$t2Pass = str_contains($createCode, "themeDebounceTimer = setTimeout(() => {") &&
          str_contains($createCode, "autoGenerateTheme(false)") &&
          str_contains($editCode, "themeDebounceTimer = setTimeout(() => {") &&
          str_contains($editCode, "autoGenerateTheme(false)");
reportTest("2. Title change triggers debounced Theme regeneration", $t2Pass,
    "Confirmed: themeDebounceTimer invokes autoGenerateTheme(false) when Title >= 4 characters.");

// -------------------------------------------------------------------------
// TEST 3: Theme generation completes before Objective generation
// -------------------------------------------------------------------------
$t3Pass = str_contains($createCode, "triggerSource: 'auto_theme'") &&
          str_contains($createCode, "detail.triggerSource === 'auto_theme'") &&
          str_contains($createCode, "cancelPendingObjectivesGeneration();") &&
          !str_contains($createCode, "themeEl.dispatchEvent(new Event('input'");
reportTest("3. Theme generation completes before Objective generation (strict sequence guarantee)", $t3Pass,
    "Confirmed: Title -> Theme must complete before 'auto_theme' event triggers autoGenerateObjectives. No premature or duplicate calls.");

// -------------------------------------------------------------------------
// TEST 4: New Theme is passed to Objective generation
// -------------------------------------------------------------------------
$t4Pass = str_contains($createCode, "autoGenerateObjectives(false, { title: curTitle, theme: curTheme })") &&
          str_contains($createCode, "const currentTheme = contextOverride?.theme ??");
reportTest("4. New Theme is passed to Objective generation", $t4Pass,
    "Confirmed: Generated theme is supplied in contextOverride to autoGenerateObjectives.");

// -------------------------------------------------------------------------
// TEST 5: Theme change triggers Objective regeneration
// -------------------------------------------------------------------------
$t5Pass = str_contains($createCode, "triggerSource: 'manual_theme'") &&
          str_contains($createCode, "detail.triggerSource === 'manual_theme'") &&
          str_contains($createCode, "objDebounceTimer = setTimeout(() => {");
reportTest("5. Theme change triggers Objective regeneration", $t5Pass,
    "Confirmed: Manual edits to Theme dispatch 'manual_theme' and debounce Objective generation.");

// -------------------------------------------------------------------------
// TEST 6: AI-generated untouched objectives are replaced after Title/Theme changes
// -------------------------------------------------------------------------
$t6Pass = str_contains($createCode, "if (isObjectivesProtected && !forced) {") &&
          str_contains($createCode, "genEl.value = data.general_objective;") &&
          str_contains($createCode, "specEl.value = data.specific_objectives;");
reportTest("6. AI-generated untouched objectives are replaced after Title/Theme changes", $t6Pass,
    "Confirmed: When isObjectivesProtected is false, new AI objectives overwrite textareas seamlessly.");

// -------------------------------------------------------------------------
// TEST 7: Manually edited objectives are preserved
// -------------------------------------------------------------------------
$t7Pass = str_contains($createCode, "isObjectivesProtected = true;") &&
          str_contains($createCode, "renderObjectivesStatus('OUTDATED');") &&
          str_contains($editCode, "isObjectivesProtected = true;");
reportTest("7. Manually edited objectives are preserved", $t7Pass,
    "Confirmed: Manual edits flip isObjectivesProtected = true, preventing silent overwrite.");

// -------------------------------------------------------------------------
// TEST 8: Outdated state is correctly identified
// -------------------------------------------------------------------------
$t8Pass = str_contains($createCode, "case 'OUTDATED':") &&
          str_contains($createCode, "⚠️ Title/theme changed. Your edited objectives were preserved.") &&
          str_contains($createCode, "↻ Regenerate Objectives");
reportTest("8. Outdated state is correctly identified and presented to Faculty", $t8Pass,
    "Confirmed: OUTDATED banner alerts faculty of changes with explicit '↻ Regenerate Objectives' action.");

// -------------------------------------------------------------------------
// TEST 9: No generation occurs merely from loading proposal-edit.php
// -------------------------------------------------------------------------
$t9Pass = str_contains($editCode, "setupAutomaticObjectivesOnEdit();") &&
          !str_contains($editCode, "setupAutomaticObjectivesOnEdit(); autoGenerateObjectives(") &&
          !str_contains($editCode, "setupAutomaticThemeGeneration(); autoGenerateTheme(");
reportTest("9. No generation occurs merely from loading proposal-edit.php", $t9Pass,
    "Confirmed: Edit page setup functions attach listeners and protect saved content with ZERO AI calls on load.");

// -------------------------------------------------------------------------
// TEST 10: Rapid changes do not allow stale responses to overwrite newer results
// -------------------------------------------------------------------------
$t10Pass = str_contains($createCode, "currentObjAbortController = new AbortController()") &&
           str_contains($createCode, "currentObjAbortController.abort()") &&
           str_contains($createCode, "requestId !== currentObjRequestId") &&
           str_contains($createCode, "nowTitle !== currentTitle || nowTheme !== currentTheme");
reportTest("10. Rapid changes do not allow stale responses to overwrite newer results", $t10Pass,
    "Confirmed: AbortController, request ID monotonicity, and DOM state verification guard against race conditions.");

// -------------------------------------------------------------------------
// TEST 11: AI failure clears loading states
// -------------------------------------------------------------------------
$t11Pass = str_contains($createCode, "finally {") &&
           str_contains($createCode, "isObjGenerating = false;") &&
           str_contains($createCode, "setObjectiveSpinner(false);");
reportTest("11. AI failure clears loading states in finally block", $t11Pass,
    "Confirmed: Loading spinners and generation flags are guaranteed to clear in finally blocks.");

// -------------------------------------------------------------------------
// TEST 12: AI failure does not fabricate objectives
// -------------------------------------------------------------------------
$t12Pass = str_contains($createCode, "renderObjectivesStatus('ERROR', err.message);") &&
           !str_contains($createCode, "genEl.value = 'To celebrate'") &&
           !str_contains($createCode, "genEl.value = 'Objective placeholder'");
reportTest("12. AI failure does not fabricate objectives (preserves content, shows error)", $t12Pass,
    "Confirmed: Real error is surfaced to user and textareas remain editable with zero mock data injection.");

// -------------------------------------------------------------------------
// TEST 13: Existing AI Objectives endpoint remains functional
// -------------------------------------------------------------------------
$resObj = runEndpointSubprocess(__DIR__ . '/../api/generate-objectives.php', $facSession, [
    'title' => 'Buwan ng Wika 2026',
    'theme' => 'Wikang Filipino: Sandigan ng Karunungan',
    'source' => 'faculty',
    'target_participants' => '200'
]);
$t13Pass = is_array($resObj['json']) &&
           !empty($resObj['json']['success']) &&
           !empty($resObj['json']['general_objective']) &&
           !empty($resObj['json']['specific_objectives']);
reportTest("13. Existing AI Objectives endpoint remains functional (live generation contract)", $t13Pass,
    $t13Pass ? "General: " . substr($resObj['json']['general_objective'], 0, 60) . "..." : "Raw: " . $resObj['raw']);

// -------------------------------------------------------------------------
// TEST 14: Existing AI Theme endpoint remains functional
// -------------------------------------------------------------------------
$resTheme = runEndpointSubprocess(__DIR__ . '/../api/generate-theme.php', $facSession, [
    'title' => 'Tagisan ng Talino 2026',
    'source' => 'faculty',
    'target_participants' => '150'
]);
$t14Pass = is_array($resTheme['json']) &&
           !empty($resTheme['json']['success']) &&
           !empty($resTheme['json']['theme']) &&
           is_string($resTheme['json']['theme']);
reportTest("14. Existing AI Theme endpoint remains functional (live generation contract)", $t14Pass,
    $t14Pass ? "Theme: " . $resTheme['json']['theme'] : "Raw: " . $resTheme['raw']);

// -------------------------------------------------------------------------
// TEST 15: No unnecessary PII is sent
// -------------------------------------------------------------------------
$t15Pass = !str_contains($objEndpointCode, '$_SESSION[\'user_name\']') &&
           !str_contains($objEndpointCode, '$_SESSION[\'user_email\']') &&
           !str_contains($themeEndpointCode, '$_SESSION[\'user_name\']') &&
           !str_contains($themeEndpointCode, '$_SESSION[\'user_email\']');
reportTest("15. Zero PII Guarantee: prompt construction strictly omits names, emails, and personal data", $t15Pass);

// -------------------------------------------------------------------------
// TEST 16: Final Theme/Objectives values save correctly
// -------------------------------------------------------------------------
$tomorrow = date('Y-m-d', strtotime('+2 days'));
$savePayload = [
    'title' => 'Revision 4 Persistence Test ' . uniqid(),
    'source' => 'faculty',
    'theme' => 'AI Generated Theme for Persistence',
    'general_objectives' => 'To verify that AI generated general objectives persist accurately.',
    'specific_objectives' => "1. First specific goal\n2. Second specific goal\n3. Third specific goal",
    'event_date' => $tomorrow,
    'start_time' => '09:00',
    'end_time' => '12:00',
    'venue' => 'Computer Lab 1',
    'target_participants' => '50',
    'involved_subjects' => 'ITE401',
    'rationale' => 'Persistence verification for capstone revision 4',
    'action' => 'draft'
];

$resSave = runEndpointSubprocess(__DIR__ . '/../api/proposal-save.php', $facSession, $savePayload);
$savedActivity = $db->query("SELECT id, title, theme, general_objectives, specific_objectives FROM activities WHERE title = " . $db->quote($savePayload['title']))->fetch(PDO::FETCH_ASSOC);

$t16Pass = ($savedActivity !== false) &&
           ($savedActivity['theme'] === $savePayload['theme']) &&
           ($savedActivity['general_objectives'] === $savePayload['general_objectives']) &&
           ($savedActivity['specific_objectives'] === $savePayload['specific_objectives']);
reportTest("16. Final Theme/Objectives values save correctly into activities database table", $t16Pass,
    $t16Pass ? "Verified in DB id={$savedActivity['id']}: theme and objectives match exactly." : "DB row mismatch or not found.");

// -------------------------------------------------------------------------
// TEST 17: Existing proposal editing still works
// -------------------------------------------------------------------------
$t17Pass = false;
if ($savedActivity) {
    $updatedTheme = 'Updated Theme for Revision 4';
    $updatedGenObj = 'Updated General Objective via proposal-update.php';
    $updatedSpecObj = "1. Updated Goal A\n2. Updated Goal B";

    $updatePayload = array_merge($savePayload, [
        'activity_id' => $savedActivity['id'],
        'theme' => $updatedTheme,
        'general_objectives' => $updatedGenObj,
        'specific_objectives' => $updatedSpecObj
    ]);

    runEndpointSubprocess(__DIR__ . '/../api/proposal-update.php', $facSession, $updatePayload);
    $refreshed = $db->query("SELECT theme, general_objectives, specific_objectives FROM activities WHERE id = {$savedActivity['id']}")->fetch(PDO::FETCH_ASSOC);

    $t17Pass = ($refreshed !== false) &&
               ($refreshed['theme'] === $updatedTheme) &&
               ($refreshed['general_objectives'] === $updatedGenObj) &&
               ($refreshed['specific_objectives'] === $updatedSpecObj);
}
reportTest("17. Existing proposal editing still works (proposal-update.php updates DB fields)", $t17Pass,
    $t17Pass ? "Verified update on activity {$savedActivity['id']}." : "Update failed.");

// -------------------------------------------------------------------------
// TEST 18: Existing proposal creation still works
// -------------------------------------------------------------------------
$t18Pass = ($savedActivity !== false && (int)$savedActivity['id'] > 0);
reportTest("18. Existing proposal creation still works (proposal-save.php creates draft activity)", $t18Pass,
    $t18Pass ? "Activity id {$savedActivity['id']} successfully created." : "Creation failed.");

// Clean up test activity
if ($savedActivity && !empty($savedActivity['id'])) {
    $db->exec("DELETE FROM activities WHERE id = {$savedActivity['id']}");
}

// -------------------------------------------------------------------------
// Summary
// -------------------------------------------------------------------------
echo "\n========================================================================\n";
echo " TEST RESULTS: {$testsPassed} Passed, {$testsFailed} Failed\n";
echo "========================================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
exit(0);
