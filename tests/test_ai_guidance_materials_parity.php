<?php
/**
 * Test AI Guidance Materials Parity, Section Filtering, and Interactive Table Editors
 */

declare(strict_types=1);

echo "======================================================\n";
echo "AI GUIDANCE: MATERIALS, FILTERING & TABLE EDITORS TEST\n";
echo "======================================================\n\n";

$errors = [];

// 1. Syntax check on GeminiProposalGuidanceService.php
$serviceFile = __DIR__ . '/../services/GeminiProposalGuidanceService.php';
$output = [];
$returnVar = 0;
exec("php -l " . escapeshellarg($serviceFile), $output, $returnVar);
if ($returnVar !== 0) {
    $errors[] = "PHP syntax error in GeminiProposalGuidanceService.php: " . implode("\n", $output);
} else {
    echo "[PASS] PHP syntax check on GeminiProposalGuidanceService.php passed.\n";
}

// 2. Validate GeminiProposalGuidanceService section filtering and no objectives
require_once $serviceFile;

$service = new GeminiProposalGuidanceService();
$proposalContext = [
    'title' => 'Web Development Bootcamp 2026',
    'theme' => 'Innovating Through Code',
    'source' => 'Co-Curricular',
    'venue' => 'Computer Lab 301',
    'target_participants' => 60,
    'event_date' => '2026-12-05',
    'start_time' => '09:00',
    'end_time' => '17:00'
];

// Test requesting only 'materials'
$materialsOnly = $service->getFallbackSectionSuggestions($proposalContext, ['materials']);
if (!isset($materialsOnly['materials'])) {
    $errors[] = "Requested 'materials' but 'materials' key is missing from output.";
} elseif (count($materialsOnly) !== 1) {
    $errors[] = "Requested ONLY 'materials' but received " . count($materialsOnly) . " sections: " . implode(', ', array_keys($materialsOnly));
} else {
    echo "[PASS] Single section request ('materials') strictly returns ONLY 'materials'.\n";
}

// Test requesting multiple sections: 'materials' and 'schedule'
$subset = $service->getFallbackSectionSuggestions($proposalContext, ['materials', 'schedule']);
if (count($subset) !== 2 || !isset($subset['materials']) || !isset($subset['schedule'])) {
    $errors[] = "Requested 'materials' and 'schedule' but received: " . implode(', ', array_keys($subset));
} else {
    echo "[PASS] Subset request strictly returns only the 2 requested sections.\n";
}

// Verify objectives is completely removed from fallback
$all = $service->getFallbackSectionSuggestions($proposalContext, ['objectives', 'materials']);
if (isset($all['objectives'])) {
    $errors[] = "'objectives' still returned in fallback suggestions.";
} else {
    echo "[PASS] 'objectives' is completely removed from fallback suggestions.\n";
}

// 3. Validate client-side JS implementation in ai-proposal-guidance.js
$jsFile = __DIR__ . '/../assets/js/ai-proposal-guidance.js';
$jsContent = file_get_contents($jsFile);

// Check that raw JSON code editor is completely eradicated
if (strpos($jsContent, 'edit_json_') !== false) {
    $errors[] = "Found raw JSON code editor 'edit_json_' still present in ai-proposal-guidance.js!";
} else {
    echo "[PASS] Raw JSON code editor (edit_json_) completely eliminated.\n";
}

// Check that objectives is removed from SECTION_CONFIG
if (preg_match('/SECTION_CONFIG\s*=\s*\{[^}]*objectives\s*:/s', $jsContent)) {
    $errors[] = "'objectives' is still present in SECTION_CONFIG!";
} else {
    echo "[PASS] 'objectives' successfully removed from SECTION_CONFIG.\n";
}

// Check that table editors exist for all 5 tabular sections
$tableEditors = [
    'edit_table_materials' => 'Interactive Materials Table Editor',
    'edit_table_people' => 'Interactive People / Manpower Table Editor',
    'edit_table_schedule' => 'Interactive Schedule Table Editor',
    'edit_table_faculty_tasks' => 'Interactive Faculty Tasks Table Editor',
    'edit_table_evaluation_kpi' => 'Interactive Evaluation & KPI Table Editor'
];

foreach ($tableEditors as $tableId => $desc) {
    if (strpos($jsContent, $tableId) === false) {
        $errors[] = "Missing table editor element: {$tableId} ({$desc})";
    } else {
        echo "[PASS] {$desc} ({$tableId}) present.\n";
    }
}

// Check that helper methods are present and exported
$helpers = [
    'addEditRow' => 'Dynamic row adder for edit tables',
    'deleteEditRow' => 'Row deleter for edit tables',
    'onEditMaterialProviderChange' => 'Provider dropdown change handler in edit view',
    'calcEditMaterialRow' => 'Live row calculation in edit view'
];

foreach ($helpers as $helper => $desc) {
    if (strpos($jsContent, $helper) === false) {
        $errors[] = "Missing helper: {$helper} ({$desc})";
    } else {
        echo "[PASS] Helper {$helper} ({$desc}) present.\n";
    }
}

// Check that suggestions are filtered to only selected sections
if (strpos($jsContent, '_currentSuggestions = {};') === false) {
    $errors[] = "Missing strict reset of _currentSuggestions to selected sections in generateSections()";
} else {
    echo "[PASS] Strict reset of _currentSuggestions to selected sections is active.\n";
}

// Balance checks on braces and parenthesis in JS
$openBraces = substr_count($jsContent, '{');
$closeBraces = substr_count($jsContent, '}');
if ($openBraces !== $closeBraces) {
    $errors[] = "Unbalanced curly braces in ai-proposal-guidance.js ({$openBraces} open, {$closeBraces} close).";
} else {
    echo "[PASS] JavaScript syntax balance: {$openBraces} braces balanced.\n";
}

echo "\n------------------------------------------------------\n";
if (empty($errors)) {
    echo "SUMMARY: ALL VERIFICATION CHECKS PASSED (0 ERRORS)\n";
    echo "------------------------------------------------------\n";
    exit(0);
} else {
    echo "SUMMARY: FAILED WITH " . count($errors) . " ERROR(S):\n";
    foreach ($errors as $e) {
        echo " - {$e}\n";
    }
    echo "------------------------------------------------------\n";
    exit(1);
}
