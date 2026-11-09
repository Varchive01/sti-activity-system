<?php
/**
 * api/ai-proposal-guidance.php
 *
 * REST JSON API endpoint for AI-Assisted Proposal Guidance (Task 15).
 * Analyzes activity proposals for completeness, objective quality, consistency,
 * and actionable improvements using Google Gemini with intelligent rule-based fallback.
 */

ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/GeminiProposalGuidanceService.php';

// 1. Session & Authentication Check
startSession();
$user = currentUser();
if (!$user || empty($user['id'])) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Unauthorized: Please log in to request AI proposal guidance.']);
    exit;
}

// 2. Role Check
if (!in_array($user['role'], ['faculty', 'program_head', 'dean', 'admin1', 'admin2'], true)) {
    http_response_code(403);
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Forbidden: You do not have permission to access AI proposal guidance.']);
    exit;
}

// 3. HTTP Method Check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

// 4. Parse JSON or Form Input
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input) && !empty($_POST)) {
    $input = $_POST;
}

if (!is_array($input)) {
    http_response_code(400);
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Invalid request payload: JSON or form data required.']);
    exit;
}

$action = trim((string)($input['action'] ?? $_GET['action'] ?? 'review'));
$activityId = (int)($input['activity_id'] ?? $_GET['activity_id'] ?? 0);
$db = getDB();

// Normalize and safeguard Activity Title
$resolvedTitle = trim((string)($input['title'] ?? $input['activity_title'] ?? $input['event_title'] ?? ''));

// If title was not provided directly in payload, but activityId > 0, lookup activity from DB
if ($resolvedTitle === '' && $activityId > 0) {
    try {
        $stmtAct = $db->prepare("SELECT title, theme, source, venue, venue_address, event_date, start_time, end_time, target_participants, general_objectives, specific_objectives, rationale, involved_subjects FROM activities WHERE id = ? LIMIT 1");
        $stmtAct->execute([$activityId]);
        $actRow = $stmtAct->fetch(PDO::FETCH_ASSOC);
        if ($actRow && !empty($actRow['title'])) {
            $resolvedTitle = trim($actRow['title']);
            $input['title'] = $resolvedTitle;
            $input['activity_title'] = $resolvedTitle;
            // Backfill other missing event context
            foreach ($actRow as $k => $v) {
                if (empty($input[$k]) && !empty($v)) {
                    $input[$k] = $v;
                }
            }
        }
    } catch (Exception $dbEx) {
        error_log("api/ai-proposal-guidance.php DB lookup fallback error: " . $dbEx->getMessage());
    }
} else {
    $input['title'] = $resolvedTitle;
    $input['activity_title'] = $resolvedTitle;
}

// 5. Action: log_applied (logs when faculty accepts and applies a suggestion)
if ($action === 'log_applied') {
    if ($activityId > 0) {
        if (!canUserAccessActivity($activityId, 'modify', $user['id'], $user['role'], $user['dept'])) {
            http_response_code(403);
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Access denied: You cannot modify this proposal.']);
            exit;
        }

        $section = trim((string)($input['section'] ?? 'Proposal Guidance'));
        $details = trim((string)($input['details'] ?? 'Applied AI suggestion to refine proposal content.'));

        logProposalContribution($activityId, (int)$user['id'], 'applied_ai_guidance', $section, $details, $db);
    }

    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'AI recommendation application recorded in contribution history.'
    ]);
    exit;
}

// 5.5 Action: generate_sections (Generates AI suggestions for requested sections)
if ($action === 'generate_sections') {
    $requestedSections = $input['sections'] ?? [];
    if (!is_array($requestedSections) || empty($requestedSections)) {
        $requestedSections = ['objectives', 'materials', 'floor_plan', 'people', 'schedule', 'guidelines', 'faculty_tasks', 'evaluation_kpi'];
    }

    $guidanceService = new GeminiProposalGuidanceService();
    try {
        $suggestions = $guidanceService->generateProposalSections($input, $requestedSections);

        // Record AI suggestion generation in proposal contribution log if activity ID exists
        if ($activityId > 0 && function_exists('logProposalContribution') && !empty($user['id'])) {
            $secCount = count($requestedSections);
            logProposalContribution(
                $activityId,
                (int)$user['id'],
                'requested_ai_guidance',
                'Proposal Guidance',
                "Generated AI proposal suggestions for {$secCount} section(s) based on Step 1 event details.",
                $db
            );
        }

        ob_clean();
        echo json_encode([
            'success'     => true,
            'activity_id' => $activityId,
            'title'       => $input['title'] ?? '',
            'suggestions' => $suggestions
        ]);
        exit;
    } catch (Exception $e) {
        error_log("api/ai-proposal-guidance.php generate_sections Exception: " . $e->getMessage());
        $fallback = $guidanceService->getFallbackSectionSuggestions($input, $requestedSections);

        if ($activityId > 0 && function_exists('logProposalContribution') && !empty($user['id'])) {
            $secCount = count($requestedSections);
            logProposalContribution(
                $activityId,
                (int)$user['id'],
                'requested_ai_guidance',
                'Proposal Guidance',
                "Generated fallback AI proposal suggestions for {$secCount} section(s) based on Step 1 event details.",
                $db
            );
        }

        ob_clean();
        echo json_encode([
            'success'     => true,
            'activity_id' => $activityId,
            'title'       => $input['title'] ?? '',
            'suggestions' => $fallback
        ]);
        exit;
    }
}

// 6. Action: review (default)
// If referencing an existing activity, verify user has view access
if ($activityId > 0) {
    if (!canUserAccessActivity($activityId, 'view', $user['id'], $user['role'], $user['dept'])) {
        http_response_code(403);
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Access denied: You do not have permission to view or review this proposal.']);
        exit;
    }
}

try {
    // Compute data fingerprint for caching
    $guidanceService = new GeminiProposalGuidanceService();
    $cacheKey = 'ai_guidance_' . md5(json_encode([
        'title'        => $input['title'] ?? '',
        'gen_obj'      => $input['general_objectives'] ?? '',
        'spec_obj'     => $input['specific_objectives'] ?? '',
        'rationale'    => $input['rationale'] ?? '',
        'theme'        => $input['theme'] ?? '',
        'venue'        => $input['venue'] ?? '',
        'date'         => $input['event_date'] ?? '',
        'participants' => $input['target_participants'] ?? ''
    ]));

    $forceRefresh = !empty($input['force_refresh']) || !empty($_GET['force_refresh']);

    if (!$forceRefresh && isset($_SESSION[$cacheKey]) && is_array($_SESSION[$cacheKey])) {
        $cached = $_SESSION[$cacheKey];
        if ((time() - ($cached['time'] ?? 0)) < 300) { // 5-minute cache
            ob_clean();
            echo json_encode([
                'success'     => true,
                'cached'      => true,
                'activity_id' => $activityId,
                'data_hash'   => $cacheKey,
                'guidance'    => $cached['data']
            ]);
            exit;
        }
    }

    $guidance = $guidanceService->generateGuidance($input);

    $_SESSION[$cacheKey] = [
        'time' => time(),
        'data' => $guidance
    ];

    ob_clean();
    echo json_encode([
        'success'     => true,
        'cached'      => false,
        'activity_id' => $activityId,
        'data_hash'   => $cacheKey,
        'guidance'    => $guidance
    ]);
    exit;

} catch (Exception $e) {
    error_log("api/ai-proposal-guidance.php Exception: " . $e->getMessage() . " - invoking fallback guidance engine.");
    try {
        $fallbackGuidance = isset($guidanceService) ? $guidanceService->getFallbackGuidance($input) : (new GeminiProposalGuidanceService())->getFallbackGuidance($input);
        ob_clean();
        echo json_encode([
            'success'     => true,
            'cached'      => false,
            'activity_id' => $activityId,
            'data_hash'   => $cacheKey ?? 'fallback',
            'guidance'    => $fallbackGuidance
        ]);
        exit;
    } catch (Exception $fallbackErr) {
        http_response_code(500);
        ob_clean();
        echo json_encode([
            'success' => false,
            'error'   => 'An error occurred while generating AI proposal guidance. Please try again.'
        ]);
        exit;
    }
}

