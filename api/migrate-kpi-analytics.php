<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();

try {
    // 1. Table for individual KPI analytics
    $db->exec("
        CREATE TABLE IF NOT EXISTS `activity_kpi_analytics` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `activity_id` int(11) NOT NULL,
          `analytics_json` longtext NOT NULL,
          `data_hash` varchar(64) NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_activity_kpi_activity_id` (`activity_id`),
          CONSTRAINT `fk_kpi_analytics_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Table 'activity_kpi_analytics' created/verified successfully.\n";
    
    // 2. Table for institutional KPI analytics
    $db->exec("
        CREATE TABLE IF NOT EXISTS `institutional_kpi_analytics` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `year` int(11) NOT NULL,
          `period` varchar(100) NOT NULL DEFAULT 'all',
          `analytics_json` longtext NOT NULL,
          `data_hash` varchar(64) NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_inst_year_period` (`year`, `period`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Table 'institutional_kpi_analytics' created/verified successfully.\n";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
