const fs = require('fs');
const path = require('path');

const dirs = ['faculty', 'admin1', 'admin2', 'dean'];
dirs.forEach(dir => {
  const fullDir = path.join(process.cwd(), dir);
  fs.readdirSync(fullDir).forEach(file => {
    if (!file.endsWith('.php')) return;
    const content = fs.readFileSync(path.join(fullDir, file), 'utf8');
    const headers = content.match(/<header\b[^>]*>/gi);
    if (headers && headers.length > 1) {
      console.log(`${dir}/${file} has ${headers.length} headers!`);
    }
  });
});
console.log('Multiple header check complete.');
