<?php
/**
 * test_schedule_conflict.php
 *
 * Automated verification test suite for the Schedule Conflict Detection feature.
 *
 * Usage: php tests/test_schedule_conflict.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai/schedule_conflict.php';

function runScheduleConflictTests(): void
{
    echo "===============================================================\n";
    echo "  STI ACTIVITY SYSTEM - SCHEDULE CONFLICT DETECTION TEST SUITE \n";
    echo "===============================================================\n\n";

    $db = getDB();

    // 1. Ensure test faculty user exists
    $stmt = $db->query("SELECT id FROM users WHERE role = 'faculty' LIMIT 1");
    $facultyId = $stmt->fetchColumn() ?: 1;

    $mockActivityIds = [];

    // Helper to insert mock activity
    $insertMockActivity = function ($title, $venue, $date, $start, $end, $status) use ($db, $facultyId, &$mockActivityIds) {
        $stmt = $db->prepare("INSERT INTO activities (
            faculty_id, title, description, theme, venue, event_date, start_time, end_time, status
        ) VALUES (?, ?, 'Mock activity description', 'Mock theme', ?, ?, ?, ?, ?)");
        $stmt->execute([$facultyId, $title, $venue, $date, $start, $end, $status]);
        $id = $db->lastInsertId();
        $mockActivityIds[] = $id;
        return $id;
    };

    try {
        echo "[SETUP] Creating base mock approved activity...\n";
        // Gymnasium booked on 2026-09-01 from 09:00 to 12:00
        $baseMockId = $insertMockActivity(
            "Test Leadership Seminar",
            "Gymnasium",
            "2026-09-01",
            "09:00:00",
            "12:00:00",
            "approved"
        );
        echo "        --> Base approved activity created (ID: {$baseMockId})\n\n";

        // Test 1: No Conflict (different venue)
        echo "[TEST 1] Checking non-conflicting venue (Auditorium, same date/time)...\n";
        $res1 = checkScheduleConflict("Auditorium", "2026-09-01", "10:00", "11:00");
        if ($res1['conflict'] === false) {
            echo "         ✅ PASSED: No conflict detected for different venue.\n\n";
        } else {
            echo "         ❌ FAILED: Erroneously reported conflict for different venue.\n\n";
        }

        // Test 2: No Conflict (same venue, same date, non-overlapping times)
        echo "[TEST 2] Checking non-overlapping times (Gymnasium, same date, 13:00 to 15:00)...\n";
        $res2 = checkScheduleConflict("Gymnasium", "2026-09-01", "13:00", "15:00");
        if ($res2['conflict'] === false) {
            echo "         ✅ PASSED: No conflict detected for non-overlapping times.\n\n";
        } else {
            echo "         ❌ FAILED: Erroneously reported conflict for non-overlapping times.\n\n";
        }

        // Test 3: No Conflict (same venue, different date, same times)
        echo "[TEST 3] Checking different date (Gymnasium, 2026-09-02, 09:00 to 12:00)...\n";
        $res3 = checkScheduleConflict("Gymnasium", "2026-09-02", "09:00", "12:00");
        if ($res3['conflict'] === false) {
            echo "         ✅ PASSED: No conflict detected for different date.\n\n";
        } else {
            echo "         ❌ FAILED: Erroneously reported conflict for different date.\n\n";
        }

        // Test 4: No Conflict (same venue, same date, overlapping time, but status is NOT approved)
        echo "[TEST 4] Checking conflict with an unapproved (draft/submitted) activity...\n";
        $draftMockId = $insertMockActivity(
            "Draft Coding Workshop",
            "Gymnasium",
            "2026-09-01",
            "14:00:00",
            "15:00:00",
            "submitted"
        );
        $res4 = checkScheduleConflict("Gymnasium", "2026-09-01", "14:00", "15:00");
        if ($res4['conflict'] === false) {
            echo "         ✅ PASSED: Ignored non-approved (submitted) activity.\n\n";
        } else {
            echo "         ❌ FAILED: Erroneously flagged conflict against an unapproved activity.\n\n";
        }

        // Test 5: Conflict Detected! (same venue, same date, overlapping time, status: approved)
        echo "[TEST 5] Checking genuine conflict (Gymnasium, 2026-09-01, 10:00 to 11:00)...\n";
        $res5 = checkScheduleConflict("Gymnasium", "2026-09-01", "10:00", "11:00");
        if ($res5['conflict'] === true) {
            echo "         ✅ PASSED: Factual conflict detected successfully.\n";
            echo "         - Conflicting Activity: \"{$res5['conflicting_activity']['title']}\"\n";
            echo "         - AI Recommended Alternative Slots count: " . count($res5['alternatives'] ?? []) . "\n";
            foreach (($res5['alternatives'] ?? []) as $idx => $alt) {
                echo "           " . ($idx + 1) . ". Date: {$alt['date']} | Time: {$alt['start_time']} - {$alt['end_time']} | Reason: {$alt['reason']}\n";
            }
            echo "\n";
        } else {
            echo "         ❌ FAILED: Failed to detect genuine scheduling conflict.\n\n";
        }

        // Test 6: Verify Exclude ID works in edit mode
        echo "[TEST 6] Checking edit mode exclusion (Gymnasium, 2026-09-01, 10:00 to 11:00, excluding base activity id)...\n";
        $res6 = checkScheduleConflict("Gymnasium", "2026-09-01", "10:00", "11:00", $baseMockId);
        if ($res6['conflict'] === false) {
            echo "         ✅ PASSED: Excluded activity correctly in check.\n\n";
        } else {
            echo "         ❌ FAILED: Did not exclude own activity ID.\n\n";
        }

    } catch (Exception $e) {
        echo "         ❌ ERROR: " . $e->getMessage() . "\n\n";
    } finally {
        echo "[CLEANUP] Removing mock activities from database...\n";
        if (!empty($mockActivityIds)) {
            $inList = implode(',', array_map('intval', $mockActivityIds));
            $db->exec("DELETE FROM activities WHERE id IN ({$inList})");
            echo "         --> Mock activities cleaned up.\n";
        }
    }
    echo "\nTest suite execution completed.\n";
}

runScheduleConflictTests();
