<?php
/**
 * Test Suite: Floor Plan Delete & Preset Restroom Verifications
 *
 * Verifies:
 * 1. Line and Arrow shape construction in assets/js/floorplan.js assigns hitStrokeWidth
 *    for effortless click selection and deletion.
 * 2. wireShape() checks if node is Line or Arrow and guarantees hitStrokeWidth is active.
 * 3. PRESETS.restroom dimensions and styling are updated to prevent cramped/squished text.
 * 4. proposal-create.php and proposal-edit.php include the updated floorplan.js script with cache-busting.
 */

echo "=== Running Floor Plan Delete & Restroom Verification Test Suite ===\n\n";

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

$jsCode = file_get_contents(__DIR__ . '/../assets/js/floorplan.js');

// 1. Line shape creation has hitStrokeWidth
runTest(
    "Konva.Line creation configures hitStrokeWidth for clickability",
    strpos($jsCode, "hitStrokeWidth: Math.max(strokeWidth + 14, 18)") !== false
);

// 2. Arrow shape creation has hitStrokeWidth
runTest(
    "Konva.Arrow creation configures hitStrokeWidth for clickability",
    strpos($jsCode, "shape = new Konva.Arrow({") !== false &&
    substr_count($jsCode, "hitStrokeWidth: Math.max(strokeWidth + 14, 18)") >= 2
);

// 3. wireShape ensures line and arrow hitStrokeWidth is applied even when restoring
runTest(
    "wireShape() ensures Line and Arrow shapes receive expanded hitStrokeWidth",
    strpos($jsCode, "if (cls === 'Line' || cls === 'Arrow') {") !== false &&
    strpos($jsCode, "node.hitStrokeWidth(Math.max(sw + 14, 18));") !== false
);

// 4. Restroom preset dimensions and aesthetics
runTest(
    "Restroom preset width is increased to at least 80px to prevent word-wrap squishing",
    preg_match("/restroom:\s*\{[^}]*w:\s*(?:80|90|100)/s", $jsCode) === 1
);

runTest(
    "Restroom preset has professional styling and readable label",
    preg_match("/restroom:\s*\{[^}]*label:\s*'Restroom'/s", $jsCode) === 1 &&
    preg_match("/restroom:\s*\{[^}]*fill:\s*'#f0fdf4'/s", $jsCode) === 1
);

// 5. Group preset label support in props panel & double-click
runTest(
    "Double click on Group presets (like Restroom) allows editing label",
    strpos($jsCode, "node.getClassName() === 'Group'") !== false &&
    strpos($jsCode, "const innerText = node.findOne('Text');") !== false
);

// 6. Both create and edit proposal views include cache-busting floorplan.js
$createPhp = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');
runTest(
    "faculty/proposal-create.php includes floorplan.js with filemtime versioning",
    strpos($createPhp, 'assets/js/floorplan.js?v=') !== false
);

$editPhp = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');
runTest(
    "faculty/proposal-edit.php includes floorplan.js with filemtime versioning",
    strpos($editPhp, 'assets/js/floorplan.js?v=') !== false
);

echo "\nSummary: {$passCount} Passed, {$failCount} Failed\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);

