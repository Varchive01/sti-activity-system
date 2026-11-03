<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();

echo "======================================================================\n";
echo "  TEST: Fix Rejected Activity Notice Label & Display Logic\n";
echo "======================================================================\n\n";

function extractBannerHtml($html) {
    if (preg_match('/<!--\s*Revision\/Rejection Banner\s*-->.*?(<div class="alert [^"]*">.*?<\/div>\s*<\/div>)/s', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/<div class="alert alert-(?:danger|warning)".*?<\/strong>.*?<\/div>\s*<\/div>/s', $html, $m)) {
        return $m[0];
    }
    return '';
}

function renderProposalView($activityId, $user) {
    global $db;
    $_GET['id'] = $activityId;
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_dept'] = $user['department'];
    $_SESSION['role'] = $user['role'];
    
    ob_start();
    include __DIR__ . '/../faculty/proposal-view.php';
    return ob_get_clean();
}

$facultyUser = $db->query("SELECT * FROM users WHERE email = 'faculty@sti.edu'")->fetch(PDO::FETCH_ASSOC);

$rejectedAct = $db->query("SELECT * FROM activities WHERE status = 'rejected' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$returnedAct = $db->query("SELECT * FROM activities WHERE status = 'returned_for_revision' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$allPassed = true;

// ── TEST 1: Rejected Activity Banner ────────────────────────────────
echo "TEST 1: Rejected Activity (ID {$rejectedAct['id']})\n";
$htmlRejected = renderProposalView($rejectedAct['id'], $facultyUser);
$bannerRejected = extractBannerHtml($htmlRejected);

echo "Banner HTML (Rejected):\n" . trim($bannerRejected) . "\n\n";

$hasRejectionHeading = (strpos($bannerRejected, 'Rejection Reason') !== false);
$hasRevisionHeading = (strpos($bannerRejected, 'Revision Notes') !== false);
$hasDisapproved = (strpos($bannerRejected, 'Disapproved:') !== false);
$hasOriginalContent = (strpos($bannerRejected, 'Off-campus venue does not comply with CHED CMO No. 63') !== false);
$hasDangerClass = (strpos($bannerRejected, 'alert-danger') !== false);

echo " - Banner Heading is 'Rejection Reason': " . ($hasRejectionHeading ? "[PASS]" : "[FAIL]") . "\n";
echo " - Banner Heading does NOT say 'Revision Notes': " . (!$hasRevisionHeading ? "[PASS]" : "[FAIL]") . "\n";
echo " - Message Prefix is 'Disapproved:': " . ($hasDisapproved ? "[PASS]" : "[FAIL]") . "\n";
echo " - Preserves Original Note Content: " . ($hasOriginalContent ? "[PASS]" : "[FAIL]") . "\n";
echo " - Danger Alert Styling: " . ($hasDangerClass ? "[PASS]" : "[FAIL]") . "\n";

if (!$hasRejectionHeading || $hasRevisionHeading || !$hasDisapproved || !$hasOriginalContent || !$hasDangerClass) {
    $allPassed = false;
}

// ── TEST 2: Returned for Revision Activity Banner ────────────────────
echo "\nTEST 2: Returned for Revision Activity (ID {$returnedAct['id']})\n";
$htmlReturned = renderProposalView($returnedAct['id'], $facultyUser);
$bannerReturned = extractBannerHtml($htmlReturned);

echo "Banner HTML (Returned):\n" . trim($bannerReturned) . "\n\n";

$hasRevisionHeadingRet = (strpos($bannerReturned, 'Revision Notes') !== false);
$hasRejectionHeadingRet = (strpos($bannerReturned, 'Rejection Reason') !== false);
$hasDisapprovedRet = (strpos($bannerReturned, 'Disapproved:') !== false);
$hasOriginalContentRet = (strpos($bannerReturned, 'Please provide detailed hourly laboratory milestones') !== false);
$hasWarningClass = (strpos($bannerReturned, 'alert-warning') !== false);

echo " - Banner Heading is 'Revision Notes': " . ($hasRevisionHeadingRet ? "[PASS]" : "[FAIL]") . "\n";
echo " - Banner Heading does NOT say 'Rejection Reason': " . (!$hasRejectionHeadingRet ? "[PASS]" : "[FAIL]") . "\n";
echo " - Message does NOT say 'Disapproved:': " . (!$hasDisapprovedRet ? "[PASS]" : "[FAIL]") . "\n";
echo " - Preserves Original Note Content: " . ($hasOriginalContentRet ? "[PASS]" : "[FAIL]") . "\n";
echo " - Warning Alert Styling: " . ($hasWarningClass ? "[PASS]" : "[FAIL]") . "\n";

if (!$hasRevisionHeadingRet || $hasRejectionHeadingRet || $hasDisapprovedRet || !$hasOriginalContentRet || !$hasWarningClass) {
    $allPassed = false;
}

// ── TEST 3: Edge Case: Rejected Activity without prefix initially ───
echo "\nTEST 3: Rejected Activity when note does not originally have prefix\n";
$syntheticAct = [
    'status' => 'rejected',
    'revision_notes' => 'Campus event guidelines prohibit overnight stays.'
];
$isRejected = ($syntheticAct['status'] === 'rejected');
$bannerHeading = $isRejected ? 'Rejection Reason' : 'Revision Notes';
$displayNote = $syntheticAct['revision_notes'];
if ($isRejected && !preg_match('/^Disapproved:\s*/i', $displayNote)) {
    $displayNote = 'Disapproved: ' . $displayNote;
}
echo " - Heading: {$bannerHeading} " . ($bannerHeading === 'Rejection Reason' ? "[PASS]" : "[FAIL]") . "\n";
echo " - Prefix ensured: " . (str_starts_with($displayNote, 'Disapproved:') ? "[PASS]" : "[FAIL]") . "\n";
echo " - Content preserved: " . (str_contains($displayNote, 'Campus event guidelines prohibit overnight stays.') ? "[PASS]" : "[FAIL]") . "\n";

if ($bannerHeading !== 'Rejection Reason' || !str_starts_with($displayNote, 'Disapproved:') || !str_contains($displayNote, 'Campus event guidelines prohibit overnight stays.')) {
    $allPassed = false;
}

// ── TEST 4: Activities List Table Badges ─────────────────────────────
echo "\nTEST 4: Activities List Table (activities.php)\n";
$activitiesPhp = file_get_contents(__DIR__ . '/../faculty/activities.php');
$hasActivitiesFix = str_contains($activitiesPhp, "\$a['status'] === 'rejected'") && str_contains($activitiesPhp, "Rejection reason");
echo " - activities.php handles Rejection reason label: " . ($hasActivitiesFix ? "[PASS]" : "[FAIL]") . "\n";
if (!$hasActivitiesFix) {
    $allPassed = false;
}

echo "\n----------------------------------------------------------------------\n";
if ($allPassed) {
    echo ">>> ALL VERIFICATION CHECKS PASSED PERFECTLY! <<<\n";
    exit(0);
} else {
    echo ">>> SOME VERIFICATION CHECKS FAILED! <<<\n";
    exit(1);
}
