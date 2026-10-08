const fs = require('fs');
const path = require('path');

function walk(dir) {
  let results = [];
  const list = fs.readdirSync(dir);
  list.forEach(file => {
    if (file === 'node_modules' || file === 'vendor' || file === '.git' || file === 'scratch' || file === 'tests') return;
    const full = path.join(dir, file);
    const stat = fs.statSync(full);
    if (stat && stat.isDirectory()) {
      results = results.concat(walk(full));
    } else if (file.endsWith('.php')) {
      results.push(full);
    }
  });
  return results;
}

const allPhp = walk(process.cwd());
console.log(`Total PHP files found: ${allPhp.length}`);

// Categorize them
const categories = {
  authPages: [],
  api: [],
  configOrInc: [],
  publicOrAuth: [],
  other: []
};

allPhp.forEach(p => {
  const rel = path.relative(process.cwd(), p).replace(/\\/g, '/');
  const content = fs.readFileSync(p, 'utf8');
  const isAuthPage = content.includes('requireLogin') || content.includes('requireRole') || content.includes('sidebar.php');
  const hasTopbar = content.includes('class="topbar"') || content.includes("class='topbar'");
  const hasProfile = content.includes('topbar-profile.php');

  if (rel.startsWith('api/')) {
    categories.api.push(rel);
  } else if (rel.startsWith('config/') || rel.startsWith('includes/')) {
    categories.configOrInc.push(rel);
  } else if (rel.startsWith('auth/')) {
    categories.publicOrAuth.push(rel);
  } else if (isAuthPage || hasTopbar) {
    categories.authPages.push({ rel, isAuthPage, hasTopbar, hasProfile });
  } else {
    categories.other.push(rel);
  }
});

console.log(`\n=== AUTHENTICATED / UI PAGES (${categories.authPages.length}) ===`);
categories.authPages.forEach(p => {
  console.log(`${p.rel}: isAuth=${p.isAuthPage}, hasTopbar=${p.hasTopbar}, hasProfile=${p.hasProfile}`);
});

console.log(`\n=== OTHER FILES (${categories.other.length}) ===`);
categories.other.forEach(p => console.log(p));
