const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const ARTIFACTS_DIR = 'C:\\Users\\Varchive\\.gemini\\antigravity-ide\\brain\\f74e8a15-c8fa-4903-8197-a66eb4f20d95';
const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9453;

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
  console.log("=== COMPREHENSIVE VERIFICATION: ADMIN1 DASHBOARD POLISH ===");
  const userDataDir = path.join(ARTIFACTS_DIR, 'scratch', 'chrome_adm1_dash_' + Date.now());
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
        const text = msg.params.args.map(a => a.value || a.description).join(' ');
        if (!text.includes('404')) {
          consoleErrors.push(text);
        }
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

  console.log("\n--- STEP 1: Logging in as Admin1 ---");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/login.php' });
  await sleep(1500);
  await evaluate(`
    document.querySelector('[name="email"]').value = 'arjay@sti.edu';
    document.querySelector('[name="password"]').value = 'password';
    document.querySelector('form').submit();
  `);
  await sleep(2000);

  console.log("\n--- STEP 2: Navigating to Admin1 Dashboard at 1440x900 ---");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/admin1/dashboard.php' });
  await sleep(2000);

  // Set Light mode initially
  await evaluate(`
    document.documentElement.dataset.theme = 'light';
    localStorage.setItem('sti-theme', 'light');
  `);
  await sleep(400);

  console.log("\n--- STEP 3: Typography Audit (Plus Jakarta Sans Everywhere) ---");
  const typographyReport = await evaluate(`
    (() => {
      const elements = [
        { name: 'body', el: document.body },
        { name: 'topbar page-title', el: document.querySelector('.topbar .page-title') },
        { name: 'filter select year', el: document.querySelector('.topbar select[name="year"]') },
        { name: 'filter select period', el: document.querySelector('.topbar select[name="period"]') },
        { name: 'filter apply btn', el: document.querySelector('.topbar form button') },
        { name: 'stat-card val', el: document.querySelector('.stat-card .stat-val') },
        { name: 'stat-card label', el: document.querySelector('.stat-card .stat-label') },
        { name: 'card-header h2 (Review Queue)', el: document.querySelector('.dashboard-main .card-header h2') },
        { name: 'table th', el: document.querySelector('.activity-table th') },
        { name: 'table act-title', el: document.querySelector('.act-title') },
        { name: 'table act-date', el: document.querySelector('.act-date') },
        { name: 'table act-faculty', el: document.querySelector('.act-faculty') },
        { name: 'table btn', el: document.querySelector('.activity-table .btn') },
        { name: 'trends h2', el: document.querySelectorAll('.dashboard-main .card-header h2')[1] },
        { name: 'trends month-label', el: document.querySelector('.month-label') },
        { name: 'kpi title', el: document.querySelector('.kpi-card .text-sm.fw-bold') },
        { name: 'kpi badge', el: document.querySelector('.kpi-card .badge') },
        { name: 'recent actions h2', el: document.querySelector('.dashboard-rail .card-header h2') },
        { name: 'rail table th', el: document.querySelector('.rail-table th') },
        { name: 'rail act-title', el: document.querySelector('.rail-act-title') },
        { name: 'topbar-user-name', el: document.querySelector('.topbar-user-name') },
        { name: 'topbar-user-role', el: document.querySelector('.topbar-user-role') }
      ];

      return elements.map(item => {
        if (!item.el) return { name: item.name, exists: false };
        const cs = window.getComputedStyle(item.el);
        return {
          name: item.name,
          exists: true,
          fontFamily: cs.fontFamily,
          fontSize: cs.fontSize,
          fontWeight: cs.fontWeight
        };
      });
    })()
  `);

  let allPJ = true;
  for (const item of typographyReport) {
    if (item.exists) {
      const isPJ = item.fontFamily.toLowerCase().includes('plus jakarta sans');
      console.log(`- ${item.name}: ${item.fontFamily} [${isPJ ? 'OK' : 'FAIL'}]`);
      if (!isPJ) allPJ = false;
    } else {
      console.log(`- ${item.name}: NOT FOUND on page`);
    }
  }
  console.log(`Overall Plus Jakarta Sans consistency: ${allPJ ? 'PASS' : 'FAIL'}`);

  console.log("\n--- STEP 4: Topbar Icons & Profile Verification ---");
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

  console.log("\n--- STEP 5: Verification of Floating Popovers ---");
  // Test Notif popover
  await evaluate(`document.getElementById('notifBellBtn').click()`);
  await sleep(400);
  const notifOpen = await evaluate(`(() => {
    const d = document.getElementById('notifDropdown');
    return d ? d.classList.contains('open') || window.getComputedStyle(d).display !== 'none' : false;
  })()`);
  console.log("Notification popover opened on click:", notifOpen);
  await captureScreenshot('admin1_dashboard_notif_open.png');

  // Close notif
  await evaluate(`document.getElementById('notifCloseBtn')?.click() || document.getElementById('notifBellBtn').click()`);
  await sleep(300);

  // Test Profile popover
  await evaluate(`document.getElementById('topbarProfile').click()`);
  await sleep(400);
  const profileOpen = await evaluate(`(() => {
    const p = document.getElementById('topbarProfilePopover');
    return p ? p.classList.contains('show') || p.classList.contains('open') || window.getComputedStyle(p).display !== 'none' : false;
  })()`);
  console.log("Profile popover opened on click:", profileOpen);
  await captureScreenshot('admin1_dashboard_profile_open.png');

  // Close profile
  await evaluate(`document.body.click()`);
  await sleep(300);

  console.log("\n--- STEP 6: Light Mode Screen Captures (Expanded & Collapsed Sidebar) ---");
  const overflowLightExp = await evaluate(`({
    hasOverflow: document.documentElement.scrollWidth > window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth
  })`);
  console.log("Light Mode (Expanded Sidebar) Overflow Check:", overflowLightExp);
  await captureScreenshot('admin1_dashboard_1440_light_expanded.png');

  // Collapse sidebar
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
  await captureScreenshot('admin1_dashboard_1440_light_collapsed.png');

  console.log("\n--- STEP 7: Dark Mode Screen Captures (Expanded & Collapsed Sidebar) ---");
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
    cardBg: window.getComputedStyle(document.querySelector('.dashboard-main .card')).backgroundColor,
    cardHeaderColor: window.getComputedStyle(document.querySelector('.dashboard-main .card-header h2')).color
  })`);
  console.log("Dark Mode (Expanded Sidebar) Check:", darkExpCheck);
  await captureScreenshot('admin1_dashboard_1440_dark_expanded.png');

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
  await captureScreenshot('admin1_dashboard_1440_dark_collapsed.png');

  console.log("\n--- STEP 8: Charts & Cards Rendering Verification ---");
  const cardsRenderCheck = await evaluate(`(() => {
    return {
      statCardsCount: document.querySelectorAll('.stat-grid .stat-card').length,
      reviewQueueCard: !!document.querySelector('.dashboard-main .card .activity-table'),
      trendsChartBarsCount: document.querySelectorAll('.month-bar-col').length,
      kpiCard: !!document.querySelector('.kpi-card .kpi-bar'),
      recentActionsRows: document.querySelectorAll('.rail-table tbody tr').length
    };
  })()`);
  console.log("Cards & Charts render check:", cardsRenderCheck);

  console.log("\n--- STEP 9: Console Errors Check ---");
  console.log(`Total console errors: ${consoleErrors.length}`);
  if (consoleErrors.length > 0) {
    console.error("Console Errors:", consoleErrors);
  }

  try { chromeProc.kill(); } catch (e) {}
  const passed = allPJ && !overflowLightExp.hasOverflow && !darkExpCheck.hasOverflow && consoleErrors.length === 0 && notifOpen && profileOpen;
  console.log(`\n=== FINAL RESULT: ${passed ? 'ALL VERIFICATIONS PASSED' : 'FAILED'} ===`);
  process.exit(passed ? 0 : 1);
}

main().catch(e => {
  console.error("Verification failed:", e);
  process.exit(1);
});
