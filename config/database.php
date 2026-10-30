<?php
// config/database.php

/**
 * Resolves configuration values prioritizing system environment variables,
 * falling back to local .env file parsing and finally default values.
 * Never prints or logs credentials.
 */
function getDatabaseConfigValue(string ...$keys): ?string {
    static $envVars = null;
    if ($envVars === null) {
        $envVars = [];
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed === '' || str_starts_with($trimmed, '#')) continue;
                    $parts = explode('=', $trimmed, 2);
                    if (count($parts) === 2) {
                        $k = trim($parts[0]);
                        $v = trim($parts[1]);
                        $v = trim($v, "\"' \t\n\r\0\x0B");
                        $envVars[$k] = $v;
                    }
                }
            }
        }
    }

    foreach ($keys as $key) {
        $val = getenv($key);
        if ($val !== false && $val !== '') {
            return $val;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string)$_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string)$_SERVER[$key];
        }
        if (isset($envVars[$key]) && $envVars[$key] !== '') {
            return (string)$envVars[$key];
        }
    }
    return null;
}

// 1. DATABASE CONFIGURATION (Constants with local XAMPP defaults)
if (!defined('DB_HOST')) {
    define('DB_HOST', getDatabaseConfigValue('DB_HOST', 'MYSQLHOST', 'MYSQL_HOST') ?: 'localhost');
}
if (!defined('DB_PORT')) {
    $portVal = getDatabaseConfigValue('DB_PORT', 'MYSQLPORT', 'MYSQL_PORT');
    define('DB_PORT', $portVal ? (int)$portVal : 3306);
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getDatabaseConfigValue('DB_NAME', 'DB_DATABASE', 'MYSQLDATABASE', 'MYSQL_DATABASE') ?: 'sti_activity_system');
}
if (!defined('DB_USER')) {
    define('DB_USER', getDatabaseConfigValue('DB_USER', 'DB_USERNAME', 'MYSQLUSER', 'MYSQL_USER') ?: 'root');
}
if (!defined('DB_PASS')) {
    // Preserve empty password support for default local XAMPP setups
    $passVal = getDatabaseConfigValue('DB_PASS', 'DB_PASSWORD', 'MYSQLPASSWORD', 'MYSQL_PASSWORD');
    define('DB_PASS', $passVal !== null ? $passVal : '');
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', getDatabaseConfigValue('DB_CHARSET') ?: 'utf8mb4');
}

// 2. FUNCTION DEFINITION
function getDB(): PDO
{
    // Static variable to keep the connection alive
    static $pdo = null;

    if ($pdo === null) {
        // Build DSN with host, optional port, database name, and charset
        $dsn = "mysql:host=" . DB_HOST;
        if (defined('DB_PORT') && DB_PORT && (int)DB_PORT !== 3306) {
            $dsn .= ";port=" . (int)DB_PORT;
        }
        $dsn .= ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Database connection failed in getDB()');
            die(json_encode(['error' => 'Database connection failed.']));
        }
    }

    return $pdo;
}
