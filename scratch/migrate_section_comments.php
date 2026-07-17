<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();
$results = [];

// 1. Add revision_sections JSON column to activities (stores flagged sections on return)
$sqls = [
    "ALTER TABLE activities ADD COLUMN IF NOT EXISTS revision_sections JSON DEFAULT NULL",
];

foreach ($sqls as $sql) {
    try {
        $db->exec($sql);
        $results[] = "✅ $sql";
    } catch (PDOException $e) {
        $results[] = "⚠️ {$e->getMessage()} — SQL: $sql";
    }
}

// 2. Create section_comments table
$createSectionComments = "
CREATE TABLE IF NOT EXISTS section_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    activity_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    section_key VARCHAR(50) NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY activity_reviewer_section (activity_id, reviewer_id, section_key),
    KEY idx_activity (activity_id),
    CONSTRAINT sc_activity_fk FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    CONSTRAINT sc_reviewer_fk FOREIGN KEY (reviewer_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

try {
    $db->exec($createSectionComments);
    $results[] = "✅ section_comments table created/verified.";
} catch (PDOException $e) {
    $results[] = "⚠️ " . $e->getMessage();
}

echo "<pre>" . implode("\n", $results) . "</pre>";
echo "<p style='color:green;font-weight:bold;'>Migration complete. You can delete this file.</p>";
