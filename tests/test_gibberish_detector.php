<?php
require_once __DIR__ . '/../includes/gibberish_detector.php';

$validWords = [
    'report', 'reports', 'reporter', 'reporting', 'reported',
    'output', 'outputs',
    'prototype', 'prototypes', 'prototyping', 'prototyped',
    'require', 'requires', 'required', 'requirement', 'requirements',
    'query', 'queries', 'querying',
    'tutor', 'tutors', 'tutoring',
    'equity',
    'writeup', 'writeups',
    'writer', 'writers',
    'property', 'properties',
    'priority', 'priorities',
    'upper', 'proper', 'properly', 'error', 'errors', 'quite', 'quiet', 'quote', 'quotes', 'trip', 'trips',
    'peer-to-peer', 'world-class', 'well-structured', 'hands-on', 'state-of-the-art', 'step-by-step', 'in-depth',
    'SDGs', 'KPIs', 'JPCS', 'AWS', 'STI', 'PDRRMC', 'SSG', 'UNESCO',
    'queue', 'queuer', 'queues', 'queued', 'queuing', 'queueing', 'Qatar', 'Iraq',
    'add', 'adds', 'all', 'ask', 'asks', 'dash', 'fall', 'falls', 'flag', 'flags', 'flash', 'flask', 'flasks', 'gas', 'glad', 'glass', 'had', 'half', 'hall', 'halls', 'has', 'lass', 'salad', 'salads', 'shall'
];

echo "--- 1. Testing Valid Academic Words, Hyphenated Compounds & Acronyms ---\n";
$failedValid = [];
foreach ($validWords as $w) {
    if (isGibberishToken($w)) {
        $failedValid[] = $w;
    }
}
if (empty($failedValid)) {
    echo "  -> ALL " . count($validWords) . " valid words passed!\n";
} else {
    echo "  -> FAILED for: " . implode(', ', $failedValid) . "\n";
    exit(1);
}

echo "\n--- 2. Testing Obvious Gibberish (Must be Rejected) ---\n";
$gibberishWords = [
    'asdasdasd', 'zxzxzx', 'qwrtypsdf', 'asdfgh', 'qwerty',
    'poiuyt', 'zxcvbn', 'aaaaa', 'qwerqwer', 'lkjhgfdsa',
    'qwtpqwtp', 'fghjkl', 'rewqrewq', 'eouyeouy', 'uioeuioe'
];
$failedGibberish = [];
foreach ($gibberishWords as $w) {
    if (!isGibberishToken($w)) {
        $failedGibberish[] = $w;
    }
}
if (empty($failedGibberish)) {
    echo "  -> ALL " . count($gibberishWords) . " gibberish tokens correctly rejected!\n";
} else {
    echo "  -> FAILED to reject: " . implode(', ', $failedGibberish) . "\n";
    exit(1);
}

echo "\n--- 3. Testing Proposal Text and validateProposalGibberish ---\n";
$proposalData = [
    'general_objectives' => 'To design and implement a prototype system that meets all institutional requirements and produces actionable reports for peer-to-peer collaboration.',
    'specific_objectives' => "1. Conduct research to identify user requirements.\n2. Design and deploy a world-class prototype for reporting.\n3. Measure key project KPIs and align with institutional SDGs.\n4. Train academic tutors and submit complete writeups.",
    'involved_subjects' => 'Computer Science, Information Technology, Software Engineering',
    'rationale' => 'This activity will provide students with well-structured, hands-on experience in software development and project management.'
];

$result = validateProposalGibberish($proposalData, false);
if ($result['valid']) {
    echo "  -> Legitimate proposal payload passed validation successfully!\n";
} else {
    echo "  -> Legitimate proposal payload unexpectedly failed: " . json_encode($result['errors']) . "\n";
    exit(1);
}

// Test proposal with gibberish in objectives
$badProposalData = $proposalData;
$badProposalData['general_objectives'] = 'asdasdasd qwerqwer zxcvbn';
$badResult = validateProposalGibberish($badProposalData, false);
if (!$badResult['valid'] && isset($badResult['errors']['general_objectives'])) {
    echo "  -> Gibberish proposal payload correctly rejected with error: " . $badResult['errors']['general_objectives'] . "\n";
} else {
    echo "  -> Gibberish proposal payload was NOT rejected as expected!\n";
    exit(1);
}

echo "\nALL PHP TESTS PASSED SUCCESSFULLY (100%)!\n";

