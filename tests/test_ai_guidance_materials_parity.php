<?php
/**
 * Test AI Guidance Materials Parity & Rate Editability
 * Verifies that AI Guidance materials recommendations align with the
 * In-House (School) vs Rental (Supplier) schema, proper dropdown, and editable rates.
 */

declare(strict_types=1);

echo "======================================================\n";
echo "AI PROPOSAL GUIDANCE MATERIALS PARITY & RATE TEST\n";
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

// 2. Validate GeminiProposalGuidanceService logic
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

$fallback = $service->getFallbackSectionSuggestions($proposalContext, ['materials']);
if (!isset($fallback['materials']) || !is_array($fallback['materials']) || empty($fallback['materials'])) {
    $errors[] = "Fallback materials not returned or empty.";
} else {
    echo "[PASS] Fallback materials returned " . count($fallback['materials']) . " items.\n";
    foreach ($fallback['materials'] as $idx => $m) {
        if (!in_array($m['provider'], ['in_house', 'rental'], true)) {
            $errors[] = "Fallback item [{$idx}] '{$m['item']}' has invalid provider: '{$m['provider']}'. Must be 'in_house' or 'rental'.";
        }
        if (!isset($m['unit']) || !in_array($m['unit'], ['per_hour', 'per_day', 'fixed'], true)) {
            $errors[] = "Fallback item [{$idx}] '{$m['item']}' has invalid or missing unit.";
        }
        if (!isset($m['rate']) || !is_numeric($m['rate'])) {
            $errors[] = "Fallback item [{$idx}] '{$m['item']}' has non-numeric rate.";
        }
        if ($m['provider'] === 'in_house' && (float)$m['rate'] !== 0.0) {
            $errors[] = "Fallback item [{$idx}] '{$m['item']}' is in_house but rate is not 0.00.";
        }
        if ($m['provider'] === 'rental' && (float)$m['rate'] <= 0.0) {
            $errors[] = "Fallback item [{$idx}] '{$m['item']}' is rental but rate is <= 0.";
        }
    }
    if (empty($errors)) {
        echo "[PASS] All fallback materials items strictly adhere to in_house vs rental schema with proper rates.\n";
    }
}

// 3. Validate client-side JS implementation in ai-proposal-guidance.js
$jsFile = __DIR__ . '/../assets/js/ai-proposal-guidance.js';
$jsContent = file_get_contents($jsFile);

$requiredSnippets = [
    'mat-provider-select' => 'Class mat-provider-select present in materials table',
    'name="mat_provider[]"' => 'Form field name mat_provider[] present in template',
    'handleMaterialProviderChange(this)' => 'onchange handler handleMaterialProviderChange wired to select',
    'value="in_house"' => 'In-House option value present',
    'value="rental"' => 'Rental option value present',
    'name="mat_unit[]"' => 'Form field name mat_unit[] present',
    'mat-unit-select' => 'Class mat-unit-select present',
    'name="mat_rate[]"' => 'Form field name mat_rate[] present',
    'mat-rate' => 'Class mat-rate present for editable rate',
    'calculateMaterialRow(this)' => 'calculateMaterialRow wired to inputs',
    'name="mat_cost[]"' => 'Form field name mat_cost[] present',
    'recalculateAllMaterials' => 'recalculateAllMaterials called after applying materials'
];

foreach ($requiredSnippets as $needle => $desc) {
    if (strpos($jsContent, $needle) === false) {
        $errors[] = "Missing in ai-proposal-guidance.js: {$desc} ('{$needle}')";
    } else {
        echo "[PASS] {$desc}.\n";
    }
}

// Check that old text input placeholder "Supplier name" is removed from applySection
if (strpos($jsContent, 'placeholder="Supplier name"') !== false) {
    $errors[] = "Old text input placeholder 'Supplier name' still exists in ai-proposal-guidance.js!";
} else {
    echo "[PASS] Old legacy text input for supplier completely eliminated.\n";
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
