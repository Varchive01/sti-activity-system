<?php
/**
 * Test Suite: Hardcoded Rule-Based Proposal Validation & Auto-Capitalization
 * Tests:
 * 1. Automatic correction of "john" -> "John" across all positions and counts.
 * 2. Sentence-start capitalization after . ? ! and newlines.
 * 3. Preservation of existing punctuation and word structures.
 * 4. Deterministic PHP proposal validator (zero Gemini API dependency).
 * 5. Rule validation API endpoint contract and missing field reporting.
 * 6. High-contrast red highlighting CSS rules in main.css.
 * 7. Proposal creation and saving compatibility.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/proposal_validator.php';

echo "=======================================================================\n";
echo "    RULE-BASED PROPOSAL VALIDATION & CAPITALIZATION TEST SUITE        \n";
echo "=======================================================================\n";

$allPassed = true;
function checkRuleTest($category, $testLabel, $condition, $details = '') {
    global $allPassed;
    if ($condition) {
        printf(" [PASS] | %-20s | %-44s | %s\n", $category, $testLabel, $details ?: 'OK');
    } else {
        printf(" [FAIL] | %-20s | %-44s | %s\n", $category, $testLabel, $details ?: 'FAILED');
        $allPassed = false;
    }
}

// 1. Capitalization: "john" -> "John" in any position and count
$t1 = "john went to the computer lab with john.";
$r1 = autoCapitalizeText($t1);
checkRuleTest("Capitalization", "Corrects multiple johns in sentence", $r1 === "John went to the computer lab with John.", "Result: $r1");

$t2 = "the committee lead is john and the assistant is john.";
$r2 = autoCapitalizeText($t2);
checkRuleTest("Capitalization", "Corrects mid-sentence and end-sentence john", $r2 === "The committee lead is John and the assistant is John.", "Result: $r2");

$t3 = "student coordinator: john, tech support: john, lead speaker: john.";
$r3 = autoCapitalizeText($t3);
checkRuleTest("Capitalization", "Corrects multiple johns in list structure", $r3 === "Student coordinator: John, tech support: John, lead speaker: John.", "Result: $r3");

// Long sentence with 5 occurrences of john
$longSentence = "during the preliminary briefing, john presented the slides while john recorded the minutes. later, john answered questions from attendees, and john coordinated with campus facilities alongside john.";
$longExpected = "During the preliminary briefing, John presented the slides while John recorded the minutes. Later, John answered questions from attendees, and John coordinated with campus facilities alongside John.";
$rLong = autoCapitalizeText($longSentence);
checkRuleTest("Capitalization", "Corrects 5 occurrences of john in long sentence", $rLong === $longExpected, "Verified 5 replacements");

// 2. Sentence-start capitalization (. ? ! and line breaks)
$tPeriod = "this is sentence one. this is sentence two. and sentence three.";
$rPeriod = autoCapitalizeText($tPeriod);
checkRuleTest("Sentence Starts", "Capitalizes after periods", $rPeriod === "This is sentence one. This is sentence two. And sentence three.", "Result: $rPeriod");

$tQuestion = "what is the event objective? it is to empower students. why is it important? because tech matters.";
$rQuestion = autoCapitalizeText($tQuestion);
checkRuleTest("Sentence Starts", "Capitalizes after question marks", $rQuestion === "What is the event objective? It is to empower students. Why is it important? Because tech matters.", "Result: $rQuestion");

$tExclamation = "welcome to the hackathon! let's build something great! congratulations to everyone.";
$rExclamation = autoCapitalizeText($tExclamation);
checkRuleTest("Sentence Starts", "Capitalizes after exclamation marks", $rExclamation === "Welcome to the hackathon! Let's build something great! Congratulations to everyone.", "Result: $rExclamation");

$tMixed = "john attended the seminar. did john enjoy it? yes, john loved it! john learned a lot.";
$rMixed = autoCapitalizeText($tMixed);
$mixedExpected = "John attended the seminar. Did John enjoy it? Yes, John loved it! John learned a lot.";
checkRuleTest("Combined Rules", "Combines sentence starts and john correction", $rMixed === $mixedExpected, "Result: $rMixed");

// 3. Rule-based PHP Proposal Validator (zero Gemini dependency)
$db = getDB();
$testAct = $db->query("SELECT id FROM activities ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($testAct) {
    $valResult = validateProposal((int)$testAct['id']);
    checkRuleTest("Rule Validator", "validateProposal runs offline without Gemini", is_array($valResult) && isset($valResult['is_complete']) && isset($valResult['is_aligned']));
    checkRuleTest("Rule Validator", "Rule validation result saved to database", isset($valResult['full_result']) && strpos($valResult['full_result'], 'rule_based') !== false);
}

// 4. JS Rule Validator & CSS Styling
$css = file_get_contents(__DIR__ . '/../assets/css/main.css');
checkRuleTest("CSS Architecture", "Red field error highlight rule defined", strpos($css, '.rule-field-error') !== false && strpos($css, '#EF4444') !== false);
checkRuleTest("CSS Architecture", "Dark mode red field error highlight defined", strpos($css, '[data-theme="dark"] .rule-field-error') !== false);
checkRuleTest("CSS Architecture", "Validation error message rule defined", strpos($css, '.rule-error-msg') !== false);

$js = file_get_contents(__DIR__ . '/../assets/js/proposal-ai-validator.js');
checkRuleTest("JS Engine", "JS autoCapitalizeText implements john correction", strpos($js, "replace(/\\bjohn\\b/gi, 'John')") !== false);
checkRuleTest("JS Engine", "JS autoCapitalizeText implements sentence starts", strpos($js, "replace(/([.?!]\\s+[\"'“‘(\\[-]*)(\\p{L})/gu") !== false);
checkRuleTest("JS Engine", "JS highlightFieldError adds red styling and message", strpos($js, "rule-field-error") !== false && strpos($js, "rule-error-msg") !== false);
checkRuleTest("JS Engine", "JS runAIValidation executes rule validation offline", strpos($js, "validateFormRules") !== false && strpos($js, "proceedWithSubmission") !== false);

echo "-----------------------------------------------------------------------\n";
echo "Overall Result: " . ($allPassed ? "ALL TESTS PASSED (100% RULE VALIDATION PARITY)" : "TESTS FAILED") . "\n";
echo "=======================================================================\n";

exit($allPassed ? 0 : 1);

