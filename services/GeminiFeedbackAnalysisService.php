<?php
/**
 * GeminiFeedbackAnalysisService.php
 *
 * Service for analyzing completed participant evaluation responses using Google Gemini API.
 * Identifies overall summaries, feedback tone, positive themes, areas for improvement,
 * common suggestions, and key actionable recommendations.
 */
class GeminiFeedbackAnalysisService
{
    private string $apiKey;
    private string $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=';

    public function __construct(string $apiKey)
    {
        $this->apiKey = trim($apiKey);
    }

    /**
     * Analyzes participant feedback responses.
     *
     * @param array $activity Associative array containing activity details.
     * @param array $questions Array of evaluation questions.
     * @param array $responses Array of evaluation responses from kpi_evaluations.
     * @return array Structured analysis output.
     */
    public function analyzeFeedback(array $activity, array $questions, array $responses): array
    {
        if (empty($this->apiKey)) {
            error_log("GeminiFeedbackAnalysisService: No API Key configured. Using fallback feedback analysis.");
            return $this->getFallbackAnalysis($activity, $responses);
        }

        $prompt = $this->buildPrompt($activity, $questions, $responses);

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

                // SSL Certificate Bundle fallback checks matching validation service
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
                        error_log("Gemini API Model $modelName returned HTTP $httpCode in Feedback Analysis. Attempt $attempt/$maxAttempts. Retrying in {$sleepSeconds}s...");
                        sleep($sleepSeconds);
                        continue;
                    }
                }
                break; // For other errors (like 400), try next model directly
            }
            error_log("Gemini API Model $modelName failed with HTTP $httpCode in Feedback Analysis. Trying fallback model...");
        }

        if (!$success) {
            error_log("Gemini API Feedback Analysis failed all models. Last HTTP Code: $httpCode. Error: " . ($curlError ?: $response));
            return $this->getFallbackAnalysis($activity, $responses);
        }

        try {
            $jsonResponse = json_decode($response, true);
            $rawText = $jsonResponse['candidates'][0]['content']['parts'][0]['text'] ?? '';

            if (empty($rawText)) {
                error_log("GeminiFeedbackAnalysisService: Empty response text.");
                return $this->getFallbackAnalysis($activity, $responses);
            }

            $rawText = $this->stripMarkdownCodeBlocks($rawText);
            $parsed = json_decode($rawText, true);

            if (!is_array($parsed)) {
                error_log("GeminiFeedbackAnalysisService: Could not decode JSON: " . $rawText);
                return $this->getFallbackAnalysis($activity, $responses);
            }

            return $this->normalizeResponse($parsed, $responses);

        } catch (Exception $e) {
            error_log("GeminiFeedbackAnalysisService Exception: " . $e->getMessage());
            return $this->getFallbackAnalysis($activity, $responses);
        }
    }

    /**
     * Builds the prompt payload containing the anonymized evaluation response data.
     */
    private function buildPrompt(array $activity, array $questions, array $responses): string
    {
        $title = $activity['title'] ?? 'Activity';
        $desc = $activity['description'] ?? '';
        $genObj = $activity['general_objectives'] ?? '';
        $specObj = $activity['specific_objectives'] ?? '';

        // Formulate evaluation questions list
        $qList = '';
        foreach ($questions as $idx => $q) {
            if (!is_array($q)) continue;
            $qList .= "- Q" . ($idx + 1) . ": " . ($q['question'] ?? '') . " (Type: " . ($q['type'] ?? 'rating') . ", Category: " . ($q['category'] ?? 'General') . ")\n";
        }

        // Anonymize and serialize response details to ensure privacy
        $serializedResponses = [];
        $anonMap = [];
        $anonCounter = 1;

        foreach ($responses as $r) {
            $rawName = trim($r['evaluator_name'] ?? '');
            if (empty($rawName)) {
                $anonName = 'Anonymous Participant';
            } else {
                if (!isset($anonMap[$rawName])) {
                    $anonMap[$rawName] = 'Participant ' . $anonCounter++;
                }
                $anonName = $anonMap[$rawName];
            }

            $serializedResponses[] = [
                'question' => $r['criteria'] ?? '',
                'rating' => $r['rating'] !== null ? (int)$r['rating'] : null,
                'comment' => trim($r['comments'] ?? ''),
                'respondent' => $anonName
            ];
        }

        $responsesJson = json_encode($serializedResponses, JSON_PRETTY_PRINT);

        return <<<EOT
Act as an academic activity feedback analyst for STI College. Your task is to analyze participant evaluation responses for a completed activity, identify key themes, summarize findings, and construct actionable recommendations.

ACTIVITY DETAILS:
- Title: {$title}
- Description: {$desc}
- General Objectives: {$genObj}
- Specific Objectives: {$specObj}

EVALUATION QUESTIONNAIRE:
{$qList}

PARTICIPANT RESPONSES (ANONYMIZED):
{$responsesJson}

CRITICAL ANALYTICAL RULES:
1. **Analyze Supplied Responses Only**: Extract insights *exclusively* from the participant ratings and comments provided above.
2. **Strictly No Hallucination / Fabrication**: Do not invent participant comments, names, suggestions, or quotes. Never generate fake quotes.
3. **Thematic Grouping**: Group semantically similar participant comments into meaningful themes.
4. **Themes Frequency Validation**: Calculate the approximate number of responses matching each theme. Do not declare a theme as a major trend if only 1 or 2 isolated responses mention it (unless clearly marked as minor).
5. **Clear Sentiment Categorization**: Distinguish positive feedback themes from improvement areas.
6. **Constructive Tone**: Maintain an objective, academic, professional, and constructive tone. Recommendations must read as helpful developmental suggestions, not administrative instructions.

Respond STRICTLY with valid JSON matching the following schema without any markdown formatting, backticks, or extra commentary:
{
  "overall_summary": "Provide a concise natural-language summary of the participant feedback.",
  "overall_feedback": {
    "label": "Positive | Mostly Positive | Mixed | Mostly Negative | Negative",
    "explanation": "Provide a brief explanation justifying the assigned tone label."
  },
  "positive_themes": [
    {
      "theme": "Positive Theme Name (e.g. Effective Speaker)",
      "summary": "Thematic summary of what participants liked regarding this aspect...",
      "frequency": 12
    }
  ],
  "improvement_themes": [
    {
      "theme": "Improvement Theme Name (e.g. Activity Duration)",
      "summary": "Thematic summary of issues or concerns raised regarding this aspect...",
      "frequency": 5
    }
  ],
  "common_suggestions": [
    "Specific common suggestion 1...",
    "Specific common suggestion 2..."
  ],
  "key_findings": [
    "Key qualitative or quantitative finding 1...",
    "Key qualitative or quantitative finding 2..."
  ],
  "recommendations": [
    "Actionable, constructive recommendation 1...",
    "Actionable, constructive recommendation 2..."
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
    private function normalizeResponse(array $data, array $responses): array
    {
        $summary = trim($data['overall_summary'] ?? 'Participant feedback has been successfully processed.');
        
        $tone = $data['overall_feedback'] ?? [];
        $toneLabel = trim($tone['label'] ?? 'Mostly Positive');
        if (!in_array($toneLabel, ['Positive', 'Mostly Positive', 'Mixed', 'Mostly Negative', 'Negative'], true)) {
            $toneLabel = 'Mostly Positive';
        }
        $toneExplanation = trim($tone['explanation'] ?? 'Based on participant rating scores and comment sentiments.');

        $posThemes = [];
        if (is_array($data['positive_themes'] ?? null)) {
            foreach ($data['positive_themes'] as $pt) {
                if (empty($pt['theme']) || empty($pt['summary'])) continue;
                $posThemes[] = [
                    'theme' => trim($pt['theme']),
                    'summary' => trim($pt['summary']),
                    'frequency' => (int)($pt['frequency'] ?? 1)
                ];
            }
        }

        $impThemes = [];
        if (is_array($data['improvement_themes'] ?? null)) {
            foreach ($data['improvement_themes'] as $it) {
                if (empty($it['theme']) || empty($it['summary'])) continue;
                $impThemes[] = [
                    'theme' => trim($it['theme']),
                    'summary' => trim($it['summary']),
                    'frequency' => (int)($it['frequency'] ?? 1)
                ];
            }
        }

        $suggs = $data['common_suggestions'] ?? [];
        if (!is_array($suggs)) $suggs = [];
        $suggs = array_filter(array_map('trim', $suggs));

        $findings = $data['key_findings'] ?? [];
        if (!is_array($findings)) $findings = [];
        $findings = array_filter(array_map('trim', $findings));

        $recs = $data['recommendations'] ?? [];
        if (!is_array($recs)) $recs = [];
        $recs = array_filter(array_map('trim', $recs));
        if (empty($recs)) {
            $recs = ['Consider adding more interactive elements in the next session.'];
        }

        return [
            'overall_summary' => $summary,
            'overall_feedback' => [
                'label' => $toneLabel,
                'explanation' => $toneExplanation
            ],
            'positive_themes' => $posThemes,
            'improvement_themes' => $impThemes,
            'common_suggestions' => array_values($suggs),
            'key_findings' => array_values($findings),
            'recommendations' => array_values($recs),
            'is_fallback' => false
        ];
    }

    /**
     * Rule-based fallback analysis when AI is unavailable.
     */
    public function getFallbackAnalysis(array $activity, array $responses): array
    {
        return [
            'overall_summary' => 'Offline/Fallback mode: Participant feedback is collected, but qualitative thematic analysis is temporarily offline.',
            'overall_feedback' => [
                'label' => 'Neutral',
                'explanation' => 'API is currently offline or unconfigured. Cannot assess tone of open-ended feedback.'
            ],
            'positive_themes' => [
                [
                    'theme' => 'Activity Conducted',
                    'summary' => 'The activity was conducted and responses were recorded.',
                    'frequency' => count($responses)
                ]
            ],
            'improvement_themes' => [],
            'common_suggestions' => [],
            'key_findings' => [
                'System is operating in fallback/offline mode.'
            ],
            'recommendations' => [
                'Please check your network connection or Gemini API key configuration.'
            ],
            'is_fallback' => true
        ];
    }
}
