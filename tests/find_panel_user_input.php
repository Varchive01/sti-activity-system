<?php
$fh = fopen('C:/Users/Varchive/.gemini/antigravity/brain/a44e6c16-86fe-42e3-a1dd-51a9a9d5e86f/.system_generated/logs/transcript.jsonl', 'r');
$matches = [];
$idx = 0;
while (($line = fgets($fh)) !== false) {
    if (strpos($line, '"USER_INPUT"') !== false) {
        $d = json_decode($line, true);
        if ($d && ($d['type'] ?? '') === 'USER_INPUT') {
            $c = $d['content'] ?? '';
            if (stripos($c, 'panel') !== false || stripos($c, 'revision') !== false || stripos($c, 'list') !== false) {
                $matches[] = [
                    'step' => $d['step_index'] ?? $idx,
                    'created_at' => $d['created_at'] ?? '',
                    'content' => $c
                ];
            }
        }
    }
    $idx++;
}
fclose($fh);

echo "Found " . count($matches) . " USER_INPUT messages containing 'panel', 'revision', or 'list':\n";
foreach ($matches as $i => $m) {
    echo "--- MATCH #$i (Step {$m['step']}, Date {$m['created_at']}) ---\n";
    echo substr($m['content'], 0, 800) . "\n\n";
}
