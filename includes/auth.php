<?php
require_once __DIR__ . '/../config/database.php';

function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function requireLogin(): void
{
    startSession();
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/auth/login.php');
        exit;
    }
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_destroy();
        header('Location: ' . BASE_URL . '/auth/login.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

function requireRole(string ...$roles): void
{
    requireLogin();
    if (!in_array($_SESSION['user_role'], $roles, true)) {
        http_response_code(403);
        die('Access denied.');
    }
}

function currentUser(): array
{
    return [
        'id'    => $_SESSION['user_id']   ?? null,
        'name'  => $_SESSION['user_name'] ?? '',
        'email' => $_SESSION['user_email'] ?? '',
        'role'  => $_SESSION['user_role'] ?? '',
        'dept'  => $_SESSION['user_dept'] ?? '',
    ];
}

function login(string $email, string $password): array|false
{
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([trim($email)]);
    $user = $stmt->fetch();

    // password_verify handles bcrypt, argon2, etc.
    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }

    // Re-hash if algorithm/cost factor is outdated
    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $db->prepare('UPDATE users SET password = ? WHERE id = ?')
            ->execute([$newHash, $user['id']]);
    }

    startSession();
    session_regenerate_id(true);
    $_SESSION['user_id']       = $user['id'];
    $_SESSION['user_name']     = $user['name'];
    $_SESSION['user_email']    = $user['email'];
    $_SESSION['user_role']     = $user['role'];
    $_SESSION['user_dept']     = $user['department'];
    $_SESSION['last_activity'] = time();
    return $user;
}

function logout(): void
{
    startSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $p['path'],
            $p['domain'],
            $p['secure'],
            $p['httponly']
        );
    }
    session_destroy();
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}
