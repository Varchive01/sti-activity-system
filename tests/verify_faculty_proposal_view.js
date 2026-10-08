const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const ARTIFACTS_DIR = 'C:\\Users\\Varchive\\.gemini\\antigravity-ide\\brain\\5f5e2c1d-db19-4387-94c1-becda405ff4f';
const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9447;

async function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
function getJson(url) {
  return new Promise((resolve, reject) => {
    http.get(url, res => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => { try { resolve(JSON.parse(data)); } catch (e) { reject(e); } });
    }).on('error', reject);
  });
}

async function main() {
  console.log("=== COMPREHENSIVE VERIFICATION: FACULTY PROPOSAL VIEW ===");
  const userDataDir = path.join(ARTIFACTS_DIR, 'scratch', 'chrome_propview_' + Date.now());
  fs.mkdirSync(userDataDir, { recursive: true });

  const chromeProc = spawn(CHROME_PATH, [
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${userDataDir}`,
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--window-size=1440,900'
  ], { stdio: 'ignore' });

  process.on('exit', () => { try { chromeProc.kill(); } catch (e) {} });

  let version = null;
  for (let i = 0; i < 30; i++) {
    await sleep(500);
    try {
      version = await getJson(`http://127.0.0.1:${PORT}/json/version`);
      if (version && version.webSocketDebuggerUrl) break;
    } catch (e) {}
  }

  const targets = await getJson(`http://127.0.0.1:${PORT}/json/list`);
  const pageTarget = targets.find(t => t.type === 'page');
  const ws = new WebSocket(pageTarget.webSocketDebuggerUrl);

  let idCounter = 1;
  const pending = new Map();
  const consoleErrors = [];

  ws.onmessage = (event) => {
    const msg = JSON.parse(event.data);
    if (msg.method === 'Runtime.consoleAPICalled') {
      if (msg.params.type === 'error') {
        consoleErrors.push(msg.params.args.map(a => a.value || a.description).join(' '));
      }
    }
    if (msg.id && pending.has(msg.id)) {
      const { resolve, reject } = pending.get(msg.id);
      pending.delete(msg.id);
      if (msg.error) reject(msg.error);
      else resolve(msg.result);
    }
  };

  await new Promise(r => ws.onopen = r);

  function send(method, params = {}) {
    return new Promise((resolve, reject) => {
      const id = idCounter++;
      pending.set(id, { resolve, reject });
      ws.send(JSON.stringify({ id, method, params }));
    });
  }

  async function evaluate(expression) {
    const res = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    return res.result ? res.result.value : null;
  }

  async function captureScreenshot(filename) {
    const res = await send('Page.captureScreenshot', { format: 'png' });
    const buffer = Buffer.from(res.data, 'base64');
    const outPath = path.join(ARTIFACTS_DIR, filename);
    fs.writeFileSync(outPath, buffer);
    console.log(`[SCREENSHOT] Saved: ${outPath}`);
    return outPath;
  }

  await send('Page.enable');
  await send('Runtime.enable');
  await send('DOM.enable');
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });

  console.log("\n--- STEP 1: Logging in as Faculty ---");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/login.php' });
  await sleep(1500);
  await evaluate(`
    document.querySelector('[name="email"]').value = 'faculty@sti.edu';
    document.querySelector('[name="password"]').value = 'password';
    document.querySelector('form').submit();
  `);
  await sleep(2000);

  console.log("\n--- STEP 2: Verifying opening from Faculty Dashboard ---");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/faculty/dashboard.php' });
  await sleep(2000);

  const dashViewLink = await evaluate(`
    (() => {
      const btn = document.querySelector('.activity-table tbody tr a.btn-outline');
      return btn ? btn.href : null;
    })()
  `);
  console.log("Dashboard Recent Activities first 'View' button URL:", dashViewLink);

  console.log("\n--- STEP 3: Verifying opening from My Activities ---");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/faculty/activities.php' });
  await sleep(2000);

  const actViewLink = await evaluate(`
    (() => {
      const btn = document.querySelector('.table tbody tr a.btn-outline') || document.querySelector('a.act-title');
      return btn ? btn.href : null;
    })()
  `);
  console.log("My Activities first 'View' link URL:", actViewLink);

  console.log("\n--- STEP 4: Navigating to faculty/proposal-view.php?id=750 ---");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/faculty/proposal-view.php?id=750' });
  await sleep(2000);

  // Set Light mode initial
  await evaluate(`
    document.documentElement.dataset.theme = 'light';
    localStorage.setItem('sti-theme', 'light');
  `);
  await sleep(400);

  console.log("\n--- STEP 5: Inspecting Typography & Design System ---");
  const typographyReport = await evaluate(`
    (() => {
      const elements = [
        { name: 'body', el: document.body },
        { name: 'page-title', el: document.querySelector('.page-title') },
        { name: 'card-header h2', el: document.querySelector('.card-header h2') },
        { name: 'info-tile lbl', el: document.querySelector('.info-tile .lbl') },
        { name: 'info-tile val', el: document.querySelector('.info-tile .val') },
        { name: 'table th', el: document.querySelector('.detail-table th') },
        { name: 'table td', el: document.querySelector('.detail-table td') },
        { name: 'topbar-user-name', el: document.querySelector('.topbar-user-name') },
        { name: 'topbar-user-role', el: document.querySelector('.topbar-user-role') },
        { name: 'btn-outline', el: document.querySelector('.btn-outline') },
        { name: 'badge', el: document.querySelector('.badge') }
      ];

      return elements.map(item => {
        if (!item.el) return { name: item.name, exists: false };
        const cs = window.getComputedStyle(item.el);
        return {
          name: item.name,
          exists: true,
          fontFamily: cs.fontFamily,
          fontSize: cs.fontSize,
          fontWeight: cs.fontWeight,
          color: cs.color
        };
      });
    })()
  `);
  console.log("Typography audit on page elements:");
  let allPlusJakarta = true;
  for (const item of typographyReport) {
    if (item.exists) {
      const isPJ = item.fontFamily.toLowerCase().includes('plus jakarta sans');
      console.log(`- ${item.name}: ${item.fontFamily} [${isPJ ? 'OK' : 'FAIL'}]`);
      if (!isPJ) allPlusJakarta = false;
    }
  }
  console.log(`Overall typography Plus Jakarta Sans consistency: ${allPlusJakarta ? 'PASS' : 'FAIL'}`);

  console.log("\n--- STEP 6: Inspecting Topbar Icons (No Permanent Box) ---");
  const topbarCheck = await evaluate(`
    (() => {
      const themeBtn = document.getElementById('themeToggleBtn');
      const notifBtn = document.getElementById('notifBellBtn');
      const csTheme = window.getComputedStyle(themeBtn);
      const csNotif = window.getComputedStyle(notifBtn);

      return {
        themeBtn: {
          exists: !!themeBtn,
          borderStyle: csTheme.borderStyle,
          borderWidth: csTheme.borderWidth,
          background: csTheme.backgroundColor,
          borderRadius: csTheme.borderRadius,
          boxShadow: csTheme.boxShadow
        },
        notifBtn: {
          exists: !!notifBtn,
          borderStyle: csNotif.borderStyle,
          borderWidth: csNotif.borderWidth,
          background: csNotif.backgroundColor,
          borderRadius: csNotif.borderRadius,
          boxShadow: csNotif.boxShadow
        },
        profileExists: !!document.getElementById('topbarProfile'),
        profileName: document.querySelector('.topbar-user-name')?.innerText,
        profileRole: document.querySelector('.topbar-user-role')?.innerText
      };
    })()
  `);
  console.log("Topbar Check:", JSON.stringify(topbarCheck, null, 2));

  // Overflow check in Light Expanded
  const overflowLightExp = await evaluate(`({
    hasOverflow: document.documentElement.scrollWidth > window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth
  })`);
  console.log("Light Mode (Expanded Sidebar) Overflow Check:", overflowLightExp);

  await captureScreenshot('faculty_detail_1440_light_expanded.png');

  console.log("\n--- STEP 7: Testing Sidebar Collapsed (Light Mode) ---");
  await evaluate(`
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    if (toggleBtn) { toggleBtn.click(); }
    else { document.body.classList.add('sidebar-collapsed'); }
  `);
  await sleep(400);

  const overflowLightCol = await evaluate(`({
    hasOverflow: document.documentElement.scrollWidth > window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth,
    isCollapsed: document.body.classList.contains('sidebar-collapsed')
  })`);
  console.log("Light Mode (Collapsed Sidebar) Overflow Check:", overflowLightCol);

  await captureScreenshot('faculty_detail_1440_light_collapsed.png');

  console.log("\n--- STEP 8: Testing Dark Mode (Sidebar Expanded & Collapsed) ---");
  // Toggle theme to dark
  await evaluate(`document.getElementById('themeToggleBtn').click();`);
  await sleep(400);

  // Uncollapse sidebar
  await evaluate(`
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    if (toggleBtn) { toggleBtn.click(); }
    else { document.body.classList.remove('sidebar-collapsed'); }
  `);
  await sleep(400);

  const darkExpCheck = await evaluate(`({
    theme: document.documentElement.dataset.theme,
    hasOverflow: document.documentElement.scrollWidth > window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth,
    cardBg: window.getComputedStyle(document.querySelector('.card')).backgroundColor,
    cardColor: window.getComputedStyle(document.querySelector('.card-header h2')).color
  })`);
  console.log("Dark Mode (Expanded Sidebar) Check:", darkExpCheck);

  await captureScreenshot('faculty_detail_1440_dark_expanded.png');

  // Collapse sidebar in dark mode
  await evaluate(`
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    if (toggleBtn) { toggleBtn.click(); }
    else { document.body.classList.add('sidebar-collapsed'); }
  `);
  await sleep(400);

  const darkColCheck = await evaluate(`({
    hasOverflow: document.documentElement.scrollWidth > window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth
  })`);
  console.log("Dark Mode (Collapsed Sidebar) Overflow Check:", darkColCheck);

  await captureScreenshot('faculty_detail_1440_dark_collapsed.png');

  console.log("\n--- STEP 9: Testing Interactive Buttons & Notification Dropdown ---");
  // Test notification panel toggle
  await evaluate(`document.getElementById('notifBellBtn').click()`);
  await sleep(400);

  const notifOpen = await evaluate(`({
    panelOpen: document.getElementById('notifDropdown')?.classList.contains('open'),
    display: window.getComputedStyle(document.getElementById('notifDropdown')).display
  })`);
  console.log("Notification panel opened:", notifOpen);

  await evaluate(`document.getElementById('notifCloseBtn')?.click() || document.getElementById('notifBackdrop')?.click()`);
  await sleep(400);

  const notifClosed = await evaluate(`({
    panelOpen: document.getElementById('notifDropdown')?.classList.contains('open')
  })`);
  console.log("Notification panel closed:", notifClosed);

  // Check Back link
  const backHref = await evaluate(`document.querySelector('.topbar-right a.btn-outline')?.href`);
  console.log("Back button href:", backHref);

  console.log("\n--- STEP 10: Checking for Console Errors ---");
  console.log(`Console error count: ${consoleErrors.length}`);
  if (consoleErrors.length > 0) {
    console.error("Console Errors:", consoleErrors);
  }

  console.log("\n=== VERIFICATION COMPLETE ===");
  try { chromeProc.kill(); } catch (e) {}
  process.exit(consoleErrors.length === 0 ? 0 : 1);
}

main().catch(e => {
  console.error("Test execution failed:", e);
  process.exit(1);
});
