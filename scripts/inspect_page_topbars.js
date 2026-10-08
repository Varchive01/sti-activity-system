const fs = require('fs');
const path = require('path');

const dirs = ['faculty', 'admin1', 'admin2', 'dean'];
const pages = [];

dirs.forEach(dir => {
  const fullDir = path.join(__dirname, '..', dir);
  if (!fs.existsSync(fullDir)) return;
  const files = fs.readdirSync(fullDir);
  files.forEach(file => {
    if (!file.endsWith('.php')) return;
    const filePath = path.join(fullDir, file);
    const content = fs.readFileSync(filePath, 'utf8');
    const hasTopbar = content.includes('class="topbar"') || content.includes("class='topbar'");
    const hasProfile = content.includes('topbar-profile.php');
    const hasNotif = content.includes('notification-topbar-widget.php');
    
    // Find snippet around topbar
    let snippet = '';
    const topbarIdx = content.indexOf('<header');
    if (topbarIdx !== -1) {
      const endHeader = content.indexOf('</header>', topbarIdx);
      if (endHeader !== -1) {
        snippet = content.substring(topbarIdx, endHeader + 9);
      }
    }

    pages.push({
      relPath: `${dir}/${file}`,
      hasTopbar,
      hasNotif,
      hasProfile,
      snippet
    });
  });
});

const missing = pages.filter(p => !p.hasProfile);
console.log(`Found ${missing.length} pages without topbar-profile:`);
missing.forEach(p => {
  console.log(`\n--- ${p.relPath} ---`);
  if (p.snippet) {
    console.log(p.snippet.replace(/\n\s*\n/g, '\n').trim());
  } else {
    console.log('[NO <header> found]');
  }
});
