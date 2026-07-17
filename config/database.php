<?php
// 1. DATABASE CONFIURATION (Constants)
define("DB_HOST", "localhost");
define("DB_NAME", "sti_activity_system");
define("DB_USER", "root");
define("DB_PASS", "");
define("DB_CHARSET", "utf8mb4");

// 2. FUNCTION DEFINITION
function getDB()
{
    // A. Static variable to keep the connection alive
    static $pdo = null;

    // B. Check if connection doesn't exist yet
    if ($pdo === null) {

        // C. Setup connection details
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

        // D. Setup PDO behavior settings
        $options = [
            PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE    => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // E. Attetmptt the connection (Try/Catch)
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed.']));
        }
    }
    // 3. Returns the PSO connection object back to whoever called it
    return $pdo;
}
