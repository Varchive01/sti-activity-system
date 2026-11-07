<?php
/**
 * scripts/cleanup_duplicate_proposals.php
 * One-click utility to clean up duplicate proposals in the database.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    echo "<!DOCTYPE html><html><head><title>STI-ARMES Proposal Deduplicator</title>";
    echo "<style>body{font-family:system-ui,sans-serif;padding:30px;background:#f8fafc;color:#0f172a;} .card{background:#fff;border-radius:12px;padding:24px;border:1px solid #e2e8f0;max-width:700px;margin:0 auto;box-shadow:0 4px 12px rgba(0,0,0,0.05);}</style></head><body><div class='card'>";
    echo "<h2 style='margin-top:0;'>🧹 Duplicate Proposal Cleanup Utility</h2>";
}

try {
    $db = getDB();
    $res = cleanupDuplicateProposals($db);
    
    $deletedCount = count($res['deleted_ids']);
    $msg = "Deduplication completed successfully. Cleaned up {$deletedCount} duplicate proposal(s).";
    if ($deletedCount > 0) {
        $msg .= " Deleted ID(s): " . implode(', ', $res['deleted_ids']) . ". Retained ID(s): " . implode(', ', $res['retained_ids']) . ".";
    }

    if ($isCli) {
        echo "[SUCCESS] $msg\n";
    } else {
        echo "<div style='color:#16a34a;padding:12px;background:#f0fdf4;border-radius:8px;border:1px solid #bbf7d0;'><strong>✓ Success:</strong> " . htmlspecialchars($msg) . "</div>";
        echo "<p style='margin-top:16px;'><a href='" . BASE_URL . "/faculty/dashboard.php' style='color:#0284c7;font-weight:600;'>Return to Dashboard →</a></p>";
    }
} catch (\Throwable $e) {
    $err = "Deduplication failed: " . $e->getMessage();
    if ($isCli) {
        echo "[ERROR] $err\n";
    } else {
        echo "<div style='color:#dc2626;padding:12px;background:#fef2f2;border-radius:8px;border:1px solid #fecaca;'><strong>✕ Error:</strong> " . htmlspecialchars($err) . "</div>";
    }
}

if (!$isCli) {
    echo "</div></body></html>";
}

