<?php
$content = file_get_contents('faculty/proposal-create.php');
preg_match_all('/_GET\s*\[\s*[\'"]error[\'"]\s*\]/i', $content, $matches, PREG_OFFSET_CAPTURE);
foreach ($matches[0] as $m) {
    $offset = $m[1];
    $start = max(0, $offset - 100);
    $length = min(strlen($content) - $start, 200);
    echo "Match: " . $m[0] . " | Context: " . substr($content, $start, $length) . "\n";
}
