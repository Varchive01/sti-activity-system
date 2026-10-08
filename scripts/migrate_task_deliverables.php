<?php
require_once __DIR__ . '/../config/database.php';

$db = getDB();

echo "Running task deliverables migration...\n";

// 1. Add completion_pct to faculty_tasks if missing
$checkCol = $db->query("SHOW COLUMNS FROM faculty_tasks LIKE 'completion_pct'")->fetch();
if (!$checkCol) {
    $db->exec("ALTER TABLE faculty_tasks ADD COLUMN completion_pct INT NOT NULL DEFAULT 0 AFTER status");
    echo "Added completion_pct column to faculty_tasks.\n";
} else {
    echo "completion_pct column already exists in faculty_tasks.\n";
}

// 2. Initialize completion_pct from existing status and descriptions
$db->exec("
    UPDATE faculty_tasks 
    SET completion_pct = 100 
    WHERE status = 'Completed' AND (completion_pct = 0 OR completion_pct IS NULL)
");
$db->exec("
    UPDATE faculty_tasks 
    SET completion_pct = 25 
    WHERE status = 'Delayed' AND (completion_pct = 0 OR completion_pct IS NULL)
");
$db->exec("
    UPDATE faculty_tasks 
    SET completion_pct = 50 
    WHERE status = 'In Progress' AND (completion_pct = 0 OR completion_pct IS NULL)
");

// 3. Create task_deliverables table
$db->exec("
    CREATE TABLE IF NOT EXISTS `task_deliverables` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `task_id` int(11) NOT NULL,
      `activity_id` int(11) NOT NULL,
      `file_name` varchar(300) NOT NULL,
      `file_path` varchar(500) NOT NULL,
      `file_size` int(11) DEFAULT 0,
      `mime_type` varchar(100) DEFAULT NULL,
      `uploaded_by` int(11) NOT NULL,
      `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `review_status` enum('pending','approved','returned_for_improvement') NOT NULL DEFAULT 'pending',
      `review_notes` text DEFAULT NULL,
      `reviewed_by` int(11) DEFAULT NULL,
      `reviewed_at` datetime DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_task_id` (`task_id`),
      KEY `idx_activity_id` (`activity_id`),
      KEY `idx_uploaded_by` (`uploaded_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");
echo "task_deliverables table verified.\n";

// 4. Ensure uploads/deliverables directory exists
$delivDir = __DIR__ . '/../uploads/deliverables';
if (!is_dir($delivDir)) {
    mkdir($delivDir, 0755, true);
    echo "Created uploads/deliverables directory.\n";
} else {
    echo "uploads/deliverables directory exists.\n";
}

echo "Migration completed successfully.\n";
