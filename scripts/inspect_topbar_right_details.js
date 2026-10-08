const fs = require('fs');
const path = require('path');

const dirs = ['faculty', 'admin1', 'admin2', 'dean'];
const filesNeedingUpdate = [];

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
    if (file === 'users.php') return; // delegates to user-management-view.php which already has it

    filesNeedingUpdate.push({
      relPath,
      filePath,
      hasNotif: content.includes('notification-topbar-widget.php'),
      hasTopbar: content.includes('class="topbar"') || content.includes("class='topbar'")
    });
  });
});

console.log(`Files to update: ${filesNeedingUpdate.length}`);
filesNeedingUpdate.forEach(f => {
  console.log(`${f.relPath}: hasNotif=${f.hasNotif}, hasTopbar=${f.hasTopbar}`);
});
