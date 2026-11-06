<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

echo "========================================================" . PHP_EOL;
echo " POSTER VIEWABILITY & PERMISSION CHECK ACROSS STATUSES" . PHP_EOL;
echo "========================================================" . PHP_EOL;

// Ensure upload test directory and dummy poster exist
$uploadDir = __DIR__ . '/../uploads/posters';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}
$dummyPosterName = 'test_poster_viewability.png';
$dummyPosterPath = $uploadDir . '/' . $dummyPosterName;
if (!file_exists($dummyPosterPath)) {
    // 1x1 transparent PNG
    file_put_contents($dummyPosterPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
}

$dbPosterRelative = 'uploads/posters/' . $dummyPosterName;

// Fetch users for each role
$facultyUser = $db->query("SELECT id, name, role, department FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$headUser    = $db->query("SELECT id, name, role, department FROM users WHERE role = 'program_head' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin1User  = $db->query("SELECT id, name, role, department FROM users WHERE role = 'admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin2User  = $db->query("SELECT id, name, role, department FROM users WHERE role = 'admin2' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$deanUser    = $db->query("SELECT id, name, role, department FROM users WHERE role = 'dean' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

echo "Test Users:" . PHP_EOL;
echo " - Faculty: ID {$facultyUser['id']} ({$facultyUser['name']}, Dept: {$facultyUser['department']})" . PHP_EOL;
echo " - Program Head: ID {$headUser['id']} ({$headUser['name']}, Dept: {$headUser['department']})" . PHP_EOL;
echo " - Admin 1: ID {$admin1User['id']} ({$admin1User['name']})" . PHP_EOL;
echo " - Admin 2: ID {$admin2User['id']} ({$admin2User['name']})" . PHP_EOL;
echo " - Dean: ID {$deanUser['id']} ({$deanUser['name']})" . PHP_EOL . PHP_EOL;

$statusesToTest = [
    'draft',
    'submitted',
    'under_review',
    'returned_for_revision',
    'resubmitted',
    'endorsed',
    'pending_final_approval',
    'pending_dean_approval',
    'approved',
    'rejected',
    'completed'
];

$db->beginTransaction();

try {
    foreach ($statusesToTest as $status) {
        echo "--- Testing Status: [{$status}] ---" . PHP_EOL;

        // 1. Create temporary activity with poster_path set
        $stmt = $db->prepare("
            INSERT INTO activities (
                title, faculty_id, status, source, poster_path, event_date, created_at, updated_at
            ) VALUES (?, ?, ?, 'faculty', ?, '2027-02-15', NOW(), NOW())
        ");
        $stmt->execute([
            "Test Proposal - {$status}",
            $facultyUser['id'],
            $status,
            $dbPosterRelative
        ]);
        $testActId = (int)$db->lastInsertId();

        // Test permission for creator (faculty)
        $canFacultyView = canUserAccessActivity($testActId, 'view', $facultyUser['id'], 'faculty', $facultyUser['department']);
        
        // Test permission for Program Head (matching department)
        $canHeadView = canUserAccessActivity($testActId, 'view', $headUser['id'], 'program_head', $facultyUser['department']);

        // Test permission for Admin 1
        $canAdmin1View = canUserAccessActivity($testActId, 'view', $admin1User['id'], 'admin1');

        // Test permission for Admin 2
        $canAdmin2View = canUserAccessActivity($testActId, 'view', $admin2User['id'], 'admin2');

        // Test permission for Dean
        $canDeanView = canUserAccessActivity($testActId, 'view', $deanUser['id'], 'dean');

        // Test poster download authorization logic from api/document-download.php
        $checkDownloadAuth = function($uId, $uRole, $uDept) use ($testActId, $status) {
            $isAuthorized = canUserAccessActivity($testActId, 'view', $uId, $uRole, $uDept);
            if (!$isAuthorized && in_array($uRole, ['admin1', 'admin2', 'dean'], true)) {
                $isAuthorized = true;
            }
            return $isAuthorized;
        };

        $posterAuthFaculty = $checkDownloadAuth($facultyUser['id'], 'faculty', $facultyUser['department']);
        $posterAuthHead    = $checkDownloadAuth($headUser['id'], 'program_head', $facultyUser['department']);
        $posterAuthAdmin1  = $checkDownloadAuth($admin1User['id'], 'admin1', null);
        $posterAuthAdmin2  = $checkDownloadAuth($admin2User['id'], 'admin2', null);
        $posterAuthDean    = $checkDownloadAuth($deanUser['id'], 'dean', null);

        echo "  Proposal Access (canUserAccessActivity):" . PHP_EOL;
        echo "   - Faculty Creator: " . ($canFacultyView ? "ALLOWED" : "DENIED") . PHP_EOL;
        echo "   - Program Head:    " . ($canHeadView ? "ALLOWED" : "DENIED") . PHP_EOL;
        echo "   - Admin 1:         " . ($canAdmin1View ? "ALLOWED" : "DENIED") . PHP_EOL;
        echo "   - Admin 2:         " . ($canAdmin2View ? "ALLOWED" : "DENIED") . PHP_EOL;
        echo "   - Dean:            " . ($canDeanView ? "ALLOWED" : "DENIED") . PHP_EOL;

        echo "  Poster API Auth (document-download.php):" . PHP_EOL;
        echo "   - Faculty Creator: " . ($posterAuthFaculty ? "OK (200)" : "FORBIDDEN (403)") . PHP_EOL;
        echo "   - Program Head:    " . ($posterAuthHead ? "OK (200)" : "FORBIDDEN (403)") . PHP_EOL;
        echo "   - Admin 1:         " . ($posterAuthAdmin1 ? "OK (200)" : "FORBIDDEN (403)") . PHP_EOL;
        echo "   - Admin 2:         " . ($posterAuthAdmin2 ? "OK (200)" : "FORBIDDEN (403)") . PHP_EOL;
        echo "   - Dean:            " . ($posterAuthDean ? "OK (200)" : "FORBIDDEN (403)") . PHP_EOL;
    }

    $db->rollBack();
    echo PHP_EOL . "Test completed successfully (all changes rolled back cleanly)." . PHP_EOL;
} catch (Exception $e) {
    $db->rollBack();
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
}

