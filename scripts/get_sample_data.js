const { execSync } = require('child_process');
const phpCode = `
require 'config/database.php';
$db = getDB();
$rows = $db->query('SELECT id, title, status FROM activities ORDER BY id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows);
`;
const out = execSync(`php -r "${phpCode.replace(/\n/g, ' ')}"`).toString();
console.log('Sample Activities:');
console.log(JSON.parse(out));
