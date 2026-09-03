<?php
$file = 'C:/Users/Varchive/.gemini/antigravity-ide/brain/d04a82ae-1158-45b6-a022-978bc95f287e/.system_generated/logs/transcript_full.jsonl';
$handle = fopen($file, 'r');
while (($line = fgets($handle)) !== false) {
    $data = json_decode($line, true);
    if ($data && (($data['step_index'] ?? 0) === 184)) {
        echo "Found step_index 184\n";
        print_r($data);
    }
}
fclose($handle);
