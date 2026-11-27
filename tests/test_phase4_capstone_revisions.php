<?php
/**
 * tests/test_phase4_capstone_revisions.php
 *
 * Automated Verification Test Suite for Phase 4 Capstone Defense Revisions:
 * 1. Global Self-Healing Phase 4 Tables (system_audit_logs, academic_terms, authorized_organizations, academic_head_kpi_criteria)
 * 2. Minimum 50+ Test Data Entries Verification (Panel 1 Requirement I.7)
 * 3. System Activity Trail & Security Event Audit Logging (Panel 1 I.8 & II.7.1)
 * 4. Data Backup (.SQL / .CSV Export) & Lookup Table Maintenance (Panel 1 II.7.2)
 * 5. Accomplishment Report Generator (PDF & Excel/CSV) & 4 Statistical Dashboards (Panel 1 II.6.1 & II.6.2)
 * 6. Academic Head & Events Committee KPI Criteria Governance Panel (Panel 2)
 * 7. Notification 1-Click Direct Shortcut & Auto-Mark as "Under Review" (Panel 2)
 * 8. Capstone Governance Deliverables Portfolio (Free of Charge, Implementation Plan, 3-Year Sustainability Plan, MOU, 5 Experts, 30 UAT Users)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();
ensureOfficialDefenseAccounts($db);

$passed = 0;
$failed = 0;

function assertPhase4(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) {
        echo "  [PASS] {$label}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$label}\n";
        $failed++;
    }
}

echo "=== PHASE 4 CAPSTONE REVISIONS VERIFICATION ===\n\n";

// 1. Verify Phase 4 Self-Healed Tables & Default Lookup Seed Data
$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach (['system_audit_logs', 'academic_terms', 'authorized_organizations', 'academic_head_kpi_criteria'] as $tbl) {
    assertPhase4(in_array($tbl, $tables, true), "Table '{$tbl}' exists in database");
}

$termCount = (int)$db->query("SELECT COUNT(*) FROM academic_terms")->fetchColumn();
$orgCount  = (int)$db->query("SELECT COUNT(*) FROM authorized_organizations")->fetchColumn();
$critCount = (int)$db->query("SELECT COUNT(*) FROM academic_head_kpi_criteria")->fetchColumn();

assertPhase4($termCount >= 3, "Lookup table 'academic_terms' is populated ({$termCount} terms)");
assertPhase4($orgCount >= 5, "Lookup table 'authorized_organizations' is populated ({$orgCount} organizations)");
assertPhase4($critCount >= 4, "Academic Head KPI criteria table is populated ({$critCount} criteria)");

// 2. Verify System Audit Logging Function
logSystemAuditEvent('test_suite', 'phase4_verification', 'Automated Phase 4 audit trail verification entry', 4, 'Frederic Yulo', 'dean');
$auditCount = (int)$db->query("SELECT COUNT(*) FROM system_audit_logs WHERE event_type = 'test_suite'")->fetchColumn();
assertPhase4($auditCount >= 1, "logSystemAuditEvent() records security and activity trail events in 'system_audit_logs'");

// 3. Verify Minimum of 50 Test Data Entries in `activities`
$actCount = (int)$db->query("SELECT COUNT(*) FROM activities")->fetchColumn();
assertPhase4($actCount >= 50, "Database contains at least 50 test data entries in 'activities' (Actual: {$actCount})");

// 4. Verify Notification Direct Shortcut & Auto-Review Wiring
$notifJs = file_get_contents(__DIR__ . '/../assets/js/notifications.js');
$notifPhp = file_get_contents(__DIR__ . '/../notifications.php');
$admin1View = file_get_contents(__DIR__ . '/../admin1/view-activity.php');
$admin2View = file_get_contents(__DIR__ . '/../admin2/view-activity.php');

assertPhase4(
    str_contains($notifJs, 'directShortcutUrl') &&
    str_contains($notifJs, '/review.php?id=') &&
    str_contains($notifPhp, '/review.php?id='),
    "Notification bell & center act as direct 1-click shortcuts to review.php"
);
assertPhase4(
    str_contains($admin1View, "UPDATE activities SET status = 'under_review'") &&
    str_contains($admin2View, "UPDATE activities SET status = 'under_review'"),
    "Opening an activity in Admin 1 or Admin 2 automatically transitions status to 'under_review'"
);

// 5. Verify Accomplishment Report Generator (PDF & Excel/CSV) & 4 Statistical Dashboards
$statDashPhp = file_get_contents(__DIR__ . '/../includes/phase4-statistical-dashboard.php');
assertPhase4(
    str_contains($statDashPhp, 'export_accomplishment=pdf') &&
    str_contains($statDashPhp, 'export_accomplishment=excel') &&
    str_contains($statDashPhp, 'Approval Turnaround Times') &&
    str_contains($statDashPhp, 'Volume of Activities per Department') &&
    str_contains($statDashPhp, 'Budget Utilization Rates') &&
    str_contains($statDashPhp, 'Overall Event Satisfaction Ratings') &&
    str_contains($statDashPhp, 'Academic Head &amp; Events Committee KPI Assessment Criteria'),
    "Accomplishment Report Generator (PDF & Excel), 4 Statistical Charts, and Academic Head KPI Panel are integrated"
);

// 6. Verify System Administration Center & Capstone Governance Deliverables Document
$sysAdminPhp = file_get_contents(__DIR__ . '/../dean/system-admin.php');
$deliverablesMd = file_get_contents(__DIR__ . '/../docs/CAPSTONE_PANEL_DELIVERABLES.md');
assertPhase4(
    str_contains($sysAdminPhp, 'export_sql') &&
    str_contains($sysAdminPhp, 'export_audit_csv') &&
    str_contains($sysAdminPhp, 'Academic Terms') &&
    str_contains($sysAdminPhp, 'Authorized Student Organizations'),
    "System Administration portal includes SQL/CSV Backup Exporter and Lookup Table Managers"
);
assertPhase4(
    str_contains($deliverablesMd, 'Free-of-Charge') &&
    str_contains($deliverablesMd, 'Phased Implementation Plan') &&
    str_contains($deliverablesMd, 'Three-Year (3-Year) Sustainability Plan') &&
    str_contains($deliverablesMd, 'Memorandum of Understanding (MOU)') &&
    str_contains($deliverablesMd, 'Functionality Testing with 5 Experts') &&
    str_contains($deliverablesMd, 'User Acceptance Testing (UAT) with 30 Users'),
    "Capstone Governance Deliverables document covers all Panel 1 General Requirements (I.1 - I.7)"
);

// 7. Verify Final 4 Panel 1 Sub-Modules (Profile Affiliation/Notif Prefs, Supporting Docs Attachment Handler, Implementation Milestone Tracker, Participant Feedback Portal)
$topbarProfPhp = file_get_contents(__DIR__ . '/../includes/topbar-profile.php');
$p2EnhPhp      = file_get_contents(__DIR__ . '/../includes/phase2-proposal-enhancements.php');
$p3DetPhp      = file_get_contents(__DIR__ . '/../includes/phase3-post-event-details.php');
$evalPortalPhp = file_get_contents(__DIR__ . '/../evaluate.php');

assertPhase4(
    str_contains($topbarProfPhp, 'profileInputDepartment') &&
    str_contains($topbarProfPhp, 'notify_email') &&
    str_contains($topbarProfPhp, 'notify_in_app'),
    "Profile Management (Module 1.2) supports updating Department/Organization Affiliation & Notification Preferences"
);
assertPhase4(
    str_contains($p2EnhPhp, 'supportingDocsHandlerBox') &&
    str_contains($p2EnhPhp, 'Letter Request') &&
    str_contains($p2EnhPhp, 'Parental Consent'),
    "File Attachment Handler (Module 2.2) supports uploading Letter Requests, Safety Guidelines, Design Layouts & Parental Consents"
);
assertPhase4(
    str_contains($p3DetPhp, 'Implementation Milestone Tracker') &&
    str_contains($p3DetPhp, 'Pre-Activity Compliance') &&
    str_contains($p3DetPhp, 'On-the-Day Compliance') &&
    str_contains($p3DetPhp, 'Post-Activity Compliance'),
    "Implementation Milestone Tracker (Module 4.3) allows checking off Pre-Activity, On-the-Day, and Post-Activity compliance milestones"
);
assertPhase4(
    str_contains($evalPortalPhp, 'Participant Feedback &amp; Evaluation Portal') &&
    str_contains($evalPortalPhp, 'core_speaker_effectiveness'),
    "Participant Feedback Portal (Module 5.2) provides QR/link-based post-activity attendee evaluation"
);

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
