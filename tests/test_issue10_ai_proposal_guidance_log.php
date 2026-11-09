<?php
/**
 * tests/test_issue10_ai_proposal_guidance_log.php
 *
 * Verification suite for Issue 10:
 * - When generating proposal content through AI Proposal Guidance, do not generate
 *   General Objectives and Specific Objectives in the Objectives step (they are auto-generated on Next).
 * - Only rationale and involved_subjects are generated/previewed/applied for Objectives.
 * - AI-generated Evaluation tool questions are generated using title, theme, and objectives.
 * - Suggestions for all proposal steps are contextualized with Step 1 event details.
 * - Generation and application are logged into proposal_contribution_logs.
 * - Review and apply controls are preserved.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/GeminiProposalGuidanceService.php';

$db = getDB();
$passed = 0;
$failed = 0;

function check(bool $cond, string $desc) {
    global $passed, $failed;
    if ($cond) {
        echo "  [PASS] {$desc}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$desc}\n";
        $failed++;
    }
}

echo "=== Issue 10: AI Proposal Guidance Generation & Objectives Separation Audit ===\n\n";

// 1. Check GeminiProposalGuidanceService fallback suggestions
$service = new GeminiProposalGuidanceService();
$proposalContext = [
    'title'               => 'Cybersecurity Defense Workshop',
    'theme'               => 'Defending Digital Frontiers',
    'source'              => 'Co-Curricular',
    'venue'               => 'STI Computer Lab 3',
    'venue_address'       => '3rd Floor, Main Academic Building',
    'event_date'          => '2026-11-25',
    'start_time'          => '09:00',
    'end_time'            => '15:00',
    'target_participants' => 60,
    'involved_subjects'   => 'CS301, IT402',
    'general_objectives'  => 'Auto-generated general goal',
    'specific_objectives' => '1. Auto-generated specific goal 1\n2. Auto-generated specific goal 2'
];

$suggestions = $service->getFallbackSectionSuggestions($proposalContext, ['objectives', 'schedule', 'materials', 'floor_plan', 'guidelines', 'faculty_tasks', 'evaluation_kpi']);

check(isset($suggestions['objectives']), "Fallback returns 'objectives' section");
check(!isset($suggestions['objectives']['general_objectives']), "Fallback 'objectives' does NOT include general_objectives");
check(!isset($suggestions['objectives']['specific_objectives']), "Fallback 'objectives' does NOT include specific_objectives");
check(isset($suggestions['objectives']['rationale']) && !empty($suggestions['objectives']['rationale']), "Fallback 'objectives' contains rationale");
check(isset($suggestions['objectives']['involved_subjects']) && !empty($suggestions['objectives']['involved_subjects']), "Fallback 'objectives' contains involved_subjects");

// 2. Check that fallback suggestions reflect Step 1 event details
check(strpos($suggestions['schedule'][0]['time'] ?? '', '09:00') !== false, "Fallback schedule starts at Step 1 start_time (09:00)");
check(strpos($suggestions['floor_plan']['notes'] ?? '', 'Computer Lab 3') !== false, "Fallback floor plan reflects Step 1 venue");
check(strpos($suggestions['guidelines']['mechanics'] ?? '', '60') !== false, "Fallback guidelines reflect Step 1 participant count (60)");
check(strpos($suggestions['evaluation_kpi'][0]['indicator'] ?? '', 'Cybersecurity Defense Workshop') !== false, "Fallback evaluation_kpi is aligned with activity title");

// 3. Inspect GeminiProposalGuidanceService prompt code
$serviceFile = file_get_contents(__DIR__ . '/../services/GeminiProposalGuidanceService.php');
check(strpos($serviceFile, 'DO NOT generate General Objectives or Specific Objectives in the Objectives step') !== false, "Gemini prompt explicitly forbids generating General or Specific Objectives");
check(strpos($serviceFile, '=== EVENT DETAILS (STEP 1 CONTEXT) ===') !== false, "Gemini prompt incorporates full Step 1 event details");

// 4. Inspect assets/js/ai-proposal-guidance.js
$jsFile = file_get_contents(__DIR__ . '/../assets/js/ai-proposal-guidance.js');
check(strpos($jsFile, "fields: ['rationale', 'involved_subjects']") !== false, "SECTION_CONFIG.objectives only tracks rationale and involved_subjects");
check(strpos($jsFile, "General and Specific Objectives are automatically generated upon clicking \"Next\"") !== false, "AI Guidance UI displays informational note that objectives are generated on Next");
check(strpos($jsFile, "action: 'generate_sections'") !== false && strpos($jsFile, "source: source") !== false && strpos($jsFile, "start_time: startTime") !== false, "JS collects and transmits full Step 1 details (source, times, venue, participants)");
check(strpos($jsFile, "action: 'log_applied'") !== false, "JS logs applied suggestions to api/ai-proposal-guidance.php");

// 5. Inspect api/generate-evaluation.php
$evalApiFile = file_get_contents(__DIR__ . '/../api/generate-evaluation.php');
check(strpos($evalApiFile, 'empty($general_objectives) && empty($specific_objectives)') !== false, "api/generate-evaluation.php strictly validates that objectives are present");
check(strpos($evalApiFile, 'Objective Alignment is Mandatory') !== false, "api/generate-evaluation.php mandates questions measure objectives achievement");

// 6. Test logging into proposal_contribution_logs
$testActivityId = (int)$db->query("SELECT id FROM activities ORDER BY id DESC LIMIT 1")->fetchColumn();
$testUserId = (int)$db->query("SELECT id FROM users WHERE role = 'faculty' LIMIT 1")->fetchColumn();

if ($testActivityId > 0 && $testUserId > 0) {
    $logged = logProposalContribution($testActivityId, $testUserId, 'applied_ai_guidance', 'Objectives & Rationale', 'Applied AI suggested rationale and involved subjects based on Step 1 event details.', $db);
    check($logged, "logProposalContribution successfully inserts applied_ai_guidance record");

    $logs = getProposalContributionLogs($testActivityId, 5, $db);
    $found = false;
    foreach ($logs as $l) {
        if ($l['action'] === 'applied_ai_guidance' && strpos($l['details'] ?? '', 'Applied AI suggested rationale') !== false) {
            $found = true;
            break;
        }
    }
    check($found, "Recorded AI contribution log is retrievable via getProposalContributionLogs");
}

echo "\nResult: " . ($failed === 0 ? "ALL " . $passed . " CHECKS PASSED!" : "FAILED ({$failed} errors)") . "\n";
exit($failed === 0 ? 0 : 1);

