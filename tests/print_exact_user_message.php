<?php
$fh = fopen('C:/Users/Varchive/.gemini/antigravity/brain/a44e6c16-86fe-42e3-a1dd-51a9a9d5e86f/.system_generated/logs/transcript.jsonl', 'r');
while (($line = fgets($fh)) !== false) {
    if (strpos($line, 'We defended our captone') !== false) {
        $d = json_decode($line, true);
        echo $d['content'] ?? '';
        break;
    }
}
fclose($fh);
