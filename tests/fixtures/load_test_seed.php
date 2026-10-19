<?php
/**
 * tests/fixtures/load_test_seed.php
 *
 * Safe loader for test_seed.sql fixture.
 * Strictly prevents execution against production or non-test databases.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Access denied: CLI only.\n");
}

require_once __DIR__ . '/../../config/database.php';

// Safe check: Only allow running if explicitly targeting a test database or environment variable
$testDb = getenv('STI_TEST_DB');
$allowSeed = getenv('ALLOW_TEST_SEED') === '1';

if (!$testDb && !$allowSeed) {
    fwrite(STDERR, "[BLOCKED] test_seed.sql cannot be loaded into the default or production database!\n");
    fwrite(STDERR, "To load seed data for isolated testing, set STI_TEST_DB=<test_database> or ALLOW_TEST_SEED=1.\n");
    exit(1);
}

$dbName = $testDb ?: DB_NAME;

// Disallow known production database names
if (in_array(strtolower($dbName), ['sti_activity_system_prod', 'sti_activity_system_production', 'production'])) {
    fwrite(STDERR, "[CRITICAL] Refusing to seed a production database.\n");
    exit(1);
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . $dbName . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $sql = file_get_contents(__DIR__ . '/test_seed.sql');
    $pdo->exec($sql);
    echo "[SUCCESS] Test seed fixture loaded into '$dbName'.\n";
    exit(0);
} catch (PDOException $e) {
    fwrite(STDERR, "[ERROR] Failed to load test seed fixture: " . $e->getMessage() . "\n");
    exit(1);
}
