<?php
http_response_code(403);
header('Content-Type: text/plain');
die('403 Forbidden: Test suites cannot be executed via the web server.');

