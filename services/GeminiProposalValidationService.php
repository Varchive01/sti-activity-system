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
        if (empty($this->apiKey)) {
            error_log("GeminiProposalValidationService: No API Key configured. Using fallback analysis.");
            return $this->getFallbackEvaluation($proposal);
        }

        $prompt = $this->buildPrompt($proposal);

        $modelsToTry = ['gemini-2.5-flash', 'gemini-3.5-flash', 'gemini-3.6-flash', 'gemini-3.7-flash', 'gemini-flash-latest'];
        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'topP' => 0.8,
                'maxOutputTokens' => 4000,
                'responseMimeType' => 'application/json'
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
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_TIMEOUT, 40);
                curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

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
                    break 2; // Success! Break both loops.
                }

                // Retry on 503 or 429
                if ($httpCode === 503 || $httpCode === 429 || strpos($response, 'UNAVAILABLE') !== false) {
                    if ($attempt < $maxAttempts) {
                        $sleepSeconds = 2;
                        error_log("Gemini API Model $modelName returned HTTP $httpCode in Proposal Validation. Attempt $attempt/$maxAttempts. Retrying in {$sleepSeconds}s...");
                        sleep($sleepSeconds);
                        continue;
                    }
                }
                break; // For other errors (like 400), try next model directly
            }
            error_log("Gemini API Model $modelName failed with HTTP $httpCode in Proposal Validation. Trying fallback model...");
        }

        if (!$success) {
            error_log("Gemini API Proposal Validation failed all models. Last HTTP Code: $httpCode. Error: " . ($curlError ?: $response));
            return $this->getFallbackEvaluation($proposal);
        }

        try {
            $jsonResponse = json_decode($response, true);
            $rawText = $jsonResponse['candidates'][0]['content']['parts'][0]['text'] ?? '';

            if (empty($rawText)) {
                error_log("GeminiProposalValidationService: Empty candidates returned from Gemini API.");
                return $this->getFallbackEvaluation($proposal);
            }

            $rawText = $this->stripMarkdownCodeBlocks($rawText);
            $parsed = json_decode($rawText, true);

            if (!is_array($parsed)) {
                error_log("GeminiProposalValidationService: Could not decode JSON from model response: " . $rawText);
                return $this->getFallbackEvaluation($proposal);
            }

            return $this->normalizeResponse($parsed, $proposal);

        } catch (Exception $e) {
            error_log("GeminiProposalValidationService Exception: " . $e->getMessage());
            return $this->getFallbackEvaluation($proposal);
        }
    }

    /**
     * Gets a normalized canonical representation of proposal fields relevant to AI validation.
     */
    public function getCanonicalProposalPayload(array $proposal): array
    {
        $desc = trim((string)($proposal['description'] ?? ''));
        if ($desc === '') {
            $notes = trim((string)($proposal['floor_plan_notes'] ?? ''));
            $mech  = trim((string)($proposal['guidelines_mechanics'] ?? ''));
            $desc  = trim($notes . ' ' . $mech);
        }

        $canonical = [
            'title'               => trim((string)($proposal['title'] ?? '')),
            'theme'               => trim((string)($proposal['theme'] ?? '')),
            'venue'               => trim((string)($proposal['venue'] ?? '')),
            'venue_address'       => trim((string)($proposal['venue_address'] ?? '')),
            'event_date'          => trim((string)($proposal['event_date'] ?? '')),
            'start_time'          => trim((string)($proposal['start_time'] ?? '')),
            'end_time'            => trim((string)($proposal['end_time'] ?? '')),
            'target_participants' => (int)($proposal['target_participants'] ?? 0),
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

        $desc = trim((string)($proposal['description'] ?? ''));
        if ($desc === '') {
            $notes = trim((string)($proposal['floor_plan_notes'] ?? ''));
            $mech  = trim((string)($proposal['guidelines_mechanics'] ?? ''));
            $desc  = trim($notes . ' ' . $mech);
            if ($desc === '') {
                $desc = 'N/A';
            }
        }

        // Extract KPI entries
        $kpiItems = [];
        if (!empty($proposal['kpis']) && is_array($proposal['kpis'])) {
            foreach ($proposal['kpis'] as $k) {
                $ind = trim((string)($k['indicator'] ?? ''));
                if ($ind !== '') {
                    $kpiItems[] = "Indicator: $ind (Target: " . ($k['target_metric'] ?? 'N/A') . ", Method: " . ($k['evaluation_method'] ?? 'N/A') . ")";
                }
            }
        }
        if (!empty($proposal['kpi_indicator']) && is_array($proposal['kpi_indicator'])) {
            foreach ($proposal['kpi_indicator'] as $idx => $ind) {
                if (empty(trim((string)$ind))) continue;
                $target = $proposal['kpi_target'][$idx] ?? 'N/A';
                $method = $proposal['kpi_method'][$idx] ?? 'N/A';
                $kpiItems[] = "Indicator: " . trim((string)$ind) . " (Target: " . trim((string)$target) . ", Method: " . trim((string)$method) . ")";
            }
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
- Description: {$desc}
- KPIs / Success Indicators:
- {$kpiList}
- Evaluation Link / Method: {$evalLink}

You must evaluate the proposal across these exact 9 criteria and provide constructive, professional feedback:
1. Overall Proposal Quality: Provide a general quality rating. Must be exactly "Excellent", "Good", "Fair", or "Needs Improvement".
2. Completeness: Check if critical required fields are missing, empty, or incomplete.
3. Title Evaluation: Check whether the title is relevant to the activity, academically appropriate, and consistent.
4. Objective Clarity & Alignment: Evaluate Objectives for clarity, measurability, and logical alignment without requiring overly strict SMART lists if otherwise appropriate.
5. KPI / Success Indicator Alignment: Explicit separate criteria. Check if KPIs actually measure objectives/outcomes. Flags resource/logistics/attendance-only counts (e.g. "200 chairs prepared") constructively and explains why they fail to verify objectives achievement.
6. Description Completeness & Quality: Check whether the description adequately explains scope, activity details, and flow.
7. Consistency & Alignment: Check logical alignment and compatibility across all fields (Title, theme, rationale, objectives, schedule, venue, KPIs, description). Do not flag semantic wording differences if meanings are consistent.
8. Grammar & Clarity: Identify spelling, grammatical, or clarity problems without unnecessary rewrites of correct faculty text.
9. Actionable Recommendations: Provide exactly 2 to 5 specific, actionable tips to refine the proposal.

For categories 2 through 8 (completeness, title_evaluation, objective_alignment, kpi_alignment, description_completeness, consistency_analysis, grammar_clarity), you must assign:
- "status": exactly "good" (no significant issue), "warning" (improvement recommended), or "error" (missing required information or genuinely serious issue)
- "feedback": a concise (1-2 sentences) explanation of findings or issues.

Respond STRICTLY with valid JSON matching the following schema without any markdown formatting, backticks, or extra commentary:
{
  "overall_quality": "Good",
  "sections": {
    "completeness": { "status": "good", "feedback": "Feedback text..." },
    "title_evaluation": { "status": "good", "feedback": "Feedback text..." },
    "objective_alignment": { "status": "good", "feedback": "Feedback text..." },
    "kpi_alignment": { "status": "warning", "feedback": "Feedback text..." },
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

        $normalizedSections = [];
        foreach ($keys as $k) {
            $sec = $sections[$k] ?? null;
            $status = trim($sec['status'] ?? 'good');
            if (!in_array($status, ['good', 'warning', 'error'], true)) {
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
        $title       = trim($proposal['title'] ?? '');
        $desc        = trim($proposal['description'] ?? '');
        $genObj      = trim($proposal['general_objectives'] ?? '');
        $specObj     = trim($proposal['specific_objectives'] ?? '');
        $participants= (int)($proposal['target_participants'] ?? 0);

        $descWords   = str_word_count($desc);
        $objWords    = str_word_count($genObj . ' ' . $specObj);

        $quality = 'Good';
        $recs = [];
        $completenessStatus = 'good';
        $completenessFeedback = 'All required basic proposal fields are complete.';

        if ($descWords < 20 || $objWords < 15) {
            $quality = 'Fair';
            $recs[] = 'Expand the event description and objectives to provide more detail on event activities and expected outcomes.';
        } else {
            $recs[] = 'Ensure all resource requirements and technical needs are finalized with the campus facilities team.';
        }

        if ($participants < 10) {
            $recs[] = 'Verify that the target participant count accurately reflects expected attendance.';
        }

        if (empty($proposal['theme'])) {
            $completenessStatus = 'warning';
            $completenessFeedback = 'Activity theme could be specified to enhance event branding.';
        }

        if (empty($title) || empty($genObj) || empty($specObj) || empty($desc)) {
            $completenessStatus = 'error';
            $completenessFeedback = 'Critical information is missing. Please complete required fields.';
        }

        return [
            'overall_quality' => $quality,
            'sections' => [
                'completeness' => [
                    'status' => $completenessStatus,
                    'feedback' => $completenessFeedback
                ],
                'title_evaluation' => [
                    'status' => !empty($title) ? 'good' : 'error',
                    'feedback' => !empty($title) ? "Title is set to '$title'." : 'Title is missing.'
                ],
                'objective_alignment' => [
                    'status' => ($objWords >= 15) ? 'good' : 'warning',
                    'feedback' => ($objWords >= 15) ? 'Objectives are detailed and outline the purpose.' : 'Consider expanding objectives to make them more detailed.'
                ],
                'kpi_alignment' => [
                    'status' => 'warning',
                    'feedback' => 'Basic validation: Please verify manually that KPIs measure your objectives.'
                ],
                'description_completeness' => [
                    'status' => ($descWords >= 20) ? 'good' : 'warning',
                    'feedback' => ($descWords >= 20) ? 'Description covers primary scope.' : 'Description is brief; adding context is recommended.'
                ],
                'consistency_analysis' => [
                    'status' => 'good',
                    'feedback' => 'Basic validation: schedule and fields appear consistent.'
                ],
                'grammar_clarity' => [
                    'status' => 'good',
                    'feedback' => 'Offline mode: Spelling and grammar checking unavailable.'
                ]
            ],
            'recommendations' => $recs,
            'is_fallback' => true
        ];
    }
}
