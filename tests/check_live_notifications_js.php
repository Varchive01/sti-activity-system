<?php
$context = stream_context_create([
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
]);

$liveNotifJs = @file_get_contents("https://sti-armes.freedev.app/assets/js/notifications.js", false, $context);
echo "--- CONTENT OF LIVE notifications.js ---\n";
echo $liveNotifJs . "\n";
