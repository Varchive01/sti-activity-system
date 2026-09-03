<?php
/**
 * GeminiKpiAnalyticsService.php
 *
 * Service for qualitative interpretation of calculated KPI performance metrics using Google Gemini API.
 */
class GeminiKpiAnalyticsService
{
    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = trim($apiKey);
    }

    /**
     * Interprets KPI achievements for a single activity.
     *
     * @param array $activityData Programmatically calculated KPI data from calculateActivityKpis().
     * @param array|null $feedbackAnalysis Pre-existing feedback analysis JSON data.
     * @return array|null Structured interpretation schema or null on failure.
     */
    public function analyzeActivityKpis(array $activityData, ?array $feedbackAnalysis = null): ?array
    {
        if (empty($this->apiKey)) {
            error_log("GeminiKpiAnalyticsService: No API Key configured.");
            return null;
        }

        $prompt = $this->buildActivityPrompt($activityData, $feedbackAnalysis);
        return $this->callGemini($prompt);
    }

    /**
     * Interprets aggregated KPI metrics for institutional dashboard.
     *
     * @param array $aggregateData Programmatically aggregated year/period stats.
     * @return array|null Structured interpretation schema or null on failure.
     */
    public function analyzeInstitutionalKpis(array $aggregateData): ?array
    {
        if (empty($this->apiKey)) {
            error_log("GeminiKpiAnalyticsService: No API Key configured.");
            return null;
        }

        $prompt = $this->buildInstitutionalPrompt($aggregateData);
        return $this->callGemini($prompt);
    }

    /**
     * Queries Google Gemini API and parses the JSON response.
     */
    private function callGemini(string $prompt): ?array
    {
        $modelsToTry = ['gemini-3.5-flash'];
        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'topP' => 0.8,
                'maxOutputTokens' => 3000,
                'responseMimeType' => 'application/json'
            ]
        ];

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
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

                // SSL Certificate fallback bundles
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
                curl_close($ch);

                if (!$curlError && $httpCode === 200) {
                    $parsed = $this->parseGeminiResponse($response);
                    if ($parsed !== null) {
                        return $parsed; // Success!
                    }
                }

                // Retry on rate limit (429) or server unavailable (503)
                if ($httpCode === 503 || $httpCode === 429) {
                    if ($attempt < $maxAttempts) {
                        sleep(2);
                    }
                } else {
                    // Fail early on other errors
                    break;
                }
            }
        }

        return null;
    }

    /**
     * Parses the response from Gemini and validates the JSON fields.
     */
    private function parseGeminiResponse(string $responseBody): ?array
    {
        $data = json_decode($responseBody, true);
        if (!$data || empty($data['candidates'][0]['content']['parts'][0]['text'])) {
            return null;
        }

        $rawText = trim($data['candidates'][0]['content']['parts'][0]['text']);
        
        // Remove code fences if Gemini returned them despite responseMimeType
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $rawText, $matches)) {
            $rawText = trim($matches[1]);
        }

        $insights = json_decode($rawText, true);
        if (!$insights || !is_array($insights)) {
            return null;
        }

        // Validate strictly structured JSON format
        $requiredKeys = ['overall_insight', 'performance_summary', 'strengths', 'areas_of_attention', 'key_findings', 'recommendations', 'confidence_note'];
        foreach ($requiredKeys as $key) {
            if (!isset($insights[$key])) {
                return null;
            }
        }

        // Standardize arrays
        if (!is_array($insights['strengths'])) $insights['strengths'] = [];
        if (!is_array($insights['areas_of_attention'])) $insights['areas_of_attention'] = [];
        if (!is_array($insights['key_findings'])) $insights['key_findings'] = [];
        if (!is_array($insights['recommendations'])) $insights['recommendations'] = [];

        return $insights;
    }

    /**
     * Builds the prompt for a single activity analysis.
     */
    private function buildActivityPrompt(array $activityData, ?array $feedbackAnalysis): string
    {
        $kpisText = "";
        
        // Attendance
        $att = $activityData['kpis']['attendance'];
        $kpisText .= "- KPI: {$att['indicator']} | Target: {$att['target']} | Actual: {$att['actual']} | Achievement: {$att['achievement']} | Status: {$att['status']}\n";
        
        // Satisfaction
        $sat = $activityData['kpis']['satisfaction'];
        $kpisText .= "- KPI: {$sat['indicator']} | Target: {$sat['target']} | Actual: {$sat['actual']} | Achievement: {$sat['achievement']} | Status: {$sat['status']}\n";
        
        // Criteria metrics
        foreach ($activityData['kpis']['criteria'] as $cr) {
            $kpisText .= "- KPI: {$cr['indicator']} | Target: {$cr['target']} | Actual: {$cr['actual']} | Achievement: {$cr['achievement']} | Status: {$cr['status']} (Responses: {$cr['response_count']})\n";
        }

        $feedbackText = "None available.";
        if ($feedbackAnalysis) {
            $feedbackText = "Overall Summary: " . ($feedbackAnalysis['overall_summary'] ?? 'N/A') . "\n";
            $feedbackText .= "Positive Themes:\n";
            if (!empty($feedbackAnalysis['positive_themes'])) {
                foreach ($feedbackAnalysis['positive_themes'] as $theme) {
                    $feedbackText .= "  - Theme: " . ($theme['theme'] ?? '') . " | Summary: " . ($theme['summary'] ?? '') . "\n";
                }
            }
            $feedbackText .= "Improvement Themes:\n";
            if (!empty($feedbackAnalysis['improvement_themes'])) {
                foreach ($feedbackAnalysis['improvement_themes'] as $theme) {
                    $feedbackText .= "  - Theme: " . ($theme['theme'] ?? '') . " | Summary: " . ($theme['summary'] ?? '') . "\n";
                }
            }
        }

        return "You are an academic activity analytics assistant for STI College. Your task is to provide objective, advisory interpretation of the following completed activity KPI results and participant feedback context.

ACTIVITY DETAILS:
- Title: {$activityData['title']}
- Overall Calculated Performance Score: {$activityData['overall_performance']}%
- Overall Performance Status: {$activityData['overall_status']}

CALCULATED NUMERICAL KPI DATA:
{$kpisText}

PARTICIPANT SURVEY FEEDBACK SUMMARY (QUALITATIVE CONTEXT):
{$feedbackText}

ANALYSIS INSTRUCTIONS & PRINCIPLES:
1. Distinguish strictly between FACT (numerical results and feedback themes directly present in the supplied data) and INFERENCE (your logical interpretation of why a result occurred).
2. DO NOT make up external details. Do not state that 'the speakers were excellent' or 'the venue was too cold' unless that specific sentiment is clearly recorded in the qualitative context above.
3. Keep the findings and recommendations grounded in the data. Recommendations should be practical, actionable suggestions for future activities.
4. AI-generated insights are advisory only and must support decision-making, not dictate human approval or administrative actions.
5. You MUST return your response as a valid, parsable JSON object matching the schema below. Do not include markdown code fences.

EXPECTED JSON SCHEMA:
{
  \"overall_insight\": \"A concise paragraph summarizing how the event performed relative to its targets.\",
  \"performance_summary\": \"A short description of overall performance score and status.\",
  \"strengths\": [
    \"A specific area where targets were met or exceeded, backed by data.\"
  ],
  \"areas_of_attention\": [
    \"A specific area where targets were missed or need optimization, backed by data.\"
  ],
  \"key_findings\": [
    \"A key objective factual finding from the KPI outcomes.\"
  ],
  \"recommendations\": [
    \"A concrete, actionable recommendation for future iterations of this or similar events.\"
  ],
  \"confidence_note\": \"A brief note indicating the data points evaluated and confidence in the analysis.\"
}";
    }

    /**
     * Builds the prompt for aggregate/institutional analysis.
     */
    private function buildInstitutionalPrompt(array $aggregateData): string
    {
        $activitiesText = "";
        foreach ($aggregateData['activities'] as $act) {
            $activitiesText .= "- Activity: {$act['title']} | Date: {$act['event_date']} | KPI Avg: {$act['avg_kpi']} | Satisfaction: {$act['satisfaction_score']}/5 | Attendance Rate: {$act['attendance_rate']}%\n";
        }

        return "You are an academic activity analytics assistant for STI College. Your task is to provide objective, institutional-level advisory interpretation of multiple completed activities for the reporting period.

AGGREGATED METRICS FOR THE PERIOD:
- Reporting Year: {$aggregateData['year']}
- Reporting Period: {$aggregateData['period']}
- Total Activities: {$aggregateData['total_activities']}
- Completed Activities: {$aggregateData['completed_activities']}
- Average KPI Rating: {$aggregateData['avg_kpi_rating']} / 4.0
- Average Participant Satisfaction: {$aggregateData['avg_satisfaction']} / 5.0
- Overall Attendance Rate: {$aggregateData['overall_attendance_rate']}%
- Activities Meeting or Exceeding Targets: {$aggregateData['meeting_targets']}
- Activities Below KPI Targets: {$aggregateData['below_targets']}

ACTIVITY DETAIL LIST:
{$activitiesText}

ANALYSIS INSTRUCTIONS & PRINCIPLES:
1. Interpret institutional trends (e.g., whether student-org vs faculty-led events performed better, common patterns in attendance, satisfaction ranges).
2. Distinguish strictly between FACT (metrics provided) and INFERENCE. DO NOT invent causes for high/low scores.
3. Keep findings and recommendations centered on strategic improvements for the college (e.g., scheduling changes, promotional improvements, survey engagement).
4. You MUST return your response as a valid, parsable JSON object matching the schema below. Do not include markdown code fences.

EXPECTED JSON SCHEMA:
{
  \"overall_insight\": \"A concise strategic overview of institutional activity performance and target achievement this period.\",
  \"performance_summary\": \"A short description summarizing average KPI rating, satisfaction, and attendance trends.\",
  \"strengths\": [
    \"A major pattern of success identified across multiple activities.\"
  ],
  \"areas_of_attention\": [
    \"A common weakness, scheduling bottleneck, or attendance gap requiring institutional review.\"
  ],
  \"key_findings\": [
    \"A key quantitative aggregate finding (e.g., '80% of activities successfully met targets').\"
  ],
  \"recommendations\": [
    \"A strategic recommendation to improve institutional event operations, coordination, or attendance.\"
  ],
  \"confidence_note\": \"Brief statement on data quality and the list of activities analyzed.\"
}";
    }
}
