const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const dirs = ['faculty', 'admin1', 'admin2', 'dean'];
const updatedFiles = [];
const skippedFiles = [];
const errorFiles = [];

dirs.forEach(dir => {
  const fullDir = path.join(process.cwd(), dir);
  if (!fs.existsSync(fullDir)) return;

  fs.readdirSync(fullDir).forEach(file => {
    if (!file.endsWith('.php')) return;
    const relPath = `${dir}/${file}`;
    const filePath = path.join(fullDir, file);
    let content = fs.readFileSync(filePath, 'utf8');

    // Skip if already has topbar-profile.php
    if (content.includes('topbar-profile.php')) {
      skippedFiles.push(`${relPath} (already has profile)`);
      return;
    }

    // Skip users.php as it requires user-management-view.php
    if (file === 'users.php') {
      skippedFiles.push(`${relPath} (delegates to user-management-view.php)`);
      return;
    }

    // Special case 1: admin2/review.php and dean/review.php
    if ((dir === 'admin2' || dir === 'dean') && file === 'review.php') {
      const topbarBlock = `  <!-- Shared Topbar -->
  <header class="topbar">
    <div class="page-title">Review Proposal</div>
    <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
      <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>
`;

      // Update sticky position so action bar stays below topbar
      content = content.replace(
        /\.review-action-bar\s*\{\s*position:\s*sticky;\s*top:\s*0;\s*z-index:\s*50;/g,
        '.review-action-bar {\n  position: sticky; top: 64px; z-index: 45;'
      );

      // Insert topbar right after <div class="main-wrap">
      const mainWrapTarget = '<div class="main-wrap">\n';
      if (content.includes(mainWrapTarget)) {
        content = content.replace(mainWrapTarget, mainWrapTarget + '\n' + topbarBlock + '\n');
        fs.writeFileSync(filePath, content, 'utf8');
        updatedFiles.push(relPath);
      } else {
        errorFiles.push(`${relPath}: Could not find <div class="main-wrap">`);
      }
      return;
    }

    // Special case 2: admin2/test-email.php
    if (dir === 'admin2' && file === 'test-email.php') {
      const oldHeader = /<header class="topbar">\s*<div class="page-title">Test Email Configuration<\/div>\s*<\/header>/;
      const newHeader = `<header class="topbar">
    <div class="page-title">Test Email Configuration</div>
    <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
      <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>`;

      if (oldHeader.test(content)) {
        content = content.replace(oldHeader, newHeader);
        fs.writeFileSync(filePath, content, 'utf8');
        updatedFiles.push(relPath);
      } else {
        errorFiles.push(`${relPath}: Could not match test-email header`);
      }
      return;
    }

    // Standard cases (44 files)
    // Find <header...> ... <div class="topbar-right"...> ... </div> ... </header>
    const topbarRegex = /(<header\b[^>]*>[\s\S]*?<div\s+class=["']topbar-right["'][^>]*>)([\s\S]*?)(<\/div>\s*<\/header>)/i;
    const match = content.match(topbarRegex);

    if (match) {
      const openTag = match[1];
      const innerContent = match[2];
      const closeTag = match[3];

      const profileInclude = `\n      <!-- User Profile Control -->\n      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>\n    `;
      const newTopbar = `${openTag}${innerContent.trimEnd()}${profileInclude}${closeTag}`;

      content = content.replace(match[0], newTopbar);
      fs.writeFileSync(filePath, content, 'utf8');
      updatedFiles.push(relPath);
    } else {
      errorFiles.push(`${relPath}: Did not match standard topbar regex`);
    }
  });
});

console.log('====================================');
console.log(`TOTAL FILES UPDATED: ${updatedFiles.length}`);
console.log(`TOTAL FILES SKIPPED: ${skippedFiles.length}`);
console.log(`TOTAL ERRORS: ${errorFiles.length}`);
console.log('====================================');

if (errorFiles.length > 0) {
  console.log('ERRORS:');
  errorFiles.forEach(e => console.log('  ' + e));
}

console.log('\nUpdated Files List:');
updatedFiles.forEach(f => console.log('  ' + f));

// Verify PHP syntax
console.log('\nRunning syntax verification on all updated files...');
let syntaxErrors = 0;
updatedFiles.forEach(f => {
  try {
    execSync(`php -l "${f}"`, { stdio: 'pipe' });
  } catch (err) {
    console.error(`Syntax error in ${f}:`, err.message);
    syntaxErrors++;
  }
});

if (syntaxErrors === 0) {
  console.log('ALL UPDATED FILES PASSED PHP SYNTAX CHECK (php -l)!');
} else {
  console.error(`${syntaxErrors} files failed syntax check!`);
}
