<?php
echo "======================================================================\n";
echo "  VERIFICATION: Standardize Dashboard Header Titles Across All Roles\n";
echo "======================================================================\n\n";

$roles = [
    'Faculty' => [
        'file' => __DIR__ . '/../faculty/dashboard.php',
        'expected_title' => 'Faculty Dashboard',
    ],
    'Program Head' => [
        'file' => __DIR__ . '/../program_head/dashboard.php',
        'expected_title' => 'Program Head Dashboard',
    ],
    'Admin 1' => [
        'file' => __DIR__ . '/../admin1/dashboard.php',
        'expected_title' => 'Committee Dashboard',
    ],
    'Admin 2' => [
        'file' => __DIR__ . '/../admin2/dashboard.php',
        'expected_title' => 'Committee Dashboard',
    ],
    'Dean' => [
        'file' => __DIR__ . '/../dean/dashboard.php',
        'expected_title' => 'Academic Head Dashboard',
    ],
];

$allPassed = true;

foreach ($roles as $roleName => $info) {
    echo "Checking {$roleName} Dashboard:\n";
    if (!file_exists($info['file'])) {
        echo " [FAIL] File not found: {$info['file']}\n\n";
        $allPassed = false;
        continue;
    }

    $content = file_get_contents($info['file']);

    // Check topbar page-title element
    if (preg_match('/<div class="page-title">\s*([^<]+)\s*<\/div>/', $content, $matches)) {
        $actualHeading = trim($matches[1]);
        if ($actualHeading === $info['expected_title']) {
            echo "   - Topbar Header Title: [PASS] '{$actualHeading}'\n";
        } else {
            echo "   - Topbar Header Title: [FAIL] Expected '{$info['expected_title']}', got '{$actualHeading}'\n";
            $allPassed = false;
        }
    } else {
        echo "   - Topbar Header Title: [FAIL] <div class=\"page-title\"> not found\n";
        $allPassed = false;
    }

    // Check <title> tag
    if (preg_match('/<title>\s*([^<]+)\s*<\/title>/', $content, $tMatches)) {
        $headTitle = trim($tMatches[1]);
        if (str_contains($headTitle, $info['expected_title'])) {
            echo "   - Document <title>:   [PASS] '{$headTitle}'\n";
        } else {
            echo "   - Document <title>:   [WARN] '{$headTitle}' (does not contain '{$info['expected_title']}')\n";
        }
    }

    echo "\n";
}

echo "----------------------------------------------------------------------\n";
if ($allPassed) {
    echo ">>> ALL 5 ROLE DASHBOARDS STANDARDIZED SUCCESSFULLY! <<<\n";
    exit(0);
} else {
    echo ">>> VERIFICATION FAILED! <<<\n";
    exit(1);
}

