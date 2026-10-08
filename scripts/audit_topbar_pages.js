const fs = require('fs');
const path = require('path');

const dirs = ['faculty', 'admin1', 'admin2', 'dean', 'includes'];
const results = [];

dirs.forEach(dir => {
  const fullDir = path.join(__dirname, '..', dir);
  if (!fs.existsSync(fullDir)) return;
  const files = fs.readdirSync(fullDir);
  files.forEach(file => {
    if (!file.endsWith('.php')) return;
    const filePath = path.join(fullDir, file);
    const content = fs.readFileSync(filePath, 'utf8');
    const hasTopbar = content.includes('class="topbar"') || content.includes("class='topbar'") || content.includes('<header');
    const hasProfile = content.includes('topbar-profile.php');
    const hasNotifWidget = content.includes('notification-topbar-widget.php');
    const hasSidebar = content.includes('includes/sidebar.php') || content.includes('sidebar.php');
    results.push({
      dir,
      file,
      relPath: `${dir}/${file}`,
      hasTopbar,
      hasProfile,
      hasNotifWidget,
      hasSidebar
    });
  });
});

console.log(JSON.stringify(results, null, 2));
