<?php
/**
 * Test Suite: Conditional Floor Plan Display Across All Views
 *
 * Verifies that when a proposal has no floor plan or skipped the floor plan:
 * 1. `faculty/proposal-view.php` hides the Floor Plan card completely.
 * 2. `admin1/review.php`, `admin2/review.php`, and `dean/review.php` omit the Floor Plan section.
 * 3. When a proposal DOES have a floor plan (file uploaded or canvas shapes drawn),
 *    the Floor Plan card/section is displayed normally.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=== Running Floor Plan Visibility Verification Suite ===\n\n";

$passCount = 0;
$failCount = 0;

function runTest(string $desc, bool $condition, string $detail = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$desc}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$desc}" . ($detail ? " - {$detail}" : '') . "\n";
        $failCount++;
    }
}

// Test 1: Helper function hasValidFloorPlanContent() unit tests
runTest(
    "hasValidFloorPlanContent returns false for null/empty floor plan",
    hasValidFloorPlanContent(null, ['floor_plan_required' => 0]) === false
);

runTest(
    "hasValidFloorPlanContent returns false when floorplan record exists but file_path, shapes, and notes are empty",
    hasValidFloorPlanContent(
        ['file_path' => '', 'canvas_json' => '{"shapes":[],"floors":{}}', 'notes' => ''],
        ['floor_plan_required' => 0]
    ) === false
);

runTest(
    "hasValidFloorPlanContent returns false when floorplan record has empty canvas array string and required = 0",
    hasValidFloorPlanContent(
        ['file_path' => null, 'canvas_json' => '[]', 'notes' => ''],
        ['floor_plan_required' => 0]
    ) === false
);

runTest(
    "hasValidFloorPlanContent returns true when valid file_path exists (regardless of required status)",
    hasValidFloorPlanContent(
        ['file_path' => 'uploads/floorplans/plan123.png', 'canvas_json' => null, 'notes' => ''],
        ['floor_plan_required' => 0]
    ) === true
);

runTest(
    "hasValidFloorPlanContent returns true when canvas_json has shapes",
    hasValidFloorPlanContent(
        ['file_path' => null, 'canvas_json' => '{"shapes":[{"type":"rect","x":10,"y":10}],"floors":{}}', 'notes' => ''],
        ['floor_plan_required' => 0]
    ) === true
);

runTest(
    "hasValidFloorPlanContent returns true when canvas_json has floor shapes",
    hasValidFloorPlanContent(
        ['file_path' => null, 'canvas_json' => '{"floors":{"ground":{"name":"Ground","shapes":[{"type":"rect"}]}}}', 'notes' => ''],
        ['floor_plan_required' => 0]
    ) === true
);

// Test 2: Verify proposal-view.php rendering behavior
$pvCode = file_get_contents(__DIR__ . '/../faculty/proposal-view.php');
runTest(
    "faculty/proposal-view.php uses hasValidFloorPlanContent to guard the Floor Plan card",
    strpos($pvCode, 'hasValidFloorPlanContent($floorplan, $activity)') !== false
);

// Test 3: Verify admin1/review.php, admin2/review.php, dean/review.php
$a1Code = file_get_contents(__DIR__ . '/../admin1/review.php');
runTest(
    "admin1/review.php checks hasValidFloorPlanContent before rendering floor plan section",
    strpos($a1Code, 'hasValidFloorPlanContent($fp, $activity)') !== false
);

$a2Code = file_get_contents(__DIR__ . '/../admin2/review.php');
runTest(
    "admin2/review.php checks hasValidFloorPlanContent before rendering floor plan section",
    strpos($a2Code, 'hasValidFloorPlanContent($fp, $activity)') !== false
);

$deanCode = file_get_contents(__DIR__ . '/../dean/review.php');
runTest(
    "dean/review.php checks hasValidFloorPlanContent before rendering floor plan section",
    strpos($deanCode, 'hasValidFloorPlanContent($fp, $activity)') !== false
);

echo "\nSummary: {$passCount} Passed, {$failCount} Failed\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);

