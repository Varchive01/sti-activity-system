<?php
require_once __DIR__ . '/../config/database.php';
try {
    $db = getDB();
    $stmt = $db->query("SELECT title, event_date, start_time, end_time, venue FROM activities WHERE status = 'approved' LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo json_encode($row, JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "No approved events found.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
