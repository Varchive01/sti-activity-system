<?php
$transcriptPath = 'C:/Users/Varchive/.gemini/antigravity/brain/a44e6c16-86fe-42e3-a1dd-51a9a9d5e86f/.system_generated/logs/transcript.jsonl';
if (!file_exists($transcriptPath)) {
    echo "Transcript not found at $transcriptPath\n";
    exit;
}
$fh = fopen($transcriptPath, 'r');
$userMsgs = [];
$lineNum = 0;
while (($line = fgets($fh)) !== false) {
    $lineNum++;
    if (strpos($line, '"user"') === false && strpos($line, 'user_message') === false && strpos($line, 'user_input') === false) {
        continue;
    }
    $data = json_decode($line, true);
    if (!$data) continue;
    $content = '';
    if (isset($data['type']) && $data['type'] === 'user_message') {
        $content = $data['content'] ?? json_encode($data);
    } elseif (isset($data['role']) && $data['role'] === 'user') {
        $content = $data['content'] ?? json_encode($data);
    } elseif (isset($data['message']['role']) && $data['message']['role'] === 'user') {
        $content = $data['message']['content'] ?? json_encode($data['message']);
    } elseif (isset($data['user_input'])) {
        $content = $data['user_input'];
    }
    if ($content !== '') {
        $preview = is_string($content) ? substr($content, 0, 300) : substr(json_encode($content), 0, 300);
        $preview = str_replace(["\r", "\n"], ' ', $preview);
        echo "Line $lineNum: $preview\n";
        $userMsgs[] = ['line' => $lineNum, 'content' => $content];
        if (count($userMsgs) >= 20) break;
    }
}
fclose($fh);
