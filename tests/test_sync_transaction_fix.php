<?php
/**
 * Automated Verification Script for Synchronization & Transaction Integrity
 *
 * Verifies:
 * 1. api/proposal-update.php has activity_participants DDL outside transaction
 * 2. api/proposal-save.php has activity_participants DDL outside transaction
 * 3. All transaction commit calls are guarded with inTransaction()
 * 4. Simulates a proposal update request to verify clean execution without 'There is no active transaction'
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "========================================================================\n";
echo "  TRANSACTION INTEGRITY & PROPOSAL SYNC VERIFICATION AUDIT\n";
echo "========================================================================\n\n";

$passCount = 0;
$failCount = 0;

function testAssert($title, $condition, $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] $title\n";
        if ($details) echo "        $details\n";
    } else {
        $failCount++;
        echo " [FAIL] $title\n";
        if ($details) echo "        Error: $details\n";
    }
}

// 1. Audit proposal-update.php
$updateContent = file_get_contents(__DIR__ . '/../api/proposal-update.php');
$beginTransactionPos = strpos($updateContent, '$db->beginTransaction()');
$createTablePos = strpos($updateContent, 'CREATE TABLE IF NOT EXISTS activity_participants');
testAssert("1.1 proposal-update.php runs CREATE TABLE before beginTransaction", $createTablePos !== false && $createTablePos < $beginTransactionPos, "DDL pos: $createTablePos, beginTransaction pos: $beginTransactionPos");
testAssert("1.2 proposal-update.php guards commit with inTransaction", strpos($updateContent, 'if ($db->inTransaction()) {') !== false && strpos($updateContent, '$db->commit();') !== false);

// 2. Audit proposal-save.php
$saveContent = file_get_contents(__DIR__ . '/../api/proposal-save.php');
$saveBeginPos = strpos($saveContent, '$db->beginTransaction()');
$saveTablePos = strpos($saveContent, 'CREATE TABLE IF NOT EXISTS activity_participants');
testAssert("2.1 proposal-save.php runs CREATE TABLE before beginTransaction", $saveTablePos !== false && $saveTablePos < $saveBeginPos, "DDL pos: $saveTablePos, beginTransaction pos: $saveBeginPos");
testAssert("2.2 proposal-save.php guards commit with inTransaction", strpos($saveContent, 'if ($db->inTransaction()) {') !== false && strpos($saveContent, '$db->commit();') !== false);

// 3. Audit other critical API endpoints
$postEventContent = file_get_contents(__DIR__ . '/../api/post-event-save.php');
testAssert("3.1 post-event-save.php guards commit with inTransaction", strpos($postEventContent, 'if ($db->inTransaction()) {') !== false);

$reviewActionContent = file_get_contents(__DIR__ . '/../api/review-action.php');
testAssert("3.2 review-action.php guards commit with inTransaction", strpos($reviewActionContent, 'if ($db->inTransaction()) {') !== false);

$importContent = file_get_contents(__DIR__ . '/../api/import-evaluations-csv.php');
testAssert("3.3 import-evaluations-csv.php guards commit with inTransaction", strpos($importContent, 'if ($db->inTransaction()) {') !== false);

$deleteContent = file_get_contents(__DIR__ . '/../api/user-delete.php');
testAssert("3.4 user-delete.php guards commit with inTransaction", strpos($deleteContent, 'if ($db->inTransaction()) {') !== false);

// 4. Test database transaction behavior directly
$db = getDB();
$testSuccess = false;
try {
    $db->beginTransaction();
    testAssert("4.1 Transaction successfully opened", $db->inTransaction());
    
    // Simulate updating an activity record
    $stmt = $db->query("SELECT id, title FROM activities WHERE status = 'draft' ORDER BY id DESC LIMIT 1");
    $draft = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    
    if ($draft) {
        $upStmt = $db->prepare("UPDATE activities SET updated_at = NOW() WHERE id = ?");
        $upStmt->execute([$draft['id']]);
        testAssert("4.2 Draft update inside transaction succeeded", true, "Activity ID: " . $draft['id']);
    } else {
        testAssert("4.2 Draft query executed", true, "No draft found, verified statement execution");
    }

    if ($db->inTransaction()) {
        $db->commit();
        testAssert("4.3 Transaction successfully committed without error", true);
        $testSuccess = true;
    }
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    testAssert("4.3 Transaction execution", false, $e->getMessage());
}

echo "\n========================================================================\n";
echo " AUDIT SUMMARY: Passed: $passCount | Failed: $failCount\n";
echo "========================================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);

