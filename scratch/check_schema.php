<?php
require 'c:/xampp/htdocs/sti-activity-system/config/config.php';
require 'c:/xampp/htdocs/sti-activity-system/config/database.php';
$db = getDB();

echo "Inserting a mock approved activity for conflict testing...\n";

// Get a faculty ID
$stmt = $db->query("SELECT id FROM users WHERE role = 'faculty' LIMIT 1");
$facultyId = $stmt->fetchColumn() ?: 1;

// Check if it already exists
$check = $db->query("SELECT COUNT(*) FROM activities WHERE title = 'Test Approved Seminar'")->fetchColumn();

if ($check == 0) {
    $ins = $db->prepare("INSERT INTO activities (
        faculty_id, title, description, theme, venue, event_date, start_time, end_time, status
    ) VALUES (?, 'Test Approved Seminar', 'Mock desc', 'Mock theme', 'Gymnasium', '2026-09-02', '10:00:00', '12:00:00', 'approved')");
    $ins->execute([$facultyId]);
    echo "Inserted approved activity:\n";
    echo "  - Title: 'Test Approved Seminar'\n";
    echo "  - Venue: 'Gymnasium'\n";
    echo "  - Date: '2026-09-02'\n";
    echo "  - Time: '10:00:00' to '12:00:00'\n";
} else {
    echo "Mock approved activity already exists.\n";
}
