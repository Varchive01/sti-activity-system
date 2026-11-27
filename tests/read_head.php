<?php
$fh = fopen('C:/Users/Varchive/.gemini/antigravity/brain/a44e6c16-86fe-42e3-a1dd-51a9a9d5e86f/.system_generated/logs/transcript.jsonl', 'r');
for ($i = 0; $i < 10; $i++) {
    $line = fgets($fh);
    if ($line === false) break;
    $d = json_decode($line, true);
    echo "Line $i keys: " . implode(', ', array_keys($d ?? [])) . "\n";
    if (isset($d['role'])) echo "  role: " . $d['role'] . "\n";
    if (isset($d['type'])) echo "  type: " . $d['type'] . "\n";
    if (isset($d['content'])) echo "  content sample: " . substr(is_string($d['content']) ? $d['content'] : json_encode($d['content']), 0, 150) . "\n";
}
fclose($fh);
