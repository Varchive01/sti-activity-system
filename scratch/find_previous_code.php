<?php
$file = 'C:/Users/Varchive/.gemini/antigravity-ide/brain/d04a82ae-1158-45b6-a022-978bc95f287e/.system_generated/logs/transcript.jsonl';
$handle = fopen($file, 'r');
$out = fopen(__DIR__ . '/matches.txt', 'w');
while (($line = fgets($handle)) !== false) {
    if (strpos($line, 'checkScheduleConflict') !== false && strpos($line, 'CodeContent') !== false) {
        fwrite($out, $line . "\n");
    }
}
fclose($handle);
fclose($out);
echo "Exported matches to matches.txt\n";
