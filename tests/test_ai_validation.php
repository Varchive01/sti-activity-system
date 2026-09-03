<?php
/**
 * test_ai_validation.php
 *
 * Manual verification script that creates a deliberately incomplete activity proposal,
 * invokes validateProposal($proposalId) using Google Gemini API (gemini-1.5-flash),
 * and confirms that the issues array is non-empty and correctly stored in the database.
 *
 * Usage: php tests/test_ai_validation.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai/proposal_validator.php';

function runAiValidationTest(): void
{
    echo "===============================================================\n";
    echo "   STI ACTIVITY SYSTEM - AI PROPOSAL VALIDATION TEST SUITE     \n";
    echo "===============================================================\n\n";

    $db = getDB();
    ensureProposalAiValidationTableExists($db);

    // 1. Ensure a test faculty user exists for foreign key constraint
    $stmt = $db->query("SELECT id FROM users WHERE role = 'faculty' LIMIT 1");
    $facultyId = $stmt->fetchColumn();
    if (!$facultyId) {
        // Fallback to any user id
        $stmt = $db->query("SELECT id FROM users LIMIT 1");
        $facultyId = $stmt->fetchColumn() ?: 1;
    }

    echo "[STEP 1] Creating a deliberately incomplete activity proposal...\n";
    // Missing objectives, date in the past, zero participants, empty venue
    $stmt = $db->prepare("INSERT INTO activities (
        faculty_id, title, description, theme, venue, event_date,
        target_participants, general_objectives, specific_objectives,
        source, status
    ) VALUES (
        ?, ?, ?, ?, ?, ?,
        ?, ?, ?,
        ?, ?
    )");

    $testTitle = "AI Validation Automated Test Proposal (" . date("His") . ")";
    $stmt->execute([
        $facultyId,
        $testTitle,
        "Incomplete description for test.",
        "Test Theme",
        "",                // Deliberately empty venue
        "2020-01-01",      // Deliberately past date
        0,                 // Deliberately zero participants
        "",                // Deliberately empty general objectives
        "",                // Deliberately empty specific objectives
        "faculty",
        "draft"
    ]);

    $testProposalId = (int)$db->lastInsertId();
    echo "         --> Proposal created successfully (ID: {$testProposalId})\n\n";

    try {
        echo "[STEP 2] Calling validateProposal({$testProposalId}) with Gemini 1.5 Flash API...\n";
        $startTime = microtime(true);
        $result = validateProposal($testProposalId);
        $duration = round(microtime(true) - $startTime, 2);
        echo "         --> Validation completed in {$duration}s\n\n";

        // Display raw structured result
        echo "[STEP 3] Inspecting returned JSON structure:\n";
        echo "         - is_complete : " . ($result['is_complete'] ? 'TRUE' : 'FALSE') . "\n";
        echo "         - is_aligned  : " . ($result['is_aligned'] ? 'TRUE' : 'FALSE') . "\n";
        echo "         - issues count: " . count($result['issues'] ?? []) . "\n";
        echo "         - suggs count : " . count($result['suggestions'] ?? []) . "\n\n";

        if (!empty($result['issues'])) {
            echo "         [FLAGGED ISSUES RETURNED BY AI]:\n";
            foreach ($result['issues'] as $idx => $issue) {
                echo "           " . ($idx + 1) . ". {$issue}\n";
            }
            echo "\n";
        }

        if (!empty($result['suggestions'])) {
            echo "         [SUGGESTIONS RETURNED BY AI]:\n";
            foreach ($result['suggestions'] as $idx => $sugg) {
                echo "           " . ($idx + 1) . ". {$sugg}\n";
            }
            echo "\n";
        }

        // Assertions
        $passed = true;

        echo "[STEP 4] Asserting test expectations:\n";

        // Assertion 1: issues array is non-empty
        if (is_array($result['issues']) && !empty($result['issues'])) {
            echo "         [PASS] Confirmed issues array is non-empty (found " . count($result['issues']) . " issues).\n";
        } else {
            echo "         [FAIL] Expected issues array to be non-empty for deliberately incomplete proposal!\n";
            $passed = false;
        }

        // Assertion 2: stored in proposal_ai_validation table
        $dbRecord = getProposalAiValidation($testProposalId);
        if ($dbRecord && (int)$dbRecord['proposal_id'] === $testProposalId) {
            echo "         [PASS] Confirmed results stored in proposal_ai_validation table.\n";
        } else {
            echo "         [FAIL] Results not found in proposal_ai_validation table!\n";
            $passed = false;
        }

        // Assertion 3: reviewed_by_human defaults to false (0)
        if ($dbRecord && $dbRecord['reviewed_by_human'] === false) {
            echo "         [PASS] Confirmed reviewed_by_human defaults to false (0).\n";
        } else {
            echo "         [FAIL] Expected reviewed_by_human to be false by default!\n";
            $passed = false;
        }

        echo "\n===============================================================\n";
        if ($passed) {
            echo "               ALL AI VALIDATION TESTS PASSED!                 \n";
        } else {
            echo "             SOME AI VALIDATION TESTS FAILED!                  \n";
        }
        echo "===============================================================\n\n";

    } finally {
        // Cleanup test proposal and validation record
        echo "[STEP 5] Cleaning up test data from database...\n";
        $db->prepare("DELETE FROM `proposal_ai_validation` WHERE `proposal_id` = ?")->execute([$testProposalId]);
        $db->prepare("DELETE FROM `activities` WHERE `id` = ?")->execute([$testProposalId]);
        echo "         --> Cleanup complete.\n\n";
    }
}

// Run test suite
runAiValidationTest();
