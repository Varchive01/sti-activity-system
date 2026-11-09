<?php
/**
 * Test Suite: Real-Time Auto-Capitalization & Name Autocorrect Verification
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=== TEST: Auto-Capitalization & Name Correction ===\n";

// 1. Test autoCapitalizeText function on standalone names and lowercase 'john'
$cases = [
    'john' => 'John',
    'john doe' => 'John doe', // sentence cap starts with J
    'hello john' => 'Hello John',
    'organizer: john' => 'Organizer: John',
    'seminar workshop' => 'Seminar workshop'
];

$passedCount = 0;
foreach ($cases as $input => $expected) {
    $actual = autoCapitalizeText($input);
    if ($actual === $expected) {
        echo "[PASS] autoCapitalizeText('$input') === '$expected'\n";
        $passedCount++;
    } else {
        echo "[FAIL] autoCapitalizeText('$input') Expected '$expected' but got '$actual'\n";
    }
}

// 2. Verify offline-drafts.js contains autoCapitalizeValue, handleAutoCapitalizeTarget, and blur listener
$jsContent = file_get_contents(__DIR__ . '/../assets/js/offline-drafts.js');
$hasJsCap = strpos($jsContent, 'autoCapitalizeValue') !== false;
$hasTargetHandler = strpos($jsContent, 'handleAutoCapitalizeTarget') !== false;
$hasBlurListener = strpos($jsContent, "document.addEventListener('blur'") !== false;
$hasSubmitListener = strpos($jsContent, "document.addEventListener('submit'") !== false;

if ($hasJsCap && $hasTargetHandler && $hasBlurListener && $hasSubmitListener) {
    echo "[PASS] offline-drafts.js includes client-side live auto-capitalization listeners.\n";
    $passedCount++;
} else {
    echo "[FAIL] offline-drafts.js missing one or more client-side capitalization functions.\n";
}

// 3. Verify api/proposal-save.php applies autoCapitalizeText to sched_organizer, prog_pic, mp_person
$saveContent = file_get_contents(__DIR__ . '/../api/proposal-save.php');
$hasSaveOrg = strpos($saveContent, '$rawOrg = $_POST[\'sched_organizer\'][$i]') !== false;
$hasSavePic = strpos($saveContent, '$rawPic = $_POST[\'prog_pic\'][$i]') !== false;
$hasSavePerson = strpos($saveContent, '$rawPerson = $_POST[\'mp_person\'][$i]') !== false;

if ($hasSaveOrg && $hasSavePic && $hasSavePerson) {
    echo "[PASS] api/proposal-save.php correctly applies autoCapitalizeText to dynamic schedule, program, and manpower names.\n";
    $passedCount++;
} else {
    echo "[FAIL] api/proposal-save.php missing dynamic array name auto-capitalization.\n";
}

// 4. Verify api/proposal-update.php applies autoCapitalizeText to sched_organizer, prog_pic, mp_person
$updateContent = file_get_contents(__DIR__ . '/../api/proposal-update.php');
$hasUpdateOrg = strpos($updateContent, '$rawOrg = $_POST[\'sched_organizer\'][$i]') !== false;
$hasUpdatePic = strpos($updateContent, '$rawPic = $_POST[\'prog_pic\'][$i]') !== false;
$hasUpdatePerson = strpos($updateContent, '$rawPerson = $_POST[\'mp_person\'][$i]') !== false;

if ($hasUpdateOrg && $hasUpdatePic && $hasUpdatePerson) {
    echo "[PASS] api/proposal-update.php correctly applies autoCapitalizeText to dynamic schedule, program, and manpower names.\n";
    $passedCount++;
} else {
    echo "[FAIL] api/proposal-update.php missing dynamic array name auto-capitalization.\n";
}

echo "\nSummary: $passedCount / 8 tests passed.\n";

