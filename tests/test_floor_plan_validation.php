<?php
/**
 * test_floor_plan_validation.php
 *
 * Official automated test suite for isValidFloorPlanFile() in includes/functions.php
 *
 * Scope:
 * 1. Valid uploads: PNG, JPEG (.jpg, .jpeg), and PDF
 * 2. Invalid extensions (.txt, .exe, .gif, .webp)
 * 3. Disguised/mismatched content (fake PNG, fake PDF, extension mismatch, video payload)
 * 4. Oversized upload (size > MAX_FILE_SIZE)
 * 5. PHP upload errors (UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_FILE)
 * 6. Missing or empty file data (empty name, non-existent tmp_name, 0-byte file)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

echo "========================================================================\n";
echo "  STI ACTIVITY SYSTEM - FLOOR PLAN VALIDATION TEST SUITE\n";
echo "  Testing function: isValidFloorPlanFile()\n";
echo "========================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertCheck(string $testName, bool $condition, string $detail = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$testName}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$testName}" . ($detail ? " - {$detail}" : "") . "\n";
    }
}

// Create dedicated temporary directory for test fixtures
$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sti_fp_test_' . uniqid();
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}

// Binary fixtures
$validPngBytes = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
);
$validJpgBytes = base64_decode(
    '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='
);
$validPdfBytes = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
$videoMp4Bytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00isommp42\x00\x00\x00\x08free";
$plainTextBytes = "This is a plain text file pretending to be a floor plan image.";

// Write fixture files to temporary folder
$pngFile = $tempDir . DIRECTORY_SEPARATOR . 'valid.png';
file_put_contents($pngFile, $validPngBytes);

$jpgFile = $tempDir . DIRECTORY_SEPARATOR . 'valid.jpg';
file_put_contents($jpgFile, $validJpgBytes);

$jpegFile = $tempDir . DIRECTORY_SEPARATOR . 'valid.jpeg';
file_put_contents($jpegFile, $validJpgBytes);

$pdfFile = $tempDir . DIRECTORY_SEPARATOR . 'valid.pdf';
file_put_contents($pdfFile, $validPdfBytes);

$fakePngWithText = $tempDir . DIRECTORY_SEPARATOR . 'disguised_text.png';
file_put_contents($fakePngWithText, $plainTextBytes);

$fakePdfWithText = $tempDir . DIRECTORY_SEPARATOR . 'disguised_text.pdf';
file_put_contents($fakePdfWithText, $plainTextBytes);

$fakePngWithVideo = $tempDir . DIRECTORY_SEPARATOR . 'disguised_video.png';
file_put_contents($fakePngWithVideo, $videoMp4Bytes);

$fakePdfWithVideo = $tempDir . DIRECTORY_SEPARATOR . 'disguised_video.pdf';
file_put_contents($fakePdfWithVideo, $videoMp4Bytes);

$pngRenamedPdf = $tempDir . DIRECTORY_SEPARATOR . 'png_mismatched_as.pdf';
file_put_contents($pngRenamedPdf, $validPngBytes);

$pdfRenamedPng = $tempDir . DIRECTORY_SEPARATOR . 'pdf_mismatched_as.png';
file_put_contents($pdfRenamedPng, $validPdfBytes);

$emptyFile = $tempDir . DIRECTORY_SEPARATOR . 'empty.png';
file_put_contents($emptyFile, '');

$gifFile = $tempDir . DIRECTORY_SEPARATOR . 'image.gif';
file_put_contents($gifFile, "GIF89a\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;");

$txtFile = $tempDir . DIRECTORY_SEPARATOR . 'plan.txt';
file_put_contents($txtFile, $plainTextBytes);

$exeFile = $tempDir . DIRECTORY_SEPARATOR . 'plan.exe';
file_put_contents($exeFile, "MZ\x90\x00\x03\x00\x00\x00");

try {
    // -------------------------------------------------------------
    // GROUP 1: Valid Uploads (PNG, JPG, JPEG, PDF)
    // -------------------------------------------------------------
    echo "--- Group 1: Valid File Types ---\n";

    $fileValidPng = [
        'name'     => 'floor_plan_ground.png',
        'type'     => 'image/png',
        'tmp_name' => $pngFile,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($pngFile)
    ];
    assertCheck("Valid PNG floor plan is accepted", isValidFloorPlanFile($fileValidPng) === true);

    $fileValidJpg = [
        'name'     => 'floor_plan_layout.jpg',
        'type'     => 'image/jpeg',
        'tmp_name' => $jpgFile,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($jpgFile)
    ];
    assertCheck("Valid JPG floor plan is accepted", isValidFloorPlanFile($fileValidJpg) === true);

    $fileValidJpeg = [
        'name'     => 'floor_plan_second.jpeg',
        'type'     => 'image/jpeg',
        'tmp_name' => $jpegFile,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($jpegFile)
    ];
    assertCheck("Valid JPEG floor plan is accepted", isValidFloorPlanFile($fileValidJpeg) === true);

    $fileValidPdf = [
        'name'     => 'architectural_floor_plan.pdf',
        'type'     => 'application/pdf',
        'tmp_name' => $pdfFile,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($pdfFile)
    ];
    assertCheck("Valid PDF floor plan is accepted", isValidFloorPlanFile($fileValidPdf) === true);

    // -------------------------------------------------------------
    // GROUP 2: Invalid Extensions
    // -------------------------------------------------------------
    echo "\n--- Group 2: Invalid Extensions ---\n";

    $fileTxt = [
        'name'     => 'notes.txt',
        'type'     => 'text/plain',
        'tmp_name' => $txtFile,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($txtFile)
    ];
    assertCheck("Text file (.txt) is rejected", isValidFloorPlanFile($fileTxt) === false);

    $fileExe = [
        'name'     => 'installer.exe',
        'type'     => 'application/octet-stream',
        'tmp_name' => $exeFile,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($exeFile)
    ];
    assertCheck("Executable file (.exe) is rejected", isValidFloorPlanFile($fileExe) === false);

    $fileGif = [
        'name'     => 'animated.gif',
        'type'     => 'image/gif',
        'tmp_name' => $gifFile,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($gifFile)
    ];
    assertCheck("GIF file (.gif) is rejected for floor plans", isValidFloorPlanFile($fileGif) === false);

    // -------------------------------------------------------------
    // GROUP 3: Disguised or Mismatched Content
    // -------------------------------------------------------------
    echo "\n--- Group 3: Disguised / Spoofed Content ---\n";

    $fileFakePng = [
        'name'     => 'malicious.png',
        'type'     => 'image/png',
        'tmp_name' => $fakePngWithText,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($fakePngWithText)
    ];
    assertCheck("Text file disguised with .png extension is rejected", isValidFloorPlanFile($fileFakePng) === false);

    $fileFakePdf = [
        'name'     => 'malicious.pdf',
        'type'     => 'application/pdf',
        'tmp_name' => $fakePdfWithText,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($fakePdfWithText)
    ];
    assertCheck("Text file disguised with .pdf extension is rejected", isValidFloorPlanFile($fileFakePdf) === false);

    $fileVideoPng = [
        'name'     => 'video.png',
        'type'     => 'image/png',
        'tmp_name' => $fakePngWithVideo,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($fakePngWithVideo)
    ];
    assertCheck("MP4 video disguised as .png is rejected", isValidFloorPlanFile($fileVideoPng) === false);

    $fileVideoPdf = [
        'name'     => 'video.pdf',
        'type'     => 'application/pdf',
        'tmp_name' => $fakePdfWithVideo,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($fakePdfWithVideo)
    ];
    assertCheck("MP4 video disguised as .pdf is rejected", isValidFloorPlanFile($fileVideoPdf) === false);

    $filePngAsPdf = [
        'name'     => 'mismatched.pdf',
        'type'     => 'application/pdf',
        'tmp_name' => $pngRenamedPdf,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($pngRenamedPdf)
    ];
    assertCheck("Real PNG content disguised as .pdf extension is rejected", isValidFloorPlanFile($filePngAsPdf) === false);

    $filePdfAsPng = [
        'name'     => 'mismatched.png',
        'type'     => 'image/png',
        'tmp_name' => $pdfRenamedPng,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($pdfRenamedPng)
    ];
    assertCheck("Real PDF content disguised as .png extension is rejected", isValidFloorPlanFile($filePdfAsPng) === false);

    // -------------------------------------------------------------
    // GROUP 4: Oversized Upload
    // -------------------------------------------------------------
    echo "\n--- Group 4: Oversized Uploads ---\n";

    $fileOversized = [
        'name'     => 'oversized_plan.png',
        'type'     => 'image/png',
        'tmp_name' => $pngFile,
        'error'    => UPLOAD_ERR_OK,
        'size'     => MAX_FILE_SIZE + 1024
    ];
    assertCheck("Upload exceeding MAX_FILE_SIZE is rejected", isValidFloorPlanFile($fileOversized) === false);

    // -------------------------------------------------------------
    // GROUP 5: PHP Upload Errors
    // -------------------------------------------------------------
    echo "\n--- Group 5: PHP Upload Error States ---\n";

    $fileErrIni = [
        'name'     => 'plan.png',
        'type'     => 'image/png',
        'tmp_name' => $pngFile,
        'error'    => UPLOAD_ERR_INI_SIZE,
        'size'     => filesize($pngFile)
    ];
    assertCheck("UPLOAD_ERR_INI_SIZE is rejected", isValidFloorPlanFile($fileErrIni) === false);

    $fileErrPartial = [
        'name'     => 'plan.png',
        'type'     => 'image/png',
        'tmp_name' => $pngFile,
        'error'    => UPLOAD_ERR_PARTIAL,
        'size'     => filesize($pngFile)
    ];
    assertCheck("UPLOAD_ERR_PARTIAL is rejected", isValidFloorPlanFile($fileErrPartial) === false);

    $fileErrNoFile = [
        'name'     => '',
        'type'     => '',
        'tmp_name' => '',
        'error'    => UPLOAD_ERR_NO_FILE,
        'size'     => 0
    ];
    assertCheck("UPLOAD_ERR_NO_FILE is rejected", isValidFloorPlanFile($fileErrNoFile) === false);

    // -------------------------------------------------------------
    // GROUP 6: Missing, Non-Existent, or Empty Files
    // -------------------------------------------------------------
    echo "\n--- Group 6: Missing or Empty Data ---\n";

    $fileEmpty = [
        'name'     => 'empty.png',
        'type'     => 'image/png',
        'tmp_name' => $emptyFile,
        'error'    => UPLOAD_ERR_OK,
        'size'     => 0
    ];
    assertCheck("Zero-byte file (size 0) is rejected", isValidFloorPlanFile($fileEmpty) === false);

    $fileMissingTmp = [
        'name'     => 'missing.png',
        'type'     => 'image/png',
        'tmp_name' => $tempDir . DIRECTORY_SEPARATOR . 'non_existent_file.png',
        'error'    => UPLOAD_ERR_OK,
        'size'     => 1024
    ];
    assertCheck("Non-existent tmp_name path is rejected", isValidFloorPlanFile($fileMissingTmp) === false);

    $fileMissingName = [
        'name'     => '',
        'type'     => 'image/png',
        'tmp_name' => $pngFile,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($pngFile)
    ];
    assertCheck("Empty filename string is rejected", isValidFloorPlanFile($fileMissingName) === false);

} finally {
    // Clean up temporary fixture directory
    $fixtures = glob($tempDir . DIRECTORY_SEPARATOR . '*');
    if ($fixtures) {
        foreach ($fixtures as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }
    @rmdir($tempDir);
}

echo "\n========================================================================\n";
echo "  TEST SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
echo "========================================================================\n";

exit($failCount > 0 ? 1 : 0);
