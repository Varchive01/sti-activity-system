<?php
date_default_timezone_set('Asia/Manila');
// ============================================================
// STI Activity System – Database Configuration
// ============================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'sti_activity_system');

define('BASE_URL', 'http://localhost/sti-activity-system');
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB

// Allowed file types
define('ALLOWED_TYPES', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'gif']);

// Create DB connection
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Auth helpers
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function requireLogin(string $role = ''): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/auth/login.php');
        exit;
    }
    if ($role && $_SESSION['role'] !== $role) {
        header('Location: ' . BASE_URL . '/auth/unauthorized.php');
        exit;
    }
}

function requireAnyRole(array $roles): void {
    if (!isLoggedIn() || !in_array($_SESSION['role'], $roles)) {
        header('Location: ' . BASE_URL . '/auth/unauthorized.php');
        exit;
    }
}

function currentUser(): array {
    return [
        'id'       => $_SESSION['user_id'] ?? null,
        'name'     => $_SESSION['user_name'] ?? '',
        'role'     => $_SESSION['role'] ?? '',
        'email'    => $_SESSION['email'] ?? '',
        'initials' => $_SESSION['initials'] ?? 'U',
    ];
}

// Generate proposal code
function generateProposalCode(): string {
    return 'STI-' . date('Y') . '-' . strtoupper(substr(uniqid(), -6));
}

// Status routing logic
function getNextApprover(string $source): ?string {
    return $source === 'student_organization' ? 'arjay' : 'ian';
}

// Format date helper
function formatDate(string $date): string {
    return date('F j, Y', strtotime($date));
}

function formatDateTime(string $dt): string {
    return date('M j, Y g:i A', strtotime($dt));
}

// Sanitize input
function sanitize(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)));
}

// JSON response
function jsonResponse(bool $success, string $message, array $data = []): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}
