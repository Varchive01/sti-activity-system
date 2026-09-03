<?php
function searchInFile($file, $query) {
    $lines = file($file);
    foreach ($lines as $idx => $line) {
        if (strpos($line, $query) !== false) {
            echo "$file: line " . ($idx + 1) . " -> " . trim($line) . "\n";
        }
    }
}
searchInFile('faculty/proposal-edit.php', 'validateStep');
searchInFile('faculty/proposal-edit.php', 'is-invalid');
