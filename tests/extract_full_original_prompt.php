<?php
$fh = fopen('C:/Users/Varchive/.gemini/antigravity/brain/a44e6c16-86fe-42e3-a1dd-51a9a9d5e86f/.system_generated/logs/transcript_full.jsonl', 'r');
$found = false;
$lineIdx = 0;
while (($line = fgets($fh)) !== false) {
    $lineIdx++;
    if (strpos($line, 'We defended our captone') !== false) {
        $d = json_decode($line, true);
        $content = $d['content'] ?? '';
        file_put_contents(__DIR__ . '/user_full_original_prompt.txt', $content);
        echo "Found at line $lineIdx! Saved full prompt (" . strlen($content) . " bytes)\n";
        $found = true;
        break;
    }
}
fclose($fh);
if (!$found) {
    echo "Not found in transcript_full.jsonl\n";
}
