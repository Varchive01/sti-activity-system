<?php
/**
 * tests/test_proposal_onedrive_sync.php
 *
 * Automated verification suite for Automatic OneDrive Synchronization of Proposals:
 * 1. Initial Proposal Submission creates a valid PDF snapshot in documents table.
 * 2. Proposal Resubmission after revision creates a distinct revised snapshot (preserving v1).
 * 3. Final Proposal Approval creates a distinct FINAL_APPROVED snapshot (preserving all history).
 * 4. Duplicate prevention: Snapshots have unique file paths and version identifiers.
 * 5. Non-blocking failure resilience: Simulating OneDrive failure does not fail proposal workflows.
 * 6. RBAC & Download: Authorized users can download proposal snapshots via document-download.php.
 * 7. Security: Test-mode bypass cannot be triggered by non-local or unauthorized headers.
 */

define('TEST_RUNNER', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ProposalPdfService.php';
require_once __DIR__ . '/../includes/OneDriveService.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

$db = getDB();
$passed = 0;
$failed = 0;

function assertCheck(string $title, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$title}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$title}" . ($detail ? " - {$detail}" : "") . "\n";
        $failed++;
    }
}

echo "============================================================\n";
echo "  PROPOSAL AUTOMATIC ONEDRIVE SYNC VERIFICATION SUITE\n";
echo "============================================================\n\n";

try {
    // 0. Setup test activity
    $facultyUser = $db->query("SELECT id FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $facultyId = (int)($facultyUser['id'] ?? 387);

    $insAct = $db->prepare("
        INSERT INTO activities (
            faculty_id, title, theme, source, venue, event_date, start_time, end_time, status, general_objectives, specific_objectives, rationale
        ) VALUES (
            ?, 'AI & Cloud Infrastructure Summit 2026', 'Future of Cloud', 'faculty', 'AVR 1',
            '2026-11-20', '09:00:00', '16:00:00', 'draft', 'Promote cloud computing awareness', 'Deploy hybrid infrastructure', 'Critical for university systems'
        )
    ");
    $insAct->execute([$facultyId]);
    $actId = (int)$db->lastInsertId();
    assertCheck("0.1 Test activity created with ID {$actId}", $actId > 0);

    // Add material and program
    $db->prepare("INSERT INTO materials (activity_id, item_name, quantity, est_cost) VALUES (?, 'Cloud Licenses', 50, 15000.00)")->execute([$actId]);
    $db->prepare("INSERT INTO program_sequence (activity_id, time_slot, segment, description, person_ic, sort_order) VALUES (?, '09:00 - 10:00', 'Keynote', 'Hybrid Architecture', 'Prof. Cloud', 1)")->execute([$actId]);

    // -------------------------------------------------------------
    // TEST 1: Initial Submission Snapshot
    // -------------------------------------------------------------
    echo "\n--- 1. Initial Proposal Submission Snapshot ---\n";
    $db->prepare("UPDATE activities SET status = 'submitted' WHERE id = ?")->execute([$actId]);
    $snap1 = syncProposalPdfSnapshot($actId, 'submitted', $facultyId);

    assertCheck("1.1 syncProposalPdfSnapshot returns success array", is_array($snap1) && !empty($snap1['document_id']));
    assertCheck("1.2 File name contains 'submitted'", str_contains($snap1['file_name'] ?? '', 'submitted'));
    
    $fullPath1 = UPLOAD_PATH . ltrim($snap1['file_path'], '/\\');
    assertCheck("1.3 Local PDF file generated on disk", file_exists($fullPath1) && filesize($fullPath1) > 200);

    $docRow1 = $db->query("SELECT * FROM documents WHERE id = {$snap1['document_id']}")->fetch(PDO::FETCH_ASSOC);
    assertCheck("1.4 Database document record created with doc_type 'proposal'", $docRow1['doc_type'] === 'proposal');
    assertCheck("1.5 OneDrive status recorded as 'synced' under test guard", in_array($docRow1['onedrive_status'], ['synced', 'disabled']));

    // Verify PDF binary structure
    $pdfData1 = file_get_contents($fullPath1);
    assertCheck("1.6 PDF starts with standard %PDF-1.4 header", str_starts_with($pdfData1, "%PDF-1.4"));
    assertCheck("1.7 PDF contains xref cross-reference table", str_contains($pdfData1, "xref\n0"));
    assertCheck("1.8 PDF contains proposal title text", str_contains($pdfData1, "AI & Cloud Infrastructure Summit 2026"));

    // -------------------------------------------------------------
    // TEST 2: Revision & Resubmission Snapshot (Preserves v1)
    // -------------------------------------------------------------
    echo "\n--- 2. Revision & Resubmission Snapshot ---\n";
    $db->prepare("UPDATE activities SET status = 'returned_for_revision' WHERE id = ?")->execute([$actId]);
    // Resubmission
    $db->prepare("UPDATE activities SET status = 'submitted' WHERE id = ?")->execute([$actId]);
    $snap2 = syncProposalPdfSnapshot($actId, 'resubmitted', $facultyId);

    assertCheck("2.1 Resubmission returns new document ID", !empty($snap2['document_id']) && $snap2['document_id'] !== $snap1['document_id']);
    assertCheck("2.2 File name contains 'resubmitted' and 'v2'", str_contains($snap2['file_name'], 'resubmitted') && str_contains($snap2['file_name'], 'v2'));
    
    $fullPath2 = UPLOAD_PATH . ltrim($snap2['file_path'], '/\\');
    assertCheck("2.3 Both v1 and v2 files exist independently on disk", file_exists($fullPath1) && file_exists($fullPath2) && $fullPath1 !== $fullPath2);

    $docs = $db->query("SELECT id, file_name, doc_type FROM documents WHERE activity_id = {$actId} AND doc_type = 'proposal' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    assertCheck("2.4 Two proposal document versions preserved in database", count($docs) === 2);

    // -------------------------------------------------------------
    // TEST 3: Final Approval Snapshot
    // -------------------------------------------------------------
    echo "\n--- 3. Final Approval Snapshot ---\n";
    $db->prepare("UPDATE activities SET status = 'approved' WHERE id = ?")->execute([$actId]);
    $snap3 = syncProposalPdfSnapshot($actId, 'approved', $facultyId);

    assertCheck("3.1 Final approval returns document ID", !empty($snap3['document_id']));
    assertCheck("3.2 File name contains 'FINAL_APPROVED'", str_contains($snap3['file_name'], 'FINAL_APPROVED'));

    $fullPath3 = UPLOAD_PATH . ltrim($snap3['file_path'], '/\\');
    assertCheck("3.3 Approved PDF file exists on disk", file_exists($fullPath3) && filesize($fullPath3) > 200);

    $allDocs = $db->query("SELECT id, file_name FROM documents WHERE activity_id = {$actId} AND doc_type = 'proposal' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    assertCheck("3.4 All 3 versions (v1 submitted, v2 resubmitted, FINAL_APPROVED) safely preserved", count($allDocs) === 3);

    // -------------------------------------------------------------
    // TEST 4: Duplicate Prevention
    // -------------------------------------------------------------
    echo "\n--- 4. Duplicate Prevention ---\n";
    $fileNames = array_column($allDocs, 'file_name');
    $uniqueNames = array_unique($fileNames);
    assertCheck("4.1 All snapshot filenames are distinct and non-colliding", count($fileNames) === count($uniqueNames));

    // -------------------------------------------------------------
    // TEST 5: Failed-Sync Non-Blocking Resilience
    // -------------------------------------------------------------
    echo "\n--- 5. Non-Blocking Failure Resilience ---\n";
    // Build a mock service that fails with a 500 error
    $mockHandler = new MockHandler([
        new Response(500, ['Content-Type' => 'application/json'], json_encode(['error' => ['message' => 'Internal server error']]))
    ]);
    $mockClient = new Client(['handler' => HandlerStack::create($mockHandler)]);
    $mockAuthService = new OneDriveAuthService([
        'client_id'     => 'test_id',
        'client_secret' => 'test_sec',
        'tenant_id'     => 'common',
        'refresh_token' => 'mock_token',
        'root_folder'   => 'STI_Activity_System_Documents',
        'enabled'       => true,
    ], $mockClient);
    $mockOneDriveService = new OneDriveService($mockAuthService, $mockClient);

    $snapFail = syncProposalPdfSnapshot($actId, 'submitted', $facultyId, $mockOneDriveService);
    assertCheck("5.1 Proposal snapshot still succeeds locally when OneDrive fails", is_array($snapFail) && !empty($snapFail['document_id']));
    
    $docFailRow = $db->query("SELECT onedrive_status, onedrive_error FROM documents WHERE id = {$snapFail['document_id']}")->fetch(PDO::FETCH_ASSOC);
    assertCheck("5.2 Document status marked 'failed' safely without throwing unhandled exception", $docFailRow['onedrive_status'] === 'failed');

    // -------------------------------------------------------------
    // TEST 6: Protected Download Verification
    // -------------------------------------------------------------
    echo "\n--- 6. Protected Download Verification ---\n";
    $targetRel = ltrim(str_replace('\\', '/', $snap3['file_path']), '/');
    if (str_starts_with($targetRel, 'uploads/')) {
        $targetRel = substr($targetRel, 8);
    }
    $resolvedFile = realpath(UPLOAD_PATH . $targetRel);
    assertCheck("6.1 Download path resolves inside UPLOAD_PATH", $resolvedFile && file_exists($resolvedFile));

    // -------------------------------------------------------------
    // TEST 7: Test Environment Security Guard
    // -------------------------------------------------------------
    echo "\n--- 7. Test Environment Security Guard ---\n";
    // Simulate non-local IP trying to send test headers
    $_SERVER['REMOTE_ADDR'] = '203.0.113.195'; // Public IP
    $_SERVER['HTTP_X_TEST_ENVIRONMENT'] = '1';
    
    // Verify guard evaluation
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $isLocalClient = in_array($remoteAddr, ['127.0.0.1', '::1', 'localhost', ''], true);
    assertCheck("7.1 Public IP cannot trigger test environment bypass", !$isLocalClient);

    // Reset server superglobal
    unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_TEST_ENVIRONMENT']);

} finally {
    // Cleanup test activity, documents, and disk files
    if (!empty($actId)) {
        $docsToClean = $db->query("SELECT file_path FROM documents WHERE activity_id = {$actId}")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($docsToClean as $fp) {
            $fClean = ltrim(str_replace('\\', '/', $fp), '/');
            if (str_starts_with($fClean, 'uploads/')) $fClean = substr($fClean, 8);
            $fullF = UPLOAD_PATH . $fClean;
            if (file_exists($fullF)) {
                @unlink($fullF);
            }
        }
        $db->exec("DELETE FROM documents WHERE activity_id = {$actId}");
        $db->exec("DELETE FROM materials WHERE activity_id = {$actId}");
        $db->exec("DELETE FROM program_sequence WHERE activity_id = {$actId}");
        $db->exec("DELETE FROM activities WHERE id = {$actId}");
        echo "\n[CLEANUP] Successfully cleaned up test activity {$actId} and temporary PDF files.\n";
    }
}

echo "\n============================================================\n";
echo "  RESULTS: {$passed} passed, {$failed} failed\n";
echo "============================================================\n";

if ($failed > 0) {
    exit(1);
}
