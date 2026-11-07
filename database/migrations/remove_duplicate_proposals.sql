-- database/migrations/remove_duplicate_proposals.sql
-- Removes duplicate proposal records while retaining the primary proposal.
-- Run in phpMyAdmin or MySQL console if performing manual database maintenance.

-- 1. Identify and delete redundant child records for duplicate 'Future Professionals: Career Readiness Seminar' proposals
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

DELETE FROM notifications 
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

-- 2. Delete redundant duplicate activity entry
DELETE FROM activities 
WHERE id IN (
    SELECT id FROM (
        SELECT id FROM activities 
        WHERE (title LIKE '%Future Professionals%' OR title LIKE '%Career Readiness%')
        ORDER BY id DESC LIMIT 1 OFFSET 1
    ) AS tmp
);
