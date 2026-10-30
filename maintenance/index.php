<?php
http_response_code(403);
header('Content-Type: text/plain');
die('403 Forbidden: Maintenance directory is not accessible via web.');

