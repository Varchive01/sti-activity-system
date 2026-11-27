<?php
/**
 * download-program-head-uat-docx.php
 *
 * Direct download handler for the official STI-ARMES Program Head User Acceptance Testing (UAT) Questionnaire in Word format (.docx).
 */

$filePath = __DIR__ . '/PROGRAM_HEAD_UAT_QUESTIONNAIRE.docx';

if (!file_exists($filePath)) {
    http_response_code(404);
    die('The requested UAT document was not found on the server.');
}

$filename = 'STI_ARMES_Program_Head_UAT_Questionnaire.docx';

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
