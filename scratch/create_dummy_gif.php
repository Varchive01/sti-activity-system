<?php
$gif = base64_decode("R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7");
file_put_contents(__DIR__ . '/dummy.gif', $gif);
echo "Created dummy.gif\n";
