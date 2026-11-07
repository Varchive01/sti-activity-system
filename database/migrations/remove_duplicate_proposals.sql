-- database/migrations/remove_duplicate_proposals.sql
-- Removes duplicate proposal records safely without hitting foreign key constraint errors.
-- Run in phpMyAdmin or MySQL console.

-- 1. Temporarily disable foreign key checks to prevent #1451 constraint errors
SET FOREIGN_KEY_CHECKS = 0;

-- 2. Clean up child records across all related tables for the duplicate proposal
DELETE FROM documents 
WHERE activity_id IN (
    SELECT id FROM (
        SELECT id FROM activities 
        WHERE (title LIKE '%Future Professionals%' OR title LIKE '%Career Readiness%')
        ORDER BY id DESC LIMIT 1 OFFSET 1
    ) AS tmp
);

DELETE FROM floor_plans 
WHERE activity_id IN (
    SELECT id FROM (
        SELECT id FROM activities 
        WHERE (title LIKE '%Future Professionals%' OR title LIKE '%Career Readiness%')
        ORDER BY id DESC LIMIT 1 OFFSET 1
    ) AS tmp
);

DELETE FROM faculty_tasks 
WHERE activity_id IN (
    SELECT id FROM (
        SELECT id FROM activities 
        WHERE (title LIKE '%Future Professionals%' OR title LIKE '%Career Readiness%')
        ORDER BY id DESC LIMIT 1 OFFSET 1
    ) AS tmp
);

DELETE FROM task_deliverables 
WHERE activity_id IN (
    SELECT id FROM (
        SELECT id FROM activities 
        WHERE (title LIKE '%Future Professionals%' OR title LIKE '%Career Readiness%')
        ORDER BY id DESC LIMIT 1 OFFSET 1
    ) AS tmp
);

DELETE FROM task_reminder_logs 
WHERE activity_id IN (
    SELECT id FROM (
        SELECT id FROM activities 
        WHERE (title LIKE '%Future Professionals%' OR title LIKE '%Career Readiness%')
        ORDER BY id DESC LIMIT 1 OFFSET 1
    ) AS tmp
);

DELETE FROM activity_participants 
WHERE activity_id IN (
    SELECT id FROM (
        SELECT id FROM activities 
        WHERE (title LIKE '%Future Professionals%' OR title LIKE '%Career Readiness%')
        ORDER BY id DESC LIMIT 1 OFFSET 1
    ) AS tmp
);

DELETE FROM materials 
WHERE activity_id IN (
    SELECT id FROM (
        SELECT id FROM activities 
        WHERE (title LIKE '%Future Professionals%' OR title LIKE '%Career Readiness%')
        ORDER BY id DESC LIMIT 1 OFFSET 1
    ) AS tmp
);

DELETE FROM manpower 
WHERE activity_id IN (
    SELECT id FROM (
        SELECT id FROM activities 
        WHERE (title LIKE '%Future Professionals%' OR title LIKE '%Career Readiness%')
        ORDER BY id DESC LIMIT 1 OFFSET 1
    ) AS tmp
);

DELETE FROM schedules 
WHERE activity_id IN (
    SELECT id FROM (
        SELECT id FROM activities 
        WHERE (title LIKE '%Future Professionals%' OR title LIKE '%Career Readiness%')
        ORDER BY id DESC LIMIT 1 OFFSET 1
    ) AS tmp
);

DELETE FROM program_sequence 
WHERE activity_id IN (
    SELECT id FROM (
        SELECT id FROM activities 
        WHERE (title LIKE '%Future Professionals%' OR title LIKE '%Career Readiness%')
        ORDER BY id DESC LIMIT 1 OFFSET 1
    ) AS tmp
);

DELETE FROM guidelines 
WHERE activity_id IN (
    SELECT id FROM (
        SELECT id FROM activities 
        WHERE (title LIKE '%Future Professionals%' OR title LIKE '%Career Readiness%')
        ORDER BY id DESC LIMIT 1 OFFSET 1
    ) AS tmp
);

DELETE FROM kpi_evaluations 
WHERE activity_id IN (
    SELECT id FROM (
        SELECT id FROM activities 
        WHERE (title LIKE '%Future Professionals%' OR title LIKE '%Career Readiness%')
        ORDER BY id DESC LIMIT 1 OFFSET 1
    ) AS tmp
);

DELETE FROM notifications 
WHERE activity_id IN (
    SELECT id FROM (
        SELECT id FROM activities 
        WHERE (title LIKE '%Future Professionals%' OR title LIKE '%Career Readiness%')
        ORDER BY id DESC LIMIT 1 OFFSET 1
    ) AS tmp
);

DELETE FROM approval_logs 
WHERE activity_id IN (
    SELECT id FROM (
        SELECT id FROM activities 
        WHERE (title LIKE '%Future Professionals%' OR title LIKE '%Career Readiness%')
        ORDER BY id DESC LIMIT 1 OFFSET 1
    ) AS tmp
);

DELETE FROM proposal_collaborators 
WHERE activity_id IN (
    SELECT id FROM (
        SELECT id FROM activities 
        WHERE (title LIKE '%Future Professionals%' OR title LIKE '%Career Readiness%')
        ORDER BY id DESC LIMIT 1 OFFSET 1
    ) AS tmp
);

DELETE FROM proposal_contribution_logs 
WHERE activity_id IN (
    SELECT id FROM (
        SELECT id FROM activities 
        WHERE (title LIKE '%Future Professionals%' OR title LIKE '%Career Readiness%')
        ORDER BY id DESC LIMIT 1 OFFSET 1
    ) AS tmp
);

-- 3. Delete redundant duplicate activity entry
DELETE FROM activities 
WHERE id IN (
    SELECT id FROM (
        SELECT id FROM activities 
        WHERE (title LIKE '%Future Professionals%' OR title LIKE '%Career Readiness%')
        ORDER BY id DESC LIMIT 1 OFFSET 1
    ) AS tmp
);

-- 4. Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;
