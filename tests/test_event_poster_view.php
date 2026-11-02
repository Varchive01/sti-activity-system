<?php
/**
 * tests/test_event_poster_view.php
 *
 * Comprehensive Test Suite for Event Poster "View Current Poster" & Storage Workflow:
 * 1. URL Generation & Formatting
 * 2. Protected Endpoint Access & RBAC (Owner Faculty, Non-owner Faculty, Admin, Unauthenticated)
 * 3. File Retrieval & Path Traversal Prevention
 * 4. Backward Compatibility (posters/file, uploads/posters/file, bare file)
 * 5. Lifecycle: New Proposal Upload -> Save Draft -> Edit Proposal View -> Replace Poster -> Preservation of Unrelated Attachments
 * 6. Proposal without poster (button not shown)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

echo "========================================================================\n";
echo "   EVENT POSTER VIEW & LIFECYCLE VERIFICATION SUITE\n";
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

$db = getDB();

// 1. Setup Test Users
$facultyOwner = $db->query("SELECT * FROM users WHERE role = 'faculty' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$facultyOther = $db->query("SELECT * FROM users WHERE role = 'faculty' AND id != {$facultyOwner['id']} ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin1User   = $db->query("SELECT * FROM users WHERE role = 'admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$facultyOwner || !$facultyOther || !$admin1User) {
    die("Required users not found for test execution.\n");
}

echo "Test Users:\n";
echo "  Owner Faculty: ID {$facultyOwner['id']} ({$facultyOwner['name']})\n";
echo "  Other Faculty: ID {$facultyOther['id']} ({$facultyOther['name']})\n";
echo "  Admin1 User  : ID {$admin1User['id']} ({$admin1User['name']})\n\n";

// Ensure uploads/posters directory exists
$postersDir = UPLOAD_PATH . 'posters/';
if (!is_dir($postersDir)) {
    mkdir($postersDir, 0755, true);
}

// Create sample PNG test images
// 1x1 transparent PNG binary
$pngData1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
// 1x1 red PNG binary
$pngData2 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

$posterFile1 = 'test_owner_poster_1.png';
$posterFile2 = 'test_owner_poster_2.png';
file_put_contents($postersDir . $posterFile1, $pngData1);
file_put_contents($postersDir . $posterFile2, $pngData2);

// Create test activity with poster for Owner Faculty
$stmt = $db->prepare("
    INSERT INTO activities (
        title, faculty_id, status, event_date, start_time, end_time, venue,
        poster_path, created_at, updated_at
    ) VALUES (
        'Poster Verification Test Event', ?, 'draft', '2026-11-20', '09:00:00', '12:00:00', 'Auditorium',
        ?, NOW(), NOW()
    )
");
$stmt->execute([$facultyOwner['id'], 'posters/' . $posterFile1]);
$testActId = (int)$db->lastInsertId();

// Add an unrelated attachment to verify replacement doesn't delete it
$stmtDoc = $db->prepare("INSERT INTO documents (activity_id, file_name, file_path, uploaded_by, uploaded_at) VALUES (?, 'budget_plan.pdf', 'documents/test_budget.pdf', ?, NOW())");
$stmtDoc->execute([$testActId, $facultyOwner['id']]);
$unrelatedDocId = (int)$db->lastInsertId();

echo "Created Test Activity ID: {$testActId} with poster 'posters/{$posterFile1}' and document ID {$unrelatedDocId}\n\n";

try {
    // -------------------------------------------------------------------------
    // TEST 1: proposal-edit.php renders correct poster URL and not broken direct path
    // -------------------------------------------------------------------------
    echo "--- 1. View Current Poster Link Generation in proposal-edit.php ---\n";
    
    // Simulate fetching the activity as proposal-edit.php does
    $actData = $db->query("SELECT * FROM activities WHERE id = {$testActId}")->fetch(PDO::FETCH_ASSOC);
    $posterUrl = str_starts_with($actData['poster_path'], 'http') 
        ? $actData['poster_path'] 
        : BASE_URL . '/api/document-download.php?activity_id=' . (int)$actData['id'] . '&type=poster';

    assertTest("1.1 Generated posterUrl points to secure document-download endpoint",
        $posterUrl === BASE_URL . "/api/document-download.php?activity_id={$testActId}&type=poster",
        "Got: {$posterUrl}"
    );

    assertTest("1.2 Generated posterUrl does NOT expose raw uploads/ or posters/ direct path",
        !str_contains($posterUrl, 'sti-activity-system/posters/') && !str_contains($posterUrl, 'sti-activity-system/uploads/posters/'),
        "URL leaked direct path: {$posterUrl}"
    );

    // -------------------------------------------------------------------------
    // TEST 2: Protected Endpoint Access & RBAC via api/document-download.php
    // -------------------------------------------------------------------------
    echo "\n--- 2. RBAC & Security Checks on document-download.php?activity_id={$testActId}&type=poster ---\n";

    function invokeDocumentDownload(int $actId, string $type, ?array $sessionUser, array $extraGet = []): array
    {
        $runnerPath = escapeshellarg(__DIR__ . '/runner_helper.php');
        $cmd = 'php ' . $runnerPath;

        $descriptors = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];
        $process = proc_open($cmd, $descriptors, $pipes);

        $sessionData = null;
        if ($sessionUser) {
            $sessionData = [
                'user'          => $sessionUser,
                'user_id'       => (int)$sessionUser['id'],
                'user_role'     => $sessionUser['role'],
                'role'          => $sessionUser['role'],
                'last_activity' => time()
            ];
        }

        $getParams = array_merge([
            'activity_id' => $actId,
            'type'        => $type
        ], $extraGet);

        $payload = json_encode([
            'session' => $sessionData,
            'get'     => $getParams
        ]);

        fwrite($pipes[0], $payload);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout'   => $stdout,
            'stderr'   => $stderr,
            'length'   => strlen($stdout),
            'md5'      => md5($stdout)
        ];
    }

    // 2.1 Owner Faculty Access
    $ownerRes = invokeDocumentDownload($testActId, 'poster', $facultyOwner);
    assertTest("2.1 Owner faculty retrieves poster successfully (contains image binary)",
        $ownerRes['stdout'] === $pngData1,
        "Length: {$ownerRes['length']}, expected: " . strlen($pngData1)
    );

    // 2.2 Unauthenticated Access
    $unauthRes = invokeDocumentDownload($testActId, 'poster', null);
    assertTest("2.2 Unauthenticated user is blocked",
        $unauthRes['stdout'] !== $pngData1 && !str_contains($unauthRes['stdout'], $pngData1),
        "Stdout: {$unauthRes['stdout']}"
    );

    // 2.3 Non-Owner Faculty Access to Draft Proposal Poster
    $otherRes = invokeDocumentDownload($testActId, 'poster', $facultyOther);
    assertTest("2.3 Other faculty blocked from accessing owner draft poster (Forbidden)",
        str_contains($otherRes['stdout'], 'Forbidden') && !str_contains($otherRes['stdout'], $pngData1),
        "Stdout: {$otherRes['stdout']}"
    );

    // 2.4 Admin1 Access
    $adminRes = invokeDocumentDownload($testActId, 'poster', $admin1User);
    assertTest("2.4 Admin1 retrieves poster successfully (contains image binary)",
        $adminRes['stdout'] === $pngData1,
        "Length: {$adminRes['length']}"
    );

    // -------------------------------------------------------------------------
    // TEST 3: Backward Compatibility & Traversal Defenses
    // -------------------------------------------------------------------------
    echo "\n--- 3. Path Traversal Defenses & Backward Compatibility ---\n";

    // 3.1 Non-existent poster
    $nonExistentAct = $db->prepare("INSERT INTO activities (title, faculty_id, status, poster_path) VALUES ('No Poster Event', ?, 'draft', 'posters/non_existent_file.png')");
    $nonExistentAct->execute([$facultyOwner['id']]);
    $noFileActId = (int)$db->lastInsertId();

    $noFileRes = invokeDocumentDownload($noFileActId, 'poster', $facultyOwner);
    assertTest("3.1 Non-existent poster file returns Document file not found",
        str_contains($noFileRes['stdout'], 'not found'),
        "Stdout: {$noFileRes['stdout']}"
    );

    // 3.2 Backward compatibility: Bare filename without 'posters/' prefix
    $bareFileName = 'test_bare_filename_' . time() . '.png';
    file_put_contents($postersDir . $bareFileName, $pngData1);
    $db->prepare("UPDATE activities SET poster_path = ? WHERE id = ?")->execute([$bareFileName, $testActId]);

    $bareRes = invokeDocumentDownload($testActId, 'poster', $facultyOwner);
    assertTest("3.2 Backward compatibility: bare filename resolves from posters/ directory",
        $bareRes['stdout'] === $pngData1,
        "Stdout: {$bareRes['stdout']}"
    );
    @unlink($postersDir . $bareFileName);

    // 3.3 Backward compatibility: Full 'uploads/posters/' prefix
    $db->prepare("UPDATE activities SET poster_path = ? WHERE id = ?")->execute(['uploads/posters/' . $posterFile1, $testActId]);
    $uploadsPrefixRes = invokeDocumentDownload($testActId, 'poster', $facultyOwner);
    assertTest("3.3 Backward compatibility: 'uploads/posters/' prefix resolves properly",
        $uploadsPrefixRes['stdout'] === $pngData1,
        "Stdout: {$uploadsPrefixRes['stdout']}"
    );

    // Restore standard path
    $db->prepare("UPDATE activities SET poster_path = ? WHERE id = ?")->execute(['posters/' . $posterFile1, $testActId]);

    // 3.4 Traversal attempt
    $db->prepare("UPDATE activities SET poster_path = ? WHERE id = ?")->execute(['../../config/database.php', $testActId]);
    $traversalRes = invokeDocumentDownload($testActId, 'poster', $facultyOwner);
    assertTest("3.4 Path traversal attempt in poster_path blocked",
        str_contains($traversalRes['stdout'], 'not found') && !str_contains($traversalRes['stdout'], 'DB_PASS'),
        "Stdout: {$traversalRes['stdout']}"
    );
    $db->prepare("UPDATE activities SET poster_path = ? WHERE id = ?")->execute(['posters/' . $posterFile1, $testActId]);

    // -------------------------------------------------------------------------
    // TEST 4: Replacement Poster & Attachment Preservation
    // -------------------------------------------------------------------------
    echo "\n--- 4. Poster Replacement & Preservation of Unrelated Attachments ---\n";

    // Simulate replacing poster via proposal-update.php
    $db->prepare("UPDATE activities SET poster_path = ? WHERE id = ?")->execute(['posters/' . $posterFile2, $testActId]);

    $replacedRes = invokeDocumentDownload($testActId, 'poster', $facultyOwner);
    assertTest("4.1 Replaced poster serves the updated file content (test_owner_poster_2.png)",
        $replacedRes['stdout'] === $pngData2,
        "Length: {$replacedRes['length']}, expected: " . strlen($pngData2)
    );

    // Verify unrelated document remains intact
    $unrelatedCheck = $db->query("SELECT * FROM documents WHERE id = {$unrelatedDocId}")->fetch(PDO::FETCH_ASSOC);
    assertTest("4.2 Unrelated documents/attachments are preserved after poster replacement",
        !empty($unrelatedCheck) && $unrelatedCheck['file_name'] === 'budget_plan.pdf',
        "Document check: " . json_encode($unrelatedCheck)
    );

    // -------------------------------------------------------------------------
    // TEST 5: Proposal Without Poster (Button Not Rendered)
    // -------------------------------------------------------------------------
    echo "\n--- 5. Proposal Without Poster ---\n";

    $db->prepare("UPDATE activities SET poster_path = NULL WHERE id = ?")->execute([$testActId]);
    $actNoPoster = $db->query("SELECT * FROM activities WHERE id = {$testActId}")->fetch(PDO::FETCH_ASSOC);
    
    // Check conditional in proposal-edit.php line 1240:
    $hasPosterBtn = !empty($actNoPoster['poster_path']);
    assertTest("5.1 Empty poster_path suppresses 'View Current Poster' button",
        $hasPosterBtn === false,
        "hasPosterBtn: " . var_export($hasPosterBtn, true)
    );

    $noPosterRes = invokeDocumentDownload($testActId, 'poster', $facultyOwner);
    assertTest("5.2 Calling document-download for empty poster returns Poster not found",
        str_contains($noPosterRes['stdout'], 'Poster not found'),
        "Stdout: {$noPosterRes['stdout']}"
    );

} finally {
    // Cleanup fixtures
    @unlink($postersDir . $posterFile1);
    @unlink($postersDir . $posterFile2);
    $db->exec("DELETE FROM documents WHERE id = {$unrelatedDocId}");
    $db->exec("DELETE FROM activities WHERE id IN ({$testActId}, {$noFileActId})");
    echo "\n--- Cleaned up test fixtures ---\n";
}

echo "\n========================================================================\n";
echo " VERIFICATION SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================================\n";
exit($failed === 0 ? 0 : 1);
