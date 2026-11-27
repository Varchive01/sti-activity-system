<?php
/**
 * download-implementation-sustainability-docx.php
 *
 * Direct download handler for the STI-ARMES Implementation Plan & 3-Year Sustainability Plan in Word (.docx) format.
 */

$filePath = __DIR__ . '/STI_ARMES_Implementation_and_Sustainability_Plan.docx';

if (!file_exists($filePath)) {
    http_response_code(404);
    die('The requested Word document was not found on the server.');
}

$filename = 'STI_ARMES_Implementation_and_Sustainability_Plan.docx';

header('Content-Description: File Transfer');
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Length: ' . filesize($filePath));

flush();
readfile($filePath);
exit;
