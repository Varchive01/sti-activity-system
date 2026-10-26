<?php
/**
 * tests/test_gibberish_detection.php
 *
 * Automated verification test suite for reliable gibberish detection.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/gibberish_detector.php';

function assertCheck(string $label, bool $passed, string $extra = '') {
    echo ($passed ? "[PASS] " : "[FAIL] ") . $label . ($extra ? " ($extra)" : "") . "\n";
    if (!$passed) {
        exit(1);
    }
}

echo "=== 1. Obvious Keyboard Mashing Rejection ===\n";
assertCheck("Reject 'fsdfsd'", isGibberishToken('fsdfsd') === true);
assertCheck("Reject 'asdf'", isGibberishToken('asdf') === true);
assertCheck("Reject 'asdd'", isGibberishToken('asdd') === true);
assertCheck("Reject 'qwerty'", isGibberishToken('qwerty') === true);
assertCheck("Reject 'asdasjfss'", isGibberishToken('asdasjfss') === true);
assertCheck("Reject 'asdfghjkllkjhgfdsa'", isGibberishToken('asdfghjkllkjhgfdsa') === true);
assertCheck("Reject 'aaaaaa'", isGibberishToken('aaaaaa') === true);
assertCheck("Reject 'asdasdasd'", isGibberishToken('asdasdasd') === true);

echo "\n=== 2. Arbitrary Character Suffix/Prefix to Gibberish ===\n";
assertCheck("Reject 'asdasjfss 123'", validateProposalFieldText('asdasjfss 123', 'descriptive') !== null);
assertCheck("Reject 'fsdfsd123'", validateProposalFieldText('fsdfsd123', 'general') !== null);
assertCheck("Reject 'asdd 99'", validateProposalFieldText('asdd 99', 'general') !== null);
assertCheck("Reject 'Prefix asdf suffix'", validateProposalFieldText('Prefix asdf suffix', 'descriptive') !== null);
assertCheck("Reject 'qwerty123'", validateProposalFieldText('qwerty123', 'descriptive') !== null);

echo "\n=== 3. Meaningful Sentences Acceptance ===\n";
$sentence1 = "To introduce incoming freshman students to university policies, support structures, and the academic grading system.";
assertCheck("Accept freshman orientation objective", validateProposalFieldText($sentence1, 'descriptive') === null);

$sentence2 = "Students will build collaborative web applications using modern programming frameworks.";
assertCheck("Accept web development objective", validateProposalFieldText($sentence2, 'descriptive') === null);

echo "\n=== 4. Legitimate Short Titles and Organization Names ===\n";
assertCheck("Accept 'Web Development Workshop'", validateProposalFieldText('Web Development Workshop', 'general') === null);
assertCheck("Accept 'Hackathon'", validateProposalFieldText('Hackathon', 'general') === null);
assertCheck("Accept 'Seminar'", validateProposalFieldText('Seminar', 'general') === null);
assertCheck("Accept 'Gymnasium'", validateProposalFieldText('Gymnasium', 'general') === null);
assertCheck("Accept course code 'CS101'", validateProposalFieldText('CS101', 'general') === null);
assertCheck("Accept course code 'ITE314'", validateProposalFieldText('ITE314', 'general') === null);

echo "\n=== 5. Venue and Address Verification ===\n";
assertCheck("Accept '123 Rizal Street, Marikina City'", validateProposalFieldText('123 Rizal Street, Marikina City', 'general') === null);
assertCheck("Accept 'STI College Marikina'", validateProposalFieldText('STI College Marikina', 'general') === null);

echo "\n=== 6. Full Proposal Payload Validation ===\n";
$badPayload = [
    'title' => 'asdf',
    'venue' => 'STI College Marikina',
    'venue_address' => '123 Rizal Street, Marikina City',
    'theme' => 'Empowering Youth',
    'general_objectives' => 'asdasjfss 123',
    'specific_objectives' => 'To learn modern web development techniques.',
    'involved_subjects' => 'ITE314',
    'rationale' => 'This event provides essential practical skills for student development.',
    'floor_plan_notes' => 'Entrance at north wing with 50 chairs in the center.',
    'guidelines_mechanics' => 'Participants must register before 8:00 AM.',
    'guidelines_criteria' => 'Relevance 40%, Creativity 30%, Delivery 30%.',
    'guidelines_scoring' => 'Judges will score out of 100 points.',
    'guidelines_awards' => 'Top 3 winners receive certificates.',
    'mat_item' => ['fsdfsd', 'Whiteboard Marker'],
    'mp_person' => ['John Doe', 'asdd']
];

$resBad = validateProposalGibberish($badPayload, true);
assertCheck("Payload with 'asdf' title and 'asdasjfss' objectives is flagged", $resBad['valid'] === false);
assertCheck("Title flagged in errors", isset($resBad['errors']['title']));
assertCheck("General objectives flagged in errors", isset($resBad['errors']['general_objectives']));
assertCheck("Material item table gibberish flagged", isset($resBad['errors']['mat_item_0']));
assertCheck("Manpower person table gibberish flagged", isset($resBad['errors']['mp_person_1']));

$goodPayload = [
    'title' => 'Web Development Skills Workshop',
    'venue' => 'STI College Marikina Gymnasium',
    'venue_address' => '123 Rizal Street, Marikina City',
    'theme' => 'Empowering Youth in Tech',
    'general_objectives' => 'To provide students with hands-on full-stack development experience.',
    'specific_objectives' => 'To learn modern web development techniques and build an application.',
    'involved_subjects' => 'ITE314',
    'rationale' => 'This event provides essential practical skills for student development.',
    'floor_plan_notes' => 'Entrance at north wing with 50 chairs in the center.',
    'guidelines_mechanics' => 'Participants must register before 8:00 AM and follow instructions.',
    'guidelines_criteria' => 'Relevance 40%, Creativity 30%, Delivery 30%.',
    'guidelines_scoring' => 'Judges will score each team out of 100 total points.',
    'guidelines_awards' => 'Top 3 winners receive certificates and tokens.',
    'mat_item' => ['Bond Paper', 'Whiteboard Marker'],
    'mp_person' => ['John Doe', 'Jane Smith'],
    'sched_event' => ['Opening Remarks', 'Hackathon Proper'],
    'ft_name' => ['Prof. Santos', 'Engr. Cruz']
];

$resGood = validateProposalGibberish($goodPayload, true);
assertCheck("Valid proposal payload passes completely", $resGood['valid'] === true);

echo "\n=======================================================\n";
echo "SUCCESS: ALL GIBBERISH DETECTION VERIFICATION TESTS PASSED\n";
echo "=======================================================\n";
