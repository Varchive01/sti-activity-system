<?php
/**
 * services/GeminiProposalGuidanceService.php
 *
 * Service for providing comprehensive AI-assisted proposal guidance for STI-ARMES (Task 15).
 * Reuses Google Gemini API infrastructure to analyze proposal completeness, objective quality,
 * internal consistency, and improvement opportunities before submission.
 *
 * Implements strict user-control principles: recommendations are actionable suggestions that
 * faculty members can review, accept, modify, or dismiss without automatic overwriting.
 */

require_once __DIR__ . '/GeminiProposalValidationService.php';
require_once __DIR__ . '/../includes/ai/proposal_validator.php';

class GeminiProposalGuidanceService
{
    private string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        if ($apiKey !== null && trim($apiKey) !== '') {
            $this->apiKey = trim($apiKey);
        } elseif (function_exists('getGeminiApiKeySecure')) {
            $this->apiKey = getGeminiApiKeySecure();
        } elseif (defined('GEMINI_API_KEY')) {
            $this->apiKey = (string)GEMINI_API_KEY;
        } else {
            $this->apiKey = '';
        }
    }

    /**
     * Generates comprehensive AI proposal guidance for faculty review.
     *
     * @param array $proposal Associative array of proposal input fields.
     * @return array Standardized guidance report.
     */
    public function generateGuidance(array $proposal): array
    {
        if (empty($this->apiKey)) {
            error_log("GeminiProposalGuidanceService: No API Key configured. Using intelligent rule-based guidance.");
            return $this->getFallbackGuidance($proposal);
        }

        $prompt = $this->buildGuidancePrompt($proposal);

        $modelsToTry = [
            'gemini-3.5-flash-lite',
            'gemini-3.1-flash-lite',
            'gemini-flash-lite-latest',
            'gemini-3.7-flash',
            'gemini-3.6-flash',
            'gemini-flash-latest',
            'gemini-3.5-flash'
        ];

        $payloadData = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature'     => 0.25,
                'topP'            => 0.85,
                'maxOutputTokens' => 4000,
                'responseMimeType'=> 'application/json'
            ]
        ];

        $success = false;
        $response = '';
        $httpCode = 0;
        $curlError = '';

        foreach ($modelsToTry as $modelName) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $modelName . ':generateContent?key=' . urlencode($this->apiKey);
            $maxAttempts = 2;
            $attempt = 0;

            while ($attempt < $maxAttempts) {
                $attempt++;
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payloadData));
                curl_setopt($ch, CURLOPT_TIMEOUT, 35);
                curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

                // Windows CA Bundle detection
                if (file_exists('C:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem')) {
                    curl_setopt($ch, CURLOPT_CAINFO, 'C:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem');
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                } elseif (file_exists('C:/xampp/perl/vendor/lib/Mozilla/CA/cacert.pem')) {
                    curl_setopt($ch, CURLOPT_CAINFO, 'C:/xampp/perl/vendor/lib/Mozilla/CA/cacert.pem');
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                } else {
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                }

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                @curl_close($ch);

                if (!$curlError && $httpCode === 200) {
                    $success = true;
                    break 2; // Success! Break both attempt and model loops
                }

                // Retry on rate limit or temporary unavailability
                if ($httpCode === 503 || $httpCode === 429 || (is_string($response) && str_contains($response, 'UNAVAILABLE'))) {
                    if ($attempt < $maxAttempts) {
                        sleep(2);
                        continue;
                    }
                }
                break;
            }
        }

        if (!$success) {
            error_log("GeminiProposalGuidanceService: Gemini API failed across all models (HTTP {$httpCode}, cURL: {$curlError}). Using fallback guidance.");
            return $this->getFallbackGuidance($proposal);
        }

        try {
            $jsonResponse = json_decode($response, true);
            $rawText = '';

            if (isset($jsonResponse['candidates'][0]['content']['parts'])) {
                foreach ($jsonResponse['candidates'][0]['content']['parts'] as $part) {
                    if (!empty($part['text'])) {
                        $rawText .= $part['text'];
                    }
                }
            }

            if (empty(trim($rawText))) {
                return $this->getFallbackGuidance($proposal);
            }

            $cleanedJson = $this->stripMarkdownCodeBlocks($rawText);
            $parsed = json_decode($cleanedJson, true);

            if (!is_array($parsed)) {
                error_log("GeminiProposalGuidanceService: Failed to parse JSON response: " . substr($cleanedJson, 0, 300));
                return $this->getFallbackGuidance($proposal);
            }

            return $this->normalizeGuidanceResponse($parsed, $proposal);

        } catch (Exception $e) {
            error_log("GeminiProposalGuidanceService Exception: " . $e->getMessage());
            return $this->getFallbackGuidance($proposal);
        }
    }

    /**
     * Builds structured prompt for proposal guidance.
     */
    private function buildGuidancePrompt(array $proposal): string
    {
        $title        = trim((string)($proposal['title'] ?? 'Untitled Proposal'));
        $theme        = trim((string)($proposal['theme'] ?? 'None specified'));
        $venue        = trim((string)($proposal['venue'] ?? 'Campus'));
        $venueAddress = trim((string)($proposal['venue_address'] ?? 'Campus premises'));
        $eventDate    = trim((string)($proposal['event_date'] ?? 'Unscheduled'));
        $startTime    = trim((string)($proposal['start_time'] ?? 'N/A'));
        $endTime      = trim((string)($proposal['end_time'] ?? 'N/A'));
        $participants = (int)($proposal['target_participants'] ?? 0);
        $source       = trim((string)($proposal['source'] ?? 'co_curricular'));
        $genObj       = trim((string)($proposal['general_objectives'] ?? ''));
        $specObj      = trim((string)($proposal['specific_objectives'] ?? ''));
        $rationale    = trim((string)($proposal['rationale'] ?? ''));
        $subjects     = trim((string)($proposal['involved_subjects'] ?? ''));

        // Extract materials summary
        $materialsSummary = [];
        if (!empty($proposal['mat_item']) && is_array($proposal['mat_item'])) {
            foreach ($proposal['mat_item'] as $i => $item) {
                $item = trim((string)$item);
                if ($item === '') continue;
                $qty = $proposal['mat_qty'][$i] ?? '1';
                $cost = $proposal['mat_cost'][$i] ?? '0';
                $materialsSummary[] = "{$item} (Qty: {$qty}, Est. Cost: ₱{$cost})";
            }
        }
        $materialsStr = !empty($materialsSummary) ? implode(', ', $materialsSummary) : 'No specific materials listed.';

        // Extract schedule / program
        $progSummary = [];
        if (!empty($proposal['prog_segment']) && is_array($proposal['prog_segment'])) {
            foreach ($proposal['prog_segment'] as $i => $seg) {
                $seg = trim((string)$seg);
                if ($seg === '') continue;
                $t = $proposal['prog_time'][$i] ?? '';
                $progSummary[] = "{$t}: {$seg}";
            }
        }
        $progStr = !empty($progSummary) ? implode('; ', $progSummary) : 'No program sequence specified.';

        // Extract guidelines
        $guidelinesStr = trim((string)($proposal['guidelines_mechanics'] ?? $proposal['guidelines'] ?? 'None specified'));

        // Extract KPIs
        $kpiSummary = [];
        if (!empty($proposal['kpi_indicator']) && is_array($proposal['kpi_indicator'])) {
            foreach ($proposal['kpi_indicator'] as $i => $ind) {
                $ind = trim((string)$ind);
                if ($ind === '') continue;
                $tgt = $proposal['kpi_target'][$i] ?? 'N/A';
                $kpiSummary[] = "Indicator: {$ind} (Target: {$tgt})";
            }
        }
        $kpiStr = !empty($kpiSummary) ? implode('; ', $kpiSummary) : 'None specified.';

        return <<<PROMPT
You are an expert Academic Activity Advisor for STI College in the Philippines.
Your role is to help a faculty member review and refine their activity proposal BEFORE final submission.
Review the following proposal details thoroughly:

=== PROPOSAL DETAILS ===
- Activity Title: {$title}
- Theme: {$theme}
- Event Schedule: {$eventDate} ({$startTime} to {$endTime})
- Venue: {$venue} ({$venueAddress})
- Target Participants: {$participants} students/participants
- Activity Category: {$source}
- Involved Subjects / Academic Course: {$subjects}
- General Objectives: {$genObj}
- Specific Objectives: {$specObj}
- Rationale / Educational Justification: {$rationale}
- Materials & Budget: {$materialsStr}
- Program / Schedule Sequence: {$progStr}
- Event Guidelines / Mechanics: {$guidelinesStr}
- Key Performance Indicators (KPIs): {$kpiStr}

=== EVALUATION MANDATE ===
Evaluate the proposal across 4 key dimensions:
A. Proposal Completeness: Identify any missing information, empty sections, or unaddressed logistical/budgetary items.
B. Objective Quality: Evaluate whether objectives are clear, understandable, actionable (SMART principles), and clearly aligned with the title.
C. Proposal Consistency: Check logical alignment between title, rationale, objectives, schedule timing, venue, and expected outcomes.
D. Improvement Opportunities: Identify weak descriptions, vague justifications, or areas where adding specific context will improve review approval.

Organize all recommendations into EXACTLY these four categories:
1. "missing_information": Critical or required details that are absent or blank.
2. "needs_improvement": Existing content that requires revision for clarity, measurability, or alignment.
3. "suggested_enhancements": Optional ideas that would elevate the quality, student engagement, or academic rigor.
4. "good_practices": Commendable elements already present in the proposal.

=== CRITICAL CONSTRAINTS ===
- Do NOT invent external institutional facts or non-existent campus requirements.
- Do NOT present your evaluation as an official Dean evaluation score or final approval decision.
- Recommendations must be constructive, professional, and actionable.
- For each recommendation, provide a concrete 'suggested_improvement' text that the faculty member could apply directly if desired.
- Set 'can_auto_apply' to true if the suggestion provides direct text suitable for the 'target_field' (e.g. general_objectives, specific_objectives, rationale, theme, title).

=== OUTPUT SCHEMA ===
Respond STRICTLY with a valid JSON object matching this schema without markdown fences:
{
  "overall_summary": {
    "completeness_assessment": "1-2 sentences assessing completeness.",
    "objectives_alignment": "1-2 sentences on objective clarity and alignment.",
    "consistency_check": "1-2 sentences on internal consistency across schedule, venue, and scope.",
    "missing_critical_info": ["Item 1", "Item 2"],
    "key_recommendations_summary": ["Recommendation 1", "Recommendation 2"]
  },
  "categories": {
    "missing_information": [
      {
        "id": "missing_1",
        "section": "Objectives",
        "target_field": "specific_objectives",
        "issue": "Brief description of the missing item",
        "explanation": "Why this information is necessary for proposal evaluation",
        "suggested_improvement": "Actionable text or recommendation to resolve",
        "priority": "High",
        "can_auto_apply": false
      }
    ],
    "needs_improvement": [
      {
        "id": "improve_1",
        "section": "Objectives",
        "target_field": "general_objectives",
        "issue": "Identified weakness or ambiguity",
        "explanation": "Educational context on why improvement is recommended",
        "suggested_improvement": "Suggested refined objective wording...",
        "priority": "High",
        "can_auto_apply": true
      }
    ],
    "suggested_enhancements": [
      {
        "id": "enhance_1",
        "section": "Event Details",
        "target_field": "theme",
        "issue": "Enhancement opportunity",
        "explanation": "Benefit to student engagement",
        "suggested_improvement": "Suggested theme wording...",
        "priority": "Medium",
        "can_auto_apply": true
      }
    ],
    "good_practices": [
      {
        "id": "good_1",
        "section": "Event Details",
        "target_field": "title",
        "issue": "Clear and descriptive activity title",
        "explanation": "Appropriately establishes the academic theme",
        "suggested_improvement": "Maintain this clarity in promotional materials",
        "priority": "Info",
        "can_auto_apply": false
      }
    ]
  }
}
PROMPT;
    }

    /**
     * Normalizes and validates the Gemini response JSON.
     */
    private function normalizeGuidanceResponse(array $data, array $proposal): array
    {
        $summary = $data['overall_summary'] ?? [];
        $categories = $data['categories'] ?? [];

        $cleanCategories = [
            'missing_information'   => [],
            'needs_improvement'     => [],
            'suggested_enhancements'=> [],
            'good_practices'        => []
        ];

        $totalFindings = 0;

        foreach ($cleanCategories as $catKey => &$itemList) {
            $rawList = $categories[$catKey] ?? [];
            if (!is_array($rawList)) continue;

            $itemIdx = 1;
            foreach ($rawList as $item) {
                if (!is_array($item)) continue;

                $section = trim((string)($item['section'] ?? 'General'));
                $targetField = trim((string)($item['target_field'] ?? ''));
                $issue = trim((string)($item['issue'] ?? 'Observation identified'));
                $explanation = trim((string)($item['explanation'] ?? ''));
                $suggested = trim((string)($item['suggested_improvement'] ?? ''));
                $priority = trim((string)($item['priority'] ?? 'Medium'));
                if (!in_array($priority, ['High', 'Medium', 'Low', 'Info'], true)) {
                    $priority = 'Medium';
                }

                $canAutoApply = !empty($item['can_auto_apply']) && in_array($targetField, ['title', 'theme', 'general_objectives', 'specific_objectives', 'rationale', 'venue'], true) && $suggested !== '';

                $itemList[] = [
                    'id'                   => "{$catKey}_{$itemIdx}",
                    'section'              => $section,
                    'target_field'         => $targetField,
                    'issue'                => $issue,
                    'explanation'          => $explanation,
                    'suggested_improvement'=> $suggested,
                    'priority'             => $priority,
                    'category'             => $catKey,
                    'can_auto_apply'       => $canAutoApply
                ];
                $itemIdx++;
                $totalFindings++;
            }
        }
        unset($itemList);

        return [
            'overall_summary' => [
                'completeness_assessment'    => trim((string)($summary['completeness_assessment'] ?? 'Proposal reviewed across completeness criteria.')),
                'objectives_alignment'       => trim((string)($summary['objectives_alignment'] ?? 'Objectives evaluated for clarity and activity alignment.')),
                'consistency_check'          => trim((string)($summary['consistency_check'] ?? 'Internal consistency verified across timeline, venue, and outcomes.')),
                'missing_critical_info'      => is_array($summary['missing_critical_info'] ?? null) ? array_values($summary['missing_critical_info']) : [],
                'key_recommendations_summary'=> is_array($summary['key_recommendations_summary'] ?? null) ? array_values($summary['key_recommendations_summary']) : []
            ],
            'disclaimer'      => 'These AI-generated findings are recommendations to assist faculty in refining proposal quality prior to submission. They do NOT constitute an official Dean review, evaluation score, or approval decision.',
            'categories'      => $cleanCategories,
            'total_findings'  => $totalFindings,
            'is_fallback'     => false
        ];
    }

    /**
     * Intelligent rule-based fallback guidance engine.
     * Evaluates proposal completeness, objectives quality, and consistency offline.
     */
    public function getFallbackGuidance(array $proposal): array
    {
        $title        = trim((string)($proposal['title'] ?? ''));
        $theme        = trim((string)($proposal['theme'] ?? ''));
        $venue        = trim((string)($proposal['venue'] ?? ''));
        $date         = trim((string)($proposal['event_date'] ?? ''));
        $startTime    = trim((string)($proposal['start_time'] ?? ''));
        $endTime      = trim((string)($proposal['end_time'] ?? ''));
        $participants = (int)($proposal['target_participants'] ?? 0);
        $genObj       = trim((string)($proposal['general_objectives'] ?? ''));
        $specObj      = trim((string)($proposal['specific_objectives'] ?? ''));
        $rationale    = trim((string)($proposal['rationale'] ?? ''));

        $missingInfo  = [];
        $needsImprove = [];
        $enhancements = [];
        $goodPractices= [];

        $critMissingList = [];
        $recSummaryList  = [];

        // ── 1. Completeness Checks ───────────────────────────────────────────
        if ($title === '') {
            $missingInfo[] = [
                'id'                   => 'missing_title',
                'section'              => 'Event Details',
                'target_field'         => 'title',
                'issue'                => 'Activity Title is Missing',
                'explanation'          => 'A clear, descriptive title is required to identify the activity in institutional records and student announcements.',
                'suggested_improvement'=> 'Provide a concise event title reflecting your program scope (e.g., "Web Development Workshop: Building Scalable APIs").',
                'priority'             => 'High',
                'category'             => 'missing_information',
                'can_auto_apply'       => false
            ];
            $critMissingList[] = 'Activity Title';
        }

        if ($genObj === '') {
            $missingInfo[] = [
                'id'                   => 'missing_gen_obj',
                'section'              => 'Objectives',
                'target_field'         => 'general_objectives',
                'issue'                => 'General Objective is Missing',
                'explanation'          => 'The proposal lacks an overarching general objective defining the academic purpose of the activity.',
                'suggested_improvement'=> 'To provide participants with practical knowledge and hands-on skills aligned with institutional curriculum competencies.',
                'priority'             => 'High',
                'category'             => 'missing_information',
                'can_auto_apply'       => true
            ];
            $critMissingList[] = 'General Objective';
        }

        if ($specObj === '') {
            $missingInfo[] = [
                'id'                   => 'missing_spec_obj',
                'section'              => 'Objectives',
                'target_field'         => 'specific_objectives',
                'issue'                => 'Specific Objectives are Missing',
                'explanation'          => 'Proposals require 3 to 5 actionable specific objectives to establish measurable learning outcomes.',
                'suggested_improvement'=> "1. Identify core industry principles through interactive lecture discussions.\n2. Apply practical tools and methodologies in guided laboratory exercises.\n3. Evaluate participant output through standardized rubric assessments.",
                'priority'             => 'High',
                'category'             => 'missing_information',
                'can_auto_apply'       => true
            ];
            $critMissingList[] = 'Specific Objectives';
        }

        if ($date === '' || $startTime === '' || $endTime === '') {
            $missingInfo[] = [
                'id'                   => 'missing_schedule',
                'section'              => 'Event Details',
                'target_field'         => 'event_date',
                'issue'                => 'Event Schedule or Time Slot Incomplete',
                'explanation'          => 'Activity date, start time, and end time are required to perform venue conflict checks.',
                'suggested_improvement'=> 'Select a future event date and defined morning/afternoon time window.',
                'priority'             => 'High',
                'category'             => 'missing_information',
                'can_auto_apply'       => false
            ];
            $critMissingList[] = 'Event Date & Time Schedule';
        }

        if ($venue === '') {
            $missingInfo[] = [
                'id'                   => 'missing_venue',
                'section'              => 'Event Details',
                'target_field'         => 'venue',
                'issue'                => 'Venue Location is Unspecified',
                'explanation'          => 'Designating a designated room, laboratory, or virtual platform is required for logistics review.',
                'suggested_improvement'=> 'Specify the campus facility (e.g., "Auditorium" or "IT Laboratory 1") or online platform.',
                'priority'             => 'High',
                'category'             => 'missing_information',
                'can_auto_apply'       => false
            ];
            $critMissingList[] = 'Venue';
        }

        if ($participants <= 0) {
            $missingInfo[] = [
                'id'                   => 'missing_participants',
                'section'              => 'Event Details',
                'target_field'         => 'target_participants',
                'issue'                => 'Target Participants Count is Missing or Zero',
                'explanation'          => 'An estimated attendance figure is required for venue capacity and materials planning.',
                'suggested_improvement'=> 'Enter an estimated student participant count matching the target sections.',
                'priority'             => 'High',
                'category'             => 'missing_information',
                'can_auto_apply'       => false
            ];
            $critMissingList[] = 'Target Participants Count';
        }

        // ── 2. Quality and Improvement Checks ────────────────────────────────
        if ($genObj !== '' && str_word_count($genObj) < 6) {
            $needsImprove[] = [
                'id'                   => 'improve_gen_obj_short',
                'section'              => 'Objectives',
                'target_field'         => 'general_objectives',
                'issue'                => 'General Objective is Too Brief',
                'explanation'          => 'The current general objective is very short and would benefit from expressing the expected transformation or learning outcome.',
                'suggested_improvement'=> 'Expand the general objective to state what knowledge or competency participants will gain (e.g., "To equip participants with foundational knowledge and applied skills in...").',
                'priority'             => 'Medium',
                'category'             => 'needs_improvement',
                'can_auto_apply'       => false
            ];
            $recSummaryList[] = 'Expand General Objective to articulate student learning outcomes.';
        }

        if ($specObj !== '') {
            $lines = array_filter(array_map('trim', explode("\n", $specObj)));
            if (count($lines) < 2) {
                $needsImprove[] = [
                    'id'                   => 'improve_spec_obj_few',
                    'section'              => 'Objectives',
                    'target_field'         => 'specific_objectives',
                    'issue'                => 'Fewer than Three Specific Objectives',
                    'explanation'          => 'Institutional guidelines recommend 3 to 5 distinct specific objectives covering cognitive, psychomotor, or affective domains.',
                    'suggested_improvement'=> "1. Understand core concepts and theoretical foundations.\n2. Demonstrate technical execution in practical exercises.\n3. Collaborate effectively with peer teams on deliverables.",
                    'priority'             => 'Medium',
                    'category'             => 'needs_improvement',
                    'can_auto_apply'       => true
                ];
                $recSummaryList[] = 'Provide at least 3 distinct specific objectives.';
            }
        }

        if ($rationale === '') {
            $needsImprove[] = [
                'id'                   => 'missing_rationale',
                'section'              => 'Objectives',
                'target_field'         => 'rationale',
                'issue'                => 'Academic Rationale is Empty',
                'explanation'          => 'Providing an educational rationale demonstrates why this activity is important to student development and program objectives.',
                'suggested_improvement'=> 'This activity addresses industry demands and enhances practical skills complementary to standard classroom lectures.',
                'priority'             => 'Medium',
                'category'             => 'needs_improvement',
                'can_auto_apply'       => true
            ];
            $recSummaryList[] = 'Add an educational rationale to justify activity relevance.';
        } elseif (str_word_count($rationale) < 15) {
            $needsImprove[] = [
                'id'                   => 'improve_rationale_short',
                'section'              => 'Objectives',
                'target_field'         => 'rationale',
                'issue'                => 'Rationale Lacks Sufficient Depth',
                'explanation'          => 'The academic justification is brief. Elaborating on industry trends or student needs strengthens proposal approval prospects.',
                'suggested_improvement'=> $rationale . ' This initiative directly reinforces institutional graduate attributes by bridging academic theory with real-world application.',
                'priority'             => 'Low',
                'category'             => 'needs_improvement',
                'can_auto_apply'       => true
            ];
        }

        // ── 3. Consistency Checks ───────────────────────────────────────────
        if ($startTime !== '' && $endTime !== '') {
            $startSec = strtotime("1970-01-01 {$startTime}");
            $endSec = strtotime("1970-01-01 {$endTime}");
            if ($endSec <= $startSec) {
                $needsImprove[] = [
                    'id'                   => 'inconsistent_time',
                    'section'              => 'Event Details',
                    'target_field'         => 'end_time',
                    'issue'                => 'End Time is Earlier than or Equal to Start Time',
                    'explanation'          => 'Event schedule times are chronologically inconsistent. Overnight activities are not supported.',
                    'suggested_improvement'=> 'Adjust the end time to occur after the start time.',
                    'priority'             => 'High',
                    'category'             => 'needs_improvement',
                    'can_auto_apply'       => false
                ];
                $recSummaryList[] = 'Resolve chronological schedule time conflict.';
            }
        }

        // ── 4. Suggested Enhancements ───────────────────────────────────────
        if ($theme === '') {
            $enhancements[] = [
                'id'                   => 'enhance_theme',
                'section'              => 'Event Details',
                'target_field'         => 'theme',
                'issue'                => 'Add an Engaging Activity Theme',
                'explanation'          => 'A creative theme enhances event identity, branding, and promotional materials.',
                'suggested_improvement'=> !empty($title) ? "Empowering Innovation: {$title}" : 'Igniting Excellence: Empowering Next-Generation Leaders',
                'priority'             => 'Low',
                'category'             => 'suggested_enhancements',
                'can_auto_apply'       => true
            ];
        }

        $enhancements[] = [
            'id'                   => 'enhance_rubrics',
            'section'              => 'Evaluation & KPI',
            'target_field'         => 'kpi_indicator',
            'issue'                => 'Define Measurable Success Metrics',
            'explanation'          => 'Pairing student satisfaction targets (e.g., >= 85% Good/Excellent) with attendance verification ensures robust post-event reporting.',
            'suggested_improvement'=> 'Include both quantitative attendance verification and student learning satisfaction surveys in your KPI section.',
            'priority'             => 'Low',
            'category'             => 'suggested_enhancements',
            'can_auto_apply'       => false
        ];

        // ── 5. Good Practices Already Present ───────────────────────────────
        if ($title !== '') {
            $goodPractices[] = [
                'id'                   => 'good_title',
                'section'              => 'Event Details',
                'target_field'         => 'title',
                'issue'                => 'Title Defined',
                'explanation'          => "Activity has an identified title: '{$title}'.",
                'suggested_improvement'=> 'Maintain consistency with this title across promotional materials and certificates.',
                'priority'             => 'Info',
                'category'             => 'good_practices',
                'can_auto_apply'       => false
            ];
        }

        if ($genObj !== '' && str_word_count($genObj) >= 10) {
            $goodPractices[] = [
                'id'                   => 'good_gen_obj',
                'section'              => 'Objectives',
                'target_field'         => 'general_objectives',
                'issue'                => 'Substantive General Objective',
                'explanation'          => 'The overarching objective outlines a clear academic purpose.',
                'suggested_improvement'=> 'Ensure program activities directly contribute to this stated goal.',
                'priority'             => 'Info',
                'category'             => 'good_practices',
                'can_auto_apply'       => false
            ];
        }

        if ($participants > 0) {
            $goodPractices[] = [
                'id'                   => 'good_participants',
                'section'              => 'Event Details',
                'target_field'         => 'target_participants',
                'issue'                => 'Target Audience Defined',
                'explanation'          => "Estimated attendance is defined at {$participants} participants.",
                'suggested_improvement'=> 'Ensure venue layout and seating accommodate this expected capacity.',
                'priority'             => 'Info',
                'category'             => 'good_practices',
                'can_auto_apply'       => false
            ];
        }

        $compAssessment = empty($critMissingList)
            ? 'All critical required proposal fields are present.'
            : 'Proposal requires attention for missing required fields: ' . implode(', ', $critMissingList) . '.';

        $objAssessment = ($genObj !== '' && $specObj !== '')
            ? 'Objectives are articulated with clear educational focus.'
            : 'Objectives section requires completion to define learning targets.';

        $constCheck = 'Internal alignment between schedule, venue, and scope is maintained.';

        $total = count($missingInfo) + count($needsImprove) + count($enhancements) + count($goodPractices);

        return [
            'overall_summary' => [
                'completeness_assessment'    => $compAssessment,
                'objectives_alignment'       => $objAssessment,
                'consistency_check'          => $constCheck,
                'missing_critical_info'      => $critMissingList,
                'key_recommendations_summary'=> !empty($recSummaryList) ? $recSummaryList : ['Finalize logistics and review KPI survey indicators.']
            ],
            'disclaimer'      => 'These AI-generated findings are recommendations to assist faculty in refining proposal quality prior to submission. They do NOT constitute an official Dean review, evaluation score, or approval decision.',
            'categories'      => [
                'missing_information'   => $missingInfo,
                'needs_improvement'     => $needsImprove,
                'suggested_enhancements'=> $enhancements,
                'good_practices'        => $goodPractices
            ],
            'total_findings'  => $total,
            'is_fallback'     => true
        ];
    }

    /**
     * Strips ```json ... ``` code fences from Gemini responses.
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
}

