<?php
/**
 * proposal_validator.php
 *
 * Implements AI-assisted activity proposal validation using Google Gemini API (model: gemini-1.5-flash).
 * Checks proposal completeness and internal alignment, handles free-tier rate limits gracefully,
 * stores results in `proposal_ai_validation`, and provides UI helpers for human reviewers.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

/**
 * Ensures the `proposal_ai_validation` table exists in the MySQL database.
 *
 * @param PDO $db
 * @return void
 */
function ensureProposalAiValidationTableExists(PDO $db): void
{
    $sql = "CREATE TABLE IF NOT EXISTS `proposal_ai_validation` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `proposal_id` int(11) NOT NULL,
        `is_complete` tinyint(1) NOT NULL DEFAULT 0,
        `is_aligned` tinyint(1) NOT NULL DEFAULT 0,
        `issues` text DEFAULT NULL,
        `suggestions` text DEFAULT NULL,
        `reviewed_by_human` tinyint(1) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_proposal_id` (`proposal_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $db->exec($sql);

    // Dynamic schema update to preserve rich AI evaluation results
    try {
        $stmt = $db->query("SHOW COLUMNS FROM `proposal_ai_validation` LIKE 'full_result'");
        if ($stmt->rowCount() === 0) {
            $db->exec("ALTER TABLE `proposal_ai_validation` ADD COLUMN `full_result` longtext DEFAULT NULL");
        }
    } catch (Exception $e) {
        error_log("AI Schema Update Error: " . $e->getMessage());
    }
}

/**
 * Retrieves the Gemini API key securely from environment variables or config constants.
 * Never hardcode the key.
 *
 * @return string
 */
if (!function_exists('getGeminiApiKeySecure')) {
    function getGeminiApiKeySecure(): string
    {
        $key = getenv('GEMINI_API_KEY');
        if (!$key && defined('GEMINI_API_KEY')) {
            $key = GEMINI_API_KEY;
        }
        if (!$key && isset($_ENV['GEMINI_API_KEY'])) {
            $key = $_ENV['GEMINI_API_KEY'];
        }
        if (!$key && isset($_SERVER['GEMINI_API_KEY'])) {
            $key = $_SERVER['GEMINI_API_KEY'];
        }
        if (!$key) {
            $envFile = __DIR__ . '/../../.env';
            if (file_exists($envFile)) {
                $envVars = parse_ini_file($envFile);
                if (isset($envVars['GEMINI_API_KEY'])) {
                    $key = (string)$envVars['GEMINI_API_KEY'];
                }
            }
        }
        return trim((string)$key);
    }
}

/**
 * Fetches a proposal record by ID from `proposals` or `activities` table.
 *
 * @param PDO $db
 * @param int $proposalId
 * @return array|null
 */
function fetchProposalRecord(PDO $db, int $proposalId): ?array
{
    // Check if `proposals` table exists and try querying it first
    $hasProposalsTable = false;
    try {
        $stmt = $db->query("SHOW TABLES LIKE 'proposals'");
        $hasProposalsTable = ($stmt && $stmt->rowCount() > 0);
    } catch (Exception $e) {
        $hasProposalsTable = false;
    }

    if ($hasProposalsTable) {
        $stmt = $db->prepare("SELECT * FROM `proposals` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$proposalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    // Fallback to querying `activities` table (primary table in STI Activity System)
    $stmt = $db->prepare("SELECT * FROM `activities` WHERE `id` = ? LIMIT 1");
    $stmt->execute([$proposalId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Builds the text prompt asking Gemini to validate proposal completeness and internal alignment.
 *
 * @param array $proposal
 * @return string
 */
function buildGeminiProposalPrompt(array $proposal): string
{
    $details = "";
    foreach ($proposal as $key => $val) {
        if ($key === 'kpis') continue;
        if (is_scalar($val) && $val !== '' && $val !== null) {
            $details .= "- {$key}: {$val}\n";
        }
    }

    $kpisText = "";
    if (!empty($proposal['kpis']) && is_array($proposal['kpis'])) {
        foreach ($proposal['kpis'] as $k) {
            $kpisText .= "- KPI Indicator: {$k['indicator']} | Target Metric: {$k['target_metric']} | Method: {$k['evaluation_method']}\n";
        }
    } else {
        $kpisText = "- No KPIs/Success Indicators specified.\n";
    }

    $today = date('Y-m-d');
    $prompt = <<<PROMPT
You are an AI proposal validation assistant for STI College Marikina.
Review the following activity proposal and check whether it is COMPLETE and INTERNALLY ALIGNED.

PROPOSAL RECORD:
{$details}
KPIs / SUCCESS INDICATORS:
{$kpisText}

VALIDATION RULES:
1. Completeness (`is_complete`):
   - Check if critical required fields are present and not empty.
   - Critical required fields include: title, general_objectives, specific_objectives, involved_subjects, rationale, venue, event_date, target_participants, and KPIs.
   - If any critical required field is missing, empty, or has placeholder text (e.g. "TBD", "---", "0"), set `is_complete` to false and include a specific message in `issues` (e.g., "Missing required field: general_objectives.").

2. Objective Alignment:
   - Check whether the General Objective and Specific Objectives are relevant to the proposed activity title and theme.
   - If they are unrelated or irrelevant, set `is_aligned` to false and add constructive feedback to `issues` explaining why.

3. KPI/Success Indicator Alignment:
   - Check whether the KPIs actually measure or support the stated objectives.
   - Example of aligned KPI:
     - Objective: Improve students' cybersecurity awareness
     - KPI: 80% of participants demonstrate improved cybersecurity knowledge (✅ Aligned)
   - Example of non-aligned KPI:
     - Objective: Improve students' cybersecurity awareness
     - KPI: 200 chairs used during the event (❌ Not aligned - this is just a resource count, not a metric measuring the objective)
   - If any KPIs fail to align with or measure the objectives, set `is_aligned` to false and add specific feedback/examples to `issues`.

4. Consistency:
   - Check whether different parts of the proposal agree with each other.
   - Example of inconsistency:
     - Title: Sports Fest
     - Description: AI Web Development Seminar (❌ Inconsistent - sports fest does not match web seminar)
   - If there is any logic discrepancy or mismatch in the description, title, or venue details, set `is_aligned` to false and flag the inconsistency in `issues`.

5. Clarity / Quality:
   - Assess objective clarity (are they specific, measurable, realistic?).
   - Check spelling and grammar. Provide suggestions if needed.

6. Safety and Advisory Constraints:
   - You must NOT approve the proposal.
   - You must NOT reject the proposal.
   - You must NOT make the final decision.
   - You must NOT change the answers automatically.
   - Provide only constructive, actionable issues and recommendations for improvement to help human reviewers (Events Committee Head, Dean) make their decision.

RESPOND STRICTLY IN VALID JSON FORMAT ONLY with the following structure (no markdown fences, no explanatory text outside JSON):
{
  "is_complete": true,
  "is_aligned": true,
  "issues": [],
  "suggestions": []
}
PROMPT;

    return trim($prompt);
}

/**
 * Strips markdown code fences if Gemini wraps JSON in ```json ... ```.
 *
 * @param string $text
 * @return string
 */
function cleanGeminiJsonResponse(string $text): string
{
    $text = trim($text);
    if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $text, $matches)) {
        return trim($matches[1]);
    }
    return $text;
}

/**
 * Fallback response when validation is unavailable due to API rate limits or network issues.
 * Never fails the whole request or blocks human review.
 *
 * @param PDO $db
 * @param int $proposalId
 * @return array
 */
function getFallbackValidationUnavailable(PDO $db, int $proposalId): array
{
    $result = [
        'is_complete' => true,
        'is_aligned'  => true,
        'issues'      => ['Validation unavailable, please review manually.'],
        'suggestions' => ['AI validation could not be completed due to rate limits or API error. Please verify fields manually.']
    ];

    saveProposalAiValidationResult($db, $proposalId, $result);
    return $result;
}

/**
 * Saves AI validation results to `proposal_ai_validation` table linked by proposal_id.
 *
 * @param PDO $db
 * @param int $proposalId
 * @param array $validation
 * @return void
 */
function saveProposalAiValidationResult(PDO $db, int $proposalId, array $validation): void
{
    ensureProposalAiValidationTableExists($db);

    $isComplete = !empty($validation['is_complete']) ? 1 : 0;
    $isAligned  = !empty($validation['is_aligned']) ? 1 : 0;
    $issues     = json_encode(is_array($validation['issues'] ?? null) ? $validation['issues'] : [], JSON_UNESCAPED_UNICODE);
    $suggs      = json_encode(is_array($validation['suggestions'] ?? null) ? $validation['suggestions'] : [], JSON_UNESCAPED_UNICODE);
    $fullResult = $validation['full_result'] ?? null;

    $stmt = $db->prepare("INSERT INTO `proposal_ai_validation`
        (`proposal_id`, `is_complete`, `is_aligned`, `issues`, `suggestions`, `full_result`, `reviewed_by_human`)
        VALUES (?, ?, ?, ?, ?, ?, 0)
        ON DUPLICATE KEY UPDATE
            `is_complete` = VALUES(`is_complete`),
            `is_aligned` = VALUES(`is_aligned`),
            `issues` = VALUES(`issues`),
            `suggestions` = VALUES(`suggestions`),
            `full_result` = VALUES(`full_result`),
            `reviewed_by_human` = 0,
            `updated_at` = NOW()");

    $stmt->execute([
        $proposalId,
        $isComplete,
        $isAligned,
        $issues,
        $suggs,
        $fullResult
    ]);
}

/**
 * Validates a proposal using Google Gemini API.
 *
 * @param int $proposalId
 * @return array Standardized legacy mapping array.
 */
function validateProposal(int $proposalId): array
{
    $db = getDB();
    ensureProposalAiValidationTableExists($db);

    $proposal = fetchProposalRecord($db, $proposalId);
    if (!$proposal) {
        return [
            'is_complete' => false,
            'is_aligned'  => false,
            'issues'      => ['Proposal record not found in database.'],
            'suggestions' => ['Verify that the proposal ID exists before running AI validation.']
        ];
    }

    // Fetch KPI entries for alignment validation
    $kpiStmt = $db->prepare("SELECT indicator, target_metric, evaluation_method FROM kpi_evaluations WHERE activity_id = ?");
    $kpiStmt->execute([$proposalId]);
    $proposal['kpis'] = $kpiStmt->fetchAll(PDO::FETCH_ASSOC);

    $apiKey = getGeminiApiKeySecure();
    require_once __DIR__ . '/../../services/GeminiProposalValidationService.php';
    $service = new GeminiProposalValidationService($apiKey);

    try {
        $rawResult = $service->validateProposal($proposal);
        $mappedResult = $service->mapToDatabaseFormat($rawResult);
        saveProposalAiValidationResult($db, $proposalId, $mappedResult);
        return $mappedResult;
    } catch (Exception $e) {
        error_log("validateProposal({$proposalId}) Exception: " . $e->getMessage());
        return getFallbackValidationUnavailable($db, $proposalId);
    }
}

/**
 * Retrieves the stored AI validation record for a proposal.
 *
 * @param int $proposalId
 * @return array|null
 */
function getProposalAiValidation(int $proposalId): ?array
{
    $db = getDB();
    ensureProposalAiValidationTableExists($db);

    $stmt = $db->prepare("SELECT * FROM `proposal_ai_validation` WHERE `proposal_id` = ? LIMIT 1");
    $stmt->execute([$proposalId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $row['is_complete']       = (bool)$row['is_complete'];
    $row['is_aligned']        = (bool)$row['is_aligned'];
    $row['reviewed_by_human'] = (bool)$row['reviewed_by_human'];
    $row['issues']            = json_decode((string)$row['issues'], true) ?: [];
    $row['suggestions']       = json_decode((string)$row['suggestions'], true) ?: [];

    return $row;
}

/**
 * Marks a proposal's AI validation results as reviewed by a human reviewer.
 *
 * @param int $proposalId
 * @return bool
 */
function markAiValidationReviewed(int $proposalId): bool
{
    $db = getDB();
    ensureProposalAiValidationTableExists($db);

    $stmt = $db->prepare("UPDATE `proposal_ai_validation` SET `reviewed_by_human` = 1, `updated_at` = NOW() WHERE `proposal_id` = ?");
    return $stmt->execute([$proposalId]);
}

/**
 * Renders the AI-assisted proposal validation UI section on human reviewer pages.
 * Displays issues and suggestions as read-only text with "AI-generated — please verify" label
 * and a button to mark as reviewed.
 *
 * @param int $proposalId
 * @return void
 */
function renderAiValidationSection(int $proposalId, bool $showReviewButton = true): void
{
    $aiVal = getProposalAiValidation($proposalId);
    if (!$aiVal) {
        // Automatically trigger AI validation if not yet performed
        $result = validateProposal($proposalId);
        $aiVal = getProposalAiValidation($proposalId);
        if (!$aiVal) {
            $aiVal = [
                'is_complete'       => $result['is_complete'] ?? true,
                'is_aligned'        => $result['is_aligned'] ?? true,
                'issues'            => $result['issues'] ?? [],
                'suggestions'       => $result['suggestions'] ?? [],
                'reviewed_by_human' => false
            ];
        }
    }

    $isComplete     = !empty($aiVal['is_complete']);
    $isAligned      = !empty($aiVal['is_aligned']);
    $issues         = is_array($aiVal['issues']) ? $aiVal['issues'] : [];
    $suggestions    = is_array($aiVal['suggestions']) ? $aiVal['suggestions'] : [];
    $isReviewed     = !empty($aiVal['reviewed_by_human']);

    // Check if it's the fallback unavailable message
    $isUnavailable  = (!empty($issues) && stripos($issues[0], 'Validation unavailable') !== false);
    ?>
    <!-- ── AI-ASSISTED PROPOSAL VALIDATION SECTION ── -->
    <div class="ai-validation-card" id="ai-validation-box-<?= $proposalId ?>" style="
        margin-bottom: 22px;
        border-radius: 12px;
        border: 1.5px solid #6366F1;
        background: linear-gradient(145deg, #EEF2FF 0%, #FFFFFF 100%);
        box-shadow: 0 4px 16px rgba(99, 102, 241, 0.12);
        overflow: hidden;
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
    ">
        <div style="
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            background: linear-gradient(90deg, #4F46E5 0%, #6366F1 100%);
            color: #FFFFFF;
            flex-wrap: wrap;
            gap: 10px;
        ">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 1.15rem;">🤖</span>
                <div>
                    <h3 style="margin: 0; font-size: 0.95rem; font-weight: 700; letter-spacing: 0.3px;">AI Proposal Validation</h3>
                    <div style="font-size: 0.72rem; opacity: 0.92; font-weight: 500;">
                        AI-generated — please verify
                    </div>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="
                    display: inline-block;
                    padding: 3px 10px;
                    border-radius: 99px;
                    font-size: 0.72rem;
                    font-weight: 700;
                    background: <?= $isComplete ? '#10B981' : '#F59E0B' ?>;
                    color: #FFFFFF;
                ">
                    Completeness: <?= $isComplete ? 'Complete' : 'Incomplete' ?>
                </span>
                <span style="
                    display: inline-block;
                    padding: 3px 10px;
                    border-radius: 99px;
                    font-size: 0.72rem;
                    font-weight: 700;
                    background: <?= $isAligned ? '#10B981' : '#F59E0B' ?>;
                    color: #FFFFFF;
                ">
                    Alignment: <?= $isAligned ? 'Aligned' : 'Issues Found' ?>
                </span>
            </div>
        </div>

        <div style="padding: 18px 20px; color: #1E293B;">
            <?php if ($isUnavailable): ?>
                <div style="
                    background: #FEF3C7;
                    border: 1px solid #FDE68A;
                    border-radius: 8px;
                    padding: 12px 14px;
                    color: #92400E;
                    font-size: 0.85rem;
                    font-weight: 600;
                    margin-bottom: 14px;
                ">
                    ⚠️ <?= htmlspecialchars($issues[0]) ?>
                </div>
            <?php else: ?>
                <!-- Flagged Issues -->
                <div style="margin-bottom: 16px;">
                    <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #475569; margin-bottom: 6px;">
                        Flagged Issues
                    </div>
                    <?php if (!empty($issues)): ?>
                        <ul style="margin: 0; padding-left: 20px; font-size: 0.88rem; color: #B91C1C; line-height: 1.5;">
                            <?php foreach ($issues as $issue): ?>
                                <li style="margin-bottom: 4px; font-weight: 500;"><?= htmlspecialchars((string)$issue) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div style="font-size: 0.88rem; color: #059669; font-weight: 600;">
                            ✓ No missing required fields or critical issues flagged.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Suggestions -->
                <?php if (!empty($suggestions)): ?>
                <div style="margin-bottom: 16px;">
                    <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #475569; margin-bottom: 6px;">
                        AI Suggestions for Reviewer
                    </div>
                    <ul style="margin: 0; padding-left: 20px; font-size: 0.88rem; color: #334155; line-height: 1.5;">
                        <?php foreach ($suggestions as $sugg): ?>
                            <li style="margin-bottom: 4px;"><?= htmlspecialchars((string)$sugg) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($showReviewButton): ?>
            <!-- Footer & Review Button -->
            <div style="
                display: flex;
                align-items: center;
                justify-content: space-between;
                border-top: 1px solid #E2E8F0;
                padding-top: 14px;
                margin-top: 6px;
                flex-wrap: wrap;
                gap: 10px;
            ">
                <span style="font-size: 0.78rem; color: #64748B;">
                    <em>Note: This analysis only informs human reviewers and does not auto-reject proposals.</em>
                </span>

                <div>
                    <button
                        type="button"
                        id="btn-mark-ai-reviewed-<?= $proposalId ?>"
                        onclick="markAiValidationReviewedAction(<?= $proposalId ?>, this)"
                        style="
                            border: 1px solid <?= $isReviewed ? '#10B981' : '#6366F1' ?>;
                            background: <?= $isReviewed ? '#D1FAE5' : '#6366F1' ?>;
                            color: <?= $isReviewed ? '#065F46' : '#FFFFFF' ?>;
                            font-weight: 600;
                            font-size: 0.8rem;
                            padding: 7px 16px;
                            border-radius: 8px;
                            cursor: <?= $isReviewed ? 'default' : 'pointer' ?>;
                            transition: all 0.2s ease;
                            display: inline-flex;
                            align-items: center;
                            gap: 6px;
                        "
                        <?= $isReviewed ? 'disabled' : '' ?>
                    >
                        <span><?= $isReviewed ? '✓ Reviewed by human' : 'Mark as Reviewed' ?></span>
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($showReviewButton): ?>
    <script>
    function markAiValidationReviewedAction(proposalId, btn) {
        if (btn.disabled) return;
        btn.disabled = true;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<span>Saving...</span>';

        fetch('<?= BASE_URL ?>/api/mark-ai-reviewed.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'id=' + encodeURIComponent(proposalId)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                btn.style.background = '#D1FAE5';
                btn.style.borderColor = '#10B981';
                btn.style.color = '#065F46';
                btn.style.cursor = 'default';
                btn.innerHTML = '<span>✓ Reviewed by human</span>';
            } else {
                alert('Could not update review status: ' + (data.error || 'Unknown error'));
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        })
        .catch(err => {
            console.error('AI review update error:', err);
            alert('A network error occurred while updating review status.');
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
    }
    </script>
    <?php endif; ?>
    <?php
}
