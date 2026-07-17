<?php
$files = [
    'c:/xampp/htdocs/sti-activity-system/faculty/dashboard.php',
    'c:/xampp/htdocs/sti-activity-system/dean/dashboard.php',
    'c:/xampp/htdocs/sti-activity-system/admin2/dashboard.php',
    'c:/xampp/htdocs/sti-activity-system/admin1/dashboard.php',
];

foreach ($files as $fp) {
    $content = file_get_contents($fp);

    // 1. Remove the old Dropdown and Backdrop from their current location (after "Welcome")
    $dropdownMatch = preg_match('/(\s*<!-- Backdrop -->.*?(?=<\/div>\s*<\/header>))/s', $content, $matches);
    if ($dropdownMatch) {
        $dropdownHtml = $matches[1];
        // Remove it from the current position
        $content = str_replace($dropdownHtml, '', $content);
        
        // 2. Wrap the Bell button and inject the dropdown inside the wrapper
        // The bell button starts with <!-- Notification Bell --> and ends with </button>
        $content = preg_replace(
            '/(<!-- Notification Bell -->.*?<\/button>)/s', 
            '<div class="notif-wrapper" style="position: relative; display: flex; align-items: center;">' . "\n        $1" . $dropdownHtml . "\n        </div>", 
            $content
        );
        
        file_put_contents($fp, $content);
    }
}

// 3. Remove .topbar-right { position: relative; } from main.css just in case
$cssFile = 'c:/xampp/htdocs/sti-activity-system/assets/css/main.css';
if (file_exists($cssFile)) {
    $cssContent = file_get_contents($cssFile);
    $cssContent = str_replace('.topbar-right { position: relative; }', '', $cssContent);
    // Optional: add top: calc(100% + 14px) and right: -10px for better bell alignment
    $cssContent = preg_replace(
        '/(\.notification-card\s*\{[^}]*?)top:\s*calc\(100%\s*\+\s*8px\);\s*right:\s*0;/s',
        '$1top: calc(100% + 14px); right: -12px;',
        $cssContent
    );
    file_put_contents($cssFile, $cssContent);
}

echo "Restructured HTML to wrap bell and dropdown together\n";
?>
