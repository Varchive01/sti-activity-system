const fs = require('fs');

['faculty', 'admin1', 'admin2', 'dean'].forEach(dir => {
  const f = dir + '/generate-report.php';
  if (fs.existsSync(f)) {
    const c = fs.readFileSync(f, 'utf8');
    const matches = c.match(/<header\b[^>]*class=["'][^"']*topbar/g);
    console.log(f, 'topbar headers count:', matches ? matches.length : 0);
  }
});
