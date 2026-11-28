<?php
/**
 * tests/test_standard_time_parity.php
 * Automated verification test suite for Global Standard 12-Hour Time Format.
 */

require_once __DIR__ . '/../includes/functions.php';

$pass = 0;
$fail = 0;

function assertCondition(string $desc, bool $condition): void {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "[PASS] $desc\n";
    } else {
        $fail++;
        echo "[FAIL] $desc\n";
    }
}

echo "=== 1. PHP Syntax Validation for Modified Files ===\n";
$filesToCheck = [
    __DIR__ . '/../includes/functions.php',
    __DIR__ . '/../faculty/proposal-view.php',
    __DIR__ . '/../faculty/view-activity.php',
    __DIR__ . '/../admin1/view-activity.php',
    __DIR__ . '/../admin2/view-activity.php',
    __DIR__ . '/../dean/view-activity.php',
    __DIR__ . '/../admin1/review.php',
    __DIR__ . '/../admin2/review.php',
    __DIR__ . '/../dean/review.php',
    __DIR__ . '/../includes/post-event-view-template.php',
    __DIR__ . '/../includes/ProposalPdfService.php',
    __DIR__ . '/../includes/activity-print-document.php',
    __DIR__ . '/../services/GeminiProposalGuidanceService.php'
];

foreach ($filesToCheck as $file) {
    $rel = basename($file);
    exec("php -l \"$file\" 2>&1", $out, $ret);
    assertCondition("Syntax check: $rel", $ret === 0);
}

echo "\n=== 2. formatStandardTime() Unit Tests ===\n";
assertCondition("13:00:00 converts to 1:00 PM", formatStandardTime('13:00:00') === '1:00 PM');
assertCondition("08:30:00 converts to 8:30 AM", formatStandardTime('08:30:00') === '8:30 AM');
assertCondition("00:00:00 converts to 12:00 AM", formatStandardTime('00:00:00') === '12:00 AM');
assertCondition("12:00:00 converts to 12:00 PM", formatStandardTime('12:00:00') === '12:00 PM');
assertCondition("23:59:00 converts to 11:59 PM", formatStandardTime('23:59:00') === '11:59 PM');
assertCondition("1:30 PM is preserved", formatStandardTime('1:30 PM') === '1:30 PM');
assertCondition("8:00 am is uppercased to 8:00 AM", formatStandardTime('8:00 am') === '8:00 AM');
assertCondition("Empty string returns —", formatStandardTime('') === '—');
assertCondition("null returns —", formatStandardTime(null) === '—');
assertCondition("— returns —", formatStandardTime('—') === '—');
assertCondition("– returns —", formatStandardTime('–') === '—');
assertCondition("N/A returns —", formatStandardTime('N/A') === '—');
assertCondition("TBD returns —", formatStandardTime('TBD') === '—');
assertCondition("TBA returns —", formatStandardTime('TBA') === '—');

echo "\n=== 3. formatStandardTimeRange() Unit Tests ===\n";
assertCondition("13:00 to 17:00 converts to 1:00 PM – 5:00 PM", formatStandardTimeRange('13:00', '17:00') === '1:00 PM – 5:00 PM');
assertCondition("08:00:00 to 12:00:00 converts to 8:00 AM – 12:00 PM", formatStandardTimeRange('08:00:00', '12:00:00') === '8:00 AM – 12:00 PM');
assertCondition("Start time only returns start time", formatStandardTimeRange('13:00', null) === '1:00 PM');
assertCondition("End time only returns end time", formatStandardTimeRange(null, '17:00') === '5:00 PM');
assertCondition("Empty start and end returns —", formatStandardTimeRange('', '') === '—');
assertCondition("Both null returns —", formatStandardTimeRange(null, null) === '—');

echo "\n=== 4. formatStandardTimeSlot() Unit Tests ===\n";
assertCondition("13:00 - 15:00 converts to 1:00 PM – 3:00 PM", formatStandardTimeSlot('13:00 - 15:00') === '1:00 PM – 3:00 PM');
assertCondition("08:00 to 10:00 converts to 8:00 AM – 10:00 AM", formatStandardTimeSlot('08:00 to 10:00') === '8:00 AM – 10:00 AM');
assertCondition("08:30 AM - 11:30 AM converts to 8:30 AM – 11:30 AM", formatStandardTimeSlot('08:30 AM - 11:30 AM') === '8:30 AM – 11:30 AM');
assertCondition("Single 14:00 converts to 2:00 PM", formatStandardTimeSlot('14:00') === '2:00 PM');
assertCondition("Single 09:00:00 converts to 9:00 AM", formatStandardTimeSlot('09:00:00') === '9:00 AM');
assertCondition("Empty slot returns —", formatStandardTimeSlot('') === '—');
assertCondition("null slot returns —", formatStandardTimeSlot(null) === '—');

echo "\n=== 5. GeminiProposalGuidanceService Schedule Output Tests ===\n";
require_once __DIR__ . '/../services/GeminiProposalGuidanceService.php';
$svc = new GeminiProposalGuidanceService('DUMMY_KEY');
$fallback = $svc->getFallbackSectionSuggestions([
    'title' => 'Intramurals 2026',
    'start_time' => '08:00',
    'end_time' => '17:00'
], ['schedule']);

assertCondition("Fallback schedule is array", is_array($fallback['schedule']) && count($fallback['schedule']) > 0);
$allStandard = true;
foreach ($fallback['schedule'] as $item) {
    if (!preg_match('/(AM|PM)/', $item['time'])) {
        $allStandard = false;
        echo "Non-standard time found: " . $item['time'] . "\n";
    }
}
assertCondition("All fallback schedule times use 12-hour AM/PM format", $allStandard);

echo "\n=== 6. JS Script Parity Check ===\n";
$gcalContent = file_get_contents(__DIR__ . '/../assets/js/gcal-popup.js');
assertCondition("gcal-popup.js has formatStandardTimeSlot", str_contains($gcalContent, 'formatStandardTimeSlot'));

$scuContent = file_get_contents(__DIR__ . '/../assets/js/schedule-conflict-ui.js');
assertCondition("schedule-conflict-ui.js formats conf.start_time with 12h", str_contains($scuContent, 'formatTime12h(conf.start_time)'));

$phase2Content = file_get_contents(__DIR__ . '/../includes/phase2-proposal-enhancements.php');
assertCondition("phase2-proposal-enhancements.php has formatTimeRange12h", str_contains($phase2Content, 'formatTimeRange12h'));

$aiContent = file_get_contents(__DIR__ . '/../assets/js/ai-proposal-guidance.js');
assertCondition("ai-proposal-guidance.js has formatStandardTime", str_contains($aiContent, 'formatStandardTime'));
assertCondition("ai-proposal-guidance.js has convertTo24h", str_contains($aiContent, 'convertTo24h'));

echo "\n============================================\n";
echo "Results: $pass Passed, $fail Failed.\n";
echo "============================================\n";

if ($fail > 0) {
    exit(1);
}
