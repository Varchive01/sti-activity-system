<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

// 1. Authenticate user
startSession();
$user = currentUser();

if (empty($user['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized: Please log in.']);
    exit;
}

$db = getDB();
ensureActivitiesArchiveSchema($db);

$allowedRoles = ['faculty', 'program_head', 'admin1', 'admin2', 'dean'];
$userRole = $user['role'] ?? '';
if (!in_array($userRole, $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden: Your role does not have permission to archive activities.']);
    exit;
}

// 2. Parse request data
$rawInput = file_get_contents('php://input');
$json = json_decode($rawInput, true) ?: [];
$params = !empty($json) ? $json : array_merge($_GET, $_POST);

$action = strtolower(trim((string)($params['action'] ?? 'preview')));
$scope  = strtolower(trim((string)($params['scope'] ?? 'month'))); // 'month' or 'all'
$singleId = (int)($params['id'] ?? 0);

$rawMonthYear = trim((string)($params['month_year'] ?? ''));
$month = isset($params['month']) && is_numeric($params['month']) ? (int)$params['month'] : 0;
$year  = isset($params['year']) && is_numeric($params['year']) ? (int)$params['year'] : 0;

if ($rawMonthYear !== '' && preg_match('/^(\d{4})-(\d{1,2})$/', $rawMonthYear, $matches)) {
    $year  = (int)$matches[1];
    $month = (int)$matches[2];
}

// 3. Build role-based scoping condition
$roleWhere = "";
$roleParams = [];

if ($userRole === 'faculty') {
    $roleWhere = " AND (a.faculty_id = ? OR EXISTS (SELECT 1 FROM proposal_collaborators pc WHERE pc.activity_id = a.id AND pc.user_id = ? AND pc.status = 'active'))";
    $roleParams[] = (int)$user['id'];
    $roleParams[] = (int)$user['id'];
} elseif ($userRole === 'program_head') {
    $headDept = trim((string)($user['dept'] ?? ''));
    if (!empty($headDept)) {
        $normDept = normalizeDepartmentName($headDept);
        $roleWhere = " AND (a.faculty_id = ? OR u.department = ? OR u.department LIKE ?)";
        $roleParams[] = (int)$user['id'];
        $roleParams[] = $headDept;
        $roleParams[] = '%' . $normDept . '%';
    } else {
        $roleWhere = " AND a.faculty_id = ?";
        $roleParams[] = (int)$user['id'];
    }
} else {
    // admin1, admin2, dean have institution-wide scope
    $roleWhere = "";
}

// ── ACTION: UNARCHIVE ──────────────────────────────────────────
if ($action === 'unarchive') {
    if ($singleId > 0) {
        $checkStmt = $db->prepare("
            SELECT a.id, a.title, a.is_archived 
            FROM activities a 
            LEFT JOIN users u ON a.faculty_id = u.id 
            WHERE a.id = ? $roleWhere 
            LIMIT 1
        ");
        $checkStmt->execute(array_merge([$singleId], $roleParams));
        $act = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$act) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Activity not found or unauthorized.']);
            exit;
        }

        $db->prepare("UPDATE activities SET is_archived = 0, archived_at = NULL, archived_by = NULL WHERE id = ?")->execute([$singleId]);

        echo json_encode([
            'success' => true,
            'message' => 'Activity "' . ($act['title'] ?: 'Activity') . '" unarchived successfully.',
            'unarchived_id' => $singleId
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing activity ID to unarchive.']);
    exit;
}

// ── ACTION: PREVIEW (count and list target completed activities) ─
if ($action === 'preview') {
    $dateFilter = "";
    $dateParams = [];

    if ($scope === 'month') {
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid month or year selected.']);
            exit;
        }
        $dateFilter = " AND (
            (a.event_date IS NOT NULL AND MONTH(a.event_date) = ? AND YEAR(a.event_date) = ?)
            OR (a.event_date IS NULL AND MONTH(a.updated_at) = ? AND YEAR(a.updated_at) = ?)
        )";
        $dateParams = [$month, $year, $month, $year];
    }

    $sql = "
        SELECT a.id, a.title, a.event_date, a.status, a.updated_at, u.name as faculty_name, u.department
        FROM activities a
        LEFT JOIN users u ON a.faculty_id = u.id
        WHERE a.status = 'completed'
          AND (a.is_archived IS NULL OR a.is_archived = 0)
          $roleWhere
          $dateFilter
        ORDER BY a.event_date DESC, a.id DESC
    ";

    $execParams = array_merge($roleParams, $dateParams);
    $stmt = $db->prepare($sql);
    $stmt->execute($execParams);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $monthName = ($month >= 1 && $month <= 12) ? date('F', mktime(0, 0, 0, $month, 1)) : '';

    echo json_encode([
        'success' => true,
        'count' => count($activities),
        'scope' => $scope,
        'month' => $month,
        'month_name' => $monthName,
        'year' => $year,
        'activities' => array_map(function($a) {
            return [
                'id' => (int)$a['id'],
                'title' => $a['title'],
                'date' => $a['event_date'] ? date('M j, Y', strtotime($a['event_date'])) : date('M j, Y', strtotime($a['updated_at'])),
                'faculty' => $a['faculty_name'] ?? 'Faculty'
            ];
        }, array_slice($activities, 0, 10)) // preview top 10
    ]);
    exit;
}

// ── ACTION: ARCHIVE (Universal / Bulk Archive completed activities) ──
if ($action === 'archive') {
    // If archiving a single activity directly
    if ($singleId > 0) {
        $checkStmt = $db->prepare("
            SELECT a.id, a.title, a.status, a.is_archived 
            FROM activities a 
            LEFT JOIN users u ON a.faculty_id = u.id 
            WHERE a.id = ? $roleWhere 
            LIMIT 1
        ");
        $checkStmt->execute(array_merge([$singleId], $roleParams));
        $act = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$act) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Activity not found or unauthorized.']);
            exit;
        }

        if ($act['status'] !== 'completed') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Only completed activities can be archived.']);
            exit;
        }

        $db->prepare("UPDATE activities SET is_archived = 1, archived_at = NOW(), archived_by = ? WHERE id = ?")
           ->execute([(int)$user['id'], $singleId]);

        echo json_encode([
            'success' => true,
            'message' => 'Activity "' . ($act['title'] ?: 'Activity') . '" archived successfully.',
            'archived_count' => 1
        ]);
        exit;
    }

    // Universal / Bulk Archive
    $dateFilter = "";
    $dateParams = [];

    if ($scope === 'month') {
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please select a valid month and year.']);
            exit;
        }
        $dateFilter = " AND (
            (a.event_date IS NOT NULL AND MONTH(a.event_date) = ? AND YEAR(a.event_date) = ?)
            OR (a.event_date IS NULL AND MONTH(a.updated_at) = ? AND YEAR(a.updated_at) = ?)
        )";
        $dateParams = [$month, $year, $month, $year];
    }

    $selectIdsSql = "
        SELECT a.id 
        FROM activities a
        LEFT JOIN users u ON a.faculty_id = u.id
        WHERE a.status = 'completed'
          AND (a.is_archived IS NULL OR a.is_archived = 0)
          $roleWhere
          $dateFilter
    ";

    $execParams = array_merge($roleParams, $dateParams);
    $stmt = $db->prepare($selectIdsSql);
    $stmt->execute($execParams);
    $idsToArchive = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($idsToArchive)) {
        $monthName = ($month >= 1 && $month <= 12) ? date('F', mktime(0, 0, 0, $month, 1)) : '';
        $label = ($scope === 'month') ? "in {$monthName} {$year}" : "ready to archive";
        echo json_encode([
            'success' => true,
            'archived_count' => 0,
            'message' => "No unarchived completed activities found {$label}."
        ]);
        exit;
    }

    $inPlaceholders = implode(',', array_fill(0, count($idsToArchive), '?'));
    $updateSql = "UPDATE activities SET is_archived = 1, archived_at = NOW(), archived_by = ? WHERE id IN ($inPlaceholders)";
    $updateStmt = $db->prepare($updateSql);
    $updateStmt->execute(array_merge([(int)$user['id']], $idsToArchive));

    $monthName = ($month >= 1 && $month <= 12) ? date('F', mktime(0, 0, 0, $month, 1)) : '';
    $scopeDesc = ($scope === 'month') ? "completed in {$monthName} {$year}" : "all completed";

    echo json_encode([
        'success' => true,
        'archived_count' => count($idsToArchive),
        'message' => 'Successfully archived ' . count($idsToArchive) . ' ' . $scopeDesc . ' activities.',
        'archived_ids' => array_map('intval', $idsToArchive)
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action requested.']);
exit;
