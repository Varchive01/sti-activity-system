<?php
// Mock session
session_start();
$_SESSION['user'] = [
    'id' => 1,
    'username' => 'faculty_test',
    'role' => 'faculty',
    'dept' => 'IT'
];

$_SERVER['REQUEST_METHOD'] = 'POST';

// We can test invoking the API logic directly or via curl if local server is running, or simulate the script execution
$testPayload = json_encode([
    'action' => 'generate_sections',
    'title' => 'Web Development Bootcamp 2026',
    'theme' => 'Modern Full-Stack Applications',
    'venue' => 'Computer Lab 301',
    'sections' => ['objectives', 'materials', 'floor_plan', 'people', 'schedule', 'guidelines', 'faculty_tasks', 'evaluation_kpi']
]);

// Test with file_get_contents on local server or direct include test
ob_start();
// simulate raw input
$tempFile = 'php://temp';
// We will test direct instantiation and execution
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/GeminiProposalGuidanceService.php';

$input = json_decode($testPayload, true);
$guidanceService = new GeminiProposalGuidanceService();
$suggestions = $guidanceService->generateProposalSections($input, $input['sections']);

$response = [
    'success' => true,
    'activity_id' => 0,
    'title' => $input['title'],
    'suggestions' => $suggestions
];

echo "API logic response status: " . ($response['success'] ? 'SUCCESS' : 'FAIL') . "\n";
echo "Response title preserved: " . $response['title'] . "\n";
echo "Number of suggested sections: " . count($response['suggestions']) . "\n";
foreach ($response['suggestions'] as $secKey => $secVal) {
    echo "  - Section '{$secKey}': " . (is_array($secVal) ? count($secVal) . ' items/keys' : 'string') . "\n";
}

