<?php
http_response_code(403);
header('Content-Type: text/plain');
die('403 Forbidden: Database migrations and schema definitions are not accessible via web.');

