const fs = require('fs');
const path = require('path');
const dirs = ['faculty', 'admin1', 'admin2', 'dean'];

let canReplaceCount = 0;
let errors = [];
let matchedFiles = [];

dirs.forEach(dir => {
  const fullDir = path.join(process.cwd(), dir);
  if (!fs.existsSync(fullDir)) return;
  fs.readdirSync(fullDir).forEach(file => {
    if (!file.endsWith('.php')) return;
    if (file === 'users.php') return;
    if (file === 'review.php' || file === 'test-email.php') return;
    const filePath = path.join(fullDir, file);
    const content = fs.readFileSync(filePath, 'utf8');
    if (content.includes('topbar-profile.php')) return;

    // Pattern to match:
    // <header ...>
    //   ...
    //   <div class="topbar-right" ...>
    //     ...
    //   </div>
    // </header>
    const topbarRegex = /(<header\b[^>]*>[\s\S]*?<div\s+class=["']topbar-right["'][^>]*>)([\s\S]*?)(<\/div>\s*<\/header>)/i;
    const match = content.match(topbarRegex);
    if (!match) {
      errors.push(`${dir}/${file} did not match topbarRegex`);
    } else {
      canReplaceCount++;
      matchedFiles.push(`${dir}/${file}`);
    }
  });
});

console.log('Matches:', canReplaceCount, 'Errors:', errors.length);
if (errors.length) {
  console.log('ERRORS:', errors);
} else {
  console.log('ALL 44 FILES MATCHED SUCCESSFULLY!');
}
