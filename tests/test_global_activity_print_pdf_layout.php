<?php
/**
 * tests/test_global_activity_print_pdf_layout.php
 *
 * Automated verification test ensuring activity detail printing and PDF export
 * follow the formal Export PDF layout globally across all user accounts.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

echo "=== TEST: Global Activity Printing & Export PDF Layout Parity ===\n\n";

$errors = [];

// 1. Verify includes/activity-print-document.php exists and passes PHP lint
$printDocPath = __DIR__ . '/../includes/activity-print-document.php';
if (!file_exists($printDocPath)) {
    $errors[] = "Missing includes/activity-print-document.php.";
} else {
    $lint = shell_exec('php -l ' . escapeshellarg($printDocPath));
    if (strpos($lint, 'No syntax errors detected') === false) {
        $errors[] = "Syntax error in includes/activity-print-document.php: $lint";
    } else {
        echo "[PASS] includes/activity-print-document.php exists and passes PHP lint.\n";
    }
}

$printDocContent = file_get_contents($printDocPath);

// 2. Verify formal layout sections in template
$requiredSections = [
    '#proposal-print-document' => 'Formal print document container',
    '@media print' => 'Print stylesheet',
    'print-brand-tagline' => 'STI-ARMES brand header',
    '1. Activity General Information' => 'Section 1: General Information',
    '2. Objectives &amp; Academic Alignment' => 'Section 2: Objectives & Alignment',
    'Schedule of Activities' => 'Section 4: Schedule of Activities table',
    'Program Sequence / Flow' => 'Section 5: Program Sequence table',
    'Working Committees &amp; Manpower Allocation' => 'Section 6: Manpower table',
    'Budget &amp; Materials Breakdown' => 'Section 8: Budget Breakdown table',
    'TOTAL ESTIMATED BUDGET' => 'Total budget calculation row',
    'Institutional Approval History &amp; Signatures' => 'Section 10: Signatures & Approval History',
    'exportProposalPdf' => 'Client-side PDF export function',
    'GLOBAL_PROPOSAL_PDF_DATA' => 'Global proposal data JSON export'
];

foreach ($requiredSections as $needle => $label) {
    if (strpos($printDocContent, $needle) !== false) {
        echo "[PASS] Found: $label\n";
    } else {
        $errors[] = "Missing in activity-print-document.php: $label ($needle)";
    }
}

// 3. Verify all 4 view-activity files include activity-print-document.php and have Print/Export buttons
$targets = [
    'dean/view-activity.php',
    'admin1/view-activity.php',
    'admin2/view-activity.php',
    'faculty/view-activity.php'
];

foreach ($targets as $relPath) {
    $filePath = __DIR__ . '/../' . $relPath;
    if (!file_exists($filePath)) {
        $errors[] = "File not found: $relPath";
        continue;
    }

    $c = file_get_contents($filePath);

    // Verify include
    if (strpos($c, 'activity-print-document.php') !== false) {
        echo "[PASS] $relPath includes activity-print-document.php\n";
    } else {
        $errors[] = "$relPath does not include activity-print-document.php";
    }

    // Verify Export PDF button is retained
    if (strpos($c, 'exportProposalPdf()') !== false) {
        echo "[PASS] $relPath retains Export PDF button\n";
    } else {
        $errors[] = "$relPath is missing Export PDF button (exportProposalPdf())";
    }

    // Verify Print button is removed from topbar
    if (strpos($c, 'onclick="window.print()"') === false) {
        echo "[PASS] $relPath successfully removed Print button\n";
    } else {
        $errors[] = "$relPath still contains Print button (onclick=\"window.print()\")";
    }

    // Verify PHP lint
    $lint = shell_exec('php -l ' . escapeshellarg($filePath));
    if (strpos($lint, 'No syntax errors detected') === false) {
        $errors[] = "$relPath has PHP syntax errors: $lint";
    } else {
        echo "[PASS] $relPath passes PHP syntax check\n";
    }
}

// 4. Test actual rendered output with a live activity record
$db = getDB();
$testAct = $db->query("SELECT id FROM activities WHERE status != 'draft' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($testAct) {
    $actId = (int)$testAct['id'];
    echo "\nTesting simulated render of activity ID #$actId for Dean role...\n";
    
    $_SESSION['user_id'] = 1;
    $_SESSION['user_role'] = 'dean';
    $_SESSION['role'] = 'dean';
    $_SESSION['user_name'] = 'Dean Evaluator';
    $_GET['id'] = $actId;
    
    ob_start();
    try {
        include __DIR__ . '/../dean/view-activity.php';
        $rendered = ob_get_clean();
        
        if (strpos($rendered, 'id="proposal-print-document"') !== false) {
            echo "[PASS] proposal-print-document rendered successfully in dean/view-activity.php\n";
        } else {
            $errors[] = "Rendered dean/view-activity.php missing #proposal-print-document.";
        }
        
        if (strpos($rendered, 'GLOBAL_PROPOSAL_PDF_DATA') !== false) {
            echo "[PASS] GLOBAL_PROPOSAL_PDF_DATA initialized in rendered output\n";
        } else {
            $errors[] = "GLOBAL_PROPOSAL_PDF_DATA not found in rendered output.";
        }
    } catch (\Throwable $e) {
        ob_end_clean();
        $errors[] = "Error during dean/view-activity.php render: " . $e->getMessage();
    }
}

echo "\n========================================================\n";
if (!empty($errors)) {
    echo "TEST FAILED:\n" . implode("\n", $errors) . "\n";
    exit(1);
} else {
    echo "ALL GLOBAL ACTIVITY PRINT & PDF LAYOUT TESTS PASSED!\n";
    echo "========================================================\n";
    exit(0);
}

