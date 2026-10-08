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
    const relPath = `${dir}/${file}`;
    const filePath = path.join(fullDir, file);
    const content = fs.readFileSync(filePath, 'utf8');
    if (content.includes('topbar-profile.php')) return;

    const hasNotifWidget = content.includes('notification-topbar-widget.php');
    const hasTopbar = content.includes('class="topbar"') || content.includes("class='topbar'");

    pages.push({
      relPath,
      hasNotifWidget,
      hasTopbar
    });
  });
});

console.log('Total pages without topbar-profile:', pages.length);
pages.forEach(p => {
  console.log(`${p.relPath}: hasNotifWidget=${p.hasNotifWidget}, hasTopbar=${p.hasTopbar}`);
});
