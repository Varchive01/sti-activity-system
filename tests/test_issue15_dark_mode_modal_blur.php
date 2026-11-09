<?php
/**
 * tests/test_issue15_dark_mode_modal_blur.php
 *
 * Automated verification suite for Issue 15:
 * Improve the dark-mode modal background blur for Floor Plan Requirement
 * and Add Collaborators dialogs.
 *
 * Verifies:
 * 1. Global CSS backdrop blur and dark overlay in assets/css/main.css.
 * 2. Explicit rules for #floorPlanRequirementModal, #initCollabPromptModal, #collaboratorsModal.
 * 3. Preservation of dark theme content colors, headers, footers, tables, and inputs.
 * 4. Page template overrides and inline style cleanup in proposal-create.php and proposal-edit.php.
 * 5. Non-blocking button interactions and backdrop click/ESC dismiss handlers.
 * 6. Live HTTP verification of modal elements in proposal-create.php and proposal-edit.php.
 */

$baseUrl = 'http://localhost/sti-activity-system';
$passCount = 0;
$failCount = 0;

function assertCheck(bool $condition, string $msg): void {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$msg}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$msg}\n";
        $failCount++;
    }
}

function makeHttpRequest(string $url, string $method = 'GET', array $data = [], ?string $cookie = null): array {
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
    }
    if ($cookie) {
        $opts[CURLOPT_COOKIE] = $cookie;
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    return ['status' => $httpCode, 'headers' => $headers, 'body' => $body];
}

function login(string $baseUrl, string $email, string $password): ?string {
    $res = makeHttpRequest("{$baseUrl}/auth/login.php", 'POST', [
        'email' => $email,
        'password' => $password
    ]);
    if (preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $res['headers'], $matches)) {
        return !empty($matches[1]) ? end($matches[1]) : null;
    }
    return null;
}

echo "============================================================\n";
echo "  TEST SUITE: ISSUE 15 DARK-MODE MODAL BACKGROUND BLUR     \n";
echo "============================================================\n\n";

// ── 1. Static CSS Analysis in assets/css/main.css ───────────────────
echo "--- 1. Global CSS Modal Backdrop Blur & Theme Rules ---\n";
$mainCss = file_get_contents(__DIR__ . '/../assets/css/main.css');

assertCheck(str_contains($mainCss, '#floorPlanRequirementModal'), "main.css includes rule targeting #floorPlanRequirementModal");
assertCheck(str_contains($mainCss, '#initCollabPromptModal'), "main.css includes rule targeting #initCollabPromptModal");
assertCheck(str_contains($mainCss, '#collaboratorsModal'), "main.css includes rule targeting #collaboratorsModal");

// Verify Backdrop Blur in Light and Dark Modes
assertCheck(str_contains($mainCss, 'backdrop-filter: blur(10px)') || str_contains($mainCss, 'backdrop-filter: blur(12px)'), "main.css sets backdrop-filter blur of at least 10px");
assertCheck(str_contains($mainCss, '-webkit-backdrop-filter: blur(10px)') || str_contains($mainCss, '-webkit-backdrop-filter: blur(12px)'), "main.css sets webkit backdrop-filter blur");

// Verify Dark Mode Selectors and Overlay Colors
assertCheck(str_contains($mainCss, '[data-theme="dark"] #floorPlanRequirementModal'), "main.css targets [data-theme='dark'] #floorPlanRequirementModal");
assertCheck(str_contains($mainCss, '[data-theme="dark"] #collaboratorsModal'), "main.css targets [data-theme='dark'] #collaboratorsModal");
assertCheck(str_contains($mainCss, 'rgba(4, 9, 20, 0.72)'), "main.css uses translucent dark overlay rgba(4, 9, 20, 0.72)");

// Verify Dark Mode Modal Content Colors
assertCheck(str_contains($mainCss, '[data-theme="dark"] #floorPlanRequirementModal .modal-content'), "main.css styles dark modal-content");
assertCheck(str_contains($mainCss, '[data-theme="dark"] #floorPlanRequirementModal .modal-header'), "main.css styles dark modal-header");
assertCheck(str_contains($mainCss, '[data-theme="dark"] #floorPlanRequirementModal .modal-footer'), "main.css styles dark modal-footer");
assertCheck(str_contains($mainCss, '[data-theme="dark"] #collaboratorsModal select'), "main.css styles dark collaborator selects");
assertCheck(str_contains($mainCss, '[data-theme="dark"] #collaboratorsModal table'), "main.css styles dark collaborator table");

// ── 2. proposal-create.php Template & Script Verification ───────────
echo "\n--- 2. proposal-create.php Modal Backdrop & Interaction ---\n";
$createPhp = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');

assertCheck(str_contains($createPhp, 'backdrop-filter: blur(10px) !important;'), "proposal-create.php .modal style has backdrop-filter blur");
assertCheck(str_contains($createPhp, '[data-theme="dark"] .modal'), "proposal-create.php has [data-theme='dark'] .modal style");
assertCheck(str_contains($createPhp, '[data-theme="dark"] .modal-backdrop'), "proposal-create.php has [data-theme='dark'] .modal-backdrop style");
assertCheck(!str_contains($createPhp, '<div class="modal fade" id="floorPlanRequirementModal" tabindex="-1" aria-labelledby="fpReqModalLabel" aria-hidden="true" style="display:none; background:rgba(10,22,40,0.6);'), "proposal-create.php removed conflicting hardcoded inline background on floor plan modal");
assertCheck(!str_contains($createPhp, '<div class="modal fade" id="collaboratorsModal" tabindex="-1" aria-labelledby="collaboratorsModalLabel" aria-hidden="true" style="display:none; background:rgba(10,22,40,0.6);'), "proposal-create.php removed conflicting hardcoded inline background on collaborators modal");

// Verify non-blocking interaction & dismiss handlers
assertCheck(str_contains($createPhp, "['floorPlanRequirementModal', 'initCollabPromptModal', 'collaboratorsModal'].forEach"), "proposal-create.php has backdrop click dismiss handler");
assertCheck(str_contains($createPhp, "e.key === 'Escape'"), "proposal-create.php has ESC key dismiss listener");
assertCheck(str_contains($createPhp, 'pointer-events: auto;'), "proposal-create.php modal-content preserves pointer-events: auto");

// ── 3. proposal-edit.php Template & Script Verification ─────────────
echo "\n--- 3. proposal-edit.php Modal Backdrop & Interaction ---\n";
$editPhp = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');

assertCheck(str_contains($editPhp, 'backdrop-filter: blur(10px) !important;'), "proposal-edit.php .modal style has backdrop-filter blur");
assertCheck(str_contains($editPhp, '[data-theme="dark"] .modal'), "proposal-edit.php has [data-theme='dark'] .modal style");
assertCheck(str_contains($editPhp, '[data-theme="dark"] .modal-backdrop'), "proposal-edit.php has [data-theme='dark'] .modal-backdrop style");
assertCheck(!str_contains($editPhp, '<div class="modal fade" id="floorPlanRequirementModal" tabindex="-1" aria-labelledby="fpReqModalLabel" aria-hidden="true" style="display:none; background:rgba(10,22,40,0.6);'), "proposal-edit.php removed conflicting hardcoded inline background on floor plan modal");
assertCheck(!str_contains($editPhp, '<div class="modal fade" id="collaboratorsModal" tabindex="-1" aria-labelledby="collaboratorsModalLabel" aria-hidden="true" style="display:none; background:rgba(10,22,40,0.6);'), "proposal-edit.php removed conflicting hardcoded inline background on collaborators modal");

// Verify dismiss handlers
assertCheck(str_contains($editPhp, "['floorPlanRequirementModal', 'collaboratorsModal'].forEach"), "proposal-edit.php has backdrop click dismiss handler");
assertCheck(str_contains($editPhp, "e.key === 'Escape'"), "proposal-edit.php has ESC key dismiss listener");

// ── 4. Live Session DOM & Modal Presence Check ──────────────────────
echo "\n--- 4. Live Session HTTP DOM Verification ---\n";
$facCookie = login($baseUrl, 'faculty@sti.edu', 'password');
assertCheck(!empty($facCookie), "Faculty authenticated successfully");

$resCreate = makeHttpRequest("{$baseUrl}/faculty/proposal-create.php", 'GET', [], $facCookie);
assertCheck($resCreate['status'] === 200, "proposal-create.php loads successfully (HTTP 200)");
assertCheck(str_contains($resCreate['body'], 'id="floorPlanRequirementModal"'), "proposal-create.php DOM includes floorPlanRequirementModal");
assertCheck(str_contains($resCreate['body'], 'id="initCollabPromptModal"'), "proposal-create.php DOM includes initCollabPromptModal");
assertCheck(str_contains($resCreate['body'], 'id="collaboratorsModal"'), "proposal-create.php DOM includes collaboratorsModal");
assertCheck(str_contains($resCreate['body'], 'btnFpModalYes') && str_contains($resCreate['body'], 'btnFpModalNo'), "Floor plan modal Yes/No buttons are present and accessible");

// Check proposal-edit.php with an existing draft or proposal
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$act = $db->query("SELECT id FROM activities WHERE faculty_id = (SELECT id FROM users WHERE email='faculty@sti.edu') AND status IN ('draft', 'returned_for_revision') ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($act) {
    $resEdit = makeHttpRequest("{$baseUrl}/faculty/proposal-edit.php?id={$act['id']}", 'GET', [], $facCookie);
    assertCheck($resEdit['status'] === 200, "proposal-edit.php loads successfully (HTTP 200)");
    assertCheck(str_contains($resEdit['body'], 'id="floorPlanRequirementModal"'), "proposal-edit.php DOM includes floorPlanRequirementModal");
    assertCheck(str_contains($resEdit['body'], 'id="collaboratorsModal"'), "proposal-edit.php DOM includes collaboratorsModal");
} else {
    echo "  [INFO] No existing activity found for edit page check, verified via template analysis.\n";
}

// ── 5. Summary ──────────────────────────────────────────────────────
echo "\n============================================================\n";
echo "  TEST SUMMARY: {$passCount} passed, {$failCount} failed\n";
echo "============================================================\n";

if ($failCount > 0) {
    exit(1);
}

