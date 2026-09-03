<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `activity_feedback_analysis` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `activity_id` int(11) NOT NULL,
          `analysis_json` longtext NOT NULL,
          `response_hash` varchar(64) NOT NULL,
          `responses_count` int(11) NOT NULL DEFAULT 0,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_activity_id` (`activity_id`),
          CONSTRAINT `fk_feedback_analysis_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Table 'activity_feedback_analysis' created successfully.\n";
} catch (Exception $e) {
    echo "Error creating table: " . $e->getMessage() . "\n";
}
