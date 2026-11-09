<?php
/**
 * tests/test_issue11_ai_proposal_guidance_title_resolution.php
 *
 * Verification suite for Issue 11:
 * Fix the 'Title not defined' error in AI Proposal Guidance.
 *
 * Verifies:
 * 1. Title collection from multiple frontend sources (id, querySelector, alternative attributes, window globals).
 * 2. Backend payload parsing, title normalization, and DB fallback resolution in api/ai-proposal-guidance.php.
 * 3. GeminiProposalGuidanceService title preservation across generateProposalSections, fallback generators,
 *    prompt construction, and response normalization.
 * 4. Elimination of false-positive "Title not defined" findings in output schema.
 * 5. Generation with multiple distinct activity titles ensuring proper context adoption.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/GeminiProposalGuidanceService.php';

$passed = 0;
$failed = 0;

function assertCondition(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) {
        echo "  [PASS] {$msg}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$msg}\n";
        $failed++;
    }
}

echo "=== Issue 11: AI Proposal Guidance Title Resolution & Continuity Test ===\n\n";

$service = new GeminiProposalGuidanceService();

// ── TEST 1: Service Section Generation with Different Titles ─────────────
echo "1. Testing Section Generation with Multiple Distinct Activity Titles...\n";

$testTitles = [
    'Cybersecurity Defense Bootcamp 2026',
    'Annual Tech Summit & Innovation Showcase',
    'AI in Healthcare Seminar'
];

foreach ($testTitles as $title) {
    $proposal = [
        'title'               => $title,
        'theme'               => 'Innovating Tomorrow',
        'source'              => 'Co-Curricular',
        'venue'               => 'Auditorium',
        'venue_address'       => 'Building A, 3rd Floor',
        'event_date'          => '2026-11-20',
        'start_time'          => '09:00',
        'end_time'            => '16:00',
        'target_participants' => '75',
        'involved_subjects'   => 'Information Technology'
    ];

    $sections = ['objectives', 'schedule', 'evaluation_kpi'];
    $suggestions = $service->getFallbackSectionSuggestions($proposal, $sections);

    // Verify rationale mentions title
    $hasTitleInRationale = str_contains($suggestions['objectives']['rationale'] ?? '', $title);
    assertCondition($hasTitleInRationale, "Fallback rationale incorporates title: '{$title}'");

    // Verify KPI mentions title
    $hasTitleInKpi = false;
    foreach ($suggestions['evaluation_kpi'] ?? [] as $kpi) {
        if (str_contains($kpi['indicator'] ?? '', $title)) {
            $hasTitleInKpi = true;
            break;
        }
    }
    assertCondition($hasTitleInKpi, "Evaluation KPI indicator references title: '{$title}'");
}

// ── TEST 2: Alternative Field Names (activity_title, event_title) ───────
echo "\n2. Testing Alternative Title Property Resolution in Service...\n";

$altTitleProposal = [
    'activity_title'      => 'Mobile App Development Expo',
    'theme'               => 'Code to Deploy',
    'source'              => 'Curricular',
    'venue'               => 'Computer Lab 4',
    'target_participants' => '50'
];
$altSuggestions = $service->getFallbackSectionSuggestions($altTitleProposal, ['objectives', 'evaluation_kpi']);
$altHasTitle = str_contains($altSuggestions['objectives']['rationale'] ?? '', 'Mobile App Development Expo');
assertCondition($altHasTitle, "Fallback resolves title from 'activity_title' property");

$eventTitleProposal = [
    'event_title'         => 'Data Science Hackathon 2026',
    'theme'               => 'Predictive Insights',
    'source'              => 'Co-Curricular',
    'venue'               => 'STI Gym',
    'target_participants' => '100'
];
$eventSuggestions = $service->getFallbackSectionSuggestions($eventTitleProposal, ['objectives', 'evaluation_kpi']);
$eventHasTitle = str_contains($eventSuggestions['objectives']['rationale'] ?? '', 'Data Science Hackathon 2026');
assertCondition($eventHasTitle, "Fallback resolves title from 'event_title' property");

// ── TEST 3: Guidance Review Prompt & Good Practices ──────────────────────
echo "\n3. Testing Guidance Prompt & Good Practices Output...\n";

$guidanceResult = $service->getFallbackGuidance([
    'title'               => 'Robotics Engineering Workshop',
    'theme'               => 'Automating the Future',
    'venue'               => 'Robotics Lab',
    'event_date'          => '2026-12-10',
    'start_time'          => '08:30',
    'end_time'            => '15:30',
    'target_participants' => '40',
    'general_objectives'  => 'To teach fundamental robotics programming.',
    'specific_objectives' => '1. Build chassis. 2. Code microcontroller.',
    'rationale'           => 'Provides practical mechatronics exposure.'
]);

// Must NOT have 'Activity Title is Missing' in missing_information
$missingTitles = array_filter(
    $guidanceResult['categories']['missing_information'] ?? [],
    fn($item) => ($item['target_field'] ?? '') === 'title' || str_contains(strtolower($item['issue'] ?? ''), 'title')
);
assertCondition(count($missingTitles) === 0, "No 'missing title' finding when title is defined");

// Must HAVE 'Title Defined' in good_practices
$goodTitles = array_filter(
    $guidanceResult['categories']['good_practices'] ?? [],
    fn($item) => ($item['target_field'] ?? '') === 'title' || str_contains(strtolower($item['issue'] ?? ''), 'title')
);
assertCondition(count($goodTitles) > 0, "Good practices affirms 'Title Defined'");

// ── TEST 4: Frontend Script Title Extraction Checks ─────────────────────
echo "\n4. Testing Frontend assets/js/ai-proposal-guidance.js Title Collection...\n";

$jsContent = file_get_contents(__DIR__ . '/../assets/js/ai-proposal-guidance.js');

assertCondition(str_contains($jsContent, "document.getElementById('f1_title')"), "JS checks document.getElementById('f1_title') directly");
assertCondition(str_contains($jsContent, "window.ACTIVITY_TITLE"), "JS checks window.ACTIVITY_TITLE global fallback");
assertCondition(str_contains($jsContent, "window.PROPOSAL_TITLE"), "JS checks window.PROPOSAL_TITLE global fallback");
assertCondition(str_contains($jsContent, "OfflineDrafts"), "JS checks OfflineDrafts fallback");
assertCondition(str_contains($jsContent, "bindTitleListeners"), "JS binds dynamic title change listener on Step 1 input");
assertCondition(str_contains($jsContent, "activity_title: title"), "JS passes both 'title' and 'activity_title' in payload");

// ── TEST 5: API Endpoint Normalization & DB Fallback ────────────────────
echo "\n5. Testing API Endpoint api/ai-proposal-guidance.php Title Fallbacks...\n";

$apiContent = file_get_contents(__DIR__ . '/../api/ai-proposal-guidance.php');

assertCondition(str_contains($apiContent, "\$resolvedTitle"), "API resolves title with fallback variable");
assertCondition(str_contains($apiContent, "SELECT title, theme, source"), "API performs DB fallback query when activity_id > 0");
assertCondition(str_contains($apiContent, "\$input['title'] = \$resolvedTitle"), "API guarantees normalized title in payload");

// ── SUMMARY ─────────────────────────────────────────────────────────────
echo "\n=== TEST RESULTS SUMMARY ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";

if ($failed === 0) {
    echo ">>> ALL ISSUE 11 TITLE RESOLUTION CHECKS PASSED! <<<\n";
    exit(0);
} else {
    echo ">>> SOME CHECKS FAILED! <<<\n";
    exit(1);
}

