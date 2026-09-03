<?php
/**
 * migrate_entities.php
 *
 * One-time migration script to decode pre-existing HTML entities in the database
 * to their raw text counterparts.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();

echo "Starting database entity migration...\n";

$tables = [
    'activities' => [
        'title', 'description', 'theme', 'venue', 'venue_address', 
        'general_objectives', 'specific_objectives', 'involved_subjects', 
        'rationale', 'evaluation_method'
    ],
    'materials' => [
        'item_name', 'description', 'provider'
    ],
    'program_sequence' => [
        'segment', 'description', 'person_ic'
    ],
    'manpower' => [
        'role', 'assigned_person'
    ],
    'schedules' => [
        'event_name', 'venue', 'organizer'
    ],
    'guidelines' => [
        'mechanics', 'criteria', 'scoring_system', 'special_awards'
    ],
    'faculty_tasks' => [
        'faculty_name', 'assigned_task', 'contribution_desc', 'role_in_event'
    ],
    'floor_plans' => [
        'notes'
    ]
];

$replacements = [
    '&#039;' => "'",
    '&quot;' => '"',
    '&lt;'   => '<',
    '&gt;'   => '>',
    '&amp;'  => '&' // Done last to avoid partial entity decoding issues
];

try {
    $db->beginTransaction();

    foreach ($tables as $table => $columns) {
        echo "Processing table: {$table}...\n";
        
        // Fetch all rows
        $stmt = $db->query("SELECT id FROM `{$table}`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($rows)) {
            echo "  --> Table empty. Skipping.\n";
            continue;
        }

        // Build select for columns
        $colList = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
        $stmtData = $db->prepare("SELECT id, {$colList} FROM `{$table}` WHERE id = ?");
        
        // Build update
        $setClauses = [];
        foreach ($columns as $col) {
            $setClauses[] = "`{$col}` = ?";
        }
        $updateSql = "UPDATE `{$table}` SET " . implode(', ', $setClauses) . " WHERE id = ?";
        $stmtUpdate = $db->prepare($updateSql);

        $updatedCount = 0;
        foreach ($rows as $r) {
            $stmtData->execute([$r['id']]);
            $data = $stmtData->fetch(PDO::FETCH_ASSOC);
            if (!$data) continue;

            $needsUpdate = false;
            $updatedVals = [];
            
            foreach ($columns as $col) {
                $originalVal = $data[$col] ?? '';
                if ($originalVal === null) {
                    $updatedVals[] = null;
                    continue;
                }
                
                // Decode entities
                $newVal = htmlspecialchars_decode($originalVal, ENT_QUOTES);
                
                if ($newVal !== $originalVal) {
                    $needsUpdate = true;
                }
                $updatedVals[] = $newVal;
            }

            if ($needsUpdate) {
                // Append ID to parameters
                $updatedVals[] = $r['id'];
                $stmtUpdate->execute($updatedVals);
                $updatedCount++;
            }
        }
        
        echo "  --> Updated {$updatedCount} rows in {$table}.\n";
    }

    $db->commit();
    echo "\nMigration completed successfully!\n";

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "\nMigration failed: " . $e->getMessage() . "\n";
}
