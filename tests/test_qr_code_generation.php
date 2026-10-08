<?php
/**
 * Focused Verification Suite for Local QR Code Generation
 *
 * Verifies:
 * 1. Valid Google Forms URLs produce valid SVG QR codes locally.
 * 2. Empty/missing URLs produce no broken QR element (return null).
 * 3. Malformed and non-HTTP URLs are safely rejected.
 * 4. Zero external API calls or third-party domains requested.
 * 5. Proposal view rendering preserves link and renders QR only on valid URL.
 */

require_once __DIR__ . '/../includes/QrCodeService.php';

echo "========================================================================\n";
echo "  LOCAL QR CODE GENERATION FOCUSED VERIFICATION\n";
echo "========================================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $title, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$title}\n";
        $passed++;
    } else {
        echo " [FAIL] {$title}" . ($detail ? " - {$detail}" : "") . "\n";
        $failed++;
    }
}

// -------------------------------------------------------------------------
// 1. Valid Google Forms URLs produce QR codes
// -------------------------------------------------------------------------
echo "--- 1. Valid Google Forms URL Tests ---\n";

$longFormUrl = 'https://docs.google.com/forms/d/e/1FAIpQLScMockFormId_ABC123XYZ/viewform';
$shortFormUrl = 'https://forms.gle/SampleForm123';

$svg1 = QrCodeService::generateSvg($longFormUrl, 160);
assertTest("1.1 Long Google Forms URL produces non-null SVG", $svg1 !== null);
assertTest("1.2 Long URL SVG starts with <svg element", str_starts_with(trim((string)$svg1), '<svg'));
assertTest("1.3 Long URL SVG contains closing </svg>", str_contains((string)$svg1, '</svg>'));
assertTest("1.4 Long URL SVG has expected width/height attributes", str_contains((string)$svg1, 'width="160"') && str_contains((string)$svg1, 'height="160"'));
assertTest("1.5 Long URL SVG contains viewBox and rect modules", str_contains((string)$svg1, 'viewBox=') && str_contains((string)$svg1, '<rect'));

$svg2 = QrCodeService::generateSvg($shortFormUrl, 180);
assertTest("1.6 Short Google Forms URL produces valid SVG", $svg2 !== null && str_starts_with(trim((string)$svg2), '<svg'));

// -------------------------------------------------------------------------
// 2. Empty / Missing URL produces no broken element
// -------------------------------------------------------------------------
echo "\n--- 2. Empty & Missing URL Tests ---\n";

$nullRes = QrCodeService::generateSvg(null);
assertTest("2.1 Null URL produces null (no broken element)", $nullRes === null);

$emptyRes = QrCodeService::generateSvg('');
assertTest("2.2 Empty string URL produces null", $emptyRes === null);

$whitespaceRes = QrCodeService::generateSvg('   ');
assertTest("2.3 Whitespace URL produces null", $whitespaceRes === null);

assertTest("2.4 isValidUrl correctly reports false for empty values", 
    !QrCodeService::isValidUrl(null) && 
    !QrCodeService::isValidUrl('') && 
    !QrCodeService::isValidUrl('   ')
);

// -------------------------------------------------------------------------
// 3. Malformed / Non-HTTP URLs safely rejected
// -------------------------------------------------------------------------
echo "\n--- 3. Malformed & Non-HTTP URL Safety Tests ---\n";

$malformedCases = [
    'javascript:alert(1)'       => 'JavaScript URI scheme',
    'file:///etc/passwd'        => 'Local file scheme',
    'data:text/html;base64,...' => 'Data URI scheme',
    'ftp://files.example.com'   => 'FTP scheme',
    'not a url at all'          => 'Plain text non-URL',
    'http://'                   => 'Incomplete HTTP scheme without host',
    'https://'                  => 'Incomplete HTTPS scheme without host',
    'https:// invalid host'     => 'Host containing spaces',
];

foreach ($malformedCases as $badUrl => $label) {
    $isValid = QrCodeService::isValidUrl($badUrl);
    $svg = QrCodeService::generateSvg($badUrl);
    assertTest("3.x Rejected {$label} safely", !$isValid && $svg === null, "URL: {$badUrl}");
}

// -------------------------------------------------------------------------
// 4. Verification that NO external QR API or domain is requested
// -------------------------------------------------------------------------
echo "\n--- 4. External QR Service Isolation Tests ---\n";

$knownExternalQrDomains = [
    'chart.googleapis.com',
    'api.qrserver.com',
    'qr-code-generator.com',
    'qrcode.kaywa.com',
    'quickchart.io',
    'api.qr-code-generator.com',
    'chart.chrome.com'
];

$serviceFileContent = file_get_contents(__DIR__ . '/../includes/QrCodeService.php');
$foundExternalDomain = false;
foreach ($knownExternalQrDomains as $domain) {
    if (stripos($serviceFileContent, $domain) !== false) {
        $foundExternalDomain = true;
        break;
    }
}
assertTest("4.1 QrCodeService source contains NO third-party QR API domains", !$foundExternalDomain);

// Verify SVG output does not reference external resources
$foundExternalInSvg = false;
foreach ($knownExternalQrDomains as $domain) {
    if (stripos((string)$svg1, $domain) !== false) {
        $foundExternalInSvg = true;
        break;
    }
}
assertTest("4.2 Generated SVG contains NO external URLs or third-party links", !$foundExternalInSvg);

// -------------------------------------------------------------------------
// 5. Proposal View Rendering Integration Checks
// -------------------------------------------------------------------------
echo "\n--- 5. Proposal View Rendering Simulation Tests ---\n";

$proposalViewContent = file_get_contents(__DIR__ . '/../faculty/proposal-view.php');

assertTest("5.1 proposal-view.php includes QrCodeService.php", str_contains($proposalViewContent, 'QrCodeService.php'));
assertTest("5.2 proposal-view.php guards QR rendering with isValidUrl check", str_contains($proposalViewContent, 'QrCodeService::isValidUrl'));
assertTest("5.3 proposal-view.php preserves clickable Google Form link", str_contains($proposalViewContent, '<strong>Google Form Link:</strong>'));
assertTest("5.4 proposal-view.php contains print-friendly stylesheet", str_contains($proposalViewContent, '@media print'));
assertTest("5.5 proposal-view.php contains print QR button with no-print class", str_contains($proposalViewContent, 'Print QR Code') && str_contains($proposalViewContent, 'no-print'));

echo "\n========================================================================\n";
echo " VERIFICATION SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
