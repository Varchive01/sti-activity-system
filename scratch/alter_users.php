<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();

$alterations = [
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS employee_id VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS contact_number VARCHAR(30) DEFAULT NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS theme_preference ENUM('light','dark','system') DEFAULT 'system'",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS notification_prefs JSON DEFAULT NULL",
];

$results = [];
foreach ($alterations as $sql) {
    try {
        $db->exec($sql);
        $results[] = "✅ $sql";
    } catch (PDOException $e) {
        $results[] = "⚠️ {$e->getMessage()} — SQL: $sql";
    }
}

// Create notifications table if not exists
$createNotif = "
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'info',
    message TEXT NOT NULL,
    link VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_unread (user_id, is_read)
)";
try {
    $db->exec($createNotif);
    $results[] = "✅ notifications table created/verified.";
} catch (PDOException $e) {
    $results[] = "⚠️ " . $e->getMessage();
}

echo "<pre>" . implode("\n", $results) . "</pre>";
echo "<p>Done. You can delete this file.</p>";
