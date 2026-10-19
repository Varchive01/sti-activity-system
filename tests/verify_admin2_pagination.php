<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = getDB();
$stmt = $db->query("SELECT * FROM users WHERE role = 'admin2' LIMIT 1");
$admin2User = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin2User) {
    die("No admin2 user found for session testing\n");
}

$_SESSION['user_id'] = $admin2User['id'];
$_SESSION['user_role'] = 'admin2';
$_SESSION['role'] = 'admin2';
$_SESSION['email'] = $admin2User['email'];
$_SESSION['name'] = $admin2User['name'];
$_SESSION['must_change_password'] = 0;

echo "=== VERIFYING ADMIN 2 ACTIVITIES PAGINATION ===\n";

function renderAdmin2Activities(array $getParams = []): string {
    $_GET = $getParams;
    ob_start();
    include __DIR__ . '/../admin2/activities.php';
    return ob_get_clean();
}

$allPassed = true;

// 1. Page 1 loads normally
$htmlPage1 = renderAdmin2Activities([]);
if (strpos($htmlPage1, 'All Activities') !== false && (strpos($htmlPage1, '<table>') !== false || strpos($htmlPage1, '<table class="activity-table">') !== false)) {
    echo "[PASS] 1. Page 1 loads normally.\n";
} else {
    echo "[FAIL] 1. Page 1 did not load normally.\n";
    $allPassed = false;
}

// 2. Pagination appears when enough records exist
if (strpos($htmlPage1, '<div class="pagination">') !== false) {
    echo "[PASS] 2. Pagination appears when enough records exist.\n";
    // Check page 1 is active
    if (strpos($htmlPage1, '<span class="active">1</span>') !== false || strpos($htmlPage1, 'class="active">1</a>') !== false) {
        echo "[PASS]    Page 1 is marked as active.\n";
    } else {
        echo "[FAIL]    Page 1 is not marked active in pagination.\n";
        $allPassed = false;
    }
    // Check Next link is present
    if (strpos($htmlPage1, 'page=2') !== false && strpos($htmlPage1, 'Next »') !== false) {
        echo "[PASS]    Next » link to page 2 is present on Page 1.\n";
    } else {
        echo "[FAIL]    Next » link missing on Page 1.\n";
        $allPassed = false;
    }
    // Check Prev link is NOT present on Page 1
    if (strpos($htmlPage1, '« Prev') === false) {
        echo "[PASS]    « Prev is correctly omitted on Page 1.\n";
    } else {
        echo "[FAIL]    « Prev should not appear on Page 1.\n";
        $allPassed = false;
    }
} else {
    echo "[FAIL] 2. Pagination did not appear even though records exist.\n";
    $allPassed = false;
}

// 3. Page 2 loads and clicking another page preserves active filters/search
$htmlFilteredPage2 = renderAdmin2Activities(['q' => 'Test', 'status' => 'submitted', 'page' => '2']);
if (strpos($htmlFilteredPage2, '<div class="pagination">') !== false) {
    echo "[PASS] 3a. Page 2 with filters rendered pagination.\n";
    // Check active page is 2
    if (strpos($htmlFilteredPage2, '<span class="active">2</span>') !== false || strpos($htmlFilteredPage2, 'class="active">2</a>') !== false) {
        echo "[PASS]    Page 2 is marked as active.\n";
    } else {
        echo "[FAIL]    Page 2 is not marked active.\n";
        $allPassed = false;
    }
    // Check Prev link is present and points to page 1 with preserved q & status
    if (strpos($htmlFilteredPage2, 'page=1&amp;q=Test&amp;status=submitted') !== false || strpos($htmlFilteredPage2, 'page=1&q=Test&status=submitted') !== false) {
        echo "[PASS]    « Prev preserves q=Test and status=submitted.\n";
    } else {
        echo "[FAIL]    « Prev did not preserve search/filter parameters.\n";
        $allPassed = false;
    }
} else {
    // If not enough filtered records for page 2, test with just search or status
    $htmlFiltered = renderAdmin2Activities(['q' => 'a', 'page' => '2']);
    if (strpos($htmlFiltered, 'page=1&amp;q=a') !== false || strpos($htmlFiltered, 'page=1&q=a') !== false) {
        echo "[PASS] 3. Filter parameters preserved across pagination links.\n";
    } else {
        echo "[FAIL] 3. Filter parameters not preserved.\n";
        $allPassed = false;
    }
}

// 4. Review/View buttons remain correctly gated
if (strpos($htmlPage1, 'admin2/view-activity.php?id=') !== false) {
    echo "[PASS] 4a. View buttons remain present for activities.\n";
} else {
    echo "[FAIL] 4a. View buttons missing.\n";
    $allPassed = false;
}

// Check review button gating condition in activities.php
$activitiesFile = file_get_contents(__DIR__ . '/../admin2/activities.php');
if (strpos($activitiesFile, '$canAdmin2Review =') !== false &&
    strpos($activitiesFile, '($a[\'source\'] === \'faculty\' && in_array($a[\'status\'], [\'submitted\', \'resubmitted\'], true))') !== false &&
    strpos($activitiesFile, '($a[\'source\'] === \'student_org\' && $a[\'status\'] === \'endorsed\')') !== false) {
    echo "[PASS] 4b. Review buttons remain strictly gated to authoritative Admin 2 stages.\n";
} else {
    echo "[FAIL] 4b. Review button gating was altered!\n";
    $allPassed = false;
}

// 5. Empty results still display correctly
$htmlEmpty = renderAdmin2Activities(['q' => 'NONEXISTENT_ACTIVITY_QUERY_XYZ_123456789']);
if (strpos($htmlEmpty, 'No activities found.') !== false) {
    echo "[PASS] 5a. Empty results show 'No activities found.'.\n";
} else {
    echo "[FAIL] 5a. Empty results message missing.\n";
    $allPassed = false;
}
if (strpos($htmlEmpty, '<div class="pagination">') === false) {
    echo "[PASS] 5b. Pagination is hidden when zero results are found.\n";
} else {
    echo "[FAIL] 5b. Pagination should not appear for zero results.\n";
    $allPassed = false;
}

echo "\nOVERALL STATUS: " . ($allPassed ? "ALL PASSED" : "FAILURES DETECTED") . "\n";
