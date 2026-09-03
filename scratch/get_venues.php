<?php
require_once __DIR__ . '/../config/database.php';
try {
    $db = getDB();
    $stmt = $db->query("SELECT DISTINCT venue FROM activities WHERE venue IS NOT NULL AND venue != ''");
    $venues = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode($venues) . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
