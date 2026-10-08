const fs = require('fs');
const path = require('path');
const dirs = ['faculty', 'admin1', 'admin2', 'dean'];

let total = 0;
dirs.forEach(dir => {
  const fullDir = path.join(process.cwd(), dir);
  if (!fs.existsSync(fullDir)) return;
  fs.readdirSync(fullDir).forEach(file => {
    if (!file.endsWith('.php')) return;
    if (file === 'users.php') return;
    const filePath = path.join(fullDir, file);
    const content = fs.readFileSync(filePath, 'utf8');
    if (content.includes('topbar-profile.php')) return;
    if (file === 'review.php' || file === 'test-email.php') return;

    total++;
    // Search for topbar-right
    const match = content.match(/<div class=["']topbar-right["'][^>]*>([\s\S]*?)<\/header>/);
    if (match) {
      console.log(`=== ${dir}/${file} ===`);
      console.log(match[0].trim());
    } else {
      console.log(`NO MATCH: ${dir}/${file}`);
    }
  });
});
console.log(`\nInspected ${total} files.`);
