<?php
$context = stream_context_create([
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ]
]);
$liveCss = @file_get_contents("https://sti-armes.freedev.app/assets/css/main.css", false, $context);
preg_match_all("/[^{}\n]*topbar[^{}]*\{[^}]*\}/is", $liveCss, $matches);
foreach ($matches[0] as $m) {
    if (strpos($m, "flex-wrap") !== false) {
        echo "MATCH:\n" . trim($m) . "\n---\n";
    }
}
