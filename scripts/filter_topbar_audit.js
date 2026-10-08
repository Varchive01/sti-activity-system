const fs = require('fs');
const path = require('path');

const dirs = ['faculty', 'admin1', 'admin2', 'dean', 'includes'];
const pages = [];

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
    
    if (hasSidebar || hasTopbar || hasProfile) {
      pages.push({
        relPath: `${dir}/${file}`,
        hasTopbar,
        hasSidebar,
        hasNotifWidget,
        hasProfile
      });
    }
  });
});

console.log('=== PAGES REQUIRING TOPBAR PROFILE ===');
const missingProfile = pages.filter(p => !p.hasProfile);
console.log(`Total missing topbar-profile: ${missingProfile.length}`);
missingProfile.forEach(p => {
  console.log(`- ${p.relPath} (hasTopbar: ${p.hasTopbar}, hasNotifWidget: ${p.hasNotifWidget})`);
});

console.log('\n=== PAGES ALREADY HAVING TOPBAR PROFILE ===');
const havingProfile = pages.filter(p => p.hasProfile);
console.log(`Total with topbar-profile: ${havingProfile.length}`);
havingProfile.forEach(p => {
  console.log(`- ${p.relPath}`);
});
