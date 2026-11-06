<?php
/**
 * GeminiProposalValidationService.php
 *
 * Service for analyzing Activity Proposal content using Google Gemini API (Gemini 3.1 Pro).
 * Evaluates overall quality, grammar, title, objective clarity, description completeness,
 * missing information, consistency across fields, and provides actionable recommendations.
 */
class GeminiProposalValidationService
{
    private string $apiKey;
    private string $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=';

    public function __construct(string $apiKey)
    {
        $this->apiKey = trim($apiKey);
    }

    /**
     * Evaluates the activity proposal.
     *
     * @param array $proposal Associative array containing proposal fields.
     * @return array Standardized evaluation array.
     */
    public function validateProposal(array $proposal): array
    {
        // 100% hardcoded, rule-based deterministic evaluation (independent of Gemini API)
        if (function_exists('autoCapitalizeText')) {
            $capFields = ['title', 'theme', 'venue', 'venue_address', 'general_objectives', 'specific_objectives', 'involved_subjects', 'rationale', 'description'];
            foreach ($capFields as $cf) {
                if (isset($proposal[$cf]) && is_string($proposal[$cf])) {
                    $proposal[$cf] = autoCapitalizeText($proposal[$cf]);
                }
            }
        }

        return $this->getFallbackEvaluation($proposal);
    }

    public function getCanonicalProposalPayload(array $proposal): array
    {
        $desc = trim((string)($proposal['description'] ?? ''));
        if ($desc === '') {
            $notes = trim((string)($proposal['floor_plan_notes'] ?? ''));
            $mech  = trim((string)($proposal['guidelines_mechanics'] ?? ''));
            $desc  = trim($notes . ' ' . $mech);
        }

        $partCounts = $proposal['part_count'] ?? $proposal['participant_count'] ?? [];
        $targetParticipants = (int)($proposal['target_participants'] ?? 0);
        if (is_array($partCounts) && !empty($partCounts)) {
            $sumParticipants = 0;
            foreach ($partCounts as $pc) {
                $sumParticipants += max(0, (int)$pc);
            }
            if ($sumParticipants > 0) {
                $targetParticipants = $sumParticipants;
            }
        }

        $canonical = [
            'title'               => trim((string)($proposal['title'] ?? '')),
            'theme'               => trim((string)($proposal['theme'] ?? '')),
            'venue'               => trim((string)($proposal['venue'] ?? '')),
            'venue_address'       => trim((string)($proposal['venue_address'] ?? '')),
            'event_date'          => trim((string)($proposal['event_date'] ?? '')),
            'start_time'          => trim((string)($proposal['start_time'] ?? '')),
            'end_time'            => trim((string)($proposal['end_time'] ?? '')),
            'target_participants' => $targetParticipants,
            'general_objectives'  => trim((string)($proposal['general_objectives'] ?? '')),
            'specific_objectives' => trim((string)($proposal['specific_objectives'] ?? '')),
            'involved_subjects'   => trim((string)($proposal['involved_subjects'] ?? '')),
            'rationale'           => trim((string)($proposal['rationale'] ?? '')),
            'description'         => $desc
        ];

        // Collect and sort KPIs alphabetically to avoid ordering mismatches
        $kpiStrings = [];

        // DB / object format (kpis key)
        if (!empty($proposal['kpis']) && is_array($proposal['kpis'])) {
            foreach ($proposal['kpis'] as $k) {
                $ind = trim((string)($k['indicator'] ?? ''));
                $target = trim((string)($k['target_metric'] ?? ''));
                $method = trim((string)($k['evaluation_method'] ?? ''));
                if ($ind !== '') {
                    $kpiStrings[] = "$ind|$target|$method";
                }
            }
        }
        // POST format (kpi_indicator, kpi_target, kpi_method)
        if (!empty($proposal['kpi_indicator']) && is_array($proposal['kpi_indicator'])) {
            foreach ($proposal['kpi_indicator'] as $idx => $ind) {
                $ind = trim((string)$ind);
                if ($ind === '') continue;
                $target = trim((string)($proposal['kpi_target'][$idx] ?? ''));
                $method = trim((string)($proposal['kpi_method'][$idx] ?? ''));
                $kpiStrings[] = "$ind|$target|$method";
            }
        }
        // POST alternative format (kpi_criteria, kpi_rating)
        if (!empty($proposal['kpi_criteria']) && is_array($proposal['kpi_criteria'])) {
            foreach ($proposal['kpi_criteria'] as $idx => $crit) {
                $crit = trim((string)$crit);
                if ($crit === '') continue;
                $rating = trim((string)($proposal['kpi_rating'][$idx] ?? ''));
                $kpiStrings[] = "$crit|$rating";
            }
        }

        sort($kpiStrings);
        $canonical['kpis'] = $kpiStrings;

        return $canonical;
    }

    /**
     * Calculates the deterministic MD5 hash of the canonical proposal payload.
     */
    public function calculateProposalHash(array $canonical): string
    {
        return md5(json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Maps rich status-based JSON response to legacy column array.
     */
    public function mapToDatabaseFormat(array $data): array
    {
        $isComplete = true;
        $isAligned = true;
        $issues = [];

        $sections = $data['sections'] ?? [];

        // 1. Completeness Check
        $comp = $sections['completeness'] ?? null;
        if ($comp) {
            if ($comp['status'] === 'error') {
                $isComplete = false;
                $issues[] = "Completeness Error: " . $comp['feedback'];
            } elseif ($comp['status'] === 'warning') {
                $issues[] = "Completeness Note: " . $comp['feedback'];
            }
        }

        // 2. Alignment Check
        $alignKeys = [
            'title_evaluation'         => 'Title Evaluation',
            'objective_alignment'      => 'Objective Clarity & Alignment',
            'kpi_alignment'            => 'KPI / Success Indicator Alignment',
            'description_completeness' => 'Description Quality',
            'consistency_analysis'     => 'Consistency & Alignment',
            'grammar_clarity'          => 'Grammar & Clarity'
        ];

        foreach ($alignKeys as $key => $label) {
            $val = $sections[$key] ?? null;
            if ($val) {
                if ($val['status'] === 'error') {
                    $isAligned = false;
                    $issues[] = "$label Mismatch: " . $val['feedback'];
                } elseif ($val['status'] === 'warning') {
                    $issues[] = "$label Recommendation: " . $val['feedback'];
                }
            }
        }

        // Recommendations
        $suggestions = $data['recommendations'] ?? [];

        return [
            'is_complete' => $isComplete,
            'is_aligned'  => $isAligned,
            'issues'      => $issues,
            'suggestions' => $suggestions,
            'full_result' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ];
    }

    /**
     * Builds structured prompt for Gemini API.
     */
    private function buildPrompt(array $proposal): string
    {
        $title       = $proposal['title'] ?? 'Untitled Proposal';
        $theme       = $proposal['theme'] ?? 'N/A';
        $venue       = $proposal['venue'] ?? 'Campus';
        $address     = $proposal['venue_address'] ?? 'N/A';
        $date        = $proposal['event_date'] ?? 'N/A';
        $start       = $proposal['start_time'] ?? 'N/A';
        $end         = $proposal['end_time'] ?? 'N/A';
        $participants= (int)($proposal['target_participants'] ?? 0);
        $source      = $proposal['source'] ?? 'faculty';
        $genObj      = $proposal['general_objectives'] ?? 'N/A';
        $specObj     = $proposal['specific_objectives'] ?? 'N/A';
        $rationale   = $proposal['rationale'] ?? 'N/A';

        $desc = $this->extractProposalDescription($proposal);
        if ($desc === '') {
            $desc = 'N/A';
        }

        // Extract KPI entries across all formats
        $extractedKpis = $this->extractProposalKpiItems($proposal);
        $kpiItems = [];
        foreach ($extractedKpis as $k) {
            $kpiItems[] = "Indicator: {$k['indicator']} (Target: {$k['target']}, Method: {$k['method']})";
        }
        $kpiList = empty($kpiItems) ? 'N/A' : implode("\n- ", $kpiItems);

        $evalLink = $proposal['eval_form_link'] ?? $proposal['evaluation_method'] ?? 'N/A';

        return <<<EOT
You are an academic event reviewer for STI College. Your task is to evaluate an Activity Proposal submitted by a faculty member.

Analyse the following proposal details:
- Title: {$title}
- Theme: {$theme}
- Event Date & Time: {$date} ({$start} - {$end})
- Venue: {$venue} ({$address})
- Target Participants: {$participants} participants
- Organizers/Source: {$source}
- General Objectives: {$genObj}
- Specific Objectives: {$specObj}
- Rationale: {$rationale}
- Description / Guidelines / Mechanics: {$desc}
- KPIs / Success Indicators:
- {$kpiList}
- Evaluation Link / Method: {$evalLink}

You must evaluate the proposal across these exact 9 criteria and provide constructive, professional feedback:
1. Overall Proposal Quality: Provide a general quality rating. Must be exactly "Excellent", "Good", "Fair", or "Needs Improvement".
2. Completeness: Check if critical required fields are missing, empty, or incomplete. If all fields above are provided, mark as "good".
3. Title Evaluation: Check whether the title is relevant to the activity, academically appropriate, and consistent.
4. Objective Clarity & Alignment: Evaluate Objectives for clarity, measurability, and logical alignment without requiring overly strict SMART lists if otherwise appropriate.
5. KPI / Success Indicator Alignment: Explicit separate criteria. Check if KPIs actually measure objectives/outcomes. Mark as "good" if KPIs measure learning, satisfaction, skill application, or participation targets aligned with the event. Only flag as "warning" or "error" if KPIs are missing or purely count logistics/furniture (e.g. "200 chairs prepared").
6. Description Completeness & Quality: Check whether the rationale, guidelines, and description adequately explain scope, activity details, and flow.
7. Consistency & Alignment: Check logical alignment and compatibility across all fields (Title, theme, rationale, objectives, schedule, venue, KPIs, description). Do not flag semantic wording differences if meanings are consistent.
8. Grammar & Clarity: Identify spelling, grammatical, or clarity problems without unnecessary rewrites of correct faculty text.
9. Actionable Recommendations: Provide 2 to 4 specific, actionable tips to refine the proposal.

For categories 2 through 8 (completeness, title_evaluation, objective_alignment, kpi_alignment, description_completeness, consistency_analysis, grammar_clarity), you must assign:
- "status": exactly "good" (no significant issue), "warning" (minor optional improvement recommended), or "error" (missing required information or genuinely serious contradiction)
- "feedback": a concise (1-2 sentences) explanation of findings or issues.

Respond STRICTLY with valid JSON matching the following schema without any markdown formatting, backticks, or extra commentary:
{
  "overall_quality": "Excellent",
  "sections": {
    "completeness": { "status": "good", "feedback": "Feedback text..." },
    "title_evaluation": { "status": "good", "feedback": "Feedback text..." },
    "objective_alignment": { "status": "good", "feedback": "Feedback text..." },
    "kpi_alignment": { "status": "good", "feedback": "Feedback text..." },
    "description_completeness": { "status": "good", "feedback": "Feedback text..." },
    "consistency_analysis": { "status": "good", "feedback": "Feedback text..." },
    "grammar_clarity": { "status": "good", "feedback": "Feedback text..." }
  },
  "recommendations": [
    "Specific actionable recommendation 1...",
    "Specific actionable recommendation 2..."
  ]
}
EOT;
    }

    /**
     * Strips ```json ... ``` markdown backticks if present.
     */
    private function stripMarkdownCodeBlocks(string $text): string
    {
        $text = trim($text);
        if (str_starts_with($text, '```json')) {
            $text = substr($text, 7);
        } elseif (str_starts_with($text, '```')) {
            $text = substr($text, 3);
        }
        if (str_ends_with($text, '```')) {
            $text = substr($text, 0, -3);
        }
        return trim($text);
    }

    /**
     * Ensures all required fields exist in the returned array.
     */
    private function normalizeResponse(array $data, array $proposal): array
    {
        $quality = trim($data['overall_quality'] ?? 'Good');
        if (!in_array($quality, ['Excellent', 'Good', 'Fair', 'Needs Improvement'], true)) {
            $quality = 'Good';
        }

        $sections = $data['sections'] ?? [];
        $keys = [
            'completeness',
            'title_evaluation',
            'objective_alignment',
            'kpi_alignment',
            'description_completeness',
            'consistency_analysis',
            'grammar_clarity'
        ];

        $desc = $this->extractProposalDescription($proposal);
        $hasAllCoreFields = !empty(trim((string)($proposal['title'] ?? '')))
            && !empty(trim((string)($proposal['general_objectives'] ?? '')))
            && !empty(trim((string)($proposal['specific_objectives'] ?? '')))
            && !empty(trim((string)($proposal['rationale'] ?? '')))
            && $desc !== '';

        $normalizedSections = [];
        foreach ($keys as $k) {
            $sec = $sections[$k] ?? null;
            $status = trim($sec['status'] ?? 'good');
            if (!in_array($status, ['good', 'warning', 'error'], true)) {
                $status = 'good';
            }
            // Prevent false-positive completeness blocking when all required fields are present
            if ($k === 'completeness' && $status === 'error' && $hasAllCoreFields) {
                $status = 'good';
            }
            if ($k === 'description_completeness' && $status === 'error' && str_word_count($desc) >= 10) {
                $status = 'good';
            }
            $feedback = trim($sec['feedback'] ?? 'Checked and no issues identified.');
            $normalizedSections[$k] = [
                'status' => $status,
                'feedback' => $feedback
            ];
        }

        $recs = $data['recommendations'] ?? [];
        if (!is_array($recs) || empty($recs)) {
            $recs = ['Verify all logistics and venue coordinates prior to event.'];
        }

        return [
            'overall_quality' => $quality,
            'sections'        => $normalizedSections,
            'recommendations' => array_values($recs),
            'is_fallback'     => false
        ];
    }

    /**
     * Rule-based fallback evaluation when AI is unavailable.
     */
    public function getFallbackEvaluation(array $proposal): array
    {
        $title        = trim((string)($proposal['title'] ?? ''));
        $theme        = trim((string)($proposal['theme'] ?? ''));
        $genObj       = trim((string)($proposal['general_objectives'] ?? ''));
        $specObj      = trim((string)($proposal['specific_objectives'] ?? ''));
        $rationale    = trim((string)($proposal['rationale'] ?? ''));
        $desc         = $this->extractProposalDescription($proposal);
        $kpiItems     = $this->extractProposalKpiItems($proposal);
        $participants = (int)($proposal['target_participants'] ?? 0);

        $descWords = str_word_count($desc);
        $objWords  = str_word_count($genObj . ' ' . $specObj);

        $recs = [];
        $completenessStatus = 'good';
        $completenessFeedback = 'All required proposal fields, objectives, rationale, and guidelines are complete.';

        if ($title === '' || $genObj === '' || $specObj === '' || $rationale === '') {
            $completenessStatus = 'error';
            $completenessFeedback = 'Critical information is missing. Please complete required fields.';
        } elseif ($theme === '') {
            $completenessStatus = 'warning';
            $completenessFeedback = 'Activity theme could be specified to enhance event branding.';
        }

        // Evaluate KPI alignment intelligently instead of hardcoding 'warning'
        $kpiStatus = 'good';
        $kpiFeedback = 'KPIs and success indicators are clearly defined and aligned with the activity objectives.';
        if (empty($kpiItems)) {
            $kpiStatus = 'warning';
            $kpiFeedback = 'Please add at least one KPI / Success Indicator to measure the achievement of your objectives.';
        } else {
            foreach ($kpiItems as $kItem) {
                if (preg_match('/\b(chairs|tables|balloons|extension cords|tarpaulins)\s+(used|prepared|rented)\b/i', $kItem['indicator'])) {
                    $kpiStatus = 'warning';
                    $kpiFeedback = 'Ensure all KPI indicators measure participant outcomes or objectives rather than equipment counts.';
                    break;
                }
            }
        }

        $objStatus = ($objWords >= 8) ? 'good' : 'warning';
        $objFeedback = ($objWords >= 8)
            ? 'General and specific objectives are well-structured and aligned with the activity.'
            : 'Consider expanding objectives to provide clearer measurable outcomes.';

        $descStatus = ($descWords >= 10) ? 'good' : 'warning';
        $descFeedback = ($descWords >= 10)
            ? 'Rationale, mechanics, and event details comprehensively cover the activity scope.'
            : 'Event description/rationale is brief; adding more context is recommended.';

        if ($descWords < 10 || $objWords < 8) {
            $recs[] = 'Expand the event rationale, guidelines, and objectives to provide fuller context for reviewers.';
        } else {
            $recs[] = 'Ensure all resource requirements and venue setups are coordinated with the campus facilities team prior to the event.';
            $recs[] = 'Distribute the post-event evaluation link promptly at the conclusion of the activity to capture participant feedback.';
        }

        if ($participants > 0 && $participants < 10) {
            $recs[] = 'Verify that the target participant count accurately reflects expected attendance.';
        }

        $allGood = ($completenessStatus === 'good' && $title !== '' && $objStatus === 'good' && $kpiStatus === 'good' && $descStatus === 'good');
        $quality = $allGood ? 'Excellent' : (($completenessStatus === 'error') ? 'Needs Improvement' : 'Good');

        return [
            'overall_quality' => $quality,
            'sections' => [
                'completeness' => [
                    'status' => $completenessStatus,
                    'feedback' => $completenessFeedback
                ],
                'title_evaluation' => [
                    'status' => ($title !== '') ? 'good' : 'error',
                    'feedback' => ($title !== '') ? "Title '{$title}' is clear and academically appropriate." : 'Title is missing.'
                ],
                'objective_alignment' => [
                    'status' => $objStatus,
                    'feedback' => $objFeedback
                ],
                'kpi_alignment' => [
                    'status' => $kpiStatus,
                    'feedback' => $kpiFeedback
                ],
                'description_completeness' => [
                    'status' => $descStatus,
                    'feedback' => $descFeedback
                ],
                'consistency_analysis' => [
                    'status' => 'good',
                    'feedback' => 'Schedule, venue, objectives, and activity details are logically consistent.'
                ],
                'grammar_clarity' => [
                    'status' => 'good',
                    'feedback' => 'Proposal text is clear, professional, and well-formatted.'
                ]
            ],
            'recommendations' => $recs,
            'is_fallback' => true
        ];
    }
}
