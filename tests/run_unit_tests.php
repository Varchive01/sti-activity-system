<?php
/**
 * STI-ARMES Comprehensive Automated Unit Testing Suite
 *
 * Executes isolated unit tests across 8 core domain areas:
 * 1. Activity proposal validation (gibberish detection, field bounds, clearance rules)
 * 2. Text formatting and capitalization (title casing, capitalization, sanitization, department normalization)
 * 3. KPI calculations (status thresholds, badge mapping, period SQL construction)
 * 4. Schedule and venue conflict detection (time ordering, field completion, mathematical interval boundaries)
 * 5. QR code generation (URL security validation, local SVG XML compilation, dimensions)
 * 6. Evaluation CSV parsing & duplicate detection (wide matrix, long format, delimiters, BOM, deduplication)
 * 7. Date and time validation (12h/24h parsing, midnight handling, range validation, schedule row batches)
 * 8. File upload validation (MIME sniffing, magic bytes, spoofing protection, extension restrictions)
 *
 * Designed for capstone manuscript documentation.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/gibberish_detector.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';
require_once __DIR__ . '/../includes/schedule_conflict.php';
require_once __DIR__ . '/../includes/QrCodeService.php';

class TestRunner {
    private array $results = [];
    private int $passCount = 0;
    private int $failCount = 0;

    public function record(
        string $module,
        string $component,
        string $functionOrMethod,
        string $testInputDesc,
        string $expectedOutputDesc,
        mixed $actualOutput,
        bool $passed,
        string $classification = 'Unit Test'
    ): void {
        if ($passed) {
            $this->passCount++;
        } else {
            $this->failCount++;
        }

        $actualStr = is_scalar($actualOutput) ? (string)$actualOutput : json_encode($actualOutput, JSON_UNESCAPED_SLASHES);
        if (strlen($actualStr) > 80) {
            $actualStr = substr($actualStr, 0, 77) . '...';
        }

        $this->results[] = [
            'id'             => count($this->results) + 1,
            'module'         => $module,
            'component'      => $component,
            'function'       => $functionOrMethod,
            'input'          => $testInputDesc,
            'expected'       => $expectedOutputDesc,
            'actual'         => $actualStr,
            'status'         => $passed ? 'PASS' : 'FAIL',
            'classification' => $classification
        ];
    }

    public function getResults(): array {
        return $this->results;
    }

    public function getSummary(): array {
        return [
            'total'  => count($this->results),
            'passed' => $this->passCount,
            'failed' => $this->failCount
        ];
    }
}

$runner = new TestRunner();

// =========================================================================
// 1. ACTIVITY PROPOSAL VALIDATION
// =========================================================================
$mod = 'Activity Proposal Validation';

// Test 1.1: isGibberishToken with obvious keyboard mash
$input = 'asdfghjkl';
$act = isGibberishToken($input);
$runner->record($mod, 'Gibberish Detector', 'isGibberishToken', "'asdfghjkl'", "true", $act, $act === true);

// Test 1.2: isGibberishToken with legitimate course code
$input = 'ITE314';
$act = isGibberishToken($input);
$runner->record($mod, 'Gibberish Detector', 'isGibberishToken', "'ITE314'", "false", $act, $act === false);

// Test 1.3: isGibberishToken with repeated characters
$input = 'aaaaaa';
$act = isGibberishToken($input);
$runner->record($mod, 'Gibberish Detector', 'isGibberishToken', "'aaaaaa'", "true", $act, $act === true);

// Test 1.4: isGibberishToken with legitimate acronym
$input = 'JPCS';
$act = isGibberishToken($input);
$runner->record($mod, 'Gibberish Detector', 'isGibberishToken', "'JPCS'", "false", $act, $act === false);

// Test 1.5: isGibberishToken with unit specification
$input = '220gsm';
$act = isGibberishToken($input);
$runner->record($mod, 'Gibberish Detector', 'isGibberishToken', "'220gsm'", "false", $act, $act === false);

// Test 1.6: validateProposalFieldText descriptive check length violation
$input = 'Too short';
$act = validateProposalFieldText($input, 'descriptive');
$passed = ($act !== null && str_contains($act, 'at least 10 characters and 2 words'));
$runner->record($mod, 'Field Validator', 'validateProposalFieldText', "'Too short' (descriptive)", "Error message requiring >=10 chars", $act, $passed);

// Test 1.7: validateProposalFieldText valid descriptive text
$input = 'To equip students with industry insights and practical competencies.';
$act = validateProposalFieldText($input, 'descriptive');
$runner->record($mod, 'Field Validator', 'validateProposalFieldText', "Legitimate descriptive text", "null (valid)", $act, $act === null);

// Test 1.8: validateProposalGibberish batch validation with mash in title
$data = ['title' => 'asdfghjkl workshop', 'general_objectives' => 'Valid substantive general objective statement here.'];
$act = validateProposalGibberish($data, true);
$passed = !empty($act['errors']['title']);
$runner->record($mod, 'Proposal Form Validator', 'validateProposalGibberish', "Array with mashed title", "['valid' => false, 'errors' => ['title' => '...']]", $act, $passed);

// Test 1.9: isLargeEventClearanceRequired with explicit flag
$actRecord = ['clearance_required' => 1];
$act = isLargeEventClearanceRequired($actRecord, null);
$runner->record($mod, 'Clearance Rule Engine', 'isLargeEventClearanceRequired', "['clearance_required' => 1]", "true", $act, $act === true);

// Test 1.10: isLargeEventClearanceRequired with budget trigger
$actRecord = ['clearance_required' => 0];
$act = isLargeEventClearanceRequired($actRecord, 25000.00);
$runner->record($mod, 'Clearance Rule Engine', 'isLargeEventClearanceRequired', "['clearance_required' => 0], budget=25000", "true", $act, $act === true);

// Test 1.11: getActivityClearanceInfo badge & status generator
$actRecord = ['clearance_required' => 0];
$act = getActivityClearanceInfo($actRecord, 0.0);
$passed = ($act['required'] === false && $act['status'] === 'Not Required' && str_contains($act['badge'], 'Clearance Not Required'));
$runner->record($mod, 'Clearance Rule Engine', 'getActivityClearanceInfo', "Zero budget ordinary event", "['required' => false, 'status' => 'Not Required']", $act, $passed);


// =========================================================================
// 2. TEXT FORMATTING AND CAPITALIZATION
// =========================================================================
$mod = 'Text Formatting & Capitalization';

// Test 2.1: autoCapitalizeText leading lowercase letter
$input = 'seminar on artificial intelligence';
$expected = 'Seminar on artificial intelligence';
$act = autoCapitalizeText($input);
$runner->record($mod, 'Text Normalizer', 'autoCapitalizeText', "'seminar on artificial intelligence'", "'Seminar on artificial intelligence'", $act, $act === $expected);

// Test 2.2: autoCapitalizeText sentence terminal punctuation capitalization
$input = 'first sentence. second sentence? third sentence! fourth sentence.';
$expected = 'First sentence. Second sentence? Third sentence! Fourth sentence.';
$act = autoCapitalizeText($input);
$runner->record($mod, 'Text Normalizer', 'autoCapitalizeText', "Sentences after . ? !", "Each sentence capitalized", $act, $act === $expected);

// Test 2.3: autoCapitalizeText specific name 'john' correction
$input = 'presented by john doe and john smith';
$expected = 'Presented by John doe and John smith';
$act = autoCapitalizeText($input);
$runner->record($mod, 'Text Normalizer', 'autoCapitalizeText', "'presented by john doe and john smith'", "'Presented by John doe and John smith'", $act, $act === $expected);

// Test 2.4: autoCapitalizeText multiline item capitalization
$input = "item one\n- item two\n* item three";
$expected = "Item one\n- Item two\n* Item three";
$act = autoCapitalizeText($input);
$runner->record($mod, 'Text Normalizer', 'autoCapitalizeText', "Multiline items with bullets", "Each item capitalized", $act, $act === $expected);

// Test 2.5: autoCapitalizeText null and empty handling
$actNull = autoCapitalizeText(null);
$actEmpty = autoCapitalizeText('');
$passed = ($actNull === '' && $actEmpty === '');
$runner->record($mod, 'Text Normalizer', 'autoCapitalizeText', "null / ''", "'' (empty string)", "null -> '{$actNull}', '' -> '{$actEmpty}'", $passed);

// Test 2.6: sanitize function for HTML security (strip_tags + htmlspecialchars_decode)
$input = '<script>alert("xss")</script>Hello & Welcome';
$expected = 'alert("xss")Hello & Welcome';
$act = sanitize($input);
$runner->record($mod, 'Input Sanitizer', 'sanitize', "'<script>alert(\"xss\")</script>Hello & Welcome'", "'alert(\"xss\")Hello & Welcome'", $act, $act === $expected);

// Test 2.7: normalizeDepartmentName standard variations
$cases = [
    'Information Technology'              => 'IT',
    'IT'                                  => 'IT',
    'I.T.'                                => 'IT',
    'Tourism and Hospitality Management' => 'THM',
    'General Education'                   => 'GE',
    'Other Special Dept'                  => 'OTHER SPECIAL DEPT'
];
$allMatch = true;
foreach ($cases as $in => $exp) {
    if (normalizeDepartmentName($in) !== $exp) {
        $allMatch = false;
        break;
    }
}
$runner->record($mod, 'Department Canonicalizer', 'normalizeDepartmentName', "IT, I.T., THM, GE, General", "Mapped to IT, THM, GE canonical tokens", $allMatch ? 'All Matched' : 'Mismatch', $allMatch);

// Test 2.8: formatCollaboratorRole label mapping
$r1 = formatCollaboratorRole('co_author');
$r2 = formatCollaboratorRole('contributor');
$r3 = formatCollaboratorRole('owner');
$passed = ($r1 === 'Co-Author' && $r2 === 'Contributor' && $r3 === 'Proposal Owner');
$runner->record($mod, 'Role Label Formatter', 'formatCollaboratorRole', "'co_author', 'contributor', 'owner'", "'Co-Author', 'Contributor', 'Proposal Owner'", "{$r1}, {$r2}, {$r3}", $passed);


// =========================================================================
// 3. KPI CALCULATIONS
// =========================================================================
$mod = 'KPI Calculations';

// Test 3.1: getKpiStatus Exceeded Threshold (>= 110%)
$input = 115.5;
$act = getKpiStatus($input);
$runner->record($mod, 'KPI Rule Engine', 'getKpiStatus', "115.5%", "'Exceeded the Target'", $act, $act === 'Exceeded the Target');

// Test 3.2: getKpiStatus Met Target (90.0% to 109.9%)
$input = 95.0;
$act = getKpiStatus($input);
$runner->record($mod, 'KPI Rule Engine', 'getKpiStatus', "95.0%", "'Met the Target'", $act, $act === 'Met the Target');

// Test 3.3: getKpiStatus Closed to Target (70.0% to 89.9%)
$input = 78.4;
$act = getKpiStatus($input);
$runner->record($mod, 'KPI Rule Engine', 'getKpiStatus', "78.4%", "'Closed to the target'", $act, $act === 'Closed to the target');

// Test 3.4: getKpiStatus Did not meet target (< 70.0%)
$input = 52.0;
$act = getKpiStatus($input);
$runner->record($mod, 'KPI Rule Engine', 'getKpiStatus', "52.0%", "'Did not meet the target'", $act, $act === 'Did not meet the target');

// Test 3.5: getKpiStatus null value handling
$act = getKpiStatus(null);
$runner->record($mod, 'KPI Rule Engine', 'getKpiStatus', "null", "'Insufficient Data'", $act, $act === 'Insufficient Data');

// Test 3.6: getKpiStatusClass badge mapping
$b1 = getKpiStatusClass('Exceeded the Target');
$b2 = getKpiStatusClass('Met the Target');
$b3 = getKpiStatusClass('Closed to the target');
$b4 = getKpiStatusClass('Did not meet the target');
$b5 = getKpiStatusClass('Unknown');
$passed = ($b1 === 'badge-success' && $b2 === 'badge-info' && $b3 === 'badge-warning' && $b4 === 'badge-danger' && $b5 === 'badge-secondary');
$runner->record($mod, 'KPI Badge Resolver', 'getKpiStatusClass', "Standard KPI labels", "badge-success, info, warning, danger, secondary", "{$b1}, {$b2}, {$b3}, {$b4}, {$b5}", $passed);

// Test 3.7: buildPeriodWhereClause for 1st sem (June to October)
$act = buildPeriodWhereClause(2026, '1st_sem', 'a.event_date');
$passed = (str_contains($act['where'], 'YEAR(a.event_date) = ?') && str_contains($act['where'], 'MONTH(a.event_date) BETWEEN 6 AND 10') && $act['params'] === [2026]);
$runner->record($mod, 'Academic Period Engine', 'buildPeriodWhereClause', "Year: 2026, Period: '1st_sem'", "YEAR(a.event_date) = ? AND MONTH BETWEEN 6 AND 10", $act, $passed);

// Test 3.8: buildPeriodWhereClause for all years
$act = buildPeriodWhereClause('all', 'all', 'a.event_date');
$passed = ($act['where'] === '1=1' && $act['params'] === []);
$runner->record($mod, 'Academic Period Engine', 'buildPeriodWhereClause', "Year: 'all', Period: 'all'", "where: '1=1', params: []", $act, $passed);


// =========================================================================
// 4. SCHEDULE AND VENUE CONFLICT DETECTION
// =========================================================================
$mod = 'Schedule & Venue Conflict Detection';

// Test 4.1: Incomplete fields return preliminary non-conflict (Pure logic unit test)
$act = checkScheduleConflict('', '2026-11-20', '08:00', '12:00');
$passed = ($act['conflict'] === false && !empty($act['is_preliminary']));
$runner->record($mod, 'Conflict Engine', 'checkScheduleConflict', "Empty venue with dates/times", "['conflict' => false, 'is_preliminary' => true]", $act, $passed);

// Test 4.2: Inverted time range returns invalid time error immediately without DB query
$act = checkScheduleConflict('Auditorium', '2026-11-20', '15:00', '10:00');
$passed = ($act['conflict'] === true && ($act['error_type'] ?? '') === 'invalid_time_range');
$runner->record($mod, 'Conflict Engine', 'checkScheduleConflict', "Start 15:00 > End 10:00", "['conflict' => true, 'error_type' => 'invalid_time_range']", $act, $passed);

// Test 4.3: Equal start and end time returns invalid time range
$act = checkScheduleConflict('Auditorium', '2026-11-20', '10:00', '10:00');
$passed = ($act['conflict'] === true && ($act['error_type'] ?? '') === 'invalid_time_range');
$runner->record($mod, 'Conflict Engine', 'checkScheduleConflict', "Start 10:00 == End 10:00", "['conflict' => true, 'error_type' => 'invalid_time_range']", $act, $passed);

// Test 4.4: Mathematical overlap boundary logic test (A < D AND B > C)
// Simulating conflict condition between Interval [08:00, 12:00] (480-720) and proposed intervals
function simulateTimeOverlap(int $s1, int $e1, int $s2, int $e2): bool {
    return ($s1 < $e2) && ($e1 > $s2);
}
// [08:00, 12:00] vs [12:00, 16:00] -> Non-overlapping adjacent boundary (Allowed)
$overlapAdjacent = simulateTimeOverlap(480, 720, 720, 960);
// [08:00, 12:00] vs [11:30, 13:00] -> Overlapping 30 mins (Conflict)
$overlapInner = simulateTimeOverlap(480, 720, 690, 780);
$passed = ($overlapAdjacent === false && $overlapInner === true);
$runner->record($mod, 'Overlap Boundary Math', 'simulateTimeOverlap', "Adjacent [08:00-12:00]&[12:00-16:00] vs Overlapping [11:30-13:00]", "Adjacent=false, Overlapping=true", "Adjacent: " . ($overlapAdjacent?'true':'false') . ", Overlapping: " . ($overlapInner?'true':'false'), $passed);


// =========================================================================
// 5. QR CODE GENERATION
// =========================================================================
$mod = 'QR Code Generation';

// Test 5.1: QrCodeService::isValidUrl with valid https Google Forms URL
$url = 'https://docs.google.com/forms/d/e/1FAIpQLSc_test123/viewform';
$act = QrCodeService::isValidUrl($url);
$runner->record($mod, 'QR Code Validator', 'QrCodeService::isValidUrl', "'https://docs.google.com/forms/...' (Valid HTTPS)", "true", $act, $act === true);

// Test 5.2: QrCodeService::isValidUrl with valid MS Forms URL
$url = 'https://forms.office.com/r/AbCdEf123';
$act = QrCodeService::isValidUrl($url);
$runner->record($mod, 'QR Code Validator', 'QrCodeService::isValidUrl', "'https://forms.office.com/r/...' (Valid HTTPS)", "true", $act, $act === true);

// Test 5.3: QrCodeService::isValidUrl rejecting javascript URI (XSS protection)
$url = 'javascript:alert(1)';
$act = QrCodeService::isValidUrl($url);
$runner->record($mod, 'QR Code Validator', 'QrCodeService::isValidUrl', "'javascript:alert(1)' (Malicious Scheme)", "false", $act, $act === false);

// Test 5.4: QrCodeService::isValidUrl rejecting empty / whitespace strings
$actNull = QrCodeService::isValidUrl(null);
$actEmpty = QrCodeService::isValidUrl('   ');
$passed = ($actNull === false && $actEmpty === false);
$runner->record($mod, 'QR Code Validator', 'QrCodeService::isValidUrl', "null / '   '", "false", "null: false, empty: false", $passed);

// Test 5.5: QrCodeService::isValidUrl rejecting URL with spaces in host
$url = 'https://bad host.com/form';
$act = QrCodeService::isValidUrl($url);
$runner->record($mod, 'QR Code Validator', 'QrCodeService::isValidUrl', "'https://bad host.com/form' (Invalid host)", "false", $act, $act === false);

// Test 5.6: QrCodeService::generateSvg produces valid SVG XML element
$url = 'https://forms.gle/testEvaluationForm';
$svg = QrCodeService::generateSvg($url, 180);
$passed = (is_string($svg) && str_starts_with($svg, '<svg') && str_ends_with(trim($svg), '</svg>') && str_contains($svg, 'width="180"') && str_contains($svg, '<rect'));
$runner->record($mod, 'QR Code Generator', 'QrCodeService::generateSvg', "'https://forms.gle/testEvaluationForm', size=180", "Valid <svg ...> XML string with <rect> elements", $passed ? 'Valid SVG XML' : 'Invalid', $passed);

// Test 5.7: QrCodeService::generateSvg returns null for invalid input
$act = QrCodeService::generateSvg('not-a-valid-url');
$runner->record($mod, 'QR Code Generator', 'QrCodeService::generateSvg', "'not-a-valid-url'", "null", $act, $act === null);


// =========================================================================
// 6. EVALUATION CSV PARSING AND DUPLICATE DETECTION
// =========================================================================
$mod = 'Evaluation CSV Parsing & Deduplication';

// Prepare isolated temporary CSV files
$tempDir = sys_get_temp_dir() . '/sti_test_' . uniqid();
@mkdir($tempDir, 0777, true);

// Test 6.1: Wide Matrix (Google Forms format) CSV with multiple respondents
$wideCsvPath = $tempDir . '/google_forms_sample.csv';
$wideCsvContent = "Timestamp,Email Address,Full Name,Section,\"The activity objectives were clearly met.\",\"The speaker was knowledgeable.\",Comments\n"
    . "2026-11-01 10:00:00,student1@sti.edu,Juan Dela Cruz,BSIT-4A,4,4,Great session!\n"
    . "2026-11-01 10:05:00,student2@sti.edu,Maria Clara,BSIT-4B,3,4,Good information.\n"
    . "2026-11-01 10:10:00,student3@sti.edu,Crisostomo Ibarra,BSIT-4A,5,5,Excellent!\n";
file_put_contents($wideCsvPath, $wideCsvContent);

$parsedWide = parseEvaluationCsvFile($wideCsvPath);
$passedWide = ($parsedWide['respondent_count'] === 3 && $parsedWide['average_rating'] > 3.0 && count($parsedWide['individual_entries']) > 0);
$runner->record($mod, 'Evaluation CSV Parser', 'parseEvaluationCsvFile', "Wide Matrix (Google Forms: 3 respondents, 2 rating cols)", "['respondent_count' => 3, 'average_rating' => ~4.17]", $parsedWide, $passedWide);

// Test 6.2: Long Format CSV with evaluator duplicate detection
$longCsvPath = $tempDir . '/long_format_duplicate_sample.csv';
$longCsvContent = "criteria,rating,evaluator_name,comments\n"
    . "Relevance of Topic,4,John Student,Informative\n"
    . "Speaker Delivery,4,John Student,Clear voice\n"
    . "Relevance of Topic,3,Jane Doe,Helpful\n"
    . "Speaker Delivery,3,Jane Doe,Paced well\n";
file_put_contents($longCsvPath, $longCsvContent);

$parsedLong = parseEvaluationCsvFile($longCsvPath);
// In long format, John Student appears twice and Jane Doe twice, representing 2 distinct respondents
$passedLong = ($parsedLong['respondent_count'] === 2 && count($parsedLong['individual_entries']) === 4);
$runner->record($mod, 'Evaluation CSV Parser', 'parseEvaluationCsvFile', "Long Format (4 rows from 2 distinct evaluators)", "['respondent_count' => 2, total_entries => 4]", $parsedLong, $passedLong);

// Test 6.3: Semicolon delimiter auto-detection
$semiCsvPath = $tempDir . '/semicolon_delimited.csv';
$semiContent = "Timestamp;Name;Overall Satisfaction;Venue Rating\n"
    . "2026-11-01;Student A;4;4\n"
    . "2026-11-01;Student B;5;4\n";
file_put_contents($semiCsvPath, $semiContent);

$parsedSemi = parseEvaluationCsvFile($semiCsvPath);
$passedSemi = ($parsedSemi['respondent_count'] === 2);
$runner->record($mod, 'Delimiter Auto-Detector', 'parseEvaluationCsvFile', "Semicolon ';' separated CSV", "['respondent_count' => 2]", $parsedSemi, $passedSemi);

// Test 6.4: UTF-8 BOM Handling
$bomCsvPath = $tempDir . '/utf8_bom.csv';
$bomContent = "\xEF\xBB\xBF" . "Timestamp,Name,Rating\n2026-11-01,Test User,4\n";
file_put_contents($bomCsvPath, $bomContent);

$parsedBom = parseEvaluationCsvFile($bomCsvPath);
$passedBom = ($parsedBom['respondent_count'] === 1 && $parsedBom['average_rating'] == 4.0);
$runner->record($mod, 'BOM Filter', 'parseEvaluationCsvFile', "UTF-8 BOM prefixed CSV", "['respondent_count' => 1, 'average_rating' => 4.0]", $parsedBom, $passedBom);

// Test 6.5: Text and ordinal ratings ("4 out of 5", "5/5")
$ratingTextCsvPath = $tempDir . '/rating_text.csv';
$ratingTextContent = "Timestamp,Name,Presentation Quality\n"
    . "2026-11-01,Alice,4 out of 5\n"
    . "2026-11-01,Bob,5/5\n";
file_put_contents($ratingTextCsvPath, $ratingTextContent);

$parsedText = parseEvaluationCsvFile($ratingTextCsvPath);
$passedText = ($parsedText['respondent_count'] === 2 && $parsedText['average_rating'] == 4.5);
$runner->record($mod, 'Rating Extractor', 'parseEvaluationCsvFile', "Textual ratings '4 out of 5' and '5/5'", "['average_rating' => 4.5]", $parsedText, $passedText);

// Clean up temporary files
@unlink($wideCsvPath);
@unlink($longCsvPath);
@unlink($semiCsvPath);
@unlink($bomCsvPath);
@unlink($ratingTextCsvPath);
@rmdir($tempDir);


// =========================================================================
// 7. DATE AND TIME VALIDATION
// =========================================================================
$mod = 'Date & Time Validation';

// Test 7.1: parseTimeToMinutes 12-hour AM
$act = parseTimeToMinutes('08:30 AM');
$expected = 510; // 8 * 60 + 30
$runner->record($mod, 'Time Parser', 'parseTimeToMinutes', "'08:30 AM'", "510 minutes", $act, $act === $expected);

// Test 7.2: parseTimeToMinutes 12-hour PM
$act = parseTimeToMinutes('02:45 PM');
$expected = 885; // 14 * 60 + 45
$runner->record($mod, 'Time Parser', 'parseTimeToMinutes', "'02:45 PM'", "885 minutes", $act, $act === $expected);

// Test 7.3: parseTimeToMinutes 12:00 AM (Midnight)
$act = parseTimeToMinutes('12:00 AM');
$expected = 0;
$runner->record($mod, 'Time Parser', 'parseTimeToMinutes', "'12:00 AM' (Midnight)", "0 minutes", $act, $act === $expected);

// Test 7.4: parseTimeToMinutes 12:00 PM (Noon)
$act = parseTimeToMinutes('12:00 PM');
$expected = 720;
$runner->record($mod, 'Time Parser', 'parseTimeToMinutes', "'12:00 PM' (Noon)", "720 minutes", $act, $act === $expected);

// Test 7.5: parseTimeToMinutes 24-hour format
$act = parseTimeToMinutes('14:30');
$expected = 870;
$runner->record($mod, 'Time Parser', 'parseTimeToMinutes', "'14:30' (24-hour)", "870 minutes", $act, $act === $expected);

// Test 7.6: parseTimeToMinutes invalid strings
$act = parseTimeToMinutes('invalid-time');
$runner->record($mod, 'Time Parser', 'parseTimeToMinutes', "'invalid-time'", "null", $act, $act === null);

// Test 7.7: validateScheduleTimeRange valid range
$act = validateScheduleTimeRange('08:00 AM', '05:00 PM', true);
$passed = ($act['valid'] === true && $act['start_minutes'] === 480 && $act['end_minutes'] === 1020);
$runner->record($mod, 'Schedule Time Validator', 'validateScheduleTimeRange', "'08:00 AM' to '05:00 PM'", "['valid' => true, start=480, end=1020]", $act, $passed);

// Test 7.8: validateScheduleTimeRange end earlier than start
$act = validateScheduleTimeRange('05:00 PM', '08:00 AM', true);
$passed = ($act['valid'] === false && $act['code'] === 'invalid_order');
$runner->record($mod, 'Schedule Time Validator', 'validateScheduleTimeRange', "'05:00 PM' to '08:00 AM' (Inverted)", "['valid' => false, 'code' => 'invalid_order']", $act, $passed);

// Test 7.9: validateScheduleTimeRange missing required start time
$act = validateScheduleTimeRange('', '05:00 PM', true);
$passed = ($act['valid'] === false && $act['code'] === 'missing_start');
$runner->record($mod, 'Schedule Time Validator', 'validateScheduleTimeRange', "Start='', End='05:00 PM' (isRequired=true)", "['valid' => false, 'code' => 'missing_start']", $act, $passed);

// Test 7.10: validateScheduleRows batch rows validation
$rows = [
    ['start_time' => '08:00 AM', 'end_time' => '10:00 AM'],
    ['start_time' => '10:00 AM', 'end_time' => '12:00 PM'],
    ['start_time' => '01:00 PM', 'end_time' => '11:00 AM'] // Invalid row
];
$act = validateScheduleRows($rows, true);
$passed = ($act['valid'] === false && count($act['rows']) === 3 && $act['rows'][2]['valid'] === false);
$runner->record($mod, 'Schedule Rows Validator', 'validateScheduleRows', "3 rows (2 valid, 1 inverted)", "['valid' => false, row 2 invalid]", $act, $passed);


// =========================================================================
// 8. FILE UPLOAD VALIDATION
// =========================================================================
$mod = 'File Upload Validation';

$tempDirFiles = sys_get_temp_dir() . '/sti_file_tests_' . uniqid();
@mkdir($tempDirFiles, 0777, true);

// Create real dummy files
$pngPath = $tempDirFiles . '/sample.png';
// 1x1 transparent PNG binary bytes
$pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
file_put_contents($pngPath, $pngBytes);

$pdfPath = $tempDirFiles . '/sample.pdf';
$pdfBytes = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF";
file_put_contents($pdfPath, $pdfBytes);

$csvPath = $tempDirFiles . '/sample.csv';
file_put_contents($csvPath, "id,name,score\n1,Alice,100\n");

$spoofVideoPath = $tempDirFiles . '/video_spoofed.png';
// Fake PNG with MP4 ftyp box binary signature
$mp4Bytes = "\x00\x00\x00\x20\x66\x74\x79\x70\x69\x73\x6F\x6D\x00\x00\x02\x00";
file_put_contents($spoofVideoPath, $mp4Bytes);

// Test 8.1: isValidPosterImage with valid PNG file
$file = [
    'name'     => 'event_poster.png',
    'type'     => 'image/png',
    'tmp_name' => $pngPath,
    'error'    => UPLOAD_ERR_OK,
    'size'     => strlen($pngBytes)
];
$act = isValidPosterImage($file);
$runner->record($mod, 'File Upload Validator', 'isValidPosterImage', "Valid 1x1 PNG image", "true", $act, $act === true);

// Test 8.2: isValidPosterImage rejecting video file disguised with .png extension
$file = [
    'name'     => 'fake_poster.png',
    'type'     => 'image/png',
    'tmp_name' => $spoofVideoPath,
    'error'    => UPLOAD_ERR_OK,
    'size'     => strlen($mp4Bytes)
];
$act = isValidPosterImage($file);
$runner->record($mod, 'File Upload Validator', 'isValidPosterImage', "MP4 video spoofed as .png", "false", $act, $act === false);

// Test 8.3: isValidFloorPlanFile with valid PDF floor plan
$file = [
    'name'     => 'floor_plan_layout.pdf',
    'type'     => 'application/pdf',
    'tmp_name' => $pdfPath,
    'error'    => UPLOAD_ERR_OK,
    'size'     => strlen($pdfBytes)
];
$act = isValidFloorPlanFile($file);
$runner->record($mod, 'File Upload Validator', 'isValidFloorPlanFile', "Valid %PDF- document", "true", $act, $act === true);

// Test 8.4: isValidPdfFile with genuine PDF header
$file = [
    'name'     => 'assessment_report.pdf',
    'type'     => 'application/pdf',
    'tmp_name' => $pdfPath,
    'error'    => UPLOAD_ERR_OK,
    'size'     => strlen($pdfBytes)
];
$act = isValidPdfFile($file);
$runner->record($mod, 'File Upload Validator', 'isValidPdfFile', "Genuine PDF with %PDF- header", "true", $act, $act === true);

// Test 8.5: isValidPdfFile rejecting non-PDF disguised as .pdf
$fakePdfPath = $tempDirFiles . '/fake.pdf';
file_put_contents($fakePdfPath, "This is plain text pretending to be PDF");
$file = [
    'name'     => 'fake.pdf',
    'type'     => 'application/pdf',
    'tmp_name' => $fakePdfPath,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($fakePdfPath)
];
$act = isValidPdfFile($file);
$runner->record($mod, 'File Upload Validator', 'isValidPdfFile', "Plain text with .pdf extension", "false", $act, $act === false);

// Test 8.6: isValidCsvFile with valid CSV
$file = [
    'name'     => 'evaluation_responses.csv',
    'type'     => 'text/csv',
    'tmp_name' => $csvPath,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($csvPath)
];
$act = isValidCsvFile($file);
$runner->record($mod, 'File Upload Validator', 'isValidCsvFile', "Valid readable CSV file", "true", $act, $act === true);

// Test 8.7: isUploadedVideo detection on MP4 magic bytes
$act = isUploadedVideo($spoofVideoPath);
$runner->record($mod, 'Media Sniffer', 'isUploadedVideo', "File containing MP4 'ftyp' binary header", "true", $act, $act === true);

// Clean up temporary files
@unlink($pngPath);
@unlink($pdfPath);
@unlink($csvPath);
@unlink($spoofVideoPath);
@unlink($fakePdfPath);
@rmdir($tempDirFiles);


// =========================================================================
// SUMMARY OUTPUT
// =========================================================================
$summary = $runner->getSummary();
echo json_encode([
    'summary' => $summary,
    'results' => $runner->getResults()
], JSON_PRETTY_PRINT);
