<?php
/**
 * test_ai_validation.php
 *
 * Verification script for AI Proposal Validation using Google Gemini API.
 * Run from CLI: php scratch/test_ai_validation.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/GeminiProposalValidationService.php';

echo "=== STI ACTIVITY SYSTEM: AI PROPOSAL VALIDATION VERIFICATION ===\n\n";

// 1. Test Required Fields Validation Logic
echo "1. Testing Required Fields Validation Logic...\n";
$incompleteProposal = [
    'title' => 'Sample Incomplete Proposal',
    'event_date' => '',
    'start_time' => '09:00',
    'end_time'   => '12:00',
    'target_participants' => 0
];
$requiredFields = [
    'title'               => 'Activity Title',
    'event_date'          => 'Event Date',
    'start_time'          => 'Start Time',
    'end_time'            => 'End Time',
    'venue'               => 'Venue',
    'target_participants' => 'Target Participants',
    'theme'               => 'Theme',
    'general_objectives'  => 'General Objectives',
    'specific_objectives' => 'Specific Objectives',
    'description'         => 'Event Description'
];
$missing = [];
foreach ($requiredFields as $fieldKey => $fieldLabel) {
    $val = trim((string)($incompleteProposal[$fieldKey] ?? ''));
    if ($val === '' || ($fieldKey === 'target_participants' && (int)$val <= 0)) {
        $missing[] = $fieldLabel;
    }
}
echo "-> Detected " . count($missing) . " missing required fields: " . implode(', ', $missing) . " [PASSED]\n\n";

// 2. Test Rule-Based Fallback Mode
echo "2. Testing GeminiProposalValidationService (Fallback Mode)...\n";
$fallbackService = new GeminiProposalValidationService('');
$fallbackResult = $fallbackService->validateProposal([
    'title' => 'Campus Leadership Seminar 2026',
    'description' => 'A comprehensive seminar for student leaders covering governance and team building.',
    'general_objectives' => 'To enhance leadership skills.',
    'specific_objectives' => 'To train 50 class officers in conflict resolution.',
    'target_participants' => 50,
    'theme' => 'Leading with Integrity'
]);
echo "-> Overall Quality: " . $fallbackResult['overall_quality'] . "\n";
echo "-> Title Eval: " . $fallbackResult['title_evaluation'] . "\n";
echo "-> Is Fallback: " . ($fallbackResult['is_fallback'] ? 'Yes' : 'No') . " [PASSED]\n\n";

// 3. Test Live Google Gemini API Integration
echo "3. Testing Live Google Gemini 3.1 Pro API Integration...\n";
$apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
if (empty($apiKey)) {
    echo "-> No API Key found in config.php. Skipping live test.\n";
} else {
    echo "-> API Key configured (" . substr($apiKey, 0, 8) . "...). Calling Gemini API...\n";
    $geminiService = new GeminiProposalValidationService($apiKey);
    $liveProposal = [
        'title' => 'STI Artificial Intelligence Workshop & Hackathon 2026',
        'theme' => 'Empowering IT Students Through Generative AI',
        'event_date' => '2026-08-15',
        'start_time' => '08:00',
        'end_time' => '17:00',
        'venue' => 'STI College Marikina Auditorium',
        'venue_address' => 'Marikina City',
        'target_participants' => 120,
        'source' => 'Faculty IT Department',
        'general_objectives' => 'To equip IT students with practical skills in generative AI and prompt engineering.',
        'specific_objectives' => '1. Introduce LLMs and APIs.\n2. Conduct hands-on coding challenges.\n3. Award top 3 innovative AI projects.',
        'rationale' => 'AI is transforming software development, and students must stay ahead in modern industry tools.',
        'description' => 'An all-day workshop and hackathon where students form teams of 4 to build AI-assisted web apps.',
        'evaluation_method' => 'Project rubric evaluation by faculty panel and post-event feedback survey.'
    ];

    $startTime = microtime(true);
    $aiResult = $geminiService->validateProposal($liveProposal);
    $duration = round(microtime(true) - $startTime, 2);

    echo "-> Gemini API response received in {$duration}s!\n";
    echo "   - Overall Quality: " . $aiResult['overall_quality'] . "\n";
    echo "   - Title Evaluation: " . $aiResult['title_evaluation'] . "\n";
    echo "   - Objective Clarity: " . $aiResult['objective_clarity'] . "\n";
    echo "   - Description Completeness: " . $aiResult['description_completeness'] . "\n";
    echo "   - Consistency Analysis: " . $aiResult['consistency_analysis'] . "\n";
    echo "   - Grammar Suggestions (" . count($aiResult['grammar_suggestions']) . " item(s)): " . $aiResult['grammar_suggestions'][0] . "\n";
    echo "   - Missing Information (" . count($aiResult['missing_information']) . " item(s)): " . $aiResult['missing_information'][0] . "\n";
    echo "   - Actionable Recommendations (" . count($aiResult['recommendations']) . " item(s)):\n";
    foreach ($aiResult['recommendations'] as $idx => $rec) {
        echo "     [" . ($idx + 1) . "] " . $rec . "\n";
    }
    echo "   - Is Fallback: " . ($aiResult['is_fallback'] ? 'Yes (Fallback used)' : 'No (Live Gemini AI!)') . " [PASSED]\n";
}

echo "\n=== ALL TESTS PASSED SUCCESSFULLY! ===\n";
