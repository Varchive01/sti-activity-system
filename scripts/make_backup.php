<?php
/**
 * scripts/make_backup.php
 * Creates project zip archive of application code, configuration templates, and database migrations.
 */

$backupDir = dirname(__DIR__) . '/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

$zipFile = $backupDir . '/project_backup_sti_activity_system_' . date('Ymd_His') . '.zip';
$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("Failed to create zip file at $zipFile\n");
}

$includeDirs = [
    'admin1', 'admin2', 'api', 'assets', 'auth', 'config',
    'database', 'dean', 'faculty', 'includes', 'maintenance',
    'program_head', 'scripts', 'services', 'tests', 'uploads'
];

$rootFiles = [
    '.env.example', '.gitignore', '.htaccess', 'composer.json',
    'package.json', 'README.md', 'index.php', 'sti_activity_system.sql'
];

$basePath = dirname(__DIR__);

// Add root files
foreach ($rootFiles as $rf) {
    $full = $basePath . '/' . $rf;
    if (file_exists($full)) {
        $zip->addFile($full, $rf);
    }
}

// Add directories recursively
foreach ($includeDirs as $d) {
    $dirPath = $basePath . '/' . $d;
    if (!is_dir($dirPath)) continue;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $item) {
        $subPath = substr($item->getPathname(), strlen($basePath) + 1);
        $subPath = str_replace('\\', '/', $subPath);
        if ($item->isDir()) {
            $zip->addEmptyDir($subPath);
        } elseif ($item->isFile()) {
            // Exclude large floorplan or poster test cache if needed, but include directory structure
            $zip->addFile($item->getPathname(), $subPath);
        }
    }
}

$numFiles = $zip->numFiles;
$zip->close();

echo "Backup created successfully!\n";
echo "Location: $zipFile\n";
echo "Files archived: $numFiles\n";
echo "Size: " . round(filesize($zipFile) / (1024 * 1024), 2) . " MB\n";

