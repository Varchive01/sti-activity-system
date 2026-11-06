<?php
require_once __DIR__ . '/../services/GeminiProposalGuidanceService.php';

$svc = new GeminiProposalGuidanceService();
$proposal = [
    'title' => 'Tech Innovation Summit 2026',
    'activity_type' => 'Seminar / Workshop',
    'lead_person' => 'Prof. Alex Santos'
];
$sections = ['objectives', 'materials', 'floor_plan', 'people', 'schedule', 'guidelines', 'faculty_tasks', 'evaluation_kpi'];

$res = $svc->generateProposalSections($proposal, $sections);

echo "Keys returned: " . implode(', ', array_keys($res)) . "\n";
echo "Objectives general present: " . (isset($res['objectives']['general_objectives']) ? "YES" : "NO") . "\n";
echo "Materials count: " . count($res['materials'] ?? []) . "\n";
echo "Floor plan notes present: " . (isset($res['floor_plan']['notes']) ? "YES" : "NO") . "\n";
echo "People count: " . count($res['people'] ?? []) . "\n";
echo "Schedule count: " . count($res['schedule'] ?? []) . "\n";
echo "Guidelines mechanics present: " . (isset($res['guidelines']['mechanics']) ? "YES" : "NO") . "\n";
echo "Faculty tasks count: " . count($res['faculty_tasks'] ?? []) . "\n";
echo "KPIs count: " . count($res['evaluation_kpi'] ?? []) . "\n";

// Check if title is excluded
if (isset($res['title'])) {
    echo "ERROR: Title should not be returned in suggestions!\n";
} else {
    echo "SUCCESS: Title is excluded from suggestions.\n";
}
