<?php
$fh = fopen('C:/Users/Varchive/.gemini/antigravity/brain/a44e6c16-86fe-42e3-a1dd-51a9a9d5e86f/.system_generated/logs/transcript.jsonl', 'r');
$fullText = '';
while (($line = fgets($fh)) !== false) {
    if (strpos($line, 'We defended our captone') !== false) {
        $d = json_decode($line, true);
        $fullText = $d['content'] ?? '';
        break;
    }
}
fclose($fh);

file_put_contents(__DIR__ . '/user_exact_prompt.txt', $fullText);
echo "Saved exact prompt to tests/user_exact_prompt.txt (" . strlen($fullText) . " bytes)\n";
