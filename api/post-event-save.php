<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/EmailService.php';
requireRole('faculty');

$user = currentUser();
$db   = getDB();
$id   = (int)($_POST['activity_id'] ?? 0);

// Verify ownership and status
$check = $db->prepare("SELECT * FROM activities WHERE id=? AND faculty_id=? AND status='approved'");
$check->execute([$id, $user['id']]);
if (!$check->fetch()) {
    header('Location: ' . BASE_URL . '/faculty/post-event.php');
    exit;
}

try {
    $db->beginTransaction();

    // Insert a minimal post_event record (attendance/KPI/observations come from QR evaluations & Sir Ian)
    $db->prepare("
        INSERT INTO post_event (activity_id, target_attendance, submitted_by, submitted_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE target_attendance=VALUES(target_attendance), submitted_by=VALUES(submitted_by), submitted_at=NOW()
    ")->execute([
        $id,
        (int)($_POST['target_attendance'] ?? 0),
        $user['id'],
    ]);

    // Upload supporting documents
    if (!empty($_FILES['post_event_files']['name'][0])) {
        $docStmt = $db->prepare("INSERT INTO documents(activity_id,doc_type,file_name,file_path,uploaded_by) VALUES(?,?,?,?,?)");
        foreach ($_FILES['post_event_files']['name'] as $i => $fname) {
            if (!$fname || $_FILES['post_event_files']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $file = [
                'name'     => $fname,
                'tmp_name' => $_FILES['post_event_files']['tmp_name'][$i],
                'size'     => $_FILES['post_event_files']['size'][$i],
                'error'    => $_FILES['post_event_files']['error'][$i],
            ];
            $path = uploadFile($file, 'post-event', $id);
            if ($path) $docStmt->execute([$id, 'post_event', $fname, $path, $user['id']]);
        }
    }

    // Mark as completed
    $db->prepare("UPDATE activities SET status='completed' WHERE id=?")->execute([$id]);

    // Notify Sir Ian
    $ian = $db->query("SELECT id, email, name FROM users WHERE role='admin2' LIMIT 1")->fetch();
    if ($ian) {
        $titleStmt = $db->prepare("SELECT title FROM activities WHERE id=? LIMIT 1");
        $titleStmt->execute([$id]);
        $title = $titleStmt->fetchColumn() ?: 'activity';

        $db->prepare("INSERT INTO notifications(user_id,activity_id,message) VALUES(?,?,?)")
            ->execute([$ian['id'], $id, "Post-event report uploaded for: {$title}. Ready for KPI review and evaluation."]);
            
        $emailService = new EmailService();
        $emailService->sendNotification(
            $ian['email'],
            $ian['name'],
            "Post-Event Evaluation Available: " . sanitize($title),
            sanitize($title),
            "Completed",
            "A Post-Event evaluation has been uploaded and is ready for KPI review.",
            BASE_URL . "/admin2/view-activity.php?id=" . $id,
            $id,
            "post_event_available"
        );
    }

    $db->commit();
    header("Location: " . BASE_URL . "/faculty/post-event.php?saved=1");
    exit;
} catch (Exception $e) {
    error_log("Post Event Save Error: " . $e->getMessage());
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    header("Location: " . BASE_URL . "/faculty/post-event.php?activity_id={$id}&error=1");
    exit;
}
